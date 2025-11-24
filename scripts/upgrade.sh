#!/bin/bash

# RayanPBX Upgrade Script
# Simple wrapper that calls install.sh with --upgrade flag

set -euo pipefail

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
WHITE='\033[1;37m'
RESET='\033[0m'

# Get script directory
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
INSTALL_SCRIPT="$REPO_ROOT/install.sh"

# Parse arguments for interactive mode
INTERACTIVE=false
while [[ $# -gt 0 ]]; do
    case $1 in
        -i|--confirm)
            INTERACTIVE=true
            shift
            ;;
        -h|--help)
            # Show install.sh help with --upgrade flag
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

# Execute install.sh with --upgrade and pass through all original arguments
cd "$REPO_ROOT"
exec "$INSTALL_SCRIPT" --upgrade "$@"
