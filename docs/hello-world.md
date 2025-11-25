# RayanPBX Hello World Setup Guide

> 🚀 Get your first phone call working in minutes with RayanPBX's automated Hello World Setup!

This guide explains how RayanPBX automates the [Asterisk Hello World](https://docs.asterisk.org/Getting-Started/Hello-World/) setup, so you can make your first phone call with just a few clicks.

## Table of Contents

1. [Overview](#overview)
2. [Prerequisites](#prerequisites)
3. [Quick Start (TUI)](#quick-start-tui)
4. [What Gets Configured](#what-gets-configured)
5. [Configure Your SIP Phone](#configure-your-sip-phone)
6. [Make the Call](#make-the-call)
7. [Cleanup](#cleanup)
8. [Troubleshooting](#troubleshooting)
9. [Manual Configuration (Reference)](#manual-configuration-reference)

---

## Overview

RayanPBX's Hello World Setup automatically configures:

1. **PJSIP Transport** - UDP transport on port 5060
2. **Test Extension (101)** - A SIP endpoint for your phone to register
3. **Hello World Dialplan** - Dial 100 to hear "Hello World!"
4. **Asterisk Reload** - Automatically applies all changes

**No manual configuration required!**

---

## Prerequisites

Before running the Hello World Setup, ensure:

### Network Requirements

- ✅ Your **SIP phone** is on the same LAN as the Asterisk server, OR you can install a softphone (MicroSIP recommended)
- ✅ If using a hardware phone, both the phone and Asterisk can reach each other on the same subnet
- ✅ Port **5060/UDP** is open on any firewall between the phone and server

### Software Requirements

- ✅ **RayanPBX installed** with Asterisk from source (`make samples` was run during installation)
- ✅ **chan_pjsip** channel driver is available (included if you followed the [Installing pjproject](https://docs.asterisk.org/Getting-Started/Installing-Asterisk/Installing-Asterisk-From-Source/PJSIP-pjproject/) guide)
- ✅ **Sound files** installed in `/var/lib/asterisk/sounds/en/`

### Required Configuration Files

The installer should have created these files in `/etc/asterisk/`:

- `asterisk.conf` - Main Asterisk configuration
- `modules.conf` - Module loading configuration  
- `extensions.conf` - Dialplan (modified by Hello World Setup)
- `pjsip.conf` - SIP configuration (modified by Hello World Setup)

If these files don't exist, run `make samples` from the Asterisk source directory.

---

## Quick Start (TUI)

The fastest way to set up Hello World:

### Step 1: Launch RayanPBX TUI

```bash
sudo rayanpbx-tui
```

### Step 2: Run the Setup Wizard

1. Select **🚀 Hello World Setup** (first menu item)
2. Select **🚀 Run Complete Setup**
3. Wait for the setup to complete
4. Note the SIP credentials displayed:
   - **Username**: `101`
   - **Password**: `101pass`
   - **Server**: Your server's IP address
   - **Port**: `5060`

### Step 3: Verify Status

The status panel shows:
- ✅ Transport: Configured
- ✅ Extension 101: Configured
- ✅ Dialplan (ext 100): Configured
- ✅ Asterisk: Running

If any item shows ❌, run the setup again or check the troubleshooting section.

---

## What Gets Configured

### pjsip.conf

The setup adds these sections:

```ini
; RayanPBX Transport
[transport-udp]
type=transport
protocol=udp
bind=0.0.0.0

; RayanPBX Hello World Extension
[101]
type=endpoint
context=from-internal
disallow=all
allow=ulaw
auth=101
aors=101

[101]
type=auth
auth_type=userpass
password=101pass
username=101

[101]
type=aor
max_contacts=1
```

### extensions.conf

The setup adds:

```ini
; RayanPBX Hello World Dialplan
[from-internal]
exten = 100,1,Answer()
same = n,Wait(1)
same = n,Playback(hello-world)
same = n,Hangup()
```

---

## Configure Your SIP Phone

### Option 1: MicroSIP (Windows - Recommended)

1. Download MicroSIP from https://www.microsip.org/
2. Open MicroSIP and click the settings button
3. Click **Add new SIP account**
4. Enter `101` for the account name, click OK
5. Configure:
   - **Domain**: Your Asterisk server IP (shown in TUI)
   - **Username**: `101`
   - **Password**: `101pass`
   - **Caller ID Name**: Leave blank or enter any name
6. Click OK

### Option 2: Zoiper (Cross-platform)

1. Download Zoiper from https://www.zoiper.com/
2. Add a new SIP account
3. Configure:
   - **Username**: `101`
   - **Password**: `101pass`
   - **Domain**: Your Asterisk server IP
   - **Port**: `5060`
   - **Transport**: UDP

### Option 3: Hardware Phone (GrandStream, Yealink, etc.)

1. Access your phone's web interface
2. Configure a SIP account:
   - **SIP Server**: Your Asterisk server IP
   - **SIP User ID**: `101`
   - **Auth ID**: `101`
   - **Password**: `101pass`
3. Save and reboot the phone

---

## Make the Call

Once your phone shows **Registered** status:

1. Dial: **100**
2. Press the Call button

### Expected Result

1. ✅ Asterisk **Answers** the call
2. ⏳ Waits **1 second**
3. 🔊 Plays **"Hello World!"** audio
4. 📞 **Hangs up**

You should hear "Hello World!" through your phone speaker!

### Asterisk Console Output

If you run `asterisk -rvvvv`, you'll see:

```
-- Executing [100@from-internal:1] Answer("PJSIP/101-00000000", "") in new stack
-- Executing [100@from-internal:2] Wait("PJSIP/101-00000000", "1") in new stack
-- Executing [100@from-internal:3] Playback("PJSIP/101-00000000", "hello-world") in new stack
-- <PJSIP/101-00000000> Playing 'hello-world.gsm' (language 'en')
-- Executing [100@from-internal:4] Hangup("PJSIP/101-00000000", "") in new stack
```

---

## Cleanup

After testing, you can remove the Hello World setup:

### Via TUI

1. Open `sudo rayanpbx-tui`
2. Select **🚀 Hello World Setup**
3. Select **🗑️ Remove Setup**

This removes:
- The Hello World extension (101) from pjsip.conf
- The Hello World dialplan from extensions.conf
- Reloads Asterisk configuration

---

## Troubleshooting

### Phone Not Registering

**Symptoms**: Phone shows "Registration Failed" or "Timeout"

**Solutions**:

1. **Check network connectivity**:
   ```bash
   ping <phone-ip>
   ```

2. **Check firewall**:
   ```bash
   sudo ufw allow 5060/udp
   sudo ufw allow 10000:20000/udp  # RTP ports
   ```

3. **Enable SIP debugging**:
   ```bash
   asterisk -rx "pjsip set logger on"
   ```

4. **Check credentials match** exactly (username: `101`, password: `101pass`)

### No Audio / Can't Hear Hello World

**Symptoms**: Call connects but no audio

**Solutions**:

1. **Check sound file exists**:
   ```bash
   ls -la /var/lib/asterisk/sounds/en/hello-world.*
   ```

2. **If missing, install Asterisk sounds**:
   ```bash
   # From Asterisk source directory
   cd /usr/src/asterisk-*
   make install-sounds
   ```

3. **Check dialplan loaded**:
   ```bash
   asterisk -rx "dialplan show 100@from-internal"
   ```

### Extension Not Showing

**Symptoms**: `pjsip show endpoints` doesn't show extension 101

**Solutions**:

1. **Check configuration was written**:
   ```bash
   grep -A 15 "Hello World Extension" /etc/asterisk/pjsip.conf
   ```

2. **Reload PJSIP**:
   ```bash
   asterisk -rx "module reload res_pjsip.so"
   ```

3. **Check for syntax errors**:
   ```bash
   asterisk -rx "core reload"
   ```

### Permission Denied Errors

**Solution**: Run the TUI with sudo:
```bash
sudo rayanpbx-tui
```

---

## Manual Configuration (Reference)

If you prefer to configure manually instead of using the automated setup:

### 1. Edit pjsip.conf

```bash
sudo nano /etc/asterisk/pjsip.conf
```

Add:

```ini
[transport-udp]
type=transport
protocol=udp
bind=0.0.0.0

[101]
type=endpoint
context=from-internal
disallow=all
allow=ulaw
auth=101
aors=101

[101]
type=auth
auth_type=userpass
password=101pass
username=101

[101]
type=aor
max_contacts=1
```

### 2. Edit extensions.conf

```bash
sudo nano /etc/asterisk/extensions.conf
```

Add:

```ini
[from-internal]
exten = 100,1,Answer()
same = n,Wait(1)
same = n,Playback(hello-world)
same = n,Hangup()
```

### 3. Reload Asterisk

```bash
asterisk -rx "core restart now"
# Or if already running:
asterisk -rx "module reload res_pjsip.so"
asterisk -rx "dialplan reload"
```

---

## Next Steps

After successfully completing Hello World:

- **Create more extensions** using the Extensions Management menu
- **Set up trunks** to connect to external phone networks
- **Configure voicemail** for extensions
- **Explore the Web UI** for browser-based management

---

<div align="center">

**Built with ❤️ by the RayanPBX Team**

```
╭────────────────────────────────────────╮
│  🎉 Congratulations on your first call!│
╰────────────────────────────────────────╯
```

</div>
