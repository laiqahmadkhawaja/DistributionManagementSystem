<?php
/**
 * Test Suite: Multi-Tier Unit Sales & Stock Conversion (Box to Piece)
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/includes/inventory.php';
require_once dirname(__DIR__) . '/includes/accounting.php';

$pdo = getDatabaseConnection();

echo "====================================================\n";
echo "   FALCON DMS - UNIT CONVERSION & SALES VERIFICATION\n";
echo "====================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertTest($condition, $message) {
    global $passCount, $failCount;
    if ($condition) {
        echo "[PASS] " . $message . "\n";
        $passCount++;
    } else {
        echo "[FAIL] " . $message . "\n";
        $failCount++;
    }
}

// 1. Check unit multiplier: 1 Box = 24 Pieces
$prodId = 3; // Sooper Biscuit
$pieceUnitId = 1;
$boxUnitId = 2;
$cartonUnitId = 3;

$boxToPiece = convertUnitQuantity($pdo, $prodId, $boxUnitId, $pieceUnitId, 1.0);
assertTest($boxToPiece == 24.0, "1 Box of biscuits correctly converts to 24 Pieces");

$cartonToPiece = convertUnitQuantity($pdo, $prodId, $cartonUnitId, $pieceUnitId, 1.0);
assertTest($cartonToPiece == 288.0, "1 Carton of biscuits correctly converts to 288 Pieces (12 boxes * 24 pcs)");

$pieceToBox = convertUnitQuantity($pdo, $prodId, $pieceUnitId, $boxUnitId, 24.0);
assertTest($pieceToBox == 1.0, "24 Pieces correctly convert to 1 Box");

// 2. Check stock inflow when receiving 2 Boxes
$whId = 1;
$initStock = getCurrentStock($pdo, $whId, $prodId);

$boxesReceived = 2.0;
$physicalPcsIn = convertUnitQuantity($pdo, $prodId, $boxUnitId, $pieceUnitId, $boxesReceived);
assertTest($physicalPcsIn == 48.0, "Receiving 2 Boxes equals 48 physical pieces inflow");

$pdo->beginTransaction();
recordStockMovement(
    $pdo,
    $whId,
    $prodId,
    'purchase_grn',
    'goods_receipt',
    99999,
    $pieceUnitId,
    $physicalPcsIn,
    0.00,
    3.54, // unit cost per piece
    null,
    "Test stock inflow for 2 boxes"
);
$stockAfterInflow = getCurrentStock($pdo, $whId, $prodId);
assertTest($stockAfterInflow == ($initStock + 48.0), "Warehouse inventory increased by 48 pieces after receiving 2 boxes");

// 3. Selling loose 5 Pieces
$pcsSold = 5.0;
recordStockMovement(
    $pdo,
    $whId,
    $prodId,
    'sale_dispatch',
    'delivery_assignment',
    99999,
    $pieceUnitId,
    0.00,
    $pcsSold,
    3.54,
    null,
    "Test dispatch for 5 loose pieces"
);
$stockAfterLoose = getCurrentStock($pdo, $whId, $prodId);
assertTest($stockAfterLoose == ($initStock + 43.0), "Selling 5 loose pieces leaves 43 pieces in stock (1 full box of 24 + 19 loose pieces)");

// 4. Selling 1 full Box
$boxSold = 1.0;
$pcsDeductedForBox = convertUnitQuantity($pdo, $prodId, $boxUnitId, $pieceUnitId, $boxSold);
recordStockMovement(
    $pdo,
    $whId,
    $prodId,
    'sale_dispatch',
    'delivery_assignment',
    99999,
    $pieceUnitId,
    0.00,
    $pcsDeductedForBox,
    3.54,
    null,
    "Test dispatch for 1 box (24 pieces)"
);
$finalStock = getCurrentStock($pdo, $whId, $prodId);
assertTest($finalStock == ($initStock + 19.0), "Selling 1 full box (24 pcs) leaves exactly 19 loose pieces in stock");

// Rollback test movements
$pdo->rollBack();

echo "\n----------------------------------------------------\n";
echo "TEST RESULTS: {$passCount} PASSED, {$failCount} FAILED.\n";
echo "----------------------------------------------------\n";

if ($failCount === 0) {
    echo "ALL UNIT CONVERSION & SALES TESTS PASSED 100%!\n";
} else {
    exit(1);
}
