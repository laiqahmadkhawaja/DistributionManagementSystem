<?php
/**
 * Desktop Sidebar Navigation
 * Enterprise Distribution Management System (DMS)
 */

if (!defined('DMS_INIT')) {
    exit;
}

$user = getCurrentUser();
$roleSlug = $user['role_slug'] ?? '';
$pdo = getDatabaseConnection();

// Dynamic counters for badges
$pendingOrdersCount = 0;
$readyDispatchCount = 0;
$pendingDeliveriesCount = 0;

try {
    $pendingOrdersCount = (int)$pdo->query("SELECT COUNT(*) FROM `sales_orders` WHERE `status` IN ('submitted', 'pending_approval')")->fetchColumn();
    $readyDispatchCount = (int)$pdo->query("SELECT COUNT(*) FROM `sales_orders` WHERE `status` = 'approved'")->fetchColumn();
    $pendingDeliveriesCount = (int)$pdo->query("SELECT COUNT(*) FROM `delivery_assignments` WHERE `status` IN ('assigned', 'in_transit')")->fetchColumn();
} catch (Exception $e) {
    // Ignore counters if tables not yet populated
}
?>

<nav id="sidebarMenu" class="col-md-3 col-lg-2 d-md-block sidebar collapse p-3">
    <div class="position-sticky">
        <ul class="nav flex-column mb-3">
            <li class="nav-item">
                <a class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'dashboard.php' ? 'active' : '' ?>" href="<?= BASE_URL ?>/dashboard.php">
                    <i class="bi bi-grid-1x2"></i> Dashboard
                </a>
            </li>

            <!-- Sales & Field Operations -->
            <h6 class="sidebar-heading">Field Sales & Distribution</h6>
            
            <?php if (hasPermission('orders', 'create') || $roleSlug === ROLE_ORDER_BOOKER || $roleSlug === ROLE_SALESMAN): ?>
            <li class="nav-item">
                <a class="nav-link text-primary fw-bold" href="<?= BASE_URL ?>/modules/orders/book.php">
                    <i class="bi bi-cart-plus-fill"></i> Order Booker App
                </a>
            </li>
            <?php endif; ?>

            <?php if (hasPermission('customers', 'view')): ?>
            <li class="nav-item">
                <a class="nav-link" href="<?= BASE_URL ?>/modules/customers/index.php">
                    <i class="bi bi-shop"></i> Retail Shops / Customers
                </a>
            </li>
            <?php endif; ?>

            <?php if (hasPermission('orders', 'view')): ?>
            <li class="nav-item">
                <a class="nav-link justify-content-between" href="<?= BASE_URL ?>/modules/orders/index.php">
                    <span><i class="bi bi-card-checklist"></i> Sales Orders</span>
                    <?php if ($pendingOrdersCount > 0): ?>
                        <span class="badge bg-warning text-dark rounded-pill"><?= $pendingOrdersCount ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <?php endif; ?>

            <?php if (hasPermission('delivery', 'view') || $roleSlug === ROLE_DELIVERY_MAN): ?>
            <li class="nav-item">
                <a class="nav-link text-success fw-bold justify-content-between" href="<?= BASE_URL ?>/modules/delivery/index.php">
                    <span><i class="bi bi-truck-flatbed"></i> Delivery Man App</span>
                    <?php if ($pendingDeliveriesCount > 0): ?>
                        <span class="badge bg-danger rounded-pill"><?= $pendingDeliveriesCount ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <?php endif; ?>

            <!-- Warehouse & Dispatch -->
            <?php if (hasPermission('warehouse', 'view') || hasPermission('inventory', 'view')): ?>
            <h6 class="sidebar-heading">Warehouse & Inventory</h6>
            <li class="nav-item">
                <a class="nav-link justify-content-between" href="<?= BASE_URL ?>/modules/warehouse/index.php">
                    <span><i class="bi bi-box-seam"></i> Dispatch Challans</span>
                    <?php if ($readyDispatchCount > 0): ?>
                        <span class="badge bg-info text-dark rounded-pill"><?= $readyDispatchCount ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="<?= BASE_URL ?>/modules/inventory/index.php">
                    <i class="bi bi-layers"></i> Stock Movement Ledger
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="<?= BASE_URL ?>/modules/products/index.php">
                    <i class="bi bi-tags"></i> Products & Price Lists
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="<?= BASE_URL ?>/modules/warehouses/index.php">
                    <i class="bi bi-building"></i> Warehouse Facilities
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="<?= BASE_URL ?>/modules/units/index.php">
                    <i class="bi bi-rulers"></i> Measurement Units & Conversions
                </a>
            </li>
            <?php endif; ?>

            <!-- Purchases & Suppliers -->
            <?php if (hasPermission('purchases', 'view') || hasPermission('suppliers', 'view')): ?>
            <h6 class="sidebar-heading">Supply Chain</h6>
            <li class="nav-item">
                <a class="nav-link" href="<?= BASE_URL ?>/modules/purchases/index.php">
                    <i class="bi bi-bag-check"></i> Purchases & GRN
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="<?= BASE_URL ?>/modules/suppliers/index.php">
                    <i class="bi bi-building"></i> Suppliers
                </a>
            </li>
            <?php endif; ?>

            <!-- Finance & Accounting -->
            <?php if (hasPermission('accounting', 'view') || hasPermission('payments', 'view')): ?>
            <h6 class="sidebar-heading">Financials & Accounts</h6>
            <li class="nav-item">
                <a class="nav-link" href="<?= BASE_URL ?>/modules/payments/index.php">
                    <i class="bi bi-cash-stack"></i> Payments & Recoveries
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="<?= BASE_URL ?>/modules/payments/handover.php">
                    <i class="bi bi-wallet2"></i> Cash Handover & Recon
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="<?= BASE_URL ?>/modules/expenses/index.php">
                    <i class="bi bi-receipt"></i> Company Expenses
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="<?= BASE_URL ?>/modules/returns/index.php">
                    <i class="bi bi-arrow-return-left"></i> Sales Returns & Credit
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="<?= BASE_URL ?>/modules/accounting/index.php">
                    <i class="bi bi-journal-bookmark-fill"></i> Double-Entry Journal
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="<?= BASE_URL ?>/modules/accounting/ledger.php">
                    <i class="bi bi-book"></i> General Ledger & Trial
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link text-success fw-bold" href="<?= BASE_URL ?>/modules/payroll/index.php">
                    <i class="bi bi-wallet2"></i> Staff Salary & Payroll
                </a>
            </li>
            <?php endif; ?>

            <!-- Reports & Settings -->
            <?php if (hasPermission('reports', 'sales') || hasPermission('settings', 'view')): ?>
            <h6 class="sidebar-heading">Analytics & Config</h6>
            <li class="nav-item">
                <a class="nav-link" href="<?= BASE_URL ?>/modules/reports/daily_closing.php">
                    <i class="bi bi-safe2"></i> Daily Day Closing (ڈیلی کلوزنگ)
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="<?= BASE_URL ?>/modules/reports/loadsheet.php">
                    <i class="bi bi-truck-flatbed"></i> Dispatch Load Sheet (لوڈ شیٹ)
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="<?= BASE_URL ?>/modules/reports/index.php">
                    <i class="bi bi-bar-chart-line"></i> Business Reports & P&L
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="<?= BASE_URL ?>/modules/areas/index.php">
                    <i class="bi bi-geo-alt"></i> Sales Territories / Areas
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="<?= BASE_URL ?>/modules/employees/index.php">
                    <i class="bi bi-people"></i> Staff & Sales Targets
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="<?= BASE_URL ?>/modules/users/index.php">
                    <i class="bi bi-people-fill"></i> System User Management
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="<?= BASE_URL ?>/modules/settings/index.php">
                    <i class="bi bi-gear"></i> System Settings & RBAC
                </a>
            </li>
            <?php endif; ?>
        </ul>

        <!-- User Session & Direct Sign Out in Sidebar -->
        <div class="sidebar-user-block mt-3 pt-3 border-top">
            <div class="d-flex align-items-center mb-2 px-1">
                <i class="bi bi-person-circle fs-4 text-primary me-2"></i>
                <div class="overflow-hidden">
                    <div class="fw-bold text-dark text-truncate small"><?= htmlspecialchars($user['full_name'] ?? 'Signed In') ?></div>
                    <span class="badge bg-secondary" style="font-size: 0.65rem;"><?= htmlspecialchars($user['role_name'] ?? 'Staff') ?></span>
                </div>
            </div>
            <a href="<?= BASE_URL ?>/logout.php" class="btn btn-outline-danger btn-sm w-100 d-flex align-items-center justify-content-center">
                <i class="bi bi-box-arrow-right me-1"></i> Sign Out
            </a>
        </div>
    </div>
</nav>
