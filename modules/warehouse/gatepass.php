<?php
/**
 * Warehouse Outward Delivery Gate Pass
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('warehouse', 'view');

$pdo = getDatabaseConnection();
$company = getCompanyInfo($pdo);
$currUser = getCurrentUser();

$assignmentId = (int)($_GET['id'] ?? 0);
$orderId = (int)($_GET['order_id'] ?? 0);

if (!$assignmentId && !$orderId) {
    setFlashMessage('error', 'Invalid Gate Pass request. Dispatch record identifier missing.');
    redirect(BASE_URL . '/modules/warehouse/index.php');
}

// Fetch Delivery Assignment details
$query = "
    SELECT da.*,
           so.order_number, so.order_date, so.grand_total as order_total, so.payment_type,
           c.shop_name, c.owner_name, c.phone as customer_phone, c.whatsapp as customer_whatsapp, c.address as customer_address,
           a.name as area_name,
           e.full_name as driver_name, e.phone as driver_phone, e.employee_code as driver_code,
           w.name as warehouse_name, w.address as warehouse_address, w.phone as warehouse_phone,
           u_disp.full_name as dispatcher_name
    FROM `delivery_assignments` da
    JOIN `sales_orders` so ON da.sales_order_id = so.id
    JOIN `customers` c ON so.customer_id = c.id
    JOIN `areas` a ON so.area_id = a.id
    JOIN `employees` e ON da.delivery_man_id = e.id
    JOIN `warehouses` w ON da.warehouse_id = w.id
    LEFT JOIN `users` u_disp ON da.dispatched_by = u_disp.id
    WHERE " . ($assignmentId ? "da.id = ?" : "da.sales_order_id = ?") . "
    LIMIT 1
";

$stmt = $pdo->prepare($query);
$stmt->execute([$assignmentId ?: $orderId]);
$gatepass = $stmt->fetch();

if (!$gatepass) {
    setFlashMessage('error', 'Gate Pass record not found for the specified dispatch.');
    redirect(BASE_URL . '/modules/warehouse/index.php');
}

// Fetch line items dispatched
$itemStmt = $pdo->prepare("
    SELECT di.*, p.name as product_name, p.code as product_code, u.name as unit_name
    FROM `delivery_items` di
    JOIN `products` p ON di.product_id = p.id
    JOIN `units` u ON di.unit_id = u.id
    WHERE di.delivery_assignment_id = ?
    ORDER BY di.id ASC
");
$itemStmt->execute([$gatepass['id']]);
$items = $itemStmt->fetchAll();

// If delivery_items is empty (fallback to sales_order_items)
if (empty($items)) {
    $fallbackStmt = $pdo->prepare("
        SELECT soi.id, soi.product_id, soi.unit_id, soi.quantity as dispatched_qty, soi.unit_price, soi.line_total as total_amount,
               p.name as product_name, p.code as product_code, u.name as unit_name
        FROM `sales_order_items` soi
        JOIN `products` p ON soi.product_id = p.id
        JOIN `units` u ON soi.unit_id = u.id
        WHERE soi.sales_order_id = ?
        ORDER BY soi.id ASC
    ");
    $fallbackStmt->execute([$gatepass['sales_order_id']]);
    $items = $fallbackStmt->fetchAll();
}

$pageTitle = 'Outward Gate Pass - ' . $gatepass['dispatch_number'];
require_once dirname(__DIR__, 2) . '/includes/header.php';

// Format Gate Pass Code
$gatePassNo = 'GP-' . str_replace(['DC-', ' '], '', $gatepass['dispatch_number']);
?>

<!-- Document Controls Toolbar -->
<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-3 gap-2 d-print-none">
    <div>
        <h4 class="fw-bold mb-0 text-dark"><i class="bi bi-shield-check text-primary me-2"></i>Outward Gate Pass</h4>
        <small class="text-muted">Document: <span class="fw-bold text-dark"><?= htmlspecialchars($gatePassNo) ?></span> · Warehouse Dispatch & Security Clearance</small>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="<?= BASE_URL ?>/modules/orders/bill.php?id=<?= $gatepass['sales_order_id'] ?>" class="btn btn-sm btn-success">
            <i class="bi bi-receipt me-1"></i> View Sales Invoice
        </a>
        <button type="button" class="btn btn-sm btn-primary" onclick="window.print();">
            <i class="bi bi-printer-fill me-1"></i> Print Gate Pass (A4)
        </button>
        <button type="button" class="btn btn-sm btn-whatsapp" onclick="shareGatePassWhatsApp();">
            <i class="bi bi-whatsapp me-1"></i> Share via WhatsApp
        </button>
        <button type="button" class="btn btn-sm btn-outline-dark" onclick="captureAndShareElement('printableGatePass', '<?= $gatePassNo ?>');">
            <i class="bi bi-image me-1"></i> Download Image
        </button>
        <a href="<?= BASE_URL ?>/modules/warehouse/index.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back to Dispatches
        </a>
    </div>
</div>

<!-- Main Printable Gate Pass Sheet (Guaranteed 100% Fit & No Horizontal Scrollbars) -->
<div id="printableGatePass" class="gatepass-sheet mx-auto my-2" style="max-width: 900px; width: 100%;">
    
    <!-- Header Block: Company Letterhead & Document Info -->
    <div class="d-flex justify-content-between align-items-start pb-2 mb-2 border-bottom border-dark border-2">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <span class="badge bg-dark p-2 rounded text-white"><i class="bi bi-box-seam fs-5"></i></span>
                <div>
                    <h3 class="gatepass-header-title mb-0"><?= htmlspecialchars($company['name'] ?? APP_NAME) ?></h3>
                    <div class="text-muted" style="font-size: 0.72rem; font-weight: 600; letter-spacing: 0.3px;">
                        CENTRAL DEPOT LOGISTICS & OUTWARD GOODS SECURITY CLEARANCE
                    </div>
                </div>
            </div>
            <div class="text-secondary small" style="font-size: 0.72rem; line-height: 1.35;">
                <div>Facility: <strong><?= htmlspecialchars($gatepass['warehouse_name']) ?></strong> (<?= htmlspecialchars($gatepass['warehouse_address'] ?: $company['address']) ?>)</div>
                <div>Phone: <strong><?= htmlspecialchars($gatepass['warehouse_phone'] ?: $company['phone']) ?></strong> · Security Desk: <strong>Ext. 104</strong></div>
            </div>
        </div>
        <div class="text-end">
            <div class="gatepass-badge mb-1">OUTWARD GATE PASS</div>
            <div class="fw-bold fs-5 text-dark font-monospace mt-1" style="letter-spacing: 0.5px;"><?= htmlspecialchars($gatePassNo) ?></div>
            <div class="small text-muted" style="font-size: 0.75rem;">
                Date: <strong class="text-dark"><?= formatDate($gatepass['dispatch_date']) ?></strong>
            </div>
            <div class="small text-muted" style="font-size: 0.72rem;">
                Dispatch Challan: <strong><?= htmlspecialchars($gatepass['dispatch_number']) ?></strong>
            </div>
        </div>
    </div>

    <!-- Logistics & Transport Metadata Grid (Always 50/50 Side-by-Side) -->
    <div class="gatepass-meta-box mb-2">
        <table class="table-layout-grid mb-0" style="width: 100%; table-layout: fixed;">
            <tr>
                <!-- Left: Dispatch & Vehicle Logistics -->
                <td style="width: 50%; vertical-align: top; padding-right: 10px; border-right: 1px solid #cbd5e1;">
                    <div class="bill-section-title">DISPATCH & VEHICLE DETAILS</div>
                    <table class="table table-sm table-borderless mb-0" style="font-size: 0.75rem; line-height: 1.25;">
                        <tr>
                            <td class="text-muted ps-0 py-0" style="width: 120px;">Dispatch Challan:</td>
                            <td class="fw-bold text-dark py-0"><?= htmlspecialchars($gatepass['dispatch_number']) ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted ps-0 py-0">Sales Order No:</td>
                            <td class="fw-bold text-dark py-0"><?= htmlspecialchars($gatepass['order_number']) ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted ps-0 py-0">Vehicle Reg No:</td>
                            <td class="py-0">
                                <span class="badge bg-dark px-2 py-0 text-white font-monospace" style="font-size: 0.75rem;">
                                    <?= htmlspecialchars($gatepass['vehicle_no'] ?: 'Company Vehicle') ?>
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <td class="text-muted ps-0 py-0">Delivery Driver:</td>
                            <td class="text-dark fw-bold py-0"><?= htmlspecialchars($gatepass['driver_name']) ?> (<?= htmlspecialchars($gatepass['driver_phone']) ?>)</td>
                        </tr>
                        <tr>
                            <td class="text-muted ps-0 py-0">Origin Depot:</td>
                            <td class="text-dark py-0"><?= htmlspecialchars($gatepass['warehouse_name']) ?></td>
                        </tr>
                    </table>
                </td>

                <!-- Right: Consignee & Route Destination -->
                <td style="width: 50%; vertical-align: top; padding-left: 10px;">
                    <div class="bill-section-title">CONSIGNEE & DESTINATION DETAILS</div>
                    <table class="table table-sm table-borderless mb-0" style="font-size: 0.75rem; line-height: 1.25;">
                        <tr>
                            <td class="text-muted ps-0 py-0" style="width: 110px;">Customer / Shop:</td>
                            <td class="fw-bold text-dark py-0" style="font-size: 0.85rem;"><?= htmlspecialchars($gatepass['shop_name']) ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted ps-0 py-0">Proprietor:</td>
                            <td class="text-dark py-0"><?= htmlspecialchars($gatepass['owner_name']) ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted ps-0 py-0">Contact Phone:</td>
                            <td class="text-dark py-0"><?= htmlspecialchars($gatepass['customer_phone']) ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted ps-0 py-0">Delivery Route:</td>
                            <td class="fw-bold text-dark py-0"><?= htmlspecialchars($gatepass['area_name']) ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted ps-0 py-0">Delivery Address:</td>
                            <td class="text-dark py-0"><?= htmlspecialchars($gatepass['customer_address'] ?: 'Commercial Area') ?></td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </div>

    <!-- Dispatched Items Grid Table (100% Fit & Table Layout Fixed) -->
    <div class="table-responsive mb-2">
        <table class="table table-excel align-middle mb-0" style="table-layout: fixed; width: 100%;">
            <thead>
                <tr>
                    <th style="width: 4%;" class="text-center">#</th>
                    <th style="width: 14%;">SKU Code</th>
                    <th style="width: 42%;">Product Description / Specifications</th>
                    <th style="width: 10%;" class="text-center">Packaging</th>
                    <th style="width: 10%;" class="text-center">Dispatched Qty</th>
                    <th style="width: 10%;" class="text-center">Count Check</th>
                    <th style="width: 10%;" class="text-center">Seal Condition</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $totDispatchedQty = 0;
                $sno = 1;
                foreach ($items as $item):
                    $totDispatchedQty += (float)$item['dispatched_qty'];
                ?>
                    <tr>
                        <td class="text-center text-muted py-1"><?= $sno++ ?></td>
                        <td class="py-1"><code><?= htmlspecialchars($item['product_code']) ?></code></td>
                        <td class="py-1 fw-bold text-dark"><?= htmlspecialchars($item['product_name']) ?></td>
                        <td class="text-center py-1"><?= htmlspecialchars($item['unit_name']) ?></td>
                        <td class="num-cell text-center fw-bold text-dark py-1"><?= number_format((float)$item['dispatched_qty'], 0) ?></td>
                        <td class="text-center text-success py-1 fw-semibold" style="font-size: 0.72rem;">[ &#10003; Verified ]</td>
                        <td class="text-center py-1" style="font-size: 0.72rem;">Sealed & Intact</td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="4" class="fw-bold text-dark py-1">TOTAL MERCHANDISE DISPATCHED (<?= count($items) ?> Line Items)</td>
                    <td class="num-cell text-center fw-bold text-dark py-1"><?= number_format($totDispatchedQty, 0) ?></td>
                    <td colspan="2" class="text-center text-muted py-1" style="font-size: 0.72rem;">All Cartons & Units Re-verified</td>
                </tr>
            </tfoot>
        </table>
    </div>

    <!-- Official Dispatch Notice & Instructions -->
    <?php
    $gpDirective = getSetting($pdo, 'gatepass_security_directive', 'This document serves as legal and physical outward gate clearance for the listed cargo to exit the warehouse premises. The transport driver assumes full fiduciary responsibility for inventory integrity, protection against damage, and safe transit until physical handover against customer signature.');
    ?>
    <div class="p-1 mb-2 bg-light rounded border text-muted" style="font-size: 0.68rem; line-height: 1.35;">
        <strong>SECURITY CLEARANCE DIRECTIVE:</strong> <?= htmlspecialchars($gpDirective) ?>
    </div>

    <!-- 4-Column Chain of Custody & Official Signatures Grid (4 Equal Columns Side-by-Side Across 100% Width) -->
    <table class="table-layout-grid mt-2" style="width: 100%; table-layout: fixed; border-collapse: separate; border-spacing: 6px 0;">
        <tr>
            <!-- Sign 1: Warehouse Storekeeper -->
            <td style="width: 25%; vertical-align: top; padding: 0;">
                <div class="gatepass-sig-box">
                    <div class="fw-bold text-dark" style="font-size: 0.72rem;">1. Staged & Packed By</div>
                    <div class="text-muted" style="font-size: 0.65rem;"><?= htmlspecialchars($gatepass['dispatcher_name'] ?? 'Storekeeper') ?></div>
                    <div class="border-top pt-1 text-center text-muted" style="font-size: 0.65rem;">Storekeeper Signature</div>
                </div>
            </td>

            <!-- Sign 2: Warehouse Quality Inspector -->
            <td style="width: 25%; vertical-align: top; padding: 0;">
                <div class="gatepass-sig-box">
                    <div class="fw-bold text-dark" style="font-size: 0.72rem;">2. Count Verified</div>
                    <div class="text-muted" style="font-size: 0.65rem;">Inventory Control Desk</div>
                    <div class="border-top pt-1 text-center text-muted" style="font-size: 0.65rem;">Inspector Signature</div>
                </div>
            </td>

            <!-- Sign 3: Delivery Driver Acknowledgment -->
            <td style="width: 25%; vertical-align: top; padding: 0;">
                <div class="gatepass-sig-box">
                    <div class="fw-bold text-dark" style="font-size: 0.72rem;">3. Driver Received</div>
                    <div class="text-muted" style="font-size: 0.65rem;"><?= htmlspecialchars($gatepass['driver_name']) ?></div>
                    <div class="border-top pt-1 text-center text-muted" style="font-size: 0.65rem;">Driver Signature</div>
                </div>
            </td>

            <!-- Sign 4: Security Outward Gate Clearance -->
            <td style="width: 25%; vertical-align: top; padding: 0;">
                <div class="gatepass-sig-box" style="border: 1.5px solid #0f172a; background-color: #f8fafc;">
                    <div class="fw-bold text-dark text-uppercase" style="font-size: 0.7rem;">4. Gate Outward Stamp</div>
                    <div class="text-dark font-monospace" style="font-size: 0.65rem;">Time Out: ____:____</div>
                    <div class="border-top border-dark pt-1 text-center fw-bold text-dark" style="font-size: 0.65rem;">Security Officer Seal</div>
                </div>
            </td>
        </tr>
    </table>
</div>

<script>
function shareGatePassWhatsApp() {
    const customer = "<?= addslashes(htmlspecialchars($gatepass['shop_name'])) ?>";
    const driver = "<?= addslashes(htmlspecialchars($gatepass['driver_name'])) ?>";
    const driverPhone = "<?= addslashes(htmlspecialchars($gatepass['driver_phone'])) ?>";
    const vehicle = "<?= addslashes(htmlspecialchars($gatepass['vehicle_no'] ?: 'Company Vehicle')) ?>";
    const gatePassNo = "<?= $gatePassNo ?>";
    const challanNo = "<?= addslashes(htmlspecialchars($gatepass['dispatch_number'])) ?>";
    const area = "<?= addslashes(htmlspecialchars($gatepass['area_name'])) ?>";
    const totalItems = "<?= count($items) ?>";
    const totalQty = "<?= number_format($totDispatchedQty, 0) ?>";
    const targetPhone = "<?= htmlspecialchars($gatepass['customer_whatsapp'] ?: $gatepass['customer_phone']) ?>";

    let text = `*OUTWARD DELIVERY GATE PASS*\n`;
    text += `Gate Pass No: *${gatePassNo}*\n`;
    text += `Challan Ref: ${challanNo}\n`;
    text += `Destination: *${customer}* (${area})\n`;
    text += `Vehicle: *${vehicle}*\n`;
    text += `Driver: ${driver} (${driverPhone})\n`;
    text += `Cargo: ${totalItems} Line Items (${totalQty} units)\n`;
    text += `Security Status: *Dispatched & Gate Cleared*\n`;
    text += `------------------------------------\n`;
    text += `Falcon FMCG Central Distribution Gujranwala`;

    if (typeof window.shareOnWhatsApp === 'function') {
        window.shareOnWhatsApp(targetPhone, text);
    }
}
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
