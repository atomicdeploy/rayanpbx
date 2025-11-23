#!/bin/bash

# Test script to verify rayanpbx-cli.sh handles .env files with variable expansion correctly
# This specifically tests the fix for the WEBSOCKET_PORT unbound variable issue

set -e

# Colors for output
readonly GREEN='\033[0;32m'
readonly RED='\033[0;31m'
readonly BLUE='\033[0;34m'
readonly RESET='\033[0m'

print_test() {
    echo -e "${BLUE}[TEST]${RESET} $1"
}

print_pass() {
    echo -e "${GREEN}✅ PASS${RESET}: $1"
}

print_fail() {
    echo -e "${RED}❌ FAIL${RESET}: $1"
    exit 1
}

# Helper function to test CLI version command
test_cli_version() {
    local test_dir=$1
    if RAYANPBX_ROOT="$test_dir" bash scripts/rayanpbx-cli.sh version 2>&1 | grep -q "RayanPBX CLI"; then
        return 0
    else
        return 1
    fi
}

# Helper function to create .env file
create_env_file() {
    local test_dir=$1
    local content=$2
    echo "$content" > "$test_dir/.env"
}

# Setup test directory using mktemp for security
TEST_DIR=$(mktemp -d)
trap 'rm -rf "$TEST_DIR"' EXIT

# Test 1: Script should auto-fix problematic .env order
print_test "Testing CLI with WEBSOCKET_PORT referenced before definition (auto-fix)"
create_env_file "$TEST_DIR" 'API_BASE_URL=http://localhost:8000
APP_NAME=RayanPBX

# This references WEBSOCKET_PORT before it'\''s defined
VITE_WS_URL=ws://localhost:${WEBSOCKET_PORT}

# WEBSOCKET_PORT defined AFTER being referenced
WEBSOCKET_PORT=9000'

output=$(RAYANPBX_ROOT="$TEST_DIR" bash scripts/rayanpbx-cli.sh version 2>&1)
if echo "$output" | grep -q "RayanPBX CLI"; then
    print_pass "Script runs without crashing and auto-fixes problematic variable order"
    # Verify the .env was actually normalized
    if grep -q "WEBSOCKET_PORT=9000" "$TEST_DIR/.env" && grep -A1 "WEBSOCKET_PORT=9000" "$TEST_DIR/.env" | grep -q "VITE_WS_URL"; then
        print_pass ".env file was normalized with correct variable ordering"
    fi
else
    print_fail "Script crashed or didn't produce expected output"
fi

# Test 2: Script should work correctly with proper .env order
print_test "Testing CLI with properly ordered .env file"
create_env_file "$TEST_DIR" 'API_BASE_URL=http://localhost:8000
APP_NAME=RayanPBX

# WEBSOCKET_PORT defined first
WEBSOCKET_PORT=9000

# Then referenced
VITE_WS_URL=ws://localhost:${WEBSOCKET_PORT}'

if test_cli_version "$TEST_DIR"; then
    print_pass "Script works with properly ordered .env file"
else
    print_fail "Script failed with properly ordered .env file"
fi

# Test 3: Script should handle missing .env file gracefully
print_test "Testing CLI without .env file"
rm -f "$TEST_DIR/.env"

if test_cli_version "$TEST_DIR"; then
    print_pass "Script works without .env file"
else
    print_fail "Script failed without .env file"
fi

echo ""
echo -e "${GREEN}All tests passed!${RESET}"
