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

// Handle POST Submission BEFORE HTML output
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $supplierId = (int)($_POST['supplier_id'] ?? 0);
    $warehouseId = (int)($_POST['warehouse_id'] ?? 1);
    $poDate = $_POST['po_date'] ?? date('Y-m-d');
    $expectedDate = !empty($_POST['expected_date']) ? $_POST['expected_date'] : null;
    $referenceNo = sanitize($_POST['reference_no'] ?? '');
    $notes = sanitize($_POST['notes'] ?? '');

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

        $poStmt = $pdo->prepare("
            INSERT INTO `purchase_orders` (
                `po_number`, `supplier_id`, `warehouse_id`, `po_date`, 
                `expected_date`, `reference_no`, `subtotal`, `discount`, 
                `freight`, `other_charges`, `grand_total`, `status`, `notes`, 
                `created_by`, `created_at`
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'submitted', ?, ?, NOW())
        ");
        $poStmt->execute([
            $poNumber, $supplierId, $warehouseId, $poDate,
            $expectedDate, $referenceNo, $subtotal, $discount,
            $freight, $otherCharges, $grandTotal, $notes,
            $currentUser['id']
        ]);
        $poId = (int)$pdo->lastInsertId();

        $itemStmt = $pdo->prepare("
            INSERT INTO `purchase_items` (`purchase_order_id`, `product_id`, `unit_id`, `order_qty`, `unit_cost`, `total_cost`)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        foreach ($items as $it) {
            $itemStmt->execute([$poId, $it['product_id'], $it['unit_id'], $it['quantity'], $it['unit_cost'], $it['line_total']]);
        }

        logAudit($pdo, 'purchases', $poId, 'create_po', null, ['po_number' => $poNumber, 'total' => $grandTotal]);
        $pdo->commit();

        setFlashMessage('success', "Purchase Order {$poNumber} created successfully! Proceed with Goods Receiving when supplier delivers.");
        redirect(BASE_URL . '/modules/purchases/index.php');

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        setFlashMessage('error', 'Error creating PO: ' . $e->getMessage());
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
        <!-- Supplier & Delivery Details -->
        <div class="col-12 col-md-4">
            <div class="card border-0 shadow-sm rounded-3 mb-3">
                <div class="card-header bg-white py-3 border-0">
                    <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-building me-2 text-primary"></i>Supplier Information</h6>
                </div>
                <div class="card-body p-3">
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
                            <input type="number" class="form-control form-control-sm" name="freight" value="0.00" step="0.01">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Discount (Rs.)</label>
                            <input type="number" class="form-control form-control-sm" name="discount" value="0.00" step="0.01">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">PO Notes</label>
                        <textarea class="form-control form-control-sm" name="notes" rows="2" placeholder="e.g. Terms Net 30"></textarea>
                    </div>
                </div>
            </div>
        </div>

        <!-- Order Items -->
        <div class="col-12 col-md-8">
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
                                    <th>Product</th>
                                    <th style="width: 100px;">Qty</th>
                                    <th style="width: 120px;">Unit Cost</th>
                                    <th style="width: 120px;" class="text-end">Total</th>
                                    <th style="width: 40px;"></th>
                                </tr>
                            </thead>
                            <tbody id="po-items-container">
                                <!-- Dynamic rows via JS -->
                            </tbody>
                        </table>
                    </div>

                    <div class="p-3 bg-light rounded-3 mt-3">
                        <div class="d-flex justify-content-between">
                            <span class="fw-bold text-dark">Estimated Subtotal:</span>
                            <span class="fw-bold text-primary fs-5" id="po-calc-subtotal">Rs. 0.00</span>
                        </div>
                    </div>

                    <div class="mt-3">
                        <button type="submit" class="btn btn-primary w-100 btn-touch shadow-sm py-2">
                            <i class="bi bi-check2-circle me-1"></i> Save Purchase Order
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
const prodsList = <?= json_encode($products) ?>;

function addPoRow(defaultId = null) {
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
            <input type="hidden" name="unit_id[]" class="po-unit-id" value="1">
        </td>
        <td>
            <input type="number" class="form-control form-control-sm po-qty text-center" name="quantity[]" value="100" min="1" step="1" required>
        </td>
        <td>
            <input type="number" class="form-control form-control-sm po-cost text-end" name="unit_cost[]" value="0.00" step="0.01" required>
        </td>
        <td class="text-end fw-bold po-line-tot">Rs. 0.00</td>
        <td class="text-center">
            <button type="button" class="btn btn-sm btn-outline-danger p-1 po-del-btn"><i class="bi bi-trash"></i></button>
        </td>
    `;

    container.appendChild(tr);

    const sel = tr.querySelector('.po-prod-sel');
    const qty = tr.querySelector('.po-qty');
    const cost = tr.querySelector('.po-cost');
    const del = tr.querySelector('.po-del-btn');

    sel.addEventListener('change', function() {
        const opt = this.options[this.selectedIndex];
        if (this.value) {
            cost.value = parseFloat(opt.getAttribute('data-cost') || 0).toFixed(2);
            tr.querySelector('.po-unit-id').value = opt.getAttribute('data-unit') || 1;
        }
        recalc();
    });

    qty.addEventListener('input', recalc);
    cost.addEventListener('input', recalc);
    del.addEventListener('click', function() { tr.remove(); recalc(); });

    if (defaultId) sel.dispatchEvent(new Event('change'));
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
    document.getElementById('po-calc-subtotal').innerText = 'Rs. ' + tot.toLocaleString('en-US', {minimumFractionDigits: 2});
}

document.getElementById('btn-add-po-row').addEventListener('click', () => addPoRow());
document.addEventListener('DOMContentLoaded', function() {
    addPoRow(1);
    addPoRow(2);
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
