<?php
/**
 * Professional Sales Bill & Invoice Document View
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();

// Commercial sales bill is viewable by order staff, delivery personnel, warehouse dispatchers, accountants and managers
$canViewBill = hasPermission('orders', 'view') 
    || hasPermission('delivery', 'view') 
    || hasPermission('warehouse', 'view') 
    || hasPermission('accounting', 'view')
    || hasRole([ROLE_SUPER_ADMIN, ROLE_ADMIN, ROLE_ORDER_BOOKER, ROLE_SALESMAN, ROLE_DELIVERY_MAN, ROLE_STOREKEEPER, ROLE_ACCOUNTANT]);

if (!$canViewBill) {
    requirePermission('orders', 'view');
}

$pdo = getDatabaseConnection();
$company = getCompanyInfo($pdo);
$currUser = getCurrentUser();

$orderId = (int)($_GET['id'] ?? ($_GET['order_id'] ?? 0));
$invoiceId = (int)($_GET['invoice_id'] ?? 0);
$deliveryId = (int)($_GET['delivery_id'] ?? 0);

// Resolve Order ID from delivery_id or invoice_id if passed
if (!$orderId) {
    if ($deliveryId) {
        $orderId = (int)$pdo->query("SELECT sales_order_id FROM delivery_assignments WHERE id = {$deliveryId}")->fetchColumn();
    } elseif ($invoiceId) {
        $orderId = (int)$pdo->query("SELECT sales_order_id FROM sales_invoices WHERE id = {$invoiceId}")->fetchColumn();
    }
}

if (!$orderId) {
    setFlashMessage('error', 'Invalid Bill request. Order identifier missing.');
    redirect(BASE_URL . '/modules/orders/index.php');
}

// Fetch complete Sales Order details with Customer, Booker, and Area
$orderStmt = $pdo->prepare("
    SELECT so.*,
           c.shop_name, c.owner_name, c.phone as cust_phone, c.whatsapp as cust_whatsapp, 
           c.address as cust_address, c.code as cust_code, c.current_balance as cust_balance,
           c.credit_limit as cust_credit_limit, c.payment_terms as cust_terms,
           a.name as area_name,
           e.full_name as booker_name, e.phone as booker_phone, e.employee_code as booker_code
    FROM `sales_orders` so
    JOIN `customers` c ON so.customer_id = c.id
    JOIN `areas` a ON so.area_id = a.id
    LEFT JOIN `employees` e ON so.order_booker_id = e.id
    WHERE so.id = ?
    LIMIT 1
");
$orderStmt->execute([$orderId]);
$order = $orderStmt->fetch();

if (!$order) {
    setFlashMessage('error', 'Order not found for generating bill.');
    redirect(BASE_URL . '/modules/orders/index.php');
}

// Fetch associated Sales Invoice (if already dispatched/invoiced)
$invStmt = $pdo->prepare("
    SELECT * FROM `sales_invoices` 
    WHERE sales_order_id = ? AND status != 'voided' 
    ORDER BY id DESC LIMIT 1
");
$invStmt->execute([$orderId]);
$invoice = $invStmt->fetch();

// Fetch Delivery Assignment (if dispatched)
$dispStmt = $pdo->prepare("
    SELECT da.*, e.full_name as driver_name, e.phone as driver_phone, w.name as warehouse_name
    FROM `delivery_assignments` da
    JOIN `employees` e ON da.delivery_man_id = e.id
    JOIN `warehouses` w ON da.warehouse_id = w.id
    WHERE da.sales_order_id = ?
    ORDER BY da.id DESC LIMIT 1
");
$dispStmt->execute([$orderId]);
$dispatch = $dispStmt->fetch();

// Fetch Bill Line Items
$itemsStmt = $pdo->prepare("
    SELECT soi.*, p.name as product_name, p.code as product_code, p.barcode,
           u.name as unit_name
    FROM `sales_order_items` soi
    JOIN `products` p ON soi.product_id = p.id
    JOIN `units` u ON soi.unit_id = u.id
    WHERE soi.sales_order_id = ?
    ORDER BY soi.id ASC
");
$itemsStmt->execute([$orderId]);
$items = $itemsStmt->fetchAll();

// Payments collected against this order or customer
$paymentStmt = $pdo->prepare("
    SELECT * FROM `payments`
    WHERE (reference_no = ? OR notes LIKE ?) AND status = 'posted'
    ORDER BY id ASC
");
$paymentStmt->execute([$order['order_number'], "%{$order['order_number']}%"]);
$collectedPayments = $paymentStmt->fetchAll();

$totalCollectedOnOrder = 0.00;
foreach ($collectedPayments as $cp) {
    $totalCollectedOnOrder += (float)$cp['amount'];
}

// Document Number formatting
$invoiceNo = $invoice['invoice_number'] ?? ('INV-' . str_replace(['SO-', 'SO'], '', $order['order_number']));
$invoiceDate = $invoice['invoice_date'] ?? $order['order_date'];

// Ledger Calculations:
$currentBillAmount = (float)$order['grand_total'];
$balanceDueOnThisBill = max(0, $currentBillAmount - $totalCollectedOnOrder);
$priorOutstanding = max(0, (float)$order['cust_balance'] - $balanceDueOnThisBill);
$netTotalPayable = $priorOutstanding + $balanceDueOnThisBill;

$pageTitle = 'Sales Invoice - ' . $invoiceNo;
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<!-- Document Controls Action Bar -->
<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-3 gap-2 d-print-none">
    <div>
        <h4 class="fw-bold mb-0 text-dark"><i class="bi bi-receipt text-primary me-2"></i>Commercial Sales Invoice</h4>
        <small class="text-muted">Document: <span class="fw-bold text-dark"><?= htmlspecialchars($invoiceNo) ?></span> · Customer: <strong><?= htmlspecialchars($order['shop_name']) ?></strong></small>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <button type="button" class="btn btn-sm btn-primary" onclick="window.print();">
            <i class="bi bi-printer-fill me-1"></i> Print Invoice (A4)
        </button>
        <button type="button" class="btn btn-sm btn-whatsapp" onclick="shareBillWhatsApp();">
            <i class="bi bi-whatsapp me-1"></i> Share via WhatsApp
        </button>
        <button type="button" class="btn btn-sm btn-outline-dark" onclick="captureAndShareElement('printableBill', '<?= $invoiceNo ?>');">
            <i class="bi bi-image me-1"></i> Download Image
        </button>
        <a href="<?= BASE_URL ?>/modules/orders/view.php?id=<?= $orderId ?>" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-sliders me-1"></i> Order Details
        </a>
        <a href="<?= BASE_URL ?>/modules/orders/index.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back to Orders
        </a>
    </div>
</div>

<!-- Main Printable Bill Document (Single-Page A4 Guarantee) -->
<div id="printableBill" class="bill-sheet mx-auto my-2" style="max-width: 900px; width: 100%;">
    
    <!-- Header Block: Company Letterhead & Document Info -->
    <div class="d-flex justify-content-between align-items-start pb-2 mb-2 border-bottom border-dark border-2">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <span class="badge bg-dark p-2 rounded text-white"><i class="bi bi-truck fs-5"></i></span>
                <div>
                    <h3 class="bill-header-title mb-0"><?= htmlspecialchars($company['name'] ?? APP_NAME) ?></h3>
                    <div class="text-muted" style="font-size: 0.72rem; font-weight: 600; letter-spacing: 0.3px;">
                        AUTHORIZED FMCG DISTRIBUTOR & LOGISTICS NETWORK
                    </div>
                </div>
            </div>
            <div class="text-secondary small" style="font-size: 0.72rem; line-height: 1.35;">
                <div><?= htmlspecialchars($company['address'] ?? 'Plot 45, Small Industrial Estate, Gujranwala, Pakistan') ?></div>
                <div>Phone: <strong><?= htmlspecialchars($company['phone'] ?? '+92 300 1234567') ?></strong> · Email: <?= htmlspecialchars($company['email'] ?? 'sales@falcon-dist.com') ?></div>
                <div>STRN / NTN: <strong><?= htmlspecialchars($company['tax_number'] ?? '1234567-8') ?></strong></div>
            </div>
        </div>
        <div class="text-end">
            <div class="bill-badge-status mb-1">COMMERCIAL SALES INVOICE</div>
            <div class="fw-bold fs-5 text-dark font-monospace mt-1" style="letter-spacing: 0.5px;"><?= htmlspecialchars($invoiceNo) ?></div>
            <div class="small text-muted" style="font-size: 0.75rem;">
                Date: <strong class="text-dark"><?= formatDate($invoiceDate) ?></strong>
            </div>
            <div class="small text-muted" style="font-size: 0.72rem;">
                Order Ref: <strong><?= htmlspecialchars($order['order_number']) ?></strong>
            </div>
        </div>
    </div>

    <!-- Metadata Grid: Customer Billed To & Logistics Information (Always Side-by-Side) -->
    <div class="bill-meta-box mb-2">
        <table class="table-layout-grid mb-0" style="width: 100%; table-layout: fixed;">
            <tr>
                <!-- Left: Billed To Customer -->
                <td style="width: 50%; vertical-align: top; padding-right: 10px; border-right: 1px solid #cbd5e1;">
                    <div class="bill-section-title">BILLED TO / CUSTOMER DETAILS</div>
                    <table class="table table-sm table-borderless mb-0" style="font-size: 0.75rem; line-height: 1.25;">
                        <tr>
                            <td class="text-muted ps-0 py-0" style="width: 105px;">Shop Name:</td>
                            <td class="fw-bold text-dark py-0" style="font-size: 0.85rem;"><?= htmlspecialchars($order['shop_name']) ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted ps-0 py-0">Proprietor:</td>
                            <td class="text-dark py-0 fw-semibold"><?= htmlspecialchars($order['owner_name']) ?> (Code: <code><?= htmlspecialchars($order['cust_code']) ?></code>)</td>
                        </tr>
                        <tr>
                            <td class="text-muted ps-0 py-0">Phone / WA:</td>
                            <td class="text-dark py-0"><?= htmlspecialchars($order['cust_phone']) ?> <?= !empty($order['cust_whatsapp']) ? '· WA: ' . htmlspecialchars($order['cust_whatsapp']) : '' ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted ps-0 py-0">Territory / Area:</td>
                            <td class="fw-bold text-dark py-0"><?= htmlspecialchars($order['area_name']) ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted ps-0 py-0">Delivery Address:</td>
                            <td class="text-dark py-0"><?= htmlspecialchars($order['cust_address'] ?: 'Commercial Area') ?></td>
                        </tr>
                    </table>
                </td>

                <!-- Right: Order Logistics & Terms -->
                <td style="width: 50%; vertical-align: top; padding-left: 10px;">
                    <div class="bill-section-title">ORDER & LOGISTICS DETAILS</div>
                    <table class="table table-sm table-borderless mb-0" style="font-size: 0.75rem; line-height: 1.25;">
                        <tr>
                            <td class="text-muted ps-0 py-0" style="width: 100px;">Sales Order #:</td>
                            <td class="fw-bold text-dark py-0"><?= htmlspecialchars($order['order_number']) ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted ps-0 py-0">Payment Terms:</td>
                            <td class="fw-bold text-uppercase py-0 <?= $order['payment_type'] === 'cash' ? 'text-success' : 'text-danger' ?>">
                                <?= htmlspecialchars($order['payment_type']) ?>
                            </td>
                        </tr>
                        <tr>
                            <td class="text-muted ps-0 py-0">Order Booker:</td>
                            <td class="text-dark py-0"><?= htmlspecialchars($order['booker_name'] ?? 'Direct Sales') ?></td>
                        </tr>
                        <?php if ($dispatch): ?>
                        <tr>
                            <td class="text-muted ps-0 py-0">Dispatch Challan:</td>
                            <td class="text-dark fw-bold py-0"><?= htmlspecialchars($dispatch['dispatch_number']) ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted ps-0 py-0">Delivery Driver:</td>
                            <td class="text-dark py-0"><?= htmlspecialchars($dispatch['driver_name'] ?? 'Assigned') ?> (<?= htmlspecialchars($dispatch['vehicle_no'] ?? 'N/A') ?>)</td>
                        </tr>
                        <?php endif; ?>
                    </table>
                </td>
            </tr>
        </table>
    </div>

    <!-- Itemized Bill Table (Excel Spreadsheet Style - 100% Fit) -->
    <div class="table-responsive mb-2">
        <table class="table table-excel align-middle mb-0" style="table-layout: fixed; width: 100%;">
            <thead>
                <tr>
                    <th style="width: 4%;" class="text-center">#</th>
                    <th style="width: 12%;">SKU Code</th>
                    <th style="width: 38%;">Product Description & Specifications</th>
                    <th style="width: 10%;" class="text-center">Packaging</th>
                    <th style="width: 8%;" class="text-center">Quantity</th>
                    <th style="width: 12%;" class="text-end">Trade Price</th>
                    <th style="width: 6%;" class="text-end">Disc.</th>
                    <th style="width: 10%;" class="text-end">Line Net (Rs.)</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $totItemsQty = 0;
                $totDiscount = 0.00;
                $sno = 1;
                foreach ($items as $item):
                    $qty = (float)$item['quantity'];
                    $price = (float)$item['unit_price'];
                    $disc = (float)$item['discount'];
                    $lineNet = (float)$item['line_total'];
                    $totItemsQty += $qty;
                    $totDiscount += $disc;
                ?>
                    <tr>
                        <td class="text-center text-muted py-1"><?= $sno++ ?></td>
                        <td class="py-1"><code><?= htmlspecialchars($item['product_code']) ?></code></td>
                        <td class="py-1">
                            <span class="fw-bold text-dark"><?= htmlspecialchars($item['product_name']) ?></span>
                            <?php if (!empty($item['barcode'])): ?>
                                <small class="text-muted d-inline ms-1">(<?= htmlspecialchars($item['barcode']) ?>)</small>
                            <?php endif; ?>
                        </td>
                        <td class="text-center py-1"><?= htmlspecialchars($item['unit_name']) ?></td>
                        <td class="num-cell fw-bold text-dark text-center py-1"><?= number_format($qty, 0) ?></td>
                        <td class="num-cell text-end py-1"><?= number_format($price, 2) ?></td>
                        <td class="num-cell text-end py-1 text-muted"><?= $disc > 0 ? number_format($disc, 2) : '-' ?></td>
                        <td class="num-cell text-end fw-bold text-dark py-1"><?= number_format($lineNet, 2) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="4" class="fw-bold text-dark py-1">TOTAL INVOICE ITEMS (<?= count($items) ?> Distinct SKUs)</td>
                    <td class="num-cell text-center fw-bold text-dark py-1"><?= number_format($totItemsQty, 0) ?></td>
                    <td colspan="2" class="text-end text-muted py-1">Gross Subtotal:</td>
                    <td class="num-cell text-end fw-bold text-dark py-1"><?= number_format((float)$order['subtotal'], 2) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <!-- Financial Breakdown & Settlement Section (Always Side-by-Side) -->
    <table class="table-layout-grid mb-2" style="width: 100%; table-layout: fixed;">
        <tr>
            <!-- Left: Terms of Supply, Bank Details & Recorded Payments (58%) -->
            <td style="width: 58%; vertical-align: top; padding-right: 8px;">
                <div class="p-2 bg-light rounded border h-100" style="font-size: 0.72rem; line-height: 1.35;">
                    <div class="fw-bold text-dark text-uppercase mb-1" style="font-size: 0.7rem; border-bottom: 1px solid #cbd5e1; padding-bottom: 2px;">
                        PAYMENT & RECOVERY STATUS
                    </div>
                    <?php if (empty($collectedPayments)): ?>
                        <div class="text-muted mb-1">
                            <i class="bi bi-clock-history me-1"></i> No payment received yet. Payment due on delivery.
                        </div>
                    <?php else: ?>
                        <table class="table table-sm table-borderless mb-1 small" style="font-size: 0.72rem;">
                            <thead>
                                <tr class="text-muted border-bottom">
                                    <th class="py-0">Date</th>
                                    <th class="py-0">Mode</th>
                                    <th class="py-0">Receipt #</th>
                                    <th class="text-end py-0">Amount (Rs.)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($collectedPayments as $p): ?>
                                    <tr>
                                        <td class="py-0"><?= formatDate($p['payment_date']) ?></td>
                                        <td class="fw-bold text-uppercase py-0"><?= htmlspecialchars($p['payment_method_code']) ?></td>
                                        <td class="py-0"><code><?= htmlspecialchars($p['payment_number']) ?></code></td>
                                        <td class="text-end fw-bold text-success py-0"><?= number_format((float)$p['amount'], 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>

                    <?php if (!empty($order['notes'])): ?>
                        <div class="text-muted mb-1">
                            <strong>Order Remarks:</strong> <?= htmlspecialchars($order['notes']) ?>
                        </div>
                    <?php endif; ?>

                    <?php
                    $billTerms = getSetting($pdo, 'bill_terms_conditions', 'Goods once sold are non-returnable without prior distributor authorization. Please verify carton seal and pieces count prior to signing receiver acknowledgment.');
                    ?>
                    <div class="text-secondary mt-1 pt-1 border-top" style="font-size: 0.68rem; line-height: 1.3;">
                        <strong>TERMS OF SUPPLY:</strong> <?= htmlspecialchars($billTerms) ?>
                    </div>
                </div>
            </td>

            <!-- Right: Exact Ledger Financial Reconciliation (42%) -->
            <td style="width: 42%; vertical-align: top; padding-left: 8px;">
                <div class="card border rounded shadow-none">
                    <div class="card-body p-1">
                        <table class="bill-totals-table">
                            <tr>
                                <td class="text-muted py-0">Current Order Subtotal:</td>
                                <td class="num-cell text-end fw-semibold text-dark py-0"><?= number_format((float)$order['subtotal'], 2) ?></td>
                            </tr>
                            <?php if ((float)$order['discount_amount'] > 0): ?>
                            <tr>
                                <td class="text-danger py-0">Special Bill Discount:</td>
                                <td class="num-cell text-end text-danger py-0">-(<?= number_format((float)$order['discount_amount'], 2) ?>)</td>
                            </tr>
                            <?php endif; ?>
                            <?php if ((float)$order['tax_amount'] > 0): ?>
                            <tr>
                                <td class="text-muted py-0">Sales Tax / GST:</td>
                                <td class="num-cell text-end py-0"><?= number_format((float)$order['tax_amount'], 2) ?></td>
                            </tr>
                            <?php endif; ?>
                            <?php if ((float)$order['delivery_charges'] > 0): ?>
                            <tr>
                                <td class="text-muted py-0">Delivery / Freight:</td>
                                <td class="num-cell text-end py-0"><?= number_format((float)$order['delivery_charges'], 2) ?></td>
                            </tr>
                            <?php endif; ?>
                            <tr style="border-top: 1px solid #cbd5e1; font-weight: 700;">
                                <td class="text-dark py-1">CURRENT INVOICE TOTAL:</td>
                                <td class="num-cell text-end text-dark py-1"><?= DEFAULT_CURRENCY_SYMBOL ?> <?= number_format($currentBillAmount, 2) ?></td>
                            </tr>
                            <tr>
                                <td class="text-muted py-0">Previous Ledger Balance:</td>
                                <td class="num-cell text-end fw-semibold <?= $priorOutstanding > 0 ? 'text-danger' : 'text-dark' ?> py-0">
                                    <?= number_format($priorOutstanding, 2) ?>
                                </td>
                            </tr>
                            <tr class="grand-total-row">
                                <td class="text-dark py-1">TOTAL DUES PAYABLE:</td>
                                <td class="num-cell text-end text-dark py-1"><?= DEFAULT_CURRENCY_SYMBOL ?> <?= number_format($netTotalPayable, 2) ?></td>
                            </tr>
                            <tr>
                                <td class="text-success fw-bold py-0">Less: Payment Received:</td>
                                <td class="num-cell text-end text-success fw-bold py-0">-(<?= number_format($totalCollectedOnOrder, 2) ?>)</td>
                            </tr>
                            <tr class="bill-due-box">
                                <td class="<?= $balanceDueOnThisBill > 0 ? 'text-danger' : 'text-success' ?> fw-bold py-1">
                                    BALANCE OUTSTANDING:
                                </td>
                                <td class="num-cell text-end <?= $balanceDueOnThisBill > 0 ? 'text-danger' : 'text-success' ?> fw-bold py-1 fs-6">
                                    <?= DEFAULT_CURRENCY_SYMBOL ?> <?= number_format($balanceDueOnThisBill, 2) ?>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
            </td>
        </tr>
    </table>

    <!-- Official Dual Signatures Block (Always 50/50 Side-by-Side) -->
    <table class="table-layout-grid mt-2 pt-2 border-top" style="width: 100%; table-layout: fixed;">
        <tr>
            <td style="width: 50%; vertical-align: top; padding-right: 20px; text-align: center;">
                <div class="bill-sig-box">
                    <div class="fw-bold">For <?= htmlspecialchars($company['name'] ?? APP_NAME) ?></div>
                    <div class="text-muted" style="font-size: 0.68rem;">Authorized Signatory & Distributor Stamp</div>
                </div>
            </td>
            <td style="width: 50%; vertical-align: top; padding-left: 20px; text-align: center;">
                <div class="bill-sig-box">
                    <div class="fw-bold">Customer / Shopkeeper Signature</div>
                    <div class="text-muted" style="font-size: 0.68rem;">Received Goods In Sound Condition & Full Count</div>
                </div>
            </td>
        </tr>
    </table>
</div>

<script>
function shareBillWhatsApp() {
    const shop = "<?= addslashes(htmlspecialchars($order['shop_name'])) ?>";
    const billNo = "<?= addslashes(htmlspecialchars($invoiceNo)) ?>";
    const orderNo = "<?= addslashes(htmlspecialchars($order['order_number'])) ?>";
    const billDate = "<?= formatDate($invoiceDate) ?>";
    const billTotal = "<?= number_format($currentBillAmount, 2) ?>";
    const prevBalance = "<?= number_format($priorOutstanding, 2) ?>";
    const netTotal = "<?= number_format($netTotalPayable, 2) ?>";
    const paid = "<?= number_format($totalCollectedOnOrder, 2) ?>";
    const balanceDue = "<?= number_format($balanceDueOnThisBill, 2) ?>";
    const itemsCount = "<?= count($items) ?>";
    const targetPhone = "<?= htmlspecialchars($order['cust_whatsapp'] ?: $order['cust_phone']) ?>";

    let text = `*Falcon FMCG Distribution - Commercial Sales Invoice*\n`;
    text += `Invoice No: *${billNo}*\n`;
    text += `Order No: ${orderNo} | Date: ${billDate}\n`;
    text += `Customer: *${shop}*\n`;
    text += `Items: ${itemsCount} SKUs\n`;
    text += `------------------------------------\n`;
    text += `Current Invoice Total: *Rs. ${billTotal}*\n`;
    <?php if ($priorOutstanding > 0): ?>
    text += `Previous Balance: Rs. ${prevBalance}\n`;
    text += `Total Dues Payable: Rs. ${netTotal}\n`;
    <?php endif; ?>
    <?php if ($totalCollectedOnOrder > 0): ?>
    text += `Payment Received: Rs. ${paid}\n`;
    <?php endif; ?>
    text += `*Remaining Balance Due: Rs. ${balanceDue}*\n`;
    text += `------------------------------------\n`;
    text += `Thank you for your business!\n`;
    text += `Falcon FMCG Central Distribution Gujranwala`;

    if (typeof window.shareOnWhatsApp === 'function') {
        window.shareOnWhatsApp(targetPhone, text);
    }
}
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
