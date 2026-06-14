<?php
$pageTitle = 'Fuel Transactions';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';

// Check permission
if (!current_admin_has_permission($pdo, 'school_van_fuel.access')) {
    flash('error', 'You do not have permission to access School Van Fuel.');
    redirect('admin/dashboard.php');
}

$ctx = current_academic_context($pdo);
if (!empty($ctx['start_date']) && !empty($ctx['end_date'])) {
    $stmt = $pdo->prepare('SELECT ft.*, v.vehicle_name FROM fuel_transactions ft JOIN vehicles v ON v.id = ft.vehicle_id WHERE ft.fuel_date BETWEEN :start_date AND :end_date ORDER BY ft.fuel_date DESC, ft.id DESC');
    $stmt->execute(['start_date' => $ctx['start_date'], 'end_date' => $ctx['end_date']]);
    $transactions = $stmt->fetchAll();
} else {
    $stmt = $pdo->prepare('SELECT ft.*, v.vehicle_name FROM fuel_transactions ft JOIN vehicles v ON v.id = ft.vehicle_id WHERE YEAR(ft.fuel_date) = :year ORDER BY ft.fuel_date DESC, ft.id DESC');
    $stmt->execute(['year' => $ctx['academic_year']]);
    $transactions = $stmt->fetchAll();
}

require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title">
    <div>
        <p class="eyebrow">Transport</p>
        <h1>Fuel Transactions</h1>
        <p class="mb-0 text-muted">Record fueling and view history.</p>
    </div>
    <div class="action-row">
        <a class="btn btn-primary" href="<?= url('admin/fuel_form.php') ?>">Add Fuel</a>
        <a class="btn btn-outline-primary" href="<?= url('admin/vehicles.php') ?>">Vehicles</a>
    </div>
</div>

<section class="panel">
    <h2>Fuel History</h2>
    <div class="table-responsive">
        <table class="table table-striped align-middle">
            <thead><tr><th>Date</th><th>Vehicle</th><th>Fuel Type</th><th>Amount</th><th>Station</th><th>Receipt</th></tr></thead>
            <tbody>
                <?php foreach ($transactions as $t): ?>
                    <tr>
                        <td><?= h($t['fuel_date']) ?></td>
                        <td><?= h($t['vehicle_name']) ?></td>
                        <td><?= h($t['fuel_type']) ?></td>
                        <td><?= money((float)$t['total_amount']) ?></td>
                        <td><?= h($t['fuel_station']) ?></td>
                        <td><?= h($t['receipt_no']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>
