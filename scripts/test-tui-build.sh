#!/bin/bash

# Test script to verify TUI build process doesn't modify go.mod
# This test ensures the fix for go.mod modification issue works correctly

set -e

# Colors for output
readonly GREEN='\033[0;32m'
readonly RED='\033[0;31m'
readonly BLUE='\033[0;34m'
readonly YELLOW='\033[1;33m'
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

print_info() {
    echo -e "${YELLOW}[INFO]${RESET} $1"
}

# Check if Go is installed
if ! command -v go &> /dev/null; then
    print_fail "Go is not installed"
fi

GO_VERSION=$(go version | awk '{print $3}' | sed 's/go//' || echo "unknown")
print_info "Go version: $GO_VERSION"

# Get repository root
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
TUI_DIR="$REPO_ROOT/tui"

cd "$TUI_DIR"

# Test 1: Verify go.mod exists and has correct version
print_test "Checking go.mod version requirement"
if ! grep -q "^go 1.22$" go.mod; then
    print_fail "go.mod should require Go 1.22"
fi
print_pass "go.mod requires Go 1.22"

# Test 2: Verify required dependencies are present
print_test "Checking required dependencies in go.mod"
if ! grep -q "github.com/golang-jwt/jwt/v5" go.mod; then
    print_fail "Missing dependency: github.com/golang-jwt/jwt/v5"
fi
if ! grep -q "github.com/gorilla/websocket" go.mod; then
    print_fail "Missing dependency: github.com/gorilla/websocket"
fi
print_pass "All required dependencies present in go.mod"

# Test 3: Save go.mod hash before build
print_test "Building TUI and checking go.mod doesn't change"
GO_MOD_HASH_BEFORE=$(md5sum go.mod | awk '{print $1}')
GO_SUM_HASH_BEFORE=$(md5sum go.sum | awk '{print $1}')

# Build with GOTOOLCHAIN=local (as install.sh does)
export GOTOOLCHAIN=local
BUILD_DIR=$(mktemp -d)
trap 'rm -rf "$BUILD_DIR"' EXIT

if go build -o "$BUILD_DIR/rayanpbx-tui" . 2>&1; then
    print_pass "TUI build successful"
else
    print_fail "TUI build failed"
fi

# Test 4: Verify go.mod wasn't modified
GO_MOD_HASH_AFTER=$(md5sum go.mod | awk '{print $1}')
GO_SUM_HASH_AFTER=$(md5sum go.sum | awk '{print $1}')

if [ "$GO_MOD_HASH_BEFORE" != "$GO_MOD_HASH_AFTER" ]; then
    print_fail "go.mod was modified during build!"
fi
print_pass "go.mod unchanged after build"

if [ "$GO_SUM_HASH_BEFORE" != "$GO_SUM_HASH_AFTER" ]; then
    print_fail "go.sum was modified during build!"
fi
print_pass "go.sum unchanged after build"

# Test 5: Build websocket server
print_test "Building WebSocket server"
if go build -o "$BUILD_DIR/rayanpbx-ws" websocket.go config.go 2>&1; then
    print_pass "WebSocket server build successful"
else
    print_fail "WebSocket server build failed"
fi

# Test 6: Verify binaries are executable
print_test "Verifying built binaries"
if [ ! -x "$BUILD_DIR/rayanpbx-tui" ]; then
    print_fail "rayanpbx-tui is not executable"
fi
if [ ! -x "$BUILD_DIR/rayanpbx-ws" ]; then
    print_fail "rayanpbx-ws is not executable"
fi
print_pass "Built binaries are executable"

# Test 7: Verify TUI binary runs
print_test "Running TUI --version"
if "$BUILD_DIR/rayanpbx-tui" --version 2>&1 | grep -q "RayanPBX TUI"; then
    print_pass "TUI binary runs correctly"
else
    print_fail "TUI binary doesn't run correctly"
fi

# Test 8: Verify no git changes
print_test "Checking for git changes in tui/ directory"
cd "$REPO_ROOT"
if git diff --quiet tui/go.mod tui/go.sum 2>/dev/null; then
    print_pass "No git changes detected in tui/ directory"
else
    print_info "Git changes detected (this is expected if you haven't committed go.mod changes)"
    git diff tui/go.mod tui/go.sum
fi

echo ""
echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${RESET}"
echo -e "${GREEN}✅ All tests passed!${RESET}"
echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${RESET}"
echo ""
