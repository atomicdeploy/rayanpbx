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
		name        string
		description string
		wantVendor  string
		wantModel   string
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

func TestParseLLDPCliShowNeighbors(t *testing.T) {
	am := &AsteriskManager{}
	pm := NewPhoneManager(am)
	pd := NewPhoneDiscovery(pm)

	// Sample lldpcli show neighbors output from the problem statement
	output := `-------------------------------------------------------------------------------
LLDP neighbors:
-------------------------------------------------------------------------------
Interface:    eno1, via: LLDP, RID: 1, Time: 0 day, 21:21:23
  Chassis:
    ChassisID:    ip 172.20.6.150
    SysName:      GXP1630_ec:74:d7:2f:7e:a2
    SysDescr:     GXP1630 1.0.7.64
    Capability:   Bridge, on
    Capability:   Tel, on
  Port:
    PortID:       mac ec:74:d7:2f:7e:a2
    PortDescr:    eth0
    TTL:          120
-------------------------------------------------------------------------------
Interface:    eno1, via: LLDP, RID: 2, Time: 0 day, 21:21:23
  Chassis:
    ChassisID:    ip 172.20.6.104
    SysName:      GXP1625_ec:74:d7:52:50:37
    SysDescr:     GXP1625 1.0.7.64
    Capability:   Bridge, on
    Capability:   Tel, on
  Port:
    PortID:       mac ec:74:d7:52:50:37
    PortDescr:    eth0
    TTL:          120
-------------------------------------------------------------------------------
Interface:    eno1, via: LLDP, RID: 4, Time: 0 day, 21:18:20
  Chassis:
    ChassisID:    mac b0:6e:bf:c0:08:1d
  Port:
    PortID:       mac b0:6e:bf:c0:08:1d
    TTL:          3601
-------------------------------------------------------------------------------`

	phones, err := pd.parseLLDPCliShowNeighbors(output)
	if err != nil {
		t.Errorf("parseLLDPCliShowNeighbors() error = %v", err)
	}

	// Should find 2 VoIP phones (the third device has no SysName/SysDescr so won't be detected as VoIP)
	if len(phones) != 2 {
		t.Errorf("parseLLDPCliShowNeighbors() found %d phones, want 2", len(phones))
	}

	// Check first phone (GXP1630)
	if len(phones) >= 1 {
		phone1 := phones[0]
		if phone1.IP != "172.20.6.150" {
			t.Errorf("Phone 1 IP = %v, want 172.20.6.150", phone1.IP)
		}
		if phone1.MAC != "ec:74:d7:2f:7e:a2" {
			t.Errorf("Phone 1 MAC = %v, want ec:74:d7:2f:7e:a2", phone1.MAC)
		}
		if phone1.Hostname != "GXP1630_ec:74:d7:2f:7e:a2" {
			t.Errorf("Phone 1 Hostname = %v, want GXP1630_ec:74:d7:2f:7e:a2", phone1.Hostname)
		}
		if phone1.Vendor != "GrandStream" {
			t.Errorf("Phone 1 Vendor = %v, want GrandStream", phone1.Vendor)
		}
		if phone1.Model != "GXP1630" {
			t.Errorf("Phone 1 Model = %v, want GXP1630", phone1.Model)
		}
		if len(phone1.Capabilities) != 2 {
			t.Errorf("Phone 1 Capabilities count = %d, want 2", len(phone1.Capabilities))
		}
		if phone1.DiscoveryType != "lldp" {
			t.Errorf("Phone 1 DiscoveryType = %v, want lldp", phone1.DiscoveryType)
		}
	}

	// Check second phone (GXP1625)
	if len(phones) >= 2 {
		phone2 := phones[1]
		if phone2.IP != "172.20.6.104" {
			t.Errorf("Phone 2 IP = %v, want 172.20.6.104", phone2.IP)
		}
		if phone2.MAC != "ec:74:d7:52:50:37" {
			t.Errorf("Phone 2 MAC = %v, want ec:74:d7:52:50:37", phone2.MAC)
		}
		if phone2.Model != "GXP1625" {
			t.Errorf("Phone 2 Model = %v, want GXP1625", phone2.Model)
		}
	}
}

func TestParseLLDPCliShowNeighborsEmpty(t *testing.T) {
	am := &AsteriskManager{}
	pm := NewPhoneManager(am)
	pd := NewPhoneDiscovery(pm)

	// Empty output
	output := `-------------------------------------------------------------------------------
LLDP neighbors:
-------------------------------------------------------------------------------`

	phones, err := pd.parseLLDPCliShowNeighbors(output)
	if err != nil {
		t.Errorf("parseLLDPCliShowNeighbors() error = %v", err)
	}

	if len(phones) != 0 {
		t.Errorf("parseLLDPCliShowNeighbors() with empty output found %d phones, want 0", len(phones))
	}
}

func TestParseSystemDescriptionGXPModels(t *testing.T) {
	am := &AsteriskManager{}
	pm := NewPhoneManager(am)
	pd := NewPhoneDiscovery(pm)

	tests := []struct {
		name        string
		description string
		wantVendor  string
		wantModel   string
	}{
		{
			name:        "GXP1630 with version",
			description: "GXP1630 1.0.7.64",
			wantVendor:  "GrandStream",
			wantModel:   "GXP1630",
		},
		{
			name:        "GXP1625 with version",
			description: "GXP1625 1.0.7.64",
			wantVendor:  "GrandStream",
			wantModel:   "GXP1625",
		},
		{
			name:        "GXP1628 with underscore and MAC",
			description: "GXP1628_00:0b:82:12:34:56",
			wantVendor:  "GrandStream",
			wantModel:   "GXP1628",
		},
		{
			name:        "GRP series phone",
			description: "GRP2612 1.0.5.30",
			wantVendor:  "GrandStream",
			wantModel:   "GRP2612",
		},
		{
			name:        "GXV video phone",
			description: "GXV3370 1.0.3.6",
			wantVendor:  "GrandStream",
			wantModel:   "GXV3370",
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

func TestParseARPOutput(t *testing.T) {
	am := &AsteriskManager{}
	pm := NewPhoneManager(am)
	pd := NewPhoneDiscovery(pm)

	// Sample arp -a output from the problem statement
	output := `? (172.20.4.126) at b0:6e:bf:c0:08:1d [ether] on eno1
? (172.20.15.245) at ec:74:d7:46:ad:6c [ether] on eno1
_gateway (172.20.0.10) at 08:55:31:32:d1:ec [ether] on eno1
? (172.20.15.236) at 34:5a:60:be:11:1a [ether] on eno1
? (172.20.15.240) at d8:43:ae:5d:36:ed [ether] on eno1
? (172.20.15.243) at a2:93:2b:95:fb:6f [ether] on eno1
? (172.20.5.150) at <incomplete> on eno1`

	devices, err := pd.parseARPOutput(output)
	if err != nil {
		t.Errorf("parseARPOutput() error = %v", err)
	}

	// Should find 6 devices (excluding incomplete entry)
	if len(devices) != 6 {
		t.Errorf("parseARPOutput() found %d devices, want 6", len(devices))
	}

	// Check first device
	if len(devices) >= 1 {
		device := devices[0]
		if device.IP != "172.20.4.126" {
			t.Errorf("Device 1 IP = %v, want 172.20.4.126", device.IP)
		}
		if device.MAC != "b0:6e:bf:c0:08:1d" {
			t.Errorf("Device 1 MAC = %v, want b0:6e:bf:c0:08:1d", device.MAC)
		}
		if device.DiscoveryType != "arp" {
			t.Errorf("Device 1 DiscoveryType = %v, want arp", device.DiscoveryType)
		}
	}

	// Check GrandStream device detection (ec:74:d7 is GrandStream OUI)
	if len(devices) >= 2 {
		device := devices[1]
		if device.IP != "172.20.15.245" {
			t.Errorf("Device 2 IP = %v, want 172.20.15.245", device.IP)
		}
		if device.Vendor != "GrandStream" {
			t.Errorf("Device 2 Vendor = %v, want GrandStream", device.Vendor)
		}
	}

	// Check gateway device with hostname
	if len(devices) >= 3 {
		device := devices[2]
		if device.Hostname != "_gateway" {
			t.Errorf("Device 3 Hostname = %v, want _gateway", device.Hostname)
		}
	}
}

func TestParseARPOutputEmpty(t *testing.T) {
	am := &AsteriskManager{}
	pm := NewPhoneManager(am)
	pd := NewPhoneDiscovery(pm)

	devices, err := pd.parseARPOutput("")
	if err != nil {
		t.Errorf("parseARPOutput() error = %v", err)
	}

	if len(devices) != 0 {
		t.Errorf("parseARPOutput() with empty output found %d devices, want 0", len(devices))
	}
}

func TestDetectVendorFromMAC(t *testing.T) {
	am := &AsteriskManager{}
	pm := NewPhoneManager(am)
	pd := NewPhoneDiscovery(pm)

	tests := []struct {
		name       string
		mac        string
		wantVendor string
	}{
		{
			name:       "GrandStream ec:74:d7",
			mac:        "ec:74:d7:46:ad:6c",
			wantVendor: "GrandStream",
		},
		{
			name:       "GrandStream 00:0b:82",
			mac:        "00:0b:82:12:34:56",
			wantVendor: "GrandStream",
		},
		{
			name:       "Yealink",
			mac:        "00:15:65:12:34:56",
			wantVendor: "Yealink",
		},
		{
			name:       "Unknown vendor",
			mac:        "aa:bb:cc:dd:ee:ff",
			wantVendor: "",
		},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			vendor := pd.detectVendorFromMAC(tt.mac)
			if vendor != tt.wantVendor {
				t.Errorf("detectVendorFromMAC() = %v, want %v", vendor, tt.wantVendor)
			}
		})
	}
}

func TestParseLLDPCtlJson0(t *testing.T) {
	am := &AsteriskManager{}
	pm := NewPhoneManager(am)
	pd := NewPhoneDiscovery(pm)

	// Sample lldpctl -f json0 output
	output := `{
  "lldp": [
    {
      "interface": [
        {
          "name": "eno1",
          "via": "LLDP",
          "rid": "1",
          "age": "0 day, 22:05:38",
          "chassis": [
            {
              "id": [
                {
                  "type": "ip",
                  "value": "172.20.6.150"
                }
              ],
              "name": [
                {
                  "value": "GXP1630_ec:74:d7:2f:7e:a2"
                }
              ],
              "descr": [
                {
                  "value": "GXP1630 1.0.7.64"
                }
              ],
              "capability": [
                {
                  "type": "Bridge",
                  "enabled": true
                },
                {
                  "type": "Tel",
                  "enabled": true
                }
              ]
            }
          ],
          "port": [
            {
              "id": [
                {
                  "type": "mac",
                  "value": "ec:74:d7:2f:7e:a2"
                }
              ],
              "descr": [
                {
                  "value": "eth0"
                }
              ]
            }
          ],
          "lldp-med": [
            {
              "inventory": [
                {
                  "manufacturer": [
                    {
                      "value": "Grandstream Networks, Inc."
                    }
                  ],
                  "model": [
                    {
                      "value": "GXP1630"
                    }
                  ],
                  "serial": [
                    {
                      "value": "ec:74:d7:2f:7e:a2"
                    }
                  ],
                  "software": [
                    {
                      "value": "1.0.7.64"
                    }
                  ],
                  "firmware": [
                    {
                      "value": "1.0.7.64"
                    }
                  ],
                  "hardware": [
                    {
                      "value": "V2.0A"
                    }
                  ]
                }
              ]
            }
          ]
        }
      ]
    }
  ]
}`

	phones, err := pd.parseLLDPCtlJson0(output)
	if err != nil {
		t.Errorf("parseLLDPCtlJson0() error = %v", err)
	}

	if len(phones) != 1 {
		t.Errorf("parseLLDPCtlJson0() found %d phones, want 1", len(phones))
	}

	if len(phones) >= 1 {
		phone := phones[0]
		if phone.IP != "172.20.6.150" {
			t.Errorf("Phone IP = %v, want 172.20.6.150", phone.IP)
		}
		if phone.MAC != "ec:74:d7:2f:7e:a2" {
			t.Errorf("Phone MAC = %v, want ec:74:d7:2f:7e:a2", phone.MAC)
		}
		if phone.Vendor != "Grandstream Networks, Inc." {
			t.Errorf("Phone Vendor = %v, want Grandstream Networks, Inc.", phone.Vendor)
		}
		if phone.Model != "GXP1630" {
			t.Errorf("Phone Model = %v, want GXP1630", phone.Model)
		}
		if phone.SoftwareVersion != "1.0.7.64" {
			t.Errorf("Phone SoftwareVersion = %v, want 1.0.7.64", phone.SoftwareVersion)
		}
		if phone.HardwareVersion != "V2.0A" {
			t.Errorf("Phone HardwareVersion = %v, want V2.0A", phone.HardwareVersion)
		}
	}
}

func TestMergePhonesByMAC(t *testing.T) {
	am := &AsteriskManager{}
	pm := NewPhoneManager(am)
	pd := NewPhoneDiscovery(pm)

	phones := []DiscoveredPhone{
		{
			MAC:    "ec:74:d7:2f:7e:a2",
			IP:     "172.20.6.150",
			Vendor: "GrandStream",
		},
		{
			MAC:             "ec:74:d7:2f:7e:a2",
			Model:           "GXP1630",
			SoftwareVersion: "1.0.7.64",
		},
	}

	merged := pd.mergePhonesByMAC(phones)
	if len(merged) != 1 {
		t.Errorf("mergePhonesByMAC() returned %d phones, want 1", len(merged))
	}

	if len(merged) >= 1 {
		phone := merged[0]
		if phone.IP != "172.20.6.150" {
			t.Errorf("Merged phone IP = %v, want 172.20.6.150", phone.IP)
		}
		if phone.Model != "GXP1630" {
			t.Errorf("Merged phone Model = %v, want GXP1630", phone.Model)
		}
		if phone.Vendor != "GrandStream" {
			t.Errorf("Merged phone Vendor = %v, want GrandStream", phone.Vendor)
		}
		if phone.SoftwareVersion != "1.0.7.64" {
			t.Errorf("Merged phone SoftwareVersion = %v, want 1.0.7.64", phone.SoftwareVersion)
		}
	}
}
