# Project Requirements Document (PRD)

**Project:** ERP/MRP System with MES Extensions and Light SCM  
**Owner:** Sean  
**Goal:** Build a modular ERP for a plastic injection molding company, starting with ERP core functions, then extending into MES, and optionally SCM.

## 1. Overview

We are developing a comprehensive, AI-enhanced database-driven ERP/MRP system to streamline operations for a plastic injection molding manufacturer. The system will be built step-by-step, starting with ERP core functionality (User Authentication, Inventory, BOM, MRP, FG/Shipping), then extended with MES modules (production tracking, quality approval, palletizing), lightweight SCM features (supplier tracking, purchasing workflows), and advanced capabilities including AI-driven document processing, intelligent quality analysis, smart form auto-population, and an integrated employee communications platform for operational coordination and company-wide announcements.

## 2. Objectives

- Centralize data for inventory, production, and shipping
- Provide a lightweight but secure user authentication and role system
- Track raw materials, packaging, and finished goods with lot/INV tags
- Plan production via BOM + MRP (time-phased)
- Extend ERP into MES for real-time shop-floor execution (quality, production logs)
- Cover basic SCM needs (supplier data, purchasing, lead times)
- **Implement AI-driven automation** for document processing, quality analysis, and operational intelligence
- **Enable smart, context-aware user interfaces** that minimize data entry through intelligent form auto-population
- **Provide integrated employee communications platform** for operational coordination, quality alerts, time-off requests, and company announcements

## 3. Scope & Phases

### Phase 0 – User Authentication & Audit

**User Stories:**
- As an Admin, I want to create user accounts with clock numbers (usernames), real names, and initials so I can control access and track operations
- As an Admin, I want to issue credentials to users without requiring email accounts
- As an Admin, I want to prevent users from changing their own passwords to maintain security control
- As a Manager, I want to assist users with password changes when requested
- As an HR Employee, I want to manage employee information and post company announcements
- As a Planner, I want to log in using my assigned clock number and password to manage inventory and production plans
- As an Operator, I want to log in with minimal permissions so I can only record production and palletizing
- As a Material Handler, I want to move materials between locations and update inventory
- As a Mold Setter, I want to install/remove molds, connect water lines, and set up auxiliary equipment (and also process parts when needed)
- As a Processor, I want to start up molding presses and process parts that I believe are good (and assist with mold setup when needed)
- As a Quality Manager, I want to approve or reject parts for production based on specifications
- As a Quality Technician, I want to approve or reject parts for production based on specifications
- As a Manager, I want an audit trail so I know who made what changes

**Acceptance Criteria:**
- ✅ User accounts include clock number (username for login), real name, and initials
- ✅ System displays real names in user interfaces and reports
- ✅ Initials used for quick identification in operations (production logs, quality approvals, material movements)
- ✅ Admin issues passwords - users cannot self-register or change passwords
- ✅ Only Admin and Manager roles can reset/change user passwords
- ✅ Users must request password assistance from Admin or Manager
- ✅ Users can register/login/logout securely using clock number and password
- ✅ Roles: Admin, Manager, HR Employee, Planner, Operator, Material Handler, Mold Setter, Processor, Quality Manager, Quality Technician, Viewer
- ✅ HR Employee role can post holiday schedules and manage employee-related announcements
- ✅ Mold Setter role has permissions for both mold setup operations and processing functions
- ✅ Processor role has permissions for both processing operations and assisting with mold setup
- ✅ Quality department (Quality Manager and Quality Technicians) only handles part approval, not setup approval
- ✅ Passwords hashed (not plain text)
- ✅ Sessions persist across requests until logout/timeout
- ✅ Audit log captures clock number, real name, action, timestamp

### Phase 1 – Inventory Module

**User Stories:**
- As a Receiver, I want to enter new resin/materials into inventory so they can be tracked
- As a Planner, I want to see inventory levels by lot and location so I can plan production
- As an Operator, I want to move resin from Receiving → Storage → Press-side
- As a Material Handler, I want to move materials to any location I see fit and update the system location
- As a Material Handler, I want to scan location tags when available or manually enter locations like "Press 16" when no scannable tag exists
- As QA, I want lot tracking so I can trace materials used in production

**Acceptance Criteria:**
- ✅ Materials can be added using material_item_number (dropdown linked to Materials table)
- ✅ System generates unique INV tag for each new lot/entry
- ✅ Inventory tracks: material, quantity, unit, lot, location, status
- ✅ **Flexible Location Updates:** Material handlers can update locations by:
  - Scanning location tags (when available)
  - Manual entry for non-tagged areas (Press 16, Press-side Bay 3, etc.)
  - Free-form location entry with standardized suggestions
- ✅ **Real-time Location Tracking:** Location changes update immediately across all system functions
- ✅ Movements update location history (Receiving → Rack → Bay → Press) with user and timestamp
- ✅ Partial consumption (e.g., bag/Gaylord) updates balance correctly
- ✅ **Movement Audit Trail:** System logs who moved material, when, from where to where

### Phase 2 – BOM (Bill of Materials)

**User Stories:**
- As an Engineer, I want to define BOMs so products can consume the right materials
- As a Planner, I want to link BOMs to inventory so MRP can calculate requirements
- As QA, I want BOMs to support multi-level assemblies for traceability

**Acceptance Criteria:**
- ✅ BOM can define multiple material items per finished product
- ✅ Supports multi-level (assembly of subcomponents)
- ✅ BOM integrates with Materials table (not free-text)
- ✅ Quantity per product defined and validated

### Phase 3 – MRP (Material Requirements Planning)

**User Stories:**
- As a Planner, I want to generate material requirements so I know when to order
- As a Manager, I want MRP runs to consider lead times so production isn't delayed
- As an Operator, I want MRP suggestions translated into clear production/purchase orders

**Acceptance Criteria:**
- ✅ MRP input: MPS + customer orders
- ✅ Time-phased netting against on-hand inventory
- ✅ Output: Gross Req, Net Req, Planned Orders
- ✅ Lead times offset required dates properly
- ✅ Planned orders saved in a table with status (firm, suggested)

### Phase 4 – Finished Goods & Shipping

**User Stories:**
- As an Operator, I want to palletize and label finished goods so they are ready for shipping
- As a Planner, I want FG inventory updated when pallets are created
- As a Shipping Clerk, I want ASN data prepared for customers with flexible delivery options
- As a Shipping Clerk, I want to submit ASNs through customer portals when required
- As a Shipping Clerk, I want EDI capability for customers who require electronic ASN submission
- As an Admin, I want to manage label templates and print professional labels without ongoing software costs
- As a User, I want to print INV tags and other labels directly from BEMS to Zebra printers

**Acceptance Criteria:**
- ✅ FG inventory tracks part, skid #, QPC, lot, and packaging
- ✅ Palletizing workflow creates label records and updates FG stock
- ✅ Labels generated in Word/PDF (customer format)
- ✅ **Automated Shipping Number Generation:** System creates shipping numbers using delivery date + location abbreviation format (e.g., "081625-SLB" for Shelbyville delivery on 08/16/2025)
- ✅ **Consistent Document Numbering:** Same shipping number used across PO, Packing List, and ASN submission
- ✅ **Direct ZPL Label Printing:** System generates and prints labels directly to Zebra printers using ZPL commands without third-party software
- ✅ **Label Template Management:** 
  - Phase 1: Import existing Bartender templates exported as ZPL code
  - Store ZPL templates in BEMS database with variable placeholders ({INV_NUMBER}, {PART_NUMBER}, {DATE})
  - Support multiple label types (INV tags, shipping labels, quality labels, part labels)
- ✅ **INV Tag Serialization:** Auto-increment INV numbers (INV10001, INV10002, etc.) with configurable ranges and rollover logic
- ✅ **Variable Data Integration:** Labels pull real-time data from BEMS database (part numbers, dates, lots, quantities)
- ✅ **Network Printing:** Print to any Zebra printer on network from any BEMS workstation
- ✅ **Future Label Designer:** Phase 7+ built-in simple label designer for template modifications and new label creation
- ✅ **Flexible ASN Management:** System tracks customer-specific ASN requirements:
  - No ASN required (default setting, can be changed if requirements change)
  - Manual portal submission (system prepares data for manual entry into customer systems)
  - EDI transmission (automated electronic submission)
- ✅ **Customer Portal Integration:** For customers requiring manual ASN submission (like Nifco Oracle iSupplier Portal), system provides:
  - Pre-formatted data matching portal field requirements
  - **Automated Shipping Number Generation:** System creates shipping numbers using delivery date + location abbreviation format (e.g., "081625-SLB" for Shelbyville delivery on 08/16/2025)
  - **Consistent Document Numbering:** Same shipping number used across PO, Packing List, and ASN submission
  - **Location Abbreviation Management:** System maintains customer location codes (SLB=Shelbyville KY, CNL=Canal Winchester OH, etc.) with support for multiple abbreviations per location when customers use different codes
  - One-click copy functions for easy data transfer into customer portals
  - Portal-specific templates (Oracle iSupplier, SAP Ariba, etc.)
  - Screen-by-screen data preparation matching customer portal workflows
- ✅ **EDI Capability:** System can generate and transmit EDI documents for customers with EDI requirements:
  - **EDI 856** - Advance Ship Notice (ASN) for shipment notifications
  - **EDI 862** - Planning Schedule for receiving customer forecasts and delivery schedules
- ✅ **ASN Data Preparation:** All customers get ASN data prepared regardless of submission method for future flexibility
- ✅ **Customer Configuration:** Admin can set ASN requirements per customer (None/Portal/EDI) and change as requirements evolve

### Phase 5 – MES Extensions

**User Stories:**
- As a Processor, I want to provide the first five full shots/good parts to Quality for inspection
- As a Quality Manager, I want to inspect first article parts and approve/reject production go
- As a Quality Technician, I want to inspect first article parts and approve/reject production go
- As a Processor, I want to record production cycles after receiving production go approval
- As a Processor, I want the system to automatically recommend which material inventory to use based on FIFO and BOM requirements
- As a Material Handler, I want to see exactly which INV tags to pull for upcoming production runs
- As a Mold Setter, I want to complete mold setup and mark it ready for production
- As a Processor, I want to inspect setup if something seems out of place before beginning production
- As a Quality Manager, I want AI-generated reports that quantify the business impact of quality issues
- As a Quality Technician, I want to see root cause patterns and trends across quality data
- As a Shift Supervisor, I want to communicate production status and issues to the next shift
- As a Shift Lead, I want to see what the previous shift accomplished and any pending issues
- As a Maintenance Manager, I want to track press schedules, maintenance requirements, and capabilities
- As a Mold Setter, I want to track mold locations, maintenance cycles, and shot counts
- As a Production Planner, I want to manage customer part numbers vs internal part numbers
- As a Supervisor, I want downtime and scrap recorded for performance tracking

**Acceptance Criteria:**
- ✅ **Setup Workflow:** Mold Setter completes setup → Processor inspects setup (if needed) → Processor runs first five shots
- ✅ **First Article Inspection:** Processor provides first 5 full shots/good parts → Quality department inspects → Approves/rejects production go
- ✅ **Production Workflow:** After production go approval → Processor records ongoing cycles → Quality spot checks as needed
- ✅ **Intelligent Material Selection:** When production order is selected, system automatically recommends specific INV tags based on:
  - BOM requirements (correct material type/grade)
  - Strict FIFO logic (oldest inventory first, regardless of location)
  - Quality status (only approved/released material)
  - Quantity requirements (multiple INV tags if needed for full order)
- ✅ **Material Consumption Tracking:** System updates inventory balances and tracks partial usage when materials are consumed
- ✅ **Material Pull Lists:** Generate pick lists for Material Handlers showing exact INV tags and locations for upcoming production
- ✅ **Shift Handoff System:** 
  - Digital shift notes and status updates
  - Production summary (completed jobs, issues encountered, pending tasks)
  - Equipment status and any problems reported
  - Priority items for incoming shift attention
- ✅ **Equipment/Press Management:**
  - Press master data (capabilities, tonnage, shot capacity, maintenance schedules)
  - Press scheduling and availability tracking
  - Maintenance logs and service history
  - Equipment performance metrics and downtime tracking
- ✅ **Tool/Mold Tracking:**
  - Mold master data (part numbers, cavity count, maintenance intervals)
  - Current mold location (storage, on press, maintenance area)
  - Shot count tracking per mold with maintenance triggers
  - Mold maintenance history and service records
  - Mold availability and scheduling
- ✅ **Customer Master Data Management:**
  - Customer contact information and shipping addresses
  - Customer-specific requirements (packaging, labeling, ASN preferences)
  - Multiple ship-to locations per customer
  - Customer communication preferences and portal requirements
- ✅ **Part Number Management:**
  - **Multi-level part number system** supporting complex automotive supply chain numbering:
    - Internal part numbers (your system - consistent across all operations)
    - Direct customer part numbers (e.g., Nifco: 26833)
    - End customer part numbers (e.g., automotive OEM: T1548-0005AH2)
    - Multiple customer part numbers per single internal part
  - **Cross-reference matrix** linking all part number variations to single internal master
  - **Context-aware displays** - Show appropriate part number based on user/document type:
    - Internal operations: Always use internal part numbers
    - Customer communications: Display customer's part numbers
    - Shipping documents: Show customer part numbers
    - End customer documents: Display OEM part numbers when required
  - **Search capability** across all part number types
  - **Audit trail** showing part number usage and relationships
- ✅ Production logs: press, part, shot count, good qty, scrap qty, operator (Mold Setter or Processor)
- ✅ First article inspection required before full production can begin
- ✅ Quality department (Quality Manager or Quality Technician) approves production go based on first article parts
- ✅ Ongoing production cycles only recorded after production go approval
- ✅ **AI Quality Impact Analysis:** System analyzes quality rejections and calculates business impact (scrap costs, downtime, material waste, customer delays)
- ✅ **Root Cause Pattern Recognition:** AI identifies trends across molds, presses, operators, shifts, and time periods
- ✅ **Quality Cost Reports:** AI generates reports showing financial impact of quality issues for prioritization and ROI justification
- ✅ **Trend Analysis:** AI tracks quality performance improvements after corrective actions and identifies systemic vs random defects
- ✅ Downtime reasons selectable from a list
- ✅ MES data updates FG inventory only after production go approval
- ✅ Rejected first articles require setup adjustment before new first article submission
- ✅ Setup verification handled within production team, first article inspection managed by quality department

### Phase 6 – SCM (Lightweight)

**User Stories:**
- As a Buyer, I want to create purchase orders for resin/materials
- As a Manager, I want to track supplier performance (on-time, late)
- As a Receiver, I want to confirm deliveries against POs
- As a Receiver, I want to scan packing slips and have AI automatically extract and enter material data
- As a Receiver, I want scanned documents automatically filed in the correct supplier folders
- As a Compliance Manager, I want packing slips automatically emailed to suppliers like Nifco within required timeframes
- As a Manager, I want alerts when compliance deadlines are approaching or missed

**Acceptance Criteria:**
- ✅ Supplier master table with contacts, lead times, and email requirements
- ✅ POs track supplier, material, qty, due date, status
- ✅ Receiving validates against open POs with discrepancy alerts
- ✅ **AI Document Processing:** Scan packing slips/lists → AI extracts supplier, materials, quantities, PO numbers, dates
- ✅ **Signature/Date Detection:** AI scans document focusing on middle and bottom areas where signatures/scribbles typically appear, and looks for handwritten dates anywhere on the page
- ✅ **Receiving Verification:** System alerts if no handwritten marks detected anywhere on document, logged-in user identity provides receiver identification
- ✅ **Smart Form Presentation:** Pre-populated receiving form shown to logged-in user with all scanned data and "Receiving" location default
- ✅ **PO Validation:** AI matches scanned data against open POs and flags discrepancies
- ✅ **Automated Workflow Alerts:** Upon receiving confirmation, system notifies Material Handlers, Managers, and designated roles of shipment ready for warehouse movement
- ✅ **Location Tracking:** Materials begin in "Receiving" location with clear workflow for movement to proper warehouse storage
- ✅ **Automated Document Filing:** Scanned documents filed in supplier-specific folder structure (Supplier → Date → PO)
- ✅ **Conditional Email Automation:** System checks supplier rules and auto-emails packing slips when required
- ✅ **Compliance Monitoring:** Alerts for missed deadlines (e.g., Nifco 24-hour requirement), email delivery tracking
- ✅ **Audit Trail:** Complete tracking of document processing, email deliveries, compliance actions, and receiving verification
- ✅ Reports: supplier on-time vs late, shortages, compliance status dashboard

## 4. Non-Goals

- No advanced global SCM (multi-site balancing, logistics optimization)
- No HR/Payroll integration at this stage
- No financial accounting beyond basic purchasing data

## 5. Technical Notes

- **Stack:** LAMP (Linux, Apache, MySQL, PHP) - **Already Installed and Ready**
- **System:** WSL/Windows environment with Claude Code installed
- **Database Name:** BEMS (Best ERP Manufacturing System)
- **Architecture:** API-first design with RESTful endpoints serving web interface and enabling future mobile applications
- **Design:** Database-first design; schema evolves per phase
- **Development Approach:** OOP approach for maintainability
- **Structure:** Modular architecture (ERP core, MES extension, SCM add-on, Communication platform)
- **UI:** Web-first responsive interface consuming REST APIs, mobile-friendly, lightweight CSS
- **Integration:** M365 email integration for outbound communications and alerts
- **Future Mobile Ready:** API foundation supports Android/iOS apps for shop floor operations (inventory scanning, production logging, quality inspection)

### **PRIORITY: Development Environment Setup**
**Claude Code must address the complete development environment setup BEFORE any planning or coding activities begin (unless absolutely necessary for setup).**

**Environment Status:**
- ✅ LAMP Stack installed and operational
- ✅ Claude Code installed and ready
- ⚠️ **REQUIRED:** Configure Claude Code permissions for full system access

**Required Setup Tasks:**
1. **System Permissions Configuration** - Grant Claude Code full access to:
   - Apache web server (configuration, virtual hosts, document root)
   - MySQL database server (create/modify BEMS database, user management, schema operations)
   - PHP configuration and module management
   - WSL/Windows file system (read/write access to project directories)
   - Service management (start/stop/restart Apache, MySQL services)

2. **BEMS Database Setup** - Create and configure BEMS database with proper permissions
3. **MCP Server Configuration** - Install and configure all required MCP servers (Playwright, Database, Git, File System, PDF, OCR/AI, Document Management, Monitoring)
4. **Claude Code Agent Creation** - Establish specialized agent team with defined roles and responsibilities
5. **Agent Communication Protocols** - Set up coordination methods between specialized agents
6. **Development Workspace** - Configure VS Code environment with necessary extensions and integrations
7. **Initial Project Structure** - Create base directory structure and configuration files within Apache document root
8. **Version Control** - Initialize Git repository with appropriate branching strategy

**Permission Verification Checklist:**
- Claude Code can create/modify Apache virtual hosts and configurations
- Claude Code can create BEMS database and manage MySQL users/permissions
- Claude Code can read/write PHP files and modify php.ini settings
- Claude Code can access WSL file system and Windows directories as needed
- Claude Code can restart services (Apache, MySQL) when configuration changes are made
- Each specialized agent can access their required MCP servers and tools
- Agents can communicate and coordinate effectively
- Database connectivity and initial BEMS schema creation capabilities confirmed

### Navigation & User Experience
- **Role-based dashboards** - Each user role sees relevant tools and information immediately upon login
- **Quick access patterns** - Frequently used functions (production logging, inventory lookup, part search) prominently featured
- **Mobile-responsive design** - Touch-friendly interface optimized for shop floor tablets and mobile devices
- **Contextual navigation** - Clear breadcrumbs and workflow indicators for complex multi-step processes
- **Search functionality** - Global search for part numbers, INV tags, orders, and materials
- **Single-click common actions** - Minimize clicks for routine tasks like material movements and production entries

### **Smart Form Intelligence & Auto-Population**
- **Context-Aware Forms** - System pre-populates fields with known information based on user context, location, and recent activities
- **Predictive Data Entry** - Forms anticipate user needs by suggesting relevant materials, parts, and settings based on current workflow
- **Session Memory** - System remembers what user was working on (press, part, location) and carries context forward
- **Barcode/Tag Integration** - Scanning INV tags, part numbers, or equipment codes auto-fills all related form fields
- **Historical Intelligence** - System suggests materials, settings, and configurations based on previous successful operations
- **Role-Based Defaults** - Forms automatically populate user name, department, and permission-appropriate options
- **Workflow Context** - Forms adapt based on current process (setup vs production vs quality vs shipping)
- **Minimal Dropdowns** - Replace dropdown selections with smart auto-complete and predictive text where possible
- **One-Touch Common Operations** - Frequently performed tasks require minimal manual data entry

**Smart Form Examples:**
- Production logging auto-fills operator name, current press, last part run
- Inventory movements pre-populate current location when INV tag is scanned
- BOM creation suggests materials used in similar parts
- Quality inspection forms remember last inspection criteria for the same part
- Material receiving auto-populates supplier info when PO number is entered

### Development Team Structure
**Claude Code Agent Specialization:**
- **Database Architect Agent** - MySQL schema design, optimization, and data modeling expert
- **Backend/API Agent** - PHP backend development, REST API design, and business logic implementation
- **Frontend/UI Agent** - Responsive web interfaces, CSS frameworks, and user experience design
- **Security Agent** - Authentication systems, role-based access control, and audit logging
- **Integration Agent** - M365 integration, email systems, and external API connections
- **Testing Agent** - Unit testing, integration testing, and quality assurance processes
- **DevOps Agent** - Deployment, server configuration, and production environment management

### MCP Server Tools
- **Playwright MCP** - Visual frontend design, automated testing, and UI component validation
- **Database MCP** - Schema management, query optimization, and data migration support
- **Git MCP** - Version control, branch management, and code collaboration workflows
- **File System MCP** - Project file management, documentation, and configuration handling
- **PDF MCP** - Report generation, label creation, and documentation export
- **OCR/AI MCP** - Document scanning, signature presence detection, date recognition, and intelligent data processing for packing slips
- **Document Management MCP** - Automated filing, folder organization, and document retrieval systems
- **Monitoring MCP** - System health tracking and performance monitoring (production phase)

*Additional MCP servers to be evaluated based on development needs - maintaining focus on core functionality over tool complexity.*

## 6. Future Communication & Collaboration Phase

### Phase 7 – Communication Platform
**User Stories:**
- As a Quality Manager, I want to post quality alerts that all project employees can see
- As a Manager, I want to send system-generated reports via email to external stakeholders
- As an Employee, I want to communicate with colleagues about work processes through internal messaging
- As a Processor, I want to receive automated alerts when materials are running low
- As an Employee, I want to request time off for doctors appointments and personal needs
- As an HR Employee, I want to post holiday schedules and company announcements
- As a Manager, I want to approve/deny time off requests from my team
- As an Admin, I want to control who can send emails outside the system for security

**Acceptance Criteria:**
- ✅ Quality alerts broadcast to all employees on relevant projects
- ✅ M365 integration for outbound email (documents, reports, alerts)
- ✅ Internal messaging system for process coordination
- ✅ Lightweight social platform for company-wide and department-specific communication
- ✅ Time off request system with manager approval workflow
- ✅ HR posting capabilities for holiday schedules, company announcements
- ✅ Manager dashboard for pending time off requests
- ✅ Automated alert system for critical events (inventory, quality, production issues)
- ✅ Role-based email sending permissions for security control
- ✅ Communication audit trail for work-related discussions
- ✅ Calendar integration showing approved time off and company holidays

## 7. Communication & Integration Features

### Email Integration (M365)
- **Outbound Email:** System can send documents, reports, and notifications to any email address
- **M365 Integration:** Admin, Manager, and designated roles have M365 accounts for external communication
- **Security:** Email sending permissions controlled by role-based access

### Alert System
- **Automated Alerts:** System-generated notifications for production issues, inventory levels, quality concerns
- **Alert Recipients:** Configurable by role and department
- **Delivery Methods:** In-system notifications, email alerts (for M365 users), dashboard alerts

### Internal Communication Platform
- **Process Communication:** Direct messaging between employees for work-related coordination
- **Role-Based Messaging:** Communication channels organized by department/function
- **Work Orders/Notes:** Attached communications to specific jobs, parts, or processes

### Lightweight Social Platform
- **Company Feed:** General communication and announcements
- **Department Boards:** Specific communication areas (Production, Quality, Materials, etc.)
- **Quality Alerts:** Priority posts from Quality Manager/Managers visible to all relevant employees
- **Project-Specific Communication:** Communication threads tied to specific production runs or projects

**Communication Acceptance Criteria:**
- ✅ Quality Manager and Managers can post priority alerts visible to all project employees
- ✅ Role-based access to communication channels (Production, Quality, Materials, etc.)
- ✅ System can send emails through M365 integration with proper security controls
- ✅ Internal messaging system for process coordination
- ✅ Automated alerts for critical system events (low inventory, quality issues, etc.)
- ✅ Communication audit trail for work-related discussions

---

This PRD keeps it modular, phased, and clear — Claude Code can pick this up and start generating classes, DB schemas, or routes per phase.