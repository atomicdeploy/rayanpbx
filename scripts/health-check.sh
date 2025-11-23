#!/bin/bash

# Service Health & Port Checker
# Ensures ports are available and services are healthy

# Version - read from VERSION file
VERSION="2.0.0"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
VERSION_FILE="$SCRIPT_DIR/../VERSION"
if [ -f "$VERSION_FILE" ]; then
    VERSION=$(cat "$VERSION_FILE" | tr -d '[:space:]')
fi

# Colors - only define if not already set (allows sourcing from install.sh)
if [ -z "${GREEN+x}" ]; then
    readonly GREEN='\033[0;32m'
fi
if [ -z "${RED+x}" ]; then
    readonly RED='\033[0;31m'
fi
if [ -z "${YELLOW+x}" ]; then
    readonly YELLOW='\033[1;33m'
fi
if [ -z "${CYAN+x}" ]; then
    readonly CYAN='\033[0;36m'
fi
if [ -z "${BOLD+x}" ]; then
    readonly BOLD='\033[1m'
fi
if [ -z "${RESET+x}" ]; then
    readonly RESET='\033[0m'
fi

print_success() {
    echo -e "${GREEN}✅ $1${RESET}"
}

print_error() {
    echo -e "${RED}❌ $1${RESET}"
}

print_warning() {
    echo -e "${YELLOW}⚠️  $1${RESET}"
}

print_info() {
    echo -e "${CYAN}ℹ️  $1${RESET}"
}

# Check if port is in use
check_port() {
    local port=$1
    local service_name=${2:-"Service"}
    
    if lsof -i :$port &> /dev/null || netstat -tuln | grep -q ":$port "; then
        local process=$(lsof -ti :$port 2>/dev/null || netstat -tuln | grep ":$port" | awk '{print $7}')
        print_error "Port $port is already in use"
        print_warning "Process using port: $process"
        return 1
    else
        print_success "Port $port is available for $service_name"
        return 0
    fi
}

# Wait and verify port is listening
verify_port_listening() {
    local port=$1
    local service_name=${2:-"Service"}
    local timeout=${3:-30}
    local elapsed=0
    
    print_info "Waiting for $service_name to listen on port $port..."
    
    while [ $elapsed -lt $timeout ]; do
        if netstat -tuln | grep -q ":$port .*LISTEN" || ss -tuln | grep -q ":$port "; then
            print_success "$service_name is listening on port $port"
            return 0
        fi
        sleep 1
        ((elapsed++))
        echo -ne "\r   Waiting... ${elapsed}s/${timeout}s"
    done
    
    echo
    print_error "$service_name failed to listen on port $port after ${timeout}s"
    return 1
}

# Helper function to check if a port is listening (simpler check)
is_port_listening() {
    local port=$1
    # Check both ss (modern) and netstat (legacy) with consistent patterns
    # Match port followed by space or end-of-line to avoid matching partial port numbers
    # netstat fallback for older systems that might not have ss
    if ss -tuln 2>/dev/null | grep -qE ":${port}([[:space:]]|$)" || netstat -tuln 2>/dev/null | grep -qE ":${port}([[:space:]]|$)"; then
        return 0
    fi
    return 1
}

# Wait for port to be listening (similar to verify_port_listening but with different style)
check_port_listening() {
    local port=$1
    local service_name=$2
    local max_attempts=${3:-30}
    local attempt=0
    
    while [ $attempt -lt $max_attempts ]; do
        if is_port_listening "$port"; then
            return 0
        fi
        attempt=$((attempt + 1))
        sleep 1
    done
    
    print_error "Port $port not listening after ${max_attempts}s for $service_name"
    return 1
}

# Check WebSocket health
check_websocket_health() {
    local host=$1
    local port=$2
    local service_name=$3
    local max_attempts=${4:-15}
    local attempt=0
    
    while [ $attempt -lt $max_attempts ]; do
        # Check if port is listening using utility function
        if is_port_listening "$port"; then
            return 0
        fi
        
        attempt=$((attempt + 1))
        sleep 2
    done
    
    print_error "$service_name not responding on port $port"
    return 1
}

# Sanitize output for security (remove sensitive data)
sanitize_output() {
    local text="$1"
    local max_length="${2:-200}"
    # Remove control chars and redact common sensitive patterns
    # Limit output length for security (balance between debugging and data exposure)
    # Patterns: password, token, secret, key, api_key, access_token, auth_token, client_secret, private_key, env vars
    echo "$text" | head -c "$max_length" | tr -d '\000-\037' | sed -E 's/(password|token|secret|key|api[_-]?key|access[_-]?token|auth[_-]?(token|key)|client[_-]?secret|private[_-]?key|[A-Z_]+PASSWORD)[[:space:]]*[:=][[:space:]]*[^[:space:]&]*/\1=***REDACTED***/gi'
}

# Test service health (comprehensive health check for different service types)
test_service_health() {
    local service_type=$1
    local service_name=$2
    
    case $service_type in
        "api")
            print_info "Testing Backend API health..."
            if ! check_port_listening 8000 "$service_name" 30; then
                return 1
            fi
            
            # Use the updated health endpoint with proper response handling
            local url="http://localhost:8000/api/health"
            local max_attempts=15
            local attempt=0
            local temp_file=$(mktemp -t rayanpbx-health.XXXXXX)
            trap "rm -f '$temp_file'" RETURN
            
            while [ $attempt -lt $max_attempts ]; do
                local response=$(curl -s -w "%{http_code}" --connect-timeout 5 -o "$temp_file" "$url" 2>/dev/null)
                
                if [ "$response" = "200" ] || [ "$response" = "302" ]; then
                    print_success "Backend API is healthy and responding"
                    return 0
                fi
                
                if [ "$response" = "500" ]; then
                    print_warning "Backend API returned HTTP 500"
                    local error_details=$(sanitize_output "$(cat "$temp_file")" 200)
                    if [ -n "$error_details" ]; then
                        echo "  Error preview (sanitized): ${error_details}..."
                    fi
                fi
                
                attempt=$((attempt + 1))
                sleep 2
            done
            
            print_error "Backend API is not responding correctly"
            print_info "Check backend logs:"
            echo "  journalctl -u rayanpbx-api -n 50 --no-pager"
            echo "  tail -f /opt/rayanpbx/backend/storage/logs/laravel.log"
            return 1
            ;;
            
        "frontend")
            print_info "Testing Frontend health..."
            if ! check_port_listening 3000 "$service_name" 30; then
                return 1
            fi
            
            local url="http://localhost:3000"
            local max_attempts=15
            local attempt=0
            
            while [ $attempt -lt $max_attempts ]; do
                local response=$(curl -s -o /dev/null -w "%{http_code}" --connect-timeout 5 "$url" 2>/dev/null)
                
                if [ "$response" = "200" ] || [ "$response" = "302" ]; then
                    print_success "Frontend is healthy and responding"
                    return 0
                fi
                
                attempt=$((attempt + 1))
                sleep 2
            done
            
            print_error "Frontend is not responding correctly"
            print_info "Check PM2 logs:"
            echo "  su - www-data -s /bin/bash -c 'pm2 logs rayanpbx-web --nostream'"
            return 1
            ;;
            
        "websocket")
            print_info "Testing WebSocket server health..."
            if ! check_websocket_health "localhost" 9000 "$service_name" 15; then
                print_error "WebSocket server is not responding"
                print_info "Check PM2 logs:"
                echo "  su - www-data -s /bin/bash -c 'pm2 logs rayanpbx-ws --nostream'"
                return 1
            fi
            print_success "WebSocket server is healthy and listening"
            ;;
            
        *)
            print_error "Unknown service type: $service_type"
            return 1
            ;;
    esac
    
    return 0
}

# Check service health via systemctl
check_systemd_service() {
    local service=$1
    
    if ! systemctl is-active --quiet $service; then
        print_error "$service is not running"
        print_warning "Status: $(systemctl is-active $service)"
        print_info "Check logs: journalctl -u $service -n 20"
        return 1
    fi
    
    if ! systemctl is-enabled --quiet $service 2>/dev/null; then
        print_warning "$service is running but not enabled (won't start on boot)"
    fi
    
    local status=$(systemctl show $service --property=ActiveState --value)
    local uptime=$(systemctl show $service --property=ActiveEnterTimestamp --value)
    
    print_success "$service is active"
    print_info "Status: $status"
    print_info "Started: $uptime"
    
    return 0
}

# Check HTTP endpoint health
check_http_health() {
    local url=$1
    local expected_status=${2:-200}
    local timeout=${3:-10}
    
    local status_code=$(curl -s -o /dev/null -w "%{http_code}" --max-time $timeout "$url" 2>/dev/null)
    
    if [ "$status_code" == "$expected_status" ]; then
        print_success "HTTP health check passed: $url (HTTP $status_code)"
        return 0
    else
        print_error "HTTP health check failed: $url (HTTP $status_code, expected $expected_status)"
        return 1
    fi
}

# Check Asterisk specific health
check_asterisk_health() {
    if ! command -v asterisk &> /dev/null; then
        print_error "Asterisk not installed"
        return 1
    fi
    
    # Check if process is running
    if ! pgrep -x asterisk &> /dev/null; then
        print_error "Asterisk process not running"
        return 1
    fi
    
    # Check CLI responsiveness
    if ! asterisk -rx "core show version" &> /dev/null; then
        print_error "Asterisk CLI not responding"
        return 1
    fi
    
    # Get version
    local version=$(asterisk -rx "core show version" 2>/dev/null | head -1)
    print_success "Asterisk is healthy"
    print_info "$version"
    
    # Check active calls
    local calls=$(asterisk -rx "core show calls" 2>/dev/null | grep "active call" | awk '{print $1}')
    print_info "Active calls: ${calls:-0}"
    
    # Check SIP registrations
    local registrations=$(asterisk -rx "pjsip show registrations" 2>/dev/null | grep -c "Registered" || echo "0")
    print_info "SIP registrations: $registrations"
    
    return 0
}

# Check MySQL/MariaDB health
check_mysql_health() {
    local password=$1
    
    if ! command -v mysql &> /dev/null; then
        print_error "MySQL not installed"
        return 1
    fi
    
    # Check if service is running
    if ! systemctl is-active --quiet mysql && ! systemctl is-active --quiet mariadb; then
        print_error "MySQL/MariaDB service not running"
        return 1
    fi
    
    # Check connectivity
    if [ -n "$password" ]; then
        if mysql -u root -p"$password" -e "SELECT 1" &> /dev/null; then
            print_success "MySQL is healthy and accessible"
        else
            print_error "MySQL connection failed"
            return 1
        fi
    else
        print_warning "MySQL password not provided, skipping connection test"
    fi
    
    # Check if rayanpbx database exists
    if mysql -u root -p"$password" -e "USE rayanpbx" &> /dev/null; then
        print_success "RayanPBX database exists"
    else
        print_warning "RayanPBX database not found"
    fi
    
    return 0
}

# Check PM2 services
check_pm2_services() {
    if ! command -v pm2 &> /dev/null; then
        print_error "PM2 not installed"
        return 1
    fi
    
    local pm2_list=$(su - www-data -s /bin/bash -c "pm2 jlist" 2>/dev/null)
    
    if [ -z "$pm2_list" ] || [ "$pm2_list" == "[]" ]; then
        print_warning "No PM2 services running"
        return 1
    fi
    
    # Parse PM2 JSON output
    local service_count=$(echo "$pm2_list" | jq '. | length' 2>/dev/null || echo "0")
    local online_count=$(echo "$pm2_list" | jq '[.[] | select(.pm2_env.status == "online")] | length' 2>/dev/null || echo "0")
    
    print_info "PM2 services: $online_count/$service_count online"
    
    # List services
    echo "$pm2_list" | jq -r '.[] | "  • \(.name): \(.pm2_env.status)"' 2>/dev/null || true
    
    if [ "$online_count" == "$service_count" ]; then
        print_success "All PM2 services are online"
        return 0
    else
        print_warning "Some PM2 services are not online"
        return 1
    fi
}

# Get current Linux username (first non-system user)
get_default_username() {
    # Get users with UID >= 1000 (normal users, not system accounts)
    local username=$(getent passwd | awk -F: '$3 >= 1000 && $3 < 65534 {print $1}' | head -1)
    
    if [ -z "$username" ]; then
        # Fallback to $SUDO_USER if available
        username=${SUDO_USER:-root}
    fi
    
    echo "$username"
}

# Main function
main() {
    local action=${1:-}
    
    # Check for version flag
    if [[ "$action" == "--version" || "$action" == "-v" || "$action" == "version" ]]; then
        echo -e "${CYAN}RayanPBX Health Check${RESET} ${GREEN}v${VERSION}${RESET}"
        echo "Service Health & Port Checker"
        exit 0
    fi
    
    case "$action" in
        check-port)
            check_port "$2" "$3"
            ;;
        verify-port)
            verify_port_listening "$2" "$3" "$4"
            ;;
        check-service)
            check_systemd_service "$2"
            ;;
        check-http)
            check_http_health "$2" "$3" "$4"
            ;;
        check-asterisk)
            check_asterisk_health
            ;;
        check-mysql)
            check_mysql_health "$2"
            ;;
        check-pm2)
            check_pm2_services
            ;;
        get-username)
            get_default_username
            ;;
        full-check)
            echo -e "${CYAN}${BOLD}🔍 Full System Health Check${RESET}\n"
            
            echo -e "${CYAN}📊 Services:${RESET}"
            check_systemd_service "asterisk"
            check_systemd_service "rayanpbx-api"
            check_systemd_service "mysql" || check_systemd_service "mariadb"
            echo
            
            echo -e "${CYAN}🌐 Ports:${RESET}"
            verify_port_listening 5038 "Asterisk AMI"
            verify_port_listening 8000 "RayanPBX API"
            verify_port_listening 3000 "RayanPBX Web"
            verify_port_listening 9000 "WebSocket Server"
            echo
            
            echo -e "${CYAN}💊 Health Checks:${RESET}"
            check_asterisk_health
            check_http_health "http://localhost:8000/api/health" 200
            check_pm2_services
            echo
            ;;
        *)
            if [ -z "$action" ]; then
                echo "Usage: $0 {check-port|verify-port|check-service|check-http|check-asterisk|check-mysql|check-pm2|get-username|full-check|--version} [args...]"
            else
                echo "Unknown command: $action"
                echo "Usage: $0 {check-port|verify-port|check-service|check-http|check-asterisk|check-mysql|check-pm2|get-username|full-check|--version} [args...]"
            fi
            echo
            echo "Commands:"
            echo "  check-port PORT [SERVICE]           - Check if port is available"
            echo "  verify-port PORT [SERVICE] [TIMEOUT] - Wait and verify port is listening"
            echo "  check-service SERVICE               - Check systemd service health"
            echo "  check-http URL [STATUS] [TIMEOUT]   - Check HTTP endpoint"
            echo "  check-asterisk                      - Check Asterisk health"
            echo "  check-mysql [PASSWORD]              - Check MySQL health"
            echo "  check-pm2                           - Check PM2 services"
            echo "  get-username                        - Get default Linux username"
            echo "  full-check                          - Run all health checks"
            echo "  --version, -v                       - Show version information"
            exit 1
            ;;
    esac
}

# Run if called directly
if [ "${BASH_SOURCE[0]}" == "${0}" ]; then
    main "$@"
fi
