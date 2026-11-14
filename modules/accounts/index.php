<?php
/**
 * Official Company Bank Accounts & Payment Channels Management
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('payments', 'view');

$pdo = getDatabaseConnection();
$currentUser = getCurrentUser();
$roleSlug = $currentUser['role_slug'];
$isAdminOrAccountant = in_array($roleSlug, [ROLE_SUPER_ADMIN, ROLE_ADMIN, 'accountant']);

// Handle POST: Create or Update Company Account
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_account'])) {
    if (!$isAdminOrAccountant) {
        setFlashMessage('error', 'Only administrative staff or accountants can manage official company bank accounts.');
        redirect(BASE_URL . '/modules/accounts/index.php');
    }

    $id = (int)($_POST['account_id'] ?? 0);
    $accountType = sanitize($_POST['account_type'] ?? 'bank');
    $bankName = sanitize($_POST['bank_name'] ?? '');
    $accountTitle = sanitize($_POST['account_title'] ?? '');
    $accountNumber = sanitize($_POST['account_number'] ?? '');
    $iban = sanitize($_POST['iban'] ?? '');
    $branchName = sanitize($_POST['branch_name'] ?? '');
    $branchCode = sanitize($_POST['branch_code'] ?? '');
    $raastId = sanitize($_POST['raast_id'] ?? '');
    $notes = sanitize($_POST['notes'] ?? '');
    $isPrimary = isset($_POST['is_primary']) ? 1 : 0;
    $showOnBills = isset($_POST['show_on_bills']) ? 1 : 0;
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    $displayOrder = (int)($_POST['display_order'] ?? 0);

    if (empty($bankName) || empty($accountTitle) || empty($accountNumber)) {
        setFlashMessage('error', 'Bank / Wallet Provider, Account Title, and Account Number are required.');
        redirect(BASE_URL . '/modules/accounts/index.php');
    }

    try {
        $pdo->beginTransaction();

        // If marked primary, unset other primaries
        if ($isPrimary === 1) {
            $pdo->exec("UPDATE `company_accounts` SET `is_primary` = 0");
        }

        if ($id > 0) {
            // Update
            $stmt = $pdo->prepare("
                UPDATE `company_accounts` SET 
                    `account_type` = ?, `bank_name` = ?, `account_title` = ?, `account_number` = ?,
                    `iban` = ?, `branch_name` = ?, `branch_code` = ?, `raast_id` = ?,
                    `notes` = ?, `is_primary` = ?, `is_active` = ?, `show_on_bills` = ?,
                    `display_order` = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $accountType, $bankName, $accountTitle, $accountNumber,
                $iban, $branchName, $branchCode, $raastId,
                $notes, $isPrimary, $isActive, $showOnBills,
                $displayOrder, $id
            ]);
            logAudit($pdo, 'company_accounts', $id, 'update_account', null, ['bank_name' => $bankName, 'account_no' => $accountNumber]);
            $msg = "Account '{$bankName}' updated successfully.";
        } else {
            // Insert
            $stmt = $pdo->prepare("
                INSERT INTO `company_accounts` (
                    `account_type`, `bank_name`, `account_title`, `account_number`,
                    `iban`, `branch_name`, `branch_code`, `raast_id`,
                    `notes`, `is_primary`, `is_active`, `show_on_bills`, `display_order`, `created_at`
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $accountType, $bankName, $accountTitle, $accountNumber,
                $iban, $branchName, $branchCode, $raastId,
                $notes, $isPrimary, $isActive, $showOnBills, $displayOrder
            ]);
            $newId = (int)$pdo->lastInsertId();
            logAudit($pdo, 'company_accounts', $newId, 'create_account', null, ['bank_name' => $bankName, 'account_no' => $accountNumber]);
            $msg = "New {$accountType} account '{$bankName}' added successfully.";
        }

        $pdo->commit();
        setFlashMessage('success', $msg);
        redirect(BASE_URL . '/modules/accounts/index.php');

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        setFlashMessage('error', 'Error saving account: ' . $e->getMessage());
        redirect(BASE_URL . '/modules/accounts/index.php');
    }
}

// Handle POST: Toggle Active Status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_status'])) {
    if (!$isAdminOrAccountant) {
        setFlashMessage('error', 'Unauthorized.');
        redirect(BASE_URL . '/modules/accounts/index.php');
    }

    $id = (int)($_POST['account_id'] ?? 0);
    $status = (int)($_POST['new_status'] ?? 0);

    $pdo->prepare("UPDATE `company_accounts` SET `is_active` = ? WHERE id = ?")->execute([$status, $id]);
    setFlashMessage('success', 'Account status updated.');
    redirect(BASE_URL . '/modules/accounts/index.php');
}

// Handle POST: Set Primary
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_primary'])) {
    if (!$isAdminOrAccountant) {
        setFlashMessage('error', 'Unauthorized.');
        redirect(BASE_URL . '/modules/accounts/index.php');
    }

    $id = (int)($_POST['account_id'] ?? 0);
    $pdo->beginTransaction();
    $pdo->exec("UPDATE `company_accounts` SET `is_primary` = 0");
    $pdo->prepare("UPDATE `company_accounts` SET `is_primary` = 1, `is_active` = 1 WHERE id = ?")->execute([$id]);
    $pdo->commit();

    setFlashMessage('success', 'Primary receiving account updated.');
    redirect(BASE_URL . '/modules/accounts/index.php');
}

// Handle POST: Delete Account
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_account'])) {
    if (!$isAdminOrAccountant) {
        setFlashMessage('error', 'Unauthorized.');
        redirect(BASE_URL . '/modules/accounts/index.php');
    }

    $id = (int)($_POST['account_id'] ?? 0);
    $pdo->prepare("DELETE FROM `company_accounts` WHERE id = ?")->execute([$id]);
    logAudit($pdo, 'company_accounts', $id, 'delete_account', null, []);
    setFlashMessage('success', 'Account removed successfully.');
    redirect(BASE_URL . '/modules/accounts/index.php');
}

// Fetch all accounts
$accounts = $pdo->query("
    SELECT * FROM `company_accounts` 
    ORDER BY `is_primary` DESC, `display_order` ASC, `bank_name` ASC
")->fetchAll();

$bankCount = 0;
$walletCount = 0;
$activeCount = 0;
foreach ($accounts as $a) {
    if ($a['is_active']) $activeCount++;
    if ($a['account_type'] === 'bank') $bankCount++;
    if ($a['account_type'] === 'wallet') $walletCount++;
}

$pageTitle = 'Company Bank & Wallet Accounts';
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-3 gap-2">
    <div>
        <h4 class="fw-bold mb-0 text-dark"><i class="bi bi-bank text-primary me-2"></i>Official Bank & Wallet Accounts</h4>
        <small class="text-muted">Manage Receiving Bank Accounts, Digital Mobile Wallets (JazzCash/EasyPaisa) & Customer Payment Display</small>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="<?= BASE_URL ?>/modules/accounts/payment_details.php" target="_blank" class="btn btn-sm btn-success shadow-sm">
            <i class="bi bi-qr-code me-1"></i> Open Customer Payment Screen
        </a>
        <?php if ($isAdminOrAccountant): ?>
            <button type="button" class="btn btn-sm btn-primary shadow-sm" data-confirm-trigger data-confirm-target="panel-create-account">
                <i class="bi bi-plus-circle me-1"></i> Add Bank / Wallet Account
            </button>
        <?php endif; ?>
    </div>
</div>

<!-- Summary Metrics Cards -->
<div class="row g-2 mb-3">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm p-3 bg-white rounded-3 h-100">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-muted small fw-semibold">Active Channels</div>
                    <div class="h5 fw-bold text-success mb-0"><?= $activeCount ?> / <?= count($accounts) ?></div>
                </div>
                <div class="p-2 bg-success-subtle text-success rounded-circle">
                    <i class="bi bi-check-circle fs-5"></i>
                </div>
            </div>
            <small class="text-muted mt-1 d-block">Available for receiving payments</small>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm p-3 bg-white rounded-3 h-100">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-muted small fw-semibold">Bank Accounts</div>
                    <div class="h5 fw-bold text-primary mb-0"><?= $bankCount ?> Banks</div>
                </div>
                <div class="p-2 bg-primary-subtle text-primary rounded-circle">
                    <i class="bi bi-bank fs-5"></i>
                </div>
            </div>
            <small class="text-muted mt-1 d-block">Meezan, HBL, Alfalah, etc.</small>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm p-3 bg-white rounded-3 h-100">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-muted small fw-semibold">Digital Wallets</div>
                    <div class="h5 fw-bold text-warning mb-0"><?= $walletCount ?> Wallets</div>
                </div>
                <div class="p-2 bg-warning-subtle text-warning rounded-circle">
                    <i class="bi bi-phone fs-5"></i>
                </div>
            </div>
            <small class="text-muted mt-1 d-block">JazzCash, EasyPaisa, SadaPay</small>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm p-3 bg-white rounded-3 h-100">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-muted small fw-semibold">Customer Sharing</div>
                    <div class="h5 fw-bold text-info mb-0">1-Click Share</div>
                </div>
                <div class="p-2 bg-info-subtle text-info rounded-circle">
                    <i class="bi bi-whatsapp fs-5"></i>
                </div>
            </div>
            <small class="text-muted mt-1 d-block">Direct WhatsApp & Print Screen</small>
        </div>
    </div>
</div>

<!-- Strict No-Modal Inline Add / Define Account Panel -->
<div id="panel-create-account" class="card border-0 shadow-sm rounded-3 mb-3 d-none">
    <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
        <h6 class="fw-bold mb-0 text-primary"><i class="bi bi-plus-circle me-1"></i>Add Official Receiving Bank / Wallet</h6>
        <button type="button" class="btn btn-sm btn-close" data-confirm-cancel data-confirm-target="panel-create-account"></button>
    </div>
    <div class="card-body p-3">
        <form method="POST" action="<?= BASE_URL ?>/modules/accounts/index.php">
            <?= getCsrfField() ?>
            <input type="hidden" name="save_account" value="1">
            <input type="hidden" name="account_id" value="0">

            <div class="row g-2">
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Account Category <span class="text-danger">*</span></label>
                    <select class="form-select form-select-sm" name="account_type" required>
                        <option value="bank">Commercial Bank Account</option>
                        <option value="wallet">Mobile Wallet (JazzCash / EasyPaisa / SadaPay)</option>
                        <option value="raast">Instant Raast P2M / P2P Alias</option>
                        <option value="other">Other Payment Gateway</option>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label small fw-semibold">Bank / Wallet Provider Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control form-control-sm" name="bank_name" placeholder="e.g. Meezan Bank / JazzCash / HBL" required list="bankSuggestions">
                    <datalist id="bankSuggestions">
                        <option value="Meezan Bank Ltd">
                        <option value="Habib Bank Limited (HBL)">
                        <option value="Bank Alfalah Ltd">
                        <option value="United Bank Limited (UBL)">
                        <option value="MCB Bank Ltd">
                        <option value="Allied Bank Ltd (ABL)">
                        <option value="Faysal Bank Ltd">
                        <option value="Dubai Islamic Bank">
                        <option value="JazzCash Business">
                        <option value="EasyPaisa Merchant">
                        <option value="SadaPay Business">
                        <option value="NayaPay">
                    </datalist>
                </div>

                <div class="col-md-5">
                    <label class="form-label small fw-semibold">Account Title / Beneficiary Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control form-control-sm" name="account_title" placeholder="e.g. Falcon FMCG Distribution Co." required>
                </div>

                <div class="col-md-4">
                    <label class="form-label small fw-semibold">Account Number / Mobile Wallet # <span class="text-danger">*</span></label>
                    <input type="text" class="form-control form-control-sm font-monospace fw-bold text-dark" name="account_number" placeholder="e.g. 02140105893214 / 03001111111" required>
                </div>

                <div class="col-md-5">
                    <label class="form-label small fw-semibold">24-Digit IBAN (For Bank Transfers)</label>
                    <input type="text" class="form-control form-control-sm font-monospace" name="iban" placeholder="e.g. PK42MEZN0002140105893214">
                </div>

                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Raast ID / Linked Mobile #</label>
                    <input type="text" class="form-control form-control-sm" name="raast_id" placeholder="e.g. 03001111111">
                </div>

                <div class="col-md-6">
                    <label class="form-label small fw-semibold">Branch Name & City</label>
                    <input type="text" class="form-control form-control-sm" name="branch_name" placeholder="e.g. Satellite Town Branch, Gujranwala">
                </div>

                <div class="col-md-2">
                    <label class="form-label small fw-semibold">Branch Code</label>
                    <input type="text" class="form-control form-control-sm" name="branch_code" placeholder="e.g. 0214">
                </div>

                <div class="col-md-4">
                    <label class="form-label small fw-semibold">Display Priority Order</label>
                    <input type="number" class="form-control form-control-sm" name="display_order" value="1" min="0">
                </div>

                <div class="col-12">
                    <label class="form-label small fw-semibold">Instructions for Customers / Shopkeepers</label>
                    <input type="text" class="form-control form-control-sm" name="notes" placeholder="e.g. Please send payment screenshot via WhatsApp after transfer.">
                </div>

                <div class="col-12 d-flex flex-wrap gap-4 pt-2">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_primary" id="new_is_primary" value="1">
                        <label class="form-check-label small fw-semibold text-dark" for="new_is_primary">
                            Set as Default Primary Account
                        </label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="show_on_bills" id="new_show_bills" value="1" checked>
                        <label class="form-check-label small fw-semibold text-dark" for="new_show_bills">
                            Display on Customer Invoices & Public Payment Page
                        </label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_active" id="new_is_active" value="1" checked>
                        <label class="form-check-label small fw-semibold text-dark" for="new_is_active">
                            Active Channel
                        </label>
                    </div>
                </div>

                <div class="col-12 mt-3">
                    <button type="submit" class="btn btn-primary btn-sm px-4">Save Account</button>
                    <button type="button" class="btn btn-light border btn-sm ms-2" data-confirm-cancel data-confirm-target="panel-create-account">Cancel</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Accounts List & Management Table -->
<div class="card border-0 shadow-sm rounded-3">
    <div class="card-header bg-white py-3 border-0 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-credit-card-2-front me-2 text-primary"></i>Configured Accounts Ledger</h6>
            <small class="text-muted">Bank accounts and wallets displayed on customer statements and delivery bills</small>
        </div>
        <div>
            <button type="button" class="btn btn-sm btn-outline-dark" onclick="copyAllAccountsToClipboard();">
                <i class="bi bi-clipboard me-1"></i> Copy All Details
            </button>
        </div>
    </div>
    <div class="card-body p-0">
        <?php if (empty($accounts)): ?>
            <div class="p-4 text-center text-muted">No bank accounts or mobile wallets defined yet. Click "Add Bank / Wallet Account" to add one.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover table-responsive-card align-middle mb-0">
                    <thead class="table-light small">
                        <tr>
                            <th>Channel / Bank</th>
                            <th>Account Title</th>
                            <th>Account & IBAN Number</th>
                            <th>Branch / City</th>
                            <th>Raast / Notes</th>
                            <th class="text-center">Status</th>
                            <th class="text-end pe-3">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($accounts as $acc): 
                            $badgeColor = match ($acc['account_type']) {
                                'wallet' => 'bg-warning text-dark',
                                'raast' => 'bg-info text-dark',
                                default => 'bg-primary-subtle text-primary border border-primary-subtle'
                            };
                        ?>
                            <tr>
                                <td data-label="Channel">
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="p-2 rounded-2 <?= $acc['account_type'] === 'wallet' ? 'bg-warning-subtle text-warning' : 'bg-primary-subtle text-primary' ?>">
                                            <i class="bi <?= $acc['account_type'] === 'wallet' ? 'bi-phone' : 'bi-bank' ?> fs-5"></i>
                                        </div>
                                        <div>
                                            <div class="fw-bold text-dark"><?= htmlspecialchars($acc['bank_name']) ?></div>
                                            <span class="badge <?= $badgeColor ?> small"><?= strtoupper($acc['account_type']) ?></span>
                                            <?php if ($acc['is_primary']): ?>
                                                <span class="badge bg-success-subtle text-success border border-success-subtle small ms-1"><i class="bi bi-star-fill me-1"></i>Primary</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>

                                <td data-label="Title">
                                    <div class="fw-bold text-dark"><?= htmlspecialchars($acc['account_title']) ?></div>
                                </td>

                                <td data-label="Account #">
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <span class="font-monospace fw-bold text-dark fs-6"><?= htmlspecialchars($acc['account_number']) ?></span>
                                        <button type="button" class="btn btn-sm btn-light border py-0 px-2" title="Copy Number" onclick="copyText('<?= htmlspecialchars($acc['account_number']) ?>', this)">
                                            <i class="bi bi-copy"></i>
                                        </button>
                                    </div>
                                    <?php if (!empty($acc['iban'])): ?>
                                        <div class="text-muted font-monospace small">
                                            IBAN: <?= htmlspecialchars($acc['iban']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <td data-label="Branch">
                                    <?php if (!empty($acc['branch_name'])): ?>
                                        <div class="small text-dark"><?= htmlspecialchars($acc['branch_name']) ?></div>
                                        <?php if (!empty($acc['branch_code'])): ?>
                                            <small class="text-muted">Code: <?= htmlspecialchars($acc['branch_code']) ?></small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <small class="text-muted">-</small>
                                    <?php endif; ?>
                                </td>

                                <td data-label="Raast / Notes">
                                    <?php if (!empty($acc['raast_id'])): ?>
                                        <div class="small fw-semibold text-primary"><i class="bi bi-lightning-fill text-warning me-1"></i>Raast: <?= htmlspecialchars($acc['raast_id']) ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($acc['notes'])): ?>
                                        <small class="text-muted d-block text-truncate" style="max-width: 180px;" title="<?= htmlspecialchars($acc['notes']) ?>"><?= htmlspecialchars($acc['notes']) ?></small>
                                    <?php endif; ?>
                                </td>

                                <td data-label="Status" class="text-center">
                                    <?php if ($acc['is_active']): ?>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary-subtle text-muted border">Disabled</span>
                                    <?php endif; ?>
                                </td>

                                <td data-label="Action" class="text-end pe-3">
                                    <?php if ($isAdminOrAccountant): ?>
                                        <div class="d-inline-flex gap-1">
                                            <button type="button" class="btn btn-sm btn-outline-primary" data-confirm-trigger data-confirm-target="panel-edit-<?= $acc['id'] ?>" title="Edit Account">
                                                <i class="bi bi-pencil"></i>
                                            </button>

                                            <?php if (!$acc['is_primary']): ?>
                                                <form method="POST" action="<?= BASE_URL ?>/modules/accounts/index.php" class="d-inline">
                                                    <?= getCsrfField() ?>
                                                    <input type="hidden" name="set_primary" value="1">
                                                    <input type="hidden" name="account_id" value="<?= $acc['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-success" title="Set as Default Primary">
                                                        <i class="bi bi-star"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Strict No-Modal Inline Edit Panel -->
                                        <div id="panel-edit-<?= $acc['id'] ?>" class="inline-confirm-panel d-none text-start mt-2 p-3 bg-light rounded border">
                                            <div class="fw-bold small text-primary mb-2">Edit Account: <?= htmlspecialchars($acc['bank_name']) ?></div>
                                            <form method="POST" action="<?= BASE_URL ?>/modules/accounts/index.php">
                                                <?= getCsrfField() ?>
                                                <input type="hidden" name="save_account" value="1">
                                                <input type="hidden" name="account_id" value="<?= $acc['id'] ?>">

                                                <div class="row g-2">
                                                    <div class="col-md-4">
                                                        <label class="form-label small fw-semibold">Type</label>
                                                        <select class="form-select form-select-sm" name="account_type">
                                                            <option value="bank" <?= $acc['account_type'] === 'bank' ? 'selected' : '' ?>>Bank</option>
                                                            <option value="wallet" <?= $acc['account_type'] === 'wallet' ? 'selected' : '' ?>>Wallet</option>
                                                            <option value="raast" <?= $acc['account_type'] === 'raast' ? 'selected' : '' ?>>Raast</option>
                                                            <option value="other" <?= $acc['account_type'] === 'other' ? 'selected' : '' ?>>Other</option>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-8">
                                                        <label class="form-label small fw-semibold">Bank / Provider</label>
                                                        <input type="text" class="form-control form-control-sm" name="bank_name" value="<?= htmlspecialchars($acc['bank_name']) ?>" required>
                                                    </div>
                                                    <div class="col-12">
                                                        <label class="form-label small fw-semibold">Account Title</label>
                                                        <input type="text" class="form-control form-control-sm" name="account_title" value="<?= htmlspecialchars($acc['account_title']) ?>" required>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label class="form-label small fw-semibold">Account Number</label>
                                                        <input type="text" class="form-control form-control-sm font-monospace" name="account_number" value="<?= htmlspecialchars($acc['account_number']) ?>" required>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label class="form-label small fw-semibold">IBAN</label>
                                                        <input type="text" class="form-control form-control-sm font-monospace" name="iban" value="<?= htmlspecialchars($acc['iban'] ?? '') ?>">
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label class="form-label small fw-semibold">Branch Name</label>
                                                        <input type="text" class="form-control form-control-sm" name="branch_name" value="<?= htmlspecialchars($acc['branch_name'] ?? '') ?>">
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label class="form-label small fw-semibold">Raast ID</label>
                                                        <input type="text" class="form-control form-control-sm" name="raast_id" value="<?= htmlspecialchars($acc['raast_id'] ?? '') ?>">
                                                    </div>
                                                    <div class="col-12">
                                                        <label class="form-label small fw-semibold">Instructions / Notes</label>
                                                        <input type="text" class="form-control form-control-sm" name="notes" value="<?= htmlspecialchars($acc['notes'] ?? '') ?>">
                                                    </div>
                                                    <div class="col-12 d-flex flex-wrap gap-3 pt-1">
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="checkbox" name="is_primary" id="edit_pri_<?= $acc['id'] ?>" value="1" <?= $acc['is_primary'] ? 'checked' : '' ?>>
                                                            <label class="form-check-label small" for="edit_pri_<?= $acc['id'] ?>">Primary</label>
                                                        </div>
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="checkbox" name="is_active" id="edit_act_<?= $acc['id'] ?>" value="1" <?= $acc['is_active'] ? 'checked' : '' ?>>
                                                            <label class="form-check-label small" for="edit_act_<?= $acc['id'] ?>">Active</label>
                                                        </div>
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="checkbox" name="show_on_bills" id="edit_shw_<?= $acc['id'] ?>" value="1" <?= $acc['show_on_bills'] ? 'checked' : '' ?>>
                                                            <label class="form-check-label small" for="edit_shw_<?= $acc['id'] ?>">Show on Bills</label>
                                                        </div>
                                                    </div>
                                                    <div class="col-12 mt-2 d-flex justify-content-between">
                                                        <div class="d-flex gap-1">
                                                            <button type="submit" class="btn btn-sm btn-success">Update</button>
                                                            <button type="button" class="btn btn-sm btn-secondary" data-confirm-cancel data-confirm-target="panel-edit-<?= $acc['id'] ?>">Cancel</button>
                                                        </div>
                                                        <form method="POST" action="<?= BASE_URL ?>/modules/accounts/index.php" onsubmit="return true;">
                                                            <?= getCsrfField() ?>
                                                            <input type="hidden" name="delete_account" value="1">
                                                            <input type="hidden" name="account_id" value="<?= $acc['id'] ?>">
                                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete Account">Delete</button>
                                                        </form>
                                                    </div>
                                                </div>
                                            </form>
                                        </div>
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

<script>
function copyText(text, btnElem) {
    if (navigator.clipboard) {
        navigator.clipboard.writeText(text).then(() => {
            const originalHtml = btnElem.innerHTML;
            btnElem.innerHTML = '<i class="bi bi-check text-success"></i>';
            setTimeout(() => { btnElem.innerHTML = originalHtml; }, 1500);
        });
    }
}

function copyAllAccountsToClipboard() {
    let text = `*OFFICIAL COMPANY BANK & PAYMENT ACCOUNTS*\n` +
               `Falcon FMCG Distribution Co. - Gujranwala\n\n`;

    <?php foreach ($accounts as $acc): if (!$acc['is_active']) continue; ?>
        text += `🔹 *<?= addslashes(htmlspecialchars($acc['bank_name'])) ?>*\n` +
                `Account Title: <?= addslashes(htmlspecialchars($acc['account_title'])) ?>\n` +
                `Account Number: <?= addslashes(htmlspecialchars($acc['account_number'])) ?>\n` +
                <?php if (!empty($acc['iban'])): ?>
                `IBAN: <?= addslashes(htmlspecialchars($acc['iban'])) ?>\n` +
                <?php endif; ?>
                <?php if (!empty($acc['raast_id'])): ?>
                `Raast ID: <?= addslashes(htmlspecialchars($acc['raast_id'])) ?>\n` +
                <?php endif; ?>
                <?php if (!empty($acc['branch_name'])): ?>
                `Branch: <?= addslashes(htmlspecialchars($acc['branch_name'])) ?>\n` +
                <?php endif; ?>
                `\n`;
    <?php endforeach; ?>

    text += `Please share deposit receipt screenshot on WhatsApp after payment.`;

    if (navigator.clipboard) {
        navigator.clipboard.writeText(text).then(() => {
            alert('Official payment bank accounts copied to clipboard! You can paste and send on WhatsApp.');
        });
    }
}
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
