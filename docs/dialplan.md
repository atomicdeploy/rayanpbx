# Dialplan Configuration

RayanPBX provides comprehensive dialplan management through both the Web UI and TUI (Terminal User Interface). The dialplan controls how calls are routed within your PBX system.

## Overview

The dialplan in Asterisk determines what happens when an extension is dialed. RayanPBX supports two styles of dialplan configuration:

### 1. Generalized Dialplan (Pattern Matching)
Uses patterns like `_1XX` to match ranges of extensions:
```ini
[from-internal]
exten => _1XX,1,NoOp(Extension to extension call: ${EXTEN})
 same => n,Dial(PJSIP/${EXTEN},30)
 same => n,Hangup()
```

- `_1XX` matches any 3-digit number starting with 1 (100-199)
- `${EXTEN}` is automatically replaced with the dialed extension
- **Recommended for most installations**

### 2. Explicit Dialplan (Per Extension)
Defines rules for each specific extension:
```ini
[from-internal]
exten => 101,1,Dial(PJSIP/101)
exten => 102,1,Dial(PJSIP/102)
```

## Web UI Configuration

Navigate to **Dialplan** from the main dashboard to access the dialplan management interface.

### Creating Default Rules

1. Click **Create Defaults** to generate the default internal pattern rule
2. This creates `_1XX` pattern for extensions 100-199
3. Click **Apply to Asterisk** to activate the configuration

### Creating Custom Rules

1. Click **Add Rule**
2. Fill in the required fields:
   - **Name**: Descriptive name for the rule
   - **Context**: Usually `from-internal` for internal calls
   - **Pattern**: The dial pattern (e.g., `_1XX`, `101`, `_9X.`)
   - **Application**: The Asterisk application to execute (usually `Dial`)
   - **App Data**: Parameters for the application (e.g., `PJSIP/${EXTEN},30`)
3. Click **Save Rule**
4. Click **Apply to Asterisk** to activate

### Enabling/Disabling Rules

Toggle the switch next to any rule to enable or disable it. Disabled rules are commented out in the configuration file.

## TUI Configuration

Launch the TUI with:
```bash
rayanpbx-tui
```

Navigate to **Dialplan Management** from the main menu.

### Available Options

1. **View Current Dialplan**: Shows the active dialplan from Asterisk
2. **Generate from Extensions**: Creates dialplan rules based on configured extensions
3. **Create Default Pattern (_1XX)**: Creates the default generalized pattern
4. **Apply to Asterisk**: Writes the configuration and reloads Asterisk
5. **Reload Dialplan**: Reloads the dialplan without rewriting the configuration
6. **Pattern Help**: Shows the pattern reference guide

## Pattern Reference

### Pattern Characters

| Character | Meaning |
|-----------|---------|
| `X` | Matches any digit 0-9 |
| `Z` | Matches any digit 1-9 |
| `N` | Matches any digit 2-9 |
| `[1-5]` | Matches any digit in the range 1-5 |
| `.` | Wildcard: matches one or more characters |
| `!` | Wildcard: matches zero or more characters |
| `_` | Prefix indicating a pattern (required) |

### Common Patterns

| Pattern | Description |
|---------|-------------|
| `100` | Matches exactly 100 |
| `_1XX` | Matches 100-199 (3-digit extensions starting with 1) |
| `_NXX` | Matches 200-999 |
| `_9X.` | Matches 9 followed by any number of digits (outbound) |
| `_0X.` | Matches 0 followed by any number of digits |
| `s` | Start extension (for incoming calls without DID) |

### Variables

| Variable | Description |
|----------|-------------|
| `${EXTEN}` | The dialed extension number |
| `${EXTEN:1}` | Dialed number with first digit stripped |
| `${CALLERID(num)}` | Caller ID number |
| `${CALLERID(name)}` | Caller ID name |

## Dialplan Contexts

### from-internal
Used for calls originating from internal extensions (SIP phones connected to the PBX).

```ini
[from-internal]
; Internal extension calls (100-199)
exten => _1XX,1,NoOp(Extension call: ${EXTEN})
 same => n,Dial(PJSIP/${EXTEN},30)
 same => n,Hangup()
```

### Outbound Routes
For calls going to external numbers via a SIP trunk:

```ini
[from-internal]
; Outbound calls via trunk (dial 9 + number)
exten => _9X.,1,NoOp(Outbound call: ${EXTEN})
 same => n,Dial(PJSIP/${EXTEN:1}@mytrunk,60)
 same => n,Hangup()
```

### Inbound Routes
For calls coming in from SIP trunks:

```ini
[from-trunk]
; Send all incoming calls to extension 101
exten => s,1,NoOp(Incoming call)
 same => n,Dial(PJSIP/101,30)
 same => n,VoiceMail(101@default,u)
 same => n,Hangup()
```

## API Reference

The dialplan management API is available at `/api/dialplan`.

### Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/dialplan` | List all dialplan rules |
| POST | `/api/dialplan` | Create a new rule |
| GET | `/api/dialplan/{id}` | Get a specific rule |
| PUT | `/api/dialplan/{id}` | Update a rule |
| DELETE | `/api/dialplan/{id}` | Delete a rule |
| POST | `/api/dialplan/{id}/toggle` | Enable/disable a rule |
| GET | `/api/dialplan/contexts` | List available contexts |
| GET | `/api/dialplan/applications` | List available applications |
| GET | `/api/dialplan/patterns` | Get pattern reference |
| GET | `/api/dialplan/preview` | Preview generated dialplan |
| POST | `/api/dialplan/apply` | Apply dialplan to Asterisk |
| POST | `/api/dialplan/defaults` | Create default rules |
| GET | `/api/dialplan/live` | Get current Asterisk dialplan |

### Example: Create a Rule

```bash
curl -X POST http://localhost:8000/api/dialplan \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Internal Extensions",
    "context": "from-internal",
    "pattern": "_1XX",
    "app": "Dial",
    "app_data": "PJSIP/${EXTEN},30",
    "rule_type": "pattern",
    "enabled": true
  }'
```

## Troubleshooting

### Calls Not Connecting

1. Check that dialplan rules exist:
   ```bash
   asterisk -rx "dialplan show from-internal"
   ```

2. Verify pattern matches your extensions:
   - RayanPBX uses 3-digit extensions (100-199): Use `_1XX`

3. Check Asterisk logs:
   ```bash
   tail -f /var/log/asterisk/full
   ```

### Reload After Changes

After modifying the dialplan through the API, it's automatically reloaded. To manually reload:

```bash
asterisk -rx "dialplan reload"
```

### View Current Configuration

```bash
cat /etc/asterisk/extensions.conf
```

## Best Practices

1. **Use generalized patterns** when possible - easier to maintain
2. **Comment your rules** - add descriptions for clarity
3. **Test before production** - use the preview feature
4. **Backup configuration** - dialplan changes are tracked in Git
5. **Use consistent contexts** - `from-internal` for internal calls
