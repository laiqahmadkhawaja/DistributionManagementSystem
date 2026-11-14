<?php
/**
 * Global Header
 * Enterprise Distribution Management System (DMS)
 */

if (!defined('DMS_INIT')) {
    require_once dirname(__DIR__) . '/config/app.php';
}

$currentUser = getCurrentUser();
$company = getCompanyInfo(getDatabaseConnection());
$pageTitle = $pageTitle ?? 'Dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#0d6efd">
    <title><?= htmlspecialchars($pageTitle) ?> - <?= htmlspecialchars($company['name'] ?? APP_NAME) ?></title>
    
    <!-- Local Vendor CSS (Zero External CDN Dependency for Offline/WebView) -->
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/vendor/bootstrap-icons/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app.css">
    <script>window.DMS_BASE_URL = "<?= BASE_URL ?>";</script>
</head>
<body>

<!-- Top Navigation Bar -->
<header class="navbar navbar-expand-lg navbar-dms sticky-top px-3 py-2">
    <div class="container-fluid p-0">
        <a class="navbar-brand d-flex align-items-center me-3" href="<?= BASE_URL ?>/dashboard.php">
            <span class="badge bg-primary me-2 p-2 rounded-3"><i class="bi bi-truck fs-5"></i></span>
            <div>
                <div class="brand-text fs-6 lh-1"><?= htmlspecialchars($company['name'] ?? APP_NAME) ?></div>
                <small class="text-muted" style="font-size: 0.7rem;">Enterprise DMS · <?= htmlspecialchars($company['city'] ?? 'Gujranwala') ?></small>
            </div>
        </a>

        <!-- Network & Sync Status Header Badge -->
        <div class="d-flex align-items-center ms-auto gap-2">
            <span id="dms-network-pill" class="sync-indicator-banner sync-online">
                <i class="bi bi-wifi"></i> Online
            </span>

            <button id="dms-sync-btn" class="btn btn-sm btn-outline-primary d-flex align-items-center" title="Sync Offline Data">
                <i class="bi bi-arrow-repeat me-1"></i>
                <span class="d-none d-sm-inline">Sync</span>
                <span id="dms-sync-badge" class="badge bg-danger rounded-pill ms-1 d-none">0</span>
            </button>

            <?php if ($currentUser): ?>
            <div class="dropdown">
                <button class="btn btn-sm btn-light border dropdown-toggle d-flex align-items-center" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-person-circle fs-5 me-1 text-primary"></i>
                    <span class="d-none d-md-inline fw-semibold me-1"><?= htmlspecialchars($currentUser['full_name']) ?></span>
                    <span class="badge bg-secondary" style="font-size: 0.65rem;"><?= htmlspecialchars($currentUser['role_name']) ?></span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                    <li><h6 class="dropdown-header"><?= htmlspecialchars($currentUser['username']) ?></h6></li>
                    <li><a class="dropdown-item" href="<?= BASE_URL ?>/dashboard.php"><i class="bi bi-speedometer2 me-2"></i>Dashboard</a></li>
                    <li><a class="dropdown-item" href="<?= BASE_URL ?>/modules/sync/index.php"><i class="bi bi-cloud-arrow-up me-2"></i>Sync Center</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="<?= BASE_URL ?>/logout.php"><i class="bi bi-box-arrow-right me-2"></i>Sign Out</a></li>
                </ul>
            </div>
            <a href="<?= BASE_URL ?>/logout.php" class="btn btn-sm btn-outline-danger d-flex align-items-center" title="Sign Out">
                <i class="bi bi-box-arrow-right me-1"></i>
                <span class="d-none d-sm-inline">Sign Out</span>
            </a>
            <?php else: ?>
            <a href="<?= BASE_URL ?>/login.php" class="btn btn-sm btn-primary d-flex align-items-center">
                <i class="bi bi-box-arrow-in-right me-1"></i>
                <span>Sign In</span>
            </a>
            <a href="<?= BASE_URL ?>/logout.php" class="btn btn-sm btn-outline-secondary d-flex align-items-center" title="Reset Session">
                <i class="bi bi-power"></i>
            </a>
            <?php endif; ?>
        </div>
    </div>
</header>

<div class="container-fluid dms-layout-container">
    <div class="row g-0 h-100">
        <?php if ($currentUser): ?>
            <?php require_once BASE_PATH . '/includes/sidebar.php'; ?>
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-3 dms-main-content">
        <?php else: ?>
            <main class="col-12 px-3 py-4 dms-main-content">
        <?php endif; ?>

        <!-- Flash Messages Display -->
        <?php $flashes = getFlashMessages(); ?>
        <?php if (!empty($flashes)): ?>
            <div class="flash-container mb-3">
                <?php foreach ($flashes as $flash): ?>
                    <div class="alert alert-<?= $flash['type'] === 'error' ? 'danger' : htmlspecialchars($flash['type']) ?> alert-dismissible fade show alert-auto-dismiss shadow-sm" role="alert">
                        <i class="bi <?= $flash['type'] === 'error' ? 'bi-exclamation-triangle-fill text-danger' : 'bi-check-circle-fill text-success' ?> me-2"></i>
                        <?= htmlspecialchars($flash['message']) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
