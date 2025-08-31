# BEMS System Architecture & Troubleshooting Guide

## Core Design Principles
- **API-First**: All business logic in backend API endpoints
- **MVC Pattern**: Models, Controllers, Views separation
- **OOP**: Object-oriented middleware and services
- **Session-based Auth**: PHP sessions with CSRF protection

## Dual API System Architecture
BEMS has **TWO** distinct API systems:

### 1. MVC API (`/index.php`)
- **Route**: `/bems/public/index.php?route=/api/v1/*`
- **Features**: Full middleware stack, authentication, CSRF protection
- **Use Cases**: Products, authenticated operations
- **Authentication**: Required for all endpoints
- **CSRF**: Required for POST/PUT/DELETE

### 2. Simple API (`/api-simple.php`) 
- **Route**: `/bems/public/api-simple.php?route=*`
- **Features**: Direct database access, no middleware
- **Use Cases**: Materials, basic operations
- **Authentication**: None
- **CSRF**: None

## Request Flow Pattern
1. Browser → Apache → Router (index.php or api-simple.php)
2. Router → Middleware Chain → Controller → Model
3. Response ← JSON API ← Controller ← Model

## Middleware Stack (MVC API Only)
Applied in this exact order:
1. **RateLimitMiddleware**: Prevents abuse
2. **AuthMiddleware**: Session authentication 
3. **RoleMiddleware**: Permission checking
4. **CSRFMiddleware**: Token validation for state-changing operations

## PROVEN Form Submission Pattern

**⚠️ CRITICAL: ALL NEW FORMS MUST FOLLOW THIS EXACT PATTERN**

### 1. HTML Structure
```html
<script src="js/utils.js"></script>
<script src="js/api-client.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', async () => {
        const user = await bemsUtils.checkAuth();
        if (user) {
            setTimeout(() => bemsUtils.initUserMenu(), 100);
        }
        
        // Add event listeners INSIDE DOMContentLoaded
        document.getElementById('form-id').addEventListener('submit', handleSubmit);
    });
</script>
```

### 2. Authentication Flow
1. Page loads → `bemsUtils.checkAuth()` checks `/auth/status`
2. If not authenticated → automatic redirect to login
3. If authenticated → `bemsUtils.initUserMenu()` displays user info

### 3. Form Submission (MVC API)
```javascript
async function handleSubmit(e) {
    e.preventDefault();
    const formData = new FormData(e.target);
    const data = Object.fromEntries(formData.entries());
    
    try {
        const response = await bemsAPI.createProduct(data); // Auto-handles CSRF
        if (response.success) {
            showSuccess('Success message');
        }
    } catch (error) {
        showError(error.message);
    }
}
```

### 4. Form Submission (Simple API)
```javascript
const response = await fetch('/bems/public/api-simple.php?route=create_material', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(data) // No CSRF needed
});
```

## CSRF Implementation Details
- Token generated per session in `CSRFMiddleware`
- `bemsAPI` client automatically fetches and includes token
- All POST/PUT/DELETE to MVC API require valid token
- Token included as `X-CSRF-Token` header
- **Never manually handle CSRF** - let `bemsAPI` do it

## Database Schema Alignment
**⚠️ CRITICAL: Controllers must match actual database schema**

### Common Issue: Column Mismatch
```php
// WRONG - assumes columns that don't exist
INSERT INTO products (dimensions, material_composition, ...)

// CORRECT - check actual table structure first
DESCRIBE products;
INSERT INTO products (internal_part_number, product_name, ...)
```

## File Structure
```
/index.php                 # MVC API router
/api-simple.php           # Simple API router  
/js/api-client.js         # MVC API client (handles CSRF)
/js/utils.js              # Auth helpers (checkAuth, initUserMenu)
/app/controllers/         # MVC business logic
/app/middleware/          # Request middleware
/app/models/              # Data models
```

## Troubleshooting Guide

### Problem: "CSRF token validation failed"
**Solution**: Use MVC API pattern with `bemsAPI` client
```javascript
// WRONG
fetch('/bems/public/index.php?route=/api/v1/products', {...})

// CORRECT  
await bemsAPI.createProduct(data); // Handles CSRF automatically
```

### Problem: "Authentication required"
**Solution**: Use proper authentication pattern
```javascript
// Add to every page
document.addEventListener('DOMContentLoaded', async () => {
    const user = await bemsUtils.checkAuth(); // Auto-redirects if not logged in
    if (user) {
        setTimeout(() => bemsUtils.initUserMenu(), 100);
    }
});
```

### Problem: "Column not found" database errors
**Solution**: Check actual table schema
```bash
sudo mysql -u root BEMS -e "DESCRIBE table_name;"
```

### Problem: Connection reset / 500 errors
**Solution**: Check which API system to use
- Products, Users, Auth → **MVC API** (`bemsAPI` client)
- Materials, Simple operations → **Simple API** (direct fetch)

## Development Workflow

### When Creating New Forms:
1. **Copy existing working form** (material-create.html or product-create.html)
2. **Check database schema** for target table
3. **Use appropriate API system** (MVC vs Simple)
4. **Follow proven authentication pattern** 
5. **Test in browser context** with actual login

### When Troubleshooting:
1. **Check Apache error logs**: `sudo tail -f /var/log/apache2/error.log`
2. **Use browser dev tools**: Console + Network tabs
3. **Reference this documentation**
4. **Test with curl** for API endpoints

## Reference Examples

### Working Form Examples:
- **MVC API**: `/product-create.html` (authentication + CSRF)
- **Simple API**: `/material-create.html` (no auth/CSRF)

### Working Controllers:
- **MVC**: `/app/controllers/ProductController.php`
- **Simple**: `/api-simple.php` routes