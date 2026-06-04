<?php
$pageTitle = 'Transport Payments';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';

$classLevels = class_level_options();
$genderOptions = gender_options();
$terms = term_options();
$errors = [];
$currentContext = current_academic_context($pdo);
$editingAccountId = (int) ($_GET['edit'] ?? ($_POST['transport_account_id'] ?? 0));
$payingAccountId = (int) ($_GET['pay'] ?? ($_POST['payment_account_id'] ?? 0));
$form = [
    'transport_account_id' => '',
    'mode' => 'existing',
    'student_id' => '',
    'student_name' => '',
    'gender' => '',
    'parent_name' => '',
    'parent_phone' => '',
    'pickup_location' => '',
    'amount' => '',
    'academic_year' => $currentContext['academic_year'],
    'term' => $currentContext['term'],
];

function transport_account_by_id(PDO $pdo, int $id): ?array
{
    $statement = $pdo->prepare(
        "SELECT
            transport_accounts.*,
            transport_students.student_id,
            transport_students.student_name,
            transport_students.gender,
            transport_students.parent_name,
            transport_students.parent_phone,
            transport_students.pickup_location,
            transport_students.is_outside,
            students.registration_no,
            students.class_level
         FROM transport_accounts
         JOIN transport_students ON transport_students.id = transport_accounts.transport_student_id
         LEFT JOIN students ON students.id = transport_students.student_id
         WHERE transport_accounts.id = :id"
    );
    $statement->execute(['id' => $id]);
    $account = $statement->fetch();

    return $account ?: null;
}

function transport_paid_amount(PDO $pdo, int $accountId): float
{
    $statement = $pdo->prepare("SELECT COALESCE(SUM(amount_paid), 0) FROM transport_payments WHERE transport_account_id = :id");
    $statement->execute(['id' => $accountId]);
    return (float) $statement->fetchColumn();
}

function transport_account_duplicate_exists(PDO $pdo, int $transportStudentId, string $academicYear, string $term, int $ignoreAccountId = 0): bool
{
    $sql = "SELECT COUNT(*)
            FROM transport_accounts
            WHERE transport_student_id = :transport_student_id
              AND academic_year = :academic_year
              AND term = :term";
    $params = [
        'transport_student_id' => $transportStudentId,
        'academic_year' => $academicYear,
        'term' => $term,
    ];

    if ($ignoreAccountId > 0) {
        $sql .= " AND id <> :id";
        $params['id'] = $ignoreAccountId;
    }

    $statement = $pdo->prepare($sql);
    $statement->execute($params);

    return (int) $statement->fetchColumn() > 0;
}

function find_or_create_existing_transport_student(PDO $pdo, int $studentId, string $pickupLocation): int
{
    $studentStatement = $pdo->prepare("SELECT * FROM students WHERE id = :id");
    $studentStatement->execute(['id' => $studentId]);
    $student = $studentStatement->fetch();

    if (!$student) {
        throw new RuntimeException('Selected student was not found.');
    }

    $statement = $pdo->prepare("SELECT id FROM transport_students WHERE student_id = :student_id LIMIT 1");
    $statement->execute(['student_id' => $studentId]);
    $transportStudentId = (int) $statement->fetchColumn();

    if ($transportStudentId > 0) {
        $update = $pdo->prepare(
            "UPDATE transport_students
             SET student_name = :student_name,
                 gender = :gender,
                 parent_name = :parent_name,
                 parent_phone = :parent_phone,
                 pickup_location = :pickup_location,
                 is_outside = 0
             WHERE id = :id"
        );
        $update->execute([
            'student_name' => $student['full_name'],
            'gender' => $student['gender'],
            'parent_name' => $student['parent_name'],
            'parent_phone' => $student['guardian_phone'],
            'pickup_location' => $pickupLocation,
            'id' => $transportStudentId,
        ]);

        return $transportStudentId;
    }

    $insert = $pdo->prepare(
        "INSERT INTO transport_students (student_id, student_name, gender, parent_name, parent_phone, pickup_location, is_outside)
         VALUES (:student_id, :student_name, :gender, :parent_name, :parent_phone, :pickup_location, 0)"
    );
    $insert->execute([
        'student_id' => $studentId,
        'student_name' => $student['full_name'],
        'gender' => $student['gender'],
        'parent_name' => $student['parent_name'],
        'parent_phone' => $student['guardian_phone'],
        'pickup_location' => $pickupLocation,
    ]);

    return (int) $pdo->lastInsertId();
}

function find_or_create_outside_transport_student(PDO $pdo, array $data): int
{
    $statement = $pdo->prepare(
        "SELECT id
         FROM transport_students
         WHERE student_id IS NULL
           AND student_name = :student_name
           AND parent_phone = :parent_phone
           AND pickup_location = :pickup_location
         LIMIT 1"
    );
    $statement->execute([
        'student_name' => $data['student_name'],
        'parent_phone' => $data['parent_phone'],
        'pickup_location' => $data['pickup_location'],
    ]);
    $transportStudentId = (int) $statement->fetchColumn();

    if ($transportStudentId > 0) {
        $update = $pdo->prepare(
            "UPDATE transport_students
             SET gender = :gender,
                 parent_name = :parent_name,
                 is_outside = 1
             WHERE id = :id"
        );
        $update->execute([
            'gender' => $data['gender'],
            'parent_name' => $data['parent_name'],
            'id' => $transportStudentId,
        ]);

        return $transportStudentId;
    }

    $insert = $pdo->prepare(
        "INSERT INTO transport_students (student_id, student_name, gender, parent_name, parent_phone, pickup_location, is_outside)
         VALUES (NULL, :student_name, :gender, :parent_name, :parent_phone, :pickup_location, 1)"
    );
    $insert->execute([
        'student_name' => $data['student_name'],
        'gender' => $data['gender'],
        'parent_name' => $data['parent_name'],
        'parent_phone' => $data['parent_phone'],
        'pickup_location' => $data['pickup_location'],
    ]);

    return (int) $pdo->lastInsertId();
}

if ($editingAccountId > 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $account = transport_account_by_id($pdo, $editingAccountId);
    if ($account) {
        $form = [
            'transport_account_id' => $account['id'],
            'mode' => (int) $account['is_outside'] === 1 ? 'outside' : 'existing',
            'student_id' => $account['student_id'],
            'student_name' => $account['student_name'],
            'gender' => $account['gender'],
            'parent_name' => $account['parent_name'],
            'parent_phone' => $account['parent_phone'],
            'pickup_location' => $account['pickup_location'],
            'amount' => $account['amount_due'],
            'academic_year' => $account['academic_year'],
            'term' => $account['term'],
        ];
    } else {
        flash('error', 'Transport account was not found.');
        redirect('admin/transport.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save_account';

    if ($action === 'delete_account') {
        $accountId = (int) ($_POST['transport_account_id'] ?? 0);
        $account = $accountId > 0 ? transport_account_by_id($pdo, $accountId) : null;

        if (!$account) {
            $errors[] = 'Choose a transport account to delete.';
        } else {
            $statement = $pdo->prepare("DELETE FROM transport_accounts WHERE id = :id");
            $statement->execute(['id' => $accountId]);
            flash('success', 'Transport account deleted successfully.');
            redirect('admin/transport.php');
        }
    } elseif ($action === 'save_payment') {
        $accountId = (int) ($_POST['payment_account_id'] ?? 0);
        $amountPaid = trim($_POST['amount_paid'] ?? '');
        $paymentDate = trim($_POST['payment_date'] ?? date('Y-m-d'));
        $referenceNo = trim($_POST['reference_no'] ?? '');
        $account = $accountId > 0 ? transport_account_by_id($pdo, $accountId) : null;

        if (!$account) {
            $errors[] = 'Choose a valid transport account.';
        }
        if (!valid_money_value($amountPaid) || (float) $amountPaid <= 0) {
            $errors[] = 'Payment amount must be greater than zero.';
        }
        if (!valid_date_value($paymentDate)) {
            $errors[] = 'Payment date must be valid.';
        }
        if (!$errors) {
            $balance = max((float) $account['amount_due'] - transport_paid_amount($pdo, $accountId), 0);
            if ((float) $amountPaid > $balance + 0.005) {
                $errors[] = 'Payment cannot exceed the transport balance.';
            }
        }

        if (!$errors) {
            $statement = $pdo->prepare(
                "INSERT INTO transport_payments (transport_account_id, amount_paid, payment_date, reference_no)
                 VALUES (:transport_account_id, :amount_paid, :payment_date, :reference_no)"
            );
            $statement->execute([
                'transport_account_id' => $accountId,
                'amount_paid' => (float) $amountPaid,
                'payment_date' => $paymentDate,
                'reference_no' => $referenceNo,
            ]);
            flash('success', 'Transport payment recorded successfully.');
            redirect('admin/transport.php');
        }
    } else {
        $accountId = (int) ($_POST['transport_account_id'] ?? 0);
        $form = [
            'transport_account_id' => $accountId,
            'mode' => ($_POST['mode'] ?? 'existing') === 'outside' ? 'outside' : 'existing',
            'student_id' => (int) ($_POST['student_id'] ?? 0),
            'student_name' => trim($_POST['student_name'] ?? ''),
            'gender' => trim($_POST['gender'] ?? ''),
            'parent_name' => trim($_POST['parent_name'] ?? ''),
            'parent_phone' => trim($_POST['parent_phone'] ?? ''),
            'pickup_location' => trim($_POST['pickup_location'] ?? ''),
            'amount' => trim($_POST['amount'] ?? ''),
            'academic_year' => trim($_POST['academic_year'] ?? ''),
            'term' => trim($_POST['term'] ?? ''),
        ];

        if ($form['mode'] === 'existing' && $form['student_id'] <= 0) {
            $errors[] = 'Choose an existing student.';
        }
        if ($form['mode'] === 'outside') {
            if ($form['student_name'] === '') {
                $errors[] = 'Student name is required for outside students.';
            }
            if (!in_array($form['gender'], $genderOptions, true)) {
                $errors[] = 'Choose a valid gender.';
            }
            if ($form['parent_name'] === '') {
                $errors[] = 'Parent name is required.';
            }
        }
        if ($form['pickup_location'] === '') {
            $errors[] = 'Pickup location is required.';
        }
        if (!valid_money_value($form['amount']) || (float) $form['amount'] <= 0) {
            $errors[] = 'Transport amount must be greater than zero.';
        }
        if (!preg_match('/^\d{4}$/', $form['academic_year'])) {
            $errors[] = 'Academic year must be four digits.';
        }
        if (!in_array($form['term'], $terms, true)) {
            $errors[] = 'Choose a valid term.';
        }
        if (!$errors && $action === 'update_account' && $accountId > 0 && (float) $form['amount'] < transport_paid_amount($pdo, $accountId)) {
            $errors[] = 'Transport amount cannot be less than payments already recorded.';
        }

        if (!$errors) {
            $pdo->beginTransaction();
            try {
                if ($form['mode'] === 'existing') {
                    $transportStudentId = find_or_create_existing_transport_student($pdo, (int) $form['student_id'], $form['pickup_location']);
                } else {
                    $transportStudentId = find_or_create_outside_transport_student($pdo, $form);
                }

                if (transport_account_duplicate_exists($pdo, $transportStudentId, $form['academic_year'], $form['term'], $action === 'update_account' ? $accountId : 0)) {
                    throw new RuntimeException('This transport student already has an account for the selected year and term.');
                }

                $params = [
                    'transport_student_id' => $transportStudentId,
                    'academic_year' => $form['academic_year'],
                    'term' => $form['term'],
                    'amount_due' => (float) $form['amount'],
                ];

                if ($action === 'update_account' && $accountId > 0) {
                    $params['id'] = $accountId;
                    $statement = $pdo->prepare(
                        "UPDATE transport_accounts
                         SET transport_student_id = :transport_student_id,
                             academic_year = :academic_year,
                             term = :term,
                             amount_due = :amount_due
                         WHERE id = :id"
                    );
                    $statement->execute($params);
                    flash('success', 'Transport account updated successfully.');
                } else {
                    $statement = $pdo->prepare(
                        "INSERT INTO transport_accounts (transport_student_id, academic_year, term, amount_due)
                         VALUES (:transport_student_id, :academic_year, :term, :amount_due)"
                    );
                    $statement->execute($params);
                    flash('success', 'Transport account added successfully.');
                }

                $pdo->commit();
                redirect('admin/transport.php');
            } catch (Throwable $exception) {
                $pdo->rollBack();
                $errors[] = $exception->getMessage();
            }
        }
    }
}

$students = $pdo->query(
    "SELECT id, registration_no, full_name, gender, parent_name, guardian_phone, class_level
     FROM students
     ORDER BY full_name ASC"
)->fetchAll();

$search = trim($_GET['search'] ?? '');
$location = trim($_GET['location'] ?? '');
$filterYear = preg_match('/^\d{4}$/', ($_GET['year'] ?? '')) ? $_GET['year'] : '';
$filterTerm = in_array(($_GET['term'] ?? ''), $terms, true) ? $_GET['term'] : '';
$where = [];
$params = [];

if ($search !== '') {
    $where[] = '(transport_students.student_name LIKE :search OR transport_students.parent_name LIKE :search OR transport_students.parent_phone LIKE :search OR transport_students.pickup_location LIKE :search)';
    $params['search'] = '%' . $search . '%';
}
if ($location !== '') {
    $where[] = 'transport_students.pickup_location LIKE :location';
    $params['location'] = '%' . $location . '%';
}
if ($filterYear !== '') {
    $where[] = 'transport_accounts.academic_year = :year';
    $params['year'] = $filterYear;
}
if ($filterTerm !== '') {
    $where[] = 'transport_accounts.term = :term';
    $params['term'] = $filterTerm;
}

$sql = "SELECT
            transport_accounts.*,
            transport_students.student_id,
            transport_students.student_name,
            transport_students.gender,
            transport_students.parent_name,
            transport_students.parent_phone,
            transport_students.pickup_location,
            transport_students.is_outside,
            students.registration_no,
            students.class_level,
            COALESCE(SUM(transport_payments.amount_paid), 0) AS paid,
            transport_accounts.amount_due - COALESCE(SUM(transport_payments.amount_paid), 0) AS balance
        FROM transport_accounts
        JOIN transport_students ON transport_students.id = transport_accounts.transport_student_id
        LEFT JOIN students ON students.id = transport_students.student_id
        LEFT JOIN transport_payments ON transport_payments.transport_account_id = transport_accounts.id";
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' GROUP BY transport_accounts.id ORDER BY transport_accounts.academic_year DESC, FIELD(transport_accounts.term, "Term 1", "Term 2", "Term 3"), transport_students.student_name ASC';
$statement = $pdo->prepare($sql);
$statement->execute($params);
$accounts = $statement->fetchAll();
$paymentTarget = $payingAccountId > 0 ? transport_account_by_id($pdo, $payingAccountId) : null;

$paymentHistory = $pdo->query(
    "SELECT transport_payments.*, transport_students.student_name, transport_accounts.term, transport_accounts.academic_year
     FROM transport_payments
     JOIN transport_accounts ON transport_accounts.id = transport_payments.transport_account_id
     JOIN transport_students ON transport_students.id = transport_accounts.transport_student_id
     ORDER BY transport_payments.payment_date DESC, transport_payments.id DESC
     LIMIT 20"
)->fetchAll();

require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title">
    <div>
        <p class="eyebrow">Routes and Riders</p>
        <h1>Transport Payments</h1>
        <p class="mb-0 text-muted"><?= h($currentContext['academic_year'] . ' - ' . $currentContext['term']) ?></p>
    </div>
    <a class="btn btn-outline-primary" href="<?= url('admin/reports.php') ?>">Reports</a>
</div>

<?php if ($message = flash('success')): ?><div class="alert alert-success"><?= h($message) ?></div><?php endif; ?>
<?php if ($message = flash('error')): ?><div class="alert alert-danger"><?= h($message) ?></div><?php endif; ?>
<?php if ($errors): ?><div class="alert alert-danger"><?php foreach ($errors as $error): ?><div><?= h($error) ?></div><?php endforeach; ?></div><?php endif; ?>

<div class="row g-4">
    <div class="col-lg-4">
        <section class="panel">
            <h2><?= (int) $form['transport_account_id'] > 0 ? 'Edit Transport Student' : 'Add Transport Student' ?></h2>
            <form class="row g-3" method="post">
                <input type="hidden" name="transport_account_id" value="<?= (int) $form['transport_account_id'] ?>">
                <input type="hidden" name="academic_year" value="<?= h((string) $form['academic_year']) ?>">
                <input type="hidden" name="term" value="<?= h((string) $form['term']) ?>">
                <div class="col-12">
                    <label class="form-label" for="mode">Student Type</label>
                    <select class="form-select" id="mode" name="mode">
                        <option value="existing" <?= $form['mode'] === 'existing' ? 'selected' : '' ?>>Existing school student</option>
                        <option value="outside" <?= $form['mode'] === 'outside' ? 'selected' : '' ?>>Outside student</option>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label" for="student_id">Existing Student</label>
                    <select class="form-select" id="student_id" name="student_id">
                        <option value="">Choose student...</option>
                        <?php foreach ($students as $student): ?>
                            <option value="<?= (int) $student['id'] ?>" <?= (int) $form['student_id'] === (int) $student['id'] ? 'selected' : '' ?>>
                                <?= h($student['registration_no'] . ' - ' . $student['full_name'] . ' - ' . $student['class_level']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label" for="student_name">Outside Student Name</label>
                    <input class="form-control" id="student_name" name="student_name" value="<?= h((string) $form['student_name']) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="gender">Gender</label>
                    <select class="form-select" id="gender" name="gender">
                        <option value="">Choose gender...</option>
                        <?php foreach ($genderOptions as $gender): ?>
                            <option value="<?= h($gender) ?>" <?= $form['gender'] === $gender ? 'selected' : '' ?>><?= h($gender) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="parent_phone">Parent Phone</label>
                    <input class="form-control" id="parent_phone" name="parent_phone" value="<?= h((string) $form['parent_phone']) ?>">
                </div>
                <div class="col-12">
                    <label class="form-label" for="parent_name">Parent Name</label>
                    <input class="form-control" id="parent_name" name="parent_name" value="<?= h((string) $form['parent_name']) ?>">
                </div>
                <div class="col-12">
                    <label class="form-label" for="pickup_location">Pickup Location</label>
                    <input class="form-control" id="pickup_location" name="pickup_location" value="<?= h((string) $form['pickup_location']) ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="amount">Amount</label>
                    <input class="form-control" type="number" min="0.01" step="0.01" id="amount" name="amount" value="<?= h((string) $form['amount']) ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="academic_year">Year</label>
                    <input class="form-control" id="academic_year" value="<?= h((string) $form['academic_year']) ?>" readonly>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="term">Term</label>
                    <input class="form-control" id="term" value="<?= h((string) $form['term']) ?>" readonly>
                </div>
                <div class="col-12">
                    <div class="action-row">
                        <button class="btn btn-primary" name="action" value="save_account" type="submit">Save</button>
                        <button class="btn btn-outline-primary" name="action" value="update_account" type="submit" <?= (int) $form['transport_account_id'] > 0 ? '' : 'disabled' ?>>Update</button>
                    </div>
                </div>
            </form>
        </section>
        <?php if ($paymentTarget): ?>
            <section class="panel mt-4">
                <h2>Record Transport Payment</h2>
                <p class="text-muted"><?= h($paymentTarget['student_name'] . ' - ' . $paymentTarget['term'] . ' ' . $paymentTarget['academic_year']) ?></p>
                <form class="row g-3" method="post">
                    <input type="hidden" name="payment_account_id" value="<?= (int) $paymentTarget['id'] ?>">
                    <div class="col-md-6">
                        <label class="form-label" for="amount_paid">Amount Paid</label>
                        <input class="form-control" type="number" min="0.01" step="0.01" id="amount_paid" name="amount_paid" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="payment_date">Date</label>
                        <input class="form-control" type="date" id="payment_date" name="payment_date" value="<?= h(date('Y-m-d')) ?>" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="reference_no">Reference</label>
                        <input class="form-control" id="reference_no" name="reference_no">
                    </div>
                    <div class="col-12">
                        <button class="btn btn-primary w-100" name="action" value="save_payment" type="submit">Save Payment</button>
                    </div>
                </form>
            </section>
        <?php endif; ?>
    </div>
    <div class="col-lg-8">
        <section class="panel">
            <h2>Transport Accounts</h2>
            <form class="row g-3 mb-4" method="get">
                <div class="col-md-3"><input class="form-control" name="search" value="<?= h($search) ?>" placeholder="Search student, parent, route"></div>
                <div class="col-md-3"><input class="form-control" name="location" value="<?= h($location) ?>" placeholder="Route/location"></div>
                <div class="col-md-2"><input class="form-control" name="year" value="<?= h($filterYear) ?>" maxlength="4" placeholder="Year"></div>
                <div class="col-md-2">
                    <select class="form-select" name="term">
                        <option value="">All terms</option>
                        <?php foreach ($terms as $term): ?><option value="<?= h($term) ?>" <?= $filterTerm === $term ? 'selected' : '' ?>><?= h($term) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2"><button class="btn btn-outline-primary w-100" type="submit">Filter</button></div>
            </form>
            <div class="table-responsive">
                <table class="table table-striped align-middle">
                    <thead><tr><th>Student</th><th>Route</th><th>Year</th><th>Term</th><th>Due</th><th>Paid</th><th>Balance</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($accounts as $account): ?>
                            <tr>
                                <td>
                                    <?= h($account['student_name']) ?><br>
                                    <small><?= (int) $account['is_outside'] === 1 ? 'Outside student' : h($account['registration_no'] . ' - ' . $account['class_level']) ?></small>
                                </td>
                                <td><?= h($account['pickup_location']) ?></td>
                                <td><?= h($account['academic_year']) ?></td>
                                <td><?= h($account['term']) ?></td>
                                <td><?= money((float) $account['amount_due']) ?></td>
                                <td><?= money((float) $account['paid']) ?></td>
                                <td><?= money((float) $account['balance']) ?></td>
                                <td>
                                    <div class="action-row">
                                        <a class="btn btn-sm btn-outline-primary" href="<?= url('admin/transport.php?edit=' . $account['id']) ?>">Edit</a>
                                        <a class="btn btn-sm btn-primary" href="<?= url('admin/transport.php?pay=' . $account['id']) ?>">Pay</a>
                                        <form method="post" onsubmit="return confirm('Delete this transport account and payments?');">
                                            <input type="hidden" name="transport_account_id" value="<?= (int) $account['id'] ?>">
                                            <button class="btn btn-sm btn-outline-danger" name="action" value="delete_account" type="submit">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$accounts): ?><tr><td colspan="8">No transport accounts found.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <section class="panel mt-4">
            <h2>Payment History</h2>
            <div class="table-responsive">
                <table class="table table-striped align-middle">
                    <thead><tr><th>Student</th><th>Term</th><th>Amount</th><th>Date</th><th>Reference</th></tr></thead>
                    <tbody>
                        <?php foreach ($paymentHistory as $payment): ?>
                            <tr>
                                <td><?= h($payment['student_name']) ?></td>
                                <td><?= h($payment['term'] . ' ' . $payment['academic_year']) ?></td>
                                <td><?= money((float) $payment['amount_paid']) ?></td>
                                <td><?= h($payment['payment_date']) ?></td>
                                <td><?= h($payment['reference_no']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$paymentHistory): ?><tr><td colspan="5">No transport payments recorded.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>
