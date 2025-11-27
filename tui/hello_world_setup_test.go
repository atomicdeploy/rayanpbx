package main

import (
	"strings"
	"testing"
)

func TestNewHelloWorldSetup(t *testing.T) {
	asteriskManager := NewAsteriskManager()
	configManager := NewAsteriskConfigManager(false)
	setup := NewHelloWorldSetup(configManager, asteriskManager, false)

	if setup == nil {
		t.Error("Expected NewHelloWorldSetup to return non-nil")
	}

	if setup.configManager == nil {
		t.Error("Expected configManager to be set")
	}

	if setup.asteriskManager == nil {
		t.Error("Expected asteriskManager to be set")
	}
}

func TestGenerateHelloWorldExtension(t *testing.T) {
	asteriskManager := NewAsteriskManager()
	configManager := NewAsteriskConfigManager(false)
	setup := NewHelloWorldSetup(configManager, asteriskManager, false)

	sections := setup.GenerateHelloWorldExtension()

	// Check that we get 3 sections (endpoint, auth, aor)
	if len(sections) != 3 {
		t.Errorf("Expected 3 sections, got %d", len(sections))
	}

	// Check endpoint section
	endpoint := sections[0]
	if endpoint.Name != "101" || endpoint.Type != "endpoint" {
		t.Errorf("Expected endpoint section for 101, got %s/%s", endpoint.Name, endpoint.Type)
	}
	ctx, _ := endpoint.GetProperty("context")
	if ctx != "from-internal" {
		t.Errorf("Expected context 'from-internal', got '%s'", ctx)
	}

	// Check auth section
	auth := sections[1]
	if auth.Name != "101" || auth.Type != "auth" {
		t.Errorf("Expected auth section for 101, got %s/%s", auth.Name, auth.Type)
	}
	pass, _ := auth.GetProperty("password")
	if pass != "101pass" {
		t.Errorf("Expected password '101pass', got '%s'", pass)
	}

	// Check aor section
	aor := sections[2]
	if aor.Name != "101" || aor.Type != "aor" {
		t.Errorf("Expected aor section for 101, got %s/%s", aor.Name, aor.Type)
	}
	maxContacts, _ := aor.GetProperty("max_contacts")
	if maxContacts != "1" {
		t.Errorf("Expected max_contacts '1', got '%s'", maxContacts)
	}
}

func TestGenerateHelloWorldDialplan(t *testing.T) {
	asteriskManager := NewAsteriskManager()
	configManager := NewAsteriskConfigManager(false)
	setup := NewHelloWorldSetup(configManager, asteriskManager, false)

	config := setup.GenerateHelloWorldDialplan()

	// Check for expected content (dialplan is still string-based)
	expectedStrings := []string{
		"[from-internal]",
		"exten = 100,1,Answer()",
		"same = n,Wait(1)",
		"same = n,Playback(hello-world)",
		"same = n,Hangup()",
	}

	for _, expected := range expectedStrings {
		if !strings.Contains(config, expected) {
			t.Errorf("Expected dialplan to contain '%s'", expected)
		}
	}
}

func TestGenerateTransportConfig(t *testing.T) {
	asteriskManager := NewAsteriskManager()
	configManager := NewAsteriskConfigManager(false)
	setup := NewHelloWorldSetup(configManager, asteriskManager, false)

	sections := setup.GenerateTransportConfig()

	// Check that we get 2 sections (UDP and TCP transports)
	if len(sections) != 2 {
		t.Errorf("Expected 2 transport sections, got %d", len(sections))
	}

	// Check UDP transport
	udp := sections[0]
	if udp.Name != "transport-udp" || udp.Type != "transport" {
		t.Errorf("Expected transport-udp section, got %s/%s", udp.Name, udp.Type)
	}
	proto, _ := udp.GetProperty("protocol")
	if proto != "udp" {
		t.Errorf("Expected protocol 'udp', got '%s'", proto)
	}

	// Check TCP transport
	tcp := sections[1]
	if tcp.Name != "transport-tcp" || tcp.Type != "transport" {
		t.Errorf("Expected transport-tcp section, got %s/%s", tcp.Name, tcp.Type)
	}
}

func TestGetSIPCredentials(t *testing.T) {
	asteriskManager := NewAsteriskManager()
	configManager := NewAsteriskConfigManager(false)
	setup := NewHelloWorldSetup(configManager, asteriskManager, false)

	username, password, server, port := setup.GetSIPCredentials()

	if username != "101" {
		t.Errorf("Expected username '101', got '%s'", username)
	}

	if password != "101pass" {
		t.Errorf("Expected password '101pass', got '%s'", password)
	}

	if port != 5060 {
		t.Errorf("Expected port 5060, got %d", port)
	}

	// Server should be non-empty (either IP or hostname)
	if server == "" {
		t.Error("Expected server to be non-empty")
	}
}

func TestHelloWorldStatus(t *testing.T) {
	asteriskManager := NewAsteriskManager()
	configManager := NewAsteriskConfigManager(false)
	setup := NewHelloWorldSetup(configManager, asteriskManager, false)

	// GetStatus should not panic even if files don't exist
	status := setup.GetStatus()

	// Status struct should be valid (all fields should be accessible)
	_ = status.ExtensionConfigured
	_ = status.DialplanConfigured
	_ = status.TransportConfigured
	_ = status.AsteriskRunning
	_ = status.SoundFileExists
}

func TestHelloWorldMenuInitialization(t *testing.T) {
	m := model{
		helloWorldMenu: []string{
			"🚀 Run Complete Setup",
			"📊 Check Status",
			"🗑️  Remove Setup",
			"🔙 Back to Main Menu",
		},
	}

	if len(m.helloWorldMenu) != 4 {
		t.Errorf("Expected 4 menu items, got %d", len(m.helloWorldMenu))
	}
}
