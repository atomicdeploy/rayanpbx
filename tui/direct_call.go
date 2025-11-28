package main

import (
	"encoding/json"
	"fmt"
	"os/exec"
	"strings"
	"sync"
	"time"
)

// CallState represents the current state of a call
type CallState string

const (
	CallStateIdle      CallState = "idle"
	CallStateDialing   CallState = "dialing"
	CallStateRinging   CallState = "ringing"
	CallStateAnswered  CallState = "answered"
	CallStateConnected CallState = "connected"
	CallStateHangup    CallState = "hangup"
	CallStateFailed    CallState = "failed"
	CallStateBusy      CallState = "busy"
	CallStateNoAnswer  CallState = "no_answer"
)

// CallMode represents the type of call
type CallMode string

const (
	CallModeAudioFile CallMode = "audio_file"
	CallModeConsole   CallMode = "console"
)

// ConsoleExtension is the default extension for the Asterisk console
const ConsoleExtension = "9999"

// ConsoleChannel is the Asterisk console channel name
const ConsoleChannel = "Console/dsp"

// DirectCallManager handles direct SIP calls from the TUI
type DirectCallManager struct {
	asteriskManager *AsteriskManager
	mutex           sync.RWMutex
	activeCalls     map[string]*CallInfo
	consoleState    *ConsoleState
}

// CallInfo contains information about an active call
type CallInfo struct {
	CallID       string    `json:"call_id"`
	State        CallState `json:"state"`
	Destination  string    `json:"destination"`
	Mode         CallMode  `json:"mode"`
	AudioFile    string    `json:"audio_file,omitempty"`
	CallerID     string    `json:"caller_id"`
	Channel      string    `json:"channel,omitempty"`
	StartedAt    time.Time `json:"started_at"`
	AnsweredAt   time.Time `json:"answered_at,omitempty"`
	EndedAt      time.Time `json:"ended_at,omitempty"`
	ErrorMessage string    `json:"error,omitempty"`
}

// ConsoleState represents the current state of the Asterisk console
type ConsoleState struct {
	State         CallState `json:"state"`
	Channel       string    `json:"channel"`
	RemoteParty   string    `json:"remote_party,omitempty"`
	Direction     string    `json:"direction,omitempty"` // inbound, outbound
	StartedAt     time.Time `json:"started_at,omitempty"`
	CallDuration  int       `json:"call_duration,omitempty"` // in seconds
	DNDEnabled    bool      `json:"dnd_enabled"`
	Muted         bool      `json:"muted"`
}

// CallResult represents the result of a call operation
type CallResult struct {
	Success  bool      `json:"success"`
	CallID   string    `json:"call_id,omitempty"`
	Message  string    `json:"message,omitempty"`
	Error    string    `json:"error,omitempty"`
	Channel  string    `json:"channel,omitempty"`
	State    CallState `json:"state,omitempty"`
}

// NewDirectCallManager creates a new direct call manager
func NewDirectCallManager(asteriskManager *AsteriskManager) *DirectCallManager {
	return &DirectCallManager{
		asteriskManager: asteriskManager,
		activeCalls:     make(map[string]*CallInfo),
		consoleState: &ConsoleState{
			State:   CallStateIdle,
			Channel: ConsoleChannel,
		},
	}
}

// OriginateCall initiates a call to a destination
func (dcm *DirectCallManager) OriginateCall(
	destination string,
	mode CallMode,
	audioFile string,
	callerID string,
	timeout int,
) *CallResult {
	// Generate unique call ID
	callID := fmt.Sprintf("call_%d", time.Now().UnixNano())

	// Validate parameters
	if mode == CallModeAudioFile && audioFile == "" {
		return &CallResult{
			Success: false,
			CallID:  callID,
			Error:   "Audio file path is required for audio_file mode",
		}
	}

	if timeout <= 0 {
		timeout = 30
	}

	// Create call info
	callInfo := &CallInfo{
		CallID:      callID,
		State:       CallStateDialing,
		Destination: destination,
		Mode:        mode,
		AudioFile:   audioFile,
		CallerID:    callerID,
		StartedAt:   time.Now(),
	}

	dcm.mutex.Lock()
	dcm.activeCalls[callID] = callInfo
	dcm.mutex.Unlock()

	// Build the channel string
	channel := dcm.buildChannelString(destination)

	// Build the Asterisk command
	var command string
	if mode == CallModeAudioFile {
		// Play audio file to the called party
		audioPath := dcm.normalizeAudioPath(audioFile)
		command = fmt.Sprintf("channel originate %s application Playback(%s)",
			channel, audioPath)
	} else {
		// Console mode - bridge with console channel
		command = fmt.Sprintf("channel originate %s application Dial(%s,%d)",
			channel, ConsoleChannel, timeout)
	}

	// Execute the command
	output, err := dcm.asteriskManager.ExecuteCLICommand(command)

	if err != nil {
		callInfo.State = CallStateFailed
		callInfo.ErrorMessage = err.Error()
		callInfo.EndedAt = time.Now()

		return &CallResult{
			Success: false,
			CallID:  callID,
			Error:   fmt.Sprintf("Failed to originate call: %v", err),
			State:   CallStateFailed,
		}
	}

	// Check output for errors
	if strings.Contains(strings.ToLower(output), "error") {
		callInfo.State = CallStateFailed
		callInfo.ErrorMessage = output
		callInfo.EndedAt = time.Now()

		return &CallResult{
			Success: false,
			CallID:  callID,
			Error:   output,
			State:   CallStateFailed,
		}
	}

	callInfo.State = CallStateRinging
	callInfo.Channel = channel

	// If console mode, update console state
	if mode == CallModeConsole {
		dcm.consoleState.State = CallStateDialing
		dcm.consoleState.RemoteParty = destination
		dcm.consoleState.Direction = "outbound"
		dcm.consoleState.StartedAt = time.Now()
	}

	return &CallResult{
		Success: true,
		CallID:  callID,
		Message: fmt.Sprintf("Call initiated to %s", destination),
		Channel: channel,
		State:   CallStateRinging,
	}
}

// DialFromConsole dials an extension from the Asterisk console
func (dcm *DirectCallManager) DialFromConsole(extension string, timeout int) *CallResult {
	if timeout <= 0 {
		timeout = 30
	}

	callID := fmt.Sprintf("console_%d", time.Now().UnixNano())

	// Update console state
	dcm.consoleState.State = CallStateDialing
	dcm.consoleState.RemoteParty = extension
	dcm.consoleState.Direction = "outbound"
	dcm.consoleState.StartedAt = time.Now()

	// Build the command
	channel := "PJSIP/" + extension
	command := fmt.Sprintf("channel originate %s application Dial(%s,%d)",
		ConsoleChannel, channel, timeout)

	output, err := dcm.asteriskManager.ExecuteCLICommand(command)

	if err != nil {
		dcm.consoleState.State = CallStateFailed
		return &CallResult{
			Success: false,
			CallID:  callID,
			Error:   fmt.Sprintf("Failed to dial from console: %v", err),
			State:   CallStateFailed,
		}
	}

	if strings.Contains(strings.ToLower(output), "error") {
		dcm.consoleState.State = CallStateFailed
		return &CallResult{
			Success: false,
			CallID:  callID,
			Error:   output,
			State:   CallStateFailed,
		}
	}

	dcm.consoleState.State = CallStateRinging

	return &CallResult{
		Success: true,
		CallID:  callID,
		Message: fmt.Sprintf("Dialing %s from console...", extension),
		Channel: ConsoleChannel,
		State:   CallStateRinging,
	}
}

// AnswerConsole answers an incoming call on the console
func (dcm *DirectCallManager) AnswerConsole() *CallResult {
	output, err := dcm.asteriskManager.ExecuteCLICommand("console answer")

	if err != nil {
		return &CallResult{
			Success: false,
			Error:   fmt.Sprintf("Failed to answer: %v", err),
		}
	}

	dcm.consoleState.State = CallStateConnected
	_ = output // unused but good for debugging

	return &CallResult{
		Success: true,
		Message: "Call answered on console",
		Channel: ConsoleChannel,
		State:   CallStateConnected,
	}
}

// HangupConsole hangs up the current console call
func (dcm *DirectCallManager) HangupConsole() *CallResult {
	output, err := dcm.asteriskManager.ExecuteCLICommand("console hangup")

	if err != nil {
		return &CallResult{
			Success: false,
			Error:   fmt.Sprintf("Failed to hangup: %v", err),
		}
	}

	dcm.consoleState.State = CallStateIdle
	dcm.consoleState.RemoteParty = ""
	dcm.consoleState.Direction = ""
	_ = output

	return &CallResult{
		Success: true,
		Message: "Console call hung up",
		State:   CallStateIdle,
	}
}

// GetConsoleStatus returns the current console state
func (dcm *DirectCallManager) GetConsoleStatus() *ConsoleState {
	// Refresh status from Asterisk
	dcm.refreshConsoleStatus()

	return dcm.consoleState
}

// refreshConsoleStatus updates console state from Asterisk
func (dcm *DirectCallManager) refreshConsoleStatus() {
	output, err := dcm.asteriskManager.ExecuteCLICommand("core show channels")
	if err != nil {
		return
	}

	// Parse console channel state
	lines := strings.Split(output, "\n")
	consoleFound := false

	for _, line := range lines {
		if strings.Contains(line, "Console/dsp") {
			consoleFound = true

			// Parse state from the line
			fields := strings.Fields(line)
			if len(fields) >= 4 {
				stateStr := strings.ToLower(fields[3])
				switch stateStr {
				case "ring":
					dcm.consoleState.State = CallStateRinging
					dcm.consoleState.Direction = "inbound"
				case "ringing":
					dcm.consoleState.State = CallStateDialing
				case "up":
					dcm.consoleState.State = CallStateConnected
				default:
					dcm.consoleState.State = CallState(stateStr)
				}
			}
			break
		}
	}

	if !consoleFound {
		dcm.consoleState.State = CallStateIdle
		dcm.consoleState.RemoteParty = ""
		dcm.consoleState.Direction = ""
	}
}

// GetCallStatus returns the status of a specific call
func (dcm *DirectCallManager) GetCallStatus(callID string) *CallInfo {
	dcm.mutex.RLock()
	defer dcm.mutex.RUnlock()

	if call, exists := dcm.activeCalls[callID]; exists {
		return call
	}
	return nil
}

// ListActiveCalls returns all active calls
func (dcm *DirectCallManager) ListActiveCalls() []*CallInfo {
	dcm.mutex.RLock()
	defer dcm.mutex.RUnlock()

	calls := make([]*CallInfo, 0, len(dcm.activeCalls))
	for _, call := range dcm.activeCalls {
		if call.State != CallStateHangup && call.State != CallStateFailed {
			calls = append(calls, call)
		}
	}
	return calls
}

// HangupCall hangs up a specific call by channel
func (dcm *DirectCallManager) HangupCall(channel string) *CallResult {
	command := fmt.Sprintf("channel request hangup %s", channel)
	_, err := dcm.asteriskManager.ExecuteCLICommand(command)

	if err != nil {
		return &CallResult{
			Success: false,
			Error:   fmt.Sprintf("Failed to hangup: %v", err),
		}
	}

	return &CallResult{
		Success: true,
		Message: "Call hung up",
	}
}

// SendDTMF sends DTMF tones during a call
func (dcm *DirectCallManager) SendDTMF(channel, digits string) *CallResult {
	// Validate DTMF digits
	for _, c := range digits {
		if !strings.ContainsRune("0123456789*#ABCD", c) {
			return &CallResult{
				Success: false,
				Error:   "Invalid DTMF digits",
			}
		}
	}

	command := fmt.Sprintf("channel request dtmf %s %s", channel, digits)
	_, err := dcm.asteriskManager.ExecuteCLICommand(command)

	if err != nil {
		return &CallResult{
			Success: false,
			Error:   fmt.Sprintf("Failed to send DTMF: %v", err),
		}
	}

	return &CallResult{
		Success: true,
		Message: "DTMF sent",
	}
}

// TestCall makes a test call to verify audio is working
func (dcm *DirectCallManager) TestCall(destination string) *CallResult {
	// Use built-in Asterisk test audio
	testAudio := "/var/lib/asterisk/sounds/en/tt-weasels"

	return dcm.OriginateCall(
		destination,
		CallModeAudioFile,
		testAudio,
		"RayanPBX Test",
		20,
	)
}

// CallPhone calls a VoIP phone directly by IP
func (dcm *DirectCallManager) CallPhone(ip string, extension string, mode CallMode, audioFile string) *CallResult {
	destination := ip
	if extension != "" {
		destination = fmt.Sprintf("%s@%s", extension, ip)
	}

	return dcm.OriginateCall(destination, mode, audioFile, "RayanPBX", 30)
}

// ConfigureConsoleEndpoint configures the Asterisk console for use
func (dcm *DirectCallManager) ConfigureConsoleEndpoint() *CallResult {
	// Load console channel module if not loaded
	_, err := dcm.asteriskManager.ExecuteCLICommand("module load chan_console.so")
	if err != nil {
		// Module might already be loaded, which is fine
	}

	// Check if console is available
	output, err := dcm.asteriskManager.ExecuteCLICommand("console show devices")
	if err != nil {
		return &CallResult{
			Success: false,
			Error:   "Console channel not available. Ensure ALSA/OSS sound device is configured.",
		}
	}

	if strings.Contains(output, "No devices") {
		return &CallResult{
			Success: false,
			Error:   "No audio devices found. Check /etc/asterisk/console.conf",
		}
	}

	return &CallResult{
		Success: true,
		Message: fmt.Sprintf("Console configured. Extension: %s, Channel: %s", ConsoleExtension, ConsoleChannel),
	}
}

// GetConsoleDialplanConfig returns the dialplan configuration for the console
func (dcm *DirectCallManager) GetConsoleDialplanConfig() string {
	return fmt.Sprintf(`; Console Extension Configuration
; Add this to your extensions.conf

[from-internal]
; Allow dialing the console extension
exten => %s,1,NoOp(Calling Console/DSP)
 same => n,Dial(Console/dsp,30,r)
 same => n,VoiceMail(%s@default,u)
 same => n,Hangup()

; Intercom mode - auto-answer
exten => *%s,1,NoOp(Intercom to Console)
 same => n,Set(PJSIP_HEADER(add,Alert-Info)=<http://localhost>;answer-after=0)
 same => n,Dial(Console/dsp,30,A(beep))
 same => n,Hangup()
`, ConsoleExtension, ConsoleExtension, ConsoleExtension)
}

// buildChannelString creates an Asterisk channel string from destination
func (dcm *DirectCallManager) buildChannelString(destination string) string {
	// Already a channel string
	if strings.HasPrefix(strings.ToUpper(destination), "PJSIP/") ||
		strings.HasPrefix(strings.ToUpper(destination), "SIP/") ||
		strings.HasPrefix(strings.ToUpper(destination), "IAX2/") ||
		strings.HasPrefix(strings.ToLower(destination), "console/") {
		return destination
	}

	// SIP URI format: sip:user@host
	if strings.HasPrefix(strings.ToLower(destination), "sip:") {
		// Extract user and host
		parts := strings.TrimPrefix(strings.ToLower(destination), "sip:")
		if strings.Contains(parts, "@") {
			idx := strings.Index(parts, "@")
			user := parts[:idx]
			host := parts[idx+1:]
			return fmt.Sprintf("PJSIP/%s@%s", user, host)
		}
	}

	// Extension number (digits only)
	if dcm.isExtensionNumber(destination) {
		return "PJSIP/" + destination
	}

	// IP address with optional port
	if dcm.isIPAddress(destination) {
		return fmt.Sprintf("PJSIP/%s@%s", ConsoleExtension, destination)
	}

	// Default: treat as PJSIP endpoint
	return "PJSIP/" + destination
}

// isExtensionNumber checks if the string is a valid extension number
func (dcm *DirectCallManager) isExtensionNumber(s string) bool {
	for _, c := range s {
		if c < '0' || c > '9' {
			return false
		}
	}
	return len(s) > 0
}

// isIPAddress checks if the string is an IP address
func (dcm *DirectCallManager) isIPAddress(s string) bool {
	// Simple check - contains dots and numbers
	parts := strings.Split(s, ".")
	if len(parts) != 4 {
		return false
	}
	for _, part := range parts {
		// Allow port suffix on last part
		if strings.Contains(part, ":") {
			part = strings.Split(part, ":")[0]
		}
		for _, c := range part {
			if c < '0' || c > '9' {
				return false
			}
		}
	}
	return true
}

// normalizeAudioPath normalizes audio file path for Asterisk
func (dcm *DirectCallManager) normalizeAudioPath(audioFile string) string {
	// Asterisk expects audio files without extension
	extensions := []string{".wav", ".gsm", ".ulaw", ".alaw", ".sln", ".g722"}
	for _, ext := range extensions {
		if strings.HasSuffix(strings.ToLower(audioFile), ext) {
			return audioFile[:len(audioFile)-len(ext)]
		}
	}
	return audioFile
}

// FormatCallStatus formats call state for display
func FormatCallStatus(state CallState) string {
	switch state {
	case CallStateIdle:
		return "📱 Idle"
	case CallStateDialing:
		return "📞 Dialing..."
	case CallStateRinging:
		return "🔔 Ringing..."
	case CallStateAnswered:
		return "📞 Answered"
	case CallStateConnected:
		return "🟢 Connected"
	case CallStateHangup:
		return "📴 Hung up"
	case CallStateFailed:
		return "❌ Failed"
	case CallStateBusy:
		return "🚫 Busy"
	case CallStateNoAnswer:
		return "📵 No Answer"
	default:
		return string(state)
	}
}

// CallPhoneWithPJSUA uses pjsua to make a call with host audio (microphone/speaker)
// This is an alternative method that uses external tools
func (dcm *DirectCallManager) CallPhoneWithPJSUA(
	destination string,
	serverIP string,
	extension string,
	password string,
	duration int,
) *CallResult {
	// Check if pjsua is available
	_, err := exec.LookPath("pjsua")
	if err != nil {
		return &CallResult{
			Success: false,
			Error:   "pjsua not installed. Install with: apt install pjsua",
		}
	}

	// Build pjsua command
	// pjsua --no-vad --id sip:ext@server --registrar sip:server --realm * --username ext --password pwd sip:dest@server
	args := []string{
		"--no-vad",
		fmt.Sprintf("--id=sip:%s@%s", extension, serverIP),
		fmt.Sprintf("--registrar=sip:%s", serverIP),
		"--realm=*",
		fmt.Sprintf("--username=%s", extension),
		fmt.Sprintf("--password=%s", password),
		fmt.Sprintf("sip:%s@%s", destination, serverIP),
	}

	if duration > 0 {
		args = append(args, fmt.Sprintf("--duration=%d", duration))
	}

	callID := fmt.Sprintf("pjsua_%d", time.Now().UnixNano())

	// Start pjsua in background
	cmd := exec.Command("pjsua", args...)

	err = cmd.Start()
	if err != nil {
		return &CallResult{
			Success: false,
			CallID:  callID,
			Error:   fmt.Sprintf("Failed to start pjsua: %v", err),
		}
	}

	return &CallResult{
		Success: true,
		CallID:  callID,
		Message: fmt.Sprintf("Call initiated to %s using pjsua", destination),
		State:   CallStateDialing,
	}
}

// DirectCallOptions contains options for direct calls
type DirectCallOptions struct {
	Destination string   `json:"destination"`
	Mode        CallMode `json:"mode"`
	AudioFile   string   `json:"audio_file,omitempty"`
	CallerID    string   `json:"caller_id,omitempty"`
	Timeout     int      `json:"timeout,omitempty"`
	// For PJSUA mode
	ServerIP  string `json:"server_ip,omitempty"`
	Extension string `json:"extension,omitempty"`
	Password  string `json:"password,omitempty"`
	Duration  int    `json:"duration,omitempty"`
}

// ToJSON converts DirectCallOptions to JSON
func (opts *DirectCallOptions) ToJSON() (string, error) {
	data, err := json.Marshal(opts)
	if err != nil {
		return "", err
	}
	return string(data), nil
}
