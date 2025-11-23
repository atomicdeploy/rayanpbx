# RayanPBX - UI/UX Design Documentation

## Beautiful, Modern Admin Panel ✨

### Design Philosophy

RayanPBX features a **professionally designed, modern admin panel** that prioritizes:
- ✅ **Beautiful aesthetics** - Gradient backgrounds, smooth animations, professional color schemes
- ✅ **Excellent UX** - Intuitive navigation, clear information hierarchy, helpful tooltips
- ✅ **Accessibility** - WCAG compliant, keyboard navigation, screen reader support
- ✅ **Performance** - Fast loading, smooth transitions, optimized assets
- ✅ **Responsiveness** - Works perfectly on desktop, tablet, and mobile

---

## Login Screen 🔐

### Design Features

**Visual Elements:**
- **Animated gradient background** - Blue → Purple → Pink with blob animations
- **Professional logo** - RayanPBX with pulsing animation effect
- **Glassmorphism card** - Frosted glass effect with backdrop blur
- **Modern input fields** - Icons, placeholders, password toggle
- **Smooth animations** - Fade-in effects, hover states, loading spinners

**Components Used:**
- Logo component with animated pulse
- Icon-enhanced input fields (User, Lock icons)
- Password visibility toggle (Eye icon)
- Error messages with alert icons
- Loading spinner during authentication

**Features:**
- PAM authentication info displayed
- Helpful placeholders (Enter your Linux username)
- Smooth error handling with AI explanations
- Dark mode support
- RTL support for Persian

---

## Dashboard 📊

### Status Cards

Four beautifully designed status cards with:
- **Gradient backgrounds** - Subtle color coding
- **Large, clear icons** - Heroicons (Phone, Database, Users, Globe)
- **Live data** - Real-time status updates
- **Color-coded indicators** - Green (running), Red (stopped), Yellow (warning)
- **Smooth animations** - Hover effects, transitions

**Cards:**
1. **Asterisk Status** - Shows if PBX is running/stopped
2. **Database Status** - Shows MySQL/MariaDB connection
3. **Extensions** - Active/Total count with live registration data
4. **Trunks** - Active/Total count with reachability status

### Quick Actions Grid

Four action cards with:
- **Large icons** - Visual representation of function
- **Clear titles** - "Extensions", "Trunks", "Console", "Logs"
- **Descriptions** - Brief explanation of each function
- **Hover effects** - Shadow elevation, smooth transitions
- **Click animations** - Scale effects

---

## Extensions Management 📱

### Table View

**Enhanced table with:**
- **HD Codec Badges** - Green gradient badges for 16kHz+ audio (g722, opus, etc.)
- **Live Registration Status** - Pulsing green dot for registered, gray for offline
- **IP Address Display** - Shows device IP and port when registered
- **Latency Monitoring** - Displays qualify results in milliseconds
- **User-Agent Info** - Shows SIP client software
- **Action Buttons** - Edit (pencil icon) and Delete (trash icon)

**Visual Indicators:**
```
Extension | Status          | HD Badge
----------------------------------------
100       | 🟢 Registered   | 🎵 HD (g722)
          | 📍 192.168.1.50:5060
          | (45ms)
```

### Add/Edit Modal

**Modern modal dialog with:**
- **Overlay backdrop** - Blurred background
- **Clean form layout** - Two-column grid for compact design
- **Icon-enhanced labels** - Visual cues for each field
- **Validation** - Real-time validation with error messages
- **AI Help** - Tooltips with Pollination.ai explanations
- **Loading states** - Spinner during save

**Fields:**
- Extension Number (disabled when editing)
- Name
- Email
- Password (with strength indicator)
- Enabled checkbox
- Notes textarea

---

## Trunks Management 🌐

### Table View

**Professional trunk management with:**
- **Connection Status** - Color-coded indicators (green/yellow/red)
- **Reachability Testing** - Live qualify results
- **Latency Display** - Ping time to provider
- **Provider Info** - Host, port, registration status
- **Action Buttons** - Test, Edit, Delete

**Visual Indicators:**
```
Trunk         | Status          | Latency
---------------------------------------------
PrimaryTrunk  | 🟢 Reachable    | 45ms
              | sip.provider.com:5060
              | ✅ Registered
```

### Add/Edit Modal

**Comprehensive trunk configuration:**
- Host/IP address
- Port number
- Username/Password (optional for static peers)
- Routing prefix (e.g., 9 for external)
- Strip digits checkbox
- Codec preferences
- NAT settings (direct_media, from_domain)
- Notes

---

## Console Page 🖥️

### Interactive Asterisk CLI

**Features:**
- **Command history** - Navigate with up/down arrows
- **Syntax highlighting** - Color-coded output
- **Auto-complete** - Common commands suggested
- **Quick commands** - Buttons for common operations
- **Live output** - Real-time command results
- **Dark theme** - Terminal-style interface

**Quick Actions:**
- Show Endpoints
- Show Channels
- Show Calls
- Reload Dialplan
- Show Version

---

## Logs Viewer 📋

### Live Log Streaming

**Features:**
- **Color-coded levels** - Error (red), Warning (yellow), Info (blue), Debug (gray)
- **Auto-scroll** - Follows new logs automatically
- **Search/Filter** - Find specific log entries
- **Time display** - Formatted timestamps
- **Source indication** - Which component generated the log
- **Export** - Download logs as file

---

## Traffic Analyzer 📡

### Packet Capture Interface

**Features:**
- **Start/Stop Controls** - Easy packet capture management
- **Live Statistics** - Packet count, file size, duration
- **SIP Message Parsing** - Identifies REGISTER, INVITE, etc.
- **RTP Stream Detection** - Counts audio streams
- **Filter Options** - Port, protocol, source/destination
- **Export PCAP** - Download for Wireshark analysis

---

## Design System

### Colors

**Primary Palette:**
```scss
$primary-blue: #3b82f6;
$primary-purple: #8b5cf6;
$success-green: #10b981;
$warning-yellow: #f59e0b;
$danger-red: #ef4444;
```

**Dark Mode:**
```scss
$dark-bg: #111827;
$dark-card: #1f2937;
$dark-border: #374151;
$dark-text: #f9fafb;
```

### Typography

**English:**
- System fonts (Inter, SF Pro Display, Segoe UI)
- Font weights: 400 (normal), 500 (medium), 600 (semibold), 700 (bold)

**Persian (Farsi):**
- Vazir font family
- Proper RTL text direction
- Right-aligned form labels
- Logical properties (margin-inline-start instead of margin-left)

### Icons

**Heroicons v2:**
- Outline style for navigation
- Solid style for buttons and emphasis
- 20x20px for inline use
- 24x24px for buttons and headers

**Examples:**
- PhoneIcon - Extensions, calls
- GlobeIcon - Trunks, network
- DatabaseIcon - Storage, persistence
- ChartBarIcon - Statistics, analytics
- CogIcon - Settings, configuration

### Animations

**Transition Speed:**
- Fast: 150ms (hover effects)
- Normal: 200ms (default)
- Slow: 300ms (page transitions)

**Effects:**
- Fade in on page load
- Smooth color transitions
- Scale on hover (cards)
- Pulse animation (status indicators)
- Bounce on click (buttons)
- Blob animations (background)

### Components

**Buttons:**
```vue
<button class="btn btn-primary">Primary Action</button>
<button class="btn btn-secondary">Secondary Action</button>
<button class="btn btn-danger">Delete</button>
```

**Cards:**
```vue
<div class="card">
  <h3>Card Title</h3>
  <p>Card content</p>
</div>
```

**Inputs:**
```vue
<label class="label">Field Label</label>
<input type="text" class="input" placeholder="Enter value" />
```

**Status Badges:**
```vue
<span class="status-badge status-badge-success">Online</span>
<span class="status-badge status-badge-danger">Offline</span>
```

---

## Accessibility

**WCAG 2.1 Compliance:**
- ✅ Proper color contrast ratios
- ✅ Keyboard navigation support
- ✅ Screen reader labels (aria-label)
- ✅ Focus indicators
- ✅ Semantic HTML structure
- ✅ Alternative text for icons

**Features:**
- Tab navigation through forms
- Escape key closes modals
- Enter key submits forms
- Arrow keys navigate lists
- Screen reader announcements for status changes

---

## Responsive Design

### Breakpoints

**Mobile First Approach:**
```scss
// Small devices (phones, <640px)
@media (max-width: 639px) { ... }

// Medium devices (tablets, ≥640px)
@media (min-width: 640px) { ... }

// Large devices (desktops, ≥1024px)
@media (min-width: 1024px) { ... }

// Extra large devices (≥1280px)
@media (min-width: 1280px) { ... }
```

**Layout Adaptations:**
- Mobile: Single column, stacked cards
- Tablet: Two-column grid for cards
- Desktop: Four-column grid, side-by-side modals
- XL: Wider containers, more whitespace

---

## UI Component Library

**Headless UI (Vue):**
- Dialog - Modals, confirmations
- Menu - Dropdowns, context menus
- Listbox - Select dropdowns
- Disclosure - Collapsible sections
- Transition - Smooth animations

**Benefits:**
- Fully accessible out of the box
- No imposed styling (use Tailwind)
- TypeScript support
- Vue 3 Composition API

---

## Performance Optimizations

**Frontend:**
- Code splitting (Nuxt auto-imports)
- Lazy loading images
- Debounced API calls
- Virtual scrolling for large lists
- Cached API responses
- Service worker for PWA

**Backend:**
- Query optimization (Eloquent eager loading)
- Response caching (Redis)
- API rate limiting
- Gzip compression
- CDN for static assets

---

## Dark Mode

**Implementation:**
- Toggle button in navigation
- Automatic system preference detection
- Persisted in localStorage
- Smooth transition between modes
- All components styled for both modes
- Proper contrast in dark mode

**Code:**
```vue
<button @click="toggleColorMode" class="btn btn-secondary">
  <span v-if="colorMode === 'dark'">☀️ Light</span>
  <span v-else>🌙 Dark</span>
</button>
```

---

## RTL Support (Persian/Farsi)

**Implementation:**
- Automatic text direction switching
- Vazir font for Persian text
- Logical properties in CSS (margin-inline-start instead of margin-left)
- Mirrored layouts where appropriate
- Number formatting (۱۲۳۴۵۶۷۸۹۰)

**Code:**
```vue
<button @click="toggleLocale" class="btn btn-secondary">
  {{ locale === 'en' ? 'فارسی' : 'English' }}
</button>
```

---

## Future UI Enhancements

### Planned Features

1. **Dashboard Widgets** - Customizable, draggable widgets
2. **Call Analytics** - Charts and graphs for call statistics
3. **IVR Builder** - Visual flowchart builder
4. **Phone Management** - GrandStream phone UI
5. **User Management** - Admin user interface
6. **Backup/Restore** - Visual backup management
7. **System Settings** - Comprehensive settings page
8. **Notifications** - Toast notifications for events
9. **Help System** - Integrated documentation
10. **Theming** - Custom color schemes

---

## UI Screenshots

### Login Screen
![Login](./screenshots/login.png)
- Animated gradient background with blob effects
- Professional logo with pulse animation
- Glassmorphism card effect
- Icon-enhanced input fields
- Password visibility toggle
- PAM authentication info

### Dashboard
![Dashboard](./screenshots/dashboard.png)
- Four status cards with gradients
- Live data with color-coded indicators
- Quick action grid with hover effects
- Dark mode support

### Extensions Page
![Extensions](./screenshots/extensions.png)
- HD codec badges (green gradient)
- Live registration status (pulsing dot)
- IP address and latency display
- Modern table with hover effects
- Edit/delete action buttons

### Trunks Page
![Trunks](./screenshots/trunks.png)
- Connection status indicators
- Reachability testing with latency
- Provider information display
- Action buttons for management

### Console Page
![Console](./screenshots/console.png)
- Terminal-style interface
- Syntax-highlighted output
- Quick command buttons
- Command history

---

## Summary

RayanPBX features a **world-class, beautiful admin panel** that combines:
- ✅ **Professional design** - Gradients, animations, modern aesthetics
- ✅ **Excellent UX** - Intuitive, clear, helpful
- ✅ **Modern components** - Headless UI, Heroicons, Tailwind CSS
- ✅ **Full functionality** - Extension/trunk management, console, logs, traffic analysis
- ✅ **Accessibility** - WCAG compliant, keyboard navigation
- ✅ **Dark mode** - Complete support with smooth transitions
- ✅ **RTL support** - Full Persian/Farsi support with Vazir font
- ✅ **Responsive** - Works on all devices
- ✅ **Fast** - Optimized performance

**The UI is production-ready and rivals commercial PBX solutions!** 🎉
