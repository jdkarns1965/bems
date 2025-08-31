-- ========================================================================
-- BEMS Products Module Database Schema
-- Best ERP Manufacturing System
-- 
-- This migration creates the comprehensive products/finished goods management
-- system with multi-level part number cross-referencing for automotive
-- supply chain requirements.
-- ========================================================================

-- Set connection charset and collation
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ========================================================================
-- DROP EXISTING TABLES IF THEY EXIST (for clean reinstall)
-- ========================================================================

DROP TABLE IF EXISTS `product_specifications`;
DROP TABLE IF EXISTS `product_part_numbers`;
DROP TABLE IF EXISTS `products`;

-- ========================================================================
-- PRODUCTS TABLE - Master product definitions
-- ========================================================================

CREATE TABLE `products` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `internal_part_number` varchar(50) NOT NULL COMMENT 'Internal part number - primary identifier',
  `product_name` varchar(255) NOT NULL COMMENT 'Product name/description',
  `product_description` text DEFAULT NULL COMMENT 'Detailed product description',
  `product_family` varchar(100) DEFAULT NULL COMMENT 'Product family/group classification',
  `base_unit` varchar(20) NOT NULL DEFAULT 'EA' COMMENT 'Base unit of measure for production',
  `standard_pack_quantity` decimal(12,2) DEFAULT 1.00 COMMENT 'Standard packaging quantity',
  `weight_per_unit` decimal(10,4) DEFAULT NULL COMMENT 'Weight per unit for shipping calculations',
  `dimensions` varchar(100) DEFAULT NULL COMMENT 'Product dimensions (L x W x H)',
  `material_composition` text DEFAULT NULL COMMENT 'Material composition description',
  `color_specification` varchar(50) DEFAULT NULL COMMENT 'Color specification',
  `finish_specification` varchar(100) DEFAULT NULL COMMENT 'Surface finish requirements',
  `quality_standards` text DEFAULT NULL COMMENT 'Quality standards and certifications required',
  `drawing_number` varchar(100) DEFAULT NULL COMMENT 'Engineering drawing reference',
  `revision_level` varchar(20) DEFAULT NULL COMMENT 'Current design revision',
  `product_status` enum('active','inactive','development','obsolete','on_hold') NOT NULL DEFAULT 'active' COMMENT 'Product lifecycle status',
  `introduction_date` date DEFAULT NULL COMMENT 'Product introduction date',
  `phase_out_date` date DEFAULT NULL COMMENT 'Planned phase-out date',
  `standard_cost` decimal(12,4) DEFAULT NULL COMMENT 'Standard manufacturing cost',
  `standard_price` decimal(12,4) DEFAULT NULL COMMENT 'Standard selling price',
  `created_by` varchar(50) NOT NULL COMMENT 'Clock number of user who created this product',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` varchar(50) DEFAULT NULL COMMENT 'Clock number of user who last updated this product',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_products_internal_part` (`internal_part_number`),
  KEY `idx_products_name` (`product_name`),
  KEY `idx_products_family` (`product_family`),
  KEY `idx_products_status` (`product_status`),
  KEY `idx_products_drawing` (`drawing_number`),
  KEY `idx_products_created_by` (`created_by`),
  
  CONSTRAINT `fk_products_created_by` 
    FOREIGN KEY (`created_by`) 
    REFERENCES `users`(`clock_number`) 
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_products_updated_by` 
    FOREIGN KEY (`updated_by`) 
    REFERENCES `users`(`clock_number`) 
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci 
COMMENT='Master products table for finished goods and manufactured items';

-- ========================================================================
-- PRODUCT PART NUMBERS TABLE - Multi-level part number cross-referencing
-- ========================================================================

CREATE TABLE `product_part_numbers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `internal_part_number` varchar(50) NOT NULL COMMENT 'Reference to products table',
  `part_number_type` enum('internal','customer','oem','legacy','alias') NOT NULL COMMENT 'Type of part number relationship',
  `part_number_value` varchar(100) NOT NULL COMMENT 'The actual part number value',
  `customer_name` varchar(100) DEFAULT NULL COMMENT 'Customer name for customer part numbers',
  `oem_name` varchar(100) DEFAULT NULL COMMENT 'OEM name for end customer part numbers',
  `priority` tinyint(3) NOT NULL DEFAULT 50 COMMENT 'Display priority (1-100, higher = more prominent)',
  `is_primary` boolean NOT NULL DEFAULT FALSE COMMENT 'Primary part number for this type',
  `effective_date` date NOT NULL DEFAULT (CURDATE()) COMMENT 'When this part number becomes active',
  `expiry_date` date DEFAULT NULL COMMENT 'When this part number expires',
  `usage_context` enum('all','internal','customer','shipping','documentation') NOT NULL DEFAULT 'all' COMMENT 'Where this part number should be displayed',
  `notes` text DEFAULT NULL COMMENT 'Additional notes about this part number',
  `created_by` varchar(50) NOT NULL COMMENT 'Clock number of user who created this relationship',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` varchar(50) DEFAULT NULL COMMENT 'Clock number of user who last updated this relationship',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_part_numbers_value_type` (`part_number_value`, `part_number_type`, `customer_name`, `oem_name`),
  KEY `idx_part_numbers_internal` (`internal_part_number`),
  KEY `idx_part_numbers_value` (`part_number_value`),
  KEY `idx_part_numbers_type` (`part_number_type`),
  KEY `idx_part_numbers_customer` (`customer_name`),
  KEY `idx_part_numbers_oem` (`oem_name`),
  KEY `idx_part_numbers_primary` (`internal_part_number`, `part_number_type`, `is_primary`),
  KEY `idx_part_numbers_effective` (`effective_date`, `expiry_date`),
  
  CONSTRAINT `fk_part_numbers_product` 
    FOREIGN KEY (`internal_part_number`) 
    REFERENCES `products`(`internal_part_number`) 
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT `fk_part_numbers_created_by` 
    FOREIGN KEY (`created_by`) 
    REFERENCES `users`(`clock_number`) 
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_part_numbers_updated_by` 
    FOREIGN KEY (`updated_by`) 
    REFERENCES `users`(`clock_number`) 
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `chk_part_numbers_dates` 
    CHECK (`expiry_date` IS NULL OR `expiry_date` >= `effective_date`),
  CONSTRAINT `chk_part_numbers_priority` 
    CHECK (`priority` BETWEEN 1 AND 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci 
COMMENT='Multi-level part number cross-reference table for complex supply chain requirements';

-- ========================================================================
-- PRODUCT SPECIFICATIONS TABLE - Manufacturing and quality parameters
-- ========================================================================

CREATE TABLE `product_specifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `internal_part_number` varchar(50) NOT NULL COMMENT 'Reference to products table',
  `specification_type` enum('manufacturing','quality','packaging','shipping','safety','regulatory') NOT NULL COMMENT 'Type of specification',
  `specification_name` varchar(100) NOT NULL COMMENT 'Specification parameter name',
  `specification_value` text NOT NULL COMMENT 'Specification value or description',
  `tolerance` varchar(50) DEFAULT NULL COMMENT 'Tolerance or acceptable range',
  `measurement_unit` varchar(20) DEFAULT NULL COMMENT 'Unit of measurement',
  `test_method` varchar(100) DEFAULT NULL COMMENT 'Testing or verification method',
  `is_critical` boolean NOT NULL DEFAULT FALSE COMMENT 'Critical specification flag',
  `is_customer_requirement` boolean NOT NULL DEFAULT FALSE COMMENT 'Customer-specified requirement',
  `effective_date` date NOT NULL DEFAULT (CURDATE()) COMMENT 'When this specification becomes effective',
  `expiry_date` date DEFAULT NULL COMMENT 'When this specification expires',
  `notes` text DEFAULT NULL COMMENT 'Additional specification notes',
  `created_by` varchar(50) NOT NULL COMMENT 'Clock number of user who created this specification',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` varchar(50) DEFAULT NULL COMMENT 'Clock number of user who last updated this specification',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_specifications_product_name` (`internal_part_number`, `specification_type`, `specification_name`),
  KEY `idx_specifications_product` (`internal_part_number`),
  KEY `idx_specifications_type` (`specification_type`),
  KEY `idx_specifications_critical` (`is_critical`),
  KEY `idx_specifications_customer` (`is_customer_requirement`),
  KEY `idx_specifications_effective` (`effective_date`, `expiry_date`),
  
  CONSTRAINT `fk_specifications_product` 
    FOREIGN KEY (`internal_part_number`) 
    REFERENCES `products`(`internal_part_number`) 
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT `fk_specifications_created_by` 
    FOREIGN KEY (`created_by`) 
    REFERENCES `users`(`clock_number`) 
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_specifications_updated_by` 
    FOREIGN KEY (`updated_by`) 
    REFERENCES `users`(`clock_number`) 
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `chk_specifications_dates` 
    CHECK (`expiry_date` IS NULL OR `expiry_date` >= `effective_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci 
COMMENT='Product specifications for manufacturing, quality, and regulatory requirements';

-- ========================================================================
-- BUSINESS FUNCTIONS FOR PRODUCTS
-- ========================================================================

DELIMITER $$

-- Function to get the next internal part number
DROP FUNCTION IF EXISTS `fn_get_next_product_number`$$
CREATE FUNCTION `fn_get_next_product_number`(sequence_type VARCHAR(20)) 
RETURNS VARCHAR(20) 
READS SQL DATA 
DETERMINISTIC
COMMENT 'Generate next internal product part number based on sequence type'
BEGIN
    DECLARE next_number INT DEFAULT 1000;
    DECLARE prefix VARCHAR(10) DEFAULT 'PROD';
    DECLARE formatted_number VARCHAR(20);
    
    -- Determine prefix and starting number based on sequence type
    CASE sequence_type
        WHEN 'STANDARD' THEN 
            SET prefix = 'PROD';
            SET next_number = 10000;
        WHEN 'PROTOTYPE' THEN 
            SET prefix = 'PROTO';
            SET next_number = 5000;
        WHEN 'CUSTOM' THEN 
            SET prefix = 'CUST';
            SET next_number = 8000;
        ELSE
            SET prefix = 'PROD';
            SET next_number = 10000;
    END CASE;
    
    -- Get the highest existing number for this prefix
    SELECT COALESCE(MAX(CAST(SUBSTRING(internal_part_number, LENGTH(prefix) + 1) AS UNSIGNED)), next_number - 1) + 1 
    INTO next_number
    FROM products 
    WHERE internal_part_number LIKE CONCAT(prefix, '%')
    AND internal_part_number REGEXP CONCAT('^', prefix, '[0-9]+$');
    
    -- Format with zero padding
    SET formatted_number = CONCAT(prefix, LPAD(next_number, 5, '0'));
    
    RETURN formatted_number;
END$$

-- Stored procedure to create audit log entry for product operations
DROP PROCEDURE IF EXISTS `sp_log_product_audit`$$
CREATE PROCEDURE `sp_log_product_audit`(
    IN p_internal_part_number VARCHAR(50),
    IN p_operation VARCHAR(50),
    IN p_old_values JSON,
    IN p_new_values JSON,
    IN p_clock_number VARCHAR(50)
)
COMMENT 'Log product-related operations to audit trail'
BEGIN
    DECLARE audit_description TEXT;
    
    SET audit_description = CONCAT('Product ', p_operation, ' for ', p_internal_part_number);
    
    INSERT INTO audit_log (
        table_name,
        record_id,
        operation,
        field_name,
        old_value,
        new_value,
        changed_by,
        operation_timestamp,
        description
    ) VALUES (
        'products',
        p_internal_part_number,
        p_operation,
        'product_data',
        JSON_EXTRACT(p_old_values, '$'),
        JSON_EXTRACT(p_new_values, '$'),
        p_clock_number,
        NOW(),
        audit_description
    );
END$$

DELIMITER ;

-- ========================================================================
-- PRODUCT MANAGEMENT VIEWS
-- ========================================================================

-- View for product search with all part numbers
DROP VIEW IF EXISTS `v_product_search`;
CREATE VIEW `v_product_search` AS
SELECT DISTINCT
    p.internal_part_number,
    p.product_name,
    p.product_description,
    p.product_family,
    p.product_status,
    p.standard_cost,
    p.standard_price,
    p.created_at,
    GROUP_CONCAT(
        DISTINCT CONCAT(
            ppn.part_number_type, ':', ppn.part_number_value,
            CASE 
                WHEN ppn.customer_name IS NOT NULL THEN CONCAT(' (', ppn.customer_name, ')')
                WHEN ppn.oem_name IS NOT NULL THEN CONCAT(' (', ppn.oem_name, ')')
                ELSE ''
            END
        ) 
        ORDER BY ppn.priority DESC, ppn.part_number_type 
        SEPARATOR '; '
    ) AS all_part_numbers,
    -- Individual part number types for specific searches
    MAX(CASE WHEN ppn.part_number_type = 'customer' THEN ppn.part_number_value END) AS customer_part_number,
    MAX(CASE WHEN ppn.part_number_type = 'oem' THEN ppn.part_number_value END) AS oem_part_number,
    MAX(CASE WHEN ppn.part_number_type = 'legacy' THEN ppn.part_number_value END) AS legacy_part_number
FROM products p
LEFT JOIN product_part_numbers ppn ON p.internal_part_number = ppn.internal_part_number
    AND (ppn.expiry_date IS NULL OR ppn.expiry_date >= CURDATE())
    AND ppn.effective_date <= CURDATE()
WHERE p.product_status IN ('active', 'development')
GROUP BY p.internal_part_number, p.product_name, p.product_description, 
         p.product_family, p.product_status, p.standard_cost, p.standard_price, p.created_at;

-- View for active products with primary part numbers
DROP VIEW IF EXISTS `v_products_active`;
CREATE VIEW `v_products_active` AS
SELECT 
    p.*,
    ppn_customer.part_number_value AS primary_customer_part,
    ppn_customer.customer_name,
    ppn_oem.part_number_value AS primary_oem_part,
    ppn_oem.oem_name
FROM products p
LEFT JOIN product_part_numbers ppn_customer ON p.internal_part_number = ppn_customer.internal_part_number
    AND ppn_customer.part_number_type = 'customer'
    AND ppn_customer.is_primary = TRUE
    AND (ppn_customer.expiry_date IS NULL OR ppn_customer.expiry_date >= CURDATE())
    AND ppn_customer.effective_date <= CURDATE()
LEFT JOIN product_part_numbers ppn_oem ON p.internal_part_number = ppn_oem.internal_part_number
    AND ppn_oem.part_number_type = 'oem'
    AND ppn_oem.is_primary = TRUE
    AND (ppn_oem.expiry_date IS NULL OR ppn_oem.expiry_date >= CURDATE())
    AND ppn_oem.effective_date <= CURDATE()
WHERE p.product_status = 'active';

-- ========================================================================
-- INSERT DEFAULT DATA
-- ========================================================================

-- Insert sample products for testing
INSERT INTO products (
    internal_part_number, product_name, product_description, product_family,
    base_unit, standard_pack_quantity, weight_per_unit, dimensions,
    material_composition, color_specification, product_status,
    standard_cost, standard_price, created_by
) VALUES 
('PROD10001', 'Automotive Bracket - Type A', 'Reinforcement bracket for automotive assembly', 'Brackets', 'EA', 50.00, 0.125, '4.5 x 2.1 x 0.8', 'ABS Plastic', 'Black', 'active', 1.25, 3.75, 'ADMIN001'),
('PROD10002', 'Housing Cover - Standard', 'Protective housing cover for electronic components', 'Housings', 'EA', 25.00, 0.089, '3.2 x 2.8 x 1.2', 'Polycarbonate', 'Clear', 'active', 2.10, 5.25, 'ADMIN001'),
('PROTO50001', 'Test Component - Rev A', 'Prototype component for validation testing', 'Prototypes', 'EA', 1.00, 0.045, '2.1 x 1.5 x 0.6', 'Nylon 6/6', 'Natural', 'development', 8.50, 0.00, 'ADMIN001');

-- Insert part number cross-references
INSERT INTO product_part_numbers (
    internal_part_number, part_number_type, part_number_value, 
    customer_name, priority, is_primary, usage_context, created_by
) VALUES 
-- PROD10001 part numbers
('PROD10001', 'customer', '26833', 'Nifco America', 90, TRUE, 'customer', 'ADMIN001'),
('PROD10001', 'oem', 'T1548-0005AH2', 'Ford Motor Company', 85, TRUE, 'shipping', 'ADMIN001'),
('PROD10001', 'legacy', 'BRK-001-REV3', NULL, 50, FALSE, 'internal', 'ADMIN001'),

-- PROD10002 part numbers  
('PROD10002', 'customer', 'HC-STD-2024', 'TechCorp Industries', 90, TRUE, 'customer', 'ADMIN001'),
('PROD10002', 'oem', 'GM-HC-789456', 'General Motors', 85, TRUE, 'shipping', 'ADMIN001');

-- Insert sample specifications
INSERT INTO product_specifications (
    internal_part_number, specification_type, specification_name, 
    specification_value, tolerance, measurement_unit, is_critical, 
    is_customer_requirement, created_by
) VALUES 
('PROD10001', 'manufacturing', 'Cycle Time', '45', '±5', 'seconds', TRUE, FALSE, 'ADMIN001'),
('PROD10001', 'quality', 'Tensile Strength', '8500', '±200', 'PSI', TRUE, TRUE, 'ADMIN001'),
('PROD10001', 'quality', 'Surface Finish', 'SPI-A2', NULL, NULL, FALSE, TRUE, 'ADMIN001'),
('PROD10002', 'manufacturing', 'Injection Pressure', '1250', '±50', 'PSI', TRUE, FALSE, 'ADMIN001'),
('PROD10002', 'quality', 'Light Transmission', '92', '±2', 'percent', TRUE, TRUE, 'ADMIN001');

-- ========================================================================
-- MIGRATION COMPLETE
-- ========================================================================

SELECT 'Products Module Migration Complete' as Status,
       (SELECT COUNT(*) FROM products) as Products_Created,
       (SELECT COUNT(*) FROM product_part_numbers) as Part_Number_References,
       (SELECT COUNT(*) FROM product_specifications) as Specifications_Created;