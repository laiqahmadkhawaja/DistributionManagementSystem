<?php
/**
 * Real-Time Field Activity & Live Counters Poller
 * Enterprise Distribution Management System (DMS)
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

require_once dirname(__DIR__) . '/config/app.php';

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'logged_in' => false]);
    exit;
}

$pdo = getDatabaseConnection();
$currentUser = getCurrentUser();
$roleSlug = $currentUser['role_slug'] ?? '';

// 1. Gather Section & Item Counters
$counts = [
    'pending_orders' => 0,
    'ready_dispatch' => 0,
    'pending_deliveries' => 0,
    'pending_returns' => 0,
    'quarantine_resell' => 0,
    'pending_handovers' => 0
];

try {
    $counts['pending_orders'] = (int)$pdo->query("SELECT COUNT(*) FROM `sales_orders` WHERE `status` IN ('submitted', 'pending_approval')")->fetchColumn();
    $counts['ready_dispatch'] = (int)$pdo->query("SELECT COUNT(*) FROM `sales_orders` WHERE `status` = 'approved'")->fetchColumn();
    $counts['pending_deliveries'] = (int)$pdo->query("SELECT COUNT(*) FROM `delivery_assignments` WHERE `status` IN ('assigned', 'in_transit')")->fetchColumn();
    $counts['pending_returns'] = (int)$pdo->query("SELECT COUNT(*) FROM `sales_returns` WHERE `handover_status` = 'in_vehicle_custody'")->fetchColumn();
    $counts['quarantine_resell'] = (int)$pdo->query("SELECT COUNT(*) FROM `sales_returns` WHERE `handover_status` = 'handed_over_to_warehouse'")->fetchColumn();
    $counts['pending_handovers'] = (int)$pdo->query("SELECT COUNT(*) FROM `cash_handovers` WHERE `status` = 'pending'")->fetchColumn();
} catch (Exception $e) {
    // Fail silently on query errors
}

$sectionCounts = [
    'secSales' => $counts['pending_orders'] + $counts['pending_deliveries'] + $counts['pending_returns'],
    'secWarehouse' => $counts['ready_dispatch'] + $counts['quarantine_resell'],
    'secFinance' => $counts['pending_handovers']
];

// 2. Fetch Latest Field Worker Activities
$activities = [];

// Recent Orders
try {
    $qOrders = $pdo->query("
        SELECT 
            CONCAT('order_', so.id) as act_id,
            'order' as act_type,
            CONCAT('Order #', so.order_number) as title,
            CONCAT(COALESCE(e.full_name, 'Field Booker'), ' booked Rs. ', FORMAT(so.grand_total, 2), ' for ', c.shop_name) as description,
            'secSales' as section,
            UNIX_TIMESTAMP(so.created_at) as ts
        FROM sales_orders so
        JOIN customers c ON so.customer_id = c.id
        LEFT JOIN employees e ON so.order_booker_id = e.id
        ORDER BY so.id DESC LIMIT 4
    ")->fetchAll();
    if ($qOrders) {
        $activities = array_merge($activities, $qOrders);
    }
} catch (Exception $e) {}

// Recent Deliveries
try {
    $qDeliv = $pdo->query("
        SELECT 
            CONCAT('deliv_', da.id, '_', da.status) as act_id,
            'delivery' as act_type,
            CONCAT('Delivery #', da.dispatch_number) as title,
            CONCAT(COALESCE(e.full_name, 'Delivery Driver'), ' updated delivery for ', c.shop_name, ' (', REPLACE(da.status, '_', ' '), ')') as description,
            'secSales' as section,
            UNIX_TIMESTAMP(da.created_at) as ts
        FROM delivery_assignments da
        JOIN sales_orders so ON da.sales_order_id = so.id
        JOIN customers c ON so.customer_id = c.id
        LEFT JOIN employees e ON da.delivery_man_id = e.id
        ORDER BY da.id DESC LIMIT 4
    ")->fetchAll();
    if ($qDeliv) {
        $activities = array_merge($activities, $qDeliv);
    }
} catch (Exception $e) {}

// Recent Payments / Collections
try {
    $qPayments = $pdo->query("
        SELECT 
            CONCAT('payment_', p.id) as act_id,
            'payment' as act_type,
            CONCAT('Payment #', p.payment_number) as title,
            CONCAT(COALESCE(e.full_name, 'Collector'), ' collected Rs. ', FORMAT(p.amount, 2), ' from ', COALESCE(c.shop_name, 'Customer')) as description,
            'secFinance' as section,
            UNIX_TIMESTAMP(p.created_at) as ts
        FROM payments p
        LEFT JOIN customers c ON p.party_type = 'customer' AND p.party_id = c.id
        LEFT JOIN employees e ON p.collected_by = e.id
        ORDER BY p.id DESC LIMIT 4
    ")->fetchAll();
    if ($qPayments) {
        $activities = array_merge($activities, $qPayments);
    }
} catch (Exception $e) {}

// Recent Returns
try {
    $qReturns = $pdo->query("
        SELECT 
            CONCAT('return_', sr.id) as act_id,
            'return' as act_type,
            CONCAT('Shop Return #', sr.return_number) as title,
            CONCAT(COALESCE(e.full_name, 'Driver'), ' recorded return for ', c.shop_name, ' (Rs. ', FORMAT(sr.total_amount, 2), ')') as description,
            'secSales' as section,
            UNIX_TIMESTAMP(sr.created_at) as ts
        FROM sales_returns sr
        JOIN customers c ON sr.customer_id = c.id
        LEFT JOIN employees e ON sr.delivery_man_id = e.id
        ORDER BY sr.id DESC LIMIT 4
    ")->fetchAll();
    if ($qReturns) {
        $activities = array_merge($activities, $qReturns);
    }
} catch (Exception $e) {}

// Recent Cash Handovers
try {
    $qHandovers = $pdo->query("
        SELECT 
            CONCAT('handover_', ch.id) as act_id,
            'handover' as act_type,
            CONCAT('Cash Handover #', ch.handover_number) as title,
            CONCAT(COALESCE(e.full_name, 'Driver'), ' submitted cash handover of Rs. ', FORMAT(ch.cash_deposited, 2)) as description,
            'secFinance' as section,
            UNIX_TIMESTAMP(ch.created_at) as ts
        FROM cash_handovers ch
        LEFT JOIN employees e ON ch.delivery_man_id = e.id
        ORDER BY ch.id DESC LIMIT 4
    ")->fetchAll();
    if ($qHandovers) {
        $activities = array_merge($activities, $qHandovers);
    }
} catch (Exception $e) {}

// Sort activities descending by timestamp
usort($activities, fn($a, $b) => $b['ts'] - $a['ts']);

$latestActivity = !empty($activities) ? $activities[0] : null;

// Generate State Hash
$hashString = json_encode($counts) . '_' . ($latestActivity['act_id'] ?? 'none') . '_' . ($latestActivity['ts'] ?? 0);
$hash = md5($hashString);

echo json_encode([
    'success' => true,
    'logged_in' => true,
    'hash' => $hash,
    'counts' => $counts,
    'section_counts' => $sectionCounts,
    'latest_activity' => $latestActivity,
    'recent_activities' => array_slice($activities, 0, 8),
    'timestamp' => time()
]);
