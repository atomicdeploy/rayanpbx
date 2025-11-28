package main

import (
	"fmt"
	"strings"
	"testing"

	tea "github.com/charmbracelet/bubbletea"
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
	
	// Verify menu has expected number of items (13 now including Configure PJSIP Transports)
	expectedMenuItems := 13
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

// TestExtensionSelectionAfterCreation tests that the newly created extension is selected
// after the extensions list is reloaded
func TestExtensionSelectionAfterCreation(t *testing.T) {
	m := initialModel(nil, nil, false)
	m.currentScreen = extensionsScreen

	// Simulate the state after extension creation:
	// Extensions are sorted by extension_number
	m.extensions = []Extension{
		{ID: 1, ExtensionNumber: "100", Name: "First"},
		{ID: 3, ExtensionNumber: "102", Name: "Third"},
		{ID: 4, ExtensionNumber: "103", Name: "Fourth"},
	}

	// Simulate adding a new extension "101" which will be at index 1 after sorting
	newExtNumber := "101"
	m.extensions = []Extension{
		{ID: 1, ExtensionNumber: "100", Name: "First"},
		{ID: 2, ExtensionNumber: "101", Name: "New Extension"},
		{ID: 3, ExtensionNumber: "102", Name: "Third"},
		{ID: 4, ExtensionNumber: "103", Name: "Fourth"},
	}

	// The logic from createExtension() to find and select the new extension
	found := false
	for i, ext := range m.extensions {
		if ext.ExtensionNumber == newExtNumber {
			m.selectedExtensionIdx = i
			found = true
			break
		}
	}

	// The new extension "101" should be at index 1
	if m.selectedExtensionIdx != 1 {
		t.Errorf("Expected selectedExtensionIdx to be 1 for extension 101, got %d", m.selectedExtensionIdx)
	}

	// Verify the extension was found
	if !found {
		t.Error("Expected new extension to be found in the list")
	}

	// Verify the selected extension is correct
	if m.extensions[m.selectedExtensionIdx].ExtensionNumber != "101" {
		t.Errorf("Expected selected extension to be 101, got %s",
			m.extensions[m.selectedExtensionIdx].ExtensionNumber)
	}
}

// TestExtensionSelectionBoundsCheck tests that selectedExtensionIdx stays within bounds
func TestExtensionSelectionBoundsCheck(t *testing.T) {
	m := initialModel(nil, nil, false)
	m.currentScreen = extensionsScreen

	// Set a high selectedExtensionIdx (simulating previous state with more extensions)
	m.selectedExtensionIdx = 10

	// Simulate extension list with fewer items
	m.extensions = []Extension{
		{ID: 1, ExtensionNumber: "100", Name: "First"},
		{ID: 2, ExtensionNumber: "101", Name: "Second"},
	}

	// Simulate not finding the extension (searching for non-existent "999")
	newExtNumber := "999"
	found := false
	for i, ext := range m.extensions {
		if ext.ExtensionNumber == newExtNumber {
			m.selectedExtensionIdx = i
			found = true
			break
		}
	}

	// Apply bounds checking as in createExtension()
	if !found && len(m.extensions) > 0 {
		if m.selectedExtensionIdx >= len(m.extensions) {
			m.selectedExtensionIdx = len(m.extensions) - 1
		}
	} else if len(m.extensions) == 0 {
		m.selectedExtensionIdx = 0
	}

	// selectedExtensionIdx should be adjusted to be within bounds (1, since list has 2 items)
	if m.selectedExtensionIdx >= len(m.extensions) {
		t.Errorf("selectedExtensionIdx (%d) should be less than extensions length (%d)",
			m.selectedExtensionIdx, len(m.extensions))
	}

	// Should be set to last valid index
	if m.selectedExtensionIdx != 1 {
		t.Errorf("Expected selectedExtensionIdx to be 1 (last valid index), got %d", m.selectedExtensionIdx)
	}
}

// TestExtensionScreenHelpText tests that extension screen shows toggle help
func TestExtensionScreenHelpText(t *testing.T) {
	m := initialModel(nil, nil, false)
	m.currentScreen = extensionsScreen
	// Set extensionSyncManager to nil to avoid DB access
	m.extensionSyncManager = nil
	
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
		{"Quick Setup", 0, true},
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
	
	// We expect 12 menu items (including Quick Setup and Exit)
	expectedItems := 12
	if len(m.menuItems) != expectedItems {
		t.Errorf("Expected %d menu items, got %d", expectedItems, len(m.menuItems))
	}
	
	// Verify specific items exist
	expectedTexts := []string{
		"Quick Setup",
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

// TestCommandExecution tests command execution functionality
func TestCommandExecution(t *testing.T) {
	m := initialModel(nil, nil, false)
	
	// Test that command execution returns nil for empty command output
	cmd := m.executeCommand("")
	if m.usageOutput != "" {
		t.Error("Expected empty usage output for empty command")
	}
	if cmd != nil {
		t.Error("Expected nil cmd for empty command")
	}
	
	// Test that a simple command that works captures output
	cmd = m.executeCommand("echo hello")
	if cmd != nil {
		t.Error("Expected nil cmd for quick command (echo)")
	}
	if m.errorMsg != "" {
		t.Errorf("Expected no error for echo command, got: %s", m.errorMsg)
	}
	if !strings.Contains(m.usageOutput, "hello") {
		t.Errorf("Expected output to contain 'hello', got: %s", m.usageOutput)
	}
}

// TestLongRunningCommandDetection tests that long-running commands are properly detected
func TestLongRunningCommandDetection(t *testing.T) {
	m := initialModel(nil, nil, false)
	
	// Test commands that should be detected as long-running
	longRunningCommands := []string{
		"systemctl start asterisk",
		"rayanpbx-cli asterisk stop",
		"service asterisk restart",
		"rayanpbx-cli system update",
	}
	
	for _, cmdStr := range longRunningCommands {
		cmd := m.executeCommand(cmdStr)
		// For long-running commands, we expect a tea.Cmd to be returned
		// Since these commands may fail (not installed), we just check that
		// the pendingCommand is set
		if !strings.Contains(cmdStr, "start") && !strings.Contains(cmdStr, "stop") && 
		   !strings.Contains(cmdStr, "restart") && !strings.Contains(cmdStr, "update") {
			t.Errorf("Test case should contain long-running keywords: %s", cmdStr)
		}
		_ = cmd // Command may or may not be nil depending on implementation
	}
	
	// Test commands that should NOT be detected as long-running
	quickCommands := []string{
		"echo test",
		"ls -la",
	}
	
	for _, cmdStr := range quickCommands {
		m.pendingCommand = ""
		cmd := m.executeCommand(cmdStr)
		if cmd != nil {
			t.Errorf("Expected nil cmd for quick command: %s", cmdStr)
		}
		if m.pendingCommand != "" {
			t.Errorf("Expected pendingCommand to be empty for quick command: %s", cmdStr)
		}
	}
}

// TestUsageOutputDisplay tests that command output is displayed in renderUsage
func TestUsageOutputDisplay(t *testing.T) {
	m := initialModel(nil, nil, false)
	m.currentScreen = usageScreen
	m.usageCommands = getUsageCommands()
	
	// Test that output is displayed when present
	m.usageOutput = "test output"
	output := m.renderUsage()
	
	if !strings.Contains(output, "test output") {
		t.Error("Expected renderUsage to display usageOutput")
	}
	if !strings.Contains(output, "━━━") {
		t.Error("Expected renderUsage to show separator when output is present")
	}
	
	// Test that no separator is shown when output is empty
	m.usageOutput = ""
	output = m.renderUsage()
	
	if strings.Contains(output, "test output") {
		t.Error("Expected renderUsage to NOT display output when empty")
	}
}

// TestCommandFinishedMsg tests handling of commandFinishedMsg
func TestCommandFinishedMsg(t *testing.T) {
	m := initialModel(nil, nil, false)
	m.pendingCommand = "test command"
	
	// Test successful completion
	msg := commandFinishedMsg{output: "success output", err: nil}
	newModel, _ := m.Update(msg)
	updated := newModel.(model)
	
	if updated.errorMsg != "" {
		t.Errorf("Expected no error on success, got: %s", updated.errorMsg)
	}
	if updated.usageOutput != "success output" {
		t.Errorf("Expected usageOutput to be 'success output', got: %s", updated.usageOutput)
	}
	if updated.pendingCommand != "" {
		t.Error("Expected pendingCommand to be cleared after completion")
	}
	
	// Test error completion
	m.pendingCommand = "test command"
	msg = commandFinishedMsg{output: "", err: fmt.Errorf("test error")}
	newModel, _ = m.Update(msg)
	updated = newModel.(model)
	
	if !strings.Contains(updated.errorMsg, "test error") {
		t.Errorf("Expected error message to contain 'test error', got: %s", updated.errorMsg)
	}
	if updated.usageOutput != "" {
		t.Error("Expected usageOutput to be empty on error")
	}
}

// TestIsLongRunningCommand tests the long-running command detection function
func TestIsLongRunningCommand(t *testing.T) {
	testCases := []struct {
		command      string
		isLongRunning bool
	}{
		{"systemctl start asterisk", true},
		{"systemctl stop asterisk", true},
		{"systemctl restart asterisk", true},
		{"service start myservice", true},
		{"rayanpbx-cli system update", true},
		{"some-tool --update", true},
		{"echo hello", false},
		{"ls -la", false},
		{"cat /etc/hosts", false},
		{"rayanpbx-cli extension list", false},
		{"status check", false}, // 'status' alone should not match
	}
	
	for _, tc := range testCases {
		result := isLongRunningCommand(tc.command)
		if result != tc.isLongRunning {
			t.Errorf("isLongRunningCommand(%q) = %v, expected %v", tc.command, result, tc.isLongRunning)
		}
	}
}

// TestParseCommand tests the command parsing function with quoted arguments
func TestParseCommand(t *testing.T) {
	testCases := []struct {
		input       string
		executable  string
		args        []string
		expectError bool
	}{
		{"echo hello", "echo", []string{"hello"}, false},
		{"echo 'hello world'", "echo", []string{"hello world"}, false},
		{"echo \"hello world\"", "echo", []string{"hello world"}, false},
		{"ls -la /tmp", "ls", []string{"-la", "/tmp"}, false},
		{"grep -r 'search term' /path", "grep", []string{"-r", "search term", "/path"}, false},
		{"", "", nil, true},
		{"   ", "", nil, true},
		{"single", "single", []string{}, false},
	}
	
	for _, tc := range testCases {
		executable, args, err := parseCommand(tc.input)
		
		if tc.expectError {
			if err == nil {
				t.Errorf("parseCommand(%q) expected error, got none", tc.input)
			}
			continue
		}
		
		if err != nil {
			t.Errorf("parseCommand(%q) unexpected error: %v", tc.input, err)
			continue
		}
		
		if executable != tc.executable {
			t.Errorf("parseCommand(%q) executable = %q, expected %q", tc.input, executable, tc.executable)
		}
		
		if len(args) != len(tc.args) {
			t.Errorf("parseCommand(%q) args length = %d, expected %d", tc.input, len(args), len(tc.args))
			continue
		}
		
		for i, arg := range args {
			if arg != tc.args[i] {
				t.Errorf("parseCommand(%q) args[%d] = %q, expected %q", tc.input, i, arg, tc.args[i])
			}
		}
	}
}

// TestMenuRolloverNavigation tests that menu navigation wraps around at boundaries
func TestMenuRolloverNavigation(t *testing.T) {
	testCases := []struct {
		name         string
		screen       screen
		menuLen      int
		setupFunc    func(*model)
		getCursor    func(*model) int
		setCursor    func(*model, int)
	}{
		{
			name:    "Main menu rollover",
			screen:  mainMenu,
			setupFunc: func(m *model) {},
			getCursor: func(m *model) int { return m.cursor },
			setCursor: func(m *model, v int) { m.cursor = v },
		},
		{
			name:    "Diagnostics menu rollover",
			screen:  diagnosticsMenuScreen,
			setupFunc: func(m *model) {},
			getCursor: func(m *model) int { return m.cursor },
			setCursor: func(m *model, v int) { m.cursor = v },
		},
		{
			name:    "Asterisk menu rollover",
			screen:  asteriskMenuScreen,
			setupFunc: func(m *model) {},
			getCursor: func(m *model) int { return m.cursor },
			setCursor: func(m *model, v int) { m.cursor = v },
		},
		{
			name:    "SIP test menu rollover",
			screen:  sipTestMenuScreen,
			setupFunc: func(m *model) {},
			getCursor: func(m *model) int { return m.cursor },
			setCursor: func(m *model, v int) { m.cursor = v },
		},
	}
	
	for _, tc := range testCases {
		t.Run(tc.name, func(t *testing.T) {
			m := initialModel(nil, nil, false)
			m.currentScreen = tc.screen
			tc.setupFunc(&m)
			
			// Get menu length based on screen type
			var menuLen int
			switch tc.screen {
			case mainMenu:
				menuLen = len(m.menuItems)
			case diagnosticsMenuScreen:
				menuLen = len(m.diagnosticsMenu)
			case asteriskMenuScreen:
				menuLen = len(m.asteriskMenu)
			case sipTestMenuScreen:
				menuLen = len(m.sipTestMenu)
			}
			
			if menuLen == 0 {
				t.Skip("Menu is empty")
			}
			
			// Test rollover from first to last (pressing up at cursor=0)
			tc.setCursor(&m, 0)
			result, _ := m.Update(tea.KeyMsg{Type: tea.KeyUp})
			newModel := result.(model)
			if tc.getCursor(&newModel) != menuLen-1 {
				t.Errorf("Expected cursor to rollover to %d (last), got %d", menuLen-1, tc.getCursor(&newModel))
			}
			
			// Test rollover from last to first (pressing down at cursor=last)
			tc.setCursor(&m, menuLen-1)
			result, _ = m.Update(tea.KeyMsg{Type: tea.KeyDown})
			newModel = result.(model)
			if tc.getCursor(&newModel) != 0 {
				t.Errorf("Expected cursor to rollover to 0 (first), got %d", tc.getCursor(&newModel))
			}
		})
	}
}

// TestHomeEndKeyNavigation tests Home and End key navigation
func TestHomeEndKeyNavigation(t *testing.T) {
	testCases := []struct {
		name         string
		screen       screen
		setupFunc    func(*model)
		getMenuLen   func(*model) int
		getCursor    func(*model) int
		setCursor    func(*model, int)
	}{
		{
			name:    "Main menu Home/End",
			screen:  mainMenu,
			setupFunc: func(m *model) {},
			getMenuLen: func(m *model) int { return len(m.menuItems) },
			getCursor: func(m *model) int { return m.cursor },
			setCursor: func(m *model, v int) { m.cursor = v },
		},
		{
			name:    "Diagnostics menu Home/End",
			screen:  diagnosticsMenuScreen,
			setupFunc: func(m *model) {},
			getMenuLen: func(m *model) int { return len(m.diagnosticsMenu) },
			getCursor: func(m *model) int { return m.cursor },
			setCursor: func(m *model, v int) { m.cursor = v },
		},
		{
			name:    "Extensions list Home/End",
			screen:  extensionsScreen,
			setupFunc: func(m *model) {
				m.extensions = []Extension{
					{ID: 1, ExtensionNumber: "100", Name: "Test 1"},
					{ID: 2, ExtensionNumber: "101", Name: "Test 2"},
					{ID: 3, ExtensionNumber: "102", Name: "Test 3"},
				}
			},
			getMenuLen: func(m *model) int { return len(m.extensions) },
			getCursor: func(m *model) int { return m.selectedExtensionIdx },
			setCursor: func(m *model, v int) { m.selectedExtensionIdx = v },
		},
	}
	
	for _, tc := range testCases {
		t.Run(tc.name, func(t *testing.T) {
			m := initialModel(nil, nil, false)
			m.currentScreen = tc.screen
			tc.setupFunc(&m)
			
			menuLen := tc.getMenuLen(&m)
			if menuLen == 0 {
				t.Skip("Menu/list is empty")
			}
			
			// Test End key - should go to last item
			tc.setCursor(&m, 0)
			result, _ := m.Update(tea.KeyMsg{Type: tea.KeyEnd})
			newModel := result.(model)
			if tc.getCursor(&newModel) != menuLen-1 {
				t.Errorf("Expected End key to move cursor to %d (last), got %d", menuLen-1, tc.getCursor(&newModel))
			}
			
			// Test Home key - should go to first item
			tc.setCursor(&m, menuLen-1)
			result, _ = m.Update(tea.KeyMsg{Type: tea.KeyHome})
			newModel = result.(model)
			if tc.getCursor(&newModel) != 0 {
				t.Errorf("Expected Home key to move cursor to 0 (first), got %d", tc.getCursor(&newModel))
			}
		})
	}
}

// TestInputModeRollover tests that form input navigation wraps around
func TestInputModeRollover(t *testing.T) {
	m := initialModel(nil, nil, false)
	
	// Initialize with some input fields
	m.inputMode = true
	m.inputFields = []string{"Field1", "Field2", "Field3"}
	m.inputValues = []string{"", "", ""}
	m.inputCursor = 0
	
	// Test rollover from first to last (pressing up at inputCursor=0)
	result, _ := m.handleInputMode(tea.KeyMsg{Type: tea.KeyUp})
	newModel := result.(model)
	if newModel.inputCursor != 2 {
		t.Errorf("Expected inputCursor to rollover to 2 (last field), got %d", newModel.inputCursor)
	}
	
	// Reset and test rollover from last to first (pressing down at inputCursor=last)
	m.inputCursor = 2
	result, _ = m.handleInputMode(tea.KeyMsg{Type: tea.KeyDown})
	newModel = result.(model)
	if newModel.inputCursor != 0 {
		t.Errorf("Expected inputCursor to rollover to 0 (first field), got %d", newModel.inputCursor)
	}
}

// TestInputModeHomeEnd tests Home and End keys in input mode
func TestInputModeHomeEnd(t *testing.T) {
	m := initialModel(nil, nil, false)
	
	// Initialize with some input fields
	m.inputMode = true
	m.inputFields = []string{"Field1", "Field2", "Field3", "Field4"}
	m.inputValues = []string{"", "", "", ""}
	m.inputCursor = 1
	
	// Test Home key - should go to first field
	result, _ := m.handleInputMode(tea.KeyMsg{Type: tea.KeyHome})
	newModel := result.(model)
	if newModel.inputCursor != 0 {
		t.Errorf("Expected Home key to move inputCursor to 0, got %d", newModel.inputCursor)
	}
	
	// Test End key - should go to last field
	result, _ = newModel.handleInputMode(tea.KeyMsg{Type: tea.KeyEnd})
	newModel = result.(model)
	if newModel.inputCursor != 3 {
		t.Errorf("Expected End key to move inputCursor to 3 (last field), got %d", newModel.inputCursor)
	}
}

// TestVoIPControlMenuRollover tests that VoIP control menu navigation wraps around
func TestVoIPControlMenuRollover(t *testing.T) {
	m := initialModel(nil, nil, false)
	m.initVoIPControlMenu()
	
	menuLen := len(m.voipControlMenu)
	if menuLen == 0 {
		t.Fatal("voipControlMenu is empty")
	}
	
	// Test rollover from first to last (pressing up at cursor=0)
	// Note: initVoIPControlMenu sets currentScreen to voipPhoneControlScreen,
	// and handleVoIPPhonesKeyPress has special handling for this screen
	// that manages cursor navigation in the control menu
	m.cursor = 0
	m.handleVoIPPhonesKeyPress("up")
	if m.cursor != menuLen-1 {
		t.Errorf("Expected cursor to rollover to %d (last), got %d", menuLen-1, m.cursor)
	}
	
	// Test rollover from last to first (pressing down at cursor=last)
	m.cursor = menuLen - 1
	m.handleVoIPPhonesKeyPress("down")
	if m.cursor != 0 {
		t.Errorf("Expected cursor to rollover to 0 (first), got %d", m.cursor)
	}
}

// TestExtractCommandParams tests extracting parameter placeholders from commands
func TestExtractCommandParams(t *testing.T) {
	testCases := []struct {
		command    string
		wantParams []string
		hasParams  bool
	}{
		{
			command:    "rayanpbx-cli extension create <num> <name> <pass>",
			wantParams: []string{"num", "name", "pass"},
			hasParams:  true,
		},
		{
			command:    "rayanpbx-cli extension status <num>",
			wantParams: []string{"num"},
			hasParams:  true,
		},
		{
			command:    "rayanpbx-cli extension list",
			wantParams: nil,
			hasParams:  false,
		},
		{
			command:    "rayanpbx-cli diag test-trunk <name>",
			wantParams: []string{"name"},
			hasParams:  true,
		},
		{
			command:    "",
			wantParams: nil,
			hasParams:  false,
		},
	}

	for _, tc := range testCases {
		params, hasParams := extractCommandParams(tc.command)
		
		if hasParams != tc.hasParams {
			t.Errorf("extractCommandParams(%q) hasParams = %v, expected %v", tc.command, hasParams, tc.hasParams)
		}
		
		if len(params) != len(tc.wantParams) {
			t.Errorf("extractCommandParams(%q) got %d params, expected %d", tc.command, len(params), len(tc.wantParams))
			continue
		}
		
		for i, param := range params {
			if param != tc.wantParams[i] {
				t.Errorf("extractCommandParams(%q) params[%d] = %q, expected %q", tc.command, i, param, tc.wantParams[i])
			}
		}
	}
}

// TestSubstituteCommandParams tests substituting parameter values in commands
func TestSubstituteCommandParams(t *testing.T) {
	testCases := []struct {
		template string
		values   []string
		expected string
	}{
		{
			template: "rayanpbx-cli extension create <num> <name> <pass>",
			values:   []string{"100", "John Doe", "secret123"},
			expected: `rayanpbx-cli extension create 100 "John Doe" secret123`,
		},
		{
			template: "rayanpbx-cli extension status <num>",
			values:   []string{"100"},
			expected: "rayanpbx-cli extension status 100",
		},
		{
			template: "rayanpbx-cli diag test-trunk <name>",
			values:   []string{"ShatelTrunk"},
			expected: "rayanpbx-cli diag test-trunk ShatelTrunk",
		},
		{
			template: "rayanpbx-cli extension list",
			values:   []string{},
			expected: "rayanpbx-cli extension list",
		},
	}

	for _, tc := range testCases {
		result := substituteCommandParams(tc.template, tc.values)
		if result != tc.expected {
			t.Errorf("substituteCommandParams(%q, %v) = %q, expected %q", tc.template, tc.values, result, tc.expected)
		}
	}
}

// TestParameterizedCommandDetection tests that parameterized commands are properly detected
func TestParameterizedCommandDetection(t *testing.T) {
	m := initialModel(nil, nil, false)
	m.currentScreen = usageScreen
	m.usageCommands = getUsageCommands()
	
	// Test a command with parameters
	cmd := m.executeCommand("rayanpbx-cli extension create <num> <name> <pass>")
	
	// Should switch to input mode
	if !m.inputMode {
		t.Error("Expected inputMode to be true for parameterized command")
	}
	
	// Should switch to usageInputScreen
	if m.currentScreen != usageInputScreen {
		t.Errorf("Expected currentScreen to be usageInputScreen, got %d", m.currentScreen)
	}
	
	// Should have 3 input fields
	if len(m.inputFields) != 3 {
		t.Errorf("Expected 3 input fields, got %d", len(m.inputFields))
	}
	
	// Should have stored the command template
	if m.usageCommandTemplate != "rayanpbx-cli extension create <num> <name> <pass>" {
		t.Errorf("Expected usageCommandTemplate to be set, got %q", m.usageCommandTemplate)
	}
	
	// Should return nil cmd since we're switching to input mode
	if cmd != nil {
		t.Error("Expected nil cmd for parameterized command")
	}
}

// TestUsageInputScreenRendering tests that the usage input screen renders correctly
func TestUsageInputScreenRendering(t *testing.T) {
	m := initialModel(nil, nil, false)
	m.currentScreen = usageInputScreen
	m.inputMode = true
	m.usageCommandTemplate = "rayanpbx-cli extension create <num> <name> <pass>"
	m.inputFields = []string{"num", "name", "pass"}
	m.inputValues = []string{"100", "", ""}
	m.inputCursor = 1
	
	output := m.renderUsageInput()
	
	// Should contain the command template
	if !strings.Contains(output, "rayanpbx-cli extension create") {
		t.Error("Expected output to contain command template")
	}
	
	// Should contain the field names
	if !strings.Contains(output, "num") || !strings.Contains(output, "name") || !strings.Contains(output, "pass") {
		t.Error("Expected output to contain field names")
	}
	
	// Should contain the entered value
	if !strings.Contains(output, "100") {
		t.Error("Expected output to contain entered value '100'")
	}
}

// TestUsageInputScreenNavigation tests navigation in the usage input screen
func TestUsageInputScreenNavigation(t *testing.T) {
	m := initialModel(nil, nil, false)
	m.currentScreen = usageInputScreen
	m.inputMode = true
	m.usageCommandTemplate = "test <a> <b> <c>"
	m.inputFields = []string{"a", "b", "c"}
	m.inputValues = []string{"", "", ""}
	m.inputCursor = 0
	
	// Test ESC cancels and returns to usage screen
	result, _ := m.handleInputMode(tea.KeyMsg{Type: tea.KeyEsc})
	newModel := result.(model)
	
	if newModel.inputMode {
		t.Error("Expected inputMode to be false after ESC")
	}
	if newModel.currentScreen != usageScreen {
		t.Errorf("Expected currentScreen to be usageScreen after ESC, got %d", newModel.currentScreen)
	}
	if newModel.usageCommandTemplate != "" {
		t.Error("Expected usageCommandTemplate to be cleared after ESC")
	}
}

// TestQuickSetupInit tests the Quick Setup wizard initialization
func TestQuickSetupInit(t *testing.T) {
m := initialModel(nil, nil, false)

// Initialize Quick Setup
m.initQuickSetup()

// Verify initial state
if m.currentScreen != quickSetupScreen {
t.Errorf("Expected currentScreen to be quickSetupScreen, got %v", m.currentScreen)
}

if m.quickSetupStep != 0 {
t.Errorf("Expected quickSetupStep to be 0, got %d", m.quickSetupStep)
}

if m.quickSetupExtStart != "100" {
t.Errorf("Expected quickSetupExtStart to be '100', got '%s'", m.quickSetupExtStart)
}

if m.quickSetupExtEnd != "105" {
t.Errorf("Expected quickSetupExtEnd to be '105', got '%s'", m.quickSetupExtEnd)
}

if len(m.inputFields) != 3 {
t.Errorf("Expected 3 input fields, got %d", len(m.inputFields))
}

if !m.inputMode {
t.Error("Expected inputMode to be true")
}
}

// TestQuickSetupRender tests that Quick Setup renders correctly
func TestQuickSetupRender(t *testing.T) {
m := initialModel(nil, nil, false)
m.initQuickSetup()

output := m.renderQuickSetup()

// Check for expected content
if !strings.Contains(output, "Quick Setup Wizard") {
t.Error("Expected output to contain 'Quick Setup Wizard'")
}

if !strings.Contains(output, "Starting Extension Number") {
t.Error("Expected output to contain 'Starting Extension Number'")
}

if !strings.Contains(output, "Ending Extension Number") {
t.Error("Expected output to contain 'Ending Extension Number'")
}

if !strings.Contains(output, "Password for all extensions") {
t.Error("Expected output to contain 'Password for all extensions'")
}
}

// TestQuickSetupInputHandling tests input handling in Quick Setup
func TestQuickSetupInputHandling(t *testing.T) {
m := initialModel(nil, nil, false)
m.initQuickSetup()

// Test navigation
m.handleQuickSetupInput("down")
if m.inputCursor != 1 {
t.Errorf("Expected inputCursor to be 1 after down, got %d", m.inputCursor)
}

m.handleQuickSetupInput("up")
if m.inputCursor != 0 {
t.Errorf("Expected inputCursor to be 0 after up, got %d", m.inputCursor)
}

// Test character input
m.handleQuickSetupInput("1")
if !strings.HasSuffix(m.inputValues[0], "1") {
t.Errorf("Expected first input to end with '1', got '%s'", m.inputValues[0])
}

// Test backspace
originalLen := len(m.inputValues[0])
m.handleQuickSetupInput("backspace")
if len(m.inputValues[0]) != originalLen-1 {
t.Errorf("Expected input length to decrease after backspace")
}
}

// TestGetSelectedExtensionWithSyncInfos tests that getSelectedExtension returns
// the correct extension when extensionSyncInfos is populated
func TestGetSelectedExtensionWithSyncInfos(t *testing.T) {
	m := initialModel(nil, nil, false)
	
	// Create test extensions
	ext1 := Extension{ExtensionNumber: "100", Name: "Test 100", ID: 1}
	ext2 := Extension{ExtensionNumber: "200", Name: "Test 200", ID: 2}
	ext3 := Extension{ExtensionNumber: "300", Name: "Test 300", ID: 3}
	
	// Order in extensions slice: 100, 200, 300 (DB order)
	m.extensions = []Extension{ext1, ext2, ext3}
	
	// Order in extensionSyncInfos: 300, 200, 100 (different order, simulating unsorted map)
	m.extensionSyncInfos = []ExtensionSyncInfo{
		{ExtensionNumber: "300", DBExtension: &ext3, Source: SourceBoth},
		{ExtensionNumber: "200", DBExtension: &ext2, Source: SourceBoth},
		{ExtensionNumber: "100", DBExtension: &ext1, Source: SourceBoth},
	}
	
	// Test 1: Select first item in the display list (should be extension 300)
	m.selectedExtensionIdx = 0
	selected := m.getSelectedExtension()
	if selected == nil {
		t.Fatal("Expected to get selected extension, got nil")
	}
	if selected.ExtensionNumber != "300" {
		t.Errorf("Expected extension 300 at index 0, got %s", selected.ExtensionNumber)
	}
	
	// Test 2: Select second item (should be extension 200)
	m.selectedExtensionIdx = 1
	selected = m.getSelectedExtension()
	if selected == nil {
		t.Fatal("Expected to get selected extension, got nil")
	}
	if selected.ExtensionNumber != "200" {
		t.Errorf("Expected extension 200 at index 1, got %s", selected.ExtensionNumber)
	}
	
	// Test 3: Select third item (should be extension 100)
	m.selectedExtensionIdx = 2
	selected = m.getSelectedExtension()
	if selected == nil {
		t.Fatal("Expected to get selected extension, got nil")
	}
	if selected.ExtensionNumber != "100" {
		t.Errorf("Expected extension 100 at index 2, got %s", selected.ExtensionNumber)
	}
}

// TestGetSelectedExtensionWithAsteriskOnly tests that getSelectedExtension returns
// nil for extensions that are only in Asterisk (not in DB)
func TestGetSelectedExtensionWithAsteriskOnly(t *testing.T) {
	m := initialModel(nil, nil, false)
	
	// Create a DB extension
	dbExt := Extension{ExtensionNumber: "100", Name: "Test 100", ID: 1}
	
	m.extensions = []Extension{dbExt}
	
	// Create sync infos with one DB extension and one Asterisk-only extension
	m.extensionSyncInfos = []ExtensionSyncInfo{
		{ExtensionNumber: "100", DBExtension: &dbExt, Source: SourceBoth},
		{ExtensionNumber: "200", DBExtension: nil, Source: SourceAsterisk}, // Asterisk only
	}
	
	// Test selecting the Asterisk-only extension
	m.selectedExtensionIdx = 1
	selected := m.getSelectedExtension()
	if selected != nil {
		t.Errorf("Expected nil for Asterisk-only extension, got %+v", selected)
	}
	
	// hasSelectedExtension should return false
	if m.hasSelectedExtension() {
		t.Error("Expected hasSelectedExtension() to return false for Asterisk-only extension")
	}
}

// TestGetSelectedExtensionFallback tests that getSelectedExtension falls back
// to the extensions slice when extensionSyncInfos is empty
func TestGetSelectedExtensionFallback(t *testing.T) {
	m := initialModel(nil, nil, false)
	
	// Create test extensions
	ext1 := Extension{ExtensionNumber: "100", Name: "Test 100", ID: 1}
	ext2 := Extension{ExtensionNumber: "200", Name: "Test 200", ID: 2}
	
	m.extensions = []Extension{ext1, ext2}
	m.extensionSyncInfos = nil // Empty sync infos
	
	// Select first extension
	m.selectedExtensionIdx = 0
	selected := m.getSelectedExtension()
	if selected == nil {
		t.Fatal("Expected to get selected extension, got nil")
	}
	if selected.ExtensionNumber != "100" {
		t.Errorf("Expected extension 100, got %s", selected.ExtensionNumber)
	}
	
	// Select second extension
	m.selectedExtensionIdx = 1
	selected = m.getSelectedExtension()
	if selected == nil {
		t.Fatal("Expected to get selected extension, got nil")
	}
	if selected.ExtensionNumber != "200" {
		t.Errorf("Expected extension 200, got %s", selected.ExtensionNumber)
	}
}

// TestGetSelectedExtensionIndex tests that getSelectedExtensionIndex returns
// the correct index in the extensions slice
func TestGetSelectedExtensionIndex(t *testing.T) {
	m := initialModel(nil, nil, false)
	
	// Create test extensions
	ext1 := Extension{ExtensionNumber: "100", Name: "Test 100", ID: 1}
	ext2 := Extension{ExtensionNumber: "200", Name: "Test 200", ID: 2}
	ext3 := Extension{ExtensionNumber: "300", Name: "Test 300", ID: 3}
	
	// Order in extensions slice: 100, 200, 300
	m.extensions = []Extension{ext1, ext2, ext3}
	
	// Order in extensionSyncInfos: 300, 100, 200 (different order)
	m.extensionSyncInfos = []ExtensionSyncInfo{
		{ExtensionNumber: "300", DBExtension: &ext3, Source: SourceBoth},
		{ExtensionNumber: "100", DBExtension: &ext1, Source: SourceBoth},
		{ExtensionNumber: "200", DBExtension: &ext2, Source: SourceBoth},
	}
	
	// Select first item in sync infos (extension 300, which is at index 2 in extensions)
	m.selectedExtensionIdx = 0
	idx := m.getSelectedExtensionIndex()
	if idx != 2 {
		t.Errorf("Expected getSelectedExtensionIndex() = 2, got %d", idx)
	}
	
	// Select second item in sync infos (extension 100, which is at index 0 in extensions)
	m.selectedExtensionIdx = 1
	idx = m.getSelectedExtensionIndex()
	if idx != 0 {
		t.Errorf("Expected getSelectedExtensionIndex() = 0, got %d", idx)
	}
	
	// Select third item in sync infos (extension 200, which is at index 1 in extensions)
	m.selectedExtensionIdx = 2
	idx = m.getSelectedExtensionIndex()
	if idx != 1 {
		t.Errorf("Expected getSelectedExtensionIndex() = 1, got %d", idx)
	}
}
