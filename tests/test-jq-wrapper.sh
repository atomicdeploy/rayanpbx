#!/bin/bash

# Test script for jq-wrapper.sh functionality
# Tests the jq debugging wrapper and error handling

set +e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
JQ_WRAPPER="$SCRIPT_DIR/../scripts/jq-wrapper.sh"

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

echo "========================================="
echo "  jq-wrapper.sh Tests"
echo "========================================="
echo ""

# Test 1: Valid JSON parsing works
print_test "Test 1: Valid JSON parsing"
source "$JQ_WRAPPER"
result=$(echo '{"name": "test", "value": 123}' | jq -r '.name')
if [ "$result" == "test" ]; then
    print_pass "Valid JSON parsed correctly"
else
    print_fail "Valid JSON parsing failed (got: $result)"
fi

# Test 2: Invalid JSON shows error details
print_test "Test 2: Invalid JSON error handling"
error_output=$(echo 'not valid json' | jq '.field' 2>&1)
if echo "$error_output" | grep -q "jq failed\|jq Error Details"; then
    print_pass "Invalid JSON error details shown"
else
    print_fail "Invalid JSON should show error details"
fi

# Test 3: Empty input handling
print_test "Test 3: Empty input handling"
error_output=$(echo '' | jq '.' 2>&1)
# Empty input to jq is valid (returns null)
if [ $? -eq 0 ] || echo "$error_output" | grep -q "stdin was empty\|jq stdin"; then
    print_pass "Empty input handled"
else
    print_fail "Empty input handling failed"
fi

# Test 4: GitHub issue body generation (check the function exists)
print_test "Test 4: Issue body function exists"
if type _jq_wrapper_create_issue_body &>/dev/null; then
    print_pass "Issue body function exists"
else
    print_fail "Issue body function not found"
fi

# Test 5: Stacktrace function exists
print_test "Test 5: Stacktrace function exists"
if type _jq_wrapper_stacktrace &>/dev/null; then
    print_pass "Stacktrace function exists"
else
    print_fail "Stacktrace function not found"
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
