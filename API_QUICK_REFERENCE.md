# RayanPBX PJSIP API Quick Reference

## Extension Management

### List All Extensions
```bash
GET /api/extensions
Authorization: Bearer {token}
```

Response includes:
- Database records
- Real-time Asterisk registration status
- IP address and port (if registered)
- Contact count

### Create Extension
```bash
POST /api/extensions
Content-Type: application/json
Authorization: Bearer {token}

{
  "extension_number": "1001",
  "name": "User Name",
  "secret": "SecurePassword123",
  "enabled": true,
  "context": "internal",
  "transport": "udp",
  "codecs": ["ulaw", "alaw", "g722"],
  "max_contacts": 1,
  "caller_id": "\"User\" <1001>",
  "voicemail_enabled": false
}
```

Response includes:
- Created extension record
- `asterisk_verified`: boolean - whether endpoint exists in Asterisk
- `reload_success`: boolean - whether Asterisk reload succeeded

### Verify Extension in Asterisk
```bash
GET /api/extensions/{id}/verify
Authorization: Bearer {token}
```

Returns:
- `exists_in_asterisk`: boolean
- `registration_status`: object with registration details
- `endpoint_details`: parsed PJSIP endpoint information

### Get All Asterisk Endpoints
```bash
GET /api/extensions/asterisk/endpoints
Authorization: Bearer {token}
```

Returns all endpoints from Asterisk (not just RayanPBX managed ones).

## Event Monitoring

### Get Recent Events
```bash
GET /api/events
Authorization: Bearer {token}
```

### Get Registration Events
```bash
GET /api/events/registrations
Authorization: Bearer {token}
```

### Get Call Events
```bash
GET /api/events/calls
Authorization: Bearer {token}
```

### Get Extension Status
```bash
GET /api/events/extension/{extension_number}
Authorization: Bearer {token}
```

Returns:
```json
{
  "extension": "1001",
  "registered": true,
  "status": "Created",
  "uri": "sip:1001@192.168.1.100:5060",
  "timestamp": "2024-01-01T12:00:00Z"
}
```

## PJSIP Configuration

### Get Global Settings
```bash
GET /api/pjsip/config/global
Authorization: Bearer {token}
```

### Update External Media Address
```bash
POST /api/pjsip/config/external-media
Content-Type: application/json
Authorization: Bearer {token}

{
  "external_media_address": "203.0.113.1",
  "external_signaling_address": "203.0.113.1",
  "local_net": "192.168.1.0/24"
}
```

### Update Transport
```bash
POST /api/pjsip/config/transport
Content-Type: application/json
Authorization: Bearer {token}

{
  "protocol": "udp",
  "bind": "0.0.0.0:5060"
}
```

## Artisan Commands

### Monitor AMI Events
```bash
php artisan rayanpbx:monitor-events
```

Monitors and displays:
- Extension registrations/unregistrations
- Ringing events
- Call hangups

### Extension Management
```bash
# List extensions
php artisan rayanpbx:extension list

# Create extension
php artisan rayanpbx:extension create 1001

# Delete extension
php artisan rayanpbx:extension delete 1001
```

### Asterisk Commands
```bash
# Execute Asterisk CLI command
php artisan rayanpbx:asterisk "pjsip show endpoints"

# Reload configuration
php artisan rayanpbx:config reload
```

## TUI (Terminal UI)

### Show Endpoints
```go
// In TUI code
am := NewAsteriskManager()

// Verify single endpoint
exists, output, err := am.VerifyEndpoint("1001")

// Get endpoint status
status, err := am.GetEndpointStatus("1001")
// Returns: "registered", "offline", "not_found", or "error"

// List all endpoints
endpoints, err := am.ListAllEndpoints()
```

## WebSocket Events

Events are cached and can be picked up by WebSocket servers:

```javascript
// Frontend example (pseudo-code)
ws.onmessage = (event) => {
  const data = JSON.parse(event.data);
  
  switch(data.type) {
    case 'extension.registration':
      console.log(`Extension ${data.data.extension} ${data.data.registered ? 'registered' : 'unregistered'}`);
      break;
      
    case 'extension.ringing':
      console.log(`Extension ${data.data.destination} ringing from ${data.data.caller}`);
      break;
      
    case 'extension.hangup':
      console.log(`Call ended: ${data.data.cause_text}`);
      break;
  }
};
```

## Configuration File Structure

### PJSIP Config (`/etc/asterisk/pjsip.conf`)

Managed sections are wrapped with markers:
```ini
; BEGIN MANAGED - {identifier}
[section]
config=value
; END MANAGED - {identifier}
```

**Do not edit managed sections manually** - they will be overwritten.

### Dialplan (`/etc/asterisk/extensions.conf`)

Same marker system:
```ini
; BEGIN MANAGED - RayanPBX Internal Extensions
[internal]
exten => 1001,1,Dial(PJSIP/1001)
; END MANAGED - RayanPBX Internal Extensions
```

## Testing Endpoints

### From Command Line
```bash
# Check if endpoint exists
asterisk -rx "pjsip show endpoint 1001"

# Show all endpoints
asterisk -rx "pjsip show endpoints"

# Show registrations
asterisk -rx "pjsip show registrations"

# Test dialplan
asterisk -rx "dialplan show internal"

# Originate test call
asterisk -rx "channel originate PJSIP/1001 extension 1002@internal"
```

### From API
```bash
# Execute Asterisk command
curl -X POST http://localhost:8000/api/console/execute \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"command": "pjsip show endpoints"}'

# Get endpoints
curl http://localhost:8000/api/console/endpoints \
  -H "Authorization: Bearer $TOKEN"
```

## Common Patterns

### Create and Verify Extension
```bash
# 1. Create extension
RESPONSE=$(curl -s -X POST http://localhost:8000/api/extensions \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "extension_number": "1001",
    "name": "Test User",
    "secret": "TestPass123"
  }')

# 2. Extract extension ID
EXT_ID=$(echo $RESPONSE | jq -r '.extension.id')

# 3. Verify in Asterisk
curl -s http://localhost:8000/api/extensions/$EXT_ID/verify \
  -H "Authorization: Bearer $TOKEN" | jq

# 4. Monitor registration
watch -n 2 "curl -s http://localhost:8000/api/events/extension/1001 \
  -H 'Authorization: Bearer $TOKEN' | jq"
```

### Monitor Real-time Events
```bash
# Terminal 1: Start event monitor
php artisan rayanpbx:monitor-events

# Terminal 2: Watch API events
watch -n 1 "curl -s http://localhost:8000/api/events \
  -H 'Authorization: Bearer $TOKEN' | jq '.events[-5:]'"

# Terminal 3: Configure SIP client and register
```

## Error Handling

### Extension Creation Failed
Check response for:
- `asterisk_verified: false` - Endpoint not created in Asterisk
- `reload_success: false` - Asterisk reload failed

Debug:
```bash
# Check Asterisk logs
tail -f /var/log/asterisk/messages

# Check config file
grep "Extension 1001" /etc/asterisk/pjsip.conf

# Try manual reload
asterisk -rx "pjsip reload"
```

### Registration Not Working
1. Check endpoint exists: `GET /api/extensions/{id}/verify`
2. Check transport: `asterisk -rx "pjsip show transports"`
3. Check firewall: `netstat -tunlp | grep 5060`
4. Enable PJSIP logger: `asterisk -rx "pjsip set logger on"`

## Performance Notes

- Extension listing includes real-time Asterisk queries (can be slow with many extensions)
- Event monitoring is asynchronous (doesn't block API requests)
- Events are cached for 60 seconds
- Recent events list is limited to last 100 events

## Security Considerations

- Extension secrets are hashed with bcrypt before storage
- Plain-text secrets are never logged
- AMI credentials should be secured
- Consider TLS transport for production
- Use strong passwords (min 8 characters enforced)

## References

- Main documentation: `README.md`
- Setup guide: `PJSIP_SETUP_GUIDE.md`
- Asterisk PJSIP docs: https://wiki.asterisk.org/wiki/display/AST/Configuring+res_pjsip
