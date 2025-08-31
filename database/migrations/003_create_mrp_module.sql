-- BEMS Phase 3: Material Requirements Planning (MRP) Module Database Schema
-- Best ERP Manufacturing System
-- Creates tables for MRP planning, time-phased requirements, and planned orders

-- Master Production Schedule (MPS) table - defines what to produce and when
CREATE TABLE IF NOT EXISTS master_production_schedule (
    id INT PRIMARY KEY AUTO_INCREMENT,
    item_number VARCHAR(50) NOT NULL COMMENT 'Material number to be produced',
    plan_version VARCHAR(20) NOT NULL DEFAULT 'ACTIVE' COMMENT 'Planning version identifier',
    planning_period_start DATE NOT NULL COMMENT 'Start date of planning period',
    planning_period_end DATE NOT NULL COMMENT 'End date of planning period',
    demand_date DATE NOT NULL COMMENT 'Required completion date',
    planned_quantity DECIMAL(15,6) NOT NULL COMMENT 'Quantity to produce',
    firm_planned_flag BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Is this a firm planned order',
    priority_code VARCHAR(10) DEFAULT 'NORMAL' COMMENT 'Priority (HIGH, NORMAL, LOW)',
    customer_order_ref VARCHAR(50) NULL COMMENT 'Customer order reference',
    notes TEXT NULL COMMENT 'Planning notes',
    planning_fence_date DATE NULL COMMENT 'Freeze date for changes',
    created_by VARCHAR(50) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_by VARCHAR(50) NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Indexes for performance
    INDEX idx_mps_item (item_number),
    INDEX idx_mps_version (plan_version),
    INDEX idx_mps_demand_date (demand_date),
    INDEX idx_mps_period (planning_period_start, planning_period_end),
    INDEX idx_mps_active (item_number, plan_version, demand_date),
    
    -- Constraints
    CONSTRAINT fk_mps_item 
        FOREIGN KEY (item_number) 
        REFERENCES materials(material_number) 
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_mps_quantity CHECK (planned_quantity > 0),
    CONSTRAINT chk_mps_dates CHECK (planning_period_end >= planning_period_start AND demand_date >= planning_period_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci 
COMMENT='Master Production Schedule defining production requirements';

-- Material Requirements Planning (MRP) records - time-phased requirements
CREATE TABLE IF NOT EXISTS mrp_requirements (
    id INT PRIMARY KEY AUTO_INCREMENT,
    planning_run_id VARCHAR(50) NOT NULL COMMENT 'MRP run identifier',
    item_number VARCHAR(50) NOT NULL COMMENT 'Material requiring planning',
    plan_version VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
    requirement_date DATE NOT NULL COMMENT 'Date requirement is needed',
    requirement_type ENUM('GROSS', 'NET', 'PLANNED_ORDER', 'SCHEDULED_RECEIPT') NOT NULL COMMENT 'Type of requirement',
    source_type ENUM('MPS', 'DEPENDENT', 'SAFETY_STOCK', 'REORDER_POINT') NOT NULL COMMENT 'Source of requirement',
    source_reference VARCHAR(100) NULL COMMENT 'Reference to source (order, BOM line, etc)',
    required_quantity DECIMAL(15,6) NOT NULL COMMENT 'Quantity required',
    available_quantity DECIMAL(15,6) NOT NULL DEFAULT 0 COMMENT 'Quantity available (on-hand + scheduled)',
    net_requirement DECIMAL(15,6) NOT NULL DEFAULT 0 COMMENT 'Net quantity needed after netting',
    lot_size_quantity DECIMAL(15,6) NULL COMMENT 'Lot sizing quantity',
    planning_lead_time_days INT NOT NULL DEFAULT 0 COMMENT 'Planning lead time',
    order_release_date DATE NULL COMMENT 'Calculated order release date',
    parent_item VARCHAR(50) NULL COMMENT 'Parent item if dependent demand',
    bom_level INT NOT NULL DEFAULT 0 COMMENT 'Level in BOM explosion (0=MPS, 1=child, etc)',
    action_required ENUM('NONE', 'CREATE_ORDER', 'RESCHEDULE', 'CANCEL', 'EXPEDITE') NOT NULL DEFAULT 'NONE',
    action_message TEXT NULL COMMENT 'Action message details',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    -- Indexes for performance
    INDEX idx_mrp_run (planning_run_id),
    INDEX idx_mrp_item (item_number),
    INDEX idx_mrp_item_date (item_number, requirement_date),
    INDEX idx_mrp_version (plan_version),
    INDEX idx_mrp_type (requirement_type),
    INDEX idx_mrp_level (bom_level),
    INDEX idx_mrp_parent (parent_item),
    INDEX idx_mrp_action (action_required),
    
    -- Constraints
    CONSTRAINT fk_mrp_item 
        FOREIGN KEY (item_number) 
        REFERENCES materials(material_number) 
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_mrp_parent 
        FOREIGN KEY (parent_item) 
        REFERENCES materials(material_number) 
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT chk_mrp_quantities CHECK (required_quantity >= 0 AND available_quantity >= 0),
    CONSTRAINT chk_mrp_dates CHECK (order_release_date IS NULL OR order_release_date <= requirement_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci 
COMMENT='MRP requirements and planned orders by time period';

-- Planned Orders table - orders suggested by MRP system
CREATE TABLE IF NOT EXISTS planned_orders (
    id INT PRIMARY KEY AUTO_INCREMENT,
    planning_run_id VARCHAR(50) NOT NULL COMMENT 'MRP run that created this order',
    planned_order_number VARCHAR(50) NOT NULL UNIQUE COMMENT 'System generated planned order number',
    item_number VARCHAR(50) NOT NULL COMMENT 'Item to be ordered/produced',
    plan_version VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
    order_type ENUM('PURCHASE', 'PRODUCTION', 'TRANSFER') NOT NULL COMMENT 'Type of planned order',
    planned_quantity DECIMAL(15,6) NOT NULL COMMENT 'Planned order quantity',
    unit_of_measure VARCHAR(10) NOT NULL DEFAULT 'EA',
    due_date DATE NOT NULL COMMENT 'Date order is needed',
    start_date DATE NOT NULL COMMENT 'Date order should be released',
    planning_lead_time_days INT NOT NULL DEFAULT 0,
    lot_sizing_rule ENUM('LOT_FOR_LOT', 'FIXED_LOT', 'ECONOMIC_ORDER_QTY', 'PERIOD_ORDER_QTY') NOT NULL DEFAULT 'LOT_FOR_LOT',
    lot_size_quantity DECIMAL(15,6) NULL COMMENT 'Fixed lot size if applicable',
    safety_stock_quantity DECIMAL(15,6) NOT NULL DEFAULT 0,
    source_requirement_id INT NULL COMMENT 'Link to MRP requirement that created this',
    supplier_id VARCHAR(50) NULL COMMENT 'Preferred supplier for purchase orders',
    work_center_id VARCHAR(50) NULL COMMENT 'Work center for production orders',
    priority_code VARCHAR(10) DEFAULT 'NORMAL',
    firm_flag BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Is this order firmed by planner',
    status ENUM('PLANNED', 'FIRMED', 'RELEASED', 'CANCELLED') NOT NULL DEFAULT 'PLANNED',
    pegging_references TEXT NULL COMMENT 'JSON array of pegging data showing demand sources',
    planning_notes TEXT NULL,
    created_by VARCHAR(50) NOT NULL DEFAULT 'MRP_SYSTEM',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_by VARCHAR(50) NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Indexes for performance
    INDEX idx_planned_orders_run (planning_run_id),
    INDEX idx_planned_orders_item (item_number),
    INDEX idx_planned_orders_dates (due_date, start_date),
    INDEX idx_planned_orders_type (order_type),
    INDEX idx_planned_orders_status (status),
    INDEX idx_planned_orders_firm (firm_flag),
    INDEX idx_planned_orders_priority (priority_code),
    
    -- Constraints
    CONSTRAINT fk_planned_orders_item 
        FOREIGN KEY (item_number) 
        REFERENCES materials(material_number) 
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_planned_orders_req 
        FOREIGN KEY (source_requirement_id) 
        REFERENCES mrp_requirements(id) 
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT chk_planned_orders_qty CHECK (planned_quantity > 0),
    CONSTRAINT chk_planned_orders_dates CHECK (start_date <= due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci 
COMMENT='Planned orders generated by MRP system';

-- MRP Planning Parameters table - controls MRP behavior by item
CREATE TABLE IF NOT EXISTS mrp_planning_parameters (
    id INT PRIMARY KEY AUTO_INCREMENT,
    item_number VARCHAR(50) NOT NULL UNIQUE COMMENT 'Material number',
    planning_method ENUM('MRP', 'REORDER_POINT', 'MANUAL', 'NONE') NOT NULL DEFAULT 'MRP' COMMENT 'How item is planned',
    lot_sizing_rule ENUM('LOT_FOR_LOT', 'FIXED_LOT', 'ECONOMIC_ORDER_QTY', 'PERIOD_ORDER_QTY') NOT NULL DEFAULT 'LOT_FOR_LOT',
    fixed_lot_size DECIMAL(15,6) NULL COMMENT 'Fixed lot size quantity',
    minimum_order_quantity DECIMAL(15,6) NOT NULL DEFAULT 0,
    maximum_order_quantity DECIMAL(15,6) NULL,
    order_multiple DECIMAL(15,6) NOT NULL DEFAULT 1 COMMENT 'Order must be multiple of this',
    safety_stock_quantity DECIMAL(15,6) NOT NULL DEFAULT 0,
    safety_lead_time_days INT NOT NULL DEFAULT 0 COMMENT 'Additional lead time buffer',
    planning_lead_time_days INT NOT NULL DEFAULT 0 COMMENT 'Total planning lead time',
    yield_percentage DECIMAL(5,2) NOT NULL DEFAULT 100.00 COMMENT 'Expected yield percentage',
    shrinkage_percentage DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT 'Expected shrinkage/scrap',
    low_level_code INT NOT NULL DEFAULT 0 COMMENT 'Lowest level in any BOM structure',
    make_buy_code ENUM('MAKE', 'BUY', 'TRANSFER') NOT NULL DEFAULT 'BUY',
    default_supplier_id VARCHAR(50) NULL,
    planning_fence_days INT NOT NULL DEFAULT 0 COMMENT 'Days in future where changes are restricted',
    demand_fence_days INT NOT NULL DEFAULT 0 COMMENT 'Days where demand is frozen',
    active_flag BOOLEAN NOT NULL DEFAULT TRUE,
    created_by VARCHAR(50) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_by VARCHAR(50) NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Indexes for performance
    INDEX idx_mrp_params_method (planning_method),
    INDEX idx_mrp_params_make_buy (make_buy_code),
    INDEX idx_mrp_params_level (low_level_code),
    INDEX idx_mrp_params_active (active_flag),
    
    -- Constraints
    CONSTRAINT fk_mrp_params_item 
        FOREIGN KEY (item_number) 
        REFERENCES materials(material_number) 
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT chk_mrp_params_lots CHECK (
        (lot_sizing_rule != 'FIXED_LOT' OR fixed_lot_size IS NOT NULL) AND
        minimum_order_quantity >= 0 AND
        (maximum_order_quantity IS NULL OR maximum_order_quantity >= minimum_order_quantity) AND
        order_multiple > 0 AND
        safety_stock_quantity >= 0 AND
        yield_percentage > 0 AND yield_percentage <= 100 AND
        shrinkage_percentage >= 0 AND shrinkage_percentage < 100
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci 
COMMENT='MRP planning parameters controlling how each item is planned';

-- MRP Planning Runs table - tracks MRP execution history
CREATE TABLE IF NOT EXISTS mrp_planning_runs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    planning_run_id VARCHAR(50) NOT NULL UNIQUE COMMENT 'Unique run identifier',
    plan_version VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
    planning_horizon_days INT NOT NULL DEFAULT 365 COMMENT 'How far into future to plan',
    planning_start_date DATE NOT NULL COMMENT 'Start of planning horizon',
    planning_end_date DATE NOT NULL COMMENT 'End of planning horizon',
    run_type ENUM('FULL_REGENERATIVE', 'NET_CHANGE', 'SIMULATION') NOT NULL DEFAULT 'FULL_REGENERATIVE',
    run_status ENUM('RUNNING', 'COMPLETED', 'FAILED', 'CANCELLED') NOT NULL DEFAULT 'RUNNING',
    items_planned INT NOT NULL DEFAULT 0 COMMENT 'Number of items processed',
    planned_orders_created INT NOT NULL DEFAULT 0 COMMENT 'Number of planned orders created',
    planned_orders_cancelled INT NOT NULL DEFAULT 0 COMMENT 'Number of planned orders cancelled',
    action_messages_generated INT NOT NULL DEFAULT 0 COMMENT 'Number of action messages created',
    run_start_time TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    run_end_time TIMESTAMP NULL,
    run_duration_seconds INT NULL COMMENT 'Total runtime in seconds',
    error_message TEXT NULL COMMENT 'Error message if run failed',
    planning_parameters TEXT NULL COMMENT 'JSON of parameters used for this run',
    created_by VARCHAR(50) NOT NULL,
    
    -- Indexes for performance
    INDEX idx_mrp_runs_status (run_status),
    INDEX idx_mrp_runs_version (plan_version),
    INDEX idx_mrp_runs_date (run_start_time),
    INDEX idx_mrp_runs_type (run_type),
    
    -- Constraints
    CONSTRAINT chk_mrp_runs_dates CHECK (planning_end_date >= planning_start_date),
    CONSTRAINT chk_mrp_runs_counters CHECK (
        items_planned >= 0 AND 
        planned_orders_created >= 0 AND 
        planned_orders_cancelled >= 0 AND
        action_messages_generated >= 0
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci 
COMMENT='MRP planning run execution history and statistics';

-- Insert sample Master Production Schedule data
INSERT INTO master_production_schedule (
    item_number, plan_version, planning_period_start, planning_period_end,
    demand_date, planned_quantity, priority_code, created_by
) VALUES 
('PP-1001-NAT', 'ACTIVE', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), 
 DATE_ADD(CURDATE(), INTERVAL 7 DAY), 1000.000000, 'HIGH', 'ADMIN001'),
('BOX-001-LG', 'ACTIVE', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY),
 DATE_ADD(CURDATE(), INTERVAL 10 DAY), 500.000000, 'NORMAL', 'ADMIN001'),
('PE-2001-NAT', 'ACTIVE', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY),
 DATE_ADD(CURDATE(), INTERVAL 14 DAY), 750.000000, 'NORMAL', 'ADMIN001');

-- Insert sample MRP planning parameters for existing materials
INSERT INTO mrp_planning_parameters (
    item_number, planning_method, lot_sizing_rule, planning_lead_time_days,
    safety_stock_quantity, make_buy_code, minimum_order_quantity, created_by
) VALUES 
-- Production items
('PP-1001-NAT', 'MRP', 'FIXED_LOT', 5, 100.000000, 'MAKE', 100.000000, 'ADMIN001'),
('PP-1001-BLK', 'MRP', 'FIXED_LOT', 5, 50.000000, 'MAKE', 50.000000, 'ADMIN001'),
('PE-2001-NAT', 'MRP', 'LOT_FOR_LOT', 7, 200.000000, 'MAKE', 0.000000, 'ADMIN001'),
-- Packaging items
('BOX-001-LG', 'MRP', 'ECONOMIC_ORDER_QTY', 3, 25.000000, 'BUY', 25.000000, 'ADMIN001'),
('BOX-001-SM', 'MRP', 'ECONOMIC_ORDER_QTY', 3, 50.000000, 'BUY', 25.000000, 'ADMIN001'),
-- Raw materials/additives
('ADD-001', 'REORDER_POINT', 'FIXED_LOT', 14, 5.000000, 'BUY', 1.000000, 'ADMIN001'),
('CLEAN-001', 'REORDER_POINT', 'LOT_FOR_LOT', 7, 2.000000, 'BUY', 0.100000, 'ADMIN001');

-- Update planning parameters with fixed lot sizes where applicable
UPDATE mrp_planning_parameters 
SET fixed_lot_size = 500.000000 
WHERE item_number = 'PP-1001-NAT' AND lot_sizing_rule = 'FIXED_LOT';

UPDATE mrp_planning_parameters 
SET fixed_lot_size = 200.000000 
WHERE item_number = 'PP-1001-BLK' AND lot_sizing_rule = 'FIXED_LOT';

UPDATE mrp_planning_parameters 
SET fixed_lot_size = 10.000000 
WHERE item_number = 'ADD-001' AND lot_sizing_rule = 'FIXED_LOT';

-- Create reporting views for MRP analysis
CREATE OR REPLACE VIEW vw_mrp_requirements_summary AS
SELECT 
    r.planning_run_id,
    r.item_number,
    m.material_name,
    r.requirement_date,
    r.requirement_type,
    r.source_type,
    r.required_quantity,
    r.available_quantity,
    r.net_requirement,
    r.bom_level,
    r.action_required,
    r.action_message,
    pp.make_buy_code,
    pp.planning_lead_time_days
FROM mrp_requirements r
LEFT JOIN materials m ON r.item_number = m.material_number
LEFT JOIN mrp_planning_parameters pp ON r.item_number = pp.item_number
WHERE r.plan_version = 'ACTIVE'
ORDER BY r.requirement_date, r.bom_level, r.item_number;

CREATE OR REPLACE VIEW vw_planned_orders_summary AS
SELECT 
    po.planned_order_number,
    po.item_number,
    m.material_name,
    po.order_type,
    po.planned_quantity,
    po.unit_of_measure,
    po.start_date,
    po.due_date,
    po.planning_lead_time_days,
    po.priority_code,
    po.status,
    po.firm_flag,
    pp.make_buy_code,
    DATEDIFF(po.due_date, CURDATE()) as days_until_due
FROM planned_orders po
LEFT JOIN materials m ON po.item_number = m.material_number
LEFT JOIN mrp_planning_parameters pp ON po.item_number = pp.item_number
WHERE po.plan_version = 'ACTIVE' AND po.status IN ('PLANNED', 'FIRMED')
ORDER BY po.due_date, po.priority_code DESC, po.item_number;

CREATE OR REPLACE VIEW vw_mrp_action_messages AS
SELECT 
    r.planning_run_id,
    r.item_number,
    m.material_name,
    r.requirement_date,
    r.action_required,
    r.action_message,
    r.required_quantity,
    r.net_requirement,
    pp.make_buy_code,
    CASE 
        WHEN r.action_required = 'CREATE_ORDER' THEN 'Create new order'
        WHEN r.action_required = 'RESCHEDULE' THEN 'Reschedule existing order'
        WHEN r.action_required = 'CANCEL' THEN 'Cancel unnecessary order'
        WHEN r.action_required = 'EXPEDITE' THEN 'Expedite to meet demand'
        ELSE 'No action required'
    END as action_description
FROM mrp_requirements r
LEFT JOIN materials m ON r.item_number = m.material_number  
LEFT JOIN mrp_planning_parameters pp ON r.item_number = pp.item_number
WHERE r.plan_version = 'ACTIVE' 
  AND r.action_required != 'NONE'
ORDER BY 
    CASE r.action_required
        WHEN 'EXPEDITE' THEN 1
        WHEN 'CREATE_ORDER' THEN 2
        WHEN 'RESCHEDULE' THEN 3
        WHEN 'CANCEL' THEN 4
    END,
    r.requirement_date;

-- Add indexes for audit performance
CREATE INDEX IF NOT EXISTS idx_audit_mrp_table ON audit_logs(table_name);