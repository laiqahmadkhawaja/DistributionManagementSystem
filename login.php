<?php
/**
 * User Authentication / Login
 * Enterprise Distribution Management System (DMS)
 */

require_once __DIR__ . '/config/app.php';

// Redirect if already logged in
if (isLoggedIn()) {
    redirect(BASE_URL . '/dashboard.php');
}

$error = null;

// Process POST Login Action before any HTML output
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($username) || empty($password)) {
        $error = "Please enter both username and password.";
    } else {
        $result = loginUser($username, $password);
        if ($result['success']) {
            setFlashMessage('success', "Welcome back, {$result['user']['full_name']}!");
            redirect(BASE_URL . '/dashboard.php');
        } else {
            $error = $result['message'];
        }
    }
}

$pageTitle = 'Sign In';
$company = getCompanyInfo(getDatabaseConnection());
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Sign In - <?= htmlspecialchars($company['name'] ?? 'Falcon DMS') ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/vendor/bootstrap-icons/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app.css">
</head>
<body class="d-flex align-items-center justify-content-center bg-light" style="min-height: 100vh; padding: 20px 10px;">

<div class="card shadow-sm border-0" style="max-width: 440px; width: 100%; border-radius: 16px;">
    <div class="card-body p-4 p-md-5">
        <div class="text-center mb-4">
            <div class="d-inline-flex align-items-center justify-content-center bg-primary text-white rounded-circle mb-3" style="width: 64px; height: 64px;">
                <i class="bi bi-truck fs-2"></i>
            </div>
            <h4 class="fw-bold mb-1 text-dark"><?= htmlspecialchars($company['name'] ?? 'Falcon DMS') ?></h4>
            <p class="text-muted small">Enterprise Distribution Management System</p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger d-flex align-items-center py-2 px-3 mb-3 rounded-3" role="alert">
                <i class="bi bi-exclamation-octagon-fill me-2 fs-5"></i>
                <div class="small"><?= htmlspecialchars($error) ?></div>
            </div>
        <?php endif; ?>

        <form method="POST" action="<?= BASE_URL ?>/login.php" autocomplete="off">
            <?= getCsrfField() ?>

            <div class="mb-3">
                <label for="username" class="form-label small fw-semibold text-secondary">Username or Email</label>
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-person text-muted"></i></span>
                    <input type="text" class="form-control border-start-0" id="username" name="username" placeholder="e.g. admin" required autofocus>
                </div>
            </div>

            <div class="mb-4">
                <label for="password" class="form-label small fw-semibold text-secondary">Password</label>
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-lock text-muted"></i></span>
                    <input type="password" class="form-control border-start-0" id="password" name="password" placeholder="••••••••" required>
                </div>
            </div>

            <button type="submit" class="btn btn-primary w-100 btn-touch shadow-sm mb-4">
                <i class="bi bi-box-arrow-in-right me-1"></i> Sign In to DMS
            </button>
        </form>

        <!-- Quick One-Click Demo Role Logins for Testing -->
        <div class="border-top pt-3 text-center">
            <div class="text-muted small fw-semibold mb-2">Quick Role Fill for Testing:</div>
            <div class="d-flex flex-wrap gap-1 justify-content-center">
                <button type="button" class="btn btn-sm btn-outline-secondary demo-fill" data-user="admin" data-pass="Password@123">Admin</button>
                <button type="button" class="btn btn-sm btn-outline-primary demo-fill" data-user="booker1" data-pass="Password@123">Order Booker</button>
                <button type="button" class="btn btn-sm btn-outline-success demo-fill" data-user="delivery1" data-pass="Password@123">Delivery Man</button>
                <button type="button" class="btn btn-sm btn-outline-warning demo-fill" data-user="store1" data-pass="Password@123">Storekeeper</button>
                <button type="button" class="btn btn-sm btn-outline-info demo-fill" data-user="accounts1" data-pass="Password@123">Accountant</button>
            </div>
            <div class="text-muted mt-2" style="font-size: 0.75rem;">Default Demo Password: <code>Password@123</code></div>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('.demo-fill').forEach(function(btn) {
    btn.addEventListener('click', function() {
        document.getElementById('username').value = this.getAttribute('data-user');
        document.getElementById('password').value = this.getAttribute('data-pass');
    });
});
</script>
</body>
</html>
