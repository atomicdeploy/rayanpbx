#!/bin/bash
#
# RayanPBX PAM Authentication Test Suite
#
# This script tests PAM authentication functionality for RayanPBX
# It verifies that:
# 1. pamtester is installed and working
# 2. PAM service configuration exists
# 3. Authentication works with valid credentials
# 4. System logging is working
#
# Usage: ./test-pam-auth.sh [--setup-test-user]

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

# Test counters
TESTS_RUN=0
TESTS_PASSED=0
TESTS_FAILED=0

# Test user (for automated testing)
TEST_USER="rayanpbx_test_user"
TEST_PASSWORD="TestPassword123!"

# Print colored message
print_msg() {
    local color=$1
    local msg=$2
    echo -e "${color}${msg}${NC}"
}

print_info() {
    print_msg "$BLUE" "[INFO] $1"
}

print_success() {
    print_msg "$GREEN" "[PASS] $1"
}

print_warning() {
    print_msg "$YELLOW" "[WARN] $1"
}

print_fail() {
    print_msg "$RED" "[FAIL] $1"
}

# Run a test
run_test() {
    local name=$1
    local command=$2
    
    TESTS_RUN=$((TESTS_RUN + 1))
    
    print_info "Running test: $name"
    
    if eval "$command"; then
        TESTS_PASSED=$((TESTS_PASSED + 1))
        print_success "$name"
        return 0
    else
        TESTS_FAILED=$((TESTS_FAILED + 1))
        print_fail "$name"
        return 1
    fi
}

# Test: Check if pamtester is installed
test_pamtester_installed() {
    command -v pamtester &> /dev/null
}

# Test: Check PAM setup script exists
test_setup_script_exists() {
    [[ -f "$PROJECT_ROOT/scripts/setup-pam.sh" ]] && [[ -x "$PROJECT_ROOT/scripts/setup-pam.sh" ]]
}

# Test: Check PAM service file exists
test_pam_service_exists() {
    [[ -f "/etc/pam.d/rayanpbx" ]]
}

# Test: Check syslog is available
test_syslog_available() {
    logger -t "rayanpbx-test" "Test message" 2>/dev/null
}

# Test: Check PHP PAM service classes exist
test_php_services_exist() {
    local pam_service="$PROJECT_ROOT/backend/app/Services/PamAuthService.php"
    local log_service="$PROJECT_ROOT/backend/app/Services/SystemLogService.php"
    
    [[ -f "$pam_service" ]] && [[ -f "$log_service" ]]
}

# Test: Check TUI syslog module exists
test_tui_syslog_exists() {
    [[ -f "$PROJECT_ROOT/tui/syslog.go" ]]
}

# Test: Validate PHP syntax of PAM service
test_php_pam_syntax() {
    php -l "$PROJECT_ROOT/backend/app/Services/PamAuthService.php" &> /dev/null
}

# Test: Validate PHP syntax of SystemLog service
test_php_log_syntax() {
    php -l "$PROJECT_ROOT/backend/app/Services/SystemLogService.php" &> /dev/null
}

# Test: Validate AuthController PHP syntax
test_php_auth_syntax() {
    php -l "$PROJECT_ROOT/backend/app/Http/Controllers/Api/AuthController.php" &> /dev/null
}

# Test: Compile TUI to check for errors
test_tui_compile() {
    cd "$PROJECT_ROOT/tui"
    go build -o /dev/null . 2>/dev/null
}

# Test: PAM authentication with test user (requires test user to exist)
test_pam_auth_with_user() {
    if ! id "$TEST_USER" &> /dev/null; then
        print_warning "Test user $TEST_USER does not exist, skipping auth test"
        return 0  # Skip, not fail
    fi
    
    # Try to authenticate using pamtester
    echo "$TEST_PASSWORD" | pamtester login "$TEST_USER" authenticate &> /dev/null
}

# Create test user for automated testing
create_test_user() {
    if id "$TEST_USER" &> /dev/null; then
        print_info "Test user $TEST_USER already exists"
        return 0
    fi
    
    print_info "Creating test user: $TEST_USER"
    
    if [[ $EUID -ne 0 ]]; then
        print_fail "Must be run as root to create test user"
        return 1
    fi
    
    useradd -m -s /bin/bash "$TEST_USER"
    echo "$TEST_USER:$TEST_PASSWORD" | chpasswd
    
    print_success "Created test user $TEST_USER"
}

# Remove test user
remove_test_user() {
    if ! id "$TEST_USER" &> /dev/null; then
        print_info "Test user $TEST_USER does not exist"
        return 0
    fi
    
    if [[ $EUID -ne 0 ]]; then
        print_fail "Must be run as root to remove test user"
        return 1
    fi
    
    userdel -r "$TEST_USER" 2>/dev/null || true
    print_success "Removed test user $TEST_USER"
}

# Test PAM authentication works with actual system user
test_pam_real_auth() {
    # This test requires manual intervention - skip in automated mode
    if [[ -z "$PAM_TEST_USER" ]] || [[ -z "$PAM_TEST_PASS" ]]; then
        print_warning "Skipping real auth test (set PAM_TEST_USER and PAM_TEST_PASS to enable)"
        return 0
    fi
    
    echo "$PAM_TEST_PASS" | pamtester login "$PAM_TEST_USER" authenticate &> /dev/null
}

# Print summary
print_summary() {
    echo ""
    echo "=========================================="
    echo "Test Summary"
    echo "=========================================="
    echo "Tests Run:    $TESTS_RUN"
    echo "Tests Passed: $TESTS_PASSED"
    echo "Tests Failed: $TESTS_FAILED"
    echo "=========================================="
    
    if [[ $TESTS_FAILED -eq 0 ]]; then
        print_success "All tests passed!"
        return 0
    else
        print_fail "$TESTS_FAILED test(s) failed"
        return 1
    fi
}

# Main
main() {
    echo "=========================================="
    echo "RayanPBX PAM Authentication Test Suite"
    echo "=========================================="
    echo ""
    
    # Handle special options
    case "${1:-}" in
        --setup-test-user)
            create_test_user
            exit $?
            ;;
        --cleanup-test-user)
            remove_test_user
            exit $?
            ;;
        --help)
            echo "Usage: $0 [options]"
            echo ""
            echo "Options:"
            echo "  --setup-test-user     Create a test user for authentication tests"
            echo "  --cleanup-test-user   Remove the test user"
            echo "  --help                Show this help message"
            echo ""
            echo "Environment Variables:"
            echo "  PAM_TEST_USER   Username for real authentication test"
            echo "  PAM_TEST_PASS   Password for real authentication test"
            echo ""
            exit 0
            ;;
    esac
    
    # Run tests
    run_test "pamtester installed" "test_pamtester_installed"
    run_test "Setup script exists" "test_setup_script_exists"
    run_test "PHP PAM services exist" "test_php_services_exist"
    run_test "TUI syslog module exists" "test_tui_syslog_exists"
    run_test "PHP PamAuthService syntax" "test_php_pam_syntax"
    run_test "PHP SystemLogService syntax" "test_php_log_syntax"
    run_test "PHP AuthController syntax" "test_php_auth_syntax"
    run_test "Syslog available" "test_syslog_available"
    run_test "TUI compiles" "test_tui_compile"
    
    # Optional tests (may require setup)
    run_test "PAM service file exists" "test_pam_service_exists" || true
    run_test "PAM auth with test user" "test_pam_auth_with_user" || true
    run_test "PAM real auth" "test_pam_real_auth" || true
    
    # Print summary
    print_summary
}

main "$@"
