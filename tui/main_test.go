package main

import (
	"strings"
	"testing"
)

// TestUsageCommandsGeneration tests that usage commands are generated correctly
func TestUsageCommandsGeneration(t *testing.T) {
	commands := getUsageCommands()
	
	if len(commands) == 0 {
		t.Error("Expected usage commands to be generated, got empty slice")
	}
	
	// Check that we have commands for all categories
	categories := make(map[string]int)
	for _, cmd := range commands {
		categories[cmd.Category]++
		
		// Verify each command has required fields
		if cmd.Command == "" {
			t.Errorf("Command has empty Command field: %+v", cmd)
		}
		if cmd.Category == "" {
			t.Errorf("Command has empty Category field: %+v", cmd)
		}
		if cmd.Description == "" {
			t.Errorf("Command has empty Description field: %+v", cmd)
		}
	}
	
	// Verify we have major categories
	expectedCategories := []string{"Extensions", "Trunks", "Asterisk", "Diagnostics", "System"}
	for _, expected := range expectedCategories {
		if count, ok := categories[expected]; !ok || count == 0 {
			t.Errorf("Expected category %s to have commands, got %d", expected, count)
		}
	}
}

// TestModelInitialization tests that the model initializes correctly
func TestModelInitialization(t *testing.T) {
	// Create a model without DB (nil is acceptable for this test)
	m := initialModel(nil, nil, false)
	
	if m.currentScreen != mainMenu {
		t.Errorf("Expected currentScreen to be mainMenu, got %d", m.currentScreen)
	}
	
	if len(m.menuItems) == 0 {
		t.Error("Expected menuItems to be populated")
	}
	
	if m.cursor != 0 {
		t.Errorf("Expected cursor to start at 0, got %d", m.cursor)
	}
}

// TestInputFieldsValidation tests input validation logic
func TestInputFieldsValidation(t *testing.T) {
	m := initialModel(nil, nil, false)
	
	// Test extension creation initialization
	m.initCreateExtension()
	
	if m.currentScreen != createExtensionScreen {
		t.Errorf("Expected screen to be createExtensionScreen, got %d", m.currentScreen)
	}
	
	if !m.inputMode {
		t.Error("Expected inputMode to be true after initCreateExtension")
	}
	
	if len(m.inputFields) != 3 {
		t.Errorf("Expected 3 input fields for extension, got %d", len(m.inputFields))
	}
	
	// Test trunk creation initialization
	m.initCreateTrunk()
	
	if m.currentScreen != createTrunkScreen {
		t.Errorf("Expected screen to be createTrunkScreen, got %d", m.currentScreen)
	}
	
	if len(m.inputFields) != 4 {
		t.Errorf("Expected 4 input fields for trunk, got %d", len(m.inputFields))
	}
}

// TestScreenEnumValues tests that screen enum values are distinct
func TestScreenEnumValues(t *testing.T) {
	screens := []screen{
		mainMenu,
		extensionsScreen,
		trunksScreen,
		asteriskScreen,
		diagnosticsScreen,
		statusScreen,
		logsScreen,
		usageScreen,
		createExtensionScreen,
		createTrunkScreen,
		diagnosticsMenuScreen,
		diagTestExtensionScreen,
		diagTestTrunkScreen,
		diagTestRoutingScreen,
		diagPortTestScreen,
		editExtensionScreen,
		deleteExtensionScreen,
		extensionDetailsScreen,
	}
	
	// Check that all values are unique
	seen := make(map[screen]bool)
	for _, s := range screens {
		if seen[s] {
			t.Errorf("Duplicate screen value: %d", s)
		}
		seen[s] = true
	}
	
	if len(seen) != len(screens) {
		t.Errorf("Expected %d unique screen values, got %d", len(screens), len(seen))
	}
}

// TestDiagnosticsMenuInitialization tests diagnostics menu initialization
func TestDiagnosticsMenuInitialization(t *testing.T) {
	m := initialModel(nil, nil, false)
	
	if m.diagnosticsManager == nil {
		t.Error("Expected diagnosticsManager to be initialized")
	}
	
	if len(m.diagnosticsMenu) == 0 {
		t.Error("Expected diagnosticsMenu to be populated")
	}
	
	// Check for key menu items
	expectedItems := []string{"Health Check", "System Information", "SIP Debugging"}
	found := make(map[string]bool)
	for _, item := range m.diagnosticsMenu {
		for _, expected := range expectedItems {
			if strings.Contains(item, expected) {
				found[expected] = true
			}
		}
	}
	
	for _, expected := range expectedItems {
		if !found[expected] {
			t.Errorf("Expected to find menu item containing '%s'", expected)
		}
	}
}

// TestDiagnosticsInputValidation tests input validation for diagnostics operations
func TestDiagnosticsInputValidation(t *testing.T) {
	m := initialModel(nil, nil, false)
	
	// Test extension test initialization
	m.currentScreen = diagTestExtensionScreen
	m.inputMode = true
	m.inputFields = []string{"Extension Number"}
	m.inputValues = []string{""}
	
	// Execute with empty value should fail
	m.executeDiagTestExtension()
	if m.errorMsg == "" {
		t.Error("Expected error message for empty extension number")
	}
	
	// Test trunk test initialization
	m.currentScreen = diagTestTrunkScreen
	m.inputMode = true
	m.inputFields = []string{"Trunk Name"}
	m.inputValues = []string{""}
	
	// Execute with empty value should fail
	m.executeDiagTestTrunk()
	if m.errorMsg == "" {
		t.Error("Expected error message for empty trunk name")
	}
	
	// Test routing test validation
	m.currentScreen = diagTestRoutingScreen
	m.inputFields = []string{"From Extension", "To Number"}
	m.inputValues = []string{"", ""}
	
	// Execute with empty values should fail
	m.executeDiagTestRouting()
	if m.errorMsg == "" {
		t.Error("Expected error message for empty routing fields")
	}
	
	// Test port test validation
	m.currentScreen = diagPortTestScreen
	m.inputFields = []string{"Host", "Port"}
	m.inputValues = []string{"", ""}
	
	// Execute with empty values should fail
	m.executeDiagPortTest()
	if m.errorMsg == "" {
		t.Error("Expected error message for empty port fields")
	}
	
	// Test port test with invalid port
	m.inputValues = []string{"localhost", "invalid"}
	m.executeDiagPortTest()
	if m.errorMsg == "" {
		t.Error("Expected error message for invalid port number")
	}
	
	// Test port test with out-of-range port (too low)
	m.errorMsg = ""
	m.inputValues = []string{"localhost", "0"}
	m.executeDiagPortTest()
	if m.errorMsg == "" {
		t.Error("Expected error message for port 0")
	}
	
	// Test port test with out-of-range port (too high)
	m.errorMsg = ""
	m.inputValues = []string{"localhost", "65536"}
	m.executeDiagPortTest()
	if m.errorMsg == "" {
		t.Error("Expected error message for port > 65535")
	}
}

// TestIsDiagnosticsInputScreen tests the helper function
func TestIsDiagnosticsInputScreen(t *testing.T) {
	m := initialModel(nil, nil, false)
	
	// Test that diagnostics input screens return true
	diagnosticsInputScreens := []screen{
		diagTestExtensionScreen,
		diagTestTrunkScreen,
		diagTestRoutingScreen,
		diagPortTestScreen,
	}
	
	for _, scr := range diagnosticsInputScreens {
		m.currentScreen = scr
		if !m.isDiagnosticsInputScreen() {
			t.Errorf("Expected isDiagnosticsInputScreen() to return true for screen %d", scr)
		}
	}
	
	// Test that other screens return false
	otherScreens := []screen{
		mainMenu,
		extensionsScreen,
		trunksScreen,
		asteriskScreen,
		diagnosticsMenuScreen,
		statusScreen,
	}
	
	for _, scr := range otherScreens {
		m.currentScreen = scr
		if m.isDiagnosticsInputScreen() {
			t.Errorf("Expected isDiagnosticsInputScreen() to return false for screen %d", scr)
		}
	}
}
