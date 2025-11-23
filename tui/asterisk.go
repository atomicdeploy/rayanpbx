package main

import (
	"fmt"
	"os/exec"
	"strings"

	"github.com/fatih/color"
)

// AsteriskManager handles Asterisk service and CLI operations
type AsteriskManager struct{}

// NewAsteriskManager creates a new Asterisk manager
func NewAsteriskManager() *AsteriskManager {
	return &AsteriskManager{}
}

// GetServiceStatus checks Asterisk service status via systemctl
func (am *AsteriskManager) GetServiceStatus() (string, error) {
	cmd := exec.Command("systemctl", "status", "asterisk")
	output, err := cmd.CombinedOutput()

	if err != nil {
		// Check if service is stopped
		if strings.Contains(string(output), "inactive") || strings.Contains(string(output), "dead") {
			return "stopped", nil
		}
		return "unknown", err
	}

	if strings.Contains(string(output), "active (running)") {
		return "running", nil
	} else if strings.Contains(string(output), "inactive") {
		return "stopped", nil
	}

	return "unknown", nil
}

// StartService starts the Asterisk service
func (am *AsteriskManager) StartService() error {
	green := color.New(color.FgGreen)
	cyan := color.New(color.FgCyan)

	cyan.Println("🔄 Starting Asterisk service...")
	cmd := exec.Command("systemctl", "start", "asterisk")
	if err := cmd.Run(); err != nil {
		return fmt.Errorf("failed to start service: %v", err)
	}

	green.Println("✅ Asterisk service started successfully")
	return nil
}

// StopService stops the Asterisk service
func (am *AsteriskManager) StopService() error {
	yellow := color.New(color.FgYellow)
	green := color.New(color.FgGreen)

	yellow.Println("⏸️  Stopping Asterisk service...")
	cmd := exec.Command("systemctl", "stop", "asterisk")
	if err := cmd.Run(); err != nil {
		return fmt.Errorf("failed to stop service: %v", err)
	}

	green.Println("✅ Asterisk service stopped successfully")
	return nil
}

// RestartService restarts the Asterisk service
func (am *AsteriskManager) RestartService() error {
	cyan := color.New(color.FgCyan)
	green := color.New(color.FgGreen)

	cyan.Println("🔄 Restarting Asterisk service...")
	cmd := exec.Command("systemctl", "restart", "asterisk")
	if err := cmd.Run(); err != nil {
		return fmt.Errorf("failed to restart service: %v", err)
	}

	green.Println("✅ Asterisk service restarted successfully")
	return nil
}

// ExecuteCLICommand executes an Asterisk CLI command
func (am *AsteriskManager) ExecuteCLICommand(command string) (string, error) {
	cmd := exec.Command("asterisk", "-rx", command)
	output, err := cmd.CombinedOutput()

	if err != nil {
		return "", fmt.Errorf("failed to execute command: %v", err)
	}

	return string(output), nil
}

// ReloadPJSIP reloads PJSIP configuration
func (am *AsteriskManager) ReloadPJSIP() error {
	cyan := color.New(color.FgCyan)
	green := color.New(color.FgGreen)

	cyan.Println("🔄 Reloading PJSIP configuration...")
	output, err := am.ExecuteCLICommand("module reload res_pjsip.so")
	if err != nil {
		return err
	}

	green.Println("✅ PJSIP configuration reloaded")
	fmt.Println(output)
	return nil
}

// ReloadDialplan reloads dialplan configuration
func (am *AsteriskManager) ReloadDialplan() error {
	cyan := color.New(color.FgCyan)
	green := color.New(color.FgGreen)

	cyan.Println("🔄 Reloading dialplan...")
	output, err := am.ExecuteCLICommand("dialplan reload")
	if err != nil {
		return err
	}

	green.Println("✅ Dialplan reloaded")
	fmt.Println(output)
	return nil
}

// ReloadAll reloads all Asterisk modules
func (am *AsteriskManager) ReloadAll() error {
	cyan := color.New(color.FgCyan)
	green := color.New(color.FgGreen)

	cyan.Println("🔄 Reloading all modules...")
	output, err := am.ExecuteCLICommand("core reload")
	if err != nil {
		return err
	}

	green.Println("✅ All modules reloaded")
	fmt.Println(output)
	return nil
}

// ShowEndpoints displays PJSIP endpoints
func (am *AsteriskManager) ShowEndpoints() (string, error) {
	return am.ExecuteCLICommand("pjsip show endpoints")
}

// ShowChannels displays active channels
func (am *AsteriskManager) ShowChannels() (string, error) {
	return am.ExecuteCLICommand("core show channels")
}

// ShowPeers displays SIP peers (legacy and PJSIP)
func (am *AsteriskManager) ShowPeers() (string, error) {
	return am.ExecuteCLICommand("pjsip show registrations")
}

// ShowDialplan displays dialplan
func (am *AsteriskManager) ShowDialplan() (string, error) {
	return am.ExecuteCLICommand("dialplan show")
}

// ValidateConfiguration validates Asterisk configuration
func (am *AsteriskManager) ValidateConfiguration() error {
	cyan := color.New(color.FgCyan)
	green := color.New(color.FgGreen)
	red := color.New(color.FgRed)

	cyan.Println("🔍 Validating Asterisk configuration...")

	// Check PJSIP configuration
	output, err := am.ExecuteCLICommand("pjsip show endpoints")
	if err != nil {
		red.Printf("❌ Error checking PJSIP endpoints: %v\n", err)
		return err
	}

	if strings.Contains(output, "No objects found") {
		red.Println("⚠️  Warning: No PJSIP endpoints configured")
	} else {
		green.Println("✅ PJSIP endpoints validated")
	}

	// Check dialplan
	output, err = am.ExecuteCLICommand("dialplan show")
	if err != nil {
		red.Printf("❌ Error checking dialplan: %v\n", err)
		return err
	}

	green.Println("✅ Dialplan validated")

	return nil
}

// PrintServiceStatus displays formatted service status
func (am *AsteriskManager) PrintServiceStatus() {
	cyan := color.New(color.FgCyan, color.Bold)
	green := color.New(color.FgGreen)
	red := color.New(color.FgRed)
	yellow := color.New(color.FgYellow)

	cyan.Println("\n⚙️  Asterisk Service Status:")
	fmt.Println("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━")

	status, err := am.GetServiceStatus()
	if err != nil {
		red.Printf("❌ Error: %v\n", err)
		return
	}

	switch status {
	case "running":
		green.Println("✅ Status: Running")
	case "stopped":
		red.Println("❌ Status: Stopped")
	default:
		yellow.Println("⚠️  Status: Unknown")
	}

	fmt.Println("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━")
	fmt.Println()
}
