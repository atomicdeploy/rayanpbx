#!/bin/bash
#
# RayanPBX LLDP Diagnostics Script
# 
# This script tests the LLDP functionality for discovering VoIP phones.
# It checks if lldpd is installed and running, and displays LLDP neighbors.
#
# Usage: ./diag-lldp.sh [--verbose]
#

set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

VERBOSE=false

# Parse arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        -v|--verbose)
            VERBOSE=true
            shift
            ;;
        -h|--help)
            echo "Usage: $0 [--verbose]"
            echo ""
            echo "Options:"
            echo "  -v, --verbose    Show detailed output"
            echo "  -h, --help       Show this help message"
            exit 0
            ;;
        *)
            echo "Unknown option: $1"
            exit 1
            ;;
    esac
done

print_header() {
    echo ""
    echo -e "${BLUE}========================================${NC}"
    echo -e "${BLUE}  RayanPBX LLDP Diagnostics${NC}"
    echo -e "${BLUE}========================================${NC}"
    echo ""
}

print_success() {
    echo -e "${GREEN}✓ $1${NC}"
}

print_error() {
    echo -e "${RED}✗ $1${NC}"
}

print_warning() {
    echo -e "${YELLOW}! $1${NC}"
}

print_info() {
    echo -e "${BLUE}ℹ $1${NC}"
}

check_lldpd_installed() {
    echo -e "\n${BLUE}[1/5] Checking lldpd installation...${NC}"
    
    if command -v lldpctl &> /dev/null; then
        print_success "lldpctl command is available"
        if $VERBOSE; then
            echo "  Path: $(which lldpctl)"
        fi
        return 0
    elif command -v lldpcli &> /dev/null; then
        print_success "lldpcli command is available"
        if $VERBOSE; then
            echo "  Path: $(which lldpcli)"
        fi
        return 0
    else
        print_error "lldpd is not installed"
        echo ""
        echo "  Install with:"
        echo "    Ubuntu/Debian: sudo apt install lldpd"
        echo "    CentOS/RHEL:   sudo yum install lldpd"
        echo "    Fedora:        sudo dnf install lldpd"
        return 1
    fi
}

check_lldpd_service() {
    echo -e "\n${BLUE}[2/5] Checking lldpd service status...${NC}"
    
    if systemctl is-active --quiet lldpd 2>/dev/null; then
        print_success "lldpd service is running"
        if $VERBOSE; then
            systemctl status lldpd --no-pager | head -10
        fi
        return 0
    else
        print_error "lldpd service is not running"
        echo ""
        echo "  Start with:"
        echo "    sudo systemctl enable lldpd"
        echo "    sudo systemctl start lldpd"
        return 1
    fi
}

check_network_interfaces() {
    echo -e "\n${BLUE}[3/5] Checking network interfaces...${NC}"
    
    # Get interfaces with LLDP enabled
    if command -v lldpctl &> /dev/null; then
        INTERFACES=$(lldpctl -f keyvalue 2>/dev/null | grep "^lldp\." | cut -d'.' -f2 | sort -u || true)
    elif command -v lldpcli &> /dev/null; then
        INTERFACES=$(lldpcli show interfaces 2>/dev/null | grep "Interface:" | awk '{print $2}' | tr -d ',' || true)
    fi
    
    if [ -n "$INTERFACES" ]; then
        print_success "LLDP is enabled on the following interfaces:"
        echo "$INTERFACES" | while read iface; do
            echo "    - $iface"
        done
    else
        print_warning "No interfaces with LLDP data found"
        echo ""
        echo "  This might be normal if no LLDP neighbors are connected yet."
        echo "  Active network interfaces:"
        ip link show | grep "state UP" | awk -F: '{print "    - " $2}' | tr -d ' '
    fi
}

show_lldp_neighbors() {
    echo -e "\n${BLUE}[4/5] Discovering LLDP neighbors...${NC}"
    
    # Try lldpcli show neighbors (human-readable format)
    if command -v lldpcli &> /dev/null; then
        echo ""
        echo "Using: lldpcli show neighbors"
        echo "----------------------------------------"
        
        OUTPUT=$(lldpcli show neighbors 2>&1 || true)
        
        if echo "$OUTPUT" | grep -q "Interface:"; then
            echo "$OUTPUT"
            echo ""
            
            # Count neighbors
            NEIGHBOR_COUNT=$(echo "$OUTPUT" | grep -c "Interface:" || echo "0")
            print_success "Found $NEIGHBOR_COUNT LLDP neighbor(s)"
            
            # Extract VoIP phones
            echo ""
            echo "VoIP Phones detected:"
            echo "$OUTPUT" | grep -E "(GXP|GRP|GXV|Yealink|Polycom|Cisco|Snom|Panasonic|Fanvil)" || echo "  (none detected by model name)"
        else
            print_warning "No LLDP neighbors found"
            echo ""
            echo "  Possible reasons:"
            echo "    - No LLDP-capable devices connected"
            echo "    - VoIP phones may need time to send LLDP frames"
            echo "    - LLDP might be disabled on network devices"
        fi
    fi
    
    # Also try lldpctl -f keyvalue for parsing test
    if command -v lldpctl &> /dev/null && $VERBOSE; then
        echo ""
        echo "Keyvalue format (for parsing):"
        echo "----------------------------------------"
        lldpctl -f keyvalue 2>&1 | head -50 || echo "(empty or error)"
    fi
}

test_parsing() {
    echo -e "\n${BLUE}[5/5] Testing LLDP parsing...${NC}"
    
    # Create a sample LLDP output for testing
    SAMPLE_OUTPUT='-------------------------------------------------------------------------------
LLDP neighbors:
-------------------------------------------------------------------------------
Interface:    eno1, via: LLDP, RID: 1, Time: 0 day, 21:21:23
  Chassis:
    ChassisID:    ip 172.20.6.150
    SysName:      GXP1630_ec:74:d7:2f:7e:a2
    SysDescr:     GXP1630 1.0.7.64
    Capability:   Bridge, on
    Capability:   Tel, on
  Port:
    PortID:       mac ec:74:d7:2f:7e:a2
    PortDescr:    eth0
    TTL:          120
-------------------------------------------------------------------------------
Interface:    eno1, via: LLDP, RID: 2, Time: 0 day, 21:21:23
  Chassis:
    ChassisID:    ip 172.20.6.104
    SysName:      GXP1625_ec:74:d7:52:50:37
    SysDescr:     GXP1625 1.0.7.64
    Capability:   Bridge, on
    Capability:   Tel, on
  Port:
    PortID:       mac ec:74:d7:52:50:37
    PortDescr:    eth0
    TTL:          120
-------------------------------------------------------------------------------'
    
    echo ""
    echo "Parsing sample LLDP output..."
    
    # Parse the sample output
    echo "$SAMPLE_OUTPUT" | while IFS= read -r line; do
        if echo "$line" | grep -q "^Interface:"; then
            echo ""
            IFACE=$(echo "$line" | sed 's/Interface:\s*//' | cut -d',' -f1)
            echo -e "  ${GREEN}Found interface: $IFACE${NC}"
        fi
        
        if echo "$line" | grep -q "ChassisID:.*ip "; then
            IP=$(echo "$line" | sed 's/.*ip //')
            echo "    IP Address: $IP"
        fi
        
        if echo "$line" | grep -q "SysName:"; then
            NAME=$(echo "$line" | sed 's/.*SysName:\s*//')
            echo "    System Name: $NAME"
        fi
        
        if echo "$line" | grep -q "SysDescr:"; then
            DESC=$(echo "$line" | sed 's/.*SysDescr:\s*//')
            echo "    Description: $DESC"
            
            # Check if it's a VoIP phone
            if echo "$DESC" | grep -qiE "(GXP|GRP|GXV|Yealink|Polycom|Cisco|Snom|Fanvil)"; then
                echo -e "    ${GREEN}✓ Detected as VoIP phone${NC}"
            fi
        fi
        
        if echo "$line" | grep -q "PortID:.*mac "; then
            MAC=$(echo "$line" | sed 's/.*mac //')
            echo "    MAC Address: $MAC"
        fi
    done
    
    print_success "LLDP parsing test completed"
}

# Main execution
print_header

# Run checks
check_lldpd_installed || true
check_lldpd_service || true
check_network_interfaces
show_lldp_neighbors
test_parsing

echo ""
echo -e "${BLUE}========================================${NC}"
echo -e "${BLUE}  Diagnostics Complete${NC}"
echo -e "${BLUE}========================================${NC}"
echo ""

# Summary
echo "Summary:"
echo "--------"
if command -v lldpctl &> /dev/null || command -v lldpcli &> /dev/null; then
    print_success "lldpd is installed"
else
    print_error "lldpd is NOT installed"
fi

if systemctl is-active --quiet lldpd 2>/dev/null; then
    print_success "lldpd service is running"
else
    print_error "lldpd service is NOT running"
fi

echo ""
echo "For more information, run with --verbose flag"
