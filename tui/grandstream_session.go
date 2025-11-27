package main

import (
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strings"
	"sync"
	"time"
)

// Version constants for User-Agent
const (
	AppName    = "RayanPBX"
	AppVersion = "2.0.0"
)

// GetUserAgent returns the User-Agent string for HTTP requests
func GetUserAgent() string {
	return fmt.Sprintf("%s/%s (Go TUI)", AppName, AppVersion)
}

// buildBaseURL constructs the base URL for a phone IP, supporting both http and https
// If the IP already includes a scheme, it uses that; otherwise defaults to http
func buildBaseURL(ip string) string {
	if strings.HasPrefix(ip, "http://") || strings.HasPrefix(ip, "https://") {
		return strings.TrimSuffix(ip, "/")
	}
	return "http://" + ip
}

// GrandStreamSession represents an authenticated session with a GrandStream phone
type GrandStreamSession struct {
	PhoneIP         string            `json:"phone_ip"`
	Username        string            `json:"username"`
	SessionID       string            `json:"session_id,omitempty"` // The sid from login response
	Role            string            `json:"role,omitempty"`       // admin, user, etc.
	Cookies         map[string]string `json:"cookies,omitempty"`    // session-identity, session-role
	Method          string            `json:"method"`               // dologin
	IsActive        bool              `json:"is_active"`
	AuthenticatedAt time.Time         `json:"authenticated_at"`
	ExpiresAt       time.Time         `json:"expires_at"`
	LastUsedAt      time.Time         `json:"last_used_at"`
}

// SessionStore manages GrandStream phone sessions (in-memory)
type SessionStore struct {
	sessions map[string]*GrandStreamSession // key: phone IP
	mu       sync.RWMutex
}

// GrandStreamSessionManager handles session-based authentication for GrandStream phones
type GrandStreamSessionManager struct {
	store      *SessionStore
	httpClient *http.Client
	sessionTTL time.Duration
}

// LoginResponse represents the JSON response from /cgi-bin/dologin
type LoginResponse struct {
	Response string `json:"response"`
	Body     struct {
		SID         string `json:"sid"`
		Role        string `json:"role"`
		DefaultAuth bool   `json:"defaultAuth"`
	} `json:"body"`
}

// APIResponse represents a response from api.values.get
type APIResponse struct {
	Response string                 `json:"response"`
	Body     map[string]interface{} `json:"body"`
}

// DeviceInfo contains device information from the phone
type DeviceInfo struct {
	VendorName     string `json:"vendor_name"`
	VendorFullname string `json:"vendor_fullname"`
	PhoneModel     string `json:"phone_model"`
	CoreVersion    string `json:"core_version,omitempty"`
	BaseVersion    string `json:"base_version,omitempty"`
	ProgVersion    string `json:"prog_version,omitempty"`
	BootVersion    string `json:"boot_version,omitempty"`
	DSPVersion     string `json:"dsp_version,omitempty"`
}

// NewSessionStore creates a new session store
func NewSessionStore() *SessionStore {
	return &SessionStore{
		sessions: make(map[string]*GrandStreamSession),
	}
}

// NewGrandStreamSessionManager creates a new session manager
func NewGrandStreamSessionManager(httpClient *http.Client) *GrandStreamSessionManager {
	if httpClient == nil {
		httpClient = &http.Client{
			Timeout: 15 * time.Second,
		}
	}

	return &GrandStreamSessionManager{
		store:      NewSessionStore(),
		httpClient: httpClient,
		sessionTTL: 30 * time.Minute,
	}
}

// Login authenticates with a GrandStream phone via /cgi-bin/dologin
//
// Authentication flow:
// 1. POST to /cgi-bin/dologin with username=admin&password=<password>
// 2. Include Cookie: HttpOnly header
// 3. Get session ID (sid) and role from response
// 4. Store cookies: session-identity=<sid> and session-role=<role>
func (m *GrandStreamSessionManager) Login(ip, username, password string) (*GrandStreamSession, error) {
	// Build request
	formData := url.Values{}
	formData.Set("username", username)
	formData.Set("password", password)

	baseURL := buildBaseURL(ip)
	req, err := http.NewRequest("POST", baseURL+"/cgi-bin/dologin", strings.NewReader(formData.Encode()))
	if err != nil {
		return nil, fmt.Errorf("failed to create request: %w", err)
	}

	// Set required headers (minimal set - Referer is required for login)
	req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	req.Header.Set("Cookie", "HttpOnly")
	req.Header.Set("Referer", baseURL+"/")
	req.Header.Set("User-Agent", GetUserAgent())

	resp, err := m.httpClient.Do(req)
	if err != nil {
		return nil, fmt.Errorf("login request failed: %w", err)
	}
	defer resp.Body.Close()

	body, _ := io.ReadAll(resp.Body)

	if resp.StatusCode != http.StatusOK {
		return nil, fmt.Errorf("login failed with status %d: %s", resp.StatusCode, string(body))
	}

	// Check for Forbidden response
	if strings.Contains(string(body), "Forbidden") {
		return nil, fmt.Errorf("login forbidden - invalid credentials or headers")
	}

	// Parse JSON response
	var loginResp LoginResponse
	if err := json.Unmarshal(body, &loginResp); err != nil {
		return nil, fmt.Errorf("failed to parse login response: %w", err)
	}

	if loginResp.Response != "success" {
		return nil, fmt.Errorf("login was not successful: %s", string(body))
	}

	if loginResp.Body.SID == "" {
		return nil, fmt.Errorf("no session ID received")
	}

	// Build session with correct cookie names
	session := &GrandStreamSession{
		PhoneIP:   ip,
		Username:  username,
		SessionID: loginResp.Body.SID,
		Role:      loginResp.Body.Role,
		Cookies: map[string]string{
			"HttpOnly":         "",
			"session-identity": loginResp.Body.SID, // Correct cookie name for session ID
			"session-role":     loginResp.Body.Role,
		},
		Method:          "dologin",
		IsActive:        true,
		AuthenticatedAt: time.Now(),
		ExpiresAt:       time.Now().Add(m.sessionTTL),
		LastUsedAt:      time.Now(),
	}

	// Store session
	m.store.Set(ip, session)

	return session, nil
}

// GetParameters retrieves parameters from the phone using the correct API format
//
// Format: POST /cgi-bin/api.values.get
// Content-Type: application/x-www-form-urlencoded
// Body: request=param1:param2:param3&sid=<sid>
// Cookies: HttpOnly; session-identity=<sid>; session-role=admin
func (m *GrandStreamSessionManager) GetParameters(session *GrandStreamSession, parameters []string) (map[string]interface{}, error) {
	// Build request body: request=param1:param2:param3&sid=<sid>
	requestParams := strings.Join(parameters, ":")
	formBody := fmt.Sprintf("request=%s&sid=%s", requestParams, session.SessionID)

	baseURL := buildBaseURL(session.PhoneIP)
	req, err := http.NewRequest("POST", baseURL+"/cgi-bin/api.values.get", strings.NewReader(formBody))
	if err != nil {
		return nil, fmt.Errorf("failed to create request: %w", err)
	}

	// Set headers (minimal set)
	req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	req.Header.Set("Cookie", session.GetCookieHeader())
	req.Header.Set("User-Agent", GetUserAgent())

	resp, err := m.httpClient.Do(req)
	if err != nil {
		return nil, fmt.Errorf("API request failed: %w", err)
	}
	defer resp.Body.Close()

	body, _ := io.ReadAll(resp.Body)

	// Check for session expiration
	if strings.Contains(string(body), "session-expired") {
		session.IsActive = false
		return nil, fmt.Errorf("session expired")
	}

	var apiResp APIResponse
	if err := json.Unmarshal(body, &apiResp); err != nil {
		return nil, fmt.Errorf("failed to parse API response: %w", err)
	}

	if apiResp.Response != "success" {
		return nil, fmt.Errorf("API request was not successful: %s", string(body))
	}

	session.LastUsedAt = time.Now()
	return apiResp.Body, nil
}

// GetDeviceInfo retrieves device information from the phone
func (m *GrandStreamSessionManager) GetDeviceInfo(session *GrandStreamSession) (*DeviceInfo, error) {
	params := []string{
		"vendor_name",
		"vendor_fullname",
		"phone_model",
		"core_version",
		"base_version",
		"prog_version",
		"boot_version",
		"dsp_version",
	}

	data, err := m.GetParameters(session, params)
	if err != nil {
		return nil, err
	}

	info := &DeviceInfo{}

	if v, ok := data["vendor_name"].(string); ok {
		info.VendorName = v
	}
	if v, ok := data["vendor_fullname"].(string); ok {
		info.VendorFullname = v
	}
	if v, ok := data["phone_model"].(string); ok {
		info.PhoneModel = v
	}
	if v, ok := data["core_version"].(string); ok {
		info.CoreVersion = v
	}
	if v, ok := data["base_version"].(string); ok {
		info.BaseVersion = v
	}
	if v, ok := data["prog_version"].(string); ok {
		info.ProgVersion = v
	}
	if v, ok := data["boot_version"].(string); ok {
		info.BootVersion = v
	}
	if v, ok := data["dsp_version"].(string); ok {
		info.DSPVersion = v
	}

	return info, nil
}

// GetSession returns a valid session for a phone, or nil if none exists
func (m *GrandStreamSessionManager) GetSession(ip string) *GrandStreamSession {
	session := m.store.Get(ip)
	if session != nil && session.IsValid() {
		session.LastUsedAt = time.Now()
		return session
	}
	return nil
}

// GetOrCreateSession returns an existing valid session or creates a new one
func (m *GrandStreamSessionManager) GetOrCreateSession(ip, username, password string) (*GrandStreamSession, error) {
	session := m.GetSession(ip)
	if session != nil {
		return session, nil
	}

	return m.Login(ip, username, password)
}

// Logout revokes the session for a phone
func (m *GrandStreamSessionManager) Logout(ip string) {
	m.store.Delete(ip)
}

// IsValid checks if the session is valid and not expired
func (s *GrandStreamSession) IsValid() bool {
	return s.IsActive && time.Now().Before(s.ExpiresAt)
}

// GetCookieHeader returns cookies as a header string
func (s *GrandStreamSession) GetCookieHeader() string {
	var parts []string
	for name, value := range s.Cookies {
		if value != "" {
			parts = append(parts, fmt.Sprintf("%s=%s", name, value))
		} else {
			parts = append(parts, name)
		}
	}
	return strings.Join(parts, "; ")
}

// SIPAccountConfig contains SIP account configuration parameters
type SIPAccountConfig struct {
	AccountActive       bool   `json:"account_active"`
	AccountName         string `json:"account_name"`
	SIPServer           string `json:"sip_server"`
	SecondarySIPServer  string `json:"secondary_sip_server,omitempty"`
	OutboundProxy       string `json:"outbound_proxy,omitempty"`
	BackupOutboundProxy string `json:"backup_outbound_proxy,omitempty"`
	BLFServer           string `json:"blf_server,omitempty"`
	SIPUserID           string `json:"sip_user_id"`
	AuthID              string `json:"auth_id,omitempty"`
	AuthPassword        string `json:"auth_password,omitempty"`
	DisplayName         string `json:"display_name,omitempty"`
	Voicemail           string `json:"voicemail,omitempty"`
	AccountDisplay      string `json:"account_display,omitempty"` // 0=User Name, 1=User ID
}

// SIP Account P-value constants
const (
	PAccountActive       = "P271"
	PAccountName         = "P270"
	PSIPServer           = "P47"
	PSecondarySIPServer  = "P2312"
	POutboundProxy       = "P48"
	PBackupOutboundProxy = "P2333"
	PBLFServer           = "P2375"
	PSIPUserID           = "P35"
	PAuthID              = "P36"
	PAuthPassword        = "P34"
	PDisplayName         = "P3"
	PVoicemail           = "P33"
	PAccountDisplay      = "P2380"
)

// SetParameters sets parameters on the phone using /cgi-bin/api.values.post
//
// Format: POST /cgi-bin/api.values.post
// Content-Type: application/x-www-form-urlencoded
// Body: P270=value1&P47=value2&sid=<sid>
func (m *GrandStreamSessionManager) SetParameters(session *GrandStreamSession, parameters map[string]string) error {
	// Build form body: P270=value1&P47=value2&sid=<sid>
	formParts := make([]string, 0, len(parameters)+1)
	for name, value := range parameters {
		formParts = append(formParts, fmt.Sprintf("%s=%s", url.QueryEscape(name), url.QueryEscape(value)))
	}
	formParts = append(formParts, fmt.Sprintf("sid=%s", url.QueryEscape(session.SessionID)))
	formBody := strings.Join(formParts, "&")

	baseURL := buildBaseURL(session.PhoneIP)
	req, err := http.NewRequest("POST", baseURL+"/cgi-bin/api.values.post", strings.NewReader(formBody))
	if err != nil {
		return fmt.Errorf("failed to create request: %w", err)
	}

	// Set headers (minimal set)
	req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	req.Header.Set("Cookie", session.GetCookieHeader())
	req.Header.Set("User-Agent", GetUserAgent())

	resp, err := m.httpClient.Do(req)
	if err != nil {
		return fmt.Errorf("API request failed: %w", err)
	}
	defer resp.Body.Close()

	body, _ := io.ReadAll(resp.Body)

	// Check for session expiration
	if strings.Contains(string(body), "session-expired") {
		session.IsActive = false
		return fmt.Errorf("session expired")
	}

	var apiResp struct {
		Response string `json:"response"`
		Body     struct {
			Status string `json:"status"`
		} `json:"body"`
	}

	if err := json.Unmarshal(body, &apiResp); err != nil {
		return fmt.Errorf("failed to parse API response: %w", err)
	}

	if apiResp.Response != "success" || apiResp.Body.Status != "right" {
		return fmt.Errorf("API request was not successful: %s", string(body))
	}

	session.LastUsedAt = time.Now()
	return nil
}

// GetSIPAccount retrieves SIP account configuration from the phone
func (m *GrandStreamSessionManager) GetSIPAccount(session *GrandStreamSession) (*SIPAccountConfig, error) {
	params := []string{
		PAccountActive, PAccountName, PSIPServer, PSecondarySIPServer,
		POutboundProxy, PBackupOutboundProxy, PBLFServer, PSIPUserID,
		PAuthID, PDisplayName, PVoicemail, PAccountDisplay,
	}

	data, err := m.GetParameters(session, params)
	if err != nil {
		return nil, err
	}

	config := &SIPAccountConfig{}

	if v, ok := data[PAccountActive].(string); ok {
		config.AccountActive = v == "1"
	}
	if v, ok := data[PAccountName].(string); ok {
		config.AccountName = v
	}
	if v, ok := data[PSIPServer].(string); ok {
		config.SIPServer = v
	}
	if v, ok := data[PSecondarySIPServer].(string); ok {
		config.SecondarySIPServer = v
	}
	if v, ok := data[POutboundProxy].(string); ok {
		config.OutboundProxy = v
	}
	if v, ok := data[PBackupOutboundProxy].(string); ok {
		config.BackupOutboundProxy = v
	}
	if v, ok := data[PBLFServer].(string); ok {
		config.BLFServer = v
	}
	if v, ok := data[PSIPUserID].(string); ok {
		config.SIPUserID = v
	}
	if v, ok := data[PAuthID].(string); ok {
		config.AuthID = v
	}
	if v, ok := data[PDisplayName].(string); ok {
		config.DisplayName = v
	}
	if v, ok := data[PVoicemail].(string); ok {
		config.Voicemail = v
	}
	if v, ok := data[PAccountDisplay].(string); ok {
		config.AccountDisplay = v
	}

	return config, nil
}

// SetSIPAccount configures a SIP account on the phone
func (m *GrandStreamSessionManager) SetSIPAccount(session *GrandStreamSession, config *SIPAccountConfig) error {
	params := make(map[string]string)

	// Account Active
	if config.AccountActive {
		params[PAccountActive] = "1"
	} else {
		params[PAccountActive] = "0"
	}

	// Required fields
	if config.AccountName != "" {
		params[PAccountName] = config.AccountName
	}
	if config.SIPServer != "" {
		params[PSIPServer] = config.SIPServer
	}
	if config.SIPUserID != "" {
		params[PSIPUserID] = config.SIPUserID
	}

	// Optional fields
	if config.SecondarySIPServer != "" {
		params[PSecondarySIPServer] = config.SecondarySIPServer
	}
	if config.OutboundProxy != "" {
		params[POutboundProxy] = config.OutboundProxy
	}
	if config.BackupOutboundProxy != "" {
		params[PBackupOutboundProxy] = config.BackupOutboundProxy
	}
	if config.BLFServer != "" {
		params[PBLFServer] = config.BLFServer
	}
	if config.AuthID != "" {
		params[PAuthID] = config.AuthID
	}
	if config.AuthPassword != "" {
		params[PAuthPassword] = config.AuthPassword
	}
	if config.DisplayName != "" {
		params[PDisplayName] = config.DisplayName
	}
	if config.Voicemail != "" {
		params[PVoicemail] = config.Voicemail
	}
	if config.AccountDisplay != "" {
		params[PAccountDisplay] = config.AccountDisplay
	}

	return m.SetParameters(session, params)
}

// ProvisionExtension is a simplified interface for provisioning a SIP extension
func (m *GrandStreamSessionManager) ProvisionExtension(session *GrandStreamSession, extension, password, server, displayName string) error {
	if displayName == "" {
		displayName = fmt.Sprintf("Extension %s", extension)
	}

	return m.SetSIPAccount(session, &SIPAccountConfig{
		AccountActive: true,
		AccountName:   "SIP",
		SIPServer:     server,
		SIPUserID:     extension,
		AuthID:        extension,
		AuthPassword:  password,
		DisplayName:   displayName,
	})
}

// SessionStore methods

// Set stores a session
func (s *SessionStore) Set(ip string, session *GrandStreamSession) {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.sessions[ip] = session
}

// Get retrieves a session
func (s *SessionStore) Get(ip string) *GrandStreamSession {
	s.mu.RLock()
	defer s.mu.RUnlock()
	return s.sessions[ip]
}

// Delete removes a session
func (s *SessionStore) Delete(ip string) {
	s.mu.Lock()
	defer s.mu.Unlock()
	delete(s.sessions, ip)
}

// Count returns the number of active sessions
func (s *SessionStore) Count() int {
	s.mu.RLock()
	defer s.mu.RUnlock()
	return len(s.sessions)
}

// CleanupExpired removes expired sessions
func (s *SessionStore) CleanupExpired() int {
	s.mu.Lock()
	defer s.mu.Unlock()

	count := 0
	for ip, session := range s.sessions {
		if !session.IsValid() {
			delete(s.sessions, ip)
			count++
		}
	}
	return count
}

// All returns all sessions (for debugging/admin)
func (s *SessionStore) All() []*GrandStreamSession {
	s.mu.RLock()
	defer s.mu.RUnlock()

	result := make([]*GrandStreamSession, 0, len(s.sessions))
	for _, session := range s.sessions {
		result = append(result, session)
	}
	return result
}
