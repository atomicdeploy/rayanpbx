# Install Script Step Dependencies

This document describes the dependencies between installation steps in `install.sh`.

## Step Dependency Matrix

### Core System Steps
- `updates` - No dependencies (checks for git updates)
- `system-verification` - No dependencies (checks Ubuntu version)
- `package-manager` - Depends on: `system-verification`
- `system-update` - Depends on: `package-manager`
- `dependencies` - Depends on: `system-update`
- `github-cli` - Depends on: `package-manager`, `system-update`

### Database & Backend Runtime
- `database` - Depends on: `system-update`, `dependencies`
- `php` - Depends on: `system-update`
- `composer` - Depends on: `php`

### Frontend Runtime
- `nodejs` - Depends on: `system-update`

### TUI Runtime
- `go` - Depends on: `system-update`

### Application Components
- `asterisk` - Depends on: `dependencies`
- `asterisk-ami` - Depends on: `asterisk`, `source`
- `source` - No dependencies (git clone)
- `env-config` - Depends on: `source`, `database` (for DB credentials)

### Application Setup
- `backend` - Depends on: `php`, `composer`, `source`, `env-config`, `database`
- `frontend` - Depends on: `nodejs`, `source`, `env-config`
- `tui` - Depends on: `go`, `source`

### Service Management
- `pm2` - Depends on: `nodejs`, `frontend`, `tui`
- `systemd` - Depends on: `backend`
- `cron` - Depends on: `backend`
- `health-check` - Depends on: `systemd`, `pm2`
- `complete` - Depends on: all steps

## Common Use Cases

### Full Installation
```bash
sudo ./install.sh
```

### Backend Only Update
```bash
sudo ./install.sh --steps=source,env-config,backend,systemd
```
Prerequisites: PHP, Composer, Database must already be installed.

### Frontend Only Update
```bash
sudo ./install.sh --steps=source,env-config,frontend,pm2
```
Prerequisites: Node.js must already be installed.

### TUI Only Update
```bash
sudo ./install.sh --steps=source,tui,pm2
```
Prerequisites: Go must already be installed.

### Skip Asterisk (for development)
```bash
sudo ./install.sh --skip=asterisk,asterisk-ami
```

### CI/CD Mode (specific components)
```bash
./install.sh --ci --steps=backend,frontend,tui
```

## Warning Messages

When steps are skipped or only specific steps are run, the script will proceed but may fail if dependencies are not met. The user is responsible for ensuring:

1. All required runtime dependencies are installed
2. Source code is present if building applications
3. Configuration files exist if starting services

## Minimum Step Sets

### Backend Development
```bash
--steps=source,env-config,backend
```
Requires: PHP 8.3, Composer, MySQL/MariaDB already installed

### Frontend Development
```bash
--steps=source,env-config,frontend
```
Requires: Node.js 24 already installed

### TUI Development
```bash
--steps=source,tui
```
Requires: Go 1.23 already installed

### Service Restart
```bash
--steps=systemd,pm2,health-check
```
Requires: All applications already built

## CI/CD Integration

For CI/CD environments, use:
```bash
# Example: Run backend and frontend tests
./install.sh --ci --steps=backend,frontend,tui --verbose

# Example: Run only backend setup
./install.sh --ci --steps=source,env-config,backend --verbose
```

The `--ci` flag:
- Skips root privilege check
- Assumes non-interactive mode
- Suitable for automated testing environments
