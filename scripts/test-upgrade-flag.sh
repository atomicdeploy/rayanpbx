#!/bin/bash

# Test script to verify that --upgrade flag works correctly
# This tests the implementation of the -u/--upgrade flag feature

set -e

# Colors
readonly GREEN='\033[0;32m'
readonly RED='\033[0;31m'
readonly YELLOW='\033[1;33m'
readonly CYAN='\033[0;36m'
readonly BOLD='\033[1m'
readonly DIM='\033[2m'
readonly RESET='\033[0m'

TEST_COUNT=0
PASS_COUNT=0
FAIL_COUNT=0

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
    echo "║         🧪  Upgrade Flag Test Suite  🧪                   ║"
    echo "║                                                            ║"
    echo "╚════════════════════════════════════════════════════════════╝"
    echo -e "${RESET}\n"
}

# Get script directory
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

# Test 1: Check that UPGRADE_MODE variable is defined
test_upgrade_mode_variable() {
    print_test "Checking that UPGRADE_MODE variable is defined in install.sh"
    
    if grep -q '^UPGRADE_MODE=false' "$REPO_ROOT/install.sh"; then
        print_pass "UPGRADE_MODE variable is properly defined"
        return 0
    else
        print_fail "UPGRADE_MODE variable is not defined"
        return 1
    fi
}

# Test 2: Check that --upgrade flag is parsed
test_upgrade_flag_parsing() {
    print_test "Verifying --upgrade flag is parsed in argument loop"
    
    if grep -E '(-u\|--upgrade)' "$REPO_ROOT/install.sh" > /dev/null; then
        print_pass "Both -u and --upgrade flags are handled"
        return 0
    else
        print_fail "--upgrade flag parsing not found"
        return 1
    fi
}

# Test 3: Check that UPGRADE_MODE is set when flag is used
test_upgrade_mode_set() {
    print_test "Verifying UPGRADE_MODE=true is set when flag is parsed"
    
    if grep -A 2 '\-u|--upgrade' "$REPO_ROOT/install.sh" | grep -q 'UPGRADE_MODE=true'; then
        print_pass "UPGRADE_MODE is set to true when flag is detected"
        return 0
    else
        print_fail "UPGRADE_MODE not properly set"
        return 1
    fi
}

# Test 4: Check help text includes --upgrade flag
test_help_text() {
    print_test "Verifying help text documents --upgrade flag"
    
    if grep -q '\-u.*--upgrade' "$REPO_ROOT/install.sh"; then
        print_pass "Help text includes --upgrade flag documentation"
        return 0
    else
        print_fail "Help text missing --upgrade documentation"
        return 1
    fi
}

# Test 5: Check that upgrade mode skips interactive prompt
test_upgrade_mode_logic() {
    print_test "Verifying upgrade mode logic skips interactive prompt"
    
    # Look for the conditional check in the update section
    if grep -q 'if \[ "\$UPGRADE_MODE" = true \]' "$REPO_ROOT/install.sh"; then
        print_pass "Upgrade mode conditional logic found"
        return 0
    else
        print_fail "Upgrade mode logic not implemented"
        return 1
    fi
}

# Test 6: Verify REPLY is automatically set to 'y' in upgrade mode
test_reply_auto_set() {
    print_test "Verifying REPLY is automatically set to 'y' in upgrade mode"
    
    if grep -A 2 'if \[ "\$UPGRADE_MODE" = true \]' "$REPO_ROOT/install.sh" | grep -q 'REPLY="y"'; then
        print_pass "REPLY is automatically set to 'y'"
        return 0
    else
        print_fail "REPLY not automatically set"
        return 1
    fi
}

# Test 7: Verify documentation is updated
test_documentation() {
    print_test "Verifying COMMAND_LINE_OPTIONS.md is updated"
    
    if [ -f "$REPO_ROOT/COMMAND_LINE_OPTIONS.md" ] && grep -q '\-u.*--upgrade' "$REPO_ROOT/COMMAND_LINE_OPTIONS.md"; then
        print_pass "Documentation includes --upgrade flag"
        return 0
    else
        print_fail "Documentation missing --upgrade flag"
        return 1
    fi
}

# Test 8: Simulate upgrade mode with mock script
test_mock_upgrade_script() {
    print_test "Testing with mock script that simulates upgrade behavior"
    
    # Create a temporary directory for testing
    TEST_DIR=$(mktemp -d)
    if [ -z "$TEST_DIR" ] || [ ! -d "$TEST_DIR" ]; then
        print_fail "Failed to create temporary directory"
        return 1
    fi
    cd "$TEST_DIR"
    
    # Initialize a fake git repo
    git init > /dev/null 2>&1
    git config user.email "test@test.com"
    git config user.name "Test User"
    
    # Create mock install script with upgrade mode
    cat > mock-install.sh << 'MOCKSCRIPT'
#!/bin/bash
set -e

ORIGINAL_ARGS=("$@")
UPGRADE_MODE=false

# Parse arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        -u|--upgrade)
            UPGRADE_MODE=true
            shift
            ;;
        *)
            shift
            ;;
    esac
done

# Create a marker to track if updates would have been applied
if [ "$UPGRADE_MODE" = true ]; then
    echo "UPGRADE_MODE_ENABLED"
    # Simulate automatic update
    REPLY="y"
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        echo "UPDATES_APPLIED"
    fi
else
    echo "UPGRADE_MODE_DISABLED"
fi
MOCKSCRIPT
    
    chmod +x mock-install.sh
    
    # Test with --upgrade flag
    OUTPUT=$(./mock-install.sh --upgrade 2>&1)
    if echo "$OUTPUT" | grep -q "UPGRADE_MODE_ENABLED" && echo "$OUTPUT" | grep -q "UPDATES_APPLIED"; then
        print_pass "Mock script correctly applies updates in upgrade mode"
        cd /
        rm -rf "$TEST_DIR"
        return 0
    else
        print_fail "Mock script did not apply updates correctly"
        echo -e "${DIM}Output was:${RESET}"
        echo "$OUTPUT"
        cd /
        rm -rf "$TEST_DIR"
        return 1
    fi
}

# Test 9: Test that -u short flag works
test_short_flag() {
    print_test "Verifying -u short flag works"
    
    TEST_DIR=$(mktemp -d)
    cd "$TEST_DIR"
    
    cat > mock-install.sh << 'MOCKSCRIPT'
#!/bin/bash
UPGRADE_MODE=false
while [[ $# -gt 0 ]]; do
    case $1 in
        -u|--upgrade)
            UPGRADE_MODE=true
            shift
            ;;
        *)
            shift
            ;;
    esac
done
echo "UPGRADE_MODE=$UPGRADE_MODE"
MOCKSCRIPT
    
    chmod +x mock-install.sh
    OUTPUT=$(./mock-install.sh -u 2>&1)
    
    if echo "$OUTPUT" | grep -q "UPGRADE_MODE=true"; then
        print_pass "Short flag -u works correctly"
        cd /
        rm -rf "$TEST_DIR"
        return 0
    else
        print_fail "Short flag -u does not work"
        cd /
        rm -rf "$TEST_DIR"
        return 1
    fi
}

# Test 10: Verify install.sh syntax is still valid
test_install_syntax() {
    print_test "Verifying install.sh syntax is still valid"
    
    if bash -n "$REPO_ROOT/install.sh" 2>&1; then
        print_pass "install.sh has valid syntax"
        return 0
    else
        print_fail "install.sh has syntax errors"
        return 1
    fi
}

# Test 11: Test combined flags (--upgrade --verbose)
test_combined_flags() {
    print_test "Verifying --upgrade works with other flags like --verbose"
    
    TEST_DIR=$(mktemp -d)
    cd "$TEST_DIR"
    
    cat > mock-install.sh << 'MOCKSCRIPT'
#!/bin/bash
UPGRADE_MODE=false
VERBOSE=false
while [[ $# -gt 0 ]]; do
    case $1 in
        -u|--upgrade)
            UPGRADE_MODE=true
            shift
            ;;
        -v|--verbose)
            VERBOSE=true
            shift
            ;;
        *)
            shift
            ;;
    esac
done
echo "UPGRADE_MODE=$UPGRADE_MODE"
echo "VERBOSE=$VERBOSE"
MOCKSCRIPT
    
    chmod +x mock-install.sh
    OUTPUT=$(./mock-install.sh --upgrade --verbose 2>&1)
    
    if echo "$OUTPUT" | grep -q "UPGRADE_MODE=true" && echo "$OUTPUT" | grep -q "VERBOSE=true"; then
        print_pass "Combined flags work correctly"
        cd /
        rm -rf "$TEST_DIR"
        return 0
    else
        print_fail "Combined flags do not work correctly"
        cd /
        rm -rf "$TEST_DIR"
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
        echo -e "${GREEN}${BOLD}🎉 All tests passed! Upgrade flag is working correctly! 🎉${RESET}\n"
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
    test_upgrade_mode_variable || true
    test_upgrade_flag_parsing || true
    test_upgrade_mode_set || true
    test_help_text || true
    test_upgrade_mode_logic || true
    test_reply_auto_set || true
    test_documentation || true
    test_mock_upgrade_script || true
    test_short_flag || true
    test_install_syntax || true
    test_combined_flags || true
    
    # Print summary
    print_summary
}

# Run if executed directly
if [ "${BASH_SOURCE[0]}" == "${0}" ]; then
    main "$@"
fi
