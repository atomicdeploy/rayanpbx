#!/bin/bash

# RayanPBX AMI Credential Fix Script
# Extracts AMI secret from manager.conf, updates .env, and tests the connection

set -euo pipefail

# Version - read from VERSION file
VERSION="2.0.0"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
VERSION_FILE="$SCRIPT_DIR/../VERSION"
if [ -f "$VERSION_FILE" ]; then
    VERSION=$(cat "$VERSION_FILE" | tr -d '[:space:]')
fi

# Source ini-helper for INI file manipulation
if [ -f "$SCRIPT_DIR/ini-helper.sh" ]; then
    source "$SCRIPT_DIR/ini-helper.sh"
fi

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
MAGENTA='\033[0;35m'
BOLD='\033[1m'
DIM='\033[2m'
NC='\033[0m' # No Color

# Configuration
MANAGER_CONF="${MANAGER_CONF:-/etc/asterisk/manager.conf}"
DEFAULT_AMI_HOST="127.0.0.1"
DEFAULT_AMI_PORT="5038"
DEFAULT_AMI_USERNAME="admin"

# Helper functions
print_success() {
    echo -e "${GREEN}✅ $1${NC}"
}

print_error() {
    echo -e "${RED}❌ $1${NC}"
}

print_info() {
    echo -e "${CYAN}ℹ️  $1${NC}"
}

print_warn() {
    echo -e "${YELLOW}⚠️  $1${NC}"
}

print_header() {
    echo -e "${MAGENTA}═══════════════════════════════════════${NC}"
    echo -e "${CYAN}  $1${NC}"
    echo -e "${MAGENTA}═══════════════════════════════════════${NC}"
}

# Helper function to find project root by looking for VERSION file
find_project_root() {
    local current_dir="$(pwd)"
    local max_depth=5
    
    for ((i=0; i<max_depth; i++)); do
        if [ -f "$current_dir/VERSION" ]; then
            echo "$current_dir"
            return
        fi
        
        local parent_dir="$(dirname "$current_dir")"
        if [ "$parent_dir" = "$current_dir" ]; then
            break
        fi
        current_dir="$parent_dir"
    done
    
    # Check common installation paths
    if [ -f "/opt/rayanpbx/VERSION" ]; then
        echo "/opt/rayanpbx"
        return
    fi
    
    # Return script directory's parent as fallback
    echo "$(dirname "$SCRIPT_DIR")"
}

# Find .env file path
find_env_file() {
    local env_paths=(
        "/opt/rayanpbx/.env"
        "/usr/local/rayanpbx/.env"
        "/etc/rayanpbx/.env"
    )
    
    # Add project root .env
    local project_root
    project_root=$(find_project_root)
    env_paths+=("$project_root/.env")
    
    # Add current directory .env
    env_paths+=("$(pwd)/.env")
    
    # Return first existing .env file
    for env_file in "${env_paths[@]}"; do
        if [ -f "$env_file" ]; then
            echo "$env_file"
            return 0
        fi
    done
    
    # Return default path if none found (for creation)
    echo "$project_root/.env"
    return 1
}

# Extract AMI secret from manager.conf for a specific user
extract_ami_secret() {
    local manager_conf="$1"
    local username="${2:-admin}"
    
    if [ ! -f "$manager_conf" ]; then
        echo ""
        return 1
    fi
    
    # Parse the secret from the user section in manager.conf
    # Look for [username] section and extract the secret value
    local secret=""
    local in_section=false
    
    while IFS= read -r line || [[ -n "$line" ]]; do
        # Remove leading/trailing whitespace and carriage returns
        line=$(echo "$line" | tr -d '\r' | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')
        
        # Skip empty lines and comments (lines starting with ; or #)
        [[ -z "$line" ]] && continue
        [[ "$line" == ";"* ]] && continue
        [[ "$line" == "#"* ]] && continue
        
        # Check for section headers
        if [[ "$line" =~ ^\[([^]]+)\]$ ]]; then
            section="${BASH_REMATCH[1]}"
            if [ "$section" = "$username" ]; then
                in_section=true
            else
                in_section=false
            fi
            continue
        fi
        
        # If in the target section, look for secret
        if [ "$in_section" = true ]; then
            if [[ "$line" =~ ^secret[[:space:]]*=[[:space:]]*(.+)$ ]]; then
                secret="${BASH_REMATCH[1]}"
                # Remove any trailing comments
                secret="${secret%%;*}"
                secret="${secret%%#*}"
                # Trim whitespace
                secret=$(echo "$secret" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')
                echo "$secret"
                return 0
            fi
        fi
    done < "$manager_conf"
    
    echo ""
    return 1
}

# Extract AMI username from manager.conf (first non-general section with secret)
extract_ami_username() {
    local manager_conf="$1"
    
    if [ ! -f "$manager_conf" ]; then
        echo "$DEFAULT_AMI_USERNAME"
        return 1
    fi
    
    # Find the first section with a secret (that's not [general])
    local current_section=""
    
    while IFS= read -r line || [[ -n "$line" ]]; do
        # Remove leading/trailing whitespace and carriage returns
        line=$(echo "$line" | tr -d '\r' | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')
        
        # Skip empty lines and comments (lines starting with ; or #)
        [[ -z "$line" ]] && continue
        [[ "$line" == ";"* ]] && continue
        [[ "$line" == "#"* ]] && continue
        
        # Check for section headers
        if [[ "$line" =~ ^\[([^]]+)\]$ ]]; then
            current_section="${BASH_REMATCH[1]}"
            continue
        fi
        
        # Skip [general] section
        if [ "$current_section" = "general" ]; then
            continue
        fi
        
        # If we find a secret line, return the current section name as username
        if [[ "$line" =~ ^secret[[:space:]]*= ]]; then
            echo "$current_section"
            return 0
        fi
    done < "$manager_conf"
    
    echo "$DEFAULT_AMI_USERNAME"
    return 1
}

# Update .env file with AMI credentials
update_env_file() {
    local env_file="$1"
    local ami_host="$2"
    local ami_port="$3"
    local ami_username="$4"
    local ami_secret="$5"
    
    # Create .env if it doesn't exist
    if [ ! -f "$env_file" ]; then
        print_warn ".env file not found at $env_file"
        print_info "Creating new .env file..."
        
        # Create directory if needed
        local env_dir
        env_dir=$(dirname "$env_file")
        if [ ! -d "$env_dir" ]; then
            mkdir -p "$env_dir"
        fi
        
        # Create minimal .env with AMI settings
        cat > "$env_file" << EOF
# RayanPBX Configuration
# Generated by fix-ami-credentials.sh

# Asterisk AMI Configuration
ASTERISK_AMI_HOST=$ami_host
ASTERISK_AMI_PORT=$ami_port
ASTERISK_AMI_USERNAME=$ami_username
ASTERISK_AMI_SECRET=$ami_secret
EOF
        
        print_success "Created .env file with AMI credentials"
        return 0
    fi
    
    # Backup existing .env
    local backup
    backup=$(backup_config "$env_file" 2>/dev/null || cp "$env_file" "${env_file}.backup.$(date +%Y%m%d_%H%M%S)" && echo "${env_file}.backup.$(date +%Y%m%d_%H%M%S)")
    print_info "Backup created: $backup"
    
    # Update or add each AMI setting
    update_env_var "$env_file" "ASTERISK_AMI_HOST" "$ami_host"
    update_env_var "$env_file" "ASTERISK_AMI_PORT" "$ami_port"
    update_env_var "$env_file" "ASTERISK_AMI_USERNAME" "$ami_username"
    update_env_var "$env_file" "ASTERISK_AMI_SECRET" "$ami_secret"
    
    print_success ".env file updated with AMI credentials"
    return 0
}

# Update a single variable in .env file
update_env_var() {
    local env_file="$1"
    local key="$2"
    local value="$3"
    
    # Escape special characters in value for sed
    local escaped_value
    escaped_value=$(printf '%s\n' "$value" | sed 's:[\/&]:\\&:g')
    
    # Check if key exists
    if grep -q "^${key}=" "$env_file" 2>/dev/null; then
        # Update existing key (using @ as delimiter to avoid conflicts with /)
        sed -i "s@^${key}=.*@${key}=${escaped_value}@" "$env_file"
    else
        # Add new key
        echo "${key}=${value}" >> "$env_file"
    fi
}

# Test AMI connection
test_ami_connection() {
    local host="$1"
    local port="$2"
    local username="$3"
    local secret="$4"
    
    print_info "Testing AMI connection to $host:$port..."
    
    # Check if port is listening
    local port_listening=false
    if command -v ss &> /dev/null; then
        if ss -tuln 2>/dev/null | grep -qE ":${port}([[:space:]]|$)"; then
            port_listening=true
        fi
    elif command -v netstat &> /dev/null; then
        if netstat -tuln 2>/dev/null | grep -qE ":${port}([[:space:]]|$)"; then
            port_listening=true
        fi
    fi
    
    if [ "$port_listening" = false ]; then
        print_error "AMI port $port is not listening"
        print_warn "Asterisk may not be running or AMI is not enabled"
        return 1
    fi
    
    print_success "AMI port $port is listening"
    
    # Test authentication
    if command -v nc &> /dev/null; then
        local ami_response
        ami_response=$(echo -e "Action: Login\r\nUsername: $username\r\nSecret: $secret\r\n\r\n" | timeout 5 nc "$host" "$port" 2>/dev/null | head -20)
        
        if echo "$ami_response" | grep -qi "Success"; then
            print_success "AMI authentication successful!"
            return 0
        elif echo "$ami_response" | grep -qi "Authentication failed"; then
            print_error "AMI authentication failed"
            print_warn "The secret in manager.conf may not match"
            return 1
        else
            print_warn "Could not verify AMI authentication"
            print_info "Response: $(echo "$ami_response" | head -3 | tr '\n' ' ')"
            return 1
        fi
    else
        print_warn "netcat (nc) not available for connection testing"
        print_info "Install with: apt install netcat-openbsd"
        return 1
    fi
}

# Reload Asterisk manager module
reload_asterisk_manager() {
    print_info "Reloading Asterisk manager module..."
    
    if command -v asterisk &> /dev/null; then
        if asterisk -rx "manager reload" > /dev/null 2>&1; then
            print_success "Asterisk manager reloaded"
            sleep 2
            return 0
        else
            print_warn "Could not reload Asterisk manager"
            print_info "Try: systemctl restart asterisk"
            return 1
        fi
    else
        print_warn "Asterisk CLI not available"
        return 1
    fi
}

# Main fix function - extract, update, and test
fix_ami_credentials() {
    local auto_reload="${1:-true}"
    
    print_header "🔧 AMI Credential Fix"
    echo ""
    
    # Step 1: Check if manager.conf exists
    if [ ! -f "$MANAGER_CONF" ]; then
        print_error "manager.conf not found at $MANAGER_CONF"
        print_info "Asterisk may not be installed or configured"
        return 1
    fi
    
    print_success "Found manager.conf at $MANAGER_CONF"
    
    # Step 2: Extract AMI username and secret from manager.conf
    print_info "Extracting AMI credentials from manager.conf..."
    
    local ami_username
    ami_username=$(extract_ami_username "$MANAGER_CONF")
    
    local ami_secret
    ami_secret=$(extract_ami_secret "$MANAGER_CONF" "$ami_username")
    
    if [ -z "$ami_secret" ]; then
        print_error "Could not extract AMI secret from manager.conf"
        print_info "Please verify that manager.conf has a valid user section with a secret"
        echo ""
        echo "Expected format in manager.conf:"
        echo "  [$ami_username]"
        echo "  secret = your_secret_here"
        echo "  ..."
        return 1
    fi
    
    print_success "Extracted AMI username: $ami_username"
    print_success "Extracted AMI secret: ${ami_secret:0:4}****"
    
    # Step 3: Find and update .env file
    print_info "Locating .env file..."
    
    local env_file
    env_file=$(find_env_file) || true
    
    print_info "Using .env file: $env_file"
    
    update_env_file "$env_file" "$DEFAULT_AMI_HOST" "$DEFAULT_AMI_PORT" "$ami_username" "$ami_secret"
    
    # Step 4: Reload Asterisk if requested
    if [ "$auto_reload" = "true" ]; then
        reload_asterisk_manager
    fi
    
    # Step 5: Test the connection
    echo ""
    print_header "🔌 Testing AMI Connection"
    echo ""
    
    if test_ami_connection "$DEFAULT_AMI_HOST" "$DEFAULT_AMI_PORT" "$ami_username" "$ami_secret"; then
        echo ""
        print_header "✅ AMI Credentials Fixed Successfully"
        echo ""
        echo -e "  ${CYAN}AMI Host:${NC}     $DEFAULT_AMI_HOST"
        echo -e "  ${CYAN}AMI Port:${NC}     $DEFAULT_AMI_PORT"
        echo -e "  ${CYAN}AMI Username:${NC} $ami_username"
        echo -e "  ${CYAN}AMI Secret:${NC}   ${ami_secret:0:4}****"
        echo -e "  ${CYAN}.env File:${NC}    $env_file"
        echo ""
        return 0
    else
        echo ""
        print_warn "AMI connection test failed"
        print_info "Credentials were updated, but connection could not be verified"
        print_info "Please check:"
        echo "  1. Asterisk is running: systemctl status asterisk"
        echo "  2. AMI is enabled in manager.conf: enabled = yes"
        echo "  3. Firewall allows local connections to port $DEFAULT_AMI_PORT"
        echo ""
        return 1
    fi
}

# Check current AMI status (read-only check)
check_ami_status() {
    print_header "🔍 AMI Status Check"
    echo ""
    
    # Check manager.conf
    if [ ! -f "$MANAGER_CONF" ]; then
        print_error "manager.conf not found at $MANAGER_CONF"
        return 1
    fi
    
    print_success "Found manager.conf"
    
    # Extract current settings
    local ami_username
    ami_username=$(extract_ami_username "$MANAGER_CONF")
    
    local ami_secret
    ami_secret=$(extract_ami_secret "$MANAGER_CONF" "$ami_username")
    
    echo ""
    echo -e "${CYAN}manager.conf Configuration:${NC}"
    echo -e "  Username: ${ami_username:-${RED}not found${NC}}"
    if [ -n "$ami_secret" ]; then
        echo -e "  Secret:   ${GREEN}configured${NC} (${ami_secret:0:4}****)"
    else
        echo -e "  Secret:   ${RED}not found${NC}"
    fi
    
    # Check .env file
    echo ""
    local env_file
    if env_file=$(find_env_file) && [ -f "$env_file" ]; then
        print_success "Found .env file: $env_file"
        
        local env_host env_port env_username env_secret
        env_host=$(grep "^ASTERISK_AMI_HOST=" "$env_file" 2>/dev/null | cut -d'=' -f2- | tr -d '"' | tr -d "'" || echo "")
        env_port=$(grep "^ASTERISK_AMI_PORT=" "$env_file" 2>/dev/null | cut -d'=' -f2- | tr -d '"' | tr -d "'" || echo "")
        env_username=$(grep "^ASTERISK_AMI_USERNAME=" "$env_file" 2>/dev/null | cut -d'=' -f2- | tr -d '"' | tr -d "'" || echo "")
        env_secret=$(grep "^ASTERISK_AMI_SECRET=" "$env_file" 2>/dev/null | cut -d'=' -f2- | tr -d '"' | tr -d "'" || echo "")
        
        echo ""
        echo -e "${CYAN}.env Configuration:${NC}"
        echo -e "  Host:     ${env_host:-${RED}not set${NC}}"
        echo -e "  Port:     ${env_port:-${RED}not set${NC}}"
        echo -e "  Username: ${env_username:-${RED}not set${NC}}"
        if [ -n "$env_secret" ]; then
            echo -e "  Secret:   ${GREEN}configured${NC} (${env_secret:0:4}****)"
        else
            echo -e "  Secret:   ${RED}not set${NC}"
        fi
        
        # Compare secrets
        echo ""
        if [ -n "$ami_secret" ] && [ -n "$env_secret" ]; then
            if [ "$ami_secret" = "$env_secret" ]; then
                print_success "Secrets match between manager.conf and .env"
            else
                print_error "Secrets DO NOT match between manager.conf and .env"
                print_info "Run 'fix-ami-credentials.sh fix' to synchronize"
            fi
        fi
    else
        print_warn ".env file not found"
    fi
    
    # Test connection
    echo ""
    print_info "Testing AMI connection..."
    
    local test_username="${ami_username:-$DEFAULT_AMI_USERNAME}"
    local test_secret="${ami_secret:-}"
    
    if [ -n "$test_secret" ]; then
        test_ami_connection "$DEFAULT_AMI_HOST" "$DEFAULT_AMI_PORT" "$test_username" "$test_secret"
    else
        print_error "Cannot test connection: no secret found in manager.conf"
    fi
    
    echo ""
}

# Show usage
show_usage() {
    echo -e "${CYAN}${BOLD}RayanPBX AMI Credential Fix Tool${NC} ${GREEN}v${VERSION}${NC}"
    echo ""
    echo "This tool extracts AMI credentials from Asterisk's manager.conf,"
    echo "updates the .env file, and verifies the connection is working."
    echo ""
    echo -e "${YELLOW}Usage:${NC}"
    echo "  $0 [command] [options]"
    echo ""
    echo -e "${YELLOW}Commands:${NC}"
    echo "  fix          Extract credentials from manager.conf, update .env, and test"
    echo "  check        Check current AMI status without making changes"
    echo "  test         Test AMI connection with current .env credentials"
    echo "  help         Show this help message"
    echo ""
    echo -e "${YELLOW}Options:${NC}"
    echo "  --no-reload  Don't reload Asterisk after updating credentials"
    echo "  --manager-conf PATH  Specify custom manager.conf path"
    echo ""
    echo -e "${YELLOW}Examples:${NC}"
    echo "  $0 fix                    # Fix and sync credentials"
    echo "  $0 check                  # Check current status"
    echo "  $0 fix --no-reload        # Fix without reloading Asterisk"
    echo ""
}

# Main entry point
main() {
    local command="${1:-help}"
    local auto_reload="true"
    
    # Parse options
    shift || true
    while [[ $# -gt 0 ]]; do
        case "$1" in
            --no-reload)
                auto_reload="false"
                shift
                ;;
            --manager-conf)
                MANAGER_CONF="$2"
                shift 2
                ;;
            --version|-v)
                echo -e "${CYAN}RayanPBX AMI Credential Fix${NC} ${GREEN}v${VERSION}${NC}"
                exit 0
                ;;
            *)
                shift
                ;;
        esac
    done
    
    case "$command" in
        fix)
            fix_ami_credentials "$auto_reload"
            ;;
        check|status)
            check_ami_status
            ;;
        test)
            # Load credentials from .env and test
            local env_file
            env_file=$(find_env_file)
            
            if [ -f "$env_file" ]; then
                local host port username secret
                host=$(grep "^ASTERISK_AMI_HOST=" "$env_file" 2>/dev/null | cut -d'=' -f2- | tr -d '"' | tr -d "'" || echo "$DEFAULT_AMI_HOST")
                port=$(grep "^ASTERISK_AMI_PORT=" "$env_file" 2>/dev/null | cut -d'=' -f2- | tr -d '"' | tr -d "'" || echo "$DEFAULT_AMI_PORT")
                username=$(grep "^ASTERISK_AMI_USERNAME=" "$env_file" 2>/dev/null | cut -d'=' -f2- | tr -d '"' | tr -d "'" || echo "$DEFAULT_AMI_USERNAME")
                secret=$(grep "^ASTERISK_AMI_SECRET=" "$env_file" 2>/dev/null | cut -d'=' -f2- | tr -d '"' | tr -d "'" || echo "")
                
                if [ -n "$secret" ]; then
                    print_header "🔌 AMI Connection Test"
                    echo ""
                    test_ami_connection "${host:-$DEFAULT_AMI_HOST}" "${port:-$DEFAULT_AMI_PORT}" "${username:-$DEFAULT_AMI_USERNAME}" "$secret"
                else
                    print_error "No AMI secret found in .env file"
                    exit 1
                fi
            else
                print_error ".env file not found"
                exit 1
            fi
            ;;
        help|--help|-h)
            show_usage
            ;;
        *)
            print_error "Unknown command: $command"
            echo ""
            show_usage
            exit 2
            ;;
    esac
}

# Run if called directly
if [ "${BASH_SOURCE[0]}" = "${0}" ]; then
    main "$@"
fi
