<?php
$pageTitle = 'Fees';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';

function generate_receipt_no(PDO $pdo): string
{
    do {
        $receiptNo = 'REC-' . date('Ymd-His') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        $statement = $pdo->prepare("SELECT COUNT(*) FROM fees WHERE receipt_no = :receipt_no");
        $statement->execute(['receipt_no' => $receiptNo]);
    } while ((int) $statement->fetchColumn() > 0);

    return $receiptNo;
}

$currentContext = current_academic_context($pdo);
$currentAcademicYear = $currentContext['academic_year'];
$currentTerm = $currentContext['term'];
sync_current_term_fee_balances($pdo);

$studentStatement = $pdo->prepare(
    "SELECT
        students.id,
        students.registration_no,
        students.full_name,
        students.parent_name,
        students.class_level,
        COALESCE(current_balances.required_amount, 0) AS required_amount,
        COALESCE(current_balances.paid_amount, 0) AS paid_amount,
        COALESCE(current_balances.balance, 0) AS balance
     FROM students
     LEFT JOIN fee_balances AS current_balances
       ON current_balances.student_id = students.id
      AND current_balances.academic_year = :academic_year
      AND current_balances.term = :term
     ORDER BY students.full_name ASC"
);
$studentStatement->execute([
    'academic_year' => $currentAcademicYear,
    'term' => $currentTerm,
]);
$students = $studentStatement->fetchAll();

$selectedStudentId = (int) ($_GET['student_id'] ?? ($_POST['student_id'] ?? 0));
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $studentId = (int) ($_POST['student_id'] ?? 0);
    $amountPaid = (float) ($_POST['amount_paid'] ?? 0);
    $paymentDate = trim($_POST['payment_date'] ?? date('Y-m-d'));
    $mpesaCode = strtoupper(trim($_POST['mpesa_code'] ?? ''));
    $mpesaReferenceText = trim($_POST['mpesa_reference_text'] ?? '');
    $term = $currentTerm;
    $year = $currentAcademicYear;

    if ($studentId <= 0) {
        $errors[] = 'Please choose a student.';
    }
    if ($amountPaid <= 0) {
        $errors[] = 'Amount paid must be greater than zero.';
    }
    if ($paymentDate === '') {
        $errors[] = 'Payment date is required.';
    }
    if ($paymentDate !== '') {
        $paymentDateObject = DateTime::createFromFormat('Y-m-d', $paymentDate);
        if (!$paymentDateObject || $paymentDateObject->format('Y-m-d') !== $paymentDate) {
            $errors[] = 'Payment date must be a valid date.';
        }
    }
    if ($mpesaCode === '') {
        $errors[] = 'M-PESA transaction code is required.';
    }
    if ($mpesaCode !== '' && !preg_match('/^[A-Z0-9]{8,20}$/', $mpesaCode)) {
        $errors[] = 'M-PESA transaction code should contain 8 to 20 letters or numbers.';
    }
    if (!$errors) {
        $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare("SELECT * FROM students WHERE id = :id FOR UPDATE");
            $statement->execute(['id' => $studentId]);
            $student = $statement->fetch();

            if (!$student) {
                $errors[] = 'Selected student was not found.';
            } elseif (!get_fee_structure($pdo, $student['class_level'], $year)) {
                $errors[] = "No fee structure exists for {$student['class_level']} in {$year}.";
            } else {
                sync_fee_balance_for_student($pdo, $studentId, $student['class_level'], $year, $term);
                $balance = get_fee_balance($pdo, $studentId, $year, $term, true);

                if (!$balance) {
                    $errors[] = 'Fee balance could not be created for the selected student.';
                } else {
                    $termBalance = (float) $balance['balance'];
                }

                if (!$errors && $amountPaid > $termBalance + 0.005) {
                    $errors[] = 'Amount paid cannot exceed the current term balance.';
                } elseif (!$errors) {
                    $balanceAfter = max($termBalance - $amountPaid, 0);
                    $paidAfter = (float) $balance['paid_amount'] + $amountPaid;
                    $receiptNo = generate_receipt_no($pdo);

                    $insert = $pdo->prepare(
                        "INSERT INTO fees (student_id, receipt_no, amount_paid, mpesa_code, mpesa_reference_text, term, year, balance_after_payment, payment_date)
                         VALUES (:student_id, :receipt_no, :amount_paid, :mpesa_code, :mpesa_reference_text, :term, :year, :balance_after_payment, :payment_date)"
                    );
                    $insert->execute([
                        'student_id' => $studentId,
                        'receipt_no' => $receiptNo,
                        'amount_paid' => $amountPaid,
                        'mpesa_code' => $mpesaCode,
                        'mpesa_reference_text' => $mpesaReferenceText,
                        'term' => $term,
                        'year' => $year,
                        'balance_after_payment' => $balanceAfter,
                        'payment_date' => $paymentDate,
                    ]);
                    $feeId = (int) $pdo->lastInsertId();

                    $updateBalance = $pdo->prepare(
                        "UPDATE fee_balances
                         SET paid_amount = :paid_amount,
                             balance = :balance
                         WHERE id = :id"
                    );
                    $updateBalance->execute([
                        'paid_amount' => $paidAfter,
                        'balance' => $balanceAfter,
                        'id' => (int) $balance['id'],
                    ]);

                    $pdo->commit();
                    redirect('admin/receipt.php?id=' . $feeId);
                }
            }

            if ($errors) {
                $pdo->rollBack();
            }
        } catch (Throwable $exception) {
            $pdo->rollBack();
            $errors[] = 'Unable to save payment: ' . $exception->getMessage();
        }
    }
}

$payments = $pdo->query(
    "SELECT fees.*, students.registration_no, students.full_name, students.class_level
     FROM fees
     JOIN students ON students.id = fees.student_id
     ORDER BY fees.payment_date DESC, fees.id DESC"
)->fetchAll();

require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title">
    <div>
        <p class="eyebrow">Fee Payments</p>
        <h1>Record Payment</h1>
        <p class="mb-0 text-muted"><?= h($currentAcademicYear . ' - ' . $currentTerm) ?></p>
    </div>
    <a class="btn btn-outline-primary" href="<?= url('admin/export.php?type=fees') ?>">Export Fees to Excel</a>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <?php foreach ($errors as $error): ?>
            <div><?= h($error) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-4">
        <section class="panel">
            <h2>New Payment</h2>
            <form class="row g-3" method="post">
                <div class="col-12">
                    <label class="form-label" for="student_id">Student</label>
                    <select class="form-select" id="student_id" name="student_id" required>
                        <option value="">Choose student...</option>
                        <?php foreach ($students as $student): ?>
                            <option value="<?= (int) $student['id'] ?>" <?= $selectedStudentId === (int) $student['id'] ? 'selected' : '' ?>>
                                <?= h($student['registration_no'] . ' - ' . $student['full_name'] . ' | Parent: ' . $student['parent_name'] . ' | ' . $currentTerm . ' Balance: ' . money((float) $student['balance'])) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="amount_paid">Amount Paid</label>
                    <input class="form-control" type="number" min="0.01" step="0.01" id="amount_paid" name="amount_paid" value="<?= h($_POST['amount_paid'] ?? '') ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="payment_date">Date</label>
                    <input class="form-control" type="date" id="payment_date" name="payment_date" value="<?= h($_POST['payment_date'] ?? date('Y-m-d')) ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="current_term">Current Term</label>
                    <input class="form-control" id="current_term" value="<?= h($currentTerm) ?>" readonly>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="current_year">Academic Year</label>
                    <input class="form-control" id="current_year" value="<?= h($currentAcademicYear) ?>" readonly>
                </div>
                <div class="col-12">
                    <label class="form-label" for="mpesa_code">M-PESA Transaction Code</label>
                    <input class="form-control" id="mpesa_code" name="mpesa_code" value="<?= h($_POST['mpesa_code'] ?? '') ?>" placeholder="UEO455OHOT" required>
                </div>
                
                <div class="col-12">
                    <button class="btn btn-primary w-100" type="submit">Save Payment and Open Receipt</button>
                </div>
            </form>
        </section>
    </div>
    <div class="col-lg-8">
        <section class="panel">
            <h2>Payment History</h2>
            <div class="table-responsive">
                <table class="table table-striped align-middle">
                    <thead>
                        <tr>
                            <th>Receipt No</th>
                            <th>Student</th>
                            <th>Term</th>
                            <th>M-PESA Code</th>
                            <th>Amount Paid</th>
                            <th>Term Balance</th>
                            <th>Date</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $payment): ?>
                            <tr>
                                <td><?= h($payment['receipt_no']) ?></td>
                                <td><?= h($payment['full_name']) ?><br><small><?= h($payment['registration_no']) ?></small></td>
                                <td><?= h($payment['term'] . ' - ' . $payment['year']) ?></td>
                                <td><?= h($payment['mpesa_code']) ?></td>
                                <td><?= money((float) $payment['amount_paid']) ?></td>
                                <td><?= money((float) $payment['balance_after_payment']) ?></td>
                                <td><?= date('M d, Y', strtotime($payment['payment_date'])) ?></td>
                                <td><a class="btn btn-sm btn-outline-primary" href="<?= url('admin/receipt.php?id=' . $payment['id']) ?>">Receipt</a></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$payments): ?>
                            <tr><td colspan="8">No fee payments recorded yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>
