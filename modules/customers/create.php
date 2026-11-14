<?php
/**
 * Register New Retail Shop / Customer
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('customers', 'create');

$pdo = getDatabaseConnection();
$currentUser = getCurrentUser();
$currentEmp = getCurrentEmployee();
$roleSlug = $currentUser['role_slug'];

// Area Scoping: If Order Booker, only show assigned areas
$assignedAreaIds = getUserAssignedAreaIds($currentUser['id']);
$areaPlaceholders = !empty($assignedAreaIds) ? implode(',', array_map('intval', $assignedAreaIds)) : '0';

if ($roleSlug === ROLE_SUPER_ADMIN || $roleSlug === ROLE_ADMIN) {
    $areas = $pdo->query("SELECT id, name FROM `areas` WHERE status = 'active' ORDER BY name ASC")->fetchAll();
} else {
    $areas = $pdo->query("SELECT id, name FROM `areas` WHERE status = 'active' AND id IN ({$areaPlaceholders}) ORDER BY name ASC")->fetchAll();
}

$error = null;

// Handle POST Customer Registration BEFORE HTML output
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $shopName = sanitize($_POST['shop_name'] ?? '');
    $ownerName = sanitize($_POST['owner_name'] ?? '');
    $phone = sanitize($_POST['phone'] ?? '');
    $whatsapp = sanitize($_POST['whatsapp'] ?? $phone);
    $address = sanitize($_POST['address'] ?? '');
    $areaId = (int)($_POST['area_id'] ?? 0);
    $customerType = sanitize($_POST['customer_type'] ?? 'retailer');
    $creditLimit = (float)($_POST['credit_limit'] ?? 50000.00);
    $openingBalance = (float)($_POST['opening_balance'] ?? 0.00);
    $paymentTerms = sanitize($_POST['payment_terms'] ?? 'Due on Delivery');
    $bookerId = $currentEmp['id'] ?? null;

    if (empty($shopName) || empty($phone) || empty($address) || !$areaId) {
        $error = "Please fill in all mandatory fields (Shop Name, Phone, Address, Area).";
    } else {
        // Duplicate check
        $dupStmt = $pdo->prepare("SELECT id, shop_name FROM `customers` WHERE phone = ? OR (shop_name = ? AND area_id = ?)");
        $dupStmt->execute([$phone, $shopName, $areaId]);
        $existing = $dupStmt->fetch();

        if ($existing) {
            $error = "A customer shop already exists with this phone number or shop name in the selected area (Shop: {$existing['shop_name']}).";
        } else {
            try {
                $pdo->beginTransaction();

                $code = generateDocNumber($pdo, 'customers', 'code', 'CUST-');

                $stmt = $pdo->prepare("
                    INSERT INTO `customers` (
                        `code`, `shop_name`, `owner_name`, `phone`, `whatsapp`, 
                        `address`, `area_id`, `customer_type`, `credit_limit`, 
                        `opening_balance`, `current_balance`, `payment_terms`, 
                        `assigned_order_booker_id`, `status`, `created_at`
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW())
                ");
                $stmt->execute([
                    $code, $shopName, $ownerName, $phone, $whatsapp,
                    $address, $areaId, $customerType, $creditLimit,
                    $openingBalance, $openingBalance, $paymentTerms,
                    $bookerId
                ]);
                $customerId = (int)$pdo->lastInsertId();

                // If opening balance > 0, post balanced double-entry opening journal entry
                if ($openingBalance > 0) {
                    $recAcc = getSystemAccount($pdo, 'receivables');
                    $equityAcc = getSystemAccount($pdo, 'retained_earnings');

                    $lines = [
                        [
                            'account_id' => $recAcc['id'],
                            'debit' => $openingBalance,
                            'credit' => 0.00,
                            'memo' => "Opening customer receivable balance for {$shopName}"
                        ],
                        [
                            'account_id' => $equityAcc['id'],
                            'debit' => 0.00,
                            'credit' => $openingBalance,
                            'memo' => "Opening equity balance offset for {$shopName}"
                        ]
                    ];

                    postJournalEntry($pdo, date('Y-m-d'), 'customer_opening_balance', $customerId, "Opening balance setup for {$shopName}", $lines, $currentUser['id']);
                }

                logAudit($pdo, 'customers', $customerId, 'create', null, ['code' => $code, 'shop_name' => $shopName]);
                $pdo->commit();

                setFlashMessage('success', "Customer shop '{$shopName}' ({$code}) registered successfully!");
                redirect(BASE_URL . '/modules/customers/index.php');

            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = "Database error: " . $e->getMessage();
            }
        }
    }
}

$pageTitle = 'Register New Retail Shop';
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="fw-bold mb-0 text-dark"><i class="bi bi-shop-window text-primary me-2"></i>Register New Retail Shop</h4>
        <small class="text-muted">Onboard new customer into sales territory</small>
    </div>
    <a href="<?= BASE_URL ?>/modules/customers/index.php" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i> Cancel
    </a>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger shadow-sm rounded-3 py-2 px-3 mb-3">
        <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($error) ?>
    </div>
<?php endif; ?>

<div class="card border-0 shadow-sm rounded-3">
    <div class="card-body p-4">
        <form method="POST" action="<?= BASE_URL ?>/modules/customers/create.php">
            <?= getCsrfField() ?>

            <div class="row g-3">
                <div class="col-12 col-md-6">
                    <label class="form-label small fw-semibold">Shop / Store Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="shop_name" placeholder="e.g. Al-Madina Super Store" required>
                </div>

                <div class="col-12 col-md-6">
                    <label class="form-label small fw-semibold">Owner / Proprietor Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="owner_name" placeholder="e.g. Haji Muhammad Tariq" required>
                </div>

                <div class="col-12 col-md-6">
                    <label class="form-label small fw-semibold">Mobile Phone Number <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="phone" placeholder="0300 1234567" required>
                </div>

                <div class="col-12 col-md-6">
                    <label class="form-label small fw-semibold">WhatsApp Number</label>
                    <input type="text" class="form-control" name="whatsapp" placeholder="0300 1234567">
                </div>

                <div class="col-12 col-md-6">
                    <label class="form-label small fw-semibold">Sales Territory / Area <span class="text-danger">*</span></label>
                    <select class="form-select" name="area_id" required>
                        <option value="">-- Choose Assigned Area --</option>
                        <?php foreach ($areas as $a): ?>
                            <option value="<?= $a['id'] ?>"><?= htmlspecialchars($a['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12 col-md-6">
                    <label class="form-label small fw-semibold">Customer Category</label>
                    <select class="form-select" name="customer_type">
                        <option value="retailer">Retailer (Corner Grocery / Mart)</option>
                        <option value="wholesaler">Wholesaler</option>
                        <option value="distributor">Distributor</option>
                        <option value="corporate">Corporate / Institution</option>
                    </select>
                </div>

                <div class="col-12">
                    <label class="form-label small fw-semibold">Physical Shop Address <span class="text-danger">*</span></label>
                    <textarea class="form-control" name="address" rows="2" placeholder="e.g. Shop 12, Main Bazaar, Near Jamia Masjid" required></textarea>
                </div>

                <div class="col-12 col-md-4">
                    <label class="form-label small fw-semibold">Credit Limit (Rs.)</label>
                    <input type="number" class="form-control" name="credit_limit" value="50000" step="500">
                </div>

                <div class="col-12 col-md-4">
                    <label class="form-label small fw-semibold">Opening Balance (Rs.)</label>
                    <input type="number" class="form-control" name="opening_balance" value="0.00" step="0.01">
                    <small class="text-muted" style="font-size: 0.72rem;">If customer already owes money.</small>
                </div>

                <div class="col-12 col-md-4">
                    <label class="form-label small fw-semibold">Payment Terms</label>
                    <input type="text" class="form-control" name="payment_terms" value="Due on Delivery">
                </div>

                <div class="col-12 mt-4">
                    <button type="submit" class="btn btn-primary btn-touch shadow-sm px-4">
                        <i class="bi bi-check-lg me-1"></i> Save & Register Shop
                    </button>
                    <a href="<?= BASE_URL ?>/modules/customers/index.php" class="btn btn-light border ms-2">Cancel</a>
                </div>
            </div>
        </form>
    </div>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
