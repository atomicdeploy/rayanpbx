# CORS Configuration Guide

## Overview

RayanPBX backend API includes built-in CORS (Cross-Origin Resource Sharing) support for scenarios where the frontend client directly accesses the backend API without a reverse proxy.

## When is CORS Needed?

CORS is needed when:
- Frontend (Nuxt app on port 3000) directly accesses Backend API (Laravel on port 8000)
- Frontend is served from a different domain/IP than the backend
- No reverse proxy (like Apache, Nginx) is handling CORS headers

**Note:** If you're using a reverse proxy (we have Apache2 configuration available), the reverse proxy will handle CORS instead of the backend.

## Configuration

### Basic Setup

The CORS configuration is automatically enabled with sensible defaults. The following origins are allowed by default:

- `http://localhost:3000` (from `FRONTEND_URL` environment variable)
- Additional origins specified in `CORS_ALLOWED_ORIGINS`

### Environment Variables

#### FRONTEND_URL
Primary URL where your frontend is hosted:
```bash
FRONTEND_URL=http://localhost:3000
```

#### CORS_ALLOWED_ORIGINS
Comma-separated list of additional allowed origins:
```bash
# Example: Multiple domains/IPs
CORS_ALLOWED_ORIGINS=https://rayanpbx.example.com,http://192.168.1.100:3000,https://another-domain.com
```

#### CORS_ALLOWED_ORIGINS_PATTERNS
Comma-separated list of allowed origin patterns (regex) for flexible origin matching:
```bash
# Example: Allow any IP/hostname on port 3000 (useful for development)
CORS_ALLOWED_ORIGINS_PATTERNS=/^https?:\/\/.*:3000$/

# Example: Allow subdomains
CORS_ALLOWED_ORIGINS_PATTERNS=/^https?:\/\/(.*\.)?example\.com(:\d+)?$/

# Example: Multiple patterns
CORS_ALLOWED_ORIGINS_PATTERNS=/^https?:\/\/.*:3000$/,/^https?:\/\/(.*\.)?example\.com$/
```

**Use Cases for Patterns:**
- **Development**: Allow access from any IP address on the same port (e.g., `http://172.20.10.99:3000`, `http://hp-server:3000`)
- **Dynamic IPs**: Match multiple IP addresses without hardcoding each one
- **Subdomains**: Allow all subdomains of a specific domain

**Security Note:** Patterns are powerful but should be used carefully. In production, prefer specific origins in `CORS_ALLOWED_ORIGINS` over broad patterns.

### Advanced Configuration

For advanced CORS configuration, edit `backend/config/cors.php`:

```php
return [
    // API paths that support CORS
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'health'],

    // Allowed HTTP methods
    'allowed_methods' => ['*'],

    // Allowed origins (exact matches)
    'allowed_origins' => [
        // ... configured origins
    ],

    // Allowed origin patterns (regex)
    'allowed_origins_patterns' => [
        // Example: '/^https?:\/\/.*:3000$/'
    ],

    // Allowed headers
    'allowed_headers' => ['*'],

    // Support for credentials (cookies, authorization headers)
    'supports_credentials' => true,

    // Max age for preflight cache (0 = no cache)
    'max_age' => 0,
];
```

## How It Works

### CORS Middleware

The `HandleCors` middleware is automatically applied to all API routes. It:

1. **Handles Preflight Requests** - Responds to OPTIONS requests with appropriate CORS headers
2. **Adds CORS Headers** - Adds necessary headers to actual API responses
3. **Validates Origins** - Only adds CORS headers for allowed origins

### Preflight Requests

Browsers automatically send preflight OPTIONS requests before actual requests. Example:

```bash
# Browser sends preflight:
OPTIONS /api/extensions
Origin: http://localhost:3000
Access-Control-Request-Method: GET
Access-Control-Request-Headers: Authorization

# Backend responds with:
Access-Control-Allow-Origin: http://localhost:3000
Access-Control-Allow-Methods: GET
Access-Control-Allow-Headers: Authorization
Access-Control-Allow-Credentials: true
```

### Actual Requests

After preflight, the actual request includes CORS headers:

```bash
# Browser sends:
GET /api/extensions
Origin: http://localhost:3000
Authorization: Bearer <token>

# Backend responds with:
Access-Control-Allow-Origin: http://localhost:3000
Access-Control-Allow-Credentials: true
[... response data ...]
```

## Testing CORS

### Test Preflight Request

```bash
curl -X OPTIONS http://localhost:8000/api/health \
  -H "Origin: http://localhost:3000" \
  -H "Access-Control-Request-Method: GET" \
  -H "Access-Control-Request-Headers: Authorization" \
  -v
```

Expected response includes:
- `Access-Control-Allow-Origin: http://localhost:3000`
- `Access-Control-Allow-Credentials: true`
- `Access-Control-Allow-Methods: GET`

### Test Actual Request

```bash
curl -X GET http://localhost:8000/api/health \
  -H "Origin: http://localhost:3000" \
  -v
```

Expected response includes:
- `Access-Control-Allow-Origin: http://localhost:3000`
- `Access-Control-Allow-Credentials: true`

## Production Deployment

### With Reverse Proxy (Recommended)

If using Apache, Nginx, or another reverse proxy:
1. Configure CORS headers in the proxy
2. The backend CORS will be automatically bypassed
3. See our Apache2 CORS configuration in the relevant PR

### Without Reverse Proxy

If accessing the API directly:
1. Set `FRONTEND_URL` to your production frontend URL
2. Add any additional origins to `CORS_ALLOWED_ORIGINS`
3. Ensure the backend is accessible from the frontend

Example production `.env`:
```bash
FRONTEND_URL=https://rayanpbx.company.com
CORS_ALLOWED_ORIGINS=https://admin.company.com,https://rayanpbx-mobile.company.com
```

### Development with Dynamic IPs

For development environments where you need to access the frontend from multiple IPs or hostnames:

```bash
# Allow any IP/hostname on port 3000 (development only)
CORS_ALLOWED_ORIGINS_PATTERNS=/^https?:\/\/.*:3000$/
```

This allows access from:
- `http://localhost:3000`
- `http://172.20.10.99:3000`
- `http://hp-server:3000`
- `http://192.168.1.100:3000`
- Any other IP or hostname on port 3000

**Warning:** Do not use broad patterns like this in production!

## Security Considerations

1. **Only Add Trusted Origins** - Never use `*` for allowed origins in production
2. **Use HTTPS in Production** - Always use HTTPS URLs for production origins
3. **Credentials Support** - `supports_credentials: true` allows cookies and authorization headers
4. **Preflight Caching** - Currently set to 0 (no cache) for flexibility
5. **Pattern Security** - Use `allowed_origins_patterns` carefully:
   - Patterns use regex matching and can be powerful
   - Broad patterns like `/^https?:\/\/.*$/` would allow ANY origin (never use this!)
   - Test patterns thoroughly to ensure they only match intended origins
   - In production, prefer explicit origins over patterns when possible

## Troubleshooting

### CORS Errors in Browser Console

**Error:** "Access to fetch at '...' has been blocked by CORS policy"

**Solutions:**
1. Check that `FRONTEND_URL` is set correctly
2. Verify the frontend URL is in `CORS_ALLOWED_ORIGINS` or matches a pattern in `CORS_ALLOWED_ORIGINS_PATTERNS`
3. Check browser console for the exact origin being used
4. For development with dynamic IPs, use `CORS_ALLOWED_ORIGINS_PATTERNS=/^https?:\/\/.*:3000$/`
5. Clear browser cache and restart development servers

### Preflight Request Failing

**Solutions:**
1. Ensure OPTIONS method is allowed in your web server
2. Check that CORS middleware is properly loaded
3. Verify `config/cors.php` paths include your API routes

### Headers Not Present

**Solutions:**
1. Clear Laravel configuration cache: `php artisan config:clear`
2. Restart the Laravel development server
3. Check that middleware is properly registered in `bootstrap/app.php`

## Related Files

- `backend/config/cors.php` - CORS configuration
- `backend/bootstrap/app.php` - Middleware registration
- `.env.example` - Environment variables template

## Additional Resources

- [MDN CORS Documentation](https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS)
- [Laravel CORS Documentation](https://laravel.com/docs/11.x/routing#cors)
- [fruitcake/php-cors](https://github.com/fruitcake/php-cors) - Underlying CORS library
