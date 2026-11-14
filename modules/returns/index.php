<?php
/**
 * Customer Sales Returns & Credit Notes
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('returns', 'view');

$pdo = getDatabaseConnection();
$currentUser = getCurrentUser();

// Handle Create Sales Return POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_return'])) {
    requirePermission('returns', 'create');

    $customerId = (int)($_POST['customer_id'] ?? 0);
    $warehouseId = (int)($_POST['warehouse_id'] ?? 1);
    $returnDate = $_POST['return_date'] ?? date('Y-m-d');
    $destination = sanitize($_POST['return_destination'] ?? 'good_stock');
    $reason = sanitize($_POST['reason'] ?? 'Damaged / Expiry Return');

    $productIds = $_POST['prod_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $prices = $_POST['unit_price'] ?? [];

    if (!$customerId || empty($productIds)) {
        setFlashMessage('error', 'Please choose customer and add return products.');
        redirect(BASE_URL . '/modules/returns/index.php');
    }

    try {
        $pdo->beginTransaction();

        $returnNumber = generateDocNumber($pdo, 'sales_returns', 'return_number', 'SR-');
        $totalReturnAmount = 0.00;
        $totalInventoryCost = 0.00;
        $returnItems = [];

        for ($i = 0; $i < count($productIds); $i++) {
            $pId = (int)$productIds[$i];
            $qty = (float)($quantities[$i] ?? 0);
            $price = (float)($prices[$i] ?? 0);

            if ($pId > 0 && $qty > 0) {
                // Fetch product cost
                $pCostStmt = $pdo->prepare("SELECT purchase_price, base_unit_id FROM `products` WHERE id = ?");
                $pCostStmt->execute([$pId]);
                $pInfo = $pCostStmt->fetch();

                $cost = (float)($pInfo['purchase_price'] ?? 0);
                $uId = (int)($pInfo['base_unit_id'] ?? 1);

                $lineTotal = round($qty * $price, 2);
                $totalReturnAmount += $lineTotal;
                $totalInventoryCost += round($qty * $cost, 2);

                $returnItems[] = [
                    'product_id' => $pId,
                    'unit_id' => $uId,
                    'quantity' => $qty,
                    'unit_price' => $price,
                    'unit_cost' => $cost,
                    'total_amount' => $lineTotal
                ];
            }
        }

        if (empty($returnItems)) {
            throw new Exception("Please specify valid return quantities.");
        }

        $srStmt = $pdo->prepare("
            INSERT INTO `sales_returns` (
                `return_number`, `customer_id`, `warehouse_id`, `return_date`, 
                `total_amount`, `return_destination`, `status`, `reason`, 
                `created_by`, `created_at`
            ) VALUES (?, ?, ?, ?, ?, ?, 'approved', ?, ?, NOW())
        ");
        $srStmt->execute([
            $returnNumber, $customerId, $warehouseId, $returnDate,
            $totalReturnAmount, $destination, $reason, $currentUser['id']
        ]);
        $returnId = (int)$pdo->lastInsertId();

        $sriStmt = $pdo->prepare("
            INSERT INTO `sales_return_items` (`sales_return_id`, `product_id`, `unit_id`, `quantity`, `unit_price`, `unit_cost`, `total_amount`)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($returnItems as $ri) {
            $sriStmt->execute([$returnId, $ri['product_id'], $ri['unit_id'], $ri['quantity'], $ri['unit_price'], $ri['unit_cost'], $ri['total_amount']]);

            // If stock destination is good_stock, restock inventory immediately
            if ($destination === 'good_stock') {
                recordStockMovement(
                    $pdo,
                    $warehouseId,
                    $ri['product_id'],
                    'sale_return',
                    'sales_return',
                    $returnId,
                    $ri['unit_id'],
                    $ri['quantity'], // Quantity In
                    0.00,
                    $ri['unit_cost'],
                    null,
                    "Sales return {$returnNumber} good stock restock"
                );
            }
        }

        // Generate Credit Note
        $cnNumber = generateDocNumber($pdo, 'credit_notes', 'credit_note_number', 'CN-');
        $pdo->prepare("
            INSERT INTO `credit_notes` (`credit_note_number`, `sales_return_id`, `customer_id`, `amount`, `note_date`, `status`, `created_at`)
            VALUES (?, ?, ?, ?, ?, 'active', NOW())
        ")->execute([$cnNumber, $returnId, $customerId, $totalReturnAmount, $returnDate]);

        // Post Double-Entry Accounting
        postSalesReturnAccounting($pdo, $returnId, $returnNumber, $customerId, $totalReturnAmount, $totalInventoryCost, $destination, $currentUser['id']);

        logAudit($pdo, 'returns', $returnId, 'create_return', null, ['return_number' => $returnNumber, 'amount' => $totalReturnAmount]);
        $pdo->commit();

        setFlashMessage('success', "Sales return {$returnNumber} approved. Credit Note {$cnNumber} (Rs. " . number_format($totalReturnAmount, 2) . ") credited to customer balance.");
        redirect(BASE_URL . '/modules/returns/index.php');

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        setFlashMessage('error', 'Sales return error: ' . $e->getMessage());
        redirect(BASE_URL . '/modules/returns/index.php');
    }
}

// Fetch sales returns
$returns = $pdo->query("
    SELECT sr.*, c.shop_name, c.owner_name, w.name as warehouse_name
    FROM `sales_returns` sr
    JOIN `customers` c ON sr.customer_id = c.id
    JOIN `warehouses` w ON sr.warehouse_id = w.id
    ORDER BY sr.id DESC LIMIT 50
")->fetchAll();

$customers = $pdo->query("SELECT id, shop_name, owner_name FROM `customers` WHERE status = 'active' ORDER BY shop_name ASC")->fetchAll();
$products = $pdo->query("SELECT id, name, trade_price FROM `products` WHERE status = 'active' ORDER BY name ASC")->fetchAll();
$warehouses = $pdo->query("SELECT id, name FROM `warehouses` WHERE status = 'active'")->fetchAll();

$pageTitle = 'Sales Returns & Credit Notes';
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-3 gap-2">
    <div>
        <h4 class="fw-bold mb-0 text-dark"><i class="bi bi-arrow-return-left text-danger me-2"></i>Sales Returns & Credit Notes</h4>
        <small class="text-muted">Customer Return Management, Damaged Quarantine & Account Credits</small>
    </div>
    <div>
        <button type="button" class="btn btn-primary btn-touch shadow-sm" data-confirm-trigger data-confirm-target="panel-new-return">
            <i class="bi bi-plus-circle me-1"></i> New Sales Return
        </button>
    </div>
</div>

<!-- Inline New Sales Return Panel (Strict No-Modal UI) -->
<div id="panel-new-return" class="card border-0 shadow-sm rounded-3 mb-3 d-none">
    <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
        <h6 class="fw-bold mb-0 text-danger"><i class="bi bi-arrow-return-left me-1"></i>Process Sales Return & Credit Note</h6>
        <button type="button" class="btn btn-sm btn-close" data-confirm-cancel data-confirm-target="panel-new-return"></button>
    </div>
    <div class="card-body p-3">
        <form method="POST" action="<?= BASE_URL ?>/modules/returns/index.php">
            <?= getCsrfField() ?>
            <input type="hidden" name="create_return" value="1">
            <div class="row g-2 mb-3">
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">Customer Shop <span class="text-danger">*</span></label>
                    <select class="form-select form-select-sm" name="customer_id" required>
                        <option value="">-- Choose Customer --</option>
                        <?php foreach ($customers as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['shop_name']) ?> (<?= htmlspecialchars($c['owner_name']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Warehouse Receiving Stock</label>
                    <select class="form-select form-select-sm" name="warehouse_id">
                        <?php foreach ($warehouses as $w): ?>
                            <option value="<?= $w['id'] ?>"><?= htmlspecialchars($w['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Return Destination <span class="text-danger">*</span></label>
                    <select class="form-select form-select-sm" name="return_destination">
                        <option value="good_stock">Good Stock (Restock into sellable inventory)</option>
                        <option value="damaged_stock">Damaged Stock (Quarantine / Write-off)</option>
                        <option value="expired_stock">Expired Stock (Disposal)</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold">Return Date</label>
                    <input type="date" class="form-control form-control-sm" name="return_date" value="<?= date('Y-m-d') ?>">
                </div>
            </div>

            <!-- Return Item Row -->
            <div class="row g-2 align-items-end mb-3">
                <div class="col-md-5">
                    <label class="form-label small fw-semibold">Product Being Returned</label>
                    <select class="form-select form-select-sm" id="ret-prod" name="prod_id[]" required>
                        <option value="">-- Choose Product --</option>
                        <?php foreach ($products as $pr): ?>
                            <option value="<?= $pr['id'] ?>" data-price="<?= (float)$pr['trade_price'] ?>"><?= htmlspecialchars($pr['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Quantity</label>
                    <input type="number" class="form-control form-control-sm text-center" name="quantity[]" value="10" min="1" step="1" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">Credit Rate (Rs.)</label>
                    <input type="number" class="form-control form-control-sm text-end" id="ret-price" name="unit_price[]" value="0.00" step="0.01" required>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label small fw-semibold">Reason for Return</label>
                <input type="text" class="form-control form-control-sm" name="reason" placeholder="e.g. Customer cancelled portion of order / Seal damaged" required>
            </div>

            <button type="submit" class="btn btn-danger btn-sm px-4">
                <i class="bi bi-check2-circle me-1"></i> Post Sales Return & Issue Credit Note
            </button>
            <button type="button" class="btn btn-light border btn-sm ms-2" data-confirm-cancel data-confirm-target="panel-new-return">Cancel</button>
        </form>
    </div>
</div>

<!-- Returns Table -->
<div class="card border-0 shadow-sm rounded-3">
    <div class="card-body p-0">
        <?php if (empty($returns)): ?>
            <div class="p-4 text-center text-muted">No sales returns recorded yet.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover table-responsive-card align-middle mb-0">
                    <thead class="table-light small">
                        <tr>
                            <th>Return #</th>
                            <th>Date</th>
                            <th>Customer Shop</th>
                            <th>Warehouse / Destination</th>
                            <th>Credit Amount</th>
                            <th>Reason</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($returns as $ret): ?>
                            <tr>
                                <td data-label="Return #" class="fw-bold text-primary"><?= htmlspecialchars($ret['return_number']) ?></td>
                                <td data-label="Date"><?= formatDate($ret['return_date']) ?></td>
                                <td data-label="Customer">
                                    <div class="fw-semibold text-dark"><?= htmlspecialchars($ret['shop_name']) ?></div>
                                    <small class="text-muted"><?= htmlspecialchars($ret['owner_name']) ?></small>
                                </td>
                                <td data-label="Destination">
                                    <span class="badge <?= $ret['return_destination'] === 'good_stock' ? 'bg-success' : 'bg-danger' ?>">
                                        <?= htmlspecialchars(str_replace('_', ' ', $ret['return_destination'])) ?>
                                    </span>
                                </td>
                                <td data-label="Amount" class="fw-bold text-danger"><?= formatMoney($ret['total_amount']) ?></td>
                                <td data-label="Reason"><?= htmlspecialchars($ret['reason']) ?></td>
                                <td data-label="Status">
                                    <span class="badge bg-success">Approved</span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.getElementById('ret-prod').addEventListener('change', function() {
    const opt = this.options[this.selectedIndex];
    if (this.value) {
        document.getElementById('ret-price').value = parseFloat(opt.getAttribute('data-price') || 0).toFixed(2);
    }
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
