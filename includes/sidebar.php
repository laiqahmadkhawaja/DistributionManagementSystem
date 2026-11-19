<?php
/**
 * Desktop Sidebar Navigation - Collapsible Accordion & Active Route Highlighting
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
$pendingStockHandoverCount = 0;
$pendingCashHandoversCount = 0;

try {
    $pendingOrdersCount = (int)$pdo->query("SELECT COUNT(*) FROM `sales_orders` WHERE `status` IN ('submitted', 'pending_approval')")->fetchColumn();
    $readyDispatchCount = (int)$pdo->query("SELECT COUNT(*) FROM `sales_orders` WHERE `status` = 'approved'")->fetchColumn();
    $pendingDeliveriesCount = (int)$pdo->query("SELECT COUNT(*) FROM `delivery_assignments` WHERE `status` IN ('assigned', 'in_transit')")->fetchColumn();
    $pendingStockHandoverCount = (int)$pdo->query("SELECT COUNT(*) FROM `sales_returns` WHERE `handover_status` = 'handed_over_to_warehouse'")->fetchColumn();
    $pendingCashHandoversCount = (int)$pdo->query("SELECT COUNT(*) FROM `cash_handovers` WHERE `status` = 'pending'")->fetchColumn();
} catch (Exception $e) {
    // Ignore counters if tables not yet populated
}

$secSalesCount = $pendingOrdersCount + $pendingDeliveriesCount;
$secWarehouseCount = $readyDispatchCount + $pendingStockHandoverCount;
$secFinanceCount = $pendingCashHandoversCount;

// Active Route Detection
$scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$requestUri = str_replace('\\', '/', $_SERVER['REQUEST_URI'] ?? '');

$isPageActive = function(array|string $patterns) use ($scriptName, $requestUri): bool {
    if (is_string($patterns)) {
        $patterns = [$patterns];
    }
    foreach ($patterns as $pattern) {
        $p = trim($pattern, '/');
        if (str_ends_with($scriptName, $p) || str_contains($scriptName, '/' . $p) || str_contains($requestUri, '/' . $p)) {
            return true;
        }
    }
    return false;
};

// Section Active Detection
$isSalesActive = $isPageActive([
    'modules/orders/book.php',
    'modules/customers/',
    'modules/payments/recovery.php',
    'modules/orders/',
    'modules/delivery/',
]);

$isWarehouseActive = $isPageActive([
    'modules/warehouse/',
    'modules/inventory/',
    'modules/products/',
    'modules/warehouses/',
    'modules/units/',
]);

$isSupplyActive = $isPageActive([
    'modules/purchases/',
    'modules/suppliers/',
]);

$isFinanceActive = $isPageActive([
    'modules/payments/index.php',
    'modules/payments/handover.php',
    'modules/accounts/',
    'modules/expenses/',
    'modules/returns/',
    'modules/accounting/',
    'modules/payroll/',
]);

$isAdminActive = $isPageActive([
    'modules/reports/',
    'modules/areas/',
    'modules/employees/',
    'modules/users/',
    'modules/settings/',
]);

// Default open section on home / dashboard based on employee role
$anySectionActive = $isSalesActive || $isWarehouseActive || $isSupplyActive || $isFinanceActive || $isAdminActive;
if (!$anySectionActive) {
    if (in_array($roleSlug, [ROLE_DELIVERY_MAN, ROLE_ORDER_BOOKER, ROLE_SALESMAN])) {
        $isSalesActive = true;
    } elseif ($roleSlug === ROLE_STOREKEEPER) {
        $isWarehouseActive = true;
    } elseif ($roleSlug === ROLE_ACCOUNTANT) {
        $isFinanceActive = true;
    } else {
        $isSalesActive = true;
    }
}
?>

<nav id="sidebarMenu" class="col-md-3 col-lg-2 d-md-block sidebar collapse p-3">
    <div class="position-sticky">
        
        <!-- Standalone Dashboard Link -->
        <ul class="nav flex-column mb-2">
            <li class="nav-item">
                <a class="nav-link <?= (basename($scriptName) === 'dashboard.php') ? 'active' : '' ?>" href="<?= BASE_URL ?>/dashboard.php">
                    <i class="bi bi-grid-1x2"></i> <span>Dashboard</span>
                </a>
            </li>
        </ul>

        <!-- ==========================================
             SECTION 1: Field Sales & Distribution
             ========================================== -->
        <?php if (hasPermission('orders', 'view') || hasPermission('orders', 'create') || hasPermission('delivery', 'view') || hasPermission('customers', 'view') || in_array($roleSlug, [ROLE_ORDER_BOOKER, ROLE_SALESMAN, ROLE_DELIVERY_MAN, ROLE_ADMIN, ROLE_SUPER_ADMIN])): ?>
        <a class="sidebar-section-header <?= $isSalesActive ? 'is-active-section' : '' ?> <?= $secSalesCount > 0 ? 'section-blink-alert' : '' ?>" 
           id="secSalesHeader"
           data-bs-toggle="collapse" 
           href="#secSales" 
           role="button" 
           aria-expanded="<?= $isSalesActive ? 'true' : 'false' ?>" 
           aria-controls="secSales">
            <span class="sidebar-heading">
                <i class="bi bi-truck text-primary"></i> Field Sales & Distribution
                <span class="badge bg-danger rounded-pill pulse-badge section-counter-badge ms-1 <?= $secSalesCount > 0 ? '' : 'd-none' ?>" id="secSalesBadge"><?= $secSalesCount ?></span>
            </span>
            <i class="bi bi-chevron-down section-chevron"></i>
        </a>
        <div class="collapse <?= $isSalesActive ? 'show' : '' ?>" id="secSales">
            <ul class="nav flex-column mb-2">
                <?php if (hasPermission('orders', 'create') || $roleSlug === ROLE_ORDER_BOOKER || $roleSlug === ROLE_SALESMAN): ?>
                <li class="nav-item">
                    <a class="nav-link <?= $isPageActive('modules/orders/quick_booking.php') ? 'active' : '' ?>" href="<?= BASE_URL ?>/modules/orders/quick_booking.php">
                        <i class="bi bi-lightning-charge-fill text-warning"></i> Quick Route Booking
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $isPageActive('modules/orders/book.php') ? 'active' : '' ?>" href="<?= BASE_URL ?>/modules/orders/book.php">
                        <i class="bi bi-cart-plus-fill"></i> Order Booker App
                    </a>
                </li>
                <?php endif; ?>

                <?php if (hasPermission('customers', 'view')): ?>
                <li class="nav-item">
                    <a class="nav-link <?= $isPageActive('modules/customers/') ? 'active' : '' ?>" href="<?= BASE_URL ?>/modules/customers/index.php">
                        <i class="bi bi-shop"></i> Retail Shops / Customers
                    </a>
                </li>
                <?php endif; ?>

                <?php if (hasPermission('payments', 'create') || hasPermission('payments', 'view') || hasRole([ROLE_SUPER_ADMIN, ROLE_ADMIN, ROLE_ORDER_BOOKER, ROLE_SALESMAN, ROLE_DELIVERY_MAN, ROLE_ACCOUNTANT])): ?>
                <li class="nav-item">
                    <a class="nav-link <?= $isPageActive(['modules/payments/recovery.php', 'modules/payments/receipt_print.php']) ? 'active' : '' ?>" href="<?= BASE_URL ?>/modules/payments/recovery.php">
                        <i class="bi bi-cash-coin"></i> Customer Payment Recovery
                    </a>
                </li>
                <?php endif; ?>

                <?php if (hasPermission('orders', 'view')): ?>
                <li class="nav-item">
                    <a class="nav-link justify-content-between <?= ($isPageActive('modules/orders/') && !$isPageActive(['modules/orders/book.php', 'modules/orders/quick_booking.php'])) ? 'active' : '' ?>" href="<?= BASE_URL ?>/modules/orders/index.php">
                        <span><i class="bi bi-card-checklist"></i> Sales Orders</span>
                        <span class="badge bg-warning text-dark rounded-pill <?= $pendingOrdersCount > 0 ? 'pulse-badge' : 'd-none' ?>" id="badgePendingOrders"><?= $pendingOrdersCount ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $isPageActive('modules/orders/print_batch.php') ? 'active' : '' ?>" href="<?= BASE_URL ?>/modules/orders/print_batch.php" target="_blank">
                        <i class="bi bi-printer"></i> Batch Bills (2 per A4)
                    </a>
                </li>
                <?php endif; ?>

                <?php if (hasPermission('delivery', 'view') || $roleSlug === ROLE_DELIVERY_MAN): ?>
                <li class="nav-item">
                    <a class="nav-link justify-content-between <?= $isPageActive(['modules/delivery/index.php', 'modules/delivery/deliver.php']) ? 'active' : '' ?>" href="<?= BASE_URL ?>/modules/delivery/index.php">
                        <span><i class="bi bi-truck-flatbed"></i> Delivery Man App</span>
                        <span class="badge bg-danger rounded-pill <?= $pendingDeliveriesCount > 0 ? 'pulse-badge' : 'd-none' ?>" id="badgePendingDeliveries"><?= $pendingDeliveriesCount ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $isPageActive('modules/delivery/sheet_print.php') ? 'active' : '' ?>" href="<?= BASE_URL ?>/modules/delivery/sheet_print.php" target="_blank">
                        <i class="bi bi-file-earmark-spreadsheet"></i> Daily Run Sheet
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $isPageActive('modules/delivery/sheet_reconcile.php') ? 'active' : '' ?>" href="<?= BASE_URL ?>/modules/delivery/sheet_reconcile.php">
                        <i class="bi bi-ui-checks-grid"></i> Reconcile Run Sheet
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $isPageActive(['modules/delivery/return.php', 'modules/delivery/return_print.php']) ? 'active' : '' ?>" href="<?= BASE_URL ?>/modules/delivery/return.php">
                        <i class="bi bi-box-arrow-in-left"></i> Shop Returns Entry
                    </a>
                </li>
                <?php endif; ?>
            </ul>
        </div>
        <?php endif; ?>

        <!-- ==========================================
             SECTION 2: Warehouse & Inventory
             ========================================== -->
        <?php if (hasPermission('warehouse', 'view') || hasPermission('inventory', 'view') || in_array($roleSlug, [ROLE_STOREKEEPER, ROLE_ADMIN, ROLE_SUPER_ADMIN])): ?>
        <a class="sidebar-section-header <?= $isWarehouseActive ? 'is-active-section' : '' ?> <?= $secWarehouseCount > 0 ? 'section-blink-alert' : '' ?>" 
           id="secWarehouseHeader"
           data-bs-toggle="collapse" 
           href="#secWarehouse" 
           role="button" 
           aria-expanded="<?= $isWarehouseActive ? 'true' : 'false' ?>" 
           aria-controls="secWarehouse">
            <span class="sidebar-heading">
                <i class="bi bi-box-seam text-warning"></i> Warehouse & Inventory
                <span class="badge bg-danger rounded-pill pulse-badge section-counter-badge ms-1 <?= $secWarehouseCount > 0 ? '' : 'd-none' ?>" id="secWarehouseBadge"><?= $readyDispatchCount > 0 ? $readyDispatchCount . ' Ready' : $secWarehouseCount ?></span>
            </span>
            <i class="bi bi-chevron-down section-chevron"></i>
        </a>
        <div class="collapse <?= $isWarehouseActive ? 'show' : '' ?>" id="secWarehouse">
            <ul class="nav flex-column mb-2">
                <li class="nav-item">
                    <a class="nav-link justify-content-between <?= ($isPageActive('modules/warehouse/') && !$isPageActive(['modules/warehouse/stock_handover.php', 'modules/warehouse/stock_issue_print.php'])) ? 'active' : '' ?>" href="<?= BASE_URL ?>/modules/warehouse/index.php">
                        <span><i class="bi bi-box-seam"></i> Dispatch Challans</span>
                        <span class="badge bg-info text-dark rounded-pill <?= $readyDispatchCount > 0 ? 'pulse-badge' : 'd-none' ?>" id="badgeReadyDispatch"><?= $readyDispatchCount ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $isPageActive('modules/warehouse/stock_issue_print.php') ? 'active' : '' ?>" href="<?= BASE_URL ?>/modules/warehouse/stock_issue_print.php" target="_blank">
                        <i class="bi bi-file-earmark-spreadsheet"></i> Stock Issue Summary
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link justify-content-between <?= $isPageActive('modules/warehouse/stock_handover.php') ? 'active' : '' ?>" href="<?= BASE_URL ?>/modules/warehouse/stock_handover.php">
                        <span><i class="bi bi-arrow-down-left-square"></i> Stock Handover & Resell</span>
                        <span class="badge bg-danger rounded-pill <?= $pendingStockHandoverCount > 0 ? 'pulse-badge' : 'd-none' ?>" id="badgeStockHandover"><?= $pendingStockHandoverCount ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $isPageActive('modules/inventory/') ? 'active' : '' ?>" href="<?= BASE_URL ?>/modules/inventory/index.php">
                        <i class="bi bi-layers"></i> Stock Movement Ledger
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= ($isPageActive('modules/products/') && !$isPageActive('modules/products/categories.php')) ? 'active' : '' ?>" href="<?= BASE_URL ?>/modules/products/index.php">
                        <i class="bi bi-tags"></i> Products & Price Lists
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $isPageActive('modules/products/categories.php') ? 'active' : '' ?>" href="<?= BASE_URL ?>/modules/products/categories.php">
                        <i class="bi bi-folder2-open"></i> Product Categories
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $isPageActive('modules/warehouses/') ? 'active' : '' ?>" href="<?= BASE_URL ?>/modules/warehouses/index.php">
                        <i class="bi bi-building"></i> Warehouse Facilities
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $isPageActive('modules/units/') ? 'active' : '' ?>" href="<?= BASE_URL ?>/modules/units/index.php">
                        <i class="bi bi-rulers"></i> Measurement Units & Conversions
                    </a>
                </li>
            </ul>
        </div>
        <?php endif; ?>

        <!-- ==========================================
             SECTION 3: Supply Chain & Purchases
             ========================================== -->
        <?php if (hasPermission('purchases', 'view') || hasPermission('suppliers', 'view') || in_array($roleSlug, [ROLE_ADMIN, ROLE_SUPER_ADMIN])): ?>
        <a class="sidebar-section-header <?= $isSupplyActive ? 'is-active-section' : '' ?>" 
           data-bs-toggle="collapse" 
           href="#secSupply" 
           role="button" 
           aria-expanded="<?= $isSupplyActive ? 'true' : 'false' ?>" 
           aria-controls="secSupply">
            <span class="sidebar-heading">
                <i class="bi bi-bag-check text-info"></i> Supply Chain
            </span>
            <i class="bi bi-chevron-down section-chevron"></i>
        </a>
        <div class="collapse <?= $isSupplyActive ? 'show' : '' ?>" id="secSupply">
            <ul class="nav flex-column mb-2">
                <li class="nav-item">
                    <a class="nav-link <?= $isPageActive('modules/purchases/') ? 'active' : '' ?>" href="<?= BASE_URL ?>/modules/purchases/index.php">
                        <i class="bi bi-bag-check"></i> Purchases & GRN
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $isPageActive('modules/suppliers/') ? 'active' : '' ?>" href="<?= BASE_URL ?>/modules/suppliers/index.php">
                        <i class="bi bi-building"></i> Suppliers
                    </a>
                </li>
            </ul>
        </div>
        <?php endif; ?>

        <!-- ==========================================
             SECTION 4: Financials & Accounts
             ========================================== -->
        <?php if (hasPermission('accounting', 'view') || hasPermission('payments', 'view') || in_array($roleSlug, [ROLE_ACCOUNTANT, ROLE_ADMIN, ROLE_SUPER_ADMIN])): ?>
        <a class="sidebar-section-header <?= $isFinanceActive ? 'is-active-section' : '' ?> <?= $secFinanceCount > 0 ? 'section-blink-alert' : '' ?>" 
           id="secFinanceHeader"
           data-bs-toggle="collapse" 
           href="#secFinance" 
           role="button" 
           aria-expanded="<?= $isFinanceActive ? 'true' : 'false' ?>" 
           aria-controls="secFinance">
            <span class="sidebar-heading">
                <i class="bi bi-cash-stack text-success"></i> Financials & Accounts
                <span class="badge bg-warning text-dark rounded-pill pulse-badge section-counter-badge ms-1 <?= $secFinanceCount > 0 ? '' : 'd-none' ?>" id="secFinanceBadge"><?= $secFinanceCount ?></span>
            </span>
            <i class="bi bi-chevron-down section-chevron"></i>
        </a>
        <div class="collapse <?= $isFinanceActive ? 'show' : '' ?>" id="secFinance">
            <ul class="nav flex-column mb-2">
                <li class="nav-item">
                    <a class="nav-link <?= ($isPageActive('modules/payments/index.php') || $isPageActive('modules/payments/create.php')) ? 'active' : '' ?>" href="<?= BASE_URL ?>/modules/payments/index.php">
                        <i class="bi bi-cash-stack"></i> Payments & Recoveries
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link justify-content-between <?= $isPageActive('modules/payments/handover.php') ? 'active' : '' ?>" href="<?= BASE_URL ?>/modules/payments/handover.php">
                        <span><i class="bi bi-wallet2"></i> Cash Handover & Recon</span>
                        <span class="badge bg-warning text-dark rounded-pill <?= $pendingCashHandoversCount > 0 ? 'pulse-badge' : 'd-none' ?>" id="badgeCashHandover"><?= $pendingCashHandoversCount ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $isPageActive('modules/accounts/') ? 'active' : '' ?>" href="<?= BASE_URL ?>/modules/accounts/index.php">
                        <i class="bi bi-bank"></i> Bank & Wallet Accounts
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $isPageActive('modules/expenses/') ? 'active' : '' ?>" href="<?= BASE_URL ?>/modules/expenses/index.php">
                        <i class="bi bi-receipt"></i> Company Expenses
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $isPageActive('modules/returns/') ? 'active' : '' ?>" href="<?= BASE_URL ?>/modules/returns/index.php">
                        <i class="bi bi-arrow-return-left"></i> Sales Returns & Credit
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= ($isPageActive('modules/accounting/index.php') || $isPageActive('modules/accounting/create.php')) ? 'active' : '' ?>" href="<?= BASE_URL ?>/modules/accounting/index.php">
                        <i class="bi bi-journal-bookmark-fill"></i> Double-Entry Journal
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= ($isPageActive('modules/accounting/ledger.php') || $isPageActive('modules/accounting/trial_balance.php') || $isPageActive('modules/accounting/pl.php') || $isPageActive('modules/accounting/balance_sheet.php')) ? 'active' : '' ?>" href="<?= BASE_URL ?>/modules/accounting/ledger.php">
                        <i class="bi bi-book"></i> General Ledger & Trial
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $isPageActive('modules/payroll/') ? 'active' : '' ?>" href="<?= BASE_URL ?>/modules/payroll/index.php">
                        <i class="bi bi-wallet2"></i> Staff Salary & Payroll
                    </a>
                </li>
            </ul>
        </div>
        <?php endif; ?>

        <!-- ==========================================
             SECTION 5: Analytics & Configuration
             ========================================== -->
        <?php if (hasPermission('reports', 'sales') || hasPermission('settings', 'view') || in_array($roleSlug, [ROLE_ADMIN, ROLE_SUPER_ADMIN])): ?>
        <a class="sidebar-section-header <?= $isAdminActive ? 'is-active-section' : '' ?>" 
           data-bs-toggle="collapse" 
           href="#secAdmin" 
           role="button" 
           aria-expanded="<?= $isAdminActive ? 'true' : 'false' ?>" 
           aria-controls="secAdmin">
            <span class="sidebar-heading">
                <i class="bi bi-sliders text-secondary"></i> Analytics & Config
            </span>
            <i class="bi bi-chevron-down section-chevron"></i>
        </a>
        <div class="collapse <?= $isAdminActive ? 'show' : '' ?>" id="secAdmin">
            <ul class="nav flex-column mb-2">
                <li class="nav-item">
                    <a class="nav-link <?= $isPageActive('modules/reports/parties_credit.php') ? 'active' : '' ?>" href="<?= BASE_URL ?>/modules/reports/parties_credit.php" target="_blank">
                        <i class="bi bi-person-lines-fill text-info"></i> Parties Credit (Market)
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $isPageActive('modules/reports/daily_sale_summary.php') ? 'active' : '' ?>" href="<?= BASE_URL ?>/modules/reports/daily_sale_summary.php" target="_blank">
                        <i class="bi bi-calendar-check text-warning"></i> Daily Sale Macro Summary
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $isPageActive('modules/reports/daily_closing.php') ? 'active' : '' ?>" href="<?= BASE_URL ?>/modules/reports/daily_closing.php">
                        <i class="bi bi-safe2"></i> Daily Day Closing
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $isPageActive('modules/reports/loadsheet.php') ? 'active' : '' ?>" href="<?= BASE_URL ?>/modules/reports/loadsheet.php">
                        <i class="bi bi-truck-flatbed"></i> Dispatch Load Sheet
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= ($isPageActive('modules/reports/index.php') || $isPageActive('modules/reports/view.php')) ? 'active' : '' ?>" href="<?= BASE_URL ?>/modules/reports/index.php">
                        <i class="bi bi-bar-chart-line"></i> Business Reports & P&L
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $isPageActive('modules/areas/') ? 'active' : '' ?>" href="<?= BASE_URL ?>/modules/areas/index.php">
                        <i class="bi bi-geo-alt"></i> Routes & Territories
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $isPageActive('modules/employees/') ? 'active' : '' ?>" href="<?= BASE_URL ?>/modules/employees/index.php">
                        <i class="bi bi-people"></i> Staff & Sales Targets
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $isPageActive('modules/users/') ? 'active' : '' ?>" href="<?= BASE_URL ?>/modules/users/index.php">
                        <i class="bi bi-people-fill"></i> System User Management
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $isPageActive('modules/settings/') ? 'active' : '' ?>" href="<?= BASE_URL ?>/modules/settings/index.php">
                        <i class="bi bi-gear"></i> System Settings & RBAC
                    </a>
                </li>
            </ul>
        </div>
        <?php endif; ?>

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
