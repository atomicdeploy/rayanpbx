# RayanPBX Artisan Commands

RayanPBX provides a comprehensive set of Laravel Artisan commands for managing your PBX system from the command line.

## Available Commands

### Status & Health

#### `rayanpbx:status`
Display status of all RayanPBX services and components.

```bash
php artisan rayanpbx:status

# Output as JSON
php artisan rayanpbx:status --json
```

Shows:
- Asterisk PBX status, version, active calls, and channels
- RayanPBX API service status
- MySQL database status
- Redis cache status
- Service uptime and resource usage

#### `rayanpbx:health`
Run comprehensive health checks on the RayanPBX system.

```bash
php artisan rayanpbx:health

# Output as JSON
php artisan rayanpbx:health --json

# Detailed output
php artisan rayanpbx:health --detailed
```

Checks:
- All system services
- Database connectivity
- Asterisk functionality
- Disk space usage (warns if >90%)
- Memory usage (warns if >90%)
- Network ports availability

### Service Management

#### `rayanpbx:service`
Manage RayanPBX system services (start, stop, restart, reload, status).

```bash
# Start a service
php artisan rayanpbx:service start asterisk
php artisan rayanpbx:service start rayanpbx-api
php artisan rayanpbx:service start mysql
php artisan rayanpbx:service start redis

# Stop a service
php artisan rayanpbx:service stop asterisk

# Restart a service
php artisan rayanpbx:service restart asterisk

# Reload configuration (Asterisk only)
php artisan rayanpbx:service reload asterisk

# Check service status
php artisan rayanpbx:service status asterisk

# Manage all services at once
php artisan rayanpbx:service start all
php artisan rayanpbx:service stop all
php artisan rayanpbx:service restart all
php artisan rayanpbx:service status all
```

### Asterisk Management

#### `rayanpbx:asterisk`
Execute Asterisk CLI commands and manage Asterisk.

```bash
# Interactive menu
php artisan rayanpbx:asterisk

# Execute specific command
php artisan rayanpbx:asterisk "core show version"
php artisan rayanpbx:asterisk "core show calls"
php artisan rayanpbx:asterisk "pjsip show endpoints"
php artisan rayanpbx:asterisk "core reload"
```

Common commands:
- `core show version` - Show Asterisk version
- `core show calls` - Show active calls
- `core show channels` - Show active channels
- `pjsip show endpoints` - Show PJSIP endpoints
- `core reload` - Reload Asterisk configuration
- `core show uptime` - Show Asterisk uptime

### Extension Management

#### `rayanpbx:extension`
Manage SIP extensions.

```bash
# List all extensions
php artisan rayanpbx:extension list

# Create a new extension
php artisan rayanpbx:extension create 1001 --name="John Doe" --email="john@example.com" --secret="strongpassword"

# Create extension interactively
php artisan rayanpbx:extension create

# Show extension details
php artisan rayanpbx:extension show 1001

# Enable an extension
php artisan rayanpbx:extension enable 1001

# Disable an extension
php artisan rayanpbx:extension disable 1001

# Delete an extension
php artisan rayanpbx:extension delete 1001

# Delete without confirmation
php artisan rayanpbx:extension delete 1001 --all
```

After creating, enabling, or disabling extensions, remember to reload the configuration:
```bash
php artisan rayanpbx:config reload
```

### Trunk Management

#### `rayanpbx:trunk`
Manage SIP trunks.

```bash
# List all trunks
php artisan rayanpbx:trunk list

# Create a new trunk
php artisan rayanpbx:trunk create --name="MyTrunk" --type="pjsip" --host="sip.provider.com" --username="myuser" --secret="mypassword"

# Create trunk interactively
php artisan rayanpbx:trunk create

# Show trunk details
php artisan rayanpbx:trunk show MyTrunk

# Enable a trunk
php artisan rayanpbx:trunk enable MyTrunk

# Disable a trunk
php artisan rayanpbx:trunk disable MyTrunk

# Delete a trunk
php artisan rayanpbx:trunk delete MyTrunk

# Delete without confirmation
php artisan rayanpbx:trunk delete MyTrunk --all
```

After creating, enabling, or disabling trunks, remember to reload the configuration:
```bash
php artisan rayanpbx:config reload
```

### Configuration Management

#### `rayanpbx:config`
Validate and reload RayanPBX configurations.

```bash
# Validate configuration
php artisan rayanpbx:config validate

# Reload/apply configuration
php artisan rayanpbx:config reload

# Apply configuration without confirmation
php artisan rayanpbx:config apply --force
```

The `reload` command will:
1. Generate Asterisk configuration from database
2. Reload Asterisk configuration
3. Verify the reload was successful

#### `rayanpbx:generate-config`
Generate Asterisk configuration files from database.

```bash
# Generate configuration files
php artisan rayanpbx:generate-config

# Dry run (preview without writing)
php artisan rayanpbx:generate-config --dry-run
```

This command generates:
- `/etc/asterisk/pjsip_custom.conf` - PJSIP endpoints for extensions and trunks
- `/etc/asterisk/extensions_custom.conf` - Dialplan configuration

### Backup & Restore

#### `rayanpbx:backup`
Backup RayanPBX configurations and database.

```bash
# Create backup
php artisan rayanpbx:backup

# Create compressed backup
php artisan rayanpbx:backup --compress

# Specify backup location
php artisan rayanpbx:backup --path=/custom/backup/path

# Create compressed backup to custom location
php artisan rayanpbx:backup --path=/custom/backup/path --compress
```

The backup includes:
- Complete database dump
- `.env` configuration file
- Asterisk configuration files (pjsip.conf, extensions.conf, manager.conf, etc.)
- Backup metadata (timestamp, version, counts)

Default backup location: `/opt/rayanpbx/backups/`

#### `rayanpbx:restore`
Restore RayanPBX from backup.

```bash
# Restore from backup
php artisan rayanpbx:restore /opt/rayanpbx/backups/backup_2024-11-23_16-30-00

# Restore from compressed backup
php artisan rayanpbx:restore /opt/rayanpbx/backups/backup_2024-11-23_16-30-00.tar.gz

# Restore without confirmation
php artisan rayanpbx:restore /path/to/backup --force
```

After restore, you should restart services:
```bash
php artisan rayanpbx:service restart all
```

## Common Workflows

### Adding a New Extension

```bash
# 1. Create the extension
php artisan rayanpbx:extension create 1001 \
  --name="John Doe" \
  --email="john@example.com" \
  --secret="strongpassword"

# 2. Generate and reload configuration
php artisan rayanpbx:config reload

# 3. Verify extension is registered
php artisan rayanpbx:asterisk "pjsip show endpoints"
```

### Adding a New Trunk

```bash
# 1. Create the trunk
php artisan rayanpbx:trunk create \
  --name="VoipProvider" \
  --type="pjsip" \
  --host="sip.provider.com" \
  --username="myaccount" \
  --secret="mypassword"

# 2. Generate and reload configuration
php artisan rayanpbx:config reload

# 3. Verify trunk registration
php artisan rayanpbx:asterisk "pjsip show endpoints"
```

### System Maintenance

```bash
# 1. Check system health
php artisan rayanpbx:health

# 2. Check service status
php artisan rayanpbx:status

# 3. View active calls
php artisan rayanpbx:asterisk "core show calls"

# 4. Restart services if needed
php artisan rayanpbx:service restart asterisk
```

### Backup and Restore

```bash
# Create a backup before major changes
php artisan rayanpbx:backup --compress

# After changes, if something goes wrong, restore
php artisan rayanpbx:restore /opt/rayanpbx/backups/backup_2024-11-23_16-30-00.tar.gz

# Restart services after restore
php artisan rayanpbx:service restart all
```

### Troubleshooting

```bash
# 1. Run health check
php artisan rayanpbx:health

# 2. Check service status
php artisan rayanpbx:status

# 3. View Asterisk logs
php artisan rayanpbx:asterisk "core show channels"

# 4. Validate configuration
php artisan rayanpbx:config validate

# 5. Restart problematic service
php artisan rayanpbx:service restart asterisk
```

## Database Commands

### `db:check-collation`
Check and fix database collation.

```bash
# Check database collation
php artisan db:check-collation

# Fix database collation if needed
php artisan db:check-collation --fix
```

## Integration with Existing Scripts

These Artisan commands complement the existing shell scripts in `/opt/rayanpbx/scripts/`:

- `scripts/health-check.sh` - Can be used alongside `rayanpbx:health`
- `scripts/rayanpbx-cli.sh` - CLI wrapper that can call these commands
- `scripts/config-tui.sh` - Configuration TUI interface

## Notes

- Most commands require the application to be properly configured with database access.
- Commands that modify Asterisk configuration require appropriate file system permissions.
- Service management commands require root/sudo privileges.
- Always backup before making significant changes.
- Use `--help` flag with any command to see all available options.

## Examples

### Daily Operations

```bash
# Morning check
php artisan rayanpbx:health
php artisan rayanpbx:status

# Check active calls throughout the day
php artisan rayanpbx:asterisk "core show calls"

# Evening backup
php artisan rayanpbx:backup --compress
```

### Adding Multiple Extensions

```bash
# Create extensions with a script
for ext in {1001..1010}; do
  php artisan rayanpbx:extension create $ext \
    --name="User $ext" \
    --email="user$ext@example.com" \
    --secret="$(openssl rand -base64 12)"
done

# Reload configuration once
php artisan rayanpbx:config reload
```

### System Maintenance Window

```bash
# 1. Backup current state
php artisan rayanpbx:backup --compress

# 2. Perform maintenance tasks
php artisan rayanpbx:extension list
php artisan rayanpbx:trunk list

# 3. Apply configuration changes
php artisan rayanpbx:config reload

# 4. Verify everything is working
php artisan rayanpbx:health

# 5. Check services
php artisan rayanpbx:status
```
