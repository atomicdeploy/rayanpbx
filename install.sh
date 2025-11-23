#!/bin/bash

set -e

# RayanPBX Installation Script for Ubuntu 24.04 LTS
# This script installs and configures RayanPBX with Asterisk 22

# Script version
readonly SCRIPT_VERSION="2.0.0"

# ════════════════════════════════════════════════════════════════════════
# Configuration Variables
# ════════════════════════════════════════════════════════════════════════

VERBOSE=false
DRY_RUN=false

# ════════════════════════════════════════════════════════════════════════
# ANSI Color Codes & Emojis
# ════════════════════════════════════════════════════════════════════════

# Colors
readonly RED='\033[0;31m'
readonly GREEN='\033[0;32m'
readonly YELLOW='\033[1;33m'
readonly BLUE='\033[0;34m'
readonly MAGENTA='\033[0;35m'
readonly CYAN='\033[0;36m'
readonly WHITE='\033[1;37m'
readonly BOLD='\033[1m'
readonly DIM='\033[2m'
readonly RESET='\033[0m'

# Background colors
readonly BG_RED='\033[41m'
readonly BG_GREEN='\033[42m'
readonly BG_YELLOW='\033[43m'
readonly BG_BLUE='\033[44m'

# Cursor control
readonly CURSOR_UP='\033[1A'
readonly CURSOR_DOWN='\033[1B'
readonly CLEAR_LINE='\033[2K'

# Step counter
STEP_NUMBER=0

# ════════════════════════════════════════════════════════════════════════
# Usage and Help Functions
# ════════════════════════════════════════════════════════════════════════

show_version() {
    echo -e "${CYAN}${BOLD}RayanPBX Installation Script${RESET} ${GREEN}v${SCRIPT_VERSION}${RESET}"
    echo -e "${DIM}For Ubuntu 24.04 LTS${RESET}"
    exit 0
}

show_help() {
    echo -e "${CYAN}${BOLD}RayanPBX Installation Script${RESET} ${GREEN}v${SCRIPT_VERSION}${RESET}"
    echo ""
    echo -e "${YELLOW}${BOLD}USAGE:${RESET}"
    echo -e "    ${WHITE}sudo ./install.sh [OPTIONS]${RESET}"
    echo ""
    echo -e "${YELLOW}${BOLD}DESCRIPTION:${RESET}"
    echo -e "    Installs and configures RayanPBX with Asterisk 22, including all"
    echo -e "    required dependencies (MariaDB, PHP 8.3, Node.js 24, Go 1.23)."
    echo ""
    echo -e "${YELLOW}${BOLD}OPTIONS:${RESET}"
    echo -e "    ${GREEN}-h, --help${RESET}          Show this help message and exit"
    echo -e "    ${GREEN}-v, --verbose${RESET}       Enable verbose output (shows detailed execution)"
    echo -e "    ${GREEN}-V, --version${RESET}       Show script version and exit"
    echo -e "    ${GREEN}--dry-run${RESET}           Simulate installation without making changes (not yet implemented)"
    echo ""
    echo -e "${YELLOW}${BOLD}REQUIREMENTS:${RESET}"
    echo -e "    ${CYAN}•${RESET} Ubuntu 24.04 LTS (recommended)"
    echo -e "    ${CYAN}•${RESET} Root privileges (run with sudo)"
    echo -e "    ${CYAN}•${RESET} Internet connection"
    echo -e "    ${CYAN}•${RESET} At least 4GB RAM"
    echo -e "    ${CYAN}•${RESET} At least 10GB free disk space"
    echo ""
    echo -e "${YELLOW}${BOLD}EXAMPLES:${RESET}"
    echo -e "    ${DIM}# Standard installation${RESET}"
    echo -e "    ${WHITE}sudo ./install.sh${RESET}"
    echo ""
    echo -e "    ${DIM}# Verbose installation (helpful for debugging)${RESET}"
    echo -e "    ${WHITE}sudo ./install.sh --verbose${RESET}"
    echo ""
    echo -e "    ${DIM}# Show version${RESET}"
    echo -e "    ${WHITE}./install.sh --version${RESET}"
    echo ""
    echo -e "${YELLOW}${BOLD}DOCUMENTATION:${RESET}"
    echo -e "    ${BLUE}GitHub:${RESET}  https://github.com/atomicdeploy/rayanpbx"
    echo -e "    ${BLUE}Issues:${RESET}  https://github.com/atomicdeploy/rayanpbx/issues"
    echo ""
    exit 0
}

# ════════════════════════════════════════════════════════════════════════
# Helper Functions
# ════════════════════════════════════════════════════════════════════════

print_verbose() {
    if [ "$VERBOSE" = true ]; then
        echo -e "${DIM}[VERBOSE] $1${RESET}"
    fi
}

print_banner() {
    clear
    print_verbose "Displaying banner..."
    
    if command -v figlet &> /dev/null; then
        print_verbose "figlet found, checking for slant font..."
        # Try to use figlet with slant font, but fall back gracefully
        if figlet -f slant "RayanPBX" > /dev/null 2>&1; then
            if command -v lolcat &> /dev/null; then
                print_verbose "Using figlet with lolcat"
                figlet -f slant "RayanPBX" | lolcat
            else
                print_verbose "Using figlet without lolcat"
                echo -e "${CYAN}$(figlet -f slant "RayanPBX")${RESET}"
            fi
        else
            print_verbose "slant font not available, trying default font..."
            # Try default font if slant is not available
            if figlet "RayanPBX" > /dev/null 2>&1; then
                if command -v lolcat &> /dev/null; then
                    figlet "RayanPBX" | lolcat
                else
                    echo -e "${CYAN}$(figlet "RayanPBX")${RESET}"
                fi
            else
                print_verbose "figlet failed, using fallback banner"
                # If figlet fails completely, use fallback
                echo -e "${CYAN}${BOLD}"
                echo "╔══════════════════════════════════════════╗"
                echo "║                                          ║"
                echo "║        🚀  RayanPBX Installer  🚀        ║"
                echo "║                                          ║"
                echo "║   Modern SIP Server Management Suite    ║"
                echo "║                                          ║"
                echo "╚══════════════════════════════════════════╝"
                echo -e "${RESET}"
            fi
        fi
    else
        print_verbose "figlet not found, using fallback banner"
        echo -e "${CYAN}${BOLD}"
        echo "╔══════════════════════════════════════════╗"
        echo "║                                          ║"
        echo "║        🚀  RayanPBX Installer  🚀        ║"
        echo "║                                          ║"
        echo "║   Modern SIP Server Management Suite    ║"
        echo "║                                          ║"
        echo "╚══════════════════════════════════════════╝"
        echo -e "${RESET}"
    fi
    echo -e "${DIM}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${RESET}\n"
}

next_step() {
    STEP_NUMBER=$((STEP_NUMBER + 1))
    echo -e "\n${BLUE}${BOLD}┌─ Step ${STEP_NUMBER}: $1${RESET}"
    echo -e "${DIM}└─────────────────────────────────────────────────────────────${RESET}"
}

print_success() {
    echo -e "${GREEN}✅ $1${RESET}"
}

print_info() {
    echo -e "${CYAN}🔧 $1${RESET}"
}

print_error() {
    echo -e "${RED}${BOLD}❌ $1${RESET}"
}

print_warning() {
    echo -e "${YELLOW}⚠️  $1${RESET}"
}

print_progress() {
    echo -e "${MAGENTA}⏳ $1${RESET}"
}

print_cmd() {
    echo -e "${DIM}   $ $1${RESET}"
}

handle_asterisk_error() {
    local error_msg="$1"
    local context="${2:-Asterisk operation}"
    
    print_error "$context failed"
    print_warning "Error: $error_msg"
    echo ""
    echo -e "${CYAN}🔍 Checking for solutions...${RESET}"
    echo ""
    
    # URL encode the error message using sed for special characters
    local encoded_query=$(printf '%s' "$error_msg $context" | sed 's/ /%20/g; s/!/%21/g; s/"/%22/g; s/#/%23/g; s/\$/%24/g; s/&/%26/g; s/'\''/%27/g; s/(/%28/g; s/)/%29/g; s/\*/%2A/g; s/+/%2B/g; s/,/%2C/g; s/:/%3A/g; s/;/%3B/g; s/=/%3D/g; s/?/%3F/g; s/@/%40/g; s/\[/%5B/g; s/\]/%5D/g')
    
    # Automatically fetch solution using GET request
    local solution=$(curl -s "https://text.pollinations.ai/${encoded_query}" 2>/dev/null | head -c 500)
    
    if [ -n "$solution" ]; then
        echo -e "${YELLOW}${BOLD}💡 Suggested solution:${RESET}"
        echo -e "${DIM}${solution}${RESET}"
        echo ""
    else
        echo -e "${DIM}Could not retrieve solution automatically. Check your internet connection.${RESET}"
        echo ""
    fi
}

print_box() {
    local text="$1"
    local color="${2:-$CYAN}"
    local length=${#text}
    local border=$(printf '─%.0s' $(seq 1 $((length + 4))))
    
    echo -e "${color}"
    echo "┌${border}┐"
    echo "│  ${text}  │"
    echo "└${border}┘"
    echo -e "${RESET}"
}

spinner() {
    local pid=$1
    local delay=0.1
    local spinstr='⠋⠙⠹⠸⠼⠴⠦⠧⠇⠏'
    while ps -p $pid > /dev/null 2>&1; do
        local temp=${spinstr#?}
        printf " [%c]  " "$spinstr"
        local spinstr=$temp${spinstr%"$temp"}
        sleep $delay
        printf "\b\b\b\b\b\b"
    done
    printf "    \b\b\b\b"
}

check_installed() {
    local package="$1"
    local name="${2:-$package}"
    
    if command -v "$package" &> /dev/null; then
        print_success "$name already installed: $(command -v $package)"
        return 0
    else
        print_info "$name not found, will install"
        return 1
    fi
}

download_file() {
    local url="$1"
    local output_file="$2"
    local show_progress="${3:-false}"
    
    print_verbose "Downloading: $url"
    print_verbose "Output file: $output_file"
    
    # Check if aria2c is available
    if command -v aria2c &> /dev/null; then
        print_verbose "Using aria2c for download"
        
        # aria2c parameters:
        # -R: retry on errors
        # -c: continue downloading partially downloaded file
        # -s 16: split file into 16 pieces for parallel download
        # -x 16: maximum connections per server
        # -k 1M: minimum split size (1 megabyte)
        # -j 1: maximum concurrent downloads (1 since we're downloading one file)
        # -d: directory to save the file
        # -o: output filename
        
        local dir="$(dirname "$output_file")"
        local filename="$(basename "$output_file")"
        local aria2c_opts="-R -c -s 16 -x 16 -k 1M -j 1"
        
        if [ "$show_progress" = true ] || [ "$VERBOSE" = true ]; then
            aria2c $aria2c_opts -d "$dir" -o "$filename" "$url"
        else
            aria2c $aria2c_opts -d "$dir" -o "$filename" "$url" > /dev/null 2>&1
        fi
        
        return $?
    else
        print_verbose "aria2c not available, falling back to wget"
        
        # Fallback to wget
        if [ "$show_progress" = true ]; then
            wget --show-progress -O "$output_file" "$url"
        elif [ "$VERBOSE" = true ]; then
            wget -O "$output_file" "$url"
        else
            wget -q -O "$output_file" "$url"
        fi
        
        return $?
    fi
}

# ════════════════════════════════════════════════════════════════════════
# Error Handler (for verbose mode)
# ════════════════════════════════════════════════════════════════════════

error_handler() {
    local line_number=$1
    local command="$2"
    print_error "Script failed at line $line_number"
    if [ "$VERBOSE" = true ]; then
        print_error "Failed command: $command"
    fi
    exit 1
}

# ════════════════════════════════════════════════════════════════════════
# Parse Command Line Arguments
# ════════════════════════════════════════════════════════════════════════

while [[ $# -gt 0 ]]; do
    case $1 in
        -h|--help)
            show_help
            ;;
        -v|--verbose)
            VERBOSE=true
            shift
            ;;
        -V|--version)
            show_version
            ;;
        --dry-run)
            DRY_RUN=true
            echo "Dry-run mode enabled (not yet fully implemented)"
            shift
            ;;
        *)
            echo "Unknown option: $1"
            echo "Use --help for usage information"
            exit 1
            ;;
    esac
done

# Set up error trap after parsing arguments
if [ "$VERBOSE" = true ]; then
    # Use -E to inherit ERR trap in functions, command substitutions, and subshells
    set -eE
    trap 'error_handler ${LINENO} "$BASH_COMMAND"' ERR
    print_verbose "Verbose mode enabled"
fi

# ════════════════════════════════════════════════════════════════════════
# Main Installation
# ════════════════════════════════════════════════════════════════════════

print_verbose "Starting RayanPBX installation script v${SCRIPT_VERSION}"
print_verbose "System: $(uname -a)"
print_verbose "User: $(whoami)"

print_banner

# Check if running as root
print_verbose "Checking if running as root (EUID: $EUID)..."
if [[ $EUID -ne 0 ]]; then
   print_error "This script must be run as root"
   echo -e "${YELLOW}💡 Please run: ${WHITE}sudo $0${RESET}"
   exit 1
fi
print_verbose "Root check passed"

# Check for git updates
next_step "Checking for Updates"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
print_verbose "Script directory: $SCRIPT_DIR"

if [ -d "$SCRIPT_DIR/.git" ]; then
    print_verbose "Git repository detected, checking for updates..."
    cd "$SCRIPT_DIR"
    
    # Get current branch name
    CURRENT_BRANCH=$(git rev-parse --abbrev-ref HEAD 2>/dev/null)
    print_verbose "Current branch: $CURRENT_BRANCH"
    
    # Fetch the latest changes without merging
    print_progress "Fetching latest updates from repository..."
    FETCH_SUCCESS=true
    if ! git fetch origin >/dev/null 2>&1; then
        print_verbose "Fetch failed or had warnings, skipping update check"
        print_info "Continuing with current version (fetch failed)"
        FETCH_SUCCESS=false
    else
        print_verbose "Fetch completed successfully"
    fi
    
    # Only check for updates if fetch was successful
    if [ "$FETCH_SUCCESS" = true ]; then
        # Get current and remote commit hashes
        LOCAL_COMMIT=$(git rev-parse HEAD 2>/dev/null)
        
        # Try to get remote commit for current branch, fallback to main if that doesn't exist
        REMOTE_COMMIT=""
        if git rev-parse origin/$CURRENT_BRANCH >/dev/null 2>&1; then
            REMOTE_COMMIT=$(git rev-parse origin/$CURRENT_BRANCH 2>/dev/null)
            print_verbose "Checking against remote branch: origin/$CURRENT_BRANCH"
        elif git rev-parse origin/main >/dev/null 2>&1; then
            REMOTE_COMMIT=$(git rev-parse origin/main 2>/dev/null)
            print_verbose "Checking against remote branch: origin/main"
        else
            print_verbose "Could not find remote branch, skipping update check"
            REMOTE_COMMIT="$LOCAL_COMMIT"
        fi
        
        print_verbose "Local commit: $LOCAL_COMMIT"
        print_verbose "Remote commit: $REMOTE_COMMIT"
        
        if [ -n "$REMOTE_COMMIT" ] && [ "$LOCAL_COMMIT" != "$REMOTE_COMMIT" ]; then
            print_warning "Updates available for RayanPBX!"
            echo ""
            print_info "Changelog:"
            git log --oneline "$LOCAL_COMMIT".."$REMOTE_COMMIT" 2>/dev/null | head -5 || echo "  (changelog unavailable)"
            echo ""
            
            read -p "$(echo -e ${CYAN}Pull updates and restart installation? \(y/n\) ${RESET})" -n 1 -r
            echo
            if [[ $REPLY =~ ^[Yy]$ ]]; then
                print_progress "Pulling latest updates..."
                
                # Determine which branch to pull from
                PULL_BRANCH="main"
                if git rev-parse origin/$CURRENT_BRANCH >/dev/null 2>&1; then
                    PULL_BRANCH="$CURRENT_BRANCH"
                fi
                
                if git pull origin $PULL_BRANCH; then
                    print_success "Updates pulled successfully"
                    print_info "Restarting installation with latest version..."
                    echo ""
                    sleep 2
                    
                    # Re-execute the script with the same arguments
                    # Using exec replaces the current process entirely, ensuring the new version runs
                    # This is intentional - we want a clean restart with the updated script
                    exec "$0" "$@"
                else
                    print_error "Failed to pull updates"
                    print_warning "Continuing with current version..."
                fi
            else
                print_info "Continuing with current version..."
            fi
        else
            print_success "Already running the latest version"
        fi
    fi
else
    print_verbose "Not a git repository, skipping update check"
fi

# Check Ubuntu version
next_step "System Verification"
print_verbose "Checking Ubuntu version..."
print_verbose "Reading OS release information..."
if [ "$VERBOSE" = true ]; then
    head -n 5 /etc/os-release
fi

if ! grep -q "24.04" /etc/os-release; then
    print_warning "This script is designed for Ubuntu 24.04 LTS"
    echo -e "${YELLOW}Your version: $(lsb_release -d | cut -f2)${RESET}"
    read -p "Continue anyway? (y/n) " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        print_verbose "User chose not to continue on non-24.04 system"
        exit 1
    fi
else
    print_success "Ubuntu 24.04 LTS detected"
fi
print_verbose "System verification complete"

# Install nala if not present
next_step "Package Manager Setup"
print_verbose "Checking for nala package manager..."
if ! command -v nala &> /dev/null; then
    print_progress "Installing nala package manager..."
    print_verbose "Running apt-get update..."
    if ! apt-get update -qq 2>&1; then
        print_error "Failed to update package lists"
        print_warning "Check your internet connection and repository configuration"
        if [ "$VERBOSE" = true ]; then
            print_verbose "Running apt-get update with full output for diagnosis..."
            apt-get update
        fi
        exit 1
    fi
    print_verbose "apt-get update completed successfully"
    
    print_verbose "Installing nala package..."
    if ! apt-get install -y nala > /dev/null 2>&1; then
        print_error "Failed to install nala package manager"
        print_warning "Falling back to apt-get for remaining operations"
        if [ "$VERBOSE" = true ]; then
            print_verbose "Attempting nala install with full output..."
            apt-get install -y nala
        fi
        # Don't exit, just use apt-get instead
    else
        print_success "nala installed"
        print_verbose "nala version: $(nala --version 2>&1 | head -n 1 || echo 'unable to determine')"
    fi
else
    print_success "nala already installed"
    print_verbose "nala version: $(nala --version 2>&1 | head -n 1 || echo 'unable to determine')"
fi

# System update
next_step "System Update"
print_progress "Updating package lists and upgrading system..."

# Determine which package manager to use
PKG_MGR="nala"
if ! command -v nala &> /dev/null; then
    PKG_MGR="apt-get"
fi
print_verbose "Using package manager: $PKG_MGR"

print_verbose "Running $PKG_MGR update..."
if [ "$VERBOSE" = true ]; then
    # Show output in verbose mode
    if ! $PKG_MGR update; then
        print_error "Failed to update package lists"
        print_warning "This may cause issues with package installation"
        print_warning "Check your internet connection and /etc/apt/sources.list"
        echo -e "${YELLOW}Continue anyway? (y/n)${RESET}"
        read -n 1 -r
        echo
        if [[ ! $REPLY =~ ^[Yy]$ ]]; then
            exit 1
        fi
    fi
else
    # Hide output in normal mode
    if ! $PKG_MGR update > /dev/null 2>&1; then
        print_error "Failed to update package lists"
        print_warning "This may cause issues with package installation"
        print_warning "Check your internet connection and /etc/apt/sources.list"
        echo -e "${YELLOW}Continue anyway? (y/n)${RESET}"
        read -n 1 -r
        echo
        if [[ ! $REPLY =~ ^[Yy]$ ]]; then
            exit 1
        fi
    fi
fi

print_verbose "Running $PKG_MGR upgrade..."
if [ "$VERBOSE" = true ]; then
    if ! $PKG_MGR upgrade -y; then
        print_warning "System upgrade encountered issues but will continue"
    fi
else
    if ! $PKG_MGR upgrade -y > /dev/null 2>&1; then
        print_warning "System upgrade encountered issues but will continue"
    fi
fi
print_success "System updated"

# Install dependencies
next_step "Essential Dependencies"
PACKAGES=(
    software-properties-common
    curl
    wget
    aria2
    git
    build-essential
    libncurses5-dev
    libssl-dev
    libxml2-dev
    libsqlite3-dev
    uuid-dev
    libjansson-dev
    pkg-config
    figlet
    lolcat
    redis-server
    cron
    apache2
    libapache2-mod-php8.3
)

print_info "Installing essential packages..."
print_verbose "Package list: ${PACKAGES[*]}"

for package in "${PACKAGES[@]}"; do
    print_verbose "Checking package: $package"
    if ! dpkg -l | grep -q "^ii  $package "; then
        echo -e "${DIM}   Installing $package...${RESET}"
        print_verbose "Running: $PKG_MGR install -y $package"
        
        if [ "$VERBOSE" = true ]; then
            if ! $PKG_MGR install -y "$package"; then
                print_error "Failed to install $package"
                print_warning "Some features may not work without $package"
                # Continue with other packages
            else
                print_success "✓ $package"
            fi
        else
            if ! $PKG_MGR install -y "$package" > /dev/null 2>&1; then
                print_error "Failed to install $package"
                print_warning "Some features may not work without $package"
                # Continue with other packages
            else
                print_success "✓ $package"
            fi
        fi
    else
        echo -e "${DIM}   ✓ $package (already installed)${RESET}"
        print_verbose "$package is already installed"
    fi
done

# Install GitHub CLI
next_step "GitHub CLI Installation"
print_verbose "Checking for GitHub CLI..."
if ! check_installed "gh" "GitHub CLI"; then
    print_progress "Installing GitHub CLI..."
    print_verbose "Downloading GitHub CLI keyring..."
    if curl -fsSL https://cli.github.com/packages/githubcli-archive-keyring.gpg | dd of=/usr/share/keyrings/githubcli-archive-keyring.gpg 2>/dev/null; then
        chmod go+r /usr/share/keyrings/githubcli-archive-keyring.gpg
        print_verbose "Adding GitHub CLI repository..."
        echo "deb [arch=$(dpkg --print-architecture) signed-by=/usr/share/keyrings/githubcli-archive-keyring.gpg] https://cli.github.com/packages stable main" | tee /etc/apt/sources.list.d/github-cli.list > /dev/null
        print_verbose "Updating package lists..."
        $PKG_MGR update > /dev/null 2>&1
        print_verbose "Installing gh package..."
        if ! $PKG_MGR install -y gh > /dev/null 2>&1; then
            print_warning "Failed to install GitHub CLI (optional)"
            if [ "$VERBOSE" = true ]; then
                print_verbose "Attempting with full output..."
                $PKG_MGR install -y gh
            fi
        else
            print_success "GitHub CLI installed"
            print_verbose "GitHub CLI version: $(gh --version | head -n 1)"
        fi
    else
        print_warning "Failed to download GitHub CLI keyring (optional)"
    fi
fi

# MySQL/MariaDB Installation
next_step "Database Setup (MySQL/MariaDB)"
print_verbose "Checking for MySQL/MariaDB..."
if ! command -v mysql &> /dev/null; then
    print_progress "Installing MariaDB..."
    print_verbose "Installing mariadb-server and mariadb-client..."
    
    if [ "$VERBOSE" = true ]; then
        if ! $PKG_MGR install -y mariadb-server mariadb-client; then
            print_error "Failed to install MariaDB"
            print_warning "Database is required for RayanPBX to function"
            exit 1
        fi
    else
        if ! $PKG_MGR install -y mariadb-server mariadb-client > /dev/null 2>&1; then
            print_error "Failed to install MariaDB"
            print_warning "Database is required for RayanPBX to function"
            exit 1
        fi
    fi
    
    print_verbose "Enabling MariaDB service..."
    systemctl enable mariadb
    print_verbose "Starting MariaDB service..."
    systemctl start mariadb
    print_success "MariaDB installed and started"
    
    print_verbose "Checking MariaDB service status..."
    if [ "$VERBOSE" = true ]; then
        systemctl status mariadb --no-pager | head -n 10
    fi
    
    # Check if MySQL is already secured
    print_verbose "Checking if MySQL root access requires password..."
    if mysql -u root -e "SELECT 1" &> /dev/null; then
        print_warning "MySQL root has no password - securing now..."
        echo -e "${YELLOW}Please set a secure MySQL root password${RESET}"
        
        while true; do
            read -sp "$(echo -e ${CYAN}Enter new MySQL root password: ${RESET})" MYSQL_ROOT_PASSWORD
            echo
            read -sp "$(echo -e ${CYAN}Confirm MySQL root password: ${RESET})" MYSQL_ROOT_PASSWORD_CONFIRM
            echo
            
            if [ "$MYSQL_ROOT_PASSWORD" == "$MYSQL_ROOT_PASSWORD_CONFIRM" ]; then
                if [ -z "$MYSQL_ROOT_PASSWORD" ]; then
                    print_warning "Password cannot be empty"
                    continue
                fi
                break
            else
                print_error "Passwords do not match!"
            fi
        done
        
        print_progress "Securing MySQL installation..."
        print_verbose "Setting root password and removing test databases..."
        mysql -u root <<EOF
ALTER USER 'root'@'localhost' IDENTIFIED BY '$MYSQL_ROOT_PASSWORD';
DELETE FROM mysql.user WHERE User='';
DELETE FROM mysql.user WHERE User='root' AND Host NOT IN ('localhost', '127.0.0.1', '::1');
DROP DATABASE IF EXISTS test;
DELETE FROM mysql.db WHERE Db='test' OR Db='test\\_%';
FLUSH PRIVILEGES;
EOF
        print_success "MySQL secured"
    else
        print_info "MySQL already secured"
        print_verbose "MySQL root access requires password"
        read -sp "$(echo -e ${CYAN}Enter MySQL root password: ${RESET})" MYSQL_ROOT_PASSWORD
        echo
    fi
else
    print_success "MySQL/MariaDB already installed"
    print_verbose "MySQL version: $(mysql --version)"
    read -sp "$(echo -e ${CYAN}Enter MySQL root password: ${RESET})" MYSQL_ROOT_PASSWORD
    echo
fi

# Create RayanPBX database
print_progress "Creating RayanPBX database..."
print_verbose "Generating random database password..."
ESCAPED_DB_PASSWORD=$(openssl rand -hex 16)
print_verbose "Database password generated (random hex string)"

print_verbose "Creating database and user..."
# Use mysql --defaults-extra-file for secure password passing
MYSQL_TMP_CNF=$(mktemp)
cat > "$MYSQL_TMP_CNF" <<EOF
[client]
user=root
password=$MYSQL_ROOT_PASSWORD
EOF
chmod 600 "$MYSQL_TMP_CNF"

if mysql --defaults-extra-file="$MYSQL_TMP_CNF" <<EOSQL
CREATE DATABASE IF NOT EXISTS rayanpbx CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'rayanpbx'@'localhost' IDENTIFIED BY '$ESCAPED_DB_PASSWORD';
ALTER USER 'rayanpbx'@'localhost' IDENTIFIED BY '$ESCAPED_DB_PASSWORD';
GRANT ALL PRIVILEGES ON rayanpbx.* TO 'rayanpbx'@'localhost';
FLUSH PRIVILEGES;
EOSQL
then
    print_success "Database 'rayanpbx' created"
    print_verbose "Database user 'rayanpbx' created with privileges"
    rm -f "$MYSQL_TMP_CNF"
else
    print_error "Failed to create database"
    print_warning "Check your MySQL root password and database access"
    if [ "$VERBOSE" = true ]; then
        print_verbose "Attempting to verify MySQL connection..."
        mysql --defaults-extra-file="$MYSQL_TMP_CNF" -e "SHOW DATABASES;" 2>&1 | head -n 10
    fi
    rm -f "$MYSQL_TMP_CNF"
    exit 1
fi

# PHP 8.3 Installation
next_step "PHP 8.3 Installation"
print_verbose "Checking for PHP 8.3..."
if ! command -v php &> /dev/null || ! php -v | grep -q "8.3"; then
    print_progress "Installing PHP 8.3 and extensions..."
    print_verbose "Installing PHP 8.3 packages..."
    
    if [ "$VERBOSE" = true ]; then
        if ! $PKG_MGR install -y \
            php8.3 \
            php8.3-cli \
            php8.3-fpm \
            php8.3-mysql \
            php8.3-xml \
            php8.3-mbstring \
            php8.3-curl \
            php8.3-zip \
            php8.3-gd \
            php8.3-bcmath \
            php8.3-redis; then
            print_error "Failed to install PHP 8.3"
            print_warning "PHP is required for the backend API"
            exit 1
        fi
    else
        if ! $PKG_MGR install -y \
            php8.3 \
            php8.3-cli \
            php8.3-fpm \
            php8.3-mysql \
            php8.3-xml \
            php8.3-mbstring \
            php8.3-curl \
            php8.3-zip \
            php8.3-gd \
            php8.3-bcmath \
            php8.3-redis > /dev/null 2>&1; then
            print_error "Failed to install PHP 8.3"
            print_warning "PHP is required for the backend API"
            exit 1
        fi
    fi
    print_success "PHP 8.3 installed"
else
    print_success "PHP 8.3 already installed"
fi
php -v | head -n 1
print_verbose "PHP configuration file: $(php --ini | grep 'Loaded Configuration File' | cut -d: -f2 | xargs)"

# Composer Installation
next_step "Composer Installation"
print_verbose "Checking for Composer..."
if ! check_installed "composer" "Composer"; then
    print_progress "Installing Composer..."
    print_verbose "Downloading Composer installer..."
    
    if [ "$VERBOSE" = true ]; then
        if curl -sS https://getcomposer.org/installer | php; then
            mv composer.phar /usr/local/bin/composer
            chmod +x /usr/local/bin/composer
            print_success "Composer installed"
        else
            print_error "Failed to install Composer"
            print_warning "Composer is required for backend dependencies"
            exit 1
        fi
    else
        if curl -sS https://getcomposer.org/installer | php > /dev/null 2>&1; then
            mv composer.phar /usr/local/bin/composer
            chmod +x /usr/local/bin/composer
            print_success "Composer installed"
        else
            print_error "Failed to install Composer"
            print_warning "Composer is required for backend dependencies"
            exit 1
        fi
    fi
fi
composer --version | head -n 1
print_verbose "Composer location: $(which composer)"

# Apache2 Configuration
next_step "Apache2 Web Server Setup"
print_verbose "Checking Apache2 status..."
if systemctl is-enabled --quiet apache2 2>/dev/null; then
    print_success "Apache2 already installed and enabled"
else
    print_progress "Enabling Apache2 service..."
    systemctl enable apache2 > /dev/null 2>&1 || true
fi

print_progress "Configuring Apache2 modules..."
print_verbose "Enabling required Apache2 modules..."

# Enable necessary modules
a2enmod rewrite > /dev/null 2>&1 || print_warning "rewrite module may already be enabled"
a2enmod proxy > /dev/null 2>&1 || print_warning "proxy module may already be enabled"
a2enmod proxy_http > /dev/null 2>&1 || print_warning "proxy_http module may already be enabled"
a2enmod ssl > /dev/null 2>&1 || print_warning "ssl module may already be enabled"
a2enmod headers > /dev/null 2>&1 || print_warning "headers module may already be enabled"

print_success "Apache2 modules configured"

# Node.js 24 Installation
next_step "Node.js 24 Installation"
print_verbose "Checking for Node.js 24..."
if ! command -v node &> /dev/null || ! node -v | grep -q "v24"; then
    print_progress "Installing Node.js 24..."
    print_verbose "Adding NodeSource repository..."
    
    if [ "$VERBOSE" = true ]; then
        if curl -fsSL https://deb.nodesource.com/setup_24.x | bash -; then
            print_verbose "Installing nodejs package..."
            if ! $PKG_MGR install -y nodejs; then
                print_error "Failed to install Node.js"
                print_warning "Node.js is required for the frontend"
                exit 1
            fi
            print_success "Node.js 24 installed"
        else
            print_error "Failed to add Node.js repository"
            exit 1
        fi
    else
        if curl -fsSL https://deb.nodesource.com/setup_24.x | bash - > /dev/null 2>&1; then
            if ! $PKG_MGR install -y nodejs > /dev/null 2>&1; then
                print_error "Failed to install Node.js"
                print_warning "Node.js is required for the frontend"
                exit 1
            fi
            print_success "Node.js 24 installed"
        else
            print_error "Failed to add Node.js repository"
            exit 1
        fi
    fi
else
    print_success "Node.js 24 already installed"
fi
node -v
npm -v
print_verbose "Node.js location: $(which node)"
print_verbose "npm location: $(which npm)"

# PM2 Installation
print_info "Installing PM2 process manager..."
print_verbose "Checking for PM2..."
if ! command -v pm2 &> /dev/null; then
    print_verbose "Installing PM2 globally via npm..."
    
    if [ "$VERBOSE" = true ]; then
        if npm install -g pm2; then
            # pm2 startup may fail if www-data user doesn't exist yet or if systemd is not available
            # We use '|| true' to allow this to fail gracefully without stopping the installation
            # PM2 startup can be configured manually later if needed
            print_verbose "Configuring PM2 startup..."
            pm2 startup systemd -u www-data --hp /var/www || true
            print_success "PM2 installed"
        else
            print_error "Failed to install PM2"
            print_warning "PM2 is required for process management"
            exit 1
        fi
    else
        if npm install -g pm2 > /dev/null 2>&1; then
            # pm2 startup may fail if www-data user doesn't exist yet or if systemd is not available
            # We use '|| true' to allow this to fail gracefully without stopping the installation
            # PM2 startup can be configured manually later if needed
            pm2 startup systemd -u www-data --hp /var/www > /dev/null 2>&1 || true
            print_success "PM2 installed"
        else
            print_error "Failed to install PM2"
            print_warning "PM2 is required for process management"
            exit 1
        fi
    fi
else
    print_success "PM2 already installed"
fi
pm2 -v
print_verbose "PM2 location: $(which pm2)"

# Go 1.23 Installation
next_step "Go 1.23 Installation"
print_verbose "Checking for Go..."
if ! check_installed "go" "Go"; then
    print_progress "Installing Go 1.23..."
    print_verbose "Downloading Go 1.23.4..."
    
    if download_file "https://go.dev/dl/go1.23.4.linux-amd64.tar.gz" "go1.23.4.linux-amd64.tar.gz" false; then
        print_verbose "Extracting Go to /usr/local..."
        if tar -C /usr/local -xzf go1.23.4.linux-amd64.tar.gz > /dev/null 2>&1; then
            print_verbose "Adding Go to PATH in /etc/profile..."
            echo 'export PATH=$PATH:/usr/local/go/bin' >> /etc/profile
            export PATH=$PATH:/usr/local/go/bin
            rm go1.23.4.linux-amd64.tar.gz
            print_success "Go 1.23 installed"
        else
            print_error "Failed to extract Go"
            rm -f go1.23.4.linux-amd64.tar.gz
            exit 1
        fi
    else
        print_error "Failed to download Go"
        print_warning "Go is required for TUI and WebSocket server"
        exit 1
    fi
fi
go version
print_verbose "Go location: $(which go)"
print_verbose "GOPATH: $(go env GOPATH 2>/dev/null || echo 'not set')"

# Asterisk 22 Installation
next_step "Asterisk 22 Installation"
SKIP_ASTERISK=""

if command -v asterisk &> /dev/null; then
    ASTERISK_VERSION=$(asterisk -V 2>/dev/null | grep -oP '\d+' | head -n 1)
    if [ "$ASTERISK_VERSION" -ge 22 ]; then
        print_success "Asterisk $ASTERISK_VERSION already installed"
        asterisk -V
        SKIP_ASTERISK=1
    else
        print_warning "Asterisk $ASTERISK_VERSION found (version 22+ recommended)"
        read -p "$(echo -e ${YELLOW}Upgrade to Asterisk 22? \(y/n\) ${RESET})" -n 1 -r
        echo
        if [[ ! $REPLY =~ ^[Yy]$ ]]; then
            SKIP_ASTERISK=1
        fi
    fi
fi

if [ -z "$SKIP_ASTERISK" ]; then
    print_progress "Downloading and building Asterisk 22 (this may take 15-30 minutes)..."
    echo -e "${DIM}   This is the most time-consuming step - please be patient${RESET}"
    
    cd /usr/src
    
    # Download
    print_info "📥 Downloading Asterisk source..."
    download_file "https://downloads.asterisk.org/pub/telephony/asterisk/asterisk-22-current.tar.gz" "asterisk-22-current.tar.gz" true
    tar xzf asterisk-22-current.tar.gz
    cd asterisk-22.*
    
    # Install prerequisites
    print_info "📦 Installing Asterisk prerequisites..."
    contrib/scripts/install_prereq install 2>&1 | tee /var/log/asterisk-prereq.log | grep -E "(Installing|Skipping)" || true
    
    if [ ${PIPESTATUS[0]} -ne 0 ]; then
        print_error "Failed to install prerequisites"
        echo -e "${YELLOW}Check /var/log/asterisk-prereq.log for details${RESET}"
        exit 1
    fi
    
    # Configure
    print_info "⚙️  Configuring Asterisk build..."
    ./configure --with-jansson-bundled 2>&1 | tee /var/log/asterisk-configure.log | tail -20
    
    if [ ${PIPESTATUS[0]} -ne 0 ]; then
        print_error "Asterisk configuration failed"
        echo -e "${YELLOW}Check /var/log/asterisk-configure.log for details${RESET}"
        exit 1
    fi
    
    # Build
    print_info "🔨 Building Asterisk (using $(nproc) CPU cores)..."
    make -j$(nproc) 2>&1 | tee /var/log/asterisk-build.log | grep -E "(CC|LD|GEN)" || true
    
    if [ ${PIPESTATUS[0]} -ne 0 ]; then
        print_error "Asterisk build failed"
        echo -e "${YELLOW}Check /var/log/asterisk-build.log for details${RESET}"
        exit 1
    fi
    
    # Install
    print_info "📦 Installing Asterisk..."
    make install 2>&1 | tee /var/log/asterisk-install.log | grep -E "Installing" || true
    
    if [ ${PIPESTATUS[0]} -ne 0 ]; then
        print_error "Asterisk installation failed"
        echo -e "${YELLOW}Check /var/log/asterisk-install.log for details${RESET}"
        exit 1
    fi
    
    make samples > /dev/null 2>&1
    make config > /dev/null 2>&1
    
    # Create asterisk user if not exists
    if ! id asterisk &> /dev/null; then
        groupadd -r asterisk
        useradd -r -g asterisk -d /var/lib/asterisk -s /bin/false asterisk
        print_info "Created asterisk user"
    fi
    
    # Set ownership
    chown -R asterisk:asterisk /var/lib/asterisk
    chown -R asterisk:asterisk /var/log/asterisk
    chown -R asterisk:asterisk /var/spool/asterisk
    chown -R asterisk:asterisk /etc/asterisk
    
    cd /root
    print_success "Asterisk 22 installed successfully"
    asterisk -V
fi

# Configure Asterisk AMI (using INI helper)
next_step "Asterisk AMI Configuration"
print_info "Configuring Asterisk Manager Interface..."

# Source INI helper script
if [ ! -f "/opt/rayanpbx/scripts/ini-helper.sh" ]; then
    print_warning "INI helper script not found yet, will configure after repo clone"
else
    source /opt/rayanpbx/scripts/ini-helper.sh
    modify_manager_conf "rayanpbx_ami_secret"
    print_success "AMI configured"
fi

systemctl enable asterisk > /dev/null 2>&1

print_progress "Restarting Asterisk service..."
if ! systemctl restart asterisk 2>&1 | tee /tmp/asterisk-restart.log; then
    RESTART_ERROR=$(cat /tmp/asterisk-restart.log)
    handle_asterisk_error "$RESTART_ERROR" "Asterisk restart"
    print_warning "Continuing with installation, but Asterisk may need manual intervention"
fi

# Check Asterisk status
sleep 3
if systemctl is-active --quiet asterisk; then
    print_success "Asterisk service is running"
    print_info "Active channels: $(asterisk -rx 'core show channels' 2>/dev/null | grep 'active channel' || echo '0 active channels')"
else
    print_error "Failed to start Asterisk"
    echo ""
    print_warning "To diagnose the issue, run these commands:"
    print_cmd "systemctl status asterisk"
    print_cmd "journalctl -u asterisk -n 50"
    print_cmd "asterisk -rvvvvv  # Launch Asterisk console in verbose mode"
    echo ""
    handle_asterisk_error "Service failed to start" "Asterisk startup"
fi

# Clone/Update RayanPBX Repository
next_step "RayanPBX Source Code"
cd /opt

if [ -d "rayanpbx" ]; then
    print_info "RayanPBX directory exists, updating..."
    cd rayanpbx
    git pull origin main 2>&1 | tail -5
    print_success "Repository updated"
else
    print_progress "Cloning RayanPBX repository..."
    git clone https://github.com/atomicdeploy/rayanpbx.git 2>&1 | tail -5
    cd rayanpbx
    print_success "Repository cloned"
fi

# Now configure AMI if we skipped earlier
if [ ! -f "/etc/asterisk/manager.conf.rayanpbx-configured" ]; then
    source /opt/rayanpbx/scripts/ini-helper.sh
    modify_manager_conf "rayanpbx_ami_secret"
    touch /etc/asterisk/manager.conf.rayanpbx-configured
    
    print_progress "Reloading Asterisk to apply AMI configuration..."
    if ! systemctl reload asterisk 2>&1 | tee /tmp/asterisk-reload.log; then
        RELOAD_ERROR=$(cat /tmp/asterisk-reload.log)
        print_warning "Asterisk reload encountered an issue"
        
        # Check if it's actually running
        if systemctl is-active --quiet asterisk; then
            print_info "Asterisk is still running, attempting a restart instead..."
            if systemctl restart asterisk 2>&1; then
                print_success "Asterisk restarted successfully"
            else
                handle_asterisk_error "$RELOAD_ERROR" "Asterisk reload/restart"
            fi
        else
            # Try to diagnose the issue
            print_cmd "asterisk -rvvv  # Launch Asterisk console to investigate"
            handle_asterisk_error "$RELOAD_ERROR" "Asterisk reload"
        fi
    else
        print_success "Asterisk configuration reloaded"
    fi
fi

# Setup unified .env file
next_step "Environment Configuration"
if [ ! -f ".env" ]; then
    print_progress "Creating unified environment configuration..."
    cp .env.example .env
    print_verbose ".env file created from template"
else
    print_progress "Updating existing environment configuration..."
fi

# Always update database credentials (in case of re-run or fresh install)
sed -i "s|DB_PASSWORD=.*|DB_PASSWORD=$ESCAPED_DB_PASSWORD|" .env
sed -i "s|DB_DATABASE=.*|DB_DATABASE=rayanpbx|" .env
sed -i "s|DB_USERNAME=.*|DB_USERNAME=rayanpbx|" .env
print_verbose "Database credentials updated in .env"

# Generate JWT secret if not already set
if ! grep -q "JWT_SECRET=.\{10,\}" .env; then
    JWT_SECRET=$(openssl rand -base64 32)
    sed -i "s|JWT_SECRET=.*|JWT_SECRET=$JWT_SECRET|" .env
    print_verbose "JWT secret generated"
fi

# Generate Laravel APP_KEY if not already set
if ! grep -q "APP_KEY=.\{10,\}" .env; then
    APP_KEY="base64:$(openssl rand -base64 32)"
    sed -i "s|APP_KEY=.*|APP_KEY=$APP_KEY|" .env
    print_verbose "Laravel APP_KEY generated"
fi

print_success "Environment configured"

# Copy .env to backend directory for Laravel
print_progress "Setting up backend environment..."
cp .env backend/.env
print_verbose "Backend .env synchronized with root .env"
print_success "Backend environment configured"

# Backend Setup
next_step "Backend API Setup"
print_progress "Installing backend dependencies..."
cd /opt/rayanpbx/backend
composer install --no-dev --optimize-autoloader 2>&1 | grep -E "(Installing|Generating)" || true

print_progress "Running database migrations..."
php artisan migrate --force

if [ $? -eq 0 ]; then
    print_success "Database migrations completed"
else
    print_error "Database migration failed"
    exit 1
fi

# Check and fix database collation
print_progress "Checking database collation..."
COLLATION_CHECK_OUTPUT=$(php artisan db:check-collation 2>&1)
if [ $? -eq 0 ]; then
    print_success "Database collation is correct"
    print_verbose "$COLLATION_CHECK_OUTPUT"
else
    print_warning "Database collation needs to be fixed"
    if [ "$VERBOSE" = true ]; then
        echo "$COLLATION_CHECK_OUTPUT"
    fi
    print_progress "Fixing database collation..."
    if php artisan db:check-collation --fix 2>&1 | tee -a /tmp/collation-fix.log; then
        print_success "Database collation fixed successfully"
    else
        print_warning "Could not fix database collation automatically"
        print_info "You may need to fix it manually later"
        if [ -f /tmp/collation-fix.log ]; then
            print_verbose "Check /tmp/collation-fix.log for details"
        fi
    fi
fi

print_success "Backend configured successfully"

# Frontend Setup
next_step "Frontend Web UI Setup"
print_progress "Installing frontend dependencies..."
cd /opt/rayanpbx/frontend
npm install 2>&1 | grep -E "(added|up to date)" | tail -1

print_progress "Building frontend..."
npm run build 2>&1 | tail -10

print_success "Frontend built successfully"

# Apache Virtual Host Configuration
next_step "Apache2 Virtual Host Configuration"
print_progress "Configuring Apache2 virtual hosts for RayanPBX..."

# Get server IP
SERVER_IP=$(hostname -I | awk '{print $1}')
print_verbose "Server IP: $SERVER_IP"

# Configure Laravel backend virtual host
print_info "Setting up Apache virtual host for Laravel backend..."
cat > /etc/apache2/sites-available/rayanpbx-backend.conf << EOF
<VirtualHost *:80>
    ServerName rayanpbx-api.local
    ServerAlias $SERVER_IP
    DocumentRoot /opt/rayanpbx/backend/public

    <Directory /opt/rayanpbx/backend/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog \${APACHE_LOG_DIR}/rayanpbx-backend-error.log
    CustomLog \${APACHE_LOG_DIR}/rayanpbx-backend-access.log combined

    # PHP Configuration
    <FilesMatch \.php$>
        SetHandler "proxy:unix:/var/run/php/php8.3-fpm.sock|fcgi://localhost"
    </FilesMatch>
</VirtualHost>
EOF

print_success "Backend virtual host configured"

# Set proper permissions for Laravel
print_progress "Setting permissions for Laravel backend..."
chown -R www-data:www-data /opt/rayanpbx/backend
chmod -R 755 /opt/rayanpbx/backend
chmod -R 775 /opt/rayanpbx/backend/storage
chmod -R 775 /opt/rayanpbx/backend/bootstrap/cache
print_success "Permissions set for backend"

# Configure frontend to be served by Apache (proxying to Node.js)
print_info "Setting up Apache virtual host for Nuxt frontend..."
# Note: Using quoted EOF to prevent variable expansion for Apache config variables
# This preserves $1 and ${APACHE_LOG_DIR} as literal strings for Apache
cat > /etc/apache2/sites-available/rayanpbx-frontend.conf << 'EOF'
<VirtualHost *:8080>
    ServerName rayanpbx.local

    ProxyPreserveHost On
    ProxyPass / http://localhost:3000/
    ProxyPassReverse / http://localhost:3000/

    # WebSocket support
    RewriteEngine On
    RewriteCond %{HTTP:Upgrade} =websocket [NC]
    RewriteRule /(.*)  ws://localhost:3000/$1 [P,L]

    ErrorLog ${APACHE_LOG_DIR}/rayanpbx-frontend-error.log
    CustomLog ${APACHE_LOG_DIR}/rayanpbx-frontend-access.log combined
</VirtualHost>
EOF

# Add ServerAlias with actual IP after file creation
sed -i "/ServerName rayanpbx.local/a\    ServerAlias $SERVER_IP" /etc/apache2/sites-available/rayanpbx-frontend.conf

print_success "Frontend virtual host configured"

# Configure Apache to listen on port 8080 for frontend
print_progress "Configuring Apache to listen on additional ports..."
if ! grep -q "Listen 8080" /etc/apache2/ports.conf; then
    echo "Listen 8080" >> /etc/apache2/ports.conf
    print_verbose "Added port 8080 to Apache configuration"
fi

# Enable PHP-FPM
print_progress "Enabling PHP-FPM..."
systemctl enable php8.3-fpm > /dev/null 2>&1
systemctl start php8.3-fpm > /dev/null 2>&1 || true

# Enable proxy_fcgi for PHP-FPM
a2enmod proxy_fcgi > /dev/null 2>&1 || print_verbose "proxy_fcgi already enabled"
a2enconf php8.3-fpm > /dev/null 2>&1 || print_verbose "php8.3-fpm config already enabled"

# Enable the sites
print_progress "Enabling virtual hosts..."
a2dissite 000-default.conf > /dev/null 2>&1 || print_verbose "Default site already disabled"
a2ensite rayanpbx-backend.conf > /dev/null 2>&1
a2ensite rayanpbx-frontend.conf > /dev/null 2>&1

print_success "Virtual hosts enabled"

# Restart Apache to apply changes
print_progress "Restarting Apache2..."
if systemctl restart apache2 2>&1; then
    print_success "Apache2 restarted successfully"
else
    print_warning "Apache2 restart encountered an issue, checking configuration..."
    apache2ctl configtest 2>&1 | tail -5
fi

print_success "Apache2 configuration complete"

# TUI Setup
next_step "TUI (Terminal UI) Build"
print_progress "Building TUI application..."
cd /opt/rayanpbx/tui
go mod download
go build -o /usr/local/bin/rayanpbx-tui main.go config.go
chmod +x /usr/local/bin/rayanpbx-tui

print_success "TUI built: /usr/local/bin/rayanpbx-tui"

# WebSocket Server Setup
print_progress "Building WebSocket server..."
go build -o /usr/local/bin/rayanpbx-ws websocket.go config.go
chmod +x /usr/local/bin/rayanpbx-ws

print_success "WebSocket server built: /usr/local/bin/rayanpbx-ws"

# PM2 Ecosystem Configuration
next_step "PM2 Process Management Setup"
cat > /opt/rayanpbx/ecosystem.config.js << 'EOF'
module.exports = {
  apps: [
    {
      name: 'rayanpbx-web',
      cwd: '/opt/rayanpbx/frontend',
      script: '.output/server/index.mjs',
      instances: 1,
      autorestart: true,
      watch: false,
      max_memory_restart: '500M',
      env: {
        PORT: 3000,
        NODE_ENV: 'production'
      }
    },
    {
      name: 'rayanpbx-ws',
      script: '/usr/local/bin/rayanpbx-ws',
      cwd: '/opt/rayanpbx/tui',
      instances: 1,
      autorestart: true,
      watch: false,
      max_memory_restart: '200M'
    }
  ]
};
EOF

print_success "PM2 ecosystem configured"

# Systemd Services
next_step "Systemd Services Configuration"

# Backend queue worker service (for Laravel queues)
cat > /etc/systemd/system/rayanpbx-queue.service << 'EOF'
[Unit]
Description=RayanPBX Queue Worker
After=network.target mysql.service redis-server.service

[Service]
Type=simple
User=www-data
WorkingDirectory=/opt/rayanpbx/backend
ExecStart=/usr/bin/php artisan queue:work --sleep=3 --tries=3 --max-time=3600
Restart=always
RestartSec=3
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
EOF

print_success "Created rayanpbx-queue.service"

# Reload systemd
systemctl daemon-reload

# Enable and start services
print_progress "Starting services..."
systemctl enable rayanpbx-queue > /dev/null 2>&1
systemctl start rayanpbx-queue

# Ensure Apache2 is running
print_progress "Ensuring Apache2 is running..."
systemctl enable apache2 > /dev/null 2>&1
systemctl restart apache2

# Start PM2 services
cd /opt/rayanpbx
su - www-data -s /bin/bash -c "cd /opt/rayanpbx && pm2 start ecosystem.config.js"
su - www-data -s /bin/bash -c "pm2 save"

# Setup Cron Jobs
next_step "Cron Jobs Setup"
print_info "Configuring cron jobs..."

# Laravel scheduler
(crontab -l 2>/dev/null || true; echo "* * * * * cd /opt/rayanpbx/backend && php artisan schedule:run >> /dev/null 2>&1") | crontab -

print_success "Cron jobs configured"

# Verify services
next_step "Service Verification"
sleep 3

if systemctl is-active --quiet apache2; then
    print_success "✓ Apache2 running"
    if curl -s -o /dev/null -w "%{http_code}" http://localhost/ | grep -q "200\|301\|302"; then
        print_success "✓ Laravel backend accessible via Apache2"
    else
        print_warning "✗ Laravel backend may not be responding correctly"
    fi
else
    print_warning "✗ Apache2 service failed - check: systemctl status apache2"
fi

if systemctl is-active --quiet rayanpbx-queue; then
    print_success "✓ Queue worker running"
else
    print_warning "✗ Queue worker failed - check: systemctl status rayanpbx-queue"
fi

if su - www-data -s /bin/bash -c "pm2 list" | grep -q "rayanpbx-web.*online"; then
    print_success "✓ Web service running (PM2)"
    if curl -s -o /dev/null -w "%{http_code}" http://localhost:3000/ | grep -q "200\|301\|302"; then
        print_success "✓ Frontend accessible via PM2"
    fi
else
    print_warning "✗ Web service issue - check: pm2 list"
fi

if su - www-data -s /bin/bash -c "pm2 list" | grep -q "rayanpbx-ws.*online"; then
    print_success "✓ WebSocket service running (PM2)"
else
    print_warning "✗ WebSocket service issue - check: pm2 list"
fi

if systemctl is-active --quiet asterisk; then
    print_success "✓ Asterisk running"
    ASTERISK_VERSION=$(asterisk -V 2>/dev/null | head -n 1)
    echo -e "${DIM}   $ASTERISK_VERSION${RESET}"
else
    print_warning "✗ Asterisk issue - check: systemctl status asterisk"
fi

# Final Banner
next_step "Installation Complete! 🎉"

clear
print_banner

print_box "Installation Successful!" "$GREEN"

echo -e "${BOLD}${CYAN}📊 System Services:${RESET}"
echo -e "  ${GREEN}✓${RESET} Laravel Backend (Apache2): http://$(hostname -I | awk '{print $1}')/api"
echo -e "  ${GREEN}✓${RESET} Web Interface (Apache2)  : http://$(hostname -I | awk '{print $1}'):8080"
echo -e "  ${GREEN}✓${RESET} WebSocket Server         : ws://$(hostname -I | awk '{print $1}'):9000/ws"
echo -e "  ${GREEN}✓${RESET} TUI Terminal             : ${WHITE}rayanpbx-tui${RESET}"
echo ""

echo -e "${BOLD}${CYAN}🔐 Default Login (Development):${RESET}"
echo -e "  ${YELLOW}Username:${RESET} admin"
echo -e "  ${YELLOW}Password:${RESET} admin"
echo ""

echo -e "${BOLD}${CYAN}🌐 Apache2 Configuration:${RESET}"
echo -e "  ${DIM}Backend VHost:${RESET}  /etc/apache2/sites-available/rayanpbx-backend.conf"
echo -e "  ${DIM}Frontend VHost:${RESET} /etc/apache2/sites-available/rayanpbx-frontend.conf"
echo -e "  ${DIM}Backend Port:${RESET}   80 (default HTTP)"
echo -e "  ${DIM}Frontend Port:${RESET}  8080"
echo ""

echo -e "${BOLD}${CYAN}📁 File Locations:${RESET}"
echo -e "  ${DIM}Configuration:${RESET} /opt/rayanpbx/.env"
echo -e "  ${DIM}Backend Root:${RESET}  /opt/rayanpbx/backend/public"
echo -e "  ${DIM}Asterisk:${RESET}      /etc/asterisk/"
echo -e "  ${DIM}Apache Logs:${RESET}   /var/log/apache2/"
echo ""

echo -e "${BOLD}${CYAN}🛠️  Useful Commands:${RESET}"
echo -e "  ${DIM}View services:${RESET}     pm2 list"
echo -e "  ${DIM}View logs:${RESET}         pm2 logs"
echo -e "  ${DIM}Apache status:${RESET}     systemctl status apache2"
echo -e "  ${DIM}Apache test:${RESET}       apache2ctl configtest"
echo -e "  ${DIM}Asterisk CLI:${RESET}      asterisk -rvvv   ${GREEN}(Recommended!)${RESET}"
echo -e "  ${DIM}Asterisk status:${RESET}   systemctl status asterisk"
echo -e "  ${DIM}Queue worker:${RESET}      systemctl status rayanpbx-queue"
echo ""

echo -e "${BOLD}${CYAN}🚀 Next Steps:${RESET}"
echo -e "  ${GREEN}1.${RESET} ${BOLD}Launch Asterisk Console${RESET} to monitor calls:"
echo -e "     ${WHITE}asterisk -rvvv${RESET}  ${DIM}(press 'exit' or Ctrl+C to quit)${RESET}"
echo ""
echo -e "  ${GREEN}2.${RESET} Access web UI via Apache2: http://$(hostname -I | awk '{print $1}'):8080"
echo ""
echo -e "  ${GREEN}3.${RESET} Access API via Apache2: http://$(hostname -I | awk '{print $1}')/api"
echo ""
echo -e "  ${GREEN}4.${RESET} Login with admin/admin"
echo ""
echo -e "  ${GREEN}5.${RESET} Configure your first extension"
echo ""
echo -e "  ${GREEN}6.${RESET} Set up a SIP trunk"
echo ""
echo -e "  ${GREEN}7.${RESET} Test your setup"
echo ""

echo -e "${BOLD}${CYAN}📚 Documentation & Support:${RESET}"
echo -e "  ${DIM}GitHub:${RESET}  https://github.com/atomicdeploy/rayanpbx"
echo -e "  ${DIM}Issues:${RESET}  https://github.com/atomicdeploy/rayanpbx/issues"
echo ""

print_box "Thank you for installing RayanPBX! 💙" "$CYAN"
echo ""
