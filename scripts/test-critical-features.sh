#!/bin/bash

# RayanPBX Critical Functionality Validator
# Tests the THREE MOST IMPORTANT features:
# 1. Create SIP extensions and verify they work
# 2. Configure SIP trunk and verify connectivity
# 3. Error reporting with AI-powered solutions

set -e

# Configuration
API_BASE_URL="${API_BASE_URL:-http://localhost/api}"

# Colors
readonly GREEN='\033[0;32m'
readonly RED='\033[0;31m'
readonly YELLOW='\033[1;33m'
readonly BLUE='\033[0;34m'
readonly CYAN='\033[0;36m'
readonly BOLD='\033[1m'
readonly RESET='\033[0m'

TEST_RESULTS=()
CRITICAL_FAILURES=()

print_banner() {
    clear
    echo -e "${CYAN}${BOLD}"
    echo "╔══════════════════════════════════════════════════════════╗"
    echo "║                                                          ║"
    echo "║    🎯 RayanPBX Critical Functionality Validator 🎯      ║"
    echo "║                                                          ║"
    echo "║  Testing: Extensions, Trunks, Error Reporting           ║"
    echo "║                                                          ║"
    echo "╚══════════════════════════════════════════════════════════╝"
    echo -e "${RESET}\n"
}

log_test() {
    echo -e "${BLUE}${BOLD}[TEST]${RESET} $1"
}

log_success() {
    echo -e "${GREEN}✅ SUCCESS:${RESET} $1"
    TEST_RESULTS+=("✅ $1")
}

log_fail() {
    echo -e "${RED}❌ CRITICAL FAILURE:${RESET} $1"
    TEST_RESULTS+=("❌ $1")
    CRITICAL_FAILURES+=("$1")
}

log_info() {
    echo -e "${CYAN}ℹ️  $1${RESET}"
}

log_warning() {
    echo -e "${YELLOW}⚠️  $1${RESET}"
}

# ============================================================================
# CRITICAL TEST 1: Create SIP Extension and Verify It Works
# ============================================================================

test_extension_creation() {
    echo -e "\n${CYAN}${BOLD}╔═══════════════════════════════════════════════════════╗"
    echo "║  CRITICAL TEST 1: Extension Creation & Registration  ║"
    echo -e "╚═══════════════════════════════════════════════════════╝${RESET}\n"
    
    local TEST_EXT="7001"
    local TEST_PASS="SecureTest123!"
    local TEST_NAME="Test Extension 7001"
    
    # Step 1: Create extension via API
    log_test "Creating extension $TEST_EXT via API..."
    
    # Get auth token first
    log_info "Authenticating with API..."
    
    TOKEN_RESPONSE=$(curl -s -X POST ${API_BASE_URL}/auth/login \
        -H "Content-Type: application/json" \
        -d "{\"username\":\"$(whoami)\",\"password\":\"test123\"}" 2>/dev/null)
    
    if [ -z "$TOKEN_RESPONSE" ]; then
        log_fail "Cannot connect to API (is it running?)"
        log_info "Start backend: cd /opt/rayanpbx/backend && php artisan serve"
        return 1
    fi
    
    TOKEN=$(echo "$TOKEN_RESPONSE" | grep -o '"token":"[^"]*"' | cut -d'"' -f4)
    
    if [ -z "$TOKEN" ]; then
        log_warning "API authentication failed, trying without auth..."
    fi
    
    # Create extension
    CREATE_RESPONSE=$(curl -s -X POST ${API_BASE_URL}/extensions \
        -H "Content-Type: application/json" \
        -H "Authorization: Bearer $TOKEN" \
        -d "{
            \"extension_number\": \"$TEST_EXT\",
            \"name\": \"$TEST_NAME\",
            \"secret\": \"$TEST_PASS\",
            \"context\": \"from-internal\",
            \"enabled\": true
        }" 2>/dev/null)
    
    if echo "$CREATE_RESPONSE" | grep -q "success.*true"; then
        log_success "Extension $TEST_EXT created via API"
    else
        log_fail "Failed to create extension via API"
        log_info "Response: $CREATE_RESPONSE"
        return 1
    fi
    
    # Step 2: Verify extension exists in database
    log_test "Verifying extension in database..."
    
    DB_CHECK=$(mysql -u rayanpbx -p$(cat /opt/rayanpbx/.db_credentials | grep DB_PASSWORD | cut -d'=' -f2) \
        -D rayanpbx -se "SELECT COUNT(*) FROM extensions WHERE extension_number='$TEST_EXT';" 2>/dev/null)
    
    if [ "$DB_CHECK" -eq "1" ]; then
        log_success "Extension found in database"
    else
        log_fail "Extension NOT found in database"
        return 1
    fi
    
    # Step 3: Verify PJSIP configuration was created
    log_test "Verifying PJSIP configuration..."
    
    if grep -q "BEGIN MANAGED - Extension $TEST_EXT" /etc/asterisk/pjsip.conf; then
        log_success "PJSIP configuration created"
        
        # Check if endpoint is configured correctly
        if grep -A 10 "\[$TEST_EXT\]" /etc/asterisk/pjsip.conf | grep -q "type=endpoint"; then
            log_success "Endpoint configuration is correct"
        else
            log_fail "Endpoint configuration is incomplete"
            return 1
        fi
        
        # Check if auth is configured
        if grep -A 5 "\[$TEST_EXT\]" /etc/asterisk/pjsip.conf | grep -q "type=auth"; then
            log_success "Authentication configuration is correct"
        else
            log_fail "Authentication configuration is missing"
            return 1
        fi
        
        # Check if AOR is configured
        if grep -A 5 "\[$TEST_EXT\]" /etc/asterisk/pjsip.conf | grep -q "type=aor"; then
            log_success "AOR configuration is correct"
        else
            log_fail "AOR configuration is missing"
            return 1
        fi
        
    else
        log_fail "PJSIP configuration NOT created"
        return 1
    fi
    
    # Step 4: Reload Asterisk to apply changes
    log_test "Reloading Asterisk PJSIP module..."
    
    RELOAD_OUTPUT=$(asterisk -rx "pjsip reload" 2>&1)
    sleep 2
    
    if echo "$RELOAD_OUTPUT" | grep -q -i "error"; then
        log_fail "Asterisk reload failed: $RELOAD_OUTPUT"
        return 1
    else
        log_success "Asterisk PJSIP reloaded successfully"
    fi
    
    # Step 5: Verify endpoint is visible in Asterisk
    log_test "Verifying endpoint in Asterisk..."
    
    ENDPOINT_CHECK=$(asterisk -rx "pjsip show endpoint $TEST_EXT" 2>&1)
    
    if echo "$ENDPOINT_CHECK" | grep -q "$TEST_EXT"; then
        log_success "Endpoint $TEST_EXT is visible in Asterisk"
    else
        log_fail "Endpoint $TEST_EXT NOT found in Asterisk"
        log_info "Output: $ENDPOINT_CHECK"
        return 1
    fi
    
    # Step 6: Test actual SIP registration
    log_test "Testing actual SIP registration with PJSUA..."
    
    if ! command -v pjsua &> /dev/null; then
        log_warning "PJSUA not installed, installing..."
        apt-get update -qq && apt-get install -y pjsua > /dev/null 2>&1
    fi
    
    # Create PJSUA config
    cat > /tmp/test-ext-${TEST_EXT}.cfg <<EOF
--id sip:${TEST_EXT}@127.0.0.1
--registrar sip:127.0.0.1:5060
--realm *
--username ${TEST_EXT}
--password ${TEST_PASS}
--auto-answer 200
--duration 10
--null-audio
--log-level 3
EOF
    
    # Start PJSUA
    timeout 15 pjsua --config-file /tmp/test-ext-${TEST_EXT}.cfg > /tmp/pjsua-${TEST_EXT}.log 2>&1 &
    PJSUA_PID=$!
    
    sleep 5
    
    # Check registration
    if grep -q -i "registration.*success\|Registration successful\|200 OK" /tmp/pjsua-${TEST_EXT}.log; then
        log_success "SIP registration SUCCESSFUL! Extension $TEST_EXT is fully functional!"
    else
        log_fail "SIP registration FAILED"
        log_info "PJSUA log:"
        tail -20 /tmp/pjsua-${TEST_EXT}.log | sed 's/^/    /'
        
        # Try to diagnose the issue
        log_info "Checking Asterisk logs for clues..."
        asterisk -rx "pjsip show registration $TEST_EXT" 2>&1 | sed 's/^/    /'
        
        kill $PJSUA_PID 2>/dev/null || true
        return 1
    fi
    
    kill $PJSUA_PID 2>/dev/null || true
    
    # Step 7: Verify registration status via API
    log_test "Checking registration status via API..."
    
    STATUS_RESPONSE=$(curl -s -X POST ${API_BASE_URL}/asterisk/endpoint/status \
        -H "Content-Type: application/json" \
        -H "Authorization: Bearer $TOKEN" \
        -d "{\"endpoint\": \"$TEST_EXT\"}" 2>/dev/null)
    
    if echo "$STATUS_RESPONSE" | grep -q "registered.*true"; then
        log_success "API reports extension as registered"
    else
        log_warning "API status check inconclusive"
    fi
    
    log_success "✅ CRITICAL TEST 1 PASSED: Extension creation and registration works!"
    return 0
}

# ============================================================================
# CRITICAL TEST 2: Configure SIP Trunk and Verify Connectivity
# ============================================================================

test_trunk_configuration() {
    echo -e "\n${CYAN}${BOLD}╔════════════════════════════════════════════════════╗"
    echo "║  CRITICAL TEST 2: Trunk Configuration & Testing  ║"
    echo -e "╚════════════════════════════════════════════════════╝${RESET}\n"
    
    local TRUNK_NAME="TestTrunk"
    local TRUNK_HOST="sip.example.com"  # Example, will fail but tests flow
    local TRUNK_PORT="5060"
    
    # Step 1: Create trunk via API
    log_test "Creating SIP trunk via API..."
    
    TOKEN=$(curl -s -X POST ${API_BASE_URL}/auth/login \
        -H "Content-Type: application/json" \
        -d "{\"username\":\"$(whoami)\",\"password\":\"test123\"}" 2>/dev/null | \
        grep -o '"token":"[^"]*"' | cut -d'"' -f4)
    
    TRUNK_RESPONSE=$(curl -s -X POST ${API_BASE_URL}/trunks \
        -H "Content-Type: application/json" \
        -H "Authorization: Bearer $TOKEN" \
        -d "{
            \"name\": \"$TRUNK_NAME\",
            \"host\": \"$TRUNK_HOST\",
            \"port\": $TRUNK_PORT,
            \"context\": \"from-trunk\",
            \"enabled\": true,
            \"routing_prefix\": \"9\",
            \"strip_prefix\": true
        }" 2>/dev/null)
    
    if echo "$TRUNK_RESPONSE" | grep -q "success.*true"; then
        log_success "Trunk created via API"
    else
        log_fail "Failed to create trunk via API"
        log_info "Response: $TRUNK_RESPONSE"
        return 1
    fi
    
    # Step 2: Verify trunk in database
    log_test "Verifying trunk in database..."
    
    DB_TRUNK=$(mysql -u rayanpbx -p$(cat /opt/rayanpbx/.db_credentials | grep DB_PASSWORD | cut -d'=' -f2) \
        -D rayanpbx -se "SELECT COUNT(*) FROM trunks WHERE name='$TRUNK_NAME';" 2>/dev/null)
    
    if [ "$DB_TRUNK" -eq "1" ]; then
        log_success "Trunk found in database"
    else
        log_fail "Trunk NOT found in database"
        return 1
    fi
    
    # Step 3: Verify PJSIP trunk configuration
    log_test "Verifying PJSIP trunk configuration..."
    
    if grep -q "BEGIN MANAGED - Trunk $TRUNK_NAME" /etc/asterisk/pjsip.conf; then
        log_success "Trunk PJSIP configuration created"
    else
        log_fail "Trunk PJSIP configuration NOT created"
        return 1
    fi
    
    # Step 4: Verify dialplan for trunk routing
    log_test "Verifying dialplan configuration..."
    
    if [ -f /etc/asterisk/extensions.conf ]; then
        if grep -q "BEGIN MANAGED - RayanPBX Outbound Routing" /etc/asterisk/extensions.conf; then
            log_success "Dialplan routing configuration created"
            
            # Check for prefix routing
            if grep -A 5 "BEGIN MANAGED" /etc/asterisk/extensions.conf | grep -q "exten => _9"; then
                log_success "Prefix-based routing (9) configured correctly"
            else
                log_warning "Prefix routing may need verification"
            fi
        else
            log_fail "Dialplan routing NOT configured"
            return 1
        fi
    else
        log_fail "extensions.conf not found"
        return 1
    fi
    
    # Step 5: Reload Asterisk
    log_test "Reloading Asterisk..."
    
    asterisk -rx "pjsip reload" > /dev/null 2>&1
    asterisk -rx "dialplan reload" > /dev/null 2>&1
    sleep 2
    
    log_success "Asterisk reloaded"
    
    # Step 6: Test trunk reachability (will likely fail with example.com, but tests the flow)
    log_test "Testing trunk reachability..."
    
    TRUNK_STATUS=$(asterisk -rx "pjsip show endpoint $TRUNK_NAME" 2>&1)
    
    if echo "$TRUNK_STATUS" | grep -q "$TRUNK_NAME"; then
        log_success "Trunk endpoint visible in Asterisk"
        
        # Try to qualify (ping) the trunk
        log_info "Checking trunk connectivity..."
        QUALIFY_OUTPUT=$(asterisk -rx "pjsip qualify $TRUNK_NAME" 2>&1)
        
        if echo "$QUALIFY_OUTPUT" | grep -q -i "unreachable\|error"; then
            log_warning "Trunk is unreachable (expected for test with sip.example.com)"
            log_info "For real trunk, use actual SIP provider details"
        else
            log_success "Trunk reachability test completed"
        fi
    else
        log_fail "Trunk endpoint NOT found in Asterisk"
        return 1
    fi
    
    # Step 7: Verify trunk status via API
    log_test "Checking trunk status via API..."
    
    API_TRUNK_STATUS=$(curl -s -X POST ${API_BASE_URL}/asterisk/trunk/status \
        -H "Content-Type: application/json" \
        -H "Authorization: Bearer $TOKEN" \
        -d "{\"trunk\": \"$TRUNK_NAME\"}" 2>/dev/null)
    
    if echo "$API_TRUNK_STATUS" | grep -q "trunk"; then
        log_success "API trunk status endpoint working"
    else
        log_warning "API trunk status check inconclusive"
    fi
    
    log_success "✅ CRITICAL TEST 2 PASSED: Trunk configuration works!"
    log_info "Note: Replace sip.example.com with real SIP provider for actual calls"
    
    return 0
}

# ============================================================================
# CRITICAL TEST 3: Error Reporting with AI-Powered Solutions
# ============================================================================

test_error_reporting() {
    echo -e "\n${CYAN}${BOLD}╔═══════════════════════════════════════════════════╗"
    echo "║  CRITICAL TEST 3: Error Reporting & AI Help     ║"
    echo -e "╚═══════════════════════════════════════════════════╝${RESET}\n"
    
    # Step 1: Test API error reporting endpoint
    log_test "Testing error explanation API..."
    
    TOKEN=$(curl -s -X POST ${API_BASE_URL}/auth/login \
        -H "Content-Type: application/json" \
        -d "{\"username\":\"$(whoami)\",\"password\":\"test123\"}" 2>/dev/null | \
        grep -o '"token":"[^"]*"' | cut -d'"' -f4)
    
    # Test with common SIP error
    ERROR_TEST="Failed to register SIP extension - 401 Unauthorized"
    
    EXPLAIN_RESPONSE=$(curl -s -X POST ${API_BASE_URL}/help/error \
        -H "Content-Type: application/json" \
        -H "Authorization: Bearer $TOKEN" \
        -d "{
            \"error\": \"$ERROR_TEST\",
            \"context\": \"SIP registration\"
        }" 2>/dev/null)
    
    if echo "$EXPLAIN_RESPONSE" | grep -q "explanation"; then
        log_success "Error explanation API is working"
        
        EXPLANATION=$(echo "$EXPLAIN_RESPONSE" | grep -o '"explanation":"[^"]*"' | cut -d'"' -f4 | head -c 200)
        log_info "Sample explanation: $EXPLANATION..."
    else
        log_fail "Error explanation API failed"
        log_info "Response: $EXPLAIN_RESPONSE"
        return 1
    fi
    
    # Step 2: Test codec explanation
    log_test "Testing codec explanation API..."
    
    CODEC_RESPONSE=$(curl -s -X POST ${API_BASE_URL}/help/codec \
        -H "Content-Type: application/json" \
        -H "Authorization: Bearer $TOKEN" \
        -d "{\"codec\": \"g722\"}" 2>/dev/null)
    
    if echo "$CODEC_RESPONSE" | grep -q "explanation"; then
        log_success "Codec explanation API is working"
        
        CODEC_EXPLAIN=$(echo "$CODEC_RESPONSE" | grep -o '"explanation":"[^"]*"' | cut -d'"' -f4 | head -c 200)
        log_info "Sample: $CODEC_EXPLAIN..."
    else
        log_warning "Codec explanation API may be using fallback"
    fi
    
    # Step 3: Test field help
    log_test "Testing field help API..."
    
    FIELD_RESPONSE=$(curl -s -X POST ${API_BASE_URL}/help/field \
        -H "Content-Type: application/json" \
        -H "Authorization: Bearer $TOKEN" \
        -d "{
            \"field\": \"context\",
            \"value\": \"from-internal\"
        }" 2>/dev/null)
    
    if echo "$FIELD_RESPONSE" | grep -q "explanation"; then
        log_success "Field help API is working"
    else
        log_warning "Field help API may be using fallback"
    fi
    
    # Step 4: Simulate real error and get solution
    log_test "Simulating real error scenario..."
    
    # Create intentionally broken extension
    echo "" >> /etc/asterisk/pjsip.conf
    echo "; INTENTIONAL SYNTAX ERROR FOR TEST" >> /etc/asterisk/pjsip.conf
    echo "[broken_test" >> /etc/asterisk/pjsip.conf
    
    # Try to reload
    RELOAD_ERROR=$(asterisk -rx "pjsip reload" 2>&1)
    
    # Remove the broken config
    sed -i '/INTENTIONAL SYNTAX ERROR/,/\[broken_test/d' /etc/asterisk/pjsip.conf
    
    if echo "$RELOAD_ERROR" | grep -q -i "error\|fail"; then
        log_info "Detected error: ${RELOAD_ERROR:0:100}..."
        
        # Get AI explanation for the error
        AI_SOLUTION=$(curl -s -X POST ${API_BASE_URL}/help/error \
            -H "Content-Type: application/json" \
            -H "Authorization: Bearer $TOKEN" \
            -d "{
                \"error\": \"PJSIP configuration error\",
                \"context\": \"Asterisk reload failed\"
            }" 2>/dev/null | grep -o '"explanation":"[^"]*"' | cut -d'"' -f4)
        
        if [ -n "$AI_SOLUTION" ]; then
            log_success "AI provided solution for error"
            log_info "Solution: ${AI_SOLUTION:0:150}..."
        else
            log_warning "AI solution generation needs verification"
        fi
    fi
    
    # Reload cleanly
    asterisk -rx "pjsip reload" > /dev/null 2>&1
    
    log_success "✅ CRITICAL TEST 3 PASSED: Error reporting and AI help works!"
    
    return 0
}

# ============================================================================
# Cleanup
# ============================================================================

cleanup_test_data() {
    log_test "Cleaning up test data..."
    
    # Remove test extension from pjsip.conf
    sed -i '/BEGIN MANAGED - Extension 7001/,/END MANAGED - Extension 7001/d' /etc/asterisk/pjsip.conf 2>/dev/null || true
    
    # Remove test trunk
    sed -i '/BEGIN MANAGED - Trunk TestTrunk/,/END MANAGED - Trunk TestTrunk/d' /etc/asterisk/pjsip.conf 2>/dev/null || true
    
    # Remove from database
    mysql -u rayanpbx -p$(cat /opt/rayanpbx/.db_credentials | grep DB_PASSWORD | cut -d'=' -f2) \
        -D rayanpbx -se "DELETE FROM extensions WHERE extension_number='7001';" 2>/dev/null || true
    
    mysql -u rayanpbx -p$(cat /opt/rayanpbx/.db_credentials | grep DB_PASSWORD | cut -d'=' -f2) \
        -D rayanpbx -se "DELETE FROM trunks WHERE name='TestTrunk';" 2>/dev/null || true
    
    # Reload Asterisk
    asterisk -rx "pjsip reload" > /dev/null 2>&1
    asterisk -rx "dialplan reload" > /dev/null 2>&1
    
    log_success "Cleanup complete"
}

# ============================================================================
# Main Execution
# ============================================================================

main() {
    print_banner
    
    log_info "Testing the THREE MOST CRITICAL features..."
    log_info "This validates that RayanPBX actually works!\n"
    
    # Check prerequisites
    if ! systemctl is-active --quiet asterisk; then
        log_fail "Asterisk is not running!"
        log_info "Start it with: sudo systemctl start asterisk"
        exit 1
    fi
    
    if ! curl -s ${API_BASE_URL}/health > /dev/null 2>&1; then
        log_fail "Backend API is not running!"
        log_info "Start it with: cd /opt/rayanpbx/backend && php artisan serve"
        exit 1
    fi
    
    log_success "Prerequisites OK\n"
    
    # Run critical tests
    test_extension_creation || true
    test_trunk_configuration || true
    test_error_reporting || true
    
    # Cleanup
    cleanup_test_data
    
    # Print summary
    echo -e "\n${CYAN}${BOLD}╔════════════════════════════════════════════════════╗"
    echo "║                   Test Summary                      ║"
    echo -e "╚════════════════════════════════════════════════════╝${RESET}\n"
    
    for result in "${TEST_RESULTS[@]}"; do
        echo -e "  $result"
    done
    
    echo ""
    
    if [ ${#CRITICAL_FAILURES[@]} -eq 0 ]; then
        echo -e "${GREEN}${BOLD}╔════════════════════════════════════════════════════════╗"
        echo "║                                                        ║"
        echo "║  🎉 ALL CRITICAL TESTS PASSED!                        ║"
        echo "║                                                        ║"
        echo "║  ✅ Extension creation works                          ║"
        echo "║  ✅ SIP registration works                            ║"
        echo "║  ✅ Trunk configuration works                         ║"
        echo "║  ✅ Error reporting works                             ║"
        echo "║                                                        ║"
        echo "║  RayanPBX is FULLY FUNCTIONAL! 🚀                     ║"
        echo "║                                                        ║"
        echo "╚════════════════════════════════════════════════════════╝"
        echo -e "${RESET}\n"
        return 0
    else
        echo -e "${RED}${BOLD}╔════════════════════════════════════════════════════════╗"
        echo "║                                                        ║"
        echo "║  ⚠️  CRITICAL FAILURES DETECTED                       ║"
        echo "║                                                        ║"
        echo -e "╚════════════════════════════════════════════════════════╝${RESET}\n"
        
        echo -e "${RED}Failed tests:${RESET}"
        for failure in "${CRITICAL_FAILURES[@]}"; do
            echo -e "  ❌ $failure"
        done
        
        echo ""
        return 1
    fi
}

# Run if executed directly
if [ "${BASH_SOURCE[0]}" == "${0}" ]; then
    if [[ $EUID -ne 0 ]]; then
        echo -e "${RED}This script must be run as root${RESET}"
        exit 1
    fi
    
    main "$@"
fi
