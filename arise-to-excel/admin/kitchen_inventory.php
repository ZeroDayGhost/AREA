<?php
$pageTitle = 'Kitchen Inventory';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';

$errors = [];
$editingId = (int) ($_GET['edit'] ?? ($_POST['item_id'] ?? 0));
$form = [
    'id' => '',
    'item_name' => '',
    'quantity' => '',
    'unit_price' => '',
    'item_date' => date('Y-m-d'),
    'supplier' => '',
];

function kitchen_item_by_id(PDO $pdo, int $id): ?array
{
    $statement = $pdo->prepare("SELECT * FROM kitchen_inventory WHERE id = :id");
    $statement->execute(['id' => $id]);
    $item = $statement->fetch();

    return $item ?: null;
}

function kitchen_duplicate_exists(PDO $pdo, string $itemName, string $itemDate, string $supplier, int $ignoreId = 0): bool
{
    $sql = "SELECT COUNT(*)
            FROM kitchen_inventory
            WHERE item_name = :item_name
              AND item_date = :item_date
              AND COALESCE(supplier, '') = :supplier";
    $params = [
        'item_name' => $itemName,
        'item_date' => $itemDate,
        'supplier' => $supplier,
    ];

    if ($ignoreId > 0) {
        $sql .= " AND id <> :id";
        $params['id'] = $ignoreId;
    }

    $statement = $pdo->prepare($sql);
    $statement->execute($params);

    return (int) $statement->fetchColumn() > 0;
}

if ($editingId > 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $item = kitchen_item_by_id($pdo, $editingId);
    if ($item) {
        $form = array_merge($form, $item);
    } else {
        flash('error', 'Kitchen item was not found.');
        redirect('admin/kitchen_inventory.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save';
    $itemId = (int) ($_POST['item_id'] ?? 0);

    if ($action === 'delete') {
        $item = $itemId > 0 ? kitchen_item_by_id($pdo, $itemId) : null;

        if (!$item) {
            $errors[] = 'Choose an item to delete.';
        } else {
            $statement = $pdo->prepare("DELETE FROM kitchen_inventory WHERE id = :id");
            $statement->execute(['id' => $itemId]);
            flash('success', 'Kitchen item deleted successfully.');
            redirect('admin/kitchen_inventory.php');
        }
    } else {
        $form = [
            'id' => $itemId,
            'item_name' => trim($_POST['item_name'] ?? ''),
            'quantity' => trim($_POST['quantity'] ?? ''),
            'unit_price' => trim($_POST['unit_price'] ?? ''),
            'item_date' => trim($_POST['item_date'] ?? ''),
            'supplier' => trim($_POST['supplier'] ?? ''),
        ];

        if ($form['item_name'] === '') {
            $errors[] = 'Item name is required.';
        }
        if (!valid_quantity_value($form['quantity']) || (float) $form['quantity'] <= 0) {
            $errors[] = 'Quantity must be greater than zero.';
        }
        if (!valid_money_value($form['unit_price'])) {
            $errors[] = 'Unit price must be zero or more.';
        }
        if (!valid_date_value($form['item_date'])) {
            $errors[] = 'Date must be valid.';
        }
        if (!$errors && kitchen_duplicate_exists($pdo, $form['item_name'], $form['item_date'], $form['supplier'], $action === 'update' ? $itemId : 0)) {
            $errors[] = 'This kitchen item already exists for the same date and supplier.';
        }

        if (!$errors) {
            $totalAmount = (float) $form['quantity'] * (float) $form['unit_price'];
            $params = [
                'item_name' => $form['item_name'],
                'quantity' => (float) $form['quantity'],
                'unit_price' => (float) $form['unit_price'],
                'total_amount' => $totalAmount,
                'item_date' => $form['item_date'],
                'supplier' => $form['supplier'],
            ];

            if ($action === 'update' && $itemId > 0) {
                $params['id'] = $itemId;
                $statement = $pdo->prepare(
                    "UPDATE kitchen_inventory
                     SET item_name = :item_name,
                         quantity = :quantity,
                         unit_price = :unit_price,
                         total_amount = :total_amount,
                         item_date = :item_date,
                         supplier = :supplier
                     WHERE id = :id"
                );
                $statement->execute($params);
                flash('success', 'Kitchen item updated successfully.');
            } else {
                $statement = $pdo->prepare(
                    "INSERT INTO kitchen_inventory (item_name, quantity, unit_price, total_amount, item_date, supplier)
                     VALUES (:item_name, :quantity, :unit_price, :total_amount, :item_date, :supplier)"
                );
                $statement->execute($params);
                flash('success', 'Kitchen item added successfully.');
            }

            redirect('admin/kitchen_inventory.php');
        }
    }
}

$search = trim($_GET['search'] ?? '');
$dateFrom = valid_date_value($_GET['date_from'] ?? '') ? $_GET['date_from'] : '';
$dateTo = valid_date_value($_GET['date_to'] ?? '') ? $_GET['date_to'] : '';
$where = [];
$params = [];

if ($search !== '') {
    $where[] = '(item_name LIKE :search OR supplier LIKE :search)';
    $params['search'] = '%' . $search . '%';
}
if ($dateFrom !== '') {
    $where[] = 'item_date >= :date_from';
    $params['date_from'] = $dateFrom;
}
if ($dateTo !== '') {
    $where[] = 'item_date <= :date_to';
    $params['date_to'] = $dateTo;
}

$sql = "SELECT * FROM kitchen_inventory";
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY item_date DESC, id DESC';
$statement = $pdo->prepare($sql);
$statement->execute($params);
$items = $statement->fetchAll();
$totalInventory = array_sum(array_map(fn($item) => (float) $item['total_amount'], $items));

require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title">
    <div>
        <p class="eyebrow">Kitchen</p>
        <h1>Kitchen Inventory</h1>
    </div>
    <a class="btn btn-outline-primary" href="<?= url('admin/reports.php') ?>">Reports</a>
</div>

<?php if ($message = flash('success')): ?><div class="alert alert-success"><?= h($message) ?></div><?php endif; ?>
<?php if ($message = flash('error')): ?><div class="alert alert-danger"><?= h($message) ?></div><?php endif; ?>
<?php if ($errors): ?>
    <div class="alert alert-danger"><?php foreach ($errors as $error): ?><div><?= h($error) ?></div><?php endforeach; ?></div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-4">
        <section class="panel">
            <div class="panel-heading"><h2><?= (int) $form['id'] > 0 ? 'Edit Item' : 'Add Item' ?></h2></div>
            <form class="row g-3" method="post">
                <input type="hidden" name="item_id" value="<?= (int) $form['id'] ?>">
                <div class="col-12">
                    <label class="form-label" for="item_name">Item Name</label>
                    <input class="form-control" id="item_name" name="item_name" value="<?= h((string) $form['item_name']) ?>" placeholder="Rice" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="quantity">Quantity</label>
                    <input class="form-control" type="number" min="0.01" step="0.01" id="quantity" name="quantity" value="<?= h((string) $form['quantity']) ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="unit_price">Unit Price</label>
                    <input class="form-control" type="number" min="0" step="0.01" id="unit_price" name="unit_price" value="<?= h((string) $form['unit_price']) ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="item_date">Date</label>
                    <input class="form-control" type="date" id="item_date" name="item_date" value="<?= h((string) $form['item_date']) ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="supplier">Supplier</label>
                    <input class="form-control" id="supplier" name="supplier" value="<?= h((string) $form['supplier']) ?>">
                </div>
                <div class="col-12">
                    <div class="action-row">
                        <button class="btn btn-primary" name="action" value="save" type="submit">Save</button>
                        <button class="btn btn-outline-primary" name="action" value="update" type="submit" <?= (int) $form['id'] > 0 ? '' : 'disabled' ?>>Update</button>
                    </div>
                </div>
            </form>
        </section>
    </div>
    <div class="col-lg-8">
        <section class="panel">
            <div class="panel-heading">
                <h2>Inventory Purchases</h2>
                <strong><?= money($totalInventory) ?></strong>
            </div>
            <form class="row g-3 mb-4" method="get">
                <div class="col-md-4"><input class="form-control" name="search" value="<?= h($search) ?>" placeholder="Search item or supplier"></div>
                <div class="col-md-3"><input class="form-control" type="date" name="date_from" value="<?= h($dateFrom) ?>"></div>
                <div class="col-md-3"><input class="form-control" type="date" name="date_to" value="<?= h($dateTo) ?>"></div>
                <div class="col-md-2"><button class="btn btn-outline-primary w-100" type="submit">Filter</button></div>
            </form>
            <div class="table-responsive">
                <table class="table table-striped align-middle">
                    <thead><tr><th>Item</th><th>Qty</th><th>Unit Price</th><th>Total</th><th>Date</th><th>Supplier</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td><?= h($item['item_name']) ?></td>
                                <td><?= h((string) $item['quantity']) ?></td>
                                <td><?= money((float) $item['unit_price']) ?></td>
                                <td><?= money((float) $item['total_amount']) ?></td>
                                <td><?= h($item['item_date']) ?></td>
                                <td><?= h($item['supplier']) ?></td>
                                <td>
                                    <div class="action-row">
                                        <a class="btn btn-sm btn-outline-primary" href="<?= url('admin/kitchen_inventory.php?edit=' . $item['id']) ?>">Edit</a>
                                        <form method="post" onsubmit="return confirm('Delete this kitchen item?');">
                                            <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                                            <button class="btn btn-sm btn-outline-danger" name="action" value="delete" type="submit">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$items): ?><tr><td colspan="7">No kitchen items found.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>
