<?php
$pageTitle = 'Uniform Item';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';

// Module access
if (!current_admin_has_permission($pdo, 'school_uniform.access')) {
    flash('error', 'You do not have permission to access School Uniform.');
    redirect('admin/dashboard.php');
}

$canEditCatalog = current_admin_has_permission($pdo, 'school_uniform.edit_catalog');
$canManageInventory = current_admin_has_permission($pdo, 'school_uniform.inventory');

$id = (int) ($_GET['id'] ?? 0);
$error = flash('error');
$success = flash('success');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $uniformName = trim($_POST['uniform_name'] ?? '');
    $category = trim($_POST['category'] ?? 'General');
    $gender = trim($_POST['gender'] ?? 'Male');
    $size = trim($_POST['size'] ?? 'One Size');
    $sellingPrice = (float) ($_POST['selling_price'] ?? 0);
    $openingStock = (int) ($_POST['opening_stock'] ?? 0);
    $reorderLevel = (int) ($_POST['reorder_level'] ?? 0);
    $status = trim($_POST['status'] ?? 'Active');
    $description = trim($_POST['description'] ?? '');

    if ($uniformName === '') {
        flash('error', 'Uniform name is required.');
        redirect('admin/uniform_form.php' . ($id ? '?id=' . $id : ''));
    }

    if (isset($_POST['save'])) {
        if (!$canEditCatalog) {
            flash('error', 'You do not have permission to edit the uniform catalog.');
            redirect('admin/uniforms.php');
        }
        if ($id > 0) {
            $stmt = $pdo->prepare('UPDATE uniforms SET uniform_name = :uniform_name, category = :category, gender = :gender, size = :size, selling_price = :selling_price, opening_stock = :opening_stock, reorder_level = :reorder_level, status = :status, description = :description WHERE id = :id');
            $stmt->execute([
                'uniform_name' => $uniformName,
                'category' => $category,
                'gender' => $gender,
                'size' => $size,
                'selling_price' => $sellingPrice,
                'opening_stock' => $openingStock,
                'reorder_level' => $reorderLevel,
                'status' => $status,
                'description' => $description,
                'id' => $id,
            ]);
            flash('success', 'Uniform updated.');
        } else {
            $stmt = $pdo->prepare('INSERT INTO uniforms (uniform_name, category, gender, size, selling_price, opening_stock, reorder_level, status, description) VALUES (:uniform_name, :category, :gender, :size, :selling_price, :opening_stock, :reorder_level, :status, :description)');
            $stmt->execute([
                'uniform_name' => $uniformName,
                'category' => $category,
                'gender' => $gender,
                'size' => $size,
                'selling_price' => $sellingPrice,
                'opening_stock' => $openingStock,
                'reorder_level' => $reorderLevel,
                'status' => $status,
                'description' => $description,
            ]);
            flash('success', 'Uniform created.');
        }
        redirect('admin/uniforms.php');
    }

    if (isset($_POST['delete']) && $id > 0) {
        if (!$canEditCatalog) {
            flash('error', 'You do not have permission to delete uniform items.');
            redirect('admin/uniforms.php');
        }
        $pdo->prepare('DELETE FROM uniforms WHERE id = :id')->execute(['id' => $id]);
        flash('success', 'Uniform deleted.');
        redirect('admin/uniforms.php');
    }
}

$uniform = null;
if ($id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM uniforms WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $uniform = $stmt->fetch();
    if (!$uniform) {
        flash('error', 'Uniform not found.');
        redirect('admin/uniforms.php');
    }
}

require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title">
    <div>
        <p class="eyebrow">Inventory</p>
        <h1><?= $id ? 'Edit Uniform' : 'New Uniform' ?></h1>
    </div>
    <div class="action-row">
        <a class="btn btn-outline-primary" href="<?= url('admin/uniforms.php') ?>">Back to Catalog</a>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>
<?php if ($success): ?>
    <div class="alert alert-success"><?= h($success) ?></div>
<?php endif; ?>

<section class="panel">
    <form method="post" class="row g-3">
        <div class="col-md-6">
            <label class="form-label">Uniform Name</label>
            <input class="form-control" name="uniform_name" value="<?= h($uniform['uniform_name'] ?? '') ?>" required>
        </div>
        <div class="col-md-6">
            <label class="form-label">Category</label>
            <input class="form-control" name="category" value="<?= h($uniform['category'] ?? '') ?>">
        </div>
        <div class="col-md-4">
            <label class="form-label">Gender</label>
            <select class="form-select" name="gender">
                <option value="Male" <?= (($uniform['gender'] ?? '') === 'Male') ? 'selected' : '' ?>>Male</option>
                <option value="Female" <?= (($uniform['gender'] ?? '') === 'Female') ? 'selected' : '' ?>>Female</option>
                <option value="Unisex" <?= (($uniform['gender'] ?? '') === 'Unisex') ? 'selected' : '' ?>>Unisex</option>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label">Size</label>
            <input class="form-control" name="size" value="<?= h($uniform['size'] ?? '') ?>">
        </div>
        <div class="col-md-4">
            <label class="form-label">Selling Price</label>
            <input class="form-control" type="number" step="0.01" name="selling_price" value="<?= h($uniform['selling_price'] ?? '') ?>">
        </div>
        <div class="col-md-4">
            <label class="form-label">Opening Stock</label>
            <input class="form-control" type="number" name="opening_stock" value="<?= h($uniform['opening_stock'] ?? 0) ?>">
        </div>
        <div class="col-md-4">
            <label class="form-label">Reorder Level</label>
            <input class="form-control" type="number" name="reorder_level" value="<?= h($uniform['reorder_level'] ?? 0) ?>">
        </div>
        <div class="col-md-4">
            <label class="form-label">Status</label>
            <select class="form-select" name="status">
                <option value="Active" <?= (($uniform['status'] ?? '') === 'Active') ? 'selected' : '' ?>>Active</option>
                <option value="Inactive" <?= (($uniform['status'] ?? '') === 'Inactive') ? 'selected' : '' ?>>Inactive</option>
            </select>
        </div>
        <div class="col-12">
            <label class="form-label">Description</label>
            <textarea class="form-control" name="description"><?= h($uniform['description'] ?? '') ?></textarea>
        </div>
        <div class="col-12">
            <?php if ($canEditCatalog): ?>
                <button class="btn btn-primary" type="submit" name="save">Save</button>
                <?php if ($id): ?>
                    <button class="btn btn-danger" type="submit" name="delete" onclick="return confirm('Delete this uniform?')">Delete</button>
                <?php endif; ?>
            <?php else: ?>
                <button class="btn btn-primary" type="button" disabled>Save</button>
                <?php if ($id): ?>
                    <button class="btn btn-danger" type="button" disabled>Delete</button>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </form>
</section>

<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>
