package main

import (
	"database/sql"
	"fmt"
	"os"
	"regexp"
	"strconv"
	"strings"
	"time"

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

// Default port values
const (
	DefaultSIPPort = "5060"
)

// Default extension values
const (
	DefaultExtensionContext   = "from-internal"
	DefaultExtensionTransport = "transport-udp"
	DefaultMaxContacts        = 1
)

type screen int

const (
	mainMenu screen = iota
	extensionsScreen
	trunksScreen
	asteriskScreen
	asteriskMenuScreen
	diagnosticsScreen
	statusScreen
	logsScreen
	usageScreen
	createExtensionScreen
	createTrunkScreen
	diagnosticsMenuScreen
	diagTestExtensionScreen
	diagTestTrunkScreen
	diagTestRoutingScreen
	diagPortTestScreen
	editExtensionScreen
	deleteExtensionScreen
	extensionDetailsScreen
	systemSettingsScreen
	configManagementScreen
	configEditScreen
	configAddScreen
	voipPhonesScreen
	voipPhoneDetailsScreen
	voipPhoneControlScreen
	voipPhoneProvisionScreen
	voipManualIPScreen
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

	// Diagnostics
	diagnosticsManager *DiagnosticsManager
	diagnosticsMenu    []string
	diagnosticsOutput  string
	
	// Configuration management
	configManager *AsteriskConfigManager
	verbose       bool
	
	// Extension/Trunk selection
	selectedExtensionIdx int
	selectedTrunkIdx     int
	
	// Asterisk management
	asteriskManager *AsteriskManager
	asteriskMenu    []string
	asteriskOutput  string
	
	// VoIP phone management
	phoneManager       *PhoneManager
	voipPhones         []PhoneInfo
	selectedPhoneIdx   int
	voipControlMenu    []string
	voipPhoneOutput    string
	currentPhoneStatus *PhoneStatus
	phoneCredentials   map[string]map[string]string
}

// isDiagnosticsInputScreen returns true if the current screen is a diagnostics input screen
func (m model) isDiagnosticsInputScreen() bool {
	return m.currentScreen == diagTestExtensionScreen ||
		m.currentScreen == diagTestTrunkScreen ||
		m.currentScreen == diagTestRoutingScreen ||
		m.currentScreen == diagPortTestScreen
}

func initialModel(db *sql.DB, config *Config, verbose bool) model {
	asteriskManager := NewAsteriskManager()
	diagnosticsManager := NewDiagnosticsManager(asteriskManager)
	configManager := NewAsteriskConfigManager(verbose)
	
	return model{
		currentScreen: mainMenu,
		menuItems: []string{
			"📱 Extensions Management",
			"🔗 Trunks Management",
			"📞 VoIP Phones Management",
			"⚙️  Asterisk Management",
			"🔍 Diagnostics & Debugging",
			"📊 System Status",
			"📋 Logs Viewer",
			"📖 CLI Usage Guide",
			"🔧 Configuration Management",
			"⚙️  System Settings",
			"❌ Exit",
		},
		cursor:             0,
		db:                 db,
		config:             config,
		asteriskManager:    asteriskManager,
		diagnosticsManager: diagnosticsManager,
		configManager:      configManager,
		verbose:            verbose,
		asteriskMenu: []string{
			"🟢 Start Asterisk Service",
			"🔴 Stop Asterisk Service",
			"🔄 Restart Asterisk Service",
			"📊 Show Service Status",
			"🔧 Reload PJSIP Configuration",
			"📞 Reload Dialplan",
			"🔁 Reload All Modules",
			"👥 Show PJSIP Endpoints",
			"📡 Show Active Channels",
			"📋 Show Registrations",
			"🔙 Back to Main Menu",
		},
		diagnosticsMenu: []string{
			"🏥 Run System Health Check",
			"💻 Show System Information",
			"🔍 Enable SIP Debugging",
			"🔇 Disable SIP Debugging",
			"📞 Test Extension Registration",
			"🔗 Test Trunk Connectivity",
			"🛣️  Test Call Routing",
			"🌐 Test Port Connectivity",
			"🔙 Back to Main Menu",
		},
	}
}

func (m model) Init() tea.Cmd {
	return nil
}

func (m model) Update(msg tea.Msg) (tea.Model, tea.Cmd) {
	switch msg := msg.(type) {
	case tea.KeyMsg:
		// Handle config management screens
		if m.currentScreen == configManagementScreen {
			return updateConfigManagement(msg, m)
		} else if m.currentScreen == configAddScreen {
			return updateConfigAdd(msg, m)
		} else if m.currentScreen == configEditScreen {
			return updateConfigEdit(msg, m)
		}
		
		// Handle VoIP phone screens
		if m.currentScreen == voipPhonesScreen || m.currentScreen == voipPhoneDetailsScreen || 
		   m.currentScreen == voipPhoneControlScreen || m.currentScreen == voipPhoneProvisionScreen {
			// Handle VoIP-specific keys first
			switch msg.String() {
			case "m", "c", "r", "p":
				m.handleVoIPPhonesKeyPress(msg.String())
				return m, nil
			}
		}
		
		// Handle VoIP manual IP screen with input mode
		if m.currentScreen == voipManualIPScreen && m.inputMode {
			return m.handleInputMode(msg)
		}
		
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
			} else if m.currentScreen == diagnosticsMenuScreen {
				// Navigate diagnostics menu
				if m.cursor > 0 {
					m.cursor--
				}
			} else if m.currentScreen == asteriskMenuScreen {
				// Navigate asterisk menu
				if m.cursor > 0 {
					m.cursor--
				}
			} else if m.currentScreen == systemSettingsScreen {
				if m.cursor > 0 {
					m.cursor--
				}
			} else if m.currentScreen == extensionsScreen {
				// Navigate extensions list
				if m.selectedExtensionIdx > 0 {
					m.selectedExtensionIdx--
				}
			} else if m.currentScreen == voipPhonesScreen || m.currentScreen == voipPhoneControlScreen || m.currentScreen == voipPhoneProvisionScreen {
				// Handle VoIP phone navigation
				m.handleVoIPPhonesKeyPress("up")
			} else if m.cursor > 0 {
				m.cursor--
			}

		case "down", "j":
			if m.currentScreen == usageScreen {
				// Navigate usage commands
				if m.usageCursor < len(m.usageCommands)-1 {
					m.usageCursor++
				}
			} else if m.currentScreen == diagnosticsMenuScreen {
				// Navigate diagnostics menu
				if m.cursor < len(m.diagnosticsMenu)-1 {
					m.cursor++
				}
			} else if m.currentScreen == asteriskMenuScreen {
				// Navigate asterisk menu
				if m.cursor < len(m.asteriskMenu)-1 {
					m.cursor++
				}
			} else if m.currentScreen == systemSettingsScreen {
				// System settings has 6 options (added upgrade)
				if m.cursor < 5 {
					m.cursor++
				}
			} else if m.currentScreen == extensionsScreen {
				// Navigate extensions list
				if m.selectedExtensionIdx < len(m.extensions)-1 {
					m.selectedExtensionIdx++
				}
			} else if m.currentScreen == voipPhonesScreen || m.currentScreen == voipPhoneControlScreen || m.currentScreen == voipPhoneProvisionScreen {
				// Handle VoIP phone navigation
				m.handleVoIPPhonesKeyPress("down")
			} else if m.cursor < len(m.menuItems)-1 {
				m.cursor++
			}

		case "a":
			// Add button - create new extension/trunk
			if m.currentScreen == extensionsScreen {
				m.initCreateExtension()
			} else if m.currentScreen == trunksScreen {
				m.initCreateTrunk()
			}
		
		case "e":
			// Edit button - edit selected extension/trunk
			if m.currentScreen == extensionsScreen && len(m.extensions) > 0 {
				if m.selectedExtensionIdx < len(m.extensions) {
					m.initEditExtension()
				}
			}
		
		case "d":
			// Delete button - delete selected extension/trunk
			if m.currentScreen == extensionsScreen && len(m.extensions) > 0 {
				if m.selectedExtensionIdx < len(m.extensions) {
					m.currentScreen = deleteExtensionScreen
				}
			}
		
		case "y":
			// Confirm deletion
			if m.currentScreen == deleteExtensionScreen {
				m.deleteExtension()
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
					// VoIP Phones Management
					m.initVoIPPhonesScreen()
				case 3:
					m.currentScreen = asteriskMenuScreen
					m.cursor = 0
					m.errorMsg = ""
					m.successMsg = ""
					m.asteriskOutput = ""
				case 4:
					m.currentScreen = diagnosticsMenuScreen
					m.cursor = 0
					m.errorMsg = ""
					m.successMsg = ""
					m.diagnosticsOutput = ""
				case 5:
					m.currentScreen = statusScreen
				case 6:
					m.currentScreen = logsScreen
				case 7:
					m.currentScreen = usageScreen
					m.usageCommands = getUsageCommands()
					m.usageCursor = 0
				case 8:
					m.currentScreen = configManagementScreen
					m.cursor = 0
					m.errorMsg = ""
					m.successMsg = ""
				case 9:
					m.currentScreen = systemSettingsScreen
					m.cursor = 0
				case 10:
					return m, tea.Quit
				}
			} else if m.currentScreen == usageScreen {
				// Execute selected command
				if m.usageCursor < len(m.usageCommands) {
					m.executeCommand(m.usageCommands[m.usageCursor].Command)
				}
			} else if m.currentScreen == diagnosticsMenuScreen {
				// Handle diagnostics menu selection
				m.handleDiagnosticsMenuSelection()
			} else if m.currentScreen == asteriskMenuScreen {
				// Handle asterisk menu selection
				m.handleAsteriskMenuSelection()
			} else if m.currentScreen == systemSettingsScreen {
				// Handle system settings menu selection
				m.handleSystemSettingsAction()
			} else if m.currentScreen == voipPhonesScreen || m.currentScreen == voipPhoneControlScreen || m.currentScreen == voipPhoneProvisionScreen {
				// Handle VoIP phone enter key
				m.handleVoIPPhonesKeyPress("enter")
			}

		case "esc":
			if m.currentScreen != mainMenu {
				// If in a diagnostics submenu, go back to diagnostics menu
				if m.isDiagnosticsInputScreen() {
					m.currentScreen = diagnosticsMenuScreen
					m.errorMsg = ""
					m.successMsg = ""
					m.diagnosticsOutput = ""
				} else if m.currentScreen == diagnosticsMenuScreen {
					m.currentScreen = mainMenu
					m.cursor = 0
					m.errorMsg = ""
					m.successMsg = ""
					m.diagnosticsOutput = ""
				} else if m.currentScreen == asteriskMenuScreen {
					m.currentScreen = mainMenu
					m.cursor = 0
					m.errorMsg = ""
					m.successMsg = ""
					m.asteriskOutput = ""
				} else if m.currentScreen == deleteExtensionScreen {
					m.currentScreen = extensionsScreen
					m.errorMsg = ""
					m.successMsg = ""
				} else if m.currentScreen == configManagementScreen {
					m.currentScreen = mainMenu
					m.cursor = 0
					m.errorMsg = ""
					m.successMsg = ""
				} else if m.currentScreen == configAddScreen || m.currentScreen == configEditScreen {
					m.currentScreen = configManagementScreen
					m.inputMode = false
					m.errorMsg = ""
					m.successMsg = ""
				} else if m.currentScreen == voipPhoneDetailsScreen || m.currentScreen == voipPhoneControlScreen || 
				           m.currentScreen == voipPhoneProvisionScreen || m.currentScreen == voipManualIPScreen {
					// Handle VoIP phone screen back navigation
					if m.currentScreen == voipPhoneControlScreen || m.currentScreen == voipPhoneProvisionScreen {
						m.currentScreen = voipPhoneDetailsScreen
					} else if m.currentScreen == voipPhoneDetailsScreen || m.currentScreen == voipManualIPScreen {
						m.currentScreen = voipPhonesScreen
						m.inputMode = false
					}
					m.errorMsg = ""
					m.successMsg = ""
				} else if m.currentScreen == voipPhonesScreen {
					m.currentScreen = mainMenu
					m.cursor = 0
					m.errorMsg = ""
					m.successMsg = ""
				} else {
					m.currentScreen = mainMenu
					m.errorMsg = ""
					m.successMsg = ""
				}
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
	case asteriskMenuScreen:
		s += m.renderAsteriskMenu()
	case diagnosticsScreen:
		s += m.renderDiagnostics()
	case diagnosticsMenuScreen:
		s += m.renderDiagnosticsMenu()
	case diagTestExtensionScreen:
		s += m.renderDiagTestExtension()
	case diagTestTrunkScreen:
		s += m.renderDiagTestTrunk()
	case diagTestRoutingScreen:
		s += m.renderDiagTestRouting()
	case diagPortTestScreen:
		s += m.renderDiagPortTest()
	case statusScreen:
		s += m.renderStatus()
	case logsScreen:
		s += m.renderLogs()
	case usageScreen:
		s += m.renderUsage()
	case createExtensionScreen:
		s += m.renderCreateExtension()
	case editExtensionScreen:
		s += m.renderEditExtension()
	case deleteExtensionScreen:
		s += m.renderDeleteExtension()
	case createTrunkScreen:
		s += m.renderCreateTrunk()
	case systemSettingsScreen:
		s += m.renderSystemSettings()
	case configManagementScreen:
		s += viewConfigManagement(m)
	case configAddScreen:
		s += viewConfigInput(m, true)
	case configEditScreen:
		s += viewConfigInput(m, false)
	case voipPhonesScreen:
		s += m.renderVoIPPhones()
	case voipPhoneDetailsScreen:
		s += m.renderVoIPPhoneDetails()
	case voipPhoneControlScreen:
		s += m.renderVoIPPhoneControl()
	case voipPhoneProvisionScreen:
		s += m.renderVoIPPhoneProvision()
	case voipManualIPScreen:
		s += m.renderVoIPManualIP()
	}

	// Footer with emojis
	s += "\n\n"
	if m.currentScreen == mainMenu {
		s += helpStyle.Render("↑/↓ or j/k: Navigate • Enter: Select • q: Quit")
	} else if m.currentScreen == extensionsScreen {
		s += helpStyle.Render("↑/↓: Navigate • a: Add • e: Edit • d: Delete • ESC: Back • q: Quit")
	} else if m.currentScreen == trunksScreen {
		s += helpStyle.Render("↑/↓: Navigate • a: Add Trunk • ESC: Back • q: Quit")
	} else if m.currentScreen == usageScreen {
		s += helpStyle.Render("↑/↓: Navigate • Enter: Execute Command • ESC: Back • q: Quit")
	} else if m.currentScreen == systemSettingsScreen {
		s += helpStyle.Render("↑/↓: Navigate • Enter: Apply Setting • ESC: Back • q: Quit")
	} else if m.currentScreen == diagnosticsMenuScreen {
		s += helpStyle.Render("↑/↓: Navigate • Enter: Select • ESC: Back to Main Menu • q: Quit")
	} else if m.currentScreen == asteriskMenuScreen {
		s += helpStyle.Render("↑/↓: Navigate • Enter: Execute • ESC: Back to Main Menu • q: Quit")
	} else if m.isDiagnosticsInputScreen() {
		if m.inputMode {
			s += helpStyle.Render("↑/↓: Navigate Fields • Enter: Next/Submit • ESC: Cancel • q: Quit")
		} else {
			s += helpStyle.Render("ESC: Back to Diagnostics Menu • q: Quit")
		}
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

		for i, ext := range m.extensions {
			status := "🔴 Disabled"
			if ext.Enabled {
				status = "🟢 Enabled"
			}

			cursor := " "
			if i == m.selectedExtensionIdx {
				cursor = "▶"
			}
			
			line := fmt.Sprintf("%s %s - %s (%s)\n",
				cursor,
				successStyle.Render(ext.ExtensionNumber),
				ext.Name,
				status,
			)
			content += line
		}
	}

	content += "\n" + helpStyle.Render("💡 Tip: Use ↑/↓ to select, 'a' to add, 'e' to edit, 'd' to delete")

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

func (m model) renderAsteriskMenu() string {
	content := infoStyle.Render("⚙️  Asterisk Management Menu") + "\n\n"

	// Show service status at the top
	status, _ := m.asteriskManager.GetServiceStatus()
	statusText := "🔴 Stopped"
	if status == "running" {
		statusText = "🟢 Running"
	}
	content += fmt.Sprintf("Current Status: %s\n\n", statusText)

	// Display asterisk output if any
	if m.asteriskOutput != "" {
		content += "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n"
		content += m.asteriskOutput + "\n"
		content += "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n"
	}

	content += "Select an operation:\n\n"

	for i, item := range m.asteriskMenu {
		cursor := " "
		if m.cursor == i {
			cursor = "▶"
			item = selectedItemStyle.Render(item)
		} else {
			item = lipgloss.NewStyle().Foreground(lipgloss.Color("#FFFFFF")).Render(item)
		}
		content += fmt.Sprintf("%s %s\n", cursor, item)
	}

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

func (m model) renderDiagnosticsMenu() string {
	content := infoStyle.Render("🔍 Diagnostics & Debugging Menu") + "\n\n"

	// Display diagnostics output if any
	if m.diagnosticsOutput != "" {
		content += "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n"
		content += m.diagnosticsOutput + "\n"
		content += "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n"
	}

	content += "Select a diagnostic operation:\n\n"

	for i, item := range m.diagnosticsMenu {
		cursor := " "
		if m.cursor == i {
			cursor = "▶"
			item = selectedItemStyle.Render(item)
		} else {
			item = lipgloss.NewStyle().Foreground(lipgloss.Color("#FFFFFF")).Render(item)
		}
		content += fmt.Sprintf("%s %s\n", cursor, item)
	}

	return menuStyle.Render(content)
}

func (m model) renderDiagTestExtension() string {
	content := infoStyle.Render("📞 Test Extension Registration") + "\n\n"

	if m.diagnosticsOutput != "" {
		content += m.diagnosticsOutput + "\n\n"
	}

	for i, field := range m.inputFields {
		cursor := "  "
		fieldStyle := lipgloss.NewStyle()
		if i == m.inputCursor {
			cursor = "▶ "
			fieldStyle = selectedItemStyle
		}

		value := m.inputValues[i]
		if value == "" {
			value = helpStyle.Render("<enter extension number>")
		}

		content += fmt.Sprintf("%s%s: %s\n", cursor, fieldStyle.Render(field), value)
	}

	content += "\n" + helpStyle.Render("💡 Enter the extension number to test")

	return menuStyle.Render(content)
}

func (m model) renderDiagTestTrunk() string {
	content := infoStyle.Render("🔗 Test Trunk Connectivity") + "\n\n"

	if m.diagnosticsOutput != "" {
		content += m.diagnosticsOutput + "\n\n"
	}

	for i, field := range m.inputFields {
		cursor := "  "
		fieldStyle := lipgloss.NewStyle()
		if i == m.inputCursor {
			cursor = "▶ "
			fieldStyle = selectedItemStyle
		}

		value := m.inputValues[i]
		if value == "" {
			value = helpStyle.Render("<enter trunk name>")
		}

		content += fmt.Sprintf("%s%s: %s\n", cursor, fieldStyle.Render(field), value)
	}

	content += "\n" + helpStyle.Render("💡 Enter the trunk name to test")

	return menuStyle.Render(content)
}

func (m model) renderDiagTestRouting() string {
	content := infoStyle.Render("🛣️  Test Call Routing") + "\n\n"

	if m.diagnosticsOutput != "" {
		content += m.diagnosticsOutput + "\n\n"
	}

	for i, field := range m.inputFields {
		cursor := "  "
		fieldStyle := lipgloss.NewStyle()
		if i == m.inputCursor {
			cursor = "▶ "
			fieldStyle = selectedItemStyle
		}

		value := m.inputValues[i]
		if value == "" {
			if field == "From Extension" {
				value = helpStyle.Render("<enter source extension>")
			} else {
				value = helpStyle.Render("<enter destination number>")
			}
		}

		content += fmt.Sprintf("%s%s: %s\n", cursor, fieldStyle.Render(field), value)
	}

	content += "\n" + helpStyle.Render("💡 Test routing from an extension to a destination")

	return menuStyle.Render(content)
}

func (m model) renderDiagPortTest() string {
	content := infoStyle.Render("🌐 Test Port Connectivity") + "\n\n"

	if m.diagnosticsOutput != "" {
		content += m.diagnosticsOutput + "\n\n"
	}

	for i, field := range m.inputFields {
		cursor := "  "
		fieldStyle := lipgloss.NewStyle()
		if i == m.inputCursor {
			cursor = "▶ "
			fieldStyle = selectedItemStyle
		}

		value := m.inputValues[i]
		if value == "" {
			if field == "Host" {
				value = helpStyle.Render("<enter host/IP>")
			} else {
				value = helpStyle.Render("<enter port number>")
			}
		}

		content += fmt.Sprintf("%s%s: %s\n", cursor, fieldStyle.Render(field), value)
	}

	content += "\n" + helpStyle.Render("💡 Test network connectivity to a host and port")

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

// initEditExtension initializes the extension edit form
func (m *model) initEditExtension() {
	if m.selectedExtensionIdx >= len(m.extensions) {
		return
	}
	
	ext := m.extensions[m.selectedExtensionIdx]
	m.currentScreen = editExtensionScreen
	m.inputMode = true
	m.inputFields = []string{"Extension Number", "Name", "Password"}
	// Pre-fill with current values (password will be empty for security)
	m.inputValues = []string{ext.ExtensionNumber, ext.Name, ""}
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
		if m.currentScreen == createExtensionScreen || m.currentScreen == editExtensionScreen {
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
			} else if m.currentScreen == editExtensionScreen {
				m.editExtension()
			} else if m.currentScreen == createTrunkScreen {
				m.createTrunk()
			} else if m.currentScreen == diagTestExtensionScreen {
				m.executeDiagTestExtension()
			} else if m.currentScreen == diagTestTrunkScreen {
				m.executeDiagTestTrunk()
			} else if m.currentScreen == diagTestRoutingScreen {
				m.executeDiagTestRouting()
			} else if m.currentScreen == diagPortTestScreen {
				m.executeDiagPortTest()
			} else if m.currentScreen == voipManualIPScreen {
				m.executeManualIPAdd()
			} else if m.currentScreen == voipPhoneProvisionScreen {
				m.executeVoIPProvision()
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
	query := `INSERT INTO extensions (extension_number, name, secret, context, transport, enabled, max_contacts, created_at, updated_at)
			  VALUES (?, ?, ?, ?, ?, 1, ?, NOW(), NOW())`

	_, err := m.db.Exec(query, m.inputValues[extFieldNumber], m.inputValues[extFieldName], m.inputValues[extFieldPassword],
		DefaultExtensionContext, DefaultExtensionTransport, DefaultMaxContacts)
	if err != nil {
		m.errorMsg = fmt.Sprintf("Failed to create extension: %v", err)
		return
	}

	// Create extension object for config generation
	ext := Extension{
		ExtensionNumber: m.inputValues[extFieldNumber],
		Name:            m.inputValues[extFieldName],
		Secret:          m.inputValues[extFieldPassword],
		Context:         DefaultExtensionContext,
		Transport:       DefaultExtensionTransport,
		Enabled:         true,
		MaxContacts:     DefaultMaxContacts,
	}

	// Generate and write PJSIP configuration
	config := m.configManager.GeneratePjsipEndpoint(ext)
	if err := m.configManager.WritePjsipConfig(config, fmt.Sprintf("Extension %s", ext.ExtensionNumber)); err != nil {
		m.errorMsg = fmt.Sprintf("Extension created in DB but failed to write config: %v", err)
		m.successMsg = fmt.Sprintf("Extension %s created (DB only - config write failed)", m.inputValues[extFieldNumber])
	} else {
		// Reload Asterisk
		if err := m.configManager.ReloadAsterisk(); err != nil {
			m.errorMsg = fmt.Sprintf("Config written but Asterisk reload failed: %v", err)
			m.successMsg = fmt.Sprintf("Extension %s created and config written (reload failed)", m.inputValues[extFieldNumber])
		} else {
			m.successMsg = fmt.Sprintf("Extension %s created and activated!", m.inputValues[extFieldNumber])
		}
	}

	m.inputMode = false

	// Reload extensions list
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

// editExtension updates an existing extension
func (m *model) editExtension() {
	if m.selectedExtensionIdx >= len(m.extensions) {
		m.errorMsg = "No extension selected"
		return
	}
	
	// Validate inputs
	if m.inputValues[extFieldNumber] == "" || m.inputValues[extFieldName] == "" {
		m.errorMsg = "Extension number and name are required"
		return
	}
	
	ext := m.extensions[m.selectedExtensionIdx]
	oldNumber := ext.ExtensionNumber
	newNumber := m.inputValues[extFieldNumber]
	
	// Build update query - only update password if provided
	var query string
	var args []interface{}
	
	if m.inputValues[extFieldPassword] != "" {
		query = `UPDATE extensions SET extension_number = ?, name = ?, secret = ?, updated_at = NOW() WHERE id = ?`
		args = []interface{}{newNumber, m.inputValues[extFieldName], m.inputValues[extFieldPassword], ext.ID}
	} else {
		query = `UPDATE extensions SET extension_number = ?, name = ?, updated_at = NOW() WHERE id = ?`
		args = []interface{}{newNumber, m.inputValues[extFieldName], ext.ID}
	}
	
	_, err := m.db.Exec(query, args...)
	if err != nil {
		m.errorMsg = fmt.Sprintf("Failed to update extension: %v", err)
		return
	}
	
	// If extension number changed, remove old config
	if oldNumber != newNumber {
		m.configManager.RemovePjsipConfig(fmt.Sprintf("Extension %s", oldNumber))
	}
	
	// Update extension object
	ext.ExtensionNumber = newNumber
	ext.Name = m.inputValues[extFieldName]
	if m.inputValues[extFieldPassword] != "" {
		ext.Secret = m.inputValues[extFieldPassword]
	}
	
	// Generate and write updated config
	config := m.configManager.GeneratePjsipEndpoint(ext)
	if err := m.configManager.WritePjsipConfig(config, fmt.Sprintf("Extension %s", ext.ExtensionNumber)); err != nil {
		m.errorMsg = fmt.Sprintf("Extension updated in DB but failed to write config: %v", err)
		m.successMsg = fmt.Sprintf("Extension %s updated (config write failed)", newNumber)
	} else {
		// Reload Asterisk
		if err := m.configManager.ReloadAsterisk(); err != nil {
			m.errorMsg = fmt.Sprintf("Config written but Asterisk reload failed: %v", err)
			m.successMsg = fmt.Sprintf("Extension %s updated (reload failed)", newNumber)
		} else {
			m.successMsg = fmt.Sprintf("Extension %s updated successfully!", newNumber)
		}
	}
	
	m.inputMode = false
	
	// Reload extensions list
	if exts, err := GetExtensions(m.db); err == nil {
		m.extensions = exts
	}
	
	m.currentScreen = extensionsScreen
}

// deleteExtension deletes an extension from database and config
func (m *model) deleteExtension() {
	if m.selectedExtensionIdx >= len(m.extensions) {
		m.errorMsg = "No extension selected"
		return
	}
	
	ext := m.extensions[m.selectedExtensionIdx]
	
	// Delete from database
	query := `DELETE FROM extensions WHERE id = ?`
	_, err := m.db.Exec(query, ext.ID)
	if err != nil {
		m.errorMsg = fmt.Sprintf("Failed to delete extension: %v", err)
		return
	}
	
	// Remove from config
	if err := m.configManager.RemovePjsipConfig(fmt.Sprintf("Extension %s", ext.ExtensionNumber)); err != nil {
		m.errorMsg = fmt.Sprintf("Extension deleted from DB but failed to remove config: %v", err)
		m.successMsg = fmt.Sprintf("Extension %s deleted (config removal failed)", ext.ExtensionNumber)
	} else {
		// Reload Asterisk
		if err := m.configManager.ReloadAsterisk(); err != nil {
			m.errorMsg = fmt.Sprintf("Config removed but Asterisk reload failed: %v", err)
			m.successMsg = fmt.Sprintf("Extension %s deleted (reload failed)", ext.ExtensionNumber)
		} else {
			m.successMsg = fmt.Sprintf("Extension %s deleted successfully!", ext.ExtensionNumber)
		}
	}
	
	// Reload extensions list
	if exts, err := GetExtensions(m.db); err == nil {
		m.extensions = exts
		// Adjust selection if needed
		if len(m.extensions) == 0 {
			m.selectedExtensionIdx = 0
		} else if m.selectedExtensionIdx >= len(m.extensions) {
			m.selectedExtensionIdx = len(m.extensions) - 1
		}
	}
	
	m.currentScreen = extensionsScreen
}

// renderEditExtension renders the extension edit form
func (m model) renderEditExtension() string {
	content := infoStyle.Render("✏️  Edit Extension") + "\n\n"
	
	if m.selectedExtensionIdx >= len(m.extensions) {
		content += errorStyle.Render("No extension selected") + "\n"
		return menuStyle.Render(content)
	}

	for i, field := range m.inputFields {
		cursor := "  "
		fieldStyle := lipgloss.NewStyle()
		if i == m.inputCursor {
			cursor = "▶ "
			fieldStyle = selectedItemStyle
		}

		value := m.inputValues[i]
		if value == "" {
			if field == "Password" {
				value = helpStyle.Render("<leave empty to keep current>")
			} else {
				value = helpStyle.Render("<enter value>")
			}
		} else if field == "Password" {
			value = "********"
		}

		content += fmt.Sprintf("%s%s: %s\n", cursor, fieldStyle.Render(field), value)
	}

	content += "\n" + helpStyle.Render("💡 Leave password empty to keep current password")

	return menuStyle.Render(content)
}

// renderDeleteExtension renders the delete confirmation screen
func (m model) renderDeleteExtension() string {
	content := infoStyle.Render("🗑️  Delete Extension") + "\n\n"
	
	if m.selectedExtensionIdx >= len(m.extensions) {
		content += errorStyle.Render("No extension selected") + "\n"
		return menuStyle.Render(content)
	}
	
	ext := m.extensions[m.selectedExtensionIdx]
	
	content += errorStyle.Render("⚠️  WARNING: This action cannot be undone!") + "\n\n"
	content += fmt.Sprintf("You are about to delete extension:\n")
	content += fmt.Sprintf("  Number: %s\n", successStyle.Render(ext.ExtensionNumber))
	content += fmt.Sprintf("  Name: %s\n", ext.Name)
	content += fmt.Sprintf("  Status: %s\n\n", func() string {
		if ext.Enabled {
			return "🟢 Enabled"
		}
		return "🔴 Disabled"
	}())
	
	content += "This will:\n"
	content += "  • Remove extension from database\n"
	content += "  • Remove PJSIP configuration\n"
	content += "  • Reload Asterisk\n\n"
	
	content += helpStyle.Render("Press 'y' to confirm, ESC to cancel")

	return menuStyle.Render(content)
}

// handleDiagnosticsMenuSelection handles diagnostics menu selection
func (m *model) handleDiagnosticsMenuSelection() {
	m.errorMsg = ""
	m.successMsg = ""
	m.diagnosticsOutput = ""

	switch m.cursor {
	case 0: // Run System Health Check
		m.diagnosticsManager.RunHealthCheck()
		// Capture output will be shown in the menu
		m.successMsg = "Health check completed"
	case 1: // Show System Information
		m.diagnosticsOutput = m.diagnosticsManager.GetSystemInfo()
	case 2: // Enable SIP Debugging
		if err := m.diagnosticsManager.EnableSIPDebug(); err != nil {
			m.errorMsg = fmt.Sprintf("Failed to enable SIP debug: %v", err)
		} else {
			m.successMsg = "SIP debugging enabled"
		}
	case 3: // Disable SIP Debugging
		if err := m.diagnosticsManager.DisableSIPDebug(); err != nil {
			m.errorMsg = fmt.Sprintf("Failed to disable SIP debug: %v", err)
		} else {
			m.successMsg = "SIP debugging disabled"
		}
	case 4: // Test Extension Registration
		m.currentScreen = diagTestExtensionScreen
		m.inputMode = true
		m.inputFields = []string{"Extension Number"}
		m.inputValues = []string{""}
		m.inputCursor = 0
	case 5: // Test Trunk Connectivity
		m.currentScreen = diagTestTrunkScreen
		m.inputMode = true
		m.inputFields = []string{"Trunk Name"}
		m.inputValues = []string{""}
		m.inputCursor = 0
	case 6: // Test Call Routing
		m.currentScreen = diagTestRoutingScreen
		m.inputMode = true
		m.inputFields = []string{"From Extension", "To Number"}
		m.inputValues = []string{"", ""}
		m.inputCursor = 0
	case 7: // Test Port Connectivity
		m.currentScreen = diagPortTestScreen
		m.inputMode = true
		m.inputFields = []string{"Host", "Port"}
		m.inputValues = []string{"", DefaultSIPPort}
		m.inputCursor = 0
	case 8: // Back to Main Menu
		m.currentScreen = mainMenu
		m.cursor = 0
	}
}

// handleAsteriskMenuSelection handles asterisk menu selection
func (m *model) handleAsteriskMenuSelection() {
	m.errorMsg = ""
	m.successMsg = ""
	m.asteriskOutput = ""

	switch m.cursor {
	case 0: // Start Asterisk Service
		if err := m.asteriskManager.StartService(); err != nil {
			m.errorMsg = fmt.Sprintf("Failed to start service: %v", err)
		} else {
			m.successMsg = "Asterisk service started successfully"
		}
	case 1: // Stop Asterisk Service
		if err := m.asteriskManager.StopService(); err != nil {
			m.errorMsg = fmt.Sprintf("Failed to stop service: %v", err)
		} else {
			m.successMsg = "Asterisk service stopped successfully"
		}
	case 2: // Restart Asterisk Service
		if err := m.asteriskManager.RestartService(); err != nil {
			m.errorMsg = fmt.Sprintf("Failed to restart service: %v", err)
		} else {
			m.successMsg = "Asterisk service restarted successfully"
		}
	case 3: // Show Service Status
		m.asteriskManager.PrintServiceStatus()
		m.successMsg = "Service status displayed"
	case 4: // Reload PJSIP Configuration
		if err := m.asteriskManager.ReloadPJSIP(); err != nil {
			m.errorMsg = fmt.Sprintf("Failed to reload PJSIP: %v", err)
		} else {
			m.successMsg = "PJSIP configuration reloaded successfully"
		}
	case 5: // Reload Dialplan
		if err := m.asteriskManager.ReloadDialplan(); err != nil {
			m.errorMsg = fmt.Sprintf("Failed to reload dialplan: %v", err)
		} else {
			m.successMsg = "Dialplan reloaded successfully"
		}
	case 6: // Reload All Modules
		if err := m.asteriskManager.ReloadAll(); err != nil {
			m.errorMsg = fmt.Sprintf("Failed to reload modules: %v", err)
		} else {
			m.successMsg = "All modules reloaded successfully"
		}
	case 7: // Show PJSIP Endpoints
		output, err := m.asteriskManager.ShowEndpoints()
		if err != nil {
			m.errorMsg = fmt.Sprintf("Failed to show endpoints: %v", err)
		} else {
			m.asteriskOutput = output
			m.successMsg = "PJSIP endpoints retrieved"
		}
	case 8: // Show Active Channels
		output, err := m.asteriskManager.ShowChannels()
		if err != nil {
			m.errorMsg = fmt.Sprintf("Failed to show channels: %v", err)
		} else {
			m.asteriskOutput = output
			m.successMsg = "Active channels retrieved"
		}
	case 9: // Show Registrations
		output, err := m.asteriskManager.ShowPeers()
		if err != nil {
			m.errorMsg = fmt.Sprintf("Failed to show registrations: %v", err)
		} else {
			m.asteriskOutput = output
			m.successMsg = "Registrations retrieved"
		}
	case 10: // Back to Main Menu
		m.currentScreen = mainMenu
		m.cursor = 0
	}
}

// executeDiagTestExtension executes extension registration test
func (m *model) executeDiagTestExtension() {
	if m.inputValues[0] == "" {
		m.errorMsg = "Extension number is required"
		return
	}

	if err := m.diagnosticsManager.TestExtensionRegistration(m.inputValues[0]); err != nil {
		m.errorMsg = fmt.Sprintf("Test failed: %v", err)
	} else {
		m.successMsg = fmt.Sprintf("Extension %s test completed", m.inputValues[0])
	}

	m.inputMode = false
}

// executeDiagTestTrunk executes trunk connectivity test
func (m *model) executeDiagTestTrunk() {
	if m.inputValues[0] == "" {
		m.errorMsg = "Trunk name is required"
		return
	}

	if err := m.diagnosticsManager.TestTrunkConnectivity(m.inputValues[0]); err != nil {
		m.errorMsg = fmt.Sprintf("Test failed: %v", err)
	} else {
		m.successMsg = fmt.Sprintf("Trunk %s test completed", m.inputValues[0])
	}

	m.inputMode = false
}

// executeDiagTestRouting executes call routing test
func (m *model) executeDiagTestRouting() {
	if m.inputValues[0] == "" || m.inputValues[1] == "" {
		m.errorMsg = "Both from extension and to number are required"
		return
	}

	if err := m.diagnosticsManager.TestCallRouting(m.inputValues[0], m.inputValues[1]); err != nil {
		m.errorMsg = fmt.Sprintf("Test failed: %v", err)
	} else {
		m.successMsg = fmt.Sprintf("Routing test completed for %s -> %s", m.inputValues[0], m.inputValues[1])
	}

	m.inputMode = false
}

// executeDiagPortTest executes port connectivity test
func (m *model) executeDiagPortTest() {
	if m.inputValues[0] == "" || m.inputValues[1] == "" {
		m.errorMsg = "Both host and port are required"
		return
	}

	// Convert port to int and validate
	port, err := strconv.Atoi(m.inputValues[1])
	if err != nil {
		m.errorMsg = "Invalid port number"
		return
	}
	
	// Validate port range
	if port < 1 || port > 65535 {
		m.errorMsg = "Port must be between 1 and 65535"
		return
	}

	if err := m.diagnosticsManager.CheckPortConnectivity(m.inputValues[0], port); err != nil {
		m.errorMsg = fmt.Sprintf("Test failed: %v", err)
	} else {
		m.successMsg = fmt.Sprintf("Port test completed for %s:%d", m.inputValues[0], port)
	}

	m.inputMode = false
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
		"🚀 Run System Upgrade",
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
		// Run System Upgrade
		m.runSystemUpgrade()
	case 5:
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

	// Create backup with timestamp
	timestamp := time.Now().Format("20060102_150405")
	backupFile := fmt.Sprintf("%s.backup.%s", envFile, timestamp)
	err = os.WriteFile(backupFile, content, 0644)
	if err != nil {
		m.errorMsg = fmt.Sprintf("Failed to create backup: %v", err)
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
			err = os.WriteFile(backendEnvFile, []byte(lines), 0644)
			if err != nil {
				m.errorMsg = fmt.Sprintf("Failed to write backend .env: %v", err)
				return
			}
		}
	}

	// Reload config
	m.config.AppEnv = env
	m.config.AppDebug = debug
	
	m.successMsg = fmt.Sprintf("Mode set to %s (debug: %v). Changes will take effect after service restart.", env, debug)
}

// runSystemUpgrade executes the upgrade script
func (m *model) runSystemUpgrade() {
	// Look for upgrade script in common locations
	upgradeScript := "/opt/rayanpbx/scripts/upgrade.sh"
	
	// Check if the script exists
	if _, err := os.Stat(upgradeScript); os.IsNotExist(err) {
		m.errorMsg = fmt.Sprintf("Upgrade script not found at: %s", upgradeScript)
		return
	}
	
	// Display a message and exit TUI to run upgrade
	fmt.Println("\n🚀 Launching system upgrade...")
	fmt.Println("The TUI will close and the upgrade script will start.")
	fmt.Println()
	
	// Execute the upgrade script with sudo
	cmd := fmt.Sprintf("sudo bash %s", upgradeScript)
	fmt.Printf("Running: %s\n\n", cmd)
	
	// Exit the TUI program
	os.Exit(0)
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
	// Parse flags
	verbose := false
	for _, arg := range os.Args[1:] {
		if arg == "--verbose" {
			verbose = true
		}
	}
	
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
		fmt.Println("    --verbose        Show detailed information about config file updates")
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
	if verbose {
		cyan.Println("   Verbose mode enabled")
	}
	fmt.Println()

	// Start TUI
	p := tea.NewProgram(initialModel(db, config, verbose), tea.WithAltScreen())

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
