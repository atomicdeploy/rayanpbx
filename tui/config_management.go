package main

import (
	"bufio"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"strings"
	"time"

	tea "github.com/charmbracelet/bubbletea"
	"github.com/charmbracelet/lipgloss"
)

// EnvConfig represents a single environment variable or section header
type EnvConfig struct {
	Key         string
	Value       string
	Description string
	Sensitive   bool
	IsSection   bool   // True if this is a section header (comment line)
	SectionName string // Name of the section (for display)
}

// ConfigManager handles environment file operations
type ConfigManager struct {
	envPath     string
	configs     []EnvConfig
	verbose     bool
}

// NewConfigManager creates a new configuration manager
func NewConfigManager(verbose bool) *ConfigManager {
	envPath := findEnvPath()
	return &ConfigManager{
		envPath: envPath,
		verbose: verbose,
	}
}

// findEnvPath finds the .env file in the project
func findEnvPath() string {
	paths := []string{
		"/opt/rayanpbx/.env",
		"/usr/local/rayanpbx/.env",
		"/etc/rayanpbx/.env",
	}
	
	// Add project root .env
	rootPath := findRootPath()
	rootEnvPath := filepath.Join(rootPath, ".env")
	paths = append(paths, rootEnvPath)
	
	// Add current directory .env
	currentDir, _ := os.Getwd()
	localEnvPath := filepath.Join(currentDir, ".env")
	paths = append(paths, localEnvPath)
	
	// Return first existing path
	for _, path := range paths {
		if _, err := os.Stat(path); err == nil {
			return path
		}
	}
	
	// Default to project root
	return rootEnvPath
}

// LoadConfigs loads all configurations from .env file
func (cm *ConfigManager) LoadConfigs() error {
	file, err := os.Open(cm.envPath)
	if err != nil {
		return fmt.Errorf("failed to open .env file: %w", err)
	}
	defer file.Close()

	cm.configs = []EnvConfig{}
	scanner := bufio.NewScanner(file)
	lastComment := ""
	pendingSectionComment := ""

	for scanner.Scan() {
		line := strings.TrimSpace(scanner.Text())
		
		// Skip empty lines - they reset comments but also mark section boundaries
		if line == "" {
			// If we had a pending section comment, it was a standalone section header
			if pendingSectionComment != "" {
				cm.configs = append(cm.configs, EnvConfig{
					IsSection:   true,
					SectionName: pendingSectionComment,
				})
				pendingSectionComment = ""
			}
			lastComment = ""
			continue
		}
		
		// Handle comments
		if strings.HasPrefix(line, "#") {
			commentText := strings.TrimPrefix(line, "#")
			commentText = strings.TrimSpace(commentText)
			
			// Check if this is a section header (single-line comment that looks like a title)
			// Section headers are typically short, capitalized words or phrases
			if isSectionHeader(commentText) {
				// If we already had a pending section, save it
				if pendingSectionComment != "" {
					cm.configs = append(cm.configs, EnvConfig{
						IsSection:   true,
						SectionName: pendingSectionComment,
					})
				}
				pendingSectionComment = commentText
			} else {
				// Regular comment - use as description for next variable
				if lastComment != "" {
					lastComment += " " + commentText
				} else {
					lastComment = commentText
				}
			}
			continue
		}
		
		// Parse key=value
		if strings.Contains(line, "=") {
			parts := strings.SplitN(line, "=", 2)
			if len(parts) == 2 {
				key := strings.TrimSpace(parts[0])
				value := strings.TrimSpace(parts[1])
				
				// Remove quotes
				value = strings.Trim(value, `"'`)
				
				// Check if sensitive
				sensitive := cm.isSensitive(key)
				
				// If there's a pending section comment, add it first
				if pendingSectionComment != "" {
					cm.configs = append(cm.configs, EnvConfig{
						IsSection:   true,
						SectionName: pendingSectionComment,
					})
					pendingSectionComment = ""
				}
				
				config := EnvConfig{
					Key:         key,
					Value:       value,
					Description: lastComment,
					Sensitive:   sensitive,
					IsSection:   false,
				}
				
				cm.configs = append(cm.configs, config)
				lastComment = ""
			}
		}
	}
	
	// Add any remaining pending section
	if pendingSectionComment != "" {
		cm.configs = append(cm.configs, EnvConfig{
			IsSection:   true,
			SectionName: pendingSectionComment,
		})
	}

	if err := scanner.Err(); err != nil {
		return fmt.Errorf("error reading .env file: %w", err)
	}

	return nil
}

// isSectionHeader checks if a comment looks like a section header
func isSectionHeader(comment string) bool {
	// Section headers are typically:
	// - Short (less than 50 chars)
	// - Don't contain common description words
	// - Often contain words like "Configuration", "Settings", etc.
	if len(comment) > 50 {
		return false
	}
	
	// Empty comments are not section headers
	if comment == "" {
		return false
	}
	
	// Check for common section header patterns
	sectionPatterns := []string{
		"Configuration",
		"Config",
		"Settings",
		"Options",
		"Logging",
		"Security",
		"Database",
		"Redis",
		"Cache",
		"JWT",
		"Session",
		"Asterisk",
		"SIP",
		"Mail",
		"CORS",
		"API",
		"Frontend",
		"WebSocket",
		"RayanPBX",
		"Development",
		"CLI/TUI",
		"Nuxt",
		"Pollination",
	}
	
	for _, pattern := range sectionPatterns {
		if strings.Contains(comment, pattern) {
			return true
		}
	}
	
	// Check if it's a short capitalized phrase without special characters
	// that looks like a header
	if len(comment) < 30 && !strings.Contains(comment, ":") && 
		!strings.Contains(comment, "=") && !strings.HasPrefix(comment, "Note") &&
		!strings.HasPrefix(comment, "Example") && !strings.HasPrefix(comment, "Comma") &&
		!strings.HasPrefix(comment, "Useful") {
		// Count uppercase letters vs total
		upperCount := 0
		for _, c := range comment {
			if c >= 'A' && c <= 'Z' {
				upperCount++
			}
		}
		// If first character is uppercase and it's a relatively short phrase
		if len(comment) > 0 && comment[0] >= 'A' && comment[0] <= 'Z' && len(strings.Fields(comment)) <= 4 {
			return true
		}
	}
	
	return false
}

// GetConfigs returns all loaded configurations
func (cm *ConfigManager) GetConfigs() []EnvConfig {
	return cm.configs
}

// GetConfig returns a specific configuration by key
func (cm *ConfigManager) GetConfig(key string) *EnvConfig {
	for i := range cm.configs {
		if cm.configs[i].Key == key {
			return &cm.configs[i]
		}
	}
	return nil
}

// AddConfig adds a new configuration
func (cm *ConfigManager) AddConfig(key, value string) error {
	// Validate key format
	if !cm.isValidKey(key) {
		return fmt.Errorf("invalid key format: must be uppercase with underscores")
	}
	
	// Check if key exists
	if cm.GetConfig(key) != nil {
		return fmt.Errorf("key already exists: %s", key)
	}
	
	// Backup file
	if err := cm.backupEnvFile(); err != nil {
		return fmt.Errorf("failed to backup .env file: %w", err)
	}
	
	// Append to file
	file, err := os.OpenFile(cm.envPath, os.O_APPEND|os.O_WRONLY, 0644)
	if err != nil {
		return fmt.Errorf("failed to open .env file: %w", err)
	}
	defer file.Close()
	
	_, err = fmt.Fprintf(file, "\n%s=%s\n", key, value)
	if err != nil {
		return fmt.Errorf("failed to write to .env file: %w", err)
	}
	
	// Reload configs
	return cm.LoadConfigs()
}

// UpdateConfig updates an existing configuration
func (cm *ConfigManager) UpdateConfig(key, value string) error {
	// Check if key exists
	if cm.GetConfig(key) == nil {
		return fmt.Errorf("key not found: %s", key)
	}
	
	// Backup file
	if err := cm.backupEnvFile(); err != nil {
		return fmt.Errorf("failed to backup .env file: %w", err)
	}
	
	// Read file
	content, err := os.ReadFile(cm.envPath)
	if err != nil {
		return fmt.Errorf("failed to read .env file: %w", err)
	}
	
	// Replace the line
	lines := strings.Split(string(content), "\n")
	for i, line := range lines {
		trimmed := strings.TrimSpace(line)
		if strings.HasPrefix(trimmed, key+"=") {
			lines[i] = fmt.Sprintf("%s=%s", key, value)
			break
		}
	}
	
	// Write back
	newContent := strings.Join(lines, "\n")
	if err := os.WriteFile(cm.envPath, []byte(newContent), 0644); err != nil {
		return fmt.Errorf("failed to write .env file: %w", err)
	}
	
	// Reload configs
	return cm.LoadConfigs()
}

// RemoveConfig removes a configuration
func (cm *ConfigManager) RemoveConfig(key string) error {
	// Check if key exists
	if cm.GetConfig(key) == nil {
		return fmt.Errorf("key not found: %s", key)
	}
	
	// Backup file
	if err := cm.backupEnvFile(); err != nil {
		return fmt.Errorf("failed to backup .env file: %w", err)
	}
	
	// Read file
	content, err := os.ReadFile(cm.envPath)
	if err != nil {
		return fmt.Errorf("failed to read .env file: %w", err)
	}
	
	// Remove the line
	lines := strings.Split(string(content), "\n")
	newLines := []string{}
	for _, line := range lines {
		trimmed := strings.TrimSpace(line)
		if !strings.HasPrefix(trimmed, key+"=") {
			newLines = append(newLines, line)
		}
	}
	
	// Write back
	newContent := strings.Join(newLines, "\n")
	if err := os.WriteFile(cm.envPath, []byte(newContent), 0644); err != nil {
		return fmt.Errorf("failed to write .env file: %w", err)
	}
	
	// Reload configs
	return cm.LoadConfigs()
}

// backupEnvFile creates a backup of the .env file
func (cm *ConfigManager) backupEnvFile() error {
	backupPath := fmt.Sprintf("%s.backup.%s", cm.envPath, time.Now().Format("20060102150405"))
	content, err := os.ReadFile(cm.envPath)
	if err != nil {
		return err
	}
	return os.WriteFile(backupPath, content, 0644)
}

// isValidKey checks if a key is valid (uppercase with underscores)
func (cm *ConfigManager) isValidKey(key string) bool {
	matched, _ := regexp.MatchString("^[A-Z_][A-Z0-9_]*$", key)
	return matched
}

// isSensitive checks if a key contains sensitive information
func (cm *ConfigManager) isSensitive(key string) bool {
	sensitivePatterns := []string{
		"password", "secret", "key", "token", "api_key",
		"private_key", "jwt_secret", "db_password", "ami_secret",
	}
	
	keyLower := strings.ToLower(key)
	for _, pattern := range sensitivePatterns {
		if strings.Contains(keyLower, pattern) {
			return true
		}
	}
	return false
}

// reloadAllServices reloads all configured services
func reloadAllServices() string {
	messages := []string{}
	
	// Try to reload Asterisk
	if err := exec.Command("asterisk", "-rx", "core reload").Run(); err == nil {
		messages = append(messages, "✅ Asterisk reloaded")
	} else {
		messages = append(messages, "⚠️  Asterisk reload failed or not found")
	}
	
	// Try to clear Laravel cache
	rootPath := findRootPath()
	backendPath := filepath.Join(rootPath, "backend")
	if _, err := os.Stat(backendPath); err == nil {
		if err := exec.Command("php", filepath.Join(backendPath, "artisan"), "config:clear").Run(); err == nil {
			messages = append(messages, "✅ Laravel config cleared")
		}
		if err := exec.Command("php", filepath.Join(backendPath, "artisan"), "cache:clear").Run(); err == nil {
			messages = append(messages, "✅ Laravel cache cleared")
		}
	}
	
	if len(messages) == 0 {
		return "⚠️  No services could be reloaded"
	}
	
	return strings.Join(messages, " | ")
}

// Default visible rows for configuration table
const defaultConfigVisibleRows = 12

// initConfigManagement initializes the configuration management screen
func initConfigManagement(m *model) {
	configManager := NewConfigManager(m.verbose)
	if err := configManager.LoadConfigs(); err == nil {
		configs := configManager.GetConfigs()
		// Don't sort - preserve original order with sections
		m.configItems = configs
	} else {
		m.configItems = []EnvConfig{}
		m.errorMsg = fmt.Sprintf("Error loading configs: %v", err)
	}
	m.configCursor = 0
	m.configScrollOffset = 0
	m.configSearchQuery = ""
	// Set visible rows based on terminal height, accounting for header/footer/menu
	// Leave room for: title (2), stats (2), table header (2), menu options (5), help (2), messages (2)
	if m.height > 25 {
		m.configVisibleRows = m.height - 20
	} else {
		m.configVisibleRows = defaultConfigVisibleRows
	}
}

// getFilteredConfigs returns configs filtered by search query
func getFilteredConfigs(configs []EnvConfig, query string) []EnvConfig {
	if query == "" {
		return configs
	}
	
	queryLower := strings.ToLower(query)
	var filtered []EnvConfig
	for _, config := range configs {
		// Include section headers if they match or if any of their children match
		if config.IsSection {
			if strings.Contains(strings.ToLower(config.SectionName), queryLower) {
				filtered = append(filtered, config)
			}
		} else {
			if strings.Contains(strings.ToLower(config.Key), queryLower) ||
				strings.Contains(strings.ToLower(config.Value), queryLower) {
				filtered = append(filtered, config)
			}
		}
	}
	return filtered
}

// Update function to handle config management screen
func updateConfigManagement(msg tea.Msg, m model) (tea.Model, tea.Cmd) {
	switch msg := msg.(type) {
	case tea.KeyMsg:
		// Load configs if not already loaded
		if len(m.configItems) == 0 {
			initConfigManagement(&m)
		}
		
		// Get filtered configs - menu options are separate and always visible
		filteredConfigs := getFilteredConfigs(m.configItems, m.configSearchQuery)
		configCount := len(filteredConfigs)
		totalItems := configCount + 3 // configs + 3 menu options (add, reload, back)
		
		switch msg.String() {
		case "q", "esc":
			m.currentScreen = mainMenu
			m.cursor = m.mainMenuCursor
			m.configItems = nil // Clear cached items
			m.configSearchQuery = ""
			return m, nil
			
		case "up", "k":
			if m.configCursor > 0 {
				m.configCursor--
				// Adjust scroll if cursor goes above visible area (only for config items)
				if m.configCursor < configCount && m.configCursor < m.configScrollOffset {
					m.configScrollOffset = m.configCursor
				}
			}
			
		case "down", "j":
			if m.configCursor < totalItems-1 {
				m.configCursor++
				// Adjust scroll if cursor goes below visible area (only for config items)
				if m.configCursor < configCount && m.configCursor >= m.configScrollOffset+m.configVisibleRows {
					m.configScrollOffset = m.configCursor - m.configVisibleRows + 1
				}
			}
			
		case "pgup", "ctrl+b":
			// Page up - move cursor up by visible rows
			m.configCursor -= m.configVisibleRows
			if m.configCursor < 0 {
				m.configCursor = 0
			}
			// Adjust scroll
			m.configScrollOffset -= m.configVisibleRows
			if m.configScrollOffset < 0 {
				m.configScrollOffset = 0
			}
			
		case "pgdown", "ctrl+f":
			// Page down - move cursor down by visible rows
			m.configCursor += m.configVisibleRows
			if m.configCursor >= totalItems {
				m.configCursor = totalItems - 1
			}
			// Adjust scroll - cap at max config items
			maxOffset := configCount - m.configVisibleRows
			if maxOffset < 0 {
				maxOffset = 0
			}
			m.configScrollOffset += m.configVisibleRows
			if m.configScrollOffset > maxOffset {
				m.configScrollOffset = maxOffset
			}
			
		case "home", "g":
			// Go to top
			m.configCursor = 0
			m.configScrollOffset = 0
			
		case "end", "G":
			// Go to bottom (to last menu item)
			m.configCursor = totalItems - 1
			// Keep scroll at max config position
			maxOffset := configCount - m.configVisibleRows
			if maxOffset < 0 {
				maxOffset = 0
			}
			m.configScrollOffset = maxOffset
		
		case "/":
			// Toggle search mode - for now just show help
			m.successMsg = "Search: Type to filter, press '/' again to clear"
			if m.configSearchQuery != "" {
				m.configSearchQuery = ""
				m.configCursor = 0
				m.configScrollOffset = 0
			}
			
		case "r":
			// Refresh config list
			initConfigManagement(&m)
			m.successMsg = "Configuration reloaded"
			
		case "enter":
			if m.configCursor < configCount {
				// Check if it's a section header (not editable)
				if filteredConfigs[m.configCursor].IsSection {
					// Skip - sections are not editable
					return m, nil
				}
				// Edit config
				m.currentScreen = configEditScreen
				m.inputMode = true
				m.inputFields = []string{"Key", "Value"}
				m.inputValues = []string{filteredConfigs[m.configCursor].Key, filteredConfigs[m.configCursor].Value}
				m.inputCursor = 0
			} else if m.configCursor == configCount {
				// Add new config
				m.currentScreen = configAddScreen
				m.inputMode = true
				m.inputFields = []string{"Key", "Value"}
				m.inputValues = []string{"", ""}
				m.inputCursor = 0
			} else if m.configCursor == configCount+1 {
				// Reload services
				m.successMsg = reloadAllServices()
			} else {
				// Back
				m.currentScreen = mainMenu
				m.cursor = m.mainMenuCursor
				m.configItems = nil
				m.configSearchQuery = ""
			}
		}
	
	case tea.WindowSizeMsg:
		m.width = msg.Width
		m.height = msg.Height
		// Recalculate visible rows based on new terminal size
		if m.height > 25 {
			m.configVisibleRows = m.height - 20
		} else {
			m.configVisibleRows = defaultConfigVisibleRows
		}
	}
	
	return m, nil
}

// View function for config management screen
func viewConfigManagement(m model) string {
	var s strings.Builder
	
	s.WriteString(titleStyle.Render("🔧 Configuration Management"))
	s.WriteString("\n\n")
	
	// Initialize configs if not loaded
	if len(m.configItems) == 0 {
		configManager := NewConfigManager(m.verbose)
		if err := configManager.LoadConfigs(); err != nil {
			s.WriteString(errorStyle.Render(fmt.Sprintf("Error loading configs: %v", err)))
			s.WriteString("\n\n")
			s.WriteString(helpStyle.Render("Press 'q' or 'esc' to go back"))
			return menuStyle.Render(s.String())
		}
	}
	
	// Get filtered configs
	filteredConfigs := getFilteredConfigs(m.configItems, m.configSearchQuery)
	configCount := len(filteredConfigs)
	totalItems := configCount + 3 // configs + 3 menu options
	
	// Show search query if active
	if m.configSearchQuery != "" {
		s.WriteString(infoStyle.Render(fmt.Sprintf("🔍 Filter: %s", m.configSearchQuery)))
		s.WriteString("\n")
	}
	
	// Statistics
	if m.configSearchQuery != "" {
		s.WriteString(infoStyle.Render(fmt.Sprintf("Showing %d of %d configurations", configCount, len(m.configItems))))
	} else {
		s.WriteString(infoStyle.Render(fmt.Sprintf("Total: %d configurations", configCount)))
	}
	s.WriteString("\n")
	
	// Show scroll position indicator
	if configCount > m.configVisibleRows {
		percentage := 0
		if configCount > 1 {
			percentage = (m.configCursor * 100) / (configCount - 1)
			if percentage > 100 {
				percentage = 100
			}
		}
		s.WriteString(helpStyle.Render(fmt.Sprintf("[%d/%d] %d%%", m.configCursor+1, totalItems, percentage)))
	}
	s.WriteString("\n\n")
	
	// Calculate max key width for alignment (only for non-section items)
	maxKeyWidth := 30
	for _, config := range filteredConfigs {
		if !config.IsSection && len(config.Key) > maxKeyWidth {
			maxKeyWidth = len(config.Key)
		}
	}
	if maxKeyWidth > 40 {
		maxKeyWidth = 40 // Cap at 40 chars
	}
	
	// Table header styling
	headerStyle := lipgloss.NewStyle().
		Foreground(lipgloss.Color("#7D56F4")).
		Bold(true)
	
	// Section header styling
	sectionStyle := lipgloss.NewStyle().
		Foreground(lipgloss.Color("#FFA500")).
		Bold(true)
	
	// Table header
	s.WriteString(headerStyle.Render(fmt.Sprintf("  %-*s │ %s", maxKeyWidth, "KEY", "VALUE")))
	s.WriteString("\n")
	s.WriteString(headerStyle.Render(fmt.Sprintf("─%s─┼%s", strings.Repeat("─", maxKeyWidth), strings.Repeat("─", 40))))
	s.WriteString("\n")
	
	// Calculate visible range - only for config items, menu is always shown separately
	visibleRows := m.configVisibleRows
	if visibleRows <= 0 {
		visibleRows = defaultConfigVisibleRows
	}
	
	startIdx := m.configScrollOffset
	endIdx := startIdx + visibleRows
	if endIdx > configCount {
		endIdx = configCount
	}
	
	// Show scroll indicator at top (with proper alignment)
	if startIdx > 0 {
		s.WriteString(helpStyle.Render(fmt.Sprintf("  %-*s │ ▲ more above...", maxKeyWidth, "")))
		s.WriteString("\n")
	}
	
	// Display exactly visibleRows lines for configs
	displayedRows := 0
	for i := startIdx; i < configCount && displayedRows < visibleRows; i++ {
		config := filteredConfigs[i]
		
		if config.IsSection {
			// Section header - display as a separator row
			sectionLine := fmt.Sprintf("─%s─┼%s", strings.Repeat("─", maxKeyWidth), strings.Repeat("─", 40))
			s.WriteString(headerStyle.Render(sectionLine))
			s.WriteString("\n")
			
			// Section name row
			cursor := " "
			if m.configCursor == i {
				cursor = "▶"
			}
			sectionName := config.SectionName
			if len(sectionName) > maxKeyWidth-2 {
				sectionName = sectionName[:maxKeyWidth-5] + "..."
			}
			
			sectionRow := fmt.Sprintf("%s %-*s │ %s", cursor, maxKeyWidth, "# "+sectionName, "(section)")
			if m.configCursor == i {
				s.WriteString(selectedItemStyle.Render(sectionRow))
			} else {
				s.WriteString(sectionStyle.Render(sectionRow))
			}
			s.WriteString("\n")
		} else {
			// Regular config item
			cursor := " "
			if m.configCursor == i {
				cursor = "▶"
			}
			
			// Truncate key if too long
			displayKey := config.Key
			if len(displayKey) > maxKeyWidth {
				displayKey = displayKey[:maxKeyWidth-3] + "..."
			}
			
			// Handle value display
			displayValue := config.Value
			if config.Sensitive {
				displayValue = "********"
			}
			// Truncate value if too long
			maxValueWidth := 35
			if len(displayValue) > maxValueWidth {
				displayValue = displayValue[:maxValueWidth-3] + "..."
			}
			
			line := fmt.Sprintf("%s %-*s │ %s", cursor, maxKeyWidth, displayKey, displayValue)
			
			if m.configCursor == i {
				s.WriteString(selectedItemStyle.Render(line))
			} else {
				s.WriteString(line)
			}
			s.WriteString("\n")
			
			// Show description for selected item
			if config.Description != "" && m.configCursor == i {
				s.WriteString(helpStyle.Render(fmt.Sprintf("  └─ %s", config.Description)))
				s.WriteString("\n")
			}
		}
		displayedRows++
	}
	
	// Show scroll indicator at bottom (with proper alignment)
	if endIdx < configCount {
		s.WriteString(helpStyle.Render(fmt.Sprintf("  %-*s │ ▼ more below...", maxKeyWidth, "")))
		s.WriteString("\n")
	}
	
	// Separator before menu
	s.WriteString(headerStyle.Render(fmt.Sprintf("─%s─┴%s", strings.Repeat("─", maxKeyWidth), strings.Repeat("─", 40))))
	s.WriteString("\n\n")
	
	// Menu options - always visible at the bottom
	menuOptions := []string{
		"➕ Add New Configuration",
		"🔄 Reload Services",
		"🔙 Back to Main Menu",
	}
	
	for i, option := range menuOptions {
		cursor := " "
		itemIdx := configCount + i
		if m.configCursor == itemIdx {
			cursor = "▶"
			s.WriteString(selectedItemStyle.Render(cursor + " " + option))
		} else {
			s.WriteString(cursor + " " + option)
		}
		s.WriteString("\n")
	}
	
	s.WriteString("\n")
	s.WriteString(helpStyle.Render("↑/↓/j/k: Navigate │ PgUp/PgDn: Page │ g/G: Top/Bottom │ Enter: Edit │ r: Refresh │ q/esc: Back"))
	
	if m.successMsg != "" {
		s.WriteString("\n\n")
		s.WriteString(successStyle.Render(m.successMsg))
	}
	
	if m.errorMsg != "" {
		s.WriteString("\n\n")
		s.WriteString(errorStyle.Render(m.errorMsg))
	}
	
	return menuStyle.Render(s.String())
}

// Update function for config add screen
func updateConfigAdd(msg tea.Msg, m model) (tea.Model, tea.Cmd) {
	switch msg := msg.(type) {
	case tea.KeyMsg:
		if m.inputMode {
			return handleConfigInput(msg, m, true)
		}
	}
	return m, nil
}

// Update function for config edit screen
func updateConfigEdit(msg tea.Msg, m model) (tea.Model, tea.Cmd) {
	switch msg := msg.(type) {
	case tea.KeyMsg:
		if m.inputMode {
			return handleConfigInput(msg, m, false)
		}
	}
	return m, nil
}

// Handle input for config add/edit
func handleConfigInput(msg tea.KeyMsg, m model, isAdd bool) (tea.Model, tea.Cmd) {
	switch msg.String() {
	case "esc":
		m.currentScreen = configManagementScreen
		m.inputMode = false
		m.errorMsg = ""
		m.successMsg = ""
		return m, nil
		
	case "up":
		if m.inputCursor > 0 {
			m.inputCursor--
		}
		
	case "down", "tab":
		if m.inputCursor < len(m.inputFields)-1 {
			m.inputCursor++
		}
		
	case "enter":
		if m.inputCursor == len(m.inputFields)-1 {
			// Save
			configManager := NewConfigManager(m.verbose)
			if err := configManager.LoadConfigs(); err != nil {
				m.errorMsg = fmt.Sprintf("Error loading configs: %v", err)
				return m, nil
			}
			
			key := m.inputValues[0]
			value := m.inputValues[1]
			
			var err error
			if isAdd {
				err = configManager.AddConfig(key, value)
			} else {
				err = configManager.UpdateConfig(key, value)
			}
			
			if err != nil {
				m.errorMsg = fmt.Sprintf("Error: %v", err)
			} else {
				m.successMsg = "Configuration saved successfully"
				m.currentScreen = configManagementScreen
				m.inputMode = false
			}
		} else {
			m.inputCursor++
		}
		
	case "backspace":
		if len(m.inputValues[m.inputCursor]) > 0 {
			m.inputValues[m.inputCursor] = m.inputValues[m.inputCursor][:len(m.inputValues[m.inputCursor])-1]
		}
		
	default:
		if len(msg.String()) == 1 {
			m.inputValues[m.inputCursor] += msg.String()
		}
	}
	
	return m, nil
}

// View function for config add/edit screen
func viewConfigInput(m model, isAdd bool) string {
	var s strings.Builder
	
	title := "🔧 Edit Configuration"
	if isAdd {
		title = "➕ Add Configuration"
	}
	
	s.WriteString(titleStyle.Render(title))
	s.WriteString("\n\n")
	
	for i, field := range m.inputFields {
		cursor := " "
		if m.inputCursor == i {
			cursor = ">"
		}
		
		value := m.inputValues[i]
		if field == "Value" && i < m.inputCursor {
			// Show masked value for previous field if it looks sensitive
			// Use a temporary ConfigManager to check sensitivity
			cm := &ConfigManager{}
			if cm.isSensitive(m.inputValues[0]) {
				value = strings.Repeat("*", len(value))
			}
		}
		
		line := fmt.Sprintf("%s %s: %s", cursor, field, value)
		if m.inputCursor == i {
			s.WriteString(selectedItemStyle.Render(line + "█"))
		} else {
			s.WriteString(line)
		}
		s.WriteString("\n")
	}
	
	s.WriteString("\n")
	s.WriteString(helpStyle.Render("↑/↓: Navigate fields | Enter: Next/Save | Esc: Cancel"))
	
	if m.errorMsg != "" {
		s.WriteString("\n\n")
		s.WriteString(errorStyle.Render(m.errorMsg))
	}
	
	return menuStyle.Render(s.String())
}
