package main

import (
	"fmt"
	"os"
	"strings"
	"sync"
	"time"
)

// Discovery state for background scanning
type discoveryState struct {
	isScanning      bool
	lastScanTime    time.Time
	scanError       string
	lldpError       string
	mutex           sync.Mutex
	// Pending discovered phones to be merged (thread-safe way to pass data)
	pendingPhones   []DiscoveredPhone
	hasPendingData  bool
}

// Global discovery state
var voipDiscoveryState = &discoveryState{}

// isRunningAsRoot checks if the current process is running as root
func isRunningAsRoot() bool {
	return os.Geteuid() == 0
}

// renderVoIPPhones renders the VoIP phones list screen
func (m model) renderVoIPPhones() string {
	content := infoStyle.Render("📱 VoIP Phones Management") + "\n"
	content += helpStyle.Render("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━") + "\n\n"
	
	// Show discovery status
	voipDiscoveryState.mutex.Lock()
	isScanning := voipDiscoveryState.isScanning
	scanError := voipDiscoveryState.scanError
	lldpError := voipDiscoveryState.lldpError
	voipDiscoveryState.mutex.Unlock()
	
	if isScanning {
		content += warningStyle.Render("🔍 Discovering phones on the network...") + "\n\n"
	}
	
	// Show LLDP warning only if not running as root
	if !isRunningAsRoot() {
		content += errorStyle.Render("⚠️  LLDP discovery requires root/sudo") + "\n\n"
	}
	
	if scanError != "" {
		content += errorStyle.Render("❌ Scan error: "+scanError) + "\n"
	}
	if lldpError != "" && isRunningAsRoot() {
		content += errorStyle.Render("❌ LLDP error: "+lldpError) + "\n"
	}
	
	if m.voipPhones == nil || len(m.voipPhones) == 0 {
		content += "📭 No phones detected\n\n"
		content += helpStyle.Render("💡 Phones are detected automatically via:") + "\n"
		content += helpStyle.Render("   • SIP registrations from Asterisk") + "\n"
		content += helpStyle.Render("   • LLDP network discovery") + "\n"
		content += helpStyle.Render("   • Network scanning (VoIP phone OUI detection)") + "\n\n"
		content += helpStyle.Render("   Press 'a' to manually add a phone by IP address") + "\n"
		content += helpStyle.Render("   Press 'A' to add all discovered phones")
		return menuStyle.Render(content)
	}
	
	content += fmt.Sprintf("📊 Total Phones: %s\n\n", successStyle.Render(fmt.Sprintf("%d", len(m.voipPhones))))
	
	// Header
	content += helpStyle.Render("  Extension      IP Address         Status        Vendor") + "\n"
	content += helpStyle.Render("  ──────────────────────────────────────────────────────") + "\n"
	
	for i, phone := range m.voipPhones {
		cursor := " "
		if i == m.selectedPhoneIdx {
			cursor = "▶"
		}
		
		// Status with emoji
		status := "🔴 Offline"
		statusStyle := errorStyle
		if phone.Status == "Registered" || phone.Status == "Available" || phone.Status == "online" {
			status = "🟢 Online"
			statusStyle = successStyle
		} else if phone.Status == "discovered" || phone.Status == "Manual" {
			status = "🟡 Added"
			statusStyle = warningStyle
		}
		
		// Extract vendor from user agent
		vendor := phone.UserAgent
		if len(vendor) > 15 {
			vendor = vendor[:15] + "..."
		}
		
		// Format extension
		ext := phone.Extension
		if ext == "" {
			ext = "---"
		}
		if len(ext) > 12 {
			ext = ext[:12]
		}
		
		line := fmt.Sprintf("%s %-12s  %-18s %s  %s\n",
			cursor,
			successStyle.Render(ext),
			phone.IP,
			statusStyle.Render(fmt.Sprintf("%-10s", status)),
			helpStyle.Render(vendor),
		)
		content += line
	}
	
	content += "\n" + helpStyle.Render("📌 Tips:") + "\n"
	content += helpStyle.Render("   ↑/↓  Select phone    Enter  View details/Add credentials") + "\n"
	content += helpStyle.Render("   a    Add manually    A      Add all discovered    r  Refresh    ESC  Back")
	
	return menuStyle.Render(content)
}

// renderVoIPPhoneDetails renders detailed information about a selected phone
func (m model) renderVoIPPhoneDetails() string {
	if m.selectedPhoneIdx >= len(m.voipPhones) {
		return menuStyle.Render(errorStyle.Render("No phone selected"))
	}
	
	phone := m.voipPhones[m.selectedPhoneIdx]
	
	content := infoStyle.Render(fmt.Sprintf("📱 Phone Details: %s", phone.Extension)) + "\n"
	content += helpStyle.Render("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━") + "\n\n"
	
	// Phone info output if available
	if m.voipPhoneOutput != "" {
		content += successStyle.Render("📋 Last Operation:") + "\n"
		content += "┌─────────────────────────────────────┐\n"
		outputLines := strings.Split(m.voipPhoneOutput, "\n")
		for _, line := range outputLines {
			if line != "" {
				content += "│ " + line + "\n"
			}
		}
		content += "└─────────────────────────────────────┘\n\n"
	}
	
	// Basic information in a nice box
	content += infoStyle.Render("📊 Basic Information") + "\n"
	content += "┌─────────────────────────────────────┐\n"
	content += fmt.Sprintf("│ Extension:   %s\n", successStyle.Render(phone.Extension))
	content += fmt.Sprintf("│ IP Address:  %s\n", phone.IP)
	
	// Status with color
	statusText := phone.Status
	if phone.Status == "Registered" || phone.Status == "Available" || phone.Status == "online" {
		statusText = "🟢 " + phone.Status
	} else if phone.Status == "discovered" || phone.Status == "Manual" {
		statusText = "🟡 " + phone.Status
	} else {
		statusText = "🔴 " + phone.Status
	}
	content += fmt.Sprintf("│ Status:      %s\n", statusText)
	
	if phone.UserAgent != "" {
		content += fmt.Sprintf("│ User Agent:  %s\n", phone.UserAgent)
	}
	content += "└─────────────────────────────────────┘\n"
	
	// Show credential status
	hasCredentials := false
	if m.phoneCredentials != nil {
		if creds, ok := m.phoneCredentials[phone.IP]; ok && creds["password"] != "" {
			hasCredentials = true
		}
	}
	
	if hasCredentials {
		content += "\n" + successStyle.Render("🔑 Credentials: Configured") + "\n"
	} else {
		content += "\n" + warningStyle.Render("🔑 Credentials: Not configured") + "\n"
		content += helpStyle.Render("   Press 'i' to add credentials for phone control") + "\n"
	}
	
	// Show phone status details if available
	if m.currentPhoneStatus != nil {
		content += "\n" + infoStyle.Render("🔧 Device Information") + "\n"
		content += "┌─────────────────────────────────────┐\n"
		content += fmt.Sprintf("│ Model:       %s\n", successStyle.Render(m.currentPhoneStatus.Model))
		content += fmt.Sprintf("│ Firmware:    %s\n", m.currentPhoneStatus.Firmware)
		content += fmt.Sprintf("│ MAC:         %s\n", m.currentPhoneStatus.MAC)
		content += fmt.Sprintf("│ Uptime:      %s\n", m.currentPhoneStatus.Uptime)
		content += "└─────────────────────────────────────┘\n"
		
		if len(m.currentPhoneStatus.Accounts) > 0 {
			content += "\n" + infoStyle.Render("📞 SIP Accounts") + "\n"
			content += "┌─────────────────────────────────────┐\n"
			for _, acc := range m.currentPhoneStatus.Accounts {
				statusIcon := "🔴"
				if acc.Status == "Registered" {
					statusIcon = "🟢"
				}
				content += fmt.Sprintf("│ %s Account %d: %s (%s)\n", 
					statusIcon, acc.Number, acc.Extension, acc.Status)
			}
			content += "└─────────────────────────────────────┘\n"
		}
		
		if m.currentPhoneStatus.NetworkInfo != nil {
			content += "\n🌐 Network Information:\n"
			content += fmt.Sprintf("  IP: %s\n", m.currentPhoneStatus.NetworkInfo.IP)
			content += fmt.Sprintf("  Subnet: %s\n", m.currentPhoneStatus.NetworkInfo.Subnet)
			content += fmt.Sprintf("  Gateway: %s\n", m.currentPhoneStatus.NetworkInfo.Gateway)
			content += fmt.Sprintf("  DHCP: %v\n", m.currentPhoneStatus.NetworkInfo.DHCP)
		}
	}
	
	content += "\n" + helpStyle.Render("💡 Press 'i' for credentials, 'c' for control menu, 'r' to refresh, ESC to go back")
	
	return menuStyle.Render(content)
}

// renderVoIPPhoneControl renders the phone control menu
func (m model) renderVoIPPhoneControl() string {
	if m.selectedPhoneIdx >= len(m.voipPhones) {
		return menuStyle.Render(errorStyle.Render("No phone selected"))
	}
	
	phone := m.voipPhones[m.selectedPhoneIdx]
	
	content := infoStyle.Render(fmt.Sprintf("🎛️  Phone Control: %s", phone.Extension)) + "\n"
	content += helpStyle.Render("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━") + "\n\n"
	
	// Show operation output if any
	if m.voipPhoneOutput != "" {
		content += successStyle.Render("📋 Output:") + "\n"
		content += "┌─────────────────────────────────────┐\n"
		outputLines := strings.Split(m.voipPhoneOutput, "\n")
		for _, line := range outputLines {
			if line != "" {
				content += "│ " + line + "\n"
			}
		}
		content += "└─────────────────────────────────────┘\n\n"
	}
	
	// Render menu items with section styling
	for i, item := range m.voipControlMenu {
		// Check if it's a separator line
		if strings.HasPrefix(item, "────") {
			content += helpStyle.Render("  ─────────────────────────────────") + "\n"
			continue
		}
		
		// Check if it's a section header
		if strings.HasSuffix(item, ":") && !strings.HasPrefix(item, "  ") {
			content += "\n" + infoStyle.Render(item) + "\n"
			continue
		}
		
		cursor := " "
		if m.cursor == i {
			cursor = "▶"
			// Skip separators and headers from selection
			item = selectedItemStyle.Render(item)
		} else {
			item = fmt.Sprintf("%s", item)
		}
		content += fmt.Sprintf("%s %s\n", cursor, item)
	}
	
	content += "\n" + helpStyle.Render("💡 Use ↑/↓ to navigate, Enter to select, ESC to go back")
	
	return menuStyle.Render(content)
}

// renderVoIPManualIP renders the manual IP input screen (used for both adding new phones and editing credentials)
func (m model) renderVoIPManualIP() string {
	var title string
	var helpText string
	
	if m.voipEditingExistingIP != "" {
		// Editing credentials for existing phone
		title = fmt.Sprintf("🔑 Edit Credentials: %s", m.voipEditingExistingIP)
		helpText = "💡 Enter credentials to control this phone"
	} else {
		// Adding new phone
		title = "📱 Add Phone by IP Address"
		helpText = "💡 Enter IP address and credentials to add and control the phone"
	}
	
	content := infoStyle.Render(title) + "\n\n"
	
	if m.voipPhoneOutput != "" {
		content += m.voipPhoneOutput + "\n\n"
	}
	
	for i, field := range m.inputFields {
		cursor := "  "
		var fieldText string
		if i == m.inputCursor {
			cursor = "▶ "
			fieldText = selectedItemStyle.Render(field)
		} else {
			fieldText = field
		}
		
		value := m.inputValues[i]
		if value == "" {
			switch field {
			case "IP Address":
				value = helpStyle.Render("<enter IP address>")
			case "Username":
				value = helpStyle.Render("<admin username, default: admin>")
			case "Password":
				value = helpStyle.Render("<admin password>")
			default:
				value = helpStyle.Render("<enter value>")
			}
		} else if field == "Password" {
			value = "********"
		}
		
		content += fmt.Sprintf("%s%s: %s\n", cursor, fieldText, value)
	}
	
	content += "\n" + helpStyle.Render(helpText)
	
	return menuStyle.Render(content)
}

// renderVoIPPhoneProvision renders the phone provisioning screen
func (m model) renderVoIPPhoneProvision() string {
	if m.selectedPhoneIdx >= len(m.voipPhones) {
		return menuStyle.Render(errorStyle.Render("No phone selected"))
	}
	
	phone := m.voipPhones[m.selectedPhoneIdx]
	
	content := infoStyle.Render(fmt.Sprintf("🔧 Provision Phone: %s", phone.IP)) + "\n\n"
	
	if m.voipPhoneOutput != "" {
		content += m.voipPhoneOutput + "\n\n"
	}
	
	// Show extension selection
	if len(m.extensions) == 0 {
		content += errorStyle.Render("No extensions available to provision\n")
		content += "\n" + helpStyle.Render("Create an extension first before provisioning")
		return menuStyle.Render(content)
	}
	
	content += "Select an extension to provision:\n\n"
	
	for i, ext := range m.extensions {
		cursor := " "
		if i == m.selectedExtensionIdx {
			cursor = "▶"
		}
		
		line := fmt.Sprintf("%s %s - %s\n",
			cursor,
			successStyle.Render(ext.ExtensionNumber),
			ext.Name,
		)
		content += line
	}
	
	// Account number selection
	content += "\n" + helpStyle.Render("Account Number (Line): ")
	if m.inputMode && len(m.inputValues) > 0 {
		content += m.inputValues[0]
	} else {
		content += helpStyle.Render("1")
	}
	
	content += "\n\n" + helpStyle.Render("💡 Use ↑/↓ to select extension, Enter to provision")
	
	return menuStyle.Render(content)
}

// initVoIPPhonesScreen initializes the VoIP phones screen
func (m *model) initVoIPPhonesScreen() {
	m.currentScreen = voipPhonesScreen
	m.selectedPhoneIdx = 0
	m.voipPhoneOutput = ""
	m.errorMsg = ""
	m.successMsg = ""
	m.currentPhoneStatus = nil
	
	// Initialize phone discovery
	if m.phoneDiscovery == nil {
		if m.phoneManager == nil {
			m.phoneManager = NewPhoneManager(m.asteriskManager)
		}
		m.phoneDiscovery = NewPhoneDiscovery(m.phoneManager)
	}
	
	// Load registered phones and trigger background discovery
	m.loadRegisteredPhonesWithDiscovery()
}

// loadRegisteredPhones loads phones from Asterisk registrations and database
func (m *model) loadRegisteredPhones() {
	if m.phoneManager == nil {
		m.phoneManager = NewPhoneManager(m.asteriskManager)
	}
	
	// Get phones from Asterisk
	phones, err := m.phoneManager.GetRegisteredPhones()
	if err != nil {
		m.errorMsg = fmt.Sprintf("Failed to load phones from Asterisk: %v", err)
		phones = []PhoneInfo{}
	}
	
	// Get phones from database
	if m.db != nil {
		dbPhones, err := GetVoIPPhones(m.db)
		if err == nil && dbPhones != nil {
			// Merge database phones with Asterisk phones
			phoneMap := make(map[string]PhoneInfo)
			
			// Add Asterisk phones first
			for _, p := range phones {
				phoneMap[p.IP] = p
			}
			
			// Add/update with database phones
			for _, dbp := range dbPhones {
				if existing, ok := phoneMap[dbp.IP]; ok {
					// Update existing phone with DB info
					if dbp.Extension != "" && existing.Extension == "" {
						existing.Extension = dbp.Extension
					}
					if dbp.UserAgent != "" {
						existing.UserAgent = dbp.UserAgent
					}
					phoneMap[dbp.IP] = existing
				} else {
					// Add database-only phone
					phoneMap[dbp.IP] = PhoneInfo{
						Extension: dbp.Extension,
						IP:        dbp.IP,
						Status:    dbp.Status,
						UserAgent: dbp.UserAgent,
					}
				}
			}
			
			// Convert map back to slice
			phones = make([]PhoneInfo, 0, len(phoneMap))
			for _, p := range phoneMap {
				phones = append(phones, p)
			}
		}
	}
	
	m.voipPhones = phones
	if len(phones) > 0 {
		m.successMsg = fmt.Sprintf("Found %d phone(s)", len(phones))
	}
}

// handleVoIPPhonesKeyPress handles key presses in VoIP phones screens
func (m *model) handleVoIPPhonesKeyPress(key string) {
	switch m.currentScreen {
	case voipPhonesScreen:
		switch key {
		case "up", "k":
			if m.selectedPhoneIdx > 0 {
				m.selectedPhoneIdx--
			} else if len(m.voipPhones) > 0 {
				m.selectedPhoneIdx = len(m.voipPhones) - 1
			}
		case "down", "j":
			if m.selectedPhoneIdx < len(m.voipPhones)-1 {
				m.selectedPhoneIdx++
			} else if len(m.voipPhones) > 0 {
				m.selectedPhoneIdx = 0
			}
		case "enter":
			// Show phone details or go to add credentials if no phones
			if len(m.voipPhones) > 0 {
				// Check if this phone has credentials, if not redirect to add credentials
				phone := m.voipPhones[m.selectedPhoneIdx]
				hasCredentials := false
				if m.phoneCredentials != nil {
					if creds, ok := m.phoneCredentials[phone.IP]; ok && creds["password"] != "" {
						hasCredentials = true
					}
				}
				
				if !hasCredentials {
					// Redirect to add credentials page with IP pre-filled
					m.initManualIPInputWithIP(phone.IP)
				} else {
					m.currentScreen = voipPhoneDetailsScreen
					m.refreshPhoneStatus()
				}
			} else {
				// No phones, go to add screen
				m.initManualIPInput()
			}
		case "a":
			// Manual IP input (add phone)
			m.initManualIPInput()
		case "A":
			// Add all discovered phones
			m.addAllDiscoveredPhones()
		case "r":
			// Process any pending discovered phones first
			m.processPendingDiscoveredPhones()
			// Refresh phone list with background discovery
			m.loadRegisteredPhonesWithDiscovery()
		}
		
	case voipPhoneDetailsScreen:
		switch key {
		case "c":
			// Control menu
			m.initVoIPControlMenu()
		case "i":
			// Add/edit credentials for the current phone
			if m.selectedPhoneIdx < len(m.voipPhones) {
				phone := m.voipPhones[m.selectedPhoneIdx]
				m.initManualIPInputWithIP(phone.IP)
			}
		case "r":
			// Refresh phone status
			m.refreshPhoneStatus()
		case "p":
			// Provision phone
			m.initVoIPProvisionScreen()
		}
		
	case voipPhoneControlScreen:
		switch key {
		case "up", "k":
			if m.cursor > 0 {
				m.cursor--
			} else if len(m.voipControlMenu) > 0 {
				m.cursor = len(m.voipControlMenu) - 1
			}
		case "down", "j":
			if m.cursor < len(m.voipControlMenu)-1 {
				m.cursor++
			} else if len(m.voipControlMenu) > 0 {
				m.cursor = 0
			}
		case "enter":
			m.executeVoIPControlAction()
		}
	}
}

// initVoIPControlMenu initializes the VoIP control menu
func (m *model) initVoIPControlMenu() {
	m.currentScreen = voipPhoneControlScreen
	m.cursor = 0
	m.voipPhoneOutput = ""
	m.errorMsg = ""
	m.successMsg = ""
	
	m.voipControlMenu = []string{
		"📊 Get Phone Status",
		"🔄 Reboot Phone",
		"🏭 Factory Reset",
		"📋 Get Configuration",
		"⚙️ Set Configuration",
		"🔧 Provision Extension",
		"📡 TR-069 Management",
		"🔗 Webhook Configuration",
		"📊 Live Monitoring",
		"────────────────────", // Separator
		"📞 CTI/CSTA Operations:",
		"  📱 Get Phone State",
		"  ✅ Accept Call",
		"  ❌ Reject Call",
		"  🔚 End Call",
		"  ⏸️  Hold Call",
		"  ▶️  Resume Call",
		"  📲 Dial Number",
		"  🔢 Send DTMF",
		"  ↗️  Blind Transfer",
		"  🚫 Toggle DND",
		"────────────────────", // Separator
		"🔧 Enable CTI Features",
		"🧪 Test CTI/SNMP",
		"🔙 Back to Details",
	}
}

// initManualIPInput initializes manual IP input screen for adding a new phone
func (m *model) initManualIPInput() {
	m.currentScreen = voipManualIPScreen
	m.inputMode = true
	m.inputFields = []string{"IP Address", "Username", "Password"}
	m.inputValues = []string{"", "admin", ""}
	m.inputCursor = 0
	m.voipPhoneOutput = ""
	m.errorMsg = ""
	m.successMsg = ""
	m.voipEditingExistingIP = "" // Not editing existing phone
}

// initManualIPInputWithIP initializes manual IP input screen for editing credentials of an existing phone
func (m *model) initManualIPInputWithIP(ip string) {
	m.currentScreen = voipManualIPScreen
	m.inputMode = true
	m.inputFields = []string{"IP Address", "Username", "Password"}
	// Pre-fill IP and set cursor to Username field
	existingUsername := "admin"
	if m.phoneCredentials != nil {
		if creds, ok := m.phoneCredentials[ip]; ok {
			if username, hasUser := creds["username"]; hasUser && username != "" {
				existingUsername = username
			}
		}
	}
	m.inputValues = []string{ip, existingUsername, ""}
	m.inputCursor = 1 // Start at Username field since IP is already filled
	m.voipPhoneOutput = ""
	m.errorMsg = ""
	m.successMsg = ""
	m.voipEditingExistingIP = ip // Track that we're editing existing phone
}

// addAllDiscoveredPhones adds all phones from discoveredPhones to voipPhones and saves to DB
func (m *model) addAllDiscoveredPhones() {
	if len(m.discoveredPhones) == 0 {
		m.errorMsg = "No discovered phones to add"
		return
	}
	
	addedCount := 0
	for _, discovered := range m.discoveredPhones {
		// Check if phone already exists in the list
		exists := false
		for _, existing := range m.voipPhones {
			if existing.IP == discovered.IP {
				exists = true
				break
			}
		}
		
		if !exists {
			// Add to in-memory list
			phoneInfo := PhoneInfo{
				Extension: "",
				IP:        discovered.IP,
				Status:    "discovered",
				UserAgent: fmt.Sprintf("%s %s", discovered.Vendor, discovered.Model),
				Online:    discovered.Online,
			}
			m.voipPhones = append(m.voipPhones, phoneInfo)
			
			// Save to database
			if m.db != nil {
				dbPhone := &VoIPPhoneDB{
					IP:            discovered.IP,
					MAC:           discovered.MAC,
					Vendor:        discovered.Vendor,
					Model:         discovered.Model,
					Status:        "discovered",
					DiscoveryType: discovered.DiscoveryType,
					UserAgent:     fmt.Sprintf("%s %s", discovered.Vendor, discovered.Model),
				}
				SaveVoIPPhone(m.db, dbPhone)
			}
			addedCount++
		}
	}
	
	if addedCount > 0 {
		m.successMsg = fmt.Sprintf("Added %d phone(s) to the list", addedCount)
	} else {
		m.successMsg = "All discovered phones are already in the list"
	}
}

// loadRegisteredPhonesWithDiscovery loads phones and triggers background discovery
func (m *model) loadRegisteredPhonesWithDiscovery() {
	// Process any pending discovered phones from previous background scan
	m.processPendingDiscoveredPhones()
	
	// Load registered phones normally
	m.loadRegisteredPhones()
	
	// Initialize phone discovery if not already done
	if m.phoneDiscovery == nil {
		if m.phoneManager == nil {
			m.phoneManager = NewPhoneManager(m.asteriskManager)
		}
		m.phoneDiscovery = NewPhoneDiscovery(m.phoneManager)
	}
	
	// Run discovery in background
	go m.runBackgroundDiscovery()
}

// runBackgroundDiscovery runs LLDP and network discovery in background
func (m *model) runBackgroundDiscovery() {
	voipDiscoveryState.mutex.Lock()
	if voipDiscoveryState.isScanning {
		voipDiscoveryState.mutex.Unlock()
		return // Already scanning
	}
	voipDiscoveryState.isScanning = true
	voipDiscoveryState.scanError = ""
	voipDiscoveryState.lldpError = ""
	voipDiscoveryState.pendingPhones = nil
	voipDiscoveryState.hasPendingData = false
	voipDiscoveryState.mutex.Unlock()
	
	defer func() {
		voipDiscoveryState.mutex.Lock()
		voipDiscoveryState.isScanning = false
		voipDiscoveryState.lastScanTime = time.Now()
		voipDiscoveryState.mutex.Unlock()
	}()
	
	// Get network subnet from config or use default
	network := DefaultNetworkSubnet
	if m.config != nil && m.config.NetworkSubnet != "" {
		network = m.config.NetworkSubnet
	}
	
	var allDiscovered []DiscoveredPhone
	
	// Try LLDP discovery (only works as root)
	if isRunningAsRoot() {
		lldpPhones, err := m.phoneDiscovery.GetLLDPNeighbors()
		if err != nil {
			voipDiscoveryState.mutex.Lock()
			voipDiscoveryState.lldpError = err.Error()
			voipDiscoveryState.mutex.Unlock()
		} else {
			allDiscovered = append(allDiscovered, lldpPhones...)
		}
	}
	
	// Try network scanning
	scanPhones, err := m.phoneDiscovery.DiscoverPhones(network)
	if err != nil {
		voipDiscoveryState.mutex.Lock()
		voipDiscoveryState.scanError = err.Error()
		voipDiscoveryState.mutex.Unlock()
	} else {
		allDiscovered = append(allDiscovered, scanPhones...)
	}
	
	// Store pending phones for UI thread to process
	if len(allDiscovered) > 0 {
		voipDiscoveryState.mutex.Lock()
		voipDiscoveryState.pendingPhones = allDiscovered
		voipDiscoveryState.hasPendingData = true
		voipDiscoveryState.mutex.Unlock()
	}
}

// processPendingDiscoveredPhones processes any pending discovered phones from background scan
// This should be called from the main UI thread
func (m *model) processPendingDiscoveredPhones() {
	voipDiscoveryState.mutex.Lock()
	if !voipDiscoveryState.hasPendingData {
		voipDiscoveryState.mutex.Unlock()
		return
	}
	pending := voipDiscoveryState.pendingPhones
	voipDiscoveryState.pendingPhones = nil
	voipDiscoveryState.hasPendingData = false
	voipDiscoveryState.mutex.Unlock()
	
	// Now safe to modify model data on UI thread
	m.mergeDiscoveredPhones(pending)
}

// mergeDiscoveredPhones merges discovered phones into the existing list and saves to DB
func (m *model) mergeDiscoveredPhones(discovered []DiscoveredPhone) {
	for _, disc := range discovered {
		// Check if phone already exists
		exists := false
		for i, existing := range m.voipPhones {
			if existing.IP == disc.IP {
				// Update existing phone info if we have better data
				if disc.Vendor != "" && (existing.UserAgent == "" || existing.UserAgent == "Unknown") {
					m.voipPhones[i].UserAgent = fmt.Sprintf("%s %s", disc.Vendor, disc.Model)
				}
				if disc.Online {
					m.voipPhones[i].Online = true
				}
				exists = true
				break
			}
		}
		
		if !exists {
			// Add new discovered phone
			phoneInfo := PhoneInfo{
				Extension: "",
				IP:        disc.IP,
				Status:    "discovered",
				UserAgent: fmt.Sprintf("%s %s", disc.Vendor, disc.Model),
				Online:    disc.Online,
			}
			m.voipPhones = append(m.voipPhones, phoneInfo)
			m.discoveredPhones = append(m.discoveredPhones, disc)
			
			// Save to database
			if m.db != nil {
				dbPhone := &VoIPPhoneDB{
					IP:            disc.IP,
					MAC:           disc.MAC,
					Vendor:        disc.Vendor,
					Model:         disc.Model,
					Status:        "discovered",
					DiscoveryType: disc.DiscoveryType,
					UserAgent:     fmt.Sprintf("%s %s", disc.Vendor, disc.Model),
				}
				SaveVoIPPhone(m.db, dbPhone)
			}
		}
	}
}

// initVoIPProvisionScreen initializes the provision screen
func (m *model) initVoIPProvisionScreen() {
	m.currentScreen = voipPhoneProvisionScreen
	m.selectedExtensionIdx = 0
	m.inputMode = true
	m.inputFields = []string{"Account Number"}
	m.inputValues = []string{"1"}
	m.inputCursor = 0
	m.voipPhoneOutput = ""
	m.errorMsg = ""
	m.successMsg = ""
	
	// Load extensions if not loaded
	if len(m.extensions) == 0 {
		if exts, err := GetExtensions(m.db); err == nil {
			m.extensions = exts
		}
	}
}

// refreshPhoneStatus refreshes the current phone status
func (m *model) refreshPhoneStatus() {
	if m.selectedPhoneIdx >= len(m.voipPhones) {
		return
	}
	
	phone := m.voipPhones[m.selectedPhoneIdx]
	
	m.voipPhoneOutput = "Retrieving phone status...\n"
	
	// Detect vendor
	vendor, err := m.phoneManager.DetectPhoneVendor(phone.IP)
	if err != nil {
		m.errorMsg = fmt.Sprintf("Failed to detect phone vendor: %v", err)
		m.voipPhoneOutput = ""
		return
	}
	
	// Get credentials - use stored credentials or default
	credentials := map[string]string{
		"username": "admin",
		"password": "", // Empty password will fail, forcing user to provide
	}
	
	if m.phoneCredentials != nil {
		if creds, ok := m.phoneCredentials[phone.IP]; ok {
			credentials = creds
		}
	}
	
	// If no stored credentials, redirect to add credentials page
	if credentials["password"] == "" {
		m.voipPhoneOutput = ""
		m.initManualIPInputWithIP(phone.IP)
		return
	}
	
	phoneInstance, err := m.phoneManager.CreatePhone(phone.IP, vendor, credentials)
	if err != nil {
		m.errorMsg = fmt.Sprintf("Failed to create phone instance: %v", err)
		m.voipPhoneOutput = ""
		return
	}
	
	// Get status
	status, err := phoneInstance.GetStatus()
	if err != nil {
		m.errorMsg = fmt.Sprintf("Failed to get phone status: %v", err)
		m.voipPhoneOutput = ""
		return
	}
	
	m.currentPhoneStatus = status
	m.successMsg = "Phone status retrieved successfully"
	m.voipPhoneOutput = ""
}

// executeVoIPControlAction executes the selected control action
func (m *model) executeVoIPControlAction() {
	if m.selectedPhoneIdx >= len(m.voipPhones) {
		return
	}
	
	phone := m.voipPhones[m.selectedPhoneIdx]
	
	// Get credentials from stored credentials or prompt for manual entry
	credentials := map[string]string{
		"username": "admin",
		"password": "",
	}
	
	if m.phoneCredentials != nil {
		if creds, ok := m.phoneCredentials[phone.IP]; ok {
			credentials = creds
		}
	}
	
	// Check if we have credentials - if not, redirect to add credentials page
	if credentials["password"] == "" {
		m.initManualIPInputWithIP(phone.IP)
		return
	}
	
	vendor := "grandstream" // Default to GrandStream
	phoneInstance, err := m.phoneManager.CreatePhone(phone.IP, vendor, credentials)
	if err != nil {
		m.errorMsg = fmt.Sprintf("Failed to create phone instance: %v", err)
		return
	}
	
	m.errorMsg = ""
	m.successMsg = ""
	m.voipPhoneOutput = ""
	
	switch m.cursor {
	case 0: // Get Phone Status
		status, err := phoneInstance.GetStatus()
		if err != nil {
			m.errorMsg = fmt.Sprintf("Failed to get status: %v", err)
		} else {
			m.currentPhoneStatus = status
			output := fmt.Sprintf("Model: %s\nFirmware: %s\nMAC: %s\nUptime: %s\n", 
				status.Model, status.Firmware, status.MAC, status.Uptime)
			if len(status.Accounts) > 0 {
				output += "\nSIP Accounts:\n"
				for _, acc := range status.Accounts {
					output += fmt.Sprintf("  Account %d: %s (%s)\n", acc.Number, acc.Extension, acc.Status)
				}
			}
			m.voipPhoneOutput = output
			m.successMsg = "Status retrieved successfully"
		}
		
	case 1: // Reboot Phone
		err := phoneInstance.Reboot()
		if err != nil {
			m.errorMsg = fmt.Sprintf("Failed to reboot: %v", err)
		} else {
			m.successMsg = "Reboot command sent successfully"
			m.voipPhoneOutput = "Phone is rebooting... This may take a few minutes."
		}
		
	case 2: // Factory Reset
		err := phoneInstance.FactoryReset()
		if err != nil {
			m.errorMsg = fmt.Sprintf("Failed to factory reset: %v", err)
		} else {
			m.successMsg = "Factory reset command sent successfully"
			m.voipPhoneOutput = "Phone is resetting to factory defaults... This may take a few minutes."
		}
		
	case 3: // Get Configuration
		config, err := phoneInstance.GetConfig()
		if err != nil {
			m.errorMsg = fmt.Sprintf("Failed to get config: %v", err)
		} else {
			var output strings.Builder
			output.WriteString("Current Configuration:\n")
			for key, value := range config {
				output.WriteString(fmt.Sprintf("  %s: %v\n", key, value))
			}
			m.voipPhoneOutput = output.String()
			m.successMsg = "Configuration retrieved successfully"
		}
		
	case 4: // Set Configuration
		m.voipPhoneOutput = "Configuration setting not yet implemented in TUI.\nUse Web UI for advanced configuration."
		
	case 5: // Provision Extension
		m.initVoIPProvisionScreen()
		
	case 6: // TR-069 Management
		m.voipPhoneOutput = "TR-069 Management:\n\n"
		m.voipPhoneOutput += "TR-069 (CWMP) provides advanced management capabilities:\n"
		m.voipPhoneOutput += "- Firmware updates\n"
		m.voipPhoneOutput += "- Remote configuration\n"
		m.voipPhoneOutput += "- Parameter monitoring\n"
		m.voipPhoneOutput += "- Bulk operations\n\n"
		m.voipPhoneOutput += "Use the Web UI or API for TR-069 management."
		
	case 7: // Webhook Configuration
		m.voipPhoneOutput = "Webhook Configuration:\n\n"
		m.voipPhoneOutput += "Configure webhooks for phone events:\n"
		m.voipPhoneOutput += "- Registration events\n"
		m.voipPhoneOutput += "- Call start/end events\n"
		m.voipPhoneOutput += "- Configuration changes\n\n"
		
		// Get server address from config or environment
		serverAddr := "your-server"
		if m.config != nil && m.config.APIBaseURL != "" {
			serverAddr = strings.TrimPrefix(m.config.APIBaseURL, "http://")
			serverAddr = strings.TrimPrefix(serverAddr, "https://")
			serverAddr = strings.TrimSuffix(serverAddr, "/api")
		}
		
		m.voipPhoneOutput += fmt.Sprintf("Webhook URL: http://%s/api/phones/webhook\n", serverAddr)
		m.voipPhoneOutput += "Configure in phone web interface under Events/Hooks."
		
	case 8: // Live Monitoring
		m.voipPhoneOutput = "Live Monitoring:\n\n"
		if m.currentPhoneStatus != nil {
			m.voipPhoneOutput += fmt.Sprintf("Phone: %s\n", phone.IP)
			m.voipPhoneOutput += fmt.Sprintf("Status: %s\n", m.currentPhoneStatus.Vendor)
			m.voipPhoneOutput += fmt.Sprintf("Model: %s\n", m.currentPhoneStatus.Model)
			m.voipPhoneOutput += fmt.Sprintf("Firmware: %s\n", m.currentPhoneStatus.Firmware)
			m.voipPhoneOutput += fmt.Sprintf("Active Calls: %d\n", m.currentPhoneStatus.ActiveCalls)
			m.voipPhoneOutput += fmt.Sprintf("Registered: %v\n", m.currentPhoneStatus.Registered)
		} else {
			m.voipPhoneOutput += "No status data available. Get phone status first."
		}
		
	case 9: // Separator - do nothing
		// Separator line
		
	case 10: // CTI/CSTA header - do nothing
		m.voipPhoneOutput = "CTI/CSTA Operations:\n\n"
		m.voipPhoneOutput += "Computer-Telephony Integration (CTI) and\n"
		m.voipPhoneOutput += "Computer Supported Telecommunications Applications (CSTA)\n"
		m.voipPhoneOutput += "provide programmatic control over phone operations.\n\n"
		m.voipPhoneOutput += "Select an operation from the menu below."
		
	case 11: // Get Phone State
		gsPhone, ok := phoneInstance.(*GrandStreamPhone)
		if !ok {
			m.errorMsg = "CTI operations only available for GrandStream phones"
			return
		}
		state, err := gsPhone.GetPhoneState()
		if err != nil {
			m.errorMsg = fmt.Sprintf("Failed to get phone state: %v", err)
		} else {
			var output strings.Builder
			output.WriteString("Phone State:\n\n")
			output.WriteString(fmt.Sprintf("  DND Enabled: %v\n", state.DNDEnabled))
			output.WriteString(fmt.Sprintf("  Forward Enabled: %v\n", state.ForwardEnabled))
			if state.ForwardTarget != "" {
				output.WriteString(fmt.Sprintf("  Forward Target: %s\n", state.ForwardTarget))
			}
			output.WriteString(fmt.Sprintf("  Message Waiting: %v\n", state.MWI))
			output.WriteString(fmt.Sprintf("  Active Line: %d\n", state.ActiveLine))
			if len(state.Calls) > 0 {
				output.WriteString("\nActive Calls:\n")
				for _, call := range state.Calls {
					output.WriteString(fmt.Sprintf("  Line %d: %s (%s) - %s\n", 
						call.LineID, call.RemoteNumber, call.Direction, call.State))
				}
			} else {
				output.WriteString("\nNo active calls\n")
			}
			m.voipPhoneOutput = output.String()
			m.successMsg = "Phone state retrieved successfully"
		}
		
	case 12: // Accept Call
		err := phoneInstance.AcceptCall(1)
		if err != nil {
			m.errorMsg = fmt.Sprintf("Failed to accept call: %v", err)
		} else {
			m.successMsg = "Accept call command sent successfully"
		}
		
	case 13: // Reject Call
		err := phoneInstance.RejectCall(1)
		if err != nil {
			m.errorMsg = fmt.Sprintf("Failed to reject call: %v", err)
		} else {
			m.successMsg = "Reject call command sent successfully"
		}
		
	case 14: // End Call
		err := phoneInstance.EndCall(1)
		if err != nil {
			m.errorMsg = fmt.Sprintf("Failed to end call: %v", err)
		} else {
			m.successMsg = "End call command sent successfully"
		}
		
	case 15: // Hold Call
		err := phoneInstance.HoldCall(1)
		if err != nil {
			m.errorMsg = fmt.Sprintf("Failed to hold call: %v", err)
		} else {
			m.successMsg = "Hold call command sent successfully"
		}
		
	case 16: // Resume Call
		err := phoneInstance.ResumeCall(1)
		if err != nil {
			m.errorMsg = fmt.Sprintf("Failed to resume call: %v", err)
		} else {
			m.successMsg = "Resume call command sent successfully"
		}
		
	case 17: // Dial Number
		// Dial functionality requires interactive input which is complex in TUI
		// Users should use the Web API or Web UI for dialing
		m.voipPhoneOutput = "Dial Number:\n\n"
		m.voipPhoneOutput += "To dial a number programmatically, use the Web API:\n"
		m.voipPhoneOutput += "POST /api/grandstream/cti/operation\n"
		m.voipPhoneOutput += "{\n"
		m.voipPhoneOutput += "  \"ip\": \"" + phone.IP + "\",\n"
		m.voipPhoneOutput += "  \"operation\": \"dial\",\n"
		m.voipPhoneOutput += "  \"number\": \"<destination>\"\n"
		m.voipPhoneOutput += "}\n\n"
		m.voipPhoneOutput += "Or use the Web UI for interactive dialing."
		
	case 18: // Send DTMF
		m.voipPhoneOutput = "Send DTMF:\n\n"
		m.voipPhoneOutput += "To send DTMF tones, use the Web API:\n"
		m.voipPhoneOutput += "POST /api/phones/control\n"
		m.voipPhoneOutput += "{\n"
		m.voipPhoneOutput += "  \"ip\": \"" + phone.IP + "\",\n"
		m.voipPhoneOutput += "  \"action\": \"dtmf\",\n"
		m.voipPhoneOutput += "  \"digits\": \"<dtmf-digits>\"\n"
		m.voipPhoneOutput += "}"
		
	case 19: // Blind Transfer
		m.voipPhoneOutput = "Blind Transfer:\n\n"
		m.voipPhoneOutput += "To perform blind transfer, use the Web API:\n"
		m.voipPhoneOutput += "POST /api/phones/control\n"
		m.voipPhoneOutput += "{\n"
		m.voipPhoneOutput += "  \"ip\": \"" + phone.IP + "\",\n"
		m.voipPhoneOutput += "  \"action\": \"blind_transfer\",\n"
		m.voipPhoneOutput += "  \"target\": \"<extension>\"\n"
		m.voipPhoneOutput += "}"
		
	case 20: // Toggle DND
		gsPhone, ok := phoneInstance.(*GrandStreamPhone)
		if !ok {
			m.errorMsg = "DND toggle only available for GrandStream phones"
			return
		}
		// Get current state first
		state, err := gsPhone.GetPhoneState()
		if err != nil {
			m.errorMsg = fmt.Sprintf("Failed to get phone state: %v", err)
			return
		}
		// Toggle DND
		newDND := !state.DNDEnabled
		err = gsPhone.SetDND(newDND)
		if err != nil {
			m.errorMsg = fmt.Sprintf("Failed to toggle DND: %v", err)
		} else {
			if newDND {
				m.successMsg = "DND enabled successfully"
			} else {
				m.successMsg = "DND disabled successfully"
			}
		}
		
	case 21: // Separator - do nothing
		// Separator line
		
	case 22: // Enable CTI Features
		gsPhone, ok := phoneInstance.(*GrandStreamPhone)
		if !ok {
			m.errorMsg = "CTI features only available for GrandStream phones"
			return
		}
		// Enable CTI with SNMP
		snmpConfig := &SNMPConfig{
			Enabled:   true,
			Community: "public",
			Version:   "v2c",
		}
		err := gsPhone.EnableCTIFeatures(true, snmpConfig)
		if err != nil {
			m.errorMsg = fmt.Sprintf("Failed to enable CTI features: %v", err)
		} else {
			m.successMsg = "CTI and SNMP features enabled successfully"
			m.voipPhoneOutput = "CTI Features Enabled:\n\n"
			m.voipPhoneOutput += "✅ CTI API access enabled\n"
			m.voipPhoneOutput += "✅ SNMP monitoring enabled\n"
			m.voipPhoneOutput += "✅ Community: public\n"
			m.voipPhoneOutput += "✅ Version: v2c\n\n"
			m.voipPhoneOutput += "You may need to reboot the phone for all changes to take effect."
		}
		
	case 23: // Test CTI/SNMP
		gsPhone, ok := phoneInstance.(*GrandStreamPhone)
		if !ok {
			m.errorMsg = "CTI test only available for GrandStream phones"
			return
		}
		ctiOK, snmpOK, err := gsPhone.TestCTIFeatures()
		if err != nil {
			m.errorMsg = fmt.Sprintf("CTI test error: %v", err)
		}
		
		var output strings.Builder
		output.WriteString("CTI/SNMP Test Results:\n\n")
		if ctiOK {
			output.WriteString("✅ CTI API: Working\n")
		} else {
			output.WriteString("❌ CTI API: Not working or not enabled\n")
		}
		if snmpOK {
			output.WriteString("✅ SNMP: Enabled\n")
		} else {
			output.WriteString("❌ SNMP: Not enabled\n")
		}
		
		if !ctiOK || !snmpOK {
			output.WriteString("\n💡 Use 'Enable CTI Features' to enable these features.\n")
		}
		
		m.voipPhoneOutput = output.String()
		m.successMsg = "CTI/SNMP test completed"
		
	case 24: // Back to Details
		m.currentScreen = voipPhoneDetailsScreen
	}
}

// executeManualIPAdd executes the manual IP add action
func (m *model) executeManualIPAdd() {
	if len(m.inputValues) < 3 {
		m.errorMsg = "All fields are required"
		return
	}
	
	ip := m.inputValues[0]
	username := m.inputValues[1]
	password := m.inputValues[2]
	
	if ip == "" {
		m.errorMsg = "IP address is required"
		return
	}
	
	if password == "" {
		m.errorMsg = "Password is required"
		return
	}
	
	// Detect vendor
	vendor, err := m.phoneManager.DetectPhoneVendor(ip)
	if err != nil {
		m.errorMsg = fmt.Sprintf("Failed to connect to phone: %v", err)
		return
	}
	
	// Check if phone already exists, update if so
	phoneExists := false
	for i, existing := range m.voipPhones {
		if existing.IP == ip {
			phoneExists = true
			// Update existing phone info
			m.voipPhones[i].UserAgent = strings.ToUpper(vendor)
			m.voipPhones[i].Status = "Manual"
			break
		}
	}
	
	if !phoneExists {
		// Add to phone list
		newPhone := PhoneInfo{
			Extension: "",
			IP:        ip,
			Status:    "Manual",
			UserAgent: strings.ToUpper(vendor),
		}
		m.voipPhones = append(m.voipPhones, newPhone)
	}
	
	// Store credentials for future use
	if m.phoneCredentials == nil {
		m.phoneCredentials = make(map[string]map[string]string)
	}
	m.phoneCredentials[ip] = map[string]string{
		"username": username,
		"password": password,
	}
	
	// Save to database if available
	if m.db != nil {
		dbPhone := &VoIPPhoneDB{
			IP:            ip,
			Vendor:        vendor,
			Status:        "discovered",
			DiscoveryType: "manual",
			UserAgent:     strings.ToUpper(vendor),
		}
		if err := SaveVoIPPhone(m.db, dbPhone); err != nil {
			// Non-fatal error, just log it
			m.errorMsg = fmt.Sprintf("Phone updated but failed to save to database: %v", err)
			m.inputMode = false
			m.currentScreen = voipPhonesScreen
			return
		}
	}
	
	if phoneExists {
		m.successMsg = fmt.Sprintf("Credentials updated for phone: %s (%s)", ip, vendor)
	} else {
		m.successMsg = fmt.Sprintf("Phone added: %s (%s)", ip, vendor)
	}
	m.inputMode = false
	m.currentScreen = voipPhonesScreen
}

// executeVoIPProvision executes the phone provisioning action
func (m *model) executeVoIPProvision() {
	if m.selectedPhoneIdx >= len(m.voipPhones) {
		m.errorMsg = "No phone selected"
		return
	}
	
	if m.selectedExtensionIdx >= len(m.extensions) {
		m.errorMsg = "No extension selected"
		return
	}
	
	phone := m.voipPhones[m.selectedPhoneIdx]
	ext := m.extensions[m.selectedExtensionIdx]
	
	accountNumber := 1
	if len(m.inputValues) > 0 && m.inputValues[0] != "" {
		fmt.Sscanf(m.inputValues[0], "%d", &accountNumber)
	}
	
	// Get credentials from stored credentials
	credentials := map[string]string{
		"username": "admin",
		"password": "",
	}
	
	if m.phoneCredentials != nil {
		if creds, ok := m.phoneCredentials[phone.IP]; ok {
			credentials = creds
		}
	}
	
	// Check if we have credentials - if not, redirect to add credentials page
	if credentials["password"] == "" {
		m.initManualIPInputWithIP(phone.IP)
		return
	}
	
	vendor := "grandstream"
	phoneInstance, err := m.phoneManager.CreatePhone(phone.IP, vendor, credentials)
	if err != nil {
		m.errorMsg = fmt.Sprintf("Failed to create phone instance: %v", err)
		return
	}
	
	// Provision extension
	err = phoneInstance.ProvisionExtension(ext, accountNumber)
	if err != nil {
		m.errorMsg = fmt.Sprintf("Failed to provision: %v", err)
		return
	}
	
	m.successMsg = fmt.Sprintf("Extension %s provisioned on account %d", ext.ExtensionNumber, accountNumber)
	m.inputMode = false
	m.currentScreen = voipPhoneDetailsScreen
}

// renderVoIPDiscovery renders the phone discovery screen
func (m model) renderVoIPDiscovery() string {
content := infoStyle.Render("🔍 VoIP Phone Discovery") + "\n\n"

if m.voipPhoneOutput != "" {
content += m.voipPhoneOutput + "\n\n"
}

if len(m.discoveredPhones) == 0 {
content += "📭 No phones discovered yet\n\n"
content += helpStyle.Render("💡 Press 's' to scan network for VoIP phones\n")
content += helpStyle.Render("   Press 'l' to discover via LLDP (requires root/sudo)\n")
content += helpStyle.Render("   ESC to go back")
return menuStyle.Render(content)
}

content += fmt.Sprintf("Discovered Phones: %s\n\n", successStyle.Render(fmt.Sprintf("%d", len(m.discoveredPhones))))

for i, phone := range m.discoveredPhones {
cursor := " "
if i == m.selectedPhoneIdx {
cursor = "▶"
}

status := "🔴 Offline"
if phone.Online {
status = "🟢 Online"
}

vendor := phone.Vendor
if vendor == "" {
vendor = "Unknown"
}

model := phone.Model
if model == "" {
model = "Unknown"
}

discoveryType := ""
switch phone.DiscoveryType {
case "lldp":
discoveryType = "📡 LLDP"
case "nmap":
discoveryType = "🔍 Scan"
case "http":
discoveryType = "🌐 HTTP"
}

line := fmt.Sprintf("%s %s - %s %s/%s (%s) %s\n",
cursor,
phone.IP,
status,
successStyle.Render(vendor),
model,
phone.MAC,
discoveryType,
)
content += line
}

content += "\n" + helpStyle.Render("💡 's' to scan, 'l' for LLDP, 'r' to check reachability, 'a' to add selected phone, ESC to go back")

return menuStyle.Render(content)
}

// initVoIPDiscoveryScreen initializes the phone discovery screen
func (m *model) initVoIPDiscoveryScreen() {
m.currentScreen = voipDiscoveryScreen
m.selectedPhoneIdx = 0
m.voipPhoneOutput = ""
m.errorMsg = ""
m.successMsg = ""

// Initialize phone discovery if not already done
if m.phoneDiscovery == nil {
if m.phoneManager == nil {
m.phoneManager = NewPhoneManager(m.asteriskManager)
}
m.phoneDiscovery = NewPhoneDiscovery(m.phoneManager)
}
}

// discoverPhones discovers phones on the network
func (m *model) discoverPhones(network string) {
if m.phoneDiscovery == nil {
m.errorMsg = "Phone discovery not initialized"
return
}

m.voipPhoneOutput = "🔍 Scanning network for VoIP phones...\nThis may take a few moments..."

phones, err := m.phoneDiscovery.DiscoverPhones(network)
if err != nil {
m.errorMsg = fmt.Sprintf("Discovery failed: %v", err)
m.voipPhoneOutput = ""
return
}

m.discoveredPhones = phones
m.successMsg = fmt.Sprintf("Found %d phone(s)", len(phones))
m.voipPhoneOutput = ""
}

// discoverViaLLDP discovers phones using LLDP protocol
func (m *model) discoverViaLLDP() {
if m.phoneDiscovery == nil {
m.errorMsg = "Phone discovery not initialized"
return
}

m.voipPhoneOutput = "📡 Discovering phones via LLDP...\nNote: This may require root/sudo privileges"

phones, err := m.phoneDiscovery.GetLLDPNeighbors()
if err != nil {
m.errorMsg = fmt.Sprintf("LLDP discovery failed: %v\nMake sure lldpd is installed or run with sudo", err)
m.voipPhoneOutput = ""
return
}

m.discoveredPhones = phones
m.successMsg = fmt.Sprintf("Found %d phone(s) via LLDP", len(phones))
m.voipPhoneOutput = ""
}

// checkDiscoveredPhonesReachability checks if discovered phones are reachable
func (m *model) checkDiscoveredPhonesReachability() {
if m.phoneDiscovery == nil || len(m.discoveredPhones) == 0 {
return
}

m.voipPhoneOutput = "🔍 Checking reachability of discovered phones..."

for i := range m.discoveredPhones {
m.discoveredPhones[i].Online = m.phoneDiscovery.PingHost(m.discoveredPhones[i].IP, 2)
}

onlineCount := 0
for _, phone := range m.discoveredPhones {
if phone.Online {
onlineCount++
}
}

m.successMsg = fmt.Sprintf("%d of %d phone(s) are online", onlineCount, len(m.discoveredPhones))
m.voipPhoneOutput = ""
}

// addDiscoveredPhone adds the selected discovered phone to the phone list
func (m *model) addDiscoveredPhone() {
if m.selectedPhoneIdx >= len(m.discoveredPhones) {
return
}

phone := m.discoveredPhones[m.selectedPhoneIdx]

// Convert to PhoneInfo
phoneInfo := PhoneInfo{
Extension: fmt.Sprintf("Discovered-%s", phone.MAC),
IP:        phone.IP,
Status:    "Discovered",
UserAgent: fmt.Sprintf("%s %s", phone.Vendor, phone.Model),
Online:    phone.Online,
}

// Add to phone list
m.voipPhones = append(m.voipPhones, phoneInfo)
m.successMsg = fmt.Sprintf("Added phone at %s to the list", phone.IP)

// Switch back to phones screen
m.currentScreen = voipPhonesScreen
}

// handleVoIPDiscoveryKeyPress handles key presses in VoIP discovery screen
func (m *model) handleVoIPDiscoveryKeyPress(key string) {
	// Get network subnet from config or use default
	network := "192.168.1.0/24"
	if m.config != nil && m.config.NetworkSubnet != "" {
		network = m.config.NetworkSubnet
	}
	
	switch key {
	case "s":
		// Scan network
		m.discoverPhones(network)
	case "l":
		// LLDP discovery
		m.discoverViaLLDP()
	case "r":
		// Check reachability
		m.checkDiscoveredPhonesReachability()
	case "a":
		// Add selected phone
		if len(m.discoveredPhones) > 0 {
			m.addDiscoveredPhone()
		}
	}
}
