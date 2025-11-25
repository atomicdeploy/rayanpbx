# SIP Extension Registration Diagnostics Implementation

## Overview

This document describes the implementation of comprehensive SIP extension diagnostics and setup guidance features in RayanPBX, covering TUI, Web UI, and API.

## Features Implemented

### 1. TUI (Terminal User Interface) - (i) Info/Diagnostics Command

#### Usage
From the Extensions Management screen:
1. Navigate to any extension using ↑/↓ arrow keys
2. Press `i` to open the extension info/diagnostics screen

#### What It Shows
- **Extension Details**: Number, name, enabled status
- **Real-time Registration Status**: Live data from Asterisk (`pjsip show endpoint`)
  - Online/Offline indicator
  - Contact information if registered
  - IP address and port
- **SIP Client Setup Guide**: Complete configuration instructions
  - Extension/Username
  - Password (reminder)
  - Server IP/hostname
  - Port (5060)
  - Transport (UDP)
- **Popular SIP Clients List**:
  - MicroSIP (Windows)
  - Linphone (Cross-platform)
  - GrandStream (Hardware phones)
  - Yealink (Hardware phones)
- **Testing Instructions**: Step-by-step validation process
  1. Register SIP client
  2. Check registration status
  3. Place test call
  4. Verify two-way audio
- **Troubleshooting Tips**: Context-sensitive guidance
  - Authentication failures
  - Network issues
  - Configuration problems
  - Asterisk logs location

#### Quick Actions
- Press `r`: Reload Asterisk PJSIP configuration
- Press `t`: Launch SIP test suite (pre-filled with extension info)
- Press `s`: Enable SIP debugging
- Press `ESC`: Return to extensions list

#### Implementation Details
- **File**: `tui/main.go`
- **New Screen**: `extensionInfoScreen`
- **Key Handler**: Added 'i' key in extensions screen
- **Functions**: 
  - `renderExtensionInfo()`: Displays the diagnostics screen
  - Real-time Asterisk query via `AsteriskManager.ExecuteCLICommand()`

### 2. Backend API - Diagnostics Endpoint

#### New Endpoint
```
GET /api/extensions/{id}/diagnostics
```

#### Authentication
Requires JWT authentication via Sanctum middleware.

#### Response Structure
```json
{
  "extension": {
    "id": 1,
    "extension_number": "1001",
    "name": "John Doe",
    "enabled": true,
    ...
  },
  "registration_status": {
    "registered": true,
    "status": "Available",
    "contacts": 1,
    "details": {...}
  },
  "endpoint_details": {...},
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
      "severity": "error|warning|info",
      "message": "Issue description",
      "solution": "How to fix it",
      "action": "action_id or null"
    },
    ...
  ],
  "test_instructions": [
    {
      "step": 1,
      "action": "Register SIP client",
      "description": "Configure your SIP client..."
    },
    ...
  ],
  "api_endpoints": {
    "verify": "/api/extensions/1/verify",
    "endpoints": "/api/extensions/asterisk/endpoints"
  }
}
```

#### Context-Sensitive Troubleshooting
The API automatically generates troubleshooting tips based on:
- Extension enabled/disabled state
- Registration status (online/offline)
- Endpoint presence in Asterisk
- Network connectivity indicators

#### Implementation Details
- **File**: `backend/app/Http/Controllers/Api/ExtensionController.php`
- **Method**: `diagnostics($id)`
- **Route**: Added to `backend/routes/api.php`
- **Dependencies**: Uses existing `AsteriskAdapter` for real-time data

### 3. Web UI - Enhanced Diagnostics Modal

#### Accessibility
The diagnostics modal can be opened by:
- Clicking on the status badge for **any** extension (online or offline)
- Previously only available for offline extensions

#### Features

##### 1. Real-time Registration Status
- Live indicator (🟢 Registered / ⚫ Offline)
- Contact details if registered
- Expiry time for registrations

##### 2. SIP Client Setup Guide
- Comprehensive configuration table
- Required credentials clearly displayed
- Server, port, and transport information
- List of popular SIP clients with links

##### 3. Testing & Validation Steps
- 5-step testing process
- Clear descriptions for each step
- Expected outcomes explained

##### 4. Context-Sensitive Troubleshooting
- Dynamic tips based on extension state
- Severity indicators (error/warning/info)
- Actionable solutions
- Links to relevant documentation

##### 5. Status Indicators Guide
- Explanation of what each indicator means
- 🟢 Registered: Ready for calls
- ⚫ Offline: Not registered
- 📍 IP:Port: Network location display

##### 6. Quick Actions
- Edit Extension: Jump to edit form
- Enable Extension: One-click enable (if disabled)
- Refresh Status: Update diagnostics data
- View Console: Access Asterisk logs

##### 7. API Reference
- Shows relevant API endpoints
- Direct links for programmatic access

#### Implementation Details
- **File**: `frontend/pages/extensions.vue`
- **Modal**: Enhanced `offlineHelpModal` (now works for all extensions)
- **New State**: 
  - `diagnosticsData`: Holds API response
  - `loadingDiagnostics`: Loading indicator
- **Functions**:
  - `showOfflineHelp(ext)`: Opens modal and fetches diagnostics
  - `fetchDiagnostics(extensionId)`: Calls API endpoint
  - `refreshDiagnostics()`: Updates data on demand

## User Workflows

### Workflow 1: Setting Up a New Extension (Offline)

1. **Web UI or TUI**: Create a new extension
2. **TUI**: Navigate to the extension and press `i`
   - **OR** **Web UI**: Click on the offline status badge
3. View the complete setup guide with credentials
4. Configure your SIP phone/softphone using the provided information
5. Press `r` (TUI) or click "Refresh Status" (Web UI) to update
6. Extension should now show as 🟢 Registered

### Workflow 2: Troubleshooting Registration Issues

1. **Notice**: Extension shows as offline
2. **Web UI/TUI**: Open diagnostics modal/screen
3. **Review**: Check troubleshooting tips
   - If disabled: Enable via quick action
   - If credentials wrong: Edit extension
   - If network issue: Check firewall/connectivity
4. **Enable SIP Debug**: Press `s` in TUI or check console logs
5. **Test**: Use SIP test suite (press `t` in TUI)
6. **Verify**: Refresh status to confirm registration

### Workflow 3: Validating a Working Extension (Online)

1. **Web UI/TUI**: Open diagnostics for registered extension
2. **Review**: Current registration details
3. **Test**: Follow the 5-step validation process
4. **Verify**: Two-way audio and call establishment
5. **Document**: Note working configuration for future reference

## Technical Details

### TUI Key Bindings
- `i`: Show info/diagnostics for selected extension
- `r`: Reload Asterisk PJSIP (from info screen)
- `t`: Launch SIP test suite (from info screen)
- `s`: Enable SIP debugging (from info screen)
- `ESC`: Return to previous screen

### API Integration
The diagnostics feature integrates with existing RayanPBX APIs:
- `GET /api/extensions/{id}/diagnostics`: New diagnostics endpoint
- `GET /api/extensions/{id}/verify`: Existing verification endpoint
- `GET /api/extensions/asterisk/endpoints`: All endpoints from Asterisk

### Real-time Data
All status information is fetched in real-time from Asterisk:
- TUI: Direct CLI commands via `AsteriskManager`
- Web UI: API calls to backend, which queries Asterisk
- No caching: Always shows current state

### Extensibility
The diagnostics system is designed to be extended:
- Add new SIP clients to the list
- Customize troubleshooting tips
- Add more test instructions
- Integrate with monitoring systems

## Security Considerations

1. **Password Display**: Actual passwords are never shown, only reminders
2. **Authentication**: All API endpoints require JWT authentication
3. **Authorization**: Users can only access extensions they have permission for
4. **Input Validation**: All API inputs are validated
5. **SQL Injection**: Protected by Laravel's Eloquent ORM

## Testing

### Manual Testing Checklist
- [ ] TUI: Press `i` on an offline extension
- [ ] TUI: Press `i` on an online extension
- [ ] TUI: Press `r` to reload PJSIP
- [ ] TUI: Press `t` to launch test suite
- [ ] TUI: Press `s` to enable SIP debug
- [ ] Web UI: Click offline status badge
- [ ] Web UI: Click online status badge
- [ ] Web UI: Click "Refresh Status" button
- [ ] Web UI: Click "Enable Extension" (if disabled)
- [ ] API: Call `/api/extensions/{id}/diagnostics`

### Automated Testing
- TUI: All existing tests pass (`go test -v`)
- Backend: PHP syntax validated (`php -l`)
- Go Build: Successful compilation (`go build`)

## Future Enhancements

1. **Auto-refresh**: Periodic status updates in diagnostics modal
2. **Call Testing**: Automated call tests from Web UI
3. **History**: Track registration/de-registration events
4. **Notifications**: Alert when extension goes offline
5. **Bulk Operations**: Test multiple extensions simultaneously
6. **Export**: Generate PDF setup guides
7. **QR Codes**: For mobile SIP client configuration
8. **Templates**: Pre-configured settings for common phones

## Related Documentation

- [SIP_TESTING_GUIDE.md](SIP_TESTING_GUIDE.md): Comprehensive SIP testing documentation
- [PJSIP_SETUP_GUIDE.md](PJSIP_SETUP_GUIDE.md): PJSIP configuration guide
- [API_QUICK_REFERENCE.md](API_QUICK_REFERENCE.md): API documentation

## Support

For issues or questions:
1. Check this documentation
2. Review [SIP_TESTING_GUIDE.md](SIP_TESTING_GUIDE.md)
3. Check Asterisk logs: `/var/log/asterisk/full`
4. Open an issue: https://github.com/atomicdeploy/rayanpbx/issues

## Changelog

### v1.0.0 (2024-11-24)
- Initial implementation of SIP diagnostics features
- Added TUI (i) command for extension info/diagnostics
- Created `/api/extensions/{id}/diagnostics` endpoint
- Enhanced Web UI diagnostics modal
- Made diagnostics accessible for all extensions (online/offline)
- Added comprehensive setup guides and troubleshooting tips
