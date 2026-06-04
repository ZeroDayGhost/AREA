<?php
$pageTitle = 'Reports';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';

$classLevels = class_level_options();
$terms = term_options();

$filters = [
    'class_level' => in_array(($_GET['class_level'] ?? ''), $classLevels, true) ? $_GET['class_level'] : '',
    'gender' => in_array(($_GET['gender'] ?? ''), gender_options(), true) ? $_GET['gender'] : '',
    'year' => preg_match('/^\d{4}$/', ($_GET['year'] ?? '')) ? $_GET['year'] : '',
    'term' => in_array(($_GET['term'] ?? ''), $terms, true) ? $_GET['term'] : '',
    'date_from' => valid_date_value($_GET['date_from'] ?? '') ? $_GET['date_from'] : '',
    'date_to' => valid_date_value($_GET['date_to'] ?? '') ? $_GET['date_to'] : '',
    'paid_status' => in_array(($_GET['paid_status'] ?? ''), paid_status_options(), true) ? $_GET['paid_status'] : '',
    'feeding' => in_array(($_GET['feeding'] ?? ''), ['yes', 'no'], true) ? $_GET['feeding'] : '',
    'transport' => in_array(($_GET['transport'] ?? ''), ['yes', 'no'], true) ? $_GET['transport'] : '',
    'include_payments' => ($_GET['include_payments'] ?? '') === '1' ? '1' : '',
];
sync_current_term_fee_balances($pdo);

function report_scalar(PDO $pdo, string $sql, array $params = []): float
{
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return (float) $statement->fetchColumn();
}

function report_rows(PDO $pdo, string $sql, array $params = []): array
{
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll();
}

function fee_payment_filter(array $filters): array
{
    $where = [];
    $params = [];

    if ($filters['class_level'] !== '') {
        $where[] = 'students.class_level = :fee_class_level';
        $params['fee_class_level'] = $filters['class_level'];
    }
    if ($filters['gender'] !== '') {
        $where[] = 'students.gender = :fee_gender';
        $params['fee_gender'] = $filters['gender'];
    }
    if ($filters['year'] !== '') {
        $where[] = 'fees.year = :fee_year';
        $params['fee_year'] = $filters['year'];
    }
    if ($filters['term'] !== '') {
        $where[] = 'fees.term = :fee_term';
        $params['fee_term'] = $filters['term'];
    }
    if ($filters['date_from'] !== '') {
        $where[] = 'fees.payment_date >= :fee_date_from';
        $params['fee_date_from'] = $filters['date_from'];
    }
    if ($filters['date_to'] !== '') {
        $where[] = 'fees.payment_date <= :fee_date_to';
        $params['fee_date_to'] = $filters['date_to'];
    }

    return [$where ? ' WHERE ' . implode(' AND ', $where) : '', $params];
}

function fee_balance_filter(array $filters, bool $defaultersOnly = false): array
{
    $where = [];
    $params = [];

    if ($defaultersOnly) {
        $where[] = 'fee_balances.balance > 0';
    }
    if ($filters['class_level'] !== '') {
        $where[] = 'students.class_level = :balance_class_level';
        $params['balance_class_level'] = $filters['class_level'];
    }
    if ($filters['year'] !== '') {
        $where[] = 'fee_balances.academic_year = :balance_year';
        $params['balance_year'] = $filters['year'];
    }
    if ($filters['term'] !== '') {
        $where[] = 'fee_balances.term = :balance_term';
        $params['balance_term'] = $filters['term'];
    }

    return [$where ? ' WHERE ' . implode(' AND ', $where) : '', $params];
}

function feeding_payment_filter(array $filters): array
{
    $where = [];
    $params = [];

    if ($filters['class_level'] !== '') {
        $where[] = 'students.class_level = :feeding_class_level';
        $params['feeding_class_level'] = $filters['class_level'];
    }
    if ($filters['gender'] !== '') {
        $where[] = 'students.gender = :feeding_gender';
        $params['feeding_gender'] = $filters['gender'];
    }
    if ($filters['year'] !== '') {
        $where[] = 'feeding_subscriptions.academic_year = :feeding_year';
        $params['feeding_year'] = $filters['year'];
    }
    if ($filters['term'] !== '') {
        $where[] = 'feeding_subscriptions.term = :feeding_term';
        $params['feeding_term'] = $filters['term'];
    }
    if ($filters['date_from'] !== '') {
        $where[] = 'feeding_payments.payment_date >= :feeding_date_from';
        $params['feeding_date_from'] = $filters['date_from'];
    }
    if ($filters['date_to'] !== '') {
        $where[] = 'feeding_payments.payment_date <= :feeding_date_to';
        $params['feeding_date_to'] = $filters['date_to'];
    }

    return [$where ? ' WHERE ' . implode(' AND ', $where) : '', $params];
}

function transport_payment_filter(array $filters): array
{
    $where = [];
    $params = [];

    if ($filters['class_level'] !== '') {
        $where[] = 'students.class_level = :transport_class_level';
        $params['transport_class_level'] = $filters['class_level'];
    }
    if ($filters['gender'] !== '') {
        $where[] = 'students.gender = :transport_gender';
        $params['transport_gender'] = $filters['gender'];
    }
    if ($filters['year'] !== '') {
        $where[] = 'transport_accounts.academic_year = :transport_year';
        $params['transport_year'] = $filters['year'];
    }
    if ($filters['term'] !== '') {
        $where[] = 'transport_accounts.term = :transport_term';
        $params['transport_term'] = $filters['term'];
    }
    if ($filters['date_from'] !== '') {
        $where[] = 'transport_payments.payment_date >= :transport_date_from';
        $params['transport_date_from'] = $filters['date_from'];
    }
    if ($filters['date_to'] !== '') {
        $where[] = 'transport_payments.payment_date <= :transport_date_to';
        $params['transport_date_to'] = $filters['date_to'];
    }

    return [$where ? ' WHERE ' . implode(' AND ', $where) : '', $params];
}

function dated_expense_filter(array $filters, string $dateColumn, string $prefix): array
{
    $where = [];
    $params = [];

    if ($filters['year'] !== '') {
        $where[] = "YEAR({$dateColumn}) = :{$prefix}_year";
        $params["{$prefix}_year"] = $filters['year'];
    }
    if ($filters['date_from'] !== '') {
        $where[] = "{$dateColumn} >= :{$prefix}_date_from";
        $params["{$prefix}_date_from"] = $filters['date_from'];
    }
    if ($filters['date_to'] !== '') {
        $where[] = "{$dateColumn} <= :{$prefix}_date_to";
        $params["{$prefix}_date_to"] = $filters['date_to'];
    }

    return [$where ? ' WHERE ' . implode(' AND ', $where) : '', $params];
}

$studentConditions = [];
$studentParams = [];
if ($filters['class_level'] !== '') {
    $studentConditions[] = 'class_level = :student_class_level';
    $studentParams['student_class_level'] = $filters['class_level'];
}
if ($filters['gender'] !== '') {
    $studentConditions[] = 'gender = :student_gender';
    $studentParams['student_gender'] = $filters['gender'];
}
$studentWhere = $studentConditions ? ' WHERE ' . implode(' AND ', $studentConditions) : '';
$totalStudents = (int) report_scalar($pdo, "SELECT COUNT(*) FROM students{$studentWhere}", $studentParams);
$studentsPerClass = report_rows(
    $pdo,
    "SELECT class_level, COUNT(*) AS student_count
     FROM students{$studentWhere}
     GROUP BY class_level
     ORDER BY class_level ASC",
    $studentParams
);

[$feeWhere, $feeParams] = fee_payment_filter($filters);
[$feedingWhere, $feedingParams] = feeding_payment_filter($filters);
[$transportWhere, $transportParams] = transport_payment_filter($filters);
[$kitchenWhere, $kitchenParams] = dated_expense_filter($filters, 'item_date', 'kitchen');
[$expenseWhere, $expenseParams] = dated_expense_filter($filters, 'expense_date', 'expense');

$totalFeePaid = report_scalar($pdo, "SELECT COALESCE(SUM(fees.amount_paid), 0) FROM fees JOIN students ON students.id = fees.student_id{$feeWhere}", $feeParams);
$kitchenExpenses = report_scalar($pdo, "SELECT COALESCE(SUM(total_amount), 0) FROM kitchen_inventory{$kitchenWhere}", $kitchenParams);
$schoolExpenses = report_scalar($pdo, "SELECT COALESCE(SUM(total_amount), 0) FROM school_expenses{$expenseWhere}", $expenseParams);
$feedingPayments = report_scalar(
    $pdo,
    "SELECT COALESCE(SUM(feeding_payments.amount_paid), 0)
     FROM feeding_payments
     JOIN feeding_subscriptions ON feeding_subscriptions.id = feeding_payments.feeding_subscription_id
     JOIN students ON students.id = feeding_subscriptions.student_id{$feedingWhere}",
    $feedingParams
);
$transportPayments = report_scalar(
    $pdo,
    "SELECT COALESCE(SUM(transport_payments.amount_paid), 0)
     FROM transport_payments
     JOIN transport_accounts ON transport_accounts.id = transport_payments.transport_account_id
     JOIN transport_students ON transport_students.id = transport_accounts.transport_student_id
     LEFT JOIN students ON students.id = transport_students.student_id{$transportWhere}",
    $transportParams
);

$feeTermReport = fetch_fee_term_report($pdo, $filters);
$feeBalanceRows = array_values(array_filter($feeTermReport, fn($row) => (float) $row['balance'] > 0.005));
$totalRequiredFees = array_sum(array_map(fn($row) => (float) $row['required_amount'], $feeTermReport));
$totalPaidForBalances = array_sum(array_map(fn($row) => (float) $row['paid_amount'], $feeTermReport));
$totalFeeBalances = array_sum(array_map(fn($row) => (float) $row['balance'], $feeTermReport));

$outstandingTermBalances = $feeBalanceRows;
usort($outstandingTermBalances, fn($left, $right) => (float) $right['balance'] <=> (float) $left['balance']);

$unpaidByTerm = [];
foreach ($feeBalanceRows as $row) {
    $termKey = $row['academic_year'] . '|' . $row['term'];
    if (!isset($unpaidByTerm[$termKey])) {
        $unpaidByTerm[$termKey] = [
            'academic_year' => $row['academic_year'],
            'term' => $row['term'],
            'total_balance' => 0.0,
        ];
    }
    $unpaidByTerm[$termKey]['total_balance'] += (float) $row['balance'];
}
usort($unpaidByTerm, fn($left, $right) => [$right['academic_year'], $left['term']] <=> [$left['academic_year'], $right['term']]);

$totalIncome = $totalFeePaid + $feedingPayments + $transportPayments;
$totalExpenditure = $kitchenExpenses + $schoolExpenses;
$netIncome = $totalIncome - $totalExpenditure;

$paymentsByTerm = report_rows(
    $pdo,
    "SELECT fees.year,
            fees.term,
            CONCAT(fees.year, ' ', fees.term) AS term_label,
            COALESCE(SUM(fees.amount_paid), 0) AS total_paid
     FROM fees
     JOIN students ON students.id = fees.student_id{$feeWhere}
     GROUP BY fees.year, fees.term
     ORDER BY fees.year DESC, FIELD(fees.term, 'Term 1', 'Term 2', 'Term 3')",
    $feeParams
);
$dailyCollections = report_rows(
    $pdo,
    "SELECT fees.payment_date AS collection_date, COALESCE(SUM(fees.amount_paid), 0) AS total_paid
     FROM fees
     JOIN students ON students.id = fees.student_id{$feeWhere}
     GROUP BY fees.payment_date
     ORDER BY fees.payment_date DESC
     LIMIT 31",
    $feeParams
);
$monthlyCollections = report_rows(
    $pdo,
    "SELECT DATE_FORMAT(fees.payment_date, '%Y-%m') AS collection_month, COALESCE(SUM(fees.amount_paid), 0) AS total_paid
     FROM fees
     JOIN students ON students.id = fees.student_id{$feeWhere}
     GROUP BY DATE_FORMAT(fees.payment_date, '%Y-%m')
     ORDER BY collection_month DESC
     LIMIT 24",
    $feeParams
);
$expenseSummaries = report_rows(
    $pdo,
    "SELECT category, COALESCE(SUM(total_amount), 0) AS total_amount
     FROM school_expenses{$expenseWhere}
     GROUP BY category
     ORDER BY total_amount DESC",
    $expenseParams
);
$feedingByTerm = report_rows(
    $pdo,
    "SELECT feeding_subscriptions.term, COALESCE(SUM(feeding_payments.amount_paid), 0) AS total_paid
     FROM feeding_payments
     JOIN feeding_subscriptions ON feeding_subscriptions.id = feeding_payments.feeding_subscription_id
     JOIN students ON students.id = feeding_subscriptions.student_id{$feedingWhere}
     GROUP BY feeding_subscriptions.term
     ORDER BY FIELD(feeding_subscriptions.term, 'Term 1', 'Term 2', 'Term 3')",
    $feedingParams
);
$feedingBalanceWhere = [];
$feedingBalanceParams = [];
if ($filters['class_level'] !== '') {
    $feedingBalanceWhere[] = 'students.class_level = :feeding_balance_class';
    $feedingBalanceParams['feeding_balance_class'] = $filters['class_level'];
}
if ($filters['gender'] !== '') {
    $feedingBalanceWhere[] = 'students.gender = :feeding_balance_gender';
    $feedingBalanceParams['feeding_balance_gender'] = $filters['gender'];
}
if ($filters['year'] !== '') {
    $feedingBalanceWhere[] = 'feeding_subscriptions.academic_year = :feeding_balance_year';
    $feedingBalanceParams['feeding_balance_year'] = $filters['year'];
}
if ($filters['term'] !== '') {
    $feedingBalanceWhere[] = 'feeding_subscriptions.term = :feeding_balance_term';
    $feedingBalanceParams['feeding_balance_term'] = $filters['term'];
}
$feedingBalanceSql = "SELECT
        students.registration_no,
        students.full_name,
        students.class_level,
        feeding_subscriptions.academic_year,
        feeding_subscriptions.term,
        feeding_subscriptions.feeding_amount AS required_amount,
        COALESCE(SUM(feeding_payments.amount_paid), 0) AS paid_amount,
        feeding_subscriptions.feeding_amount - COALESCE(SUM(feeding_payments.amount_paid), 0) AS balance
     FROM feeding_subscriptions
     JOIN students ON students.id = feeding_subscriptions.student_id
     LEFT JOIN feeding_payments ON feeding_payments.feeding_subscription_id = feeding_subscriptions.id";
if ($feedingBalanceWhere) {
    $feedingBalanceSql .= ' WHERE ' . implode(' AND ', $feedingBalanceWhere);
}
$feedingBalanceSql .= " GROUP BY feeding_subscriptions.id
     HAVING balance > 0
     ORDER BY feeding_subscriptions.academic_year DESC, FIELD(feeding_subscriptions.term, 'Term 1', 'Term 2', 'Term 3'), students.full_name ASC";
$feedingBalanceRows = report_rows($pdo, $feedingBalanceSql, $feedingBalanceParams);
$transportByTerm = report_rows(
    $pdo,
    "SELECT transport_accounts.term, COALESCE(SUM(transport_payments.amount_paid), 0) AS total_paid
     FROM transport_payments
     JOIN transport_accounts ON transport_accounts.id = transport_payments.transport_account_id
     JOIN transport_students ON transport_students.id = transport_accounts.transport_student_id
     LEFT JOIN students ON students.id = transport_students.student_id{$transportWhere}
     GROUP BY transport_accounts.term
     ORDER BY FIELD(transport_accounts.term, 'Term 1', 'Term 2', 'Term 3')",
    $transportParams
);

$feedingReportRows = [];
$feedingPaymentRows = [];
if ($filters['feeding'] !== 'no') {
    $feedingReportWhere = [];
    $feedingReportParams = [];
    $feedingPaymentJoin = '';
    if ($filters['class_level'] !== '') {
        $feedingReportWhere[] = 'students.class_level = :feeding_report_class';
        $feedingReportParams['feeding_report_class'] = $filters['class_level'];
    }
    if ($filters['gender'] !== '') {
        $feedingReportWhere[] = 'students.gender = :feeding_report_gender';
        $feedingReportParams['feeding_report_gender'] = $filters['gender'];
    }
    if ($filters['year'] !== '') {
        $feedingReportWhere[] = 'feeding_subscriptions.academic_year = :feeding_report_year';
        $feedingReportParams['feeding_report_year'] = $filters['year'];
    }
    if ($filters['term'] !== '') {
        $feedingReportWhere[] = 'feeding_subscriptions.term = :feeding_report_term';
        $feedingReportParams['feeding_report_term'] = $filters['term'];
    }
    if ($filters['date_from'] !== '') {
        $feedingPaymentJoin .= ' AND feeding_payments.payment_date >= :feeding_report_date_from';
        $feedingReportParams['feeding_report_date_from'] = $filters['date_from'];
    }
    if ($filters['date_to'] !== '') {
        $feedingPaymentJoin .= ' AND feeding_payments.payment_date <= :feeding_report_date_to';
        $feedingReportParams['feeding_report_date_to'] = $filters['date_to'];
    }

    $feedingReportSql = "SELECT
            feeding_subscriptions.*,
            students.registration_no,
            students.full_name,
            students.gender,
            students.parent_name,
            students.guardian_phone,
            students.class_level,
            feeding_subscriptions.feeding_amount AS required_amount,
            COALESCE(SUM(feeding_payments.amount_paid), 0) AS paid_amount,
            feeding_subscriptions.feeding_amount - COALESCE(SUM(feeding_payments.amount_paid), 0) AS balance
         FROM feeding_subscriptions
         JOIN students ON students.id = feeding_subscriptions.student_id
         LEFT JOIN feeding_payments ON feeding_payments.feeding_subscription_id = feeding_subscriptions.id{$feedingPaymentJoin}";
    if ($feedingReportWhere) {
        $feedingReportSql .= ' WHERE ' . implode(' AND ', $feedingReportWhere);
    }
    $feedingReportSql .= " GROUP BY feeding_subscriptions.id
         ORDER BY feeding_subscriptions.academic_year DESC, FIELD(feeding_subscriptions.term, 'Term 1', 'Term 2', 'Term 3'), students.full_name ASC";
    $feedingReportRows = report_rows($pdo, $feedingReportSql, $feedingReportParams);

    $feedingPaymentRows = report_rows(
        $pdo,
        "SELECT
            feeding_payments.reference_no,
            feeding_payments.amount_paid,
            feeding_payments.payment_date,
            feeding_payments.created_at,
            students.registration_no,
            students.full_name,
            students.class_level,
            feeding_subscriptions.academic_year,
            feeding_subscriptions.term
         FROM feeding_payments
         JOIN feeding_subscriptions ON feeding_subscriptions.id = feeding_payments.feeding_subscription_id
         JOIN students ON students.id = feeding_subscriptions.student_id{$feedingWhere}
         ORDER BY feeding_payments.payment_date DESC, feeding_payments.id DESC",
        $feedingParams
    );
}

$transportAccountRows = [];
$transportPaymentRows = [];
if ($filters['transport'] !== 'no') {
    $transportAccountWhere = [];
    $transportAccountParams = [];
    $transportPaymentJoin = '';
    if ($filters['class_level'] !== '') {
        $transportAccountWhere[] = 'students.class_level = :transport_account_class';
        $transportAccountParams['transport_account_class'] = $filters['class_level'];
    }
    if ($filters['gender'] !== '') {
        $transportAccountWhere[] = 'transport_students.gender = :transport_account_gender';
        $transportAccountParams['transport_account_gender'] = $filters['gender'];
    }
    if ($filters['year'] !== '') {
        $transportAccountWhere[] = 'transport_accounts.academic_year = :transport_account_year';
        $transportAccountParams['transport_account_year'] = $filters['year'];
    }
    if ($filters['term'] !== '') {
        $transportAccountWhere[] = 'transport_accounts.term = :transport_account_term';
        $transportAccountParams['transport_account_term'] = $filters['term'];
    }
    if ($filters['date_from'] !== '') {
        $transportPaymentJoin .= ' AND transport_payments.payment_date >= :transport_account_date_from';
        $transportAccountParams['transport_account_date_from'] = $filters['date_from'];
    }
    if ($filters['date_to'] !== '') {
        $transportPaymentJoin .= ' AND transport_payments.payment_date <= :transport_account_date_to';
        $transportAccountParams['transport_account_date_to'] = $filters['date_to'];
    }

    $transportAccountSql = "SELECT
            transport_accounts.*,
            transport_students.student_name,
            transport_students.gender,
            transport_students.parent_name,
            transport_students.parent_phone,
            transport_students.pickup_location,
            transport_students.is_outside,
            students.registration_no,
            students.class_level,
            COALESCE(SUM(transport_payments.amount_paid), 0) AS paid_amount,
            transport_accounts.amount_due - COALESCE(SUM(transport_payments.amount_paid), 0) AS balance
         FROM transport_accounts
         JOIN transport_students ON transport_students.id = transport_accounts.transport_student_id
         LEFT JOIN students ON students.id = transport_students.student_id
         LEFT JOIN transport_payments ON transport_payments.transport_account_id = transport_accounts.id{$transportPaymentJoin}";
    if ($transportAccountWhere) {
        $transportAccountSql .= ' WHERE ' . implode(' AND ', $transportAccountWhere);
    }
    $transportAccountSql .= " GROUP BY transport_accounts.id
         ORDER BY transport_accounts.academic_year DESC, FIELD(transport_accounts.term, 'Term 1', 'Term 2', 'Term 3'), transport_students.student_name ASC";
    $transportAccountRows = report_rows($pdo, $transportAccountSql, $transportAccountParams);

    $transportPaymentRows = report_rows(
        $pdo,
        "SELECT
            transport_payments.reference_no,
            transport_payments.amount_paid,
            transport_payments.payment_date,
            transport_payments.created_at,
            transport_students.student_name,
            transport_students.pickup_location,
            transport_accounts.academic_year,
            transport_accounts.term
         FROM transport_payments
         JOIN transport_accounts ON transport_accounts.id = transport_payments.transport_account_id
         JOIN transport_students ON transport_students.id = transport_accounts.transport_student_id
         LEFT JOIN students ON students.id = transport_students.student_id{$transportWhere}
         ORDER BY transport_payments.payment_date DESC, transport_payments.id DESC",
        $transportParams
    );
}

$kitchenRows = report_rows(
    $pdo,
    "SELECT item_name, quantity, unit_price, total_amount, item_date, supplier, created_at, updated_at
     FROM kitchen_inventory{$kitchenWhere}
     ORDER BY item_date DESC, id DESC",
    $kitchenParams
);

$paymentHistory = [];
if ($filters['include_payments'] === '1') {
    $paymentHistory = report_rows(
        $pdo,
        "SELECT
            fees.receipt_no,
            students.full_name,
            fees.amount_paid,
            'M-PESA' AS payment_method,
            fees.mpesa_code,
            fees.payment_date
         FROM fees
         JOIN students ON students.id = fees.student_id{$feeWhere}
         ORDER BY fees.payment_date DESC, fees.id DESC",
        $feeParams
    );
}

$expenseChartLabels = ['Kitchen Inventory'];
$expenseChartValues = [$kitchenExpenses];
foreach ($expenseSummaries as $row) {
    $expenseChartLabels[] = $row['category'];
    $expenseChartValues[] = (float) $row['total_amount'];
}

$exportFilters = array_filter($filters, fn($value) => $value !== '');
$excelUrl = url('admin/reports_export.php?' . http_build_query(array_merge($exportFilters, ['format' => 'xlsx'])));
$pdfUrl = url('admin/reports_export.php?' . http_build_query(array_merge($exportFilters, ['format' => 'pdf'])));
$csvUrl = url('admin/reports_export.php?' . http_build_query(array_merge($exportFilters, ['format' => 'csv'])));
$printUrl = url('admin/reports_export.php?' . http_build_query(array_merge($exportFilters, ['format' => 'print'])));

require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title no-print">
    <div>
        <p class="eyebrow">School Analytics</p>
        <h1>Reports Dashboard</h1>
    </div>
    <div class="action-row">
        <div class="dropdown export-dropdown">
            <button class="btn export-button dropdown-toggle" type="button" aria-haspopup="true" aria-expanded="false" data-export-toggle>
                <i class="fa-solid fa-file-export"></i> <span data-export-label>Export</span> <i class="fa-solid fa-chevron-down"></i>
            </button>
            <div class="dropdown-menu dropdown-menu-end modern-dropdown export-menu">
                <a class="dropdown-item" href="<?= h($pdfUrl) ?>" data-export-option><i class="fa-regular fa-file-pdf"></i> Export PDF</a>
                <a class="dropdown-item" href="<?= h($excelUrl) ?>" data-export-option><i class="fa-regular fa-file-excel"></i> Export Excel (.xlsx)</a>
                <a class="dropdown-item" href="<?= h($csvUrl) ?>" data-export-option><i class="fa-solid fa-file-csv"></i> Export CSV</a>
                <a class="dropdown-item" href="<?= h($printUrl) ?>" target="_blank" rel="noopener" data-export-option><i class="fa-solid fa-print"></i> Print Report</a>
            </div>
        </div>
    </div>
</div>

<?php if ($message = flash('error')): ?>
    <div class="alert alert-danger no-print"><?= h($message) ?></div>
<?php endif; ?>

<style>
.page-title {
    position: relative;
    z-index: 2500;
}

.export-dropdown {
    position: relative;
    z-index: 3000;
    pointer-events: auto !important;
}

.export-button {
    cursor: pointer;
    pointer-events: auto !important;
}

.export-menu {
    z-index: 3100;
    pointer-events: auto !important;
}

.export-menu.show {
    display: block !important;
}
</style>

<script>
function toggleReportExportMenu(event) {
    event.preventDefault();
    event.stopPropagation();

    var button = event.currentTarget;
    var dropdown = button.closest('.export-dropdown');
    var menu = dropdown ? dropdown.querySelector('.export-menu') : null;

    if (!menu) {
        return false;
    }

    document.querySelectorAll('.export-menu.show').forEach(function (openMenu) {
        if (openMenu !== menu) {
            openMenu.classList.remove('show');
        }
    });

    var isOpen = menu.classList.toggle('show');
    button.setAttribute('aria-expanded', isOpen ? 'true' : 'false');

    return false;
}

document.addEventListener('click', function (event) {
    if (!event.target.closest('.export-dropdown')) {
        document.querySelectorAll('.export-menu.show').forEach(function (menu) {
            menu.classList.remove('show');
        });
        document.querySelectorAll('[data-export-toggle]').forEach(function (button) {
            button.setAttribute('aria-expanded', 'false');
        });
    }
});
</script>

<section class="panel no-print">
    <form class="row g-3" method="get">
        <div class="col-md-2">
            <label class="form-label" for="class_level">Class</label>
            <select class="form-select" id="class_level" name="class_level">
                <option value="">All classes</option>
                <?php foreach ($classLevels as $classLevel): ?>
                    <option value="<?= h($classLevel) ?>" <?= $filters['class_level'] === $classLevel ? 'selected' : '' ?>><?= h($classLevel) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label" for="gender">Gender</label>
            <select class="form-select" id="gender" name="gender">
                <option value="">All genders</option>
                <?php foreach (gender_options() as $gender): ?>
                    <option value="<?= h($gender) ?>" <?= $filters['gender'] === $gender ? 'selected' : '' ?>><?= h($gender) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label" for="year">Year</label>
            <input class="form-control" id="year" name="year" maxlength="4" inputmode="numeric" value="<?= h($filters['year']) ?>" placeholder="<?= h(current_academic_year()) ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label" for="term">Term</label>
            <select class="form-select" id="term" name="term">
                <option value="">All terms</option>
                <?php foreach ($terms as $term): ?>
                    <option value="<?= h($term) ?>" <?= $filters['term'] === $term ? 'selected' : '' ?>><?= h($term) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label" for="paid_status">Paid Status</label>
            <select class="form-select" id="paid_status" name="paid_status">
                <option value="">All statuses</option>
                <?php foreach (paid_status_options() as $status): ?>
                    <option value="<?= h($status) ?>" <?= $filters['paid_status'] === $status ? 'selected' : '' ?>><?= h($status) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label" for="date_from">From</label>
            <input class="form-control" type="date" id="date_from" name="date_from" value="<?= h($filters['date_from']) ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label" for="date_to">To</label>
            <input class="form-control" type="date" id="date_to" name="date_to" value="<?= h($filters['date_to']) ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label" for="feeding">Feeding</label>
            <select class="form-select" id="feeding" name="feeding">
                <option value="">All students</option>
                <option value="yes" <?= $filters['feeding'] === 'yes' ? 'selected' : '' ?>>Feeding only</option>
                <option value="no" <?= $filters['feeding'] === 'no' ? 'selected' : '' ?>>Non-feeding</option>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label" for="transport">Transport</label>
            <select class="form-select" id="transport" name="transport">
                <option value="">All students</option>
                <option value="yes" <?= $filters['transport'] === 'yes' ? 'selected' : '' ?>>Transport only</option>
                <option value="no" <?= $filters['transport'] === 'no' ? 'selected' : '' ?>>Non-transport</option>
            </select>
        </div>
        <div class="col-md-2 d-flex align-items-end">
            <label class="modern-check">
                <input type="checkbox" name="include_payments" value="1" <?= $filters['include_payments'] === '1' ? 'checked' : '' ?>>
                <span>Payment history</span>
            </label>
        </div>
        <div class="col-md-2 d-flex align-items-end">
            <button class="btn btn-outline-primary w-100" type="submit">Apply Filters</button>
        </div>
    </form>
</section>

<div class="row g-4 mt-1">
    <div class="col-md-2"><div class="metric-card"><span><?= $totalStudents ?></span><p>Total Students</p></div></div>
    <div class="col-md-2"><div class="metric-card"><span><?= money($totalFeeBalances) ?></span><p>Term Fee Balances</p></div></div>
    <div class="col-md-2"><div class="metric-card"><span><?= money($totalFeePaid) ?></span><p>Term Fee Paid</p></div></div>
    <div class="col-md-2"><div class="metric-card"><span><?= money($transportPayments) ?></span><p>Transport Payments</p></div></div>
    <div class="col-md-2"><div class="metric-card"><span><?= money($kitchenExpenses) ?></span><p>Kitchen Expenses</p></div></div>
    <div class="col-md-2"><div class="metric-card"><span><?= money($netIncome) ?></span><p>Net Income</p></div></div>
</div>

<section class="panel mt-4">
    <div class="panel-heading">
        <div>
            <h2>Accountant Fee Report</h2>
            <p class="panel-subtitle">Export-ready student fee status with expected fees, paid amount, balances, and last payment date.</p>
        </div>
        <strong><?= $totalRequiredFees > 0 ? round(($totalPaidForBalances / $totalRequiredFees) * 100, 2) : 0 ?>% collected</strong>
    </div>
    <div class="table-responsive report-table">
        <table class="table table-striped align-middle">
            <thead>
                <tr>
                    <th>Admission No</th>
                    <th>Student Name</th>
                    <th>Gender</th>
                    <th>Class Level</th>
                    <th>Academic Year</th>
                    <th>Term</th>
                    <th>Expected Fees</th>
                    <th>Paid Amount</th>
                    <th>Balance</th>
                    <th>Status</th>
                    <th>Last Payment Date</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($feeTermReport as $row): ?>
                    <?php $statusClass = 'status-' . strtolower($row['status']); ?>
                    <tr>
                        <td><?= h($row['registration_no']) ?></td>
                        <td><?= h($row['full_name']) ?></td>
                        <td><?= h($row['gender']) ?></td>
                        <td><?= h($row['class_level']) ?></td>
                        <td><?= h($row['academic_year']) ?></td>
                        <td><?= h($row['term']) ?></td>
                        <td><?= money((float) $row['required_amount']) ?></td>
                        <td><?= money((float) $row['paid_amount']) ?></td>
                        <td><?= money((float) $row['balance']) ?></td>
                        <td><span class="status-badge <?= h($statusClass) ?>"><?= h(strtoupper($row['status'])) ?></span></td>
                        <td><?= h($row['last_payment_date'] ?: 'N/A') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$feeTermReport): ?><tr><td colspan="11">No fee report records found.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php if ($filters['include_payments'] === '1'): ?>
    <section class="panel mt-4">
        <div class="panel-heading">
            <h2>Payment History</h2>
        </div>
        <div class="table-responsive report-table">
            <table class="table table-striped align-middle">
                <thead><tr><th>Receipt Number</th><th>Student Name</th><th>Amount</th><th>Payment Method</th><th>Mpesa Code</th><th>Date</th></tr></thead>
                <tbody>
                    <?php foreach ($paymentHistory as $payment): ?>
                        <tr>
                            <td><?= h($payment['receipt_no']) ?></td>
                            <td><?= h($payment['full_name']) ?></td>
                            <td><?= money((float) $payment['amount_paid']) ?></td>
                            <td><?= h($payment['payment_method']) ?></td>
                            <td><?= h($payment['mpesa_code']) ?></td>
                            <td><?= h($payment['payment_date']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$paymentHistory): ?><tr><td colspan="6">No payment history found.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<section class="panel mt-4">
    <div class="panel-heading">
        <div>
            <h2>Kitchen Inventory Purchases</h2>
            <p class="panel-subtitle">All kitchen items bought, including quantity, supplier, purchase date, and recorded timestamps.</p>
        </div>
        <strong><?= money($kitchenExpenses) ?></strong>
    </div>
    <div class="table-responsive report-table">
        <table class="table table-striped align-middle">
            <thead>
                <tr><th>Item</th><th>Quantity</th><th>Unit Price</th><th>Total</th><th>Date Bought</th><th>Supplier</th><th>Added</th><th>Updated</th></tr>
            </thead>
            <tbody>
                <?php foreach ($kitchenRows as $row): ?>
                    <tr>
                        <td><?= h($row['item_name']) ?></td>
                        <td><?= h(number_format((float) $row['quantity'], 2)) ?></td>
                        <td><?= money((float) $row['unit_price']) ?></td>
                        <td><?= money((float) $row['total_amount']) ?></td>
                        <td><?= h($row['item_date']) ?></td>
                        <td><?= h($row['supplier'] ?: 'N/A') ?></td>
                        <td><?= h($row['created_at']) ?></td>
                        <td><?= h($row['updated_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$kitchenRows): ?><tr><td colspan="8">No kitchen inventory purchases found.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="panel mt-4">
    <div class="panel-heading">
        <div>
            <h2>Feeding Report</h2>
            <p class="panel-subtitle">All feeding subscriptions with student, parent, required amount, paid amount, balance, and status details.</p>
        </div>
        <strong><?= count($feedingReportRows) ?> records</strong>
    </div>
    <div class="table-responsive report-table">
        <table class="table table-striped align-middle">
            <thead>
                <tr><th>Admission No</th><th>Student</th><th>Gender</th><th>Parent</th><th>Phone</th><th>Class</th><th>Year</th><th>Term</th><th>Required</th><th>Paid</th><th>Balance</th><th>Status</th><th>Added</th><th>Updated</th></tr>
            </thead>
            <tbody>
                <?php foreach ($feedingReportRows as $row): ?>
                    <tr class="<?= (float) $row['balance'] > 0.005 ? 'balance-high' : '' ?>">
                        <td><?= h($row['registration_no']) ?></td>
                        <td><?= h($row['full_name']) ?></td>
                        <td><?= h($row['gender']) ?></td>
                        <td><?= h($row['parent_name']) ?></td>
                        <td><?= h($row['guardian_phone'] ?: 'N/A') ?></td>
                        <td><?= h($row['class_level']) ?></td>
                        <td><?= h($row['academic_year']) ?></td>
                        <td><?= h($row['term']) ?></td>
                        <td><?= money((float) $row['required_amount']) ?></td>
                        <td><?= money((float) $row['paid_amount']) ?></td>
                        <td><?= money((float) $row['balance']) ?></td>
                        <td><?= h($row['status']) ?></td>
                        <td><?= h($row['created_at']) ?></td>
                        <td><?= h($row['updated_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$feedingReportRows): ?><tr><td colspan="14">No feeding records found.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="panel mt-4">
    <div class="panel-heading">
        <div>
            <h2>Feeding Payment History</h2>
            <p class="panel-subtitle">All feeding payments with references, student details, academic term, payment date, and recorded date.</p>
        </div>
        <strong><?= money(array_sum(array_map(fn($row) => (float) $row['amount_paid'], $feedingPaymentRows))) ?></strong>
    </div>
    <div class="table-responsive report-table">
        <table class="table table-striped align-middle">
            <thead>
                <tr><th>Reference</th><th>Student</th><th>Class</th><th>Academic Year</th><th>Term</th><th>Amount</th><th>Payment Date</th><th>Recorded At</th></tr>
            </thead>
            <tbody>
                <?php foreach ($feedingPaymentRows as $row): ?>
                    <tr>
                        <td><?= h($row['reference_no'] ?: 'N/A') ?></td>
                        <td><?= h($row['full_name']) ?><br><small><?= h($row['registration_no']) ?></small></td>
                        <td><?= h($row['class_level']) ?></td>
                        <td><?= h($row['academic_year']) ?></td>
                        <td><?= h($row['term']) ?></td>
                        <td><?= money((float) $row['amount_paid']) ?></td>
                        <td><?= h($row['payment_date']) ?></td>
                        <td><?= h($row['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$feedingPaymentRows): ?><tr><td colspan="8">No feeding payment history found.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="panel mt-4">
    <div class="panel-heading">
        <div>
            <h2>Transport Accounts Report</h2>
            <p class="panel-subtitle">All transport students and accounts with parent, route, amount due, payments, balances, and status details.</p>
        </div>
        <strong><?= count($transportAccountRows) ?> records</strong>
    </div>
    <div class="table-responsive report-table">
        <table class="table table-striped align-middle">
            <thead>
                <tr><th>Student</th><th>Admission/Type</th><th>Gender</th><th>Parent</th><th>Phone</th><th>Route</th><th>Year</th><th>Term</th><th>Due</th><th>Paid</th><th>Balance</th><th>Status</th><th>Added</th><th>Updated</th></tr>
            </thead>
            <tbody>
                <?php foreach ($transportAccountRows as $row): ?>
                    <tr class="<?= (float) $row['balance'] > 0.005 ? 'balance-high' : '' ?>">
                        <td><?= h($row['student_name']) ?></td>
                        <td><?= h($row['registration_no'] ?: ((int) $row['is_outside'] === 1 ? 'Outside student' : 'N/A')) ?><br><small><?= h($row['class_level'] ?: 'N/A') ?></small></td>
                        <td><?= h($row['gender']) ?></td>
                        <td><?= h($row['parent_name']) ?></td>
                        <td><?= h($row['parent_phone'] ?: 'N/A') ?></td>
                        <td><?= h($row['pickup_location']) ?></td>
                        <td><?= h($row['academic_year']) ?></td>
                        <td><?= h($row['term']) ?></td>
                        <td><?= money((float) $row['amount_due']) ?></td>
                        <td><?= money((float) $row['paid_amount']) ?></td>
                        <td><?= money((float) $row['balance']) ?></td>
                        <td><?= h($row['status']) ?></td>
                        <td><?= h($row['created_at']) ?></td>
                        <td><?= h($row['updated_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$transportAccountRows): ?><tr><td colspan="14">No transport account records found.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="panel mt-4">
    <div class="panel-heading">
        <div>
            <h2>Transport Payment History</h2>
            <p class="panel-subtitle">All transport payments with references, route, academic term, payment date, and recorded date.</p>
        </div>
        <strong><?= money(array_sum(array_map(fn($row) => (float) $row['amount_paid'], $transportPaymentRows))) ?></strong>
    </div>
    <div class="table-responsive report-table">
        <table class="table table-striped align-middle">
            <thead>
                <tr><th>Reference</th><th>Student</th><th>Route</th><th>Academic Year</th><th>Term</th><th>Amount</th><th>Payment Date</th><th>Recorded At</th></tr>
            </thead>
            <tbody>
                <?php foreach ($transportPaymentRows as $row): ?>
                    <tr>
                        <td><?= h($row['reference_no'] ?: 'N/A') ?></td>
                        <td><?= h($row['student_name']) ?></td>
                        <td><?= h($row['pickup_location']) ?></td>
                        <td><?= h($row['academic_year']) ?></td>
                        <td><?= h($row['term']) ?></td>
                        <td><?= money((float) $row['amount_paid']) ?></td>
                        <td><?= h($row['payment_date']) ?></td>
                        <td><?= h($row['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$transportPaymentRows): ?><tr><td colspan="8">No transport payment history found.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="panel mt-4">
    <h2>Feeding Balances</h2>
    <div class="table-responsive report-table">
        <table class="table table-striped align-middle">
            <thead><tr><th>Student Name</th><th>Class</th><th>Academic Year</th><th>Term</th><th>Required Amount</th><th>Paid Amount</th><th>Balance</th></tr></thead>
            <tbody>
                <?php foreach ($feedingBalanceRows as $row): ?>
                    <tr class="balance-high">
                        <td><?= h($row['full_name']) ?><br><small><?= h($row['registration_no']) ?></small></td>
                        <td><?= h($row['class_level']) ?></td>
                        <td><?= h($row['academic_year']) ?></td>
                        <td><?= h($row['term']) ?></td>
                        <td><?= money((float) $row['required_amount']) ?></td>
                        <td><?= money((float) $row['paid_amount']) ?></td>
                        <td><?= money((float) $row['balance']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$feedingBalanceRows): ?><tr><td colspan="7">No feeding balances found.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<div class="row g-4 mt-1">
    <div class="col-lg-4">
        <section class="panel">
            <h2>Students per Class</h2>
            <div class="chart-wrap compact-chart"><canvas id="studentsPerClassChart"></canvas></div>
        </section>
    </div>
    <div class="col-lg-4">
        <section class="panel">
            <h2>Fee Collection Summary</h2>
            <div class="chart-wrap compact-chart"><canvas id="feeCollectionSummaryChart"></canvas></div>
        </section>
    </div>
    <div class="col-lg-4">
        <section class="panel">
            <h2>Paid vs Unpaid</h2>
            <div class="chart-wrap compact-chart"><canvas id="paidUnpaidChart"></canvas></div>
        </section>
    </div>
</div>

<div class="row g-4 mt-1">
    <div class="col-lg-4">
        <section class="panel">
            <h2>Expenses Summary</h2>
            <div class="chart-wrap compact-chart"><canvas id="expensesSummaryChart"></canvas></div>
        </section>
    </div>
    <div class="col-lg-4">
        <section class="panel">
            <h2>Transport Payments</h2>
            <div class="chart-wrap compact-chart"><canvas id="transportPaymentsChart"></canvas></div>
        </section>
    </div>
    <div class="col-lg-4">
        <section class="panel">
            <h2>Feeding Payments</h2>
            <div class="chart-wrap compact-chart"><canvas id="feedingPaymentsChart"></canvas></div>
        </section>
    </div>
</div>

<div class="row g-4 mt-1">
    <div class="col-lg-4">
        <section class="panel">
            <h2>Monthly Collections</h2>
            <div class="chart-wrap compact-chart"><canvas id="monthlyCollectionsChart"></canvas></div>
        </section>
    </div>
    <div class="col-lg-4">
        <section class="panel">
            <h2>Income vs Expenditure</h2>
            <div class="chart-wrap compact-chart"><canvas id="incomeExpenseChart"></canvas></div>
        </section>
    </div>
    <div class="col-lg-4">
        <section class="panel">
            <h2>Summary</h2>
            <div class="table-responsive">
                <table class="table table-striped align-middle">
                    <tbody>
                        <tr><th>Feeding Payments</th><td><?= money($feedingPayments) ?></td></tr>
                        <tr><th>School Expenses</th><td><?= money($schoolExpenses) ?></td></tr>
                        <tr><th>Total Income</th><td><?= money($totalIncome) ?></td></tr>
                        <tr><th>Total Expenditure</th><td><?= money($totalExpenditure) ?></td></tr>
                        <tr><th>Net Income</th><td><?= money($netIncome) ?></td></tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>

<div class="row g-4 mt-1">
    <div class="col-lg-6">
        <section class="panel">
            <h2>Term Fee Balances</h2>
            <div class="table-responsive report-table">
                <table class="table table-striped align-middle">
                    <thead><tr><th>Student</th><th>Class</th><th>Academic Year</th><th>Term</th><th>Required Amount</th><th>Paid Amount</th><th>Balance</th></tr></thead>
                    <tbody>
                        <?php foreach ($feeTermReport as $row): ?>
                            <tr class="<?= (float) $row['balance'] > 0.005 ? 'balance-high' : '' ?>">
                                <td><?= h($row['full_name']) ?><br><small><?= h($row['registration_no']) ?></small></td>
                                <td><?= h($row['class_level']) ?></td>
                                <td><?= h($row['academic_year']) ?></td>
                                <td><?= h($row['term']) ?></td>
                                <td><?= money((float) $row['required_amount']) ?></td>
                                <td><?= money((float) $row['paid_amount']) ?></td>
                                <td><?= money((float) $row['balance']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$feeTermReport): ?><tr><td colspan="7">No term balances found.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
    <div class="col-lg-6">
        <section class="panel">
            <h2>Outstanding Term Balances</h2>
            <div class="table-responsive report-table">
                <table class="table table-striped align-middle">
                    <thead><tr><th>Student</th><th>Class</th><th>Academic Year</th><th>Term</th><th>Required Amount</th><th>Paid Amount</th><th>Balance</th></tr></thead>
                    <tbody>
                        <?php foreach ($outstandingTermBalances as $row): ?>
                            <tr class="balance-high">
                                <td><?= h($row['full_name']) ?><br><small><?= h($row['registration_no']) ?></small></td>
                                <td><?= h($row['class_level']) ?></td>
                                <td><?= h($row['academic_year']) ?></td>
                                <td><?= h($row['term']) ?></td>
                                <td><?= money((float) $row['required_amount']) ?></td>
                                <td><?= money((float) $row['paid_amount']) ?></td>
                                <td><?= money((float) $row['balance']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$outstandingTermBalances): ?><tr><td colspan="7">No outstanding term balances found.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>

<div class="row g-4 mt-1">
    <div class="col-lg-6">
        <section class="panel">
            <h2>Unpaid Balance Totals by Term</h2>
            <div class="table-responsive">
                <table class="table table-striped align-middle">
                    <thead><tr><th>Academic Year</th><th>Term</th><th>Total Unpaid</th></tr></thead>
                    <tbody>
                        <?php foreach ($unpaidByTerm as $row): ?>
                            <tr><td><?= h($row['academic_year']) ?></td><td><?= h($row['term']) ?></td><td><?= money((float) $row['total_balance']) ?></td></tr>
                        <?php endforeach; ?>
                        <?php if (!$unpaidByTerm): ?><tr><td colspan="3">No unpaid term balances found.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
    <div class="col-lg-6">
        <section class="panel">
            <h2>Fee Payments by Academic Term</h2>
            <div class="table-responsive">
                <table class="table table-striped align-middle">
                    <thead><tr><th>Academic Year</th><th>Term</th><th>Total Paid</th></tr></thead>
                    <tbody>
                        <?php foreach ($paymentsByTerm as $row): ?>
                            <tr><td><?= h($row['year']) ?></td><td><?= h($row['term']) ?></td><td><?= money((float) $row['total_paid']) ?></td></tr>
                        <?php endforeach; ?>
                        <?php if (!$paymentsByTerm): ?><tr><td colspan="3">No payments found.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>

<div class="row g-4 mt-1">
    <div class="col-lg-6">
        <section class="panel">
            <h2>Daily Collections</h2>
            <div class="table-responsive report-table">
                <table class="table table-striped align-middle">
                    <thead><tr><th>Date</th><th>Total</th></tr></thead>
                    <tbody>
                        <?php foreach ($dailyCollections as $row): ?>
                            <tr><td><?= h($row['collection_date']) ?></td><td><?= money((float) $row['total_paid']) ?></td></tr>
                        <?php endforeach; ?>
                        <?php if (!$dailyCollections): ?><tr><td colspan="2">No daily collections found.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
    <div class="col-lg-6">
        <section class="panel">
            <h2>Monthly Collections</h2>
            <div class="table-responsive report-table">
                <table class="table table-striped align-middle">
                    <thead><tr><th>Month</th><th>Total</th></tr></thead>
                    <tbody>
                        <?php foreach ($monthlyCollections as $row): ?>
                            <tr><td><?= h($row['collection_month']) ?></td><td><?= money((float) $row['total_paid']) ?></td></tr>
                        <?php endforeach; ?>
                        <?php if (!$monthlyCollections): ?><tr><td colspan="2">No monthly collections found.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>

<section class="panel mt-4">
    <h2>Expense Summaries</h2>
    <div class="table-responsive">
        <table class="table table-striped align-middle">
            <thead><tr><th>Category</th><th>Total</th></tr></thead>
            <tbody>
                <tr><td>Kitchen Inventory</td><td><?= money($kitchenExpenses) ?></td></tr>
                <?php foreach ($expenseSummaries as $row): ?>
                    <tr><td><?= h($row['category']) ?></td><td><?= money((float) $row['total_amount']) ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$expenseSummaries && $kitchenExpenses <= 0): ?><tr><td colspan="2">No expenses found.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<script>
window.reportCharts = {
    studentsPerClass: {
        labels: <?= json_encode(array_column($studentsPerClass, 'class_level')) ?>,
        values: <?= json_encode(array_map('intval', array_column($studentsPerClass, 'student_count'))) ?>
    },
    paymentsByTerm: {
        labels: <?= json_encode(array_column($paymentsByTerm, 'term_label')) ?>,
        values: <?= json_encode(array_map('floatval', array_column($paymentsByTerm, 'total_paid'))) ?>
    },
    feeCollectionSummary: {
        labels: ['Required', 'Paid', 'Balance'],
        values: <?= json_encode([$totalRequiredFees, $totalPaidForBalances, $totalFeeBalances]) ?>
    },
    paidUnpaid: {
        labels: ['Paid', 'Unpaid'],
        values: <?= json_encode([$totalPaidForBalances, $totalFeeBalances]) ?>
    },
    expensesSummary: {
        labels: <?= json_encode($expenseChartLabels) ?>,
        values: <?= json_encode($expenseChartValues) ?>
    },
    transportPayments: {
        labels: <?= json_encode(array_column($transportByTerm, 'term')) ?>,
        values: <?= json_encode(array_map('floatval', array_column($transportByTerm, 'total_paid'))) ?>
    },
    feedingPayments: {
        labels: <?= json_encode(array_column($feedingByTerm, 'term')) ?>,
        values: <?= json_encode(array_map('floatval', array_column($feedingByTerm, 'total_paid'))) ?>
    },
    monthlyCollections: {
        labels: <?= json_encode(array_reverse(array_column($monthlyCollections, 'collection_month'))) ?>,
        values: <?= json_encode(array_reverse(array_map('floatval', array_column($monthlyCollections, 'total_paid')))) ?>
    },
    incomeExpense: {
        labels: ['Income', 'Expenditure'],
        values: <?= json_encode([$totalIncome, $totalExpenditure]) ?>
    }
};
</script>
<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>
