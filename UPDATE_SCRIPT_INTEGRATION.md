# Update Script Integration Documentation

This document describes exactly where code from the old update script was integrated into `install.sh`.

## Overview

All functionality from the old update script has been successfully integrated into `install.sh`. The install script now serves as both an installer and an updater, eliminating code duplication and following DRY (Don't Repeat Yourself) principles.

**Result:** The old update script has been **replaced** with `scripts/upgrade.sh` - a simple wrapper that calls `install.sh --upgrade`.

## New Upgrade Script

The new `scripts/upgrade.sh` is a minimal wrapper that:
- Displays an informative header with version information
- Validates that install.sh exists
- Checks for root privileges  
- Optionally prompts for confirmation with `-i/--confirm` flag
- Optionally creates backup with `-b/--backup` flag
- Calls `install.sh --upgrade` with all passed arguments
- The `--upgrade` flag tells install.sh to automatically apply updates without prompting

This follows the DRY principle by having all logic in one place (install.sh) while providing a convenient upgrade command.

## Code Integration Locations

### 1. Local Changes Detection (Line ~586-592 in install.sh)

**Source:** Old update script (Step 2: Check for local changes)

**Added to install.sh:** After line 584 (CURRENT_BRANCH detection), before git fetch

```bash
# Check for local changes (from old update script)
print_verbose "Checking for local changes..."
if git diff-index --quiet HEAD -- 2>/dev/null; then
    print_verbose "No local changes detected"
else
    print_warning "Local changes detected in repository"
    print_info "Local changes will be preserved during update"
fi
```

**Purpose:** Detects if there are uncommitted changes in the repository before attempting an update.

---

### 2. Backup Creation Before Updates (Line ~607-625 in install.sh)

**From old update script:** Lines 87-91 (Step 1: Backup current installation)

**Added to install.sh:** Inside the update acceptance block, before git pull

```bash
# Create backup before pulling updates (from old update script)
BACKUP_DIR="/tmp/rayanpbx-backup-$(date +%Y%m%d-%H%M%S)"
print_progress "Creating backup before update..."
print_verbose "Backup directory: $BACKUP_DIR"
mkdir -p "$BACKUP_DIR"
if [ -f "$SCRIPT_DIR/.env" ]; then
    cp "$SCRIPT_DIR/.env" "$BACKUP_DIR/" 2>/dev/null || true
    print_verbose "Backed up .env file"
fi
if [ -d "$SCRIPT_DIR/backend/storage" ]; then
    cp -r "$SCRIPT_DIR/backend/storage" "$BACKUP_DIR/" 2>/dev/null || true
    print_verbose "Backed up backend storage"
fi
print_success "Backup created: $BACKUP_DIR"
```

**Purpose:** Creates a timestamped backup of critical files (.env and backend/storage) before pulling updates, allowing recovery if the update fails.

---

### 3. Git Stash for Local Changes (Line ~627-634 in install.sh)

**From old update script:** Lines 94-101 (Step 2: Check for local changes - stash portion)

**Added to install.sh:** After backup creation, before git pull

```bash
# Stash local changes if any (from old update script)
if ! git diff-index --quiet HEAD -- 2>/dev/null; then
    print_info "Stashing local changes before update..."
    if git stash push -m "Auto-stash before update $(date)" 2>/dev/null; then
        print_verbose "Local changes stashed successfully"
    else
        print_warning "Could not stash changes, attempting update anyway"
    fi
fi
```

**Purpose:** Automatically stashes local changes before pulling updates to prevent merge conflicts. Changes are preserved and can be recovered later.

---

### 4. Backup Restoration on Failed Update (Line ~654-664 in install.sh)

**From old update script:** Lines 127-129 (error handling in Step 4)

**Added to install.sh:** In the git pull error handling block

```bash
else
    print_error "Failed to pull updates"
    print_warning "Restoring from backup if needed..."
    if [ -n "$BACKUP_DIR" ] && [ -d "$BACKUP_DIR" ]; then
        if [ -f "$BACKUP_DIR/.env" ]; then
            cp "$BACKUP_DIR/.env" "$SCRIPT_DIR/" 2>/dev/null || true
            print_verbose "Restored .env from backup"
        fi
    fi
    print_warning "Continuing with current version..."
fi
```

**Purpose:** Automatically restores .env file from backup if git pull fails, ensuring the system remains in a working state.

---

### 5. Cache Clearing After Migrations (Line ~1588-1595 in install.sh)

**From old update script:** Lines 172-176 (Step 9: Clear caches)

**Added to install.sh:** After database migrations complete successfully

```bash
if [ $? -eq 0 ]; then
    print_success "Database migrations completed"
    
    # Clear Laravel caches after migrations (from old update script)
    print_progress "Clearing application caches..."
    print_verbose "Clearing cache, config, and route caches..."
    php artisan cache:clear 2>/dev/null || true
    php artisan config:clear 2>/dev/null || true
    php artisan route:clear 2>/dev/null || true
    print_success "Caches cleared"
```

**Purpose:** Clears Laravel's cache, config, and route caches after migrations to ensure all changes are picked up immediately. Uses `|| true` to continue even if cache clearing fails (non-critical operation).

---

### 6. Enhanced Service Restart Logic (Line ~1828-1846 in install.sh)

**From old update script:** Lines 178-218 (Step 10: Restart services - detection logic)

**Added to install.sh:** In the systemd services section, replacing simple restart

```bash
# Reload systemd
systemctl daemon-reload

# Enable and start services
# Note: Service restart logic integrated from old update script
# During fresh install, services won't be running yet
# During updates, this will restart existing services
print_progress "Starting services..."
systemctl enable rayanpbx-api > /dev/null 2>&1

# Check if service is already running (update scenario)
if systemctl is-active --quiet rayanpbx-api 2>/dev/null; then
    print_verbose "rayanpbx-api is already running, restarting..."
    systemctl restart rayanpbx-api
else
    print_verbose "rayanpbx-api not running, starting fresh..."
    systemctl start rayanpbx-api
fi

# Start PM2 services
cd /opt/rayanpbx
# Stop existing PM2 services if running (update scenario)
su - www-data -s /bin/bash -c "pm2 delete rayanpbx-web rayanpbx-ws 2>/dev/null || true"
# Start PM2 services
su - www-data -s /bin/bash -c "cd /opt/rayanpbx && pm2 start ecosystem.config.js"
su - www-data -s /bin/bash -c "pm2 save"
```

**Purpose:** Intelligently handles both fresh install and update scenarios:
- For fresh installs: Starts services for the first time
- For updates: Restarts existing services to pick up changes
- PM2 services are deleted first to ensure clean restart with new code

---

## Already Existing Functionality (No Duplication)

The following functionality from `old update script` was **already present** in `install.sh`, so no code needed to be added:

### Repository Update (Line 1485-1495 in install.sh)
- **Corresponds to:** old update script Lines 103-129 (Steps 3-4: Fetch and pull)
- Already handles cloning fresh or pulling updates if directory exists

### Backend Dependencies Update (Line ~1584 in install.sh)
- **Corresponds to:** old update script Lines 132-139 (Step 5)
- `composer install --no-dev --optimize-autoloader` runs every time

### Frontend Dependencies and Build (Lines ~1657, 1671 in install.sh)
- **Corresponds to:** old update script Lines 141-150 (Step 6)
- `npm install` and `npm run build` run every time

### TUI Rebuild (Lines ~1695-1696 in install.sh)
- **Corresponds to:** old update script Lines 152-161 (Step 7)
- `go mod download` and `go build` run every time

### Database Migrations (Line ~1587 in install.sh)
- **Corresponds to:** old update script Lines 163-169 (Step 8)
- `php artisan migrate --force` runs every time

### Installation Verification (Lines ~1803-1879 in install.sh)
- **Corresponds to:** old update script Lines 220-239 (Step 11)
- Comprehensive health checks with `test_service_health()` already implemented
- More thorough than update script's basic curl checks

---

## Summary

### Code Additions by Line Number in install.sh:

1. **Line ~567-573**: Local changes detection
2. **Line ~607-625**: Backup creation before updates  
3. **Line ~627-634**: Git stash for local changes
4. **Line ~654-664**: Backup restoration on failed update
5. **Line ~1588-1595**: Cache clearing after migrations
6. **Line ~1828-1846**: Enhanced service restart logic

### Result:

- **Total new lines added:** ~60 lines
- **Duplicate code eliminated:** All update functionality now centralized in install.sh
- **install.sh now serves dual purpose:** Both installer and updater
- **DRY principle maintained:** No code duplication between scripts
- **Backward compatibility:** Works for both fresh installs and updates

### Benefits:

1. **Single source of truth:** Only one script to maintain
2. **Consistent behavior:** Updates use the same reliable installation logic
3. **Better error handling:** Integrated backup and restore functionality
4. **No duplication:** All shared logic (dependency installation, building, migrations) only exists once
5. **Cleaner codebase:** Fewer scripts to maintain and test

---

## Testing

All existing tests pass:
```bash
$ bash scripts/test-install-fixes.sh
✅ All 8 tests passed
```

The integration maintains backward compatibility and adds no breaking changes.
