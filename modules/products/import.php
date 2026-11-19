<?php
/**
 * High-Speed Product Catalog Excel/CSV Bulk Importer
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('products', 'create');

$pdo = getDatabaseConnection();
$company = getCompanyInfo($pdo);
$pageTitle = 'Import Products (Excel/CSV)';

// 1. Download Sample CSV Template
if (isset($_GET['action']) && $_GET['action'] === 'download_template') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="DMS_Products_Import_Template.csv"');
    $output = fopen('php://output', 'w');
    // UTF-8 BOM for Excel
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Header
    fputcsv($output, [
        'Product Name',
        'SKU Code',
        'Category',
        'Brand',
        'Barcode',
        'Pieces per Carton',
        'Purchase Cost',
        'Trade Price (TP)',
        'Retail Price (MRP)',
        'Opening Stock'
    ]);
    
    // Sample Rows
    fputcsv($output, ['Nimko Daal Moth (1*36)', 'NMK-DM-36', 'Snacks', 'Falcon', '89640001', '36', '1250.00', '1512.00', '1800.00', '50']);
    fputcsv($output, ['Nimko Daal Moth (1*24)', 'NMK-DM-24', 'Snacks', 'Falcon', '89640002', '24', '1850.00', '2208.00', '2400.00', '40']);
    fputcsv($output, ['Nimko Mix (1*36)', 'NMK-MX-36', 'Snacks', 'Falcon', '89640003', '36', '1250.00', '1512.00', '1800.00', '35']);
    fputcsv($output, ['Nimko Mix (1*24)', 'NMK-MX-24', 'Snacks', 'Falcon', '89640004', '24', '1850.00', '2208.00', '2400.00', '30']);
    fputcsv($output, ['Zoro Ring Puff BBQ (1*12)', 'ZRO-BBQ-12', 'Puffs', 'Zoro', '89640005', '12', '1800.00', '2160.00', '2400.00', '60']);
    
    fclose($output);
    exit;
}

// 2. Process CSV Upload POST
$importResults = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_products'])) {
    if (empty($_FILES['csv_file']['tmp_name']) || !is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
        setFlashMessage('error', 'Please choose a valid CSV or Excel-compatible file to upload.');
        redirect(BASE_URL . '/modules/products/import.php');
    }

    $filePath = $_FILES['csv_file']['tmp_name'];
    $handle = fopen($filePath, 'r');
    if (!$handle) {
        setFlashMessage('error', 'Unable to read the uploaded file.');
        redirect(BASE_URL . '/modules/products/import.php');
    }

    // Skip BOM if present
    $bom = fread($handle, 3);
    if ($bom !== "\xEF\xBB\xBF") {
        rewind($handle);
    }

    $header = fgetcsv($handle);
    if (!$header || count($header) < 2) {
        fclose($handle);
        setFlashMessage('error', 'Invalid CSV structure. The file header row is empty or corrupted.');
        redirect(BASE_URL . '/modules/products/import.php');
    }

    // Prepare Category, Brand, and Unit Caches
    $catMap = [];
    foreach ($pdo->query("SELECT id, LOWER(name) as name_key FROM categories WHERE status = 'active'")->fetchAll() as $c) {
        $catMap[$c['name_key']] = (int)$c['id'];
    }

    $brandMap = [];
    foreach ($pdo->query("SELECT id, LOWER(name) as name_key FROM brands WHERE status = 'active'")->fetchAll() as $b) {
        $brandMap[$b['name_key']] = (int)$b['id'];
    }

    // Get Base Unit ID (Pieces) & Carton Unit ID
    $pieceUnitId = (int)($pdo->query("SELECT id FROM units WHERE code = 'pc' OR LOWER(name) LIKE '%piece%' LIMIT 1")->fetchColumn() ?: 1);
    $cartonUnitId = (int)($pdo->query("SELECT id FROM units WHERE code = 'ctn' OR LOWER(name) LIKE '%carton%' LIMIT 1")->fetchColumn() ?: 2);
    $mainWarehouseId = (int)($pdo->query("SELECT id FROM warehouses WHERE is_primary = 1 LIMIT 1")->fetchColumn() ?: 1);

    $inserted = 0;
    $updated = 0;
    $errors = [];
    $rowNum = 1;

    $insCatStmt = $pdo->prepare("INSERT INTO categories (`name`, `code`, `status`, `created_at`) VALUES (?, ?, 'active', NOW())");
    $insBrandStmt = $pdo->prepare("INSERT INTO brands (`name`, `code`, `status`, `created_at`) VALUES (?, ?, 'active', NOW())");
    
    $checkProdStmt = $pdo->prepare("SELECT id FROM products WHERE code = ? LIMIT 1");
    
    $insertProdStmt = $pdo->prepare("
        INSERT INTO products (
            `category_id`, `brand_id`, `base_unit_id`, `name`, `code`, `barcode`,
            `cost_price`, `trade_price`, `retail_price`, `status`, `created_at`
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW())
    ");

    $updateProdStmt = $pdo->prepare("
        UPDATE products 
        SET `category_id` = ?, `brand_id` = ?, `name` = ?, `barcode` = ?,
            `cost_price` = ?, `trade_price` = ?, `retail_price` = ?, `status` = 'active'
        WHERE id = ?
    ");

    $upsertConvStmt = $pdo->prepare("
        INSERT INTO unit_conversions (`product_id`, `from_unit_id`, `to_unit_id`, `multiplier`)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE `multiplier` = VALUES(`multiplier`)
    ");

    $stockLedgerStmt = $pdo->prepare("
        INSERT INTO stock_ledger (
            `product_id`, `warehouse_id`, `movement_type`, `reference_type`, `reference_id`,
            `quantity`, `unit_cost`, `running_balance`, `notes`, `created_at`
        ) VALUES (?, ?, 'opening_balance', 'opening', 0, ?, ?, ?, 'Opening Stock via Excel Batch Import', NOW())
    ");

    try {
        $pdo->beginTransaction();

        while (($row = fgetcsv($handle)) !== false) {
            $rowNum++;
            // Skip empty rows
            if (empty(array_filter($row, fn($v) => trim((string)$v) !== ''))) {
                continue;
            }

            $name = trim($row[0] ?? '');
            $code = trim($row[1] ?? '');
            $catName = trim($row[2] ?? '');
            $brandName = trim($row[3] ?? '');
            $barcode = trim($row[4] ?? '');
            $pcsPerCarton = (float)($row[5] ?? 0);
            $costPrice = (float)($row[6] ?? 0);
            $tradePrice = (float)($row[7] ?? 0);
            $retailPrice = (float)($row[8] ?? 0);
            $openingStock = (float)($row[9] ?? 0);

            if (empty($name)) {
                $errors[] = "Row {$rowNum}: Product Name is required.";
                continue;
            }

            if (empty($code)) {
                $code = 'SKU-' . strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $name), 0, 6)) . '-' . rand(100, 999);
            }

            // Category match or auto-create
            $catId = 1;
            if (!empty($catName)) {
                $catKey = strtolower($catName);
                if (isset($catMap[$catKey])) {
                    $catId = $catMap[$catKey];
                } else {
                    $catCode = 'CAT-' . strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $catName), 0, 4)) . '-' . rand(10, 99);
                    $insCatStmt->execute([$catName, $catCode]);
                    $catId = (int)$pdo->lastInsertId();
                    $catMap[$catKey] = $catId;
                }
            }

            // Brand match or auto-create
            $brandId = null;
            if (!empty($brandName)) {
                $bKey = strtolower($brandName);
                if (isset($brandMap[$bKey])) {
                    $brandId = $brandMap[$bKey];
                } else {
                    $bCode = 'BRD-' . strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $brandName), 0, 4)) . '-' . rand(10, 99);
                    $insBrandStmt->execute([$brandName, $bCode]);
                    $brandId = (int)$pdo->lastInsertId();
                    $brandMap[$bKey] = $brandId;
                }
            }

            // Check if product exists
            $checkProdStmt->execute([$code]);
            $existingId = $checkProdStmt->fetchColumn();

            if ($existingId) {
                $prodId = (int)$existingId;
                $updateProdStmt->execute([
                    $catId, $brandId, $name, $barcode,
                    $costPrice, $tradePrice, $retailPrice, $prodId
                ]);
                $updated++;
            } else {
                $insertProdStmt->execute([
                    $catId, $brandId, $pieceUnitId, $name, $code, $barcode,
                    $costPrice, $tradePrice, $retailPrice
                ]);
                $prodId = (int)$pdo->lastInsertId();
                $inserted++;
            }

            // Register Carton Unit Conversion if specified
            if ($pcsPerCarton > 1) {
                $upsertConvStmt->execute([$prodId, $cartonUnitId, $pieceUnitId, $pcsPerCarton]);
            }

            // Opening stock recording
            if ($openingStock > 0 && !$existingId) {
                $currentBal = (float)$pdo->query("SELECT COALESCE(SUM(quantity), 0) FROM stock_ledger WHERE product_id = {$prodId} AND warehouse_id = {$mainWarehouseId}")->fetchColumn();
                $newBal = $currentBal + $openingStock;
                $stockLedgerStmt->execute([$prodId, $mainWarehouseId, $openingStock, $costPrice, $newBal]);
            }
        }

        $pdo->commit();
        fclose($handle);

        logAudit($pdo, 'products', 0, 'import_csv', null, ['inserted' => $inserted, 'updated' => $updated, 'errors' => count($errors)]);
        $importResults = [
            'inserted' => $inserted,
            'updated' => $updated,
            'errors' => $errors
        ];

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fclose($handle);
        setFlashMessage('error', 'Import failed due to database error: ' . $e->getMessage());
        redirect(BASE_URL . '/modules/products/import.php');
    }
}

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-3 gap-2">
    <div>
        <h4 class="fw-bold mb-0 text-dark"><i class="bi bi-file-earmark-excel text-success me-2"></i>Bulk Import Products & SKUs</h4>
        <small class="text-muted">Import complete product catalog, carton pack ratios, trade prices and initial stock via CSV / Excel</small>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="<?= BASE_URL ?>/modules/products/import.php?action=download_template" class="btn btn-outline-success btn-sm shadow-sm">
            <i class="bi bi-download me-1"></i> Download Excel / CSV Template
        </a>
        <a href="<?= BASE_URL ?>/modules/products/index.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i> Products Catalog
        </a>
    </div>
</div>

<?php if ($importResults): ?>
<div class="alert alert-success bg-white border-success shadow-sm rounded-3 mb-4 p-3">
    <div class="d-flex align-items-center gap-3">
        <i class="bi bi-check-circle-fill fs-2 text-success"></i>
        <div>
            <h5 class="fw-bold text-success mb-1">Import Completed Successfully!</h5>
            <p class="mb-0 text-dark">
                <strong><?= $importResults['inserted'] ?></strong> new products created &bull; 
                <strong><?= $importResults['updated'] ?></strong> existing products updated.
            </p>
        </div>
    </div>
    <?php if (!empty($importResults['errors'])): ?>
        <hr>
        <div class="text-danger small">
            <strong>Warnings / Skipped Rows:</strong>
            <ul class="mb-0 mt-1">
                <?php foreach ($importResults['errors'] as $err): ?>
                    <li><?= htmlspecialchars($err) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="row g-4">
    <!-- Upload Card -->
    <div class="col-12 col-lg-7">
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-header bg-white py-3 border-0">
                <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-cloud-arrow-up text-primary me-2"></i>Upload Spreadsheet File</h6>
                <small class="text-muted">Select or drag your filled CSV / Excel file</small>
            </div>
            <div class="card-body p-4">
                <form method="POST" action="<?= BASE_URL ?>/modules/products/import.php" enctype="multipart/form-data">
                    <?= getCsrfField() ?>
                    <input type="hidden" name="import_products" value="1">

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-dark">CSV File (*.csv)</label>
                        <input type="file" name="csv_file" class="form-control" accept=".csv,text/csv,text/plain" required>
                        <div class="form-text small">
                            Save your Excel spreadsheet as <strong>CSV (Comma delimited) (*.csv)</strong> before uploading.
                        </div>
                    </div>

                    <div class="card bg-light border p-3 rounded-3 mb-3 small">
                        <div class="fw-bold text-dark mb-1"><i class="bi bi-info-circle me-1 text-primary"></i>Automatic Intelligent Processing:</div>
                        <ul class="mb-0 ps-3 text-muted">
                            <li><strong>Carton Conversions:</strong> Defining <code>Pieces per Carton</code> (e.g. 24 or 36) automatically builds carton-to-piece sales & deduction ratios.</li>
                            <li><strong>Categories & Brands:</strong> New category or brand names are created on the fly if not already in system.</li>
                            <li><strong>Duplicate Handling:</strong> If SKU Code exists, prices and details are updated without duplicating the record.</li>
                        </ul>
                    </div>

                    <div class="d-flex justify-content-end gap-2">
                        <button type="submit" class="btn btn-primary px-4 fw-semibold shadow-sm">
                            <i class="bi bi-upload me-1"></i> Start Import Now
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Template Guidance -->
    <div class="col-12 col-lg-5">
        <div class="card border-0 shadow-sm rounded-3 h-100">
            <div class="card-header bg-white py-3 border-0">
                <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-table text-primary me-2"></i>Expected Column Format</h6>
                <small class="text-muted">Standard format of columns in spreadsheet</small>
            </div>
            <div class="card-body p-3">
                <div class="table-responsive">
                    <table class="table table-sm table-bordered align-middle small mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Column Header</th>
                                <th>Example</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td>1</td><td><strong>Product Name</strong></td><td>Nimko Daal Moth (1*36)</td></tr>
                            <tr><td>2</td><td><strong>SKU Code</strong></td><td>NMK-DM-36</td></tr>
                            <tr><td>3</td><td><strong>Category</strong></td><td>Snacks</td></tr>
                            <tr><td>4</td><td><strong>Brand</strong></td><td>Falcon</td></tr>
                            <tr><td>5</td><td><strong>Barcode</strong></td><td>89640001</td></tr>
                            <tr><td>6</td><td><strong>Pieces per Carton</strong></td><td>36</td></tr>
                            <tr><td>7</td><td><strong>Purchase Cost</strong></td><td>1250.00</td></tr>
                            <tr><td>8</td><td><strong>Trade Price (TP)</strong></td><td>1512.00</td></tr>
                            <tr><td>9</td><td><strong>Retail Price (MRP)</strong></td><td>1800.00</td></tr>
                            <tr><td>10</td><td><strong>Opening Stock</strong></td><td>50</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="mt-3 text-center">
                    <a href="<?= BASE_URL ?>/modules/products/import.php?action=download_template" class="btn btn-success btn-sm w-100 fw-semibold">
                        <i class="bi bi-file-earmark-arrow-down me-1"></i> Download Pre-Formatted CSV
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
