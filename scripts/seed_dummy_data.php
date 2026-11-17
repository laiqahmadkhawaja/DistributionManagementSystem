<?php
/**
 * Master Dummy Data Seeder for Falcon FMCG Enterprise DMS 2.0
 * Generates rich, realistic FMCG distribution data with 100% balanced double-entry accounting.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/includes/accounting.php';
require_once dirname(__DIR__) . '/includes/inventory.php';

echo "========================================================\n";
echo "   FALCON FMCG DMS 2.0 - MASTER DUMMY DATA SEEDER       \n";
echo "========================================================\n\n";

$pdo = getDatabaseConnection();

$pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");

$tablesToTruncate = [
    'audit_logs', 'salary_slips', 'payroll_periods', 'stock_ledger',
    'stock_reservations', 'product_batches', 'journal_entry_lines', 'journal_entries',
    'payment_allocations', 'cash_handovers', 'payments', 'expenses',
    'sales_invoice_items', 'sales_invoices', 'delivery_items', 'delivery_assignments',
    'order_status_history', 'sales_order_items', 'sales_orders', 'purchase_returns',
    'goods_receipt_items', 'goods_receipts', 'purchase_items', 'purchase_orders',
    'stock_adjustment_items', 'stock_adjustments', 'stock_transfer_items', 'stock_transfers',
    'stock_handover_items', 'stock_handovers', 'credit_notes', 'customer_visits',
    'customers', 'suppliers', 'products', 'unit_conversions', 'units',
    'brands', 'categories', 'warehouses', 'expense_categories', 'payment_methods',
    'company_accounts', 'chart_of_accounts', 'employee_area_assignments', 'sub_areas',
    'areas', 'employees', 'users', 'role_permissions', 'permissions', 'roles',
    'settings', 'companies'
];

foreach ($tablesToTruncate as $tbl) {
    $pdo->exec("TRUNCATE TABLE `{$tbl}`;");
}

$pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
echo "✓ Cleaned existing tables\n";

// -------------------------------------------------------------
// 1. Company & Settings
// -------------------------------------------------------------
$pdo->exec("
INSERT INTO `companies` (`id`, `name`, `legal_name`, `email`, `phone`, `address`, `city`, `ntn`, `strn`, `currency`, `currency_symbol`, `invoice_prefix`, `purchase_prefix`, `order_prefix`, `delivery_prefix`, `receipt_prefix`, `inventory_valuation`, `status`) VALUES
(1, 'Falcon FMCG Distribution Co.', 'Falcon Enterprise Distribution Pvt Ltd', 'info@falcon-dist.com', '+92 300 1234567', 'Plot 45, Small Industrial Estate', 'Gujranwala', '1234567-8', 'STRN-987654', 'PKR', 'Rs.', 'INV-', 'PO-', 'SO-', 'DC-', 'RCP-', 'WAC', 'active');
");

$settings = [
    ['company_name', 'Falcon FMCG Distribution Co.', 'Business Name'],
    ['company_address', 'Plot 45, Small Industrial Estate, Gujranwala', 'Address'],
    ['company_phone', '+92 300 1234567', 'Phone Number'],
    ['currency', 'PKR', 'Default Currency Code'],
    ['currency_symbol', 'Rs.', 'Default Currency Symbol'],
    ['stock_deduction_point', 'dispatch', 'Stock deduction event (dispatch or delivery)'],
    ['inventory_costing_method', 'WAC', 'Weighted Average Costing'],
    ['default_credit_limit', '60000', 'Default credit limit for new retail shops'],
    ['offline_sync_enabled', '1', 'Allow field workers offline storage and auto-sync'],
    ['procurement_receiving_mode', 'direct_receive', 'Direct stock receive on PO creation for fast procurement'],
    ['procurement_settlement_policy', 'immediate_payment', 'Default payment collection for direct purchases'],
    ['purchase_default_payment_account', '3', 'Default bank account for purchase payments']
];
$stmt = $pdo->prepare("INSERT INTO `settings` (`setting_key`, `setting_value`, `description`) VALUES (?, ?, ?)");
foreach ($settings as $s) {
    $stmt->execute($s);
}
echo "✓ Company & Settings seeded\n";

// -------------------------------------------------------------
// 2. Roles & Permissions
// -------------------------------------------------------------
$pdo->exec("
INSERT INTO `roles` (`id`, `name`, `slug`, `description`, `is_system`) VALUES
(1, 'Super Admin', 'super_admin', 'Full access to all system features, accounts and settings', 1),
(2, 'Admin / General Manager', 'admin', 'Management level access across all operations', 1),
(3, 'Order Booker', 'order_booker', 'Mobile field sales rep: assigned areas, shop registration, order booking', 1),
(4, 'Salesman', 'salesman', 'Direct van salesman: orders, collections and spot distribution', 1),
(5, 'Delivery Man', 'delivery_man', 'Mobile delivery app: deliveries, returns and payment recovery', 1),
(6, 'Warehouse Manager / Storekeeper', 'storekeeper', 'Stock receiving, order picking, packing, dispatch and adjustments', 1),
(7, 'Accountant', 'accountant', 'Double-entry accounting, cash reconciliations, ledgers and P&L', 1);

INSERT INTO `permissions` (`id`, `module`, `action`, `description`) VALUES
(1, 'dashboard', 'view', 'View dashboard statistics and analytics'),
(2, 'suppliers', 'view', 'View suppliers list and ledger'),
(3, 'suppliers', 'create', 'Add new supplier'),
(4, 'suppliers', 'edit', 'Edit supplier details'),
(5, 'suppliers', 'delete', 'Deactivate or struck-off supplier'),
(6, 'products', 'view', 'View product catalogue and prices'),
(7, 'products', 'create', 'Create new product'),
(8, 'products', 'edit', 'Update product and pricing lists'),
(9, 'products', 'delete', 'Deactivate product'),
(10, 'inventory', 'view', 'View warehouse stock and ledger'),
(11, 'inventory', 'adjust', 'Perform stock adjustment (in/out)'),
(12, 'inventory', 'transfer', 'Perform stock transfer between warehouses'),
(13, 'purchases', 'view', 'View purchase orders and GRN'),
(14, 'purchases', 'create', 'Create purchase order or direct GRN'),
(15, 'purchases', 'approve', 'Approve purchase orders'),
(16, 'purchases', 'receive', 'Receive goods (GRN posting)'),
(17, 'purchases', 'return', 'Create purchase return to supplier'),
(18, 'areas', 'view', 'View sales areas and routes'),
(19, 'areas', 'create', 'Create new sales area'),
(20, 'areas', 'edit', 'Update area details'),
(21, 'areas', 'assign', 'Assign areas to order bookers / salesmen'),
(22, 'customers', 'view', 'View customer shops and ledger'),
(23, 'customers', 'create', 'Register new customer shop'),
(24, 'customers', 'edit', 'Edit customer details'),
(25, 'customers', 'delete', 'Struck-off customer'),
(26, 'orders', 'view', 'View sales orders'),
(27, 'orders', 'create', 'Book new sales order'),
(28, 'orders', 'edit', 'Edit submitted sales order'),
(29, 'orders', 'approve', 'Approve or reject sales order'),
(30, 'orders', 'cancel', 'Cancel sales order'),
(31, 'warehouse', 'view', 'View picking lists and orders for dispatch'),
(32, 'warehouse', 'dispatch', 'Dispatch order and generate challan'),
(33, 'delivery', 'view', 'View assigned deliveries'),
(34, 'delivery', 'deliver', 'Confirm delivered quantities and returns'),
(35, 'delivery', 'collect_payment', 'Collect cash/digital recovery from customer'),
(36, 'payments', 'view', 'View payment receipts and vouchers'),
(37, 'payments', 'create', 'Receive or make payment'),
(38, 'payments', 'handover', 'Submit delivery cash handover'),
(39, 'payments', 'reconcile', 'Verify and reconcile cash handover'),
(40, 'returns', 'view', 'View sales returns'),
(41, 'returns', 'create', 'Create customer sales return'),
(42, 'returns', 'approve', 'Approve sales return and stock destination'),
(43, 'expenses', 'view', 'View expenses'),
(44, 'expenses', 'create', 'Record company expense'),
(45, 'expenses', 'approve', 'Approve pending expense'),
(46, 'employees', 'view', 'View staff and employee profiles'),
(47, 'employees', 'create', 'Add new employee'),
(48, 'employees', 'edit', 'Edit employee and salary/commission'),
(49, 'accounting', 'view', 'View chart of accounts and journal entries'),
(50, 'accounting', 'post', 'Post manual journal entries'),
(51, 'reports', 'sales', 'View sales and order booker performance reports'),
(52, 'reports', 'financial', 'View P&L, Trial Balance, Ledgers'),
(53, 'reports', 'inventory', 'View stock movements and valuation'),
(54, 'settings', 'view', 'View system settings'),
(55, 'settings', 'edit', 'Update business settings and configurations'),
(59, 'payroll', 'view', 'View salary slips and payroll periods'),
(60, 'payroll', 'create', 'Generate monthly staff payroll'),
(61, 'payroll', 'disburse', 'Disburse salary slips and post to ledger');

-- Super Admin gets all permissions
INSERT INTO `role_permissions` (`role_id`, `permission_id`) SELECT 1, `id` FROM `permissions`;
-- Admin gets all except delete
INSERT INTO `role_permissions` (`role_id`, `permission_id`) SELECT 2, `id` FROM `permissions` WHERE `action` NOT IN ('delete');
-- Order Booker
INSERT INTO `role_permissions` (`role_id`, `permission_id`) VALUES (3, 1), (3, 6), (3, 18), (3, 22), (3, 23), (3, 24), (3, 26), (3, 27), (3, 28), (3, 51);
-- Delivery Man
INSERT INTO `role_permissions` (`role_id`, `permission_id`) VALUES (5, 1), (5, 22), (5, 26), (5, 33), (5, 34), (5, 35), (5, 36), (5, 38), (5, 40), (5, 41);
-- Storekeeper
INSERT INTO `role_permissions` (`role_id`, `permission_id`) VALUES (6, 1), (6, 6), (6, 10), (6, 11), (6, 12), (6, 13), (6, 16), (6, 26), (6, 31), (6, 32), (6, 40), (6, 53);
-- Accountant
INSERT INTO `role_permissions` (`role_id`, `permission_id`) VALUES (7, 1), (7, 2), (7, 13), (7, 22), (7, 26), (7, 36), (7, 37), (7, 39), (7, 40), (7, 42), (7, 43), (7, 44), (7, 45), (7, 49), (7, 50), (7, 51), (7, 52), (7, 53), (7, 59), (7, 60), (7, 61);
");
echo "✓ Roles & Permissions matrix configured\n";

// -------------------------------------------------------------
// 3. Chart of Accounts & Company Bank Accounts
// -------------------------------------------------------------
$pdo->exec("
INSERT INTO `chart_of_accounts` (`id`, `code`, `name`, `group_type`, `parent_id`, `is_active`, `system_tag`) VALUES
(1, '1000', 'Current Assets', 'Asset', NULL, 1, NULL),
(2, '1010', 'Cash in Hand', 'Asset', 1, 1, 'cash'),
(3, '1020', 'Bank Account (Main - HBL Current)', 'Asset', 1, 1, 'bank'),
(4, '1025', 'Meezan Bank (Islamic Business Account)', 'Asset', 1, 1, 'bank_meezan'),
(5, '1030', 'Accounts Receivable / Customer Balances', 'Asset', 1, 1, 'receivables'),
(6, '1040', 'Merchandise Inventory', 'Asset', 1, 1, 'inventory'),
(7, '2000', 'Current Liabilities', 'Liability', NULL, 1, NULL),
(8, '2010', 'Accounts Payable / Supplier Balances', 'Liability', 7, 1, 'payables'),
(9, '3000', 'Equity', 'Equity', NULL, 1, NULL),
(10, '3010', 'Owner Capital', 'Equity', 9, 1, 'capital'),
(11, '3020', 'Retained Earnings', 'Equity', 9, 1, 'retained_earnings'),
(12, '4000', 'Revenue / Sales', 'Income', NULL, 1, NULL),
(13, '4010', 'Sales Revenue', 'Income', 12, 1, 'sales_revenue'),
(14, '4020', 'Delivery & Handling Income', 'Income', 12, 1, 'delivery_income'),
(15, '4500', 'Contra Revenue', 'Contra_Income', NULL, 1, NULL),
(16, '4510', 'Sales Returns & Damaged Allowances', 'Contra_Income', 15, 1, 'sales_returns'),
(17, '4520', 'Sales Discounts Granted', 'Contra_Income', 15, 1, 'sales_discounts'),
(18, '5000', 'Cost of Sales', 'Cost_Of_Sales', NULL, 1, NULL),
(19, '5010', 'Cost of Goods Sold (COGS)', 'Cost_Of_Sales', 18, 1, 'cogs'),
(20, '6000', 'Operating Expenses', 'Expense', NULL, 1, NULL),
(21, '6010', 'Salaries & Wages', 'Expense', 20, 1, 'salaries'),
(22, '6020', 'Vehicle Fuel & Fleet Maintenance', 'Expense', 20, 1, 'fuel_vehicle'),
(23, '6030', 'Warehouse Rent & Electricity', 'Expense', 20, 1, 'rent_utilities'),
(24, '6040', 'Loading / Unloading Freight Charges', 'Expense', 20, 1, 'freight_loading'),
(25, '6050', 'Sales Commission Expense', 'Expense', 20, 1, 'commission'),
(26, '6060', 'General Office & Miscellaneous Expense', 'Expense', 20, 1, 'misc_expense');

INSERT INTO `payment_methods` (`id`, `name`, `code`, `account_id`, `is_active`) VALUES
(1, 'Cash in Hand', 'cash', 2, 1),
(2, 'HBL Bank Transfer / Cheque', 'bank_transfer', 3, 1),
(3, 'Meezan Bank Transfer', 'meezan_bank', 4, 1),
(4, 'JazzCash Merchant Account', 'jazzcash', 2, 1),
(5, 'Easypaisa Business Wallet', 'easypaisa', 2, 1),
(6, 'Company Cheque', 'cheque', 3, 1);

INSERT INTO `company_accounts` (`id`, `account_type`, `bank_name`, `account_title`, `account_number`, `iban`, `branch_name`, `branch_code`, `raast_id`, `is_primary`, `is_active`, `show_on_bills`, `display_order`) VALUES
(1, 'bank', 'Habib Bank Limited (HBL)', 'Falcon FMCG Distribution Co', '0129-79018234-03', 'PK36HABB0001297901823403', 'Small Industrial Estate Branch, Gujranwala', '0129', '03001234567', 1, 1, 1, 1),
(2, 'bank', 'Meezan Bank Limited', 'Falcon FMCG Distribution Co', '0210-0104892110-01', 'PK89MEZN0002100104892110', 'Model Town Branch, Gujranwala', '0210', '03001234568', 0, 1, 1, 2),
(3, 'wallet', 'JazzCash Corporate Wallet', 'Falcon FMCG Till', '0300-1234567', NULL, 'Digital Banking Gujranwala', 'JC-01', '03001234567', 0, 1, 1, 3);

INSERT INTO `expense_categories` (`id`, `name`, `code`, `account_id`, `status`) VALUES
(1, 'Staff Salaries & Allowances', 'salaries', 21, 'active'),
(2, 'Vehicle Fuel & Fleet Maintenance', 'fuel', 22, 'active'),
(3, 'Warehouse Rent & Electricity (GEPCO)', 'rent', 23, 'active'),
(4, 'Inbound Freight & Labor Loading', 'freight', 24, 'active'),
(5, 'Sales Field Commission', 'commission', 25, 'active'),
(6, 'Office Supplies, Internet & Printing', 'misc', 26, 'active');
");
echo "✓ Chart of Accounts, Bank Accounts, & Expense Categories seeded\n";

// -------------------------------------------------------------
// 4. Warehouses, Areas, Sub-Areas & Employees
// -------------------------------------------------------------
$pdo->exec("
INSERT INTO `warehouses` (`id`, `code`, `name`, `address`, `contact_person`, `phone`, `is_default`, `status`) VALUES
(1, 'WH-MAIN', 'Central Distribution Warehouse', 'Plot 45 Small Industrial Estate, Gujranwala', 'Tariq Mehmood', '+92 300 1122334', 1, 'active'),
(2, 'WH-NORTH', 'North City Transit Hub', 'Rahwali Cantt Road, Gujranwala', 'Babar Ali', '+92 301 4455667', 0, 'active'),
(3, 'WH-VAN-01', 'Van Sub-Inventory (GAW-4821)', 'Mobile Distribution Unit 1', 'Rashid Khan', '+92 300 4444444', 0, 'active');

INSERT INTO `areas` (`id`, `name`, `code`, `city`, `description`, `status`) VALUES
(1, 'Gujranwala Central Market', 'GW-CENT', 'Gujranwala', 'Satellite Town, Model Town, Civil Lines', 'active'),
(2, 'Rahwali Cantt Zone', 'GW-RAH', 'Gujranwala', 'Cantt Area, Rahwali Gate, GT Road', 'active'),
(3, 'Kamoke Commercial Belt', 'GW-KAM', 'Kamoke', 'Kamoke Grain Market, Mandi Road, Tatley Aali', 'active'),
(4, 'Wazirabad City Zone', 'GW-WAZ', 'Wazirabad', 'Railway Road, Kutchery Road, Nizamabad', 'active'),
(5, 'Ghakhar Mandi Zone', 'GW-GHK', 'Ghakhar', 'Main G.T Road, Ghakhar Grain Market', 'active');

INSERT INTO `sub_areas` (`id`, `area_id`, `name`, `code`, `status`) VALUES
(1, 1, 'Satellite Town Block A & B', 'ST-AB', 'active'),
(2, 1, 'Model Town Commercial Market', 'MT-MKT', 'active'),
(3, 1, 'Civil Lines Market', 'CL-MKT', 'active'),
(4, 2, 'Rahwali Main Chowk', 'RAH-CHK', 'active'),
(5, 2, 'Cantt Shopping Centre', 'RAH-CSC', 'active'),
(6, 3, 'Kamoke Grain Market', 'KAM-GM', 'active'),
(7, 3, 'Mandi Road Retail Strip', 'KAM-MR', 'active'),
(8, 4, 'Railway Road Bazaars', 'WAZ-RR', 'active'),
(9, 4, 'Kutchery Road Market', 'WAZ-KR', 'active'),
(10, 5, 'Ghakhar Grain Hub', 'GHK-GH', 'active');

-- Users & Password Hash for 'Password@123':
-- $2y$10$3z7G9yO4R6vYlGSmYvYfvev0xHdQb6T6s1iZ.0Zz5aGqLwFkE6XoO
INSERT INTO `users` (`id`, `role_id`, `username`, `password_hash`, `full_name`, `email`, `phone`, `status`) VALUES
(1, 1, 'admin', '$2y$10$3z7G9yO4R6vYlGSmYvYfvev0xHdQb6T6s1iZ.0Zz5aGqLwFkE6XoO', 'Super Administrator', 'admin@dms.local', '+92 300 1111111', 'active'),
(2, 2, 'manager', '$2y$10$3z7G9yO4R6vYlGSmYvYfvev0xHdQb6T6s1iZ.0Zz5aGqLwFkE6XoO', 'Operations Manager', 'manager@dms.local', '+92 300 2222222', 'active'),
(3, 3, 'booker1', '$2y$10$3z7G9yO4R6vYlGSmYvYfvev0xHdQb6T6s1iZ.0Zz5aGqLwFkE6XoO', 'Zubair Ahmad (Order Booker)', 'booker1@dms.local', '+92 300 3333333', 'active'),
(4, 3, 'booker2', '$2y$10$3z7G9yO4R6vYlGSmYvYfvev0xHdQb6T6s1iZ.0Zz5aGqLwFkE6XoO', 'Hamza Tariq (Field Booker)', 'booker2@dms.local', '+92 300 3333334', 'active'),
(5, 5, 'delivery1', '$2y$10$3z7G9yO4R6vYlGSmYvYfvev0xHdQb6T6s1iZ.0Zz5aGqLwFkE6XoO', 'Rashid Khan (Delivery Staff)', 'delivery1@dms.local', '+92 300 4444444', 'active'),
(6, 6, 'store1', '$2y$10$3z7G9yO4R6vYlGSmYvYfvev0xHdQb6T6s1iZ.0Zz5aGqLwFkE6XoO', 'Tariq Mehmood (Storekeeper)', 'store1@dms.local', '+92 300 5555555', 'active'),
(7, 7, 'accounts1', '$2y$10$3z7G9yO4R6vYlGSmYvYfvev0xHdQb6T6s1iZ.0Zz5aGqLwFkE6XoO', 'Saeed Anwar (Accountant)', 'accounts1@dms.local', '+92 300 6666666', 'active');

INSERT INTO `employees` (`id`, `user_id`, `employee_code`, `full_name`, `phone`, `email`, `designation`, `role_type`, `salary`, `commission_type`, `commission_rate`, `status`) VALUES
(1, 1, 'EMP-001', 'Super Administrator', '+92 300 1111111', 'admin@dms.local', 'Executive Director', 'super_admin', 150000.00, 'none', 0.00, 'active'),
(2, 2, 'EMP-002', 'Operations Manager', '+92 300 2222222', 'manager@dms.local', 'General Manager', 'admin', 90000.00, 'none', 0.00, 'active'),
(3, 3, 'EMP-003', 'Zubair Ahmad', '+92 300 3333333', 'booker1@dms.local', 'Senior Order Booker', 'order_booker', 35000.00, 'percentage_sales', 1.50, 'active'),
(4, 4, 'EMP-004', 'Hamza Tariq', '+92 300 3333334', 'booker2@dms.local', 'Field Sales Rep', 'order_booker', 32000.00, 'percentage_sales', 1.50, 'active'),
(5, 5, 'EMP-005', 'Rashid Khan', '+92 300 4444444', 'delivery1@dms.local', 'Delivery Specialist & Driver', 'delivery_man', 30000.00, 'fixed_per_order', 25.00, 'active'),
(6, 6, 'EMP-006', 'Tariq Mehmood', '+92 300 5555555', 'store1@dms.local', 'Warehouse & Inventory In-charge', 'storekeeper', 40000.00, 'none', 0.00, 'active'),
(7, 7, 'EMP-007', 'Saeed Anwar', '+92 300 6666666', 'accounts1@dms.local', 'Senior Accounts Officer', 'accountant', 55000.00, 'none', 0.00, 'active');

INSERT INTO `employee_area_assignments` (`employee_id`, `area_id`) VALUES
(3, 1), (3, 2), -- Zubair assigned to Central & Rahwali
(4, 3), (4, 4), (4, 5); -- Hamza assigned to Kamoke, Wazirabad & Ghakhar

UPDATE `areas` SET `assigned_order_booker_id` = 3 WHERE `id` IN (1, 2);
UPDATE `areas` SET `assigned_order_booker_id` = 4 WHERE `id` IN (3, 4, 5);
");
echo "✓ Warehouses, Areas, Sub-Areas & Employees seeded\n";

// -------------------------------------------------------------
// 5. Units & Unit Conversions
// -------------------------------------------------------------
$pdo->exec("
INSERT INTO `units` (`id`, `name`, `code`, `is_base`) VALUES
(1, 'Piece', 'pc', 1),
(2, 'Box', 'box', 0),
(3, 'Carton', 'ctn', 0),
(4, 'Dozen', 'doz', 0),
(5, 'Pack', 'pack', 0),
(6, 'Kilogram', 'kg', 1),
(7, 'Liter', 'ltr', 1),
(8, 'Tray', 'tray', 0);

-- Multi-Tier Unit Conversions:
-- 1 Carton = 12 Boxes
-- 1 Box = 24 Pieces (1 Carton = 288 Pieces)
-- 1 Dozen = 12 Pieces
-- 1 Tray = 24 Pieces
-- 1 Pack = 6 Pieces
INSERT INTO `unit_conversions` (`id`, `product_id`, `from_unit_id`, `to_unit_id`, `multiplier`) VALUES
(1, NULL, 3, 2, 12.0000), -- 1 ctn = 12 box
(2, NULL, 2, 1, 24.0000), -- 1 box = 24 pc
(3, NULL, 4, 1, 12.0000), -- 1 doz = 12 pc
(4, NULL, 8, 1, 24.0000), -- 1 tray = 24 pc
(5, NULL, 5, 1, 6.0000);   -- 1 pack = 6 pc
");
echo "✓ Units and multi-tier conversions seeded\n";

// -------------------------------------------------------------
// 6. Categories, Brands & 22 Leading FMCG Products
// -------------------------------------------------------------
$pdo->exec("
INSERT INTO `categories` (`id`, `name`, `code`, `status`) VALUES
(1, 'Dairy & Breakfast', 'DAIRY', 'active'),
(2, 'Beverages & Juices', 'BEV', 'active'),
(3, 'Biscuits & Confectionery', 'CONF', 'active'),
(4, 'Personal Care & Soaps', 'CARE', 'active'),
(5, 'Cooking Oil & Banaspati Ghee', 'OIL', 'active'),
(6, 'Spices, Recipe Mixes & Salts', 'SPICE', 'active'),
(7, 'Laundry, Detergents & Cleaning', 'CLEAN', 'active');

INSERT INTO `brands` (`id`, `name`, `code`, `status`) VALUES
(1, 'Nestle Pakistan', 'NEST', 'active'),
(2, 'English Biscuit Manufacturers (EBM)', 'EBM', 'active'),
(3, 'Unilever Pakistan', 'UNIL', 'active'),
(4, 'Shan Foods', 'SHAN', 'active'),
(5, 'Dalda Foods', 'DALD', 'active'),
(6, 'National Foods', 'NATL', 'active'),
(7, 'Colgate-Palmolive', 'COLG', 'active'),
(8, 'Tapal Tea', 'TAPL', 'active');
");

$products = [
    // [id, code, sku, barcode, name, cat_id, brand_id, base_unit_id, purchase_price, wholesale, retail, trade, min_sale, tax, reorder, is_batch]
    [1, 'PRD-001', 'NES-MILK-1L', '8961011001', 'Nestle MilkPak 1 Liter', 1, 1, 1, 260.00, 275.00, 290.00, 270.00, 265.00, 0.00, 100.00, 1],
    [2, 'PRD-002', 'NES-JUICE-1L', '8961011002', 'Nestle Fruita Vitals Chaunsa 1L', 2, 1, 1, 240.00, 255.00, 275.00, 250.00, 245.00, 0.00, 80.00, 1],
    [3, 'PRD-003', 'NES-CERELAC', '8961011003', 'Nestle Cerelac Wheat 350g', 1, 1, 1, 480.00, 510.00, 550.00, 500.00, 490.00, 0.00, 50.00, 1],
    [4, 'PRD-004', 'NES-MILO-200', '8961011004', 'Nestle Milo Chocolate Drink 200ml', 2, 1, 1, 75.00, 82.00, 95.00, 80.00, 78.00, 0.00, 120.00, 1],
    [5, 'PRD-005', 'EBM-SOOPER-FMLY', '8961022001', 'Sooper Biscuit Family Pack', 3, 2, 1, 80.00, 88.00, 100.00, 85.00, 82.00, 0.00, 150.00, 0],
    [6, 'PRD-006', 'EBM-GLUCO-HALF', '8961022002', 'Gluco Biscuit Half Roll', 3, 2, 1, 35.00, 40.00, 50.00, 38.00, 36.00, 0.00, 200.00, 0],
    [7, 'PRD-007', 'EBM-RIO-STRAW', '8961022003', 'Peek Freans Rio Strawberry Cream', 3, 2, 1, 45.00, 50.00, 60.00, 48.00, 46.00, 0.00, 150.00, 0],
    [8, 'PRD-008', 'EBM-CHOCOLATTO', '8961022004', 'Chocolatto Choco Delight Pack', 3, 2, 1, 60.00, 68.00, 80.00, 65.00, 62.00, 0.00, 80.00, 0],
    [9, 'PRD-009', 'LUX-ROSE-140G', '8961033001', 'Lux Beauty Soap French Rose 140g', 4, 3, 1, 130.00, 142.00, 160.00, 138.00, 135.00, 0.00, 100.00, 0],
    [10, 'PRD-010', 'LIFE-SOAP-115G', '8961033002', 'Lifebuoy Total 10 Antibacterial Soap', 4, 3, 1, 95.00, 105.00, 120.00, 102.00, 98.00, 0.00, 120.00, 0],
    [11, 'PRD-011', 'SUNSILK-SHP-180', '8961033003', 'Sunsilk Black Shine Shampoo 180ml', 4, 3, 1, 280.00, 310.00, 350.00, 300.00, 290.00, 0.00, 60.00, 0],
    [12, 'PRD-012', 'DOVE-BAR-100G', '8961033004', 'Dove Deep Moisture Beauty Bar 100g', 4, 3, 1, 210.00, 230.00, 260.00, 225.00, 218.00, 0.00, 50.00, 0],
    [13, 'PRD-013', 'SURF-EXCEL-1KG', '8961033005', 'Surf Excel Quick Wash Detergent 1kg', 7, 3, 1, 460.00, 500.00, 560.00, 490.00, 475.00, 0.00, 80.00, 0],
    [14, 'PRD-014', 'VIM-BAR-300G', '8961033006', 'Vim Dishwash Lemon Bar 300g', 7, 3, 1, 70.00, 78.00, 90.00, 75.00, 72.00, 0.00, 100.00, 0],
    [15, 'PRD-015', 'SHAN-BIRYANI-50', '8961044001', 'Shan Special Bombay Biryani Masala', 6, 4, 1, 105.00, 115.00, 130.00, 112.00, 108.00, 0.00, 120.00, 0],
    [16, 'PRD-016', 'SHAN-KORMA-50', '8961044002', 'Shan Chicken White Korma Masala', 6, 4, 1, 105.00, 115.00, 130.00, 112.00, 108.00, 0.00, 100.00, 0],
    [17, 'PRD-017', 'NATL-KETCHUP-800', '8961055001', 'National Tomato Ketchup Pouch 800g', 6, 6, 1, 275.00, 300.00, 340.00, 290.00, 280.00, 0.00, 60.00, 1],
    [18, 'PRD-018', 'NATL-SALT-800G', '8961055002', 'National Iodized Pink Salt 800g', 6, 6, 1, 45.00, 50.00, 60.00, 48.00, 46.00, 0.00, 150.00, 0],
    [19, 'PRD-019', 'DALDA-OIL-5LTR', '8961066001', 'Dalda Cooking Oil 5 Liter Tin', 5, 5, 1, 2450.00, 2600.00, 2850.00, 2550.00, 2500.00, 0.00, 30.00, 1],
    [20, 'PRD-020', 'DALDA-GHEE-1KG', '8961066002', 'Dalda Banaspati Ghee Pouch 1kg', 5, 5, 1, 485.00, 515.00, 560.00, 505.00, 495.00, 0.00, 80.00, 1],
    [21, 'PRD-021', 'COLG-TP-100ML', '8961077001', 'Colgate Maximum Cavity Protection 100ml', 4, 7, 1, 160.00, 178.00, 200.00, 172.00, 168.00, 0.00, 80.00, 0],
    [22, 'PRD-022', 'TAPAL-TEA-430G', '8961088001', 'Tapal Danedar Tea Premium Pouch 430g', 2, 8, 1, 620.00, 670.00, 750.00, 655.00, 640.00, 0.00, 60.00, 0]
];

$pStmt = $pdo->prepare("
INSERT INTO `products` (
    `id`, `code`, `sku`, `barcode`, `name`, `category_id`, `brand_id`, 
    `base_unit_id`, `purchase_price`, `wholesale_price`, `retail_price`, 
    `trade_price`, `min_sale_price`, `tax_rate`, `reorder_level`, `is_batch_managed`, `status`
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active');
");
foreach ($products as $pr) {
    $pStmt->execute($pr);
}
echo "✓ 22 Products with multi-tier pricing seeded\n";

// -------------------------------------------------------------
// 7. Suppliers (6 Major FMCG Manufacturers)
// -------------------------------------------------------------
$suppliers = [
    [1, 'SUP-001', 'Nestle Pakistan Ltd', 'Nestle Pakistan Gujranwala Distribution', 'Nadeem Butt', '+92 321 9876543', '+92 321 9876543', 'orders@nestle.com.pk', 'Industrial Area Sheikhupura Road', 'Gujranwala', 600000.00, 'Net 15'],
    [2, 'SUP-002', 'English Biscuit Manufacturers (Pvt) Ltd', 'EBM Distribution Hub', 'Farhan Sheikh', '+92 333 4567890', '+92 333 4567890', 'ebm@dist.pk', 'Plot 12 GT Road', 'Gujranwala', 500000.00, 'Net 30'],
    [3, 'SUP-003', 'Unilever Pakistan Consumer Goods', 'Unilever Distribution Depot', 'Kashif Rauf', '+92 300 7891234', '+92 300 7891234', 'kashif@unilever.pk', 'Cantt Area GT Road', 'Gujranwala', 700000.00, 'Net 15'],
    [4, 'SUP-004', 'Shan Foods (Pvt) Ltd', 'Shan Foods Central Punjab Depot', 'Amjad Parvez', '+92 312 8899112', '+92 312 8899112', 'orders@shanfoods.pk', 'Sialkot Bypass Road', 'Gujranwala', 400000.00, 'Net 20'],
    [5, 'SUP-005', 'Dalda Foods (Pvt) Ltd', 'Dalda Edible Oils & Fats Hub', 'Zahid Hussain', '+92 345 6677889', '+92 345 6677889', 'distribution@dalda.pk', 'Small Industrial Estate 2', 'Gujranwala', 800000.00, 'Net 15'],
    [6, 'SUP-006', 'National Foods Ltd', 'National Foods Regional Hub', 'Mian Shahbaz', '+92 322 5544332', '+92 322 5544332', 'punjab@nfoods.com', 'GT Road Kamoke Bypass', 'Kamoke', 350000.00, 'Net 20']
];
$sStmt = $pdo->prepare("
INSERT INTO `suppliers` (
    `id`, `code`, `name`, `company_name`, `contact_person`, `phone`, `whatsapp`, 
    `email`, `address`, `city`, `opening_balance`, `current_balance`, `credit_limit`, `payment_terms`, `status`
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0.00, 0.00, ?, ?, 'active');
");
foreach ($suppliers as $sup) {
    $sStmt->execute($sup);
}
echo "✓ 6 Suppliers seeded\n";

// -------------------------------------------------------------
// 8. Retail Customers (12 Stores across Gujranwala Division)
// -------------------------------------------------------------
$customers = [
    [1, 'CUST-001', 'Madina Cash & Carry', 'Haji Muhammad Akram', '+92 300 9641234', '+92 300 9641234', 'Shop 12-14, Satellite Town Main Market', 1, 1, 'retailer', 120000.00, '7 Days Credit', 3],
    [2, 'CUST-002', 'Bismillah General Store', 'Chaudhry Arshad', '+92 321 8765432', '+92 321 8765432', 'Gali No. 4, Model Town Market', 1, 2, 'retailer', 80000.00, 'Due on Delivery', 3],
    [3, 'CUST-003', 'Subhan Wholesale Point', 'Malik Tanveer', '+92 334 1122998', '+92 334 1122998', 'GT Road near Rahwali Gate', 2, 4, 'wholesaler', 200000.00, '15 Days Credit', 3],
    [4, 'CUST-004', 'Al-Rehman Super Mart', 'Sheikh Rizwan', '+92 302 3344556', '+92 302 3344556', 'Kamoke Grain Market, Circular Road', 3, 6, 'retailer', 90000.00, 'Due on Delivery', 4],
    [5, 'CUST-005', 'Decent Kiryana Store', 'Imran Ashraf', '+92 313 4455667', '+92 313 4455667', 'Civil Lines Court Road', 1, 3, 'retailer', 50000.00, '7 Days Credit', 3],
    [6, 'CUST-006', 'Ghousia Departmental Store', 'Mian Tariq Mehmood', '+92 301 5566778', '+92 301 5566778', 'Rahwali Cantt Shopping Strip', 2, 5, 'retailer', 85000.00, '7 Days Credit', 3],
    [7, 'CUST-007', 'Al-Madad Mart & Bakers', 'Bilal Gujjar', '+92 344 6677889', '+92 344 6677889', 'Railway Road Bazaar', 4, 8, 'retailer', 75000.00, 'Due on Delivery', 4],
    [8, 'CUST-008', 'City Cash & Carry', 'Usman Butt', '+92 323 7788990', '+92 323 7788990', 'Kutchery Road Main Chowk', 4, 9, 'wholesaler', 150000.00, '15 Days Credit', 4],
    [9, 'CUST-009', 'Gourmet Foods & Grocery', 'Farooq Ahmad', '+92 305 8899001', '+92 305 8899001', 'Block C Model Town', 1, 2, 'retailer', 100000.00, '7 Days Credit', 3],
    [10, 'CUST-010', 'Shaheen General Store', 'Naveed Aslam', '+92 315 9900112', '+92 315 9900112', 'Mandi Road Kamoke', 3, 7, 'retailer', 60000.00, 'Due on Delivery', 4],
    [11, 'CUST-011', 'New Punjab General Store', 'Irfan Ali', '+92 336 1122334', '+92 336 1122334', 'Ghakhar Main G.T Road', 5, 10, 'retailer', 70000.00, '7 Days Credit', 4],
    [12, 'CUST-012', 'Standard Super Store', 'Zeeshan Mughal', '+92 307 2233445', '+92 307 2233445', 'Satellite Town Block D', 1, 1, 'retailer', 110000.00, '10 Days Credit', 3]
];

$cStmt = $pdo->prepare("
INSERT INTO `customers` (
    `id`, `code`, `shop_name`, `owner_name`, `phone`, `whatsapp`, `address`, 
    `area_id`, `sub_area_id`, `customer_type`, `credit_limit`, `opening_balance`, 
    `current_balance`, `payment_terms`, `assigned_order_booker_id`, `status`
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0.00, 0.00, ?, ?, 'active');
");
foreach ($customers as $cust) {
    $cStmt->execute($cust);
}
echo "✓ 12 Retail Shops seeded across 5 zones\n";

// -------------------------------------------------------------
// 9. Initial Opening Inventory & Opening Capital Journal Entry
// -------------------------------------------------------------
// We seed opening inventory across the 22 products with initial batches
$initialStockItems = [
    // [product_id, qty, unit_cost, batch_no, expiry]
    [1, 600, 260.00, 'BATCH-MP-26A', '2027-06-15'],
    [2, 400, 240.00, 'BATCH-FV-26A', '2027-04-10'],
    [3, 200, 480.00, 'BATCH-CR-26A', '2027-08-20'],
    [4, 500, 75.00, 'BATCH-ML-26A', '2027-03-30'],
    [5, 1200, 80.00, 'BATCH-SP-26A', '2027-10-15'],
    [6, 1500, 35.00, 'BATCH-GL-26A', '2027-10-15'],
    [7, 800, 45.00, 'BATCH-RO-26A', '2027-09-10'],
    [8, 600, 60.00, 'BATCH-CH-26A', '2027-09-10'],
    [9, 800, 130.00, 'BATCH-LX-26A', '2028-01-01'],
    [10, 1000, 95.00, 'BATCH-LB-26A', '2028-01-01'],
    [11, 400, 280.00, 'BATCH-SS-26A', '2028-02-15'],
    [12, 350, 210.00, 'BATCH-DV-26A', '2028-03-01'],
    [13, 500, 460.00, 'BATCH-SE-26A', '2028-05-01'],
    [14, 600, 70.00, 'BATCH-VM-26A', '2028-05-01'],
    [15, 700, 105.00, 'BATCH-SB-26A', '2027-12-31'],
    [16, 500, 105.00, 'BATCH-SK-26A', '2027-12-31'],
    [17, 300, 275.00, 'BATCH-NK-26A', '2027-08-15'],
    [18, 800, 45.00, 'BATCH-NS-26A', '2029-01-01'],
    [19, 120, 2450.00, 'BATCH-DO-26A', '2027-11-20'],
    [20, 400, 485.00, 'BATCH-DG-26A', '2027-11-20'],
    [21, 500, 160.00, 'BATCH-CP-26A', '2028-06-30'],
    [22, 300, 620.00, 'BATCH-TT-26A', '2027-12-15']
];

$totalOpeningInventoryCost = 0.00;
foreach ($initialStockItems as $item) {
    $totalOpeningInventoryCost += ($item[1] * $item[2]);
}

$openingCash = 350000.00;
$openingHBL = 850000.00;
$openingMeezan = 600000.00;
$totalOwnerCapital = $openingCash + $openingHBL + $openingMeezan + $totalOpeningInventoryCost;

// Post Opening Balanced Journal Entry
$jeStmt = $pdo->prepare("
INSERT INTO `journal_entries` (`id`, `entry_number`, `entry_date`, `reference_type`, `reference_id`, `narration`, `total_debit`, `total_credit`, `posted_by`, `status`, `created_at`) 
VALUES (1, 'JE-OPENING-001', '2026-11-01', 'opening_entry', 1, 'Initial balance sheet & owner capital setup for Falcon FMCG DMS', ?, ?, 1, 'posted', '2026-11-01 08:00:00');
");
$jeStmt->execute([$totalOwnerCapital, $totalOwnerCapital]);

$linesStmt = $pdo->prepare("INSERT INTO `journal_entry_lines` (`journal_entry_id`, `account_id`, `debit`, `credit`, `memo`) VALUES (?, ?, ?, ?, ?)");
// Debit Cash in Hand (2)
$linesStmt->execute([1, 2, $openingCash, 0.00, 'Opening Cash in Hand']);
// Debit HBL Bank (3)
$linesStmt->execute([1, 3, $openingHBL, 0.00, 'Opening HBL Bank Current Account']);
// Debit Meezan Bank (4)
$linesStmt->execute([1, 4, $openingMeezan, 0.00, 'Opening Meezan Islamic Business Account']);
// Debit Merchandise Inventory (6)
$linesStmt->execute([1, 6, $totalOpeningInventoryCost, 0.00, "Opening Stock Inventory for 22 Products"]);
// Credit Owner Capital (10)
$linesStmt->execute([1, 10, 0.00, $totalOwnerCapital, 'Opening Equity / Owner Capital']);

// Insert stock ledger & batch records
$batchStmt = $pdo->prepare("INSERT INTO `product_batches` (`product_id`, `warehouse_id`, `batch_number`, `expiry_date`, `quantity`, `unit_cost`, `created_at`) VALUES (?, 1, ?, ?, ?, ?, '2026-11-01 08:30:00')");
$slStmt = $pdo->prepare("
INSERT INTO `stock_ledger` (
    `warehouse_id`, `product_id`, `batch_id`, `movement_type`, `reference_type`, 
    `reference_id`, `unit_id`, `quantity_in`, `quantity_out`, `balance_quantity`, 
    `unit_cost`, `total_cost`, `notes`, `created_at`
) VALUES (1, ?, ?, 'adjustment_in', 'opening_balance', 1, 1, ?, 0.00, ?, ?, ?, 'Opening stock load', '2026-11-01 08:30:00');
");

foreach ($initialStockItems as $stk) {
    $batchStmt->execute([$stk[0], $stk[3], $stk[4], $stk[1], $stk[2]]);
    $batchId = (int)$pdo->lastInsertId();
    $tot = $stk[1] * $stk[2];
    $slStmt->execute([$stk[0], $batchId, $stk[1], $stk[1], $stk[2], $tot]);
}

echo "✓ Opening balance sheet posted (Owner Capital: Rs. " . number_format($totalOwnerCapital, 2) . ", Inventory: Rs. " . number_format($totalOpeningInventoryCost, 2) . ")\n";

// -------------------------------------------------------------
// 10. Operational Purchases & Goods Receipts (GRN) with Payments
// -------------------------------------------------------------
// Purchase 1: From Nestle Pakistan (MilkPak & Fruita Vitals)
// PO-202611020001, GRN-202611020001
$po1Date = '2026-11-02';
$po1Total = (200 * 260.00) + (150 * 240.00); // 52,000 + 36,000 = 88,000
$pdo->exec("
INSERT INTO `purchase_orders` (`id`, `po_number`, `supplier_id`, `warehouse_id`, `po_date`, `expected_date`, `reference_no`, `subtotal`, `grand_total`, `status`, `notes`, `created_by`, `created_at`) 
VALUES (1, 'PO-202611020001', 1, 1, '{$po1Date}', '2026-11-05', 'REF-NES-91', {$po1Total}, {$po1Total}, 'received', 'Regular stock replenishment for dairy & juices', 1, '{$po1Date} 09:00:00');

INSERT INTO `purchase_items` (`purchase_order_id`, `product_id`, `unit_id`, `order_qty`, `received_qty`, `unit_cost`, `total_cost`) VALUES
(1, 1, 1, 200.00, 200.00, 260.00, 52000.00),
(1, 2, 1, 150.00, 150.00, 240.00, 36000.00);

INSERT INTO `goods_receipts` (`id`, `grn_number`, `purchase_order_id`, `supplier_id`, `warehouse_id`, `grn_date`, `invoice_no`, `invoice_date`, `total_amount`, `freight_cost`, `landed_cost`, `notes`, `posted_to_accounts`, `created_by`, `created_at`)
VALUES (1, 'GRN-202611020001', 1, 1, 1, '{$po1Date}', 'NES-INV-8491', '{$po1Date}', {$po1Total}, 0.00, {$po1Total}, 'Received intact at Central Warehouse', 1, 6, '{$po1Date} 14:00:00');

INSERT INTO `goods_receipt_items` (`goods_receipt_id`, `product_id`, `batch_number`, `expiry_date`, `unit_id`, `received_qty`, `unit_cost`, `total_cost`) VALUES
(1, 1, 'BATCH-MP-26B', '2027-07-20', 1, 200.00, 260.00, 52000.00),
(1, 2, 'BATCH-FV-26B', '2027-05-15', 1, 150.00, 240.00, 36000.00);
");

// Update stock ledger for GRN 1
$batchStmt->execute([1, 'BATCH-MP-26B', '2027-07-20', 200.00, 260.00]);
$b1 = (int)$pdo->lastInsertId();
$slStmt->execute([1, $b1, 200.00, 800.00, 260.00, 52000.00]);

$batchStmt->execute([2, 'BATCH-FV-26B', '2027-05-15', 150.00, 240.00]);
$b2 = (int)$pdo->lastInsertId();
$slStmt->execute([2, $b2, 150.00, 550.00, 240.00, 36000.00]);

// Post GRN 1 Accounting: Debit Inventory (6), Credit Accounts Payable Supplier (8)
postGoodsReceiptAccounting($pdo, 1, 'GRN-202611020001', 1, $po1Total, 1);

// Supplier Payment 1: Disburse Rs. 50,000 from Meezan Bank (Account 4) to Nestle
$pdo->exec("
INSERT INTO `payments` (`id`, `payment_number`, `payment_type`, `reference_type`, `reference_id`, `party_type`, `party_id`, `amount`, `payment_method_id`, `payment_method_code`, `payment_account_id`, `payment_date`, `reference_no`, `notes`, `status`, `created_by`, `created_at`)
VALUES (1, 'PV-202611030001', 'supplier_payment', 'supplier_disbursement', 1, 'supplier', 1, 50000.00, 3, 'meezan_bank', 4, '2026-11-03', 'TRF-MEEZAN-99', 'Partial payment against GRN-202611020001', 'posted', 1, '2026-11-03 11:00:00');
");
postSupplierPaymentAccounting($pdo, 1, 'PV-202611030001', 1, 50000.00, 4, 1);

// Purchase 2: From EBM (Sooper & Chocolatto)
$po2Date = '2026-11-04';
$po2Total = (500 * 80.00) + (300 * 60.00); // 40,000 + 18,000 = 58,000
$pdo->exec("
INSERT INTO `purchase_orders` (`id`, `po_number`, `supplier_id`, `warehouse_id`, `po_date`, `expected_date`, `reference_no`, `subtotal`, `grand_total`, `status`, `notes`, `created_by`, `created_at`) 
VALUES (2, 'PO-202611040002', 2, 1, '{$po2Date}', '2026-11-06', 'EBM-ORDER-14', {$po2Total}, {$po2Total}, 'received', 'Biscuits stock refresh', 1, '{$po2Date} 10:00:00');

INSERT INTO `purchase_items` (`purchase_order_id`, `product_id`, `unit_id`, `order_qty`, `received_qty`, `unit_cost`, `total_cost`) VALUES
(2, 5, 1, 500.00, 500.00, 80.00, 40000.00),
(2, 8, 1, 300.00, 300.00, 60.00, 18000.00);

INSERT INTO `goods_receipts` (`id`, `grn_number`, `purchase_order_id`, `supplier_id`, `warehouse_id`, `grn_date`, `invoice_no`, `invoice_date`, `total_amount`, `freight_cost`, `landed_cost`, `notes`, `posted_to_accounts`, `created_by`, `created_at`)
VALUES (2, 'GRN-202611040002', 2, 2, 1, '{$po2Date}', 'EBM-BIL-3392', '{$po2Date}', {$po2Total}, 0.00, {$po2Total}, 'Direct shipment from EBM Hub', 1, 6, '{$po2Date} 15:30:00');

INSERT INTO `goods_receipt_items` (`goods_receipt_id`, `product_id`, `batch_number`, `expiry_date`, `unit_id`, `received_qty`, `unit_cost`, `total_cost`) VALUES
(2, 5, 'BATCH-SP-26B', '2027-11-20', 1, 500.00, 80.00, 40000.00),
(2, 8, 'BATCH-CH-26B', '2027-10-30', 1, 300.00, 60.00, 18000.00);
");
postGoodsReceiptAccounting($pdo, 2, 'GRN-202611040002', 2, $po2Total, 1);

// Supplier Payment 2: Clear full payment of Rs. 58,000 to EBM from HBL Bank (Account 3)
$pdo->exec("
INSERT INTO `payments` (`id`, `payment_number`, `payment_type`, `reference_type`, `reference_id`, `party_type`, `party_id`, `amount`, `payment_method_id`, `payment_method_code`, `payment_account_id`, `payment_date`, `reference_no`, `notes`, `status`, `created_by`, `created_at`)
VALUES (2, 'PV-202611050002', 'supplier_payment', 'supplier_disbursement', 2, 'supplier', 2, {$po2Total}, 2, 'bank_transfer', 3, '2026-11-05', 'HBL-CHQ-4819', 'Full clearance for GRN-202611040002', 'posted', 1, '2026-11-05 11:30:00');
");
postSupplierPaymentAccounting($pdo, 2, 'PV-202611050002', 2, $po2Total, 3, 1);

echo "✓ Purchase orders, Goods receipts & Supplier payment vouchers posted\n";

// -------------------------------------------------------------
// 11. Sales Orders, Delivery Workflows, Invoices & Collections
// -------------------------------------------------------------
// Order 1: Madina Cash & Carry (Delivered, Invoiced, Fully Paid via Bank)
$so1Total = (30 * 270.00) + (40 * 85.00) + (20 * 138.00); // 8,100 + 3,400 + 2,760 = 14,260
$so1Cost = (30 * 260.00) + (40 * 80.00) + (20 * 130.00);  // 7,800 + 3,200 + 2,600 = 13,600
$so1Date = '2026-11-06';
$pdo->exec("
INSERT INTO `sales_orders` (`id`, `order_number`, `customer_id`, `order_booker_id`, `area_id`, `order_date`, `delivery_date`, `subtotal`, `grand_total`, `status`, `payment_type`, `price_tier`, `notes`, `approved_by`, `approved_at`, `created_at`)
VALUES (1, 'SO-202611060001', 1, 3, 1, '{$so1Date}', '2026-11-07', {$so1Total}, {$so1Total}, 'delivered', 'credit', 'trade_price', 'Weekly shop order for central shelf', 1, '{$so1Date} 14:00:00', '{$so1Date} 10:00:00');

INSERT INTO `sales_order_items` (`sales_order_id`, `product_id`, `unit_id`, `unit_price`, `quantity`, `line_total`, `dispatched_qty`, `delivered_qty`) VALUES
(1, 1, 1, 270.00, 30.00, 8100.00, 30.00, 30.00),
(1, 5, 1, 85.00, 40.00, 3400.00, 40.00, 40.00),
(1, 9, 1, 138.00, 20.00, 2760.00, 20.00, 20.00);

INSERT INTO `delivery_assignments` (`id`, `dispatch_number`, `sales_order_id`, `delivery_man_id`, `warehouse_id`, `vehicle_no`, `route_name`, `dispatch_date`, `status`, `notes`, `dispatched_by`, `created_at`)
VALUES (1, 'DC-202611070001', 1, 5, 1, 'GAW-4821', 'Route 1 - Satellite Town', '2026-11-07', 'delivered', 'Delivered intact', 1, '2026-11-07 09:00:00');

INSERT INTO `sales_invoices` (`id`, `invoice_number`, `sales_order_id`, `customer_id`, `warehouse_id`, `invoice_date`, `due_date`, `subtotal`, `grand_total`, `cost_of_goods`, `paid_amount`, `balance_due`, `status`, `created_at`)
VALUES (1, 'INV-202611070001', 1, 1, 1, '2026-11-07', '2026-11-14', {$so1Total}, {$so1Total}, {$so1Cost}, {$so1Total}, 0.00, 'paid', '2026-11-07 09:30:00');

INSERT INTO `sales_invoice_items` (`sales_invoice_id`, `product_id`, `unit_id`, `quantity`, `unit_price`, `unit_cost`, `discount`, `tax`, `line_total`) VALUES
(1, 1, 1, 30.00, 270.00, 260.00, 0.00, 0.00, 8100.00),
(1, 5, 1, 40.00, 85.00, 80.00, 0.00, 0.00, 3400.00),
(1, 9, 1, 20.00, 138.00, 130.00, 0.00, 0.00, 2760.00);
");

// Post Invoice 1 Accounting: Debit Accounts Receivable (5), Credit Sales Revenue (13) + COGS entry
postSalesInvoiceAccounting($pdo, 1, 'INV-202611070001', 1, $so1Total, $so1Cost, 1);

// Post Payment Recovery for Order 1: Full amount Rs. 14,260 into Meezan Bank (Account 4)
$pdo->exec("
INSERT INTO `payments` (`id`, `payment_number`, `payment_type`, `reference_type`, `reference_id`, `party_type`, `party_id`, `amount`, `payment_method_id`, `payment_method_code`, `payment_account_id`, `payment_date`, `reference_no`, `collected_by`, `handover_status`, `notes`, `status`, `created_by`, `created_at`)
VALUES (3, 'RCP-202611080001', 'customer_receipt', 'recovery', 1, 'customer', 1, {$so1Total}, 3, 'meezan_bank', 4, '2026-11-08', 'RAAST-8812', 3, 'reconciled', 'Direct bank settlement from Haji Akram', 'posted', 1, '2026-11-08 11:00:00');

INSERT INTO `payment_allocations` (`payment_id`, `invoice_id`, `allocated_amount`, `created_at`) VALUES
(3, 1, {$so1Total}, '2026-11-08 11:00:00');
");
postCustomerPaymentAccounting($pdo, 3, 'RCP-202611080001', 1, $so1Total, 4, 1);

// Order 2: Bismillah General Store (Delivered, Invoiced for Rs. 12,500, Partial Paid Rs. 7,500 in Cash, Balance Due: Rs. 5,000)
$so2Total = 12500.00;
$so2Cost = 11200.00;
$so2Date = '2026-11-09';
$pdo->exec("
INSERT INTO `sales_orders` (`id`, `order_number`, `customer_id`, `order_booker_id`, `area_id`, `order_date`, `delivery_date`, `subtotal`, `grand_total`, `status`, `payment_type`, `price_tier`, `notes`, `approved_by`, `approved_at`, `created_at`)
VALUES (2, 'SO-202611090002', 2, 3, 1, '{$so2Date}', '2026-11-10', {$so2Total}, {$so2Total}, 'delivered', 'credit', 'trade_price', 'Model town shop delivery', 1, '{$so2Date} 15:00:00', '{$so2Date} 11:00:00');

INSERT INTO `sales_order_items` (`sales_order_id`, `product_id`, `unit_id`, `unit_price`, `quantity`, `line_total`, `dispatched_qty`, `delivered_qty`) VALUES
(2, 6, 1, 38.00, 100.00, 3800.00, 100.00, 100.00),
(2, 10, 1, 102.00, 50.00, 5100.00, 50.00, 50.00),
(2, 14, 1, 72.00, 50.00, 3600.00, 50.00, 50.00);

INSERT INTO `delivery_assignments` (`id`, `dispatch_number`, `sales_order_id`, `delivery_man_id`, `warehouse_id`, `vehicle_no`, `route_name`, `dispatch_date`, `status`, `notes`, `dispatched_by`, `created_at`)
VALUES (2, 'DC-202611100002', 2, 5, 1, 'GAW-4821', 'Route 2 - Model Town', '2026-11-10', 'delivered', 'Collected partial payment', 1, '2026-11-10 09:30:00');

INSERT INTO `sales_invoices` (`id`, `invoice_number`, `sales_order_id`, `customer_id`, `warehouse_id`, `invoice_date`, `due_date`, `subtotal`, `grand_total`, `cost_of_goods`, `paid_amount`, `balance_due`, `status`, `created_at`)
VALUES (2, 'INV-202611100002', 2, 2, 1, '2026-11-10', '2026-11-17', {$so2Total}, {$so2Total}, {$so2Cost}, 7500.00, 5000.00, 'partially_paid', '2026-11-10 10:00:00');
");
postSalesInvoiceAccounting($pdo, 2, 'INV-202611100002', 2, $so2Total, $so2Cost, 1);

// Cash recovery by delivery driver: Rs. 7,500 into Cash in Hand (Account 2)
$pdo->exec("
INSERT INTO `payments` (`id`, `payment_number`, `payment_type`, `reference_type`, `reference_id`, `party_type`, `party_id`, `amount`, `payment_method_id`, `payment_method_code`, `payment_account_id`, `payment_date`, `reference_no`, `collected_by`, `handover_status`, `notes`, `status`, `created_by`, `created_at`)
VALUES (4, 'RCP-202611100002', 'customer_receipt', 'delivery', 2, 'customer', 2, 7500.00, 1, 'cash', 2, '2026-11-10', 'CASH-REC-102', 5, 'reconciled', 'Partial cash collected at shop doorstep', 'posted', 1, '2026-11-10 12:00:00');

INSERT INTO `payment_allocations` (`payment_id`, `invoice_id`, `allocated_amount`, `created_at`) VALUES
(4, 2, 7500.00, '2026-11-10 12:00:00');
");
postCustomerPaymentAccounting($pdo, 4, 'RCP-202611100002', 2, 7500.00, 2, 1);

// Driver Handover for Order 2 cash: Rs. 7,500 verified and reconciled
$pdo->exec("
INSERT INTO `cash_handovers` (`id`, `handover_number`, `delivery_man_id`, `handover_date`, `cash_amount`, `digital_amount`, `cheque_amount`, `total_collected`, `cash_deposited`, `difference_amount`, `status`, `verified_by`, `verified_at`, `notes`, `created_at`)
VALUES (1, 'CHO-202611100001', 5, '2026-11-10', 7500.00, 0.00, 0.00, 7500.00, 7500.00, 0.00, 'verified', 1, '2026-11-10 17:30:00', 'Evening route collection reconciled in full', '2026-11-10 17:00:00');
");

// Order 3: Subhan Wholesale Point (Delivered, Invoiced for Rs. 28,500, Full Credit)
$so3Total = 28500.00;
$so3Cost = 25800.00;
$so3Date = '2026-11-11';
$pdo->exec("
INSERT INTO `sales_orders` (`id`, `order_number`, `customer_id`, `order_booker_id`, `area_id`, `order_date`, `delivery_date`, `subtotal`, `grand_total`, `status`, `payment_type`, `price_tier`, `notes`, `approved_by`, `approved_at`, `created_at`)
VALUES (3, 'SO-202611110003', 3, 3, 2, '{$so3Date}', '2026-11-12', {$so3Total}, {$so3Total}, 'delivered', 'credit', 'wholesale_price', 'Wholesale bulk carton orders', 1, '{$so3Date} 16:00:00', '{$so3Date} 12:00:00');

INSERT INTO `sales_order_items` (`sales_order_id`, `product_id`, `unit_id`, `unit_price`, `quantity`, `line_total`, `dispatched_qty`, `delivered_qty`) VALUES
(3, 19, 1, 2600.00, 5.00, 13000.00, 5.00, 5.00),
(3, 22, 1, 670.00, 15.00, 10050.00, 15.00, 15.00),
(3, 13, 1, 490.00, 11.00, 5390.00, 11.00, 11.00);

INSERT INTO `delivery_assignments` (`id`, `dispatch_number`, `sales_order_id`, `delivery_man_id`, `warehouse_id`, `vehicle_no`, `route_name`, `dispatch_date`, `status`, `notes`, `dispatched_by`, `created_at`)
VALUES (3, 'DC-202611120003', 3, 5, 1, 'GAW-4821', 'Route 3 - Rahwali Belt', '2026-11-12', 'delivered', 'Signed by Malik Tanveer', 1, '2026-11-12 10:00:00');

INSERT INTO `sales_invoices` (`id`, `invoice_number`, `sales_order_id`, `customer_id`, `warehouse_id`, `invoice_date`, `due_date`, `subtotal`, `grand_total`, `cost_of_goods`, `paid_amount`, `balance_due`, `status`, `created_at`)
VALUES (3, 'INV-202611120003', 3, 3, 1, '2026-11-12', '2026-11-27', {$so3Total}, {$so3Total}, {$so3Cost}, 0.00, {$so3Total}, 'posted', '2026-11-12 10:30:00');
");
postSalesInvoiceAccounting($pdo, 3, 'INV-202611120003', 3, $so3Total, $so3Cost, 1);

// Active Order 4: Ghousia Departmental Store (Dispatched - Out for delivery today)
$so4Total = 8650.00;
$pdo->exec("
INSERT INTO `sales_orders` (`id`, `order_number`, `customer_id`, `order_booker_id`, `area_id`, `order_date`, `delivery_date`, `subtotal`, `grand_total`, `status`, `payment_type`, `price_tier`, `notes`, `approved_by`, `approved_at`, `created_at`)
VALUES (4, 'SO-202611160004', 6, 3, 2, '2026-11-16', '2026-11-17', {$so4Total}, {$so4Total}, 'dispatched', 'credit', 'trade_price', 'Scheduled morning delivery', 1, '2026-11-16 17:00:00', '2026-11-16 11:30:00');

INSERT INTO `sales_order_items` (`sales_order_id`, `product_id`, `unit_id`, `unit_price`, `quantity`, `line_total`, `dispatched_qty`, `delivered_qty`) VALUES
(4, 5, 1, 85.00, 50.00, 4250.00, 50.00, 0.00),
(4, 7, 1, 48.00, 50.00, 2400.00, 50.00, 0.00),
(4, 4, 1, 80.00, 25.00, 2000.00, 25.00, 0.00);

INSERT INTO `delivery_assignments` (`id`, `dispatch_number`, `sales_order_id`, `delivery_man_id`, `warehouse_id`, `vehicle_no`, `route_name`, `dispatch_date`, `status`, `notes`, `dispatched_by`, `created_at`)
VALUES (4, 'DC-202611170004', 4, 5, 1, 'GAW-4821', 'Route 2 - Rahwali Cantt', '2026-11-17', 'in_transit', 'Loaded in morning van dispatch', 1, '2026-11-17 08:30:00');
");

// Active Order 5: Al-Madad Mart (Approved, Packing in Warehouse)
$so5Total = 6400.00;
$pdo->exec("
INSERT INTO `sales_orders` (`id`, `order_number`, `customer_id`, `order_booker_id`, `area_id`, `order_date`, `delivery_date`, `subtotal`, `grand_total`, `status`, `payment_type`, `price_tier`, `notes`, `approved_by`, `approved_at`, `created_at`)
VALUES (5, 'SO-202611170005', 7, 4, 4, '2026-11-17', '2026-11-18', {$so5Total}, {$so5Total}, 'approved', 'credit', 'trade_price', 'Wazirabad weekly supply', 1, '2026-11-17 09:30:00', '2026-11-17 09:00:00');

INSERT INTO `sales_order_items` (`sales_order_id`, `product_id`, `unit_id`, `unit_price`, `quantity`, `line_total`, `dispatched_qty`, `delivered_qty`) VALUES
(5, 15, 1, 112.00, 30.00, 3360.00, 0.00, 0.00),
(5, 16, 1, 112.00, 20.00, 2240.00, 0.00, 0.00),
(5, 18, 1, 48.00, 16.00, 768.00, 0.00, 0.00);
");

// Active Order 6 & 7: Freshly Submitted by Order Bookers (Pending Approval)
$pdo->exec("
INSERT INTO `sales_orders` (`id`, `order_number`, `customer_id`, `order_booker_id`, `area_id`, `order_date`, `delivery_date`, `subtotal`, `grand_total`, `status`, `payment_type`, `price_tier`, `notes`, `created_at`)
VALUES 
(6, 'SO-202611170006', 4, 4, 3, '2026-11-17', '2026-11-18', 15800.00, 15800.00, 'submitted', 'credit', 'trade_price', 'Kamoke grocery replenishment', '2026-11-17 10:15:00'),
(7, 'SO-202611170007', 5, 3, 1, '2026-11-17', '2026-11-18', 7200.00, 7200.00, 'submitted', 'credit', 'trade_price', 'Civil lines urgent biscuit reorder', '2026-11-17 10:45:00');

INSERT INTO `sales_order_items` (`sales_order_id`, `product_id`, `unit_id`, `unit_price`, `quantity`, `line_total`) VALUES
(6, 19, 1, 2550.00, 4.00, 10200.00),
(6, 20, 1, 505.00, 10.00, 5050.00),
(6, 18, 1, 48.00, 11.00, 528.00),
(7, 5, 1, 85.00, 40.00, 3400.00),
(7, 6, 1, 38.00, 100.00, 3800.00);
");

echo "✓ Sales Orders, Delivery dispatches, Invoices, & Customer collections seeded\n";

// -------------------------------------------------------------
// 12. Operating Expenses Vouchers
// -------------------------------------------------------------
$expenses = [
    // [expense_no, cat_id, date, amount, pay_acc_id, method, ref, desc]
    ['EXP-202611040001', 3, '2026-11-04', 65000.00, 3, 'bank_transfer', 'CHQ-HBL-9182', 'Monthly Warehouse Rent for Plot 45 Industrial Estate'],
    ['EXP-202611060002', 2, '2026-11-06', 14500.00, 2, 'cash', 'PSO-FUEL-819', 'Diesel fuel refill for Toyota Hiace Delivery Van GAW-4821'],
    ['EXP-202611080003', 3, '2026-11-08', 22400.00, 4, 'meezan_bank', 'GEPCO-BILL-99', 'Monthly Electricity & Utilities Bill paid via Meezan Online'],
    ['EXP-202611110004', 4, '2026-11-11', 8500.00, 2, 'cash', 'LABOR-VOUCH-12', 'Unloading labor charges for inbound FMCG trailer shipments'],
    ['EXP-202611140005', 6, '2026-11-14', 6200.00, 2, 'cash', 'OFFICE-STAT-41', 'Delivery challan books, invoice rolls, and barcode label printer ribbon']
];

$expStmt = $pdo->prepare("
INSERT INTO `expenses` (
    `id`, `expense_number`, `category_id`, `expense_date`, `amount`, 
    `payment_account_id`, `payment_method`, `reference_no`, `description`, 
    `entered_by`, `approved_by`, `status`, `created_at`
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1, 'approved', ?);
");

$catAccMap = [
    1 => 21, // salaries
    2 => 22, // fuel
    3 => 23, // rent
    4 => 24, // freight
    5 => 25, // commission
    6 => 26  // misc
];

foreach ($expenses as $idx => $exp) {
    $expId = $idx + 1;
    $expStmt->execute([
        $expId, $exp[0], $exp[1], $exp[2], $exp[3],
        $exp[4], $exp[5], $exp[6], $exp[7],
        $exp[2] . ' 12:00:00'
    ]);
    postExpenseAccounting($pdo, $expId, $exp[0], $catAccMap[$exp[1]], $exp[4], $exp[3], $exp[7], 1);
}
echo "✓ 5 Operating Expense vouchers posted with balanced accounting entries\n";

// -------------------------------------------------------------
// 13. Staff Payroll (Previous Month: October 2026)
// -------------------------------------------------------------
$pdo->exec("
INSERT INTO `payroll_periods` (
    `id`, `period_code`, `month_year`, `start_date`, `end_date`, 
    `total_gross_salary`, `total_commissions`, `total_deductions`, `total_net_salary`, 
    `status`, `processed_by`, `processed_at`, `created_at`
) VALUES (
    1, 'PR-2026-10', 'October 2026', '2026-10-01', '2026-10-31', 
    382000.00, 18500.00, 5000.00, 395500.00, 
    'paid', 1, '2026-11-01 16:00:00', '2026-11-01 15:00:00'
);

INSERT INTO `salary_slips` (
    `id`, `payroll_period_id`, `slip_number`, `employee_id`, `base_salary`, 
    `commission_amount`, `bonus_amount`, `allowances`, `deductions`, `net_salary`, 
    `orders_count`, `sales_volume`, `status`, `payment_date`, `payment_method`, 
    `remarks`, `paid_by`, `created_at`
) VALUES
(1, 1, 'SLIP-202610-001', 1, 150000.00, 0.00, 0.00, 0.00, 0.00, 150000.00, 0, 0.00, 'paid', '2026-11-01', 'bank', 'Monthly executive compensation', 1, '2026-11-01 15:30:00'),
(2, 1, 'SLIP-202610-002', 2, 90000.00, 0.00, 0.00, 0.00, 0.00, 90000.00, 0, 0.00, 'paid', '2026-11-01', 'bank', 'Monthly manager salary', 1, '2026-11-01 15:30:00'),
(3, 1, 'SLIP-202610-003', 3, 35000.00, 12500.00, 0.00, 0.00, 2000.00, 45500.00, 45, 835000.00, 'paid', '2026-11-01', 'bank', 'Order booker salary + sales commission', 1, '2026-11-01 15:30:00'),
(4, 1, 'SLIP-202610-004', 5, 30000.00, 6000.00, 0.00, 0.00, 1000.00, 35000.00, 80, 0.00, 'paid', '2026-11-01', 'cash', 'Driver salary + delivery trip incentives', 1, '2026-11-01 15:30:00'),
(5, 1, 'SLIP-202610-005', 6, 40000.00, 0.00, 0.00, 0.00, 1000.00, 39000.00, 0, 0.00, 'paid', '2026-11-01', 'bank', 'Storekeeper salary', 1, '2026-11-01 15:30:00'),
(6, 1, 'SLIP-202610-006', 7, 55000.00, 0.00, 0.00, 0.00, 1000.00, 54000.00, 0, 0.00, 'paid', '2026-11-01', 'bank', 'Senior accountant compensation', 1, '2026-11-01 15:30:00');
");

// Post Double-Entry Journal Entry for Payroll Disbursement (Total: Rs. 395,500 disbursed via Bank Account HBL)
$entryNumber = generateDocNumber($pdo, 'journal_entries', 'entry_number', 'JE-PAY-');
$payrollJeStmt = $pdo->prepare("
INSERT INTO `journal_entries` (`entry_number`, `entry_date`, `reference_type`, `reference_id`, `narration`, `total_debit`, `total_credit`, `posted_by`, `status`, `created_at`) 
VALUES (?, '2026-11-01', 'payroll_period', 1, 'Staff Salaries & Commissions for October 2026 via HBL Bank Transfer', 395500.00, 395500.00, 1, 'posted', '2026-11-01 16:30:00');
");
$payrollJeStmt->execute([$entryNumber]);
$pJeId = (int)$pdo->lastInsertId();

// Debit Salaries Expense (21)
$linesStmt->execute([$pJeId, 21, 395500.00, 0.00, 'Staff Salaries & Commissions for October 2026']);
// Credit HBL Bank (3)
$linesStmt->execute([$pJeId, 3, 0.00, 395500.00, 'Salary disbursement via HBL Corporate Transfer']);

echo "✓ October 2026 Staff Payroll disbursed & posted to General Ledger\n";

// -------------------------------------------------------------
// 14. Verification & Audit Check
// -------------------------------------------------------------
echo "\n--- VERIFICATION CHECKS ---\n";

// 1. Unbalanced Journal Entries
$unbalanced = $pdo->query("
    SELECT je.id, je.entry_number, je.total_debit, je.total_credit,
           COALESCE(SUM(jel.debit), 0) as lines_debit,
           COALESCE(SUM(jel.credit), 0) as lines_credit
    FROM journal_entries je
    LEFT JOIN journal_entry_lines jel ON je.id = jel.journal_entry_id
    GROUP BY je.id, je.entry_number, je.total_debit, je.total_credit
    HAVING ABS(lines_debit - lines_credit) > 0.001
")->fetchAll();

if (count($unbalanced) > 0) {
    echo "❌ ERROR: Found " . count($unbalanced) . " unbalanced journal entries!\n";
    print_r($unbalanced);
    exit(1);
}
echo "✓ Zero unbalanced journal entries detected (100% strict double-entry equality)\n";

// 2. Trial Balance Sums
$tb = $pdo->query("
    SELECT SUM(debit) as tot_debit, SUM(credit) as tot_credit 
    FROM journal_entry_lines
")->fetch();

echo "✓ Trial Balance Equilibrium: Total Debits = Rs. " . number_format((float)$tb['tot_debit'], 2) . " | Total Credits = Rs. " . number_format((float)$tb['tot_credit'], 2) . "\n";

// 3. Customer Balances Verification
$cCust = $pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();
$totReceivables = (float)$pdo->query("SELECT SUM(current_balance) FROM customers")->fetchColumn();
echo "✓ Customers count: {$cCust} | Outstanding Receivables: Rs. " . number_format($totReceivables, 2) . "\n";

// 4. Supplier Balances Verification
$cSupp = $pdo->query("SELECT COUNT(*) FROM suppliers")->fetchColumn();
$totPayables = (float)$pdo->query("SELECT SUM(current_balance) FROM suppliers")->fetchColumn();
echo "✓ Suppliers count: {$cSupp} | Outstanding Payables: Rs. " . number_format($totPayables, 2) . "\n";

echo "\n========================================================\n";
echo "✓ DUMMY DATA SEEDING COMPLETED SUCCESSFULLY!\n";
echo "========================================================\n";
