<?php
/**
 * Product Catalogue & Pricing Lists
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('products', 'view');

$pdo = getDatabaseConnection();

$search = sanitize($_GET['q'] ?? '');
$filterCategory = (int)($_GET['cat_id'] ?? 0);

$where = ["p.status = 'active'"];
$params = [];

if ($filterCategory > 0) {
    $where[] = "p.category_id = ?";
    $params[] = $filterCategory;
}

if (!empty($search)) {
    $where[] = "(p.name LIKE ? OR p.code LIKE ? OR p.barcode LIKE ? OR p.sku LIKE ?)";
    $like = "%{$search}%";
    $params = array_merge($params, [$like, $like, $like, $like]);
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

$categories = $pdo->query("SELECT id, name FROM `categories` WHERE status = 'active' ORDER BY name ASC")->fetchAll();

$pageTitle = 'Products & Multi-tier Pricing';
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-3 gap-2">
    <div>
        <h4 class="fw-bold mb-0 text-dark"><i class="bi bi-tags text-primary me-2"></i>Product Catalogue & Pricing Lists</h4>
        <small class="text-muted">Multi-Tier Pricing: Retail, Wholesale, Trade & Minimum Safe Price</small>
    </div>
    <div class="d-flex gap-2">
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
        <form method="GET" action="<?= BASE_URL ?>/modules/products/index.php" class="row g-2">
            <div class="col-12 col-md-7">
                <input type="text" class="form-control form-control-sm" name="q" placeholder="Search by Product Name, Code, Barcode, SKU..." value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="col-8 col-md-3">
                <select class="form-select form-select-sm" name="cat_id">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>" <?= $filterCategory == $cat['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cat['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-4 col-md-2">
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
            <div class="p-4 text-center text-muted">No products found matching your search.</div>
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
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products as $p): ?>
                            <tr>
                                <td data-label="Product">
                                    <div class="fw-bold text-dark"><?= htmlspecialchars($p['name']) ?></div>
                                    <small class="text-muted"><?= htmlspecialchars($p['code']) ?> <?= $p['barcode'] ? '· Barcode: ' . htmlspecialchars($p['barcode']) : '' ?></small>
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
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
