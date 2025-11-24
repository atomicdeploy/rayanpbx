# Multi-Path .env Loading

## Overview

RayanPBX now supports loading `.env` configuration files from multiple paths in a priority order. This allows for flexible configuration management across different deployment scenarios.

## Loading Priority

Configuration files are loaded in the following order, with **later paths overriding earlier ones**:

1. `/opt/rayanpbx/.env` - System-wide installation (lowest priority)
2. `/usr/local/rayanpbx/.env` - Alternative system installation
3. `/etc/rayanpbx/.env` - System configuration directory
4. `<project root>/.env` - Project-specific configuration (found by VERSION file)
5. `<current working directory>/.env` - Local overrides (highest priority)

## Benefits

- **System-Wide Defaults**: Install RayanPBX in `/opt/rayanpbx` with a base configuration
- **Per-Environment Config**: Override settings in `/etc/rayanpbx` for different environments
- **Development Overrides**: Developers can use local `.env` files without modifying system configs
- **Clean Separation**: Production, staging, and development can coexist on the same system

## Implementation

### TUI (Go)

The Terminal UI loads configuration via `LoadConfig()` in `tui/config.go`:

```go
func LoadConfig() (*Config, error) {
    // Loads from all 5 paths in order
    // Uses godotenv.Load() for first file, godotenv.Overload() for subsequent files
}
```

### CLI (Bash)

The CLI script loads configuration via `load_env_files()` in `scripts/rayanpbx-cli.sh`:

```bash
load_env_files() {
    # Sources .env files from all 5 paths
    # Later files override earlier variables
}
```

### Backend (Laravel/PHP)

The Laravel backend uses `EnvLoaderServiceProvider` in `backend/app/Providers/EnvLoaderServiceProvider.php`:

```php
class EnvLoaderServiceProvider extends ServiceProvider {
    // Loads .env files using Dotenv::createMutable()->load()
    // createMutable creates a mutable repository that allows overwriting
    // existing environment variables
}
```

## Example Use Cases

### Use Case 1: Production Installation

```bash
# Base installation
/opt/rayanpbx/.env
    DB_HOST=localhost
    DB_PORT=3306
    API_BASE_URL=http://localhost:8000

# Production overrides
/etc/rayanpbx/.env
    DB_HOST=prod-db.example.com
    API_BASE_URL=https://api.example.com
```

Result: Production uses `prod-db.example.com` while keeping other settings from `/opt/rayanpbx/.env`

### Use Case 2: Development Override

```bash
# System installation
/opt/rayanpbx/.env
    DB_HOST=prod-db.example.com
    DB_PORT=3306

# Developer's local override
~/projects/rayanpbx/.env
    DB_HOST=localhost
    DB_PORT=3307
```

Result: Developer uses local database without modifying system configuration

### Use Case 3: Multi-Environment Server

```bash
# Shared base config
/opt/rayanpbx/.env
    DB_PORT=3306
    LOG_LEVEL=info

# Staging environment
/etc/rayanpbx/.env
    ENVIRONMENT=staging
    DB_HOST=staging-db.example.com
```

Result: Staging inherits defaults but uses its own database

## Testing

### Unit Tests

Run TUI tests:
```bash
cd tui
go test -v
```

### Integration Tests

Test environment loading behavior:
```bash
./scripts/test-env-loading.sh
```

Test CLI script:
```bash
./scripts/test-cli-env-loading.sh
```

## Troubleshooting

### Checking Loaded Values

**TUI/CLI**: Enable verbose mode to see which files are loaded:
```bash
rayanpbx-cli --verbose <command>
```

**Find Current Values**: Check environment variables after loading:
```bash
# In bash
echo $DB_HOST

# In TUI, check the Config struct values
```

### Common Issues

1. **Config not updating**: Ensure the file path is correct and readable
2. **Wrong priority**: Remember that current directory has highest priority
3. **Syntax errors**: Validate `.env` file syntax (KEY=value format)

## Migration Guide

If you have an existing installation:

1. **No changes required** - existing `.env` files will continue to work
2. **Optional**: Move system-wide settings to `/opt/rayanpbx/.env` or `/etc/rayanpbx/.env`
3. **Optional**: Use local `.env` files for development overrides

## Best Practices

1. **System Config**: Place shared/default settings in `/opt/rayanpbx/.env`
2. **Environment Config**: Place environment-specific settings in `/etc/rayanpbx/.env`
3. **Secrets**: Store sensitive values in `/etc/rayanpbx/.env` with restricted permissions
4. **Development**: Use project/local `.env` files, add to `.gitignore`
5. **Documentation**: Comment your `.env` files to explain non-obvious settings

## Security Considerations

- `.env` files may contain sensitive data (passwords, API keys)
- Set appropriate file permissions: `chmod 600 /etc/rayanpbx/.env`
- Never commit `.env` files to version control
- Use different secrets for each environment

## See Also

- `.env.example` - Example configuration file
- `tui/config.go` - TUI configuration loading
- `scripts/rayanpbx-cli.sh` - CLI configuration loading
- `backend/app/Providers/EnvLoaderServiceProvider.php` - Laravel configuration loading
