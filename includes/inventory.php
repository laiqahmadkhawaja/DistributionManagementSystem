<?php
/**
 * Inventory Movement & Stock Engine
 * Enterprise Distribution Management System (DMS)
 */

if (!defined('DMS_INIT')) {
    define('DMS_INIT', true);
}

function getCurrentStock(PDO $pdo, int $warehouseId, int $productId): float {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(quantity_in) - SUM(quantity_out), 0.00)
        FROM `stock_ledger`
        WHERE `warehouse_id` = ? AND `product_id` = ?
    ");
    $stmt->execute([$warehouseId, $productId]);
    return (float)$stmt->fetchColumn();
}

function getReservedStock(PDO $pdo, int $warehouseId, int $productId): float {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(reserved_qty), 0.00)
        FROM `stock_reservations`
        WHERE `warehouse_id` = ? AND `product_id` = ? AND `status` = 'active'
    ");
    $stmt->execute([$warehouseId, $productId]);
    return (float)$stmt->fetchColumn();
}

function getAvailableStock(PDO $pdo, int $warehouseId, int $productId): float {
    $current = getCurrentStock($pdo, $warehouseId, $productId);
    $reserved = getReservedStock($pdo, $warehouseId, $productId);
    return max(0.00, $current - $reserved);
}

function recordStockMovement(
    PDO $pdo,
    int $warehouseId,
    int $productId,
    string $movementType,
    string $refType,
    int $refId,
    int $unitId,
    float $qtyIn,
    float $qtyOut,
    float $unitCost,
    ?int $batchId = null,
    string $notes = ''
): int {
    $current = getCurrentStock($pdo, $warehouseId, $productId);
    $newBalance = $current + $qtyIn - $qtyOut;

    // Check negative stock restriction unless setting allows
    if ($newBalance < 0) {
        $allowNegative = (int)getSetting($pdo, 'allow_negative_stock', '0');
        if (!$allowNegative) {
            $stmt = $pdo->prepare("SELECT name FROM `products` WHERE id = ?");
            $stmt->execute([$productId]);
            $pName = $stmt->fetchColumn() ?: "Product #{$productId}";
            throw new Exception("Insufficient stock for '{$pName}' in selected warehouse. Available: {$current}, Requested Out: {$qtyOut}");
        }
    }

    $totalCost = ($qtyIn > 0) ? round($qtyIn * $unitCost, 2) : round($qtyOut * $unitCost, 2);

    $stmt = $pdo->prepare("
        INSERT INTO `stock_ledger` (
            `warehouse_id`, `product_id`, `batch_id`, `movement_type`, 
            `reference_type`, `reference_id`, `unit_id`, `quantity_in`, 
            `quantity_out`, `balance_quantity`, `unit_cost`, `total_cost`, 
            `notes`, `created_at`
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $warehouseId, $productId, $batchId, $movementType,
        $refType, $refId, $unitId, $qtyIn,
        $qtyOut, $newBalance, $unitCost, $totalCost,
        $notes
    ]);

    $ledgerId = (int)$pdo->lastInsertId();

    // If batch specified, update batch quantity
    if ($batchId) {
        if ($qtyIn > 0) {
            $pdo->prepare("UPDATE `product_batches` SET `quantity` = `quantity` + ? WHERE id = ?")->execute([$qtyIn, $batchId]);
        } elseif ($qtyOut > 0) {
            $pdo->prepare("UPDATE `product_batches` SET `quantity` = GREATEST(0, `quantity` - ?) WHERE id = ?")->execute([$qtyOut, $batchId]);
        }
    }

    // Weighted Average Cost (Moving WAC) recalculation on GRN / Purchase / Inflow
    if ($qtyIn > 0 && $newBalance > 0 && in_array($movementType, ['purchase_grn', 'adjustment_in'])) {
        $oldPriceStmt = $pdo->prepare("SELECT purchase_price, name FROM `products` WHERE id = ?");
        $oldPriceStmt->execute([$productId]);
        $prodRow = $oldPriceStmt->fetch();
        $oldPrice = (float)($prodRow['purchase_price'] ?? 0);

        $priorStock = max(0, $newBalance - $qtyIn);
        $incomingCost = (float)$totalCost;
        if ($incomingCost <= 0 && $unitCost > 0) {
            $incomingCost = $qtyIn * (float)$unitCost;
        }

        if ($priorStock > 0 && $oldPrice > 0) {
            $totalInventoryValue = ($priorStock * $oldPrice) + $incomingCost;
            $newWac = round($totalInventoryValue / $newBalance, 2);
        } else {
            $newWac = $qtyIn > 0 ? round($incomingCost / $qtyIn, 2) : $oldPrice;
        }

        if ($newWac > 0 && abs($newWac - $oldPrice) >= 0.005) {
            $pdo->prepare("UPDATE `products` SET `purchase_price` = ? WHERE id = ?")->execute([$newWac, $productId]);

            // Log WAC recalculation to audit log as requested
            if (function_exists('logAudit')) {
                logAudit($pdo, 'products', $productId, 'wac_cost_update', [
                    'old_purchase_price' => $oldPrice,
                    'prior_stock' => $priorStock,
                    'movement_type' => $movementType,
                    'ref_type' => $refType,
                    'ref_id' => $refId
                ], [
                    'new_purchase_price' => $newWac,
                    'incoming_qty' => $qtyIn,
                    'incoming_total_cost' => $incomingCost,
                    'new_total_stock' => $newBalance,
                    'product_name' => $prodRow['name'] ?? "Product #{$productId}"
                ]);
            }
        }
    }

    return $ledgerId;
}

function reserveStockForOrder(PDO $pdo, int $orderId, int $warehouseId, int $productId, float $qty): void {
    // Release any prior active reservation for this order & product
    $pdo->prepare("
        DELETE FROM `stock_reservations` 
        WHERE `order_id` = ? AND `product_id` = ? AND `status` = 'active'
    ")->execute([$orderId, $productId]);

    $stmt = $pdo->prepare("
        INSERT INTO `stock_reservations` (`order_id`, `product_id`, `warehouse_id`, `reserved_qty`, `status`, `created_at`)
        VALUES (?, ?, ?, ?, 'active', NOW())
    ");
    $stmt->execute([$orderId, $productId, $warehouseId, $qty]);
}

function fulfillStockReservation(PDO $pdo, int $orderId): void {
    $stmt = $pdo->prepare("UPDATE `stock_reservations` SET `status` = 'fulfilled' WHERE `order_id` = ? AND `status` = 'active'");
    $stmt->execute([$orderId]);
}

function releaseStockReservation(PDO $pdo, int $orderId): void {
    $stmt = $pdo->prepare("UPDATE `stock_reservations` SET `status` = 'released' WHERE `order_id` = ? AND `status` = 'active'");
    $stmt->execute([$orderId]);
}

function convertUnitQuantity(PDO $pdo, int $productId, int $fromUnitId, int $toUnitId, float $qty): float {
    if ($fromUnitId === $toUnitId) {
        return $qty;
    }

    // Check direct conversion
    $stmt = $pdo->prepare("
        SELECT multiplier FROM `unit_conversions`
        WHERE (product_id = ? OR product_id IS NULL) AND from_unit_id = ? AND to_unit_id = ?
        ORDER BY product_id DESC LIMIT 1
    ");
    $stmt->execute([$productId, $fromUnitId, $toUnitId]);
    $mult = $stmt->fetchColumn();
    if ($mult !== false) {
        return $qty * (float)$mult;
    }

    // 2. Check reverse conversion
    $stmt->execute([$productId, $toUnitId, $fromUnitId]);
    $revMult = $stmt->fetchColumn();
    if ($revMult !== false && (float)$revMult > 0) {
        return $qty / (float)$revMult;
    }

    // 3. Check 2-step chained conversion (e.g. 1 Carton = 12 Boxes, 1 Box = 24 Pieces => 288 Pieces)
    $allConvs = $pdo->query("SELECT * FROM `unit_conversions` WHERE product_id = {$productId} OR product_id IS NULL ORDER BY product_id DESC")->fetchAll();
    foreach ($allConvs as $c1) {
        if ((int)$c1['from_unit_id'] === $fromUnitId) {
            $midUnitId = (int)$c1['to_unit_id'];
            foreach ($allConvs as $c2) {
                if ((int)$c2['from_unit_id'] === $midUnitId && (int)$c2['to_unit_id'] === $toUnitId) {
                    return $qty * ((float)$c1['multiplier'] * (float)$c2['multiplier']);
                }
            }
        }
    }

    // Fallback: 1-to-1 if undefined
    return $qty;
}
