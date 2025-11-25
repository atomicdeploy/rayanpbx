package main

import (
	"encoding/binary"
	"encoding/json"
	"fmt"
	"net"
	"os/exec"
	"regexp"
	"strconv"
	"strings"
	"time"
)

// Discovery constants
const (
	DefaultPingTimeout    = 2  // seconds
	LLDPCaptureTimeout    = 10 // seconds
	LLDPCapturePackets    = 10 // number of packets to capture
	DefaultNetworkSubnet  = "192.168.1.0/24"
)

// VoIP vendor list
var voipVendors = []string{"grandstream", "yealink", "polycom", "cisco", "snom", "panasonic", "fanvil"}

// Pre-compiled regular expressions for performance
var (
	// GrandStream model patterns: GXP, GRP, GXV, DP, WP, GAC, HT series
	grandstreamModelRegex = regexp.MustCompile(`(?i)\b(gxp|grp|gxv|dp|wp|gac|ht)\d+[a-z0-9]*`)
	// Other vendor model patterns
	yealinkModelRegex   = regexp.MustCompile(`(?i)sip-t\d+[a-z]*`)
	polycomModelRegex   = regexp.MustCompile(`(?i)(soundpoint|vvx\d+[a-z]*)`)
	ciscoModelRegex     = regexp.MustCompile(`(?i)(cp-\d+[a-z]*|spa\d+[a-z]*)`)
	snomModelRegex      = regexp.MustCompile(`(?i)snom\d+[a-z]*`)
	panasonicModelRegex = regexp.MustCompile(`(?i)kx-[\w]+`)
	fanvilModelRegex    = regexp.MustCompile(`(?i)x\d+[a-z]*`)
)

// DiscoveredPhone represents a phone discovered on the network
type DiscoveredPhone struct {
	IP              string    `json:"ip"`
	MAC             string    `json:"mac"`
	Hostname        string    `json:"hostname"`
	Vendor          string    `json:"vendor"`
	Model           string    `json:"model"`
	PortID          string    `json:"port_id"`
	VLAN            int       `json:"vlan"`
	Capabilities    []string  `json:"capabilities"`
	DiscoveryType   string    `json:"discovery_type"` // "lldp", "nmap", "http", "arp"
	LastSeen        time.Time `json:"last_seen"`
	Online          bool      `json:"online"`
	Serial          string    `json:"serial,omitempty"`
	SoftwareVersion string    `json:"software_version,omitempty"`
	FirmwareVersion string    `json:"firmware_version,omitempty"`
	HardwareVersion string    `json:"hardware_version,omitempty"`
}

// PhoneDiscovery handles discovery of VoIP phones on the network
type PhoneDiscovery struct {
	phoneManager *PhoneManager
}

// NewPhoneDiscovery creates a new phone discovery instance
func NewPhoneDiscovery(phoneManager *PhoneManager) *PhoneDiscovery {
	return &PhoneDiscovery{
		phoneManager: phoneManager,
	}
}

// LLDP TLV (Type-Length-Value) constants
const (
	LLDPTLVChassisID    = 1
	LLDPTLVPortID       = 2
	LLDPTLVTTL          = 3
	LLDPTLVPortDesc     = 4
	LLDPTLVSystemName   = 5
	LLDPTLVSystemDesc   = 6
	LLDPTLVSystemCap    = 7
	LLDPTLVMgmtAddr     = 8
	LLDPTLVOrgSpecific  = 127
	LLDPTLVEnd          = 0
)

// LLDPInfo represents parsed LLDP information
type LLDPInfo struct {
	ChassisID   string
	PortID      string
	TTL         uint16
	SystemName  string
	SystemDesc  string
	MgmtAddr    string
	PortDesc    string
	Capabilities []string
}

// DiscoverPhones discovers VoIP phones on the network using multiple methods
func (pd *PhoneDiscovery) DiscoverPhones(network string) ([]DiscoveredPhone, error) {
	var phones []DiscoveredPhone

	// Try LLDP discovery first (requires root/sudo)
	lldpPhones, err := pd.discoverViaLLDP()
	if err == nil {
		phones = append(phones, lldpPhones...)
	}

	// Try ARP table discovery
	arpPhones, err := pd.discoverViaARP()
	if err == nil {
		phones = append(phones, arpPhones...)
	}

	// Fallback to network scanning
	scanPhones, err := pd.discoverViaNmap(network)
	if err == nil {
		phones = append(phones, scanPhones...)
	}

	// Deduplicate by MAC address
	phones = pd.deduplicatePhones(phones)

	// Check reachability for all discovered phones
	for i := range phones {
		phones[i].Online = pd.PingHost(phones[i].IP, 2)
	}

	return phones, nil
}

// discoverViaLLDP discovers phones using LLDP protocol
// Runs multiple lldpctl formats and merges results for maximum data
func (pd *PhoneDiscovery) discoverViaLLDP() ([]DiscoveredPhone, error) {
	var allPhones []DiscoveredPhone

	// Try json0 format first (most structured and verbose, easiest to parse)
	output, err := exec.Command("lldpctl", "-f", "json0").Output()
	if err == nil {
		phones, parseErr := pd.parseLLDPCtlJson0(string(output))
		if parseErr == nil && len(phones) > 0 {
			allPhones = append(allPhones, phones...)
		}
	}

	// Try plain format (default, human-readable)
	output, err = exec.Command("lldpctl", "-f", "plain").Output()
	if err == nil {
		phones, parseErr := pd.parseLLDPCliShowNeighbors(string(output))
		if parseErr == nil && len(phones) > 0 {
			allPhones = append(allPhones, phones...)
		}
	}

	// Try json format as fallback
	output, err = exec.Command("lldpctl", "-f", "json").Output()
	if err == nil {
		phones, parseErr := pd.parseLLDPCtlJson(string(output))
		if parseErr == nil && len(phones) > 0 {
			allPhones = append(allPhones, phones...)
		}
	}

	// NOTE: lldpcli show neighbors is disabled by default
	// It provides similar data to plain format but with different parsing
	// Uncomment below if needed:
	// output, err = exec.Command("lldpcli", "show", "neighbors").Output()
	// if err == nil {
	//     phones, parseErr := pd.parseLLDPCliShowNeighbors(string(output))
	//     if parseErr == nil && len(phones) > 0 {
	//         allPhones = append(allPhones, phones...)
	//     }
	// }

	// Fallback to keyvalue format
	output, err = exec.Command("lldpctl", "-f", "keyvalue").Output()
	if err == nil {
		phones, parseErr := pd.parseLLDPCtlOutput(string(output))
		if parseErr == nil && len(phones) > 0 {
			allPhones = append(allPhones, phones...)
		}
	}

	// If nothing worked, try tcpdump approach
	if len(allPhones) == 0 {
		return pd.captureLLDPPackets()
	}

	// Deduplicate and merge device data by MAC address
	return pd.mergePhonesByMAC(allPhones), nil
}

// parseLLDPCtlJson0 parses lldpctl -f json0 output (most verbose JSON format)
func (pd *PhoneDiscovery) parseLLDPCtlJson0(output string) ([]DiscoveredPhone, error) {
	var phones []DiscoveredPhone

	var data struct {
		LLDP []struct {
			Interface []struct {
				Name    string `json:"name"`
				Via     string `json:"via"`
				Chassis []struct {
					ID []struct {
						Type  string `json:"type"`
						Value string `json:"value"`
					} `json:"id"`
					Name []struct {
						Value string `json:"value"`
					} `json:"name"`
					Descr []struct {
						Value string `json:"value"`
					} `json:"descr"`
					Capability []struct {
						Type    string `json:"type"`
						Enabled bool   `json:"enabled"`
					} `json:"capability"`
				} `json:"chassis"`
				Port []struct {
					ID []struct {
						Type  string `json:"type"`
						Value string `json:"value"`
					} `json:"id"`
					Descr []struct {
						Value string `json:"value"`
					} `json:"descr"`
				} `json:"port"`
				LLDPMed []struct {
					Inventory []struct {
						Manufacturer []struct {
							Value string `json:"value"`
						} `json:"manufacturer"`
						Model []struct {
							Value string `json:"value"`
						} `json:"model"`
						Serial []struct {
							Value string `json:"value"`
						} `json:"serial"`
						Software []struct {
							Value string `json:"value"`
						} `json:"software"`
						Firmware []struct {
							Value string `json:"value"`
						} `json:"firmware"`
						Hardware []struct {
							Value string `json:"value"`
						} `json:"hardware"`
					} `json:"inventory"`
				} `json:"lldp-med"`
			} `json:"interface"`
		} `json:"lldp"`
	}

	if err := json.Unmarshal([]byte(output), &data); err != nil {
		return phones, err
	}

	for _, lldp := range data.LLDP {
		for _, iface := range lldp.Interface {
			phone := DiscoveredPhone{
				DiscoveryType: "lldp",
				LastSeen:      time.Now(),
			}

			// Parse chassis info
			for _, chassis := range iface.Chassis {
				for _, id := range chassis.ID {
					if id.Type == "ip" {
						phone.IP = id.Value
					} else if id.Type == "mac" {
						phone.MAC = id.Value
					}
				}
				for _, name := range chassis.Name {
					phone.Hostname = name.Value
				}
				for _, descr := range chassis.Descr {
					phone.Vendor, phone.Model = pd.parseSystemDescription(descr.Value)
				}
				for _, cap := range chassis.Capability {
					if cap.Enabled {
						phone.Capabilities = append(phone.Capabilities, cap.Type)
					}
				}
			}

			// Parse port info
			for _, port := range iface.Port {
				for _, id := range port.ID {
					if id.Type == "mac" && phone.MAC == "" {
						phone.MAC = id.Value
					}
				}
				for _, descr := range port.Descr {
					phone.PortID = descr.Value
				}
			}

			// Parse LLDP-MED inventory
			for _, med := range iface.LLDPMed {
				for _, inv := range med.Inventory {
					for _, mfg := range inv.Manufacturer {
						phone.Vendor = mfg.Value
					}
					for _, mdl := range inv.Model {
						phone.Model = mdl.Value
					}
					for _, srl := range inv.Serial {
						phone.Serial = srl.Value
					}
					for _, sw := range inv.Software {
						phone.SoftwareVersion = sw.Value
					}
					for _, fw := range inv.Firmware {
						phone.FirmwareVersion = fw.Value
					}
					for _, hw := range inv.Hardware {
						phone.HardwareVersion = hw.Value
					}
				}
			}

			if pd.isVoIPPhone(&phone) && (phone.MAC != "" || phone.IP != "") {
				phones = append(phones, phone)
			}
		}
	}

	return phones, nil
}

// parseLLDPCtlJson parses lldpctl -f json output
func (pd *PhoneDiscovery) parseLLDPCtlJson(output string) ([]DiscoveredPhone, error) {
	var phones []DiscoveredPhone

	var data struct {
		LLDP struct {
			Interface []map[string]struct {
				Via     string `json:"via"`
				Chassis map[string]struct {
					ID struct {
						Type  string `json:"type"`
						Value string `json:"value"`
					} `json:"id"`
					Descr      string `json:"descr"`
					Capability []struct {
						Type    string `json:"type"`
						Enabled bool   `json:"enabled"`
					} `json:"capability"`
				} `json:"chassis"`
				Port struct {
					ID struct {
						Type  string `json:"type"`
						Value string `json:"value"`
					} `json:"id"`
					Descr string `json:"descr"`
				} `json:"port"`
				LLDPMed struct {
					Inventory struct {
						Manufacturer string `json:"manufacturer"`
						Model        string `json:"model"`
						Serial       string `json:"serial"`
						Software     string `json:"software"`
						Firmware     string `json:"firmware"`
						Hardware     string `json:"hardware"`
					} `json:"inventory"`
				} `json:"lldp-med"`
			} `json:"interface"`
		} `json:"lldp"`
	}

	if err := json.Unmarshal([]byte(output), &data); err != nil {
		return phones, err
	}

	for _, ifaceMap := range data.LLDP.Interface {
		for ifaceName, iface := range ifaceMap {
			phone := DiscoveredPhone{
				DiscoveryType: "lldp",
				LastSeen:      time.Now(),
			}

			// Parse chassis info
			for chassisName, chassis := range iface.Chassis {
				if chassis.ID.Type == "ip" {
					phone.IP = chassis.ID.Value
				} else if chassis.ID.Type == "mac" {
					phone.MAC = chassis.ID.Value
				}
				phone.Hostname = chassisName
				phone.Vendor, phone.Model = pd.parseSystemDescription(chassis.Descr)
				for _, cap := range chassis.Capability {
					if cap.Enabled {
						phone.Capabilities = append(phone.Capabilities, cap.Type)
					}
				}
			}

			// Parse port info
			if iface.Port.ID.Type == "mac" && phone.MAC == "" {
				phone.MAC = iface.Port.ID.Value
			}
			phone.PortID = iface.Port.Descr

			// Parse LLDP-MED inventory
			if iface.LLDPMed.Inventory.Manufacturer != "" {
				phone.Vendor = iface.LLDPMed.Inventory.Manufacturer
			}
			if iface.LLDPMed.Inventory.Model != "" {
				phone.Model = iface.LLDPMed.Inventory.Model
			}
			phone.Serial = iface.LLDPMed.Inventory.Serial
			phone.SoftwareVersion = iface.LLDPMed.Inventory.Software
			phone.FirmwareVersion = iface.LLDPMed.Inventory.Firmware
			phone.HardwareVersion = iface.LLDPMed.Inventory.Hardware

			// Unused variable to avoid compilation error
			_ = ifaceName

			if pd.isVoIPPhone(&phone) && (phone.MAC != "" || phone.IP != "") {
				phones = append(phones, phone)
			}
		}
	}

	return phones, nil
}

// mergePhonesByMAC merges phones by MAC address, combining data from multiple sources
func (pd *PhoneDiscovery) mergePhonesByMAC(phones []DiscoveredPhone) []DiscoveredPhone {
	merged := make(map[string]*DiscoveredPhone)

	for _, phone := range phones {
		key := phone.MAC
		if key == "" {
			key = phone.IP
		}
		if key == "" {
			continue
		}

		if existing, ok := merged[key]; ok {
			// Merge data, preferring non-empty values
			if phone.IP != "" && existing.IP == "" {
				existing.IP = phone.IP
			}
			if phone.Hostname != "" && existing.Hostname == "" {
				existing.Hostname = phone.Hostname
			}
			if phone.Vendor != "" && existing.Vendor == "" {
				existing.Vendor = phone.Vendor
			}
			if phone.Model != "" && existing.Model == "" {
				existing.Model = phone.Model
			}
			if phone.PortID != "" && existing.PortID == "" {
				existing.PortID = phone.PortID
			}
			if phone.Serial != "" && existing.Serial == "" {
				existing.Serial = phone.Serial
			}
			if phone.SoftwareVersion != "" && existing.SoftwareVersion == "" {
				existing.SoftwareVersion = phone.SoftwareVersion
			}
			if phone.FirmwareVersion != "" && existing.FirmwareVersion == "" {
				existing.FirmwareVersion = phone.FirmwareVersion
			}
			if phone.HardwareVersion != "" && existing.HardwareVersion == "" {
				existing.HardwareVersion = phone.HardwareVersion
			}
			// Merge capabilities
			capMap := make(map[string]bool)
			for _, cap := range existing.Capabilities {
				capMap[cap] = true
			}
			for _, cap := range phone.Capabilities {
				if !capMap[cap] {
					existing.Capabilities = append(existing.Capabilities, cap)
				}
			}
		} else {
			phoneCopy := phone
			merged[key] = &phoneCopy
		}
	}

	result := make([]DiscoveredPhone, 0, len(merged))
	for _, phone := range merged {
		result = append(result, *phone)
	}
	return result
}

// parseLLDPCtlOutput parses lldpctl keyvalue output
func (pd *PhoneDiscovery) parseLLDPCtlOutput(output string) ([]DiscoveredPhone, error) {
	var phones []DiscoveredPhone
	phoneMap := make(map[string]*DiscoveredPhone)

	lines := strings.Split(output, "\n")
	var currentInterface string

	for _, line := range lines {
		line = strings.TrimSpace(line)
		if line == "" {
			continue
		}

		parts := strings.SplitN(line, "=", 2)
		if len(parts) != 2 {
			continue
		}

		key := parts[0]
		value := parts[1]

		// Extract interface name
		if strings.HasPrefix(key, "lldp.") {
			interfaceMatch := regexp.MustCompile(`lldp\.([^.]+)\.`).FindStringSubmatch(key)
			if len(interfaceMatch) > 1 {
				currentInterface = interfaceMatch[1]
			}
		}

		if currentInterface == "" {
			continue
		}

		// Get or create phone entry for this interface
		if _, exists := phoneMap[currentInterface]; !exists {
			phoneMap[currentInterface] = &DiscoveredPhone{
				DiscoveryType: "lldp",
				LastSeen:      time.Now(),
			}
		}

		phone := phoneMap[currentInterface]

		// Parse different LLDP fields
		if strings.Contains(key, ".chassis.mac") {
			phone.MAC = value
		} else if strings.Contains(key, ".chassis.name") {
			phone.Hostname = value
		} else if strings.Contains(key, ".port.descr") {
			phone.PortID = value
		} else if strings.Contains(key, ".mgmt-ip") {
			phone.IP = value
		} else if strings.Contains(key, ".chassis.descr") {
			// Parse system description for vendor/model info
			phone.Vendor, phone.Model = pd.parseSystemDescription(value)
		}
	}

	// Convert map to slice and filter for VoIP phones
	for _, phone := range phoneMap {
		if pd.isVoIPPhone(phone) {
			phones = append(phones, *phone)
		}
	}

	return phones, nil
}

// parseLLDPCliShowNeighbors parses the human-readable output of "lldpcli show neighbors"
// Example format:
// -------------------------------------------------------------------------------
// LLDP neighbors:
// -------------------------------------------------------------------------------
// Interface:    eno1, via: LLDP, RID: 1, Time: 0 day, 21:21:23
//   Chassis:
//     ChassisID:    ip 172.20.6.150
//     SysName:      GXP1630_ec:74:d7:2f:7e:a2
//     SysDescr:     GXP1630 1.0.7.64
//     Capability:   Bridge, on
//     Capability:   Tel, on
//   Port:
//     PortID:       mac ec:74:d7:2f:7e:a2
//     PortDescr:    eth0
//     TTL:          120
func (pd *PhoneDiscovery) parseLLDPCliShowNeighbors(output string) ([]DiscoveredPhone, error) {
	var phones []DiscoveredPhone
	
	lines := strings.Split(output, "\n")
	var currentPhone *DiscoveredPhone
	
	for _, line := range lines {
		trimmed := strings.TrimSpace(line)
		
		// Skip empty lines and separators
		if trimmed == "" || strings.HasPrefix(trimmed, "---") || trimmed == "LLDP neighbors:" {
			continue
		}
		
		// New interface/neighbor block
		if strings.HasPrefix(trimmed, "Interface:") {
			// Save previous phone if it exists and is a VoIP phone
			if currentPhone != nil && pd.isVoIPPhone(currentPhone) {
				phones = append(phones, *currentPhone)
			}
			
			currentPhone = &DiscoveredPhone{
				DiscoveryType: "lldp",
				LastSeen:      time.Now(),
			}
			continue
		}
		
		if currentPhone == nil {
			continue
		}
		
		// Parse ChassisID - can be "ip X.X.X.X" or "mac XX:XX:XX:XX:XX:XX"
		if strings.HasPrefix(trimmed, "ChassisID:") {
			value := strings.TrimPrefix(trimmed, "ChassisID:")
			value = strings.TrimSpace(value)
			
			if strings.HasPrefix(value, "ip ") {
				// ChassisID is an IP address
				ip := strings.TrimPrefix(value, "ip ")
				currentPhone.IP = strings.TrimSpace(ip)
			} else if strings.HasPrefix(value, "mac ") {
				// ChassisID is a MAC address
				mac := strings.TrimPrefix(value, "mac ")
				currentPhone.MAC = strings.TrimSpace(mac)
			}
			continue
		}
		
		// Parse SysName - e.g., "GXP1630_ec:74:d7:2f:7e:a2"
		if strings.HasPrefix(trimmed, "SysName:") {
			value := strings.TrimPrefix(trimmed, "SysName:")
			currentPhone.Hostname = strings.TrimSpace(value)
			
			// Try to extract vendor/model from SysName (e.g., "GXP1630_ec:74:d7:2f:7e:a2")
			if currentPhone.Vendor == "" || currentPhone.Model == "" {
				vendor, model := pd.parseSystemDescription(currentPhone.Hostname)
				if vendor != "" {
					currentPhone.Vendor = vendor
				}
				if model != "" {
					currentPhone.Model = model
				}
			}
			continue
		}
		
		// Parse SysDescr - e.g., "GXP1630 1.0.7.64"
		if strings.HasPrefix(trimmed, "SysDescr:") {
			value := strings.TrimPrefix(trimmed, "SysDescr:")
			value = strings.TrimSpace(value)
			vendor, model := pd.parseSystemDescription(value)
			if vendor != "" {
				currentPhone.Vendor = vendor
			}
			if model != "" {
				currentPhone.Model = model
			}
			continue
		}
		
		// Parse Capability - e.g., "Bridge, on" or "Tel, on"
		if strings.HasPrefix(trimmed, "Capability:") {
			value := strings.TrimPrefix(trimmed, "Capability:")
			value = strings.TrimSpace(value)
			// Parse "Bridge, on" format
			parts := strings.Split(value, ",")
			if len(parts) >= 2 && strings.TrimSpace(parts[1]) == "on" {
				cap := strings.TrimSpace(parts[0])
				currentPhone.Capabilities = append(currentPhone.Capabilities, cap)
			}
			continue
		}
		
		// Parse PortID - e.g., "mac ec:74:d7:2f:7e:a2"
		if strings.HasPrefix(trimmed, "PortID:") {
			value := strings.TrimPrefix(trimmed, "PortID:")
			value = strings.TrimSpace(value)
			if strings.HasPrefix(value, "mac ") {
				mac := strings.TrimPrefix(value, "mac ")
				// If we don't have a MAC from ChassisID, use PortID MAC
				if currentPhone.MAC == "" {
					currentPhone.MAC = strings.TrimSpace(mac)
				}
			}
			currentPhone.PortID = value
			continue
		}
		
		// Parse PortDescr - e.g., "eth0"
		if strings.HasPrefix(trimmed, "PortDescr:") {
			value := strings.TrimPrefix(trimmed, "PortDescr:")
			if currentPhone.PortID == "" {
				currentPhone.PortID = strings.TrimSpace(value)
			}
			continue
		}
		
		// Parse TTL
		if strings.HasPrefix(trimmed, "TTL:") {
			// TTL is available but we don't store it currently
			continue
		}
	}
	
	// Don't forget the last phone
	if currentPhone != nil && pd.isVoIPPhone(currentPhone) {
		phones = append(phones, *currentPhone)
	}
	
	return phones, nil
}

// captureLLDPPackets captures LLDP packets using tcpdump (requires root)
func (pd *PhoneDiscovery) captureLLDPPackets() ([]DiscoveredPhone, error) {
	// LLDP uses multicast MAC 01:80:c2:00:00:0e and ethertype 0x88cc
	// This is a simplified implementation
	// In production, you'd want to use a proper packet capture library
	
	cmd := exec.Command("timeout", strconv.Itoa(LLDPCaptureTimeout), "tcpdump", 
		"-nn", "-v", "-c", strconv.Itoa(LLDPCapturePackets), 
		"-i", "any",
		"ether proto 0x88cc")
	
	output, err := cmd.Output()
	if err != nil {
		return nil, fmt.Errorf("failed to capture LLDP packets: %w (requires root/sudo)", err)
	}

	// Parse tcpdump output
	return pd.parseTcpdumpLLDP(string(output))
}

// parseTcpdumpLLDP parses tcpdump LLDP output
func (pd *PhoneDiscovery) parseTcpdumpLLDP(output string) ([]DiscoveredPhone, error) {
	var phones []DiscoveredPhone
	phoneMap := make(map[string]*DiscoveredPhone)

	lines := strings.Split(output, "\n")
	var currentMAC string

	for _, line := range lines {
		line = strings.TrimSpace(line)
		
		// Extract MAC address from LLDP frame
		if strings.Contains(line, "LLDP") {
			macMatch := regexp.MustCompile(`([0-9a-fA-F]{2}:[0-9a-fA-F]{2}:[0-9a-fA-F]{2}:[0-9a-fA-F]{2}:[0-9a-fA-F]{2}:[0-9a-fA-F]{2})`).FindStringSubmatch(line)
			if len(macMatch) > 1 {
				currentMAC = strings.ToUpper(macMatch[1])
				if _, exists := phoneMap[currentMAC]; !exists {
					phoneMap[currentMAC] = &DiscoveredPhone{
						MAC:           currentMAC,
						DiscoveryType: "lldp",
						LastSeen:      time.Now(),
					}
				}
			}
		}

		if currentMAC == "" {
			continue
		}

		phone := phoneMap[currentMAC]

		// Parse LLDP fields from tcpdump output
		if strings.Contains(line, "System Name TLV") {
			nameMatch := regexp.MustCompile(`System Name TLV.*'([^']+)'`).FindStringSubmatch(line)
			if len(nameMatch) > 1 {
				phone.Hostname = nameMatch[1]
			}
		} else if strings.Contains(line, "System Description TLV") {
			descMatch := regexp.MustCompile(`System Description TLV.*'([^']+)'`).FindStringSubmatch(line)
			if len(descMatch) > 1 {
				phone.Vendor, phone.Model = pd.parseSystemDescription(descMatch[1])
			}
		} else if strings.Contains(line, "Management Address TLV") {
			ipMatch := regexp.MustCompile(`(\d+\.\d+\.\d+\.\d+)`).FindStringSubmatch(line)
			if len(ipMatch) > 1 {
				phone.IP = ipMatch[1]
			}
		}
	}

	// Convert map to slice and filter for VoIP phones
	for _, phone := range phoneMap {
		if pd.isVoIPPhone(phone) {
			phones = append(phones, *phone)
		}
	}

	return phones, nil
}

// discoverViaARP discovers devices from the ARP table
// ARP table contains IP to MAC mappings for recently communicated hosts
func (pd *PhoneDiscovery) discoverViaARP() ([]DiscoveredPhone, error) {
	output, err := exec.Command("arp", "-a").Output()
	if err != nil {
		return nil, fmt.Errorf("arp command failed: %w", err)
	}

	return pd.parseARPOutput(string(output))
}

// parseARPOutput parses the output of 'arp -a' command
// Example format:
// ? (172.20.4.126) at b0:6e:bf:c0:08:1d [ether] on eno1
// _gateway (172.20.0.10) at 08:55:31:32:d1:ec [ether] on eno1
func (pd *PhoneDiscovery) parseARPOutput(output string) ([]DiscoveredPhone, error) {
	var phones []DiscoveredPhone

	lines := strings.Split(output, "\n")
	for _, line := range lines {
		line = strings.TrimSpace(line)
		if line == "" {
			continue
		}

		// Parse ARP entry: hostname (IP) at MAC [type] on interface
		// or: ? (IP) at MAC [type] on interface (when hostname unknown)
		
		// Extract IP address from parentheses
		ipStart := strings.Index(line, "(")
		ipEnd := strings.Index(line, ")")
		if ipStart == -1 || ipEnd == -1 || ipEnd <= ipStart {
			continue
		}
		ip := line[ipStart+1 : ipEnd]
		
		// Validate IP address
		if net.ParseIP(ip) == nil {
			continue
		}

		// Extract MAC address after "at "
		atIndex := strings.Index(line, " at ")
		if atIndex == -1 {
			continue
		}
		
		remainder := line[atIndex+4:] // Skip " at "
		parts := strings.Fields(remainder)
		if len(parts) == 0 {
			continue
		}
		
		mac := parts[0]
		
		// Validate MAC address format (should contain colons or hyphens)
		if !strings.Contains(mac, ":") && !strings.Contains(mac, "-") {
			continue
		}
		
		// Skip incomplete entries (shown as <incomplete>)
		if strings.Contains(mac, "<") || strings.Contains(mac, ">") {
			continue
		}

		// Normalize MAC to lowercase with colons
		mac = strings.ToLower(strings.ReplaceAll(mac, "-", ":"))

		// Extract hostname (before the parenthesis)
		hostname := ""
		if ipStart > 0 {
			hostname = strings.TrimSpace(line[:ipStart])
			if hostname == "?" {
				hostname = ""
			}
		}

		// Try to detect vendor from MAC address OUI
		vendor := pd.detectVendorFromMAC(mac)

		phone := DiscoveredPhone{
			IP:            ip,
			MAC:           mac,
			Hostname:      hostname,
			Vendor:        vendor,
			DiscoveryType: "arp",
			LastSeen:      time.Now(),
		}

		phones = append(phones, phone)
	}

	return phones, nil
}

// detectVendorFromMAC attempts to identify the vendor from MAC address OUI
func (pd *PhoneDiscovery) detectVendorFromMAC(mac string) string {
	// Common VoIP phone vendor OUI prefixes
	ouiPrefixes := map[string]string{
		"00:0b:82": "GrandStream",
		"00:19:15": "GrandStream",
		"c0:74:ad": "GrandStream",
		"ec:74:d7": "GrandStream",
		"00:15:65": "Yealink",
		"80:5e:c0": "Yealink",
		"00:04:f2": "Polycom",
		"64:16:7f": "Polycom",
		"00:1e:c2": "Cisco",
		"00:50:c2": "Cisco",
		"00:04:13": "Snom",
		"00:1b:63": "Panasonic",
		"0c:38:3e": "Fanvil",
	}

	// Normalize MAC and get first 3 octets
	mac = strings.ToLower(strings.ReplaceAll(mac, "-", ":"))
	parts := strings.Split(mac, ":")
	if len(parts) >= 3 {
		oui := strings.Join(parts[:3], ":")
		if vendor, ok := ouiPrefixes[oui]; ok {
			return vendor
		}
	}

	return ""
}

// discoverViaNmap discovers phones using nmap network scanning
func (pd *PhoneDiscovery) discoverViaNmap(network string) ([]DiscoveredPhone, error) {
	// Scan for common VoIP phone ports: 80 (HTTP), 5060 (SIP), 443 (HTTPS)
	cmd := exec.Command("nmap", 
		"-sS", // SYN scan
		"-p", "80,443,5060,5061", // Common VoIP ports
		"--open", // Only show open ports
		"-T4", // Faster timing
		"-oG", "-", // Greppable output
		network)

	output, err := cmd.Output()
	if err != nil {
		return nil, fmt.Errorf("nmap scan failed: %w (nmap may not be installed)", err)
	}

	return pd.parseNmapOutput(string(output))
}

// parseNmapOutput parses nmap greppable output
func (pd *PhoneDiscovery) parseNmapOutput(output string) ([]DiscoveredPhone, error) {
	var phones []DiscoveredPhone

	lines := strings.Split(output, "\n")
	for _, line := range lines {
		if !strings.HasPrefix(line, "Host:") {
			continue
		}

		// Parse: Host: 192.168.1.100 ()  Status: Up
		parts := strings.Fields(line)
		if len(parts) < 4 {
			continue
		}

		ip := parts[1]
		
		// Check if it has VoIP-related ports open
		if !strings.Contains(line, "80/open") && 
		   !strings.Contains(line, "443/open") && 
		   !strings.Contains(line, "5060/open") {
			continue
		}

		phone := DiscoveredPhone{
			IP:            ip,
			DiscoveryType: "nmap",
			LastSeen:      time.Now(),
			Online:        true,
		}

		// Try to detect vendor via HTTP
		if vendor, err := pd.phoneManager.DetectPhoneVendor(ip); err == nil {
			phone.Vendor = vendor
		}

		phones = append(phones, phone)
	}

	return phones, nil
}

// parseSystemDescription extracts vendor and model from LLDP system description
func (pd *PhoneDiscovery) parseSystemDescription(desc string) (vendor string, model string) {
	descLower := strings.ToLower(desc)

	// GrandStream patterns - check for model prefix first (e.g., "GXP1630 1.0.7.64")
	// GrandStream models start with GXP, GRP, GXV, DP, WP, GAC, or HT
	if match := grandstreamModelRegex.FindString(desc); match != "" {
		vendor = "GrandStream"
		model = strings.ToUpper(match)
	} else if strings.Contains(descLower, "grandstream") {
		vendor = "GrandStream"
		if match := grandstreamModelRegex.FindString(descLower); match != "" {
			model = strings.ToUpper(match)
		}
	} else if strings.Contains(descLower, "yealink") {
		vendor = "Yealink"
		if match := yealinkModelRegex.FindString(descLower); match != "" {
			model = strings.ToUpper(match)
		}
	} else if strings.Contains(descLower, "polycom") {
		vendor = "Polycom"
		if match := polycomModelRegex.FindString(descLower); match != "" {
			model = strings.ToUpper(match)
		}
	} else if strings.Contains(descLower, "cisco") {
		vendor = "Cisco"
		if match := ciscoModelRegex.FindString(descLower); match != "" {
			model = strings.ToUpper(match)
		}
	} else if strings.Contains(descLower, "snom") {
		vendor = "Snom"
		if match := snomModelRegex.FindString(descLower); match != "" {
			model = strings.ToUpper(match)
		}
	} else if strings.Contains(descLower, "panasonic") {
		vendor = "Panasonic"
		if match := panasonicModelRegex.FindString(descLower); match != "" {
			model = strings.ToUpper(match)
		}
	} else if strings.Contains(descLower, "fanvil") {
		vendor = "Fanvil"
		if match := fanvilModelRegex.FindString(desc); match != "" {
			model = strings.ToUpper(match)
		}
	}

	return vendor, model
}

// isVoIPPhone determines if a discovered device is likely a VoIP phone
func (pd *PhoneDiscovery) isVoIPPhone(phone *DiscoveredPhone) bool {
	// Check vendor
	vendorLower := strings.ToLower(phone.Vendor)
	for _, v := range voipVendors {
		if strings.Contains(vendorLower, v) {
			return true
		}
	}

	// Check hostname patterns
	hostLower := strings.ToLower(phone.Hostname)
	for _, v := range voipVendors {
		if strings.Contains(hostLower, v) {
			return true
		}
	}

	// Check model
	if phone.Model != "" {
		return true
	}

	return false
}

// deduplicatePhones removes duplicate phones based on MAC or IP
func (pd *PhoneDiscovery) deduplicatePhones(phones []DiscoveredPhone) []DiscoveredPhone {
	seen := make(map[string]bool)
	result := []DiscoveredPhone{}

	for _, phone := range phones {
		key := phone.MAC
		if key == "" {
			key = phone.IP
		}
		
		if key != "" && !seen[key] {
			seen[key] = true
			result = append(result, phone)
		}
	}

	return result
}

// PingHost checks if a host is reachable using ICMP ping
func (pd *PhoneDiscovery) PingHost(host string, timeoutSec int) bool {
	if timeoutSec <= 0 {
		timeoutSec = DefaultPingTimeout
	}
	// Use system ping command (works on most Unix-like systems)
	cmd := exec.Command("ping", "-c", "1", "-W", strconv.Itoa(timeoutSec), host)
	err := cmd.Run()
	return err == nil
}

// CheckPhoneReachability checks if existing phones are online and reachable
func (pd *PhoneDiscovery) CheckPhoneReachability(phones []PhoneInfo) []PhoneInfo {
	for i := range phones {
		phones[i].Online = pd.PingHost(phones[i].IP, 2)
	}
	return phones
}

// GetLLDPNeighbors returns LLDP neighbors for all interfaces
func (pd *PhoneDiscovery) GetLLDPNeighbors() ([]DiscoveredPhone, error) {
	phones, err := pd.discoverViaLLDP()
	if err != nil {
		return nil, fmt.Errorf("failed to get LLDP neighbors: %w", err)
	}
	return phones, nil
}

// parseLLDPPacket parses a raw LLDP packet (helper for future enhancement)
func parseLLDPPacket(data []byte) (*LLDPInfo, error) {
	info := &LLDPInfo{}
	offset := 0

	for offset < len(data) {
		if offset+2 > len(data) {
			break
		}

		// LLDP TLV format: 7 bits type, 9 bits length
		tlvHeader := binary.BigEndian.Uint16(data[offset : offset+2])
		tlvType := (tlvHeader >> 9) & 0x7F
		tlvLength := int(tlvHeader & 0x1FF)
		
		offset += 2

		if offset+tlvLength > len(data) {
			break
		}

		tlvValue := data[offset : offset+tlvLength]
		offset += tlvLength

		switch tlvType {
		case LLDPTLVChassisID:
			if len(tlvValue) > 1 {
				info.ChassisID = string(tlvValue[1:])
			}
		case LLDPTLVPortID:
			if len(tlvValue) > 1 {
				info.PortID = string(tlvValue[1:])
			}
		case LLDPTLVTTL:
			if len(tlvValue) >= 2 {
				info.TTL = binary.BigEndian.Uint16(tlvValue)
			}
		case LLDPTLVSystemName:
			info.SystemName = string(tlvValue)
		case LLDPTLVSystemDesc:
			info.SystemDesc = string(tlvValue)
		case LLDPTLVMgmtAddr:
			if len(tlvValue) > 5 {
				// Parse management address (simplified)
				addrLen := int(tlvValue[0])
				if addrLen == 5 && tlvValue[1] == 1 { // IPv4
					ip := net.IPv4(tlvValue[2], tlvValue[3], tlvValue[4], tlvValue[5])
					info.MgmtAddr = ip.String()
				}
			}
		case LLDPTLVEnd:
			return info, nil
		}
	}

	return info, nil
}

// ScanSubnet scans a subnet for VoIP phones
func (pd *PhoneDiscovery) ScanSubnet(subnet string) ([]DiscoveredPhone, error) {
	return pd.DiscoverPhones(subnet)
}
