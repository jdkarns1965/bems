# BEMS Phase 1: Inventory Module Database Schema

## Overview

This document describes the Phase 1 database schema for the BEMS (Best ERP Manufacturing System) Inventory Module. The schema implements comprehensive inventory management with materials master data, unique INV tag generation, location tracking, movement audit trail, and FIFO logic support.

## Database Architecture

### Core Design Principles

1. **Unique Inventory Tags**: Every inventory lot receives a unique INV tag (INV10001, INV10002, etc.)
2. **FIFO Logic**: Built-in First-In-First-Out priority calculation for material consumption
3. **Flexible Location Management**: Support for both scannable location codes and manual entry
4. **Complete Audit Trail**: Every movement tracked with full context and user attribution
5. **Partial Consumption**: Support for consuming partial quantities while maintaining lot integrity
6. **Expiration Management**: Automatic expiration date calculation and alerts

## Database Tables

### 1. `inv_tag_sequences` - INV Tag Generation System

Manages auto-incrementing sequences for INV tag generation with configurable ranges.

**Key Features:**
- Multiple sequence support (INV_MAIN, INV_CONSUMABLE, INV_SCRAP)
- Configurable prefix, padding, and increment values
- Rollover protection with min/max ranges
- Sequence audit trail and status management

**Sample Tags:**
- `INV10001`, `INV10002` (Main inventory)
- `CONS50001`, `CONS50002` (Consumables)
- `SCRAP90001`, `SCRAP90002` (Scrap materials)

### 2. `material_categories` - Hierarchical Material Classification

Organizes materials into categories with parent-child relationships.

**Standard Categories:**
- **RAW** - Raw Materials (resins, additives, colorants)
- **PKG** - Packaging Materials (boxes, bags, labels)
- **CHEM** - Chemicals (cleaners, lubricants, process chemicals)
- **TOOLS** - Tooling Materials (spare parts, maintenance items)
- **CONS** - Consumables (office supplies, safety equipment)
- **MRO** - Maintenance, Repair, Operations supplies

### 3. `materials` - Materials Master Data

Complete material specifications with detailed properties and business data.

**Key Information:**
- Material number and descriptions
- Category classification
- Units of measure (primary and alternatives)
- Material grades and specifications
- Shelf life and storage requirements
- Supplier and manufacturer data
- Cost and reorder information
- ABC classification for inventory management

**Sample Materials:**
```
PP-1001-NAT - Polypropylene Natural (Injection Grade)
PE-2001-BLK - Polyethylene Black (Blow Grade)
ADD-001 - UV Stabilizer Additive
BOX-001-SM - Small Shipping Box (12x8x6)
```

### 4. `locations` - Location Master Data

Comprehensive location management with hierarchical structure and scanning support.

**Location Types:**
- **RECEIVING** - Incoming material docks
- **STORAGE** - Warehouse storage areas
- **PRODUCTION** - Manufacturing floor locations
- **SHIPPING** - Outbound material areas
- **QC** - Quality control areas
- **SCRAP** - Waste and scrap storage

**Hierarchical Structure:**
```
Building → Zone → Aisle → Rack → Shelf → Bin
Main Building → Raw Storage → A → A01 → Level 1 → Bin 01
```

**Scanning Support:**
- Barcode data for standard scanners
- QR code data for advanced information
- Manual location override (e.g., "Press 16")

### 5. `inventory` - Core Inventory Tracking

Central inventory table with unique INV tags and comprehensive lot management.

**Core Features:**
- Unique INV tag for each lot/entry
- Material and location relationships
- Quantity tracking (current and original)
- Cost tracking (unit and total)
- Status management (Available, Reserved, On-Hold, etc.)
- Expiration date calculation and tracking
- FIFO priority calculation
- Quality inspection status
- Hold management with reasons

**Status Values:**
- `AVAILABLE` - Ready for consumption
- `RESERVED` - Allocated to specific orders
- `ON_HOLD` - Temporarily unavailable
- `QUARANTINE` - Quality hold pending inspection
- `CONSUMED` - Fully consumed
- `SCRAPPED` - Designated as scrap/waste

### 6. `inventory_movements` - Movement Audit Trail

Complete audit trail of all inventory transactions and movements.

**Movement Types:**
- `RECEIVE` - Initial material receipt
- `TRANSFER` - Location-to-location movement
- `CONSUME` - Production consumption
- `ADJUST` - Manual adjustments
- `SCRAP` - Scrap/waste designation
- `RETURN` - Material returns
- `INSPECT` - Quality inspection movements

**Tracking Information:**
- From/to locations (both structured and manual)
- Quantities and units
- Reference numbers (PO, WO, Job numbers)
- Cost tracking for transactions
- User attribution and timestamps
- Machine/press information
- Operator details

### 7. `inventory_reservations` - Material Reservations

Tracks material allocations for production orders and special purposes.

**Reservation Types:**
- `PRODUCTION` - Reserved for manufacturing
- `QUALITY` - Reserved for quality testing
- `MAINTENANCE` - Reserved for equipment maintenance
- `REWORK` - Reserved for rework operations

**Features:**
- Priority levels (Low, Normal, High, Urgent)
- Work order and job number linking
- Date-based reservations
- Status tracking (Active, Fulfilled, Cancelled, Expired)
- User attribution for accountability

### 8. `inventory_adjustments` - Manual Adjustments and Cycle Counts

Tracks all manual inventory adjustments with approval workflow.

**Adjustment Types:**
- `CYCLE_COUNT` - Regular cycle counting
- `PHYSICAL_COUNT` - Annual physical inventory
- `SHRINKAGE` - Inventory shrinkage adjustments
- `DAMAGE` - Damaged material write-offs
- `CORRECTION` - Error corrections
- `WRITEOFF` - Complete write-offs

**Approval Workflow:**
- Manager approval for significant adjustments
- Supporting documentation requirements
- Financial impact calculations
- Reason code standardization

## Database Views

### 1. `v_inventory_summary` - Comprehensive Inventory Overview

Combines inventory, materials, and location data with calculated fields.

**Key Fields:**
- INV tags and material information
- Current quantities and costs
- Location details (structured and manual)
- Expiration status and alerts
- FIFO priority information

**Alert Status Calculations:**
- `EXPIRED` - Past expiration date
- `EXPIRING_SOON` - Within 30 days of expiration
- `DEPLETED` - Zero or negative quantity
- `NORMAL` - Standard status

### 2. `v_fifo_available_inventory` - FIFO Production View

Optimized view for production material selection using FIFO logic.

**Features:**
- Available quantity calculations (total - reserved)
- FIFO priority ordering
- Expiration date filtering
- Material number grouping
- Location information for picking

### 3. `v_inventory_totals_by_location` - Location Summary

Aggregated inventory totals organized by location and material.

**Aggregations:**
- Total quantities by material/location
- Lot count summaries
- Age analysis (oldest/newest receipts)
- Financial value totals
- Location type grouping

### 4. `v_recent_inventory_movements` - Movement History

Recent movement activity with user and reference information.

**Enhanced Information:**
- User real names (not just clock numbers)
- Complete location descriptions
- Reference number details
- Movement categorization
- Time-based filtering (last 30 days)

### 5. `v_expiring_inventory` - Expiration Alerts

Materials approaching or past expiration dates with urgency levels.

**Urgency Levels:**
- `EXPIRED` - Past expiration date
- `CRITICAL` - Within 7 days
- `WARNING` - Within 30 days
- `NORMAL` - Beyond 60 days

## Stored Procedures and Functions

### Functions

#### `fn_get_next_inv_tag(sequence_name)`
Generates the next INV tag from specified sequence with atomic increment.

**Parameters:**
- `sequence_name` - Sequence identifier (e.g., 'INV_MAIN')

**Returns:**
- Formatted INV tag (e.g., 'INV10001')

#### `fn_calculate_fifo_priority(received_date, expiration_date)`
Calculates FIFO priority value based on receipt and expiration dates.

**Logic:**
- Earlier received date = higher priority (lower number)
- Sooner expiration = higher priority adjustment
- Random tie-breaker for uniqueness

### Stored Procedures

#### `sp_receive_inventory(...)`
Complete material receiving procedure with validation and audit trail.

**Parameters:**
- Material number and lot information
- Quantity and cost data
- Location and supplier details
- User attribution

**Validations:**
- Material exists and is active
- Location exists and is active
- Cost and quantity validation

**Actions:**
- Generates unique INV tag
- Calculates expiration date
- Creates inventory record
- Records initial movement
- Creates audit log entry

#### `sp_transfer_inventory(...)`
Transfers inventory between locations with partial quantity support.

**Features:**
- Partial transfer support
- Location validation (structured or manual)
- Reference number tracking
- Remaining quantity calculation
- Complete audit trail

#### `sp_consume_inventory(...)`
Consumes inventory for production with work order tracking.

**Features:**
- Partial consumption support
- Work order and job linking
- Machine/press tracking
- Operator attribution
- Automatic status updates (CONSUMED when depleted)

## FIFO Logic Implementation

### Priority Calculation

The FIFO system uses a decimal priority value calculated from:

1. **Base Priority**: Unix timestamp of received date
2. **Expiration Adjustment**: Reduction based on days until expiration
3. **Tie-Breaker**: Random decimal component for uniqueness

### Usage in Production

```sql
-- Get next material for consumption (FIFO order)
SELECT inv_tag, quantity, location_code
FROM v_fifo_available_inventory
WHERE material_number = 'PP-1001-NAT'
  AND available_quantity > 0
ORDER BY fifo_priority
LIMIT 1;
```

### Benefits

- **Automatic Ordering**: No manual FIFO management required
- **Expiration Priority**: Materials near expiration consumed first
- **Consistent Results**: Deterministic ordering with tie-breaking
- **Performance Optimized**: Indexed priority field for fast queries

## Location Management System

### Flexible Location Model

The system supports both structured and manual location tracking:

**Structured Locations:**
- Scannable location codes (RAW-A01, PKG-01, etc.)
- Hierarchical organization
- Capacity and environmental controls
- Barcode/QR code support

**Manual Locations:**
- Free-text entry for dynamic locations
- Press numbers (Press 16, Press 21)
- Temporary staging areas
- Work-in-process locations

### Location Types

Different location types support different workflows:

- **RECEIVING**: Incoming material validation and staging
- **STORAGE**: Long-term inventory storage with capacity management
- **PRODUCTION**: Work-in-process and active manufacturing areas
- **SHIPPING**: Outbound material staging and loading
- **QC**: Quality control testing and inspection areas
- **SCRAP**: Waste and scrap material segregation

## Movement Audit Trail

### Complete Traceability

Every inventory transaction creates a movement record with:

- **What**: Material and quantities involved
- **When**: Date and timestamp of movement
- **Who**: User attribution with clock numbers
- **Where**: From and to locations (structured or manual)
- **Why**: Reason codes and descriptions
- **How**: Reference numbers (PO, WO, Job numbers)

### Movement Types and Workflows

Each movement type supports specific business processes:

**RECEIVE Workflow:**
1. Material arrives at receiving dock
2. Quality inspection (if required)
3. Location assignment and put-away
4. System update with INV tag generation

**TRANSFER Workflow:**
1. Pick material from current location
2. Validate destination location
3. Update system with new location
4. Print location labels if needed

**CONSUME Workflow:**
1. Select material using FIFO priority
2. Link to work order/job number
3. Track machine/press and operator
4. Update remaining quantities
5. Mark as consumed if depleted

## Integration with Phase 0 Authentication

### User Attribution

All inventory transactions are linked to the Phase 0 user system:

- **Clock Numbers**: User identification throughout system
- **Real Names**: Display names for reports and audit trails
- **Role-Based Access**: Permission checking for inventory functions
- **Audit Integration**: Inventory actions logged in main audit system

### Permission Integration

Inventory operations respect user roles from Phase 0:

- **Material Handlers**: Can move materials and update locations
- **Planners**: Can manage inventory and view all reports
- **Managers**: Can approve adjustments and access all functions
- **Operators**: Limited to recording consumption at assigned equipment

## Performance Optimization

### Indexing Strategy

Strategic indexes support common query patterns:

**Inventory Table:**
- `inv_tag` (unique) - Primary lookup
- `material_id, status, fifo_priority` - FIFO queries
- `current_location_id` - Location-based queries
- `expiration_date` - Expiration monitoring
- `received_date` - Age analysis

**Movement Table:**
- `inventory_id` - Movement history by inventory
- `movement_date` - Time-based reporting
- `reference_number, reference_type` - Reference lookups
- `created_at` - Recent activity queries

### Query Optimization

Views are optimized for common use cases:

- Pre-calculated availability (total - reserved)
- Location hierarchy flattening
- User name resolution
- Alert status determination

## Data Integrity and Constraints

### Foreign Key Relationships

Strict referential integrity ensures data consistency:

```
inventory.material_id → materials.id
inventory.current_location_id → locations.id
inventory_movements.inventory_id → inventory.id
inventory_movements.from_location_id → locations.id
inventory_movements.to_location_id → locations.id
```

### Business Rules Enforcement

- INV tags must be unique across all sequences
- Quantities cannot be negative
- Movement quantities cannot exceed available inventory
- Expiration dates must be logical (future dates for perishables)
- Status transitions follow defined workflows

### Audit Trail Integrity

- Movement records are append-only (no updates/deletes)
- All changes trigger audit log entries
- User attribution is mandatory for all transactions
- Timestamps are system-generated and immutable

## Sample Data and Testing

### Initial Data Population

The migration includes comprehensive sample data:

**Material Categories:** 6 standard categories with proper hierarchy
**Materials:** 7 sample materials covering different types and units
**Locations:** 11 locations representing complete workflow
**Inventory:** 8 sample lots with proper FIFO priorities

### Test Scenarios

The test script validates:

1. **Schema Structure**: All tables, views, and procedures
2. **INV Tag Generation**: Sequence functionality and uniqueness
3. **FIFO Logic**: Priority calculation and ordering
4. **Material Receiving**: Complete receiving workflow
5. **Inventory Transfers**: Location-to-location movements
6. **Production Consumption**: Work order linking and consumption
7. **Movement Audit**: Complete transaction history
8. **Expiration Management**: Date calculations and alerts
9. **Performance**: Query execution times and optimization

## Installation and Setup

### Prerequisites

- BEMS Phase 0 Authentication system installed
- MySQL/MariaDB 5.7 or higher
- UTF8MB4 character set support
- InnoDB storage engine
- Event scheduler enabled

### Installation Steps

1. **Run Phase 1 Migration:**
```bash
mysql -u root -p BEMS < /var/www/html/bems/database/migrations/002_create_inventory_module_schema.sql
```

2. **Test System Installation:**
```bash
php /var/www/html/bems/database/test_inventory_system.php
```

3. **Verify Sample Data:**
```sql
-- Check inventory summary
SELECT * FROM v_inventory_summary LIMIT 10;

-- Verify FIFO ordering
SELECT material_number, inv_tag, fifo_priority 
FROM v_fifo_available_inventory 
ORDER BY material_number, fifo_priority;

-- Test INV tag generation
SELECT fn_get_next_inv_tag('INV_MAIN') as next_tag;
```

## Usage Examples

### Receiving New Material

```php
// Using stored procedure
$pdo->prepare("CALL sp_receive_inventory(?, ?, ?, ?, ?, ?, ?, ?, ?, @result, @inv_tag)")
    ->execute([
        'PP-1001-NAT',      // material_number
        'LOT-240501',       // lot_number
        25000.00,           // quantity
        0.87,               // unit_cost
        'RAW-A01',          // location_code
        'Polymer Corp',     // supplier_name
        'PO-2024-015',      // purchase_order
        'MAT001',           // received_by
        'Regular delivery'  // notes
    ]);

// Get results
$result = $pdo->query("SELECT @result as result, @inv_tag as inv_tag")->fetch();
if ($result['result'] === 'SUCCESS') {
    echo "Material received with tag: " . $result['inv_tag'];
}
```

### Finding Next Material for Production (FIFO)

```sql
-- Get next available lot for specific material
SELECT inv_tag, lot_number, available_quantity, location_code
FROM v_fifo_available_inventory
WHERE material_number = 'PP-1001-NAT'
  AND available_quantity >= 1000  -- Required quantity
ORDER BY fifo_priority
LIMIT 1;
```

### Transferring Material to Production

```php
// Transfer material to production area
$pdo->prepare("CALL sp_transfer_inventory(?, ?, ?, ?, ?, ?, ?, @result)")
    ->execute([
        'INV10001',         // inv_tag
        null,               // to_location_code (using manual)
        'Press 16',         // to_location_manual
        5000.00,            // quantity
        'WO-2024-100',      // reference_number
        'Transfer to production for molding',  // reason
        'MAT001'            // transferred_by
    ]);
```

### Consuming Material for Production

```php
// Consume material for production
$pdo->prepare("CALL sp_consume_inventory(?, ?, ?, ?, ?, ?, ?, @result)")
    ->execute([
        'INV10001',         // inv_tag
        2500.00,            // quantity
        'WO-2024-100',      // work_order
        'JOB-456',          // job_number
        'Press 16',         // machine_press
        'OP01',             // operator_initials
        'PROC001'           // consumed_by
    ]);
```

### Inventory Reporting

```sql
-- Daily inventory summary by location
SELECT 
    location_code,
    location_name,
    COUNT(*) as lot_count,
    SUM(total_quantity) as total_qty,
    SUM(total_value) as total_value
FROM v_inventory_totals_by_location
GROUP BY location_code, location_name
ORDER BY total_value DESC;

-- Expiring inventory alert
SELECT 
    inv_tag,
    material_number,
    material_name,
    expiration_date,
    days_to_expiration,
    urgency_level
FROM v_expiring_inventory
WHERE urgency_level IN ('EXPIRED', 'CRITICAL')
ORDER BY expiration_date;

-- Recent movement activity
SELECT 
    inv_tag,
    material_number,
    movement_type,
    quantity_moved,
    from_location_code,
    to_location_code,
    movement_date,
    created_by_name
FROM v_recent_inventory_movements
WHERE movement_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
ORDER BY movement_time DESC;
```

## Maintenance and Monitoring

### Automated Maintenance

**Daily Tasks (6:00 AM):**
- Check for expiring inventory
- Generate expiration alerts
- Update material status flags

**Monthly Tasks:**
- Archive old movement records (2+ years)
- Analyze sequence usage and adjust ranges
- Review and cleanup inactive materials/locations

### Performance Monitoring

**Key Metrics:**
- INV tag generation rate and sequence utilization
- Query performance for FIFO operations
- Movement transaction volume
- Storage utilization by location

**Health Checks:**
```sql
-- Check sequence health
SELECT 
    sequence_name,
    current_number,
    (current_number - min_number) / (max_number - min_number) * 100 as percent_used
FROM inv_tag_sequences
WHERE active_status = 1;

-- Check for data consistency
SELECT 
    COUNT(*) as inventory_count,
    SUM(CASE WHEN quantity < 0 THEN 1 ELSE 0 END) as negative_qty,
    SUM(CASE WHEN expiration_date < CURDATE() AND status = 'AVAILABLE' THEN 1 ELSE 0 END) as expired_available
FROM inventory;

-- Movement volume analysis
SELECT 
    DATE(movement_date) as date,
    COUNT(*) as movement_count,
    COUNT(DISTINCT inventory_id) as unique_lots,
    COUNT(DISTINCT created_by) as active_users
FROM inventory_movements
WHERE movement_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
GROUP BY DATE(movement_date)
ORDER BY date DESC;
```

## Integration Points for Future Phases

### Phase 2: BOM Management
- Materials table ready for BOM component relationships
- Cost tracking prepared for BOM costing
- Movement audit supports BOM consumption tracking

### Phase 3: MRP Planning
- Reorder points and quantities established
- Lead time tracking framework in place
- Supplier information ready for procurement planning

### Phase 4: Shipping Module
- Finished goods inventory structure compatible
- Location tracking extends to shipping areas
- Movement audit supports shipping documentation

### Phase 5: MES Integration
- Real-time consumption tracking established
- Machine/press linking in movement records
- Operator attribution for production accountability

## Security Considerations

### Data Protection
- All sensitive cost data encrypted at rest
- User attribution prevents anonymous changes
- Audit trail immutability ensures accountability

### Access Control
- Role-based permissions from Phase 0 system
- Function-level security for inventory operations
- Approval workflows for significant adjustments

### Compliance
- Complete audit trail for regulatory compliance
- Lot traceability for quality investigations
- Expiration management for safety requirements

## Troubleshooting Guide

### Common Issues

**INV Tag Generation Failures:**
- Check sequence table for active status
- Verify sequence hasn't reached maximum value
- Ensure database user has UPDATE privileges

**FIFO Ordering Problems:**
- Verify FIFO priority calculations are unique
- Check for NULL expiration dates affecting calculations
- Ensure proper indexing on priority fields

**Movement Audit Gaps:**
- Verify all procedures use movement tracking
- Check trigger status and functionality
- Ensure user attribution is properly maintained

### Diagnostic Queries

```sql
-- Check for orphaned records
SELECT 'inventory' as table_name, COUNT(*) as orphaned_count
FROM inventory i
LEFT JOIN materials m ON i.material_id = m.id
WHERE m.id IS NULL

UNION ALL

SELECT 'movements' as table_name, COUNT(*) as orphaned_count
FROM inventory_movements im
LEFT JOIN inventory i ON im.inventory_id = i.id
WHERE i.id IS NULL;

-- Verify sequence integrity
SELECT 
    sequence_name,
    current_number,
    (SELECT MAX(CAST(SUBSTRING(inv_tag, LENGTH(prefix) + 1) AS UNSIGNED)) 
     FROM inventory 
     WHERE inv_tag LIKE CONCAT(prefix, '%')) as max_used_number
FROM inv_tag_sequences;

-- Check for duplicate INV tags
SELECT inv_tag, COUNT(*) as duplicate_count
FROM inventory
GROUP BY inv_tag
HAVING COUNT(*) > 1;
```

---

## Summary

The BEMS Phase 1 Inventory Module provides a comprehensive foundation for material management with:

- **Complete Traceability**: Every material lot tracked from receipt to consumption
- **FIFO Logic**: Automated first-in-first-out material rotation
- **Flexible Locations**: Support for both structured and dynamic location management
- **Audit Compliance**: Complete movement history with user attribution
- **Performance Optimized**: Indexed queries and optimized views for production use
- **Integration Ready**: Designed for seamless integration with future BEMS phases

The system is production-ready and provides the solid foundation needed for advanced manufacturing ERP functionality in subsequent phases.