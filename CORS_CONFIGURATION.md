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

- `http://localhost:3000` (Development frontend)
- `http://127.0.0.1:3000` (Alternative localhost)
- Value from `FRONTEND_URL` environment variable
- Value from `APP_URL` environment variable

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

### Advanced Configuration

For advanced CORS configuration, edit `backend/config/cors.php`:

```php
return [
    // API paths that support CORS
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'health'],

    // Allowed HTTP methods
    'allowed_methods' => ['*'],

    // Allowed origins
    'allowed_origins' => [
        // ... configured origins
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

## Security Considerations

1. **Only Add Trusted Origins** - Never use `*` for allowed origins in production
2. **Use HTTPS in Production** - Always use HTTPS URLs for production origins
3. **Credentials Support** - `supports_credentials: true` allows cookies and authorization headers
4. **Preflight Caching** - Currently set to 0 (no cache) for flexibility

## Troubleshooting

### CORS Errors in Browser Console

**Error:** "Access to fetch at '...' has been blocked by CORS policy"

**Solutions:**
1. Check that `FRONTEND_URL` is set correctly
2. Verify the frontend URL is in `CORS_ALLOWED_ORIGINS`
3. Check browser console for the exact origin being used
4. Clear browser cache and restart development servers

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
