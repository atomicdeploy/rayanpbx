# WebSocket Live Features - Testing Guide

## Overview
This document provides instructions for testing the WebSocket live features implementation in RayanPBX.

## Architecture

The WebSocket implementation follows this flow:

```
[Frontend Vue App] <--WebSocket--> [Go WebSocket Server] <--Redis Pub/Sub--> [Laravel Backend]
       |                                    |                                        |
       |                                    |                                        |
    User Actions                    Broadcast Events                         CRUD Operations
```

## Prerequisites

1. **Redis Server**: Must be running and accessible
   ```bash
   sudo systemctl start redis-server
   sudo systemctl status redis-server
   ```

2. **MySQL/MariaDB**: Database must be set up
   ```bash
   sudo systemctl status mysql
   ```

3. **Environment Configuration**: Ensure `.env` file has correct settings
   ```bash
   # Backend .env
   REDIS_HOST=127.0.0.1
   REDIS_PORT=6379
   REDIS_PASSWORD=
   
   # WebSocket Server (reads from root .env)
   WEBSOCKET_HOST=0.0.0.0
   WEBSOCKET_PORT=9000
   JWT_SECRET=your-super-secret-jwt-key-change-this
   ```

## Starting the Services

### 1. Start Backend API (Laravel)
```bash
cd /opt/rayanpbx/backend
php artisan serve --host=0.0.0.0 --port=8000
```

### 2. Start WebSocket Server (Go)
```bash
cd /opt/rayanpbx/tui
go run websocket.go config.go

# Or if already built:
/usr/local/bin/rayanpbx-ws
```

Expected output:
```
╭──────────────────────────────────────────────────╮
│        WebSocket Server                          │
╰──────────────────────────────────────────────────╯
    🚀 RayanPBX Real-time Event System 🚀

🔧 Loading configuration...
✅ Configuration loaded
🔌 Connecting to database...
✅ Database connected
📊 Starting database monitor...
📡 Starting Redis monitor...
✅ Redis connected
🎧 Subscribed to Redis channels: extensions, trunks, calls, status
🚀 WebSocket server starting on ws://0.0.0.0:9000/ws
💚 Health endpoint: http://0.0.0.0:9000/health
📡 Waiting for connections...
```

### 3. Start Frontend (Nuxt)
```bash
cd /opt/rayanpbx/frontend
npm install  # If not already done
npm run dev
```

## Testing WebSocket Events

### Test 1: Extension Creation
1. Open browser to `http://localhost:3000`
2. Login with your credentials
3. Open browser console (F12) to see WebSocket logs
4. Navigate to Extensions page
5. Click "Add Extension"
6. Fill in details and save
7. **Expected Results:**
   - In WebSocket server console: `📤 Broadcast event: extension.created`
   - In browser console: `Extension created: { id, extension_number, name, enabled }`
   - Extension appears in list immediately without page refresh

### Test 2: Extension Update
1. Click edit button on any extension
2. Change the name or other details
3. Save
4. **Expected Results:**
   - In WebSocket server console: `📤 Broadcast event: extension.updated`
   - In browser console: `Extension updated: { id, extension_number, name, enabled }`
   - Changes appear immediately

### Test 3: Extension Deletion
1. Click delete button on any extension
2. Confirm deletion
3. **Expected Results:**
   - In WebSocket server console: `📤 Broadcast event: extension.deleted`
   - In browser console: `Extension deleted: { id, extension_number }`
   - Extension removed from list immediately

### Test 4: Multi-Client Broadcasting
1. Open two browser windows/tabs to the Extensions page
2. In first tab: Create/update/delete an extension
3. **Expected Results:**
   - Both tabs show the changes immediately
   - WebSocket server shows 2 connected clients
   - Both clients receive the broadcast

### Test 5: Trunk Operations
1. Navigate to Trunks page
2. Create/Update/Delete a trunk
3. **Expected Results:**
   - Similar to extension tests
   - Events: `trunk.created`, `trunk.updated`, `trunk.deleted`

### Test 6: WebSocket Reconnection
1. While on Extensions or Trunks page
2. Stop the WebSocket server (Ctrl+C)
3. **Expected Results:**
   - Browser console shows: `👋 WebSocket disconnected`
   - Browser console shows: `🔄 Reconnecting in XXXms (attempt N)...`
4. Restart WebSocket server
5. **Expected Results:**
   - Browser console shows: `✅ WebSocket connected`
   - Reconnection happens automatically

### Test 7: Dashboard Status Updates
1. Open Dashboard page
2. Create or delete extensions/trunks
3. **Expected Results:**
   - Dashboard counts update in real-time
   - Event type: `status_update`

## Debugging

### Check WebSocket Health
```bash
curl http://localhost:9000/health
# Expected: {"status":"healthy","clients":N}
```

### Monitor Redis Pub/Sub
```bash
redis-cli
> SUBSCRIBE rayanpbx:extensions rayanpbx:trunks rayanpbx:status
```

### Check WebSocket Connection in Browser
Open browser console and run:
```javascript
// Check WebSocket state
window.$nuxt.$root.$data  // Look for WebSocket store state
```

### Common Issues

#### Issue: WebSocket not connecting
- **Check**: Is WebSocket server running on port 9000?
- **Check**: JWT token valid? (Check localStorage: `rayanpbx_token`)
- **Check**: CORS settings in WebSocket server (currently allows all origins in dev)

#### Issue: No events received
- **Check**: Is Redis running?
- **Check**: Redis connection settings in .env
- **Check**: EventBroadcastService is being called in controllers

#### Issue: Events received but UI not updating
- **Check**: Browser console for JavaScript errors
- **Check**: Event handlers registered correctly in onMounted
- **Check**: Component not unmounted/remounted

## Event Payload Examples

### extension.created
```json
{
  "type": "extension.created",
  "payload": {
    "id": 123,
    "extension_number": "101",
    "name": "John Doe",
    "enabled": true
  },
  "timestamp": "2025-11-23T13:30:00.000Z"
}
```

### trunk.updated
```json
{
  "type": "trunk.updated",
  "payload": {
    "id": 5,
    "name": "MainTrunk",
    "host": "sip.provider.com",
    "enabled": true
  },
  "timestamp": "2025-11-23T13:30:00.000Z"
}
```

### status_update
```json
{
  "type": "status_update",
  "payload": {
    "extensions": 10,
    "trunks": 2
  },
  "timestamp": "2025-11-23T13:30:00.000Z"
}
```

## Performance Considerations

- WebSocket connections are persistent (not polling)
- Database polling reduced significantly:
  - Dashboard: 30s intervals (was 5s)
  - Extensions: 10s for live status only
  - Trunks: No polling
- Redis pub/sub is very efficient
- Go WebSocket server can handle thousands of concurrent connections

## Next Steps

To complete the implementation:
1. Add WebSocket events for logs, console, and traffic pages
2. Add Asterisk AMI event monitoring
3. Broadcast call status changes
4. Add registration status change events
5. Production hardening (WSS, proper CORS, rate limiting)
