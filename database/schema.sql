-- ============================================================================
-- Enterprise Distribution Management System (DMS)
-- Database Schema (MySQL 8+ / InnoDB / UTF8mb4)
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- 1. Companies & Business Settings
DROP TABLE IF EXISTS `companies`;
CREATE TABLE `companies` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(150) NOT NULL,
    `legal_name` VARCHAR(200) NULL,
    `email` VARCHAR(100) NULL,
    `phone` VARCHAR(50) NULL,
    `address` TEXT NULL,
    `city` VARCHAR(100) DEFAULT 'Gujranwala',
    `ntn` VARCHAR(50) NULL,
    `strn` VARCHAR(50) NULL,
    `currency` VARCHAR(10) DEFAULT 'PKR',
    `currency_symbol` VARCHAR(10) DEFAULT 'Rs.',
    `financial_year_start` DATE NULL,
    `invoice_prefix` VARCHAR(10) DEFAULT 'INV-',
    `purchase_prefix` VARCHAR(10) DEFAULT 'PO-',
    `order_prefix` VARCHAR(10) DEFAULT 'SO-',
    `delivery_prefix` VARCHAR(10) DEFAULT 'DC-',
    `receipt_prefix` VARCHAR(10) DEFAULT 'RCP-',
    `default_warehouse_id` INT UNSIGNED NULL,
    `inventory_valuation` ENUM('WAC', 'FIFO') DEFAULT 'WAC',
    `allow_negative_stock` TINYINT(1) DEFAULT 0,
    `status` ENUM('active', 'inactive') DEFAULT 'active',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `settings`;
CREATE TABLE `settings` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `setting_key` VARCHAR(100) NOT NULL UNIQUE,
    `setting_value` TEXT NULL,
    `description` VARCHAR(255) NULL,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Roles & Granular Permissions (RBAC)
DROP TABLE IF EXISTS `roles`;
CREATE TABLE `roles` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `slug` VARCHAR(100) NOT NULL UNIQUE,
    `description` VARCHAR(255) NULL,
    `is_system` TINYINT(1) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `permissions`;
CREATE TABLE `permissions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `module` VARCHAR(50) NOT NULL,
    `action` VARCHAR(50) NOT NULL,
    `description` VARCHAR(200) NULL,
    UNIQUE KEY `uk_module_action` (`module`, `action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `role_permissions`;
CREATE TABLE `role_permissions` (
    `role_id` INT UNSIGNED NOT NULL,
    `permission_id` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`role_id`, `permission_id`),
    CONSTRAINT `fk_rp_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rp_perm` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Users & Employees
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `role_id` INT UNSIGNED NOT NULL,
    `username` VARCHAR(80) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `full_name` VARCHAR(150) NOT NULL,
    `email` VARCHAR(120) NULL,
    `phone` VARCHAR(50) NULL,
    `status` ENUM('active', 'inactive', 'suspended') DEFAULT 'active',
    `last_login_at` DATETIME NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `employees`;
CREATE TABLE `employees` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NULL UNIQUE,
    `employee_code` VARCHAR(50) NOT NULL UNIQUE,
    `full_name` VARCHAR(150) NOT NULL,
    `phone` VARCHAR(50) NOT NULL,
    `email` VARCHAR(100) NULL,
    `designation` VARCHAR(100) NOT NULL,
    `role_type` ENUM('super_admin', 'admin', 'accountant', 'sales_manager', 'salesman', 'order_booker', 'delivery_manager', 'delivery_man', 'warehouse_manager', 'storekeeper', 'purchase_manager') NOT NULL,
    `salary` DECIMAL(12,2) DEFAULT 0.00,
    `commission_type` ENUM('none', 'percentage_sales', 'fixed_per_order', 'percentage_collected') DEFAULT 'none',
    `commission_rate` DECIMAL(8,2) DEFAULT 0.00,
    `status` ENUM('active', 'inactive') DEFAULT 'active',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_emp_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Geographic Areas & Assignments
DROP TABLE IF EXISTS `areas`;
CREATE TABLE `areas` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(120) NOT NULL,
    `code` VARCHAR(50) NOT NULL UNIQUE,
    `parent_id` INT UNSIGNED NULL,
    `city` VARCHAR(100) DEFAULT 'Gujranwala',
    `description` TEXT NULL,
    `assigned_order_booker_id` INT UNSIGNED NULL,
    `assigned_salesman_id` INT UNSIGNED NULL,
    `assigned_rider_id` INT UNSIGNED NULL,
    `status` ENUM('active', 'inactive') DEFAULT 'active',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_area_parent` FOREIGN KEY (`parent_id`) REFERENCES `areas` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_area_ob` FOREIGN KEY (`assigned_order_booker_id`) REFERENCES `employees` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_area_sm` FOREIGN KEY (`assigned_salesman_id`) REFERENCES `employees` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_area_rider` FOREIGN KEY (`assigned_rider_id`) REFERENCES `employees` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `sub_areas`;
CREATE TABLE `sub_areas` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `area_id` INT UNSIGNED NOT NULL,
    `name` VARCHAR(120) NOT NULL,
    `code` VARCHAR(50) NULL,
    `status` ENUM('active', 'inactive') DEFAULT 'active',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_subarea_area` FOREIGN KEY (`area_id`) REFERENCES `areas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `employee_area_assignments`;
CREATE TABLE `employee_area_assignments` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `employee_id` INT UNSIGNED NOT NULL,
    `area_id` INT UNSIGNED NOT NULL,
    `assignment_type` ENUM('fixed', 'daily', 'weekly', 'monthly') NOT NULL DEFAULT 'fixed',
    `schedule_key` VARCHAR(50) NOT NULL DEFAULT 'ALL',
    `day_of_week` VARCHAR(20) NOT NULL DEFAULT 'Flexible',
    `role_type` ENUM('order_booker', 'delivery_man', 'salesman') NOT NULL DEFAULT 'order_booker',
    `status` ENUM('active', 'completed', 'inactive') NOT NULL DEFAULT 'active',
    `notes` VARCHAR(255) NULL,
    `assigned_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_emp_area` (`employee_id`, `area_id`),
    CONSTRAINT `fk_eaa_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_eaa_area` FOREIGN KEY (`area_id`) REFERENCES `areas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Warehouses
DROP TABLE IF EXISTS `warehouses`;
CREATE TABLE `warehouses` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(50) NOT NULL UNIQUE,
    `name` VARCHAR(150) NOT NULL,
    `address` TEXT NULL,
    `contact_person` VARCHAR(100) NULL,
    `phone` VARCHAR(50) NULL,
    `is_default` TINYINT(1) DEFAULT 0,
    `status` ENUM('active', 'inactive') DEFAULT 'active',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Units & Conversions
DROP TABLE IF EXISTS `units`;
CREATE TABLE `units` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(50) NOT NULL,
    `code` VARCHAR(20) NOT NULL UNIQUE,
    `is_base` TINYINT(1) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `unit_conversions`;
CREATE TABLE `unit_conversions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `product_id` INT UNSIGNED NULL, -- NULL means global unit conversion
    `from_unit_id` INT UNSIGNED NOT NULL,
    `to_unit_id` INT UNSIGNED NOT NULL,
    `multiplier` DECIMAL(12,4) NOT NULL, -- 1 Carton = 12 Boxes
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_uc_from` FOREIGN KEY (`from_unit_id`) REFERENCES `units` (`id`),
    CONSTRAINT `fk_uc_to` FOREIGN KEY (`to_unit_id`) REFERENCES `units` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Categories & Brands
DROP TABLE IF EXISTS `categories`;
CREATE TABLE `categories` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `code` VARCHAR(50) NOT NULL UNIQUE,
    `parent_id` INT UNSIGNED NULL,
    `status` ENUM('active', 'inactive') DEFAULT 'active',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_cat_parent` FOREIGN KEY (`parent_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `brands`;
CREATE TABLE `brands` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `code` VARCHAR(50) NOT NULL UNIQUE,
    `status` ENUM('active', 'inactive') DEFAULT 'active',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Products & Pricing
DROP TABLE IF EXISTS `products`;
CREATE TABLE `products` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(50) NOT NULL UNIQUE,
    `sku` VARCHAR(80) NULL,
    `barcode` VARCHAR(80) NULL,
    `name` VARCHAR(200) NOT NULL,
    `category_id` INT UNSIGNED NOT NULL,
    `brand_id` INT UNSIGNED NULL,
    `base_unit_id` INT UNSIGNED NOT NULL,
    `purchase_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `wholesale_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `retail_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `trade_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `min_sale_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `tax_rate` DECIMAL(5,2) DEFAULT 0.00,
    `reorder_level` DECIMAL(10,2) DEFAULT 10.00,
    `is_batch_managed` TINYINT(1) DEFAULT 0,
    `image` VARCHAR(255) NULL,
    `description` TEXT NULL,
    `status` ENUM('active', 'inactive') DEFAULT 'active',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_prod_cat` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`),
    CONSTRAINT `fk_prod_brand` FOREIGN KEY (`brand_id`) REFERENCES `brands` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_prod_unit` FOREIGN KEY (`base_unit_id`) REFERENCES `units` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `product_batches`;
CREATE TABLE `product_batches` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `product_id` INT UNSIGNED NOT NULL,
    `warehouse_id` INT UNSIGNED NOT NULL,
    `batch_number` VARCHAR(80) NOT NULL,
    `expiry_date` DATE NULL,
    `quantity` DECIMAL(12,2) DEFAULT 0.00,
    `unit_cost` DECIMAL(12,2) DEFAULT 0.00,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_pb_prod` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_pb_wh` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Suppliers
DROP TABLE IF EXISTS `suppliers`;
CREATE TABLE `suppliers` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(50) NOT NULL UNIQUE,
    `name` VARCHAR(150) NOT NULL,
    `company_name` VARCHAR(180) NULL,
    `contact_person` VARCHAR(100) NULL,
    `phone` VARCHAR(50) NOT NULL,
    `whatsapp` VARCHAR(50) NULL,
    `email` VARCHAR(100) NULL,
    `address` TEXT NULL,
    `city` VARCHAR(100) DEFAULT 'Gujranwala',
    `area` VARCHAR(100) NULL,
    `opening_balance` DECIMAL(12,2) DEFAULT 0.00,
    `current_balance` DECIMAL(12,2) DEFAULT 0.00,
    `credit_limit` DECIMAL(12,2) DEFAULT 0.00,
    `payment_terms` VARCHAR(100) DEFAULT 'Net 30',
    `ntn_strn` VARCHAR(50) NULL,
    `notes` TEXT NULL,
    `status` ENUM('active', 'inactive') DEFAULT 'active',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Customers / Retail Shops
DROP TABLE IF EXISTS `customers`;
CREATE TABLE `customers` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(50) NOT NULL UNIQUE,
    `shop_name` VARCHAR(180) NOT NULL,
    `owner_name` VARCHAR(120) NOT NULL,
    `phone` VARCHAR(50) NOT NULL,
    `whatsapp` VARCHAR(50) NULL,
    `address` TEXT NOT NULL,
    `area_id` INT UNSIGNED NOT NULL,
    `sub_area_id` INT UNSIGNED NULL,
    `gps_lat` DECIMAL(10,8) NULL,
    `gps_lng` DECIMAL(11,8) NULL,
    `customer_type` ENUM('retailer', 'wholesaler', 'distributor', 'corporate', 'cash', 'credit') DEFAULT 'retailer',
    `credit_limit` DECIMAL(12,2) DEFAULT 50000.00,
    `opening_balance` DECIMAL(12,2) DEFAULT 0.00,
    `current_balance` DECIMAL(12,2) DEFAULT 0.00,
    `payment_terms` VARCHAR(100) DEFAULT 'Due on Delivery',
    `assigned_order_booker_id` INT UNSIGNED NULL,
    `assigned_salesman_id` INT UNSIGNED NULL,
    `notes` TEXT NULL,
    `status` ENUM('active', 'inactive', 'struck_off') DEFAULT 'active',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_cust_area` FOREIGN KEY (`area_id`) REFERENCES `areas` (`id`),
    CONSTRAINT `fk_cust_subarea` FOREIGN KEY (`sub_area_id`) REFERENCES `sub_areas` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_cust_ob` FOREIGN KEY (`assigned_order_booker_id`) REFERENCES `employees` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_cust_sm` FOREIGN KEY (`assigned_salesman_id`) REFERENCES `employees` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `customer_visits`;
CREATE TABLE `customer_visits` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `customer_id` INT UNSIGNED NOT NULL,
    `employee_id` INT UNSIGNED NOT NULL,
    `visit_date` DATE NOT NULL,
    `purpose` VARCHAR(150) NULL,
    `notes` TEXT NULL,
    `gps_lat` DECIMAL(10,8) NULL,
    `gps_lng` DECIMAL(11,8) NULL,
    `has_order` TINYINT(1) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_cv_cust` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
    CONSTRAINT `fk_cv_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. Chart of Accounts & Double-Entry Accounting
DROP TABLE IF EXISTS `chart_of_accounts`;
CREATE TABLE `chart_of_accounts` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(50) NOT NULL UNIQUE,
    `name` VARCHAR(150) NOT NULL,
    `group_type` ENUM('Asset', 'Liability', 'Equity', 'Income', 'Contra_Income', 'Expense', 'Cost_Of_Sales') NOT NULL,
    `parent_id` INT UNSIGNED NULL,
    `is_active` TINYINT(1) DEFAULT 1,
    `system_tag` VARCHAR(50) NULL UNIQUE, -- e.g. cash, bank, receivables, inventory, payables, sales_revenue, cogs
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_coa_parent` FOREIGN KEY (`parent_id`) REFERENCES `chart_of_accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `journal_entries`;
CREATE TABLE `journal_entries` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `entry_number` VARCHAR(50) NOT NULL UNIQUE,
    `entry_date` DATE NOT NULL,
    `reference_type` VARCHAR(50) NOT NULL, -- e.g. purchase, grn, sales_invoice, customer_payment, expense, sales_return
    `reference_id` INT UNSIGNED NULL,
    `narration` TEXT NOT NULL,
    `total_debit` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `total_credit` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `posted_by` INT UNSIGNED NULL,
    `status` ENUM('draft', 'posted', 'voided') DEFAULT 'posted',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_je_posted_by` FOREIGN KEY (`posted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `journal_entry_lines`;
CREATE TABLE `journal_entry_lines` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `journal_entry_id` INT UNSIGNED NOT NULL,
    `account_id` INT UNSIGNED NOT NULL,
    `debit` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `credit` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `memo` VARCHAR(255) NULL,
    CONSTRAINT `fk_jel_entry` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_jel_account` FOREIGN KEY (`account_id`) REFERENCES `chart_of_accounts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. Stock Movements & Ledger
DROP TABLE IF EXISTS `stock_ledger`;
CREATE TABLE `stock_ledger` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `warehouse_id` INT UNSIGNED NOT NULL,
    `product_id` INT UNSIGNED NOT NULL,
    `batch_id` INT UNSIGNED NULL,
    `movement_type` ENUM('purchase_grn', 'sale_dispatch', 'sale_return', 'purchase_return', 'adjustment_in', 'adjustment_out', 'transfer_in', 'transfer_out') NOT NULL,
    `reference_type` VARCHAR(50) NOT NULL, -- e.g. goods_receipt, delivery_assignment, sales_return
    `reference_id` INT UNSIGNED NOT NULL,
    `unit_id` INT UNSIGNED NOT NULL,
    `quantity_in` DECIMAL(12,2) DEFAULT 0.00,
    `quantity_out` DECIMAL(12,2) DEFAULT 0.00,
    `balance_quantity` DECIMAL(12,2) DEFAULT 0.00,
    `unit_cost` DECIMAL(12,2) DEFAULT 0.00,
    `total_cost` DECIMAL(14,2) DEFAULT 0.00,
    `notes` VARCHAR(255) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_sl_wh` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`),
    CONSTRAINT `fk_sl_prod` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
    CONSTRAINT `fk_sl_unit` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `stock_reservations`;
CREATE TABLE `stock_reservations` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `order_id` INT UNSIGNED NOT NULL,
    `product_id` INT UNSIGNED NOT NULL,
    `warehouse_id` INT UNSIGNED NOT NULL,
    `reserved_qty` DECIMAL(12,2) NOT NULL,
    `status` ENUM('active', 'released', 'fulfilled') DEFAULT 'active',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. Purchases & Goods Receipt Notes (GRN)
DROP TABLE IF EXISTS `purchase_orders`;
CREATE TABLE `purchase_orders` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `po_number` VARCHAR(50) NOT NULL UNIQUE,
    `supplier_id` INT UNSIGNED NOT NULL,
    `warehouse_id` INT UNSIGNED NOT NULL,
    `po_date` DATE NOT NULL,
    `expected_date` DATE NULL,
    `reference_no` VARCHAR(100) NULL,
    `subtotal` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `discount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `tax` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `freight` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `other_charges` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `grand_total` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `status` ENUM('draft', 'submitted', 'approved', 'partially_received', 'received', 'cancelled') DEFAULT 'submitted',
    `notes` TEXT NULL,
    `created_by` INT UNSIGNED NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_po_sup` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`),
    CONSTRAINT `fk_po_wh` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`),
    CONSTRAINT `fk_po_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `purchase_items`;
CREATE TABLE `purchase_items` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `purchase_order_id` INT UNSIGNED NOT NULL,
    `product_id` INT UNSIGNED NOT NULL,
    `unit_id` INT UNSIGNED NOT NULL,
    `order_qty` DECIMAL(12,2) NOT NULL,
    `received_qty` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `unit_cost` DECIMAL(12,2) NOT NULL,
    `discount_amount` DECIMAL(12,2) DEFAULT 0.00,
    `tax_amount` DECIMAL(12,2) DEFAULT 0.00,
    `total_cost` DECIMAL(14,2) NOT NULL,
    CONSTRAINT `fk_pi_po` FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_pi_prod` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
    CONSTRAINT `fk_pi_unit` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `goods_receipts`;
CREATE TABLE `goods_receipts` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `grn_number` VARCHAR(50) NOT NULL UNIQUE,
    `purchase_order_id` INT UNSIGNED NULL,
    `supplier_id` INT UNSIGNED NOT NULL,
    `warehouse_id` INT UNSIGNED NOT NULL,
    `grn_date` DATE NOT NULL,
    `invoice_no` VARCHAR(100) NULL,
    `invoice_date` DATE NULL,
    `total_amount` DECIMAL(14,2) NOT NULL,
    `freight_cost` DECIMAL(12,2) DEFAULT 0.00,
    `landed_cost` DECIMAL(14,2) NOT NULL,
    `notes` TEXT NULL,
    `posted_to_accounts` TINYINT(1) DEFAULT 1,
    `created_by` INT UNSIGNED NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_grn_po` FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_grn_sup` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`),
    CONSTRAINT `fk_grn_wh` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `goods_receipt_items`;
CREATE TABLE `goods_receipt_items` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `goods_receipt_id` INT UNSIGNED NOT NULL,
    `product_id` INT UNSIGNED NOT NULL,
    `batch_number` VARCHAR(80) NULL,
    `expiry_date` DATE NULL,
    `unit_id` INT UNSIGNED NOT NULL,
    `received_qty` DECIMAL(12,2) NOT NULL,
    `unit_cost` DECIMAL(12,2) NOT NULL,
    `total_cost` DECIMAL(14,2) NOT NULL,
    CONSTRAINT `fk_gri_grn` FOREIGN KEY (`goods_receipt_id`) REFERENCES `goods_receipts` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_gri_prod` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
    CONSTRAINT `fk_gri_unit` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `purchase_returns`;
CREATE TABLE `purchase_returns` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `return_number` VARCHAR(50) NOT NULL UNIQUE,
    `supplier_id` INT UNSIGNED NOT NULL,
    `warehouse_id` INT UNSIGNED NOT NULL,
    `goods_receipt_id` INT UNSIGNED NULL,
    `return_date` DATE NOT NULL,
    `total_amount` DECIMAL(14,2) NOT NULL,
    `reason` TEXT NULL,
    `status` ENUM('draft', 'posted', 'voided') DEFAULT 'posted',
    `created_by` INT UNSIGNED NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_pr_sup` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`),
    CONSTRAINT `fk_pr_wh` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14. Sales Orders & Status Management
DROP TABLE IF EXISTS `sales_orders`;
CREATE TABLE `sales_orders` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `order_number` VARCHAR(50) NOT NULL UNIQUE,
    `customer_id` INT UNSIGNED NOT NULL,
    `order_booker_id` INT UNSIGNED NOT NULL,
    `area_id` INT UNSIGNED NOT NULL,
    `order_date` DATE NOT NULL,
    `delivery_date` DATE NULL,
    `subtotal` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `discount_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `tax_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `delivery_charges` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `grand_total` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `status` ENUM('draft', 'submitted', 'pending_approval', 'approved', 'rejected', 'processing', 'ready_for_dispatch', 'dispatched', 'partially_delivered', 'delivered', 'cancelled') DEFAULT 'submitted',
    `payment_type` ENUM('cash', 'credit') DEFAULT 'credit',
    `price_tier` ENUM('trade_price', 'wholesale_price', 'retail_price') DEFAULT 'trade_price',
    `notes` TEXT NULL,
    `approved_by` INT UNSIGNED NULL,
    `approved_at` DATETIME NULL,
    `sync_id` VARCHAR(100) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_so_cust` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
    CONSTRAINT `fk_so_ob` FOREIGN KEY (`order_booker_id`) REFERENCES `employees` (`id`),
    CONSTRAINT `fk_so_area` FOREIGN KEY (`area_id`) REFERENCES `areas` (`id`),
    CONSTRAINT `fk_so_appby` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `sales_order_items`;
CREATE TABLE `sales_order_items` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `sales_order_id` INT UNSIGNED NOT NULL,
    `product_id` INT UNSIGNED NOT NULL,
    `unit_id` INT UNSIGNED NOT NULL,
    `unit_price` DECIMAL(12,2) NOT NULL,
    `quantity` DECIMAL(12,2) NOT NULL,
    `discount` DECIMAL(12,2) DEFAULT 0.00,
    `tax` DECIMAL(12,2) DEFAULT 0.00,
    `line_total` DECIMAL(14,2) NOT NULL,
    `dispatched_qty` DECIMAL(12,2) DEFAULT 0.00,
    `delivered_qty` DECIMAL(12,2) DEFAULT 0.00,
    CONSTRAINT `fk_soi_so` FOREIGN KEY (`sales_order_id`) REFERENCES `sales_orders` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_soi_prod` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
    CONSTRAINT `fk_soi_unit` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `order_status_history`;
CREATE TABLE `order_status_history` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `sales_order_id` INT UNSIGNED NOT NULL,
    `from_status` VARCHAR(50) NULL,
    `to_status` VARCHAR(50) NOT NULL,
    `remarks` VARCHAR(255) NULL,
    `changed_by` INT UNSIGNED NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_osh_so` FOREIGN KEY (`sales_order_id`) REFERENCES `sales_orders` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_osh_user` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 15. Warehouse Dispatch & Delivery Management
DROP TABLE IF EXISTS `delivery_assignments`;
CREATE TABLE `delivery_assignments` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `dispatch_number` VARCHAR(50) NOT NULL UNIQUE,
    `sales_order_id` INT UNSIGNED NOT NULL,
    `delivery_man_id` INT UNSIGNED NOT NULL,
    `warehouse_id` INT UNSIGNED NOT NULL,
    `vehicle_no` VARCHAR(50) NULL,
    `route_name` VARCHAR(100) NULL,
    `dispatch_date` DATE NOT NULL,
    `status` ENUM('assigned', 'in_transit', 'delivered', 'partially_delivered', 'returned', 'cancelled') DEFAULT 'assigned',
    `notes` TEXT NULL,
    `dispatched_by` INT UNSIGNED NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_da_so` FOREIGN KEY (`sales_order_id`) REFERENCES `sales_orders` (`id`),
    CONSTRAINT `fk_da_dm` FOREIGN KEY (`delivery_man_id`) REFERENCES `employees` (`id`),
    CONSTRAINT `fk_da_wh` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `delivery_items`;
CREATE TABLE `delivery_items` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `delivery_assignment_id` INT UNSIGNED NOT NULL,
    `sales_order_item_id` INT UNSIGNED NOT NULL,
    `product_id` INT UNSIGNED NOT NULL,
    `unit_id` INT UNSIGNED NOT NULL,
    `dispatched_qty` DECIMAL(12,2) NOT NULL,
    `delivered_qty` DECIMAL(12,2) DEFAULT 0.00,
    `returned_qty` DECIMAL(12,2) DEFAULT 0.00,
    `short_qty` DECIMAL(12,2) DEFAULT 0.00,
    `damaged_qty` DECIMAL(12,2) DEFAULT 0.00,
    `return_reason` VARCHAR(150) NULL,
    `unit_price` DECIMAL(12,2) NOT NULL,
    `total_amount` DECIMAL(14,2) NOT NULL,
    CONSTRAINT `fk_di_da` FOREIGN KEY (`delivery_assignment_id`) REFERENCES `delivery_assignments` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_di_soi` FOREIGN KEY (`sales_order_item_id`) REFERENCES `sales_order_items` (`id`),
    CONSTRAINT `fk_di_prod` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `delivery_proofs`;
CREATE TABLE `delivery_proofs` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `delivery_assignment_id` INT UNSIGNED NOT NULL,
    `customer_id` INT UNSIGNED NOT NULL,
    `receiver_name` VARCHAR(120) NULL,
    `receiver_phone` VARCHAR(50) NULL,
    `signature_image` VARCHAR(255) NULL,
    `photo_proof` VARCHAR(255) NULL,
    `notes` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_dp_da` FOREIGN KEY (`delivery_assignment_id`) REFERENCES `delivery_assignments` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_dp_cust` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 16. Sales Invoices
DROP TABLE IF EXISTS `sales_invoices`;
CREATE TABLE `sales_invoices` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `invoice_number` VARCHAR(50) NOT NULL UNIQUE,
    `sales_order_id` INT UNSIGNED NOT NULL,
    `customer_id` INT UNSIGNED NOT NULL,
    `warehouse_id` INT UNSIGNED NOT NULL,
    `invoice_date` DATE NOT NULL,
    `due_date` DATE NULL,
    `subtotal` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `discount_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `tax_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `delivery_charges` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `grand_total` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `cost_of_goods` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `paid_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `balance_due` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `status` ENUM('posted', 'partially_paid', 'paid', 'voided') DEFAULT 'posted',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_si_so` FOREIGN KEY (`sales_order_id`) REFERENCES `sales_orders` (`id`),
    CONSTRAINT `fk_si_cust` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
    CONSTRAINT `fk_si_wh` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `sales_invoice_items`;
CREATE TABLE `sales_invoice_items` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `sales_invoice_id` INT UNSIGNED NOT NULL,
    `product_id` INT UNSIGNED NOT NULL,
    `unit_id` INT UNSIGNED NOT NULL,
    `quantity` DECIMAL(12,2) NOT NULL,
    `unit_price` DECIMAL(12,2) NOT NULL,
    `unit_cost` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `discount` DECIMAL(12,2) DEFAULT 0.00,
    `tax` DECIMAL(12,2) DEFAULT 0.00,
    `line_total` DECIMAL(14,2) NOT NULL,
    CONSTRAINT `fk_sii_si` FOREIGN KEY (`sales_invoice_id`) REFERENCES `sales_invoices` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_sii_prod` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 17. Sales Returns & Credit Notes
DROP TABLE IF EXISTS `sales_returns`;
CREATE TABLE `sales_returns` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `return_number` VARCHAR(50) NOT NULL UNIQUE,
    `sales_invoice_id` INT UNSIGNED NULL,
    `customer_id` INT UNSIGNED NOT NULL,
    `warehouse_id` INT UNSIGNED NOT NULL,
    `return_date` DATE NOT NULL,
    `total_amount` DECIMAL(14,2) NOT NULL,
    `return_destination` ENUM('good_stock', 'damaged_stock', 'expired_stock', 'quarantine') DEFAULT 'good_stock',
    `status` ENUM('draft', 'approved', 'rejected') DEFAULT 'approved',
    `reason` TEXT NULL,
    `created_by` INT UNSIGNED NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_sr_si` FOREIGN KEY (`sales_invoice_id`) REFERENCES `sales_invoices` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_sr_cust` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
    CONSTRAINT `fk_sr_wh` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `sales_return_items`;
CREATE TABLE `sales_return_items` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `sales_return_id` INT UNSIGNED NOT NULL,
    `product_id` INT UNSIGNED NOT NULL,
    `unit_id` INT UNSIGNED NOT NULL,
    `quantity` DECIMAL(12,2) NOT NULL,
    `unit_price` DECIMAL(12,2) NOT NULL,
    `unit_cost` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `total_amount` DECIMAL(14,2) NOT NULL,
    `condition_type` VARCHAR(50) DEFAULT 'good',
    CONSTRAINT `fk_sri_sr` FOREIGN KEY (`sales_return_id`) REFERENCES `sales_returns` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_sri_prod` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `credit_notes`;
CREATE TABLE `credit_notes` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `credit_note_number` VARCHAR(50) NOT NULL UNIQUE,
    `sales_return_id` INT UNSIGNED NOT NULL,
    `customer_id` INT UNSIGNED NOT NULL,
    `amount` DECIMAL(14,2) NOT NULL,
    `note_date` DATE NOT NULL,
    `status` ENUM('active', 'applied', 'voided') DEFAULT 'active',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_cn_sr` FOREIGN KEY (`sales_return_id`) REFERENCES `sales_returns` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_cn_cust` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 18. Payment Methods, Collections & Handovers
DROP TABLE IF EXISTS `payment_methods`;
CREATE TABLE `payment_methods` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(80) NOT NULL,
    `code` VARCHAR(50) NOT NULL UNIQUE, -- cash, bank_transfer, jazzcash, easypaisa, cheque
    `account_id` INT UNSIGNED NOT NULL,
    `is_active` TINYINT(1) DEFAULT 1,
    CONSTRAINT `fk_pm_acc` FOREIGN KEY (`account_id`) REFERENCES `chart_of_accounts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `payments`;
CREATE TABLE `payments` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `payment_number` VARCHAR(50) NOT NULL UNIQUE,
    `payment_type` ENUM('customer_receipt', 'supplier_payment', 'expense_payment') NOT NULL,
    `reference_type` VARCHAR(50) NOT NULL, -- invoice, grn, advance, delivery
    `reference_id` INT UNSIGNED NULL,
    `party_type` ENUM('customer', 'supplier', 'employee', 'other') NOT NULL,
    `party_id` INT UNSIGNED NOT NULL,
    `amount` DECIMAL(14,2) NOT NULL,
    `payment_method_id` INT UNSIGNED NOT NULL,
    `payment_method_code` VARCHAR(50) NOT NULL,
    `payment_account_id` INT UNSIGNED NOT NULL,
    `payment_date` DATE NOT NULL,
    `reference_no` VARCHAR(100) NULL,
    `proof_file` VARCHAR(255) NULL,
    `collected_by` INT UNSIGNED NULL,
    `handover_status` ENUM('not_applicable', 'pending_handover', 'handed_over', 'reconciled') DEFAULT 'not_applicable',
    `notes` TEXT NULL,
    `status` ENUM('draft', 'posted', 'voided') DEFAULT 'posted',
    `created_by` INT UNSIGNED NULL,
    `sync_id` VARCHAR(100) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_pay_method` FOREIGN KEY (`payment_method_id`) REFERENCES `payment_methods` (`id`),
    CONSTRAINT `fk_pay_acc` FOREIGN KEY (`payment_account_id`) REFERENCES `chart_of_accounts` (`id`),
    CONSTRAINT `fk_pay_collector` FOREIGN KEY (`collected_by`) REFERENCES `employees` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `payment_allocations`;
CREATE TABLE `payment_allocations` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `payment_id` INT UNSIGNED NOT NULL,
    `invoice_id` INT UNSIGNED NOT NULL,
    `allocated_amount` DECIMAL(14,2) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_pa_pay` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_pa_inv` FOREIGN KEY (`invoice_id`) REFERENCES `sales_invoices` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `cash_handovers`;
CREATE TABLE `cash_handovers` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `handover_number` VARCHAR(50) NOT NULL UNIQUE,
    `delivery_man_id` INT UNSIGNED NOT NULL,
    `handover_date` DATE NOT NULL,
    `cash_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `digital_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `cheque_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `total_collected` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `cash_deposited` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `difference_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `status` ENUM('pending', 'verified', 'rejected') DEFAULT 'pending',
    `verified_by` INT UNSIGNED NULL,
    `verified_at` DATETIME NULL,
    `notes` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_ch_dm` FOREIGN KEY (`delivery_man_id`) REFERENCES `employees` (`id`),
    CONSTRAINT `fk_ch_verby` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 18b. Official Company Bank Accounts & Payment Receiving Wallets
DROP TABLE IF EXISTS `company_accounts`;
CREATE TABLE `company_accounts` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `account_type` ENUM('bank', 'wallet', 'raast', 'other') NOT NULL DEFAULT 'bank',
    `bank_name` VARCHAR(120) NOT NULL,
    `account_title` VARCHAR(150) NOT NULL,
    `account_number` VARCHAR(100) NOT NULL,
    `iban` VARCHAR(50) NULL,
    `branch_name` VARCHAR(150) NULL,
    `branch_code` VARCHAR(50) NULL,
    `raast_id` VARCHAR(100) NULL,
    `qr_code_image` VARCHAR(255) NULL,
    `notes` TEXT NULL,
    `is_primary` TINYINT(1) DEFAULT 0,
    `is_active` TINYINT(1) DEFAULT 1,
    `show_on_bills` TINYINT(1) DEFAULT 1,
    `display_order` INT DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 19. Expenses & Categories
DROP TABLE IF EXISTS `expense_categories`;
CREATE TABLE `expense_categories` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `code` VARCHAR(50) NOT NULL UNIQUE,
    `account_id` INT UNSIGNED NOT NULL,
    `status` ENUM('active', 'inactive') DEFAULT 'active',
    CONSTRAINT `fk_ec_acc` FOREIGN KEY (`account_id`) REFERENCES `chart_of_accounts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `expenses`;
CREATE TABLE `expenses` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `expense_number` VARCHAR(50) NOT NULL UNIQUE,
    `category_id` INT UNSIGNED NOT NULL,
    `expense_date` DATE NOT NULL,
    `amount` DECIMAL(14,2) NOT NULL,
    `payment_account_id` INT UNSIGNED NOT NULL,
    `payment_method` VARCHAR(50) DEFAULT 'Cash',
    `reference_no` VARCHAR(100) NULL,
    `description` TEXT NOT NULL,
    `attachment` VARCHAR(255) NULL,
    `entered_by` INT UNSIGNED NULL,
    `approved_by` INT UNSIGNED NULL,
    `status` ENUM('pending', 'approved', 'rejected') DEFAULT 'approved',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_exp_cat` FOREIGN KEY (`category_id`) REFERENCES `expense_categories` (`id`),
    CONSTRAINT `fk_exp_acc` FOREIGN KEY (`payment_account_id`) REFERENCES `chart_of_accounts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 20. Stock Adjustments & Transfers
DROP TABLE IF EXISTS `stock_adjustments`;
CREATE TABLE `stock_adjustments` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `adjustment_number` VARCHAR(50) NOT NULL UNIQUE,
    `warehouse_id` INT UNSIGNED NOT NULL,
    `adjustment_date` DATE NOT NULL,
    `adjustment_type` ENUM('in', 'out') NOT NULL,
    `reason` VARCHAR(255) NOT NULL,
    `total_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `status` ENUM('draft', 'posted', 'voided') DEFAULT 'posted',
    `created_by` INT UNSIGNED NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_sa_wh` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `stock_adjustment_items`;
CREATE TABLE `stock_adjustment_items` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `stock_adjustment_id` INT UNSIGNED NOT NULL,
    `product_id` INT UNSIGNED NOT NULL,
    `batch_id` INT UNSIGNED NULL,
    `unit_id` INT UNSIGNED NOT NULL,
    `quantity` DECIMAL(12,2) NOT NULL,
    `unit_cost` DECIMAL(12,2) NOT NULL,
    `total_cost` DECIMAL(14,2) NOT NULL,
    CONSTRAINT `fk_sai_sa` FOREIGN KEY (`stock_adjustment_id`) REFERENCES `stock_adjustments` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_sai_prod` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `stock_transfers`;
CREATE TABLE `stock_transfers` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `transfer_number` VARCHAR(50) NOT NULL UNIQUE,
    `from_warehouse_id` INT UNSIGNED NOT NULL,
    `to_warehouse_id` INT UNSIGNED NOT NULL,
    `transfer_date` DATE NOT NULL,
    `status` ENUM('pending', 'in_transit', 'completed', 'cancelled') DEFAULT 'completed',
    `notes` TEXT NULL,
    `created_by` INT UNSIGNED NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_st_from` FOREIGN KEY (`from_warehouse_id`) REFERENCES `warehouses` (`id`),
    CONSTRAINT `fk_st_to` FOREIGN KEY (`to_warehouse_id`) REFERENCES `warehouses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `stock_transfer_items`;
CREATE TABLE `stock_transfer_items` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `stock_transfer_id` INT UNSIGNED NOT NULL,
    `product_id` INT UNSIGNED NOT NULL,
    `unit_id` INT UNSIGNED NOT NULL,
    `quantity` DECIMAL(12,2) NOT NULL,
    CONSTRAINT `fk_sti_st` FOREIGN KEY (`stock_transfer_id`) REFERENCES `stock_transfers` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_sti_prod` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 21. Audit Trail & Offline Synchronization Queue
DROP TABLE IF EXISTS `audit_logs`;
CREATE TABLE `audit_logs` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NULL,
    `module` VARCHAR(50) NOT NULL,
    `record_id` INT UNSIGNED NULL,
    `action` VARCHAR(50) NOT NULL,
    `previous_data` JSON NULL,
    `new_data` JSON NULL,
    `ip_address` VARCHAR(45) NULL,
    `user_agent` VARCHAR(255) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_audit_module_rec` (`module`, `record_id`),
    INDEX `idx_audit_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `offline_sync_queue`;
CREATE TABLE `offline_sync_queue` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `sync_uuid` VARCHAR(80) NOT NULL UNIQUE,
    `client_device_id` VARCHAR(100) NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `entity_type` VARCHAR(50) NOT NULL, -- e.g. order, delivery_collection, customer_visit, new_shop
    `payload` JSON NOT NULL,
    `status` ENUM('pending', 'processed', 'conflict', 'failed') DEFAULT 'pending',
    `error_message` TEXT NULL,
    `server_record_id` INT UNSIGNED NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `processed_at` DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `daily_closings`;
CREATE TABLE `daily_closings` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `closing_date` DATE NOT NULL UNIQUE,
    `opening_cash` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `total_sales` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `total_cost` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `gross_profit` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `cash_collected` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `expenses_paid` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `supplier_payments` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `expected_cash` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `actual_cash` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `cash_difference` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `orders_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `status` ENUM('open', 'closed') NOT NULL DEFAULT 'closed',
    `notes` TEXT NULL,
    `closed_by` INT UNSIGNED NULL,
    `closed_at` DATETIME NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_dc_closed_by` FOREIGN KEY (`closed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
