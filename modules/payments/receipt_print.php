<?php
/**
 * Customer Payment Recovery Receipt Voucher (Official Print / Share)
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();

$paymentId = (int)($_GET['id'] ?? 0);
if (!$paymentId) {
    setFlashMessage('error', 'Invalid payment receipt ID.');
    redirect(BASE_URL . '/modules/payments/recovery.php');
}

$pdo = getDatabaseConnection();
$company = getCompanyInfo($pdo);

// Fetch complete payment receipt details
$stmt = $pdo->prepare("
    SELECT p.*, 
           c.shop_name, c.owner_name, c.phone as cust_phone, c.whatsapp as cust_whatsapp, 
           c.address as cust_address, c.code as cust_code, c.current_balance as latest_balance,
           a.name as area_name,
           e.full_name as collector_name, e.employee_code as collector_code, e.phone as collector_phone,
           pm.name as method_name,
           coa.code as account_code, coa.name as account_name
    FROM `payments` p
    JOIN `customers` c ON p.party_id = c.id
    JOIN `areas` a ON c.area_id = a.id
    JOIN `payment_methods` pm ON p.payment_method_id = pm.id
    LEFT JOIN `employees` e ON p.collected_by = e.id
    LEFT JOIN `chart_of_accounts` coa ON p.payment_account_id = coa.id
    WHERE p.id = ? AND p.party_type = 'customer'
    LIMIT 1
");
$stmt->execute([$paymentId]);
$receipt = $stmt->fetch();

if (!$receipt) {
    setFlashMessage('error', 'Payment receipt voucher not found.');
    redirect(BASE_URL . '/modules/payments/recovery.php');
}

// Fetch any invoice allocations settled by this voucher
$allocStmt = $pdo->prepare("
    SELECT pa.allocated_amount, si.invoice_number, si.invoice_date, si.grand_total, si.balance_due
    FROM `payment_allocations` pa
    JOIN `sales_invoices` si ON pa.invoice_id = si.id
    WHERE pa.payment_id = ?
");
$allocStmt->execute([$paymentId]);
$allocations = $allocStmt->fetchAll();

// Estimate prior balance before this payment
$amountPaid = (float)$receipt['amount'];
$latestBalance = (float)$receipt['latest_balance'];
$priorBalance = $latestBalance + $amountPaid;

$autoprint = isset($_GET['print']) && $_GET['print'] == '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Receipt - <?= htmlspecialchars($receipt['payment_number']) ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/vendor/bootstrap-icons/bootstrap-icons.min.css">
    <style>
        body {
            background-color: #f1f5f9;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            color: #0f172a;
            padding: 20px 10px;
        }
        .receipt-card {
            max-width: 600px;
            margin: 0 auto;
            background: #fff;
            border-radius: 12px;
            border: 1px solid #cbd5e1;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
            padding: 24px;
        }
        .receipt-header {
            border-bottom: 2px dashed #94a3b8;
            padding-bottom: 16px;
            margin-bottom: 16px;
            text-align: center;
        }
        .table-compact td, .table-compact th {
            padding: 5px 8px;
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
            .receipt-card {
                border: none !important;
                box-shadow: none !important;
                max-width: 100% !important;
                padding: 10px !important;
            }
        }
    </style>
</head>
<body>

<!-- Action Bar (Hidden during printing) -->
<div class="no-print text-center mb-3">
    <div class="btn-group shadow-sm">
        <button type="button" class="btn btn-primary fw-bold" onclick="window.print()">
            <i class="bi bi-printer-fill me-1"></i> Print Receipt Voucher
        </button>
        <button type="button" class="btn btn-success fw-bold" onclick="shareWhatsApp()">
            <i class="bi bi-whatsapp me-1"></i> Share on WhatsApp
        </button>
        <a href="<?= BASE_URL ?>/modules/payments/recovery.php" class="btn btn-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back to Recovery
        </a>
    </div>
</div>

<div class="receipt-card">
    <!-- Header -->
    <div class="receipt-header">
        <h4 class="fw-bold mb-1 text-dark"><?= htmlspecialchars($company['name'] ?? APP_NAME) ?></h4>
        <div class="text-muted small"><?= htmlspecialchars($company['address'] ?? 'Central FMCG Distribution') ?> | Phone: <?= htmlspecialchars($company['phone'] ?? '') ?></div>
        <div class="badge bg-dark text-white text-uppercase px-3 py-1 mt-2" style="letter-spacing: 1px; font-size: 0.8rem;">
            Customer Payment Recovery Receipt
        </div>
    </div>

    <!-- Voucher Top Details -->
    <div class="row g-2 mb-3 small">
        <div class="col-6">
            <div><span class="text-muted">Receipt No:</span> <strong class="text-primary"><?= htmlspecialchars($receipt['payment_number']) ?></strong></div>
            <div><span class="text-muted">Date:</span> <strong><?= formatDate($receipt['payment_date']) ?></strong></div>
            <div><span class="text-muted">Payment Mode:</span> <span class="badge bg-light text-dark border"><?= strtoupper($receipt['payment_method_code']) ?></span></div>
            <?php if (!empty($receipt['account_name'])): ?>
                <div><span class="text-muted">Deposited Into:</span> <strong><?= htmlspecialchars($receipt['account_name']) ?></strong> <small class="text-muted">(<?= htmlspecialchars($receipt['account_code']) ?>)</small></div>
            <?php endif; ?>
            <?php if (!empty($receipt['reference_no'])): ?>
                <div><span class="text-muted">Ref/TID:</span> <strong><?= htmlspecialchars($receipt['reference_no']) ?></strong></div>
            <?php endif; ?>
        </div>
        <div class="col-6 text-end">
            <div><span class="text-muted">Shop:</span> <strong class="text-dark fs-6"><?= htmlspecialchars($receipt['shop_name']) ?></strong></div>
            <div><span class="text-muted">Owner:</span> <strong><?= htmlspecialchars($receipt['owner_name']) ?></strong></div>
            <div><span class="text-muted">Area / Route:</span> <strong><?= htmlspecialchars($receipt['area_name']) ?></strong></div>
            <div><span class="text-muted">Contact:</span> <strong><?= htmlspecialchars($receipt['cust_phone']) ?></strong></div>
        </div>
    </div>

    <!-- Payment Summary Box -->
    <div class="p-3 bg-light rounded-3 mb-3 border">
        <table class="table table-borderless table-compact mb-0">
            <tr>
                <td class="text-muted">Previous Outstanding Balance:</td>
                <td class="text-end fw-semibold text-danger"><?= formatMoney($priorBalance) ?></td>
            </tr>
            <tr class="border-top border-bottom">
                <td class="fs-6 fw-bold text-success py-2">
                    <i class="bi bi-check2-circle me-1"></i>Amount Received / Recovered:
                </td>
                <td class="fs-5 fw-bold text-success text-end py-2">
                    <?= formatMoney($amountPaid) ?>
                </td>
            </tr>
            <tr>
                <td class="fw-bold text-dark pt-2">Remaining Shop Balance:</td>
                <td class="text-end fw-bold text-dark fs-6 pt-2">
                    <?= formatMoney($latestBalance) ?>
                </td>
            </tr>
        </table>
    </div>

    <!-- Invoice Allocations (if any settled) -->
    <?php if (!empty($allocations)): ?>
    <div class="mb-3">
        <div class="text-muted small fw-semibold mb-1">Bills / Invoices Settled by this Voucher:</div>
        <table class="table table-sm table-bordered table-compact mb-0 bg-white">
            <thead class="table-light">
                <tr>
                    <th>Invoice #</th>
                    <th>Date</th>
                    <th class="text-end">Invoice Amount</th>
                    <th class="text-end">Applied Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($allocations as $a): ?>
                    <tr>
                        <td class="fw-semibold text-primary"><?= htmlspecialchars($a['invoice_number']) ?></td>
                        <td><?= formatDate($a['invoice_date']) ?></td>
                        <td class="text-end"><?= formatMoney($a['grand_total']) ?></td>
                        <td class="text-end fw-bold text-success"><?= formatMoney($a['allocated_amount']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php if (!empty($receipt['notes'])): ?>
    <div class="mb-3 small p-2 bg-light rounded border text-muted">
        <strong>Remarks:</strong> <?= htmlspecialchars($receipt['notes']) ?>
    </div>
    <?php endif; ?>

    <!-- Signature Blocks -->
    <div class="row g-3 pt-3 mt-1">
        <div class="col-6">
            <div class="sig-line">
                <div><?= htmlspecialchars($receipt['collector_name'] ?? 'Recovery Officer') ?></div>
                <small class="text-muted fw-normal">Authorized Collection Officer</small>
            </div>
        </div>
        <div class="col-6">
            <div class="sig-line">
                <div><?= htmlspecialchars($receipt['shop_name']) ?></div>
                <small class="text-muted fw-normal">Customer / Shopkeeper Signature</small>
            </div>
        </div>
    </div>

    <!-- Receipt Footer Notice -->
    <div class="text-center mt-3 pt-2 text-muted" style="font-size: 0.68rem; border-top: 1px dashed #cbd5e1;">
        Computer Generated Official Payment Receipt. Retain for account reconciliation.
    </div>
</div>

<script>
function shareWhatsApp() {
    const shop = "<?= addslashes(htmlspecialchars($receipt['shop_name'])) ?>";
    const voucherNo = "<?= addslashes(htmlspecialchars($receipt['payment_number'])) ?>";
    const amount = "<?= number_format($amountPaid, 2) ?>";
    const balance = "<?= number_format($latestBalance, 2) ?>";
    const phone = "<?= htmlspecialchars($receipt['cust_whatsapp'] ?: $receipt['cust_phone']) ?>";

    let text = `*Falcon FMCG Distribution - Payment Receipt*\n`;
    text += `Receipt Voucher: *${voucherNo}*\n`;
    text += `Customer / Shop: *${shop}*\n`;
    text += `Payment Received: *Rs. ${amount}*\n`;
    text += `Remaining Balance: *Rs. ${balance}*\n`;
    text += `Payment Mode: *<?= strtoupper($receipt['payment_method_code']) ?>*\n`;
    text += `Collected By: *<?= htmlspecialchars($receipt['collector_name'] ?? 'Staff') ?>*\n`;
    text += `------------------------------------\n`;
    text += `Thank you for your business & prompt payment!\n`;
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
