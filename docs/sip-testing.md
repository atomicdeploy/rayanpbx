# SIP Extension Testing Guide

This guide explains how to use the comprehensive SIP extension testing features in RayanPBX.

## Table of Contents

1. [Overview](#overview)
2. [Supported Tools](#supported-tools)
3. [Installation](#installation)
4. [Usage](#usage)
   - [CLI Testing](#cli-testing)
   - [TUI Testing](#tui-testing)
   - [Web UI Testing](#web-ui-testing)
   - [Direct Script Usage](#direct-script-usage)
5. [Test Types](#test-types)
6. [Troubleshooting](#troubleshooting)
7. [Examples](#examples)

## Overview

RayanPBX provides robust functionality to test SIP extension operations using both external tools and built-in commands. The testing suite allows you to:

- **Test SIP registration** - Verify that extensions can register with Asterisk
- **Test calls between extensions** - Ensure call establishment and audio path work correctly
- **Run comprehensive test suites** - Execute multiple tests in sequence
- **Check tool availability** - See which SIP testing tools are installed
- **Install testing tools** - Easily install pjsua, sipsak, or sipp

Testing can be performed through:
- **Command Line Interface (CLI)** - Quick command-line testing
- **Terminal User Interface (TUI)** - Interactive menu-driven testing
- **Web User Interface** - Browser-based testing (coming soon)
- **Direct Script** - Scriptable testing for automation

## Supported Tools

The testing suite supports multiple SIP testing clients:

### pjsua (Recommended)
- **Description**: Full-featured SIP user agent from PJSIP project
- **Capabilities**: Registration, call testing, audio path verification
- **Best for**: Comprehensive testing with call establishment
- **Installation**: `sudo apt-get install pjsua` or use RayanPBX installer

### sipsak
- **Description**: SIP Swiss Army Knife
- **Capabilities**: Quick SIP OPTIONS ping, registration testing
- **Best for**: Fast connectivity tests and OPTIONS pings
- **Installation**: `sudo apt-get install sipsak` or use RayanPBX installer

### sipexer
- **Description**: Modern SIP testing tool
- **Capabilities**: Registration testing, various SIP methods
- **Best for**: Advanced SIP protocol testing
- **Installation**: Manual from https://github.com/miconda/sipexer

### sipp
- **Description**: SIP performance testing tool
- **Capabilities**: Load testing, scenarios
- **Best for**: Performance and stress testing
- **Installation**: `sudo apt-get install sipp` or use RayanPBX installer

## Installation

### Install via CLI

```bash
# List available tools
rayanpbx-cli sip-test tools

# Install pjsua (recommended)
rayanpbx-cli sip-test install pjsua

# Install sipsak
rayanpbx-cli sip-test install sipsak

# Install sipp
rayanpbx-cli sip-test install sipp
```

### Install via TUI

1. Launch TUI: `rayanpbx-cli tui`
2. Navigate to: **Diagnostics** → **SIP Testing Suite**
3. Select: **Check Available Tools**
4. Follow on-screen instructions to install

### Manual Installation

```bash
# Ubuntu/Debian
sudo apt-get update
sudo apt-get install pjsua sipsak sipp

# CentOS/RHEL
sudo yum install pjsua sipsak sipp
```

## Usage

### CLI Testing

The CLI provides quick command-line access to SIP testing:

#### Check Available Tools
```bash
rayanpbx-cli sip-test tools
```

#### Test Registration
```bash
# Test on local server (127.0.0.1)
rayanpbx-cli sip-test register 101 mypassword

# Test on remote server
rayanpbx-cli sip-test register 101 mypassword 192.168.1.100
```

#### Test Call Between Extensions
```bash
# Test call on local server
rayanpbx-cli sip-test call 101 pass1 102 pass2

# Test call on remote server
rayanpbx-cli sip-test call 101 pass1 102 pass2 192.168.1.100
```

#### Run Full Test Suite
```bash
# Run all tests with two extensions
rayanpbx-cli sip-test full 101 pass1 102 pass2

# Run all tests on remote server
rayanpbx-cli sip-test full 101 pass1 102 pass2 192.168.1.100
```

### TUI Testing

The TUI provides an interactive, menu-driven interface:

#### Launch TUI
```bash
rayanpbx-cli tui
```

#### Navigate to SIP Testing
1. Select **Diagnostics** from main menu
2. Select **SIP Testing Suite**
3. Choose your desired test type:
   - Check Available Tools
   - Install SIP Tool
   - Test Registration
   - Test Call
   - Run Full Test Suite

#### Test Registration via TUI
1. Select **Test Registration**
2. Enter extension number
3. Enter password
4. Enter server (or press Enter for default 127.0.0.1)
5. Press Enter to execute
6. View results on screen

#### Test Call via TUI
1. Select **Test Call**
2. Enter caller extension
3. Enter caller password
4. Enter destination extension
5. Enter destination password
6. Enter server (optional)
7. Press Enter to execute
8. View results with troubleshooting hints

### Web UI Testing

**Coming Soon** - Web-based interface for SIP testing with visual result display.

### Direct Script Usage

For automation or advanced usage, call the script directly:

```bash
# Show help
/opt/rayanpbx/scripts/sip-test-suite.sh --help

# List available tools
/opt/rayanpbx/scripts/sip-test-suite.sh tools

# Test registration
/opt/rayanpbx/scripts/sip-test-suite.sh register 101 password

# Test call
/opt/rayanpbx/scripts/sip-test-suite.sh call 101 pass1 102 pass2

# Full test suite
/opt/rayanpbx/scripts/sip-test-suite.sh full 101 pass1 102 pass2

# With verbose output
/opt/rayanpbx/scripts/sip-test-suite.sh -v register 101 password

# With custom server and port
/opt/rayanpbx/scripts/sip-test-suite.sh -s 192.168.1.100 -p 5060 register 101 password
```

## Test Types

### Registration Test

Tests if an extension can successfully register with the SIP server.

**What it checks:**
- SIP registration request/response
- Authentication (username/password)
- Network connectivity to SIP server
- Server response codes (200 OK, 401 Unauthorized, etc.)

**Success criteria:**
- Extension receives 200 OK response
- Registration remains active

**Troubleshooting hints provided:**
- Authentication failures (401/403)
- Network issues (timeouts)
- Extension not found (404)

### Call Test

Tests if two extensions can establish a call.

**What it checks:**
- Both extensions register successfully
- INVITE request/response
- Call establishment (CONFIRMED state)
- Audio path (null audio for testing)
- Call teardown

**Success criteria:**
- Both extensions register
- Caller sends INVITE
- Receiver answers automatically
- Call reaches CONFIRMED state

**Troubleshooting hints provided:**
- Call not answered (480/486)
- Destination not found (404)
- Service unavailable (503)
- Dialplan issues

### Full Test Suite

Runs comprehensive tests including:
1. SIP OPTIONS ping (if sipsak available)
2. Registration test for extension 1
3. Registration test for extension 2
4. Call test from extension 1 to extension 2

**Success criteria:**
- All individual tests pass
- Summary shows 0 failures

## Troubleshooting

### Common Issues

#### "No SIP testing tools available"
**Solution**: Install at least one tool:
```bash
rayanpbx-cli sip-test install pjsua
```

#### "Registration failed - Authentication failed"
**Causes**:
- Incorrect username or password
- Extension not configured in Asterisk
- PJSIP configuration error

**Solutions**:
1. Verify extension credentials in database
2. Check PJSIP configuration: `/etc/asterisk/pjsip.conf`
3. Reload Asterisk: `asterisk -rx "pjsip reload"`

#### "Registration failed - Network issue"
**Causes**:
- Asterisk not running
- Port 5060 not listening
- Firewall blocking SIP traffic

**Solutions**:
1. Check Asterisk status: `systemctl status asterisk`
2. Verify port: `netstat -tunlp | grep 5060`
3. Check firewall: `ufw status` or `firewall-cmd --list-ports`

#### "Call failed - Service unavailable"
**Causes**:
- Dialplan not configured
- Extension context incorrect
- No route to destination

**Solutions**:
1. Check dialplan: `/etc/asterisk/extensions.conf`
2. Verify context in PJSIP config matches dialplan
3. Reload dialplan: `asterisk -rx "dialplan reload"`

#### "Extension not found"
**Causes**:
- Extension not created in database
- PJSIP endpoint not configured
- Configuration not reloaded

**Solutions**:
1. Create extension via Web UI or CLI
2. Verify endpoint: `asterisk -rx "pjsip show endpoint 101"`
3. Reload PJSIP: `asterisk -rx "pjsip reload"`

### Debugging Tips

#### Enable SIP debugging
```bash
# Via Asterisk CLI
asterisk -rx "pjsip set logger on"

# Via RayanPBX CLI
rayanpbx-cli asterisk command "pjsip set logger on"
```

#### Check extension status
```bash
# Via Asterisk CLI
asterisk -rx "pjsip show endpoint 101"
asterisk -rx "pjsip show registrations"

# Via RayanPBX CLI
rayanpbx-cli extension status 101
```

#### View Asterisk logs
```bash
# Follow live logs
tail -f /var/log/asterisk/full

# Search for errors
grep -i error /var/log/asterisk/full
```

## Examples

### Example 1: Test New Extension

After creating a new extension 101, test it:

```bash
# 1. Check if Asterisk sees the endpoint
rayanpbx-cli asterisk command "pjsip show endpoint 101"

# 2. Test registration
rayanpbx-cli sip-test register 101 secretpass

# 3. If successful, test with another extension
rayanpbx-cli sip-test call 101 secretpass 102 otherpass
```

### Example 2: Troubleshoot Registration Issues

Extension won't register from phone:

```bash
# 1. Test registration from server
rayanpbx-cli sip-test register 101 password

# 2. If server test passes, issue is with phone or network
# 3. If server test fails, check Asterisk configuration

# 4. Enable SIP debugging
rayanpbx-cli asterisk command "pjsip set logger on"

# 5. Try registration again and watch logs
tail -f /var/log/asterisk/full
```

### Example 3: Pre-Production Testing

Before deploying to production:

```bash
# Create test extensions
rayanpbx-cli extension create 9001 "Test User 1" testpass1
rayanpbx-cli extension create 9002 "Test User 2" testpass2

# Run full test suite
rayanpbx-cli sip-test full 9001 testpass1 9002 testpass2

# If all tests pass, system is ready
# If tests fail, investigate and fix issues

# Clean up test extensions after testing
# (via Web UI or database)
```

### Example 4: Remote Server Testing

Test extensions on a remote RayanPBX server:

```bash
# Test registration on remote server
rayanpbx-cli sip-test register 101 password 192.168.1.100

# Test call on remote server
rayanpbx-cli sip-test call 101 pass1 102 pass2 192.168.1.100

# Full suite on remote server
rayanpbx-cli sip-test full 101 pass1 102 pass2 192.168.1.100
```

### Example 5: Automated Testing Script

Create a test script for CI/CD:

```bash
#!/bin/bash
# automated-sip-test.sh

set -e

echo "Starting SIP extension tests..."

# Test critical extensions
EXTENSIONS=("101:pass1" "102:pass2" "103:pass3")

for ext_info in "${EXTENSIONS[@]}"; do
    IFS=':' read -r ext pass <<< "$ext_info"
    echo "Testing extension $ext..."
    
    if rayanpbx-cli sip-test register "$ext" "$pass"; then
        echo "✅ Extension $ext: PASS"
    else
        echo "❌ Extension $ext: FAIL"
        exit 1
    fi
done

# Test call between two extensions
echo "Testing call: 101 -> 102..."
if rayanpbx-cli sip-test call 101 pass1 102 pass2; then
    echo "✅ Call test: PASS"
else
    echo "❌ Call test: FAIL"
    exit 1
fi

echo "All tests passed successfully!"
```

## Best Practices

1. **Always test after configuration changes** - Run tests after modifying PJSIP or dialplan configs
2. **Test with pjsua when possible** - It provides the most comprehensive testing
3. **Use verbose mode for troubleshooting** - Add `-v` flag to see detailed output
4. **Test both registration and calls** - Registration alone doesn't guarantee calls will work
5. **Keep test extensions** - Maintain dedicated test extensions for ongoing validation
6. **Automate testing** - Include SIP tests in your deployment/CI pipeline
7. **Document test results** - Keep records of test outcomes for auditing

## API Reference

For programmatic access, use the REST API endpoints:

```bash
# Check available tools
curl -X GET http://localhost:8000/api/sip-test/tools

# Test registration
curl -X POST http://localhost:8000/api/sip-test/registration \
  -H "Content-Type: application/json" \
  -d '{"extension":"101","password":"pass","server":"127.0.0.1"}'

# Test call
curl -X POST http://localhost:8000/api/sip-test/call \
  -H "Content-Type: application/json" \
  -d '{"from_extension":"101","from_password":"pass1",
       "to_extension":"102","to_password":"pass2"}'

# Full test suite
curl -X POST http://localhost:8000/api/sip-test/full \
  -H "Content-Type: application/json" \
  -d '{"extension1":"101","password1":"pass1",
       "extension2":"102","password2":"pass2"}'
```

## Related Documentation

- [PJSIP Setup Guide](PJSIP_SETUP_GUIDE.md)
- [CLI Command Reference](COMMAND_LINE_OPTIONS.md)
- [API Quick Reference](API_QUICK_REFERENCE.md)
- [Troubleshooting Guide](README.md#troubleshooting)

## Support

For issues or questions:
1. Check this documentation
2. Review troubleshooting section
3. Check Asterisk logs
4. Open an issue on GitHub: https://github.com/atomicdeploy/rayanpbx/issues

## Contributing

Contributions to improve SIP testing are welcome! Please submit pull requests or open issues for:
- Additional test scenarios
- Support for more SIP tools
- Improved error messages
- Better troubleshooting guidance
