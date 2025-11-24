#!/bin/bash

# RayanPBX Upgrade Script
# Simple wrapper that calls install.sh with --upgrade flag

set -euo pipefail

# Version - read from VERSION file
VERSION="2.0.0"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
VERSION_FILE="$SCRIPT_DIR/../VERSION"
if [ -f "$VERSION_FILE" ]; then
    VERSION=$(cat "$VERSION_FILE" | tr -d '[:space:]')
fi

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
MAGENTA='\033[0;35m'
WHITE='\033[1;37m'
BOLD='\033[1m'
DIM='\033[2m'
RESET='\033[0m'

# Emojis
ROCKET="🚀"

# Get repository root
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
INSTALL_SCRIPT="$REPO_ROOT/install.sh"

print_header() {
    echo -e "${MAGENTA}${BOLD}"
    echo "╔════════════════════════════════════════════════════╗"
    echo "║  $ROCKET RayanPBX Upgrade Utility $ROCKET                   ║"
    echo "╚════════════════════════════════════════════════════╝"
    echo -e "${RESET}"
    echo -e "${CYAN}Version: ${VERSION}${RESET}\n"
}

# Parse arguments for interactive mode and backup flag
INTERACTIVE=false
CREATE_BACKUP=false
while [[ $# -gt 0 ]]; do
    case $1 in
        -i|--confirm)
            INTERACTIVE=true
            shift
            ;;
        -b|--backup)
            CREATE_BACKUP=true
            shift
            ;;
        -h|--help)
            # Show install.sh help
            if [ -f "$INSTALL_SCRIPT" ]; then
                "$INSTALL_SCRIPT" --help
            fi
            exit 0
            ;;
        *)
            # Pass through all other arguments
            break
            ;;
    esac
done

# Check if install.sh exists
if [ ! -f "$INSTALL_SCRIPT" ]; then
    echo -e "${RED}Error: install.sh not found at: $INSTALL_SCRIPT${RESET}"
    echo -e "${YELLOW}Cannot proceed with upgrade.${RESET}"
    exit 1
fi

# Check if running as root
if [ "$EUID" -ne 0 ]; then
    echo -e "${RED}Error: This script must be run as root${RESET}"
    echo -e "${YELLOW}Please run: ${WHITE}sudo $0${RESET}"
    exit 1
fi

# Print header
print_header

echo -e "${CYAN}This upgrade script provides a convenient way to update RayanPBX.${RESET}"
echo ""
echo -e "${CYAN}The install script automatically:${RESET}"
echo -e "  ${GREEN}•${RESET} Detects whether to install or update"
echo -e "  ${GREEN}•${RESET} Backs up your configuration before updates (with --backup flag)"
echo -e "  ${GREEN}•${RESET} Stashes local changes automatically"
echo -e "  ${GREEN}•${RESET} Updates all dependencies and services"
echo -e "  ${GREEN}•${RESET} Clears caches and restarts services"
echo ""
echo -e "${CYAN}Upgrading using the install script:${RESET}"
echo -e "  ${WHITE}cd /opt/rayanpbx && sudo ./install.sh --upgrade${RESET}"
echo ""
echo -e "${CYAN}Or with verbose output:${RESET}"
echo -e "  ${WHITE}cd /opt/rayanpbx && sudo ./install.sh --upgrade --verbose${RESET}"
echo ""
echo -e "${DIM}────────────────────────────────────────────────────────────${RESET}"
echo ""

# Ask for confirmation if in interactive mode
if [ "$INTERACTIVE" = true ]; then
    echo -e "${CYAN}This script will launch ${WHITE}install.sh --upgrade${RESET}${CYAN} to perform the upgrade.${RESET}"
    echo ""
    read -p "$(echo -e ${CYAN}Continue with upgrade? \(y/n\) ${RESET})" -n 1 -r
    echo ""
    echo ""
    
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        echo -e "${YELLOW}Upgrade cancelled.${RESET}"
        exit 0
    fi
fi

# Build arguments for install.sh
INSTALL_ARGS="--upgrade"
if [ "$CREATE_BACKUP" = true ]; then
    INSTALL_ARGS="$INSTALL_ARGS --backup"
fi

# Execute install.sh with --upgrade and pass through all original arguments
if ! cd "$REPO_ROOT" 2>/dev/null; then
    echo -e "${RED}Error: Cannot access repository root: $REPO_ROOT${RESET}"
    exit 1
fi

exec "$INSTALL_SCRIPT" $INSTALL_ARGS "$@"

