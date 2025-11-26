package main

import (
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strings"
)

// GrandStream CTI/CSTA phone operation commands
// Based on GrandStream CTI Guide and GXP16xx Administration Guide

// CTI operation commands
const (
	// Call control commands
	CTICmdAcceptCall     = "acceptcall"
	CTICmdRejectCall     = "rejectcall"
	CTICmdEndCall        = "endcall"
	CTICmdHoldCall       = "hold"
	CTICmdResumeCall     = "unhold"
	CTICmdTransferCall   = "transfer"
	CTICmdAttendTransfer = "attended_transfer"
	CTICmdBlindTransfer  = "blind_transfer"
	CTICmdConference     = "conference"
	CTICmdMute           = "mute"
	CTICmdUnmute         = "unmute"

	// Dial commands
	CTICmdDial        = "dial"
	CTICmdRedial      = "redial"
	CTICmdDialDTMF    = "dtmf"
	CTICmdIntercom    = "intercom"
	CTICmdPaging      = "paging"

	// Feature commands
	CTICmdDND         = "dnd"
	CTICmdForward     = "forward"
	CTICmdCallPark    = "park"
	CTICmdCallPickup  = "pickup"
	CTICmdBLF         = "blf"
	CTICmdCallRecordStart = "record_start"
	CTICmdCallRecordStop  = "record_stop"

	// System commands
	CTICmdScreenshot  = "screenshot"
	CTICmdLCDMessage  = "lcd_message"
	CTICmdReboot      = "reboot"
	CTICmdProvision   = "provision"
	CTICmdUpgrade     = "upgrade"
)

// CTICallState represents the current state of a call
type CTICallState struct {
	LineID       int    `json:"line_id"`
	CallID       string `json:"call_id"`
	State        string `json:"state"` // idle, ringing, dialing, connected, hold, conference
	Direction    string `json:"direction"` // inbound, outbound
	RemoteNumber string `json:"remote_number"`
	RemoteName   string `json:"remote_name"`
	Duration     int    `json:"duration"` // seconds
	Muted        bool   `json:"muted"`
}

// CTIPhoneState represents the current state of the phone
type CTIPhoneState struct {
	Calls         []CTICallState `json:"calls"`
	DNDEnabled    bool           `json:"dnd_enabled"`
	ForwardEnabled bool          `json:"forward_enabled"`
	ForwardTarget  string        `json:"forward_target"`
	MWI           bool           `json:"mwi"` // Message Waiting Indicator
	ActiveLine    int            `json:"active_line"`
}

// CTIResponse represents a response from CTI command
type CTIResponse struct {
	Success    bool              `json:"success"`
	StatusCode int               `json:"status_code"`
	Response   string            `json:"response"`
	Body       string            `json:"body"`
	Data       map[string]string `json:"data,omitempty"`
}

// SNMPConfig represents SNMP configuration for monitoring
type SNMPConfig struct {
	Enabled       bool   `json:"enabled"`
	Community     string `json:"community"`
	TrapServer    string `json:"trap_server"`
	TrapPort      int    `json:"trap_port"`
	Version       string `json:"version"` // v1, v2c, v3
	SecurityLevel string `json:"security_level,omitempty"` // noAuthNoPriv, authNoPriv, authPriv (for v3)
	AuthProtocol  string `json:"auth_protocol,omitempty"` // MD5, SHA
	PrivProtocol  string `json:"priv_protocol,omitempty"` // DES, AES
	Username      string `json:"username,omitempty"` // for v3
}

// GrandStreamCTI provides CTI/CSTA operations for GrandStream phones
type GrandStreamCTI struct {
	phone      *GrandStreamPhone
	httpClient *http.Client
}

// NewGrandStreamCTI creates a new CTI interface for a GrandStream phone
func NewGrandStreamCTI(phone *GrandStreamPhone) *GrandStreamCTI {
	return &GrandStreamCTI{
		phone:      phone,
		httpClient: phone.httpClient,
	}
}

// GetPhoneStatus gets detailed phone status including call states
func (cti *GrandStreamCTI) GetPhoneStatus() (*CTIPhoneState, error) {
	resp, err := cti.executeAPIRequest("/cgi-bin/api-get_phone_status", nil)
	if err != nil {
		return nil, fmt.Errorf("failed to get phone status: %w", err)
	}

	state := &CTIPhoneState{}
	if resp.Data != nil {
		// Parse call states from response
		// Format varies by firmware version
		if activeLine, ok := resp.Data["activeline"]; ok {
			fmt.Sscanf(activeLine, "%d", &state.ActiveLine)
		}
		if dnd, ok := resp.Data["dnd"]; ok {
			state.DNDEnabled = dnd == "1" || dnd == "true"
		}
		if forward, ok := resp.Data["forward"]; ok {
			state.ForwardEnabled = forward == "1" || forward == "true"
		}
		if forwardTarget, ok := resp.Data["forward_target"]; ok {
			state.ForwardTarget = forwardTarget
		}
		if mwi, ok := resp.Data["mwi"]; ok {
			state.MWI = mwi == "1" || mwi == "true"
		}
	}

	return state, nil
}

// AcceptCall answers an incoming call on specified line
func (cti *GrandStreamCTI) AcceptCall(lineID int) (*CTIResponse, error) {
	params := map[string]string{
		"cmd": CTICmdAcceptCall,
	}
	if lineID > 0 {
		params["line"] = fmt.Sprintf("%d", lineID)
	}
	return cti.executePhoneOperation(params)
}

// RejectCall rejects an incoming call on specified line
func (cti *GrandStreamCTI) RejectCall(lineID int) (*CTIResponse, error) {
	params := map[string]string{
		"cmd": CTICmdRejectCall,
	}
	if lineID > 0 {
		params["line"] = fmt.Sprintf("%d", lineID)
	}
	return cti.executePhoneOperation(params)
}

// EndCall terminates the current call on specified line
func (cti *GrandStreamCTI) EndCall(lineID int) (*CTIResponse, error) {
	params := map[string]string{
		"cmd": CTICmdEndCall,
	}
	if lineID > 0 {
		params["line"] = fmt.Sprintf("%d", lineID)
	}
	return cti.executePhoneOperation(params)
}

// HoldCall places the current call on hold
func (cti *GrandStreamCTI) HoldCall(lineID int) (*CTIResponse, error) {
	params := map[string]string{
		"cmd": CTICmdHoldCall,
	}
	if lineID > 0 {
		params["line"] = fmt.Sprintf("%d", lineID)
	}
	return cti.executePhoneOperation(params)
}

// ResumeCall resumes a held call
func (cti *GrandStreamCTI) ResumeCall(lineID int) (*CTIResponse, error) {
	params := map[string]string{
		"cmd": CTICmdResumeCall,
	}
	if lineID > 0 {
		params["line"] = fmt.Sprintf("%d", lineID)
	}
	return cti.executePhoneOperation(params)
}

// Dial initiates a call to the specified number
func (cti *GrandStreamCTI) Dial(number string, lineID int) (*CTIResponse, error) {
	params := map[string]string{
		"cmd":    CTICmdDial,
		"number": number,
	}
	if lineID > 0 {
		params["line"] = fmt.Sprintf("%d", lineID)
	}
	return cti.executePhoneOperation(params)
}

// SendDTMF sends DTMF tones during a call
func (cti *GrandStreamCTI) SendDTMF(digits string, lineID int) (*CTIResponse, error) {
	params := map[string]string{
		"cmd":   CTICmdDialDTMF,
		"value": digits,
	}
	if lineID > 0 {
		params["line"] = fmt.Sprintf("%d", lineID)
	}
	return cti.executePhoneOperation(params)
}

// BlindTransfer performs a blind transfer to specified number
func (cti *GrandStreamCTI) BlindTransfer(target string, lineID int) (*CTIResponse, error) {
	params := map[string]string{
		"cmd":    CTICmdBlindTransfer,
		"target": target,
	}
	if lineID > 0 {
		params["line"] = fmt.Sprintf("%d", lineID)
	}
	return cti.executePhoneOperation(params)
}

// AttendedTransfer starts an attended transfer to specified number
func (cti *GrandStreamCTI) AttendedTransfer(target string, lineID int) (*CTIResponse, error) {
	params := map[string]string{
		"cmd":    CTICmdAttendTransfer,
		"target": target,
	}
	if lineID > 0 {
		params["line"] = fmt.Sprintf("%d", lineID)
	}
	return cti.executePhoneOperation(params)
}

// Conference starts a 3-way conference call
func (cti *GrandStreamCTI) Conference(lineID int) (*CTIResponse, error) {
	params := map[string]string{
		"cmd": CTICmdConference,
	}
	if lineID > 0 {
		params["line"] = fmt.Sprintf("%d", lineID)
	}
	return cti.executePhoneOperation(params)
}

// Mute mutes the current call
func (cti *GrandStreamCTI) Mute(lineID int) (*CTIResponse, error) {
	params := map[string]string{
		"cmd": CTICmdMute,
	}
	if lineID > 0 {
		params["line"] = fmt.Sprintf("%d", lineID)
	}
	return cti.executePhoneOperation(params)
}

// Unmute unmutes the current call
func (cti *GrandStreamCTI) Unmute(lineID int) (*CTIResponse, error) {
	params := map[string]string{
		"cmd": CTICmdUnmute,
	}
	if lineID > 0 {
		params["line"] = fmt.Sprintf("%d", lineID)
	}
	return cti.executePhoneOperation(params)
}

// SetDND enables or disables Do Not Disturb
func (cti *GrandStreamCTI) SetDND(enable bool) (*CTIResponse, error) {
	value := "0"
	if enable {
		value = "1"
	}
	params := map[string]string{
		"cmd":   CTICmdDND,
		"value": value,
	}
	return cti.executePhoneOperation(params)
}

// SetForward enables or disables call forwarding
func (cti *GrandStreamCTI) SetForward(enable bool, target string, forwardType string) (*CTIResponse, error) {
	value := "0"
	if enable {
		value = "1"
	}
	params := map[string]string{
		"cmd":   CTICmdForward,
		"value": value,
	}
	if target != "" {
		params["target"] = target
	}
	if forwardType != "" {
		params["type"] = forwardType // unconditional, busy, noanswer
	}
	return cti.executePhoneOperation(params)
}

// Intercom initiates an intercom call
func (cti *GrandStreamCTI) Intercom(number string, lineID int) (*CTIResponse, error) {
	params := map[string]string{
		"cmd":    CTICmdIntercom,
		"number": number,
	}
	if lineID > 0 {
		params["line"] = fmt.Sprintf("%d", lineID)
	}
	return cti.executePhoneOperation(params)
}

// Paging initiates a paging call
func (cti *GrandStreamCTI) Paging(number string, lineID int) (*CTIResponse, error) {
	params := map[string]string{
		"cmd":    CTICmdPaging,
		"number": number,
	}
	if lineID > 0 {
		params["line"] = fmt.Sprintf("%d", lineID)
	}
	return cti.executePhoneOperation(params)
}

// CallPark parks the current call
func (cti *GrandStreamCTI) CallPark(slot string, lineID int) (*CTIResponse, error) {
	params := map[string]string{
		"cmd": CTICmdCallPark,
	}
	if slot != "" {
		params["slot"] = slot
	}
	if lineID > 0 {
		params["line"] = fmt.Sprintf("%d", lineID)
	}
	return cti.executePhoneOperation(params)
}

// CallPickup picks up a ringing call
func (cti *GrandStreamCTI) CallPickup(extension string) (*CTIResponse, error) {
	params := map[string]string{
		"cmd": CTICmdCallPickup,
	}
	if extension != "" {
		params["extension"] = extension
	}
	return cti.executePhoneOperation(params)
}

// TakeScreenshot captures the phone screen (if supported)
func (cti *GrandStreamCTI) TakeScreenshot() ([]byte, error) {
	params := map[string]string{
		"cmd": CTICmdScreenshot,
	}
	resp, err := cti.executePhoneOperation(params)
	if err != nil {
		return nil, err
	}
	return []byte(resp.Body), nil
}

// DisplayLCDMessage shows a message on the phone LCD
func (cti *GrandStreamCTI) DisplayLCDMessage(message string, duration int) (*CTIResponse, error) {
	params := map[string]string{
		"cmd":     CTICmdLCDMessage,
		"message": message,
	}
	if duration > 0 {
		params["duration"] = fmt.Sprintf("%d", duration)
	}
	return cti.executePhoneOperation(params)
}

// TriggerProvision triggers the phone to re-provision
func (cti *GrandStreamCTI) TriggerProvision() (*CTIResponse, error) {
	params := map[string]string{
		"cmd": CTICmdProvision,
	}
	return cti.executePhoneOperation(params)
}

// TriggerUpgrade triggers firmware upgrade
func (cti *GrandStreamCTI) TriggerUpgrade(firmwareURL string) (*CTIResponse, error) {
	params := map[string]string{
		"cmd": CTICmdUpgrade,
	}
	if firmwareURL != "" {
		params["url"] = firmwareURL
	}
	return cti.executePhoneOperation(params)
}

// GetLineStatus gets the status of a specific line/account
func (cti *GrandStreamCTI) GetLineStatus(lineID int) (*CTICallState, error) {
	params := map[string]string{
		"line": fmt.Sprintf("%d", lineID),
	}
	resp, err := cti.executeAPIRequest("/cgi-bin/api-get_line_status", params)
	if err != nil {
		return nil, fmt.Errorf("failed to get line status: %w", err)
	}

	state := &CTICallState{
		LineID: lineID,
	}
	if resp.Data != nil {
		if callState, ok := resp.Data["state"]; ok {
			state.State = callState
		}
		if direction, ok := resp.Data["direction"]; ok {
			state.Direction = direction
		}
		if remote, ok := resp.Data["remote"]; ok {
			state.RemoteNumber = remote
		}
		if name, ok := resp.Data["name"]; ok {
			state.RemoteName = name
		}
		if muted, ok := resp.Data["muted"]; ok {
			state.Muted = muted == "1" || muted == "true"
		}
	}

	return state, nil
}

// GetAccountStatus gets the registration status of a SIP account
func (cti *GrandStreamCTI) GetAccountStatus(accountID int) (map[string]string, error) {
	params := map[string]string{
		"account": fmt.Sprintf("%d", accountID),
	}
	resp, err := cti.executeAPIRequest("/cgi-bin/api-get_account_status", params)
	if err != nil {
		return nil, fmt.Errorf("failed to get account status: %w", err)
	}
	return resp.Data, nil
}

// EnableSNMP enables SNMP monitoring on the phone
func (cti *GrandStreamCTI) EnableSNMP(config *SNMPConfig) error {
	if config == nil {
		return fmt.Errorf("SNMP config is required")
	}

	// GrandStream SNMP parameters
	snmpParams := map[string]interface{}{
		"P1610": boolToString(config.Enabled), // SNMP Enable
	}

	if config.Community != "" {
		snmpParams["P1611"] = config.Community // Community string
	}
	if config.TrapServer != "" {
		snmpParams["P1612"] = config.TrapServer // Trap server
	}
	if config.TrapPort > 0 {
		snmpParams["P1613"] = fmt.Sprintf("%d", config.TrapPort) // Trap port
	}

	// Set SNMP version (0=v1, 1=v2c, 2=v3)
	switch config.Version {
	case "v1":
		snmpParams["P1614"] = "0"
	case "v2c":
		snmpParams["P1614"] = "1"
	case "v3":
		snmpParams["P1614"] = "2"
		if config.Username != "" {
			snmpParams["P1615"] = config.Username
		}
		// Security level: 0=noAuthNoPriv, 1=authNoPriv, 2=authPriv
		switch config.SecurityLevel {
		case "authNoPriv":
			snmpParams["P1616"] = "1"
		case "authPriv":
			snmpParams["P1616"] = "2"
		default:
			snmpParams["P1616"] = "0"
		}
	}

	return cti.phone.SetConfig(snmpParams)
}

// DisableSNMP disables SNMP monitoring
func (cti *GrandStreamCTI) DisableSNMP() error {
	snmpParams := map[string]interface{}{
		"P1610": "0", // SNMP Disable
	}
	return cti.phone.SetConfig(snmpParams)
}

// GetSNMPStatus returns current SNMP configuration
func (cti *GrandStreamCTI) GetSNMPStatus() (*SNMPConfig, error) {
	config, err := cti.phone.GetConfig()
	if err != nil {
		return nil, fmt.Errorf("failed to get phone config: %w", err)
	}

	snmpConfig := &SNMPConfig{}

	if enabled, ok := config["P1610"]; ok {
		snmpConfig.Enabled = enabled == "1" || enabled == 1
	}
	if community, ok := config["P1611"].(string); ok {
		snmpConfig.Community = community
	}
	if server, ok := config["P1612"].(string); ok {
		snmpConfig.TrapServer = server
	}
	if port, ok := config["P1613"]; ok {
		switch v := port.(type) {
		case string:
			fmt.Sscanf(v, "%d", &snmpConfig.TrapPort)
		case float64:
			snmpConfig.TrapPort = int(v)
		case int:
			snmpConfig.TrapPort = v
		}
	}
	if version, ok := config["P1614"]; ok {
		switch v := version.(type) {
		case string:
			switch v {
			case "0":
				snmpConfig.Version = "v1"
			case "1":
				snmpConfig.Version = "v2c"
			case "2":
				snmpConfig.Version = "v3"
			}
		case float64:
			switch int(v) {
			case 0:
				snmpConfig.Version = "v1"
			case 1:
				snmpConfig.Version = "v2c"
			case 2:
				snmpConfig.Version = "v3"
			}
		}
	}

	return snmpConfig, nil
}

// EnableCTI enables CTI functionality on the phone
func (cti *GrandStreamCTI) EnableCTI() error {
	ctiParams := map[string]interface{}{
		"P1650": "1", // Enable CTI
		"P1651": "1", // Allow CTI operations without authentication (local network)
	}
	return cti.phone.SetConfig(ctiParams)
}

// DisableCTI disables CTI functionality
func (cti *GrandStreamCTI) DisableCTI() error {
	ctiParams := map[string]interface{}{
		"P1650": "0", // Disable CTI
	}
	return cti.phone.SetConfig(ctiParams)
}

// TestCTI tests if CTI is working by getting phone status
func (cti *GrandStreamCTI) TestCTI() (bool, error) {
	_, err := cti.GetPhoneStatus()
	return err == nil, err
}

// executePhoneOperation executes a phone operation command
func (cti *GrandStreamCTI) executePhoneOperation(params map[string]string) (*CTIResponse, error) {
	return cti.executeAPIRequest("/cgi-bin/api-phone_operation", params)
}

// executeAPIRequest executes an API request to the phone
func (cti *GrandStreamCTI) executeAPIRequest(endpoint string, params map[string]string) (*CTIResponse, error) {
	// Build URL with query parameters
	apiURL := fmt.Sprintf("http://%s%s", cti.phone.ip, endpoint)
	
	if len(params) > 0 {
		values := url.Values{}
		for k, v := range params {
			values.Set(k, v)
		}
		apiURL += "?" + values.Encode()
	}

	req, err := http.NewRequest("GET", apiURL, nil)
	if err != nil {
		return nil, fmt.Errorf("failed to create request: %w", err)
	}

	// Add authentication if provided
	if username, ok := cti.phone.credentials["username"]; ok {
		if password, ok := cti.phone.credentials["password"]; ok {
			req.SetBasicAuth(username, password)
		}
	}

	resp, err := cti.httpClient.Do(req)
	if err != nil {
		return nil, fmt.Errorf("request failed: %w", err)
	}
	defer resp.Body.Close()

	body, err := io.ReadAll(resp.Body)
	if err != nil {
		return nil, fmt.Errorf("failed to read response: %w", err)
	}

	ctiResp := &CTIResponse{
		Success:    resp.StatusCode == http.StatusOK,
		StatusCode: resp.StatusCode,
		Response:   resp.Status,
		Body:       string(body),
	}

	// Try to parse JSON response
	if strings.Contains(resp.Header.Get("Content-Type"), "application/json") {
		var data map[string]string
		if err := json.Unmarshal(body, &data); err == nil {
			ctiResp.Data = data
		}
	}

	// Try to parse response body as key=value pairs
	if ctiResp.Data == nil {
		ctiResp.Data = parseKeyValueResponse(string(body))
	}

	return ctiResp, nil
}

// parseKeyValueResponse parses response body as key=value pairs
func parseKeyValueResponse(body string) map[string]string {
	result := make(map[string]string)
	lines := strings.Split(body, "\n")
	for _, line := range lines {
		line = strings.TrimSpace(line)
		if line == "" {
			continue
		}
		parts := strings.SplitN(line, "=", 2)
		if len(parts) == 2 {
			result[strings.TrimSpace(parts[0])] = strings.TrimSpace(parts[1])
		}
	}
	return result
}

// boolToString converts bool to "1" or "0"
func boolToString(b bool) string {
	if b {
		return "1"
	}
	return "0"
}

// CTIProvisioningConfig contains CTI and SNMP configuration to be applied during provisioning
type CTIProvisioningConfig struct {
	EnableCTI      bool        `json:"enable_cti"`
	EnableSNMP     bool        `json:"enable_snmp"`
	SNMPConfig     *SNMPConfig `json:"snmp_config,omitempty"`
	EnableActionURLs bool      `json:"enable_action_urls"`
}

// ProvisionCTIFeatures provisions CTI/CSTA and SNMP features on the phone
func (cti *GrandStreamCTI) ProvisionCTIFeatures(config *CTIProvisioningConfig) error {
	if config == nil {
		return fmt.Errorf("provisioning config is required")
	}

	// Enable CTI if requested
	if config.EnableCTI {
		if err := cti.EnableCTI(); err != nil {
			return fmt.Errorf("failed to enable CTI: %w", err)
		}
	}

	// Enable SNMP if requested
	if config.EnableSNMP {
		if config.SNMPConfig == nil {
			// Use default SNMP config
			config.SNMPConfig = &SNMPConfig{
				Enabled:   true,
				Community: "public",
				Version:   "v2c",
			}
		}
		if err := cti.EnableSNMP(config.SNMPConfig); err != nil {
			return fmt.Errorf("failed to enable SNMP: %w", err)
		}
	}

	return nil
}

// TestCTIAndSNMP tests if CTI and SNMP are properly configured
func (cti *GrandStreamCTI) TestCTIAndSNMP() (ctiOK bool, snmpOK bool, err error) {
	// Test CTI
	ctiOK, err = cti.TestCTI()
	if err != nil {
		// CTI test failed but continue to test SNMP
		err = nil
	}

	// Test SNMP by checking if it's enabled
	snmpConfig, snmpErr := cti.GetSNMPStatus()
	if snmpErr == nil && snmpConfig.Enabled {
		snmpOK = true
	}

	return ctiOK, snmpOK, err
}
