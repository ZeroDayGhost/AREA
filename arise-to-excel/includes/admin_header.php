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
        $lowStockUniforms = low_stock_uniforms($pdo);
        // Use global low-stock computation so notifications persist until addressed,
        // regardless of the currently selected academic term/year.
        $lowStockKitchen = function_exists('low_stock_kitchen_items_all') ? low_stock_kitchen_items_all($pdo) : low_stock_kitchen_items($pdo);
        $lowStock = array_merge($lowStockUniforms ?: [], $lowStockKitchen ?: []);
    }
} catch (Throwable $exception) {
    $headerContext = null;
}

$currentTermLabel = $headerContext
    ? $headerContext['academic_year'] . ' - ' . $headerContext['term']
    : date('Y') . ' - Term';

$navItems = [
    ['label' => 'Dashboard', 'href' => 'admin/dashboard.php', 'icon' => 'fa-gauge-high', 'matches' => ['dashboard.php'], 'module' => 'Dashboard'],
    ['label' => 'Students', 'href' => 'admin/students.php', 'icon' => 'fa-user-graduate', 'matches' => ['students.php', 'student_form.php', 'import_students.php'], 'module' => 'Students'],
    ['label' => 'Fees', 'href' => 'admin/fees.php', 'icon' => 'fa-wallet', 'matches' => ['fees.php', 'receipt.php'], 'module' => 'Fees'],
    ['label' => 'Transport Fee Structure', 'href' => 'admin/transport_fee_structures.php', 'icon' => 'fa-route', 'matches' => ['transport_fee_structures.php'], 'module' => 'Transport Fee Structure'],
    ['label' => 'Transport', 'href' => 'admin/transport.php', 'icon' => 'fa-bus', 'matches' => ['transport.php', 'transport_receipt.php'], 'module' => 'Transport'],
    ['label' => 'Feeding', 'href' => 'admin/feeding.php', 'icon' => 'fa-utensils', 'matches' => ['feeding.php', 'feeding_receipt.php'], 'module' => 'Feeding'],
    ['label' => 'School Uniform', 'href' => 'admin/uniforms.php', 'icon' => 'fa-shirt', 'matches' => ['uniforms.php', 'uniform_form.php', 'uniform_sales.php', 'uniform_reports.php', 'uniform_stock.php', 'uniform_receipt.php'], 'module' => 'School Uniform'],
    ['label' => 'School Van Fuel', 'href' => 'admin/fuel.php', 'icon' => 'fa-gas-pump', 'matches' => ['fuel.php', 'fuel_form.php', 'fuel_reports.php', 'vehicles.php'], 'module' => 'School Van Fuel'],
    ['label' => 'Kitchen', 'href' => 'admin/kitchen_inventory.php', 'icon' => 'fa-kitchen-set', 'matches' => ['kitchen_inventory.php', 'kitchen_usage.php', 'kitchen_reports.php', 'kitchen_consumption_report.php', 'kitchen_weekly_shopping.php', 'kitchen_daily_purchase.php', 'kitchen_items.php'], 'module' => 'Kitchen'],
    ['label' => 'Expenses', 'href' => 'admin/school_expenses.php', 'icon' => 'fa-coins', 'matches' => ['school_expenses.php'], 'module' => 'Expenses'],
    ['label' => 'Reports', 'href' => 'admin/reports.php', 'icon' => 'fa-chart-line', 'matches' => ['reports.php', 'reports_export.php'], 'module' => 'Reports'],
    ['label' => 'Settings', 'href' => 'admin/settings.php', 'icon' => 'fa-gear', 'matches' => ['settings.php', 'fee_structures.php', 'users_permissions.php', 'role_permissions.php', 'user_permissions_detail.php'], 'module' => 'Settings'],
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
    <link rel="stylesheet" href="<?= asset_version('css/receipt.css') ?>">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
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
                <strong>Arise To Excel Academy</strong>
                <small>School Command Center</small>
            </span>
        </a>

        <nav class="sidebar-nav">
            <?php foreach ($navItems as $item): ?>
                <?php 
                    $isActive = in_array($currentScript, $item['matches'], true);
                    $hasPermission = !isset($item['module']) || (isset($pdo) && current_admin_can_access_module($pdo, $item['module']));
                ?>
                <?php if ($hasPermission): ?>
                    <a class="sidebar-link <?= $isActive ? 'active' : '' ?>" href="<?= url($item['href']) ?>">
                        <i class="fa-solid <?= h($item['icon']) ?>"></i>
                        <span><?= h($item['label']) ?></span>
                    </a>
                <?php endif; ?>
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

            <?php if (isset($pdo) && current_admin_can_access_module($pdo, 'Students')): ?>
                <form class="dashboard-search" action="<?= url('admin/students.php') ?>" method="get" role="search">
                    <button class="dashboard-search-button" type="submit" aria-label="Search">
                        <i class="fa-solid fa-magnifying-glass"></i>
                    </button>
                    <input type="search" name="search" placeholder="Search students, payments, classes..." aria-label="Search students">
                </form>
            <?php else: ?>
                <div class="dashboard-search" aria-hidden="true"></div>
            <?php endif; ?>

            <div class="header-actions">
                <div class="dropdown term-dropdown" data-header-dropdown>
                    <button class="term-button" type="button" aria-haspopup="true" aria-expanded="false" data-header-dropdown-toggle>
                        <i class="fa-regular fa-calendar"></i>
                        <span><?= h($currentTermLabel) ?></span>
                        <i class="fa-solid fa-chevron-down"></i>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end modern-dropdown">
                        <?php if (isset($pdo) && current_admin_can_access_module($pdo, 'Academic Calendar')): ?>
                            <a class="dropdown-item" href="<?= url('admin/academic_calendar.php') ?>">Manage academic calendar</a>
                        <?php endif; ?>
                        <?php if (isset($pdo) && current_admin_can_access_module($pdo, 'Reports')): ?>
                            <a class="dropdown-item" href="<?= url('admin/reports.php') ?>">View term reports</a>
                        <?php endif; ?>
                    </div>
                </div>

                <?php
                    $currentAdminRoles = [];
                    if (isset($pdo) && function_exists('current_admin_roles')) {
                        $currentAdminRoles = current_admin_roles($pdo);
                    }
                    $showNotifications = false;
                    foreach ($currentAdminRoles as $roleName) {
                        $lowerRole = mb_strtolower(trim($roleName));
                        if (in_array($lowerRole, ['secretary', 'director'], true)) {
                            $showNotifications = true;
                            break;
                        }
                    }
                ?>
                <?php if ($showNotifications): ?>
                    <div class="dropdown header-notifications" data-header-dropdown>
                        <button class="header-icon-button" type="button" aria-label="Notifications" aria-haspopup="true" aria-expanded="false" data-header-dropdown-toggle>
                            <i class="fa-regular fa-bell"></i>
                            <span class="notification-badge"><?= isset($lowStock) && count($lowStock) > 0 ? count($lowStock) : 0 ?></span>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end modern-dropdown notification-menu">
                            <?php if (isset($lowStock) && count($lowStock) > 0): ?>
                                <div class="dropdown-divider"></div>
                                <?php foreach ($lowStock as $ls): ?>
                                    <?php
                                        $isCritical = false;
                                        if (isset($ls['item_name'])) {
                                            $isCritical = (float)$ls['remaining_stock'] <= 0;
                                        } elseif (isset($ls['uniform_name'])) {
                                            $isCritical = (int)$ls['available_stock'] <= 0;
                                        }
                                        $alertClass = $isCritical ? 'text-danger' : 'text-warning';
                                        $alertPrefix = $isCritical ? 'CRITICAL: Out of stock - ' : 'Low stock: ';
                                    ?>
                                    <?php if (isset($ls['uniform_name'])): ?>
                                        <a class="dropdown-item <?= $alertClass ?>" href="<?= url('admin/uniform_stock_form.php?id=' . $ls['id']) ?>"><?= $alertPrefix ?><?= h($ls['uniform_name']) ?> (<?= (int)$ls['available_stock'] ?> left)</a>
                                    <?php elseif (isset($ls['item_name'])): ?>
                                        <a class="dropdown-item <?= $alertClass ?>" href="<?= url('admin/kitchen_inventory.php?search=' . urlencode($ls['item_name'])) ?>"><?= $alertPrefix ?><?= h($ls['item_name']) ?> (<?= h((string)$ls['remaining_stock']) ?> left)</a>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="dropdown-item text-muted">No notifications</div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (isset($pdo) && current_admin_can_access_module($pdo, 'Settings')): ?>
                    <a class="header-icon-button" href="<?= url('admin/settings.php') ?>" aria-label="Settings">
                        <i class="fa-solid fa-gear"></i>
                    </a>
                <?php endif; ?>

                <div class="dropdown admin-profile" data-header-dropdown>
                    <button class="profile-button" type="button" aria-haspopup="true" aria-expanded="false" data-header-dropdown-toggle>
                        <span class="profile-avatar"><?= h($adminInitials) ?></span>
                        <span class="profile-name"><?= h($adminName) ?></span>
                        <i class="fa-solid fa-chevron-down"></i>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end modern-dropdown">
                        <a class="dropdown-item" href="<?= url('admin/profile.php') ?>">My Profile</a>
                        <a class="dropdown-item" href="<?= url('admin/profile.php#change-password') ?>">Change My Password</a>
                        <?php if (isset($pdo) && current_admin_can_access_module($pdo, 'Settings')): ?>
                            <a class="dropdown-item" href="<?= url('admin/settings.php') ?>">Settings</a>
                        <?php endif; ?>
                        <a class="dropdown-item" href="<?= url('admin/logout.php') ?>">Logout</a>
                    </div>
                </div>
            </div>
        </header>

        <main class="container-fluid admin-shell">
            <?php if ($msg = flash('success')): ?>
                <div class="alert alert-success" style="margin:12px 0"><?= h($msg) ?></div>
            <?php endif; ?>
            <?php if ($msg = flash('error')): ?>
                <div class="alert alert-danger" style="margin:12px 0"><?= h($msg) ?></div>
            <?php endif; ?>
