package main

import (
	"fmt"
	"io"
	"net/http"
	"net/url"
	"os/exec"
	"regexp"
	"strings"
	"time"

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
		return "", fmt.Errorf("command failed: asterisk -rx %q\nOutput: %s\n%s",
			command,
			strings.TrimSpace(string(output)),
			getAsteriskErrorHelp(err))
	}

	return string(output), nil
}

// getAsteriskErrorHelp provides helpful troubleshooting info for Asterisk CLI errors
func getAsteriskErrorHelp(err error) string {
	var help strings.Builder
	help.WriteString("Possible causes:\n")

	errStr := err.Error()

	// Use word boundary regex patterns for precise exit code matching
	exitCode127 := regexp.MustCompile(`\bexit status 127\b`)
	exitCode126 := regexp.MustCompile(`\bexit status 126\b`)
	exitCode1 := regexp.MustCompile(`\bexit status 1\b`)

	// Check for specific exit codes (more specific checks first to avoid false matches)
	if exitCode127.MatchString(errStr) {
		help.WriteString("  - 'asterisk' command not found. Is Asterisk installed?\n")
		help.WriteString("  - Check if asterisk is in your PATH\n")
	} else if exitCode126.MatchString(errStr) {
		help.WriteString("  - Permission denied to execute asterisk binary\n")
		help.WriteString("  - Try running with sudo or check file permissions\n")
	} else if exitCode1.MatchString(errStr) {
		help.WriteString("  - Asterisk may not be running. Check with: systemctl status asterisk\n")
		help.WriteString("  - Invalid command syntax or Asterisk internal error\n")
		help.WriteString("  - Permission denied to access Asterisk socket\n")
	} else if strings.Contains(strings.ToLower(errStr), "permission denied") {
		help.WriteString("  - Current user lacks permission to run Asterisk commands\n")
		help.WriteString("  - Add user to 'asterisk' group or run as root\n")
	}

	// Get AI-powered solution from pollinations.ai
	aiSolution := getAISolution(errStr)
	if aiSolution != "" {
		help.WriteString("\nAI-Suggested Solution:\n")
		help.WriteString(aiSolution)
		help.WriteString("\n")
	}

	help.WriteString("\nTroubleshooting:\n")
	help.WriteString("  - Check Asterisk status: systemctl status asterisk\n")
	help.WriteString("  - View Asterisk logs: tail -f /var/log/asterisk/full\n")
	help.WriteString("  - Restart Asterisk: systemctl restart asterisk\n")

	return help.String()
}

// getAISolution fetches an AI-powered solution from pollinations.ai
func getAISolution(errorStr string) string {
	// Build query for pollinations.ai
	query := fmt.Sprintf("Brief fix for Asterisk CLI error: %s", errorStr)
	if len(query) > 150 {
		query = query[:150]
	}

	apiURL := "https://text.pollinations.ai/" + url.PathEscape(query)

	// Create HTTP client with timeout
	client := &http.Client{
		Timeout: 5 * time.Second,
	}

	resp, err := client.Get(apiURL)
	if err != nil {
		return ""
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		return ""
	}

	body, err := io.ReadAll(resp.Body)
	if err != nil {
		return ""
	}

	response := strings.TrimSpace(string(body))
	if response == "" {
		return ""
	}

	// Limit response to first 5 lines and format
	lines := strings.Split(response, "\n")
	if len(lines) > 5 {
		lines = lines[:5]
	}

	var formatted strings.Builder
	for _, line := range lines {
		if strings.TrimSpace(line) != "" {
			formatted.WriteString("  ")
			formatted.WriteString(line)
			formatted.WriteString("\n")
		}
	}

	return formatted.String()
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

// ShowTransports displays PJSIP transports
func (am *AsteriskManager) ShowTransports() (string, error) {
	return am.ExecuteCLICommand("pjsip show transports")
}

// ShowDialplan displays dialplan
func (am *AsteriskManager) ShowDialplan() (string, error) {
	return am.ExecuteCLICommand("dialplan show")
}

// VerifyEndpoint checks if an endpoint exists in Asterisk
func (am *AsteriskManager) VerifyEndpoint(endpoint string) (bool, string, error) {
	output, err := am.ExecuteCLICommand(fmt.Sprintf("pjsip show endpoint %s", endpoint))
	if err != nil {
		return false, "", err
	}
	
	// Check if endpoint was found
	if strings.Contains(output, "Unable to find object") || 
	   strings.Contains(output, "No objects found") {
		return false, output, nil
	}
	
	return true, output, nil
}

// GetEndpointStatus gets the registration status of an endpoint
func (am *AsteriskManager) GetEndpointStatus(endpoint string) (string, error) {
	exists, output, err := am.VerifyEndpoint(endpoint)
	if err != nil {
		return "error", err
	}
	
	if !exists {
		return "not_found", nil
	}
	
	// Parse the output to determine registration status
	if strings.Contains(output, "Unavailable") {
		return "offline", nil
	} else if strings.Contains(output, "Avail") || strings.Contains(output, "Online") {
		return "registered", nil
	}
	
	return "unknown", nil
}

// ListAllEndpoints gets all PJSIP endpoints
func (am *AsteriskManager) ListAllEndpoints() ([]string, error) {
	output, err := am.ExecuteCLICommand("pjsip show endpoints")
	if err != nil {
		return nil, err
	}
	
	endpoints := []string{}
	lines := strings.Split(output, "\n")
	
	for _, line := range lines {
		line = strings.TrimSpace(line)
		// Skip header and empty lines
		if line == "" || (strings.Contains(line, "Endpoint:") && strings.Contains(line, "State")) {
			continue
		}
		
		// Extract endpoint name (first column)
		parts := strings.Fields(line)
		if len(parts) > 0 {
			endpoints = append(endpoints, parts[0])
		}
	}
	
	return endpoints, nil
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

// GetServiceStatusOutput returns service status as a string (for TUI use)
func (am *AsteriskManager) GetServiceStatusOutput() string {
	var result strings.Builder
	
	result.WriteString("⚙️  Asterisk Service Status\n")
	result.WriteString("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n")

	status, err := am.GetServiceStatus()
	if err != nil {
		result.WriteString(fmt.Sprintf("❌ Error: %v\n", err))
		return result.String()
	}

	switch status {
	case "running":
		result.WriteString("✅ Status: Running\n")
	case "stopped":
		result.WriteString("❌ Status: Stopped\n")
	default:
		result.WriteString("⚠️  Status: Unknown\n")
	}

	result.WriteString("\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n")
	
	return result.String()
}

// StartServiceQuiet starts the Asterisk service without printing to stdout (for TUI use)
// Returns any command output and an error if the operation failed
func (am *AsteriskManager) StartServiceQuiet() (string, error) {
	cmd := exec.Command("systemctl", "start", "asterisk")
	output, err := cmd.CombinedOutput()
	outputStr := strings.TrimSpace(string(output))
	if err != nil {
		if outputStr != "" {
			return outputStr, fmt.Errorf("failed to start service: %v", err)
		}
		return "", fmt.Errorf("failed to start service: %v", err)
	}
	return outputStr, nil
}

// StopServiceQuiet stops the Asterisk service without printing to stdout (for TUI use)
// Returns any command output and an error if the operation failed
func (am *AsteriskManager) StopServiceQuiet() (string, error) {
	cmd := exec.Command("systemctl", "stop", "asterisk")
	output, err := cmd.CombinedOutput()
	outputStr := strings.TrimSpace(string(output))
	if err != nil {
		if outputStr != "" {
			return outputStr, fmt.Errorf("failed to stop service: %v", err)
		}
		return "", fmt.Errorf("failed to stop service: %v", err)
	}
	return outputStr, nil
}

// RestartServiceQuiet restarts the Asterisk service without printing to stdout (for TUI use)
// Returns any command output and an error if the operation failed
func (am *AsteriskManager) RestartServiceQuiet() (string, error) {
	cmd := exec.Command("systemctl", "restart", "asterisk")
	output, err := cmd.CombinedOutput()
	outputStr := strings.TrimSpace(string(output))
	if err != nil {
		if outputStr != "" {
			return outputStr, fmt.Errorf("failed to restart service: %v", err)
		}
		return "", fmt.Errorf("failed to restart service: %v", err)
	}
	return outputStr, nil
}

// ReloadPJSIPQuiet reloads PJSIP configuration without printing to stdout (for TUI use)
// Returns the reload output
func (am *AsteriskManager) ReloadPJSIPQuiet() (string, error) {
	output, err := am.ExecuteCLICommand("module reload res_pjsip.so")
	if err != nil {
		return "", err
	}
	return output, nil
}

// ReloadDialplanQuiet reloads dialplan configuration without printing to stdout (for TUI use)
// Returns the reload output
func (am *AsteriskManager) ReloadDialplanQuiet() (string, error) {
	output, err := am.ExecuteCLICommand("dialplan reload")
	if err != nil {
		return "", err
	}
	return output, nil
}

// ReloadAllQuiet reloads all Asterisk modules without printing to stdout (for TUI use)
// Returns the reload output
func (am *AsteriskManager) ReloadAllQuiet() (string, error) {
	output, err := am.ExecuteCLICommand("core reload")
	if err != nil {
		return "", err
	}
	return output, nil
}
