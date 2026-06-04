<?php

function class_level_options(): array
{
    return ['Playgroup', 'PP1', 'PP2', 'Grade 1', 'Grade 2', 'Grade 3', 'Grade 4', 'Grade 5', 'Grade 6'];
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
    if ($requiredAmount > 0 && $balance <= 0.005) {
        return 'Paid';
    }

    if ($paidAmount > 0.005 && $balance > 0.005) {
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
    return ['Kitchen', 'Office', 'Utilities', 'Maintenance', 'Transport', 'Other'];
}

function fee_structure_term_columns(): array
{
    return [
        'Term 1' => 'term1_total',
        'Term 2' => 'term2_total',
        'Term 3' => 'term3_total',
    ];
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

function current_academic_year_from_calendar(PDO $pdo): string
{
    return current_academic_context($pdo)['academic_year'];
}

function current_academic_term(PDO $pdo): string
{
    return current_academic_context($pdo)['term'];
}

function valid_money_value($value): bool
{
    return is_numeric($value) && (float) $value >= 0;
}

function valid_quantity_value($value): bool
{
    return is_numeric($value) && (float) $value >= 0;
}

function valid_date_value(string $value): bool
{
    $date = DateTime::createFromFormat('Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value;
}

function get_fee_structure(PDO $pdo, string $classLevel, string $academicYear): ?array
{
    $statement = $pdo->prepare(
        "SELECT *
         FROM fee_structures
         WHERE academic_year = :academic_year
           AND class_level = :class_level
         LIMIT 1"
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
        "SELECT *
         FROM student_fee_discounts
         WHERE student_id = :student_id
           AND academic_year = :academic_year
           AND term = :term
         LIMIT 1"
    );
    $statement->execute([
        'student_id' => $studentId,
        'academic_year' => $academicYear,
        'term' => $term,
    ]);
    $discount = $statement->fetch();

    return $discount ?: null;
}

function default_discounted_fee(float $originalFee, float $discountPercentage = 50.0): float
{
    return round(max($originalFee * ((100 - $discountPercentage) / 100), 0), 2);
}

function save_student_fee_discount(PDO $pdo, int $studentId, string $academicYear, string $term, float $originalFee, float $discountPercentage, float $discountedFee): void
{
    $statement = $pdo->prepare(
        "INSERT INTO student_fee_discounts
            (student_id, academic_year, term, original_fee, discount_percentage, discounted_fee)
         VALUES
            (:student_id, :academic_year, :term, :original_fee, :discount_percentage, :discounted_fee)
         ON DUPLICATE KEY UPDATE
            original_fee = VALUES(original_fee),
            discount_percentage = VALUES(discount_percentage),
            discounted_fee = VALUES(discounted_fee)"
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
        "DELETE FROM student_fee_discounts
         WHERE student_id = :student_id
           AND academic_year = :academic_year"
    );
    $statement->execute([
        'student_id' => $studentId,
        'academic_year' => $academicYear,
    ]);
}

function resolve_student_term_fee_amounts(PDO $pdo, int $studentId, string $studentType, array $structure, string $academicYear, string $term): array
{
    $originalFee = fee_required_amount_for_term($structure, $term);

    if (!is_teacher_child_type($studentType)) {
        return [
            'original_fee' => $originalFee,
            'discount_percentage' => 0.0,
            'discounted_fee' => $originalFee,
            'required_amount' => $originalFee,
        ];
    }

    $discount = fetch_student_fee_discount($pdo, $studentId, $academicYear, $term);
    $discountPercentage = $discount ? (float) $discount['discount_percentage'] : 50.0;
    $discountedFee = $discount ? (float) $discount['discounted_fee'] : default_discounted_fee($originalFee, $discountPercentage);

    if ($discount) {
        $storedOriginalFee = (float) $discount['original_fee'];
        $storedDefaultFee = default_discounted_fee($storedOriginalFee, $discountPercentage);
        if (abs($discountedFee - $storedDefaultFee) <= 0.005 && abs($storedOriginalFee - $originalFee) > 0.005) {
            $discountedFee = default_discounted_fee($originalFee, $discountPercentage);
        }
    }

    $discountedFee = min(max($discountedFee, 0), $originalFee);
    save_student_fee_discount($pdo, $studentId, $academicYear, $term, $originalFee, $discountPercentage, $discountedFee);

    return [
        'original_fee' => $originalFee,
        'discount_percentage' => $discountPercentage,
        'discounted_fee' => $discountedFee,
        'required_amount' => $discountedFee,
    ];
}

function fetch_student_term_payments(PDO $pdo, int $studentId, string $academicYear): array
{
    $payments = [
        'Term 1' => 0.0,
        'Term 2' => 0.0,
        'Term 3' => 0.0,
    ];

    $statement = $pdo->prepare(
        "SELECT term, COALESCE(SUM(amount_paid), 0) AS paid
         FROM fees
         WHERE student_id = :student_id
           AND year = :academic_year
         GROUP BY term"
    );
    $statement->execute([
        'student_id' => $studentId,
        'academic_year' => $academicYear,
    ]);

    foreach ($statement->fetchAll() as $row) {
        if (array_key_exists($row['term'], $payments)) {
            $payments[$row['term']] = (float) $row['paid'];
        }
    }

    return $payments;
}

function sync_fee_balance_for_student(PDO $pdo, int $studentId, string $classLevel, string $academicYear, ?string $term = null): array
{
    $studentStatement = $pdo->prepare("SELECT class_level, student_type FROM students WHERE id = :id LIMIT 1");
    $studentStatement->execute(['id' => $studentId]);
    $student = $studentStatement->fetch();

    if (!$student) {
        throw new RuntimeException('Selected student was not found.');
    }

    $studentType = $student['student_type'] ?? 'Normal Student';
    $classLevel = $student['class_level'] ?: $classLevel;
    $structure = get_fee_structure($pdo, $classLevel, $academicYear);

    if (!$structure) {
        throw new RuntimeException("No fee structure exists for {$classLevel} in {$academicYear}.");
    }

    $termToSync = $term ?? current_academic_context($pdo)['term'];

    if (!in_array($termToSync, term_options(), true)) {
        throw new InvalidArgumentException('Invalid academic term.');
    }

    $payments = fetch_student_term_payments($pdo, $studentId, $academicYear);
    $statement = $pdo->prepare(
        "INSERT INTO fee_balances
            (student_id, academic_year, term, original_fee, discount_percentage, discounted_fee, required_amount, paid_amount, balance)
         VALUES
            (:student_id, :academic_year, :term, :original_fee, :discount_percentage, :discounted_fee, :required_amount, :paid_amount, :balance)
         ON DUPLICATE KEY UPDATE
            original_fee = VALUES(original_fee),
            discount_percentage = VALUES(discount_percentage),
            discounted_fee = VALUES(discounted_fee),
            required_amount = VALUES(required_amount),
            paid_amount = VALUES(paid_amount),
            balance = VALUES(balance)"
    );

    $syncedRows = [];
    $feeAmounts = resolve_student_term_fee_amounts($pdo, $studentId, $studentType, $structure, $academicYear, $termToSync);
    $requiredAmount = $feeAmounts['required_amount'];
    $paidAmount = $payments[$termToSync] ?? 0.0;
    $balance = max($requiredAmount - $paidAmount, 0);

    $row = [
        'student_id' => $studentId,
        'academic_year' => $academicYear,
        'term' => $termToSync,
        'original_fee' => $feeAmounts['original_fee'],
        'discount_percentage' => $feeAmounts['discount_percentage'],
        'discounted_fee' => $feeAmounts['discounted_fee'],
        'required_amount' => $requiredAmount,
        'paid_amount' => $paidAmount,
        'balance' => $balance,
    ];
    $statement->execute($row);
    $syncedRows[] = $row;

    return $syncedRows;
}

function sync_fee_balances_for_class_year(PDO $pdo, string $classLevel, string $academicYear, ?string $term = null): int
{
    $termToSync = $term ?? current_academic_context($pdo)['term'];

    $statement = $pdo->prepare(
        "SELECT id
         FROM students
         WHERE class_level = :class_level"
    );
    $statement->execute(['class_level' => $classLevel]);

    $synced = 0;
    foreach ($statement->fetchAll() as $student) {
        $synced += count(sync_fee_balance_for_student($pdo, (int) $student['id'], $classLevel, $academicYear, $termToSync));
    }

    return $synced;
}

function sync_current_term_fee_balances(PDO $pdo): int
{
    $context = current_academic_context($pdo);
    $statement = $pdo->prepare(
        "SELECT students.id, students.class_level
         FROM students
         JOIN fee_structures
           ON fee_structures.academic_year = :academic_year
          AND fee_structures.class_level = students.class_level"
    );
    $statement->execute(['academic_year' => $context['academic_year']]);

    $synced = 0;
    foreach ($statement->fetchAll() as $student) {
        $synced += count(sync_fee_balance_for_student(
            $pdo,
            (int) $student['id'],
            $student['class_level'],
            $context['academic_year'],
            $context['term']
        ));
    }

    return $synced;
}

function get_fee_balance(PDO $pdo, int $studentId, string $academicYear, $term = null, bool $forUpdate = false): ?array
{
    if (is_bool($term)) {
        $forUpdate = $term;
        $term = null;
    }

    if ($term === null) {
        $term = current_academic_context($pdo)['term'];
    }

    if (!in_array($term, term_options(), true)) {
        throw new InvalidArgumentException('Invalid academic term.');
    }

    $sql = "SELECT *
            FROM fee_balances
            WHERE student_id = :student_id
              AND academic_year = :academic_year
              AND term = :term
            LIMIT 1";

    if ($forUpdate) {
        $sql .= " FOR UPDATE";
    }

    $statement = $pdo->prepare($sql);
    $statement->execute([
        'student_id' => $studentId,
        'academic_year' => $academicYear,
        'term' => $term,
    ]);

    $balance = $statement->fetch();
    return $balance ?: null;
}

function generate_registration_no(PDO $pdo, ?string $academicYear = null): string
{
    $prefix = substr((string) ($academicYear ?? current_academic_year()), -2);
    $statement = $pdo->prepare(
        "SELECT MAX(CAST(SUBSTRING_INDEX(registration_no, '/', -1) AS UNSIGNED))
         FROM students
         WHERE registration_no LIKE :registration_prefix"
    );
    $statement->execute(['registration_prefix' => $prefix . '/%']);
    $next = ((int) $statement->fetchColumn()) + 1;

    do {
        $registrationNo = $prefix . '/' . str_pad((string) $next, 3, '0', STR_PAD_LEFT);
        $check = $pdo->prepare("SELECT COUNT(*) FROM students WHERE registration_no = :registration_no");
        $check->execute(['registration_no' => $registrationNo]);
        $next++;
    } while ((int) $check->fetchColumn() > 0);

    return $registrationNo;
}

function registration_no_exists(PDO $pdo, string $registrationNo, int $ignoreStudentId = 0): bool
{
    $sql = "SELECT COUNT(*)
            FROM students
            WHERE registration_no = :registration_no";
    $params = ['registration_no' => $registrationNo];

    if ($ignoreStudentId > 0) {
        $sql .= " AND id <> :student_id";
        $params['student_id'] = $ignoreStudentId;
    }

    $statement = $pdo->prepare($sql);
    $statement->execute($params);

    return (int) $statement->fetchColumn() > 0;
}

function fetch_fee_term_report(PDO $pdo, array $filters = [], bool $balancesOnly = false): array
{
    $classLevel = $filters['class_level'] ?? '';
    $gender = $filters['gender'] ?? '';
    $academicYear = $filters['year'] ?? '';
    $termFilter = $filters['term'] ?? '';
    $paidStatus = $filters['paid_status'] ?? '';
    $feedingFilter = $filters['feeding'] ?? '';
    $transportFilter = $filters['transport'] ?? '';
    $dateFrom = $filters['date_from'] ?? '';
    $dateTo = $filters['date_to'] ?? '';
    $where = [];
    $params = [];

    if ($classLevel !== '') {
        $where[] = 'students.class_level = :class_level';
        $params['class_level'] = $classLevel;
    }
    if ($gender !== '') {
        $where[] = 'students.gender = :gender';
        $params['gender'] = $gender;
    }
    if ($academicYear !== '') {
        $where[] = 'fee_balances.academic_year = :academic_year';
        $params['academic_year'] = $academicYear;
    }
    if ($termFilter !== '') {
        $where[] = 'fee_balances.term = :term';
        $params['term'] = $termFilter;
    }
    if ($balancesOnly) {
        $where[] = 'fee_balances.balance > 0.005';
    }
    if ($paidStatus === 'Paid') {
        $where[] = 'fee_balances.required_amount > 0 AND fee_balances.balance <= 0.005';
    } elseif ($paidStatus === 'Partial') {
        $where[] = 'fee_balances.paid_amount > 0.005 AND fee_balances.balance > 0.005';
    } elseif ($paidStatus === 'Unpaid') {
        $where[] = 'fee_balances.paid_amount <= 0.005 AND fee_balances.balance > 0.005';
    }
    if ($dateFrom !== '' || $dateTo !== '') {
        $dateConditions = [];
        if ($dateFrom !== '') {
            $dateConditions[] = 'fees.payment_date >= :balance_date_from';
            $params['balance_date_from'] = $dateFrom;
        }
        if ($dateTo !== '') {
            $dateConditions[] = 'fees.payment_date <= :balance_date_to';
            $params['balance_date_to'] = $dateTo;
        }
        $where[] = "EXISTS (
            SELECT 1
            FROM fees
            WHERE fees.student_id = students.id
              AND fees.year = fee_balances.academic_year
              AND fees.term = fee_balances.term
              AND " . implode(' AND ', $dateConditions) . "
        )";
    }
    if ($feedingFilter === 'yes') {
        $where[] = "EXISTS (
            SELECT 1
            FROM feeding_subscriptions
            WHERE feeding_subscriptions.student_id = students.id
              AND feeding_subscriptions.academic_year = fee_balances.academic_year
              AND feeding_subscriptions.term = fee_balances.term
              AND feeding_subscriptions.status = 'Active'
        )";
    } elseif ($feedingFilter === 'no') {
        $where[] = "NOT EXISTS (
            SELECT 1
            FROM feeding_subscriptions
            WHERE feeding_subscriptions.student_id = students.id
              AND feeding_subscriptions.academic_year = fee_balances.academic_year
              AND feeding_subscriptions.term = fee_balances.term
              AND feeding_subscriptions.status = 'Active'
        )";
    }
    if ($transportFilter === 'yes') {
        $where[] = "EXISTS (
            SELECT 1
            FROM transport_accounts
            JOIN transport_students ON transport_students.id = transport_accounts.transport_student_id
            WHERE transport_students.student_id = students.id
              AND transport_accounts.academic_year = fee_balances.academic_year
              AND transport_accounts.term = fee_balances.term
              AND transport_accounts.status = 'Active'
        )";
    } elseif ($transportFilter === 'no') {
        $where[] = "NOT EXISTS (
            SELECT 1
            FROM transport_accounts
            JOIN transport_students ON transport_students.id = transport_accounts.transport_student_id
            WHERE transport_students.student_id = students.id
              AND transport_accounts.academic_year = fee_balances.academic_year
              AND transport_accounts.term = fee_balances.term
              AND transport_accounts.status = 'Active'
        )";
    }

    $sql = "SELECT
                students.id AS student_id,
                students.registration_no,
                students.full_name,
                students.gender,
                students.class_level,
                students.student_type,
                fee_balances.academic_year,
                fee_balances.term,
                fee_balances.original_fee,
                fee_balances.discount_percentage,
                fee_balances.discounted_fee,
                fee_balances.required_amount,
                fee_balances.paid_amount,
                fee_balances.balance,
                (
                    SELECT MAX(fees.payment_date)
                    FROM fees
                    WHERE fees.student_id = students.id
                      AND fees.year = fee_balances.academic_year
                      AND fees.term = fee_balances.term
                ) AS last_payment_date
            FROM fee_balances
            JOIN students ON students.id = fee_balances.student_id";
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= " ORDER BY fee_balances.academic_year DESC,
                     FIELD(fee_balances.term, 'Term 1', 'Term 2', 'Term 3') ASC,
                     students.class_level ASC,
                     students.full_name ASC";

    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    $termOrder = array_flip(term_options());
    $rows = [];

    foreach ($statement->fetchAll() as $balanceRow) {
        $term = $balanceRow['term'];
        $rows[] = [
            'student_id' => (int) $balanceRow['student_id'],
            'registration_no' => $balanceRow['registration_no'],
            'full_name' => $balanceRow['full_name'],
            'gender' => $balanceRow['gender'],
            'class_level' => $balanceRow['class_level'],
            'student_type' => $balanceRow['student_type'] ?? 'Normal Student',
            'academic_year' => $balanceRow['academic_year'],
            'term' => $term,
            'term_order' => $termOrder[$term] ?? 99,
            'original_fee' => (float) $balanceRow['original_fee'],
            'discount_percentage' => (float) $balanceRow['discount_percentage'],
            'discounted_fee' => (float) $balanceRow['discounted_fee'],
            'required_amount' => (float) $balanceRow['required_amount'],
            'paid_amount' => (float) $balanceRow['paid_amount'],
            'balance' => (float) $balanceRow['balance'],
            'status' => fee_payment_status((float) $balanceRow['required_amount'], (float) $balanceRow['paid_amount'], (float) $balanceRow['balance']),
            'last_payment_date' => $balanceRow['last_payment_date'],
        ];
    }

    usort($rows, function (array $left, array $right): int {
        return [$right['academic_year'], $left['term_order'], $left['class_level'], $left['full_name']]
            <=> [$left['academic_year'], $right['term_order'], $right['class_level'], $right['full_name']];
    });

    return $rows;
}
