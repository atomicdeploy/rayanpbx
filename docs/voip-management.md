# VoIP Phone Management Feature

## Overview

This feature provides comprehensive management, control, and monitoring capabilities for VoIP phones (currently GrandStream, with support for Yealink planned) through the TUI interface.

## Features

### 1. Phone Discovery
- **Automatic Discovery**: Phones are automatically detected from Asterisk SIP registrations
- **Manual IP Entry**: Option to add phones by IP address when not registered
- **Vendor Detection**: Automatically identifies phone vendor (GrandStream, Yealink, etc.)

### 2. Phone Control
- **Reboot Phone**: Remotely reboot the phone
- **Factory Reset**: Perform factory reset via web interface
- **Status Retrieval**: Get detailed phone status including model, firmware, MAC address
- **Configuration Management**: View and modify phone configuration

### 3. Phone Provisioning
- **Extension Assignment**: Assign extensions to phone accounts
- **Multi-Line Support**: Configure multiple accounts on the same phone
- **SIP Account Configuration**: Automatically configure SIP server, credentials, etc.

## Architecture

### Abstraction Layer
The implementation uses an interface-based design for vendor-agnostic phone management:

```go
type VoIPPhone interface {
    GetStatus() (*PhoneStatus, error)
    Reboot() error
    FactoryReset() error
    GetConfig() (map[string]interface{}, error)
    SetConfig(config map[string]interface{}) error
    ProvisionExtension(ext Extension, accountNumber int) error
}
```

### GrandStream Implementation
The `GrandStreamPhone` struct implements the `VoIPPhone` interface and provides:
- HTTP-based communication with phone web interface
- Basic authentication support
- XML/JSON response parsing
- CGI-based API calls

## Usage

### Accessing VoIP Phones Menu
1. Start the TUI: `rayanpbx-tui`
2. Navigate to "📞 VoIP Phones Management"
3. Press Enter to view registered phones

### Phone List Screen
- **↑/↓**: Navigate through phones
- **Enter**: View phone details
- **m**: Add phone manually by IP address
- **r**: Refresh phone list
- **ESC**: Back to main menu

### Phone Details Screen
- Shows basic phone information
- Displays SIP accounts and registration status
- Shows network information
- **c**: Open control menu
- **r**: Refresh status
- **p**: Provision extension
- **ESC**: Back to phone list

### Phone Control Menu
- **📊 Get Phone Status**: Retrieve detailed status
- **🔄 Reboot Phone**: Reboot the device
- **🏭 Factory Reset**: Factory reset (WARNING: irreversible)
- **📋 Get Configuration**: View current configuration
- **🔧 Provision Extension**: Assign extension to phone
- **🔙 Back to Details**: Return to details screen

### Manual IP Entry
When adding a phone manually:
1. Enter IP address (e.g., 192.168.1.100)
2. Enter admin username (default: admin)
3. Enter admin password
4. Press Enter to add

The system will detect the vendor and add it to the phone list.

### Provisioning Extensions
1. Select a phone from the list
2. Press 'p' for provisioning
3. Select the extension to provision
4. Enter account number (line number on phone, usually 1-6)
5. Press Enter to apply

The phone will be configured with:
- SIP server address
- Extension credentials
- Display name
- Account activation

## API Communication

### GrandStream HTTP API
The implementation uses GrandStream's HTTP-based API:

#### Status Retrieval
```
GET http://<phone-ip>/cgi-bin/api-sys_operation
Authorization: Basic <base64(username:password)>
```

#### Reboot
```
POST http://<phone-ip>/cgi-bin/api-sys_operation?request=reboot
Authorization: Basic <base64(username:password)>
```

#### Factory Reset
```
POST http://<phone-ip>/cgi-bin/api-sys_operation?request=factory_reset
Authorization: Basic <base64(username:password)>
```

#### Configuration
```
GET http://<phone-ip>/cgi-bin/api-get_config
Authorization: Basic <base64(username:password)>

POST http://<phone-ip>/cgi-bin/api-set_config
Content-Type: application/json
Authorization: Basic <base64(username:password)>
Body: {"P147": "server", "P135": "username", ...}
```

## Phone Discovery from Asterisk

The system discovers phones by querying Asterisk PJSIP endpoints:

```bash
asterisk -rx "pjsip show endpoints"
```

This provides:
- Extension numbers
- Registration status
- Contact IP addresses
- User-Agent strings

## Security Considerations

1. **Credentials Storage**: Phone credentials are stored in memory only during the session
2. **Password Masking**: Passwords are masked in the UI (********)
3. **Basic Auth**: Uses HTTP Basic Authentication (consider upgrading to HTTPS in production)
4. **Factory Reset Protection**: Requires explicit confirmation

## Testing

Comprehensive unit tests cover:
- Phone manager creation
- IP extraction from contact strings
- Vendor detection
- Phone creation
- GrandStream-specific operations
- TUI screen initialization
- Navigation logic

Run tests:
```bash
cd tui
go test -v
```

## Future Enhancements

### Planned Features
1. **Yealink Support**: Implement YealinkPhone struct
2. **HTTPS Support**: Secure communication with phones
3. **Bulk Operations**: Configure multiple phones at once
4. **Phone Templates**: Save and apply configuration templates
5. **Firmware Updates**: Manage phone firmware upgrades
6. **Call Statistics**: View call history and statistics per phone
7. **BLF Configuration**: Configure Busy Lamp Field buttons
8. **Speed Dial**: Configure speed dial buttons

### Backend Integration
Future versions will integrate with the Laravel backend:
- API endpoints for phone control
- Database storage for phone configurations
- WebSocket notifications for phone status changes
- Web UI for phone management

## Troubleshooting

### Phone Not Appearing in List
- Verify phone is registered in Asterisk: `asterisk -rx "pjsip show endpoints"`
- Check if phone has valid contact IP
- Try adding phone manually by IP address

### Cannot Connect to Phone
- Verify phone IP is reachable: `ping <phone-ip>`
- Check if web interface is accessible: `curl http://<phone-ip>/`
- Verify admin credentials are correct
- Check if phone web interface is enabled

### Provisioning Fails
- Ensure phone web interface allows configuration changes
- Verify SIP server is reachable from phone
- Check firewall rules for SIP traffic (UDP 5060)
- Verify extension credentials are correct

## File Structure

```
tui/
├── voip_phone.go         # Core phone management interfaces and GrandStream implementation
├── voip_phone_tui.go     # TUI screens and navigation for phone management
├── voip_phone_test.go    # Unit tests for phone management
└── main.go               # Main TUI application with VoIP menu integration
```

## API Reference

### PhoneManager
- `NewPhoneManager(asteriskManager *AsteriskManager) *PhoneManager`
- `GetRegisteredPhones() ([]PhoneInfo, error)`
- `CreatePhone(ip, vendor string, credentials map[string]string) (VoIPPhone, error)`
- `DetectPhoneVendor(ip string) (string, error)`

### GrandStreamPhone
- `NewGrandStreamPhone(ip string, credentials map[string]string, httpClient *http.Client) *GrandStreamPhone`
- `GetStatus() (*PhoneStatus, error)`
- `Reboot() error`
- `FactoryReset() error`
- `GetConfig() (map[string]interface{}, error)`
- `SetConfig(config map[string]interface{}) error`
- `ProvisionExtension(ext Extension, accountNumber int) error`

## Contributing

When adding support for a new phone vendor:

1. Implement the `VoIPPhone` interface
2. Add vendor detection logic in `DetectPhoneVendor()`
3. Update `CreatePhone()` to handle the new vendor
4. Add comprehensive tests
5. Update this documentation

## License

Part of the RayanPBX project. See main project LICENSE file.
