<?php
/**
 * End-of-Day Delivery Run Sheet Reconciliation Grid
 * Reconciles driver physical run sheets (Cash Received, Credit/Udhaar, Returns)
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('delivery', 'deliver');

$pdo = getDatabaseConnection();
$currentUser = getCurrentUser();
$company = getCompanyInfo($pdo);

$date = sanitize($_GET['date'] ?? date('Y-m-d'));
$deliveryManId = (int)($_GET['delivery_man_id'] ?? 0);
$areaId = (int)($_GET['area_id'] ?? 0);

// Dropdown sources
$deliveryStaff = $pdo->query("SELECT id, full_name, employee_code FROM employees WHERE role_type IN ('delivery_man', 'delivery_manager', 'salesman') AND status = 'active' ORDER BY full_name ASC")->fetchAll();
$areas = $pdo->query("SELECT id, name, code FROM areas WHERE status = 'active' ORDER BY name ASC")->fetchAll();

// Payment accounts
$cashMethod = $pdo->query("SELECT * FROM payment_methods WHERE code = 'cash' LIMIT 1")->fetch();
$cashAccountId = (int)($cashMethod['account_id'] ?? 1);

// Process POST Bulk Reconciliation Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reconcile_sheet') {
    validateCsrfToken($_POST['csrf_token'] ?? '');

    $orderIds = $_POST['order_ids'] ?? [];
    $cashInputs = $_POST['cash'] ?? [];
    $creditInputs = $_POST['credit'] ?? [];
    $returnInputs = $_POST['returns'] ?? [];
    $driverId = (int)($_POST['driver_id'] ?? $deliveryManId);
    $reconcileDate = sanitize($_POST['reconcile_date'] ?? $date);

    if (empty($orderIds)) {
        setFlashMessage('error', 'No orders selected for reconciliation.');
        redirect(BASE_URL . "/modules/delivery/sheet_reconcile.php?date={$date}&delivery_man_id={$driverId}");
    }

    $processedCount = 0;
    $totalCashProcessed = 0.00;
    $totalCreditProcessed = 0.00;
    $totalReturnProcessed = 0.00;

    try {
        $pdo->beginTransaction();

        foreach ($orderIds as $orderId) {
            $orderId = (int)$orderId;
            $cashAmt = round((float)($cashInputs[$orderId] ?? 0.00), 2);
            $creditAmt = round((float)($creditInputs[$orderId] ?? 0.00), 2);
            $returnAmt = round((float)($returnInputs[$orderId] ?? 0.00), 2);

            // Fetch order and assignment details
            $stmt = $pdo->prepare("
                SELECT so.*, da.id as assignment_id, da.dispatch_number, da.warehouse_id,
                       c.id as customer_id, c.shop_name, c.current_balance
                FROM sales_orders so
                JOIN customers c ON so.customer_id = c.id
                LEFT JOIN delivery_assignments da ON da.sales_order_id = so.id
                WHERE so.id = ?
                FOR UPDATE
            ");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch();

            if (!$order) {
                continue;
            }

            $orderAmount = (float)$order['grand_total'];
            $assignmentId = (int)($order['assignment_id'] ?? 0);
            $customerId = (int)$order['customer_id'];
            $whId = (int)($order['warehouse_id'] ?? 1);

            // 1. Process Cash Received
            if ($cashAmt > 0) {
                $receiptNumber = generateDocNumber($pdo, 'payments', 'payment_number', 'RCP-');
                $pStmt = $pdo->prepare("
                    INSERT INTO payments (
                        payment_number, payment_type, reference_type, reference_id,
                        party_type, party_id, amount, payment_method_id,
                        payment_method_code, payment_account_id, payment_date,
                        collected_by, handover_status, notes, status, created_by, created_at
                    ) VALUES (?, 'customer_receipt', 'delivery', ?, 'customer', ?, ?, ?, 'cash', ?, ?, ?, 'pending_handover', ?, 'posted', ?, NOW())
                ");
                $pStmt->execute([
                    $receiptNumber, $assignmentId, $customerId, $cashAmt,
                    ($cashMethod['id'] ?? 1), $cashAccountId, $reconcileDate,
                    $driverId, "Daily Run Sheet Cash: Order #{$order['order_number']}", $currentUser['id']
                ]);
                $paymentId = (int)$pdo->lastInsertId();

                postCustomerPaymentAccounting($pdo, $paymentId, $receiptNumber, $customerId, $cashAmt, $cashAccountId, $currentUser['id']);
                $totalCashProcessed += $cashAmt;
            }

            // 2. Process Returns (if any return amount reported)
            if ($returnAmt > 0) {
                $srNumber = generateDocNumber($pdo, 'sales_returns', 'return_number', 'SR-');
                $srStmt = $pdo->prepare("
                    INSERT INTO sales_returns (
                        return_number, customer_id, delivery_man_id, delivery_assignment_id,
                        warehouse_id, return_date, total_amount, return_destination,
                        status, handover_status, reason, created_by, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, 'quarantine', 'approved', 'in_vehicle_custody', ?, ?, NOW())
                ");
                $srStmt->execute([
                    $srNumber, $customerId, $driverId, $assignmentId,
                    $whId, $reconcileDate, $returnAmt,
                    "Run Sheet Return on Order #{$order['order_number']}", $currentUser['id']
                ]);
                $srId = (int)$pdo->lastInsertId();

                // Create Credit Note
                $cnNumber = generateDocNumber($pdo, 'credit_notes', 'credit_note_number', 'CN-');
                $pdo->prepare("
                    INSERT INTO credit_notes (credit_note_number, sales_return_id, customer_id, amount, note_date, status, created_at)
                    VALUES (?, ?, ?, ?, ?, 'active', NOW())
                ")->execute([$cnNumber, $srId, $customerId, $returnAmt, $reconcileDate]);

                // Adjust Customer Balance for returned amount
                updateCustomerBalance($pdo, $customerId, -$returnAmt);
                $totalReturnProcessed += $returnAmt;
            }

            // 3. Credit Given:
            // Notice: When credit is extended to the shop, the unpaid portion remains as an outstanding receivable
            // on the customer's ledger, which was already posted when the invoice was generated.
            $totalCreditProcessed += $creditAmt;

            // 4. Determine final delivery status
            $newStatus = 'delivered';
            if ($returnAmt >= $orderAmount) {
                $newStatus = 'returned';
            } elseif ($returnAmt > 0) {
                $newStatus = 'partially_delivered';
            }

            // Update sales order and assignment
            $pdo->prepare("UPDATE sales_orders SET status = ?, updated_at = NOW() WHERE id = ?")->execute([$newStatus, $orderId]);
            if ($assignmentId > 0) {
                $pdo->prepare("UPDATE delivery_assignments SET status = ?, notes = CONCAT(COALESCE(notes, ''), ' [Reconciled Run Sheet]') WHERE id = ?")->execute([$newStatus, $assignmentId]);
            }

            // Order status log
            $pdo->prepare("
                INSERT INTO order_status_history (sales_order_id, from_status, to_status, remarks, changed_by, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ")->execute([
                $orderId, $order['status'], $newStatus,
                "Reconciled via Run Sheet. Cash: Rs. {$cashAmt}, Credit: Rs. {$creditAmt}, Return: Rs. {$returnAmt}",
                $currentUser['id']
            ]);

            logAudit($pdo, 'delivery', $assignmentId ?: $orderId, 'reconcile_sheet', null, [
                'order_number' => $order['order_number'],
                'cash' => $cashAmt,
                'credit' => $creditAmt,
                'return' => $returnAmt,
                'status' => $newStatus
            ]);

            $processedCount++;
        }

        $pdo->commit();

        setFlashMessage('success', "Daily Run Sheet successfully reconciled! {$processedCount} bills updated. Cash Collected: Rs. " . number_format($totalCashProcessed, 2) . ", Credit Recorded: Rs. " . number_format($totalCreditProcessed, 2) . ", Returns: Rs. " . number_format($totalReturnProcessed, 2));
        redirect(BASE_URL . "/modules/delivery/sheet_reconcile.php?date={$date}&delivery_man_id={$driverId}");

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        setFlashMessage('error', 'Reconciliation failed: ' . $e->getMessage());
        redirect(BASE_URL . "/modules/delivery/sheet_reconcile.php?date={$date}&delivery_man_id={$driverId}");
    }
}

// Fetch orders for reconciliation
$whereClauses = ["so.order_date = ?", "so.status IN ('approved', 'dispatched', 'in_transit', 'partially_delivered')"];
$params = [$date];

if ($deliveryManId > 0) {
    $whereClauses[] = "EXISTS (SELECT 1 FROM delivery_assignments da WHERE da.sales_order_id = so.id AND da.delivery_man_id = ?)";
    $params[] = $deliveryManId;
}
if ($areaId > 0) {
    $whereClauses[] = "so.area_id = ?";
    $params[] = $areaId;
}

$whereSql = implode(' AND ', $whereClauses);

$ordersStmt = $pdo->prepare("
    SELECT 
        so.id as sales_order_id,
        so.order_number,
        so.order_date,
        so.grand_total as order_amount,
        so.status as order_status,
        c.id as customer_id,
        c.shop_name,
        c.owner_name,
        c.phone as cust_phone,
        c.current_balance as cust_balance,
        a.name as area_name,
        (SELECT da.id FROM delivery_assignments da WHERE da.sales_order_id = so.id LIMIT 1) as assignment_id,
        (SELECT emp.full_name FROM delivery_assignments da JOIN employees emp ON da.delivery_man_id = emp.id WHERE da.sales_order_id = so.id LIMIT 1) as rider_name
    FROM sales_orders so
    JOIN customers c ON so.customer_id = c.id
    JOIN areas a ON so.area_id = a.id
    WHERE {$whereSql}
    ORDER BY a.name ASC, c.shop_name ASC
");
$ordersStmt->execute($params);
$orders = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Daily Run Sheet Reconciliation';
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-3 gap-2">
    <div>
        <h4 class="fw-bold mb-0 text-dark">
            <i class="bi bi-ui-checks-grid text-primary me-2"></i>Daily Run Sheet Reconciliation
        </h4>
        <small class="text-muted">Direct back-office settlement of Rider Paper Run Sheets (Cash + Udhaar + Returns)</small>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/modules/delivery/sheet_print.php?date=<?= $date ?>&delivery_man_id=<?= $deliveryManId ?>&area_id=<?= $areaId ?>" target="_blank" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-printer me-1"></i> Print Run Sheet
        </a>
        <a href="<?= BASE_URL ?>/modules/delivery/index.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-truck me-1"></i> Delivery Hub
        </a>
    </div>
</div>

<!-- Filter Selection Bar -->
<div class="card border-0 shadow-sm rounded-3 mb-3 bg-white">
    <div class="card-body p-3">
        <form method="GET" action="" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small fw-bold text-muted mb-1">Dispatch / Order Date</label>
                <input type="date" name="date" class="form-control form-control-sm" value="<?= htmlspecialchars($date) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-bold text-muted mb-1">Delivery Man / Van</label>
                <select name="delivery_man_id" class="form-select form-select-sm">
                    <option value="0">-- All Delivery Drivers --</option>
                    <?php foreach ($deliveryStaff as $ds): ?>
                        <option value="<?= $ds['id'] ?>" <?= $deliveryManId == $ds['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($ds['full_name']) ?> (<?= htmlspecialchars($ds['employee_code']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-bold text-muted mb-1">Area / Route</label>
                <select name="area_id" class="form-select form-select-sm">
                    <option value="0">-- All Routes --</option>
                    <?php foreach ($areas as $a): ?>
                        <option value="<?= $a['id'] ?>" <?= $areaId == $a['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($a['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-1">
                <button type="submit" class="btn btn-sm btn-primary w-100 fw-bold">
                    <i class="bi bi-filter me-1"></i> Load Orders
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Live Calculation KPI Cards -->
<div class="row g-2 mb-3">
    <div class="col-6 col-md-2">
        <div class="p-2 border rounded bg-white shadow-sm">
            <div class="text-muted small fw-semibold">Orders Loaded</div>
            <div class="h5 fw-bold text-dark mb-0"><?= count($orders) ?></div>
            <small class="text-muted">Awaiting posting</small>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="p-2 border rounded bg-white shadow-sm">
            <div class="text-muted small fw-semibold">Total Invoiced</div>
            <div class="h5 fw-bold text-primary mb-0" id="kpiTotalInvoiced">Rs. 0</div>
            <small class="text-muted">Value dispatched</small>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="p-2 border rounded bg-white shadow-sm">
            <div class="text-muted small fw-semibold">Cash Collected</div>
            <div class="h5 fw-bold text-success mb-0" id="kpiTotalCash">Rs. 0</div>
            <small class="text-muted">Physical cash</small>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="p-2 border rounded bg-white shadow-sm">
            <div class="text-muted small fw-semibold">Credit (Udhaar)</div>
            <div class="h5 fw-bold text-warning mb-0" id="kpiTotalCredit">Rs. 0</div>
            <small class="text-muted">Added to Ledger</small>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="p-2 border rounded bg-white shadow-sm">
            <div class="text-muted small fw-semibold">Returns (Wapsi)</div>
            <div class="h5 fw-bold text-info mb-0" id="kpiTotalReturn">Rs. 0</div>
            <small class="text-muted">Stock brought back</small>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="p-2 border rounded bg-white shadow-sm">
            <div class="text-muted small fw-semibold">Balance Status</div>
            <div class="h5 fw-bold mb-0" id="kpiStatus">
                <span class="badge bg-secondary">Ready</span>
            </div>
            <small class="text-muted" id="kpiDiffDetail">Diff: Rs. 0</small>
        </div>
    </div>
</div>

<!-- Reconciliation Form -->
<form method="POST" action="" id="reconcileForm">
    <?= getCsrfField() ?>
    <input type="hidden" name="action" value="reconcile_sheet">
    <input type="hidden" name="driver_id" value="<?= $deliveryManId ?>">
    <input type="hidden" name="reconcile_date" value="<?= htmlspecialchars($date) ?>">

    <div class="card border-0 shadow-sm rounded-3 mb-3 bg-white">
        <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center border-bottom">
            <span class="fw-bold text-dark">
                <i class="bi bi-table text-primary me-1"></i> Dispatched Bills Spread Sheet
            </span>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-xs btn-outline-success" onclick="markAllFullCash();">
                    <i class="bi bi-check-all me-1"></i> Fill All Full Cash
                </button>
                <button type="button" class="btn btn-xs btn-outline-warning text-dark" onclick="markAllCredit();">
                    <i class="bi bi-journal-plus me-1"></i> Fill All Full Credit
                </button>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-bordered align-middle mb-0" id="reconcileTable" style="font-size: 0.88rem;">
                    <thead class="table-light text-center">
                        <tr>
                            <th style="width: 40px;">
                                <input type="checkbox" id="checkAll" checked onclick="toggleAll(this);">
                            </th>
                            <th style="width: 45px;">Sr.</th>
                            <th class="text-start" style="width: 22%;">Shop Name & Area</th>
                            <th style="width: 14%;">Bill No</th>
                            <th style="width: 11%;">Bill Amount</th>
                            <th style="width: 14%;">Cash Received</th>
                            <th style="width: 14%;">Credit (Udhaar)</th>
                            <th style="width: 12%;">Return (Wapsi)</th>
                            <th style="width: 9%;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        if (empty($orders)):
                        ?>
                            <tr>
                                <td colspan="9" class="text-center py-5 text-muted">
                                    <i class="bi bi-inbox fs-2 d-block mb-2 text-secondary"></i>
                                    No pending dispatched orders found for <?= date('d M Y', strtotime($date)) ?>.
                                </td>
                            </tr>
                        <?php 
                        else:
                            $sr = 1;
                            foreach ($orders as $o):
                                $oId = (int)$o['sales_order_id'];
                                $billAmt = (float)$o['order_amount'];
                        ?>
                            <tr class="order-row" id="row_<?= $oId ?>" data-order-id="<?= $oId ?>" data-bill-amount="<?= $billAmt ?>">
                                <td class="text-center">
                                    <input type="checkbox" name="order_ids[]" value="<?= $oId ?>" class="order-checkbox" checked onchange="recalcTotals();">
                                </td>
                                <td class="text-center text-muted"><?= $sr++ ?></td>
                                <td>
                                    <div class="fw-bold text-dark"><?= htmlspecialchars($o['shop_name']) ?></div>
                                    <small class="text-muted"><?= htmlspecialchars($o['area_name']) ?> &middot; Ph: <?= htmlspecialchars($o['cust_phone'] ?: '-') ?></small>
                                </td>
                                <td class="text-center">
                                    <a href="<?= BASE_URL ?>/modules/orders/bill_compact.php?id=<?= $oId ?>" target="_blank" class="fw-bold text-decoration-none">
                                        <?= htmlspecialchars($o['order_number']) ?>
                                    </a>
                                </td>
                                <td class="text-end fw-bold text-dark" style="font-size: 0.95rem;">
                                    Rs. <?= number_format($billAmt, 0) ?>
                                </td>
                                <td>
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text">Rs.</span>
                                        <input type="number" step="1" min="0" max="<?= $billAmt ?>" name="cash[<?= $oId ?>]" 
                                               class="form-control text-end fw-bold text-success input-cash" 
                                               value="<?= (int)$billAmt ?>" 
                                               data-order-id="<?= $oId ?>" 
                                               oninput="onCashInput(<?= $oId ?>); recalcTotals();">
                                    </div>
                                </td>
                                <td>
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text">Rs.</span>
                                        <input type="number" step="1" min="0" max="<?= $billAmt ?>" name="credit[<?= $oId ?>]" 
                                               class="form-control text-end fw-bold text-warning input-credit" 
                                               value="0" 
                                               data-order-id="<?= $oId ?>" 
                                               oninput="onCreditInput(<?= $oId ?>); recalcTotals();">
                                    </div>
                                </td>
                                <td>
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text">Rs.</span>
                                        <input type="number" step="1" min="0" max="<?= $billAmt ?>" name="returns[<?= $oId ?>]" 
                                               class="form-control text-end fw-bold text-danger input-return" 
                                               value="0" 
                                               data-order-id="<?= $oId ?>" 
                                               oninput="onReturnInput(<?= $oId ?>); recalcTotals();">
                                    </div>
                                </td>
                                <td class="text-center status-col" id="status_<?= $oId ?>">
                                    <span class="badge bg-success-subtle text-success border border-success">Balanced</span>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php if (!empty($orders)): ?>
            <div class="card-footer bg-light p-3 d-flex justify-content-between align-items-center">
                <span class="text-muted small">
                    <i class="bi bi-shield-check text-success me-1"></i> Double-entry accounting entries and inventory return records will be created automatically.
                </span>
                <button type="submit" class="btn btn-primary px-4 fw-bold shadow-sm" id="btnSubmitReconcile">
                    <i class="bi bi-check-circle-fill me-1"></i> Post &amp; Reconcile Daily Run Sheet
                </button>
            </div>
        <?php endif; ?>
    </div>
</form>

<script>
function toggleAll(master) {
    const boxes = document.querySelectorAll('.order-checkbox');
    boxes.forEach(cb => cb.checked = master.checked);
    recalcTotals();
}

function onCashInput(orderId) {
    const row = document.getElementById('row_' + orderId);
    if (!row) return;
    const billAmt = parseFloat(row.getAttribute('data-bill-amount')) || 0;
    const cashInput = row.querySelector('.input-cash');
    const creditInput = row.querySelector('.input-credit');
    const returnInput = row.querySelector('.input-return');

    let cash = parseFloat(cashInput.value) || 0;
    let ret = parseFloat(returnInput.value) || 0;

    let remCredit = billAmt - cash - ret;
    if (remCredit >= 0) {
        creditInput.value = remCredit;
    }
}

function onCreditInput(orderId) {
    const row = document.getElementById('row_' + orderId);
    if (!row) return;
    const billAmt = parseFloat(row.getAttribute('data-bill-amount')) || 0;
    const cashInput = row.querySelector('.input-cash');
    const creditInput = row.querySelector('.input-credit');
    const returnInput = row.querySelector('.input-return');

    let cred = parseFloat(creditInput.value) || 0;
    let ret = parseFloat(returnInput.value) || 0;

    let remCash = billAmt - cred - ret;
    if (remCash >= 0) {
        cashInput.value = remCash;
    }
}

function onReturnInput(orderId) {
    const row = document.getElementById('row_' + orderId);
    if (!row) return;
    const billAmt = parseFloat(row.getAttribute('data-bill-amount')) || 0;
    const cashInput = row.querySelector('.input-cash');
    const creditInput = row.querySelector('.input-credit');
    const returnInput = row.querySelector('.input-return');

    let ret = parseFloat(returnInput.value) || 0;
    let cash = parseFloat(cashInput.value) || 0;

    let remCredit = billAmt - cash - ret;
    if (remCredit >= 0) {
        creditInput.value = remCredit;
    } else {
        creditInput.value = 0;
        cashInput.value = Math.max(0, billAmt - ret);
    }
}

function markAllFullCash() {
    document.querySelectorAll('.order-row').forEach(row => {
        const billAmt = parseFloat(row.getAttribute('data-bill-amount')) || 0;
        row.querySelector('.input-cash').value = billAmt;
        row.querySelector('.input-credit').value = 0;
        row.querySelector('.input-return').value = 0;
    });
    recalcTotals();
}

function markAllCredit() {
    document.querySelectorAll('.order-row').forEach(row => {
        const billAmt = parseFloat(row.getAttribute('data-bill-amount')) || 0;
        row.querySelector('.input-cash').value = 0;
        row.querySelector('.input-credit').value = billAmt;
        row.querySelector('.input-return').value = 0;
    });
    recalcTotals();
}

function recalcTotals() {
    let totInvoiced = 0;
    let totCash = 0;
    let totCredit = 0;
    let totReturn = 0;
    let hasDiscrepancy = false;

    document.querySelectorAll('.order-row').forEach(row => {
        const chk = row.querySelector('.order-checkbox');
        if (!chk || !chk.checked) return;

        const orderId = row.getAttribute('data-order-id');
        const billAmt = parseFloat(row.getAttribute('data-bill-amount')) || 0;
        const cash = parseFloat(row.querySelector('.input-cash').value) || 0;
        const cred = parseFloat(row.querySelector('.input-credit').value) || 0;
        const ret = parseFloat(row.querySelector('.input-return').value) || 0;

        totInvoiced += billAmt;
        totCash += cash;
        totCredit += cred;
        totReturn += ret;

        const sum = Math.round((cash + cred + ret) * 100) / 100;
        const target = Math.round(billAmt * 100) / 100;
        const statusEl = document.getElementById('status_' + orderId);

        if (Math.abs(sum - target) < 0.01) {
            statusEl.innerHTML = '<span class="badge bg-success-subtle text-success border border-success">Balanced</span>';
        } else {
            hasDiscrepancy = true;
            const diff = sum - target;
            statusEl.innerHTML = '<span class="badge bg-danger-subtle text-danger border border-danger">Diff: ' + (diff > 0 ? '+' : '') + diff.toFixed(0) + '</span>';
        }
    });

    document.getElementById('kpiTotalInvoiced').innerText = 'Rs. ' + Math.round(totInvoiced).toLocaleString();
    document.getElementById('kpiTotalCash').innerText = 'Rs. ' + Math.round(totCash).toLocaleString();
    document.getElementById('kpiTotalCredit').innerText = 'Rs. ' + Math.round(totCredit).toLocaleString();
    document.getElementById('kpiTotalReturn').innerText = 'Rs. ' + Math.round(totReturn).toLocaleString();

    const grandSum = totCash + totCredit + totReturn;
    const grandDiff = grandSum - totInvoiced;
    const kpiStatus = document.getElementById('kpiStatus');
    const kpiDiffDetail = document.getElementById('kpiDiffDetail');

    if (Math.abs(grandDiff) < 0.01 && !hasDiscrepancy) {
        kpiStatus.innerHTML = '<span class="badge bg-success">100% Balanced</span>';
        kpiDiffDetail.innerText = 'All items reconcile perfectly';
        kpiDiffDetail.className = 'text-success fw-bold';
    } else {
        kpiStatus.innerHTML = '<span class="badge bg-danger">Imbalance Alert</span>';
        kpiDiffDetail.innerText = 'Difference: Rs. ' + (grandDiff > 0 ? '+' : '') + Math.round(grandDiff).toLocaleString();
        kpiDiffDetail.className = 'text-danger fw-bold';
    }
}

document.addEventListener('DOMContentLoaded', () => {
    recalcTotals();
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
