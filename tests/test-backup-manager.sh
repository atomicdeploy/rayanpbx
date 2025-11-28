#!/bin/bash

# Test script for the centralized backup-manager.sh
# Verifies all backup operations work correctly

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
    export BACKUP_DIR="$TEST_DIR/backups"
    
    # Create mock Asterisk directory structure
    MOCK_ASTERISK_DIR="$TEST_DIR/asterisk"
    mkdir -p "$MOCK_ASTERISK_DIR"
    
    # Create mock config files
    cat > "$MOCK_ASTERISK_DIR/manager.conf" << 'EOF'
[general]
enabled=yes
port=5038

[admin]
secret=test_secret
read=all
write=all
EOF
    
    cat > "$MOCK_ASTERISK_DIR/pjsip.conf" << 'EOF'
[transport-udp]
type=transport
protocol=udp
bind=0.0.0.0:5060

[101]
type=endpoint
context=from-internal
EOF
    
    cat > "$MOCK_ASTERISK_DIR/extensions.conf" << 'EOF'
[from-internal]
exten => 101,1,Dial(PJSIP/101,30)
exten => 101,n,Hangup()
EOF
    
    print_info "Test directory: $TEST_DIR"
    print_info "Backup directory: $BACKUP_DIR"
}

# Cleanup test environment
cleanup_test_env() {
    if [ -n "$TEST_DIR" ] && [ -d "$TEST_DIR" ]; then
        rm -rf "$TEST_DIR"
        print_info "Cleaned up test directory"
    fi
}

# Test 1: Verify backup_config_file creates backup
test_backup_single_file() {
    print_test "Test 1: Backup single file"
    
    # Source the backup manager
    source scripts/backup-manager.sh
    
    # Create backup
    local backup_path
    backup_path=$(backup_config_file "$MOCK_ASTERISK_DIR/manager.conf")
    
    if [ -f "$backup_path" ]; then
        print_pass "Backup created: $backup_path"
    else
        print_fail "Backup was not created"
    fi
    
    # Verify backup is in the correct directory
    if [[ "$backup_path" == "$BACKUP_DIR/"* ]]; then
        print_pass "Backup is in centralized backup directory"
    else
        print_fail "Backup is not in centralized directory: $backup_path"
    fi
}

# Test 2: Verify duplicate detection
test_no_duplicate_backup() {
    print_test "Test 2: No duplicate backup for identical content"
    
    source scripts/backup-manager.sh
    
    # Create first backup
    backup_config_file "$MOCK_ASTERISK_DIR/manager.conf"
    local count_before
    count_before=$(find "$BACKUP_DIR" -name "manager.conf.*.backup" | wc -l)
    
    # Wait and try to create another backup
    sleep 1
    backup_config_file "$MOCK_ASTERISK_DIR/manager.conf"
    local count_after
    count_after=$(find "$BACKUP_DIR" -name "manager.conf.*.backup" | wc -l)
    
    if [ "$count_before" -eq "$count_after" ]; then
        print_pass "No duplicate backup created (count: $count_after)"
    else
        print_fail "Duplicate backup created: $count_before -> $count_after"
    fi
}

# Test 3: Verify new backup on content change
test_new_backup_on_change() {
    print_test "Test 3: New backup created when content changes"
    
    source scripts/backup-manager.sh
    
    local count_before
    count_before=$(find "$BACKUP_DIR" -name "manager.conf.*.backup" | wc -l)
    
    # Modify the file
    echo "modified=yes" >> "$MOCK_ASTERISK_DIR/manager.conf"
    
    # Create backup
    sleep 1
    backup_config_file "$MOCK_ASTERISK_DIR/manager.conf"
    
    local count_after
    count_after=$(find "$BACKUP_DIR" -name "manager.conf.*.backup" | wc -l)
    
    if [ "$count_after" -gt "$count_before" ]; then
        print_pass "New backup created for changed content: $count_before -> $count_after"
    else
        print_fail "No new backup created for changed content"
    fi
}

# Test 4: Verify backup_all
test_backup_all() {
    print_test "Test 4: Backup all managed files"
    
    source scripts/backup-manager.sh
    
    # Override MANAGED_CONF_FILES for testing
    MANAGED_CONF_FILES=(
        "$MOCK_ASTERISK_DIR/manager.conf"
        "$MOCK_ASTERISK_DIR/pjsip.conf"
        "$MOCK_ASTERISK_DIR/extensions.conf"
    )
    
    # Clear existing backups
    rm -f "$BACKUP_DIR"/*.backup 2>/dev/null || true
    
    # Backup all
    backup_all
    
    local total_backups
    total_backups=$(find "$BACKUP_DIR" -name "*.backup" | wc -l)
    
    if [ "$total_backups" -ge 3 ]; then
        print_pass "All files backed up: $total_backups backups created"
    else
        print_fail "Not all files backed up: $total_backups (expected >= 3)"
    fi
}

# Test 5: Verify restore
test_restore_backup() {
    print_test "Test 5: Restore backup"
    
    source scripts/backup-manager.sh
    
    # Get the latest backup
    local latest_backup
    latest_backup=$(find "$BACKUP_DIR" -name "manager.conf.*.backup" -type f | sort -r | head -1)
    
    if [ -z "$latest_backup" ]; then
        print_fail "No backup found to restore"
        return
    fi
    
    # Modify the original file significantly
    echo "COMPLETELY_MODIFIED" > "$MOCK_ASTERISK_DIR/manager.conf"
    
    # Restore
    restore_backup "$(basename "$latest_backup")" "$MOCK_ASTERISK_DIR/manager.conf"
    
    # Check if restored content is different from modified content
    if grep -q "\[general\]" "$MOCK_ASTERISK_DIR/manager.conf"; then
        print_pass "Backup restored successfully"
    else
        print_fail "Backup restore failed"
    fi
}

# Test 6: Verify cleanup
test_cleanup_backups() {
    print_test "Test 6: Cleanup old backups"
    
    source scripts/backup-manager.sh
    
    # Create multiple backups with different content
    for i in {1..5}; do
        echo "content_$i" > "$MOCK_ASTERISK_DIR/manager.conf"
        sleep 1
        backup_config_file "$MOCK_ASTERISK_DIR/manager.conf" true
    done
    
    local count_before
    count_before=$(find "$BACKUP_DIR" -name "manager.conf.*.backup" | wc -l)
    
    # Cleanup, keeping only 2
    cleanup_backups 2
    
    local count_after
    count_after=$(find "$BACKUP_DIR" -name "manager.conf.*.backup" | wc -l)
    
    if [ "$count_after" -le 2 ]; then
        print_pass "Cleanup successful: $count_before -> $count_after (kept 2)"
    else
        print_fail "Cleanup did not work: $count_after backups remaining"
    fi
}

# Test 7: Verify list backups
test_list_backups() {
    print_test "Test 7: List backups"
    
    source scripts/backup-manager.sh
    
    local output
    output=$(list_backups 2>&1)
    
    if [[ "$output" == *"manager.conf"* ]] || [[ "$output" == *"No backups found"* ]]; then
        print_pass "List backups works correctly"
    else
        print_fail "List backups output unexpected: $output"
    fi
}

# Test 8: Verify status
test_status() {
    print_test "Test 8: Backup status"
    
    source scripts/backup-manager.sh
    
    local output
    output=$(show_status 2>&1)
    
    if [[ "$output" == *"Backup Status"* ]] && [[ "$output" == *"$BACKUP_DIR"* ]]; then
        print_pass "Status command works correctly"
    else
        print_fail "Status output unexpected"
    fi
}

# Run all tests
main() {
    echo ""
    echo "=================================="
    echo "Backup Manager Tests"
    echo "=================================="
    echo ""
    
    setup_test_env
    
    test_backup_single_file
    test_no_duplicate_backup
    test_new_backup_on_change
    test_backup_all
    test_restore_backup
    test_cleanup_backups
    test_list_backups
    test_status
    
    cleanup_test_env
    
    echo ""
    echo "=================================="
    echo "Test Results"
    echo "=================================="
    echo -e "Passed: ${GREEN}$TESTS_PASSED${RESET}"
    echo -e "Failed: ${RED}$TESTS_FAILED${RESET}"
    echo ""
    
    if [ "$TESTS_FAILED" -eq 0 ]; then
        echo -e "${GREEN}All tests passed!${RESET}"
        exit 0
    else
        echo -e "${RED}Some tests failed!${RESET}"
        exit 1
    fi
}

main
