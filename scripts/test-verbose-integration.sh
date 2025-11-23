#!/bin/bash

# Integration test to demonstrate the verbose flag preservation fix
# This script simulates a real-world scenario where install.sh detects an update
# and restarts itself, verifying that the --verbose flag is preserved.

set -e

# Colors
readonly GREEN='\033[0;32m'
readonly RED='\033[0;31m'
readonly YELLOW='\033[1;33m'
readonly CYAN='\033[0;36m'
readonly BOLD='\033[1m'
readonly DIM='\033[2m'
readonly RESET='\033[0m'

print_header() {
    clear
    echo -e "${CYAN}${BOLD}"
    echo "╔════════════════════════════════════════════════════════════╗"
    echo "║                                                            ║"
    echo "║   Integration Test: Verbose Flag Preservation             ║"
    echo "║                                                            ║"
    echo "╚════════════════════════════════════════════════════════════╝"
    echo -e "${RESET}\n"
}

print_step() {
    echo -e "\n${CYAN}▶ $1${RESET}"
}

print_success() {
    echo -e "${GREEN}✅ $1${RESET}"
}

print_info() {
    echo -e "${CYAN}ℹ️  $1${RESET}"
}

print_warn() {
    echo -e "${YELLOW}⚠️  $1${RESET}"
}

print_error() {
    echo -e "${RED}❌ $1${RESET}"
}

print_header

print_info "This test demonstrates that the --verbose flag is preserved"
print_info "when install.sh restarts itself after detecting an update."
echo ""

# Create a temporary test environment
TEST_DIR=$(mktemp -d)
if [ -z "$TEST_DIR" ] || [ ! -d "$TEST_DIR" ]; then
    print_error "Failed to create temporary directory"
    exit 1
fi
echo -e "${DIM}Test directory: $TEST_DIR${RESET}"

cd "$TEST_DIR"

# Create a git repository
print_step "Step 1: Creating mock git repository"
git init > /dev/null 2>&1
git config user.email "test@test.com"
git config user.name "Test User"
print_success "Git repository initialized"

# Create initial version of install script
print_step "Step 2: Creating initial version of install script"
cat > install.sh << 'INSTALL_V1'
#!/bin/bash
set -e

# Save original arguments before parsing
ORIGINAL_ARGS=("$@")

VERBOSE=false

# Parse arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        -v|--verbose)
            VERBOSE=true
            shift
            ;;
        *)
            shift
            ;;
    esac
done

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if [ "$VERBOSE" = true ]; then
    echo "[VERBOSE] Script started with verbose mode enabled"
    echo "[VERBOSE] SCRIPT_DIR=$SCRIPT_DIR"
fi

echo "Current version: 1.0"

# Simulate checking for updates
if [ -d "$SCRIPT_DIR/.git" ]; then
    cd "$SCRIPT_DIR"
    
    # Check if there's a "remote" update
    if [ -f "$SCRIPT_DIR/.has-update" ]; then
        echo "Updates available!"
        read -p "Pull updates and restart? (y/n) " -n 1 -r
        echo
        if [[ $REPLY =~ ^[Yy]$ ]]; then
            echo "Pulling updates..."
            
            # Simulate update
            cat > install.sh << 'INSTALL_V2'
#!/bin/bash
set -e

# Save original arguments before parsing
ORIGINAL_ARGS=("$@")

VERBOSE=false

# Parse arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        -v|--verbose)
            VERBOSE=true
            shift
            ;;
        *)
            shift
            ;;
    esac
done

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if [ "$VERBOSE" = true ]; then
    echo "[VERBOSE] Script RESTARTED with verbose mode enabled"
    echo "[VERBOSE] SCRIPT_DIR=$SCRIPT_DIR"
fi

echo "Current version: 2.0 (UPDATED!)"

echo ""
if [ "$VERBOSE" = true ]; then
    echo "SUCCESS: Verbose flag was preserved across restart!"
else
    echo "FAILURE: Verbose flag was NOT preserved!"
    exit 1
fi

exit 0
INSTALL_V2
            
            chmod +x install.sh
            
            echo "Updates pulled successfully"
            echo "Restarting installation with latest version..."
            sleep 1
            
            # Restart with original args (THE FIX!)
            exec "$SCRIPT_DIR/$(basename "${BASH_SOURCE[0]}")" "${ORIGINAL_ARGS[@]}"
        fi
    else
        echo "Already at latest version"
    fi
fi
INSTALL_V1

chmod +x install.sh
touch .has-update
print_success "Initial install script created (v1.0)"

# Commit initial version
git add install.sh .has-update
git commit -m "Initial version" > /dev/null 2>&1
print_success "Committed initial version"

# Test 1: Run without verbose flag
print_step "Step 3: Test 1 - Running without --verbose flag"
echo -e "${DIM}Command: ./install.sh${RESET}"
echo ""

OUTPUT1=$(echo "y" | ./install.sh 2>&1 || true)
echo "$OUTPUT1"
echo ""

if echo "$OUTPUT1" | grep -q "version: 1.0"; then
    print_success "Test 1 setup complete"
else
    print_error "Test 1 failed"
fi

# Reset for Test 2
git checkout install.sh > /dev/null 2>&1
touch .has-update

# Test 2: Run WITH verbose flag
print_step "Step 4: Test 2 - Running WITH --verbose flag (THE FIX TEST)"
echo -e "${DIM}Command: ./install.sh --verbose${RESET}"
echo ""

OUTPUT2=$(echo "y" | ./install.sh --verbose 2>&1 || true)
echo "$OUTPUT2"
echo ""

# Verify the fix worked
if echo "$OUTPUT2" | grep -q "\[VERBOSE\] Script RESTARTED"; then
    print_success "✓ Verbose mode detected in restarted script"
    VERBOSE_PRESERVED=true
else
    print_error "✗ Verbose mode NOT detected in restarted script"
    VERBOSE_PRESERVED=false
fi

if echo "$OUTPUT2" | grep -q "SUCCESS: Verbose flag was preserved"; then
    print_success "✓ Script confirms verbose flag was preserved"
    SUCCESS_CONFIRMED=true
else
    print_error "✗ Script does NOT confirm verbose flag preservation"
    SUCCESS_CONFIRMED=false
fi

# Test 3: Test with -v flag (short form)
git checkout install.sh > /dev/null 2>&1
touch .has-update

print_step "Step 5: Test 3 - Testing with -v flag (short form)"
echo -e "${DIM}Command: ./install.sh -v${RESET}"
echo ""

OUTPUT3=$(echo "y" | ./install.sh -v 2>&1 || true)
echo "$OUTPUT3"
echo ""

if echo "$OUTPUT3" | grep -q "SUCCESS: Verbose flag was preserved"; then
    print_success "✓ Short form -v flag also works correctly"
    SHORT_FLAG_WORKS=true
else
    print_error "✗ Short form -v flag did NOT work"
    SHORT_FLAG_WORKS=false
fi

# Cleanup
cd /
rm -rf "$TEST_DIR"

# Final summary
print_step "Test Results Summary"
echo ""

TOTAL_TESTS=3
PASSED_TESTS=0

if [ "$VERBOSE_PRESERVED" = true ]; then
    echo -e "${GREEN}✓ Test 1: Verbose mode detected in restarted script${RESET}"
    ((PASSED_TESTS++))
else
    echo -e "${RED}✗ Test 1: Verbose mode NOT detected in restarted script${RESET}"
fi

if [ "$SUCCESS_CONFIRMED" = true ]; then
    echo -e "${GREEN}✓ Test 2: Script confirms verbose flag preservation${RESET}"
    ((PASSED_TESTS++))
else
    echo -e "${RED}✗ Test 2: Script does NOT confirm verbose flag preservation${RESET}"
fi

if [ "$SHORT_FLAG_WORKS" = true ]; then
    echo -e "${GREEN}✓ Test 3: Short form -v flag works correctly${RESET}"
    ((PASSED_TESTS++))
else
    echo -e "${RED}✗ Test 3: Short form -v flag did NOT work${RESET}"
fi

echo ""
echo -e "${BOLD}Results: ${GREEN}$PASSED_TESTS${RESET}/${TOTAL_TESTS} tests passed${RESET}"
echo ""

if [ "$PASSED_TESTS" -eq "$TOTAL_TESTS" ]; then
    echo -e "${GREEN}${BOLD}🎉 SUCCESS! All integration tests passed! 🎉${RESET}"
    echo ""
    echo -e "${CYAN}The fix correctly preserves the --verbose flag when install.sh"
    echo -e "restarts itself after detecting an update.${RESET}"
    exit 0
else
    echo -e "${RED}${BOLD}⚠️  Some integration tests failed${RESET}"
    exit 1
fi
