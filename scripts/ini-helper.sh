#!/bin/bash

# INI Configuration File Helper
# Modifies INI-style configuration files while preserving structure

# Source the backup manager for centralized backup functionality
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [ -f "${SCRIPT_DIR}/backup-manager.sh" ]; then
    source "${SCRIPT_DIR}/backup-manager.sh"
fi

# Function to uncomment a line in INI file
uncomment_ini_line() {
    local file="$1"
    local section="$2"
    local key="$3"
    
    # Use sed to find and uncomment the line in the section
    sed -i "/^\[$section\]/,/^\[/ s/^[;#]\s*\($key\s*=\)/\1/" "$file"
}

# Function to comment a line in INI file
comment_ini_line() {
    local file="$1"
    local section="$2"
    local key="$3"
    
    # Use sed to find and comment the line in the section
    sed -i "/^\[$section\]/,/^\[/ s/^\($key\s*=\)/; \1/" "$file"
}

# Function to set INI value (uncomment if needed and update value)
set_ini_value() {
    local file="$1"
    local section="$2"
    local key="$3"
    local value="$4"
    
    # First, uncomment if commented
    uncomment_ini_line "$file" "$section" "$key"
    
    # Check if key exists in section
    if grep -A 50 "^\[$section\]" "$file" | grep -q "^$key\s*="; then
        # Update existing value
        sed -i "/^\[$section\]/,/^\[/ s|^\($key\s*=\s*\).*|\1$value|" "$file"
    else
        # Add new key-value pair to section
        sed -i "/^\[$section\]/a $key = $value" "$file"
    fi
}

# Function to ensure section exists
ensure_ini_section() {
    local file="$1"
    local section="$2"
    
    if ! grep -q "^\[$section\]" "$file"; then
        echo "" >> "$file"
        echo "[$section]" >> "$file"
    fi
}

# Normalize/reorder keys in a section to a known working order
# This is crucial for Asterisk manager.conf where ACL order matters
# (deny must come before permit for proper ACL evaluation)
normalize_ini_section() {
    local file="$1"
    local section="$2"
    # Expected key order (space-separated)
    local key_order="$3"
    
    [ ! -f "$file" ] && return 1
    
    # Extract all key=value pairs from the section
    local section_start
    section_start=$(grep -n "^\[$section\]" "$file" | head -1 | cut -d: -f1)
    [ -z "$section_start" ] && return 1
    
    # Find section end (next section or end of file)
    local section_end
    section_end=$(tail -n +$((section_start + 1)) "$file" | grep -n "^\[" | head -1 | cut -d: -f1)
    if [ -z "$section_end" ]; then
        section_end=$(wc -l < "$file")
    else
        section_end=$((section_start + section_end - 1))
    fi
    
    # Extract key-value pairs from section (excluding comments and section header)
    local pairs=()
    local line_num=$((section_start + 1))
    while [ $line_num -le $section_end ]; do
        local line
        line=$(sed -n "${line_num}p" "$file")
        # Skip comments and empty lines, only process key=value lines
        if [[ -n "$line" ]] && [[ ! "$line" =~ ^[[:space:]]*[\;#] ]] && [[ "$line" =~ = ]]; then
            pairs+=("$line")
        fi
        line_num=$((line_num + 1))
    done
    
    # Build ordered output
    local ordered_pairs=()
    for key in $key_order; do
        for pair in "${pairs[@]}"; do
            if [[ "$pair" =~ ^[[:space:]]*${key}[[:space:]]*= ]]; then
                ordered_pairs+=("$pair")
                break
            fi
        done
    done
    
    # Add any pairs not in the order list (preserve custom settings)
    for pair in "${pairs[@]}"; do
        local found=false
        for ordered in "${ordered_pairs[@]}"; do
            [ "$pair" = "$ordered" ] && { found=true; break; }
        done
        [ "$found" = false ] && ordered_pairs+=("$pair")
    done
    
    # Reconstruct the section
    # Remove old section content (keep header)
    sed -i "$((section_start + 1)),${section_end}d" "$file"
    
    # Insert ordered pairs after section header
    local insert_line=$section_start
    for pair in "${ordered_pairs[@]}"; do
        sed -i "${insert_line}a\\${pair}" "$file"
        insert_line=$((insert_line + 1))
    done
    
    return 0
}

# Helper function to calculate file checksum
# Note: This function is kept for backward compatibility
# The backup_config_file function in backup-manager.sh also provides this
calculate_file_checksum() {
    local file="$1"
    
    if [ ! -f "$file" ]; then
        return 1
    fi
    
    if command -v md5sum &> /dev/null; then
        md5sum "$file" | awk '{print $1}'
    elif command -v shasum &> /dev/null; then
        shasum -a 256 "$file" | awk '{print $1}'
    else
        # No checksum tool available
        return 1
    fi
}

# Function to backup config file
# This function now uses the centralized backup manager to store backups
# in /etc/asterisk/backups/ subdirectory instead of the same directory
backup_config() {
    local file="$1"
    
    if [ ! -f "$file" ]; then
        return 1
    fi
    
    # Use centralized backup manager if available
    if type backup_config_file &>/dev/null; then
        backup_config_file "$file"
        return $?
    fi
    
    # Fallback to legacy behavior if backup manager is not available
    # Calculate checksum of the file to backup
    local file_checksum
    if ! file_checksum=$(calculate_file_checksum "$file"); then
        # Fallback: if no checksum tool available, always create backup
        local backup="${file}.backup.$(date +%Y%m%d_%H%M%S)"
        cp "$file" "$backup"
        echo "$backup"
        return 0
    fi
    
    # Check if any existing backup has the same checksum
    local existing_backup
    local backup_pattern="${file}.backup.*"
    
    # Use nullglob-safe approach: check if pattern expands to actual files
    shopt -s nullglob
    local backups=($backup_pattern)
    shopt -u nullglob
    
    for existing_backup in "${backups[@]}"; do
        if [ -f "$existing_backup" ]; then
            local backup_checksum
            if backup_checksum=$(calculate_file_checksum "$existing_backup"); then
                if [ "$file_checksum" = "$backup_checksum" ]; then
                    # Identical backup already exists, no need to create a new one
                    echo "$existing_backup"
                    return 0
                fi
            fi
        fi
    done
    
    # No identical backup exists, create a new one
    local backup="${file}.backup.$(date +%Y%m%d_%H%M%S)"
    cp "$file" "$backup"
    echo "$backup"
}

# Main function for modifying Asterisk manager.conf
modify_manager_conf() {
    local file="/etc/asterisk/manager.conf"
    local backup
    
    if [ ! -f "$file" ]; then
        echo "Error: $file not found"
        return 1
    fi
    
    # Backup first
    backup=$(backup_config "$file")
    echo "Created backup: $backup"
    
    # Modify [general] section
    ensure_ini_section "$file" "general"
    set_ini_value "$file" "general" "enabled" "yes"
    set_ini_value "$file" "general" "port" "5038"
    set_ini_value "$file" "general" "bindaddr" "127.0.0.1"
    
    # Modify or create [admin] section
    ensure_ini_section "$file" "admin"
    set_ini_value "$file" "admin" "secret" "${1:-rayanpbx_ami_secret}"
    set_ini_value "$file" "admin" "deny" "0.0.0.0/0.0.0.0"
    set_ini_value "$file" "admin" "permit" "127.0.0.1/255.255.255.255"
    set_ini_value "$file" "admin" "read" "all"
    set_ini_value "$file" "admin" "write" "all"
    
    # Normalize section order - critical for Asterisk AMI
    # deny must come before permit for proper ACL evaluation
    normalize_ini_section "$file" "general" "enabled port bindaddr"
    normalize_ini_section "$file" "admin" "secret deny permit read write"
    
    echo "Modified $file successfully"
}

# If script is called directly (not sourced)
if [ "${BASH_SOURCE[0]}" = "${0}" ]; then
    case "$1" in
        modify-manager)
            modify_manager_conf "$2"
            ;;
        set)
            set_ini_value "$2" "$3" "$4" "$5"
            ;;
        comment)
            comment_ini_line "$2" "$3" "$4"
            ;;
        uncomment)
            uncomment_ini_line "$2" "$3" "$4"
            ;;
        backup)
            backup_config "$2"
            ;;
        *)
            echo "Usage: $0 {modify-manager|set|comment|uncomment|backup} [args...]"
            echo "  modify-manager [ami_secret]  - Modify Asterisk manager.conf"
            echo "  set FILE SECTION KEY VALUE   - Set INI value"
            echo "  comment FILE SECTION KEY     - Comment INI line"
            echo "  uncomment FILE SECTION KEY   - Uncomment INI line"
            echo "  backup FILE                  - Backup config file"
            exit 1
            ;;
    esac
fi
