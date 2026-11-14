<?php
/**
 * Delivery Man Mobile App - Deliveries & Collection Dashboard
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('delivery', 'view');

$pdo = getDatabaseConnection();
$currentUser = getCurrentUser();
$roleSlug = $currentUser['role_slug'];
$currentEmp = getCurrentEmployee();

// Scoping: If Delivery Man, only show their own assigned deliveries
$whereClause = "1=1";
$params = [];

if ($roleSlug === ROLE_DELIVERY_MAN) {
    $empId = $currentEmp['id'] ?? 0;
    $whereClause = "da.delivery_man_id = ?";
    $params = [$empId];
}

// Assigned Deliveries Query
$deliveriesQuery = "
    SELECT da.*, so.order_number, so.grand_total as order_amount, so.order_date,
           c.id as customer_id, c.shop_name, c.owner_name, c.phone as cust_phone, c.address as cust_address,
           c.current_balance as cust_balance, a.name as area_name,
           e.full_name as delivery_man_name
    FROM `delivery_assignments` da
    JOIN `sales_orders` so ON da.sales_order_id = so.id
    JOIN `customers` c ON so.customer_id = c.id
    JOIN `areas` a ON so.area_id = a.id
    JOIN `employees` e ON da.delivery_man_id = e.id
    WHERE {$whereClause}
    ORDER BY da.id DESC
    LIMIT 100
";

$stmt = $pdo->prepare($deliveriesQuery);
$stmt->execute($params);
$deliveries = $stmt->fetchAll();

// Calculate Today's Delivery Driver KPIs
$today = date('Y-m-d');
$totalAssigned = count($deliveries);
$pendingDeliveries = 0;
$completedDeliveries = 0;
$todayCashCollected = 0.00;
$todayDigitalCollected = 0.00;

foreach ($deliveries as $d) {
    if (in_array($d['status'], ['assigned', 'in_transit'])) {
        $pendingDeliveries++;
    } elseif ($d['status'] === 'delivered' || $d['status'] === 'partially_delivered') {
        $completedDeliveries++;
    }
}

// Cash collected by this driver today
if (!empty($currentEmp['id'])) {
    $cStmt = $pdo->prepare("
        SELECT 
            COALESCE(SUM(CASE WHEN payment_method_code = 'cash' THEN amount ELSE 0 END), 0) as cash_tot,
            COALESCE(SUM(CASE WHEN payment_method_code != 'cash' THEN amount ELSE 0 END), 0) as digital_tot
        FROM `payments`
        WHERE `collected_by` = ? AND `payment_date` = ? AND `status` = 'posted'
    ");
    $cStmt->execute([$currentEmp['id'], $today]);
    $coll = $cStmt->fetch();
    $todayCashCollected = (float)$coll['cash_tot'];
    $todayDigitalCollected = (float)$coll['digital_tot'];
}

$pageTitle = 'Delivery Driver App';
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<!-- Driver App Header -->
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="fw-bold mb-0 text-dark"><i class="bi bi-truck-flatbed text-success me-2"></i>Delivery Driver App</h4>
        <small class="text-muted">Deliveries, Returns & Payment Recovery</small>
    </div>
    <a href="<?= BASE_URL ?>/modules/payments/handover.php" class="btn btn-sm btn-outline-warning">
        <i class="bi bi-wallet2 me-1"></i> Cash Handover
    </a>
</div>

<!-- Driver Daily KPI Cards Grid -->
<div class="row g-2 mb-3">
    <div class="col-6 col-md-3">
        <div class="stat-card p-3">
            <span class="text-muted small fw-semibold">Pending Delivery</span>
            <h4 class="fw-bold text-danger mb-0"><?= $pendingDeliveries ?></h4>
            <small class="text-muted" style="font-size: 0.7rem;">Out of <?= $totalAssigned ?> Orders</small>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card p-3">
            <span class="text-muted small fw-semibold">Completed Today</span>
            <h4 class="fw-bold text-success mb-0"><?= $completedDeliveries ?></h4>
            <small class="text-muted" style="font-size: 0.7rem;">Deliveries</small>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card p-3">
            <span class="text-muted small fw-semibold">Cash in Hand</span>
            <h4 class="fw-bold text-primary mb-0"><?= formatMoney($todayCashCollected) ?></h4>
            <small class="text-muted" style="font-size: 0.7rem;">Needs Handover</small>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card p-3">
            <span class="text-muted small fw-semibold">Digital Recovery</span>
            <h4 class="fw-bold text-info mb-0"><?= formatMoney($todayDigitalCollected) ?></h4>
            <small class="text-muted" style="font-size: 0.7rem;">JazzCash / Bank</small>
        </div>
    </div>
</div>

<!-- Assigned Deliveries List (Mobile Optimized Card Stack) -->
<div class="card border-0 shadow-sm rounded-3">
    <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
        <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-geo-alt-fill text-danger me-1"></i>Today's Assigned Route Deliveries</h6>
        <span class="badge bg-light text-dark border"><?= count($deliveries) ?> Stops</span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($deliveries)): ?>
            <div class="p-4 text-center text-muted">
                <i class="bi bi-truck fs-1 d-block mb-1 text-secondary opacity-50"></i>
                No delivery assignments assigned to you today.
            </div>
        <?php else: ?>
            <div class="list-group list-group-flush">
                <?php foreach ($deliveries as $del): ?>
                    <?php
                    $isDone = in_array($del['status'], ['delivered', 'partially_delivered']);
                    $borderClass = $isDone ? 'border-success' : 'border-primary';
                    $totalReceivable = (float)$del['cust_balance'];
                    ?>
                    <div class="list-group-item p-3 <?= $isDone ? 'bg-light bg-opacity-50' : '' ?>">
                        <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2">
                            <div>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="fw-bold text-dark fs-6"><?= htmlspecialchars($del['shop_name']) ?></span>
                                    <?php
                                    $b = match ($del['status']) {
                                        'assigned', 'in_transit' => 'bg-warning text-dark',
                                        'delivered' => 'bg-success',
                                        'partially_delivered' => 'bg-info text-dark',
                                        'returned' => 'bg-danger',
                                        default => 'bg-secondary'
                                    };
                                    ?>
                                    <span class="badge badge-status <?= $b ?>"><?= htmlspecialchars(str_replace('_', ' ', $del['status'])) ?></span>
                                </div>
                                <div class="small text-muted mt-1">
                                    <i class="bi bi-person me-1"></i><?= htmlspecialchars($del['owner_name']) ?> · 
                                    <a href="tel:<?= htmlspecialchars($del['cust_phone']) ?>" class="text-decoration-none fw-semibold">
                                        <i class="bi bi-telephone-fill text-success me-1"></i><?= htmlspecialchars($del['cust_phone']) ?>
                                    </a>
                                </div>
                                <div class="small text-secondary mt-1">
                                    <i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($del['cust_address']) ?> (<?= htmlspecialchars($del['area_name']) ?>)
                                </div>
                                <div class="small text-muted mt-1">
                                    <span>Challan: <strong><?= htmlspecialchars($del['dispatch_number']) ?></strong></span>
                                    <span class="ms-2">Order: <strong><?= htmlspecialchars($del['order_number']) ?></strong></span>
                                </div>
                            </div>

                            <div class="text-md-end w-100 w-md-auto mt-2 mt-md-0">
                                <div class="d-flex justify-content-between justify-content-md-end gap-3 mb-2">
                                    <div>
                                        <div class="text-muted" style="font-size: 0.72rem;">Order Amount</div>
                                        <div class="fw-bold text-dark"><?= formatMoney($del['order_amount']) ?></div>
                                    </div>
                                    <div>
                                        <div class="text-muted" style="font-size: 0.72rem;">Total Shop Due</div>
                                        <div class="fw-bold text-danger"><?= formatMoney($totalReceivable) ?></div>
                                    </div>
                                </div>

                                <?php if (!$isDone): ?>
                                    <a href="<?= BASE_URL ?>/modules/delivery/deliver.php?id=<?= $del['id'] ?>" class="btn btn-success btn-touch w-100 shadow-sm mb-1">
                                        <i class="bi bi-check2-circle me-1"></i> Deliver & Collect Payment
                                    </a>
                                    <div class="d-flex gap-1 mb-1">
                                        <a href="<?= BASE_URL ?>/modules/orders/bill.php?id=<?= $del['sales_order_id'] ?>" class="btn btn-sm btn-outline-success w-50">
                                            <i class="bi bi-receipt me-1"></i> Bill
                                        </a>
                                        <a href="<?= BASE_URL ?>/modules/warehouse/gatepass.php?id=<?= $del['id'] ?>" class="btn btn-sm btn-outline-primary w-50">
                                            <i class="bi bi-shield-check me-1"></i> Gate Pass
                                        </a>
                                    </div>
                                <?php else: ?>
                                    <a href="<?= BASE_URL ?>/modules/orders/bill.php?id=<?= $del['sales_order_id'] ?>" class="btn btn-sm btn-success w-100 mb-1">
                                        <i class="bi bi-receipt me-1"></i> View Bill & Customer Receipt
                                    </a>
                                    <a href="<?= BASE_URL ?>/modules/warehouse/gatepass.php?id=<?= $del['id'] ?>" class="btn btn-sm btn-outline-primary w-100">
                                        <i class="bi bi-shield-check me-1"></i> View Gate Pass
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
