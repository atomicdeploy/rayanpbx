#!/bin/bash

# RayanPBX Backup Manager
# Centralized backup solution for Asterisk configuration files
# Stores backups in /etc/asterisk/backups/ subdirectory
# Provides backup, restore, list, and cleanup functionality

# Default backup directory
BACKUP_DIR="${BACKUP_DIR:-/etc/asterisk/backups}"

# List of configuration files that RayanPBX manages
MANAGED_CONF_FILES=(
    "/etc/asterisk/manager.conf"
    "/etc/asterisk/pjsip.conf"
    "/etc/asterisk/extensions.conf"
    "/etc/asterisk/cdr.conf"
    "/etc/asterisk/cel.conf"
)

# Colors for output (if terminal supports it)
if [ -t 1 ]; then
    RED='\033[0;31m'
    GREEN='\033[0;32m'
    YELLOW='\033[1;33m'
    CYAN='\033[0;36m'
    RESET='\033[0m'
else
    RED=''
    GREEN=''
    YELLOW=''
    CYAN=''
    RESET=''
fi

# Helper function to print messages
print_info() {
    echo -e "${CYAN}[INFO]${RESET} $1"
}

print_success() {
    echo -e "${GREEN}[OK]${RESET} $1"
}

print_warning() {
    echo -e "${YELLOW}[WARN]${RESET} $1"
}

print_error() {
    echo -e "${RED}[ERROR]${RESET} $1"
}

# Calculate file checksum for deduplication
# Uses md5sum preferentially for consistency; falls back to shasum if unavailable
# Note: All checksum comparisons in a single system run use the same algorithm
calculate_file_checksum() {
    local file="$1"
    
    if [ ! -f "$file" ]; then
        return 1
    fi
    
    # Use md5sum preferentially as it's most common on Linux systems
    if command -v md5sum &> /dev/null; then
        md5sum "$file" | awk '{print $1}'
    elif command -v md5 &> /dev/null; then
        # macOS uses 'md5' command
        md5 -q "$file"
    elif command -v shasum &> /dev/null; then
        shasum -a 256 "$file" | awk '{print $1}'
    else
        # No checksum tool available
        return 1
    fi
}

# Ensure backup directory exists
ensure_backup_dir() {
    if [ ! -d "$BACKUP_DIR" ]; then
        mkdir -p "$BACKUP_DIR"
        chmod 755 "$BACKUP_DIR"
    fi
}

# Get backup filename for a config file
# Format: <basename>.<timestamp>.backup
# Uses nanoseconds to avoid timestamp collisions within the same second
get_backup_filename() {
    local file="$1"
    local timestamp="${2:-$(date +%Y%m%d_%H%M%S_%N)}"
    local basename
    basename=$(basename "$file")
    echo "${BACKUP_DIR}/${basename}.${timestamp}.backup"
}

# Backup a single configuration file
# Returns the path to the backup file, or existing backup if content is identical
backup_config_file() {
    local file="$1"
    local force="${2:-false}"
    
    if [ ! -f "$file" ]; then
        print_error "File not found: $file"
        return 1
    fi
    
    ensure_backup_dir
    
    local basename
    basename=$(basename "$file")
    
    # Calculate checksum of the file to backup
    local file_checksum
    if ! file_checksum=$(calculate_file_checksum "$file"); then
        # Fallback: if no checksum tool available, always create backup
        local backup_path
        backup_path=$(get_backup_filename "$file")
        cp "$file" "$backup_path"
        echo "$backup_path"
        return 0
    fi
    
    # Unless forced, check for existing identical backups
    if [ "$force" != "true" ]; then
        local backup_pattern="${BACKUP_DIR}/${basename}.*.backup"
        
        # Use nullglob-safe approach
        shopt -s nullglob
        local backups=($backup_pattern)
        shopt -u nullglob
        
        for existing_backup in "${backups[@]}"; do
            if [ -f "$existing_backup" ]; then
                local backup_checksum
                if backup_checksum=$(calculate_file_checksum "$existing_backup"); then
                    if [ "$file_checksum" = "$backup_checksum" ]; then
                        # Identical backup already exists
                        echo "$existing_backup"
                        return 0
                    fi
                fi
            fi
        done
    fi
    
    # No identical backup exists or force is set, create a new one
    local backup_path
    backup_path=$(get_backup_filename "$file")
    cp "$file" "$backup_path"
    chmod 644 "$backup_path"
    echo "$backup_path"
}

# Backup all managed configuration files
backup_all() {
    local force="${1:-false}"
    local backed_up=0
    local skipped=0
    local failed=0
    
    ensure_backup_dir
    
    print_info "Backing up RayanPBX managed configuration files..."
    
    for file in "${MANAGED_CONF_FILES[@]}"; do
        if [ -f "$file" ]; then
            local backup_path
            if backup_path=$(backup_config_file "$file" "$force"); then
                if [ -f "$backup_path" ]; then
                    # Check if this is a new backup or existing
                    local file_checksum existing_checksum
                    file_checksum=$(calculate_file_checksum "$file")
                    existing_checksum=$(calculate_file_checksum "$backup_path")
                    if [ "$file_checksum" = "$existing_checksum" ]; then
                        backed_up=$((backed_up + 1))
                        print_success "Backed up: $file -> $(basename "$backup_path")"
                    fi
                fi
            else
                failed=$((failed + 1))
                print_error "Failed to backup: $file"
            fi
        else
            skipped=$((skipped + 1))
        fi
    done
    
    echo ""
    print_info "Backup summary: $backed_up backed up, $skipped skipped (not found), $failed failed"
    
    return 0
}

# List available backups
list_backups() {
    local filter="${1:-}"
    
    if [ ! -d "$BACKUP_DIR" ]; then
        print_info "No backups found (backup directory does not exist)"
        return 0
    fi
    
    echo -e "\n${CYAN}Available backups in ${BACKUP_DIR}:${RESET}\n"
    
    local pattern="*.backup"
    if [ -n "$filter" ]; then
        pattern="${filter}.*.backup"
    fi
    
    # List backups grouped by config file
    local found=false
    for base in manager.conf pjsip.conf extensions.conf cdr.conf cel.conf; do
        if [ -n "$filter" ] && [ "$base" != "$filter" ]; then
            continue
        fi
        
        local backups=()
        shopt -s nullglob
        backups=("${BACKUP_DIR}/${base}".*.backup)
        shopt -u nullglob
        
        if [ ${#backups[@]} -gt 0 ]; then
            found=true
            echo -e "${YELLOW}$base:${RESET}"
            for backup in "${backups[@]}"; do
                local basename timestamp size
                basename=$(basename "$backup")
                # Extract timestamp from filename (format: name.timestamp.backup)
                timestamp=$(echo "$basename" | sed 's/.*\.\([0-9_]*\)\.backup/\1/')
                size=$(du -h "$backup" 2>/dev/null | cut -f1)
                echo "  - $basename (${size:-unknown})"
            done
            echo ""
        fi
    done
    
    if [ "$found" = false ]; then
        print_info "No backups found"
    fi
}

# Restore a specific backup
restore_backup() {
    local backup_file="$1"
    local target_file="$2"
    
    # If backup_file doesn't include path, add backup directory
    if [[ ! "$backup_file" == /* ]]; then
        backup_file="${BACKUP_DIR}/${backup_file}"
    fi
    
    if [ ! -f "$backup_file" ]; then
        print_error "Backup file not found: $backup_file"
        return 1
    fi
    
    # Determine target file from backup name if not specified
    if [ -z "$target_file" ]; then
        local basename
        basename=$(basename "$backup_file")
        # Extract original filename (format: name.timestamp.backup)
        local orig_name
        orig_name=$(echo "$basename" | sed 's/\.[0-9_]*\.backup$//')
        target_file="/etc/asterisk/${orig_name}"
    fi
    
    # Create a backup of current file before restoring
    if [ -f "$target_file" ]; then
        print_info "Creating backup of current $target_file before restore..."
        backup_config_file "$target_file" true
    fi
    
    # Restore the backup
    cp "$backup_file" "$target_file"
    chmod 644 "$target_file"
    
    print_success "Restored: $backup_file -> $target_file"
    
    return 0
}

# Get the latest backup for a config file
get_latest_backup() {
    local config_file="$1"
    local basename
    basename=$(basename "$config_file")
    
    if [ ! -d "$BACKUP_DIR" ]; then
        return 1
    fi
    
    local pattern="${BACKUP_DIR}/${basename}.*.backup"
    
    shopt -s nullglob
    local backups=($pattern)
    shopt -u nullglob
    
    if [ ${#backups[@]} -eq 0 ]; then
        return 1
    fi
    
    # Sort by timestamp (newest first) and return the first
    printf '%s\n' "${backups[@]}" | sort -r | head -1
}

# Cleanup old backups, keeping a specified number of most recent ones
cleanup_backups() {
    local keep="${1:-5}"
    
    if [ ! -d "$BACKUP_DIR" ]; then
        print_info "No backups to clean up"
        return 0
    fi
    
    print_info "Cleaning up old backups (keeping $keep most recent per file)..."
    
    local deleted=0
    
    for base in manager.conf pjsip.conf extensions.conf cdr.conf cel.conf; do
        local backups=()
        shopt -s nullglob
        backups=("${BACKUP_DIR}/${base}".*.backup)
        shopt -u nullglob
        
        local count=${#backups[@]}
        if [ "$count" -gt "$keep" ]; then
            # Sort by timestamp and delete oldest ones
            local to_delete=$((count - keep))
            printf '%s\n' "${backups[@]}" | sort | head -n "$to_delete" | while read -r backup; do
                rm -f "$backup"
                deleted=$((deleted + 1))
                print_info "Deleted: $(basename "$backup")"
            done
        fi
    done
    
    print_success "Cleanup complete"
}

# Show backup status/summary
show_status() {
    echo -e "\n${CYAN}RayanPBX Backup Status${RESET}\n"
    
    echo "Backup directory: $BACKUP_DIR"
    
    if [ ! -d "$BACKUP_DIR" ]; then
        echo "Status: Not initialized (no backups yet)"
        return 0
    fi
    
    echo ""
    
    local total=0
    local total_size=0
    
    for base in manager.conf pjsip.conf extensions.conf cdr.conf cel.conf; do
        local backups=()
        shopt -s nullglob
        backups=("${BACKUP_DIR}/${base}".*.backup)
        shopt -u nullglob
        
        local count=${#backups[@]}
        total=$((total + count))
        
        if [ "$count" -gt 0 ]; then
            local latest
            latest=$(printf '%s\n' "${backups[@]}" | sort -r | head -1)
            local latest_time
            latest_time=$(stat -c %y "$latest" 2>/dev/null | cut -d. -f1)
            echo "$base: $count backup(s), latest: $latest_time"
        else
            echo "$base: no backups"
        fi
    done
    
    echo ""
    echo "Total backups: $total"
    
    if [ -d "$BACKUP_DIR" ]; then
        local dir_size
        dir_size=$(du -sh "$BACKUP_DIR" 2>/dev/null | cut -f1)
        echo "Total size: ${dir_size:-unknown}"
    fi
}

# Show help
show_help() {
    cat << 'EOF'
RayanPBX Backup Manager

Usage: backup-manager.sh <command> [options]

Commands:
  backup [file] [--force]    Backup config file(s). Without file, backs up all managed configs.
                             Use --force to create backup even if identical exists.
  
  restore <backup> [target]  Restore a backup file. Target defaults to /etc/asterisk/<name>
  
  list [filter]              List available backups. Filter by config name (e.g., manager.conf)
  
  latest <config>            Get the path to the latest backup for a config file
  
  cleanup [keep]             Remove old backups, keeping 'keep' most recent per file (default: 5)
  
  status                     Show backup status summary
  
  help                       Show this help message

Managed configuration files:
  - /etc/asterisk/manager.conf
  - /etc/asterisk/pjsip.conf
  - /etc/asterisk/extensions.conf
  - /etc/asterisk/cdr.conf
  - /etc/asterisk/cel.conf

Backups are stored in: /etc/asterisk/backups/

Examples:
  backup-manager.sh backup                    # Backup all managed configs
  backup-manager.sh backup manager.conf       # Backup only manager.conf
  backup-manager.sh list                      # List all backups
  backup-manager.sh restore manager.conf.20240101_120000.backup
  backup-manager.sh cleanup 3                 # Keep only 3 most recent backups per file
EOF
}

# Main command dispatcher
main() {
    local command="${1:-help}"
    shift 2>/dev/null || true
    
    case "$command" in
        backup)
            if [ -n "$1" ] && [[ ! "$1" == --* ]]; then
                local file="$1"
                shift
                # Handle full path or just filename
                if [[ ! "$file" == /* ]]; then
                    file="/etc/asterisk/$file"
                fi
                local force=false
                [ "$1" = "--force" ] && force=true
                backup_config_file "$file" "$force"
            else
                local force=false
                [ "$1" = "--force" ] && force=true
                backup_all "$force"
            fi
            ;;
        restore)
            restore_backup "$1" "$2"
            ;;
        list)
            list_backups "$1"
            ;;
        latest)
            get_latest_backup "$1"
            ;;
        cleanup)
            cleanup_backups "${1:-5}"
            ;;
        status)
            show_status
            ;;
        help|--help|-h)
            show_help
            ;;
        *)
            print_error "Unknown command: $command"
            echo "Run 'backup-manager.sh help' for usage information."
            exit 1
            ;;
    esac
}

# Run main function if script is executed directly (not sourced)
if [ "${BASH_SOURCE[0]}" = "${0}" ]; then
    main "$@"
fi
