<?php
/**
 * Edit Product & Multi-Tier Pricing
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('products', 'edit');

$pdo = getDatabaseConnection();
$currentUser = getCurrentUser();

$productId = (int)($_GET['id'] ?? 0);
if (!$productId) {
    setFlashMessage('error', 'Invalid Product ID.');
    redirect(BASE_URL . '/modules/products/index.php');
}

// Fetch Product
$stmt = $pdo->prepare("SELECT p.*, c.name as category_name, b.name as brand_name, u.name as unit_name FROM `products` p JOIN `categories` c ON p.category_id = c.id LEFT JOIN `brands` b ON p.brand_id = b.id JOIN `units` u ON p.base_unit_id = u.id WHERE p.id = ? LIMIT 1");
$stmt->execute([$productId]);
$product = $stmt->fetch();

if (!$product) {
    setFlashMessage('error', 'Product not found.');
    redirect(BASE_URL . '/modules/products/index.php');
}

$error = null;

// Handle POST: Update or Status Toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $action = sanitize($_POST['action'] ?? 'update');

    // 1. SOFT DELETE / DEACTIVATE
    if ($action === 'soft_delete') {
        requirePermission('products', 'delete');
        $upd = $pdo->prepare("UPDATE `products` SET `status` = 'inactive', `updated_at` = NOW() WHERE `id` = ?");
        $upd->execute([$productId]);

        logAudit($pdo, 'products', $productId, 'soft_delete', null, ['name' => $product['name'], 'code' => $product['code']]);
        setFlashMessage('success', "Product '{$product['name']}' has been deactivated (soft deleted). All historical sales orders and ledger movements remain safe.");
        redirect(BASE_URL . '/modules/products/index.php');
    }

    // 2. RESTORE / REACTIVATE
    if ($action === 'restore') {
        requirePermission('products', 'edit');
        $upd = $pdo->prepare("UPDATE `products` SET `status` = 'active', `updated_at` = NOW() WHERE `id` = ?");
        $upd->execute([$productId]);

        logAudit($pdo, 'products', $productId, 'restore', null, ['name' => $product['name'], 'code' => $product['code']]);
        setFlashMessage('success', "Product '{$product['name']}' has been restored to active status.");
        redirect(BASE_URL . '/modules/products/index.php');
    }

    // 3. REGULAR UPDATE
    if ($action === 'update') {
        $name = sanitize($_POST['name'] ?? '');
        $sku = sanitize($_POST['sku'] ?? '');
        $barcode = sanitize($_POST['barcode'] ?? '');
        $categoryId = (int)($_POST['category_id'] ?? 0);
        $brandId = !empty($_POST['brand_id']) ? (int)$_POST['brand_id'] : null;
        $unitId = (int)($_POST['base_unit_id'] ?? 1);

        $purchasePrice = (float)($_POST['purchase_price'] ?? 0.00);
        $tradePrice = (float)($_POST['trade_price'] ?? 0.00);
        $wholesalePrice = (float)($_POST['wholesale_price'] ?? 0.00);
        $retailPrice = (float)($_POST['retail_price'] ?? 0.00);
        $minSalePrice = (float)($_POST['min_sale_price'] ?? 0.00);
        $taxRate = (float)($_POST['tax_rate'] ?? 0.00);
        $reorderLevel = (float)($_POST['reorder_level'] ?? 10.00);
        $isBatchManaged = !empty($_POST['is_batch_managed']) ? 1 : 0;
        $status = in_array($_POST['status'] ?? '', ['active', 'inactive']) ? $_POST['status'] : $product['status'];
        $description = sanitize($_POST['description'] ?? '');

        if (empty($name) || !$categoryId || !$unitId) {
            $error = "Please provide product name, category, and base unit.";
        } else {
            // Check unique barcode if entered
            if (!empty($barcode)) {
                $chk = $pdo->prepare("SELECT id FROM `products` WHERE `barcode` = ? AND id != ? LIMIT 1");
                $chk->execute([$barcode, $productId]);
                if ($chk->fetch()) {
                    $error = "Barcode '{$barcode}' is already assigned to another product.";
                }
            }

            if (!$error) {
                try {
                    $upd = $pdo->prepare("
                        UPDATE `products` SET
                            `sku` = ?, `barcode` = ?, `name` = ?, `category_id` = ?, `brand_id` = ?,
                            `base_unit_id` = ?, `purchase_price` = ?, `wholesale_price` = ?, `retail_price` = ?,
                            `trade_price` = ?, `min_sale_price` = ?, `tax_rate` = ?, `reorder_level` = ?,
                            `is_batch_managed` = ?, `description` = ?, `status` = ?, `updated_at` = NOW()
                        WHERE `id` = ?
                    ");
                    $upd->execute([
                        $sku, $barcode, $name, $categoryId, $brandId,
                        $unitId, $purchasePrice, $wholesalePrice, $retailPrice,
                        $tradePrice, $minSalePrice, $taxRate, $reorderLevel,
                        $isBatchManaged, $description, $status, $productId
                    ]);

                    logAudit($pdo, 'products', $productId, 'update', null, [
                        'name' => $name,
                        'trade_price' => $tradePrice,
                        'status' => $status
                    ]);

                    setFlashMessage('success', "Product '{$name}' ({$product['code']}) updated successfully!");
                    redirect(BASE_URL . '/modules/products/index.php');

                } catch (Exception $e) {
                    $error = "Database error: " . $e->getMessage();
                }
            }
        }
    }
}

// Fetch categories, brands, units
$currentCatId = (int)$product['category_id'];
$categories = $pdo->query("SELECT id, name FROM `categories` WHERE status = 'active' OR id = {$currentCatId} ORDER BY name ASC")->fetchAll();

$currentBrandId = (int)($product['brand_id'] ?? 0);
$brandFilter = $currentBrandId > 0 ? "status = 'active' OR id = {$currentBrandId}" : "status = 'active'";
$brands = $pdo->query("SELECT id, name FROM `brands` WHERE {$brandFilter} ORDER BY name ASC")->fetchAll();

$units = $pdo->query("SELECT id, name, code FROM `units` ORDER BY id ASC")->fetchAll();

// Get stock quantity
$stockQty = (float)$pdo->query("SELECT COALESCE(SUM(quantity_in) - SUM(quantity_out), 0) FROM `stock_ledger` WHERE product_id = {$productId}")->fetchColumn();

$pageTitle = 'Edit Product - ' . $product['name'];
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<!-- Header -->
<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-3 gap-2">
    <div>
        <div class="d-flex align-items-center gap-2">
            <h4 class="fw-bold mb-0 text-dark">
                <i class="bi bi-pencil-square text-primary me-2"></i>Edit Product
            </h4>
            <?php if ($product['status'] === 'active'): ?>
                <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-2 py-1">Active</span>
            <?php else: ?>
                <span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary border-opacity-25 px-2 py-1">Inactive (Soft Deleted)</span>
            <?php endif; ?>
        </div>
        <small class="text-muted">
            Code: <code><?= htmlspecialchars($product['code']) ?></code> · Current Warehouse Stock: <strong><?= $stockQty ?> <?= htmlspecialchars($product['unit_name']) ?></strong>
        </small>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/modules/products/index.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back to Products
        </a>
    </div>
</div>

<?php if ($product['status'] === 'inactive'): ?>
    <div class="alert alert-warning border-0 shadow-sm rounded-3 py-2 px-3 mb-3 d-flex align-items-center justify-content-between">
        <div>
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <strong>This product is Soft-Deleted (Inactive).</strong> It is hidden from active order booking and purchase entry, but its historical invoices and reports are preserved.
        </div>
        <form method="POST" action="<?= BASE_URL ?>/modules/products/edit.php?id=<?= $productId ?>" class="m-0">
            <?= getCsrfField() ?>
            <input type="hidden" name="action" value="restore">
            <button type="submit" class="btn btn-sm btn-success fw-semibold">
                <i class="bi bi-arrow-counterclockwise me-1"></i> Reactivate Product
            </button>
        </form>
    </div>
<?php endif; ?>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger shadow-sm rounded-3 py-2 px-3 mb-3">
        <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($error) ?>
    </div>
<?php endif; ?>

<!-- Main Edit Form -->
<div class="card border-0 shadow-sm rounded-3 mb-4">
    <div class="card-body p-4">
        <form method="POST" action="<?= BASE_URL ?>/modules/products/edit.php?id=<?= $productId ?>" id="editProductForm">
            <?= getCsrfField() ?>
            <input type="hidden" name="action" value="update">

            <div class="row g-3">
                <!-- Basic Information -->
                <div class="col-12 col-md-3">
                    <label class="form-label small fw-semibold">Product Code</label>
                    <input type="text" class="form-control font-monospace bg-light" value="<?= htmlspecialchars($product['code']) ?>" readonly>
                    <small class="text-muted" style="font-size: 0.72rem;">System generated product code</small>
                </div>

                <div class="col-12 col-md-9">
                    <label class="form-label small fw-semibold">Product Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="name" value="<?= htmlspecialchars($product['name']) ?>" required>
                </div>

                <div class="col-6 col-md-4">
                    <label class="form-label small fw-semibold">Barcode / EAN</label>
                    <input type="text" class="form-control" name="barcode" value="<?= htmlspecialchars($product['barcode'] ?? '') ?>" placeholder="e.g. 8961011001">
                </div>

                <div class="col-6 col-md-4">
                    <label class="form-label small fw-semibold">SKU / Short Code</label>
                    <input type="text" class="form-control" name="sku" value="<?= htmlspecialchars($product['sku'] ?? '') ?>" placeholder="e.g. NES-MILK-1L">
                </div>

                <div class="col-12 col-md-4">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <label class="form-label small fw-semibold mb-0">Category <span class="text-danger">*</span></label>
                        <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none fw-semibold" data-bs-toggle="modal" data-bs-target="#quickAddCategoryModal">
                            <i class="bi bi-plus-circle me-1"></i>+ New Category
                        </button>
                    </div>
                    <select class="form-select" name="category_id" id="categorySelect" required>
                        <option value="">-- Choose Category --</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>" <?= $product['category_id'] == $cat['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12 col-md-4">
                    <label class="form-label small fw-semibold">Brand / Manufacturer</label>
                    <select class="form-select" name="brand_id">
                        <option value="">-- Choose Brand --</option>
                        <?php foreach ($brands as $br): ?>
                            <option value="<?= $br['id'] ?>" <?= $product['brand_id'] == $br['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($br['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12 col-md-4">
                    <label class="form-label small fw-semibold">Base Unit <span class="text-danger">*</span></label>
                    <select class="form-select" name="base_unit_id" required>
                        <?php foreach ($units as $u): ?>
                            <option value="<?= $u['id'] ?>" <?= $product['base_unit_id'] == $u['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($u['name']) ?> (<?= htmlspecialchars($u['code']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12 col-md-4">
                    <label class="form-label small fw-semibold">Status</label>
                    <select class="form-select" name="status">
                        <option value="active" <?= $product['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $product['status'] === 'inactive' ? 'selected' : '' ?>>Inactive (Soft-Deleted)</option>
                    </select>
                </div>

                <!-- Multi-Tier Pricing Fields -->
                <div class="col-12 mt-4">
                    <h6 class="fw-bold text-dark border-bottom pb-2">
                        <i class="bi bi-cash-stack me-1 text-success"></i>Multi-Tier Pricing Configuration (Rs.)
                    </h6>
                </div>

                <div class="col-6 col-md-4">
                    <label class="form-label small fw-semibold">Purchase Cost Rate (WAC)</label>
                    <input type="number" class="form-control" name="purchase_price" value="<?= htmlspecialchars($product['purchase_price']) ?>" step="0.01">
                </div>

                <div class="col-6 col-md-4">
                    <label class="form-label small fw-semibold text-primary">Trade Price (Standard Order Rate) <span class="text-danger">*</span></label>
                    <input type="number" class="form-control border-primary" name="trade_price" value="<?= htmlspecialchars($product['trade_price']) ?>" step="0.01" required>
                </div>

                <div class="col-6 col-md-4">
                    <label class="form-label small fw-semibold text-success">Wholesale Price (Bulk Rate)</label>
                    <input type="number" class="form-control" name="wholesale_price" value="<?= htmlspecialchars($product['wholesale_price']) ?>" step="0.01">
                </div>

                <div class="col-6 col-md-4">
                    <label class="form-label small fw-semibold">Retail MRP Price</label>
                    <input type="number" class="form-control" name="retail_price" value="<?= htmlspecialchars($product['retail_price']) ?>" step="0.01">
                </div>

                <div class="col-6 col-md-4">
                    <label class="form-label small fw-semibold text-danger">Minimum Allowed Sale Price</label>
                    <input type="number" class="form-control" name="min_sale_price" value="<?= htmlspecialchars($product['min_sale_price']) ?>" step="0.01">
                </div>

                <div class="col-6 col-md-4">
                    <label class="form-label small fw-semibold">Reorder Alert Stock Level</label>
                    <input type="number" class="form-control" name="reorder_level" value="<?= htmlspecialchars($product['reorder_level']) ?>" step="1">
                </div>

                <div class="col-12 mt-3">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="batch_tracking" name="is_batch_managed" value="1" <?= !empty($product['is_batch_managed']) ? 'checked' : '' ?>>
                        <label class="form-check-label fw-semibold small" for="batch_tracking">
                            Enable Batch / Lot & Expiry Date Tracking for this product
                        </label>
                    </div>
                </div>

                <div class="col-12 mb-3">
                    <label class="form-label small fw-semibold">Description / Notes</label>
                    <textarea class="form-control" name="description" rows="2"><?= htmlspecialchars($product['description'] ?? '') ?></textarea>
                </div>

                <!-- Form Action Buttons -->
                <div class="col-12 mt-3 d-flex flex-wrap align-items-center justify-content-between gap-2 border-top pt-3">
                    <div>
                        <button type="submit" class="btn btn-primary btn-touch shadow-sm px-4 fw-semibold">
                            <i class="bi bi-check-lg me-1"></i> Update Product
                        </button>
                        <a href="<?= BASE_URL ?>/modules/products/index.php" class="btn btn-light border ms-2">Cancel</a>
                    </div>
                    
                    <?php if (hasPermission('products', 'delete')): ?>
                        <div>
                            <?php if ($product['status'] === 'active'): ?>
                                <button type="button" class="btn btn-outline-danger btn-sm" onclick="confirmProductSoftDelete()">
                                    <i class="bi bi-trash3 me-1"></i> Soft Delete Product
                                </button>
                            <?php else: ?>
                                <button type="button" class="btn btn-outline-success btn-sm" onclick="confirmProductRestore()">
                                    <i class="bi bi-arrow-counterclockwise me-1"></i> Reactivate Product
                                </button>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ==============================================================
     MODAL: Quick Add Category (AJAX)
     ============================================================== -->
<div class="modal fade" id="quickAddCategoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold">
                    <i class="bi bi-folder-plus me-1"></i> Quick Add Category
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div id="quickCatAlert" class="alert alert-danger d-none py-2 px-3 small"></div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold">Category Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="quickCatName" placeholder="e.g. Dairy & Frozen Goods" required>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Category Code</label>
                    <input type="text" class="form-control font-monospace text-uppercase" id="quickCatCode" placeholder="e.g. DAIR (auto if blank)">
                    <small class="text-muted" style="font-size: 0.72rem;">Leave blank to auto-generate from name</small>
                </div>
                <div class="mb-2">
                    <label class="form-label small fw-semibold">Description</label>
                    <textarea class="form-control" id="quickCatDesc" rows="2" placeholder="Optional category description..."></textarea>
                </div>
            </div>
            <div class="modal-footer bg-light justify-content-between">
                <a href="<?= BASE_URL ?>/modules/products/categories.php" target="_blank" class="small text-muted text-decoration-none">
                    <i class="bi bi-box-arrow-up-right me-1"></i> Full Categories Manager
                </a>
                <div>
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary btn-sm px-3 fw-semibold" id="quickCatSubmitBtn" onclick="submitQuickCategory()">
                        <i class="bi bi-check-lg me-1"></i> Save & Select
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ==============================================================
     MODAL: Confirm Soft Delete / Reactivate Product
     ============================================================== -->
<div class="modal fade" id="productStatusModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST" action="<?= BASE_URL ?>/modules/products/edit.php?id=<?= $productId ?>">
                <?= getCsrfField() ?>
                <input type="hidden" name="action" id="productModalAction" value="soft_delete">

                <div class="modal-header" id="prodModalHeader">
                    <h5 class="modal-title fw-bold" id="prodModalTitle">Confirm Action</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4 text-center">
                    <div id="prodModalIcon" class="mb-3 fs-1"></div>
                    <h5 class="fw-bold mb-2" id="prodModalPrompt"></h5>
                    <p class="text-muted small mb-0" id="prodModalNote"></p>
                </div>
                <div class="modal-footer bg-light justify-content-center">
                    <button type="button" class="btn btn-secondary px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn px-4 fw-semibold" id="prodModalBtn"></button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function submitQuickCategory() {
    var nameInput = document.getElementById('quickCatName');
    var codeInput = document.getElementById('quickCatCode');
    var descInput = document.getElementById('quickCatDesc');
    var alertBox = document.getElementById('quickCatAlert');
    var btn = document.getElementById('quickCatSubmitBtn');

    var name = nameInput.value.trim();
    if (!name) {
        alertBox.className = 'alert alert-danger py-2 px-3 small';
        alertBox.innerText = 'Please enter a category name.';
        return;
    }

    alertBox.className = 'alert alert-danger d-none py-2 px-3 small';
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving...';

    var formData = new FormData();
    formData.append('ajax_quick_add', '1');
    formData.append('csrf_token', '<?= getCsrfToken() ?>');
    formData.append('name', name);
    formData.append('code', codeInput.value.trim());
    formData.append('description', descInput.value.trim());

    fetch('<?= BASE_URL ?>/modules/products/categories.php', {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: formData
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-check-lg me-1"></i> Save & Select';

        if (data.success) {
            var select = document.getElementById('categorySelect');
            var opt = document.createElement('option');
            opt.value = data.category.id;
            opt.text = data.category.name;
            opt.selected = true;
            select.appendChild(opt);

            // Close modal
            var modalEl = document.getElementById('quickAddCategoryModal');
            var modalInstance = bootstrap.Modal.getInstance(modalEl);
            if (modalInstance) {
                modalInstance.hide();
            }

            // Clear inputs
            nameInput.value = '';
            codeInput.value = '';
            descInput.value = '';
        } else {
            alertBox.className = 'alert alert-danger py-2 px-3 small';
            alertBox.innerText = data.error || 'Failed to create category.';
        }
    })
    .catch(function(err) {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-check-lg me-1"></i> Save & Select';
        alertBox.className = 'alert alert-danger py-2 px-3 small';
        alertBox.innerText = 'Network or server error while adding category.';
    });
}

function confirmProductSoftDelete() {
    document.getElementById('productModalAction').value = 'soft_delete';
    document.getElementById('prodModalHeader').className = 'modal-header bg-danger text-white';
    document.getElementById('prodModalTitle').innerText = 'Soft Delete Product';
    document.getElementById('prodModalIcon').className = 'bi bi-trash3 text-danger fs-1';
    document.getElementById('prodModalPrompt').innerText = 'Deactivate "<?= htmlspecialchars(addslashes($product['name'])) ?>"?';
    document.getElementById('prodModalNote').innerText = 'This product will be marked inactive and hidden from active sales orders and the booking app. All historical invoices, deliveries, and stock ledger entries remain permanently safe.';
    document.getElementById('prodModalBtn').className = 'btn btn-danger px-4 fw-semibold';
    document.getElementById('prodModalBtn').innerHTML = '<i class="bi bi-trash3 me-1"></i> Deactivate (Soft Delete)';

    var modal = new bootstrap.Modal(document.getElementById('productStatusModal'));
    modal.show();
}

function confirmProductRestore() {
    document.getElementById('productModalAction').value = 'restore';
    document.getElementById('prodModalHeader').className = 'modal-header bg-success text-white';
    document.getElementById('prodModalTitle').innerText = 'Reactivate Product';
    document.getElementById('prodModalIcon').className = 'bi bi-arrow-counterclockwise text-success fs-1';
    document.getElementById('prodModalPrompt').innerText = 'Reactivate "<?= htmlspecialchars(addslashes($product['name'])) ?>"?';
    document.getElementById('prodModalNote').innerText = 'This product will be returned to active status and will appear again in the Order Booker app and sales catalogue.';
    document.getElementById('prodModalBtn').className = 'btn btn-success px-4 fw-semibold text-white';
    document.getElementById('prodModalBtn').innerHTML = '<i class="bi bi-check-circle me-1"></i> Reactivate Product';

    var modal = new bootstrap.Modal(document.getElementById('productStatusModal'));
    modal.show();
}
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
