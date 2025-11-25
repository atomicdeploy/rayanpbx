#!/bin/bash

# Test script to verify comprehensive health check functionality in health-check.sh and install.sh

set -e

# Colors
readonly GREEN='\033[0;32m'
readonly RED='\033[0;31m'
readonly YELLOW='\033[1;33m'
readonly CYAN='\033[0;36m'
readonly BOLD='\033[1m'
readonly RESET='\033[0m'

TEST_COUNT=0
PASS_COUNT=0
FAIL_COUNT=0

# Get script directory
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

print_test() {
    ((TEST_COUNT++))
    echo -e "${CYAN}[TEST $TEST_COUNT]${RESET} $1"
}

print_pass() {
    ((PASS_COUNT++))
    echo -e "${GREEN}✅ PASS${RESET}: $1"
}

print_fail() {
    ((FAIL_COUNT++))
    echo -e "${RED}❌ FAIL${RESET}: $1"
}

print_header() {
    echo -e "${CYAN}${BOLD}"
    echo "╔════════════════════════════════════════════════════════════╗"
    echo "║                                                            ║"
    echo "║      🧪  Comprehensive Health Check Test Suite  🧪        ║"
    echo "║                                                            ║"
    echo "╚════════════════════════════════════════════════════════════╝"
    echo -e "${RESET}\n"
}

# Test 1: Check health-check.sh syntax
test_health_check_syntax() {
    print_test "Checking health-check.sh syntax"
    
    if bash -n "$REPO_ROOT/scripts/health-check.sh" 2>&1; then
        print_pass "health-check.sh has valid syntax"
        return 0
    else
        print_fail "health-check.sh has syntax errors"
        return 1
    fi
}

# Test 2: Check install.sh syntax
test_install_syntax() {
    print_test "Checking install.sh syntax"
    
    if bash -n "$REPO_ROOT/install.sh" 2>&1; then
        print_pass "install.sh has valid syntax"
        return 0
    else
        print_fail "install.sh has syntax errors"
        return 1
    fi
}

# Test 3: Verify check_ami_health function exists in health-check.sh
test_ami_health_function_exists() {
    print_test "Verifying check_ami_health function exists in health-check.sh"
    
    if grep -q "^check_ami_health()" "$REPO_ROOT/scripts/health-check.sh"; then
        print_pass "check_ami_health function is defined"
        return 0
    else
        print_fail "check_ami_health function not found"
        return 1
    fi
}

# Test 4: Verify fix_ami_configuration function exists
test_fix_ami_function_exists() {
    print_test "Verifying fix_ami_configuration function exists in health-check.sh"
    
    if grep -q "^fix_ami_configuration()" "$REPO_ROOT/scripts/health-check.sh"; then
        print_pass "fix_ami_configuration function is defined"
        return 0
    else
        print_fail "fix_ami_configuration function not found"
        return 1
    fi
}

# Test 5: Verify check_and_fix_ami function exists
test_check_and_fix_ami_function_exists() {
    print_test "Verifying check_and_fix_ami function exists in health-check.sh"
    
    if grep -q "^check_and_fix_ami()" "$REPO_ROOT/scripts/health-check.sh"; then
        print_pass "check_and_fix_ami function is defined"
        return 0
    else
        print_fail "check_and_fix_ami function not found"
        return 1
    fi
}

# Test 6: Verify check-ami command is in main case statement
test_check_ami_command_registered() {
    print_test "Verifying check-ami command is registered in main function"
    
    if grep -q "check-ami)" "$REPO_ROOT/scripts/health-check.sh"; then
        print_pass "check-ami command is registered in case statement"
        return 0
    else
        print_fail "check-ami command not found in case statement"
        return 1
    fi
}

# Test 7: Verify AMI check is part of full-check
test_ami_check_in_full_check() {
    print_test "Verifying AMI check is included in full-check command"
    
    if grep -A 30 "full-check)" "$REPO_ROOT/scripts/health-check.sh" | grep -q "check_and_fix_ami"; then
        print_pass "AMI check is included in full-check"
        return 0
    else
        print_fail "AMI check is not included in full-check"
        return 1
    fi
}

# Test 8: Verify AMI check is in install.sh health-check step
test_ami_check_in_install() {
    print_test "Verifying AMI check is in install.sh health-check step"
    
    if grep -q "Checking Asterisk AMI socket" "$REPO_ROOT/install.sh"; then
        print_pass "AMI socket check is in install.sh health-check step"
        return 0
    else
        print_fail "AMI socket check is not in install.sh health-check step"
        return 1
    fi
}

# Test 9: Verify AMI warning message exists in install.sh
test_ami_warning_exists() {
    print_test "Verifying AMI warning message exists in install.sh"
    
    if grep -q "WARNING: AMI Socket" "$REPO_ROOT/install.sh"; then
        print_pass "AMI warning message exists in install.sh"
        return 0
    else
        print_fail "AMI warning message not found in install.sh"
        return 1
    fi
}

# Test 10: Verify health-check.sh help includes check-ami
test_help_includes_ami() {
    print_test "Verifying health-check.sh help includes check-ami command"
    
    HELP_OUTPUT=$("$REPO_ROOT/scripts/health-check.sh" 2>&1 || true)
    if echo "$HELP_OUTPUT" | grep -q "check-ami"; then
        print_pass "Help output includes check-ami command"
        return 0
    else
        print_fail "Help output does not include check-ami command"
        return 1
    fi
}

# Test 11: Verify AMI configuration references manager.conf
test_ami_config_references_manager_conf() {
    print_test "Verifying fix_ami_configuration references manager.conf"
    
    if grep -A 50 "^fix_ami_configuration()" "$REPO_ROOT/scripts/health-check.sh" | grep -q "manager.conf"; then
        print_pass "fix_ami_configuration references manager.conf"
        return 0
    else
        print_fail "fix_ami_configuration does not reference manager.conf"
        return 1
    fi
}

# Test 12: Verify install.sh reads AMI credentials from .env
test_install_reads_ami_credentials() {
    print_test "Verifying install.sh reads AMI credentials from .env"
    
    if grep -q "ASTERISK_AMI_HOST" "$REPO_ROOT/install.sh" && \
       grep -q "ASTERISK_AMI_PORT" "$REPO_ROOT/install.sh" && \
       grep -q "ASTERISK_AMI_SECRET" "$REPO_ROOT/install.sh"; then
        print_pass "install.sh reads AMI credentials from .env"
        return 0
    else
        print_fail "install.sh does not properly read AMI credentials from .env"
        return 1
    fi
}

# Test 13: Verify Database health check is in install.sh
test_database_health_check_in_install() {
    print_test "Verifying Database health check is in install.sh"
    
    if grep -q "Checking Database" "$REPO_ROOT/install.sh"; then
        print_pass "Database health check is in install.sh"
        return 0
    else
        print_fail "Database health check is not in install.sh"
        return 1
    fi
}

# Test 14: Verify Redis health check is in install.sh
test_redis_health_check_in_install() {
    print_test "Verifying Redis health check is in install.sh"
    
    if grep -q "Checking Redis" "$REPO_ROOT/install.sh"; then
        print_pass "Redis health check is in install.sh"
        return 0
    else
        print_fail "Redis health check is not in install.sh"
        return 1
    fi
}

# Test 15: Verify install.sh reads database credentials from .env
test_install_reads_database_credentials() {
    print_test "Verifying install.sh reads database credentials from .env"
    
    if grep -q "DB_HOST" "$REPO_ROOT/install.sh" && \
       grep -q "DB_USERNAME" "$REPO_ROOT/install.sh" && \
       grep -q "DB_PASSWORD" "$REPO_ROOT/install.sh"; then
        print_pass "install.sh reads database credentials from .env"
        return 0
    else
        print_fail "install.sh does not properly read database credentials from .env"
        return 1
    fi
}

# Test 16: Verify Redis connectivity check exists in install.sh
test_redis_connectivity_check() {
    print_test "Verifying Redis connectivity check (redis-cli ping) exists in install.sh"
    
    if grep -q "redis-cli ping" "$REPO_ROOT/install.sh"; then
        print_pass "Redis connectivity check exists in install.sh"
        return 0
    else
        print_fail "Redis connectivity check does not exist in install.sh"
        return 1
    fi
}

# Print summary
print_summary() {
    echo ""
    echo -e "${CYAN}${BOLD}════════════════════════════════════════════════════════════${RESET}"
    echo -e "${BOLD}Test Summary:${RESET}"
    echo -e "  Total:  $TEST_COUNT"
    echo -e "  ${GREEN}Passed: $PASS_COUNT${RESET}"
    echo -e "  ${RED}Failed: $FAIL_COUNT${RESET}"
    echo -e "${CYAN}${BOLD}════════════════════════════════════════════════════════════${RESET}"
    echo ""
    
    if [ $FAIL_COUNT -eq 0 ]; then
        echo -e "${GREEN}${BOLD}✅ All tests passed!${RESET}"
        return 0
    else
        echo -e "${RED}${BOLD}❌ Some tests failed!${RESET}"
        return 1
    fi
}

# Main test execution
main() {
    print_header
    
    test_health_check_syntax || true
    test_install_syntax || true
    test_ami_health_function_exists || true
    test_fix_ami_function_exists || true
    test_check_and_fix_ami_function_exists || true
    test_check_ami_command_registered || true
    test_ami_check_in_full_check || true
    test_ami_check_in_install || true
    test_ami_warning_exists || true
    test_help_includes_ami || true
    test_ami_config_references_manager_conf || true
    test_install_reads_ami_credentials || true
    test_database_health_check_in_install || true
    test_redis_health_check_in_install || true
    test_install_reads_database_credentials || true
    test_redis_connectivity_check || true
    
    print_summary
}

main "$@"
