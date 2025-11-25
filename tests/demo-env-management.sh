#!/bin/bash

# RayanPBX Environment Configuration Management Demo
# This script demonstrates the new .env management features

set -e

# Colors
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${BLUE}╔══════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║                                                  ║${NC}"
echo -e "${BLUE}║   RayanPBX Environment Configuration Manager     ║${NC}"
echo -e "${BLUE}║              Feature Demonstration               ║${NC}"
echo -e "${BLUE}║                                                  ║${NC}"
echo -e "${BLUE}╚══════════════════════════════════════════════════╝${NC}"
echo ""

# Setup
export RAYANPBX_ROOT="${RAYANPBX_ROOT:-$(pwd)}"
CLI="bash $RAYANPBX_ROOT/scripts/rayanpbx-cli.sh"

echo -e "${GREEN}[1/7] Demonstrating: List all configurations${NC}"
echo -e "${YELLOW}Command: rayanpbx-cli config list${NC}"
echo ""
$CLI config list | head -20
echo "..."
echo ""
sleep 2

echo -e "${GREEN}[2/7] Demonstrating: Get a specific configuration value${NC}"
echo -e "${YELLOW}Command: rayanpbx-cli config get APP_NAME${NC}"
echo ""
APP_NAME=$($CLI config get APP_NAME)
echo "Result: $APP_NAME"
echo ""
sleep 2

echo -e "${GREEN}[3/7] Demonstrating: Add a new configuration${NC}"
echo -e "${YELLOW}Command: rayanpbx-cli config add DEMO_FEATURE_FLAG enabled${NC}"
echo ""
$CLI config add DEMO_FEATURE_FLAG enabled
echo ""
sleep 2

echo -e "${GREEN}[4/7] Demonstrating: Verify the new configuration${NC}"
echo -e "${YELLOW}Command: rayanpbx-cli config get DEMO_FEATURE_FLAG${NC}"
echo ""
DEMO_VALUE=$($CLI config get DEMO_FEATURE_FLAG)
echo "Result: $DEMO_VALUE"
echo ""
sleep 2

echo -e "${GREEN}[5/7] Demonstrating: Update the configuration${NC}"
echo -e "${YELLOW}Command: rayanpbx-cli config set DEMO_FEATURE_FLAG disabled${NC}"
echo ""
$CLI config set DEMO_FEATURE_FLAG disabled
echo ""
sleep 2

echo -e "${GREEN}[6/7] Demonstrating: Verify the update${NC}"
echo -e "${YELLOW}Command: rayanpbx-cli config get DEMO_FEATURE_FLAG${NC}"
echo ""
UPDATED_VALUE=$($CLI config get DEMO_FEATURE_FLAG)
echo "Result: $UPDATED_VALUE"
echo ""
sleep 2

echo -e "${GREEN}[7/7] Demonstrating: Remove the configuration${NC}"
echo -e "${YELLOW}Command: rayanpbx-cli config remove DEMO_FEATURE_FLAG${NC}"
echo ""
$CLI config remove DEMO_FEATURE_FLAG
echo ""
sleep 2

echo -e "${BLUE}═══════════════════════════════════════════════════${NC}"
echo -e "${GREEN}✅ Demo completed successfully!${NC}"
echo ""
echo -e "${YELLOW}Additional Features Available:${NC}"
echo ""
echo "  📝 CLI Commands:"
echo "     • rayanpbx-cli config list"
echo "     • rayanpbx-cli config get <KEY>"
echo "     • rayanpbx-cli config set <KEY> <VALUE>"
echo "     • rayanpbx-cli config add <KEY> <VALUE>"
echo "     • rayanpbx-cli config remove <KEY>"
echo "     • rayanpbx-cli config reload [service]"
echo ""
echo "  🎨 TUI Interface:"
echo "     • rayanpbx-cli tui"
echo "     • Navigate to 'Configuration Management'"
echo "     • Interactive menu-driven interface"
echo ""
echo "  🌐 Web Interface:"
echo "     • Access via browser at http://localhost:3000/config"
echo "     • Beautiful UI with search and filters"
echo "     • Real-time service reload"
echo ""
echo "  🔒 Security Features:"
echo "     • Automatic sensitive value masking"
echo "     • Timestamped backups before changes"
echo "     • Key validation"
echo "     • JWT authentication for API"
echo ""
echo -e "${BLUE}═══════════════════════════════════════════════════${NC}"
echo ""
echo "For more information, see ENV_MANAGEMENT.md"
echo ""
