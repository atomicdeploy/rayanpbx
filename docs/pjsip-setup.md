# RayanPBX PJSIP Extension Setup Guide

This guide walks you through setting up and managing PJSIP extensions in RayanPBX, ensuring they properly register with Asterisk and can make calls.

## Table of Contents
1. [Overview](#overview)
2. [Creating Extensions](#creating-extensions)
3. [Verification](#verification)
4. [Configuration Files](#configuration-files)
5. [Real-time Monitoring](#real-time-monitoring)
6. [Presence and BLF Support](#presence-and-blf-support)
7. [Troubleshooting](#troubleshooting)
8. [Example: Complete Setup for 2 Extensions](#example-complete-setup-for-2-extensions)
9. [Additional Resources](#additional-resources)
10. [Support](#support)

## Overview

RayanPBX now provides comprehensive PJSIP management that:
- ✅ Stores extension configuration in the database
- ✅ Generates proper PJSIP configuration files
- ✅ Generates dialplan for extension-to-extension calling
- ✅ Verifies endpoints exist in Asterisk
- ✅ Monitors real-time registration status
- ✅ Tracks call events (ringing, hangup)
- ✅ Supports external media address configuration (NAT)
- ✅ Provides SIP presence and BLF (Busy Lamp Field) support

## Creating Extensions

### Via Web UI

1. Navigate to Extensions page (`http://your-server:3000/extensions`)
2. Click "Add Extension"
3. Fill in the details:
   - **Extension Number**: e.g., `101`
   - **Name**: User's name
   - **Secret**: Strong password (min 8 characters)
   - **Context**: `from-internal` (default)
   - **Transport**: `udp` (default)
   - **Max Contacts**: `1` (default)

4. Click "Save"

RayanPBX will:
- Create the extension in the database
- Generate PJSIP configuration
- Generate dialplan configuration
- Reload Asterisk
- Verify the endpoint was created

### Via API

```bash
curl -X POST http://localhost:8000/api/extensions \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -d '{
    "extension_number": "101",
    "name": "John Doe",
    "secret": "SecurePass123",
    "enabled": true,
    "context": "from-internal",
    "transport": "udp",
    "codecs": ["ulaw", "alaw", "g722"],
    "max_contacts": 1
  }'
```

### Via Artisan Command

```bash
cd /opt/rayanpbx/backend
php artisan rayanpbx:extension create 101 --name="John Doe" --secret="SecurePass123"
```

## Verification

### Check if Extension Exists in Asterisk

#### Web UI
Navigate to Extensions page - each extension shows:
- 🟢 **Registered** - Extension is online
- ⚫ **Offline** - Extension is not registered
- IP address and port (when registered)

#### API Endpoint
```bash
# Verify specific extension
curl http://localhost:8000/api/extensions/1/verify \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"

# Get all Asterisk endpoints
curl http://localhost:8000/api/extensions/asterisk/endpoints \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"
```

#### Asterisk CLI
```bash
asterisk -rvvv
> pjsip show endpoints
> pjsip show endpoint 101
```

Expected output:
```
Endpoint:  <Endpoint/101>  Unavailable   0 of 1
    Aor:  101                   0
  Contact:  101/sip:101@192.168.1.100:5060  Avail
   Transport:  transport-udp             udp      0      0  0.0.0.0:5060
```

### Check Dialplan

```bash
asterisk -rvvv
> dialplan show from-internal
```

You should see:
```
[ Context 'from-internal' created by 'pbx_config' ]
  '101' =>          1. NoOp(Call to extension 1001)
                    2. Dial(PJSIP/101,30)
                    3. Hangup()
  
  '_1XX' =>         1. NoOp(Extension to extension call: ${EXTEN})
                    2. Dial(PJSIP/${EXTEN},30)
                    3. Hangup()
```

## Configuration Files

### PJSIP Configuration (`/etc/asterisk/pjsip.conf`)

RayanPBX uses proper INI section manipulation for configuration management:

```ini
[transport-udp]
type=transport
protocol=udp
bind=0.0.0.0:5060

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
password=SecurePass123

[101]
type=aor
max_contacts=1
remove_existing=yes
qualify_frequency=60
```

**Section Naming Convention**: For user extensions, all related sections (endpoint, auth, aor) use the **same name** (e.g., `[101]`). This is the Asterisk-recommended approach because:
- Registration matching requires the aor name to match the SIP URI user part
- Sections are distinguished by their `type=` property, not their name
- Alternative naming like `[101-auth]` is only appropriate for SIP trunks where IP-based matching is used

Sections are identified by their `[name]` and `type=` property. RayanPBX will add, update, or remove sections as needed.

### Dialplan Configuration (`/etc/asterisk/extensions.conf`)

```ini
[from-internal]
exten => 101,1,NoOp(Call to extension 101)
 same => n,Dial(PJSIP/101,30)
 same => n,Hangup()

exten => 102,1,NoOp(Call to extension 102)
 same => n,Dial(PJSIP/102,30)
 same => n,Hangup()

; Pattern match for all extensions
exten => _1XX,1,NoOp(Extension to extension call: ${EXTEN})
 same => n,Dial(PJSIP/${EXTEN},30)
 same => n,Hangup()

[outbound-routes]
exten => _9X.,1,NoOp(Outbound call via trunk)
 same => n,Dial(PJSIP/${EXTEN:1}@trunk,60)
 same => n,Hangup()
```

### External Media Address (NAT Configuration)

If your server is behind NAT, configure external media address:

#### Via API
```bash
curl -X POST http://localhost:8000/api/pjsip/config/external-media \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "external_media_address": "your.public.ip.or.domain",
    "external_signaling_address": "your.public.ip.or.domain",
    "local_net": "192.168.1.0/24"
  }'
```

This adds to `pjsip.conf`:
```ini
[global]
external_media_address=your.public.ip.or.domain
external_signaling_address=your.public.ip.or.domain
local_net=192.168.1.0/24
```

## Real-time Monitoring

### AMI Event Monitor

Start the event monitor to track registrations and calls:

```bash
cd /opt/rayanpbx/backend
php artisan rayanpbx:monitor-events
```

Output:
```
Starting AMI Event Monitor...
Monitoring for PJSIP registrations, calls, and other events
Press Ctrl+C to stop

✅ Extension 101 registered
🔔 Extension 102 ringing from 101
📞 Call ended: PJSIP/102-00000001 - Normal Clearing
```

### API Endpoints for Events

```bash
# Get recent events
curl http://localhost:8000/api/events \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"

# Get registration events only
curl http://localhost:8000/api/events/registrations \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"

# Get call events
curl http://localhost:8000/api/events/calls \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"

# Get specific extension status
curl http://localhost:8000/api/events/extension/101 \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"
```

## Presence and BLF Support

RayanPBX automatically configures extensions for SIP presence and BLF (Busy Lamp Field) functionality.

### What is Presence/BLF?

- **Presence**: Shows the availability status of extensions (available, busy, ringing, etc.)
- **BLF (Busy Lamp Field)**: Visual indicators on IP phones showing other extensions' status

### Automatic Configuration

When you create an extension, RayanPBX automatically generates:

1. **PJSIP Endpoint Settings**:
   ```ini
   subscribe_context=from-internal  ; Enables presence subscriptions
   device_state_busy_at=1           ; Reports busy when 1+ calls active
   ```

2. **AOR Settings**:
   ```ini
   support_outbound=yes  ; Enables outbound presence PUBLISH
   ```

3. **Dialplan Hints**:
   ```ini
   exten => 101,hint,PJSIP/101  ; Maps extension to PJSIP endpoint
   ```

### Verifying Presence Support

Check if hints are registered:
```bash
asterisk -rx "core show hints"
```

Expected output:
```
101@from-internal         : PJSIP/101        State:Unavailable   Watchers  0
102@from-internal         : PJSIP/102        State:Idle          Watchers  1
```

Check subscriptions:
```bash
asterisk -rx "pjsip show subscriptions inbound"
```

### Configuring BLF on IP Phones

Example for GrandStream phones:
1. Go to **Accounts** → **Account X** → **BLF Keys**
2. Set **Mode**: Speed Dial + BLF
3. Set **Value**: Extension number (e.g., `102`)
4. Set **Account**: Your SIP account

### Troubleshooting Presence (489 Bad Event)

If you see "489 Bad Event" responses in SIP logs:

1. **Check required Asterisk modules**:
   ```bash
   asterisk -rx "module show like pjsip_publish"
   ```
   
   You should see `res_pjsip_publish_asterisk` loaded.

2. **Load missing modules**:
   ```bash
   asterisk -rx "module load res_pjsip_publish_asterisk"
   ```

3. **Verify hints are configured**:
   ```bash
   asterisk -rx "dialplan show from-internal" | grep hint
   ```

4. **Check endpoint subscribe_context**:
   ```bash
   asterisk -rx "pjsip show endpoint 101" | grep subscribe
   ```

### Presence Event Limitations

**Note**: The SIP "presence" event type (RFC 3856) requires specific Asterisk modules:
- `res_pjsip_publish_asterisk` - Handles inbound PUBLISH for presence
- `res_pjsip_exten_state` - Provides extension state via PJSIP

If your Asterisk build doesn't include these modules, presence publishing may not work. BLF via SUBSCRIBE/NOTIFY will still function for monitoring extension states.

## Troubleshooting

### Extension Not Showing in `pjsip show endpoints`

**Possible causes:**

1. **Configuration not written**
   ```bash
   # Check if config file was updated
   grep "Extension 101" /etc/asterisk/pjsip.conf
   ```

2. **Asterisk not reloaded**
   ```bash
   asterisk -rx "pjsip reload"
   asterisk -rx "dialplan reload"
   ```

3. **Syntax error in configuration**
   ```bash
   asterisk -rx "pjsip show endpoints"
   # Check Asterisk logs
   tail -f /var/log/asterisk/messages
   ```

4. **Transport not configured**
   ```bash
   asterisk -rx "pjsip show transports"
   ```
   
   If no transport, check pjsip.conf has:
   ```ini
   [transport-udp]
   type=transport
   protocol=udp
   bind=0.0.0.0:5060
   ```

### Extension Not Registering

**From MicroSIP or other SIP client:**

1. **Check credentials**
   - Username: `101`
   - Password: The secret you set
   - Domain: Your server IP
   - Port: `5060`

2. **Check network connectivity**
   ```bash
   # From client machine
   telnet your-server-ip 5060
   ```

3. **Check Asterisk is listening**
   ```bash
   netstat -tunlp | grep 5060
   ```

4. **Check firewall**
   ```bash
   ufw status
   # Allow SIP if needed
   ufw allow 5060/udp
   ufw allow 10000:20000/udp  # RTP
   ```

5. **Monitor registration attempts**
   ```bash
   asterisk -rvvvv
   > pjsip set logger on
   ```

### Can't Call Between Extensions

1. **Check dialplan**
   ```bash
   asterisk -rx "dialplan show from-internal"
   ```

2. **Check context in pjsip.conf**
   Each endpoint should have `context=from-internal`

3. **Test call from CLI**
   ```bash
   asterisk -rvvv
   > channel originate PJSIP/101 extension 102@from-internal
   ```

4. **Check for errors**
   ```bash
   tail -f /var/log/asterisk/messages | grep -i error
   ```

### Reload Not Working

If AMI reload fails, use CLI directly:

```bash
asterisk -rx "pjsip reload"
asterisk -rx "dialplan reload"
# Or full reload
asterisk -rx "core reload"
```

### Permission Issues

If getting permission errors when writing configs:

```bash
# Check ownership
ls -la /etc/asterisk/pjsip.conf

# Fix if needed
chown asterisk:asterisk /etc/asterisk/pjsip.conf
chmod 644 /etc/asterisk/pjsip.conf
```

## Example: Complete Setup for 2 Extensions

### 1. Create Extensions

```bash
# Extension 101
curl -X POST http://localhost:8000/api/extensions \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "extension_number": "101",
    "name": "Alice",
    "secret": "Alice123Pass",
    "enabled": true,
    "context": "from-internal"
  }'

# Extension 102
curl -X POST http://localhost:8000/api/extensions \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "extension_number": "102",
    "name": "Bob",
    "secret": "Bob456Pass",
    "enabled": true,
    "context": "from-internal"
  }'
```

### 2. Verify in Asterisk

```bash
asterisk -rx "pjsip show endpoints"
```

Should show:
```
Endpoint:  <Endpoint/101>  Unavailable   0 of 1
Endpoint:  <Endpoint/102>  Unavailable   0 of 1
```

### 3. Configure MicroSIP

**For Extension 101:**
- Account name: Alice (101)
- SIP Server: your-server-ip
- SIP Proxy: your-server-ip:5060
- Username: 101
- Password: Alice123Pass
- Domain: your-server-ip

**For Extension 102:**
- Same as above but with 102 credentials

### 4. Verify Registration

Wait a few seconds, then:
```bash
asterisk -rx "pjsip show endpoints"
```

Should now show:
```
Endpoint:  <Endpoint/101>  Available   1 of 1
Endpoint:  <Endpoint/102>  Available   1 of 1
```

MicroSIP should show green "Online" status.

### 5. Test Call

From 101, dial: `102`

Should see in RayanPBX event monitor:
```
🔔 Extension 102 ringing from 101
📞 Call ended: PJSIP/102-00000001 - Normal Clearing
```

## Additional Resources

- [Asterisk PJSIP Configuration](https://wiki.asterisk.org/wiki/display/AST/Configuring+res_pjsip)
- [MicroSIP Setup Guide](https://www.microsip.org/)
- [RayanPBX API Documentation](../README.md)

## Support

If you encounter issues:

1. Check the logs: `tail -f /var/log/asterisk/messages`
2. Enable verbose mode: `asterisk -rvvvv`
3. Check RayanPBX logs: `journalctl -u rayanpbx-api -f`
4. Open an issue: https://github.com/atomicdeploy/rayanpbx/issues
