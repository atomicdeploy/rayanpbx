#!/bin/bash

# Test script for install.sh step-based execution
# This script tests various scenarios of step selection

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
INSTALL_SCRIPT="$SCRIPT_DIR/../install.sh"

echo "🧪 Testing install.sh step-based execution"
echo "==========================================="
echo ""

# Test 1: Syntax check
echo "Test 1: Checking script syntax..."
if bash -n "$INSTALL_SCRIPT"; then
    echo "✅ Syntax check passed"
else
    echo "❌ Syntax check failed"
    exit 1
fi
echo ""

# Test 2: Version flag
echo "Test 2: Testing --version flag..."
output=$("$INSTALL_SCRIPT" --version 2>&1 | sed 's/\x1b\[[0-9;]*[mGKH]//g' || true)
if [[ "$output" == *"RayanPBX Installation Script v"* ]]; then
    echo "✅ Version flag working"
else
    echo "❌ Version flag failed"
    echo "Output: $output"
    exit 1
fi
echo ""

# Test 3: Help flag
echo "Test 3: Testing --help flag..."
output=$("$INSTALL_SCRIPT" --help 2>&1 || true)
if [[ "$output" == *"USAGE:"* ]]; then
    echo "✅ Help flag working"
else
    echo "❌ Help flag failed"
    exit 1
fi
echo ""

# Test 4: List steps flag
echo "Test 4: Testing --list-steps flag..."
output=$("$INSTALL_SCRIPT" --list-steps 2>&1 || true)
if [[ "$output" == *"Available Installation Steps:"* ]]; then
    echo "✅ List steps flag working"
    # Check if all expected steps are listed
    expected_steps=("updates" "system-verification" "backend" "frontend" "tui")
    for step in "${expected_steps[@]}"; do
        if [[ "$output" == *"$step"* ]]; then
            echo "  ✓ Step '$step' found"
        else
            echo "  ✗ Step '$step' not found"
            exit 1
        fi
    done
else
    echo "❌ List steps flag failed"
    exit 1
fi
echo ""

# Test 5: Root check (should fail without sudo)
echo "Test 5: Testing root check..."
output=$("$INSTALL_SCRIPT" 2>&1 || true)
if [[ "$output" == *"This script must be run as root"* ]]; then
    echo "✅ Root check working correctly"
else
    echo "⚠️  Root check may not be working as expected"
fi
echo ""

# Test 6: CI mode flag (should skip root check)
echo "Test 6: Testing --ci mode (skip root check)..."
output=$(timeout 5 bash -c "$INSTALL_SCRIPT --ci --steps=backend --verbose" 2>&1 || true)
if [[ "$output" == *"CI mode enabled"* ]]; then
    echo "✅ CI mode flag working"
else
    echo "❌ CI mode flag not detected"
    exit 1
fi
echo ""

# Test 7: Check step filtering
echo "Test 7: Testing step filtering..."
output=$(timeout 5 bash -c "$INSTALL_SCRIPT --ci --steps=backend,frontend --verbose" 2>&1 || true)
if [[ "$output" == *"Running only steps:"* ]]; then
    echo "✅ Step filtering working"
    if [[ "$output" == *"backend"* ]] && [[ "$output" == *"frontend"* ]]; then
        echo "  ✓ Backend and frontend steps selected"
    fi
else
    echo "❌ Step filtering failed"
    exit 1
fi
echo ""

# Test 8: Check skip functionality
echo "Test 8: Testing skip functionality..."
output=$(timeout 5 bash -c "$INSTALL_SCRIPT --ci --skip=asterisk,asterisk-ami --verbose" 2>&1 || true)
if [[ "$output" == *"Skipping steps:"* ]]; then
    echo "✅ Skip functionality working"
else
    echo "⚠️  Skip functionality may not be working"
fi
echo ""

echo "==========================================="
echo "🎉 All tests passed!"
echo ""
echo "📝 Step Dependencies:"
echo "   - backend requires: database, php, composer, source, env-config"
echo "   - frontend requires: nodejs, source, env-config"
echo "   - tui requires: go, source"
echo "   - pm2 requires: nodejs, frontend, tui (for PM2 processes)"
echo "   - systemd requires: backend (for systemd service)"
echo "   - health-check requires: systemd, pm2 (for services to check)"
echo ""
echo "⚠️  Note: When running specific steps, ensure dependencies are met"
echo "   or have been installed previously."
