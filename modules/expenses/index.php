<?php
/**
 * Operating Expense Management & Accounting Integration
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('expenses', 'view');

$pdo = getDatabaseConnection();
$currentUser = getCurrentUser();

// Handle Record Expense POST BEFORE HTML output
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_expense'])) {
    requirePermission('expenses', 'create');

    $categoryId = (int)($_POST['category_id'] ?? 0);
    $amount = (float)($_POST['amount'] ?? 0.00);
    $paymentMethod = sanitize($_POST['payment_method'] ?? 'cash');
    $expenseDate = $_POST['expense_date'] ?? date('Y-m-d');
    $referenceNo = sanitize($_POST['reference_no'] ?? '');
    $description = sanitize($_POST['description'] ?? '');

    if (!$categoryId || $amount <= 0 || empty($description)) {
        setFlashMessage('error', 'Please fill in category, positive amount and description.');
        redirect(BASE_URL . '/modules/expenses/index.php');
    }

    try {
        $pdo->beginTransaction();

        $catStmt = $pdo->prepare("SELECT * FROM `expense_categories` WHERE id = ?");
        $catStmt->execute([$categoryId]);
        $cat = $catStmt->fetch();

        if (!$cat) {
            throw new Exception("Invalid expense category.");
        }

        // Determine Payment Account: Cash in Hand vs Bank Account
        $payAccTag = ($paymentMethod === 'cash') ? 'cash' : 'bank';
        $payAcc = getSystemAccount($pdo, $payAccTag);

        $expenseNumber = generateDocNumber($pdo, 'expenses', 'expense_number', 'EXP-');

        $expStmt = $pdo->prepare("
            INSERT INTO `expenses` (
                `expense_number`, `category_id`, `expense_date`, `amount`, 
                `payment_account_id`, `payment_method`, `reference_no`, `description`, 
                `entered_by`, `approved_by`, `status`, `created_at`
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'approved', NOW())
        ");
        $expStmt->execute([
            $expenseNumber, $categoryId, $expenseDate, $amount,
            $payAcc['id'], $paymentMethod, $referenceNo, $description,
            $currentUser['id'], $currentUser['id']
        ]);
        $expenseId = (int)$pdo->lastInsertId();

        // Critical Double-Entry Accounting Rule:
        // Debit: Expense Account (Salaries, Fuel, Rent, etc.)
        // Credit: Cash / Bank
        postExpenseAccounting($pdo, $expenseId, $expenseNumber, $cat['account_id'], $payAcc['id'], $amount, $description, $currentUser['id']);

        logAudit($pdo, 'expenses', $expenseId, 'create_expense', null, ['number' => $expenseNumber, 'amount' => $amount, 'category' => $cat['name']]);
        $pdo->commit();

        setFlashMessage('success', "Expense {$expenseNumber} of Rs. " . number_format($amount, 2) . " posted to financial accounts!");
        redirect(BASE_URL . '/modules/expenses/index.php');

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        setFlashMessage('error', 'Expense recording error: ' . $e->getMessage());
        redirect(BASE_URL . '/modules/expenses/index.php');
    }
}

// Fetch Expenses
$expenses = $pdo->query("
    SELECT e.*, ec.name as category_name, u.full_name as enterer_name
    FROM `expenses` e
    JOIN `expense_categories` ec ON e.category_id = ec.id
    LEFT JOIN `users` u ON e.entered_by = u.id
    ORDER BY e.id DESC
    LIMIT 100
")->fetchAll();

$categories = $pdo->query("SELECT id, name FROM `expense_categories` WHERE status = 'active' ORDER BY name ASC")->fetchAll();

$totalMonthExpenses = (float)$pdo->query("SELECT COALESCE(SUM(amount), 0) FROM `expenses` WHERE `expense_date` >= '" . date('Y-m-01') . "' AND `status` = 'approved'")->fetchColumn();

$pageTitle = 'Operating Expenses';
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-3 gap-2">
    <div>
        <h4 class="fw-bold mb-0 text-dark"><i class="bi bi-receipt text-danger me-2"></i>Operating Expense Management</h4>
        <small class="text-muted">Salaries, Fleet Fuel, Warehouse Rent & Overhead Expenses</small>
    </div>
    <div>
        <button type="button" class="btn btn-primary btn-touch shadow-sm" data-confirm-trigger data-confirm-target="panel-record-expense">
            <i class="bi bi-plus-circle me-1"></i> Record New Expense
        </button>
    </div>
</div>

<!-- Inline Add Expense Panel (Strict No-Modal UI) -->
<div id="panel-record-expense" class="card border-0 shadow-sm rounded-3 mb-3 d-none">
    <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
        <h6 class="fw-bold mb-0 text-danger"><i class="bi bi-cash me-1"></i>Record Business Expense Voucher</h6>
        <button type="button" class="btn btn-sm btn-close" data-confirm-cancel data-confirm-target="panel-record-expense"></button>
    </div>
    <div class="card-body p-3">
        <form method="POST" action="<?= BASE_URL ?>/modules/expenses/index.php">
            <?= getCsrfField() ?>
            <input type="hidden" name="record_expense" value="1">
            <div class="row g-2">
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">Expense Category <span class="text-danger">*</span></label>
                    <select class="form-select form-select-sm" name="category_id" required>
                        <option value="">-- Choose Category --</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Amount (Rs.) <span class="text-danger">*</span></label>
                    <input type="number" class="form-control form-control-sm text-end fw-bold" name="amount" step="0.01" required placeholder="0.00">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Disbursed From Account</label>
                    <select class="form-select form-select-sm" name="payment_method">
                        <option value="cash">Cash in Hand</option>
                        <option value="bank">Bank Account</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold">Expense Date</label>
                    <input type="date" class="form-control form-control-sm" name="expense_date" value="<?= date('Y-m-d') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">Reference / Bill #</label>
                    <input type="text" class="form-control form-control-sm" name="reference_no" placeholder="e.g. BILL-998">
                </div>
                <div class="col-md-8">
                    <label class="form-label small fw-semibold">Expense Narration / Description <span class="text-danger">*</span></label>
                    <input type="text" class="form-control form-control-sm" name="description" placeholder="e.g. Fuel for Delivery Van GAW-8821" required>
                </div>
                <div class="col-12 mt-3">
                    <button type="submit" class="btn btn-danger btn-sm px-4">Save & Post Expense Entry</button>
                    <button type="button" class="btn btn-light border btn-sm ms-2" data-confirm-cancel data-confirm-target="panel-record-expense">Cancel</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Monthly Expenses KPI -->
<div class="card border-0 shadow-sm rounded-3 mb-3">
    <div class="card-body p-3">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <span class="text-muted small">Total Operating Expenses This Month</span>
                <h4 class="fw-bold text-danger mb-0"><?= formatMoney($totalMonthExpenses) ?></h4>
            </div>
            <span class="badge bg-light text-dark border"><?= count($expenses) ?> Total Expense Entries</span>
        </div>
    </div>
</div>

<!-- Expense List Table -->
<div class="card border-0 shadow-sm rounded-3">
    <div class="card-body p-0">
        <?php if (empty($expenses)): ?>
            <div class="p-4 text-center text-muted">No expenses recorded.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover table-responsive-card align-middle mb-0">
                    <thead class="table-light small">
                        <tr>
                            <th>Voucher #</th>
                            <th>Date</th>
                            <th>Category</th>
                            <th>Narration / Purpose</th>
                            <th>Paid Via</th>
                            <th class="text-end">Amount</th>
                            <th>Entered By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($expenses as $exp): ?>
                            <tr>
                                <td data-label="Voucher #" class="fw-bold text-dark"><?= htmlspecialchars($exp['expense_number']) ?></td>
                                <td data-label="Date"><?= formatDate($exp['expense_date']) ?></td>
                                <td data-label="Category"><span class="badge bg-light text-dark border"><?= htmlspecialchars($exp['category_name']) ?></span></td>
                                <td data-label="Description"><?= htmlspecialchars($exp['description']) ?></td>
                                <td data-label="Paid Via"><?= ucfirst($exp['payment_method']) ?></td>
                                <td data-label="Amount" class="text-end fw-bold text-danger"><?= formatMoney($exp['amount']) ?></td>
                                <td data-label="By"><small class="text-muted"><?= htmlspecialchars($exp['enterer_name'] ?? 'System') ?></small></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
