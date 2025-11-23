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

# Setup test directory
TEST_DIR="/tmp/rayanpbx-cli-test-$$"
mkdir -p "$TEST_DIR"

# Test 1: Script should not crash with problematic .env order
print_test "Testing CLI with WEBSOCKET_PORT referenced before definition"
cat > "$TEST_DIR/.env" << 'EOF'
API_BASE_URL=http://localhost:8000
APP_NAME=RayanPBX

# This references WEBSOCKET_PORT before it's defined
VITE_WS_URL=ws://localhost:${WEBSOCKET_PORT}

# WEBSOCKET_PORT defined AFTER being referenced
WEBSOCKET_PORT=9000
EOF

if RAYANPBX_ROOT="$TEST_DIR" bash scripts/rayanpbx-cli.sh version 2>&1 | grep -q "RayanPBX CLI"; then
    print_pass "Script runs without crashing even with problematic variable order"
else
    print_fail "Script crashed or didn't produce expected output"
fi

# Test 2: Script should work correctly with proper .env order
print_test "Testing CLI with properly ordered .env file"
cat > "$TEST_DIR/.env" << 'EOF'
API_BASE_URL=http://localhost:8000
APP_NAME=RayanPBX

# WEBSOCKET_PORT defined first
WEBSOCKET_PORT=9000

# Then referenced
VITE_WS_URL=ws://localhost:${WEBSOCKET_PORT}
EOF

if RAYANPBX_ROOT="$TEST_DIR" bash scripts/rayanpbx-cli.sh version 2>&1 | grep -q "RayanPBX CLI"; then
    print_pass "Script works with properly ordered .env file"
else
    print_fail "Script failed with properly ordered .env file"
fi

# Test 3: Script should handle missing .env file gracefully
print_test "Testing CLI without .env file"
rm -f "$TEST_DIR/.env"

if RAYANPBX_ROOT="$TEST_DIR" bash scripts/rayanpbx-cli.sh version 2>&1 | grep -q "RayanPBX CLI"; then
    print_pass "Script works without .env file"
else
    print_fail "Script failed without .env file"
fi

# Cleanup
rm -rf "$TEST_DIR"

echo ""
echo -e "${GREEN}All tests passed!${RESET}"
