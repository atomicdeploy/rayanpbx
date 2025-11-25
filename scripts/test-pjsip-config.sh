#!/bin/bash

# RayanPBX PJSIP Extension Test Script
# This script validates that PJSIP extensions are properly configured

set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

echo -e "${CYAN}╔════════════════════════════════════════════════════════╗${NC}"
echo -e "${CYAN}║   RayanPBX PJSIP Extension Configuration Test         ║${NC}"
echo -e "${CYAN}╚════════════════════════════════════════════════════════╝${NC}"
echo ""

# Check if running as root or with sudo
if [[ $EUID -ne 0 ]]; then
   echo -e "${RED}✗ This script must be run as root or with sudo${NC}" 
   exit 1
fi

echo -e "${CYAN}1. Checking Asterisk service...${NC}"
if systemctl is-active --quiet asterisk; then
    echo -e "${GREEN}   ✓ Asterisk is running${NC}"
else
    echo -e "${RED}   ✗ Asterisk is not running${NC}"
    echo -e "${YELLOW}   Starting Asterisk...${NC}"
    systemctl start asterisk
    sleep 2
fi

echo ""
echo -e "${CYAN}2. Checking PJSIP configuration file...${NC}"
if [[ -f /etc/asterisk/pjsip.conf ]]; then
    echo -e "${GREEN}   ✓ pjsip.conf exists${NC}"
    
    # Check for transport configuration
    if grep -q "type=transport" /etc/asterisk/pjsip.conf; then
        echo -e "${GREEN}   ✓ Transport configuration found${NC}"
    else
        echo -e "${YELLOW}   ⚠ Transport configuration not found${NC}"
        echo -e "${YELLOW}   Adding basic transport configuration...${NC}"
        
        cat >> /etc/asterisk/pjsip.conf << 'EOF'

; BEGIN MANAGED - RayanPBX Transports
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
        echo -e "${GREEN}   ✓ Transport configuration added${NC}"
    fi
else
    echo -e "${RED}   ✗ pjsip.conf not found${NC}"
    echo -e "${YELLOW}   Creating basic pjsip.conf...${NC}"
    
    cat > /etc/asterisk/pjsip.conf << 'EOF'
; RayanPBX PJSIP Configuration

[global]
type=global
max_forwards=70
keep_alive_interval=90

; BEGIN MANAGED - RayanPBX Transports
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
    echo -e "${GREEN}   ✓ Basic pjsip.conf created${NC}"
fi

echo ""
echo -e "${CYAN}3. Checking extensions.conf...${NC}"
if [[ -f /etc/asterisk/extensions.conf ]]; then
    echo -e "${GREEN}   ✓ extensions.conf exists${NC}"
    
    # Check for internal context
    if grep -q "\[internal\]" /etc/asterisk/extensions.conf; then
        echo -e "${GREEN}   ✓ Internal context found${NC}"
    else
        echo -e "${YELLOW}   ⚠ Internal context not found${NC}"
        echo -e "${YELLOW}   Adding internal context...${NC}"
        
        cat >> /etc/asterisk/extensions.conf << 'EOF'

; BEGIN MANAGED - RayanPBX Internal Extensions
[internal]
; Pattern match for all extensions
exten => _1XXX,1,NoOp(Extension to extension call: ${EXTEN})
 same => n,Dial(PJSIP/${EXTEN},30)
 same => n,Hangup()
; END MANAGED - RayanPBX Internal Extensions
EOF
        echo -e "${GREEN}   ✓ Internal context added${NC}"
    fi
else
    echo -e "${RED}   ✗ extensions.conf not found${NC}"
fi

echo ""
echo -e "${CYAN}4. Reloading Asterisk configuration...${NC}"
asterisk -rx "pjsip reload" > /dev/null 2>&1 || true
asterisk -rx "dialplan reload" > /dev/null 2>&1 || true
sleep 1
echo -e "${GREEN}   ✓ Configuration reloaded${NC}"

echo ""
echo -e "${CYAN}5. Checking PJSIP endpoints...${NC}"
ENDPOINTS=$(asterisk -rx "pjsip show endpoints" 2>/dev/null | grep -E "^\s+[0-9]+" | wc -l)
if [[ $ENDPOINTS -gt 0 ]]; then
    echo -e "${GREEN}   ✓ Found $ENDPOINTS PJSIP endpoint(s)${NC}"
    echo ""
    asterisk -rx "pjsip show endpoints"
else
    echo -e "${YELLOW}   ⚠ No PJSIP endpoints configured yet${NC}"
    echo -e "${YELLOW}   This is normal for a fresh installation${NC}"
fi

echo ""
echo -e "${CYAN}6. Checking PJSIP transports...${NC}"
TRANSPORTS=$(asterisk -rx "pjsip show transports" 2>/dev/null)
if echo "$TRANSPORTS" | grep -q "transport-udp"; then
    echo -e "${GREEN}   ✓ UDP transport is active${NC}"
else
    echo -e "${YELLOW}   ⚠ UDP transport not found${NC}"
fi
if echo "$TRANSPORTS" | grep -q "transport-tcp"; then
    echo -e "${GREEN}   ✓ TCP transport is active${NC}"
else
    echo -e "${YELLOW}   ⚠ TCP transport not found${NC}"
fi
if echo "$TRANSPORTS" | grep -q "transport-udp\|transport-tcp"; then
    echo ""
    echo "$TRANSPORTS"
else
    echo -e "${RED}   ✗ No UDP or TCP transports found${NC}"
fi

echo ""
echo -e "${CYAN}7. Network connectivity check...${NC}"
if netstat -tunlp 2>/dev/null | grep -q ":5060"; then
    LISTENING=$(netstat -tunlp 2>/dev/null | grep ":5060")
    echo -e "${GREEN}   ✓ Asterisk is listening on port 5060${NC}"
    echo -e "   ${LISTENING}"
else
    echo -e "${RED}   ✗ Port 5060 is not listening${NC}"
fi

echo ""
echo -e "${CYAN}8. Checking firewall...${NC}"
if command -v ufw &> /dev/null; then
    if ufw status | grep -q "5060"; then
        echo -e "${GREEN}   ✓ UFW rule for port 5060 exists${NC}"
    else
        echo -e "${YELLOW}   ⚠ UFW rule for port 5060 not found${NC}"
        echo -e "${YELLOW}   Consider adding: ufw allow 5060/udp${NC}"
    fi
elif command -v firewall-cmd &> /dev/null; then
    if firewall-cmd --list-ports 2>/dev/null | grep -q "5060"; then
        echo -e "${GREEN}   ✓ Firewall rule for port 5060 exists${NC}"
    else
        echo -e "${YELLOW}   ⚠ Firewall rule for port 5060 not found${NC}"
        echo -e "${YELLOW}   Consider adding: firewall-cmd --permanent --add-port=5060/udp${NC}"
    fi
else
    echo -e "${YELLOW}   ⚠ No firewall detected${NC}"
fi

echo ""
echo -e "${CYAN}═══════════════════════════════════════════════════════${NC}"
echo -e "${GREEN}✓ Configuration test complete!${NC}"
echo ""
echo -e "${CYAN}Next steps:${NC}"
echo -e "  1. Create extensions via Web UI or API"
echo -e "  2. Configure your SIP client with:"
echo -e "     - Server: $(hostname -I | awk '{print $1}')"
echo -e "     - Port: 5060"
echo -e "     - Extension: 1001 (example)"
echo -e "     - Password: Your configured password"
echo -e "  3. Monitor events: php artisan rayanpbx:monitor-events"
echo ""
echo -e "${CYAN}For detailed setup instructions, see:${NC}"
echo -e "  ${YELLOW}PJSIP_SETUP_GUIDE.md${NC}"
echo ""
