package main

import (
	"fmt"
	"os"
	"os/exec"
	"regexp"
	"strings"
)

// HelloWorldSetup handles the automated Hello World configuration
type HelloWorldSetup struct {
	configManager   *AsteriskConfigManager
	asteriskManager *AsteriskManager
	verbose         bool
}

// HelloWorldStatus represents the current state of Hello World setup
type HelloWorldStatus struct {
	ExtensionConfigured bool
	DialplanConfigured  bool
	TransportConfigured bool
	AsteriskRunning     bool
	SoundFileExists     bool
}

// NewHelloWorldSetup creates a new Hello World setup handler
func NewHelloWorldSetup(configManager *AsteriskConfigManager, asteriskManager *AsteriskManager, verbose bool) *HelloWorldSetup {
	return &HelloWorldSetup{
		configManager:   configManager,
		asteriskManager: asteriskManager,
		verbose:         verbose,
	}
}

// GetStatus checks the current status of Hello World setup
func (h *HelloWorldSetup) GetStatus() HelloWorldStatus {
	status := HelloWorldStatus{}

	// Check if extension 101 is configured in pjsip.conf
	pjsipContent, err := os.ReadFile("/etc/asterisk/pjsip.conf")
	if err == nil {
		content := string(pjsipContent)
		status.ExtensionConfigured = strings.Contains(content, "; BEGIN MANAGED - RayanPBX Hello World Extension")
		status.TransportConfigured = strings.Contains(content, "[transport-udp]") && strings.Contains(content, "type=transport")
	}

	// Check if Hello World dialplan is configured in extensions.conf
	extContent, err := os.ReadFile("/etc/asterisk/extensions.conf")
	if err == nil {
		content := string(extContent)
		status.DialplanConfigured = strings.Contains(content, "; BEGIN MANAGED - RayanPBX Hello World Dialplan")
	}

	// Check if Asterisk is running
	asteriskStatus, _ := h.asteriskManager.GetServiceStatus()
	status.AsteriskRunning = asteriskStatus == "running"

	// Check if hello-world sound file exists
	soundPaths := []string{
		"/var/lib/asterisk/sounds/en/hello-world.gsm",
		"/var/lib/asterisk/sounds/en/hello-world.wav",
		"/var/lib/asterisk/sounds/en/hello-world.ulaw",
		"/var/lib/asterisk/sounds/en/hello-world.alaw",
		"/usr/share/asterisk/sounds/en/hello-world.gsm",
		"/usr/share/asterisk/sounds/en/hello-world.wav",
	}
	for _, path := range soundPaths {
		if _, err := os.Stat(path); err == nil {
			status.SoundFileExists = true
			break
		}
	}

	return status
}

// GenerateHelloWorldExtension generates the PJSIP config for extension 101
func (h *HelloWorldSetup) GenerateHelloWorldExtension() string {
	var config strings.Builder

	config.WriteString("\n; BEGIN MANAGED - RayanPBX Hello World Extension\n")
	config.WriteString("; Test extension for Hello World setup - can be removed after testing\n\n")

	// Extension 101 endpoint
	config.WriteString("[101]\n")
	config.WriteString("type=endpoint\n")
	config.WriteString("context=from-internal\n")
	config.WriteString("disallow=all\n")
	config.WriteString("allow=ulaw\n")
	config.WriteString("auth=101\n")
	config.WriteString("aors=101\n")
	config.WriteString("\n")

	// Auth section
	config.WriteString("[101]\n")
	config.WriteString("type=auth\n")
	config.WriteString("auth_type=userpass\n")
	config.WriteString("password=101pass\n")
	config.WriteString("username=101\n")
	config.WriteString("\n")

	// AOR section
	config.WriteString("[101]\n")
	config.WriteString("type=aor\n")
	config.WriteString("max_contacts=1\n")

	config.WriteString("\n; END MANAGED - RayanPBX Hello World Extension\n")

	return config.String()
}

// GenerateHelloWorldDialplan generates the dialplan for Hello World
func (h *HelloWorldSetup) GenerateHelloWorldDialplan() string {
	var config strings.Builder

	config.WriteString("\n; BEGIN MANAGED - RayanPBX Hello World Dialplan\n")
	config.WriteString("; Test dialplan for Hello World setup - can be removed after testing\n")
	config.WriteString("[from-internal]\n")
	config.WriteString("exten = 100,1,Answer()\n")
	config.WriteString("same = n,Wait(1)\n")
	config.WriteString("same = n,Playback(hello-world)\n")
	config.WriteString("same = n,Hangup()\n")
	config.WriteString("; END MANAGED - RayanPBX Hello World Dialplan\n")

	return config.String()
}

// GenerateTransportConfig generates the PJSIP transport configuration
func (h *HelloWorldSetup) GenerateTransportConfig() string {
	var config strings.Builder

	config.WriteString("\n; BEGIN MANAGED - RayanPBX Transport\n")
	config.WriteString("[transport-udp]\n")
	config.WriteString("type=transport\n")
	config.WriteString("protocol=udp\n")
	config.WriteString("bind=0.0.0.0\n")
	config.WriteString("; END MANAGED - RayanPBX Transport\n")

	return config.String()
}

// SetupAll performs the complete Hello World setup
func (h *HelloWorldSetup) SetupAll() error {
	// Step 1: Ensure transport configuration exists
	if err := h.ensureTransport(); err != nil {
		return fmt.Errorf("failed to configure transport: %v", err)
	}

	// Step 2: Configure Hello World extension (101)
	if err := h.configureExtension(); err != nil {
		return fmt.Errorf("failed to configure extension: %v", err)
	}

	// Step 3: Configure Hello World dialplan
	if err := h.configureDialplan(); err != nil {
		return fmt.Errorf("failed to configure dialplan: %v", err)
	}

	// Step 4: Start/Restart Asterisk and reload configuration
	if err := h.restartAsterisk(); err != nil {
		return fmt.Errorf("failed to restart Asterisk: %v", err)
	}

	return nil
}

// ensureTransport ensures the UDP transport exists in pjsip.conf
func (h *HelloWorldSetup) ensureTransport() error {
	pjsipPath := "/etc/asterisk/pjsip.conf"

	// Read existing config
	existingContent, err := os.ReadFile(pjsipPath)
	if err != nil {
		if os.IsNotExist(err) {
			// Create new file with transport config
			header := "; RayanPBX PJSIP Configuration\n; Generated by RayanPBX Hello World Setup\n"
			transportConfig := h.GenerateTransportConfig()
			return os.WriteFile(pjsipPath, []byte(header+transportConfig), 0644)
		}
		return fmt.Errorf("failed to read config file: %v", err)
	}

	content := string(existingContent)

	// Check if transport already exists
	if strings.Contains(content, "[transport-udp]") && strings.Contains(content, "type=transport") {
		return nil // Already configured
	}

	// Check if RayanPBX managed transport exists, remove it first
	if strings.Contains(content, "; BEGIN MANAGED - RayanPBX Transport") {
		re := regexp.MustCompile(`(?s); BEGIN MANAGED - RayanPBX Transport.*?; END MANAGED - RayanPBX Transport\n`)
		content = re.ReplaceAllString(content, "")
	}

	// Add transport config at the beginning (after any header comments)
	transportConfig := h.GenerateTransportConfig()

	// Find insertion point after initial comments
	lines := strings.Split(content, "\n")
	insertIdx := 0
	for i, line := range lines {
		trimmed := strings.TrimSpace(line)
		if !strings.HasPrefix(trimmed, ";") && trimmed != "" {
			insertIdx = i
			break
		}
		insertIdx = i + 1
	}

	// Insert transport config
	beforeInsert := strings.Join(lines[:insertIdx], "\n")
	if insertIdx > 0 && !strings.HasSuffix(beforeInsert, "\n\n") {
		beforeInsert += "\n"
	}
	afterInsert := strings.Join(lines[insertIdx:], "\n")
	content = beforeInsert + transportConfig + afterInsert

	return os.WriteFile(pjsipPath, []byte(content), 0644)
}

// configureExtension adds the Hello World extension to pjsip.conf
func (h *HelloWorldSetup) configureExtension() error {
	pjsipPath := "/etc/asterisk/pjsip.conf"

	existingContent, err := os.ReadFile(pjsipPath)
	if err != nil {
		if os.IsNotExist(err) {
			header := "; RayanPBX PJSIP Configuration\n; Generated by RayanPBX Hello World Setup\n"
			existingContent = []byte(header)
		} else {
			return fmt.Errorf("failed to read config file: %v", err)
		}
	}

	content := string(existingContent)

	// Remove old Hello World extension if exists
	if strings.Contains(content, "; BEGIN MANAGED - RayanPBX Hello World Extension") {
		// Use simple string replacement for the managed section
		startMarker := "; BEGIN MANAGED - RayanPBX Hello World Extension"
		endMarker := "; END MANAGED - RayanPBX Hello World Extension\n"
		
		startIdx := strings.Index(content, startMarker)
		endIdx := strings.Index(content, endMarker)
		if startIdx != -1 && endIdx != -1 {
			// Find the newline before the start marker
			prevNewline := strings.LastIndex(content[:startIdx], "\n")
			if prevNewline == -1 {
				prevNewline = 0
			}
			content = content[:prevNewline] + content[endIdx+len(endMarker):]
		}
	}

	// Append new config
	extensionConfig := h.GenerateHelloWorldExtension()
	content += extensionConfig

	return os.WriteFile(pjsipPath, []byte(content), 0644)
}

// configureDialplan adds the Hello World dialplan to extensions.conf
func (h *HelloWorldSetup) configureDialplan() error {
	extPath := "/etc/asterisk/extensions.conf"

	existingContent, err := os.ReadFile(extPath)
	if err != nil {
		if os.IsNotExist(err) {
			header := "; RayanPBX Dialplan Configuration\n; Generated by RayanPBX Hello World Setup\n\n"
			existingContent = []byte(header)
		} else {
			return fmt.Errorf("failed to read dialplan file: %v", err)
		}
	}

	content := string(existingContent)

	// Remove old Hello World dialplan if exists
	if strings.Contains(content, "; BEGIN MANAGED - RayanPBX Hello World Dialplan") {
		startMarker := "; BEGIN MANAGED - RayanPBX Hello World Dialplan"
		endMarker := "; END MANAGED - RayanPBX Hello World Dialplan\n"
		
		startIdx := strings.Index(content, startMarker)
		endIdx := strings.Index(content, endMarker)
		if startIdx != -1 && endIdx != -1 {
			// Find the newline before the start marker
			prevNewline := strings.LastIndex(content[:startIdx], "\n")
			if prevNewline == -1 {
				prevNewline = 0
			}
			content = content[:prevNewline] + content[endIdx+len(endMarker):]
		}
	}

	// Append new dialplan
	dialplanConfig := h.GenerateHelloWorldDialplan()
	content += dialplanConfig

	return os.WriteFile(extPath, []byte(content), 0644)
}

// restartAsterisk starts or restarts Asterisk and reloads configuration
func (h *HelloWorldSetup) restartAsterisk() error {
	// Check if Asterisk is running
	status, _ := h.asteriskManager.GetServiceStatus()

	if status == "running" {
		// Reload PJSIP and dialplan
		if _, err := h.asteriskManager.ExecuteCLICommand("module reload res_pjsip.so"); err != nil {
			// Try full core restart if module reload fails
			if err := h.asteriskManager.RestartServiceQuiet(); err != nil {
				return fmt.Errorf("failed to restart Asterisk: %v", err)
			}
		}
		if _, err := h.asteriskManager.ExecuteCLICommand("dialplan reload"); err != nil {
			return fmt.Errorf("failed to reload dialplan: %v", err)
		}
	} else {
		// Start Asterisk
		if err := h.asteriskManager.StartServiceQuiet(); err != nil {
			return fmt.Errorf("failed to start Asterisk: %v", err)
		}
	}

	return nil
}

// RemoveSetup removes all Hello World configuration
func (h *HelloWorldSetup) RemoveSetup() error {
	// Remove extension from pjsip.conf
	if err := h.removeExtension(); err != nil {
		return fmt.Errorf("failed to remove extension: %v", err)
	}

	// Remove dialplan from extensions.conf
	if err := h.removeDialplan(); err != nil {
		return fmt.Errorf("failed to remove dialplan: %v", err)
	}

	// Reload Asterisk configuration
	status, _ := h.asteriskManager.GetServiceStatus()
	if status == "running" {
		h.asteriskManager.ExecuteCLICommand("module reload res_pjsip.so")
		h.asteriskManager.ExecuteCLICommand("dialplan reload")
	}

	return nil
}

// removeExtension removes the Hello World extension from pjsip.conf
func (h *HelloWorldSetup) removeExtension() error {
	pjsipPath := "/etc/asterisk/pjsip.conf"

	existingContent, err := os.ReadFile(pjsipPath)
	if err != nil {
		if os.IsNotExist(err) {
			return nil // Nothing to remove
		}
		return fmt.Errorf("failed to read config file: %v", err)
	}

	content := string(existingContent)

	// Remove Hello World extension section
	if strings.Contains(content, "; BEGIN MANAGED - RayanPBX Hello World Extension") {
		startMarker := "; BEGIN MANAGED - RayanPBX Hello World Extension"
		endMarker := "; END MANAGED - RayanPBX Hello World Extension\n"
		
		startIdx := strings.Index(content, startMarker)
		endIdx := strings.Index(content, endMarker)
		if startIdx != -1 && endIdx != -1 {
			// Find the newline before the start marker
			prevNewline := strings.LastIndex(content[:startIdx], "\n")
			if prevNewline == -1 {
				prevNewline = 0
			}
			content = content[:prevNewline] + content[endIdx+len(endMarker):]
		}
	}

	return os.WriteFile(pjsipPath, []byte(content), 0644)
}

// removeDialplan removes the Hello World dialplan from extensions.conf
func (h *HelloWorldSetup) removeDialplan() error {
	extPath := "/etc/asterisk/extensions.conf"

	existingContent, err := os.ReadFile(extPath)
	if err != nil {
		if os.IsNotExist(err) {
			return nil // Nothing to remove
		}
		return fmt.Errorf("failed to read dialplan file: %v", err)
	}

	content := string(existingContent)

	// Remove Hello World dialplan section
	if strings.Contains(content, "; BEGIN MANAGED - RayanPBX Hello World Dialplan") {
		startMarker := "; BEGIN MANAGED - RayanPBX Hello World Dialplan"
		endMarker := "; END MANAGED - RayanPBX Hello World Dialplan\n"
		
		startIdx := strings.Index(content, startMarker)
		endIdx := strings.Index(content, endMarker)
		if startIdx != -1 && endIdx != -1 {
			// Find the newline before the start marker
			prevNewline := strings.LastIndex(content[:startIdx], "\n")
			if prevNewline == -1 {
				prevNewline = 0
			}
			content = content[:prevNewline] + content[endIdx+len(endMarker):]
		}
	}

	return os.WriteFile(extPath, []byte(content), 0644)
}

// CheckSoundFile checks if the hello-world sound file exists
func (h *HelloWorldSetup) CheckSoundFile() (bool, string) {
	soundPaths := []string{
		"/var/lib/asterisk/sounds/en/hello-world.gsm",
		"/var/lib/asterisk/sounds/en/hello-world.wav",
		"/var/lib/asterisk/sounds/en/hello-world.ulaw",
		"/var/lib/asterisk/sounds/en/hello-world.alaw",
		"/usr/share/asterisk/sounds/en/hello-world.gsm",
		"/usr/share/asterisk/sounds/en/hello-world.wav",
	}

	for _, path := range soundPaths {
		if _, err := os.Stat(path); err == nil {
			return true, path
		}
	}

	return false, ""
}

// CreateDefaultSoundFile creates a simple hello-world sound file using text2wave if available
func (h *HelloWorldSetup) CreateDefaultSoundFile() error {
	soundDir := "/var/lib/asterisk/sounds/en"
	soundFile := soundDir + "/hello-world.wav"

	// Check if directory exists
	if _, err := os.Stat(soundDir); os.IsNotExist(err) {
		// Try alternate location
		soundDir = "/usr/share/asterisk/sounds/en"
		soundFile = soundDir + "/hello-world.wav"
		if _, err := os.Stat(soundDir); os.IsNotExist(err) {
			return fmt.Errorf("asterisk sounds directory not found")
		}
	}

	// Check if text2wave is available
	_, err := exec.LookPath("text2wave")
	if err != nil {
		// Try using sox to create a simple tone
		_, err := exec.LookPath("sox")
		if err != nil {
			return fmt.Errorf("neither text2wave nor sox found - please install festival or sox to generate sound files")
		}

		// Create a simple tone using sox (a placeholder sound)
		cmd := exec.Command("sox", "-n", "-r", "8000", "-c", "1", soundFile, "synth", "1", "sine", "440")
		if output, err := cmd.CombinedOutput(); err != nil {
			return fmt.Errorf("failed to create sound file with sox: %v - %s", err, string(output))
		}
		return nil
	}

	// Use text2wave to create a proper "Hello World" audio file
	// Using a safer approach without shell interpolation
	echoCmd := exec.Command("echo", "Hello World")
	text2waveCmd := exec.Command("text2wave", "-o", soundFile)
	
	// Create pipe between echo and text2wave
	pipe, err := echoCmd.StdoutPipe()
	if err != nil {
		return fmt.Errorf("failed to create pipe: %v", err)
	}
	text2waveCmd.Stdin = pipe
	
	if err := echoCmd.Start(); err != nil {
		return fmt.Errorf("failed to start echo: %v", err)
	}
	
	if output, err := text2waveCmd.CombinedOutput(); err != nil {
		return fmt.Errorf("failed to create sound file: %v - %s", err, string(output))
	}
	
	echoCmd.Wait()

	return nil
}

// GetSIPCredentials returns the SIP credentials for the Hello World extension
func (h *HelloWorldSetup) GetSIPCredentials() (username, password, server string, port int) {
	username = "101"
	password = "101pass"
	port = 5060

	// Get server IP
	ips := GetLocalIPAddresses()
	if len(ips) > 0 {
		server = ips[0]
	} else {
		server = GetSystemHostname()
	}

	return
}
