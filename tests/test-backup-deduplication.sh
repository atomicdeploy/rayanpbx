#!/bin/bash

# Test script for backup deduplication functionality
# Verifies that identical backups are not created multiple times

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
RESET='\033[0m'

# Test counters
TESTS_PASSED=0
TESTS_FAILED=0

print_test() {
    echo -e "${CYAN}[TEST]${RESET} $1"
}

print_pass() {
    echo -e "${GREEN}[PASS]${RESET} $1"
    TESTS_PASSED=$((TESTS_PASSED + 1))
}

print_fail() {
    echo -e "${RED}[FAIL]${RESET} $1"
    TESTS_FAILED=$((TESTS_FAILED + 1))
}

print_info() {
    echo -e "${YELLOW}[INFO]${RESET} $1"
}

# Setup test environment
setup_test_env() {
    TEST_DIR=$(mktemp -d)
    TEST_FILE="$TEST_DIR/test_config.conf"
    
    # Create a test configuration file
    cat > "$TEST_FILE" << 'EOF'
[general]
enabled=yes
port=5038

[admin]
secret=test_secret
read=all
write=all
EOF
    
    print_info "Test directory: $TEST_DIR"
}

# Cleanup test environment
cleanup_test_env() {
    if [ -n "$TEST_DIR" ] && [ -d "$TEST_DIR" ]; then
        rm -rf "$TEST_DIR"
        print_info "Cleaned up test directory"
    fi
}

# Test 1: Verify backup_config function creates backup on first call
test_first_backup() {
    print_test "Test 1: First backup should be created"
    
    # Source the ini-helper script
    source scripts/ini-helper.sh
    
    # Create first backup
    local backup_path
    backup_path=$(backup_config "$TEST_FILE")
    
    if [ -f "$backup_path" ]; then
        print_pass "First backup created: $backup_path"
    else
        print_fail "First backup was not created"
    fi
}

# Test 2: Verify backup_config doesn't create duplicate if content is identical
test_no_duplicate_backup() {
    print_test "Test 2: Duplicate backup should NOT be created for identical content"
    
    # Source the ini-helper script
    source scripts/ini-helper.sh
    
    # Try to create backup again with same content
    local backup_path1
    local backup_path2
    
    backup_path1=$(backup_config "$TEST_FILE")
    sleep 1  # Ensure different timestamp would be generated
    backup_path2=$(backup_config "$TEST_FILE")
    
    # Count backup files
    local backup_count
    backup_count=$(find "$TEST_DIR" -name "test_config.conf.backup.*" | wc -l)
    
    if [ "$backup_count" -eq 1 ]; then
        print_pass "Only one backup exists (no duplicate created)"
    else
        print_fail "Expected 1 backup but found $backup_count"
        find "$TEST_DIR" -name "test_config.conf.backup.*" -ls
    fi
    
    # Verify both calls returned the same backup file
    if [ "$backup_path1" = "$backup_path2" ]; then
        print_pass "Both backup calls returned the same file path"
    else
        print_fail "Backup paths differ: '$backup_path1' vs '$backup_path2'"
    fi
}

# Test 3: Verify backup_config creates new backup when content changes
test_backup_on_content_change() {
    print_test "Test 3: New backup should be created when content changes"
    
    # Source the ini-helper script
    source scripts/ini-helper.sh
    
    # Get initial backup count
    local initial_count
    initial_count=$(find "$TEST_DIR" -name "test_config.conf.backup.*" | wc -l)
    
    # Modify the file content
    echo "" >> "$TEST_FILE"
    echo "[newuser]" >> "$TEST_FILE"
    echo "secret=new_secret" >> "$TEST_FILE"
    
    sleep 1  # Ensure different timestamp
    
    # Create backup of modified content
    local backup_path
    backup_path=$(backup_config "$TEST_FILE")
    
    # Count backups again
    local new_count
    new_count=$(find "$TEST_DIR" -name "test_config.conf.backup.*" | wc -l)
    
    if [ "$new_count" -gt "$initial_count" ]; then
        print_pass "New backup created after content change ($initial_count -> $new_count)"
    else
        print_fail "New backup was not created after content change"
    fi
}

# Test 4: Verify no duplicate after second change attempt with same content
test_no_duplicate_after_change() {
    print_test "Test 4: No duplicate backup after multiple calls with same changed content"
    
    # Source the ini-helper script
    source scripts/ini-helper.sh
    
    # Get current backup count
    local count_before
    count_before=$(find "$TEST_DIR" -name "test_config.conf.backup.*" | wc -l)
    
    # Try to backup again without changing content
    sleep 1
    backup_config "$TEST_FILE" > /dev/null
    
    local count_after
    count_after=$(find "$TEST_DIR" -name "test_config.conf.backup.*" | wc -l)
    
    if [ "$count_before" -eq "$count_after" ]; then
        print_pass "No duplicate created after multiple backup attempts"
    else
        print_fail "Duplicate backup created ($count_before -> $count_after)"
    fi
}

# Test 5: Test CLI backup functionality
test_cli_backup() {
    print_test "Test 5: CLI config set should handle backups correctly"
    
    # Create a test .env file
    local cli_test_dir
    cli_test_dir=$(mktemp -d)
    local test_env="$cli_test_dir/.env"
    
    cat > "$test_env" << 'EOF'
APP_NAME=RayanPBX
APP_ENV=production
DB_PASSWORD=test123
EOF
    
    # Set environment for CLI to use our test file
    export ENV_FILE="$test_env"
    export VERBOSE=false
    
    # Source CLI functions (we'll call the config set function)
    # Note: This is a simplified test - in real scenario we'd call the full CLI
    
    # Manually test the backup logic
    source scripts/ini-helper.sh
    
    # First backup
    backup_config "$test_env" > /dev/null
    local backup_count1
    backup_count1=$(find "$cli_test_dir" -name ".env.backup.*" | wc -l)
    
    # Try backup again with same content
    sleep 1
    backup_config "$test_env" > /dev/null
    local backup_count2
    backup_count2=$(find "$cli_test_dir" -name ".env.backup.*" | wc -l)
    
    if [ "$backup_count1" -eq "$backup_count2" ] && [ "$backup_count1" -eq 1 ]; then
        print_pass "CLI backup deduplication works correctly"
    else
        print_fail "CLI backup created duplicates ($backup_count1 vs $backup_count2)"
    fi
    
    # Cleanup
    rm -rf "$cli_test_dir"
    unset ENV_FILE
    unset VERBOSE
}

# Main test execution
main() {
    echo -e "${CYAN}========================================${RESET}"
    echo -e "${CYAN}  Backup Deduplication Test Suite${RESET}"
    echo -e "${CYAN}========================================${RESET}"
    echo ""
    
    # Setup
    setup_test_env
    
    # Run tests
    test_first_backup
    echo ""
    test_no_duplicate_backup
    echo ""
    test_backup_on_content_change
    echo ""
    test_no_duplicate_after_change
    echo ""
    test_cli_backup
    echo ""
    
    # Summary
    echo -e "${CYAN}========================================${RESET}"
    echo -e "${GREEN}Tests Passed: $TESTS_PASSED${RESET}"
    echo -e "${RED}Tests Failed: $TESTS_FAILED${RESET}"
    echo -e "${CYAN}========================================${RESET}"
    
    # Cleanup
    cleanup_test_env
    
    # Exit with appropriate code
    if [ $TESTS_FAILED -eq 0 ]; then
        exit 0
    else
        exit 1
    fi
}

# Run main
main
