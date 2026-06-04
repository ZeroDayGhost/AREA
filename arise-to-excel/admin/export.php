<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';

$type = $_GET['type'] ?? '';
$today = date('Y-m-d');
$currentContext = current_academic_context($pdo);

if ($type === 'students') {
    sync_current_term_fee_balances($pdo);

    $filename = "students_export_{$today}.csv";
    $statement = $pdo->prepare(
        "SELECT
            students.registration_no,
            students.full_name,
            students.gender,
            students.parent_name,
            students.class_level,
            students.guardian_phone,
            :display_academic_year AS academic_year,
            :display_term AS term,
            COALESCE(current_balances.required_amount, 0) AS required_amount,
            COALESCE(current_balances.paid_amount, 0) AS paid_amount,
            COALESCE(current_balances.balance, 0) AS balance,
            students.created_at
         FROM students
         LEFT JOIN fee_balances AS current_balances
           ON current_balances.student_id = students.id
          AND current_balances.academic_year = :balance_academic_year
          AND current_balances.term = :balance_term
         ORDER BY students.full_name ASC"
    );
    $statement->execute([
        'display_academic_year' => $currentContext['academic_year'],
        'display_term' => $currentContext['term'],
        'balance_academic_year' => $currentContext['academic_year'],
        'balance_term' => $currentContext['term'],
    ]);
    $rows = $statement->fetchAll();

    $headers = ['Registration No', 'Student Name', 'Gender', 'Parent Name', 'Class Level', 'Parent Phone', 'Academic Year', 'Term', 'Required Amount', 'Paid Amount', 'Balance', 'Created At'];
} elseif ($type === 'fees') {
    $filename = "fees_export_{$today}.csv";
    $rows = $pdo->query(
        "SELECT
            fees.receipt_no,
            fees.payment_date,
            students.registration_no,
            students.full_name,
            students.class_level,
            fees.term,
            fees.year,
            fees.mpesa_code,
            fees.mpesa_reference_text,
            fees.amount_paid,
            fees.balance_after_payment,
            fees.created_at
         FROM fees
         JOIN students ON students.id = fees.student_id
         ORDER BY fees.payment_date DESC, fees.id DESC"
    )->fetchAll();

    $headers = ['Receipt No', 'Payment Date', 'Registration No', 'Student Name', 'Class Level', 'Term', 'Year', 'M-PESA Code', 'M-PESA Reference Text', 'Amount Paid', 'Term Balance', 'Created At'];
} else {
    redirect('admin/dashboard.php');
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');
fputcsv($output, $headers);

foreach ($rows as $row) {
    fputcsv($output, array_values($row));
}

fclose($output);
exit;
