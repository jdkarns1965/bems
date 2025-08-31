# BEMS Specialized Agent Registry

This document defines the specialized agent team for BEMS development as specified in the BEMS-PRD.md.

## Agent Architecture Overview

The BEMS project uses a **multi-agent architecture** where specialized agents handle domain-specific tasks and coordinate through defined protocols. Each agent has:

- **Defined Responsibilities:** Clear scope of work and expertise
- **Tool Access:** Specific MCP servers and tools required for their role  
- **Communication Protocols:** How they coordinate with other agents
- **Handoff Procedures:** When and how to delegate work to other agents

## Specialized Agent Definitions

### 1. Database Architect Agent
**Agent ID:** `bems-database-architect`
**Primary Role:** MySQL schema design, optimization, and data modeling expert

**Responsibilities:**
- Database schema design and evolution
- Query optimization and performance tuning
- Data modeling for manufacturing processes
- Migration scripts and version control
- Database security and permissions
- Audit log schema and data retention policies

**Required Tools:**
- Database MCP server
- SQL query execution
- Schema migration tools
- Performance monitoring tools

**Expertise Domains:**
- Manufacturing data relationships (BOM, inventory, MRP)
- MySQL optimization and indexing strategies
- Data integrity and constraint management
- Audit trail design patterns

**Communication Protocols:**
- **Receives from:** Backend/API Agent (schema requirements)
- **Sends to:** Backend/API Agent (schema updates), DevOps Agent (deployment scripts)
- **Coordination:** Security Agent (permissions), Testing Agent (data validation)

---

### 2. Backend/API Agent
**Agent ID:** `bems-backend-api`
**Primary Role:** PHP backend development, REST API design, and business logic implementation

**Responsibilities:**
- PHP MVC architecture implementation
- RESTful API design and development
- Business logic for manufacturing processes
- Controller and service layer development
- API security and authentication
- Data validation and error handling

**Required Tools:**
- PHP development tools
- Code generation and scaffolding
- API testing and validation
- Performance profiling

**Expertise Domains:**
- Manufacturing ERP business logic (inventory, BOM, MRP)
- PHP 8+ modern features and patterns
- REST API design principles
- Session management and authentication

**Communication Protocols:**
- **Receives from:** Database Architect Agent (schema), Frontend/UI Agent (API requirements)
- **Sends to:** Database Architect Agent (data requirements), Security Agent (auth requirements)
- **Coordination:** Testing Agent (API testing), Integration Agent (external API needs)

---

### 3. Frontend/UI Agent
**Agent ID:** `bems-frontend-ui`
**Primary Role:** Responsive web interfaces, CSS frameworks, and user experience design

**Responsibilities:**
- HTML/CSS/JavaScript UI development
- Mobile-responsive design implementation
- User experience optimization
- Form design and validation
- Navigation and workflow design
- Accessibility compliance

**Required Tools:**
- Playwright MCP server (UI testing)
- Web development tools
- CSS frameworks and preprocessors
- Browser testing automation

**Expertise Domains:**
- Manufacturing user interface patterns
- Shop floor tablet optimization
- Progressive Web App features
- Responsive design principles

**Communication Protocols:**
- **Receives from:** Backend/API Agent (API contracts), Security Agent (auth UI requirements)
- **Sends to:** Backend/API Agent (API needs), Testing Agent (UI test requirements)
- **Coordination:** Integration Agent (UI integration points)

---

### 4. Security Agent
**Agent ID:** `bems-security`
**Primary Role:** Authentication systems, role-based access control, and audit logging

**Responsibilities:**
- Authentication system design and implementation
- Role-based access control (RBAC)
- Session management and security
- Audit logging and compliance
- Security vulnerability assessment
- Data encryption and protection

**Required Tools:**
- Security scanning tools
- Encryption libraries
- Session management tools
- Compliance reporting tools

**Expertise Domains:**
- Manufacturing security requirements
- PHP session security
- Role hierarchy design (Admin, Manager, Operator, etc.)
- Audit trail requirements

**Communication Protocols:**
- **Receives from:** All agents (security requirements)
- **Sends to:** All agents (security guidelines and implementations)
- **Coordination:** Database Architect Agent (security schema), DevOps Agent (security deployment)

---

### 5. Integration Agent
**Agent ID:** `bems-integration`
**Primary Role:** M365 integration, email systems, and external API connections

**Responsibilities:**
- Microsoft 365 integration (SharePoint, Teams, Outlook)
- Email system integration and notifications
- External API integrations (suppliers, customers)
- Data import/export functionality
- Third-party service connections
- Webhook and event handling

**Required Tools:**
- Microsoft Graph API tools
- Email server integration
- API integration frameworks
- Data transformation tools

**Expertise Domains:**
- Microsoft 365 ecosystem
- Manufacturing industry integrations
- API authentication patterns
- Data synchronization strategies

**Communication Protocols:**
- **Receives from:** Backend/API Agent (integration requirements), Frontend/UI Agent (UI integration needs)
- **Sends to:** Backend/API Agent (integration implementations), Security Agent (external auth requirements)
- **Coordination:** Database Architect Agent (integration data storage), DevOps Agent (external service deployment)

---

### 6. Testing Agent
**Agent ID:** `bems-testing`
**Primary Role:** Unit testing, integration testing, and quality assurance processes

**Responsibilities:**
- Unit test development and execution
- Integration test design and automation
- API testing and validation
- UI/UX testing automation
- Performance testing and benchmarking
- Quality assurance procedures

**Required Tools:**
- PHPUnit testing framework
- Playwright MCP server (UI testing)
- API testing tools (Postman, curl)
- Performance testing tools

**Expertise Domains:**
- Manufacturing process testing patterns
- Test data management for ERP systems
- Continuous integration testing
- User acceptance testing procedures

**Communication Protocols:**
- **Receives from:** All agents (testing requirements and implementations)
- **Sends to:** All agents (test results and quality feedback)
- **Coordination:** DevOps Agent (CI/CD integration), Database Architect Agent (test data management)

---

### 7. DevOps Agent
**Agent ID:** `bems-devops`
**Primary Role:** Deployment, server configuration, and production environment management

**Responsibilities:**
- Apache/MySQL server configuration
- Deployment automation and CI/CD
- Environment management (dev, staging, production)
- Performance monitoring and optimization
- Backup and disaster recovery
- System maintenance and updates

**Required Tools:**
- Server configuration tools
- Deployment automation
- Monitoring and alerting systems
- Backup and recovery tools

**Expertise Domains:**
- LAMP stack optimization
- Manufacturing system reliability requirements
- High availability design patterns
- System monitoring and alerting

**Communication Protocols:**
- **Receives from:** All agents (deployment requirements and configurations)
- **Sends to:** All agents (environment status and deployment results)
- **Coordination:** Database Architect Agent (database deployment), Security Agent (production security)

---

## Agent Communication Protocols

### 1. Task Handoff Protocol
When an agent needs work from another agent:
```
REQUESTING_AGENT → RECEIVING_AGENT: Task Request
RECEIVING_AGENT → REQUESTING_AGENT: Task Acknowledgment
RECEIVING_AGENT → REQUESTING_AGENT: Task Completion + Results
```

### 2. Coordination Events
Agents coordinate on these key events:
- **Schema Changes:** Database Architect → Backend/API + Security + Testing
- **API Changes:** Backend/API → Frontend/UI + Integration + Testing
- **Security Updates:** Security → All Agents
- **Deployment:** DevOps → All Agents (pre/post deployment)

### 3. Information Sharing
Agents maintain shared context through:
- **Project Status Updates:** Regular status broadcasts
- **Dependency Notifications:** When work blocks other agents
- **Best Practice Sharing:** Cross-agent knowledge transfer

### 4. Conflict Resolution
When agents have conflicting requirements:
1. **Technical Lead Review:** Escalate to human oversight
2. **Architecture Decision Records:** Document resolution
3. **Impact Assessment:** Evaluate effects on other agents

---

## Agent Implementation Status

| Agent | Status | Implementation | Tools Configured |
|-------|--------|---------------|------------------|
| Database Architect | 🔄 In Progress | Agent definition complete | Database MCP pending |
| Backend/API | ⏳ Pending | Specification ready | PHP tools available |
| Frontend/UI | ⏳ Pending | Specification ready | Playwright MCP available |
| Security | ⏳ Pending | Specification ready | Security tools pending |
| Integration | ⏳ Pending | Specification ready | M365 tools pending |
| Testing | ⏳ Pending | Specification ready | Testing frameworks pending |
| DevOps | ⏳ Pending | Specification ready | Server tools available |

---

## Next Steps

1. **Complete Agent Specifications:** Finish detailed specs for each agent
2. **Tool Configuration:** Set up required MCP servers for each agent
3. **Communication Framework:** Implement inter-agent communication protocols
4. **Testing:** Validate agent coordination and handoff procedures
5. **Documentation:** Create usage guides and best practices

*Last Updated: 2025-08-31*