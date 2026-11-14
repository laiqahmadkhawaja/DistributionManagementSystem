<?php
/**
 * Edit Warehouse Facility
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('warehouse', 'manage');

$pdo = getDatabaseConnection();
$currUser = getCurrentUser();

$whId = (int)($_GET['id'] ?? 0);
if (!$whId) {
    setFlashMessage('error', 'Invalid warehouse ID.');
    redirect(BASE_URL . '/modules/warehouses/index.php');
}

$stmt = $pdo->prepare("SELECT * FROM warehouses WHERE id = ? LIMIT 1");
$stmt->execute([$whId]);
$wh = $stmt->fetch();

if (!$wh) {
    setFlashMessage('error', 'Warehouse not found.');
    redirect(BASE_URL . '/modules/warehouses/index.php');
}

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
        $chk = $pdo->prepare("SELECT id FROM warehouses WHERE code = ? AND id != ? LIMIT 1");
        $chk->execute([$code, $whId]);
        if ($chk->fetch()) {
            $error = "Warehouse code '{$code}' is already used by another facility.";
        } else {
            try {
                $pdo->beginTransaction();

                if ($isDefault) {
                    $pdo->exec("UPDATE warehouses SET is_default = 0");
                }

                $updStmt = $pdo->prepare("
                    UPDATE `warehouses`
                    SET `code` = ?, `name` = ?, `address` = ?, `contact_person` = ?, 
                        `phone` = ?, `is_default` = ?, `status` = ?
                    WHERE id = ?
                ");
                $updStmt->execute([$code, $name, $address, $contactPerson, $phone, $isDefault, $status, $whId]);

                logAudit($pdo, 'warehouses', $whId, 'update', null, ['code' => $code, 'name' => $name, 'status' => $status]);
                $pdo->commit();

                setFlashMessage('success', "Warehouse '{$name}' updated successfully!");
                redirect(BASE_URL . '/modules/warehouses/index.php');

            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = "Update error: " . $e->getMessage();
            }
        }
    }
}

$pageTitle = 'Edit Warehouse - ' . $wh['name'];
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="fw-bold mb-0 text-dark"><i class="bi bi-pencil-square text-primary me-2"></i>Edit Warehouse</h4>
        <small class="text-muted">Facility: <span class="fw-bold text-dark"><?= htmlspecialchars($wh['name']) ?></span> (<code><?= htmlspecialchars($wh['code']) ?></code>)</small>
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
                <h6 class="fw-bold mb-0 text-dark">Update Facility Details</h6>
            </div>
            <div class="card-body p-4">
                <form method="POST" action="<?= BASE_URL ?>/modules/warehouses/edit.php?id=<?= $whId ?>">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Warehouse Code <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="code" required value="<?= htmlspecialchars($_POST['code'] ?? $wh['code']) ?>">
                        </div>

                        <div class="col-md-8">
                            <label class="form-label small fw-semibold">Warehouse / Depot Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" required value="<?= htmlspecialchars($_POST['name'] ?? $wh['name']) ?>">
                        </div>

                        <div class="col-md-12">
                            <label class="form-label small fw-semibold">Facility Street Address</label>
                            <textarea class="form-control" name="address" rows="2"><?= htmlspecialchars($_POST['address'] ?? $wh['address']) ?></textarea>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Contact Person / Store Manager</label>
                            <input type="text" class="form-control" name="contact_person" value="<?= htmlspecialchars($_POST['contact_person'] ?? $wh['contact_person']) ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Contact Phone Number</label>
                            <input type="text" class="form-control" name="phone" value="<?= htmlspecialchars($_POST['phone'] ?? $wh['phone']) ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Facility Status</label>
                            <select class="form-select" name="status">
                                <option value="active" <?= ($wh['status'] === 'active') ? 'selected' : '' ?>>Active (Accepting Stock)</option>
                                <option value="inactive" <?= ($wh['status'] === 'inactive') ? 'selected' : '' ?>>Inactive (Closed)</option>
                            </select>
                        </div>

                        <div class="col-md-6 d-flex align-items-center mt-4">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="is_default" value="1" id="isDefaultCheck" <?= $wh['is_default'] ? 'checked' : '' ?>>
                                <label class="form-check-label fw-semibold small" for="isDefaultCheck">
                                    Set as Company Default Warehouse
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end gap-2 mt-4 pt-3 border-top">
                        <a href="<?= BASE_URL ?>/modules/warehouses/index.php" class="btn btn-outline-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary px-4">
                            <i class="bi bi-save me-1"></i> Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>