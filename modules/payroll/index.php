<?php
/**
 * Salary & Payroll Management Dashboard
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('accounting', 'view'); // Accountants & Admins

$pdo = getDatabaseConnection();
$currUser = getCurrentUser();

// Handle Disburse/Pay Whole Period POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['disburse_period_id'])) {
    $periodId = (int)$_POST['disburse_period_id'];
    $payMethod = sanitize($_POST['payment_method'] ?? 'cash');

    try {
        $pdo->beginTransaction();

        $pStmt = $pdo->prepare("SELECT * FROM payroll_periods WHERE id = ? LIMIT 1");
        $pStmt->execute([$periodId]);
        $period = $pStmt->fetch();

        if ($period && $period['status'] !== 'paid') {
            $totalNet = (float)$period['total_net_salary'];
            $today = date('Y-m-d');

            // 1. Mark all pending slips in this period as paid
            $pdo->prepare("
                UPDATE `salary_slips` 
                SET `status` = 'paid', `payment_date` = ?, `payment_method` = ?, `paid_by` = ?
                WHERE `payroll_period_id` = ? AND `status` = 'pending'
            ")->execute([$today, $payMethod, $currUser['id'], $periodId]);

            // 2. Mark period as paid
            $pdo->prepare("
                UPDATE `payroll_periods` 
                SET `status` = 'paid', `processed_by` = ?, `processed_at` = NOW() 
                WHERE id = ?
            ")->execute([$currUser['id'], $periodId]);

            // 3. Post Double-Entry Journal Entry for Payroll Disbursement
            $salariesAcc = getSystemAccount($pdo, 'salaries') ?: ['id' => 20];
            $creditAcc = ($payMethod === 'bank') ? getSystemAccount($pdo, 'bank') : getSystemAccount($pdo, 'cash');
            $paymentAccountId = (int)($_POST['payment_account_id'] ?? 0);
            if ($paymentAccountId > 0) {
                $accStmt = $pdo->prepare("SELECT id, name, code FROM `chart_of_accounts` WHERE id = ? AND is_active = 1");
                $accStmt->execute([$paymentAccountId]);
                $customAcc = $accStmt->fetch();
                if ($customAcc) {
                    $creditAcc = $customAcc;
                }
            }

            $entryNumber = generateDocNumber($pdo, 'journal_entries', 'entry_number', 'JE-PAY-');
            $jeStmt = $pdo->prepare("
                INSERT INTO `journal_entries` (`entry_number`, `entry_date`, `reference_type`, `reference_id`, `description`, `total_debit`, `total_credit`, `status`, `created_by`, `created_at`)
                VALUES (?, ?, 'payroll_period', ?, ?, ?, ?, 'posted', ?, NOW())
            ");
            $desc = "Payroll disbursement for {$period['month_year']} via " . strtoupper($payMethod);
            $jeStmt->execute([$entryNumber, $today, $periodId, $desc, $totalNet, $totalNet, $currUser['id']]);
            $jeId = (int)$pdo->lastInsertId();

            // Line 1: Debit Salaries Expense
            $pdo->prepare("
                INSERT INTO `journal_entry_lines` (`journal_entry_id`, `account_id`, `debit`, `credit`, `narration`)
                VALUES (?, ?, ?, 0.00, ?)
            ")->execute([$jeId, $salariesAcc['id'], $totalNet, "Staff Salaries & Commissions for {$period['month_year']}"]);

            // Line 2: Credit Cash / Bank
            $pdo->prepare("
                INSERT INTO `journal_entry_lines` (`journal_entry_id`, `account_id`, `debit`, `credit`, `narration`)
                VALUES (?, ?, 0.00, ?, ?)
            ")->execute([$jeId, $creditAcc['id'], $totalNet, "Payroll payout via " . strtoupper($payMethod)]);

            logAudit($pdo, 'payroll', $periodId, 'disburse', null, ['amount' => $totalNet, 'method' => $payMethod]);
            $pdo->commit();

            setFlashMessage('success', "Payroll for {$period['month_year']} successfully disbursed and posted to General Ledger!");
        } else {
            $pdo->rollBack();
            setFlashMessage('error', 'Period not found or already paid.');
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        setFlashMessage('error', 'Disbursement error: ' . $e->getMessage());
    }
    redirect(BASE_URL . '/modules/payroll/index.php');
}

// Summary Statistics
$totalPayrollPaid = (float)$pdo->query("SELECT COALESCE(SUM(total_net_salary), 0) FROM payroll_periods WHERE status = 'paid'")->fetchColumn();
$thisMonthCode = date('Y-m');
$thisMonthPaid = (float)$pdo->query("SELECT COALESCE(SUM(total_net_salary), 0) FROM payroll_periods WHERE period_code = '{$thisMonthCode}' AND status = 'paid'")->fetchColumn();
$totalEmployees = (int)$pdo->query("SELECT COUNT(*) FROM employees WHERE status = 'active'")->fetchColumn();
$pendingSlipsCount = (int)$pdo->query("SELECT COUNT(*) FROM salary_slips WHERE status = 'pending'")->fetchColumn();

// Fetch all payroll periods
$periods = $pdo->query("
    SELECT pp.*, u.full_name as processor_name,
           (SELECT COUNT(*) FROM salary_slips WHERE payroll_period_id = pp.id) as total_slips,
           (SELECT COUNT(*) FROM salary_slips WHERE payroll_period_id = pp.id AND status = 'paid') as paid_slips
    FROM `payroll_periods` pp
    LEFT JOIN `users` u ON pp.processed_by = u.id
    ORDER BY pp.start_date DESC
")->fetchAll();

$pageTitle = 'Salary & Payroll Management';
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-3 gap-2">
    <div>
        <h4 class="fw-bold mb-0 text-dark"><i class="bi bi-wallet2 text-primary me-2"></i>Staff Salary & Payroll</h4>
        <small class="text-muted">Monthly salary calculations, sales commissions, pay slips & double-entry ledger payouts</small>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/modules/payroll/generate.php" class="btn btn-sm btn-primary">
            <i class="bi bi-calculator me-1"></i> Process / Generate Monthly Payroll
        </a>
        <a href="<?= BASE_URL ?>/modules/payroll/slips.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-receipt me-1"></i> All Salary Slips
        </a>
    </div>
</div>

<!-- KPI Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <span class="text-muted small fw-semibold">Total Paid All Time</span>
            <h5 class="fw-bold mt-1 mb-0 text-dark"><?= formatMoney($totalPayrollPaid) ?></h5>
            <small class="text-muted" style="font-size: 0.72rem;">All processed payrolls</small>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <span class="text-muted small fw-semibold">Disbursed This Month</span>
            <h5 class="fw-bold mt-1 mb-0 text-success"><?= formatMoney($thisMonthPaid) ?></h5>
            <small class="text-muted" style="font-size: 0.72rem;"><?= date('F Y') ?></small>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <span class="text-muted small fw-semibold">Active Staff on Payroll</span>
            <h5 class="fw-bold mt-1 mb-0 text-primary"><?= $totalEmployees ?></h5>
            <small class="text-muted" style="font-size: 0.72rem;">Eligible for salary</small>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <span class="text-muted small fw-semibold">Pending Slips</span>
            <h5 class="fw-bold mt-1 mb-0 text-warning"><?= $pendingSlipsCount ?></h5>
            <small class="text-muted" style="font-size: 0.72rem;">Awaiting disbursement</small>
        </div>
    </div>
</div>

<!-- Payroll Periods Table -->
<div class="card border-0 shadow-sm rounded-3">
    <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
        <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-calendar3 me-2 text-primary"></i>Processed Payroll Periods</h6>
    </div>
    <div class="card-body p-0">
        <?php if (empty($periods)): ?>
            <div class="p-4 text-center text-muted">
                <i class="bi bi-wallet-fill fs-1 text-secondary opacity-50 d-block mb-2"></i>
                No payroll periods generated yet. Click "Process / Generate Monthly Payroll" to start.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover table-responsive-card align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Payroll Period / Month</th>
                            <th>Date Range</th>
                            <th class="text-end">Base Salary</th>
                            <th class="text-end">Commissions</th>
                            <th class="text-end">Total Net Payable</th>
                            <th>Slips & Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($periods as $p): ?>
                            <tr>
                                <td data-label="Period">
                                    <span class="fw-bold text-dark fs-6"><?= htmlspecialchars($p['month_year']) ?></span>
                                    <div class="text-muted" style="font-size: 0.72rem;">Code: <code><?= htmlspecialchars($p['period_code']) ?></code></div>
                                </td>
                                <td data-label="Date Range">
                                    <div class="small text-dark"><?= formatDate($p['start_date']) ?> to <?= formatDate($p['end_date']) ?></div>
                                </td>
                                <td data-label="Base Salary" class="num-cell">
                                    <?= number_format((float)$p['total_gross_salary'], 2) ?>
                                </td>
                                <td data-label="Commissions" class="num-cell text-success fw-semibold">
                                    +<?= number_format((float)$p['total_commissions'], 2) ?>
                                </td>
                                <td data-label="Net Payable" class="num-cell fw-bold text-primary fs-6">
                                    <?= DEFAULT_CURRENCY_SYMBOL ?> <?= number_format((float)$p['total_net_salary'], 2) ?>
                                </td>
                                <td data-label="Status">
                                    <?php
                                    $sBadge = match ($p['status']) {
                                        'paid' => 'bg-success',
                                        'approved' => 'bg-info text-dark',
                                        default => 'bg-warning text-dark'
                                    };
                                    ?>
                                    <span class="badge <?= $sBadge ?>"><?= strtoupper($p['status']) ?></span>
                                    <div class="small text-muted mt-1"><?= $p['paid_slips'] ?> of <?= $p['total_slips'] ?> slips paid</div>
                                </td>
                                <td data-label="Actions" class="text-end">
                                    <div class="d-inline-flex gap-1">
                                        <a href="<?= BASE_URL ?>/modules/payroll/slips.php?period_id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-primary" title="View Individual Salary Slips">
                                            <i class="bi bi-file-earmark-text"></i> Slips (<?= $p['total_slips'] ?>)
                                        </a>
                                        <?php if ($p['status'] !== 'paid'): ?>
                                            <form method="POST" action="<?= BASE_URL ?>/modules/payroll/index.php" style="display:inline;">
                                                <input type="hidden" name="disburse_period_id" value="<?= $p['id'] ?>">
                                                <input type="hidden" name="payment_method" value="cash">
                                                <button type="submit" class="btn btn-sm btn-success" title="Disburse Payroll & Post Journal Entry">
                                                    <i class="bi bi-cash-stack me-1"></i> Disburse All
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