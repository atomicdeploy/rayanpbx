#!/bin/bash

# RayanPBX Enhanced Installer for Ubuntu 24.04 LTS
# This script installs and configures RayanPBX with Asterisk 22

set -e

# ╔════════════════════════════════════════════════════════════════════════╗
# ║                          PREPARATION PHASE                             ║
# ╚════════════════════════════════════════════════════════════════════════╝

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

# Show preparation message
echo -e "${YELLOW}${BOLD}⚙️  Preparing the install script...${RESET}"
echo -e "${DIM}Loading components and checking prerequisites...${RESET}\n"

# Quick dependency check for preparation tools
if ! command -v apt-get &> /dev/null; then
    echo -e "${RED}❌ apt-get not found. This script requires Ubuntu/Debian.${RESET}"
    exit 1
fi

# Install figlet and lolcat for beautiful output if not present
PREP_PKGS=()
if ! command -v figlet &> /dev/null; then
    PREP_PKGS+=("figlet")
fi
if ! command -v lolcat &> /dev/null; then
    PREP_PKGS+=("lolcat")
fi
if ! command -v toilet &> /dev/null; then
    PREP_PKGS+=("toilet")
fi

if [ ${#PREP_PKGS[@]} -gt 0 ]; then
    echo -e "${CYAN}📦 Installing display tools: ${PREP_PKGS[*]}${RESET}"
    apt-get update -qq 2>&1 | grep -v "^$" | head -3 || true
    apt-get install -y "${PREP_PKGS[@]}" > /dev/null 2>&1
    echo -e "${GREEN}✅ Display tools ready${RESET}\n"
fi

# Small delay for effect
sleep 0.5

# Clear screen for main installer
clear

# ╔════════════════════════════════════════════════════════════════════════╗
# ║                          MAIN INSTALLER                                ║
# ╚════════════════════════════════════════════════════════════════════════╝

# Step counter
STEP_NUMBER=0

# Helper Functions
print_banner() {
    if command -v toilet &> /dev/null && command -v lolcat &> /dev/null; then
        toilet -f bigmono12 -F border "RayanPBX" | lolcat -a -s 50
        echo -e "${CYAN}${BOLD}       🎯 Modern SIP Server Management Suite 🎯${RESET}" | lolcat -a -s 50
    elif command -v figlet &> /dev/null && command -v lolcat &> /dev/null; then
        figlet -f slant "RayanPBX" | lolcat -a -s 50
        echo -e "${CYAN}${BOLD}   Modern SIP Server Management Suite${RESET}" | lolcat -a -s 50
    elif command -v figlet &> /dev/null; then
        echo -e "${CYAN}${BOLD}"
        figlet -f slant "RayanPBX"
        echo -e "   Modern SIP Server Management Suite"
        echo -e "${RESET}"
    else
        echo -e "${CYAN}${BOLD}"
        echo "╔════════════════════════════════════════════════════════╗"
        echo "║                                                        ║"
        echo "║          🚀  RayanPBX Installer  🚀                    ║"
        echo "║                                                        ║"
        echo "║      Modern SIP Server Management Suite               ║"
        echo "║                                                        ║"
        echo "╚════════════════════════════════════════════════════════╝"
        echo -e "${RESET}"
    fi
    echo -e "${DIM}╾━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━╼${RESET}\n"
}

next_step() {
    ((STEP_NUMBER++))
    echo -e "\n${BLUE}${BOLD}╭─ Step ${STEP_NUMBER}: $1${RESET}"
    echo -e "${DIM}╰─────────────────────────────────────────────────────────────${RESET}"
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

print_box() {
    local text="$1"
    local emoji="${2:-🎯}"
    local color="${3:-$CYAN}"
    local length=${#text}
    local padding=$((60 - length))
    local pad_left=$((padding / 2))
    local pad_right=$((padding - pad_left))
    
    echo -e "${color}${BOLD}"
    echo "╭────────────────────────────────────────────────────────────╮"
    printf "│ ${emoji} %*s%s%*s │\n" $pad_left "" "$text" $pad_right ""
    echo "╰────────────────────────────────────────────────────────────╯"
    echo -e "${RESET}"
}

# Animated progress bar
progress_bar() {
    local duration=$1
    local message="${2:-Processing...}"
    local width=50
    
    echo -ne "${CYAN}${message} ${RESET}["
    
    for ((i=0; i<=width; i++)); do
        local percent=$((i * 100 / width))
        echo -ne "\033[${GREEN}m█${RESET}"
        echo -ne "] ${percent}%\r"
        echo -ne "${CYAN}${message} ${RESET}["
        sleep $(echo "scale=3; $duration/$width" | bc)
    done
    
    echo -ne "\033[${GREEN}m"
    for ((i=0; i<=width; i++)); do echo -n "█"; done
    echo -e "${RESET}] 100%"
}

# Spinner for indefinite operations
spinner() {
    local pid=$1
    local message="${2:-Working...}"
    local delay=0.1
    local spinchars='⠋⠙⠹⠸⠼⠴⠦⠧⠇⠏'
    
    while ps -p $pid > /dev/null 2>&1; do
        for ((i=0; i<${#spinchars}; i++)); do
            echo -ne "\r${MAGENTA}${spinchars:$i:1} ${message}${RESET}"
            sleep $delay
        done
    done
    echo -ne "\r${CLEAR_LINE}"
}

check_installed() {
    local cmd="$1"
    local name="${2:-$cmd}"
    
    if command -v "$cmd" &> /dev/null; then
        local version=$($cmd --version 2>&1 | head -1 | grep -oP '\d+\.\d+(\.\d+)?' | head -1 || echo "installed")
        print_success "$name already installed ${DIM}(${version})${RESET}"
        return 0
    else
        print_info "$name not found, will install"
        return 1
    fi
}

# Main installation starts here
print_banner

# Check root
if [[ $EUID -ne 0 ]]; then
   print_box "This script must be run as root" "⛔" "$RED"
   echo -e "${YELLOW}💡 Please run: ${WHITE}sudo $0${RESET}"
   exit 1
fi

# Verify Ubuntu 24.04
next_step "System Verification"
if ! grep -q "24.04" /etc/os-release 2>/dev/null; then
    print_warning "This script is designed for Ubuntu 24.04 LTS"
    echo -e "${YELLOW}Your version: $(lsb_release -d 2>/dev/null | cut -f2 || echo "Unknown")${RESET}"
    read -p "Continue anyway? (y/n) " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        exit 1
    fi
else
    print_success "Ubuntu 24.04 LTS detected ✨"
fi

# Install/check nala
next_step "Package Manager Setup"
if ! check_installed nala "nala package manager"; then
    print_progress "Installing nala..."
    (apt-get update -qq && apt-get install -y nala) > /tmp/nala-install.log 2>&1 &
    spinner $! "Installing nala package manager"
    print_success "nala installed"
fi

# System update
next_step "System Update"
print_progress "Updating package lists and upgrading system..."
(nala update && nala upgrade -y) > /tmp/system-update.log 2>&1 &
spinner $! "Updating system packages"
print_success "System updated"

# Install essential dependencies
next_step "Essential Dependencies"

ESSENTIAL_PKGS=(
    software-properties-common curl wget git build-essential
    libncurses5-dev libssl-dev libxml2-dev libsqlite3-dev
    uuid-dev libjansson-dev pkg-config redis-server cron
)

print_info "Installing essential build tools..."
for pkg in "${ESSENTIAL_PKGS[@]}"; do
    if dpkg -l | grep -q "^ii.*$pkg"; then
        echo -e "${DIM}  ✓ $pkg${RESET}"
    else
        echo -e "${CYAN}  📦 $pkg${RESET}"
    fi
done

(nala install -y "${ESSENTIAL_PKGS[@]}") > /tmp/essential-pkgs.log 2>&1 &
spinner $! "Installing essential packages"
print_success "Essential dependencies installed"

# Install gh (GitHub CLI)
next_step "GitHub CLI Setup"
if ! check_installed gh "GitHub CLI"; then
    print_progress "Installing GitHub CLI..."
    (
        curl -fsSL https://cli.github.com/packages/githubcli-archive-keyring.gpg | dd of=/usr/share/keyrings/githubcli-archive-keyring.gpg
        chmod go+r /usr/share/keyrings/githubcli-archive-keyring.gpg
        echo "deb [arch=$(dpkg --print-architecture) signed-by=/usr/share/keyrings/githubcli-archive-keyring.gpg] https://cli.github.com/packages stable main" | tee /etc/apt/sources.list.d/github-cli.list > /dev/null
        nala update && nala install -y gh
    ) > /tmp/gh-install.log 2>&1 &
    spinner $! "Installing GitHub CLI"
    print_success "GitHub CLI installed"
fi

# Install tcpdump for traffic analysis
next_step "Traffic Analysis Tools"
if ! check_installed tcpdump "tcpdump"; then
    print_progress "Installing tcpdump..."
    nala install -y tcpdump > /tmp/tcpdump-install.log 2>&1
    print_success "tcpdump installed"
fi

# PHP 8.3 Installation
next_step "PHP 8.3 Installation"
if ! check_installed php "PHP"; then
    print_progress "Installing PHP 8.3 and extensions..."
    PHP_PACKAGES=(
        php8.3 php8.3-cli php8.3-fpm php8.3-mysql php8.3-xml
        php8.3-mbstring php8.3-curl php8.3-zip php8.3-bcmath
        php8.3-redis php8.3-intl php8.3-gd
    )
    (nala install -y "${PHP_PACKAGES[@]}") > /tmp/php-install.log 2>&1 &
    spinner $! "Installing PHP 8.3"
    print_success "PHP 8.3 installed"
else
    print_success "PHP already installed"
fi

# Composer Installation
next_step "Composer Setup"
if ! check_installed composer "Composer"; then
    print_progress "Installing Composer..."
    (
        curl -sS https://getcomposer.org/installer | php
        mv composer.phar /usr/local/bin/composer
        chmod +x /usr/local/bin/composer
    ) > /tmp/composer-install.log 2>&1 &
    spinner $! "Installing Composer"
    print_success "Composer installed"
fi

# Node.js 24 Installation
next_step "Node.js 24 Setup"
if ! check_installed node "Node.js"; then
    print_progress "Installing Node.js 24..."
    (
        curl -fsSL https://deb.nodesource.com/setup_24.x | bash -
        nala install -y nodejs
    ) > /tmp/nodejs-install.log 2>&1 &
    spinner $! "Installing Node.js 24"
    print_success "Node.js 24 installed"
elif ! node --version | grep -q "v24\."; then
    print_warning "Node.js $(node --version) found, but v24 recommended"
    print_info "Skipping Node.js installation"
fi

# PM2 Installation
next_step "PM2 Process Manager"
if ! check_installed pm2 "PM2"; then
    print_progress "Installing PM2..."
    npm install -g pm2 > /tmp/pm2-install.log 2>&1 &
    spinner $! "Installing PM2"
    pm2 startup > /dev/null 2>&1 || true
    print_success "PM2 installed"
fi

# Go 1.23 Installation
next_step "Go 1.23 Setup"
if ! check_installed go "Go"; then
    print_progress "Installing Go 1.23..."
    (
        GO_VERSION="1.23.0"
        wget -q "https://go.dev/dl/go${GO_VERSION}.linux-amd64.tar.gz" -O /tmp/go.tar.gz
        rm -rf /usr/local/go
        tar -C /usr/local -xzf /tmp/go.tar.gz
        rm /tmp/go.tar.gz
        
        # Add to PATH for current session
        export PATH=$PATH:/usr/local/go/bin
        
        # Add to profile for persistence
        if ! grep -q "/usr/local/go/bin" /etc/profile; then
            echo 'export PATH=$PATH:/usr/local/go/bin' >> /etc/profile
        fi
    ) > /tmp/go-install.log 2>&1 &
    spinner $! "Installing Go 1.23"
    export PATH=$PATH:/usr/local/go/bin
    print_success "Go 1.23 installed"
fi

# MariaDB Installation
next_step "MariaDB Setup"
if ! check_installed mysql "MariaDB"; then
    print_progress "Installing MariaDB Server..."
    (
        export DEBIAN_FRONTEND=noninteractive
        nala install -y mariadb-server mariadb-client
        systemctl start mariadb
        systemctl enable mariadb
    ) > /tmp/mariadb-install.log 2>&1 &
    spinner $! "Installing MariaDB"
    print_success "MariaDB installed"
    
    # Run mysql_secure_installation if not done before
    if [ ! -f /root/.mysql_secured ]; then
        print_info "Securing MariaDB installation..."
        read -sp "Enter MariaDB root password: " MYSQL_ROOT_PASSWORD
        echo
        
        # Secure MariaDB
        mysql -uroot <<EOF
ALTER USER 'root'@'localhost' IDENTIFIED BY '${MYSQL_ROOT_PASSWORD}';
DELETE FROM mysql.user WHERE User='';
DELETE FROM mysql.user WHERE User='root' AND Host NOT IN ('localhost', '127.0.0.1', '::1');
DROP DATABASE IF EXISTS test;
DELETE FROM mysql.db WHERE Db='test' OR Db='test\\_%';
FLUSH PRIVILEGES;
EOF
        touch /root/.mysql_secured
        print_success "MariaDB secured"
    fi
else
    print_success "MariaDB already installed"
fi

# Create database with UTF8MB4
print_info "Creating RayanPBX database with UTF8MB4..."

# Get or prompt for password
if [ -f /root/.mysql_secured ]; then
    read -sp "Enter MariaDB root password: " MYSQL_ROOT_PASSWORD
    echo
else
    MYSQL_ROOT_PASSWORD=""
fi

# Create database and user
DB_PASSWORD="rayanpbx_$(openssl rand -hex 12)"

mysql -uroot ${MYSQL_ROOT_PASSWORD:+-p"${MYSQL_ROOT_PASSWORD}"} <<EOF
CREATE DATABASE IF NOT EXISTS rayanpbx CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'rayanpbx'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
GRANT ALL PRIVILEGES ON rayanpbx.* TO 'rayanpbx'@'localhost';
FLUSH PRIVILEGES;
EOF

# Save database credentials for later use
mkdir -p /opt/rayanpbx
cat > /opt/rayanpbx/.db_credentials <<EOF
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=rayanpbx
DB_USERNAME=rayanpbx
DB_PASSWORD=${DB_PASSWORD}
EOF

chmod 600 /opt/rayanpbx/.db_credentials

print_success "Database created with UTF8MB4 collation"
print_info "Database credentials saved to /opt/rayanpbx/.db_credentials"

# Asterisk 22 Installation
next_step "Asterisk 22 Installation"
print_warning "This may take 15-30 minutes depending on your system..."

if ! check_installed asterisk "Asterisk"; then
    print_box "Building Asterisk 22 from source" "📞" "$MAGENTA"
    
    (
        cd /usr/src
        
        # Download Asterisk 22
        ASTERISK_VERSION="22.0.0"
        wget -q "http://downloads.asterisk.org/pub/telephony/asterisk/asterisk-${ASTERISK_VERSION}.tar.gz"
        tar -xzf "asterisk-${ASTERISK_VERSION}.tar.gz"
        cd "asterisk-${ASTERISK_VERSION}"
        
        # Install dependencies
        contrib/scripts/install_prereq.sh --assume-yes > /tmp/asterisk-deps.log 2>&1
        
        # Configure
        ./configure --with-pjproject-bundled --with-jansson-bundled 2>&1 | tee /tmp/asterisk-configure.log
        
        # Build
        make menuselect.makeopts
        menuselect/menuselect --enable app_macro --enable CORE-SOUNDS-EN-GSM --enable MOH-OPSOUND-GSM menuselect.makeopts
        
        make -j$(nproc) 2>&1 | tee /tmp/asterisk-build.log
        make install 2>&1 | tee /tmp/asterisk-install.log
        make samples 2>&1 | tee -a /tmp/asterisk-install.log
        make config 2>&1 | tee -a /tmp/asterisk-install.log
        
        # Check for errors
        if [ ${PIPESTATUS[0]} -ne 0 ]; then
            echo "Build errors detected, check /tmp/asterisk-build.log"
            exit 1
        fi
        
        # Create asterisk user
        useradd -r -d /var/lib/asterisk -s /bin/false asterisk 2>/dev/null || true
        chown -R asterisk:asterisk /var/lib/asterisk
        chown -R asterisk:asterisk /var/spool/asterisk
        chown -R asterisk:asterisk /var/log/asterisk
        chown -R asterisk:asterisk /etc/asterisk
        
    ) > /tmp/asterisk-full-install.log 2>&1 &
    
    # Show progress while building
    BUILD_PID=$!
    while ps -p $BUILD_PID > /dev/null 2>&1; do
        if [ -f /tmp/asterisk-build.log ]; then
            PROGRESS=$(tail -20 /tmp/asterisk-build.log | grep -c "CC\|LD" || echo "0")
            echo -ne "\r${MAGENTA}🔨 Building Asterisk... [${PROGRESS} files compiled]${RESET}"
        fi
        sleep 2
    done
    echo
    
    wait $BUILD_PID
    if [ $? -eq 0 ]; then
        print_success "Asterisk 22 built and installed successfully! 🎉"
    else
        print_error "Asterisk build failed. Check /tmp/asterisk-*.log for details"
        exit 1
    fi
fi

# Configure and start Asterisk
systemctl enable asterisk
systemctl start asterisk

# Verify Asterisk is running
if systemctl is-active --quiet asterisk; then
    print_success "Asterisk service is running"
else
    print_warning "Asterisk service failed to start, check: sudo systemctl status asterisk"
fi

# Clone RayanPBX repository
next_step "RayanPBX Application Setup"
INSTALL_DIR="/opt/rayanpbx"

if [ -d "$INSTALL_DIR" ]; then
    print_warning "RayanPBX already exists at $INSTALL_DIR"
    read -p "Remove and reinstall? (y/n) " -n 1 -r
    echo
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        rm -rf "$INSTALL_DIR"
    else
        print_info "Skipping application setup"
    fi
fi

if [ ! -d "$INSTALL_DIR" ]; then
    print_progress "Cloning RayanPBX repository..."
    git clone https://github.com/atomicdeploy/rayanpbx.git "$INSTALL_DIR" > /tmp/git-clone.log 2>&1 &
    spinner $! "Cloning repository"
    print_success "Repository cloned"
fi

cd "$INSTALL_DIR"

# Configure environment
print_info "Configuring environment..."
./scripts/config-tui.sh

# Install backend dependencies
print_progress "Installing backend dependencies..."
cd backend
composer install --no-dev --optimize-autoloader > /tmp/composer-deps.log 2>&1 &
spinner $! "Installing PHP dependencies"
print_success "Backend dependencies installed"

# Run migrations
php artisan migrate --force
print_success "Database migrated"

# Install frontend dependencies
print_progress "Installing frontend dependencies..."
cd ../frontend
npm install > /tmp/npm-deps.log 2>&1 &
spinner $! "Installing Node.js dependencies"
print_success "Frontend dependencies installed"

# Build frontend
print_progress "Building frontend..."
npm run build > /tmp/npm-build.log 2>&1 &
spinner $! "Building frontend"
print_success "Frontend built"

# Build TUI
print_progress "Building TUI..."
cd ../tui
go build -o /usr/local/bin/rayanpbx-tui . > /tmp/go-build.log 2>&1 &
spinner $! "Building TUI"
print_success "TUI built"

# Setup PM2 services
cd "$INSTALL_DIR"
print_info "Setting up PM2 services..."

pm2 delete rayanpbx-backend 2>/dev/null || true
pm2 delete rayanpbx-frontend 2>/dev/null || true
pm2 delete rayanpbx-websocket 2>/dev/null || true

cd backend
pm2 start "php artisan serve --host=0.0.0.0 --port=8000" --name rayanpbx-backend

cd ../frontend
pm2 start "npm run start" --name rayanpbx-frontend

cd ../tui
pm2 start "./rayanpbx-tui --websocket" --name rayanpbx-websocket

pm2 save
print_success "PM2 services configured"

# Setup cron for Laravel scheduler
print_info "Setting up cron jobs..."
(crontab -l 2>/dev/null; echo "* * * * * cd $INSTALL_DIR/backend && php artisan schedule:run >> /dev/null 2>&1") | crontab -
print_success "Cron jobs configured"

# Final health check
next_step "Health Verification"
bash scripts/health-check.sh

# Print completion banner
clear
print_banner

print_box "Installation Complete!" "🎉" "$GREEN"

cat <<EOF

${GREEN}${BOLD}✨ RayanPBX is now installed and running! ✨${RESET}

${CYAN}${BOLD}Access Points:${RESET}
  ${BLUE}🌐 Web UI:${RESET}      http://$(hostname -I | awk '{print $1}'):3000
  ${BLUE}🔌 API:${RESET}         http://$(hostname -I | awk '{print $1}'):8000/api
  ${BLUE}⚡ WebSocket:${RESET}   ws://$(hostname -I | awk '{print $1}'):9000/ws
  ${BLUE}🖥️  TUI:${RESET}         rayanpbx-tui

${CYAN}${BOLD}Default Login:${RESET}
  ${BLUE}Username:${RESET} $(whoami)
  ${BLUE}Password:${RESET} Your Linux password

${CYAN}${BOLD}Management Commands:${RESET}
  ${DIM}pm2 list${RESET}                    - View running services
  ${DIM}pm2 logs rayanpbx-backend${RESET}   - View backend logs
  ${DIM}systemctl status asterisk${RESET}   - Check Asterisk status
  ${DIM}rayanpbx-tui${RESET}                - Launch terminal UI

${CYAN}${BOLD}Log Files:${RESET}
  ${DIM}/tmp/asterisk-*.log${RESET}        - Asterisk build logs
  ${DIM}/var/log/asterisk/full${RESET}     - Asterisk runtime logs
  ${DIM}$INSTALL_DIR/backend/storage/logs${RESET} - Application logs

${YELLOW}${BOLD}📚 Next Steps:${RESET}
  1. Open the web UI and log in
  2. Create your first extension
  3. Configure a SIP trunk
  4. Test making calls!

${GREEN}${BOLD}Thank you for using RayanPBX! 🚀${RESET}

EOF

if command -v lolcat &> /dev/null; then
    echo "════════════════════════════════════════════════════════════" | lolcat
fi

exit 0
