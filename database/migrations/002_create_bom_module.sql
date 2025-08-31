-- BEMS Phase 2: Bill of Materials (BOM) Module Database Schema
-- Best ERP Manufacturing System
-- Creates tables for multi-level BOM management with version control

-- BOM Headers table - stores top-level BOM information
CREATE TABLE IF NOT EXISTS bom_headers (
    id INT PRIMARY KEY AUTO_INCREMENT,
    bom_number VARCHAR(50) NOT NULL UNIQUE COMMENT 'Unique BOM identifier',
    parent_material_number VARCHAR(50) NOT NULL COMMENT 'Material this BOM defines',
    bom_version VARCHAR(10) NOT NULL DEFAULT '1.0' COMMENT 'BOM version for change control',
    description TEXT COMMENT 'BOM description',
    base_quantity DECIMAL(15,6) NOT NULL DEFAULT 1.000000 COMMENT 'Base quantity for calculations',
    unit_of_measure VARCHAR(10) NOT NULL DEFAULT 'EA' COMMENT 'Unit of measure',
    status ENUM('active', 'inactive', 'pending', 'obsolete') NOT NULL DEFAULT 'active',
    effective_date DATE NOT NULL COMMENT 'When this BOM becomes effective',
    expiry_date DATE NULL COMMENT 'When this BOM expires (NULL = no expiry)',
    created_by VARCHAR(50) NOT NULL COMMENT 'User who created this BOM',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_by VARCHAR(50) NULL COMMENT 'User who last modified this BOM',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Indexes for performance
    INDEX idx_bom_material (parent_material_number),
    INDEX idx_bom_status (status),
    INDEX idx_bom_effective (effective_date),
    INDEX idx_bom_version (parent_material_number, bom_version),
    
    -- Constraints
    CONSTRAINT fk_bom_parent_material 
        FOREIGN KEY (parent_material_number) 
        REFERENCES materials(material_number) 
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_bom_base_qty CHECK (base_quantity > 0),
    CONSTRAINT chk_bom_dates CHECK (expiry_date IS NULL OR expiry_date >= effective_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci 
COMMENT='BOM header records for multi-level bill of materials';

-- BOM Lines table - stores component details for each BOM
CREATE TABLE IF NOT EXISTS bom_lines (
    id INT PRIMARY KEY AUTO_INCREMENT,
    bom_id INT NOT NULL COMMENT 'Reference to BOM header',
    line_number INT NOT NULL COMMENT 'Line sequence within BOM',
    component_material_number VARCHAR(50) NOT NULL COMMENT 'Component material',
    required_quantity DECIMAL(15,6) NOT NULL COMMENT 'Quantity needed per base quantity',
    unit_of_measure VARCHAR(10) NOT NULL DEFAULT 'EA' COMMENT 'Component UOM',
    scrap_percentage DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT 'Expected scrap/waste percentage',
    lead_time_offset_days INT NOT NULL DEFAULT 0 COMMENT 'Days before parent needed',
    operation_sequence INT NULL COMMENT 'Operation where component is consumed',
    substitute_group VARCHAR(20) NULL COMMENT 'Substitution group identifier',
    is_critical BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Critical path component flag',
    is_phantom BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Phantom/reference component flag',
    notes TEXT NULL COMMENT 'Component-specific notes',
    effective_date DATE NOT NULL COMMENT 'When this line becomes effective',
    expiry_date DATE NULL COMMENT 'When this line expires',
    created_by VARCHAR(50) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_by VARCHAR(50) NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Indexes for performance
    INDEX idx_bom_lines_bom (bom_id),
    INDEX idx_bom_lines_component (component_material_number),
    INDEX idx_bom_lines_seq (bom_id, line_number),
    INDEX idx_bom_lines_effective (effective_date),
    INDEX idx_bom_lines_substitute (substitute_group),
    
    -- Constraints
    CONSTRAINT fk_bom_lines_header 
        FOREIGN KEY (bom_id) 
        REFERENCES bom_headers(id) 
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_bom_lines_component 
        FOREIGN KEY (component_material_number) 
        REFERENCES materials(material_number) 
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT uq_bom_line_number UNIQUE (bom_id, line_number),
    CONSTRAINT chk_bom_line_qty CHECK (required_quantity > 0),
    CONSTRAINT chk_bom_scrap CHECK (scrap_percentage >= 0 AND scrap_percentage < 100),
    CONSTRAINT chk_bom_line_dates CHECK (expiry_date IS NULL OR expiry_date >= effective_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci 
COMMENT='BOM line items defining component requirements';

-- BOM Explosion Cache table - for performance optimization of multi-level explosions
CREATE TABLE IF NOT EXISTS bom_explosion_cache (
    id INT PRIMARY KEY AUTO_INCREMENT,
    parent_material_number VARCHAR(50) NOT NULL COMMENT 'Top-level parent material',
    bom_version VARCHAR(10) NOT NULL COMMENT 'BOM version used',
    component_material_number VARCHAR(50) NOT NULL COMMENT 'End component material',
    bom_level INT NOT NULL COMMENT 'Level in BOM hierarchy (0=parent, 1=child, etc)',
    net_quantity DECIMAL(18,8) NOT NULL COMMENT 'Net quantity needed per parent',
    path TEXT NOT NULL COMMENT 'JSON path showing BOM hierarchy',
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Indexes for performance
    INDEX idx_explosion_parent (parent_material_number, bom_version),
    INDEX idx_explosion_component (component_material_number),
    INDEX idx_explosion_level (parent_material_number, bom_level),
    
    -- Constraints
    CONSTRAINT uq_explosion_path UNIQUE (parent_material_number, bom_version, component_material_number, path(255)),
    CONSTRAINT chk_explosion_level CHECK (bom_level >= 0),
    CONSTRAINT chk_explosion_qty CHECK (net_quantity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci 
COMMENT='Cached BOM explosion results for performance optimization';

-- BOM Cost Rollup table - for storing calculated costs
CREATE TABLE IF NOT EXISTS bom_cost_rollup (
    id INT PRIMARY KEY AUTO_INCREMENT,
    bom_id INT NOT NULL COMMENT 'Reference to BOM header',
    cost_type ENUM('standard', 'actual', 'planned') NOT NULL DEFAULT 'standard',
    material_cost DECIMAL(15,4) NOT NULL DEFAULT 0.0000 COMMENT 'Total material cost',
    labor_cost DECIMAL(15,4) NOT NULL DEFAULT 0.0000 COMMENT 'Total labor cost',
    overhead_cost DECIMAL(15,4) NOT NULL DEFAULT 0.0000 COMMENT 'Total overhead cost',
    total_cost DECIMAL(15,4) NOT NULL DEFAULT 0.0000 COMMENT 'Total BOM cost',
    cost_per_unit DECIMAL(15,4) NOT NULL DEFAULT 0.0000 COMMENT 'Cost per base unit',
    calculation_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    calculated_by VARCHAR(50) NOT NULL,
    
    -- Indexes for performance
    INDEX idx_cost_rollup_bom (bom_id),
    INDEX idx_cost_rollup_type (cost_type),
    INDEX idx_cost_rollup_date (calculation_date),
    
    -- Constraints
    CONSTRAINT fk_cost_rollup_bom 
        FOREIGN KEY (bom_id) 
        REFERENCES bom_headers(id) 
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT uq_cost_rollup_type UNIQUE (bom_id, cost_type),
    CONSTRAINT chk_cost_amounts CHECK (
        material_cost >= 0 AND labor_cost >= 0 AND 
        overhead_cost >= 0 AND total_cost >= 0 AND cost_per_unit >= 0
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci 
COMMENT='BOM cost rollup calculations by cost type';

-- Insert some sample BOM data for testing
INSERT INTO bom_headers (
    bom_number, parent_material_number, bom_version, description, 
    base_quantity, unit_of_measure, effective_date, created_by
) VALUES 
('BOM-WIDGET-001', 'WIDGET-A100', '1.0', 'Standard Widget Assembly', 1.000000, 'EA', CURDATE(), 'ADMIN001'),
('BOM-PUMP-001', 'PUMP-P200', '1.0', 'Hydraulic Pump Assembly', 1.000000, 'EA', CURDATE(), 'ADMIN001'),
('BOM-FRAME-001', 'FRAME-F300', '1.0', 'Equipment Frame Assembly', 1.000000, 'EA', CURDATE(), 'ADMIN001');

-- Insert sample BOM lines (components)
INSERT INTO bom_lines (
    bom_id, line_number, component_material_number, required_quantity, 
    unit_of_measure, scrap_percentage, lead_time_offset_days, 
    effective_date, created_by
) VALUES 
-- Widget BOM lines
(1, 10, 'STEEL-304SS-1IN', 2.500000, 'FT', 5.00, 7, CURDATE(), 'ADMIN001'),
(1, 20, 'BOLT-M8X25', 4.000000, 'EA', 0.00, 3, CURDATE(), 'ADMIN001'),
(1, 30, 'WASHER-M8', 4.000000, 'EA', 0.00, 3, CURDATE(), 'ADMIN001'),

-- Pump BOM lines  
(2, 10, 'STEEL-CAST-A536', 15.000000, 'LB', 2.00, 14, CURDATE(), 'ADMIN001'),
(2, 20, 'SEAL-VITON-2IN', 2.000000, 'EA', 10.00, 5, CURDATE(), 'ADMIN001'),
(2, 30, 'BEARING-6205', 2.000000, 'EA', 0.00, 10, CURDATE(), 'ADMIN001'),

-- Frame BOM lines
(3, 10, 'STEEL-TUBE-2X2', 20.000000, 'FT', 3.00, 10, CURDATE(), 'ADMIN001'),
(3, 20, 'WELD-ROD-7018', 0.500000, 'LB', 15.00, 2, CURDATE(), 'ADMIN001');

-- Add indexes for audit performance  
CREATE INDEX IF NOT EXISTS idx_audit_bom_table ON audit_logs(table_name);