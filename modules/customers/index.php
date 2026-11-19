<?php
/**
 * Customer / Retail Shop Directory
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('customers', 'view');

$pdo = getDatabaseConnection();
$currentUser = getCurrentUser();
$roleSlug = $currentUser['role_slug'];

// Area Scoping: If Order Booker, only show shops in assigned areas
$assignedAreaIds = getUserAssignedAreaIds($currentUser['id']);
$areaPlaceholders = !empty($assignedAreaIds) ? implode(',', array_map('intval', $assignedAreaIds)) : '0';

$search = sanitize($_GET['q'] ?? '');
$filterArea = (int)($_GET['area_id'] ?? 0);

$where = ["c.status != 'deleted'"];
$params = [];

if ($roleSlug === ROLE_ORDER_BOOKER || $roleSlug === ROLE_SALESMAN) {
    $where[] = "c.area_id IN ({$areaPlaceholders})";
}

if ($filterArea > 0) {
    $where[] = "c.area_id = ?";
    $params[] = $filterArea;
}

if (!empty($search)) {
    $where[] = "(c.shop_name LIKE ? OR c.owner_name LIKE ? OR c.phone LIKE ? OR c.code LIKE ?)";
    $like = "%{$search}%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$whereSql = implode(' AND ', $where);

$query = "
    SELECT c.*, a.name as area_name, e.full_name as booker_name
    FROM `customers` c
    JOIN `areas` a ON c.area_id = a.id
    LEFT JOIN `employees` e ON c.assigned_order_booker_id = e.id
    WHERE {$whereSql}
    ORDER BY c.shop_name ASC
    LIMIT 150
";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$customers = $stmt->fetchAll();

$areas = $pdo->query("SELECT id, name FROM `areas` WHERE status = 'active' ORDER BY name ASC")->fetchAll();

$pageTitle = 'Retail Shops & Customers';
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-3 gap-2">
    <div>
        <h4 class="fw-bold mb-0 text-dark"><i class="bi bi-shop text-primary me-2"></i>Retail Customers Directory</h4>
        <small class="text-muted">Registered Shops, Credit Balances & Route Assignments</small>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="<?= BASE_URL ?>/modules/customers/import.php" class="btn btn-outline-success btn-touch shadow-sm">
            <i class="bi bi-file-earmark-excel me-1"></i> Import Shops (Excel)
        </a>
        <a href="<?= BASE_URL ?>/modules/customers/create.php" class="btn btn-primary btn-touch shadow-sm">
            <i class="bi bi-plus-circle me-1"></i> Register New Shop
        </a>
    </div>
</div>

<!-- Filter Bar -->
<div class="card border-0 shadow-sm rounded-3 mb-3">
    <div class="card-body p-3">
        <form method="GET" action="<?= BASE_URL ?>/modules/customers/index.php" class="row g-2">
            <div class="col-12 col-md-7">
                <input type="text" class="form-control form-control-sm" name="q" placeholder="Search by Shop Name, Owner, Phone or Code..." value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="col-8 col-md-3">
                <select class="form-select form-select-sm" name="area_id">
                    <option value="">All Areas</option>
                    <?php foreach ($areas as $a): ?>
                        <option value="<?= $a['id'] ?>" <?= $filterArea == $a['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($a['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-4 col-md-2">
                <button type="submit" class="btn btn-sm btn-secondary w-100">
                    <i class="bi bi-search me-1"></i> Filter
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Shops List -->
<div class="card border-0 shadow-sm rounded-3">
    <div class="card-body p-0">
        <?php if (empty($customers)): ?>
            <div class="p-4 text-center text-muted">No retail shops found. Click "Register New Shop" to add.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover table-responsive-card align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Shop Name / Code</th>
                            <th>Owner / Contact</th>
                            <th>Territory Area</th>
                            <th>Customer Type</th>
                            <th>Current Due</th>
                            <th>Credit Limit</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($customers as $c): ?>
                            <tr>
                                <td data-label="Shop">
                                    <a href="<?= BASE_URL ?>/modules/customers/view.php?id=<?= $c['id'] ?>" class="fw-bold text-primary text-decoration-none">
                                        <?= htmlspecialchars($c['shop_name']) ?>
                                    </a>
                                    <div class="text-muted" style="font-size: 0.72rem;"><?= htmlspecialchars($c['code']) ?></div>
                                </td>
                                <td data-label="Owner">
                                    <div class="text-dark fw-semibold"><?= htmlspecialchars($c['owner_name']) ?></div>
                                    <small class="text-muted"><?= htmlspecialchars($c['phone']) ?></small>
                                </td>
                                <td data-label="Area">
                                    <span class="badge bg-light text-dark border"><?= htmlspecialchars($c['area_name']) ?></span>
                                    <small class="d-block text-muted" style="font-size: 0.7rem;"><?= htmlspecialchars($c['address']) ?></small>
                                </td>
                                <td data-label="Type">
                                    <span class="badge bg-secondary"><?= ucfirst($c['customer_type']) ?></span>
                                </td>
                                <td data-label="Current Due" class="fw-bold <?= $c['current_balance'] > 0 ? 'text-danger' : 'text-success' ?>">
                                    <?= formatMoney($c['current_balance']) ?>
                                </td>
                                <td data-label="Limit" class="text-muted small">
                                    <?= formatMoney($c['credit_limit']) ?>
                                </td>
                                <td data-label="Action" class="text-end">
                                    <div class="btn-group btn-group-sm">
                                        <a href="<?= BASE_URL ?>/modules/customers/view.php?id=<?= $c['id'] ?>" class="btn btn-primary" title="View 360 Customer Profile">
                                            <i class="bi bi-person-badge me-1"></i> Profile
                                        </a>
                                        <a href="<?= BASE_URL ?>/modules/payments/recovery.php?customer_id=<?= $c['id'] ?>" class="btn btn-outline-success" title="Record Payment Recovery">
                                            <i class="bi bi-cash-coin me-1"></i> Recover
                                        </a>
                                        <a href="<?= BASE_URL ?>/modules/customers/ledger.php?id=<?= $c['id'] ?>" class="btn btn-outline-secondary" title="View Customer Ledger">
                                            <i class="bi bi-journal-text me-1"></i> Ledger
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
