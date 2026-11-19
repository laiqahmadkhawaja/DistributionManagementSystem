<?php
/**
 * Product Catalogue & Pricing Lists
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('products', 'view');

$pdo = getDatabaseConnection();
$currentUser = getCurrentUser();

// Handle POST Actions (Soft Delete & Restore)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $action = sanitize($_POST['action'] ?? '');
    $prodId = (int)($_POST['product_id'] ?? 0);

    if ($action === 'soft_delete') {
        requirePermission('products', 'delete');
        $stmt = $pdo->prepare("SELECT name, code FROM `products` WHERE id = ? LIMIT 1");
        $stmt->execute([$prodId]);
        $p = $stmt->fetch();

        if ($p) {
            $pdo->prepare("UPDATE `products` SET `status` = 'inactive', `updated_at` = NOW() WHERE `id` = ?")->execute([$prodId]);
            logAudit($pdo, 'products', $prodId, 'soft_delete', null, ['name' => $p['name'], 'code' => $p['code']]);
            setFlashMessage('success', "Product '{$p['name']}' ({$p['code']}) has been soft-deleted (deactivated). Historical sales and ledger records remain fully intact.");
        } else {
            setFlashMessage('error', 'Product not found.');
        }

        $redirectQuery = $_GET;
        unset($redirectQuery['action'], $redirectQuery['product_id']);
        redirect(BASE_URL . '/modules/products/index.php' . (!empty($redirectQuery) ? '?' . http_build_query($redirectQuery) : ''));
    }

    if ($action === 'restore') {
        requirePermission('products', 'edit');
        $stmt = $pdo->prepare("SELECT name, code FROM `products` WHERE id = ? LIMIT 1");
        $stmt->execute([$prodId]);
        $p = $stmt->fetch();

        if ($p) {
            $pdo->prepare("UPDATE `products` SET `status` = 'active', `updated_at` = NOW() WHERE `id` = ?")->execute([$prodId]);
            logAudit($pdo, 'products', $prodId, 'restore', null, ['name' => $p['name'], 'code' => $p['code']]);
            setFlashMessage('success', "Product '{$p['name']}' ({$p['code']}) has been restored to active status.");
        } else {
            setFlashMessage('error', 'Product not found.');
        }

        $redirectQuery = $_GET;
        unset($redirectQuery['action'], $redirectQuery['product_id']);
        redirect(BASE_URL . '/modules/products/index.php' . (!empty($redirectQuery) ? '?' . http_build_query($redirectQuery) : ''));
    }
}

// Search and Filter Parameters
$search = sanitize($_GET['q'] ?? '');
$filterCategory = (int)($_GET['cat_id'] ?? 0);
$filterStatus = sanitize($_GET['status'] ?? 'active');

$where = [];
$params = [];

if ($filterStatus === 'active') {
    $where[] = "p.status = 'active'";
} elseif ($filterStatus === 'inactive') {
    $where[] = "p.status = 'inactive'";
}
// If $filterStatus === 'all', no status restriction is applied

if ($filterCategory > 0) {
    $where[] = "p.category_id = ?";
    $params[] = $filterCategory;
}

if (!empty($search)) {
    $where[] = "(p.name LIKE ? OR p.code LIKE ? OR p.barcode LIKE ? OR p.sku LIKE ?)";
    $like = "%{$search}%";
    $params = array_merge($params, [$like, $like, $like, $like]);
}

if (empty($where)) {
    $where[] = "1=1";
}

$whereSql = implode(' AND ', $where);

$query = "
    SELECT p.*, c.name as category_name, b.name as brand_name, u.name as unit_name,
           COALESCE(SUM(sl.quantity_in) - SUM(sl.quantity_out), 0) as current_stock
    FROM `products` p
    JOIN `categories` c ON p.category_id = c.id
    LEFT JOIN `brands` b ON p.brand_id = b.id
    JOIN `units` u ON p.base_unit_id = u.id
    LEFT JOIN `stock_ledger` sl ON p.id = sl.product_id
    WHERE {$whereSql}
    GROUP BY p.id, c.name, b.name, u.name
    ORDER BY p.name ASC
";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$products = $stmt->fetchAll();

// Categories for filter dropdown
$categories = $pdo->query("SELECT id, name FROM `categories` ORDER BY name ASC")->fetchAll();

// Metrics for tabs / quick badges
$totalActiveCount = (int)$pdo->query("SELECT COUNT(*) FROM `products` WHERE status = 'active'")->fetchColumn();
$totalInactiveCount = (int)$pdo->query("SELECT COUNT(*) FROM `products` WHERE status = 'inactive'")->fetchColumn();
$totalCategoriesCount = (int)$pdo->query("SELECT COUNT(*) FROM `categories` WHERE status = 'active'")->fetchColumn();

$pageTitle = 'Products & Multi-tier Pricing';
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-3 gap-2">
    <div>
        <h4 class="fw-bold mb-0 text-dark"><i class="bi bi-tags text-primary me-2"></i>Product Catalogue & Pricing Lists</h4>
        <small class="text-muted">Multi-Tier Pricing: Retail, Wholesale, Trade & Minimum Safe Price</small>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <?php if (hasPermission('products', 'create')): ?>
            <a href="<?= BASE_URL ?>/modules/products/import.php" class="btn btn-outline-success btn-touch shadow-sm">
                <i class="bi bi-file-earmark-excel me-1"></i> Import Excel / CSV
            </a>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/modules/products/categories.php" class="btn btn-outline-primary btn-touch">
            <i class="bi bi-folder2-open me-1"></i> Manage Categories (<?= $totalCategoriesCount ?>)
        </a>
        <a href="<?= BASE_URL ?>/modules/units/index.php" class="btn btn-outline-secondary btn-touch">
            <i class="bi bi-rulers me-1"></i> Units & Conversions
        </a>
        <?php if (hasPermission('products', 'create')): ?>
            <a href="<?= BASE_URL ?>/modules/products/create.php" class="btn btn-primary btn-touch shadow-sm">
                <i class="bi bi-plus-circle me-1"></i> Add New Product
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- Search & Filter Card -->
<div class="card border-0 shadow-sm rounded-3 mb-3">
    <div class="card-body p-3">
        <form method="GET" action="<?= BASE_URL ?>/modules/products/index.php" class="row g-2 align-items-center">
            <div class="col-12 col-md-5">
                <input type="text" class="form-control form-control-sm" name="q" placeholder="Search by Product Name, Code, Barcode, SKU..." value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="col-6 col-md-3">
                <select class="form-select form-select-sm" name="cat_id">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>" <?= $filterCategory == $cat['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cat['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <select class="form-select form-select-sm" name="status">
                    <option value="active" <?= $filterStatus === 'active' ? 'selected' : '' ?>>Active Only (<?= $totalActiveCount ?>)</option>
                    <option value="inactive" <?= $filterStatus === 'inactive' ? 'selected' : '' ?>>Inactive / Soft-Deleted (<?= $totalInactiveCount ?>)</option>
                    <option value="all" <?= $filterStatus === 'all' ? 'selected' : '' ?>>All Products (<?= $totalActiveCount + $totalInactiveCount ?>)</option>
                </select>
            </div>
            <div class="col-12 col-md-2">
                <button type="submit" class="btn btn-sm btn-secondary w-100">
                    <i class="bi bi-search me-1"></i> Filter
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Products Table -->
<div class="card border-0 shadow-sm rounded-3">
    <div class="card-body p-0">
        <?php if (empty($products)): ?>
            <div class="p-4 text-center text-muted">
                <i class="bi bi-box-seam fs-1 text-secondary opacity-50 d-block mb-2"></i>
                No products found matching your search.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover table-responsive-card align-middle mb-0">
                    <thead class="table-light small">
                        <tr>
                            <th>Product Name / Code</th>
                            <th>Category & Brand</th>
                            <th class="text-center">Current Stock</th>
                            <th class="text-end">Cost Rate</th>
                            <th class="text-end text-primary">Trade Price</th>
                            <th class="text-end text-success">Wholesale</th>
                            <th class="text-end">Retail MRP</th>
                            <th class="text-center">Min Price</th>
                            <th class="text-end" style="min-width: 130px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products as $p): ?>
                            <tr class="<?= $p['status'] === 'inactive' ? 'table-light opacity-75' : '' ?>">
                                <td data-label="Product">
                                    <div class="d-flex align-items-center gap-1 flex-wrap">
                                        <span class="fw-bold text-dark"><?= htmlspecialchars($p['name']) ?></span>
                                        <?php if ($p['status'] === 'inactive'): ?>
                                            <span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary border-opacity-25" style="font-size: 0.68rem;">Inactive (Soft Deleted)</span>
                                        <?php endif; ?>
                                    </div>
                                    <small class="text-muted d-block">
                                        <code><?= htmlspecialchars($p['code']) ?></code>
                                        <?= !empty($p['barcode']) ? '· Barcode: ' . htmlspecialchars($p['barcode']) : '' ?>
                                        <?= !empty($p['sku']) ? '· SKU: ' . htmlspecialchars($p['sku']) : '' ?>
                                    </small>
                                </td>
                                <td data-label="Category">
                                    <span class="badge bg-light text-dark border"><?= htmlspecialchars($p['category_name']) ?></span>
                                    <small class="text-muted d-block"><?= htmlspecialchars($p['brand_name'] ?? '-') ?></small>
                                </td>
                                <td data-label="Stock" class="text-center">
                                    <?php if ((float)$p['current_stock'] <= (float)$p['reorder_level']): ?>
                                        <span class="badge bg-danger rounded-pill"><?= (float)$p['current_stock'] ?> <?= htmlspecialchars($p['unit_name']) ?></span>
                                    <?php else: ?>
                                        <span class="badge bg-success bg-opacity-10 text-success fw-bold rounded-pill border"><?= (float)$p['current_stock'] ?> <?= htmlspecialchars($p['unit_name']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Cost" class="text-end"><?= formatMoney($p['purchase_price']) ?></td>
                                <td data-label="Trade" class="text-end fw-bold text-primary"><?= formatMoney($p['trade_price']) ?></td>
                                <td data-label="Wholesale" class="text-end fw-semibold text-success"><?= formatMoney($p['wholesale_price']) ?></td>
                                <td data-label="Retail" class="text-end text-dark"><?= formatMoney($p['retail_price']) ?></td>
                                <td data-label="Min Safe" class="text-center text-muted small"><?= formatMoney($p['min_sale_price']) ?></td>
                                <td data-label="Actions" class="text-end">
                                    <div class="btn-group btn-group-sm">
                                        <?php if (hasPermission('products', 'edit')): ?>
                                            <a href="<?= BASE_URL ?>/modules/products/edit.php?id=<?= $p['id'] ?>" class="btn btn-outline-primary" title="Edit Product">
                                                <i class="bi bi-pencil-square"></i> Edit
                                            </a>
                                        <?php endif; ?>

                                        <?php if ($p['status'] === 'active'): ?>
                                            <?php if (hasPermission('products', 'delete')): ?>
                                                <button type="button" class="btn btn-outline-danger" title="Soft Delete / Deactivate"
                                                        onclick="confirmProductSoftDelete(<?= $p['id'] ?>, '<?= htmlspecialchars(addslashes($p['name'])) ?>')">
                                                    <i class="bi bi-trash3"></i>
                                                </button>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <?php if (hasPermission('products', 'edit')): ?>
                                                <button type="button" class="btn btn-outline-success" title="Reactivate Product"
                                                        onclick="confirmProductRestore(<?= $p['id'] ?>, '<?= htmlspecialchars(addslashes($p['name'])) ?>')">
                                                    <i class="bi bi-arrow-counterclockwise"></i>
                                                </button>
                                            <?php endif; ?>
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

<!-- ==============================================================
     MODAL: Confirm Soft Delete / Reactivate Product
     ============================================================== -->
<div class="modal fade" id="productStatusModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST" action="<?= BASE_URL ?>/modules/products/index.php<?= !empty($_SERVER['QUERY_STRING']) ? '?' . htmlspecialchars($_SERVER['QUERY_STRING']) : '' ?>">
                <?= getCsrfField() ?>
                <input type="hidden" name="action" id="productModalAction" value="soft_delete">
                <input type="hidden" name="product_id" id="productModalId" value="0">

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
function confirmProductSoftDelete(id, name) {
    document.getElementById('productModalId').value = id;
    document.getElementById('productModalAction').value = 'soft_delete';
    document.getElementById('prodModalHeader').className = 'modal-header bg-danger text-white';
    document.getElementById('prodModalTitle').innerText = 'Soft Delete Product';
    document.getElementById('prodModalIcon').className = 'bi bi-trash3 text-danger fs-1';
    document.getElementById('prodModalPrompt').innerText = 'Deactivate "' + name + '"?';
    document.getElementById('prodModalNote').innerText = 'This product will be marked inactive and hidden from active sales orders and the booking app. All historical invoices, deliveries, and stock ledger entries remain permanently safe.';
    document.getElementById('prodModalBtn').className = 'btn btn-danger px-4 fw-semibold';
    document.getElementById('prodModalBtn').innerHTML = '<i class="bi bi-trash3 me-1"></i> Deactivate (Soft Delete)';

    var modal = new bootstrap.Modal(document.getElementById('productStatusModal'));
    modal.show();
}

function confirmProductRestore(id, name) {
    document.getElementById('productModalId').value = id;
    document.getElementById('productModalAction').value = 'restore';
    document.getElementById('prodModalHeader').className = 'modal-header bg-success text-white';
    document.getElementById('prodModalTitle').innerText = 'Reactivate Product';
    document.getElementById('prodModalIcon').className = 'bi bi-arrow-counterclockwise text-success fs-1';
    document.getElementById('prodModalPrompt').innerText = 'Reactivate "' + name + '"?';
    document.getElementById('prodModalNote').innerText = 'This product will be returned to active status and will appear again in the Order Booker app and sales catalogue.';
    document.getElementById('prodModalBtn').className = 'btn btn-success px-4 fw-semibold text-white';
    document.getElementById('prodModalBtn').innerHTML = '<i class="bi bi-check-circle me-1"></i> Reactivate Product';

    var modal = new bootstrap.Modal(document.getElementById('productStatusModal'));
    modal.show();
}
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
