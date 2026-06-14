<?php
$pageTitle = 'Kitchen Daily Usage';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';

// Module access
if (!current_admin_has_permission($pdo, 'kitchen.access')) {
    flash('error', 'You do not have permission to access Kitchen.');
    redirect('admin/dashboard.php');
}

$canRecordUsage = current_admin_has_permission($pdo, 'kitchen.record_usage');

ensure_kitchen_tables($pdo);
$currentContext = current_academic_context($pdo);

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'record_usage') {
        $itemName = trim($_POST['item_name'] ?? '');
        $usageDate = trim($_POST['usage_date'] ?? date('Y-m-d'));
        $qtyUsed = (float) ($_POST['quantity_used'] ?? 0);
        $unitPrice = (float) ($_POST['unit_price'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');

        if ($itemName === '' || $qtyUsed <= 0) {
            $errors[] = 'Item name and quantity are required.';
        } elseif (!valid_date_value($usageDate)) {
            $errors[] = 'Date must be valid.';
        } else {
            if (!$canRecordUsage) {
                flash('error', 'You do not have permission to record kitchen usage.');
                redirect('admin/kitchen_usage.php');
            }
            $totalCost = $qtyUsed * $unitPrice;
            $kitem = kitchen_get_or_create_item($pdo, $itemName);
            $recordedBy = $_SESSION['admin_id'] ?? null;
            
            // Record the usage movement with unit price and total cost for the correct term
            kitchen_record_movement($pdo, (int)$kitem['id'], 'out', $qtyUsed, $unitPrice, $totalCost, null, 'Usage: ' . $notes, $recordedBy, $usageDate);
            
            // Also create an expense record for cost tracking
            $expStmt = $pdo->prepare("INSERT INTO school_expenses (item_name, category, amount, quantity, total_amount, expense_date, description) VALUES (:item_name, :category, :amount, :quantity, :total_amount, :expense_date, :description)");
            $expStmt->execute([
                'item_name' => $itemName,
                'category' => 'Kitchen',
                'amount' => $unitPrice,
                'quantity' => $qtyUsed,
                'total_amount' => $totalCost,
                'expense_date' => $usageDate,
                'description' => 'Daily usage: ' . ($notes ?: 'Regular consumption'),
            ]);
            
            flash('success', 'Usage recorded: ' . $itemName . ' (' . $qtyUsed . 'kg)');
            redirect('admin/kitchen_usage.php');
        }
    }
}

// Get all items for dropdown
$allItemsStmt = $pdo->query("SELECT DISTINCT item_name FROM kitchen_items ORDER BY item_name ASC");
$allItems = $allItemsStmt->fetchAll(PDO::FETCH_COLUMN);

// Usage history with remaining stock calculation
$historySql = "SELECT ksm.id, ksm.created_at, ki.item_name, ksm.quantity, ksm.unit_price, ksm.total_cost, ksm.note, ksm.recorded_by, a.name AS recorded_by_name,
            (SELECT SUM(CASE WHEN movement_type IN ('in','purchase') THEN quantity ELSE -quantity END) 
             FROM kitchen_stock_movements 
             WHERE kitchen_item_id = ksm.kitchen_item_id";
$historyParams = [];
$termAware = db_has_column($pdo, 'kitchen_stock_movements', 'academic_year') && db_has_column($pdo, 'kitchen_stock_movements', 'term');
if ($termAware) {
    $historySql .= " AND academic_year = :academic_year AND term = :term AND transaction_date <= ksm.transaction_date";
    $historyParams['academic_year'] = $currentContext['academic_year'];
    $historyParams['term'] = $currentContext['term'];
} else {
    $historySql .= " AND created_at <= ksm.created_at";
}
$historySql .= ") AS remaining_at_time
     FROM kitchen_stock_movements ksm
     JOIN kitchen_items ki ON ki.id = ksm.kitchen_item_id
     LEFT JOIN admin a ON a.id = ksm.recorded_by
     WHERE ksm.movement_type = 'out'";
if ($termAware) {
    $historySql .= " AND ksm.academic_year = :academic_year AND ksm.term = :term";
}
$historySql .= " ORDER BY " . ($termAware ? 'ksm.transaction_date' : 'ksm.created_at') . " DESC LIMIT 100";
$historyStmt = $pdo->prepare($historySql);
$historyStmt->execute($historyParams);
$history = $historyStmt->fetchAll();

// Daily usage summary
$todaySql = "SELECT ki.item_name, SUM(ksm.quantity) AS qty_used, SUM(ksm.total_cost) AS cost_used
     FROM kitchen_stock_movements ksm
     JOIN kitchen_items ki ON ki.id = ksm.kitchen_item_id
     WHERE ksm.movement_type = 'out'";
$todayParams = ['today' => date('Y-m-d')];
if ($termAware) {
    $todaySql .= " AND ksm.transaction_date = :today AND ksm.academic_year = :academic_year AND ksm.term = :term";
    $todayParams['academic_year'] = $currentContext['academic_year'];
    $todayParams['term'] = $currentContext['term'];
} else {
    $todaySql .= " AND DATE(ksm.created_at) = :today";
}
$todaySql .= " GROUP BY ksm.kitchen_item_id ORDER BY qty_used DESC";
$todayStmt = $pdo->prepare($todaySql);
$todayStmt->execute($todayParams);
$todayUsage = $todayStmt->fetchAll();

$todayQtyTotal = array_sum(array_column($todayUsage, 'qty_used'));
$todayCostTotal = array_sum(array_column($todayUsage, 'cost_used'));

require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title">
    <div>
        <p class="eyebrow">Kitchen</p>
        <h1>Daily Kitchen Usage</h1>
        <p class="mb-0 text-muted">Record daily consumption and track food costs.</p>
    </div>
    <div class="action-row">
        <a class="btn btn-outline-primary" href="<?= url('admin/kitchen_inventory.php') ?>">Inventory</a>
        <a class="btn btn-outline-secondary" href="<?= url('admin/kitchen_consumption_report.php') ?>">Consumption Report</a>
        <a class="btn btn-outline-secondary" href="<?= url('admin/kitchen_reports.php') ?>">Kitchen Reports</a>
    </div>
</div>

<!-- Flash messages are shown in header -->
<?php if ($errors): ?>
    <div class="alert alert-danger"><?php foreach ($errors as $error): ?><div><?= h($error) ?></div><?php endforeach; ?></div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-4">
        <section class="panel">
            <div class="panel-heading"><h2>Record Usage</h2></div>
            <form method="post" class="row g-3">
                <input type="hidden" name="action" value="record_usage">
                
                <div class="col-12">
                    <label class="form-label" for="item_name">Item Name</label>
                    <input class="form-control" id="item_name" name="item_name" placeholder="Rice, Bread, etc." list="item_list" required>
                    <datalist id="item_list">
                        <?php foreach ($allItems as $item): ?>
                            <option value="<?= h($item) ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="usage_date">Date</label>
                    <input class="form-control" type="date" id="usage_date" name="usage_date" value="<?= h(date('Y-m-d')) ?>" required>
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="quantity_used">Quantity Used</label>
                    <input class="form-control" type="number" step="0.01" id="quantity_used" name="quantity_used" placeholder="kg" min="0" required>
                </div>

                <div class="col-12">
                    <label class="form-label" for="unit_price">Unit Price (per kg)</label>
                    <input class="form-control" type="number" step="0.01" id="unit_price" name="unit_price" placeholder="KSh" min="0" required>
                </div>

                <div class="col-12">
                    <label class="form-label" for="notes">Notes</label>
                    <input class="form-control" id="notes" name="notes" placeholder="e.g., Breakfast, Lunch">
                </div>

                <div class="col-12">
                    <?php if ($canRecordUsage): ?>
                        <button class="btn btn-primary w-100" type="submit">Record Usage</button>
                    <?php else: ?>
                        <button class="btn btn-primary w-100" type="button" disabled>Record Usage</button>
                    <?php endif; ?>
                </div>
            </form>
        </section>

        <section class="panel">
            <div class="panel-heading"><h2>Today's Usage</h2></div>
            <table class="table table-sm table-borderless">
                <tbody>
                    <tr>
                        <td><strong>Items Used:</strong></td>
                        <td class="text-end"><?= count($todayUsage) ?></td>
                    </tr>
                    <tr>
                        <td><strong>Total Quantity:</strong></td>
                        <td class="text-end"><?= h((string)$todayQtyTotal) ?> kg</td>
                    </tr>
                    <tr>
                        <td><strong>Total Cost:</strong></td>
                        <td class="text-end"><strong><?= money($todayCostTotal) ?></strong></td>
                    </tr>
                </tbody>
            </table>

            <?php if ($todayUsage): ?>
                <div style="font-size:0.85rem;border-top:1px solid #ddd;padding-top:12px;">
                    <?php foreach ($todayUsage as $u): ?>
                        <div style="margin-bottom:8px;display:flex;justify-content:space-between;">
                            <span><?= h($u['item_name']) ?></span>
                            <span><strong><?= money((float)$u['cost_used']) ?></strong></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <div class="col-lg-8">
        <section class="panel">
            <div class="panel-heading"><h2>Usage History (Last 100 entries)</h2></div>
            <div class="table-responsive">
                <table class="table table-striped table-sm align-middle">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Item</th>
                            <th>Quantity</th>
                            <th>Unit Price</th>
                            <th>Cost</th>
                            <th>Recorded By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $h): ?>
                            <tr>
                                <td><?= h(date('Y-m-d', strtotime($h['created_at']))) ?></td>
                                <td><?= h($h['item_name']) ?></td>
                                <td><?= h((string)$h['quantity']) ?></td>
                                <td><?= money((float)$h['unit_price']) ?></td>
                                <td><?= money((float)$h['total_cost']) ?></td>
                                <td><small><?= h($h['recorded_by_name'] ?? 'System') ?></small></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$history): ?><tr><td colspan="6">No usage history yet.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>
