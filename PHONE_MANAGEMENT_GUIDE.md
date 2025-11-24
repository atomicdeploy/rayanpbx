# GXP1625/GXP1630 Phone Management Guide

## Overview

RayanPBX provides comprehensive management, monitoring, and control functionality for GrandStream GXP1625 and GXP1630 VoIP phones. This guide covers all available management methods and features.

## Supported Models

- **GXP1625**: 2 lines, 2.3" LCD display
- **GXP1630**: 3 lines, 2.8" color LCD display

Both models support:
- HTTP Web Management API
- TR-069 (CWMP) protocol
- Webhooks for event notification
- Auto-provisioning via XML

## Management Methods

### 1. Web UI Management

Access the phone management interface at `http://your-server:3000/phones`

#### Features:
- **Phone Discovery**: Automatic detection from SIP registrations
- **Live Status**: Real-time phone status with model, firmware, MAC, uptime
- **Control Operations**:
  - Reboot phone
  - Factory reset
  - Get/Set configuration
  - Provision extensions
- **Multi-line Support**: Configure up to 6 accounts per phone

#### Usage:
1. Navigate to "Phones" in the web interface
2. Click on a phone to view details
3. Enter admin credentials when prompted
4. Use control buttons to manage the phone

### 2. API Management

#### List All Phones
```bash
curl -X GET "http://your-server:8000/api/phones" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

#### Get Phone Status
```bash
curl -X GET "http://your-server:8000/api/phones/192.168.1.100" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "credentials": {
      "username": "admin",
      "password": "your_password"
    }
  }'
```

#### Control Phone
```bash
# Reboot
curl -X POST "http://your-server:8000/api/phones/control" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "ip": "192.168.1.100",
    "action": "reboot",
    "credentials": {
      "username": "admin",
      "password": "your_password"
    }
  }'

# Get Status
curl -X POST "http://your-server:8000/api/phones/control" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "ip": "192.168.1.100",
    "action": "get_status",
    "credentials": {
      "username": "admin",
      "password": "your_password"
    }
  }'

# Get Configuration
curl -X POST "http://your-server:8000/api/phones/control" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "ip": "192.168.1.100",
    "action": "get_config",
    "credentials": {
      "username": "admin",
      "password": "your_password"
    }
  }'
```

#### Provision Extension
```bash
curl -X POST "http://your-server:8000/api/phones/provision" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "ip": "192.168.1.100",
    "extension_id": 1,
    "account_number": 1,
    "credentials": {
      "username": "admin",
      "password": "your_password"
    }
  }'
```

### 3. TUI Management

Access via terminal:
```bash
rayanpbx-tui
```

#### Navigation:
1. Select "📞 VoIP Phones Management" from main menu
2. Use ↑/↓ to navigate phone list
3. Press Enter to view phone details
4. Press 'c' to open control menu

#### Control Menu Options:
- 📊 Get Phone Status
- 🔄 Reboot Phone
- 🏭 Factory Reset
- 📋 Get Configuration
- ⚙️ Set Configuration
- 🔧 Provision Extension
- 📡 TR-069 Management
- 🔗 Webhook Configuration
- 📊 Live Monitoring
- 🔙 Back to Details

### 4. TR-069 (CWMP) Management

TR-069 provides advanced management capabilities for bulk operations and enterprise deployments.

#### Setup TR-069 on Phone:
1. Access phone web interface
2. Navigate to Maintenance > TR-069
3. Configure ACS settings:
   - ACS URL: `http://your-server:7547/`
   - ACS Username: `admin`
   - ACS Password: (from config)
4. Enable TR-069
5. Set periodic inform interval (e.g., 300 seconds)

#### TR-069 API Usage:

**Get Parameter Values:**
```bash
curl -X POST "http://your-server:8000/api/phones/tr069/manage" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "serial_number": "PHONE_SERIAL",
    "action": "get_params",
    "parameters": [
      "InternetGatewayDevice.DeviceInfo.ModelName",
      "InternetGatewayDevice.DeviceInfo.SoftwareVersion"
    ]
  }'
```

**Set Parameter Values:**
```bash
curl -X POST "http://your-server:8000/api/phones/tr069/manage" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "serial_number": "PHONE_SERIAL",
    "action": "set_params",
    "parameters": {
      "InternetGatewayDevice.Services.VoiceService.1.VoiceProfile.1.Enable": "1"
    }
  }'
```

**Reboot via TR-069:**
```bash
curl -X POST "http://your-server:8000/api/phones/tr069/manage" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "serial_number": "PHONE_SERIAL",
    "action": "reboot"
  }'
```

**Configure SIP Account:**
```bash
curl -X POST "http://your-server:8000/api/phones/tr069/manage" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "serial_number": "PHONE_SERIAL",
    "action": "configure_sip",
    "account_number": 1,
    "sip_config": {
      "server": "your-pbx-server",
      "port": "5060",
      "username": "1001",
      "password": "secret123",
      "extension": "1001"
    }
  }'
```

### 5. Webhook Integration

Configure phones to send events to RayanPBX for real-time monitoring.

#### Setup Webhooks on Phone:
1. Access phone web interface
2. Navigate to Settings > Events
3. Configure webhook URL: `http://your-server:8000/api/phones/webhook`
4. Enable desired events:
   - Registration
   - Call start/end
   - Configuration changes

#### Webhook Endpoint:
```
POST /api/phones/webhook
Content-Type: application/json

{
  "event": "registration",
  "data": {
    "extension": "1001",
    "ip": "192.168.1.100",
    "timestamp": "2024-01-01T12:00:00Z"
  }
}
```

## Configuration Parameters

### GrandStream Parameter Format
GrandStream uses P-codes for configuration parameters. Format: `P{account}{parameter}`

#### Common Parameters:

**Account Configuration (X = account number 1-6):**
- `PX47` - SIP Server
- `PX35` - SIP User ID
- `PX36` - Authenticate ID
- `PX34` - Authenticate Password
- `PX3` - Name/Display Name
- `PX270` - Account Active (1=yes, 0=no)

**Network Configuration:**
- `P8` - DHCP (1=enabled, 0=disabled)
- `P9` - Static IP
- `P10` - Subnet Mask
- `P11` - Gateway

**Codec Configuration:**
- `P57` - Codec 1 (PCMU)
- `P58` - Codec 2 (PCMA)
- `P59` - Codec 3 (G722)

## Provisioning Methods

### 1. Direct HTTP Provisioning
Push configuration directly via HTTP API (immediate effect):

```bash
curl -X POST "http://your-server:8000/api/grandstream/provision-direct" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "ip": "192.168.1.100",
    "extension_id": 1,
    "account_number": 1,
    "credentials": {
      "username": "admin",
      "password": "your_password"
    }
  }'
```

### 2. XML-based Auto-Provisioning
Phone pulls configuration from provisioning server:

**Configure phone:**
1. Access web interface
2. Navigate to Maintenance > Upgrade
3. Set Config Server Path: `http://your-server:8000/api/grandstream/provision`
4. Click "Update"

**Generate configuration:**
```bash
curl -X POST "http://your-server:8000/api/grandstream/configure/MAC_ADDRESS" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "extension_id": 1,
    "model": "GXP1630",
    "account_number": 1
  }'
```

### 3. TR-069 Provisioning
Use TR-069 for bulk provisioning (enterprise):

```bash
curl -X POST "http://your-server:8000/api/phones/tr069/manage" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "serial_number": "PHONE_SERIAL",
    "action": "configure_sip",
    "account_number": 1,
    "sip_config": {
      "server": "pbx.example.com",
      "username": "1001",
      "password": "secret123"
    }
  }'
```

## Monitoring and Status

### Real-time Status Information:
- IP Address
- Model
- Firmware Version
- MAC Address
- Uptime
- Registration Status
- Active Calls
- SIP Account Status
- Network Configuration

### Status Display Locations:
1. **Web UI**: Click phone in list for detailed view
2. **TUI**: Press 'c' then select "Get Phone Status"
3. **API**: GET `/api/phones/{identifier}`

## Security Considerations

1. **Credential Storage**: Phone credentials are stored in memory during TUI session only
2. **HTTPS**: Use HTTPS in production for secure communication
3. **Password Protection**: Always use strong passwords for phone admin accounts
4. **Network Isolation**: Consider VLAN isolation for voice traffic
5. **Access Control**: Use JWT authentication for API access

## Troubleshooting

### Phone Not Appearing in List
- Verify phone is registered: `asterisk -rx "pjsip show endpoints"`
- Check network connectivity: `ping phone-ip`
- Verify SIP configuration

### Cannot Connect to Phone Web Interface
- Check if web interface is enabled on phone
- Verify IP address is correct
- Test with browser: `http://phone-ip/`
- Check firewall rules

### Provisioning Fails
- Verify phone admin credentials
- Check SIP server reachability from phone
- Verify extension credentials are correct
- Check firewall rules for SIP traffic (UDP 5060)

### TR-069 Issues
- Verify ACS URL is accessible from phone
- Check ACS credentials
- Verify TR-069 is enabled on phone
- Check inform interval settings

## Best Practices

1. **Credential Management**: Store phone credentials securely
2. **Firmware Updates**: Keep phone firmware up to date
3. **Configuration Backup**: Export phone configurations regularly
4. **Network Design**: Use VLANs for voice traffic separation
5. **Monitoring**: Enable webhooks for event monitoring
6. **Documentation**: Document phone locations and configurations
7. **Testing**: Test provisioning in non-production environment first

## Integration Examples

### Python Example:
```python
import requests

API_URL = "http://your-server:8000/api"
TOKEN = "your_jwt_token"

headers = {
    "Authorization": f"Bearer {TOKEN}",
    "Content-Type": "application/json"
}

# Get all phones
response = requests.get(f"{API_URL}/phones", headers=headers)
phones = response.json()["phones"]

# Control a phone
for phone in phones:
    requests.post(
        f"{API_URL}/phones/control",
        headers=headers,
        json={
            "ip": phone["ip"],
            "action": "get_status",
            "credentials": {
                "username": "admin",
                "password": "admin123"
            }
        }
    )
```

### Shell Script Example:
```bash
#!/bin/bash

API_URL="http://your-server:8000/api"
TOKEN="your_jwt_token"

# Provision all phones in a list
while IFS=',' read -r ip extension; do
  curl -X POST "$API_URL/phones/provision" \
    -H "Authorization: Bearer $TOKEN" \
    -H "Content-Type: application/json" \
    -d "{
      \"ip\": \"$ip\",
      \"extension_id\": $extension,
      \"credentials\": {
        \"username\": \"admin\",
        \"password\": \"admin123\"
      }
    }"
done < phones.csv
```

## Support

For issues or questions:
- GitHub Issues: https://github.com/atomicdeploy/rayanpbx/issues
- Documentation: https://github.com/atomicdeploy/rayanpbx
- Email: support@rayanpbx.local
