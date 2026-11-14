<?php
/**
 * Create New System User
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('settings', 'view');

$pdo = getDatabaseConnection();
$currUser = getCurrentUser();

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim(sanitize($_POST['username'] ?? ''));
    $fullName = trim(sanitize($_POST['full_name'] ?? ''));
    $email = trim(sanitize($_POST['email'] ?? ''));
    $phone = trim(sanitize($_POST['phone'] ?? ''));
    $roleId = (int)($_POST['role_id'] ?? 0);
    $employeeId = (int)($_POST['employee_id'] ?? 0);
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $status = sanitize($_POST['status'] ?? 'active');

    if (empty($username) || empty($fullName) || empty($roleId) || empty($password)) {
        $error = "Please fill in all mandatory fields (Username, Full Name, Role, Password).";
    } elseif ($password !== $confirmPassword) {
        $error = "Passwords do not match. Please re-enter.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters long.";
    } else {
        // Check username uniqueness
        $checkStmt = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        $checkStmt->execute([$username]);
        if ($checkStmt->fetch()) {
            $error = "Username '{$username}' is already taken. Please choose another.";
        } else {
            try {
                $pdo->beginTransaction();
                $passwordHash = password_hash($password, PASSWORD_BCRYPT);

                $insStmt = $pdo->prepare("
                    INSERT INTO `users` (`role_id`, `username`, `password_hash`, `full_name`, `email`, `phone`, `status`, `created_at`)
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $insStmt->execute([$roleId, $username, $passwordHash, $fullName, $email, $phone, $status]);
                $newUserId = (int)$pdo->lastInsertId();

                // If an employee was selected, link this user account to the employee
                if ($employeeId > 0) {
                    $pdo->prepare("UPDATE employees SET user_id = ? WHERE id = ?")->execute([$newUserId, $employeeId]);
                }

                logAudit($pdo, 'users', $newUserId, 'create', null, ['username' => $username, 'role_id' => $roleId]);
                $pdo->commit();

                setFlashMessage('success', "User account '@{$username}' created successfully!");
                redirect(BASE_URL . '/modules/users/index.php');

            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = "Failed to create user: " . $e->getMessage();
            }
        }
    }
}

// Fetch roles and available employees for dropdowns
$roles = $pdo->query("SELECT id, name, slug FROM roles ORDER BY id ASC")->fetchAll();
$availableEmployees = $pdo->query("SELECT id, full_name, employee_code, designation FROM employees WHERE status = 'active' ORDER BY full_name ASC")->fetchAll();

$pageTitle = 'Add New System User';
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="fw-bold mb-0 text-dark"><i class="bi bi-person-plus-fill text-primary me-2"></i>Create New User</h4>
        <small class="text-muted">Register system login with role-based access permissions</small>
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
                <h6 class="fw-bold mb-0 text-dark">User Account Credentials & Details</h6>
            </div>
            <div class="card-body p-4">
                <form method="POST" action="<?= BASE_URL ?>/modules/users/create.php" autocomplete="off">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Username <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">@</span>
                                <input type="text" class="form-control" name="username" required value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" placeholder="e.g. ali_booker">
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Full Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="full_name" required value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>" placeholder="e.g. Muhammad Ali">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Email Address</label>
                            <input type="email" class="form-control" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" placeholder="ali@falcon.pk">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Mobile Phone</label>
                            <input type="text" class="form-control" name="phone" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>" placeholder="03001234567">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">System Access Role <span class="text-danger">*</span></label>
                            <select class="form-select" name="role_id" required>
                                <option value="">Select Role...</option>
                                <?php foreach ($roles as $r): ?>
                                    <option value="<?= $r['id'] ?>" <?= (isset($_POST['role_id']) && $_POST['role_id'] == $r['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($r['name']) ?> (<?= htmlspecialchars($r['slug']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Link to Employee Record (Optional)</label>
                            <select class="form-select" name="employee_id">
                                <option value="0">-- None (System Administrator or Direct User) --</option>
                                <?php foreach ($availableEmployees as $emp): ?>
                                    <option value="<?= $emp['id'] ?>" <?= (isset($_POST['employee_id']) && $_POST['employee_id'] == $emp['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($emp['full_name']) ?> (<?= htmlspecialchars($emp['employee_code']) ?> - <?= htmlspecialchars($emp['designation']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Login Password <span class="text-danger">*</span></label>
                            <input type="password" class="form-control" name="password" required placeholder="Minimum 6 characters">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Confirm Password <span class="text-danger">*</span></label>
                            <input type="password" class="form-control" name="confirm_password" required placeholder="Re-type password">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Account Status</label>
                            <select class="form-select" name="status">
                                <option value="active" <?= (!isset($_POST['status']) || $_POST['status'] === 'active') ? 'selected' : '' ?>>Active (Can Sign In)</option>
                                <option value="inactive" <?= (isset($_POST['status']) && $_POST['status'] === 'inactive') ? 'selected' : '' ?>>Inactive (Access Blocked)</option>
                            </select>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end gap-2 mt-4 pt-3 border-top">
                        <a href="<?= BASE_URL ?>/modules/users/index.php" class="btn btn-outline-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary px-4">
                            <i class="bi bi-check2-circle me-1"></i> Create User Account
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>