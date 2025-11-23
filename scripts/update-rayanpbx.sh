#!/bin/bash

# RayanPBX Update Script
# One-command update from git repository

set -euo pipefail

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
MAGENTA='\033[0;35m'
NC='\033[0m'

# Emojis
ROCKET="🚀"
CHECK="✅"
CROSS="❌"
INFO="ℹ️ "
WARN="⚠️ "

# Configuration
RAYANPBX_ROOT="${RAYANPBX_ROOT:-/opt/rayanpbx}"
BACKUP_DIR="/tmp/rayanpbx-backup-$(date +%Y%m%d-%H%M%S)"

print_header() {
    echo -e "${MAGENTA}"
    echo "╔════════════════════════════════════════════════════╗"
    echo "║  $ROCKET RayanPBX Update Utility $ROCKET                   ║"
    echo "╚════════════════════════════════════════════════════╝"
    echo -e "${NC}"
}

print_success() {
    echo -e "${GREEN}$CHECK $1${NC}"
}

print_error() {
    echo -e "${RED}$CROSS $1${NC}"
}

print_info() {
    echo -e "${CYAN}$INFO$1${NC}"
}

print_warn() {
    echo -e "${YELLOW}$WARN$1${NC}"
}

print_step() {
    echo -e "\n${CYAN}▶ $1${NC}"
}

# Check if running as root
if [ "$EUID" -ne 0 ]; then
    print_error "Please run as root (use sudo)"
    exit 1
fi

# Check if RayanPBX directory exists
if [ ! -d "$RAYANPBX_ROOT" ]; then
    print_error "RayanPBX directory not found: $RAYANPBX_ROOT"
    exit 1
fi

# Check if it's a git repository
if [ ! -d "$RAYANPBX_ROOT/.git" ]; then
    print_error "Not a git repository: $RAYANPBX_ROOT"
    exit 1
fi

cd "$RAYANPBX_ROOT"

print_header

# Step 1: Backup current installation
print_step "Creating backup..."
mkdir -p "$BACKUP_DIR"
cp -r "$RAYANPBX_ROOT"/.env "$BACKUP_DIR/" 2>/dev/null || true
cp -r "$RAYANPBX_ROOT"/backend/storage "$BACKUP_DIR/" 2>/dev/null || true
print_success "Backup created: $BACKUP_DIR"

# Step 2: Check for local changes
print_step "Checking for local changes..."
if git diff-index --quiet HEAD --; then
    print_info "No local changes"
else
    print_warn "Local changes detected"
    print_info "Stashing local changes..."
    git stash push -m "Auto-stash before update $(date)"
fi

# Step 3: Fetch latest changes
print_step "Fetching latest changes from repository..."
git fetch origin

# Get current and latest commit
CURRENT_COMMIT=$(git rev-parse HEAD)
LATEST_COMMIT=$(git rev-parse origin/main)

if [ "$CURRENT_COMMIT" == "$LATEST_COMMIT" ]; then
    print_success "Already up to date!"
    exit 0
fi

# Show changelog
print_step "Changelog:"
git log --oneline "$CURRENT_COMMIT".."$LATEST_COMMIT" | head -10

# Step 4: Pull changes
print_step "Pulling latest changes..."
if git pull origin main; then
    print_success "Code updated successfully"
else
    print_error "Failed to pull changes"
    print_info "Restoring from backup..."
    git reset --hard "$CURRENT_COMMIT"
    exit 1
fi

# Step 5: Update backend dependencies
print_step "Updating backend dependencies..."
if [ -d "$RAYANPBX_ROOT/backend" ]; then
    cd "$RAYANPBX_ROOT/backend"
    if [ -f "composer.json" ]; then
        composer install --no-dev --optimize-autoloader
        print_success "Backend dependencies updated"
    fi
fi

# Step 6: Update frontend dependencies
print_step "Updating frontend dependencies..."
if [ -d "$RAYANPBX_ROOT/frontend" ]; then
    cd "$RAYANPBX_ROOT/frontend"
    if [ -f "package.json" ]; then
        npm install --production
        npm run build 2>/dev/null || true
        print_success "Frontend dependencies updated"
    fi
fi

# Step 7: Update TUI dependencies
print_step "Updating TUI dependencies..."
if [ -d "$RAYANPBX_ROOT/tui" ]; then
    cd "$RAYANPBX_ROOT/tui"
    if [ -f "go.mod" ]; then
        go mod download
        go build -o rayanpbx-tui
        print_success "TUI rebuilt"
    fi
fi

# Step 8: Run database migrations
print_step "Running database migrations..."
cd "$RAYANPBX_ROOT/backend"
if [ -f "artisan" ]; then
    php artisan migrate --force
    print_success "Database migrations complete"
fi

# Step 9: Clear caches
print_step "Clearing caches..."
php artisan cache:clear 2>/dev/null || true
php artisan config:clear 2>/dev/null || true
php artisan route:clear 2>/dev/null || true
print_success "Caches cleared"

# Step 10: Restart services
print_step "Restarting services..."

# Check which services are running
RESTART_NEEDED=()

if systemctl is-active --quiet asterisk; then
    RESTART_NEEDED+=("asterisk")
fi

if systemctl is-active --quiet rayanpbx-api 2>/dev/null; then
    RESTART_NEEDED+=("rayanpbx-api")
fi

if pm2 list | grep -q "rayanpbx-frontend"; then
    RESTART_NEEDED+=("pm2")
fi

if [ ${#RESTART_NEEDED[@]} -gt 0 ]; then
    print_warn "The following services need to be restarted:"
    for service in "${RESTART_NEEDED[@]}"; do
        echo "  • $service"
    done
    
    read -p "Restart services now? (y/n) " -n 1 -r
    echo
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        for service in "${RESTART_NEEDED[@]}"; do
            if [ "$service" == "pm2" ]; then
                pm2 restart rayanpbx-frontend 2>/dev/null || true
            else
                systemctl restart "$service" 2>/dev/null || true
            fi
            print_success "$service restarted"
        done
    else
        print_warn "Services not restarted. Restart manually when ready."
    fi
else
    print_info "No services to restart"
fi

# Step 11: Verify installation
print_step "Verifying installation..."
ERRORS=0

# Check API
if curl -s -o /dev/null -w "%{http_code}" "http://localhost:8000" | grep -q "200\|302"; then
    print_success "API is responding"
else
    print_warn "API not responding (may need manual restart)"
    ((ERRORS++))
fi

# Check database
if mysql -u root -e "USE rayanpbx;" 2>/dev/null; then
    print_success "Database is accessible"
else
    print_warn "Database connection issue"
    ((ERRORS++))
fi

# Final status
echo
echo -e "${MAGENTA}════════════════════════════════════════════════════${NC}"
if [ $ERRORS -eq 0 ]; then
    print_success "Update completed successfully! $ROCKET"
    echo
    print_info "RayanPBX is now running the latest version"
    print_info "Previous version backed up to: $BACKUP_DIR"
else
    print_warn "Update completed with warnings"
    print_info "Please check the services manually"
fi
echo -e "${MAGENTA}════════════════════════════════════════════════════${NC}"
echo

# Show version info
print_info "Current version:"
git log -1 --oneline

exit 0
