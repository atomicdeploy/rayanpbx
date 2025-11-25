package main

import (
	"fmt"
	"os/exec"
	"strings"

	"github.com/fatih/color"
)

// DiagnosticsManager handles diagnostics and debugging operations
type DiagnosticsManager struct {
	asterisk *AsteriskManager
}

// NewDiagnosticsManager creates a new diagnostics manager
func NewDiagnosticsManager(asterisk *AsteriskManager) *DiagnosticsManager {
	return &DiagnosticsManager{
		asterisk: asterisk,
	}
}

// EnableSIPDebug enables SIP debugging in Asterisk
func (dm *DiagnosticsManager) EnableSIPDebug() error {
	cyan := color.New(color.FgCyan)
	green := color.New(color.FgGreen)

	cyan.Println("🔍 Enabling SIP debugging...")
	_, err := dm.asterisk.ExecuteCLICommand("pjsip set logger on")
	if err != nil {
		return fmt.Errorf("failed to enable SIP debug: %v", err)
	}

	green.Println("✅ SIP debugging enabled")
	green.Println("💡 View live SIP messages with: asterisk -rx 'pjsip set logger on'")
	return nil
}

// EnableSIPDebugQuiet enables SIP debugging without printing to stdout (for TUI use)
// Returns the output from Asterisk for display in TUI
func (dm *DiagnosticsManager) EnableSIPDebugQuiet() (string, error) {
	output, err := dm.asterisk.ExecuteCLICommand("pjsip set logger on")
	if err != nil {
		return "", fmt.Errorf("failed to enable SIP debug: %v", err)
	}
	return output, nil
}

// DisableSIPDebug disables SIP debugging
func (dm *DiagnosticsManager) DisableSIPDebug() error {
	cyan := color.New(color.FgCyan)
	green := color.New(color.FgGreen)

	cyan.Println("🔍 Disabling SIP debugging...")
	_, err := dm.asterisk.ExecuteCLICommand("pjsip set logger off")
	if err != nil {
		return fmt.Errorf("failed to disable SIP debug: %v", err)
	}

	green.Println("✅ SIP debugging disabled")
	return nil
}

// DisableSIPDebugQuiet disables SIP debugging without printing to stdout (for TUI use)
// Returns the output from Asterisk for display in TUI
func (dm *DiagnosticsManager) DisableSIPDebugQuiet() (string, error) {
	output, err := dm.asterisk.ExecuteCLICommand("pjsip set logger off")
	if err != nil {
		return "", fmt.Errorf("failed to disable SIP debug: %v", err)
	}
	return output, nil
}

// TestExtensionRegistration tests if an extension is registered
func (dm *DiagnosticsManager) TestExtensionRegistration(extension string) error {
	cyan := color.New(color.FgCyan)
	green := color.New(color.FgGreen)
	red := color.New(color.FgRed)
	yellow := color.New(color.FgYellow)

	cyan.Printf("🔍 Testing registration for extension %s...\n", extension)

	// Check endpoint status
	output, err := dm.asterisk.ExecuteCLICommand(fmt.Sprintf("pjsip show endpoint %s", extension))
	if err != nil {
		red.Printf("❌ Error: %v\n", err)
		return err
	}

	if strings.Contains(output, "Unavailable") || strings.Contains(output, "Not found") {
		red.Printf("❌ Extension %s is not registered\n", extension)
		yellow.Println("💡 Tip: Check if the extension is configured correctly")
		return fmt.Errorf("extension not registered")
	}

	green.Printf("✅ Extension %s is registered\n", extension)

	// Show contact info
	if strings.Contains(output, "Contact:") {
		lines := strings.Split(output, "\n")
		for _, line := range lines {
			if strings.Contains(line, "Contact:") || strings.Contains(line, "Status:") {
				fmt.Println("  ", line)
			}
		}
	}

	return nil
}

// TestTrunkConnectivity tests trunk connectivity
func (dm *DiagnosticsManager) TestTrunkConnectivity(trunkName string) error {
	cyan := color.New(color.FgCyan)
	green := color.New(color.FgGreen)
	red := color.New(color.FgRed)
	yellow := color.New(color.FgYellow)

	cyan.Printf("🔍 Testing connectivity for trunk %s...\n", trunkName)

	// Check endpoint status
	output, err := dm.asterisk.ExecuteCLICommand(fmt.Sprintf("pjsip show endpoint %s", trunkName))
	if err != nil {
		red.Printf("❌ Error: %v\n", err)
		return err
	}

	if strings.Contains(output, "Not found") {
		red.Printf("❌ Trunk %s not found\n", trunkName)
		return fmt.Errorf("trunk not found")
	}

	// Check qualify status
	if strings.Contains(output, "Unavailable") {
		red.Printf("❌ Trunk %s is unreachable\n", trunkName)
		yellow.Println("💡 Tip: Check network connectivity and trunk credentials")
		return fmt.Errorf("trunk unreachable")
	}

	green.Printf("✅ Trunk %s is reachable\n", trunkName)

	// Show qualify result if available
	if strings.Contains(output, "RTT:") || strings.Contains(output, "qualify") {
		lines := strings.Split(output, "\n")
		for _, line := range lines {
			if strings.Contains(line, "RTT:") || strings.Contains(line, "qualify") {
				fmt.Println("  ", line)
			}
		}
	}

	return nil
}

// TestCallRouting tests call routing for a specific number
func (dm *DiagnosticsManager) TestCallRouting(from, to string) error {
	cyan := color.New(color.FgCyan)
	green := color.New(color.FgGreen)
	red := color.New(color.FgRed)

	cyan.Printf("🔍 Testing call routing: %s → %s...\n", from, to)

	// Show dialplan matching
	output, err := dm.asterisk.ExecuteCLICommand(fmt.Sprintf("dialplan show %s@from-internal", to))
	if err != nil {
		red.Printf("❌ Error: %v\n", err)
		return err
	}

	if strings.Contains(output, "No such context") {
		red.Println("❌ No routing found for this number")
		return fmt.Errorf("no routing found")
	}

	green.Println("✅ Routing found:")
	fmt.Println(output)

	return nil
}

// CheckPortConnectivity checks if a port is open
func (dm *DiagnosticsManager) CheckPortConnectivity(host string, port int) error {
	cyan := color.New(color.FgCyan)
	green := color.New(color.FgGreen)
	red := color.New(color.FgRed)

	cyan.Printf("🔍 Testing connectivity to %s:%d...\n", host, port)

	// Use netcat or telnet to test port
	cmd := exec.Command("timeout", "3", "bash", "-c", fmt.Sprintf("echo > /dev/tcp/%s/%d", host, port))
	err := cmd.Run()

	if err != nil {
		red.Printf("❌ Port %d on %s is not accessible\n", port, host)
		return err
	}

	green.Printf("✅ Port %d on %s is accessible\n", port, host)
	return nil
}

// StartPacketCapture starts capturing SIP/RTP packets
func (dm *DiagnosticsManager) StartPacketCapture(iface string) error {
	cyan := color.New(color.FgCyan)
	yellow := color.New(color.FgYellow)

	cyan.Println("📡 Starting packet capture...")
	yellow.Printf("💡 Capturing on interface: %s\n", iface)
	yellow.Println("💡 Filter: UDP port 5060 (SIP) and RTP ports")
	yellow.Println("💡 Output: /tmp/rayanpbx-capture.pcap")
	yellow.Println("💡 Stop capture with: sudo pkill tcpdump")

	// This would start tcpdump in background
	fmt.Println("\nCommand to run manually:")
	fmt.Printf("sudo tcpdump -i %s -w /tmp/rayanpbx-capture.pcap 'udp port 5060 or (udp portrange 10000-20000)'\n", iface)

	return nil
}

// GetSystemInfo retrieves system information
func (dm *DiagnosticsManager) GetSystemInfo() string {
	cyan := color.New(color.FgCyan, color.Bold)

	var info strings.Builder

	cyan.Fprintln(&info, "\n💻 System Information:")
	info.WriteString("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n")

	// Asterisk version
	if output, err := dm.asterisk.ExecuteCLICommand("core show version"); err == nil {
		info.WriteString(fmt.Sprintf("Asterisk: %s\n", strings.TrimSpace(output)))
	}

	// Uptime
	if output, err := dm.asterisk.ExecuteCLICommand("core show uptime"); err == nil {
		lines := strings.Split(output, "\n")
		for _, line := range lines {
			if strings.Contains(line, "System uptime:") || strings.Contains(line, "Last reload:") {
				info.WriteString(fmt.Sprintf("%s\n", strings.TrimSpace(line)))
			}
		}
	}

	info.WriteString("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n")

	return info.String()
}

// RunHealthCheck runs comprehensive health checks
func (dm *DiagnosticsManager) RunHealthCheck() {
	cyan := color.New(color.FgCyan, color.Bold)
	green := color.New(color.FgGreen)
	red := color.New(color.FgRed)
	yellow := color.New(color.FgYellow)

	cyan.Println("\n🏥 Running Health Check...")
	fmt.Println("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━")

	// Check Asterisk service
	fmt.Print("Asterisk Service: ")
	status, err := dm.asterisk.GetServiceStatus()
	if err == nil && status == "running" {
		green.Println("✅ Running")
	} else {
		red.Println("❌ Not Running")
		// Show errors if service is not running
		dm.ShowAsteriskErrors()
	}

	// Check PJSIP endpoints
	fmt.Print("PJSIP Endpoints: ")
	if output, err := dm.asterisk.ExecuteCLICommand("pjsip show endpoints"); err == nil {
		if !strings.Contains(output, "No objects found") {
			green.Println("✅ Configured")
		} else {
			yellow.Println("⚠️  None configured")
		}
	} else {
		red.Println("❌ Error checking")
	}

	// Check active channels
	fmt.Print("Active Channels: ")
	if output, err := dm.asterisk.ExecuteCLICommand("core show channels count"); err == nil {
		green.Printf("✅ %s", strings.TrimSpace(output))
	} else {
		red.Println("❌ Error checking")
	}

	fmt.Println("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━")
	fmt.Println()
}

// GetHealthCheckOutput returns health check results as a string (for TUI use)
func (dm *DiagnosticsManager) GetHealthCheckOutput() string {
	var result strings.Builder
	
	result.WriteString("🏥 Health Check Results\n")
	result.WriteString("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n")

	// Check Asterisk service
	status, err := dm.asterisk.GetServiceStatus()
	if err == nil && status == "running" {
		result.WriteString("Asterisk Service: ✅ Running\n")
	} else {
		result.WriteString("Asterisk Service: ❌ Not Running\n")
		// Get error summary
		errors, _ := dm.GetAsteriskErrorsSummary()
		if len(errors) > 0 {
			result.WriteString("\nRecent Errors:\n")
			for i, errMsg := range errors {
				if i >= 5 { // Limit to 5 errors
					break
				}
				result.WriteString(fmt.Sprintf("  • %s\n", errMsg))
			}
		}
	}

	// Check PJSIP endpoints
	if output, err := dm.asterisk.ExecuteCLICommand("pjsip show endpoints"); err == nil {
		if !strings.Contains(output, "No objects found") {
			result.WriteString("PJSIP Endpoints: ✅ Configured\n")
		} else {
			result.WriteString("PJSIP Endpoints: ⚠️  None configured\n")
		}
	} else {
		result.WriteString("PJSIP Endpoints: ❌ Error checking\n")
	}

	// Check active channels
	if output, err := dm.asterisk.ExecuteCLICommand("core show channels count"); err == nil {
		result.WriteString(fmt.Sprintf("Active Channels: %s\n", strings.TrimSpace(output)))
	} else {
		result.WriteString("Active Channels: ❌ Error checking\n")
	}

	result.WriteString("\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n")

	return result.String()
}

// ShowAsteriskErrors displays Asterisk service errors
func (dm *DiagnosticsManager) ShowAsteriskErrors() {
	red := color.New(color.FgRed, color.Bold)
	yellow := color.New(color.FgYellow)
	cyan := color.New(color.FgCyan)

	red.Println("\n❌ Asterisk Service Errors:")
	fmt.Println("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━")

	// Check the persistent error log file
	errorLogFile := "/var/log/rayanpbx/asterisk-errors.log"
	if output, err := exec.Command("tail", "-n", "30", errorLogFile).Output(); err == nil && len(output) > 0 {
		cyan.Println("📋 Recent errors from log file:")
		fmt.Println(string(output))
	}

	// Get current journal errors
	if output, err := exec.Command("journalctl", "-u", "asterisk", "-n", "20", "--no-pager").Output(); err == nil {
		journalOutput := string(output)
		// Filter for errors and warnings
		lines := strings.Split(journalOutput, "\n")
		hasErrors := false
		for _, line := range lines {
			lineLower := strings.ToLower(line)
			if strings.Contains(lineLower, "error") || strings.Contains(lineLower, "fail") || strings.Contains(lineLower, "warning") {
				if !hasErrors {
					cyan.Println("\n📋 Recent journal entries:")
					hasErrors = true
				}
				if strings.Contains(lineLower, "error") || strings.Contains(lineLower, "fail") {
					red.Println("  " + line)
				} else {
					yellow.Println("  " + line)
				}
			}
		}
		if !hasErrors {
			fmt.Println("  No specific error messages found in recent logs")
		}
	}

	// Check systemctl status for more details
	if output, err := exec.Command("systemctl", "status", "asterisk", "--no-pager").Output(); err != nil {
		// Error means service is not running, show the output
		if len(output) > 0 {
			cyan.Println("\n📋 Service status:")
			fmt.Println(string(output))
		}
	}

	// Check for common issues
	cyan.Println("\n💡 Common fixes:")
	yellow.Println("  1. Check radiusclient config: ls -la /etc/radiusclient-ng/")
	yellow.Println("  2. View detailed logs: journalctl -u asterisk -f")
	yellow.Println("  3. Start in verbose mode: asterisk -vvvvvc")
	yellow.Println("  4. Check Asterisk config: asterisk -rx 'core show settings'")
	
	fmt.Println("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━")
}

// GetAsteriskErrorsSummary returns a summary of Asterisk errors for display
func (dm *DiagnosticsManager) GetAsteriskErrorsSummary() ([]string, error) {
	var errors []string

	// Check the persistent error log file
	errorLogFile := "/var/log/rayanpbx/asterisk-errors.log"
	if output, err := exec.Command("tail", "-n", "10", errorLogFile).Output(); err == nil && len(output) > 0 {
		lines := strings.Split(string(output), "\n")
		for _, line := range lines {
			line = strings.TrimSpace(line)
			if line != "" && !strings.HasPrefix(line, "===") {
				errors = append(errors, line)
			}
		}
	}

	// Get current journal errors
	if output, err := exec.Command("journalctl", "-u", "asterisk", "-n", "10", "--no-pager").Output(); err == nil {
		lines := strings.Split(string(output), "\n")
		for _, line := range lines {
			lineLower := strings.ToLower(line)
			if strings.Contains(lineLower, "error") || strings.Contains(lineLower, "fail") {
				errors = append(errors, strings.TrimSpace(line))
			}
		}
	}

	return errors, nil
}

// GetSystemHostname returns the system hostname
func GetSystemHostname() string {
	cmd := exec.Command("hostname", "-f")
	output, err := cmd.Output()
	if err != nil {
		// Fallback to simple hostname
		cmd = exec.Command("hostname")
		output, err = cmd.Output()
		if err != nil {
			return "localhost"
		}
	}
	return strings.TrimSpace(string(output))
}

// GetLocalIPAddresses returns all local IP addresses
func GetLocalIPAddresses() []string {
	var ips []string
	
	// Try to get IP addresses using hostname -I
	cmd := exec.Command("hostname", "-I")
	output, err := cmd.Output()
	if err == nil {
		parts := strings.Fields(string(output))
		for _, ip := range parts {
			ip = strings.TrimSpace(ip)
			if ip != "" && !strings.HasPrefix(ip, "127.") {
				ips = append(ips, ip)
			}
		}
	}
	
	// Fallback: Try ip addr command
	if len(ips) == 0 {
		cmd = exec.Command("ip", "-4", "addr", "show")
		output, err = cmd.Output()
		if err == nil {
			lines := strings.Split(string(output), "\n")
			for _, line := range lines {
				if strings.Contains(line, "inet ") && !strings.Contains(line, "127.0.0.1") {
					parts := strings.Fields(line)
					for i, part := range parts {
						if part == "inet" && i+1 < len(parts) {
							ip := strings.Split(parts[i+1], "/")[0]
							ips = append(ips, ip)
						}
					}
				}
			}
		}
	}
	
	if len(ips) == 0 {
		return []string{"127.0.0.1"}
	}
	return ips
}

// GetEnabledCodecs queries Asterisk for enabled codecs
func (dm *DiagnosticsManager) GetEnabledCodecs() ([]string, error) {
	output, err := dm.asterisk.ExecuteCLICommand("pjsip show endpoints")
	if err != nil {
		// Fallback to showing common codecs
		return []string{"ulaw", "alaw", "g722"}, nil
	}
	
	// Try to parse codecs from endpoint output
	codecs := make(map[string]bool)
	lines := strings.Split(output, "\n")
	for _, line := range lines {
		lineLower := strings.ToLower(line)
		// Check for common codec names
		codecNames := []string{"ulaw", "alaw", "g722", "g729", "opus", "speex", "gsm", "ilbc"}
		for _, codec := range codecNames {
			if strings.Contains(lineLower, codec) {
				codecs[codec] = true
			}
		}
	}
	
	// If no codecs found, try core show codecs
	if len(codecs) == 0 {
		output, err = dm.asterisk.ExecuteCLICommand("core show codecs")
		if err == nil {
			lines := strings.Split(output, "\n")
			for _, line := range lines {
				parts := strings.Fields(line)
				if len(parts) >= 2 {
					codec := strings.ToLower(parts[0])
					if codec != "codec" && codec != "----" && len(codec) > 0 && len(codec) < 10 {
						codecs[codec] = true
					}
				}
			}
		}
	}
	
	// Convert map to slice
	var result []string
	for codec := range codecs {
		result = append(result, codec)
	}
	
	if len(result) == 0 {
		return []string{"ulaw", "alaw", "g722"}, nil
	}
	return result, nil
}

// GetCodecDescription returns a description for a codec
func GetCodecDescription(codec string) string {
	descriptions := map[string]string{
		"ulaw":  "G.711μ - Standard US codec, 64kbps, 8kHz",
		"alaw":  "G.711a - Standard EU codec, 64kbps, 8kHz",
		"g722":  "G.722 - HD audio codec, 64kbps, 16kHz",
		"g729":  "G.729 - Low bandwidth codec, 8kbps (licensed)",
		"opus":  "Opus - Modern codec, variable bitrate, wideband",
		"speex": "Speex - Open source, variable bitrate",
		"gsm":   "GSM - Low bandwidth, 13kbps",
		"ilbc":  "iLBC - Internet Low Bitrate Codec, 15.2kbps",
	}
	if desc, ok := descriptions[strings.ToLower(codec)]; ok {
		return desc
	}
	return codec + " - Audio codec"
}
