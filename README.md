# 🚀 RayanPBX

> Modern, elegant SIP Server Management Toolkit with Web UI, TUI, and CLI

<div align="center">

```
╭──────────────────────────────────────────────────────────────╮
│                                                              │
│             🎯 RayanPBX - SIP Server Management             │
│                                                              │
│        A modern, beautiful, and powerful toolkit for         │
│          managing Asterisk-based PBX systems                 │
│                                                              │
╰──────────────────────────────────────────────────────────────╯
```

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](https://opensource.org/licenses/MIT)
[![PHP](https://img.shields.io/badge/PHP-8.3-777BB4?logo=php)](https://php.net)
[![Laravel](https://img.shields.io/badge/Laravel-11-FF2D20?logo=laravel)](https://laravel.com)
[![Node.js](https://img.shields.io/badge/Node.js-24-339933?logo=node.js)](https://nodejs.org)
[![Vue.js](https://img.shields.io/badge/Vue.js-3-4FC08D?logo=vue.js)](https://vuejs.org)
[![Go](https://img.shields.io/badge/Go-1.23-00ADD8?logo=go)](https://golang.org)
[![Asterisk](https://img.shields.io/badge/Asterisk-22-FF6600)](https://asterisk.org)

</div>

## ✨ Features

```
┌─────────────────────────────────────────────────────────────┐
│ 🌐 Web Admin Panel    │  Modern, responsive SPA/PWA        │
│ 🖥️ Terminal UI (TUI)  │  Beautiful CLI interface           │
│ ⚡ Real-time Events   │  WebSocket-based live updates      │
│ 🔐 JWT Authentication │  Secure, token-based auth          │
│ 🎨 Dark Mode          │  Elegant dark/light themes         │
│ 🌍 i18n Support       │  English & Persian (RTL)           │
│ 📱 Extension Manager  │  Complete SIP extension lifecycle  │
│ 🔗 Trunk Routing      │  Advanced outbound call routing    │
│ 🖥️ Asterisk Console   │  Interactive CLI from web UI       │
│ 📊 Live Monitoring    │  Real-time call & system status    │
│ ⚙️ Config Management  │  CLI/TUI/Web .env management       │
│ 🔍 Phone Discovery    │  LLDP/nmap-based phone detection   │
│ 🧪 SIP Testing Suite  │  Comprehensive extension testing   │
└─────────────────────────────────────────────────────────────┘
```

## 🏗️ Architecture

```
╭────────────────────────────────────────────────────────────────╮
│                     RayanPBX Architecture                      │
├────────────────────────────────────────────────────────────────┤
│                                                                │
│   ┌──────────────┐   ┌──────────────┐   ┌──────────────┐    │
│   │   Web UI     │   │     TUI      │   │     CLI      │    │
│   │ (Nuxt/Vue3)  │   │  (Go/Bubble) │   │   (Bash)     │    │
│   └──────┬───────┘   └──────┬───────┘   └──────┬───────┘    │
│          │                  │                   │             │
│          └──────────────────┼───────────────────┘             │
│                             │                                 │
│   ┌────────────────────────────────────────────────────┐     │
│   │         API Server (Laravel 11 / PHP 8.3)         │     │
│   │  ┌─────────────┐  ┌─────────────┐  ┌───────────┐ │     │
│   │  │  JWT Auth   │  │   Console   │  │  WebSocket │ │     │
│   │  └─────────────┘  └─────────────┘  └───────────┘ │     │
│   └────────────────────────┬───────────────────────────┘     │
│                            │                                  │
│   ┌────────────┬───────────┴────────┬─────────────┐         │
│   │            │                    │             │         │
│   ▼            ▼                    ▼             ▼         │
│ ┌─────┐   ┌─────────┐        ┌──────────┐   ┌────────┐    │
│ │MySQL│   │Asterisk │        │  Redis   │   │  PAM   │    │
│ └─────┘   │   22    │        └──────────┘   └────────┘    │
│           └─────────┘                                       │
╰────────────────────────────────────────────────────────────────╯
```

## 📦 Tech Stack

### Backend
- **Laravel 11** - Modern PHP framework
- **PHP 8.3** - Latest PHP version
- **MySQL/MariaDB** - Database
- **Redis** - Caching & sessions
- **JWT** - Stateless authentication

### Frontend
- **Nuxt 3** - Vue.js framework for SPA/PWA
- **Vue 3** - Progressive JavaScript framework
- **TypeScript** - Type-safe development
- **Tailwind CSS** - Utility-first CSS
- **SCSS** - Enhanced styling with logical properties
- **Pinia** - State management

### TUI/CLI
- **Go 1.23** - Systems programming language
- **Bubble Tea** - Terminal UI framework
- **Lipgloss** - Styling for terminals
- **Figlet** - ASCII art generation

### DevOps
- **PM2** - Process manager for Node.js
- **systemd** - Linux service management
- **GitHub Actions** - CI/CD pipeline
- **nala** - Modern APT wrapper

### SIP/Telephony
- **Asterisk 22** - Open source PBX
- **PJSIP** - Modern SIP stack
- **AMI** - Asterisk Manager Interface

## 🚀 Quick Start

### One-Line Installation

```bash
sudo bash -c "$(curl -fsSL https://raw.githubusercontent.com/atomicdeploy/rayanpbx/main/install.sh)"
```

### Manual Installation

```bash
# Clone the repository
git clone https://github.com/atomicdeploy/rayanpbx.git
cd rayanpbx

# Run the installer (standard mode)
sudo ./install.sh

# Or with verbose mode for debugging
sudo ./install.sh --verbose
```

### Installation Options

The installer supports several command-line options:

```bash
# Show help and available options
./install.sh --help

# Show version
./install.sh --version

# List all available installation steps
./install.sh --list-steps

# Install with verbose output (recommended for debugging)
sudo ./install.sh --verbose

# Run only specific steps (for upgrades or partial installations)
sudo ./install.sh --steps=backend,frontend,tui

# Skip certain steps (e.g., skip Asterisk installation)
sudo ./install.sh --skip=asterisk,asterisk-ami

# Automatic upgrade mode (no prompts)
sudo ./install.sh --upgrade

# Create backup before updates
sudo ./install.sh --upgrade --backup
```

#### Step-Based Installation

For faster upgrades or when you only need to update specific components:

```bash
# Update only the backend API
sudo ./install.sh --steps=source,env-config,backend,systemd

# Update only the frontend
sudo ./install.sh --steps=source,env-config,frontend,pm2

# Update only the TUI
sudo ./install.sh --steps=source,tui,pm2

# Install without Asterisk (development environment)
sudo ./install.sh --skip=asterisk,asterisk-ami
```

**Important:** When using `--steps` or `--skip`, ensure all dependencies are already installed. See [INSTALL_STEPS_GUIDE.md](INSTALL_STEPS_GUIDE.md) for detailed dependency information.

For detailed information about command-line options, see [COMMAND_LINE_OPTIONS.md](COMMAND_LINE_OPTIONS.md).

### Troubleshooting Installation

If the installation fails or exits unexpectedly:

1. **Use verbose mode** to see detailed information:
   ```bash
   sudo ./install.sh --verbose 2>&1 | tee install.log
   ```

2. **Check the log** to identify where it failed.

3. **Report issues** with the log file at: https://github.com/atomicdeploy/rayanpbx/issues

### Configuration

Interactive configuration tool:

```bash
cd /opt/rayanpbx
./scripts/config-tui.sh
```

Non-interactive configuration:

```bash
./scripts/config-tui.sh /opt/rayanpbx/.env /opt/rayanpbx/.env.example --non-interactive
```

## 📖 Usage

### Web Interface

```
╭────────────────────────────────────────────────────╮
│  🌐 Web UI: http://your-server-ip:3000           │
│  📡 API:    http://your-server-ip:8000/api       │
│  🔌 WebSocket: ws://your-server-ip:9000/ws       │
╰────────────────────────────────────────────────────╯
```

Default login uses your Linux username and password via PAM authentication.

### Terminal UI

```bash
rayanpbx-tui
```

### Artisan Commands

RayanPBX provides comprehensive Laravel Artisan commands for system management:

```bash
# Check system status
php artisan rayanpbx:status

# Run health checks
php artisan rayanpbx:health

# Manage services
php artisan rayanpbx:service restart asterisk

# Manage extensions
php artisan rayanpbx:extension list
php artisan rayanpbx:extension create 1001

# Manage trunks
php artisan rayanpbx:trunk list
php artisan rayanpbx:trunk create

# Configuration management
php artisan rayanpbx:config validate
php artisan rayanpbx:config reload

# Backup and restore
php artisan rayanpbx:backup --compress
php artisan rayanpbx:restore /path/to/backup

# Execute Asterisk CLI commands
php artisan rayanpbx:asterisk "core show calls"
```

For complete documentation, see [ARTISAN_COMMANDS.md](ARTISAN_COMMANDS.md).

### Shell Scripts

```bash
# Using health check script
/opt/rayanpbx/scripts/health-check.sh full-check

# Using config TUI
/opt/rayanpbx/scripts/config-tui.sh

# Using INI helper
/opt/rayanpbx/scripts/ini-helper.sh modify-manager
```

### Service Management

```bash
# Check services
systemctl status rayanpbx-api
systemctl status asterisk

# View PM2 services
pm2 list
pm2 logs

# View logs
journalctl -u rayanpbx-api -f
journalctl -u asterisk -f
```

## 🔧 Development

### Backend Development

```bash
cd /opt/rayanpbx/backend
composer install
php artisan serve
```

### Frontend Development

```bash
cd /opt/rayanpbx/frontend
npm install
npm run dev
```

### TUI Development

```bash
cd /opt/rayanpbx/tui
go build -o rayanpbx-tui main.go config.go
./rayanpbx-tui
```

## 📚 Documentation

```
╭──────────────────────────────────────────────────────────╮
│  📖 User Guide:          /docs/user-guide.md             │
│  🔧 API Docs:            /docs/api.md                    │
│  🏗️ Architecture:        /docs/architecture.md           │
│  🚀 Deployment:          /docs/deployment.md             │
│  🔐 Security:            /docs/security.md               │
│  🌐 CORS Configuration:  CORS_CONFIGURATION.md           │
│  📝 Command Options:     COMMAND_LINE_OPTIONS.md         │
│  ⚙️ Config Management:  ENV_MANAGEMENT.md                │
│  🔍 Phone Discovery:     PHONE_DISCOVERY.md              │
│  🧪 SIP Testing Guide:   SIP_TESTING_GUIDE.md            │
│  📡 PJSIP Setup:         PJSIP_SETUP_GUIDE.md            │
│  📊 Implementation:      VOIP_DISCOVERY_IMPLEMENTATION.md│
╰──────────────────────────────────────────────────────────╯
```

## 🤝 Contributing

Contributions are welcome! Please read our [Contributing Guide](CONTRIBUTING.md) for details.

## 📄 License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## 💙 Acknowledgments

- **Asterisk** - The world's leading open source PBX
- **Laravel** - The PHP framework for web artisans
- **Vue.js** - The progressive JavaScript framework
- **Go** - Build simple, secure, scalable systems
- **pollination.ai** - AI-powered assistance

## 📞 Support

```
╭──────────────────────────────────────────────────────╮
│  🐛 Issues:  github.com/atomicdeploy/rayanpbx/issues │
│  💬 Discussions: github.com/atomicdeploy/rayanpbx    │
│  📧 Email:   support@rayanpbx.local                  │
╰──────────────────────────────────────────────────────╯
```

---

<div align="center">

**Built with ❤️ by the RayanPBX Team**

```
╭────────────────────────────────────────╮
│  🚀 Modern • 🎨 Elegant • 💪 Powerful  │
╰────────────────────────────────────────╯
```

</div>
