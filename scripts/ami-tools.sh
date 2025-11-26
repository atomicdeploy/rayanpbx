#!/bin/bash

# ============================================================================
# AMI Tools - Asterisk Manager Interface Configuration & Diagnostics
# ============================================================================
# 
# A comprehensive tool for AMI configuration, testing, and diagnostics.
# Can be used standalone with command-line flags, or sourced by other scripts.
#
# Usage (standalone):
#   ./ami-tools.sh fix                    - Fix AMI credentials
#   ./ami-tools.sh check                  - Check AMI health
#   ./ami-tools.sh test                   - Test AMI connection
#   ./ami-tools.sh diag                   - Run full diagnostics
#   ./ami-tools.sh --help                 - Show help
#
# Usage (sourced):
#   source ami-tools.sh
#   test_ami_connection "127.0.0.1" "5038" "admin" "secret"
#   configure_ami "/etc/asterisk/manager.conf" "secret" "admin"
#
# ============================================================================

# Version
AMI_TOOLS_VERSION="2.0.0"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [ -f "$SCRIPT_DIR/../VERSION" ]; then
    AMI_TOOLS_VERSION=$(cat "$SCRIPT_DIR/../VERSION" | tr -d '[:space:]')
fi

# ============================================================================
# Default Configuration
# ============================================================================
DEFAULT_AMI_HOST="${DEFAULT_AMI_HOST:-127.0.0.1}"
DEFAULT_AMI_PORT="${DEFAULT_AMI_PORT:-5038}"
DEFAULT_AMI_USERNAME="${DEFAULT_AMI_USERNAME:-admin}"
DEFAULT_AMI_SECRET="${DEFAULT_AMI_SECRET:-rayanpbx_ami_secret}"
MANAGER_CONF="${MANAGER_CONF:-/etc/asterisk/manager.conf}"

# Verbose mode (set AMI_VERBOSE=true for debug output)
AMI_VERBOSE="${AMI_VERBOSE:-false}"

# ============================================================================
# Color Definitions (only if terminal supports it)
# ============================================================================
if [ -t 1 ] && [ -z "${NO_COLOR:-}" ]; then
    AMI_RED='\033[0;31m'
    AMI_GREEN='\033[0;32m'
    AMI_YELLOW='\033[1;33m'
    AMI_CYAN='\033[0;36m'
    AMI_BOLD='\033[1m'
    AMI_DIM='\033[2m'
    AMI_NC='\033[0m'
else
    AMI_RED='' AMI_GREEN='' AMI_YELLOW='' AMI_CYAN='' AMI_BOLD='' AMI_DIM='' AMI_NC=''
fi

# ============================================================================
# Output Functions
# ============================================================================
ami_success() { echo -e "${AMI_GREEN}✅ $1${AMI_NC}"; }
ami_error() { echo -e "${AMI_RED}❌ $1${AMI_NC}"; }
ami_info() { echo -e "${AMI_CYAN}ℹ️  $1${AMI_NC}"; }
ami_warn() { echo -e "${AMI_YELLOW}⚠️  $1${AMI_NC}"; }
ami_header() { echo -e "\n${AMI_CYAN}${AMI_BOLD}═══════════════════════════════════════${AMI_NC}\n  $1\n${AMI_CYAN}${AMI_BOLD}═══════════════════════════════════════${AMI_NC}\n"; }

ami_debug() {
    [ "$AMI_VERBOSE" = "true" ] && echo -e "${AMI_DIM}[DEBUG] $1${AMI_NC}"
}

ami_debug_block() {
    if [ "$AMI_VERBOSE" = "true" ]; then
        echo -e "${AMI_DIM}[DEBUG] $1:${AMI_NC}"
        echo -e "${AMI_DIM}────────────────────────────────────${AMI_NC}"
        echo -e "${AMI_DIM}$2${AMI_NC}"
        echo -e "${AMI_DIM}────────────────────────────────────${AMI_NC}"
    fi
}

# ============================================================================
# Utility Functions
# ============================================================================

# Check if a port is listening
ami_is_port_listening() {
    local port=$1
    if command -v ss &> /dev/null; then
        ss -tuln 2>/dev/null | grep -qE ":${port}([[:space:]]|$)"
    elif command -v netstat &> /dev/null; then
        netstat -tuln 2>/dev/null | grep -qE ":${port}([[:space:]]|$)"
    else
        return 1
    fi
}

# Find .env file in common locations
ami_find_env_file() {
    local search_paths=(
        "$SCRIPT_DIR/../.env"
        "$SCRIPT_DIR/../backend/.env"
        "/opt/rayanpbx/.env"
        "/opt/rayanpbx/backend/.env"
        "./.env"
    )
    
    for path in "${search_paths[@]}"; do
        if [ -f "$path" ]; then
            echo "$(cd "$(dirname "$path")" && pwd)/$(basename "$path")"
            return 0
        fi
    done
    
    echo "$SCRIPT_DIR/../.env"
    return 0
}

# ============================================================================
# Credential Extraction Functions
# ============================================================================

# Extract AMI secret from manager.conf for a specific user
extract_ami_secret() {
    local manager_conf="${1:-$MANAGER_CONF}"
    local target_user="${2:-admin}"
    
    [ ! -f "$manager_conf" ] && { echo ""; return 1; }
    
    local in_target_section=false
    local current_section=""
    
    while IFS= read -r line || [[ -n "$line" ]]; do
        line=$(echo "$line" | tr -d '\r' | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')
        [[ -z "$line" || "$line" == ";"* || "$line" == "#"* ]] && continue
        
        if [[ "$line" =~ ^\[([^]]+)\]$ ]]; then
            current_section="${BASH_REMATCH[1]}"
            [ "$current_section" = "$target_user" ] && in_target_section=true || in_target_section=false
            continue
        fi
        
        if [ "$in_target_section" = true ] && [[ "$line" =~ ^secret[[:space:]]*=[[:space:]]*(.+)$ ]]; then
            local secret="${BASH_REMATCH[1]}"
            secret="${secret%%;*}"
            secret="${secret%%#*}"
            echo "$secret" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//'
            return 0
        fi
    done < "$manager_conf"
    
    echo ""
    return 1
}

# Extract AMI username from manager.conf (first non-general section with secret)
extract_ami_username() {
    local manager_conf="${1:-$MANAGER_CONF}"
    
    [ ! -f "$manager_conf" ] && { echo "$DEFAULT_AMI_USERNAME"; return 1; }
    
    local current_section=""
    
    while IFS= read -r line || [[ -n "$line" ]]; do
        line=$(echo "$line" | tr -d '\r' | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')
        [[ -z "$line" || "$line" == ";"* || "$line" == "#"* ]] && continue
        
        if [[ "$line" =~ ^\[([^]]+)\]$ ]]; then
            current_section="${BASH_REMATCH[1]}"
            continue
        fi
        
        [ "$current_section" = "general" ] && continue
        
        if [[ "$line" =~ ^secret[[:space:]]*= ]]; then
            echo "$current_section"
            return 0
        fi
    done < "$manager_conf"
    
    echo "$DEFAULT_AMI_USERNAME"
    return 1
}

# ============================================================================
# AMI Connection Testing
# ============================================================================

# Test AMI connection with authentication
test_ami_connection() {
    local host="${1:-$DEFAULT_AMI_HOST}"
    local port="${2:-$DEFAULT_AMI_PORT}"
    local username="${3:-$DEFAULT_AMI_USERNAME}"
    local secret="${4:-}"
    
    ami_debug "Testing AMI connection: host=$host, port=$port, username=$username"
    
    if ! ami_is_port_listening "$port"; then
        ami_debug "AMI port $port is not listening"
        return 1
    fi
    
    if command -v nc &> /dev/null; then
        local ami_response
        ami_debug "Sending AMI login request"
        ami_response=$(echo -e "Action: Login\r\nUsername: $username\r\nSecret: $secret\r\n\r" | nc -w 3 "$host" "$port" 2>/dev/null | head -20)
        ami_debug_block "AMI Response" "$ami_response"
        
        if echo "$ami_response" | grep -qi "Success"; then
            return 0
        fi
    fi
    
    return 1
}

# ============================================================================
# Service Management
# ============================================================================

check_asterisk_running() {
    if command -v systemctl &> /dev/null; then
        systemctl is-active --quiet asterisk 2>/dev/null
    elif pgrep -x asterisk &> /dev/null; then
        return 0
    else
        return 1
    fi
}

start_asterisk_service() {
    ami_debug "Starting Asterisk service..."
    if command -v systemctl &> /dev/null; then
        systemctl start asterisk 2>/dev/null && sleep 3 && check_asterisk_running && return 0
    fi
    return 1
}

reload_asterisk_manager() {
    ami_debug "Reloading Asterisk manager..."
    if command -v asterisk &> /dev/null; then
        asterisk -rx "manager reload" > /dev/null 2>&1 && sleep 2 && return 0
    fi
    if command -v systemctl &> /dev/null; then
        systemctl reload asterisk 2>/dev/null && sleep 2 && return 0
    fi
    return 1
}

# ============================================================================
# AMI Configuration
# ============================================================================

# Check if AMI is enabled
check_ami_enabled() {
    local manager_conf="${1:-$MANAGER_CONF}"
    [ ! -f "$manager_conf" ] && return 1
    
    local in_general=false
    while IFS= read -r line || [[ -n "$line" ]]; do
        line=$(echo "$line" | tr -d '\r' | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')
        [[ -z "$line" || "$line" == ";"* || "$line" == "#"* ]] && continue
        
        [[ "$line" =~ ^\[general\]$ ]] && { in_general=true; continue; }
        [[ "$line" =~ ^\[.*\]$ ]] && { in_general=false; continue; }
        
        if [ "$in_general" = true ] && [[ "$line" =~ ^enabled[[:space:]]*=[[:space:]]*yes ]]; then
            return 0
        fi
    done < "$manager_conf"
    
    return 1
}

# Configure AMI in manager.conf
# Tries ini-helper.sh with normalization first (preserves custom settings)
# Falls back to clean config if ini-helper fails
configure_ami() {
    local manager_conf="${1:-$MANAGER_CONF}"
    local ami_secret="${2:-$DEFAULT_AMI_SECRET}"
    local ami_username="${3:-$DEFAULT_AMI_USERNAME}"
    
    ami_debug "Configuring AMI: conf=$manager_conf, user=$ami_username"
    
    # Backup existing config
    if [ -f "$manager_conf" ]; then
        cp "$manager_conf" "${manager_conf}.backup.$(date +%Y%m%d_%H%M%S)"
        ami_debug "Backed up existing manager.conf"
    fi
    
    # Try ini-helper.sh first (preserves custom settings)
    if [ -f "$SCRIPT_DIR/ini-helper.sh" ]; then
        ami_debug "Using ini-helper.sh with normalization"
        source "$SCRIPT_DIR/ini-helper.sh"
        
        # Ensure file exists with minimal content
        if [ ! -f "$manager_conf" ]; then
            mkdir -p "$(dirname "$manager_conf")"
            echo "[general]" > "$manager_conf"
        fi
        
        ensure_ini_section "$manager_conf" "general"
        set_ini_value "$manager_conf" "general" "enabled" "yes"
        set_ini_value "$manager_conf" "general" "port" "5038"
        set_ini_value "$manager_conf" "general" "bindaddr" "127.0.0.1"
        
        ensure_ini_section "$manager_conf" "$ami_username"
        set_ini_value "$manager_conf" "$ami_username" "secret" "$ami_secret"
        set_ini_value "$manager_conf" "$ami_username" "deny" "0.0.0.0/0.0.0.0"
        set_ini_value "$manager_conf" "$ami_username" "permit" "127.0.0.1/255.255.255.255"
        set_ini_value "$manager_conf" "$ami_username" "read" "all"
        set_ini_value "$manager_conf" "$ami_username" "write" "all"
        
        # Normalize sections to ensure correct key order
        # Critical: deny must come before permit for ACL to work correctly
        normalize_ini_section "$manager_conf" "general" "enabled port bindaddr"
        normalize_ini_section "$manager_conf" "$ami_username" "secret deny permit read write"
        
        chown asterisk:asterisk "$manager_conf" 2>/dev/null || true
        chmod 640 "$manager_conf" 2>/dev/null || true
        
        ami_debug "Configured via ini-helper with normalization"
        return 0
    fi
    
    # Fallback: create clean manager.conf
    ami_debug "Creating clean manager.conf (ini-helper not available)"
    mkdir -p "$(dirname "$manager_conf")"
    
    cat > "$manager_conf" << EOF
; Asterisk Manager Interface (AMI) Configuration
; Managed by RayanPBX

[general]
enabled = yes
port = 5038
bindaddr = 127.0.0.1

[$ami_username]
secret = $ami_secret
deny = 0.0.0.0/0.0.0.0
permit = 127.0.0.1/255.255.255.255
read = all
write = all
EOF
    
    chown asterisk:asterisk "$manager_conf" 2>/dev/null || true
    chmod 640 "$manager_conf" 2>/dev/null || true
    
    ami_debug "Created clean manager.conf"
    return 0
}

# ============================================================================
# .env File Management
# ============================================================================

update_env_ami_credentials() {
    local env_file="$1"
    local ami_host="${2:-$DEFAULT_AMI_HOST}"
    local ami_port="${3:-$DEFAULT_AMI_PORT}"
    local ami_username="${4:-$DEFAULT_AMI_USERNAME}"
    local ami_secret="${5:-}"
    
    ami_debug "Updating AMI credentials in: $env_file"
    
    if [ ! -f "$env_file" ]; then
        mkdir -p "$(dirname "$env_file")"
        cat > "$env_file" << EOF
# RayanPBX Configuration
ASTERISK_AMI_HOST=$ami_host
ASTERISK_AMI_PORT=$ami_port
ASTERISK_AMI_USERNAME=$ami_username
ASTERISK_AMI_SECRET=$ami_secret
EOF
        return 0
    fi
    
    cp "$env_file" "${env_file}.backup.$(date +%Y%m%d_%H%M%S)"
    
    for kv in "ASTERISK_AMI_HOST=$ami_host" "ASTERISK_AMI_PORT=$ami_port" "ASTERISK_AMI_USERNAME=$ami_username" "ASTERISK_AMI_SECRET=$ami_secret"; do
        local key="${kv%%=*}"
        local value="${kv#*=}"
        if grep -q "^${key}=" "$env_file"; then
            sed -i "s|^${key}=.*|${key}=${value}|" "$env_file"
        else
            echo "${key}=${value}" >> "$env_file"
        fi
    done
    
    return 0
}

# ============================================================================
# Comprehensive AMI Check and Fix
# ============================================================================

ami_check_and_fix() {
    local host="${1:-$DEFAULT_AMI_HOST}"
    local port="${2:-$DEFAULT_AMI_PORT}"
    local username="${3:-$DEFAULT_AMI_USERNAME}"
    local secret="${4:-$DEFAULT_AMI_SECRET}"
    local auto_fix="${5:-true}"
    local manager_conf="${6:-$MANAGER_CONF}"
    
    ami_debug "AMI check: host=$host, port=$port, user=$username, auto_fix=$auto_fix"
    
    # Check Asterisk running
    if ! check_asterisk_running; then
        ami_debug "Asterisk not running"
        [ "$auto_fix" = "true" ] && start_asterisk_service || return 2
    fi
    
    # Check AMI enabled
    if ! check_ami_enabled "$manager_conf"; then
        ami_debug "AMI not enabled"
        if [ "$auto_fix" = "true" ]; then
            configure_ami "$manager_conf" "$secret" "$username"
            reload_asterisk_manager
        else
            return 2
        fi
    fi
    
    # Check port listening
    if ! ami_is_port_listening "$port"; then
        ami_debug "AMI port not listening"
        [ "$auto_fix" = "true" ] && reload_asterisk_manager
        sleep 2
        ami_is_port_listening "$port" || return 2
    fi
    
    # Test connection
    if test_ami_connection "$host" "$port" "$username" "$secret"; then
        return 0
    fi
    
    # Try to fix
    if [ "$auto_fix" = "true" ]; then
        ami_debug "Reconfiguring AMI..."
        configure_ami "$manager_conf" "$secret" "$username"
        systemctl restart asterisk 2>/dev/null && sleep 3
        test_ami_connection "$host" "$port" "$username" "$secret" && return 1
    fi
    
    return 2
}

# ============================================================================
# CLI Commands
# ============================================================================

cmd_fix() {
    local auto_reload="true"
    local manager_conf="$MANAGER_CONF"
    
    # Parse options
    while [[ $# -gt 0 ]]; do
        case "$1" in
            --no-reload) auto_reload="false"; shift ;;
            --manager-conf) manager_conf="$2"; shift 2 ;;
            *) shift ;;
        esac
    done
    
    ami_header "🔧 AMI Credential Fix"
    
    if [ ! -f "$manager_conf" ]; then
        ami_error "manager.conf not found at $manager_conf"
        return 1
    fi
    ami_success "Found manager.conf at $manager_conf"
    
    ami_info "Extracting AMI credentials..."
    local ami_username ami_secret
    ami_username=$(extract_ami_username "$manager_conf")
    ami_secret=$(extract_ami_secret "$manager_conf" "$ami_username")
    
    if [ -z "$ami_secret" ]; then
        ami_error "No AMI secret found in manager.conf"
        ami_info "Run: ./ami-tools.sh configure --secret <secret>"
        return 1
    fi
    
    ami_success "Username: $ami_username"
    ami_success "Secret: ${ami_secret:0:4}****"
    
    # Update .env
    ami_info "Updating .env file..."
    local env_file
    env_file=$(ami_find_env_file)
    update_env_ami_credentials "$env_file" "$DEFAULT_AMI_HOST" "$DEFAULT_AMI_PORT" "$ami_username" "$ami_secret"
    ami_success ".env updated: $env_file"
    
    # Reload
    [ "$auto_reload" = "true" ] && { ami_info "Reloading Asterisk..."; reload_asterisk_manager && ami_success "Reloaded"; }
    
    # Test
    ami_header "🔌 Testing AMI Connection"
    if test_ami_connection "$DEFAULT_AMI_HOST" "$DEFAULT_AMI_PORT" "$ami_username" "$ami_secret"; then
        ami_success "AMI connection successful!"
        ami_header "✅ Complete"
        echo -e "  ${AMI_CYAN}Host:${AMI_NC}     $DEFAULT_AMI_HOST"
        echo -e "  ${AMI_CYAN}Port:${AMI_NC}     $DEFAULT_AMI_PORT"
        echo -e "  ${AMI_CYAN}Username:${AMI_NC} $ami_username"
        echo -e "  ${AMI_CYAN}Secret:${AMI_NC}   ${ami_secret:0:4}****"
        return 0
    fi
    
    ami_warn "Connection failed, running auto-fix..."
    local result=0
    ami_check_and_fix "$DEFAULT_AMI_HOST" "$DEFAULT_AMI_PORT" "$ami_username" "$ami_secret" "true" "$manager_conf" || result=$?
    
    [ $result -le 1 ] && { ami_success "AMI fixed!"; return 0; }
    
    ami_error "Could not fix AMI connection"
    ami_info "Check: journalctl -u asterisk -n 50"
    return 1
}

cmd_check() {
    ami_header "🔍 AMI Health Check"
    
    local manager_conf="${1:-$MANAGER_CONF}"
    
    # Check manager.conf
    if [ ! -f "$manager_conf" ]; then
        ami_error "manager.conf not found"
        return 1
    fi
    ami_success "manager.conf exists"
    
    # Check Asterisk
    if check_asterisk_running; then
        ami_success "Asterisk is running"
    else
        ami_error "Asterisk is NOT running"
        return 1
    fi
    
    # Check AMI enabled
    if check_ami_enabled "$manager_conf"; then
        ami_success "AMI is enabled"
    else
        ami_error "AMI is NOT enabled"
        return 1
    fi
    
    # Check port
    if ami_is_port_listening "$DEFAULT_AMI_PORT"; then
        ami_success "Port $DEFAULT_AMI_PORT is listening"
    else
        ami_error "Port $DEFAULT_AMI_PORT is NOT listening"
        return 1
    fi
    
    # Check credentials
    local username secret
    username=$(extract_ami_username "$manager_conf")
    secret=$(extract_ami_secret "$manager_conf" "$username")
    
    if [ -n "$secret" ]; then
        ami_success "Credentials configured (user: $username)"
        
        if test_ami_connection "$DEFAULT_AMI_HOST" "$DEFAULT_AMI_PORT" "$username" "$secret"; then
            ami_success "AMI authentication works"
        else
            ami_error "AMI authentication FAILED"
            return 1
        fi
    else
        ami_warn "No credentials found"
    fi
    
    ami_header "✅ All Checks Passed"
    return 0
}

cmd_test() {
    local host="$DEFAULT_AMI_HOST"
    local port="$DEFAULT_AMI_PORT"
    local username="$DEFAULT_AMI_USERNAME"
    local secret=""
    
    # Parse args or load from .env
    if [ $# -ge 4 ]; then
        host="$1"; port="$2"; username="$3"; secret="$4"
    else
        local env_file
        env_file=$(ami_find_env_file)
        if [ -f "$env_file" ]; then
            host=$(grep "^ASTERISK_AMI_HOST=" "$env_file" 2>/dev/null | cut -d'=' -f2- | tr -d '"' || echo "$host")
            port=$(grep "^ASTERISK_AMI_PORT=" "$env_file" 2>/dev/null | cut -d'=' -f2- | tr -d '"' || echo "$port")
            username=$(grep "^ASTERISK_AMI_USERNAME=" "$env_file" 2>/dev/null | cut -d'=' -f2- | tr -d '"' || echo "$username")
            secret=$(grep "^ASTERISK_AMI_SECRET=" "$env_file" 2>/dev/null | cut -d'=' -f2- | tr -d '"' || echo "")
        fi
    fi
    
    ami_info "Testing AMI connection to $host:$port as $username..."
    
    if test_ami_connection "$host" "$port" "$username" "$secret"; then
        ami_success "AMI connection successful"
        return 0
    else
        ami_error "AMI connection failed"
        return 1
    fi
}

cmd_diag() {
    ami_header "🔬 AMI Full Diagnostics"
    
    echo -e "${AMI_CYAN}System:${AMI_NC}"
    echo "  Hostname: $(hostname)"
    echo "  Date: $(date)"
    echo ""
    
    echo -e "${AMI_CYAN}Asterisk:${AMI_NC}"
    if check_asterisk_running; then
        echo "  Status: Running"
        echo "  Version: $(asterisk -V 2>/dev/null || echo 'unknown')"
    else
        echo "  Status: NOT Running"
    fi
    echo ""
    
    echo -e "${AMI_CYAN}AMI Configuration:${AMI_NC}"
    local manager_conf="$MANAGER_CONF"
    if [ -f "$manager_conf" ]; then
        echo "  Config: $manager_conf"
        echo "  Enabled: $(check_ami_enabled "$manager_conf" && echo 'yes' || echo 'no')"
        local username=$(extract_ami_username "$manager_conf")
        echo "  Username: $username"
        echo "  Secret: $(extract_ami_secret "$manager_conf" "$username" | head -c4)****"
    else
        echo "  Config: NOT FOUND"
    fi
    echo ""
    
    echo -e "${AMI_CYAN}Network:${AMI_NC}"
    echo "  Port $DEFAULT_AMI_PORT: $(ami_is_port_listening "$DEFAULT_AMI_PORT" && echo 'listening' || echo 'NOT listening')"
    if command -v ss &> /dev/null; then
        ss -tuln 2>/dev/null | grep ":$DEFAULT_AMI_PORT" | head -1 | sed 's/^/  /'
    fi
    echo ""
    
    echo -e "${AMI_CYAN}.env File:${AMI_NC}"
    local env_file=$(ami_find_env_file)
    if [ -f "$env_file" ]; then
        echo "  Path: $env_file"
        grep "ASTERISK_AMI" "$env_file" 2>/dev/null | sed 's/SECRET=.*/SECRET=****/' | sed 's/^/  /'
    else
        echo "  Path: NOT FOUND"
    fi
    echo ""
    
    # Connection test
    echo -e "${AMI_CYAN}Connection Test:${AMI_NC}"
    cmd_test > /dev/null 2>&1 && echo "  Result: SUCCESS" || echo "  Result: FAILED"
    
    return 0
}

cmd_configure() {
    local secret="$DEFAULT_AMI_SECRET"
    local username="$DEFAULT_AMI_USERNAME"
    local manager_conf="$MANAGER_CONF"
    
    while [[ $# -gt 0 ]]; do
        case "$1" in
            --secret) secret="$2"; shift 2 ;;
            --username) username="$2"; shift 2 ;;
            --manager-conf) manager_conf="$2"; shift 2 ;;
            *) shift ;;
        esac
    done
    
    ami_header "⚙️  Configure AMI"
    ami_info "Setting up AMI configuration..."
    ami_info "Username: $username"
    ami_info "Secret: ${secret:0:4}****"
    
    if configure_ami "$manager_conf" "$secret" "$username"; then
        ami_success "AMI configuration written to $manager_conf"
        
        ami_info "Restarting Asterisk..."
        if systemctl restart asterisk 2>/dev/null; then
            sleep 3
            if test_ami_connection "$DEFAULT_AMI_HOST" "$DEFAULT_AMI_PORT" "$username" "$secret"; then
                ami_success "AMI is working!"
                return 0
            fi
        fi
        ami_warn "Asterisk restart had issues"
    else
        ami_error "Failed to write configuration"
        return 1
    fi
}

ami_show_help() {
    cat << EOF
${AMI_CYAN}${AMI_BOLD}AMI Tools${AMI_NC} - Asterisk Manager Interface Configuration & Diagnostics
Version: $AMI_TOOLS_VERSION

${AMI_YELLOW}Usage:${AMI_NC}
  $0 <command> [options]

${AMI_YELLOW}Commands:${AMI_NC}
  fix                  Extract credentials from manager.conf, update .env, test
  check                Check AMI health and configuration
  test [h] [p] [u] [s] Test AMI connection (or use .env credentials)
  diag                 Run full diagnostics
  configure            Configure AMI with new credentials

${AMI_YELLOW}Options:${AMI_NC}
  --verbose            Enable debug output
  --no-reload          Don't reload Asterisk (for fix command)
  --secret <secret>    AMI secret (for configure command)
  --username <user>    AMI username (default: admin)
  --manager-conf <p>   Path to manager.conf

${AMI_YELLOW}Examples:${AMI_NC}
  $0 fix                           # Fix and sync credentials
  $0 check                         # Health check
  $0 test                          # Test with .env credentials
  $0 diag --verbose                # Full diagnostics with debug
  $0 configure --secret mysecret   # Configure AMI

${AMI_YELLOW}Sourcing:${AMI_NC}
  source $0
  test_ami_connection "127.0.0.1" "5038" "admin" "secret"
  configure_ami "/etc/asterisk/manager.conf" "secret" "admin"

EOF
}

# ============================================================================
# Main Entry Point
# ============================================================================

main() {
    local command="${1:-}"
    
    # Handle global flags
    case "$command" in
        --version|-v) echo "AMI Tools v$AMI_TOOLS_VERSION"; exit 0 ;;
        --verbose) AMI_VERBOSE=true; shift; command="${1:-}" ;;
        --help|-h|"") ami_show_help; exit 0 ;;
    esac
    
    shift || true
    
    # Check for verbose in remaining args
    for arg in "$@"; do
        [ "$arg" = "--verbose" ] && AMI_VERBOSE=true
    done
    
    case "$command" in
        fix) cmd_fix "$@" ;;
        check) cmd_check "$@" ;;
        test) cmd_test "$@" ;;
        diag|diagnostics) cmd_diag "$@" ;;
        configure) cmd_configure "$@" ;;
        *) ami_error "Unknown command: $command"; ami_show_help; exit 1 ;;
    esac
}

# Run main only if executed directly (not sourced)
if [ "${BASH_SOURCE[0]}" = "${0}" ]; then
    main "$@"
fi
