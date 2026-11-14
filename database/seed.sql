-- ============================================================================
-- Enterprise Distribution Management System (DMS)
-- Seed Data & Initial Setup
-- ============================================================================

-- 1. Company
INSERT INTO `companies` (`id`, `name`, `legal_name`, `email`, `phone`, `address`, `city`, `ntn`, `strn`, `currency`, `currency_symbol`, `invoice_prefix`, `purchase_prefix`, `order_prefix`, `delivery_prefix`, `receipt_prefix`, `inventory_valuation`, `status`) VALUES
(1, 'Falcon FMCG Distribution Co.', 'Falcon Enterprise Distribution Pvt Ltd', 'info@falcon-dist.com', '+92 300 1234567', 'Plot 45, Small Industrial Estate', 'Gujranwala', '1234567-8', 'STRN-987654', 'PKR', 'Rs.', 'INV-', 'PO-', 'SO-', 'DC-', 'RCP-', 'WAC', 'active')
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`);

-- 2. Settings
INSERT INTO `settings` (`setting_key`, `setting_value`, `description`) VALUES
('company_name', 'Falcon FMCG Distribution Co.', 'Business Name'),
('company_address', 'Plot 45, Small Industrial Estate, Gujranwala', 'Address'),
('company_phone', '+92 300 1234567', 'Phone Number'),
('currency', 'PKR', 'Default Currency Code'),
('currency_symbol', 'Rs.', 'Default Currency Symbol'),
('stock_deduction_point', 'dispatch', 'Stock deduction event (dispatch or delivery)'),
('inventory_costing_method', 'WAC', 'Weighted Average Costing'),
('default_credit_limit', '50000', 'Default credit limit for new retail shops'),
('offline_sync_enabled', '1', 'Allow field workers offline storage and auto-sync')
ON DUPLICATE KEY UPDATE `setting_value`=VALUES(`setting_value`);

-- 3. Roles
INSERT INTO `roles` (`id`, `name`, `slug`, `description`, `is_system`) VALUES
(1, 'Super Admin', 'super_admin', 'Full access to all system features, accounts and settings', 1),
(2, 'Admin / General Manager', 'admin', 'Management level access across all operations', 1),
(3, 'Order Booker', 'order_booker', 'Mobile field sales rep: assigned areas, shop registration, order booking', 1),
(4, 'Salesman', 'salesman', 'Direct van salesman: orders, collections and spot distribution', 1),
(5, 'Delivery Man', 'delivery_man', 'Mobile delivery app: deliveries, returns and payment recovery', 1),
(6, 'Warehouse Manager / Storekeeper', 'storekeeper', 'Stock receiving, order picking, packing, dispatch and adjustments', 1),
(7, 'Accountant', 'accountant', 'Double-entry accounting, cash reconciliations, ledgers and P&L', 1)
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`);

-- 4. Granular Permissions
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
(55, 'settings', 'edit', 'Update business settings and configurations')
ON DUPLICATE KEY UPDATE `description`=VALUES(`description`);

-- Assign Permissions to Super Admin (All permissions 1..55)
DELETE FROM `role_permissions` WHERE `role_id` = 1;
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 1, `id` FROM `permissions`;

-- Assign Permissions to Admin (Almost all except system destructive settings)
DELETE FROM `role_permissions` WHERE `role_id` = 2;
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 2, `id` FROM `permissions` WHERE `action` NOT IN ('delete');

-- Assign Permissions to Order Booker (Mobile Field Rep)
DELETE FROM `role_permissions` WHERE `role_id` = 3;
INSERT INTO `role_permissions` (`role_id`, `permission_id`) VALUES
(3, 1),  -- dashboard.view
(3, 6),  -- products.view
(3, 18), -- areas.view
(3, 22), -- customers.view
(3, 23), -- customers.create
(3, 24), -- customers.edit
(3, 26), -- orders.view
(3, 27), -- orders.create
(3, 28), -- orders.edit
(3, 51); -- reports.sales (own)

-- Assign Permissions to Delivery Man (Mobile Delivery App)
DELETE FROM `role_permissions` WHERE `role_id` = 5;
INSERT INTO `role_permissions` (`role_id`, `permission_id`) VALUES
(5, 1),  -- dashboard.view
(5, 22), -- customers.view
(5, 26), -- orders.view
(5, 33), -- delivery.view
(5, 34), -- delivery.deliver
(5, 35), -- delivery.collect_payment
(5, 36), -- payments.view
(5, 38); -- payments.handover

-- Assign Permissions to Storekeeper
DELETE FROM `role_permissions` WHERE `role_id` = 6;
INSERT INTO `role_permissions` (`role_id`, `permission_id`) VALUES
(6, 1),  -- dashboard.view
(6, 6),  -- products.view
(6, 10), -- inventory.view
(6, 11), -- inventory.adjust
(6, 12), -- inventory.transfer
(6, 13), -- purchases.view
(6, 16), -- purchases.receive
(6, 26), -- orders.view
(6, 31), -- warehouse.view
(6, 32), -- warehouse.dispatch
(6, 40), -- returns.view
(6, 53); -- reports.inventory

-- Assign Permissions to Accountant
DELETE FROM `role_permissions` WHERE `role_id` = 7;
INSERT INTO `role_permissions` (`role_id`, `permission_id`) VALUES
(7, 1), (7, 2), (7, 13), (7, 22), (7, 26), (7, 36), (7, 37), (7, 39),
(7, 43), (7, 44), (7, 45), (7, 49), (7, 50), (7, 51), (7, 52), (7, 53);

-- 5. Chart of Accounts (Complete Double-Entry Framework)
INSERT INTO `chart_of_accounts` (`id`, `code`, `name`, `group_type`, `parent_id`, `is_active`, `system_tag`) VALUES
(1, '1000', 'Current Assets', 'Asset', NULL, 1, NULL),
(2, '1010', 'Cash in Hand', 'Asset', 1, 1, 'cash'),
(3, '1020', 'Bank Account (Main)', 'Asset', 1, 1, 'bank'),
(4, '1030', 'Accounts Receivable / Customer Balances', 'Asset', 1, 1, 'receivables'),
(5, '1040', 'Merchandise Inventory', 'Asset', 1, 1, 'inventory'),
(6, '2000', 'Current Liabilities', 'Liability', NULL, 1, NULL),
(7, '2010', 'Accounts Payable / Supplier Balances', 'Liability', 6, 1, 'payables'),
(8, '3000', 'Equity', 'Equity', NULL, 1, NULL),
(9, '3010', 'Owner Capital', 'Equity', 8, 1, 'capital'),
(10, '3020', 'Retained Earnings', 'Equity', 8, 1, 'retained_earnings'),
(11, '4000', 'Revenue / Sales', 'Income', NULL, 1, NULL),
(12, '4010', 'Sales Revenue', 'Income', 11, 1, 'sales_revenue'),
(13, '4020', 'Delivery & Handling Income', 'Income', 11, 1, 'delivery_income'),
(14, '4500', 'Contra Revenue', 'Contra_Income', NULL, 1, NULL),
(15, '4510', 'Sales Returns & Damaged Allowances', 'Contra_Income', 14, 1, 'sales_returns'),
(16, '4520', 'Sales Discounts Granted', 'Contra_Income', 14, 1, 'sales_discounts'),
(17, '5000', 'Cost of Sales', 'Cost_Of_Sales', NULL, 1, NULL),
(18, '5010', 'Cost of Goods Sold (COGS)', 'Cost_Of_Sales', 17, 1, 'cogs'),
(19, '6000', 'Operating Expenses', 'Expense', NULL, 1, NULL),
(20, '6010', 'Salaries & Wages', 'Expense', 19, 1, 'salaries'),
(21, '6020', 'Vehicle Fuel & Fleet Maintenance', 'Expense', 19, 1, 'fuel_vehicle'),
(22, '6030', 'Warehouse Rent & Electricity', 'Expense', 19, 1, 'rent_utilities'),
(23, '6040', 'Loading / Unloading Freight Charges', 'Expense', 19, 1, 'freight_loading'),
(24, '6050', 'Sales Commission Expense', 'Expense', 19, 1, 'commission'),
(25, '6060', 'General Office & Miscellaneous Expense', 'Expense', 19, 1, 'misc_expense')
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`), `system_tag`=VALUES(`system_tag`);

-- 6. Payment Methods
INSERT INTO `payment_methods` (`id`, `name`, `code`, `account_id`, `is_active`) VALUES
(1, 'Cash', 'cash', 2, 1),
(2, 'Bank Transfer / Cheque', 'bank_transfer', 3, 1),
(3, 'JazzCash Merchant', 'jazzcash', 3, 1),
(4, 'Easypaisa Merchant', 'easypaisa', 3, 1),
(5, 'Bank Cheque', 'cheque', 3, 1)
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`);

-- 7. Expense Categories
INSERT INTO `expense_categories` (`id`, `name`, `code`, `account_id`, `status`) VALUES
(1, 'Salaries', 'salaries', 20, 'active'),
(2, 'Fuel & Vehicle Maintenance', 'fuel', 21, 'active'),
(3, 'Warehouse Rent', 'rent', 22, 'active'),
(4, 'Freight & Loading', 'freight', 23, 'active'),
(5, 'Sales Commission', 'commission', 24, 'active'),
(6, 'Office & Miscellaneous', 'misc', 25, 'active')
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`);

-- 8. Warehouses
INSERT INTO `warehouses` (`id`, `code`, `name`, `address`, `contact_person`, `phone`, `is_default`, `status`) VALUES
(1, 'WH-MAIN', 'Central Distribution Warehouse', 'Plot 45 Small Industrial Estate, Gujranwala', 'Tariq Mehmood', '+92 300 1122334', 1, 'active'),
(2, 'WH-NORTH', 'North City Transit Hub', 'Rahwali Cantt Road, Gujranwala', 'Babar Ali', '+92 301 4455667', 0, 'active')
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`);

-- 9. Units & Unit Conversions
INSERT INTO `units` (`id`, `name`, `code`, `is_base`) VALUES
(1, 'Piece', 'pc', 1),
(2, 'Box', 'box', 0),
(3, 'Carton', 'ctn', 0),
(4, 'Dozen', 'doz', 0),
(5, 'Kilogram', 'kg', 1),
(6, 'Liter', 'ltr', 1),
(7, 'Pack', 'pack', 0)
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`);

-- Standard Conversions: 1 Carton = 12 Boxes, 1 Box = 24 Pieces (1 Carton = 288 Pieces)
INSERT INTO `unit_conversions` (`id`, `product_id`, `from_unit_id`, `to_unit_id`, `multiplier`) VALUES
(1, NULL, 3, 2, 12.0000), -- 1 ctn = 12 box
(2, NULL, 2, 1, 24.0000), -- 1 box = 24 pcs
(3, NULL, 4, 1, 12.0000)  -- 1 doz = 12 pcs
ON DUPLICATE KEY UPDATE `multiplier`=VALUES(`multiplier`);

-- 10. Categories & Brands
INSERT INTO `categories` (`id`, `name`, `code`, `parent_id`, `status`) VALUES
(1, 'Beverages & Soft Drinks', 'BEV', NULL, 'active'),
(2, 'Biscuits & Confectionery', 'CONF', NULL, 'active'),
(3, 'Personal Care & Soaps', 'CARE', NULL, 'active'),
(4, 'Cooking Oil & Ghee', 'OIL', NULL, 'active'),
(5, 'Spices & Staples', 'STAP', NULL, 'active')
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`);

INSERT INTO `brands` (`id`, `name`, `code`, `status`) VALUES
(1, 'Nestle', 'NEST', 'active'),
(2, 'Unilever', 'UNIL', 'active'),
(3, 'English Biscuit Manufacturers (EBM)', 'EBM', 'active'),
(4, 'Shan Foods', 'SHAN', 'active'),
(5, 'Dalda Foods', 'DALD', 'active')
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`);

-- 11. Geographic Areas & Sub-Areas
INSERT INTO `areas` (`id`, `name`, `code`, `city`, `description`, `status`) VALUES
(1, 'Gujranwala Central Market', 'GW-CENT', 'Gujranwala', 'Main Bazaar, Satellite Town, Model Town', 'active'),
(2, 'Rahwali Cantt Zone', 'GW-RAH', 'Gujranwala', 'Cantt Area, Rahwali Gate, GT Road', 'active'),
(3, 'Kamoke Commercial Belt', 'GW-KAM', 'Kamoke', 'Kamoke Bazaar, Mandi Road, Tatley Aali', 'active'),
(4, 'Wazirabad City Zone', 'GW-WAZ', 'Wazirabad', 'Railway Road, Kutchery Road, Nizamabad', 'active')
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`);

INSERT INTO `sub_areas` (`id`, `area_id`, `name`, `code`, `status`) VALUES
(1, 1, 'Satellite Town Block A & B', 'ST-AB', 'active'),
(2, 1, 'Model Town Market', 'MT-MKT', 'active'),
(3, 2, 'Rahwali Main Chowk', 'RAH-CHK', 'active'),
(4, 3, 'Kamoke Grain Market', 'KAM-GM', 'active')
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`);

-- 12. Default Users & Employees
-- Password for all demo accounts is: Password@123
-- Hash generated using PASSWORD_BCRYPT:
-- $2y$10$wEoml5fWzW4Z7j31uNsq5e6l02jV3Q5b3t.rP28kG2yVbC1F0Wq1K
-- Let's insert users:
INSERT INTO `users` (`id`, `role_id`, `username`, `password_hash`, `full_name`, `email`, `phone`, `status`) VALUES
(1, 1, 'admin', '$2y$10$3z7G9yO4R6vYlGSmYvYfvev0xHdQb6T6s1iZ.0Zz5aGqLwFkE6XoO', 'Super Administrator', 'admin@dms.local', '+92 300 1111111', 'active'),
(2, 2, 'manager', '$2y$10$3z7G9yO4R6vYlGSmYvYfvev0xHdQb6T6s1iZ.0Zz5aGqLwFkE6XoO', 'Operations Manager', 'manager@dms.local', '+92 300 2222222', 'active'),
(3, 3, 'booker1', '$2y$10$3z7G9yO4R6vYlGSmYvYfvev0xHdQb6T6s1iZ.0Zz5aGqLwFkE6XoO', 'Zubair Ahmad (Order Booker)', 'booker1@dms.local', '+92 300 3333333', 'active'),
(4, 5, 'delivery1', '$2y$10$3z7G9yO4R6vYlGSmYvYfvev0xHdQb6T6s1iZ.0Zz5aGqLwFkE6XoO', 'Rashid Khan (Delivery Staff)', 'delivery1@dms.local', '+92 300 4444444', 'active'),
(5, 6, 'store1', '$2y$10$3z7G9yO4R6vYlGSmYvYfvev0xHdQb6T6s1iZ.0Zz5aGqLwFkE6XoO', 'Tariq Mehmood (Storekeeper)', 'store1@dms.local', '+92 300 5555555', 'active'),
(6, 7, 'accounts1', '$2y$10$3z7G9yO4R6vYlGSmYvYfvev0xHdQb6T6s1iZ.0Zz5aGqLwFkE6XoO', 'Saeed Anwar (Accountant)', 'accounts1@dms.local', '+92 300 6666666', 'active')
ON DUPLICATE KEY UPDATE `full_name`=VALUES(`full_name`);

INSERT INTO `employees` (`id`, `user_id`, `employee_code`, `full_name`, `phone`, `email`, `designation`, `role_type`, `salary`, `commission_type`, `commission_rate`, `status`) VALUES
(1, 1, 'EMP-001', 'Super Administrator', '+92 300 1111111', 'admin@dms.local', 'Executive Director', 'super_admin', 150000.00, 'none', 0.00, 'active'),
(2, 2, 'EMP-002', 'Operations Manager', '+92 300 2222222', 'manager@dms.local', 'General Manager', 'admin', 90000.00, 'none', 0.00, 'active'),
(3, 3, 'EMP-003', 'Zubair Ahmad', '+92 300 3333333', 'booker1@dms.local', 'Senior Order Booker', 'order_booker', 35000.00, 'percentage_sales', 1.50, 'active'),
(4, 4, 'EMP-004', 'Rashid Khan', '+92 300 4444444', 'delivery1@dms.local', 'Delivery Specialist & Cash Custodian', 'delivery_man', 30000.00, 'fixed_per_order', 25.00, 'active'),
(5, 5, 'EMP-005', 'Tariq Mehmood', '+92 300 5555555', 'store1@dms.local', 'Warehouse & Inventory In-charge', 'storekeeper', 40000.00, 'none', 0.00, 'active'),
(6, 6, 'EMP-006', 'Saeed Anwar', '+92 300 6666666', 'accounts1@dms.local', 'Senior Accounts Officer', 'accountant', 55000.00, 'none', 0.00, 'active')
ON DUPLICATE KEY UPDATE `full_name`=VALUES(`full_name`);

-- Assign Areas to Employees
INSERT INTO `employee_area_assignments` (`employee_id`, `area_id`) VALUES
(3, 1),
(3, 2)
ON DUPLICATE KEY UPDATE `employee_id`=VALUES(`employee_id`);

UPDATE `areas` SET `assigned_order_booker_id` = 3 WHERE `id` IN (1, 2);

-- 13. Suppliers
INSERT INTO `suppliers` (`id`, `code`, `name`, `company_name`, `contact_person`, `phone`, `whatsapp`, `email`, `address`, `city`, `opening_balance`, `current_balance`, `credit_limit`, `payment_terms`, `status`) VALUES
(1, 'SUP-001', 'Nestle Pakistan Ltd', 'Nestle Pakistan Gujranwala Distribution', 'Nadeem Butt', '+92 321 9876543', '+92 321 9876543', 'orders@nestle.com.pk', 'Industrial Area Sheikhupura Road', 'Gujranwala', 0.00, 0.00, 500000.00, 'Net 15', 'active'),
(2, 'SUP-002', 'English Biscuit Manufacturers (Pvt) Ltd', 'EBM Distribution Hub', 'Farhan Sheikh', '+92 333 4567890', '+92 333 4567890', 'ebm@dist.pk', 'Plot 12 GT Road', 'Gujranwala', 0.00, 0.00, 400000.00, 'Net 30', 'active'),
(3, 'SUP-003', 'Unilever Pakistan', 'Unilever Consumer Goods', 'Kashif Rauf', '+92 300 7891234', '+92 300 7891234', 'kashif@unilever.pk', 'Cantt Area', 'Gujranwala', 0.00, 0.00, 600000.00, 'Net 15', 'active')
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`);

-- 14. Products & Multi-tier Pricing
INSERT INTO `products` (`id`, `code`, `sku`, `barcode`, `name`, `category_id`, `brand_id`, `base_unit_id`, `purchase_price`, `wholesale_price`, `retail_price`, `trade_price`, `min_sale_price`, `tax_rate`, `reorder_level`, `is_batch_managed`, `status`) VALUES
(1, 'PRD-001', 'NES-MILK-1L', '8961011001', 'Nestle MilkPak 1 Liter', 1, 1, 1, 260.00, 275.00, 290.00, 270.00, 265.00, 0.00, 50.00, 1, 'active'),
(2, 'PRD-002', 'NES-JUICE-200ML', '8961011002', 'Nestle Fruita Vitals Chaunsa 200ml', 1, 1, 1, 65.00, 72.00, 80.00, 70.00, 68.00, 0.00, 100.00, 1, 'active'),
(3, 'PRD-003', 'EBM-PEEK-FMLY', '8961022001', 'Sooper Biscuit Family Pack', 2, 3, 1, 80.00, 88.00, 100.00, 85.00, 82.00, 0.00, 120.00, 0, 'active'),
(4, 'PRD-004', 'EBM-GLUCOSE', '8961022002', 'Gluco Biscuit Half Roll', 2, 3, 1, 35.00, 40.00, 50.00, 38.00, 36.00, 0.00, 150.00, 0, 'active'),
(5, 'PRD-005', 'LUX-SOAP-140G', '8961033001', 'Lux Beauty Soap Rose 140g', 3, 2, 1, 130.00, 142.00, 160.00, 138.00, 135.00, 0.00, 80.00, 0, 'active'),
(6, 'PRD-006', 'LIFE-SHAMP-180', '8961033002', 'Lifebuoy Shampoo Total Care 180ml', 3, 2, 1, 280.00, 310.00, 350.00, 300.00, 290.00, 0.00, 40.00, 0, 'active')
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`);

-- 15. Initial Stock Balance for Warehouse 1
INSERT INTO `stock_ledger` (`warehouse_id`, `product_id`, `movement_type`, `reference_type`, `reference_id`, `unit_id`, `quantity_in`, `quantity_out`, `balance_quantity`, `unit_cost`, `total_cost`, `notes`) VALUES
(1, 1, 'adjustment_in', 'initial_setup', 1, 1, 500.00, 0.00, 500.00, 260.00, 130000.00, 'Opening inventory load'),
(1, 2, 'adjustment_in', 'initial_setup', 1, 1, 600.00, 0.00, 600.00, 65.00, 39000.00, 'Opening inventory load'),
(1, 3, 'adjustment_in', 'initial_setup', 1, 1, 800.00, 0.00, 800.00, 80.00, 64000.00, 'Opening inventory load'),
(1, 4, 'adjustment_in', 'initial_setup', 1, 1, 1000.00, 0.00, 1000.00, 35.00, 35000.00, 'Opening inventory load'),
(1, 5, 'adjustment_in', 'initial_setup', 1, 1, 400.00, 0.00, 400.00, 130.00, 52000.00, 'Opening inventory load'),
(1, 6, 'adjustment_in', 'initial_setup', 1, 1, 300.00, 0.00, 300.00, 280.00, 84000.00, 'Opening inventory load');

-- Batches for batch managed products
INSERT INTO `product_batches` (`product_id`, `warehouse_id`, `batch_number`, `expiry_date`, `quantity`, `unit_cost`) VALUES
(1, 1, 'BATCH-MP-2026-A', '2027-05-15', 500.00, 260.00),
(2, 1, 'BATCH-FV-2026-B', '2027-03-20', 600.00, 65.00);

-- Initial Journal Entry for Inventory & Capital: Total inventory = Rs. 404,000 + Cash = Rs. 200,000 + Bank = Rs. 500,000 => Capital = Rs. 1,104,000
INSERT INTO `journal_entries` (`id`, `entry_number`, `entry_date`, `reference_type`, `reference_id`, `narration`, `total_debit`, `total_credit`, `posted_by`, `status`) VALUES
(1, 'JE-OPENING-001', '2026-11-01', 'opening_entry', 1, 'Opening balance sheet load for Falcon FMCG DMS', 1104000.00, 1104000.00, 1, 'posted');

INSERT INTO `journal_entry_lines` (`journal_entry_id`, `account_id`, `debit`, `credit`, `memo`) VALUES
(1, 2, 200000.00, 0.00, 'Opening Cash in Hand'),
(1, 3, 500000.00, 0.00, 'Opening Bank Balance'),
(1, 5, 404000.00, 0.00, 'Opening Stock Inventory (404,000)'),
(1, 9, 0.00, 1104000.00, 'Opening Owner Capital Balance');

-- 16. Customers (Retail Shops) in Gujranwala
INSERT INTO `customers` (`id`, `code`, `shop_name`, `owner_name`, `phone`, `whatsapp`, `address`, `area_id`, `sub_area_id`, `customer_type`, `credit_limit`, `opening_balance`, `current_balance`, `payment_terms`, `assigned_order_booker_id`, `status`) VALUES
(1, 'CUST-001', 'Madina Cash & Carry', 'Haji Muhammad Akram', '+92 300 9641234', '+92 300 9641234', 'Shop 12-14, Satellite Town Main Market', 1, 1, 'retailer', 100000.00, 0.00, 0.00, '7 Days Credit', 3, 'active'),
(2, 'CUST-002', 'Bismillah General Store', 'Chaudhry Arshad', '+92 321 8765432', '+92 321 8765432', 'Gali No. 4, Model Town Market', 1, 2, 'retailer', 60000.00, 0.00, 0.00, 'Due on Delivery', 3, 'active'),
(3, 'CUST-003', 'Subhan Wholesale Point', 'Malik Tanveer', '+92 334 1122998', '+92 334 1122998', 'GT Road near Rahwali Gate', 2, 3, 'wholesaler', 150000.00, 0.00, 0.00, '15 Days Credit', 3, 'active'),
(4, 'CUST-004', 'Al-Rehman Super Mart', 'Sheikh Rizwan', '+92 302 3344556', '+92 302 3344556', 'Kamoke Grain Market, Circular Road', 3, 4, 'retailer', 80000.00, 0.00, 0.00, 'Due on Delivery', 3, 'active')
ON DUPLICATE KEY UPDATE `shop_name`=VALUES(`shop_name`);
