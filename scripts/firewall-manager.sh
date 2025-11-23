#!/bin/bash

# RayanPBX Firewall Management
# Based on FreePBX firewall and IncrediblePBX security features

set -euo pipefail

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

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

# Check if running as root
check_root() {
    if [ "$EUID" -ne 0 ]; then
        print_error "This script must be run as root"
        exit 1
    fi
}

# Install UFW if not present
install_ufw() {
    if ! command -v ufw &> /dev/null; then
        print_info "Installing UFW..."
        apt-get update -qq
        apt-get install -y ufw
        print_success "UFW installed"
    fi
}

# Enable UFW firewall
firewall_enable() {
    print_info "Enabling firewall..."
    
    # Allow SSH first to prevent lockout
    ufw allow 22/tcp comment 'SSH'
    
    # Enable UFW
    echo "y" | ufw enable
    
    print_success "Firewall enabled"
}

# Disable UFW firewall
firewall_disable() {
    print_warn "Disabling firewall..."
    ufw disable
    print_success "Firewall disabled"
}

# Start/restart UFW
firewall_start() {
    print_info "Starting firewall..."
    ufw enable
    print_success "Firewall started"
}

# Stop UFW
firewall_stop() {
    print_info "Stopping firewall..."
    ufw disable
    print_success "Firewall stopped"
}

# Show firewall status
firewall_status() {
    echo -e "${CYAN}═══════════════════════════════════════${NC}"
    echo -e "${CYAN}  🔥 Firewall Status${NC}"
    echo -e "${CYAN}═══════════════════════════════════════${NC}"
    ufw status verbose
}

# Trust an IP address or network
firewall_trust() {
    local host=$1
    
    if [ -z "$host" ]; then
        print_error "Please specify an IP address or network"
        exit 1
    fi
    
    print_info "Adding $host to trusted zone..."
    ufw allow from "$host" comment "Trusted: $host"
    print_success "Host $host added to trusted zone"
}

# Untrust an IP address or network
firewall_untrust() {
    local host=$1
    
    if [ -z "$host" ]; then
        print_error "Please specify an IP address or network"
        exit 1
    fi
    
    print_info "Removing $host from trusted zone..."
    ufw delete allow from "$host"
    print_success "Host $host removed from trusted zone"
}

# List firewall rules
firewall_list() {
    echo -e "${CYAN}═══════════════════════════════════════${NC}"
    echo -e "${CYAN}  📋 Firewall Rules${NC}"
    echo -e "${CYAN}═══════════════════════════════════════${NC}"
    ufw status numbered
}

# Add a firewall rule
firewall_add() {
    local zone=$1
    local rule=$2
    
    case "$zone" in
        trusted)
            firewall_trust "$rule"
            ;;
        allow)
            print_info "Adding allow rule: $rule"
            ufw allow "$rule"
            print_success "Rule added"
            ;;
        deny)
            print_info "Adding deny rule: $rule"
            ufw deny "$rule"
            print_success "Rule added"
            ;;
        *)
            print_error "Unknown zone: $zone"
            echo "Available zones: trusted, allow, deny"
            exit 1
            ;;
    esac
}

# Delete a firewall rule
firewall_delete() {
    local rule_num=$1
    
    if [ -z "$rule_num" ]; then
        print_error "Please specify a rule number"
        exit 1
    fi
    
    print_info "Deleting rule $rule_num..."
    echo "y" | ufw delete "$rule_num"
    print_success "Rule deleted"
}

# Setup default PBX ports
firewall_setup_pbx() {
    print_info "Setting up default PBX firewall rules..."
    
    # Allow SSH
    ufw allow 22/tcp comment 'SSH'
    
    # Allow HTTP/HTTPS
    ufw allow 80/tcp comment 'HTTP'
    ufw allow 443/tcp comment 'HTTPS'
    
    # Allow RayanPBX services
    ufw allow 3000/tcp comment 'RayanPBX Web UI'
    ufw allow 8000/tcp comment 'RayanPBX API'
    ufw allow 9000/tcp comment 'RayanPBX WebSocket'
    
    # Allow Asterisk
    ufw allow 5060/udp comment 'SIP UDP'
    ufw allow 5060/tcp comment 'SIP TCP'
    ufw allow 5061/tcp comment 'SIP TLS'
    
    # Allow RTP (media) - standard range
    ufw allow 10000:20000/udp comment 'RTP Media'
    
    # Allow AMI (from localhost only)
    # ufw allow from 127.0.0.1 to any port 5038 proto tcp comment 'Asterisk AMI'
    
    print_success "Default PBX firewall rules configured"
}

# Reset firewall to defaults
firewall_reset() {
    print_warn "This will reset all firewall rules!"
    read -p "Are you sure? (yes/no): " confirm
    
    # Convert to lowercase for comparison
    confirm=$(echo "$confirm" | tr '[:upper:]' '[:lower:]')
    
    if [ "$confirm" != "yes" ] && [ "$confirm" != "y" ]; then
        print_info "Reset cancelled"
        exit 0
    fi
    
    print_info "Resetting firewall..."
    ufw --force reset
    
    print_info "Setting up default rules..."
    firewall_setup_pbx
    
    print_info "Enabling firewall..."
    echo "y" | ufw enable
    
    print_success "Firewall reset complete"
}

# Main function
main() {
    check_root
    install_ufw
    
    local command=${1:-}
    
    case "$command" in
        enable)
            firewall_enable
            ;;
        disable)
            firewall_disable
            ;;
        start)
            firewall_start
            ;;
        stop)
            firewall_stop
            ;;
        restart)
            firewall_stop
            sleep 1
            firewall_start
            ;;
        status)
            firewall_status
            ;;
        trust)
            firewall_trust "$2"
            ;;
        untrust)
            firewall_untrust "$2"
            ;;
        list)
            firewall_list
            ;;
        add)
            firewall_add "$2" "$3"
            ;;
        delete|del)
            firewall_delete "$2"
            ;;
        setup)
            firewall_setup_pbx
            ;;
        reset)
            firewall_reset
            ;;
        *)
            echo "RayanPBX Firewall Management"
            echo ""
            echo "Usage: $0 <command> [options]"
            echo ""
            echo "Commands:"
            echo "  enable              - Enable firewall"
            echo "  disable             - Disable firewall"
            echo "  start               - Start firewall"
            echo "  stop                - Stop firewall"
            echo "  restart             - Restart firewall"
            echo "  status              - Show firewall status"
            echo "  trust HOST          - Add host/IP to trusted zone"
            echo "  untrust HOST        - Remove host/IP from trusted zone"
            echo "  list                - List all firewall rules"
            echo "  add ZONE RULE       - Add a firewall rule (zones: trusted, allow, deny)"
            echo "  delete NUM          - Delete rule by number"
            echo "  setup               - Setup default PBX firewall rules"
            echo "  reset               - Reset firewall to defaults"
            echo ""
            echo "Examples:"
            echo "  $0 setup                      # Setup default PBX rules"
            echo "  $0 trust 192.168.1.0/24       # Trust local network"
            echo "  $0 add allow 8080/tcp         # Allow port 8080"
            echo "  $0 status                     # Show current status"
            exit 1
            ;;
    esac
}

main "$@"
