#!/bin/bash
#
# RayanPBX PAM Setup Script
#
# This script sets up PAM (Pluggable Authentication Modules) for RayanPBX
# It should be run with root privileges (sudo)
#
# Usage: sudo ./setup-pam.sh [options]
#
# Options:
#   --install     Install required packages and configure PAM
#   --uninstall   Remove RayanPBX PAM configuration
#   --status      Check PAM configuration status
#   --help        Show this help message

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration
PAM_SERVICE_NAME="rayanpbx"
PAM_SERVICE_FILE="/etc/pam.d/${PAM_SERVICE_NAME}"
RAYANPBX_USER="${RAYANPBX_USER:-www-data}"  # User that runs the web server

# Print colored message
print_msg() {
    local color=$1
    local msg=$2
    echo -e "${color}${msg}${NC}"
}

print_info() {
    print_msg "$BLUE" "[INFO] $1"
}

print_success() {
    print_msg "$GREEN" "[SUCCESS] $1"
}

print_warning() {
    print_msg "$YELLOW" "[WARNING] $1"
}

print_error() {
    print_msg "$RED" "[ERROR] $1"
}

# Check if running as root
check_root() {
    if [[ $EUID -ne 0 ]]; then
        print_error "This script must be run as root (use sudo)"
        exit 1
    fi
}

# Install required packages
install_packages() {
    print_info "Installing required packages..."
    
    # Detect package manager
    if command -v apt-get &> /dev/null; then
        apt-get update
        apt-get install -y pamtester
    elif command -v dnf &> /dev/null; then
        dnf install -y pamtester
    elif command -v yum &> /dev/null; then
        yum install -y pamtester
    elif command -v pacman &> /dev/null; then
        pacman -S --noconfirm pamtester
    else
        print_warning "Could not detect package manager. Please install 'pamtester' manually."
        return 1
    fi
    
    print_success "Packages installed successfully"
}

# Create PAM service configuration
create_pam_service() {
    print_info "Creating PAM service configuration..."
    
    # Create the PAM service file
    cat > "$PAM_SERVICE_FILE" << 'EOF'
#
# PAM configuration for RayanPBX
# This file is used for authenticating users against the system's user database
#

# Standard authentication using system accounts
auth    required        pam_unix.so nullok
auth    required        pam_permit.so

# Account management
account required        pam_unix.so

# Session management (optional, for logging)
session optional        pam_unix.so
EOF

    chmod 644 "$PAM_SERVICE_FILE"
    
    print_success "PAM service configuration created at ${PAM_SERVICE_FILE}"
}

# Configure shadow file access for the web server user
configure_shadow_access() {
    print_info "Configuring shadow file access..."
    
    # Check if shadow group exists
    if getent group shadow &> /dev/null; then
        # Add web server user to shadow group for reading /etc/shadow
        if id "$RAYANPBX_USER" &> /dev/null; then
            usermod -aG shadow "$RAYANPBX_USER"
            print_success "Added ${RAYANPBX_USER} to shadow group"
        else
            print_warning "User ${RAYANPBX_USER} not found. Skipping shadow group configuration."
        fi
    else
        print_warning "Shadow group not found. Shadow authentication may not work."
    fi
}

# Test PAM configuration
test_pam() {
    print_info "Testing PAM configuration..."
    
    # Check if pamtester is available
    if ! command -v pamtester &> /dev/null; then
        print_error "pamtester is not installed"
        return 1
    fi
    
    # Check if PAM service file exists
    if [[ ! -f "$PAM_SERVICE_FILE" ]]; then
        print_error "PAM service file not found at ${PAM_SERVICE_FILE}"
        return 1
    fi
    
    print_success "PAM configuration test passed"
    
    # Show instructions for manual testing
    echo ""
    print_info "To test PAM authentication manually, run:"
    echo "  pamtester ${PAM_SERVICE_NAME} <username> authenticate"
    echo ""
    print_info "You will be prompted for the password."
}

# Show status
show_status() {
    print_info "Checking PAM configuration status..."
    echo ""
    
    # Check pamtester
    if command -v pamtester &> /dev/null; then
        print_success "pamtester: installed ($(which pamtester))"
    else
        print_error "pamtester: not installed"
    fi
    
    # Check PAM service file
    if [[ -f "$PAM_SERVICE_FILE" ]]; then
        print_success "PAM service file: exists (${PAM_SERVICE_FILE})"
    else
        print_warning "PAM service file: not found (${PAM_SERVICE_FILE})"
    fi
    
    # Check shadow file readability by web server user
    if id "$RAYANPBX_USER" &> /dev/null; then
        if groups "$RAYANPBX_USER" | grep -q shadow; then
            print_success "Shadow group: ${RAYANPBX_USER} is member"
        else
            print_warning "Shadow group: ${RAYANPBX_USER} is NOT a member"
        fi
    else
        print_warning "Web server user ${RAYANPBX_USER} not found"
    fi
    
    echo ""
}

# Uninstall configuration
uninstall() {
    print_info "Removing RayanPBX PAM configuration..."
    
    # Remove PAM service file
    if [[ -f "$PAM_SERVICE_FILE" ]]; then
        rm -f "$PAM_SERVICE_FILE"
        print_success "Removed ${PAM_SERVICE_FILE}"
    else
        print_info "PAM service file not found, nothing to remove"
    fi
    
    # Remove user from shadow group
    if id "$RAYANPBX_USER" &> /dev/null; then
        if groups "$RAYANPBX_USER" | grep -q shadow; then
            gpasswd -d "$RAYANPBX_USER" shadow 2>/dev/null || true
            print_success "Removed ${RAYANPBX_USER} from shadow group"
        fi
    fi
    
    print_success "RayanPBX PAM configuration removed"
}

# Show help
show_help() {
    echo "RayanPBX PAM Setup Script"
    echo ""
    echo "Usage: sudo $0 [options]"
    echo ""
    echo "Options:"
    echo "  --install     Install required packages and configure PAM"
    echo "  --uninstall   Remove RayanPBX PAM configuration"
    echo "  --status      Check PAM configuration status"
    echo "  --help        Show this help message"
    echo ""
    echo "Environment Variables:"
    echo "  RAYANPBX_USER   User that runs the web server (default: www-data)"
    echo ""
    echo "Examples:"
    echo "  sudo $0 --install"
    echo "  sudo $0 --status"
    echo "  sudo RAYANPBX_USER=nginx $0 --install"
    echo ""
}

# Main
main() {
    case "${1:-}" in
        --install)
            check_root
            install_packages
            create_pam_service
            configure_shadow_access
            test_pam
            ;;
        --uninstall)
            check_root
            uninstall
            ;;
        --status)
            show_status
            ;;
        --help)
            show_help
            ;;
        "")
            print_error "No option specified"
            echo ""
            show_help
            exit 1
            ;;
        *)
            print_error "Unknown option: $1"
            echo ""
            show_help
            exit 1
            ;;
    esac
}

main "$@"
