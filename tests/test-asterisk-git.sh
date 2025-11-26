#!/bin/bash

# Test script for Asterisk Configuration Git Repository feature
# Tests the asterisk-git-commit.sh script and Git repository initialization

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
COMMIT_SCRIPT="$SCRIPT_DIR/../scripts/asterisk-git-commit.sh"
TEST_DIR="/tmp/test-asterisk-git-$$"
ASTERISK_DIR="$TEST_DIR/asterisk"

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

# Test counters
TESTS_RUN=0
TESTS_PASSED=0
TESTS_FAILED=0

# Print functions
print_test() {
    echo -e "${CYAN}[TEST]${NC} $1"
    TESTS_RUN=$((TESTS_RUN + 1))
}

print_pass() {
    echo -e "${GREEN}[PASS]${NC} $1"
    TESTS_PASSED=$((TESTS_PASSED + 1))
}

print_fail() {
    echo -e "${RED}[FAIL]${NC} $1"
    TESTS_FAILED=$((TESTS_FAILED + 1))
}

print_info() {
    echo -e "${YELLOW}[INFO]${NC} $1"
}

# Setup test environment
setup() {
    print_info "Setting up test environment..."
    mkdir -p "$ASTERISK_DIR"
    
    # Create some sample asterisk config files
    cat > "$ASTERISK_DIR/pjsip.conf" << 'EOF'
; Sample PJSIP configuration

[transport-udp]
type=transport
protocol=udp
bind=0.0.0.0:5060

[1001]
type=endpoint
context=from-internal
disallow=all
allow=ulaw
allow=alaw
auth=1001
aors=1001

[1001]
type=auth
auth_type=userpass
username=1001
password=secret123

[1001]
type=aor
max_contacts=1
EOF
    
    cat > "$ASTERISK_DIR/extensions.conf" << 'EOF'
; Sample dialplan

[from-internal]
exten => 1001,1,Dial(PJSIP/1001)
exten => 1001,n,Hangup()
EOF
    
    print_info "Test directory created: $TEST_DIR"
}

# Cleanup test environment
cleanup() {
    print_info "Cleaning up test environment..."
    rm -rf "$TEST_DIR"
}

# Test: Script exists and is executable
test_script_exists() {
    print_test "Script exists and is executable"
    
    if [ -f "$COMMIT_SCRIPT" ] && [ -x "$COMMIT_SCRIPT" ]; then
        print_pass "Script exists and is executable"
    else
        print_fail "Script missing or not executable: $COMMIT_SCRIPT"
    fi
}

# Test: Help command works
test_help_command() {
    print_test "Help command works"
    
    if "$COMMIT_SCRIPT" help 2>&1 | grep -q "asterisk-git-commit.sh"; then
        print_pass "Help command shows usage"
    else
        print_fail "Help command failed"
    fi
}

# Test: Status command on non-git directory
test_status_non_git() {
    print_test "Status command on non-git directory"
    
    output=$(ASTERISK_CONFIG_DIR="$ASTERISK_DIR" "$COMMIT_SCRIPT" status 2>&1 || true)
    
    if echo "$output" | grep -q "not a Git repository"; then
        print_pass "Correctly identifies non-git directory"
    else
        print_fail "Should report non-git directory"
    fi
}

# Test: Initialize Git repository
test_init_git_repo() {
    print_test "Initialize Git repository"
    
    cd "$ASTERISK_DIR"
    git init > /dev/null 2>&1
    git config user.email "test@localhost"
    git config user.name "Test"
    
    # Create .gitignore
    cat > "$ASTERISK_DIR/.gitignore" << 'EOF'
backups/
*.backup
*.bak
EOF
    
    git add -A
    git commit -m "[initial] Initial test commit" > /dev/null 2>&1
    
    if [ -d "$ASTERISK_DIR/.git" ]; then
        print_pass "Git repository initialized"
    else
        print_fail "Git repository not initialized"
    fi
}

# Test: Status command on git directory
test_status_git() {
    print_test "Status command on git directory"
    
    output=$(ASTERISK_CONFIG_DIR="$ASTERISK_DIR" "$COMMIT_SCRIPT" status 2>&1)
    
    if echo "$output" | grep -q "Repository initialized"; then
        print_pass "Correctly identifies git directory"
    else
        print_fail "Should report git directory initialized"
    fi
}

# Test: Commit changes
test_commit_changes() {
    print_test "Commit changes"
    
    # Make a change
    echo "; New extension 1002" >> "$ASTERISK_DIR/pjsip.conf"
    
    # Commit using the script
    output=$(ASTERISK_CONFIG_DIR="$ASTERISK_DIR" SOURCE="Test" "$COMMIT_SCRIPT" commit "extension-create" "Added extension 1002" 2>&1)
    
    # Check if commit was made
    cd "$ASTERISK_DIR"
    if git log -1 --oneline | grep -q "extension-create"; then
        print_pass "Changes committed successfully"
    else
        print_fail "Commit not found in history"
    fi
}

# Test: History command
test_history_command() {
    print_test "History command shows commits"
    
    output=$(ASTERISK_CONFIG_DIR="$ASTERISK_DIR" "$COMMIT_SCRIPT" history 5 2>&1)
    
    if echo "$output" | grep -q "extension-create"; then
        print_pass "History shows recent commits"
    else
        print_fail "History command failed to show commits"
    fi
}

# Test: No changes to commit
test_no_changes() {
    print_test "Handles no changes to commit"
    
    output=$(ASTERISK_CONFIG_DIR="$ASTERISK_DIR" "$COMMIT_SCRIPT" commit "test" "No changes" 2>&1)
    
    # Script should not fail even if no changes
    if [ $? -eq 0 ]; then
        print_pass "Handles no changes gracefully"
    else
        print_fail "Should handle no changes gracefully"
    fi
}

# Test: Backups directory is ignored
test_backups_ignored() {
    print_test "Backups directory is ignored by .gitignore"
    
    mkdir -p "$ASTERISK_DIR/backups"
    echo "backup file" > "$ASTERISK_DIR/backups/test-backup.conf"
    
    cd "$ASTERISK_DIR"
    git_status=$(git status --porcelain 2>&1)
    
    if echo "$git_status" | grep -q "backups"; then
        print_fail "Backups directory should be ignored"
    else
        print_pass "Backups directory is properly ignored"
    fi
}

# Test: Multiple commits create history
test_multiple_commits() {
    print_test "Multiple commits create history"
    
    # Make multiple changes and commits
    echo "; Extension 1003" >> "$ASTERISK_DIR/pjsip.conf"
    ASTERISK_CONFIG_DIR="$ASTERISK_DIR" "$COMMIT_SCRIPT" commit "extension-create" "Added extension 1003" > /dev/null 2>&1
    
    echo "; Extension 1004" >> "$ASTERISK_DIR/pjsip.conf"
    ASTERISK_CONFIG_DIR="$ASTERISK_DIR" "$COMMIT_SCRIPT" commit "extension-create" "Added extension 1004" > /dev/null 2>&1
    
    cd "$ASTERISK_DIR"
    commit_count=$(git rev-list --count HEAD)
    
    if [ "$commit_count" -ge 3 ]; then
        print_pass "Multiple commits in history (count: $commit_count)"
    else
        print_fail "Expected at least 3 commits, got $commit_count"
    fi
}

# Main test runner
main() {
    echo ""
    echo -e "${CYAN}═══════════════════════════════════════════════════${NC}"
    echo -e "${CYAN}  Asterisk Config Git Repository Tests${NC}"
    echo -e "${CYAN}═══════════════════════════════════════════════════${NC}"
    echo ""
    
    # Run setup
    setup
    
    # Run tests
    test_script_exists
    test_help_command
    test_status_non_git
    test_init_git_repo
    test_status_git
    test_commit_changes
    test_history_command
    test_no_changes
    test_backups_ignored
    test_multiple_commits
    
    # Cleanup
    cleanup
    
    # Print summary
    echo ""
    echo -e "${CYAN}═══════════════════════════════════════════════════${NC}"
    echo -e "${CYAN}  Test Summary${NC}"
    echo -e "${CYAN}═══════════════════════════════════════════════════${NC}"
    echo ""
    echo -e "  Tests Run:    $TESTS_RUN"
    echo -e "  ${GREEN}Passed:${NC}       $TESTS_PASSED"
    echo -e "  ${RED}Failed:${NC}       $TESTS_FAILED"
    echo ""
    
    if [ $TESTS_FAILED -eq 0 ]; then
        echo -e "${GREEN}All tests passed!${NC}"
        exit 0
    else
        echo -e "${RED}Some tests failed!${NC}"
        exit 1
    fi
}

main "$@"
