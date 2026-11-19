-- ==============================================================================
-- Migration: 2026_11_19_traditional_fmcg_workflow.sql
-- Enterprise FMCG Distribution Management System (DMS)
-- Traditional Desktop & Paper-Assisted FMCG Workflow Setup
-- ==============================================================================

-- 1. Insert & Update Default Operational Settings
INSERT INTO `settings` (`setting_key`, `setting_value`, `description`, `updated_at`) VALUES
('system_operational_mode', 'hybrid', 'System Operational Mode: traditional (Desktop/Paper-Assisted), app_driven (Mobile Apps), hybrid (All options active)', NOW()),
('batch_bills_per_a4', '2', 'Number of commercial sales bills printed per A4 page (2 for half-sheet / A5 size)', NOW()),
('bill_terms_conditions', 'Goods once sold are non-returnable without prior distributor authorization.', 'Sales Bill & Invoice Written Terms of Supply', NOW()),
('gatepass_security_directive', 'Strict outward vehicle gate clearance check before exiting premises.', 'Warehouse Outward Gate Pass Security Clearance Directive', NOW()),
('route_assignment_cadence', 'weekly', 'Territory Route & Area Assignment Cadence: weekly, daily, monthly, fixed', NOW()),
('route_coverage_tracking', '1', 'Shop Visit & Booking Progress Coverage Tracking: 1 (Enabled) or 0 (Disabled)', NOW())
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`), `updated_at` = NOW();

-- 2. Ensure Proper Performance Indexes on Foreign Keys & High-Volume Tables
ALTER TABLE `unit_conversions`
  ADD INDEX IF NOT EXISTS `idx_conv_lookup` (`product_id`, `from_unit_id`, `to_unit_id`);

ALTER TABLE `customers`
  ADD INDEX IF NOT EXISTS `idx_cust_balance` (`current_balance`),
  ADD INDEX IF NOT EXISTS `idx_cust_area_status` (`area_id`, `status`);

ALTER TABLE `suppliers`
  ADD INDEX IF NOT EXISTS `idx_supp_balance` (`current_balance`);

ALTER TABLE `sales_orders`
  ADD INDEX IF NOT EXISTS `idx_so_order_date` (`order_date`, `status`),
  ADD INDEX IF NOT EXISTS `idx_so_area_date` (`area_id`, `order_date`);

ALTER TABLE `delivery_assignments`
  ADD INDEX IF NOT EXISTS `idx_da_driver_date` (`delivery_man_id`, `dispatch_date`);
