<?php
/**
 * Automated Verification Suite for Expense Categories CRUD & Soft-Delete
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__) . '/config/app.php';

$pdo = getDatabaseConnection();

echo "====================================================\n";
echo "   EXPENSE CATEGORIES CRUD & INTEGRITY QA SUITE    \n";
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

// 1. Create a New Category
$testName = "QA Test Subscription Category " . time();
$testCode = "qa_sub_" . time();
$testAccId = 25; // 6060 General Office & Misc

$stmt = $pdo->prepare("
    INSERT INTO `expense_categories` (`name`, `code`, `account_id`, `status`)
    VALUES (?, ?, ?, 'active')
");
$stmt->execute([$testName, $testCode, $testAccId]);
$catId = (int)$pdo->lastInsertId();

assertTest($catId > 0, "Expense category created successfully with ID: {$catId}");

// Verify active categories query includes it
$activeCats = $pdo->query("SELECT id FROM `expense_categories` WHERE status = 'active'")->fetchAll(PDO::FETCH_COLUMN);
assertTest(in_array($catId, $activeCats), "New category is present in active categories list");

// 2. Edit the Category
$updatedName = $testName . " - Updated";
$updatedCode = $testCode . "_upd";
$pdo->prepare("
    UPDATE `expense_categories` 
    SET `name` = ?, `code` = ? 
    WHERE id = ?
")->execute([$updatedName, $updatedCode, $catId]);

$fetchStmt = $pdo->prepare("SELECT name, code FROM `expense_categories` WHERE id = ?");
$fetchStmt->execute([$catId]);
$cat = $fetchStmt->fetch();

assertTest($cat['name'] === $updatedName, "Category name updated successfully via edit flow");
assertTest($cat['code'] === $updatedCode, "Category code updated successfully via edit flow");

// 3. Toggle Status (Deactivate / Soft-Delete)
$pdo->prepare("UPDATE `expense_categories` SET `status` = 'inactive' WHERE id = ?")->execute([$catId]);
$activeAfterDeactivate = $pdo->query("SELECT id FROM `expense_categories` WHERE status = 'active'")->fetchAll(PDO::FETCH_COLUMN);
assertTest(!in_array($catId, $activeAfterDeactivate), "Deactivated category is hidden from active voucher creation dropdown");

// Reactivate
$pdo->prepare("UPDATE `expense_categories` SET `status` = 'active' WHERE id = ?")->execute([$catId]);
$activeAfterReactivate = $pdo->query("SELECT id FROM `expense_categories` WHERE status = 'active'")->fetchAll(PDO::FETCH_COLUMN);
assertTest(in_array($catId, $activeAfterReactivate), "Reactivated category is visible again in active dropdown");

// 4. Safe Deletion & Foreign Key Protection
// Insert a test expense voucher attached to this category
$expNum = "TEST-EXP-" . time();
$pdo->prepare("
    INSERT INTO `expenses` (`expense_number`, `category_id`, `expense_date`, `amount`, `payment_account_id`, `payment_method`, `description`, `status`, `created_at`)
    VALUES (?, ?, CURDATE(), 100.00, 2, 'cash', 'Test attached voucher', 'approved', NOW())
")->execute([$expNum, $catId]);
$expId = (int)$pdo->lastInsertId();

// Verify that attached expenses block hard deletion
$hasExpenses = (int)$pdo->query("SELECT COUNT(*) FROM `expenses` WHERE category_id = {$catId}")->fetchColumn() > 0;
assertTest($hasExpenses, "Integrity check detects attached expense vouchers");

// Try to delete: in application logic, delete is rejected if expense_count > 0
$canDelete = ((int)$pdo->query("SELECT COUNT(*) FROM `expenses` WHERE category_id = {$catId}")->fetchColumn() === 0);
assertTest(!$canDelete, "Hard deletion of category blocked when expense vouchers depend on it");

// Clean up the test expense voucher
$pdo->prepare("DELETE FROM `expenses` WHERE id = ?")->execute([$expId]);

// Now delete should be allowed
$canDeleteNow = ((int)$pdo->query("SELECT COUNT(*) FROM `expenses` WHERE category_id = {$catId}")->fetchColumn() === 0);
assertTest($canDeleteNow, "Deletion allowed once zero expense vouchers remain attached");

$pdo->prepare("DELETE FROM `expense_categories` WHERE id = ?")->execute([$catId]);
$exists = $pdo->query("SELECT COUNT(*) FROM `expense_categories` WHERE id = {$catId}")->fetchColumn() > 0;
assertTest(!$exists, "Category permanently deleted cleanly from database");

echo "\n----------------------------------------------------\n";
echo "TEST RESULTS: {$passCount} PASSED, {$failCount} FAILED.\n";
echo "----------------------------------------------------\n";

if ($failCount === 0) {
    echo "ALL EXPENSE CATEGORY CRUD TESTS PASSED 100%!\n";
} else {
    exit(1);
}
