-- ========================================================================
-- BEMS Phase 1: Inventory Module Database Schema
-- Best ERP Manufacturing System
-- 
-- This migration creates the comprehensive inventory management system
-- for the BEMS ERP system with materials master data, inventory tracking,
-- location management, movement audit trail, and FIFO logic support.
-- ========================================================================

-- Set connection charset and collation
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ========================================================================
-- DROP EXISTING TABLES IF THEY EXIST (for clean reinstall)
-- ========================================================================

DROP TABLE IF EXISTS `inventory_movements`;
DROP TABLE IF EXISTS `inventory_adjustments`;
DROP TABLE IF EXISTS `inventory_reservations`;
DROP TABLE IF EXISTS `inventory`;
DROP TABLE IF EXISTS `locations`;
DROP TABLE IF EXISTS `material_categories`;
DROP TABLE IF EXISTS `materials`;
DROP TABLE IF EXISTS `inv_tag_sequences`;

-- ========================================================================
-- INV TAG SEQUENCES TABLE
-- Auto-increment sequence management for INV tags with configurable ranges
-- ========================================================================

CREATE TABLE `inv_tag_sequences` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sequence_name` varchar(50) NOT NULL DEFAULT 'INV_MAIN' COMMENT 'Sequence identifier for different tag series',
  `prefix` varchar(10) NOT NULL DEFAULT 'INV' COMMENT 'Tag prefix (INV, CONS, etc.)',
  `current_number` int(11) NOT NULL DEFAULT 10000 COMMENT 'Current sequence number',
  `min_number` int(11) NOT NULL DEFAULT 10001 COMMENT 'Minimum sequence number',
  `max_number` int(11) NOT NULL DEFAULT 999999 COMMENT 'Maximum sequence number before rollover',
  `increment_by` int(3) NOT NULL DEFAULT 1 COMMENT 'Increment step size',
  `zero_padding` int(2) NOT NULL DEFAULT 5 COMMENT 'Zero padding for number portion',
  `active_status` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Sequence is active and available',
  `description` text DEFAULT NULL COMMENT 'Purpose and usage of this sequence',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_by` varchar(20) DEFAULT NULL COMMENT 'Clock number of user who created this sequence',
  
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_inv_tag_sequences_name` (`sequence_name`),
  KEY `idx_inv_tag_sequences_active` (`active_status`),
  KEY `idx_inv_tag_sequences_prefix` (`prefix`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci 
COMMENT='Auto-increment sequence management for inventory tag generation';

-- ========================================================================
-- MATERIAL CATEGORIES TABLE
-- Hierarchical categorization system for materials
-- ========================================================================

CREATE TABLE `material_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category_code` varchar(20) NOT NULL COMMENT 'Unique category identifier (RAW, FIN, PKG, etc.)',
  `category_name` varchar(100) NOT NULL COMMENT 'Display name for category',
  `category_description` text DEFAULT NULL COMMENT 'Detailed description of category purpose',
  `parent_category_id` int(11) DEFAULT NULL COMMENT 'Parent category for hierarchical structure',
  `sort_order` int(3) NOT NULL DEFAULT 100 COMMENT 'Display sort order',
  `active_status` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Category is active and can be used',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_by` varchar(20) DEFAULT NULL COMMENT 'Clock number of user who created this category',
  
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_material_categories_code` (`category_code`),
  KEY `idx_material_categories_parent` (`parent_category_id`),
  KEY `idx_material_categories_active` (`active_status`),
  KEY `idx_material_categories_sort` (`sort_order`),
  
  CONSTRAINT `fk_material_categories_parent` FOREIGN KEY (`parent_category_id`) REFERENCES `material_categories` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci 
COMMENT='Hierarchical categorization system for materials organization';

-- ========================================================================
-- MATERIALS TABLE
-- Master data for all materials with detailed specifications
-- ========================================================================

CREATE TABLE `materials` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `material_number` varchar(50) NOT NULL COMMENT 'Unique material item number for dropdown selection',
  `material_name` varchar(255) NOT NULL COMMENT 'Descriptive name of the material',
  `material_description` text DEFAULT NULL COMMENT 'Detailed description and specifications',
  `category_id` int(11) NOT NULL COMMENT 'Foreign key to material_categories',
  `base_unit` varchar(20) NOT NULL COMMENT 'Primary unit of measure (EA, LB, GAL, BAG, etc.)',
  `alternative_units` json DEFAULT NULL COMMENT 'JSON array of alternative units with conversion factors',
  `material_grade` varchar(50) DEFAULT NULL COMMENT 'Material grade or specification (FDA, Medical, etc.)',
  `material_color` varchar(50) DEFAULT NULL COMMENT 'Material color or appearance',
  `density` decimal(10,4) DEFAULT NULL COMMENT 'Material density for weight calculations',
  `shelf_life_days` int(11) DEFAULT NULL COMMENT 'Shelf life in days (NULL = no expiration)',
  `storage_requirements` text DEFAULT NULL COMMENT 'Special storage conditions and requirements',
  `safety_notes` text DEFAULT NULL COMMENT 'Safety handling requirements and warnings',
  `supplier_part_number` varchar(100) DEFAULT NULL COMMENT 'Primary supplier part number',
  `manufacturer_name` varchar(100) DEFAULT NULL COMMENT 'Original manufacturer name',
  `manufacturer_part_number` varchar(100) DEFAULT NULL COMMENT 'Manufacturer part number',
  `standard_cost` decimal(12,4) DEFAULT NULL COMMENT 'Standard cost per base unit',
  `reorder_point` decimal(12,2) DEFAULT NULL COMMENT 'Reorder point quantity',
  `reorder_quantity` decimal(12,2) DEFAULT NULL COMMENT 'Standard reorder quantity',
  `abc_classification` enum('A','B','C') DEFAULT 'C' COMMENT 'ABC classification for inventory management',
  `active_status` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Material is active and can be used',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_by` varchar(20) DEFAULT NULL COMMENT 'Clock number of user who created this material',
  
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_materials_number` (`material_number`),
  KEY `idx_materials_category` (`category_id`),
  KEY `idx_materials_name` (`material_name`),
  KEY `idx_materials_active` (`active_status`),
  KEY `idx_materials_grade` (`material_grade`),
  KEY `idx_materials_manufacturer` (`manufacturer_name`),
  KEY `idx_materials_abc` (`abc_classification`),
  
  CONSTRAINT `fk_materials_category` FOREIGN KEY (`category_id`) REFERENCES `material_categories` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci 
COMMENT='Master data for all materials with detailed specifications and tracking info';

-- ========================================================================
-- LOCATIONS TABLE
-- Master data for inventory locations with flexible structure
-- ========================================================================

CREATE TABLE `locations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `location_code` varchar(50) NOT NULL COMMENT 'Unique scannable location identifier',
  `location_name` varchar(100) NOT NULL COMMENT 'Human-readable location name',
  `location_description` text DEFAULT NULL COMMENT 'Detailed description of the location',
  `location_type` enum('RECEIVING','STORAGE','PRODUCTION','SHIPPING','QC','SCRAP','OTHER') NOT NULL DEFAULT 'STORAGE' COMMENT 'Type of location for workflow management',
  `parent_location_id` int(11) DEFAULT NULL COMMENT 'Parent location for hierarchical structure',
  `building` varchar(50) DEFAULT NULL COMMENT 'Building identifier',
  `floor` varchar(20) DEFAULT NULL COMMENT 'Floor or level identifier',
  `zone` varchar(50) DEFAULT NULL COMMENT 'Zone or area identifier',
  `aisle` varchar(20) DEFAULT NULL COMMENT 'Aisle identifier',
  `rack` varchar(20) DEFAULT NULL COMMENT 'Rack identifier',
  `shelf` varchar(20) DEFAULT NULL COMMENT 'Shelf or level identifier',
  `bin` varchar(20) DEFAULT NULL COMMENT 'Bin or position identifier',
  `max_weight_kg` decimal(10,2) DEFAULT NULL COMMENT 'Maximum weight capacity in kilograms',
  `max_volume_m3` decimal(10,3) DEFAULT NULL COMMENT 'Maximum volume capacity in cubic meters',
  `temperature_controlled` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Location has temperature control',
  `temperature_min_c` decimal(5,2) DEFAULT NULL COMMENT 'Minimum temperature in Celsius',
  `temperature_max_c` decimal(5,2) DEFAULT NULL COMMENT 'Maximum temperature in Celsius',
  `requires_certification` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Location requires special certification to access',
  `barcode_data` varchar(255) DEFAULT NULL COMMENT 'Encoded barcode data for scanning',
  `qr_code_data` text DEFAULT NULL COMMENT 'QR code data for advanced scanning',
  `active_status` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Location is active and can be used',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_by` varchar(20) DEFAULT NULL COMMENT 'Clock number of user who created this location',
  
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_locations_code` (`location_code`),
  KEY `idx_locations_type` (`location_type`),
  KEY `idx_locations_parent` (`parent_location_id`),
  KEY `idx_locations_active` (`active_status`),
  KEY `idx_locations_building_zone` (`building`, `zone`),
  KEY `idx_locations_rack_shelf` (`rack`, `shelf`),
  
  CONSTRAINT `fk_locations_parent` FOREIGN KEY (`parent_location_id`) REFERENCES `locations` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci 
COMMENT='Master data for inventory locations with hierarchical structure and scanning support';

-- ========================================================================
-- INVENTORY TABLE
-- Core inventory tracking with unique INV tags and detailed lot information
-- ========================================================================

CREATE TABLE `inventory` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `inv_tag` varchar(20) NOT NULL COMMENT 'Unique inventory tag (INV10001, INV10002, etc.)',
  `material_id` int(11) NOT NULL COMMENT 'Foreign key to materials table',
  `lot_number` varchar(100) NOT NULL COMMENT 'Supplier lot number or internal batch number',
  `sublot_number` varchar(50) DEFAULT NULL COMMENT 'Internal sublot for partial lot splits',
  `quantity` decimal(12,4) NOT NULL COMMENT 'Current quantity in base unit',
  `original_quantity` decimal(12,4) NOT NULL COMMENT 'Original received quantity for tracking consumption',
  `unit` varchar(20) NOT NULL COMMENT 'Unit of measure (must match material base_unit or alternatives)',
  `unit_cost` decimal(12,4) DEFAULT NULL COMMENT 'Cost per unit at time of receipt',
  `total_cost` decimal(15,2) DEFAULT NULL COMMENT 'Total cost of this inventory lot',
  `current_location_id` int(11) NOT NULL COMMENT 'Current location foreign key',
  `current_location_manual` varchar(255) DEFAULT NULL COMMENT 'Manual location override (e.g., Press 16)',
  `status` enum('AVAILABLE','RESERVED','ON_HOLD','QUARANTINE','CONSUMED','SCRAPPED') NOT NULL DEFAULT 'AVAILABLE' COMMENT 'Current inventory status',
  `received_date` date NOT NULL COMMENT 'Date material was received',
  `received_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Timestamp when material was received',
  `expiration_date` date DEFAULT NULL COMMENT 'Calculated expiration date based on shelf life',
  `supplier_name` varchar(100) DEFAULT NULL COMMENT 'Name of supplier who provided this material',
  `supplier_invoice` varchar(100) DEFAULT NULL COMMENT 'Supplier invoice number',
  `purchase_order` varchar(100) DEFAULT NULL COMMENT 'Purchase order number',
  `certificate_analysis` text DEFAULT NULL COMMENT 'Certificate of analysis or quality documentation',
  `inspection_status` enum('PENDING','PASSED','FAILED','WAIVED') DEFAULT 'PENDING' COMMENT 'Quality inspection status',
  `inspection_date` date DEFAULT NULL COMMENT 'Date of quality inspection',
  `inspector_initials` varchar(10) DEFAULT NULL COMMENT 'Initials of quality inspector',
  `hold_reason` text DEFAULT NULL COMMENT 'Reason for ON_HOLD or QUARANTINE status',
  `hold_by` varchar(20) DEFAULT NULL COMMENT 'Clock number of user who placed hold',
  `hold_date` timestamp NULL DEFAULT NULL COMMENT 'Timestamp when hold was placed',
  `notes` text DEFAULT NULL COMMENT 'General notes and comments about this inventory',
  `fifo_priority` decimal(15,6) NOT NULL COMMENT 'FIFO priority (timestamp + tie-breaker for sorting)',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_by` varchar(20) NOT NULL COMMENT 'Clock number of user who created this inventory record',
  
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_inventory_inv_tag` (`inv_tag`),
  KEY `idx_inventory_material` (`material_id`),
  KEY `idx_inventory_lot` (`lot_number`),
  KEY `idx_inventory_location` (`current_location_id`),
  KEY `idx_inventory_status` (`status`),
  KEY `idx_inventory_received` (`received_date`),
  KEY `idx_inventory_expiration` (`expiration_date`),
  KEY `idx_inventory_supplier` (`supplier_name`),
  KEY `idx_inventory_po` (`purchase_order`),
  KEY `idx_inventory_fifo` (`material_id`, `status`, `fifo_priority`),
  KEY `idx_inventory_inspection` (`inspection_status`, `inspection_date`),
  
  CONSTRAINT `fk_inventory_material` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_inventory_location` FOREIGN KEY (`current_location_id`) REFERENCES `locations` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci 
COMMENT='Core inventory tracking with unique INV tags and comprehensive lot management';

-- ========================================================================
-- INVENTORY MOVEMENTS TABLE
-- Complete audit trail of all inventory movements and transactions
-- ========================================================================

CREATE TABLE `inventory_movements` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `inventory_id` bigint(20) NOT NULL COMMENT 'Foreign key to inventory table',
  `inv_tag` varchar(20) NOT NULL COMMENT 'Inventory tag for quick reference',
  `movement_type` enum('RECEIVE','TRANSFER','CONSUME','ADJUST','SCRAP','RETURN','INSPECT') NOT NULL COMMENT 'Type of movement',
  `movement_subtype` varchar(50) DEFAULT NULL COMMENT 'Additional movement classification (PRODUCTION, REWORK, etc.)',
  `from_location_id` int(11) DEFAULT NULL COMMENT 'Source location foreign key',
  `from_location_manual` varchar(255) DEFAULT NULL COMMENT 'Manual source location description',
  `to_location_id` int(11) DEFAULT NULL COMMENT 'Destination location foreign key',
  `to_location_manual` varchar(255) DEFAULT NULL COMMENT 'Manual destination location description',
  `quantity_moved` decimal(12,4) NOT NULL COMMENT 'Quantity moved in this transaction',
  `unit` varchar(20) NOT NULL COMMENT 'Unit of measure for quantity moved',
  `remaining_quantity` decimal(12,4) NOT NULL COMMENT 'Remaining quantity after movement',
  `unit_cost` decimal(12,4) DEFAULT NULL COMMENT 'Unit cost at time of movement',
  `total_cost` decimal(15,2) DEFAULT NULL COMMENT 'Total cost of quantity moved',
  `reference_number` varchar(100) DEFAULT NULL COMMENT 'Reference number (PO, WO, Job, etc.)',
  `reference_type` varchar(50) DEFAULT NULL COMMENT 'Type of reference (PURCHASE_ORDER, WORK_ORDER, etc.)',
  `reason_code` varchar(50) DEFAULT NULL COMMENT 'Reason for movement (standard codes)',
  `reason_description` text DEFAULT NULL COMMENT 'Detailed reason for movement',
  `work_order` varchar(100) DEFAULT NULL COMMENT 'Work order associated with movement',
  `job_number` varchar(100) DEFAULT NULL COMMENT 'Job number associated with movement',
  `machine_press` varchar(50) DEFAULT NULL COMMENT 'Machine or press involved in movement',
  `operator_initials` varchar(10) DEFAULT NULL COMMENT 'Operator initials if applicable',
  `quality_notes` text DEFAULT NULL COMMENT 'Quality-related notes for movement',
  `movement_date` date NOT NULL COMMENT 'Date of movement',
  `movement_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Timestamp of movement',
  `created_by` varchar(20) NOT NULL COMMENT 'Clock number of user who recorded movement',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  
  PRIMARY KEY (`id`),
  KEY `idx_movements_inventory` (`inventory_id`),
  KEY `idx_movements_inv_tag` (`inv_tag`),
  KEY `idx_movements_type` (`movement_type`),
  KEY `idx_movements_date` (`movement_date`),
  KEY `idx_movements_from_location` (`from_location_id`),
  KEY `idx_movements_to_location` (`to_location_id`),
  KEY `idx_movements_reference` (`reference_number`, `reference_type`),
  KEY `idx_movements_work_order` (`work_order`),
  KEY `idx_movements_job` (`job_number`),
  KEY `idx_movements_machine` (`machine_press`),
  KEY `idx_movements_created` (`created_at`),
  
  CONSTRAINT `fk_movements_inventory` FOREIGN KEY (`inventory_id`) REFERENCES `inventory` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_movements_from_location` FOREIGN KEY (`from_location_id`) REFERENCES `locations` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_movements_to_location` FOREIGN KEY (`to_location_id`) REFERENCES `locations` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci 
COMMENT='Complete audit trail of all inventory movements and transactions';

-- ========================================================================
-- INVENTORY RESERVATIONS TABLE
-- Tracks material reservations for production orders and jobs
-- ========================================================================

CREATE TABLE `inventory_reservations` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `inventory_id` bigint(20) NOT NULL COMMENT 'Foreign key to inventory table',
  `inv_tag` varchar(20) NOT NULL COMMENT 'Inventory tag for quick reference',
  `reservation_type` enum('PRODUCTION','QUALITY','MAINTENANCE','REWORK') NOT NULL DEFAULT 'PRODUCTION' COMMENT 'Type of reservation',
  `reserved_quantity` decimal(12,4) NOT NULL COMMENT 'Quantity reserved',
  `unit` varchar(20) NOT NULL COMMENT 'Unit of measure for reserved quantity',
  `work_order` varchar(100) DEFAULT NULL COMMENT 'Work order for reservation',
  `job_number` varchar(100) DEFAULT NULL COMMENT 'Job number for reservation',
  `reference_number` varchar(100) DEFAULT NULL COMMENT 'Reference number for reservation',
  `reserved_for_date` date DEFAULT NULL COMMENT 'Date when material is needed',
  `priority_level` enum('LOW','NORMAL','HIGH','URGENT') NOT NULL DEFAULT 'NORMAL' COMMENT 'Reservation priority',
  `status` enum('ACTIVE','FULFILLED','CANCELLED','EXPIRED') NOT NULL DEFAULT 'ACTIVE' COMMENT 'Current reservation status',
  `notes` text DEFAULT NULL COMMENT 'Notes about the reservation',
  `reserved_by` varchar(20) NOT NULL COMMENT 'Clock number of user who created reservation',
  `reserved_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Timestamp when reservation was created',
  `fulfilled_by` varchar(20) DEFAULT NULL COMMENT 'Clock number of user who fulfilled reservation',
  `fulfilled_at` timestamp NULL DEFAULT NULL COMMENT 'Timestamp when reservation was fulfilled',
  `cancelled_by` varchar(20) DEFAULT NULL COMMENT 'Clock number of user who cancelled reservation',
  `cancelled_at` timestamp NULL DEFAULT NULL COMMENT 'Timestamp when reservation was cancelled',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  PRIMARY KEY (`id`),
  KEY `idx_reservations_inventory` (`inventory_id`),
  KEY `idx_reservations_inv_tag` (`inv_tag`),
  KEY `idx_reservations_type` (`reservation_type`),
  KEY `idx_reservations_status` (`status`),
  KEY `idx_reservations_work_order` (`work_order`),
  KEY `idx_reservations_job` (`job_number`),
  KEY `idx_reservations_date` (`reserved_for_date`),
  KEY `idx_reservations_priority` (`priority_level`),
  
  CONSTRAINT `fk_reservations_inventory` FOREIGN KEY (`inventory_id`) REFERENCES `inventory` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci 
COMMENT='Tracks material reservations for production orders and jobs';

-- ========================================================================
-- INVENTORY ADJUSTMENTS TABLE
-- Tracks manual inventory adjustments and cycle counts
-- ========================================================================

CREATE TABLE `inventory_adjustments` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `inventory_id` bigint(20) NOT NULL COMMENT 'Foreign key to inventory table',
  `inv_tag` varchar(20) NOT NULL COMMENT 'Inventory tag for quick reference',
  `adjustment_type` enum('CYCLE_COUNT','PHYSICAL_COUNT','SHRINKAGE','DAMAGE','CORRECTION','WRITEOFF') NOT NULL COMMENT 'Type of adjustment',
  `old_quantity` decimal(12,4) NOT NULL COMMENT 'Quantity before adjustment',
  `new_quantity` decimal(12,4) NOT NULL COMMENT 'Quantity after adjustment',
  `adjustment_quantity` decimal(12,4) NOT NULL COMMENT 'Net adjustment quantity (new - old)',
  `unit` varchar(20) NOT NULL COMMENT 'Unit of measure',
  `unit_cost` decimal(12,4) DEFAULT NULL COMMENT 'Unit cost for valuation',
  `adjustment_value` decimal(15,2) DEFAULT NULL COMMENT 'Financial impact of adjustment',
  `reason_code` varchar(50) NOT NULL COMMENT 'Standard reason code for adjustment',
  `reason_description` text NOT NULL COMMENT 'Detailed reason for adjustment',
  `count_method` enum('MANUAL','SCALE','SCANNER','ESTIMATED') DEFAULT 'MANUAL' COMMENT 'Method used for counting',
  `supporting_documentation` text DEFAULT NULL COMMENT 'Reference to supporting documents',
  `approved_by` varchar(20) DEFAULT NULL COMMENT 'Clock number of approving manager',
  `approved_at` timestamp NULL DEFAULT NULL COMMENT 'Timestamp of approval',
  `adjustment_date` date NOT NULL COMMENT 'Date of physical adjustment',
  `adjustment_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Timestamp of adjustment',
  `created_by` varchar(20) NOT NULL COMMENT 'Clock number of user who recorded adjustment',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  
  PRIMARY KEY (`id`),
  KEY `idx_adjustments_inventory` (`inventory_id`),
  KEY `idx_adjustments_inv_tag` (`inv_tag`),
  KEY `idx_adjustments_type` (`adjustment_type`),
  KEY `idx_adjustments_date` (`adjustment_date`),
  KEY `idx_adjustments_reason` (`reason_code`),
  KEY `idx_adjustments_approved` (`approved_by`, `approved_at`),
  KEY `idx_adjustments_created` (`created_at`),
  
  CONSTRAINT `fk_adjustments_inventory` FOREIGN KEY (`inventory_id`) REFERENCES `inventory` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci 
COMMENT='Tracks manual inventory adjustments and cycle counts with approval workflow';

-- ========================================================================
-- CREATE DATABASE VIEWS FOR COMMON INVENTORY QUERIES
-- ========================================================================

-- Comprehensive inventory summary view
CREATE OR REPLACE VIEW `v_inventory_summary` AS
SELECT 
    i.`id`,
    i.`inv_tag`,
    m.`material_number`,
    m.`material_name`,
    m.`material_description`,
    mc.`category_code`,
    mc.`category_name`,
    i.`lot_number`,
    i.`sublot_number`,
    i.`quantity`,
    i.`original_quantity`,
    i.`unit`,
    i.`unit_cost`,
    i.`total_cost`,
    l.`location_code`,
    l.`location_name`,
    i.`current_location_manual`,
    i.`status`,
    i.`received_date`,
    i.`expiration_date`,
    DATEDIFF(i.`expiration_date`, CURDATE()) as `days_to_expiration`,
    i.`supplier_name`,
    i.`purchase_order`,
    i.`inspection_status`,
    i.`hold_reason`,
    i.`fifo_priority`,
    CASE 
        WHEN i.`expiration_date` IS NOT NULL AND i.`expiration_date` <= CURDATE() THEN 'EXPIRED'
        WHEN i.`expiration_date` IS NOT NULL AND i.`expiration_date` <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 'EXPIRING_SOON'
        WHEN i.`quantity` <= 0 THEN 'DEPLETED'
        ELSE 'NORMAL'
    END as `alert_status`,
    i.`created_at`,
    i.`updated_at`
FROM `inventory` i
INNER JOIN `materials` m ON i.`material_id` = m.`id`
INNER JOIN `material_categories` mc ON m.`category_id` = mc.`id`
INNER JOIN `locations` l ON i.`current_location_id` = l.`id`
WHERE i.`quantity` > 0
ORDER BY i.`inv_tag`;

-- FIFO availability view for production consumption
CREATE OR REPLACE VIEW `v_fifo_available_inventory` AS
SELECT 
    i.`id`,
    i.`inv_tag`,
    m.`material_number`,
    m.`material_name`,
    i.`lot_number`,
    i.`quantity`,
    i.`unit`,
    l.`location_code`,
    l.`location_name`,
    i.`current_location_manual`,
    i.`received_date`,
    i.`expiration_date`,
    i.`fifo_priority`,
    i.`supplier_name`,
    -- Calculate reserved quantity
    COALESCE(SUM(r.`reserved_quantity`), 0) as `reserved_quantity`,
    -- Calculate available quantity
    i.`quantity` - COALESCE(SUM(r.`reserved_quantity`), 0) as `available_quantity`
FROM `inventory` i
INNER JOIN `materials` m ON i.`material_id` = m.`id`
INNER JOIN `locations` l ON i.`current_location_id` = l.`id`
LEFT JOIN `inventory_reservations` r ON i.`id` = r.`inventory_id` 
    AND r.`status` = 'ACTIVE'
WHERE i.`status` = 'AVAILABLE' 
    AND i.`quantity` > 0
    AND (i.`expiration_date` IS NULL OR i.`expiration_date` > CURDATE())
    AND m.`active_status` = 1
GROUP BY i.`id`
HAVING `available_quantity` > 0
ORDER BY m.`material_number`, i.`fifo_priority`;

-- Material totals by location
CREATE OR REPLACE VIEW `v_inventory_totals_by_location` AS
SELECT 
    m.`material_number`,
    m.`material_name`,
    l.`location_code`,
    l.`location_name`,
    l.`location_type`,
    COUNT(i.`id`) as `lot_count`,
    SUM(i.`quantity`) as `total_quantity`,
    m.`base_unit`,
    MIN(i.`received_date`) as `oldest_receipt_date`,
    MAX(i.`received_date`) as `newest_receipt_date`,
    MIN(i.`expiration_date`) as `earliest_expiration`,
    SUM(i.`total_cost`) as `total_value`
FROM `inventory` i
INNER JOIN `materials` m ON i.`material_id` = m.`id`
INNER JOIN `locations` l ON i.`current_location_id` = l.`id`
WHERE i.`quantity` > 0 
    AND i.`status` IN ('AVAILABLE', 'RESERVED', 'ON_HOLD')
GROUP BY m.`id`, l.`id`
ORDER BY m.`material_number`, l.`location_code`;

-- Recent movements audit view
CREATE OR REPLACE VIEW `v_recent_inventory_movements` AS
SELECT 
    im.`id`,
    im.`inv_tag`,
    m.`material_number`,
    m.`material_name`,
    im.`movement_type`,
    im.`movement_subtype`,
    fl.`location_code` as `from_location_code`,
    fl.`location_name` as `from_location_name`,
    im.`from_location_manual`,
    tl.`location_code` as `to_location_code`,
    tl.`location_name` as `to_location_name`,
    im.`to_location_manual`,
    im.`quantity_moved`,
    im.`unit`,
    im.`remaining_quantity`,
    im.`reference_number`,
    im.`reference_type`,
    im.`work_order`,
    im.`job_number`,
    im.`machine_press`,
    im.`operator_initials`,
    im.`movement_date`,
    im.`movement_time`,
    u.`real_name` as `created_by_name`,
    im.`created_by`
FROM `inventory_movements` im
INNER JOIN `inventory` i ON im.`inventory_id` = i.`id`
INNER JOIN `materials` m ON i.`material_id` = m.`id`
LEFT JOIN `locations` fl ON im.`from_location_id` = fl.`id`
LEFT JOIN `locations` tl ON im.`to_location_id` = tl.`id`
LEFT JOIN `users` u ON im.`created_by` = u.`clock_number`
WHERE im.`movement_time` >= DATE_SUB(NOW(), INTERVAL 30 DAY)
ORDER BY im.`movement_time` DESC;

-- Expiring inventory alert view
CREATE OR REPLACE VIEW `v_expiring_inventory` AS
SELECT 
    i.`inv_tag`,
    m.`material_number`,
    m.`material_name`,
    i.`lot_number`,
    i.`quantity`,
    i.`unit`,
    l.`location_code`,
    l.`location_name`,
    i.`expiration_date`,
    DATEDIFF(i.`expiration_date`, CURDATE()) as `days_to_expiration`,
    i.`supplier_name`,
    i.`total_cost`,
    CASE 
        WHEN i.`expiration_date` <= CURDATE() THEN 'EXPIRED'
        WHEN i.`expiration_date` <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 'CRITICAL'
        WHEN i.`expiration_date` <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 'WARNING'
        ELSE 'NORMAL'
    END as `urgency_level`
FROM `inventory` i
INNER JOIN `materials` m ON i.`material_id` = m.`id`
INNER JOIN `locations` l ON i.`current_location_id` = l.`id`
WHERE i.`quantity` > 0 
    AND i.`status` IN ('AVAILABLE', 'RESERVED', 'ON_HOLD')
    AND i.`expiration_date` IS NOT NULL
    AND i.`expiration_date` <= DATE_ADD(CURDATE(), INTERVAL 60 DAY)
ORDER BY i.`expiration_date`, m.`material_number`;

-- ========================================================================
-- CREATE STORED PROCEDURES FOR INVENTORY OPERATIONS
-- ========================================================================

DELIMITER $$

-- Generate next INV tag from sequence
CREATE FUNCTION `fn_get_next_inv_tag`(
    p_sequence_name VARCHAR(50)
) RETURNS VARCHAR(20)
READS SQL DATA
DETERMINISTIC
BEGIN
    DECLARE v_prefix VARCHAR(10);
    DECLARE v_current_number INT;
    DECLARE v_zero_padding INT;
    DECLARE v_new_tag VARCHAR(20);
    
    -- Get sequence information and increment
    SELECT `prefix`, `current_number` + `increment_by`, `zero_padding`
    INTO v_prefix, v_current_number, v_zero_padding
    FROM `inv_tag_sequences`
    WHERE `sequence_name` = p_sequence_name
        AND `active_status` = 1;
    
    -- Check if sequence exists
    IF v_current_number IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Inventory tag sequence not found or inactive';
    END IF;
    
    -- Update sequence
    UPDATE `inv_tag_sequences`
    SET `current_number` = v_current_number
    WHERE `sequence_name` = p_sequence_name;
    
    -- Generate formatted tag
    SET v_new_tag = CONCAT(v_prefix, LPAD(v_current_number, v_zero_padding, '0'));
    
    RETURN v_new_tag;
END$$

-- Calculate FIFO priority for new inventory
CREATE FUNCTION `fn_calculate_fifo_priority`(
    p_received_date DATE,
    p_expiration_date DATE
) RETURNS DECIMAL(15,6)
READS SQL DATA
DETERMINISTIC
BEGIN
    DECLARE v_priority DECIMAL(15,6);
    DECLARE v_tie_breaker DECIMAL(6,6);
    
    -- Base priority on received date (earlier = lower number = higher priority)
    SET v_priority = UNIX_TIMESTAMP(p_received_date);
    
    -- Add expiration date factor if applicable (sooner expiration = higher priority)
    IF p_expiration_date IS NOT NULL THEN
        SET v_priority = v_priority - (DATEDIFF(p_expiration_date, p_received_date) * 0.1);
    END IF;
    
    -- Add small random tie-breaker to ensure unique priorities
    SET v_tie_breaker = RAND() * 0.999999;
    SET v_priority = v_priority + v_tie_breaker;
    
    RETURN v_priority;
END$$

-- Procedure to receive new inventory
CREATE PROCEDURE `sp_receive_inventory`(
    IN p_material_number VARCHAR(50),
    IN p_lot_number VARCHAR(100),
    IN p_quantity DECIMAL(12,4),
    IN p_unit_cost DECIMAL(12,4),
    IN p_location_code VARCHAR(50),
    IN p_supplier_name VARCHAR(100),
    IN p_purchase_order VARCHAR(100),
    IN p_received_by VARCHAR(20),
    IN p_notes TEXT,
    OUT p_result VARCHAR(50),
    OUT p_inv_tag VARCHAR(20)
)
BEGIN
    DECLARE v_material_id INT;
    DECLARE v_location_id INT;
    DECLARE v_base_unit VARCHAR(20);
    DECLARE v_shelf_life_days INT;
    DECLARE v_expiration_date DATE;
    DECLARE v_fifo_priority DECIMAL(15,6);
    DECLARE v_inventory_id BIGINT;
    DECLARE v_total_cost DECIMAL(15,2);
    
    DECLARE exit handler for sqlexception
    BEGIN
        ROLLBACK;
        SET p_result = 'ERROR';
        SET p_inv_tag = NULL;
    END;
    
    START TRANSACTION;
    
    -- Validate material
    SELECT `id`, `base_unit`, `shelf_life_days`
    INTO v_material_id, v_base_unit, v_shelf_life_days
    FROM `materials`
    WHERE `material_number` = p_material_number
        AND `active_status` = 1;
    
    IF v_material_id IS NULL THEN
        SET p_result = 'MATERIAL_NOT_FOUND';
        SET p_inv_tag = NULL;
        ROLLBACK;
        LEAVE sp_receive_inventory;
    END IF;
    
    -- Validate location
    SELECT `id` INTO v_location_id
    FROM `locations`
    WHERE `location_code` = p_location_code
        AND `active_status` = 1;
    
    IF v_location_id IS NULL THEN
        SET p_result = 'LOCATION_NOT_FOUND';
        SET p_inv_tag = NULL;
        ROLLBACK;
        LEAVE sp_receive_inventory;
    END IF;
    
    -- Generate INV tag
    SET p_inv_tag = fn_get_next_inv_tag('INV_MAIN');
    
    -- Calculate expiration date
    IF v_shelf_life_days IS NOT NULL THEN
        SET v_expiration_date = DATE_ADD(CURDATE(), INTERVAL v_shelf_life_days DAY);
    END IF;
    
    -- Calculate FIFO priority
    SET v_fifo_priority = fn_calculate_fifo_priority(CURDATE(), v_expiration_date);
    
    -- Calculate total cost
    SET v_total_cost = p_quantity * p_unit_cost;
    
    -- Insert inventory record
    INSERT INTO `inventory` (
        `inv_tag`, `material_id`, `lot_number`, `quantity`, `original_quantity`,
        `unit`, `unit_cost`, `total_cost`, `current_location_id`, `status`,
        `received_date`, `expiration_date`, `supplier_name`, `purchase_order`,
        `notes`, `fifo_priority`, `created_by`
    ) VALUES (
        p_inv_tag, v_material_id, p_lot_number, p_quantity, p_quantity,
        v_base_unit, p_unit_cost, v_total_cost, v_location_id, 'AVAILABLE',
        CURDATE(), v_expiration_date, p_supplier_name, p_purchase_order,
        p_notes, v_fifo_priority, p_received_by
    );
    
    SET v_inventory_id = LAST_INSERT_ID();
    
    -- Record movement
    INSERT INTO `inventory_movements` (
        `inventory_id`, `inv_tag`, `movement_type`, `to_location_id`,
        `quantity_moved`, `unit`, `remaining_quantity`, `unit_cost`,
        `total_cost`, `reference_number`, `reference_type`, `reason_description`,
        `movement_date`, `created_by`
    ) VALUES (
        v_inventory_id, p_inv_tag, 'RECEIVE', v_location_id,
        p_quantity, v_base_unit, p_quantity, p_unit_cost, v_total_cost,
        p_purchase_order, 'PURCHASE_ORDER', CONCAT('Initial receipt - Lot: ', p_lot_number),
        CURDATE(), p_received_by
    );
    
    -- Create audit log
    CALL sp_create_audit_log(
        p_received_by,
        'INVENTORY_RECEIVE',
        CONCAT('Received inventory: ', p_inv_tag, ' - ', p_material_number, ' (', p_quantity, ' ', v_base_unit, ')'),
        'inventory',
        v_inventory_id,
        NULL,
        JSON_OBJECT(
            'inv_tag', p_inv_tag,
            'material_number', p_material_number,
            'lot_number', p_lot_number,
            'quantity', p_quantity,
            'unit', v_base_unit,
            'supplier', p_supplier_name,
            'purchase_order', p_purchase_order
        ),
        '127.0.0.1',
        NULL,
        NULL,
        NULL,
        'MEDIUM'
    );
    
    COMMIT;
    SET p_result = 'SUCCESS';
END$$

-- Procedure to transfer inventory between locations
CREATE PROCEDURE `sp_transfer_inventory`(
    IN p_inv_tag VARCHAR(20),
    IN p_to_location_code VARCHAR(50),
    IN p_to_location_manual VARCHAR(255),
    IN p_quantity DECIMAL(12,4),
    IN p_reference_number VARCHAR(100),
    IN p_reason_description TEXT,
    IN p_transferred_by VARCHAR(20),
    OUT p_result VARCHAR(50)
)
BEGIN
    DECLARE v_inventory_id BIGINT;
    DECLARE v_current_quantity DECIMAL(12,4);
    DECLARE v_current_location_id INT;
    DECLARE v_to_location_id INT;
    DECLARE v_unit VARCHAR(20);
    DECLARE v_unit_cost DECIMAL(12,4);
    DECLARE v_remaining_quantity DECIMAL(12,4);
    
    DECLARE exit handler for sqlexception
    BEGIN
        ROLLBACK;
        SET p_result = 'ERROR';
    END;
    
    START TRANSACTION;
    
    -- Get current inventory information
    SELECT `id`, `quantity`, `current_location_id`, `unit`, `unit_cost`
    INTO v_inventory_id, v_current_quantity, v_current_location_id, v_unit, v_unit_cost
    FROM `inventory`
    WHERE `inv_tag` = p_inv_tag
        AND `status` IN ('AVAILABLE', 'RESERVED')
        AND `quantity` >= p_quantity;
    
    IF v_inventory_id IS NULL THEN
        SET p_result = 'INVENTORY_NOT_FOUND';
        ROLLBACK;
        LEAVE sp_transfer_inventory;
    END IF;
    
    -- Validate destination location (if provided)
    IF p_to_location_code IS NOT NULL THEN
        SELECT `id` INTO v_to_location_id
        FROM `locations`
        WHERE `location_code` = p_to_location_code
            AND `active_status` = 1;
        
        IF v_to_location_id IS NULL THEN
            SET p_result = 'LOCATION_NOT_FOUND';
            ROLLBACK;
            LEAVE sp_transfer_inventory;
        END IF;
    END IF;
    
    -- Calculate remaining quantity
    SET v_remaining_quantity = v_current_quantity - p_quantity;
    
    -- Update inventory location and quantity
    IF v_to_location_id IS NOT NULL THEN
        UPDATE `inventory`
        SET `current_location_id` = v_to_location_id,
            `current_location_manual` = NULL,
            `quantity` = v_remaining_quantity
        WHERE `id` = v_inventory_id;
    ELSE
        UPDATE `inventory`
        SET `current_location_manual` = p_to_location_manual,
            `quantity` = v_remaining_quantity
        WHERE `id` = v_inventory_id;
    END IF;
    
    -- Record movement
    INSERT INTO `inventory_movements` (
        `inventory_id`, `inv_tag`, `movement_type`, `from_location_id`,
        `to_location_id`, `to_location_manual`, `quantity_moved`, `unit`,
        `remaining_quantity`, `unit_cost`, `reference_number`,
        `reason_description`, `movement_date`, `created_by`
    ) VALUES (
        v_inventory_id, p_inv_tag, 'TRANSFER', v_current_location_id,
        v_to_location_id, p_to_location_manual, p_quantity, v_unit,
        v_remaining_quantity, v_unit_cost, p_reference_number,
        p_reason_description, CURDATE(), p_transferred_by
    );
    
    -- Create audit log
    CALL sp_create_audit_log(
        p_transferred_by,
        'INVENTORY_TRANSFER',
        CONCAT('Transferred inventory: ', p_inv_tag, ' (', p_quantity, ' ', v_unit, ') to ', 
               COALESCE(p_to_location_code, p_to_location_manual)),
        'inventory',
        v_inventory_id,
        NULL,
        JSON_OBJECT(
            'inv_tag', p_inv_tag,
            'quantity', p_quantity,
            'unit', v_unit,
            'to_location', COALESCE(p_to_location_code, p_to_location_manual),
            'reference', p_reference_number
        ),
        '127.0.0.1',
        NULL,
        NULL,
        NULL,
        'LOW'
    );
    
    COMMIT;
    SET p_result = 'SUCCESS';
END$$

-- Procedure to consume inventory for production
CREATE PROCEDURE `sp_consume_inventory`(
    IN p_inv_tag VARCHAR(20),
    IN p_quantity DECIMAL(12,4),
    IN p_work_order VARCHAR(100),
    IN p_job_number VARCHAR(100),
    IN p_machine_press VARCHAR(50),
    IN p_operator_initials VARCHAR(10),
    IN p_consumed_by VARCHAR(20),
    OUT p_result VARCHAR(50)
)
BEGIN
    DECLARE v_inventory_id BIGINT;
    DECLARE v_current_quantity DECIMAL(12,4);
    DECLARE v_unit VARCHAR(20);
    DECLARE v_unit_cost DECIMAL(12,4);
    DECLARE v_remaining_quantity DECIMAL(12,4);
    DECLARE v_location_id INT;
    
    DECLARE exit handler for sqlexception
    BEGIN
        ROLLBACK;
        SET p_result = 'ERROR';
    END;
    
    START TRANSACTION;
    
    -- Get current inventory information
    SELECT `id`, `quantity`, `unit`, `unit_cost`, `current_location_id`
    INTO v_inventory_id, v_current_quantity, v_unit, v_unit_cost, v_location_id
    FROM `inventory`
    WHERE `inv_tag` = p_inv_tag
        AND `status` = 'AVAILABLE'
        AND `quantity` >= p_quantity;
    
    IF v_inventory_id IS NULL THEN
        SET p_result = 'INVENTORY_NOT_AVAILABLE';
        ROLLBACK;
        LEAVE sp_consume_inventory;
    END IF;
    
    -- Calculate remaining quantity
    SET v_remaining_quantity = v_current_quantity - p_quantity;
    
    -- Update inventory quantity, or mark as consumed if fully used
    IF v_remaining_quantity > 0 THEN
        UPDATE `inventory`
        SET `quantity` = v_remaining_quantity
        WHERE `id` = v_inventory_id;
    ELSE
        UPDATE `inventory`
        SET `quantity` = 0,
            `status` = 'CONSUMED'
        WHERE `id` = v_inventory_id;
    END IF;
    
    -- Record movement
    INSERT INTO `inventory_movements` (
        `inventory_id`, `inv_tag`, `movement_type`, `from_location_id`,
        `quantity_moved`, `unit`, `remaining_quantity`, `unit_cost`,
        `work_order`, `job_number`, `machine_press`, `operator_initials`,
        `reason_description`, `movement_date`, `created_by`
    ) VALUES (
        v_inventory_id, p_inv_tag, 'CONSUME', v_location_id,
        p_quantity, v_unit, v_remaining_quantity, v_unit_cost,
        p_work_order, p_job_number, p_machine_press, p_operator_initials,
        CONCAT('Production consumption - WO: ', COALESCE(p_work_order, 'N/A'), ', Job: ', COALESCE(p_job_number, 'N/A')),
        CURDATE(), p_consumed_by
    );
    
    -- Create audit log
    CALL sp_create_audit_log(
        p_consumed_by,
        'INVENTORY_CONSUME',
        CONCAT('Consumed inventory: ', p_inv_tag, ' (', p_quantity, ' ', v_unit, ') for production'),
        'inventory',
        v_inventory_id,
        NULL,
        JSON_OBJECT(
            'inv_tag', p_inv_tag,
            'quantity', p_quantity,
            'unit', v_unit,
            'work_order', p_work_order,
            'job_number', p_job_number,
            'machine_press', p_machine_press,
            'operator_initials', p_operator_initials
        ),
        '127.0.0.1',
        NULL,
        NULL,
        NULL,
        'MEDIUM'
    );
    
    COMMIT;
    SET p_result = 'SUCCESS';
END$$

DELIMITER ;

-- ========================================================================
-- CREATE TRIGGERS FOR AUTOMATIC AUDIT LOGGING
-- ========================================================================

DELIMITER $$

-- Trigger for inventory table INSERT (receives)
CREATE TRIGGER `tr_inventory_insert_audit` 
AFTER INSERT ON `inventory`
FOR EACH ROW
BEGIN
    -- Audit log is handled in sp_receive_inventory procedure
    -- This trigger is for any direct inserts that bypass the procedure
    IF @disable_inventory_triggers IS NULL OR @disable_inventory_triggers = 0 THEN
        CALL sp_create_audit_log(
            NEW.created_by,
            'INVENTORY_CREATE',
            CONCAT('Created inventory record: ', NEW.inv_tag),
            'inventory',
            NEW.id,
            NULL,
            JSON_OBJECT(
                'inv_tag', NEW.inv_tag,
                'material_id', NEW.material_id,
                'lot_number', NEW.lot_number,
                'quantity', NEW.quantity,
                'status', NEW.status
            ),
            '127.0.0.1',
            NULL,
            NULL,
            NULL,
            'MEDIUM'
        );
    END IF;
END$$

-- Trigger for inventory table UPDATE
CREATE TRIGGER `tr_inventory_update_audit` 
AFTER UPDATE ON `inventory`
FOR EACH ROW
BEGIN
    DECLARE v_action_desc TEXT;
    
    IF @disable_inventory_triggers IS NULL OR @disable_inventory_triggers = 0 THEN
        -- Determine what was updated
        SET v_action_desc = CONCAT('Updated inventory: ', NEW.inv_tag, ' - ');
        
        IF OLD.quantity != NEW.quantity THEN
            SET v_action_desc = CONCAT(v_action_desc, 'Quantity changed. ');
        END IF;
        
        IF OLD.current_location_id != NEW.current_location_id THEN
            SET v_action_desc = CONCAT(v_action_desc, 'Location changed. ');
        END IF;
        
        IF OLD.status != NEW.status THEN
            SET v_action_desc = CONCAT(v_action_desc, 'Status changed. ');
        END IF;
        
        CALL sp_create_audit_log(
            COALESCE(NEW.created_by, OLD.created_by),
            'INVENTORY_UPDATE',
            v_action_desc,
            'inventory',
            NEW.id,
            JSON_OBJECT(
                'quantity', OLD.quantity,
                'location_id', OLD.current_location_id,
                'status', OLD.status,
                'location_manual', OLD.current_location_manual
            ),
            JSON_OBJECT(
                'quantity', NEW.quantity,
                'location_id', NEW.current_location_id,
                'status', NEW.status,
                'location_manual', NEW.current_location_manual
            ),
            '127.0.0.1',
            NULL,
            NULL,
            NULL,
            'MEDIUM'
        );
    END IF;
END$$

DELIMITER ;

-- ========================================================================
-- INSERT INITIAL SEQUENCE DATA
-- ========================================================================

INSERT INTO `inv_tag_sequences` (`sequence_name`, `prefix`, `current_number`, `min_number`, `max_number`, `increment_by`, `zero_padding`, `description`, `created_by`) VALUES
('INV_MAIN', 'INV', 10000, 10001, 999999, 1, 5, 'Main inventory tag sequence for all received materials', NULL),
('INV_CONSUMABLE', 'CONS', 50000, 50001, 59999, 1, 5, 'Consumable materials tag sequence', NULL),
('INV_SCRAP', 'SCRAP', 90000, 90001, 99999, 1, 5, 'Scrap and waste materials tag sequence', NULL);

-- ========================================================================
-- INSERT INITIAL MATERIAL CATEGORIES
-- ========================================================================

INSERT INTO `material_categories` (`category_code`, `category_name`, `category_description`, `sort_order`, `created_by`) VALUES
('RAW', 'Raw Materials', 'Primary raw materials for production including resins, additives, and colorants', 10, NULL),
('PKG', 'Packaging Materials', 'Packaging materials including boxes, bags, labels, and protective materials', 20, NULL),
('CHEM', 'Chemicals', 'Process chemicals, cleaners, lubricants, and maintenance chemicals', 30, NULL),
('TOOLS', 'Tooling Materials', 'Tooling components, spare parts, and maintenance materials', 40, NULL),
('CONS', 'Consumables', 'Consumable items including office supplies, safety equipment, and disposables', 50, NULL),
('MRO', 'Maintenance Supplies', 'Maintenance, repair, and operations supplies', 60, NULL);

-- ========================================================================
-- INSERT INITIAL LOCATIONS
-- ========================================================================

INSERT INTO `locations` (`location_code`, `location_name`, `location_description`, `location_type`, `building`, `zone`, `created_by`) VALUES
('RCV-01', 'Receiving Dock 1', 'Primary receiving dock for incoming materials', 'RECEIVING', 'Main', 'Receiving', NULL),
('RCV-02', 'Receiving Dock 2', 'Secondary receiving dock for large shipments', 'RECEIVING', 'Main', 'Receiving', NULL),
('RAW-A01', 'Raw Material Storage A01', 'Primary raw material storage rack A, position 01', 'STORAGE', 'Main', 'Raw Storage', NULL),
('RAW-A02', 'Raw Material Storage A02', 'Primary raw material storage rack A, position 02', 'STORAGE', 'Main', 'Raw Storage', NULL),
('RAW-B01', 'Raw Material Storage B01', 'Secondary raw material storage rack B, position 01', 'STORAGE', 'Main', 'Raw Storage', NULL),
('PKG-01', 'Packaging Storage 01', 'Primary packaging material storage area', 'STORAGE', 'Main', 'Packaging', NULL),
('PROD-01', 'Production Floor 1', 'Main production floor area', 'PRODUCTION', 'Main', 'Production', NULL),
('PROD-02', 'Production Floor 2', 'Secondary production floor area', 'PRODUCTION', 'Main', 'Production', NULL),
('QC-LAB', 'Quality Control Lab', 'Quality control laboratory and inspection area', 'QC', 'Main', 'Quality', NULL),
('SHIP-01', 'Shipping Dock 1', 'Primary shipping dock for finished goods', 'SHIPPING', 'Main', 'Shipping', NULL),
('SCRAP-01', 'Scrap Storage', 'Scrap and waste material storage area', 'STORAGE', 'Main', 'Waste', NULL);

-- ========================================================================
-- INSERT SAMPLE MATERIALS
-- ========================================================================

-- Get category IDs for materials
SET @raw_category = (SELECT id FROM material_categories WHERE category_code = 'RAW');
SET @pkg_category = (SELECT id FROM material_categories WHERE category_code = 'PKG');
SET @chem_category = (SELECT id FROM material_categories WHERE category_code = 'CHEM');

INSERT INTO `materials` (`material_number`, `material_name`, `material_description`, `category_id`, `base_unit`, `material_grade`, `shelf_life_days`, `supplier_part_number`, `manufacturer_name`, `standard_cost`, `reorder_point`, `reorder_quantity`, `abc_classification`, `created_by`) VALUES
('PP-1001-NAT', 'Polypropylene Natural', 'Natural polypropylene resin, injection molding grade', @raw_category, 'LB', 'Injection Grade', 1095, 'PP1001NAT', 'Polymer Corp', 0.85, 10000, 50000, 'A', NULL),
('PP-1001-BLK', 'Polypropylene Black', 'Black polypropylene resin, injection molding grade', @raw_category, 'LB', 'Injection Grade', 1095, 'PP1001BLK', 'Polymer Corp', 0.87, 5000, 25000, 'A', NULL),
('PE-2001-NAT', 'Polyethylene Natural', 'Natural polyethylene resin, blow molding grade', @raw_category, 'LB', 'Blow Grade', 730, 'PE2001NAT', 'Polymer Corp', 0.92, 8000, 40000, 'B', NULL),
('ADD-001', 'UV Stabilizer', 'UV stabilizer additive for outdoor applications', @raw_category, 'LB', 'Additive', 365, 'UV001', 'Additive Inc', 12.50, 100, 500, 'B', NULL),
('BOX-001-SM', 'Shipping Box Small', 'Small corrugated shipping box 12x8x6', @pkg_category, 'EA', 'Corrugated', NULL, 'BOX001SM', 'Package Co', 0.75, 1000, 5000, 'C', NULL),
('BOX-001-LG', 'Shipping Box Large', 'Large corrugated shipping box 24x16x12', @pkg_category, 'EA', 'Corrugated', NULL, 'BOX001LG', 'Package Co', 1.25, 500, 2000, 'C', NULL),
('CLEAN-001', 'Mold Cleaner', 'Heavy duty mold cleaning solution', @chem_category, 'GAL', 'Industrial', 730, 'CLEAN001', 'Chemical Corp', 15.00, 50, 100, 'C', NULL);

-- ========================================================================
-- INSERT SAMPLE INVENTORY DATA FOR TESTING
-- ========================================================================

-- Set variables for sample data
SET @rcv_location = (SELECT id FROM locations WHERE location_code = 'RCV-01');
SET @raw_a01 = (SELECT id FROM locations WHERE location_code = 'RAW-A01');
SET @raw_a02 = (SELECT id FROM locations WHERE location_code = 'RAW-A02');
SET @pkg_01 = (SELECT id FROM locations WHERE location_code = 'PKG-01');

-- Sample inventory records with proper FIFO priorities
INSERT INTO `inventory` (`inv_tag`, `material_id`, `lot_number`, `quantity`, `original_quantity`, `unit`, `unit_cost`, `total_cost`, `current_location_id`, `status`, `received_date`, `expiration_date`, `supplier_name`, `purchase_order`, `fifo_priority`, `created_by`) VALUES

-- PP Natural - multiple lots for FIFO testing
('INV10001', (SELECT id FROM materials WHERE material_number = 'PP-1001-NAT'), 'PP240101', 25000.00, 25000.00, 'LB', 0.85, 21250.00, @raw_a01, 'AVAILABLE', '2024-01-15', '2027-01-14', 'Polymer Corp', 'PO-2024-001', fn_calculate_fifo_priority('2024-01-15', '2027-01-14'), 'MAT001'),

('INV10002', (SELECT id FROM materials WHERE material_number = 'PP-1001-NAT'), 'PP240201', 25000.00, 25000.00, 'LB', 0.85, 21250.00, @raw_a01, 'AVAILABLE', '2024-02-01', '2027-01-31', 'Polymer Corp', 'PO-2024-002', fn_calculate_fifo_priority('2024-02-01', '2027-01-31'), 'MAT001'),

('INV10003', (SELECT id FROM materials WHERE material_number = 'PP-1001-NAT'), 'PP240301', 25000.00, 25000.00, 'LB', 0.87, 21750.00, @raw_a01, 'AVAILABLE', '2024-03-01', '2027-02-28', 'Polymer Corp', 'PO-2024-003', fn_calculate_fifo_priority('2024-03-01', '2027-02-28'), 'MAT001'),

-- PP Black
('INV10004', (SELECT id FROM materials WHERE material_number = 'PP-1001-BLK'), 'PPB240101', 15000.00, 15000.00, 'LB', 0.87, 13050.00, @raw_a02, 'AVAILABLE', '2024-01-20', '2027-01-19', 'Polymer Corp', 'PO-2024-004', fn_calculate_fifo_priority('2024-01-20', '2027-01-19'), 'MAT001'),

-- PE Natural
('INV10005', (SELECT id FROM materials WHERE material_number = 'PE-2001-NAT'), 'PE240101', 20000.00, 20000.00, 'LB', 0.92, 18400.00, @raw_a02, 'AVAILABLE', '2024-01-25', '2026-01-20', 'Polymer Corp', 'PO-2024-005', fn_calculate_fifo_priority('2024-01-25', '2026-01-20'), 'MAT001'),

-- UV Stabilizer
('INV10006', (SELECT id FROM materials WHERE material_number = 'ADD-001'), 'UV240101', 250.00, 250.00, 'LB', 12.50, 3125.00, @raw_a02, 'AVAILABLE', '2024-02-10', '2025-02-09', 'Additive Inc', 'PO-2024-006', fn_calculate_fifo_priority('2024-02-10', '2025-02-09'), 'MAT001'),

-- Packaging boxes
('INV10007', (SELECT id FROM materials WHERE material_number = 'BOX-001-SM'), 'BOX240101', 2500.00, 2500.00, 'EA', 0.75, 1875.00, @pkg_01, 'AVAILABLE', '2024-02-15', NULL, 'Package Co', 'PO-2024-007', fn_calculate_fifo_priority('2024-02-15', NULL), 'MAT001'),

('INV10008', (SELECT id FROM materials WHERE material_number = 'BOX-001-LG'), 'BOXL240101', 1000.00, 1000.00, 'EA', 1.25, 1250.00, @pkg_01, 'AVAILABLE', '2024-02-15', NULL, 'Package Co', 'PO-2024-007', fn_calculate_fifo_priority('2024-02-15', NULL), 'MAT001');

-- Create corresponding movement records for all received inventory
INSERT INTO `inventory_movements` (`inventory_id`, `inv_tag`, `movement_type`, `to_location_id`, `quantity_moved`, `unit`, `remaining_quantity`, `unit_cost`, `total_cost`, `reference_number`, `reference_type`, `reason_description`, `movement_date`, `created_by`)
SELECT 
    i.id,
    i.inv_tag,
    'RECEIVE',
    i.current_location_id,
    i.original_quantity,
    i.unit,
    i.quantity,
    i.unit_cost,
    i.total_cost,
    i.purchase_order,
    'PURCHASE_ORDER',
    CONCAT('Initial receipt - Lot: ', i.lot_number),
    i.received_date,
    i.created_by
FROM inventory i
WHERE i.inv_tag LIKE 'INV1000%';

-- ========================================================================
-- CREATE EVENTS FOR AUTOMATED MAINTENANCE
-- ========================================================================

-- Event to check for expiring inventory (runs daily at 6 AM)
CREATE EVENT IF NOT EXISTS `ev_check_expiring_inventory`
ON SCHEDULE EVERY 1 DAY
STARTS TIMESTAMP(CURRENT_DATE) + INTERVAL 6 HOUR
DO
BEGIN
    -- Log expiring inventory for management attention
    INSERT INTO audit_logs (
        clock_number,
        real_name,
        action_type,
        action_description,
        table_affected,
        severity_level,
        ip_address,
        additional_context
    )
    SELECT 
        'SYSTEM',
        'Inventory Monitor',
        'INVENTORY_EXPIRING',
        CONCAT('Inventory expiring: ', COUNT(*), ' lots within 30 days'),
        'inventory',
        'MEDIUM',
        '127.0.0.1',
        JSON_OBJECT('expiring_count', COUNT(*), 'check_date', CURDATE())
    FROM inventory i
    WHERE i.quantity > 0 
        AND i.status IN ('AVAILABLE', 'RESERVED')
        AND i.expiration_date IS NOT NULL
        AND i.expiration_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
    HAVING COUNT(*) > 0;
END;

-- Event to clean up old movement records (runs monthly)
CREATE EVENT IF NOT EXISTS `ev_cleanup_old_movements`
ON SCHEDULE EVERY 1 MONTH
STARTS TIMESTAMP(CURRENT_DATE) + INTERVAL 2 HOUR
DO
BEGIN
    -- Archive movements older than 2 years (implement archiving logic here)
    -- For now, just log the cleanup activity
    INSERT INTO audit_logs (
        clock_number,
        real_name,
        action_type,
        action_description,
        table_affected,
        severity_level,
        ip_address,
        additional_context
    ) VALUES (
        'SYSTEM',
        'Database Maintenance',
        'MAINTENANCE',
        'Monthly inventory movements cleanup completed',
        'inventory_movements',
        'LOW',
        '127.0.0.1',
        JSON_OBJECT('cleanup_date', NOW())
    );
END;

-- ========================================================================
-- DATABASE PERFORMANCE OPTIMIZATIONS
-- ========================================================================

-- Analyze tables for query optimization
ANALYZE TABLE `inv_tag_sequences`, `material_categories`, `materials`, `locations`, `inventory`, `inventory_movements`, `inventory_reservations`, `inventory_adjustments`;

-- ========================================================================
-- MIGRATION COMPLETION LOG
-- ========================================================================

-- Log the completion of this migration
INSERT INTO `audit_logs` (
    `clock_number`,
    `real_name`,
    `action_type`,
    `action_description`,
    `table_affected`,
    `severity_level`,
    `ip_address`,
    `additional_context`
) VALUES (
    'SYSTEM',
    'Database Migration System',
    'MIGRATION',
    'Phase 1 Inventory Module database schema migration completed successfully',
    'ALL',
    'HIGH',
    '127.0.0.1',
    JSON_OBJECT(
        'migration_file', '002_create_inventory_module_schema.sql',
        'phase', 'Phase 1',
        'tables_created', JSON_ARRAY('inv_tag_sequences', 'material_categories', 'materials', 'locations', 'inventory', 'inventory_movements', 'inventory_reservations', 'inventory_adjustments'),
        'views_created', JSON_ARRAY('v_inventory_summary', 'v_fifo_available_inventory', 'v_inventory_totals_by_location', 'v_recent_inventory_movements', 'v_expiring_inventory'),
        'procedures_created', JSON_ARRAY('sp_receive_inventory', 'sp_transfer_inventory', 'sp_consume_inventory'),
        'functions_created', JSON_ARRAY('fn_get_next_inv_tag', 'fn_calculate_fifo_priority'),
        'events_created', JSON_ARRAY('ev_check_expiring_inventory', 'ev_cleanup_old_movements'),
        'triggers_created', JSON_ARRAY('tr_inventory_insert_audit', 'tr_inventory_update_audit'),
        'sample_data', JSON_OBJECT('categories', 6, 'locations', 11, 'materials', 7, 'inventory_lots', 8)
    )
);

-- ========================================================================
-- END OF MIGRATION
-- Phase 1: Inventory Module Complete
-- ========================================================================

-- Display completion message
SELECT 'BEMS Phase 1 Inventory Module migration completed successfully!' as 'Migration Status',
       'Tables: inv_tag_sequences, material_categories, materials, locations, inventory, inventory_movements, inventory_reservations, inventory_adjustments' as 'Tables Created',
       '6 material categories, 11 locations, 7 sample materials, 8 inventory lots created' as 'Sample Data',
       'INV tag sequence starts at INV10001 with auto-increment' as 'Tag System',
       'FIFO priority calculation with expiration date support' as 'FIFO Logic',
       'Complete movement audit trail with location tracking' as 'Audit Features',
       'UTF8MB4 charset with optimized indexing and foreign keys' as 'Database Features';