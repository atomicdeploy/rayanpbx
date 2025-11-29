package main

import (
	"fmt"
	"strings"

	tea "github.com/charmbracelet/bubbletea"
)

// initDialplanScreen initializes the dialplan management screen
func (m *model) initDialplanScreen() {
	m.currentScreen = dialplanScreen
	m.cursor = 0
	m.errorMsg = ""
	m.successMsg = ""
	m.dialplanOutput = ""
	m.dialplanPreview = ""
}

// handleDialplanScreen processes input for the dialplan screen
func (m *model) handleDialplanScreen(msg tea.KeyMsg) (*model, tea.Cmd) {
	switch msg.String() {
	case "up", "k":
		if m.cursor > 0 {
			m.cursor--
		}
	case "down", "j":
		if m.cursor < len(m.dialplanMenu)-1 {
			m.cursor++
		}
	case "q", "esc":
		// Return to main menu
		m.currentScreen = mainMenu
		m.cursor = m.mainMenuCursor
		m.errorMsg = ""
		m.successMsg = ""
	case "enter", " ":
		m.executeDialplanMenuAction()
	}
	return m, nil
}

// executeDialplanMenuAction executes the selected dialplan menu action
func (m *model) executeDialplanMenuAction() {
	switch m.cursor {
	case 0: // View Current Dialplan
		m.viewCurrentDialplan()
	case 1: // Generate from Extensions
		m.generateDialplanFromExtensions()
	case 2: // Create Default Pattern
		m.createDefaultDialplanPattern()
	case 3: // Apply to Asterisk
		m.applyDialplanToAsterisk()
	case 4: // Reload Dialplan
		m.reloadDialplan()
	case 5: // Pattern Help
		m.showDialplanPatternHelp()
	case 6: // Back to Main Menu
		m.currentScreen = mainMenu
		m.cursor = m.mainMenuCursor
	}
}

// viewCurrentDialplan shows the current dialplan from Asterisk
func (m *model) viewCurrentDialplan() {
	output, err := m.asteriskManager.ShowDialplan()
	if err != nil {
		errStr := err.Error()
		if strings.Contains(errStr, "not running") || strings.Contains(errStr, "Connection refused") {
			m.errorMsg = "Asterisk is not running. Start Asterisk first via Asterisk Management."
		} else if strings.Contains(errStr, "permission denied") {
			m.errorMsg = "Permission denied. Try running with sudo."
		} else if strings.Contains(errStr, "command not found") {
			m.errorMsg = "Asterisk command not found. Is Asterisk installed?"
		} else {
			m.errorMsg = fmt.Sprintf("Error getting dialplan: %v", err)
		}
		m.dialplanOutput = ""
		return
	}
	m.dialplanOutput = output
	m.successMsg = "Dialplan loaded from Asterisk"
}

// generateDialplanFromExtensions generates dialplan from configured extensions
func (m *model) generateDialplanFromExtensions() {
	// Load extensions from database
	extensions, err := GetExtensions(m.db)
	if err != nil {
		m.errorMsg = fmt.Sprintf("Error loading extensions: %v", err)
		return
	}

	// Generate the dialplan
	dialplan := m.configManager.GenerateInternalDialplan(extensions)
	m.dialplanPreview = dialplan
	m.dialplanOutput = fmt.Sprintf("Generated dialplan for %d extensions:\n\n%s", len(extensions), dialplan)
	m.successMsg = "Dialplan generated successfully"
}

// createDefaultDialplanPattern creates the default _1XX pattern
func (m *model) createDefaultDialplanPattern() {
	defaultPattern := `
[from-internal]
; Generalized dialplan - Pattern match for extension ranges
; _1XX matches 100-199 (3-digit extensions starting with 1)
exten => _1XX,1,NoOp(Extension to extension call: ${EXTEN})
 same => n,Dial(PJSIP/${EXTEN},30)
 same => n,Hangup()

; _1XXX matches 1000-1999 (4-digit extensions starting with 1)
exten => _1XXX,1,NoOp(Extension to extension call: ${EXTEN})
 same => n,Dial(PJSIP/${EXTEN},30)
 same => n,Hangup()
`
	m.dialplanPreview = defaultPattern
	m.dialplanOutput = fmt.Sprintf("Default dialplan pattern:\n\n%s\n\nSelect 'Apply to Asterisk' to save this configuration.", defaultPattern)
	m.successMsg = "Default pattern created - ready to apply"
}

// applyDialplanToAsterisk writes the dialplan to extensions.conf and reloads
func (m *model) applyDialplanToAsterisk() {
	if m.dialplanPreview == "" {
		m.errorMsg = "No dialplan to apply. Generate or create a pattern first."
		return
	}

	// Write the dialplan configuration
	err := m.configManager.WriteDialplanConfig(m.dialplanPreview, "RayanPBX-TUI")
	if err != nil {
		m.errorMsg = fmt.Sprintf("Failed to write dialplan configuration: %v", err)
		return
	}

	// Reload dialplan in Asterisk
	err = m.asteriskManager.ReloadDialplan()
	if err != nil {
		m.errorMsg = fmt.Sprintf("Dialplan written but reload failed: %v", err)
		return
	}

	m.successMsg = "Dialplan applied and reloaded successfully"
	m.dialplanOutput = "Dialplan has been written to extensions.conf and reloaded in Asterisk."
}

// reloadDialplan reloads the dialplan in Asterisk
func (m *model) reloadDialplan() {
	err := m.asteriskManager.ReloadDialplan()
	if err != nil {
		m.errorMsg = fmt.Sprintf("Failed to reload dialplan: %v", err)
		return
	}
	m.successMsg = "Dialplan reloaded successfully"
}

// showDialplanPatternHelp displays help about dialplan patterns
func (m *model) showDialplanPatternHelp() {
	help := `
╔══════════════════════════════════════════════════════════════════════════════╗
║                        Dialplan Pattern Reference                             ║
╠══════════════════════════════════════════════════════════════════════════════╣
║                                                                               ║
║  Pattern Characters:                                                          ║
║  ───────────────────                                                          ║
║  X  - Matches any digit 0-9                                                   ║
║  Z  - Matches any digit 1-9                                                   ║
║  N  - Matches any digit 2-9                                                   ║
║  [1-5] - Matches any digit in the range 1-5                                   ║
║  .  - Wildcard: matches one or more characters                                ║
║  !  - Wildcard: matches zero or more characters                               ║
║  _  - Prefix indicating a pattern (required)                                  ║
║                                                                               ║
║  Common Patterns:                                                             ║
║  ────────────────                                                             ║
║  100       - Matches exactly 100                                              ║
║  _1XX      - Matches 100-199 (3 digits starting with 1)                       ║
║  _1XXX     - Matches 1000-1999 (4 digits starting with 1)                     ║
║  _NXX      - Matches 200-999                                                  ║
║  _9X.      - Matches 9 followed by any number of digits (outbound)            ║
║  _0X.      - Matches 0 followed by any number of digits                       ║
║  s         - Start extension (for incoming calls without DID)                 ║
║                                                                               ║
║  Variables:                                                                   ║
║  ──────────                                                                   ║
║  ${EXTEN}          - The dialed extension number                              ║
║  ${EXTEN:1}        - Dialed number with first digit stripped                  ║
║  ${CALLERID(num)}  - Caller ID number                                         ║
║  ${CALLERID(name)} - Caller ID name                                           ║
║                                                                               ║
║  Example Dialplan:                                                            ║
║  ─────────────────                                                            ║
║  [from-internal]                                                              ║
║  ; Internal extension calls (100-199)                                         ║
║  exten => _1XX,1,NoOp(Extension call: ${EXTEN})                               ║
║   same => n,Dial(PJSIP/${EXTEN},30)                                           ║
║   same => n,Hangup()                                                          ║
║                                                                               ║
║  ; Outbound calls via trunk (dial 9 + number)                                 ║
║  exten => _9X.,1,NoOp(Outbound call: ${EXTEN})                                ║
║   same => n,Dial(PJSIP/${EXTEN:1}@mytrunk,60)                                 ║
║   same => n,Hangup()                                                          ║
║                                                                               ║
╚══════════════════════════════════════════════════════════════════════════════╝
`
	m.dialplanOutput = help
}

// renderDialplanScreen renders the dialplan management screen
func (m model) renderDialplanScreen() string {
	var s strings.Builder

	s.WriteString("\n")
	s.WriteString("╔══════════════════════════════════════════════════════════════════════════════╗\n")
	s.WriteString("║                         📜 Dialplan Management                               ║\n")
	s.WriteString("╠══════════════════════════════════════════════════════════════════════════════╣\n")
	s.WriteString("║                                                                              ║\n")
	s.WriteString("║  Configure how calls are routed in your PBX system.                          ║\n")
	s.WriteString("║  Create rules for internal calls, outbound routing, and inbound handling.   ║\n")
	s.WriteString("║                                                                              ║\n")
	s.WriteString("╚══════════════════════════════════════════════════════════════════════════════╝\n")
	s.WriteString("\n")

	// Render menu items
	for i, item := range m.dialplanMenu {
		cursor := "  "
		if m.cursor == i {
			cursor = "👉"
		}
		s.WriteString(fmt.Sprintf("%s %s\n", cursor, item))
	}

	// Show error or success message
	if m.errorMsg != "" {
		s.WriteString(fmt.Sprintf("\n❌ Error: %s\n", m.errorMsg))
	}
	if m.successMsg != "" {
		s.WriteString(fmt.Sprintf("\n✅ %s\n", m.successMsg))
	}

	// Show dialplan output if available
	if m.dialplanOutput != "" {
		s.WriteString("\n")
		s.WriteString("─────────────────────────────────────────────────────────────────────────────────\n")
		s.WriteString(m.dialplanOutput)
		s.WriteString("\n")
	}

	s.WriteString("\n───────────────────────────────────────────────────────────────────────────────\n")
	s.WriteString("Navigation: ↑/↓ or j/k to move, Enter to select, q/Esc to go back\n")

	return s.String()
}
