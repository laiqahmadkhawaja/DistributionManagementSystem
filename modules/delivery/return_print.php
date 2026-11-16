<?php
/**
 * Customer Shop Return Slip / Acknowledgement (Official Print & WhatsApp)
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();

$returnId = (int)($_GET['id'] ?? 0);
if (!$returnId) {
    setFlashMessage('error', 'Invalid return ID.');
    redirect(BASE_URL . '/modules/delivery/index.php');
}

$pdo = getDatabaseConnection();
$company = getCompanyInfo($pdo);

// Fetch return details
$stmt = $pdo->prepare("
    SELECT sr.*, c.shop_name, c.owner_name, c.phone as cust_phone, c.whatsapp as cust_whatsapp, 
           c.address as cust_address, c.code as cust_code, c.current_balance,
           a.name as area_name,
           e.full_name as driver_name, e.employee_code as driver_code, e.phone as driver_phone,
           cn.credit_note_number
    FROM `sales_returns` sr
    JOIN `customers` c ON sr.customer_id = c.id
    JOIN `areas` a ON c.area_id = a.id
    LEFT JOIN `employees` e ON sr.delivery_man_id = e.id
    LEFT JOIN `credit_notes` cn ON cn.sales_return_id = sr.id
    WHERE sr.id = ?
    LIMIT 1
");
$stmt->execute([$returnId]);
$return = $stmt->fetch();

if (!$return) {
    setFlashMessage('error', 'Return record not found.');
    redirect(BASE_URL . '/modules/delivery/index.php');
}

// Fetch items
$itemsStmt = $pdo->prepare("
    SELECT sri.*, p.name as product_name, p.code as product_code, u.name as unit_name
    FROM `sales_return_items` sri
    JOIN `products` p ON sri.product_id = p.id
    JOIN `units` u ON sri.unit_id = u.id
    WHERE sri.sales_return_id = ?
");
$itemsStmt->execute([$returnId]);
$items = $itemsStmt->fetchAll();

$autoprint = isset($_GET['print']) && $_GET['print'] == '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Return Slip - <?= htmlspecialchars($return['return_number']) ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/vendor/bootstrap-icons/bootstrap-icons.min.css">
    <style>
        body {
            background-color: #f1f5f9;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            color: #0f172a;
            padding: 20px 10px;
        }
        .slip-card {
            max-width: 650px;
            margin: 0 auto;
            background: #fff;
            border-radius: 12px;
            border: 1px solid #cbd5e1;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
            padding: 24px;
        }
        .slip-header {
            border-bottom: 2px dashed #94a3b8;
            padding-bottom: 16px;
            margin-bottom: 16px;
            text-align: center;
        }
        .table-compact td, .table-compact th {
            padding: 6px 8px;
            font-size: 0.85rem;
        }
        .sig-line {
            border-top: 1px solid #475569;
            margin-top: 36px;
            padding-top: 4px;
            font-size: 0.75rem;
            text-align: center;
            font-weight: 600;
        }
        @media print {
            body {
                background: #fff !important;
                padding: 0 !important;
            }
            .no-print {
                display: none !important;
            }
            .slip-card {
                border: none !important;
                box-shadow: none !important;
                max-width: 100% !important;
                padding: 10px !important;
            }
        }
    </style>
</head>
<body>

<div class="no-print text-center mb-3">
    <div class="btn-group shadow-sm">
        <button type="button" class="btn btn-primary fw-bold" onclick="window.print()">
            <i class="bi bi-printer-fill me-1"></i> Print Return Slip
        </button>
        <button type="button" class="btn btn-success fw-bold" onclick="shareWhatsApp()">
            <i class="bi bi-whatsapp me-1"></i> Share on WhatsApp
        </button>
        <a href="<?= BASE_URL ?>/modules/delivery/index.php" class="btn btn-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back to Delivery App
        </a>
    </div>
</div>

<div class="slip-card">
    <div class="slip-header">
        <h4 class="fw-bold mb-1 text-dark"><?= htmlspecialchars($company['name'] ?? APP_NAME) ?></h4>
        <div class="text-muted small"><?= htmlspecialchars($company['address'] ?? 'Central FMCG Distribution') ?> | Phone: <?= htmlspecialchars($company['phone'] ?? '') ?></div>
        <div class="badge bg-danger text-white text-uppercase px-3 py-1 mt-2" style="letter-spacing: 1px; font-size: 0.8rem;">
            Customer Product Return Acknowledgement
        </div>
    </div>

    <div class="row g-2 mb-3 small">
        <div class="col-6">
            <div><span class="text-muted">Return Voucher #:</span> <strong class="text-danger"><?= htmlspecialchars($return['return_number']) ?></strong></div>
            <div><span class="text-muted">Credit Note #:</span> <strong class="text-success"><?= htmlspecialchars($return['credit_note_number'] ?? 'CN-Auto') ?></strong></div>
            <div><span class="text-muted">Return Date:</span> <strong><?= formatDate($return['return_date']) ?></strong></div>
            <div><span class="text-muted">Collected By:</span> <strong><?= htmlspecialchars($return['driver_name'] ?? 'Delivery Agent') ?></strong></div>
        </div>
        <div class="col-6 text-end">
            <div><span class="text-muted">Shop:</span> <strong class="text-dark fs-6"><?= htmlspecialchars($return['shop_name']) ?></strong></div>
            <div><span class="text-muted">Owner:</span> <strong><?= htmlspecialchars($return['owner_name']) ?></strong></div>
            <div><span class="text-muted">Area / Route:</span> <strong><?= htmlspecialchars($return['area_name']) ?></strong></div>
            <div><span class="text-muted">Phone:</span> <strong><?= htmlspecialchars($return['cust_phone']) ?></strong></div>
        </div>
    </div>

    <!-- Items Table (100% Fit & Zero Horizontal Scroll) -->
    <div class="mb-3" style="overflow-x: hidden; width: 100%;">
        <table class="table table-bordered table-compact mb-0 bg-white" style="table-layout: fixed; width: 100%;">
            <thead class="table-light">
                <tr>
                    <th style="width: 44%;">Product Description</th>
                    <th style="width: 12%;">Unit</th>
                    <th style="width: 14%;" class="text-center">Returned Qty</th>
                    <th style="width: 14%;">Condition</th>
                    <th style="width: 16%;" class="text-end">Credit Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $it): ?>
                    <tr>
                        <td style="word-break: break-word;">
                            <div class="fw-bold text-dark"><?= htmlspecialchars($it['product_name']) ?></div>
                            <small class="text-muted"><?= htmlspecialchars($it['product_code']) ?></small>
                        </td>
                        <td><?= htmlspecialchars($it['unit_name']) ?></td>
                        <td class="text-center fw-bold text-danger"><?= (float)$it['quantity'] ?></td>
                        <td>
                            <span class="badge bg-light text-dark border"><?= ucfirst(str_replace('_', ' ', $it['condition_type'])) ?></span>
                        </td>
                        <td class="text-end fw-bold text-danger"><?= formatMoney($it['total_amount']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot class="table-light">
                <tr>
                    <td colspan="4" class="text-end fw-bold">Total Credit Given to Shop:</td>
                    <td class="text-end fw-bold text-danger fs-6"><?= formatMoney($return['total_amount']) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <?php if (!empty($return['reason'])): ?>
        <div class="mb-3 small p-2 bg-light rounded border text-muted">
            <strong>Notes / Reason:</strong> <?= htmlspecialchars($return['reason']) ?>
        </div>
    <?php endif; ?>

    <div class="alert alert-success py-2 px-3 small border-0 rounded-2 mb-3">
        <i class="bi bi-check-circle-fill me-1"></i><strong>Account Balance Credited:</strong> 
        Rs. <?= number_format($return['total_amount'], 2) ?> has been deducted from shop dues. 
        Current outstanding balance: <strong><?= formatMoney($return['current_balance']) ?></strong>.
    </div>

    <!-- Signature Blocks -->
    <div class="row g-3 pt-2">
        <div class="col-6">
            <div class="sig-line">
                <div><?= htmlspecialchars($return['driver_name'] ?? 'Delivery Driver') ?></div>
                <small class="text-muted fw-normal">Delivery Specialist (Receiving Custody)</small>
            </div>
        </div>
        <div class="col-6">
            <div class="sig-line">
                <div><?= htmlspecialchars($return['shop_name']) ?></div>
                <small class="text-muted fw-normal">Shopkeeper Signature & Stamp</small>
            </div>
        </div>
    </div>

    <div class="text-center mt-3 pt-2 text-muted" style="font-size: 0.68rem; border-top: 1px dashed #cbd5e1;">
        Official Return Acknowledgment. Items are held in delivery vehicle custody pending warehouse inspection.
    </div>
</div>

<script>
function shareWhatsApp() {
    const shop = "<?= addslashes(htmlspecialchars($return['shop_name'])) ?>";
    const voucherNo = "<?= addslashes(htmlspecialchars($return['return_number'])) ?>";
    const creditNote = "<?= addslashes(htmlspecialchars($return['credit_note_number'] ?? 'CN-Auto')) ?>";
    const totalAmount = "<?= number_format($return['total_amount'], 2) ?>";
    const newBal = "<?= number_format($return['current_balance'], 2) ?>";
    const phone = "<?= htmlspecialchars($return['cust_whatsapp'] ?: $return['cust_phone']) ?>";

    let text = `*Falcon FMCG Distribution - Product Return Voucher*\n`;
    text += `Return Voucher: *${voucherNo}*\n`;
    text += `Credit Note: *${creditNote}*\n`;
    text += `Customer / Shop: *${shop}*\n`;
    text += `Total Return Credit: *Rs. ${totalAmount}*\n`;
    text += `Remaining Shop Balance: *Rs. ${newBal}*\n`;
    text += `------------------------------------\n`;
    text += `Your returned goods have been acknowledged by the delivery agent and your ledger balance has been credited.\n`;
    text += `Falcon FMCG Central Distribution Gujranwala`;

    const cleanPhone = (phone || '').replace(/[^0-9]/g, '');
    let url = 'https://api.whatsapp.com/send?text=' + encodeURIComponent(text);
    if (cleanPhone) {
        let p = cleanPhone.startsWith('0') ? '92' + cleanPhone.substring(1) : cleanPhone;
        url = 'https://api.whatsapp.com/send?phone=' + p + '&text=' + encodeURIComponent(text);
    }
    window.open(url, '_blank');
}

<?php if ($autoprint): ?>
window.onload = function() {
    window.print();
};
<?php endif; ?>
</script>
</body>
</html>
