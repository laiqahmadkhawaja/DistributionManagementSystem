<?php
/**
 * Create New Purchase Order
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('purchases', 'create');

$pdo = getDatabaseConnection();
$currentUser = getCurrentUser();

$suppliers = $pdo->query("SELECT id, name, company_name FROM `suppliers` WHERE status = 'active' ORDER BY name ASC")->fetchAll();
$warehouses = $pdo->query("SELECT id, name FROM `warehouses` WHERE status = 'active' ORDER BY is_default DESC")->fetchAll();
$products = $pdo->query("SELECT id, code, name, purchase_price, base_unit_id FROM `products` WHERE status = 'active' ORDER BY name ASC")->fetchAll();
$allUnits = $pdo->query("SELECT id, name, code, is_base FROM `units` ORDER BY is_base DESC, id ASC")->fetchAll();
$allConversions = $pdo->query("
    SELECT uc.*, u1.name as from_name, u1.code as from_code, u2.name as to_name, u2.code as to_code 
    FROM `unit_conversions` uc
    JOIN `units` u1 ON uc.from_unit_id = u1.id
    JOIN `units` u2 ON uc.to_unit_id = u2.id
")->fetchAll();

// Procurement & Payment Configuration Defaults
$defaultReceivingMode = getSetting($pdo, 'purchase_receiving_mode', 'direct_receive');
$defaultPaymentMode = getSetting($pdo, 'purchase_default_payment_mode', 'credit');
$defaultPaymentAccountId = (int)getSetting($pdo, 'purchase_default_payment_account', 2);

$paymentAccounts = $pdo->query("
    SELECT id, code, name, system_tag 
    FROM `chart_of_accounts` 
    WHERE group_type = 'Asset' AND (system_tag IN ('cash', 'bank') OR parent_id = 1) AND is_active = 1 
    ORDER BY code ASC
")->fetchAll();

$paymentMethods = $pdo->query("
    SELECT id, name, code, account_id 
    FROM `payment_methods` 
    WHERE is_active = 1 
    ORDER BY id ASC
")->fetchAll();

// Handle POST Submission BEFORE HTML output
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $supplierId = (int)($_POST['supplier_id'] ?? 0);
    $warehouseId = (int)($_POST['warehouse_id'] ?? 1);
    $poDate = $_POST['po_date'] ?? date('Y-m-d');
    $expectedDate = !empty($_POST['expected_date']) ? $_POST['expected_date'] : null;
    $referenceNo = sanitize($_POST['reference_no'] ?? '');
    $notes = sanitize($_POST['notes'] ?? '');

    $receivingMode = sanitize($_POST['receiving_mode'] ?? $defaultReceivingMode);
    $paymentStatus = sanitize($_POST['payment_status'] ?? $defaultPaymentMode);
    $paidAmount = (float)($_POST['paid_amount'] ?? 0.00);
    $paymentAccountId = (int)($_POST['payment_account_id'] ?? $defaultPaymentAccountId);
    $paymentMethodId = (int)($_POST['payment_method_id'] ?? 1);
    $paymentRef = sanitize($_POST['payment_ref'] ?? '');
    $paymentNotes = sanitize($_POST['payment_notes'] ?? '');

    $productIds = $_POST['prod_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $costs = $_POST['unit_cost'] ?? [];
    $unitIds = $_POST['unit_id'] ?? [];

    $freight = (float)($_POST['freight'] ?? 0.00);
    $otherCharges = (float)($_POST['other_charges'] ?? 0.00);
    $discount = (float)($_POST['discount'] ?? 0.00);

    if (empty($supplierId) || empty($productIds)) {
        setFlashMessage('error', 'Please select a supplier and add at least one product.');
        redirect(BASE_URL . '/modules/purchases/create.php');
    }

    if ($paymentStatus !== 'credit' && $paidAmount > 0) {
        if ($paymentAccountId <= 0) {
            setFlashMessage('error', 'Please select a valid payment account (Cash or Bank) to disburse funds.');
            redirect(BASE_URL . '/modules/purchases/create.php');
        }
    }

    try {
        $pdo->beginTransaction();

        $subtotal = 0.00;
        $items = [];

        for ($i = 0; $i < count($productIds); $i++) {
            $pId = (int)$productIds[$i];
            $qty = (float)($quantities[$i] ?? 0);
            $cost = (float)($costs[$i] ?? 0);
            $uId = (int)($unitIds[$i] ?? 1);

            if ($pId > 0 && $qty > 0) {
                $lineTot = round($qty * $cost, 2);
                $subtotal += $lineTot;
                $items[] = [
                    'product_id' => $pId,
                    'unit_id' => $uId,
                    'quantity' => $qty,
                    'unit_cost' => $cost,
                    'line_total' => $lineTot
                ];
            }
        }

        if (empty($items)) {
            throw new Exception("Please specify valid quantities for products.");
        }

        $grandTotal = max(0.00, $subtotal - $discount + $freight + $otherCharges);
        $poNumber = generateDocNumber($pdo, 'purchase_orders', 'po_number', 'PO-');
        $poStatus = ($receivingMode === 'direct_receive') ? 'received' : 'submitted';

        $poStmt = $pdo->prepare("
            INSERT INTO `purchase_orders` (
                `po_number`, `supplier_id`, `warehouse_id`, `po_date`, 
                `expected_date`, `reference_no`, `subtotal`, `discount`, 
                `freight`, `other_charges`, `grand_total`, `status`, `notes`, 
                `created_by`, `created_at`
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $poStmt->execute([
            $poNumber, $supplierId, $warehouseId, $poDate,
            $expectedDate, $referenceNo, $subtotal, $discount,
            $freight, $otherCharges, $grandTotal, $poStatus, $notes,
            $currentUser['id']
        ]);
        $poId = (int)$pdo->lastInsertId();

        $itemStmt = $pdo->prepare("
            INSERT INTO `purchase_items` (`purchase_order_id`, `product_id`, `unit_id`, `order_qty`, `received_qty`, `unit_cost`, `total_cost`)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($items as $it) {
            $recQty = ($receivingMode === 'direct_receive') ? $it['quantity'] : 0.00;
            $itemStmt->execute([$poId, $it['product_id'], $it['unit_id'], $it['quantity'], $recQty, $it['unit_cost'], $it['line_total']]);
        }

        $grnId = null;
        $grnNumber = null;

        // DIRECT RECEIVE FLOW: Auto-generate GRN, Stock Movement & Double-Entry Accounting
        if ($receivingMode === 'direct_receive') {
            $grnNumber = generateDocNumber($pdo, 'goods_receipts', 'grn_number', 'GRN-');
            $grnStmt = $pdo->prepare("
                INSERT INTO `goods_receipts` (
                    `grn_number`, `purchase_order_id`, `supplier_id`, `warehouse_id`, 
                    `grn_date`, `invoice_no`, `invoice_date`, `total_amount`, 
                    `freight_cost`, `landed_cost`, `notes`, `posted_to_accounts`, 
                    `created_by`, `created_at`
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW())
            ");
            $landedCost = $grandTotal;
            $grnStmt->execute([
                $grnNumber, $poId, $supplierId, $warehouseId,
                $poDate, $referenceNo, $poDate, $subtotal,
                $freight, $landedCost, "Direct purchase auto-receipt under PO {$poNumber}",
                $currentUser['id']
            ]);
            $grnId = (int)$pdo->lastInsertId();

            $griStmt = $pdo->prepare("
                INSERT INTO `goods_receipt_items` (
                    `goods_receipt_id`, `product_id`, `batch_number`, `expiry_date`, 
                    `unit_id`, `received_qty`, `unit_cost`, `total_cost`
                ) VALUES (?, ?, NULL, NULL, ?, ?, ?, ?)
            ");

            // Cache product base units
            $prodBaseUnitMap = [];
            foreach ($products as $p) {
                $prodBaseUnitMap[$p['id']] = (int)$p['base_unit_id'];
            }

            foreach ($items as $it) {
                $pId = $it['product_id'];
                $uId = $it['unit_id'];
                $recQty = $it['quantity'];
                $uCost = $it['unit_cost'];
                $lineTot = $it['line_total'];

                $griStmt->execute([$grnId, $pId, $uId, $recQty, $uCost, $lineTot]);

                // Convert packaging units to base physical units
                $baseUnitId = $prodBaseUnitMap[$pId] ?? $uId;
                $physicalQtyIn = convertUnitQuantity($pdo, $pId, $uId, $baseUnitId, $recQty);
                $baseUnitCost = ($physicalQtyIn > 0) ? round($lineTot / $physicalQtyIn, 4) : $uCost;

                recordStockMovement(
                    $pdo,
                    $warehouseId,
                    $pId,
                    'purchase_grn',
                    'goods_receipt',
                    $grnId,
                    $baseUnitId,
                    $physicalQtyIn, // Physical base units replenished
                    0.00,
                    $baseUnitCost,
                    null,
                    "Direct purchase receiving under GRN {$grnNumber} against PO {$poNumber}"
                );
            }

            // Post GRN Accounting: Debit Merchandise Inventory, Credit Supplier Accounts Payable
            postGoodsReceiptAccounting($pdo, $grnId, $grnNumber, $supplierId, $grandTotal, $currentUser['id']);
        }

        // PAYMENT SETTLEMENT INTEGRATION: Immediate Cash / Bank Disbursement
        $paymentId = null;
        $paymentNumber = null;
        if ($paymentStatus !== 'credit' && $paidAmount > 0) {
            $pmStmt = $pdo->prepare("SELECT code FROM `payment_methods` WHERE id = ?");
            $pmStmt->execute([$paymentMethodId]);
            $pmCode = $pmStmt->fetchColumn() ?: 'cash';

            $paymentNumber = generateDocNumber($pdo, 'payments', 'payment_number', 'PAY-');
            $payRef = $paymentRef ?: $referenceNo;

            $payStmt = $pdo->prepare("
                INSERT INTO `payments` (
                    `payment_number`, `payment_type`, `reference_type`, `reference_id`, 
                    `party_type`, `party_id`, `amount`, `payment_method_id`, 
                    `payment_method_code`, `payment_account_id`, `payment_date`, 
                    `reference_no`, `notes`, `status`, `created_by`, `created_at`
                ) VALUES (?, 'supplier_payment', 'purchase_order', ?, 'supplier', ?, ?, ?, ?, ?, ?, ?, ?, 'posted', ?, NOW())
            ");
            $payStmt->execute([
                $paymentNumber, $poId, $supplierId, $paidAmount, $paymentMethodId,
                $pmCode, $paymentAccountId, $poDate, $payRef,
                "Immediate purchase disbursement for PO {$poNumber}" . ($paymentNotes ? " - {$paymentNotes}" : ""),
                $currentUser['id']
            ]);
            $paymentId = (int)$pdo->lastInsertId();

            // Post Supplier Payment Accounting: Debit Accounts Payable, Credit Cash / Bank Account
            postSupplierPaymentAccounting($pdo, $paymentId, $paymentNumber, $supplierId, $paidAmount, $paymentAccountId, $currentUser['id']);
        }

        logAudit($pdo, 'purchases', $poId, 'create_purchase', null, [
            'po_number' => $poNumber,
            'receiving_mode' => $receivingMode,
            'grand_total' => $grandTotal,
            'paid_amount' => $paidAmount,
            'grn_number' => $grnNumber,
            'payment_number' => $paymentNumber
        ]);

        $pdo->commit();

        if ($receivingMode === 'direct_receive') {
            $msg = "Direct Purchase {$poNumber} successfully completed! GRN {$grnNumber} generated, warehouse stock replenished";
            if ($paidAmount > 0) {
                $msg .= ", and Rs. " . number_format($paidAmount, 2) . " disbursed via payment voucher {$paymentNumber}.";
            } else {
                $msg .= ", and Rs. " . number_format($grandTotal, 2) . " booked to supplier payable ledger.";
            }
        } else {
            $msg = "Purchase Order {$poNumber} created successfully! Proceed with Goods Receiving (GRN) when supplier delivers";
            if ($paidAmount > 0) {
                $msg .= " (Advance payment of Rs. " . number_format($paidAmount, 2) . " recorded via {$paymentNumber}).";
            } else {
                $msg .= ".";
            }
        }

        setFlashMessage('success', $msg);
        redirect(BASE_URL . '/modules/purchases/index.php');

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        setFlashMessage('error', 'Error processing purchase: ' . $e->getMessage());
        redirect(BASE_URL . '/modules/purchases/create.php');
    }
}

$pageTitle = 'Create Purchase Order';
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="fw-bold mb-0 text-dark"><i class="bi bi-cart-plus text-primary me-2"></i>Create Purchase Order</h4>
        <small class="text-muted">Generate procurement order for manufacturer / supplier</small>
    </div>
    <a href="<?= BASE_URL ?>/modules/purchases/index.php" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i> Back
    </a>
</div>

<form method="POST" action="<?= BASE_URL ?>/modules/purchases/create.php">
    <?= getCsrfField() ?>

    <div class="row g-3">
        <!-- Supplier, Receiving Mode & Payment Settlement -->
        <div class="col-12 col-lg-5">
            <!-- Supplier Information Card -->
            <div class="card border-0 shadow-sm rounded-3 mb-3">
                <div class="card-header bg-white py-3 border-0">
                    <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-building me-2 text-primary"></i>Supplier Information</h6>
                </div>
                <div class="card-body p-3 pt-0">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Supplier <span class="text-danger">*</span></label>
                        <select class="form-select form-select-sm" name="supplier_id" required>
                            <option value="">-- Choose Supplier --</option>
                            <?php foreach ($suppliers as $s): ?>
                                <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?> (<?= htmlspecialchars($s['company_name'] ?: 'Distributor') ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Destination Warehouse <span class="text-danger">*</span></label>
                        <select class="form-select form-select-sm" name="warehouse_id" required>
                            <?php foreach ($warehouses as $w): ?>
                                <option value="<?= $w['id'] ?>"><?= htmlspecialchars($w['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small fw-semibold">PO Date</label>
                            <input type="date" class="form-control form-control-sm" name="po_date" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Expected Date</label>
                            <input type="date" class="form-control form-control-sm" name="expected_date" value="<?= date('Y-m-d', strtotime('+7 days')) ?>">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Supplier Ref / Quote #</label>
                        <input type="text" class="form-control form-control-sm" name="reference_no" placeholder="e.g. QUOTE-998">
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Freight (Rs.)</label>
                            <input type="number" class="form-control form-control-sm" name="freight" id="po-freight" value="0.00" step="0.01">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Discount (Rs.)</label>
                            <input type="number" class="form-control form-control-sm" name="discount" id="po-discount" value="0.00" step="0.01">
                        </div>
                    </div>

                    <div class="mb-0">
                        <label class="form-label small fw-semibold">PO Notes</label>
                        <textarea class="form-control form-control-sm" name="notes" rows="2" placeholder="e.g. Terms Net 30"></textarea>
                    </div>
                </div>
            </div>

            <!-- Stock Receiving & Workflow Mode -->
            <div class="card border-0 shadow-sm rounded-3 mb-3">
                <div class="card-header bg-white py-3 border-0">
                    <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-box-arrow-in-down me-2 text-success"></i>Stock Inflow Workflow</h6>
                </div>
                <div class="card-body p-3 pt-0">
                    <div class="row g-2">
                        <div class="col-12">
                            <div class="form-check p-2 border rounded-2 receiving-option <?= $defaultReceivingMode === 'direct_receive' ? 'border-primary bg-primary bg-opacity-10' : 'bg-light' ?>" id="card-recv-direct">
                                <input class="form-check-input ms-1 me-2" type="radio" name="receiving_mode" id="recv_direct" value="direct_receive" <?= $defaultReceivingMode === 'direct_receive' ? 'checked' : '' ?>>
                                <label class="form-check-label fw-bold text-dark small" for="recv_direct">
                                    Direct Purchase (Instant Stock Fill)
                                    <span class="badge bg-success ms-1" style="font-size:0.68rem;">Instant Inflow</span>
                                </label>
                                <div class="text-muted ms-4" style="font-size:0.75rem;">
                                    Immediately inflows stock into warehouse inventory & auto-posts GRN. No extra dock step needed.
                                </div>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="form-check p-2 border rounded-2 receiving-option <?= $defaultReceivingMode === 'two_step_grn' ? 'border-primary bg-primary bg-opacity-10' : 'bg-light' ?>" id="card-recv-twostep">
                                <input class="form-check-input ms-1 me-2" type="radio" name="receiving_mode" id="recv_twostep" value="two_step_grn" <?= $defaultReceivingMode === 'two_step_grn' ? 'checked' : '' ?>>
                                <label class="form-check-label fw-bold text-dark small" for="recv_twostep">
                                    Two-Step Procurement (PO &rarr; GRN)
                                    <span class="badge bg-secondary ms-1" style="font-size:0.68rem;">Enterprise</span>
                                </label>
                                <div class="text-muted ms-4" style="font-size:0.75rem;">
                                    Saves PO in <em>submitted</em> status. Warehouse dock staff must physically inspect & receive goods via GRN later.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Direct Purchase Payment Settlement -->
            <div class="card border-0 shadow-sm rounded-3 mb-3">
                <div class="card-header bg-white py-3 border-0">
                    <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-cash-coin me-2 text-primary"></i>Purchase Payment Settlement</h6>
                </div>
                <div class="card-body p-3 pt-0">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Payment Status</label>
                        <div class="btn-group w-100" role="group" id="payment-status-group">
                            <input type="radio" class="btn-check" name="payment_status" id="paystat_credit" value="credit" <?= $defaultPaymentMode === 'credit' ? 'checked' : '' ?>>
                            <label class="btn btn-outline-secondary btn-sm" for="paystat_credit">
                                <i class="bi bi-clock-history me-1"></i> Credit / Unpaid
                            </label>

                            <input type="radio" class="btn-check" name="payment_status" id="paystat_full" value="full" <?= $defaultPaymentMode === 'full' ? 'checked' : '' ?>>
                            <label class="btn btn-outline-success btn-sm" for="paystat_full">
                                <i class="bi bi-check-circle me-1"></i> Full Payment
                            </label>

                            <input type="radio" class="btn-check" name="payment_status" id="paystat_partial" value="partial" <?= $defaultPaymentMode === 'partial' ? 'checked' : '' ?>>
                            <label class="btn btn-outline-warning btn-sm" for="paystat_partial">
                                <i class="bi bi-pie-chart me-1"></i> Partial
                            </label>
                        </div>
                    </div>

                    <div id="payment-details-box" style="<?= $defaultPaymentMode === 'credit' ? 'display: none;' : '' ?>">
                        <div class="p-3 bg-light border rounded-3">
                            <div class="row g-2 mb-2">
                                <div class="col-6">
                                    <label class="form-label small fw-semibold">Paid Amount (Rs.) <span class="text-danger">*</span></label>
                                    <input type="number" class="form-control form-control-sm text-end fw-bold text-success" name="paid_amount" id="po-paid-amount" value="0.00" step="0.01">
                                </div>
                                <div class="col-6">
                                    <label class="form-label small fw-semibold">Payment Method</label>
                                    <select class="form-select form-select-sm" name="payment_method_id" id="po-payment-method">
                                        <?php foreach ($paymentMethods as $pm): ?>
                                            <option value="<?= $pm['id'] ?>" data-account="<?= $pm['account_id'] ?>"><?= htmlspecialchars($pm['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="mb-2">
                                <label class="form-label small fw-semibold">Disbursing Account <span class="text-danger">*</span></label>
                                <select class="form-select form-select-sm" name="payment_account_id" id="po-payment-account">
                                    <?php foreach ($paymentAccounts as $acc): ?>
                                        <option value="<?= $acc['id'] ?>" <?= $defaultPaymentAccountId === (int)$acc['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($acc['code']) ?> - <?= htmlspecialchars($acc['name']) ?> (<?= htmlspecialchars($acc['system_tag'] ?: 'Asset') ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="row g-2">
                                <div class="col-6">
                                    <label class="form-label small fw-semibold">Cheque / Txn Ref #</label>
                                    <input type="text" class="form-control form-control-sm" name="payment_ref" placeholder="e.g. CHQ-9901">
                                </div>
                                <div class="col-6">
                                    <label class="form-label small fw-semibold">Settlement Note</label>
                                    <input type="text" class="form-control form-control-sm" name="payment_notes" placeholder="Optional memo">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Purchase Items & Summary -->
        <div class="col-12 col-lg-7">
            <div class="card border-0 shadow-sm rounded-3 mb-3">
                <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                    <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-box-seam me-2 text-primary"></i>Purchase Items</h6>
                    <button type="button" id="btn-add-po-row" class="btn btn-sm btn-primary">
                        <i class="bi bi-plus-lg me-1"></i> Add Product
                    </button>
                </div>
                <div class="card-body p-3">
                    <div class="table-responsive">
                        <table class="table table-bordered table-sm align-middle" id="po-items-table">
                            <thead class="table-light small">
                                <tr>
                                    <th style="min-width: 170px;">Product</th>
                                    <th style="width: 140px;">Packaging Unit</th>
                                    <th style="width: 80px;">Qty</th>
                                    <th style="width: 110px;">Unit Cost</th>
                                    <th style="width: 110px;" class="text-end">Total</th>
                                    <th style="width: 38px;"></th>
                                </tr>
                            </thead>
                            <tbody id="po-items-container">
                                <!-- Dynamic rows via JS -->
                            </tbody>
                        </table>
                    </div>

                    <div class="p-3 bg-light rounded-3 mt-3">
                        <div class="d-flex justify-content-between mb-1">
                            <span class="text-muted small">Items Subtotal:</span>
                            <span class="fw-semibold text-dark" id="po-calc-subtotal">Rs. 0.00</span>
                        </div>
                        <div class="d-flex justify-content-between mb-1">
                            <span class="text-muted small">Freight / Shipping (+):</span>
                            <span class="fw-semibold text-dark" id="po-calc-freight">Rs. 0.00</span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted small">Discount (-):</span>
                            <span class="fw-semibold text-danger" id="po-calc-discount">Rs. 0.00</span>
                        </div>
                        <hr class="my-2">
                        <div class="d-flex justify-content-between mb-1">
                            <span class="fw-bold text-dark fs-6">Grand Total (Net Payable):</span>
                            <span class="fw-bold text-primary fs-5" id="po-calc-grandtotal">Rs. 0.00</span>
                        </div>
                        <div class="d-flex justify-content-between" id="summary-balance-row" style="display: none;">
                            <span class="text-muted small">Unpaid Balance to Supplier AP:</span>
                            <span class="fw-bold text-danger" id="po-calc-balance">Rs. 0.00</span>
                        </div>
                    </div>

                    <div class="mt-3">
                        <button type="submit" id="btn-submit-purchase" class="btn btn-primary w-100 btn-touch shadow-sm py-2 fw-semibold">
                            <i class="bi bi-box-arrow-in-down me-1"></i> Complete Direct Purchase (Stock In)
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
const prodsList = <?= json_encode($products, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const allUnits = <?= json_encode($allUnits, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const allConversions = <?= json_encode($allConversions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

function getUnitMultiplier(fromUnitId, toUnitId) {
    fromUnitId = parseInt(fromUnitId);
    toUnitId = parseInt(toUnitId);
    if (!fromUnitId || !toUnitId || fromUnitId === toUnitId) return 1.0;

    // Direct rule
    let direct = allConversions.find(c => parseInt(c.from_unit_id) === fromUnitId && parseInt(c.to_unit_id) === toUnitId);
    if (direct) return parseFloat(direct.multiplier);

    // Reverse rule
    let rev = allConversions.find(c => parseInt(c.from_unit_id) === toUnitId && parseInt(c.to_unit_id) === fromUnitId);
    if (rev && parseFloat(rev.multiplier) > 0) return 1.0 / parseFloat(rev.multiplier);

    // 2-step chain (e.g. Carton -> Box -> Piece)
    let step1 = allConversions.filter(c => parseInt(c.from_unit_id) === fromUnitId);
    for (let s1 of step1) {
        let step2 = allConversions.find(c => parseInt(c.from_unit_id) === parseInt(s1.to_unit_id) && parseInt(c.to_unit_id) === toUnitId);
        if (step2) {
            return parseFloat(s1.multiplier) * parseFloat(step2.multiplier);
        }
    }
    return 1.0;
}

function addPoRow(defaultId = null, defaultUnitId = null) {
    const container = document.getElementById('po-items-container');
    const tr = document.createElement('tr');
    tr.className = 'po-row';

    let options = '<option value="">-- Choose Product --</option>';
    prodsList.forEach(p => {
        const isSel = (defaultId && p.id == defaultId) ? 'selected' : '';
        options += `<option value="${p.id}" data-cost="${p.purchase_price}" data-unit="${p.base_unit_id}" ${isSel}>${p.name}</option>`;
    });

    tr.innerHTML = `
        <td>
            <select class="form-select form-select-sm po-prod-sel" name="prod_id[]" required>${options}</select>
        </td>
        <td>
            <select class="form-select form-select-sm po-unit-sel" name="unit_id[]" required>
                <option value="">-- Unit --</option>
            </select>
        </td>
        <td>
            <input type="number" class="form-control form-control-sm po-qty text-center" name="quantity[]" value="1" min="1" step="1" required>
        </td>
        <td>
            <input type="number" class="form-control form-control-sm po-cost text-end" name="unit_cost[]" value="0.00" step="0.01" required>
        </td>
        <td class="text-end fw-bold po-line-tot">Rs. 0.00</td>
        <td class="text-center">
            <button type="button" class="btn btn-sm btn-outline-danger p-1 po-del-btn" title="Remove"><i class="bi bi-trash"></i></button>
        </td>
    `;

    container.appendChild(tr);

    const sel = tr.querySelector('.po-prod-sel');
    const unitSel = tr.querySelector('.po-unit-sel');
    const qty = tr.querySelector('.po-qty');
    const cost = tr.querySelector('.po-cost');
    const del = tr.querySelector('.po-del-btn');

    function populateRowUnits(productId, preselectedUnitId = null) {
        unitSel.innerHTML = '';
        const prod = prodsList.find(p => p.id == productId);
        if (!prod) {
            unitSel.innerHTML = '<option value="">-- Unit --</option>';
            return;
        }

        const baseUnit = allUnits.find(u => u.id == prod.base_unit_id);
        if (baseUnit) {
            const isSel = (!preselectedUnitId || preselectedUnitId == baseUnit.id) ? 'selected' : '';
            unitSel.innerHTML += `<option value="${baseUnit.id}" data-multiplier="1" ${isSel}>${baseUnit.name} (${baseUnit.code})</option>`;
        }

        allUnits.forEach(u => {
            if (!baseUnit || u.id != baseUnit.id) {
                const mult = getUnitMultiplier(u.id, prod.base_unit_id);
                let label = `${u.name} (${u.code})`;
                if (mult > 1) {
                    label += ` (x${mult} ${baseUnit ? baseUnit.code : ''})`;
                }
                const isSel = (preselectedUnitId && preselectedUnitId == u.id) ? 'selected' : '';
                unitSel.innerHTML += `<option value="${u.id}" data-multiplier="${mult}" ${isSel}>${label}</option>`;
            }
        });
    }

    function updateUnitCost() {
        const prod = prodsList.find(p => p.id == sel.value);
        if (!prod) {
            cost.value = '0.00';
            return;
        }
        const unitOpt = unitSel.options[unitSel.selectedIndex];
        const mult = unitOpt ? parseFloat(unitOpt.getAttribute('data-multiplier') || 1) : 1;
        const baseCost = parseFloat(prod.purchase_price || 0);
        cost.value = (baseCost * mult).toFixed(2);
    }

    sel.addEventListener('change', function() {
        if (this.value) {
            populateRowUnits(this.value);
            updateUnitCost();
        } else {
            unitSel.innerHTML = '<option value="">-- Unit --</option>';
            cost.value = '0.00';
        }
        recalc();
    });

    unitSel.addEventListener('change', function() {
        updateUnitCost();
        recalc();
    });

    qty.addEventListener('input', recalc);
    cost.addEventListener('input', recalc);
    del.addEventListener('click', function() {
        if (document.querySelectorAll('.po-row').length > 1) {
            tr.remove();
            recalc();
        } else {
            sel.value = '';
            unitSel.innerHTML = '<option value="">-- Unit --</option>';
            qty.value = '1';
            cost.value = '0.00';
            recalc();
        }
    });

    if (defaultId) {
        sel.value = defaultId;
        populateRowUnits(defaultId, defaultUnitId);
        updateUnitCost();
        recalc();
    }
}

function recalc() {
    let tot = 0;
    document.querySelectorAll('.po-row').forEach(r => {
        const q = parseFloat(r.querySelector('.po-qty').value || 0);
        const c = parseFloat(r.querySelector('.po-cost').value || 0);
        const line = q * c;
        r.querySelector('.po-line-tot').innerText = 'Rs. ' + line.toLocaleString('en-US', {minimumFractionDigits: 2});
        tot += line;
    });

    const freight = parseFloat(document.getElementById('po-freight').value || 0);
    const discount = parseFloat(document.getElementById('po-discount').value || 0);
    const grandTotal = Math.max(0, tot + freight - discount);

    document.getElementById('po-calc-subtotal').innerText = 'Rs. ' + tot.toLocaleString('en-US', {minimumFractionDigits: 2});
    document.getElementById('po-calc-freight').innerText = 'Rs. ' + freight.toLocaleString('en-US', {minimumFractionDigits: 2});
    document.getElementById('po-calc-discount').innerText = 'Rs. ' + discount.toLocaleString('en-US', {minimumFractionDigits: 2});
    document.getElementById('po-calc-grandtotal').innerText = 'Rs. ' + grandTotal.toLocaleString('en-US', {minimumFractionDigits: 2});

    const payStatus = document.querySelector('input[name="payment_status"]:checked')?.value || 'credit';
    const paidInput = document.getElementById('po-paid-amount');
    
    if (payStatus === 'full') {
        paidInput.value = grandTotal.toFixed(2);
    } else if (payStatus === 'credit') {
        paidInput.value = '0.00';
    }

    const currentPaid = parseFloat(paidInput.value || 0);
    const balance = Math.max(0, grandTotal - currentPaid);
    const balRow = document.getElementById('summary-balance-row');
    
    if (payStatus === 'credit' || balance > 0) {
        balRow.style.display = 'flex';
        document.getElementById('po-calc-balance').innerText = 'Rs. ' + balance.toLocaleString('en-US', {minimumFractionDigits: 2});
    } else {
        balRow.style.display = 'none';
    }

    updateSubmitButton();
}

function updateSubmitButton() {
    const recvMode = document.querySelector('input[name="receiving_mode"]:checked')?.value || 'direct_receive';
    const payStatus = document.querySelector('input[name="payment_status"]:checked')?.value || 'credit';
    const btn = document.getElementById('btn-submit-purchase');

    if (recvMode === 'direct_receive') {
        if (payStatus === 'full') {
            btn.innerHTML = '<i class="bi bi-lightning-charge-fill me-1"></i> Receive Stock & Settle Full Payment';
            btn.className = 'btn btn-success w-100 btn-touch shadow-sm py-2 fw-semibold';
        } else if (payStatus === 'partial') {
            btn.innerHTML = '<i class="bi bi-box-arrow-in-down me-1"></i> Receive Stock & Disburse Partial Payment';
            btn.className = 'btn btn-primary w-100 btn-touch shadow-sm py-2 fw-semibold';
        } else {
            btn.innerHTML = '<i class="bi bi-box-arrow-in-down me-1"></i> Complete Direct Purchase (Stock In)';
            btn.className = 'btn btn-primary w-100 btn-touch shadow-sm py-2 fw-semibold';
        }
    } else {
        if (payStatus === 'full' || payStatus === 'partial') {
            btn.innerHTML = '<i class="bi bi-file-earmark-check me-1"></i> Save PO with Advance Payment';
            btn.className = 'btn btn-primary w-100 btn-touch shadow-sm py-2 fw-semibold';
        } else {
            btn.innerHTML = '<i class="bi bi-file-earmark-check me-1"></i> Save Purchase Order (PO)';
            btn.className = 'btn btn-primary w-100 btn-touch shadow-sm py-2 fw-semibold';
        }
    }
}

// Receiving Mode Radio Visual Toggle
document.querySelectorAll('input[name="receiving_mode"]').forEach(radio => {
    radio.addEventListener('change', function() {
        const directCard = document.getElementById('card-recv-direct');
        const twoStepCard = document.getElementById('card-recv-twostep');
        if (this.value === 'direct_receive') {
            directCard.className = 'form-check p-2 border rounded-2 receiving-option border-primary bg-primary bg-opacity-10';
            twoStepCard.className = 'form-check p-2 border rounded-2 receiving-option bg-light';
        } else {
            directCard.className = 'form-check p-2 border rounded-2 receiving-option bg-light';
            twoStepCard.className = 'form-check p-2 border rounded-2 receiving-option border-primary bg-primary bg-opacity-10';
        }
        updateSubmitButton();
    });
});

// Payment Status Radio Handlers
document.querySelectorAll('input[name="payment_status"]').forEach(radio => {
    radio.addEventListener('change', function() {
        const box = document.getElementById('payment-details-box');
        const paidInput = document.getElementById('po-paid-amount');
        if (this.value === 'credit') {
            box.style.display = 'none';
            paidInput.value = '0.00';
            paidInput.readOnly = false;
        } else if (this.value === 'full') {
            box.style.display = 'block';
            paidInput.readOnly = true;
        } else if (this.value === 'partial') {
            box.style.display = 'block';
            paidInput.readOnly = false;
            paidInput.focus();
        }
        recalc();
    });
});

// Auto-select payment account based on payment method
document.getElementById('po-payment-method')?.addEventListener('change', function() {
    const opt = this.options[this.selectedIndex];
    const accId = opt.getAttribute('data-account');
    if (accId) {
        const accSelect = document.getElementById('po-payment-account');
        if (accSelect) accSelect.value = accId;
    }
});

// Paid amount input event
document.getElementById('po-paid-amount')?.addEventListener('input', function() {
    const payStatus = document.querySelector('input[name="payment_status"]:checked')?.value;
    if (payStatus !== 'full') {
        const grandTot = parseFloat(document.getElementById('po-calc-grandtotal').innerText.replace(/[^\d.]/g, '') || 0);
        const paid = parseFloat(this.value || 0);
        const bal = Math.max(0, grandTot - paid);
        const balRow = document.getElementById('summary-balance-row');
        if (bal > 0 || payStatus === 'credit') {
            balRow.style.display = 'flex';
            document.getElementById('po-calc-balance').innerText = 'Rs. ' + bal.toLocaleString('en-US', {minimumFractionDigits: 2});
        } else {
            balRow.style.display = 'none';
        }
    }
});

document.getElementById('po-freight')?.addEventListener('input', recalc);
document.getElementById('po-discount')?.addEventListener('input', recalc);
document.getElementById('btn-add-po-row').addEventListener('click', () => addPoRow());

document.addEventListener('DOMContentLoaded', function() {
    addPoRow();
    recalc();
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
