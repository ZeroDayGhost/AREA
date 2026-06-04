<?php
$pageTitle = 'Dashboard';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';

$currentContext = current_academic_context($pdo);
sync_current_term_fee_balances($pdo);

function dashboard_balance_class(float $balance, float $requiredAmount, float $paidAmount): string
{
    if ($balance <= 0.005) {
        return '';
    }

    if ($paidAmount <= 0.005 || ($requiredAmount > 0 && $balance >= ($requiredAmount * 0.5))) {
        return 'balance-high';
    }

    return 'balance-partial';
}

function dashboard_percent(float $value, float $total): int
{
    if ($total <= 0.005) {
        return 0;
    }

    return (int) round(($value / $total) * 100);
}

function dashboard_date_label(?string $date): string
{
    if (!$date) {
        return 'Recently';
    }

    $timestamp = strtotime($date);
    return $timestamp ? date('M d, Y', $timestamp) : 'Recently';
}

$summaryStatement = $pdo->prepare(
    "SELECT
        (SELECT COUNT(*) FROM students) AS total_students,
        (SELECT COUNT(*) FROM students WHERE gender = 'Male') AS total_boys,
        (SELECT COUNT(*) FROM students WHERE gender = 'Female') AS total_girls,
        (SELECT COALESCE(SUM(amount_paid), 0)
         FROM fees
         WHERE year = :fee_year
           AND term = :fee_term) AS total_fee_collected,
        (SELECT COALESCE(SUM(balance), 0)
         FROM fee_balances
         WHERE academic_year = :balance_year
           AND term = :balance_term) AS total_fee_balance,
        (SELECT COUNT(DISTINCT student_id)
         FROM feeding_subscriptions
         WHERE academic_year = :academic_year
           AND term = :term
           AND status = 'Active') AS total_feeding_students,
        (SELECT COUNT(DISTINCT transport_student_id)
         FROM transport_accounts
         WHERE academic_year = :academic_year_transport
           AND term = :term_transport
           AND status = 'Active') AS total_transport_students,
        ((SELECT COALESCE(SUM(total_amount), 0) FROM school_expenses)
         + (SELECT COALESCE(SUM(total_amount), 0) FROM kitchen_inventory)) AS total_expenses"
);
$summaryStatement->execute([
    'academic_year' => $currentContext['academic_year'],
    'term' => $currentContext['term'],
    'fee_year' => $currentContext['academic_year'],
    'fee_term' => $currentContext['term'],
    'balance_year' => $currentContext['academic_year'],
    'balance_term' => $currentContext['term'],
    'academic_year_transport' => $currentContext['academic_year'],
    'term_transport' => $currentContext['term'],
]);
$summary = $summaryStatement->fetch();

$studentTotal = (int) $summary['total_students'];
$totalBoys = (int) $summary['total_boys'];
$totalGirls = (int) $summary['total_girls'];
$totalFeeCollected = (float) $summary['total_fee_collected'];
$totalBalance = (float) $summary['total_fee_balance'];
$totalFeedingStudents = (int) $summary['total_feeding_students'];
$totalTransportStudents = (int) $summary['total_transport_students'];
$totalExpenses = (float) $summary['total_expenses'];

$feeTermReport = fetch_fee_term_report($pdo, [
    'year' => $currentContext['academic_year'],
    'term' => $currentContext['term'],
]);
$totalRequiredFees = array_sum(array_map(fn($row) => (float) $row['required_amount'], $feeTermReport));
$totalPaidForBalances = array_sum(array_map(fn($row) => (float) $row['paid_amount'], $feeTermReport));
$balanceRows = array_values(array_filter($feeTermReport, fn($row) => (float) $row['balance'] > 0.005));
usort($balanceRows, fn($left, $right) => (float) $right['balance'] <=> (float) $left['balance']);
$balances = array_slice($balanceRows, 0, 12);

$monthlyRowsStatement = $pdo->prepare(
    "SELECT DATE_FORMAT(payment_date, '%Y-%m') AS payment_month, SUM(amount_paid) AS monthly_total
     FROM fees
     WHERE year = :academic_year
       AND term = :term
     GROUP BY DATE_FORMAT(payment_date, '%Y-%m')
     ORDER BY payment_month ASC"
);
$monthlyRowsStatement->execute([
    'academic_year' => $currentContext['academic_year'],
    'term' => $currentContext['term'],
]);
$monthlyRows = $monthlyRowsStatement->fetchAll();
$chartLabels = array_column($monthlyRows, 'payment_month');
$chartTotals = array_map('floatval', array_column($monthlyRows, 'monthly_total'));

$kitchenExpenses = (float) $pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM kitchen_inventory")->fetchColumn();
$schoolExpenses = (float) $pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM school_expenses")->fetchColumn();
$feedingPayments = (float) $pdo->query("SELECT COALESCE(SUM(amount_paid), 0) FROM feeding_payments")->fetchColumn();
$transportPayments = (float) $pdo->query("SELECT COALESCE(SUM(amount_paid), 0) FROM transport_payments")->fetchColumn();
$totalIncome = $totalFeeCollected + $feedingPayments + $transportPayments;
$totalExpenditure = $kitchenExpenses + $schoolExpenses;
$netPosition = $totalIncome - $totalExpenditure;

$recentFeePayments = $pdo->query(
    "SELECT fees.id, fees.receipt_no, fees.amount_paid, fees.payment_date, fees.term, fees.year, students.full_name
     FROM fees
     JOIN students ON students.id = fees.student_id
     ORDER BY fees.payment_date DESC, fees.id DESC
     LIMIT 5"
)->fetchAll();
$recentExpenses = $pdo->query(
    "SELECT *
     FROM (
        SELECT id, 'School Expense' AS activity_type, item_name AS title, category AS detail, total_amount AS amount, expense_date AS activity_date
        FROM school_expenses
        UNION ALL
        SELECT id, 'Kitchen Inventory' AS activity_type, item_name AS title, COALESCE(supplier, '') AS detail, total_amount AS amount, item_date AS activity_date
        FROM kitchen_inventory
     ) AS expense_activity
     ORDER BY activity_date DESC, id DESC
     LIMIT 5"
)->fetchAll();
$recentFeedingPayments = $pdo->query(
    "SELECT feeding_payments.amount_paid, feeding_payments.payment_date, feeding_subscriptions.term, feeding_subscriptions.academic_year, students.full_name
     FROM feeding_payments
     JOIN feeding_subscriptions ON feeding_subscriptions.id = feeding_payments.feeding_subscription_id
     JOIN students ON students.id = feeding_subscriptions.student_id
     ORDER BY feeding_payments.payment_date DESC, feeding_payments.id DESC
     LIMIT 5"
)->fetchAll();
$recentTransportPayments = $pdo->query(
    "SELECT transport_payments.amount_paid, transport_payments.payment_date, transport_accounts.term, transport_accounts.academic_year, transport_students.student_name
     FROM transport_payments
     JOIN transport_accounts ON transport_accounts.id = transport_payments.transport_account_id
     JOIN transport_students ON transport_students.id = transport_accounts.transport_student_id
     ORDER BY transport_payments.payment_date DESC, transport_payments.id DESC
     LIMIT 5"
)->fetchAll();

$recentActivities = [];
foreach ($recentFeePayments as $payment) {
    $recentActivities[] = [
        'title' => 'Fee payment from ' . $payment['full_name'],
        'detail' => $payment['receipt_no'] . ' - ' . $payment['term'] . ' ' . $payment['year'],
        'date' => $payment['payment_date'],
        'sort' => strtotime($payment['payment_date']) ?: 0,
        'amount' => money((float) $payment['amount_paid']),
        'icon' => 'fa-wallet',
        'tone' => 'activity-success',
        'url' => url('admin/receipt.php?id=' . $payment['id']),
    ];
}
foreach ($recentExpenses as $expense) {
    $recentActivities[] = [
        'title' => $expense['title'],
        'detail' => $expense['activity_type'] . ($expense['detail'] ? ' - ' . $expense['detail'] : ''),
        'date' => $expense['activity_date'],
        'sort' => strtotime($expense['activity_date']) ?: 0,
        'amount' => money((float) $expense['amount']),
        'icon' => 'fa-receipt',
        'tone' => 'activity-warning',
        'url' => '',
    ];
}
foreach ($recentFeedingPayments as $payment) {
    $recentActivities[] = [
        'title' => 'Feeding payment from ' . $payment['full_name'],
        'detail' => $payment['term'] . ' ' . $payment['academic_year'],
        'date' => $payment['payment_date'],
        'sort' => strtotime($payment['payment_date']) ?: 0,
        'amount' => money((float) $payment['amount_paid']),
        'icon' => 'fa-utensils',
        'tone' => 'activity-info',
        'url' => '',
    ];
}
foreach ($recentTransportPayments as $payment) {
    $recentActivities[] = [
        'title' => 'Transport payment from ' . $payment['student_name'],
        'detail' => $payment['term'] . ' ' . $payment['academic_year'],
        'date' => $payment['payment_date'],
        'sort' => strtotime($payment['payment_date']) ?: 0,
        'amount' => money((float) $payment['amount_paid']),
        'icon' => 'fa-bus',
        'tone' => 'activity-purple',
        'url' => '',
    ];
}
usort($recentActivities, fn($left, $right) => $right['sort'] <=> $left['sort']);
$recentActivities = array_slice($recentActivities, 0, 8);

$collectionRate = dashboard_percent($totalPaidForBalances, $totalRequiredFees);
$balanceRate = dashboard_percent($totalBalance, $totalRequiredFees);
$boysRate = dashboard_percent($totalBoys, $studentTotal);
$girlsRate = dashboard_percent($totalGirls, $studentTotal);
$feedingRate = dashboard_percent($totalFeedingStudents, $studentTotal);
$transportRate = dashboard_percent($totalTransportStudents, $studentTotal);
$displayDate = dashboard_date_label($currentContext['today'] ?? date('Y-m-d'));

$dashboardStats = [
    [
        'label' => 'Total Students',
        'value' => (string) $studentTotal,
        'indicator' => ($studentTotal > 0 ? '100%' : '0%') . ' live roster',
        'icon' => 'fa-users',
        'theme' => 'stat-students',
        'href' => url('admin/students.php'),
        'money' => false,
    ],
    [
        'label' => 'Total Boys',
        'value' => (string) $totalBoys,
        'indicator' => $boysRate . '% of students',
        'icon' => 'fa-person',
        'theme' => 'stat-boys',
        'href' => url('admin/students.php?search=Male'),
        'money' => false,
    ],
    [
        'label' => 'Total Girls',
        'value' => (string) $totalGirls,
        'indicator' => $girlsRate . '% of students',
        'icon' => 'fa-person-dress',
        'theme' => 'stat-girls',
        'href' => url('admin/students.php?search=Female'),
        'money' => false,
    ],
    [
        'label' => 'Fees Collected',
        'value' => money($totalFeeCollected),
        'indicator' => $collectionRate . '% collected',
        'icon' => 'fa-sack-dollar',
        'theme' => 'stat-fees',
        'href' => url('admin/fees.php'),
        'money' => true,
    ],
    [
        'label' => 'Fee Balance',
        'value' => money($totalBalance),
        'indicator' => $balanceRate . '% remaining',
        'icon' => 'fa-scale-balanced',
        'theme' => 'stat-balance',
        'href' => url('admin/reports.php'),
        'money' => true,
    ],
    [
        'label' => 'Transport',
        'value' => (string) $totalTransportStudents,
        'indicator' => $transportRate . '% enrolled',
        'icon' => 'fa-bus',
        'theme' => 'stat-transport',
        'href' => url('admin/transport.php'),
        'money' => false,
    ],
    [
        'label' => 'Feeding',
        'value' => (string) $totalFeedingStudents,
        'indicator' => $feedingRate . '% active',
        'icon' => 'fa-bowl-food',
        'theme' => 'stat-feeding',
        'href' => url('admin/feeding.php'),
        'money' => false,
    ],
];

$quickActions = [
    ['label' => 'Add Student', 'icon' => 'fa-user-plus', 'href' => url('admin/student_form.php'), 'theme' => 'action-green'],
    ['label' => 'Record Payment', 'icon' => 'fa-wallet', 'href' => url('admin/fees.php'), 'theme' => 'action-blue'],
    ['label' => 'Add Expense', 'icon' => 'fa-receipt', 'href' => url('admin/school_expenses.php'), 'theme' => 'action-pink'],
    ['label' => 'Transport', 'icon' => 'fa-bus', 'href' => url('admin/transport.php'), 'theme' => 'action-cyan'],
    ['label' => 'Reports', 'icon' => 'fa-chart-pie', 'href' => url('admin/reports.php'), 'theme' => 'action-purple'],
    ['label' => 'Feeding', 'icon' => 'fa-utensils', 'href' => url('admin/feeding.php'), 'theme' => 'action-orange'],
];

require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title dashboard-title">
    <div class="welcome-copy">
        <p class="eyebrow">School Command Center</p>
        <h1>Dashboard</h1>
        <p class="mb-0">Welcome back, <?= h($_SESSION['admin_name'] ?? 'Admin') ?>. Here is today's command-center snapshot.</p>
    </div>
    <div class="title-actions">
        <span class="date-pill"><i class="fa-regular fa-calendar"></i><?= h($displayDate) ?> - <?= h($currentContext['term']) ?></span>
        <a class="btn btn-primary" href="<?= url('admin/fees.php') ?>"><i class="fa-solid fa-plus"></i>Record Payment</a>
    </div>
</div>

<div class="dashboard-metrics">
    <?php foreach ($dashboardStats as $card): ?>
        <a class="metric-card dashboard-stat-card <?= h($card['theme']) ?>" href="<?= h($card['href']) ?>">
            <div class="stat-card-top">
                <span class="stat-icon"><i class="fa-solid <?= h($card['icon']) ?>"></i></span>
                <span class="stat-trend"><i class="fa-solid fa-arrow-trend-up"></i><?= h($card['indicator']) ?></span>
            </div>
            <span class="stat-content">
                <strong class="stat-value <?= $card['money'] ? 'stat-money' : '' ?>"><?= h($card['value']) ?></strong>
                <span class="stat-label"><?= h($card['label']) ?></span>
            </span>
        </a>
    <?php endforeach; ?>
</div>

<div class="dashboard-grid">
    <section class="panel">
        <div class="panel-heading">
            <div>
                <h2><i class="fa-solid fa-chart-line text-primary me-2"></i>Fee Overview</h2>
                <p class="panel-subtitle">Current term collections, balances, expenses, and net position.</p>
            </div>
            <a class="btn btn-sm btn-outline-primary" href="<?= url('admin/reports.php') ?>">Reports</a>
        </div>
        <div class="chart-summary-row">
            <div class="chart-summary"><span>Collected</span><strong><?= money($totalFeeCollected) ?></strong></div>
            <div class="chart-summary"><span>Balance</span><strong><?= money($totalBalance) ?></strong></div>
            <div class="chart-summary"><span>Expenses</span><strong><?= money($totalExpenses) ?></strong></div>
            <div class="chart-summary"><span>Net Position</span><strong><?= money($netPosition) ?></strong></div>
        </div>
        <div class="chart-wrap"><canvas id="feeOverviewChart" aria-label="Fee overview chart"></canvas></div>
    </section>

    <section class="panel">
        <div class="panel-heading">
            <div>
                <h2><i class="fa-solid fa-bolt text-primary me-2"></i>Quick Actions</h2>
                <p class="panel-subtitle">Jump into the workflows used most often.</p>
            </div>
        </div>
        <div class="quick-actions-grid">
            <?php foreach ($quickActions as $action): ?>
                <a class="quick-action-card <?= h($action['theme']) ?>" href="<?= h($action['href']) ?>">
                    <span class="quick-action-icon"><i class="fa-solid <?= h($action['icon']) ?>"></i></span>
                    <span><?= h($action['label']) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
</div>

<div class="row g-4 mt-1">
    <div class="col-xl-4 col-lg-6">
        <section class="panel">
            <div class="panel-heading">
                <div>
                    <h2><i class="fa-solid fa-users text-primary me-2"></i>Student Statistics</h2>
                    <p class="panel-subtitle">Gender distribution across the student roster.</p>
                </div>
            </div>
            <div class="chart-wrap compact-chart"><canvas id="studentStatsChart"></canvas></div>
        </section>
    </div>

    <div class="col-xl-4 col-lg-6">
        <section class="panel">
            <div class="panel-heading">
                <div>
                    <h2><i class="fa-solid fa-arrow-trend-up text-primary me-2"></i>Collection Trend</h2>
                    <p class="panel-subtitle">Monthly payment activity for the active term.</p>
                </div>
            </div>
            <div class="chart-wrap compact-chart"><canvas id="collectionTrendChart"></canvas></div>
        </section>
    </div>

    <div class="col-xl-4">
        <section class="announcement-card">
            <div class="announcement-visual"><i class="fa-solid fa-bullhorn"></i></div>
            <h2>Term Operations Pulse</h2>
            <p>Keep fee follow-ups, feeding subscriptions, transport accounts, and expense approvals aligned with the active academic period.</p>
            <div class="announcement-meta">
                <span class="soft-chip"><?= h($currentContext['academic_year']) ?></span>
                <span class="soft-chip"><?= h($currentContext['term']) ?></span>
                <span class="soft-chip"><?= h($collectionRate) ?>% collected</span>
            </div>
        </section>
    </div>
</div>

<section class="panel mt-4">
    <div class="panel-heading">
        <div>
            <h2><i class="fa-solid fa-scale-balanced text-primary me-2"></i>Outstanding Fee Balances</h2>
            <p class="panel-subtitle">Highest current-term balances that may need follow-up.</p>
        </div>
        <a class="btn btn-sm btn-outline-primary" href="<?= url('admin/reports.php') ?>">View Reports</a>
    </div>
    <div class="table-responsive">
        <table class="table table-striped align-middle">
            <thead>
                <tr>
                    <th>Reg No</th>
                    <th>Student Name</th>
                    <th>Class Level</th>
                    <th>Academic Year</th>
                    <th>Term</th>
                    <th>Required Amount</th>
                    <th>Paid Amount</th>
                    <th>Balance</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($balances as $student): ?>
                    <tr class="<?= h(dashboard_balance_class((float) $student['balance'], (float) $student['required_amount'], (float) $student['paid_amount'])) ?>">
                        <td><?= h($student['registration_no']) ?></td>
                        <td><?= h($student['full_name']) ?></td>
                        <td><?= h($student['class_level']) ?></td>
                        <td><?= h($student['academic_year']) ?></td>
                        <td><?= h($student['term']) ?></td>
                        <td><?= money((float) $student['required_amount']) ?></td>
                        <td><?= money((float) $student['paid_amount']) ?></td>
                        <td><strong><?= money((float) $student['balance']) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$balances): ?>
                    <tr><td colspan="8">No outstanding fee balances found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<div class="row g-4 mt-1">
    <div class="col-xl-8">
        <section class="panel activity-panel">
            <div class="panel-heading">
                <div>
                    <h2><i class="fa-solid fa-wave-square text-primary me-2"></i>Recent Activity</h2>
                    <p class="panel-subtitle">Latest fee, feeding, transport, and expense events.</p>
                </div>
                <a class="btn btn-sm btn-outline-primary" href="<?= url('admin/reports.php') ?>">View All</a>
            </div>

            <?php if ($recentActivities): ?>
                <div class="activity-feed">
                    <?php foreach ($recentActivities as $activity): ?>
                        <div class="activity-item">
                            <span class="activity-icon <?= h($activity['tone']) ?>"><i class="fa-solid <?= h($activity['icon']) ?>"></i></span>
                            <div>
                                <strong><?= h($activity['title']) ?></strong>
                                <span><?= h($activity['detail']) ?> - <?= h(dashboard_date_label($activity['date'])) ?></span>
                            </div>
                            <?php if ($activity['url']): ?>
                                <a class="activity-amount" href="<?= h($activity['url']) ?>"><?= h($activity['amount']) ?></a>
                            <?php else: ?>
                                <strong class="activity-amount"><?= h($activity['amount']) ?></strong>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <div>
                        <span class="empty-state-illustration"><i class="fa-regular fa-clipboard"></i></span>
                        <strong>No recent activity</strong>
                        <span>Activities will appear here once records are added.</span>
                    </div>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <div class="col-xl-4">
        <section class="panel">
            <div class="panel-heading">
                <h2><i class="fa-solid fa-chart-simple text-primary me-2"></i>Operations Snapshot</h2>
            </div>
            <div class="row g-3">
                <div class="col-sm-6 col-xl-12">
                    <div class="mini-stat"><span>Required Fees</span><strong><?= money($totalRequiredFees) ?></strong></div>
                </div>
                <div class="col-sm-6 col-xl-12">
                    <div class="mini-stat"><span>Paid Against Balances</span><strong><?= money($totalPaidForBalances) ?></strong></div>
                </div>
                <div class="col-sm-6 col-xl-12">
                    <div class="mini-stat"><span>Feeding Payments</span><strong><?= money($feedingPayments) ?></strong></div>
                </div>
                <div class="col-sm-6 col-xl-12">
                    <div class="mini-stat"><span>Transport Payments</span><strong><?= money($transportPayments) ?></strong></div>
                </div>
            </div>
        </section>
    </div>
</div>

<script>
window.dashboardCharts = {
    feeOverview: {
        labels: ['Collected', 'Balance', 'Expenses', 'Net'],
        values: <?= json_encode([$totalFeeCollected, $totalBalance, $totalExpenses, $netPosition]) ?>
    },
    studentStats: {
        labels: ['Boys', 'Girls'],
        values: <?= json_encode([$totalBoys, $totalGirls]) ?>
    },
    collectionTrend: {
        labels: <?= json_encode($chartLabels) ?>,
        values: <?= json_encode($chartTotals) ?>
    }
};
</script>
<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>
