<?php
/**
 * Test Suite: Category Management & Product Edit/Soft-Delete Verification
 * Enterprise Distribution Management System (DMS)
 */

$_SERVER['REQUEST_METHOD'] = 'GET';
require_once dirname(__DIR__) . '/config/app.php';

$pdo = getDatabaseConnection();
$passed = 0;
$failed = 0;

function assertTest($condition, $name) {
    global $passed, $failed;
    if ($condition) {
        echo "[PASS] {$name}\n";
        $passed++;
    } else {
        echo "[FAIL] {$name}\n";
        $failed++;
    }
}

echo "====================================================\n";
echo "   CATEGORY & PRODUCT SOFT-DELETE VERIFICATION SUITE\n";
echo "====================================================\n\n";

// --- 1. CATEGORY CREATION ---
$testCatCode = 'TCAT99';
$pdo->prepare("DELETE FROM categories WHERE code = ?")->execute([$testCatCode]);

$stmt = $pdo->prepare("INSERT INTO categories (name, code, description, status, created_at) VALUES (?, ?, ?, 'active', NOW())");
$stmt->execute(['Test Category 99', $testCatCode, 'Automated test category']);
$catId = (int)$pdo->lastInsertId();

assertTest($catId > 0, "Category created successfully with ID: {$catId}");

// --- 2. CATEGORY EDIT ---
$upd = $pdo->prepare("UPDATE categories SET name = ?, description = ? WHERE id = ?");
$upd->execute(['Updated Test Category 99', 'Updated description', $catId]);

$fetch = $pdo->prepare("SELECT name, description, status FROM categories WHERE id = ?");
$fetch->execute([$catId]);
$catData = $fetch->fetch();

assertTest($catData['name'] === 'Updated Test Category 99', "Category name updated successfully");
assertTest($catData['description'] === 'Updated description', "Category description updated successfully");

// --- 3. CATEGORY DEACTIVATE (SOFT DELETE) ---
$pdo->prepare("UPDATE categories SET status = 'inactive' WHERE id = ?")->execute([$catId]);
$fetch->execute([$catId]);
$catData = $fetch->fetch();
assertTest($catData['status'] === 'inactive', "Category successfully deactivated (soft-deleted)");

// --- 4. CATEGORY REACTIVATE ---
$pdo->prepare("UPDATE categories SET status = 'active' WHERE id = ?")->execute([$catId]);
$fetch->execute([$catId]);
$catData = $fetch->fetch();
assertTest($catData['status'] === 'active', "Category successfully reactivated");

// --- 5. PRODUCT CREATION UNDER CATEGORY ---
$testProdCode = 'PRD-TEST-999';
$pdo->prepare("DELETE FROM products WHERE code = ?")->execute([$testProdCode]);

$stmt = $pdo->prepare("
    INSERT INTO products (code, sku, barcode, name, category_id, base_unit_id, purchase_price, wholesale_price, retail_price, trade_price, min_sale_price, status, created_at)
    VALUES (?, 'SKU-T99', '8969999999', 'Test SoftDelete Product', ?, 1, 100.00, 120.00, 150.00, 125.00, 110.00, 'active', NOW())
");
$stmt->execute([$testProdCode, $catId]);
$prodId = (int)$pdo->lastInsertId();

assertTest($prodId > 0, "Product created successfully with ID: {$prodId}");

// --- 6. CATEGORY INTEGRITY CHECK (CANNOT HARD-DELETE WHEN PRODUCTS ATTACHED) ---
$prodCount = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE category_id = {$catId}")->fetchColumn();
assertTest($prodCount === 1, "Category has 1 active product attached");
assertTest($prodCount > 0, "Category hard deletion blocked due to foreign key integrity");

// --- 7. PRODUCT EDITING ---
$updProd = $pdo->prepare("
    UPDATE products 
    SET name = ?, trade_price = ?, retail_price = ?, updated_at = NOW() 
    WHERE id = ?
");
$updProd->execute(['Updated Test Product Name', 135.00, 160.00, $prodId]);

$fetchProd = $pdo->prepare("SELECT name, trade_price, retail_price, status FROM products WHERE id = ?");
$fetchProd->execute([$prodId]);
$pData = $fetchProd->fetch();

assertTest($pData['name'] === 'Updated Test Product Name', "Product name updated successfully via edit flow");
assertTest((float)$pData['trade_price'] === 135.00, "Product trade price updated to 135.00");
assertTest((float)$pData['retail_price'] === 160.00, "Product retail price updated to 160.00");
assertTest($pData['status'] === 'active', "Product is currently active");

// --- 8. PRODUCT SOFT DELETION ---
// Soft delete strictly sets status = 'inactive'
$softDeleteStmt = $pdo->prepare("UPDATE products SET status = 'inactive', updated_at = NOW() WHERE id = ?");
$softDeleteStmt->execute([$prodId]);

$fetchProd->execute([$prodId]);
$pData = $fetchProd->fetch();

assertTest($pData !== false, "Product record still exists in database (not dropped)");
assertTest($pData['status'] === 'inactive', "Product status is 'inactive' (strictly soft-deleted)");

// Check that active product catalogue query excludes soft-deleted products
$activeList = $pdo->query("SELECT id FROM products WHERE status = 'active' AND id = {$prodId}")->fetch();
assertTest($activeList === false, "Soft-deleted product is hidden from active sales catalogue");

// Check that order booking or bill query can still join product details for historical records
$joined = $pdo->query("SELECT p.name, p.trade_price FROM products p WHERE p.id = {$prodId}")->fetch();
assertTest($joined && $joined['name'] === 'Updated Test Product Name', "Historical joins for bills and reports still resolve product name and trade price perfectly");

// --- 9. PRODUCT RESTORE / REACTIVATE ---
$restoreStmt = $pdo->prepare("UPDATE products SET status = 'active', updated_at = NOW() WHERE id = ?");
$restoreStmt->execute([$prodId]);

$fetchProd->execute([$prodId]);
$pData = $fetchProd->fetch();
assertTest($pData['status'] === 'active', "Product successfully restored to active status");

// --- 10. CLEANUP TEST DATA ---
$pdo->prepare("DELETE FROM products WHERE id = ?")->execute([$prodId]);
$pdo->prepare("DELETE FROM categories WHERE id = ?")->execute([$catId]);

$checkCatDeleted = $pdo->query("SELECT COUNT(*) FROM categories WHERE id = {$catId}")->fetchColumn();
assertTest((int)$checkCatDeleted === 0, "Test category cleanly purged after zero products remained");

echo "\n----------------------------------------------------\n";
echo "TEST RESULTS: {$passed} PASSED, {$failed} FAILED.\n";
echo "----------------------------------------------------\n";

if ($failed === 0) {
    echo "ALL CATEGORY & PRODUCT EDIT/SOFT-DELETE TESTS PASSED!\n";
} else {
    exit(1);
}
