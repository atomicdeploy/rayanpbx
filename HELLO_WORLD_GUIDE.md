# RayanPBX Hello World Guide

> 🚀 Get your first phone call working in minutes with RayanPBX!

This guide walks you through making your first "Hello World" phone call using RayanPBX, following the same concepts as [Asterisk's Hello World](https://docs.asterisk.org/Getting-Started/Hello-World/) but with our elegant TUI, Web UI, and CLI interfaces.

## Table of Contents

1. [Overview](#overview)
2. [Prerequisites](#prerequisites)
3. [Quick Start Steps](#quick-start-steps)
4. [Method 1: Web UI (Recommended)](#method-1-web-ui-recommended)
5. [Method 2: TUI (Terminal UI)](#method-2-tui-terminal-ui)
6. [Method 3: CLI (Command Line)](#method-3-cli-command-line)
7. [Configure Your SIP Phone](#configure-your-sip-phone)
8. [Make the Call](#make-the-call)
9. [Troubleshooting](#troubleshooting)

---

## Overview

In this guide, you will:

1. **Create a SIP extension** (equivalent to configuring PJSIP endpoint in Asterisk)
2. **Set up a Hello World dialplan** (automatically handled by RayanPBX)
3. **Register a SIP phone** (using software like Zoiper)
4. **Dial extension 100** to hear the "hello-world" audio greeting

RayanPBX automates the configuration of `pjsip.conf` and `extensions.conf` - you just use our intuitive interfaces!

---

## Prerequisites

Before starting, ensure you have:

- ✅ **RayanPBX installed** ([Installation Guide](README.md#-quick-start))
- ✅ **Asterisk 22** running (included with RayanPBX installation)
- ✅ **A SIP phone or softphone** (we recommend [Zoiper](https://www.zoiper.com/))
- ✅ **Network access** to your RayanPBX server

### Verify Asterisk is Running

```bash
# Check Asterisk service status
systemctl status asterisk

# Or use RayanPBX CLI
php artisan rayanpbx:status
```

---

## Quick Start Steps

Here's the quick overview - detailed instructions follow:

| Step | Action | Interface |
|------|--------|-----------|
| 1 | Create extension `6001` | Web UI / TUI / CLI |
| 2 | Create Hello World dialplan | Automatic |
| 3 | Configure SIP phone with credentials | Your phone app |
| 4 | Dial `100` | Your phone app |
| 5 | Hear "Hello World!" | 🎉 |

---

## Method 1: Web UI (Recommended)

The easiest way to set up your Hello World is through the Web UI.

### Step 1: Access the Web Interface

1. Open your browser and navigate to:
   ```
   http://your-server-ip:3000
   ```

2. Log in with your Linux username and password (PAM authentication)

### Step 2: Create Your First Extension

1. Click on **Extensions** in the navigation menu
2. Click the **Add Extension** button
3. Fill in the form:

   | Field | Value | Description |
   |-------|-------|-------------|
   | **Extension Number** | `6001` | Your SIP username |
   | **Name** | `Hello World Test` | Display name |
   | **Password** | `unsecurepassword` | SIP authentication password |
   | **Enabled** | ✓ Checked | Enable the extension |

4. (Optional) Click **Show Advanced** to configure:
   - **Context**: `from-internal` (default, recommended)
   - **Transport**: `transport-udp` (default)
   - **Codecs**: `ulaw, alaw, g722` (default - includes HD audio)

5. Click **Save**

### Step 3: Create Hello World Dialplan

RayanPBX needs a special dialplan entry for the Hello World test. We'll add it via the console.

1. Click on **Console** in the navigation menu
2. Run this command to add the Hello World dialplan:

   ```
   dialplan show from-internal
   ```

   This shows the current dialplan. RayanPBX automatically creates extension-to-extension dialing.

3. For the classic "dial 100 to hear hello-world" experience, we'll use the Asterisk CLI:

   ```bash
   # Add hello-world dialplan (run in terminal)
   sudo tee -a /etc/asterisk/extensions.conf << 'EOF'

   ; Hello World Demo
   [from-internal]
   exten => 100,1,Answer()
   same => n,Wait(1)
   same => n,Playback(hello-world)
   same => n,Hangup()
   EOF

   # Reload dialplan
   asterisk -rx "dialplan reload"
   ```

### Step 4: Verify Extension Created

1. Go back to **Extensions** page
2. Your extension `6001` should appear with status:
   - 🔴 **Offline** - Not yet registered (expected)
   - ✅ **Enabled** - Ready for registration

---

## Method 2: TUI (Terminal UI)

Use the beautiful Terminal UI for keyboard-based management.

### Step 1: Launch the TUI

```bash
rayanpbx-tui
```

### Step 2: Create Extension

1. Use **↑/↓** keys to navigate to **📱 Extensions Management**
2. Press **Enter**
3. Press **a** to add a new extension
4. Fill in the fields (use **↑/↓** to navigate, **Enter** to confirm):

   ```
   Extension Number: 6001
   Name: Hello World Test
   Password: unsecurepassword
   Codecs: ulaw,alaw,g722
   Context: from-internal
   Transport: transport-udp
   Direct Media: no
   Max Contacts: 1
   Qualify Freq: 60
   ```

5. Press **Enter** on the last field to create

### Step 3: Add Hello World Dialplan

1. Press **ESC** to return to main menu
2. Navigate to **⚙️ Asterisk Management**
3. The Hello World dialplan needs to be added manually:

   ```bash
   # Open another terminal and run:
   sudo tee -a /etc/asterisk/extensions.conf << 'EOF'

   ; Hello World Demo
   [from-internal]
   exten => 100,1,Answer()
   same => n,Wait(1)
   same => n,Playback(hello-world)
   same => n,Hangup()
   EOF
   ```

4. In TUI, select **📞 Reload Dialplan** and press **Enter**

### Step 4: Verify in TUI

1. Go to **📱 Extensions Management**
2. Select your extension `6001`
3. Press **i** for Info - shows:
   - Extension details
   - Real-time registration status
   - SIP client configuration

---

## Method 3: CLI (Command Line)

For scripting and automation, use the CLI.

### Step 1: Create Extension via API

```bash
# Get JWT token first
TOKEN=$(curl -s -X POST http://localhost:8000/api/login \
  -H "Content-Type: application/json" \
  -d '{"username": "your-linux-user", "password": "your-password"}' \
  | jq -r '.token')

# Create the extension
curl -X POST http://localhost:8000/api/extensions \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{
    "extension_number": "6001",
    "name": "Hello World Test",
    "secret": "unsecurepassword",
    "enabled": true,
    "context": "from-internal",
    "transport": "transport-udp",
    "codecs": ["ulaw", "alaw", "g722"],
    "max_contacts": 1,
    "direct_media": "no",
    "qualify_frequency": 60
  }'
```

### Step 2: Create Extension via Artisan

```bash
cd /opt/rayanpbx/backend
php artisan rayanpbx:extension create 6001 --name="Hello World Test" --secret="unsecurepassword"
```

### Step 3: Add Hello World Dialplan

```bash
# Add the Hello World dialplan entry
cat << 'EOF' | sudo tee -a /etc/asterisk/extensions.conf

; RayanPBX Hello World Demo
[from-internal]
exten => 100,1,Answer()
same => n,Wait(1)
same => n,Playback(hello-world)
same => n,Hangup()
EOF

# Reload the dialplan
asterisk -rx "dialplan reload"
```

### Step 4: Verify Extension

```bash
# Check extension exists in Asterisk
asterisk -rx "pjsip show endpoints"

# Or via API
curl http://localhost:8000/api/extensions \
  -H "Authorization: Bearer $TOKEN"
```

---

## Configure Your SIP Phone

Now configure your SIP phone (Zoiper, MicroSIP, Linphone, or hardware phone).

### Recommended Softphones

| App | Platform | Download |
|-----|----------|----------|
| **Zoiper** | Windows, macOS, Linux, iOS, Android | [zoiper.com](https://www.zoiper.com/) |
| **MicroSIP** | Windows | [microsip.org](https://www.microsip.org/) |
| **Linphone** | All platforms | [linphone.org](https://www.linphone.org/) |

### Configuration Settings

Use these settings in your SIP phone:

| Setting | Value |
|---------|-------|
| **Account Name** | Hello World (6001) |
| **Username** | `6001` |
| **Password** | `unsecurepassword` |
| **Domain/Server** | Your RayanPBX server IP |
| **Port** | `5060` |
| **Transport** | UDP |

### Zoiper Configuration Example

1. Open Zoiper, click **Settings** (wrench icon)
2. Click **Add new SIP account**
3. Enter `6001` for account name, click **OK**
4. Configure:
   - **Domain**: `your-server-ip` (e.g., `192.168.1.100`)
   - **Username**: `6001`
   - **Password**: `unsecurepassword`
5. Click **OK** and then **Register**

### Verify Registration

Your phone should show **Registered** or **Online** status.

In RayanPBX, verify registration:

```bash
# Via Asterisk CLI
asterisk -rx "pjsip show endpoints"

# Expected output:
# Endpoint: <Endpoint/6001>  Available  1 of 1
```

Or in the Web UI:
- Go to **Extensions** page
- Extension `6001` should show 🟢 **Registered**

---

## Make the Call

🎉 **This is the moment of truth!**

### Dial Extension 100

1. On your registered SIP phone, dial: **100**
2. Press the **Call** button

### What Happens

1. ✅ Asterisk **Answers** the call
2. ⏳ Waits **1 second**
3. 🔊 Plays the **hello-world** sound file
4. 📞 **Hangs up**

You should hear **"Hello World!"** played through your phone!

### Asterisk CLI Output

If you watch the Asterisk console (`asterisk -rvvvv`), you'll see:

```
-- Executing [100@from-internal:1] Answer("PJSIP/6001-00000000", "") in new stack
-- Executing [100@from-internal:2] Wait("PJSIP/6001-00000000", "1") in new stack
-- Executing [100@from-internal:3] Playback("PJSIP/6001-00000000", "hello-world") in new stack
-- <PJSIP/6001-00000000> Playing 'hello-world.gsm' (language 'en')
-- Executing [100@from-internal:4] Hangup("PJSIP/6001-00000000", "") in new stack
```

---

## Troubleshooting

### Extension Not Registering

**Symptoms**: Phone shows "Registration Failed" or "Unauthorized"

**Solutions**:
1. **Check credentials**: Verify username/password match exactly
2. **Check network**: Ensure phone can reach server on port 5060
3. **Check firewall**:
   ```bash
   sudo ufw allow 5060/udp
   sudo ufw allow 10000:20000/udp  # RTP
   ```
4. **Enable SIP debugging**:
   ```bash
   asterisk -rx "pjsip set logger on"
   ```

### No Audio / Can't Hear Hello World

**Symptoms**: Call connects but no audio

**Solutions**:
1. **Check sound file exists**:
   ```bash
   ls -la /var/lib/asterisk/sounds/en/hello-world.*
   ```
2. **Verify dialplan**:
   ```bash
   asterisk -rx "dialplan show 100@from-internal"
   ```
3. **Check context**: Ensure extension uses `from-internal` context

### Extension Not Showing in Asterisk

**Symptoms**: `pjsip show endpoints` doesn't show your extension

**Solutions**:
1. **Reload PJSIP**:
   ```bash
   asterisk -rx "pjsip reload"
   ```
2. **Check config file**:
   ```bash
   grep "6001" /etc/asterisk/pjsip.conf
   ```
3. **Verify via Web UI**: Check extension is **Enabled**

### Getting Help

- **TUI Help**: Press `h` in extension info screen
- **Web UI Diagnostics**: Click on extension status for troubleshooting
- **API Diagnostics**: `GET /api/extensions/{id}/diagnostics`
- **Asterisk Console**: Connect with `asterisk -rvvvv`

---

## What's Next?

Now that you've made your first call, explore more features:

| Feature | Documentation |
|---------|---------------|
| **Extension-to-Extension Calling** | Extensions can dial each other directly |
| **Trunks** | Connect to external phone networks |
| **Voicemail** | Configure voicemail for extensions |
| **Call Routing** | Set up IVR and call flows |
| **Real-time Monitoring** | WebSocket events for live status |

### Further Reading

- [PJSIP Setup Guide](PJSIP_SETUP_GUIDE.md) - Detailed PJSIP configuration
- [SIP Testing Guide](SIP_TESTING_GUIDE.md) - Test your SIP setup
- [API Quick Reference](API_QUICK_REFERENCE.md) - REST API documentation
- [Artisan Commands](ARTISAN_COMMANDS.md) - CLI management commands

---

## Summary

Congratulations! 🎉 You've successfully:

1. ✅ Created a SIP extension using RayanPBX
2. ✅ Set up the Hello World dialplan
3. ✅ Registered a SIP phone
4. ✅ Made your first call to hear "Hello World!"

RayanPBX makes Asterisk configuration easy with:
- 🌐 **Web UI** - Point-and-click management
- 🖥️ **TUI** - Beautiful terminal interface
- ⚡ **CLI/API** - Automation and scripting
- 📊 **Real-time status** - Live registration monitoring

---

<div align="center">

**Built with ❤️ by the RayanPBX Team**

```
╭────────────────────────────────────────╮
│  🚀 Your first call is just the start! │
╰────────────────────────────────────────╯
```

</div>
