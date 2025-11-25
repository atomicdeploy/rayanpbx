#!/bin/bash

# Test script to verify the fixes for ini-helper.sh and install.sh

set -e

# Colors
readonly GREEN='\033[0;32m'
readonly RED='\033[0;31m'
readonly YELLOW='\033[1;33m'
readonly CYAN='\033[0;36m'
readonly BOLD='\033[1m'
readonly RESET='\033[0m'

TEST_COUNT=0
PASS_COUNT=0
FAIL_COUNT=0

# Get script directory
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

print_test() {
    ((TEST_COUNT++))
    echo -e "${CYAN}[TEST $TEST_COUNT]${RESET} $1"
}

print_pass() {
    ((PASS_COUNT++))
    echo -e "${GREEN}✅ PASS${RESET}: $1"
}

print_fail() {
    ((FAIL_COUNT++))
    echo -e "${RED}❌ FAIL${RESET}: $1"
}

print_header() {
    echo -e "${CYAN}${BOLD}"
    echo "╔════════════════════════════════════════════════════════════╗"
    echo "║                                                            ║"
    echo "║        🧪  Script Fixes Validation Test Suite  🧪         ║"
    echo "║                                                            ║"
    echo "╚════════════════════════════════════════════════════════════╝"
    echo -e "${RESET}\n"
}

# Test 1: Check ini-helper.sh syntax
test_ini_helper_syntax() {
    print_test "Checking ini-helper.sh syntax"
    
    if bash -n "$REPO_ROOT/scripts/ini-helper.sh" 2>&1; then
        print_pass "ini-helper.sh has valid syntax"
        return 0
    else
        print_fail "ini-helper.sh has syntax errors"
        return 1
    fi
}

# Test 2: Check that line 100 uses string comparison
test_ini_helper_comparison() {
    print_test "Verifying BASH_SOURCE check uses string comparison (=) not integer comparison (-eq)"
    
    # Check for the correct pattern without depending on line number
    if grep 'BASH_SOURCE.*=.*"${0}"' "$REPO_ROOT/scripts/ini-helper.sh" | grep -v -q '\-eq'; then
        print_pass "BASH_SOURCE check correctly uses string comparison (=)"
        return 0
    else
        print_fail "BASH_SOURCE check does not use correct string comparison"
        return 1
    fi
}

# Test 3: Source ini-helper.sh without errors
test_ini_helper_source() {
    print_test "Sourcing ini-helper.sh script"
    
    if bash -c "source '$REPO_ROOT/scripts/ini-helper.sh' && echo 'Success'" > /dev/null 2>&1; then
        print_pass "ini-helper.sh can be sourced without errors"
        return 0
    else
        print_fail "Failed to source ini-helper.sh"
        return 1
    fi
}

# Test 4: Run ini-helper.sh directly
test_ini_helper_direct() {
    print_test "Running ini-helper.sh directly (should show usage)"
    
    OUTPUT=$("$REPO_ROOT/scripts/ini-helper.sh" 2>&1)
    
    if echo "$OUTPUT" | grep -q "Usage:"; then
        print_pass "ini-helper.sh runs directly and shows usage"
        return 0
    else
        print_fail "ini-helper.sh does not run correctly"
        return 1
    fi
}

# Test 5: Check install.sh syntax
test_install_syntax() {
    print_test "Checking install.sh syntax"
    
    if bash -n "$REPO_ROOT/install.sh" 2>&1; then
        print_pass "install.sh has valid syntax"
        return 0
    else
        print_fail "install.sh has syntax errors"
        return 1
    fi
}

# Test 6: Check handle_asterisk_error function exists
test_handle_asterisk_error_exists() {
    print_test "Checking if handle_asterisk_error function exists in install.sh"
    
    if grep -q "handle_asterisk_error()" "$REPO_ROOT/install.sh"; then
        print_pass "handle_asterisk_error function exists"
        return 0
    else
        print_fail "handle_asterisk_error function not found"
        return 1
    fi
}

# Test 7: Check pollinations.ai integration
test_pollinations_integration() {
    print_test "Checking pollinations.ai integration in error handling"
    
    if grep -q "pollinations.ai" "$REPO_ROOT/install.sh"; then
        print_pass "pollinations.ai integration found"
        return 0
    else
        print_fail "pollinations.ai integration not found"
        return 1
    fi
}

# Test 8: Check Asterisk console guidance
test_asterisk_console_guidance() {
    print_test "Checking Asterisk console guidance in final output"
    
    if grep -q "asterisk -rvvv" "$REPO_ROOT/install.sh"; then
        print_pass "Asterisk console command (asterisk -rvvv) found in guidance"
        return 0
    else
        print_fail "Asterisk console command not found in guidance"
        return 1
    fi
}

# Test 9: Check enhanced error handling for Asterisk restart
test_asterisk_restart_handling() {
    print_test "Checking enhanced error handling for Asterisk restart"
    
    if grep -A 5 "systemctl restart asterisk" "$REPO_ROOT/install.sh" | grep -q "handle_asterisk_error"; then
        print_pass "Enhanced error handling for Asterisk restart found"
        return 0
    else
        print_fail "Enhanced error handling for Asterisk restart not found"
        return 1
    fi
}

# Test 10: Check enhanced error handling for Asterisk reload
test_asterisk_reload_handling() {
    print_test "Checking enhanced error handling for Asterisk reload"
    
    if grep -A 10 "systemctl reload asterisk" "$REPO_ROOT/install.sh" | grep -q "handle_asterisk_error"; then
        print_pass "Enhanced error handling for Asterisk reload found"
        return 0
    else
        print_fail "Enhanced error handling for Asterisk reload not found"
        return 1
    fi
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
        echo -e "${GREEN}${BOLD}🎉 All tests passed! Script fixes are working correctly! 🎉${RESET}\n"
        return 0
    else
        echo -e "${RED}${BOLD}⚠️  Some tests failed. Please review the output above. ⚠️${RESET}\n"
        return 1
    fi
}

# Main execution
main() {
    print_header
    
    # Run all tests
    test_ini_helper_syntax || true
    test_ini_helper_comparison || true
    test_ini_helper_source || true
    test_ini_helper_direct || true
    test_install_syntax || true
    test_handle_asterisk_error_exists || true
    test_pollinations_integration || true
    test_asterisk_console_guidance || true
    test_asterisk_restart_handling || true
    test_asterisk_reload_handling || true
    
    # Print summary
    print_summary
}

# Run if executed directly
if [ "${BASH_SOURCE[0]}" == "${0}" ]; then
    main "$@"
fi
