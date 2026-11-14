<?php
/**
 * Sales Territories & Route Area Management
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('areas', 'view');

$pdo = getDatabaseConnection();

// Load Route & Assignment Settings
$cadence = getSetting($pdo, 'route_assignment_cadence', 'weekly');
$coverageTrackingEnabled = (getSetting($pdo, 'route_coverage_tracking', '1') === '1');
$allowFlexibleDays = (getSetting($pdo, 'route_allow_flexible_days', '1') === '1');

// Determine Period & Dates based on Cadence
$now = new DateTime();
if ($cadence === 'daily') {
    $period = sanitize($_GET['period'] ?? $now->format('Y-m-d'));
    $periodStart = $period;
    $periodEnd = $period;
    $prevPeriod = date('Y-m-d', strtotime($period . ' -1 day'));
    $nextPeriod = date('Y-m-d', strtotime($period . ' +1 day'));
    $currentPeriod = $now->format('Y-m-d');
    $periodDisplay = date('l, d M Y', strtotime($period));
} elseif ($cadence === 'monthly') {
    $period = sanitize($_GET['period'] ?? $now->format('Y-m'));
    $periodStart = date('Y-m-01', strtotime($period . '-01'));
    $periodEnd = date('Y-m-t', strtotime($period . '-01'));
    $prevPeriod = date('Y-m', strtotime($period . '-01 -1 month'));
    $nextPeriod = date('Y-m', strtotime($period . '-01 +1 month'));
    $currentPeriod = $now->format('Y-m');
    $periodDisplay = date('F Y', strtotime($period . '-01'));
} elseif ($cadence === 'fixed') {
    $period = 'ALL';
    // For fixed, statistics use current month
    $periodStart = $now->format('Y-m-01');
    $periodEnd = $now->format('Y-m-t');
    $prevPeriod = 'ALL';
    $nextPeriod = 'ALL';
    $currentPeriod = 'ALL';
    $periodDisplay = 'Permanent Fixed Territories (Current Month Activity: ' . $now->format('M Y') . ')';
} else { // default: weekly
    $period = sanitize($_GET['period'] ?? $now->format('o-\WW'));
    $year = (int)substr($period, 0, 4);
    $week = (int)substr($period, 6, 2);
    $dt = new DateTime();
    $dt->setISODate($year, $week);
    $periodStart = $dt->format('Y-m-d');
    $dt->modify('+6 days');
    $periodEnd = $dt->format('Y-m-d');
    
    // Prev / Next weeks
    $dtPrev = new DateTime();
    $dtPrev->setISODate($year, $week);
    $dtPrev->modify('-7 days');
    $prevPeriod = $dtPrev->format('o-\WW');

    $dtNext = new DateTime();
    $dtNext->setISODate($year, $week);
    $dtNext->modify('+7 days');
    $nextPeriod = $dtNext->format('o-\WW');

    $currentPeriod = $now->format('o-\WW');
    $periodDisplay = "Week {$week} ({$year}) • " . date('d M', strtotime($periodStart)) . " to " . date('d M Y', strtotime($periodEnd));
}

// Handle POST: Create Territory Area
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_area'])) {
    requirePermission('areas', 'create');

    $name = sanitize($_POST['name'] ?? '');
    $city = sanitize($_POST['city'] ?? 'Gujranwala');
    $bookerId = !empty($_POST['assigned_order_booker_id']) ? (int)$_POST['assigned_order_booker_id'] : null;
    $riderId = !empty($_POST['assigned_rider_id']) ? (int)$_POST['assigned_rider_id'] : null;
    $dayOfWeek = sanitize($_POST['day_of_week'] ?? 'Flexible');
    $desc = sanitize($_POST['description'] ?? '');

    if (empty($name)) {
        setFlashMessage('error', 'Territory area name is required.');
        redirect(BASE_URL . '/modules/areas/index.php?period=' . urlencode($period));
    }

    try {
        $code = generateDocNumber($pdo, 'areas', 'code', 'AREA-');
        $stmt = $pdo->prepare("
            INSERT INTO `areas` (`name`, `code`, `city`, `description`, `assigned_order_booker_id`, `assigned_rider_id`, `status`, `created_at`)
            VALUES (?, ?, ?, ?, ?, ?, 'active', NOW())
        ");
        $stmt->execute([$name, $code, $city, $desc, $bookerId, $riderId]);
        $areaId = (int)$pdo->lastInsertId();

        // Save Schedule Assignment
        if ($bookerId) {
            $pdo->prepare("
                INSERT INTO `employee_area_assignments` (`employee_id`, `area_id`, `assignment_type`, `schedule_key`, `day_of_week`, `role_type`, `status`)
                VALUES (?, ?, ?, ?, ?, 'order_booker', 'active')
                ON DUPLICATE KEY UPDATE `day_of_week` = VALUES(`day_of_week`), `status` = 'active'
            ")->execute([$bookerId, $areaId, $cadence, $period, $dayOfWeek]);
        }

        if ($riderId) {
            $pdo->prepare("
                INSERT INTO `employee_area_assignments` (`employee_id`, `area_id`, `assignment_type`, `schedule_key`, `day_of_week`, `role_type`, `status`)
                VALUES (?, ?, ?, ?, ?, 'delivery_man', 'active')
                ON DUPLICATE KEY UPDATE `day_of_week` = VALUES(`day_of_week`), `status` = 'active'
            ")->execute([$riderId, $areaId, $cadence, $period, $dayOfWeek]);
        }

        logAudit($pdo, 'areas', $areaId, 'create_area', null, ['name' => $name, 'code' => $code]);
        setFlashMessage('success', "Sales area '{$name}' created and assigned successfully!");
        redirect(BASE_URL . '/modules/areas/index.php?period=' . urlencode($period));

    } catch (Exception $e) {
        setFlashMessage('error', 'Error creating area: ' . $e->getMessage());
        redirect(BASE_URL . '/modules/areas/index.php?period=' . urlencode($period));
    }
}

// Handle POST: Update Area Route Assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_route'])) {
    requirePermission('areas', 'assign');

    $areaId = (int)($_POST['area_id'] ?? 0);
    $bookerId = !empty($_POST['assigned_order_booker_id']) ? (int)$_POST['assigned_order_booker_id'] : null;
    $riderId = !empty($_POST['assigned_rider_id']) ? (int)$_POST['assigned_rider_id'] : null;
    $dayOfWeek = sanitize($_POST['day_of_week'] ?? 'Flexible');
    $updatePermanent = isset($_POST['update_permanent']) ? true : false;

    if ($areaId <= 0) {
        setFlashMessage('error', 'Invalid area selected.');
        redirect(BASE_URL . '/modules/areas/index.php?period=' . urlencode($period));
    }

    try {
        $pdo->beginTransaction();

        // 1. Update master table defaults
        if ($updatePermanent || $cadence === 'fixed') {
            $pdo->prepare("
                UPDATE `areas` 
                SET `assigned_order_booker_id` = ?, `assigned_rider_id` = ? 
                WHERE id = ?
            ")->execute([$bookerId, $riderId, $areaId]);
        }

        // 2. Clear old assignments for this area and period
        $pdo->prepare("
            DELETE FROM `employee_area_assignments` 
            WHERE `area_id` = ? AND `assignment_type` = ? AND `schedule_key` = ?
        ")->execute([$areaId, $cadence, $period]);

        // 3. Insert Booker assignment
        if ($bookerId) {
            $pdo->prepare("
                INSERT INTO `employee_area_assignments` (`employee_id`, `area_id`, `assignment_type`, `schedule_key`, `day_of_week`, `role_type`, `status`)
                VALUES (?, ?, ?, ?, ?, 'order_booker', 'active')
            ")->execute([$bookerId, $areaId, $cadence, $period, $dayOfWeek]);
        }

        // 4. Insert Rider assignment
        if ($riderId) {
            $pdo->prepare("
                INSERT INTO `employee_area_assignments` (`employee_id`, `area_id`, `assignment_type`, `schedule_key`, `day_of_week`, `role_type`, `status`)
                VALUES (?, ?, ?, ?, ?, 'delivery_man', 'active')
            ")->execute([$riderId, $areaId, $cadence, $period, $dayOfWeek]);
        }

        logAudit($pdo, 'areas', $areaId, 'assign_route', null, ['booker_id' => $bookerId, 'rider_id' => $riderId, 'cadence' => $cadence, 'period' => $period]);
        $pdo->commit();

        setFlashMessage('success', "Territory route schedule updated successfully for {$periodDisplay}!");
        redirect(BASE_URL . '/modules/areas/index.php?period=' . urlencode($period));

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        setFlashMessage('error', 'Error assigning route: ' . $e->getMessage());
        redirect(BASE_URL . '/modules/areas/index.php?period=' . urlencode($period));
    }
}

// Fetch staff options
$bookers = $pdo->query("
    SELECT id, full_name, employee_code, phone 
    FROM `employees` 
    WHERE role_type IN ('order_booker', 'salesman') AND status = 'active' 
    ORDER BY full_name ASC
")->fetchAll();

$riders = $pdo->query("
    SELECT id, full_name, employee_code, phone 
    FROM `employees` 
    WHERE role_type IN ('delivery_man', 'salesman') AND status = 'active' 
    ORDER BY full_name ASC
")->fetchAll();

// Fetch all active territories
$rawAreas = $pdo->query("
    SELECT a.*, 
           eb_default.full_name as default_booker_name,
           er_default.full_name as default_rider_name,
           (SELECT COUNT(*) FROM `customers` WHERE area_id = a.id AND status = 'active') as shop_count
    FROM `areas` a
    LEFT JOIN `employees` eb_default ON a.assigned_order_booker_id = eb_default.id
    LEFT JOIN `employees` er_default ON a.assigned_rider_id = er_default.id
    WHERE a.status = 'active'
    ORDER BY a.name ASC
")->fetchAll();

// Fetch scheduled assignments for the active period
$schedStmt = $pdo->prepare("
    SELECT eaa.*, e.full_name, e.employee_code, e.role_type as emp_role
    FROM `employee_area_assignments` eaa
    JOIN `employees` e ON eaa.employee_id = e.id
    WHERE eaa.assignment_type = ? AND eaa.schedule_key = ?
");
$schedStmt->execute([$cadence, $period]);
$scheduledRows = $schedStmt->fetchAll();

// Map scheduled assignments by area_id
$areaSchedMap = [];
foreach ($scheduledRows as $sr) {
    $aId = (int)$sr['area_id'];
    if ($sr['role_type'] === 'order_booker') {
        $areaSchedMap[$aId]['booker_id'] = (int)$sr['employee_id'];
        $areaSchedMap[$aId]['booker_name'] = $sr['full_name'];
        $areaSchedMap[$aId]['day_of_week'] = $sr['day_of_week'];
    } elseif ($sr['role_type'] === 'delivery_man') {
        $areaSchedMap[$aId]['rider_id'] = (int)$sr['employee_id'];
        $areaSchedMap[$aId]['rider_name'] = $sr['full_name'];
        if (empty($areaSchedMap[$aId]['day_of_week'])) {
            $areaSchedMap[$aId]['day_of_week'] = $sr['day_of_week'];
        }
    }
}

// Compute market coverage & progress per territory
$totalSystemShops = 0;
$totalSystemBookedShops = 0;
$totalSystemPeriodSales = 0.0;
$areas = [];

foreach ($rawAreas as $a) {
    $aId = (int)$a['id'];
    $totalShops = (int)$a['shop_count'];
    $totalSystemShops += $totalShops;

    // Period sales & shop visits
    $covStmt = $pdo->prepare("
        SELECT COUNT(DISTINCT customer_id) as booked_shops, COALESCE(SUM(grand_total), 0) as total_sales
        FROM sales_orders 
        WHERE area_id = ? AND order_date BETWEEN ? AND ? AND status NOT IN ('draft', 'rejected', 'cancelled')
    ");
    $covStmt->execute([$aId, $periodStart, $periodEnd]);
    $cov = $covStmt->fetch();
    $bookedShops = (int)($cov['booked_shops'] ?? 0);
    $periodSales = (float)($cov['total_sales'] ?? 0.0);

    $totalSystemBookedShops += $bookedShops;
    $totalSystemPeriodSales += $periodSales;

    $pct = ($totalShops > 0) ? min(100, round(($bookedShops / $totalShops) * 100)) : 0;

    // Resolve scheduled or fallback booker/rider
    $activeBookerId = $areaSchedMap[$aId]['booker_id'] ?? $a['assigned_order_booker_id'];
    $activeBookerName = $areaSchedMap[$aId]['booker_name'] ?? $a['default_booker_name'];
    $activeRiderId = $areaSchedMap[$aId]['rider_id'] ?? $a['assigned_rider_id'];
    $activeRiderName = $areaSchedMap[$aId]['rider_name'] ?? $a['default_rider_name'];
    $assignedDay = $areaSchedMap[$aId]['day_of_week'] ?? 'Flexible';

    $a['active_booker_id'] = $activeBookerId;
    $a['active_booker_name'] = $activeBookerName;
    $a['active_rider_id'] = $activeRiderId;
    $a['active_rider_name'] = $activeRiderName;
    $a['assigned_day'] = $assignedDay;
    $a['booked_shops'] = $bookedShops;
    $a['coverage_pct'] = $pct;
    $a['period_sales'] = $periodSales;

    $areas[] = $a;
}

$overallCoveragePct = ($totalSystemShops > 0) ? min(100, round(($totalSystemBookedShops / $totalSystemShops) * 100)) : 0;

$pageTitle = 'Sales Territories & Route Assignment';
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-3 gap-2">
    <div>
        <div class="d-flex align-items-center gap-2 mb-1">
            <h4 class="fw-bold mb-0 text-dark"><i class="bi bi-geo-alt text-primary me-2"></i>Territory & Route Management</h4>
            <span class="badge bg-primary-subtle text-primary border border-primary-subtle text-uppercase px-2 py-1">
                <i class="bi bi-arrow-repeat me-1"></i><?= htmlspecialchars(ucfirst($cadence)) ?> Cadence
            </span>
            <?php if ($allowFlexibleDays): ?>
                <span class="badge bg-light text-dark border small">Flexible Day Execution</span>
            <?php endif; ?>
        </div>
        <small class="text-muted">Field Territory Routes, Staff Beat Assignment & Market Shop Coverage Tracking</small>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="<?= BASE_URL ?>/modules/settings/index.php?tab=routes" class="btn btn-sm btn-outline-secondary" title="Configure Cadence">
            <i class="bi bi-gear me-1"></i> Cadence Policy
        </a>
        <button type="button" class="btn btn-sm btn-primary shadow-sm" data-confirm-trigger data-confirm-target="panel-create-area">
            <i class="bi bi-plus-circle me-1"></i> Add Territory Area
        </button>
    </div>
</div>

<!-- Schedule Period Navigation Toolbar -->
<div class="card border-0 shadow-sm rounded-3 mb-3 bg-white">
    <div class="card-body p-2 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div class="d-flex align-items-center gap-2">
            <span class="small fw-bold text-muted text-uppercase">Schedule Period:</span>
            <div class="fw-bold text-dark fs-6"><?= htmlspecialchars($periodDisplay) ?></div>
        </div>

        <?php if ($cadence !== 'fixed'): ?>
            <div class="d-flex align-items-center gap-1">
                <a href="<?= BASE_URL ?>/modules/areas/index.php?period=<?= urlencode($prevPeriod) ?>" class="btn btn-sm btn-outline-secondary" title="Previous Period">
                    <i class="bi bi-chevron-left"></i> Prev
                </a>
                <a href="<?= BASE_URL ?>/modules/areas/index.php?period=<?= urlencode($currentPeriod) ?>" class="btn btn-sm <?= $period === $currentPeriod ? 'btn-primary' : 'btn-outline-primary' ?>">
                    Current
                </a>
                <a href="<?= BASE_URL ?>/modules/areas/index.php?period=<?= urlencode($nextPeriod) ?>" class="btn btn-sm btn-outline-secondary" title="Next Period">
                    Next <i class="bi bi-chevron-right"></i>
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Overall Market Coverage Analytics KPI Cards -->
<?php if ($coverageTrackingEnabled): ?>
<div class="row g-2 mb-3">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm p-3 bg-white rounded-3 h-100">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-muted small fw-semibold">Overall Market Coverage</div>
                    <div class="h5 fw-bold text-primary mb-0"><?= $overallCoveragePct ?>%</div>
                </div>
                <div class="p-2 bg-primary-subtle text-primary rounded-circle">
                    <i class="bi bi-pie-chart fs-5"></i>
                </div>
            </div>
            <div class="progress mt-2" style="height: 6px;">
                <div class="progress-bar <?= $overallCoveragePct >= 100 ? 'bg-success' : 'bg-primary' ?>" style="width: <?= $overallCoveragePct ?>%;"></div>
            </div>
            <small class="text-muted mt-1 d-block"><?= $totalSystemBookedShops ?> / <?= $totalSystemShops ?> shops ordered</small>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm p-3 bg-white rounded-3 h-100">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-muted small fw-semibold">Total Territory Areas</div>
                    <div class="h5 fw-bold text-dark mb-0"><?= count($areas) ?> Areas</div>
                </div>
                <div class="p-2 bg-info-subtle text-info rounded-circle">
                    <i class="bi bi-map fs-5"></i>
                </div>
            </div>
            <small class="text-muted mt-1 d-block">Active market beats</small>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm p-3 bg-white rounded-3 h-100">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-muted small fw-semibold">Registered Retail Shops</div>
                    <div class="h5 fw-bold text-success mb-0"><?= $totalSystemShops ?> Shops</div>
                </div>
                <div class="p-2 bg-success-subtle text-success rounded-circle">
                    <i class="bi bi-shop fs-5"></i>
                </div>
            </div>
            <small class="text-muted mt-1 d-block">Customer base in database</small>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm p-3 bg-white rounded-3 h-100">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-muted small fw-semibold">Booked Sales in Period</div>
                    <div class="h5 fw-bold text-dark mb-0"><?= formatMoney($totalSystemPeriodSales) ?></div>
                </div>
                <div class="p-2 bg-warning-subtle text-warning rounded-circle">
                    <i class="bi bi-cash-stack fs-5"></i>
                </div>
            </div>
            <small class="text-muted mt-1 d-block">Gross sales orders value</small>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Inline Create Territory Panel (Strict No-Modal UI) -->
<div id="panel-create-area" class="card border-0 shadow-sm rounded-3 mb-3 d-none">
    <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
        <h6 class="fw-bold mb-0 text-primary"><i class="bi bi-plus-circle me-1"></i>Define New Sales Territory</h6>
        <button type="button" class="btn btn-sm btn-close" data-confirm-cancel data-confirm-target="panel-create-area"></button>
    </div>
    <div class="card-body p-3">
        <form method="POST" action="<?= BASE_URL ?>/modules/areas/index.php?period=<?= urlencode($period) ?>">
            <?= getCsrfField() ?>
            <input type="hidden" name="save_area" value="1">
            <div class="row g-2">
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Territory Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control form-control-sm" name="name" placeholder="e.g. Eminabad Zone" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold">City</label>
                    <input type="text" class="form-control form-control-sm" name="city" value="Gujranwala">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Assigned Order Booker</label>
                    <select class="form-select form-select-sm" name="assigned_order_booker_id">
                        <option value="">-- Unassigned --</option>
                        <?php foreach ($bookers as $b): ?>
                            <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold">Assigned Delivery Rider</label>
                    <select class="form-select form-select-sm" name="assigned_rider_id">
                        <option value="">-- Unassigned --</option>
                        <?php foreach ($riders as $r): ?>
                            <option value="<?= $r['id'] ?>"><?= htmlspecialchars($r['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold">Day of Week</label>
                    <select class="form-select form-select-sm" name="day_of_week">
                        <option value="Flexible">Flexible / Any Day</option>
                        <option value="Monday">Monday</option>
                        <option value="Tuesday">Tuesday</option>
                        <option value="Wednesday">Wednesday</option>
                        <option value="Thursday">Thursday</option>
                        <option value="Friday">Friday</option>
                        <option value="Saturday">Saturday</option>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label small fw-semibold">Territory Route Notes</label>
                    <input type="text" class="form-control form-control-sm" name="description" placeholder="e.g. GT Road towards Eminabad More, Covering retail grocery marts">
                </div>
                <div class="col-12 mt-2">
                    <button type="submit" class="btn btn-primary btn-sm px-3">Create Territory</button>
                    <button type="button" class="btn btn-light border btn-sm ms-2" data-confirm-cancel data-confirm-target="panel-create-area">Cancel</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Territories & Route Scheduling Table -->
<div class="card border-0 shadow-sm rounded-3">
    <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
        <div>
            <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-compass me-2 text-primary"></i>Territory Beat Assignments & Market Progress</h6>
            <small class="text-muted">Showing routes for: <strong><?= htmlspecialchars($periodDisplay) ?></strong></small>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-responsive-card align-middle mb-0">
                <thead class="table-light small">
                    <tr>
                        <th>Territory / City</th>
                        <th>Assigned Order Booker</th>
                        <th>Assigned Delivery Rider</th>
                        <th>Day / Routine</th>
                        <th class="text-center">Registered Shops</th>
                        <?php if ($coverageTrackingEnabled): ?>
                            <th style="min-width: 170px;">Market Coverage & Progress</th>
                            <th class="text-end">Booked Sales</th>
                        <?php endif; ?>
                        <th class="text-end pe-3">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($areas as $area): ?>
                        <tr>
                            <td data-label="Territory">
                                <div class="fw-bold text-dark"><?= htmlspecialchars($area['name']) ?></div>
                                <div class="d-flex gap-1 align-items-center">
                                    <span class="badge bg-secondary-subtle text-dark border small"><?= htmlspecialchars($area['code']) ?></span>
                                    <small class="text-muted"><?= htmlspecialchars($area['city']) ?></small>
                                </div>
                                <?php if (!empty($area['description'])): ?>
                                    <small class="text-muted d-block text-truncate" style="max-width: 200px;" title="<?= htmlspecialchars($area['description']) ?>">
                                        <?= htmlspecialchars($area['description']) ?>
                                    </small>
                                <?php endif; ?>
                            </td>

                            <td data-label="Order Booker">
                                <?php if (!empty($area['active_booker_name'])): ?>
                                    <span class="badge bg-light text-primary border p-2">
                                        <i class="bi bi-person-badge me-1"></i><?= htmlspecialchars($area['active_booker_name']) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-light text-muted border p-2">
                                        <i class="bi bi-dash-circle me-1"></i>Unassigned
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td data-label="Delivery Rider">
                                <?php if (!empty($area['active_rider_name'])): ?>
                                    <span class="badge bg-light text-success border p-2">
                                        <i class="bi bi-bicycle me-1"></i><?= htmlspecialchars($area['active_rider_name']) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-light text-muted border p-2">
                                        <i class="bi bi-dash-circle me-1"></i>Unassigned
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td data-label="Day / Routine">
                                <span class="badge bg-light text-dark border">
                                    <i class="bi bi-calendar-event me-1 text-primary"></i><?= htmlspecialchars($area['assigned_day']) ?>
                                </span>
                            </td>

                            <td data-label="Shops" class="text-center">
                                <span class="badge bg-success-subtle text-success border border-success-subtle py-1 px-2">
                                    <i class="bi bi-shop me-1"></i><?= $area['shop_count'] ?> Shops
                                </span>
                            </td>

                            <?php if ($coverageTrackingEnabled): ?>
                                <td data-label="Coverage & Progress">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <small class="fw-bold text-dark"><?= $area['booked_shops'] ?> / <?= $area['shop_count'] ?> Shops</small>
                                        <?php if ($area['shop_count'] > 0 && $area['coverage_pct'] >= 100): ?>
                                            <span class="badge bg-success small"><i class="bi bi-check-all me-1"></i>100% Completed</span>
                                        <?php elseif ($area['coverage_pct'] > 0): ?>
                                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle small"><?= $area['coverage_pct'] ?>% Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-light text-muted border small">0% Pending</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="progress" style="height: 6px;">
                                        <div class="progress-bar <?= $area['coverage_pct'] >= 100 ? 'bg-success' : 'bg-primary' ?>" style="width: <?= $area['coverage_pct'] ?>%;"></div>
                                    </div>
                                </td>

                                <td data-label="Booked Sales" class="text-end fw-bold text-dark">
                                    <?= formatMoney($area['period_sales']) ?>
                                </td>
                            <?php endif; ?>

                            <td data-label="Action" class="text-end pe-3">
                                <button type="button" class="btn btn-sm btn-outline-primary" data-confirm-trigger data-confirm-target="panel-assign-<?= $area['id'] ?>">
                                    <i class="bi bi-pencil-square me-1"></i> Assign Beat
                                </button>

                                <!-- Strict No-Modal Inline Assign Beat Panel -->
                                <div id="panel-assign-<?= $area['id'] ?>" class="inline-confirm-panel d-none text-start mt-2 p-3 bg-light rounded border">
                                    <div class="fw-bold small text-primary mb-2">
                                        Assign Route: <?= htmlspecialchars($area['name']) ?> (<?= htmlspecialchars($periodDisplay) ?>)
                                    </div>
                                    <form method="POST" action="<?= BASE_URL ?>/modules/areas/index.php?period=<?= urlencode($period) ?>">
                                        <?= getCsrfField() ?>
                                        <input type="hidden" name="assign_route" value="1">
                                        <input type="hidden" name="area_id" value="<?= $area['id'] ?>">

                                        <div class="mb-2">
                                            <label class="form-label small fw-semibold mb-1">Order Booker</label>
                                            <select class="form-select form-select-sm" name="assigned_order_booker_id">
                                                <option value="">-- No Booker --</option>
                                                <?php foreach ($bookers as $b): ?>
                                                    <option value="<?= $b['id'] ?>" <?= $area['active_booker_id'] == $b['id'] ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($b['full_name']) ?> (<?= htmlspecialchars($b['employee_code']) ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="mb-2">
                                            <label class="form-label small fw-semibold mb-1">Delivery Rider / Driver</label>
                                            <select class="form-select form-select-sm" name="assigned_rider_id">
                                                <option value="">-- No Rider --</option>
                                                <?php foreach ($riders as $r): ?>
                                                    <option value="<?= $r['id'] ?>" <?= $area['active_rider_id'] == $r['id'] ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($r['full_name']) ?> (<?= htmlspecialchars($r['employee_code']) ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="mb-2">
                                            <label class="form-label small fw-semibold mb-1">Scheduled Day</label>
                                            <select class="form-select form-select-sm" name="day_of_week">
                                                <option value="Flexible" <?= $area['assigned_day'] === 'Flexible' ? 'selected' : '' ?>>Flexible / Any Day</option>
                                                <option value="Monday" <?= $area['assigned_day'] === 'Monday' ? 'selected' : '' ?>>Monday</option>
                                                <option value="Tuesday" <?= $area['assigned_day'] === 'Tuesday' ? 'selected' : '' ?>>Tuesday</option>
                                                <option value="Wednesday" <?= $area['assigned_day'] === 'Wednesday' ? 'selected' : '' ?>>Wednesday</option>
                                                <option value="Thursday" <?= $area['assigned_day'] === 'Thursday' ? 'selected' : '' ?>>Thursday</option>
                                                <option value="Friday" <?= $area['assigned_day'] === 'Friday' ? 'selected' : '' ?>>Friday</option>
                                                <option value="Saturday" <?= $area['assigned_day'] === 'Saturday' ? 'selected' : '' ?>>Saturday</option>
                                            </select>
                                        </div>

                                        <div class="form-check mb-2">
                                            <input class="form-check-input" type="checkbox" name="update_permanent" id="perm_<?= $area['id'] ?>" value="1" <?= $cadence === 'fixed' ? 'checked' : '' ?>>
                                            <label class="form-check-label small text-muted" for="perm_<?= $area['id'] ?>">
                                                Also update as permanent default for this territory
                                            </label>
                                        </div>

                                        <div class="d-flex gap-1">
                                            <button type="submit" class="btn btn-sm btn-success">Save Assignment</button>
                                            <button type="button" class="btn btn-sm btn-secondary" data-confirm-cancel data-confirm-target="panel-assign-<?= $area['id'] ?>">Cancel</button>
                                        </div>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
