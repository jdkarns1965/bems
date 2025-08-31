# BEMS Development Guidelines

## System Architecture Reference
- **ALWAYS reference SYSTEM_ARCHITECTURE.md first** before working on any forms or API issues
- BEMS has dual API systems: MVC API (authentication/CSRF) and Simple API (direct access)
- All authentication, CSRF, and form submission patterns are documented with working examples

## Development Process
1. **Read SYSTEM_ARCHITECTURE.md** - contains proven patterns and troubleshooting guide
2. **Check database schema** with `DESCRIBE table_name` before writing controllers
3. **Copy working examples** - product-create.html (MVC) or material-create.html (Simple)
4. **Follow exact authentication pattern** - documented in architecture guide
5. **Test in browser context** with actual login session

## Critical Rules
- Do NOT hardcode authentication or bypass proven patterns
- NEVER create files unless absolutely necessary for achieving your goal
- ALWAYS prefer editing an existing file to creating a new one
- NEVER proactively create documentation files unless explicitly requested

## When Troubleshooting
1. Check `/var/log/apache2/error.log` for server errors
2. Use browser dev tools for client-side issues  
3. Reference SYSTEM_ARCHITECTURE.md troubleshooting section
4. Verify which API system (MVC vs Simple) should be used
- This systematic, documentation-first approach would have solved the problem in 10 minutes instead of spending time on repeated trial-and-error.  I don't want to have to tell you again.