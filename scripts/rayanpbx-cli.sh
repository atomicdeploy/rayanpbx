#!/bin/bash

# RayanPBX CLI Tool
# Comprehensive command-line interface for RayanPBX management

set -euo pipefail

# Version - read from VERSION file
VERSION="2.0.0"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
VERSION_FILE="$SCRIPT_DIR/../VERSION"
if [ -f "$VERSION_FILE" ]; then
    VERSION=$(cat "$VERSION_FILE" | tr -d '[:space:]')
fi

# Source ini-helper for backup functionality
if [ -f "$SCRIPT_DIR/ini-helper.sh" ]; then
    source "$SCRIPT_DIR/ini-helper.sh"
fi

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
MAGENTA='\033[0;35m'
NC='\033[0m' # No Color

# VT-100 Styles
BOLD='\033[1m'
UNDERLINE='\033[4m'
DIM='\033[2m'
RESET='\033[0m'

# Emojis
CHECK="✅"
CROSS="❌"
INFO="ℹ️ "
WARN="⚠️ "
ROCKET="🚀"

# Helper functions (defined early so they can be used during config loading)
print_success() {
    echo -e "${GREEN}${CHECK} $1${NC}"
}

print_error() {
    echo -e "${RED}${CROSS} $1${NC}"
}

print_info() {
    echo -e "${CYAN}${INFO}$1${NC}"
}

print_warn() {
    echo -e "${YELLOW}${WARN}$1${NC}"
}

print_verbose() {
    if [ "${VERBOSE:-false}" = true ]; then
        echo -e "${DIM}[VERBOSE] $1${RESET}"
    fi
}

print_header() {
    echo -e "${MAGENTA}═══════════════════════════════════════${NC}"
    echo -e "${CYAN}  $1${NC}"
    echo -e "${MAGENTA}═══════════════════════════════════════${NC}"
}

# Banner display function
print_banner() {
    # Check if banner display is enabled in .env
    local use_figlet="${CLI_USE_FIGLET:-true}"
    local use_lolcat="${CLI_USE_LOLCAT:-false}"
    
    if [ "$use_figlet" != "true" ]; then
        return
    fi
    
    # Try figlet first, then toilet as fallback
    if command -v figlet &> /dev/null; then
        # Try slant font first
        if figlet -f slant "RayanPBX" > /dev/null 2>&1; then
            if [ "$use_lolcat" = "true" ] && command -v lolcat &> /dev/null; then
                figlet -f slant "RayanPBX" | lolcat
            else
                echo -e "${CYAN}$(figlet -f slant "RayanPBX")${NC}"
            fi
        else
            # Try default font
            if figlet "RayanPBX" > /dev/null 2>&1; then
                if [ "$use_lolcat" = "true" ] && command -v lolcat &> /dev/null; then
                    figlet "RayanPBX" | lolcat
                else
                    echo -e "${CYAN}$(figlet "RayanPBX")${NC}"
                fi
            fi
        fi
    elif command -v toilet &> /dev/null; then
        # Use toilet as fallback
        if [ "$use_lolcat" = "true" ] && command -v lolcat &> /dev/null; then
            toilet -f slant "RayanPBX" | lolcat
        else
            echo -e "${CYAN}$(toilet -f slant "RayanPBX")${NC}"
        fi
    fi
    
    # Subtitle
    echo -e "${YELLOW}    ${ROCKET} Modern SIP Server Management CLI ${ROCKET}${NC} ${GREEN}v${VERSION}${NC}"
    echo -e "${DIM}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}\n"
}

# Configuration
RAYANPBX_ROOT="${RAYANPBX_ROOT:-/opt/rayanpbx}"
API_BASE_URL="http://localhost:8000/api"
VERBOSE=false

# Helper function to find project root by looking for VERSION file
find_project_root() {
    local current_dir="$(pwd)"
    local max_depth=3
    
    for ((i=0; i<max_depth; i++)); do
        if [ -f "$current_dir/VERSION" ] || [ -f "$current_dir/.env" ]; then
            echo "$current_dir"
            return
        fi
        
        local parent_dir="$(dirname "$current_dir")"
        if [ "$parent_dir" = "$current_dir" ]; then
            break
        fi
        current_dir="$parent_dir"
    done
    
    # Return current directory if not found
    pwd
}

# Load configuration from multiple .env file paths in priority order
# Later paths override earlier ones:
# 1. /opt/rayanpbx/.env
# 2. /usr/local/rayanpbx/.env
# 3. /etc/rayanpbx/.env
# 4. <root of the project>/.env (found by looking for VERSION file)
# 5. <current working directory>/.env
load_env_files() {
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
    local current_dir
    current_dir=$(pwd)
    env_paths+=("$current_dir/.env")
    
    # Track loaded paths to avoid duplicates
    declare -A loaded_paths
    
    # Load each .env file in order
    for env_file in "${env_paths[@]}"; do
        # Skip if already loaded
        if [ -n "${loaded_paths[$env_file]:-}" ]; then
            continue
        fi
        
        # Load file if it exists
        if [ -f "$env_file" ]; then
            set +u
            # shellcheck source=/dev/null
            source "$env_file" 2>/dev/null
            set -u
            loaded_paths[$env_file]=1
            print_verbose "Loaded .env from: $env_file"
        fi
    done
    
    # Handle normalization for the primary config file (for backward compatibility)
    local primary_env="/opt/rayanpbx/.env"
    if [ -f "$primary_env" ] && [[ -n "${VITE_WS_URL:-}" ]] && [[ "$VITE_WS_URL" == *"ws://localhost:"* ]] && [[ "$VITE_WS_URL" != *":${WEBSOCKET_PORT}"* ]] && [[ "$VITE_WS_URL" != *":[0-9]*"* ]]; then
        print_warn ".env file has variable ordering issues. Auto-fixing..."
        
        if [ -f "$SCRIPT_DIR/normalize-env.sh" ]; then
            bash "$SCRIPT_DIR/normalize-env.sh" "$primary_env" > /dev/null 2>&1
            print_success ".env file normalized. Variables now properly ordered."
            # Re-source the normalized file
            unset VITE_WS_URL WEBSOCKET_PORT
            source "$primary_env" 2>/dev/null
        fi
    fi
}

# Load all .env files
load_env_files

# Set defaults after loading
API_BASE_URL="${API_BASE_URL:-http://localhost:8000/api}"

# For backward compatibility, maintain ENV_FILE variable pointing to primary config
ENV_FILE="$RAYANPBX_ROOT/.env"

# API call helper
api_call() {
    local method=$1
    local endpoint=$2
    local data=${3:-}
    
    print_verbose "API Call: $method $API_BASE_URL/$endpoint"
    if [ -n "$data" ]; then
        print_verbose "Request body: $data"
    fi
    
    local response
    if [ -n "$data" ]; then
        response=$(curl -s -X "$method" "$API_BASE_URL/$endpoint" \
            -H "Content-Type: application/json" \
            -d "$data")
    else
        response=$(curl -s -X "$method" "$API_BASE_URL/$endpoint")
    fi
    
    print_verbose "Response: $response"
    echo "$response"
}

# Extension commands
cmd_extension_list() {
    print_header "📱 Extensions List"
    
    response=$(api_call GET "extensions")
    
    if command -v jq &> /dev/null; then
        echo "$response" | jq -r '.extensions[] | "\(.extension_number)\t\(.name)\t\(.enabled)"' | \
            while IFS=$'\t' read -r num name enabled; do
                if [ "$enabled" == "true" ]; then
                    echo -e "  ${GREEN}●${NC} $num - $name"
                else
                    echo -e "  ${RED}●${NC} $num - $name (disabled)"
                fi
            done
    else
        echo "$response"
    fi
    
    print_success "Done"
}

cmd_extension_create() {
    local number=$1
    local name=$2
    local password=$3
    
    print_info "Creating extension $number..."
    
    data=$(cat <<EOF
{
    "extension_number": "$number",
    "name": "$name",
    "secret": "$password",
    "context": "from-internal",
    "enabled": true
}
EOF
)
    
    response=$(api_call POST "extensions" "$data")
    
    if echo "$response" | grep -q "success\|created"; then
        print_success "Extension $number created successfully"
    else
        print_error "Failed to create extension: $response"
        exit 1
    fi
}

cmd_extension_status() {
    local number=$1
    
    print_header "📊 Extension $number Status"
    
    response=$(api_call GET "asterisk/endpoint/status?extension=$number")
    
    if command -v jq &> /dev/null; then
        registered=$(echo "$response" | jq -r '.registered')
        ip=$(echo "$response" | jq -r '.ip_address')
        user_agent=$(echo "$response" | jq -r '.user_agent')
        
        if [ "$registered" == "true" ]; then
            print_success "Registered"
            echo -e "  IP: $ip"
            echo -e "  User-Agent: $user_agent"
        else
            print_warn "Not registered"
        fi
    else
        echo "$response"
    fi
}

# Trunk commands
cmd_trunk_list() {
    print_header "🔗 Trunks List"
    
    response=$(api_call GET "trunks")
    
    if command -v jq &> /dev/null; then
        echo "$response" | jq -r '.[] | "\(.name)\t\(.host):\(.port)\t\(.enabled)"' | \
            while IFS=$'\t' read -r name host enabled; do
                if [ "$enabled" == "true" ]; then
                    echo -e "  ${GREEN}●${NC} $name - $host"
                else
                    echo -e "  ${RED}●${NC} $name - $host (disabled)"
                fi
            done
    else
        echo "$response"
    fi
}

cmd_trunk_test() {
    local name=$1
    
    print_header "🔍 Testing Trunk: $name"
    
    response=$(api_call GET "validate/trunk/$name")
    
    if command -v jq &> /dev/null; then
        reachable=$(echo "$response" | jq -r '.reachable')
        latency=$(echo "$response" | jq -r '.latency_ms')
        
        if [ "$reachable" == "true" ]; then
            print_success "Trunk is reachable"
            echo -e "  Latency: ${latency}ms"
        else
            print_error "Trunk is unreachable"
        fi
    else
        echo "$response"
    fi
}

# Asterisk commands
cmd_asterisk_status() {
    print_header "⚙️  Asterisk Service Status"
    
    if systemctl is-active --quiet asterisk; then
        print_success "Asterisk is running"
    else
        print_error "Asterisk is not running"
        exit 3
    fi
}

cmd_asterisk_restart() {
    print_info "Restarting Asterisk service..."
    sudo systemctl restart asterisk
    sleep 2
    
    if systemctl is-active --quiet asterisk; then
        print_success "Asterisk restarted successfully"
    else
        print_error "Failed to restart Asterisk"
        exit 3
    fi
}

cmd_asterisk_command() {
    local command=$1
    
    print_header "🖥️  Executing: $command"
    
    sudo asterisk -rx "$command"
}

# Diagnostics commands
cmd_diag_test_extension() {
    local number=$1
    
    print_header "🔍 Testing Extension: $number"
    
    # Check registration
    output=$(sudo asterisk -rx "pjsip show endpoint $number")
    
    if echo "$output" | grep -q "Unavailable\|Not found"; then
        print_error "Extension is not registered"
        exit 1
    else
        print_success "Extension is registered"
        echo "$output" | grep -E "Contact:|Status:"
    fi
}

cmd_diag_health_check() {
    print_header "🏥 System Health Check"
    
    # Check Asterisk
    echo -n "Asterisk Service: "
    if systemctl is-active --quiet asterisk; then
        print_success "Running"
    else
        print_error "Stopped"
    fi
    
    # Check database
    echo -n "Database: "
    if mysql -u root -e "USE rayanpbx;" 2>/dev/null; then
        print_success "Connected"
    else
        print_warn "Cannot connect"
    fi
    
    # Check API
    echo -n "API Server: "
    if curl -s -o /dev/null -w "%{http_code}" "http://localhost:8000" | grep -q "200\|302"; then
        print_success "Running"
    else
        print_warn "Not responding"
    fi
    
    print_success "Health check complete"
}

# System commands
cmd_system_update() {
    print_header "${ROCKET} Updating RayanPBX"
    
    if [ ! -d "$RAYANPBX_ROOT/.git" ]; then
        print_error "Not a git repository"
        exit 1
    fi
    
    cd "$RAYANPBX_ROOT"
    
    print_info "Pulling latest changes..."
    git pull origin main
    
    print_info "Updating dependencies..."
    cd backend && composer install --no-dev
    cd ../frontend && npm install
    cd ../tui && go mod download
    
    print_success "Update complete!"
    print_warn "Restart services to apply changes"
}

# Config commands for .env file manipulation
cmd_config_get() {
    local key=$1
    
    if [ -z "$key" ]; then
        print_error "Key parameter required"
        echo "Usage: rayanpbx-cli config get <KEY>"
        exit 2
    fi
    
    if [ ! -f "$ENV_FILE" ]; then
        print_error "Configuration file not found: $ENV_FILE"
        exit 4
    fi
    
    # Get value from .env file
    local value
    value=$(grep "^${key}=" "$ENV_FILE" | cut -d'=' -f2- | sed 's/^["'\'']\(.*\)["'\'']$/\1/')
    
    if [ -z "$value" ]; then
        print_warn "Key '$key' not found in configuration"
        exit 1
    fi
    
    echo "$value"
}

cmd_config_set() {
    local key=$1
    local value=$2
    
    if [ -z "$key" ] || [ -z "$value" ]; then
        print_error "Both key and value parameters required"
        echo "Usage: rayanpbx-cli config set <KEY> <VALUE>"
        exit 2
    fi
    
    if [ ! -f "$ENV_FILE" ]; then
        print_error "Configuration file not found: $ENV_FILE"
        exit 4
    fi
    
    # Backup config file using helper from ini-helper.sh
    local backup
    backup=$(backup_config "$ENV_FILE")
    if [ -n "$backup" ]; then
        print_verbose "Backup: $backup"
    fi
    
    # Escape special characters in value for sed
    local escaped_value
    escaped_value=$(printf '%s\n' "$value" | sed 's:[\/&]:\\&:g')
    
    # Check if key exists
    if grep -q "^${key}=" "$ENV_FILE"; then
        # Update existing key (using @ as delimiter to avoid conflicts with /)
        sed -i "s@^${key}=.*@${key}=${escaped_value}@" "$ENV_FILE"
        print_success "Updated ${key}=${value}"
    else
        # Add new key
        echo "${key}=${value}" >> "$ENV_FILE"
        print_success "Added ${key}=${value}"
    fi
}

cmd_config_list() {
    if [ ! -f "$ENV_FILE" ]; then
        print_error "Configuration file not found: $ENV_FILE"
        exit 4
    fi
    
    print_header "⚙️  Configuration"
    
    # Display non-empty, non-comment lines
    grep -v "^#" "$ENV_FILE" | grep -v "^$" | while IFS= read -r line; do
        # Extract key and value (handle multiple equals signs)
        local key="${line%%=*}"
        local value="${line#*=}"
        
        # Mask sensitive values (more specific patterns)
        if echo "$key" | grep -qiE "password|secret|private_key|api_key|token"; then
            echo -e "  ${CYAN}${key}${NC}=${DIM}********${NC}"
        else
            echo -e "  ${CYAN}${key}${NC}=${GREEN}${value}${NC}"
        fi
    done
}

# TUI launcher
cmd_tui() {
    local tui_path="$RAYANPBX_ROOT/tui/rayanpbx-tui"
    
    if [ ! -x "$tui_path" ]; then
        print_error "TUI binary not found or not executable: $tui_path"
        print_info "Build it with: cd $RAYANPBX_ROOT/tui && go build"
        exit 1
    fi
    
    exec "$tui_path" "$@"
}

# Main command dispatcher
cmd_version() {
    echo -e "${CYAN}${BOLD}RayanPBX CLI${RESET} ${GREEN}v${VERSION}${RESET}"
    echo -e "${DIM}Modern SIP Server Management Toolkit${RESET}"
}

cmd_help() {
    local command=${1:-}
    
    print_banner
    
    if [ -z "$command" ]; then
        # General help
        echo -e "${CYAN}${BOLD}RayanPBX CLI - Command Reference${NC}"
        echo -e "${DIM}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}\n"
        
        echo -e "${MAGENTA}${BOLD}USAGE:${NC}"
        echo -e "  ${YELLOW}rayanpbx-cli${NC} ${GREEN}<command>${NC} ${BLUE}[subcommand]${NC} ${DIM}[options]${NC}\n"
        
        echo -e "${MAGENTA}${BOLD}COMMANDS:${NC}\n"
        
        echo -e "${CYAN}📱 extension${NC} ${DIM}- Extension management${NC}"
        echo -e "   ${GREEN}list${NC}                              List all extensions"
        echo -e "   ${GREEN}create${NC} <number> <name> <password>  Create new extension"
        echo -e "   ${GREEN}status${NC} <number>                    Check extension status"
        echo ""
        
        echo -e "${CYAN}🔗 trunk${NC} ${DIM}- Trunk management${NC}"
        echo -e "   ${GREEN}list${NC}                              List all trunks"
        echo -e "   ${GREEN}test${NC} <name>                       Test trunk connectivity"
        echo ""
        
        echo -e "${CYAN}⚙️  asterisk${NC} ${DIM}- Asterisk service management${NC}"
        echo -e "   ${GREEN}status${NC}                            Check Asterisk status"
        echo -e "   ${GREEN}restart${NC}                           Restart Asterisk service"
        echo -e "   ${GREEN}command${NC} <cli_command>             Execute Asterisk CLI command"
        echo ""
        
        echo -e "${CYAN}🔍 diag${NC} ${DIM}- Diagnostics and troubleshooting${NC}"
        echo -e "   ${GREEN}test-extension${NC} <number>           Test extension registration"
        echo -e "   ${GREEN}health-check${NC}                      Run system health check"
        echo ""
        
        echo -e "${CYAN}⚙️  config${NC} ${DIM}- Configuration management${NC}"
        echo -e "   ${GREEN}get${NC} <KEY>                         Get configuration value"
        echo -e "   ${GREEN}set${NC} <KEY> <VALUE>                 Set configuration value"
        echo -e "   ${GREEN}list${NC}                              List all configuration"
        echo ""
        
        echo -e "${CYAN}🖥️  system${NC} ${DIM}- System operations${NC}"
        echo -e "   ${GREEN}update${NC}                            Update RayanPBX from repository"
        echo ""
        
        echo -e "${CYAN}🎨 tui${NC} ${DIM}- Launch Terminal UI${NC}"
        echo -e "   ${GREEN}tui${NC}                               Launch interactive TUI interface"
        echo ""
        
        echo -e "${CYAN}❓ help${NC} ${DIM}- Help and documentation${NC}"
        echo -e "   ${GREEN}help${NC}                              Show this help message"
        echo -e "   ${GREEN}help${NC} <command>                    Show detailed help for command"
        echo -e "   ${GREEN}version${NC}, ${GREEN}--version${NC}, ${GREEN}-v${NC}          Show version information"
        echo ""
        
        echo -e "${MAGENTA}${BOLD}EXAMPLES:${NC}"
        echo -e "  ${DIM}# List all extensions${NC}"
        echo -e "  ${YELLOW}rayanpbx-cli extension list${NC}\n"
        echo -e "  ${DIM}# Create a new extension${NC}"
        echo -e "  ${YELLOW}rayanpbx-cli extension create 100 \"John Doe\" secret123${NC}\n"
        echo -e "  ${DIM}# Get a configuration value${NC}"
        echo -e "  ${YELLOW}rayanpbx-cli config get DB_HOST${NC}\n"
        echo -e "  ${DIM}# Set a configuration value${NC}"
        echo -e "  ${YELLOW}rayanpbx-cli config set ASTERISK_AMI_PORT 5038${NC}\n"
        echo -e "  ${DIM}# Launch TUI interface${NC}"
        echo -e "  ${YELLOW}rayanpbx-cli tui${NC}\n"
        
        echo -e "${MAGENTA}${BOLD}EXIT CODES:${NC}"
        echo -e "  ${GREEN}0${NC}  Success"
        echo -e "  ${YELLOW}1${NC}  General error"
        echo -e "  ${YELLOW}2${NC}  Invalid arguments"
        echo -e "  ${YELLOW}3${NC}  Service/connection error"
        echo -e "  ${YELLOW}4${NC}  Configuration error"
        echo ""
        
        echo -e "${DIM}For detailed command help: ${YELLOW}rayanpbx-cli help <command>${NC}"
        echo -e "${DIM}Configuration file: ${CYAN}$ENV_FILE${NC}"
        echo ""
    else
        # Command-specific help
        case "$command" in
            extension)
                echo -e "${CYAN}${BOLD}Extension Management${NC}"
                echo -e "${DIM}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}\n"
                echo -e "Manage SIP extensions for your PBX system.\n"
                echo -e "${YELLOW}rayanpbx-cli extension list${NC}"
                echo -e "  Lists all configured extensions with their status.\n"
                echo -e "${YELLOW}rayanpbx-cli extension create <number> <name> <password>${NC}"
                echo -e "  Creates a new SIP extension."
                echo -e "  ${DIM}Example: rayanpbx-cli extension create 100 \"John Doe\" secret123${NC}\n"
                echo -e "${YELLOW}rayanpbx-cli extension status <number>${NC}"
                echo -e "  Checks registration status of an extension."
                echo -e "  ${DIM}Example: rayanpbx-cli extension status 100${NC}"
                echo ""
                ;;
            config)
                echo -e "${CYAN}${BOLD}Configuration Management${NC}"
                echo -e "${DIM}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}\n"
                echo -e "Manage RayanPBX configuration stored in .env file.\n"
                echo -e "${YELLOW}rayanpbx-cli config get <KEY>${NC}"
                echo -e "  Retrieves the value of a configuration key."
                echo -e "  ${DIM}Example: rayanpbx-cli config get DB_HOST${NC}\n"
                echo -e "${YELLOW}rayanpbx-cli config set <KEY> <VALUE>${NC}"
                echo -e "  Sets or updates a configuration key-value pair."
                echo -e "  ${DIM}Example: rayanpbx-cli config set ASTERISK_AMI_PORT 5038${NC}\n"
                echo -e "${YELLOW}rayanpbx-cli config list${NC}"
                echo -e "  Lists all configuration key-value pairs."
                echo -e "  ${DIM}Note: Sensitive values (passwords, secrets) are masked.${NC}"
                echo ""
                ;;
            tui)
                echo -e "${CYAN}${BOLD}Terminal UI${NC}"
                echo -e "${DIM}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}\n"
                echo -e "Launch the interactive Terminal User Interface.\n"
                echo -e "${YELLOW}rayanpbx-cli tui${NC}"
                echo -e "  Starts the beautiful TUI interface with menu-driven navigation."
                echo -e "  The TUI provides a more user-friendly way to manage your PBX."
                echo ""
                ;;
            *)
                print_warn "No detailed help available for: $command"
                echo "Run 'rayanpbx-cli help' for general help"
                exit 2
                ;;
        esac
    fi
}

main() {
    # Parse global flags first
    while [[ $# -gt 0 ]]; do
        case "$1" in
            --verbose|-V)
                VERBOSE=true
                print_verbose "Verbose mode enabled"
                shift
                ;;
            --version|-v)
                print_banner
                cmd_version
                exit 0
                ;;
            --help)
                cmd_help
                exit 0
                ;;
            *)
                break
                ;;
        esac
    done
    
    # Show banner if not suppressed
    if [ $# -eq 0 ]; then
        print_banner
        # Display colorful usage message with VT-100 styling
        echo -e "${CYAN}${BOLD}Usage:${NC} ${YELLOW}${BOLD}rayanpbx-cli${NC} ${GREEN}${UNDERLINE}<command>${NC} ${BLUE}[options]${NC}"
        echo -e "${CYAN}Run '${YELLOW}${BOLD}rayanpbx-cli help${NC}${CYAN}' for complete command reference${NC}"
        exit 2
    fi
    
    case "$1" in
        version)
            print_banner
            cmd_version
            ;;
        help)
            cmd_help "${2:-}"
            ;;
        extension)
            case "${2:-}" in
                list) cmd_extension_list ;;
                create) cmd_extension_create "$3" "$4" "$5" ;;
                status) cmd_extension_status "$3" ;;
                *) echo "Unknown extension command: ${2:-}"; exit 2 ;;
            esac
            ;;
        trunk)
            case "${2:-}" in
                list) cmd_trunk_list ;;
                test) cmd_trunk_test "$3" ;;
                *) echo "Unknown trunk command: ${2:-}"; exit 2 ;;
            esac
            ;;
        asterisk)
            case "${2:-}" in
                status) cmd_asterisk_status ;;
                restart) cmd_asterisk_restart ;;
                command) cmd_asterisk_command "$3" ;;
                *) echo "Unknown asterisk command: ${2:-}"; exit 2 ;;
            esac
            ;;
        diag)
            case "${2:-}" in
                test-extension) cmd_diag_test_extension "$3" ;;
                health-check) cmd_diag_health_check ;;
                *) echo "Unknown diag command: ${2:-}"; exit 2 ;;
            esac
            ;;
        config)
            case "${2:-}" in
                get) cmd_config_get "$3" ;;
                set) cmd_config_set "$3" "$4" ;;
                list) cmd_config_list ;;
                *) echo "Unknown config command: ${2:-}"; exit 2 ;;
            esac
            ;;
        system)
            case "${2:-}" in
                update) cmd_system_update ;;
                *) echo "Unknown system command: ${2:-}"; exit 2 ;;
            esac
            ;;
        tui)
            shift
            cmd_tui "$@"
            ;;
        *)
            print_banner
            echo "Unknown command: $1"
            echo "Run 'rayanpbx-cli help' for usage information"
            exit 2
            ;;
    esac
}

main "$@"
