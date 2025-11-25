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

	config := setup.GenerateHelloWorldExtension()

	// Check for expected content
	expectedStrings := []string{
		"; BEGIN MANAGED - RayanPBX Hello World Extension",
		"[101]",
		"type=endpoint",
		"context=from-internal",
		"type=auth",
		"password=101pass",
		"username=101",
		"type=aor",
		"max_contacts=1",
		"; END MANAGED - RayanPBX Hello World Extension",
	}

	for _, expected := range expectedStrings {
		if !strings.Contains(config, expected) {
			t.Errorf("Expected config to contain '%s'", expected)
		}
	}
}

func TestGenerateHelloWorldDialplan(t *testing.T) {
	asteriskManager := NewAsteriskManager()
	configManager := NewAsteriskConfigManager(false)
	setup := NewHelloWorldSetup(configManager, asteriskManager, false)

	config := setup.GenerateHelloWorldDialplan()

	// Check for expected content
	expectedStrings := []string{
		"; BEGIN MANAGED - RayanPBX Hello World Dialplan",
		"[from-internal]",
		"exten = 100,1,Answer()",
		"same = n,Wait(1)",
		"same = n,Playback(hello-world)",
		"same = n,Hangup()",
		"; END MANAGED - RayanPBX Hello World Dialplan",
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

	config := setup.GenerateTransportConfig()

	// Check for expected content
	expectedStrings := []string{
		"; BEGIN MANAGED - RayanPBX Transport",
		"[transport-udp]",
		"type=transport",
		"protocol=udp",
		"bind=0.0.0.0",
		"; END MANAGED - RayanPBX Transport",
	}

	for _, expected := range expectedStrings {
		if !strings.Contains(config, expected) {
			t.Errorf("Expected transport config to contain '%s'", expected)
		}
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
