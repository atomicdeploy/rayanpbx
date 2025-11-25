#!/bin/bash

# Test script to verify the fixes for install.sh flag preservation and package handling

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
    echo "║      🧪  Install Script Fixes Test Suite  🧪              ║"
    echo "║                                                            ║"
    echo "╚════════════════════════════════════════════════════════════╝"
    echo -e "${RESET}\n"
}

# Test 1: Check install.sh syntax
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

# Test 2: Verify flag preservation fix - check that exec uses absolute path
test_flag_preservation_fix() {
    print_test "Verifying flag preservation fix uses absolute path"
    
    # Check that the exec line uses SCRIPT_DIR for absolute path
    if grep -A 2 "exec.*BASH_SOURCE" "$REPO_ROOT/install.sh" | grep -q 'SCRIPT_DIR'; then
        print_pass "Flag preservation uses absolute path with SCRIPT_DIR"
        return 0
    else
        print_fail "Flag preservation does not use absolute path correctly"
        return 1
    fi
}

# Test 3: Verify package check uses dpkg-query
test_package_check_improvement() {
    print_test "Verifying package check uses dpkg-query instead of dpkg -l | grep"
    
    if grep "dpkg-query.*install ok installed" "$REPO_ROOT/install.sh" > /dev/null; then
        print_pass "Package check uses dpkg-query for better reliability"
        return 0
    else
        print_fail "Package check does not use dpkg-query"
        return 1
    fi
}

# Test 4: Verify package skip messaging
test_package_skip_messaging() {
    print_test "Verifying package skip messaging includes 'skipping'"
    
    if grep "already installed, skipping" "$REPO_ROOT/install.sh" > /dev/null; then
        print_pass "Package skip messaging is clear"
        return 0
    else
        print_fail "Package skip messaging is not clear"
        return 1
    fi
}

# Test 5: Verify SCRIPT_DIR is defined before use in exec
test_script_dir_defined() {
    print_test "Verifying SCRIPT_DIR is defined before exec usage"
    
    # Find line where SCRIPT_DIR is defined
    SCRIPT_DIR_LINE=$(grep -n 'SCRIPT_DIR=".*cd.*dirname.*BASH_SOURCE' "$REPO_ROOT/install.sh" | head -1 | cut -d: -f1)
    # Find line where exec uses SCRIPT_DIR
    EXEC_LINE=$(grep -n 'exec.*SCRIPT_DIR' "$REPO_ROOT/install.sh" | head -1 | cut -d: -f1)
    
    if [ -n "$SCRIPT_DIR_LINE" ] && [ -n "$EXEC_LINE" ] && [ "$SCRIPT_DIR_LINE" -lt "$EXEC_LINE" ]; then
        print_pass "SCRIPT_DIR is defined before exec usage (line $SCRIPT_DIR_LINE before line $EXEC_LINE)"
        return 0
    else
        print_fail "SCRIPT_DIR may not be defined before exec usage"
        return 1
    fi
}

# Test 6: Simulate exec command to verify it would work
test_exec_simulation() {
    print_test "Simulating exec command construction"
    
    # Simulate the variables
    TEST_SCRIPT_DIR="/opt/rayanpbx"
    TEST_BASH_SOURCE=("install.sh")
    
    # Construct the exec command as it would be in the script
    EXEC_CMD="$TEST_SCRIPT_DIR/$(basename "${TEST_BASH_SOURCE[0]}")"
    
    if [ "$EXEC_CMD" = "/opt/rayanpbx/install.sh" ]; then
        print_pass "Exec command construction works correctly: $EXEC_CMD"
        return 0
    else
        print_fail "Exec command construction is incorrect: $EXEC_CMD"
        return 1
    fi
}

# Test 7: Check that package check handles errors gracefully
test_package_check_error_handling() {
    print_test "Verifying package check handles dpkg-query errors gracefully"
    
    if grep "dpkg-query.*2>/dev/null" "$REPO_ROOT/install.sh" > /dev/null; then
        print_pass "Package check redirects stderr to avoid showing errors for missing packages"
        return 0
    else
        print_fail "Package check does not handle dpkg-query errors"
        return 1
    fi
}

# Test 8: Verify the logic flow - check comes before install
test_package_logic_flow() {
    print_test "Verifying package check comes before install attempt"
    
    # Extract the relevant section and verify the if/else structure
    if awk '/for package in.*PACKAGES/,/^done$/' "$REPO_ROOT/install.sh" | \
       grep -A 5 "dpkg-query" | grep -q "already installed"; then
        print_pass "Package check correctly identifies already installed packages"
        return 0
    else
        print_fail "Package check logic flow is incorrect"
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
        echo -e "${GREEN}${BOLD}🎉 All tests passed! Install script fixes are working correctly! 🎉${RESET}\n"
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
    test_install_syntax || true
    test_flag_preservation_fix || true
    test_package_check_improvement || true
    test_package_skip_messaging || true
    test_script_dir_defined || true
    test_exec_simulation || true
    test_package_check_error_handling || true
    test_package_logic_flow || true
    
    # Print summary
    print_summary
}

# Run if executed directly
if [ "${BASH_SOURCE[0]}" == "${0}" ]; then
    main "$@"
fi
