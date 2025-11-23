# RayanPBX CLI Commands Reference

## Overview

RayanPBX CLI (`rayanpbx-cli`) is a comprehensive command-line interface for managing your RayanPBX installation. It provides feature parity with FreePBX's `fwconsole`, IncrediblePBX utilities, and IssabelPBX console commands, with modern enhancements.

## Quick Start

```bash
# Show all available commands
rayanpbx-cli list

# Show detailed help
rayanpbx-cli help

# Run a health check
rayanpbx-cli diag health-check
```

## Command Categories

### Extension Management

Manage SIP extensions (users).

```bash
# List all extensions
rayanpbx-cli extension list

# Create a new extension
rayanpbx-cli extension create 1001 "John Doe" secretpass123

# Check extension status
rayanpbx-cli extension status 1001
```

### Trunk Management

Manage SIP trunks for outbound calling.

```bash
# List all trunks
rayanpbx-cli trunk list

# Test trunk connectivity
rayanpbx-cli trunk test my-trunk
```

### Asterisk Control

Control and manage the Asterisk PBX engine.

```bash
# Check Asterisk status
rayanpbx-cli asterisk status

# Start Asterisk service
rayanpbx-cli asterisk start

# Stop Asterisk service
rayanpbx-cli asterisk stop

# Restart Asterisk service
rayanpbx-cli asterisk restart

# Reload Asterisk configuration
rayanpbx-cli asterisk reload

# Open interactive Asterisk console
rayanpbx-cli asterisk console

# Execute a single Asterisk command
rayanpbx-cli asterisk command "core show version"
rayanpbx-cli asterisk command "pjsip show endpoints"
```

### Diagnostics

System diagnostics and troubleshooting tools.

```bash
# Test extension registration
rayanpbx-cli diag test-extension 1001

# Run comprehensive system health check
rayanpbx-cli diag health-check

# Show active channels
rayanpbx-cli diag channels

# Show active calls
rayanpbx-cli diag calls

# Show all system versions
rayanpbx-cli diag version
```

### System Management

System-level operations and information.

```bash
# Update RayanPBX from git
rayanpbx-cli system update

# Show system information (CPU, RAM, disk, etc.)
rayanpbx-cli system info

# Show status of all services
rayanpbx-cli system services

# Reload all RayanPBX services
rayanpbx-cli system reload

# Fix file permissions
rayanpbx-cli system chown
```

### Database Management

Database operations and maintenance.

```bash
# Open MySQL console
rayanpbx-cli database mysql
# or short form
rayanpbx-cli db mysql

# Show database information
rayanpbx-cli database info

# Backup database
rayanpbx-cli database backup

# Restore database from backup
rayanpbx-cli database restore /path/to/backup.sql.gz
```

### Backup & Restore

Full system backup and restore functionality.

```bash
# Create full system backup (database + config)
rayanpbx-cli backup create

# List available backups
rayanpbx-cli backup list
```

### PJSIP/Endpoint Management

Advanced PJSIP endpoint management (similar to FreePBX's endpoint commands).

```bash
# List all PJSIP endpoints
rayanpbx-cli endpoint list
# or
rayanpbx-cli pjsip list

# Show detailed endpoint information
rayanpbx-cli endpoint show 1001

# Show all registered contacts
rayanpbx-cli endpoint contacts

# Show PJSIP registrations
rayanpbx-cli endpoint registrations

# Qualify an endpoint (check connectivity)
rayanpbx-cli endpoint qualify 1001
```

### Module Management

Asterisk module management (similar to FreePBX's moduleadmin).

```bash
# List all loaded modules
rayanpbx-cli module list

# Reload all modules
rayanpbx-cli module reload

# Reload specific module
rayanpbx-cli module reload res_pjsip.so

# Load a module
rayanpbx-cli module load app_voicemail.so

# Unload a module
rayanpbx-cli module unload app_echo.so
```

### Dialplan/Context Management

Inspect dialplan contexts and routing.

```bash
# Show all dialplan contexts
rayanpbx-cli context show
# or
rayanpbx-cli dialplan show

# Show specific context
rayanpbx-cli context show from-internal
```

### Log Management

View and tail various system logs.

```bash
# View Asterisk full log (tail -f)
rayanpbx-cli log view full

# View Asterisk messages log
rayanpbx-cli log view messages

# View RayanPBX API log
rayanpbx-cli log view api

# View Asterisk service log
rayanpbx-cli log view asterisk
```

## Feature Parity

### FreePBX fwconsole Features Implemented

✅ **Service Control**
- `start`, `stop`, `restart`, `reload` - Asterisk service control
- `chown` - Fix file permissions

✅ **Module Administration**
- `module list`, `reload`, `load`, `unload` - Module management

✅ **Backup & Restore**
- `backup create`, `list` - Backup management
- `database backup`, `restore` - Database backup/restore

✅ **Database Utilities**
- `database mysql` - MySQL console access
- `database info` - Database information

✅ **Diagnostics**
- `diag health-check` - System health check
- `diag channels`, `calls` - Active channels and calls
- `diag version` - Version information

✅ **System Management**
- `system info` - System information
- `system services` - Service status
- `system update` - Update system

✅ **PJSIP/Endpoint Management**
- `endpoint list`, `show`, `contacts`, `registrations`, `qualify`

✅ **Context/Dialplan**
- `context show` - View dialplan contexts

✅ **Logs**
- `log view` - View various logs

### IncrediblePBX Features Implemented

✅ **System Information**
- System info with CPU, RAM, disk stats

✅ **Service Management**
- Comprehensive service status checking

✅ **Database Management**
- MySQL console access
- Database backup and restore

### IssabelPBX Features Implemented

✅ **PJSIP Management**
- `pjsip show endpoints`
- `pjsip show contacts`
- `pjsip show registrations`
- `pjsip qualify`

✅ **Extension Management**
- List, create, and manage extensions
- Test extension registration

✅ **Trunk Management**
- List trunks
- Test trunk connectivity

✅ **Diagnostics**
- Health checks
- Channel monitoring
- Call monitoring

## Command Shortcuts

Some commands have short aliases:

- `db` → `database`
- `pjsip` → `endpoint`
- `dialplan` → `context`

## Exit Codes

The CLI uses standard exit codes:

- `0` - Success
- `1` - General error
- `2` - Command not found / invalid usage
- `3` - Service error

## Examples

### Daily Operations

```bash
# Morning health check
rayanpbx-cli diag health-check

# Check if extension is registered
rayanpbx-cli endpoint show 1001

# View active calls
rayanpbx-cli diag calls

# Check system resources
rayanpbx-cli system info
```

### Troubleshooting

```bash
# Test extension registration
rayanpbx-cli diag test-extension 1001

# View full Asterisk log
rayanpbx-cli log view full

# Check module status
rayanpbx-cli module list

# Execute custom Asterisk command
rayanpbx-cli asterisk command "core show uptime"
```

### Maintenance

```bash
# Create backup before changes
rayanpbx-cli backup create

# Reload after configuration changes
rayanpbx-cli asterisk reload

# Fix permissions if needed
rayanpbx-cli system chown

# Update system
rayanpbx-cli system update
```

## Integration with Scripts

The CLI is designed to be script-friendly:

```bash
#!/bin/bash

# Check if Asterisk is running
if rayanpbx-cli asterisk status; then
    echo "Asterisk is running"
else
    echo "Asterisk is down, attempting restart..."
    rayanpbx-cli asterisk start
fi

# Create daily backup
rayanpbx-cli backup create

# Check health and log results
rayanpbx-cli diag health-check > /var/log/rayanpbx-health.log
```

## Configuration

The CLI reads configuration from:
- `$RAYANPBX_ROOT/.env` (default: `/opt/rayanpbx/.env`)
- `$RAYANPBX_ROOT` environment variable can override the installation path

## Requirements

- Bash 4.0+
- Root or sudo access (for most commands)
- RayanPBX installation at `/opt/rayanpbx` (or custom path)
- MySQL/MariaDB client (for database commands)
- curl (for API calls)
- jq (optional, for better JSON formatting)

## Future Enhancements

Planned features for future releases:

- **Firewall Management** - Port knocker and firewall rules
- **Certificate Management** - SSL/TLS certificate operations
- **Sound Management** - Upload and manage sound files
- **User Management** - Web UI user administration
- **Notifications** - System notification management
- **Queue Management** - Call queue operations
- **IVR Management** - Interactive voice response configuration

## Support

For issues, questions, or contributions:

- GitHub Issues: https://github.com/atomicdeploy/rayanpbx/issues
- Documentation: https://github.com/atomicdeploy/rayanpbx
- Community: GitHub Discussions

## See Also

- `rayanpbx-tui` - Terminal UI interface
- `health-check.sh` - Detailed health checking script
- `config-tui.sh` - Configuration management TUI
- `update-rayanpbx.sh` - System update script
