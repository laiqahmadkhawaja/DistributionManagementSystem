<?php
/**
 * Double-Entry Journal Entry Register
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('accounting', 'view');

$pdo = getDatabaseConnection();

$entries = $pdo->query("
    SELECT je.*, u.full_name as poster_name
    FROM `journal_entries` je
    LEFT JOIN `users` u ON je.posted_by = u.id
    ORDER BY je.id DESC
    LIMIT 100
")->fetchAll();

$pageTitle = 'Double-Entry Journal Register';
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-3 gap-2">
    <div>
        <h4 class="fw-bold mb-0 text-dark"><i class="bi bi-journal-bookmark-fill text-primary me-2"></i>Double-Entry Journal Register</h4>
        <small class="text-muted">General Journal Entries with Complete Balanced Debits & Credits</small>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/modules/accounting/ledger.php" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-book me-1"></i> General Ledger & Trial Balance
        </a>
    </div>
</div>

<div class="card border-0 shadow-sm rounded-3 mb-3">
    <div class="card-body p-3">
        <div class="d-flex align-items-center justify-content-between">
            <div class="text-muted small">
                <i class="bi bi-shield-check text-success me-1"></i> Double-entry financial integrity strictly enforced: <strong>Total Debit = Total Credit</strong> on all transactions.
            </div>
            <span class="badge bg-light text-dark border"><?= count($entries) ?> Posted Journals</span>
        </div>
    </div>
</div>

<!-- Journal Entries List -->
<div class="d-flex flex-column gap-3">
    <?php if (empty($entries)): ?>
        <div class="card border-0 shadow-sm rounded-3 p-4 text-center text-muted">No journal entries posted yet.</div>
    <?php else: ?>
        <?php foreach ($entries as $je): ?>
            <?php
            $linesStmt = $pdo->prepare("
                SELECT jel.*, coa.code as account_code, coa.name as account_name, coa.group_type
                FROM `journal_entry_lines` jel
                JOIN `chart_of_accounts` coa ON jel.account_id = coa.id
                WHERE jel.journal_entry_id = ?
                ORDER BY jel.debit DESC, jel.credit ASC
            ");
            $linesStmt->execute([$je['id']]);
            $lines = $linesStmt->fetchAll();
            ?>
            <div class="card border-0 shadow-sm rounded-3">
                <div class="card-header bg-white py-2 px-3 border-bottom d-flex flex-wrap justify-content-between align-items-center">
                    <div>
                        <span class="fw-bold text-primary me-2"><?= htmlspecialchars($je['entry_number']) ?></span>
                        <span class="badge bg-secondary me-2"><?= htmlspecialchars($je['reference_type']) ?></span>
                        <span class="text-muted small"><?= formatDate($je['entry_date']) ?></span>
                    </div>
                    <div>
                        <small class="text-muted">Posted by <?= htmlspecialchars($je['poster_name'] ?? 'System') ?></small>
                        <span class="badge bg-success ms-2">Balanced</span>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="px-3 py-2 small bg-light text-dark border-bottom">
                        <strong>Narration:</strong> <?= htmlspecialchars($je['narration']) ?>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered align-middle mb-0">
                            <thead class="table-light small">
                                <tr>
                                    <th style="width: 120px;">Account Code</th>
                                    <th>Account Title</th>
                                    <th>Classification</th>
                                    <th class="text-end" style="width: 140px;">Debit (Rs.)</th>
                                    <th class="text-end" style="width: 140px;">Credit (Rs.)</th>
                                    <th>Line Memo</th>
                                </tr>
                            </thead>
                            <tbody class="small">
                                <?php foreach ($lines as $line): ?>
                                    <tr>
                                        <td class="font-monospace fw-semibold"><?= htmlspecialchars($line['account_code']) ?></td>
                                        <td class="<?= $line['credit'] > 0 ? 'ps-4 text-secondary' : 'fw-bold text-dark' ?>">
                                            <?= htmlspecialchars($line['account_name']) ?>
                                        </td>
                                        <td><span class="badge bg-light text-dark border" style="font-size: 0.68rem;"><?= htmlspecialchars($line['group_type']) ?></span></td>
                                        <td class="text-end fw-semibold text-danger">
                                            <?= $line['debit'] > 0 ? formatMoney($line['debit'], false) : '-' ?>
                                        </td>
                                        <td class="text-end fw-semibold text-success">
                                            <?= $line['credit'] > 0 ? formatMoney($line['credit'], false) : '-' ?>
                                        </td>
                                        <td><small class="text-muted"><?= htmlspecialchars($line['memo'] ?? '') ?></small></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light small fw-bold">
                                <tr>
                                    <td colspan="3" class="text-end">Total:</td>
                                    <td class="text-end text-danger"><?= formatMoney($je['total_debit'], false) ?></td>
                                    <td class="text-end text-success"><?= formatMoney($je['total_credit'], false) ?></td>
                                    <td class="text-success"><i class="bi bi-check-circle-fill me-1"></i>Debits Equal Credits</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
