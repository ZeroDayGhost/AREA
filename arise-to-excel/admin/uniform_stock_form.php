<?php
$pageTitle = 'Adjust Uniform Stock';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) redirect('admin/uniform_stock.php');

$stmt = $pdo->prepare('SELECT u.*, (u.opening_stock + COALESCE(SUM(usm.quantity),0)) AS available_stock FROM uniforms u LEFT JOIN uniform_stock_movements usm ON usm.uniform_id = u.id WHERE u.id = :id GROUP BY u.id LIMIT 1');
$stmt->execute(['id'=>$id]);
$uniform = $stmt->fetch();
if (!$uniform) { flash('error','Uniform not found'); redirect('admin/uniform_stock.php'); }

$error = flash('error'); $success = flash('success');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $movementType = trim($_POST['movement_type'] ?? 'Add');
    $quantity = (int)($_POST['quantity'] ?? 0);
    $note = trim($_POST['note'] ?? '');

    if ($quantity === 0) { flash('error','Quantity cannot be zero'); redirect('admin/uniform_stock_form.php?id=' . $id); }

    // For removals, store negative quantity
    $qtyToStore = in_array($movementType,['Damaged','Adjustment','Sale']) ? -abs($quantity) : abs($quantity);

    $ins = $pdo->prepare('INSERT INTO uniform_stock_movements (uniform_id, movement_type, quantity, reference_id, note) VALUES (:uniform_id, :movement_type, :quantity, NULL, :note)');
    $ins->execute(['uniform_id'=>$id,'movement_type'=>$movementType,'quantity'=>$qtyToStore,'note'=>$note]);

    flash('success','Stock updated');
    redirect('admin/uniform_stock_form.php?id=' . $id);
}

$movements = $pdo->prepare('SELECT * FROM uniform_stock_movements WHERE uniform_id = :id ORDER BY created_at DESC');
$movements->execute(['id'=>$id]);
$movements = $movements->fetchAll();

require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title">
    <div>
        <p class="eyebrow">Inventory</p>
        <h1>Adjust Stock — <?= h($uniform['uniform_name']) ?></h1>
    </div>
    <div class="action-row">
        <a class="btn btn-outline-primary" href="<?= url('admin/uniform_stock.php') ?>">Back</a>
    </div>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>

<section class="panel">
    <div class="row g-3">
        <div class="col-md-4">
            <strong>Opening Stock</strong><div><?= (int)$uniform['opening_stock'] ?></div>
        </div>
        <div class="col-md-4">
            <strong>Available Stock</strong><div><?= (int)$uniform['available_stock'] ?></div>
        </div>
        <div class="col-md-4">
            <strong>Reorder Level</strong><div><?= (int)$uniform['reorder_level'] ?></div>
        </div>
    </div>

    <hr>
    <form method="post" class="row g-3">
        <div class="col-md-4">
            <label class="form-label">Movement Type</label>
            <select class="form-select" name="movement_type">
                <option>Add</option>
                <option>Returned</option>
                <option>Adjustment</option>
                <option>Damaged</option>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label">Quantity</label>
            <input class="form-control" type="number" name="quantity" required>
        </div>
        <div class="col-md-4">
            <label class="form-label">Note</label>
            <input class="form-control" name="note">
        </div>
        <div class="col-12">
            <button class="btn btn-primary" type="submit">Save Movement</button>
        </div>
    </form>

    <hr>
    <h3>Recent Movements</h3>
    <div class="table-responsive">
        <table class="table table-sm">
            <thead><tr><th>Date</th><th>Type</th><th>Qty</th><th>Note</th></tr></thead>
            <tbody>
                <?php foreach ($movements as $m): ?>
                    <tr>
                        <td><?= h($m['created_at']) ?></td>
                        <td><?= h($m['movement_type']) ?></td>
                        <td><?= (int)$m['quantity'] ?></td>
                        <td><?= h($m['note']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>
