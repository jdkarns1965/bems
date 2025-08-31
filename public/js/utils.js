/**
 * BEMS Utility Functions
 * Common helper functions for the frontend
 */

// Load user info and check authentication
async function loadUserInfo() {
    try {
        const user = await checkAuth();
        if (user) {
            const userInfoDiv = document.getElementById('user-info');
            if (userInfoDiv) {
                userInfoDiv.innerHTML = `
                    <div class="user-name">${user.first_name} ${user.last_name}</div>
                    <div class="user-role">${user.role}</div>
                    <button class="btn-logout" onclick="logout()">Logout</button>
                `;
            }
        }
    } catch (error) {
        console.error('Failed to load user info:', error);
    }
}


// Format date for display
function formatDate(dateString) {
    if (!dateString) return '';
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
}

// Format currency
function formatCurrency(amount) {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD'
    }).format(amount || 0);
}

// Format number with commas
function formatNumber(num) {
    return new Intl.NumberFormat('en-US').format(num || 0);
}

// Show notification
function showNotification(message, type = 'info') {
    const notification = document.createElement('div');
    notification.className = `notification notification-${type}`;
    notification.textContent = message;
    
    // Add styles if not already in page
    if (!document.getElementById('notification-styles')) {
        const styles = document.createElement('style');
        styles.id = 'notification-styles';
        styles.textContent = `
            .notification {
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 15px 20px;
                border-radius: 4px;
                color: white;
                font-weight: 500;
                z-index: 9999;
                animation: slideIn 0.3s ease-out;
                max-width: 400px;
            }
            .notification-success { background: #10b981; }
            .notification-error { background: #ef4444; }
            .notification-warning { background: #f59e0b; }
            .notification-info { background: #3b82f6; }
            @keyframes slideIn {
                from { transform: translateX(100%); opacity: 0; }
                to { transform: translateX(0); opacity: 1; }
            }
            @keyframes slideOut {
                from { transform: translateX(0); opacity: 1; }
                to { transform: translateX(100%); opacity: 0; }
            }
        `;
        document.head.appendChild(styles);
    }
    
    document.body.appendChild(notification);
    
    // Auto remove after 5 seconds
    setTimeout(() => {
        notification.style.animation = 'slideOut 0.3s ease-out';
        setTimeout(() => notification.remove(), 300);
    }, 5000);
}

// Show loading spinner
function showLoading(container) {
    const loader = document.createElement('div');
    loader.className = 'loading-spinner';
    loader.innerHTML = `
        <style>
            .loading-spinner {
                display: flex;
                justify-content: center;
                align-items: center;
                padding: 40px;
            }
            .spinner {
                border: 3px solid #f3f4f6;
                border-top: 3px solid #3b82f6;
                border-radius: 50%;
                width: 40px;
                height: 40px;
                animation: spin 1s linear infinite;
            }
            @keyframes spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
        </style>
        <div class="spinner"></div>
    `;
    
    if (container) {
        container.innerHTML = '';
        container.appendChild(loader);
    }
    
    return loader;
}

// Hide loading spinner
function hideLoading(container) {
    const loader = container.querySelector('.loading-spinner');
    if (loader) {
        loader.remove();
    }
}

// Create data table
function createDataTable(data, columns, container) {
    const table = document.createElement('table');
    table.className = 'data-table';
    
    // Create header
    const thead = document.createElement('thead');
    const headerRow = document.createElement('tr');
    columns.forEach(col => {
        const th = document.createElement('th');
        th.textContent = col.label;
        if (col.sortable) {
            th.className = 'sortable';
            th.onclick = () => sortTable(table, col.field);
        }
        headerRow.appendChild(th);
    });
    thead.appendChild(headerRow);
    table.appendChild(thead);
    
    // Create body
    const tbody = document.createElement('tbody');
    data.forEach(row => {
        const tr = document.createElement('tr');
        columns.forEach(col => {
            const td = document.createElement('td');
            
            // Handle different data types
            if (col.render) {
                td.innerHTML = col.render(row[col.field], row);
            } else if (col.type === 'currency') {
                td.textContent = formatCurrency(row[col.field]);
            } else if (col.type === 'number') {
                td.textContent = formatNumber(row[col.field]);
            } else if (col.type === 'date') {
                td.textContent = formatDate(row[col.field]);
            } else {
                td.textContent = row[col.field] || '';
            }
            
            tr.appendChild(td);
        });
        tbody.appendChild(tr);
    });
    table.appendChild(tbody);
    
    // Add table styles if not already in page
    if (!document.getElementById('table-styles')) {
        const styles = document.createElement('style');
        styles.id = 'table-styles';
        styles.textContent = `
            .data-table {
                width: 100%;
                border-collapse: collapse;
                background: white;
                box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            }
            .data-table th {
                background: #f3f4f6;
                padding: 12px;
                text-align: left;
                font-weight: 600;
                color: #374151;
                border-bottom: 2px solid #e5e7eb;
            }
            .data-table th.sortable {
                cursor: pointer;
                user-select: none;
            }
            .data-table th.sortable:hover {
                background: #e5e7eb;
            }
            .data-table td {
                padding: 12px;
                border-bottom: 1px solid #e5e7eb;
            }
            .data-table tbody tr:hover {
                background: #f9fafb;
            }
        `;
        document.head.appendChild(styles);
    }
    
    if (container) {
        container.innerHTML = '';
        container.appendChild(table);
    }
    
    return table;
}

// Sort table
function sortTable(table, field) {
    const tbody = table.querySelector('tbody');
    const rows = Array.from(tbody.querySelectorAll('tr'));
    const headerCells = table.querySelectorAll('th');
    const columnIndex = Array.from(headerCells).findIndex(th => 
        th.textContent === field || th.dataset.field === field
    );
    
    if (columnIndex === -1) return;
    
    // Determine sort direction
    const currentOrder = table.dataset.sortOrder || 'asc';
    const newOrder = currentOrder === 'asc' ? 'desc' : 'asc';
    table.dataset.sortOrder = newOrder;
    
    // Sort rows
    rows.sort((a, b) => {
        const aValue = a.cells[columnIndex].textContent;
        const bValue = b.cells[columnIndex].textContent;
        
        // Try to parse as number
        const aNum = parseFloat(aValue.replace(/[^0-9.-]/g, ''));
        const bNum = parseFloat(bValue.replace(/[^0-9.-]/g, ''));
        
        if (!isNaN(aNum) && !isNaN(bNum)) {
            return newOrder === 'asc' ? aNum - bNum : bNum - aNum;
        }
        
        // Sort as string
        if (newOrder === 'asc') {
            return aValue.localeCompare(bValue);
        }
        return bValue.localeCompare(aValue);
    });
    
    // Re-append sorted rows
    rows.forEach(row => tbody.appendChild(row));
}

// Debounce function for search inputs
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// Check authentication status
async function checkAuth() {
    try {
        const response = await bemsAPI.checkAuth();
        if (response.authenticated) {
            // Store user data for UI use
            localStorage.setItem('user_data', JSON.stringify(response.user));
            return response.user;
        }
    } catch (error) {
        console.error('Auth check failed:', error);
        // Clear any stale user data
        localStorage.removeItem('user_data');
    }
    
    // Redirect to login if not authenticated
    if (!window.location.pathname.includes('/login.html')) {
        window.location.href = '/bems/public/login.html';
    }
    return null;
}

// Logout function
async function logout() {
    try {
        await bemsAPI.logout();
        showNotification('Logged out successfully', 'success');
    } catch (error) {
        console.error('Logout failed:', error);
        showNotification('Logout failed', 'error');
    } finally {
        // Clear all client-side storage
        localStorage.clear();
        sessionStorage.clear();
        
        // Clear cookies by setting them to expire
        document.cookie.split(";").forEach(function(c) { 
            document.cookie = c.replace(/^ +/, "").replace(/=.*/, "=;expires=" + new Date().toUTCString() + ";path=/"); 
        });
        
        // Force page reload to clear any cached data
        window.location.replace('/bems/public/login.html');
    }
}

// Initialize user menu
function initUserMenu() {
    const userData = localStorage.getItem('user_data');
    if (!userData) return;
    
    const user = JSON.parse(userData);
    const userInfo = document.getElementById('user-info');
    if (userInfo) {
        userInfo.innerHTML = `
            <span class="user-name">${user.real_name}</span>
            <span class="user-role">(${user.role_name})</span>
            <button onclick="logout()" class="btn-logout">Logout</button>
        `;
    }
}

// Export functions for use in other scripts
window.bemsUtils = {
    formatDate,
    formatCurrency,
    formatNumber,
    showNotification,
    showLoading,
    hideLoading,
    createDataTable,
    sortTable,
    debounce,
    checkAuth,
    logout,
    initUserMenu
};