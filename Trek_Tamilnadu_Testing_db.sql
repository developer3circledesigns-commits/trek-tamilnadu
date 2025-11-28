-- Forest Trekking System Database
-- Complete SQL file with all tables
-- Clean version without sample data

SET FOREIGN_KEY_CHECKS=0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";

CREATE DATABASE IF NOT EXISTS `Trek_Tamilnadu_Testing_db` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `Trek_Tamilnadu_Testing_db`;

-- --------------------------------------------------------
-- Table structure for table `categories`
-- --------------------------------------------------------

CREATE TABLE `categories` (
  `category_id` int(11) NOT NULL AUTO_INCREMENT,
  `category_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `category_code` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('Active','Inactive') COLLATE utf8mb4_unicode_ci DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`category_id`),
  UNIQUE KEY `category_name` (`category_name`),
  UNIQUE KEY `category_code` (`category_code`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `forest_trails`
-- --------------------------------------------------------

CREATE TABLE `forest_trails` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `trail_code` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `trail_location` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `district_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `division_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `menu_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('Active','Inactive') COLLATE utf8mb4_unicode_ci DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `trail_code` (`trail_code`),
  KEY `status` (`status`),
  KEY `idx_trail_location` (`trail_location`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `suppliers`
-- --------------------------------------------------------

CREATE TABLE `suppliers` (
  `supplier_id` int(11) NOT NULL AUTO_INCREMENT,
  `supplier_code` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `supplier_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact_person` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gstin` varchar(15) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pan_number` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('Active','Inactive') COLLATE utf8mb4_unicode_ci DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`supplier_id`),
  UNIQUE KEY `supplier_code` (`supplier_code`),
  KEY `status` (`status`),
  KEY `idx_supplier_name` (`supplier_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `items_data`
-- --------------------------------------------------------

CREATE TABLE `items_data` (
  `item_id` int(11) NOT NULL AUTO_INCREMENT,
  `item_code` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `item_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `category_id` int(11) DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `quantity` int(11) DEFAULT 0,
  `min_stock_level` int(11) DEFAULT 10,
  `unit_price` decimal(10,2) DEFAULT 0.00,
  `supplier_id` int(11) DEFAULT NULL,
  `status` enum('Active','Inactive') COLLATE utf8mb4_unicode_ci DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`item_id`),
  UNIQUE KEY `item_code` (`item_code`),
  KEY `category_id` (`category_id`),
  KEY `supplier_id` (`supplier_id`),
  KEY `idx_item_name` (`item_name`),
  KEY `status` (`status`),
  KEY `idx_quantity` (`quantity`),
  CONSTRAINT `items_data_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `categories` (`category_id`) ON DELETE SET NULL,
  CONSTRAINT `items_data_ibfk_2` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`supplier_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `item_suppliers`
-- --------------------------------------------------------

CREATE TABLE `item_suppliers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `item_id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `is_primary` tinyint(1) DEFAULT 0,
  `status` enum('Active','Inactive') COLLATE utf8mb4_unicode_ci DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `item_supplier_unique` (`item_id`,`supplier_id`),
  KEY `supplier_id` (`supplier_id`),
  KEY `is_primary` (`is_primary`),
  KEY `status` (`status`),
  CONSTRAINT `item_suppliers_ibfk_1` FOREIGN KEY (`item_id`) REFERENCES `items_data` (`item_id`) ON DELETE CASCADE,
  CONSTRAINT `item_suppliers_ibfk_2` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`supplier_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `trekkers_data_table`
-- --------------------------------------------------------

CREATE TABLE `trekkers_data_table` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `trek_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `trek_route_no` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `trail_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `booking_date` date NOT NULL,
  `trek_date` date NOT NULL,
  `category` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'Easy',
  `status` enum('Paid','Cancelled','Pending') COLLATE utf8mb4_unicode_ci DEFAULT 'Pending',
  `GU001` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `GU002` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `GU003` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `GU004` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `GU005` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `GU006` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `GU007` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `GK001` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `GK002` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `GK003` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `GK004` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `GK005` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `GK006` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `GK007` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `GK008` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `GK009` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `GK010` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `GK011` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `GK012` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `GK013` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `CK001` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `CK002` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `CK003` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `CK004` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `CK005` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `CK006` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `TR001` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `TR002` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `TR003` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `TR004` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `TR005` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `TR006` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `TR007` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `TR008` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `trek_id` (`trek_id`),
  KEY `idx_trek_id` (`trek_id`),
  KEY `idx_trek_route_no` (`trek_route_no`),
  KEY `idx_status` (`status`),
  KEY `idx_trek_date` (`trek_date`),
  KEY `idx_booking_date` (`booking_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `trail_inventory`
-- --------------------------------------------------------

CREATE TABLE `trail_inventory` (
  `inventory_id` int(11) NOT NULL AUTO_INCREMENT,
  `trail_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `current_stock` int(11) DEFAULT 0,
  `min_stock_level` int(11) DEFAULT 5,
  `max_stock_level` int(11) DEFAULT 100,
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`inventory_id`),
  UNIQUE KEY `unique_trail_item` (`trail_id`, `item_id`),
  KEY `trail_id` (`trail_id`),
  KEY `item_id` (`item_id`),
  KEY `idx_current_stock` (`current_stock`),
  CONSTRAINT `trail_inventory_ibfk_1` FOREIGN KEY (`trail_id`) REFERENCES `forest_trails` (`id`) ON DELETE CASCADE,
  CONSTRAINT `trail_inventory_ibfk_2` FOREIGN KEY (`item_id`) REFERENCES `items_data` (`item_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `stock_transfers`
-- --------------------------------------------------------

CREATE TABLE `stock_transfers` (
  `transfer_id` int(11) NOT NULL AUTO_INCREMENT,
  `transfer_code` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `from_trail_id` int(11) NOT NULL,
  `to_trail_id` int(11) NOT NULL,
  `transfer_reason` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `total_items` int(11) DEFAULT 0,
  `total_quantity` int(11) DEFAULT 0,
  `status` enum('Pending','In Progress','Completed','Cancelled','Returned') COLLATE utf8mb4_unicode_ci DEFAULT 'Pending',
  `created_by` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`transfer_id`),
  UNIQUE KEY `transfer_code` (`transfer_code`),
  KEY `from_trail_id` (`from_trail_id`),
  KEY `to_trail_id` (`to_trail_id`),
  KEY `status` (`status`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `stock_transfers_ibfk_1` FOREIGN KEY (`from_trail_id`) REFERENCES `forest_trails` (`id`) ON DELETE CASCADE,
  CONSTRAINT `stock_transfers_ibfk_2` FOREIGN KEY (`to_trail_id`) REFERENCES `forest_trails` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `transfer_items`
-- --------------------------------------------------------

CREATE TABLE `transfer_items` (
  `transfer_item_id` int(11) NOT NULL AUTO_INCREMENT,
  `transfer_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `item_code` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `item_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `category_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `requested_quantity` int(11) NOT NULL,
  `available_quantity` int(11) DEFAULT 0,
  `transferred_quantity` int(11) DEFAULT 0,
  `item_remarks` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`transfer_item_id`),
  KEY `transfer_id` (`transfer_id`),
  KEY `item_id` (`item_id`),
  CONSTRAINT `transfer_items_ibfk_1` FOREIGN KEY (`transfer_id`) REFERENCES `stock_transfers` (`transfer_id`) ON DELETE CASCADE,
  CONSTRAINT `transfer_items_ibfk_2` FOREIGN KEY (`item_id`) REFERENCES `items_data` (`item_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `stock_movements`
-- --------------------------------------------------------

CREATE TABLE `stock_movements` (
  `movement_id` int(11) NOT NULL AUTO_INCREMENT,
  `item_id` int(11) NOT NULL,
  `from_trail_id` int(11) DEFAULT NULL,
  `to_trail_id` int(11) DEFAULT NULL,
  `quantity` int(11) NOT NULL,
  `movement_type` enum('TRANSFER_OUT','TRANSFER_IN','TRANSFER_REVERSAL','ADJUSTMENT','STATUS_CHANGE') COLLATE utf8mb4_unicode_ci NOT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`movement_id`),
  KEY `item_id` (`item_id`),
  KEY `from_trail_id` (`from_trail_id`),
  KEY `to_trail_id` (`to_trail_id`),
  KEY `movement_type` (`movement_type`),
  KEY `reference_id` (`reference_id`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `stock_movements_ibfk_1` FOREIGN KEY (`item_id`) REFERENCES `items_data` (`item_id`) ON DELETE CASCADE,
  CONSTRAINT `stock_movements_ibfk_2` FOREIGN KEY (`from_trail_id`) REFERENCES `forest_trails` (`id`) ON DELETE SET NULL,
  CONSTRAINT `stock_movements_ibfk_3` FOREIGN KEY (`to_trail_id`) REFERENCES `forest_trails` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `purchase_orders`
-- --------------------------------------------------------

CREATE TABLE `purchase_orders` (
  `po_id` int(11) NOT NULL AUTO_INCREMENT,
  `po_no` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `proforma_invoice_date` date NOT NULL,
  `proforma_invoice_no` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `supplier_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `supplier_code` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `delivery_address` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `remarks` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('Draft','Pending','Approved','Rejected','Completed','Partially Received','Cancelled') COLLATE utf8mb4_unicode_ci DEFAULT 'Draft',
  `total_items` int(11) NOT NULL DEFAULT 0,
  `total_quantity` int(11) NOT NULL DEFAULT 0,
  `total_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`po_id`),
  UNIQUE KEY `po_no` (`po_no`),
  KEY `supplier_id` (`supplier_id`),
  KEY `status` (`status`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `purchase_orders_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`supplier_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `po_items`
-- --------------------------------------------------------

CREATE TABLE `po_items` (
  `po_item_id` int(11) NOT NULL AUTO_INCREMENT,
  `po_no` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `item_id` int(11) NOT NULL,
  `item_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `item_code` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `category_id` int(11) DEFAULT NULL,
  `category_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `category_code` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `quantity` int(11) NOT NULL DEFAULT 0,
  `price_per_unit` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `received_quantity` int(11) DEFAULT 0,
  `damaged_quantity` int(11) DEFAULT 0,
  `stock_quantity` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`po_item_id`),
  KEY `po_no` (`po_no`),
  KEY `item_id` (`item_id`),
  KEY `idx_category_id` (`category_id`),
  CONSTRAINT `po_items_ibfk_1` FOREIGN KEY (`po_no`) REFERENCES `purchase_orders` (`po_no`) ON DELETE CASCADE,
  CONSTRAINT `po_items_ibfk_2` FOREIGN KEY (`item_id`) REFERENCES `items_data` (`item_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `order_status`
-- --------------------------------------------------------

CREATE TABLE `order_status` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `po_no` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `category_id` int(11) NOT NULL,
  `category_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `category_code` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `item_id` int(11) NOT NULL,
  `item_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `item_code` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `total_quantity` int(11) NOT NULL,
  `received_quantity` int(11) NOT NULL DEFAULT 0,
  `damaged_quantity` int(11) NOT NULL DEFAULT 0,
  `stock_quantity` int(11) NOT NULL DEFAULT 0,
  `status` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Pending',
  `remarks` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_po_item` (`po_no`, `item_id`),
  KEY `idx_po_no` (`po_no`),
  KEY `idx_item_id` (`item_id`),
  KEY `idx_status` (`status`),
  KEY `idx_updated_at` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `stock_inventory`
-- --------------------------------------------------------

CREATE TABLE `stock_inventory` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `item_id` int(11) NOT NULL,
  `item_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `item_code` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `category_id` int(11) NOT NULL,
  `category_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `category_code` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `current_stock` int(11) NOT NULL DEFAULT 0,
  `min_stock_level` int(11) NOT NULL DEFAULT 10,
  `max_stock_level` int(11) DEFAULT NULL,
  `unit_price` decimal(10,2) DEFAULT 0.00,
  `total_value` decimal(12,2) DEFAULT 0.00,
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_by` varchar(100) DEFAULT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `supplier_name` varchar(255) DEFAULT NULL,
  `location` varchar(255) DEFAULT 'Main Store',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `remarks` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_item` (`item_id`),
  KEY `idx_item_code` (`item_code`),
  KEY `idx_category_id` (`category_id`),
  KEY `idx_category_code` (`category_code`),
  KEY `idx_current_stock` (`current_stock`),
  KEY `idx_last_updated` (`last_updated`),
  KEY `idx_is_active` (`is_active`),
  KEY `idx_supplier_id` (`supplier_id`),
  KEY `idx_location` (`location`),
  KEY `idx_stock_level` (`current_stock`, `min_stock_level`),
  CONSTRAINT `stock_inventory_ibfk_1` FOREIGN KEY (`item_id`) REFERENCES `items_data` (`item_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `food_menu`
-- --------------------------------------------------------

CREATE TABLE `food_menu` (
  `menu_id` int(11) NOT NULL AUTO_INCREMENT,
  `menu_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `menu_code` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `items_included` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cost_per_person` decimal(8,2) DEFAULT 0.00,
  `status` enum('Active','Inactive') COLLATE utf8mb4_unicode_ci DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`menu_id`),
  UNIQUE KEY `menu_name` (`menu_name`),
  UNIQUE KEY `menu_code` (`menu_code`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `users`
-- --------------------------------------------------------

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `full_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` enum('Super Admin','Admin','Manager','Staff') COLLATE utf8mb4_unicode_ci DEFAULT 'Staff',
  `department` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('Active','Inactive') COLLATE utf8mb4_unicode_ci DEFAULT 'Active',
  `last_login` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`),
  KEY `role` (`role`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `reports`
-- --------------------------------------------------------

CREATE TABLE `reports` (
  `report_id` int(11) NOT NULL AUTO_INCREMENT,
  `report_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `report_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `report_data` longtext COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `generated_by` int(11) DEFAULT NULL,
  `generated_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `parameters` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`report_id`),
  KEY `report_type` (`report_type`),
  KEY `generated_by` (`generated_by`),
  KEY `idx_generated_at` (`generated_at`),
  CONSTRAINT `reports_ibfk_1` FOREIGN KEY (`generated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `system_logs`
-- --------------------------------------------------------

CREATE TABLE `system_logs` (
  `log_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`log_id`),
  KEY `user_id` (`user_id`),
  KEY `action` (`action`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `system_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `settings`
-- --------------------------------------------------------

CREATE TABLE `settings` (
  `setting_id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `setting_value` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `setting_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'text',
  `description` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_id`),
  UNIQUE KEY `setting_key` (`setting_key`),
  KEY `updated_by` (`updated_by`),
  CONSTRAINT `settings_ibfk_1` FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Views for common queries
-- --------------------------------------------------------

CREATE OR REPLACE VIEW `vw_stock_levels` AS
SELECT 
    i.item_id,
    i.item_code,
    i.item_name,
    c.category_name,
    i.quantity,
    i.min_stock_level,
    CASE 
        WHEN i.quantity = 0 THEN 'Out of Stock'
        WHEN i.quantity <= i.min_stock_level THEN 'Low Stock'
        ELSE 'In Stock'
    END as stock_status,
    i.status
FROM items_data i
LEFT JOIN categories c ON i.category_id = c.category_id;

CREATE OR REPLACE VIEW `vw_trail_statistics` AS
SELECT 
    t.trail_code,
    t.trail_location,
    t.district_name,
    COUNT(td.id) as total_trekkers,
    SUM(CASE WHEN td.status = 'Paid' THEN 1 ELSE 0 END) as paid_trekkers,
    SUM(CASE WHEN td.status = 'Pending' THEN 1 ELSE 0 END) as pending_trekkers
FROM forest_trails t
LEFT JOIN trekkers_data_table td ON t.trail_code = td.trek_route_no
GROUP BY t.trail_code, t.trail_location, t.district_name;

CREATE OR REPLACE VIEW `vw_supplier_items` AS
SELECT 
    s.supplier_id,
    s.supplier_code,
    s.supplier_name,
    s.contact_person,
    s.email,
    s.phone,
    s.status as supplier_status,
    COUNT(i.item_id) as total_items,
    SUM(i.quantity) as total_stock_quantity
FROM suppliers s
LEFT JOIN items_data i ON s.supplier_id = i.supplier_id
GROUP BY s.supplier_id, s.supplier_code, s.supplier_name, s.contact_person, s.email, s.phone, s.status;

-- --------------------------------------------------------
-- Indexes for better performance
-- --------------------------------------------------------

CREATE INDEX `idx_items_category` ON `items_data` (`category_id`, `status`);
CREATE INDEX `idx_items_supplier` ON `items_data` (`supplier_id`, `status`);
CREATE INDEX `idx_trekkers_dates` ON `trekkers_data_table` (`booking_date`, `trek_date`, `status`);
CREATE INDEX `idx_trails_status` ON `forest_trails` (`status`, `district_name`);
CREATE INDEX `idx_suppliers_status` ON `suppliers` (`status`);
CREATE INDEX `idx_suppliers_code` ON `suppliers` (`supplier_code`);
CREATE INDEX `idx_orders_status` ON `purchase_orders` (`status`, `created_at`);
CREATE INDEX `idx_users_role` ON `users` (`role`, `status`);

-- --------------------------------------------------------
-- Triggers for automated updates
-- --------------------------------------------------------

DELIMITER //

CREATE TRIGGER `update_stock_inventory_on_item_update` 
AFTER UPDATE ON `items_data`
FOR EACH ROW
BEGIN
    IF OLD.quantity != NEW.quantity OR OLD.unit_price != NEW.unit_price THEN
        INSERT INTO stock_inventory (item_id, item_name, item_code, category_id, category_name, category_code, current_stock, min_stock_level, total_value, unit_price)
        SELECT 
            NEW.item_id, 
            NEW.item_name, 
            NEW.item_code, 
            NEW.category_id, 
            c.category_name, 
            c.category_code, 
            NEW.quantity, 
            NEW.min_stock_level,
            NEW.quantity * NEW.unit_price,
            NEW.unit_price
        FROM categories c 
        WHERE c.category_id = NEW.category_id
        ON DUPLICATE KEY UPDATE 
            current_stock = NEW.quantity,
            min_stock_level = NEW.min_stock_level,
            total_value = NEW.quantity * NEW.unit_price,
            unit_price = NEW.unit_price,
            last_updated = CURRENT_TIMESTAMP;
    END IF;
END//

CREATE TRIGGER `insert_stock_inventory_on_new_item` 
AFTER INSERT ON `items_data`
FOR EACH ROW
BEGIN
    INSERT INTO stock_inventory (item_id, item_name, item_code, category_id, category_name, category_code, current_stock, min_stock_level, total_value, unit_price)
    SELECT 
        NEW.item_id, 
        NEW.item_name, 
        NEW.item_code, 
        NEW.category_id, 
        c.category_name, 
        c.category_code, 
        NEW.quantity, 
        NEW.min_stock_level,
        NEW.quantity * NEW.unit_price,
        NEW.unit_price
    FROM categories c 
    WHERE c.category_id = NEW.category_id;
END//

-- Trigger to generate supplier code if not provided
CREATE TRIGGER `before_supplier_insert`
BEFORE INSERT ON `suppliers`
FOR EACH ROW
BEGIN
    IF NEW.supplier_code IS NULL OR NEW.supplier_code = '' THEN
        SET @next_number = (SELECT COALESCE(MAX(CAST(SUBSTRING(supplier_code, 4) AS UNSIGNED)), 0) + 1 FROM suppliers WHERE supplier_code LIKE 'SUP%');
        SET NEW.supplier_code = CONCAT('SUP', LPAD(@next_number, 3, '0'));
    END IF;
END//

DELIMITER ;

SET FOREIGN_KEY_CHECKS=1;
COMMIT;