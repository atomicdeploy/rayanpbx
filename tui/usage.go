package main

import (
	"fmt"

	"github.com/fatih/color"
)

// ShowCLIUsage displays comprehensive CLI usage guide
func ShowCLIUsage() {
	cyan := color.New(color.FgCyan, color.Bold)
	green := color.New(color.FgGreen)
	yellow := color.New(color.FgYellow)
	magenta := color.New(color.FgMagenta)

	cyan.Println("\n📖 RayanPBX CLI Usage Guide")
	fmt.Println("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━")

	// Extension Management
	magenta.Println("\n📱 Extension Management:")
	yellow.Println("  rayanpbx-cli extension list")
	fmt.Println("    └─ List all configured extensions")
	fmt.Println()
	yellow.Println("  rayanpbx-cli extension create <number> <name> <password>")
	fmt.Println("    └─ Create a new extension")
	green.Println("       Example: rayanpbx-cli extension create 100 \"John Doe\" secret123")
	fmt.Println()
	yellow.Println("  rayanpbx-cli extension delete <number>")
	fmt.Println("    └─ Delete an extension")
	green.Println("       Example: rayanpbx-cli extension delete 100")
	fmt.Println()
	yellow.Println("  rayanpbx-cli extension status <number>")
	fmt.Println("    └─ Check extension registration status")
	green.Println("       Example: rayanpbx-cli extension status 100")

	// Trunk Management
	magenta.Println("\n🔗 Trunk Management:")
	yellow.Println("  rayanpbx-cli trunk list")
	fmt.Println("    └─ List all configured trunks")
	fmt.Println()
	yellow.Println("  rayanpbx-cli trunk create <name> <host> <port>")
	fmt.Println("    └─ Create a new trunk")
	green.Println("       Example: rayanpbx-cli trunk create ShatelTrunk tel07.fp.shatel.ir 5060")
	fmt.Println()
	yellow.Println("  rayanpbx-cli trunk test <name>")
	fmt.Println("    └─ Test trunk connectivity")
	green.Println("       Example: rayanpbx-cli trunk test ShatelTrunk")
	fmt.Println()
	yellow.Println("  rayanpbx-cli trunk status <name>")
	fmt.Println("    └─ Get trunk status and statistics")

	// Asterisk Management
	magenta.Println("\n⚙️  Asterisk Management:")
	yellow.Println("  rayanpbx-cli asterisk status")
	fmt.Println("    └─ Check Asterisk service status")
	fmt.Println()
	yellow.Println("  rayanpbx-cli asterisk start")
	fmt.Println("    └─ Start Asterisk service")
	fmt.Println()
	yellow.Println("  rayanpbx-cli asterisk stop")
	fmt.Println("    └─ Stop Asterisk service")
	fmt.Println()
	yellow.Println("  rayanpbx-cli asterisk restart")
	fmt.Println("    └─ Restart Asterisk service")
	fmt.Println()
	yellow.Println("  rayanpbx-cli asterisk reload")
	fmt.Println("    └─ Reload Asterisk configuration")
	fmt.Println()
	yellow.Println("  rayanpbx-cli asterisk command \"<cli_command>\"")
	fmt.Println("    └─ Execute Asterisk CLI command")
	green.Println("       Example: rayanpbx-cli asterisk command \"pjsip show endpoints\"")

	// Diagnostics
	magenta.Println("\n🔍 Diagnostics:")
	yellow.Println("  rayanpbx-cli diag test-extension <number>")
	fmt.Println("    └─ Test extension registration")
	green.Println("       Example: rayanpbx-cli diag test-extension 100")
	fmt.Println()
	yellow.Println("  rayanpbx-cli diag test-trunk <name>")
	fmt.Println("    └─ Test trunk connectivity")
	green.Println("       Example: rayanpbx-cli diag test-trunk ShatelTrunk")
	fmt.Println()
	yellow.Println("  rayanpbx-cli diag test-routing <from> <to>")
	fmt.Println("    └─ Test call routing")
	green.Println("       Example: rayanpbx-cli diag test-routing 100 02191002369")
	fmt.Println()
	yellow.Println("  rayanpbx-cli diag sip-debug <on|off>")
	fmt.Println("    └─ Enable/disable SIP debugging")
	green.Println("       Example: rayanpbx-cli diag sip-debug on")
	fmt.Println()
	yellow.Println("  rayanpbx-cli diag health-check")
	fmt.Println("    └─ Run comprehensive system health check")

	// System
	magenta.Println("\n🖥️  System:")
	yellow.Println("  rayanpbx-cli system status")
	fmt.Println("    └─ Show overall system status")
	fmt.Println()
	yellow.Println("  rayanpbx-cli system update")
	fmt.Println("    └─ Update RayanPBX from git repository")
	fmt.Println()
	yellow.Println("  rayanpbx-cli system logs")
	fmt.Println("    └─ View recent system logs")
	fmt.Println()
	yellow.Println("  rayanpbx-cli system health-check")
	fmt.Println("    └─ Run system health checks")

	// Help
	magenta.Println("\n❓ Help:")
	yellow.Println("  rayanpbx-cli help")
	fmt.Println("    └─ Show this usage guide")
	fmt.Println()
	yellow.Println("  rayanpbx-cli help <command>")
	fmt.Println("    └─ Show detailed help for specific command")
	green.Println("       Example: rayanpbx-cli help extension")

	// Output Options
	magenta.Println("\n📊 Output Options:")
	fmt.Println("  Add --json flag for JSON output (scriptable)")
	fmt.Println("  Add --csv flag for CSV output")
	fmt.Println("  Add --quiet flag to suppress colors")
	green.Println("  Example: rayanpbx-cli extension list --json")

	// Exit Codes
	magenta.Println("\n🚦 Exit Codes:")
	fmt.Println("  0  - Success")
	fmt.Println("  1  - General error")
	fmt.Println("  2  - Invalid arguments")
	fmt.Println("  3  - Service/connection error")
	fmt.Println("  4  - Configuration error")

	fmt.Println("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━")

	// Tips
	cyan.Println("\n💡 Tips:")
	fmt.Println("  • Use TAB for command completion (if bash-completion installed)")
	fmt.Println("  • Commands require sudo for system-level operations")
	fmt.Println("  • Add -v or --verbose for detailed output")
	fmt.Println("  • Configuration files loaded in order (later overrides earlier):")
	fmt.Println("    1. /opt/rayanpbx/.env")
	fmt.Println("    2. /usr/local/rayanpbx/.env")
	fmt.Println("    3. /etc/rayanpbx/.env")
	fmt.Println("    4. <project root>/.env")
	fmt.Println("    5. <current directory>/.env")
	fmt.Println("  • Logs directory: /var/log/rayanpbx/")

	fmt.Println()
}

// ShowExtensionHelp shows detailed help for extension commands
func ShowExtensionHelp() {
	cyan := color.New(color.FgCyan, color.Bold)
	green := color.New(color.FgGreen)
	yellow := color.New(color.FgYellow)

	cyan.Println("\n📱 Extension Commands - Detailed Help")
	fmt.Println("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━")

	yellow.Println("\nCOMMAND: extension list")
	fmt.Println("  Lists all configured SIP extensions")
	fmt.Println()
	fmt.Println("  Options:")
	fmt.Println("    --active     Show only active/enabled extensions")
	fmt.Println("    --registered Show only currently registered extensions")
	fmt.Println("    --json       Output in JSON format")
	fmt.Println()
	green.Println("  Examples:")
	fmt.Println("    rayanpbx-cli extension list")
	fmt.Println("    rayanpbx-cli extension list --active")
	fmt.Println("    rayanpbx-cli extension list --json")

	yellow.Println("\nCOMMAND: extension create <number> <name> <password>")
	fmt.Println("  Creates a new SIP extension")
	fmt.Println()
	fmt.Println("  Parameters:")
	fmt.Println("    number    - Extension number (3-5 digits, e.g., 100-999)")
	fmt.Println("    name      - Display name (quoted if contains spaces)")
	fmt.Println("    password  - SIP password (min 8 characters)")
	fmt.Println()
	fmt.Println("  Options:")
	fmt.Println("    --context <context>  Dialplan context (default: from-internal)")
	fmt.Println("    --codecs <list>      Allowed codecs (default: g722,opus,ulaw,alaw)")
	fmt.Println("    --voicemail          Enable voicemail for this extension")
	fmt.Println()
	green.Println("  Examples:")
	fmt.Println("    rayanpbx-cli extension create 100 \"John Doe\" MySecretPass123")
	fmt.Println("    rayanpbx-cli extension create 101 Sales sales@123 --voicemail")

	yellow.Println("\nCOMMAND: extension status <number>")
	fmt.Println("  Checks the registration status of an extension")
	fmt.Println()
	fmt.Println("  Shows:")
	fmt.Println("    • Registration status (registered/unregistered)")
	fmt.Println("    • IP address and port of registered device")
	fmt.Println("    • User-Agent (device/softphone name)")
	fmt.Println("    • Codec in use")
	fmt.Println("    • Qualify latency (RTT)")
	fmt.Println()
	green.Println("  Example:")
	fmt.Println("    rayanpbx-cli extension status 100")

	fmt.Println("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━")
	fmt.Println()
}
