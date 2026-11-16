<?php
/**
 * Customer Payment Recovery (فیلڈ وصولی / مارکیٹ ریکوری)
 * Dedicated Independent Shop-by-Shop Dues Collection & Receipt Issuance
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();

// Authorized for recovery staff, order bookers, salesmen, delivery men, accountants, and managers
$canAccess = hasPermission('payments', 'create') 
    || hasPermission('payments', 'view')
    || hasRole([ROLE_SUPER_ADMIN, ROLE_ADMIN, ROLE_ORDER_BOOKER, ROLE_SALESMAN, ROLE_DELIVERY_MAN, ROLE_ACCOUNTANT]);

if (!$canAccess) {
    requirePermission('payments', 'create');
}

$pdo = getDatabaseConnection();
$currentUser = getCurrentUser();
$roleSlug = $currentUser['role_slug'];
$currentEmp = getCurrentEmployee();
$isSuperAdmin = ($roleSlug === ROLE_SUPER_ADMIN);

// Process POST: Record Customer Payment Recovery
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_recovery'])) {
    validateCsrf();

    $customerId = (int)($_POST['customer_id'] ?? 0);
    $recoveryDate = sanitize($_POST['recovery_date'] ?? date('Y-m-d'));
    $amount = (float)($_POST['amount'] ?? 0.00);
    $methodCode = sanitize($_POST['payment_method_code'] ?? 'cash');
    $referenceNo = sanitize($_POST['reference_no'] ?? '');
    $notes = sanitize($_POST['notes'] ?? '');

    // Resolve Collector Employee ID: Only Super Administrator can record under another employee's name
    if ($isSuperAdmin) {
        $collectorId = !empty($_POST['collector_id']) ? (int)$_POST['collector_id'] : ($currentEmp['id'] ?? 0);
    } else {
        if (empty($currentEmp['id'])) {
            setFlashMessage('error', 'Access denied. You do not have an active employee profile linked to your user account.');
            redirect(BASE_URL . '/modules/payments/recovery.php');
        }
        $collectorId = (int)$currentEmp['id'];
    }

    if ($customerId <= 0) {
        setFlashMessage('error', 'Please select a valid customer / retail shop.');
        redirect(BASE_URL . '/modules/payments/recovery.php');
    }

    if ($amount <= 0.001) {
        setFlashMessage('error', 'Recovery amount must be greater than zero.');
        redirect(BASE_URL . '/modules/payments/recovery.php?customer_id=' . $customerId);
    }

    if ($collectorId <= 0) {
        setFlashMessage('error', 'Please assign a valid recovery officer or field agent.');
        redirect(BASE_URL . '/modules/payments/recovery.php?customer_id=' . $customerId);
    }

    // Verify Customer exists
    $cStmt = $pdo->prepare("SELECT id, shop_name, owner_name, current_balance, phone, whatsapp FROM `customers` WHERE id = ?");
    $cStmt->execute([$customerId]);
    $customer = $cStmt->fetch();

    if (!$customer) {
        setFlashMessage('error', 'Customer not found.');
        redirect(BASE_URL . '/modules/payments/recovery.php');
    }

    // Verify Payment Method
    $pmStmt = $pdo->prepare("SELECT * FROM `payment_methods` WHERE code = ? AND is_active = 1 LIMIT 1");
    $pmStmt->execute([$methodCode]);
    $pm = $pmStmt->fetch();

    if (!$pm) {
        // Fallback to cash
        $pmStmt = $pdo->query("SELECT * FROM `payment_methods` WHERE code = 'cash' LIMIT 1");
        $pm = $pmStmt->fetch();
        $methodCode = 'cash';
    }

    try {
        $pdo->beginTransaction();

        $receiptNumber = generateDocNumber($pdo, 'payments', 'payment_number', 'RCP-');
        
        // Handover Status: Physical cash held by collector requires handover to cashier/accountant
        $handoverStatus = ($methodCode === 'cash' || $methodCode === 'cheque') ? 'pending_handover' : 'reconciled';

        // 1. Insert into payments table
        $pStmt = $pdo->prepare("
            INSERT INTO `payments` (
                `payment_number`, `payment_type`, `reference_type`, `reference_id`,
                `party_type`, `party_id`, `amount`, `payment_method_id`,
                `payment_method_code`, `payment_account_id`, `payment_date`,
                `reference_no`, `collected_by`, `handover_status`, `notes`,
                `status`, `created_by`, `created_at`
            ) VALUES (?, 'customer_receipt', 'recovery', ?, 'customer', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'posted', ?, NOW())
        ");
        $pStmt->execute([
            $receiptNumber, $customerId, $customerId, $amount,
            $pm['id'], $methodCode, $pm['account_id'], $recoveryDate,
            $referenceNo, $collectorId, $handoverStatus, $notes,
            $currentUser['id']
        ]);
        $paymentId = (int)$pdo->lastInsertId();

        // 2. FIFO Settlement against unpaid sales invoices (if any exist for this shop)
        $unpaidInvoicesStmt = $pdo->prepare("
            SELECT id, invoice_number, grand_total, paid_amount, balance_due 
            FROM `sales_invoices` 
            WHERE customer_id = ? AND status IN ('posted', 'partially_paid') AND balance_due > 0 
            ORDER BY invoice_date ASC, id ASC
        ");
        $unpaidInvoicesStmt->execute([$customerId]);
        $unpaidInvoices = $unpaidInvoicesStmt->fetchAll();

        $remainingToAllocate = $amount;
        $allocatedInvoicesCount = 0;

        foreach ($unpaidInvoices as $inv) {
            if ($remainingToAllocate <= 0) break;
            $due = (float)$inv['balance_due'];
            $alloc = min($remainingToAllocate, $due);
            $newPaid = (float)$inv['paid_amount'] + $alloc;
            $newDue = $due - $alloc;
            $newInvStatus = ($newDue <= 0.001) ? 'paid' : 'partially_paid';

            // Insert allocation record
            $pdo->prepare("
                INSERT INTO `payment_allocations` (`payment_id`, `invoice_id`, `allocated_amount`, `created_at`) 
                VALUES (?, ?, ?, NOW())
            ")->execute([$paymentId, $inv['id'], $alloc]);

            // Update invoice status & balances
            $pdo->prepare("
                UPDATE `sales_invoices` 
                SET `paid_amount` = ?, `balance_due` = ?, `status` = ? 
                WHERE id = ?
            ")->execute([$newPaid, $newDue, $newInvStatus, $inv['id']]);

            $remainingToAllocate -= $alloc;
            $allocatedInvoicesCount++;
        }

        // 3. Post Double-Entry Accounting Journal Entry & Customer Balance Deduction
        // Debits Cash/Bank account, Credits Customer Receivables account, Decrements customer current_balance
        postCustomerPaymentAccounting(
            $pdo,
            $paymentId,
            $receiptNumber,
            $customerId,
            $amount,
            (int)$pm['account_id'],
            $currentUser['id']
        );

        // 4. Audit Log
        logAudit($pdo, 'payments', $paymentId, 'customer_recovery', null, [
            'receipt_number' => $receiptNumber,
            'customer_id' => $customerId,
            'shop_name' => $customer['shop_name'],
            'amount' => $amount,
            'method' => $methodCode,
            'collector_id' => $collectorId,
            'handover_status' => $handoverStatus
        ]);

        $pdo->commit();

        $successMsg = "Payment recovery <strong>{$receiptNumber}</strong> of " . formatMoney($amount) . " from <strong>" . htmlspecialchars($customer['shop_name']) . "</strong> recorded successfully!";
        setFlashMessage('success', $successMsg);
        redirect(BASE_URL . '/modules/payments/recovery.php?last_id=' . $paymentId . '&customer_id=' . $customerId);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        setFlashMessage('error', 'Payment recovery failed: ' . $e->getMessage());
        redirect(BASE_URL . '/modules/payments/recovery.php?customer_id=' . $customerId);
    }
}

// Fetch Pre-selected Customer (if query param provided)
$selectedCustId = (int)($_GET['customer_id'] ?? 0);
$selectedCustomer = null;
$customerUnpaidInvoices = [];

if ($selectedCustId > 0) {
    $scStmt = $pdo->prepare("
        SELECT c.*, a.name as area_name, e.full_name as booker_name
        FROM `customers` c
        JOIN `areas` a ON c.area_id = a.id
        LEFT JOIN `employees` e ON c.assigned_order_booker_id = e.id
        WHERE c.id = ?
    ");
    $scStmt->execute([$selectedCustId]);
    $selectedCustomer = $scStmt->fetch();

    if ($selectedCustomer) {
        $invStmt = $pdo->prepare("
            SELECT si.*, so.order_number 
            FROM `sales_invoices` si
            LEFT JOIN `sales_orders` so ON si.sales_order_id = so.id
            WHERE si.customer_id = ? AND si.status IN ('posted', 'partially_paid') AND si.balance_due > 0
            ORDER BY si.invoice_date ASC, si.id ASC
            LIMIT 10
        ");
        $invStmt->execute([$selectedCustId]);
        $customerUnpaidInvoices = $invStmt->fetchAll();
    }
}

// Fetch Active Customers List for Dropdown / Search (with balances)
$customers = $pdo->query("
    SELECT c.id, c.shop_name, c.owner_name, c.phone, c.current_balance, c.credit_limit, a.name as area_name
    FROM `customers` c
    JOIN `areas` a ON c.area_id = a.id
    WHERE c.status = 'active'
    ORDER BY c.current_balance DESC, c.shop_name ASC
")->fetchAll();

// Fetch Payment Methods
$paymentMethods = $pdo->query("SELECT * FROM `payment_methods` WHERE is_active = 1 ORDER BY id ASC")->fetchAll();

// Fetch Collectors / Recovery Personnel
$collectors = $pdo->query("
    SELECT id, employee_code, full_name, role_type, phone
    FROM `employees`
    WHERE status = 'active' 
      AND role_type IN ('order_booker', 'salesman', 'delivery_man', 'accountant', 'admin', 'super_admin')
    ORDER BY full_name ASC
")->fetchAll();

// Default collector: Parameter, logged in employee, or first collector
$defaultCollectorId = $isSuperAdmin 
    ? (!empty($_GET['collector_id']) ? (int)$_GET['collector_id'] : (!empty($currentEmp['id']) ? (int)$currentEmp['id'] : ($collectors[0]['id'] ?? 0)))
    : (int)($currentEmp['id'] ?? 0);

// Key Statistics for Today
$today = date('Y-m-d');
$statsStmt = $pdo->prepare("
    SELECT 
        COALESCE(SUM(amount), 0) as total_recovered_today,
        COALESCE(SUM(CASE WHEN payment_method_code = 'cash' AND handover_status = 'pending_handover' THEN amount ELSE 0 END), 0) as cash_custody_pending,
        COALESCE(SUM(CASE WHEN payment_method_code IN ('jazzcash', 'easypaisa', 'bank_transfer') THEN amount ELSE 0 END), 0) as digital_today,
        COUNT(id) as count_today
    FROM `payments`
    WHERE payment_type = 'customer_receipt' 
      AND payment_date = ? 
      AND status = 'posted'
");
$statsStmt->execute([$today]);
$todayStats = $statsStmt->fetch();

$totalMarketDues = (float)$pdo->query("SELECT COALESCE(SUM(current_balance), 0) FROM `customers` WHERE current_balance > 0")->fetchColumn();

// Fetch Recent Recoveries
$filterDate = sanitize($_GET['date'] ?? '');
$filterCollector = (int)($_GET['collector'] ?? 0);

$recWhere = ["p.payment_type = 'customer_receipt'", "p.status = 'posted'"];
$recParams = [];

if (!empty($filterDate)) {
    $recWhere[] = "p.payment_date = ?";
    $recParams[] = $filterDate;
}
if ($filterCollector > 0) {
    $recWhere[] = "p.collected_by = ?";
    $recParams[] = $filterCollector;
}

$recWhereSql = implode(' AND ', $recWhere);

$recentStmt = $pdo->prepare("
    SELECT p.*, c.shop_name, c.owner_name, c.phone as cust_phone, c.current_balance as latest_balance,
           a.name as area_name, e.full_name as collector_name, e.employee_code as collector_code
    FROM `payments` p
    JOIN `customers` c ON p.party_id = c.id
    JOIN `areas` a ON c.area_id = a.id
    LEFT JOIN `employees` e ON p.collected_by = e.id
    WHERE {$recWhereSql}
    ORDER BY p.id DESC
    LIMIT 50
");
$recentStmt->execute($recParams);
$recentRecoveries = $recentStmt->fetchAll();

// Last inserted payment for instant print preview
$lastPaymentId = (int)($_GET['last_id'] ?? 0);
$lastPayment = null;
if ($lastPaymentId > 0) {
    $lpStmt = $pdo->prepare("
        SELECT p.*, c.shop_name, c.owner_name, c.phone as cust_phone, c.whatsapp as cust_whatsapp, 
               c.current_balance as current_balance, a.name as area_name, e.full_name as collector_name
        FROM `payments` p
        JOIN `customers` c ON p.party_id = c.id
        JOIN `areas` a ON c.area_id = a.id
        LEFT JOIN `employees` e ON p.collected_by = e.id
        WHERE p.id = ?
    ");
    $lpStmt->execute([$lastPaymentId]);
    $lastPayment = $lpStmt->fetch();
}

$pageTitle = 'Customer Payment Recovery';
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<!-- Header Section -->
<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-3 gap-2">
    <div>
        <h4 class="fw-bold mb-0 text-dark">
            <i class="bi bi-cash-coin text-success me-2"></i>Customer Payment Recovery (فیلڈ وصولی)
        </h4>
        <small class="text-muted">Direct Market Credit Collections, Receipt Voucher Issuance & Driver Handover Tracking</small>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/modules/payments/handover.php" class="btn btn-warning btn-sm text-dark fw-bold">
            <i class="bi bi-wallet2 me-1"></i> Cash Handover & Recon
        </a>
        <a href="<?= BASE_URL ?>/modules/payments/index.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-list-ul me-1"></i> All Payments Registry
        </a>
    </div>
</div>

<!-- Instant Voucher Success Banner (If just recovered) -->
<?php if ($lastPayment): ?>
<div class="alert alert-success border-0 shadow-sm rounded-3 d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2 mb-4 p-3">
    <div>
        <div class="fw-bold fs-6 text-success">
            <i class="bi bi-check-circle-fill me-2"></i>Voucher #<?= htmlspecialchars($lastPayment['payment_number']) ?> Created Successfully!
        </div>
        <div class="small text-dark mt-1">
            Received <strong><?= formatMoney($lastPayment['amount']) ?></strong> from <strong><?= htmlspecialchars($lastPayment['shop_name']) ?></strong> 
            via <?= strtoupper($lastPayment['payment_method_code']) ?> (Collected by <?= htmlspecialchars($lastPayment['collector_name'] ?? 'Staff') ?>).
            Remaining Shop Balance: <strong><?= formatMoney($lastPayment['current_balance']) ?></strong>
        </div>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/modules/payments/receipt_print.php?id=<?= $lastPayment['id'] ?>" target="_blank" class="btn btn-success btn-sm fw-bold">
            <i class="bi bi-printer-fill me-1"></i> Print Receipt Voucher
        </a>
        <button type="button" class="btn btn-outline-success btn-sm fw-bold" onclick="shareVoucherWhatsApp('<?= htmlspecialchars($lastPayment['shop_name']) ?>', '<?= htmlspecialchars($lastPayment['payment_number']) ?>', '<?= number_format($lastPayment['amount'], 2) ?>', '<?= number_format($lastPayment['current_balance'], 2) ?>', '<?= htmlspecialchars($lastPayment['cust_whatsapp'] ?: $lastPayment['cust_phone']) ?>')">
            <i class="bi bi-whatsapp me-1"></i> Share WhatsApp
        </button>
    </div>
</div>
<?php endif; ?>

<!-- Top Key Statistics Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm p-3 bg-white rounded-3 h-100 border-start border-4 border-success">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-muted small fw-semibold">Recovered Today</div>
                    <div class="h5 fw-bold text-success mb-0"><?= formatMoney($todayStats['total_recovered_today']) ?></div>
                </div>
                <div class="p-2 bg-success-subtle text-success rounded-circle">
                    <i class="bi bi-cash-stack fs-5"></i>
                </div>
            </div>
            <small class="text-muted mt-1 d-block"><?= (int)$todayStats['count_today'] ?> market receipts today</small>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm p-3 bg-white rounded-3 h-100 border-start border-4 border-warning">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-muted small fw-semibold">Cash in Field Custody</div>
                    <div class="h5 fw-bold text-warning text-dark mb-0"><?= formatMoney($todayStats['cash_custody_pending']) ?></div>
                </div>
                <div class="p-2 bg-warning-subtle text-warning rounded-circle">
                    <i class="bi bi-wallet-fill fs-5"></i>
                </div>
            </div>
            <small class="text-muted mt-1 d-block">Held by recovery / delivery agents</small>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm p-3 bg-white rounded-3 h-100 border-start border-4 border-info">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-muted small fw-semibold">Digital Recoveries</div>
                    <div class="h5 fw-bold text-info mb-0"><?= formatMoney($todayStats['digital_today']) ?></div>
                </div>
                <div class="p-2 bg-info-subtle text-info rounded-circle">
                    <i class="bi bi-phone fs-5"></i>
                </div>
            </div>
            <small class="text-muted mt-1 d-block">JazzCash / EasyPaisa / Bank</small>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm p-3 bg-white rounded-3 h-100 border-start border-4 border-danger">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-muted small fw-semibold">Total Market Dues</div>
                    <div class="h5 fw-bold text-danger mb-0"><?= formatMoney($totalMarketDues) ?></div>
                </div>
                <div class="p-2 bg-danger-subtle text-danger rounded-circle">
                    <i class="bi bi-exclamation-octagon fs-5"></i>
                </div>
            </div>
            <small class="text-muted mt-1 d-block">Receivables outstanding across shops</small>
        </div>
    </div>
</div>

<div class="row g-3">
    <!-- LEFT: Recovery Entry Form -->
    <div class="col-12 col-lg-5">
        <div class="card border-0 shadow-sm rounded-3 mb-3">
            <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0 text-dark">
                    <i class="bi bi-pencil-square text-success me-2"></i>Record New Recovery
                </h6>
                <span class="badge bg-success-subtle text-success border border-success-subtle">Direct Collection</span>
            </div>
            <div class="card-body p-3">
                <form method="POST" action="<?= BASE_URL ?>/modules/payments/recovery.php" id="recoveryForm">
                    <?= getCsrfField() ?>
                    <input type="hidden" name="record_recovery" value="1">

                    <!-- Customer Selection -->
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Select Customer / Retail Shop <span class="text-danger">*</span></label>
                        <select class="form-select form-select-lg" name="customer_id" id="customerSelect" required onchange="handleCustomerChange(this.value)">
                            <option value="">-- Choose Retail Shop --</option>
                            <?php foreach ($customers as $c): ?>
                                <option value="<?= $c['id'] ?>" 
                                        data-balance="<?= (float)$c['current_balance'] ?>"
                                        data-phone="<?= htmlspecialchars($c['phone']) ?>"
                                        data-owner="<?= htmlspecialchars($c['owner_name']) ?>"
                                        data-area="<?= htmlspecialchars($c['area_name']) ?>"
                                        data-limit="<?= (float)$c['credit_limit'] ?>"
                                        <?= $c['id'] == $selectedCustId ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($c['shop_name']) ?> (<?= htmlspecialchars($c['area_name']) ?>) - Due: <?= formatMoney($c['current_balance']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Customer Profile & Balance Snapshot Card -->
                    <div id="customerInfoBox" class="p-3 bg-light rounded-3 mb-3 border <?= empty($selectedCustomer) ? 'd-none' : '' ?>">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div>
                                <h6 class="fw-bold text-dark mb-0" id="infoShopName"><?= htmlspecialchars($selectedCustomer['shop_name'] ?? '') ?></h6>
                                <small class="text-muted" id="infoOwnerArea">
                                    Owner: <span id="infoOwner"><?= htmlspecialchars($selectedCustomer['owner_name'] ?? '') ?></span> | 
                                    Area: <span id="infoArea"><?= htmlspecialchars($selectedCustomer['area_name'] ?? '') ?></span>
                                </small>
                            </div>
                            <span class="badge bg-light text-dark border" id="infoPhone"><?= htmlspecialchars($selectedCustomer['phone'] ?? '') ?></span>
                        </div>

                        <div class="p-2 bg-white rounded border d-flex justify-content-between align-items-center">
                            <div>
                                <div class="text-muted small">Current Outstanding Dues:</div>
                                <div class="h5 fw-bold mb-0 text-danger" id="infoBalanceDisplay">
                                    <?= formatMoney($selectedCustomer['current_balance'] ?? 0) ?>
                                </div>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-danger fw-bold" id="btnFillFullBalance" onclick="fillAmount('full')">
                                <i class="bi bi-arrow-down-left me-1"></i>Pay Full
                            </button>
                        </div>
                    </div>

                    <!-- Recovery Amount -->
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label class="form-label small fw-semibold mb-0">Recovered Amount (Rs.) <span class="text-danger">*</span></label>
                            <div class="btn-group btn-group-sm">
                                <button type="button" class="btn btn-outline-secondary py-0 px-2" onclick="fillAmount('50')">50%</button>
                                <button type="button" class="btn btn-outline-secondary py-0 px-2" onclick="fillAmount('25')">25%</button>
                                <button type="button" class="btn btn-outline-secondary py-0 px-2" onclick="fillAmount('clear')">Clear</button>
                            </div>
                        </div>
                        <div class="input-group">
                            <span class="input-group-text bg-white fw-bold">Rs.</span>
                            <input type="number" class="form-control form-control-lg fw-bold text-success" name="amount" id="inputAmount" placeholder="0.00" min="1" step="0.01" required>
                        </div>
                        <div class="form-text small" id="amountInWordsText">Enter physical cash or digital transfer collected from customer</div>
                    </div>

                    <!-- Payment Method Selection (Pills) -->
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Payment Mode <span class="text-danger">*</span></label>
                        <div class="row g-2">
                            <?php foreach ($paymentMethods as $idx => $pm): ?>
                                <div class="col-6">
                                    <div class="form-check p-2 border rounded bg-white">
                                        <input class="form-check-input ms-0 me-2" type="radio" name="payment_method_code" id="pm_<?= $pm['code'] ?>" value="<?= $pm['code'] ?>" <?= ($idx === 0 || $pm['code'] === 'cash') ? 'checked' : '' ?> onchange="handleMethodChange(this.value)">
                                        <label class="form-check-label fw-semibold small text-dark" for="pm_<?= $pm['code'] ?>">
                                            <?php if ($pm['code'] === 'cash'): ?>
                                                <i class="bi bi-cash me-1 text-success"></i> Cash in Hand
                                            <?php elseif ($pm['code'] === 'jazzcash'): ?>
                                                <i class="bi bi-phone me-1 text-danger"></i> JazzCash
                                            <?php elseif ($pm['code'] === 'easypaisa'): ?>
                                                <i class="bi bi-wallet2 me-1 text-success"></i> EasyPaisa
                                            <?php elseif ($pm['code'] === 'bank_transfer'): ?>
                                                <i class="bi bi-bank me-1 text-primary"></i> Bank Transfer
                                            <?php elseif ($pm['code'] === 'cheque'): ?>
                                                <i class="bi bi-card-heading me-1 text-warning"></i> Cheque
                                            <?php else: ?>
                                                <i class="bi bi-credit-card me-1 text-secondary"></i> <?= htmlspecialchars($pm['name']) ?>
                                            <?php endif; ?>
                                        </label>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Handover Status Indicator Alert -->
                    <div id="handoverAlert" class="alert alert-warning py-2 px-3 small border-0 rounded-2 mb-3">
                        <i class="bi bi-info-circle me-1"></i><strong>Physical Cash:</strong> This cash will be placed under the recovery officer's custody and must be surrendered during <strong>Cash Handover</strong>.
                    </div>

                    <!-- Reference No / Transaction ID (Conditional) -->
                    <div class="mb-3" id="refGroup">
                        <label class="form-label small fw-semibold" id="refLabel">Transaction ID / Cheque # / Slip Ref</label>
                        <input type="text" class="form-control" name="reference_no" id="inputRef" placeholder="e.g. TID-9823482 or Cheque # 049281">
                    </div>

                    <div class="row g-2 mb-3">
                        <!-- Collector / Recovery Officer -->
                        <div class="col-12 col-md-6">
                            <label class="form-label small fw-semibold">Collected By <span class="text-danger">*</span></label>
                            <?php if ($isSuperAdmin): ?>
                                <select class="form-select form-select-sm" name="collector_id" id="collectorSelect" required>
                                    <?php foreach ($collectors as $col): ?>
                                        <option value="<?= $col['id'] ?>" <?= $col['id'] == $defaultCollectorId ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($col['full_name']) ?> (<?= htmlspecialchars($col['employee_code']) ?> - <?= ucwords(str_replace('_', ' ', $col['role_type'])) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else: ?>
                                <input type="hidden" name="collector_id" value="<?= (int)($currentEmp['id'] ?? 0) ?>">
                                <select class="form-select form-select-sm bg-light" disabled>
                                    <option value="<?= (int)($currentEmp['id'] ?? 0) ?>" selected>
                                        <?= htmlspecialchars($currentEmp['full_name'] ?? 'Myself') ?> (<?= htmlspecialchars($currentEmp['employee_code'] ?? '') ?> - <?= ucwords(str_replace('_', ' ', $currentEmp['role_type'] ?? 'Staff')) ?>)
                                    </option>
                                </select>
                                <small class="text-muted" style="font-size:0.7rem;"><i class="bi bi-shield-lock text-secondary me-1"></i>Locked to your collector ID (Super Admin can reassign)</small>
                            <?php endif; ?>
                        </div>

                        <!-- Recovery Date -->
                        <div class="col-12 col-md-6">
                            <label class="form-label small fw-semibold">Recovery Date</label>
                            <input type="date" class="form-control form-control-sm" name="recovery_date" value="<?= date('Y-m-d') ?>" required>
                        </div>
                    </div>

                    <!-- Notes -->
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Remarks / Notes</label>
                        <textarea class="form-control form-control-sm" name="notes" rows="2" placeholder="e.g. Shopkeeper paid weekly outstanding balance. No dispute."></textarea>
                    </div>

                    <button type="submit" class="btn btn-success w-100 btn-touch shadow-sm fw-bold py-2">
                        <i class="bi bi-check2-circle me-1"></i> Save Recovery & Generate Receipt
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- RIGHT: Unpaid Invoices & Market Debtors Overview -->
    <div class="col-12 col-lg-7">
        <?php if (!empty($customerUnpaidInvoices)): ?>
            <!-- Outstanding Invoices for Selected Customer -->
            <div class="card border-0 shadow-sm rounded-3 mb-3">
                <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="fw-bold mb-0 text-dark">
                            <i class="bi bi-receipt me-2 text-primary"></i>Pending Invoices to Settle
                        </h6>
                        <small class="text-muted">Recoveries automatically settle oldest unpaid bills (FIFO)</small>
                    </div>
                    <span class="badge bg-primary rounded-pill"><?= count($customerUnpaidInvoices) ?> Invoices</span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover table-sm align-middle mb-0">
                            <thead class="table-light small">
                                <tr>
                                    <th>Invoice #</th>
                                    <th>Date</th>
                                    <th class="text-end">Total Bill</th>
                                    <th class="text-end">Paid So Far</th>
                                    <th class="text-end">Remaining Due</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($customerUnpaidInvoices as $inv): ?>
                                    <tr>
                                        <td class="fw-bold text-primary">
                                            <a href="<?= BASE_URL ?>/modules/orders/bill.php?invoice_id=<?= $inv['id'] ?>" target="_blank" class="text-decoration-none">
                                                <?= htmlspecialchars($inv['invoice_number']) ?>
                                            </a>
                                        </td>
                                        <td><?= formatDate($inv['invoice_date']) ?></td>
                                        <td class="text-end"><?= formatMoney($inv['grand_total']) ?></td>
                                        <td class="text-end text-success"><?= formatMoney($inv['paid_amount']) ?></td>
                                        <td class="text-end fw-bold text-danger"><?= formatMoney($inv['balance_due']) ?></td>
                                        <td>
                                            <span class="badge bg-warning text-dark" style="font-size: 0.68rem;"><?= ucfirst($inv['status']) ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Recent Recoveries Registry -->
        <div class="card border-0 shadow-sm rounded-3 mb-3">
            <div class="card-header bg-white py-3 border-0 d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <h6 class="fw-bold mb-0 text-dark">
                        <i class="bi bi-clock-history me-2 text-success"></i>Recent Market Recoveries
                    </h6>
                    <small class="text-muted">Live ledger audit of all shop collections</small>
                </div>
                <div class="d-flex gap-2">
                    <form method="GET" action="<?= BASE_URL ?>/modules/payments/recovery.php" class="d-flex gap-1">
                        <input type="date" name="date" class="form-control form-control-sm" value="<?= htmlspecialchars($filterDate) ?>">
                        <button type="submit" class="btn btn-sm btn-outline-secondary">Filter</button>
                        <?php if (!empty($filterDate) || $filterCollector > 0): ?>
                            <a href="<?= BASE_URL ?>/modules/payments/recovery.php" class="btn btn-sm btn-light border">Reset</a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
            <div class="card-body p-0">
                <?php if (empty($recentRecoveries)): ?>
                    <div class="p-4 text-center text-muted">No recent recoveries found.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-responsive-card align-middle mb-0">
                            <thead class="table-light small">
                                <tr>
                                    <th>Voucher #</th>
                                    <th>Date</th>
                                    <th>Customer / Area</th>
                                    <th>Collector</th>
                                    <th>Method</th>
                                    <th class="text-end">Amount</th>
                                    <th>Handover</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentRecoveries as $rec): ?>
                                    <tr>
                                        <td data-label="Voucher #" class="fw-bold text-primary">
                                            <a href="<?= BASE_URL ?>/modules/payments/receipt_print.php?id=<?= $rec['id'] ?>" target="_blank" class="text-decoration-none">
                                                <?= htmlspecialchars($rec['payment_number']) ?>
                                            </a>
                                        </td>
                                        <td data-label="Date"><?= formatDate($rec['payment_date']) ?></td>
                                        <td data-label="Customer">
                                            <div class="fw-bold text-dark"><?= htmlspecialchars($rec['shop_name']) ?></div>
                                            <small class="text-muted"><?= htmlspecialchars($rec['area_name']) ?></small>
                                        </td>
                                        <td data-label="Collector">
                                            <div class="text-dark small fw-semibold"><?= htmlspecialchars($rec['collector_name'] ?? '-') ?></div>
                                        </td>
                                        <td data-label="Method">
                                            <span class="badge bg-light text-dark border"><?= strtoupper($rec['payment_method_code']) ?></span>
                                            <?php if (!empty($rec['reference_no'])): ?>
                                                <div class="text-muted" style="font-size: 0.65rem;"><?= htmlspecialchars($rec['reference_no']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Amount" class="text-end fw-bold text-success fs-6">
                                            <?= formatMoney($rec['amount']) ?>
                                        </td>
                                        <td data-label="Handover">
                                            <?php if ($rec['handover_status'] === 'pending_handover'): ?>
                                                <span class="badge bg-warning text-dark" style="font-size: 0.65rem;">Pending Handover</span>
                                            <?php elseif ($rec['handover_status'] === 'handed_over'): ?>
                                                <span class="badge bg-info text-dark" style="font-size: 0.65rem;">Handed Over</span>
                                            <?php elseif ($rec['handover_status'] === 'reconciled'): ?>
                                                <span class="badge bg-success" style="font-size: 0.65rem;">Reconciled</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary" style="font-size: 0.65rem;">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Action" class="text-end">
                                            <div class="btn-group btn-group-sm">
                                                <a href="<?= BASE_URL ?>/modules/payments/receipt_print.php?id=<?= $rec['id'] ?>" target="_blank" class="btn btn-outline-primary" title="Print Receipt">
                                                    <i class="bi bi-printer"></i>
                                                </a>
                                                <button type="button" class="btn btn-outline-success" title="Share WhatsApp" onclick="shareVoucherWhatsApp('<?= htmlspecialchars($rec['shop_name']) ?>', '<?= htmlspecialchars($rec['payment_number']) ?>', '<?= number_format($rec['amount'], 2) ?>', '<?= number_format($rec['latest_balance'], 2) ?>', '<?= htmlspecialchars($rec['cust_phone']) ?>')">
                                                    <i class="bi bi-whatsapp"></i>
                                                </button>
                                                <a href="<?= BASE_URL ?>/modules/customers/ledger.php?id=<?= $rec['party_id'] ?>" class="btn btn-outline-secondary" title="View Customer Ledger">
                                                    <i class="bi bi-journal-text"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
let currentSelectedBalance = <?= (float)($selectedCustomer['current_balance'] ?? 0) ?>;

function handleCustomerChange(custId) {
    if (!custId) {
        document.getElementById('customerInfoBox').classList.add('d-none');
        currentSelectedBalance = 0;
        return;
    }

    const select = document.getElementById('customerSelect');
    const opt = select.options[select.selectedIndex];
    
    if (opt) {
        currentSelectedBalance = parseFloat(opt.getAttribute('data-balance')) || 0;
        const phone = opt.getAttribute('data-phone') || '';
        const owner = opt.getAttribute('data-owner') || '';
        const area = opt.getAttribute('data-area') || '';
        const shop = opt.text.split('(')[0].trim();

        document.getElementById('infoShopName').textContent = shop;
        document.getElementById('infoOwner').textContent = owner;
        document.getElementById('infoArea').textContent = area;
        document.getElementById('infoPhone').textContent = phone;
        document.getElementById('infoBalanceDisplay').textContent = 'Rs. ' + currentSelectedBalance.toLocaleString('en-PK', {minimumFractionDigits: 2, maximumFractionDigits: 2});

        document.getElementById('customerInfoBox').classList.remove('d-none');

        // Pre-fill full balance if positive
        if (currentSelectedBalance > 0) {
            document.getElementById('inputAmount').value = currentSelectedBalance.toFixed(2);
        }
    }
}

function fillAmount(type) {
    const input = document.getElementById('inputAmount');
    if (type === 'full') {
        input.value = Math.max(0, currentSelectedBalance).toFixed(2);
    } else if (type === '50') {
        input.value = (Math.max(0, currentSelectedBalance) * 0.50).toFixed(2);
    } else if (type === '25') {
        input.value = (Math.max(0, currentSelectedBalance) * 0.25).toFixed(2);
    } else if (type === 'clear') {
        input.value = '';
    }
}

function handleMethodChange(method) {
    const alertBox = document.getElementById('handoverAlert');
    const refGroup = document.getElementById('refGroup');
    const refLabel = document.getElementById('refLabel');
    const inputRef = document.getElementById('inputRef');

    if (method === 'cash') {
        alertBox.className = 'alert alert-warning py-2 px-3 small border-0 rounded-2 mb-3';
        alertBox.innerHTML = '<i class="bi bi-info-circle me-1"></i><strong>Physical Cash:</strong> This cash is held in the collector\'s custody and will appear in <strong>Cash Handover & Reconciliation</strong>.';
        refLabel.textContent = 'Manual Receipt / Slip Book # (Optional)';
        inputRef.placeholder = 'e.g. Manual Slip # 1042';
        inputRef.required = false;
    } else if (method === 'cheque') {
        alertBox.className = 'alert alert-info py-2 px-3 small border-0 rounded-2 mb-3';
        alertBox.innerHTML = '<i class="bi bi-card-heading me-1"></i><strong>Cheque Collection:</strong> Physical cheque custody requires verification and deposit.';
        refLabel.textContent = 'Cheque Number & Bank Name *';
        inputRef.placeholder = 'e.g. HBL Cheque # 8294109';
        inputRef.required = true;
    } else {
        alertBox.className = 'alert alert-success py-2 px-3 small border-0 rounded-2 mb-3';
        alertBox.innerHTML = '<i class="bi bi-check-circle me-1"></i><strong>Digital / Online Payment:</strong> Reconciles directly to company digital accounts. No physical cash handover required.';
        refLabel.textContent = 'Transaction ID / Reference Number *';
        inputRef.placeholder = 'e.g. JazzCash TID / Bank Ref #';
        inputRef.required = true;
    }
}

function shareVoucherWhatsApp(shop, voucherNo, amount, balance, phone) {
    let text = `*Falcon FMCG Distribution - Payment Receipt*\n`;
    text += `Receipt Voucher: *${voucherNo}*\n`;
    text += `Customer / Shop: *${shop}*\n`;
    text += `Payment Received: *Rs. ${amount}*\n`;
    text += `Remaining Shop Balance: *Rs. ${balance}*\n`;
    text += `Status: *Received & Verified*\n`;
    text += `------------------------------------\n`;
    text += `Thank you for your timely payment!\n`;
    text += `Falcon FMCG Central Distribution Gujranwala`;

    const cleanPhone = (phone || '').replace(/[^0-9]/g, '');
    let url = 'https://api.whatsapp.com/send?text=' + encodeURIComponent(text);
    if (cleanPhone) {
        let p = cleanPhone.startsWith('0') ? '92' + cleanPhone.substring(1) : cleanPhone;
        url = 'https://api.whatsapp.com/send?phone=' + p + '&text=' + encodeURIComponent(text);
    }
    window.open(url, '_blank');
}

// Auto init if preselected
document.addEventListener('DOMContentLoaded', function() {
    const custId = document.getElementById('customerSelect').value;
    if (custId) {
        handleCustomerChange(custId);
    }
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
