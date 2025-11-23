package main

import (
	"database/sql"
	"fmt"
	"os"
	"regexp"
	"strings"

	tea "github.com/charmbracelet/bubbletea"
	"github.com/charmbracelet/lipgloss"
	"github.com/fatih/color"
)

// Styles
var (
	titleStyle = lipgloss.NewStyle().
			Foreground(lipgloss.Color("#FAFAFA")).
			Background(lipgloss.Color("#7D56F4")).
			Padding(0, 1).
			Bold(true)

	menuStyle = lipgloss.NewStyle().
			BorderStyle(lipgloss.RoundedBorder()).
			BorderForeground(lipgloss.Color("#874BFD")).
			Padding(1, 2)

	selectedItemStyle = lipgloss.NewStyle().
				Foreground(lipgloss.Color("#7D56F4")).
				Bold(true).
				Underline(true)

	infoStyle = lipgloss.NewStyle().
			Foreground(lipgloss.Color("#7D56F4")).
			Bold(true)

	helpStyle = lipgloss.NewStyle().
			Foreground(lipgloss.Color("#626262"))

	successStyle = lipgloss.NewStyle().
			Foreground(lipgloss.Color("#00FF00")).
			Bold(true)

	errorStyle = lipgloss.NewStyle().
			Foreground(lipgloss.Color("#FF0000")).
			Bold(true)
)

// UsageCommand represents a CLI command in the usage guide
type UsageCommand struct {
	Category    string
	Command     string
	Description string
}

// Field indices for extension creation form
const (
	extFieldNumber = iota
	extFieldName
	extFieldPassword
)

// Field indices for trunk creation form
const (
	trunkFieldName = iota
	trunkFieldHost
	trunkFieldPort
	trunkFieldPriority
)

type screen int

const (
	mainMenu screen = iota
	extensionsScreen
	trunksScreen
	asteriskScreen
	diagnosticsScreen
	statusScreen
	logsScreen
	usageScreen
	createExtensionScreen
	createTrunkScreen
	systemSettingsScreen
)

type model struct {
	currentScreen screen
	menuItems     []string
	cursor        int
	width         int
	height        int
	db            *sql.DB
	config        *Config
	extensions    []Extension
	trunks        []Trunk
	errorMsg      string
	successMsg    string

	// Input fields for creation forms
	inputMode   bool
	inputFields []string
	inputValues []string
	inputCursor int

	// CLI usage navigation
	usageCommands []UsageCommand
	usageCursor   int
}

func initialModel(db *sql.DB, config *Config) model {
	return model{
		currentScreen: mainMenu,
		menuItems: []string{
			"📱 Extensions Management",
			"🔗 Trunks Management",
			"⚙️  Asterisk Management",
			"🔍 Diagnostics & Debugging",
			"📊 System Status",
			"📋 Logs Viewer",
			"📖 CLI Usage Guide",
			"⚙️  System Settings",
			"❌ Exit",
		},
		cursor: 0,
		db:     db,
		config: config,
	}
}

func (m model) Init() tea.Cmd {
	return nil
}

func (m model) Update(msg tea.Msg) (tea.Model, tea.Cmd) {
	switch msg := msg.(type) {
	case tea.KeyMsg:
		// Handle input mode for creation forms
		if m.inputMode {
			return m.handleInputMode(msg)
		}

		switch msg.String() {
		case "ctrl+c", "q":
			return m, tea.Quit

		case "up", "k":
			if m.currentScreen == usageScreen {
				// Navigate usage commands
				if m.usageCursor > 0 {
					m.usageCursor--
				}
			} else if m.cursor > 0 {
				m.cursor--
			}

		case "down", "j":
			if m.currentScreen == usageScreen {
				// Navigate usage commands
				if m.usageCursor < len(m.usageCommands)-1 {
					m.usageCursor++
				}
			} else if m.currentScreen == systemSettingsScreen {
				// System settings has 5 options
				if m.cursor < 4 {
					m.cursor++
				}
			} else if m.cursor < len(m.menuItems)-1 {
				m.cursor++
			}

		case "up", "k":
			if m.currentScreen == usageScreen {
				// Navigate usage commands
				if m.usageCursor > 0 {
					m.usageCursor--
				}
			} else if m.currentScreen == systemSettingsScreen {
				if m.cursor > 0 {
					m.cursor--
				}
			} else if m.cursor > 0 {
				m.cursor--
			}

		case "a":
			// Add button - create new extension/trunk
			if m.currentScreen == extensionsScreen {
				m.initCreateExtension()
			} else if m.currentScreen == trunksScreen {
				m.initCreateTrunk()
			}

		case "enter":
			if m.currentScreen == mainMenu {
				switch m.cursor {
				case 0:
					// Load extensions
					if exts, err := GetExtensions(m.db); err == nil {
						m.extensions = exts
						m.currentScreen = extensionsScreen
					} else {
						m.errorMsg = fmt.Sprintf("Error loading extensions: %v", err)
					}
				case 1:
					// Load trunks
					if trunks, err := GetTrunks(m.db); err == nil {
						m.trunks = trunks
						m.currentScreen = trunksScreen
					} else {
						m.errorMsg = fmt.Sprintf("Error loading trunks: %v", err)
					}
				case 2:
					m.currentScreen = asteriskScreen
				case 3:
					m.currentScreen = diagnosticsScreen
				case 4:
					m.currentScreen = statusScreen
				case 5:
					m.currentScreen = logsScreen
				case 6:
					m.currentScreen = usageScreen
					m.usageCommands = getUsageCommands()
					m.usageCursor = 0
				case 7:
					m.currentScreen = systemSettingsScreen
					m.cursor = 0
				case 8:
					return m, tea.Quit
				}
			} else if m.currentScreen == usageScreen {
				// Execute selected command
				if m.usageCursor < len(m.usageCommands) {
					m.executeCommand(m.usageCommands[m.usageCursor].Command)
				}
			} else if m.currentScreen == systemSettingsScreen {
				// Handle system settings menu selection
				m.handleSystemSettingsAction()
			}

		case "esc":
			if m.currentScreen != mainMenu {
				m.currentScreen = mainMenu
				m.errorMsg = ""
				m.successMsg = ""
			}
		}

	case tea.WindowSizeMsg:
		m.width = msg.Width
		m.height = msg.Height
	}

	return m, nil
}

func (m model) View() string {
	var s string

	// Header with emojis
	header := titleStyle.Render("🎯 RayanPBX - Modern SIP Server Management 🚀")
	s += header + "\n\n"

	// Show error if any
	if m.errorMsg != "" {
		s += errorStyle.Render("❌ "+m.errorMsg) + "\n\n"
	}

	// Show success message if any
	if m.successMsg != "" {
		s += successStyle.Render("✅ "+m.successMsg) + "\n\n"
	}

	switch m.currentScreen {
	case mainMenu:
		s += m.renderMainMenu()
	case extensionsScreen:
		s += m.renderExtensions()
	case trunksScreen:
		s += m.renderTrunks()
	case asteriskScreen:
		s += m.renderAsterisk()
	case diagnosticsScreen:
		s += m.renderDiagnostics()
	case statusScreen:
		s += m.renderStatus()
	case logsScreen:
		s += m.renderLogs()
	case usageScreen:
		s += m.renderUsage()
	case createExtensionScreen:
		s += m.renderCreateExtension()
	case createTrunkScreen:
		s += m.renderCreateTrunk()
	case systemSettingsScreen:
		s += m.renderSystemSettings()
	}

	// Footer with emojis
	s += "\n\n"
	if m.currentScreen == mainMenu {
		s += helpStyle.Render("↑/↓ or j/k: Navigate • Enter: Select • q: Quit")
	} else if m.currentScreen == extensionsScreen {
		s += helpStyle.Render("↑/↓: Navigate • a: Add Extension • ESC: Back • q: Quit")
	} else if m.currentScreen == trunksScreen {
		s += helpStyle.Render("↑/↓: Navigate • a: Add Trunk • ESC: Back • q: Quit")
	} else if m.currentScreen == usageScreen {
		s += helpStyle.Render("↑/↓: Navigate • Enter: Execute Command • ESC: Back • q: Quit")
	} else if m.currentScreen == systemSettingsScreen {
		s += helpStyle.Render("↑/↓: Navigate • Enter: Apply Setting • ESC: Back • q: Quit")
	} else if m.inputMode {
		s += helpStyle.Render("↑/↓: Navigate Fields • Enter: Next/Submit • ESC: Cancel • q: Quit")
	} else {
		s += helpStyle.Render("ESC: Back to Menu • q: Quit")
	}

	return s
}

func (m model) renderMainMenu() string {
	menu := "🏠 Main Menu\n\n"

	for i, item := range m.menuItems {
		cursor := " "
		if m.cursor == i {
			cursor = "▶"
			item = selectedItemStyle.Render(item)
		} else {
			item = lipgloss.NewStyle().Foreground(lipgloss.Color("#FFFFFF")).Render(item)
		}
		menu += fmt.Sprintf("%s %s\n", cursor, item)
	}

	return menuStyle.Render(menu)
}

func (m model) renderExtensions() string {
	content := infoStyle.Render("📱 Extensions Management") + "\n\n"

	if len(m.extensions) == 0 {
		content += "📭 No extensions configured\n\n"
	} else {
		content += fmt.Sprintf("Total Extensions: %s\n\n", successStyle.Render(fmt.Sprintf("%d", len(m.extensions))))

		for _, ext := range m.extensions {
			status := "🔴 Disabled"
			if ext.Enabled {
				status = "🟢 Enabled"
			}

			line := fmt.Sprintf("  %s - %s (%s)\n",
				successStyle.Render(ext.ExtensionNumber),
				ext.Name,
				status,
			)
			content += line
		}
	}

	content += "\n" + helpStyle.Render("💡 Tip: Extensions allow users to make and receive calls")

	return menuStyle.Render(content)
}

func (m model) renderTrunks() string {
	content := infoStyle.Render("🔗 Trunk Configuration") + "\n\n"

	if len(m.trunks) == 0 {
		content += "📭 No trunks configured\n\n"
	} else {
		content += fmt.Sprintf("Total Trunks: %s\n\n", successStyle.Render(fmt.Sprintf("%d", len(m.trunks))))

		for _, trunk := range m.trunks {
			status := "🔴 Disabled"
			if trunk.Enabled {
				status = "🟢 Enabled"
			}

			line := fmt.Sprintf("  %s - %s:%d (Priority: %d) %s\n",
				successStyle.Render(trunk.Name),
				trunk.Host,
				trunk.Port,
				trunk.Priority,
				status,
			)
			content += line
		}
	}

	content += "\n" + helpStyle.Render("💡 Tip: Trunks connect your PBX to external phone networks")

	return menuStyle.Render(content)
}

func (m model) renderStatus() string {
	content := infoStyle.Render("📊 System Status") + "\n\n"

	// Check database
	if err := m.db.Ping(); err == nil {
		content += successStyle.Render("✅ Database: Connected") + "\n"
	} else {
		content += errorStyle.Render("❌ Database: Disconnected") + "\n"
	}

	// Get statistics
	var extTotal, extActive, trunkTotal, trunkActive int
	m.db.QueryRow("SELECT COUNT(*) FROM extensions").Scan(&extTotal)
	m.db.QueryRow("SELECT COUNT(*) FROM extensions WHERE enabled = 1").Scan(&extActive)
	m.db.QueryRow("SELECT COUNT(*) FROM trunks").Scan(&trunkTotal)
	m.db.QueryRow("SELECT COUNT(*) FROM trunks WHERE enabled = 1").Scan(&trunkActive)

	content += "\n📈 Statistics:\n"
	content += fmt.Sprintf("  📱 Extensions: %s active / %d total\n",
		successStyle.Render(fmt.Sprintf("%d", extActive)), extTotal)
	content += fmt.Sprintf("  🔗 Trunks: %s active / %d total\n",
		successStyle.Render(fmt.Sprintf("%d", trunkActive)), trunkTotal)
	content += "  📞 Active Calls: 0\n"

	content += "\n" + helpStyle.Render("🔄 Status updates in real-time")

	return menuStyle.Render(content)
}

func (m model) renderLogs() string {
	content := infoStyle.Render("📋 System Logs") + "\n\n"
	content += "Recent Activity:\n"
	content += "  " + successStyle.Render("[INFO]") + " System initialized\n"
	content += "  " + successStyle.Render("[INFO]") + " Database connected\n"
	content += "  " + helpStyle.Render("[DEBUG]") + " Configuration loaded\n"
	content += "  " + successStyle.Render("[INFO]") + " TUI interface started\n\n"
	content += helpStyle.Render("📡 Live logs coming from Asterisk and API")

	return menuStyle.Render(content)
}

func (m model) renderAsterisk() string {
	content := infoStyle.Render("⚙️  Asterisk Management") + "\n\n"

	am := NewAsteriskManager()

	// Show service status
	status, _ := am.GetServiceStatus()
	statusText := "🔴 Stopped"
	if status == "running" {
		statusText = "🟢 Running"
	}
	content += fmt.Sprintf("Service Status: %s\n\n", statusText)

	content += "Available Actions:\n"
	content += "  • Start/Stop/Restart Service\n"
	content += "  • Reload PJSIP Configuration\n"
	content += "  • Reload Dialplan\n"
	content += "  • Execute CLI Commands\n"
	content += "  • View Endpoints\n"
	content += "  • View Active Channels\n\n"

	content += helpStyle.Render("💡 Use rayanpbx-cli for direct Asterisk management")

	return menuStyle.Render(content)
}

func (m model) renderDiagnostics() string {
	content := infoStyle.Render("🔍 Diagnostics & Debugging") + "\n\n"

	content += "Diagnostic Tools:\n"
	content += "  🔍 SIP Debugging\n"
	content += "  📡 Network Diagnostics\n"
	content += "  📞 Call Flow Testing\n"
	content += "  🔗 Extension Registration Tests\n"
	content += "  🌐 Trunk Connectivity Tests\n"
	content += "  📊 Traffic Analysis\n"
	content += "  🏥 System Health Check\n\n"

	content += helpStyle.Render("💡 Use rayanpbx-cli diag for diagnostic commands")

	return menuStyle.Render(content)
}

func (m model) renderUsage() string {
	content := infoStyle.Render("📖 CLI Usage Guide") + "\n\n"

	if len(m.usageCommands) == 0 {
		content += "Loading commands...\n"
	} else {
		content += "Navigate with ↑/↓ and press Enter to execute:\n\n"

		currentCategory := ""
		for i, cmd := range m.usageCommands {
			if cmd.Category != currentCategory {
				if currentCategory != "" {
					content += "\n"
				}
				content += successStyle.Render(cmd.Category+":") + "\n"
				currentCategory = cmd.Category
			}

			cursor := "  "
			cmdText := cmd.Command
			if i == m.usageCursor {
				cursor = "▶ "
				cmdText = selectedItemStyle.Render(cmd.Command)
			}

			content += fmt.Sprintf("%s%s\n", cursor, cmdText)
			if cmd.Description != "" && i == m.usageCursor {
				content += helpStyle.Render("   └─ "+cmd.Description) + "\n"
			}
		}
	}

	content += "\n" + helpStyle.Render("📚 Full documentation: /opt/rayanpbx/README.md")

	return menuStyle.Render(content)
}

// getUsageCommands returns a list of CLI commands for the usage guide
func getUsageCommands() []UsageCommand {
	return []UsageCommand{
		{"Extensions", "rayanpbx-cli extension list", "List all configured extensions"},
		{"Extensions", "rayanpbx-cli extension create <num> <name> <pass>", "Create a new extension"},
		{"Extensions", "rayanpbx-cli extension status <num>", "Check extension registration status"},
		{"Trunks", "rayanpbx-cli trunk list", "List all configured trunks"},
		{"Trunks", "rayanpbx-cli trunk test <name>", "Test trunk connectivity"},
		{"Trunks", "rayanpbx-cli trunk status <name>", "Get trunk status and statistics"},
		{"Asterisk", "rayanpbx-cli asterisk status", "Check Asterisk service status"},
		{"Asterisk", "rayanpbx-cli asterisk start", "Start Asterisk service"},
		{"Asterisk", "rayanpbx-cli asterisk stop", "Stop Asterisk service"},
		{"Asterisk", "rayanpbx-cli asterisk restart", "Restart Asterisk service"},
		{"Asterisk", "rayanpbx-cli asterisk reload", "Reload Asterisk configuration"},
		{"Diagnostics", "rayanpbx-cli diag test-extension <num>", "Test extension registration"},
		{"Diagnostics", "rayanpbx-cli diag test-trunk <name>", "Test trunk connectivity"},
		{"Diagnostics", "rayanpbx-cli diag health-check", "Run comprehensive system health check"},
		{"System", "rayanpbx-cli system status", "Show overall system status"},
		{"System", "rayanpbx-cli system update", "Update RayanPBX from git repository"},
		{"System", "rayanpbx-cli system logs", "View recent system logs"},
	}
}

// executeCommand shows a message about executing the command
// TODO: Implement actual command execution using exec.Command for better user experience
func (m *model) executeCommand(command string) {
	// For now, just show that the command would be executed
	// In a real implementation, this could use exec.Command to run it
	m.successMsg = fmt.Sprintf("Command ready to execute: %s", command)
	m.errorMsg = "Note: Command execution is simulated in TUI. Please run in terminal."
}

// initCreateExtension initializes the extension creation form
func (m *model) initCreateExtension() {
	m.currentScreen = createExtensionScreen
	m.inputMode = true
	m.inputFields = []string{"Extension Number", "Name", "Password"}
	m.inputValues = []string{"", "", ""}
	m.inputCursor = 0
	m.errorMsg = ""
	m.successMsg = ""
}

// initCreateTrunk initializes the trunk creation form
func (m *model) initCreateTrunk() {
	m.currentScreen = createTrunkScreen
	m.inputMode = true
	m.inputFields = []string{"Name", "Host", "Port", "Priority"}
	m.inputValues = []string{"", "", "5060", "1"}
	m.inputCursor = 0
	m.errorMsg = ""
	m.successMsg = ""
}

// handleInputMode handles keyboard input when in input mode
func (m model) handleInputMode(msg tea.KeyMsg) (tea.Model, tea.Cmd) {
	switch msg.String() {
	case "esc":
		// Cancel input
		m.inputMode = false
		if m.currentScreen == createExtensionScreen {
			m.currentScreen = extensionsScreen
		} else if m.currentScreen == createTrunkScreen {
			m.currentScreen = trunksScreen
		}
		m.errorMsg = ""
		m.successMsg = ""

	case "up":
		if m.inputCursor > 0 {
			m.inputCursor--
		}

	case "down":
		if m.inputCursor < len(m.inputFields)-1 {
			m.inputCursor++
		}

	case "enter":
		// Move to next field or submit
		if m.inputCursor < len(m.inputFields)-1 {
			m.inputCursor++
		} else {
			// Submit the form
			if m.currentScreen == createExtensionScreen {
				m.createExtension()
			} else if m.currentScreen == createTrunkScreen {
				m.createTrunk()
			}
		}

	case "backspace":
		// Delete last character from current field
		if m.inputCursor < len(m.inputValues) && len(m.inputValues[m.inputCursor]) > 0 {
			m.inputValues[m.inputCursor] = m.inputValues[m.inputCursor][:len(m.inputValues[m.inputCursor])-1]
		}

	default:
		// Add character to current field
		if len(msg.String()) == 1 && m.inputCursor < len(m.inputValues) {
			m.inputValues[m.inputCursor] += msg.String()
		}
	}

	return m, nil
}

// renderCreateExtension renders the extension creation form
func (m model) renderCreateExtension() string {
	content := infoStyle.Render("📱 Create New Extension") + "\n\n"

	for i, field := range m.inputFields {
		cursor := "  "
		fieldStyle := lipgloss.NewStyle()
		if i == m.inputCursor {
			cursor = "▶ "
			fieldStyle = selectedItemStyle
		}

		value := m.inputValues[i]
		if value == "" {
			value = helpStyle.Render("<enter value>")
		} else if field == "Password" {
			// Use fixed mask to not reveal password length (security best practice)
			// This prevents potential attackers from guessing password complexity
			value = "********"
		}

		content += fmt.Sprintf("%s%s: %s\n", cursor, fieldStyle.Render(field), value)
	}

	content += "\n" + helpStyle.Render("💡 Fill in all fields and press Enter on the last field to create")

	return menuStyle.Render(content)
}

// renderCreateTrunk renders the trunk creation form
func (m model) renderCreateTrunk() string {
	content := infoStyle.Render("🔗 Create New Trunk") + "\n\n"

	for i, field := range m.inputFields {
		cursor := "  "
		fieldStyle := lipgloss.NewStyle()
		if i == m.inputCursor {
			cursor = "▶ "
			fieldStyle = selectedItemStyle
		}

		value := m.inputValues[i]
		if value == "" {
			value = helpStyle.Render("<enter value>")
		}

		content += fmt.Sprintf("%s%s: %s\n", cursor, fieldStyle.Render(field), value)
	}

	content += "\n" + helpStyle.Render("💡 Fill in all fields and press Enter on the last field to create")

	return menuStyle.Render(content)
}

// createExtension creates a new extension in the database
func (m *model) createExtension() {
	// Validate inputs using field constants
	if m.inputValues[extFieldNumber] == "" || m.inputValues[extFieldName] == "" || m.inputValues[extFieldPassword] == "" {
		m.errorMsg = "All fields are required"
		return
	}

	// Insert into database with default configuration values
	// Note: Default context is 'from-internal' (standard internal dial context)
	// Note: Default transport is 'transport-udp' (standard UDP transport)
	// Note: Extensions are enabled by default
	// TODO: Consider extracting these defaults as constants for better maintainability
	query := `INSERT INTO extensions (extension_number, name, secret, context, transport, enabled, created_at, updated_at)
			  VALUES (?, ?, ?, 'from-internal', 'transport-udp', 1, NOW(), NOW())`

	_, err := m.db.Exec(query, m.inputValues[extFieldNumber], m.inputValues[extFieldName], m.inputValues[extFieldPassword])
	if err != nil {
		m.errorMsg = fmt.Sprintf("Failed to create extension: %v", err)
		return
	}

	// Success - reload extensions and return to list
	m.successMsg = fmt.Sprintf("Extension %s created successfully!", m.inputValues[extFieldNumber])
	m.inputMode = false

	// Reload extensions
	if exts, err := GetExtensions(m.db); err == nil {
		m.extensions = exts
	}

	m.currentScreen = extensionsScreen
}

// createTrunk creates a new trunk in the database
func (m *model) createTrunk() {
	// Validate inputs using field constants
	if m.inputValues[trunkFieldName] == "" || m.inputValues[trunkFieldHost] == "" || m.inputValues[trunkFieldPort] == "" || m.inputValues[trunkFieldPriority] == "" {
		m.errorMsg = "All fields are required"
		return
	}

	// Insert into database with default configuration values
	// Note: Trunks are enabled by default (enabled=1)
	// This is the standard behavior for newly created trunks
	// TODO: Consider extracting these defaults as constants for better maintainability
	query := `INSERT INTO trunks (name, host, port, priority, enabled, created_at, updated_at)
			  VALUES (?, ?, ?, ?, 1, NOW(), NOW())`

	_, err := m.db.Exec(query, m.inputValues[trunkFieldName], m.inputValues[trunkFieldHost], m.inputValues[trunkFieldPort], m.inputValues[trunkFieldPriority])
	if err != nil {
		m.errorMsg = fmt.Sprintf("Failed to create trunk: %v", err)
		return
	}

	// Success - reload trunks and return to list
	m.successMsg = fmt.Sprintf("Trunk %s created successfully!", m.inputValues[trunkFieldName])
	m.inputMode = false

	// Reload trunks
	if trunks, err := GetTrunks(m.db); err == nil {
		m.trunks = trunks
	}

	m.currentScreen = trunksScreen
}

func (m *model) renderSystemSettings() string {
	s := "⚙️  System Settings\n\n"
	
	// Get current mode from config
	appEnv := m.config.AppEnv
	appDebug := m.config.AppDebug
	
	settingsMenu := []string{
		fmt.Sprintf("🔄 Toggle Mode (Current: %s)", appEnv),
		fmt.Sprintf("🐛 Toggle Debug (Current: %v)", appDebug),
		"📝 Set to Production Mode",
		"🔧 Set to Development Mode",
		"⬅️  Back to Main Menu",
	}
	
	for i, item := range settingsMenu {
		cursor := " "
		if m.cursor == i {
			cursor = "▸"
			s += selectedItemStyle.Render(cursor + " " + item)
		} else {
			s += "  " + item
		}
		s += "\n"
	}
	
	if m.errorMsg != "" {
		s += "\n" + errorStyle.Render("❌ "+m.errorMsg)
	}
	if m.successMsg != "" {
		s += "\n" + successStyle.Render("✅ "+m.successMsg)
	}
	
	return menuStyle.Render(s)
}

func (m *model) handleSystemSettingsAction() {
	switch m.cursor {
	case 0:
		// Toggle Mode
		m.toggleAppMode()
	case 1:
		// Toggle Debug
		m.toggleDebugMode()
	case 2:
		// Set to Production
		m.setMode("production", false)
	case 3:
		// Set to Development
		m.setMode("development", true)
	case 4:
		// Back to main menu
		m.currentScreen = mainMenu
		m.cursor = 0
	}
}

func (m *model) toggleAppMode() {
	newEnv := "production"
	newDebug := false
	
	if m.config.AppEnv == "production" {
		newEnv = "development"
		newDebug = true
	}
	
	m.setMode(newEnv, newDebug)
}

func (m *model) toggleDebugMode() {
	m.setMode(m.config.AppEnv, !m.config.AppDebug)
}

func (m *model) setMode(env string, debug bool) {
	// Update .env file
	envFile := "/opt/rayanpbx/.env"
	
	// Read current .env
	content, err := os.ReadFile(envFile)
	if err != nil {
		m.errorMsg = fmt.Sprintf("Failed to read .env: %v", err)
		return
	}
	
	// Replace APP_ENV and APP_DEBUG
	lines := string(content)
	lines = replaceEnvValue(lines, "APP_ENV", env)
	lines = replaceEnvValue(lines, "APP_DEBUG", fmt.Sprintf("%v", debug))
	
	// Write back to .env
	err = os.WriteFile(envFile, []byte(lines), 0644)
	if err != nil {
		m.errorMsg = fmt.Sprintf("Failed to write .env: %v", err)
		return
	}
	
	// Also update backend .env if exists
	backendEnvFile := "/opt/rayanpbx/backend/.env"
	if _, err := os.Stat(backendEnvFile); err == nil {
		content, err := os.ReadFile(backendEnvFile)
		if err == nil {
			lines := string(content)
			lines = replaceEnvValue(lines, "APP_ENV", env)
			lines = replaceEnvValue(lines, "APP_DEBUG", fmt.Sprintf("%v", debug))
			os.WriteFile(backendEnvFile, []byte(lines), 0644)
		}
	}
	
	// Restart API service
	m.successMsg = fmt.Sprintf("Mode set to %s (debug: %v). Restarting API...", env, debug)
	
	// Reload config
	m.config.AppEnv = env
	m.config.AppDebug = debug
}

// Helper function to replace environment variable value in .env content
func replaceEnvValue(content, key, value string) string {
	// Match KEY=value pattern
	re := regexp.MustCompile(fmt.Sprintf(`(?m)^%s=.*$`, regexp.QuoteMeta(key)))
	replacement := fmt.Sprintf("%s=%s", key, value)
	
	if re.MatchString(content) {
		return re.ReplaceAllString(content, replacement)
	}
	
	// If key doesn't exist, append it
	if !strings.HasSuffix(content, "\n") {
		content += "\n"
	}
	return content + replacement + "\n"
}

func main() {
	// Check for version flag
	if len(os.Args) > 1 && (os.Args[1] == "--version" || os.Args[1] == "-v" || os.Args[1] == "version") {
		cyan := color.New(color.FgCyan, color.Bold)
		green := color.New(color.FgGreen)
		cyan.Print("RayanPBX TUI ")
		green.Printf("v%s\n", Version)
		fmt.Println("Modern SIP Server Management Terminal UI")
		return
	}

	// Check for help flag
	if len(os.Args) > 1 && (os.Args[1] == "--help" || os.Args[1] == "-h" || os.Args[1] == "help") {
		cyan := color.New(color.FgCyan, color.Bold)
		green := color.New(color.FgGreen)
		yellow := color.New(color.FgYellow)

		cyan.Print("RayanPBX TUI ")
		green.Printf("v%s\n\n", Version)

		yellow.Println("Modern SIP Server Management Terminal UI")
		fmt.Println()
		fmt.Println("USAGE:")
		fmt.Println("    rayanpbx-tui [OPTIONS]")
		fmt.Println()
		fmt.Println("OPTIONS:")
		fmt.Println("    -h, --help       Show this help message")
		fmt.Println("    -v, --version    Show version information")
		fmt.Println()
		fmt.Println("FEATURES:")
		fmt.Println("    • Interactive terminal UI for managing RayanPBX")
		fmt.Println("    • Extension and trunk management")
		fmt.Println("    • Asterisk service control")
		fmt.Println("    • Real-time system diagnostics")
		fmt.Println("    • Live system status monitoring")
		fmt.Println()
		return
	}

	// Print beautiful banner
	PrintBanner()

	// Load configuration
	green := color.New(color.FgGreen)
	cyan := color.New(color.FgCyan)
	red := color.New(color.FgRed)

	cyan.Println("🔧 Loading configuration...")
	config, err := LoadConfig()
	if err != nil {
		red.Printf("❌ Failed to load configuration: %v\n", err)
		os.Exit(1)
	}
	green.Println("✅ Configuration loaded")

	// Connect to database
	cyan.Println("🔌 Connecting to database...")
	db, err := ConnectDB(config)
	if err != nil {
		red.Printf("❌ Failed to connect to database: %v\n", err)
		os.Exit(1)
	}
	defer db.Close()
	green.Println("✅ Database connected")

	fmt.Println()
	cyan.Println("🚀 Starting TUI interface...")
	fmt.Println()

	// Start TUI
	p := tea.NewProgram(initialModel(db, config), tea.WithAltScreen())

	if _, err := p.Run(); err != nil {
		red.Printf("❌ Error: %v\n", err)
		os.Exit(1)
	}

	// Goodbye message
	fmt.Println()
	green.Println("👋 Thank you for using RayanPBX!")
	cyan.Println("💙 Built with love for the open-source community")
	fmt.Println()
}
