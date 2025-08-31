# BEMS Troubleshooting Log

This document maintains a record of critical issues encountered during BEMS development to prevent recurrence and provide quick resolution guidance.

## Critical Issues Log

### Issue #001: BOM/MRP Authentication Redirect Loop
**Date:** 2025-08-31  
**Severity:** Critical  
**Status:** RESOLVED  

**Symptoms:**
- BOM and MRP navigation links redirect to login page
- Dashboard and Inventory navigation work correctly
- Authentication session appears to be lost only for BOM/MRP

**Root Cause:**
Inconsistent authentication endpoint usage between HTML pages:
- **Working pages (Dashboard/Inventory):** Used `GET /api/v1/auth/status`
- **Broken pages (BOM/MRP):** Used `POST /api/v1/auth/status` with JSON body

**Technical Details:**
- The API router only registers: `$this->get("$apiBase/auth/status", [AuthController::class, 'status']);`
- No POST route exists for `/api/v1/auth/status`
- POST requests return 404 "Endpoint not found"
- 404 triggers authentication failure → redirect to login

**Fix Applied:**
Updated `/var/www/html/bems/public/bom.html` and `/var/www/html/bems/public/mrp.html`:

```javascript
// BEFORE (broken)
const response = await fetch('/bems/public/index.php?route=/api/v1/auth/status', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'status' })
});
if (result.success && result.data) { ... }

// AFTER (fixed)
const response = await fetch('/bems/public/index.php?route=/api/v1/auth/status');
const result = await response.json();
if (response.ok && result.authenticated) { ... }
```

**Prevention Guidelines:**
1. **Standardize Authentication Pattern:** All HTML pages must use identical authentication check code
2. **API Documentation:** Maintain clear documentation of available endpoints and HTTP methods
3. **Code Review:** Authentication changes require review across all HTML files
4. **Testing Protocol:** Navigation testing must include all modules before deployment

**Files Modified:**
- `/var/www/html/bems/public/bom.html`
- `/var/www/html/bems/public/mrp.html`

**Verification Steps:**
1. Navigate Dashboard → Inventory → BOM → MRP → Dashboard (all should work)
2. Verify user badge displays correctly on all pages
3. Confirm no 404 errors in browser console
4. Test logout functionality from each page

---

### Issue #002: API Response Format Inconsistency
**Date:** 2025-08-31  
**Severity:** Minor  
**Status:** IDENTIFIED - PENDING FIX  

**Symptoms:**
- JavaScript console errors: `TypeError: materials.forEach is not a function`
- Inventory page loads but shows "No data found" despite API returning 200 status

**Root Cause:**
API responses have inconsistent data structure format. Some return arrays directly, others wrap in objects.

**Investigation Needed:**
- Review MaterialsController and InventoryController response formats
- Standardize API response structure across all endpoints
- Update frontend JavaScript to handle consistent format

---

### Issue #003: Audit Log Database Schema
**Date:** 2025-08-31  
**Severity:** Low  
**Status:** RESOLVED  

**Symptoms:**
- Apache error log: `SQLSTATE[22001]: String data, right truncated: 1406 Data too long for column 'record_id' at row 1`

**Root Cause:**
`audit_logs.record_id` column was VARCHAR(50), too small for session IDs and complex record identifiers.

**Fix Applied:**
```sql
ALTER TABLE BEMS.audit_logs MODIFY COLUMN record_id VARCHAR(255);
```

---

## Development Standards

### Authentication Pattern (MANDATORY)
All HTML pages must use this exact pattern:

```javascript
// On page load - check authentication
window.onload = async function() {
    try {
        const response = await fetch('/bems/public/index.php?route=/api/v1/auth/status');
        const data = await response.json();
        
        if (response.ok && data.authenticated) {
            updateUserInterface(data.user);
        } else {
            window.location.href = '/bems/public/working-login.html';
        }
    } catch (error) {
        console.error('Authentication check failed:', error);
        window.location.href = '/bems/public/working-login.html';
    }
};

// Logout function
async function logout() {
    try {
        const response = await fetch('/bems/public/index.php?route=/api/v1/auth/logout', {
            method: 'POST'
        });
        
        localStorage.removeItem('bems_user');
        window.location.href = '/bems/public/working-login.html';
    } catch (error) {
        console.error('Logout error:', error);
        localStorage.removeItem('bems_user');
        window.location.href = '/bems/public/working-login.html';
    }
}
```

### API Endpoint Standards
- Use proper HTTP methods (GET for retrieval, POST for creation, PUT for updates)
- All endpoints must be registered in `/var/www/html/bems/public/index.php`
- Follow RESTful URL patterns: `/api/v1/resource` or `/api/v1/resource/{id}/action`
- Consistent response format across all controllers

### Testing Checklist
Before marking any navigation/authentication work as complete:

1. **Full Navigation Test:** Dashboard → Inventory → BOM → MRP → Dashboard
2. **Authentication Persistence:** User badge displays correctly on all pages
3. **API Endpoint Verification:** All endpoints return expected HTTP status codes
4. **Console Clean:** No JavaScript errors in browser console
5. **Logout Test:** Logout works correctly from each page
6. **Session Management:** Browser refresh maintains authentication state

### Issue Resolution Process
1. **Identify Scope:** Determine which modules/pages are affected
2. **Root Cause Analysis:** Use agent tools for technical investigation
3. **Document Findings:** Record exact technical details in this log
4. **Apply Fix:** Make minimum necessary changes
5. **Verify Resolution:** Complete testing checklist
6. **Update Documentation:** Add prevention guidelines

---

## Quick Reference

### Common Error Patterns
- **404 on API calls:** Check endpoint registration in `index.php`
- **Authentication loops:** Verify HTTP method matches registered route
- **Console TypeError:** Check API response data structure
- **Database constraints:** Review column sizes and data types

### Key Files
- **Main Router:** `/var/www/html/bems/public/index.php`
- **Frontend Pages:** `/var/www/html/bems/public/*.html`
- **Controllers:** `/var/www/html/bems/app/controllers/*.php`
- **Database Config:** `/var/www/html/bems/config/database.php`

### Debugging Tools
- **Browser Console:** Check for JavaScript errors and failed API calls
- **Apache Error Log:** `/var/log/apache2/error.log`
- **API Testing:** `curl -s "http://localhost/bems/public/index.php?route=/api/v1/endpoint"`
- **Playwright Browser Automation:** For UI testing and debugging

---

*Last Updated: 2025-08-31*