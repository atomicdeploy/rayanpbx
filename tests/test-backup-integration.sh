#!/bin/bash

# Integration test: Simulate installer running multiple times
# Verifies that duplicate backups are not created during upgrades

set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
RESET='\033[0m'

print_info() {
    echo -e "${CYAN}[INFO]${RESET} $1"
}

print_pass() {
    echo -e "${GREEN}[PASS]${RESET} $1"
}

print_fail() {
    echo -e "${RED}[FAIL]${RESET} $1"
    exit 1
}

# Create test environment
TEST_DIR=$(mktemp -d)
TEST_ASTERISK_CONF="$TEST_DIR/manager.conf"

print_info "Test directory: $TEST_DIR"

# Create a sample Asterisk manager.conf
cat > "$TEST_ASTERISK_CONF" << 'EOF'
[general]
enabled = yes
port = 5038
bindaddr = 0.0.0.0

[admin]
secret = mySecret123
deny=0.0.0.0/0.0.0.0
permit=127.0.0.1/255.255.255.255
read = system,call,log,verbose,command,agent,user,config
write = system,call,log,verbose,command,agent,user,config
EOF

print_info "Created test manager.conf"

# Source the ini-helper script
source scripts/ini-helper.sh

print_info "Simulating first installer run..."
# First "installer run" - create backup
backup1=$(backup_config "$TEST_ASTERISK_CONF")
print_info "First backup: $backup1"

# Modify the config (simulating installer configuration)
set_ini_value "$TEST_ASTERISK_CONF" "general" "bindaddr" "127.0.0.1"
set_ini_value "$TEST_ASTERISK_CONF" "admin" "secret" "rayanpbx_ami_secret"

print_info "Modified configuration"

# Count backups after first run
backup_count1=$(find "$TEST_DIR" -name "manager.conf.backup.*" | wc -l)
print_info "Backups after first run: $backup_count1"

# Simulate second installer run (upgrade scenario)
print_info "Simulating second installer run (upgrade)..."
sleep 1  # Ensure different timestamp

# This simulates running the installer again - it will try to backup before modifying
backup2=$(backup_config "$TEST_ASTERISK_CONF")
print_info "Second backup attempt: $backup2"

# Apply same modifications again (installer doing same configuration)
set_ini_value "$TEST_ASTERISK_CONF" "general" "bindaddr" "127.0.0.1"
set_ini_value "$TEST_ASTERISK_CONF" "admin" "secret" "rayanpbx_ami_secret"

# Count backups after second run
backup_count2=$(find "$TEST_DIR" -name "manager.conf.backup.*" | wc -l)
print_info "Backups after second run: $backup_count2"

# Simulate third installer run
print_info "Simulating third installer run (another upgrade)..."
sleep 1

backup3=$(backup_config "$TEST_ASTERISK_CONF")
print_info "Third backup attempt: $backup3"

set_ini_value "$TEST_ASTERISK_CONF" "general" "bindaddr" "127.0.0.1"
set_ini_value "$TEST_ASTERISK_CONF" "admin" "secret" "rayanpbx_ami_secret"

backup_count3=$(find "$TEST_DIR" -name "manager.conf.backup.*" | wc -l)
print_info "Backups after third run: $backup_count3"

echo ""
echo "======================================"
echo "Test Results:"
echo "======================================"

# Verification
if [ "$backup_count1" -eq 1 ]; then
    print_pass "First run created 1 backup"
else
    print_fail "First run created $backup_count1 backups (expected 1)"
fi

if [ "$backup_count2" -eq 2 ]; then
    print_pass "Second run created 1 additional backup (total 2)"
else
    print_fail "Second run resulted in $backup_count2 backups (expected 2)"
fi

if [ "$backup_count3" -eq 2 ]; then
    print_pass "Third run did NOT create duplicate (still 2 backups)"
else
    print_fail "Third run resulted in $backup_count3 backups (expected 2)"
fi

echo ""
print_info "Listing all backup files:"
find "$TEST_DIR" -name "manager.conf.backup.*" -exec basename {} \;

# Verify backups have different content
print_info "Verifying backup content differences..."
backups=($(find "$TEST_DIR" -name "manager.conf.backup.*" | sort))

if [ ${#backups[@]} -eq 2 ]; then
    # Compare checksums
    checksum1=$(md5sum "${backups[0]}" | awk '{print $1}')
    checksum2=$(md5sum "${backups[1]}" | awk '{print $1}')
    
    if [ "$checksum1" != "$checksum2" ]; then
        print_pass "The two backups have different content (as expected)"
    else
        print_fail "The two backups have identical content (unexpected)"
    fi
else
    print_info "Skipping content comparison (expected 2 backups, found ${#backups[@]})"
fi

# Cleanup
rm -rf "$TEST_DIR"
print_info "Test directory cleaned up"

echo ""
echo "======================================"
echo -e "${GREEN}✅ Integration test PASSED${RESET}"
echo "======================================"
