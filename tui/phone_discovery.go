package main

import (
	"encoding/binary"
	"fmt"
	"net"
	"os/exec"
	"regexp"
	"strconv"
	"strings"
	"time"
)

// DiscoveredPhone represents a phone discovered on the network
type DiscoveredPhone struct {
	IP            string    `json:"ip"`
	MAC           string    `json:"mac"`
	Hostname      string    `json:"hostname"`
	Vendor        string    `json:"vendor"`
	Model         string    `json:"model"`
	PortID        string    `json:"port_id"`
	VLAN          int       `json:"vlan"`
	Capabilities  []string  `json:"capabilities"`
	DiscoveryType string    `json:"discovery_type"` // "lldp", "nmap", "http"
	LastSeen      time.Time `json:"last_seen"`
	Online        bool      `json:"online"`
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
func (pd *PhoneDiscovery) discoverViaLLDP() ([]DiscoveredPhone, error) {
	// Use lldpctl command if available (lldpd package)
	output, err := exec.Command("lldpctl", "-f", "keyvalue").Output()
	if err != nil {
		// LLDP daemon not available, try tcpdump approach
		return pd.captureLLDPPackets()
	}

	return pd.parseLLDPCtlOutput(string(output))
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

// captureLLDPPackets captures LLDP packets using tcpdump (requires root)
func (pd *PhoneDiscovery) captureLLDPPackets() ([]DiscoveredPhone, error) {
	// LLDP uses multicast MAC 01:80:c2:00:00:0e and ethertype 0x88cc
	// This is a simplified implementation
	// In production, you'd want to use a proper packet capture library
	
	cmd := exec.Command("timeout", "10", "tcpdump", 
		"-nn", "-v", "-c", "10", 
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
	desc = strings.ToLower(desc)

	// GrandStream patterns
	if strings.Contains(desc, "grandstream") {
		vendor = "GrandStream"
		if match := regexp.MustCompile(`gxp\d+[a-z]*`).FindString(desc); match != "" {
			model = strings.ToUpper(match)
		}
	} else if strings.Contains(desc, "yealink") {
		vendor = "Yealink"
		if match := regexp.MustCompile(`sip-t\d+[a-z]*`).FindString(desc); match != "" {
			model = strings.ToUpper(match)
		}
	} else if strings.Contains(desc, "polycom") {
		vendor = "Polycom"
		if match := regexp.MustCompile(`soundpoint|vvx\d+[a-z]*`).FindString(desc); match != "" {
			model = strings.ToUpper(match)
		}
	} else if strings.Contains(desc, "cisco") {
		vendor = "Cisco"
		if match := regexp.MustCompile(`cp-\d+[a-z]*|spa\d+[a-z]*`).FindString(desc); match != "" {
			model = strings.ToUpper(match)
		}
	} else if strings.Contains(desc, "snom") {
		vendor = "Snom"
		if match := regexp.MustCompile(`snom\d+[a-z]*`).FindString(desc); match != "" {
			model = strings.ToUpper(match)
		}
	} else if strings.Contains(desc, "panasonic") {
		vendor = "Panasonic"
		if match := regexp.MustCompile(`kx-[\w]+`).FindString(desc); match != "" {
			model = strings.ToUpper(match)
		}
	}

	return vendor, model
}

// isVoIPPhone determines if a discovered device is likely a VoIP phone
func (pd *PhoneDiscovery) isVoIPPhone(phone *DiscoveredPhone) bool {
	// Check vendor
	voipVendors := []string{"grandstream", "yealink", "polycom", "cisco", "snom", "panasonic", "fanvil"}
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
