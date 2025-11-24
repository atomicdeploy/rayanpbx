# Environment Configuration Management

RayanPBX now includes comprehensive tools for managing environment variables (`.env` file) through multiple interfaces: CLI, TUI, and Web UI.

## Features

### 1. Command-Line Interface (CLI)

The CLI provides powerful commands for managing configuration from the terminal.

#### Available Commands

##### List All Configuration
```bash
rayanpbx-cli config list
```
Displays all configuration keys and values, with sensitive values (passwords, secrets) automatically masked.

##### Get a Specific Value
```bash
rayanpbx-cli config get KEY_NAME
```
Example:
```bash
rayanpbx-cli config get DB_HOST
# Output: 127.0.0.1
```

##### Add a New Configuration
```bash
rayanpbx-cli config add KEY_NAME VALUE
```
Example:
```bash
rayanpbx-cli config add NEW_FEATURE_FLAG true
```
Note: The command will fail if the key already exists. Use `set` to update existing keys.

##### Update an Existing Configuration
```bash
rayanpbx-cli config set KEY_NAME NEW_VALUE
```
Example:
```bash
rayanpbx-cli config set ASTERISK_AMI_PORT 5039
```

##### Remove a Configuration
```bash
rayanpbx-cli config remove KEY_NAME
```
Example:
```bash
rayanpbx-cli config remove OLD_SETTING
```

##### Reload Services
```bash
rayanpbx-cli config reload [SERVICE]
```

Services options:
- `all` (default) - Reloads Asterisk, Laravel, and Frontend
- `asterisk` - Reloads only Asterisk configuration
- `laravel`, `backend`, or `api` - Clears Laravel configuration and cache
- `frontend`, `vue`, or `nuxt` - Restarts frontend service

Examples:
```bash
# Reload all services
rayanpbx-cli config reload

# Reload only Asterisk
rayanpbx-cli config reload asterisk

# Reload only Laravel backend
rayanpbx-cli config reload laravel
```

#### Key Format Requirements

Configuration keys must:
- Start with an uppercase letter or underscore
- Contain only uppercase letters, numbers, and underscores
- Example valid keys: `MY_KEY`, `API_KEY_2`, `_PRIVATE_CONFIG`

#### Automatic Backups

All modification operations (add, set, remove) automatically create timestamped backups of your `.env` file:
- Format: `.env.backup.YYYYMMDD_HHMMSS`
- Location: Same directory as `.env`
- Example: `.env.backup.20231124_153410`

### 2. Terminal User Interface (TUI)

The TUI provides an interactive, menu-driven interface for configuration management.

#### Accessing Configuration Management

1. Launch the TUI:
   ```bash
   rayanpbx-cli tui
   ```
   Or directly:
   ```bash
   cd /path/to/rayanpbx/tui
   ./rayanpbx-tui
   ```

2. Navigate to "🔧 Configuration Management" from the main menu

#### TUI Features

- **List Configurations**: View all configuration keys with their values
- **Search and Navigate**: Use arrow keys to browse configurations
- **Add New**: Add new configuration keys interactively
- **Edit Existing**: Modify existing configuration values
- **Remove**: Delete unwanted configuration keys
- **Reload Services**: Trigger service reloads after changes
- **Sensitive Value Protection**: Passwords and secrets are automatically masked

#### Navigation

- `↑`/`↓` or `j`/`k`: Navigate through items
- `Enter`: Select an item or confirm action
- `Esc` or `q`: Go back to previous screen
- `Ctrl+C`: Exit application

### 3. Web User Interface

The Web UI provides a beautiful, modern interface for managing configurations with advanced features.

#### Accessing the Web UI

1. Log in to RayanPBX web interface
2. Navigate to the "Configuration" page from the menu

#### Web UI Features

##### Dashboard Stats
- **Total Keys**: Count of all configuration variables
- **Sensitive Keys**: Number of sensitive values (passwords, secrets)
- **Normal Keys**: Count of regular configuration values

##### Search and Filter
- **Search Bar**: Quick search across all configuration keys and values
- **Filter Options**:
  - All Keys
  - Sensitive Only
  - Normal Only

##### Configuration Table
- **Key Column**: Shows configuration key names
  - Sensitive keys are marked with a lock icon 🔒
  - Descriptions are shown for keys with comments
- **Value Column**: Displays configuration values
  - Sensitive values are automatically masked
  - Values shown in monospace font for clarity
- **Type Column**: Badge indicating if key is Sensitive or Normal
- **Actions Column**: Edit and Delete buttons for each key

##### Operations

###### Add New Configuration
1. Click "Add New" button
2. Enter key name (must be uppercase with underscores)
3. Enter value
4. Click "Add Configuration"

###### Edit Configuration
1. Click "Edit" button next to the configuration
2. Modify the value (key cannot be changed)
3. Click "Update Configuration"

###### Delete Configuration
1. Click "Delete" button next to the configuration
2. Confirm deletion in the popup modal

###### Reload Services
1. Click "Reload Services" button
2. Select which services to reload:
   - All Services
   - Asterisk Only
   - Laravel/Backend Only
3. Click "Reload" to confirm

##### Real-time Notifications
- Success/error toast notifications for all operations
- Auto-dismiss after 5 seconds
- Color-coded by status (green for success, red for error)

### 4. API Endpoints

For programmatic access, the following REST API endpoints are available:

#### List All Configurations
```http
GET /api/config
Authorization: Bearer {token}
```

Response:
```json
{
  "success": true,
  "data": [
    {
      "key": "DB_HOST",
      "value": "127.0.0.1",
      "sensitive": false,
      "description": "Database server hostname"
    },
    {
      "key": "DB_PASSWORD",
      "value": "********",
      "sensitive": true,
      "description": ""
    }
  ],
  "count": 135
}
```

#### Get Specific Configuration
```http
GET /api/config/{key}
Authorization: Bearer {token}
```

#### Create New Configuration
```http
POST /api/config
Authorization: Bearer {token}
Content-Type: application/json

{
  "key": "NEW_FEATURE",
  "value": "enabled"
}
```

#### Update Configuration
```http
PUT /api/config/{key}
Authorization: Bearer {token}
Content-Type: application/json

{
  "value": "new_value"
}
```

#### Delete Configuration
```http
DELETE /api/config/{key}
Authorization: Bearer {token}
```

#### Reload Services
```http
POST /api/config/reload
Authorization: Bearer {token}
Content-Type: application/json

{
  "service": "all"
}
```

Service options: `all`, `asterisk`, `laravel`, `backend`, `api`

## Security Features

### Sensitive Value Detection
The system automatically detects and masks sensitive configuration values based on key names containing:
- `password`
- `secret`
- `key`
- `token`
- `api_key`
- `private_key`
- `jwt_secret`

### Automatic Backups
Every modification operation creates a timestamped backup of the `.env` file before making changes.

### Key Validation
Configuration keys are validated to ensure they follow proper naming conventions:
- Must start with uppercase letter or underscore
- Can only contain uppercase letters, numbers, and underscores
- Invalid keys are rejected before any changes are made

### Authentication
All API endpoints and web UI operations require authentication via JWT tokens.

## Service Reload Behavior

### Asterisk
- Executes: `asterisk -rx "core reload"`
- Reloads all Asterisk modules and configuration files
- Does not interrupt active calls

### Laravel/Backend
- Executes: `php artisan config:clear`
- Executes: `php artisan cache:clear`
- Clears configuration and application caches
- Forces Laravel to read `.env` on next request

### Frontend
- Restarts the frontend service if running
- Triggers rebuild of environment-dependent code

### All Services
- Performs all of the above operations in sequence
- Recommended after making configuration changes

## Best Practices

1. **Test Changes**: After modifying configuration, use the reload functionality to apply changes without full system restart

2. **Backup Before Major Changes**: While automatic backups are created, consider manual backups before making extensive changes

3. **Use Descriptive Keys**: Follow the existing naming convention and use clear, descriptive key names

4. **Document Custom Keys**: Add comments in the `.env` file for custom configuration keys

5. **Sensitive Values**: Never commit real passwords or secrets to version control

6. **Reload After Changes**: Always reload relevant services after configuration changes for them to take effect

## Troubleshooting

### Changes Not Taking Effect
- Ensure you ran `config reload` after making changes
- Check that the service you're trying to reload is actually running
- Verify that the `.env` file was actually modified (check timestamp)

### Permission Issues
- Ensure the user running the commands has write permissions to the `.env` file
- Check that backup directory is writable

### Service Reload Failures
- Check service logs for specific errors
- Verify that Asterisk/Laravel/Frontend services are properly installed
- Ensure the user has permissions to reload services (may require sudo)

## Examples

### Complete Workflow: Adding a New Feature Flag

```bash
# 1. Add the configuration
rayanpbx-cli config add ENABLE_NEW_FEATURE true

# 2. Verify it was added
rayanpbx-cli config get ENABLE_NEW_FEATURE

# 3. Reload services to apply
rayanpbx-cli config reload laravel

# 4. Test the feature

# 5. If needed, update the value
rayanpbx-cli config set ENABLE_NEW_FEATURE false

# 6. Reload again
rayanpbx-cli config reload
```

### Changing Asterisk AMI Settings

```bash
# 1. Update AMI port
rayanpbx-cli config set ASTERISK_AMI_PORT 5039

# 2. Update AMI host if needed
rayanpbx-cli config set ASTERISK_AMI_HOST 192.168.1.100

# 3. Reload Asterisk configuration
rayanpbx-cli config reload asterisk

# 4. Verify connection works
rayanpbx-cli asterisk status
```

## Related Documentation

- [CLI Commands Reference](COMMAND_LINE_OPTIONS.md)
- [TUI Documentation](TUI_ENHANCEMENTS.md)
- [API Documentation](API_QUICK_REFERENCE.md)
- [Security Guide](SECURITY_SUMMARY.md)
