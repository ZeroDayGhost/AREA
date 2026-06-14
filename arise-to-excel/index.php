<?php
require_once __DIR__ . '/config/app.php';

if (is_admin_logged_in()) {
    redirect('admin/dashboard.php');
}

redirect('admin/login.php');
