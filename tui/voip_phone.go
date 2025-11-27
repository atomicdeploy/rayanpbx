package main

import (
	"encoding/json"
	"encoding/xml"
	"fmt"
	"io"
	"net/http"
	"regexp"
	"strings"
	"time"
)

// GrandStream configuration parameter constants
const (
	// Account configuration parameters (replace X with account number 1-6)
	GSParamSIPServer       = "P%d47"  // SIP Server
	GSParamSIPUserID       = "P%d35"  // SIP User ID
	GSParamAuthID          = "P%d36"  // Authenticate ID
	GSParamAuthPassword    = "P%d34"  // Authenticate Password
	GSParamDisplayName     = "P%d3"   // Display Name
	GSParamAccountActive   = "P%d270" // Account Active (1=yes, 0=no)
)

// VoIPPhone represents a generic VoIP phone interface
type VoIPPhone interface {
	// GetStatus retrieves the phone status
	GetStatus() (*PhoneStatus, error)
	
	// Reboot reboots the phone
	Reboot() error
	
	// FactoryReset performs a factory reset
	FactoryReset() error
	
	// GetConfig retrieves the phone configuration
	GetConfig() (map[string]interface{}, error)
	
	// SetConfig sets phone configuration parameters
	SetConfig(config map[string]interface{}) error
	
	// ProvisionExtension provisions an extension on the phone
	ProvisionExtension(ext Extension, accountNumber int) error

	// CTI/CSTA Operations
	// AcceptCall answers an incoming call
	AcceptCall(lineID int) error
	// RejectCall rejects an incoming call
	RejectCall(lineID int) error
	// EndCall terminates the current call
	EndCall(lineID int) error
	// HoldCall places the call on hold
	HoldCall(lineID int) error
	// ResumeCall resumes a held call
	ResumeCall(lineID int) error
	// Dial initiates an outgoing call
	Dial(number string, lineID int) error
	// SendDTMF sends DTMF tones
	SendDTMF(digits string, lineID int) error
	// BlindTransfer performs blind transfer
	BlindTransfer(target string, lineID int) error
	// SetDND enables/disables Do Not Disturb
	SetDND(enable bool) error
	// GetPhoneState returns current phone state including call info
	GetPhoneState() (*CTIPhoneState, error)
}

// PhoneStatus represents the status of a VoIP phone
type PhoneStatus struct {
	IP              string    `json:"ip"`
	MAC             string    `json:"mac"`
	Model           string    `json:"model"`
	Firmware        string    `json:"firmware"`
	Vendor          string    `json:"vendor"`
	Registered      bool      `json:"registered"`
	Uptime          string    `json:"uptime"`
	ActiveCalls     int       `json:"active_calls"`
	Accounts        []Account `json:"accounts"`
	LastUpdate      time.Time `json:"last_update"`
	NetworkInfo     *NetworkInfo `json:"network_info,omitempty"`
}

// Account represents a SIP account on the phone
type Account struct {
	Number      int    `json:"number"`
	Extension   string `json:"extension"`
	Status      string `json:"status"` // Registered, Unregistered, Registering
	Server      string `json:"server"`
	DisplayName string `json:"display_name"`
}

// NetworkInfo represents network configuration
type NetworkInfo struct {
	IP          string `json:"ip"`
	Subnet      string `json:"subnet"`
	Gateway     string `json:"gateway"`
	DNS1        string `json:"dns1"`
	DNS2        string `json:"dns2"`
	MAC         string `json:"mac"`
	DHCP        bool   `json:"dhcp"`
}

// PhoneManager manages VoIP phones
type PhoneManager struct {
	asteriskManager *AsteriskManager
	httpClient      *http.Client
}

// NewPhoneManager creates a new phone manager
func NewPhoneManager(asteriskManager *AsteriskManager) *PhoneManager {
	return &PhoneManager{
		asteriskManager: asteriskManager,
		httpClient: &http.Client{
			Timeout: 10 * time.Second,
		},
	}
}

// GetRegisteredPhones retrieves all registered phones from Asterisk
func (pm *PhoneManager) GetRegisteredPhones() ([]PhoneInfo, error) {
	// Get PJSIP endpoints from Asterisk
	output, err := pm.asteriskManager.ExecuteCLICommand("pjsip show endpoints")
	if err != nil {
		return nil, fmt.Errorf("failed to get endpoints: %w", err)
	}
	
	return pm.parseEndpoints(output)
}

// PhoneInfo contains basic information about a phone
type PhoneInfo struct {
	Extension string
	IP        string
	Status    string
	UserAgent string
	Online    bool
}

// parseEndpoints parses the output of "pjsip show endpoints"
func (pm *PhoneManager) parseEndpoints(output string) ([]PhoneInfo, error) {
	var phones []PhoneInfo
	lines := strings.Split(output, "\n")
	
	for _, line := range lines {
		line = strings.TrimSpace(line)
		// Skip empty lines, headers, and separator lines
		if line == "" || strings.HasPrefix(line, "=") || strings.Contains(line, "Endpoint:") || 
		   strings.Contains(line, "<Endpoint") {
			continue
		}
		
		// Parse endpoint line format: "extension/sip:extension@ip:port"
		fields := strings.Fields(line)
		if len(fields) < 2 {
			continue
		}
		
		extension := fields[0]
		
		// Skip if this is not an endpoint line (e.g., it's an InAuth, Aor, Contact, etc. line)
		if strings.Contains(extension, ":") || len(extension) == 0 {
			continue
		}
		
		status := "Unknown"
		if len(fields) >= 5 {
			status = fields[4]
		}
		
		// Try to extract IP from contact info
		ip := pm.extractIPFromContact(line)
		
		// Only add if we have valid data
		if extension != "" && ip != "" {
			phones = append(phones, PhoneInfo{
				Extension: extension,
				IP:        ip,
				Status:    status,
			})
		}
	}
	
	return phones, nil
}

// extractIPFromContact extracts IP address from contact string
func (pm *PhoneManager) extractIPFromContact(contact string) string {
	// Look for IP pattern in contact string
	parts := strings.Split(contact, "@")
	if len(parts) < 2 {
		return ""
	}
	
	ipPart := strings.Split(parts[1], ":")[0]
	return strings.TrimSpace(ipPart)
}

// CreatePhone creates a phone instance for the given IP and vendor
func (pm *PhoneManager) CreatePhone(ip string, vendor string, credentials map[string]string) (VoIPPhone, error) {
	switch strings.ToLower(vendor) {
	case "grandstream":
		return NewGrandStreamPhone(ip, credentials, pm.httpClient), nil
	default:
		return nil, fmt.Errorf("unsupported vendor: %s", vendor)
	}
}

// DetectPhoneVendor attempts to detect the vendor of a phone at the given IP
func (pm *PhoneManager) DetectPhoneVendor(ip string) (string, error) {
	// Try to get the web interface and check headers/content
	resp, err := pm.httpClient.Get(fmt.Sprintf("http://%s/", ip))
	if err != nil {
		return "", fmt.Errorf("failed to connect to phone: %w", err)
	}
	defer resp.Body.Close()
	
	// Check Server header
	if server := resp.Header.Get("Server"); server != "" {
		if strings.Contains(strings.ToLower(server), "grandstream") {
			return "grandstream", nil
		}
		if strings.Contains(strings.ToLower(server), "yealink") {
			return "yealink", nil
		}
	}
	
	// Read body to check for vendor-specific strings
	body, err := io.ReadAll(resp.Body)
	if err != nil {
		return "", fmt.Errorf("failed to read response: %w", err)
	}
	
	bodyStr := strings.ToLower(string(body))
	if strings.Contains(bodyStr, "grandstream") {
		return "grandstream", nil
	}
	if strings.Contains(bodyStr, "yealink") {
		return "yealink", nil
	}
	
	return "unknown", nil
}

// PhoneVendorAndModel contains detected vendor and model information
type PhoneVendorAndModel struct {
	Vendor string
	Model  string
}

// Pre-compiled regular expressions for model detection (same as phone_discovery.go)
var (
	// GrandStream model patterns: GXP, GRP, GXV, DP, WP, GAC, HT series
	grandstreamModelPattern = regexp.MustCompile(`(?i)\b(gxp|grp|gxv|dp|wp|gac|ht)\d+[a-z0-9]*`)
	// Other vendor model patterns
	yealinkModelPattern   = regexp.MustCompile(`(?i)sip-t\d+[a-z]*`)
	polycomModelPattern   = regexp.MustCompile(`(?i)(soundpoint|vvx\d+[a-z]*)`)
	ciscoModelPattern     = regexp.MustCompile(`(?i)(cp-\d+[a-z]*|spa\d+[a-z]*)`)
	snomModelPattern      = regexp.MustCompile(`(?i)snom\d+[a-z]*`)
	panasonicModelPattern = regexp.MustCompile(`(?i)kx-[\w]+`)
	fanvilModelPattern    = regexp.MustCompile(`(?i)x\d+[a-z]*`)
)

// DetectPhoneVendorAndModel attempts to detect both vendor and model of a phone at the given IP
func (pm *PhoneManager) DetectPhoneVendorAndModel(ip string) (*PhoneVendorAndModel, error) {
	result := &PhoneVendorAndModel{
		Vendor: "unknown",
		Model:  "",
	}

	// Try to get the web interface and check headers/content
	resp, err := pm.httpClient.Get(fmt.Sprintf("http://%s/", ip))
	if err != nil {
		return nil, fmt.Errorf("failed to connect to phone: %w", err)
	}
	defer resp.Body.Close()

	// Read body to check for vendor-specific strings
	body, err := io.ReadAll(resp.Body)
	if err != nil {
		return nil, fmt.Errorf("failed to read response: %w", err)
	}

	bodyStr := string(body)
	bodyStrLower := strings.ToLower(bodyStr)

	// Check Server header first
	if server := resp.Header.Get("Server"); server != "" {
		serverLower := strings.ToLower(server)
		if strings.Contains(serverLower, "grandstream") {
			result.Vendor = "grandstream"
			if match := grandstreamModelPattern.FindString(server); match != "" {
				result.Model = strings.ToUpper(match)
			}
		} else if strings.Contains(serverLower, "yealink") {
			result.Vendor = "yealink"
			if match := yealinkModelPattern.FindString(server); match != "" {
				result.Model = strings.ToUpper(match)
			}
		}
	}

	// Check body content for vendor and model
	// GrandStream
	if result.Model == "" {
		if match := grandstreamModelPattern.FindString(bodyStr); match != "" {
			result.Vendor = "grandstream"
			result.Model = strings.ToUpper(match)
		} else if strings.Contains(bodyStrLower, "grandstream") {
			result.Vendor = "grandstream"
			// Try to find model in body again with case-insensitive search
			if match := grandstreamModelPattern.FindString(bodyStrLower); match != "" {
				result.Model = strings.ToUpper(match)
			}
		}
	}

	// Yealink
	if result.Model == "" && strings.Contains(bodyStrLower, "yealink") {
		result.Vendor = "yealink"
		if match := yealinkModelPattern.FindString(bodyStr); match != "" {
			result.Model = strings.ToUpper(match)
		}
	}

	// Polycom
	if result.Model == "" && strings.Contains(bodyStrLower, "polycom") {
		result.Vendor = "polycom"
		if match := polycomModelPattern.FindString(bodyStr); match != "" {
			result.Model = strings.ToUpper(match)
		}
	}

	// Cisco
	if result.Model == "" && strings.Contains(bodyStrLower, "cisco") {
		result.Vendor = "cisco"
		if match := ciscoModelPattern.FindString(bodyStr); match != "" {
			result.Model = strings.ToUpper(match)
		}
	}

	// Snom
	if result.Model == "" && strings.Contains(bodyStrLower, "snom") {
		result.Vendor = "snom"
		if match := snomModelPattern.FindString(bodyStr); match != "" {
			result.Model = strings.ToUpper(match)
		}
	}

	// Panasonic
	if result.Model == "" && strings.Contains(bodyStrLower, "panasonic") {
		result.Vendor = "panasonic"
		if match := panasonicModelPattern.FindString(bodyStr); match != "" {
			result.Model = strings.ToUpper(match)
		}
	}

	// Fanvil
	if result.Model == "" && strings.Contains(bodyStrLower, "fanvil") {
		result.Vendor = "fanvil"
		if match := fanvilModelPattern.FindString(bodyStr); match != "" {
			result.Model = strings.ToUpper(match)
		}
	}

	return result, nil
}

// GrandStreamPhone implements VoIPPhone for GrandStream devices
type GrandStreamPhone struct {
	ip          string
	credentials map[string]string
	httpClient  *http.Client
}

// NewGrandStreamPhone creates a new GrandStream phone instance
func NewGrandStreamPhone(ip string, credentials map[string]string, httpClient *http.Client) *GrandStreamPhone {
	if httpClient == nil {
		httpClient = &http.Client{Timeout: 10 * time.Second}
	}
	
	return &GrandStreamPhone{
		ip:          ip,
		credentials: credentials,
		httpClient:  httpClient,
	}
}

// GetStatus retrieves the phone status
func (gsp *GrandStreamPhone) GetStatus() (*PhoneStatus, error) {
	// GrandStream phones provide status via web interface
	url := fmt.Sprintf("http://%s/cgi-bin/api-sys_operation", gsp.ip)
	
	req, err := http.NewRequest("GET", url, nil)
	if err != nil {
		return nil, fmt.Errorf("failed to create request: %w", err)
	}
	
	// Add authentication if provided
	if username, ok := gsp.credentials["username"]; ok {
		if password, ok := gsp.credentials["password"]; ok {
			req.SetBasicAuth(username, password)
		}
	}
	
	resp, err := gsp.httpClient.Do(req)
	if err != nil {
		return nil, fmt.Errorf("failed to get status: %w", err)
	}
	defer resp.Body.Close()
	
	if resp.StatusCode != http.StatusOK {
		return nil, fmt.Errorf("unexpected status code: %d", resp.StatusCode)
	}
	
	// Parse response
	status := &PhoneStatus{
		IP:         gsp.ip,
		Vendor:     "GrandStream",
		LastUpdate: time.Now(),
	}
	
	// Try to parse JSON or XML response
	body, err := io.ReadAll(resp.Body)
	if err != nil {
		return nil, fmt.Errorf("failed to read response: %w", err)
	}
	
	// Attempt JSON parsing
	var jsonData map[string]interface{}
	if err := json.Unmarshal(body, &jsonData); err == nil {
		status = gsp.parseJSONStatus(jsonData)
	} else {
		// Attempt XML parsing
		var xmlData map[string]string
		if err := xml.Unmarshal(body, &xmlData); err == nil {
			status = gsp.parseXMLStatus(xmlData)
		}
	}
	
	return status, nil
}

// parseJSONStatus parses JSON status response
func (gsp *GrandStreamPhone) parseJSONStatus(data map[string]interface{}) *PhoneStatus {
	status := &PhoneStatus{
		IP:         gsp.ip,
		Vendor:     "GrandStream",
		LastUpdate: time.Now(),
	}
	
	if model, ok := data["model"].(string); ok {
		status.Model = model
	}
	if firmware, ok := data["firmware"].(string); ok {
		status.Firmware = firmware
	}
	if mac, ok := data["mac"].(string); ok {
		status.MAC = mac
	}
	
	return status
}

// parseXMLStatus parses XML status response
func (gsp *GrandStreamPhone) parseXMLStatus(data map[string]string) *PhoneStatus {
	status := &PhoneStatus{
		IP:         gsp.ip,
		Vendor:     "GrandStream",
		LastUpdate: time.Now(),
	}
	
	if model, ok := data["model"]; ok {
		status.Model = model
	}
	if firmware, ok := data["firmware"]; ok {
		status.Firmware = firmware
	}
	if mac, ok := data["mac"]; ok {
		status.MAC = mac
	}
	
	return status
}

// Reboot reboots the phone
func (gsp *GrandStreamPhone) Reboot() error {
	url := fmt.Sprintf("http://%s/cgi-bin/api-sys_operation?request=reboot", gsp.ip)
	
	req, err := http.NewRequest("POST", url, nil)
	if err != nil {
		return fmt.Errorf("failed to create request: %w", err)
	}
	
	if username, ok := gsp.credentials["username"]; ok {
		if password, ok := gsp.credentials["password"]; ok {
			req.SetBasicAuth(username, password)
		}
	}
	
	resp, err := gsp.httpClient.Do(req)
	if err != nil {
		return fmt.Errorf("failed to reboot phone: %w", err)
	}
	defer resp.Body.Close()
	
	if resp.StatusCode != http.StatusOK && resp.StatusCode != http.StatusAccepted {
		return fmt.Errorf("unexpected status code: %d", resp.StatusCode)
	}
	
	return nil
}

// FactoryReset performs a factory reset
func (gsp *GrandStreamPhone) FactoryReset() error {
	url := fmt.Sprintf("http://%s/cgi-bin/api-sys_operation?request=factory_reset", gsp.ip)
	
	req, err := http.NewRequest("POST", url, nil)
	if err != nil {
		return fmt.Errorf("failed to create request: %w", err)
	}
	
	if username, ok := gsp.credentials["username"]; ok {
		if password, ok := gsp.credentials["password"]; ok {
			req.SetBasicAuth(username, password)
		}
	}
	
	resp, err := gsp.httpClient.Do(req)
	if err != nil {
		return fmt.Errorf("failed to factory reset phone: %w", err)
	}
	defer resp.Body.Close()
	
	if resp.StatusCode != http.StatusOK && resp.StatusCode != http.StatusAccepted {
		return fmt.Errorf("unexpected status code: %d", resp.StatusCode)
	}
	
	return nil
}

// GetConfig retrieves the phone configuration
func (gsp *GrandStreamPhone) GetConfig() (map[string]interface{}, error) {
	url := fmt.Sprintf("http://%s/cgi-bin/api-get_config", gsp.ip)
	
	req, err := http.NewRequest("GET", url, nil)
	if err != nil {
		return nil, fmt.Errorf("failed to create request: %w", err)
	}
	
	if username, ok := gsp.credentials["username"]; ok {
		if password, ok := gsp.credentials["password"]; ok {
			req.SetBasicAuth(username, password)
		}
	}
	
	resp, err := gsp.httpClient.Do(req)
	if err != nil {
		return nil, fmt.Errorf("failed to get config: %w", err)
	}
	defer resp.Body.Close()
	
	var config map[string]interface{}
	if err := json.NewDecoder(resp.Body).Decode(&config); err != nil {
		return nil, fmt.Errorf("failed to decode config: %w", err)
	}
	
	return config, nil
}

// SetConfig sets phone configuration parameters
func (gsp *GrandStreamPhone) SetConfig(config map[string]interface{}) error {
	url := fmt.Sprintf("http://%s/cgi-bin/api-set_config", gsp.ip)
	
	jsonData, err := json.Marshal(config)
	if err != nil {
		return fmt.Errorf("failed to marshal config: %w", err)
	}
	
	req, err := http.NewRequest("POST", url, strings.NewReader(string(jsonData)))
	if err != nil {
		return fmt.Errorf("failed to create request: %w", err)
	}
	
	req.Header.Set("Content-Type", "application/json")
	
	if username, ok := gsp.credentials["username"]; ok {
		if password, ok := gsp.credentials["password"]; ok {
			req.SetBasicAuth(username, password)
		}
	}
	
	resp, err := gsp.httpClient.Do(req)
	if err != nil {
		return fmt.Errorf("failed to set config: %w", err)
	}
	defer resp.Body.Close()
	
	if resp.StatusCode != http.StatusOK && resp.StatusCode != http.StatusAccepted {
		return fmt.Errorf("unexpected status code: %d", resp.StatusCode)
	}
	
	return nil
}

// ProvisionExtension provisions an extension on the phone
func (gsp *GrandStreamPhone) ProvisionExtension(ext Extension, accountNumber int) error {
	config := map[string]interface{}{
		fmt.Sprintf(GSParamSIPServer, accountNumber):     ext.Transport,
		fmt.Sprintf(GSParamSIPUserID, accountNumber):     ext.ExtensionNumber,
		fmt.Sprintf(GSParamAuthID, accountNumber):        ext.ExtensionNumber,
		fmt.Sprintf(GSParamAuthPassword, accountNumber):  ext.Secret,
		fmt.Sprintf(GSParamDisplayName, accountNumber):   ext.Name,
		fmt.Sprintf(GSParamAccountActive, accountNumber): "1",
	}
	
	return gsp.SetConfig(config)
}

// CTI/CSTA Operations - GrandStream Implementation

// getCTI returns the CTI interface for this phone
func (gsp *GrandStreamPhone) getCTI() *GrandStreamCTI {
	return NewGrandStreamCTI(gsp)
}

// AcceptCall answers an incoming call
func (gsp *GrandStreamPhone) AcceptCall(lineID int) error {
	cti := gsp.getCTI()
	_, err := cti.AcceptCall(lineID)
	return err
}

// RejectCall rejects an incoming call
func (gsp *GrandStreamPhone) RejectCall(lineID int) error {
	cti := gsp.getCTI()
	_, err := cti.RejectCall(lineID)
	return err
}

// EndCall terminates the current call
func (gsp *GrandStreamPhone) EndCall(lineID int) error {
	cti := gsp.getCTI()
	_, err := cti.EndCall(lineID)
	return err
}

// HoldCall places the call on hold
func (gsp *GrandStreamPhone) HoldCall(lineID int) error {
	cti := gsp.getCTI()
	_, err := cti.HoldCall(lineID)
	return err
}

// ResumeCall resumes a held call
func (gsp *GrandStreamPhone) ResumeCall(lineID int) error {
	cti := gsp.getCTI()
	_, err := cti.ResumeCall(lineID)
	return err
}

// Dial initiates an outgoing call
func (gsp *GrandStreamPhone) Dial(number string, lineID int) error {
	cti := gsp.getCTI()
	_, err := cti.Dial(number, lineID)
	return err
}

// SendDTMF sends DTMF tones
func (gsp *GrandStreamPhone) SendDTMF(digits string, lineID int) error {
	cti := gsp.getCTI()
	_, err := cti.SendDTMF(digits, lineID)
	return err
}

// BlindTransfer performs blind transfer
func (gsp *GrandStreamPhone) BlindTransfer(target string, lineID int) error {
	cti := gsp.getCTI()
	_, err := cti.BlindTransfer(target, lineID)
	return err
}

// SetDND enables/disables Do Not Disturb
func (gsp *GrandStreamPhone) SetDND(enable bool) error {
	cti := gsp.getCTI()
	_, err := cti.SetDND(enable)
	return err
}

// GetPhoneState returns current phone state including call info
func (gsp *GrandStreamPhone) GetPhoneState() (*CTIPhoneState, error) {
	cti := gsp.getCTI()
	return cti.GetPhoneStatus()
}

// EnableCTIFeatures enables CTI/CSTA and optionally SNMP on the phone
func (gsp *GrandStreamPhone) EnableCTIFeatures(enableSNMP bool, snmpConfig *SNMPConfig) error {
	cti := gsp.getCTI()
	config := &CTIProvisioningConfig{
		EnableCTI:  true,
		EnableSNMP: enableSNMP,
		SNMPConfig: snmpConfig,
	}
	return cti.ProvisionCTIFeatures(config)
}

// TestCTIFeatures tests if CTI and SNMP are working
func (gsp *GrandStreamPhone) TestCTIFeatures() (ctiOK bool, snmpOK bool, err error) {
	cti := gsp.getCTI()
	return cti.TestCTIAndSNMP()
}
