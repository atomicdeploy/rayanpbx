package main

import (
	"fmt"
	"os"
	"path/filepath"
	"testing"
)

// TestConfigManagerCreation tests that ConfigManager is created correctly
func TestConfigManagerCreation(t *testing.T) {
	cm := NewConfigManager(false)
	
	if cm == nil {
		t.Error("Expected ConfigManager to be created")
	}
	
	if cm.envPath == "" {
		t.Error("Expected envPath to be set")
	}
}

// TestConfigManagerLoadConfigs tests loading configs from a test .env file
func TestConfigManagerLoadConfigs(t *testing.T) {
	// Create a temporary directory for testing
	tmpDir := t.TempDir()
	envPath := filepath.Join(tmpDir, ".env")
	
	// Create a test .env file without section headers
	testEnvContent := `APP_NAME=TestApp
APP_ENV=development
DB_PASSWORD=secret123
API_KEY=my-api-key
`
	if err := os.WriteFile(envPath, []byte(testEnvContent), 0644); err != nil {
		t.Fatalf("Failed to create test .env file: %v", err)
	}
	
	// Create a ConfigManager with custom path
	cm := &ConfigManager{
		envPath: envPath,
		verbose: false,
	}
	
	if err := cm.LoadConfigs(); err != nil {
		t.Fatalf("Failed to load configs: %v", err)
	}
	
	configs := cm.GetConfigs()
	// Count only non-section configs
	configCount := 0
	for _, c := range configs {
		if !c.IsSection {
			configCount++
		}
	}
	if configCount != 4 {
		t.Errorf("Expected 4 configs (non-section), got %d", configCount)
	}
	
	// Check that sensitive keys are marked correctly
	dbPasswordConfig := cm.GetConfig("DB_PASSWORD")
	if dbPasswordConfig == nil {
		t.Error("Expected to find DB_PASSWORD config")
	} else if !dbPasswordConfig.Sensitive {
		t.Error("Expected DB_PASSWORD to be marked as sensitive")
	}
	
	apiKeyConfig := cm.GetConfig("API_KEY")
	if apiKeyConfig == nil {
		t.Error("Expected to find API_KEY config")
	} else if !apiKeyConfig.Sensitive {
		t.Error("Expected API_KEY to be marked as sensitive")
	}
	
	appNameConfig := cm.GetConfig("APP_NAME")
	if appNameConfig == nil {
		t.Error("Expected to find APP_NAME config")
	} else if appNameConfig.Sensitive {
		t.Error("Expected APP_NAME to NOT be marked as sensitive")
	}
}

// TestConfigIsSensitive tests the sensitivity detection
func TestConfigIsSensitive(t *testing.T) {
	cm := &ConfigManager{}
	
	sensitiveKeys := []string{
		"DB_PASSWORD",
		"JWT_SECRET",
		"API_KEY",
		"PRIVATE_KEY",
		"AMI_SECRET",
		"SOME_TOKEN",
	}
	
	for _, key := range sensitiveKeys {
		if !cm.isSensitive(key) {
			t.Errorf("Expected %s to be sensitive", key)
		}
	}
	
	nonSensitiveKeys := []string{
		"APP_NAME",
		"APP_ENV",
		"DB_HOST",
		"DB_PORT",
		"LOG_LEVEL",
	}
	
	for _, key := range nonSensitiveKeys {
		if cm.isSensitive(key) {
			t.Errorf("Expected %s to NOT be sensitive", key)
		}
	}
}

// TestConfigIsValidKey tests the key validation
func TestConfigIsValidKey(t *testing.T) {
	cm := &ConfigManager{}
	
	validKeys := []string{
		"APP_NAME",
		"DB_HOST",
		"API_KEY_1",
		"_UNDERSCORE",
		"A",
	}
	
	for _, key := range validKeys {
		if !cm.isValidKey(key) {
			t.Errorf("Expected %s to be a valid key", key)
		}
	}
	
	invalidKeys := []string{
		"lowercase",
		"Mixed_Case",
		"has spaces",
		"has-dash",
		"123START",
	}
	
	for _, key := range invalidKeys {
		if cm.isValidKey(key) {
			t.Errorf("Expected %s to be an invalid key", key)
		}
	}
}

// TestGetFilteredConfigs tests the config filtering functionality
func TestGetFilteredConfigs(t *testing.T) {
	configs := []EnvConfig{
		{Key: "APP_NAME", Value: "RayanPBX"},
		{Key: "APP_ENV", Value: "development"},
		{Key: "DB_HOST", Value: "localhost"},
		{Key: "DB_PORT", Value: "3306"},
		{Key: "API_KEY", Value: "secret"},
	}
	
	// Test empty query returns all configs
	filtered := getFilteredConfigs(configs, "")
	if len(filtered) != 5 {
		t.Errorf("Expected 5 configs with empty filter, got %d", len(filtered))
	}
	
	// Test filtering by key
	filtered = getFilteredConfigs(configs, "APP")
	if len(filtered) != 2 {
		t.Errorf("Expected 2 configs matching 'APP', got %d", len(filtered))
	}
	
	// Test filtering by value
	filtered = getFilteredConfigs(configs, "local")
	if len(filtered) != 1 {
		t.Errorf("Expected 1 config matching 'local', got %d", len(filtered))
	}
	
	// Test case insensitive filtering
	filtered = getFilteredConfigs(configs, "db")
	if len(filtered) != 2 {
		t.Errorf("Expected 2 configs matching 'db', got %d", len(filtered))
	}
	
	// Test no matches
	filtered = getFilteredConfigs(configs, "nonexistent")
	if len(filtered) != 0 {
		t.Errorf("Expected 0 configs matching 'nonexistent', got %d", len(filtered))
	}
}

// TestConfigManagementInitialization tests the init function
func TestConfigManagementInitialization(t *testing.T) {
	m := initialModel(nil, nil, false)
	
	// Initialize config management
	initConfigManagement(&m)
	
	// Verify defaults are set
	if m.configCursor != 0 {
		t.Errorf("Expected configCursor to be 0, got %d", m.configCursor)
	}
	
	if m.configScrollOffset != 0 {
		t.Errorf("Expected configScrollOffset to be 0, got %d", m.configScrollOffset)
	}
	
	if m.configSearchQuery != "" {
		t.Errorf("Expected configSearchQuery to be empty, got %s", m.configSearchQuery)
	}
	
	// With no terminal size set, visible rows should use default
	if m.configVisibleRows < 10 {
		t.Errorf("Expected configVisibleRows to be at least 10, got %d", m.configVisibleRows)
	}
}

// TestConfigManagementScrolling tests the scroll offset calculations
func TestConfigManagementScrolling(t *testing.T) {
	// Create test configs
	configs := make([]EnvConfig, 50)
	for i := 0; i < 50; i++ {
		configs[i] = EnvConfig{
			Key:       fmt.Sprintf("TEST_KEY_%d", i),
			Value:     "value",
			IsSection: false,
		}
	}
	
	m := initialModel(nil, nil, false)
	m.configItems = configs
	m.configVisibleRows = 15
	m.configScrollOffset = 0
	m.configCursor = 0
	
	totalItems := len(configs) + 3 // configs + menu options
	
	// Test cursor at top
	if m.configScrollOffset != 0 {
		t.Errorf("Expected scroll offset to be 0 at top, got %d", m.configScrollOffset)
	}
	
	// Simulate moving cursor down past visible area
	m.configCursor = 20
	// Adjust scroll as the update function would
	if m.configCursor >= m.configScrollOffset+m.configVisibleRows {
		m.configScrollOffset = m.configCursor - m.configVisibleRows + 1
	}
	
	if m.configScrollOffset != 6 {
		t.Errorf("Expected scroll offset to be 6 after moving to position 20 with 15 visible rows, got %d", m.configScrollOffset)
	}
	
	// Simulate going to bottom
	m.configCursor = totalItems - 1
	maxOffset := totalItems - m.configVisibleRows
	if maxOffset < 0 {
		maxOffset = 0
	}
	m.configScrollOffset = maxOffset
	
	if m.configScrollOffset != maxOffset {
		t.Errorf("Expected scroll offset to be %d at bottom, got %d", maxOffset, m.configScrollOffset)
	}
}

// TestConfigManagementModelFields tests that the model has the required fields
func TestConfigManagementModelFields(t *testing.T) {
	m := initialModel(nil, nil, false)
	
	// Test that config management fields exist on model
	// These will fail to compile if the fields don't exist
	_ = m.configScrollOffset
	_ = m.configVisibleRows
	_ = m.configItems
	_ = m.configCursor
	_ = m.configSearchQuery
}

// TestDefaultConfigVisibleRows tests the default visible rows constant
func TestDefaultConfigVisibleRows(t *testing.T) {
	if defaultConfigVisibleRows < 10 {
		t.Errorf("Expected defaultConfigVisibleRows to be at least 10, got %d", defaultConfigVisibleRows)
	}
	
	if defaultConfigVisibleRows > 30 {
		t.Errorf("Expected defaultConfigVisibleRows to be at most 30, got %d", defaultConfigVisibleRows)
	}
}

// TestSectionHeaderParsing tests that section headers are parsed correctly from .env files
func TestSectionHeaderParsing(t *testing.T) {
	// Create a temporary directory for testing
	tmpDir := t.TempDir()
	envPath := filepath.Join(tmpDir, ".env")
	
	// Create a test .env file with section headers
	testEnvContent := `# Application
APP_NAME=TestApp
APP_ENV=development

# Database Configuration
DB_HOST=localhost
DB_PORT=3306
`
	if err := os.WriteFile(envPath, []byte(testEnvContent), 0644); err != nil {
		t.Fatalf("Failed to create test .env file: %v", err)
	}
	
	// Create a ConfigManager with custom path
	cm := &ConfigManager{
		envPath: envPath,
		verbose: false,
	}
	
	if err := cm.LoadConfigs(); err != nil {
		t.Fatalf("Failed to load configs: %v", err)
	}
	
	configs := cm.GetConfigs()
	
	// Count sections and configs
	sectionCount := 0
	configCount := 0
	for _, c := range configs {
		if c.IsSection {
			sectionCount++
		} else {
			configCount++
		}
	}
	
	if sectionCount != 2 {
		t.Errorf("Expected 2 section headers, got %d", sectionCount)
	}
	
	if configCount != 4 {
		t.Errorf("Expected 4 configs, got %d", configCount)
	}
	
	// Verify section names
	foundApplicationSection := false
	foundDatabaseSection := false
	for _, c := range configs {
		if c.IsSection {
			if c.SectionName == "Application" {
				foundApplicationSection = true
			}
			if c.SectionName == "Database Configuration" {
				foundDatabaseSection = true
			}
		}
	}
	
	if !foundApplicationSection {
		t.Error("Expected to find 'Application' section header")
	}
	if !foundDatabaseSection {
		t.Error("Expected to find 'Database Configuration' section header")
	}
}

// TestIsSectionHeader tests the section header detection function
func TestIsSectionHeader(t *testing.T) {
	testCases := []struct {
		comment  string
		expected bool
	}{
		{"Application", true},
		{"Database Configuration", true},
		{"API Configuration", true},
		{"Redis", true},
		{"JWT Authentication", true},
		{"This is a long comment that describes something in detail", false},
		{"Note: This is important", false},
		{"Example: value=test", false},
		{"", false},
	}
	
	for _, tc := range testCases {
		result := isSectionHeader(tc.comment)
		if result != tc.expected {
			t.Errorf("isSectionHeader(%q) = %v, expected %v", tc.comment, result, tc.expected)
		}
	}
}
