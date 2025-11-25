# Asterisk Management Menu - TUI Enhancement

## Overview
The Asterisk Management menu in the RayanPBX TUI has been enhanced to provide a fully functional interactive interface, replacing the previous static message that directed users to use `rayanpbx-cli`.

## Menu Structure

```
⚙️  Asterisk Management Menu

Current Status: 🟢 Running

Select an operation:

▶ 🟢 Start Asterisk Service
  🔴 Stop Asterisk Service
  🔄 Restart Asterisk Service
  📊 Show Service Status
  🔧 Reload PJSIP Configuration
  📞 Reload Dialplan
  🔁 Reload All Modules
  👥 Show PJSIP Endpoints
  📡 Show Active Channels
  📋 Show Registrations
  🔙 Back to Main Menu
```

## Features

### Service Control
1. **Start Asterisk Service** - Starts the Asterisk service via systemctl
2. **Stop Asterisk Service** - Stops the Asterisk service gracefully
3. **Restart Asterisk Service** - Restarts the Asterisk service

### Configuration Management
4. **Show Service Status** - Displays detailed service status information
5. **Reload PJSIP Configuration** - Reloads PJSIP module without service restart
6. **Reload Dialplan** - Reloads dialplan configuration
7. **Reload All Modules** - Performs a full module reload

### Information Display
8. **Show PJSIP Endpoints** - Lists all configured PJSIP endpoints
9. **Show Active Channels** - Shows currently active call channels
10. **Show Registrations** - Displays SIP registration status

### Navigation
11. **Back to Main Menu** - Returns to the main TUI menu

## Navigation Keys

- **↑ / ↓** or **j / k** - Navigate through menu items
- **Enter** - Execute the selected command
- **ESC** - Return to main menu
- **q** - Quit the TUI

## Implementation Details

### Architecture
- Uses the existing `AsteriskManager` from `asterisk.go`
- Follows the same pattern as the Diagnostics menu for consistency
- Captures command output for display in the TUI
- Shows success/error messages for operations

### Error Handling
- All operations provide clear error messages on failure
- Success messages confirm completion
- Output from Asterisk commands is displayed in the menu

### Code Organization
- **Screen Enum**: Added `asteriskMenuScreen` constant
- **Model Fields**: `asteriskManager`, `asteriskMenu`, `asteriskOutput`
- **Rendering**: `renderAsteriskMenu()` function
- **Handler**: `handleAsteriskMenuSelection()` function
- **Tests**: Comprehensive test coverage in `main_test.go`

## User Experience Improvements

### Before
```
⚙️  Asterisk Management

Service Status: 🟢 Running

Available Actions:
  • Start/Stop/Restart Service
  • Reload PJSIP Configuration
  • Reload Dialplan
  • Execute CLI Commands
  • View Endpoints
  • View Active Channels

💡 Use rayanpbx-cli for direct Asterisk management
```

### After
Users can now:
- Navigate through a clear, organized menu
- Execute commands with a single Enter press
- See immediate feedback on operation success/failure
- View command output directly in the TUI
- Perform all common Asterisk management tasks without leaving the TUI

## Testing

All functionality is covered by unit tests:
- `TestAsteriskMenuInitialization` - Verifies menu setup
- `TestAsteriskMenuNavigation` - Tests navigation logic
- `TestScreenEnumValues` - Ensures screen constants are unique

Run tests:
```bash
cd tui
go test -v
```

## Benefits

1. **DRY Principle** - Reuses existing `AsteriskManager` code
2. **Consistency** - Matches the pattern used in Diagnostics menu
3. **User-Friendly** - No need to exit TUI to manage Asterisk
4. **Comprehensive** - Covers all common Asterisk management tasks
5. **Safe** - Proper error handling and user feedback
6. **Tested** - Full test coverage ensures reliability

## Future Enhancements

Potential improvements for future versions:
- Real-time service status updates
- Command history/logging
- Confirmation prompts for destructive operations
- Advanced Asterisk CLI command input mode
- Output formatting and syntax highlighting
