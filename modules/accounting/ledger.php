<?php
/**
 * General Ledger & Trial Balance
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('accounting', 'view');

$pdo = getDatabaseConnection();

// Fetch Chart of Accounts with Total Debits and Total Credits
$accounts = $pdo->query("
    SELECT coa.*,
           COALESCE(SUM(jel.debit), 0.00) as total_debit,
           COALESCE(SUM(jel.credit), 0.00) as total_credit
    FROM `chart_of_accounts` coa
    LEFT JOIN `journal_entry_lines` jel ON coa.id = jel.account_id
    WHERE coa.is_active = 1
    GROUP BY coa.id, coa.code, coa.name, coa.group_type
    ORDER BY coa.code ASC
")->fetchAll();

$totalTrialDebit = 0.00;
$totalTrialCredit = 0.00;

foreach ($accounts as $acc) {
    $d = (float)$acc['total_debit'];
    $c = (float)$acc['total_credit'];
    $group = $acc['group_type'];

    // In double-entry:
    // Assets, Cost of Sales, Expenses normally carry DEBIT balances
    // Liabilities, Equity, Income normally carry CREDIT balances
    if (in_array($group, ['Asset', 'Expense', 'Cost_Of_Sales', 'Contra_Income'])) {
        $net = $d - $c;
        if ($net >= 0) {
            $totalTrialDebit += $net;
        } else {
            $totalTrialCredit += abs($net);
        }
    } else {
        $net = $c - $d;
        if ($net >= 0) {
            $totalTrialCredit += $net;
        } else {
            $totalTrialDebit += abs($net);
        }
    }
}

$isBalanced = abs($totalTrialDebit - $totalTrialCredit) < 0.01;

$pageTitle = 'General Ledger & Trial Balance';
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-3 gap-2">
    <div>
        <h4 class="fw-bold mb-0 text-dark"><i class="bi bi-book text-primary me-2"></i>General Ledger & Trial Balance</h4>
        <small class="text-muted">Master Double-Entry Trial Balance & Ledger Postings</small>
    </div>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print();">
            <i class="bi bi-printer me-1"></i> Print Trial Balance
        </button>
        <a href="<?= BASE_URL ?>/modules/accounting/index.php" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-journal-text me-1"></i> Journal Register
        </a>
    </div>
</div>

<!-- Trial Balance Verification Banner -->
<div class="card border-0 shadow-sm rounded-3 mb-3">
    <div class="card-body p-3">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-center gap-2">
            <div>
                <span class="text-muted small fw-semibold">Accounting Verification Check:</span>
                <div class="d-flex align-items-center gap-2 mt-1">
                    <?php if ($isBalanced): ?>
                        <span class="badge bg-success p-2 fs-6"><i class="bi bi-check2-all me-1"></i>TRIAL BALANCE BALANCED</span>
                        <span class="text-success small fw-semibold">Debit Equals Credit Equality Confirmed</span>
                    <?php else: ?>
                        <span class="badge bg-danger p-2 fs-6"><i class="bi bi-exclamation-triangle-fill me-1"></i>DISCREPANCY DETECTED</span>
                        <span class="text-danger small fw-semibold">Difference: <?= formatMoney(abs($totalTrialDebit - $totalTrialCredit)) ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="d-flex gap-3 text-end">
                <div>
                    <span class="text-muted small">Total Debits:</span>
                    <h5 class="fw-bold text-danger mb-0"><?= formatMoney($totalTrialDebit) ?></h5>
                </div>
                <div>
                    <span class="text-muted small">Total Credits:</span>
                    <h5 class="fw-bold text-success mb-0"><?= formatMoney($totalTrialCredit) ?></h5>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Accounts List & Trial Balance -->
<div class="card border-0 shadow-sm rounded-3">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-bordered table-striped table-hover align-middle mb-0">
                <thead class="table-light small">
                    <tr>
                        <th style="width: 110px;">Code</th>
                        <th>Account Description</th>
                        <th>Account Classification</th>
                        <th class="text-end" style="width: 140px;">Debit Balance (Rs.)</th>
                        <th class="text-end" style="width: 140px;">Credit Balance (Rs.)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($accounts as $acc): ?>
                        <?php
                        $d = (float)$acc['total_debit'];
                        $c = (float)$acc['total_credit'];
                        $group = $acc['group_type'];

                        $debitBal = 0.00;
                        $creditBal = 0.00;

                        if (in_array($group, ['Asset', 'Expense', 'Cost_Of_Sales', 'Contra_Income'])) {
                            $net = $d - $c;
                            if ($net >= 0) {
                                $debitBal = $net;
                            } else {
                                $creditBal = abs($net);
                            }
                        } else {
                            $net = $c - $d;
                            if ($net >= 0) {
                                $creditBal = $net;
                            } else {
                                $debitBal = abs($net);
                            }
                        }

                        // Skip accounts with zero activity for cleaner view
                        if ($debitBal == 0 && $creditBal == 0) {
                            continue;
                        }
                        ?>
                        <tr>
                            <td class="font-monospace fw-bold text-dark"><?= htmlspecialchars($acc['code']) ?></td>
                            <td class="fw-semibold text-dark"><?= htmlspecialchars($acc['name']) ?></td>
                            <td>
                                <span class="badge bg-light text-dark border"><?= htmlspecialchars($acc['group_type']) ?></span>
                            </td>
                            <td class="text-end fw-semibold text-danger">
                                <?= $debitBal > 0 ? formatMoney($debitBal, false) : '-' ?>
                            </td>
                            <td class="text-end fw-semibold text-success">
                                <?= $creditBal > 0 ? formatMoney($creditBal, false) : '-' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="table-light fw-bold fs-6">
                    <tr>
                        <td colspan="3" class="text-end">Final Balanced Totals:</td>
                        <td class="text-end text-danger"><?= formatMoney($totalTrialDebit, false) ?></td>
                        <td class="text-end text-success"><?= formatMoney($totalTrialCredit, false) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
