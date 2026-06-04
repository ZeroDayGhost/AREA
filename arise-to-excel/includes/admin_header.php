<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/fee_helpers.php';

$pageTitle = $pageTitle ?? 'Admin';
$currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
$adminName = $_SESSION['admin_name'] ?? 'Admin';
$adminInitials = strtoupper(substr(trim($adminName), 0, 1) ?: 'A');
$headerContext = null;

try {
    if (isset($pdo) && $pdo instanceof PDO) {
        $headerContext = current_academic_context($pdo);
    }
} catch (Throwable $exception) {
    $headerContext = null;
}

$currentTermLabel = $headerContext
    ? $headerContext['academic_year'] . ' - ' . $headerContext['term']
    : date('Y') . ' - Term';

$navItems = [
    ['label' => 'Dashboard', 'href' => 'admin/dashboard.php', 'icon' => 'fa-gauge-high', 'matches' => ['dashboard.php']],
    ['label' => 'Students', 'href' => 'admin/students.php', 'icon' => 'fa-user-graduate', 'matches' => ['students.php', 'student_form.php', 'import_students.php']],
    ['label' => 'Fee Structure', 'href' => 'admin/fee_structures.php', 'icon' => 'fa-file-invoice-dollar', 'matches' => ['fee_structures.php']],
    ['label' => 'Fees', 'href' => 'admin/fees.php', 'icon' => 'fa-wallet', 'matches' => ['fees.php', 'receipt.php']],
    ['label' => 'Feeding', 'href' => 'admin/feeding.php', 'icon' => 'fa-utensils', 'matches' => ['feeding.php']],
    ['label' => 'Kitchen', 'href' => 'admin/kitchen_inventory.php', 'icon' => 'fa-kitchen-set', 'matches' => ['kitchen_inventory.php']],
    ['label' => 'Expenses', 'href' => 'admin/school_expenses.php', 'icon' => 'fa-coins', 'matches' => ['school_expenses.php']],
    ['label' => 'Transport', 'href' => 'admin/transport.php', 'icon' => 'fa-bus', 'matches' => ['transport.php']],
    ['label' => 'Reports', 'href' => 'admin/reports.php', 'icon' => 'fa-chart-line', 'matches' => ['reports.php', 'reports_export.php']],
    ['label' => 'Academic', 'href' => 'admin/academic_calendar.php', 'icon' => 'fa-book-open', 'matches' => ['academic_calendar.php']],
    ['label' => 'Settings', 'href' => 'admin/settings.php', 'icon' => 'fa-gear', 'matches' => ['settings.php']],
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($pageTitle) ?> | <?= APP_NAME ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="<?= asset('vendor/bootstrap/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= asset_version('css/dashboard.css') ?>">
</head>
<body class="admin-body">
<div class="sidebar-backdrop" data-sidebar-close></div>
<div class="admin-layout">
    <aside class="admin-sidebar" aria-label="Admin navigation">
        <a class="sidebar-brand" href="<?= url('admin/dashboard.php') ?>">
            <span class="sidebar-logo-wrap">
                <img class="sidebar-logo" src="<?= asset('images/school-logo.jpg') ?>" alt="Arise To Excel Academy logo">
            </span>
            <span>
                <strong>Arise To Excel Fees</strong>
                <small>School Command Center</small>
            </span>
        </a>

        <nav class="sidebar-nav">
            <?php foreach ($navItems as $item): ?>
                <?php $isActive = in_array($currentScript, $item['matches'], true); ?>
                <a class="sidebar-link <?= $isActive ? 'active' : '' ?>" href="<?= url($item['href']) ?>">
                    <i class="fa-solid <?= h($item['icon']) ?>"></i>
                    <span><?= h($item['label']) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="sidebar-footer">
            <div class="sidebar-admin-card">
                <div class="sidebar-avatar"><?= h($adminInitials) ?></div>
                <div>
                    <strong><?= h($adminName) ?></strong>
                    <span><i class="status-dot"></i> Online</span>
                </div>
            </div>
            <a class="sidebar-link sidebar-logout" href="<?= url('admin/logout.php') ?>">
                <i class="fa-solid fa-arrow-right-from-bracket"></i>
                <span>Logout</span>
            </a>
        </div>
    </aside>

    <div class="dashboard-main">
        <header class="dashboard-header">
            <button class="sidebar-toggle" type="button" aria-label="Toggle sidebar" data-sidebar-toggle>
                <i class="fa-solid fa-bars"></i>
            </button>

            <form class="dashboard-search" action="<?= url('admin/students.php') ?>" method="get" role="search">
                <button class="dashboard-search-button" type="submit" aria-label="Search">
                    <i class="fa-solid fa-magnifying-glass"></i>
                </button>
                <input type="search" name="search" placeholder="Search students, payments, classes..." aria-label="Search students">
            </form>

            <div class="header-actions">
                <div class="dropdown term-dropdown" data-header-dropdown>
                    <button class="term-button" type="button" aria-haspopup="true" aria-expanded="false" data-header-dropdown-toggle>
                        <i class="fa-regular fa-calendar"></i>
                        <span><?= h($currentTermLabel) ?></span>
                        <i class="fa-solid fa-chevron-down"></i>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end modern-dropdown">
                        <a class="dropdown-item" href="<?= url('admin/academic_calendar.php') ?>">Manage academic calendar</a>
                        <a class="dropdown-item" href="<?= url('admin/reports.php') ?>">View term reports</a>
                    </div>
                </div>

                <div class="dropdown header-notifications" data-header-dropdown>
                    <button class="header-icon-button" type="button" aria-label="Notifications" aria-haspopup="true" aria-expanded="false" data-header-dropdown-toggle>
                        <i class="fa-regular fa-bell"></i>
                        <span class="notification-badge">3</span>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end modern-dropdown notification-menu">
                        <a class="dropdown-item" href="<?= url('admin/reports.php') ?>">Reports dashboard</a>
                        <a class="dropdown-item" href="<?= url('admin/fees.php') ?>">Record fee payment</a>
                        <a class="dropdown-item" href="<?= url('admin/academic_calendar.php') ?>">Academic calendar</a>
                    </div>
                </div>

                <a class="header-icon-button" href="<?= url('admin/settings.php') ?>" aria-label="Settings">
                    <i class="fa-solid fa-gear"></i>
                </a>

                <div class="dropdown admin-profile" data-header-dropdown>
                    <button class="profile-button" type="button" aria-haspopup="true" aria-expanded="false" data-header-dropdown-toggle>
                        <span class="profile-avatar"><?= h($adminInitials) ?></span>
                        <span class="profile-name"><?= h($adminName) ?></span>
                        <i class="fa-solid fa-chevron-down"></i>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end modern-dropdown">
                        <a class="dropdown-item" href="<?= url('admin/settings.php') ?>">Settings</a>
                        <a class="dropdown-item" href="<?= url('admin/logout.php') ?>">Logout</a>
                    </div>
                </div>
            </div>
        </header>

        <main class="container-fluid admin-shell">
