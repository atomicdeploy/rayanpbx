# RayanPBX

A modern, elegant SIP Server Management Toolkit for Ubuntu 24.04 LTS

## Overview

RayanPBX provides a comprehensive management layer for Asterisk-based SIP servers with multiple interfaces:

- 🌐 **Web Admin Panel**: Modern, beautiful SPA with dark mode and RTL support
- 💻 **TUI**: Elegant terminal interface for SSH management
- ⌨️ **CLI**: Scriptable commands for automation and CI/CD

## Features

- 📞 **Extension Management**: Create, update, disable, and delete SIP extensions
- 🌍 **Trunk Routing**: Configure outbound call routing with failover support
- 🔐 **PAM Authentication**: Secure access control using Linux user accounts
- 📊 **Real-time Monitoring**: Live status updates and log streaming
- 🎨 **Beautiful UI/UX**: Dark mode, smooth transitions, helpful tooltips
- 🌏 **Internationalization**: English and Persian (Farsi) with RTL support
- 🔧 **Non-invasive**: Works with existing Asterisk installations
- 🧪 **Fully Tested**: Automated tests with real SIP call flows

## Quick Start

### Prerequisites

- Ubuntu 24.04 LTS
- Root or sudo access
- MySQL/MariaDB 8.0+
- PHP 8.2+
- Node.js 20+
- Go 1.21+ (for TUI)

### Installation

```bash
# Clone the repository
git clone https://github.com/atomicdeploy/rayanpbx.git
cd rayanpbx

# Run the installer
sudo ./install.sh

# Start the services
sudo systemctl start rayanpbx-api
sudo systemctl start rayanpbx-web
```

### Access

- **Web UI**: http://localhost:3000
- **API**: http://localhost:8000/api
- **TUI**: `rayanpbx-tui`
- **CLI**: `rayanpbx` command

## Architecture

```
rayanpbx/
├── backend/        # Laravel API server
├── frontend/       # Nuxt 3 web interface
├── tui/           # Go-based terminal UI
├── scripts/       # Installation and setup scripts
├── tests/         # Integration tests with Docker
└── docs/          # Documentation
```

## Technology Stack

- **Backend**: Laravel 11 (PHP 8.2+)
- **Frontend**: Nuxt 3 (Vue 3 + TypeScript)
- **TUI**: Go + Bubble Tea
- **Database**: MySQL/MariaDB
- **SIP Server**: Asterisk 21 LTS with PJSIP
- **UI Framework**: Tailwind CSS + HeadlessUI
- **Icons**: Heroicons

## Development

### Backend Setup

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

### Frontend Setup

```bash
cd frontend
npm install
npm run dev
```

### TUI Build

```bash
cd tui
go build -o rayanpbx-tui
./rayanpbx-tui
```

## Testing

```bash
# Backend tests
cd backend && php artisan test

# Frontend tests
cd frontend && npm run test

# Integration tests with Docker
./scripts/test-integration.sh
```

## CLI Examples

```bash
# List extensions
rayanpbx extensions list

# Add an extension
rayanpbx extensions add 100 "John Doe" --password secret123

# Configure trunk
rayanpbx trunks add primary --host sip.provider.com --username user

# View status
rayanpbx status

# Show logs
rayanpbx logs --follow
```

## Configuration

Configuration is stored in:
- `/etc/rayanpbx/config.yaml` - Main configuration
- MySQL database - Extensions, trunks, and routing rules
- Asterisk configs - Auto-generated PJSIP and dialplan files

## Security

- PAM-based authentication with bcrypt password hashing
- CSRF protection on all forms
- Rate limiting on authentication endpoints
- SIP credentials stored as HA1 MD5 hashes
- No plaintext password storage
- Session-based authentication with secure tokens

## Contributing

Contributions are welcome! Please read our contributing guidelines.

## License

MIT License - See LICENSE file for details

## Support

For issues and questions:
- GitHub Issues: https://github.com/atomicdeploy/rayanpbx/issues
- Documentation: https://rayanpbx.io/docs

## Credits

Built with ❤️ for the open-source community
