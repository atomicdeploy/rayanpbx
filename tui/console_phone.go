package main

import (
	"fmt"
	"strings"
)

// initConsolePhoneScreen initializes the console phone/intercom screen
func (m *model) initConsolePhoneScreen() {
	m.currentScreen = consolePhoneScreen
	m.cursor = 0
	m.consolePhoneOutput = ""
	m.errorMsg = ""
	m.successMsg = ""
	
	// Initialize direct call manager if needed
	if m.directCallManager == nil {
		m.directCallManager = NewDirectCallManager(m.asteriskManager)
	}
	
	// Get initial console status
	m.consolePhoneStatus = m.directCallManager.GetConsoleStatus()
}

// renderConsolePhone renders the console phone screen
func (m model) renderConsolePhone() string {
	content := infoStyle.Render("🎙️  Console Phone / Intercom") + "\n"
	content += helpStyle.Render("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━") + "\n\n"
	
	// Show console status
	content += infoStyle.Render("📊 Console Status:") + "\n"
	content += "┌─────────────────────────────────────────────────────────────────────┐\n"
	
	if m.consolePhoneStatus != nil {
		content += fmt.Sprintf("│ Channel:      %s\n", m.consolePhoneStatus.Channel)
		content += fmt.Sprintf("│ State:        %s\n", FormatCallStatus(m.consolePhoneStatus.State))
		
		if m.consolePhoneStatus.RemoteParty != "" {
			content += fmt.Sprintf("│ Remote Party: %s\n", m.consolePhoneStatus.RemoteParty)
			content += fmt.Sprintf("│ Direction:    %s\n", m.consolePhoneStatus.Direction)
		}
		
		if m.consolePhoneStatus.DNDEnabled {
			content += "│ DND:          🚫 Enabled\n"
		}
		if m.consolePhoneStatus.Muted {
			content += "│ Muted:        🔇 Yes\n"
		}
	} else {
		content += "│ Status: Not initialized\n"
	}
	content += "└─────────────────────────────────────────────────────────────────────┘\n\n"
	
	// Show output if any
	if m.consolePhoneOutput != "" {
		content += successStyle.Render("📋 Output:") + "\n"
		content += "┌─────────────────────────────────────────────────────────────────────┐\n"
		outputLines := strings.Split(m.consolePhoneOutput, "\n")
		for _, line := range outputLines {
			if line != "" {
				// Truncate long lines
				if len(line) > 67 {
					line = line[:67] + "..."
				}
				content += "│ " + line + "\n"
			}
		}
		content += "└─────────────────────────────────────────────────────────────────────┘\n\n"
	}
	
	// Show menu
	content += infoStyle.Render("📞 Operations:") + "\n\n"
	for i, item := range m.consolePhoneMenu {
		cursor := " "
		if m.cursor == i {
			cursor = "▶"
			item = selectedItemStyle.Render(item)
		}
		content += fmt.Sprintf("  %s %s\n", cursor, item)
	}
	
	// Show help based on state
	content += "\n"
	if m.consolePhoneStatus != nil && m.consolePhoneStatus.State == CallStateRinging && 
	   m.consolePhoneStatus.Direction == "inbound" {
		content += warningStyle.Render("🔔 Incoming call! Use 'Answer Incoming Call' to pick up.") + "\n"
	}
	
	content += "\n" + helpStyle.Render("💡 Use ↑/↓ to navigate, Enter to select, ESC to go back")
	
	// Extension assignment note
	content += "\n" + helpStyle.Render(fmt.Sprintf("   Console Extension: %s", ConsoleExtension))
	
	return menuStyle.Render(content)
}

// handleConsolePhoneKeyPress handles key presses in console phone screen
func (m *model) handleConsolePhoneKeyPress(key string) {
	switch key {
	case "up", "k":
		if m.cursor > 0 {
			m.cursor--
		} else if len(m.consolePhoneMenu) > 0 {
			m.cursor = len(m.consolePhoneMenu) - 1
		}
	case "down", "j":
		if m.cursor < len(m.consolePhoneMenu)-1 {
			m.cursor++
		} else if len(m.consolePhoneMenu) > 0 {
			m.cursor = 0
		}
	case "enter":
		m.executeConsolePhoneAction()
	}
}

// executeConsolePhoneAction executes the selected console phone action
func (m *model) executeConsolePhoneAction() {
	if m.cursor >= len(m.consolePhoneMenu) {
		return
	}
	
	menuItem := m.consolePhoneMenu[m.cursor]
	m.errorMsg = ""
	m.successMsg = ""
	m.consolePhoneOutput = ""
	
	// Initialize direct call manager if needed
	if m.directCallManager == nil {
		m.directCallManager = NewDirectCallManager(m.asteriskManager)
	}
	
	switch {
	case strings.Contains(menuItem, "Dial Extension"):
		// Show input for extension number
		m.inputMode = true
		m.inputFields = []string{"Extension Number"}
		m.inputValues = []string{""}
		m.inputCursor = 0
		m.consolePhoneOutput = "Enter the extension number to dial:\n\n" +
			"Examples: 100, 101, 200\n\n" +
			"The call will use your host machine's\n" +
			"microphone and speakers."
		
	case strings.Contains(menuItem, "Call Phone by IP (Audio File)"):
		// Show input for IP and audio file
		m.inputMode = true
		m.inputFields = []string{"Phone IP Address", "Audio File Path"}
		m.inputValues = []string{"", "/var/lib/asterisk/sounds/en/hello-world"}
		m.inputCursor = 0
		m.consolePhoneOutput = "Enter the phone IP and audio file to play:\n\n" +
			"The audio file will be played to the called party.\n" +
			"File extension (.wav, .gsm) is optional."
		
	case strings.Contains(menuItem, "Call Phone by IP (Console)"):
		// Show input for IP
		m.inputMode = true
		m.inputFields = []string{"Phone IP Address", "Extension (optional)"}
		m.inputValues = []string{"", ""}
		m.inputCursor = 0
		m.consolePhoneOutput = "Enter the phone IP address to call:\n\n" +
			"The call will use your host machine's\n" +
			"microphone and speakers.\n\n" +
			"Extension is optional (uses console extension by default)."
		
	case strings.Contains(menuItem, "Answer Incoming Call"):
		result := m.directCallManager.AnswerConsole()
		if result.Success {
			m.successMsg = result.Message
			m.consolePhoneStatus = m.directCallManager.GetConsoleStatus()
		} else {
			m.errorMsg = result.Error
		}
		
	case strings.Contains(menuItem, "Hangup"):
		result := m.directCallManager.HangupConsole()
		if result.Success {
			m.successMsg = result.Message
			m.consolePhoneStatus = m.directCallManager.GetConsoleStatus()
		} else {
			m.errorMsg = result.Error
		}
		
	case strings.Contains(menuItem, "Console Status"):
		m.consolePhoneStatus = m.directCallManager.GetConsoleStatus()
		
		var output strings.Builder
		output.WriteString("Console Channel Status:\n\n")
		output.WriteString(fmt.Sprintf("  Channel:    %s\n", m.consolePhoneStatus.Channel))
		output.WriteString(fmt.Sprintf("  State:      %s\n", FormatCallStatus(m.consolePhoneStatus.State)))
		
		if m.consolePhoneStatus.RemoteParty != "" {
			output.WriteString(fmt.Sprintf("  Remote:     %s\n", m.consolePhoneStatus.RemoteParty))
			output.WriteString(fmt.Sprintf("  Direction:  %s\n", m.consolePhoneStatus.Direction))
		}
		
		if m.consolePhoneStatus.DNDEnabled {
			output.WriteString("  DND:        🚫 Enabled\n")
		}
		if m.consolePhoneStatus.Muted {
			output.WriteString("  Muted:      🔇 Yes\n")
		}
		
		m.consolePhoneOutput = output.String()
		m.successMsg = "Console status refreshed"
		
	case strings.Contains(menuItem, "Configure Console Endpoint"):
		result := m.directCallManager.ConfigureConsoleEndpoint()
		
		if result.Success {
			m.successMsg = result.Message
			m.consolePhoneOutput = fmt.Sprintf("Console Configuration:\n\n%s\n\n"+
				"Dialplan Configuration (add to extensions.conf):\n\n%s",
				result.Message,
				m.directCallManager.GetConsoleDialplanConfig())
		} else {
			m.errorMsg = result.Error
			m.consolePhoneOutput = "Console configuration failed.\n\n" +
				"Make sure:\n" +
				"• ALSA/OSS sound device is configured\n" +
				"• chan_console.so module is loaded\n" +
				"• /etc/asterisk/console.conf exists and is configured"
		}
		
	case strings.Contains(menuItem, "Show Active Calls"):
		calls := m.directCallManager.ListActiveCalls()
		
		if len(calls) == 0 {
			m.consolePhoneOutput = "No active calls"
			m.successMsg = "No active calls found"
			return
		}
		
		var output strings.Builder
		output.WriteString(fmt.Sprintf("Active Calls: %d\n\n", len(calls)))
		
		for i, call := range calls {
			output.WriteString(fmt.Sprintf("Call %d:\n", i+1))
			output.WriteString(fmt.Sprintf("  ID:          %s\n", call.CallID))
			output.WriteString(fmt.Sprintf("  Destination: %s\n", call.Destination))
			output.WriteString(fmt.Sprintf("  State:       %s\n", FormatCallStatus(call.State)))
			output.WriteString(fmt.Sprintf("  Mode:        %s\n", call.Mode))
			if call.Channel != "" {
				output.WriteString(fmt.Sprintf("  Channel:     %s\n", call.Channel))
			}
			output.WriteString(fmt.Sprintf("  Started:     %s\n", call.StartedAt.Format("15:04:05")))
			output.WriteString("\n")
		}
		
		m.consolePhoneOutput = output.String()
		m.successMsg = fmt.Sprintf("Found %d active call(s)", len(calls))
		
	case strings.Contains(menuItem, "Back to Main Menu"):
		m.currentScreen = mainMenu
		m.cursor = m.mainMenuCursor
	}
}

// handleConsolePhoneInput handles input mode for console phone screen
func (m *model) handleConsolePhoneInput() {
	// Get current input
	if len(m.inputValues) == 0 {
		return
	}
	
	// Initialize direct call manager if needed
	if m.directCallManager == nil {
		m.directCallManager = NewDirectCallManager(m.asteriskManager)
	}
	
	menuItem := m.consolePhoneMenu[m.cursor]
	
	switch {
	case strings.Contains(menuItem, "Dial Extension"):
		extension := m.inputValues[0]
		if extension == "" {
			m.errorMsg = "Extension number is required"
			return
		}
		
		result := m.directCallManager.DialFromConsole(extension, 30)
		
		if result.Success {
			m.successMsg = result.Message
			m.consolePhoneOutput = fmt.Sprintf("Call ID: %s\nDialing extension %s...\n\n"+
				"Use your host machine's microphone and speakers.",
				result.CallID, extension)
			m.consolePhoneStatus = m.directCallManager.GetConsoleStatus()
		} else {
			m.errorMsg = result.Error
		}
		
	case strings.Contains(menuItem, "Call Phone by IP (Audio File)"):
		ip := m.inputValues[0]
		audioFile := ""
		if len(m.inputValues) > 1 {
			audioFile = m.inputValues[1]
		}
		
		if ip == "" {
			m.errorMsg = "Phone IP address is required"
			return
		}
		if audioFile == "" {
			m.errorMsg = "Audio file path is required"
			return
		}
		
		result := m.directCallManager.OriginateCall(ip, CallModeAudioFile, audioFile, "RayanPBX", 30)
		
		if result.Success {
			m.successMsg = result.Message
			m.consolePhoneOutput = fmt.Sprintf("Call ID: %s\nStatus: %s\nDestination: %s\n\n"+
				"Playing audio file: %s",
				result.CallID,
				FormatCallStatus(result.State),
				ip,
				audioFile)
		} else {
			m.errorMsg = result.Error
		}
		
	case strings.Contains(menuItem, "Call Phone by IP (Console)"):
		ip := m.inputValues[0]
		extension := ""
		if len(m.inputValues) > 1 {
			extension = m.inputValues[1]
		}
		
		if ip == "" {
			m.errorMsg = "Phone IP address is required"
			return
		}
		
		destination := ip
		if extension != "" {
			destination = fmt.Sprintf("%s@%s", extension, ip)
		}
		
		result := m.directCallManager.OriginateCall(destination, CallModeConsole, "", "RayanPBX", 30)
		
		if result.Success {
			m.successMsg = result.Message
			m.consolePhoneOutput = fmt.Sprintf("Call ID: %s\nStatus: %s\nDestination: %s\n\n"+
				"Use your host machine's microphone and speakers.",
				result.CallID,
				FormatCallStatus(result.State),
				destination)
			m.consolePhoneStatus = m.directCallManager.GetConsoleStatus()
		} else {
			m.errorMsg = result.Error
		}
	}
	
	m.inputMode = false
}
