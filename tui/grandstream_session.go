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

	req, err := http.NewRequest("POST", fmt.Sprintf("http://%s/cgi-bin/dologin", ip), strings.NewReader(formData.Encode()))
	if err != nil {
		return nil, fmt.Errorf("failed to create request: %w", err)
	}

	// Set required headers
	req.Header.Set("Accept", "*/*")
	req.Header.Set("Accept-Language", "en-US,en;q=0.9")
	req.Header.Set("Cache-Control", "max-age=0")
	req.Header.Set("Connection", "keep-alive")
	req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	req.Header.Set("Cookie", "HttpOnly")
	req.Header.Set("Origin", fmt.Sprintf("http://%s", ip))
	req.Header.Set("Pragma", "no-cache")
	req.Header.Set("Referer", fmt.Sprintf("http://%s/", ip))
	req.Header.Set("User-Agent", "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36")

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

	req, err := http.NewRequest("POST", fmt.Sprintf("http://%s/cgi-bin/api.values.get", session.PhoneIP), strings.NewReader(formBody))
	if err != nil {
		return nil, fmt.Errorf("failed to create request: %w", err)
	}

	// Set headers
	req.Header.Set("Accept", "*/*")
	req.Header.Set("Accept-Language", "en-US,en;q=0.9")
	req.Header.Set("Cache-Control", "max-age=0")
	req.Header.Set("Connection", "keep-alive")
	req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	req.Header.Set("Origin", fmt.Sprintf("http://%s", session.PhoneIP))
	req.Header.Set("Pragma", "no-cache")
	req.Header.Set("Referer", fmt.Sprintf("http://%s/", session.PhoneIP))
	req.Header.Set("User-Agent", "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36")

	// Set cookies
	req.Header.Set("Cookie", session.GetCookieHeader())

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
