<?php
/**
 * Product Categories Management
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('products', 'view');

$pdo = getDatabaseConnection();
$currentUser = getCurrentUser();

// Handle AJAX Quick-Add (e.g. from create.php / edit.php)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['ajax_quick_add']) || (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest' && ($_POST['action'] ?? '') === 'save'))) {
    header('Content-Type: application/json');
    if (!validateCsrfToken()) {
        echo json_encode(['success' => false, 'error' => 'Invalid security token. Please refresh and try again.']);
        exit;
    }
    if (!hasPermission('products', 'create')) {
        echo json_encode(['success' => false, 'error' => 'Permission denied: Cannot create categories.']);
        exit;
    }

    $name = trim(sanitize($_POST['name'] ?? ''));
    $code = strtoupper(trim(sanitize($_POST['code'] ?? '')));
    $description = trim(sanitize($_POST['description'] ?? ''));

    if (empty($name)) {
        echo json_encode(['success' => false, 'error' => 'Category name is required.']);
        exit;
    }

    // Auto-generate code if empty
    if (empty($code)) {
        $clean = preg_replace('/[^A-Za-z0-9]/', '', strtoupper($name));
        $candidate = substr($clean, 0, 4);
        if (strlen($candidate) < 3) {
            $candidate = 'CAT' . rand(100, 999);
        }
        $chk = $pdo->prepare("SELECT id FROM `categories` WHERE `code` = ? LIMIT 1");
        $chk->execute([$candidate]);
        if ($chk->fetch()) {
            $candidate = substr($candidate, 0, 3) . rand(10, 99);
        }
        $code = $candidate;
    }

    // Check duplicate code
    $chk = $pdo->prepare("SELECT id FROM `categories` WHERE `code` = ? LIMIT 1");
    $chk->execute([$code]);
    if ($chk->fetch()) {
        echo json_encode(['success' => false, 'error' => "A category with code '{$code}' already exists."]);
        exit;
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO `categories` (`name`, `code`, `description`, `status`, `created_at`) VALUES (?, ?, ?, 'active', NOW())");
        $stmt->execute([$name, $code, $description]);
        $newId = (int)$pdo->lastInsertId();

        logAudit($pdo, 'categories', $newId, 'create', null, ['name' => $name, 'code' => $code]);
        echo json_encode([
            'success' => true,
            'category' => [
                'id' => $newId,
                'name' => $name,
                'code' => $code
            ]
        ]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
        exit;
    }
}

// Handle Standard POST Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $action = sanitize($_POST['action'] ?? '');

    // 1. SAVE CATEGORY (Add / Edit)
    if ($action === 'save') {
        $catId = (int)($_POST['category_id'] ?? 0);
        $name = trim(sanitize($_POST['name'] ?? ''));
        $code = strtoupper(trim(sanitize($_POST['code'] ?? '')));
        $description = trim(sanitize($_POST['description'] ?? ''));
        $parentId = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
        $status = in_array($_POST['status'] ?? '', ['active', 'inactive']) ? $_POST['status'] : 'active';

        if ($catId > 0) {
            requirePermission('products', 'edit');
        } else {
            requirePermission('products', 'create');
        }

        if (empty($name)) {
            setFlashMessage('error', 'Category name is required.');
            redirect(BASE_URL . '/modules/products/categories.php');
        }

        // Prevent setting self as parent
        if ($catId > 0 && $parentId === $catId) {
            $parentId = null;
        }

        // Auto-generate code if empty
        if (empty($code)) {
            $clean = preg_replace('/[^A-Za-z0-9]/', '', strtoupper($name));
            $candidate = substr($clean, 0, 4);
            if (strlen($candidate) < 3) {
                $candidate = 'CAT' . rand(100, 999);
            }
            $chk = $pdo->prepare("SELECT id FROM `categories` WHERE `code` = ? AND id != ? LIMIT 1");
            $chk->execute([$candidate, $catId]);
            if ($chk->fetch()) {
                $candidate = substr($candidate, 0, 3) . rand(10, 99);
            }
            $code = $candidate;
        }

        // Check duplicate code
        $dupStmt = $pdo->prepare("SELECT id FROM `categories` WHERE `code` = ? AND id != ? LIMIT 1");
        $dupStmt->execute([$code, $catId]);
        if ($dupStmt->fetch()) {
            setFlashMessage('error', "Category code '{$code}' is already in use by another category.");
            redirect(BASE_URL . '/modules/products/categories.php');
        }

        try {
            if ($catId > 0) {
                $stmt = $pdo->prepare("
                    UPDATE `categories` 
                    SET `name` = ?, `code` = ?, `description` = ?, `parent_id` = ?, `status` = ?
                    WHERE `id` = ?
                ");
                $stmt->execute([$name, $code, $description, $parentId, $status, $catId]);
                logAudit($pdo, 'categories', $catId, 'update', null, ['name' => $name, 'code' => $code, 'status' => $status]);
                setFlashMessage('success', "Category '{$name}' updated successfully.");
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO `categories` (`name`, `code`, `description`, `parent_id`, `status`, `created_at`) 
                    VALUES (?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([$name, $code, $description, $parentId, $status]);
                $newId = (int)$pdo->lastInsertId();
                logAudit($pdo, 'categories', $newId, 'create', null, ['name' => $name, 'code' => $code, 'status' => $status]);
                setFlashMessage('success', "Category '{$name}' ({$code}) created successfully.");
            }
        } catch (Exception $e) {
            setFlashMessage('error', 'Database error: ' . $e->getMessage());
        }

        redirect(BASE_URL . '/modules/products/categories.php');
    }

    // 2. TOGGLE STATUS / SOFT DELETE
    if ($action === 'toggle_status') {
        requirePermission('products', 'edit');
        $catId = (int)($_POST['category_id'] ?? 0);

        $stmt = $pdo->prepare("SELECT * FROM `categories` WHERE `id` = ? LIMIT 1");
        $stmt->execute([$catId]);
        $cat = $stmt->fetch();

        if ($cat) {
            $newStatus = ($cat['status'] === 'active') ? 'inactive' : 'active';
            $upd = $pdo->prepare("UPDATE `categories` SET `status` = ? WHERE `id` = ?");
            $upd->execute([$newStatus, $catId]);

            logAudit($pdo, 'categories', $catId, 'toggle_status', null, ['old_status' => $cat['status'], 'new_status' => $newStatus]);
            
            if ($newStatus === 'inactive') {
                setFlashMessage('success', "Category '{$cat['name']}' deactivated (soft deleted). Associated products remain safely preserved.");
            } else {
                setFlashMessage('success', "Category '{$cat['name']}' reactivated successfully.");
            }
        } else {
            setFlashMessage('error', 'Category not found.');
        }

        redirect(BASE_URL . '/modules/products/categories.php');
    }

    // 3. SAFE PERMANENT DELETE (Only if 0 products attached)
    if ($action === 'delete') {
        requirePermission('products', 'delete');
        $catId = (int)($_POST['category_id'] ?? 0);

        $stmt = $pdo->prepare("SELECT * FROM `categories` WHERE `id` = ? LIMIT 1");
        $stmt->execute([$catId]);
        $cat = $stmt->fetch();

        if ($cat) {
            // Count products attached
            $prodCount = (int)$pdo->query("SELECT COUNT(*) FROM `products` WHERE `category_id` = {$catId}")->fetchColumn();
            $subCatCount = (int)$pdo->query("SELECT COUNT(*) FROM `categories` WHERE `parent_id` = {$catId}")->fetchColumn();

            if ($prodCount > 0 || $subCatCount > 0) {
                setFlashMessage('error', "Cannot permanently delete category: {$prodCount} product(s) and {$subCatCount} sub-category(ies) are linked to it. Please deactivate (soft-delete) it instead.");
            } else {
                try {
                    $pdo->prepare("DELETE FROM `categories` WHERE `id` = ?")->execute([$catId]);
                    logAudit($pdo, 'categories', $catId, 'delete', null, ['name' => $cat['name'], 'code' => $cat['code']]);
                    setFlashMessage('success', "Category '{$cat['name']}' permanently deleted.");
                } catch (Exception $e) {
                    setFlashMessage('error', 'Delete error: ' . $e->getMessage());
                }
            }
        } else {
            setFlashMessage('error', 'Category not found.');
        }

        redirect(BASE_URL . '/modules/products/categories.php');
    }
}

// Search and Filter Params
$search = sanitize($_GET['q'] ?? '');
$filterStatus = sanitize($_GET['status'] ?? 'all');

$where = ["1=1"];
$params = [];

if ($filterStatus === 'active') {
    $where[] = "c.status = 'active'";
} elseif ($filterStatus === 'inactive') {
    $where[] = "c.status = 'inactive'";
}

if (!empty($search)) {
    $where[] = "(c.name LIKE ? OR c.code LIKE ? OR c.description LIKE ?)";
    $like = "%{$search}%";
    $params = array_merge($params, [$like, $like, $like]);
}

$whereSql = implode(' AND ', $where);

// Fetch categories with product counts and parent names
$query = "
    SELECT c.*, 
           p.name AS parent_name,
           (SELECT COUNT(*) FROM `products` pr WHERE pr.category_id = c.id) AS product_count,
           (SELECT COUNT(*) FROM `products` pr WHERE pr.category_id = c.id AND pr.status = 'active') AS active_product_count
    FROM `categories` c
    LEFT JOIN `categories` p ON c.parent_id = p.id
    WHERE {$whereSql}
    ORDER BY c.name ASC
";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$categories = $stmt->fetchAll();

// Fetch summary metrics
$totalCategories = (int)$pdo->query("SELECT COUNT(*) FROM `categories`")->fetchColumn();
$activeCategories = (int)$pdo->query("SELECT COUNT(*) FROM `categories` WHERE status = 'active'")->fetchColumn();
$inactiveCategories = (int)$pdo->query("SELECT COUNT(*) FROM `categories` WHERE status = 'inactive'")->fetchColumn();
$totalProducts = (int)$pdo->query("SELECT COUNT(*) FROM `products`")->fetchColumn();

// Fetch all categories for parent dropdown
$parentCategories = $pdo->query("SELECT id, name, code FROM `categories` WHERE status = 'active' ORDER BY name ASC")->fetchAll();

$pageTitle = 'Product Categories Management';
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<!-- Header Section -->
<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-3 gap-2">
    <div>
        <h4 class="fw-bold mb-0 text-dark">
            <i class="bi bi-folder2-open text-primary me-2"></i>Product Categories
        </h4>
        <small class="text-muted">Manage product taxonomy, classification codes & active status</small>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/modules/products/index.php" class="btn btn-outline-secondary btn-touch">
            <i class="bi bi-tags me-1"></i> Products List
        </a>
        <?php if (hasPermission('products', 'create')): ?>
            <button type="button" class="btn btn-primary btn-touch shadow-sm" onclick="openAddModal()">
                <i class="bi bi-plus-circle me-1"></i> Add New Category
            </button>
        <?php endif; ?>
    </div>
</div>

<!-- Quick Statistics KPI Cards -->
<div class="row g-2 mb-3">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm rounded-3 p-3">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <span class="text-muted small text-uppercase fw-semibold">Total Categories</span>
                    <h4 class="fw-bold mb-0 mt-1 text-dark"><?= $totalCategories ?></h4>
                </div>
                <div class="badge bg-primary bg-opacity-10 text-primary p-2 fs-5 rounded-3">
                    <i class="bi bi-folder-fill"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm rounded-3 p-3">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <span class="text-muted small text-uppercase fw-semibold">Active Categories</span>
                    <h4 class="fw-bold mb-0 mt-1 text-success"><?= $activeCategories ?></h4>
                </div>
                <div class="badge bg-success bg-opacity-10 text-success p-2 fs-5 rounded-3">
                    <i class="bi bi-check-circle-fill"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm rounded-3 p-3">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <span class="text-muted small text-uppercase fw-semibold">Inactive (Soft Deleted)</span>
                    <h4 class="fw-bold mb-0 mt-1 text-secondary"><?= $inactiveCategories ?></h4>
                </div>
                <div class="badge bg-secondary bg-opacity-10 text-secondary p-2 fs-5 rounded-3">
                    <i class="bi bi-archive-fill"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm rounded-3 p-3">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <span class="text-muted small text-uppercase fw-semibold">Classified Products</span>
                    <h4 class="fw-bold mb-0 mt-1 text-info"><?= $totalProducts ?></h4>
                </div>
                <div class="badge bg-info bg-opacity-10 text-info p-2 fs-5 rounded-3">
                    <i class="bi bi-box-seam"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Search & Filter Card -->
<div class="card border-0 shadow-sm rounded-3 mb-3">
    <div class="card-body p-3">
        <form method="GET" action="<?= BASE_URL ?>/modules/products/categories.php" class="row g-2">
            <div class="col-12 col-md-7">
                <input type="text" class="form-control form-control-sm" name="q" placeholder="Search by Category Name, Code, or Description..." value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="col-8 col-md-3">
                <select class="form-select form-select-sm" name="status">
                    <option value="all" <?= $filterStatus === 'all' ? 'selected' : '' ?>>All Statuses (Active & Inactive)</option>
                    <option value="active" <?= $filterStatus === 'active' ? 'selected' : '' ?>>Active Categories Only</option>
                    <option value="inactive" <?= $filterStatus === 'inactive' ? 'selected' : '' ?>>Inactive (Soft-Deleted) Only</option>
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

<!-- Categories Table -->
<div class="card border-0 shadow-sm rounded-3">
    <div class="card-body p-0">
        <?php if (empty($categories)): ?>
            <div class="p-4 text-center text-muted">
                <i class="bi bi-folder-x fs-1 d-block text-secondary opacity-50 mb-2"></i>
                No categories found matching your query.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light small">
                        <tr>
                            <th style="width: 15%;">Code</th>
                            <th style="width: 30%;">Category Name</th>
                            <th style="width: 20%;">Parent Category</th>
                            <th style="width: 15%;" class="text-center">Products Linked</th>
                            <th style="width: 10%;" class="text-center">Status</th>
                            <th style="width: 10%;" class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($categories as $cat): ?>
                            <tr class="<?= $cat['status'] === 'inactive' ? 'table-light opacity-75' : '' ?>">
                                <td>
                                    <span class="badge bg-light text-primary border font-monospace px-2 py-1 fs-6">
                                        <?= htmlspecialchars($cat['code']) ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark fs-6"><?= htmlspecialchars($cat['name']) ?></div>
                                    <?php if (!empty($cat['description'])): ?>
                                        <small class="text-muted d-block"><?= htmlspecialchars($cat['description']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($cat['parent_name'])): ?>
                                        <span class="badge bg-light text-dark border">
                                            <i class="bi bi-arrow-return-right me-1 text-muted"></i><?= htmlspecialchars($cat['parent_name']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted small">None (Top Level)</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ((int)$cat['product_count'] > 0): ?>
                                        <a href="<?= BASE_URL ?>/modules/products/index.php?cat_id=<?= $cat['id'] ?>" class="btn btn-sm btn-outline-info py-0 px-2 rounded-pill fw-semibold" title="View products in this category">
                                            <i class="bi bi-boxes me-1"></i><?= $cat['product_count'] ?> Products
                                        </a>
                                    <?php else: ?>
                                        <span class="badge bg-light text-muted border rounded-pill">0 Products</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($cat['status'] === 'active'): ?>
                                        <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-2 py-1">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary border-opacity-25 px-2 py-1">Inactive (Soft-Deleted)</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <div class="btn-group btn-group-sm">
                                        <?php if (hasPermission('products', 'edit')): ?>
                                            <button type="button" class="btn btn-outline-primary" 
                                                    title="Edit Category"
                                                    onclick='openEditModal(<?= json_encode($cat, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                                <i class="bi bi-pencil-square"></i>
                                            </button>
                                            
                                            <!-- Toggle Status / Soft Delete Button -->
                                            <?php if ($cat['status'] === 'active'): ?>
                                                <button type="button" class="btn btn-outline-warning" 
                                                        title="Deactivate / Soft Delete Category"
                                                        onclick="confirmToggleStatus(<?= $cat['id'] ?>, '<?= htmlspecialchars(addslashes($cat['name'])) ?>', 'deactivate')">
                                                    <i class="bi bi-slash-circle"></i>
                                                </button>
                                            <?php else: ?>
                                                <button type="button" class="btn btn-outline-success" 
                                                        title="Reactivate Category"
                                                        onclick="confirmToggleStatus(<?= $cat['id'] ?>, '<?= htmlspecialchars(addslashes($cat['name'])) ?>', 'activate')">
                                                    <i class="bi bi-arrow-counterclockwise"></i>
                                                </button>
                                            <?php endif; ?>
                                        <?php endif; ?>

                                        <?php if (hasPermission('products', 'delete')): ?>
                                            <?php if ((int)$cat['product_count'] === 0): ?>
                                                <button type="button" class="btn btn-outline-danger" 
                                                        title="Delete Category"
                                                        onclick="confirmPermanentDelete(<?= $cat['id'] ?>, '<?= htmlspecialchars(addslashes($cat['name'])) ?>')">
                                                    <i class="bi bi-trash3"></i>
                                                </button>
                                            <?php else: ?>
                                                <button type="button" class="btn btn-outline-secondary opacity-50" 
                                                        disabled
                                                        title="Cannot delete: <?= $cat['product_count'] ?> products are linked. Use Deactivate (Soft-Delete) instead.">
                                                    <i class="bi bi-trash3"></i>
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
     MODAL: Add / Edit Category
     ============================================================== -->
<div class="modal fade" id="categoryModal" tabindex="-1" aria-labelledby="categoryModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow">
            <form method="POST" action="<?= BASE_URL ?>/modules/products/categories.php" id="categoryForm">
                <?= getCsrfField() ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="category_id" id="modalCatId" value="0">

                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fw-bold" id="categoryModalLabel">
                        <i class="bi bi-folder-plus me-1"></i> Add New Category
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Category Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="name" id="modalCatName" placeholder="e.g. Dairy & Frozen Products" required>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Category Code</label>
                            <input type="text" class="form-control font-monospace text-uppercase" name="code" id="modalCatCode" placeholder="e.g. DAIR (auto if blank)">
                            <small class="text-muted" style="font-size: 0.72rem;">Unique identifier code</small>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Status</label>
                            <select class="form-select" name="status" id="modalCatStatus">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Parent Category (Optional)</label>
                        <select class="form-select" name="parent_id" id="modalCatParent">
                            <option value="">-- None (Top Level Category) --</option>
                            <?php foreach ($parentCategories as $pCat): ?>
                                <option value="<?= $pCat['id'] ?>"><?= htmlspecialchars($pCat['name']) ?> (<?= htmlspecialchars($pCat['code']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Description / Notes</label>
                        <textarea class="form-control" name="description" id="modalCatDesc" rows="2" placeholder="Optional category scope or notes..."></textarea>
                    </div>
                </div>

                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4 fw-semibold" id="modalCatSubmitBtn">
                        <i class="bi bi-check-lg me-1"></i> Save Category
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ==============================================================
     MODAL: Confirm Status Toggle (Soft Delete / Reactivate)
     ============================================================== -->
<div class="modal fade" id="toggleStatusModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST" action="<?= BASE_URL ?>/modules/products/categories.php">
                <?= getCsrfField() ?>
                <input type="hidden" name="action" value="toggle_status">
                <input type="hidden" name="category_id" id="toggleCatId" value="0">

                <div class="modal-header" id="toggleModalHeader">
                    <h5 class="modal-title fw-bold" id="toggleModalTitle">Confirm Action</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4 text-center">
                    <div id="toggleModalIcon" class="mb-3 fs-1"></div>
                    <h5 class="fw-bold mb-2" id="toggleModalPrompt"></h5>
                    <p class="text-muted small mb-0" id="toggleModalNote"></p>
                </div>
                <div class="modal-footer bg-light justify-content-center">
                    <button type="button" class="btn btn-secondary px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn px-4 fw-semibold" id="toggleModalBtn"></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ==============================================================
     MODAL: Confirm Safe Permanent Delete (0 Products Linked)
     ============================================================== -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST" action="<?= BASE_URL ?>/modules/products/categories.php">
                <?= getCsrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="category_id" id="deleteCatId" value="0">

                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title fw-bold"><i class="bi bi-trash3 me-1"></i> Permanent Delete Category</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4 text-center">
                    <i class="bi bi-exclamation-triangle text-danger fs-1 mb-2 d-block"></i>
                    <h5 class="fw-bold mb-2">Delete <span id="deleteCatName" class="text-danger"></span>?</h5>
                    <p class="text-muted small mb-0">
                        This category has 0 associated products. Are you sure you want to permanently remove it?
                    </p>
                </div>
                <div class="modal-footer bg-light justify-content-center">
                    <button type="button" class="btn btn-secondary px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger px-4 fw-semibold">
                        <i class="bi bi-trash3 me-1"></i> Yes, Delete Permanently
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openAddModal() {
    document.getElementById('categoryModalLabel').innerHTML = '<i class="bi bi-folder-plus me-1"></i> Add New Category';
    document.getElementById('modalCatId').value = '0';
    document.getElementById('modalCatName').value = '';
    document.getElementById('modalCatCode').value = '';
    document.getElementById('modalCatStatus').value = 'active';
    document.getElementById('modalCatParent').value = '';
    document.getElementById('modalCatDesc').value = '';
    document.getElementById('modalCatSubmitBtn').innerHTML = '<i class="bi bi-check-lg me-1"></i> Save Category';
    
    var modal = new bootstrap.Modal(document.getElementById('categoryModal'));
    modal.show();
}

function openEditModal(cat) {
    document.getElementById('categoryModalLabel').innerHTML = '<i class="bi bi-pencil-square me-1"></i> Edit Category';
    document.getElementById('modalCatId').value = cat.id;
    document.getElementById('modalCatName').value = cat.name || '';
    document.getElementById('modalCatCode').value = cat.code || '';
    document.getElementById('modalCatStatus').value = cat.status || 'active';
    document.getElementById('modalCatParent').value = cat.parent_id || '';
    document.getElementById('modalCatDesc').value = cat.description || '';
    document.getElementById('modalCatSubmitBtn').innerHTML = '<i class="bi bi-check-lg me-1"></i> Update Category';
    
    var modal = new bootstrap.Modal(document.getElementById('categoryModal'));
    modal.show();
}

function confirmToggleStatus(id, name, action) {
    document.getElementById('toggleCatId').value = id;
    var header = document.getElementById('toggleModalHeader');
    var icon = document.getElementById('toggleModalIcon');
    var prompt = document.getElementById('toggleModalPrompt');
    var note = document.getElementById('toggleModalNote');
    var btn = document.getElementById('toggleModalBtn');

    if (action === 'deactivate') {
        header.className = 'modal-header bg-warning text-dark';
        icon.className = 'bi bi-slash-circle text-warning fs-1';
        prompt.innerHTML = 'Deactivate (Soft Delete) Category "' + name + '"?';
        note.innerHTML = 'This category will be marked inactive and hidden from new product creation. Any existing products linked to this category remain 100% safe and intact.';
        btn.className = 'btn btn-warning px-4 fw-semibold text-dark';
        btn.innerHTML = '<i class="bi bi-slash-circle me-1"></i> Deactivate Category';
    } else {
        header.className = 'modal-header bg-success text-white';
        icon.className = 'bi bi-arrow-counterclockwise text-success fs-1';
        prompt.innerHTML = 'Reactivate Category "' + name + '"?';
        note.innerHTML = 'This category will be restored to active status and will appear in product selection dropdowns.';
        btn.className = 'btn btn-success px-4 fw-semibold text-white';
        btn.innerHTML = '<i class="bi bi-check-circle me-1"></i> Reactivate Category';
    }

    var modal = new bootstrap.Modal(document.getElementById('toggleStatusModal'));
    modal.show();
}

function confirmPermanentDelete(id, name) {
    document.getElementById('deleteCatId').value = id;
    document.getElementById('deleteCatName').innerText = name;
    var modal = new bootstrap.Modal(document.getElementById('deleteModal'));
    modal.show();
}
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
