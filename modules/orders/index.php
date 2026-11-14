<?php
/**
 * Sales Orders Management & Listing
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('orders', 'view');

$pdo = getDatabaseConnection();
$currentUser = getCurrentUser();
$roleSlug = $currentUser['role_slug'];

// Status filter
$filterStatus = sanitize($_GET['status'] ?? 'all');
$filterArea = (int)($_GET['area_id'] ?? 0);
$search = sanitize($_GET['q'] ?? '');

$where = ["1=1"];
$params = [];

// Data-level scoping: Order Bookers only see orders in their assigned areas or their own booked orders
if ($roleSlug === ROLE_ORDER_BOOKER || $roleSlug === ROLE_SALESMAN) {
    $emp = getCurrentEmployee();
    $empId = $emp['id'] ?? 0;
    $where[] = "(so.order_booker_id = ?)";
    $params[] = $empId;
}

if ($filterStatus !== 'all' && !empty($filterStatus)) {
    $where[] = "so.status = ?";
    $params[] = $filterStatus;
}

if ($filterArea > 0) {
    $where[] = "so.area_id = ?";
    $params[] = $filterArea;
}

if (!empty($search)) {
    $where[] = "(so.order_number LIKE ? OR c.shop_name LIKE ? OR c.owner_name LIKE ?)";
    $like = "%{$search}%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$whereSql = implode(' AND ', $where);

$query = "
    SELECT so.*, c.shop_name, c.owner_name, c.phone as cust_phone,
           e.full_name as booker_name, a.name as area_name,
           (SELECT COUNT(*) FROM `sales_order_items` WHERE sales_order_id = so.id) as item_count
    FROM `sales_orders` so
    JOIN `customers` c ON so.customer_id = c.id
    JOIN `employees` e ON so.order_booker_id = e.id
    JOIN `areas` a ON so.area_id = a.id
    WHERE {$whereSql}
    ORDER BY so.id DESC
    LIMIT 100
";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$orders = $stmt->fetchAll();

$areas = $pdo->query("SELECT id, name FROM `areas` WHERE status = 'active' ORDER BY name ASC")->fetchAll();

$pageTitle = 'Sales Orders';
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-3 gap-2">
    <div>
        <h4 class="fw-bold mb-0 text-dark"><i class="bi bi-card-checklist text-primary me-2"></i>Sales Orders</h4>
        <small class="text-muted">Manage, Approve, Dispatch and Track Customer Orders</small>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/modules/reports/loadsheet.php" class="btn btn-outline-primary btn-touch shadow-sm">
            <i class="bi bi-truck-flatbed me-1"></i> Vehicle Load Sheet
        </a>
        <a href="<?= BASE_URL ?>/modules/orders/book.php" class="btn btn-primary btn-touch shadow-sm">
            <i class="bi bi-plus-circle me-1"></i> Book New Order
        </a>
    </div>
</div>

<!-- Status Filters Pill Bar -->
<div class="card border-0 shadow-sm rounded-3 mb-3">
    <div class="card-body p-2">
        <div class="d-flex flex-wrap gap-1 align-items-center">
            <a href="<?= BASE_URL ?>/modules/orders/index.php" class="btn btn-sm <?= $filterStatus === 'all' ? 'btn-primary' : 'btn-light border' ?>">
                All Orders
            </a>
            <a href="<?= BASE_URL ?>/modules/orders/index.php?status=submitted" class="btn btn-sm <?= $filterStatus === 'submitted' ? 'btn-warning text-dark' : 'btn-light border' ?>">
                Pending Approval
            </a>
            <a href="<?= BASE_URL ?>/modules/orders/index.php?status=approved" class="btn btn-sm <?= $filterStatus === 'approved' ? 'btn-info text-dark' : 'btn-light border' ?>">
                Approved / Dispatch Ready
            </a>
            <a href="<?= BASE_URL ?>/modules/orders/index.php?status=dispatched" class="btn btn-sm <?= $filterStatus === 'dispatched' ? 'btn-primary' : 'btn-light border' ?>">
                In Transit / Dispatched
            </a>
            <a href="<?= BASE_URL ?>/modules/orders/index.php?status=delivered" class="btn btn-sm <?= $filterStatus === 'delivered' ? 'btn-success' : 'btn-light border' ?>">
                Delivered
            </a>
            <a href="<?= BASE_URL ?>/modules/orders/index.php?status=cancelled" class="btn btn-sm <?= $filterStatus === 'cancelled' ? 'btn-danger' : 'btn-light border' ?>">
                Cancelled
            </a>
        </div>
    </div>
</div>

<!-- Search & Area Filter Bar -->
<div class="card border-0 shadow-sm rounded-3 mb-3">
    <div class="card-body p-3">
        <form method="GET" action="<?= BASE_URL ?>/modules/orders/index.php" class="row g-2">
            <?php if ($filterStatus !== 'all'): ?>
                <input type="hidden" name="status" value="<?= htmlspecialchars($filterStatus) ?>">
            <?php endif; ?>
            <div class="col-12 col-md-6">
                <input type="text" class="form-control form-control-sm" name="q" placeholder="Search by Order #, Shop name, Owner..." value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="col-6 col-md-4">
                <select class="form-select form-select-sm" name="area_id">
                    <option value="">All Areas</option>
                    <?php foreach ($areas as $a): ?>
                        <option value="<?= $a['id'] ?>" <?= $filterArea == $a['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($a['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <button type="submit" class="btn btn-sm btn-secondary w-100">
                    <i class="bi bi-search me-1"></i> Filter
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Orders Listing -->
<div class="card border-0 shadow-sm rounded-3">
    <div class="card-body p-0">
        <?php if (empty($orders)): ?>
            <div class="p-4 text-center text-muted">
                <i class="bi bi-inbox fs-1 d-block mb-2 text-secondary opacity-50"></i>
                No sales orders found matching your criteria.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover table-responsive-card align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Order # / Date</th>
                            <th>Shop / Customer</th>
                            <th>Area / Booker</th>
                            <th>Items</th>
                            <th>Total Amount</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $o): ?>
                            <tr>
                                <td data-label="Order #">
                                    <a href="<?= BASE_URL ?>/modules/orders/view.php?id=<?= $o['id'] ?>" class="fw-bold text-primary text-decoration-none">
                                        <?= htmlspecialchars($o['order_number']) ?>
                                    </a>
                                    <div class="text-muted" style="font-size: 0.72rem;"><?= formatDate($o['order_date']) ?></div>
                                </td>
                                <td data-label="Customer">
                                    <div class="fw-bold text-dark"><?= htmlspecialchars($o['shop_name']) ?></div>
                                    <small class="text-muted"><?= htmlspecialchars($o['owner_name']) ?> · <?= htmlspecialchars($o['cust_phone']) ?></small>
                                </td>
                                <td data-label="Area">
                                    <div><?= htmlspecialchars($o['area_name']) ?></div>
                                    <small class="text-muted"><i class="bi bi-person me-1"></i><?= htmlspecialchars($o['booker_name']) ?></small>
                                </td>
                                <td data-label="Items">
                                    <span class="badge bg-light text-dark border"><?= $o['item_count'] ?> items</span>
                                </td>
                                <td data-label="Amount" class="fw-bold text-dark">
                                    <?= formatMoney($o['grand_total']) ?>
                                </td>
                                <td data-label="Status">
                                    <?php
                                    $badge = match ($o['status']) {
                                        'submitted', 'pending_approval' => 'bg-warning text-dark',
                                        'approved' => 'bg-info text-dark',
                                        'dispatched' => 'bg-primary',
                                        'delivered' => 'bg-success',
                                        'cancelled', 'rejected' => 'bg-danger',
                                        default => 'bg-secondary'
                                    };
                                    ?>
                                    <span class="badge badge-status <?= $badge ?>"><?= htmlspecialchars(str_replace('_', ' ', $o['status'])) ?></span>
                                </td>
                                <td data-label="Action" class="text-end">
                                    <div class="d-inline-flex gap-1">
                                        <a href="<?= BASE_URL ?>/modules/orders/bill.php?id=<?= $o['id'] ?>" class="btn btn-sm btn-outline-success" title="View Customer Commercial Bill">
                                            <i class="bi bi-receipt me-1"></i> Bill
                                        </a>
                                        <a href="<?= BASE_URL ?>/modules/orders/view.php?id=<?= $o['id'] ?>" class="btn btn-sm btn-outline-primary" title="Order Management & Status">
                                            <i class="bi bi-eye me-1"></i> Manage
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
