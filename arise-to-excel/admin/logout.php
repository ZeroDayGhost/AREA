<?php
require_once __DIR__ . '/../config/app.php';

$_SESSION = [];
session_destroy();
redirect('admin/login.php');
