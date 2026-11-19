<?php
/**
 * Company Business Configuration & Granular RBAC Permissions
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('settings', 'view');

$pdo = getDatabaseConnection();
$currentUser = getCurrentUser();

// Handle Company Settings Update POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    requirePermission('settings', 'edit');

    $name = sanitize($_POST['company_name'] ?? '');
    $phone = sanitize($_POST['phone'] ?? '');
    $email = sanitize($_POST['email'] ?? '');
    $address = sanitize($_POST['address'] ?? '');
    $city = sanitize($_POST['city'] ?? 'Gujranwala');
    $currency = sanitize($_POST['currency'] ?? 'PKR');
    $currencySymbol = sanitize($_POST['currency_symbol'] ?? 'Rs.');

    try {
        $stmt = $pdo->prepare("
            UPDATE `companies` 
            SET `name` = ?, `phone` = ?, `email` = ?, `address` = ?, `city` = ?, `currency` = ?, `currency_symbol` = ?
            WHERE id = 1
        ");
        $stmt->execute([$name, $phone, $email, $address, $city, $currency, $currencySymbol]);

        logAudit($pdo, 'settings', 1, 'update_company', null, ['name' => $name, 'phone' => $phone]);
        setFlashMessage('success', 'Company settings saved successfully.');
        redirect(BASE_URL . '/modules/settings/index.php');

    } catch (Exception $e) {
        setFlashMessage('error', 'Error updating settings: ' . $e->getMessage());
        redirect(BASE_URL . '/modules/settings/index.php');
    }
}

// Handle Document Terms & Directives Update POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_doc_terms'])) {
    requirePermission('settings', 'edit');

    $billTerms = sanitize($_POST['bill_terms_conditions'] ?? '');
    $gpDirective = sanitize($_POST['gatepass_security_directive'] ?? '');
    $custTerms = sanitize($_POST['customer_ledger_terms'] ?? '');
    $suppTerms = sanitize($_POST['supplier_ledger_terms'] ?? '');

    try {
        $pdo->beginTransaction();
        
        $upsertSetting = $pdo->prepare("
            INSERT INTO `settings` (`setting_key`, `setting_value`, `description`, `updated_at`)
            VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`), `updated_at` = NOW()
        ");

        $upsertSetting->execute(['bill_terms_conditions', $billTerms, 'Sales Bill & Invoice Written Terms of Supply']);
        $upsertSetting->execute(['gatepass_security_directive', $gpDirective, 'Warehouse Outward Gate Pass Security Clearance Directive']);
        $upsertSetting->execute(['customer_ledger_terms', $custTerms, 'Customer Statement & Financial Ledger Terms']);
        $upsertSetting->execute(['supplier_ledger_terms', $suppTerms, 'Supplier Accounts Payable Statement Terms']);

        logAudit($pdo, 'settings', 1, 'update_doc_terms', null, ['bill_terms' => substr($billTerms, 0, 50)]);
        $pdo->commit();

        setFlashMessage('success', 'Document terms & print directives saved successfully.');
        redirect(BASE_URL . '/modules/settings/index.php?tab=terms');

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        setFlashMessage('error', 'Error saving document terms: ' . $e->getMessage());
        redirect(BASE_URL . '/modules/settings/index.php?tab=terms');
    }
}

// Handle Route & Territory Policy Update POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_route_policy'])) {
    requirePermission('settings', 'edit');

    $cadence = sanitize($_POST['route_assignment_cadence'] ?? 'weekly');
    $coverageTracking = isset($_POST['route_coverage_tracking']) ? '1' : '0';
    $flexibleDays = isset($_POST['route_allow_flexible_days']) ? '1' : '0';

    try {
        $pdo->beginTransaction();
        
        $upsertSetting = $pdo->prepare("
            INSERT INTO `settings` (`setting_key`, `setting_value`, `description`, `updated_at`)
            VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`), `updated_at` = NOW()
        ");

        $upsertSetting->execute(['route_assignment_cadence', $cadence, 'Territory Route & Area Assignment Cadence: weekly, daily, monthly, fixed']);
        $upsertSetting->execute(['route_coverage_tracking', $coverageTracking, 'Shop Visit & Booking Progress Coverage Tracking: 1 or 0']);
        $upsertSetting->execute(['route_allow_flexible_days', $flexibleDays, 'Allow Flexible Day Area Visit within Cadence: 1 or 0']);

        logAudit($pdo, 'settings', 1, 'update_route_policy', null, ['cadence' => $cadence, 'coverage_tracking' => $coverageTracking]);
        $pdo->commit();

        setFlashMessage('success', 'Territory route cadence & coverage policy updated successfully.');
        redirect(BASE_URL . '/modules/settings/index.php?tab=routes');

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        setFlashMessage('error', 'Error updating route policy: ' . $e->getMessage());
        redirect(BASE_URL . '/modules/settings/index.php?tab=routes');
    }
}

// Handle Procurement & Purchase Policy Update POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_purchase_policy'])) {
    requirePermission('settings', 'edit');

    $receivingMode = sanitize($_POST['purchase_receiving_mode'] ?? 'direct_receive');
    $paymentMode = sanitize($_POST['purchase_default_payment_mode'] ?? 'credit');
    $paymentAccount = (int)($_POST['purchase_default_payment_account'] ?? 2);

    try {
        $pdo->beginTransaction();
        
        $upsertSetting = $pdo->prepare("
            INSERT INTO `settings` (`setting_key`, `setting_value`, `description`, `updated_at`)
            VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`), `updated_at` = NOW()
        ");

        $upsertSetting->execute(['purchase_receiving_mode', $receivingMode, 'Procurement Stock Receiving Workflow Mode: direct_receive or two_step_grn']);
        $upsertSetting->execute(['purchase_default_payment_mode', $paymentMode, 'Default Purchase Payment Mode: credit or full']);
        $upsertSetting->execute(['purchase_default_payment_account', (string)$paymentAccount, 'Default Purchase Payment Account ID from Chart of Accounts']);

        logAudit($pdo, 'settings', 1, 'update_purchase_policy', null, [
            'purchase_receiving_mode' => $receivingMode,
            'purchase_default_payment_mode' => $paymentMode,
            'purchase_default_payment_account' => $paymentAccount
        ]);
        $pdo->commit();

        setFlashMessage('success', 'Procurement & Purchasing policy saved successfully.');
        redirect(BASE_URL . '/modules/settings/index.php?tab=purchases');

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        setFlashMessage('error', 'Error updating purchase policy: ' . $e->getMessage());
        redirect(BASE_URL . '/modules/settings/index.php?tab=purchases');
    }
}

// Handle Distribution Operational Mode POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_operational_mode'])) {
    requirePermission('settings', 'edit');

    $opMode = sanitize($_POST['system_operational_mode'] ?? 'traditional');
    if (!in_array($opMode, ['traditional', 'app_driven', 'hybrid'])) {
        $opMode = 'traditional';
    }
    $batchBillsPerA4 = (int)($_POST['batch_bills_per_a4'] ?? 2);
    if (!in_array($batchBillsPerA4, [1, 2, 3])) {
        $batchBillsPerA4 = 2;
    }

    try {
        $pdo->beginTransaction();
        $upsertSetting = $pdo->prepare("
            INSERT INTO `settings` (`setting_key`, `setting_value`, `description`, `updated_at`)
            VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`), `updated_at` = NOW()
        ");
        $upsertSetting->execute(['system_operational_mode', $opMode, 'Distribution Engine Operational Mode: traditional, app_driven, or hybrid']);
        $upsertSetting->execute(['batch_bills_per_a4', (string)$batchBillsPerA4, 'Number of customer sales bills to format per A4 portrait page for batch printing']);
        $pdo->commit();

        logAudit($pdo, 'settings', 1, 'update_operational_mode', null, ['mode' => $opMode, 'bills_per_a4' => $batchBillsPerA4]);
        setFlashMessage('success', 'Operational mode & distribution workflow updated successfully.');
        redirect(BASE_URL . '/modules/settings/index.php?tab=opmode');

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        setFlashMessage('error', 'Error updating operational mode: ' . $e->getMessage());
        redirect(BASE_URL . '/modules/settings/index.php?tab=opmode');
    }
}

// Handle RBAC Permission Matrix Update POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_permissions'])) {
    requirePermission('settings', 'edit');

    $roleId = (int)($_POST['role_id'] ?? 0);
    $selectedPerms = $_POST['perm'] ?? [];

    if ($roleId > 0 && $roleId !== 1) { // Super Admin permissions are permanent
        try {
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM `role_permissions` WHERE `role_id` = ?")->execute([$roleId]);

            $ins = $pdo->prepare("INSERT INTO `role_permissions` (`role_id`, `permission_id`) VALUES (?, ?)");
            foreach ($selectedPerms as $pId) {
                $ins->execute([$roleId, (int)$pId]);
            }

            logAudit($pdo, 'rbac', $roleId, 'update_permissions', null, ['role_id' => $roleId, 'count' => count($selectedPerms)]);
            $pdo->commit();

            setFlashMessage('success', 'Role permissions updated successfully.');
            redirect(BASE_URL . '/modules/settings/index.php?tab=rbac&role_id=' . $roleId);

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            setFlashMessage('error', 'Permission update error: ' . $e->getMessage());
            redirect(BASE_URL . '/modules/settings/index.php?tab=rbac');
        }
    }
}

$company = getCompanyInfo($pdo);
$roles = $pdo->query("SELECT * FROM `roles` ORDER BY id ASC")->fetchAll();
$allPerms = $pdo->query("SELECT * FROM `permissions` ORDER BY module ASC, action ASC")->fetchAll();

$selectedRoleId = (int)($_GET['role_id'] ?? 3); // Default to Order Booker
$activeTab = sanitize($_GET['tab'] ?? 'company');

// Fetch currently assigned permissions for the selected role
$rolePermIds = $pdo->query("SELECT permission_id FROM `role_permissions` WHERE role_id = {$selectedRoleId}")->fetchAll(PDO::FETCH_COLUMN);

// Document print terms
$billTermsVal = getSetting($pdo, 'bill_terms_conditions', 'Goods once sold are non-returnable without prior distributor authorization. Please verify carton seal and pieces count prior to signing receiver acknowledgment.');
$gpDirectiveVal = getSetting($pdo, 'gatepass_security_directive', 'This document serves as legal and physical outward gate clearance for the listed cargo to exit the warehouse premises. The transport driver assumes full fiduciary responsibility for inventory integrity, protection against damage, and safe transit until physical handover against customer signature.');
$custTermsVal = getSetting($pdo, 'customer_ledger_terms', 'This financial statement reflects all invoiced sales, payments, and returns posted up to the printed date. Any discrepancy or reconciliation dispute must be reported in writing within 7 days.');
$suppTermsVal = getSetting($pdo, 'supplier_ledger_terms', 'All goods receipts (GRNs), payments, and credit adjustments are subject to final store audit and warehouse physical verification. Statement accounts payable subject to agreed supplier payment terms.');

// Territory Route & Cadence Settings
$routeCadenceVal = getSetting($pdo, 'route_assignment_cadence', 'weekly');
$routeCoverageVal = getSetting($pdo, 'route_coverage_tracking', '1');
$routeFlexibleVal = getSetting($pdo, 'route_allow_flexible_days', '1');

// Procurement & Purchasing Policy Settings
$purchaseReceivingVal = getSetting($pdo, 'purchase_receiving_mode', 'direct_receive');
$purchasePaymentVal = getSetting($pdo, 'purchase_default_payment_mode', 'credit');
$purchaseAccountVal = (int)getSetting($pdo, 'purchase_default_payment_account', 2);
$purchaseAccounts = $pdo->query("
    SELECT id, code, name, system_tag 
    FROM `chart_of_accounts` 
    WHERE group_type = 'Asset' AND (system_tag IN ('cash', 'bank') OR parent_id = 1) AND is_active = 1 
    ORDER BY code ASC
")->fetchAll();

// Distribution Operational Workflow Mode
$systemOpMode = getSetting($pdo, 'system_operational_mode', 'traditional');
$batchBillsPerA4Val = (int)getSetting($pdo, 'batch_bills_per_a4', '2');

$pageTitle = 'Settings & RBAC Security';
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-3 gap-2">
    <div>
        <h4 class="fw-bold mb-0 text-dark"><i class="bi bi-gear-fill text-primary me-2"></i>Settings & Granular RBAC Permissions</h4>
        <small class="text-muted">Business Profile, Security Control & Role-Based Access Matrices</small>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="<?= BASE_URL ?>/modules/users/index.php" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-people-fill me-1"></i> User Accounts
        </a>
        <a href="<?= BASE_URL ?>/modules/warehouses/index.php" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-building me-1"></i> Warehouses
        </a>
    </div>
</div>

<ul class="nav nav-pills mb-3" role="tablist">
    <li class="nav-item">
        <button class="nav-link <?= $activeTab === 'company' ? 'active' : '' ?> fw-semibold" id="comp-tab" data-bs-toggle="pill" data-bs-target="#comp-pane" type="button">
            <i class="bi bi-building me-1"></i> Company Profile
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link <?= $activeTab === 'terms' ? 'active' : '' ?> fw-semibold" id="terms-tab" data-bs-toggle="pill" data-bs-target="#terms-pane" type="button">
            <i class="bi bi-file-earmark-text me-1"></i> Document Terms & Directives
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link <?= $activeTab === 'routes' ? 'active' : '' ?> fw-semibold" id="routes-tab" data-bs-toggle="pill" data-bs-target="#routes-pane" type="button">
            <i class="bi bi-geo-alt-fill me-1"></i> Area & Route Policy
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link <?= $activeTab === 'purchases' ? 'active' : '' ?> fw-semibold" id="purchases-tab" data-bs-toggle="pill" data-bs-target="#purchases-pane" type="button">
            <i class="bi bi-cart-check me-1"></i> Procurement Policy
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link <?= $activeTab === 'opmode' ? 'active' : '' ?> fw-semibold" id="opmode-tab" data-bs-toggle="pill" data-bs-target="#opmode-pane" type="button">
            <i class="bi bi-toggles me-1"></i> Distribution Workflow Mode
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link <?= $activeTab === 'rbac' ? 'active' : '' ?> fw-semibold" id="rbac-tab" data-bs-toggle="pill" data-bs-target="#rbac-pane" type="button">
            <i class="bi bi-shield-lock me-1"></i> Granular RBAC Permissions Matrix
        </button>
    </li>
</ul>

<div class="tab-content">
    <!-- Company Profile Settings -->
    <div class="tab-pane fade <?= $activeTab === 'company' ? 'show active' : '' ?>" id="comp-pane">
        <div class="card border-0 shadow-sm rounded-3" style="max-width: 800px;">
            <div class="card-body p-4">
                <form method="POST" action="<?= BASE_URL ?>/modules/settings/index.php">
                    <?= getCsrfField() ?>
                    <input type="hidden" name="save_settings" value="1">
                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label small fw-semibold">Company Name</label>
                            <input type="text" class="form-control" name="company_name" value="<?= htmlspecialchars($company['name'] ?? '') ?>" required>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label small fw-semibold">Head Office City</label>
                            <input type="text" class="form-control" name="city" value="<?= htmlspecialchars($company['city'] ?? 'Gujranwala') ?>">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label small fw-semibold">Phone Number</label>
                            <input type="text" class="form-control" name="phone" value="<?= htmlspecialchars($company['phone'] ?? '') ?>">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label small fw-semibold">Email Address</label>
                            <input type="email" class="form-control" name="email" value="<?= htmlspecialchars($company['email'] ?? '') ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold">Physical Warehouse / Office Address</label>
                            <textarea class="form-control" name="address" rows="2"><?= htmlspecialchars($company['address'] ?? '') ?></textarea>
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label small fw-semibold">Currency Code</label>
                            <input type="text" class="form-control" name="currency" value="<?= htmlspecialchars($company['currency'] ?? 'PKR') ?>">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label small fw-semibold">Currency Symbol</label>
                            <input type="text" class="form-control" name="currency_symbol" value="<?= htmlspecialchars($company['currency_symbol'] ?? 'Rs.') ?>">
                        </div>
                        <div class="col-12 mt-4">
                            <button type="submit" class="btn btn-primary px-4">
                                <i class="bi bi-check-lg me-1"></i> Save Company Profile
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Document Terms & Print Directives -->
    <div class="tab-pane fade <?= $activeTab === 'terms' ? 'show active' : '' ?>" id="terms-pane">
        <div class="card border-0 shadow-sm rounded-3" style="max-width: 860px;">
            <div class="card-header bg-white py-3 border-0">
                <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-printer me-2 text-primary"></i>Print Document Terms & Directives</h6>
                <small class="text-muted">Configure written legal terms, warranty conditions, and security directives printed on Bills, Gate Passes, and Account Statements.</small>
            </div>
            <div class="card-body p-4 pt-0">
                <form method="POST" action="<?= BASE_URL ?>/modules/settings/index.php">
                    <?= getCsrfField() ?>
                    <input type="hidden" name="save_doc_terms" value="1">

                    <div class="mb-4">
                        <label class="form-label small fw-bold text-dark d-flex align-items-center">
                            <i class="bi bi-receipt text-primary me-2"></i>Sales Invoice / Bill Terms of Supply
                        </label>
                        <textarea class="form-control" name="bill_terms_conditions" rows="3" required><?= htmlspecialchars($billTermsVal) ?></textarea>
                        <small class="text-muted">Appears at the bottom of commercial sales bills (<code>modules/orders/bill.php</code>) under "TERMS OF SUPPLY".</small>
                    </div>

                    <div class="mb-4">
                        <label class="form-label small fw-bold text-dark d-flex align-items-center">
                            <i class="bi bi-shield-check text-success me-2"></i>Warehouse Outward Gate Pass Security Directive
                        </label>
                        <textarea class="form-control" name="gatepass_security_directive" rows="3" required><?= htmlspecialchars($gpDirectiveVal) ?></textarea>
                        <small class="text-muted">Appears above chain-of-custody signatures on gate passes (<code>modules/warehouse/gatepass.php</code>) under "SECURITY CLEARANCE DIRECTIVE".</small>
                    </div>

                    <div class="mb-4">
                        <label class="form-label small fw-bold text-dark d-flex align-items-center">
                            <i class="bi bi-journal-text text-info me-2"></i>Customer Account Statement / Ledger Terms
                        </label>
                        <textarea class="form-control" name="customer_ledger_terms" rows="2" required><?= htmlspecialchars($custTermsVal) ?></textarea>
                        <small class="text-muted">Appears on customer ledger statements (<code>modules/customers/ledger.php</code>) under "STATEMENT TERMS".</small>
                    </div>

                    <div class="mb-4">
                        <label class="form-label small fw-bold text-dark d-flex align-items-center">
                            <i class="bi bi-journal-bookmark text-warning me-2"></i>Supplier Accounts Payable Statement Terms
                        </label>
                        <textarea class="form-control" name="supplier_ledger_terms" rows="2" required><?= htmlspecialchars($suppTermsVal) ?></textarea>
                        <small class="text-muted">Appears on supplier payable statements (<code>modules/suppliers/ledger.php</code>) under "STATEMENT TERMS".</small>
                    </div>

                    <div class="mt-4">
                        <button type="submit" class="btn btn-primary px-4">
                            <i class="bi bi-check-lg me-1"></i> Save Document Terms
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Area & Route Assignment Policy Pane -->
    <div class="tab-pane fade <?= $activeTab === 'routes' ? 'show active' : '' ?>" id="routes-pane">
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-header bg-white py-3 border-0">
                <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-geo-alt-fill text-primary me-2"></i>Field Sales Route & Territory Assignment Policy</h6>
                <small class="text-muted">Configure how territories are assigned to order bookers & delivery riders (Weekly, Daily, Monthly, or Fixed)</small>
            </div>
            <div class="card-body p-4">
                <form method="POST" action="<?= BASE_URL ?>/modules/settings/index.php">
                    <?= getCsrfField() ?>
                    <input type="hidden" name="save_route_policy" value="1">

                    <div class="mb-4">
                        <label class="form-label small fw-bold text-dark d-flex align-items-center">
                            <i class="bi bi-calendar-week text-primary me-2"></i>Territory Assignment Cadence
                        </label>
                        <select class="form-select" name="route_assignment_cadence" required>
                            <option value="weekly" <?= $routeCadenceVal === 'weekly' ? 'selected' : '' ?>>
                                Weekly Journey Plan (PJP) - Routes and areas are scheduled weekly (Recommended for FMCG)
                            </option>
                            <option value="daily" <?= $routeCadenceVal === 'daily' ? 'selected' : '' ?>>
                                Daily Beat Assignment - Areas are scheduled on a specific day-by-day basis
                            </option>
                            <option value="monthly" <?= $routeCadenceVal === 'monthly' ? 'selected' : '' ?>>
                                Monthly Cycle Routing - Scheduled across the monthly routing cycle
                            </option>
                            <option value="fixed" <?= $routeCadenceVal === 'fixed' ? 'selected' : '' ?>>
                                Fixed Permanent Assignment - Order Bookers and Riders have permanent fixed areas
                            </option>
                        </select>
                        <small class="text-muted mt-1 d-block">
                            Defines how the Territory Assignment management view (<code>modules/areas/index.php</code>) scopes assignments for Order Bookers and Delivery Drivers.
                        </small>
                    </div>

                    <div class="card bg-light border p-3 mb-4 rounded-3">
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" name="route_coverage_tracking" id="route_coverage_tracking" value="1" <?= $routeCoverageVal === '1' ? 'checked' : '' ?>>
                            <label class="form-check-label fw-bold text-dark small" for="route_coverage_tracking">
                                Enable Market Shop Coverage Percentage & Progress Tracking
                            </label>
                        </div>
                        <small class="text-muted ms-4 d-block">
                            When enabled, displays live analytics on territory coverage: registered shops, unique shops booked in the scheduled period, and visual percentage progress bars (e.g. 10/10 shops &bull; 100% covered).
                        </small>
                    </div>

                    <div class="card bg-light border p-3 mb-4 rounded-3">
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" name="route_allow_flexible_days" id="route_allow_flexible_days" value="1" <?= $routeFlexibleVal === '1' ? 'checked' : '' ?>>
                            <label class="form-check-label fw-bold text-dark small" for="route_allow_flexible_days">
                                Allow Flexible Day Area Execution within Scheduled Cadence
                            </label>
                        </div>
                        <small class="text-muted ms-4 d-block">
                            Order bookers and riders have the operational autonomy to decide which assigned area to cover on which day of the week, provided all assigned shops are completed during the scheduled period.
                        </small>
                    </div>

                    <div class="mt-4">
                        <button type="submit" class="btn btn-primary px-4">
                            <i class="bi bi-check-lg me-1"></i> Save Route & Area Policy
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Procurement & Purchasing Policy Pane -->
    <div class="tab-pane fade <?= $activeTab === 'purchases' ? 'show active' : '' ?>" id="purchases-pane">
        <div class="card border-0 shadow-sm rounded-3" style="max-width: 860px;">
            <div class="card-header bg-white py-3 border-0">
                <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-cart-check text-primary me-2"></i>Procurement & Stock Receiving Policy</h6>
                <small class="text-muted">Configure direct purchase instant stock replenishment vs enterprise two-step goods receiving (GRN) workflow</small>
            </div>
            <div class="card-body p-4 pt-0">
                <form method="POST" action="<?= BASE_URL ?>/modules/settings/index.php">
                    <?= getCsrfField() ?>
                    <input type="hidden" name="save_purchase_policy" value="1">

                    <div class="mb-4">
                        <label class="form-label small fw-bold text-dark d-flex align-items-center mb-2">
                            <i class="bi bi-box-arrow-in-down text-success me-2"></i>Default Stock Receiving Mode
                        </label>
                        <div class="row g-3">
                            <div class="col-12 col-md-6">
                                <div class="card h-100 border p-3 rounded-3 <?= $purchaseReceivingVal === 'direct_receive' ? 'border-primary bg-primary bg-opacity-10' : 'bg-light' ?>">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="purchase_receiving_mode" id="mode_direct" value="direct_receive" <?= $purchaseReceivingVal === 'direct_receive' ? 'checked' : '' ?>>
                                        <label class="form-check-label fw-bold text-dark" for="mode_direct">
                                            Direct Purchase / Instant Stock Fill
                                            <span class="badge bg-success ms-1 small">Small Business</span>
                                        </label>
                                    </div>
                                    <small class="text-muted mt-2 d-block ps-4">
                                        Stock is immediately loaded into warehouse inventory and a Goods Receipt Note (GRN) is automatically generated and posted. Skips the extra manual receiving step for fast business operations.
                                    </small>
                                </div>
                            </div>
                            <div class="col-12 col-md-6">
                                <div class="card h-100 border p-3 rounded-3 <?= $purchaseReceivingVal === 'two_step_grn' ? 'border-primary bg-primary bg-opacity-10' : 'bg-light' ?>">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="purchase_receiving_mode" id="mode_twostep" value="two_step_grn" <?= $purchaseReceivingVal === 'two_step_grn' ? 'checked' : '' ?>>
                                        <label class="form-check-label fw-bold text-dark" for="mode_twostep">
                                            Two-Step Procurement (PO &rarr; GRN)
                                            <span class="badge bg-secondary ms-1 small">Enterprise</span>
                                        </label>
                                    </div>
                                    <small class="text-muted mt-2 d-block ps-4">
                                        Generates a Purchase Order in <em>submitted</em> status. Warehouse dock staff must physically inspect items and record a separate GRN before stock enters available inventory.
                                    </small>
                                </div>
                            </div>
                        </div>
                        <small class="text-muted mt-2 d-block">
                            Note: Regardless of the default setting chosen here, order bookers and managers can still toggle between modes on-the-fly when creating individual purchase orders.
                        </small>
                    </div>

                    <div class="mb-4">
                        <label class="form-label small fw-bold text-dark d-flex align-items-center mb-2">
                            <i class="bi bi-cash-coin text-primary me-2"></i>Default Purchase Payment Preference
                        </label>
                        <div class="row g-3">
                            <div class="col-12 col-md-6">
                                <div class="card h-100 border p-3 rounded-3 <?= $purchasePaymentVal === 'credit' ? 'border-primary bg-primary bg-opacity-10' : 'bg-light' ?>">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="purchase_default_payment_mode" id="pay_credit" value="credit" <?= $purchasePaymentVal === 'credit' ? 'checked' : '' ?>>
                                        <label class="form-check-label fw-bold text-dark" for="pay_credit">
                                            Credit / On Account (Unpaid)
                                        </label>
                                    </div>
                                    <small class="text-muted mt-2 d-block ps-4">
                                        Purchases are booked as Accounts Payable liabilities in the supplier's financial ledger. Payments are settled separately later.
                                    </small>
                                </div>
                            </div>
                            <div class="col-12 col-md-6">
                                <div class="card h-100 border p-3 rounded-3 <?= $purchasePaymentVal === 'full' ? 'border-primary bg-primary bg-opacity-10' : 'bg-light' ?>">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="purchase_default_payment_mode" id="pay_full" value="full" <?= $purchasePaymentVal === 'full' ? 'checked' : '' ?>>
                                        <label class="form-check-label fw-bold text-dark" for="pay_full">
                                            Full Immediate Payment
                                        </label>
                                    </div>
                                    <small class="text-muted mt-2 d-block ps-4">
                                        Payment collection is pre-selected on the purchase screen, automatically issuing an immediate cash/bank disbursement voucher.
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label small fw-bold text-dark d-flex align-items-center">
                            <i class="bi bi-wallet2 text-info me-2"></i>Default Disbursing Payment Account
                        </label>
                        <select class="form-select" name="purchase_default_payment_account" style="max-width: 450px;">
                            <?php foreach ($purchaseAccounts as $acc): ?>
                                <option value="<?= $acc['id'] ?>" <?= $purchaseAccountVal === (int)$acc['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($acc['code']) ?> - <?= htmlspecialchars($acc['name']) ?> (<?= htmlspecialchars($acc['system_tag'] ?: 'Account') ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted mt-1 d-block">
                            Default chart of accounts ledger used when disbursing supplier payments at purchase creation.
                        </small>
                    </div>

                    <div class="mt-4">
                        <button type="submit" class="btn btn-primary px-4">
                            <i class="bi bi-check-lg me-1"></i> Save Procurement Policy
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- RBAC Granular Matrix -->
    <div class="tab-pane fade <?= $activeTab === 'rbac' ? 'show active' : '' ?>" id="rbac-pane">
        <div class="card border-0 shadow-sm rounded-3 mb-3">
            <div class="card-body p-3">
                <div class="d-flex flex-wrap gap-2 align-items-center">
                    <span class="fw-bold small text-dark me-2">Select Role to Configure:</span>
                    <?php foreach ($roles as $r): ?>
                        <a href="<?= BASE_URL ?>/modules/settings/index.php?tab=rbac&role_id=<?= $r['id'] ?>" class="btn btn-sm <?= $selectedRoleId == $r['id'] ? 'btn-primary' : 'btn-light border' ?>">
                            <?= htmlspecialchars($r['name']) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <form method="POST" action="<?= BASE_URL ?>/modules/settings/index.php">
            <?= getCsrfField() ?>
            <input type="hidden" name="save_permissions" value="1">
            <input type="hidden" name="role_id" value="<?= $selectedRoleId ?>">

            <div class="card border-0 shadow-sm rounded-3">
                <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="fw-bold mb-0 text-dark">
                            Role Permissions for: <span class="text-primary"><?php
                                $rName = '';
                                foreach ($roles as $r) { if ($r['id'] == $selectedRoleId) $rName = $r['name']; }
                                echo htmlspecialchars($rName);
                            ?></span>
                        </h6>
                        <small class="text-muted">Checked permissions are active and enforced server-side</small>
                    </div>
                    <?php if ($selectedRoleId !== 1): ?>
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="bi bi-save me-1"></i> Save Changes to Role
                        </button>
                    <?php else: ?>
                        <span class="badge bg-success">Super Admin has Full System Access</span>
                    <?php endif; ?>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover table-bordered align-middle mb-0">
                            <thead class="table-light small">
                                <tr>
                                    <th style="width: 80px;" class="text-center">Status</th>
                                    <th>Module</th>
                                    <th>Action</th>
                                    <th>Permission Description</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($allPerms as $perm): ?>
                                    <?php $isChecked = in_array($perm['id'], $rolePermIds) || $selectedRoleId === 1; ?>
                                    <tr>
                                        <td class="text-center">
                                            <input class="form-check-input" type="checkbox" name="perm[]" value="<?= $perm['id'] ?>" <?= $isChecked ? 'checked' : '' ?> <?= $selectedRoleId === 1 ? 'disabled' : '' ?>>
                                        </td>
                                        <td class="fw-bold text-dark font-monospace"><?= htmlspecialchars($perm['module']) ?></td>
                                        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($perm['action']) ?></span></td>
                                        <td class="small text-muted"><?= htmlspecialchars($perm['description'] ?? '') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <!-- Distribution Operational Workflow Mode -->
    <div class="tab-pane fade <?= $activeTab === 'opmode' ? 'show active' : '' ?>" id="opmode-pane">
        <div class="card border-0 shadow-sm rounded-3" style="max-width: 860px;">
            <div class="card-body p-4">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <span class="badge bg-primary-subtle text-primary p-2 fs-5 rounded-3"><i class="bi bi-toggles"></i></span>
                    <div>
                        <h5 class="fw-bold mb-0 text-dark">Distribution Operational Workflow Mode</h5>
                        <small class="text-muted">Configure whether DMS runs on traditional high-speed back-office desktop workflows, mobile apps, or hybrid</small>
                    </div>
                </div>

                <form method="POST" action="<?= BASE_URL ?>/modules/settings/index.php?tab=opmode">
                    <?= getCsrfField() ?>
                    <input type="hidden" name="save_operational_mode" value="1">

                    <div class="mb-4">
                        <label class="form-label small fw-bold text-dark d-flex align-items-center">
                            <i class="bi bi-diagram-3-fill text-primary me-2"></i>Primary System Operational Mode
                        </label>
                        <div class="row g-3">
                            <div class="col-12 col-md-4">
                                <label class="card h-100 p-3 border-2 cursor-pointer <?= $systemOpMode === 'traditional' ? 'border-primary bg-primary-subtle text-primary' : 'border-light-subtle' ?>">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="system_operational_mode" value="traditional" <?= $systemOpMode === 'traditional' ? 'checked' : '' ?>>
                                        <div class="fw-bold fs-6 text-dark mt-1">1. Traditional Desktop DMS</div>
                                    </div>
                                    <small class="text-muted mt-2 d-block">
                                        Tailored for non-tech field staff. Orders entered via rapid desktop matrix, 2-per-page A4 bills, paper run sheets & fast end-of-day grid reconciliation.
                                    </small>
                                </label>
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="card h-100 p-3 border-2 cursor-pointer <?= $systemOpMode === 'hybrid' ? 'border-primary bg-primary-subtle text-primary' : 'border-light-subtle' ?>">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="system_operational_mode" value="hybrid" <?= $systemOpMode === 'hybrid' ? 'checked' : '' ?>>
                                        <div class="fw-bold fs-6 text-dark mt-1">2. Hybrid Dual-Mode (Recommended)</div>
                                    </div>
                                    <small class="text-muted mt-2 d-block">
                                        Both traditional desktop speed tools (Excel import, batch booking, paper sheets) AND mobile field apps active concurrently.
                                    </small>
                                </label>
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="card h-100 p-3 border-2 cursor-pointer <?= $systemOpMode === 'app_driven' ? 'border-primary bg-primary-subtle text-primary' : 'border-light-subtle' ?>">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="system_operational_mode" value="app_driven" <?= $systemOpMode === 'app_driven' ? 'checked' : '' ?>>
                                        <div class="fw-bold fs-6 text-dark mt-1">3. Mobile App Automated</div>
                                    </div>
                                    <small class="text-muted mt-2 d-block">
                                        Digital-only workflow with live field booking app, GPS tracking, and digital delivery confirmations.
                                    </small>
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label small fw-bold text-dark d-flex align-items-center">
                            <i class="bi bi-printer-fill text-primary me-2"></i>Sales Bill Batch Print Layout (A4 Portrait)
                        </label>
                        <select class="form-select" name="batch_bills_per_a4">
                            <option value="2" <?= $batchBillsPerA4Val === 2 ? 'selected' : '' ?>>
                                2 Bills per A4 Portrait Sheet (Recommended FMCG Half-Sheet Cut)
                            </option>
                            <option value="1" <?= $batchBillsPerA4Val === 1 ? 'selected' : '' ?>>
                                1 Bill per A4 Sheet (Full Page Invoices)
                            </option>
                            <option value="3" <?= $batchBillsPerA4Val === 3 ? 'selected' : '' ?>>
                                3 Bills per A4 Sheet (Ultra-compact continuous receipt format)
                            </option>
                        </select>
                        <small class="text-muted mt-1 d-block">
                            Controls the CSS print layout formatting when batch printing customer invoices from the Sales Orders and Load Sheet centers.
                        </small>
                    </div>

                    <div class="mt-4 pt-2 border-top d-flex justify-content-end">
                        <button type="submit" class="btn btn-primary px-4 fw-semibold shadow-sm">
                            <i class="bi bi-save me-1"></i> Save Workflow Configuration
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
