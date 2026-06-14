<?php
$pageTitle = 'Uniform Catalog';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';

// Check permission
if (!current_admin_has_permission($pdo, 'school_uniform.access')) {
    flash('error', 'You do not have permission to access School Uniform.');
    redirect('admin/dashboard.php');
}

// Quick permission flags
$canViewUniform = current_admin_has_permission($pdo, 'school_uniform.view');
$canSellUniform = current_admin_has_permission($pdo, 'school_uniform.sell');
$canManageUniform = current_admin_has_permission($pdo, 'school_uniform.inventory');

// Fetch uniforms with available stock calculation
$statement = $pdo->query(
    "SELECT u.*,
            (u.opening_stock + COALESCE(SUM(usm.quantity),0)) AS available_stock
     FROM uniforms u
     LEFT JOIN uniform_stock_movements usm ON usm.uniform_id = u.id
     GROUP BY u.id
     ORDER BY u.category ASC, u.uniform_name ASC"
);
$uniforms = $statement->fetchAll();

require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title">
    <div>
        <p class="eyebrow">Inventory</p>
        <h1>Uniform Catalog</h1>
        <p class="mb-0 text-muted">Manage uniform items, stock and pricing.</p>
    </div>
    <div class="action-row">
        <?php if ($canManageUniform): ?>
            <a class="btn btn-primary" href="<?= url('admin/uniform_form.php') ?>"><i class="fa-solid fa-plus"></i>New Uniform</a>
        <?php else: ?>
            <button class="btn btn-primary" disabled><i class="fa-solid fa-plus"></i>New Uniform</button>
        <?php endif; ?>
        <?php if ($canSellUniform): ?>
            <a class="btn btn-outline-primary" href="<?= url('admin/uniform_sales.php') ?>"><i class="fa-solid fa-cart-shopping"></i>Sell Uniform</a>
        <?php else: ?>
            <button class="btn btn-outline-primary" disabled><i class="fa-solid fa-cart-shopping"></i>Sell Uniform</button>
        <?php endif; ?>
    </div>
</div>

<section class="panel">
    <h2>Uniform Items</h2>
    <div class="table-responsive">
        <table class="table table-striped align-middle">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Category</th>
                    <th>Gender</th>
                    <th>Size</th>
                    <th>Price</th>
                    <th>Available</th>
                    <th>Reorder</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($uniforms as $u): ?>
                    <tr>
                        <td><?= h($u['uniform_name']) ?></td>
                        <td><?= h($u['category']) ?></td>
                        <td><?= h($u['gender']) ?></td>
                        <td><?= h($u['size']) ?></td>
                        <td><?= money((float)$u['selling_price']) ?></td>
                        <td><?= (int)$u['available_stock'] ?></td>
                        <td><?= (int)$u['reorder_level'] ?></td>
                        <td><?= h($u['status']) ?></td>
                        <td>
                            <div class="action-row">
                                <?php if ($canManageUniform): ?>
                                    <a class="btn btn-sm btn-outline-primary" href="<?= url('admin/uniform_form.php?id=' . $u['id']) ?>">Edit</a>
                                    <a class="btn btn-sm btn-outline-secondary" href="<?= url('admin/uniform_stock_form.php?id=' . $u['id']) ?>">Add Stock</a>
                                <?php else: ?>
                                    <button class="btn btn-sm btn-outline-primary" disabled>Edit</button>
                                    <button class="btn btn-sm btn-outline-secondary" disabled>Add Stock</button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$uniforms): ?>
                    <tr><td colspan="9">No uniform items yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>
