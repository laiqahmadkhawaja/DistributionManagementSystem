<?php
/**
 * Edit System User
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('settings', 'view');

$pdo = getDatabaseConnection();
$currUser = getCurrentUser();

$userId = (int)($_GET['id'] ?? 0);
if (!$userId) {
    setFlashMessage('error', 'Invalid user ID.');
    redirect(BASE_URL . '/modules/users/index.php');
}

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    setFlashMessage('error', 'User not found.');
    redirect(BASE_URL . '/modules/users/index.php');
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim(sanitize($_POST['username'] ?? ''));
    $fullName = trim(sanitize($_POST['full_name'] ?? ''));
    $email = trim(sanitize($_POST['email'] ?? ''));
    $phone = trim(sanitize($_POST['phone'] ?? ''));
    $roleId = (int)($_POST['role_id'] ?? 0);
    $employeeId = (int)($_POST['employee_id'] ?? 0);
    $status = sanitize($_POST['status'] ?? 'active');
    $newPassword = $_POST['new_password'] ?? '';

    if (empty($username) || empty($fullName) || empty($roleId)) {
        $error = "Please fill in all mandatory fields (Username, Full Name, Role).";
    } else {
        // Check username uniqueness
        $checkStmt = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ? LIMIT 1");
        $checkStmt->execute([$username, $userId]);
        if ($checkStmt->fetch()) {
            $error = "Username '{$username}' is already taken by another account.";
        } else {
            try {
                $pdo->beginTransaction();

                if (!empty($newPassword)) {
                    if (strlen($newPassword) < 6) {
                        throw new Exception("Password must be at least 6 characters long.");
                    }
                    $passwordHash = password_hash($newPassword, PASSWORD_BCRYPT);
                    $updStmt = $pdo->prepare("
                        UPDATE `users` 
                        SET `role_id` = ?, `username` = ?, `password_hash` = ?, `full_name` = ?, 
                            `email` = ?, `phone` = ?, `status` = ?, `updated_at` = NOW()
                        WHERE id = ?
                    ");
                    $updStmt->execute([$roleId, $username, $passwordHash, $fullName, $email, $phone, $status, $userId]);
                } else {
                    $updStmt = $pdo->prepare("
                        UPDATE `users` 
                        SET `role_id` = ?, `username` = ?, `full_name` = ?, 
                            `email` = ?, `phone` = ?, `status` = ?, `updated_at` = NOW()
                        WHERE id = ?
                    ");
                    $updStmt->execute([$roleId, $username, $fullName, $email, $phone, $status, $userId]);
                }

                // Update employee linkage
                $pdo->prepare("UPDATE employees SET user_id = NULL WHERE user_id = ?")->execute([$userId]);
                if ($employeeId > 0) {
                    $pdo->prepare("UPDATE employees SET user_id = ? WHERE id = ?")->execute([$userId, $employeeId]);
                }

                logAudit($pdo, 'users', $userId, 'update', null, ['username' => $username, 'role_id' => $roleId, 'status' => $status]);
                $pdo->commit();

                setFlashMessage('success', "User account '@{$username}' updated successfully!");
                redirect(BASE_URL . '/modules/users/index.php');

            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = $e->getMessage();
            }
        }
    }
}

// Fetch linked employee
$currentEmpId = (int)$pdo->query("SELECT id FROM employees WHERE user_id = {$userId} LIMIT 1")->fetchColumn();

// Fetch roles and employees
$roles = $pdo->query("SELECT id, name, slug FROM roles ORDER BY id ASC")->fetchAll();
$availableEmployees = $pdo->query("SELECT id, full_name, employee_code, designation FROM employees WHERE status = 'active' ORDER BY full_name ASC")->fetchAll();

$pageTitle = 'Edit User - @' . $user['username'];
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="fw-bold mb-0 text-dark"><i class="bi bi-pencil-square text-primary me-2"></i>Edit User Account</h4>
        <small class="text-muted">User: <span class="fw-bold text-dark">@<?= htmlspecialchars($user['username']) ?></span> (ID: #<?= $user['id'] ?>)</small>
    </div>
    <a href="<?= BASE_URL ?>/modules/users/index.php" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i> Back to Users
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
                <h6 class="fw-bold mb-0 text-dark">Account Profile & Permissions</h6>
            </div>
            <div class="card-body p-4">
                <form method="POST" action="<?= BASE_URL ?>/modules/users/edit.php?id=<?= $userId ?>" autocomplete="off">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Username <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">@</span>
                                <input type="text" class="form-control" name="username" required value="<?= htmlspecialchars($_POST['username'] ?? $user['username']) ?>">
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Full Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="full_name" required value="<?= htmlspecialchars($_POST['full_name'] ?? $user['full_name']) ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Email Address</label>
                            <input type="email" class="form-control" name="email" value="<?= htmlspecialchars($_POST['email'] ?? $user['email']) ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Mobile Phone</label>
                            <input type="text" class="form-control" name="phone" value="<?= htmlspecialchars($_POST['phone'] ?? $user['phone']) ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">System Access Role <span class="text-danger">*</span></label>
                            <select class="form-select" name="role_id" required>
                                <?php foreach ($roles as $r): ?>
                                    <option value="<?= $r['id'] ?>" <?= (($_POST['role_id'] ?? $user['role_id']) == $r['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($r['name']) ?> (<?= htmlspecialchars($r['slug']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Link to Employee Record</label>
                            <select class="form-select" name="employee_id">
                                <option value="0">-- None (Independent User) --</option>
                                <?php foreach ($availableEmployees as $emp): ?>
                                    <option value="<?= $emp['id'] ?>" <?= (($currentEmpId == $emp['id']) ? 'selected' : '') ?>>
                                        <?= htmlspecialchars($emp['full_name']) ?> (<?= htmlspecialchars($emp['employee_code']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Account Status</label>
                            <select class="form-select" name="status">
                                <option value="active" <?= ($user['status'] === 'active') ? 'selected' : '' ?>>Active (Can Sign In)</option>
                                <option value="inactive" <?= ($user['status'] === 'inactive') ? 'selected' : '' ?>>Inactive (Access Blocked)</option>
                                <option value="suspended" <?= ($user['status'] === 'suspended') ? 'selected' : '' ?>>Suspended</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Reset Password (Leave blank to keep current)</label>
                            <input type="password" class="form-control" name="new_password" placeholder="Enter new password if changing">
                        </div>
                    </div>

                    <div class="d-flex justify-content-end gap-2 mt-4 pt-3 border-top">
                        <a href="<?= BASE_URL ?>/modules/users/index.php" class="btn btn-outline-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary px-4">
                            <i class="bi bi-save me-1"></i> Save User Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>