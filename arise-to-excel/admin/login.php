<?php
$pageTitle = 'Login';
require_once __DIR__ . '/../config/database.php';

if (is_admin_logged_in()) {
    redirect('admin/dashboard.php');
}

$error = flash('error');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    $statement = $pdo->prepare("SELECT * FROM admin WHERE username = :username LIMIT 1");
    $statement->execute(['username' => $username]);
    $admin = $statement->fetch();

    if ($admin && password_verify($password, $admin['password_hash'])) {
        $_SESSION['admin_id'] = $admin['id'];
        $_SESSION['admin_name'] = $admin['name'];
        redirect('admin/dashboard.php');
    }

    $error = 'Invalid username or password.';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Login | <?= APP_NAME ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="<?= asset('vendor/bootstrap/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= asset('css/dashboard.css') ?>">
</head>
<body class="login-page">
<main class="login-shell">
    <section class="login-hero" aria-label="Arise To Excel Fees">
        <div class="login-brand">
            <span class="login-brand-logo">
                <img src="<?= asset('images/school-logo.jpg') ?>" alt="Arise To Excel Academy logo">
            </span>
            <span>
                <strong>Arise To Excel Fees</strong>
                <small>School Command Center</small>
            </span>
        </div>

        <div class="login-hero-copy">
            <p class="eyebrow">Premium School ERP</p>
            <h1>Finance, students, and operations in one calm command center.</h1>
            <p>Track collections, balances, feeding, transport, reports, and daily expenses with a polished admin workspace.</p>
        </div>

        <div class="login-highlight-grid">
            <div class="login-highlight-card">
                <i class="fa-solid fa-wallet"></i>
                <strong>Fees</strong>
                <span>Collections and receipts</span>
            </div>
            <div class="login-highlight-card">
                <i class="fa-solid fa-users"></i>
                <strong>Students</strong>
                <span>Roster and imports</span>
            </div>
            <div class="login-highlight-card">
                <i class="fa-solid fa-chart-line"></i>
                <strong>Reports</strong>
                <span>Term analytics</span>
            </div>
        </div>
    </section>

    <section class="login-panel-area">
        <div class="login-card">
            <span class="login-card-icon"><i class="fa-solid fa-shield-halved"></i></span>
            <p class="eyebrow">Admin Access</p>
            <h1>Welcome back</h1>
            <p class="text-muted">Sign in to continue to the Arise To Excel dashboard.</p>
            <?php if ($error): ?>
                <div class="alert alert-danger"><?= h($error) ?></div>
            <?php endif; ?>
            <form method="post">
                <div class="mb-3 login-input-group">
                    <label class="form-label" for="username">Username</label>
                    <i class="fa-regular fa-user"></i>
                    <input class="form-control" id="username" name="username" value="<?= h($_POST['username'] ?? '') ?>" placeholder="Enter username" required>
                </div>
                <div class="mb-3 login-input-group">
                    <label class="form-label" for="password">Password</label>
                    <i class="fa-solid fa-lock"></i>
                    <input class="form-control" type="password" id="password" name="password" placeholder="Enter password" required>
                </div>
                <button class="btn btn-primary w-100" type="submit"><i class="fa-solid fa-arrow-right-to-bracket"></i>Log In</button>
            </form>
        </div>
    </section>
</main>
</body>
</html>
