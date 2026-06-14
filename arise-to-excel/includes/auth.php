<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/fee_helpers.php';

// Every admin page includes this file to enforce session-based login.
if (!is_admin_logged_in()) {
    flash('error', 'Please log in to continue.');
    redirect('admin/login.php');
}

if (!current_admin_is_active($pdo)) {
    session_destroy();
    flash('error', 'Your account is inactive. Please contact the administrator.');
    redirect('admin/login.php');
}

rbac_enforce_page_access($pdo);
