<?php

require_once __DIR__ . '/rbac.php';

function class_level_options(): array
{
    global $pdo;

    $defaultClassLevels = ['Playgroup', 'PP1', 'PP2', 'Grade 1', 'Grade 2', 'Grade 3', 'Grade 4', 'Grade 5', 'Grade 6'];

    if (!isset($pdo) || !$pdo instanceof PDO) {
        return $defaultClassLevels;
    }

    try {
        $statement = $pdo->query("SELECT name FROM class_levels WHERE status = 'Active' ORDER BY name ASC");
        $levels = array_column($statement->fetchAll(PDO::FETCH_ASSOC), 'name');
        return $levels !== [] ? $levels : $defaultClassLevels;
    } catch (Throwable $exception) {
        return $defaultClassLevels;
    }
}

function fetch_class_levels(PDO $pdo): array
{
    $statement = $pdo->query("SELECT id, name, status FROM class_levels ORDER BY name ASC");
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function get_class_level_by_id(PDO $pdo, int $id): ?array
{
    $statement = $pdo->prepare("SELECT id, name, status FROM class_levels WHERE id = :id LIMIT 1");
    $statement->execute(['id' => $id]);
    $classLevel = $statement->fetch();
    return $classLevel ?: null;
}

function class_level_exists(PDO $pdo, string $name, int $ignoreId = 0): bool
{
    $sql = "SELECT COUNT(*) FROM class_levels WHERE LOWER(name) = LOWER(:name)";
    $params = ['name' => $name];
    if ($ignoreId > 0) {
        $sql .= " AND id <> :id";
        $params['id'] = $ignoreId;
    }
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return (int) $statement->fetchColumn() > 0;
}

function save_class_level(PDO $pdo, string $name, int $id = 0): int
{
    if ($id > 0) {
        $statement = $pdo->prepare("UPDATE class_levels SET name = :name, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
        $statement->execute(['name' => $name, 'id' => $id]);
        return $id;
    }

    $statement = $pdo->prepare("INSERT INTO class_levels (name, status) VALUES (:name, 'Active')");
    $statement->execute(['name' => $name]);
    return (int) $pdo->lastInsertId();
}

function is_class_level_in_use(PDO $pdo, string $name): bool
{
    $statement = $pdo->prepare("SELECT COUNT(*) FROM students WHERE class_level = :name");
    $statement->execute(['name' => $name]);
    if ((int) $statement->fetchColumn() > 0) {
        return true;
    }

    $statement = $pdo->prepare("SELECT COUNT(*) FROM fee_structures WHERE class_level = :name");
    $statement->execute(['name' => $name]);
    return (int) $statement->fetchColumn() > 0;
}

function delete_class_level(PDO $pdo, int $id): bool
{
    $statement = $pdo->prepare("DELETE FROM class_levels WHERE id = :id");
    return $statement->execute(['id' => $id]);
}

function normalize_payment_code(string $value): string
{
    return strtoupper(trim(preg_replace('/\s+/', '', $value)));
}

function payment_code_duplicate_exists(PDO $pdo, string $value, string $context): bool
{
    $value = normalize_payment_code($value);
    if ($value === '') {
        return false;
    }

    $contexts = [
        'transport_reference' => ['table' => 'transport_payments', 'column' => 'reference_no'],
        'feeding_reference' => ['table' => 'feeding_payments', 'column' => 'reference_no'],
        'fee_mpesa_code' => ['table' => 'fees', 'column' => 'mpesa_code'],
        'uniform_mpesa_code' => ['table' => 'uniform_sales', 'column' => 'mpesa_code'],
    ];

    if (!isset($contexts[$context])) {
        throw new InvalidArgumentException('Invalid payment duplicate check context.');
    }

    $info = $contexts[$context];
    $statement = $pdo->prepare("SELECT COUNT(*) FROM {$info['table']} WHERE LOWER(TRIM({$info['column']})) = LOWER(TRIM(:value))");
    $statement->execute(['value' => $value]);

    return (int) $statement->fetchColumn() > 0;
}

function transport_fee_amount_for_location(PDO $pdo, string $location, ?string $academicYear = null): ?float
{
    $location = trim($location);
    if ($location === '') {
        return null;
    }

    if ($academicYear === null || !preg_match('/^\d{4}$/', trim($academicYear))) {
        $context = current_academic_context($pdo);
        $academicYear = $context['academic_year'];
    }
    $statement = $pdo->prepare(
        "SELECT fee_amount
         FROM transport_fee_structures
         WHERE location_name = :location_name
           AND academic_year = :academic_year
           AND status = 'Active'
         LIMIT 1"
    );
    $statement->execute([
        'location_name' => $location,
        'academic_year' => $academicYear,
    ]);

    $fee = $statement->fetchColumn();
    return $fee === false ? null : (float) $fee;
}

// Kitchen inventory helpers
function ensure_kitchen_tables(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS kitchen_items (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            item_name VARCHAR(120) NOT NULL,
            unit VARCHAR(30) NOT NULL DEFAULT 'kg',
            category VARCHAR(60) NOT NULL DEFAULT 'Kitchen',
            opening_stock DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            min_stock_level DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB"
    );

    // ensure a normalized name column and unique constraint to prevent case/space duplicates
    if (!db_has_column($pdo, 'kitchen_items', 'item_name_norm')) {
        $pdo->exec("ALTER TABLE kitchen_items ADD COLUMN item_name_norm VARCHAR(120) GENERATED ALWAYS AS (LOWER(TRIM(item_name))) STORED AFTER item_name");
    }
    // create unique index on normalized name if it doesn't exist
    try {
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_kitchen_items_item_name_norm ON kitchen_items(item_name_norm)");
    } catch (Exception $e) {
        // some MySQL versions don't support IF NOT EXISTS for CREATE INDEX; ignore duplicate index errors
    }

    if (!db_has_column($pdo, 'kitchen_items', 'status')) {
        $pdo->exec("ALTER TABLE kitchen_items ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'active' AFTER updated_at");
    }

    if (!db_has_column($pdo, 'kitchen_items', 'unit')) {
        $pdo->exec("ALTER TABLE kitchen_items ADD COLUMN unit VARCHAR(30) NOT NULL DEFAULT 'kg' AFTER item_name");
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS kitchen_stock_movements (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            kitchen_item_id INT UNSIGNED NOT NULL,
            movement_type VARCHAR(30) NOT NULL,
            quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            total_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            reference_id INT UNSIGNED NULL,
            note TEXT NULL,
            recorded_by INT UNSIGNED NULL,
            transaction_date DATE NOT NULL,
            academic_year VARCHAR(4) NOT NULL,
            term VARCHAR(20) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_kitchen_stock_movements_item FOREIGN KEY (kitchen_item_id) REFERENCES kitchen_items(id) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS kitchen_daily_purchases (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            item_name VARCHAR(120) NOT NULL,
            quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            unit VARCHAR(30) NULL,
            amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            category VARCHAR(80) NOT NULL DEFAULT 'Daily',
            supplier VARCHAR(150) NULL,
            notes TEXT NULL,
            purchase_date DATE NOT NULL,
            academic_year VARCHAR(4) NOT NULL,
            term VARCHAR(20) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB"
    );
    if (!db_has_column($pdo, 'kitchen_daily_purchases', 'quantity')) {
        $pdo->exec("ALTER TABLE kitchen_daily_purchases ADD COLUMN quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER item_name");
    }
    if (!db_has_column($pdo, 'kitchen_daily_purchases', 'unit')) {
        $pdo->exec("ALTER TABLE kitchen_daily_purchases ADD COLUMN unit VARCHAR(30) NULL AFTER quantity");
    }
    if (!db_has_column($pdo, 'kitchen_daily_purchases', 'academic_year')) {
        $pdo->exec("ALTER TABLE kitchen_daily_purchases ADD COLUMN academic_year VARCHAR(4) NOT NULL DEFAULT '' AFTER purchase_date");
    }
    if (!db_has_column($pdo, 'kitchen_daily_purchases', 'term')) {
        $pdo->exec("ALTER TABLE kitchen_daily_purchases ADD COLUMN term VARCHAR(20) NOT NULL DEFAULT '' AFTER academic_year");
    }
    if (!db_has_column($pdo, 'kitchen_daily_purchases', 'payment_method')) {
        $pdo->exec("ALTER TABLE kitchen_daily_purchases ADD COLUMN payment_method VARCHAR(80) NULL AFTER notes");
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS weekly_shopping (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            supplier VARCHAR(150) NULL,
            shopping_date DATE NOT NULL,
            total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            academic_year VARCHAR(4) NOT NULL,
            term VARCHAR(20) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS weekly_shopping_items (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            weekly_shopping_id INT UNSIGNED NOT NULL,
            item_name VARCHAR(120) NOT NULL,
            quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            unit VARCHAR(30) NULL,
            unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_weekly_shopping_items_weekly FOREIGN KEY (weekly_shopping_id) REFERENCES weekly_shopping(id) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB"
    );
    if (!db_has_column($pdo, 'weekly_shopping_items', 'unit')) {
        $pdo->exec("ALTER TABLE weekly_shopping_items ADD COLUMN unit VARCHAR(30) NULL AFTER quantity");
    }
    if (!db_has_column($pdo, 'weekly_shopping_items', 'supplier')) {
        $pdo->exec("ALTER TABLE weekly_shopping_items ADD COLUMN supplier VARCHAR(150) NULL AFTER unit_price");
    }
    if (!db_has_column($pdo, 'weekly_shopping', 'academic_year')) {
        $pdo->exec("ALTER TABLE weekly_shopping ADD COLUMN academic_year VARCHAR(4) NOT NULL DEFAULT '' AFTER total_amount");
    }
    if (!db_has_column($pdo, 'weekly_shopping', 'term')) {
        $pdo->exec("ALTER TABLE weekly_shopping ADD COLUMN term VARCHAR(20) NOT NULL DEFAULT '' AFTER academic_year");
    }
    if (!db_has_column($pdo, 'kitchen_stock_movements', 'transaction_date')) {
        $pdo->exec("ALTER TABLE kitchen_stock_movements ADD COLUMN transaction_date DATE NOT NULL DEFAULT CURRENT_DATE AFTER recorded_by");
    }
    if (!db_has_column($pdo, 'kitchen_stock_movements', 'academic_year')) {
        $pdo->exec("ALTER TABLE kitchen_stock_movements ADD COLUMN academic_year VARCHAR(4) NOT NULL DEFAULT '' AFTER transaction_date");
    }
    if (!db_has_column($pdo, 'kitchen_stock_movements', 'term')) {
        $pdo->exec("ALTER TABLE kitchen_stock_movements ADD COLUMN term VARCHAR(20) NOT NULL DEFAULT '' AFTER academic_year");
    }
}

function db_has_column(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column");
    $stmt->execute(['table' => $table, 'column' => $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function registration_no_exists(PDO $pdo, string $registrationNo): bool
{
    $statement = $pdo->prepare("SELECT COUNT(*) FROM students WHERE registration_no = :registration_no");
    $statement->execute(['registration_no' => $registrationNo]);
    return (int) $statement->fetchColumn() > 0;
}

function generate_registration_no(PDO $pdo, string $academicYear = null): string
{
    $prefix = trim((string) ($academicYear ?? ''));
    if (!preg_match('/^\d{4}$/', $prefix)) {
        $context = current_academic_context($pdo);
        $prefix = $context['academic_year'];
    }
    $pattern = $prefix . '-%';
    $statement = $pdo->prepare("SELECT registration_no FROM students WHERE registration_no LIKE :pattern");
    $statement->execute(['pattern' => $pattern]);
    $maxSequence = 0;
    $regex = '/^' . preg_quote($prefix, '/') . '-(\d+)$/';

    foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $registrationNo) {
        if (preg_match($regex, (string) $registrationNo, $matches)) {
            $maxSequence = max($maxSequence, (int) $matches[1]);
        }
    }

    return $prefix . '-' . str_pad((string) ($maxSequence + 1), 4, '0', STR_PAD_LEFT);
}

function kitchen_unit_options(): array
{
    return [
        'kg' => 'Kilograms (kg)',
        'g' => 'Grams (g)',
        'L' => 'Litres (L)',
        'ml' => 'Millilitres (ml)',
        'pcs' => 'Pieces',
        'packets' => 'Packets',
        'bags' => 'Bags',
        'cartons' => 'Cartons',
        'loaves' => 'Loaves',
    ];
}

function kitchen_validate_unit(string $unit): string
{
    $unit = trim($unit);
    return $unit !== '' ? $unit : 'kg';
}

function kitchen_get_or_create_item(PDO $pdo, string $itemName, string $category = 'Kitchen', float $opening = 0.0, float $minLevel = 0.0, string $unit = 'kg'): array
{
    ensure_kitchen_tables($pdo);
    $unit = kitchen_validate_unit($unit);
    $name = trim($itemName);
    $norm = mb_strtolower($name);
    // prefer normalized lookup to avoid case/space duplicates
    $stmt = $pdo->prepare("SELECT * FROM kitchen_items WHERE (item_name_norm = :norm OR item_name = :item_name) LIMIT 1");
    $stmt->execute(['norm' => $norm, 'item_name' => $name]);
    $row = $stmt->fetch();
    if ($row) {
        $updates = [];
        $params = ['id' => $row['id']];

        if ($category && $row['category'] !== $category) {
            $updates[] = 'category = :category';
            $params['category'] = $category;
        }
        if ($opening > 0.0 && (float) $row['opening_stock'] !== $opening) {
            $updates[] = 'opening_stock = :opening_stock';
            $params['opening_stock'] = $opening;
        }
        if ($minLevel > 0.0 && (float) $row['min_stock_level'] !== $minLevel) {
            $updates[] = 'min_stock_level = :min_stock_level';
            $params['min_stock_level'] = $minLevel;
        }
        if ($unit && (!isset($row['unit']) || $row['unit'] !== $unit)) {
            $updates[] = 'unit = :unit';
            $params['unit'] = $unit;
        }

        if ($updates) {
            $updateSql = "UPDATE kitchen_items SET " . implode(', ', $updates) . " WHERE id = :id";
            $updateStmt = $pdo->prepare($updateSql);
            $updateStmt->execute($params);
            $stmt->execute(['norm' => $norm, 'item_name' => $name]);
            $row = $stmt->fetch();
        }

        return $row;
    }

    // insert normalized name trimmed
    $ins = $pdo->prepare(
        "INSERT INTO kitchen_items (item_name, unit, category, opening_stock, min_stock_level) VALUES (:item_name, :unit, :category, :opening_stock, :min_stock_level)"
    );
    $ins->execute([
        'item_name' => $name,
        'unit' => $unit,
        'category' => $category,
        'opening_stock' => $opening,
        'min_stock_level' => $minLevel,
    ]);

    // re-query and return
    $stmt->execute(['norm' => $norm, 'item_name' => $name]);
    return $stmt->fetch();
}

function kitchen_transaction_context(PDO $pdo, ?string $transactionDate = null): array
{
    $transactionDate = $transactionDate ?: date('Y-m-d');
    $context = current_academic_context($pdo, $transactionDate);

    return [
        'transaction_date' => $transactionDate,
        'academic_year' => $context['academic_year'],
        'term' => $context['term'],
    ];
}

function kitchen_record_movement(PDO $pdo, int $kitchenItemId, string $type, float $quantity, float $unitPrice = 0.0, float $total = 0.0, ?int $referenceId = null, ?string $note = null, ?int $recordedBy = null, ?string $transactionDate = null): void
{
    ensure_kitchen_tables($pdo);
    $context = kitchen_transaction_context($pdo, $transactionDate);
    $stmt = $pdo->prepare(
        "INSERT INTO kitchen_stock_movements (kitchen_item_id, movement_type, quantity, unit_price, total_cost, reference_id, note, recorded_by, transaction_date, academic_year, term) VALUES (:kitchen_item_id, :movement_type, :quantity, :unit_price, :total_cost, :reference_id, :note, :recorded_by, :transaction_date, :academic_year, :term)"
    );
    $stmt->execute([
        'kitchen_item_id' => $kitchenItemId,
        'movement_type' => $type,
        'quantity' => $quantity,
        'unit_price' => $unitPrice,
        'total_cost' => $total,
        'reference_id' => $referenceId,
        'note' => $note,
        'recorded_by' => $recordedBy,
        'transaction_date' => $context['transaction_date'],
        'academic_year' => $context['academic_year'],
        'term' => $context['term'],
    ]);
}

function kitchen_get_purchase_movement(PDO $pdo, int $referenceId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT * FROM kitchen_stock_movements WHERE reference_id = :reference_id AND movement_type = 'purchase' LIMIT 1"
    );
    $stmt->execute(['reference_id' => $referenceId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function kitchen_sync_purchase_movement(PDO $pdo, int $purchaseId, string $itemName, float $quantity, float $unitPrice, float $totalCost, ?string $note = null, ?int $recordedBy = null): void
{
    $kitem = kitchen_get_or_create_item($pdo, $itemName);
    $movement = kitchen_get_purchase_movement($pdo, $purchaseId);

    if ($movement) {
        $stmt = $pdo->prepare(
            "UPDATE kitchen_stock_movements
             SET kitchen_item_id = :kitchen_item_id,
                 quantity = :quantity,
                 unit_price = :unit_price,
                 total_cost = :total_cost,
                 note = :note,
                 recorded_by = :recorded_by
             WHERE id = :id"
        );
        $stmt->execute([
            'kitchen_item_id' => $kitem['id'],
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'total_cost' => $totalCost,
            'note' => $note,
            'recorded_by' => $recordedBy,
            'id' => $movement['id'],
        ]);
    } else {
        kitchen_record_movement($pdo, (int)$kitem['id'], 'purchase', $quantity, $unitPrice, $totalCost, $purchaseId, $note, $recordedBy);
    }
}

function kitchen_delete_purchase_movement(PDO $pdo, int $purchaseId): void
{
    $stmt = $pdo->prepare("DELETE FROM kitchen_stock_movements WHERE reference_id = :reference_id AND movement_type = 'purchase'");
    $stmt->execute(['reference_id' => $purchaseId]);
}

function kitchen_find_weekly_purchase_movement(PDO $pdo, string $itemName, float $quantity, float $totalAmount): ?array
{
    $stmt = $pdo->prepare(
        "SELECT ksm.id, ksm.reference_id
         FROM kitchen_stock_movements ksm
         JOIN kitchen_items ki ON ki.id = ksm.kitchen_item_id
         WHERE ksm.movement_type = 'purchase'
           AND ksm.note LIKE 'Weekly shopping%'
           AND ksm.quantity = :quantity
           AND ksm.total_cost = :total_amount
           AND ki.item_name = :item_name
         ORDER BY ksm.created_at DESC
         LIMIT 1"
    );
    $stmt->execute([
        'quantity' => $quantity,
        'total_amount' => $totalAmount,
        'item_name' => $itemName,
    ]);

    $movement = $stmt->fetch();
    return $movement ?: null;
}

function kitchen_delete_weekly_purchase_row(PDO $pdo, array $inventoryRow): void
{
    $movement = kitchen_find_weekly_purchase_movement($pdo, $inventoryRow['item_name'], (float) $inventoryRow['quantity'], (float) $inventoryRow['total_amount']);
    if (!$movement) {
        return;
    }

    $refId = (int) $movement['reference_id'];

    $deleteItemStmt = $pdo->prepare(
        "DELETE FROM weekly_shopping_items
         WHERE weekly_shopping_id = :weekly_shopping_id
           AND item_name = :item_name
           AND quantity = :quantity
           AND unit_price = :unit_price
         LIMIT 1"
    );
    $deleteItemStmt->execute([
        'weekly_shopping_id' => $refId,
        'item_name' => $inventoryRow['item_name'],
        'quantity' => (float) $inventoryRow['quantity'],
        'unit_price' => (float) $inventoryRow['unit_price'],
    ]);

    $deleteMovementStmt = $pdo->prepare("DELETE FROM kitchen_stock_movements WHERE id = :id");
    $deleteMovementStmt->execute(['id' => (int) $movement['id']]);

    $countItemsStmt = $pdo->prepare("SELECT COUNT(*) FROM weekly_shopping_items WHERE weekly_shopping_id = :weekly_shopping_id");
    $countItemsStmt->execute(['weekly_shopping_id' => $refId]);
    if ((int) $countItemsStmt->fetchColumn() === 0) {
        $deleteWeeklyStmt = $pdo->prepare("DELETE FROM weekly_shopping WHERE id = :id");
        $deleteWeeklyStmt->execute(['id' => $refId]);
    }
}

function kitchen_inventory_row_for_expense(PDO $pdo, array $expense): ?array
{
    $category = $expense['category'] ?? '';
    $params = [
        'item_name' => $expense['item_name'],
        'item_date' => $expense['expense_date'],
        'quantity' => (float) $expense['quantity'],
        'total_amount' => (float) $expense['total_amount'],
    ];

    if (in_array($category, ['Kitchen', 'WHOLESALE'], true)) {
        $sql = "SELECT * FROM kitchen_inventory
                WHERE item_name = :item_name
                  AND item_date = :item_date
                  AND quantity = :quantity
                  AND total_amount = :total_amount
                  AND category IN ('Kitchen', 'WHOLESALE')
                ORDER BY id DESC
                LIMIT 1";
    } else {
        $sql = "SELECT * FROM kitchen_inventory
                WHERE item_name = :item_name
                  AND item_date = :item_date
                  AND quantity = :quantity
                  AND total_amount = :total_amount
                  AND category = :category
                ORDER BY id DESC
                LIMIT 1";
        $params['category'] = $category;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ?: null;
}

function kitchen_inventory_delete_for_expense(PDO $pdo, array $expense): void
{
    $row = kitchen_inventory_row_for_expense($pdo, $expense);
    if (!$row) {
        return;
    }

    if (($row['purchase_type'] ?? 'single') === 'weekly') {
        kitchen_delete_weekly_purchase_row($pdo, $row);
    } else {
        kitchen_delete_purchase_movement($pdo, (int) $row['id']);
    }

    $stmt = $pdo->prepare("DELETE FROM kitchen_inventory WHERE id = :id");
    $stmt->execute(['id' => (int) $row['id']]);
}

function kitchen_update_weekly_purchase_row(PDO $pdo, array $inventoryRow, array $updated): void
{
    $movement = kitchen_find_weekly_purchase_movement($pdo, $inventoryRow['item_name'], (float) $inventoryRow['quantity'], (float) $inventoryRow['total_amount']);
    if (!$movement) {
        return;
    }

    $totalAmount = (float) $updated['amount'] * (float) $updated['quantity'];

    $updateMovement = $pdo->prepare(
        "UPDATE kitchen_stock_movements
         SET quantity = :quantity,
             unit_price = :unit_price,
             total_cost = :total_cost,
             note = :note
         WHERE id = :id"
    );
    $updateMovement->execute([
        'quantity' => (float) $updated['quantity'],
        'unit_price' => (float) $updated['amount'],
        'total_cost' => $totalAmount,
        'note' => 'Weekly shopping',
        'id' => (int) $movement['id'],
    ]);

    $updateItem = $pdo->prepare(
        "UPDATE weekly_shopping_items
         SET item_name = :item_name,
             quantity = :quantity,
             unit_price = :unit_price,
             total_amount = :total_amount
         WHERE weekly_shopping_id = :weekly_shopping_id
           AND item_name = :old_item_name
           AND quantity = :old_quantity
           AND unit_price = :old_unit_price
         LIMIT 1"
    );
    $updateItem->execute([
        'item_name' => $updated['item_name'],
        'quantity' => (float) $updated['quantity'],
        'unit_price' => (float) $updated['amount'],
        'total_amount' => $totalAmount,
        'weekly_shopping_id' => (int) $movement['reference_id'],
        'old_item_name' => $inventoryRow['item_name'],
        'old_quantity' => (float) $inventoryRow['quantity'],
        'old_unit_price' => (float) $inventoryRow['unit_price'],
    ]);

    $sumStmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM weekly_shopping_items WHERE weekly_shopping_id = :id");
    $sumStmt->execute(['id' => (int) $movement['reference_id']]);
    $total = (float) $sumStmt->fetchColumn();

    $updateWeekly = $pdo->prepare("UPDATE weekly_shopping SET total_amount = :total_amount WHERE id = :id");
    $updateWeekly->execute(['total_amount' => $total, 'id' => (int) $movement['reference_id']]);
}

function kitchen_inventory_update_for_expense(PDO $pdo, array $expense, array $updated): void
{
    $row = kitchen_inventory_row_for_expense($pdo, $expense);
    if (!$row) {
        return;
    }

    $totalAmount = (float) $updated['amount'] * (float) $updated['quantity'];
    $category = $updated['category'] ?? $expense['category'];
    $params = [
        'item_name' => $updated['item_name'],
        'quantity' => (float) $updated['quantity'],
        'unit_price' => (float) $updated['amount'],
        'total_amount' => $totalAmount,
        'item_date' => $updated['expense_date'],
        'supplier' => $updated['supplier'] ?? $expense['supplier'] ?? null,
        'category' => $category,
        'id' => (int) $row['id'],
    ];

    $stmt = $pdo->prepare(
        "UPDATE kitchen_inventory
         SET item_name = :item_name,
             quantity = :quantity,
             unit_price = :unit_price,
             total_amount = :total_amount,
             item_date = :item_date,
             supplier = :supplier,
             category = :category
         WHERE id = :id"
    );
    $stmt->execute($params);

    if (($row['purchase_type'] ?? 'single') === 'weekly') {
        kitchen_update_weekly_purchase_row($pdo, $row, $updated);
    } else {
        kitchen_sync_purchase_movement(
            $pdo,
            (int) $row['id'],
            $updated['item_name'],
            (float) $updated['quantity'],
            (float) $updated['amount'],
            $totalAmount,
            'Purchase from ' . ($updated['supplier'] ?? ''),
            $_SESSION['admin_id'] ?? null
        );
    }
}

function kitchen_aggregated_summary(PDO $pdo, ?string $academicYear = null, ?string $term = null, bool $ignoreContext = false): array
{
    ensure_kitchen_tables($pdo);
    $context = current_academic_context($pdo);
    if (!$ignoreContext) {
        if ($academicYear === null) {
            $academicYear = $context['academic_year'];
        }
        if ($term === null) {
            $term = $context['term'];
        }
    }

    $itemsStmt = $pdo->query("SELECT * FROM kitchen_items ORDER BY item_name ASC");
    $rawItems = $itemsStmt->fetchAll();
    // deduplicate by normalized name to avoid showing duplicates (case/space variants)
    $items = [];
    $seen = [];
    foreach ($rawItems as $it) {
        $norm = mb_strtolower(trim($it['item_name']));
        if (isset($seen[$norm])) continue;
        $seen[$norm] = true;
        $items[] = $it;
    }
    $rows = [];

    $movementInSql = "SELECT COALESCE(SUM(quantity),0) FROM kitchen_stock_movements WHERE kitchen_item_id = :id AND movement_type IN ('in','purchase')";
    $outSql = "SELECT COALESCE(SUM(quantity),0) FROM kitchen_stock_movements WHERE kitchen_item_id = :id AND movement_type = 'out'";
    $inventoryPurchaseSql = db_has_column($pdo, 'kitchen_inventory', 'purchase_type')
        ? "SELECT COALESCE(SUM(quantity),0) FROM kitchen_inventory WHERE item_name = :item_name AND COALESCE(purchase_type,'weekly') != 'daily'"
        : "SELECT COALESCE(SUM(quantity),0) FROM kitchen_inventory WHERE item_name = :item_name";
    if (!$ignoreContext && db_has_column($pdo, 'kitchen_stock_movements', 'academic_year') && db_has_column($pdo, 'kitchen_stock_movements', 'term')) {
        $movementInSql .= " AND academic_year = :academic_year AND term = :term";
        $outSql .= " AND academic_year = :academic_year AND term = :term";
    }
    if (!$ignoreContext && db_has_column($pdo, 'kitchen_inventory', 'academic_year') && db_has_column($pdo, 'kitchen_inventory', 'term')) {
        $inventoryPurchaseSql .= " AND academic_year = :academic_year AND term = :term";
    }

    $movementInStmt = $pdo->prepare($movementInSql);
    $outStmt = $pdo->prepare($outSql);
    $inventoryPurchaseStmt = $pdo->prepare($inventoryPurchaseSql);
    $inventoryUnitStmt = $pdo->prepare("SELECT unit FROM kitchen_inventory WHERE item_name = :item_name AND unit IS NOT NULL LIMIT 1");
    $lastPriceSql = "SELECT unit_price FROM kitchen_stock_movements WHERE kitchen_item_id = :id AND movement_type IN ('in','purchase')";
    if (!$ignoreContext && db_has_column($pdo, 'kitchen_stock_movements', 'academic_year') && db_has_column($pdo, 'kitchen_stock_movements', 'term')) {
        $lastPriceSql .= " AND academic_year = :academic_year AND term = :term";
    }
    $lastPriceSql .= " ORDER BY transaction_date DESC, id DESC LIMIT 1";
    $lastPriceStmt = $pdo->prepare($lastPriceSql);
    $inventoryLatestPriceStmt = $pdo->prepare("SELECT unit_price FROM kitchen_inventory WHERE item_name = :item_name ORDER BY item_date DESC, id DESC LIMIT 1");

    foreach ($items as $it) {
        $movementInParams = ['id' => $it['id']];
        $outParams = ['id' => $it['id']];
        if (!$ignoreContext && db_has_column($pdo, 'kitchen_stock_movements', 'academic_year') && db_has_column($pdo, 'kitchen_stock_movements', 'term')) {
            $movementInParams['academic_year'] = $academicYear;
            $movementInParams['term'] = $term;
            $outParams['academic_year'] = $academicYear;
            $outParams['term'] = $term;
        }
        $movementInStmt->execute($movementInParams);
        $ins = (float) $movementInStmt->fetchColumn();

        $outStmt->execute($outParams);
        $outs = (float) $outStmt->fetchColumn();

        $inventoryPurchased = 0.0;
        if ($ins <= 0) {
            $inventoryPurchaseParams = ['item_name' => $it['item_name']];
            if (!$ignoreContext && db_has_column($pdo, 'kitchen_inventory', 'academic_year') && db_has_column($pdo, 'kitchen_inventory', 'term')) {
                $inventoryPurchaseParams['academic_year'] = $academicYear;
                $inventoryPurchaseParams['term'] = $term;
            }
            $inventoryPurchaseStmt->execute($inventoryPurchaseParams);
            $inventoryPurchased = (float) $inventoryPurchaseStmt->fetchColumn();
        }

        $opening = (float) $it['opening_stock'];
        $purchased = $ins > 0 ? $ins : $inventoryPurchased;
        $remaining = $opening + $purchased - $outs;

        $lastPriceStmt->execute($movementInParams);
        $unitPrice = (float) $lastPriceStmt->fetchColumn();
        $stockValue = $remaining * $unitPrice;

        $rows[] = [
            'id' => (int) $it['id'],
            'item_name' => $it['item_name'],
            'category' => $it['category'],
            'unit' => $it['unit'] ?: 'kg',
            'opening_stock' => $opening,
            'purchased_quantity' => $purchased,
            'used_quantity' => $outs,
            'remaining_stock' => $remaining,
            'unit_price' => $unitPrice,
            'stock_value' => $stockValue,
            'min_stock_level' => (float) $it['min_stock_level'],
        ];
    }

    $inventoryDistinctSql = "SELECT DISTINCT item_name FROM kitchen_inventory";
    if (!$ignoreContext && db_has_column($pdo, 'kitchen_inventory', 'academic_year') && db_has_column($pdo, 'kitchen_inventory', 'term')) {
        $inventoryDistinctSql .= " WHERE academic_year = :academic_year AND term = :term";
    }
    $inventoryDistinctSql .= " ORDER BY item_name ASC";
    $stmt = $pdo->prepare($inventoryDistinctSql);
    $params = [];
    if (!$ignoreContext && db_has_column($pdo, 'kitchen_inventory', 'academic_year') && db_has_column($pdo, 'kitchen_inventory', 'term')) {
        $params = ['academic_year' => $academicYear, 'term' => $term];
    }
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $r) {
        $exists = false;
        foreach ($rows as $row) {
            if ($row['item_name'] === $r['item_name']) { $exists = true; break; }
        }
        if ($exists) continue;

        $inventoryPurchaseParams = ['item_name' => $r['item_name']];
        if (db_has_column($pdo, 'kitchen_inventory', 'academic_year') && db_has_column($pdo, 'kitchen_inventory', 'term')) {
            $inventoryPurchaseParams['academic_year'] = $academicYear;
            $inventoryPurchaseParams['term'] = $term;
        }
        $inventoryPurchaseStmt->execute($inventoryPurchaseParams);
        $purchased = (float) $inventoryPurchaseStmt->fetchColumn();
        $inventoryUnitStmt->execute(['item_name' => $r['item_name']]);
        $unitRow = $inventoryUnitStmt->fetch();
        $inventoryLatestPriceStmt->execute(['item_name' => $r['item_name']]);
        $unitPrice = (float) $inventoryLatestPriceStmt->fetchColumn();
        $stockValue = $purchased * $unitPrice;

        $rows[] = [
            'id' => 0,
            'item_name' => $r['item_name'],
            'category' => 'Kitchen',
            'unit' => $unitRow['unit'] ?? 'kg',
            'opening_stock' => 0.0,
            'purchased_quantity' => $purchased,
            'used_quantity' => 0.0,
            'remaining_stock' => $purchased,
            'unit_price' => $unitPrice,
            'stock_value' => $stockValue,
            'min_stock_level' => 0.0,
        ];
    }

    return $rows;
}

function low_stock_kitchen_items(PDO $pdo): array
{
    $rows = kitchen_aggregated_summary($pdo);
    $low = [];
    foreach ($rows as $r) {
        if ($r['remaining_stock'] <= 0) {
            $r['warning'] = 'Out of Stock';
            $low[] = $r;
            continue;
        }
        if ($r['remaining_stock'] <= $r['min_stock_level']) {
            $r['warning'] = 'Low Stock';
            $low[] = $r;
        }
    }
    return $low;
}

function low_stock_kitchen_items_all(PDO $pdo): array
{
    // Aggregate across all academic years/terms so notifications persist until addressed.
    $rows = kitchen_aggregated_summary($pdo, null, null, true);
    $low = [];
    foreach ($rows as $r) {
        if ($r['remaining_stock'] <= 0) {
            $r['warning'] = 'Out of Stock';
            $low[] = $r;
            continue;
        }
        if ($r['remaining_stock'] <= $r['min_stock_level']) {
            $r['warning'] = 'Low Stock';
            $low[] = $r;
        }
    }
    return $low;
}

function kitchen_inventory_valuation(PDO $pdo): float
{
    ensure_kitchen_tables($pdo);
    $inStmt = $pdo->query("SELECT COALESCE(SUM(total_cost),0) FROM kitchen_stock_movements WHERE movement_type IN ('in','purchase')");
    $outStmt = $pdo->query("SELECT COALESCE(SUM(total_cost),0) FROM kitchen_stock_movements WHERE movement_type = 'out'");
    return (float) $inStmt->fetchColumn() - (float) $outStmt->fetchColumn();
}

// Record a daily purchase and update inventory stock levels.
function record_daily_purchase(PDO $pdo, string $itemName, float $quantity, string $unit, float $amount, string $category, ?string $supplier, ?string $notes, ?string $paymentMethod, string $date): int
{
    $context = current_academic_context($pdo, $date);
    $columns = 'item_name, quantity, unit, amount, category, supplier, notes, payment_method, purchase_date';
    $values = ':item_name, :quantity, :unit, :amount, :category, :supplier, :notes, :payment_method, :purchase_date';
    $params = [
        'item_name' => $itemName,
        'quantity' => $quantity,
        'unit' => $unit ?: 'kg',
        'amount' => $amount,
        'category' => $category ?: 'Kitchen',
        'supplier' => $supplier,
        'notes' => $notes,
        'payment_method' => $paymentMethod,
        'purchase_date' => $date,
    ];
    if (db_has_column($pdo, 'kitchen_daily_purchases', 'academic_year') && db_has_column($pdo, 'kitchen_daily_purchases', 'term')) {
        $columns .= ', academic_year, term';
        $values .= ', :academic_year, :term';
        $params['academic_year'] = $context['academic_year'];
        $params['term'] = $context['term'];
    }
    $stmt = $pdo->prepare(
        "INSERT INTO kitchen_daily_purchases ({$columns}) VALUES ({$values})"
    );
    $stmt->execute($params);
    $id = (int) $pdo->lastInsertId();

    // Daily purchases are consumed the same day and should not be added to inventory stock movements.
    // We still record the purchase in `kitchen_daily_purchases` and as a school expense, but we do not
    // create a stock movement or update kitchen inventory for daily purchases.
    $unitPrice = $quantity > 0 ? $amount / $quantity : 0.0;

    // Also record as a school expense for reporting
    $expenseColumns = 'item_name, category, amount, quantity, total_amount, expense_date, description';
    $expenseValues = ':item_name, :category, :amount, :quantity, :total_amount, :expense_date, :description';
    if (db_has_column($pdo, 'school_expenses', 'academic_year') && db_has_column($pdo, 'school_expenses', 'term')) {
        $expenseColumns .= ', academic_year, term';
        $expenseValues .= ', :academic_year, :term';
        $params['academic_year'] = $context['academic_year'];
        $params['term'] = $context['term'];
    }
    $expenseHasTerm = db_has_column($pdo, 'school_expenses', 'academic_year') && db_has_column($pdo, 'school_expenses', 'term');
    if ($expenseHasTerm) {
        $expenseColumns .= ', academic_year, term';
        $expenseValues .= ', :academic_year, :term';
    }
    $exp = $pdo->prepare("INSERT INTO school_expenses ({$expenseColumns}) VALUES ({$expenseValues})");
    $expParams = [
        'item_name' => $itemName,
        'category' => $category ?: 'Kitchen Purchases',
        'amount' => $unitPrice,
        'quantity' => $quantity,
        'total_amount' => $amount,
        'expense_date' => $date,
        'description' => ($notes ?: 'Daily purchase') . ($supplier ? ' | Supplier: ' . $supplier : '') . ($paymentMethod ? ' | Payment: ' . $paymentMethod : ''),
    ];
    if ($expenseHasTerm) {
        $expParams['academic_year'] = $context['academic_year'];
        $expParams['term'] = $context['term'];
    }
    $exp->execute($expParams);

    return $id;
}

// Record a weekly shopping transaction that can contain multiple items.
function record_weekly_shopping(PDO $pdo, ?string $supplier, string $date, array $items): int
{
    // items: array of ['item_name','quantity','unit','unit_price','supplier']
    // each item can have its own supplier
    $total = 0.0;
    foreach ($items as $it) {
        $qty = (float) ($it['quantity'] ?? 0);
        $up = (float) ($it['unit_price'] ?? 0);
        $total += $qty * $up;
    }

    $context = current_academic_context($pdo, $date);
    $wsHasTerm = db_has_column($pdo, 'weekly_shopping', 'academic_year') && db_has_column($pdo, 'weekly_shopping', 'term');
    if ($wsHasTerm) {
        $ins = $pdo->prepare("INSERT INTO weekly_shopping (supplier, shopping_date, total_amount, academic_year, term) VALUES (:supplier, :shopping_date, :total_amount, :academic_year, :term)");
        $ins->execute([
            'supplier' => $supplier,
            'shopping_date' => $date,
            'total_amount' => $total,
            'academic_year' => $context['academic_year'],
            'term' => $context['term'],
        ]);
    } else {
        $ins = $pdo->prepare("INSERT INTO weekly_shopping (supplier, shopping_date, total_amount) VALUES (:supplier, :shopping_date, :total_amount)");
        $ins->execute([
            'supplier' => $supplier,
            'shopping_date' => $date,
            'total_amount' => $total,
        ]);
    }
    $wsId = (int) $pdo->lastInsertId();

    $hasSupplierColumn = db_has_column($pdo, 'weekly_shopping_items', 'supplier');
    $itemIns = $pdo->prepare("INSERT INTO weekly_shopping_items (weekly_shopping_id, item_name, quantity, unit, unit_price, total_amount" . ($hasSupplierColumn ? ", supplier" : "") . ") VALUES (:weekly_shopping_id, :item_name, :quantity, :unit, :unit_price, :total_amount" . ($hasSupplierColumn ? ", :supplier" : "") . ")");
    foreach ($items as $it) {
        $name = trim($it['item_name'] ?? '');
        $qty = (float) ($it['quantity'] ?? 0);
        $unit = $it['unit'] ?? null;
        $up = (float) ($it['unit_price'] ?? 0);
        $itemSupplier = trim($it['supplier'] ?? '');
        if ($name === '' || $qty <= 0) continue;
        $totalItem = $qty * $up;
        $itemInsParams = ['weekly_shopping_id' => $wsId, 'item_name' => $name, 'quantity' => $qty, 'unit' => $unit, 'unit_price' => $up, 'total_amount' => $totalItem];
        if ($hasSupplierColumn) {
            $itemInsParams['supplier'] = $itemSupplier;
        }
        $itemIns->execute($itemInsParams);

        // Insert into kitchen_inventory as a stock-in purchase (weekly purchases affect stock)
        if (db_has_column($pdo, 'kitchen_inventory', 'purchase_type')) {
            $stmtSql = "INSERT INTO kitchen_inventory (item_name, quantity, unit, unit_price, total_amount, item_date, supplier, purchase_type, category";
            $stmtVals = ":item_name, :quantity, :unit, :unit_price, :total_amount, :item_date, :supplier, 'weekly', 'Kitchen'";
            $itemParams = [
                'item_name' => $name,
                'quantity' => $qty,
                'unit' => $unit,
                'unit_price' => $up,
                'total_amount' => $totalItem,
                'item_date' => $date,
                'supplier' => $itemSupplier,
            ];
            if (db_has_column($pdo, 'kitchen_inventory', 'academic_year') && db_has_column($pdo, 'kitchen_inventory', 'term')) {
                $stmtSql .= ", academic_year, term";
                $stmtVals .= ", :academic_year, :term";
                $itemParams['academic_year'] = $context['academic_year'];
                $itemParams['term'] = $context['term'];
            }
            $stmtSql .= ") VALUES ($stmtVals)";
            $stmt = $pdo->prepare($stmtSql);
            $stmt->execute($itemParams);
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO kitchen_inventory (item_name, quantity, unit, unit_price, total_amount, item_date, supplier, category) VALUES (:item_name, :quantity, :unit, :unit_price, :total_amount, :item_date, :supplier, 'Kitchen')"
            );
            $stmt->execute(['item_name' => $name, 'quantity' => $qty, 'unit' => $unit, 'unit_price' => $up, 'total_amount' => $totalItem, 'item_date' => $date, 'supplier' => $itemSupplier]);
        }
        $invId = (int) $pdo->lastInsertId();

        // ensure kitchen item exists and record movement referencing weekly_shopping id
        $kitem = kitchen_get_or_create_item($pdo, $name, 'Kitchen', 0.0, 0.0, $unit);
        kitchen_record_movement($pdo, (int)$kitem['id'], 'purchase', $qty, $up, $totalItem, $wsId, 'Weekly shopping', $_SESSION['admin_id'] ?? null, $date);
    }

    if ($total > 0) {
        $expenseColumns = 'item_name, category, amount, quantity, total_amount, expense_date, description';
        $expenseValues = ':item_name, :category, :amount, :quantity, :total_amount, :expense_date, :description';
        $expenseParams = [
            'item_name' => 'Weekly Kitchen Purchases',
            'category' => 'Kitchen Purchases',
            'amount' => $total,
            'quantity' => 1,
            'total_amount' => $total,
            'expense_date' => $date,
            'description' => 'Weekly shopping batch' . ($supplier ? ' | Supplier: ' . $supplier : ''),
        ];
        if (db_has_column($pdo, 'school_expenses', 'academic_year') && db_has_column($pdo, 'school_expenses', 'term')) {
            $expenseColumns .= ', academic_year, term';
            $expenseValues .= ', :academic_year, :term';
            $expenseParams['academic_year'] = $context['academic_year'];
            $expenseParams['term'] = $context['term'];
        }
        $exp = $pdo->prepare("INSERT INTO school_expenses ({$expenseColumns}) VALUES ({$expenseValues})");
        $exp->execute($expenseParams);
    }

    return $wsId;
}

function get_daily_purchases(PDO $pdo, ?string $from = null, ?string $to = null, ?string $academicYear = null, ?string $term = null): array
{
    $sql = "SELECT * FROM kitchen_daily_purchases WHERE 1=1";
    $params = [];
    if ($from) { $sql .= " AND purchase_date >= :from"; $params['from'] = $from; }
    if ($to) { $sql .= " AND purchase_date <= :to"; $params['to'] = $to; }
    if ($academicYear !== null && $term !== null) {
        if (db_has_column($pdo, 'kitchen_daily_purchases', 'academic_year') && db_has_column($pdo, 'kitchen_daily_purchases', 'term')) {
            $sql .= " AND academic_year = :academic_year AND term = :term";
            $params['academic_year'] = $academicYear;
            $params['term'] = $term;
        } elseif (empty($from) && empty($to)) {
            $calendarStmt = $pdo->prepare(
                "SELECT start_date, end_date FROM academic_calendar WHERE academic_year = :academic_year AND term_name = :term LIMIT 1"
            );
            $calendarStmt->execute(['academic_year' => $academicYear, 'term' => $term]);
            $calendarRow = $calendarStmt->fetch();
            if ($calendarRow) {
                $sql .= " AND purchase_date BETWEEN :active_term_start AND :active_term_end";
                $params['active_term_start'] = $calendarRow['start_date'];
                $params['active_term_end'] = $calendarRow['end_date'];
            }
        }
    }
    $sql .= " ORDER BY purchase_date DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        if (isset($row['category']) && $row['category'] === 'WHOLESALE') {
            $row['category'] = 'Kitchen';
        }
    }
    return $rows;
}

function monthly_gas_expenses(PDO $pdo, int $year, int $month): float
{
    $start = sprintf('%04d-%02d-01', $year, $month);
    $end = date('Y-m-t', strtotime($start));
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM school_expenses WHERE category = 'Gas Refill' AND expense_date BETWEEN :start AND :end");
    $stmt->execute(['start' => $start, 'end' => $end]);
    return (float) $stmt->fetchColumn();
}

function yearly_gas_expenses(PDO $pdo, int $year): float
{
    $start = sprintf('%04d-01-01', $year);
    $end = sprintf('%04d-12-31', $year);
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM school_expenses WHERE category = 'Gas Refill' AND expense_date BETWEEN :start AND :end");
    $stmt->execute(['start' => $start, 'end' => $end]);
    return (float) $stmt->fetchColumn();
}

function gender_options(): array
{
    return ['Male', 'Female'];
}

function student_type_options(): array
{
    return ['Normal Student', 'Teacher Child'];
}

function teacher_child_student_type(): string
{
    return 'Teacher Child';
}

function is_teacher_child_type(?string $studentType): bool
{
    return $studentType === teacher_child_student_type();
}

function current_academic_year(): string
{
    return date('Y');
}

function feeding_term_amount(): float
{
    return 3000.00;
}

function term_options(): array
{
    return ['Term 1', 'Term 2', 'Term 3'];
}

function paid_status_options(): array
{
    return ['Paid', 'Partial', 'Unpaid'];
}

function fee_payment_status(float $requiredAmount, float $paidAmount, float $balance): string
{
    if ($balance <= 0.005) {
        return 'Paid';
    }

    if ($paidAmount > 0.005) {
        return 'Partial';
    }

    return 'Unpaid';
}

function feeding_status_options(): array
{
    return ['Active', 'Inactive'];
}

function expense_category_options(): array
{
    return ['Kitchen', 'Gas Refill', 'Office', 'Utilities', 'Maintenance', 'Transport', 'Other'];
}

function low_stock_uniforms(PDO $pdo): array
{
    $statement = $pdo->query(
        "SELECT u.*, (u.opening_stock + COALESCE(SUM(usm.quantity),0)) AS available_stock
         FROM uniforms u
         LEFT JOIN uniform_stock_movements usm ON usm.uniform_id = u.id
         GROUP BY u.id
         HAVING available_stock <= u.reorder_level
         ORDER BY available_stock ASC"
    );
    return $statement->fetchAll();
}

function fee_structure_term_columns(): array
{
    return [
        'Term 1' => 'term1_total',
        'Term 2' => 'term2_total',
        'Term 3' => 'term3_total',
    ];
}

function get_fee_structure(PDO $pdo, string $classLevel, string $academicYear): ?array
{
    $statement = $pdo->prepare(
        "SELECT * FROM fee_structures WHERE academic_year = :academic_year AND class_level = :class_level LIMIT 1"
    );
    $statement->execute([
        'academic_year' => $academicYear,
        'class_level' => $classLevel,
    ]);
    $structure = $statement->fetch();
    return $structure ?: null;
}

function fetch_student_fee_discount(PDO $pdo, int $studentId, string $academicYear, string $term): ?array
{
    $statement = $pdo->prepare(
        "SELECT * FROM student_fee_discounts WHERE student_id = :student_id AND academic_year = :academic_year AND term = :term LIMIT 1"
    );
    $statement->execute([
        'student_id' => $studentId,
        'academic_year' => $academicYear,
        'term' => $term,
    ]);
    $row = $statement->fetch();
    return $row ?: null;
}

function save_student_fee_discount(PDO $pdo, int $studentId, string $academicYear, string $term, float $originalFee, float $discountPercentage, float $discountedFee): void
{
    $existing = fetch_student_fee_discount($pdo, $studentId, $academicYear, $term);
    if ($existing) {
        $statement = $pdo->prepare(
            "UPDATE student_fee_discounts
             SET original_fee = :original_fee,
                 discount_percentage = :discount_percentage,
                 discounted_fee = :discounted_fee,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id"
        );
        $statement->execute([
            'original_fee' => $originalFee,
            'discount_percentage' => $discountPercentage,
            'discounted_fee' => $discountedFee,
            'id' => $existing['id'],
        ]);
        return;
    }

    $statement = $pdo->prepare(
        "INSERT INTO student_fee_discounts (student_id, academic_year, term, original_fee, discount_percentage, discounted_fee)
         VALUES (:student_id, :academic_year, :term, :original_fee, :discount_percentage, :discounted_fee)"
    );
    $statement->execute([
        'student_id' => $studentId,
        'academic_year' => $academicYear,
        'term' => $term,
        'original_fee' => $originalFee,
        'discount_percentage' => $discountPercentage,
        'discounted_fee' => $discountedFee,
    ]);
}

function remove_student_fee_discounts(PDO $pdo, int $studentId, string $academicYear): void
{
    $statement = $pdo->prepare(
        "DELETE FROM student_fee_discounts WHERE student_id = :student_id AND academic_year = :academic_year"
    );
    $statement->execute([
        'student_id' => $studentId,
        'academic_year' => $academicYear,
    ]);
}

function default_discounted_fee(float $originalFee, float $discountPercentage): float
{
    if ($discountPercentage < 0) {
        $discountPercentage = 0;
    }
    if ($discountPercentage > 100) {
        $discountPercentage = 100;
    }
    return round($originalFee * (100 - $discountPercentage) / 100, 2);
}

function fee_required_amount_for_term(array $structure, string $term): float
{
    $columns = fee_structure_term_columns();

    if (!isset($columns[$term])) {
        throw new InvalidArgumentException('Invalid academic term.');
    }

    return (float) $structure[$columns[$term]];
}

function default_academic_calendar_rows(string $academicYear): array
{
    return [
        [
            'academic_year' => $academicYear,
            'term_name' => 'Term 1',
            'start_date' => "{$academicYear}-01-01",
            'end_date' => "{$academicYear}-04-30",
        ],
        [
            'academic_year' => $academicYear,
            'term_name' => 'Term 2',
            'start_date' => "{$academicYear}-05-01",
            'end_date' => "{$academicYear}-08-31",
        ],
        [
            'academic_year' => $academicYear,
            'term_name' => 'Term 3',
            'start_date' => "{$academicYear}-09-01",
            'end_date' => "{$academicYear}-12-31",
        ],
    ];
}

function ensure_academic_calendar_for_year(PDO $pdo, string $academicYear): void
{
    if (!preg_match('/^\d{4}$/', $academicYear)) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS academic_calendar (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            academic_year VARCHAR(4) NOT NULL,
            term_name VARCHAR(20) NOT NULL,
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_academic_calendar_year_term (academic_year, term_name)
        ) ENGINE=InnoDB"
    );

    $insert = $pdo->prepare(
        "INSERT IGNORE INTO academic_calendar (academic_year, term_name, start_date, end_date)
         VALUES (:academic_year, :term_name, :start_date, :end_date)"
    );

    $existingRows = $pdo->prepare(
        "SELECT COUNT(*)
         FROM academic_calendar
         WHERE academic_year = :academic_year"
    );
    $existingRows->execute(['academic_year' => $academicYear]);
    if ((int) $existingRows->fetchColumn() > 0) {
        return;
    }

    foreach (default_academic_calendar_rows($academicYear) as $row) {
        $insert->execute($row);
    }
}

function ensure_academic_settings_table(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS academic_settings (
            setting_key VARCHAR(60) PRIMARY KEY,
            setting_value VARCHAR(100) NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB"
    );
}

function academic_setting_value(PDO $pdo, string $key): ?string
{
    ensure_academic_settings_table($pdo);

    $statement = $pdo->prepare(
        "SELECT setting_value
         FROM academic_settings
         WHERE setting_key = :setting_key
         LIMIT 1"
    );
    $statement->execute(['setting_key' => $key]);
    $value = $statement->fetchColumn();

    return $value === false ? null : (string) $value;
}

function save_academic_setting(PDO $pdo, string $key, string $value): void
{
    ensure_academic_settings_table($pdo);

    $statement = $pdo->prepare(
        "INSERT INTO academic_settings (setting_key, setting_value)
         VALUES (:setting_key, :setting_value)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    );
    $statement->execute([
        'setting_key' => $key,
        'setting_value' => $value,
    ]);
}

function set_current_academic_context(PDO $pdo, string $academicYear, string $term): void
{
    if (!preg_match('/^\d{4}$/', $academicYear)) {
        throw new InvalidArgumentException('Academic year must be four digits.');
    }
    if (!in_array($term, term_options(), true)) {
        throw new InvalidArgumentException('Choose a valid term.');
    }

    ensure_academic_calendar_for_year($pdo, $academicYear);
    $termExists = $pdo->prepare(
        "SELECT COUNT(*)
         FROM academic_calendar
         WHERE academic_year = :academic_year
           AND term_name = :term"
    );
    $termExists->execute([
        'academic_year' => $academicYear,
        'term' => $term,
    ]);
    if ((int) $termExists->fetchColumn() === 0) {
        throw new InvalidArgumentException("Add {$academicYear} {$term} to the academic calendar before activating it.");
    }

    save_academic_setting($pdo, 'active_academic_year', $academicYear);
    save_academic_setting($pdo, 'active_term', $term);
}

function active_academic_context_from_settings(PDO $pdo, string $today): ?array
{
    $academicYear = academic_setting_value($pdo, 'active_academic_year');
    $term = academic_setting_value($pdo, 'active_term');

    if (!$academicYear || !$term || !preg_match('/^\d{4}$/', $academicYear) || !in_array($term, term_options(), true)) {
        return null;
    }

    ensure_academic_calendar_for_year($pdo, $academicYear);
    $statement = $pdo->prepare(
        "SELECT start_date, end_date
         FROM academic_calendar
         WHERE academic_year = :academic_year
           AND term_name = :term
         LIMIT 1"
    );
    $statement->execute([
        'academic_year' => $academicYear,
        'term' => $term,
    ]);
    $calendar = $statement->fetch() ?: [];

    return [
        'academic_year' => $academicYear,
        'term' => $term,
        'start_date' => $calendar['start_date'] ?? null,
        'end_date' => $calendar['end_date'] ?? null,
        'today' => $today,
    ];
}

function fallback_term_for_date(string $date): string
{
    $month = (int) date('n', strtotime($date));

    if ($month >= 1 && $month <= 4) {
        return 'Term 1';
    }
    if ($month >= 5 && $month <= 8) {
        return 'Term 2';
    }

    return 'Term 3';
}

function valid_date_value(string $value): bool
{
    $date = DateTime::createFromFormat('Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

function valid_money_value(string $value): bool
{
    return preg_match('/^\d+(\.\d{1,2})?$/', trim($value)) === 1;
}

function valid_quantity_value(string $value): bool
{
    return preg_match('/^\d+(\.\d+)?$/', trim($value)) === 1;
}

function current_academic_context(PDO $pdo, ?string $date = null): array
{
    $today = $date ?: date('Y-m-d');
    $academicYear = date('Y', strtotime($today));
    ensure_academic_calendar_for_year($pdo, $academicYear);

    $activeContext = active_academic_context_from_settings($pdo, $today);
    if ($activeContext) {
        return $activeContext;
    }

    $statement = $pdo->prepare(
        "SELECT academic_year, term_name, start_date, end_date
         FROM academic_calendar
         WHERE :today BETWEEN start_date AND end_date
         ORDER BY start_date DESC, id DESC
         LIMIT 1"
    );
    $statement->execute(['today' => $today]);
    $calendar = $statement->fetch();

    if (!$calendar) {
        $calendar = [
            'academic_year' => $academicYear,
            'term_name' => fallback_term_for_date($today),
            'start_date' => null,
            'end_date' => null,
        ];
    }

    return [
        'academic_year' => (string) $calendar['academic_year'],
        'term' => (string) $calendar['term_name'],
        'start_date' => $calendar['start_date'],
        'end_date' => $calendar['end_date'],
        'today' => $today,
    ];
}

function permission_definitions(): array
{
    return rbac_permission_definitions();
}

function permission_template_definitions(): array
{
    $templates = [];
    foreach (rbac_role_template_definitions() as $templateName => $template) {
        $templates[$templateName] = rbac_permission_template_keys($templateName);
    }
    return $templates;
}

function get_all_permission_keys(): array
{
    return rbac_all_permission_keys(true);
}

function ensure_permissions_exist(PDO $pdo): void
{
    rbac_ensure_schema($pdo);
}

function get_permission_ids(PDO $pdo, array $permissionKeys): array
{
    return rbac_get_permission_ids($pdo, $permissionKeys);
}

function find_or_create_role(PDO $pdo, string $name, string $description = ''): int
{
    return rbac_find_or_create_role($pdo, $name, $description);
}

function set_role_permissions(PDO $pdo, int $roleId, array $permissionKeys): void
{
    rbac_set_role_permissions($pdo, $roleId, $permissionKeys);
}

function set_user_permissions(PDO $pdo, int $userId, array $permissionKeys): void
{
    rbac_set_user_overrides($pdo, $userId, $permissionKeys);
}

function set_user_role(PDO $pdo, int $userId, int $roleId): void
{
    $pdo->prepare("DELETE FROM user_roles WHERE user_id = :user_id")->execute(['user_id' => $userId]);
    $statement = $pdo->prepare("INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)");
    $statement->execute(['user_id' => $userId, 'role_id' => $roleId]);
}

function get_user_role_names(PDO $pdo, int $userId): array
{
    ensure_permissions_exist($pdo);
    $statement = $pdo->prepare(
        "SELECT COALESCE(roles.role_name, roles.name) AS name
         FROM roles
         JOIN user_roles ON user_roles.role_id = roles.id
         WHERE user_roles.user_id = :user_id"
    );
    $statement->execute(['user_id' => $userId]);
    return array_column($statement->fetchAll(), 'name');
}

function get_user_permission_keys(PDO $pdo, int $userId): array
{
    return rbac_user_permission_keys($pdo, $userId);
}

function user_has_permission(PDO $pdo, int $userId, string $permissionKey): bool
{
    return rbac_user_has_permission($pdo, $userId, $permissionKey);
}

function get_role_permission_keys(PDO $pdo, int $roleId): array
{
    return rbac_role_permission_keys($pdo, $roleId);
}

function get_role_permissions_by_id(PDO $pdo, int $roleId): array
{
    ensure_permissions_exist($pdo);
    $statement = $pdo->prepare(
        "SELECT p.id, p.permission_key, p.module_name, p.permission_name
         FROM permissions p
         JOIN role_permissions rp ON rp.permission_id = p.id
         WHERE rp.role_id = :role_id
         ORDER BY p.permission_key ASC"
    );
    $statement->execute(['role_id' => $roleId]);

    return $statement->fetchAll();
}

function find_permission_template_keys(string $templateName): array
{
    return rbac_permission_template_keys($templateName);
}

function ensure_role_templates_exist(PDO $pdo): void
{
    // Keep permission records available without recreating roles an admin has deleted.
    ensure_permissions_exist($pdo);
}

function get_stored_role_templates(PDO $pdo): array
{
    ensure_permissions_exist($pdo);
    $statement = $pdo->query("SELECT id, COALESCE(role_name, name) AS name, description FROM roles ORDER BY COALESCE(role_name, name) ASC");
    return $statement->fetchAll();
}

function get_role_by_name(PDO $pdo, string $name): ?array
{
    ensure_permissions_exist($pdo);
    $statement = $pdo->prepare("SELECT id, COALESCE(role_name, name) AS name, description FROM roles WHERE role_name = :name OR name = :name LIMIT 1");
    $statement->execute(['name' => $name]);
    $row = $statement->fetch();
    return $row ?: null;
}

function get_user_by_id(PDO $pdo, int $userId): ?array
{
    $statement = $pdo->prepare("SELECT id, name, username, status, class_level FROM admin WHERE id = :user_id LIMIT 1");
    $statement->execute(['user_id' => $userId]);
    return $statement->fetch() ?: null;
}

function get_admin_users(PDO $pdo): array
{
    ensure_permissions_exist($pdo);
    // Preload a primary role name for each user (if assigned) to make listing efficient
    $sql = "SELECT a.id, a.name, a.username, a.status, a.class_level,
                   (SELECT COALESCE(r.role_name, r.name) FROM user_roles ur JOIN roles r ON ur.role_id = r.id WHERE ur.user_id = a.id LIMIT 1) AS role_name
            FROM admin a
            ORDER BY a.name ASC";
    $statement = $pdo->query($sql);
    return $statement->fetchAll();
}

function get_user_role_template_name(PDO $pdo, int $userId): string
{
    $roleNames = get_user_role_names($pdo, $userId);
    if (!empty($roleNames)) {
        return $roleNames[0];
    }

    // If no explicit role assigned, try to infer from permission templates
    $userPermissions = get_user_permission_keys($pdo, $userId);
    if (!empty($userPermissions)) {
        $templates = permission_template_definitions();
        foreach ($templates as $templateName => $permKeys) {
            // Compare sets
            $permKeysSorted = array_values(array_unique($permKeys));
            sort($permKeysSorted);
            $userPermsSorted = $userPermissions;
            sort($userPermsSorted);
            if ($permKeysSorted === $userPermsSorted) {
                return $templateName;
            }
        }
    }

    return 'Unassigned';
}

function get_user_class_level(PDO $pdo, int $userId): ?string
{
    $user = get_user_by_id($pdo, $userId);
    return $user['class_level'] ?? null;
}

function current_admin_class_level(PDO $pdo): ?string
{
    return get_user_class_level($pdo, (int) ($_SESSION['admin_id'] ?? 0));
}

function get_active_user_status(PDO $pdo, int $userId): ?string
{
    $statement = $pdo->prepare("SELECT status FROM admin WHERE id = :user_id LIMIT 1");
    $statement->execute(['user_id' => $userId]);
    $row = $statement->fetch();
    return $row['status'] ?? null;
}

function user_is_active(PDO $pdo, int $userId): bool
{
    $status = get_active_user_status($pdo, $userId);
    return $status === 'Active';
}

function current_admin_has_permission(PDO $pdo, string $permissionKey): bool
{
    return rbac_current_admin_has_permission($pdo, $permissionKey);
}

function get_permission_label_by_key(string $permissionKey): string
{
    return rbac_permission_label($permissionKey);
}

function sanitize_user_permissions(array $permissionKeys): array
{
    $available = get_all_permission_keys();
    $normalized = [];
    foreach ($permissionKeys as $permissionKey) {
        $key = rbac_normalize_permission_key((string) $permissionKey);
        if ($key !== null) {
            $normalized[] = $key;
        }
    }
    return array_values(array_intersect($available, array_unique($normalized)));
}

function save_admin_user(PDO $pdo, array $data): int
{
    

    if (!empty($data['id'])) {
        $sql = "UPDATE admin SET name = :name, username = :username, status = :status";
        $params = [
            'name' => $data['name'],
            'username' => $data['username'],
            'status' => $data['status'],
            'id' => $data['id'],
        ];
        if (!empty($data['password_hash'])) {
            $sql .= ", password_hash = :password_hash";
            $params['password_hash'] = $data['password_hash'];
        }
        if (array_key_exists('class_level', $data)) {
            $sql .= ", class_level = :class_level";
            $params['class_level'] = $data['class_level'] !== '' ? $data['class_level'] : null;
        }
        $sql .= " WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $data['id'];
    }

    $columns = ['name', 'username', 'password_hash', 'status'];
    $placeholders = [':name', ':username', ':password_hash', ':status'];
    $params = [
        'name' => $data['name'],
        'username' => $data['username'],
        'password_hash' => $data['password_hash'],
        'status' => $data['status'],
    ];
    if (array_key_exists('class_level', $data)) {
        $columns[] = 'class_level';
        $placeholders[] = ':class_level';
        $params['class_level'] = $data['class_level'] !== '' ? $data['class_level'] : null;
    }

    $stmt = $pdo->prepare("INSERT INTO admin (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")");
    $stmt->execute($params);
    return (int) $pdo->lastInsertId();
}

function delete_admin_user(PDO $pdo, int $userId): void
{
    ensure_permissions_exist($pdo);
    $pdo->prepare('DELETE FROM user_roles WHERE user_id = :user_id')->execute(['user_id' => $userId]);
    $pdo->prepare('DELETE FROM user_permissions WHERE user_id = :user_id')->execute(['user_id' => $userId]);
    $pdo->prepare('DELETE FROM user_permission_overrides WHERE user_id = :user_id')->execute(['user_id' => $userId]);
    $pdo->prepare('DELETE FROM user_module_overrides WHERE user_id = :user_id')->execute(['user_id' => $userId]);
    $pdo->prepare('DELETE FROM admin WHERE id = :id')->execute(['id' => $userId]);
}

function get_user_by_username(PDO $pdo, string $username): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM admin WHERE username = :username LIMIT 1");
    $stmt->execute(['username' => $username]);
    return $stmt->fetch() ?: null;
}

function get_user_status_options(): array
{
    return ['Active', 'Inactive'];
}

function get_permission_template_labels(): array
{
    return array_keys(permission_template_definitions());
}

function get_user_roles_for_user(PDO $pdo, int $userId): array
{
    return get_user_role_names($pdo, $userId);
}

function get_permission_template_permissions(PDO $pdo, string $roleName): array
{
    // Prefer stored role permissions (what admin explicitly assigned).
    // Only fall back to built-in template defaults when there is no stored role.
    $role = get_role_by_name($pdo, $roleName);
    if ($role) {
        return get_role_permission_keys($pdo, (int) $role['id']);
    }

    // No stored role found — use template defaults if defined.
    if (array_key_exists($roleName, permission_template_definitions())) {
        return find_permission_template_keys($roleName);
    }

    return [];
}

function get_permission_lookup(): array
{
    $definitions = [];
    foreach (permission_definitions() as $moduleName => $module) {
        $definitions[$module['permission_key']] = $module['label'];
        foreach ($module['actions'] as $actionKey => $label) {
            $definitions[$actionKey] = $label;
        }
    }
    return $definitions;
}

function get_permission_options(): array
{
    return permission_definitions();
}

function get_permission_template_dropdown(PDO $pdo): array
{
    ensure_permissions_exist($pdo);
    $storedRoles = array_column(get_stored_role_templates($pdo), 'name');

    return $storedRoles;
}

function get_permission_module_action_labels(string $moduleName): array
{
    return permission_definitions()[$moduleName]['actions'] ?? [];
}

function get_permission_module_access_keys(): array
{
    $keys = [];
    foreach (permission_definitions() as $module) {
        $keys[] = $module['permission_key'];
    }
    return $keys;
}

function get_permission_action_names(): array
{
    $actions = [];
    foreach (permission_definitions() as $module) {
        foreach ($module['actions'] as $actionKey => $label) {
            $actions[$actionKey] = $label;
        }
    }
    return $actions;
}

function get_permission_template_keys(string $templateName): array
{
    return find_permission_template_keys($templateName);
}

function get_permission_management_link(): string
{
    return 'admin/users_permissions.php';
}

function get_permission_management_label(): string
{
    return 'Users & Permissions';
}

function get_permission_user_management_description(): string
{
    return 'Manage administrator users, role templates, and custom permissions.';
}

function get_permission_settings_access(PDO $pdo): bool
{
    return current_admin_has_permission($pdo, 'settings.view');
}

function get_user_permissions_access(PDO $pdo): bool
{
    return current_admin_has_permission($pdo, 'users_permissions.access');
}

function get_permission_template_choices(PDO $pdo): array
{
    return get_permission_template_dropdown($pdo);
}

function current_admin_permission_keys(PDO $pdo): array
{
    return empty($_SESSION['admin_id']) ? [] : get_user_permission_keys($pdo, (int) $_SESSION['admin_id']);
}

function current_admin_is_active(PDO $pdo): bool
{
    return !empty($_SESSION['admin_id']) && user_is_active($pdo, (int) $_SESSION['admin_id']);
}

function current_admin_roles(PDO $pdo): array
{
    return empty($_SESSION['admin_id']) ? [] : get_user_role_names($pdo, (int) $_SESSION['admin_id']);
}

function get_permission_role_by_user(PDO $pdo, int $userId): string
{
    return get_user_role_template_name($pdo, $userId);
}

function current_admin_displays_all_permissions(PDO $pdo): bool
{
    return get_user_permission_keys($pdo, (int) ($_SESSION['admin_id'] ?? 0)) === get_all_permission_keys();
}

function get_user_role_assignments(PDO $pdo, int $userId): array
{
    return get_user_role_names($pdo, $userId);
}

function clear_user_role_assignments(PDO $pdo, int $userId): void
{
    $pdo->prepare("DELETE FROM user_roles WHERE user_id = :user_id")->execute(['user_id' => $userId]);
}

function clear_custom_user_permissions(PDO $pdo, int $userId): void
{
    ensure_permissions_exist($pdo);
    $pdo->prepare("DELETE FROM user_permissions WHERE user_id = :user_id")->execute(['user_id' => $userId]);
    $pdo->prepare("DELETE FROM user_permission_overrides WHERE user_id = :user_id")->execute(['user_id' => $userId]);
    $pdo->prepare("DELETE FROM user_module_overrides WHERE user_id = :user_id")->execute(['user_id' => $userId]);
}

function assign_user_role_by_name(PDO $pdo, int $userId, string $roleName): void
{
    ensure_permissions_exist($pdo);
    $roleId = find_or_create_role($pdo, $roleName, ucfirst($roleName) . ' role template');
    set_user_role($pdo, $userId, $roleId);
}

function get_permission_template_details(PDO $pdo, string $roleName): array
{
    return get_permission_template_permissions($pdo, $roleName);
}

function get_permission_template_options(PDO $pdo): array
{
    return get_permission_template_dropdown($pdo);
}

function get_permission_template_label(string $templateName): string
{
    return $templateName;
}

function get_permission_lookup_map(): array
{
    return get_permission_lookup();
}

function get_permission_module_permissions_for_template(string $templateName): array
{
    return get_permission_template_permissions($pdo, $templateName);
}

function permission_label(string $permissionKey): string
{
    return get_permission_label_by_key($permissionKey);
}

function get_user_permission_names(PDO $pdo, int $userId): array
{
    return get_user_permission_keys($pdo, $userId);
}

function role_exists(PDO $pdo, string $roleName): bool
{
    return (bool) get_role_by_name($pdo, $roleName);
}

function get_permission_page_title(): string
{
    return 'Users & Permissions';
}

function get_permission_page_description(): string
{
    return 'Create admin users, assign a role template, or configure custom access.';
}

function get_permission_help_text(): string
{
    return 'Use role templates or custom permissions to control module visibility and actions.';
}

function get_user_permission_summary(PDO $pdo, int $userId): string
{
    return implode(', ', get_user_permission_keys($pdo, $userId));
}

function get_user_status_options_list(): array
{
    return get_user_status_options();
}

function get_permission_template_name_list(PDO $pdo): array
{
    return get_permission_template_dropdown($pdo);
}

function get_permission_template_label_list(): array
{
    return get_permission_template_labels();
}

function get_permission_templates_for_form(PDO $pdo): array
{
    return get_permission_template_dropdown($pdo);
}

function get_permission_user_template(PDO $pdo, int $userId): string
{
    return get_user_role_template_name($pdo, $userId);
}

function get_permission_user_status(PDO $pdo, int $userId): string
{
    return get_active_user_status($pdo, $userId) ?? 'Active';
}

function get_user_roles_dropdown(PDO $pdo): array
{
    return get_permission_template_dropdown($pdo);
}

function get_user_role_template_list(PDO $pdo): array
{
    return get_permission_template_dropdown($pdo);
}

function get_user_roles_for_select(PDO $pdo): array
{
    return get_permission_template_dropdown($pdo);
}

function get_permission_template_select_options(PDO $pdo): array
{
    return get_permission_template_dropdown($pdo);
}

function get_permission_template_select_labels(PDO $pdo): array
{
    return get_permission_template_dropdown($pdo);
}

function get_permission_template_names(): array
{
    return get_permission_template_labels();
}

function get_user_permission_template_names(PDO $pdo): array
{
    return get_permission_template_dropdown($pdo);
}

function get_permission_action_template_labels(): array
{
    return get_permission_action_names();
}

function get_permission_module_template_labels(): array
{
    return get_permission_access_labels();
}

function get_permission_template_help(): string
{
    return 'Role templates automatically assign module and action permissions.';
}

function get_permission_template_role_help(): string
{
    return 'Select a role template to assign common permissions quickly.';
}

function get_permission_custom_help(): string
{
    return 'Choose Custom to assign permissions per module and action.';
}

function get_permission_user_help(): string
{
    return 'Administrators may be active or inactive, and can be assigned roles or custom permissions.';
}

function get_permission_status_help(): string
{
    return 'Inactive users cannot log in until reactivated.';
}

function get_permission_section_help_text(): string
{
    return 'Permissions are split into module visibility plus action-level access.';
}

function get_permission_page_help_text(): string
{
    return 'Use this page to manage users, permission templates, and module access.';
}

function get_permission_section_title_text(): string
{
    return 'Permission Sections';
}

function get_permission_template_title_text(): string
{
    return 'Role Templates';
}

function get_permission_user_title_text(): string
{
    return 'Admin Users';
}

function get_permission_status_title_text(): string
{
    return 'User Status';
}

function get_permission_role_title_text(): string
{
    return 'Assigned Role';
}

function get_permission_custom_title_text(): string
{
    return 'Custom Permission Set';
}

function get_permission_module_title_text(): string
{
    return 'Module Access';
}

function get_permission_action_title_text(): string
{
    return 'Action Access';
}

function get_permission_template_section_title_text(): string
{
    return 'Role Template Selection';
}

function get_permission_user_section_title_text(): string
{
    return 'User Details';
}

function get_permission_template_section_description(): string
{
    return 'Choose a template or save a custom set as a template.';
}

function get_permission_section_summary(): string
{
    return 'Permission sections control module access and actions.';
}

function get_permission_action_summary(): string
{
    return 'Action permissions are assigned within each visible module.';
}

function get_permission_template_summary(): string
{
    return 'Built-in role templates make common assignments simple.';
}

function get_permission_user_summary_text(): string
{
    return 'Users are administrators with role assignments and custom permissions.';
}

function get_permission_status_summary(): string
{
    return 'Active users may log in; inactive users are blocked.';
}

function get_permission_configuration_summary(): string
{
    return 'Permissions are stored in tables and evaluated at runtime.';
}

function get_permission_system_summary_text(): string
{
    return 'Permissions apply to sidebar visibility and page actions.';
}

function get_permission_template_system_summary(): string
{
    return 'Templates are reusable permission sets.';
}

function get_permission_user_system_summary(): string
{
    return 'Users are assigned templates or custom permissions.';
}

function get_permission_module_system_summary(): string
{
    return 'Modules are shown based on access permissions.';
}

function get_permission_action_system_summary(): string
{
    return 'Actions are enabled only when permitted.';
}

function current_admin_can_access_module(PDO $pdo, string $moduleKeyOrName): bool
{
    return rbac_current_admin_can_access_module($pdo, $moduleKeyOrName);
}

function current_admin_module_permissions(PDO $pdo): array
{
    return current_admin_permission_keys($pdo);
}

function current_admin_permission_map(PDO $pdo): array
{
    return get_permission_lookup();
}

function current_admin_role_permissions(PDO $pdo): array
{
    return get_user_permission_keys($pdo, (int) ($_SESSION['admin_id'] ?? 0));
}

function current_admin_permission_role_names(PDO $pdo): array
{
    return current_admin_roles($pdo);
}

function current_admin_permission_template_name(PDO $pdo): string
{
    return get_user_role_template_name($pdo, (int) ($_SESSION['admin_id'] ?? 0));
}

function current_admin_permission_status(PDO $pdo): string
{
    return current_admin_is_active($pdo) ? 'Active' : 'Inactive';
}

function get_permission_action_label(string $permissionKey): string
{
    return get_permission_label_by_key($permissionKey);
}

function get_permission_module_list_for_display(): array
{
    return permission_definitions();
}

function get_permission_action_list_for_display_row(): array
{
    return get_permission_action_names();
}

function get_permission_template_list_for_display_row(PDO $pdo): array
{
    return get_permission_template_labels();
}

function get_permission_user_list_for_display_row(PDO $pdo): array
{
    return get_admin_users($pdo);
}

function get_permission_user_permissions_details(PDO $pdo, int $userId): array
{
    return get_user_permission_keys($pdo, $userId);
}

function get_permission_user_roles_details(PDO $pdo, int $userId): array
{
    return get_user_role_names($pdo, $userId);
}

function get_permission_user_status_details(PDO $pdo, int $userId): string
{
    return get_active_user_status($pdo, $userId) ?? 'Active';
}

function get_permission_user_info_details(PDO $pdo, int $userId): array
{
    return get_user_by_id($pdo, $userId) ?: [];
}

function get_permission_templates_for_user(PDO $pdo, int $userId): array
{
    return get_permission_template_labels();
}

function get_permission_actions_for_user(PDO $pdo, int $userId): array
{
    return get_user_permission_keys($pdo, $userId);
}

function get_permission_modules_for_user(PDO $pdo, int $userId): array
{
    return get_user_permission_keys($pdo, $userId);
}

function get_permission_user_template_for_user(PDO $pdo, int $userId): string
{
    return get_user_role_template_name($pdo, $userId);
}

function get_permission_user_status_for_user(PDO $pdo, int $userId): string
{
    return get_active_user_status($pdo, $userId) ?? 'Active';
}

function get_permission_user_name_for_user(PDO $pdo, int $userId): string
{
    return get_user_by_id($pdo, $userId)['name'] ?? '';
}

function get_permission_user_username_for_user(PDO $pdo, int $userId): string
{
    return get_user_by_id($pdo, $userId)['username'] ?? '';
}

function get_permission_user_role_template_for_user(PDO $pdo, int $userId): string
{
    return get_user_role_template_name($pdo, $userId);
}

function get_permission_user_permission_list_for_user(PDO $pdo, int $userId): array
{
    return get_user_permission_keys($pdo, $userId);
}

function get_permission_user_role_list_for_user(PDO $pdo, int $userId): array
{
    return get_user_role_names($pdo, $userId);
}

function get_permission_user_status_list_for_user(PDO $pdo, int $userId): string
{
    return get_active_user_status($pdo, $userId) ?? 'Active';
}

function get_permission_user_info_list_for_user(PDO $pdo, int $userId): array
{
    return get_user_by_id($pdo, $userId) ?: [];
}

function get_permission_user_management_overview_for_user(PDO $pdo, int $userId): array
{
    return get_user_permission_details($pdo, $userId);
}

function get_permission_settings_access_for_user(PDO $pdo, int $userId): bool
{
    return user_has_permission($pdo, $userId, 'settings.view');
}

function get_permission_user_management_access_for_user(PDO $pdo, int $userId): bool
{
    return user_has_permission($pdo, $userId, 'users_permissions.access');
}

function sync_fee_balance_for_student(PDO $pdo, int $studentId, string $classLevel, string $academicYear, string $term): void
{
    // Get the fee structure for the class level and academic year
    $feeStructure = get_fee_structure($pdo, $classLevel, $academicYear);
    if (!$feeStructure) {
        return; // No fee structure found, skip
    }

    // Get the required fee for the term
    $requiredFee = fee_required_amount_for_term($feeStructure, $term);

    // Check if fee_balance exists for this student/year/term
    $checkStmt = $pdo->prepare(
        "SELECT id, required_amount, paid_amount FROM fee_balances 
         WHERE student_id = :student_id AND academic_year = :academic_year AND term = :term LIMIT 1"
    );
    $checkStmt->execute([
        'student_id' => $studentId,
        'academic_year' => $academicYear,
        'term' => $term,
    ]);
    $existingBalance = $checkStmt->fetch();

    if ($existingBalance) {
        // Update existing fee balance
        $balance = max($requiredFee - (float) $existingBalance['paid_amount'], 0.0);
        $updateStmt = $pdo->prepare(
            "UPDATE fee_balances 
             SET required_amount = :required_fee, balance = :balance
             WHERE id = :id"
        );
        $updateStmt->execute([
            'required_fee' => $requiredFee,
            'balance' => $balance,
            'id' => $existingBalance['id'],
        ]);
    } else {
        // Create new fee balance
        $insertStmt = $pdo->prepare(
            "INSERT INTO fee_balances (student_id, academic_year, term, required_amount, paid_amount, balance)
             VALUES (:student_id, :academic_year, :term, :required_fee, 0.00, :balance)"
        );
        $insertStmt->execute([
            'student_id' => $studentId,
            'academic_year' => $academicYear,
            'term' => $term,
            'required_fee' => $requiredFee,
            'balance' => $requiredFee,
        ]);
    }
}

function get_fee_balance(PDO $pdo, int $studentId, string $academicYear, string $term, bool $createIfMissing = false): ?array
{
    $stmt = $pdo->prepare(
        "SELECT * FROM fee_balances WHERE student_id = :student_id AND academic_year = :academic_year AND term = :term LIMIT 1"
    );
    $stmt->execute([
        'student_id' => $studentId,
        'academic_year' => $academicYear,
        'term' => $term,
    ]);
    $row = $stmt->fetch();
    if ($row) {
        return $row;
    }

    if (!$createIfMissing) {
        return null;
    }

    // Try to create a fee balance for the student using their class level and fee structure
    $s = $pdo->prepare("SELECT class_level FROM students WHERE id = :id LIMIT 1");
    $s->execute(['id' => $studentId]);
    $student = $s->fetch();
    if (!$student || !isset($student['class_level'])) {
        return null;
    }

    $feeStructure = get_fee_structure($pdo, $student['class_level'], $academicYear);
    if (!$feeStructure) {
        return null;
    }

    $requiredFee = fee_required_amount_for_term($feeStructure, $term);

    $ins = $pdo->prepare(
        "INSERT INTO fee_balances (student_id, academic_year, term, required_amount, paid_amount, balance) VALUES (:student_id, :academic_year, :term, :required_amount, 0.00, :balance)"
    );
    $ins->execute([
        'student_id' => $studentId,
        'academic_year' => $academicYear,
        'term' => $term,
        'required_amount' => $requiredFee,
        'balance' => $requiredFee,
    ]);

    $stmt->execute([
        'student_id' => $studentId,
        'academic_year' => $academicYear,
        'term' => $term,
    ]);
    return $stmt->fetch() ?: null;
}

function sync_fee_balances_for_class_year(PDO $pdo, string $classLevel, string $academicYear, string $term): int
{
    $feeStructure = get_fee_structure($pdo, $classLevel, $academicYear);
    if (!$feeStructure) {
        return 0;
    }

    $requiredFee = fee_required_amount_for_term($feeStructure, $term);
    $studentsStmt = $pdo->prepare("SELECT id FROM students WHERE class_level = :class_level");
    $studentsStmt->execute(['class_level' => $classLevel]);
    $students = $studentsStmt->fetchAll();

    $synced = 0;
    foreach ($students as $student) {
        $studentId = (int) $student['id'];
        $checkStmt = $pdo->prepare(
            "SELECT id, paid_amount
             FROM fee_balances
             WHERE student_id = :student_id
               AND academic_year = :academic_year
               AND term = :term
             LIMIT 1"
        );
        $checkStmt->execute([
            'student_id' => $studentId,
            'academic_year' => $academicYear,
            'term' => $term,
        ]);

        $existingBalance = $checkStmt->fetch();
        $paidAmount = $existingBalance ? (float) $existingBalance['paid_amount'] : 0.0;
        $balance = max($requiredFee - $paidAmount, 0.0);

        if ($existingBalance) {
            $updateStmt = $pdo->prepare(
                "UPDATE fee_balances
                 SET required_amount = :required_fee,
                     balance = :balance
                 WHERE id = :id"
            );
            $updateStmt->execute([
                'required_fee' => $requiredFee,
                'balance' => $balance,
                'id' => $existingBalance['id'],
            ]);
        } else {
            $insertStmt = $pdo->prepare(
                "INSERT INTO fee_balances (student_id, academic_year, term, required_amount, paid_amount, balance)
                 VALUES (:student_id, :academic_year, :term, :required_fee, 0.00, :balance)"
            );
            $insertStmt->execute([
                'student_id' => $studentId,
                'academic_year' => $academicYear,
                'term' => $term,
                'required_fee' => $requiredFee,
                'balance' => $balance,
            ]);
        }

        $synced++;
    }

    return $synced;
}

function sync_current_term_fee_balances(PDO $pdo): void
{
    // Get the current academic context
    $context = current_academic_context($pdo);
    $academicYear = $context['academic_year'] ?? '';
    $term = $context['term'] ?? '';

    if ($academicYear === '' || $term === '') {
        return;
    }

    // Get all class levels
    $classLevels = class_level_options();
    
    // For each class level, sync fee balances with the current term fee structure
    foreach ($classLevels as $classLevel) {
        sync_fee_balances_for_class_year($pdo, $classLevel, $academicYear, $term);
    }
}

function fetch_fee_term_report(PDO $pdo, array $filters): array
{
    $year = $filters['year'] ?? '';
    $term = $filters['term'] ?? '';
    $params = ['year' => $year, 'term' => $term];

    $sql = "SELECT s.id AS student_id, s.registration_no, s.full_name, s.gender, s.class_level,
                   fb.id AS fee_balance_id, fb.required_amount AS fb_required, fb.paid_amount AS fb_paid, fb.balance AS fb_balance,
                   fs.term1_total, fs.term2_total, fs.term3_total,
                   (SELECT COALESCE(SUM(amount_paid),0) FROM fees WHERE student_id = s.id AND year = :year AND term = :term) AS fees_paid,
                   (SELECT payment_date FROM fees WHERE student_id = s.id AND year = :year AND term = :term ORDER BY payment_date DESC LIMIT 1) AS last_payment_date,
                   fs.id IS NOT NULL AS has_fee_structure
            FROM students s
            LEFT JOIN fee_balances fb ON fb.student_id = s.id AND fb.academic_year = :year AND fb.term = :term
            LEFT JOIN fee_structures fs ON fs.class_level = s.class_level AND fs.academic_year = :year";

    // Apply optional filters
    $where = [];
    if (!empty($filters['class_level'])) {
        $where[] = 's.class_level = :class_level';
        $params['class_level'] = $filters['class_level'];
    }
    if (!empty($filters['gender'])) {
        $where[] = "s.gender = :gender";
        $params['gender'] = $filters['gender'];
    }
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY s.full_name ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $out = [];
    foreach ($rows as $r) {
        $structure = [
            'term1_total' => $r['term1_total'] ?? 0,
            'term2_total' => $r['term2_total'] ?? 0,
            'term3_total' => $r['term3_total'] ?? 0,
        ];

        try {
            $required = $r['fb_required'] !== null ? (float) $r['fb_required'] : (isset($r['term1_total']) ? fee_required_amount_for_term($structure, $term) : 0.0);
        } catch (Throwable $ex) {
            $required = 0.0;
        }

        $paid = $r['fb_paid'] !== null ? (float) $r['fb_paid'] : (float) $r['fees_paid'];
        $balance = max($required - $paid, 0.0);
        $hasFeeStructure = (bool) ($r['has_fee_structure'] ?? false);

        if (!$hasFeeStructure) {
            if ($balance <= 0.005) {
                $status = 'Paid';
            } elseif ($paid > 0.005) {
                $status = 'Partial';
            } else {
                $status = 'Unpaid';
            }
        } else {
            $status = fee_payment_status($required, $paid, $balance);
        }

        $out[] = [
            'student_id' => (int) $r['student_id'],
            'registration_no' => $r['registration_no'] ?? '',
            'full_name' => $r['full_name'] ?? '',
            'gender' => $r['gender'] ?? '',
            'class_level' => $r['class_level'] ?? '',
            'academic_year' => $year,
            'term' => $term,
            'required_amount' => $required,
            'paid_amount' => $paid,
            'balance' => $balance,
            'status' => $status,
            'last_payment_date' => $r['last_payment_date'] ?? null,
        ];
    }

    return $out;
}

function get_permission_user_status_display(string $status): string
{
    return $status;
}

function get_permission_user_template_display(string $templateName): string
{
    return $templateName;
}

function get_permission_user_permissions_display(array $permissionKeys): string
{
    return implode(', ', $permissionKeys);
}

function get_permission_template_users(PDO $pdo): array
{
    return get_admin_users($pdo);
}

function get_permission_user_permissions_keys(PDO $pdo, int $userId): array
{
    return get_user_permission_keys($pdo, $userId);
}

function get_permission_template_keys_for_current_admin(PDO $pdo): array
{
    return current_admin_permission_keys($pdo);
}

function get_permission_user_template_keys(PDO $pdo, int $userId): array
{
    return get_user_permission_keys($pdo, $userId);
}

function get_permission_access_keys_for_user(PDO $pdo, int $userId): array
{
    return get_user_permission_keys($pdo, $userId);
}

function get_permission_editable_roles(PDO $pdo): array
{
    return get_permission_template_dropdown($pdo);
}

function get_permission_editable_permissions(PDO $pdo): array
{
    return get_all_permission_keys();
}

function get_permission_editable_permission_labels(PDO $pdo): array
{
    return get_permission_lookup();
}

function get_permission_editable_role_labels(PDO $pdo): array
{
    return get_permission_template_labels();
}

function get_permission_management_page_title(): string
{
    return 'Users & Permissions';
}

function get_permission_management_page_heading(): string
{
    return 'Users & Permissions';
}

function get_permission_management_page_subtitle(): string
{
    return 'Assign roles and control admin access';
}

function get_permission_management_actions(): array
{
    return permission_definitions();
}

function get_permission_management_templates(): array
{
    return permission_template_definitions();
}

function get_permission_management_users(PDO $pdo): array
{
    return get_admin_users($pdo);
}

function get_permission_management_roles(PDO $pdo): array
{
    return get_stored_role_templates($pdo);
}

function get_permission_management_templates_list(PDO $pdo): array
{
    return get_permission_template_dropdown($pdo);
}

function get_permission_management_permissions(PDO $pdo): array
{
    return get_all_permission_keys();
}

function get_permission_management_permission_labels(): array
{
    return get_permission_lookup();
}

function get_permission_management_role_labels(PDO $pdo): array
{
    return get_permission_template_labels();
}

function get_permission_management_user_labels(PDO $pdo): array
{
    return get_permission_lookup();
}

function get_permission_management_page_info(): string
{
    return 'A new module to manage admin users and roles.';
}

function get_permission_management_page_help_text(): string
{
    return 'Use the controls to configure user access quickly.';
}

function get_permission_management_page_summary(): string
{
    return 'Manage admin users, role templates, and custom permissions.';
}

function current_admin_is_permission_manager(PDO $pdo): bool
{
    return current_admin_has_permission($pdo, 'users_permissions.access');
}

function current_admin_has_settings_access(PDO $pdo): bool
{
    return current_admin_has_permission($pdo, 'settings.view');
}

function current_admin_has_users_permissions_access(PDO $pdo): bool
{
    return current_admin_has_permission($pdo, 'users_permissions.access');
}

function current_admin_permission_summary_text(PDO $pdo): string
{
    return implode(', ', current_admin_permission_keys($pdo));
}

function current_admin_role_template_name(PDO $pdo): string
{
    return get_user_role_template_name($pdo, (int) ($_SESSION['admin_id'] ?? 0));
}

function get_permission_user_template_by_user(PDO $pdo, int $userId): string
{
    return get_user_role_template_name($pdo, $userId);
}

function get_permission_user_status_by_user(PDO $pdo, int $userId): string
{
    return get_active_user_status($pdo, $userId) ?? 'Active';
}

function get_permission_user_permissions_by_user(PDO $pdo, int $userId): array
{
    return get_user_permission_keys($pdo, $userId);
}

function get_permission_user_role_names_by_user(PDO $pdo, int $userId): array
{
    return get_user_role_names($pdo, $userId);
}

function permission_settings_enabled(PDO $pdo): bool
{
    return current_admin_has_permission($pdo, 'settings.view');
}

function permission_user_management_enabled(PDO $pdo): bool
{
    return current_admin_has_permission($pdo, 'users_permissions.access');
}

function permission_admin_access_enabled(PDO $pdo): bool
{
    return current_admin_has_permission($pdo, 'users_permissions.access');
}

function permission_settings_access_enabled(PDO $pdo): bool
{
    return current_admin_has_permission($pdo, 'settings.view');
}

function permission_page_enabled(PDO $pdo): bool
{
    return true;
}

function get_permission_active_user_status(PDO $pdo, int $userId): string
{
    return get_active_user_status($pdo, $userId) ?? 'Active';
}

function get_permission_management_active_users(PDO $pdo): array
{
    return get_admin_users($pdo);
}

function get_permission_management_active_roles(PDO $pdo): array
{
    return get_stored_role_templates($pdo);
}

function get_permission_management_active_templates(PDO $pdo): array
{
    return get_permission_template_dropdown($pdo);
}

function get_permission_management_active_permissions(PDO $pdo): array
{
    return get_all_permission_keys();
}

function get_permission_management_active_permission_labels(PDO $pdo): array
{
    return get_permission_lookup();
}

function get_permission_management_active_role_labels(PDO $pdo): array
{
    return get_permission_template_labels();
}

function get_permission_management_active_user_labels(PDO $pdo): array
{
    return get_permission_lookup();
}

function permission_role_exists(PDO $pdo, string $roleName): bool
{
    return role_exists($pdo, $roleName);
}

function permission_user_exists(PDO $pdo, string $username): bool
{
    return (bool) get_user_by_username($pdo, $username);
}

function permission_module_exists(string $moduleName): bool
{
    return array_key_exists($moduleName, permission_definitions());
}

function permission_key_is_defined(string $permissionKey): bool
{
    return permission_key_exists($permissionKey);
}

function permission_action_is_defined(string $permissionKey): bool
{
    return permission_key_exists($permissionKey);
}

function permission_template_is_defined(string $templateName): bool
{
    return array_key_exists($templateName, permission_template_definitions());
}

function permission_mapping(): array
{
    return get_permission_lookup();
}

function permission_access_mapping(): array
{
    return get_permission_to_module_map();
}

function permission_template_mapping(): array
{
    return get_permission_template_map($pdo);
}

function permission_options(): array
{
    return permission_definitions();
}

function permission_templates(): array
{
    return permission_template_definitions();
}

function permission_users(PDO $pdo): array
{
    return get_admin_users($pdo);
}

function permission_roles(PDO $pdo): array
{
    return get_stored_role_templates($pdo);
}

function permission_template_roles(PDO $pdo): array
{
    return get_permission_template_labels();
}

function permission_user_roles(PDO $pdo, int $userId): array
{
    return get_user_role_names($pdo, $userId);
}

function permission_user_permissions(PDO $pdo, int $userId): array
{
    return get_user_permission_keys($pdo, $userId);
}

function permission_role_permissions(PDO $pdo, string $roleName): array
{
    return get_permission_template_permissions($pdo, $roleName);
}

function permission_action_labels(): array
{
    return get_permission_action_names();
}

function permission_module_labels(): array
{
    return get_permission_access_labels();
}

function permission_template_labels(): array
{
    return get_permission_template_labels();
}

function permission_user_labels(PDO $pdo): array
{
    return get_permission_lookup();
}

function permission_role_labels(PDO $pdo): array
{
    return get_permission_template_labels();
}

function permission_setting_labels(): array
{
    return get_permission_lookup();
}

/**
 * Log a permission check to the audit log.
 * @param PDO $pdo Database connection
 * @param int|null $userId Admin user ID
 * @param string $action Permission action
 * @param string|null $resource Resource being accessed
 * @param bool $granted Whether permission was granted
 * @param string|null $level Log level (INFO, WARN, DENY, ERROR)
 * @param array|null $details Additional context
 */
function audit_permission_check(
    PDO $pdo,
    ?int $userId,
    string $action,
    ?string $resource = null,
    bool $granted = false,
    ?string $level = null,
    ?array $details = null
): void {
    rbac_audit_log($pdo, $userId, $action, $resource, $granted, $level, $details);
}



