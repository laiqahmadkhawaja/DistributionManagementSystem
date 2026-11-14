<?php
/**
 * Global Footer
 * Enterprise Distribution Management System (DMS)
 */

if (!defined('DMS_INIT')) {
    exit;
}

$user = getCurrentUser();
$roleSlug = $user['role_slug'] ?? '';
?>
        </main>
    </div>
</div>

<!-- Mobile Bottom Navigation Bar (Touch-friendly & Native App Experience) -->
<?php if ($user): ?>
<nav class="mobile-bottom-nav d-md-none">
    <a href="<?= BASE_URL ?>/dashboard.php" class="mobile-nav-item">
        <i class="bi bi-grid-fill"></i>
        <span>Home</span>
    </a>

    <?php if ($roleSlug === ROLE_DELIVERY_MAN): ?>
        <a href="<?= BASE_URL ?>/modules/delivery/index.php" class="mobile-nav-item text-success">
            <i class="bi bi-truck-flatbed"></i>
            <span>Deliveries</span>
        </a>
        <a href="<?= BASE_URL ?>/modules/payments/handover.php" class="mobile-nav-item">
            <i class="bi bi-wallet2"></i>
            <span>Handover</span>
        </a>
    <?php else: ?>
        <a href="<?= BASE_URL ?>/modules/orders/book.php" class="mobile-nav-item text-primary">
            <i class="bi bi-cart-plus-fill"></i>
            <span>Book Order</span>
        </a>
        <a href="<?= BASE_URL ?>/modules/customers/index.php" class="mobile-nav-item">
            <i class="bi bi-shop"></i>
            <span>Shops</span>
        </a>
        <a href="<?= BASE_URL ?>/modules/orders/index.php" class="mobile-nav-item">
            <i class="bi bi-card-checklist"></i>
            <span>Orders</span>
        </a>
    <?php endif; ?>

    <a href="<?= BASE_URL ?>/modules/sync/index.php" class="mobile-nav-item">
        <i class="bi bi-cloud-arrow-up"></i>
        <span>Sync</span>
    </a>
    <a href="<?= BASE_URL ?>/logout.php" class="mobile-nav-item text-danger">
        <i class="bi bi-box-arrow-right"></i>
        <span>Logout</span>
    </a>
</nav>
<?php endif; ?>

<!-- Local JavaScript Assets (Zero CDN Dependency for Offline/WebView) -->
<script src="<?= BASE_URL ?>/assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="<?= BASE_URL ?>/assets/vendor/html2canvas/html2canvas.min.js"></script>
<script src="<?= BASE_URL ?>/assets/js/app.js"></script>
<script src="<?= BASE_URL ?>/assets/js/offline-sync.js"></script>

<script>
// Register Service Worker for Offline / WebView Compatibility
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('<?= BASE_URL ?>/assets/js/sw.js')
            .then(reg => console.log('DMS ServiceWorker registered successfully'))
            .catch(err => console.log('ServiceWorker registration error:', err));
    });
}
</script>

</body>
</html>
