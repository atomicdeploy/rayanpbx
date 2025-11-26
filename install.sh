#!/bin/bash

set -e

# RayanPBX Installation Script for Ubuntu 24.04 LTS
# This script installs and configures RayanPBX with Asterisk 22

# Script version - read from VERSION file
SCRIPT_VERSION="2.0.0"
if [ -f "$(dirname "${BASH_SOURCE[0]}")/VERSION" ]; then
    SCRIPT_VERSION=$(cat "$(dirname "${BASH_SOURCE[0]}")/VERSION" | tr -d '[:space:]')
fi
readonly SCRIPT_VERSION

# ════════════════════════════════════════════════════════════════════════
# Configuration Variables
# ════════════════════════════════════════════════════════════════════════

VERBOSE=false
DRY_RUN=false
UPGRADE_MODE=false
CREATE_BACKUP=false
ONLY_STEPS=""
SKIP_STEPS=""
CI_MODE=false

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
# Step Definitions and Management
# ════════════════════════════════════════════════════════════════════════

# Define all available installation steps with their identifiers
# Format: "step_id:Step Name"
declare -a ALL_STEPS=(
    "updates:Checking for Updates"
    "system-verification:System Verification"
    "package-manager:Package Manager Setup"
    "system-update:System Update"
    "dependencies:Essential Dependencies"
    "github-cli:GitHub CLI Installation"
    "database:Database Setup (MySQL/MariaDB)"
    "php:PHP 8.3 Installation"
    "composer:Composer Installation"
    "nodejs:Node.js 24 Installation"
    "go:Go 1.23 Installation"
    "asterisk:Asterisk 22 Installation"
    "asterisk-ami:Asterisk AMI Configuration"
    "source:RayanPBX Source Code"
    "env-config:Environment Configuration"
    "backend:Backend API Setup"
    "frontend:Frontend Web UI Setup"
    "tui:TUI (Terminal UI) Build"
    "pm2:PM2 Process Management Setup"
    "systemd:Systemd Services Configuration"
    "cron:Cron Jobs Setup"
    "health-check:Service Verification & Health Checks"
    "complete:Installation Complete"
)

# Array to track which steps should be executed
declare -a STEPS_TO_RUN=()

# Initialize steps to run based on command line arguments
initialize_steps() {
    if [ -n "$ONLY_STEPS" ]; then
        # User specified only certain steps to run
        IFS=',' read -ra SELECTED_STEPS <<< "$ONLY_STEPS"
        for step_id in "${SELECTED_STEPS[@]}"; do
            STEPS_TO_RUN+=("$step_id")
        done
        print_verbose "Running only steps: ${STEPS_TO_RUN[*]}"
        
        # Warn about potential missing dependencies
        if [ ${#STEPS_TO_RUN[@]} -lt ${#ALL_STEPS[@]} ]; then
            echo ""
            print_warning "Running only selected steps. Ensure dependencies are met:"
            echo -e "${DIM}   - backend requires: database, php, composer, source, env-config${RESET}"
            echo -e "${DIM}   - frontend requires: nodejs, source, env-config${RESET}"
            echo -e "${DIM}   - tui requires: go, source${RESET}"
            echo -e "${DIM}   - pm2 requires: nodejs, frontend, tui${RESET}"
            echo -e "${DIM}   - systemd requires: backend${RESET}"
            echo -e "${DIM}   - health-check requires: systemd, pm2${RESET}"
            echo -e "${DIM}   See INSTALL_STEPS_GUIDE.md for detailed dependency information.${RESET}"
            echo ""
        fi
    else
        # Run all steps by default
        for step_def in "${ALL_STEPS[@]}"; do
            step_id="${step_def%%:*}"
            STEPS_TO_RUN+=("$step_id")
        done
    fi
    
    # Remove skipped steps if any
    if [ -n "$SKIP_STEPS" ]; then
        IFS=',' read -ra SKIPPED_STEPS <<< "$SKIP_STEPS"
        local temp_steps=()
        for step_id in "${STEPS_TO_RUN[@]}"; do
            local skip_this=false
            for skip_id in "${SKIPPED_STEPS[@]}"; do
                if [ "$step_id" == "$skip_id" ]; then
                    skip_this=true
                    break
                fi
            done
            if [ "$skip_this" == false ]; then
                temp_steps+=("$step_id")
            fi
        done
        STEPS_TO_RUN=("${temp_steps[@]}")
        print_verbose "Skipping steps: ${SKIPPED_STEPS[*]}"
        
        # Warn if critical steps are skipped
        if [ ${#SKIPPED_STEPS[@]} -gt 0 ]; then
            echo ""
            print_warning "Skipping steps may cause issues if dependencies are not met."
            echo -e "${DIM}   Skipped: ${SKIPPED_STEPS[*]}${RESET}"
            echo ""
        fi
    fi
}

# Check if a step should be executed
should_run_step() {
    local step_id="$1"
    for run_step in "${STEPS_TO_RUN[@]}"; do
        if [ "$run_step" == "$step_id" ]; then
            return 0
        fi
    done
    return 1
}

# Get step name from step ID
get_step_name() {
    local step_id="$1"
    for step_def in "${ALL_STEPS[@]}"; do
        if [[ "$step_def" == "$step_id:"* ]]; then
            echo "${step_def#*:}"
            return 0
        fi
    done
    echo "Unknown Step"
    return 1
}

# List all available steps
list_steps() {
    echo -e "${CYAN}${BOLD}Available Installation Steps:${RESET}"
    echo ""
    local num=1
    for step_def in "${ALL_STEPS[@]}"; do
        step_id="${step_def%%:*}"
        step_name="${step_def#*:}"
        printf "  ${GREEN}%2d.${RESET} ${WHITE}%-20s${RESET} ${DIM}%s${RESET}\n" "$num" "$step_id" "$step_name"
        num=$((num + 1))
    done
    echo ""
    echo -e "${YELLOW}${BOLD}Usage Examples:${RESET}"
    echo -e "  ${DIM}# Run only specific steps:${RESET}"
    echo -e "  ${WHITE}sudo ./install.sh --steps=backend,frontend,tui${RESET}"
    echo ""
    echo -e "  ${DIM}# Skip certain steps:${RESET}"
    echo -e "  ${WHITE}sudo ./install.sh --skip=asterisk,asterisk-ami${RESET}"
    echo ""
    echo -e "  ${DIM}# Combine with other flags:${RESET}"
    echo -e "  ${WHITE}sudo ./install.sh --steps=backend --verbose${RESET}"
    echo ""
}

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
    echo -e "    ${GREEN}-h, --help${RESET}              Show this help message and exit"
    echo -e "    ${GREEN}-u, --upgrade${RESET}           Automatically apply updates without prompting"
    echo -e "    ${GREEN}-b, --backup${RESET}            Create backup before updates (.env and backend/storage)"
    echo -e "    ${GREEN}-v, --verbose${RESET}           Enable verbose output (shows detailed execution)"
    echo -e "    ${GREEN}-V, --version${RESET}           Show script version and exit"
    echo -e "    ${GREEN}--steps=STEPS${RESET}           Run only specified steps (comma-separated)"
    echo -e "    ${GREEN}--skip=STEPS${RESET}            Skip specified steps (comma-separated)"
    echo -e "    ${GREEN}--list-steps${RESET}            Show all available steps and exit"
    echo -e "    ${GREEN}--ci${RESET}                    CI mode (skip root check, non-interactive)"
    echo -e "    ${GREEN}--dry-run${RESET}               Simulate installation without making changes (not yet implemented)"
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
    echo -e "    ${DIM}# Automatic upgrade without prompts${RESET}"
    echo -e "    ${WHITE}sudo ./install.sh --upgrade${RESET}"
    echo ""
    echo -e "    ${DIM}# Run only backend and frontend setup${RESET}"
    echo -e "    ${WHITE}sudo ./install.sh --steps=backend,frontend${RESET}"
    echo ""
    echo -e "    ${DIM}# Skip Asterisk installation${RESET}"
    echo -e "    ${WHITE}sudo ./install.sh --skip=asterisk,asterisk-ami${RESET}"
    echo ""
    echo -e "    ${DIM}# List all available steps${RESET}"
    echo -e "    ${WHITE}./install.sh --list-steps${RESET}"
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
    # Only clear if TERM is set and not "dumb" (avoid errors in CI environments without TTY)
    if [ -n "${TERM:-}" ] && [ "${TERM}" != "dumb" ]; then
        clear
    fi
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
    local step_name="$1"
    local step_id="${2:-}"
    
    # If step_id is provided, check if it should run
    if [ -n "$step_id" ]; then
        if ! should_run_step "$step_id"; then
            print_verbose "Skipping step: $step_name (id: $step_id)"
            return 1
        fi
    fi
    
    STEP_NUMBER=$((STEP_NUMBER + 1))
    
    # Print step name in grey/dim or normal based on verbose mode
    if [ "$VERBOSE" = true ]; then
        echo -e "\n${BLUE}${BOLD}┌─ Step ${STEP_NUMBER}: $step_name${RESET} ${DIM}[$step_id]${RESET}"
    else
        echo -e "\n${BLUE}${BOLD}┌─ Step ${STEP_NUMBER}: $step_name${RESET}"
        echo -e "${DIM}└─────────────────────────────────────────────────────────────${RESET}"
    fi
    
    if [ "$VERBOSE" = true ]; then
        echo -e "${DIM}└─────────────────────────────────────────────────────────────${RESET}"
    fi
    
    return 0
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

# URL encode a string for use in URLs
# Uses Python if available for proper encoding, falls back to sed-based encoding
url_encode() {
    local string="$1"
    
    # Try Python first for proper encoding
    if command -v python3 &> /dev/null; then
        printf '%s' "$string" | python3 -c "import sys, urllib.parse; print(urllib.parse.quote_plus(sys.stdin.read()))" 2>/dev/null
        return
    fi
    
    # Fallback to sed-based encoding (covers common characters)
    printf '%s' "$string" | sed -e 's/%/%25/g' \
        -e 's/ /%20/g' \
        -e 's/!/%21/g' \
        -e 's/"/%22/g' \
        -e 's/#/%23/g' \
        -e 's/\$/%24/g' \
        -e 's/\&/%26/g' \
        -e "s/'/%27/g" \
        -e 's/(/%28/g' \
        -e 's/)/%29/g' \
        -e 's/\*/%2A/g' \
        -e 's/+/%2B/g' \
        -e 's/,/%2C/g' \
        -e 's/:/%3A/g' \
        -e 's/;/%3B/g' \
        -e 's/</%3C/g' \
        -e 's/=/%3D/g' \
        -e 's/>/%3E/g' \
        -e 's/?/%3F/g' \
        -e 's/@/%40/g' \
        -e 's/\[/%5B/g' \
        -e 's/\\/%5C/g' \
        -e 's/\]/%5D/g' \
        -e 's/\^/%5E/g' \
        -e 's/{/%7B/g' \
        -e 's/|/%7C/g' \
        -e 's/}/%7D/g' \
        -e 's/~/%7E/g'
}

# Get dynamic system context for AI prompts
# Detects OS version and Asterisk version dynamically
get_system_context() {
    local os_info=""
    local asterisk_info=""
    
    # Get OS version dynamically with proper empty string handling
    if [ -f /etc/os-release ]; then
        local pretty_name
        pretty_name=$(grep "^PRETTY_NAME=" /etc/os-release 2>/dev/null | cut -d'"' -f2)
        if [ -n "$pretty_name" ]; then
            os_info="$pretty_name"
        else
            os_info="Linux"
        fi
    elif command -v lsb_release &> /dev/null; then
        local lsb_desc
        lsb_desc=$(lsb_release -d 2>/dev/null | cut -f2)
        if [ -n "$lsb_desc" ]; then
            os_info="$lsb_desc"
        else
            os_info="Linux"
        fi
    else
        os_info="Linux"
    fi
    
    # Get Asterisk version dynamically with timeout to prevent hanging
    if command -v asterisk &> /dev/null; then
        local ast_version
        ast_version=$(timeout 5 asterisk -V 2>/dev/null | head -n 1)
        if [ -n "$ast_version" ]; then
            asterisk_info="$ast_version"
        else
            asterisk_info="Asterisk"
        fi
    else
        asterisk_info="Asterisk (not installed)"
    fi
    
    echo "System: ${os_info}, ${asterisk_info}, PJSIP stack."
}

# Query Pollinations.AI for AI-powered solutions
# Handles timeouts and error cases gracefully
# Automatically includes dynamically detected system context in the prompt
# Temporary file for storing full AI conversation (created securely per session)
AI_RESPONSE_FILE=""

query_pollinations_ai() {
    local query="$1"
    local max_lines="${2:-15}"
    
    # Create secure temporary file if not already created
    if [ -z "$AI_RESPONSE_FILE" ] || [ ! -f "$AI_RESPONSE_FILE" ]; then
        AI_RESPONSE_FILE=$(mktemp /tmp/rayanpbx-ai-response.XXXXXX)
        chmod 600 "$AI_RESPONSE_FILE"
    fi
    
    # Build system context prompt dynamically (without sensitive info)
    # This helps AI provide more accurate solutions for our specific setup
    local system_context=$(get_system_context)
    
    # Combine system context with user query
    local full_query="${system_context} ${query}"
    
    # URL encode the query
    local encoded_query=$(url_encode "$full_query")
    
    # Fetch solution from Pollinations.AI with proper timeout and error handling
    # --connect-timeout: max time for connection establishment
    # --max-time: max total time for the entire operation
    local response=""
    response=$(curl -s --connect-timeout 10 --max-time 30 \
        -H "User-Agent: RayanPBX-Installer" \
        "https://text.pollinations.ai/${encoded_query}" 2>/dev/null)
    
    local curl_exit=$?
    
    # Check for curl errors
    if [ $curl_exit -ne 0 ]; then
        echo ""
        return 1
    fi
    
    # Save the full query and response to temp file for later viewing
    {
        echo "════════════════════════════════════════════════════════════════════════"
        echo "RayanPBX AI Consultation - $(date)"
        echo "════════════════════════════════════════════════════════════════════════"
        echo ""
        echo "📤 QUERY SENT:"
        echo "────────────────────────────────────────────────────────────────────────"
        echo "$full_query"
        echo ""
        echo "📥 AI RESPONSE:"
        echo "────────────────────────────────────────────────────────────────────────"
        echo "$response"
        echo ""
        echo "════════════════════════════════════════════════════════════════════════"
        echo ""
        echo "This file can be viewed with: cat $AI_RESPONSE_FILE"
    } > "$AI_RESPONSE_FILE"
    chmod 600 "$AI_RESPONSE_FILE"
    
    # Count total lines in response (grep -c '.' handles files without trailing newline)
    local total_lines=$(printf '%s\n' "$response" | grep -c '.')
    
    # Truncate at complete lines instead of characters
    if [ "$total_lines" -gt "$max_lines" ]; then
        printf '%s\n' "$response" | head -n "$max_lines"
        echo ""
        echo -e "${DIM}$((($total_lines - $max_lines)) more lines available...)${RESET}"
        echo ""
        echo -e "📄 ${YELLOW}To view the full response run:${RESET}"
        echo -e "cat ${DIM}$AI_RESPONSE_FILE${RESET}"
    else
        echo "$response"
    fi
    
    return 0
}

handle_asterisk_error() {
    local error_msg="$1"
    local context="${2:-Asterisk operation}"
    
    print_error "$context failed"
    print_warning "Error: $error_msg"
    echo ""
    echo -e "${CYAN}🔍 Checking for solutions...${RESET}"
    echo ""
    
    # Query Pollinations.AI for solution (max 10 lines displayed)
    local solution=$(query_pollinations_ai "$error_msg $context" 10)
    
    if [ -n "$solution" ]; then
        echo -e "${YELLOW}${BOLD}💡 Suggested solution:${RESET}"
        echo -e "${DIM}${solution}${RESET}"
        echo ""
    else
        echo -e "${DIM}Could not retrieve solution automatically. Check your internet connection.${RESET}"
        echo ""
    fi
}

# Perform automated AMI checks and report results
# This function automatically checks all the items that would otherwise be
# listed as "Please check the following:" for manual verification
perform_ami_diagnostic_checks() {
    local ami_host="${1:-127.0.0.1}"
    local ami_port="${2:-5038}"
    local ami_username="${3:-admin}"
    local ami_secret="${4:-rayanpbx_ami_secret}"
    
    echo ""
    echo -e "${CYAN}${BOLD}🔍 Performing Automated AMI Diagnostic Checks...${RESET}"
    echo -e "${DIM}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${RESET}"
    echo ""
    
    local issues_found=()
    local check_results=""
    
    # Check 1: Verify /etc/asterisk/manager.conf exists and is configured
    echo -e "${CYAN}Check 1/5:${RESET} Verifying /etc/asterisk/manager.conf..."
    if [ -f "/etc/asterisk/manager.conf" ]; then
        print_success "manager.conf exists"
        
        # Check if file has content (not empty)
        if [ -s "/etc/asterisk/manager.conf" ]; then
            print_success "manager.conf has content"
            check_results="${check_results}✓ manager.conf exists and has content\n"
        else
            print_error "manager.conf is empty"
            issues_found+=("manager.conf is empty - needs configuration")
            check_results="${check_results}✗ manager.conf is empty\n"
        fi
    else
        print_error "manager.conf does not exist"
        issues_found+=("/etc/asterisk/manager.conf file not found")
        check_results="${check_results}✗ manager.conf not found\n"
    fi
    echo ""
    
    # Check 2: Ensure AMI is enabled (enabled = yes in [general] section)
    echo -e "${CYAN}Check 2/5:${RESET} Checking if AMI is enabled in configuration..."
    if [ -f "/etc/asterisk/manager.conf" ]; then
        # Check for enabled = yes in [general] section
        local ami_enabled=$(grep -A20 '^\[general\]' /etc/asterisk/manager.conf 2>/dev/null | grep -E '^enabled\s*=\s*yes' | head -1)
        if [ -n "$ami_enabled" ]; then
            print_success "AMI is enabled in [general] section"
            check_results="${check_results}✓ AMI is enabled (enabled = yes)\n"
        else
            # Check if it's disabled or not set
            local ami_disabled=$(grep -A20 '^\[general\]' /etc/asterisk/manager.conf 2>/dev/null | grep -E '^enabled\s*=\s*no' | head -1)
            if [ -n "$ami_disabled" ]; then
                print_error "AMI is explicitly disabled"
                issues_found+=("AMI is disabled - change 'enabled = no' to 'enabled = yes' in [general] section")
                check_results="${check_results}✗ AMI is disabled (enabled = no)\n"
            else
                print_warning "Cannot confirm AMI is enabled - missing 'enabled = yes' in [general]"
                issues_found+=("AMI may not be enabled - add 'enabled = yes' to [general] section")
                check_results="${check_results}? AMI enable status unclear\n"
            fi
        fi
    else
        print_error "Cannot check - manager.conf not found"
        check_results="${check_results}✗ Cannot check AMI enable status\n"
    fi
    echo ""
    
    # Check 3: Check if port 5038 is not blocked by firewall
    echo -e "${CYAN}Check 3/5:${RESET} Checking if port ${ami_port} is accessible..."
    if is_port_listening "$ami_port"; then
        print_success "Port $ami_port is listening"
        check_results="${check_results}✓ Port $ami_port is listening\n"
        
        # Show what process is listening
        local listen_info=""
        if command -v ss &> /dev/null; then
            listen_info=$(ss -tunlp 2>/dev/null | grep ":${ami_port}" | head -1)
        elif command -v netstat &> /dev/null; then
            listen_info=$(netstat -tunlp 2>/dev/null | grep ":${ami_port}" | head -1)
        fi
        if [ -n "$listen_info" ]; then
            print_info "Listener: $listen_info"
        fi
    else
        print_error "Port $ami_port is NOT listening"
        issues_found+=("AMI port $ami_port is not listening - Asterisk may not have started AMI")
        check_results="${check_results}✗ Port $ami_port is not listening\n"
        
        # Check if firewall might be blocking
        if command -v ufw &> /dev/null; then
            local ufw_status=$(ufw status 2>/dev/null | grep -i active)
            if [ -n "$ufw_status" ]; then
                print_warning "UFW firewall is active - check if port $ami_port is allowed"
                check_results="${check_results}? UFW firewall is active\n"
            fi
        fi
        if command -v iptables &> /dev/null; then
            local iptables_drop=$(iptables -L INPUT -n 2>/dev/null | grep -E "DROP.*$ami_port")
            if [ -n "$iptables_drop" ]; then
                print_warning "iptables may be blocking port $ami_port"
                issues_found+=("iptables may be blocking port $ami_port")
                check_results="${check_results}? iptables may be blocking port\n"
            fi
        fi
    fi
    echo ""
    
    # Check 4: Verify Asterisk service is running
    echo -e "${CYAN}Check 4/5:${RESET} Checking Asterisk service status..."
    if systemctl is-active --quiet asterisk 2>/dev/null; then
        print_success "Asterisk service is running"
        check_results="${check_results}✓ Asterisk service is running\n"
        
        # Get uptime info
        local uptime_info=$(systemctl show asterisk --property=ActiveEnterTimestamp --value 2>/dev/null)
        if [ -n "$uptime_info" ]; then
            print_info "Started: $uptime_info"
        fi
    else
        print_error "Asterisk service is NOT running"
        issues_found+=("Asterisk service is not running")
        check_results="${check_results}✗ Asterisk service is not running\n"
        
        # Get recent error from journal
        local recent_error=$(journalctl -u asterisk -n 5 --no-pager 2>/dev/null | grep -i "error\|fail" | tail -1)
        if [ -n "$recent_error" ]; then
            print_warning "Recent error: $recent_error"
            check_results="${check_results}  Error: $recent_error\n"
        fi
    fi
    echo ""
    
    # Check 5: Try to reload Asterisk manager and test connection
    echo -e "${CYAN}Check 5/5:${RESET} Testing AMI connection..."
    if systemctl is-active --quiet asterisk 2>/dev/null; then
        # Try to reload manager
        print_info "Attempting to reload Asterisk manager..."
        asterisk -rx "manager reload" > /dev/null 2>&1 || true
        sleep 1
        
        # Test AMI connection with netcat if available
        if command -v nc &> /dev/null; then
            # Use a temporary file for AMI credentials to avoid exposure in process list
            local ami_temp_file=""
            old_umask=$(umask)
            umask 077
            ami_temp_file=$(mktemp -t rayanpbx-ami.XXXXXX 2>/dev/null)
            umask "$old_umask"
            
            if [ -n "$ami_temp_file" ] && [ -f "$ami_temp_file" ]; then
                # Write AMI login command to temp file
                printf "Action: Login\r\nUsername: %s\r\nSecret: %s\r\n\r\n" "$ami_username" "$ami_secret" > "$ami_temp_file"
                
                # Test AMI connection using temp file for credentials
                local ami_response=$(timeout 5 cat "$ami_temp_file" | nc "$ami_host" "$ami_port" 2>/dev/null | head -10)
                
                # Clean up temp file immediately after use
                rm -f "$ami_temp_file" 2>/dev/null
                
                if echo "$ami_response" | grep -qi "Success"; then
                    print_success "AMI connection and authentication successful!"
                    check_results="${check_results}✓ AMI authentication successful\n"
                elif echo "$ami_response" | grep -qi "Authentication failed"; then
                    print_error "AMI authentication failed - wrong credentials"
                    issues_found+=("AMI authentication failed - check username/secret in manager.conf")
                    check_results="${check_results}✗ AMI authentication failed\n"
                elif [ -n "$ami_response" ]; then
                    print_warning "AMI responded but with unexpected response"
                    check_results="${check_results}? AMI response unexpected\n"
                else
                    print_error "AMI did not respond - connection timeout"
                    issues_found+=("AMI connection timeout - service may not be responding")
                    check_results="${check_results}✗ AMI connection timeout\n"
                fi
            else
                print_warning "Could not create temp file for secure AMI test"
                check_results="${check_results}? AMI test skipped (temp file error)\n"
            fi
        else
            print_info "netcat (nc) not available - skipping connection test"
            check_results="${check_results}? Connection test skipped (nc not installed)\n"
        fi
    else
        print_warning "Skipping AMI connection test - Asterisk not running"
        check_results="${check_results}? Connection test skipped (Asterisk not running)\n"
    fi
    echo ""
    
    # Summary of checks
    echo -e "${DIM}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${RESET}"
    echo -e "${CYAN}${BOLD}📋 Diagnostic Summary:${RESET}"
    echo -e "${DIM}$check_results${RESET}"
    
    # If there are issues, get AI-powered solution from Pollinations.AI
    if [ ${#issues_found[@]} -gt 0 ]; then
        echo ""
        echo -e "${YELLOW}${BOLD}⚠️  Issues Found: ${#issues_found[@]}${RESET}"
        for issue in "${issues_found[@]}"; do
            echo -e "  ${RED}•${RESET} $issue"
        done
        echo ""
        
        # Build error message for AI
        local error_summary="AMI socket is not working. Issues found: "
        for issue in "${issues_found[@]}"; do
            error_summary="${error_summary}${issue}; "
        done
        
        # Use existing Pollinations.AI integration to get solution
        echo -e "${CYAN}🤖 Consulting AI for solution...${RESET}"
        echo ""
        
        # Query Pollinations.AI using the helper function (max 15 lines displayed)
        local ai_solution=$(query_pollinations_ai "Asterisk AMI $error_summary How to fix?" 15)
        
        if [ -n "$ai_solution" ]; then
            echo -e "${GREEN}${BOLD}💡 AI-Suggested Solution:${RESET}"
            echo -e "${DIM}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${RESET}"
            echo -e "${WHITE}${ai_solution}${RESET}"
            echo -e "${DIM}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${RESET}"
            echo ""
        else
            echo -e "${DIM}Could not retrieve AI solution. Check your internet connection.${RESET}"
            echo ""
        fi
        
        # Still show manual commands as fallback
        echo -e "${CYAN}${BOLD}🔧 Manual Fix Commands:${RESET}"
        echo -e "  ${WHITE}sudo rayanpbx-cli diag check-ami${RESET}"
        echo -e "  ${WHITE}sudo /opt/rayanpbx/scripts/health-check.sh check-ami${RESET}"
        echo ""
        
        return 1
    else
        echo -e "${GREEN}${BOLD}✅ All Checks Passed!${RESET}"
        echo -e "${WHITE}AMI configuration appears to be correct.${RESET}"
        echo ""
        return 0
    fi
}

# Parse manager.conf and verify/fix AMI credentials
# This function compares expected values from .env with actual values in manager.conf
# When verbose mode is enabled, it displays detailed comparison information
# Returns: 0 = no fixes needed, 1 = fixes applied successfully, 2 = fixes failed
verify_and_fix_ami_credentials() {
    local ami_host="${1:-127.0.0.1}"
    local ami_port="${2:-5038}"
    local ami_username="${3:-admin}"
    local ami_secret="${4:-rayanpbx_ami_secret}"
    local auto_fix="${5:-true}"
    
    local manager_conf="/etc/asterisk/manager.conf"
    local issues_found=()
    local fixes_applied=false
    
    print_verbose "Verifying AMI credentials in manager.conf..."
    print_verbose "Expected: host=$ami_host, port=$ami_port, user=$ami_username"
    
    # Check if manager.conf exists
    if [ ! -f "$manager_conf" ]; then
        print_warning "manager.conf not found at $manager_conf"
        
        if [ "$auto_fix" = "true" ]; then
            print_info "Creating manager.conf with required configuration..."
            
            # Create the file with proper configuration
            mkdir -p /etc/asterisk
            cat > "$manager_conf" << EOF
; Asterisk Manager Interface (AMI) Configuration
; Created by RayanPBX installer

[general]
enabled = yes
port = $ami_port
bindaddr = $ami_host

[$ami_username]
secret = $ami_secret
deny = 0.0.0.0/0.0.0.0
permit = 127.0.0.1/255.255.255.255
read = all
write = all
EOF
            
            chown asterisk:asterisk "$manager_conf" 2>/dev/null || true
            chmod 640 "$manager_conf" 2>/dev/null || true
            
            print_success "Created manager.conf with AMI configuration"
            return 1  # Fixes applied
        else
            return 2  # Cannot fix
        fi
    fi
    
    # Parse current values from manager.conf
    local current_enabled=$(grep -A20 '^\[general\]' "$manager_conf" 2>/dev/null | grep -E '^\s*enabled\s*=' | head -1 | sed 's/.*=\s*//' | tr -d '[:space:]')
    local current_port=$(grep -A20 '^\[general\]' "$manager_conf" 2>/dev/null | grep -E '^\s*port\s*=' | head -1 | sed 's/.*=\s*//' | tr -d '[:space:]')
    local current_bindaddr=$(grep -A20 '^\[general\]' "$manager_conf" 2>/dev/null | grep -E '^\s*bindaddr\s*=' | head -1 | sed 's/.*=\s*//' | tr -d '[:space:]')
    
    # Check if the expected user section exists
    local user_section_exists=false
    if grep -q "^\[$ami_username\]" "$manager_conf" 2>/dev/null; then
        user_section_exists=true
    fi
    
    local current_secret=""
    local current_permit=""
    local current_deny=""
    local current_read=""
    local current_write=""
    
    if [ "$user_section_exists" = true ]; then
        current_secret=$(grep -A20 "^\[$ami_username\]" "$manager_conf" 2>/dev/null | grep -E '^\s*secret\s*=' | head -1 | sed 's/.*=\s*//' | tr -d '[:space:]')
        current_permit=$(grep -A20 "^\[$ami_username\]" "$manager_conf" 2>/dev/null | grep -E '^\s*permit\s*=' | head -1 | sed 's/.*=\s*//' | tr -d '[:space:]')
        current_deny=$(grep -A20 "^\[$ami_username\]" "$manager_conf" 2>/dev/null | grep -E '^\s*deny\s*=' | head -1 | sed 's/.*=\s*//' | tr -d '[:space:]')
        current_read=$(grep -A20 "^\[$ami_username\]" "$manager_conf" 2>/dev/null | grep -E '^\s*read\s*=' | head -1 | sed 's/.*=\s*//' | tr -d '[:space:]')
        current_write=$(grep -A20 "^\[$ami_username\]" "$manager_conf" 2>/dev/null | grep -E '^\s*write\s*=' | head -1 | sed 's/.*=\s*//' | tr -d '[:space:]')
    fi
    
    # Display verbose comparison if enabled
    if [ "$VERBOSE" = true ]; then
        echo ""
        echo -e "${CYAN}${BOLD}📋 AMI Configuration Comparison:${RESET}"
        echo -e "${DIM}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${RESET}"
        echo ""
        echo -e "${CYAN}[general] section:${RESET}"
        
        # enabled
        if [ "$current_enabled" = "yes" ]; then
            echo -e "  enabled    : ${GREEN}$current_enabled${RESET} ${DIM}(expected: yes)${RESET} ✓"
        else
            echo -e "  enabled    : ${RED}${current_enabled:-not set}${RESET} ${DIM}(expected: yes)${RESET} ✗"
        fi
        
        # port
        if [ "$current_port" = "$ami_port" ]; then
            echo -e "  port       : ${GREEN}$current_port${RESET} ${DIM}(expected: $ami_port)${RESET} ✓"
        else
            echo -e "  port       : ${RED}${current_port:-not set}${RESET} ${DIM}(expected: $ami_port)${RESET} ✗"
        fi
        
        # bindaddr
        if [ "$current_bindaddr" = "$ami_host" ]; then
            echo -e "  bindaddr   : ${GREEN}$current_bindaddr${RESET} ${DIM}(expected: $ami_host)${RESET} ✓"
        else
            echo -e "  bindaddr   : ${YELLOW}${current_bindaddr:-not set}${RESET} ${DIM}(expected: $ami_host)${RESET} ?"
        fi
        
        echo ""
        
        if [ "$user_section_exists" = true ]; then
            echo -e "${CYAN}[$ami_username] section:${RESET}"
            
            # secret (masked for security)
            if [ "$current_secret" = "$ami_secret" ]; then
                echo -e "  secret     : ${GREEN}***matches***${RESET} ${DIM}(verified, hidden for security)${RESET} ✓"
            else
                echo -e "  secret     : ${RED}***mismatch***${RESET} ${DIM}(different from expected, hidden for security)${RESET} ✗"
            fi
            
            # deny
            if [ -n "$current_deny" ]; then
                echo -e "  deny       : ${GREEN}$current_deny${RESET} ✓"
            else
                echo -e "  deny       : ${YELLOW}not set${RESET} ?"
            fi
            
            # permit
            if [ -n "$current_permit" ]; then
                echo -e "  permit     : ${GREEN}$current_permit${RESET} ✓"
            else
                echo -e "  permit     : ${RED}not set${RESET} ✗"
            fi
            
            # read
            if [ "$current_read" = "all" ]; then
                echo -e "  read       : ${GREEN}$current_read${RESET} ${DIM}(expected: all)${RESET} ✓"
            else
                echo -e "  read       : ${RED}${current_read:-not set}${RESET} ${DIM}(expected: all)${RESET} ✗"
            fi
            
            # write
            if [ "$current_write" = "all" ]; then
                echo -e "  write      : ${GREEN}$current_write${RESET} ${DIM}(expected: all)${RESET} ✓"
            else
                echo -e "  write      : ${RED}${current_write:-not set}${RESET} ${DIM}(expected: all)${RESET} ✗"
            fi
        else
            echo -e "${RED}[$ami_username] section not found!${RESET}"
        fi
        
        echo ""
        echo -e "${DIM}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${RESET}"
    fi
    
    # Check for issues
    if [ "$current_enabled" != "yes" ]; then
        issues_found+=("AMI is not enabled (current: '$current_enabled', expected: 'yes')")
    fi
    if [ "$current_port" != "$ami_port" ]; then
        issues_found+=("AMI port is incorrect (current: '$current_port', expected: '$ami_port')")
    fi
    if [ "$user_section_exists" != true ]; then
        issues_found+=("[$ami_username] section does not exist")
    else
        if [ "$current_secret" != "$ami_secret" ]; then
            issues_found+=("AMI secret mismatch (manager.conf value differs from expected)")
        fi
        if [ "$current_read" != "all" ]; then
            issues_found+=("AMI read permission incorrect (current: '$current_read', expected: 'all')")
        fi
        if [ "$current_write" != "all" ]; then
            issues_found+=("AMI write permission incorrect (current: '$current_write', expected: 'all')")
        fi
        if [ -z "$current_permit" ]; then
            issues_found+=("AMI permit not set (should include '127.0.0.1/255.255.255.255')")
        fi
    fi
    
    # If no issues, we're done
    if [ ${#issues_found[@]} -eq 0 ]; then
        print_success "All AMI credentials are correctly configured"
        return 0
    fi
    
    # Report issues
    print_warning "Found ${#issues_found[@]} issue(s) in AMI configuration:"
    for issue in "${issues_found[@]}"; do
        echo -e "  ${RED}•${RESET} $issue"
    done
    echo ""
    
    # Auto-fix if enabled
    if [ "$auto_fix" = "true" ]; then
        print_info "Attempting to fix AMI configuration..."
        
        # Source ini-helper for proper INI file manipulation
        local ini_helper=""
        if [ -f "$INSTALL_SCRIPT_DIR/scripts/ini-helper.sh" ]; then
            ini_helper="$INSTALL_SCRIPT_DIR/scripts/ini-helper.sh"
        elif [ -f "/opt/rayanpbx/scripts/ini-helper.sh" ]; then
            ini_helper="/opt/rayanpbx/scripts/ini-helper.sh"
        fi
        
        if [ -n "$ini_helper" ] && [ -f "$ini_helper" ]; then
            source "$ini_helper"
            
            # Backup current config
            local backup=$(backup_config "$manager_conf")
            print_verbose "Created backup: $backup"
            
            # Apply all required settings
            ensure_ini_section "$manager_conf" "general"
            set_ini_value "$manager_conf" "general" "enabled" "yes"
            set_ini_value "$manager_conf" "general" "port" "$ami_port"
            set_ini_value "$manager_conf" "general" "bindaddr" "$ami_host"
            
            ensure_ini_section "$manager_conf" "$ami_username"
            set_ini_value "$manager_conf" "$ami_username" "secret" "$ami_secret"
            set_ini_value "$manager_conf" "$ami_username" "deny" "0.0.0.0/0.0.0.0"
            set_ini_value "$manager_conf" "$ami_username" "permit" "127.0.0.1/255.255.255.255"
            set_ini_value "$manager_conf" "$ami_username" "read" "all"
            set_ini_value "$manager_conf" "$ami_username" "write" "all"
            
            # Set proper ownership and permissions
            chown asterisk:asterisk "$manager_conf" 2>/dev/null || true
            chmod 640 "$manager_conf" 2>/dev/null || true
            
            print_success "manager.conf updated successfully"
            fixes_applied=true
            
            # Reload Asterisk manager
            if systemctl is-active --quiet asterisk 2>/dev/null; then
                print_info "Reloading Asterisk manager..."
                if asterisk -rx "manager reload" > /dev/null 2>&1; then
                    print_success "Asterisk manager reloaded"
                    sleep 2
                else
                    print_warning "Could not reload Asterisk manager"
                    print_info "Try: systemctl restart asterisk"
                fi
            fi
            
            return 1  # Fixes applied
        else
            print_warning "ini-helper.sh not found - cannot safely modify manager.conf"
            print_info "Please manually edit $manager_conf or run: rayanpbx-cli diag reapply-ami"
            return 2  # Cannot fix
        fi
    else
        print_info "Auto-fix is disabled. Run with auto_fix=true or use: rayanpbx-cli diag reapply-ami"
        return 2  # Cannot fix
    fi
}

fix_radiusclient_config() {
    local config_file="$1"
    
    # Validate input path
    if [ -z "$config_file" ]; then
        print_error "Invalid config file path (empty)"
        return 1
    fi
    
    # Sanitize path - remove any command substitution attempts and special characters
    config_file="${config_file//\$()/}"
    config_file="${config_file//\`/}"
    config_file="${config_file//;/}"
    config_file="${config_file//|/}"
    config_file="${config_file//&/}"
    
    # Validate that path looks like a real config file path
    if [[ ! "$config_file" =~ ^/[a-zA-Z0-9/_.-]+\.conf$ ]]; then
        print_error "Invalid config file path format: $config_file"
        return 1
    fi
    
    print_progress "Attempting to fix radiusclient configuration..."
    
    # Validate PKG_MGR is set and safe
    if [ -z "$PKG_MGR" ]; then
        print_error "Package manager not configured"
        return 1
    fi
    
    # Ensure PKG_MGR is one of the expected values
    if [[ "$PKG_MGR" != "nala" && "$PKG_MGR" != "apt-get" ]]; then
        print_error "Invalid package manager: $PKG_MGR"
        return 1
    fi
    
    # Check if radiusclient-ng package is installed
    if ! dpkg -l | grep -q radiusclient-ng; then
        print_info "Installing radiusclient-ng package..."
        if [ "$VERBOSE" = true ]; then
            if "$PKG_MGR" install -y radiusclient-ng; then
                print_success "radiusclient-ng package installed"
            else
                print_warning "Failed to install radiusclient-ng package"
                return 1
            fi
        else
            if "$PKG_MGR" install -y radiusclient-ng > /dev/null 2>&1; then
                print_success "radiusclient-ng package installed"
            else
                print_warning "Failed to install radiusclient-ng package"
                return 1
            fi
        fi
    fi
    
    # Check if the config file exists now (after package installation)
    if [ -f "$config_file" ]; then
        print_success "Configuration file now exists: $config_file"
        return 0
    fi
    
    # Create directory if it doesn't exist
    local config_dir=$(dirname -- "$config_file")
    if [ ! -d "$config_dir" ]; then
        print_info "Creating directory: $config_dir"
        mkdir -p -- "$config_dir"
    fi
    
    # Create a minimal configuration file
    print_info "Creating minimal radiusclient configuration..."
    cat > "$config_file" << 'EOF'
# Minimal radiusclient-ng configuration for Asterisk
# This file was auto-generated by RayanPBX installer

# RADIUS server configuration
auth_order      radius
login_tries     4
login_timeout   60
login_local     /etc/radiusclient-ng/login.radiusclient

# Default RADIUS servers (modify as needed)
authserver      localhost:1812
acctserver      localhost:1813

# RADIUS dictionary
dictionary      /etc/radiusclient-ng/dictionary

# Default values for RADIUS attributes
default_realm
radius_timeout  10
radius_retries  3

# Bind address (optional)
bindaddr        *

# Issue challenges
issue           /etc/radiusclient-ng/issue

EOF
    
    if [ -f "$config_file" ]; then
        print_success "Created radiusclient configuration file"
        print_warning "Note: This is a minimal configuration. You may need to configure RADIUS servers."
    else
        print_error "Failed to create configuration file"
        return 1
    fi
    
    # Update Asterisk CDR and CEL configuration files to use correct radiusclient path
    print_verbose "Updating Asterisk CDR and CEL configuration files..."
    
    # Check which radiusclient config path exists
    local actual_radcli_path=""
    if [ -f "/etc/radcli/radiusclient.conf" ]; then
        actual_radcli_path="/etc/radcli/radiusclient.conf"
    elif [ -f "/etc/radiusclient-ng/radiusclient.conf" ]; then
        actual_radcli_path="/etc/radiusclient-ng/radiusclient.conf"
    else
        actual_radcli_path="$config_file"
    fi
    
    print_verbose "Using radiusclient config path: $actual_radcli_path"
    
    # Update cdr.conf if it exists
    if [ -f "/etc/asterisk/cdr.conf" ]; then
        print_verbose "Updating /etc/asterisk/cdr.conf..."
        # Enable radius section
        sed -i 's/;\[radius\]/[radius]/g' /etc/asterisk/cdr.conf 2>/dev/null || true
        # Update radiuscfg path
        sed -i "s|;radiuscfg => /usr/local/etc/radiusclient-ng/radiusclient.conf|radiuscfg => ${actual_radcli_path}|g" /etc/asterisk/cdr.conf 2>/dev/null || true
        sed -i "s|radiuscfg => /usr/local/etc/radiusclient-ng/radiusclient.conf|radiuscfg => ${actual_radcli_path}|g" /etc/asterisk/cdr.conf 2>/dev/null || true
        print_verbose "Updated cdr.conf"
    fi
    
    # Update cel.conf if it exists
    if [ -f "/etc/asterisk/cel.conf" ]; then
        print_verbose "Updating /etc/asterisk/cel.conf..."
        # Update radiuscfg path
        sed -i "s|;radiuscfg => /usr/local/etc/radiusclient-ng/radiusclient.conf|radiuscfg => ${actual_radcli_path}|g" /etc/asterisk/cel.conf 2>/dev/null || true
        sed -i "s|radiuscfg => /usr/local/etc/radiusclient-ng/radiusclient.conf|radiuscfg => ${actual_radcli_path}|g" /etc/asterisk/cel.conf 2>/dev/null || true
        print_verbose "Updated cel.conf"
    fi
    
    print_success "Asterisk RADIUS configuration updated"
    return 0
}

check_asterisk_status() {
    local context="${1:-Asterisk operation}"
    local fix_errors="${2:-false}"
    
    print_verbose "Checking Asterisk service status..."
    
    # Capture systemctl status output
    local status_output=$(systemctl status asterisk 2>&1)
    local service_active=$(systemctl is-active --quiet asterisk && echo "yes" || echo "no")
    
    # Capture journalctl logs for detailed errors
    local journal_errors=$(journalctl -u asterisk -n 50 --no-pager 2>/dev/null | grep -i "error\|fail\|warning" | tail -10)
    
    # Check if service is active
    if [ "$service_active" = "yes" ]; then
        print_verbose "Asterisk service is active"
        
        # Even if active, check for errors in logs
        if [ -n "$journal_errors" ]; then
            print_warning "Asterisk is running but has recent errors/warnings in logs"
            print_verbose "Recent errors/warnings:"
            echo -e "${DIM}${journal_errors}${RESET}"
            echo ""
        fi
        return 0
    fi
    
    # Service is not active - gather error details
    print_verbose "Asterisk service is not active, gathering error details..."
    
    # Check for specific known errors
    local radiusclient_error=""
    
    # Check for radiusclient configuration error in systemctl status
    if echo "$status_output" | grep -q "radiusclient"; then
        radiusclient_error=$(echo "$status_output" | grep "radiusclient" | head -1)
        print_warning "Detected radiusclient configuration issue"
        print_verbose "Error: $radiusclient_error"
    fi
    
    # Also check journal logs (append if already found in status)
    if echo "$journal_errors" | grep -q "radiusclient"; then
        local journal_radiusclient_error=$(echo "$journal_errors" | grep "radiusclient" | head -1)
        if [ -z "$radiusclient_error" ]; then
            # No error found in status, use journal error
            radiusclient_error="$journal_radiusclient_error"
        fi
        print_warning "Detected radiusclient configuration issue in logs"
        print_verbose "Error: $journal_radiusclient_error"
    fi
    
    # Try to fix radiusclient error if found
    if [ -n "$radiusclient_error" ] && [ "$fix_errors" = "true" ]; then
        print_info "Attempting to fix radiusclient error..."
        
        # Extract the missing file path from error message
        # Try with grep -P first (PCRE), fallback to sed if not available
        local config_file=""
        local grep_result=$(echo "$radiusclient_error" | grep -oP "(?<=open )[^:]+(?=:)" 2>/dev/null | head -1)
        if [ -n "$grep_result" ]; then
            config_file="$grep_result"
        else
            # Fallback: use sed for extraction if grep -P is not available
            config_file=$(echo "$radiusclient_error" | sed -n 's/.*open \([^:]*\):.*/\1/p' | head -1)
        fi
        
        if [ -z "$config_file" ]; then
            # Default path if extraction failed
            config_file="/etc/radiusclient-ng/radiusclient.conf"
        fi
        
        print_verbose "Missing configuration file: $config_file"
        
        if fix_radiusclient_config "$config_file"; then
            print_success "Radiusclient configuration fixed"
            print_info "Restarting Asterisk to apply fix..."
            
            if systemctl restart asterisk > /dev/null 2>&1; then
                sleep 2
                if systemctl is-active --quiet asterisk; then
                    print_success "Asterisk service restarted successfully after fix"
                    return 0
                else
                    print_warning "Asterisk failed to start after fix"
                fi
            else
                print_warning "Failed to restart Asterisk after fix"
            fi
        else
            print_warning "Could not automatically fix radiusclient configuration"
        fi
    fi
    
    # If we couldn't fix the error or there are other errors, report them
    if [ "$service_active" = "no" ]; then
        print_error "Asterisk service is not running"
        
        # Collect all error information
        local full_error_msg=""
        
        if [ -n "$radiusclient_error" ]; then
            full_error_msg="$radiusclient_error"
        fi
        
        if [ -n "$journal_errors" ]; then
            if [ -n "$full_error_msg" ]; then
                full_error_msg="$full_error_msg\n\nAdditional errors:\n$journal_errors"
            else
                full_error_msg="$journal_errors"
            fi
        fi
        
        if [ -z "$full_error_msg" ]; then
            full_error_msg="Service failed to start (no specific error details available)"
        fi
        
        # Write error to persistent log file for Web UI and TUI to read
        local error_log_file="/var/log/rayanpbx/asterisk-errors.log"
        mkdir -p /var/log/rayanpbx 2>/dev/null || true
        
        {
            echo "==================================="
            echo "Asterisk Error - $(date '+%Y-%m-%d %H:%M:%S')"
            echo "Context: $context"
            echo "==================================="
            echo -e "$full_error_msg"
            echo ""
        } >> "$error_log_file"
        
        print_verbose "Error logged to: $error_log_file"
        
        # Display error and get AI solution
        handle_asterisk_error "$full_error_msg" "$context"
        
        return 1
    fi
    
    return 0
}

# Configure PJSIP transport to ensure Asterisk listens on port 5060
configure_pjsip_transport() {
    local pjsip_conf="/etc/asterisk/pjsip.conf"
    
    print_info "Configuring PJSIP transport for SIP connectivity..."
    
    # Check if pjsip.conf exists
    if [ ! -f "$pjsip_conf" ]; then
        print_warning "pjsip.conf not found, creating with transport configuration..."
        cat > "$pjsip_conf" << 'EOF'
; RayanPBX PJSIP Configuration
; Generated by RayanPBX Installation Script

[global]
type=global
max_forwards=70
keep_alive_interval=90

; BEGIN MANAGED - RayanPBX Transports
; Generated by RayanPBX - SIP Transports Configuration

[transport-udp]
type=transport
protocol=udp
bind=0.0.0.0:5060
allow_reload=yes

[transport-tcp]
type=transport
protocol=tcp
bind=0.0.0.0:5060
allow_reload=yes
; END MANAGED - RayanPBX Transports

EOF
        chown asterisk:asterisk "$pjsip_conf" 2>/dev/null || true
        chmod 640 "$pjsip_conf" 2>/dev/null || true
        print_success "Created pjsip.conf with transport configuration"
        return 0
    fi
    
    # Check if both UDP and TCP transport configurations already exist
    # Use grep with context to check for proper configuration within transport sections
    local has_udp_transport="no"
    local has_tcp_transport="no"
    
    # Check for UDP transport section with proper configuration
    if grep -A5 '^\[transport-udp\]' "$pjsip_conf" 2>/dev/null | grep -q 'protocol=udp' && \
       grep -A5 '^\[transport-udp\]' "$pjsip_conf" 2>/dev/null | grep -q 'bind=0.0.0.0:5060'; then
        has_udp_transport="yes"
    fi
    
    # Check for TCP transport section with proper configuration
    if grep -A5 '^\[transport-tcp\]' "$pjsip_conf" 2>/dev/null | grep -q 'protocol=tcp' && \
       grep -A5 '^\[transport-tcp\]' "$pjsip_conf" 2>/dev/null | grep -q 'bind=0.0.0.0:5060'; then
        has_tcp_transport="yes"
    fi
    
    if [ "$has_udp_transport" = "yes" ] && [ "$has_tcp_transport" = "yes" ]; then
        print_success "PJSIP transport configuration already complete (UDP and TCP on port 5060)"
        return 0
    fi
    
    # Transport configuration is incomplete, need to add/update
    print_progress "Adding/updating PJSIP transport configuration..."
    
    # Backup original file before modification
    cp "$pjsip_conf" "${pjsip_conf}.bak" 2>/dev/null || true
    
    # Remove any existing RayanPBX transport sections
    sed -i '/; BEGIN MANAGED - RayanPBX Transport/,/; END MANAGED - RayanPBX Transport/d' "$pjsip_conf" 2>/dev/null || true
    sed -i '/; BEGIN MANAGED - RayanPBX Transports/,/; END MANAGED - RayanPBX Transports/d' "$pjsip_conf" 2>/dev/null || true
    
    # Create temp file with transport config
    local temp_file=$(mktemp)
    cat > "$temp_file" << 'EOF'
; BEGIN MANAGED - RayanPBX Transports
; Generated by RayanPBX - SIP Transports Configuration

[transport-udp]
type=transport
protocol=udp
bind=0.0.0.0:5060
allow_reload=yes

[transport-tcp]
type=transport
protocol=tcp
bind=0.0.0.0:5060
allow_reload=yes
; END MANAGED - RayanPBX Transports

EOF
    
    # Prepend transport config to existing file with error handling
    if cat "$pjsip_conf" >> "$temp_file" 2>/dev/null; then
        if mv "$temp_file" "$pjsip_conf" 2>/dev/null; then
            chown asterisk:asterisk "$pjsip_conf" 2>/dev/null || true
            chmod 640 "$pjsip_conf" 2>/dev/null || true
            print_success "PJSIP transport configuration added (UDP and TCP on port 5060)"
            # Remove backup on success
            rm -f "${pjsip_conf}.bak" 2>/dev/null || true
        else
            print_error "Failed to update pjsip.conf, restoring backup..."
            mv "${pjsip_conf}.bak" "$pjsip_conf" 2>/dev/null || true
            rm -f "$temp_file" 2>/dev/null || true
            return 1
        fi
    else
        print_error "Failed to read pjsip.conf, restoring backup..."
        mv "${pjsip_conf}.bak" "$pjsip_conf" 2>/dev/null || true
        rm -f "$temp_file" 2>/dev/null || true
        return 1
    fi
    
    return 0
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

# Ensure PKG_MGR is set (helper for steps that may run independently)
# This function is used by steps that need package manager access
# when they may be run standalone via --steps flag
ensure_pkg_mgr() {
    if [ -z "$PKG_MGR" ]; then
        PKG_MGR="nala"
        if ! command -v nala &> /dev/null; then
            PKG_MGR="apt-get"
        fi
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
# Health Check Functions - Source from health-check.sh script
# ════════════════════════════════════════════════════════════════════════

# Determine script directory early for sourcing health-check.sh
INSTALL_SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Source the health check script for DRY code
HEALTH_CHECK_SCRIPT="$INSTALL_SCRIPT_DIR/scripts/health-check.sh"
if [ -f "$HEALTH_CHECK_SCRIPT" ]; then
    # Source the health check script to get all health check functions
    source "$HEALTH_CHECK_SCRIPT"
    print_verbose "Loaded health check functions from $HEALTH_CHECK_SCRIPT"
else
    # Fallback: define minimal functions if health-check.sh is not available yet
    print_verbose "Health check script not found, using fallback functions"
    
    sanitize_output() {
        local text="$1"
        local max_length="${2:-200}"
        echo "$text" | head -c "$max_length" | tr -d '\000-\037' | sed -E 's/(password|token|secret|key|api[_-]?key|access[_-]?token|auth[_-]?(token|key)|client[_-]?secret|private[_-]?key|[A-Z_]+PASSWORD)[[:space:]]*[:=][[:space:]]*[^[:space:]&]*/\1=***REDACTED***/gi'
    }

    is_port_listening() {
        local port=$1
        if ss -tuln 2>/dev/null | grep -qE ":${port}([[:space:]]|$)" || netstat -tuln 2>/dev/null | grep -qE ":${port}([[:space:]]|$)"; then
            return 0
        fi
        return 1
    }

    check_port_listening() {
        local port=$1
        local service_name=$2
        local max_attempts=${3:-30}
        local attempt=0
        
        print_verbose "Checking if port $port is listening (max ${max_attempts}s)..."
        
        while [ $attempt -lt $max_attempts ]; do
            if is_port_listening "$port"; then
                print_verbose "Port $port is now listening"
                return 0
            fi
            attempt=$((attempt + 1))
            sleep 1
        done
        
        print_error "Port $port not listening after ${max_attempts}s for $service_name"
        return 1
    }

    check_websocket_health() {
        local host=$1
        local port=$2
        local service_name=$3
        local max_attempts=${4:-15}
        local attempt=0
        
        print_verbose "Checking WebSocket at $host:$port (max ${max_attempts} attempts)..."
        
        while [ $attempt -lt $max_attempts ]; do
            if is_port_listening "$port"; then
                print_verbose "WebSocket port $port is listening"
                return 0
            fi
            
            attempt=$((attempt + 1))
            sleep 2
        done
        
        print_error "$service_name not responding on port $port"
        return 1
    }

    test_service_health() {
        local service_type=$1
        local service_name=$2
        
        case $service_type in
            "api")
                print_info "Testing Backend API health..."
                if ! check_port_listening 8000 "$service_name" 30; then
                    return 1
                fi
                
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
                        print_warning "$service_name returned HTTP 500, attempting to get error details..."
                        local error_details=$(sanitize_output "$(cat "$temp_file")" 200)
                        if [ -n "$error_details" ]; then
                            print_verbose "Error response preview (sanitized): ${error_details}..."
                        fi
                    fi
                    
                    attempt=$((attempt + 1))
                    sleep 2
                done
                
                print_error "Backend API is not responding correctly"
                print_info "Checking for error details..."
                local api_response=$(sanitize_output "$(curl -s $url 2>&1)" 200)
                print_verbose "API response (sanitized): ${api_response}..."
                print_info "Check backend logs:"
                print_cmd "journalctl -u rayanpbx-api -n 50 --no-pager"
                print_cmd "tail -f /opt/rayanpbx/backend/storage/logs/laravel.log"
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
                print_cmd "su - www-data -s /bin/bash -c 'pm2 logs rayanpbx-web --nostream'"
                return 1
                ;;
                
            "websocket")
                print_info "Testing WebSocket server health..."
                if ! check_websocket_health "localhost" 9000 "$service_name" 15; then
                    print_error "WebSocket server is not responding"
                    print_info "Check PM2 logs:"
                    print_cmd "su - www-data -s /bin/bash -c 'pm2 logs rayanpbx-ws --nostream'"
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
fi

# ════════════════════════════════════════════════════════════════════════
# Parse Command Line Arguments
# ════════════════════════════════════════════════════════════════════════

# Save original arguments before parsing for use in script restart
ORIGINAL_ARGS=("$@")

while [[ $# -gt 0 ]]; do
    case $1 in
        -h|--help)
            show_help
            ;;
        -u|--upgrade)
            UPGRADE_MODE=true
            shift
            ;;
        -b|--backup)
            CREATE_BACKUP=true
            shift
            ;;
        -v|--verbose)
            VERBOSE=true
            shift
            ;;
        -V|--version)
            show_version
            ;;
        --list-steps)
            list_steps
            exit 0
            ;;
        --ci)
            CI_MODE=true
            shift
            ;;
        --steps=*)
            ONLY_STEPS="${1#*=}"
            shift
            ;;
        --skip=*)
            SKIP_STEPS="${1#*=}"
            shift
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

if [ "$UPGRADE_MODE" = true ]; then
    print_verbose "Upgrade mode enabled - will automatically apply updates without prompting"
fi

# Initialize step filtering based on command-line arguments
initialize_steps

# ════════════════════════════════════════════════════════════════════════
# Main Installation
# ════════════════════════════════════════════════════════════════════════

print_verbose "Starting RayanPBX installation script v${SCRIPT_VERSION}"
print_verbose "System: $(uname -a)"
print_verbose "User: $(whoami)"

print_banner

# Check if running as root
print_verbose "Checking if running as root (EUID: $EUID)..."
if [[ $EUID -ne 0 ]] && [[ "$CI_MODE" != true ]]; then
   print_error "This script must be run as root"
   echo -e "${YELLOW}💡 Please run: ${WHITE}sudo $0${RESET}"
   exit 1
fi
if [[ "$CI_MODE" == true ]]; then
    print_verbose "CI mode enabled, skipping root check"
else
    print_verbose "Root check passed"
fi

# Check for git updates
if next_step "Checking for Updates" "updates"; then
    SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
    print_verbose "Script directory: $SCRIPT_DIR"

    if [ -d "$SCRIPT_DIR/.git" ]; then
        print_verbose "Git repository detected, checking for updates..."
        cd "$SCRIPT_DIR"
        
        # Get current branch name
        CURRENT_BRANCH=$(git rev-parse --abbrev-ref HEAD 2>/dev/null)
        print_verbose "Current branch: $CURRENT_BRANCH"
        
        # Check for local changes
        print_verbose "Checking for local changes..."
        if git diff-index --quiet HEAD -- 2>/dev/null; then
            print_verbose "No local changes detected"
        else
            print_warning "Local changes detected in repository"
            print_info "Local changes will be preserved during update"
        fi
        
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
                
                # Check if upgrade mode is enabled
                if [ "$UPGRADE_MODE" = true ]; then
                    print_info "Upgrade mode enabled - automatically applying updates..."
                    REPLY="y"
                else
                    read -p "$(echo -e ${CYAN}Pull updates and restart installation? \(y/n\) ${RESET})" -n 1 -r
                    echo
                fi
                
                if [[ $REPLY =~ ^[Yy]$ ]]; then
                    # Create backup before pulling updates (only if --backup flag is set)
                    if [ "$CREATE_BACKUP" = true ]; then
                        BACKUP_DIR="/tmp/rayanpbx-backup-$(date +%Y%m%d-%H%M%S)"
                        print_progress "Creating backup before update..."
                        print_verbose "Backup directory: $BACKUP_DIR"
                        mkdir -p "$BACKUP_DIR"
                        if [ -f "$SCRIPT_DIR/.env" ]; then
                            cp "$SCRIPT_DIR/.env" "$BACKUP_DIR/" 2>/dev/null || true
                            print_verbose "Backed up .env file"
                        fi
                        if [ -d "$SCRIPT_DIR/backend/storage" ]; then
                            cp -r "$SCRIPT_DIR/backend/storage" "$BACKUP_DIR/" 2>/dev/null || true
                            print_verbose "Backed up backend storage"
                        fi
                        print_success "Backup created: $BACKUP_DIR"
                    fi
                    
                    # Stash local changes if any
                    if ! git diff-index --quiet HEAD -- 2>/dev/null; then
                        print_info "Stashing local changes before update..."
                        if git stash push -m "Auto-stash before update $(date)" 2>/dev/null; then
                            print_verbose "Local changes stashed successfully"
                        else
                            print_warning "Could not stash changes, attempting update anyway"
                        fi
                    fi
                    
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
                        # Use absolute path to ensure script is found after directory changes
                        # Use ORIGINAL_ARGS to preserve flags that were parsed (e.g., --verbose)
                        exec "$SCRIPT_DIR/$(basename "${BASH_SOURCE[0]}")" "${ORIGINAL_ARGS[@]}"
                    else
                        print_error "Failed to pull updates"
                        print_warning "Restoring from backup if needed..."
                        if [ -n "$BACKUP_DIR" ] && [ -d "$BACKUP_DIR" ]; then
                            if [ -f "$BACKUP_DIR/.env" ]; then
                                cp "$BACKUP_DIR/.env" "$SCRIPT_DIR/" 2>/dev/null || true
                                print_verbose "Restored .env from backup"
                            fi
                        fi
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
fi

# Check Ubuntu version
if next_step "System Verification" "system-verification"; then
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
fi

# Install nala if not present
if next_step "Package Manager Setup" "package-manager"; then
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
fi

# System update
if next_step "System Update" "system-update"; then
    print_progress "Updating package lists and upgrading system..."

    ensure_pkg_mgr
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
fi

# Install dependencies
if next_step "Essential Dependencies" "dependencies"; then
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
        net-tools
        lldpd
        nmap
        tcpdump
        jq
        avahi-utils
    )

    print_info "Installing essential packages..."
    print_verbose "Package list: ${PACKAGES[*]}"

    ensure_pkg_mgr

    for package in "${PACKAGES[@]}"; do
        print_verbose "Checking package: $package"
        # Use dpkg-query for more reliable package status checking
        if dpkg-query -W -f='${Status}' "$package" 2>/dev/null | grep -q "install ok installed"; then
            echo -e "${DIM}   ✓ $package (already installed)${RESET}"
            print_verbose "$package is already installed, skipping"
        else
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
        fi
    done

    # Enable and start lldpd service for VoIP phone discovery
    print_verbose "Configuring lldpd service for VoIP phone discovery..."
    if dpkg-query -W -f='${Status}' lldpd 2>/dev/null | grep -q "install ok installed"; then
        if ! systemctl is-enabled lldpd > /dev/null 2>&1; then
            print_verbose "Enabling lldpd service..."
            systemctl enable lldpd > /dev/null 2>&1 || print_warning "Failed to enable lldpd service"
        fi
        if ! systemctl is-active lldpd > /dev/null 2>&1; then
            print_verbose "Starting lldpd service..."
            systemctl start lldpd > /dev/null 2>&1 || print_warning "Failed to start lldpd service"
        fi
        print_success "lldpd service configured for VoIP phone discovery"
    fi

    # Install optional SIP testing tools
    print_verbose "Installing optional SIP testing tools..."
    SIP_TOOLS=(pjsua sipsak sngrep sipp)
    for tool in "${SIP_TOOLS[@]}"; do
        if ! command -v "$tool" &> /dev/null; then
            print_verbose "Installing $tool..."
            if $PKG_MGR install -y "$tool" > /dev/null 2>&1; then
                print_verbose "$tool installed successfully"
            else
                print_verbose "$tool not available in repositories (optional)"
            fi
        else
            print_verbose "$tool already installed"
        fi
    done
fi

# Install GitHub CLI
if next_step "GitHub CLI Installation" "github-cli"; then
    ensure_pkg_mgr

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
fi

# ════════════════════════════════════════════════════════════════════════
# MySQL/MariaDB Helper Functions
# ════════════════════════════════════════════════════════════════════════

# Extract value from .env file, handling quotes and equals signs properly
extract_env_value() {
    local env_file="$1"
    local key="$2"
    local value
    value=$(grep "^${key}=" "$env_file" 2>/dev/null | cut -d'=' -f2-)
    
    # Remove matching quotes (both start and end must be same type)
    if [[ "$value" =~ ^\".*\"$ ]]; then
        # Remove double quotes
        value="${value#\"}"
        value="${value%\"}"
    elif [[ "$value" =~ ^\'.*\'$ ]]; then
        # Remove single quotes
        value="${value#\'}"
        value="${value%\'}"
    fi
    
    echo "$value"
}

check_rayanpbx_user_privileges() {
    local db_user="$1"
    local db_password="$2"
    local db_name="${3:-rayanpbx}"
    
    print_verbose "Testing if user '$db_user' has sufficient privileges..."
    
    # Create temporary config file for secure password passing
    # Set restrictive umask to prevent race condition
    local old_umask=$(umask)
    umask 077
    # Ensure umask is restored even on unexpected exit
    trap "umask $old_umask" RETURN
    local temp_cnf=$(mktemp)
    umask "$old_umask"
    
    cat > "$temp_cnf" <<EOF
[client]
user=$db_user
password=$db_password
EOF
    chmod 600 "$temp_cnf"
    
    # Test if user can connect to database
    if ! mysql --defaults-extra-file="$temp_cnf" -e "SELECT 1;" &> /dev/null; then
        print_verbose "User '$db_user' cannot connect to MySQL"
        rm -f "$temp_cnf"
        return 1
    fi
    
    # Test if database exists and user has access
    if mysql --defaults-extra-file="$temp_cnf" -e "USE $db_name;" &> /dev/null; then
        print_verbose "User '$db_user' has access to database '$db_name'"
        
        # Test if user can create tables (sufficient for migrations)
        # Use unique table name with timestamp and PID to avoid conflicts
        local test_table="_rayanpbx_test_$(date +%s)_$$"
        local create_result=0
        mysql --defaults-extra-file="$temp_cnf" "$db_name" -e "CREATE TABLE IF NOT EXISTS ${test_table} (id INT);" &> /dev/null || create_result=1
        
        if [ $create_result -eq 0 ]; then
            # Table created successfully, now clean it up
            mysql --defaults-extra-file="$temp_cnf" "$db_name" -e "DROP TABLE IF EXISTS ${test_table};" &> /dev/null
            print_verbose "User '$db_user' has sufficient privileges for database operations"
            rm -f "$temp_cnf"
            return 0
        else
            print_verbose "User '$db_user' cannot create tables in database '$db_name'"
            rm -f "$temp_cnf"
            return 1
        fi
    else
        print_verbose "Database '$db_name' does not exist or user lacks access"
        rm -f "$temp_cnf"
        return 1
    fi
}

# MySQL/MariaDB Installation
if next_step "Database Setup (MySQL/MariaDB)" "database"; then
    ensure_pkg_mgr

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
    fi

    # Check if we're in an upgrade scenario with existing credentials
    NEED_ROOT_PASSWORD=true
    USE_EXISTING_CREDENTIALS=false

    if [ -f "/opt/rayanpbx/.env" ]; then
        print_verbose "Found existing .env file, checking for database credentials..."
        
        # Try to extract existing credentials from .env using helper function
        EXISTING_DB_USER=$(extract_env_value "/opt/rayanpbx/.env" "DB_USERNAME")
        EXISTING_DB_PASSWORD=$(extract_env_value "/opt/rayanpbx/.env" "DB_PASSWORD")
        EXISTING_DB_NAME=$(extract_env_value "/opt/rayanpbx/.env" "DB_DATABASE")
        
        if [ -n "$EXISTING_DB_USER" ] && [ -n "$EXISTING_DB_PASSWORD" ]; then
            print_verbose "Found existing credentials for user: $EXISTING_DB_USER"
            print_info "Testing existing database credentials..."
            
            if check_rayanpbx_user_privileges "$EXISTING_DB_USER" "$EXISTING_DB_PASSWORD" "${EXISTING_DB_NAME:-rayanpbx}"; then
                print_success "Existing database user has sufficient privileges"
                NEED_ROOT_PASSWORD=false
                USE_EXISTING_CREDENTIALS=true
                ESCAPED_DB_PASSWORD="$EXISTING_DB_PASSWORD"
                print_verbose "Will use existing database credentials, no root password needed"
            else
                print_warning "Existing database user lacks sufficient privileges"
                print_info "Root password will be needed to fix database permissions"
            fi
        else
            print_verbose "No valid credentials found in existing .env file"
        fi
    fi

    # Only ask for root password if we actually need it
    if [ "$NEED_ROOT_PASSWORD" = true ]; then
        print_info "Root password is required to set up or update database"
        read -sp "$(echo -e ${CYAN}Enter MySQL root password: ${RESET})" MYSQL_ROOT_PASSWORD
        echo
    fi

    # Create or update RayanPBX database
    if [ "$USE_EXISTING_CREDENTIALS" = false ]; then
        print_progress "Setting up RayanPBX database..."
        print_verbose "Generating random database password..."
        ESCAPED_DB_PASSWORD=$(openssl rand -hex 16)
        print_verbose "Database password generated (random hex string)"
        
        print_verbose "Creating database and user..."
        # Use mysql --defaults-extra-file for secure password passing
        # Set restrictive umask to prevent race condition
        old_umask=$(umask)
        umask 077
        MYSQL_TMP_CNF=$(mktemp)
        umask "$old_umask"
        
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
    else
        print_success "Using existing database configuration"
        print_verbose "Database: ${EXISTING_DB_NAME:-rayanpbx}, User: $EXISTING_DB_USER"
    fi
fi

# PHP 8.3 Installation
if next_step "PHP 8.3 Installation" "php"; then
    ensure_pkg_mgr

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

    # Verify and enable Redis extension
    print_verbose "Verifying Redis extension is enabled..."
    if ! php -m | grep -qi "redis"; then
        print_warning "Redis extension not loaded, attempting to enable..."
        
        # Try to enable the extension
        if command -v phpenmod &> /dev/null; then
            print_verbose "Using phpenmod to enable redis extension..."
            if phpenmod redis 2>/dev/null; then
                print_success "Redis extension enabled via phpenmod"
            else
                print_verbose "Could not enable via phpenmod, checking if package is installed..."
            fi
        fi
        
        # Restart PHP-FPM to ensure extension is loaded
        if systemctl is-active --quiet php8.3-fpm; then
            print_verbose "Restarting PHP-FPM to load Redis extension..."
            if systemctl restart php8.3-fpm 2>/dev/null; then
                print_verbose "PHP-FPM restarted successfully"
            else
                print_warning "Failed to restart PHP-FPM, extension may not be loaded"
            fi
        fi
        
        # Verify again
        if php -m | grep -qi "redis"; then
            print_success "Redis extension verified and loaded"
        else
            print_warning "Redis extension may not be properly loaded. Fallback to predis will be used if needed."
        fi
    else
        print_success "Redis extension already loaded"
        print_verbose "Redis extension version: $(php -r "echo phpversion('redis');" 2>/dev/null || echo 'unknown')"
    fi
fi

# Composer Installation
if next_step "Composer Installation" "composer"; then
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
fi

# Node.js 24 Installation
if next_step "Node.js 24 Installation" "nodejs"; then
    ensure_pkg_mgr

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
fi

# Go 1.23 Installation
if next_step "Go 1.23 Installation" "go"; then
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
fi

# Asterisk 22 Installation
if next_step "Asterisk 22 Installation" "asterisk"; then
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
        
        # Configure PJSIP transport to ensure Asterisk listens on port 5060 UDP and TCP
        configure_pjsip_transport
        
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
fi

# Configure Asterisk AMI (using INI helper)
if next_step "Asterisk AMI Configuration" "asterisk-ami"; then
    print_info "Configuring Asterisk Manager Interface..."

    # Source INI helper script
    if [ ! -f "/opt/rayanpbx/scripts/ini-helper.sh" ]; then
        print_warning "INI helper script not found yet, will configure after repo clone"
    else
        source /opt/rayanpbx/scripts/ini-helper.sh
        modify_manager_conf "rayanpbx_ami_secret"
        print_success "AMI configured"
    fi

    # Ensure PJSIP transport configuration exists (port 5060 UDP and TCP)
    configure_pjsip_transport

    systemctl enable asterisk > /dev/null 2>&1

    print_progress "Restarting Asterisk service..."
    if systemctl restart asterisk > /dev/null 2>&1; then
        print_verbose "Asterisk restart command completed"
    else
        print_warning "Asterisk restart command had issues"
    fi

    # Check Asterisk status with comprehensive error checking and auto-fix
    if check_asterisk_status "Asterisk startup" "true"; then
        print_success "Asterisk service is running"
        print_info "Active channels: $(asterisk -rx 'core show channels' 2>/dev/null | grep 'active channel' || echo '0 active channels')"
    else
        print_error "Failed to start Asterisk service"
        echo ""
        
        # Perform automated diagnostics instead of asking user to run commands manually
        echo -e "${CYAN}${BOLD}🔍 Running Automated Asterisk Diagnostics...${RESET}"
        echo ""
        
        # Check 1: Systemctl status
        echo -e "${CYAN}Systemctl Status:${RESET}"
        systemctl status asterisk --no-pager 2>&1 | head -15 || true
        echo ""
        
        # Check 2: Journal errors
        echo -e "${CYAN}Recent Errors from Journal:${RESET}"
        local recent_errors=$(journalctl -u asterisk -n 20 --no-pager 2>/dev/null | grep -i "error\|fail\|warning" | tail -10)
        if [ -n "$recent_errors" ]; then
            echo -e "${DIM}$recent_errors${RESET}"
        else
            echo -e "${DIM}No recent errors found in journal.${RESET}"
        fi
        echo ""
        
        # Build error summary for AI
        local error_summary="Asterisk failed to start. "
        if [ -n "$recent_errors" ]; then
            error_summary="${error_summary}Recent errors: $(echo "$recent_errors" | head -3 | tr '\n' ' ')"
        fi
        
        # Use Pollinations.AI to get solution using the helper function (max 12 lines displayed)
        echo -e "${CYAN}🤖 Consulting AI for solution...${RESET}"
        local ai_solution=$(query_pollinations_ai "$error_summary How to fix?" 12)
        
        if [ -n "$ai_solution" ]; then
            echo ""
            echo -e "${GREEN}${BOLD}💡 AI-Suggested Solution:${RESET}"
            echo -e "${DIM}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${RESET}"
            echo -e "${WHITE}${ai_solution}${RESET}"
            echo -e "${DIM}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${RESET}"
        fi
        echo ""
        
        print_warning "Continuing with installation, but Asterisk may need additional configuration"
    fi
fi

# Clone/Update RayanPBX Repository
if next_step "RayanPBX Source Code" "source"; then
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
        if systemctl reload asterisk > /dev/null 2>&1; then
            print_verbose "Asterisk reload command completed"
        else
            print_verbose "Asterisk reload command had issues, will check status"
        fi
        
        sleep 2
        # Check if it's running and handle any errors
        if ! check_asterisk_status "Asterisk reload after AMI configuration" "true"; then
            print_warning "Asterisk reload encountered an issue"
            
            # Try a full restart if reload failed
            print_info "Attempting a full restart instead of reload..."
            if systemctl restart asterisk > /dev/null 2>&1; then
                sleep 2
                if check_asterisk_status "Asterisk restart after AMI configuration" "true"; then
                    print_success "Asterisk restarted successfully"
                else
                    print_error "Failed to restart Asterisk after AMI configuration"
                    print_info "You may need to configure Asterisk manually"
                fi
            else
                print_error "Failed to restart Asterisk"
                print_cmd "systemctl status asterisk  # Check Asterisk status"
            fi
        else
            print_success "Asterisk configuration reloaded successfully"
        fi
    fi
fi

# Setup unified .env file
if next_step "Environment Configuration" "env-config"; then
    cd /opt/rayanpbx
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

    # Enable debug mode for better error visibility during installation
    # This is intentionally set regardless of user preferences to ensure proper error reporting
    # during installation. Users can switch to production mode afterward (see final instructions).
    sed -i "s|APP_DEBUG=.*|APP_DEBUG=true|" .env
    sed -i "s|APP_ENV=.*|APP_ENV=development|" .env
    print_verbose "Debug mode enabled for installation (can be changed to production after setup)"

    print_success "Environment configured"

    # Copy .env to backend directory for Laravel
    print_progress "Setting up backend environment..."
    cp .env backend/.env
    print_verbose "Backend .env synchronized with root .env"
    print_success "Backend environment configured"
fi

# Backend Setup
if next_step "Backend API Setup" "backend"; then
    print_progress "Installing backend dependencies..."
    cd /opt/rayanpbx/backend
    composer install --no-dev --optimize-autoloader 2>&1 | grep -E "(Installing|Generating)" || true

    print_progress "Running database migrations..."
    php artisan migrate --force

    if [ $? -eq 0 ]; then
        print_success "Database migrations completed"
        
        # Clear Laravel caches after migrations
        print_progress "Clearing application caches..."
        print_verbose "Clearing cache, config, and route caches..."
        php artisan cache:clear 2>/dev/null || true
        php artisan config:clear 2>/dev/null || true
        php artisan route:clear 2>/dev/null || true
        print_success "Caches cleared"
    else
        print_error "Database migration failed"
        exit 1
    fi

    # Check and fix database collation
    print_progress "Checking database collation..."
    # Use && ... || ... pattern to capture exit code without triggering set -e
    COLLATION_CHECK_OUTPUT=$(php artisan db:check-collation 2>&1) && COLLATION_EXIT_CODE=0 || COLLATION_EXIT_CODE=$?
    if [ $COLLATION_EXIT_CODE -eq 0 ]; then
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

    # Set proper ownership and permissions for Laravel
    print_progress "Setting proper ownership and permissions..."
    print_verbose "Setting ownership of /opt/rayanpbx/backend to www-data:www-data..."
    chown -R www-data:www-data /opt/rayanpbx/backend

    print_verbose "Setting permissions for Laravel storage and cache directories..."
    # Storage directory needs to be writable by web server
    if [ -d /opt/rayanpbx/backend/storage ]; then
        # Set directories to 775
        find /opt/rayanpbx/backend/storage -type d -exec chmod 775 {} \;
        # Set regular files to 664 (readable/writable by owner and group)
        find /opt/rayanpbx/backend/storage -type f -exec chmod 664 {} \;
        # .gitignore files should be 644 (not executable, readable by all)
        find /opt/rayanpbx/backend/storage -type f -name ".gitignore" -exec chmod 644 {} \;
        print_verbose "Set permissions on storage directory"
    fi

    if [ -d /opt/rayanpbx/backend/bootstrap/cache ]; then
        # Set directories to 775
        find /opt/rayanpbx/backend/bootstrap/cache -type d -exec chmod 775 {} \;
        # Set regular files to 664 (readable/writable by owner and group)
        find /opt/rayanpbx/backend/bootstrap/cache -type f -exec chmod 664 {} \;
        # .gitignore files should be 644 (not executable, readable by all)
        find /opt/rayanpbx/backend/bootstrap/cache -type f -name ".gitignore" -exec chmod 644 {} \;
        print_verbose "Set permissions on bootstrap/cache directory"
    fi

    # Ensure www-data can write to log files
    if [ -f /opt/rayanpbx/backend/storage/logs/laravel.log ]; then
        chmod 664 /opt/rayanpbx/backend/storage/logs/laravel.log
        print_verbose "Set permissions on laravel.log"
    fi

    print_success "Ownership and permissions configured"
    print_success "Backend configured successfully"
fi

# Frontend Setup
if next_step "Frontend Web UI Setup" "frontend"; then
    print_progress "Installing frontend dependencies..."
    cd /opt/rayanpbx/frontend
    npm install 2>&1 | grep -E "(added|up to date)" | tail -1

    # Create frontend .env file with proper API configuration
    print_progress "Configuring frontend environment..."
    SERVER_IP=$(hostname -I | awk '{print $1}')
    cat > .env << EOF
NUXT_PUBLIC_API_BASE=http://${SERVER_IP}:8000/api
NUXT_PUBLIC_WS_URL=ws://${SERVER_IP}:9000/ws
EOF
    print_verbose "Frontend .env configured with API_BASE=http://${SERVER_IP}:8000/api"
    print_success "Frontend environment configured"

    print_progress "Building frontend..."
    npm run build 2>&1 | tee /tmp/frontend-build.log | tail -10
    if [ ${PIPESTATUS[0]} -ne 0 ]; then
        print_error "Frontend build failed"
        echo -e "${YELLOW}Check /tmp/frontend-build.log for details${RESET}"
        exit 1
    fi
    print_success "Frontend built successfully"
fi

# TUI Setup
if next_step "TUI (Terminal UI) Build" "tui"; then
    print_progress "Building TUI application..."
    cd /opt/rayanpbx/tui

    # Use local toolchain without modifying go.mod
    # go.mod is set to minimum supported version (1.22)
    export GOTOOLCHAIN=local
    print_verbose "Set GOTOOLCHAIN=local to use installed Go toolchain"

    INSTALLED_GO_VERSION=$(go version | grep -oP 'go\K[0-9]+\.[0-9]+' || echo "")
    if [ -n "$INSTALLED_GO_VERSION" ]; then
        print_verbose "Detected Go version: $INSTALLED_GO_VERSION"
        print_verbose "Building with installed Go toolchain (go.mod requires 1.22+)"
    fi

    go mod download
    go build -o /usr/local/bin/rayanpbx-tui .
    chmod +x /usr/local/bin/rayanpbx-tui

    print_success "TUI built: /usr/local/bin/rayanpbx-tui"

    # WebSocket Server Setup
    print_progress "Building WebSocket server..."
    go mod download
    go build -o /usr/local/bin/rayanpbx-ws websocket.go config.go
    chmod +x /usr/local/bin/rayanpbx-ws

    print_success "WebSocket server built: /usr/local/bin/rayanpbx-ws"

    # CLI Tool Setup
    print_progress "Setting up CLI tool..."
    if [ -f "/opt/rayanpbx/scripts/rayanpbx-cli.sh" ]; then
        ln -sf /opt/rayanpbx/scripts/rayanpbx-cli.sh /usr/local/bin/rayanpbx-cli
        chmod +x /opt/rayanpbx/scripts/rayanpbx-cli.sh
        chmod +x /usr/local/bin/rayanpbx-cli
        print_success "CLI tool linked: rayanpbx-cli"
    else
        print_warning "CLI tool script not found at /opt/rayanpbx/scripts/rayanpbx-cli.sh"
    fi
fi

# PM2 Ecosystem Configuration
if next_step "PM2 Process Management Setup" "pm2"; then
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
fi

# Systemd Services
if next_step "Systemd Services Configuration" "systemd"; then
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
    # Service restart logic handles both fresh installs and updates
    # During fresh install, services won't be running yet
    # During updates, this will restart existing services
    print_progress "Starting services..."
    systemctl enable rayanpbx-api > /dev/null 2>&1

    # Check if service is already running (update scenario)
    if systemctl is-active --quiet rayanpbx-api 2>/dev/null; then
        print_verbose "rayanpbx-api is already running, restarting..."
        systemctl restart rayanpbx-api
    else
        print_verbose "rayanpbx-api not running, starting fresh..."
        systemctl start rayanpbx-api
    fi

    # Start PM2 services
    cd /opt/rayanpbx
    # Stop existing PM2 services if running (update scenario)
    su - www-data -s /bin/bash -c "pm2 delete rayanpbx-web rayanpbx-ws 2>/dev/null || true"
    # Start PM2 services
    su - www-data -s /bin/bash -c "cd /opt/rayanpbx && pm2 start ecosystem.config.js"
    su - www-data -s /bin/bash -c "pm2 save"
fi

# Setup Cron Jobs
if next_step "Cron Jobs Setup" "cron"; then
    print_info "Configuring cron jobs..."

    # Laravel scheduler
    (crontab -l 2>/dev/null || true; echo "* * * * * cd /opt/rayanpbx/backend && php artisan schedule:run >> /dev/null 2>&1") | crontab -

    print_success "Cron jobs configured"
fi

# Verify services with comprehensive health checks
if next_step "Service Verification & Health Checks" "health-check"; then
    print_info "Performing comprehensive health checks on all services..."
    echo ""

    # Track which services failed
    FAILED_SERVICES=()

    # Check Backend API
    print_progress "Checking Backend API (port 8000)..."
    if systemctl is-active --quiet rayanpbx-api; then
        if test_service_health "api" "rayanpbx-api"; then
            print_success "✓ Backend API is fully operational"
        else
            print_warning "✗ Backend API service is running but not healthy"
            FAILED_SERVICES+=("Backend API")
        fi
    else
        print_error "✗ Backend API service failed to start"
        print_info "Check status: systemctl status rayanpbx-api"
        FAILED_SERVICES+=("Backend API")
    fi
    echo ""

    # Check Frontend
    print_progress "Checking Frontend (port 3000)..."
    if su - www-data -s /bin/bash -c "pm2 list" | grep -q "rayanpbx-web.*online"; then
        if test_service_health "frontend" "rayanpbx-web"; then
            print_success "✓ Frontend is fully operational"
        else
            print_warning "✗ Frontend service is running but not healthy"
            FAILED_SERVICES+=("Frontend")
        fi
    else
        print_error "✗ Frontend service failed to start"
        print_info "Check status: su - www-data -s /bin/bash -c 'pm2 list'"
        FAILED_SERVICES+=("Frontend")
    fi
    echo ""

    # Check WebSocket Server
    print_progress "Checking WebSocket Server (port 9000)..."
    if su - www-data -s /bin/bash -c "pm2 list" | grep -q "rayanpbx-ws.*online"; then
        if test_service_health "websocket" "rayanpbx-ws"; then
            print_success "✓ WebSocket Server is fully operational"
        else
            print_warning "✗ WebSocket service is running but not healthy"
            FAILED_SERVICES+=("WebSocket")
        fi
    else
        print_error "✗ WebSocket service failed to start"
        print_info "Check status: su - www-data -s /bin/bash -c 'pm2 list'"
        FAILED_SERVICES+=("WebSocket")
    fi
    echo ""

    # Check Database (MySQL/MariaDB)
    print_progress "Checking Database (MySQL/MariaDB)..."
    if systemctl is-active --quiet mysql || systemctl is-active --quiet mariadb; then
        print_success "✓ Database service is running"
        
        # Default database credentials (used if .env is not available)
        DEFAULT_DB_HOST="127.0.0.1"
        DEFAULT_DB_USER="rayanpbx"
        DEFAULT_DB_NAME="rayanpbx"
        
        # Get database credentials from .env if available
        DB_HOST="$DEFAULT_DB_HOST"
        DB_USER="$DEFAULT_DB_USER"
        DB_PASS=""
        DB_NAME="$DEFAULT_DB_NAME"
        
        if [ -f "/opt/rayanpbx/.env" ]; then
            DB_HOST=$(grep "^DB_HOST=" /opt/rayanpbx/.env 2>/dev/null | cut -d'=' -f2- | tr -d '"' | tr -d "'" || echo "$DEFAULT_DB_HOST")
            DB_USER=$(grep "^DB_USERNAME=" /opt/rayanpbx/.env 2>/dev/null | cut -d'=' -f2- | tr -d '"' | tr -d "'" || echo "$DEFAULT_DB_USER")
            DB_PASS=$(grep "^DB_PASSWORD=" /opt/rayanpbx/.env 2>/dev/null | cut -d'=' -f2- | tr -d '"' | tr -d "'")
            DB_NAME=$(grep "^DB_DATABASE=" /opt/rayanpbx/.env 2>/dev/null | cut -d'=' -f2- | tr -d '"' | tr -d "'" || echo "$DEFAULT_DB_NAME")
        fi
        
        # Test database connectivity using a temporary config file to avoid password exposure in process list
        if [ -n "$DB_PASS" ]; then
            # Create temporary config file with restrictive permissions from the start using umask
            old_umask=$(umask)
            umask 077
            DB_TMP_CNF=$(mktemp) || { umask "$old_umask"; print_warning "  (Could not create temp file for secure connection)"; }
            umask "$old_umask"
            
            if [ -n "$DB_TMP_CNF" ] && [ -f "$DB_TMP_CNF" ]; then
                # Set trap to ensure cleanup even on unexpected exit
                trap "rm -f '$DB_TMP_CNF'" RETURN
                
                cat > "$DB_TMP_CNF" <<EOF
[client]
host=$DB_HOST
user=$DB_USER
password=$DB_PASS
EOF
                if mysql --defaults-extra-file="$DB_TMP_CNF" -e "SELECT 1" "$DB_NAME" &>/dev/null; then
                    print_success "✓ Database connectivity verified"
                else
                    print_warning "✗ Database connectivity issue"
                    echo -e "${YELLOW}  Could not connect to database '$DB_NAME' as user '$DB_USER'${RESET}"
                    FAILED_SERVICES+=("Database")
                fi
                rm -f "$DB_TMP_CNF"
            fi
        else
            print_info "  (Skipping connectivity test - no password available)"
        fi
    else
        print_error "✗ Database service is not running"
        print_info "Check status: systemctl status mysql (or mariadb)"
        FAILED_SERVICES+=("Database")
    fi
    echo ""

    # Check Redis
    print_progress "Checking Redis..."
    if systemctl is-active --quiet redis-server || systemctl is-active --quiet redis; then
        print_success "✓ Redis service is running"
        
        # Test Redis connectivity
        if command -v redis-cli &>/dev/null; then
            if redis-cli ping 2>/dev/null | grep -qi "PONG"; then
                print_success "✓ Redis connectivity verified"
            else
                print_warning "✗ Redis is running but not responding to PING"
                FAILED_SERVICES+=("Redis")
            fi
        else
            print_info "  (redis-cli not available for connectivity test)"
        fi
    else
        print_warning "✗ Redis service is not running"
        print_info "Redis is optional but improves performance and enables real-time features"
        # Don't add to FAILED_SERVICES as Redis is optional
    fi
    echo ""

    # Check Asterisk
    print_progress "Checking Asterisk..."
    SIP_LISTENING_ADDRESS=""
    SIP_LISTENING_PORT="5060"
    
    if systemctl is-active --quiet asterisk; then
        print_success "✓ Asterisk is running"
        ASTERISK_VERSION=$(asterisk -V 2>/dev/null | head -n 1)
        echo -e "${DIM}   $ASTERISK_VERSION${RESET}"
        
        # Check PJSIP transport (port 5060)
        print_progress "Checking PJSIP transport (port 5060)..."
        PJSIP_TRANSPORTS=$(asterisk -rx "pjsip show transports" 2>/dev/null || echo "")
        SIP_PORT_LISTENING=false
        
        if echo "$PJSIP_TRANSPORTS" | grep -q "transport-udp\|transport-tcp"; then
            print_success "✓ PJSIP transports configured"
            
            # Check if port 5060 is actually listening (validate connection refused scenario)
            if is_port_listening 5060; then
                SIP_PORT_LISTENING=true
                print_success "✓ Asterisk listening on SIP port 5060"
                
                # Get the actual listening address for display
                if command -v ss &> /dev/null; then
                    PORT_CHECK=$(ss -tunlp 2>/dev/null | grep ":5060" | head -1 || echo "")
                elif command -v netstat &> /dev/null; then
                    PORT_CHECK=$(netstat -tunlp 2>/dev/null | grep ":5060" | head -1 || echo "")
                fi
                
                if [ -n "$PORT_CHECK" ]; then
                    echo -e "${DIM}   $PORT_CHECK${RESET}"
                fi
            else
                print_warning "⚠ Port 5060 is not listening - attempting to fix..."
                
                # Try to reload PJSIP to activate transports
                asterisk -rx "pjsip reload" > /dev/null 2>&1 || true
                sleep 2
                
                # Re-check after reload
                if is_port_listening 5060; then
                    SIP_PORT_LISTENING=true
                    print_success "✓ Asterisk SIP port 5060 now listening after reload"
                else
                    print_error "✗ Asterisk SIP port 5060 is NOT listening"
                    print_warning "SIP clients will get 'connection refused' errors!"
                    echo ""
                    echo -e "${YELLOW}${BOLD}⚠️  WARNING: SIP Port Not Listening!${RESET}"
                    echo -e "${WHITE}Asterisk is running but not accepting SIP connections.${RESET}"
                    echo -e "${WHITE}Check the following:${RESET}"
                    echo -e "  ${DIM}1.${RESET} Verify PJSIP transport config: ${WHITE}cat /etc/asterisk/pjsip.conf | grep -A5 transport${RESET}"
                    echo -e "  ${DIM}2.${RESET} Check for bind errors: ${WHITE}journalctl -u asterisk | grep -i bind${RESET}"
                    echo -e "  ${DIM}3.${RESET} Ensure port is not in use: ${WHITE}ss -tunlp | grep :5060${RESET}"
                    echo ""
                    FAILED_SERVICES+=("Asterisk SIP")
                fi
            fi
        else
            print_warning "⚠ PJSIP transports not found, attempting to configure..."
            configure_pjsip_transport
            asterisk -rx "pjsip reload" > /dev/null 2>&1 || true
            sleep 2
            
            # Check if port is now listening
            if is_port_listening 5060; then
                SIP_PORT_LISTENING=true
                print_success "✓ Asterisk SIP port 5060 now listening after configuration"
            else
                print_error "✗ Asterisk SIP port 5060 still not listening after configuration"
                FAILED_SERVICES+=("Asterisk SIP")
            fi
        fi
        
        # Store the SIP listening address for final display
        if [ "$SIP_PORT_LISTENING" = true ]; then
            SIP_LISTENING_ADDRESS=$(hostname -I | awk '{print $1}')
        fi
    else
        print_error "✗ Asterisk service failed"
        print_info "Check status: systemctl status asterisk"
        FAILED_SERVICES+=("Asterisk")
    fi
    echo ""

    # Check AMI socket (critical for RayanPBX functionality)
    print_progress "Checking Asterisk AMI socket (port 5038)..."
    
    # Get AMI credentials from .env if available
    AMI_HOST="127.0.0.1"
    AMI_PORT="5038"
    AMI_USERNAME="admin"
    AMI_SECRET="rayanpbx_ami_secret"
    
    if [ -f "/opt/rayanpbx/.env" ]; then
        AMI_HOST=$(grep "^ASTERISK_AMI_HOST=" /opt/rayanpbx/.env 2>/dev/null | cut -d'=' -f2- | tr -d '"' | tr -d "'" || echo "127.0.0.1")
        AMI_PORT=$(grep "^ASTERISK_AMI_PORT=" /opt/rayanpbx/.env 2>/dev/null | cut -d'=' -f2- | tr -d '"' | tr -d "'" || echo "5038")
        AMI_USERNAME=$(grep "^ASTERISK_AMI_USERNAME=" /opt/rayanpbx/.env 2>/dev/null | cut -d'=' -f2- | tr -d '"' | tr -d "'" || echo "admin")
        AMI_SECRET=$(grep "^ASTERISK_AMI_SECRET=" /opt/rayanpbx/.env 2>/dev/null | cut -d'=' -f2- | tr -d '"' | tr -d "'" || echo "rayanpbx_ami_secret")
        print_verbose "Using AMI credentials from .env: host=$AMI_HOST, port=$AMI_PORT, user=$AMI_USERNAME"
    fi
    
    # First, verify and fix AMI credentials in manager.conf if needed
    # This ensures the configuration is correct before we try to connect
    print_verbose "Verifying AMI credentials in manager.conf..."
    VERIFY_RESULT=0
    verify_and_fix_ami_credentials "$AMI_HOST" "$AMI_PORT" "$AMI_USERNAME" "$AMI_SECRET" "true" && VERIFY_RESULT=$? || VERIFY_RESULT=$?
    
    if [ $VERIFY_RESULT -eq 1 ]; then
        # Fixes were applied, give Asterisk time to reload
        print_info "AMI configuration was updated, waiting for Asterisk to apply changes..."
        sleep 2
    fi
    
    # Use the check_and_fix_ami function if available, otherwise inline check
    if type check_and_fix_ami &>/dev/null; then
        # Use the comprehensive check from health-check.sh
        # Use && ... || ... pattern to capture exit code without triggering set -e
        AMI_CHECK_RESULT=$(check_and_fix_ami "$AMI_HOST" "$AMI_PORT" "$AMI_USERNAME" "$AMI_SECRET" "true" 2>&1) && AMI_CHECK_EXIT_CODE=0 || AMI_CHECK_EXIT_CODE=$?
        
        if [ $AMI_CHECK_EXIT_CODE -eq 0 ]; then
            print_success "✓ AMI socket is working correctly"
        elif [ $AMI_CHECK_EXIT_CODE -eq 2 ]; then
            # Unfixable - run automated diagnostic checks instead of asking user to check manually
            print_error "✗ AMI socket is not working and could not be fixed automatically"
            FAILED_SERVICES+=("Asterisk AMI")
            echo ""
            echo -e "${YELLOW}${BOLD}⚠️  WARNING: AMI Socket Issue Detected!${RESET}"
            echo -e "${YELLOW}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${RESET}"
            echo -e "${WHITE}The Asterisk Manager Interface (AMI) is not responding.${RESET}"
            echo -e "${WHITE}This is critical for RayanPBX to communicate with Asterisk.${RESET}"
            echo ""
            
            # Run automated diagnostic checks instead of asking user to manually check
            # This function performs all the checks automatically and uses Pollinations.AI for solutions
            perform_ami_diagnostic_checks "$AMI_HOST" "$AMI_PORT" "$AMI_USERNAME" "$AMI_SECRET"
            
            # Show the reapply-ami command as a fix option
            echo -e "${CYAN}${BOLD}🔧 To manually reapply AMI credentials:${RESET}"
            echo -e "  ${WHITE}sudo rayanpbx-cli diag reapply-ami${RESET}"
            echo ""
            
            echo -e "${YELLOW}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${RESET}"
            echo ""
        else
            # Other failure
            print_warning "✗ AMI socket check encountered an issue"
            FAILED_SERVICES+=("Asterisk AMI")
        fi
    else
        # Fallback: simple port check if health-check.sh functions not available
        if is_port_listening "$AMI_PORT"; then
            print_success "✓ AMI port $AMI_PORT is listening"
            
            # Try a simple connection test
            if command -v nc &> /dev/null; then
                AMI_RESPONSE=$(echo -e "Action: Login\r\nUsername: $AMI_USERNAME\r\nSecret: $AMI_SECRET\r\n\r\n" | timeout 5 nc "$AMI_HOST" "$AMI_PORT" 2>/dev/null | head -10)
                if echo "$AMI_RESPONSE" | grep -qi "Success"; then
                    print_success "✓ AMI authentication successful"
                else
                    print_warning "✗ AMI is listening but authentication failed"
                    echo ""
                    echo -e "${YELLOW}${BOLD}⚠️  WARNING: AMI Authentication Issue!${RESET}"
                    echo ""
                    # Run automated diagnostic checks instead of asking user to check manually
                    perform_ami_diagnostic_checks "$AMI_HOST" "$AMI_PORT" "$AMI_USERNAME" "$AMI_SECRET"
                    
                    # Show the reapply-ami command as a fix option
                    echo -e "${CYAN}${BOLD}🔧 To manually reapply AMI credentials:${RESET}"
                    echo -e "  ${WHITE}sudo rayanpbx-cli diag reapply-ami${RESET}"
                    echo ""
                    
                    FAILED_SERVICES+=("Asterisk AMI")
                fi
            else
                print_info "  (Could not verify authentication - nc not installed)"
            fi
        else
            print_error "✗ AMI port $AMI_PORT is not listening"
            echo ""
            echo -e "${YELLOW}${BOLD}⚠️  WARNING: AMI Socket Not Available!${RESET}"
            echo -e "${WHITE}Asterisk Manager Interface is not running on port $AMI_PORT.${RESET}"
            echo ""
            # Run automated diagnostic checks instead of asking user to check manually
            perform_ami_diagnostic_checks "$AMI_HOST" "$AMI_PORT" "$AMI_USERNAME" "$AMI_SECRET"
            
            # Show the reapply-ami command as a fix option
            echo -e "${CYAN}${BOLD}🔧 To manually reapply AMI credentials:${RESET}"
            echo -e "  ${WHITE}sudo rayanpbx-cli diag reapply-ami${RESET}"
            echo ""
            
            FAILED_SERVICES+=("Asterisk AMI")
        fi
    fi
    echo ""

    # Display health check summary
    if [ ${#FAILED_SERVICES[@]} -eq 0 ]; then
        print_box "All Services Healthy! ✅" "$GREEN"
    else
        print_warning "Some services need attention:"
        for service in "${FAILED_SERVICES[@]}"; do
            echo -e "  ${RED}✗${RESET} $service"
        done
        echo ""
        print_info "Installation completed but some services may need manual intervention"
        print_info "Review the error messages above for troubleshooting steps"
    fi
fi

# Final Banner
if next_step "Installation Complete! 🎉" "complete"; then

    # Only clear if TERM is set and not "dumb" (avoid errors in CI environments without TTY)
    if [ -n "${TERM:-}" ] && [ "${TERM}" != "dumb" ]; then
        # clear
        # print_banner
        :  # no-op placeholder - if block requires at least one command
    fi

    print_box "Installation Successful!" "$GREEN"

    echo -e "${BOLD}${CYAN}📊 System Services:${RESET}"
    echo -e "  ${GREEN}✓${RESET} API Server      : http://$(hostname -I | awk '{print $1}'):8000/api"
    echo -e "  ${GREEN}✓${RESET} Web Interface   : http://$(hostname -I | awk '{print $1}'):3000"
    echo -e "  ${GREEN}✓${RESET} WebSocket Server: ws://$(hostname -I | awk '{print $1}'):9000/ws"
    echo -e "  ${GREEN}✓${RESET} TUI Terminal    : ${WHITE}rayanpbx-tui${RESET}"
    echo ""

    echo -e "${BOLD}${CYAN}📞 SIP Endpoint for Clients:${RESET}"
    # Use the SIP_LISTENING_ADDRESS set during health check if available
    if [ -n "$SIP_LISTENING_ADDRESS" ]; then
        echo -e "  ${GREEN}✓${RESET} SIP Server      : ${WHITE}${SIP_LISTENING_ADDRESS}:5060${RESET} (UDP/TCP)"
        echo -e "  ${DIM}   Connect your SIP phones and softphones to this address${RESET}"
    else
        # Fallback - get current IP if not set
        sip_ip=$(hostname -I | awk '{print $1}')
        if is_port_listening 5060 2>/dev/null; then
            echo -e "  ${GREEN}✓${RESET} SIP Server      : ${WHITE}${sip_ip}:5060${RESET} (UDP/TCP)"
            echo -e "  ${DIM}   Connect your SIP phones and softphones to this address${RESET}"
        else
            echo -e "  ${RED}✗${RESET} SIP Server      : ${YELLOW}Not listening - check Asterisk configuration${RESET}"
            echo -e "  ${DIM}   Run: rayanpbx-cli diag check-sip${RESET}"
        fi
    fi
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
    echo -e "  ${DIM}RayanPBX TUI:${RESET}      ${WHITE}rayanpbx-tui${RESET}   ${GREEN}(Interactive Terminal UI!)${RESET}"
    echo -e "  ${DIM}RayanPBX CLI:${RESET}      ${WHITE}rayanpbx-cli help${RESET}"
    echo -e "  ${DIM}Health check:${RESET}      ${WHITE}rayanpbx-cli diag health-check${RESET}"
    echo -e "  ${DIM}SIP check:${RESET}         ${WHITE}rayanpbx-cli diag check-sip${RESET}"
    echo -e "  ${DIM}View services:${RESET}     pm2 list"
    echo -e "  ${DIM}View logs:${RESET}         pm2 logs"
    echo -e "  ${DIM}Asterisk CLI:${RESET}      asterisk -rvvv   ${GREEN}(Recommended!)${RESET}"
    echo -e "  ${DIM}Asterisk status:${RESET}   systemctl status asterisk"
    echo -e "  ${DIM}System status:${RESET}     systemctl status rayanpbx-api"
    echo ""

    echo -e "${BOLD}${CYAN}🚀 Next Steps:${RESET}"
    echo -e "  ${GREEN}1.${RESET} ${BOLD}Launch Asterisk Console${RESET} to monitor calls:"
    echo -e "     ${WHITE}asterisk -rvvv${RESET}  ${DIM}(press 'exit' or Ctrl+C to quit)${RESET}"
    echo ""
    echo -e "  ${GREEN}2.${RESET} Access web UI: http://$(hostname -I | awk '{print $1}'):3000"
    echo ""
    echo -e "  ${GREEN}3.${RESET} Login with admin/admin"
    echo ""
    echo -e "  ${GREEN}4.${RESET} Configure your first extension"
    echo ""
    echo -e "  ${GREEN}5.${RESET} Set up a SIP trunk"
    echo ""
    echo -e "  ${GREEN}6.${RESET} Test your setup"
    echo ""

    echo -e "${BOLD}${CYAN}⚠️  Security Notice:${RESET}"
    echo -e "  ${YELLOW}Debug mode is ENABLED${RESET} for easier troubleshooting during setup."
    echo -e "  ${DIM}File: /opt/rayanpbx/.env (APP_DEBUG=true, APP_ENV=development)${RESET}"
    echo ""
    echo -e "  ${BOLD}For production use, choose one of these methods:${RESET}"
    echo -e ""
    echo -e "  ${CYAN}${BOLD}Method 1: Using CLI (Recommended)${RESET}"
    echo -e "  ${WHITE}rayanpbx-cli system set-mode production${RESET}"
    echo -e ""
    echo -e "  ${CYAN}${BOLD}Method 2: Using TUI${RESET}"
    echo -e "  ${WHITE}rayanpbx-tui${RESET} ${DIM}(then navigate to System Settings)${RESET}"
    echo -e ""
    echo -e "  ${CYAN}${BOLD}Method 3: Manually${RESET}"
    echo -e "  ${WHITE}1.${RESET} Edit ${CYAN}/opt/rayanpbx/.env${RESET}"
    echo -e "  ${WHITE}2.${RESET} Set ${CYAN}APP_DEBUG=false${RESET} and ${CYAN}APP_ENV=production${RESET}"
    echo -e "  ${WHITE}3.${RESET} Restart: ${CYAN}systemctl restart rayanpbx-api${RESET}"
    echo ""

    echo -e "${BOLD}${CYAN}📚 Documentation & Support:${RESET}"
    echo -e "  ${DIM}GitHub:${RESET}  https://github.com/atomicdeploy/rayanpbx"
    echo -e "  ${DIM}Issues:${RESET}  https://github.com/atomicdeploy/rayanpbx/issues"
    echo ""

    print_box "Thank you for installing RayanPBX! 💙" "$CYAN"
    echo ""
fi
