# Installation Script Fix Documentation

## Problem Statement
The `install.sh` script was exiting silently after printing the banner, without showing any error messages. This made it impossible for users to diagnose and fix installation issues.

## Root Cause
The script uses `set -e` (exit on error) combined with many commands that suppress output:
- `apt-get update -qq`
- `nala install -y package > /dev/null 2>&1`
- `curl ... > /dev/null 2>&1`
- etc.

When any of these commands failed (e.g., due to network issues, permission problems, or missing dependencies), the script would exit immediately due to `set -e`, but no error message would be displayed because the output was suppressed.

## Solution
Added explicit error checking and proper error messages for all critical commands:

### Example Pattern
**Before:**
```bash
apt-get update -qq
apt-get install -y package > /dev/null 2>&1
```

**After:**
```bash
if ! apt-get update -qq 2>&1; then
    print_error "Failed to update package lists"
    print_warning "Check your internet connection and repository configuration"
    exit 1
fi
if ! apt-get install -y package > /dev/null 2>&1; then
    print_error "Failed to install package"
    exit 1
fi
```

## Changes Made

### 1. Package Manager Setup (nala)
- Added error checking for `apt-get update`
- Added fallback to `apt-get` if `nala` installation fails
- Introduced `PKG_MGR` variable to support both package managers

### 2. System Update
- Added error checking for package list updates
- Prompts user to continue if update fails
- Shows warnings instead of silent failures

### 3. Critical Package Installations
- Added error checking for all critical packages (MariaDB, PHP, Node.js, Composer, Go, PM2)
- Shows clear error messages when installations fail
- Distinguishes between required and optional components

### 4. Optional Package Installations
- GitHub CLI: Shows warning but continues if installation fails
- Figlet/lolcat: Non-critical, uses fallback banner if not available

## CI/CD Testing
Added a new job `test-install-script` in `.github/workflows/ci.yml` that:
- Validates script syntax with `bash -n`
- Tests root privilege detection
- Verifies error handling functions
- Checks OS version detection
- Ensures all basic tools are available

## Best Practices for Future Modifications

When adding new commands to `install.sh`:

1. **Always add error checking for commands that suppress output:**
   ```bash
   if ! command > /dev/null 2>&1; then
       print_error "Descriptive error message"
       exit 1  # or continue, depending on criticality
   fi
   ```

2. **Distinguish between critical and optional components:**
   - Critical: Exit with error message
   - Optional: Show warning and continue

3. **Use the PKG_MGR variable instead of hardcoding nala or apt-get:**
   ```bash
   $PKG_MGR install -y package
   ```

4. **Test the script syntax after modifications:**
   ```bash
   bash -n install.sh
   ```

5. **Update CI/CD tests if adding new critical functionality**

## Testing the Fix

To verify the fix works:

```bash
# Test 1: Syntax check
bash -n install.sh

# Test 2: Root detection
bash install.sh  # Should show error message about needing root

# Test 3: With sudo (will show proper error messages if anything fails)
sudo bash install.sh
```

## Related Files
- `/home/runner/work/rayanpbx/rayanpbx/install.sh` - Main installation script
- `/home/runner/work/rayanpbx/rayanpbx/.github/workflows/ci.yml` - CI/CD pipeline with tests
