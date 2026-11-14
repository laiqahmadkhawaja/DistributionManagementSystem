<?php
/**
 * Delivery Driver Cash Handover & Daily Reconciliation
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('payments', 'view');

$pdo = getDatabaseConnection();
$currentUser = getCurrentUser();
$roleSlug = $currentUser['role_slug'];
$currentEmp = getCurrentEmployee();

// Process POST Handover Submission (By Delivery Driver)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_handover'])) {
    $deliveryManId = (int)($_POST['delivery_man_id'] ?? ($currentEmp['id'] ?? 0));
    $handoverDate = $_POST['handover_date'] ?? date('Y-m-d');
    $cashAmount = (float)($_POST['cash_amount'] ?? 0.00);
    $digitalAmount = (float)($_POST['digital_amount'] ?? 0.00);
    $chequeAmount = (float)($_POST['cheque_amount'] ?? 0.00);
    $cashDeposited = (float)($_POST['cash_deposited'] ?? 0.00);
    $totalCollected = $cashAmount + $digitalAmount + $chequeAmount;
    $difference = $cashDeposited - $cashAmount;
    $notes = sanitize($_POST['notes'] ?? '');

    try {
        $handoverNo = generateDocNumber($pdo, 'cash_handovers', 'handover_number', 'CHO-');
        $stmt = $pdo->prepare("
            INSERT INTO `cash_handovers` (
                `handover_number`, `delivery_man_id`, `handover_date`, `cash_amount`, 
                `digital_amount`, `cheque_amount`, `total_collected`, `cash_deposited`, 
                `difference_amount`, `status`, `notes`, `created_at`
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, NOW())
        ");
        $stmt->execute([
            $handoverNo, $deliveryManId, $handoverDate, $cashAmount,
            $digitalAmount, $chequeAmount, $totalCollected, $cashDeposited,
            $difference, $notes
        ]);

        // Update collected payments status
        $pdo->prepare("
            UPDATE `payments` 
            SET `handover_status` = 'handed_over' 
            WHERE `collected_by` = ? AND `payment_date` = ? AND `handover_status` = 'pending_handover'
        ")->execute([$deliveryManId, $handoverDate]);

        logAudit($pdo, 'payments', (int)$pdo->lastInsertId(), 'handover_submit', null, ['handover_no' => $handoverNo, 'deposited' => $cashDeposited]);
        setFlashMessage('success', "Cash handover {$handoverNo} submitted successfully. Awaiting accountant verification.");
        redirect(BASE_URL . '/modules/payments/handover.php');

    } catch (Exception $e) {
        setFlashMessage('error', 'Handover submission error: ' . $e->getMessage());
        redirect(BASE_URL . '/modules/payments/handover.php');
    }
}

// Process POST Verification Action (By Accountant / Admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_handover'])) {
    requirePermission('payments', 'reconcile');
    $handoverId = (int)($_POST['handover_id'] ?? 0);
    $verAction = sanitize($_POST['ver_action'] ?? 'verified');

    try {
        $pdo->prepare("
            UPDATE `cash_handovers` 
            SET `status` = ?, `verified_by` = ?, `verified_at` = NOW() 
            WHERE id = ?
        ")->execute([$verAction, $currentUser['id'], $handoverId]);

        // Mark payments reconciled
        $hStmt = $pdo->prepare("SELECT delivery_man_id, handover_date FROM `cash_handovers` WHERE id = ?");
        $hStmt->execute([$handoverId]);
        $hRec = $hStmt->fetch();
        if ($hRec) {
            $pdo->prepare("
                UPDATE `payments` 
                SET `handover_status` = 'reconciled' 
                WHERE `collected_by` = ? AND `payment_date` = ?
            ")->execute([$hRec['delivery_man_id'], $hRec['handover_date']]);
        }

        logAudit($pdo, 'payments', $handoverId, 'handover_verify', null, ['ver_action' => $verAction]);
        setFlashMessage('success', "Handover marked as {$verAction}.");
        redirect(BASE_URL . '/modules/payments/handover.php');

    } catch (Exception $e) {
        setFlashMessage('error', 'Verification error: ' . $e->getMessage());
        redirect(BASE_URL . '/modules/payments/handover.php');
    }
}

// Fetch pending cash in hand for current delivery driver (or all if admin)
$driverEmpId = ($roleSlug === ROLE_DELIVERY_MAN) ? ($currentEmp['id'] ?? 0) : 0;
$today = date('Y-m-d');

$custodyQuery = "
    SELECT 
        COALESCE(SUM(CASE WHEN payment_method_code = 'cash' THEN amount ELSE 0 END), 0) as cash_in_hand,
        COALESCE(SUM(CASE WHEN payment_method_code IN ('jazzcash', 'easypaisa') THEN amount ELSE 0 END), 0) as digital_coll,
        COALESCE(SUM(CASE WHEN payment_method_code = 'cheque' THEN amount ELSE 0 END), 0) as cheque_coll,
        COALESCE(SUM(amount), 0) as total_coll
    FROM `payments`
    WHERE `handover_status` = 'pending_handover'
";
if ($driverEmpId > 0) {
    $custodyQuery .= " AND `collected_by` = {$driverEmpId}";
}
$custody = $pdo->query($custodyQuery)->fetch();

// Fetch handovers history
$handoverHistory = $pdo->query("
    SELECT ch.*, e.full_name as driver_name, u.full_name as verifier_name
    FROM `cash_handovers` ch
    JOIN `employees` e ON ch.delivery_man_id = e.id
    LEFT JOIN `users` u ON ch.verified_by = u.id
    ORDER BY ch.id DESC LIMIT 20
")->fetchAll();

$pageTitle = 'Cash Handover & Reconciliation';
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="fw-bold mb-0 text-dark"><i class="bi bi-wallet2 text-warning me-2"></i>Cash Handover & Reconciliation</h4>
        <small class="text-muted">End-of-Day Custody Verification & Difference Tracking</small>
    </div>
</div>

<div class="row g-3">
    <!-- Handover Submission Form -->
    <div class="col-12 col-md-5">
        <div class="card border-0 shadow-sm rounded-3 mb-3">
            <div class="card-header bg-white py-3 border-0">
                <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-cash-coin me-2 text-success"></i>Submit End-of-Day Handover</h6>
            </div>
            <div class="card-body p-3">
                <form method="POST" action="<?= BASE_URL ?>/modules/payments/handover.php">
                    <?= getCsrfField() ?>
                    <input type="hidden" name="submit_handover" value="1">

                    <?php if ($roleSlug !== ROLE_DELIVERY_MAN): ?>
                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Delivery Driver</label>
                            <select class="form-select form-select-sm" name="delivery_man_id" required>
                                <?php
                                $drivers = $pdo->query("SELECT id, full_name FROM `employees` WHERE role_type IN ('delivery_man', 'salesman')")->fetchAll();
                                foreach ($drivers as $d):
                                ?>
                                    <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['full_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Handover Date</label>
                        <input type="date" class="form-control form-control-sm" name="handover_date" value="<?= date('Y-m-d') ?>" required>
                    </div>

                    <div class="p-3 bg-light rounded-3 mb-3 border">
                        <div class="d-flex justify-content-between mb-1">
                            <span class="text-muted small">Pending Cash Recovery:</span>
                            <span class="fw-bold text-success"><?= formatMoney($custody['cash_in_hand']) ?></span>
                        </div>
                        <div class="d-flex justify-content-between mb-1">
                            <span class="text-muted small">Pending Digital:</span>
                            <span class="fw-bold text-info"><?= formatMoney($custody['digital_coll']) ?></span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span class="text-muted small">Total Collections:</span>
                            <span class="fw-bold text-dark"><?= formatMoney($custody['total_coll']) ?></span>
                        </div>
                    </div>

                    <input type="hidden" name="cash_amount" value="<?= (float)$custody['cash_in_hand'] ?>">
                    <input type="hidden" name="digital_amount" value="<?= (float)$custody['digital_coll'] ?>">
                    <input type="hidden" name="cheque_amount" value="<?= (float)$custody['cheque_coll'] ?>">

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Physical Cash Handed Over (Rs.) <span class="text-danger">*</span></label>
                        <input type="number" class="form-control form-control-lg fw-bold text-primary" name="cash_deposited" value="<?= (float)$custody['cash_in_hand'] ?>" step="1" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Remarks / Discrepancy Note</label>
                        <textarea class="form-control form-control-sm" name="notes" rows="2" placeholder="e.g. Complete cash collected deposited to accountant"></textarea>
                    </div>

                    <button type="submit" class="btn btn-warning w-100 btn-touch shadow-sm text-dark fw-bold">
                        <i class="bi bi-box-arrow-down me-1"></i> Submit Cash for Verification
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Handover Records & Reconciliation Table -->
    <div class="col-12 col-md-7">
        <div class="card border-0 shadow-sm rounded-3 mb-3">
            <div class="card-header bg-white py-3 border-0">
                <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-journal-check me-2 text-primary"></i>Handover Reconciliation Logs</h6>
            </div>
            <div class="card-body p-0">
                <?php if (empty($handoverHistory)): ?>
                    <div class="p-4 text-center text-muted">No handovers recorded yet.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-responsive-card align-middle mb-0">
                            <thead class="table-light small">
                                <tr>
                                    <th>Handover #</th>
                                    <th>Driver / Date</th>
                                    <th>Collected</th>
                                    <th>Deposited</th>
                                    <th>Diff</th>
                                    <th>Status</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($handoverHistory as $h): ?>
                                    <tr>
                                        <td data-label="Handover #" class="fw-bold text-dark"><?= htmlspecialchars($h['handover_number']) ?></td>
                                        <td data-label="Driver">
                                            <div class="fw-semibold text-dark"><?= htmlspecialchars($h['driver_name']) ?></div>
                                            <small class="text-muted"><?= formatDate($h['handover_date']) ?></small>
                                        </td>
                                        <td data-label="Collected"><?= formatMoney($h['total_collected']) ?></td>
                                        <td data-label="Deposited" class="fw-bold text-primary"><?= formatMoney($h['cash_deposited']) ?></td>
                                        <td data-label="Diff" class="<?= $h['difference_amount'] < 0 ? 'text-danger fw-bold' : 'text-success' ?>">
                                            <?= formatMoney($h['difference_amount']) ?>
                                        </td>
                                        <td data-label="Status">
                                            <?php
                                            $b = match ($h['status']) {
                                                'verified' => 'bg-success',
                                                'rejected' => 'bg-danger',
                                                default => 'bg-warning text-dark'
                                            };
                                            ?>
                                            <span class="badge badge-status <?= $b ?>"><?= htmlspecialchars($h['status']) ?></span>
                                        </td>
                                        <td data-label="Action" class="text-end">
                                            <?php if ($h['status'] === 'pending' && (hasPermission('payments', 'reconcile') || $roleSlug === ROLE_SUPER_ADMIN)): ?>
                                                <button type="button" class="btn btn-sm btn-outline-success" data-confirm-trigger data-confirm-target="panel-verify-<?= $h['id'] ?>">
                                                    Verify
                                                </button>

                                                <!-- Inline Verify Panel -->
                                                <div id="panel-verify-<?= $h['id'] ?>" class="inline-confirm-panel d-none text-start mt-2">
                                                    <div class="fw-bold small text-success mb-2">Verify Cash Deposit?</div>
                                                    <form method="POST" action="<?= BASE_URL ?>/modules/payments/handover.php">
                                                        <?= getCsrfField() ?>
                                                        <input type="hidden" name="verify_handover" value="1">
                                                        <input type="hidden" name="handover_id" value="<?= $h['id'] ?>">
                                                        <div class="d-flex gap-1">
                                                            <button type="submit" name="ver_action" value="verified" class="btn btn-sm btn-success">Confirm Received</button>
                                                            <button type="button" class="btn btn-sm btn-secondary" data-confirm-cancel data-confirm-target="panel-verify-<?= $h['id'] ?>">Cancel</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            <?php else: ?>
                                                <small class="text-muted"><?= $h['verifier_name'] ? 'by ' . htmlspecialchars($h['verifier_name']) : '-' ?></small>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
