package main

import (
	"bufio"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"sort"
	"strings"
	"time"

	tea "github.com/charmbracelet/bubbletea"
	"github.com/charmbracelet/lipgloss"
)

// EnvConfig represents a single environment variable
type EnvConfig struct {
	Key         string
	Value       string
	Description string
	Sensitive   bool
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

	for scanner.Scan() {
		line := strings.TrimSpace(scanner.Text())
		
		// Skip empty lines
		if line == "" {
			lastComment = ""
			continue
		}
		
		// Collect comments
		if strings.HasPrefix(line, "#") {
			lastComment = strings.TrimPrefix(line, "#")
			lastComment = strings.TrimSpace(lastComment)
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
				
				config := EnvConfig{
					Key:         key,
					Value:       value,
					Description: lastComment,
					Sensitive:   sensitive,
				}
				
				cm.configs = append(cm.configs, config)
				lastComment = ""
			}
		}
	}

	if err := scanner.Err(); err != nil {
		return fmt.Errorf("error reading .env file: %w", err)
	}

	return nil
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
const defaultConfigVisibleRows = 15

// initConfigManagement initializes the configuration management screen
func initConfigManagement(m *model) {
	configManager := NewConfigManager(m.verbose)
	if err := configManager.LoadConfigs(); err == nil {
		configs := configManager.GetConfigs()
		// Sort configs alphabetically
		sort.Slice(configs, func(i, j int) bool {
			return configs[i].Key < configs[j].Key
		})
		m.configItems = configs
	} else {
		m.configItems = []EnvConfig{}
		m.errorMsg = fmt.Sprintf("Error loading configs: %v", err)
	}
	m.configCursor = 0
	m.configScrollOffset = 0
	m.configSearchQuery = ""
	// Set visible rows based on terminal height, accounting for header/footer
	if m.height > 20 {
		m.configVisibleRows = m.height - 18 // Leave room for title, header, footer, etc.
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
		if strings.Contains(strings.ToLower(config.Key), queryLower) ||
			strings.Contains(strings.ToLower(config.Value), queryLower) {
			filtered = append(filtered, config)
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
		
		// Get filtered configs
		filteredConfigs := getFilteredConfigs(m.configItems, m.configSearchQuery)
		totalItems := len(filteredConfigs) + 3 // configs + 3 menu options (add, reload, back)
		
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
				// Adjust scroll if cursor goes above visible area
				if m.configCursor < m.configScrollOffset {
					m.configScrollOffset = m.configCursor
				}
			}
			
		case "down", "j":
			if m.configCursor < totalItems-1 {
				m.configCursor++
				// Adjust scroll if cursor goes below visible area
				if m.configCursor >= m.configScrollOffset+m.configVisibleRows {
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
			// Adjust scroll
			maxOffset := totalItems - m.configVisibleRows
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
			// Go to bottom
			m.configCursor = totalItems - 1
			maxOffset := totalItems - m.configVisibleRows
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
			configCount := len(filteredConfigs)
			
			if m.configCursor < configCount {
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
		if m.height > 20 {
			m.configVisibleRows = m.height - 18
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
	if totalItems > m.configVisibleRows {
		percentage := 0
		if totalItems > 1 {
			percentage = (m.configCursor * 100) / (totalItems - 1)
		}
		s.WriteString(helpStyle.Render(fmt.Sprintf("[%d/%d] %d%%", m.configCursor+1, totalItems, percentage)))
	}
	s.WriteString("\n\n")
	
	// Table header
	headerStyle := lipgloss.NewStyle().
		Foreground(lipgloss.Color("#7D56F4")).
		Bold(true)
	
	// Calculate max key width for alignment
	maxKeyWidth := 30
	for _, config := range filteredConfigs {
		if len(config.Key) > maxKeyWidth {
			maxKeyWidth = len(config.Key)
		}
	}
	if maxKeyWidth > 40 {
		maxKeyWidth = 40 // Cap at 40 chars
	}
	
	s.WriteString(headerStyle.Render(fmt.Sprintf("  %-*s │ %s", maxKeyWidth, "KEY", "VALUE")))
	s.WriteString("\n")
	s.WriteString(headerStyle.Render(strings.Repeat("─", maxKeyWidth+2) + "┼" + strings.Repeat("─", 40)))
	s.WriteString("\n")
	
	// Calculate visible range
	visibleRows := m.configVisibleRows
	if visibleRows <= 0 {
		visibleRows = defaultConfigVisibleRows
	}
	
	startIdx := m.configScrollOffset
	endIdx := startIdx + visibleRows
	if endIdx > totalItems {
		endIdx = totalItems
	}
	
	// Show scroll indicators
	if startIdx > 0 {
		s.WriteString(helpStyle.Render("  ▲ more above..."))
		s.WriteString("\n")
	}
	
	// Display configs within visible range
	for i := startIdx; i < endIdx; i++ {
		if i < configCount {
			config := filteredConfigs[i]
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
		} else {
			// Menu options (Add, Reload, Back)
			menuIdx := i - configCount
			menuOptions := []string{
				"➕ Add New Configuration",
				"🔄 Reload Services",
				"🔙 Back to Main Menu",
			}
			
			if menuIdx < len(menuOptions) {
				cursor := " "
				if m.configCursor == i {
					cursor = "▶"
				}
				
				// Add separator before first menu option if visible
				isFirstMenuOption := menuIdx == 0
				if isFirstMenuOption {
					s.WriteString("\n")
				}
				
				line := cursor + " " + menuOptions[menuIdx]
				if m.configCursor == i {
					s.WriteString(selectedItemStyle.Render(line))
				} else {
					s.WriteString(line)
				}
				s.WriteString("\n")
			}
		}
	}
	
	// Show scroll indicator at bottom
	if endIdx < totalItems {
		s.WriteString(helpStyle.Render("  ▼ more below..."))
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
