<?php
/**
 * Delivery Driver Cash Handover & Daily Reconciliation
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('payments', 'view');

$pdo = getDatabaseConnection();
$currentUser = getCurrentUser();
$roleSlug = $currentUser['role_slug'];
$currentEmp = getCurrentEmployee();
$isSuperAdmin = ($roleSlug === ROLE_SUPER_ADMIN);
$isFieldRole = in_array($roleSlug, [ROLE_DELIVERY_MAN, ROLE_ORDER_BOOKER, ROLE_SALESMAN]);

// Process POST Handover Submission (By Delivery Driver or Admin/Cashier)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_handover'])) {
    // Security Rule: Only Super Administrator can submit handover under another employee's name
    if ($isSuperAdmin) {
        $deliveryManId = !empty($_POST['delivery_man_id']) ? (int)$_POST['delivery_man_id'] : (!empty($currentEmp['id']) ? (int)$currentEmp['id'] : 0);
    } else {
        if (empty($currentEmp['id'])) {
            setFlashMessage('error', 'Access denied. You do not have an active employee profile linked to your user account.');
            redirect(BASE_URL . '/modules/payments/handover.php');
        }
        $deliveryManId = (int)$currentEmp['id'];
    }
    $handoverDate = $_POST['handover_date'] ?? date('Y-m-d');
    $cashAmount = (float)($_POST['cash_amount'] ?? 0.00);
    $digitalAmount = (float)($_POST['digital_amount'] ?? 0.00);
    $chequeAmount = (float)($_POST['cheque_amount'] ?? 0.00);
    $cashDeposited = (float)($_POST['cash_deposited'] ?? 0.00);
    $totalCollected = $cashAmount + $digitalAmount + $chequeAmount;
    $difference = $cashDeposited - $cashAmount;
    $notes = sanitize($_POST['notes'] ?? '');

    if ($deliveryManId <= 0) {
        setFlashMessage('error', 'Please select a valid delivery driver or field agent.');
        redirect(BASE_URL . '/modules/payments/handover.php');
    }

    try {
        $handoverNo = generateDocNumber($pdo, 'cash_handovers', 'handover_number', 'CHO-');
        $stmt = $pdo->prepare("
            INSERT INTO `cash_handovers` (
                `handover_number`, `delivery_man_id`, `handover_date`, `cash_amount`, 
                `digital_amount`, `cheque_amount`, `total_collected`, `cash_deposited`, 
                `difference_amount`, `status`, `notes`, `created_at`
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, NOW())
        ");
        $stmt->execute([
            $handoverNo, $deliveryManId, $handoverDate, $cashAmount,
            $digitalAmount, $chequeAmount, $totalCollected, $cashDeposited,
            $difference, $notes
        ]);

        // Update collected payments status for this driver up to handoverDate
        $pUpdate = $pdo->prepare("
            UPDATE `payments` p
            LEFT JOIN `delivery_assignments` da ON p.reference_type = 'delivery' AND p.reference_id = da.id
            SET p.handover_status = 'handed_over', p.collected_by = ?
            WHERE (p.collected_by = ? OR da.delivery_man_id = ?) 
              AND p.handover_status = 'pending_handover'
              AND p.payment_date <= ?
        ");
        $pUpdate->execute([$deliveryManId, $deliveryManId, $deliveryManId, $handoverDate]);

        logAudit($pdo, 'payments', (int)$pdo->lastInsertId(), 'handover_submit', null, ['handover_no' => $handoverNo, 'deposited' => $cashDeposited]);
        setFlashMessage('success', "Cash handover {$handoverNo} submitted successfully. Awaiting accountant verification.");
        redirect(BASE_URL . '/modules/payments/handover.php');

    } catch (Exception $e) {
        setFlashMessage('error', 'Handover submission error: ' . $e->getMessage());
        redirect(BASE_URL . '/modules/payments/handover.php');
    }
}

// Process POST Verification Action (STRICTLY RESTRICTED TO SUPER ADMINISTRATOR ONLY)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_handover'])) {
    if (!$isSuperAdmin) {
        setFlashMessage('error', 'Access denied. Handover verification is strictly restricted to Super Administrator.');
        redirect(BASE_URL . '/modules/payments/handover.php');
    }

    $handoverId = (int)($_POST['handover_id'] ?? 0);
    $verAction = sanitize($_POST['ver_action'] ?? 'verified');

    try {
        $pdo->prepare("
            UPDATE `cash_handovers` 
            SET `status` = ?, `verified_by` = ?, `verified_at` = NOW() 
            WHERE id = ?
        ")->execute([$verAction, $currentUser['id'], $handoverId]);

        // Mark payments reconciled if verified
        if ($verAction === 'verified') {
            $hStmt = $pdo->prepare("SELECT delivery_man_id, handover_date FROM `cash_handovers` WHERE id = ?");
            $hStmt->execute([$handoverId]);
            $hRec = $hStmt->fetch();
            if ($hRec) {
                $pdo->prepare("
                    UPDATE `payments` p
                    LEFT JOIN `delivery_assignments` da ON p.reference_type = 'delivery' AND p.reference_id = da.id
                    SET p.handover_status = 'reconciled'
                    WHERE (p.collected_by = ? OR da.delivery_man_id = ?)
                      AND p.handover_status IN ('handed_over', 'pending_handover')
                      AND p.payment_date <= ?
                ")->execute([$hRec['delivery_man_id'], $hRec['delivery_man_id'], $hRec['handover_date']]);
            }
        }

        logAudit($pdo, 'payments', $handoverId, 'handover_verify', null, ['ver_action' => $verAction]);
        setFlashMessage('success', "Handover marked as {$verAction}.");
        redirect(BASE_URL . '/modules/payments/handover.php');

    } catch (Exception $e) {
        setFlashMessage('error', 'Verification error: ' . $e->getMessage());
        redirect(BASE_URL . '/modules/payments/handover.php');
    }
}

// Fetch all active delivery personnel, recovery officers & sales agents who collect cash
$drivers = $pdo->query("
    SELECT DISTINCT e.id, e.employee_code, e.full_name, e.role_type, e.phone
    FROM employees e
    WHERE e.status = 'active'
      AND (
          e.role_type IN ('delivery_man', 'salesman', 'order_booker', 'recovery_officer')
          OR e.id IN (SELECT DISTINCT collected_by FROM payments WHERE handover_status = 'pending_handover' AND collected_by IS NOT NULL)
      )
    ORDER BY e.full_name ASC
")->fetchAll();

// Default driver/collector selection
$defaultDriverId = $isSuperAdmin 
    ? (!empty($_GET['driver_id']) ? (int)$_GET['driver_id'] : (!empty($currentEmp['id']) ? (int)$currentEmp['id'] : ($drivers[0]['id'] ?? 0)))
    : (int)($currentEmp['id'] ?? 0);

// Precompute live custody data for all drivers
$driversCustody = [];
$totalSystemPendingCash = 0.0;
$totalSystemLifetimeColl = 0.0;
$totalSystemLifetimeDep = 0.0;

foreach ($drivers as $d) {
    $empId = (int)$d['id'];

    // 1. Pending collections from posted payments not yet handed over
    $stmt = $pdo->prepare("
        SELECT 
            COALESCE(SUM(CASE WHEN p.payment_method_code = 'cash' THEN p.amount ELSE 0 END), 0) as pending_cash,
            COALESCE(SUM(CASE WHEN p.payment_method_code IN ('jazzcash', 'easypaisa', 'bank_transfer') THEN p.amount ELSE 0 END), 0) as pending_digital,
            COALESCE(SUM(CASE WHEN p.payment_method_code = 'cheque' THEN p.amount ELSE 0 END), 0) as pending_cheque,
            COALESCE(SUM(p.amount), 0) as pending_total
        FROM payments p
        LEFT JOIN delivery_assignments da ON p.reference_type = 'delivery' AND p.reference_id = da.id
        WHERE p.status = 'posted'
          AND p.handover_status = 'pending_handover'
          AND (p.collected_by = ? OR da.delivery_man_id = ?)
    ");
    $stmt->execute([$empId, $empId]);
    $pending = $stmt->fetch();

    // 1b. Fetch itemized list of pending receipts (delivery collections + market recoveries)
    $itemsStmt = $pdo->prepare("
        SELECT p.id, p.payment_number, p.payment_date, p.payment_method_code, p.amount, p.reference_type, p.reference_no,
               CASE 
                   WHEN p.party_type = 'customer' THEN (SELECT shop_name FROM `customers` WHERE id = p.party_id)
                   ELSE 'General'
               END as shop_name
        FROM payments p
        LEFT JOIN delivery_assignments da ON p.reference_type = 'delivery' AND p.reference_id = da.id
        WHERE p.status = 'posted'
          AND p.handover_status = 'pending_handover'
          AND (p.collected_by = ? OR da.delivery_man_id = ?)
        ORDER BY p.id ASC
    ");
    $itemsStmt->execute([$empId, $empId]);
    $pendingItems = $itemsStmt->fetchAll();

    // 2. Lifetime cash collections vs lifetime deposits
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(p.amount), 0) as lifetime_cash_collected
        FROM payments p
        LEFT JOIN delivery_assignments da ON p.reference_type = 'delivery' AND p.reference_id = da.id
        WHERE p.status = 'posted'
          AND p.payment_method_code = 'cash'
          AND (p.collected_by = ? OR da.delivery_man_id = ?)
    ");
    $stmt->execute([$empId, $empId]);
    $lifetimeColl = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(cash_deposited), 0) as lifetime_cash_deposited
        FROM cash_handovers
        WHERE delivery_man_id = ? AND status IN ('pending', 'verified')
    ");
    $stmt->execute([$empId]);
    $lifetimeDep = (float)$stmt->fetchColumn();

    $netCustodyBalance = $lifetimeColl - $lifetimeDep;
    $activeOwedCash = max(0.00, $netCustodyBalance);

    $totalSystemPendingCash += (float)$pending['pending_cash'];
    $totalSystemLifetimeColl += $lifetimeColl;
    $totalSystemLifetimeDep += $lifetimeDep;

    $driversCustody[$empId] = [
        'id' => $empId,
        'name' => $d['full_name'],
        'role' => ucwords(str_replace('_', ' ', $d['role_type'])),
        'code' => $d['employee_code'],
        'phone' => $d['phone'] ?? '',
        'pending_cash' => (float)$pending['pending_cash'],
        'pending_digital' => (float)$pending['pending_digital'],
        'pending_cheque' => (float)$pending['pending_cheque'],
        'pending_total' => (float)$pending['pending_total'],
        'lifetime_collected' => $lifetimeColl,
        'lifetime_deposited' => $lifetimeDep,
        'net_custody' => $netCustodyBalance,
        'active_owed' => $activeOwedCash,
        'items' => $pendingItems
    ];
}

// Fetch handovers history (Superadmin sees all company records; field workers see only their own)
$historyWhere = "1=1";
$historyParams = [];
if (!$isSuperAdmin) {
    $empId = (int)($currentEmp['id'] ?? 0);
    $historyWhere = "ch.delivery_man_id = ?";
    $historyParams = [$empId];
}

$hStmt = $pdo->prepare("
    SELECT ch.*, e.full_name as driver_name, e.employee_code, u.full_name as verifier_name
    FROM `cash_handovers` ch
    JOIN `employees` e ON ch.delivery_man_id = e.id
    LEFT JOIN `users` u ON ch.verified_by = u.id
    WHERE {$historyWhere}
    ORDER BY ch.id DESC LIMIT 50
");
$hStmt->execute($historyParams);
$handoverHistory = $hStmt->fetchAll();

// FIFO Deficit Settlement Analyzer across handovers
$driverHandoversAsc = [];
foreach ($handoverHistory as $h) {
    $driverHandoversAsc[$h['delivery_man_id']][] = $h;
}

$settlementStatus = []; // handover_id => ['is_settled' => bool, 'settled_by' => string]
foreach ($driverHandoversAsc as $dId => $hList) {
    usort($hList, fn($a, $b) => $a['id'] <=> $b['id']);
    $deficits = []; // h_id => remaining_shortfall

    foreach ($hList as $h) {
        $diff = (float)$h['difference_amount'];
        $hId = (int)$h['id'];

        if ($diff < 0) {
            $deficits[$hId] = [
                'original' => abs($diff),
                'remaining' => abs($diff),
                'settled_by' => null
            ];
        } elseif ($diff > 0) {
            $surplus = $diff;
            foreach ($deficits as $subId => &$dData) {
                if ($dData['remaining'] > 0) {
                    $apply = min($surplus, $dData['remaining']);
                    $dData['remaining'] -= $apply;
                    $surplus -= $apply;
                    if ($dData['remaining'] <= 0.001) {
                        $dData['settled_by'] = $h['handover_number'];
                    }
                    if ($surplus <= 0) break;
                }
            }
            unset($dData);
        }
    }

    $dNetCustody = $driversCustody[$dId]['net_custody'] ?? 0;
    foreach ($deficits as $subId => $dData) {
        $isSettled = ($dData['remaining'] <= 0.001) || ($dNetCustody <= 0.001);
        $settlementStatus[$subId] = [
            'is_settled' => $isSettled,
            'original' => $dData['original'],
            'remaining' => $isSettled ? 0.00 : $dData['remaining'],
            'settled_by' => $dData['settled_by']
        ];
    }
}

$pageTitle = 'Cash Handover & Reconciliation';
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <div>
        <h4 class="fw-bold mb-0 text-dark"><i class="bi bi-wallet2 text-warning me-2"></i>Field Delivery & Recovery Cash Handover (کیش ہینڈ اوور)</h4>
        <small class="text-muted">End-of-Day Custody Verification for Delivery Specialists & Recovery Officers</small>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/modules/payments/recovery.php" class="btn btn-sm btn-success fw-bold">
            <i class="bi bi-cash-coin me-1"></i> + Record Customer Recovery
        </a>
        <a href="<?= BASE_URL ?>/modules/reports/daily_closing.php" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-clock-history me-1"></i> Daily Closing Report
        </a>
    </div>
</div>

<?php if ($isSuperAdmin): ?>
<!-- High-Level Custody Metrics Cards (Super Administrator View) -->
<div class="row g-2 mb-3">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm p-3 bg-white rounded-3 h-100">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-muted small fw-semibold">Pending Cash in Market</div>
                    <div class="h5 fw-bold text-danger mb-0" id="metricTotalPending"><?= formatMoney($totalSystemPendingCash) ?></div>
                </div>
                <div class="p-2 bg-danger-subtle text-danger rounded-circle">
                    <i class="bi bi-cash-stack fs-5"></i>
                </div>
            </div>
            <small class="text-muted mt-1 d-block">Un-reconciled physical cash with drivers</small>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm p-3 bg-white rounded-3 h-100">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-muted small fw-semibold">Total Lifetime Collections</div>
                    <div class="h5 fw-bold text-dark mb-0"><?= formatMoney($totalSystemLifetimeColl) ?></div>
                </div>
                <div class="p-2 bg-primary-subtle text-primary rounded-circle">
                    <i class="bi bi-graph-up-arrow fs-5"></i>
                </div>
            </div>
            <small class="text-muted mt-1 d-block">Total cash receipts logged</small>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm p-3 bg-white rounded-3 h-100">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-muted small fw-semibold">Total Cash Deposited</div>
                    <div class="h5 fw-bold text-success mb-0"><?= formatMoney($totalSystemLifetimeDep) ?></div>
                </div>
                <div class="p-2 bg-success-subtle text-success rounded-circle">
                    <i class="bi bi-safe fs-5"></i>
                </div>
            </div>
            <small class="text-muted mt-1 d-block">Deposited to accountant / company</small>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm p-3 bg-white rounded-3 h-100">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-muted small fw-semibold">Active Drivers Tracked</div>
                    <div class="h5 fw-bold text-info mb-0"><?= count($drivers) ?> Drivers</div>
                </div>
                <div class="p-2 bg-info-subtle text-info rounded-circle">
                    <i class="bi bi-people fs-5"></i>
                </div>
            </div>
            <small class="text-muted mt-1 d-block">Field agents & delivery specialists</small>
        </div>
    </div>
</div>
<?php else: ?>
<?php 
$myEmpId = (int)($currentEmp['id'] ?? 0);
$myCustody = $driversCustody[$myEmpId] ?? [
    'pending_cash' => 0.0, 'pending_total' => 0.0, 'lifetime_collected' => 0.0, 'lifetime_deposited' => 0.0, 'net_custody' => 0.0
];
?>
<!-- Driver's Personal Cash Custody Cards (Field Worker View) -->
<div class="row g-2 mb-3">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm p-3 bg-white rounded-3 h-100">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-muted small fw-semibold">Cash in Your Hand</div>
                    <div class="h5 fw-bold text-danger mb-0"><?= formatMoney($myCustody['pending_cash']) ?></div>
                </div>
                <div class="p-2 bg-danger-subtle text-danger rounded-circle">
                    <i class="bi bi-wallet2 fs-5"></i>
                </div>
            </div>
            <small class="text-muted mt-1 d-block">Physical cash awaiting handover</small>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm p-3 bg-white rounded-3 h-100">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-muted small fw-semibold">Your Total Collections</div>
                    <div class="h5 fw-bold text-dark mb-0"><?= formatMoney($myCustody['lifetime_collected']) ?></div>
                </div>
                <div class="p-2 bg-primary-subtle text-primary rounded-circle">
                    <i class="bi bi-receipt fs-5"></i>
                </div>
            </div>
            <small class="text-muted mt-1 d-block">All-time collected by you</small>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm p-3 bg-white rounded-3 h-100">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-muted small fw-semibold">Your Total Deposited</div>
                    <div class="h5 fw-bold text-success mb-0"><?= formatMoney($myCustody['lifetime_deposited']) ?></div>
                </div>
                <div class="p-2 bg-success-subtle text-success rounded-circle">
                    <i class="bi bi-check-circle fs-5"></i>
                </div>
            </div>
            <small class="text-muted mt-1 d-block">Handed over to administration</small>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm p-3 bg-white rounded-3 h-100">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-muted small fw-semibold">Your Custody Status</div>
                    <div class="h6 fw-bold <?= $myCustody['net_custody'] <= 0.01 ? 'text-success' : 'text-warning' ?> mb-0">
                        <?= $myCustody['net_custody'] <= 0.01 ? 'All Settled (Rs. 0.00)' : 'Active Due: ' . formatMoney($myCustody['net_custody']) ?>
                    </div>
                </div>
                <div class="p-2 bg-info-subtle text-info rounded-circle">
                    <i class="bi bi-shield-check fs-5"></i>
                </div>
            </div>
            <small class="text-muted mt-1 d-block">Verification status with office</small>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="row g-3">
    <!-- Handover Submission Form -->
    <div class="col-12 col-lg-5">
        <div class="card border-0 shadow-sm rounded-3 mb-3">
            <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0 text-dark">
                    <i class="bi bi-cash-coin me-2 text-success"></i>Submit End-of-Day Handover
                </h6>
                <span id="custodyBadge" class="badge bg-success-subtle text-success border border-success-subtle">Reconciled</span>
            </div>
            <div class="card-body p-3">
                <form method="POST" action="<?= BASE_URL ?>/modules/payments/handover.php" id="handoverForm">
                    <?= getCsrfField() ?>
                    <input type="hidden" name="submit_handover" value="1">

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Delivery Driver / Recovery Officer <span class="text-danger">*</span></label>
                        <?php if ($isSuperAdmin): ?>
                            <select class="form-select fw-semibold" name="delivery_man_id" id="deliveryManSelect" required>
                                <?php foreach ($drivers as $d): ?>
                                    <option value="<?= $d['id'] ?>" <?= $d['id'] == $defaultDriverId ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($d['full_name']) ?> (<?= htmlspecialchars($d['employee_code']) ?> - <?= ucwords(str_replace('_', ' ', $d['role_type'])) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <input type="hidden" name="delivery_man_id" id="deliveryManSelect" value="<?= (int)($currentEmp['id'] ?? 0) ?>">
                            <select class="form-select fw-semibold bg-light" disabled>
                                <option value="<?= (int)($currentEmp['id'] ?? 0) ?>" selected>
                                    <?= htmlspecialchars($currentEmp['full_name'] ?? 'Myself') ?> (<?= htmlspecialchars($currentEmp['employee_code'] ?? '') ?> - <?= ucwords(str_replace('_', ' ', $currentEmp['role_type'] ?? 'Staff')) ?>)
                                </option>
                            </select>
                            <small class="text-muted" style="font-size:0.7rem;"><i class="bi bi-shield-lock text-secondary me-1"></i>Locked to your collection custody (Super Admin can reassign)</small>
                        <?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Handover Date</label>
                        <input type="date" class="form-control" name="handover_date" value="<?= date('Y-m-d') ?>" required>
                    </div>

                    <!-- Dynamic Custody Summary Box -->
                    <div class="p-3 bg-light rounded-3 mb-3 border">
                        <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom">
                            <span class="text-muted small fw-semibold">Driver / Agent Cash Custody:</span>
                            <span id="boxCustodyStatus" class="fw-bold text-success">Balanced (Rs. 0.00)</span>
                        </div>

                        <div class="d-flex justify-content-between mb-1">
                            <span class="text-muted small">Un-handed Cash Collections:</span>
                            <span id="boxPendingCash" class="fw-bold text-dark">Rs. 0.00</span>
                        </div>

                        <div id="boxDeficitRow" class="d-flex justify-content-between mb-1 d-none">
                            <span class="text-danger small fw-semibold"><i class="bi bi-exclamation-circle me-1"></i>Previous Unsettled Deficit:</span>
                            <span id="boxPendingDeficit" class="fw-bold text-danger">Rs. 0.00</span>
                        </div>

                        <div class="d-flex justify-content-between mb-1">
                            <span class="text-muted small">Pending Digital (JazzCash / Online):</span>
                            <span id="boxPendingDigital" class="fw-bold text-info">Rs. 0.00</span>
                        </div>

                        <div class="d-flex justify-content-between pt-2 border-top">
                            <span class="fw-bold text-dark small">Total Due for Deposit (Physical Cash):</span>
                            <span id="boxTotalDue" class="fw-bold text-primary fs-6">Rs. 0.00</span>
                        </div>
                    </div>

                    <!-- Pending Collections Breakdown (Itemized Receipts) -->
                    <div id="pendingItemsContainer" class="mb-3 d-none">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="text-muted small fw-semibold"><i class="bi bi-list-check me-1 text-primary"></i>Awaiting Handover (<span id="pendingItemsCount">0</span> Receipts):</span>
                        </div>
                        <div id="pendingItemsList" class="list-group list-group-flush border rounded-2 small" style="max-height: 160px; overflow-y: auto;">
                            <!-- Filled dynamically by JS -->
                        </div>
                    </div>

                    <!-- Hidden calculated inputs for backend synchronization -->
                    <input type="hidden" name="cash_amount" id="inputCashAmount" value="0.00">
                    <input type="hidden" name="digital_amount" id="inputDigitalAmount" value="0.00">
                    <input type="hidden" name="cheque_amount" id="inputChequeAmount" value="0.00">

                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label class="form-label small fw-semibold mb-0">Physical Cash Handed Over (Rs.) <span class="text-danger">*</span></label>
                            <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none" id="btnAutoFillExact">
                                <i class="bi bi-magic me-1"></i>Fill Exact Due
                            </button>
                        </div>
                        <div class="input-group">
                            <span class="input-group-text bg-white fw-bold">Rs.</span>
                            <input type="number" class="form-control form-control-lg fw-bold text-primary" name="cash_deposited" id="inputCashDeposited" value="0" min="0" step="1" required>
                        </div>
                        <div id="diffHelpText" class="form-text small mt-1"></div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Remarks / Discrepancy Note</label>
                        <textarea class="form-control form-control-sm" name="notes" rows="2" placeholder="e.g. Complete cash collected deposited to accountant. No discrepancies."></textarea>
                    </div>

                    <button type="submit" class="btn btn-warning w-100 btn-touch shadow-sm text-dark fw-bold py-2">
                        <i class="bi bi-box-arrow-down me-1"></i> Submit Cash for Verification
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Handover Records & Reconciliation Table -->
    <div class="col-12 col-lg-7">
        <div class="card border-0 shadow-sm rounded-3 mb-3">
            <div class="card-header bg-white py-3 border-0 d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <?php if ($isSuperAdmin): ?>
                        <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-shield-check me-2 text-primary"></i>Handover Reconciliation Logs & Verification</h6>
                        <small class="text-muted">Automatic FIFO deficit settlement & custody ledger audit</small>
                    <?php else: ?>
                        <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-clock-history me-2 text-primary"></i>Your Cash Handover History</h6>
                        <small class="text-muted">Status of your end-of-day deposits awaiting administrator verification</small>
                    <?php endif; ?>
                </div>
                <?php if ($isSuperAdmin): ?>
                    <div class="d-flex gap-2">
                        <select id="filterDriverLog" class="form-select form-select-sm" style="max-width: 180px;">
                            <option value="all">All Drivers</option>
                            <?php foreach ($drivers as $d): ?>
                                <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php else: ?>
                    <span class="badge bg-light text-dark border"><?= count($handoverHistory) ?> Submissions</span>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <?php if (empty($handoverHistory)): ?>
                    <div class="p-4 text-center text-muted">No handovers recorded yet.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-responsive-card align-middle mb-0" id="handoverTable">
                            <thead class="table-light small">
                                <tr>
                                    <th>Handover #</th>
                                    <th>Driver / Date</th>
                                    <th class="text-end">Collected</th>
                                    <th class="text-end">Deposited</th>
                                    <th class="text-center">Shift Diff / Settlement</th>
                                    <th>Status</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($handoverHistory as $h): 
                                    $hId = (int)$h['id'];
                                    $driverId = (int)$h['delivery_man_id'];
                                    $diff = (float)$h['difference_amount'];
                                    $settleInfo = $settlementStatus[$hId] ?? ['is_settled' => false, 'settled_by' => null];
                                ?>
                                    <tr data-driver-id="<?= $driverId ?>">
                                        <td data-label="Handover #" class="fw-bold text-dark">
                                            <?= htmlspecialchars($h['handover_number']) ?>
                                            <?php if (!empty($h['notes'])): ?>
                                                <div class="text-muted small text-truncate" style="max-width: 140px;" title="<?= htmlspecialchars($h['notes']) ?>">
                                                    <?= htmlspecialchars($h['notes']) ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Driver">
                                            <div class="fw-semibold text-dark"><?= htmlspecialchars($h['driver_name']) ?></div>
                                            <small class="text-muted"><?= formatDate($h['handover_date']) ?></small>
                                        </td>
                                        <td data-label="Collected" class="text-end fw-semibold"><?= formatMoney($h['total_collected']) ?></td>
                                        <td data-label="Deposited" class="text-end fw-bold text-primary"><?= formatMoney($h['cash_deposited']) ?></td>
                                        <td data-label="Shift Diff" class="text-center">
                                            <?php if ($diff == 0): ?>
                                                <div class="text-success fw-semibold small">Rs. 0.00</div>
                                                <span class="badge bg-light text-muted border border-secondary-subtle small py-1 px-2">Exact Match</span>
                                            <?php elseif ($diff > 0): ?>
                                                <div class="text-primary fw-bold small">+<?= formatMoney($diff) ?></div>
                                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle small py-1 px-2">Excess / Offset</span>
                                            <?php else: ?>
                                                <?php if ($settleInfo['is_settled']): ?>
                                                    <div class="text-muted text-decoration-line-through small fw-semibold"><?= formatMoney($diff) ?></div>
                                                    <span class="badge bg-success-subtle text-success border border-success-subtle small py-1 px-2" title="Deficit recovered by subsequent deposits">
                                                        <i class="bi bi-check2-circle me-1"></i>Settled
                                                    </span>
                                                <?php else: ?>
                                                    <div class="text-danger fw-bold small"><?= formatMoney($diff) ?></div>
                                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle small py-1 px-2">
                                                        <i class="bi bi-exclamation-triangle-fill me-1"></i>Active Shortfall
                                                    </span>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Status">
                                            <?php
                                            $b = match ($h['status']) {
                                                'verified' => 'bg-success',
                                                'rejected' => 'bg-danger',
                                                default => 'bg-warning text-dark'
                                            };
                                            ?>
                                            <span class="badge badge-status <?= $b ?>"><?= htmlspecialchars($h['status']) ?></span>
                                        </td>
                                        <td data-label="Action" class="text-end">
                                            <?php if ($isSuperAdmin): ?>
                                                <?php if ($h['status'] === 'pending'): ?>
                                                    <button type="button" class="btn btn-sm btn-outline-success" data-confirm-trigger data-confirm-target="panel-verify-<?= $h['id'] ?>">
                                                        <i class="bi bi-shield-check me-1"></i> Verify
                                                    </button>

                                                    <!-- Strict No-Modal Inline Verify Panel -->
                                                    <div id="panel-verify-<?= $h['id'] ?>" class="inline-confirm-panel d-none text-start mt-2 p-2 bg-light rounded border border-success-subtle">
                                                        <div class="fw-bold small text-success mb-2">Verify Cash Deposit of <?= formatMoney($h['cash_deposited']) ?>?</div>
                                                        <form method="POST" action="<?= BASE_URL ?>/modules/payments/handover.php">
                                                            <?= getCsrfField() ?>
                                                            <input type="hidden" name="verify_handover" value="1">
                                                            <input type="hidden" name="handover_id" value="<?= $h['id'] ?>">
                                                            <div class="d-flex gap-1">
                                                                <button type="submit" name="ver_action" value="verified" class="btn btn-sm btn-success">Confirm Received</button>
                                                                <button type="button" class="btn btn-sm btn-secondary" data-confirm-cancel data-confirm-target="panel-verify-<?= $h['id'] ?>">Cancel</button>
                                                            </div>
                                                        </form>
                                                    </div>
                                                <?php else: ?>
                                                    <small class="text-muted"><?= $h['verifier_name'] ? 'by ' . htmlspecialchars($h['verifier_name']) : 'Verified' ?></small>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <?php if ($h['status'] === 'pending'): ?>
                                                    <span class="badge bg-warning text-dark border border-warning-subtle py-1 px-2" style="font-size:0.68rem;">
                                                        <i class="bi bi-hourglass-split me-1"></i>Awaiting Admin Check
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-success-subtle text-success border border-success-subtle py-1 px-2" style="font-size:0.68rem;">
                                                        <i class="bi bi-shield-check me-1"></i>Verified by <?= htmlspecialchars($h['verifier_name'] ?? 'Admin') ?>
                                                    </span>
                                                <?php endif; ?>
                                            <?php endif; ?>
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
// Driver Custody Dataset precomputed from PHP
const driversCustodyData = <?= json_encode($driversCustody) ?>;

function formatRupees(num) {
    const val = parseFloat(num) || 0;
    return 'Rs. ' + val.toLocaleString('en-PK', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function updateCustodyView() {
    const selectElem = document.getElementById('deliveryManSelect');
    if (!selectElem) return;

    const driverId = selectElem.value;
    const driver = driversCustodyData[driverId] || {
        pending_cash: 0,
        pending_digital: 0,
        pending_cheque: 0,
        pending_total: 0,
        lifetime_collected: 0,
        lifetime_deposited: 0,
        net_custody: 0,
        active_owed: 0
    };

    // Calculate figures
    const pendingCash = parseFloat(driver.pending_cash) || 0;
    const pendingDigital = parseFloat(driver.pending_digital) || 0;
    const pendingCheque = parseFloat(driver.pending_cheque) || 0;
    const netCustody = parseFloat(driver.net_custody) || 0;
    
    // An active shortfall from past days is present if net custody > pendingCash
    const pastDeficit = Math.max(0, netCustody - pendingCash);
    
    // Total physical cash due for deposit
    const totalDue = Math.max(0, pendingCash + pastDeficit);

    // Update Form Badges & Display Boxes
    const custodyBadge = document.getElementById('custodyBadge');
    const boxCustodyStatus = document.getElementById('boxCustodyStatus');
    const boxPendingCash = document.getElementById('boxPendingCash');
    const boxDeficitRow = document.getElementById('boxDeficitRow');
    const boxPendingDeficit = document.getElementById('boxPendingDeficit');
    const boxPendingDigital = document.getElementById('boxPendingDigital');
    const boxTotalDue = document.getElementById('boxTotalDue');

    boxPendingCash.textContent = formatRupees(pendingCash);
    boxPendingDigital.textContent = formatRupees(pendingDigital);
    boxTotalDue.textContent = formatRupees(totalDue);

    if (pastDeficit > 0) {
        boxDeficitRow.classList.remove('d-none');
        boxPendingDeficit.textContent = formatRupees(pastDeficit);
    } else {
        boxDeficitRow.classList.add('d-none');
    }

    if (totalDue <= 0.001) {
        custodyBadge.className = 'badge bg-success-subtle text-success border border-success-subtle';
        custodyBadge.textContent = 'Reconciled';
        boxCustodyStatus.className = 'fw-bold text-success';
        boxCustodyStatus.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i>All Settled (Rs. 0.00)';
    } else {
        custodyBadge.className = 'badge bg-danger-subtle text-danger border border-danger-subtle';
        custodyBadge.textContent = 'Pending Recovery';
        boxCustodyStatus.className = 'fw-bold text-danger';
        boxCustodyStatus.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-1"></i>Due: ' + formatRupees(totalDue);
    }

    // Set Hidden Inputs for Submission
    document.getElementById('inputCashAmount').value = totalDue;
    document.getElementById('inputDigitalAmount').value = pendingDigital;
    document.getElementById('inputChequeAmount').value = pendingCheque;

    // Render itemized pending collections breakdown
    const itemsContainer = document.getElementById('pendingItemsContainer');
    const itemsList = document.getElementById('pendingItemsList');
    const itemsCount = document.getElementById('pendingItemsCount');

    if (itemsContainer && itemsList) {
        const items = driver.items || [];
        if (items.length > 0) {
            itemsContainer.classList.remove('d-none');
            itemsCount.textContent = items.length;
            let html = '';
            items.forEach(function(it) {
                const isRecovery = (it.reference_type === 'recovery');
                const badgeClass = isRecovery ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-primary-subtle text-primary border border-primary-subtle';
                const typeLabel = isRecovery ? 'Recovery' : 'Delivery';
                const methodCode = (it.payment_method_code || 'cash').toUpperCase();
                html += `
                    <div class="list-group-item d-flex justify-content-between align-items-center py-2 px-2">
                        <div>
                            <span class="badge ${badgeClass} me-1" style="font-size: 0.65rem;">${typeLabel}</span>
                            <span class="fw-bold text-dark">${it.shop_name || 'Shop'}</span>
                            <div class="text-muted" style="font-size: 0.68rem;">#${it.payment_number} • ${methodCode}</div>
                        </div>
                        <div class="text-end">
                            <span class="fw-bold text-dark">${formatRupees(it.amount)}</span>
                        </div>
                    </div>
                `;
            });
            itemsList.innerHTML = html;
        } else {
            itemsContainer.classList.add('d-none');
            itemsList.innerHTML = '';
        }
    }

    // Set Default Cash Deposited Input Value
    const depInput = document.getElementById('inputCashDeposited');
    depInput.value = Math.round(totalDue);
    updateDiffHelp();
}

function updateDiffHelp() {
    const totalDue = parseFloat(document.getElementById('inputCashAmount').value) || 0;
    const depVal = parseFloat(document.getElementById('inputCashDeposited').value) || 0;
    const diff = depVal - totalDue;
    const helpElem = document.getElementById('diffHelpText');

    if (diff === 0) {
        helpElem.innerHTML = '<span class="text-success"><i class="bi bi-check2-circle me-1"></i>Exact amount: 100% reconciled with no difference.</span>';
    } else if (diff < 0) {
        helpElem.innerHTML = '<span class="text-danger fw-semibold"><i class="bi bi-exclamation-circle me-1"></i>Shortfall of ' + formatRupees(Math.abs(diff)) + ' (will be logged as driver deficit).</span>';
    } else {
        helpElem.innerHTML = '<span class="text-primary fw-semibold"><i class="bi bi-info-circle me-1"></i>Excess deposit of ' + formatRupees(diff) + ' (will automatically offset any previous shortfalls).</span>';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const selectElem = document.getElementById('deliveryManSelect');
    if (selectElem && selectElem.tagName === 'SELECT') {
        selectElem.addEventListener('change', updateCustodyView);
    }

    const depInput = document.getElementById('inputCashDeposited');
    if (depInput) {
        depInput.addEventListener('input', updateDiffHelp);
    }

    const btnAutoFill = document.getElementById('btnAutoFillExact');
    if (btnAutoFill) {
        btnAutoFill.addEventListener('click', function() {
            const totalDue = parseFloat(document.getElementById('inputCashAmount').value) || 0;
            depInput.value = Math.round(totalDue);
            updateDiffHelp();
        });
    }

    // Filter Logs by Driver
    const filterSelect = document.getElementById('filterDriverLog');
    if (filterSelect) {
        filterSelect.addEventListener('change', function() {
            const filterVal = this.value;
            const rows = document.querySelectorAll('#handoverTable tbody tr');
            rows.forEach(function(row) {
                const rowDriverId = row.getAttribute('data-driver-id');
                if (filterVal === 'all' || rowDriverId === filterVal) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });
    }

    // Initial View Update
    updateCustodyView();
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
