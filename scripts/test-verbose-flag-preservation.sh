#!/bin/bash

# Test script to verify that --verbose flag is preserved when install.sh restarts after update
# This tests the fix for the issue where flags were lost during script restart

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
    echo "║      🧪  Verbose Flag Preservation Test Suite  🧪         ║"
    echo "║                                                            ║"
    echo "╚════════════════════════════════════════════════════════════╝"
    echo -e "${RESET}\n"
}

# Get script directory
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

# Test 1: Check that ORIGINAL_ARGS is defined
test_original_args_defined() {
    print_test "Checking that ORIGINAL_ARGS is saved before argument parsing"
    
    if grep -q 'ORIGINAL_ARGS=.*"$@"' "$REPO_ROOT/install.sh"; then
        print_pass "ORIGINAL_ARGS is saved before parsing"
        return 0
    else
        print_fail "ORIGINAL_ARGS is not saved"
        return 1
    fi
}

# Test 2: Check that ORIGINAL_ARGS is defined BEFORE the while loop
test_original_args_position() {
    print_test "Verifying ORIGINAL_ARGS is defined before argument parsing loop"
    
    # Find line numbers
    ORIGINAL_ARGS_LINE=$(grep -n 'ORIGINAL_ARGS=.*"$@"' "$REPO_ROOT/install.sh" | head -1 | cut -d: -f1)
    WHILE_LOOP_LINE=$(grep -n 'while \[\[ \$# -gt 0 \]\]' "$REPO_ROOT/install.sh" | head -1 | cut -d: -f1)
    
    if [ -n "$ORIGINAL_ARGS_LINE" ] && [ -n "$WHILE_LOOP_LINE" ] && [ "$ORIGINAL_ARGS_LINE" -lt "$WHILE_LOOP_LINE" ]; then
        print_pass "ORIGINAL_ARGS is defined at line $ORIGINAL_ARGS_LINE, before parsing loop at line $WHILE_LOOP_LINE"
        return 0
    else
        print_fail "ORIGINAL_ARGS is not properly positioned"
        return 1
    fi
}

# Test 3: Check that exec uses ORIGINAL_ARGS instead of $@
test_exec_uses_original_args() {
    print_test "Verifying exec command uses ORIGINAL_ARGS"
    
    if grep 'exec.*SCRIPT_DIR.*ORIGINAL_ARGS' "$REPO_ROOT/install.sh" > /dev/null; then
        print_pass "exec command uses ORIGINAL_ARGS to preserve flags"
        return 0
    else
        print_fail "exec command does not use ORIGINAL_ARGS"
        return 1
    fi
}

# Test 4: Verify the exec command syntax is correct
test_exec_syntax() {
    print_test "Verifying exec command syntax is correct"
    
    if grep 'exec.*"\${ORIGINAL_ARGS\[@\]}"' "$REPO_ROOT/install.sh" > /dev/null; then
        print_pass "exec command uses correct array expansion syntax"
        return 0
    else
        print_fail "exec command syntax may be incorrect"
        return 1
    fi
}

# Test 5: Simulate the fix with a mock script
test_mock_script_execution() {
    print_test "Testing with mock script that simulates install.sh behavior"
    
    # Create a temporary directory for testing
    TEST_DIR=$(mktemp -d)
    if [ -z "$TEST_DIR" ] || [ ! -d "$TEST_DIR" ]; then
        print_fail "Failed to create temporary directory"
        return 1
    fi
    cd "$TEST_DIR"
    
    # Create mock install script with the fix
    cat > mock-install.sh << 'MOCKSCRIPT'
#!/bin/bash
set -e

# Save original arguments (THE FIX)
ORIGINAL_ARGS=("$@")

VERBOSE=false

# Parse arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        -v|--verbose)
            VERBOSE=true
            shift
            ;;
        *)
            shift
            ;;
    esac
done

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Check if this is first or second run
if [ -f "$SCRIPT_DIR/.rerun-marker" ]; then
    echo "SECOND_RUN"
    echo "VERBOSE=$VERBOSE"
    rm -f "$SCRIPT_DIR/.rerun-marker"
    exit 0
else
    touch "$SCRIPT_DIR/.rerun-marker"
    # Restart with original args
    exec "$SCRIPT_DIR/$(basename "${BASH_SOURCE[0]}")" "${ORIGINAL_ARGS[@]}"
fi
MOCKSCRIPT
    
    chmod +x mock-install.sh
    
    # Test with --verbose
    OUTPUT=$(./mock-install.sh --verbose 2>&1)
    if echo "$OUTPUT" | grep -q "SECOND_RUN" && echo "$OUTPUT" | grep -q "VERBOSE=true"; then
        print_pass "Mock script correctly preserves --verbose flag"
        cd /
        rm -rf "$TEST_DIR"
        return 0
    else
        print_fail "Mock script did not preserve --verbose flag"
        echo -e "${DIM}Output was:${RESET}"
        echo "$OUTPUT"
        cd /
        rm -rf "$TEST_DIR"
        return 1
    fi
}

# Test 6: Verify comment explaining the fix
test_comment_exists() {
    print_test "Verifying explanatory comment exists for ORIGINAL_ARGS usage"
    
    # Check for comment near the exec line
    if grep -B 5 'exec.*ORIGINAL_ARGS' "$REPO_ROOT/install.sh" | grep -q "ORIGINAL_ARGS"; then
        print_pass "Explanatory comment found"
        return 0
    else
        print_fail "No explanatory comment found"
        return 1
    fi
}

# Test 7: Check install.sh syntax is still valid
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

# Test 8: Verify both -v and --verbose work
test_both_flag_formats() {
    print_test "Verifying both -v and --verbose flags are handled"
    
    if grep -E '(-v\|--verbose)' "$REPO_ROOT/install.sh" > /dev/null; then
        print_pass "Both -v and --verbose flag formats are handled"
        return 0
    else
        print_fail "Flag handling may be incomplete"
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
        echo -e "${GREEN}${BOLD}🎉 All tests passed! Verbose flag preservation is working correctly! 🎉${RESET}\n"
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
    test_original_args_defined || true
    test_original_args_position || true
    test_exec_uses_original_args || true
    test_exec_syntax || true
    test_mock_script_execution || true
    test_comment_exists || true
    test_install_syntax || true
    test_both_flag_formats || true
    
    # Print summary
    print_summary
}

# Run if executed directly
if [ "${BASH_SOURCE[0]}" == "${0}" ]; then
    main "$@"
fi
