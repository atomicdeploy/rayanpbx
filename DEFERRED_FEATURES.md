# Deferred Features - RayanPBX

## Overview

This document tracks features that have been identified from IncrediblePBX and IssabelPBX but are deferred for future implementation. These features are optional, require additional planning, or need specific use cases before implementation.

## Status: DEFERRED FOR FUTURE RELEASES

---

## Security Features (Deferred)

### 1. Port Knocking (knockd)
**Status**: Deferred
**Reason**: Advanced security feature that requires careful configuration and user understanding

**Description**:
- Port knocking provides an additional layer of security by hiding services until a specific sequence of port "knocks" is received
- Uses knockd daemon to monitor network traffic

**Implementation Notes**:
- Would require random port generation (e.g., 6001-9950 range)
- Configuration file: `/etc/knockd.conf`
- Network interface auto-detection needed
- Integration with iptables/ufw

**Future Consideration**:
- Could be added as `--with-advanced-security` flag
- Requires user education about port knocking concepts
- Alternative: Focus on UFW firewall + fail2ban which are already implemented

**References**:
- IncrediblePBX implementation: Lines 428-471 in IncrediblePBX2025.sh
- Package: `knockd`

---

### 2. IPv6 Disabling
**Status**: Deferred - Anti-pattern
**Reason**: Disabling IPv6 without user consent is considered an anti-pattern in modern networking

**Description**:
- Some older PBX systems disable IPv6 by default for "security"
- Modern networks often use IPv6, and disabling it can cause issues

**Decision**:
- **DO NOT implement automatic IPv6 disabling**
- Ubuntu 24.04 handles IPv6 security properly by default
- Users who specifically need to disable IPv6 can do so manually

**Manual Disable (if needed)**:
```bash
# Create configuration file
sudo tee /etc/sysctl.d/70-disable-ipv6.conf << EOF
net.ipv6.conf.all.disable_ipv6 = 1
net.ipv6.conf.default.disable_ipv6 = 1
net.ipv6.conf.lo.disable_ipv6 = 0
EOF

# Apply changes
sudo sysctl -p -f /etc/sysctl.d/70-disable-ipv6.conf
```

**References**:
- IncrediblePBX implementation: Lines 312-318 in IncrediblePBX2025.sh
- Modern best practice: Keep IPv6 enabled unless specific network requirements

---

## Network Features (Deferred)

### 3. OpenVPN Integration
**Status**: Deferred
**Reason**: Complex feature requiring individual user VPN configurations

**Description**:
- OpenVPN provides secure remote access to PBX
- IncrediblePBX includes preconfigured OpenVPN setups

**Implementation Challenges**:
- Each deployment needs unique VPN keys and certificates
- Configuration varies widely based on network topology
- Requires ongoing certificate management
- May conflict with existing VPN solutions

**Future Consideration**:
- Could provide OpenVPN configuration templates
- Better served as a separate guide/documentation
- Users with VPN needs likely have existing solutions

**Alternative Solutions**:
- SSH tunneling (already available)
- WireGuard (modern, simpler VPN alternative)
- Tailscale or similar mesh VPN services

**References**:
- IncrediblePBX implementation: Lines 195-210 in IncrediblePBX2025.sh
- Package: `openvpn`

---

### 4. NTP Time Synchronization
**Status**: Deferred - Already Handled
**Reason**: Ubuntu 24.04 includes systemd-timesyncd by default

**Description**:
- Network Time Protocol ensures accurate system time
- Critical for call logging and CDR accuracy

**Current Implementation**:
- Ubuntu 24.04 uses `systemd-timesyncd` by default
- Automatically syncs with Ubuntu's NTP servers
- No additional configuration needed

**Decision**:
- Do not install separate `ntp` package
- Use built-in `systemd-timesyncd`
- Already configured in current install script

**Manual NTP Configuration (if needed)**:
```bash
# Check timesyncd status
timedatectl status

# Configure custom NTP servers (optional)
sudo nano /etc/systemd/timesyncd.conf
# Add: NTP=0.pool.ntp.org 1.pool.ntp.org

# Restart service
sudo systemctl restart systemd-timesyncd
```

**References**:
- IncrediblePBX implementation: Lines 351-353 in IncrediblePBX2025.sh
- Ubuntu documentation: https://ubuntu.com/server/docs/network-ntp

---

## Web Administration Features (Deferred)

### 5. Webmin Installation
**Status**: Deferred
**Reason**: RayanPBX has its own web interface; Webmin adds complexity

**Description**:
- Webmin is a web-based system administration tool
- IncrediblePBX installs it on port 9001

**Reasons for Deferral**:
- RayanPBX already has a comprehensive web UI
- Additional attack surface
- Different authentication system to manage
- Most features covered by RayanPBX CLI and web UI

**Future Consideration**:
- Could be optional for advanced system administrators
- Would require careful security configuration
- Alternative: Improve RayanPBX's built-in system management features

**Manual Installation (if desired)**:
```bash
# Add Webmin repository
echo "deb http://download.webmin.com/download/repository sarge contrib" | sudo tee -a /etc/apt/sources.list

# Add GPG key
wget -qO- http://www.webmin.com/jcameron-key.asc | sudo apt-key add -

# Install
sudo apt update
sudo apt install webmin

# Access at https://your-server:10000
```

**References**:
- IncrediblePBX implementation: Lines 393-397 in IncrediblePBX2025.sh
- Package: `webmin`

---

## Communication Features (Deferred)

### 6. Today Weather TTS Script
**Status**: Deferred
**Reason**: Nice-to-have feature, not essential for PBX operation

**Description**:
- IncrediblePBX includes a script that generates daily weather TTS messages
- Uses gTTS to create audio files with current weather

**Implementation Notes**:
- Requires weather API integration
- Needs location configuration
- Daily cron job to update
- TTS must be installed

**Future Consideration**:
- Could be added as example script in documentation
- Users can implement custom TTS applications once TTS is installed
- Better suited as a plugin or module

**Example Implementation**:
```python
#!/usr/bin/env python3
from gtts import gTTS
import requests

# Get weather data
weather_api = "https://api.weather.gov/..."
# Generate TTS
tts = gTTS("Today's weather is...")
tts.save("/var/lib/asterisk/sounds/custom/weather.mp3")
```

**References**:
- IncrediblePBX implementation: Lines 478-482 in IncrediblePBX2025.sh
- Script: `nv-today.php`

---

### 7. Gmail SMTP Relay Configuration
**Status**: Deferred - Email Server Now Optional
**Reason**: Highly specific to individual email configurations

**Current Implementation**:
- Full email server (Postfix + Dovecot) available via `--with-email` flag
- Provides complete SMTP, IMAP, and POP3 functionality
- Users can configure relay to any SMTP provider after installation

**Description**:
- IncrediblePBX includes helper script for Gmail SMTP relay
- Allows sending emails through Gmail

**Implementation Challenges**:
- Requires Gmail app passwords (2FA)
- Gmail policies change frequently
- Better alternatives exist (SendGrid, Mailgun, etc.)
- Privacy concerns with Google integration

**Future Consideration**:
- Provide documentation for various SMTP providers
- Generic SMTP configuration is available when using `--with-email`
- Users can configure their preferred email service

**Manual Configuration Example**:
```bash
# Install email server first
sudo ./install.sh --with-email

# Then configure Postfix for Gmail relay
sudo apt install libsasl2-modules
sudo postconf -e "relayhost = [smtp.gmail.com]:587"
sudo postconf -e "smtp_sasl_auth_enable = yes"
# ... additional configuration
```

**References**:
- IncrediblePBX implementation: enable-gmail-smarthost-with-postfix script
- Alternative: Use any SMTP service with installed Postfix

---

## System Features (Deferred)

### 8. pbxstatus Monitoring Tool
**Status**: Deferred
**Reason**: RayanPBX CLI provides equivalent functionality

**Description**:
- IncrediblePBX custom monitoring tool
- Displays system status, Asterisk info, network details

**Current Alternative**:
- `rayanpbx-cli diag health-check` - System health
- `rayanpbx-cli system info` - System information
- `rayanpbx-cli system services` - Service status
- Standard tools: `htop`, `systemctl status`

**Decision**:
- Do not create separate monitoring tool
- Enhance RayanPBX CLI diagnostics instead
- Use standard Linux tools (htop, netstat, etc.)

**References**:
- IncrediblePBX implementation: pbxstatus-2027 binary
- Lines 344-346, 431 in IncrediblePBX2025.sh

---

### 9. Custom Bash Profiles
**Status**: Partially Implemented
**Reason**: User preferences vary; minimal implementation sufficient

**Current Implementation**:
- VIM configuration (`.vimrc`)
- Shell aliases (`ls`, `ll`, `la`) with colors

**Deferred Items**:
- Auto-running pbxstatus on login
- Custom prompt modifications
- User-specific bash customizations

**Decision**:
- Provide sensible defaults
- Let users customize their own environments
- Don't force specific workflows

**References**:
- IncrediblePBX implementation: Lines 356-363 in IncrediblePBX2025.sh

---

## Implementation Priority

### Current Implementation (Already Done)
- ✅ Fail2ban for Asterisk security
- ✅ UFW firewall management (via CLI)
- ✅ Sound file management
- ✅ Certificate management (Let's Encrypt)
- ✅ FAX support preparation
- ✅ Log rotation
- ✅ VIM and shell aliases
- ✅ systemd-timesyncd (built-in)

### Optional Features (Flag-based)
- ✅ Text-to-Speech (gTTS + Piper) - Use `--with-tts` flag
- ✅ Email Server (Postfix + Dovecot) - Use `--with-email` flag

### Future Releases (Needs Planning)
1. **Advanced Security Suite** (`--with-advanced-security`)
   - Port knocking (knockd)
   - IDS/IPS integration
   - Enhanced logging
   
2. **VPN Configuration Templates**
   - OpenVPN setup guide
   - WireGuard templates
   
3. **Enhanced Monitoring Dashboard**
   - Real-time statistics
   - Performance metrics
   - Alert system

### Not Recommended
- ❌ Automatic IPv6 disabling
- ❌ Webmin (conflicts with RayanPBX UI)
- ❌ Custom bash profiles (too opinionated)

---

## User Feedback

If you need any of these deferred features, please:

1. **Open a GitHub Issue**: https://github.com/atomicdeploy/rayanpbx/issues
2. **Describe your use case**: Why do you need this feature?
3. **Provide details**: How would you use it?

Features with sufficient demand will be prioritized for future releases.

---

## Summary

**Implemented**: Essential security, system tools, and communication features  
**Optional**: TTS engines (via `--with-tts`)  
**Deferred**: Advanced features requiring more planning or user-specific configuration  
**Rejected**: Anti-patterns like automatic IPv6 disabling

This approach ensures a secure, modern installation while maintaining flexibility for advanced users.

---

**Document Version**: 1.0  
**Last Updated**: November 23, 2025  
**Status**: Living document - will be updated as features are reconsidered
