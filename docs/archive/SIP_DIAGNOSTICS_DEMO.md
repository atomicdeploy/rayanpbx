# SIP Extension Diagnostics - Feature Demonstration

## TUI (Terminal User Interface) - Extension Info Screen

### How to Access
1. Launch TUI: `rayanpbx-tui`
2. Navigate to "📱 Extensions Management"
3. Use ↑/↓ to select an extension
4. Press `i` for Info/Diagnostics

### What You'll See

```
╭────────────────────────────────────────────────────────────────╮
│ 📞 Extension Info & Diagnostics: 1001                         │
╰────────────────────────────────────────────────────────────────╯

📋 Extension Details:
  • Number: 1001
  • Name: John Doe
  • Status: ✅ Enabled

🔍 Real-time Registration Status:
  🟢 Status: Registered
  Contact: sip:1001@192.168.1.50:5060;transport=UDP
  Status: Available 10.0.0.1:5060 expires 3600

📱 SIP Client Setup Guide:
  Configure your SIP phone/softphone with these settings:

  Required Configuration:
    • Extension/Username: 1001
    • Password: (your configured secret)
    • SIP Server: (your PBX server IP or hostname)
    • Port: 5060 (default)
    • Transport: UDP (default)

  Popular SIP Clients:
    • MicroSIP (Windows): https://www.microsip.org/
    • Linphone (Cross-platform): https://www.linphone.org/
    • GrandStream phones: Enterprise hardware phones
    • Yealink phones: Enterprise hardware phones

🧪 Testing Instructions:
  1. Register your SIP client with the above credentials
  2. Check registration status (should show 'Registered')
  3. Place a test call to another extension
  4. Verify two-way audio works correctly

🔧 Troubleshooting:
  If registration fails:
    • Verify credentials match database
    • Check network connectivity to PBX
    • Ensure port 5060 is not blocked by firewall
    • Check Asterisk logs: /var/log/asterisk/full
    • Enable SIP debug: pjsip set logger on

⚡ Quick Actions:
  • Press 'r' to reload Asterisk PJSIP
  • Press 't' to run SIP test suite
  • Press 's' to enable SIP debugging
  • Press ESC to go back
```

### Quick Actions Demonstration

#### Press 'r' - Reload PJSIP
```
🔄 Reloading Asterisk PJSIP...
✅ PJSIP reloaded successfully
```

#### Press 't' - Launch Test Suite
```
╭────────────────────────────────────────────────────────────────╮
│ 📞 Test Registration                                          │
╰────────────────────────────────────────────────────────────────╯

Enter the following details:

Extension Number: 1001
Password: _
Server (optional): 127.0.0.1
```

#### Press 's' - Enable SIP Debugging
```
🔍 Enabling SIP debugging...
✅ SIP debugging enabled - check Asterisk console
```

---

## Web UI - Enhanced Diagnostics Modal

### How to Access
1. Open RayanPBX Web UI
2. Navigate to Extensions page
3. Click on any extension's status badge (🟢 Registered or ⚫ Offline)

### Modal Sections

#### 1. Header
```
✓ Extension 1001 Diagnostics
```
or (if offline)
```
⚠️ Extension 1001 Setup & Troubleshooting
```

#### 2. Real-time Registration Status
```
┌─────────────────────────────────────────────────────────┐
│ 🟢 Registered - Real-time Status                        │
│                                                          │
│ Contact: sip:1001@192.168.1.50:5060;transport=UDP      │
│ Expires: 3600 seconds                                   │
└─────────────────────────────────────────────────────────┘
```

#### 3. SIP Client Setup Guide
```
┌─────────────────────────────────────────────────────────┐
│ 📱 SIP Client Setup Guide                               │
│                                                          │
│ Configure your SIP phone/softphone with these settings: │
│                                                          │
│ ┌────────────────────────────────────────────────┐     │
│ │ Extension/Username:  1001                       │     │
│ │ Password:            (your configured secret)   │     │
│ │ SIP Server:          192.168.1.100             │     │
│ │ Port:                5060                       │     │
│ │ Transport:           UDP                        │     │
│ └────────────────────────────────────────────────┘     │
│                                                          │
│ Popular SIP Clients:                                     │
│  • MicroSIP (Windows) - Lightweight softphone     ↗     │
│  • Linphone (Cross-platform) - Open source VoIP   ↗     │
│  • Zoiper (Cross-platform) - Free and premium     ↗     │
│  • GrandStream (Hardware) - Enterprise phones     ↗     │
│  • Yealink (Hardware) - Professional IP phones    ↗     │
└─────────────────────────────────────────────────────────┘
```

#### 4. Testing & Validation Steps
```
┌─────────────────────────────────────────────────────────┐
│ 🧪 Testing & Validation Steps                           │
│                                                          │
│  1. Register SIP client: Configure your SIP client...   │
│  2. Verify registration: Check that the extension...    │
│  3. Place test call: Dial another extension number...   │
│  4. Verify audio: Ensure two-way audio works...         │
│  5. Test receiving calls: Have another extension call..  │
└─────────────────────────────────────────────────────────┘
```

#### 5. Troubleshooting (Context-Sensitive)
If extension is **disabled**:
```
┌─────────────────────────────────────────────────────────┐
│ 🔧 Troubleshooting                                      │
│                                                          │
│  • Extension is disabled: Enable the extension before   │
│    attempting registration                              │
└─────────────────────────────────────────────────────────┘
```

If extension is **offline**:
```
┌─────────────────────────────────────────────────────────┐
│ 🔧 Troubleshooting                                      │
│                                                          │
│  • Extension is not registered: Configure a SIP client  │
│    with the provided credentials                        │
│  • Check network connectivity: Ensure the SIP client    │
│    can reach the PBX server on port 5060               │
│  • Verify credentials: Ensure the extension number and  │
│    password match your configuration                    │
└─────────────────────────────────────────────────────────┘
```

#### 6. Status Indicators Guide
```
┌─────────────────────────────────────────────────────────┐
│ 📊 Status Indicators Guide                              │
│                                                          │
│  🟢 Registered - Extension is online and ready          │
│  ⚫ Offline - Extension is not registered               │
│  📍 IP:Port - Shows the network location of device      │
└─────────────────────────────────────────────────────────┘
```

#### 7. Quick Actions (Interactive Buttons)
```
┌──────────────┬──────────────┬──────────────┬─────────────┐
│ 📝 Edit      │ ✅ Enable    │ 🔄 Refresh   │ 🖥️ Console │
│ Extension    │ Extension    │ Status       │             │
└──────────────┴──────────────┴──────────────┴─────────────┘
```

#### 8. API Reference (Footer)
```
API Endpoints:
 • Verify: /api/extensions/1/verify
 • Endpoints: /api/extensions/asterisk/endpoints
```

---

## Backend API - Diagnostics Endpoint

### Request
```http
GET /api/extensions/1/diagnostics
Authorization: Bearer {jwt_token}
```

### Response (200 OK)
```json
{
  "extension": {
    "id": 1,
    "extension_number": "1001",
    "name": "John Doe",
    "enabled": true,
    "context": "from-internal",
    ...
  },
  "registration_status": {
    "registered": true,
    "status": "Available",
    "contacts": 1,
    "details": {
      "contacts": [
        {
          "uri": "sip:1001@192.168.1.50:5060;transport=UDP",
          "expires": "3600",
          "qualify": "Available"
        }
      ]
    }
  },
  "endpoint_details": { ... },
  "setup_guide": {
    "extension": "1001",
    "username": "1001",
    "server": "192.168.1.100",
    "port": 5060,
    "transport": "UDP",
    "context": "from-internal"
  },
  "sip_clients": [
    {
      "name": "MicroSIP",
      "platform": "Windows",
      "url": "https://www.microsip.org/",
      "description": "Lightweight SIP softphone for Windows"
    },
    ...
  ],
  "troubleshooting": [
    {
      "severity": "warning",
      "message": "Extension is not registered",
      "solution": "Configure a SIP client with the provided credentials",
      "action": null
    },
    ...
  ],
  "test_instructions": [
    {
      "step": 1,
      "action": "Register SIP client",
      "description": "Configure your SIP client with the provided credentials..."
    },
    ...
  ],
  "api_endpoints": {
    "verify": "http://localhost:8000/api/extensions/1/verify",
    "endpoints": "http://localhost:8000/api/extensions/asterisk/endpoints"
  }
}
```

---

## User Workflows

### Workflow 1: New Extension Setup
1. **TUI**: Create extension with 'a' key
2. **TUI**: Select extension, press 'i'
3. **View**: Complete setup guide
4. **Configure**: Set up SIP client using displayed credentials
5. **Verify**: Press 'r' to refresh, check status

### Workflow 2: Troubleshooting Offline Extension
1. **Web UI**: Notice red "⚫ Offline" status
2. **Click**: Click on status badge
3. **Review**: Read context-sensitive troubleshooting tips
4. **Action**: Click "Enable Extension" if disabled
5. **Test**: Click "Refresh Status" to verify

### Workflow 3: Validating Working Extension
1. **TUI**: Press 'i' on registered extension
2. **Verify**: Real-time status shows "🟢 Registered"
3. **Test**: Press 't' to run SIP test suite
4. **Validate**: Follow test instructions
5. **Document**: Note working configuration

---

## Benefits

### For Users
✅ **No manual lookup**: All information in one place
✅ **Context-aware**: Tips based on actual extension state
✅ **Quick actions**: One-key commands for common tasks
✅ **Real-time**: Always shows current Asterisk status
✅ **Educational**: Learn about SIP configuration

### For Administrators
✅ **Reduced support**: Users self-serve with guides
✅ **Faster troubleshooting**: Automated diagnostics
✅ **Better validation**: Step-by-step test process
✅ **Comprehensive logs**: Link to console and logs
✅ **API integration**: Programmatic access available

### For Developers
✅ **Well-documented**: Clear API responses
✅ **Extensible**: Easy to add new SIP clients
✅ **Modular**: Separate concerns (TUI/API/Web)
✅ **Tested**: All tests pass, no security issues
✅ **Accessible**: ARIA labels and keyboard navigation

---

## Next Steps

After merging this PR:
1. Update user documentation with screenshots
2. Create video tutorial for extension setup
3. Add auto-refresh capability to Web UI modal
4. Implement automated call testing
5. Add QR code generation for mobile clients
6. Create setup templates for popular phones
