# Phone Management API Reference

## Overview

Complete API reference for managing GXP1625 and GXP1630 VoIP phones in RayanPBX.

**Base URL**: `http://your-server:8000/api`

**Authentication**: All endpoints require JWT Bearer token authentication.

## Endpoints

### Phone Discovery & Listing

#### GET /phones
List all discovered phones from SIP registrations.

**Response:**
```json
{
  "success": true,
  "phones": [
    {
      "extension": "101",
      "ip": "192.168.1.100",
      "status": "Registered",
      "user_agent": "Grandstream GXP1630"
    }
  ],
  "total": 1
}
```

#### GET /phones/{identifier}
Get detailed information about a specific phone.

**Parameters:**
- `identifier` (path): IP address, MAC address, or extension number

**Request Body:**
```json
{
  "credentials": {
    "username": "admin",
    "password": "your_password"
  }
}
```

**Response:**
```json
{
  "success": true,
  "phone": {
    "status": "online",
    "ip": "192.168.1.100",
    "model": "GXP1630",
    "firmware": "1.0.11.23",
    "mac": "00:0B:82:12:34:56",
    "uptime": "5 days",
    "registered": true,
    "last_update": "2024-01-01T12:00:00Z"
  }
}
```

### Phone Control

#### POST /phones/control
Execute control operations on a phone.

**Request Body:**
```json
{
  "ip": "192.168.1.100",
  "action": "reboot|factory_reset|get_config|set_config|get_status",
  "credentials": {
    "username": "admin",
    "password": "your_password"
  },
  "config": {
    // Required only for set_config action
  }
}
```

**Actions:**

##### get_status
Get current phone status.

**Response:**
```json
{
  "status": "online",
  "ip": "192.168.1.100",
  "model": "GXP1630",
  "firmware": "1.0.11.23",
  "mac": "00:0B:82:12:34:56",
  "uptime": "5 days"
}
```

##### reboot
Reboot the phone.

**Response:**
```json
{
  "success": true,
  "message": "Phone reboot command sent successfully",
  "ip": "192.168.1.100"
}
```

##### factory_reset
Factory reset the phone.

**Response:**
```json
{
  "success": true,
  "message": "Phone factory reset command sent successfully",
  "ip": "192.168.1.100"
}
```

##### get_config
Get phone configuration.

**Response:**
```json
{
  "success": true,
  "config": {
    "P147": "192.168.1.1",
    "P135": "101",
    "P13": "Extension 101"
  },
  "ip": "192.168.1.100"
}
```

##### set_config
Set phone configuration parameters.

**Request:**
```json
{
  "ip": "192.168.1.100",
  "action": "set_config",
  "config": {
    "P147": "192.168.1.1",
    "P135": "101",
    "P134": "secret123"
  },
  "credentials": {
    "username": "admin",
    "password": "your_password"
  }
}
```

**Response:**
```json
{
  "success": true,
  "message": "Phone configuration updated successfully",
  "ip": "192.168.1.100"
}
```

### Phone Provisioning

#### POST /phones/provision
Provision an extension to a phone via direct HTTP.

**Request Body:**
```json
{
  "ip": "192.168.1.100",
  "extension_id": 1,
  "account_number": 1,
  "credentials": {
    "username": "admin",
    "password": "your_password"
  }
}
```

**Response:**
```json
{
  "success": true,
  "message": "Extension provisioned successfully",
  "ip": "192.168.1.100"
}
```

### TR-069 Management

#### POST /phones/tr069/manage
Manage phone via TR-069 protocol.

**Request Body:**
```json
{
  "serial_number": "PHONE_SERIAL_NUMBER",
  "action": "get_params|set_params|reboot|factory_reset|configure_sip",
  "parameters": [],  // For get_params/set_params
  "sip_config": {}   // For configure_sip
}
```

**Actions:**

##### get_params
Get TR-069 parameter values.

**Request:**
```json
{
  "serial_number": "ABC123",
  "action": "get_params",
  "parameters": [
    "InternetGatewayDevice.DeviceInfo.ModelName",
    "InternetGatewayDevice.DeviceInfo.SoftwareVersion"
  ]
}
```

**Response:**
```json
{
  "success": true,
  "result": {
    "Status": "Pending",
    "RPC_ID": "GetParams_1234567890",
    "Message": "RPC queued, waiting for device connection"
  }
}
```

##### set_params
Set TR-069 parameter values.

**Request:**
```json
{
  "serial_number": "ABC123",
  "action": "set_params",
  "parameters": {
    "InternetGatewayDevice.Services.VoiceService.1.VoiceProfile.1.Enable": "1"
  }
}
```

**Response:**
```json
{
  "success": true,
  "result": {
    "Status": "Pending",
    "RPC_ID": "SetParams_1234567890"
  }
}
```

##### configure_sip
Configure SIP account via TR-069.

**Request:**
```json
{
  "serial_number": "ABC123",
  "action": "configure_sip",
  "account_number": 1,
  "sip_config": {
    "server": "pbx.example.com",
    "port": "5060",
    "username": "101",
    "password": "secret123",
    "extension": "101"
  }
}
```

**Response:**
```json
{
  "success": true,
  "result": {
    "Status": 0
  }
}
```

#### GET /phones/tr069/devices
List all TR-069 managed devices.

**Response:**
```json
{
  "success": true,
  "devices": [
    {
      "serial_number": "ABC123",
      "manufacturer": "Grandstream",
      "model": "GXP1630",
      "last_inform": "2024-01-01T12:00:00",
      "connection_request_url": "http://192.168.1.100:7547"
    }
  ],
  "total": 1
}
```

### Webhooks

#### POST /phones/webhook
Receive webhook events from phones.

**Request Body:**
```json
{
  "event": "registration|call_start|call_end|config_change",
  "data": {
    "extension": "101",
    "ip": "192.168.1.100",
    "timestamp": "2024-01-01T12:00:00Z"
  }
}
```

**Response:**
```json
{
  "success": true,
  "message": "Webhook processed"
}
```

### GrandStream Specific APIs

#### GET /grandstream/devices
List discovered GrandStream devices.

**Query Parameters:**
- `network` (optional): Network to scan (e.g., "192.168.1.0/24")

**Response:**
```json
{
  "success": true,
  "devices": [
    {
      "extension": "101",
      "ip": "192.168.1.100",
      "status": "Registered",
      "user_agent": "Grandstream GXP1630"
    }
  ]
}
```

#### POST /grandstream/scan
Scan network for GrandStream phones.

**Request Body:**
```json
{
  "network": "192.168.1.0/24"
}
```

**Response:**
```json
{
  "success": true,
  "scan_result": {
    "status": "success",
    "phones": []
  }
}
```

#### GET /grandstream/provision/{mac}
Get provisioning configuration for a phone (used by phones).

**Parameters:**
- `mac` (path): MAC address of the phone

**Response:** XML configuration file

#### POST /grandstream/configure/{mac}
Configure a phone via provisioning server.

**Parameters:**
- `mac` (path): MAC address

**Request Body:**
```json
{
  "extension_id": 1,
  "model": "GXP1630",
  "account_number": 1,
  "blf_list": ["102", "103"],
  "network": {
    "dhcp": 1
  }
}
```

**Response:**
```json
{
  "success": true,
  "message": "Phone configured successfully",
  "mac": "000B82123456",
  "extension": "101",
  "config_url": "http://server/api/grandstream/provision/000B82123456"
}
```

#### GET /grandstream/status/{mac}
Get phone status by MAC address.

**Query Parameters:**
- `ip` (required): Phone IP address
- `credentials` (optional): Admin credentials object

**Response:**
```json
{
  "success": true,
  "mac": "000B82123456",
  "status": {
    "status": "online",
    "ip": "192.168.1.100",
    "model": "GXP1630"
  }
}
```

#### POST /grandstream/reboot
Reboot a GrandStream phone.

**Request Body:**
```json
{
  "ip": "192.168.1.100",
  "credentials": {
    "username": "admin",
    "password": "your_password"
  }
}
```

**Response:**
```json
{
  "success": true,
  "message": "Phone reboot command sent successfully",
  "ip": "192.168.1.100"
}
```

#### POST /grandstream/factory-reset
Factory reset a GrandStream phone.

**Request Body:**
```json
{
  "ip": "192.168.1.100",
  "credentials": {
    "username": "admin",
    "password": "your_password"
  }
}
```

**Response:**
```json
{
  "success": true,
  "message": "Phone factory reset command sent successfully",
  "ip": "192.168.1.100"
}
```

#### POST /grandstream/config/get
Get phone configuration.

**Request Body:**
```json
{
  "ip": "192.168.1.100",
  "credentials": {
    "username": "admin",
    "password": "your_password"
  }
}
```

**Response:**
```json
{
  "success": true,
  "config": {},
  "ip": "192.168.1.100"
}
```

#### POST /grandstream/config/set
Set phone configuration.

**Request Body:**
```json
{
  "ip": "192.168.1.100",
  "config": {
    "P147": "192.168.1.1"
  },
  "credentials": {
    "username": "admin",
    "password": "your_password"
  }
}
```

**Response:**
```json
{
  "success": true,
  "message": "Phone configuration updated successfully",
  "ip": "192.168.1.100"
}
```

#### POST /grandstream/provision-direct
Provision extension directly via HTTP.

**Request Body:**
```json
{
  "ip": "192.168.1.100",
  "extension_id": 1,
  "account_number": 1,
  "credentials": {
    "username": "admin",
    "password": "your_password"
  }
}
```

**Response:**
```json
{
  "success": true,
  "message": "Phone configuration updated successfully",
  "ip": "192.168.1.100"
}
```

#### GET /grandstream/models
Get supported phone models.

**Response:**
```json
{
  "success": true,
  "models": {
    "GXP1625": {
      "lines": 2,
      "template": "gxp1620.xml",
      "firmware": "1.0.11.23",
      "display": "2.3\" LCD"
    },
    "GXP1630": {
      "lines": 3,
      "template": "gxp1620.xml",
      "firmware": "1.0.11.23",
      "display": "2.8\" Color LCD"
    }
  }
}
```

#### GET /grandstream/hooks
Get provisioning hooks information.

**Response:**
```json
{
  "success": true,
  "provisioning": {
    "protocol": "HTTP",
    "base_url": "http://server/api/grandstream/provision",
    "method": "GET",
    "format": "XML",
    "auth_required": false
  },
  "models": {},
  "configuration_url_format": "{base_url}/{mac}.xml",
  "phone_setup": {
    "step_1": "Access phone web interface",
    "step_2": "Navigate to Maintenance > Upgrade",
    "step_3": "Set Config Server Path",
    "step_4": "Click Update"
  }
}
```

## Error Responses

All endpoints may return error responses:

**400 Bad Request:**
```json
{
  "success": false,
  "error": "Validation error",
  "message": "Invalid IP address"
}
```

**401 Unauthorized:**
```json
{
  "success": false,
  "error": "Authentication failed",
  "message": "Invalid or expired token"
}
```

**404 Not Found:**
```json
{
  "success": false,
  "error": "Phone not found"
}
```

**500 Internal Server Error:**
```json
{
  "success": false,
  "error": "Internal server error",
  "message": "Failed to communicate with phone"
}
```

## Rate Limiting

API endpoints are rate-limited:
- 60 requests per minute per IP
- 1000 requests per hour per user

## WebSocket Events

For real-time updates, connect to WebSocket:

**URL**: `ws://your-server:9000/ws`

**Events:**
- `phone.registered` - Phone registered
- `phone.unregistered` - Phone unregistered
- `phone.status_changed` - Phone status changed
- `phone.config_changed` - Phone configuration changed

**Event Format:**
```json
{
  "event": "phone.registered",
  "data": {
    "extension": "101",
    "ip": "192.168.1.100",
    "timestamp": "2024-01-01T12:00:00Z"
  }
}
```

## Code Examples

### cURL Examples

**List phones:**
```bash
curl -X GET "http://server:8000/api/phones" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Reboot phone:**
```bash
curl -X POST "http://server:8000/api/phones/control" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "ip": "192.168.1.100",
    "action": "reboot",
    "credentials": {"username": "admin", "password": "admin123"}
  }'
```

### Python Example

```python
import requests

API_URL = "http://server:8000/api"
TOKEN = "your_token"

headers = {
    "Authorization": f"Bearer {TOKEN}",
    "Content-Type": "application/json"
}

# List phones
response = requests.get(f"{API_URL}/phones", headers=headers)
print(response.json())

# Control phone
response = requests.post(
    f"{API_URL}/phones/control",
    headers=headers,
    json={
        "ip": "192.168.1.100",
        "action": "reboot",
        "credentials": {"username": "admin", "password": "admin123"}
    }
)
print(response.json())
```

### JavaScript Example

```javascript
const API_URL = 'http://server:8000/api';
const TOKEN = 'your_token';

const headers = {
  'Authorization': `Bearer ${TOKEN}`,
  'Content-Type': 'application/json'
};

// List phones
fetch(`${API_URL}/phones`, { headers })
  .then(res => res.json())
  .then(data => console.log(data));

// Control phone
fetch(`${API_URL}/phones/control`, {
  method: 'POST',
  headers,
  body: JSON.stringify({
    ip: '192.168.1.100',
    action: 'reboot',
    credentials: { username: 'admin', password: 'admin123' }
  })
})
  .then(res => res.json())
  .then(data => console.log(data));
```

## Support

For API support:
- GitHub Issues: https://github.com/atomicdeploy/rayanpbx/issues
- Documentation: https://github.com/atomicdeploy/rayanpbx
