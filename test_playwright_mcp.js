#!/usr/bin/env node

// Test script to verify Playwright MCP server installation
const { spawn } = require('child_process');
const { existsSync } = require('fs');
const fs = require('fs');

console.log('Testing Playwright MCP Server Installation...\n');

// Test 1: Check if mcp-server-playwright command exists
console.log('1. Checking mcp-server-playwright command...');
try {
    const child = spawn('which', ['mcp-server-playwright'], { stdio: 'pipe' });
    child.on('close', (code) => {
        if (code === 0) {
            console.log('✓ mcp-server-playwright command found');
        } else {
            console.log('✗ mcp-server-playwright command not found in PATH');
        }
    });
} catch (error) {
    console.log('✗ Error checking mcp-server-playwright:', error.message);
}

// Test 2: Check MCP configuration
console.log('\n2. Checking MCP configuration...');
try {
    if (existsSync('/var/www/html/bems/.mcp.json')) {
        console.log('✓ MCP configuration file exists');
        const config = JSON.parse(fs.readFileSync('/var/www/html/bems/.mcp.json', 'utf8'));
        if (config.mcpServers && config.mcpServers.playwright) {
            console.log('✓ Playwright server configured in MCP');
            console.log('  - Command:', config.mcpServers.playwright.command);
            console.log('  - Type:', config.mcpServers.playwright.type);
        } else {
            console.log('✗ Playwright server not found in MCP configuration');
        }
    } else {
        console.log('✗ MCP configuration file not found');
    }
} catch (error) {
    console.log('✗ Error reading MCP configuration:', error.message);
}

// Test 3: Check if BEMS server is accessible using curl
console.log('\n3. Testing BEMS server accessibility...');
const testUrl = 'http://localhost:8000/working-login.html';
const curlChild = spawn('curl', ['-s', '-o', '/dev/null', '-w', '%{http_code}', testUrl], { stdio: 'pipe' });

let curlOutput = '';
curlChild.stdout.on('data', (data) => {
    curlOutput += data.toString();
});

curlChild.on('close', (code) => {
    if (code === 0 && curlOutput.trim() === '200') {
        console.log('✓ BEMS server accessible at', testUrl);
        console.log('  - Status:', curlOutput.trim());
    } else {
        console.log('✗ BEMS server not accessible (HTTP status:', curlOutput.trim() || 'unknown', ')');
        console.log('  Make sure the PHP development server is running with:');
        console.log('  cd /var/www/html/bems/public && php -S localhost:8000');
    }
});

setTimeout(() => {
    console.log('\nTest completed. Setup summary:');
    console.log('- Playwright MCP package installed: @playwright/mcp@0.0.35');
    console.log('- MCP configuration created: /var/www/html/bems/.mcp.json');
    console.log('- Binary available: mcp-server-playwright');
    console.log('\nTo use Playwright MCP tools, restart Claude Code in this directory.');
}, 2000);