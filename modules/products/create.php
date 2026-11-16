<?php
/**
 * Create New Product & Pricing Matrix
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('products', 'create');

$pdo = getDatabaseConnection();
$currentUser = getCurrentUser();

$categories = $pdo->query("SELECT id, name FROM `categories` WHERE status = 'active' ORDER BY name ASC")->fetchAll();
$brands = $pdo->query("SELECT id, name FROM `brands` WHERE status = 'active' ORDER BY name ASC")->fetchAll();
$units = $pdo->query("SELECT id, name, code FROM `units` ORDER BY id ASC")->fetchAll();

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
    $description = sanitize($_POST['description'] ?? '');

    if (empty($name) || !$categoryId || !$unitId) {
        $error = "Please provide product name, category, and base unit.";
    } else {
        try {
            $code = generateDocNumber($pdo, 'products', 'code', 'PRD-');

            $stmt = $pdo->prepare("
                INSERT INTO `products` (
                    `code`, `sku`, `barcode`, `name`, `category_id`, `brand_id`, 
                    `base_unit_id`, `purchase_price`, `wholesale_price`, `retail_price`, 
                    `trade_price`, `min_sale_price`, `tax_rate`, `reorder_level`, 
                    `is_batch_managed`, `description`, `status`, `created_at`
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW())
            ");
            $stmt->execute([
                $code, $sku, $barcode, $name, $categoryId, $brandId,
                $unitId, $purchasePrice, $wholesalePrice, $retailPrice,
                $tradePrice, $minSalePrice, $taxRate, $reorderLevel,
                $isBatchManaged, $description
            ]);
            $productId = (int)$pdo->lastInsertId();

            logAudit($pdo, 'products', $productId, 'create', null, ['code' => $code, 'name' => $name, 'trade_price' => $tradePrice]);
            setFlashMessage('success', "Product '{$name}' ({$code}) created successfully!");
            redirect(BASE_URL . '/modules/products/index.php');

        } catch (Exception $e) {
            $error = "Database error: " . $e->getMessage();
        }
    }
}

$pageTitle = 'Add New Product';
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="fw-bold mb-0 text-dark"><i class="bi bi-tag text-primary me-2"></i>Add New Product</h4>
        <small class="text-muted">Define product specifications and multi-tier pricing</small>
    </div>
    <a href="<?= BASE_URL ?>/modules/products/index.php" class="btn btn-sm btn-outline-secondary">
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
        <form method="POST" action="<?= BASE_URL ?>/modules/products/create.php">
            <?= getCsrfField() ?>

            <div class="row g-3">
                <div class="col-12 col-md-6">
                    <label class="form-label small fw-semibold">Product Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="name" placeholder="e.g. MilkPak 1 Liter" required>
                </div>

                <div class="col-6 col-md-3">
                    <label class="form-label small fw-semibold">Barcode / EAN</label>
                    <input type="text" class="form-control" name="barcode" placeholder="e.g. 8961011001">
                </div>

                <div class="col-6 col-md-3">
                    <label class="form-label small fw-semibold">SKU / Short Code</label>
                    <input type="text" class="form-control" name="sku" placeholder="e.g. NES-MILK-1L">
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
                            <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12 col-md-4">
                    <label class="form-label small fw-semibold">Brand / Manufacturer</label>
                    <select class="form-select" name="brand_id">
                        <option value="">-- Choose Brand --</option>
                        <?php foreach ($brands as $br): ?>
                            <option value="<?= $br['id'] ?>"><?= htmlspecialchars($br['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12 col-md-4">
                    <label class="form-label small fw-semibold">Base Unit <span class="text-danger">*</span></label>
                    <select class="form-select" name="base_unit_id" required>
                        <?php foreach ($units as $u): ?>
                            <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['name']) ?> (<?= htmlspecialchars($u['code']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Multi-Tier Pricing Fields -->
                <div class="col-12 mt-4">
                    <h6 class="fw-bold text-dark border-bottom pb-2"><i class="bi bi-cash-stack me-1 text-success"></i>Multi-Tier Pricing Configuration (Rs.)</h6>
                </div>

                <div class="col-6 col-md-4">
                    <label class="form-label small fw-semibold">Purchase Cost Rate (WAC)</label>
                    <input type="number" class="form-control" name="purchase_price" value="0.00" step="0.01">
                </div>

                <div class="col-6 col-md-4">
                    <label class="form-label small fw-semibold text-primary">Trade Price (Standard Order Rate)</label>
                    <input type="number" class="form-control border-primary" name="trade_price" value="0.00" step="0.01" required>
                </div>

                <div class="col-6 col-md-4">
                    <label class="form-label small fw-semibold text-success">Wholesale Price (Bulk Rate)</label>
                    <input type="number" class="form-control" name="wholesale_price" value="0.00" step="0.01">
                </div>

                <div class="col-6 col-md-4">
                    <label class="form-label small fw-semibold">Retail MRP Price</label>
                    <input type="number" class="form-control" name="retail_price" value="0.00" step="0.01">
                </div>

                <div class="col-6 col-md-4">
                    <label class="form-label small fw-semibold text-danger">Minimum Allowed Sale Price</label>
                    <input type="number" class="form-control" name="min_sale_price" value="0.00" step="0.01">
                </div>

                <div class="col-6 col-md-4">
                    <label class="form-label small fw-semibold">Reorder Alert Stock Level</label>
                    <input type="number" class="form-control" name="reorder_level" value="20" step="1">
                </div>

                <div class="col-12 mt-3">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="batch_tracking" name="is_batch_managed" value="1">
                        <label class="form-check-label fw-semibold small" for="batch_tracking">
                            Enable Batch / Lot & Expiry Date Tracking for this product
                        </label>
                    </div>
                </div>

                <div class="col-12 mt-4">
                    <button type="submit" class="btn btn-primary btn-touch shadow-sm px-4">
                        <i class="bi bi-check-lg me-1"></i> Save Product
                    </button>
                    <a href="<?= BASE_URL ?>/modules/products/index.php" class="btn btn-light border ms-2">Cancel</a>
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
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
