<?php
/**
 * Sales Territories & Route Area Management
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('areas', 'view');

$pdo = getDatabaseConnection();

// Handle Create / Assign Area POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_area'])) {
    requirePermission('areas', 'create');

    $name = sanitize($_POST['name'] ?? '');
    $city = sanitize($_POST['city'] ?? 'Gujranwala');
    $bookerId = !empty($_POST['assigned_order_booker_id']) ? (int)$_POST['assigned_order_booker_id'] : null;
    $desc = sanitize($_POST['description'] ?? '');

    if (empty($name)) {
        setFlashMessage('error', 'Territory area name is required.');
        redirect(BASE_URL . '/modules/areas/index.php');
    }

    try {
        $code = generateDocNumber($pdo, 'areas', 'code', 'AREA-');
        $stmt = $pdo->prepare("
            INSERT INTO `areas` (`name`, `code`, `city`, `description`, `assigned_order_booker_id`, `status`, `created_at`)
            VALUES (?, ?, ?, ?, ?, 'active', NOW())
        ");
        $stmt->execute([$name, $code, $city, $desc, $bookerId]);
        $areaId = (int)$pdo->lastInsertId();

        if ($bookerId) {
            $pdo->prepare("INSERT INTO `employee_area_assignments` (`employee_id`, `area_id`) VALUES (?, ?) ON DUPLICATE KEY UPDATE area_id=area_id")->execute([$bookerId, $areaId]);
        }

        logAudit($pdo, 'areas', $areaId, 'create_area', null, ['name' => $name, 'code' => $code]);
        setFlashMessage('success', "Sales area '{$name}' created and assigned!");
        redirect(BASE_URL . '/modules/areas/index.php');

    } catch (Exception $e) {
        setFlashMessage('error', 'Error creating area: ' . $e->getMessage());
        redirect(BASE_URL . '/modules/areas/index.php');
    }
}

// Fetch areas
$areas = $pdo->query("
    SELECT a.*, e.full_name as booker_name,
           (SELECT COUNT(*) FROM `customers` WHERE area_id = a.id AND status = 'active') as shop_count
    FROM `areas` a
    LEFT JOIN `employees` e ON a.assigned_order_booker_id = e.id
    WHERE a.status = 'active'
    ORDER BY a.name ASC
")->fetchAll();

$bookers = $pdo->query("SELECT id, full_name FROM `employees` WHERE role_type IN ('order_booker', 'salesman') AND status = 'active' ORDER BY full_name ASC")->fetchAll();

$pageTitle = 'Sales Territories & Areas';
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-3 gap-2">
    <div>
        <h4 class="fw-bold mb-0 text-dark"><i class="bi bi-geo-alt text-primary me-2"></i>Sales Territories & Route Assignment</h4>
        <small class="text-muted">Field Territory Division & Staff Data Scoping</small>
    </div>
    <div>
        <button type="button" class="btn btn-primary btn-touch shadow-sm" data-confirm-trigger data-confirm-target="panel-create-area">
            <i class="bi bi-plus-circle me-1"></i> Add New Territory Area
        </button>
    </div>
</div>

<!-- Inline Create Territory Panel (Strict No-Modal UI) -->
<div id="panel-create-area" class="card border-0 shadow-sm rounded-3 mb-3 d-none">
    <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
        <h6 class="fw-bold mb-0 text-primary"><i class="bi bi-plus-circle me-1"></i>Define New Sales Territory</h6>
        <button type="button" class="btn btn-sm btn-close" data-confirm-cancel data-confirm-target="panel-create-area"></button>
    </div>
    <div class="card-body p-3">
        <form method="POST" action="<?= BASE_URL ?>/modules/areas/index.php">
            <?= getCsrfField() ?>
            <input type="hidden" name="save_area" value="1">
            <div class="row g-2">
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">Territory Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control form-control-sm" name="name" placeholder="e.g. Eminabad Zone" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">City</label>
                    <input type="text" class="form-control form-control-sm" name="city" value="Gujranwala">
                </div>
                <div class="col-md-5">
                    <label class="form-label small fw-semibold">Assigned Order Booker / Salesman</label>
                    <select class="form-select form-select-sm" name="assigned_order_booker_id">
                        <option value="">-- Unassigned --</option>
                        <?php foreach ($bookers as $b): ?>
                            <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label small fw-semibold">Territory Route Notes</label>
                    <input type="text" class="form-control form-control-sm" name="description" placeholder="e.g. GT Road towards Eminabad More">
                </div>
                <div class="col-12 mt-2">
                    <button type="submit" class="btn btn-primary btn-sm px-3">Create Territory</button>
                    <button type="button" class="btn btn-light border btn-sm ms-2" data-confirm-cancel data-confirm-target="panel-create-area">Cancel</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Territories Table -->
<div class="card border-0 shadow-sm rounded-3">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-responsive-card align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Territory Name / Code</th>
                        <th>City</th>
                        <th>Assigned Order Booker</th>
                        <th class="text-center">Registered Shops</th>
                        <th>Description / Coverage</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($areas as $area): ?>
                        <tr>
                            <td data-label="Territory">
                                <div class="fw-bold text-dark"><?= htmlspecialchars($area['name']) ?></div>
                                <small class="text-muted"><?= htmlspecialchars($area['code']) ?></small>
                            </td>
                            <td data-label="City"><?= htmlspecialchars($area['city']) ?></td>
                            <td data-label="Booker">
                                <?php if ($area['booker_name']): ?>
                                    <span class="badge bg-light text-primary border"><i class="bi bi-person-check me-1"></i><?= htmlspecialchars($area['booker_name']) ?></span>
                                <?php else: ?>
                                    <span class="badge bg-light text-muted border">Unassigned</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Shops" class="text-center">
                                <span class="badge bg-success bg-opacity-10 text-success border border-success"><?= $area['shop_count'] ?> Shops</span>
                            </td>
                            <td data-label="Description"><small class="text-muted"><?= htmlspecialchars($area['description'] ?? '-') ?></small></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
