package main

import (
	"net/http"
	"net/http/httptest"
	"testing"
)

// TestGrandStreamCTICreation tests creating a new CTI interface
func TestGrandStreamCTICreation(t *testing.T) {
	credentials := map[string]string{
		"username": "admin",
		"password": "admin",
	}
	
	phone := NewGrandStreamPhone("192.168.1.100", credentials, nil)
	cti := NewGrandStreamCTI(phone)
	
	if cti == nil {
		t.Fatal("GrandStreamCTI should not be nil")
	}
	
	if cti.phone == nil {
		t.Error("CTI should have phone reference")
	}
	
	if cti.httpClient == nil {
		t.Error("CTI should have http client")
	}
}

// TestCTIAcceptCall tests the accept call operation
func TestCTIAcceptCall(t *testing.T) {
	// Create test server
	acceptCalled := false
	ts := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/cgi-bin/api-phone_operation" && r.URL.Query().Get("cmd") == CTICmdAcceptCall {
			acceptCalled = true
			w.WriteHeader(http.StatusOK)
			w.Write([]byte("result=success"))
		}
	}))
	defer ts.Close()
	
	// Extract host:port
	ip := ts.URL[7:]
	
	credentials := map[string]string{
		"username": "admin",
		"password": "admin",
	}
	
	phone := NewGrandStreamPhone(ip, credentials, &http.Client{})
	cti := NewGrandStreamCTI(phone)
	
	resp, err := cti.AcceptCall(1)
	
	if err != nil {
		t.Errorf("AcceptCall should not fail: %v", err)
	}
	
	if !acceptCalled {
		t.Error("Accept call endpoint was not called")
	}
	
	if resp == nil {
		t.Error("Response should not be nil")
	}
}

// TestCTIRejectCall tests the reject call operation
func TestCTIRejectCall(t *testing.T) {
	rejectCalled := false
	ts := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/cgi-bin/api-phone_operation" && r.URL.Query().Get("cmd") == CTICmdRejectCall {
			rejectCalled = true
			w.WriteHeader(http.StatusOK)
			w.Write([]byte("result=success"))
		}
	}))
	defer ts.Close()
	
	ip := ts.URL[7:]
	credentials := map[string]string{"username": "admin", "password": "admin"}
	phone := NewGrandStreamPhone(ip, credentials, &http.Client{})
	cti := NewGrandStreamCTI(phone)
	
	_, err := cti.RejectCall(1)
	
	if err != nil {
		t.Errorf("RejectCall should not fail: %v", err)
	}
	
	if !rejectCalled {
		t.Error("Reject call endpoint was not called")
	}
}

// TestCTIEndCall tests the end call operation
func TestCTIEndCall(t *testing.T) {
	endCalled := false
	ts := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/cgi-bin/api-phone_operation" && r.URL.Query().Get("cmd") == CTICmdEndCall {
			endCalled = true
			w.WriteHeader(http.StatusOK)
		}
	}))
	defer ts.Close()
	
	ip := ts.URL[7:]
	credentials := map[string]string{"username": "admin", "password": "admin"}
	phone := NewGrandStreamPhone(ip, credentials, &http.Client{})
	cti := NewGrandStreamCTI(phone)
	
	_, err := cti.EndCall(1)
	
	if err != nil {
		t.Errorf("EndCall should not fail: %v", err)
	}
	
	if !endCalled {
		t.Error("End call endpoint was not called")
	}
}

// TestCTIHoldCall tests the hold call operation
func TestCTIHoldCall(t *testing.T) {
	holdCalled := false
	ts := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/cgi-bin/api-phone_operation" && r.URL.Query().Get("cmd") == CTICmdHoldCall {
			holdCalled = true
			w.WriteHeader(http.StatusOK)
		}
	}))
	defer ts.Close()
	
	ip := ts.URL[7:]
	credentials := map[string]string{"username": "admin", "password": "admin"}
	phone := NewGrandStreamPhone(ip, credentials, &http.Client{})
	cti := NewGrandStreamCTI(phone)
	
	_, err := cti.HoldCall(1)
	
	if err != nil {
		t.Errorf("HoldCall should not fail: %v", err)
	}
	
	if !holdCalled {
		t.Error("Hold call endpoint was not called")
	}
}

// TestCTIResumeCall tests the resume call operation
func TestCTIResumeCall(t *testing.T) {
	resumeCalled := false
	ts := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/cgi-bin/api-phone_operation" && r.URL.Query().Get("cmd") == CTICmdResumeCall {
			resumeCalled = true
			w.WriteHeader(http.StatusOK)
		}
	}))
	defer ts.Close()
	
	ip := ts.URL[7:]
	credentials := map[string]string{"username": "admin", "password": "admin"}
	phone := NewGrandStreamPhone(ip, credentials, &http.Client{})
	cti := NewGrandStreamCTI(phone)
	
	_, err := cti.ResumeCall(1)
	
	if err != nil {
		t.Errorf("ResumeCall should not fail: %v", err)
	}
	
	if !resumeCalled {
		t.Error("Resume call endpoint was not called")
	}
}

// TestCTIDial tests the dial operation
func TestCTIDial(t *testing.T) {
	dialCalled := false
	dialedNumber := ""
	ts := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/cgi-bin/api-phone_operation" && r.URL.Query().Get("cmd") == CTICmdDial {
			dialCalled = true
			dialedNumber = r.URL.Query().Get("number")
			w.WriteHeader(http.StatusOK)
		}
	}))
	defer ts.Close()
	
	ip := ts.URL[7:]
	credentials := map[string]string{"username": "admin", "password": "admin"}
	phone := NewGrandStreamPhone(ip, credentials, &http.Client{})
	cti := NewGrandStreamCTI(phone)
	
	_, err := cti.Dial("1234567890", 1)
	
	if err != nil {
		t.Errorf("Dial should not fail: %v", err)
	}
	
	if !dialCalled {
		t.Error("Dial endpoint was not called")
	}
	
	if dialedNumber != "1234567890" {
		t.Errorf("Expected dialed number 1234567890, got %s", dialedNumber)
	}
}

// TestCTISendDTMF tests the DTMF sending operation
func TestCTISendDTMF(t *testing.T) {
	dtmfCalled := false
	sentDigits := ""
	ts := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/cgi-bin/api-phone_operation" && r.URL.Query().Get("cmd") == CTICmdDialDTMF {
			dtmfCalled = true
			sentDigits = r.URL.Query().Get("value")
			w.WriteHeader(http.StatusOK)
		}
	}))
	defer ts.Close()
	
	ip := ts.URL[7:]
	credentials := map[string]string{"username": "admin", "password": "admin"}
	phone := NewGrandStreamPhone(ip, credentials, &http.Client{})
	cti := NewGrandStreamCTI(phone)
	
	_, err := cti.SendDTMF("123#", 1)
	
	if err != nil {
		t.Errorf("SendDTMF should not fail: %v", err)
	}
	
	if !dtmfCalled {
		t.Error("DTMF endpoint was not called")
	}
	
	if sentDigits != "123#" {
		t.Errorf("Expected DTMF digits 123#, got %s", sentDigits)
	}
}

// TestCTIBlindTransfer tests the blind transfer operation
func TestCTIBlindTransfer(t *testing.T) {
	transferCalled := false
	targetExtension := ""
	ts := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/cgi-bin/api-phone_operation" && r.URL.Query().Get("cmd") == CTICmdBlindTransfer {
			transferCalled = true
			targetExtension = r.URL.Query().Get("target")
			w.WriteHeader(http.StatusOK)
		}
	}))
	defer ts.Close()
	
	ip := ts.URL[7:]
	credentials := map[string]string{"username": "admin", "password": "admin"}
	phone := NewGrandStreamPhone(ip, credentials, &http.Client{})
	cti := NewGrandStreamCTI(phone)
	
	_, err := cti.BlindTransfer("102", 1)
	
	if err != nil {
		t.Errorf("BlindTransfer should not fail: %v", err)
	}
	
	if !transferCalled {
		t.Error("Transfer endpoint was not called")
	}
	
	if targetExtension != "102" {
		t.Errorf("Expected target extension 102, got %s", targetExtension)
	}
}

// TestCTISetDND tests the DND toggle operation
func TestCTISetDND(t *testing.T) {
	dndCalled := false
	dndValue := ""
	ts := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/cgi-bin/api-phone_operation" && r.URL.Query().Get("cmd") == CTICmdDND {
			dndCalled = true
			dndValue = r.URL.Query().Get("value")
			w.WriteHeader(http.StatusOK)
		}
	}))
	defer ts.Close()
	
	ip := ts.URL[7:]
	credentials := map[string]string{"username": "admin", "password": "admin"}
	phone := NewGrandStreamPhone(ip, credentials, &http.Client{})
	cti := NewGrandStreamCTI(phone)
	
	// Test enabling DND
	_, err := cti.SetDND(true)
	
	if err != nil {
		t.Errorf("SetDND(true) should not fail: %v", err)
	}
	
	if !dndCalled {
		t.Error("DND endpoint was not called")
	}
	
	if dndValue != "1" {
		t.Errorf("Expected DND value 1, got %s", dndValue)
	}
	
	// Test disabling DND
	dndCalled = false
	_, err = cti.SetDND(false)
	
	if err != nil {
		t.Errorf("SetDND(false) should not fail: %v", err)
	}
	
	if dndValue != "0" {
		t.Errorf("Expected DND value 0, got %s", dndValue)
	}
}

// TestCTIGetPhoneStatus tests getting phone status
func TestCTIGetPhoneStatus(t *testing.T) {
	statusCalled := false
	ts := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/cgi-bin/api-get_phone_status" {
			statusCalled = true
			w.WriteHeader(http.StatusOK)
			w.Write([]byte("dnd=0\nforward=0\nmwi=0\nactiveline=1"))
		}
	}))
	defer ts.Close()
	
	ip := ts.URL[7:]
	credentials := map[string]string{"username": "admin", "password": "admin"}
	phone := NewGrandStreamPhone(ip, credentials, &http.Client{})
	cti := NewGrandStreamCTI(phone)
	
	state, err := cti.GetPhoneStatus()
	
	if err != nil {
		t.Errorf("GetPhoneStatus should not fail: %v", err)
	}
	
	if !statusCalled {
		t.Error("Status endpoint was not called")
	}
	
	if state == nil {
		t.Fatal("Phone state should not be nil")
	}
	
	if state.ActiveLine != 1 {
		t.Errorf("Expected active line 1, got %d", state.ActiveLine)
	}
	
	if state.DNDEnabled {
		t.Error("DND should be disabled")
	}
}

// TestCTIEnableSNMP tests enabling SNMP
func TestCTIEnableSNMP(t *testing.T) {
	configSet := false
	ts := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/cgi-bin/api-set_config" {
			configSet = true
			w.WriteHeader(http.StatusOK)
		}
	}))
	defer ts.Close()
	
	ip := ts.URL[7:]
	credentials := map[string]string{"username": "admin", "password": "admin"}
	phone := NewGrandStreamPhone(ip, credentials, &http.Client{})
	cti := NewGrandStreamCTI(phone)
	
	snmpConfig := &SNMPConfig{
		Enabled:   true,
		Community: "public",
		Version:   "v2c",
	}
	
	err := cti.EnableSNMP(snmpConfig)
	
	if err != nil {
		t.Errorf("EnableSNMP should not fail: %v", err)
	}
	
	if !configSet {
		t.Error("Config set endpoint was not called")
	}
}

// TestCTIProvisioningConfig tests CTI provisioning configuration
func TestCTIProvisioningConfig(t *testing.T) {
	ts := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusOK)
	}))
	defer ts.Close()
	
	ip := ts.URL[7:]
	credentials := map[string]string{"username": "admin", "password": "admin"}
	phone := NewGrandStreamPhone(ip, credentials, &http.Client{})
	cti := NewGrandStreamCTI(phone)
	
	config := &CTIProvisioningConfig{
		EnableCTI:  true,
		EnableSNMP: true,
		SNMPConfig: &SNMPConfig{
			Enabled:   true,
			Community: "public",
			Version:   "v2c",
		},
	}
	
	err := cti.ProvisionCTIFeatures(config)
	
	if err != nil {
		t.Errorf("ProvisionCTIFeatures should not fail: %v", err)
	}
}

// TestParseKeyValueResponse tests parsing key=value response format
func TestParseKeyValueResponse(t *testing.T) {
	tests := []struct {
		name     string
		input    string
		expected map[string]string
	}{
		{
			name:  "Single line",
			input: "key=value",
			expected: map[string]string{
				"key": "value",
			},
		},
		{
			name:  "Multiple lines",
			input: "key1=value1\nkey2=value2\nkey3=value3",
			expected: map[string]string{
				"key1": "value1",
				"key2": "value2",
				"key3": "value3",
			},
		},
		{
			name:  "With spaces",
			input: "key = value\n another = test ",
			expected: map[string]string{
				"key":     "value",
				"another": "test",
			},
		},
		{
			name:     "Empty input",
			input:    "",
			expected: map[string]string{},
		},
	}
	
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			result := parseKeyValueResponse(tt.input)
			
			if len(result) != len(tt.expected) {
				t.Errorf("Expected %d entries, got %d", len(tt.expected), len(result))
			}
			
			for k, v := range tt.expected {
				if result[k] != v {
					t.Errorf("Expected %s=%s, got %s=%s", k, v, k, result[k])
				}
			}
		})
	}
}

// TestBoolToString tests the boolToString helper
func TestBoolToString(t *testing.T) {
	if boolToString(true) != "1" {
		t.Error("boolToString(true) should return '1'")
	}
	
	if boolToString(false) != "0" {
		t.Error("boolToString(false) should return '0'")
	}
}

// TestCTICommandConstants tests that CTI command constants are defined correctly
func TestCTICommandConstants(t *testing.T) {
	commands := []struct {
		name     string
		constant string
		expected string
	}{
		{"AcceptCall", CTICmdAcceptCall, "acceptcall"},
		{"RejectCall", CTICmdRejectCall, "rejectcall"},
		{"EndCall", CTICmdEndCall, "endcall"},
		{"Hold", CTICmdHoldCall, "hold"},
		{"Unhold", CTICmdResumeCall, "unhold"},
		{"Mute", CTICmdMute, "mute"},
		{"Unmute", CTICmdUnmute, "unmute"},
		{"Dial", CTICmdDial, "dial"},
		{"DTMF", CTICmdDialDTMF, "dtmf"},
		{"BlindTransfer", CTICmdBlindTransfer, "blind_transfer"},
		{"AttendedTransfer", CTICmdAttendTransfer, "attended_transfer"},
		{"Conference", CTICmdConference, "conference"},
		{"DND", CTICmdDND, "dnd"},
		{"Forward", CTICmdForward, "forward"},
		{"Park", CTICmdCallPark, "park"},
		{"Pickup", CTICmdCallPickup, "pickup"},
	}
	
	for _, tt := range commands {
		t.Run(tt.name, func(t *testing.T) {
			if tt.constant != tt.expected {
				t.Errorf("Expected CTI command %s to be '%s', got '%s'", tt.name, tt.expected, tt.constant)
			}
		})
	}
}

// TestSNMPConfigDefaults tests default SNMP configuration values
func TestSNMPConfigDefaults(t *testing.T) {
	config := &SNMPConfig{
		Enabled:   true,
		Community: "public",
		Version:   "v2c",
	}
	
	if !config.Enabled {
		t.Error("SNMP should be enabled")
	}
	
	if config.Community != "public" {
		t.Errorf("Expected community 'public', got '%s'", config.Community)
	}
	
	if config.Version != "v2c" {
		t.Errorf("Expected version 'v2c', got '%s'", config.Version)
	}
}

// TestCTICallStateFields tests CTICallState struct fields
func TestCTICallStateFields(t *testing.T) {
	state := CTICallState{
		LineID:       1,
		CallID:       "call-123",
		State:        "connected",
		Direction:    "inbound",
		RemoteNumber: "1234567890",
		RemoteName:   "Test Caller",
		Duration:     120,
		Muted:        false,
	}
	
	if state.LineID != 1 {
		t.Errorf("Expected LineID 1, got %d", state.LineID)
	}
	
	if state.CallID != "call-123" {
		t.Errorf("Expected CallID 'call-123', got '%s'", state.CallID)
	}
	
	if state.State != "connected" {
		t.Errorf("Expected State 'connected', got '%s'", state.State)
	}
	
	if state.Direction != "inbound" {
		t.Errorf("Expected Direction 'inbound', got '%s'", state.Direction)
	}
	
	if state.Duration != 120 {
		t.Errorf("Expected Duration 120, got %d", state.Duration)
	}
	
	if state.Muted {
		t.Error("Expected Muted false")
	}
}

// TestCTIPhoneStateFields tests CTIPhoneState struct fields
func TestCTIPhoneStateFields(t *testing.T) {
	state := CTIPhoneState{
		DNDEnabled:     true,
		ForwardEnabled: true,
		ForwardTarget:  "102",
		MWI:            true,
		ActiveLine:     2,
		Calls: []CTICallState{
			{LineID: 1, State: "idle"},
			{LineID: 2, State: "connected"},
		},
	}
	
	if !state.DNDEnabled {
		t.Error("Expected DNDEnabled true")
	}
	
	if !state.ForwardEnabled {
		t.Error("Expected ForwardEnabled true")
	}
	
	if state.ForwardTarget != "102" {
		t.Errorf("Expected ForwardTarget '102', got '%s'", state.ForwardTarget)
	}
	
	if !state.MWI {
		t.Error("Expected MWI true")
	}
	
	if state.ActiveLine != 2 {
		t.Errorf("Expected ActiveLine 2, got %d", state.ActiveLine)
	}
	
	if len(state.Calls) != 2 {
		t.Errorf("Expected 2 calls, got %d", len(state.Calls))
	}
}
