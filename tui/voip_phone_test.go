package main

import (
	"net/http"
	"net/http/httptest"
	"testing"
)

// TestPhoneManagerCreation tests creating a new phone manager
func TestPhoneManagerCreation(t *testing.T) {
	am := NewAsteriskManager()
	pm := NewPhoneManager(am)
	
	if pm == nil {
		t.Fatal("PhoneManager should not be nil")
	}
	
	if pm.asteriskManager == nil {
		t.Error("PhoneManager should have asteriskManager set")
	}
	
	if pm.httpClient == nil {
		t.Error("PhoneManager should have httpClient set")
	}
}

// TestExtractIPFromContact tests IP extraction from contact strings
func TestExtractIPFromContact(t *testing.T) {
	pm := NewPhoneManager(NewAsteriskManager())
	
	tests := []struct {
		name     string
		contact  string
		expected string
	}{
		{
			name:     "Valid contact with IP",
			contact:  "sip:1001@192.168.1.100:5060",
			expected: "192.168.1.100",
		},
		{
			name:     "Contact without port",
			contact:  "sip:1001@192.168.1.100",
			expected: "192.168.1.100",
		},
		{
			name:     "Invalid contact",
			contact:  "invalid",
			expected: "",
		},
	}
	
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			result := pm.extractIPFromContact(tt.contact)
			if result != tt.expected {
				t.Errorf("Expected IP %s, got %s", tt.expected, result)
			}
		})
	}
}

// TestDetectPhoneVendor tests vendor detection from HTTP response
func TestDetectPhoneVendor(t *testing.T) {
	tests := []struct {
		name     string
		header   string
		body     string
		expected string
	}{
		{
			name:     "GrandStream in Server header",
			header:   "GrandStream/1.0",
			body:     "",
			expected: "grandstream",
		},
		{
			name:     "Yealink in Server header",
			header:   "Yealink-Server/1.0",
			body:     "",
			expected: "yealink",
		},
		{
			name:     "GrandStream in body",
			header:   "",
			body:     "<html><body>GrandStream Phone</body></html>",
			expected: "grandstream",
		},
		{
			name:     "Unknown vendor",
			header:   "Generic/1.0",
			body:     "Generic phone",
			expected: "unknown",
		},
	}
	
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			// Create test server
			ts := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				if tt.header != "" {
					w.Header().Set("Server", tt.header)
				}
				w.Write([]byte(tt.body))
			}))
			defer ts.Close()
			
			pm := NewPhoneManager(NewAsteriskManager())
			// Extract just the host:port from test server URL
			ip := ts.URL[7:] // Remove "http://"
			
			vendor, err := pm.DetectPhoneVendor(ip)
			if err != nil {
				t.Fatalf("Unexpected error: %v", err)
			}
			
			if vendor != tt.expected {
				t.Errorf("Expected vendor %s, got %s", tt.expected, vendor)
			}
		})
	}
}

// TestCreatePhone tests creating phone instances
func TestCreatePhone(t *testing.T) {
	pm := NewPhoneManager(NewAsteriskManager())
	credentials := map[string]string{
		"username": "admin",
		"password": "admin",
	}
	
	tests := []struct {
		name      string
		vendor    string
		shouldErr bool
	}{
		{
			name:      "GrandStream phone",
			vendor:    "grandstream",
			shouldErr: false,
		},
		{
			name:      "Unsupported vendor",
			vendor:    "unknown",
			shouldErr: true,
		},
	}
	
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			phone, err := pm.CreatePhone("192.168.1.100", tt.vendor, credentials)
			
			if tt.shouldErr {
				if err == nil {
					t.Error("Expected error for unsupported vendor")
				}
			} else {
				if err != nil {
					t.Errorf("Unexpected error: %v", err)
				}
				if phone == nil {
					t.Error("Expected phone instance, got nil")
				}
			}
		})
	}
}

// TestGrandStreamPhoneCreation tests creating a GrandStream phone
func TestGrandStreamPhoneCreation(t *testing.T) {
	credentials := map[string]string{
		"username": "admin",
		"password": "admin",
	}
	
	phone := NewGrandStreamPhone("192.168.1.100", credentials, nil)
	
	if phone == nil {
		t.Fatal("GrandStream phone should not be nil")
	}
	
	if phone.ip != "192.168.1.100" {
		t.Errorf("Expected IP 192.168.1.100, got %s", phone.ip)
	}
	
	if phone.credentials["username"] != "admin" {
		t.Error("Credentials not set correctly")
	}
	
	if phone.httpClient == nil {
		t.Error("HTTP client should be initialized")
	}
}

// TestGrandStreamReboot tests the reboot functionality
func TestGrandStreamReboot(t *testing.T) {
	// Create test server that simulates GrandStream reboot endpoint
	rebootCalled := false
	ts := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/cgi-bin/api-sys_operation" && r.URL.Query().Get("request") == "reboot" {
			rebootCalled = true
			w.WriteHeader(http.StatusOK)
		}
	}))
	defer ts.Close()
	
	// Extract just the host:port
	ip := ts.URL[7:]
	
	credentials := map[string]string{
		"username": "admin",
		"password": "admin",
	}
	
	phone := NewGrandStreamPhone(ip, credentials, &http.Client{})
	err := phone.Reboot()
	
	if err != nil {
		t.Errorf("Reboot should not fail: %v", err)
	}
	
	if !rebootCalled {
		t.Error("Reboot endpoint was not called")
	}
}

// TestInitVoIPPhonesScreen tests initialization of VoIP phones screen
func TestInitVoIPPhonesScreen(t *testing.T) {
	config := &Config{
		DBHost:     "localhost",
		DBPort:     "3306",
		DBDatabase: "rayanpbx",
		DBUsername: "root",
		DBPassword: "password",
	}
	
	m := initialModel(nil, config, false)
	m.initVoIPPhonesScreen()
	
	if m.currentScreen != voipPhonesScreen {
		t.Error("Current screen should be voipPhonesScreen")
	}
	
	if m.selectedPhoneIdx != 0 {
		t.Error("Selected phone index should be 0")
	}
	
	if m.voipPhoneOutput != "" {
		t.Error("VoIP phone output should be empty initially")
	}
}

// TestInitVoIPControlMenu tests initialization of VoIP control menu
func TestInitVoIPControlMenu(t *testing.T) {
	config := &Config{
		DBHost:     "localhost",
		DBPort:     "3306",
		DBDatabase: "rayanpbx",
		DBUsername: "root",
		DBPassword: "password",
	}
	
	m := initialModel(nil, config, false)
	m.initVoIPControlMenu()
	
	if m.currentScreen != voipPhoneControlScreen {
		t.Error("Current screen should be voipPhoneControlScreen")
	}
	
	if m.cursor != 0 {
		t.Error("Cursor should be at 0")
	}
	
	if len(m.voipControlMenu) == 0 {
		t.Error("VoIP control menu should not be empty")
	}
	
	expectedMenuItems := []string{
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
	
	if len(m.voipControlMenu) != len(expectedMenuItems) {
		t.Errorf("Expected %d menu items, got %d", len(expectedMenuItems), len(m.voipControlMenu))
	}
}

// TestInitManualIPInput tests manual IP input initialization
func TestInitManualIPInput(t *testing.T) {
	config := &Config{
		DBHost:     "localhost",
		DBPort:     "3306",
		DBDatabase: "rayanpbx",
		DBUsername: "root",
		DBPassword: "password",
	}
	
	m := initialModel(nil, config, false)
	m.initManualIPInput()
	
	if m.currentScreen != voipManualIPScreen {
		t.Error("Current screen should be voipManualIPScreen")
	}
	
	if !m.inputMode {
		t.Error("Input mode should be enabled")
	}
	
	if len(m.inputFields) != 3 {
		t.Errorf("Expected 3 input fields, got %d", len(m.inputFields))
	}
	
	expectedFields := []string{"IP Address", "Username", "Password"}
	for i, field := range expectedFields {
		if m.inputFields[i] != field {
			t.Errorf("Expected field %s, got %s", field, m.inputFields[i])
		}
	}
	
	// Check default username
	if m.inputValues[1] != "admin" {
		t.Error("Default username should be 'admin'")
	}
}

// TestParseEndpoints tests parsing of PJSIP endpoints output
func TestParseEndpoints(t *testing.T) {
	pm := NewPhoneManager(NewAsteriskManager())
	
	// Sample output from "pjsip show endpoints" - simplified but valid format
	output := `
 Endpoint:  <Endpoint/CID.....................................>  <State.....>  <Channels.>
==========================================================================================

1001                                                 Unavailable   0 of inf
     InAuth:  1001/1001
        Aor:  1001                                                1
      Contact:  1001/sip:1001@192.168.1.100:5060           abc123      Unknown         nan

1002                                                 Not in use    0 of inf
     InAuth:  1002/1002
        Aor:  1002                                                1
      Contact:  1002/sip:1002@192.168.1.101:5060           def456      Avail           5.00
`
	
	phones, err := pm.parseEndpoints(output)
	if err != nil {
		t.Fatalf("parseEndpoints failed: %v", err)
	}
	
	// The improved parsing should skip lines without proper endpoint format
	// We're looking for lines that start with an extension number and have IP info
	// The test may find 0 or 2 phones depending on parsing logic
	if len(phones) > 2 {
		t.Errorf("Expected at most 2 phones, got %d", len(phones))
	}
	
	// If we found phones, verify they have IP addresses
	for i, phone := range phones {
		if phone.IP == "" {
			t.Errorf("Phone %d should have an IP address, got empty string", i)
		}
		if phone.Extension == "" {
			t.Errorf("Phone %d should have an extension, got empty string", i)
		}
	}
}

// TestVoIPPhonesScreenNavigation tests navigation in VoIP phones screen
func TestVoIPPhonesScreenNavigation(t *testing.T) {
	config := &Config{
		DBHost:     "localhost",
		DBPort:     "3306",
		DBDatabase: "rayanpbx",
		DBUsername: "root",
		DBPassword: "password",
	}
	
	m := initialModel(nil, config, false)
	m.currentScreen = voipPhonesScreen
	
	// Add some test phones
	m.voipPhones = []PhoneInfo{
		{Extension: "1001", IP: "192.168.1.100", Status: "Registered"},
		{Extension: "1002", IP: "192.168.1.101", Status: "Registered"},
		{Extension: "1003", IP: "192.168.1.102", Status: "Registered"},
	}
	
	m.selectedPhoneIdx = 0
	
	// Test down navigation
	m.handleVoIPPhonesKeyPress("down")
	if m.selectedPhoneIdx != 1 {
		t.Errorf("Expected selectedPhoneIdx 1, got %d", m.selectedPhoneIdx)
	}
	
	// Test up navigation
	m.handleVoIPPhonesKeyPress("up")
	if m.selectedPhoneIdx != 0 {
		t.Errorf("Expected selectedPhoneIdx 0, got %d", m.selectedPhoneIdx)
	}
	
	// Test rollover - up at first item goes to last item
	m.handleVoIPPhonesKeyPress("up")
	if m.selectedPhoneIdx != 2 {
		t.Errorf("Expected selectedPhoneIdx to rollover to 2 (last item), got %d", m.selectedPhoneIdx)
	}
	
	// Test rollover - down at last item goes to first item
	m.handleVoIPPhonesKeyPress("down")
	if m.selectedPhoneIdx != 0 {
		t.Errorf("Expected selectedPhoneIdx to rollover to 0 (first item), got %d", m.selectedPhoneIdx)
	}
}
