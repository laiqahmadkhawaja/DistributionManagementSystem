<?php
/**
 * Measurement Units & Multi-Tier Conversion Management
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('products', 'view');

$pdo = getDatabaseConnection();
$currentUser = getCurrentUser();
$activeTab = sanitize($_GET['tab'] ?? 'units');

// Handle Add/Edit Unit POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_unit'])) {
    requirePermission('products', 'create');

    $unitId = (int)($_POST['unit_id'] ?? 0);
    $name = sanitize($_POST['name'] ?? '');
    $code = strtolower(sanitize($_POST['code'] ?? ''));
    $isBase = !empty($_POST['is_base']) ? 1 : 0;

    if (empty($name) || empty($code)) {
        setFlashMessage('error', 'Unit name and symbol/code are required.');
        redirect(BASE_URL . '/modules/units/index.php?tab=units');
    }

    try {
        // Check for duplicate code
        $dupStmt = $pdo->prepare("SELECT id FROM `units` WHERE `code` = ? AND id != ? LIMIT 1");
        $dupStmt->execute([$code, $unitId]);
        if ($dupStmt->fetch()) {
            setFlashMessage('error', "A unit with code '{$code}' already exists.");
            redirect(BASE_URL . '/modules/units/index.php?tab=units');
        }

        if ($unitId > 0) {
            $stmt = $pdo->prepare("UPDATE `units` SET `name` = ?, `code` = ?, `is_base` = ? WHERE `id` = ?");
            $stmt->execute([$name, $code, $isBase, $unitId]);
            logAudit($pdo, 'units', $unitId, 'update', null, ['name' => $name, 'code' => $code]);
            setFlashMessage('success', "Unit '{$name}' updated successfully.");
        } else {
            $stmt = $pdo->prepare("INSERT INTO `units` (`name`, `code`, `is_base`, `created_at`) VALUES (?, ?, ?, NOW())");
            $stmt->execute([$name, $code, $isBase]);
            $newId = (int)$pdo->lastInsertId();
            logAudit($pdo, 'units', $newId, 'create', null, ['name' => $name, 'code' => $code]);
            setFlashMessage('success', "New unit '{$name}' ({$code}) created successfully.");
        }
        redirect(BASE_URL . '/modules/units/index.php?tab=units');

    } catch (Exception $e) {
        setFlashMessage('error', 'Database error: ' . $e->getMessage());
        redirect(BASE_URL . '/modules/units/index.php?tab=units');
    }
}

// Handle Delete Unit POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_unit'])) {
    requirePermission('products', 'delete');
    $unitId = (int)($_POST['unit_id'] ?? 0);

    if ($unitId > 0) {
        // Integrity Check 1: Products table
        $prodCount = (int)$pdo->query("SELECT COUNT(*) FROM `products` WHERE `base_unit_id` = {$unitId}")->fetchColumn();
        if ($prodCount > 0) {
            setFlashMessage('error', "Cannot delete this unit: {$prodCount} product(s) are actively configured with it.");
            redirect(BASE_URL . '/modules/units/index.php?tab=units');
        }

        // Integrity Check 2: Unit conversions table
        $convCount = (int)$pdo->query("SELECT COUNT(*) FROM `unit_conversions` WHERE `from_unit_id` = {$unitId} OR `to_unit_id` = {$unitId}")->fetchColumn();
        if ($convCount > 0) {
            setFlashMessage('error', "Cannot delete this unit: It is used in {$convCount} active conversion ratio rule(s). Remove the conversion rule first.");
            redirect(BASE_URL . '/modules/units/index.php?tab=units');
        }

        try {
            $pdo->prepare("DELETE FROM `units` WHERE `id` = ?")->execute([$unitId]);
            logAudit($pdo, 'units', $unitId, 'delete');
            setFlashMessage('success', 'Unit deleted successfully.');
        } catch (Exception $e) {
            setFlashMessage('error', 'Error deleting unit: ' . $e->getMessage());
        }
    }
    redirect(BASE_URL . '/modules/units/index.php?tab=units');
}

// Handle Add/Edit Conversion Rule POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_conversion'])) {
    requirePermission('products', 'edit');

    $fromUnitId = (int)($_POST['from_unit_id'] ?? 0);
    $toUnitId = (int)($_POST['to_unit_id'] ?? 0);
    $multiplier = (float)($_POST['multiplier'] ?? 0);
    $productId = !empty($_POST['product_id']) ? (int)$_POST['product_id'] : null;

    if ($fromUnitId <= 0 || $toUnitId <= 0 || $multiplier <= 0) {
        setFlashMessage('error', 'Please specify valid units and a positive conversion multiplier.');
        redirect(BASE_URL . '/modules/units/index.php?tab=conversions');
    }

    if ($fromUnitId === $toUnitId) {
        setFlashMessage('error', 'Source unit and target unit cannot be the same.');
        redirect(BASE_URL . '/modules/units/index.php?tab=conversions');
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO `unit_conversions` (`product_id`, `from_unit_id`, `to_unit_id`, `multiplier`, `created_at`)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$productId, $fromUnitId, $toUnitId, $multiplier]);
        $ruleId = (int)$pdo->lastInsertId();
        logAudit($pdo, 'unit_conversions', $ruleId, 'create', null, ['from' => $fromUnitId, 'to' => $toUnitId, 'mult' => $multiplier]);
        setFlashMessage('success', 'Unit conversion rule configured successfully.');
        redirect(BASE_URL . '/modules/units/index.php?tab=conversions');

    } catch (Exception $e) {
        setFlashMessage('error', 'Error saving conversion rule: ' . $e->getMessage());
        redirect(BASE_URL . '/modules/units/index.php?tab=conversions');
    }
}

// Handle Delete Conversion Rule POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_conversion'])) {
    requirePermission('products', 'delete');
    $convId = (int)($_POST['conv_id'] ?? 0);

    if ($convId > 0) {
        try {
            $pdo->prepare("DELETE FROM `unit_conversions` WHERE `id` = ?")->execute([$convId]);
            logAudit($pdo, 'unit_conversions', $convId, 'delete');
            setFlashMessage('success', 'Conversion rule removed successfully.');
        } catch (Exception $e) {
            setFlashMessage('error', 'Error removing rule: ' . $e->getMessage());
        }
    }
    redirect(BASE_URL . '/modules/units/index.php?tab=conversions');
}

// Fetch Units with Product Usage Counts
$unitsQuery = "
    SELECT u.*, 
           COUNT(p.id) as product_count,
           (SELECT COUNT(*) FROM `unit_conversions` uc WHERE uc.from_unit_id = u.id OR uc.to_unit_id = u.id) as conversion_count
    FROM `units` u
    LEFT JOIN `products` p ON u.id = p.base_unit_id
    GROUP BY u.id
    ORDER BY u.is_base DESC, u.name ASC
";
$units = $pdo->query($unitsQuery)->fetchAll();

// Fetch Conversion Rules with Unit Names and Product Name if Scoped
$convQuery = "
    SELECT uc.*, 
           uf.name as from_name, uf.code as from_code,
           ut.name as to_name, ut.code as to_code,
           p.name as product_name, p.code as product_code
    FROM `unit_conversions` uc
    JOIN `units` uf ON uc.from_unit_id = uf.id
    JOIN `units` ut ON uc.to_unit_id = ut.id
    LEFT JOIN `products` p ON uc.product_id = p.id
    ORDER BY uc.product_id ASC, uf.name ASC
";
$conversions = $pdo->query($convQuery)->fetchAll();

// Fetch Active Products for optional product-specific conversions
$products = $pdo->query("SELECT id, name, code FROM `products` WHERE status = 'active' ORDER BY name ASC")->fetchAll();

$pageTitle = 'Measurement Units & Conversions';
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<!-- Header Action Toolbar -->
<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-3 gap-2">
    <div>
        <h4 class="fw-bold mb-0 text-dark"><i class="bi bi-rulers text-primary me-2"></i>Unit Management & Conversion Ratios</h4>
        <small class="text-muted">Packaging Units, Standard Multipliers & Auto-Calculating Stock Conversions</small>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <button type="button" class="btn btn-sm btn-primary" data-confirm-trigger data-confirm-target="panel-add-unit">
            <i class="bi bi-plus-circle me-1"></i> Add Unit
        </button>
        <button type="button" class="btn btn-sm btn-outline-primary" data-confirm-trigger data-confirm-target="panel-add-conversion">
            <i class="bi bi-arrow-left-right me-1"></i> Add Conversion Ratio
        </button>
        <a href="<?= BASE_URL ?>/modules/products/index.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-tags me-1"></i> Product Catalogue
        </a>
    </div>
</div>

<!-- Inline Add Unit Form Panel (Strict No-Modal UI) -->
<div id="panel-add-unit" class="card border-0 shadow-sm rounded-3 mb-3 d-none">
    <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
        <h6 class="fw-bold mb-0 text-primary"><i class="bi bi-plus-square me-2"></i>Create New Measurement Unit</h6>
        <button type="button" class="btn btn-sm btn-close" data-confirm-cancel data-confirm-target="panel-add-unit"></button>
    </div>
    <div class="card-body p-3 pt-0">
        <form method="POST" action="<?= BASE_URL ?>/modules/units/index.php">
            <?= getCsrfField() ?>
            <input type="hidden" name="save_unit" value="1">
            <input type="hidden" name="unit_id" id="edit_unit_id" value="0">
            <div class="row g-2 align-items-end">
                <div class="col-12 col-md-4">
                    <label class="form-label small fw-semibold">Unit Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control form-control-sm" name="name" id="unit_name" placeholder="e.g. Carton, Box, Bottle, Pack" required>
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label small fw-semibold">Symbol / Code <span class="text-danger">*</span></label>
                    <input type="text" class="form-control form-control-sm" name="code" id="unit_code" placeholder="e.g. ctn, box, btl, pack" required>
                </div>
                <div class="col-12 col-md-3">
                    <div class="form-check mb-1">
                        <input class="form-check-input" type="checkbox" name="is_base" id="unit_is_base" value="1">
                        <label class="form-check-label small fw-semibold" for="unit_is_base">
                            Is Primary Base Unit?
                        </label>
                    </div>
                    <small class="text-muted d-block" style="font-size: 0.72rem;">Smallest inventory tracking unit (e.g. Piece, Kg, Liter).</small>
                </div>
                <div class="col-12 col-md-2">
                    <button type="submit" class="btn btn-sm btn-primary w-100">
                        <i class="bi bi-check-lg me-1"></i> Save Unit
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Inline Add Conversion Ratio Panel (Strict No-Modal UI) -->
<div id="panel-add-conversion" class="card border-0 shadow-sm rounded-3 mb-3 d-none">
    <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
        <h6 class="fw-bold mb-0 text-success"><i class="bi bi-arrow-left-right me-2"></i>Configure Unit Conversion Ratio</h6>
        <button type="button" class="btn btn-sm btn-close" data-confirm-cancel data-confirm-target="panel-add-conversion"></button>
    </div>
    <div class="card-body p-3 pt-0">
        <form method="POST" action="<?= BASE_URL ?>/modules/units/index.php">
            <?= getCsrfField() ?>
            <input type="hidden" name="save_conversion" value="1">
            <div class="row g-2 align-items-end">
                <div class="col-12 col-md-3">
                    <label class="form-label small fw-semibold">1 Packaging Unit (From) <span class="text-danger">*</span></label>
                    <select class="form-select form-select-sm" name="from_unit_id" id="from_unit_select" required>
                        <option value="">-- Select Source Unit --</option>
                        <?php foreach ($units as $u): ?>
                            <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['name']) ?> (<?= htmlspecialchars($u['code']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-2 text-center d-none d-md-block pb-2">
                    <span class="fs-5 text-muted fw-bold">=</span>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small fw-semibold">Multiplier / Ratio <span class="text-danger">*</span></label>
                    <input type="number" step="0.0001" min="0.0001" class="form-control form-control-sm text-end" name="multiplier" placeholder="e.g. 12" required>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label small fw-semibold">Sub-Units (To) <span class="text-danger">*</span></label>
                    <select class="form-select form-select-sm" name="to_unit_id" id="to_unit_select" required>
                        <option value="">-- Select Target Unit --</option>
                        <?php foreach ($units as $u): ?>
                            <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['name']) ?> (<?= htmlspecialchars($u['code']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-8 mt-2">
                    <label class="form-label small fw-semibold">Product Scope (Optional)</label>
                    <select class="form-select form-select-sm" name="product_id">
                        <option value="">Global Rule (Applies to all products using these units)</option>
                        <?php foreach ($products as $p): ?>
                            <option value="<?= $p['id'] ?>">Specific Product: <?= htmlspecialchars($p['name']) ?> (<code><?= htmlspecialchars($p['code']) ?></code>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-4 mt-2">
                    <button type="submit" class="btn btn-sm btn-success w-100">
                        <i class="bi bi-link-45deg me-1"></i> Save Conversion Rule
                    </button>
                </div>
            </div>
            <div class="p-2 mt-2 bg-light rounded text-muted small" style="font-size: 0.75rem;">
                <i class="bi bi-info-circle me-1 text-primary"></i> Example: <strong>1 Carton = 12 Boxes</strong>, or <strong>1 Box = 24 Pieces</strong>. When a field salesperson sells 2 Cartons, inventory automatically computes and reserves 24 Boxes or 288 Pieces.
            </div>
        </form>
    </div>
</div>

<!-- Tabs Navigation -->
<ul class="nav nav-pills mb-3" role="tablist">
    <li class="nav-item">
        <button class="nav-link <?= $activeTab === 'units' ? 'active' : '' ?> fw-semibold" id="units-tab" data-bs-toggle="pill" data-bs-target="#units-pane" type="button">
            <i class="bi bi-collection me-1"></i> Measurement Units (<?= count($units) ?>)
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link <?= $activeTab === 'conversions' ? 'active' : '' ?> fw-semibold" id="conversions-tab" data-bs-toggle="pill" data-bs-target="#conversions-pane" type="button">
            <i class="bi bi-arrow-left-right me-1"></i> Conversion Ratios (<?= count($conversions) ?>)
        </button>
    </li>
</ul>

<div class="tab-content">
    <!-- TAB 1: UNITS LIST -->
    <div class="tab-pane fade <?= $activeTab === 'units' ? 'show active' : '' ?>" id="units-pane">
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light small">
                            <tr>
                                <th style="width: 5%;" class="text-center">#</th>
                                <th style="width: 25%;">Unit Name</th>
                                <th style="width: 15%;">Symbol / Code</th>
                                <th style="width: 15%;" class="text-center">Classification</th>
                                <th style="width: 15%;" class="text-center">Configured Products</th>
                                <th style="width: 10%;" class="text-center">Ratios</th>
                                <th style="width: 15%;" class="text-end pe-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($units)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-4 text-muted">No measurement units registered yet.</td>
                                </tr>
                            <?php else: ?>
                                <?php $sno = 1; foreach ($units as $u): ?>
                                    <tr>
                                        <td class="text-center text-muted small"><?= $sno++ ?></td>
                                        <td>
                                            <span class="fw-bold text-dark"><?= htmlspecialchars($u['name']) ?></span>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-dark border font-monospace px-2 py-1">
                                                <?= htmlspecialchars($u['code']) ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($u['is_base']): ?>
                                                <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">
                                                    <i class="bi bi-star-fill me-1"></i> Base Unit
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle px-2 py-1">
                                                    Derived / Packaging
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($u['product_count'] > 0): ?>
                                                <a href="<?= BASE_URL ?>/modules/products/index.php" class="badge bg-primary text-white rounded-pill text-decoration-none px-2 py-1">
                                                    <?= $u['product_count'] ?> products
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted small">0 products</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-info-subtle text-info border border-info-subtle rounded-pill">
                                                <?= $u['conversion_count'] ?> rules
                                            </span>
                                        </td>
                                        <td class="text-end pe-3">
                                            <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2" onclick="editUnit(<?= $u['id'] ?>, '<?= addslashes(htmlspecialchars($u['name'])) ?>', '<?= addslashes(htmlspecialchars($u['code'])) ?>', <?= $u['is_base'] ?>);" title="Edit Unit">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <?php if ($u['product_count'] == 0 && $u['conversion_count'] == 0): ?>
                                                <span id="del-unit-init-<?= $u['id'] ?>">
                                                    <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2" onclick="document.getElementById('del-unit-init-<?= $u['id'] ?>').classList.add('d-none'); document.getElementById('del-unit-confirm-<?= $u['id'] ?>').classList.remove('d-none');" title="Delete Unit">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </span>
                                                <span id="del-unit-confirm-<?= $u['id'] ?>" class="d-none">
                                                    <form method="POST" action="<?= BASE_URL ?>/modules/units/index.php" class="d-inline">
                                                        <?= getCsrfField() ?>
                                                        <input type="hidden" name="delete_unit" value="1">
                                                        <input type="hidden" name="unit_id" value="<?= $u['id'] ?>">
                                                        <button type="submit" class="btn btn-sm btn-danger py-0 px-2 fw-semibold" title="Confirm Delete">
                                                            <i class="bi bi-check-lg"></i>
                                                        </button>
                                                    </form>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1" onclick="document.getElementById('del-unit-confirm-<?= $u['id'] ?>').classList.add('d-none'); document.getElementById('del-unit-init-<?= $u['id'] ?>').classList.remove('d-none');" title="Cancel">
                                                        <i class="bi bi-x-lg"></i>
                                                    </button>
                                                </span>
                                            <?php else: ?>
                                                <button type="button" class="btn btn-sm btn-light text-muted py-0 px-2 border-0" disabled title="Cannot delete: unit in active use">
                                                    <i class="bi bi-lock"></i>
                                                </button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- TAB 2: UNIT CONVERSION RATIOS -->
    <div class="tab-pane fade <?= $activeTab === 'conversions' ? 'show active' : '' ?>" id="conversions-pane">
        <!-- Visual Multi-Tier Conversion Chain Guide -->
        <div class="card border-0 shadow-sm rounded-3 mb-3 bg-light">
            <div class="card-body p-3">
                <h6 class="fw-bold text-dark mb-1"><i class="bi bi-diagram-3 me-2 text-primary"></i>Multi-Tier Packaging Chain in Distribution</h6>
                <div class="d-flex flex-wrap align-items-center gap-2 mt-2">
                    <span class="badge bg-dark p-2 fs-6">1 Carton</span>
                    <i class="bi bi-arrow-right fs-5 text-muted"></i>
                    <span class="badge bg-primary p-2 fs-6">12 Boxes</span>
                    <i class="bi bi-arrow-right fs-5 text-muted"></i>
                    <span class="badge bg-success p-2 fs-6">288 Pieces (Base)</span>
                </div>
                <small class="text-muted d-block mt-2">When field agents take orders in wholesale cartons or retail boxes, the system automatically translates quantities to base inventory units for accurate dispatch and stock ledger entries.</small>
            </div>
        </div>

        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light small">
                            <tr>
                                <th style="width: 5%;" class="text-center">#</th>
                                <th style="width: 25%;">Scope</th>
                                <th style="width: 20%;">Larger Packaging Unit (From)</th>
                                <th style="width: 25%;">Conversion Equivalence</th>
                                <th style="width: 15%;">Multiplier</th>
                                <th style="width: 10%;" class="text-end pe-3">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($conversions)): ?>
                                <tr>
                                    <td colspan="6" class="text-center py-4 text-muted">No conversion ratios configured yet. Click "Add Conversion Ratio" to create one.</td>
                                </tr>
                            <?php else: ?>
                                <?php $sno = 1; foreach ($conversions as $c): ?>
                                    <tr>
                                        <td class="text-center text-muted small"><?= $sno++ ?></td>
                                        <td>
                                            <?php if (!empty($c['product_name'])): ?>
                                                <span class="badge bg-warning-subtle text-dark border border-warning-subtle">
                                                    <i class="bi bi-box me-1"></i> Product Specific
                                                </span>
                                                <div class="small fw-semibold text-dark mt-1"><?= htmlspecialchars($c['product_name']) ?></div>
                                            <?php else: ?>
                                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle">
                                                    <i class="bi bi-globe me-1"></i> Global Standard
                                                </span>
                                                <div class="small text-muted mt-1">Applies to all products</div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="fw-bold fs-6 text-dark">1 <?= htmlspecialchars($c['from_name']) ?></span>
                                            <span class="text-muted small">(<code><?= htmlspecialchars($c['from_code']) ?></code>)</span>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <span class="text-muted">=</span>
                                                <span class="badge bg-success-subtle text-success fs-6 border border-success-subtle">
                                                    <?= number_format((float)$c['multiplier'], 2) ?> <?= htmlspecialchars($c['to_name']) ?>
                                                </span>
                                                <span class="text-muted small">(<code><?= htmlspecialchars($c['to_code']) ?></code>)</span>
                                            </div>
                                        </td>
                                        <td>
                                            <code class="fw-bold fs-6">× <?= rtrim(rtrim(number_format((float)$c['multiplier'], 4), '0'), '.') ?></code>
                                        </td>
                                        <td class="text-end pe-3">
                                            <span id="del-conv-init-<?= $c['id'] ?>">
                                                <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2" onclick="document.getElementById('del-conv-init-<?= $c['id'] ?>').classList.add('d-none'); document.getElementById('del-conv-confirm-<?= $c['id'] ?>').classList.remove('d-none');" title="Remove Conversion Rule">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </span>
                                            <span id="del-conv-confirm-<?= $c['id'] ?>" class="d-none">
                                                <form method="POST" action="<?= BASE_URL ?>/modules/units/index.php" class="d-inline">
                                                    <?= getCsrfField() ?>
                                                    <input type="hidden" name="delete_conversion" value="1">
                                                    <input type="hidden" name="conv_id" value="<?= $c['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-danger py-0 px-2 fw-semibold" title="Confirm Delete">
                                                        <i class="bi bi-check-lg"></i>
                                                    </button>
                                                </form>
                                                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1" onclick="document.getElementById('del-conv-confirm-<?= $c['id'] ?>').classList.add('d-none'); document.getElementById('del-conv-init-<?= $c['id'] ?>').classList.remove('d-none');" title="Cancel">
                                                    <i class="bi bi-x-lg"></i>
                                                </button>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function editUnit(id, name, code, isBase) {
    document.getElementById('edit_unit_id').value = id;
    document.getElementById('unit_name').value = name;
    document.getElementById('unit_code').value = code;
    document.getElementById('unit_is_base').checked = (isBase == 1);
    
    const panel = document.getElementById('panel-add-unit');
    panel.classList.remove('d-none');
    panel.scrollIntoView({ behavior: 'smooth' });
}
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
