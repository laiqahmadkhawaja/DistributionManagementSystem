<?php
/**
 * Supplier Management Directory
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('suppliers', 'view');

$pdo = getDatabaseConnection();

$suppliers = $pdo->query("
    SELECT s.*,
           (SELECT COUNT(*) FROM `goods_receipts` WHERE supplier_id = s.id) as grn_count
    FROM `suppliers` s
    WHERE s.status = 'active'
    ORDER BY s.name ASC
")->fetchAll();

$totalPayables = 0.00;
foreach ($suppliers as $s) {
    $totalPayables += (float)$s['current_balance'];
}

// Handle Add Supplier Form POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_supplier'])) {
    requirePermission('suppliers', 'create');

    $name = sanitize($_POST['name'] ?? '');
    $companyName = sanitize($_POST['company_name'] ?? '');
    $contactPerson = sanitize($_POST['contact_person'] ?? '');
    $phone = sanitize($_POST['phone'] ?? '');
    $whatsapp = sanitize($_POST['whatsapp'] ?? $phone);
    $address = sanitize($_POST['address'] ?? '');
    $openingBalance = (float)($_POST['opening_balance'] ?? 0.00);

    if (empty($name) || empty($phone)) {
        setFlashMessage('error', 'Supplier name and phone are required.');
        redirect(BASE_URL . '/modules/suppliers/index.php');
    }

    try {
        $code = generateDocNumber($pdo, 'suppliers', 'code', 'SUP-');
        $stmt = $pdo->prepare("
            INSERT INTO `suppliers` (`code`, `name`, `company_name`, `contact_person`, `phone`, `whatsapp`, `address`, `opening_balance`, `current_balance`, `status`, `created_at`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW())
        ");
        $stmt->execute([$code, $name, $companyName, $contactPerson, $phone, $whatsapp, $address, $openingBalance, $openingBalance]);

        setFlashMessage('success', "Supplier '{$name}' registered successfully!");
        redirect(BASE_URL . '/modules/suppliers/index.php');

    } catch (Exception $e) {
        setFlashMessage('error', 'Error adding supplier: ' . $e->getMessage());
        redirect(BASE_URL . '/modules/suppliers/index.php');
    }
}

$pageTitle = 'Suppliers Directory';
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-3 gap-2">
    <div>
        <h4 class="fw-bold mb-0 text-dark"><i class="bi bi-building text-primary me-2"></i>Manufacturers & Suppliers</h4>
        <small class="text-muted">Supply Chain Partners, Accounts Payable & Ledger Statements</small>
    </div>
    <div>
        <button type="button" class="btn btn-primary btn-touch shadow-sm" data-confirm-trigger data-confirm-target="panel-add-supplier">
            <i class="bi bi-plus-circle me-1"></i> Add New Supplier
        </button>
    </div>
</div>

<!-- Inline Add Supplier Panel (Strict No-Modal UI) -->
<div id="panel-add-supplier" class="card border-0 shadow-sm rounded-3 mb-3 d-none">
    <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
        <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-plus-circle me-1 text-primary"></i>Register Supplier</h6>
        <button type="button" class="btn btn-sm btn-close" data-confirm-cancel data-confirm-target="panel-add-supplier"></button>
    </div>
    <div class="card-body p-3">
        <form method="POST" action="<?= BASE_URL ?>/modules/suppliers/index.php">
            <?= getCsrfField() ?>
            <input type="hidden" name="add_supplier" value="1">
            <div class="row g-2">
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">Supplier / Distributor Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control form-control-sm" name="name" required placeholder="e.g. Nestle Pakistan">
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">Company / Business Name</label>
                    <input type="text" class="form-control form-control-sm" name="company_name" placeholder="e.g. Nestle Official Hub">
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">Contact Person</label>
                    <input type="text" class="form-control form-control-sm" name="contact_person" placeholder="e.g. Tariq Butt">
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">Phone <span class="text-danger">*</span></label>
                    <input type="text" class="form-control form-control-sm" name="phone" required placeholder="0321 1234567">
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">Address</label>
                    <input type="text" class="form-control form-control-sm" name="address" placeholder="e.g. GT Road Gujranwala">
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">Opening Payable Balance (Rs.)</label>
                    <input type="number" class="form-control form-control-sm" name="opening_balance" value="0.00" step="0.01">
                </div>
                <div class="col-12 mt-3">
                    <button type="submit" class="btn btn-primary btn-sm px-3">Save Supplier</button>
                    <button type="button" class="btn btn-light border btn-sm ms-2" data-confirm-cancel data-confirm-target="panel-add-supplier">Cancel</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Supplier Payables KPI -->
<div class="card border-0 shadow-sm rounded-3 mb-3">
    <div class="card-body p-3">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <span class="text-muted small">Total Supplier Accounts Payable Liability</span>
                <h4 class="fw-bold text-danger mb-0"><?= formatMoney($totalPayables) ?></h4>
            </div>
            <span class="badge bg-light text-dark border"><?= count($suppliers) ?> Active Suppliers</span>
        </div>
    </div>
</div>

<!-- Suppliers Table -->
<div class="card border-0 shadow-sm rounded-3">
    <div class="card-body p-0">
        <?php if (empty($suppliers)): ?>
            <div class="p-4 text-center text-muted">No suppliers registered.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover table-responsive-card align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Supplier Name / Code</th>
                            <th>Contact Person</th>
                            <th>Phone / WhatsApp</th>
                            <th>City / Address</th>
                            <th class="text-end">Current Payable Due</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($suppliers as $sup): ?>
                            <tr>
                                <td data-label="Supplier">
                                    <div class="fw-bold text-dark"><?= htmlspecialchars($sup['name']) ?></div>
                                    <small class="text-muted"><?= htmlspecialchars($sup['code']) ?> <?= $sup['company_name'] ? '· ' . htmlspecialchars($sup['company_name']) : '' ?></small>
                                </td>
                                <td data-label="Contact"><?= htmlspecialchars($sup['contact_person'] ?? '-') ?></td>
                                <td data-label="Phone">
                                    <a href="tel:<?= htmlspecialchars($sup['phone']) ?>" class="text-decoration-none">
                                        <?= htmlspecialchars($sup['phone']) ?>
                                    </a>
                                </td>
                                <td data-label="City"><?= htmlspecialchars($sup['city'] ?? 'Gujranwala') ?></td>
                                <td data-label="Payable" class="text-end fw-bold text-danger">
                                    <?= formatMoney($sup['current_balance']) ?>
                                </td>
                                <td data-label="Action" class="text-end">
                                    <a href="<?= BASE_URL ?>/modules/suppliers/ledger.php?id=<?= $sup['id'] ?>" class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-journal-text me-1"></i> Ledger
                                    </a>
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
