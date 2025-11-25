# Verbose Flag Preservation Fix

## Issue

When `install.sh` detected a repository update and restarted itself, command-line flags like `-v`/`--verbose` were not passed to the restarted script, causing verbose output to be lost.

## Root Cause

The argument parsing loop in `install.sh` uses `shift` to consume command-line arguments:

```bash
while [[ $# -gt 0 ]]; do
    case $1 in
        -v|--verbose)
            VERBOSE=true
            shift  # <-- This removes the argument from $@
            ;;
        # ... other cases
    esac
done
```

After this loop completes, `$@` is empty because all arguments have been consumed by `shift`. When the script restarts using:

```bash
exec "$SCRIPT_DIR/install.sh" "$@"  # $@ is now empty!
```

The original flags are lost.

## Solution

Save the original arguments **before** they are parsed:

```bash
# Save original arguments before parsing for use in script restart
ORIGINAL_ARGS=("$@")

while [[ $# -gt 0 ]]; do
    # ... parsing logic ...
done
```

Then use the saved arguments when restarting:

```bash
exec "$SCRIPT_DIR/$(basename "${BASH_SOURCE[0]}")" "${ORIGINAL_ARGS[@]}"
```

## Changes Made

### install.sh

1. **Line 500**: Added `ORIGINAL_ARGS=("$@")` before the argument parsing loop
2. **Line 628**: Changed exec command from `"$@"` to `"${ORIGINAL_ARGS[@]}"`

## Testing

### Unit Tests (test-verbose-flag-preservation.sh)

Created comprehensive test suite with 8 tests:
1. ✅ ORIGINAL_ARGS is saved before parsing
2. ✅ ORIGINAL_ARGS is defined before parsing loop
3. ✅ exec command uses ORIGINAL_ARGS
4. ✅ exec command uses correct array expansion syntax
5. ✅ Mock script correctly preserves --verbose flag
6. ✅ Explanatory comment exists
7. ✅ install.sh syntax is valid
8. ✅ Both -v and --verbose formats are handled

### Integration Test (test-verbose-integration.sh)

Demonstrates the fix in action:
- Creates a mock git repository
- Simulates an update scenario
- Verifies verbose flag is preserved across script restart
- Tests both `--verbose` and `-v` flag formats

### Existing Tests

All existing tests in `test-install-fixes.sh` continue to pass (8/8).

## How to Verify the Fix

Run the test suite:

```bash
# Unit tests
./tests/test-verbose-flag-preservation.sh

# Integration test
./tests/test-verbose-integration.sh

# Existing tests
./tests/test-install-fixes.sh
```

Or manually test:

```bash
# Run install script with verbose flag
sudo ./install.sh --verbose

# If an update is available, accept it and verify that
# the restarted script still shows verbose output
```

## Technical Details

### Why Array Expansion?

We use `"${ORIGINAL_ARGS[@]}"` instead of `"$ORIGINAL_ARGS"` because:

- `"${ORIGINAL_ARGS[@]}"` expands to separate quoted arguments: `"--verbose" "-v"`
- `"$ORIGINAL_ARGS"` would expand to a single string: `"--verbose -v"`

This is crucial for proper argument handling.

### Why Save Before Parsing?

The `shift` command modifies the positional parameters (`$@`). Once an argument is shifted, it's removed from the list. By saving `$@` into an array before any shifts occur, we preserve the original command-line arguments for later use.

## Benefits

1. ✅ Users can run `./install.sh --verbose` and maintain verbose output through updates
2. ✅ All command-line flags are now preserved during script restart
3. ✅ No breaking changes to existing functionality
4. ✅ Comprehensive test coverage ensures the fix works correctly

## upgrade.sh Verbose Flag Fix

A similar issue existed in `scripts/upgrade.sh` where passing `-v` as the first argument would cause the argument parsing loop to break early, preventing other flags like `-i` and `-b` from being processed.

### Root Cause

The argument parsing loop in `upgrade.sh` used `break` for unknown arguments:

```bash
while [[ $# -gt 0 ]]; do
    case $1 in
        -i|--confirm) ... ;;
        -b|--backup) ... ;;
        *)
            break  # <-- This stopped processing on first unknown arg like -v
            ;;
    esac
done
```

If a user ran `./upgrade.sh -v -i -b`, the script would:
1. See `-v`, not recognize it, and break out of the loop
2. Never process `-i` or `-b`
3. Pass all remaining args to install.sh

### Solution

Changed the `break` to collect unknown arguments into a `PASSTHROUGH_ARGS` array:

```bash
PASSTHROUGH_ARGS=()

while [[ $# -gt 0 ]]; do
    case $1 in
        -i|--confirm) ... ;;
        -b|--backup) ... ;;
        *)
            # Collect all other arguments to pass through to install.sh
            PASSTHROUGH_ARGS+=("$1")
            shift
            ;;
    esac
done

# Pass collected arguments to install.sh
exec "$INSTALL_SCRIPT" $INSTALL_ARGS "${PASSTHROUGH_ARGS[@]}"
```

### Testing

Run the test suite to verify the fix:

```bash
./tests/test-upgrade-verbose-flag.sh
```

## Related Files

- `install.sh` - Main installation script (lines 500, 628)
- `scripts/upgrade.sh` - Upgrade wrapper script (fixed argument parsing)
- `tests/test-verbose-flag-preservation.sh` - Unit test suite for install.sh
- `tests/test-upgrade-verbose-flag.sh` - Unit test suite for upgrade.sh
- `tests/test-verbose-integration.sh` - Integration test
- `tests/test-install-fixes.sh` - Existing test suite (still passes)
