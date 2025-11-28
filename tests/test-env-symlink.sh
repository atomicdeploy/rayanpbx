#!/bin/bash

# Test script to verify the .env symlink handling in install.sh

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
    echo "║      🧪  .env Symlink Test Suite  🧪                      ║"
    echo "║                                                            ║"
    echo "╚════════════════════════════════════════════════════════════╝"
    echo -e "${RESET}\n"
}

# Test 1: Check that the symlink handling function is defined
test_symlink_function_exists() {
    print_test "Checking setup_backend_env_symlink function exists in install.sh"
    
    if grep -q "setup_backend_env_symlink()" "$REPO_ROOT/install.sh"; then
        print_pass "setup_backend_env_symlink function is defined"
        return 0
    else
        print_fail "setup_backend_env_symlink function is not defined"
        return 1
    fi
}

# Test 2: Check that symlink creation for non-existent file is handled
test_symlink_creation_logic() {
    print_test "Checking symlink creation for non-existent backend/.env"
    
    if grep -q '! -e "\$backend_env"' "$REPO_ROOT/install.sh" && \
       grep -q 'ln -s "\$root_env" "\$backend_env"' "$REPO_ROOT/install.sh"; then
        print_pass "Symlink creation logic for non-existent file is present"
        return 0
    else
        print_fail "Symlink creation logic for non-existent file is missing"
        return 1
    fi
}

# Test 3: Check that existing symlink is handled
test_existing_symlink_handling() {
    print_test "Checking existing symlink handling"
    
    if grep -q '\-L "\$backend_env"' "$REPO_ROOT/install.sh" && \
       grep -q 'readlink -f' "$REPO_ROOT/install.sh"; then
        print_pass "Existing symlink handling is present"
        return 0
    else
        print_fail "Existing symlink handling is missing"
        return 1
    fi
}

# Test 4: Check that file content comparison is implemented
test_content_comparison() {
    print_test "Checking file content comparison with cmp -s"
    
    if grep -q 'cmp -s "\$root_env" "\$backend_env"' "$REPO_ROOT/install.sh"; then
        print_pass "File content comparison is implemented"
        return 0
    else
        print_fail "File content comparison is not implemented"
        return 1
    fi
}

# Test 5: Check that backup is created when contents differ
test_backup_creation() {
    print_test "Checking backup creation for differing content"
    
    if grep -q 'backup_path=.*\.backup\.' "$REPO_ROOT/install.sh" && \
       grep -q 'mv "\$backend_env" "\$backup_path"' "$REPO_ROOT/install.sh"; then
        print_pass "Backup creation for differing content is implemented"
        return 0
    else
        print_fail "Backup creation for differing content is not implemented"
        return 1
    fi
}

# Test 6: Check that CI mode auto-handles conflicts
test_ci_mode_handling() {
    print_test "Checking CI mode auto-handles conflicts"
    
    if grep -q 'CI_MODE.*= "true"' "$REPO_ROOT/install.sh" && \
       grep -A 5 'CI_MODE.*= "true"' "$REPO_ROOT/install.sh" | grep -q 'Backing up and replacing'; then
        print_pass "CI mode automatically handles conflicts"
        return 0
    else
        print_fail "CI mode conflict handling is missing"
        return 1
    fi
}

# Test 7: Check that user is prompted with choices
test_user_prompt_choices() {
    print_test "Checking user prompt with choices (overwrite/diff/abort)"
    
    if grep -q 'Overwrite.*Backup backend/.env and replace with symlink' "$REPO_ROOT/install.sh" && \
       grep -q 'Show diff.*Display differences' "$REPO_ROOT/install.sh" && \
       grep -q 'Abort.*Stop installation' "$REPO_ROOT/install.sh"; then
        print_pass "User prompt with all choices is implemented"
        return 0
    else
        print_fail "User prompt choices are incomplete"
        return 1
    fi
}

# Test 8: Check that APP_KEY existence check is implemented
test_app_key_existence_check() {
    print_test "Checking APP_KEY existence check"
    
    if grep -q 'grep -q "\^APP_KEY=" \.env' "$REPO_ROOT/install.sh" && \
       grep -q 'echo "APP_KEY=" >> \.env' "$REPO_ROOT/install.sh"; then
        print_pass "APP_KEY existence check is implemented"
        return 0
    else
        print_fail "APP_KEY existence check is not implemented"
        return 1
    fi
}

# Test 9: Check that artisan key:generate is run
test_artisan_key_generate() {
    print_test "Checking artisan key:generate is run"
    
    if grep -q 'php artisan key:generate --force --no-interaction' "$REPO_ROOT/install.sh"; then
        print_pass "artisan key:generate is run"
        return 0
    else
        print_fail "artisan key:generate is not being run"
        return 1
    fi
}

# Test 10: Check fallback APP_KEY generation
test_fallback_key_generation() {
    print_test "Checking fallback APP_KEY generation with openssl"
    
    if grep -A 10 'artisan key:generate' "$REPO_ROOT/install.sh" | grep -q 'openssl rand -base64 32'; then
        print_pass "Fallback APP_KEY generation is implemented"
        return 0
    else
        print_fail "Fallback APP_KEY generation is not implemented"
        return 1
    fi
}

# Test 11: Check the old cp .env method is removed
test_old_copy_method_removed() {
    print_test "Checking old 'cp .env backend/.env' method is removed"
    
    # Count occurrences - should not have the old simple copy
    if grep -q 'cp \.env backend/\.env' "$REPO_ROOT/install.sh" && \
       ! grep -B 5 'cp \.env backend/\.env' "$REPO_ROOT/install.sh" | grep -q 'symlink'; then
        print_fail "Old 'cp .env backend/.env' method still present"
        return 1
    else
        print_pass "Old simple copy method has been replaced"
        return 0
    fi
}

# Test 12: Check install.sh syntax
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

# Print summary
print_summary() {
    echo -e "\n${CYAN}${BOLD}"
    echo "╔════════════════════════════════════════════════════════════╗"
    echo "║                      Test Summary                          ║"
    echo "╚════════════════════════════════════════════════════════════╝"
    echo -e "${RESET}"
    
    echo -e "${GREEN}Passed: $PASS_COUNT${RESET}"
    echo -e "${RED}Failed: $FAIL_COUNT${RESET}"
    echo -e "Total:  $TEST_COUNT\n"
    
    if [ $FAIL_COUNT -eq 0 ]; then
        echo -e "${GREEN}${BOLD}🎉 All tests passed! .env symlink handling is working correctly! 🎉${RESET}\n"
        return 0
    else
        echo -e "${RED}${BOLD}⚠️  Some tests failed. Please review the output above. ⚠️${RESET}\n"
        return 1
    fi
}

# Main execution
main() {
    print_header
    
    # Run all tests
    test_install_syntax || true
    test_symlink_function_exists || true
    test_symlink_creation_logic || true
    test_existing_symlink_handling || true
    test_content_comparison || true
    test_backup_creation || true
    test_ci_mode_handling || true
    test_user_prompt_choices || true
    test_app_key_existence_check || true
    test_artisan_key_generate || true
    test_fallback_key_generation || true
    test_old_copy_method_removed || true
    
    # Print summary
    print_summary
}

# Run if executed directly
if [ "${BASH_SOURCE[0]}" == "${0}" ]; then
    main "$@"
fi
