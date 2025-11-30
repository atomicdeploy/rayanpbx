# Copilot Instructions for RayanPBX

## Project Overview
RayanPBX is a comprehensive management, installation, monitoring, and control suite for Asterisk and VoIP phones. It provides TUI (Text User Interface), CLI, and Web Admin Panel interfaces for managing SIP extensions, trunks, and VoIP devices like GrandStream and YeaLink phones.

## Tech Stack
- **Backend**: Laravel (PHP) with MySQL/MariaDB
- **Frontend**: Nuxt (Vue 3) with modern SPA/PWA design
- **TUI**: Go-based terminal user interface
- **SIP Server**: Asterisk with PJSIP

## Critical Rules - DO NOT VIOLATE

### go.mod Protection
**NEVER remove lines from `go.mod`** - This has caused repeated breakages. Always verify your commits don't remove go.mod dependencies. If you accidentally remove lines, restore them immediately.

### Code Must Actually Work
- **Run the code, don't simulate it**. Execute actual commands instead of mocking/placeholders.
- If functionality takes time or has token constraints, modify scripts to be usable by both developers and CI/CD.
- If something is out of scope, clearly communicate this to the user - don't leave TODOs or not-implemented stubs.

### DRY Principle
- **Reuse existing code**. Look for existing implementations before creating new ones.
- Avoid WET (Write Everything Twice) code patterns.
- Check `backend/`, `tui/`, and `frontend/` for existing functionality that can be extended.

### File Organization
- Documentation goes in `docs/` directory, not the project root.
- Test scripts go in `tests/` or `scripts/tests/`, not randomly in `scripts/`.
- Configuration samples go in `samples/`.

### Legacy Code Policy
- No need to keep legacy code or deprecation notices - project is in fast-paced development.
- When removing code, ensure a better alternative is already in place.
- Migrate all useful content before deleting anything.

## Development Guidelines

### Running Tests and Builds
```bash
# Backend (Laravel)
cd backend && composer install && php artisan test

# Frontend (Nuxt)
cd frontend && npm install && npm run build

# TUI (Go)
cd tui && go build ./...
```

### Installation Script
The `./install.sh` script supports step-based installation:
- Use `--steps` flag to run specific steps
- Use `--skip` flag to skip certain steps
- Step-specific packages should only install during their respective steps

### Configuration Files
- Asterisk configs: `/etc/asterisk/pjsip.conf`, `/etc/asterisk/extensions.conf`
- Always reload Asterisk after config changes: `asterisk -rx "pjsip reload"` and `asterisk -rx "dialplan reload"`
- Use managed block markers: `# BEGIN MANAGED` / `# END MANAGED`

### PJSIP Configuration Best Practices
When generating SIP extension configs:
1. Use separate blocks for endpoint, auth, and aor (PJSIP modular architecture)
2. Include `direct_media=no` for NAT-friendly behavior
3. Set `qualify_frequency=60` for keepalive pings
4. Support multiple codecs: ulaw, alaw, g722
5. Specify transport explicitly (e.g., `transport-udp`)

### Database and Asterisk Sync
- Always cross-check database state with Asterisk's live configuration
- Provide sync functionality when mismatches are detected
- Use `pjsip show endpoints` to verify Asterisk state

## Common Pitfalls to Avoid

1. **Hardcoded paths/ports/URLs** - Use environment variables and configuration
2. **Cluttered post-install messages** - Keep output clean and organized
3. **Duplicate CI/CD tasks** - Consolidate similar workflows
4. **Missing port validation** - Verify Asterisk is actually listening on configured ports
5. **Incomplete SIP testing** - Use actual SIP clients (sipsak, sipexer, sipp) to validate

## API Development
- Replace manual curl calls with Guzzle or Laravel's HTTP client
- Include User-Agent with software name and version
- Respect `HTTP_PROXY`/`HTTPS_PROXY` environment variables
- Implement proper error handling with actionable messages

## UI/UX Requirements
- Web UI must be professionally designed with elegant theming
- Support dark mode
- Include helpful notes for users unfamiliar with PBX terminology
- Show real-time status (registered endpoints, trunk state)
- Use event-driven updates rather than polling where possible

## VoIP Phone Integration (GrandStream Focus)
- Support Action URL webhooks for phone events
- Implement routes for: Setup Completed, Registered, Unregistered, Call events, etc.
- Allow manual IP specification for unregistered phones

## Security
- Use PAM-based authentication (like Cockpit/Webmin)
- Never store passwords in plaintext - use bcrypt/argon2
- Implement CSRF protection and rate limiting
- Validate all SIP URIs and extension ranges

## CI/CD
- Ensure all CI/CD jobs pass before merging
- Use `./install.sh` with specific steps for CI - avoid duplication
- Test actual Asterisk connectivity, not just code compilation
