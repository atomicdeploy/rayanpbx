package main

import (
	"fmt"
	"strings"
	"testing"
)

// TestGetAsteriskErrorHelpExitStatus1 tests error help for exit status 1
func TestGetAsteriskErrorHelpExitStatus1(t *testing.T) {
	err := fmt.Errorf("exit status 1")
	help := getAsteriskErrorHelp(err)

	expectedStrings := []string{
		"Asterisk may not be running",
		"systemctl status asterisk",
		"Troubleshooting",
	}

	for _, expected := range expectedStrings {
		if !strings.Contains(help, expected) {
			t.Errorf("Expected help to contain '%s', got: %s", expected, help)
		}
	}
}

// TestGetAsteriskErrorHelpExitStatus127 tests error help for exit status 127
func TestGetAsteriskErrorHelpExitStatus127(t *testing.T) {
	err := fmt.Errorf("exit status 127")
	help := getAsteriskErrorHelp(err)

	if !strings.Contains(help, "not found") {
		t.Errorf("Expected help to mention 'not found' for exit status 127, got: %s", help)
	}
}

// TestGetAsteriskErrorHelpExitStatus126 tests error help for exit status 126
func TestGetAsteriskErrorHelpExitStatus126(t *testing.T) {
	err := fmt.Errorf("exit status 126")
	help := getAsteriskErrorHelp(err)

	if !strings.Contains(help, "Permission denied") {
		t.Errorf("Expected help to mention 'Permission denied' for exit status 126, got: %s", help)
	}
}

// TestGetAsteriskErrorHelpPermissionDenied tests error help for permission denied
func TestGetAsteriskErrorHelpPermissionDenied(t *testing.T) {
	err := fmt.Errorf("open /var/run/asterisk/asterisk.ctl: permission denied")
	help := getAsteriskErrorHelp(err)

	expectedStrings := []string{
		"lacks permission",
		"Troubleshooting",
	}

	for _, expected := range expectedStrings {
		if !strings.Contains(help, expected) {
			t.Errorf("Expected help to contain '%s', got: %s", expected, help)
		}
	}
}

// TestGetAsteriskErrorHelpGenericError tests error help for a generic error
func TestGetAsteriskErrorHelpGenericError(t *testing.T) {
	err := fmt.Errorf("some unknown error")
	help := getAsteriskErrorHelp(err)

	// Should still contain troubleshooting tips
	if !strings.Contains(help, "Troubleshooting:") {
		t.Errorf("Expected help to contain 'Troubleshooting:', got: %s", help)
	}
	if !strings.Contains(help, "systemctl status asterisk") {
		t.Errorf("Expected help to contain 'systemctl status asterisk', got: %s", help)
	}
}
