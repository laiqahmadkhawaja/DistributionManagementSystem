<?php
/**
 * Warehouse Facilities Management
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('warehouse', 'view');

$pdo = getDatabaseConnection();
$currUser = getCurrentUser();

// Handle Set Default Warehouse POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_default_id'])) {
    $whId = (int)$_POST['set_default_id'];
    $pdo->beginTransaction();
    $pdo->exec("UPDATE warehouses SET is_default = 0");
    $pdo->prepare("UPDATE warehouses SET is_default = 1, status = 'active' WHERE id = ?")->execute([$whId]);
    $pdo->commit();
    setFlashMessage('success', 'Default warehouse updated successfully.');
    redirect(BASE_URL . '/modules/warehouses/index.php');
}

// Fetch all warehouses with active stock metrics
$query = "
    SELECT w.*,
           (SELECT COUNT(DISTINCT product_id) FROM `stock_ledger` WHERE warehouse_id = w.id) as distinct_products,
           (SELECT COALESCE(SUM(quantity_in) - SUM(quantity_out), 0) FROM `stock_ledger` WHERE warehouse_id = w.id) as total_units,
           (SELECT COALESCE(SUM(total_cost), 0) FROM `stock_ledger` WHERE warehouse_id = w.id AND quantity_in > 0) as stock_valuation
    FROM `warehouses` w
    ORDER BY w.is_default DESC, w.name ASC
";
$warehouses = $pdo->query($query)->fetchAll();

$pageTitle = 'Warehouse Management';
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-3 gap-2">
    <div>
        <h4 class="fw-bold mb-0 text-dark"><i class="bi bi-building text-primary me-2"></i>Warehouse Facilities</h4>
        <small class="text-muted">Central depots, regional storage hubs, picking locations & inventory centers</small>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/modules/warehouses/create.php" class="btn btn-sm btn-primary">
            <i class="bi bi-plus-circle me-1"></i> Add New Warehouse
        </a>
        <a href="<?= BASE_URL ?>/modules/inventory/index.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-layers me-1"></i> Stock Ledger
        </a>
    </div>
</div>

<!-- Warehouses Grid / Table -->
<div class="card border-0 shadow-sm rounded-3">
    <div class="card-body p-0">
        <?php if (empty($warehouses)): ?>
            <div class="p-4 text-center text-muted">No warehouses configured yet.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover table-responsive-card align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Warehouse Code / Name</th>
                            <th>Location & Contact</th>
                            <th>Active SKUs</th>
                            <th>Total Units Stored</th>
                            <th>Default Facility</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($warehouses as $wh): ?>
                            <tr>
                                <td data-label="Warehouse">
                                    <div class="fw-bold text-dark fs-6"><?= htmlspecialchars($wh['name']) ?></div>
                                    <div class="text-muted" style="font-size: 0.72rem;">Code: <code><?= htmlspecialchars($wh['code']) ?></code></div>
                                </td>
                                <td data-label="Location">
                                    <div class="text-dark small"><i class="bi bi-geo-alt me-1 text-primary"></i><?= htmlspecialchars($wh['address'] ?: 'Gujranwala') ?></div>
                                    <small class="text-muted">Contact: <?= htmlspecialchars($wh['contact_person'] ?: '-') ?> <?= !empty($wh['phone']) ? '(' . htmlspecialchars($wh['phone']) . ')' : '' ?></small>
                                </td>
                                <td data-label="SKUs">
                                    <span class="badge bg-light text-dark border"><?= $wh['distinct_products'] ?> SKUs</span>
                                </td>
                                <td data-label="Units" class="fw-bold text-dark">
                                    <?= number_format((float)$wh['total_units'], 0) ?>
                                </td>
                                <td data-label="Default">
                                    <?php if ($wh['is_default']): ?>
                                        <span class="badge bg-primary"><i class="bi bi-star-fill me-1"></i> Default Facility</span>
                                    <?php else: ?>
                                        <form method="POST" action="<?= BASE_URL ?>/modules/warehouses/index.php" style="display:inline;">
                                            <input type="hidden" name="set_default_id" value="<?= $wh['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size: 0.75rem;">
                                                Set Default
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Status">
                                    <span class="badge <?= $wh['status'] === 'active' ? 'bg-success' : 'bg-danger' ?>">
                                        <?= htmlspecialchars(strtoupper($wh['status'])) ?>
                                    </span>
                                </td>
                                <td data-label="Actions" class="text-end">
                                    <div class="d-inline-flex gap-1">
                                        <a href="<?= BASE_URL ?>/modules/warehouses/edit.php?id=<?= $wh['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit Warehouse">
                                            <i class="bi bi-pencil-square"></i> Edit
                                        </a>
                                        <a href="<?= BASE_URL ?>/modules/inventory/index.php?warehouse_id=<?= $wh['id'] ?>" class="btn btn-sm btn-outline-secondary" title="View Warehouse Inventory">
                                            <i class="bi bi-box-seam"></i> Stock
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

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>