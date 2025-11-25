# VoIP Phone Discovery

RayanPBX provides comprehensive VoIP phone discovery capabilities using industry-standard network protocols. This feature helps you automatically discover, identify, and manage VoIP phones on your network.

## Overview

The phone discovery system uses multiple methods to find VoIP phones:

1. **LLDP (Link Layer Discovery Protocol)** - Discovers phones that advertise themselves via LLDP
2. **Network Scanning (nmap)** - Scans network ranges for devices with VoIP-related ports open
3. **HTTP Detection** - Identifies phone vendors by examining HTTP headers and web interface content
4. **Ping/Reachability** - Checks if discovered or registered phones are online and reachable

## Supported Vendors

The discovery system can identify phones from the following vendors:

- **GrandStream** (GXP series)
- **Yealink** (SIP-T series)
- **Polycom** (VVX series, SoundPoint)
- **Cisco** (CP series, SPA series)
- **Snom**
- **Panasonic** (KX series)
- **Fanvil**

## Features

### 1. LLDP-Based Discovery

LLDP is a vendor-neutral Layer 2 protocol that allows network devices to advertise their identity, capabilities, and neighbors.

**Benefits:**
- Fast and efficient
- Accurate device information (model, MAC address, management IP)
- No network scanning overhead
- Real-time discovery as devices connect

**Requirements:**
- `lldpd` package installed on the server
- LLDP enabled on network switches
- LLDP enabled on VoIP phones (usually enabled by default)

**Installation:**
```bash
# On Ubuntu/Debian
sudo apt-get install lldpd

# On CentOS/RHEL
sudo yum install lldpd

# Start the service
sudo systemctl enable lldpd
sudo systemctl start lldpd
```

### 2. Network Scanning

Network scanning uses `nmap` to discover devices with VoIP-related ports open.

**Scanned Ports:**
- Port 80 (HTTP - Phone web interface)
- Port 443 (HTTPS - Secure web interface)
- Port 5060 (SIP - Session Initiation Protocol)
- Port 5061 (SIP-TLS - Secure SIP)

**Requirements:**
- `nmap` package installed
- Appropriate network permissions
- May require sudo/root for SYN scans

**Installation:**
```bash
# On Ubuntu/Debian
sudo apt-get install nmap

# On CentOS/RHEL
sudo yum install nmap
```

### 3. Reachability Checking

The system can ping phones to verify they are online and reachable on the network.

**Uses:**
- Verify registered phones are still online
- Confirm discovered phones are active
- Monitor phone connectivity status

## Usage

### TUI (Terminal User Interface)

1. Navigate to **VoIP Phones** from the main menu
2. Press **'d'** to open the Phone Discovery screen

#### Discovery Options:

- **'s'** - Scan network using nmap (scans default 192.168.1.0/24 subnet)
- **'l'** - Discover via LLDP protocol
- **'r'** - Check reachability of discovered phones (ping)
- **'a'** - Add selected discovered phone to the managed list
- **↑/↓** - Navigate through discovered phones
- **ESC** - Return to VoIP Phones screen

#### Example Workflow:

```
1. Press 'd' to enter discovery mode
2. Press 'l' to discover phones via LLDP
3. Review discovered phones (shows IP, vendor, model, MAC)
4. Press 'r' to check which phones are online
5. Use ↑/↓ to select a phone
6. Press 'a' to add it to your managed phones
7. Press ESC to return to the main phones list
```

### Web API

#### Discover Phones

```http
POST /api/grandstream/scan
Content-Type: application/json

{
  "network": "192.168.1.0/24"
}
```

**Response:**
```json
{
  "success": true,
  "count": 3,
  "devices": [
    {
      "ip": "192.168.1.100",
      "mac": "00:0B:82:12:34:56",
      "vendor": "GrandStream",
      "model": "GXP1628",
      "hostname": "gxp1628-office",
      "discovery_type": "lldp",
      "online": true,
      "last_seen": "2025-11-24T18:00:00Z"
    }
  ]
}
```

#### List Discovered Devices

```http
GET /api/grandstream/devices?network=192.168.1.0/24
```

#### Ping Phone

```http
POST /api/grandstream/ping
Content-Type: application/json

{
  "ip": "192.168.1.100"
}
```

**Response:**
```json
{
  "success": true,
  "ip": "192.168.1.100",
  "online": true
}
```

#### Check Multiple Phones Reachability

```http
POST /api/grandstream/reachability
Content-Type: application/json

{
  "phones": [
    {"ip": "192.168.1.100"},
    {"ip": "192.168.1.101"},
    {"ip": "192.168.1.102"}
  ]
}
```

**Response:**
```json
{
  "success": true,
  "phones": [
    {"ip": "192.168.1.100", "online": true},
    {"ip": "192.168.1.101", "online": true},
    {"ip": "192.168.1.102", "online": false}
  ]
}
```

## Architecture

### Go Implementation (TUI)

**File:** `tui/phone_discovery.go`

**Key Components:**

1. **PhoneDiscovery** - Main discovery coordinator
   - Manages multiple discovery methods
   - Deduplicates results
   - Checks reachability

2. **DiscoveredPhone** - Represents a discovered device
   ```go
   type DiscoveredPhone struct {
       IP            string
       MAC           string
       Hostname      string
       Vendor        string
       Model         string
       PortID        string
       VLAN          int
       Capabilities  []string
       DiscoveryType string
       LastSeen      time.Time
       Online        bool
   }
   ```

3. **Discovery Methods:**
   - `discoverViaLLDP()` - Uses lldpctl or tcpdump for LLDP
   - `discoverViaNmap()` - Network scanning with nmap
   - `PingHost()` - ICMP ping for reachability

### PHP Implementation (Backend API)

**File:** `backend/app/Services/GrandStreamProvisioningService.php`

**Key Methods:**

1. `discoverPhones($network)` - Main discovery entry point
2. `discoverViaLLDP()` - LLDP-based discovery
3. `discoverViaNmap($network)` - Network scanning
4. `pingHost($ip)` - Check single host reachability
5. `checkPhoneReachability($phones)` - Batch reachability check

## Security Considerations

### LLDP Discovery

- LLDP operates at Layer 2 and requires read access to the LLDP daemon
- For tcpdump-based capture, root/sudo access is required
- Only trusted administrators should have access to discovery functions

### Network Scanning

- Network scanning may trigger security alerts on monitored networks
- Ensure you have permission to scan the target network
- Consider rate limiting to avoid overwhelming network equipment
- nmap SYN scans require root privileges

### Access Control

- Discovery features should be restricted to authenticated users
- API endpoints should require valid authentication tokens
- Consider implementing role-based access control (RBAC)

## Troubleshooting

### LLDP Discovery Not Working

**Problem:** No phones discovered via LLDP

**Solutions:**

1. **Check if lldpd is installed and running:**
   ```bash
   systemctl status lldpd
   ```

2. **Verify LLDP is enabled on network switches**
   ```bash
   # Check LLDP neighbors
   lldpctl
   ```

3. **Ensure phones have LLDP enabled** (check phone web interface)

4. **Check network connectivity** between server and phones

### Network Scanning Issues

**Problem:** nmap scan returns no results or fails

**Solutions:**

1. **Verify nmap is installed:**
   ```bash
   which nmap
   nmap --version
   ```

2. **Check network permissions:**
   ```bash
   # Test with simple ping scan first
   nmap -sn 192.168.1.0/24
   ```

3. **Run with appropriate privileges:**
   ```bash
   # SYN scan requires root
   sudo nmap -sS -p 80,443,5060 192.168.1.0/24
   ```

4. **Verify network subnet is correct**

### Permission Denied Errors

**Problem:** "Permission denied" when running discovery

**Solutions:**

1. **Grant necessary permissions:**
   ```bash
   # Allow specific users to run lldpctl
   sudo usermod -aG lldpd www-data
   
   # Allow nmap without password (use with caution)
   # Add to /etc/sudoers.d/rayanpbx
   www-data ALL=(ALL) NOPASSWD: /usr/bin/nmap
   ```

2. **Use setcap for network operations:**
   ```bash
   sudo setcap cap_net_raw+ep /usr/bin/nmap
   ```

### Phones Not Detected

**Problem:** Phones are on the network but not discovered

**Possible Causes:**

1. **Phones in different VLAN** - Discovery may not cross VLAN boundaries
2. **Firewall blocking** - Check firewall rules for SIP/HTTP ports
3. **Phones not registered** - Unregistered phones may not advertise via LLDP
4. **Wrong subnet** - Verify network range includes the phones

## Best Practices

### Network Organization

1. **Place phones in a dedicated VLAN** for easier discovery and management
2. **Enable LLDP on all network switches** for reliable discovery
3. **Document IP ranges** used for VoIP phones
4. **Use DHCP with reservations** for consistent IP addresses

### Regular Discovery

1. **Schedule periodic discoveries** to detect new phones
2. **Monitor phone reachability** to detect network issues
3. **Maintain inventory** of discovered phones
4. **Track phone firmware versions** for updates

### Integration

1. **Combine with provisioning** - Auto-provision discovered phones
2. **Link to extensions** - Associate discovered phones with SIP extensions
3. **Monitor status** - Regular health checks on registered phones
4. **Alert on issues** - Notify when phones go offline

## Performance

### LLDP Discovery
- **Speed:** Near-instant (reads cached LLDP data)
- **Network Impact:** Minimal (no active scanning)
- **Resource Usage:** Low

### Network Scanning
- **Speed:** 10-60 seconds for /24 subnet (256 IPs)
- **Network Impact:** Moderate (sends probe packets)
- **Resource Usage:** Moderate CPU, low memory

### Recommendations

- Use LLDP as primary discovery method
- Use nmap for initial network surveys or when LLDP is unavailable
- Cache discovery results to reduce overhead
- Implement incremental updates rather than full rescans

## Future Enhancements

- **CDP Support** - Cisco Discovery Protocol for Cisco-heavy environments
- **SNMP Discovery** - Query network devices via SNMP
- **Multicast DNS (mDNS)** - Discover phones using Bonjour/Avahi
- **Auto-provisioning** - Automatically provision discovered phones
- **Firmware Management** - Track and update phone firmware
- **Network Diagram** - Visual representation of discovered topology

## Related Documentation

- [VoIP Phone Management](VOIP_PHONE_MANAGEMENT.md)
- [API Quick Reference](API_QUICK_REFERENCE.md)
- [TUI Enhancements](TUI_ENHANCEMENTS.md)
- [Security Summary](SECURITY_SUMMARY.md)

## Support

For issues or questions:
- GitHub Issues: https://github.com/atomicdeploy/rayanpbx/issues
- Documentation: https://github.com/atomicdeploy/rayanpbx
