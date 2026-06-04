<?php
require_once __DIR__ . '/../config/database.php';

// Every admin page includes this file to enforce session-based login.
if (!is_admin_logged_in()) {
    flash('error', 'Please log in to continue.');
    redirect('admin/login.php');
}
