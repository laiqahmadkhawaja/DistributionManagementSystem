<?php
/**
 * Delivery Execution, Verification & Payment Collection
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('delivery', 'deliver');

$assignmentId = (int)($_GET['id'] ?? 0);
if (!$assignmentId) {
    setFlashMessage('error', 'Invalid delivery assignment ID.');
    redirect(BASE_URL . '/modules/delivery/index.php');
}

$pdo = getDatabaseConnection();
$currentUser = getCurrentUser();
$currentEmp = getCurrentEmployee();

// Fetch delivery assignment
$daStmt = $pdo->prepare("
    SELECT da.*, so.order_number, so.order_date, so.grand_total as order_amount,
           c.id as customer_id, c.shop_name, c.owner_name, c.phone as cust_phone, c.address as cust_address,
           c.current_balance as cust_balance, a.name as area_name,
           e.full_name as delivery_man_name
    FROM `delivery_assignments` da
    JOIN `sales_orders` so ON da.sales_order_id = so.id
    JOIN `customers` c ON so.customer_id = c.id
    JOIN `areas` a ON so.area_id = a.id
    JOIN `employees` e ON da.delivery_man_id = e.id
    WHERE da.id = ?
");
$daStmt->execute([$assignmentId]);
$delivery = $daStmt->fetch();

if (!$delivery) {
    setFlashMessage('error', 'Delivery assignment not found.');
    redirect(BASE_URL . '/modules/delivery/index.php');
}

// Fetch delivery items
$itemsStmt = $pdo->prepare("
    SELECT di.*, p.name as product_name, p.code as product_code, p.purchase_price, p.trade_price, u.name as unit_name
    FROM `delivery_items` di
    JOIN `products` p ON di.product_id = p.id
    JOIN `units` u ON di.unit_id = u.id
    WHERE di.delivery_assignment_id = ?
");
$itemsStmt->execute([$assignmentId]);
$items = $itemsStmt->fetchAll();

// Available Payment Methods
$paymentMethods = $pdo->query("SELECT * FROM `payment_methods` WHERE is_active = 1 ORDER BY id ASC")->fetchAll();

// Process POST Delivery & Collection Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $deliveredQuantities = $_POST['delivered_qty'] ?? [];
    $returnedQuantities = $_POST['returned_qty'] ?? [];
    $returnReasons = $_POST['return_reason'] ?? [];

    $cashAmount = (float)($_POST['pay_cash'] ?? 0.00);
    $jazzcashAmount = (float)($_POST['pay_jazzcash'] ?? 0.00);
    $easypaisaAmount = (float)($_POST['pay_easypaisa'] ?? 0.00);
    $bankAmount = (float)($_POST['pay_bank'] ?? 0.00);
    $chequeAmount = (float)($_POST['pay_cheque'] ?? 0.00);

    $receiverName = sanitize($_POST['receiver_name'] ?? $delivery['owner_name']);
    $receiverPhone = sanitize($_POST['receiver_phone'] ?? $delivery['cust_phone']);
    $notes = sanitize($_POST['delivery_notes'] ?? '');

    $totalPaymentCollected = $cashAmount + $jazzcashAmount + $easypaisaAmount + $bankAmount + $chequeAmount;

    try {
        $pdo->beginTransaction();

        $allDeliveredFull = true;
        $anyDelivered = false;

        $diUpdate = $pdo->prepare("
            UPDATE `delivery_items` 
            SET `delivered_qty` = ?, `returned_qty` = ?, `return_reason` = ?
            WHERE id = ?
        ");

        $soiUpdate = $pdo->prepare("UPDATE `sales_order_items` SET `delivered_qty` = ? WHERE id = ?");

        $returnedItemsList = [];
        $driverId = !empty($delivery['delivery_man_id']) ? (int)$delivery['delivery_man_id'] : (int)($currentEmp['id'] ?? 0);

        foreach ($items as $item) {
            $dId = $item['id'];
            $dispatched = (float)$item['dispatched_qty'];
            $delQty = (float)($deliveredQuantities[$dId] ?? $dispatched);
            $retQty = (float)($returnedQuantities[$dId] ?? 0.00);
            $reason = sanitize($returnReasons[$dId] ?? '');

            if ($delQty < $dispatched) {
                $allDeliveredFull = false;
            }
            if ($delQty > 0) {
                $anyDelivered = true;
            }

            $diUpdate->execute([$delQty, $retQty, $reason, $dId]);
            $soiUpdate->execute([$delQty, $item['sales_order_item_id']]);

            // Queue returned items for vehicle custody & warehouse stock handover
            // Notice: Items are placed into driver custody (NOT added to active stock_ledger)
            if ($retQty > 0) {
                $unitPrice = (float)($item['trade_price'] ?? 0.00);
                $unitCost = (float)($item['purchase_price'] ?? 0.00);
                $returnedItemsList[] = [
                    'product_id' => (int)$item['product_id'],
                    'unit_id' => (int)$item['unit_id'],
                    'quantity' => $retQty,
                    'unit_price' => $unitPrice,
                    'unit_cost' => $unitCost,
                    'total_amount' => round($retQty * $unitPrice, 2),
                    'condition_type' => !empty($reason) ? $reason : 'good'
                ];
            }
        }

        // If any items were returned, record Sales Return into Driver's Vehicle Custody
        if (!empty($returnedItemsList)) {
            $srNumber = generateDocNumber($pdo, 'sales_returns', 'return_number', 'SR-');
            $srTotal = 0.00;
            foreach ($returnedItemsList as $ri) {
                $srTotal += $ri['total_amount'];
            }

            $srStmt = $pdo->prepare("
                INSERT INTO `sales_returns` (
                    `return_number`, `customer_id`, `delivery_man_id`, `delivery_assignment_id`,
                    `warehouse_id`, `return_date`, `total_amount`, `return_destination`,
                    `status`, `handover_status`, `reason`, `created_by`, `created_at`
                ) VALUES (?, ?, ?, ?, ?, CURDATE(), ?, 'quarantine', 'approved', 'in_vehicle_custody', ?, ?, NOW())
            ");
            $srStmt->execute([
                $srNumber, $delivery['customer_id'], $driverId, $assignmentId,
                $delivery['warehouse_id'], $srTotal, "Returned on delivery {$delivery['dispatch_number']}",
                $currentUser['id']
            ]);
            $srId = (int)$pdo->lastInsertId();

            $sriStmt = $pdo->prepare("
                INSERT INTO `sales_return_items` (
                    `sales_return_id`, `product_id`, `unit_id`, `quantity`,
                    `unit_price`, `unit_cost`, `total_amount`, `condition_type`, `resell_status`
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'in_vehicle_custody')
            ");
            foreach ($returnedItemsList as $ri) {
                $sriStmt->execute([
                    $srId, $ri['product_id'], $ri['unit_id'], $ri['quantity'],
                    $ri['unit_price'], $ri['unit_cost'], $ri['total_amount'], $ri['condition_type']
                ]);
            }

            // Generate Credit Note for customer
            $cnNumber = generateDocNumber($pdo, 'credit_notes', 'credit_note_number', 'CN-');
            $pdo->prepare("
                INSERT INTO `credit_notes` (`credit_note_number`, `sales_return_id`, `customer_id`, `amount`, `note_date`, `status`, `created_at`)
                VALUES (?, ?, ?, ?, CURDATE(), 'active', NOW())
            ")->execute([$cnNumber, $srId, $delivery['customer_id'], $srTotal]);

            // Adjust customer ledger balance for the returned items
            updateCustomerBalance($pdo, (int)$delivery['customer_id'], -$srTotal);
        }

        $newStatus = $allDeliveredFull ? 'delivered' : ($anyDelivered ? 'partially_delivered' : 'returned');

        // Update Delivery Assignment
        $pdo->prepare("UPDATE `delivery_assignments` SET `status` = ?, `notes` = ? WHERE id = ?")->execute([$newStatus, $notes, $assignmentId]);
        $pdo->prepare("UPDATE `sales_orders` SET `status` = ? WHERE id = ?")->execute([$newStatus, $delivery['sales_order_id']]);

        // Add Proof record
        $dpStmt = $pdo->prepare("
            INSERT INTO `delivery_proofs` (`delivery_assignment_id`, `customer_id`, `receiver_name`, `receiver_phone`, `notes`, `created_at`)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $dpStmt->execute([$assignmentId, $delivery['customer_id'], $receiverName, $receiverPhone, $notes]);

        // Process Payment Recoveries (if any payment was collected)
        // If delivery was assigned to a delivery man, he holds the physical cash in custody
        $collectorId = !empty($delivery['delivery_man_id']) ? (int)$delivery['delivery_man_id'] : ($currentEmp['id'] ?? null);
        $paymentsMade = [
            'cash' => $cashAmount,
            'jazzcash' => $jazzcashAmount,
            'easypaisa' => $easypaisaAmount,
            'bank_transfer' => $bankAmount,
            'cheque' => $chequeAmount
        ];

        foreach ($paymentsMade as $methodCode => $amt) {
            if ($amt > 0) {
                // Find payment method account
                $pmStmt = $pdo->prepare("SELECT * FROM `payment_methods` WHERE code = ? LIMIT 1");
                $pmStmt->execute([$methodCode]);
                $pm = $pmStmt->fetch();

                if ($pm) {
                    $receiptNumber = generateDocNumber($pdo, 'payments', 'payment_number', 'RCP-');
                    $handoverStatus = ($methodCode === 'cash') ? 'pending_handover' : 'reconciled';

                    $pStmt = $pdo->prepare("
                        INSERT INTO `payments` (
                            `payment_number`, `payment_type`, `reference_type`, `reference_id`, 
                            `party_type`, `party_id`, `amount`, `payment_method_id`, 
                            `payment_method_code`, `payment_account_id`, `payment_date`, 
                            `collected_by`, `handover_status`, `notes`, `status`, `created_at`
                        ) VALUES (?, 'customer_receipt', 'delivery', ?, 'customer', ?, ?, ?, ?, ?, CURDATE(), ?, ?, ?, 'posted', NOW())
                    ");
                    $pStmt->execute([
                        $receiptNumber, $assignmentId, $delivery['customer_id'],
                        $amt, $pm['id'], $methodCode, $pm['account_id'],
                        $collectorId, $handoverStatus, "Collected on delivery {$delivery['dispatch_number']}"
                    ]);
                    $paymentId = (int)$pdo->lastInsertId();

                    // Automatic Double-Entry Accounting Entry
                    postCustomerPaymentAccounting($pdo, $paymentId, $receiptNumber, $delivery['customer_id'], $amt, $pm['account_id'], $currentUser['id']);
                }
            }
        }

        // Status history
        $pdo->prepare("
            INSERT INTO `order_status_history` (`sales_order_id`, `from_status`, `to_status`, `remarks`, `changed_by`, `created_at`)
            VALUES (?, 'dispatched', ?, ?, ?, NOW())
        ")->execute([$delivery['sales_order_id'], $newStatus, "Delivered by {$delivery['delivery_man_name']}. Collected: Rs. " . number_format($totalPaymentCollected, 2), $currentUser['id']]);

        logAudit($pdo, 'delivery', $assignmentId, 'deliver', null, [
            'status' => $newStatus,
            'collected' => $totalPaymentCollected,
            'receiver' => $receiverName
        ]);

        $pdo->commit();

        setFlashMessage('success', "Delivery marked as {$newStatus}! Total payment Rs. " . number_format($totalPaymentCollected, 2) . " successfully posted to ledger.");
        redirect(BASE_URL . '/modules/delivery/index.php');

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        setFlashMessage('error', 'Delivery completion error: ' . $e->getMessage());
        redirect(BASE_URL . "/modules/delivery/deliver.php?id={$assignmentId}");
    }
}

$pageTitle = 'Deliver ' . $delivery['dispatch_number'];
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="fw-bold mb-0 text-dark"><i class="bi bi-box2-check text-success me-2"></i>Record Delivery & Collection</h4>
        <small class="text-muted">Challan: <span class="fw-bold text-dark"><?= htmlspecialchars($delivery['dispatch_number']) ?></span> · Order: <?= htmlspecialchars($delivery['order_number']) ?></small>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/modules/orders/bill.php?id=<?= $delivery['sales_order_id'] ?>" class="btn btn-sm btn-outline-success">
            <i class="bi bi-receipt me-1"></i> View Bill
        </a>
        <a href="<?= BASE_URL ?>/modules/warehouse/gatepass.php?id=<?= $delivery['id'] ?>" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-shield-check me-1"></i> View Gate Pass
        </a>
        <a href="<?= BASE_URL ?>/modules/delivery/index.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back
        </a>
    </div>
</div>

<!-- Customer & Receivable Summary Bar -->
<div class="card border-0 shadow-sm rounded-3 mb-3 bg-white">
    <div class="card-body p-3">
        <div class="row g-2 align-items-center">
            <div class="col-12 col-md-6">
                <span class="badge bg-light text-primary border mb-1"><?= htmlspecialchars($delivery['area_name']) ?></span>
                <h5 class="fw-bold text-dark mb-0"><?= htmlspecialchars($delivery['shop_name']) ?></h5>
                <small class="text-muted"><?= htmlspecialchars($delivery['owner_name']) ?> · <?= htmlspecialchars($delivery['cust_address']) ?></small>
            </div>
            <div class="col-12 col-md-6 border-start-md">
                <div class="d-flex justify-content-between justify-content-md-end gap-3 text-md-end">
                    <div>
                        <div class="text-muted small">Current Order</div>
                        <div class="fw-bold text-dark"><?= formatMoney($delivery['order_amount']) ?></div>
                    </div>
                    <div>
                        <div class="text-muted small">Total Outstanding</div>
                        <div class="fw-bold text-danger fs-5" id="disp-total-due" data-due="<?= (float)$delivery['cust_balance'] ?>">
                            <?= formatMoney($delivery['cust_balance']) ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<form id="delivery-submission-form" method="POST" action="<?= BASE_URL ?>/modules/delivery/deliver.php?id=<?= $assignmentId ?>">
    <?= getCsrfField() ?>

    <div class="row g-3">
        <!-- Section 1: Item Quantities Verification -->
        <div class="col-12 col-lg-7">
            <div class="card border-0 shadow-sm rounded-3 mb-3">
                <div class="card-header bg-white py-3 border-0">
                    <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-boxes me-2 text-primary"></i>1. Verify Delivered Quantities</h6>
                    <small class="text-muted">Enter actual quantities handed over to the shopkeeper.</small>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-bordered table-responsive-card align-middle mb-0">
                            <thead class="table-light small">
                                <tr>
                                    <th>Product</th>
                                    <th class="text-center">Dispatched</th>
                                    <th class="text-center" style="width: 110px;">Delivered</th>
                                    <th class="text-center" style="width: 100px;">Returned</th>
                                    <th>Return Reason</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $item): ?>
                                    <tr>
                                        <td data-label="Product">
                                            <div class="fw-bold text-dark"><?= htmlspecialchars($item['product_name']) ?></div>
                                            <small class="text-muted"><?= formatMoney($item['unit_price']) ?> / <?= htmlspecialchars($item['unit_name']) ?></small>
                                        </td>
                                        <td data-label="Dispatched" class="text-center fw-bold text-muted"><?= (float)$item['dispatched_qty'] ?></td>
                                        <td data-label="Delivered" class="text-center">
                                            <input type="number" 
                                                   class="form-control form-control-sm text-center fw-bold text-success" 
                                                   name="delivered_qty[<?= $item['id'] ?>]" 
                                                   value="<?= (float)$item['dispatched_qty'] ?>" 
                                                   min="0" 
                                                   max="<?= (float)$item['dispatched_qty'] ?>" 
                                                   step="1" 
                                                   required>
                                        </td>
                                        <td data-label="Returned" class="text-center">
                                            <input type="number" 
                                                   class="form-control form-control-sm text-center text-danger" 
                                                   name="returned_qty[<?= $item['id'] ?>]" 
                                                   value="0" 
                                                   min="0" 
                                                   max="<?= (float)$item['dispatched_qty'] ?>" 
                                                   step="1">
                                        </td>
                                        <td data-label="Reason">
                                            <select class="form-select form-select-sm" name="return_reason[<?= $item['id'] ?>]">
                                                <option value="">None</option>
                                                <option value="Damaged / Leaked">Damaged / Leaked</option>
                                                <option value="Expired / Near Expiry">Expired</option>
                                                <option value="Customer Refused">Refused</option>
                                                <option value="Wrong Product">Wrong Product</option>
                                            </select>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Proof & Receiver Information -->
            <div class="card border-0 shadow-sm rounded-3 mb-3">
                <div class="card-header bg-white py-3 border-0">
                    <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-person-check me-2 text-primary"></i>2. Receiver Confirmation</h6>
                </div>
                <div class="card-body p-3">
                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Receiver Person Name</label>
                            <input type="text" class="form-control form-control-sm" name="receiver_name" value="<?= htmlspecialchars($delivery['owner_name']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Receiver Phone</label>
                            <input type="text" class="form-control form-control-sm" name="receiver_phone" value="<?= htmlspecialchars($delivery['cust_phone']) ?>">
                        </div>
                        <div class="col-12 mt-2">
                            <label class="form-label small fw-semibold">Delivery Notes / Remarks</label>
                            <input type="text" class="form-control form-control-sm" name="delivery_notes" placeholder="e.g. Received in good order">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Section 2: Multi-Method Payment Collection (PRD Section 2.11) -->
        <div class="col-12 col-lg-5">
            <div class="card border-0 shadow-sm rounded-3 mb-3">
                <div class="card-header bg-white py-3 border-0">
                    <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-cash-stack me-2 text-success"></i>3. Payment Recovery</h6>
                    <small class="text-muted">Supports full, partial, credit or multiple payment modes.</small>
                </div>
                <div class="card-body p-3">
                    <!-- Cash Payment -->
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-dark d-flex justify-content-between">
                            <span><i class="bi bi-cash me-1 text-success"></i> Cash Received (Rs.)</span>
                            <span class="text-muted small">Physical Currency</span>
                        </label>
                        <input type="number" class="form-control form-control-lg pay-field fw-bold text-success" id="pay_cash" name="pay_cash" value="<?= (float)$delivery['order_amount'] ?>" step="1" min="0">
                    </div>

                    <!-- Digital Wallets (Easypaisa / JazzCash) -->
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Easypaisa (Rs.)</label>
                            <input type="number" class="form-control pay-field" id="pay_easypaisa" name="pay_easypaisa" value="0.00" step="1" min="0">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold">JazzCash (Rs.)</label>
                            <input type="number" class="form-control pay-field" id="pay_jazzcash" name="pay_jazzcash" value="0.00" step="1" min="0">
                        </div>
                    </div>

                    <!-- Bank Transfer / Cheque -->
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Bank Transfer (Rs.)</label>
                            <input type="number" class="form-control pay-field" id="pay_bank" name="pay_bank" value="0.00" step="1" min="0">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Bank Cheque (Rs.)</label>
                            <input type="number" class="form-control pay-field" id="pay_cheque" name="pay_cheque" value="0.00" step="1" min="0">
                        </div>
                    </div>

                    <!-- Dynamic Payment Calculation Summary Box -->
                    <div class="p-3 bg-light rounded-3 mb-3 border">
                        <div class="d-flex justify-content-between mb-1">
                            <span class="text-muted small">Total Due:</span>
                            <span class="fw-semibold">Rs. <?= number_format($delivery['cust_balance'], 2) ?></span>
                        </div>
                        <div class="d-flex justify-content-between mb-1">
                            <span class="text-muted small">Total Received:</span>
                            <span class="fw-bold text-success" id="calc-total-received">Rs. 0.00</span>
                        </div>
                        <hr class="my-1">
                        <div class="d-flex justify-content-between">
                            <span class="fw-bold text-dark">Remaining Receivable:</span>
                            <span class="fw-bold text-danger fs-5" id="calc-remaining-receivable">Rs. 0.00</span>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-success w-100 btn-touch shadow-sm py-2">
                        <i class="bi bi-check2-circle fs-5 me-1 align-middle"></i> Complete Delivery & Post Recovery
                    </button>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const totalDue = parseFloat(document.getElementById('disp-total-due').getAttribute('data-due') || 0);
    const payFields = document.querySelectorAll('.pay-field');
    const calcReceived = document.getElementById('calc-total-received');
    const calcRemaining = document.getElementById('calc-remaining-receivable');

    function recalcPayment() {
        let totalPaid = 0;
        payFields.forEach(f => {
            totalPaid += parseFloat(f.value || 0);
        });

        const remaining = Math.max(0, totalDue - totalPaid);

        calcReceived.innerText = 'Rs. ' + totalPaid.toLocaleString('en-US', {minimumFractionDigits: 2});
        calcRemaining.innerText = 'Rs. ' + remaining.toLocaleString('en-US', {minimumFractionDigits: 2});
    }

    payFields.forEach(f => {
        f.addEventListener('input', recalcPayment);
    });

    recalcPayment();

    // Offline interceptor for mobile drivers
    const form = document.getElementById('delivery-submission-form');
    form.addEventListener('submit', function (e) {
        if (!navigator.onLine) {
            e.preventDefault();
            const payload = {
                assignment_id: <?= $assignmentId ?>,
                customer_id: <?= $delivery['customer_id'] ?>,
                cash_amount: parseFloat(document.getElementById('pay_cash').value || 0),
                digital_amount: parseFloat(document.getElementById('pay_easypaisa').value || 0) + parseFloat(document.getElementById('pay_jazzcash').value || 0),
                items: []
            };

            DMSOffline.addToQueue('delivery_collection', payload);
            window.location.href = '<?= BASE_URL ?>/modules/delivery/index.php';
        }
    });
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
