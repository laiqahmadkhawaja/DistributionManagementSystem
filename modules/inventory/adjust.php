<?php
/**
 * Manual Stock Adjustments (Audit Gain / Loss)
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('inventory', 'adjust');

$pdo = getDatabaseConnection();
$currentUser = getCurrentUser();

$warehouses = $pdo->query("SELECT id, name FROM `warehouses` WHERE status = 'active'")->fetchAll();
$products = $pdo->query("SELECT id, name, code, purchase_price, base_unit_id FROM `products` WHERE status = 'active' ORDER BY name ASC")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $warehouseId = (int)($_POST['warehouse_id'] ?? 1);
    $adjType = sanitize($_POST['adjustment_type'] ?? 'in'); // 'in' or 'out'
    $reason = sanitize($_POST['reason'] ?? 'Physical inventory recount');
    $adjDate = $_POST['adjustment_date'] ?? date('Y-m-d');

    $productId = (int)($_POST['product_id'] ?? 0);
    $quantity = (float)($_POST['quantity'] ?? 0.00);
    $unitCost = (float)($_POST['unit_cost'] ?? 0.00);

    if (!$productId || $quantity <= 0) {
        setFlashMessage('error', 'Please select a product and positive quantity.');
        redirect(BASE_URL . '/modules/inventory/adjust.php');
    }

    try {
        $pdo->beginTransaction();

        $adjNumber = generateDocNumber($pdo, 'stock_adjustments', 'adjustment_number', 'ADJ-');
        $totalCost = round($quantity * $unitCost, 2);

        $saStmt = $pdo->prepare("
            INSERT INTO `stock_adjustments` (`adjustment_number`, `warehouse_id`, `adjustment_date`, `adjustment_type`, `reason`, `total_amount`, `status`, `created_by`, `created_at`)
            VALUES (?, ?, ?, ?, ?, ?, 'posted', ?, NOW())
        ");
        $saStmt->execute([$adjNumber, $warehouseId, $adjDate, $adjType, $reason, $totalCost, $currentUser['id']]);
        $adjId = (int)$pdo->lastInsertId();

        $pStmt = $pdo->prepare("SELECT base_unit_id FROM `products` WHERE id = ?");
        $pStmt->execute([$productId]);
        $unitId = (int)$pStmt->fetchColumn();

        $pdo->prepare("
            INSERT INTO `stock_adjustment_items` (`stock_adjustment_id`, `product_id`, `unit_id`, `quantity`, `unit_cost`, `total_cost`)
            VALUES (?, ?, ?, ?, ?, ?)
        ")->execute([$adjId, $productId, $unitId, $quantity, $unitCost, $totalCost]);

        $movementType = ($adjType === 'in') ? 'adjustment_in' : 'adjustment_out';
        $qtyIn = ($adjType === 'in') ? $quantity : 0.00;
        $qtyOut = ($adjType === 'out') ? $quantity : 0.00;

        recordStockMovement(
            $pdo,
            $warehouseId,
            $productId,
            $movementType,
            'stock_adjustment',
            $adjId,
            $unitId,
            $qtyIn,
            $qtyOut,
            $unitCost,
            null,
            "Adjustment {$adjNumber}: {$reason}"
        );

        // Double-entry accounting for inventory gain / loss
        $invAcc = getSystemAccount($pdo, 'inventory');
        $cogsAcc = getSystemAccount($pdo, 'cogs');

        if ($adjType === 'in') {
            $lines = [
                ['account_id' => $invAcc['id'], 'debit' => $totalCost, 'credit' => 0.00, 'memo' => "Stock Adjustment Gain {$adjNumber}"],
                ['account_id' => $cogsAcc['id'], 'debit' => 0.00, 'credit' => $totalCost, 'memo' => "COGS credit for stock gain {$adjNumber}"]
            ];
        } else {
            $lines = [
                ['account_id' => $cogsAcc['id'], 'debit' => $totalCost, 'credit' => 0.00, 'memo' => "COGS debit for inventory loss {$adjNumber}"],
                ['account_id' => $invAcc['id'], 'debit' => 0.00, 'credit' => $totalCost, 'memo' => "Inventory write-down {$adjNumber}"]
            ];
        }

        postJournalEntry($pdo, $adjDate, 'stock_adjustment', $adjId, "Stock Adjustment {$adjNumber} - {$reason}", $lines, $currentUser['id']);

        logAudit($pdo, 'inventory', $adjId, 'stock_adjust', null, ['adj_number' => $adjNumber, 'type' => $adjType, 'total' => $totalCost]);
        $pdo->commit();

        setFlashMessage('success', "Stock adjustment {$adjNumber} posted successfully!");
        redirect(BASE_URL . '/modules/inventory/index.php');

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        setFlashMessage('error', 'Adjustment failed: ' . $e->getMessage());
        redirect(BASE_URL . '/modules/inventory/adjust.php');
    }
}

$pageTitle = 'Stock Adjustment';
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="fw-bold mb-0 text-dark"><i class="bi bi-sliders text-warning me-2"></i>Stock Adjustment (In / Out)</h4>
        <small class="text-muted">Reconcile physical stock audits or write-off damages</small>
    </div>
    <a href="<?= BASE_URL ?>/modules/inventory/index.php" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i> Stock Ledger
    </a>
</div>

<div class="card border-0 shadow-sm rounded-3" style="max-width: 600px;">
    <div class="card-body p-4">
        <form method="POST" action="<?= BASE_URL ?>/modules/inventory/adjust.php">
            <?= getCsrfField() ?>

            <div class="mb-3">
                <label class="form-label small fw-semibold">Warehouse</label>
                <select class="form-select form-select-sm" name="warehouse_id" required>
                    <?php foreach ($warehouses as $wh): ?>
                        <option value="<?= $wh['id'] ?>"><?= htmlspecialchars($wh['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="mb-3">
                <label class="form-label small fw-semibold">Adjustment Type</label>
                <div class="d-flex gap-3">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="adjustment_type" id="adj_in" value="in" checked>
                        <label class="form-check-label text-success fw-bold" for="adj_in">
                            <i class="bi bi-plus-circle me-1"></i> Stock In (Surplus / Recount Gain)
                        </label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="adjustment_type" id="adj_out" value="out">
                        <label class="form-check-label text-danger fw-bold" for="adj_out">
                            <i class="bi bi-dash-circle me-1"></i> Stock Out (Damage / Loss Write-Off)
                        </label>
                    </div>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label small fw-semibold">Product</label>
                <select class="form-select form-select-sm" id="adj-product" name="product_id" required>
                    <option value="">-- Choose Product --</option>
                    <?php foreach ($products as $pr): ?>
                        <option value="<?= $pr['id'] ?>" data-cost="<?= (float)$pr['purchase_price'] ?>">
                            <?= htmlspecialchars($pr['name']) ?> (<?= htmlspecialchars($pr['code']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="row g-2 mb-3">
                <div class="col-6">
                    <label class="form-label small fw-semibold">Adjustment Quantity</label>
                    <input type="number" class="form-control form-control-sm text-center fw-bold" name="quantity" value="10" min="1" step="1" required>
                </div>
                <div class="col-6">
                    <label class="form-label small fw-semibold">Unit Cost (Rs.)</label>
                    <input type="number" class="form-control form-control-sm text-end" id="adj-cost" name="unit_cost" value="0.00" step="0.01" required>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label small fw-semibold">Adjustment Date</label>
                <input type="date" class="form-control form-control-sm" name="adjustment_date" value="<?= date('Y-m-d') ?>" required>
            </div>

            <div class="mb-3">
                <label class="form-label small fw-semibold">Reason / Audit Remark</label>
                <input type="text" class="form-control form-control-sm" name="reason" placeholder="e.g. Monthly stock take discrepancy adjustment" required>
            </div>

            <button type="submit" class="btn btn-warning w-100 btn-touch shadow-sm text-dark fw-bold">
                <i class="bi bi-check2-circle me-1"></i> Confirm & Post Stock Adjustment
            </button>
        </form>
    </div>
</div>

<script>
document.getElementById('adj-product').addEventListener('change', function() {
    const opt = this.options[this.selectedIndex];
    if (this.value) {
        document.getElementById('adj-cost').value = parseFloat(opt.getAttribute('data-cost') || 0).toFixed(2);
    }
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
