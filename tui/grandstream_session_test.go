package main

import (
"encoding/json"
"net/http"
"net/http/httptest"
"testing"
"time"
)

// TestGrandStreamSessionManagerCreation tests creating a new session manager
func TestGrandStreamSessionManagerCreation(t *testing.T) {
manager := NewGrandStreamSessionManager(nil)

if manager == nil {
t.Fatal("GrandStreamSessionManager should not be nil")
}

if manager.store == nil {
t.Error("Session store should not be nil")
}

if manager.httpClient == nil {
t.Error("HTTP client should not be nil")
}

if manager.sessionTTL != 30*time.Minute {
t.Errorf("Expected session TTL of 30 minutes, got %v", manager.sessionTTL)
}
}

// TestSessionStoreOperations tests the session store operations
func TestSessionStoreOperations(t *testing.T) {
store := NewSessionStore()

// Test Set and Get
session := &GrandStreamSession{
PhoneIP:   "192.168.1.100",
SessionID: "test-session-123",
Role:      "admin",
IsActive:  true,
ExpiresAt: time.Now().Add(30 * time.Minute),
}

store.Set("192.168.1.100", session)

retrieved := store.Get("192.168.1.100")
if retrieved == nil {
t.Fatal("Expected to retrieve session")
}

if retrieved.SessionID != "test-session-123" {
t.Errorf("Expected session ID 'test-session-123', got '%s'", retrieved.SessionID)
}

// Test Count
if store.Count() != 1 {
t.Errorf("Expected count of 1, got %d", store.Count())
}

// Test Delete
store.Delete("192.168.1.100")

if store.Get("192.168.1.100") != nil {
t.Error("Expected session to be deleted")
}

if store.Count() != 0 {
t.Errorf("Expected count of 0 after delete, got %d", store.Count())
}
}

// TestSessionValidity tests the IsValid method
func TestSessionValidity(t *testing.T) {
// Valid session
validSession := &GrandStreamSession{
IsActive:  true,
ExpiresAt: time.Now().Add(30 * time.Minute),
}

if !validSession.IsValid() {
t.Error("Session should be valid")
}

// Expired session
expiredSession := &GrandStreamSession{
IsActive:  true,
ExpiresAt: time.Now().Add(-1 * time.Minute),
}

if expiredSession.IsValid() {
t.Error("Expired session should not be valid")
}

// Inactive session
inactiveSession := &GrandStreamSession{
IsActive:  false,
ExpiresAt: time.Now().Add(30 * time.Minute),
}

if inactiveSession.IsValid() {
t.Error("Inactive session should not be valid")
}
}

// TestGetCookieHeader tests the GetCookieHeader method
func TestGetCookieHeader(t *testing.T) {
session := &GrandStreamSession{
Cookies: map[string]string{
"HttpOnly":         "",
"session-identity": "abc123",
"session-role":     "admin",
},
}

header := session.GetCookieHeader()

// Check that all cookies are present
if !contains(header, "session-identity=abc123") {
t.Error("Cookie header should contain session-identity")
}

if !contains(header, "session-role=admin") {
t.Error("Cookie header should contain session-role")
}

if !contains(header, "HttpOnly") {
t.Error("Cookie header should contain HttpOnly")
}
}

// TestLogin tests the Login method with a mock server
func TestLogin(t *testing.T) {
// Create mock server that simulates GrandStream login
loginCalled := false
ts := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
if r.URL.Path == "/cgi-bin/dologin" && r.Method == "POST" {
loginCalled = true

// Check required headers
if r.Header.Get("Cookie") != "HttpOnly" {
t.Error("Expected Cookie: HttpOnly header")
}

if r.Header.Get("Content-Type") != "application/x-www-form-urlencoded" {
t.Error("Expected Content-Type: application/x-www-form-urlencoded")
}

// Check form data
r.ParseForm()
if r.Form.Get("username") != "admin" {
t.Error("Expected username=admin")
}
if r.Form.Get("password") != "testpass" {
t.Error("Expected password=testpass")
}

// Return successful login response
response := LoginResponse{
Response: "success",
}
response.Body.SID = "1234567890abc"
response.Body.Role = "admin"
response.Body.DefaultAuth = false

w.Header().Set("Content-Type", "application/json")
w.Header().Set("Set-Cookie", "session-role=admin")
json.NewEncoder(w).Encode(response)
}
}))
defer ts.Close()

// Extract host:port from test server URL (remove "http://")
ip := ts.URL[7:]

manager := NewGrandStreamSessionManager(&http.Client{Timeout: 5 * time.Second})

session, err := manager.Login(ip, "admin", "testpass")

if err != nil {
t.Fatalf("Login should not fail: %v", err)
}

if !loginCalled {
t.Error("Login endpoint was not called")
}

if session == nil {
t.Fatal("Session should not be nil")
}

if session.SessionID != "1234567890abc" {
t.Errorf("Expected session ID '1234567890abc', got '%s'", session.SessionID)
}

if session.Role != "admin" {
t.Errorf("Expected role 'admin', got '%s'", session.Role)
}

if !session.IsActive {
t.Error("Session should be active")
}

// Check cookies
if session.Cookies["session-identity"] != "1234567890abc" {
t.Error("Cookies should contain session-identity")
}

if session.Cookies["session-role"] != "admin" {
t.Error("Cookies should contain session-role")
}
}

// TestGetParameters tests the GetParameters method
func TestGetParameters(t *testing.T) {
apiCalled := false
ts := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
if r.URL.Path == "/cgi-bin/api.values.get" && r.Method == "POST" {
apiCalled = true

// Check Content-Type
if r.Header.Get("Content-Type") != "application/x-www-form-urlencoded" {
t.Error("Expected Content-Type: application/x-www-form-urlencoded")
}

// Check Cookie header contains session info
cookie := r.Header.Get("Cookie")
if !contains(cookie, "session-identity") {
t.Error("Cookie should contain session-identity")
}

// Parse form to check request format
r.ParseForm()
request := r.Form.Get("request")
if request != "vendor_name:phone_model" {
t.Errorf("Expected request='vendor_name:phone_model', got '%s'", request)
}

// Return successful response
response := APIResponse{
Response: "success",
Body: map[string]interface{}{
"vendor_name":  "Grandstream",
"phone_model": "GXP1625",
},
}

w.Header().Set("Content-Type", "application/json")
json.NewEncoder(w).Encode(response)
}
}))
defer ts.Close()

ip := ts.URL[7:]

manager := NewGrandStreamSessionManager(&http.Client{Timeout: 5 * time.Second})

session := &GrandStreamSession{
PhoneIP:   ip,
SessionID: "testsession123",
Role:      "admin",
Cookies: map[string]string{
"HttpOnly":         "",
"session-identity": "testsession123",
"session-role":     "admin",
},
IsActive:  true,
ExpiresAt: time.Now().Add(30 * time.Minute),
}

data, err := manager.GetParameters(session, []string{"vendor_name", "phone_model"})

if err != nil {
t.Fatalf("GetParameters should not fail: %v", err)
}

if !apiCalled {
t.Error("API endpoint was not called")
}

if data["vendor_name"] != "Grandstream" {
t.Errorf("Expected vendor_name 'Grandstream', got '%v'", data["vendor_name"])
}

if data["phone_model"] != "GXP1625" {
t.Errorf("Expected phone_model 'GXP1625', got '%v'", data["phone_model"])
}
}

// TestGetDeviceInfo tests the GetDeviceInfo method
func TestGetDeviceInfo(t *testing.T) {
ts := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
if r.URL.Path == "/cgi-bin/api.values.get" {
response := APIResponse{
Response: "success",
Body: map[string]interface{}{
"vendor_name":     "Grandstream",
"vendor_fullname": "Grandstream Networks, Inc.",
"phone_model":     "GXP1625",
"prog_version":    "1.0.7.13",
},
}
json.NewEncoder(w).Encode(response)
}
}))
defer ts.Close()

ip := ts.URL[7:]

manager := NewGrandStreamSessionManager(&http.Client{Timeout: 5 * time.Second})

session := &GrandStreamSession{
PhoneIP:   ip,
SessionID: "testsession123",
Cookies: map[string]string{
"HttpOnly":         "",
"session-identity": "testsession123",
"session-role":     "admin",
},
IsActive:  true,
ExpiresAt: time.Now().Add(30 * time.Minute),
}

info, err := manager.GetDeviceInfo(session)

if err != nil {
t.Fatalf("GetDeviceInfo should not fail: %v", err)
}

if info.VendorName != "Grandstream" {
t.Errorf("Expected vendor 'Grandstream', got '%s'", info.VendorName)
}

if info.PhoneModel != "GXP1625" {
t.Errorf("Expected model 'GXP1625', got '%s'", info.PhoneModel)
}
}

// TestCleanupExpired tests the CleanupExpired method
func TestCleanupExpired(t *testing.T) {
store := NewSessionStore()

// Add valid session
validSession := &GrandStreamSession{
PhoneIP:   "192.168.1.100",
IsActive:  true,
ExpiresAt: time.Now().Add(30 * time.Minute),
}
store.Set("192.168.1.100", validSession)

// Add expired session
expiredSession := &GrandStreamSession{
PhoneIP:   "192.168.1.101",
IsActive:  true,
ExpiresAt: time.Now().Add(-1 * time.Minute),
}
store.Set("192.168.1.101", expiredSession)

// Cleanup
cleaned := store.CleanupExpired()

if cleaned != 1 {
t.Errorf("Expected 1 expired session to be cleaned, got %d", cleaned)
}

if store.Count() != 1 {
t.Errorf("Expected 1 session remaining, got %d", store.Count())
}

if store.Get("192.168.1.100") == nil {
t.Error("Valid session should still exist")
}

if store.Get("192.168.1.101") != nil {
t.Error("Expired session should be removed")
}
}

// Helper function
func contains(s, substr string) bool {
return len(s) > 0 && len(substr) > 0 && (s == substr || len(s) > len(substr) && (s[:len(substr)] == substr || s[len(s)-len(substr):] == substr || containsMiddle(s, substr)))
}

func containsMiddle(s, substr string) bool {
for i := 1; i < len(s)-len(substr); i++ {
if s[i:i+len(substr)] == substr {
return true
}
}
return false
}

// TestSetParameters tests the SetParameters method
func TestSetParameters(t *testing.T) {
postCalled := false
ts := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
if r.URL.Path == "/cgi-bin/api.values.post" && r.Method == "POST" {
postCalled = true

// Check Content-Type
if r.Header.Get("Content-Type") != "application/x-www-form-urlencoded" {
t.Error("Expected Content-Type: application/x-www-form-urlencoded")
}

// Check Cookie header
cookie := r.Header.Get("Cookie")
if !contains(cookie, "session-identity") {
t.Error("Cookie should contain session-identity")
}

// Parse form
r.ParseForm()
if r.Form.Get("P270") != "TestAccount" {
t.Errorf("Expected P270=TestAccount, got '%s'", r.Form.Get("P270"))
}
if r.Form.Get("sid") == "" {
t.Error("Expected sid parameter")
}

// Return success response
w.Header().Set("Content-Type", "application/json")
w.Write([]byte(`{ "response": "success", "body": { "status": "right" } }`))
}
}))
defer ts.Close()

ip := ts.URL[7:]

manager := NewGrandStreamSessionManager(&http.Client{Timeout: 5 * time.Second})

session := &GrandStreamSession{
PhoneIP:   ip,
SessionID: "testsession123",
Cookies: map[string]string{
"HttpOnly":         "",
"session-identity": "testsession123",
"session-role":     "admin",
},
IsActive:  true,
ExpiresAt: time.Now().Add(30 * time.Minute),
}

err := manager.SetParameters(session, map[string]string{
"P270": "TestAccount",
})

if err != nil {
t.Fatalf("SetParameters should not fail: %v", err)
}

if !postCalled {
t.Error("POST endpoint was not called")
}
}

// TestGetSIPAccount tests the GetSIPAccount method
func TestGetSIPAccount(t *testing.T) {
ts := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
if r.URL.Path == "/cgi-bin/api.values.get" {
response := APIResponse{
Response: "success",
Body: map[string]interface{}{
"P271":  "1",
"P270":  "SIP",
"P47":   "sip.example.com",
"P35":   "100",
"P36":   "100",
"P3":    "Extension 100",
"P2380": "0",
},
}
json.NewEncoder(w).Encode(response)
}
}))
defer ts.Close()

ip := ts.URL[7:]

manager := NewGrandStreamSessionManager(&http.Client{Timeout: 5 * time.Second})

session := &GrandStreamSession{
PhoneIP:   ip,
SessionID: "testsession123",
Cookies: map[string]string{
"HttpOnly":         "",
"session-identity": "testsession123",
"session-role":     "admin",
},
IsActive:  true,
ExpiresAt: time.Now().Add(30 * time.Minute),
}

config, err := manager.GetSIPAccount(session)

if err != nil {
t.Fatalf("GetSIPAccount should not fail: %v", err)
}

if !config.AccountActive {
t.Error("Account should be active")
}

if config.AccountName != "SIP" {
t.Errorf("Expected AccountName 'SIP', got '%s'", config.AccountName)
}

if config.SIPServer != "sip.example.com" {
t.Errorf("Expected SIPServer 'sip.example.com', got '%s'", config.SIPServer)
}

if config.SIPUserID != "100" {
t.Errorf("Expected SIPUserID '100', got '%s'", config.SIPUserID)
}

if config.DisplayName != "Extension 100" {
t.Errorf("Expected DisplayName 'Extension 100', got '%s'", config.DisplayName)
}
}

// TestSetSIPAccount tests the SetSIPAccount method
func TestSetSIPAccount(t *testing.T) {
postCalled := false
ts := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
if r.URL.Path == "/cgi-bin/api.values.post" {
postCalled = true

r.ParseForm()

// Check expected parameters
if r.Form.Get("P271") != "1" {
t.Errorf("Expected P271=1, got '%s'", r.Form.Get("P271"))
}
if r.Form.Get("P47") != "sip.example.com" {
t.Errorf("Expected P47=sip.example.com, got '%s'", r.Form.Get("P47"))
}
if r.Form.Get("P35") != "100" {
t.Errorf("Expected P35=100, got '%s'", r.Form.Get("P35"))
}

w.Header().Set("Content-Type", "application/json")
w.Write([]byte(`{ "response": "success", "body": { "status": "right" } }`))
}
}))
defer ts.Close()

ip := ts.URL[7:]

manager := NewGrandStreamSessionManager(&http.Client{Timeout: 5 * time.Second})

session := &GrandStreamSession{
PhoneIP:   ip,
SessionID: "testsession123",
Cookies: map[string]string{
"HttpOnly":         "",
"session-identity": "testsession123",
"session-role":     "admin",
},
IsActive:  true,
ExpiresAt: time.Now().Add(30 * time.Minute),
}

err := manager.SetSIPAccount(session, &SIPAccountConfig{
AccountActive: true,
AccountName:   "SIP",
SIPServer:     "sip.example.com",
SIPUserID:     "100",
AuthID:        "100",
AuthPassword:  "secret123",
DisplayName:   "Extension 100",
})

if err != nil {
t.Fatalf("SetSIPAccount should not fail: %v", err)
}

if !postCalled {
t.Error("POST endpoint was not called")
}
}

// TestProvisionExtension tests the ProvisionExtension method
func TestProvisionExtension(t *testing.T) {
ts := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
if r.URL.Path == "/cgi-bin/api.values.post" {
r.ParseForm()

// Verify all required fields are set
if r.Form.Get("P271") != "1" {
t.Error("Account should be active")
}
if r.Form.Get("P270") != "SIP" {
t.Error("Account name should be SIP")
}
if r.Form.Get("P47") != "pbx.example.com" {
t.Error("SIP server should be set")
}
if r.Form.Get("P35") != "200" {
t.Error("Extension should be set")
}
if r.Form.Get("P34") != "pass123" {
t.Error("Password should be set")
}
if r.Form.Get("P3") != "User 200" {
t.Error("Display name should be set")
}

w.Header().Set("Content-Type", "application/json")
w.Write([]byte(`{ "response": "success", "body": { "status": "right" } }`))
}
}))
defer ts.Close()

ip := ts.URL[7:]

manager := NewGrandStreamSessionManager(&http.Client{Timeout: 5 * time.Second})

session := &GrandStreamSession{
PhoneIP:   ip,
SessionID: "testsession123",
Cookies: map[string]string{
"HttpOnly":         "",
"session-identity": "testsession123",
"session-role":     "admin",
},
IsActive:  true,
ExpiresAt: time.Now().Add(30 * time.Minute),
}

err := manager.ProvisionExtension(session, "200", "pass123", "pbx.example.com", "User 200")

if err != nil {
t.Fatalf("ProvisionExtension should not fail: %v", err)
}
}
