# Playwright MCP Server Setup Report for BEMS

## Setup Overview

Successfully configured Playwright MCP server for browser automation testing in the BEMS (Manufacturing ERP System) project. The setup enables programmatic testing of the web interface through Claude Code's MCP integration.

## Installation Details

### 1. Environment Information
- **Project Location**: `/var/www/html/bems`
- **Server**: PHP development server running on `localhost:8000`
- **Document Root**: `/var/www/html/bems/public/`
- **Target Page**: `http://localhost:8000/working-login.html`

### 2. Playwright MCP Package
- **Package**: `@playwright/mcp@0.0.35`
- **Installation**: Global installation via npm
- **Binary Location**: `/home/jdkarns1965/.npm-global/bin/mcp-server-playwright`
- **Installation Command**: `npm install -g @playwright/mcp`

### 3. MCP Configuration
- **Configuration File**: `/var/www/html/bems/.mcp.json`
- **Server Name**: `playwright`
- **Transport Type**: `stdio`
- **Command**: `mcp-server-playwright`

Configuration JSON:
```json
{
  "mcpServers": {
    "playwright": {
      "type": "stdio",
      "command": "mcp-server-playwright",
      "args": [],
      "env": {}
    }
  }
}
```

## Available Capabilities

The Playwright MCP server provides the following browser automation capabilities:

### Core Browser Operations
- **Multi-browser support**: Chrome, Firefox, WebKit, Microsoft Edge
- **Page navigation**: Navigate to URLs, handle redirects
- **Element interaction**: Click, type, select, hover
- **Form handling**: Fill forms, submit, validate inputs
- **Screenshot capture**: Full page and element screenshots
- **PDF generation**: Convert pages to PDF

### Advanced Features
- **Device emulation**: Test responsive designs with device presets
- **Network interception**: Monitor and modify network requests
- **Storage manipulation**: Cookies, localStorage, sessionStorage
- **JavaScript execution**: Run custom scripts in browser context
- **Wait strategies**: Wait for elements, network, timeouts
- **Multi-tab support**: Handle multiple browser tabs/windows

### Configuration Options
- **Headless/Headed mode**: Run with or without visible browser
- **Viewport customization**: Set custom screen resolutions
- **User agent strings**: Customize browser identification
- **Proxy settings**: Route traffic through proxies
- **Security options**: Handle HTTPS errors, disable sandbox

## Test Target: BEMS Login Page

### Page Analysis
- **URL**: `http://localhost:8000/working-login.html`
- **Status**: ✅ Accessible (HTTP 200)
- **Type**: Login form with demo credentials
- **Framework**: Vanilla HTML/CSS/JavaScript with AJAX

### Login Form Elements
- **Clock Number Field**: `#clockNumber` (default: "ADMIN001")
- **Password Field**: `#password` (default: "AdminPassword123!")
- **Submit Button**: `.login-btn`
- **Message Area**: `#message`

### Test Scenarios Enabled
1. **Form Validation Testing**: Test required field validation
2. **Authentication Flow**: Test login with valid/invalid credentials
3. **UI Responsiveness**: Test form behavior and feedback messages
4. **Cross-browser Testing**: Verify consistency across browsers
5. **Screenshot Documentation**: Capture visual states for documentation

## Usage Instructions

### Starting Claude Code with MCP
1. Navigate to the project directory:
   ```bash
   cd /var/www/html/bems
   ```

2. Ensure the PHP server is running:
   ```bash
   cd public && php -S localhost:8000
   ```

3. Start Claude Code in the project directory:
   ```bash
   claude
   ```

### Example Browser Automation Commands
Once connected to Claude Code with MCP enabled, you can use commands like:

- "Take a screenshot of the login page"
- "Fill in the login form and submit it"
- "Test the login functionality with invalid credentials"
- "Check if the page is mobile-responsive"
- "Generate a PDF of the current page"

### Verification Commands
Run the test script to verify the setup:
```bash
node test_playwright_mcp.js
```

Expected output should show:
- ✅ mcp-server-playwright command found
- ✅ MCP configuration file exists  
- ✅ Playwright server configured in MCP
- ✅ BEMS server accessible

## Troubleshooting

### Common Issues and Solutions

1. **MCP Tools Not Available**
   - Restart Claude Code in the project directory
   - Verify `.mcp.json` exists in the working directory
   - Check that `mcp-server-playwright` is in PATH

2. **BEMS Server Not Accessible**
   - Start the PHP development server: `cd public && php -S localhost:8000`
   - Verify port 8000 is not in use by another service
   - Check firewall settings if accessing remotely

3. **Permission Issues**
   - Ensure `mcp-server-playwright` has execute permissions
   - Check npm global installation permissions
   - Verify user has access to the project directory

4. **Browser Launch Issues**
   - Install required system dependencies for headless browsers
   - For WSL: May require additional configuration for GUI applications
   - Consider using `--no-sandbox` flag for containerized environments

## Next Steps

### Recommended Testing Workflows
1. **Automated UI Testing**: Create test suites for critical user flows
2. **Visual Regression Testing**: Capture screenshots for visual comparisons
3. **Performance Testing**: Monitor page load times and responsiveness
4. **Cross-browser Validation**: Test across different browser engines
5. **Accessibility Testing**: Validate WCAG compliance

### Integration Opportunities
- **CI/CD Pipeline**: Integrate browser tests into build processes
- **Documentation**: Auto-generate screenshots for user manuals
- **Quality Assurance**: Automated regression testing for releases
- **User Acceptance Testing**: Streamline UAT processes

## Technical Notes

- **MCP Protocol**: Uses Model Context Protocol for Claude integration
- **Transport**: stdio-based communication between Claude and Playwright
- **Browser Persistence**: Configurable session persistence and isolation
- **Resource Management**: Automatic cleanup of browser instances
- **Security**: Isolated browser profiles prevent data leakage

## Files Created/Modified

1. **`/var/www/html/bems/.mcp.json`** - MCP server configuration
2. **`/var/www/html/bems/test_playwright_mcp.js`** - Verification test script
3. **`/var/www/html/bems/playwright_mcp_setup_guide.md`** - This documentation

---

**Setup Status**: ✅ Complete and Functional
**Last Updated**: August 30, 2025
**Setup Time**: ~5 minutes
**Browser Automation**: Ready for use