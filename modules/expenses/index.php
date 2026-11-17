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
        redirect(BASE_URL . '/modules/expenses/index.php?tab=vouchers');
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
        redirect(BASE_URL . '/modules/expenses/index.php?tab=vouchers');

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        setFlashMessage('error', 'Expense recording error: ' . $e->getMessage());
        redirect(BASE_URL . '/modules/expenses/index.php?tab=vouchers');
    }
}

// Handle Add/Edit Expense Category POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_category'])) {
    $catId = (int)($_POST['category_id'] ?? 0);
    $name = sanitize($_POST['name'] ?? '');
    $code = sanitize($_POST['code'] ?? '');
    $accountId = (int)($_POST['account_id'] ?? 0);
    $status = sanitize($_POST['status'] ?? 'active');
    if (!in_array($status, ['active', 'inactive'])) {
        $status = 'active';
    }

    if (empty($name)) {
        setFlashMessage('error', 'Category name is required.');
        redirect(BASE_URL . '/modules/expenses/index.php?tab=categories');
    }

    // Auto-generate code from name if empty
    if (empty($code)) {
        $code = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '_', $name), '_'));
    } else {
        $code = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '_', $code), '_'));
    }

    // Validate or fallback account_id
    if ($accountId <= 0) {
        $defAcc = $pdo->query("SELECT id FROM `chart_of_accounts` WHERE group_type = 'Expense' AND system_tag = 'misc_expense' LIMIT 1")->fetch();
        $accountId = $defAcc ? (int)$defAcc['id'] : 25;
    }

    try {
        // Check duplicate code
        $dupStmt = $pdo->prepare("SELECT id FROM `expense_categories` WHERE `code` = ? AND id != ? LIMIT 1");
        $dupStmt->execute([$code, $catId]);
        if ($dupStmt->fetch()) {
            setFlashMessage('error', "An expense category with code '{$code}' already exists. Please choose a unique code.");
            redirect(BASE_URL . '/modules/expenses/index.php?tab=categories');
        }

        if ($catId > 0) {
            $stmt = $pdo->prepare("
                UPDATE `expense_categories` 
                SET `name` = ?, `code` = ?, `account_id` = ?, `status` = ?
                WHERE `id` = ?
            ");
            $stmt->execute([$name, $code, $accountId, $status, $catId]);
            logAudit($pdo, 'expenses', $catId, 'update_category', null, ['name' => $name, 'code' => $code, 'account_id' => $accountId, 'status' => $status]);
            setFlashMessage('success', "Expense category '{$name}' updated successfully.");
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO `expense_categories` (`name`, `code`, `account_id`, `status`)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$name, $code, $accountId, $status]);
            $newCatId = (int)$pdo->lastInsertId();
            logAudit($pdo, 'expenses', $newCatId, 'create_category', null, ['name' => $name, 'code' => $code, 'account_id' => $accountId]);
            setFlashMessage('success', "Expense category '{$name}' created successfully.");
        }
        redirect(BASE_URL . '/modules/expenses/index.php?tab=categories');

    } catch (Exception $e) {
        setFlashMessage('error', 'Error saving category: ' . $e->getMessage());
        redirect(BASE_URL . '/modules/expenses/index.php?tab=categories');
    }
}

// Handle Category Status Toggle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_category_status'])) {
    $catId = (int)($_POST['category_id'] ?? 0);

    if ($catId > 0) {
        $curr = $pdo->prepare("SELECT name, status FROM `expense_categories` WHERE id = ?");
        $curr->execute([$catId]);
        $cat = $curr->fetch();
        if ($cat) {
            $newStatus = ($cat['status'] === 'active') ? 'inactive' : 'active';
            $pdo->prepare("UPDATE `expense_categories` SET `status` = ? WHERE id = ?")->execute([$newStatus, $catId]);
            logAudit($pdo, 'expenses', $catId, 'toggle_category_status', null, ['from' => $cat['status'], 'to' => $newStatus]);
            setFlashMessage('success', "Category '{$cat['name']}' is now {$newStatus}.");
        }
    }
    redirect(BASE_URL . '/modules/expenses/index.php?tab=categories');
}

// Handle Delete Category POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_category'])) {
    $catId = (int)($_POST['category_id'] ?? 0);

    if ($catId > 0) {
        $cStmt = $pdo->prepare("SELECT name FROM `expense_categories` WHERE id = ?");
        $cStmt->execute([$catId]);
        $catName = $cStmt->fetchColumn() ?: "Category #{$catId}";

        // Check if any expenses are attached
        $expCount = (int)$pdo->query("SELECT COUNT(*) FROM `expenses` WHERE `category_id` = {$catId}")->fetchColumn();
        if ($expCount > 0) {
            setFlashMessage('error', "Cannot delete '{$catName}': {$expCount} expense voucher(s) are recorded under this category. To hide it from future use while preserving financial audit history, deactivate it instead.");
            redirect(BASE_URL . '/modules/expenses/index.php?tab=categories');
        }

        try {
            $pdo->prepare("DELETE FROM `expense_categories` WHERE `id` = ?")->execute([$catId]);
            logAudit($pdo, 'expenses', $catId, 'delete_category', null, ['name' => $catName]);
            setFlashMessage('success', "Expense category '{$catName}' deleted successfully.");
        } catch (Exception $e) {
            setFlashMessage('error', 'Error deleting category: ' . $e->getMessage());
        }
    }
    redirect(BASE_URL . '/modules/expenses/index.php?tab=categories');
}

// Tab navigation
$activeTab = sanitize($_GET['tab'] ?? 'vouchers');
if (!in_array($activeTab, ['vouchers', 'categories'])) {
    $activeTab = 'vouchers';
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

// Fetch All Categories for Category Management Tab
$allCategories = $pdo->query("
    SELECT ec.*, coa.code as account_code, coa.name as account_name,
           COUNT(e.id) as expense_count,
           COALESCE(SUM(e.amount), 0.00) as total_spent
    FROM `expense_categories` ec
    JOIN `chart_of_accounts` coa ON ec.account_id = coa.id
    LEFT JOIN `expenses` e ON ec.id = e.category_id AND e.status = 'approved'
    GROUP BY ec.id, ec.name, ec.code, ec.account_id, ec.status, coa.code, coa.name
    ORDER BY ec.status ASC, ec.name ASC
")->fetchAll();

// Expense Accounts for linking category
$expenseAccounts = $pdo->query("
    SELECT id, code, name, group_type, system_tag 
    FROM `chart_of_accounts` 
    WHERE group_type = 'Expense' OR parent_id = 19
    ORDER BY code ASC
")->fetchAll();

// Category metrics
$catCountTotal = count($allCategories);
$catCountActive = 0;
$catCountInactive = 0;
$catTotalSpent = 0.00;
foreach ($allCategories as $c) {
    if ($c['status'] === 'active') $catCountActive++;
    else $catCountInactive++;
    $catTotalSpent += (float)$c['total_spent'];
}

$pageTitle = 'Operating Expenses & Categories';
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-3 gap-2">
    <div>
        <h4 class="fw-bold mb-0 text-dark"><i class="bi bi-receipt text-danger me-2"></i>Operating Expense Management</h4>
        <small class="text-muted">Salaries, Fleet Fuel, Warehouse Rent, Overheads & Category Classification</small>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <?php if ($activeTab === 'vouchers'): ?>
            <a href="<?= BASE_URL ?>/modules/expenses/index.php?tab=categories" class="btn btn-sm btn-outline-primary">
                <i class="bi bi-tags me-1"></i> Manage Categories
            </a>
            <button type="button" class="btn btn-sm btn-danger btn-touch shadow-sm" data-confirm-trigger data-confirm-target="panel-record-expense">
                <i class="bi bi-plus-circle me-1"></i> Record New Expense
            </button>
        <?php else: ?>
            <a href="<?= BASE_URL ?>/modules/expenses/index.php?tab=vouchers" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i> Back to Expense Vouchers
            </a>
            <button type="button" class="btn btn-sm btn-primary btn-touch shadow-sm" id="btn-toggle-add-cat">
                <i class="bi bi-plus-circle me-1"></i> Add New Category
            </button>
        <?php endif; ?>
    </div>
</div>

<!-- Tabs Navigation -->
<ul class="nav nav-pills mb-3" role="tablist">
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'vouchers' ? 'active' : '' ?> fw-semibold" href="<?= BASE_URL ?>/modules/expenses/index.php?tab=vouchers">
            <i class="bi bi-receipt me-1"></i> Expense Vouchers (<?= count($expenses) ?>)
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'categories' ? 'active' : '' ?> fw-semibold" href="<?= BASE_URL ?>/modules/expenses/index.php?tab=categories">
            <i class="bi bi-tags me-1"></i> Expense Categories (<?= count($allCategories) ?>)
        </a>
    </li>
</ul>

<div class="tab-content">
    <!-- TAB 1: EXPENSE VOUCHERS -->
    <div class="tab-pane fade <?= $activeTab === 'vouchers' ? 'show active' : '' ?>" id="vouchers-pane">
        <!-- Inline Add Expense Panel (Strict No-Modal UI) -->
        <div id="panel-record-expense" class="card border-0 shadow-sm rounded-3 mb-3 d-none">
            <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0 text-danger"><i class="bi bi-cash me-1"></i>Record Business Expense Voucher</h6>
                <button type="button" class="btn btn-sm btn-close" data-confirm-cancel data-confirm-target="panel-record-expense"></button>
            </div>
            <div class="card-body p-3">
                <form method="POST" action="<?= BASE_URL ?>/modules/expenses/index.php?tab=vouchers">
                    <?= getCsrfField() ?>
                    <input type="hidden" name="record_expense" value="1">
                    <div class="row g-2">
                        <div class="col-md-4">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <label class="form-label small fw-semibold mb-0">Expense Category <span class="text-danger">*</span></label>
                                <a href="<?= BASE_URL ?>/modules/expenses/index.php?tab=categories&action=new" class="small text-primary text-decoration-none fw-semibold">
                                    <i class="bi bi-plus-circle me-1"></i>New Category
                                </a>
                            </div>
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
    </div>

    <!-- TAB 2: EXPENSE CATEGORIES CRUD -->
    <div class="tab-pane fade <?= $activeTab === 'categories' ? 'show active' : '' ?>" id="categories-pane">
        <!-- KPI Cards -->
        <div class="row g-3 mb-3">
            <div class="col-6 col-md-3">
                <div class="card border-0 shadow-sm rounded-3">
                    <div class="card-body p-3">
                        <div class="text-muted small">Total Categories</div>
                        <h4 class="fw-bold text-dark mb-0"><?= $catCountTotal ?></h4>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card border-0 shadow-sm rounded-3">
                    <div class="card-body p-3">
                        <div class="text-muted small">Active Categories</div>
                        <h4 class="fw-bold text-success mb-0"><?= $catCountActive ?></h4>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card border-0 shadow-sm rounded-3">
                    <div class="card-body p-3">
                        <div class="text-muted small">Inactive Categories</div>
                        <h4 class="fw-bold text-secondary mb-0"><?= $catCountInactive ?></h4>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card border-0 shadow-sm rounded-3">
                    <div class="card-body p-3">
                        <div class="text-muted small">Total Expenses Logged</div>
                        <h4 class="fw-bold text-danger mb-0"><?= formatMoney($catTotalSpent) ?></h4>
                    </div>
                </div>
            </div>
        </div>

        <!-- Add / Edit Category Panel -->
        <div id="panel-category-form" class="card border-0 shadow-sm rounded-3 mb-3 <?= (isset($_GET['action']) && $_GET['action'] === 'new') ? '' : 'd-none' ?>">
            <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0 text-primary" id="cat-form-title">
                    <i class="bi bi-tag-fill me-1"></i> Add New Expense Category
                </h6>
                <button type="button" class="btn btn-sm btn-close" id="btn-close-cat-form"></button>
            </div>
            <div class="card-body p-3 pt-0">
                <form method="POST" action="<?= BASE_URL ?>/modules/expenses/index.php?tab=categories" id="form-expense-category">
                    <?= getCsrfField() ?>
                    <input type="hidden" name="save_category" value="1">
                    <input type="hidden" name="category_id" id="cat_id" value="0">

                    <div class="row g-2">
                        <div class="col-12 col-md-4">
                            <label class="form-label small fw-semibold">Category Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm" name="name" id="cat_name" placeholder="e.g. Fleet Fuel, Office Stationery" required>
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label small fw-semibold">Code / Slug <small class="text-muted">(Optional)</small></label>
                            <input type="text" class="form-control form-control-sm font-monospace" name="code" id="cat_code" placeholder="e.g. fuel, office_stationery">
                            <div class="text-muted" style="font-size: 0.7rem;">Auto-generated from name if left empty</div>
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label small fw-semibold">Linked GL Ledger Account <span class="text-danger">*</span></label>
                            <select class="form-select form-select-sm" name="account_id" id="cat_account_id" required>
                                <?php foreach ($expenseAccounts as $acc): ?>
                                    <option value="<?= $acc['id'] ?>" <?= ($acc['code'] === '6060') ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($acc['code']) ?> - <?= htmlspecialchars($acc['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-2">
                            <label class="form-label small fw-semibold">Status</label>
                            <select class="form-select form-select-sm" name="status" id="cat_status">
                                <option value="active" selected>Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                        <div class="col-12 mt-3">
                            <button type="submit" class="btn btn-primary btn-sm px-4" id="cat-submit-btn">
                                <i class="bi bi-check-lg me-1"></i> Save Category
                            </button>
                            <button type="button" class="btn btn-light border btn-sm ms-2" id="btn-cancel-cat-form">
                                Cancel
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Categories Table -->
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-collection me-2 text-primary"></i>All Expense Categories</h6>
                <small class="text-muted">Configured operational classifications and general ledger mapping</small>
            </div>
            <div class="card-body p-0">
                <?php if (empty($allCategories)): ?>
                    <div class="p-4 text-center text-muted">No expense categories registered.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-responsive-card align-middle mb-0">
                            <thead class="table-light small">
                                <tr>
                                    <th style="width: 5%;">#</th>
                                    <th style="width: 25%;">Category Name</th>
                                    <th style="width: 15%;">System Code</th>
                                    <th style="width: 25%;">Linked GL Account</th>
                                    <th style="width: 15%;">Attached Vouchers</th>
                                    <th style="width: 10%;">Status</th>
                                    <th style="width: 10%;" class="text-end pe-3">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $sno = 1; foreach ($allCategories as $cat): ?>
                                    <tr>
                                        <td class="text-muted small"><?= $sno++ ?></td>
                                        <td data-label="Category Name">
                                            <span class="fw-bold text-dark"><?= htmlspecialchars($cat['name']) ?></span>
                                        </td>
                                        <td data-label="Code">
                                            <span class="badge bg-light text-dark border font-monospace"><?= htmlspecialchars($cat['code']) ?></span>
                                        </td>
                                        <td data-label="GL Account">
                                            <span class="badge bg-primary bg-opacity-10 text-primary border border-primary font-monospace">
                                                <?= htmlspecialchars($cat['account_code']) ?>
                                            </span>
                                            <small class="text-dark ms-1"><?= htmlspecialchars($cat['account_name']) ?></small>
                                        </td>
                                        <td data-label="Usage">
                                            <span class="fw-semibold text-dark"><?= (int)$cat['expense_count'] ?> voucher(s)</span>
                                            <div class="text-muted" style="font-size: 0.72rem;"><?= formatMoney($cat['total_spent']) ?></div>
                                        </td>
                                        <td data-label="Status">
                                            <?php if ($cat['status'] === 'active'): ?>
                                                <span class="badge bg-success">Active</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Actions" class="text-end pe-3">
                                            <div class="d-inline-flex gap-1">
                                                <!-- Edit Button -->
                                                <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2 btn-edit-cat"
                                                        data-id="<?= $cat['id'] ?>"
                                                        data-name="<?= htmlspecialchars($cat['name']) ?>"
                                                        data-code="<?= htmlspecialchars($cat['code']) ?>"
                                                        data-account="<?= $cat['account_id'] ?>"
                                                        data-status="<?= $cat['status'] ?>"
                                                        title="Edit Category">
                                                    <i class="bi bi-pencil"></i>
                                                </button>

                                                <!-- Status Toggle Form -->
                                                <form method="POST" action="<?= BASE_URL ?>/modules/expenses/index.php?tab=categories" class="d-inline">
                                                    <?= getCsrfField() ?>
                                                    <input type="hidden" name="toggle_category_status" value="1">
                                                    <input type="hidden" name="category_id" value="<?= $cat['id'] ?>">
                                                    <button type="submit" class="btn btn-sm <?= $cat['status'] === 'active' ? 'btn-outline-warning' : 'btn-outline-success' ?> py-0 px-2"
                                                            title="<?= $cat['status'] === 'active' ? 'Deactivate Category' : 'Activate Category' ?>">
                                                        <i class="bi <?= $cat['status'] === 'active' ? 'bi-slash-circle' : 'bi-check-circle' ?>"></i>
                                                    </button>
                                                </form>

                                                <!-- Delete / Safe Deactivate Form -->
                                                <?php if ((int)$cat['expense_count'] === 0): ?>
                                                    <form method="POST" action="<?= BASE_URL ?>/modules/expenses/index.php?tab=categories" class="d-inline"
                                                          onsubmit="return confirm('Are you sure you want to permanently delete category <?= htmlspecialchars(addslashes($cat['name'])) ?>?');">
                                                        <?= getCsrfField() ?>
                                                        <input type="hidden" name="delete_category" value="1">
                                                        <input type="hidden" name="category_id" value="<?= $cat['id'] ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2" title="Delete Category">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 disabled" 
                                                            title="Cannot delete: <?= (int)$cat['expense_count'] ?> expense voucher(s) attached. Deactivate instead." 
                                                            disabled>
                                                        <i class="bi bi-trash"></i>
                                                    </button>
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
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const catPanel = document.getElementById('panel-category-form');
    const toggleBtn = document.getElementById('btn-toggle-add-cat');
    const closeBtn = document.getElementById('btn-close-cat-form');
    const cancelBtn = document.getElementById('btn-cancel-cat-form');
    const formTitle = document.getElementById('cat-form-title');
    const submitBtn = document.getElementById('cat-submit-btn');

    const idInput = document.getElementById('cat_id');
    const nameInput = document.getElementById('cat_name');
    const codeInput = document.getElementById('cat_code');
    const accountInput = document.getElementById('cat_account_id');
    const statusInput = document.getElementById('cat_status');

    function openAddMode() {
        idInput.value = '0';
        nameInput.value = '';
        codeInput.value = '';
        statusInput.value = 'active';
        formTitle.innerHTML = '<i class="bi bi-tag-fill me-1"></i> Add New Expense Category';
        submitBtn.innerHTML = '<i class="bi bi-check-lg me-1"></i> Save Category';
        catPanel.classList.remove('d-none');
        nameInput.focus();
    }

    function closePanel() {
        catPanel.classList.add('d-none');
    }

    if (toggleBtn) {
        toggleBtn.addEventListener('click', function() {
            if (catPanel.classList.contains('d-none')) {
                openAddMode();
            } else {
                closePanel();
            }
        });
    }

    if (closeBtn) closeBtn.addEventListener('click', closePanel);
    if (cancelBtn) cancelBtn.addEventListener('click', closePanel);

    // Edit Buttons handler
    document.querySelectorAll('.btn-edit-cat').forEach(btn => {
        btn.addEventListener('click', function() {
            idInput.value = this.getAttribute('data-id');
            nameInput.value = this.getAttribute('data-name');
            codeInput.value = this.getAttribute('data-code');
            accountInput.value = this.getAttribute('data-account');
            statusInput.value = this.getAttribute('data-status');

            formTitle.innerHTML = '<i class="bi bi-pencil-square me-1"></i> Edit Expense Category: ' + this.getAttribute('data-name');
            submitBtn.innerHTML = '<i class="bi bi-check-lg me-1"></i> Update Category';
            catPanel.classList.remove('d-none');
            catPanel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            nameInput.focus();
        });
    });
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
