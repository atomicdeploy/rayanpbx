<template>
  <div class="phones-container">
    <div class="header">
      <div class="header-title">
        <NuxtLink to="/" class="back-link">← Dashboard</NuxtLink>
        <h1>📱 VoIP Phones Management</h1>
      </div>
      <div class="header-actions">
        <button @click="refreshPhones" class="btn btn-primary">
          🔄 Refresh
        </button>
        <button @click="discoverAllPhones" class="btn btn-success">
          🔍 Discover All
        </button>
        <button @click="discoverLldpNeighbors" class="btn btn-info">
          📡 LLDP Discovery
        </button>
        <button @click="discoverArpNeighbors" class="btn btn-secondary">
          🌐 ARP Discovery
        </button>
      </div>
    </div>

    <!-- Discovery Panel (shows discovered devices from all sources) -->
    <div v-if="showDiscoveryPanel && !selectedPhone" class="discovery-panel">
      <div class="panel-header">
        <h3>🔍 Discovered Devices</h3>
        <div class="panel-tabs">
          <button 
            :class="['tab-btn', discoveryTab === 'all' ? 'active' : '']"
            @click="discoveryTab = 'all'"
          >
            All ({{ discoveredDevices.length }})
          </button>
          <button 
            :class="['tab-btn', discoveryTab === 'lldp' ? 'active' : '']"
            @click="discoveryTab = 'lldp'"
          >
            📡 LLDP ({{ lldpNeighbors.length }})
          </button>
          <button 
            :class="['tab-btn', discoveryTab === 'arp' ? 'active' : '']"
            @click="discoveryTab = 'arp'"
          >
            🌐 ARP ({{ arpNeighbors.length }})
          </button>
        </div>
        <button @click="showDiscoveryPanel = false" class="btn btn-close">✕</button>
      </div>
      
      <div v-if="discoveryLoading" class="loading">
        Discovering devices...
      </div>
      
      <div v-else-if="currentDiscoveryList.length === 0" class="empty-state">
        <p>📭 No devices found</p>
        <p class="help-text">Try running discovery again or ensure devices are connected</p>
      </div>
      
      <div v-else class="neighbor-cards">
        <div
          v-for="device in currentDiscoveryList"
          :key="device.mac || device.ip"
          class="neighbor-card"
        >
          <div class="neighbor-icon">
            {{ device.vendor === 'GrandStream' ? '📞' : (device.discovery_type === 'lldp' ? '📡' : '🌐') }}
          </div>
          <div class="neighbor-info">
            <h4>{{ device.model || device.hostname || 'Unknown Device' }}</h4>
            <p class="neighbor-ip">IP: {{ device.ip || 'N/A' }}</p>
            <p class="neighbor-mac">MAC: {{ device.mac || 'N/A' }}</p>
            <p class="neighbor-vendor">Vendor: {{ device.vendor || 'Unknown' }}</p>
            <span class="discovery-type-badge">{{ device.discovery_type?.toUpperCase() || 'UNKNOWN' }}</span>
            <div v-if="device.capabilities && device.capabilities.length > 0" class="neighbor-capabilities">
              <span v-for="cap in device.capabilities" :key="cap" class="capability-badge">
                {{ cap }}
              </span>
            </div>
          </div>
          <div class="neighbor-actions">
            <button 
              v-if="device.ip" 
              @click="addDiscoveredDeviceToPhones(device)" 
              class="btn btn-success btn-sm"
            >
              ➕ Add to Phones
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Phone List -->
    <div v-if="!selectedPhone" class="phones-list">
      <div v-if="loading" class="loading">
        Loading phones...
      </div>

      <div v-else-if="phones.length === 0" class="empty-state">
        <p>📭 No phones detected</p>
        <p class="help-text">Click "Discover All" to find VoIP phones on your network, or phones are automatically detected from SIP registrations</p>
      </div>

      <div v-else class="phone-cards">
        <div
          v-for="phone in phones"
          :key="phone.ip"
          class="phone-card"
          @click="selectPhone(phone)"
        >
          <div class="phone-icon">
            {{ phone.status === 'online' ? '🟢' : '🔴' }}
          </div>
          <div class="phone-info">
            <h3>{{ phone.extension || 'Unknown' }}</h3>
            <p class="phone-ip">{{ phone.ip }}</p>
            <p class="phone-model">{{ phone.user_agent || 'GrandStream' }}</p>
          </div>
          <div class="phone-status">
            <span :class="['status-badge', phone.status]">
              {{ phone.status }}
            </span>
          </div>
        </div>
      </div>
    </div>

    <!-- Phone Details -->
    <div v-else class="phone-details">
      <div class="details-header">
        <button @click="selectedPhone = null" class="btn btn-back">
          ← Back
        </button>
        <h2>Phone: {{ selectedPhone.extension || selectedPhone.ip }}</h2>
        <button @click="refreshPhoneStatus" class="btn btn-primary">
          🔄 Refresh Status
        </button>
      </div>

      <!-- Status Panel -->
      <div class="status-panel">
        <h3>📊 Status</h3>
        <div v-if="phoneStatus" class="status-grid">
          <div class="status-item">
            <span class="label">IP Address:</span>
            <span class="value">{{ phoneStatus.ip }}</span>
          </div>
          <div class="status-item">
            <span class="label">Model:</span>
            <span class="value">{{ phoneStatus.model || 'Unknown' }}</span>
          </div>
          <div class="status-item">
            <span class="label">Firmware:</span>
            <span class="value">{{ phoneStatus.firmware || 'Unknown' }}</span>
          </div>
          <div class="status-item">
            <span class="label">MAC:</span>
            <span class="value">{{ phoneStatus.mac || 'Unknown' }}</span>
          </div>
          <div class="status-item">
            <span class="label">Uptime:</span>
            <span class="value">{{ phoneStatus.uptime || 'Unknown' }}</span>
          </div>
          <div class="status-item">
            <span class="label">Status:</span>
            <span :class="['value', 'status-' + phoneStatus.status]">
              {{ phoneStatus.status }}
            </span>
          </div>
        </div>
      </div>

      <!-- Control Panel -->
      <div class="control-panel">
        <h3>🎛️ Control</h3>
        <div class="control-buttons">
          <button @click="performAction('reboot')" class="btn btn-warning">
            🔄 Reboot
          </button>
          <button @click="performAction('factory_reset')" class="btn btn-danger">
            🏭 Factory Reset
          </button>
          <button @click="performAction('get_config')" class="btn btn-info">
            📋 Get Config
          </button>
          <button @click="showProvisionModal = true" class="btn btn-success">
            🔧 Provision
          </button>
        </div>
      </div>

      <!-- CTI/CSTA Control Panel - Beautiful Design -->
      <div class="cti-panel">
        <div class="panel-header-section">
          <h3>📞 CTI/CSTA Controls</h3>
          <div class="cti-status-indicators">
            <span :class="['indicator', ctiStatus.cti_working ? 'active' : 'inactive']">
              {{ ctiStatus.cti_working ? '✅ CTI Active' : '❌ CTI Inactive' }}
            </span>
            <span :class="['indicator', ctiStatus.snmp_enabled ? 'active' : 'inactive']">
              {{ ctiStatus.snmp_enabled ? '📊 SNMP On' : '📊 SNMP Off' }}
            </span>
          </div>
        </div>

        <!-- Real-time Phone State -->
        <div v-if="phoneState" class="phone-state-panel">
          <div class="state-grid">
            <div class="state-card">
              <div class="state-icon">📱</div>
              <div class="state-info">
                <span class="state-label">Active Line</span>
                <span class="state-value">{{ phoneState.active_line || 1 }}</span>
              </div>
            </div>
            <div :class="['state-card', phoneState.dnd_enabled ? 'alert' : '']">
              <div class="state-icon">🚫</div>
              <div class="state-info">
                <span class="state-label">DND</span>
                <span class="state-value">{{ phoneState.dnd_enabled ? 'Enabled' : 'Disabled' }}</span>
              </div>
            </div>
            <div :class="['state-card', phoneState.forward_enabled ? 'warning' : '']">
              <div class="state-icon">↗️</div>
              <div class="state-info">
                <span class="state-label">Forward</span>
                <span class="state-value">{{ phoneState.forward_enabled ? phoneState.forward_target || 'Enabled' : 'Disabled' }}</span>
              </div>
            </div>
            <div :class="['state-card', phoneState.mwi ? 'info' : '']">
              <div class="state-icon">📧</div>
              <div class="state-info">
                <span class="state-label">Voicemail</span>
                <span class="state-value">{{ phoneState.mwi ? 'Messages Waiting' : 'No Messages' }}</span>
              </div>
            </div>
          </div>
        </div>

        <!-- Call Control Section -->
        <div class="cti-section">
          <h4>📞 Call Control</h4>
          <div class="cti-button-grid">
            <button @click="executeCTI('accept_call')" class="cti-btn success">
              <span class="btn-icon">✅</span>
              <span class="btn-label">Accept</span>
            </button>
            <button @click="executeCTI('reject_call')" class="cti-btn danger">
              <span class="btn-icon">❌</span>
              <span class="btn-label">Reject</span>
            </button>
            <button @click="executeCTI('end_call')" class="cti-btn danger">
              <span class="btn-icon">🔚</span>
              <span class="btn-label">End Call</span>
            </button>
            <button @click="executeCTI('hold')" class="cti-btn warning">
              <span class="btn-icon">⏸️</span>
              <span class="btn-label">Hold</span>
            </button>
            <button @click="executeCTI('unhold')" class="cti-btn primary">
              <span class="btn-icon">▶️</span>
              <span class="btn-label">Resume</span>
            </button>
            <button @click="executeCTI('mute')" class="cti-btn secondary">
              <span class="btn-icon">🔇</span>
              <span class="btn-label">Mute</span>
            </button>
            <button @click="executeCTI('unmute')" class="cti-btn secondary">
              <span class="btn-icon">🔊</span>
              <span class="btn-label">Unmute</span>
            </button>
            <button @click="executeCTI('redial')" class="cti-btn info">
              <span class="btn-icon">🔁</span>
              <span class="btn-label">Redial</span>
            </button>
          </div>
        </div>

        <!-- Dial Pad Section -->
        <div class="cti-section">
          <h4>📲 Dial</h4>
          <div class="dial-section">
            <div class="dial-input-group">
              <input 
                v-model="dialNumber" 
                type="text" 
                placeholder="Enter number to dial" 
                class="dial-input"
                @keyup.enter="executeCTI('dial', { number: dialNumber })"
              />
              <button @click="executeCTI('dial', { number: dialNumber })" class="cti-btn success dial-btn">
                📞 Dial
              </button>
            </div>
            <div class="dial-input-group">
              <input 
                v-model="dtmfDigits" 
                type="text" 
                placeholder="DTMF digits" 
                class="dial-input small"
              />
              <button @click="executeCTI('dtmf', { digits: dtmfDigits })" class="cti-btn info dial-btn">
                🔢 Send DTMF
              </button>
            </div>
          </div>
        </div>

        <!-- Transfer Section -->
        <div class="cti-section">
          <h4>↗️ Transfer</h4>
          <div class="transfer-section">
            <input 
              v-model="transferTarget" 
              type="text" 
              placeholder="Transfer destination" 
              class="dial-input"
            />
            <div class="transfer-buttons">
              <button @click="executeCTI('blind_transfer', { target: transferTarget })" class="cti-btn primary">
                ↗️ Blind Transfer
              </button>
              <button @click="executeCTI('attended_transfer', { target: transferTarget })" class="cti-btn info">
                👥 Attended Transfer
              </button>
              <button @click="executeCTI('conference')" class="cti-btn success">
                🎙️ Conference
              </button>
            </div>
          </div>
        </div>

        <!-- Features Section -->
        <div class="cti-section">
          <h4>⚙️ Features</h4>
          <div class="feature-buttons">
            <button @click="toggleDND()" :class="['cti-btn', phoneState?.dnd_enabled ? 'danger' : 'secondary']">
              🚫 {{ phoneState?.dnd_enabled ? 'Disable DND' : 'Enable DND' }}
            </button>
            <button @click="showForwardModal = true" class="cti-btn info">
              ↗️ Call Forward
            </button>
            <button @click="showLCDModal = true" class="cti-btn warning">
              📺 LCD Message
            </button>
            <button @click="testCTI()" class="cti-btn secondary">
              🧪 Test CTI
            </button>
          </div>
        </div>

        <!-- CTI Setup Section -->
        <div class="cti-section">
          <h4>🔧 CTI Setup</h4>
          <div class="setup-buttons">
            <button @click="enableCTI()" class="cti-btn success">
              ✅ Enable CTI
            </button>
            <button @click="enableSNMP()" class="cti-btn info">
              📊 Enable SNMP
            </button>
            <button @click="provisionCTIFeatures()" class="cti-btn primary">
              🚀 Provision All
            </button>
            <button @click="refreshCTIStatus()" class="cti-btn secondary">
              🔄 Refresh Status
            </button>
          </div>
        </div>
      </div>

      <!-- Action URLs Panel -->
      <div class="action-urls-panel">
        <h3>📡 Action URLs</h3>
        <div class="action-urls-actions">
          <button @click="checkActionUrls" class="btn btn-info">
            🔍 Check Status
          </button>
          <button @click="updateActionUrls(false)" class="btn btn-primary">
            🔄 Update
          </button>
        </div>
        <div v-if="actionUrlStatus" class="action-urls-status">
          <div class="action-urls-summary">
            <span :class="['status-badge', actionUrlStatus.needs_update ? 'warning' : 'success']">
              {{ actionUrlStatus.needs_update ? 'Needs Update' : 'Configured' }}
            </span>
            <span v-if="actionUrlStatus.has_conflicts" class="status-badge danger">
              ⚠️ Has Conflicts
            </span>
          </div>
          <div class="action-urls-details">
            <p>Total: {{ actionUrlStatus.summary?.total || 0 }}</p>
            <p>Matching: {{ actionUrlStatus.summary?.matching || 0 }}</p>
            <p>Conflicts: {{ actionUrlStatus.summary?.conflicts || 0 }}</p>
          </div>
        </div>
      </div>

      <!-- Configuration Panel -->
      <div v-if="phoneConfig" class="config-panel">
        <h3>⚙️ Configuration</h3>
        <pre class="config-content">{{ JSON.stringify(phoneConfig, null, 2) }}</pre>
      </div>

      <!-- LCD Message Modal -->
      <div v-if="showLCDModal" class="modal-overlay">
        <div class="modal-content">
          <h3>📺 Display LCD Message</h3>
          <input
            v-model="lcdMessage"
            type="text"
            placeholder="Message to display"
            class="input"
            maxlength="128"
          />
          <input
            v-model.number="lcdDuration"
            type="number"
            placeholder="Duration (seconds)"
            class="input"
            min="1"
            max="300"
          />
          <div class="modal-actions">
            <button @click="sendLCDMessage" class="btn btn-primary">
              📺 Send Message
            </button>
            <button @click="showLCDModal = false" class="btn btn-secondary">
              Cancel
            </button>
          </div>
        </div>
      </div>

      <!-- Forward Modal -->
      <div v-if="showForwardModal" class="modal-overlay">
        <div class="modal-content">
          <h3>↗️ Configure Call Forwarding</h3>
          <select v-model="forwardType" class="input">
            <option value="unconditional">Unconditional</option>
            <option value="busy">When Busy</option>
            <option value="noanswer">No Answer</option>
          </select>
          <input
            v-model="forwardTarget"
            type="text"
            placeholder="Forward destination"
            class="input"
          />
          <div class="modal-actions">
            <button @click="setForward(true)" class="btn btn-success">
              ✅ Enable Forward
            </button>
            <button @click="setForward(false)" class="btn btn-danger">
              ❌ Disable Forward
            </button>
            <button @click="showForwardModal = false" class="btn btn-secondary">
              Cancel
            </button>
          </div>
        </div>
      </div>

      <!-- Credentials Input -->
      <div v-if="needsCredentials" class="credentials-modal">
        <div class="modal-content">
          <h3>Enter Phone Credentials</h3>
          <input
            v-model="credentials.username"
            type="text"
            placeholder="Username (default: admin)"
            class="input"
          />
          <input
            v-model="credentials.password"
            type="password"
            placeholder="Password"
            class="input"
          />
          <div class="modal-actions">
            <button @click="submitCredentials" class="btn btn-primary">
              Submit
            </button>
            <button @click="needsCredentials = false" class="btn btn-secondary">
              Cancel
            </button>
          </div>
        </div>
      </div>

      <!-- Action URL Confirmation Modal -->
      <div v-if="showActionUrlConfirmModal" class="action-url-confirm-modal">
        <div class="modal-content">
          <h3>⚠️ Confirm Action URL Update</h3>
          <p v-if="provisionContext">
            The extension was provisioned successfully, but the phone has existing Action URL configuration that differs from RayanPBX values.
          </p>
          <p v-else>
            The phone has existing Action URL configuration that differs from RayanPBX values.
          </p>
          <div v-if="actionUrlConflicts" class="conflicts-list">
            <h4>Conflicts:</h4>
            <div v-for="(conflict, event) in actionUrlConflicts" :key="event" class="conflict-item">
              <strong>{{ event }}</strong>
              <div class="conflict-values">
                <div class="current">Current: {{ conflict.current || '(empty)' }}</div>
                <div class="expected">Expected: {{ conflict.expected }}</div>
              </div>
            </div>
          </div>
          <div class="modal-actions">
            <button @click="forceUpdateActionUrls" class="btn btn-danger">
              Force Update Action URLs
            </button>
            <button @click="cancelActionUrlUpdate" class="btn btn-secondary">
              Cancel
            </button>
          </div>
        </div>
      </div>

      <!-- Provision Modal -->
      <div v-if="showProvisionModal" class="provision-modal">
        <div class="modal-content">
          <h3>Provision Extension</h3>
          <select v-model="selectedExtension" class="input">
            <option value="">Select Extension</option>
            <option v-for="ext in extensions" :key="ext.id" :value="ext.id">
              {{ ext.extension_number }} - {{ ext.name }}
            </option>
          </select>
          <input
            v-model="accountNumber"
            type="number"
            min="1"
            max="6"
            placeholder="Account Number (1-6)"
            class="input"
          />
          <div class="checkbox-group">
            <label>
              <input type="checkbox" v-model="includeActionUrls" />
              Configure Action URLs
            </label>
          </div>
          <div class="modal-actions">
            <button @click="provisionExtension" class="btn btn-primary">
              Provision
            </button>
            <button @click="showProvisionModal = false" class="btn btn-secondary">
              Cancel
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Toast Notification -->
    <div v-if="notification" :class="['notification', notification.type]">
      {{ notification.message }}
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'

definePageMeta({
  middleware: 'auth'
})

const api = useApi()
const authStore = useAuthStore()
const router = useRouter()

const phones = ref([])
const selectedPhone = ref(null)
const phoneStatus = ref(null)
const phoneConfig = ref(null)
const loading = ref(false)
const needsCredentials = ref(false)
const showProvisionModal = ref(false)
const showActionUrlConfirmModal = ref(false)
const extensions = ref([])
const selectedExtension = ref('')
const accountNumber = ref(1)
const includeActionUrls = ref(true)
const notification = ref(null)
const actionUrlStatus = ref(null)
const actionUrlConflicts = ref(null)

// Discovery state
const showDiscoveryPanel = ref(false)
const discoveryTab = ref('all')
const discoveryLoading = ref(false)
const lldpNeighbors = ref([])
const arpNeighbors = ref([])
const discoveredDevices = ref([])

// CTI/CSTA state
const phoneState = ref(null)
const ctiStatus = ref({ cti_working: false, snmp_enabled: false })
const dialNumber = ref('')
const dtmfDigits = ref('')
const transferTarget = ref('')
const showLCDModal = ref(false)
const showForwardModal = ref(false)
const lcdMessage = ref('')
const lcdDuration = ref(10)
const forwardType = ref('unconditional')
const forwardTarget = ref('')
let ctiRefreshInterval = null

// Computed property for current discovery list based on tab
const currentDiscoveryList = computed(() => {
  switch (discoveryTab.value) {
    case 'lldp':
      return lldpNeighbors.value
    case 'arp':
      return arpNeighbors.value
    default:
      return discoveredDevices.value
  }
})

// Store provision context for re-provisioning with force flag
const provisionContext = ref(null)

const credentials = ref({
  username: 'admin',
  password: ''
})

onMounted(async () => {
  await authStore.checkAuth()
  if (!authStore.isAuthenticated) {
    router.push('/login')
    return
  }
  refreshPhones()
  loadExtensions()
})

onUnmounted(() => {
  // Clean up interval when leaving page
  if (ctiRefreshInterval) {
    clearInterval(ctiRefreshInterval)
  }
})

async function discoverAllPhones() {
  showDiscoveryPanel.value = true
  discoveryLoading.value = true
  discoveryTab.value = 'all'
  
  try {
    const data = await api.discoverPhones()
    if (data.success) {
      discoveredDevices.value = data.devices || []
      // Also separate by type
      lldpNeighbors.value = data.devices?.filter(d => d.discovery_type === 'lldp') || []
      arpNeighbors.value = data.devices?.filter(d => d.discovery_type === 'arp') || []
      
      if (data.devices && data.devices.length > 0) {
        showNotification(`Found ${data.devices.length} device(s)`, 'success')
      } else {
        showNotification(data.message || 'No devices found', 'info')
      }
    } else {
      showNotification(data.error || 'Discovery failed', 'error')
    }
  } catch (error) {
    showNotification('Discovery failed', 'error')
  } finally {
    discoveryLoading.value = false
  }
}

async function discoverLldpNeighbors() {
  showDiscoveryPanel.value = true
  discoveryLoading.value = true
  discoveryTab.value = 'lldp'
  
  try {
    const data = await api.getLldpNeighbors()
    if (data.success) {
      lldpNeighbors.value = data.neighbors || []
      if (data.neighbors && data.neighbors.length > 0) {
        showNotification(`Found ${data.neighbors.length} LLDP neighbor(s)`, 'success')
      } else {
        showNotification(data.message || 'No LLDP neighbors found', 'info')
      }
    } else {
      showNotification(data.error || 'LLDP discovery failed', 'error')
    }
  } catch (error) {
    showNotification('LLDP discovery failed', 'error')
  } finally {
    discoveryLoading.value = false
  }
}

async function discoverArpNeighbors() {
  showDiscoveryPanel.value = true
  discoveryLoading.value = true
  discoveryTab.value = 'arp'
  
  try {
    const data = await api.getArpNeighbors()
    if (data.success) {
      arpNeighbors.value = data.neighbors || []
      if (data.neighbors && data.neighbors.length > 0) {
        showNotification(`Found ${data.neighbors.length} ARP neighbor(s)`, 'success')
      } else {
        showNotification(data.message || 'No ARP neighbors found', 'info')
      }
    } else {
      showNotification(data.error || 'ARP discovery failed', 'error')
    }
  } catch (error) {
    showNotification('ARP discovery failed', 'error')
  } finally {
    discoveryLoading.value = false
  }
}

function addDiscoveredDeviceToPhones(device) {
  // Generate a unique identifier for the phone
  let extension = device.hostname
  if (!extension) {
    const macSuffix = device.mac?.replace(/:/g, '').slice(-6) || ''
    const timestamp = Date.now().toString(36).slice(-4)
    const prefix = device.discovery_type?.toUpperCase() || 'DISC'
    extension = macSuffix ? `${prefix}-${macSuffix}-${timestamp}` : `${prefix}-${timestamp}`
  }
  
  // Add discovered device to phones list
  const newPhone = {
    extension: extension,
    ip: device.ip,
    status: 'discovered',
    user_agent: `${device.vendor || 'Unknown'} ${device.model || ''}`.trim(),
    mac: device.mac,
    discovery_type: device.discovery_type,
  }
  
  // Check if already in list by IP or MAC
  const exists = phones.value.some(p => 
    (device.ip && p.ip === device.ip) || 
    (device.mac && p.mac === device.mac)
  )
  
  if (!exists) {
    phones.value.push(newPhone)
    showNotification(`Added ${device.model || device.ip} to phones list`, 'success')
  } else {
    showNotification('Phone already in list', 'info')
  }
}

// Keep addLldpNeighborToPhones for backward compatibility
function addLldpNeighborToPhones(neighbor) {
  addDiscoveredDeviceToPhones(neighbor)
}

async function refreshPhones() {
  loading.value = true
  try {
    const data = await api.getPhones()
    if (data.success) {
      phones.value = data.phones || []
    }
  } catch (error) {
    showNotification('Failed to load phones', 'error')
  } finally {
    loading.value = false
  }
}

async function selectPhone(phone) {
  selectedPhone.value = phone
  await refreshPhoneStatus()
  await refreshCTIStatus()
  
  // Start real-time status polling
  if (ctiRefreshInterval) {
    clearInterval(ctiRefreshInterval)
  }
  ctiRefreshInterval = setInterval(async () => {
    if (selectedPhone.value) {
      await refreshCTIStatus()
    }
  }, 5000) // Poll every 5 seconds
}

// CTI/CSTA Functions
async function refreshCTIStatus() {
  if (!selectedPhone.value) return
  
  try {
    const data = await api.getCTIStatus(selectedPhone.value.ip, credentials.value)
    if (data.success) {
      phoneState.value = data.data || {}
    }
    
    // Also test CTI status
    const testData = await api.testCTIFeatures(selectedPhone.value.ip, credentials.value)
    if (testData.success) {
      ctiStatus.value = {
        cti_working: testData.cti_working || testData.results?.cti || false,
        snmp_enabled: testData.snmp_enabled || testData.results?.snmp || false
      }
    }
  } catch (error) {
    console.error('Failed to get CTI status:', error?.message || 'Unknown error')
  }
}

async function executeCTI(operation, params = {}) {
  if (!selectedPhone.value) return
  
  try {
    const data = await api.executeCTIOperation(
      selectedPhone.value.ip,
      operation,
      params,
      credentials.value
    )
    
    if (data.success) {
      showNotification(`${operation.replace('_', ' ')} executed successfully`, 'success')
      await refreshCTIStatus()
    } else {
      showNotification(data.error || `Failed to execute ${operation}`, 'error')
    }
  } catch (error) {
    showNotification(`Failed to execute ${operation}`, 'error')
  }
}

async function toggleDND() {
  if (!selectedPhone.value) return
  const enable = !phoneState.value?.dnd_enabled
  await executeCTI('dnd', { value: enable ? '1' : '0' })
}

async function setForward(enable) {
  if (!selectedPhone.value) return
  
  await executeCTI('forward', {
    value: enable ? '1' : '0',
    target: forwardTarget.value,
    forward_type: forwardType.value
  })
  
  showForwardModal.value = false
}

async function sendLCDMessage() {
  if (!selectedPhone.value || !lcdMessage.value) return
  
  try {
    const data = await api.displayLCDMessage(
      selectedPhone.value.ip,
      lcdMessage.value,
      lcdDuration.value,
      credentials.value
    )
    
    if (data.success) {
      showNotification('Message sent to phone display', 'success')
      showLCDModal.value = false
      lcdMessage.value = ''
    } else {
      showNotification(data.error || 'Failed to send message', 'error')
    }
  } catch (error) {
    showNotification('Failed to send LCD message', 'error')
  }
}

async function enableCTI() {
  if (!selectedPhone.value) return
  
  try {
    const data = await api.enableCTI(selectedPhone.value.ip, credentials.value)
    if (data.success) {
      showNotification('CTI enabled successfully', 'success')
      await refreshCTIStatus()
    } else {
      showNotification(data.error || 'Failed to enable CTI', 'error')
    }
  } catch (error) {
    showNotification('Failed to enable CTI', 'error')
  }
}

async function enableSNMP() {
  if (!selectedPhone.value) return
  
  try {
    const snmpConfig = { community: 'public', version: 'v2c' }
    const data = await api.enableSNMP(selectedPhone.value.ip, snmpConfig, credentials.value)
    if (data.success) {
      showNotification('SNMP enabled successfully', 'success')
      await refreshCTIStatus()
    } else {
      showNotification(data.error || 'Failed to enable SNMP', 'error')
    }
  } catch (error) {
    showNotification('Failed to enable SNMP', 'error')
  }
}

async function provisionCTIFeatures() {
  if (!selectedPhone.value) return
  
  try {
    const snmpConfig = { community: 'public', version: 'v2c' }
    const data = await api.provisionCTIFeatures(
      selectedPhone.value.ip,
      true, // enable CTI
      true, // enable SNMP
      snmpConfig,
      credentials.value
    )
    if (data.success) {
      showNotification('CTI and SNMP features provisioned successfully', 'success')
      await refreshCTIStatus()
    } else {
      showNotification(data.error || 'Failed to provision CTI features', 'error')
    }
  } catch (error) {
    showNotification('Failed to provision CTI features', 'error')
  }
}

async function testCTI() {
  if (!selectedPhone.value) return
  
  try {
    const data = await api.testCTIFeatures(selectedPhone.value.ip, credentials.value)
    if (data.success) {
      const ctiOK = data.cti_working || data.results?.cti
      const snmpOK = data.snmp_enabled || data.results?.snmp
      showNotification(
        `CTI: ${ctiOK ? '✅ Working' : '❌ Not working'} | SNMP: ${snmpOK ? '✅ Enabled' : '❌ Disabled'}`,
        ctiOK ? 'success' : 'warning'
      )
      ctiStatus.value = { cti_working: ctiOK, snmp_enabled: snmpOK }
    } else {
      showNotification(data.error || 'CTI test failed', 'error')
    }
  } catch (error) {
    showNotification('CTI test failed', 'error')
  }
}

async function refreshPhoneStatus() {
  if (!selectedPhone.value) return
  
  try {
    const data = await api.controlPhone(
      selectedPhone.value.ip,
      'get_status',
      credentials.value
    )
    if (data.success !== false) {
      phoneStatus.value = data
    } else if (data.error && data.error.includes('401')) {
      needsCredentials.value = true
    }
  } catch (error) {
    // Only log error message to avoid exposing sensitive data
    console.error('Failed to get phone status:', error?.message || 'Unknown error')
  }
}

async function performAction(action) {
  if (!selectedPhone.value) return

  const confirmActions = ['factory_reset', 'reboot']
  if (confirmActions.includes(action)) {
    const actionName = action === 'factory_reset' ? 'Factory Reset' : 'Reboot'
    if (!confirm(`Are you sure you want to ${actionName} this phone? This action cannot be undone.`)) {
      return
    }
  }

  try {
    const confirmDestructive = action === 'factory_reset'
    const data = await api.controlPhone(
      selectedPhone.value.ip,
      action,
      credentials.value,
      {},
      confirmDestructive
    )
    
    if (data.success) {
      if (action === 'get_config') {
        phoneConfig.value = data.config
      }
      showNotification(`Action ${action} completed successfully`, 'success')
    } else {
      if (data.error && data.error.includes('401')) {
        needsCredentials.value = true
      } else {
        showNotification(data.error || data.message || 'Action failed', 'error')
      }
    }
  } catch (error) {
    showNotification('Action failed', 'error')
  }
}

async function submitCredentials() {
  needsCredentials.value = false
  await refreshPhoneStatus()
}

async function loadExtensions() {
  try {
    const response = await api.getExtensions()
    extensions.value = response.extensions || response || []
  } catch (error) {
    // Only log error message to avoid exposing sensitive data
    console.error('Failed to load extensions:', error?.message || 'Unknown error')
  }
}

async function provisionExtension(forceActionUrls = false) {
  if (!selectedExtension.value) {
    showNotification('Please select an extension', 'error')
    return
  }

  try {
    // Store provision context for potential re-provisioning with force flag
    provisionContext.value = {
      ip: selectedPhone.value.ip,
      extension_id: selectedExtension.value,
      account_number: accountNumber.value,
      credentials: { ...credentials.value },
      include_action_urls: includeActionUrls.value
    }

    let data
    if (includeActionUrls.value) {
      data = await api.provisionPhoneComplete(
        selectedPhone.value.ip,
        selectedExtension.value,
        accountNumber.value,
        credentials.value,
        forceActionUrls
      )
    } else {
      data = await api.provisionPhone(
        selectedPhone.value.ip,
        selectedExtension.value,
        accountNumber.value,
        credentials.value
      )
    }
    
    if (data.action_urls_result?.requires_confirmation) {
      // Action URLs have conflicts - show confirmation modal
      actionUrlConflicts.value = data.action_urls_result.conflicts
      showActionUrlConfirmModal.value = true
      showProvisionModal.value = false
      
      const message = data.extension_provisioned 
        ? 'Extension provisioned successfully. Action URLs have conflicts that need confirmation.'
        : 'Action URLs have conflicts that need confirmation.'
      showNotification(message, 'warning')
    } else if (data.success) {
      provisionContext.value = null // Clear context on success
      showNotification('Extension provisioned successfully', 'success')
      showProvisionModal.value = false
      showActionUrlConfirmModal.value = false
    } else {
      const errorMessage = data.error || data.message || 'Provisioning failed'
      showNotification(errorMessage, 'error')
    }
  } catch (error) {
    // Only log error message, not the full error object to avoid exposing sensitive data
    console.error('Provisioning error:', error?.message || 'Network or server error')
    showNotification('Provisioning failed: Network or server error', 'error')
  }
}

// Handle Force Update from the conflict modal
async function forceUpdateActionUrls() {
  // If we have provision context (came from provisioning flow), re-provision with force flag
  if (provisionContext.value && provisionContext.value.include_action_urls) {
    await provisionExtension(true)
  } else {
    // Otherwise, just update Action URLs directly
    await updateActionUrls(true)
  }
}

async function checkActionUrls() {
  if (!selectedPhone.value) return
  
  try {
    const data = await api.checkPhoneActionUrls(
      selectedPhone.value.ip,
      credentials.value
    )
    
    if (data.success) {
      actionUrlStatus.value = data
      showNotification('Action URL status retrieved', 'success')
    } else {
      showNotification(data.error || 'Failed to check Action URLs', 'error')
    }
  } catch (error) {
    showNotification('Failed to check Action URLs', 'error')
  }
}

async function updateActionUrls(force = false) {
  if (!selectedPhone.value) return
  
  try {
    const data = await api.updatePhoneActionUrls(
      selectedPhone.value.ip,
      credentials.value,
      force
    )
    
    if (data.requires_confirmation) {
      // Conflicts found - show confirmation modal (without provision context)
      provisionContext.value = null
      actionUrlConflicts.value = data.conflicts
      showActionUrlConfirmModal.value = true
      showNotification('Action URL conflicts found - confirmation required', 'warning')
    } else if (data.success) {
      provisionContext.value = null
      showActionUrlConfirmModal.value = false
      showNotification(data.message || 'Action URLs updated successfully', 'success')
      // Refresh status
      await checkActionUrls()
    } else {
      const errorMessage = data.error || data.message || 'Failed to update Action URLs'
      showNotification(errorMessage, 'error')
    }
  } catch (error) {
    // Only log error message to avoid exposing sensitive data
    console.error('Update Action URLs error:', error?.message || 'Network or server error')
    showNotification('Failed to update Action URLs: Network or server error', 'error')
  }
}

// Cancel action URL update and clear context
function cancelActionUrlUpdate() {
  showActionUrlConfirmModal.value = false
  provisionContext.value = null
  actionUrlConflicts.value = null
}

function showNotification(message, type = 'info') {
  notification.value = { message, type }
  setTimeout(() => {
    notification.value = null
  }, 3000)
}
</script>

<style scoped>
.phones-container {
  padding: 20px;
  max-width: 1400px;
  margin: 0 auto;
}

.header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 30px;
}

.header-title {
  display: flex;
  align-items: center;
  gap: 15px;
}

.back-link {
  color: #7D56F4;
  text-decoration: none;
  font-size: 14px;
  padding: 6px 12px;
  border: 1px solid #7D56F4;
  border-radius: 4px;
  transition: all 0.2s;
}

.back-link:hover {
  background: #7D56F4;
  color: white;
}

.header h1 {
  font-size: 28px;
  font-weight: bold;
}

.header-actions {
  display: flex;
  gap: 10px;
}

.btn {
  padding: 10px 20px;
  border: none;
  border-radius: 6px;
  cursor: pointer;
  font-size: 14px;
  font-weight: 500;
  transition: all 0.2s;
}

.btn-primary {
  background: #7D56F4;
  color: white;
}

.btn-primary:hover {
  background: #6b48d9;
}

.btn-secondary {
  background: #6c757d;
  color: white;
}

.btn-warning {
  background: #ffc107;
  color: black;
}

.btn-danger {
  background: #dc3545;
  color: white;
}

.btn-info {
  background: #17a2b8;
  color: white;
}

.btn-success {
  background: #28a745;
  color: white;
}

.btn-back {
  background: transparent;
  color: #7D56F4;
  border: 1px solid #7D56F4;
}

.loading {
  text-align: center;
  padding: 40px;
  font-size: 18px;
  color: #666;
}

.empty-state {
  text-align: center;
  padding: 60px 20px;
}

.empty-state p {
  font-size: 18px;
  margin: 10px 0;
}

.help-text {
  color: #666;
  font-size: 14px;
}

.phone-cards {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
  gap: 20px;
}

.phone-card {
  border: 1px solid #ddd;
  border-radius: 8px;
  padding: 20px;
  display: flex;
  align-items: center;
  gap: 15px;
  cursor: pointer;
  transition: all 0.2s;
}

.phone-card:hover {
  border-color: #7D56F4;
  box-shadow: 0 4px 12px rgba(125, 86, 244, 0.1);
  transform: translateY(-2px);
}

.phone-icon {
  font-size: 32px;
}

.phone-info {
  flex: 1;
}

.phone-info h3 {
  margin: 0 0 8px 0;
  font-size: 18px;
}

.phone-ip, .phone-model {
  margin: 4px 0;
  font-size: 14px;
  color: #666;
}

.status-badge {
  padding: 4px 12px;
  border-radius: 12px;
  font-size: 12px;
  font-weight: 500;
}

.status-badge.online {
  background: #d4edda;
  color: #155724;
}

.status-badge.offline {
  background: #f8d7da;
  color: #721c24;
}

.phone-details {
  background: white;
  border-radius: 8px;
  padding: 30px;
}

.details-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 30px;
  padding-bottom: 20px;
  border-bottom: 1px solid #eee;
}

.status-panel, .control-panel, .config-panel {
  margin-bottom: 30px;
  padding: 20px;
  background: #f8f9fa;
  border-radius: 8px;
}

.status-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
  gap: 15px;
  margin-top: 15px;
}

.status-item {
  display: flex;
  justify-content: space-between;
}

.status-item .label {
  font-weight: 500;
  color: #666;
}

.status-item .value {
  font-weight: 600;
}

.control-buttons {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
  margin-top: 15px;
}

.config-content {
  background: #fff;
  padding: 15px;
  border-radius: 4px;
  overflow-x: auto;
  font-size: 12px;
  margin-top: 15px;
}

.credentials-modal, .provision-modal, .action-url-confirm-modal {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: rgba(0, 0, 0, 0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
}

.modal-content {
  background: white;
  padding: 30px;
  border-radius: 8px;
  min-width: 400px;
  max-width: 90%;
  max-height: 80vh;
  overflow-y: auto;
}

.modal-content h3 {
  margin: 0 0 20px 0;
}

.input {
  width: 100%;
  padding: 10px;
  border: 1px solid #ddd;
  border-radius: 4px;
  margin-bottom: 15px;
  font-size: 14px;
}

.checkbox-group {
  margin-bottom: 15px;
}

.checkbox-group label {
  display: flex;
  align-items: center;
  gap: 8px;
  cursor: pointer;
}

.modal-actions {
  display: flex;
  gap: 10px;
  justify-content: flex-end;
  margin-top: 20px;
}

.action-urls-panel {
  margin-bottom: 30px;
  padding: 20px;
  background: #f8f9fa;
  border-radius: 8px;
}

.action-urls-actions {
  display: flex;
  gap: 10px;
  margin-top: 15px;
}

.action-urls-status {
  margin-top: 15px;
  padding: 15px;
  background: white;
  border-radius: 4px;
}

.action-urls-summary {
  display: flex;
  gap: 10px;
  margin-bottom: 15px;
}

.action-urls-details {
  display: flex;
  gap: 20px;
  font-size: 14px;
  color: #666;
}

.status-badge.success {
  background: #d4edda;
  color: #155724;
}

.status-badge.warning {
  background: #fff3cd;
  color: #856404;
}

.status-badge.danger {
  background: #f8d7da;
  color: #721c24;
}

.conflicts-list {
  margin: 15px 0;
  max-height: 300px;
  overflow-y: auto;
}

.conflict-item {
  padding: 10px;
  margin-bottom: 10px;
  background: #fff3cd;
  border-radius: 4px;
  border: 1px solid #ffc107;
}

.conflict-item strong {
  display: block;
  margin-bottom: 8px;
  color: #856404;
}

.conflict-values {
  font-size: 12px;
}

.conflict-values .current {
  color: #dc3545;
  word-break: break-all;
}

.conflict-values .expected {
  color: #28a745;
  word-break: break-all;
}

.notification {
  position: fixed;
  top: 20px;
  right: 20px;
  padding: 15px 25px;
  border-radius: 6px;
  font-weight: 500;
  z-index: 2000;
  animation: slideIn 0.3s ease-out;
}

.notification.success {
  background: #d4edda;
  color: #155724;
  border: 1px solid #c3e6cb;
}

.notification.error {
  background: #f8d7da;
  color: #721c24;
  border: 1px solid #f5c6cb;
}

.notification.info {
  background: #d1ecf1;
  color: #0c5460;
  border: 1px solid #bee5eb;
}

.notification.warning {
  background: #fff3cd;
  color: #856404;
  border: 1px solid #ffc107;
}

@keyframes slideIn {
  from {
    transform: translateX(100%);
    opacity: 0;
  }
  to {
    transform: translateX(0);
    opacity: 1;
  }
}

/* LLDP Panel Styles */
.lldp-panel,
.discovery-panel {
  background: var(--panel-bg, white);
  border-radius: 8px;
  padding: 20px;
  margin-bottom: 20px;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.panel-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 20px;
  padding-bottom: 15px;
  border-bottom: 1px solid var(--border-color, #eee);
  gap: 15px;
}

.panel-tabs {
  display: flex;
  gap: 8px;
  flex: 1;
  justify-content: center;
}

.tab-btn {
  padding: 6px 12px;
  border: 1px solid var(--border-color, #ddd);
  border-radius: 4px;
  background: var(--tab-bg, #f5f5f5);
  color: var(--text-color, inherit);
  cursor: pointer;
  font-size: 13px;
  transition: all 0.2s;
}

.tab-btn:hover {
  background: var(--tab-hover-bg, #e0e0e0);
}

.tab-btn.active {
  background: #3b82f6;
  color: white;
  border-color: #3b82f6;
}

.discovery-type-badge {
  display: inline-block;
  padding: 2px 6px;
  border-radius: 4px;
  font-size: 10px;
  font-weight: bold;
  background: var(--badge-bg, #e0e7ff);
  color: var(--badge-color, #3730a3);
  margin-top: 4px;
}

.panel-header h3 {
  margin: 0;
  font-size: 18px;
  color: var(--text-color, inherit);
}

.btn-close {
  background: transparent;
  border: 1px solid var(--border-color, #ddd);
  border-radius: 4px;
  padding: 4px 10px;
  cursor: pointer;
  font-size: 16px;
  color: var(--text-color, inherit);
}

.btn-close:hover {
  background: var(--hover-bg, #f0f0f0);
}

.neighbor-cards {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
  gap: 15px;
}

.neighbor-card {
  border: 1px solid var(--border-color, #ddd);
  border-radius: 8px;
  padding: 15px;
  display: flex;
  gap: 15px;
  background: var(--card-bg, #fafafa);
}

.neighbor-icon {
  font-size: 28px;
}

.neighbor-info {
  flex: 1;
}

.neighbor-info h4 {
  margin: 0 0 8px 0;
  font-size: 16px;
  color: var(--text-primary, #333);
}

.neighbor-ip,
.neighbor-mac,
.neighbor-vendor {
  margin: 4px 0;
  font-size: 13px;
  color: var(--text-muted, #666);
}

.neighbor-capabilities {
  margin-top: 8px;
  display: flex;
  gap: 5px;
  flex-wrap: wrap;
}

.capability-badge {
  background: var(--capability-bg, #e3f2fd);
  color: var(--capability-color, #1976d2);
  padding: 2px 8px;
  border-radius: 12px;
  font-size: 11px;
}

.neighbor-actions {
  display: flex;
  align-items: center;
}

.btn-sm {
  padding: 6px 12px;
  font-size: 12px;
}

/* CTI Panel Styles */
.cti-panel {
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  border-radius: 16px;
  padding: 24px;
  margin-bottom: 30px;
  color: white;
  box-shadow: 0 10px 40px rgba(102, 126, 234, 0.3);
}

.panel-header-section {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 24px;
  padding-bottom: 16px;
  border-bottom: 1px solid rgba(255, 255, 255, 0.2);
}

.panel-header-section h3 {
  margin: 0;
  font-size: 22px;
  font-weight: 600;
}

.cti-status-indicators {
  display: flex;
  gap: 12px;
}

.indicator {
  padding: 6px 14px;
  border-radius: 20px;
  font-size: 13px;
  font-weight: 500;
  background: rgba(255, 255, 255, 0.2);
}

.indicator.active {
  background: rgba(40, 167, 69, 0.9);
}

.indicator.inactive {
  background: rgba(220, 53, 69, 0.7);
}

/* Phone State Panel */
.phone-state-panel {
  margin-bottom: 24px;
}

.state-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 16px;
}

.state-card {
  background: rgba(255, 255, 255, 0.15);
  backdrop-filter: blur(10px);
  border-radius: 12px;
  padding: 16px;
  display: flex;
  align-items: center;
  gap: 14px;
  transition: all 0.3s ease;
}

.state-card:hover {
  background: rgba(255, 255, 255, 0.25);
  transform: translateY(-2px);
}

.state-card.alert {
  background: rgba(220, 53, 69, 0.4);
  border: 1px solid rgba(220, 53, 69, 0.6);
}

.state-card.warning {
  background: rgba(255, 193, 7, 0.3);
  border: 1px solid rgba(255, 193, 7, 0.5);
}

.state-card.info {
  background: rgba(23, 162, 184, 0.3);
  border: 1px solid rgba(23, 162, 184, 0.5);
}

.state-icon {
  font-size: 28px;
}

.state-info {
  display: flex;
  flex-direction: column;
}

.state-label {
  font-size: 12px;
  opacity: 0.8;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.state-value {
  font-size: 16px;
  font-weight: 600;
}

/* CTI Section */
.cti-section {
  background: rgba(255, 255, 255, 0.1);
  border-radius: 12px;
  padding: 20px;
  margin-bottom: 16px;
}

.cti-section h4 {
  margin: 0 0 16px 0;
  font-size: 16px;
  font-weight: 600;
  opacity: 0.9;
}

/* CTI Button Grid */
.cti-button-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
  gap: 10px;
}

.cti-btn {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 14px 12px;
  border: none;
  border-radius: 12px;
  cursor: pointer;
  font-size: 13px;
  font-weight: 500;
  transition: all 0.2s ease;
  min-height: 70px;
}

.cti-btn:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
}

.cti-btn:active {
  transform: translateY(0);
}

.btn-icon {
  font-size: 22px;
  margin-bottom: 6px;
}

.btn-label {
  font-size: 12px;
}

.cti-btn.success {
  background: linear-gradient(135deg, #28a745, #20c997);
  color: white;
}

.cti-btn.danger {
  background: linear-gradient(135deg, #dc3545, #e74c3c);
  color: white;
}

.cti-btn.warning {
  background: linear-gradient(135deg, #ffc107, #fd7e14);
  color: #212529;
}

.cti-btn.primary {
  background: linear-gradient(135deg, #007bff, #6610f2);
  color: white;
}

.cti-btn.info {
  background: linear-gradient(135deg, #17a2b8, #3498db);
  color: white;
}

.cti-btn.secondary {
  background: rgba(255, 255, 255, 0.2);
  color: white;
  border: 1px solid rgba(255, 255, 255, 0.3);
}

.cti-btn.secondary:hover {
  background: rgba(255, 255, 255, 0.3);
}

/* Dial Section */
.dial-section {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.dial-input-group {
  display: flex;
  gap: 10px;
}

.dial-input {
  flex: 1;
  padding: 12px 16px;
  border: 1px solid rgba(255, 255, 255, 0.3);
  border-radius: 10px;
  background: rgba(255, 255, 255, 0.15);
  color: white;
  font-size: 15px;
}

.dial-input::placeholder {
  color: rgba(255, 255, 255, 0.6);
}

.dial-input:focus {
  outline: none;
  border-color: rgba(255, 255, 255, 0.6);
  background: rgba(255, 255, 255, 0.2);
}

.dial-input.small {
  max-width: 180px;
}

.dial-btn {
  flex-direction: row;
  min-height: auto;
  padding: 12px 20px;
  gap: 8px;
}

/* Transfer Section */
.transfer-section {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.transfer-buttons {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
}

.transfer-buttons .cti-btn {
  flex-direction: row;
  min-height: auto;
  padding: 12px 18px;
  gap: 8px;
}

/* Feature Buttons */
.feature-buttons, .setup-buttons {
  display: flex;
  gap: 12px;
  flex-wrap: wrap;
}

.feature-buttons .cti-btn, .setup-buttons .cti-btn {
  flex-direction: row;
  min-height: auto;
  padding: 12px 18px;
  gap: 8px;
}

/* Modal Overlay */
.modal-overlay {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: rgba(0, 0, 0, 0.6);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
  backdrop-filter: blur(4px);
}

.modal-overlay .modal-content {
  background: var(--modal-bg, white);
  color: var(--text-color, inherit);
  padding: 30px;
  border-radius: 16px;
  min-width: 400px;
  max-width: 90%;
  max-height: 80vh;
  overflow-y: auto;
  box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
}

/* Dark Mode Support */
@media (prefers-color-scheme: dark) {
  .phones-container {
    --panel-bg: #1e1e2e;
    --card-bg: #2a2a3e;
    --border-color: #3a3a4e;
    --text-color: #e0e0e0;
    --text-primary: #f0f0f0;
    --text-muted: #a0a0a0;
    --tab-bg: #2a2a3e;
    --tab-hover-bg: #3a3a4e;
    --hover-bg: #3a3a4e;
    --badge-bg: #3730a3;
    --badge-color: #e0e7ff;
    --capability-bg: #1a365d;
    --capability-color: #90cdf4;
    --modal-bg: #1e1e2e;
  }
  
  .discovery-panel,
  .lldp-panel {
    background: var(--panel-bg);
  }
  
  .neighbor-card {
    background: var(--card-bg);
    border-color: var(--border-color);
  }
  
  .tab-btn {
    background: var(--tab-bg);
    border-color: var(--border-color);
    color: var(--text-color);
  }
  
  .tab-btn:hover {
    background: var(--tab-hover-bg);
  }
  
  .neighbor-info h4 {
    color: var(--text-primary);
  }
  
  .neighbor-ip,
  .neighbor-mac,
  .neighbor-vendor {
    color: var(--text-muted);
  }
  
  .btn-close {
    border-color: var(--border-color);
    color: var(--text-color);
  }
  
  .btn-close:hover {
    background: var(--hover-bg);
  }
  
  .panel-header {
    border-bottom-color: var(--border-color);
  }
  
  .panel-header h3 {
    color: var(--text-primary);
  }
}
</style>
