#!/bin/bash

# Test script for rayanpbx-cli enhancements
# Tests banner display, help system, and config commands

# Don't exit on errors - we want to test all cases
set +e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CLI_SCRIPT="$SCRIPT_DIR/rayanpbx-cli.sh"
RAYANPBX_ROOT="$(dirname "$SCRIPT_DIR")"
ENV_FILE="$RAYANPBX_ROOT/.env"

# Colors for output
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m'

passed=0
failed=0

print_test() {
    echo -e "${YELLOW}[TEST]${NC} $1"
}

print_pass() {
    echo -e "${GREEN}[PASS]${NC} $1"
    ((passed++))
}

print_fail() {
    echo -e "${RED}[FAIL]${NC} $1"
    ((failed++))
}

# Ensure .env exists
if [ ! -f "$ENV_FILE" ]; then
    cp "$RAYANPBX_ROOT/.env.example" "$ENV_FILE"
fi

echo "========================================="
echo "  rayanpbx-cli Enhancement Tests"
echo "========================================="
echo ""

# Test 1: Banner display (without figlet to avoid terminal output complexity)
print_test "Test 1: CLI runs without errors"
output=$(RAYANPBX_ROOT="$RAYANPBX_ROOT" CLI_USE_FIGLET=false bash "$CLI_SCRIPT" 2>&1)
if echo "$output" | grep -q "rayanpbx-cli"; then
    print_pass "CLI displays usage message"
else
    print_fail "CLI failed to display usage message"
fi

# Test 2: Help command
print_test "Test 2: Help command displays command reference"
output=$(RAYANPBX_ROOT="$RAYANPBX_ROOT" CLI_USE_FIGLET=false bash "$CLI_SCRIPT" help 2>&1)
if echo "$output" | grep -q "Command Reference"; then
    print_pass "Help command works"
else
    print_fail "Help command failed"
fi

# Test 3: Help with specific command
print_test "Test 3: Help for specific command (config)"
if RAYANPBX_ROOT="$RAYANPBX_ROOT" CLI_USE_FIGLET=false bash "$CLI_SCRIPT" help config 2>&1 | grep -q "Configuration Management"; then
    print_pass "Command-specific help works"
else
    print_fail "Command-specific help failed"
fi

# Test 4: Version command
print_test "Test 4: Version command"
if RAYANPBX_ROOT="$RAYANPBX_ROOT" CLI_USE_FIGLET=false bash "$CLI_SCRIPT" version 2>&1 | grep -q "RayanPBX CLI"; then
    print_pass "Version command works"
else
    print_fail "Version command failed"
fi

# Test 5: Config get command
print_test "Test 5: Config get command"
result=$(RAYANPBX_ROOT="$RAYANPBX_ROOT" bash "$CLI_SCRIPT" config get APP_NAME 2>&1)
if [ "$result" = "RayanPBX" ]; then
    print_pass "Config get works (got: $result)"
else
    print_fail "Config get failed (got: $result)"
fi

# Test 6: Config set command
print_test "Test 6: Config set command"
if RAYANPBX_ROOT="$RAYANPBX_ROOT" bash "$CLI_SCRIPT" config set TEST_CLI_KEY test_cli_value 2>&1 | grep -q "Added\|Updated"; then
    print_pass "Config set works"
else
    print_fail "Config set failed"
fi

# Test 7: Verify config set by getting the value
print_test "Test 7: Verify config set by getting value"
result=$(RAYANPBX_ROOT="$RAYANPBX_ROOT" bash "$CLI_SCRIPT" config get TEST_CLI_KEY 2>&1)
if [ "$result" = "test_cli_value" ]; then
    print_pass "Config set+get roundtrip works"
else
    print_fail "Config set+get roundtrip failed (got: $result)"
fi

# Test 8: Config update existing value
print_test "Test 8: Config update existing value"
if RAYANPBX_ROOT="$RAYANPBX_ROOT" bash "$CLI_SCRIPT" config set TEST_CLI_KEY updated_value 2>&1 | grep -q "Updated"; then
    result=$(RAYANPBX_ROOT="$RAYANPBX_ROOT" bash "$CLI_SCRIPT" config get TEST_CLI_KEY 2>&1)
    if [ "$result" = "updated_value" ]; then
        print_pass "Config update works"
    else
        print_fail "Config update failed (got: $result)"
    fi
else
    print_fail "Config update command failed"
fi

# Test 9: Config list command
print_test "Test 9: Config list command"
if RAYANPBX_ROOT="$RAYANPBX_ROOT" bash "$CLI_SCRIPT" config list 2>&1 | grep -q "APP_NAME"; then
    print_pass "Config list works"
else
    print_fail "Config list failed"
fi

# Test 10: Config list masks passwords
print_test "Test 10: Config list masks sensitive values"
if RAYANPBX_ROOT="$RAYANPBX_ROOT" bash "$CLI_SCRIPT" config list 2>&1 | grep "DB_PASSWORD" | grep -q "\*\*\*\*"; then
    print_pass "Config list masks passwords"
else
    print_fail "Config list doesn't mask passwords properly"
fi

# Test 11: Figlet banner display (if figlet is available)
print_test "Test 11: Figlet banner display"
if command -v figlet &> /dev/null; then
    if RAYANPBX_ROOT="$RAYANPBX_ROOT" CLI_USE_FIGLET=true bash "$CLI_SCRIPT" version 2>&1 | grep -E "____.*____"; then
        print_pass "Figlet banner displays"
    else
        print_fail "Figlet banner doesn't display"
    fi
else
    echo "  SKIP: figlet not installed"
fi

# Clean up test key
if grep -q "TEST_CLI_KEY=" "$ENV_FILE"; then
    # Create a backup before cleanup
    cp "$ENV_FILE" "${ENV_FILE}.test_backup"
    sed -i '/TEST_CLI_KEY=/d' "$ENV_FILE" || {
        echo "Warning: Failed to clean up test key, restoring backup"
        mv "${ENV_FILE}.test_backup" "$ENV_FILE"
    }
    rm -f "${ENV_FILE}.test_backup"
fi

echo ""
echo "========================================="
echo "  Test Results"
echo "========================================="
echo -e "${GREEN}Passed: $passed${NC}"
echo -e "${RED}Failed: $failed${NC}"
echo ""

if [ $failed -eq 0 ]; then
    echo -e "${GREEN}✅ All tests passed!${NC}"
    exit 0
else
    echo -e "${RED}❌ Some tests failed${NC}"
    exit 1
fi
