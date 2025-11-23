#!/bin/bash

# INI Configuration File Helper
# Modifies INI-style configuration files while preserving structure

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

# Function to backup config file
backup_config() {
    local file="$1"
    local backup="${file}.backup.$(date +%Y%m%d_%H%M%S)"
    
    if [ -f "$file" ]; then
        cp "$file" "$backup"
        echo "$backup"
    fi
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
    
    echo "Modified $file successfully"
}

# If script is called directly (not sourced)
if [ "${BASH_SOURCE[0]}" -eq "${0}" ]; then
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
