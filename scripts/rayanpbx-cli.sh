#!/bin/bash

# RayanPBX CLI Tool
# Comprehensive command-line interface for RayanPBX management

set -euo pipefail

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

# Emojis
CHECK="✅"
CROSS="❌"
INFO="ℹ️ "
WARN="⚠️ "
ROCKET="🚀"

# Configuration
RAYANPBX_ROOT="${RAYANPBX_ROOT:-/opt/rayanpbx}"
API_BASE_URL="http://localhost:8000/api"
ENV_FILE="$RAYANPBX_ROOT/.env"

# Load configuration
if [ -f "$ENV_FILE" ]; then
    source "$ENV_FILE"
    API_BASE_URL="${API_BASE_URL:-http://localhost:8000/api}"
fi

# Helper functions
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

print_header() {
    echo -e "${MAGENTA}═══════════════════════════════════════${NC}"
    echo -e "${CYAN}  $1${NC}"
    echo -e "${MAGENTA}═══════════════════════════════════════${NC}"
}

# API call helper
api_call() {
    local method=$1
    local endpoint=$2
    local data=${3:-}
    
    if [ -n "$data" ]; then
        curl -s -X "$method" "$API_BASE_URL/$endpoint" \
            -H "Content-Type: application/json" \
            -d "$data"
    else
        curl -s -X "$method" "$API_BASE_URL/$endpoint"
    fi
}

# Extension commands
cmd_extension_list() {
    print_header "📱 Extensions List"
    
    response=$(api_call GET "extensions")
    
    if command -v jq &> /dev/null; then
        echo "$response" | jq -r '.[] | "\(.extension_number)\t\(.name)\t\(.enabled)"' | \
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

# Main command dispatcher
main() {
    if [ $# -eq 0 ]; then
        # Display colorful usage message with VT-100 styling
        echo -e "${CYAN}${BOLD}Usage:${NC} ${YELLOW}${BOLD}rayanpbx-cli${NC} ${GREEN}${UNDERLINE}<command>${NC} ${BLUE}[options]${NC}"
        echo -e "${CYAN}Run '${YELLOW}${BOLD}rayanpbx-cli help${NC}${CYAN}' for more information${NC}"
        exit 2
    fi
    
    case "$1" in
        extension)
            case "$2" in
                list) cmd_extension_list ;;
                create) cmd_extension_create "$3" "$4" "$5" ;;
                status) cmd_extension_status "$3" ;;
                *) echo "Unknown extension command: $2"; exit 2 ;;
            esac
            ;;
        trunk)
            case "$2" in
                list) cmd_trunk_list ;;
                test) cmd_trunk_test "$3" ;;
                *) echo "Unknown trunk command: $2"; exit 2 ;;
            esac
            ;;
        asterisk)
            case "$2" in
                status) cmd_asterisk_status ;;
                restart) cmd_asterisk_restart ;;
                command) cmd_asterisk_command "$3" ;;
                *) echo "Unknown asterisk command: $2"; exit 2 ;;
            esac
            ;;
        diag)
            case "$2" in
                test-extension) cmd_diag_test_extension "$3" ;;
                health-check) cmd_diag_health_check ;;
                *) echo "Unknown diag command: $2"; exit 2 ;;
            esac
            ;;
        system)
            case "$2" in
                update) cmd_system_update ;;
                *) echo "Unknown system command: $2"; exit 2 ;;
            esac
            ;;
        help)
            # Show help from TUI
            if [ -x "$RAYANPBX_ROOT/tui/rayanpbx-tui" ]; then
                "$RAYANPBX_ROOT/tui/rayanpbx-tui" --help
            else
                echo "For detailed help, run: rayanpbx-tui"
            fi
            ;;
        *)
            echo "Unknown command: $1"
            echo "Run 'rayanpbx-cli help' for usage information"
            exit 2
            ;;
    esac
}

main "$@"
