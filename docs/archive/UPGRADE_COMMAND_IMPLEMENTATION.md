# Upgrade Command Implementation

This document describes the implementation of upgrade commands in rayanpbx-cli and rayanpbx-tui.

## Overview

Two new commands have been added to make it easier for users to upgrade their RayanPBX installation:

1. **CLI Command**: `rayanpbx-cli system upgrade`
2. **TUI Option**: "Run System Upgrade" in System Settings menu

Both commands launch the existing `upgrade.sh` script from the installed path.

## Usage

### Command Line Interface (CLI)

Run the upgrade from the command line:

```bash
# Basic upgrade
rayanpbx-cli system upgrade

# With interactive confirmation
rayanpbx-cli system upgrade --confirm

# With backup
rayanpbx-cli system upgrade --backup

# With both
rayanpbx-cli system upgrade --confirm --backup
```

The command will:
1. Look for the upgrade script in the script directory or `/opt/rayanpbx/scripts/`
2. Execute it with sudo privileges
3. Pass through any additional arguments to the upgrade script

### Terminal User Interface (TUI)

Launch the TUI and navigate to the upgrade option:

```bash
# Start the TUI
rayanpbx-tui

# Then navigate:
# Main Menu → System Settings → Run System Upgrade
```

The TUI will:
1. Display a message about launching the upgrade
2. Exit gracefully
3. The upgrade script will continue in the terminal

## Implementation Details

### rayanpbx-cli.sh

Added a new `cmd_system_upgrade()` function that:
- Checks for the upgrade script in common locations
- Executes it with sudo using `exec` to replace the current shell
- Passes through all command-line arguments

Location in code: Line ~454

### tui/main.go

Added functionality in several places:
1. **Menu Option**: Added "🚀 Run System Upgrade" to System Settings menu (~line 1677)
2. **Handler**: Updated `handleSystemSettingsAction()` to handle the new option (~line 1715)
3. **Function**: Implemented `runSystemUpgrade()` to launch the script (~line 1798)
4. **Navigation**: Updated cursor limits for the system settings screen (~line 322)

## Script Location

The upgrade script is expected to be at:
- Development: `./scripts/upgrade.sh`
- Installed: `/opt/rayanpbx/scripts/upgrade.sh`

Both locations are checked by the CLI command. The TUI only checks the installed location since it's typically run after installation.

## Testing

### Syntax and Compilation
- ✅ Bash syntax check: `bash -n scripts/rayanpbx-cli.sh`
- ✅ Go compilation: `cd tui && go build`
- ✅ Help text verification: `rayanpbx-cli help | grep upgrade`

### Manual Testing
To manually test:

1. **CLI Test** (without actual upgrade):
   ```bash
   # Check help displays the command
   rayanpbx-cli help | grep upgrade
   
   # Try to run (will fail if not installed, but tests argument parsing)
   rayanpbx-cli system upgrade --help
   ```

2. **TUI Test**:
   ```bash
   # Launch TUI
   rayanpbx-tui
   
   # Navigate: System Settings → Run System Upgrade
   # Verify the menu option appears
   ```

## Related Files

- `scripts/rayanpbx-cli.sh` - CLI implementation
- `tui/main.go` - TUI implementation  
- `scripts/upgrade.sh` - The upgrade script being called
- `install.sh` - The actual installation script (called by upgrade.sh with --upgrade flag)

## Notes

- The upgrade requires sudo privileges
- All arguments are passed through to the upgrade script
- The TUI exits when upgrade is selected to allow the upgrade script to take over the terminal
- The CLI uses `exec` to replace itself with the upgrade script for a seamless transition
