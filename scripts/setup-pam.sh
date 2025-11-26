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
CYAN='\033[0;36m'
MAGENTA='\033[0;35m'
WHITE='\033[1;37m'
BOLD='\033[1m'
DIM='\033[2m'
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

# Print elegant banner
print_banner() {
    echo ""
    echo -e "${CYAN}${BOLD}╔══════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${CYAN}${BOLD}║${NC}           ${WHITE}${BOLD}🔐 RayanPBX PAM Setup Script${NC}           ${CYAN}${BOLD}║${NC}"
    echo -e "${CYAN}${BOLD}╚══════════════════════════════════════════════════════════════╝${NC}"
    echo ""
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
    echo -e "${CYAN}${BOLD}📝 Manual Testing${NC}"
    echo -e "${DIM}To test PAM authentication manually, run:${NC}"
    echo -e "  ${WHITE}pamtester ${PAM_SERVICE_NAME} <username> authenticate${NC}"
    echo ""
    echo -e "${DIM}You will be prompted for the password.${NC}"
}

# Show status
show_status() {
    print_banner
    echo -e "${BOLD}${CYAN}📊 PAM Configuration Status${NC}"
    echo ""
    
    # Check pamtester
    echo -ne "  ${DIM}pamtester:${NC} "
    if command -v pamtester &> /dev/null; then
        echo -e "${GREEN}✓ installed${NC} ${DIM}($(which pamtester))${NC}"
    else
        echo -e "${RED}✗ not installed${NC}"
    fi
    
    # Check PAM service file
    echo -ne "  ${DIM}PAM service file:${NC} "
    if [[ -f "$PAM_SERVICE_FILE" ]]; then
        echo -e "${GREEN}✓ exists${NC} ${DIM}(${PAM_SERVICE_FILE})${NC}"
    else
        echo -e "${YELLOW}⚠ not found${NC} ${DIM}(${PAM_SERVICE_FILE})${NC}"
    fi
    
    # Check shadow file readability by web server user
    echo -ne "  ${DIM}Shadow group membership:${NC} "
    if id "$RAYANPBX_USER" &> /dev/null; then
        if groups "$RAYANPBX_USER" | grep -q shadow; then
            echo -e "${GREEN}✓ ${RAYANPBX_USER} is member${NC}"
        else
            echo -e "${YELLOW}⚠ ${RAYANPBX_USER} is NOT a member${NC}"
        fi
    else
        echo -e "${YELLOW}⚠ User ${RAYANPBX_USER} not found${NC}"
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
    print_banner
    
    echo -e "${BOLD}${WHITE}DESCRIPTION${NC}"
    echo -e "  ${DIM}This script sets up PAM (Pluggable Authentication Modules) for RayanPBX,${NC}"
    echo -e "  ${DIM}enabling Linux user account authentication for the web interface.${NC}"
    echo ""
    
    echo -e "${BOLD}${WHITE}USAGE${NC}"
    echo -e "  ${CYAN}sudo${NC} ${WHITE}$0${NC} ${GREEN}[option]${NC}"
    echo ""
    
    echo -e "${BOLD}${WHITE}OPTIONS${NC}"
    echo -e "  ${GREEN}--install${NC}     ${DIM}Install required packages and configure PAM${NC}"
    echo -e "  ${GREEN}--uninstall${NC}   ${DIM}Remove RayanPBX PAM configuration${NC}"
    echo -e "  ${GREEN}--status${NC}      ${DIM}Check PAM configuration status${NC}"
    echo -e "  ${GREEN}--help${NC}        ${DIM}Show this help message${NC}"
    echo ""
    
    echo -e "${BOLD}${WHITE}ENVIRONMENT VARIABLES${NC}"
    echo -e "  ${YELLOW}RAYANPBX_USER${NC}   ${DIM}User that runs the web server${NC}"
    echo -e "                  ${DIM}Default: ${WHITE}www-data${NC}"
    echo ""
    
    echo -e "${BOLD}${WHITE}EXAMPLES${NC}"
    echo -e "  ${DIM}# Install PAM configuration${NC}"
    echo -e "  ${CYAN}sudo${NC} ${WHITE}$0${NC} ${GREEN}--install${NC}"
    echo ""
    echo -e "  ${DIM}# Check current status${NC}"
    echo -e "  ${CYAN}sudo${NC} ${WHITE}$0${NC} ${GREEN}--status${NC}"
    echo ""
    echo -e "  ${DIM}# Install with custom web server user${NC}"
    echo -e "  ${CYAN}sudo${NC} ${YELLOW}RAYANPBX_USER${NC}=${WHITE}nginx${NC} ${WHITE}$0${NC} ${GREEN}--install${NC}"
    echo ""
    
    echo -e "${BOLD}${WHITE}NOTES${NC}"
    echo -e "  ${DIM}• This script must be run with root privileges (sudo)${NC}"
    echo -e "  ${DIM}• PAM authentication allows users to log in with their Linux credentials${NC}"
    echo -e "  ${DIM}• The web server user needs shadow group access to verify passwords${NC}"
    echo ""
}

# Main
main() {
    case "${1:-}" in
        --install)
            print_banner
            check_root
            install_packages
            create_pam_service
            configure_shadow_access
            test_pam
            ;;
        --uninstall)
            print_banner
            check_root
            uninstall
            ;;
        --status)
            show_status
            ;;
        --help|-h)
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
