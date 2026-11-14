<?php
/**
 * Salary Slips Listing & Payment Execution
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('accounting', 'view');

$pdo = getDatabaseConnection();
$currUser = getCurrentUser();

$periodId = (int)($_GET['period_id'] ?? 0);
$employeeId = (int)($_GET['employee_id'] ?? 0);

// Handle Single Slip Payment POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pay_slip_id'])) {
    $slipId = (int)$_POST['pay_slip_id'];
    $payMethod = sanitize($_POST['payment_method'] ?? 'cash');
    $payDate = $_POST['payment_date'] ?? date('Y-m-d');
    $remarks = sanitize($_POST['remarks'] ?? '');

    try {
        $pdo->beginTransaction();

        $slipStmt = $pdo->prepare("
            SELECT ss.*, e.full_name as emp_name, e.employee_code, pp.month_year 
            FROM salary_slips ss
            JOIN employees e ON ss.employee_id = e.id
            JOIN payroll_periods pp ON ss.payroll_period_id = pp.id
            WHERE ss.id = ? AND ss.status = 'pending'
            LIMIT 1
        ");
        $slipStmt->execute([$slipId]);
        $slip = $slipStmt->fetch();

        if ($slip) {
            $amount = (float)$slip['net_salary'];

            // 1. Post Double-Entry Journal Entry
            $salariesAcc = getSystemAccount($pdo, 'salaries') ?: ['id' => 20];
            $creditAcc = ($payMethod === 'bank') ? getSystemAccount($pdo, 'bank') : getSystemAccount($pdo, 'cash');

            $entryNumber = generateDocNumber($pdo, 'journal_entries', 'entry_number', 'JE-SAL-');
            $jeStmt = $pdo->prepare("
                INSERT INTO `journal_entries` (`entry_number`, `entry_date`, `reference_type`, `reference_id`, `description`, `total_debit`, `total_credit`, `status`, `created_by`, `created_at`)
                VALUES (?, ?, 'salary_slip', ?, ?, ?, ?, 'posted', ?, NOW())
            ");
            $desc = "Salary disbursement for {$slip['emp_name']} ({$slip['slip_number']}) - {$slip['month_year']} via " . strtoupper($payMethod);
            $jeStmt->execute([$entryNumber, $payDate, $slipId, $desc, $amount, $amount, $currUser['id']]);
            $jeId = (int)$pdo->lastInsertId();

            // Line 1: Debit Salaries Expense
            $pdo->prepare("
                INSERT INTO `journal_entry_lines` (`journal_entry_id`, `account_id`, `debit`, `credit`, `narration`)
                VALUES (?, ?, ?, 0.00, ?)
            ")->execute([$jeId, $salariesAcc['id'], $amount, "Salary & Commission for {$slip['emp_name']}"]);

            // Line 2: Credit Cash / Bank
            $pdo->prepare("
                INSERT INTO `journal_entry_lines` (`journal_entry_id`, `account_id`, `debit`, `credit`, `narration`)
                VALUES (?, ?, 0.00, ?, ?)
            ")->execute([$jeId, $creditAcc['id'], $amount, "Paid via " . strtoupper($payMethod)]);

            // 2. Update Salary Slip
            $pdo->prepare("
                UPDATE `salary_slips` 
                SET `status` = 'paid', `payment_date` = ?, `payment_method` = ?, `remarks` = ?, `paid_by` = ?, `journal_entry_id` = ?
                WHERE id = ?
            ")->execute([$payDate, $payMethod, $remarks, $currUser['id'], $jeId, $slipId]);

            // 3. Check if all slips in this period are paid, if so update period status
            $pendingInPeriod = (int)$pdo->query("SELECT COUNT(*) FROM salary_slips WHERE payroll_period_id = {$slip['payroll_period_id']} AND status = 'pending'")->fetchColumn();
            if ($pendingInPeriod === 0) {
                $pdo->prepare("UPDATE payroll_periods SET status = 'paid' WHERE id = ?")->execute([$slip['payroll_period_id']]);
            }

            logAudit($pdo, 'payroll', $slipId, 'pay_slip', null, ['amount' => $amount, 'method' => $payMethod]);
            $pdo->commit();

            setFlashMessage('success', "Salary slip {$slip['slip_number']} for {$slip['emp_name']} marked as PAID! Journal entry posted.");
        } else {
            $pdo->rollBack();
            setFlashMessage('error', 'Salary slip not found or already paid.');
        }

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        setFlashMessage('error', 'Payment failed: ' . $e->getMessage());
    }

    $ret = BASE_URL . '/modules/payroll/slips.php' . ($periodId ? "?period_id={$periodId}" : '');
    redirect($ret);
}

// Build query
$query = "
    SELECT ss.*, pp.month_year, pp.period_code,
           e.full_name as emp_name, e.employee_code, e.role_type, e.phone as emp_phone
    FROM `salary_slips` ss
    JOIN `payroll_periods` pp ON ss.payroll_period_id = pp.id
    JOIN `employees` e ON ss.employee_id = e.id
    WHERE 1=1
";
$params = [];

if ($periodId) {
    $query .= " AND ss.payroll_period_id = ?";
    $params[] = $periodId;
}
if ($employeeId) {
    $query .= " AND ss.employee_id = ?";
    $params[] = $employeeId;
}

$query .= " ORDER BY ss.id DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$slips = $stmt->fetchAll();

// Fetch periods and employees for filters
$periods = $pdo->query("SELECT id, month_year, period_code FROM payroll_periods ORDER BY start_date DESC")->fetchAll();
$employees = $pdo->query("SELECT id, full_name, employee_code FROM employees ORDER BY full_name ASC")->fetchAll();

$pageTitle = 'Salary Slips';
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-3 gap-2">
    <div>
        <h4 class="fw-bold mb-0 text-dark"><i class="bi bi-file-earmark-text text-primary me-2"></i>Staff Salary Pay Slips</h4>
        <small class="text-muted">Individual pay slips, sales commission breakdowns & payouts</small>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/modules/payroll/generate.php" class="btn btn-sm btn-primary">
            <i class="bi bi-calculator me-1"></i> New Monthly Payroll
        </a>
        <a href="<?= BASE_URL ?>/modules/payroll/index.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Payroll Periods
        </a>
    </div>
</div>

<!-- Filters Bar -->
<div class="card border-0 shadow-sm rounded-3 mb-3">
    <div class="card-body p-3">
        <form method="GET" action="<?= BASE_URL ?>/modules/payroll/slips.php" class="row g-2 align-items-center">
            <div class="col-6 col-md-5">
                <select class="form-select form-select-sm" name="period_id">
                    <option value="">All Payroll Periods</option>
                    <?php foreach ($periods as $p): ?>
                        <option value="<?= $p['id'] ?>" <?= $periodId == $p['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($p['month_year']) ?> (<?= htmlspecialchars($p['period_code']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-5">
                <select class="form-select form-select-sm" name="employee_id">
                    <option value="">All Staff Members</option>
                    <?php foreach ($employees as $emp): ?>
                        <option value="<?= $emp['id'] ?>" <?= $employeeId == $emp['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($emp['full_name']) ?> (<?= htmlspecialchars($emp['employee_code']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-2">
                <button type="submit" class="btn btn-sm btn-secondary w-100">
                    <i class="bi bi-funnel me-1"></i> Filter Slips
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Salary Slips Table -->
<div class="card border-0 shadow-sm rounded-3">
    <div class="card-body p-0">
        <?php if (empty($slips)): ?>
            <div class="p-4 text-center text-muted">No salary slips found matching your filter criteria.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover table-responsive-card align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Slip # / Period</th>
                            <th>Staff Member</th>
                            <th class="text-end">Base Salary</th>
                            <th class="text-end">Commission</th>
                            <th class="text-end">Net Pay</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($slips as $s): ?>
                            <tr>
                                <td data-label="Slip #">
                                    <a href="<?= BASE_URL ?>/modules/payroll/slip_view.php?id=<?= $s['id'] ?>" class="fw-bold text-primary text-decoration-none">
                                        <?= htmlspecialchars($s['slip_number']) ?>
                                    </a>
                                    <div class="text-muted" style="font-size: 0.72rem;"><?= htmlspecialchars($s['month_year']) ?></div>
                                </td>
                                <td data-label="Employee">
                                    <div class="fw-bold text-dark"><?= htmlspecialchars($s['emp_name']) ?></div>
                                    <small class="text-muted"><code><?= htmlspecialchars($s['employee_code']) ?></code> · <?= htmlspecialchars(str_replace('_', ' ', $s['role_type'])) ?></small>
                                </td>
                                <td data-label="Base Salary" class="num-cell">
                                    <?= number_format((float)$s['base_salary'], 2) ?>
                                </td>
                                <td data-label="Commission" class="num-cell text-success fw-semibold">
                                    <?= (float)$s['commission_amount'] > 0 ? '+' . number_format((float)$s['commission_amount'], 2) : '-' ?>
                                </td>
                                <td data-label="Net Pay" class="num-cell fw-bold text-primary fs-6">
                                    <?= DEFAULT_CURRENCY_SYMBOL ?> <?= number_format((float)$s['net_salary'], 2) ?>
                                </td>
                                <td data-label="Status">
                                    <span class="badge <?= $s['status'] === 'paid' ? 'bg-success' : 'bg-warning text-dark' ?>">
                                        <?= strtoupper($s['status']) ?>
                                    </span>
                                    <?php if ($s['status'] === 'paid' && !empty($s['payment_date'])): ?>
                                        <div class="text-muted" style="font-size: 0.68rem;"><?= formatDate($s['payment_date']) ?> via <?= strtoupper($s['payment_method']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Actions" class="text-end">
                                    <div class="d-inline-flex gap-1">
                                        <a href="<?= BASE_URL ?>/modules/payroll/slip_view.php?id=<?= $s['id'] ?>" class="btn btn-sm btn-outline-primary" title="View & Print Official Pay Slip">
                                            <i class="bi bi-file-earmark-ruled"></i> Slip
                                        </a>
                                        <?php if ($s['status'] === 'pending'): ?>
                                            <form method="POST" action="<?= BASE_URL ?>/modules/payroll/slips.php<?= $periodId ? "?period_id={$periodId}" : '' ?>" style="display:inline;">
                                                <input type="hidden" name="pay_slip_id" value="<?= $s['id'] ?>">
                                                <input type="hidden" name="payment_method" value="cash">
                                                <input type="hidden" name="payment_date" value="<?= date('Y-m-d') ?>">
                                                <button type="submit" class="btn btn-sm btn-success" title="Mark as Paid (Disburse Cash & Post Entry)">
                                                    <i class="bi bi-check2-circle"></i> Pay
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