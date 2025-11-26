package main

import (
	"fmt"
	"os"
	"os/exec"
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
	pjsipConfig, err := ParseAsteriskConfig("/etc/asterisk/pjsip.conf")
	if err == nil {
		// Check if extension 101 endpoint exists
		status.ExtensionConfigured = pjsipConfig.HasSectionWithType("101", "endpoint")
		status.TransportConfigured = pjsipConfig.HasSectionWithType("transport-udp", "transport")
	}

	// Check if Hello World dialplan is configured in extensions.conf
	extConfig, err := ParseAsteriskConfig("/etc/asterisk/extensions.conf")
	if err == nil {
		// Check if from-internal context exists with the hello-world dialplan
		status.DialplanConfigured = extConfig.HasSection("from-internal")
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

// GenerateHelloWorldExtension generates the PJSIP sections for extension 101
func (h *HelloWorldSetup) GenerateHelloWorldExtension() []*AsteriskSection {
	sections := make([]*AsteriskSection, 0, 3)

	// Extension 101 endpoint
	endpoint := NewAsteriskSection("101", "endpoint")
	endpoint.Comments = []string{"; Test extension for Hello World setup - can be removed after testing"}
	endpoint.SetProperty("type", "endpoint")
	endpoint.SetProperty("context", "from-internal")
	endpoint.SetProperty("disallow", "all")
	endpoint.SetProperty("allow", "ulaw")
	endpoint.SetProperty("auth", "101")
	endpoint.SetProperty("aors", "101")
	sections = append(sections, endpoint)

	// Auth section
	auth := NewAsteriskSection("101", "auth")
	auth.SetProperty("type", "auth")
	auth.SetProperty("auth_type", "userpass")
	auth.SetProperty("password", "101pass")
	auth.SetProperty("username", "101")
	sections = append(sections, auth)

	// AOR section
	aor := NewAsteriskSection("101", "aor")
	aor.SetProperty("type", "aor")
	aor.SetProperty("max_contacts", "1")
	sections = append(sections, aor)

	return sections
}

// GenerateHelloWorldDialplan generates the dialplan for Hello World
func (h *HelloWorldSetup) GenerateHelloWorldDialplan() string {
	var config strings.Builder

	config.WriteString("; Test dialplan for Hello World setup - can be removed after testing\n")
	config.WriteString("[from-internal]\n")
	config.WriteString("exten = 100,1,Answer()\n")
	config.WriteString("same = n,Wait(1)\n")
	config.WriteString("same = n,Playback(hello-world)\n")
	config.WriteString("same = n,Hangup()\n")

	return config.String()
}

// GenerateTransportConfig generates the PJSIP transport sections
func (h *HelloWorldSetup) GenerateTransportConfig() []*AsteriskSection {
	return CreateTransportSections()
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

	// Parse existing config or create new one
	var config *AsteriskConfig
	var err error

	if _, statErr := os.Stat(pjsipPath); os.IsNotExist(statErr) {
		// Create new config with header and transport
		config = &AsteriskConfig{
			HeaderLines: []string{"; RayanPBX PJSIP Configuration", "; Generated by RayanPBX Hello World Setup", ""},
			Sections:    h.GenerateTransportConfig(),
			FilePath:    pjsipPath,
		}
		return config.Save()
	}

	config, err = ParseAsteriskConfig(pjsipPath)
	if err != nil {
		return fmt.Errorf("failed to read config file: %v", err)
	}

	// Check if transport already exists
	if config.HasSectionWithType("transport-udp", "transport") {
		return nil // Already configured
	}

	// Remove old transport sections and add new ones
	config.RemoveSectionsByName("transport-udp")
	config.RemoveSectionsByName("transport-tcp")

	// Prepend transport sections
	transportSections := h.GenerateTransportConfig()
	newSections := make([]*AsteriskSection, 0, len(transportSections)+len(config.Sections))
	newSections = append(newSections, transportSections...)
	newSections = append(newSections, config.Sections...)
	config.Sections = newSections

	return config.Save()
}

// configureExtension adds the Hello World extension to pjsip.conf
func (h *HelloWorldSetup) configureExtension() error {
	pjsipPath := "/etc/asterisk/pjsip.conf"

	// Parse existing config or create new one
	var config *AsteriskConfig
	var err error

	if _, statErr := os.Stat(pjsipPath); os.IsNotExist(statErr) {
		config = &AsteriskConfig{
			HeaderLines: []string{"; RayanPBX PJSIP Configuration", "; Generated by RayanPBX Hello World Setup", ""},
			Sections:    []*AsteriskSection{},
			FilePath:    pjsipPath,
		}
	} else {
		config, err = ParseAsteriskConfig(pjsipPath)
		if err != nil {
			return fmt.Errorf("failed to read config file: %v", err)
		}
	}

	// Remove old extension 101 sections if they exist
	config.RemoveSectionsByName("101")

	// Add new extension sections
	extensionSections := h.GenerateHelloWorldExtension()
	for _, section := range extensionSections {
		config.AddSection(section)
	}

	return config.Save()
}

// configureDialplan adds the Hello World dialplan to extensions.conf
func (h *HelloWorldSetup) configureDialplan() error {
	extPath := "/etc/asterisk/extensions.conf"

	// Parse existing config or create new one
	var config *AsteriskConfig
	var err error

	if _, statErr := os.Stat(extPath); os.IsNotExist(statErr) {
		config = &AsteriskConfig{
			HeaderLines: []string{"; RayanPBX Dialplan Configuration", "; Generated by RayanPBX Hello World Setup", ""},
			Sections:    []*AsteriskSection{},
			FilePath:    extPath,
		}
	} else {
		config, err = ParseAsteriskConfig(extPath)
		if err != nil {
			return fmt.Errorf("failed to read dialplan file: %v", err)
		}
	}

	// Check if from-internal context already exists
	if !config.HasSection("from-internal") {
		// Parse and add the new dialplan content
		dialplanContent := h.GenerateHelloWorldDialplan()
		newConfig, err := ParseAsteriskConfigContent(dialplanContent, "")
		if err != nil {
			return fmt.Errorf("failed to parse dialplan content: %v", err)
		}
		for _, section := range newConfig.Sections {
			config.AddSection(section)
		}
	}

	return config.Save()
}

// restartAsterisk starts or restarts Asterisk and reloads configuration
func (h *HelloWorldSetup) restartAsterisk() error {
	// Check if Asterisk is running
	status, _ := h.asteriskManager.GetServiceStatus()

	if status == "running" {
		// Reload PJSIP and dialplan
		if _, err := h.asteriskManager.ExecuteCLICommand("module reload res_pjsip.so"); err != nil {
			// Try full core restart if module reload fails
			if _, err := h.asteriskManager.RestartServiceQuiet(); err != nil {
				return fmt.Errorf("failed to restart Asterisk: %v", err)
			}
		}
		if _, err := h.asteriskManager.ExecuteCLICommand("dialplan reload"); err != nil {
			return fmt.Errorf("failed to reload dialplan: %v", err)
		}
	} else {
		// Start Asterisk
		if _, err := h.asteriskManager.StartServiceQuiet(); err != nil {
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

	config, err := ParseAsteriskConfig(pjsipPath)
	if err != nil {
		if os.IsNotExist(err) {
			return nil // Nothing to remove
		}
		return fmt.Errorf("failed to read config file: %v", err)
	}

	// Remove all sections with name "101" (endpoint, auth, aor)
	config.RemoveSectionsByName("101")

	return config.Save()
}

// removeDialplan removes the Hello World dialplan from extensions.conf
func (h *HelloWorldSetup) removeDialplan() error {
	extPath := "/etc/asterisk/extensions.conf"

	config, err := ParseAsteriskConfig(extPath)
	if err != nil {
		if os.IsNotExist(err) {
			return nil // Nothing to remove
		}
		return fmt.Errorf("failed to read dialplan file: %v", err)
	}

	// Remove from-internal section
	config.RemoveSectionsByName("from-internal")

	return config.Save()
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
