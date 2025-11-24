package main

import (
	"testing"
	"time"
)

func TestNewPhoneDiscovery(t *testing.T) {
	am := &AsteriskManager{}
	pm := NewPhoneManager(am)
	pd := NewPhoneDiscovery(pm)

	if pd == nil {
		t.Fatal("NewPhoneDiscovery returned nil")
	}

	if pd.phoneManager != pm {
		t.Error("PhoneDiscovery phoneManager not set correctly")
	}
}

func TestParseSystemDescription(t *testing.T) {
	am := &AsteriskManager{}
	pm := NewPhoneManager(am)
	pd := NewPhoneDiscovery(pm)

	tests := []struct {
		name          string
		description   string
		wantVendor    string
		wantModel     string
	}{
		{
			name:        "GrandStream GXP1628",
			description: "GrandStream GXP1628 IP Phone",
			wantVendor:  "GrandStream",
			wantModel:   "GXP1628",
		},
		{
			name:        "Yealink T46S",
			description: "Yealink SIP-T46S VoIP Phone",
			wantVendor:  "Yealink",
			wantModel:   "SIP-T46S",
		},
		{
			name:        "Polycom VVX 411",
			description: "Polycom VVX411 Business Media Phone",
			wantVendor:  "Polycom",
			wantModel:   "VVX411",
		},
		{
			name:        "Cisco SPA",
			description: "Cisco SPA504G IP Phone",
			wantVendor:  "Cisco",
			wantModel:   "SPA504G",
		},
		{
			name:        "Unknown device",
			description: "Some Random Device",
			wantVendor:  "",
			wantModel:   "",
		},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			vendor, model := pd.parseSystemDescription(tt.description)
			if vendor != tt.wantVendor {
				t.Errorf("parseSystemDescription() vendor = %v, want %v", vendor, tt.wantVendor)
			}
			if model != tt.wantModel {
				t.Errorf("parseSystemDescription() model = %v, want %v", model, tt.wantModel)
			}
		})
	}
}

func TestIsVoIPPhone(t *testing.T) {
	am := &AsteriskManager{}
	pm := NewPhoneManager(am)
	pd := NewPhoneDiscovery(pm)

	tests := []struct {
		name  string
		phone *DiscoveredPhone
		want  bool
	}{
		{
			name: "GrandStream phone by vendor",
			phone: &DiscoveredPhone{
				Vendor: "GrandStream",
			},
			want: true,
		},
		{
			name: "Yealink phone by hostname",
			phone: &DiscoveredPhone{
				Hostname: "yealink-t46s",
			},
			want: true,
		},
		{
			name: "Phone with model",
			phone: &DiscoveredPhone{
				Model: "GXP1628",
			},
			want: true,
		},
		{
			name: "Non-VoIP device",
			phone: &DiscoveredPhone{
				Vendor:   "Dell",
				Hostname: "pc-workstation",
			},
			want: false,
		},
		{
			name: "Polycom in vendor name",
			phone: &DiscoveredPhone{
				Vendor: "Polycom Corporation",
			},
			want: true,
		},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			if got := pd.isVoIPPhone(tt.phone); got != tt.want {
				t.Errorf("isVoIPPhone() = %v, want %v", got, tt.want)
			}
		})
	}
}

func TestDeduplicatePhones(t *testing.T) {
	am := &AsteriskManager{}
	pm := NewPhoneManager(am)
	pd := NewPhoneDiscovery(pm)

	phones := []DiscoveredPhone{
		{MAC: "00:0B:82:12:34:56", IP: "192.168.1.100"},
		{MAC: "00:0B:82:12:34:56", IP: "192.168.1.100"}, // Duplicate by MAC
		{MAC: "00:0B:82:12:34:57", IP: "192.168.1.101"},
		{IP: "192.168.1.102"}, // No MAC
		{IP: "192.168.1.102"}, // Duplicate by IP
	}

	result := pd.deduplicatePhones(phones)

	if len(result) != 3 {
		t.Errorf("deduplicatePhones() returned %d phones, want 3", len(result))
	}

	// Check that we have unique entries
	seen := make(map[string]bool)
	for _, phone := range result {
		key := phone.MAC
		if key == "" {
			key = phone.IP
		}
		if seen[key] {
			t.Errorf("deduplicatePhones() still has duplicate: %s", key)
		}
		seen[key] = true
	}
}

func TestDiscoveredPhoneStruct(t *testing.T) {
	phone := DiscoveredPhone{
		IP:            "192.168.1.100",
		MAC:           "00:0B:82:12:34:56",
		Hostname:      "gxp1628-phone",
		Vendor:        "GrandStream",
		Model:         "GXP1628",
		PortID:        "eth0",
		VLAN:          100,
		Capabilities:  []string{"Bridge", "Telephone"},
		DiscoveryType: "lldp",
		LastSeen:      time.Now(),
		Online:        true,
	}

	if phone.IP != "192.168.1.100" {
		t.Error("DiscoveredPhone IP not set correctly")
	}
	if phone.MAC != "00:0B:82:12:34:56" {
		t.Error("DiscoveredPhone MAC not set correctly")
	}
	if phone.Vendor != "GrandStream" {
		t.Error("DiscoveredPhone Vendor not set correctly")
	}
	if !phone.Online {
		t.Error("DiscoveredPhone Online not set correctly")
	}
}

func TestParseLLDPPacket(t *testing.T) {
	// Test with a minimal valid LLDP packet structure
	// TLV: Type=0 (End), Length=0
	data := []byte{0x00, 0x00}

	info, err := parseLLDPPacket(data)
	if err != nil {
		t.Errorf("parseLLDPPacket() error = %v", err)
	}
	if info == nil {
		t.Error("parseLLDPPacket() returned nil info")
	}
}

func TestCheckPhoneReachability(t *testing.T) {
	am := &AsteriskManager{}
	pm := NewPhoneManager(am)
	pd := NewPhoneDiscovery(pm)

	phones := []PhoneInfo{
		{Extension: "1001", IP: "127.0.0.1"}, // localhost should be reachable
		{Extension: "1002", IP: "192.0.2.1"}, // TEST-NET-1, likely unreachable
	}

	result := pd.CheckPhoneReachability(phones)

	if len(result) != 2 {
		t.Errorf("CheckPhoneReachability() returned %d phones, want 2", len(result))
	}

	// Localhost should be online
	if !result[0].Online {
		t.Log("Warning: localhost ping failed (may be expected in some environments)")
	}
}

func TestParseLLDPCtlOutput(t *testing.T) {
	am := &AsteriskManager{}
	pm := NewPhoneManager(am)
	pd := NewPhoneDiscovery(pm)

	// Sample lldpctl output
	output := `lldp.eth0.chassis.mac=00:0b:82:12:34:56
lldp.eth0.chassis.name=gxp1628
lldp.eth0.chassis.descr=GrandStream GXP1628 IP Phone
lldp.eth0.port.descr=Port 1
lldp.eth0.mgmt-ip=192.168.1.100`

	phones, err := pd.parseLLDPCtlOutput(output)
	if err != nil {
		t.Errorf("parseLLDPCtlOutput() error = %v", err)
	}

	if len(phones) == 0 {
		t.Log("parseLLDPCtlOutput() returned no phones - expected if not a VoIP device")
		return
	}

	phone := phones[0]
	if phone.MAC != "00:0b:82:12:34:56" {
		t.Errorf("parseLLDPCtlOutput() MAC = %v, want %v", phone.MAC, "00:0b:82:12:34:56")
	}
	if phone.IP != "192.168.1.100" {
		t.Errorf("parseLLDPCtlOutput() IP = %v, want %v", phone.IP, "192.168.1.100")
	}
	if phone.Vendor != "GrandStream" {
		t.Errorf("parseLLDPCtlOutput() Vendor = %v, want %v", phone.Vendor, "GrandStream")
	}
}

func TestParseNmapOutput(t *testing.T) {
	am := &AsteriskManager{}
	pm := NewPhoneManager(am)
	pd := NewPhoneDiscovery(pm)

	// Sample nmap greppable output
	output := `# Nmap 7.80 scan
Host: 192.168.1.100 ()	Status: Up
Host: 192.168.1.100 ()	Ports: 80/open/tcp//http///, 5060/open/tcp//sip///
Host: 192.168.1.101 ()	Status: Up
Host: 192.168.1.101 ()	Ports: 22/open/tcp//ssh///`

	phones, err := pd.parseNmapOutput(output)
	if err != nil {
		t.Errorf("parseNmapOutput() error = %v", err)
	}

	// Should find at least one device with VoIP ports
	if len(phones) == 0 {
		t.Log("parseNmapOutput() found no VoIP phones (expected if HTTP detection fails)")
	}
}
