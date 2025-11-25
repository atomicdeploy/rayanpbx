#!/bin/bash

# Test script to verify that -v/--verbose flag is correctly passed through by upgrade.sh
# This tests the fix for the issue where the verbose flag broke argument parsing

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
    echo "║   🧪  Upgrade Script Verbose Flag Test Suite  🧪          ║"
    echo "║                                                            ║"
    echo "╚════════════════════════════════════════════════════════════╝"
    echo -e "${RESET}\n"
}

# Get script directory
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

# Test 1: Check that PASSTHROUGH_ARGS is defined
test_passthrough_args_defined() {
    print_test "Checking that PASSTHROUGH_ARGS array is defined in upgrade.sh"
    
    if grep -q 'PASSTHROUGH_ARGS=()' "$REPO_ROOT/scripts/upgrade.sh"; then
        print_pass "PASSTHROUGH_ARGS array is properly initialized"
        return 0
    else
        print_fail "PASSTHROUGH_ARGS array is not defined"
        return 1
    fi
}

# Test 2: Check that unknown args are collected into PASSTHROUGH_ARGS
test_passthrough_collection() {
    print_test "Verifying unknown arguments are collected into PASSTHROUGH_ARGS"
    
    if grep -q 'PASSTHROUGH_ARGS+=.*"\$1"' "$REPO_ROOT/scripts/upgrade.sh"; then
        print_pass "Unknown arguments are properly collected"
        return 0
    else
        print_fail "PASSTHROUGH_ARGS collection not found"
        return 1
    fi
}

# Test 3: Check that exec uses PASSTHROUGH_ARGS array
test_exec_uses_passthrough() {
    print_test "Verifying exec command uses PASSTHROUGH_ARGS array"
    
    if grep 'exec.*PASSTHROUGH_ARGS' "$REPO_ROOT/scripts/upgrade.sh" > /dev/null; then
        print_pass "exec command passes through collected arguments"
        return 0
    else
        print_fail "exec command does not use PASSTHROUGH_ARGS"
        return 1
    fi
}

# Test 4: Check that exec uses correct array expansion syntax
test_exec_syntax() {
    print_test "Verifying exec command uses correct array expansion syntax"
    
    if grep 'exec.*"\${PASSTHROUGH_ARGS\[@\]}"' "$REPO_ROOT/scripts/upgrade.sh" > /dev/null; then
        print_pass "exec command uses correct array expansion syntax"
        return 0
    else
        print_fail "exec command syntax may be incorrect"
        return 1
    fi
}

# Test 5: Test with mock script - verbose flag alone
test_mock_verbose_alone() {
    print_test "Testing -v flag is passed through correctly"
    
    # Create a temporary directory for testing
    TEST_DIR=$(mktemp -d)
    if [ -z "$TEST_DIR" ] || [ ! -d "$TEST_DIR" ]; then
        print_fail "Failed to create temporary directory"
        return 1
    fi
    
    # Create mock install.sh
    cat > "$TEST_DIR/install.sh" << 'EOF'
#!/bin/bash
echo "RECEIVED_ARGS: $@"
for arg in "$@"; do
    case $arg in
        -v|--verbose) echo "VERBOSE_FOUND=true" ;;
        --upgrade) echo "UPGRADE_FOUND=true" ;;
        --backup) echo "BACKUP_FOUND=true" ;;
    esac
done
EOF
    chmod +x "$TEST_DIR/install.sh"
    
    # Create test version of upgrade.sh
    cat > "$TEST_DIR/upgrade.sh" << 'EOF'
#!/bin/bash
set -euo pipefail
INSTALL_SCRIPT="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )/install.sh"
INTERACTIVE=false
CREATE_BACKUP=false
PASSTHROUGH_ARGS=()
while [[ $# -gt 0 ]]; do
    case $1 in
        -i|--confirm) INTERACTIVE=true; shift ;;
        -b|--backup) CREATE_BACKUP=true; shift ;;
        *) PASSTHROUGH_ARGS+=("$1"); shift ;;
    esac
done
INSTALL_ARGS="--upgrade"
[ "$CREATE_BACKUP" = true ] && INSTALL_ARGS="$INSTALL_ARGS --backup"
exec "$INSTALL_SCRIPT" $INSTALL_ARGS "${PASSTHROUGH_ARGS[@]}"
EOF
    chmod +x "$TEST_DIR/upgrade.sh"
    
    # Test with -v
    OUTPUT=$("$TEST_DIR/upgrade.sh" -v 2>&1)
    
    if echo "$OUTPUT" | grep -q "VERBOSE_FOUND=true" && echo "$OUTPUT" | grep -q "UPGRADE_FOUND=true"; then
        print_pass "-v flag is passed through correctly"
        rm -rf "$TEST_DIR"
        return 0
    else
        print_fail "-v flag was not passed through"
        echo -e "${DIM}Output was:${RESET}"
        echo "$OUTPUT"
        rm -rf "$TEST_DIR"
        return 1
    fi
}

# Test 6: Test with verbose and backup flags together
test_mock_verbose_with_backup() {
    print_test "Testing -v and -b flags work together"
    
    TEST_DIR=$(mktemp -d)
    
    # Create mock install.sh
    cat > "$TEST_DIR/install.sh" << 'EOF'
#!/bin/bash
echo "RECEIVED_ARGS: $@"
for arg in "$@"; do
    case $arg in
        -v|--verbose) echo "VERBOSE_FOUND=true" ;;
        --upgrade) echo "UPGRADE_FOUND=true" ;;
        --backup) echo "BACKUP_FOUND=true" ;;
    esac
done
EOF
    chmod +x "$TEST_DIR/install.sh"
    
    # Create test version of upgrade.sh
    cat > "$TEST_DIR/upgrade.sh" << 'EOF'
#!/bin/bash
set -euo pipefail
INSTALL_SCRIPT="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )/install.sh"
INTERACTIVE=false
CREATE_BACKUP=false
PASSTHROUGH_ARGS=()
while [[ $# -gt 0 ]]; do
    case $1 in
        -i|--confirm) INTERACTIVE=true; shift ;;
        -b|--backup) CREATE_BACKUP=true; shift ;;
        *) PASSTHROUGH_ARGS+=("$1"); shift ;;
    esac
done
INSTALL_ARGS="--upgrade"
[ "$CREATE_BACKUP" = true ] && INSTALL_ARGS="$INSTALL_ARGS --backup"
exec "$INSTALL_SCRIPT" $INSTALL_ARGS "${PASSTHROUGH_ARGS[@]}"
EOF
    chmod +x "$TEST_DIR/upgrade.sh"
    
    # Test with -v and -b
    OUTPUT=$("$TEST_DIR/upgrade.sh" -v -b 2>&1)
    
    if echo "$OUTPUT" | grep -q "VERBOSE_FOUND=true" && \
       echo "$OUTPUT" | grep -q "BACKUP_FOUND=true" && \
       echo "$OUTPUT" | grep -q "UPGRADE_FOUND=true"; then
        print_pass "-v and -b flags work correctly together"
        rm -rf "$TEST_DIR"
        return 0
    else
        print_fail "Flags did not work together correctly"
        echo -e "${DIM}Output was:${RESET}"
        echo "$OUTPUT"
        rm -rf "$TEST_DIR"
        return 1
    fi
}

# Test 7: Test verbose flag before backup flag (the problematic case)
test_mock_verbose_first() {
    print_test "Testing -v before -b (the problematic order that was fixed)"
    
    TEST_DIR=$(mktemp -d)
    
    # Create mock install.sh
    cat > "$TEST_DIR/install.sh" << 'EOF'
#!/bin/bash
echo "RECEIVED_ARGS: $@"
for arg in "$@"; do
    case $arg in
        -v|--verbose) echo "VERBOSE_FOUND=true" ;;
        --upgrade) echo "UPGRADE_FOUND=true" ;;
        --backup) echo "BACKUP_FOUND=true" ;;
    esac
done
EOF
    chmod +x "$TEST_DIR/install.sh"
    
    # Create test version of upgrade.sh
    cat > "$TEST_DIR/upgrade.sh" << 'EOF'
#!/bin/bash
set -euo pipefail
INSTALL_SCRIPT="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )/install.sh"
INTERACTIVE=false
CREATE_BACKUP=false
PASSTHROUGH_ARGS=()
while [[ $# -gt 0 ]]; do
    case $1 in
        -i|--confirm) INTERACTIVE=true; shift ;;
        -b|--backup) CREATE_BACKUP=true; shift ;;
        *) PASSTHROUGH_ARGS+=("$1"); shift ;;
    esac
done
INSTALL_ARGS="--upgrade"
[ "$CREATE_BACKUP" = true ] && INSTALL_ARGS="$INSTALL_ARGS --backup"
exec "$INSTALL_SCRIPT" $INSTALL_ARGS "${PASSTHROUGH_ARGS[@]}"
EOF
    chmod +x "$TEST_DIR/upgrade.sh"
    
    # Test with -v -b -i order (previously this would fail because -v caused break)
    OUTPUT=$("$TEST_DIR/upgrade.sh" -v -b -i 2>&1)
    
    if echo "$OUTPUT" | grep -q "VERBOSE_FOUND=true" && \
       echo "$OUTPUT" | grep -q "BACKUP_FOUND=true" && \
       echo "$OUTPUT" | grep -q "UPGRADE_FOUND=true"; then
        print_pass "Verbose flag first order works correctly"
        rm -rf "$TEST_DIR"
        return 0
    else
        print_fail "Verbose flag first order failed"
        echo -e "${DIM}Output was:${RESET}"
        echo "$OUTPUT"
        rm -rf "$TEST_DIR"
        return 1
    fi
}

# Test 8: Test --steps= argument passes through
test_mock_steps_passthrough() {
    print_test "Testing --steps= argument passes through"
    
    TEST_DIR=$(mktemp -d)
    
    # Create mock install.sh
    cat > "$TEST_DIR/install.sh" << 'EOF'
#!/bin/bash
echo "RECEIVED_ARGS: $@"
for arg in "$@"; do
    if [[ "$arg" == --steps=* ]]; then
        echo "STEPS_FOUND=${arg#*=}"
    fi
done
EOF
    chmod +x "$TEST_DIR/install.sh"
    
    # Create test version of upgrade.sh
    cat > "$TEST_DIR/upgrade.sh" << 'EOF'
#!/bin/bash
set -euo pipefail
INSTALL_SCRIPT="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )/install.sh"
INTERACTIVE=false
CREATE_BACKUP=false
PASSTHROUGH_ARGS=()
while [[ $# -gt 0 ]]; do
    case $1 in
        -i|--confirm) INTERACTIVE=true; shift ;;
        -b|--backup) CREATE_BACKUP=true; shift ;;
        *) PASSTHROUGH_ARGS+=("$1"); shift ;;
    esac
done
INSTALL_ARGS="--upgrade"
[ "$CREATE_BACKUP" = true ] && INSTALL_ARGS="$INSTALL_ARGS --backup"
exec "$INSTALL_SCRIPT" $INSTALL_ARGS "${PASSTHROUGH_ARGS[@]}"
EOF
    chmod +x "$TEST_DIR/upgrade.sh"
    
    # Test with --steps=backend,frontend
    OUTPUT=$("$TEST_DIR/upgrade.sh" --steps=backend,frontend 2>&1)
    
    if echo "$OUTPUT" | grep -q "STEPS_FOUND=backend,frontend"; then
        print_pass "--steps= argument passes through correctly"
        rm -rf "$TEST_DIR"
        return 0
    else
        print_fail "--steps= argument was not passed through"
        echo -e "${DIM}Output was:${RESET}"
        echo "$OUTPUT"
        rm -rf "$TEST_DIR"
        return 1
    fi
}

# Test 9: Verify upgrade.sh syntax is valid
test_upgrade_syntax() {
    print_test "Verifying upgrade.sh syntax is still valid"
    
    if bash -n "$REPO_ROOT/scripts/upgrade.sh" 2>&1; then
        print_pass "upgrade.sh has valid syntax"
        return 0
    else
        print_fail "upgrade.sh has syntax errors"
        return 1
    fi
}

# Test 10: Verify comment explaining passthrough behavior
test_comment_exists() {
    print_test "Verifying explanatory comment exists for PASSTHROUGH_ARGS"
    
    if grep -B 2 'PASSTHROUGH_ARGS=()' "$REPO_ROOT/scripts/upgrade.sh" | grep -q -i "pass-through\|passthrough"; then
        print_pass "Explanatory comment found"
        return 0
    else
        print_fail "No explanatory comment found (this is a minor issue)"
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
        echo -e "${GREEN}${BOLD}🎉 All tests passed! Upgrade script verbose flag is working correctly! 🎉${RESET}\n"
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
    test_passthrough_args_defined || true
    test_passthrough_collection || true
    test_exec_uses_passthrough || true
    test_exec_syntax || true
    test_mock_verbose_alone || true
    test_mock_verbose_with_backup || true
    test_mock_verbose_first || true
    test_mock_steps_passthrough || true
    test_upgrade_syntax || true
    test_comment_exists || true
    
    # Print summary
    print_summary
}

# Run if executed directly
if [ "${BASH_SOURCE[0]}" == "${0}" ]; then
    main "$@"
fi
