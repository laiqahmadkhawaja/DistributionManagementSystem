<?php
/**
 * Granular Role-Based Access Control (RBAC) & Data Scoping
 * Enterprise Distribution Management System (DMS)
 */

if (!defined('DMS_INIT')) {
    define('DMS_INIT', true);
}

function hasRole($roles): bool {
    $user = getCurrentUser();
    if (!$user) {
        return false;
    }
    if (is_array($roles)) {
        return in_array($user['role_slug'], $roles, true);
    }
    return $user['role_slug'] === $roles;
}

function hasPermission(string $module, string $action): bool {
    $user = getCurrentUser();
    if (!$user) {
        return false;
    }

    // Super Admin has all permissions automatically
    if ($user['role_slug'] === ROLE_SUPER_ADMIN) {
        return true;
    }

    static $permissionsCache = [];
    $cacheKey = $user['role_id'] . ':' . $module . ':' . $action;

    if (isset($permissionsCache[$cacheKey])) {
        return $permissionsCache[$cacheKey];
    }

    $pdo = getDatabaseConnection();
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM `role_permissions` rp
        JOIN `permissions` p ON rp.permission_id = p.id
        WHERE rp.role_id = ? AND p.module = ? AND p.action = ?
    ");
    $stmt->execute([$user['role_id'], $module, $action]);
    $has = (int)$stmt->fetchColumn() > 0;

    $permissionsCache[$cacheKey] = $has;
    return $has;
}

function requirePermission(string $module, string $action): void {
    requireLogin();
    if (!hasPermission($module, $action)) {
        http_response_code(403);
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => "Access denied. Missing permission: {$module}.{$action}"]);
            exit;
        }

        // Render clean inline access denied message
        require_once BASE_PATH . '/includes/header.php';
        $curr = getCurrentUser();
        $userInfo = $curr 
            ? 'Currently signed in as: <strong>' . htmlspecialchars($curr['full_name']) . '</strong> (<code>' . htmlspecialchars($curr['username']) . '</code> · Role: <span class="badge bg-secondary">' . htmlspecialchars($curr['role_name']) . '</span>)'
            : 'No active authenticated session detected.';

        echo '<div class="container-fluid py-4">
            <div class="card border-danger shadow-sm" style="max-width: 720px; margin: 0 auto; border-radius: 12px;">
                <div class="card-header bg-danger text-white py-3">
                    <h5 class="mb-0 fw-bold"><i class="bi bi-shield-lock-fill me-2"></i>Access Denied (403)</h5>
                </div>
                <div class="card-body p-4">
                    <div class="d-flex align-items-start mb-3">
                        <div class="me-3 text-danger"><i class="bi bi-exclamation-triangle-fill fs-1"></i></div>
                        <div>
                            <h6 class="fw-bold text-dark mb-1">Permission Restricted</h6>
                            <p class="text-secondary mb-2">You do not have the required permission (<code>' . htmlspecialchars($module . '.' . $action) . '</code>) to perform this action or view this document.</p>
                            <div class="p-2 bg-light rounded border text-muted small mb-2">' . $userInfo . '</div>
                            <small class="text-muted">If you need access to this feature, please contact your Super Administrator to update your role permissions.</small>
                        </div>
                    </div>
                    <hr>
                    <div class="d-flex flex-wrap gap-2">
                        <a href="' . BASE_URL . '/dashboard.php" class="btn btn-primary">
                            <i class="bi bi-house-door me-1"></i> Return to Dashboard
                        </a>
                        <a href="' . BASE_URL . '/logout.php" class="btn btn-danger">
                            <i class="bi bi-box-arrow-right me-1"></i> Sign Out / Switch Account
                        </a>
                        <a href="javascript:history.back()" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left me-1"></i> Go Back
                        </a>
                    </div>
                </div>
            </div>
        </div>';
        require_once BASE_PATH . '/includes/footer.php';
        exit;
    }
}

function getUserAssignedAreaIds(?int $userId = null): array {
    if ($userId === null) {
        $user = getCurrentUser();
        if (!$user) return [];
        if ($user['role_slug'] === ROLE_SUPER_ADMIN || $user['role_slug'] === ROLE_ADMIN) {
            // Admin has access to all areas
            $pdo = getDatabaseConnection();
            return $pdo->query("SELECT id FROM `areas` WHERE status = 'active'")->fetchAll(PDO::FETCH_COLUMN);
        }
        $userId = $user['id'];
    }

    $pdo = getDatabaseConnection();
    // Get employee id
    $empStmt = $pdo->prepare("SELECT id FROM `employees` WHERE user_id = ?");
    $empStmt->execute([$userId]);
    $empId = $empStmt->fetchColumn();

    if (!$empId) {
        return [];
    }

    $stmt = $pdo->prepare("
        SELECT area_id FROM `employee_area_assignments` WHERE employee_id = ?
        UNION
        SELECT id FROM `areas` WHERE assigned_order_booker_id = ? OR assigned_salesman_id = ?
    ");
    $stmt->execute([$empId, $empId, $empId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

function getUserAssignedWarehouseIds(?int $userId = null): array {
    $pdo = getDatabaseConnection();
    // In multi-warehouse architecture, admins and storekeepers get all active warehouses
    return $pdo->query("SELECT id FROM `warehouses` WHERE status = 'active'")->fetchAll(PDO::FETCH_COLUMN);
}
