# Git File Permissions and .gitignore Files

## The Issue

If you see Git showing file permission changes for `.gitignore` files like this:

```diff
diff --git a/backend/bootstrap/cache/.gitignore b/backend/bootstrap/cache/.gitignore
old mode 100644
new mode 100755
```

This indicates that `.gitignore` files have been made executable (mode 755) when they should be regular files (mode 644).

## Why This Happens

The RayanPBX installation script sets permissions on Laravel's `storage` and `bootstrap/cache` directories to ensure the web server can write to them. Previously, the script used `chmod -R 775` which made **all files** in these directories executable, including `.gitignore` files.

## Why .gitignore Files Should NOT Be Executable

1. **They are text files**: `.gitignore` files contain patterns for Git to ignore, they are not scripts or programs
2. **Standard convention**: By convention, only scripts and binaries should have execute permissions
3. **Security best practice**: Following the principle of least privilege, files should only have the permissions they need
4. **Git tracking**: When `core.fileMode=true`, Git tracks these changes as modifications, creating unnecessary diffs

## The Solution

The install script has been updated to set correct permissions:

- **Directories**: `775` (rwxrwxr-x) - Allows web server to create files
- **Regular files**: `664` (rw-rw-r--) - Allows web server to write
- **`.gitignore` files**: `644` (rw-r--r--) - Read-only for group/others, not executable

## Preventing Git from Tracking File Mode Changes

If you want to prevent Git from tracking file permission changes entirely, you can configure:

```bash
git config core.fileMode false
```

This tells Git to ignore file mode changes. However, this is generally **not recommended** for production servers where file permissions are important for security.

## For Developers

If you're developing locally and see these permission changes:

1. **After running install.sh**: The script now sets correct permissions, this should not happen
2. **Working from Windows/WSL**: You may want to set `core.fileMode=false` as Windows filesystems don't preserve Unix permissions
3. **Checking current setting**: Run `git config core.fileMode` to see if it's enabled

## Summary

**Q: Is mode 755 (.gitignore files being executable) better or not?**
**A: No, mode 644 is correct. .gitignore files should not be executable.**

**Q: Why is my local machine trying to apply this diff?**
**A: Because something (likely the install script) changed the file permissions to 755, and Git's `core.fileMode` is set to `true`, so it detects this as a change.**

The fix is now in place - the install script properly sets 644 permissions for `.gitignore` files while maintaining 775 for directories and 664 for other files.
