# RayanPBX TUI Enhancements

## Overview

This document describes the enhancements made to the RayanPBX Terminal User Interface (TUI) to improve usability and functionality.

## New Features

### 1. Extension Creation (Press 'a' in Extensions Screen)

**How to Use:**
1. Launch the TUI: `rayanpbx-tui`
2. Select "📱 Extensions Management" from the main menu
3. Press the `a` key to create a new extension
4. Fill in the required fields:
   - Extension Number (e.g., 100, 101, etc.)
   - Name (display name for the extension)
   - Password (SIP secret for authentication)
5. Navigate between fields using ↑/↓ arrow keys
6. Press Enter on the last field to create the extension
7. Press ESC to cancel at any time

**Database Schema:**
The extension is created with the following defaults:
- `context`: 'from-internal'
- `transport`: 'transport-udp'
- `enabled`: 1 (true)
- Timestamps are automatically set

### 2. Trunk Creation (Press 'a' in Trunks Screen)

**How to Use:**
1. Launch the TUI: `rayanpbx-tui`
2. Select "🔗 Trunks Management" from the main menu
3. Press the `a` key to create a new trunk
4. Fill in the required fields:
   - Name (trunk identifier)
   - Host (SIP server hostname or IP)
   - Port (default: 5060)
   - Priority (routing priority, default: 1)
5. Navigate between fields using ↑/↓ arrow keys
6. Press Enter on the last field to create the trunk
7. Press ESC to cancel at any time

**Database Schema:**
The trunk is created with the following defaults:
- `enabled`: 1 (true)
- Timestamps are automatically set

### 3. Navigable CLI Usage Guide

**How to Use:**
1. Launch the TUI: `rayanpbx-tui`
2. Select "📖 CLI Usage Guide" from the main menu
3. Navigate through commands using ↑/↓ arrow keys
4. Press Enter on any command to see it ready for execution

**Available Commands:**
The usage guide includes commands organized by category:
- **Extensions**: List, create, and check status
- **Trunks**: List, test, and check status
- **Asterisk**: Service control and management
- **Diagnostics**: System health checks and testing
- **System**: Updates, logs, and status

**Note:** The current implementation displays the command that would be executed. To actually run commands, use them directly in your terminal.

## Key Bindings

### Main Menu
- `↑/↓` or `j/k`: Navigate menu items
- `Enter`: Select menu item
- `q` or `Ctrl+C`: Quit application

### Extensions/Trunks Screen
- `a`: Add new extension/trunk
- `↑/↓`: Navigate list (if applicable)
- `ESC`: Back to main menu
- `q`: Quit application

### CLI Usage Guide
- `↑/↓`: Navigate commands
- `Enter`: Show command for execution
- `ESC`: Back to main menu
- `q`: Quit application

### Input Mode (Creation Forms)
- `↑/↓`: Navigate between fields
- `Enter`: Move to next field / Submit form
- `Backspace`: Delete last character
- `ESC`: Cancel and return
- `q`: Quit application
- Any character: Type into current field

## Visual Feedback

- **Success messages** are displayed in green with a ✅ icon
- **Error messages** are displayed in red with a ❌ icon
- **Active field** in forms is highlighted with a ▶ cursor
- **Selected command** in usage guide is highlighted and shows description

## Implementation Details

### Data Structures

```go
type UsageCommand struct {
    Category    string  // Command category (Extensions, Trunks, etc.)
    Command     string  // The actual CLI command
    Description string  // Brief description of what it does
}
```

### New Screens
- `createExtensionScreen`: Form for creating extensions
- `createTrunkScreen`: Form for creating trunks

### Input Handling
The TUI now supports an `inputMode` flag that switches keyboard handling to form input mode, allowing character input for text fields.

## Future Enhancements

Potential improvements for future versions:
1. Actually execute CLI commands from the TUI
2. Edit existing extensions/trunks
3. Delete extensions/trunks with confirmation
4. Real-time validation of input fields
5. More detailed error messages with suggestions
6. Bulk operations (import/export)
7. Search/filter functionality in list views

## Technical Notes

- The TUI is built using the Bubble Tea framework
- Database operations use prepared statements for security
- All inputs should be validated before database insertion
- Passwords are masked with asterisks in the UI
- The implementation follows Go best practices for error handling

## Screenshots

### Main Menu
```
🎯 RayanPBX - Modern SIP Server Management 🚀

🏠 Main Menu

▶ 📱 Extensions Management
  🔗 Trunks Management
  ⚙️  Asterisk Management
  🔍 Diagnostics & Debugging
  📊 System Status
  📋 Logs Viewer
  📖 CLI Usage Guide
  ❌ Exit
```

### Extensions List (with 'a' key help)
```
📱 Extensions Management

Total Extensions: 2

  100 - John Doe (🟢 Enabled)
  101 - Jane Smith (🟢 Enabled)

💡 Tip: Extensions allow users to make and receive calls

↑/↓: Navigate • a: Add Extension • ESC: Back • q: Quit
```

### Create Extension Form
```
📱 Create New Extension

▶ Extension Number: 102
  Name: <enter value>
  Password: <enter value>

💡 Fill in all fields and press Enter on the last field to create

↑/↓: Navigate Fields • Enter: Next/Submit • ESC: Cancel • q: Quit
```

### CLI Usage Guide (Navigable)
```
📖 CLI Usage Guide

Navigate with ↑/↓ and press Enter to execute:

Extensions:
▶ rayanpbx-cli extension list
   └─ List all configured extensions
  rayanpbx-cli extension create <num> <name> <pass>
  rayanpbx-cli extension status <num>

Trunks:
  rayanpbx-cli trunk list
  rayanpbx-cli trunk test <name>
  ...
```

## Testing

To test the new features:

1. **Build the TUI:**
   ```bash
   cd /home/runner/work/rayanpbx/rayanpbx/tui
   go build -o rayanpbx-tui
   ```

2. **Run the TUI:**
   ```bash
   ./rayanpbx-tui
   ```

3. **Test Extension Creation:**
   - Navigate to Extensions Management
   - Press 'a'
   - Fill in fields
   - Press Enter to submit

4. **Test Trunk Creation:**
   - Navigate to Trunks Management
   - Press 'a'
   - Fill in fields
   - Press Enter to submit

5. **Test CLI Usage Guide:**
   - Navigate to CLI Usage Guide
   - Use arrow keys to navigate
   - Press Enter on commands

## Troubleshooting

**Issue:** TUI won't start
- Check database connection settings in `.env`
- Ensure MySQL is running
- Verify database credentials

**Issue:** Cannot create extension/trunk
- Check database permissions
- Verify all required fields are filled
- Check for duplicate extension numbers

**Issue:** Input not working
- Ensure terminal supports interactive mode
- Try a different terminal emulator
- Check terminal size (minimum recommended: 80x24)

## Credits

Built with:
- [Bubble Tea](https://github.com/charmbracelet/bubbletea) - TUI framework
- [Lipgloss](https://github.com/charmbracelet/lipgloss) - Styling
- [Go MySQL Driver](https://github.com/go-sql-driver/mysql) - Database access
