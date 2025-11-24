# RayanPBX Installation Script - Command-Line Options

## Overview

The RayanPBX installation script (`install.sh`) now supports command-line options to help with debugging, getting information, and controlling the installation process.

## Available Options

### Help (`-h`, `--help`)

Display usage information, requirements, examples, and available options.

```bash
./install.sh --help
# or
./install.sh -h
```

**Output:**
- Script version
- Usage syntax
- Description of what the script does
- List of all available options
- System requirements
- Usage examples
- Links to documentation and support

### List Steps (`--list-steps`)

Display all available installation steps with their identifiers.

```bash
./install.sh --list-steps
```

**Output:**
```
Available Installation Steps:

   1. updates              Checking for Updates
   2. system-verification  System Verification
   3. package-manager      Package Manager Setup
   ...
  23. complete             Installation Complete

Usage Examples:
  # Run only specific steps:
  sudo ./install.sh --steps=backend,frontend,tui
  
  # Skip certain steps:
  sudo ./install.sh --skip=asterisk,asterisk-ami
```

### Run Specific Steps (`--steps=STEPS`)

Run only the specified installation steps (comma-separated). Steps will always run in their defined order regardless of the order specified.

```bash
sudo ./install.sh --steps=backend,frontend,tui
```

**When to use:**
- Upgrading specific components without full reinstallation
- Faster installation when dependencies are already met
- CI/CD pipelines that only need certain components
- Development environments with selective components

**Important:** Ensure all dependencies are already installed. See [INSTALL_STEPS_GUIDE.md](INSTALL_STEPS_GUIDE.md) for dependency information.

**Examples:**
```bash
# Update backend only (requires: PHP, Composer, database already installed)
sudo ./install.sh --steps=source,env-config,backend,systemd

# Update frontend only (requires: Node.js already installed)
sudo ./install.sh --steps=source,env-config,frontend,pm2

# Update TUI only (requires: Go already installed)
sudo ./install.sh --steps=source,tui,pm2

# Install backend and frontend without Asterisk
sudo ./install.sh --steps=system-update,dependencies,database,php,composer,nodejs,source,env-config,backend,frontend,pm2,systemd
```

### Skip Steps (`--skip=STEPS`)

Skip specified installation steps (comma-separated).

```bash
sudo ./install.sh --skip=asterisk,asterisk-ami
```

**When to use:**
- Skip time-consuming steps like Asterisk compilation
- Development environments that don't need all components
- Testing specific configurations

**Examples:**
```bash
# Skip Asterisk installation (saves ~30 minutes)
sudo ./install.sh --skip=asterisk,asterisk-ami

# Skip GitHub CLI (optional component)
sudo ./install.sh --skip=github-cli

# Skip updates check
sudo ./install.sh --skip=updates
```

### CI Mode (`--ci`)

Enable CI/CD mode which skips root checks and runs non-interactively.

```bash
./install.sh --ci --steps=backend,frontend
```

**When to use:**
- GitHub Actions or other CI/CD pipelines
- Automated testing environments
- Non-interactive installations

**What it does:**
- Skips root privilege check (for containerized environments)
- Assumes non-interactive mode
- Suitable for automated environments

### Backup (`-b`, `--backup`)

Create backup before updates (.env and backend/storage).

```bash
sudo ./install.sh --backup --upgrade
```

**When to use:**
- Before major upgrades
- When you want to preserve configuration and data
- Production environments

### Upgrade (`-u`, `--upgrade`)

Automatically apply updates without prompting for confirmation when updates are available.

```bash
sudo ./install.sh --upgrade
# or
sudo ./install.sh -u
```

**When to use:**
- Automated deployments or CI/CD pipelines
- Scripted installations where interactive prompts are not desired
- When you always want the latest version without manual confirmation

**What it does:**
- Automatically fetches the latest changes from the repository
- Skips the "Pull updates and restart installation? (y/n)" prompt
- Automatically pulls updates and restarts the installation with the new version
- Works seamlessly with other flags (e.g., `--upgrade --verbose`)

**Example:**
```bash
# Automatically upgrade and show verbose output
sudo ./install.sh --upgrade --verbose

# Or using short flags
sudo ./install.sh -u -v
```

### Version (`-V`, `--version`)

Display the script version information.

```bash
./install.sh --version
# or
./install.sh -V
```

**Output:**
```
RayanPBX Installation Script v2.0.0
For Ubuntu 24.04 LTS
```

### Verbose Mode (`-v`, `--verbose`)

Enable detailed output showing what the script is doing at each step. This is extremely helpful for debugging installation issues.

```bash
sudo ./install.sh --verbose
# or
sudo ./install.sh -v
```

**What Verbose Mode Shows:**
- System information (kernel, user, hostname)
- Detailed progress of each installation step
- Command execution details
- Version information for installed packages
- File locations and paths
- Error details with line numbers when failures occur
- Full command output (instead of suppressing it)

**Example Verbose Output:**
```
[VERBOSE] Verbose mode enabled
[VERBOSE] Starting RayanPBX installation script v2.0.0
[VERBOSE] System: Linux hostname 6.11.0-1018-azure #18~24.04.1-Ubuntu SMP ...
[VERBOSE] User: root
[VERBOSE] Displaying banner...
[VERBOSE] figlet found, checking for slant font...
[VERBOSE] Using figlet with lolcat
...
[VERBOSE] Checking if running as root (EUID: 0)...
[VERBOSE] Root check passed
[VERBOSE] Checking Ubuntu version...
[VERBOSE] Contents of /etc/os-release:
PRETTY_NAME="Ubuntu 24.04.1 LTS"
NAME="Ubuntu"
...
```

### Dry Run (`--dry-run`)

**Note:** This option is currently a placeholder for future implementation.

```bash
sudo ./install.sh --dry-run
```

This will eventually allow you to simulate the installation process without making actual changes to the system.

## Usage Examples

### Standard Installation

```bash
sudo ./install.sh
```

### List Available Steps

```bash
./install.sh --list-steps
```

### Step-Based Installation

#### Update Only Backend
```bash
# Assumes PHP, Composer, and database are already installed
sudo ./install.sh --steps=source,env-config,backend,systemd
```

#### Update Only Frontend
```bash
# Assumes Node.js is already installed
sudo ./install.sh --steps=source,env-config,frontend,pm2
```

#### Update Only TUI
```bash
# Assumes Go is already installed
sudo ./install.sh --steps=source,tui,pm2
```

#### Install Without Asterisk
```bash
# Useful for development environments
sudo ./install.sh --skip=asterisk,asterisk-ami
```

### Installation with Debugging

If you encounter issues during installation, use verbose mode to see detailed information:

```bash
sudo ./install.sh --verbose
```

This will help you identify:
- Which step is failing
- What command is causing the issue
- Network connectivity problems
- Package installation failures
- Permission issues

### Automatic Upgrade (Non-Interactive)

For automated deployments or when you always want the latest version:

```bash
sudo ./install.sh --upgrade
```

This will automatically pull updates without prompting for confirmation.

### Automatic Upgrade with Backup

Create a backup before applying updates:

```bash
sudo ./install.sh --upgrade --backup
```

### Combined Flags

You can combine multiple flags for different behaviors:

```bash
# Automatic upgrade with verbose output (recommended for troubleshooting)
sudo ./install.sh --upgrade --verbose

# Or using short flags
sudo ./install.sh -u -v

# Update backend only with verbose output
sudo ./install.sh --steps=backend --verbose

# Install without Asterisk, with backup
sudo ./install.sh --skip=asterisk,asterisk-ami --backup
```

### CI/CD Mode

For automated testing and CI/CD pipelines:

```bash
# Run specific steps in CI mode
./install.sh --ci --steps=backend,frontend,tui --verbose
```

### Getting Help

```bash
./install.sh --help
```

### Checking Version

```bash
./install.sh --version
```

## Troubleshooting with Verbose Mode

When the installation script exits unexpectedly or fails silently, verbose mode can help diagnose the issue:

1. **Run with verbose mode:**
   ```bash
   sudo ./install.sh --verbose 2>&1 | tee install.log
   ```
   This saves all output to `install.log` for later review.

2. **Look for the last `[VERBOSE]` message** to identify where the script stopped.

3. **Check for error messages** that appear after the last successful step.

4. **Common issues verbose mode helps identify:**
   - Network connectivity problems (curl/wget failures)
   - Repository issues (apt-get update failures)
   - Missing dependencies
   - Permission problems
   - Disk space issues
   - Package conflicts

## Error Handling Improvements

The script now includes:

1. **Better error messages:** Clear indication of what went wrong and suggestions for fixing it.

2. **Error trap handler:** In verbose mode, shows the exact line number and command that failed.

3. **Graceful fallbacks:** For optional components (like figlet, lolcat, GitHub CLI), the script continues with warnings instead of failing completely.

4. **Input validation:** Checks for required inputs and validates them before proceeding.

## Requirements

- Ubuntu 24.04 LTS (recommended, other versions may work with warnings)
- Root privileges (must run with `sudo`)
- Internet connection for downloading packages
- At least 4GB RAM
- At least 10GB free disk space

## Support

If you encounter issues even with verbose mode enabled:

1. Save the installation log:
   ```bash
   sudo ./install.sh --verbose 2>&1 | tee rayanpbx-install.log
   ```

2. Create an issue on GitHub with:
   - The complete log file
   - Your Ubuntu version: `lsb_release -a`
   - Available disk space: `df -h`
   - Available memory: `free -h`

3. GitHub Issues: https://github.com/atomicdeploy/rayanpbx/issues

## Version History

### v2.0.0
- Added command-line options support
- Added `--help`, `--version`, `--verbose`, `--dry-run` flags
- Added `--steps`, `--skip`, `--list-steps`, `--ci`, `--backup`, `--upgrade` flags
- Implemented step-based installation system with 23 identifiable steps
- Added dependency warnings for selective step execution
- Improved error handling and reporting
- Fixed potential silent failures in banner display
- Added comprehensive verbose logging throughout the script
- Added error trap handler for better debugging
- Created INSTALL_STEPS_GUIDE.md for dependency documentation
- Updated CI/CD pipeline to test step functionality
