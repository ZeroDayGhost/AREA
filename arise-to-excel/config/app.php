<?php
// Shared settings and helpers for the XAMPP fee management system.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Africa/Nairobi');

$scriptDirectory = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
$basePath = preg_replace('#/admin$#', '', $scriptDirectory);
$basePath = ($basePath === '/' || $basePath === '\\') ? '' : rtrim($basePath, '/');

if ($basePath !== '') {
    $segments = explode('/', trim($basePath, '/'));
    $segments = array_map(function ($segment) {
        return rawurlencode(rawurldecode($segment));
    }, $segments);
    $basePath = '/' . implode('/', $segments);
}

define('APP_NAME', 'Arise To Excel Fee Management');
define('SCHOOL_NAME', 'Arise To Excel Academy');
define('SCHOOL_ADDRESS', 'P.O Box 00629, Nairobi, Kenya');
define('SCHOOL_PHONE', '+254 116 004005');
define('SCHOOL_EMAIL', 'arisetoexcel@gmail.com');
define('BASE_URL', $basePath);

function url(string $path = ''): string
{
    return BASE_URL . '/' . ltrim($path, '/');
}

function asset(string $path): string
{
    return url('assets/' . ltrim($path, '/'));
}

function asset_version(string $path): string
{
    $cleanPath = ltrim($path, '/');
    $filePath = dirname(__DIR__) . '/assets/' . $cleanPath;
    $version = is_file($filePath) ? filemtime($filePath) : time();

    return asset($cleanPath) . '?v=' . rawurlencode((string) $version);
}

function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function money(float $amount): string
{
    return 'KES ' . number_format($amount, 2);
}

function redirect(string $path): void
{
    header('Location: ' . url($path));
    exit;
}

function is_admin_logged_in(): bool
{
    return isset($_SESSION['admin_id']);
}

function flash(string $key, ?string $message = null): ?string
{
    if ($message !== null) {
        $_SESSION['flash'][$key] = $message;
        return null;
    }

    if (!isset($_SESSION['flash'][$key])) {
        return null;
    }

    $message = $_SESSION['flash'][$key];
    unset($_SESSION['flash'][$key]);
    return $message;
}
