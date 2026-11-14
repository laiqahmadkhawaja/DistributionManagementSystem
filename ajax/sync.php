<?php
/**
 * Offline Synchronization AJAX Endpoint
 * Enterprise Distribution Management System (DMS)
 */

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__) . '/config/app.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required.']);
    exit;
}

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (!$data || empty($data['sync_uuid']) || empty($data['entity_type']) || empty($data['payload'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid sync payload structure.']);
    exit;
}

$pdo = getDatabaseConnection();
$currentUser = getCurrentUser();
$userId = $currentUser['id'];
$syncUuid = sanitize($data['sync_uuid']);
$entityType = sanitize($data['entity_type']);
$payload = $data['payload'];

// 1. Idempotency Check: Prevent duplicate processing
$stmt = $pdo->prepare("SELECT id, status, server_record_id FROM `offline_sync_queue` WHERE `sync_uuid` = ? LIMIT 1");
$stmt->execute([$syncUuid]);
$existing = $stmt->fetch();

if ($existing && $existing['status'] === 'processed') {
    echo json_encode([
        'success' => true,
        'message' => 'Record was previously synced.',
        'sync_uuid' => $syncUuid,
        'server_record_id' => $existing['server_record_id']
    ]);
    exit;
}

$queueId = null;
if (!$existing) {
    $qStmt = $pdo->prepare("
        INSERT INTO `offline_sync_queue` (`sync_uuid`, `user_id`, `entity_type`, `payload`, `status`, `created_at`)
        VALUES (?, ?, ?, ?, 'pending', NOW())
    ");
    $qStmt->execute([$syncUuid, $userId, $entityType, json_encode($payload)]);
    $queueId = (int)$pdo->lastInsertId();
} else {
    $queueId = $existing['id'];
}

try {
    $pdo->beginTransaction();
    $serverRecordId = null;

    if ($entityType === 'order') {
        // Process Offline Booked Order
        $customerId = (int)($payload['customer_id'] ?? 0);
        $orderBookerId = (int)($payload['order_booker_id'] ?? 0);
        $areaId = (int)($payload['area_id'] ?? 0);
        $orderDate = $payload['order_date'] ?? date('Y-m-d');
        $items = $payload['items'] ?? [];

        if (!$customerId || empty($items)) {
            throw new Exception("Order must contain a valid customer and at least one product item.");
        }

        $orderNumber = generateDocNumber($pdo, 'sales_orders', 'order_number', 'SO-');
        $subtotal = 0.00;
        $discountAmount = (float)($payload['discount_amount'] ?? 0.00);
        $taxAmount = (float)($payload['tax_amount'] ?? 0.00);

        foreach ($items as $item) {
            $qty = (float)$item['quantity'];
            $price = (float)$item['unit_price'];
            $disc = (float)($item['discount'] ?? 0);
            $subtotal += ($qty * $price) - $disc;
        }

        $grandTotal = max(0.00, $subtotal - $discountAmount + $taxAmount);

        $orderStmt = $pdo->prepare("
            INSERT INTO `sales_orders` (
                `order_number`, `customer_id`, `order_booker_id`, `area_id`, 
                `order_date`, `subtotal`, `discount_amount`, `tax_amount`, 
                `grand_total`, `status`, `payment_type`, `sync_id`, `created_at`
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'submitted', 'credit', ?, NOW())
        ");
        $orderStmt->execute([
            $orderNumber, $customerId, $orderBookerId, $areaId,
            $orderDate, $subtotal, $discountAmount, $taxAmount,
            $grandTotal, $syncUuid
        ]);
        $orderId = (int)$pdo->lastInsertId();
        $serverRecordId = $orderId;

        $itemStmt = $pdo->prepare("
            INSERT INTO `sales_order_items` (`sales_order_id`, `product_id`, `unit_id`, `unit_price`, `quantity`, `discount`, `line_total`)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($items as $item) {
            $pId = (int)$item['product_id'];
            $uId = (int)$item['unit_id'];
            $uPrice = (float)$item['unit_price'];
            $qty = (float)$item['quantity'];
            $disc = (float)($item['discount'] ?? 0);
            $lineTot = ($qty * $uPrice) - $disc;
            $itemStmt->execute([$orderId, $pId, $uId, $uPrice, $qty, $disc, $lineTot]);
        }

        // Status history
        $pdo->prepare("INSERT INTO `order_status_history` (`sales_order_id`, `from_status`, `to_status`, `remarks`, `changed_by`, `created_at`) VALUES (?, NULL, 'submitted', 'Synced from field offline queue', ?, NOW())")->execute([$orderId, $userId]);

    } elseif ($entityType === 'delivery_collection') {
        // Process Offline Delivery Completion & Payment Collection
        $assignmentId = (int)($payload['assignment_id'] ?? 0);
        $customerId = (int)($payload['customer_id'] ?? 0);
        $deliveredItems = $payload['items'] ?? [];
        $cashAmount = (float)($payload['cash_amount'] ?? 0.00);
        $digitalAmount = (float)($payload['digital_amount'] ?? 0.00);
        $totalPaid = $cashAmount + $digitalAmount;

        // Fetch assignment
        $assignStmt = $pdo->prepare("SELECT * FROM `delivery_assignments` WHERE id = ? FOR UPDATE");
        $assignStmt->execute([$assignmentId]);
        $assignment = $assignStmt->fetch();

        if (!$assignment) {
            throw new Exception("Delivery assignment #{$assignmentId} not found.");
        }

        // Update items delivered
        $itemUpdate = $pdo->prepare("
            UPDATE `delivery_items` 
            SET `delivered_qty` = ?, `returned_qty` = ?, `return_reason` = ?
            WHERE `delivery_assignment_id` = ? AND `product_id` = ?
        ");

        foreach ($deliveredItems as $dItem) {
            $pId = (int)$dItem['product_id'];
            $delQty = (float)$dItem['delivered_qty'];
            $retQty = (float)($dItem['returned_qty'] ?? 0);
            $reason = $dItem['return_reason'] ?? '';
            $itemUpdate->execute([$delQty, $retQty, $reason, $assignmentId, $pId]);
        }

        // Update assignment status
        $pdo->prepare("UPDATE `delivery_assignments` SET `status` = 'delivered' WHERE id = ?")->execute([$assignmentId]);
        $pdo->prepare("UPDATE `sales_orders` SET `status` = 'delivered' WHERE id = ?")->execute([$assignment['sales_order_id']]);

        // If payment collected, post payment & double-entry journal entry
        if ($totalPaid > 0) {
            $recNumber = generateDocNumber($pdo, 'payments', 'payment_number', 'RCP-');
            $cashAcc = getSystemAccount($pdo, 'cash');
            
            $payStmt = $pdo->prepare("
                INSERT INTO `payments` (
                    `payment_number`, `payment_type`, `reference_type`, `reference_id`, 
                    `party_type`, `party_id`, `amount`, `payment_method_id`, 
                    `payment_method_code`, `payment_account_id`, `payment_date`, 
                    `collected_by`, `handover_status`, `notes`, `status`, `sync_id`, `created_at`
                ) VALUES (?, 'customer_receipt', 'delivery', ?, 'customer', ?, ?, 1, 'cash', ?, ?, ?, 'pending_handover', 'Field collection during delivery (Synced)', 'posted', ?, NOW())
            ");
            $payStmt->execute([
                $recNumber, $assignmentId, $customerId, $totalPaid,
                $cashAcc['id'], date('Y-m-d'), $assignment['delivery_man_id'],
                $syncUuid
            ]);
            $paymentId = (int)$pdo->lastInsertId();

            postCustomerPaymentAccounting($pdo, $paymentId, $recNumber, $customerId, $totalPaid, $cashAcc['id'], $userId);
        }

        $serverRecordId = $assignmentId;

    } elseif ($entityType === 'customer_visit') {
        // Customer Visit Logging
        $customerId = (int)($payload['customer_id'] ?? 0);
        $empId = (int)($payload['employee_id'] ?? 0);
        $notes = sanitize($payload['notes'] ?? '');
        $lat = !empty($payload['lat']) ? (float)$payload['lat'] : null;
        $lng = !empty($payload['lng']) ? (float)$payload['lng'] : null;

        $vStmt = $pdo->prepare("
            INSERT INTO `customer_visits` (`customer_id`, `employee_id`, `visit_date`, `purpose`, `notes`, `gps_lat`, `gps_lng`, `created_at`)
            VALUES (?, ?, ?, 'Sales & Collection Visit', ?, ?, ?, NOW())
        ");
        $vStmt->execute([$customerId, $empId, date('Y-m-d'), $notes, $lat, $lng]);
        $serverRecordId = (int)$pdo->lastInsertId();

    } elseif ($entityType === 'new_shop') {
        // Field registration of new customer shop
        $shopName = sanitize($payload['shop_name'] ?? '');
        $ownerName = sanitize($payload['owner_name'] ?? '');
        $phone = sanitize($payload['phone'] ?? '');
        $address = sanitize($payload['address'] ?? '');
        $areaId = (int)($payload['area_id'] ?? 0);
        $bookerId = (int)($payload['booker_id'] ?? 0);

        if (empty($shopName) || empty($phone) || !$areaId) {
            throw new Exception("Shop name, phone, and area are required.");
        }

        $code = generateDocNumber($pdo, 'customers', 'code', 'CUST-');
        $cStmt = $pdo->prepare("
            INSERT INTO `customers` (`code`, `shop_name`, `owner_name`, `phone`, `address`, `area_id`, `assigned_order_booker_id`, `credit_limit`, `status`, `created_at`)
            VALUES (?, ?, ?, ?, ?, ?, ?, 50000.00, 'active', NOW())
        ");
        $cStmt->execute([$code, $shopName, $ownerName, $phone, $address, $areaId, $bookerId]);
        $serverRecordId = (int)$pdo->lastInsertId();
    }

    // Mark Queue Record as Processed
    $pdo->prepare("UPDATE `offline_sync_queue` SET `status` = 'processed', `server_record_id` = ?, `processed_at` = NOW() WHERE id = ?")->execute([$serverRecordId, $queueId]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'sync_uuid' => $syncUuid,
        'server_record_id' => $serverRecordId,
        'message' => 'Successfully synchronized and posted.'
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $pdo->prepare("UPDATE `offline_sync_queue` SET `status` = 'failed', `error_message` = ? WHERE id = ?")->execute([$e->getMessage(), $queueId]);
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'sync_uuid' => $syncUuid,
        'error' => $e->getMessage()
    ]);
}
