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

# Source jq-wrapper for debugging jq errors
if [ -f "$SCRIPT_DIR/jq-wrapper.sh" ]; then
    source "$SCRIPT_DIR/jq-wrapper.sh"
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

# Check if a string is valid JSON
is_valid_json() {
    local input="$1"
    
    if [ -z "$input" ]; then
        return 1
    fi
    
    # Use jq to validate JSON (but use the real jq, not the wrapper)
    local jq_bin
    jq_bin="$(type -P jq)" || return 1
    
    echo "$input" | "$jq_bin" . > /dev/null 2>&1
    return $?
}

# API call helper with robust error handling
# Returns JSON response on stdout
# Sets global API_CALL_STATUS and API_CALL_ERROR
api_call() {
    local method=$1
    local endpoint=$2
    local data=${3:-}
    
    # Reset global status variables
    API_CALL_STATUS=0
    API_CALL_ERROR=""
    API_CALL_CONTENT_TYPE=""
    
    print_verbose "API Call: $method $API_BASE_URL/$endpoint"
    if [ -n "$data" ]; then
        print_verbose "Request body: $data"
    fi
    
    # Create temp file for response body
    local tmp_body
    tmp_body=$(mktemp) || { 
        API_CALL_ERROR="Failed to create temp file"
        API_CALL_STATUS=1
        echo '{"error": "Internal error: failed to create temp file"}'
        return 1
    }
    
    # Make the request and capture status code and content type
    local http_code content_type
    if [ -n "$data" ]; then
        http_code=$(curl -s -w '%{http_code}' -o "$tmp_body" \
            -X "$method" "$API_BASE_URL/$endpoint" \
            -H "Content-Type: application/json" \
            -H "Accept: application/json" \
            -d "$data" 2>/dev/null)
    else
        http_code=$(curl -s -w '%{http_code}' -o "$tmp_body" \
            -X "$method" "$API_BASE_URL/$endpoint" \
            -H "Accept: application/json" 2>/dev/null)
    fi
    
    local curl_exit=$?
    
    # Check if curl failed
    if [ $curl_exit -ne 0 ]; then
        rm -f "$tmp_body"
        API_CALL_STATUS=$curl_exit
        API_CALL_ERROR="Failed to connect to API (curl exit code: $curl_exit)"
        print_verbose "API Error: $API_CALL_ERROR"
        echo "{\"error\": \"$API_CALL_ERROR\", \"success\": false}"
        return $curl_exit
    fi
    
    # Read response body
    local response
    response=$(cat "$tmp_body" 2>/dev/null)
    rm -f "$tmp_body"
    
    print_verbose "HTTP Status: $http_code"
    print_verbose "Response: $response"
    
    # Check HTTP status code
    case "$http_code" in
        2[0-9][0-9])
            # Success status codes (200-299)
            API_CALL_STATUS=0
            ;;
        000)
            # Connection failed
            API_CALL_STATUS=1
            API_CALL_ERROR="Failed to connect to API server at $API_BASE_URL"
            print_verbose "API Error: $API_CALL_ERROR"
            echo "{\"error\": \"$API_CALL_ERROR\", \"success\": false}"
            return 1
            ;;
        401)
            API_CALL_STATUS=401
            API_CALL_ERROR="Authentication required"
            ;;
        403)
            API_CALL_STATUS=403
            API_CALL_ERROR="Access forbidden"
            ;;
        404)
            API_CALL_STATUS=404
            API_CALL_ERROR="Endpoint not found: $endpoint"
            ;;
        422)
            API_CALL_STATUS=422
            API_CALL_ERROR="Validation error"
            ;;
        5[0-9][0-9])
            API_CALL_STATUS=$http_code
            API_CALL_ERROR="Server error (HTTP $http_code)"
            ;;
        *)
            API_CALL_STATUS=$http_code
            API_CALL_ERROR="Unexpected HTTP status: $http_code"
            ;;
    esac
    
    # Check if response is valid JSON
    if ! is_valid_json "$response"; then
        # Response is not JSON - wrap it in a JSON error object
        print_verbose "Response is not valid JSON"
        
        # Check if it looks like HTML
        if echo "$response" | grep -q "<!DOCTYPE\|<html\|<HTML"; then
            API_CALL_ERROR="API returned HTML instead of JSON (HTTP $http_code). The API server may be misconfigured."
            echo "{\"error\": \"$API_CALL_ERROR\", \"success\": false, \"http_code\": $http_code, \"raw_response_type\": \"html\"}"
            return 1
        fi
        
        # Non-JSON, non-HTML response
        API_CALL_ERROR="API returned non-JSON response (HTTP $http_code)"
        # Escape the response for JSON
        local escaped_response
        escaped_response=$(echo "$response" | head -c 500 | sed 's/\\/\\\\/g; s/"/\\"/g; s/\n/\\n/g; s/\r/\\r/g; s/\t/\\t/g')
        echo "{\"error\": \"$API_CALL_ERROR\", \"success\": false, \"http_code\": $http_code, \"raw_response\": \"$escaped_response\"}"
        return 1
    fi
    
    # Return the JSON response
    echo "$response"
    
    # Return non-zero for error status codes
    if [ "$API_CALL_STATUS" -ne 0 ]; then
        return 1
    fi
    
    return 0
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
    local number=${1:-}
    local name=${2:-}
    local password=${3:-}
    
    # Validate required parameters
    if [ -z "$number" ] || [ -z "$name" ] || [ -z "$password" ]; then
        print_error "All parameters required"
        echo "Usage: rayanpbx-cli extension create <number> <name> <password>"
        exit 2
    fi
    
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
    
    # Check if API call was successful
    if [ "${API_CALL_STATUS:-0}" -ne 0 ]; then
        print_error "Failed to create extension: ${API_CALL_ERROR:-Unknown error}"
        exit 1
    fi
    
    if echo "$response" | grep -q '"success".*true\|"created"'; then
        print_success "Extension $number created successfully"
    else
        # Try to extract error message from JSON
        if command -v jq &> /dev/null && is_valid_json "$response"; then
            local error_msg
            error_msg=$(echo "$response" | jq -r '.error // .message // "Unknown error"' 2>/dev/null)
            print_error "Failed to create extension: $error_msg"
        else
            print_error "Failed to create extension: $response"
        fi
        exit 1
    fi
}

cmd_extension_status() {
    local number=${1:-}
    
    # Check if extension number is provided
    if [ -z "$number" ]; then
        print_error "Extension number required"
        echo "Usage: rayanpbx-cli extension status <number>"
        exit 2
    fi
    
    print_header "📊 Extension $number Status"
    
    # API endpoint expects POST with JSON body containing 'endpoint' field
    local data
    data=$(cat <<EOF
{"endpoint": "$number"}
EOF
)
    
    response=$(api_call POST "asterisk/endpoint/status" "$data")
    
    # Check if API call was successful
    if [ "${API_CALL_STATUS:-0}" -ne 0 ]; then
        print_error "Failed to get extension status: ${API_CALL_ERROR:-Unknown error}"
        if [ -n "$response" ]; then
            # Try to extract error message from JSON response
            if command -v jq &> /dev/null && is_valid_json "$response"; then
                local error_msg
                error_msg=$(echo "$response" | jq -r '.error // .message // "Unknown error"' 2>/dev/null)
                if [ -n "$error_msg" ] && [ "$error_msg" != "null" ]; then
                    echo -e "  Details: $error_msg"
                fi
            fi
        fi
        exit 1
    fi
    
    if command -v jq &> /dev/null; then
        # Check if we got a valid response with endpoint data
        local success
        success=$(echo "$response" | jq -r '.success // false' 2>/dev/null)
        
        if [ "$success" != "true" ]; then
            # Check for error in response
            local error_msg
            error_msg=$(echo "$response" | jq -r '.error // .message // "Unknown error"' 2>/dev/null)
            print_error "API error: $error_msg"
            exit 1
        fi
        
        # Extract endpoint status from response
        local registered ip user_agent
        registered=$(echo "$response" | jq -r '.endpoint.registered // false' 2>/dev/null)
        ip=$(echo "$response" | jq -r '.endpoint.ip_address // "N/A"' 2>/dev/null)
        user_agent=$(echo "$response" | jq -r '.endpoint.user_agent // "N/A"' 2>/dev/null)
        
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

cmd_extension_toggle() {
    local number=$1
    
    if [ -z "$number" ]; then
        print_error "Extension number required"
        echo "Usage: rayanpbx-cli extension toggle <number>"
        exit 2
    fi
    
    print_info "Toggling extension $number..."
    
    # First, get the extension ID by listing extensions
    response=$(api_call GET "extensions")
    
    if command -v jq &> /dev/null; then
        ext_id=$(echo "$response" | jq -r --arg num "$number" '.extensions[] | select(.extension_number == $num) | .id')
        current_enabled=$(echo "$response" | jq -r --arg num "$number" '.extensions[] | select(.extension_number == $num) | .enabled')
        
        if [ -z "$ext_id" ] || [ "$ext_id" == "null" ]; then
            print_error "Extension $number not found"
            exit 1
        fi
        
        # Call the toggle endpoint
        toggle_response=$(api_call POST "extensions/$ext_id/toggle")
        
        if echo "$toggle_response" | grep -q "success\|updated"; then
            new_enabled=$(echo "$toggle_response" | jq -r '.extension.enabled')
            if [ "$new_enabled" == "true" ]; then
                print_success "Extension $number enabled successfully"
            else
                print_success "Extension $number disabled successfully"
            fi
        else
            print_error "Failed to toggle extension: $toggle_response"
            exit 1
        fi
    else
        print_error "jq is required for this command"
        exit 1
    fi
}

cmd_extension_enable() {
    local number=$1
    
    if [ -z "$number" ]; then
        print_error "Extension number required"
        echo "Usage: rayanpbx-cli extension enable <number>"
        exit 2
    fi
    
    print_info "Enabling extension $number..."
    
    # Get extension ID and current status
    response=$(api_call GET "extensions")
    
    if command -v jq &> /dev/null; then
        ext_id=$(echo "$response" | jq -r --arg num "$number" '.extensions[] | select(.extension_number == $num) | .id')
        current_enabled=$(echo "$response" | jq -r --arg num "$number" '.extensions[] | select(.extension_number == $num) | .enabled')
        
        if [ -z "$ext_id" ] || [ "$ext_id" == "null" ]; then
            print_error "Extension $number not found"
            exit 1
        fi
        
        if [ "$current_enabled" == "true" ]; then
            print_info "Extension $number is already enabled"
            exit 0
        fi
        
        # Update extension to enable it
        update_response=$(api_call PUT "extensions/$ext_id" '{"enabled": true}')
        
        if echo "$update_response" | grep -q "success\|updated"; then
            print_success "Extension $number enabled successfully"
            print_info "SIP registration is now possible for this extension"
        else
            print_error "Failed to enable extension: $update_response"
            exit 1
        fi
    else
        print_error "jq is required for this command"
        exit 1
    fi
}

cmd_extension_disable() {
    local number=$1
    
    if [ -z "$number" ]; then
        print_error "Extension number required"
        echo "Usage: rayanpbx-cli extension disable <number>"
        exit 2
    fi
    
    print_info "Disabling extension $number..."
    
    # Get extension ID and current status
    response=$(api_call GET "extensions")
    
    if command -v jq &> /dev/null; then
        ext_id=$(echo "$response" | jq -r --arg num "$number" '.extensions[] | select(.extension_number == $num) | .id')
        current_enabled=$(echo "$response" | jq -r --arg num "$number" '.extensions[] | select(.extension_number == $num) | .enabled')
        
        if [ -z "$ext_id" ] || [ "$ext_id" == "null" ]; then
            print_error "Extension $number not found"
            exit 1
        fi
        
        if [ "$current_enabled" == "false" ]; then
            print_info "Extension $number is already disabled"
            exit 0
        fi
        
        # Update extension to disable it
        update_response=$(api_call PUT "extensions/$ext_id" '{"enabled": false}')
        
        if echo "$update_response" | grep -q "success\|updated"; then
            print_success "Extension $number disabled successfully"
            print_warn "SIP registration is now blocked for this extension"
        else
            print_error "Failed to disable extension: $update_response"
            exit 1
        fi
    else
        print_error "jq is required for this command"
        exit 1
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
    local name=${1:-}
    
    if [ -z "$name" ]; then
        print_error "Trunk name required"
        echo "Usage: rayanpbx-cli trunk test <name>"
        exit 2
    fi
    
    print_header "🔍 Testing Trunk: $name"
    
    response=$(api_call GET "validate/trunk/$name")
    
    # Check if API call was successful
    if [ "${API_CALL_STATUS:-0}" -ne 0 ]; then
        print_error "Failed to test trunk: ${API_CALL_ERROR:-Unknown error}"
        exit 1
    fi
    
    if command -v jq &> /dev/null; then
        reachable=$(echo "$response" | jq -r '.reachable // false')
        latency=$(echo "$response" | jq -r '.latency_ms // "N/A"')
        
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
    local command=${1:-}
    
    if [ -z "$command" ]; then
        print_error "Asterisk CLI command required"
        echo "Usage: rayanpbx-cli asterisk command <cli_command>"
        exit 2
    fi
    
    print_header "🖥️  Executing: $command"
    
    sudo asterisk -rx "$command"
}

# Diagnostics commands
cmd_diag_test_extension() {
    local number=${1:-}
    
    if [ -z "$number" ]; then
        print_error "Extension number required"
        echo "Usage: rayanpbx-cli diag test-extension <number>"
        exit 2
    fi
    
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

# Check SIP port listening (validates Asterisk is accepting connections)
cmd_diag_check_sip() {
    print_header "📞 SIP Port Health Check"
    
    local script_path="$SCRIPT_DIR/health-check.sh"
    
    if [ ! -f "$script_path" ]; then
        print_error "Health check script not found"
        exit 1
    fi
    
    # Run the check-sip command from health-check.sh
    bash "$script_path" check-sip "${1:-5060}" "${2:-true}"
}

# SIP Testing commands
cmd_sip_test_tools() {
    print_header "🔧 SIP Testing Tools"
    
    local script_path="$SCRIPT_DIR/sip-test-suite.sh"
    
    if [ ! -f "$script_path" ]; then
        print_error "SIP test suite script not found"
        exit 1
    fi
    
    bash "$script_path" tools
}

cmd_sip_test_register() {
    local extension=$1
    local password=$2
    local server=${3:-127.0.0.1}
    
    if [ -z "$extension" ] || [ -z "$password" ]; then
        print_error "Extension and password required"
        echo "Usage: rayanpbx-cli sip-test register <extension> <password> [server]"
        exit 2
    fi
    
    print_header "📞 Testing SIP Registration"
    
    local script_path="$SCRIPT_DIR/sip-test-suite.sh"
    
    if [ ! -f "$script_path" ]; then
        print_error "SIP test suite script not found"
        exit 1
    fi
    
    bash "$script_path" register "$extension" "$password" "$server"
}

cmd_sip_test_call() {
    local from_ext=$1
    local from_pass=$2
    local to_ext=$3
    local to_pass=$4
    local server=${5:-127.0.0.1}
    
    if [ -z "$from_ext" ] || [ -z "$from_pass" ] || [ -z "$to_ext" ] || [ -z "$to_pass" ]; then
        print_error "All parameters required"
        echo "Usage: rayanpbx-cli sip-test call <from_ext> <from_pass> <to_ext> <to_pass> [server]"
        exit 2
    fi
    
    print_header "📞 Testing SIP Call"
    
    local script_path="$SCRIPT_DIR/sip-test-suite.sh"
    
    if [ ! -f "$script_path" ]; then
        print_error "SIP test suite script not found"
        exit 1
    fi
    
    bash "$script_path" call "$from_ext" "$from_pass" "$to_ext" "$to_pass" "$server"
}

cmd_sip_test_full() {
    local ext1=$1
    local pass1=$2
    local ext2=$3
    local pass2=$4
    local server=${5:-127.0.0.1}
    
    if [ -z "$ext1" ] || [ -z "$pass1" ] || [ -z "$ext2" ] || [ -z "$pass2" ]; then
        print_error "All parameters required"
        echo "Usage: rayanpbx-cli sip-test full <ext1> <pass1> <ext2> <pass2> [server]"
        exit 2
    fi
    
    print_header "🧪 Running Full SIP Test Suite"
    
    local script_path="$SCRIPT_DIR/sip-test-suite.sh"
    
    if [ ! -f "$script_path" ]; then
        print_error "SIP test suite script not found"
        exit 1
    fi
    
    bash "$script_path" full "$ext1" "$pass1" "$ext2" "$pass2" "$server"
}

cmd_sip_test_install() {
    local tool=$1
    
    if [ -z "$tool" ]; then
        print_error "Tool name required"
        echo "Usage: rayanpbx-cli sip-test install <tool>"
        echo "Available tools: pjsua, sipsak, sipp"
        exit 2
    fi
    
    print_header "📦 Installing SIP Testing Tool"
    
    local script_path="$SCRIPT_DIR/sip-test-suite.sh"
    
    if [ ! -f "$script_path" ]; then
        print_error "SIP test suite script not found"
        exit 1
    fi
    
    sudo bash "$script_path" install "$tool"
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

cmd_system_upgrade() {
    print_header "${ROCKET} Upgrading RayanPBX"
    
    # Check if upgrade script exists
    local upgrade_script="$SCRIPT_DIR/upgrade.sh"
    if [ ! -f "$upgrade_script" ]; then
        # Try installed path
        upgrade_script="/opt/rayanpbx/scripts/upgrade.sh"
    fi
    
    if [ ! -f "$upgrade_script" ]; then
        print_error "Upgrade script not found"
        print_info "Expected location: $SCRIPT_DIR/upgrade.sh or /opt/rayanpbx/scripts/upgrade.sh"
        exit 1
    fi
    
    # Execute the upgrade script
    print_info "Launching upgrade script..."
    exec sudo bash "$upgrade_script" "$@"
}

cmd_system_set_mode() {
    local mode=$1
    
    print_header "⚙️ Setting Application Mode"
    
    if [ -z "$mode" ]; then
        print_error "Mode not specified"
        echo "Usage: rayanpbx-cli system set-mode <production|development|local>"
        exit 2
    fi
    
    case "$mode" in
        production|prod)
            mode="production"
            debug="false"
            ;;
        development|dev)
            mode="development"
            debug="true"
            ;;
        local)
            mode="local"
            debug="true"
            ;;
        *)
            print_error "Invalid mode: $mode"
            echo "Valid modes: production, development, local"
            exit 2
            ;;
    esac
    
    print_info "Setting APP_ENV=$mode and APP_DEBUG=$debug..."
    
    if [ ! -f "$ENV_FILE" ]; then
        print_error "Environment file not found: $ENV_FILE"
        exit 1
    fi
    
    # Backup .env file
    if ! cp "$ENV_FILE" "${ENV_FILE}.backup.$(date +%Y%m%d_%H%M%S)"; then
        print_error "Failed to create backup of $ENV_FILE"
        exit 1
    fi
    print_verbose "Backup created"
    
    # Update APP_ENV
    if grep -q "^APP_ENV=" "$ENV_FILE"; then
        sed -i "s|^APP_ENV=.*|APP_ENV=$mode|" "$ENV_FILE"
    else
        echo "APP_ENV=$mode" >> "$ENV_FILE"
    fi
    
    # Update APP_DEBUG
    if grep -q "^APP_DEBUG=" "$ENV_FILE"; then
        sed -i "s|^APP_DEBUG=.*|APP_DEBUG=$debug|" "$ENV_FILE"
    else
        echo "APP_DEBUG=$debug" >> "$ENV_FILE"
    fi
    
    print_success "Mode set to: $mode (debug: $debug)"
    
    # Also update backend .env if it exists
    if [ -f "$RAYANPBX_ROOT/backend/.env" ]; then
        print_info "Updating backend .env..."
        cp "$RAYANPBX_ROOT/backend/.env" "$RAYANPBX_ROOT/backend/.env.backup.$(date +%Y%m%d_%H%M%S)"
        
        if grep -q "^APP_ENV=" "$RAYANPBX_ROOT/backend/.env"; then
            sed -i "s|^APP_ENV=.*|APP_ENV=$mode|" "$RAYANPBX_ROOT/backend/.env"
        fi
        
        if grep -q "^APP_DEBUG=" "$RAYANPBX_ROOT/backend/.env"; then
            sed -i "s|^APP_DEBUG=.*|APP_DEBUG=$debug|" "$RAYANPBX_ROOT/backend/.env"
        fi
    fi
    
    # Restart services
    print_info "Restarting services..."
    if systemctl is-active --quiet rayanpbx-api; then
        sudo systemctl restart rayanpbx-api
        print_success "API service restarted"
    fi
    
    print_success "Application mode changed successfully!"
    echo ""
    print_info "Current configuration:"
    echo "  APP_ENV: $mode"
    echo "  APP_DEBUG: $debug"
}

cmd_system_toggle_debug() {
    print_header "🐛 Toggling Debug Mode"
    
    if [ ! -f "$ENV_FILE" ]; then
        print_error "Environment file not found: $ENV_FILE"
        exit 1
    fi
    
    # Get current debug value (default to false if not found)
    local current_debug=$(grep "^APP_DEBUG=" "$ENV_FILE" 2>/dev/null | cut -d'=' -f2 || echo "false")
    
    # Toggle it
    local new_debug
    if [ "$current_debug" == "true" ]; then
        new_debug="false"
    else
        new_debug="true"
    fi
    
    print_info "Current: APP_DEBUG=$current_debug"
    print_info "Setting: APP_DEBUG=$new_debug"
    
    # Backup .env file
    if ! cp "$ENV_FILE" "${ENV_FILE}.backup.$(date +%Y%m%d_%H%M%S)"; then
        print_error "Failed to create backup of $ENV_FILE"
        exit 1
    fi
    
    # Update APP_DEBUG
    if grep -q "^APP_DEBUG=" "$ENV_FILE"; then
        sed -i "s|^APP_DEBUG=.*|APP_DEBUG=$new_debug|" "$ENV_FILE"
    else
        echo "APP_DEBUG=$new_debug" >> "$ENV_FILE"
    fi
    
    # Also update backend .env if it exists
    if [ -f "$RAYANPBX_ROOT/backend/.env" ]; then
        if grep -q "^APP_DEBUG=" "$RAYANPBX_ROOT/backend/.env"; then
            sed -i "s|^APP_DEBUG=.*|APP_DEBUG=$new_debug|" "$RAYANPBX_ROOT/backend/.env"
        fi
    fi
    
    print_success "Debug mode set to: $new_debug"
    
    # Restart services
    print_info "Restarting services..."
    if systemctl is-active --quiet rayanpbx-api; then
        sudo systemctl restart rayanpbx-api
        print_success "API service restarted"
    fi
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

cmd_config_add() {
    local key=$1
    local value=$2
    
    if [ -z "$key" ] || [ -z "$value" ]; then
        print_error "Both key and value parameters required"
        echo "Usage: rayanpbx-cli config add <KEY> <VALUE>"
        exit 2
    fi
    
    if [ ! -f "$ENV_FILE" ]; then
        print_error "Configuration file not found: $ENV_FILE"
        exit 4
    fi
    
    # Check if key already exists
    if grep -q "^${key}=" "$ENV_FILE"; then
        print_error "Key '$key' already exists. Use 'config set' to modify it."
        exit 1
    fi
    
    # Backup config file using helper from ini-helper.sh
    local backup
    backup=$(backup_config "$ENV_FILE")
    if [ -n "$backup" ]; then
        print_verbose "Backup: $backup"
    fi
    
    # Add new key
    echo "${key}=${value}" >> "$ENV_FILE"
    print_success "Added ${key}=${value}"
}

cmd_config_remove() {
    local key=$1
    
    if [ -z "$key" ]; then
        print_error "Key parameter required"
        echo "Usage: rayanpbx-cli config remove <KEY>"
        exit 2
    fi
    
    if [ ! -f "$ENV_FILE" ]; then
        print_error "Configuration file not found: $ENV_FILE"
        exit 4
    fi
    
    # Check if key exists
    if ! grep -q "^${key}=" "$ENV_FILE"; then
        print_warn "Key '$key' not found in configuration"
        exit 1
    fi
    
    # Backup config file using helper from ini-helper.sh
    local backup
    backup=$(backup_config "$ENV_FILE")
    if [ -n "$backup" ]; then
        print_verbose "Backup: $backup"
    fi
    
    # Remove the key
    sed -i "/^${key}=/d" "$ENV_FILE"
    print_success "Removed ${key}"
}

cmd_config_reload() {
    local service=${1:-all}
    
    print_header "🔄 Reloading Services"
    
    case "$service" in
        asterisk)
            print_info "Reloading Asterisk configuration..."
            if command -v asterisk &> /dev/null; then
                asterisk -rx "core reload" && print_success "Asterisk reloaded" || print_error "Failed to reload Asterisk"
            else
                print_warn "Asterisk not found"
            fi
            ;;
        laravel|backend|api)
            print_info "Clearing Laravel configuration cache..."
            if [ -d "$RAYANPBX_ROOT/backend" ]; then
                cd "$RAYANPBX_ROOT/backend"
                php artisan config:clear && print_success "Laravel config cleared" || print_error "Failed to clear Laravel config"
                php artisan cache:clear && print_success "Laravel cache cleared" || print_error "Failed to clear Laravel cache"
            else
                print_warn "Backend directory not found"
            fi
            ;;
        frontend|vue|nuxt)
            print_info "Restarting frontend service..."
            if systemctl is-active --quiet rayanpbx-frontend; then
                sudo systemctl restart rayanpbx-frontend && print_success "Frontend restarted" || print_error "Failed to restart frontend"
            else
                print_warn "Frontend service not running"
            fi
            ;;
        all)
            print_info "Reloading all services..."
            
            # Reload Asterisk
            if command -v asterisk &> /dev/null; then
                asterisk -rx "core reload" && print_success "Asterisk reloaded" || print_error "Failed to reload Asterisk"
            fi
            
            # Clear Laravel caches
            if [ -d "$RAYANPBX_ROOT/backend" ]; then
                cd "$RAYANPBX_ROOT/backend"
                php artisan config:clear && print_success "Laravel config cleared" || print_warn "Failed to clear Laravel config"
                php artisan cache:clear && print_success "Laravel cache cleared" || print_warn "Failed to clear Laravel cache"
            fi
            
            # Restart frontend if running
            if systemctl is-active --quiet rayanpbx-frontend 2>/dev/null; then
                sudo systemctl restart rayanpbx-frontend && print_success "Frontend restarted" || print_warn "Failed to restart frontend"
            fi
            
            print_success "All services reloaded"
            ;;
        *)
            print_error "Unknown service: $service"
            echo "Valid services: asterisk, laravel (or backend/api), frontend (or vue/nuxt), all"
            exit 2
            ;;
    esac
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
        echo -e "   ${GREEN}toggle${NC} <number>                    Toggle extension enabled/disabled"
        echo -e "   ${GREEN}enable${NC} <number>                    Enable extension for registration"
        echo -e "   ${GREEN}disable${NC} <number>                   Disable extension (block registration)"
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
        echo -e "   ${GREEN}check-sip${NC} [port] [auto-fix]       Check SIP port is listening (validates connection)"
        echo ""
        
        echo -e "${CYAN}📞 sip-test${NC} ${DIM}- SIP testing suite${NC}"
        echo -e "   ${GREEN}tools${NC}                             List available SIP testing tools"
        echo -e "   ${GREEN}install${NC} <tool>                    Install a SIP testing tool (pjsua/sipsak/sipp)"
        echo -e "   ${GREEN}register${NC} <ext> <pass> [server]   Test SIP registration"
        echo -e "   ${GREEN}call${NC} <from> <fpass> <to> <tpass> [srv]  Test call between extensions"
        echo -e "   ${GREEN}full${NC} <ext1> <pass1> <ext2> <pass2> [srv] Run full test suite"
        echo ""
        
        echo -e "${CYAN}⚙️  config${NC} ${DIM}- Configuration management${NC}"
        echo -e "   ${GREEN}get${NC} <KEY>                         Get configuration value"
        echo -e "   ${GREEN}set${NC} <KEY> <VALUE>                 Set configuration value"
        echo -e "   ${GREEN}add${NC} <KEY> <VALUE>                 Add new configuration key"
        echo -e "   ${GREEN}remove${NC} <KEY>                      Remove configuration key"
        echo -e "   ${GREEN}list${NC}                              List all configuration"
        echo -e "   ${GREEN}reload${NC} [service]                  Reload services (asterisk/laravel/frontend/all)"
        echo ""
        
        echo -e "${CYAN}🖥️  system${NC} ${DIM}- System operations${NC}"
        echo -e "   ${GREEN}update${NC}                            Update RayanPBX from repository"
        echo -e "   ${GREEN}upgrade${NC}                           Run system upgrade (calls upgrade script)"
        echo -e "   ${GREEN}set-mode${NC} <mode>                   Set application mode (production/development/local)"
        echo -e "   ${GREEN}toggle-debug${NC}                      Toggle debug mode on/off"
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
        echo -e "  ${DIM}# Test SIP registration${NC}"
        echo -e "  ${YELLOW}rayanpbx-cli sip-test register 1001 mypassword${NC}\n"
        echo -e "  ${DIM}# Test call between extensions${NC}"
        echo -e "  ${YELLOW}rayanpbx-cli sip-test call 1001 pass1 1002 pass2${NC}\n"
        
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
                echo -e "  ${DIM}Example: rayanpbx-cli extension status 100${NC}\n"
                echo -e "${YELLOW}rayanpbx-cli extension toggle <number>${NC}"
                echo -e "  Toggles extension between enabled and disabled states."
                echo -e "  ${DIM}Example: rayanpbx-cli extension toggle 100${NC}\n"
                echo -e "${YELLOW}rayanpbx-cli extension enable <number>${NC}"
                echo -e "  Enables an extension for SIP registration."
                echo -e "  ${DIM}Example: rayanpbx-cli extension enable 100${NC}\n"
                echo -e "${YELLOW}rayanpbx-cli extension disable <number>${NC}"
                echo -e "  Disables an extension (blocks SIP registration)."
                echo -e "  ${DIM}Example: rayanpbx-cli extension disable 100${NC}"
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
                echo -e "${YELLOW}rayanpbx-cli config add <KEY> <VALUE>${NC}"
                echo -e "  Adds a new configuration key-value pair (fails if key exists)."
                echo -e "  ${DIM}Example: rayanpbx-cli config add NEW_FEATURE_FLAG true${NC}\n"
                echo -e "${YELLOW}rayanpbx-cli config remove <KEY>${NC}"
                echo -e "  Removes a configuration key from .env file."
                echo -e "  ${DIM}Example: rayanpbx-cli config remove OLD_SETTING${NC}\n"
                echo -e "${YELLOW}rayanpbx-cli config list${NC}"
                echo -e "  Lists all configuration key-value pairs."
                echo -e "  ${DIM}Note: Sensitive values (passwords, secrets) are masked.${NC}\n"
                echo -e "${YELLOW}rayanpbx-cli config reload [SERVICE]${NC}"
                echo -e "  Reloads configuration for specified service or all services."
                echo -e "  ${DIM}Services: asterisk, laravel (backend), frontend, all (default)${NC}"
                echo -e "  ${DIM}Example: rayanpbx-cli config reload asterisk${NC}"
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
                create) cmd_extension_create "${3:-}" "${4:-}" "${5:-}" ;;
                status) cmd_extension_status "${3:-}" ;;
                toggle) cmd_extension_toggle "${3:-}" ;;
                enable) cmd_extension_enable "${3:-}" ;;
                disable) cmd_extension_disable "${3:-}" ;;
                *) echo "Unknown extension command: ${2:-}"; exit 2 ;;
            esac
            ;;
        trunk)
            case "${2:-}" in
                list) cmd_trunk_list ;;
                test) cmd_trunk_test "${3:-}" ;;
                *) echo "Unknown trunk command: ${2:-}"; exit 2 ;;
            esac
            ;;
        asterisk)
            case "${2:-}" in
                status) cmd_asterisk_status ;;
                restart) cmd_asterisk_restart ;;
                command) cmd_asterisk_command "${3:-}" ;;
                *) echo "Unknown asterisk command: ${2:-}"; exit 2 ;;
            esac
            ;;
        diag)
            case "${2:-}" in
                test-extension) cmd_diag_test_extension "${3:-}" ;;
                health-check) cmd_diag_health_check ;;
                check-sip) cmd_diag_check_sip "${3:-}" "${4:-}" ;;
                *) echo "Unknown diag command: ${2:-}"; exit 2 ;;
            esac
            ;;
        sip-test)
            case "${2:-}" in
                tools) cmd_sip_test_tools ;;
                install) cmd_sip_test_install "${3:-}" ;;
                register) cmd_sip_test_register "${3:-}" "${4:-}" "${5:-}" ;;
                call) cmd_sip_test_call "${3:-}" "${4:-}" "${5:-}" "${6:-}" "${7:-}" ;;
                full) cmd_sip_test_full "${3:-}" "${4:-}" "${5:-}" "${6:-}" "${7:-}" ;;
                *) echo "Unknown sip-test command: ${2:-}"; exit 2 ;;
            esac
            ;;
        config)
            case "${2:-}" in
                get) cmd_config_get "${3:-}" ;;
                set) cmd_config_set "${3:-}" "${4:-}" ;;
                add) cmd_config_add "${3:-}" "${4:-}" ;;
                remove) cmd_config_remove "${3:-}" ;;
                list) cmd_config_list ;;
                reload) cmd_config_reload "${3:-}" ;;
                *) echo "Unknown config command: ${2:-}"; exit 2 ;;
            esac
            ;;
        system)
            case "${2:-}" in
                update) cmd_system_update ;;
                upgrade) shift; shift; cmd_system_upgrade "$@" ;;
                set-mode) cmd_system_set_mode "${3:-}" ;;
                toggle-debug) cmd_system_toggle_debug ;;
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
