<?php
$pageTitle = 'Uniform Stock Management';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';

$statement = $pdo->query(
    "SELECT u.*, (u.opening_stock + COALESCE(SUM(usm.quantity),0)) AS available_stock
     FROM uniforms u
     LEFT JOIN uniform_stock_movements usm ON usm.uniform_id = u.id
     GROUP BY u.id
     ORDER BY available_stock ASC, u.uniform_name ASC"
);
$uniforms = $statement->fetchAll();

require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title">
    <div>
        <p class="eyebrow">Inventory</p>
        <h1>Uniform Stock</h1>
        <p class="mb-0 text-muted">Add stock, record damages/returns and view movement history.</p>
    </div>
    <div class="action-row">
        <a class="btn btn-primary" href="<?= url('admin/uniforms.php') ?>">Catalog</a>
    </div>
</div>

<section class="panel">
    <h2>Stock Overview</h2>
    <div class="table-responsive">
        <table class="table table-striped align-middle">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Size</th>
                    <th>Opening</th>
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
                        <td><?= h($u['size']) ?></td>
                        <td><?= (int)$u['opening_stock'] ?></td>
                        <td><?= (int)$u['available_stock'] ?></td>
                        <td><?= (int)$u['reorder_level'] ?></td>
                        <td><?= h($u['status']) ?></td>
                        <td>
                            <a class="btn btn-sm btn-outline-primary" href="<?= url('admin/uniform_stock_form.php?id=' . $u['id']) ?>">Adjust</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>
