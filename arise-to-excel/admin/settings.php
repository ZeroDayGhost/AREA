<?php
$pageTitle = 'Settings';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';

$currentContext = current_academic_context($pdo);
$totalStudents = (int) $pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();
$feeStructures = (int) $pdo->query("SELECT COUNT(*) FROM fee_structures")->fetchColumn();
$calendarTerms = (int) $pdo->query("SELECT COUNT(*) FROM academic_calendar")->fetchColumn();
$expenseCategories = count(expense_category_options());

require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title">
    <div>
        <p class="eyebrow">System Settings</p>
        <h1>Settings</h1>
        <p class="mb-0 text-muted">Manage the academic period, fee setup, imports, and operational controls.</p>
    </div>
    <div class="action-row">
        <a class="btn btn-outline-primary" href="<?= url('admin/academic_calendar.php') ?>"><i class="fa-regular fa-calendar"></i>Academic Calendar</a>
        <a class="btn btn-primary" href="<?= url('admin/fee_structures.php') ?>"><i class="fa-solid fa-file-invoice-dollar"></i>Fee Structure</a>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-4">
        <section class="panel">
            <div class="panel-heading">
                <h2>Active Academic Period</h2>
            </div>
            <div class="row g-3">
                <div class="col-sm-6 col-lg-12">
                    <div class="mini-stat">
                        <span>Academic Year</span>
                        <strong><?= h($currentContext['academic_year']) ?></strong>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-12">
                    <div class="mini-stat">
                        <span>Current Term</span>
                        <strong><?= h($currentContext['term']) ?></strong>
                    </div>
                </div>
                <div class="col-12">
                    <div class="mini-stat">
                        <span>System Date</span>
                        <strong><?= h($currentContext['today']) ?></strong>
                    </div>
                </div>
            </div>
            <a class="btn btn-outline-primary w-100 mt-3" href="<?= url('admin/academic_calendar.php') ?>">Update Academic Period</a>
        </section>
    </div>

    <div class="col-lg-8">
        <section class="panel">
            <div class="panel-heading">
                <h2>Configuration Overview</h2>
            </div>
            <div class="row g-3">
                <div class="col-md-3 col-sm-6">
                    <div class="mini-stat"><span>Students</span><strong><?= $totalStudents ?></strong></div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="mini-stat"><span>Fee Structures</span><strong><?= $feeStructures ?></strong></div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="mini-stat"><span>Calendar Terms</span><strong><?= $calendarTerms ?></strong></div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="mini-stat"><span>Expense Types</span><strong><?= $expenseCategories ?></strong></div>
                </div>
            </div>
        </section>

        <section class="panel mt-4">
            <div class="panel-heading">
                <h2>Administration Shortcuts</h2>
            </div>
            <div class="quick-actions-grid">
                <a class="quick-action-card action-blue" href="<?= url('admin/import_students.php') ?>">
                    <span class="quick-action-icon"><i class="fa-solid fa-file-import"></i></span>
                    <span>Import Students</span>
                </a>
                <a class="quick-action-card action-green" href="<?= url('admin/students.php') ?>">
                    <span class="quick-action-icon"><i class="fa-solid fa-users"></i></span>
                    <span>Student Roster</span>
                </a>
                <a class="quick-action-card action-purple" href="<?= url('admin/reports.php') ?>">
                    <span class="quick-action-icon"><i class="fa-solid fa-chart-pie"></i></span>
                    <span>Reports</span>
                </a>
                <a class="quick-action-card action-orange" href="<?= url('admin/school_expenses.php') ?>">
                    <span class="quick-action-icon"><i class="fa-solid fa-receipt"></i></span>
                    <span>Expenses</span>
                </a>
            </div>
        </section>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>
