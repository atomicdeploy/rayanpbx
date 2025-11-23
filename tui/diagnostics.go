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
	
	fmt.Println("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n")
}
