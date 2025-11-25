package main

import (
	"fmt"
	"strings"
)

// renderVoIPPhones renders the VoIP phones list screen
func (m model) renderVoIPPhones() string {
	content := infoStyle.Render("📱 VoIP Phones Management") + "\n\n"
	
	if m.voipPhones == nil || len(m.voipPhones) == 0 {
		content += "📭 No phones detected\n\n"
		content += helpStyle.Render("💡 Phones are detected from SIP registrations\n")
		content += helpStyle.Render("   Press 'm' to manually add a phone by IP address")
		return menuStyle.Render(content)
	}
	
	content += fmt.Sprintf("Total Phones: %s\n\n", successStyle.Render(fmt.Sprintf("%d", len(m.voipPhones))))
	
	for i, phone := range m.voipPhones {
		cursor := " "
		if i == m.selectedPhoneIdx {
			cursor = "▶"
		}
		
		status := "🔴 Offline"
		if phone.Status == "Registered" || phone.Status == "Available" {
			status = "🟢 Online"
		}
		
		line := fmt.Sprintf("%s %s - %s (%s) %s\n",
			cursor,
			successStyle.Render(phone.Extension),
			phone.IP,
			phone.UserAgent,
			status,
		)
		content += line
	}
	
	content += "\n" + helpStyle.Render("💡 Tip: Use ↑/↓ to select, Enter for details, 'm' to add manually, 'd' to discover phones")
	
	return menuStyle.Render(content)
}

// renderVoIPPhoneDetails renders detailed information about a selected phone
func (m model) renderVoIPPhoneDetails() string {
	if m.selectedPhoneIdx >= len(m.voipPhones) {
		return menuStyle.Render(errorStyle.Render("No phone selected"))
	}
	
	phone := m.voipPhones[m.selectedPhoneIdx]
	
	content := infoStyle.Render(fmt.Sprintf("📱 Phone Details: %s", phone.Extension)) + "\n\n"
	
	// Phone info output if available
	if m.voipPhoneOutput != "" {
		content += "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n"
		content += m.voipPhoneOutput + "\n"
		content += "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n"
	}
	
	// Basic information
	content += "📊 Basic Information:\n"
	content += fmt.Sprintf("  Extension: %s\n", successStyle.Render(phone.Extension))
	content += fmt.Sprintf("  IP Address: %s\n", phone.IP)
	content += fmt.Sprintf("  Status: %s\n", phone.Status)
	if phone.UserAgent != "" {
		content += fmt.Sprintf("  User Agent: %s\n", phone.UserAgent)
	}
	
	// Show phone status details if available
	if m.currentPhoneStatus != nil {
		content += "\n🔧 Device Information:\n"
		content += fmt.Sprintf("  Model: %s\n", m.currentPhoneStatus.Model)
		content += fmt.Sprintf("  Firmware: %s\n", m.currentPhoneStatus.Firmware)
		content += fmt.Sprintf("  MAC: %s\n", m.currentPhoneStatus.MAC)
		content += fmt.Sprintf("  Uptime: %s\n", m.currentPhoneStatus.Uptime)
		
		if len(m.currentPhoneStatus.Accounts) > 0 {
			content += "\n📞 SIP Accounts:\n"
			for _, acc := range m.currentPhoneStatus.Accounts {
				statusIcon := "🔴"
				if acc.Status == "Registered" {
					statusIcon = "🟢"
				}
				content += fmt.Sprintf("  %s Account %d: %s (%s)\n", 
					statusIcon, acc.Number, acc.Extension, acc.Status)
			}
		}
		
		if m.currentPhoneStatus.NetworkInfo != nil {
			content += "\n🌐 Network Information:\n"
			content += fmt.Sprintf("  IP: %s\n", m.currentPhoneStatus.NetworkInfo.IP)
			content += fmt.Sprintf("  Subnet: %s\n", m.currentPhoneStatus.NetworkInfo.Subnet)
			content += fmt.Sprintf("  Gateway: %s\n", m.currentPhoneStatus.NetworkInfo.Gateway)
			content += fmt.Sprintf("  DHCP: %v\n", m.currentPhoneStatus.NetworkInfo.DHCP)
		}
	}
	
	content += "\n" + helpStyle.Render("💡 Press 'c' for control menu, 'r' to refresh, ESC to go back")
	
	return menuStyle.Render(content)
}

// renderVoIPPhoneControl renders the phone control menu
func (m model) renderVoIPPhoneControl() string {
	if m.selectedPhoneIdx >= len(m.voipPhones) {
		return menuStyle.Render(errorStyle.Render("No phone selected"))
	}
	
	phone := m.voipPhones[m.selectedPhoneIdx]
	
	content := infoStyle.Render(fmt.Sprintf("🎛️  Phone Control: %s", phone.Extension)) + "\n\n"
	
	// Show operation output if any
	if m.voipPhoneOutput != "" {
		content += "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n"
		content += m.voipPhoneOutput + "\n"
		content += "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n"
	}
	
	content += "Select an operation:\n\n"
	
	for i, item := range m.voipControlMenu {
		cursor := " "
		if m.cursor == i {
			cursor = "▶"
			item = selectedItemStyle.Render(item)
		} else {
			item = fmt.Sprintf("%s", item)
		}
		content += fmt.Sprintf("%s %s\n", cursor, item)
	}
	
	content += "\n" + helpStyle.Render("💡 Use ↑/↓ to navigate, Enter to select, ESC to go back")
	
	return menuStyle.Render(content)
}

// renderVoIPManualIP renders the manual IP input screen
func (m model) renderVoIPManualIP() string {
	content := infoStyle.Render("📱 Add Phone by IP Address") + "\n\n"
	
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
	
	content += "\n" + helpStyle.Render("💡 Enter IP address and credentials to control the phone")
	
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
	
	// Load registered phones
	m.loadRegisteredPhones()
}

// loadRegisteredPhones loads phones from Asterisk registrations
func (m *model) loadRegisteredPhones() {
	if m.phoneManager == nil {
		m.phoneManager = NewPhoneManager(m.asteriskManager)
	}
	
	phones, err := m.phoneManager.GetRegisteredPhones()
	if err != nil {
		m.errorMsg = fmt.Sprintf("Failed to load phones: %v", err)
		m.voipPhones = []PhoneInfo{}
		return
	}
	
	m.voipPhones = phones
	if len(phones) > 0 {
		m.successMsg = fmt.Sprintf("Found %d registered phone(s)", len(phones))
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
			// Show phone details
			if len(m.voipPhones) > 0 {
				m.currentScreen = voipPhoneDetailsScreen
				m.refreshPhoneStatus()
			}
		case "m":
			// Manual IP input
			m.initManualIPInput()
		case "d":
			// Phone discovery
			m.initVoIPDiscoveryScreen()
		case "r":
			// Refresh phone list
			m.loadRegisteredPhones()
		}
		
	case voipPhoneDetailsScreen:
		switch key {
		case "c":
			// Control menu
			m.initVoIPControlMenu()
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
		"🔙 Back to Details",
	}
}

// initManualIPInput initializes manual IP input screen
func (m *model) initManualIPInput() {
	m.currentScreen = voipManualIPScreen
	m.inputMode = true
	m.inputFields = []string{"IP Address", "Username", "Password"}
	m.inputValues = []string{"", "admin", ""}
	m.inputCursor = 0
	m.voipPhoneOutput = ""
	m.errorMsg = ""
	m.successMsg = ""
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
	
	// If no stored credentials, prompt user to add phone manually first
	if credentials["password"] == "" {
		m.errorMsg = "No credentials available. Please add phone manually with 'm' to provide credentials."
		m.voipPhoneOutput = ""
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
	
	// Check if we have credentials
	if credentials["password"] == "" {
		m.errorMsg = "No credentials available. Please add phone manually with 'm' to provide credentials."
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
		
	case 9: // Back to Details
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
	
	// Detect vendor
	vendor, err := m.phoneManager.DetectPhoneVendor(ip)
	if err != nil {
		m.errorMsg = fmt.Sprintf("Failed to connect to phone: %v", err)
		return
	}
	
	// Add to phone list
	newPhone := PhoneInfo{
		Extension: "Manual",
		IP:        ip,
		Status:    "Manual",
		UserAgent: strings.ToUpper(vendor),
	}
	
	m.voipPhones = append(m.voipPhones, newPhone)
	m.successMsg = fmt.Sprintf("Phone added: %s (%s)", ip, vendor)
	m.inputMode = false
	m.currentScreen = voipPhonesScreen
	
	// Store credentials for future use
	if m.phoneCredentials == nil {
		m.phoneCredentials = make(map[string]map[string]string)
	}
	m.phoneCredentials[ip] = map[string]string{
		"username": username,
		"password": password,
	}
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
	
	// Check if we have credentials
	if credentials["password"] == "" {
		m.errorMsg = "No credentials available. Please add phone manually with 'm' to provide credentials."
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
