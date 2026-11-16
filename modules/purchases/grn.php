<?php
/**
 * Goods Received Note (GRN) - Actual Inventory Receiving & Posting
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('purchases', 'receive');

$poId = (int)($_GET['po_id'] ?? 0);
if (!$poId) {
    setFlashMessage('error', 'Invalid Purchase Order specified.');
    redirect(BASE_URL . '/modules/purchases/index.php');
}

$pdo = getDatabaseConnection();
$currentUser = getCurrentUser();

// Fetch PO details
$stmt = $pdo->prepare("
    SELECT po.*, s.name as supplier_name, s.company_name, w.name as warehouse_name
    FROM `purchase_orders` po
    JOIN `suppliers` s ON po.supplier_id = s.id
    JOIN `warehouses` w ON po.warehouse_id = w.id
    WHERE po.id = ? AND po.status != 'received'
");
$stmt->execute([$poId]);
$po = $stmt->fetch();

if (!$po) {
    setFlashMessage('error', 'Purchase order not found or already fully received.');
    redirect(BASE_URL . '/modules/purchases/index.php');
}

// Fetch PO Items
$itemsStmt = $pdo->prepare("
    SELECT pi.*, p.name as product_name, p.code as product_code, p.is_batch_managed, p.base_unit_id, u.name as unit_name
    FROM `purchase_items` pi
    JOIN `products` p ON pi.product_id = p.id
    JOIN `units` u ON pi.unit_id = u.id
    WHERE pi.purchase_order_id = ?
");
$itemsStmt->execute([$poId]);
$items = $itemsStmt->fetchAll();

// Handle POST GRN Receiving BEFORE HTML output
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $grnDate = $_POST['grn_date'] ?? date('Y-m-d');
    $invoiceNo = sanitize($_POST['invoice_no'] ?? '');
    $invoiceDate = !empty($_POST['invoice_date']) ? $_POST['invoice_date'] : $grnDate;
    $freightCost = (float)($_POST['freight_cost'] ?? 0.00);
    $notes = sanitize($_POST['notes'] ?? '');

    $receivedQuantities = $_POST['received_qty'] ?? [];
    $unitCosts = $_POST['unit_cost'] ?? [];
    $batchNumbers = $_POST['batch_number'] ?? [];
    $expiryDates = $_POST['expiry_date'] ?? [];

    try {
        $pdo->beginTransaction();

        $grnNumber = generateDocNumber($pdo, 'goods_receipts', 'grn_number', 'GRN-');
        $totalReceivedCost = 0.00;
        $allFulfilled = true;

        $grnStmt = $pdo->prepare("
            INSERT INTO `goods_receipts` (
                `grn_number`, `purchase_order_id`, `supplier_id`, `warehouse_id`, 
                `grn_date`, `invoice_no`, `invoice_date`, `total_amount`, 
                `freight_cost`, `landed_cost`, `notes`, `posted_to_accounts`, 
                `created_by`, `created_at`
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 0.00, ?, 0.00, ?, 1, ?, NOW())
        ");
        $grnStmt->execute([
            $grnNumber, $poId, $po['supplier_id'], $po['warehouse_id'],
            $grnDate, $invoiceNo, $invoiceDate, $freightCost, $notes,
            $currentUser['id']
        ]);
        $grnId = (int)$pdo->lastInsertId();

        $griStmt = $pdo->prepare("
            INSERT INTO `goods_receipt_items` (
                `goods_receipt_id`, `product_id`, `batch_number`, `expiry_date`, 
                `unit_id`, `received_qty`, `unit_cost`, `total_cost`
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $piUpdate = $pdo->prepare("UPDATE `purchase_items` SET `received_qty` = `received_qty` + ? WHERE id = ?");

        foreach ($items as $it) {
            $itemId = $it['id'];
            $pId = $it['product_id'];
            $uId = $it['unit_id'];
            $recQty = (float)($receivedQuantities[$itemId] ?? 0.00);
            $uCost = (float)($unitCosts[$itemId] ?? $it['unit_cost']);
            $batchNo = sanitize($batchNumbers[$itemId] ?? '');
            $expDate = !empty($expiryDates[$itemId]) ? $expiryDates[$itemId] : null;

            if ($recQty > 0) {
                $lineTotal = round($recQty * $uCost, 2);
                $totalReceivedCost += $lineTotal;

                $griStmt->execute([$grnId, $pId, $batchNo, $expDate, $uId, $recQty, $uCost, $lineTotal]);
                $piUpdate->execute([$recQty, $itemId]);

                // Create or update batch if specified
                $batchId = null;
                if (!empty($batchNo)) {
                    $bStmt = $pdo->prepare("
                        INSERT INTO `product_batches` (`product_id`, `warehouse_id`, `batch_number`, `expiry_date`, `quantity`, `unit_cost`)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $bStmt->execute([$pId, $po['warehouse_id'], $batchNo, $expDate, $recQty, $uCost]);
                    $batchId = (int)$pdo->lastInsertId();
                }

                // 1. Critical Inventory Rule: Convert packaging unit to base physical unit & record inflow
                $baseUnitId = (int)($it['base_unit_id'] ?: $uId);
                $physicalQtyIn = convertUnitQuantity($pdo, $pId, $uId, $baseUnitId, $recQty);
                $baseUnitCost = ($physicalQtyIn > 0) ? round($lineTotal / $physicalQtyIn, 4) : $uCost;

                recordStockMovement(
                    $pdo,
                    $po['warehouse_id'],
                    $pId,
                    'purchase_grn',
                    'goods_receipt',
                    $grnId,
                    $baseUnitId,
                    $physicalQtyIn, // Physical Base Quantity In
                    0.00,
                    $baseUnitCost,
                    $batchId,
                    "Received under GRN {$grnNumber} against PO {$po['po_number']}"
                );
            }

            if ($recQty < ($it['order_qty'] - $it['received_qty'])) {
                $allFulfilled = false;
            }
        }

        $landedCost = $totalReceivedCost + $freightCost;

        // Update GRN totals
        $pdo->prepare("UPDATE `goods_receipts` SET `total_amount` = ?, `landed_cost` = ? WHERE id = ?")->execute([$totalReceivedCost, $landedCost, $grnId]);

        // Update PO Status
        $newPoStatus = $allFulfilled ? 'received' : 'partially_received';
        $pdo->prepare("UPDATE `purchase_orders` SET `status` = ? WHERE id = ?")->execute([$newPoStatus, $poId]);

        // 2. Critical Double-Entry Accounting Rule:
        // Debit: Merchandise Inventory
        // Credit: Accounts Payable (Supplier)
        postGoodsReceiptAccounting($pdo, $grnId, $grnNumber, $po['supplier_id'], $totalReceivedCost, $currentUser['id']);

        logAudit($pdo, 'purchases', $grnId, 'receive_grn', null, [
            'grn_number' => $grnNumber,
            'total_amount' => $totalReceivedCost,
            'supplier_id' => $po['supplier_id']
        ]);

        $pdo->commit();

        setFlashMessage('success', "Goods Received Note {$grnNumber} posted! Stock increased in warehouse and Rs. " . number_format($totalReceivedCost, 2) . " credited to supplier ledger.");
        redirect(BASE_URL . '/modules/purchases/index.php');

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        setFlashMessage('error', 'GRN processing error: ' . $e->getMessage());
        redirect(BASE_URL . "/modules/purchases/grn.php?po_id={$poId}");
    }
}

$pageTitle = 'Receive Goods (GRN) - ' . $po['po_number'];
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="fw-bold mb-0 text-dark"><i class="bi bi-box-arrow-in-down text-success me-2"></i>Goods Received Note (GRN)</h4>
        <small class="text-muted">PO: <span class="fw-bold text-dark"><?= htmlspecialchars($po['po_number']) ?></span> · Supplier: <?= htmlspecialchars($po['supplier_name']) ?></small>
    </div>
    <a href="<?= BASE_URL ?>/modules/purchases/index.php" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i> Back
    </a>
</div>

<form method="POST" action="<?= BASE_URL ?>/modules/purchases/grn.php?po_id=<?= $poId ?>">
    <?= getCsrfField() ?>

    <div class="row g-3">
        <!-- Receiving Information Card -->
        <div class="col-12 col-md-4">
            <div class="card border-0 shadow-sm rounded-3 mb-3">
                <div class="card-header bg-white py-3 border-0">
                    <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-receipt me-2 text-primary"></i>Receiving Voucher Details</h6>
                </div>
                <div class="card-body p-3">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Receiving Date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control form-control-sm" name="grn_date" value="<?= date('Y-m-d') ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Supplier Invoice / Bilty #</label>
                        <input type="text" class="form-control form-control-sm" name="invoice_no" placeholder="e.g. SINV-8871" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Supplier Invoice Date</label>
                        <input type="date" class="form-control form-control-sm" name="invoice_date" value="<?= date('Y-m-d') ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Freight / Transportation Paid (Rs.)</label>
                        <input type="number" class="form-control form-control-sm" name="freight_cost" value="0.00" step="0.01">
                        <small class="text-muted" style="font-size: 0.72rem;">Added to product landed cost valuation.</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Receiving Inspection Remarks</label>
                        <textarea class="form-control form-control-sm" name="notes" rows="2" placeholder="e.g. Inspected packages, seals intact"></textarea>
                    </div>
                </div>
            </div>
        </div>

        <!-- Line Items Verification -->
        <div class="col-12 col-md-8">
            <div class="card border-0 shadow-sm rounded-3 mb-3">
                <div class="card-header bg-white py-3 border-0">
                    <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-check2-circle me-2 text-primary"></i>Verify Received Quantities</h6>
                    <small class="text-muted">Stock will be permanently added to warehouse inventory based on verified amounts.</small>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-bordered table-responsive-card align-middle mb-0">
                            <thead class="table-light small">
                                <tr>
                                    <th>Product Details</th>
                                    <th class="text-center">Ordered</th>
                                    <th class="text-center" style="width: 110px;">Received Qty</th>
                                    <th class="text-end" style="width: 110px;">Cost Rate</th>
                                    <th style="width: 120px;">Batch / Expiry</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $item): ?>
                                    <?php $remaining = max(0, $item['order_qty'] - $item['received_qty']); ?>
                                    <tr>
                                        <td data-label="Product">
                                            <div class="fw-bold text-dark"><?= htmlspecialchars($item['product_name']) ?></div>
                                            <small class="text-muted"><?= htmlspecialchars($item['product_code']) ?></small>
                                        </td>
                                        <td data-label="Ordered" class="text-center">
                                            <span class="badge bg-light text-dark border"><?= (float)$item['order_qty'] ?> <?= htmlspecialchars($item['unit_name']) ?></span>
                                            <?php if ($item['received_qty'] > 0): ?>
                                                <small class="d-block text-muted" style="font-size: 0.65rem;">Prev: <?= (float)$item['received_qty'] ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Received" class="text-center">
                                            <input type="number" 
                                                   class="form-control form-control-sm text-center fw-bold text-success" 
                                                   name="received_qty[<?= $item['id'] ?>]" 
                                                   value="<?= $remaining ?>" 
                                                   min="0" 
                                                   max="<?= $remaining ?>" 
                                                   step="1" 
                                                   required>
                                        </td>
                                        <td data-label="Cost" class="text-end">
                                            <input type="number" 
                                                   class="form-control form-control-sm text-end" 
                                                   name="unit_cost[<?= $item['id'] ?>]" 
                                                   value="<?= (float)$item['unit_cost'] ?>" 
                                                   step="0.01" 
                                                   required>
                                        </td>
                                        <td data-label="Batch">
                                            <input type="text" class="form-control form-control-sm mb-1" name="batch_number[<?= $item['id'] ?>]" placeholder="Batch #" value="BAT-<?= date('y') ?>-<?= $item['product_id'] ?>">
                                            <input type="date" class="form-control form-control-sm" name="expiry_date[<?= $item['id'] ?>]" value="<?= date('Y-m-d', strtotime('+1 year')) ?>">
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="p-3 bg-light border-top">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <small class="text-muted d-block">Automatic Accounting Action:</small>
                                <span class="badge bg-success">Auto-Debit Inventory</span>
                                <span class="badge bg-danger">Auto-Credit Supplier Payable</span>
                            </div>
                            <button type="submit" class="btn btn-success btn-touch shadow-sm">
                                <i class="bi bi-box-arrow-in-down me-1"></i> Post GRN & Update Stock
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
