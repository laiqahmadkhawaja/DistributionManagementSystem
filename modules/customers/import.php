<?php
/**
 * High-Speed Retail Customer Shops Excel/CSV Bulk Importer
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('customers', 'create');

$pdo = getDatabaseConnection();
$company = getCompanyInfo($pdo);
$pageTitle = 'Import Retail Shops (Excel/CSV)';

// 1. Download Sample CSV Template
if (isset($_GET['action']) && $_GET['action'] === 'download_template') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="DMS_Customers_Import_Template.csv"');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Header
    fputcsv($output, [
        'Shop Name',
        'Owner Name',
        'Phone Number',
        'WhatsApp Number',
        'Area / Route',
        'Shop Address',
        'Credit Limit',
        'Opening Balance'
    ]);
    
    // Sample Rows
    fputcsv($output, ['Prince Grocery', 'Imran Butt', '03001234567', '03001234567', 'Jandiala Bhagwala', 'Main Bazar Jandiala Bhagwala G.T Road', '25000.00', '0.00']);
    fputcsv($output, ['Usman J.S', 'Muhammad Usman', '03019876543', '03019876543', 'Civil Lines', 'Opposite General Post Office', '15000.00', '3672.00']);
    fputcsv($output, ['Waqas Super Store', 'Waqas Ahmed', '03215554433', '03215554433', 'Model Town', 'Market Chowk Model Town', '20000.00', '950.00']);
    fputcsv($output, ['Hafiz Super Store', 'Hafiz Bilal', '03334445566', '03334445566', 'Jandiala Bhagwala', 'G.T. Road Gujranwala', '50000.00', '15390.00']);
    
    fclose($output);
    exit;
}

// 2. Process CSV Upload POST
$importResults = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_customers'])) {
    if (empty($_FILES['csv_file']['tmp_name']) || !is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
        setFlashMessage('error', 'Please select a valid CSV file to upload.');
        redirect(BASE_URL . '/modules/customers/import.php');
    }

    $filePath = $_FILES['csv_file']['tmp_name'];
    $handle = fopen($filePath, 'r');
    if (!$handle) {
        setFlashMessage('error', 'Unable to read the uploaded file.');
        redirect(BASE_URL . '/modules/customers/import.php');
    }

    // Skip BOM if present
    $bom = fread($handle, 3);
    if ($bom !== "\xEF\xBB\xBF") {
        rewind($handle);
    }

    $header = fgetcsv($handle);
    if (!$header || count($header) < 2) {
        fclose($handle);
        setFlashMessage('error', 'Invalid CSV structure. Header row is missing or empty.');
        redirect(BASE_URL . '/modules/customers/import.php');
    }

    // Prepare Area Map
    $areaMap = [];
    foreach ($pdo->query("SELECT id, LOWER(name) as name_key FROM areas WHERE status = 'active'")->fetchAll() as $a) {
        $areaMap[$a['name_key']] = (int)$a['id'];
    }

    $insAreaStmt = $pdo->prepare("INSERT INTO areas (`name`, `code`, `status`, `created_at`) VALUES (?, ?, 'active', NOW())");
    $checkCustStmt = $pdo->prepare("SELECT id FROM customers WHERE shop_name = ? AND phone = ? LIMIT 1");
    
    $insCustStmt = $pdo->prepare("
        INSERT INTO customers (
            `area_id`, `shop_name`, `owner_name`, `code`, `phone`, `whatsapp`,
            `address`, `credit_limit`, `opening_balance`, `current_balance`, `status`, `created_at`
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW())
    ");

    $updateCustStmt = $pdo->prepare("
        UPDATE customers 
        SET `area_id` = ?, `owner_name` = ?, `whatsapp` = ?, `address` = ?,
            `credit_limit` = ?, `opening_balance` = ?, `current_balance` = ?, `status` = 'active'
        WHERE id = ?
    ");

    $inserted = 0;
    $updated = 0;
    $errors = [];
    $rowNum = 1;

    try {
        $pdo->beginTransaction();

        while (($row = fgetcsv($handle)) !== false) {
            $rowNum++;
            if (empty(array_filter($row, fn($v) => trim((string)$v) !== ''))) {
                continue;
            }

            $shopName = trim($row[0] ?? '');
            $ownerName = trim($row[1] ?? '');
            $phone = trim($row[2] ?? '');
            $whatsapp = trim($row[3] ?? '');
            $areaName = trim($row[4] ?? '');
            $address = trim($row[5] ?? '');
            $creditLimit = (float)($row[6] ?? 0);
            $openingBalance = (float)($row[7] ?? 0);

            if (empty($shopName)) {
                $errors[] = "Row {$rowNum}: Shop Name is required.";
                continue;
            }

            // Match or create Area
            $areaId = 1;
            if (!empty($areaName)) {
                $aKey = strtolower($areaName);
                if (isset($areaMap[$aKey])) {
                    $areaId = $areaMap[$aKey];
                } else {
                    $aCode = 'AR-' . strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $areaName), 0, 4)) . '-' . rand(10, 99);
                    $insAreaStmt->execute([$areaName, $aCode]);
                    $areaId = (int)$pdo->lastInsertId();
                    $areaMap[$aKey] = $areaId;
                }
            }

            // Check if customer exists by shop name and phone
            $checkCustStmt->execute([$shopName, $phone]);
            $existingId = $checkCustStmt->fetchColumn();

            if ($existingId) {
                $updateCustStmt->execute([
                    $areaId, $ownerName, $whatsapp, $address,
                    $creditLimit, $openingBalance, $openingBalance, (int)$existingId
                ]);
                $updated++;
            } else {
                $code = 'CUST-' . strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $shopName), 0, 4)) . '-' . rand(1000, 9999);
                $insCustStmt->execute([
                    $areaId, $shopName, $ownerName, $code, $phone, $whatsapp,
                    $address, $creditLimit, $openingBalance, $openingBalance
                ]);
                $inserted++;
            }
        }

        $pdo->commit();
        fclose($handle);

        logAudit($pdo, 'customers', 0, 'import_csv', null, ['inserted' => $inserted, 'updated' => $updated, 'errors' => count($errors)]);
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
        redirect(BASE_URL . '/modules/customers/import.php');
    }
}

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-3 gap-2">
    <div>
        <h4 class="fw-bold mb-0 text-dark"><i class="bi bi-file-earmark-excel text-primary me-2"></i>Bulk Import Retail Shops / Customers</h4>
        <small class="text-muted">Import shop profiles, phone contacts, delivery routes, credit limits and balances via CSV / Excel</small>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="<?= BASE_URL ?>/modules/customers/import.php?action=download_template" class="btn btn-outline-success btn-sm shadow-sm">
            <i class="bi bi-download me-1"></i> Download Excel / CSV Template
        </a>
        <a href="<?= BASE_URL ?>/modules/customers/index.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i> Customers List
        </a>
    </div>
</div>

<?php if ($importResults): ?>
<div class="alert alert-success bg-white border-success shadow-sm rounded-3 mb-4 p-3">
    <div class="d-flex align-items-center gap-3">
        <i class="bi bi-check-circle-fill fs-2 text-success"></i>
        <div>
            <h5 class="fw-bold text-success mb-1">Customer Import Completed Successfully!</h5>
            <p class="mb-0 text-dark">
                <strong><?= $importResults['inserted'] ?></strong> new retail shops added &bull; 
                <strong><?= $importResults['updated'] ?></strong> existing customer records updated.
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
                <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-cloud-arrow-up text-primary me-2"></i>Upload Customer Spreadsheet</h6>
                <small class="text-muted">Select or drag your filled CSV file</small>
            </div>
            <div class="card-body p-4">
                <form method="POST" action="<?= BASE_URL ?>/modules/customers/import.php" enctype="multipart/form-data">
                    <?= getCsrfField() ?>
                    <input type="hidden" name="import_customers" value="1">

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
                            <li><strong>Route & Area Matching:</strong> New market areas or delivery route names are automatically created if they do not exist.</li>
                            <li><strong>Account Identifiers:</strong> Customer codes are generated automatically for new shops.</li>
                            <li><strong>Balance Tracking:</strong> Opening balances are initialized into the customer ledger.</li>
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
                            <tr><td>1</td><td><strong>Shop Name</strong></td><td>Prince Grocery</td></tr>
                            <tr><td>2</td><td><strong>Owner Name</strong></td><td>Imran Butt</td></tr>
                            <tr><td>3</td><td><strong>Phone Number</strong></td><td>03001234567</td></tr>
                            <tr><td>4</td><td><strong>WhatsApp Number</strong></td><td>03001234567</td></tr>
                            <tr><td>5</td><td><strong>Area / Route</strong></td><td>Jandiala Bhagwala</td></tr>
                            <tr><td>6</td><td><strong>Shop Address</strong></td><td>Main Bazar G.T. Road</td></tr>
                            <tr><td>7</td><td><strong>Credit Limit</strong></td><td>25000.00</td></tr>
                            <tr><td>8</td><td><strong>Opening Balance</strong></td><td>0.00</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="mt-3 text-center">
                    <a href="<?= BASE_URL ?>/modules/customers/import.php?action=download_template" class="btn btn-success btn-sm w-100 fw-semibold">
                        <i class="bi bi-file-earmark-arrow-down me-1"></i> Download Pre-Formatted CSV
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
