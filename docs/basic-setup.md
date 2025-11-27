# RayanPBX Basic Setup Guide

> 🚀 Get your first phone call working in minutes using the RayanPBX TUI!

This guide explains how to set up a basic working PBX configuration using RayanPBX's TUI (Text User Interface) menu options.

## Table of Contents

1. [Quick Setup (Recommended)](#quick-setup-recommended)
2. [Manual Step-by-Step Setup](#manual-step-by-step-setup)
3. [Configure Your SIP Phone](#configure-your-sip-phone)
4. [Make the Call](#make-the-call)
5. [Troubleshooting](#troubleshooting)
6. [Manual Configuration (Reference)](#manual-configuration-reference)

---

## Quick Setup (Recommended)

The fastest way to get started is using the **🚀 Quick Setup** wizard:

### Step 1: Launch RayanPBX TUI

```bash
sudo rayanpbx-tui
```

### Step 2: Run Quick Setup

1. Select **🚀 Quick Setup** (first menu item)
2. Enter the starting extension number (e.g., `100`)
3. Enter the ending extension number (e.g., `105`)
4. Enter a password for all extensions
5. Press **Enter** to execute the setup

The wizard will automatically:
- ✅ Configure PJSIP transports (UDP and TCP on port 5060)
- ✅ Create extensions in the specified range
- ✅ Set up dialplan for extension-to-extension calls
- ✅ Reload Asterisk configuration

### Step 3: Configure SIP Phones

Use the displayed credentials to configure your SIP phones, then dial between extensions to test calls.

---

## Manual Step-by-Step Setup

If you prefer manual setup, follow these steps:

### Prerequisites

Before starting the setup, ensure:

#### Network Requirements

- ✅ Your **SIP phone** is on the same LAN as the Asterisk server, OR you can install a softphone (MicroSIP recommended)
- ✅ If using a hardware phone, both the phone and Asterisk can reach each other on the same subnet
- ✅ Port **5060/UDP** is open on any firewall between the phone and server

#### Software Requirements

- ✅ **RayanPBX installed** with Asterisk from source (`make samples` was run during installation)
- ✅ **chan_pjsip** channel driver is available
- ✅ **Sound files** installed in `/var/lib/asterisk/sounds/en/`

#### Required Configuration Files

The installer should have created these files in `/etc/asterisk/`:

- `asterisk.conf` - Main Asterisk configuration
- `modules.conf` - Module loading configuration  
- `extensions.conf` - Dialplan configuration
- `pjsip.conf` - SIP configuration

If these files don't exist, run `make samples` from the Asterisk source directory.

---

## Step-by-Step Setup

Follow these steps to configure a basic working PBX setup using the TUI menus.

### Step 1: Launch RayanPBX TUI

```bash
sudo rayanpbx-tui
```

### Step 2: Configure PJSIP Transports

1. Select **⚙️ Asterisk Management**
2. Select **📡 Configure PJSIP Transports**
3. Wait for the transports to be configured (UDP and TCP on port 5060)
4. You should see: "✅ PJSIP transports configured successfully"

This creates the transport configuration in `/etc/asterisk/pjsip.conf`.

### Step 3: Create an Extension

1. Press **ESC** to go back to main menu
2. Select **📱 Extensions Management**
3. Press **a** to add a new extension
4. Fill in the form with your extension details:
   - **Extension Number**: `101` (or your preferred number)
   - **Name**: `Test Extension`
   - **Password**: `your-secure-password`
   - **Codecs**: `ulaw,alaw,g722` (default)
   - **Context**: `from-internal` (default)
   - **Transport**: `transport-udp` (default)
   - **Direct Media**: `no` (default)
   - **Max Contacts**: `1` (default)
   - **Qualify Frequency**: `60` (default)
5. Press **Enter** to save

The extension will be created in the database and automatically synced to the PJSIP configuration.

### Step 4: Reload Asterisk Configuration

1. Press **ESC** to go back to main menu
2. Select **⚙️ Asterisk Management**
3. Select **🔧 Reload PJSIP Configuration**
4. Wait for the reload to complete

### Step 5: Verify the Setup

1. In the Asterisk Management menu:
2. Select **👥 Show PJSIP Endpoints** - verify your extension is listed
3. Select **🚦 Show PJSIP Transports** - verify transport-udp is active
4. Select **📊 Show Service Status** - verify Asterisk is running

---

## What Gets Configured

### pjsip.conf

The setup creates these sections:

```ini
; RayanPBX SIP Transports Configuration
[transport-udp]
type=transport
protocol=udp
bind=0.0.0.0:5060
allow_reload=yes

[transport-tcp]
type=transport
protocol=tcp
bind=0.0.0.0:5060
allow_reload=yes

; Extension endpoint
[101]
type=endpoint
context=from-internal
disallow=all
allow=ulaw
allow=alaw
allow=g722
transport=transport-udp
auth=101
aors=101
direct_media=no

[101]
type=auth
auth_type=userpass
username=101
password=your-secure-password

[101]
type=aor
max_contacts=1
remove_existing=yes
qualify_frequency=60
support_outbound=yes
```

---

## Configure Your SIP Phone

### Option 1: MicroSIP (Windows - Recommended)

1. Download MicroSIP from https://www.microsip.org/
2. Open MicroSIP and click the settings button
3. Click **Add new SIP account**
4. Enter `101` for the account name, click OK
5. Configure:
   - **Domain**: Your Asterisk server IP
   - **Username**: `101` (your extension number)
   - **Password**: The password you set when creating the extension
   - **Caller ID Name**: Leave blank or enter any name
6. Click OK

### Option 2: Zoiper (Cross-platform)

1. Download Zoiper from https://www.zoiper.com/
2. Add a new SIP account
3. Configure:
   - **Username**: Your extension number (e.g., `101`)
   - **Password**: Your extension password
   - **Domain**: Your Asterisk server IP
   - **Port**: `5060`
   - **Transport**: UDP

### Option 3: Hardware Phone (GrandStream, Yealink, etc.)

1. Access your phone's web interface
2. Configure a SIP account:
   - **SIP Server**: Your Asterisk server IP
   - **SIP User ID**: Your extension number
   - **Auth ID**: Your extension number
   - **Password**: Your extension password
3. Save and reboot the phone

---

## Make the Call

Once your phone shows **Registered** status:

1. Dial another extension number (if you've created multiple extensions)
2. Press the Call button

### Verifying Registration

In the TUI:
1. Go to **⚙️ Asterisk Management**
2. Select **👥 Show PJSIP Endpoints**
3. Your extension should show as "Available" or "Not in use"

### Asterisk Console Output

If you run `asterisk -rvvvv`, you'll see registration and call activity:

```
-- Contact 101/sip:101@192.168.1.100:5060 is now Reachable.
-- PJSIP/101 registered with 1 contact
```

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

4. **Verify credentials match** exactly - use the same extension number and password you set in the TUI

### No Audio

**Symptoms**: Call connects but no audio

**Solutions**:

1. **Check RTP port range** is open in firewall:
   ```bash
   sudo ufw allow 10000:20000/udp
   ```

2. **Check direct_media setting** - if phones are behind NAT, set `direct_media=no`

3. **Verify network connectivity** between the phones

### Extension Not Showing

**Symptoms**: `pjsip show endpoints` doesn't show your extension

**Solutions**:

1. **Check configuration was written**:
   ```bash
   grep -A 15 "\[101\]" /etc/asterisk/pjsip.conf
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

If you prefer to configure manually instead of using the TUI:

### 1. Edit pjsip.conf

```bash
sudo nano /etc/asterisk/pjsip.conf
```

Add:

```ini
; Transport configuration
[transport-udp]
type=transport
protocol=udp
bind=0.0.0.0:5060
allow_reload=yes

[transport-tcp]
type=transport
protocol=tcp
bind=0.0.0.0:5060
allow_reload=yes

; Extension configuration
[101]
type=endpoint
context=from-internal
disallow=all
allow=ulaw
allow=alaw
allow=g722
transport=transport-udp
auth=101
aors=101
direct_media=no

[101]
type=auth
auth_type=userpass
password=your-secure-password
username=101

[101]
type=aor
max_contacts=1
remove_existing=yes
qualify_frequency=60
support_outbound=yes
```

### 2. Reload Asterisk

```bash
# Reload PJSIP module
asterisk -rx "module reload res_pjsip.so"
```

---

## Next Steps

After successfully setting up your first extension:

- **Create more extensions** using the Extensions Management menu
- **Set up trunks** to connect to external phone networks
- **Configure voicemail** for extensions
- **Explore the Web UI** for browser-based management

---

<div align="center">

**Built with ❤️ by the RayanPBX Team**

```
╭────────────────────────────────────────╮
│  🎉 Congratulations on your PBX setup! │
╰────────────────────────────────────────╯
```

</div>
