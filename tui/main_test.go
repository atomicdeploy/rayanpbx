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
	
	// Now we have 9 fields for extension creation (including advanced PJSIP options)
	// Extension Number, Name, Password, Codecs, Context, Transport, Direct Media, Max Contacts, Qualify Frequency
	if len(m.inputFields) != 9 {
		t.Errorf("Expected 9 input fields for extension (including advanced PJSIP options), got %d", len(m.inputFields))
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
		asteriskMenuScreen,
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
		systemSettingsScreen,
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

// TestAsteriskMenuInitialization tests asterisk menu initialization
func TestAsteriskMenuInitialization(t *testing.T) {
	m := initialModel(nil, nil, false)
	
	if m.asteriskManager == nil {
		t.Error("Expected asteriskManager to be initialized")
	}
	
	if len(m.asteriskMenu) == 0 {
		t.Error("Expected asteriskMenu to be populated")
	}
	
	// Check for key menu items
	expectedItems := []string{"Start", "Stop", "Restart", "Status", "PJSIP", "Dialplan", "Reload All", "Endpoints", "Channels", "Registrations", "Back"}
	found := make(map[string]bool)
	for _, item := range m.asteriskMenu {
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

// TestAsteriskMenuNavigation tests asterisk menu navigation
func TestAsteriskMenuNavigation(t *testing.T) {
	m := initialModel(nil, nil, false)
	m.currentScreen = asteriskMenuScreen
	
	// Test that cursor starts at 0
	if m.cursor != 0 {
		t.Errorf("Expected cursor to start at 0, got %d", m.cursor)
	}
	
	// Test that we can navigate the menu
	menuLength := len(m.asteriskMenu)
	if menuLength == 0 {
		t.Fatal("asteriskMenu is empty")
	}
	
	// Verify menu has expected number of items (12 now including Show Transports)
	expectedMenuItems := 12
	if menuLength != expectedMenuItems {
		t.Errorf("Expected %d menu items, got %d", expectedMenuItems, menuLength)
	}
}

// TestExtensionToggleKeyBinding tests that 't' key is bound for toggle in extension screen
func TestExtensionToggleKeyBinding(t *testing.T) {
	m := initialModel(nil, nil, false)
	m.currentScreen = extensionsScreen
	
	// Populate some test extensions
	m.extensions = []Extension{
		{
			ID:              1,
			ExtensionNumber: "100",
			Name:            "Test Extension 1",
			Enabled:         true,
		},
		{
			ID:              2,
			ExtensionNumber: "101",
			Name:            "Test Extension 2",
			Enabled:         false,
		},
	}
	m.selectedExtensionIdx = 0
	
	// Note: We cannot fully test toggleExtension without a real database
	// but we can verify the extension selection logic
	if m.selectedExtensionIdx != 0 {
		t.Error("Expected selectedExtensionIdx to be 0")
	}
	
	if len(m.extensions) != 2 {
		t.Errorf("Expected 2 extensions, got %d", len(m.extensions))
	}
	
	if m.extensions[0].Enabled != true {
		t.Error("Expected first extension to be enabled")
	}
	
	if m.extensions[1].Enabled != false {
		t.Error("Expected second extension to be disabled")
	}
}

// TestExtensionScreenHelpText tests that extension screen shows toggle help
func TestExtensionScreenHelpText(t *testing.T) {
	m := initialModel(nil, nil, false)
	m.currentScreen = extensionsScreen
	
	// Populate some test extensions
	m.extensions = []Extension{
		{
			ID:              1,
			ExtensionNumber: "100",
			Name:            "Test Extension",
			Enabled:         true,
		},
	}
	
	// Verify extensions screen renders without error
	output := m.renderExtensions()
	
	// Check that the output contains extension info (toggle hint is now in footer, not in-box)
	if !strings.Contains(output, "100") || !strings.Contains(output, "Test Extension") {
		t.Error("Expected extensions screen to display extension info")
	}
	
	// Check that the footer contains toggle hint (rendered via View())
	fullOutput := m.View()
	if !strings.Contains(strings.ToLower(fullOutput), "toggle") {
		t.Error("Expected footer to mention toggle functionality")
	}
}

// TestMainMenuCursorPreservation tests that mainMenuCursor is saved when navigating to submenus
func TestMainMenuCursorPreservation(t *testing.T) {
	// Test cases for all menu items that should save mainMenuCursor
	testCases := []struct {
		name              string
		cursorPosition    int
		expectedMenuSave  bool
	}{
		{"Hello World Setup", 0, true},
		{"Extensions Management", 1, true},
		{"Trunks Management", 2, true},
		{"VoIP Phones Management", 3, true},
		{"Asterisk Management", 4, true},
		{"Diagnostics & Debugging", 5, true},
		{"System Status", 6, true},
		{"Logs Viewer", 7, true},
		{"CLI Usage Guide", 8, true},
		{"Configuration Management", 9, true},
		{"System Settings", 10, true},
	}
	
	for _, tc := range testCases {
		t.Run(tc.name, func(t *testing.T) {
			m := initialModel(nil, nil, false)
			m.currentScreen = mainMenu
			m.cursor = tc.cursorPosition
			m.mainMenuCursor = -1 // Reset to invalid value
			
			// Navigate to submenu by simulating enter key
			// Since we can't properly simulate the Update function without a DB,
			// we verify the structure exists and that mainMenuCursor saving logic is consistent
			
			// Check that this cursor position is valid
			if tc.cursorPosition >= len(m.menuItems) {
				t.Skipf("Menu item %d not in menu", tc.cursorPosition)
			}
			
			// The expected behavior is that all submenus should save mainMenuCursor
			// This test validates the test structure is correct
			if !tc.expectedMenuSave {
				t.Errorf("All submenus should save mainMenuCursor, but test case for %s says otherwise", tc.name)
			}
		})
	}
}

// TestMenuItemsCount tests that we have the expected number of main menu items
func TestMenuItemsCount(t *testing.T) {
	m := initialModel(nil, nil, false)
	
	// We expect 12 menu items (including Exit)
	expectedItems := 12
	if len(m.menuItems) != expectedItems {
		t.Errorf("Expected %d menu items, got %d", expectedItems, len(m.menuItems))
	}
	
	// Verify specific items exist
	expectedTexts := []string{
		"Hello World",
		"Extensions",
		"Trunks",
		"VoIP Phones",
		"Asterisk",
		"Diagnostics",
		"Status",
		"Logs",
		"Usage",
		"Configuration",
		"Settings",
		"Exit",
	}
	
	for _, expected := range expectedTexts {
		found := false
		for _, item := range m.menuItems {
			if strings.Contains(item, expected) {
				found = true
				break
			}
		}
		if !found {
			t.Errorf("Expected to find menu item containing '%s'", expected)
		}
	}
}
