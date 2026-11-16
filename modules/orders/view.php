<?php
/**
 * Sales Order Details & Approval Actions
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('orders', 'view');

$orderId = (int)($_GET['id'] ?? 0);
if (!$orderId) {
    setFlashMessage('error', 'Invalid order ID.');
    redirect(BASE_URL . '/modules/orders/index.php');
}

$pdo = getDatabaseConnection();
$currentUser = getCurrentUser();

// Fetch order details
$stmt = $pdo->prepare("
    SELECT so.*, c.shop_name, c.owner_name, c.phone as cust_phone, c.address as cust_address,
           c.current_balance as cust_balance, c.credit_limit as cust_credit_limit,
           e.full_name as booker_name, a.name as area_name, u.full_name as approver_name
    FROM `sales_orders` so
    JOIN `customers` c ON so.customer_id = c.id
    JOIN `employees` e ON so.order_booker_id = e.id
    JOIN `areas` a ON so.area_id = a.id
    LEFT JOIN `users` u ON so.approved_by = u.id
    WHERE so.id = ?
");
$stmt->execute([$orderId]);
$order = $stmt->fetch();

if (!$order) {
    setFlashMessage('error', 'Sales order not found.');
    redirect(BASE_URL . '/modules/orders/index.php');
}

// Fetch line items
$itemsStmt = $pdo->prepare("
    SELECT soi.*, p.name as product_name, p.code as product_code, p.base_unit_id, u.name as unit_name
    FROM `sales_order_items` soi
    JOIN `products` p ON soi.product_id = p.id
    JOIN `units` u ON soi.unit_id = u.id
    WHERE soi.sales_order_id = ?
");
$itemsStmt->execute([$orderId]);
$items = $itemsStmt->fetchAll();

// Fetch status history
$histStmt = $pdo->prepare("
    SELECT osh.*, u.full_name as user_name
    FROM `order_status_history` osh
    LEFT JOIN `users` u ON osh.changed_by = u.id
    WHERE osh.sales_order_id = ?
    ORDER BY osh.id ASC
");
$histStmt->execute([$orderId]);
$history = $histStmt->fetchAll();

// Process POST State Changes (Approve, Reject, Cancel) BEFORE HTML output
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = sanitize($_POST['order_action'] ?? '');
    $remarks = sanitize($_POST['remarks'] ?? '');

    try {
        $pdo->beginTransaction();

        if ($action === 'approve') {
            requirePermission('orders', 'approve');

            // Stock Reservation logic: Reserve physical base stock in default warehouse
            $defaultWarehouseId = 1;
            foreach ($items as $item) {
                $baseUnitId = (int)($item['base_unit_id'] ?: $item['unit_id']);
                $physicalReservedQty = convertUnitQuantity($pdo, (int)$item['product_id'], (int)$item['unit_id'], $baseUnitId, (float)$item['quantity']);
                reserveStockForOrder($pdo, $orderId, $defaultWarehouseId, (int)$item['product_id'], $physicalReservedQty);
            }

            $upd = $pdo->prepare("
                UPDATE `sales_orders` 
                SET `status` = 'approved', `approved_by` = ?, `approved_at` = NOW() 
                WHERE id = ?
            ");
            $upd->execute([$currentUser['id'], $orderId]);

            $pdo->prepare("
                INSERT INTO `order_status_history` (`sales_order_id`, `from_status`, `to_status`, `remarks`, `changed_by`, `created_at`)
                VALUES (?, ?, 'approved', ?, ?, NOW())
            ")->execute([$orderId, $order['status'], $remarks ?: 'Order approved by management', $currentUser['id']]);

            logAudit($pdo, 'orders', $orderId, 'approve', ['old_status' => $order['status']], ['status' => 'approved']);
            $pdo->commit();

            setFlashMessage('success', "Order {$order['order_number']} approved successfully. Ready for picking & dispatch.");
            redirect(BASE_URL . '/modules/orders/view.php?id=' . $orderId);

        } elseif ($action === 'reject') {
            requirePermission('orders', 'approve');

            $upd = $pdo->prepare("UPDATE `sales_orders` SET `status` = 'rejected' WHERE id = ?");
            $upd->execute([$orderId]);

            releaseStockReservation($pdo, $orderId);

            $pdo->prepare("
                INSERT INTO `order_status_history` (`sales_order_id`, `from_status`, `to_status`, `remarks`, `changed_by`, `created_at`)
                VALUES (?, ?, 'rejected', ?, ?, NOW())
            ")->execute([$orderId, $order['status'], $remarks ?: 'Order rejected by management', $currentUser['id']]);

            logAudit($pdo, 'orders', $orderId, 'reject', ['old_status' => $order['status']], ['status' => 'rejected']);
            $pdo->commit();

            setFlashMessage('info', "Order {$order['order_number']} marked as rejected.");
            redirect(BASE_URL . '/modules/orders/view.php?id=' . $orderId);

        } elseif ($action === 'cancel') {
            requirePermission('orders', 'cancel');

            $upd = $pdo->prepare("UPDATE `sales_orders` SET `status` = 'cancelled' WHERE id = ?");
            $upd->execute([$orderId]);

            releaseStockReservation($pdo, $orderId);

            $pdo->prepare("
                INSERT INTO `order_status_history` (`sales_order_id`, `from_status`, `to_status`, `remarks`, `changed_by`, `created_at`)
                VALUES (?, ?, 'cancelled', ?, ?, NOW())
            ")->execute([$orderId, $order['status'], $remarks ?: 'Order cancelled', $currentUser['id']]);

            logAudit($pdo, 'orders', $orderId, 'cancel', ['old_status' => $order['status']], ['status' => 'cancelled']);
            $pdo->commit();

            setFlashMessage('info', "Order {$order['order_number']} has been cancelled.");
            redirect(BASE_URL . '/modules/orders/view.php?id=' . $orderId);
        }

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        setFlashMessage('error', 'Action failed: ' . $e->getMessage());
        redirect(BASE_URL . '/modules/orders/view.php?id=' . $orderId);
    }
}

$pageTitle = 'Order ' . $order['order_number'];
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-3 gap-2">
    <div>
        <div class="d-flex align-items-center gap-2">
            <h4 class="fw-bold mb-0 text-dark"><?= htmlspecialchars($order['order_number']) ?></h4>
            <?php
            $badge = match ($order['status']) {
                'submitted', 'pending_approval' => 'bg-warning text-dark',
                'approved' => 'bg-info text-dark',
                'dispatched' => 'bg-primary',
                'delivered' => 'bg-success',
                'cancelled', 'rejected' => 'bg-danger',
                default => 'bg-secondary'
            };
            ?>
            <span class="badge badge-status <?= $badge ?>"><?= htmlspecialchars(str_replace('_', ' ', $order['status'])) ?></span>
        </div>
        <small class="text-muted">Booked on <?= formatDate($order['order_date']) ?> by <?= htmlspecialchars($order['booker_name']) ?></small>
    </div>
    <div class="d-flex flex-wrap gap-2 d-print-none">
        <a href="<?= BASE_URL ?>/modules/orders/bill.php?id=<?= $order['id'] ?>" class="btn btn-sm btn-success">
            <i class="bi bi-receipt me-1"></i> View Bill / Invoice
        </a>
        <button type="button" class="btn btn-sm btn-primary" onclick="window.print();">
            <i class="bi bi-printer me-1"></i> Print Order
        </button>
        <button type="button" class="btn btn-sm btn-whatsapp" onclick="shareOrderWhatsApp();">
            <i class="bi bi-whatsapp me-1"></i> WhatsApp
        </button>
        <button type="button" class="btn btn-sm btn-outline-dark" onclick="captureAndShareElement('orderPrintArea', '<?= $order['order_number'] ?>');">
            <i class="bi bi-image me-1"></i> Download Image
        </button>
        <?php if (in_array($order['status'], ['dispatched', 'delivered', 'partially_delivered'])): ?>
            <a href="<?= BASE_URL ?>/modules/warehouse/gatepass.php?order_id=<?= $order['id'] ?>" class="btn btn-sm btn-outline-primary">
                <i class="bi bi-shield-check me-1"></i> View Gate Pass
            </a>
        <?php endif; ?>
        <?php if ($order['status'] === 'approved'): ?>
            <a href="<?= BASE_URL ?>/modules/warehouse/dispatch.php?order_id=<?= $order['id'] ?>" class="btn btn-sm btn-success">
                <i class="bi bi-box-seam me-1"></i> Dispatch & Delivery Assign
            </a>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/modules/orders/index.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back
        </a>
    </div>
</div>

<div id="orderPrintArea">
<div class="row g-3">
    <!-- Left Column: Customer & Order Items -->
    <div class="col-lg-8">
        <!-- Customer Details Box -->
        <div class="card border-0 shadow-sm rounded-3 mb-3">
            <div class="card-body p-3">
                <div class="row g-2">
                    <div class="col-md-6">
                        <div class="text-muted small">Customer / Shop Name</div>
                        <h6 class="fw-bold mb-0 text-dark"><?= htmlspecialchars($order['shop_name']) ?></h6>
                        <small class="text-muted"><?= htmlspecialchars($order['owner_name']) ?> · <?= htmlspecialchars($order['cust_phone']) ?></small>
                        <div class="small text-secondary mt-1"><i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($order['cust_address']) ?> (<?= htmlspecialchars($order['area_name']) ?>)</div>
                    </div>
                    <div class="col-md-6 border-start-md">
                        <div class="row g-2">
                            <div class="col-6">
                                <div class="text-muted small">Current Ledger Balance</div>
                                <div class="fw-bold text-danger"><?= formatMoney($order['cust_balance']) ?></div>
                            </div>
                            <div class="col-6">
                                <div class="text-muted small">Credit Limit</div>
                                <div class="fw-bold text-dark"><?= formatMoney($order['cust_credit_limit']) ?></div>
                            </div>
                            <div class="col-12 mt-2">
                                <a href="<?= BASE_URL ?>/modules/customers/ledger.php?id=<?= $order['customer_id'] ?>" class="btn btn-sm btn-outline-secondary w-100">
                                    <i class="bi bi-journal-text me-1"></i> View Shop Ledger
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Line Items Table -->
        <div class="card border-0 shadow-sm rounded-3 mb-3">
            <div class="card-header bg-white py-3 border-0">
                <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-list-check me-2 text-primary"></i>Ordered Products</h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-bordered table-responsive-card align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Product Details</th>
                                <th class="text-center">Unit</th>
                                <th class="text-center">Quantity</th>
                                <th class="text-end">Rate</th>
                                <th class="text-end">Discount</th>
                                <th class="text-end">Line Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $item): ?>
                                <tr>
                                    <td data-label="Product">
                                        <div class="fw-bold text-dark"><?= htmlspecialchars($item['product_name']) ?></div>
                                        <small class="text-muted"><?= htmlspecialchars($item['product_code']) ?></small>
                                    </td>
                                    <td data-label="Unit" class="text-center"><?= htmlspecialchars($item['unit_name']) ?></td>
                                    <td data-label="Qty" class="text-center fw-bold"><?= (float)$item['quantity'] ?></td>
                                    <td data-label="Rate" class="text-end"><?= formatMoney($item['unit_price']) ?></td>
                                    <td data-label="Discount" class="text-end"><?= formatMoney($item['discount']) ?></td>
                                    <td data-label="Total" class="text-end fw-bold text-dark"><?= formatMoney($item['line_total']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Financial Totals Summary -->
                <div class="p-3 bg-light border-top">
                    <div class="row justify-content-end">
                        <div class="col-md-6 col-lg-5">
                            <div class="d-flex justify-content-between mb-1">
                                <span class="text-muted small">Subtotal:</span>
                                <span class="fw-semibold"><?= formatMoney($order['subtotal']) ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-1">
                                <span class="text-muted small">Discount:</span>
                                <span class="text-danger">-<?= formatMoney($order['discount_amount']) ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-1">
                                <span class="text-muted small">Tax:</span>
                                <span class="text-secondary"><?= formatMoney($order['tax_amount']) ?></span>
                            </div>
                            <hr class="my-1">
                            <div class="d-flex justify-content-between">
                                <span class="fw-bold text-dark">Grand Total:</span>
                                <span class="fw-bold text-primary fs-5"><?= formatMoney($order['grand_total']) ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!empty($order['notes'])): ?>
            <div class="card border-0 shadow-sm rounded-3 mb-3">
                <div class="card-body p-3">
                    <div class="text-muted small fw-semibold">Order Remarks:</div>
                    <p class="mb-0 text-dark"><?= nl2br(htmlspecialchars($order['notes'])) ?></p>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Right Column: Status Timeline & Action Panel -->
    <div class="col-lg-4">
        <!-- Order Actions Card (Inline Confirmation UI - NO MODALS) -->
        <?php if (in_array($order['status'], ['submitted', 'pending_approval'])): ?>
            <div class="card border-0 shadow-sm rounded-3 mb-3">
                <div class="card-header bg-white py-3 border-0">
                    <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-shield-check me-2 text-primary"></i>Management Actions</h6>
                </div>
                <div class="card-body p-3">
                    <p class="small text-muted mb-3">Please review credit limit and inventory before approving this order.</p>

                    <div class="d-grid gap-2">
                        <button type="button" class="btn btn-success" data-confirm-trigger data-confirm-target="panel-approve">
                            <i class="bi bi-check-circle me-1"></i> Approve Order
                        </button>
                        <button type="button" class="btn btn-outline-danger" data-confirm-trigger data-confirm-target="panel-reject">
                            <i class="bi bi-x-circle me-1"></i> Reject Order
                        </button>
                    </div>

                    <!-- Inline Confirm Panel: Approve (Strict No-Modal Alternative) -->
                    <div id="panel-approve" class="inline-confirm-panel d-none mt-3">
                        <h6 class="fw-bold text-success mb-2"><i class="bi bi-question-circle me-1"></i>Confirm Approval?</h6>
                        <p class="small text-muted mb-2">Approving will automatically reserve stock and mark this order ready for warehouse dispatch.</p>
                        <form method="POST" action="<?= BASE_URL ?>/modules/orders/view.php?id=<?= $order['id'] ?>">
                            <?= getCsrfField() ?>
                            <input type="hidden" name="order_action" value="approve">
                            <div class="mb-2">
                                <input type="text" class="form-control form-control-sm" name="remarks" placeholder="Optional approval remarks...">
                            </div>
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-sm btn-success flex-grow-1">Yes, Approve Now</button>
                                <button type="button" class="btn btn-sm btn-secondary" data-confirm-cancel data-confirm-target="panel-approve">Cancel</button>
                            </div>
                        </form>
                    </div>

                    <!-- Inline Confirm Panel: Reject -->
                    <div id="panel-reject" class="inline-confirm-panel d-none mt-3">
                        <h6 class="fw-bold text-danger mb-2"><i class="bi bi-exclamation-triangle me-1"></i>Confirm Rejection?</h6>
                        <form method="POST" action="<?= BASE_URL ?>/modules/orders/view.php?id=<?= $order['id'] ?>">
                            <?= getCsrfField() ?>
                            <input type="hidden" name="order_action" value="reject">
                            <div class="mb-2">
                                <input type="text" class="form-control form-control-sm" name="remarks" placeholder="Reason for rejection (e.g. credit limit exceeded)" required>
                            </div>
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-sm btn-danger flex-grow-1">Reject Order</button>
                                <button type="button" class="btn btn-sm btn-secondary" data-confirm-cancel data-confirm-target="panel-reject">Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Order Audit Timeline -->
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-header bg-white py-3 border-0">
                <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-clock-history me-2 text-primary"></i>Audit Timeline</h6>
            </div>
            <div class="card-body p-3">
                <div class="timeline">
                    <?php foreach ($history as $h): ?>
                        <div class="mb-3 border-start ps-3 position-relative" style="border-left: 2px solid #0d6efd !important;">
                            <div class="fw-bold small text-dark"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $h['to_status']))) ?></div>
                            <div class="text-muted" style="font-size: 0.72rem;">
                                <?= formatDateTime($h['created_at']) ?>
                                <?php if (!empty($h['user_name'])): ?>
                                    · by <?= htmlspecialchars($h['user_name']) ?>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($h['remarks'])): ?>
                                <small class="text-secondary d-block mt-1"><?= htmlspecialchars($h['remarks']) ?></small>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>
</div><!-- /#orderPrintArea -->

<script>
function shareOrderWhatsApp() {
    const shop = "<?= addslashes(htmlspecialchars($order['shop_name'])) ?>";
    const orderNo = "<?= addslashes(htmlspecialchars($order['order_number'])) ?>";
    const orderDate = "<?= formatDate($order['order_date']) ?>";
    const total = "<?= number_format((float)$order['grand_total'], 2) ?>";
    const itemsCount = "<?= count($items) ?>";
    const status = "<?= htmlspecialchars(str_replace('_', ' ', $order['status'])) ?>";
    const targetPhone = "<?= htmlspecialchars($order['cust_whatsapp'] ?? $order['cust_phone']) ?>";

    let text = `*Falcon FMCG Distribution - Sales Order Confirmation*\n`;
    text += `Order No: *${orderNo}*\n`;
    text += `Date: ${orderDate}\n`;
    text += `Customer: *${shop}*\n`;
    text += `Total Items: ${itemsCount} products\n`;
    text += `*Grand Total: Rs. ${total}*\n`;
    text += `Status: ${status.toUpperCase()}\n`;
    text += `------------------------------------\n`;
    text += `Thank you for your business!`;

    if (typeof window.shareOnWhatsApp === 'function') {
        window.shareOnWhatsApp(targetPhone, text);
    }
}
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
