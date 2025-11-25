package main

import (
	"database/sql"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"sort"
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
	extFieldCodecs
	extFieldContext
	extFieldTransport
	extFieldDirectMedia
	extFieldMaxContacts
	extFieldQualifyFreq
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

// Default paths
const (
	SipTestScriptPath = "../scripts/sip-test-suite.sh"
)

// Default extension values
const (
	DefaultExtensionContext   = "from-internal"
	DefaultExtensionTransport = "transport-udp"
	DefaultMaxContacts        = 1
	DefaultQualifyFrequency   = 60
	DefaultCodecs             = "ulaw,alaw,g722"
	DefaultDirectMedia        = "no"
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
	sipTestMenuScreen
	sipTestToolsScreen
	sipTestRegisterScreen
	sipTestCallScreen
	sipTestFullScreen
	editExtensionScreen
	deleteExtensionScreen
	extensionDetailsScreen
	extensionInfoScreen
	sipHelpScreen
	docsListScreen
	docsViewScreen
	systemSettingsScreen
	configManagementScreen
	configEditScreen
	configAddScreen
	voipPhonesScreen
	voipPhoneDetailsScreen
	voipPhoneControlScreen
	voipPhoneProvisionScreen
	voipManualIPScreen
	voipDiscoveryScreen
	helloWorldScreen
	extensionSyncScreen
	extensionSyncDetailScreen
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
	sipTestMenu        []string
	sipTestOutput      string
	
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
	phoneDiscovery     *PhoneDiscovery
	voipPhones         []PhoneInfo
	discoveredPhones   []DiscoveredPhone
	selectedPhoneIdx   int
	voipControlMenu    []string
	voipPhoneOutput    string
	currentPhoneStatus *PhoneStatus
	phoneCredentials   map[string]map[string]string
	
	// Menu position memory (preserve cursor position when navigating back)
	mainMenuCursor        int
	diagnosticsMenuCursor int
	asteriskMenuCursor    int
	sipTestMenuCursor     int
	
	// Documentation browser
	docsList          []string
	selectedDocIdx    int
	currentDocContent string
	
	// Hello World Setup
	helloWorldSetup  *HelloWorldSetup
	helloWorldStatus HelloWorldStatus
	helloWorldMenu   []string

	// Extension Sync
	extensionSyncManager *ExtensionSyncManager
	extensionSyncInfos   []ExtensionSyncInfo
	extensionSyncMenu    []string
	selectedSyncIdx      int
}

// isDiagnosticsInputScreen returns true if the current screen is a diagnostics input screen
func (m model) isDiagnosticsInputScreen() bool {
	return m.currentScreen == diagTestExtensionScreen ||
		m.currentScreen == diagTestTrunkScreen ||
		m.currentScreen == diagTestRoutingScreen ||
		m.currentScreen == diagPortTestScreen ||
		m.currentScreen == sipTestRegisterScreen ||
		m.currentScreen == sipTestCallScreen ||
		m.currentScreen == sipTestFullScreen
}

func initialModel(db *sql.DB, config *Config, verbose bool) model {
	asteriskManager := NewAsteriskManager()
	diagnosticsManager := NewDiagnosticsManager(asteriskManager)
	configManager := NewAsteriskConfigManager(verbose)
	helloWorldSetup := NewHelloWorldSetup(configManager, asteriskManager, verbose)
	extensionSyncManager := NewExtensionSyncManager(db, asteriskManager, configManager)
	
	return model{
		currentScreen: mainMenu,
		menuItems: []string{
			"🚀 Hello World Setup",
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
		cursor:                0,
		db:                    db,
		config:                config,
		asteriskManager:       asteriskManager,
		diagnosticsManager:    diagnosticsManager,
		configManager:         configManager,
		helloWorldSetup:       helloWorldSetup,
		extensionSyncManager:  extensionSyncManager,
		verbose:               verbose,
		asteriskMenu: []string{
			"🟢 Start Asterisk Service",
			"🔴 Stop Asterisk Service",
			"🔄 Restart Asterisk Service",
			"📊 Show Service Status",
			"🔧 Reload PJSIP Configuration",
			"📞 Reload Dialplan",
			"🔁 Reload All Modules",
			"👥 Show PJSIP Endpoints",
			"🚦 Show PJSIP Transports",
			"📡 Show Active Channels",
			"📋 Show Registrations",
			"🔙 Back to Main Menu",
		},
		diagnosticsMenu: []string{
			"🏥 Run System Health Check",
			"💻 Show System Information",
			"📡 Check SIP Port",
			"🔍 Enable SIP Debugging",
			"🔇 Disable SIP Debugging",
			"📞 Test Extension Registration",
			"🔗 Test Trunk Connectivity",
			"🛣️  Test Call Routing",
			"🌐 Test Port Connectivity",
			"🧪 SIP Testing Suite",
			"🔙 Back to Main Menu",
		},
		sipTestMenu: []string{
			"🔧 Check Available Tools",
			"📦 Install SIP Tool",
			"📞 Test Registration",
			"📲 Test Call",
			"🧪 Run Full Test Suite",
			"🔙 Back to Diagnostics",
		},
		helloWorldMenu: []string{
			"🚀 Run Complete Setup",
			"📊 Check Status",
			"🗑️  Remove Setup",
			"🔙 Back to Main Menu",
		},
		extensionSyncMenu: []string{
			"🔄 Sync Database → Asterisk (selected)",
			"🔄 Sync Asterisk → Database (selected)",
			"📥 Sync All DB → Asterisk",
			"📤 Sync All Asterisk → DB",
			"🔍 Refresh Sync Status",
			"🔙 Back to Extensions",
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
			case "m", "c", "r", "p", "d":
				m.handleVoIPPhonesKeyPress(msg.String())
				return m, nil
			}
		}
		
		// Handle VoIP discovery screen
		if m.currentScreen == voipDiscoveryScreen {
			switch msg.String() {
			case "s", "l", "r", "a":
				m.handleVoIPDiscoveryKeyPress(msg.String())
				return m, nil
			case "up", "k":
				if m.selectedPhoneIdx > 0 {
					m.selectedPhoneIdx--
				}
				return m, nil
			case "down", "j":
				if m.selectedPhoneIdx < len(m.discoveredPhones)-1 {
					m.selectedPhoneIdx++
				}
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
			} else if m.currentScreen == sipTestMenuScreen {
				// Navigate SIP test menu
				if m.cursor > 0 {
					m.cursor--
				}
			} else if m.currentScreen == asteriskMenuScreen {
				// Navigate asterisk menu
				if m.cursor > 0 {
					m.cursor--
				}
			} else if m.currentScreen == helloWorldScreen {
				// Navigate Hello World menu
				if m.cursor > 0 {
					m.cursor--
				}
			} else if m.currentScreen == systemSettingsScreen {
				if m.cursor > 0 {
					m.cursor--
				}
			} else if m.currentScreen == extensionsScreen {
				// Navigate extensions list (use sync infos if available)
				if len(m.extensionSyncInfos) > 0 {
					if m.selectedExtensionIdx > 0 {
						m.selectedExtensionIdx--
					}
				} else if m.selectedExtensionIdx > 0 {
					m.selectedExtensionIdx--
				}
			} else if m.currentScreen == extensionSyncScreen {
				// Navigate sync screen (extensions + menu)
				if m.cursor > 0 {
					m.cursor--
					if m.cursor < len(m.extensionSyncInfos) {
						m.selectedSyncIdx = m.cursor
					}
				}
			} else if m.currentScreen == docsListScreen {
				// Navigate docs list
				if m.selectedDocIdx > 0 {
					m.selectedDocIdx--
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
			} else if m.currentScreen == sipTestMenuScreen {
				// Navigate SIP test menu
				if m.cursor < len(m.sipTestMenu)-1 {
					m.cursor++
				}
			} else if m.currentScreen == asteriskMenuScreen {
				// Navigate asterisk menu
				if m.cursor < len(m.asteriskMenu)-1 {
					m.cursor++
				}
			} else if m.currentScreen == helloWorldScreen {
				// Navigate Hello World menu
				if m.cursor < len(m.helloWorldMenu)-1 {
					m.cursor++
				}
			} else if m.currentScreen == systemSettingsScreen {
				// System settings has 6 options (added upgrade)
				if m.cursor < 5 {
					m.cursor++
				}
			} else if m.currentScreen == extensionsScreen {
				// Navigate extensions list (use sync infos if available)
				if len(m.extensionSyncInfos) > 0 {
					if m.selectedExtensionIdx < len(m.extensionSyncInfos)-1 {
						m.selectedExtensionIdx++
					}
				} else if m.selectedExtensionIdx < len(m.extensions)-1 {
					m.selectedExtensionIdx++
				}
			} else if m.currentScreen == extensionSyncScreen {
				// Navigate sync screen (extensions + menu)
				maxIdx := len(m.extensionSyncInfos) + len(m.extensionSyncMenu) - 1
				if m.cursor < maxIdx {
					m.cursor++
					if m.cursor < len(m.extensionSyncInfos) {
						m.selectedSyncIdx = m.cursor
					}
				}
			} else if m.currentScreen == docsListScreen {
				// Navigate docs list
				if m.selectedDocIdx < len(m.docsList)-1 {
					m.selectedDocIdx++
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
		
		case "i":
			// Info/diagnostics button - show extension info
			if m.currentScreen == extensionsScreen && len(m.extensions) > 0 {
				if m.selectedExtensionIdx < len(m.extensions) {
					m.currentScreen = extensionInfoScreen
				}
			}
		
		case "t":
			// Toggle extension enabled/disabled (in extensions list) OR run SIP test (in extension info)
			if m.currentScreen == extensionsScreen && len(m.extensions) > 0 {
				if m.selectedExtensionIdx < len(m.extensions) {
					m.toggleExtension()
				}
			} else if m.currentScreen == extensionInfoScreen && m.selectedExtensionIdx < len(m.extensions) {
				// Run SIP test suite
				m.currentScreen = sipTestRegisterScreen
				m.inputMode = true
				ext := m.extensions[m.selectedExtensionIdx]
				m.inputFields = []string{"Extension Number", "Password", "Server (optional)"}
				m.inputValues = []string{ext.ExtensionNumber, "", "127.0.0.1"}
				m.inputCursor = 0
			}
		
		case "h":
			// Show help screen
			if m.currentScreen == extensionsScreen || m.currentScreen == extensionInfoScreen {
				m.currentScreen = sipHelpScreen
				m.errorMsg = ""
				m.successMsg = ""
			}
		
		case "D":
			// Show documentation browser (uppercase D only from sipHelpScreen)
			if m.currentScreen == sipHelpScreen {
				m.loadDocsList()
				m.currentScreen = docsListScreen
				m.selectedDocIdx = 0
				m.errorMsg = ""
				m.successMsg = ""
			}
		
		case "r":
			// Reload Asterisk PJSIP
			if m.currentScreen == extensionInfoScreen {
				if _, err := m.asteriskManager.ExecuteCLICommand("pjsip reload"); err != nil {
					m.errorMsg = fmt.Sprintf("Failed to reload PJSIP: %v", err)
				} else {
					m.successMsg = "PJSIP reloaded successfully"
				}
			}
		
		case "s":
			// Enable SIP debugging
			if m.currentScreen == extensionInfoScreen {
				output, err := m.diagnosticsManager.EnableSIPDebugQuiet()
				if err != nil {
					m.errorMsg = fmt.Sprintf("Failed to enable SIP debug: %v", err)
				} else {
					m.successMsg = "SIP debugging enabled - check Asterisk console"
					if output != "" {
						m.diagnosticsOutput = output
					}
				}
			}
		
		case "S":
			// Open Sync Screen (uppercase S to avoid conflict with 's' for SIP debug)
			if m.currentScreen == extensionsScreen {
				m.loadExtensionSyncInfo()
				m.currentScreen = extensionSyncScreen
				m.cursor = 0
				m.selectedSyncIdx = 0
				m.errorMsg = ""
				m.successMsg = ""
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
					// Hello World Setup
					m.mainMenuCursor = m.cursor
					m.currentScreen = helloWorldScreen
					m.helloWorldStatus = m.helloWorldSetup.GetStatus()
					m.cursor = 0
					m.errorMsg = ""
					m.successMsg = ""
				case 1:
					// Load extensions with sync info
					if exts, err := GetExtensions(m.db); err == nil {
						m.extensions = exts
						m.loadExtensionSyncInfo()
						m.currentScreen = extensionsScreen
					} else {
						m.errorMsg = fmt.Sprintf("Error loading extensions: %v", err)
					}
				case 2:
					// Load trunks
					if trunks, err := GetTrunks(m.db); err == nil {
						m.trunks = trunks
						m.currentScreen = trunksScreen
					} else {
						m.errorMsg = fmt.Sprintf("Error loading trunks: %v", err)
					}
				case 3:
					// VoIP Phones Management
					m.initVoIPPhonesScreen()
				case 4:
					m.mainMenuCursor = m.cursor // Save main menu position
					m.currentScreen = asteriskMenuScreen
					m.asteriskMenuCursor = 0
					m.cursor = 0
					m.errorMsg = ""
					m.successMsg = ""
					m.asteriskOutput = ""
				case 5:
					m.mainMenuCursor = m.cursor // Save main menu position
					m.currentScreen = diagnosticsMenuScreen
					m.diagnosticsMenuCursor = 0
					m.cursor = 0
					m.errorMsg = ""
					m.successMsg = ""
					m.diagnosticsOutput = ""
				case 6:
					m.currentScreen = statusScreen
				case 7:
					m.currentScreen = logsScreen
				case 8:
					m.currentScreen = usageScreen
					m.usageCommands = getUsageCommands()
					m.usageCursor = 0
				case 9:
					m.mainMenuCursor = m.cursor // Save main menu position
					m.currentScreen = configManagementScreen
					m.cursor = 0
					m.errorMsg = ""
					m.successMsg = ""
				case 10:
					m.mainMenuCursor = m.cursor // Save main menu position
					m.currentScreen = systemSettingsScreen
					m.cursor = 0
				case 11:
					return m, tea.Quit
				}
			} else if m.currentScreen == usageScreen {
				// Execute selected command
				if m.usageCursor < len(m.usageCommands) {
					m.executeCommand(m.usageCommands[m.usageCursor].Command)
				}
			} else if m.currentScreen == diagnosticsMenuScreen {
				// Save diagnostics menu position before handling
				m.diagnosticsMenuCursor = m.cursor
				// Handle diagnostics menu selection
				m.handleDiagnosticsMenuSelection()
			} else if m.currentScreen == sipTestMenuScreen {
				// Save SIP test menu position before handling
				m.sipTestMenuCursor = m.cursor
				// Handle SIP test menu selection
				m.handleSipTestMenuSelection()
			} else if m.currentScreen == asteriskMenuScreen {
				// Save asterisk menu position before handling
				m.asteriskMenuCursor = m.cursor
				// Handle asterisk menu selection
				m.handleAsteriskMenuSelection()
			} else if m.currentScreen == systemSettingsScreen {
				// Handle system settings menu selection
				m.handleSystemSettingsAction()
			} else if m.currentScreen == docsListScreen {
				// Open selected document
				if m.selectedDocIdx < len(m.docsList) {
					m.loadDocContent(m.docsList[m.selectedDocIdx])
					m.currentScreen = docsViewScreen
				}
			} else if m.currentScreen == voipPhonesScreen || m.currentScreen == voipPhoneControlScreen || m.currentScreen == voipPhoneProvisionScreen {
				// Handle VoIP phone enter key
				m.handleVoIPPhonesKeyPress("enter")
			} else if m.currentScreen == helloWorldScreen {
				// Handle Hello World setup menu selection
				m.handleHelloWorldMenuSelection()
			} else if m.currentScreen == extensionSyncScreen {
				// Handle extension sync menu selection
				m.handleExtensionSyncSelection()
			}

		case "esc":
			if m.currentScreen != mainMenu {
				// If in a diagnostics submenu, go back to diagnostics menu
				if m.isDiagnosticsInputScreen() {
					m.currentScreen = diagnosticsMenuScreen
					m.cursor = m.diagnosticsMenuCursor
					m.errorMsg = ""
					m.successMsg = ""
					m.diagnosticsOutput = ""
				} else if m.currentScreen == extensionSyncScreen {
					m.currentScreen = extensionsScreen
					m.cursor = 0
					m.errorMsg = ""
					m.successMsg = ""
				} else if m.currentScreen == helloWorldScreen {
					m.currentScreen = mainMenu
					m.cursor = m.mainMenuCursor
					m.errorMsg = ""
					m.successMsg = ""
				} else if m.currentScreen == diagnosticsMenuScreen {
					m.currentScreen = mainMenu
					m.cursor = m.mainMenuCursor
					m.errorMsg = ""
					m.successMsg = ""
					m.diagnosticsOutput = ""
				} else if m.currentScreen == sipTestMenuScreen {
					m.currentScreen = diagnosticsMenuScreen
					m.cursor = m.diagnosticsMenuCursor
					m.errorMsg = ""
					m.successMsg = ""
					m.sipTestOutput = ""
				} else if m.currentScreen == sipTestToolsScreen || 
					m.currentScreen == sipTestRegisterScreen ||
					m.currentScreen == sipTestCallScreen ||
					m.currentScreen == sipTestFullScreen {
					m.currentScreen = sipTestMenuScreen
					m.cursor = m.sipTestMenuCursor
					m.errorMsg = ""
					m.successMsg = ""
				} else if m.currentScreen == asteriskMenuScreen {
					m.currentScreen = mainMenu
					m.cursor = m.mainMenuCursor
					m.errorMsg = ""
					m.successMsg = ""
					m.asteriskOutput = ""
				} else if m.currentScreen == extensionInfoScreen {
					m.currentScreen = extensionsScreen
					m.errorMsg = ""
					m.successMsg = ""
				} else if m.currentScreen == sipHelpScreen {
					m.currentScreen = extensionsScreen
					m.errorMsg = ""
					m.successMsg = ""
				} else if m.currentScreen == deleteExtensionScreen {
					m.currentScreen = extensionsScreen
					m.errorMsg = ""
					m.successMsg = ""
				} else if m.currentScreen == configManagementScreen {
					m.currentScreen = mainMenu
					m.cursor = m.mainMenuCursor
					m.errorMsg = ""
					m.successMsg = ""
				} else if m.currentScreen == configAddScreen || m.currentScreen == configEditScreen {
					m.currentScreen = configManagementScreen
					m.inputMode = false
					m.errorMsg = ""
					m.successMsg = ""
				} else if m.currentScreen == docsListScreen {
					m.currentScreen = sipHelpScreen
					m.errorMsg = ""
					m.successMsg = ""
				} else if m.currentScreen == docsViewScreen {
					m.currentScreen = docsListScreen
					m.currentDocContent = ""
					m.errorMsg = ""
					m.successMsg = ""
				} else if m.currentScreen == voipPhoneDetailsScreen || m.currentScreen == voipPhoneControlScreen || 
				           m.currentScreen == voipPhoneProvisionScreen || m.currentScreen == voipManualIPScreen ||
				           m.currentScreen == voipDiscoveryScreen {
					// Handle VoIP phone screen back navigation
					if m.currentScreen == voipPhoneControlScreen || m.currentScreen == voipPhoneProvisionScreen {
						m.currentScreen = voipPhoneDetailsScreen
					} else if m.currentScreen == voipPhoneDetailsScreen || m.currentScreen == voipManualIPScreen || 
					           m.currentScreen == voipDiscoveryScreen {
						m.currentScreen = voipPhonesScreen
						m.inputMode = false
					}
					m.errorMsg = ""
					m.successMsg = ""
				} else if m.currentScreen == voipPhonesScreen {
					m.currentScreen = mainMenu
					m.cursor = m.mainMenuCursor
					m.errorMsg = ""
					m.successMsg = ""
				} else {
					m.currentScreen = mainMenu
					m.cursor = m.mainMenuCursor
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
	case sipTestMenuScreen:
		s += m.renderSipTestMenu()
	case sipTestToolsScreen:
		s += m.renderSipTestTools()
	case sipTestRegisterScreen:
		s += m.renderSipTestRegister()
	case sipTestCallScreen:
		s += m.renderSipTestCall()
	case sipTestFullScreen:
		s += m.renderSipTestFull()
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
	case extensionInfoScreen:
		s += m.renderExtensionInfo()
	case sipHelpScreen:
		s += m.renderSipHelp()
	case docsListScreen:
		s += m.renderDocsList()
	case docsViewScreen:
		s += m.renderDocView()
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
	case voipDiscoveryScreen:
		s += m.renderVoIPDiscovery()
	case helloWorldScreen:
		s += m.renderHelloWorld()
	case extensionSyncScreen:
		s += m.renderExtensionSync()
	}

	// Footer with emojis
	s += "\n\n"
	if m.currentScreen == mainMenu {
		s += helpStyle.Render("↑/↓ or j/k: Navigate • Enter: Select • q: Quit")
	} else if m.currentScreen == helloWorldScreen {
		s += helpStyle.Render("↑/↓: Navigate • Enter: Execute • ESC: Back • q: Quit")
	} else if m.currentScreen == extensionsScreen {
		s += helpStyle.Render("↑/↓: Navigate • a: Add • e: Edit • d: Delete • t: Toggle • i: Info • S: Sync • h: Help • ESC: Back")
	} else if m.currentScreen == extensionSyncScreen {
		s += helpStyle.Render("↑/↓: Navigate • Enter: Select/Execute • ESC: Back to Extensions • q: Quit")
	} else if m.currentScreen == extensionInfoScreen {
		s += helpStyle.Render("r: Reload PJSIP • t: Test Suite • s: SIP Debug • h: Help Guide • ESC: Back • q: Quit")
	} else if m.currentScreen == sipHelpScreen {
		s += helpStyle.Render("D: Browse Docs • ESC: Back • q: Quit")
	} else if m.currentScreen == docsListScreen {
		s += helpStyle.Render("↑/↓: Navigate • Enter: View • ESC: Back • q: Quit")
	} else if m.currentScreen == docsViewScreen {
		s += helpStyle.Render("ESC: Back to List • q: Quit")
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

	// Get sync summary and show if there are mismatches
	if m.extensionSyncManager != nil {
		total, matched, dbOnly, astOnly, mismatched, err := m.extensionSyncManager.GetSyncSummary()
		if err == nil && total > 0 {
			if dbOnly > 0 || astOnly > 0 || mismatched > 0 {
				content += errorStyle.Render("⚠️  Sync Issues Detected") + "\n"
				if dbOnly > 0 {
					content += fmt.Sprintf("   • %d extension(s) in DB only\n", dbOnly)
				}
				if astOnly > 0 {
					content += fmt.Sprintf("   • %d extension(s) in Asterisk only\n", astOnly)
				}
				if mismatched > 0 {
					content += fmt.Sprintf("   • %d extension(s) with mismatched config\n", mismatched)
				}
				content += helpStyle.Render("   Press 'S' to open Sync Manager\n")
				content += "\n"
			} else if matched > 0 {
				content += successStyle.Render(fmt.Sprintf("✅ All %d extensions synced", matched)) + "\n\n"
			}
		}
	}

	if len(m.extensions) == 0 && len(m.extensionSyncInfos) == 0 {
		content += "📭 No extensions configured\n\n"
	} else {
		// Show combined list from both sources if available
		if len(m.extensionSyncInfos) > 0 {
			content += fmt.Sprintf("Total Extensions: %s (from DB and Asterisk)\n\n", 
				successStyle.Render(fmt.Sprintf("%d", len(m.extensionSyncInfos))))

			for i, syncInfo := range m.extensionSyncInfos {
				cursor := " "
				if i == m.selectedExtensionIdx {
					cursor = "▶"
				}
				
				// Build status indicators
				var statusParts []string
				
				// Source indicator
				switch syncInfo.Source {
				case SourceBoth:
					if syncInfo.SyncStatus == SyncStatusMatch {
						statusParts = append(statusParts, "✅")
					} else {
						statusParts = append(statusParts, "⚠️")
					}
				case SourceDatabase:
					statusParts = append(statusParts, "📦 DB only")
				case SourceAsterisk:
					statusParts = append(statusParts, "⚡ Asterisk only")
				}
				
				// Enabled/disabled status (from DB if available)
				if syncInfo.DBExtension != nil {
					if syncInfo.DBExtension.Enabled {
						statusParts = append(statusParts, "🟢")
					} else {
						statusParts = append(statusParts, "🔴")
					}
				}
				
				// Live registration status (from Asterisk if available)
				if syncInfo.AsteriskConfig != nil && syncInfo.AsteriskConfig.Registered {
					statusParts = append(statusParts, "📞 Registered")
				}
				
				// Get name
				name := fmt.Sprintf("Extension %s", syncInfo.ExtensionNumber)
				if syncInfo.DBExtension != nil && syncInfo.DBExtension.Name != "" {
					name = syncInfo.DBExtension.Name
				}
				
				status := strings.Join(statusParts, " ")
				line := fmt.Sprintf("%s %s - %s (%s)\n",
					cursor,
					successStyle.Render(syncInfo.ExtensionNumber),
					name,
					status,
				)
				content += line
			}
		} else {
			// Fallback to DB-only list
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
	}

	// Removed per-item tip from inside the box to avoid duplication with footer
	// Global actions are shown in the footer

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

	// Check Asterisk service
	am := NewAsteriskManager()
	asteriskStatus, _ := am.GetServiceStatus()
	if asteriskStatus == "running" {
		content += successStyle.Render("✅ Asterisk: Running") + "\n"
	} else {
		content += errorStyle.Render("❌ Asterisk: Stopped") + "\n"
	}

	// Get database statistics
	var extTotal, extActive, trunkTotal, trunkActive int
	m.db.QueryRow("SELECT COUNT(*) FROM extensions").Scan(&extTotal)
	m.db.QueryRow("SELECT COUNT(*) FROM extensions WHERE enabled = 1").Scan(&extActive)
	m.db.QueryRow("SELECT COUNT(*) FROM trunks").Scan(&trunkTotal)
	m.db.QueryRow("SELECT COUNT(*) FROM trunks WHERE enabled = 1").Scan(&trunkActive)

	// Get Asterisk live endpoints
	var asteriskEndpoints int
	var registeredEndpoints int
	asteriskEndpointsList, err := am.ListAllEndpoints()
	if err == nil {
		// Filter to only count numeric extensions (not trunks)
		for _, ep := range asteriskEndpointsList {
			if matched, _ := regexp.MatchString(`^\d+$`, ep); matched {
				asteriskEndpoints++
			}
		}
	}

	// Get registered extensions from Asterisk
	if m.extensionSyncManager != nil {
		liveStatus, _ := m.extensionSyncManager.GetLiveAsteriskEndpoints()
		for _, registered := range liveStatus {
			if registered {
				registeredEndpoints++
			}
		}
	}

	content += "\n📈 Statistics:\n"
	
	// Extensions - show both DB and Asterisk
	content += "  📱 Extensions:\n"
	content += fmt.Sprintf("     Database: %s active / %d total\n",
		successStyle.Render(fmt.Sprintf("%d", extActive)), extTotal)
	if asteriskStatus == "running" {
		content += fmt.Sprintf("     Asterisk: %s configured, %s registered\n",
			successStyle.Render(fmt.Sprintf("%d", asteriskEndpoints)),
			successStyle.Render(fmt.Sprintf("%d", registeredEndpoints)))
		
		// Show sync status
		if m.extensionSyncManager != nil {
			total, matched, dbOnly, astOnly, mismatched, _ := m.extensionSyncManager.GetSyncSummary()
			if total > 0 {
				if dbOnly > 0 || astOnly > 0 || mismatched > 0 {
					content += errorStyle.Render(fmt.Sprintf("     ⚠️  Sync Issues: %d DB-only, %d Asterisk-only, %d mismatched\n", dbOnly, astOnly, mismatched))
				} else {
					content += successStyle.Render(fmt.Sprintf("     ✅ Synced: %d extensions in sync\n", matched))
				}
			}
		}
	} else {
		content += helpStyle.Render("     Asterisk: Not running\n")
	}
	
	content += fmt.Sprintf("  🔗 Trunks: %s active / %d total\n",
		successStyle.Render(fmt.Sprintf("%d", trunkActive)), trunkTotal)
	content += "  📞 Active Calls: 0\n"

	content += "\n" + helpStyle.Render("🔄 Status updates in real-time • Press 'S' in Extensions to sync")

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

func (m model) renderSipTestMenu() string {
	content := infoStyle.Render("🧪 SIP Testing Suite") + "\n\n"

	if m.sipTestOutput != "" {
		content += "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n"
		content += m.sipTestOutput + "\n"
		content += "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n"
	}

	content += "Select a SIP test operation:\n\n"

	for i, item := range m.sipTestMenu {
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

func (m model) renderSipTestTools() string {
	content := infoStyle.Render("🔧 SIP Testing Tools") + "\n\n"

	if m.sipTestOutput != "" {
		content += m.sipTestOutput + "\n\n"
	} else {
		content += "Checking available SIP testing tools...\n"
	}

	content += helpStyle.Render("💡 Press ESC to go back")

	return menuStyle.Render(content)
}

func (m model) renderSipTestRegister() string {
	content := infoStyle.Render("📞 Test SIP Registration") + "\n\n"

	if m.sipTestOutput != "" {
		content += m.sipTestOutput + "\n\n"
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
			switch field {
			case "Extension":
				value = helpStyle.Render("<enter extension number>")
			case "Password":
				value = helpStyle.Render("<enter password>")
			case "Server":
				value = helpStyle.Render("<server IP (default: 127.0.0.1)>")
			}
		}

		content += fmt.Sprintf("%s%s: %s\n", cursor, fieldStyle.Render(field), value)
	}

	content += "\n" + helpStyle.Render("💡 Test SIP registration for an extension")

	return menuStyle.Render(content)
}

func (m model) renderSipTestCall() string {
	content := infoStyle.Render("📲 Test SIP Call") + "\n\n"

	if m.sipTestOutput != "" {
		content += m.sipTestOutput + "\n\n"
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
			switch field {
			case "From Extension":
				value = helpStyle.Render("<caller extension>")
			case "From Password":
				value = helpStyle.Render("<caller password>")
			case "To Extension":
				value = helpStyle.Render("<destination extension>")
			case "To Password":
				value = helpStyle.Render("<destination password>")
			case "Server":
				value = helpStyle.Render("<server IP (default: 127.0.0.1)>")
			}
		}

		content += fmt.Sprintf("%s%s: %s\n", cursor, fieldStyle.Render(field), value)
	}

	content += "\n" + helpStyle.Render("💡 Test call between two extensions")

	return menuStyle.Render(content)
}

func (m model) renderSipTestFull() string {
	content := infoStyle.Render("🧪 Full SIP Test Suite") + "\n\n"

	if m.sipTestOutput != "" {
		content += m.sipTestOutput + "\n\n"
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
			switch field {
			case "Extension 1":
				value = helpStyle.Render("<first extension>")
			case "Password 1":
				value = helpStyle.Render("<first password>")
			case "Extension 2":
				value = helpStyle.Render("<second extension>")
			case "Password 2":
				value = helpStyle.Render("<second password>")
			case "Server":
				value = helpStyle.Render("<server IP (default: 127.0.0.1)>")
			}
		}

		content += fmt.Sprintf("%s%s: %s\n", cursor, fieldStyle.Render(field), value)
	}

	content += "\n" + helpStyle.Render("💡 Run comprehensive SIP tests with two extensions")

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

// initCreateExtension initializes the extension creation form with advanced PJSIP options
func (m *model) initCreateExtension() {
	m.currentScreen = createExtensionScreen
	m.inputMode = true
	m.inputFields = []string{
		"Extension Number",
		"Name",
		"Password",
		"Codecs (ulaw,alaw,g722)",
		"Context",
		"Transport",
		"Direct Media (yes/no)",
		"Max Contacts",
		"Qualify Frequency (sec)",
	}
	m.inputValues = []string{
		"",                          // Extension Number
		"",                          // Name
		"",                          // Password
		DefaultCodecs,               // Codecs
		DefaultExtensionContext,     // Context
		DefaultExtensionTransport,   // Transport
		DefaultDirectMedia,          // Direct Media
		fmt.Sprintf("%d", DefaultMaxContacts),     // Max Contacts
		fmt.Sprintf("%d", DefaultQualifyFrequency), // Qualify Frequency
	}
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

// initEditExtension initializes the extension edit form with advanced PJSIP options
func (m *model) initEditExtension() {
	if m.selectedExtensionIdx >= len(m.extensions) {
		return
	}
	
	ext := m.extensions[m.selectedExtensionIdx]
	m.currentScreen = editExtensionScreen
	m.inputMode = true
	m.inputFields = []string{
		"Extension Number",
		"Name",
		"Password",
		"Codecs (ulaw,alaw,g722)",
		"Context",
		"Transport",
		"Direct Media (yes/no)",
		"Max Contacts",
		"Qualify Frequency (sec)",
	}
	
	// Get codecs string, default if empty
	codecs := ext.Codecs
	if codecs == "" {
		codecs = DefaultCodecs
	}
	
	// Get direct_media, default if empty
	directMedia := ext.DirectMedia
	if directMedia == "" {
		directMedia = DefaultDirectMedia
	}
	
	// Get qualify frequency, default if zero
	qualifyFreq := ext.QualifyFrequency
	if qualifyFreq == 0 {
		qualifyFreq = DefaultQualifyFrequency
	}
	
	// Pre-fill with current values (password will be empty for security)
	m.inputValues = []string{
		ext.ExtensionNumber,
		ext.Name,
		"", // Password empty for security
		codecs,
		ext.Context,
		ext.Transport,
		directMedia,
		fmt.Sprintf("%d", ext.MaxContacts),
		fmt.Sprintf("%d", qualifyFreq),
	}
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
			} else if m.currentScreen == sipTestRegisterScreen {
				m.executeSipTestRegister()
			} else if m.currentScreen == sipTestCallScreen {
				m.executeSipTestCall()
			} else if m.currentScreen == sipTestFullScreen {
				m.executeSipTestFull()
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

// renderCreateExtension renders the extension creation form with advanced PJSIP options
func (m model) renderCreateExtension() string {
	content := infoStyle.Render("📱 Create New Extension") + "\n\n"
	
	// Help descriptions for each field
	fieldHelp := map[int]string{
		extFieldNumber:      "Unique extension number (e.g., 100, 101)",
		extFieldName:        "Display name for the extension",
		extFieldPassword:    "SIP authentication password (min 8 chars)",
		extFieldCodecs:      "Audio codecs: ulaw (US), alaw (EU), g722 (HD)",
		extFieldContext:     "Dialplan context (from-internal recommended)",
		extFieldTransport:   "SIP transport (transport-udp recommended)",
		extFieldDirectMedia: "Allow direct RTP (no=NAT-safe, yes=LAN only)",
		extFieldMaxContacts: "Max simultaneous registrations (1-10)",
		extFieldQualifyFreq: "Seconds between keep-alive checks (0=disabled)",
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
			value = helpStyle.Render("<enter value>")
		} else if field == "Password" {
			// Use fixed mask to not reveal password length (security best practice)
			value = "********"
		}

		content += fmt.Sprintf("%s%s: %s\n", cursor, fieldStyle.Render(field), value)
		
		// Show help for selected field
		if i == m.inputCursor {
			if help, ok := fieldHelp[i]; ok {
				content += helpStyle.Render(fmt.Sprintf("   💡 %s", help)) + "\n"
			}
		}
	}

	content += "\n" + helpStyle.Render("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━")
	content += "\n" + helpStyle.Render("📖 PJSIP Configuration Guide:")
	content += "\n" + helpStyle.Render("   • Codecs: g722 = HD audio (16kHz), ulaw/alaw = standard (8kHz)")
	content += "\n" + helpStyle.Render("   • Direct Media: 'no' is recommended for NAT/firewall setups")
	content += "\n" + helpStyle.Render("   • Qualify: Asterisk pings the device to check if it's online")
	content += "\n\n" + helpStyle.Render("💡 Press ↑/↓ to navigate, Enter on last field to create, ESC to cancel")

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

// codecsToJSON converts a comma-separated codec string to JSON array format
// e.g., "ulaw,alaw,g722" becomes '["ulaw","alaw","g722"]'
func codecsToJSON(codecs string) string {
	codecList := strings.Split(codecs, ",")
	var jsonParts []string
	for _, codec := range codecList {
		codec = strings.TrimSpace(codec)
		if codec != "" {
			jsonParts = append(jsonParts, fmt.Sprintf("\"%s\"", codec))
		}
	}
	return "[" + strings.Join(jsonParts, ",") + "]"
}

// parseExtensionInputValues parses and validates extension input values
// Returns: maxContacts, qualifyFreq, codecs, context, transport, directMedia
func parseExtensionInputValues(inputValues []string) (int, int, string, string, string, string) {
	// Parse max_contacts
	maxContacts := DefaultMaxContacts
	if inputValues[extFieldMaxContacts] != "" {
		if parsed, err := strconv.Atoi(inputValues[extFieldMaxContacts]); err == nil && parsed > 0 && parsed <= 10 {
			maxContacts = parsed
		}
	}
	
	// Parse qualify_frequency
	qualifyFreq := DefaultQualifyFrequency
	if inputValues[extFieldQualifyFreq] != "" {
		if parsed, err := strconv.Atoi(inputValues[extFieldQualifyFreq]); err == nil && parsed >= 0 {
			qualifyFreq = parsed
		}
	}
	
	// Get codecs (use default if empty)
	codecs := inputValues[extFieldCodecs]
	if codecs == "" {
		codecs = DefaultCodecs
	}
	
	// Get context (use default if empty)
	context := inputValues[extFieldContext]
	if context == "" {
		context = DefaultExtensionContext
	}
	
	// Get transport (use default if empty)
	transport := inputValues[extFieldTransport]
	if transport == "" {
		transport = DefaultExtensionTransport
	}
	
	// Get direct_media (use default if empty or invalid)
	directMedia := strings.ToLower(inputValues[extFieldDirectMedia])
	if directMedia != "yes" && directMedia != "no" {
		directMedia = DefaultDirectMedia
	}
	
	return maxContacts, qualifyFreq, codecs, context, transport, directMedia
}

// createExtension creates a new extension in the database with advanced PJSIP options
func (m *model) createExtension() {
	// Validate required inputs
	if m.inputValues[extFieldNumber] == "" || m.inputValues[extFieldName] == "" || m.inputValues[extFieldPassword] == "" {
		m.errorMsg = "Extension number, name, and password are required"
		return
	}
	
	// Parse and validate all extension options
	maxContacts, qualifyFreq, codecs, context, transport, directMedia := parseExtensionInputValues(m.inputValues)
	
	// Convert codecs to JSON format for database storage
	codecsJSON := codecsToJSON(codecs)

	// Insert into database with all PJSIP configuration values
	query := `INSERT INTO extensions (extension_number, name, secret, context, transport, enabled, max_contacts, codecs, direct_media, qualify_frequency, created_at, updated_at)
			  VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, ?, NOW(), NOW())`

	_, err := m.db.Exec(query, 
		m.inputValues[extFieldNumber], 
		m.inputValues[extFieldName], 
		m.inputValues[extFieldPassword],
		context, 
		transport, 
		maxContacts,
		codecsJSON,
		directMedia,
		qualifyFreq)
	if err != nil {
		m.errorMsg = fmt.Sprintf("Failed to create extension: %v", err)
		return
	}

	// Create extension object for config generation
	ext := Extension{
		ExtensionNumber:  m.inputValues[extFieldNumber],
		Name:             m.inputValues[extFieldName],
		Secret:           m.inputValues[extFieldPassword],
		Context:          context,
		Transport:        transport,
		Enabled:          true,
		MaxContacts:      maxContacts,
		Codecs:           codecs,
		DirectMedia:      directMedia,
		QualifyFrequency: qualifyFreq,
	}

	// Ensure transport configuration exists before writing extension config
	if err := m.configManager.EnsureTransportConfig(); err != nil {
		m.errorMsg = fmt.Sprintf("Warning: Failed to ensure transport config: %v", err)
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

// editExtension updates an existing extension with advanced PJSIP options
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
	
	// Parse and validate all extension options using helper function
	maxContacts, qualifyFreq, codecs, context, transport, directMedia := parseExtensionInputValues(m.inputValues)
	
	// Use existing values if new ones are empty (for edit mode)
	if m.inputValues[extFieldCodecs] == "" && ext.Codecs != "" {
		codecs = ext.Codecs
	}
	if m.inputValues[extFieldContext] == "" && ext.Context != "" {
		context = ext.Context
	}
	if m.inputValues[extFieldTransport] == "" && ext.Transport != "" {
		transport = ext.Transport
	}
	if m.inputValues[extFieldDirectMedia] == "" && ext.DirectMedia != "" {
		directMedia = ext.DirectMedia
	}
	
	// Convert codecs to JSON format for database storage
	codecsJSON := codecsToJSON(codecs)
	
	// Build update query with all PJSIP options
	var query string
	var args []interface{}
	
	if m.inputValues[extFieldPassword] != "" {
		query = `UPDATE extensions SET extension_number = ?, name = ?, secret = ?, context = ?, transport = ?, 
		         codecs = ?, direct_media = ?, max_contacts = ?, qualify_frequency = ?, updated_at = NOW() WHERE id = ?`
		args = []interface{}{newNumber, m.inputValues[extFieldName], m.inputValues[extFieldPassword],
			context, transport, codecsJSON, directMedia, maxContacts, qualifyFreq, ext.ID}
	} else {
		query = `UPDATE extensions SET extension_number = ?, name = ?, context = ?, transport = ?, 
		         codecs = ?, direct_media = ?, max_contacts = ?, qualify_frequency = ?, updated_at = NOW() WHERE id = ?`
		args = []interface{}{newNumber, m.inputValues[extFieldName],
			context, transport, codecsJSON, directMedia, maxContacts, qualifyFreq, ext.ID}
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
	
	// Update extension object with all new values
	ext.ExtensionNumber = newNumber
	ext.Name = m.inputValues[extFieldName]
	ext.Context = context
	ext.Transport = transport
	ext.Codecs = codecs
	ext.DirectMedia = directMedia
	ext.MaxContacts = maxContacts
	ext.QualifyFrequency = qualifyFreq
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

// toggleExtension toggles the enabled state of the selected extension
func (m *model) toggleExtension() {
	if m.selectedExtensionIdx >= len(m.extensions) {
		m.errorMsg = "No extension selected"
		return
	}
	
	ext := m.extensions[m.selectedExtensionIdx]
	newEnabled := !ext.Enabled
	
	// Update database
	query := `UPDATE extensions SET enabled = ?, updated_at = NOW() WHERE id = ?`
	_, err := m.db.Exec(query, newEnabled, ext.ID)
	if err != nil {
		m.errorMsg = fmt.Sprintf("Failed to toggle extension: %v", err)
		return
	}
	
	// Update in-memory state
	m.extensions[m.selectedExtensionIdx].Enabled = newEnabled
	
	// Create a copy with updated enabled state for config generation
	updatedExt := ext
	updatedExt.Enabled = newEnabled
	
	if newEnabled {
		// Extension is being enabled - write PJSIP config
		config := m.configManager.GeneratePjsipEndpoint(updatedExt)
		if err := m.configManager.WritePjsipConfig(config, fmt.Sprintf("Extension %s", ext.ExtensionNumber)); err != nil {
			m.errorMsg = fmt.Sprintf("Extension toggled in DB but failed to write config: %v", err)
			m.successMsg = fmt.Sprintf("Extension %s enabled (config write failed)", ext.ExtensionNumber)
		} else {
			// Regenerate dialplan for all enabled extensions
			if err := m.regenerateDialplan(); err != nil {
				m.errorMsg = fmt.Sprintf("PJSIP config written but dialplan update failed: %v", err)
				m.successMsg = fmt.Sprintf("Extension %s enabled (dialplan failed)", ext.ExtensionNumber)
			} else {
				// Reload Asterisk to apply changes
				if err := m.configManager.ReloadAsterisk(); err != nil {
					m.errorMsg = fmt.Sprintf("Config written but Asterisk reload failed: %v", err)
					m.successMsg = fmt.Sprintf("Extension %s enabled (reload failed)", ext.ExtensionNumber)
				} else {
					m.successMsg = fmt.Sprintf("Extension %s enabled - registration now possible!", ext.ExtensionNumber)
					m.errorMsg = "" // Clear error only on success
				}
			}
		}
	} else {
		// Extension is being disabled - remove PJSIP config
		if err := m.configManager.RemovePjsipConfig(fmt.Sprintf("Extension %s", ext.ExtensionNumber)); err != nil {
			m.errorMsg = fmt.Sprintf("Extension disabled in DB but failed to remove config: %v", err)
			m.successMsg = fmt.Sprintf("Extension %s disabled (config removal failed)", ext.ExtensionNumber)
		} else {
			// Regenerate dialplan for all enabled extensions (this extension will be excluded)
			if err := m.regenerateDialplan(); err != nil {
				m.errorMsg = fmt.Sprintf("PJSIP config removed but dialplan update failed: %v", err)
				m.successMsg = fmt.Sprintf("Extension %s disabled (dialplan failed)", ext.ExtensionNumber)
			} else {
				// Reload Asterisk to apply changes
				if err := m.configManager.ReloadAsterisk(); err != nil {
					m.errorMsg = fmt.Sprintf("Config removed but Asterisk reload failed: %v", err)
					m.successMsg = fmt.Sprintf("Extension %s disabled (reload failed)", ext.ExtensionNumber)
				} else {
					m.successMsg = fmt.Sprintf("Extension %s disabled - registration blocked!", ext.ExtensionNumber)
					m.errorMsg = "" // Clear error only on success
				}
			}
		}
	}
}

// regenerateDialplan regenerates the internal dialplan for all enabled extensions
func (m *model) regenerateDialplan() error {
	// Fetch all enabled extensions from database
	query := `SELECT id, extension_number, name, COALESCE(secret, ''), COALESCE(email, ''), 
	          enabled, COALESCE(context, 'from-internal'), COALESCE(transport, 'transport-udp'), 
	          COALESCE(caller_id, ''), COALESCE(max_contacts, 1), COALESCE(voicemail_enabled, 0),
	          COALESCE(codecs, '["ulaw","alaw","g722"]'), COALESCE(direct_media, 'no'), COALESCE(qualify_frequency, 60)
	          FROM extensions WHERE enabled = 1 ORDER BY extension_number`
	rows, err := m.db.Query(query)
	if err != nil {
		return fmt.Errorf("failed to query extensions: %v", err)
	}
	defer rows.Close()

	var enabledExtensions []Extension
	for rows.Next() {
		var ext Extension
		var codecsJSON string
		if err := rows.Scan(&ext.ID, &ext.ExtensionNumber, &ext.Name, &ext.Secret, &ext.Email,
			&ext.Enabled, &ext.Context, &ext.Transport, &ext.CallerID, &ext.MaxContacts, &ext.VoicemailEnabled,
			&codecsJSON, &ext.DirectMedia, &ext.QualifyFrequency); err != nil {
			// Log the error but continue processing other extensions
			fmt.Printf("Warning: failed to scan extension row: %v\n", err)
			continue
		}
		enabledExtensions = append(enabledExtensions, ext)
	}

	// Generate dialplan configuration
	dialplanConfig := m.configManager.GenerateInternalDialplan(enabledExtensions)
	
	// Write dialplan to file
	return m.configManager.WriteDialplanConfig(dialplanConfig, "RayanPBX Internal Extensions")
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

// renderExtensionInfo displays detailed info and diagnostics for selected extension
func (m model) renderExtensionInfo() string {
	if m.selectedExtensionIdx >= len(m.extensions) {
		return "Error: No extension selected"
	}
	
	ext := m.extensions[m.selectedExtensionIdx]
	content := titleStyle.Render(fmt.Sprintf("📞 Extension Info: %s", ext.ExtensionNumber)) + "\n\n"
	
	// Extension details
	content += infoStyle.Render("📋 Extension Details:") + "\n"
	content += fmt.Sprintf("  • Number: %s\n", successStyle.Render(ext.ExtensionNumber))
	content += fmt.Sprintf("  • Name: %s\n", ext.Name)
	content += fmt.Sprintf("  • Context: %s\n", ext.Context)
	content += fmt.Sprintf("  • Transport: %s\n", ext.Transport)
	content += fmt.Sprintf("  • Max Contacts: %d\n", ext.MaxContacts)
	content += fmt.Sprintf("  • Codecs: %s\n", ext.Codecs)
	content += fmt.Sprintf("  • Direct Media: %s\n", ext.DirectMedia)
	content += fmt.Sprintf("  • Qualify Freq: %d sec\n", ext.QualifyFrequency)
	content += fmt.Sprintf("  • Status: %s\n", func() string {
		if ext.Enabled {
			return successStyle.Render("✅ Enabled")
		}
		return errorStyle.Render("❌ Disabled")
	}())
	content += "\n"
	
	// Real-time Asterisk status
	content += infoStyle.Render("🔍 Real-time Registration Status:") + "\n"
	
	// Get endpoint status from Asterisk
	endpointOutput, err := m.asteriskManager.ExecuteCLICommand(fmt.Sprintf("pjsip show endpoint %s", ext.ExtensionNumber))
	if err != nil {
		content += errorStyle.Render(fmt.Sprintf("  ❌ Error querying Asterisk: %v\n", err))
	} else if strings.Contains(endpointOutput, "Unable to find") || strings.Contains(endpointOutput, "No such") {
		content += errorStyle.Render("  ❌ Endpoint not found in Asterisk\n")
		content += "  💡 Tip: Try reloading Asterisk configuration\n"
	} else {
		// Parse status
		if strings.Contains(endpointOutput, "Unavailable") {
			content += errorStyle.Render("  ⚫ Status: Offline/Not Registered\n")
		} else if strings.Contains(endpointOutput, "Contact:") {
			content += successStyle.Render("  🟢 Status: Registered\n")
			// Extract contact info
			lines := strings.Split(endpointOutput, "\n")
			for _, line := range lines {
				line = strings.TrimSpace(line)
				if strings.Contains(line, "Contact:") || strings.Contains(line, "Status:") {
					content += fmt.Sprintf("  %s\n", line)
				}
			}
		} else {
			content += "  ⚠️  Status: Unknown\n"
		}
	}
	content += "\n"
	
	// SIP Client Configuration (per-extension specific data only)
	content += infoStyle.Render("📱 SIP Client Configuration:") + "\n"
	content += fmt.Sprintf("  • Username: %s\n", successStyle.Render(ext.ExtensionNumber))
	content += "  • Password: (your configured secret)\n"
	content += "  • SIP Server: (your PBX IP)\n"
	content += "  • Port: 5060\n"
	content += "  • Transport: UDP\n\n"
	
	if !ext.Enabled {
		content += errorStyle.Render("  ⚠️  IMPORTANT: Extension is disabled!\n")
		content += "  Enable it first before attempting registration.\n\n"
	}
	
	return menuStyle.Render(content)
}

// renderSipHelp displays the dynamic SIP help guide with real system info
func (m model) renderSipHelp() string {
	content := titleStyle.Render("📚 SIP Client Setup Guide") + "\n\n"
	
	// System Info section - dynamic data
	content += infoStyle.Render("🖥️ Your PBX Server:") + "\n"
	hostname := GetSystemHostname()
	content += fmt.Sprintf("  • Hostname: %s\n", successStyle.Render(hostname))
	
	ips := GetLocalIPAddresses()
	content += "  • IP Addresses:\n"
	for _, ip := range ips {
		content += fmt.Sprintf("    - %s\n", successStyle.Render(ip))
	}
	content += "\n"
	
	// Popular SIP Clients section
	content += infoStyle.Render("📱 Popular SIP Clients:") + "\n"
	content += "  • MicroSIP (Windows): https://www.microsip.org/\n"
	content += "  • Linphone (Cross-platform): https://www.linphone.org/\n"
	content += "  • GrandStream phones: Enterprise hardware phones\n"
	content += "  • Yealink phones: Enterprise hardware phones\n\n"
	
	// Required Configuration section with actual server info
	content += infoStyle.Render("⚙️ Required Configuration:") + "\n"
	content += "  • Username: (extension number)\n"
	content += "  • Password: (your configured secret)\n"
	// Use first IP as SIP server address - GetLocalIPAddresses already filters out loopback (127.x.x.x)
	if len(ips) > 0 {
		content += fmt.Sprintf("  • SIP Server: %s\n", successStyle.Render(ips[0]))
	} else {
		content += fmt.Sprintf("  • SIP Server: %s\n", successStyle.Render(hostname))
	}
	content += "  • Port: 5060 (default)\n"
	content += "  • Transport: UDP (default)\n\n"
	
	// Test call instructions
	content += infoStyle.Render("🧪 Testing Instructions:") + "\n"
	content += "  1. Register your SIP client with the above credentials\n"
	content += "  2. Check registration status (should show 'Registered')\n"
	content += "  3. Place a test call to another extension\n"
	content += "  4. Verify two-way audio works correctly\n\n"
	
	// Troubleshooting tips
	content += infoStyle.Render("🔧 Troubleshooting:") + "\n"
	content += "  If registration fails:\n"
	content += "  • Verify credentials match database\n"
	content += "  • Check network connectivity to PBX\n"
	content += "  • Ensure port 5060 is not blocked by firewall\n"
	content += "  • Check Asterisk logs: /var/log/asterisk/full\n"
	content += "  • Press 's' to enable SIP debugging\n\n"
	
	// Codec information - dynamic from Asterisk
	content += infoStyle.Render("🔊 Available Codecs:") + "\n"
	if m.diagnosticsManager != nil {
		codecs, _ := m.diagnosticsManager.GetEnabledCodecs()
		for _, codec := range codecs {
			desc := GetCodecDescription(codec)
			content += fmt.Sprintf("  • %s\n", desc)
		}
	} else {
		content += "  • ulaw (G.711u): Standard US codec, 64kbps\n"
		content += "  • alaw (G.711a): Standard EU codec, 64kbps\n"
		content += "  • g722: HD audio codec, 64kbps, 16kHz\n"
	}
	content += "\n"
	
	// Documentation reference
	content += infoStyle.Render("📄 Documentation:") + "\n"
	content += "  • Press 'D' to browse full documentation\n"
	content += "  • See SIP_TESTING_GUIDE.md for detailed testing info\n"
	content += "  • See PJSIP_SETUP_GUIDE.md for setup instructions\n"
	
	return menuStyle.Render(content)
}

// handleDiagnosticsMenuSelection handles diagnostics menu selection
func (m *model) handleDiagnosticsMenuSelection() {
	m.errorMsg = ""
	m.successMsg = ""
	m.diagnosticsOutput = ""

	switch m.cursor {
	case 0: // Run System Health Check
		m.diagnosticsOutput = m.diagnosticsManager.GetHealthCheckOutput()
		m.successMsg = "Health check completed"
	case 1: // Show System Information
		m.diagnosticsOutput = m.diagnosticsManager.GetSystemInfo()
	case 2: // Check SIP Port
		m.diagnosticsOutput = m.diagnosticsManager.CheckSIPPort(5060)
		m.successMsg = "SIP port check completed"
	case 3: // Enable SIP Debugging
		output, err := m.diagnosticsManager.EnableSIPDebugQuiet()
		if err != nil {
			m.errorMsg = fmt.Sprintf("Failed to enable SIP debug: %v", err)
		} else {
			m.successMsg = "SIP debugging enabled"
			if output != "" {
				m.diagnosticsOutput = "SIP Debug Output:\n" + output
			}
		}
	case 4: // Disable SIP Debugging
		output, err := m.diagnosticsManager.DisableSIPDebugQuiet()
		if err != nil {
			m.errorMsg = fmt.Sprintf("Failed to disable SIP debug: %v", err)
		} else {
			m.successMsg = "SIP debugging disabled"
			if output != "" {
				m.diagnosticsOutput = "SIP Debug Output:\n" + output
			}
		}
	case 5: // Test Extension Registration
		m.currentScreen = diagTestExtensionScreen
		m.inputMode = true
		m.inputFields = []string{"Extension Number"}
		m.inputValues = []string{""}
		m.inputCursor = 0
	case 6: // Test Trunk Connectivity
		m.currentScreen = diagTestTrunkScreen
		m.inputMode = true
		m.inputFields = []string{"Trunk Name"}
		m.inputValues = []string{""}
		m.inputCursor = 0
	case 7: // Test Call Routing
		m.currentScreen = diagTestRoutingScreen
		m.inputMode = true
		m.inputFields = []string{"From Extension", "To Number"}
		m.inputValues = []string{"", ""}
		m.inputCursor = 0
	case 8: // Test Port Connectivity
		m.currentScreen = diagPortTestScreen
		m.inputMode = true
		m.inputFields = []string{"Host", "Port"}
		m.inputValues = []string{"", DefaultSIPPort}
		m.inputCursor = 0
	case 9: // SIP Testing Suite
		m.currentScreen = sipTestMenuScreen
		m.cursor = 0
	case 10: // Back to Main Menu
		m.currentScreen = mainMenu
		m.cursor = m.mainMenuCursor
	}
}

// handleSipTestMenuSelection handles SIP test menu selection
func (m *model) handleSipTestMenuSelection() {
	m.errorMsg = ""
	m.successMsg = ""
	m.sipTestOutput = ""

	switch m.cursor {
	case 0: // Check Available Tools
		m.currentScreen = sipTestToolsScreen
		// Run the tools check command
		cmd := exec.Command("bash", SipTestScriptPath, "tools")
		output, err := cmd.CombinedOutput()
		if err != nil {
			details := ParseCommandError(err, output)
			m.sipTestOutput = FormatVerboseError(details)
		} else {
			m.sipTestOutput = string(output)
		}
	case 1: // Install SIP Tool
		// This would require an input screen, for now show a message
		m.sipTestOutput = "To install a tool, use CLI:\nrayanpbx-cli sip-test install <tool>\n\nAvailable tools: pjsua, sipsak, sipp"
	case 2: // Test Registration
		m.currentScreen = sipTestRegisterScreen
		m.inputMode = true
		m.inputFields = []string{"Extension", "Password", "Server"}
		m.inputValues = []string{"", "", "127.0.0.1"}
		m.inputCursor = 0
	case 3: // Test Call
		m.currentScreen = sipTestCallScreen
		m.inputMode = true
		m.inputFields = []string{"From Extension", "From Password", "To Extension", "To Password", "Server"}
		m.inputValues = []string{"", "", "", "", "127.0.0.1"}
		m.inputCursor = 0
	case 4: // Run Full Test Suite
		m.currentScreen = sipTestFullScreen
		m.inputMode = true
		m.inputFields = []string{"Extension 1", "Password 1", "Extension 2", "Password 2", "Server"}
		m.inputValues = []string{"", "", "", "", "127.0.0.1"}
		m.inputCursor = 0
	case 5: // Back to Diagnostics
		m.currentScreen = diagnosticsMenuScreen
		m.cursor = m.diagnosticsMenuCursor
	}
}

// handleAsteriskMenuSelection handles asterisk menu selection
func (m *model) handleAsteriskMenuSelection() {
	m.errorMsg = ""
	m.successMsg = ""
	m.asteriskOutput = ""

	switch m.cursor {
	case 0: // Start Asterisk Service
		if err := m.asteriskManager.StartServiceQuiet(); err != nil {
			m.errorMsg = fmt.Sprintf("Failed to start service: %v", err)
		} else {
			m.successMsg = "Asterisk service started successfully"
		}
	case 1: // Stop Asterisk Service
		if err := m.asteriskManager.StopServiceQuiet(); err != nil {
			m.errorMsg = fmt.Sprintf("Failed to stop service: %v", err)
		} else {
			m.successMsg = "Asterisk service stopped successfully"
		}
	case 2: // Restart Asterisk Service
		if err := m.asteriskManager.RestartServiceQuiet(); err != nil {
			m.errorMsg = fmt.Sprintf("Failed to restart service: %v", err)
		} else {
			m.successMsg = "Asterisk service restarted successfully"
		}
	case 3: // Show Service Status
		m.asteriskOutput = m.asteriskManager.GetServiceStatusOutput()
		m.successMsg = "Service status displayed"
	case 4: // Reload PJSIP Configuration
		if err := m.asteriskManager.ReloadPJSIPQuiet(); err != nil {
			m.errorMsg = fmt.Sprintf("Failed to reload PJSIP: %v", err)
		} else {
			m.successMsg = "PJSIP configuration reloaded successfully"
		}
	case 5: // Reload Dialplan
		if err := m.asteriskManager.ReloadDialplanQuiet(); err != nil {
			m.errorMsg = fmt.Sprintf("Failed to reload dialplan: %v", err)
		} else {
			m.successMsg = "Dialplan reloaded successfully"
		}
	case 6: // Reload All Modules
		if err := m.asteriskManager.ReloadAllQuiet(); err != nil {
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
	case 8: // Show PJSIP Transports
		output, err := m.asteriskManager.ShowTransports()
		if err != nil {
			m.errorMsg = fmt.Sprintf("Failed to show transports: %v", err)
		} else {
			m.asteriskOutput = output
			m.successMsg = "PJSIP transports retrieved"
		}
	case 9: // Show Active Channels
		output, err := m.asteriskManager.ShowChannels()
		if err != nil {
			m.errorMsg = fmt.Sprintf("Failed to show channels: %v", err)
		} else {
			m.asteriskOutput = output
			m.successMsg = "Active channels retrieved"
		}
	case 10: // Show Registrations
		output, err := m.asteriskManager.ShowPeers()
		if err != nil {
			m.errorMsg = fmt.Sprintf("Failed to show registrations: %v", err)
		} else {
			m.asteriskOutput = output
			m.successMsg = "Registrations retrieved"
		}
	case 11: // Back to Main Menu
		m.currentScreen = mainMenu
		m.cursor = m.mainMenuCursor
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

func (m *model) executeSipTestRegister() {
	if m.inputValues[0] == "" || m.inputValues[1] == "" {
		m.errorMsg = "Extension and password are required"
		return
	}

	ext := m.inputValues[0]
	pass := m.inputValues[1]
	server := m.inputValues[2]
	if server == "" {
		server = "127.0.0.1"
	}

	cmd := exec.Command("bash", SipTestScriptPath, "register", ext, pass, server)
	output, err := cmd.CombinedOutput()
	
	if err != nil {
		details := ParseCommandError(err, output)
		m.errorMsg = fmt.Sprintf("Test failed: %s (exit code %d)", details.ErrorType, details.ExitCode)
		m.sipTestOutput = FormatVerboseError(details)
	} else {
		m.successMsg = "Registration test completed"
		m.sipTestOutput = string(output)
	}

	m.inputMode = false
}

func (m *model) executeSipTestCall() {
	if len(m.inputValues) < 4 || m.inputValues[0] == "" || m.inputValues[1] == "" || 
		m.inputValues[2] == "" || m.inputValues[3] == "" {
		m.errorMsg = "All extension and password fields are required"
		return
	}

	fromExt := m.inputValues[0]
	fromPass := m.inputValues[1]
	toExt := m.inputValues[2]
	toPass := m.inputValues[3]
	server := m.inputValues[4]
	if server == "" {
		server = "127.0.0.1"
	}

	cmd := exec.Command("bash", SipTestScriptPath, "call", fromExt, fromPass, toExt, toPass, server)
	output, err := cmd.CombinedOutput()
	
	if err != nil {
		details := ParseCommandError(err, output)
		m.errorMsg = fmt.Sprintf("Test failed: %s (exit code %d)", details.ErrorType, details.ExitCode)
		m.sipTestOutput = FormatVerboseError(details)
	} else {
		m.successMsg = "Call test completed"
		m.sipTestOutput = string(output)
	}

	m.inputMode = false
}

func (m *model) executeSipTestFull() {
	if len(m.inputValues) < 4 || m.inputValues[0] == "" || m.inputValues[1] == "" || 
		m.inputValues[2] == "" || m.inputValues[3] == "" {
		m.errorMsg = "All extension and password fields are required"
		return
	}

	ext1 := m.inputValues[0]
	pass1 := m.inputValues[1]
	ext2 := m.inputValues[2]
	pass2 := m.inputValues[3]
	server := m.inputValues[4]
	if server == "" {
		server = "127.0.0.1"
	}

	cmd := exec.Command("bash", SipTestScriptPath, "full", ext1, pass1, ext2, pass2, server)
	output, err := cmd.CombinedOutput()
	
	if err != nil {
		details := ParseCommandError(err, output)
		m.errorMsg = fmt.Sprintf("Test failed: %s (exit code %d)", details.ErrorType, details.ExitCode)
		m.sipTestOutput = FormatVerboseError(details)
	} else {
		m.successMsg = "Full test suite completed"
		m.sipTestOutput = string(output)
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
	// Use absolute path for security
	upgradeScript := "/opt/rayanpbx/scripts/upgrade.sh"
	
	// Check if the script exists and is a regular file
	fileInfo, err := os.Stat(upgradeScript)
	if os.IsNotExist(err) {
		m.errorMsg = fmt.Sprintf("Upgrade script not found at: %s", upgradeScript)
		return
	}
	if err != nil {
		m.errorMsg = fmt.Sprintf("Error checking upgrade script: %v", err)
		return
	}
	if !fileInfo.Mode().IsRegular() {
		m.errorMsg = fmt.Sprintf("Upgrade script is not a regular file: %s", upgradeScript)
		return
	}
	
	// Display a message and exit TUI to run upgrade
	fmt.Println("\n🚀 Launching system upgrade...")
	fmt.Println("The TUI will close and the upgrade script will start.")
	fmt.Println()
	
	// Prepare the command with sudo
	cmd := exec.Command("sudo", "bash", upgradeScript)
	cmd.Stdin = os.Stdin
	cmd.Stdout = os.Stdout
	cmd.Stderr = os.Stderr
	
	// Execute the upgrade script
	if err := cmd.Run(); err != nil {
		// Check for specific error types
		if exitErr, ok := err.(*exec.ExitError); ok {
			fmt.Printf("Upgrade script exited with status %d\n", exitErr.ExitCode())
		} else {
			fmt.Printf("Error running upgrade script: %v\n", err)
		}
		os.Exit(1)
	}
	
	// Exit successfully after upgrade completes
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

// loadDocsList loads the list of markdown documentation files
func (m *model) loadDocsList() {
	m.docsList = []string{}
	
	// Look for markdown files in common locations
	paths := []string{
		"/opt/rayanpbx",
		".",
		"..",
	}
	
	for _, basePath := range paths {
		files, err := os.ReadDir(basePath)
		if err != nil {
			continue
		}
		
		for _, file := range files {
			if !file.IsDir() && strings.HasSuffix(strings.ToLower(file.Name()), ".md") {
				fullPath := filepath.Join(basePath, file.Name())
				m.docsList = append(m.docsList, fullPath)
			}
		}
		
		// If we found files, stop looking
		if len(m.docsList) > 0 {
			break
		}
	}
	
	// Sort the list
	sort.Strings(m.docsList)
}

// loadDocContent loads the content of a documentation file
func (m *model) loadDocContent(filePath string) {
	content, err := os.ReadFile(filePath)
	if err != nil {
		m.currentDocContent = fmt.Sprintf("Error reading file: %v", err)
		return
	}
	m.currentDocContent = string(content)
}

// renderDocsList renders the documentation files list
func (m model) renderDocsList() string {
	content := titleStyle.Render("📚 Documentation Browser") + "\n\n"
	
	if len(m.docsList) == 0 {
		content += "No documentation files found.\n"
		content += "Looking in: /opt/rayanpbx, current directory\n"
		return menuStyle.Render(content)
	}
	
	content += infoStyle.Render(fmt.Sprintf("Found %d documentation files:", len(m.docsList))) + "\n\n"
	
	for i, doc := range m.docsList {
		cursor := " "
		if i == m.selectedDocIdx {
			cursor = "▶"
		}
		
		// Just show the filename, not the full path
		filename := filepath.Base(doc)
		line := fmt.Sprintf("%s %s\n", cursor, filename)
		
		if i == m.selectedDocIdx {
			content += successStyle.Render(line)
		} else {
			content += line
		}
	}
	
	return menuStyle.Render(content)
}

// renderDocView renders the content of a documentation file
func (m model) renderDocView() string {
	if m.selectedDocIdx >= len(m.docsList) {
		return menuStyle.Render("No document selected")
	}
	
	filename := filepath.Base(m.docsList[m.selectedDocIdx])
	content := titleStyle.Render(fmt.Sprintf("📄 %s", filename)) + "\n\n"
	
	// Display the content with some basic formatting
	docContent := m.currentDocContent
	
	// Maximum lines to display in terminal view to avoid scrolling issues
	const maxDocDisplayLines = 40
	
	// Limit the display height to avoid overwhelming the terminal
	lines := strings.Split(docContent, "\n")
	if len(lines) > maxDocDisplayLines {
		docContent = strings.Join(lines[:maxDocDisplayLines], "\n")
		docContent += fmt.Sprintf("\n\n... (%d more lines)", len(lines)-maxDocDisplayLines)
	}
	
	content += docContent
	
	return menuStyle.Render(content)
}

// renderHelloWorld displays the Hello World automated setup wizard
func (m model) renderHelloWorld() string {
	content := titleStyle.Render("🚀 Hello World Setup - Quick Start Wizard") + "\n\n"
	
	// Status section
	content += infoStyle.Render("📊 Current Setup Status:") + "\n"
	
	// Transport status
	if m.helloWorldStatus.TransportConfigured {
		content += successStyle.Render("  ✅ Transport: Configured") + "\n"
	} else {
		content += errorStyle.Render("  ❌ Transport: Not configured") + "\n"
	}
	
	// Extension status
	if m.helloWorldStatus.ExtensionConfigured {
		content += successStyle.Render("  ✅ Extension 101: Configured") + "\n"
	} else {
		content += errorStyle.Render("  ❌ Extension 101: Not configured") + "\n"
	}
	
	// Dialplan status
	if m.helloWorldStatus.DialplanConfigured {
		content += successStyle.Render("  ✅ Dialplan (ext 100): Configured") + "\n"
	} else {
		content += errorStyle.Render("  ❌ Dialplan (ext 100): Not configured") + "\n"
	}
	
	// Sound file status
	if m.helloWorldStatus.SoundFileExists {
		content += successStyle.Render("  ✅ Sound file: Found") + "\n"
	} else {
		content += errorStyle.Render("  ❌ Sound file: Not found") + "\n"
	}
	
	// Asterisk status
	if m.helloWorldStatus.AsteriskRunning {
		content += successStyle.Render("  ✅ Asterisk: Running") + "\n"
	} else {
		content += errorStyle.Render("  ❌ Asterisk: Not running") + "\n"
	}
	content += "\n"
	
	// SIP Phone Configuration (show only if setup is complete)
	if m.helloWorldStatus.ExtensionConfigured && m.helloWorldStatus.DialplanConfigured {
		content += infoStyle.Render("📱 SIP Phone Configuration:") + "\n"
		username, password, server, port := m.helloWorldSetup.GetSIPCredentials()
		content += fmt.Sprintf("  • Username: %s\n", successStyle.Render(username))
		content += fmt.Sprintf("  • Password: %s\n", successStyle.Render(password))
		content += fmt.Sprintf("  • Server: %s\n", successStyle.Render(server))
		content += fmt.Sprintf("  • Port: %d\n", port)
		content += "\n"
		content += helpStyle.Render("  Use MicroSIP, Zoiper, or any SIP phone to register") + "\n"
		content += helpStyle.Render("  Then dial 100 to hear 'Hello World!'") + "\n\n"
	}
	
	// Menu
	content += infoStyle.Render("Select an action:") + "\n\n"
	
	for i, item := range m.helloWorldMenu {
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

// handleHelloWorldMenuSelection handles menu selection on the Hello World screen
func (m *model) handleHelloWorldMenuSelection() {
	m.errorMsg = ""
	m.successMsg = ""
	
	switch m.cursor {
	case 0: // Run Complete Setup
		if err := m.helloWorldSetup.SetupAll(); err != nil {
			m.errorMsg = fmt.Sprintf("Setup failed: %v", err)
		} else {
			m.successMsg = "Hello World setup completed successfully! Configure your SIP phone with the credentials shown above."
		}
		// Refresh status
		m.helloWorldStatus = m.helloWorldSetup.GetStatus()
		
	case 1: // Check Status
		m.helloWorldStatus = m.helloWorldSetup.GetStatus()
		m.successMsg = "Status refreshed"
		
	case 2: // Remove Setup
		if err := m.helloWorldSetup.RemoveSetup(); err != nil {
			m.errorMsg = fmt.Sprintf("Failed to remove setup: %v", err)
		} else {
			m.successMsg = "Hello World setup removed successfully"
		}
		// Refresh status
		m.helloWorldStatus = m.helloWorldSetup.GetStatus()
		
	case 3: // Back to Main Menu
		m.currentScreen = mainMenu
		m.cursor = m.mainMenuCursor
	}
}

// loadExtensionSyncInfo loads sync information for all extensions
func (m *model) loadExtensionSyncInfo() {
	if m.extensionSyncManager == nil {
		return
	}
	
	syncInfos, err := m.extensionSyncManager.CompareExtensions()
	if err != nil {
		m.errorMsg = fmt.Sprintf("Failed to load sync info: %v", err)
		return
	}
	
	m.extensionSyncInfos = syncInfos
}

// renderExtensionSync renders the extension sync management screen
func (m model) renderExtensionSync() string {
	content := titleStyle.Render("🔄 Extension Sync Manager") + "\n\n"
	
	// Show sync summary
	if m.extensionSyncManager != nil {
		total, matched, dbOnly, astOnly, mismatched, err := m.extensionSyncManager.GetSyncSummary()
		if err != nil {
			content += errorStyle.Render(fmt.Sprintf("Error: %v", err)) + "\n\n"
		} else {
			content += infoStyle.Render("📊 Sync Summary:") + "\n"
			content += fmt.Sprintf("   Total: %d extension(s)\n", total)
			if matched > 0 {
				content += successStyle.Render(fmt.Sprintf("   ✅ Synced: %d", matched)) + "\n"
			}
			if dbOnly > 0 {
				content += errorStyle.Render(fmt.Sprintf("   📦 DB Only: %d (not in Asterisk)", dbOnly)) + "\n"
			}
			if astOnly > 0 {
				content += errorStyle.Render(fmt.Sprintf("   ⚡ Asterisk Only: %d (not in DB)", astOnly)) + "\n"
			}
			if mismatched > 0 {
				content += errorStyle.Render(fmt.Sprintf("   ⚠️  Mismatched: %d", mismatched)) + "\n"
			}
			content += "\n"
		}
	}
	
	// Show extension list with sync status
	content += infoStyle.Render("📋 Extensions Status:") + "\n\n"
	
	if len(m.extensionSyncInfos) == 0 {
		content += "📭 No extensions found\n\n"
	} else {
		for i, info := range m.extensionSyncInfos {
			cursor := " "
			if i == m.selectedSyncIdx {
				cursor = "▶"
			}
			
			// Build status indicator
			var statusIcon string
			var statusText string
			switch info.SyncStatus {
			case SyncStatusMatch:
				statusIcon = "✅"
				statusText = "Synced"
			case SyncStatusDBOnly:
				statusIcon = "📦"
				statusText = "DB Only"
			case SyncStatusAsteriskOnly:
				statusIcon = "⚡"
				statusText = "Asterisk Only"
			case SyncStatusMismatch:
				statusIcon = "⚠️"
				statusText = "Mismatch"
			}
			
			// Get name
			name := fmt.Sprintf("Extension %s", info.ExtensionNumber)
			if info.DBExtension != nil && info.DBExtension.Name != "" {
				name = info.DBExtension.Name
			}
			
			line := fmt.Sprintf("%s %s %s - %s (%s)\n",
				cursor,
				statusIcon,
				successStyle.Render(info.ExtensionNumber),
				name,
				statusText,
			)
			
			if i == m.selectedSyncIdx {
				content += selectedItemStyle.Render(line)
				// Show differences if there are any
				if len(info.Differences) > 0 {
					for _, diff := range info.Differences {
						content += helpStyle.Render(fmt.Sprintf("   └─ %s", diff)) + "\n"
					}
				}
			} else {
				content += line
			}
		}
	}
	
	content += "\n"
	
	// Menu options
	content += infoStyle.Render("⚡ Actions:") + "\n\n"
	for i, item := range m.extensionSyncMenu {
		cursor := " "
		menuIdx := len(m.extensionSyncInfos) + i
		if m.cursor == menuIdx {
			cursor = "▶"
			item = selectedItemStyle.Render(item)
		}
		content += fmt.Sprintf("%s %s\n", cursor, item)
	}
	
	return menuStyle.Render(content)
}

// handleExtensionSyncSelection handles menu selection on the sync screen
func (m *model) handleExtensionSyncSelection() {
	m.errorMsg = ""
	m.successMsg = ""
	
	// Calculate menu index (cursor position relative to menu)
	menuIdx := m.cursor - len(m.extensionSyncInfos)
	
	// If cursor is on an extension, not a menu item
	if m.cursor < len(m.extensionSyncInfos) {
		m.selectedSyncIdx = m.cursor
		return
	}
	
	switch menuIdx {
	case 0: // Sync selected DB → Asterisk
		if m.selectedSyncIdx < len(m.extensionSyncInfos) {
			info := m.extensionSyncInfos[m.selectedSyncIdx]
			if info.DBExtension != nil {
				err := m.extensionSyncManager.SyncDatabaseToAsterisk(info.ExtensionNumber)
				if err != nil {
					m.errorMsg = fmt.Sprintf("Sync failed: %v", err)
				} else {
					m.successMsg = fmt.Sprintf("Extension %s synced to Asterisk", info.ExtensionNumber)
					m.loadExtensionSyncInfo()
				}
			} else {
				m.errorMsg = "Selected extension is not in database"
			}
		}
		
	case 1: // Sync selected Asterisk → DB
		if m.selectedSyncIdx < len(m.extensionSyncInfos) {
			info := m.extensionSyncInfos[m.selectedSyncIdx]
			if info.AsteriskConfig != nil {
				err := m.extensionSyncManager.SyncAsteriskToDatabase(info.ExtensionNumber)
				if err != nil {
					m.errorMsg = fmt.Sprintf("Sync failed: %v", err)
				} else {
					m.successMsg = fmt.Sprintf("Extension %s synced to database", info.ExtensionNumber)
					m.loadExtensionSyncInfo()
					// Reload extensions from DB
					if exts, err := GetExtensions(m.db); err == nil {
						m.extensions = exts
					}
				}
			} else {
				m.errorMsg = "Selected extension is not in Asterisk config"
			}
		}
		
	case 2: // Sync all DB → Asterisk
		synced, errors := m.extensionSyncManager.SyncAllDatabaseToAsterisk()
		if len(errors) > 0 {
			m.errorMsg = fmt.Sprintf("Synced %d, %d errors", synced, len(errors))
		} else {
			m.successMsg = fmt.Sprintf("All %d extensions synced to Asterisk", synced)
		}
		m.loadExtensionSyncInfo()
		
	case 3: // Sync all Asterisk → DB
		synced, errors := m.extensionSyncManager.SyncAllAsteriskToDatabase()
		if len(errors) > 0 {
			m.errorMsg = fmt.Sprintf("Synced %d, %d errors", synced, len(errors))
		} else {
			m.successMsg = fmt.Sprintf("All %d extensions synced to database", synced)
		}
		m.loadExtensionSyncInfo()
		// Reload extensions from DB
		if exts, err := GetExtensions(m.db); err == nil {
			m.extensions = exts
		}
		
	case 4: // Refresh
		m.loadExtensionSyncInfo()
		m.successMsg = "Sync status refreshed"
		
	case 5: // Back to Extensions
		m.currentScreen = extensionsScreen
		m.cursor = m.selectedExtensionIdx
	}
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
