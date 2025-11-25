#!/bin/bash

# RayanPBX SIP Testing Suite
# Comprehensive SIP extension testing with multiple client support
# Supports: pjsua, sipsak, sipexer, sipp

set -e

# Colors and formatting
readonly GREEN='\033[0;32m'
readonly RED='\033[0;31m'
readonly YELLOW='\033[1;33m'
readonly BLUE='\033[0;34m'
readonly CYAN='\033[0;36m'
readonly BOLD='\033[1m'
readonly RESET='\033[0m'

# Test results tracking
declare -a TEST_RESULTS
TEST_COUNT=0
PASS_COUNT=0
FAIL_COUNT=0

# Default configuration
DEFAULT_SERVER="127.0.0.1"
DEFAULT_PORT="5060"
DEFAULT_TIMEOUT=10
VERBOSE=false

# Print functions
print_header() {
    echo -e "${CYAN}${BOLD}"
    echo "╔════════════════════════════════════════════════════════════╗"
    echo "║                                                            ║"
    echo "║        🧪  RayanPBX SIP Testing Suite  🧪                 ║"
    echo "║                                                            ║"
    echo "╚════════════════════════════════════════════════════════════╝"
    echo -e "${RESET}\n"
}

print_test() {
    ((TEST_COUNT++))
    echo -e "${BLUE}${BOLD}[TEST $TEST_COUNT]${RESET} $1"
}

print_pass() {
    ((PASS_COUNT++))
    echo -e "${GREEN}✅ PASS${RESET}: $1"
    TEST_RESULTS+=("PASS: $1")
}

print_fail() {
    ((FAIL_COUNT++))
    echo -e "${RED}❌ FAIL${RESET}: $1"
    TEST_RESULTS+=("FAIL: $1")
}

print_info() {
    echo -e "${CYAN}ℹ️  $1${RESET}"
}

print_warning() {
    echo -e "${YELLOW}⚠️  $1${RESET}"
}

print_verbose() {
    if [ "$VERBOSE" = true ]; then
        echo -e "${BLUE}[DEBUG]${RESET} $1"
    fi
}

# Check if a command exists
command_exists() {
    command -v "$1" &> /dev/null
}

# Detect available SIP testing tools
detect_available_tools() {
    local tools=()
    
    if command_exists pjsua; then
        tools+=("pjsua")
    fi
    
    if command_exists sipsak; then
        tools+=("sipsak")
    fi
    
    if command_exists sipexer; then
        tools+=("sipexer")
    fi
    
    if command_exists sipp; then
        tools+=("sipp")
    fi
    
    echo "${tools[@]}"
}

# Install SIP testing tools
install_tools() {
    local tool=$1
    
    print_info "Installing $tool..."
    
    case "$tool" in
        pjsua)
            if command_exists apt-get; then
                apt-get update -qq
                apt-get install -y pjsua > /dev/null 2>&1
            elif command_exists yum; then
                yum install -y pjsua > /dev/null 2>&1
            else
                print_fail "Package manager not supported"
                return 1
            fi
            ;;
        sipsak)
            if command_exists apt-get; then
                apt-get update -qq
                apt-get install -y sipsak > /dev/null 2>&1
            elif command_exists yum; then
                yum install -y sipsak > /dev/null 2>&1
            else
                print_fail "Package manager not supported"
                return 1
            fi
            ;;
        sipexer)
            print_warning "sipexer installation requires manual setup"
            print_info "Visit: https://github.com/miconda/sipexer"
            return 1
            ;;
        sipp)
            if command_exists apt-get; then
                apt-get update -qq
                apt-get install -y sipp > /dev/null 2>&1
            elif command_exists yum; then
                yum install -y sipp > /dev/null 2>&1
            else
                print_fail "Package manager not supported"
                return 1
            fi
            ;;
        *)
            print_fail "Unknown tool: $tool"
            return 1
            ;;
    esac
    
    if command_exists "$tool"; then
        print_pass "$tool installed successfully"
        return 0
    else
        print_fail "Failed to install $tool"
        return 1
    fi
}

# Test SIP registration using PJSUA
test_registration_pjsua() {
    local extension=$1
    local password=$2
    local server=${3:-$DEFAULT_SERVER}
    local port=${4:-$DEFAULT_PORT}
    
    print_test "Testing SIP registration (PJSUA): $extension@$server:$port"
    
    # Create PJSUA config
    local config_file="/tmp/pjsua-reg-${extension}.cfg"
    cat > "$config_file" <<EOF
--id sip:${extension}@${server}
--registrar sip:${server}:${port}
--realm *
--username ${extension}
--password ${password}
--auto-answer 200
--duration 5
--null-audio
EOF
    
    # Run PJSUA with timeout
    local log_file="/tmp/pjsua-reg-${extension}.log"
    timeout $DEFAULT_TIMEOUT pjsua --config-file "$config_file" > "$log_file" 2>&1 &
    local pid=$!
    
    sleep 3
    
    # Check registration status
    if grep -q "registration success\|Registration successful\|SIP/2.0 200" "$log_file"; then
        print_pass "Registration successful for $extension"
        kill $pid 2>/dev/null || true
        rm -f "$config_file" "$log_file"
        return 0
    else
        print_fail "Registration failed for $extension"
        print_info "Check log: $log_file"
        
        # Provide troubleshooting hints
        if grep -q "401\|403" "$log_file"; then
            print_warning "Authentication failed - check username/password"
        elif grep -q "timeout\|No route" "$log_file"; then
            print_warning "Network issue - check server connectivity"
        elif grep -q "404" "$log_file"; then
            print_warning "Extension not found on server"
        fi
        
        kill $pid 2>/dev/null || true
        return 1
    fi
}

# Test SIP registration using SIPSAK
test_registration_sipsak() {
    local extension=$1
    local password=$2
    local server=${3:-$DEFAULT_SERVER}
    local port=${4:-$DEFAULT_PORT}
    
    print_test "Testing SIP registration (SIPSAK): $extension@$server:$port"
    
    # Use sipsak to test registration
    local log_file="/tmp/sipsak-reg-${extension}.log"
    
    if sipsak -U -s "sip:${extension}@${server}:${port}" \
        -a "${password}" -u "${extension}" > "$log_file" 2>&1; then
        print_pass "Registration test successful for $extension"
        rm -f "$log_file"
        return 0
    else
        print_fail "Registration test failed for $extension"
        print_info "Check log: $log_file"
        return 1
    fi
}

# Test SIP call using PJSUA
test_call_pjsua() {
    local from_ext=$1
    local from_pass=$2
    local to_ext=$3
    local to_pass=$4
    local server=${5:-$DEFAULT_SERVER}
    local port=${6:-$DEFAULT_PORT}
    
    print_test "Testing call: $from_ext -> $to_ext (PJSUA)"
    
    # Start receiver
    local receiver_config="/tmp/pjsua-recv-${to_ext}.cfg"
    cat > "$receiver_config" <<EOF
--id sip:${to_ext}@${server}
--registrar sip:${server}:${port}
--realm *
--username ${to_ext}
--password ${to_pass}
--auto-answer 200
--duration 15
--null-audio
EOF
    
    local receiver_log="/tmp/pjsua-recv-${to_ext}.log"
    timeout 20 pjsua --config-file "$receiver_config" > "$receiver_log" 2>&1 &
    local receiver_pid=$!
    
    sleep 3
    
    # Start caller
    local caller_config="/tmp/pjsua-call-${from_ext}.cfg"
    cat > "$caller_config" <<EOF
--id sip:${from_ext}@${server}
--registrar sip:${server}:${port}
--realm *
--username ${from_ext}
--password ${from_pass}
--duration 8
--null-audio
sip:${to_ext}@${server}:${port}
EOF
    
    local caller_log="/tmp/pjsua-call-${from_ext}.log"
    timeout 15 pjsua --config-file "$caller_config" > "$caller_log" 2>&1 &
    local caller_pid=$!
    
    sleep 6
    
    # Check if call was established
    local call_success=false
    if grep -q "Call.*CONFIRMED\|Call.*is EARLY\|Call.*CONNECTING" "$caller_log"; then
        call_success=true
    fi
    
    if grep -q "Call.*CONFIRMED\|incoming call\|auto-answer" "$receiver_log"; then
        call_success=true
    fi
    
    # Cleanup
    kill $caller_pid 2>/dev/null || true
    kill $receiver_pid 2>/dev/null || true
    
    if [ "$call_success" = true ]; then
        print_pass "Call established successfully"
        rm -f "$caller_config" "$caller_log" "$receiver_config" "$receiver_log"
        return 0
    else
        print_fail "Call failed"
        print_info "Caller log: $caller_log"
        print_info "Receiver log: $receiver_log"
        
        # Troubleshooting hints
        if grep -q "480\|486\|487" "$caller_log"; then
            print_warning "Call not answered or rejected"
        elif grep -q "404" "$caller_log"; then
            print_warning "Destination extension not found"
        elif grep -q "503" "$caller_log"; then
            print_warning "Service unavailable - check Asterisk dialplan"
        fi
        
        return 1
    fi
}

# Test OPTIONS ping using SIPSAK
test_options_sipsak() {
    local server=${1:-$DEFAULT_SERVER}
    local port=${2:-$DEFAULT_PORT}
    
    print_test "Testing SIP OPTIONS (SIPSAK): $server:$port"
    
    if sipsak -s "sip:${server}:${port}" -v > /dev/null 2>&1; then
        print_pass "SIP server is responsive"
        return 0
    else
        print_fail "SIP server not responding"
        print_warning "Check if Asterisk is running and listening on port $port"
        return 1
    fi
}

# Create test extension in Asterisk
create_test_extension() {
    local extension=$1
    local password=$2
    
    print_test "Creating temporary test extension: $extension"
    
    # Add to pjsip.conf
    cat >> /etc/asterisk/pjsip.conf <<EOF

; BEGIN TEST EXTENSION ${extension}
[${extension}]
type=endpoint
context=from-internal
disallow=all
allow=ulaw
allow=alaw
allow=g722
transport=transport-udp
auth=${extension}
aors=${extension}

[${extension}]
type=auth
auth_type=userpass
username=${extension}
password=${password}

[${extension}]
type=aor
max_contacts=1
remove_existing=yes
; END TEST EXTENSION ${extension}
EOF
    
    # Reload Asterisk
    asterisk -rx "pjsip reload" > /dev/null 2>&1
    sleep 2
    
    # Verify
    if asterisk -rx "pjsip show endpoint ${extension}" 2>/dev/null | grep -q "${extension}"; then
        print_pass "Test extension $extension created"
        return 0
    else
        print_fail "Failed to create test extension $extension"
        return 1
    fi
}

# Cleanup test extensions
cleanup_test_extension() {
    local extension=$1
    
    print_verbose "Cleaning up test extension: $extension"
    
    # Remove from pjsip.conf
    sed -i "/; BEGIN TEST EXTENSION ${extension}/,/; END TEST EXTENSION ${extension}/d" /etc/asterisk/pjsip.conf 2>/dev/null || true
    
    # Reload
    asterisk -rx "pjsip reload" > /dev/null 2>&1
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
        echo -e "${GREEN}${BOLD}🎉 All tests passed! 🎉${RESET}\n"
        return 0
    else
        echo -e "${RED}${BOLD}⚠️  Some tests failed ⚠️${RESET}\n"
        return 1
    fi
}

# Show usage
show_usage() {
    cat <<EOF
${BOLD}RayanPBX SIP Testing Suite${RESET}

${BOLD}USAGE:${RESET}
    $0 [OPTIONS] COMMAND [ARGS]

${BOLD}COMMANDS:${RESET}
    tools                           List available SIP testing tools
    install <tool>                  Install a specific SIP tool (pjsua, sipsak, sipp)
    
    register <ext> <pass> [server]  Test SIP registration
    call <from> <fpass> <to> <tpass> [server]
                                    Test call between extensions
    options [server]                Test SIP OPTIONS ping
    
    full <ext1> <pass1> <ext2> <pass2> [server]
                                    Run full test suite with two extensions

${BOLD}OPTIONS:${RESET}
    -v, --verbose                   Enable verbose output
    -s, --server <server>           SIP server address (default: 127.0.0.1)
    -p, --port <port>               SIP port (default: 5060)
    -t, --timeout <seconds>         Test timeout (default: 10)
    -h, --help                      Show this help message

${BOLD}EXAMPLES:${RESET}
    # List available tools
    $0 tools
    
    # Install pjsua
    $0 install pjsua
    
    # Test registration
    $0 register 1001 mypassword
    
    # Test call between extensions
    $0 call 1001 pass1 1002 pass2
    
    # Run full test suite
    $0 full 1001 pass1 1002 pass2
    
    # Test with remote server
    $0 -s 192.168.1.100 register 1001 mypassword

EOF
}

# Main command dispatcher
main() {
    local server=$DEFAULT_SERVER
    local port=$DEFAULT_PORT
    
    # Parse options
    while [[ $# -gt 0 ]]; do
        case $1 in
            -v|--verbose)
                VERBOSE=true
                shift
                ;;
            -s|--server)
                server=$2
                shift 2
                ;;
            -p|--port)
                port=$2
                shift 2
                ;;
            -t|--timeout)
                DEFAULT_TIMEOUT=$2
                shift 2
                ;;
            -h|--help)
                show_usage
                exit 0
                ;;
            *)
                break
                ;;
        esac
    done
    
    local command=$1
    shift || true
    
    case "$command" in
        tools)
            print_header
            print_info "Detecting available SIP testing tools..."
            echo
            
            local available_tools
            available_tools=$(detect_available_tools)
            
            if [ -n "$available_tools" ]; then
                print_pass "Available tools: $available_tools"
            else
                print_warning "No SIP testing tools found"
                print_info "Install tools with: $0 install <tool>"
            fi
            
            echo
            print_info "Supported tools:"
            echo "  - pjsua:   Full-featured SIP user agent (recommended)"
            echo "  - sipsak:  SIP Swiss Army Knife for quick tests"
            echo "  - sipexer: Modern SIP testing tool"
            echo "  - sipp:    Performance testing and scenarios"
            ;;
            
        install)
            local tool=$1
            if [ -z "$tool" ]; then
                print_fail "Tool name required"
                echo "Usage: $0 install <tool>"
                exit 1
            fi
            
            print_header
            install_tools "$tool"
            ;;
            
        register)
            local extension=$1
            local password=$2
            local test_server=${3:-$server}
            
            if [ -z "$extension" ] || [ -z "$password" ]; then
                print_fail "Extension and password required"
                echo "Usage: $0 register <extension> <password> [server]"
                exit 1
            fi
            
            print_header
            
            # Try available tools
            if command_exists pjsua; then
                test_registration_pjsua "$extension" "$password" "$test_server" "$port"
            elif command_exists sipsak; then
                test_registration_sipsak "$extension" "$password" "$test_server" "$port"
            else
                print_fail "No SIP testing tools available"
                print_info "Install with: $0 install pjsua"
                exit 1
            fi
            
            print_summary
            ;;
            
        call)
            local from_ext=$1
            local from_pass=$2
            local to_ext=$3
            local to_pass=$4
            local test_server=${5:-$server}
            
            if [ -z "$from_ext" ] || [ -z "$from_pass" ] || [ -z "$to_ext" ] || [ -z "$to_pass" ]; then
                print_fail "All parameters required"
                echo "Usage: $0 call <from_ext> <from_pass> <to_ext> <to_pass> [server]"
                exit 1
            fi
            
            print_header
            
            if command_exists pjsua; then
                test_call_pjsua "$from_ext" "$from_pass" "$to_ext" "$to_pass" "$test_server" "$port"
            else
                print_fail "pjsua not available (required for call testing)"
                print_info "Install with: $0 install pjsua"
                exit 1
            fi
            
            print_summary
            ;;
            
        options)
            local test_server=${1:-$server}
            
            print_header
            
            if command_exists sipsak; then
                test_options_sipsak "$test_server" "$port"
            else
                print_fail "sipsak not available"
                print_info "Install with: $0 install sipsak"
                exit 1
            fi
            
            print_summary
            ;;
            
        full)
            local ext1=$1
            local pass1=$2
            local ext2=$3
            local pass2=$4
            local test_server=${5:-$server}
            
            if [ -z "$ext1" ] || [ -z "$pass1" ] || [ -z "$ext2" ] || [ -z "$pass2" ]; then
                print_fail "All parameters required"
                echo "Usage: $0 full <ext1> <pass1> <ext2> <pass2> [server]"
                exit 1
            fi
            
            print_header
            
            # Test server connectivity
            if command_exists sipsak; then
                test_options_sipsak "$test_server" "$port"
            fi
            
            # Test registration for both extensions
            if command_exists pjsua; then
                test_registration_pjsua "$ext1" "$pass1" "$test_server" "$port"
                test_registration_pjsua "$ext2" "$pass2" "$test_server" "$port"
                
                # Test call
                test_call_pjsua "$ext1" "$pass1" "$ext2" "$pass2" "$test_server" "$port"
            elif command_exists sipsak; then
                test_registration_sipsak "$ext1" "$pass1" "$test_server" "$port"
                test_registration_sipsak "$ext2" "$pass2" "$test_server" "$port"
            else
                print_fail "No SIP testing tools available"
                print_info "Install with: $0 install pjsua"
                exit 1
            fi
            
            print_summary
            ;;
            
        *)
            show_usage
            exit 1
            ;;
    esac
}

# Run if executed directly
if [ "${BASH_SOURCE[0]}" == "${0}" ]; then
    main "$@"
fi
