<?php
/**
 * System User Management
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('settings', 'view'); // Admins and Super Admins

$pdo = getDatabaseConnection();
$currUser = getCurrentUser();

// Handle Status Toggle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_user_id'])) {
    $userId = (int)$_POST['toggle_user_id'];
    if ($userId === (int)$currUser['id']) {
        setFlashMessage('error', 'You cannot deactivate your own user account.');
    } else {
        $curStatus = $pdo->query("SELECT status FROM users WHERE id = {$userId}")->fetchColumn();
        $newStatus = ($curStatus === 'active') ? 'inactive' : 'active';
        $pdo->prepare("UPDATE users SET status = ? WHERE id = ?")->execute([$newStatus, $userId]);
        logAudit($pdo, 'users', $userId, 'status_change', ['from' => $curStatus], ['to' => $newStatus]);
        setFlashMessage('success', "User status updated to {$newStatus}.");
    }
    redirect(BASE_URL . '/modules/users/index.php');
}

// Filters
$search = sanitize($_GET['q'] ?? '');
$roleFilter = sanitize($_GET['role_id'] ?? '');

$query = "
    SELECT u.*, r.name as role_name, r.slug as role_slug,
           e.full_name as emp_name, e.employee_code as emp_code
    FROM `users` u
    JOIN `roles` r ON u.role_id = r.id
    LEFT JOIN `employees` e ON u.id = e.user_id
    WHERE 1=1
";
$params = [];

if (!empty($search)) {
    $query .= " AND (u.username LIKE ? OR u.full_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)";
    $term = "%{$search}%";
    $params[] = $term; $params[] = $term; $params[] = $term; $params[] = $term;
}

if (!empty($roleFilter)) {
    $query .= " AND u.role_id = ?";
    $params[] = (int)$roleFilter;
}

$query .= " ORDER BY u.id ASC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$users = $stmt->fetchAll();

// Fetch roles for filter dropdown
$roles = $pdo->query("SELECT id, name FROM roles ORDER BY id ASC")->fetchAll();

$pageTitle = 'User Management';
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-3 gap-2">
    <div>
        <h4 class="fw-bold mb-0 text-dark"><i class="bi bi-people-fill text-primary me-2"></i>System User Management</h4>
        <small class="text-muted">Manage logins, system roles, credentials & employee bindings</small>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/modules/users/create.php" class="btn btn-sm btn-primary">
            <i class="bi bi-person-plus-fill me-1"></i> Add New User
        </a>
    </div>
</div>

<!-- Filters Bar -->
<div class="card border-0 shadow-sm rounded-3 mb-3">
    <div class="card-body p-3">
        <form method="GET" action="<?= BASE_URL ?>/modules/users/index.php" class="row g-2 align-items-center">
            <div class="col-12 col-md-6">
                <input type="text" class="form-control form-control-sm" name="q" placeholder="Search by username, name, email, phone..." value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="col-8 col-md-4">
                <select class="form-select form-select-sm" name="role_id">
                    <option value="">All System Roles</option>
                    <?php foreach ($roles as $r): ?>
                        <option value="<?= $r['id'] ?>" <?= $roleFilter == $r['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($r['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-4 col-md-2">
                <button type="submit" class="btn btn-sm btn-secondary w-100">
                    <i class="bi bi-funnel me-1"></i> Filter
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Users Table (Responsive Card Design) -->
<div class="card border-0 shadow-sm rounded-3">
    <div class="card-body p-0">
        <?php if (empty($users)): ?>
            <div class="p-4 text-center text-muted">No users found matching your search.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover table-responsive-card align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>User ID / Username</th>
                            <th>Full Name & Contact</th>
                            <th>System Role</th>
                            <th>Linked Employee</th>
                            <th>Last Active</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u): ?>
                            <tr>
                                <td data-label="Username">
                                    <span class="fw-bold text-dark">@<?= htmlspecialchars($u['username']) ?></span>
                                    <div class="text-muted" style="font-size: 0.72rem;">User #<?= $u['id'] ?></div>
                                </td>
                                <td data-label="Name">
                                    <div class="fw-semibold text-dark"><?= htmlspecialchars($u['full_name']) ?></div>
                                    <small class="text-muted"><?= htmlspecialchars($u['email'] ?: '-') ?> <?= !empty($u['phone']) ? '· ' . htmlspecialchars($u['phone']) : '' ?></small>
                                </td>
                                <td data-label="Role">
                                    <?php
                                    $roleBadge = match ($u['role_slug']) {
                                        'super_admin' => 'bg-danger',
                                        'admin' => 'bg-primary',
                                        'order_booker' => 'bg-info text-dark',
                                        'salesman' => 'bg-info text-dark',
                                        'delivery_man' => 'bg-success',
                                        'storekeeper' => 'bg-warning text-dark',
                                        'accountant' => 'bg-secondary',
                                        default => 'bg-dark'
                                    };
                                    ?>
                                    <span class="badge <?= $roleBadge ?>"><?= htmlspecialchars($u['role_name']) ?></span>
                                </td>
                                <td data-label="Employee">
                                    <?php if (!empty($u['emp_name'])): ?>
                                        <span class="text-dark small fw-semibold"><i class="bi bi-person-badge me-1"></i><?= htmlspecialchars($u['emp_name']) ?></span>
                                        <div class="text-muted" style="font-size: 0.7rem;">Code: <?= htmlspecialchars($u['emp_code']) ?></div>
                                    <?php else: ?>
                                        <span class="text-muted small fst-italic">None linked</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Last Active" class="small text-muted">
                                    <?= !empty($u['last_login_at']) ? formatDateTime($u['last_login_at']) : 'Never logged in' ?>
                                </td>
                                <td data-label="Status">
                                    <span class="badge <?= $u['status'] === 'active' ? 'bg-success' : 'bg-danger' ?>">
                                        <?= htmlspecialchars(strtoupper($u['status'])) ?>
                                    </span>
                                </td>
                                <td data-label="Actions" class="text-end">
                                    <div class="d-inline-flex gap-1">
                                        <a href="<?= BASE_URL ?>/modules/users/edit.php?id=<?= $u['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit User">
                                            <i class="bi bi-pencil-square"></i> Edit
                                        </a>
                                        <?php if ($u['id'] !== $currUser['id']): ?>
                                            <form method="POST" action="<?= BASE_URL ?>/modules/users/index.php" style="display:inline;">
                                                <input type="hidden" name="toggle_user_id" value="<?= $u['id'] ?>">
                                                <button type="submit" class="btn btn-sm <?= $u['status'] === 'active' ? 'btn-outline-danger' : 'btn-outline-success' ?>" title="<?= $u['status'] === 'active' ? 'Deactivate' : 'Activate' ?>">
                                                    <i class="bi <?= $u['status'] === 'active' ? 'bi-person-x' : 'bi-person-check' ?>"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
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