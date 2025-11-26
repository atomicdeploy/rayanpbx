#!/bin/bash

# Test suite for ami-tools.sh

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
TEST_TMP_DIR=""

# Colors
GREEN='\033[0;32m'
RED='\033[0;31m'
CYAN='\033[0;36m'
NC='\033[0m'

# Counters
TESTS_PASSED=0
TESTS_FAILED=0

print_test() { echo -e "\n${CYAN}[TEST]${NC} $1"; }
print_pass() { echo -e "${GREEN}✅ PASS:${NC} $1"; TESTS_PASSED=$((TESTS_PASSED + 1)); }
print_fail() { echo -e "${RED}❌ FAIL:${NC} $1"; TESTS_FAILED=$((TESTS_FAILED + 1)); }

setup() {
    TEST_TMP_DIR=$(mktemp -d -t test-ami-tools.XXXXXX)
    
    cat > "$TEST_TMP_DIR/manager.conf" << 'EOF'
[general]
enabled = yes
port = 5038
bindaddr = 127.0.0.1

[admin]
secret = my_super_secret_123
deny = 0.0.0.0/0.0.0.0
permit = 127.0.0.1/255.255.255.255
read = all
write = all
EOF
}

cleanup() {
    [ -n "$TEST_TMP_DIR" ] && rm -rf "$TEST_TMP_DIR"
    rm -f "$REPO_ROOT/.env" "$REPO_ROOT/.env.backup."* 2>/dev/null || true
}

trap cleanup EXIT

test_syntax() {
    print_test "Checking ami-tools.sh syntax"
    if bash -n "$REPO_ROOT/scripts/ami-tools.sh" 2>/dev/null; then
        print_pass "Script syntax is valid"
    else
        print_fail "Script has syntax errors"
    fi
}

test_help() {
    print_test "Checking help command"
    if bash "$REPO_ROOT/scripts/ami-tools.sh" --help 2>&1 | grep -q "AMI Tools"; then
        print_pass "Help command works"
    else
        print_fail "Help command failed"
    fi
}

test_version() {
    print_test "Checking version command"
    if bash "$REPO_ROOT/scripts/ami-tools.sh" --version 2>&1 | grep -q "AMI Tools"; then
        print_pass "Version command works"
    else
        print_fail "Version command failed"
    fi
}

test_username_extraction() {
    print_test "Testing username extraction from manager.conf"
    
    source "$REPO_ROOT/scripts/ami-tools.sh"
    local username
    username=$(extract_ami_username "$TEST_TMP_DIR/manager.conf")
    
    if [ "$username" = "admin" ]; then
        print_pass "Username extracted correctly"
    else
        print_fail "Username extraction failed (got: $username)"
    fi
}

test_secret_extraction() {
    print_test "Testing secret extraction from manager.conf"
    
    source "$REPO_ROOT/scripts/ami-tools.sh"
    local secret
    secret=$(extract_ami_secret "$TEST_TMP_DIR/manager.conf" "admin")
    
    if [ "$secret" = "my_super_secret_123" ]; then
        print_pass "Secret extracted correctly"
    else
        print_fail "Secret extraction failed (got: $secret)"
    fi
}

test_ami_enabled() {
    print_test "Testing AMI enabled check"
    
    source "$REPO_ROOT/scripts/ami-tools.sh"
    
    if check_ami_enabled "$TEST_TMP_DIR/manager.conf"; then
        print_pass "AMI enabled detection works"
    else
        print_fail "AMI enabled detection failed"
    fi
}

test_ami_not_enabled() {
    print_test "Testing AMI not enabled detection"
    
    cat > "$TEST_TMP_DIR/manager_disabled.conf" << 'EOF'
[general]
enabled = no
port = 5038
EOF
    
    source "$REPO_ROOT/scripts/ami-tools.sh"
    
    if ! check_ami_enabled "$TEST_TMP_DIR/manager_disabled.conf"; then
        print_pass "AMI not enabled detection works"
    else
        print_fail "AMI not enabled detection failed"
    fi
}

test_check_command() {
    print_test "Testing check command runs"
    
    local output
    output=$(MANAGER_CONF="$TEST_TMP_DIR/manager.conf" bash "$REPO_ROOT/scripts/ami-tools.sh" check 2>&1) || true
    
    if echo "$output" | grep -qE "manager.conf|AMI Health Check"; then
        print_pass "Check command runs"
    else
        print_fail "Check command failed"
    fi
}

test_diag_command() {
    print_test "Testing diag command runs"
    
    local output
    output=$(MANAGER_CONF="$TEST_TMP_DIR/manager.conf" bash "$REPO_ROOT/scripts/ami-tools.sh" diag 2>&1) || true
    
    if echo "$output" | grep -qE "Diagnostics|AMI Configuration"; then
        print_pass "Diag command runs"
    else
        print_fail "Diag command failed"
    fi
}

test_cli_integration() {
    print_test "Testing CLI integration"
    
    if grep -q "ami-tools.sh" "$REPO_ROOT/scripts/rayanpbx-cli.sh"; then
        print_pass "CLI uses ami-tools.sh"
    else
        print_fail "CLI not updated for ami-tools.sh"
    fi
}

main() {
    echo ""
    echo "╔════════════════════════════════════════════════════════════╗"
    echo "║         🧪  AMI Tools Test Suite  🧪                       ║"
    echo "╚════════════════════════════════════════════════════════════╝"
    echo ""
    
    setup
    
    test_syntax
    test_help
    test_version
    test_username_extraction
    test_secret_extraction
    test_ami_enabled
    test_ami_not_enabled
    test_check_command
    test_diag_command
    test_cli_integration
    
    echo ""
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo "Test Summary"
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo "Total:  $((TESTS_PASSED + TESTS_FAILED))"
    echo "Passed: $TESTS_PASSED"
    echo "Failed: $TESTS_FAILED"
    echo ""
    
    if [ "$TESTS_FAILED" -eq 0 ]; then
        echo -e "${GREEN}All tests passed!${NC}"
        exit 0
    else
        echo -e "${RED}Some tests failed!${NC}"
        exit 1
    fi
}

main "$@"
