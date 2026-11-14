<?php
/**
 * Generate Monthly Payroll for Staff
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('accounting', 'create');

$pdo = getDatabaseConnection();
$currUser = getCurrentUser();

$selectedMonth = sanitize($_GET['month'] ?? date('Y-m'));
$startDate = date('Y-m-01', strtotime($selectedMonth . '-01'));
$endDate = date('Y-m-t', strtotime($selectedMonth . '-01'));
$monthYear = date('F Y', strtotime($selectedMonth . '-01'));
$periodCode = $selectedMonth;

// Check if this period code has already been generated
$existingPeriod = $pdo->query("SELECT * FROM payroll_periods WHERE period_code = '{$periodCode}' LIMIT 1")->fetch();

// Handle Final Generation POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'confirm_generate') {
    if ($existingPeriod) {
        setFlashMessage('error', "Payroll for period {$monthYear} has already been processed.");
        redirect(BASE_URL . '/modules/payroll/slips.php?period_id=' . $existingPeriod['id']);
    }

    $empIds = $_POST['emp_id'] ?? [];
    $baseSalaries = $_POST['base_salary'] ?? [];
    $commissions = $_POST['commission'] ?? [];
    $bonuses = $_POST['bonus'] ?? [];
    $allowances = $_POST['allowance'] ?? [];
    $deductions = $_POST['deduction'] ?? [];
    $orderCounts = $_POST['order_count'] ?? [];
    $salesVolumes = $_POST['sales_volume'] ?? [];

    $totalGross = 0.00;
    $totalComm = 0.00;
    $totalDed = 0.00;
    $totalNet = 0.00;

    try {
        $pdo->beginTransaction();

        // Calculate period totals
        foreach ($empIds as $eId) {
            $base = (float)($baseSalaries[$eId] ?? 0);
            $comm = (float)($commissions[$eId] ?? 0);
            $bon = (float)($bonuses[$eId] ?? 0);
            $allw = (float)($allowances[$eId] ?? 0);
            $ded = (float)($deductions[$eId] ?? 0);
            $net = max(0, ($base + $comm + $bon + $allw) - $ded);

            $totalGross += $base;
            $totalComm += $comm;
            $totalDed += $ded;
            $totalNet += $net;
        }

        // Insert Payroll Period
        $pStmt = $pdo->prepare("
            INSERT INTO `payroll_periods` (
                `period_code`, `month_year`, `start_date`, `end_date`, 
                `total_gross_salary`, `total_commissions`, `total_deductions`, `total_net_salary`, 
                `status`, `processed_by`, `processed_at`, `created_at`
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'approved', ?, NOW(), NOW())
        ");
        $pStmt->execute([
            $periodCode, $monthYear, $startDate, $endDate,
            $totalGross, $totalComm, $totalDed, $totalNet, $currUser['id']
        ]);
        $periodId = (int)$pdo->lastInsertId();

        // Insert individual Salary Slips
        $slipStmt = $pdo->prepare("
            INSERT INTO `salary_slips` (
                `payroll_period_id`, `slip_number`, `employee_id`, `base_salary`, 
                `commission_amount`, `bonus_amount`, `allowances`, `deductions`, `net_salary`, 
                `orders_count`, `sales_volume`, `status`, `created_at`
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
        ");

        $seq = 1;
        foreach ($empIds as $eId) {
            $base = (float)($baseSalaries[$eId] ?? 0);
            $comm = (float)($commissions[$eId] ?? 0);
            $bon = (float)($bonuses[$eId] ?? 0);
            $allw = (float)($allowances[$eId] ?? 0);
            $ded = (float)($deductions[$eId] ?? 0);
            $net = max(0, ($base + $comm + $bon + $allw) - $ded);
            $oCount = (int)($orderCounts[$eId] ?? 0);
            $sVol = (float)($salesVolumes[$eId] ?? 0);

            $slipNumber = 'SLIP-' . str_replace('-', '', $periodCode) . '-' . str_pad($seq++, 3, '0', STR_PAD_LEFT);

            $slipStmt->execute([
                $periodId, $slipNumber, $eId, $base,
                $comm, $bon, $allw, $ded, $net,
                $oCount, $sVol
            ]);
        }

        logAudit($pdo, 'payroll', $periodId, 'generate', null, ['period' => $periodCode, 'net_total' => $totalNet]);
        $pdo->commit();

        setFlashMessage('success', "Payroll for {$monthYear} generated successfully! " . count($empIds) . " pay slips created.");
        redirect(BASE_URL . '/modules/payroll/slips.php?period_id=' . $periodId);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        setFlashMessage('error', 'Payroll generation failed: ' . $e->getMessage());
        redirect(BASE_URL . '/modules/payroll/generate.php?month=' . $selectedMonth);
    }
}

// Fetch all active employees
$employees = $pdo->query("
    SELECT e.*, u.username
    FROM `employees` e
    LEFT JOIN `users` u ON e.user_id = u.id
    WHERE e.status = 'active'
    ORDER BY e.role_type ASC, e.full_name ASC
")->fetchAll();

// Pre-calculate sales & commissions for each employee during this month
$employeeData = [];
foreach ($employees as $emp) {
    $empId = $emp['id'];
    $baseSalary = (float)$emp['salary'];
    $commType = $emp['commission_type'];
    $commRate = (float)$emp['commission_rate'];
    $calculatedComm = 0.00;
    $ordersCount = 0;
    $salesVolume = 0.00;

    if ($commType === 'percentage_sales') {
        $stmt = $pdo->prepare("
            SELECT COUNT(id) as o_count, COALESCE(SUM(grand_total), 0) as total_vol 
            FROM `sales_orders` 
            WHERE `order_booker_id` = ? AND `order_date` BETWEEN ? AND ? AND `status` != 'cancelled'
        ");
        $stmt->execute([$empId, $startDate, $endDate]);
        $res = $stmt->fetch();
        $ordersCount = (int)$res['o_count'];
        $salesVolume = (float)$res['total_vol'];
        $calculatedComm = round(($salesVolume * $commRate) / 100, 2);

    } elseif ($commType === 'fixed_per_order') {
        $stmt = $pdo->prepare("
            SELECT COUNT(id) as o_count, COALESCE(SUM(grand_total), 0) as total_vol 
            FROM `sales_orders` 
            WHERE `order_booker_id` = ? AND `order_date` BETWEEN ? AND ? AND `status` != 'cancelled'
        ");
        $stmt->execute([$empId, $startDate, $endDate]);
        $res = $stmt->fetch();
        $ordersCount = (int)$res['o_count'];
        $salesVolume = (float)$res['total_vol'];
        $calculatedComm = round($ordersCount * $commRate, 2);

    } elseif ($commType === 'percentage_collected') {
        $stmt = $pdo->prepare("
            SELECT COUNT(id) as p_count, COALESCE(SUM(amount), 0) as total_coll 
            FROM `payments` 
            WHERE `collected_by` = ? AND `payment_date` BETWEEN ? AND ? AND `status` = 'posted'
        ");
        $stmt->execute([$empId, $startDate, $endDate]);
        $res = $stmt->fetch();
        $salesVolume = (float)$res['total_coll'];
        $calculatedComm = round(($salesVolume * $commRate) / 100, 2);
    }

    $employeeData[] = [
        'emp' => $emp,
        'base_salary' => $baseSalary,
        'commission' => $calculatedComm,
        'orders_count' => $ordersCount,
        'sales_volume' => $salesVolume
    ];
}

$pageTitle = 'Generate Monthly Payroll - ' . $monthYear;
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-3 gap-2">
    <div>
        <h4 class="fw-bold mb-0 text-dark"><i class="bi bi-calculator text-primary me-2"></i>Process Monthly Staff Payroll</h4>
        <small class="text-muted">Target Month: <span class="fw-bold text-dark"><?= $monthYear ?></span> (<?= formatDate($startDate) ?> to <?= formatDate($endDate) ?>)</small>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/modules/payroll/index.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back to Payroll
        </a>
    </div>
</div>

<!-- Month Selector Card -->
<div class="card border-0 shadow-sm rounded-3 mb-3">
    <div class="card-body p-3">
        <form method="GET" action="<?= BASE_URL ?>/modules/payroll/generate.php" class="row g-2 align-items-end">
            <div class="col-8 col-md-4">
                <label class="form-label small fw-semibold">Select Payroll Month</label>
                <input type="month" class="form-control form-control-sm" name="month" value="<?= $selectedMonth ?>">
            </div>
            <div class="col-4 col-md-2">
                <button type="submit" class="btn btn-sm btn-secondary w-100">
                    <i class="bi bi-arrow-repeat me-1"></i> Recalculate
                </button>
            </div>
            <?php if ($existingPeriod): ?>
            <div class="col-12 col-md-6 text-md-end">
                <span class="badge bg-success p-2">
                    <i class="bi bi-check2-circle me-1"></i> Already Processed (Status: <?= strtoupper($existingPeriod['status']) ?>)
                </span>
                <a href="<?= BASE_URL ?>/modules/payroll/slips.php?period_id=<?= $existingPeriod['id'] ?>" class="btn btn-sm btn-outline-primary ms-2">
                    View Slips
                </a>
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<?php if ($existingPeriod): ?>
    <div class="alert alert-info shadow-sm rounded-3">
        <i class="bi bi-info-circle me-2"></i>
        Payroll for <strong><?= $monthYear ?></strong> has already been generated with <?= formatMoney($existingPeriod['total_net_salary']) ?> net total. You can view or disburse salary slips using the link above.
    </div>
<?php else: ?>
    <!-- Payroll Generation Table Form -->
    <form method="POST" action="<?= BASE_URL ?>/modules/payroll/generate.php?month=<?= $selectedMonth ?>">
        <input type="hidden" name="action" value="confirm_generate">

        <div class="card border-0 shadow-sm rounded-3 mb-4">
            <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-people me-2 text-primary"></i>Eligible Employees & Commission Calculation</h6>
                <span class="badge bg-primary"><?= count($employeeData) ?> Staff Members</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="payrollDraftTable">
                        <thead class="table-light small">
                            <tr>
                                <th>Employee / Role</th>
                                <th style="width: 140px;">Base Salary (Rs.)</th>
                                <th style="width: 150px;">Sales / Commission</th>
                                <th style="width: 120px;">Bonus</th>
                                <th style="width: 120px;">Allowances</th>
                                <th style="width: 120px;">Deductions</th>
                                <th class="text-end" style="width: 140px;">Net Payable (Rs.)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($employeeData as $row): 
                                $emp = $row['emp'];
                                $eId = $emp['id'];
                                $netEst = max(0, $row['base_salary'] + $row['commission']);
                            ?>
                                <tr>
                                    <td>
                                        <input type="hidden" name="emp_id[]" value="<?= $eId ?>">
                                        <input type="hidden" name="order_count[<?= $eId ?>]" value="<?= $row['orders_count'] ?>">
                                        <input type="hidden" name="sales_volume[<?= $eId ?>]" value="<?= $row['sales_volume'] ?>">
                                        <div class="fw-bold text-dark"><?= htmlspecialchars($emp['full_name']) ?></div>
                                        <small class="text-muted"><code><?= htmlspecialchars($emp['employee_code']) ?></code> · <?= htmlspecialchars($emp['designation']) ?></small>
                                        <div class="badge bg-light text-dark border" style="font-size: 0.68rem;"><?= htmlspecialchars(str_replace('_', ' ', $emp['role_type'])) ?></div>
                                    </td>
                                    <td>
                                        <input type="number" step="0.01" class="form-control form-control-sm base-sal-input" name="base_salary[<?= $eId ?>]" value="<?= $row['base_salary'] ?>" data-emp="<?= $eId ?>" oninput="recalcRow(<?= $eId ?>)">
                                    </td>
                                    <td>
                                        <input type="number" step="0.01" class="form-control form-control-sm comm-input text-success fw-semibold" name="commission[<?= $eId ?>]" value="<?= $row['commission'] ?>" data-emp="<?= $eId ?>" oninput="recalcRow(<?= $eId ?>)">
                                        <?php if ($row['sales_volume'] > 0): ?>
                                            <div class="text-muted" style="font-size: 0.68rem;">Vol: Rs. <?= number_format($row['sales_volume'], 0) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <input type="number" step="0.01" class="form-control form-control-sm bonus-input" name="bonus[<?= $eId ?>]" value="0.00" data-emp="<?= $eId ?>" oninput="recalcRow(<?= $eId ?>)">
                                    </td>
                                    <td>
                                        <input type="number" step="0.01" class="form-control form-control-sm allw-input" name="allowance[<?= $eId ?>]" value="0.00" data-emp="<?= $eId ?>" oninput="recalcRow(<?= $eId ?>)">
                                    </td>
                                    <td>
                                        <input type="number" step="0.01" class="form-control form-control-sm text-danger ded-input" name="deduction[<?= $eId ?>]" value="0.00" data-emp="<?= $eId ?>" oninput="recalcRow(<?= $eId ?>)">
                                    </td>
                                    <td class="text-end">
                                        <span class="fw-bold text-primary fs-6 net-display" id="net_disp_<?= $eId ?>">
                                            <?= number_format($netEst, 2) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer bg-white py-3 d-flex justify-content-between align-items-center">
                <a href="<?= BASE_URL ?>/modules/payroll/index.php" class="btn btn-outline-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary px-4">
                    <i class="bi bi-check2-circle me-1"></i> Confirm & Generate Payroll Slips
                </button>
            </div>
        </div>
    </form>

    <script>
    function recalcRow(eId) {
        const base = parseFloat(document.querySelector(`input[name="base_salary[${eId}]"]`)?.value || 0);
        const comm = parseFloat(document.querySelector(`input[name="commission[${eId}]"]`)?.value || 0);
        const bon = parseFloat(document.querySelector(`input[name="bonus[${eId}]"]`)?.value || 0);
        const allw = parseFloat(document.querySelector(`input[name="allowance[${eId}]"]`)?.value || 0);
        const ded = parseFloat(document.querySelector(`input[name="deduction[${eId}]"]`)?.value || 0);

        const net = Math.max(0, (base + comm + bon + allw) - ded);
        const disp = document.getElementById(`net_disp_${eId}`);
        if (disp) {
            disp.innerText = net.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
    }
    </script>
<?php endif; ?>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>