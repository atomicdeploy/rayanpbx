# Apache2 Configuration for RayanPBX

This document describes the Apache2 web server configuration for RayanPBX.

## Overview

RayanPBX uses Apache2 to serve both the Laravel backend and proxy requests to the Nuxt frontend. This configuration provides a production-ready web server setup.

## Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                     Apache2 Web Server                      │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  Port 80                           Port 8080               │
│  ┌────────────────────┐           ┌─────────────────────┐  │
│  │  Laravel Backend   │           │  Nuxt Frontend      │  │
│  │  (PHP-FPM)         │           │  (Proxy to PM2)     │  │
│  │                    │           │                     │  │
│  │  /api/*            │           │  /*                 │  │
│  └────────────────────┘           └─────────────────────┘  │
│           │                                 │               │
│           ▼                                 ▼               │
│  /opt/rayanpbx/backend/public    localhost:3000           │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

## Virtual Hosts

### Backend (Laravel API)

- **File**: `/etc/apache2/sites-available/rayanpbx-backend.conf`
- **Port**: 80 (default HTTP)
- **Document Root**: `/opt/rayanpbx/backend/public`
- **PHP Handler**: PHP-FPM via proxy_fcgi
- **Log Files**:
  - Error: `/var/log/apache2/rayanpbx-backend-error.log`
  - Access: `/var/log/apache2/rayanpbx-backend-access.log`

### Frontend (Nuxt Proxy)

- **File**: `/etc/apache2/sites-available/rayanpbx-frontend.conf`
- **Port**: 8080
- **Proxy Target**: `http://localhost:3000`
- **WebSocket Support**: Yes (via RewriteRule)
- **Log Files**:
  - Error: `/var/log/apache2/rayanpbx-frontend-error.log`
  - Access: `/var/log/apache2/rayanpbx-frontend-access.log`

## Required Apache Modules

The following Apache modules are enabled during installation:

- `mod_rewrite` - URL rewriting for Laravel
- `mod_proxy` - Proxy functionality
- `mod_proxy_http` - HTTP proxy support
- `mod_proxy_fcgi` - FastCGI proxy for PHP-FPM
- `mod_ssl` - SSL/TLS support (for future HTTPS)
- `mod_headers` - HTTP header manipulation

## Access URLs

After installation, RayanPBX is accessible at:

- **Backend API**: `http://your-server-ip/api`
- **Frontend Web UI**: `http://your-server-ip:8080`
- **WebSocket**: `ws://your-server-ip:9000/ws`

## File Permissions

The installer sets proper permissions for Laravel:

```bash
# Owner and group
chown -R www-data:www-data /opt/rayanpbx/backend

# Base permissions
chmod -R 755 /opt/rayanpbx/backend

# Writable directories
chmod -R 775 /opt/rayanpbx/backend/storage
chmod -R 775 /opt/rayanpbx/backend/bootstrap/cache
```

## PHP-FPM Configuration

PHP-FPM is used to handle PHP requests efficiently:

- **Service**: `php8.3-fpm`
- **Socket**: `/var/run/php/php8.3-fpm.sock`
- **User**: `www-data`

## Troubleshooting

### Check Apache Configuration

```bash
# Test Apache configuration syntax
sudo apache2ctl configtest

# Check enabled sites
sudo apache2ctl -S
```

### Restart Services

```bash
# Restart Apache2
sudo systemctl restart apache2

# Restart PHP-FPM
sudo systemctl restart php8.3-fpm

# Check service status
sudo systemctl status apache2
sudo systemctl status php8.3-fpm
```

### View Logs

```bash
# Real-time backend logs
sudo tail -f /var/log/apache2/rayanpbx-backend-error.log

# Real-time frontend logs
sudo tail -f /var/log/apache2/rayanpbx-frontend-error.log

# Apache error log
sudo tail -f /var/log/apache2/error.log
```

### Common Issues

#### 1. Permission Denied Errors

If you see permission errors in logs:

```bash
sudo chown -R www-data:www-data /opt/rayanpbx/backend
sudo chmod -R 755 /opt/rayanpbx/backend
sudo chmod -R 775 /opt/rayanpbx/backend/storage
sudo chmod -R 775 /opt/rayanpbx/backend/bootstrap/cache
```

#### 2. PHP Not Working

If PHP files are downloaded instead of executed:

```bash
# Ensure PHP-FPM is running
sudo systemctl start php8.3-fpm

# Ensure proxy_fcgi module is enabled
sudo a2enmod proxy_fcgi
sudo a2enconf php8.3-fpm
sudo systemctl restart apache2
```

#### 3. 404 Errors for API Routes

If API routes return 404:

```bash
# Ensure mod_rewrite is enabled
sudo a2enmod rewrite
sudo systemctl restart apache2

# Check .htaccess file exists
ls -la /opt/rayanpbx/backend/public/.htaccess
```

#### 4. Frontend Not Loading

If the frontend doesn't load on port 8080:

```bash
# Check if PM2 is running the frontend
sudo -u www-data pm2 list

# Check if Apache is listening on port 8080
sudo netstat -tlnp | grep :8080

# Restart PM2 services
cd /opt/rayanpbx
sudo -u www-data pm2 restart rayanpbx-web
```

## Manual Configuration

If you need to manually configure Apache2:

### Enable Sites

```bash
sudo a2ensite rayanpbx-backend.conf
sudo a2ensite rayanpbx-frontend.conf
sudo systemctl reload apache2
```

### Disable Sites

```bash
sudo a2dissite rayanpbx-backend.conf
sudo a2dissite rayanpbx-frontend.conf
sudo systemctl reload apache2
```

### Edit Virtual Hosts

```bash
# Edit backend configuration
sudo nano /etc/apache2/sites-available/rayanpbx-backend.conf

# Edit frontend configuration
sudo nano /etc/apache2/sites-available/rayanpbx-frontend.conf

# Test and reload
sudo apache2ctl configtest
sudo systemctl reload apache2
```

## Security Considerations

1. **File Permissions**: Ensure proper permissions are set to prevent unauthorized access
2. **SSL/TLS**: For production, configure SSL certificates using Let's Encrypt
3. **Firewall**: Configure UFW or iptables to restrict access as needed
4. **PHP Configuration**: Review php.ini settings for production use

## Future Enhancements

- SSL/TLS configuration with Let's Encrypt
- HTTP/2 support
- Additional security headers
- Rate limiting configuration
- CDN integration for static assets

## References

- [Apache2 Documentation](https://httpd.apache.org/docs/)
- [Laravel Deployment Documentation](https://laravel.com/docs/deployment)
- [PHP-FPM Configuration](https://www.php.net/manual/en/install.fpm.php)
