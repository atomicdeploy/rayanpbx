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

cmd_asterisk_start() {
    print_info "Starting Asterisk service..."
    sudo systemctl start asterisk
    sleep 2
    
    if systemctl is-active --quiet asterisk; then
        print_success "Asterisk started successfully"
    else
        print_error "Failed to start Asterisk"
        exit 3
    fi
}

cmd_asterisk_stop() {
    print_info "Stopping Asterisk service..."
    sudo systemctl stop asterisk
    sleep 2
    
    if ! systemctl is-active --quiet asterisk; then
        print_success "Asterisk stopped successfully"
    else
        print_error "Failed to stop Asterisk"
        exit 3
    fi
}

cmd_asterisk_reload() {
    print_info "Reloading Asterisk configuration..."
    sudo asterisk -rx "core reload"
    print_success "Asterisk configuration reloaded"
}

cmd_asterisk_console() {
    print_header "🖥️  Asterisk Console (Ctrl+C to exit)"
    sudo asterisk -r
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

cmd_diag_channels() {
    print_header "📞 Active Channels"
    
    sudo asterisk -rx "core show channels"
}

cmd_diag_calls() {
    print_header "📞 Active Calls"
    
    sudo asterisk -rx "core show calls"
}

cmd_diag_version() {
    print_header "📦 System Versions"
    
    echo -e "${CYAN}Asterisk:${NC}"
    sudo asterisk -rx "core show version"
    echo
    
    echo -e "${CYAN}RayanPBX:${NC}"
    if [ -f "$RAYANPBX_ROOT/VERSION" ]; then
        cat "$RAYANPBX_ROOT/VERSION"
    else
        echo "Version file not found"
    fi
    echo
    
    echo -e "${CYAN}PHP:${NC}"
    php -v | head -1
    echo
    
    echo -e "${CYAN}Node.js:${NC}"
    node -v
    echo
    
    echo -e "${CYAN}Go:${NC}"
    go version 2>/dev/null || echo "Go not found"
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

cmd_system_info() {
    print_header "💻 System Information"
    
    echo -e "${CYAN}Hostname:${NC} $(hostname)"
    echo -e "${CYAN}Uptime:${NC} $(uptime -p)"
    echo -e "${CYAN}Kernel:${NC} $(uname -r)"
    echo -e "${CYAN}OS:${NC} $(lsb_release -d | cut -f2)"
    echo
    
    echo -e "${CYAN}CPU:${NC}"
    lscpu | grep "Model name" | cut -d':' -f2 | xargs
    echo -e "${CYAN}CPU Cores:${NC} $(nproc)"
    echo
    
    echo -e "${CYAN}Memory:${NC}"
    free -h | grep Mem | awk '{print "  Total: "$2", Used: "$3", Free: "$4}'
    echo
    
    echo -e "${CYAN}Disk:${NC}"
    df -h / | tail -1 | awk '{print "  Total: "$2", Used: "$3" ("$5"), Free: "$4}'
}

cmd_system_services() {
    print_header "🔧 Service Status"
    
    services=("asterisk" "rayanpbx-api" "mysql" "mariadb" "redis-server")
    
    for service in "${services[@]}"; do
        if systemctl list-units --full --all | grep -q "$service.service"; then
            if systemctl is-active --quiet "$service"; then
                echo -e "  ${GREEN}●${NC} $service - Running"
            else
                echo -e "  ${RED}●${NC} $service - Stopped"
            fi
        fi
    done
    
    echo
    print_info "PM2 Services:"
    if command -v pm2 &> /dev/null; then
        pm2 list 2>/dev/null || echo "  No PM2 services"
    else
        echo "  PM2 not installed"
    fi
}

cmd_system_reload() {
    print_header "🔄 Reloading RayanPBX"
    
    print_info "Reloading Asterisk configuration..."
    sudo asterisk -rx "core reload"
    
    print_info "Restarting API server..."
    sudo systemctl restart rayanpbx-api
    
    print_info "Restarting PM2 services..."
    pm2 restart all
    
    print_success "Reload complete"
}

cmd_system_chown() {
    print_header "🔒 Fixing Permissions"
    
    print_info "Setting correct ownership for RayanPBX files..."
    
    if [ -d "$RAYANPBX_ROOT" ]; then
        sudo chown -R www-data:www-data "$RAYANPBX_ROOT/backend/storage"
        sudo chown -R www-data:www-data "$RAYANPBX_ROOT/backend/bootstrap/cache"
        sudo chmod -R 775 "$RAYANPBX_ROOT/backend/storage"
        sudo chmod -R 775 "$RAYANPBX_ROOT/backend/bootstrap/cache"
        print_success "Permissions fixed"
    else
        print_error "RayanPBX directory not found"
        exit 1
    fi
}

cmd_system_chown() {
    print_header "🔒 Fixing Permissions"
    
    print_info "Setting correct ownership for RayanPBX files..."
    
    if [ -d "$RAYANPBX_ROOT" ]; then
        sudo chown -R www-data:www-data "$RAYANPBX_ROOT/backend/storage"
        sudo chown -R www-data:www-data "$RAYANPBX_ROOT/backend/bootstrap/cache"
        sudo chmod -R 775 "$RAYANPBX_ROOT/backend/storage"
        sudo chmod -R 775 "$RAYANPBX_ROOT/backend/bootstrap/cache"
        print_success "Permissions fixed"
    else
        print_error "RayanPBX directory not found"
        exit 1
    fi
}

# Database commands
cmd_database_mysql() {
    print_header "🗄️  MySQL Console"
    
    if [ -f "$ENV_FILE" ]; then
        source "$ENV_FILE"
        mysql -u root -p"${DB_PASSWORD:-}" rayanpbx
    else
        mysql -u root rayanpbx
    fi
}

cmd_database_info() {
    print_header "🗄️  Database Information"
    
    if [ -f "$ENV_FILE" ]; then
        source "$ENV_FILE"
        DB_NAME="${DB_DATABASE:-rayanpbx}"
        
        echo -e "${CYAN}Database:${NC} $DB_NAME"
        echo -e "${CYAN}Host:${NC} ${DB_HOST:-localhost}"
        echo -e "${CYAN}Port:${NC} ${DB_PORT:-3306}"
        echo
        
        # Get table count
        table_count=$(mysql -u root -p"${DB_PASSWORD:-}" -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_NAME'" -sN 2>/dev/null)
        echo -e "${CYAN}Tables:${NC} ${table_count:-0}"
        
        # Get database size
        db_size=$(mysql -u root -p"${DB_PASSWORD:-}" -e "SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS 'Size (MB)' FROM information_schema.tables WHERE table_schema='$DB_NAME'" -sN 2>/dev/null)
        echo -e "${CYAN}Size:${NC} ${db_size:-0} MB"
    else
        print_error "Environment file not found"
        exit 1
    fi
}

cmd_database_backup() {
    print_header "💾 Database Backup"
    
    if [ -f "$ENV_FILE" ]; then
        source "$ENV_FILE"
        DB_NAME="${DB_DATABASE:-rayanpbx}"
        
        backup_dir="$RAYANPBX_ROOT/backups"
        mkdir -p "$backup_dir"
        
        backup_file="$backup_dir/rayanpbx_$(date +%Y%m%d_%H%M%S).sql"
        
        print_info "Backing up database to: $backup_file"
        mysqldump -u root -p"${DB_PASSWORD:-}" "$DB_NAME" > "$backup_file"
        
        if [ -f "$backup_file" ]; then
            gzip "$backup_file"
            print_success "Backup created: ${backup_file}.gz"
        else
            print_error "Backup failed"
            exit 1
        fi
    else
        print_error "Environment file not found"
        exit 1
    fi
}

cmd_database_restore() {
    local backup_file=$1
    
    print_header "📥 Database Restore"
    
    if [ ! -f "$backup_file" ]; then
        print_error "Backup file not found: $backup_file"
        exit 1
    fi
    
    if [ -f "$ENV_FILE" ]; then
        source "$ENV_FILE"
        DB_NAME="${DB_DATABASE:-rayanpbx}"
        
        print_warn "This will overwrite the current database!"
        read -p "Are you sure? (yes/no): " confirm
        
        if [ "$confirm" != "yes" ]; then
            print_info "Restore cancelled"
            exit 0
        fi
        
        print_info "Restoring database from: $backup_file"
        
        if [[ "$backup_file" == *.gz ]]; then
            gunzip -c "$backup_file" | mysql -u root -p"${DB_PASSWORD:-}" "$DB_NAME"
        else
            mysql -u root -p"${DB_PASSWORD:-}" "$DB_NAME" < "$backup_file"
        fi
        
        print_success "Database restored successfully"
    else
        print_error "Environment file not found"
        exit 1
    fi
}

# Backup commands
cmd_backup_create() {
    print_header "💾 Creating System Backup"
    
    backup_dir="$RAYANPBX_ROOT/backups"
    mkdir -p "$backup_dir"
    
    timestamp=$(date +%Y%m%d_%H%M%S)
    backup_name="rayanpbx_full_${timestamp}"
    backup_path="$backup_dir/$backup_name"
    
    mkdir -p "$backup_path"
    
    # Backup database
    print_info "Backing up database..."
    cmd_database_backup > /dev/null 2>&1
    
    # Backup configuration files
    print_info "Backing up configuration..."
    cp "$RAYANPBX_ROOT/.env" "$backup_path/" 2>/dev/null
    cp -r /etc/asterisk "$backup_path/asterisk_config" 2>/dev/null
    
    # Create tarball
    print_info "Creating archive..."
    cd "$backup_dir"
    tar -czf "${backup_name}.tar.gz" "$backup_name"
    rm -rf "$backup_name"
    
    print_success "Backup created: $backup_dir/${backup_name}.tar.gz"
}

cmd_backup_list() {
    print_header "📋 Available Backups"
    
    backup_dir="$RAYANPBX_ROOT/backups"
    
    if [ ! -d "$backup_dir" ]; then
        print_warn "No backups found"
        exit 0
    fi
    
    ls -lh "$backup_dir"/*.tar.gz 2>/dev/null | awk '{print "  "$9" ("$5")"}'
    ls -lh "$backup_dir"/*.sql.gz 2>/dev/null | awk '{print "  "$9" ("$5")"}'
}

# PJSIP/Endpoint commands
cmd_endpoint_list() {
    print_header "📱 PJSIP Endpoints"
    
    sudo asterisk -rx "pjsip show endpoints"
}

cmd_endpoint_show() {
    local endpoint=$1
    
    print_header "📱 Endpoint Details: $endpoint"
    
    sudo asterisk -rx "pjsip show endpoint $endpoint"
}

cmd_endpoint_contacts() {
    print_header "📱 Registered Contacts"
    
    sudo asterisk -rx "pjsip show contacts"
}

cmd_endpoint_registrations() {
    print_header "📱 PJSIP Registrations"
    
    sudo asterisk -rx "pjsip show registrations"
}

cmd_endpoint_qualify() {
    local endpoint=$1
    
    print_header "📱 Qualifying Endpoint: $endpoint"
    
    sudo asterisk -rx "pjsip qualify $endpoint"
}

# Module commands
cmd_module_list() {
    print_header "📦 Loaded Modules"
    
    sudo asterisk -rx "module show"
}

cmd_module_reload() {
    local module=$1
    
    if [ -z "$module" ]; then
        print_info "Reloading all modules..."
        sudo asterisk -rx "module reload"
    else
        print_info "Reloading module: $module"
        sudo asterisk -rx "module reload $module"
    fi
    
    print_success "Module reload complete"
}

cmd_module_load() {
    local module=$1
    
    print_info "Loading module: $module"
    sudo asterisk -rx "module load $module"
}

cmd_module_unload() {
    local module=$1
    
    print_info "Unloading module: $module"
    sudo asterisk -rx "module unload $module"
}

# Context/Dialplan commands
cmd_context_show() {
    local context=${1:-}
    
    if [ -z "$context" ]; then
        print_header "📋 All Contexts"
        sudo asterisk -rx "dialplan show"
    else
        print_header "📋 Context: $context"
        sudo asterisk -rx "dialplan show $context"
    fi
}

# Log commands
cmd_log_view() {
    local log_type=${1:-full}
    
    print_header "📄 Viewing Log: $log_type"
    
    case "$log_type" in
        full)
            tail -f /var/log/asterisk/full
            ;;
        messages)
            tail -f /var/log/asterisk/messages
            ;;
        api)
            sudo journalctl -u rayanpbx-api -f
            ;;
        asterisk)
            sudo journalctl -u asterisk -f
            ;;
        *)
            print_error "Unknown log type: $log_type"
            echo "Available: full, messages, api, asterisk"
            exit 1
            ;;
    esac
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
                start) cmd_asterisk_start ;;
                stop) cmd_asterisk_stop ;;
                restart) cmd_asterisk_restart ;;
                reload) cmd_asterisk_reload ;;
                console) cmd_asterisk_console ;;
                command) cmd_asterisk_command "$3" ;;
                *) echo "Unknown asterisk command: $2"; exit 2 ;;
            esac
            ;;
        diag)
            case "$2" in
                test-extension) cmd_diag_test_extension "$3" ;;
                health-check) cmd_diag_health_check ;;
                channels) cmd_diag_channels ;;
                calls) cmd_diag_calls ;;
                version) cmd_diag_version ;;
                *) echo "Unknown diag command: $2"; exit 2 ;;
            esac
            ;;
        system)
            case "$2" in
                update) cmd_system_update ;;
                info) cmd_system_info ;;
                services) cmd_system_services ;;
                reload) cmd_system_reload ;;
                chown) cmd_system_chown ;;
                *) echo "Unknown system command: $2"; exit 2 ;;
            esac
            ;;
        database|db)
            case "$2" in
                mysql) cmd_database_mysql ;;
                info) cmd_database_info ;;
                backup) cmd_database_backup ;;
                restore) cmd_database_restore "$3" ;;
                *) echo "Unknown database command: $2"; exit 2 ;;
            esac
            ;;
        backup)
            case "$2" in
                create) cmd_backup_create ;;
                list) cmd_backup_list ;;
                *) echo "Unknown backup command: $2"; exit 2 ;;
            esac
            ;;
        endpoint|pjsip)
            case "$2" in
                list) cmd_endpoint_list ;;
                show) cmd_endpoint_show "$3" ;;
                contacts) cmd_endpoint_contacts ;;
                registrations) cmd_endpoint_registrations ;;
                qualify) cmd_endpoint_qualify "$3" ;;
                *) echo "Unknown endpoint command: $2"; exit 2 ;;
            esac
            ;;
        module)
            case "$2" in
                list) cmd_module_list ;;
                reload) cmd_module_reload "$3" ;;
                load) cmd_module_load "$3" ;;
                unload) cmd_module_unload "$3" ;;
                *) echo "Unknown module command: $2"; exit 2 ;;
            esac
            ;;
        context|dialplan)
            cmd_context_show "$2"
            ;;
        log)
            cmd_log_view "$2"
            ;;
        list)
            # Show available commands
            print_header "📋 Available Commands"
            echo -e "${CYAN}Extension Management:${NC}"
            echo "  extension list                    - List all extensions"
            echo "  extension create NUM NAME PASS    - Create new extension"
            echo "  extension status NUM              - Show extension status"
            echo
            echo -e "${CYAN}Trunk Management:${NC}"
            echo "  trunk list                        - List all trunks"
            echo "  trunk test NAME                   - Test trunk connectivity"
            echo
            echo -e "${CYAN}Asterisk Control:${NC}"
            echo "  asterisk status                   - Check Asterisk status"
            echo "  asterisk start                    - Start Asterisk"
            echo "  asterisk stop                     - Stop Asterisk"
            echo "  asterisk restart                  - Restart Asterisk"
            echo "  asterisk reload                   - Reload Asterisk config"
            echo "  asterisk console                  - Open Asterisk console"
            echo "  asterisk command CMD              - Execute Asterisk command"
            echo
            echo -e "${CYAN}Diagnostics:${NC}"
            echo "  diag test-extension NUM           - Test extension registration"
            echo "  diag health-check                 - Run system health check"
            echo "  diag channels                     - Show active channels"
            echo "  diag calls                        - Show active calls"
            echo "  diag version                      - Show all version info"
            echo
            echo -e "${CYAN}System Management:${NC}"
            echo "  system update                     - Update RayanPBX"
            echo "  system info                       - Show system information"
            echo "  system services                   - Show service status"
            echo "  system reload                     - Reload all services"
            echo "  system chown                      - Fix file permissions"
            echo
            echo -e "${CYAN}Database:${NC}"
            echo "  database mysql                    - Open MySQL console"
            echo "  database info                     - Show database info"
            echo "  database backup                   - Backup database"
            echo "  database restore FILE             - Restore database"
            echo
            echo -e "${CYAN}Backup & Restore:${NC}"
            echo "  backup create                     - Create full backup"
            echo "  backup list                       - List available backups"
            echo
            echo -e "${CYAN}PJSIP/Endpoints:${NC}"
            echo "  endpoint list                     - List all endpoints"
            echo "  endpoint show NUM                 - Show endpoint details"
            echo "  endpoint contacts                 - Show registered contacts"
            echo "  endpoint registrations            - Show registrations"
            echo "  endpoint qualify NUM              - Qualify an endpoint"
            echo
            echo -e "${CYAN}Module Management:${NC}"
            echo "  module list                       - List loaded modules"
            echo "  module reload [MODULE]            - Reload module(s)"
            echo "  module load MODULE                - Load a module"
            echo "  module unload MODULE              - Unload a module"
            echo
            echo -e "${CYAN}Dialplan/Context:${NC}"
            echo "  context show [CONTEXT]            - Show dialplan context"
            echo
            echo -e "${CYAN}Logs:${NC}"
            echo "  log view [TYPE]                   - View logs (full/messages/api/asterisk)"
            echo
            echo -e "${CYAN}Help:${NC}"
            echo "  list                              - Show this command list"
            echo "  help                              - Show detailed help"
            ;;
        help)
            # Show detailed help
            print_header "📚 RayanPBX CLI Help"
            echo
            echo -e "${YELLOW}${BOLD}USAGE:${NC}"
            echo -e "  rayanpbx-cli ${GREEN}<command>${NC} ${BLUE}[subcommand]${NC} ${MAGENTA}[arguments]${NC}"
            echo
            echo -e "${YELLOW}${BOLD}DESCRIPTION:${NC}"
            echo "  RayanPBX CLI is a comprehensive command-line interface for managing"
            echo "  your RayanPBX installation. It provides functionality similar to"
            echo "  FreePBX's fwconsole, with modern enhancements."
            echo
            echo -e "${YELLOW}${BOLD}GETTING STARTED:${NC}"
            echo "  Run 'rayanpbx-cli list' to see all available commands"
            echo
            echo -e "${YELLOW}${BOLD}EXAMPLES:${NC}"
            echo "  rayanpbx-cli extension list"
            echo "  rayanpbx-cli asterisk status"
            echo "  rayanpbx-cli diag health-check"
            echo "  rayanpbx-cli backup create"
            echo "  rayanpbx-cli system info"
            echo
            echo -e "${YELLOW}${BOLD}DOCUMENTATION:${NC}"
            echo "  GitHub: https://github.com/atomicdeploy/rayanpbx"
            ;;
        *)
            echo "Unknown command: $1"
            echo "Run 'rayanpbx-cli list' for available commands"
            echo "Run 'rayanpbx-cli help' for detailed information"
            exit 2
            ;;
    esac
}

main "$@"
