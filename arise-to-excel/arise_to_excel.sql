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
DROP TABLE IF EXISTS academic_report_cards;
DROP TABLE IF EXISTS academic_student_results;
DROP TABLE IF EXISTS academic_subject_results;
DROP TABLE IF EXISTS academic_marks;
DROP TABLE IF EXISTS academic_exams;
DROP TABLE IF EXISTS academic_class_subjects;
DROP TABLE IF EXISTS academic_grading_scales;
DROP TABLE IF EXISTS academic_subjects;
DROP TABLE IF EXISTS academic_settings;
DROP TABLE IF EXISTS academic_calendar;
DROP TABLE IF EXISTS class_levels;
DROP TABLE IF EXISTS kitchen_inventory;
DROP TABLE IF EXISTS kitchen_daily_purchases;
DROP TABLE IF EXISTS weekly_shopping_items;
DROP TABLE IF EXISTS weekly_shopping;
DROP TABLE IF EXISTS school_expenses;
DROP TABLE IF EXISTS students;
DROP TABLE IF EXISTS admin;
DROP TABLE IF EXISTS uniform_sale_items;
DROP TABLE IF EXISTS uniform_sales;
DROP TABLE IF EXISTS uniform_stock_movements;
DROP TABLE IF EXISTS uniforms;
DROP TABLE IF EXISTS fuel_transactions;
DROP TABLE IF EXISTS vehicles;
DROP TABLE IF EXISTS user_permissions;
DROP TABLE IF EXISTS role_permissions;
DROP TABLE IF EXISTS user_roles;
DROP TABLE IF EXISTS roles;
DROP TABLE IF EXISTS permissions;

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
    status VARCHAR(20) NOT NULL DEFAULT 'Active',
    class_level VARCHAR(30) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE roles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    description TEXT,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE user_roles (
    user_id INT UNSIGNED NOT NULL,
    role_id INT UNSIGNED NOT NULL,
    assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, role_id),
    CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES admin(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE permissions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    permission_key VARCHAR(100) NOT NULL UNIQUE,
    label VARCHAR(150) NOT NULL,
    module VARCHAR(60) NOT NULL,
    action VARCHAR(80) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE role_permissions (
    role_id INT UNSIGNED NOT NULL,
    permission_id INT UNSIGNED NOT NULL,
    assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (role_id, permission_id),
    CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE user_permissions (
    user_id INT UNSIGNED NOT NULL,
    permission_id INT UNSIGNED NOT NULL,
    assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, permission_id),
    CONSTRAINT fk_user_permissions_user FOREIGN KEY (user_id) REFERENCES admin(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_user_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE ON UPDATE CASCADE
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

CREATE TABLE class_levels (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'Active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_class_levels_name (name)
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

CREATE TABLE academic_subjects (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    code VARCHAR(20) NULL,
    class_id INT UNSIGNED NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'Active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_academic_subject_name (name),
    KEY idx_academic_subjects_status (status),
    CONSTRAINT fk_academic_subjects_class FOREIGN KEY (class_id) REFERENCES class_levels(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE academic_class_subjects (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    class_level_id INT UNSIGNED NOT NULL,
    subject_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_academic_class_subject (class_level_id, subject_id),
    KEY idx_academic_class_subjects_subject (subject_id),
    CONSTRAINT fk_academic_class_subjects_class FOREIGN KEY (class_level_id) REFERENCES class_levels(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_academic_class_subjects_subject FOREIGN KEY (subject_id) REFERENCES academic_subjects(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE academic_exams (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    academic_year VARCHAR(4) NOT NULL,
    term VARCHAR(20) NOT NULL,
    exam_type VARCHAR(20) NOT NULL,
    exam_name VARCHAR(80) NOT NULL,
    max_marks DECIMAL(5,2) NOT NULL DEFAULT 100.00,
    status VARCHAR(20) NOT NULL DEFAULT 'Open',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_academic_exam_period_type (academic_year, term, exam_type),
    KEY idx_academic_exams_period (academic_year, term)
) ENGINE=InnoDB;

CREATE TABLE academic_grading_scales (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    min_score DECIMAL(5,2) NOT NULL,
    max_score DECIMAL(5,2) NOT NULL,
    grade VARCHAR(5) NOT NULL,
    remark VARCHAR(120) NULL,
    display_order INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_academic_grade_name (grade),
    KEY idx_academic_grading_range (min_score, max_score)
) ENGINE=InnoDB;

CREATE TABLE academic_marks (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id INT UNSIGNED NOT NULL,
    subject_id INT UNSIGNED NOT NULL,
    exam_id INT UNSIGNED NULL,
    academic_year VARCHAR(4) NOT NULL,
    term VARCHAR(20) NOT NULL,
    exam_type VARCHAR(20) NOT NULL,
    marks DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    remarks VARCHAR(255) NULL,
    recorded_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_academic_mark_entry (student_id, subject_id, academic_year, term, exam_type),
    UNIQUE KEY uniq_academic_mark_student_subject_exam (student_id, subject_id, exam_id),
    KEY idx_academic_marks_exam (exam_id),
    KEY idx_academic_marks_period (academic_year, term),
    CONSTRAINT fk_academic_marks_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_academic_marks_subject FOREIGN KEY (subject_id) REFERENCES academic_subjects(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_academic_marks_exam FOREIGN KEY (exam_id) REFERENCES academic_exams(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE academic_student_results (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id INT UNSIGNED NOT NULL,
    academic_year VARCHAR(4) NOT NULL,
    term VARCHAR(20) NOT NULL,
    class_level VARCHAR(30) NOT NULL,
    student_total DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    average DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    grade VARCHAR(5) NOT NULL DEFAULT 'E',
    class_position INT UNSIGNED NULL,
    subject_count INT UNSIGNED NOT NULL DEFAULT 0,
    marks_count INT UNSIGNED NOT NULL DEFAULT 0,
    calculated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_academic_student_result (student_id, academic_year, term),
    KEY idx_academic_student_results_period_class (academic_year, term, class_level),
    CONSTRAINT fk_academic_student_results_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE academic_subject_results (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id INT UNSIGNED NOT NULL,
    subject_id INT UNSIGNED NOT NULL,
    academic_year VARCHAR(4) NOT NULL,
    term VARCHAR(20) NOT NULL,
    class_level VARCHAR(30) NOT NULL,
    opening_marks DECIMAL(5,2) NULL,
    midterm_marks DECIMAL(5,2) NULL,
    closing_marks DECIMAL(5,2) NULL,
    subject_total DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    average DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    grade VARCHAR(5) NOT NULL DEFAULT 'E',
    subject_position INT UNSIGNED NULL,
    marks_count INT UNSIGNED NOT NULL DEFAULT 0,
    calculated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_academic_subject_result (student_id, subject_id, academic_year, term),
    KEY idx_academic_subject_results_period_subject (academic_year, term, subject_id),
    KEY idx_academic_subject_results_class_subject (academic_year, term, class_level, subject_id),
    CONSTRAINT fk_academic_subject_results_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_academic_subject_results_subject FOREIGN KEY (subject_id) REFERENCES academic_subjects(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE academic_report_cards (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id INT UNSIGNED NOT NULL,
    academic_year VARCHAR(4) NOT NULL,
    term VARCHAR(20) NOT NULL,
    teacher_comment TEXT NULL,
    head_teacher_comment TEXT NULL,
    principal_comment TEXT NULL,
    generated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_academic_report_card (student_id, academic_year, term),
    CONSTRAINT fk_academic_report_card_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE ON UPDATE CASCADE
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
    unit VARCHAR(30) NOT NULL DEFAULT 'kg',
    unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    item_date DATE NOT NULL,
    supplier VARCHAR(150) NULL,
    purchase_type VARCHAR(30) NOT NULL DEFAULT 'weekly',
    category VARCHAR(80) NOT NULL DEFAULT 'Kitchen',
    academic_year VARCHAR(4) NOT NULL DEFAULT '',
    term VARCHAR(20) NOT NULL DEFAULT '',
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
    academic_year VARCHAR(4) NOT NULL DEFAULT '',
    term VARCHAR(20) NOT NULL DEFAULT '',
    description TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE kitchen_daily_purchases (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    item_name VARCHAR(120) NOT NULL,
    quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    unit VARCHAR(30) NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    category VARCHAR(80) NOT NULL DEFAULT 'Daily',
    supplier VARCHAR(150) NULL,
    notes TEXT NULL,
    purchase_date DATE NOT NULL,
    academic_year VARCHAR(4) NOT NULL DEFAULT '',
    term VARCHAR(20) NOT NULL DEFAULT '',
    payment_method VARCHAR(80) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE weekly_shopping (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier VARCHAR(150) NULL,
    shopping_date DATE NOT NULL,
    total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE weekly_shopping_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    weekly_shopping_id INT UNSIGNED NOT NULL,
    item_name VARCHAR(120) NOT NULL,
    quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    unit VARCHAR(30) NULL,
    unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_weekly_shopping_items_weekly FOREIGN KEY (weekly_shopping_id) REFERENCES weekly_shopping(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE transport_students (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id INT UNSIGNED NULL,
    student_name VARCHAR(150) NOT NULL,
    school_name VARCHAR(150) NULL,
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

CREATE TABLE transport_fee_structures (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    location_name VARCHAR(150) NOT NULL,
    fee_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    academic_year VARCHAR(4) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'Active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_transport_fee_location (location_name, academic_year)
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

-- School Uniform tables
CREATE TABLE uniforms (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uniform_name VARCHAR(150) NOT NULL,
    category VARCHAR(60) NOT NULL,
    gender VARCHAR(10) NOT NULL,
    size VARCHAR(30) NOT NULL,
    selling_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    opening_stock INT NOT NULL DEFAULT 0,
    reorder_level INT NOT NULL DEFAULT 0,
    status VARCHAR(20) NOT NULL DEFAULT 'Active',
    description TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE uniform_stock_movements (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uniform_id INT UNSIGNED NOT NULL,
    movement_type VARCHAR(30) NOT NULL,
    quantity INT NOT NULL,
    reference_id INT UNSIGNED NULL,
    note TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_uniform_stock_movements_uniform FOREIGN KEY (uniform_id) REFERENCES uniforms(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE uniform_sales (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id INT UNSIGNED NULL,
    receipt_no VARCHAR(80) NOT NULL UNIQUE,
    subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    discount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    grand_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    payment_method VARCHAR(30) NOT NULL DEFAULT 'Cash',
    mpesa_code VARCHAR(30) NOT NULL DEFAULT '',
    served_by INT UNSIGNED NULL,
    payment_date DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_uniform_sales_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE uniform_sale_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sale_id INT UNSIGNED NOT NULL,
    uniform_id INT UNSIGNED NOT NULL,
    size VARCHAR(30) NOT NULL,
    quantity INT NOT NULL,
    unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    line_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_uniform_sale_items_sale FOREIGN KEY (sale_id) REFERENCES uniform_sales(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_uniform_sale_items_uniform FOREIGN KEY (uniform_id) REFERENCES uniforms(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

-- School Van Fuel tables
CREATE TABLE vehicles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vehicle_name VARCHAR(100) NOT NULL,
    registration_no VARCHAR(60) NULL,
    driver_name VARCHAR(120) NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE fuel_transactions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vehicle_id INT UNSIGNED NOT NULL,
    fuel_date DATE NOT NULL,
    fuel_type VARCHAR(60) NOT NULL,
    litres DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    cost_per_litre DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    fuel_station VARCHAR(150) NULL,
    receipt_no VARCHAR(80) NULL,
    notes TEXT NULL,
    recorded_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_fuel_transactions_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

-- Required starter admin account only. No sample student or fee records are included.
-- Login: admin / admin123
INSERT INTO admin (name, username, password_hash) VALUES
('System Administrator', 'admin', '$2y$10$TQYvwEbuJWmhxM72Etq28uyX6x2ZcfHpBjnqkPNhHw0bmOM9cYEnK');

INSERT INTO class_levels (name) VALUES
('Playgroup'), ('PP1'), ('PP2'), ('Grade 1'), ('Grade 2'), ('Grade 3'), ('Grade 4'), ('Grade 5'), ('Grade 6');

INSERT INTO academic_calendar (academic_year, term_name, start_date, end_date) VALUES
('2026', 'Term 1', '2026-01-01', '2026-04-30'),
('2026', 'Term 2', '2026-05-01', '2026-08-31'),
('2026', 'Term 3', '2026-09-01', '2026-12-31');

INSERT INTO academic_grading_scales (min_score, max_score, grade, remark, display_order) VALUES
(80, 100, 'A', 'Excellent', 1),
(70, 79, 'B', 'Very Good', 2),
(60, 69, 'C', 'Good', 3),
(50, 59, 'D', 'Fair', 4),
(0, 49, 'E', 'Needs Support', 5);

INSERT INTO academic_exams (academic_year, term, exam_type, exam_name, max_marks, status) VALUES
('2026', 'Term 1', 'Opening', 'Opening Exam', 100.00, 'Open'),
('2026', 'Term 1', 'Midterm', 'Midterm Exam', 100.00, 'Open'),
('2026', 'Term 1', 'Closing', 'Closing Exam', 100.00, 'Open'),
('2026', 'Term 2', 'Opening', 'Opening Exam', 100.00, 'Open'),
('2026', 'Term 2', 'Midterm', 'Midterm Exam', 100.00, 'Open'),
('2026', 'Term 2', 'Closing', 'Closing Exam', 100.00, 'Open'),
('2026', 'Term 3', 'Opening', 'Opening Exam', 100.00, 'Open'),
('2026', 'Term 3', 'Midterm', 'Midterm Exam', 100.00, 'Open'),
('2026', 'Term 3', 'Closing', 'Closing Exam', 100.00, 'Open');
