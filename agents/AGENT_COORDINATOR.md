# BEMS Agent Coordinator

**Purpose:** Functional implementation of the BEMS specialized agent system that enables actual agent coordination and task delegation.

## Implementation Strategy

Based on the existing Claude Code agent system, we need to create **functional coordination patterns** that allow the documented agents to actually work together.

### **Current Reality Check:**
- Claude Code provides 3 built-in agents: `general-purpose`, `statusline-setup`, `output-style-setup`
- Our BEMS agents are currently **documentation only**
- We need to bridge the gap between documentation and functional implementation

### **Functional Implementation Approach:**

#### 1. **Agent Task Delegation Pattern**
Use the existing `general-purpose` agent with specific prompts that embody each specialized agent's expertise:

```javascript
// Database Architect Agent Tasks
Task(subagent_type: "general-purpose", 
     description: "Database schema optimization",
     prompt: "Act as the BEMS Database Architect Agent with expertise in...")

// Backend/API Agent Tasks  
Task(subagent_type: "general-purpose",
     description: "API endpoint development", 
     prompt: "Act as the BEMS Backend/API Agent with expertise in...")
```

#### 2. **Agent Communication Protocol Implementation**
Create a coordination system that:
- Routes different types of tasks to appropriate "agent personas"
- Maintains context between related tasks
- Follows handoff procedures defined in agent specifications

#### 3. **Specialized Prompt Templates**
Create specific prompt templates that activate each agent's expertise domain:

**Database Architect Agent Activation:**
```
You are the BEMS Database Architect Agent. Your expertise includes:
- MySQL schema design for manufacturing ERP systems
- Query optimization for inventory, BOM, and MRP operations  
- Database security and audit logging
- Manufacturing data relationships and constraints

Current task: [specific database task]
Context: [relevant database context]
Coordinate with: [other agents as needed]
```

## Functional Agent Implementation

### **Phase 1: Core Agent Functions**
Implement the most critical agent functions using the general-purpose agent with specialized prompts:

1. **Database Architect Agent**
   - Schema analysis and optimization
   - Query performance tuning
   - Data integrity validation

2. **Backend/API Agent**  
   - API endpoint development
   - Business logic implementation
   - Controller/service layer coordination

3. **Security Agent**
   - Authentication system validation
   - Role-based access control
   - Audit logging verification

### **Phase 2: Agent Coordination Workflows**
Implement specific workflows that demonstrate agent coordination:

1. **New Feature Development Workflow:**
   ```
   Frontend/UI Agent → Backend/API Agent → Database Architect Agent → Testing Agent
   ```

2. **Security Update Workflow:**
   ```  
   Security Agent → All Agents → Testing Agent → DevOps Agent
   ```

3. **Performance Optimization Workflow:**
   ```
   Database Architect Agent → Backend/API Agent → Testing Agent
   ```

### **Phase 3: Advanced Coordination**
- Multi-agent task coordination
- Context sharing between agents
- Conflict resolution mechanisms

## Implementation Files

This coordinator will create:

1. **`/var/www/html/bems/agents/prompts/`** - Specialized agent prompt templates
2. **`/var/www/html/bems/agents/workflows/`** - Agent coordination workflows  
3. **`/var/www/html/bems/agents/examples/`** - Working examples of agent coordination

## Success Metrics

The functional agent system will be considered successful when:

1. **Task Routing**: Different types of development tasks are automatically routed to appropriate agent expertise
2. **Context Continuity**: Agents maintain context when handing off work to other agents
3. **Specialized Output**: Each agent produces work that reflects their specialized expertise
4. **Coordination**: Multi-agent workflows complete complex tasks that require multiple specialties

## Next Steps

1. Create specialized prompt templates for each agent
2. Implement basic task routing based on task type
3. Test agent coordination with actual BEMS development tasks
4. Validate that agents produce specialized, expert-level output
5. Demonstrate multi-agent workflows in action

This moves beyond documentation to create a **functional multi-agent development system** that can actually coordinate specialized expertise for BEMS development tasks.

---

*This functional implementation bridges the gap between the theoretical agent architecture and practical development coordination.*