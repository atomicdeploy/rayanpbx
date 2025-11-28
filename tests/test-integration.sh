#!/bin/bash

# RayanPBX Integration Testing Script
# Tests actual SIP registration and calls using PJSUA

set -e

# Colors for output
readonly GREEN='\033[0;32m'
readonly RED='\033[0;31m'
readonly YELLOW='\033[1;33m'
readonly BLUE='\033[0;34m'
readonly CYAN='\033[0;36m'
readonly BOLD='\033[1m'
readonly RESET='\033[0m'

TEST_RESULTS=()
TEST_COUNT=0
PASS_COUNT=0
FAIL_COUNT=0

print_header() {
    echo -e "${CYAN}${BOLD}"
    echo "╔════════════════════════════════════════════════════════════╗"
    echo "║                                                            ║"
    echo "║        🧪  RayanPBX Integration Test Suite  🧪            ║"
    echo "║                                                            ║"
    echo "╚════════════════════════════════════════════════════════════╝"
    echo -e "${RESET}\n"
}

print_test() {
    ((TEST_COUNT++))
    echo -e "${BLUE}${BOLD}[TEST $TEST_COUNT]${RESET} $1"
}

print_pass() {
    ((PASS_COUNT++))
    echo -e "${GREEN}✅ PASS${RESET}: $1"
    TEST_RESULTS+=("PASS: $1")
}

print_fail() {
    ((FAIL_COUNT++))
    echo -e "${RED}❌ FAIL${RESET}: $1"
    TEST_RESULTS+=("FAIL: $1")
}

print_info() {
    echo -e "${CYAN}ℹ️  $1${RESET}"
}

print_warning() {
    echo -e "${YELLOW}⚠️  $1${RESET}"
}

# Check if PJSUA is installed
check_pjsua() {
    print_test "Checking for PJSUA (SIP testing tool)"
    
    if command -v pjsua &> /dev/null; then
        print_pass "PJSUA is installed"
        return 0
    else
        print_fail "PJSUA not found"
        print_warning "Installing PJSUA..."
        
        apt-get update -qq
        apt-get install -y pjsua > /dev/null 2>&1
        
        if command -v pjsua &> /dev/null; then
            print_pass "PJSUA installed successfully"
            return 0
        else
            print_fail "Failed to install PJSUA"
            return 1
        fi
    fi
}

# Test Asterisk is running
test_asterisk_running() {
    print_test "Verifying Asterisk is running"
    
    if systemctl is-active --quiet asterisk; then
        print_pass "Asterisk service is active"
        return 0
    else
        print_fail "Asterisk service is not running"
        print_info "Try: sudo systemctl start asterisk"
        return 1
    fi
}

# Test Asterisk version
test_asterisk_version() {
    print_test "Checking Asterisk version"
    
    VERSION=$(asterisk -rx "core show version" 2>/dev/null | head -1)
    
    if [ -n "$VERSION" ]; then
        print_pass "Asterisk version: $VERSION"
        return 0
    else
        print_fail "Cannot get Asterisk version"
        return 1
    fi
}

# Test PJSIP configuration
test_pjsip_config() {
    print_test "Checking PJSIP configuration"
    
    if [ -f /etc/asterisk/pjsip.conf ]; then
        print_pass "pjsip.conf exists"
    else
        print_fail "pjsip.conf not found"
        return 1
    fi
    
    # Check if transport is configured
    if grep -q "type=transport" /etc/asterisk/pjsip.conf; then
        print_pass "PJSIP transport configured"
    else
        print_fail "No PJSIP transport found"
        return 1
    fi
    
    return 0
}

# Test database connection
test_database() {
    print_test "Testing database connection"
    
    # Get database credentials from .env
    if [ -f /opt/rayanpbx/.env ]; then
        source /opt/rayanpbx/.env
        
        if mysql -u"${DB_USERNAME}" -p"${DB_PASSWORD}" -e "USE ${DB_DATABASE};" 2>/dev/null; then
            print_pass "Database connection successful"
            
            # Check tables
            TABLES=$(mysql -u"${DB_USERNAME}" -p"${DB_PASSWORD}" -D "${DB_DATABASE}" -e "SHOW TABLES;" 2>/dev/null | tail -n +2)
            
            if echo "$TABLES" | grep -q "extensions"; then
                print_pass "Extensions table exists"
            else
                print_fail "Extensions table not found"
            fi
            
            if echo "$TABLES" | grep -q "trunks"; then
                print_pass "Trunks table exists"
            else
                print_fail "Trunks table not found"
            fi
            
            return 0
        else
            print_fail "Database connection failed"
            return 1
        fi
    else
        print_fail ".env file not found"
        return 1
    fi
}

# Create test extension
# Uses proper INI section manipulation instead of managed comment blocks
create_test_extension() {
    local EXT_NUM=$1
    local PASSWORD=$2
    
    print_test "Creating test extension $EXT_NUM"
    
    # First remove any existing sections for this extension
    remove_extension_sections "$EXT_NUM"
    
    # Append PJSIP endpoint sections (endpoint, auth, aor)
    cat >> /etc/asterisk/pjsip.conf <<EOF

[${EXT_NUM}]
type=endpoint
context=from-internal
disallow=all
allow=ulaw
allow=alaw
allow=g722
transport=transport-udp
auth=${EXT_NUM}
aors=${EXT_NUM}

[${EXT_NUM}]
type=auth
auth_type=userpass
username=${EXT_NUM}
password=${PASSWORD}

[${EXT_NUM}]
type=aor
max_contacts=1
remove_existing=yes
EOF
    
    # Reload PJSIP
    asterisk -rx "pjsip reload" > /dev/null 2>&1
    sleep 2
    
    # Verify extension was created
    if asterisk -rx "pjsip show endpoint ${EXT_NUM}" 2>/dev/null | grep -q "${EXT_NUM}"; then
        print_pass "Test extension ${EXT_NUM} created"
        return 0
    else
        print_fail "Failed to create extension ${EXT_NUM}"
        return 1
    fi
}

# Remove extension sections from pjsip.conf by extension number
# Uses awk to remove all sections matching the extension name
remove_extension_sections() {
    local EXT_NUM=$1
    local pjsip_conf="/etc/asterisk/pjsip.conf"
    
    if [ ! -f "$pjsip_conf" ]; then
        return 0
    fi
    
    local temp_file=$(mktemp)
    awk -v ext="$EXT_NUM" '
        /^\[/ { 
            # Extract section name
            match($0, /^\[([^\]]+)\]/, arr)
            section_name = arr[1]
            # Check if this section belongs to the extension we want to remove
            if (section_name == ext) {
                skip_section = 1
                next
            } else {
                skip_section = 0
            }
        }
        !skip_section { print }
    ' "$pjsip_conf" > "$temp_file"
    
    mv "$temp_file" "$pjsip_conf" 2>/dev/null || true
}

# Test SIP registration
test_sip_registration() {
    local EXT_NUM=$1
    local PASSWORD=$2
    
    print_test "Testing SIP registration for extension $EXT_NUM"
    
    # Create PJSUA account config
    cat > /tmp/pjsua-${EXT_NUM}.cfg <<EOF
--id sip:${EXT_NUM}@127.0.0.1
--registrar sip:127.0.0.1
--realm *
--username ${EXT_NUM}
--password ${PASSWORD}
--auto-answer 200
--duration 10
--null-audio
EOF
    
    # Start PJSUA in background
    timeout 15 pjsua --config-file /tmp/pjsua-${EXT_NUM}.cfg > /tmp/pjsua-${EXT_NUM}.log 2>&1 &
    PJSUA_PID=$!
    
    sleep 5
    
    # Check if registered
    if grep -q "registration success" /tmp/pjsua-${EXT_NUM}.log || grep -q "Registration successful" /tmp/pjsua-${EXT_NUM}.log; then
        print_pass "SIP registration successful for ${EXT_NUM}"
        kill $PJSUA_PID 2>/dev/null || true
        return 0
    else
        print_fail "SIP registration failed for ${EXT_NUM}"
        print_info "Check log: /tmp/pjsua-${EXT_NUM}.log"
        kill $PJSUA_PID 2>/dev/null || true
        return 1
    fi
}

# Test extension to extension call
test_extension_call() {
    local FROM_EXT=$1
    local FROM_PASS=$2
    local TO_EXT=$3
    local TO_PASS=$4
    
    print_test "Testing call from ${FROM_EXT} to ${TO_EXT}"
    
    # Start receiver (TO_EXT)
    cat > /tmp/pjsua-${TO_EXT}.cfg <<EOF
--id sip:${TO_EXT}@127.0.0.1
--registrar sip:127.0.0.1
--realm *
--username ${TO_EXT}
--password ${TO_PASS}
--auto-answer 200
--duration 15
--null-audio
EOF
    
    timeout 20 pjsua --config-file /tmp/pjsua-${TO_EXT}.cfg > /tmp/pjsua-${TO_EXT}-call.log 2>&1 &
    RECEIVER_PID=$!
    
    sleep 3
    
    # Start caller (FROM_EXT)
    cat > /tmp/pjsua-${FROM_EXT}.cfg <<EOF
--id sip:${FROM_EXT}@127.0.0.1
--registrar sip:127.0.0.1
--realm *
--username ${FROM_EXT}
--password ${FROM_PASS}
--duration 10
--null-audio
sip:${TO_EXT}@127.0.0.1
EOF
    
    timeout 15 pjsua --config-file /tmp/pjsua-${FROM_EXT}.cfg > /tmp/pjsua-${FROM_EXT}-call.log 2>&1 &
    CALLER_PID=$!
    
    sleep 8
    
    # Check if call was established
    if grep -q "Call.*CONFIRMED" /tmp/pjsua-${FROM_EXT}-call.log || grep -q "Call.*is EARLY" /tmp/pjsua-${FROM_EXT}-call.log; then
        print_pass "Call established from ${FROM_EXT} to ${TO_EXT}"
        CALL_SUCCESS=true
    else
        print_fail "Call from ${FROM_EXT} to ${TO_EXT} failed"
        print_info "Caller log: /tmp/pjsua-${FROM_EXT}-call.log"
        print_info "Receiver log: /tmp/pjsua-${TO_EXT}-call.log"
        CALL_SUCCESS=false
    fi
    
    # Cleanup
    kill $CALLER_PID 2>/dev/null || true
    kill $RECEIVER_PID 2>/dev/null || true
    
    if [ "$CALL_SUCCESS" = true ]; then
        return 0
    else
        return 1
    fi
}

# Test codec negotiation
test_codec_negotiation() {
    print_test "Testing codec negotiation"
    
    # Check active channels and their codecs
    CHANNELS=$(asterisk -rx "core show channels concise" 2>/dev/null)
    
    if [ -n "$CHANNELS" ]; then
        print_pass "Can query channels"
        
        # Try to get codec info for first channel
        FIRST_CHANNEL=$(echo "$CHANNELS" | head -1 | cut -d'!' -f1)
        if [ -n "$FIRST_CHANNEL" ]; then
            CODEC_INFO=$(asterisk -rx "core show channel ${FIRST_CHANNEL}" 2>/dev/null | grep -i "codec")
            if [ -n "$CODEC_INFO" ]; then
                print_pass "Codec information available"
                print_info "Sample: $CODEC_INFO"
            fi
        fi
    else
        print_info "No active channels to test"
    fi
    
    return 0
}

# Test API endpoints
test_api_endpoints() {
    print_test "Testing RayanPBX API endpoints"
    
    # Test health endpoint
    if curl -sf http://localhost:8000/api/health > /dev/null 2>&1; then
        print_pass "API health endpoint responding"
    else
        print_fail "API health endpoint not responding"
        return 1
    fi
    
    # Test extensions endpoint (requires auth)
    print_info "Note: Full API testing requires authentication"
    
    return 0
}

# Cleanup test extensions
# Uses section-based removal instead of managed comment blocks
cleanup_test_extensions() {
    print_test "Cleaning up test extensions"
    
    for EXT in 9001 9002; do
        # Remove extension sections from pjsip.conf
        remove_extension_sections "$EXT"
    done
    
    asterisk -rx "pjsip reload" > /dev/null 2>&1
    
    print_pass "Test extensions cleaned up"
}

# Print summary
print_summary() {
    echo -e "\n${CYAN}${BOLD}"
    echo "╔════════════════════════════════════════════════════════════╗"
    echo "║                      Test Summary                          ║"
    echo "╚════════════════════════════════════════════════════════════╝"
    echo -e "${RESET}"
    
    echo -e "${GREEN}Passed: $PASS_COUNT${RESET}"
    echo -e "${RED}Failed: $FAIL_COUNT${RESET}"
    echo -e "Total:  $TEST_COUNT\n"
    
    if [ $FAIL_COUNT -eq 0 ]; then
        echo -e "${GREEN}${BOLD}🎉 All tests passed! RayanPBX is working correctly! 🎉${RESET}\n"
        return 0
    else
        echo -e "${RED}${BOLD}⚠️  Some tests failed. Please review the output above. ⚠️${RESET}\n"
        return 1
    fi
}

# Main execution
main() {
    print_header
    
    # Pre-flight checks
    check_pjsua || exit 1
    test_asterisk_running || exit 1
    test_asterisk_version || exit 1
    test_pjsip_config || exit 1
    test_database || exit 1
    
    # Create test extensions
    create_test_extension "9001" "test1234" || exit 1
    create_test_extension "9002" "test5678" || exit 1
    
    # Test SIP functionality
    test_sip_registration "9001" "test1234" || true
    test_sip_registration "9002" "test5678" || true
    
    # Test call between extensions
    test_extension_call "9001" "test1234" "9002" "test5678" || true
    
    # Test codec negotiation
    test_codec_negotiation || true
    
    # Test API
    test_api_endpoints || true
    
    # Cleanup
    cleanup_test_extensions
    
    # Print summary
    print_summary
}

# Run if executed directly
if [ "${BASH_SOURCE[0]}" == "${0}" ]; then
    main "$@"
fi
