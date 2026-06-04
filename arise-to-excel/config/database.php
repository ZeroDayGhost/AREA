<?php
require_once __DIR__ . '/app.php';

// XAMPP defaults: localhost, root user, empty password.
$dbHost = 'localhost';
$dbName = 'arise_to_excel';
$dbUser = 'root';
$dbPass = '';

try {
    $pdo = new PDO(
        "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $exception) {
    http_response_code(500);
    echo '<h1>Database connection failed</h1>';
    echo '<p>Start MySQL in XAMPP and import <strong>arise_to_excel.sql</strong> using phpMyAdmin.</p>';
    echo '<p>' . h($exception->getMessage()) . '</p>';
    exit;
}

try {
    // Compatibility for databases created before the latest fee-management fields were added.
    $classLevelColumn = $pdo->query("SHOW COLUMNS FROM students LIKE 'class_level'")->fetch();
    if (!$classLevelColumn) {
        $pdo->exec("ALTER TABLE students ADD COLUMN class_level VARCHAR(30) NOT NULL DEFAULT '' AFTER full_name");
    }

    $genderColumn = $pdo->query("SHOW COLUMNS FROM students LIKE 'gender'")->fetch();
    if (!$genderColumn) {
        $pdo->exec("ALTER TABLE students ADD COLUMN gender VARCHAR(10) NOT NULL DEFAULT '' AFTER full_name");
    }

    $classNameColumn = $pdo->query("SHOW COLUMNS FROM students LIKE 'class_name'")->fetch();
    if ($classNameColumn) {
        $pdo->exec("UPDATE students SET class_level = class_name WHERE class_level = '' AND class_name IS NOT NULL AND class_name <> ''");
        $pdo->exec("ALTER TABLE students MODIFY class_name VARCHAR(80) NULL DEFAULT NULL");
    }

    $parentColumn = $pdo->query("SHOW COLUMNS FROM students LIKE 'parent_name'")->fetch();
    if (!$parentColumn) {
        $pdo->exec("ALTER TABLE students ADD COLUMN parent_name VARCHAR(150) NOT NULL DEFAULT '' AFTER class_level");
    }

    $guardianColumn = $pdo->query("SHOW COLUMNS FROM students LIKE 'guardian_name'")->fetch();
    if ($guardianColumn) {
        $pdo->exec("UPDATE students SET parent_name = guardian_name WHERE parent_name = '' AND guardian_name IS NOT NULL AND guardian_name <> ''");
    }

    $studentTypeColumn = $pdo->query("SHOW COLUMNS FROM students LIKE 'student_type'")->fetch();
    if (!$studentTypeColumn) {
        $pdo->exec("ALTER TABLE students ADD COLUMN student_type VARCHAR(30) NOT NULL DEFAULT 'Normal Student' AFTER class_level");
    }

    $feeColumns = [
        'mpesa_code' => "ALTER TABLE fees ADD COLUMN mpesa_code VARCHAR(30) NOT NULL DEFAULT '' AFTER amount_paid",
        'mpesa_reference_text' => "ALTER TABLE fees ADD COLUMN mpesa_reference_text TEXT NULL AFTER mpesa_code",
        'term' => "ALTER TABLE fees ADD COLUMN term VARCHAR(20) NOT NULL DEFAULT '' AFTER mpesa_reference_text",
        'year' => "ALTER TABLE fees ADD COLUMN year VARCHAR(4) NOT NULL DEFAULT '' AFTER term",
    ];

    foreach ($feeColumns as $column => $sql) {
        $exists = $pdo->query("SHOW COLUMNS FROM fees LIKE " . $pdo->quote($column))->fetch();
        if (!$exists) {
            $pdo->exec($sql);
        }
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS fee_structures (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            academic_year VARCHAR(4) NOT NULL,
            class_level VARCHAR(30) NOT NULL,
            term1_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            term2_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            term3_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_fee_structure_year_class (academic_year, class_level)
        ) ENGINE=InnoDB"
    );

    $feeBalancesTable = $pdo->query("SHOW TABLES LIKE " . $pdo->quote('fee_balances'))->fetch();
    if (!$feeBalancesTable) {
        $pdo->exec(
            "CREATE TABLE fee_balances (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                student_id INT UNSIGNED NOT NULL,
                academic_year VARCHAR(4) NOT NULL,
                term VARCHAR(20) NOT NULL,
                original_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                discount_percentage DECIMAL(5,2) NOT NULL DEFAULT 0.00,
                discounted_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                required_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_fee_balance_student_year_term (student_id, academic_year, term),
                CONSTRAINT fk_fee_balances_student_term
                  FOREIGN KEY (student_id) REFERENCES students(id)
                  ON DELETE CASCADE
                  ON UPDATE CASCADE
            ) ENGINE=InnoDB"
        );
    } else {
        $feeBalanceTermColumn = $pdo->query("SHOW COLUMNS FROM fee_balances LIKE 'term'")->fetch();
        if (!$feeBalanceTermColumn) {
            $pdo->exec("DROP TABLE IF EXISTS fee_balances_term_migration");
            $pdo->exec(
                "CREATE TABLE fee_balances_term_migration (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    student_id INT UNSIGNED NOT NULL,
                    academic_year VARCHAR(4) NOT NULL,
                    term VARCHAR(20) NOT NULL,
                    original_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    discount_percentage DECIMAL(5,2) NOT NULL DEFAULT 0.00,
                    discounted_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    required_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uniq_fee_balance_student_year_term (student_id, academic_year, term),
                    CONSTRAINT fk_fee_balances_student_term_migration
                      FOREIGN KEY (student_id) REFERENCES students(id)
                      ON DELETE CASCADE
                      ON UPDATE CASCADE
                ) ENGINE=InnoDB"
            );
            $pdo->exec(
                "INSERT INTO fee_balances_term_migration
                    (student_id, academic_year, term, original_fee, discount_percentage, discounted_fee, required_amount, paid_amount, balance, created_at)
                 SELECT
                    legacy.student_id,
                    legacy.academic_year,
                    legacy.term,
                    CASE legacy.term
                        WHEN 'Term 1' THEN COALESCE(fee_structures.term1_total, legacy.balance_amount + COALESCE(payments.paid_amount, 0))
                        WHEN 'Term 2' THEN COALESCE(fee_structures.term2_total, legacy.balance_amount + COALESCE(payments.paid_amount, 0))
                        ELSE COALESCE(fee_structures.term3_total, legacy.balance_amount + COALESCE(payments.paid_amount, 0))
                    END AS original_fee,
                    0.00 AS discount_percentage,
                    CASE legacy.term
                        WHEN 'Term 1' THEN COALESCE(fee_structures.term1_total, legacy.balance_amount + COALESCE(payments.paid_amount, 0))
                        WHEN 'Term 2' THEN COALESCE(fee_structures.term2_total, legacy.balance_amount + COALESCE(payments.paid_amount, 0))
                        ELSE COALESCE(fee_structures.term3_total, legacy.balance_amount + COALESCE(payments.paid_amount, 0))
                    END AS discounted_fee,
                    CASE legacy.term
                        WHEN 'Term 1' THEN COALESCE(fee_structures.term1_total, legacy.balance_amount + COALESCE(payments.paid_amount, 0))
                        WHEN 'Term 2' THEN COALESCE(fee_structures.term2_total, legacy.balance_amount + COALESCE(payments.paid_amount, 0))
                        ELSE COALESCE(fee_structures.term3_total, legacy.balance_amount + COALESCE(payments.paid_amount, 0))
                    END AS required_amount,
                    COALESCE(payments.paid_amount, 0) AS paid_amount,
                    legacy.balance_amount AS balance,
                    legacy.created_at
                 FROM (
                    SELECT student_id, academic_year, 'Term 1' AS term, term1_balance AS balance_amount, created_at FROM fee_balances
                    UNION ALL
                    SELECT student_id, academic_year, 'Term 2' AS term, term2_balance AS balance_amount, created_at FROM fee_balances
                    UNION ALL
                    SELECT student_id, academic_year, 'Term 3' AS term, term3_balance AS balance_amount, created_at FROM fee_balances
                 ) AS legacy
                 JOIN students ON students.id = legacy.student_id
                 LEFT JOIN fee_structures
                   ON fee_structures.academic_year = legacy.academic_year
                  AND fee_structures.class_level = students.class_level
                 LEFT JOIN (
                    SELECT student_id, year AS academic_year, term, COALESCE(SUM(amount_paid), 0) AS paid_amount
                    FROM fees
                    GROUP BY student_id, year, term
                 ) AS payments
                   ON payments.student_id = legacy.student_id
                  AND payments.academic_year = legacy.academic_year
                  AND payments.term = legacy.term"
            );
            $pdo->exec("DROP TABLE fee_balances");
            $pdo->exec("RENAME TABLE fee_balances_term_migration TO fee_balances");
        } else {
            $feeBalanceColumns = [
                'original_fee' => "ALTER TABLE fee_balances ADD COLUMN original_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER term",
                'discount_percentage' => "ALTER TABLE fee_balances ADD COLUMN discount_percentage DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER original_fee",
                'discounted_fee' => "ALTER TABLE fee_balances ADD COLUMN discounted_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER discount_percentage",
                'required_amount' => "ALTER TABLE fee_balances ADD COLUMN required_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER term",
                'paid_amount' => "ALTER TABLE fee_balances ADD COLUMN paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER required_amount",
                'balance' => "ALTER TABLE fee_balances ADD COLUMN balance DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER paid_amount",
                'updated_at' => "ALTER TABLE fee_balances ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
            ];
            foreach ($feeBalanceColumns as $column => $sql) {
                $exists = $pdo->query("SHOW COLUMNS FROM fee_balances LIKE " . $pdo->quote($column))->fetch();
                if (!$exists) {
                    $pdo->exec($sql);
                }
            }

            $pdo->exec(
                "UPDATE fee_balances
                 SET original_fee = required_amount
                 WHERE original_fee = 0.00"
            );
            $pdo->exec(
                "UPDATE fee_balances
                 SET discounted_fee = required_amount
                 WHERE discounted_fee = 0.00"
            );

            $termIndex = $pdo->query("SHOW INDEX FROM fee_balances WHERE Key_name = 'uniq_fee_balance_student_year_term'")->fetch();
            if (!$termIndex) {
                $pdo->exec(
                    "DELETE old_balance
                     FROM fee_balances old_balance
                     JOIN fee_balances keep_balance
                       ON keep_balance.student_id = old_balance.student_id
                      AND keep_balance.academic_year = old_balance.academic_year
                      AND keep_balance.term = old_balance.term
                      AND keep_balance.id < old_balance.id"
                );
                $pdo->exec("ALTER TABLE fee_balances ADD UNIQUE KEY uniq_fee_balance_student_year_term (student_id, academic_year, term)");
            }

            $yearOnlyIndex = $pdo->query("SHOW INDEX FROM fee_balances WHERE Key_name = 'uniq_fee_balance_student_year'")->fetch();
            if ($yearOnlyIndex) {
                $pdo->exec("ALTER TABLE fee_balances DROP INDEX uniq_fee_balance_student_year");
            }
        }
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS student_fee_discounts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            student_id INT UNSIGNED NOT NULL,
            academic_year VARCHAR(4) NOT NULL,
            term VARCHAR(20) NOT NULL,
            original_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            discount_percentage DECIMAL(5,2) NOT NULL DEFAULT 50.00,
            discounted_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_student_fee_discount_term (student_id, academic_year, term),
            CONSTRAINT fk_student_fee_discounts_student
              FOREIGN KEY (student_id) REFERENCES students(id)
              ON DELETE CASCADE
              ON UPDATE CASCADE
        ) ENGINE=InnoDB"
    );

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

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS academic_settings (
            setting_key VARCHAR(60) PRIMARY KEY,
            setting_value VARCHAR(100) NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB"
    );

    $calendarIndex = $pdo->query("SHOW INDEX FROM academic_calendar WHERE Key_name = 'uniq_academic_calendar_year_term'")->fetch();
    if (!$calendarIndex) {
        $pdo->exec(
            "DELETE old_calendar
             FROM academic_calendar old_calendar
             JOIN academic_calendar keep_calendar
               ON keep_calendar.academic_year = old_calendar.academic_year
              AND keep_calendar.term_name = old_calendar.term_name
              AND keep_calendar.id < old_calendar.id"
        );
        $pdo->exec("ALTER TABLE academic_calendar ADD UNIQUE KEY uniq_academic_calendar_year_term (academic_year, term_name)");
    }

    $calendarYear = date('Y');
    $calendarRows = [
        ['Term 1', "{$calendarYear}-01-01", "{$calendarYear}-04-30"],
        ['Term 2', "{$calendarYear}-05-01", "{$calendarYear}-08-31"],
        ['Term 3', "{$calendarYear}-09-01", "{$calendarYear}-12-31"],
    ];
    $calendarInsert = $pdo->prepare(
        "INSERT IGNORE INTO academic_calendar (academic_year, term_name, start_date, end_date)
         VALUES (:academic_year, :term_name, :start_date, :end_date)"
    );
    $calendarYearRows = $pdo->prepare(
        "SELECT COUNT(*)
         FROM academic_calendar
         WHERE academic_year = :academic_year"
    );
    $calendarYearRows->execute(['academic_year' => $calendarYear]);
    if ((int) $calendarYearRows->fetchColumn() === 0) {
        foreach ($calendarRows as $calendarRow) {
            $calendarInsert->execute([
                'academic_year' => $calendarYear,
                'term_name' => $calendarRow[0],
                'start_date' => $calendarRow[1],
                'end_date' => $calendarRow[2],
            ]);
        }
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS feeding_subscriptions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            student_id INT UNSIGNED NOT NULL,
            academic_year VARCHAR(4) NOT NULL,
            term VARCHAR(20) NOT NULL,
            feeding_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            status VARCHAR(20) NOT NULL DEFAULT 'Active',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_feeding_student_year_term (student_id, academic_year, term),
            CONSTRAINT fk_feeding_subscriptions_student
              FOREIGN KEY (student_id) REFERENCES students(id)
              ON DELETE CASCADE
              ON UPDATE CASCADE
        ) ENGINE=InnoDB"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS feeding_payments (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            feeding_subscription_id INT UNSIGNED NOT NULL,
            amount_paid DECIMAL(12,2) NOT NULL,
            payment_date DATE NOT NULL,
            reference_no VARCHAR(80) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_feeding_payments_subscription
              FOREIGN KEY (feeding_subscription_id) REFERENCES feeding_subscriptions(id)
              ON DELETE CASCADE
              ON UPDATE CASCADE
        ) ENGINE=InnoDB"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS kitchen_inventory (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            item_name VARCHAR(120) NOT NULL,
            quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            item_date DATE NOT NULL,
            supplier VARCHAR(150) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS school_expenses (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            item_name VARCHAR(120) NOT NULL,
            category VARCHAR(40) NOT NULL,
            amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            quantity DECIMAL(12,2) NOT NULL DEFAULT 1.00,
            total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            expense_date DATE NOT NULL,
            description TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS transport_students (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            student_id INT UNSIGNED NULL,
            student_name VARCHAR(150) NOT NULL,
            gender VARCHAR(10) NOT NULL,
            parent_name VARCHAR(150) NOT NULL,
            parent_phone VARCHAR(40) NULL,
            pickup_location VARCHAR(150) NOT NULL,
            is_outside TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_transport_existing_student (student_id),
            CONSTRAINT fk_transport_students_student
              FOREIGN KEY (student_id) REFERENCES students(id)
              ON DELETE SET NULL
              ON UPDATE CASCADE
        ) ENGINE=InnoDB"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS transport_accounts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            transport_student_id INT UNSIGNED NOT NULL,
            academic_year VARCHAR(4) NOT NULL,
            term VARCHAR(20) NOT NULL,
            amount_due DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            status VARCHAR(20) NOT NULL DEFAULT 'Active',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_transport_student_year_term (transport_student_id, academic_year, term),
            CONSTRAINT fk_transport_accounts_student
              FOREIGN KEY (transport_student_id) REFERENCES transport_students(id)
              ON DELETE CASCADE
              ON UPDATE CASCADE
        ) ENGINE=InnoDB"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS transport_payments (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            transport_account_id INT UNSIGNED NOT NULL,
            amount_paid DECIMAL(12,2) NOT NULL,
            payment_date DATE NOT NULL,
            reference_no VARCHAR(80) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_transport_payments_account
              FOREIGN KEY (transport_account_id) REFERENCES transport_accounts(id)
              ON DELETE CASCADE
              ON UPDATE CASCADE
        ) ENGINE=InnoDB"
    );
} catch (PDOException $ignored) {
    // Ignore this during first setup before the SQL file has been imported.
}
