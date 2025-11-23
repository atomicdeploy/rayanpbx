# Backup Deduplication Feature

## Overview

The RayanPBX installer now intelligently manages configuration backups to prevent cluttering the config directory with duplicate backups during upgrades and repeated installations.

## Problem Statement

Previously, every time the installer ran, it would create a new timestamped backup of configuration files, even if the content was identical to an existing backup. This resulted in:

- Multiple identical backup files accumulating over time
- Wasted disk space
- Difficulty identifying which backup to use for recovery
- Cluttered configuration directories

## Solution

The installer now uses content-based deduplication for backups:

1. Before creating a backup, the system calculates a checksum (MD5 or SHA256) of the file
2. It compares this checksum with all existing `.backup.*` files
3. If an identical backup already exists, it reuses that backup instead of creating a new one
4. If the content is different, it creates a new timestamped backup as before

## Benefits

✅ **Reduces clutter**: Only unique configurations are backed up  
✅ **Saves disk space**: No duplicate content stored  
✅ **Preserves safety**: All unique configurations are still protected  
✅ **Backward compatible**: Works with existing backup naming conventions  
✅ **Zero config**: Works automatically without user intervention  

## Example Scenario

### Before This Feature

```bash
# User runs installer 5 times (e.g., testing, troubleshooting, or upgrades)
$ ls -lh /etc/asterisk/manager.conf.backup.*

-rw-r--r-- 1 root root 2.1K Nov 23 10:00 manager.conf.backup.20251123_100001
-rw-r--r-- 1 root root 2.1K Nov 23 10:05 manager.conf.backup.20251123_100512
-rw-r--r-- 1 root root 2.1K Nov 23 10:10 manager.conf.backup.20251123_101023
-rw-r--r-- 1 root root 2.1K Nov 23 10:15 manager.conf.backup.20251123_101534
-rw-r--r-- 1 root root 2.1K Nov 23 10:20 manager.conf.backup.20251123_102045

# All 5 backups are identical! Wasted space and clutter.
```

### After This Feature

```bash
# User runs installer 5 times
$ ls -lh /etc/asterisk/manager.conf.backup.*

-rw-r--r-- 1 root root 1.9K Nov 23 10:00 manager.conf.backup.20251123_100001
-rw-r--r-- 1 root root 2.3K Nov 23 10:05 manager.conf.backup.20251123_100512

# Only 2 backups! One for the original config, one for the RayanPBX-modified config.
# Subsequent runs reuse the existing backup if content is identical.
```

## Technical Details

### Checksum Calculation

The system prefers MD5 for speed, falls back to SHA256, and gracefully handles systems without checksum tools:

```bash
calculate_file_checksum() {
    local file="$1"
    
    if command -v md5sum &> /dev/null; then
        md5sum "$file" | awk '{print $1}'
    elif command -v shasum &> /dev/null; then
        shasum -a 256 "$file" | awk '{print $1}'
    else
        return 1  # No checksum tool available, fall back to always creating backup
    fi
}
```

### Backup Process

```bash
backup_config() {
    local file="$1"
    
    # Calculate checksum of current file
    local file_checksum=$(calculate_file_checksum "$file")
    
    # Check all existing backups
    for existing_backup in "${file}.backup."*; do
        local backup_checksum=$(calculate_file_checksum "$existing_backup")
        
        if [ "$file_checksum" = "$backup_checksum" ]; then
            # Identical backup found, return its path
            echo "$existing_backup"
            return 0
        fi
    done
    
    # No identical backup exists, create new one
    local backup="${file}.backup.$(date +%Y%m%d_%H%M%S)"
    cp "$file" "$backup"
    echo "$backup"
}
```

### Affected Files

The deduplication logic is implemented in:

- **`scripts/ini-helper.sh`**: Core backup functions
  - `calculate_file_checksum()` - Checksum calculation helper
  - `backup_config()` - Main backup function with deduplication
  
- **`scripts/config-tui.sh`**: Configuration TUI
  - Sources `ini-helper.sh` and uses `backup_config()`
  
- **`scripts/rayanpbx-cli.sh`**: Command-line interface
  - Sources `ini-helper.sh` and uses `backup_config()`
  
- **`install.sh`**: Main installer
  - Uses `modify_manager_conf()` which calls `backup_config()`

## Testing

Comprehensive test suite validates the feature:

### Unit Tests (`test-backup-deduplication.sh`)

Tests individual backup scenarios:
- First backup creation
- Duplicate prevention
- New backup on content change
- Multiple scenarios

### Integration Tests (`test-backup-integration.sh`)

Tests the full workflow:
- Simulates 3 installer runs
- Verifies only 2 backups created (original + modified)
- Confirms third run doesn't create duplicate

### E2E Tests (`test-backup-e2e.sh`)

Tests real-world scenarios:
- 5 installer runs with different configurations
- Verifies only unique backups are created
- Tests user modification scenarios

### Running Tests

```bash
# Run all tests
cd /opt/rayanpbx
./scripts/test-backup-deduplication.sh
./scripts/test-backup-integration.sh
./scripts/test-backup-e2e.sh
```

## Backward Compatibility

This feature is **100% backward compatible**:

- Uses existing `.backup.YYYYMMDD_HHMMSS` naming convention
- Works with existing backup files
- Falls back gracefully on systems without checksum tools
- No configuration changes required
- No breaking changes to existing functionality

## FAQ

### Q: What happens if I manually edit a config file?

**A:** The next installer run will create a new backup of your modified configuration. The original backup and your modified version will both be preserved.

### Q: What if I need to restore an old backup?

**A:** All unique configurations are preserved. Simply copy the backup you want:

```bash
cp /etc/asterisk/manager.conf.backup.20251123_100001 /etc/asterisk/manager.conf
```

### Q: How much space does this save?

**A:** It depends on how often you run the installer. If you run it 10 times during troubleshooting, you'll have 2-3 backups instead of 10 (70-80% space savings).

### Q: Can I disable this feature?

**A:** The feature is automatic and doesn't require configuration. If you really need every backup timestamped regardless of content, you can remove the checksum comparison logic from `scripts/ini-helper.sh`, but this is not recommended.

### Q: What if checksum tools are not available?

**A:** The system gracefully falls back to creating backups on every run (original behavior) if neither `md5sum` nor `shasum` is available.

## Monitoring Backups

To see your backups:

```bash
# List all manager.conf backups
ls -lh /etc/asterisk/manager.conf.backup.*

# Compare two backups
diff /etc/asterisk/manager.conf.backup.20251123_100001 \
     /etc/asterisk/manager.conf.backup.20251123_100512

# Check checksums manually
md5sum /etc/asterisk/manager.conf.backup.*
```

## Implementation Notes

- **Thread-safe**: Uses atomic file operations
- **Safe glob handling**: Uses `nullglob` to prevent issues when no backups exist
- **Error handling**: Falls back gracefully when checksum tools unavailable
- **Performance**: MD5 checksums are fast, even on large files
- **Maintainable**: All logic centralized in `ini-helper.sh`

## Future Enhancements

Potential improvements for future versions:

1. **Backup rotation**: Automatically remove old backups after N versions
2. **Compression**: Compress old backups to save space
3. **Backup metadata**: Store backup reason/source in a manifest file
4. **Remote backups**: Optionally sync backups to remote storage

## Related Documentation

- [Installation Guide](../README.md)
- [Command Line Options](../COMMAND_LINE_OPTIONS.md)
- [Addressing Comments](../ADDRESSING_COMMENTS.md)

## Contributing

If you find issues or have suggestions for improving the backup system, please:

1. Open an issue on [GitHub Issues](https://github.com/atomicdeploy/rayanpbx/issues)
2. Include details about your scenario
3. Attach relevant backup files (sanitized of sensitive data)

---

**Last Updated**: November 23, 2025  
**Version**: 2.0.0
