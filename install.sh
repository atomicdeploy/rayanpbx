#!/bin/bash

set -e

# RayanPBX Installation Script for Ubuntu 24.04 LTS
# This script installs and configures RayanPBX with Asterisk 22

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
# Helper Functions
# ════════════════════════════════════════════════════════════════════════

print_banner() {
    clear
    if command -v figlet &> /dev/null; then
        if command -v lolcat &> /dev/null; then
            figlet -f slant "RayanPBX" | lolcat
        else
            echo -e "${CYAN}$(figlet -f slant "RayanPBX")${RESET}"
        fi
    else
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
    ((STEP_NUMBER++))
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

# ════════════════════════════════════════════════════════════════════════
# Main Installation
# ════════════════════════════════════════════════════════════════════════

print_banner

# Check if running as root
if [[ $EUID -ne 0 ]]; then
   print_error "This script must be run as root"
   echo -e "${YELLOW}💡 Please run: ${WHITE}sudo $0${RESET}"
   exit 1
fi

# Check Ubuntu version
next_step "System Verification"
if ! grep -q "24.04" /etc/os-release; then
    print_warning "This script is designed for Ubuntu 24.04 LTS"
    echo -e "${YELLOW}Your version: $(lsb_release -d | cut -f2)${RESET}"
    read -p "Continue anyway? (y/n) " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        exit 1
    fi
else
    print_success "Ubuntu 24.04 LTS detected"
fi

# Install nala if not present
next_step "Package Manager Setup"
if ! command -v nala &> /dev/null; then
    print_progress "Installing nala package manager..."
    apt-get update -qq
    apt-get install -y nala > /dev/null 2>&1
    print_success "nala installed"
else
    print_success "nala already installed"
fi

# System update
next_step "System Update"
print_progress "Updating package lists and upgrading system..."
nala update > /dev/null 2>&1
nala upgrade -y > /dev/null 2>&1
print_success "System updated"

# Install dependencies
next_step "Essential Dependencies"
PACKAGES=(
    software-properties-common
    curl
    wget
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
)

print_info "Installing essential packages..."
for package in "${PACKAGES[@]}"; do
    if ! dpkg -l | grep -q "^ii  $package "; then
        echo -e "${DIM}   Installing $package...${RESET}"
        nala install -y "$package" > /dev/null 2>&1
        print_success "✓ $package"
    else
        echo -e "${DIM}   ✓ $package (already installed)${RESET}"
    fi
done

# Install GitHub CLI
next_step "GitHub CLI Installation"
if ! check_installed "gh" "GitHub CLI"; then
    print_progress "Installing GitHub CLI..."
    curl -fsSL https://cli.github.com/packages/githubcli-archive-keyring.gpg | dd of=/usr/share/keyrings/githubcli-archive-keyring.gpg
    chmod go+r /usr/share/keyrings/githubcli-archive-keyring.gpg
    echo "deb [arch=$(dpkg --print-architecture) signed-by=/usr/share/keyrings/githubcli-archive-keyring.gpg] https://cli.github.com/packages stable main" | tee /etc/apt/sources.list.d/github-cli.list > /dev/null
    nala update > /dev/null 2>&1
    nala install -y gh > /dev/null 2>&1
    print_success "GitHub CLI installed"
fi

# MySQL/MariaDB Installation
next_step "Database Setup (MySQL/MariaDB)"
if ! command -v mysql &> /dev/null; then
    print_progress "Installing MariaDB..."
    nala install -y mariadb-server mariadb-client > /dev/null 2>&1
    systemctl enable mariadb
    systemctl start mariadb
    print_success "MariaDB installed and started"
    
    # Check if MySQL is already secured
    if mysql -u root -e "SELECT 1" &> /dev/null; then
        print_warning "MySQL root has no password - securing now..."
        echo -e "${YELLOW}Please set a secure MySQL root password${RESET}"
        
        while true; do
            read -sp "$(echo -e ${CYAN}Enter new MySQL root password:${RESET} )" MYSQL_ROOT_PASSWORD
            echo
            read -sp "$(echo -e ${CYAN}Confirm MySQL root password:${RESET} )" MYSQL_ROOT_PASSWORD_CONFIRM
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
        read -sp "$(echo -e ${CYAN}Enter MySQL root password:${RESET} )" MYSQL_ROOT_PASSWORD
        echo
    fi
else
    print_success "MySQL/MariaDB already installed"
    read -sp "$(echo -e ${CYAN}Enter MySQL root password:${RESET} )" MYSQL_ROOT_PASSWORD
    echo
fi

# Create RayanPBX database
print_progress "Creating RayanPBX database..."
ESCAPED_DB_PASSWORD=$(openssl rand -hex 16)

mysql -u root -p"$MYSQL_ROOT_PASSWORD" <<EOF
CREATE DATABASE IF NOT EXISTS rayanpbx CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'rayanpbx'@'localhost' IDENTIFIED BY '$ESCAPED_DB_PASSWORD';
GRANT ALL PRIVILEGES ON rayanpbx.* TO 'rayanpbx'@'localhost';
FLUSH PRIVILEGES;
EOF

print_success "Database 'rayanpbx' created"

# PHP 8.3 Installation
next_step "PHP 8.3 Installation"
if ! command -v php &> /dev/null || ! php -v | grep -q "8.3"; then
    print_progress "Installing PHP 8.3 and extensions..."
    nala install -y \
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
        php8.3-redis > /dev/null 2>&1
    print_success "PHP 8.3 installed"
else
    print_success "PHP 8.3 already installed"
fi
php -v | head -1

# Composer Installation
next_step "Composer Installation"
if ! check_installed "composer" "Composer"; then
    print_progress "Installing Composer..."
    curl -sS https://getcomposer.org/installer | php > /dev/null 2>&1
    mv composer.phar /usr/local/bin/composer
    chmod +x /usr/local/bin/composer
    print_success "Composer installed"
fi
composer --version | head -1

# Node.js 24 Installation
next_step "Node.js 24 Installation"
if ! command -v node &> /dev/null || ! node -v | grep -q "v24"; then
    print_progress "Installing Node.js 24..."
    curl -fsSL https://deb.nodesource.com/setup_24.x | bash - > /dev/null 2>&1
    nala install -y nodejs > /dev/null 2>&1
    print_success "Node.js 24 installed"
else
    print_success "Node.js 24 already installed"
fi
node -v
npm -v

# PM2 Installation
print_info "Installing PM2 process manager..."
if ! command -v pm2 &> /dev/null; then
    npm install -g pm2 > /dev/null 2>&1
    pm2 startup systemd -u www-data --hp /var/www > /dev/null 2>&1
    print_success "PM2 installed"
else
    print_success "PM2 already installed"
fi
pm2 -v

# Go 1.23 Installation
next_step "Go 1.23 Installation"
if ! check_installed "go" "Go"; then
    print_progress "Installing Go 1.23..."
    wget -q https://go.dev/dl/go1.23.4.linux-amd64.tar.gz
    tar -C /usr/local -xzf go1.23.4.linux-amd64.tar.gz > /dev/null 2>&1
    echo 'export PATH=$PATH:/usr/local/go/bin' >> /etc/profile
    export PATH=$PATH:/usr/local/go/bin
    rm go1.23.4.linux-amd64.tar.gz
    print_success "Go 1.23 installed"
fi
/usr/local/go/bin/go version

# Asterisk 22 Installation
next_step "Asterisk 22 Installation"
SKIP_ASTERISK=""

if command -v asterisk &> /dev/null; then
    ASTERISK_VERSION=$(asterisk -V 2>/dev/null | grep -oP '\d+' | head -1)
    if [ "$ASTERISK_VERSION" -ge 22 ]; then
        print_success "Asterisk $ASTERISK_VERSION already installed"
        asterisk -V
        SKIP_ASTERISK=1
    else
        print_warning "Asterisk $ASTERISK_VERSION found (version 22+ recommended)"
        read -p "$(echo -e ${YELLOW}Upgrade to Asterisk 22? \(y/n\)${RESET} )" -n 1 -r
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
    wget -q --show-progress https://downloads.asterisk.org/pub/telephony/asterisk/asterisk-22-current.tar.gz
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
systemctl restart asterisk

# Check Asterisk status
sleep 3
if systemctl is-active --quiet asterisk; then
    print_success "Asterisk service is running"
    print_info "Active channels: $(asterisk -rx 'core show channels' 2>/dev/null | grep 'active channel' || echo '0 active channels')"
else
    print_error "Failed to start Asterisk"
    print_warning "Check status with: systemctl status asterisk"
    print_warning "Check logs with: journalctl -u asterisk -n 50"
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
    systemctl reload asterisk
fi

# Setup unified .env file
next_step "Environment Configuration"
if [ ! -f ".env" ]; then
    print_progress "Creating unified environment configuration..."
    cp .env.example .env
    
    # Update database password
    sed -i "s/DB_PASSWORD=.*/DB_PASSWORD=$ESCAPED_DB_PASSWORD/" .env
    sed -i "s/DB_DATABASE=.*/DB_DATABASE=rayanpbx/" .env
    sed -i "s/DB_USERNAME=.*/DB_USERNAME=rayanpbx/" .env
    
    # Generate JWT secret
    JWT_SECRET=$(openssl rand -base64 32)
    sed -i "s|JWT_SECRET=.*|JWT_SECRET=$JWT_SECRET|" .env
    
    print_success "Environment configured"
else
    print_success "Environment file already exists"
fi

# Backend Setup
next_step "Backend API Setup"
print_progress "Installing backend dependencies..."
cd /opt/rayanpbx/backend
composer install --no-dev --optimize-autoloader 2>&1 | grep -E "(Installing|Generating)" || true

print_progress "Running database migrations..."
php artisan migrate --force

if [ $? -eq 0 ]; then
    print_success "Backend configured successfully"
else
    print_error "Database migration failed"
    exit 1
fi

# Frontend Setup
next_step "Frontend Web UI Setup"
print_progress "Installing frontend dependencies..."
cd /opt/rayanpbx/frontend
npm install 2>&1 | grep -E "(added|up to date)" | tail -1

print_progress "Building frontend..."
npm run build 2>&1 | tail -10

print_success "Frontend built successfully"

# TUI Setup
next_step "TUI (Terminal UI) Build"
print_progress "Building TUI application..."
cd /opt/rayanpbx/tui
/usr/local/go/bin/go mod download
/usr/local/go/bin/go build -o /usr/local/bin/rayanpbx-tui main.go config.go
chmod +x /usr/local/bin/rayanpbx-tui

print_success "TUI built: /usr/local/bin/rayanpbx-tui"

# WebSocket Server Setup
print_progress "Building WebSocket server..."
/usr/local/go/bin/go build -o /usr/local/bin/rayanpbx-ws websocket.go config.go
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

# Backend API service
cat > /etc/systemd/system/rayanpbx-api.service << 'EOF'
[Unit]
Description=RayanPBX API Server
After=network.target mysql.service asterisk.service redis-server.service

[Service]
Type=simple
User=www-data
WorkingDirectory=/opt/rayanpbx/backend
ExecStart=/usr/bin/php artisan serve --host=0.0.0.0 --port=8000
Restart=always
RestartSec=3
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
EOF

print_success "Created rayanpbx-api.service"

# Reload systemd
systemctl daemon-reload

# Enable and start services
print_progress "Starting services..."
systemctl enable rayanpbx-api > /dev/null 2>&1
systemctl restart rayanpbx-api

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

if systemctl is-active --quiet rayanpbx-api; then
    print_success "✓ API service running"
else
    print_warning "✗ API service failed - check: systemctl status rayanpbx-api"
fi

if su - www-data -s /bin/bash -c "pm2 list" | grep -q "rayanpbx-web.*online"; then
    print_success "✓ Web service running (PM2)"
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
    ASTERISK_VERSION=$(asterisk -V 2>/dev/null | head -1)
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
echo -e "  ${GREEN}✓${RESET} API Server      : http://$(hostname -I | awk '{print $1}'):8000/api"
echo -e "  ${GREEN}✓${RESET} Web Interface   : http://$(hostname -I | awk '{print $1}'):3000"
echo -e "  ${GREEN}✓${RESET} WebSocket Server: ws://$(hostname -I | awk '{print $1}'):9000/ws"
echo -e "  ${GREEN}✓${RESET} TUI Terminal    : ${WHITE}rayanpbx-tui${RESET}"
echo ""

echo -e "${BOLD}${CYAN}🔐 Default Login (Development):${RESET}"
echo -e "  ${YELLOW}Username:${RESET} admin"
echo -e "  ${YELLOW}Password:${RESET} admin"
echo ""

echo -e "${BOLD}${CYAN}📁 File Locations:${RESET}"
echo -e "  ${DIM}Configuration:${RESET} /opt/rayanpbx/.env"
echo -e "  ${DIM}Asterisk:${RESET}      /etc/asterisk/"
echo -e "  ${DIM}Logs:${RESET}          /var/log/rayanpbx/"
echo ""

echo -e "${BOLD}${CYAN}🛠️  Useful Commands:${RESET}"
echo -e "  ${DIM}View services:${RESET}     pm2 list"
echo -e "  ${DIM}View logs:${RESET}         pm2 logs"
echo -e "  ${DIM}Asterisk CLI:${RESET}      asterisk -rvvv"
echo -e "  ${DIM}System status:${RESET}     systemctl status rayanpbx-api"
echo ""

echo -e "${BOLD}${CYAN}🚀 Next Steps:${RESET}"
echo -e "  ${GREEN}1.${RESET} Access web UI: http://$(hostname -I | awk '{print $1}'):3000"
echo -e "  ${GREEN}2.${RESET} Login with admin/admin"
echo -e "  ${GREEN}3.${RESET} Configure your first extension"
echo -e "  ${GREEN}4.${RESET} Set up a SIP trunk"
echo -e "  ${GREEN}5.${RESET} Test your setup"
echo ""

echo -e "${BOLD}${CYAN}📚 Documentation & Support:${RESET}"
echo -e "  ${DIM}GitHub:${RESET}  https://github.com/atomicdeploy/rayanpbx"
echo -e "  ${DIM}Issues:${RESET}  https://github.com/atomicdeploy/rayanpbx/issues"
echo ""

print_box "Thank you for installing RayanPBX! 💙" "$CYAN"
echo ""
