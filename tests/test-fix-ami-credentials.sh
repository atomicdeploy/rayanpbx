#!/bin/bash

# Test script for fix-ami-credentials.sh
# Validates the AMI credential extraction and sync functionality

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
TEST_TMP_DIR="/tmp/test-ami-credentials-$$"

print_test() {
    TEST_COUNT=$((TEST_COUNT + 1))
    echo -e "${CYAN}[TEST $TEST_COUNT]${RESET} $1"
}

print_pass() {
    PASS_COUNT=$((PASS_COUNT + 1))
    echo -e "${GREEN}✅ PASS${RESET}: $1"
}

print_fail() {
    FAIL_COUNT=$((FAIL_COUNT + 1))
    echo -e "${RED}❌ FAIL${RESET}: $1"
}

print_header() {
    echo -e "${CYAN}${BOLD}"
    echo "╔════════════════════════════════════════════════════════════╗"
    echo "║                                                            ║"
    echo "║       🧪  AMI Credential Fix Script Test Suite  🧪        ║"
    echo "║                                                            ║"
    echo "╚════════════════════════════════════════════════════════════╝"
    echo -e "${RESET}\n"
}

print_summary() {
    echo ""
    echo -e "${BOLD}Test Summary${RESET}"
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo -e "Total:  $TEST_COUNT"
    echo -e "Passed: ${GREEN}$PASS_COUNT${RESET}"
    echo -e "Failed: ${RED}$FAIL_COUNT${RESET}"
    
    if [ "$FAIL_COUNT" -eq 0 ]; then
        echo ""
        echo -e "${GREEN}${BOLD}All tests passed!${RESET}"
        exit 0
    else
        echo ""
        echo -e "${RED}${BOLD}Some tests failed!${RESET}"
        exit 1
    fi
}

setup() {
    # Create test temp directory
    mkdir -p "$TEST_TMP_DIR"
}

cleanup() {
    # Remove test temp directory
    rm -rf "$TEST_TMP_DIR"
}

trap cleanup EXIT

# Test 1: Check script syntax
test_syntax() {
    print_test "Checking fix-ami-credentials.sh syntax"
    
    if bash -n "$REPO_ROOT/scripts/fix-ami-credentials.sh" 2>/dev/null; then
        print_pass "Script syntax is valid"
    else
        print_fail "Script has syntax errors"
    fi
}

# Test 2: Check help command works
test_help_command() {
    print_test "Checking help command"
    
    if bash "$REPO_ROOT/scripts/fix-ami-credentials.sh" help 2>&1 | grep -q "Usage:"; then
        print_pass "Help command works"
    else
        print_fail "Help command failed"
    fi
}

# Test 3: Check version command works
test_version_command() {
    print_test "Checking version command"
    
    if bash "$REPO_ROOT/scripts/fix-ami-credentials.sh" --version 2>&1 | grep -q "v2.0.0"; then
        print_pass "Version command works"
    else
        print_fail "Version command failed"
    fi
}

# Test 4: Test username extraction from manager.conf
test_username_extraction() {
    print_test "Testing username extraction from manager.conf"
    
    # Create test manager.conf
    cat > "$TEST_TMP_DIR/manager.conf" << 'EOF'
[general]
enabled = yes
port = 5038

[myuser]
secret = test_secret
read = all
write = all
EOF
    
    # Run check command to verify extraction
    local output
    output=$(MANAGER_CONF="$TEST_TMP_DIR/manager.conf" bash "$REPO_ROOT/scripts/fix-ami-credentials.sh" check 2>&1) || true
    
    if echo "$output" | grep -q "Username: myuser"; then
        print_pass "Username extracted correctly"
    else
        print_fail "Username extraction failed"
    fi
}

# Test 5: Test secret extraction from manager.conf
test_secret_extraction() {
    print_test "Testing secret extraction from manager.conf"
    
    # Create test manager.conf
    cat > "$TEST_TMP_DIR/manager.conf" << 'EOF'
[general]
enabled = yes
port = 5038

[admin]
secret = my_super_secret_123
deny = 0.0.0.0/0.0.0.0
permit = 127.0.0.1/255.255.255.255
read = all
write = all
EOF
    
    # Run check command to verify extraction
    local output
    output=$(MANAGER_CONF="$TEST_TMP_DIR/manager.conf" bash "$REPO_ROOT/scripts/fix-ami-credentials.sh" check 2>&1) || true
    
    # The output should show "Secret: configured (my_s****)"
    if echo "$output" | grep -q "Secret.*configured.*my_s"; then
        print_pass "Secret extracted correctly"
    else
        print_fail "Secret extraction failed"
    fi
}

# Test 6: Test comment handling in manager.conf
test_comment_handling() {
    print_test "Testing comment handling in manager.conf"
    
    # Create test manager.conf with comments
    cat > "$TEST_TMP_DIR/manager.conf" << 'EOF'
; This is a comment
[general]
enabled = yes
port = 5038

# Another comment style
[testuser]
; secret = old_secret
secret = actual_secret
read = all
EOF
    
    # Run check command - should extract "actual_secret" not "old_secret"
    local output
    output=$(MANAGER_CONF="$TEST_TMP_DIR/manager.conf" bash "$REPO_ROOT/scripts/fix-ami-credentials.sh" check 2>&1) || true
    
    if echo "$output" | grep -q "Secret.*configured.*actu"; then
        print_pass "Comments handled correctly, got actual secret"
    else
        print_fail "Comment handling failed"
    fi
}

# Test 7: Test .env file creation via fix command
test_env_update() {
    print_test "Testing .env file creation via fix command"
    
    # Clean up any previous test .env file
    rm -f "$REPO_ROOT/.env" "$REPO_ROOT/.env.backup."* 2>/dev/null || true
    
    # Create test manager.conf
    cat > "$TEST_TMP_DIR/manager.conf" << 'EOF'
[general]
enabled = yes
port = 5038

[admin]
secret = my_secret_value
read = all
write = all
EOF
    
    # Run fix command (it will create .env since it doesn't exist)
    cd "$TEST_TMP_DIR"
    MANAGER_CONF="$TEST_TMP_DIR/manager.conf" bash "$REPO_ROOT/scripts/fix-ami-credentials.sh" fix --no-reload 2>&1 || true
    
    # Check if .env was created somewhere with correct secret
    local env_created=false
    if [ -f "$TEST_TMP_DIR/.env" ] && grep -q "ASTERISK_AMI_SECRET=my_secret_value" "$TEST_TMP_DIR/.env"; then
        env_created=true
    elif [ -f "$REPO_ROOT/.env" ] && grep -q "ASTERISK_AMI_SECRET=my_secret_value" "$REPO_ROOT/.env"; then
        env_created=true
    fi
    
    if [ "$env_created" = "true" ]; then
        print_pass ".env file created with correct AMI credentials"
    else
        print_fail ".env file not created or secret not correct"
    fi
    
    # Clean up
    rm -f "$REPO_ROOT/.env" "$REPO_ROOT/.env.backup."* 2>/dev/null || true
}

# Test 8: Test .env file update (existing file)
test_env_add_new_var() {
    print_test "Testing .env file update with existing file"
    
    # Create test manager.conf
    cat > "$TEST_TMP_DIR/manager.conf" << 'EOF'
[general]
enabled = yes
port = 5038

[admin]
secret = new_test_secret
read = all
write = all
EOF
    
    # Create existing .env file in repo root (where script finds it)
    cat > "$REPO_ROOT/.env" << 'EOF'
APP_NAME=RayanPBX
ASTERISK_AMI_SECRET=old_secret
EOF
    
    # Run fix command - should update existing secret
    MANAGER_CONF="$TEST_TMP_DIR/manager.conf" bash "$REPO_ROOT/scripts/fix-ami-credentials.sh" fix --no-reload 2>&1 || true
    
    # Check if secret was updated
    if grep -q "ASTERISK_AMI_SECRET=new_test_secret" "$REPO_ROOT/.env"; then
        print_pass "Existing .env file updated correctly"
    else
        print_fail "Existing .env file not updated correctly"
    fi
    
    # Clean up
    rm -f "$REPO_ROOT/.env" "$REPO_ROOT/.env.backup."* 2>/dev/null || true
}

# Test 9: Test CLI integration
test_cli_integration() {
    print_test "Testing CLI integration"
    
    # Check that diag fix-ami is available in CLI
    if bash "$REPO_ROOT/scripts/rayanpbx-cli.sh" help 2>&1 | grep -q "fix-ami"; then
        print_pass "fix-ami command is in CLI help"
    else
        print_fail "fix-ami command not found in CLI help"
    fi
}

# Test 10: Test CLI fix-ami command exists
test_cli_fix_ami_exists() {
    print_test "Testing CLI diag fix-ami command exists"
    
    # Run diag fix-ami with a non-existent manager.conf (will fail but should not error about missing script)
    local output
    output=$(bash "$REPO_ROOT/scripts/rayanpbx-cli.sh" diag fix-ami 2>&1) || true
    
    if echo "$output" | grep -q "manager.conf not found\|AMI Credential Fix"; then
        print_pass "CLI diag fix-ami command works"
    else
        print_fail "CLI diag fix-ami command not working: $output"
    fi
}

# Test 11: Test automated Asterisk status check
test_automated_asterisk_check() {
    print_test "Testing automated Asterisk status check"
    
    # Create test manager.conf
    cat > "$TEST_TMP_DIR/manager.conf" << 'EOF'
[general]
enabled = yes
port = 5038

[admin]
secret = test_secret
read = all
write = all
EOF
    
    # Run fix command and check for automated check output
    local output
    output=$(MANAGER_CONF="$TEST_TMP_DIR/manager.conf" bash "$REPO_ROOT/scripts/fix-ami-credentials.sh" fix --no-reload 2>&1) || true
    
    # Should show automated diagnostics
    if echo "$output" | grep -q "Checking if Asterisk is running"; then
        print_pass "Automated Asterisk status check is present"
    else
        print_fail "Automated Asterisk status check missing"
    fi
    
    # Clean up
    rm -f "$REPO_ROOT/.env" "$REPO_ROOT/.env.backup."* 2>/dev/null || true
}

# Test 12: Test automated AMI enabled check
test_automated_ami_enabled_check() {
    print_test "Testing automated AMI enabled check"
    
    # Create test manager.conf with AMI enabled
    cat > "$TEST_TMP_DIR/manager.conf" << 'EOF'
[general]
enabled = yes
port = 5038

[admin]
secret = test_secret
read = all
write = all
EOF
    
    # Run fix command
    local output
    output=$(MANAGER_CONF="$TEST_TMP_DIR/manager.conf" bash "$REPO_ROOT/scripts/fix-ami-credentials.sh" fix --no-reload 2>&1) || true
    
    # Should show AMI enabled check
    if echo "$output" | grep -q "Checking if AMI is enabled in manager.conf"; then
        print_pass "Automated AMI enabled check is present"
    else
        print_fail "Automated AMI enabled check missing"
    fi
    
    # Clean up
    rm -f "$REPO_ROOT/.env" "$REPO_ROOT/.env.backup."* 2>/dev/null || true
}

# Test 13: Test AMI not enabled detection
test_ami_not_enabled_detection() {
    print_test "Testing AMI not enabled detection"
    
    # Create test manager.conf WITHOUT enabled = yes
    cat > "$TEST_TMP_DIR/manager.conf" << 'EOF'
[general]
port = 5038

[admin]
secret = test_secret
read = all
write = all
EOF
    
    # Run fix command
    local output
    output=$(MANAGER_CONF="$TEST_TMP_DIR/manager.conf" bash "$REPO_ROOT/scripts/fix-ami-credentials.sh" fix --no-reload 2>&1) || true
    
    # Should detect that AMI is not enabled and attempt to enable it
    if echo "$output" | grep -E -q "AMI is not enabled in manager.conf|Enabling AMI"; then
        print_pass "AMI not enabled detection works"
    else
        print_fail "AMI not enabled detection failed"
    fi
    
    # Clean up
    rm -f "$REPO_ROOT/.env" "$REPO_ROOT/.env.backup."* 2>/dev/null || true
}

# Test 14: Test no manual steps in output
test_no_manual_steps_message() {
    print_test "Testing that manual steps message is replaced with automation"
    
    # Create test manager.conf
    cat > "$TEST_TMP_DIR/manager.conf" << 'EOF'
[general]
enabled = yes
port = 5038

[admin]
secret = test_secret
read = all
write = all
EOF
    
    # Run fix command
    local output
    output=$(MANAGER_CONF="$TEST_TMP_DIR/manager.conf" bash "$REPO_ROOT/scripts/fix-ami-credentials.sh" fix --no-reload 2>&1) || true
    
    # Should NOT show the old manual steps message
    if echo "$output" | grep -q "Please check:" && echo "$output" | grep -q "systemctl status asterisk"; then
        print_fail "Old manual steps message still present"
    else
        print_pass "Manual steps message replaced with automated diagnostics"
    fi
    
    # Clean up
    rm -f "$REPO_ROOT/.env" "$REPO_ROOT/.env.backup."* 2>/dev/null || true
}

# Run all tests
main() {
    print_header
    setup
    
    test_syntax
    test_help_command
    test_version_command
    test_username_extraction
    test_secret_extraction
    test_comment_handling
    test_env_update
    test_env_add_new_var
    test_cli_integration
    test_cli_fix_ami_exists
    test_automated_asterisk_check
    test_automated_ami_enabled_check
    test_ami_not_enabled_detection
    test_no_manual_steps_message
    
    print_summary
}

main "$@"
