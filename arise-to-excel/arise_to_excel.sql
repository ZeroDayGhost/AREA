-- Arise To Excel Academy Fee Management Database
-- Import this file in phpMyAdmin on XAMPP.

CREATE DATABASE IF NOT EXISTS arise_to_excel
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE arise_to_excel;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS transport_payments;
DROP TABLE IF EXISTS transport_accounts;
DROP TABLE IF EXISTS transport_students;
DROP TABLE IF EXISTS feeding_payments;
DROP TABLE IF EXISTS feeding_subscriptions;
DROP TABLE IF EXISTS fees;
DROP TABLE IF EXISTS fee_balances;
DROP TABLE IF EXISTS student_fee_discounts;
DROP TABLE IF EXISTS fee_structures;
DROP TABLE IF EXISTS academic_settings;
DROP TABLE IF EXISTS academic_calendar;
DROP TABLE IF EXISTS kitchen_inventory;
DROP TABLE IF EXISTS school_expenses;
DROP TABLE IF EXISTS students;
DROP TABLE IF EXISTS admin;

-- Remove older non-fee tables if this database was previously used by the full school website.
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS timetable;
DROP TABLE IF EXISTS announcements;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE admin (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    username VARCHAR(60) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE students (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    registration_no VARCHAR(40) NOT NULL UNIQUE,
    full_name VARCHAR(150) NOT NULL,
    gender VARCHAR(10) NOT NULL,
    class_level VARCHAR(30) NOT NULL,
    student_type VARCHAR(30) NOT NULL DEFAULT 'Normal Student',
    parent_name VARCHAR(150) NOT NULL,
    guardian_phone VARCHAR(40) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE fee_structures (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    academic_year VARCHAR(4) NOT NULL,
    class_level VARCHAR(30) NOT NULL,
    term1_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    term2_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    term3_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_fee_structure_year_class (academic_year, class_level)
) ENGINE=InnoDB;

CREATE TABLE fee_balances (
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
) ENGINE=InnoDB;

CREATE TABLE student_fee_discounts (
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
) ENGINE=InnoDB;

CREATE TABLE academic_calendar (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    academic_year VARCHAR(4) NOT NULL,
    term_name VARCHAR(20) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_academic_calendar_year_term (academic_year, term_name)
) ENGINE=InnoDB;

CREATE TABLE academic_settings (
    setting_key VARCHAR(60) PRIMARY KEY,
    setting_value VARCHAR(100) NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE fees (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id INT UNSIGNED NOT NULL,
    receipt_no VARCHAR(60) NOT NULL UNIQUE,
    amount_paid DECIMAL(12,2) NOT NULL,
    mpesa_code VARCHAR(30) NOT NULL,
    mpesa_reference_text TEXT NULL,
    term VARCHAR(20) NOT NULL,
    year VARCHAR(4) NOT NULL,
    balance_after_payment DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    payment_date DATE NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_fees_student
      FOREIGN KEY (student_id) REFERENCES students(id)
      ON DELETE CASCADE
      ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE feeding_subscriptions (
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
) ENGINE=InnoDB;

CREATE TABLE feeding_payments (
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
) ENGINE=InnoDB;

CREATE TABLE kitchen_inventory (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    item_name VARCHAR(120) NOT NULL,
    quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    item_date DATE NOT NULL,
    supplier VARCHAR(150) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE school_expenses (
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
) ENGINE=InnoDB;

CREATE TABLE transport_students (
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
) ENGINE=InnoDB;

CREATE TABLE transport_accounts (
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
) ENGINE=InnoDB;

CREATE TABLE transport_payments (
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
) ENGINE=InnoDB;

-- Required starter admin account only. No sample student or fee records are included.
-- Login: admin / admin123
INSERT INTO admin (name, username, password_hash) VALUES
('System Administrator', 'admin', '$2y$10$TQYvwEbuJWmhxM72Etq28uyX6x2ZcfHpBjnqkPNhHw0bmOM9cYEnK');

INSERT INTO academic_calendar (academic_year, term_name, start_date, end_date) VALUES
('2026', 'Term 2', '2026-05-01', '2026-08-31'),
('2026', 'Term 3', '2026-09-01', '2026-12-31');
