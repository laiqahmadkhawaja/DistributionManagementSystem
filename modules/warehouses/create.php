<?php
/**
 * Create New Warehouse
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('warehouse', 'manage');

$pdo = getDatabaseConnection();
$currUser = getCurrentUser();

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = strtoupper(trim(sanitize($_POST['code'] ?? '')));
    $name = trim(sanitize($_POST['name'] ?? ''));
    $address = trim(sanitize($_POST['address'] ?? ''));
    $contactPerson = trim(sanitize($_POST['contact_person'] ?? ''));
    $phone = trim(sanitize($_POST['phone'] ?? ''));
    $isDefault = !empty($_POST['is_default']) ? 1 : 0;
    $status = sanitize($_POST['status'] ?? 'active');

    if (empty($code) || empty($name)) {
        $error = "Please enter Warehouse Code and Warehouse Name.";
    } else {
        $chk = $pdo->prepare("SELECT id FROM warehouses WHERE code = ? LIMIT 1");
        $chk->execute([$code]);
        if ($chk->fetch()) {
            $error = "Warehouse code '{$code}' already exists. Please choose a unique code.";
        } else {
            try {
                $pdo->beginTransaction();

                if ($isDefault) {
                    $pdo->exec("UPDATE warehouses SET is_default = 0");
                }

                $stmt = $pdo->prepare("
                    INSERT INTO `warehouses` (`code`, `name`, `address`, `contact_person`, `phone`, `is_default`, `status`, `created_at`)
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([$code, $name, $address, $contactPerson, $phone, $isDefault, $status]);
                $newId = (int)$pdo->lastInsertId();

                logAudit($pdo, 'warehouses', $newId, 'create', null, ['code' => $code, 'name' => $name]);
                $pdo->commit();

                setFlashMessage('success', "Warehouse '{$name}' created successfully!");
                redirect(BASE_URL . '/modules/warehouses/index.php');

            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = "Error creating warehouse: " . $e->getMessage();
            }
        }
    }
}

$pageTitle = 'Add New Warehouse';
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="fw-bold mb-0 text-dark"><i class="bi bi-building-add text-primary me-2"></i>Add Warehouse Facility</h4>
        <small class="text-muted">Register storage depot, central hub or branch stockroom</small>
    </div>
    <a href="<?= BASE_URL ?>/modules/warehouses/index.php" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i> Back to Warehouses
    </a>
</div>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger shadow-sm border-0 mb-3 rounded-3" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-header bg-white py-3 border-0">
                <h6 class="fw-bold mb-0 text-dark">Facility Information</h6>
            </div>
            <div class="card-body p-4">
                <form method="POST" action="<?= BASE_URL ?>/modules/warehouses/create.php">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Warehouse Code <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="code" required value="<?= htmlspecialchars($_POST['code'] ?? '') ?>" placeholder="e.g. WH-02">
                        </div>

                        <div class="col-md-8">
                            <label class="form-label small fw-semibold">Warehouse / Depot Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" required value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" placeholder="e.g. Kamoke Regional Hub">
                        </div>

                        <div class="col-md-12">
                            <label class="form-label small fw-semibold">Facility Street Address</label>
                            <textarea class="form-control" name="address" rows="2" placeholder="Full physical location address"><?= htmlspecialchars($_POST['address'] ?? '') ?></textarea>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Contact Person / Store Manager</label>
                            <input type="text" class="form-control" name="contact_person" value="<?= htmlspecialchars($_POST['contact_person'] ?? '') ?>" placeholder="e.g. Tariq Mahmood">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Contact Phone Number</label>
                            <input type="text" class="form-control" name="phone" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>" placeholder="03009876543">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Facility Status</label>
                            <select class="form-select" name="status">
                                <option value="active" <?= (!isset($_POST['status']) || $_POST['status'] === 'active') ? 'selected' : '' ?>>Active (Accepting Stock)</option>
                                <option value="inactive" <?= (isset($_POST['status']) && $_POST['status'] === 'inactive') ? 'selected' : '' ?>>Inactive (Temporarily Closed)</option>
                            </select>
                        </div>

                        <div class="col-md-6 d-flex align-items-center mt-4">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="is_default" value="1" id="isDefaultCheck">
                                <label class="form-check-label fw-semibold small" for="isDefaultCheck">
                                    Set as Company Default Warehouse
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end gap-2 mt-4 pt-3 border-top">
                        <a href="<?= BASE_URL ?>/modules/warehouses/index.php" class="btn btn-outline-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary px-4">
                            <i class="bi bi-check2-circle me-1"></i> Save Warehouse
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>