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
   - **Extension Number**: e.g., `1001`
   - **Name**: User's name
   - **Secret**: Strong password (min 8 characters)
   - **Context**: `internal` (default)
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
    "extension_number": "1001",
    "name": "John Doe",
    "secret": "SecurePass123",
    "enabled": true,
    "context": "internal",
    "transport": "udp",
    "codecs": ["ulaw", "alaw", "g722"],
    "max_contacts": 1
  }'
```

### Via Artisan Command

```bash
cd /opt/rayanpbx/backend
php artisan rayanpbx:extension create 1001 --name="John Doe" --secret="SecurePass123"
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
> pjsip show endpoint 1001
```

Expected output:
```
Endpoint:  <Endpoint/1001>  Unavailable   0 of 1
    Aor:  1001                   0
  Contact:  1001/sip:1001@192.168.1.100:5060  Avail
   Transport:  transport-udp             udp      0      0  0.0.0.0:5060
```

### Check Dialplan

```bash
asterisk -rvvv
> dialplan show internal
```

You should see:
```
[ Context 'internal' created by 'pbx_config' ]
  '1001' =>         1. NoOp(Call to extension 1001)
                    2. Dial(PJSIP/1001,30)
                    3. Hangup()
  
  '_1XXX' =>        1. NoOp(Extension to extension call: ${EXTEN})
                    2. Dial(PJSIP/${EXTEN},30)
                    3. Hangup()
```

## Configuration Files

### PJSIP Configuration (`/etc/asterisk/pjsip.conf`)

RayanPBX manages sections with markers:

```ini
; BEGIN MANAGED - RayanPBX Transport
[transport-udp]
type=transport
protocol=udp
bind=0.0.0.0:5060
; END MANAGED - RayanPBX Transport

; BEGIN MANAGED - Extension 1001
[1001]
type=endpoint
context=internal
disallow=all
allow=ulaw
allow=alaw
allow=g722
transport=udp
auth=1001
aors=1001
direct_media=no

[1001]
type=auth
auth_type=userpass
username=1001
password=SecurePass123

[1001]
type=aor
max_contacts=1
remove_existing=yes
qualify_frequency=60
; END MANAGED - Extension 1001
```

### Dialplan Configuration (`/etc/asterisk/extensions.conf`)

```ini
; BEGIN MANAGED - RayanPBX Internal Extensions
[internal]
exten => 1001,1,NoOp(Call to extension 1001)
 same => n,Dial(PJSIP/1001,30)
 same => n,Hangup()

exten => 1002,1,NoOp(Call to extension 1002)
 same => n,Dial(PJSIP/1002,30)
 same => n,Hangup()

; Pattern match for all extensions
exten => _1XXX,1,NoOp(Extension to extension call: ${EXTEN})
 same => n,Dial(PJSIP/${EXTEN},30)
 same => n,Hangup()
; END MANAGED - RayanPBX Internal Extensions
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

✅ Extension 1001 registered
🔔 Extension 1002 ringing from 1001
📞 Call ended: PJSIP/1002-00000001 - Normal Clearing
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
curl http://localhost:8000/api/events/extension/1001 \
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
   exten => 1001,hint,PJSIP/1001  ; Maps extension to PJSIP endpoint
   ```

### Verifying Presence Support

Check if hints are registered:
```bash
asterisk -rx "core show hints"
```

Expected output:
```
1001@internal         : PJSIP/1001        State:Unavailable   Watchers  0
1002@internal         : PJSIP/1002        State:Idle          Watchers  1
```

Check subscriptions:
```bash
asterisk -rx "pjsip show subscriptions inbound"
```

### Configuring BLF on IP Phones

Example for GrandStream phones:
1. Go to **Accounts** → **Account X** → **BLF Keys**
2. Set **Mode**: Speed Dial + BLF
3. Set **Value**: Extension number (e.g., `1002`)
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
   asterisk -rx "pjsip show endpoint 1001" | grep subscribe
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
   grep "Extension 1001" /etc/asterisk/pjsip.conf
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
   - Username: `1001`
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
   asterisk -rx "dialplan show internal"
   ```

2. **Check context in pjsip.conf**
   Each endpoint should have `context=internal`

3. **Test call from CLI**
   ```bash
   asterisk -rvvv
   > channel originate PJSIP/1001 extension 1002@internal
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
# Extension 1001
curl -X POST http://localhost:8000/api/extensions \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "extension_number": "1001",
    "name": "Alice",
    "secret": "Alice123Pass",
    "enabled": true,
    "context": "internal"
  }'

# Extension 1002
curl -X POST http://localhost:8000/api/extensions \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "extension_number": "1002",
    "name": "Bob",
    "secret": "Bob456Pass",
    "enabled": true,
    "context": "internal"
  }'
```

### 2. Verify in Asterisk

```bash
asterisk -rx "pjsip show endpoints"
```

Should show:
```
Endpoint:  <Endpoint/1001>  Unavailable   0 of 1
Endpoint:  <Endpoint/1002>  Unavailable   0 of 1
```

### 3. Configure MicroSIP

**For Extension 1001:**
- Account name: Alice (1001)
- SIP Server: your-server-ip
- SIP Proxy: your-server-ip:5060
- Username: 1001
- Password: Alice123Pass
- Domain: your-server-ip

**For Extension 1002:**
- Same as above but with 1002 credentials

### 4. Verify Registration

Wait a few seconds, then:
```bash
asterisk -rx "pjsip show endpoints"
```

Should now show:
```
Endpoint:  <Endpoint/1001>  Available   1 of 1
Endpoint:  <Endpoint/1002>  Available   1 of 1
```

MicroSIP should show green "Online" status.

### 5. Test Call

From 1001, dial: `1002`

Should see in RayanPBX event monitor:
```
🔔 Extension 1002 ringing from 1001
📞 Call ended: PJSIP/1002-00000001 - Normal Clearing
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
