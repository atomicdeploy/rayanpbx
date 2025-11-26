#!/bin/bash

# End-to-end scenario test: Real-world installer upgrade scenario
# This simulates the actual workflow that would happen during upgrades

set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
BOLD='\033[1m'
RESET='\033[0m'

print_header() {
    echo ""
    echo -e "${CYAN}${BOLD}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${RESET}"
    echo -e "${CYAN}${BOLD}  $1${RESET}"
    echo -e "${CYAN}${BOLD}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${RESET}"
}

print_step() {
    echo -e "${YELLOW}▶${RESET} $1"
}

print_pass() {
    echo -e "${GREEN}✅${RESET} $1"
}

print_fail() {
    echo -e "${RED}❌${RESET} $1"
    exit 1
}

print_info() {
    echo -e "${CYAN}ℹ️${RESET}  $1"
}

# Setup test environment mimicking a real installation
TEST_DIR=$(mktemp -d)
MOCK_ETC_ASTERISK="$TEST_DIR/etc/asterisk"
MOCK_OPT_RAYANPBX="$TEST_DIR/opt/rayanpbx"

# Set custom backup directory for testing
export BACKUP_DIR="$TEST_DIR/backups"

mkdir -p "$MOCK_ETC_ASTERISK"
mkdir -p "$MOCK_OPT_RAYANPBX"

print_header "E2E Test: Installer Upgrade Scenario"

print_info "Test environment: $TEST_DIR"
print_info "Mock Asterisk config: $MOCK_ETC_ASTERISK"
print_info "Mock RayanPBX install: $MOCK_OPT_RAYANPBX"
print_info "Backup directory: $BACKUP_DIR"

# Scenario 1: Fresh Installation
print_header "Scenario 1: Fresh Installation"

print_step "Creating initial Asterisk manager.conf..."
cat > "$MOCK_ETC_ASTERISK/manager.conf" << 'EOF'
[general]
enabled = no
port = 5038
bindaddr = 0.0.0.0

[admin]
secret = defaultSecret
deny=0.0.0.0/0.0.0.0
permit=127.0.0.1/255.255.255.255
EOF

print_step "Running installer (first time)..."
source scripts/ini-helper.sh

# First install - modify config
backup1=$(backup_config "$MOCK_ETC_ASTERISK/manager.conf")
set_ini_value "$MOCK_ETC_ASTERISK/manager.conf" "general" "enabled" "yes"
set_ini_value "$MOCK_ETC_ASTERISK/manager.conf" "general" "bindaddr" "127.0.0.1"
set_ini_value "$MOCK_ETC_ASTERISK/manager.conf" "admin" "secret" "rayanpbx_ami_secret"

backup_count=$(find "$BACKUP_DIR" -name "*.backup" 2>/dev/null | wc -l)
print_pass "Installation complete: $backup_count backup created"

# Scenario 2: First Upgrade (no config changes)
print_header "Scenario 2: First Upgrade (No Changes)"

sleep 1
print_step "Running installer again (upgrade scenario 1)..."

backup2=$(backup_config "$MOCK_ETC_ASTERISK/manager.conf")
set_ini_value "$MOCK_ETC_ASTERISK/manager.conf" "general" "enabled" "yes"
set_ini_value "$MOCK_ETC_ASTERISK/manager.conf" "general" "bindaddr" "127.0.0.1"
set_ini_value "$MOCK_ETC_ASTERISK/manager.conf" "admin" "secret" "rayanpbx_ami_secret"

backup_count2=$(find "$BACKUP_DIR" -name "*.backup" 2>/dev/null | wc -l)

# On first upgrade, a backup is created of the current (modified) state before re-applying settings
expected_count=$((backup_count + 1))
if [ "$backup_count2" -eq "$expected_count" ]; then
    print_pass "Backup created of current state ($backup_count2 backups total)"
    print_info "This is expected: we backup current state before modification"
else
    print_fail "Unexpected: backup count is $backup_count2 (expected $expected_count)"
fi

# Scenario 3: Second Upgrade (still no changes)
print_header "Scenario 3: Second Upgrade (No Changes)"

sleep 1
print_step "Running installer again (upgrade scenario 2)..."

backup3=$(backup_config "$MOCK_ETC_ASTERISK/manager.conf")
set_ini_value "$MOCK_ETC_ASTERISK/manager.conf" "general" "enabled" "yes"
set_ini_value "$MOCK_ETC_ASTERISK/manager.conf" "general" "bindaddr" "127.0.0.1"
set_ini_value "$MOCK_ETC_ASTERISK/manager.conf" "admin" "secret" "rayanpbx_ami_secret"

backup_count3=$(find "$BACKUP_DIR" -name "*.backup" 2>/dev/null | wc -l)

if [ "$backup_count3" -eq "$backup_count2" ]; then
    print_pass "Still no duplicate backup created (still $backup_count3 backups)"
else
    print_fail "Unexpected: backup count changed from $backup_count2 to $backup_count3"
fi

# Scenario 4: Upgrade with actual configuration change
print_header "Scenario 4: Upgrade with Config Change"

sleep 1
print_step "User manually changes config, then runs installer..."

# Simulate user changing a setting manually
set_ini_value "$MOCK_ETC_ASTERISK/manager.conf" "admin" "secret" "userCustomSecret"
print_info "User changed AMI secret manually"

backup4=$(backup_config "$MOCK_ETC_ASTERISK/manager.conf")

# Installer changes it back
set_ini_value "$MOCK_ETC_ASTERISK/manager.conf" "general" "enabled" "yes"
set_ini_value "$MOCK_ETC_ASTERISK/manager.conf" "general" "bindaddr" "127.0.0.1"
set_ini_value "$MOCK_ETC_ASTERISK/manager.conf" "admin" "secret" "rayanpbx_ami_secret"

backup_count4=$(find "$BACKUP_DIR" -name "*.backup" 2>/dev/null | wc -l)

if [ "$backup_count4" -gt "$backup_count3" ]; then
    print_pass "New backup created for different content ($backup_count3 -> $backup_count4)"
else
    print_fail "Expected new backup for different content, but count is $backup_count4"
fi

# Scenario 5: Another upgrade after the change
print_header "Scenario 5: Upgrade After Change"

sleep 1
print_step "Running installer again after previous change..."

backup5=$(backup_config "$MOCK_ETC_ASTERISK/manager.conf")
set_ini_value "$MOCK_ETC_ASTERISK/manager.conf" "general" "enabled" "yes"
set_ini_value "$MOCK_ETC_ASTERISK/manager.conf" "general" "bindaddr" "127.0.0.1"
set_ini_value "$MOCK_ETC_ASTERISK/manager.conf" "admin" "secret" "rayanpbx_ami_secret"

backup_count5=$(find "$BACKUP_DIR" -name "*.backup" 2>/dev/null | wc -l)

if [ "$backup_count5" -eq "$backup_count4" ]; then
    print_pass "No duplicate created after stabilization ($backup_count5 backups total)"
else
    print_fail "Unexpected backup count: $backup_count5 (expected $backup_count4)"
fi

# Final verification
print_header "Final Verification"

print_step "Listing all backup files..."
ls -lh "$BACKUP_DIR/"*.backup 2>/dev/null | awk '{print $9}' | while read -r file; do
    checksum=$(md5sum "$file" | awk '{print $1}')
    echo -e "  📄 $(basename "$file") - ${checksum:0:8}..."
done

print_step "Verifying backup uniqueness..."
declare -A checksums
duplicate_found=false

for backup in "$BACKUP_DIR/"*.backup; do
    if [ -f "$backup" ]; then
        checksum=$(md5sum "$backup" | awk '{print $1}')
        if [ -n "${checksums[$checksum]}" ]; then
            print_fail "Found duplicate backup with same checksum!"
            echo "  File 1: ${checksums[$checksum]}"
            echo "  File 2: $backup"
            duplicate_found=true
        fi
        checksums[$checksum]=$backup
    fi
done

if [ "$duplicate_found" = false ]; then
    print_pass "All backups are unique (no duplicates)"
fi

# Summary
print_header "Test Summary"

total_backups=$(find "$BACKUP_DIR" -name "*.backup" 2>/dev/null | wc -l)
unique_contents=${#checksums[@]}

echo -e "  Total backup files:   ${GREEN}$total_backups${RESET}"
echo -e "  Unique contents:      ${GREEN}$unique_contents${RESET}"
echo -e "  Expected backups:     ${CYAN}3${RESET} (original, final, user-modified)"

if [ "$total_backups" -le 4 ] && [ "$unique_contents" -le 4 ]; then
    print_pass "Backup count is reasonable (≤4 files for 5 installer runs)"
else
    print_fail "Too many backups created: $total_backups files"
fi

# Cleanup
rm -rf "$TEST_DIR"
print_info "Test environment cleaned up"

print_header "Result"
echo -e "${GREEN}${BOLD}✅ All E2E tests PASSED!${RESET}"
echo ""
echo -e "${CYAN}The backup deduplication feature successfully prevents${RESET}"
echo -e "${CYAN}cluttering during multiple installer runs while preserving${RESET}"
echo -e "${CYAN}important backups when content actually changes.${RESET}"
echo ""
