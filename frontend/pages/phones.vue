<template>
  <div class="phones-container">
    <div class="header">
      <h1>📱 VoIP Phones Management</h1>
      <div class="header-actions">
        <button @click="refreshPhones" class="btn btn-primary">
          🔄 Refresh
        </button>
        <button @click="scanNetwork" class="btn btn-secondary">
          🔍 Scan Network
        </button>
      </div>
    </div>

    <!-- Phone List -->
    <div v-if="!selectedPhone" class="phones-list">
      <div v-if="loading" class="loading">
        Loading phones...
      </div>

      <div v-else-if="phones.length === 0" class="empty-state">
        <p>📭 No phones detected</p>
        <p class="help-text">Phones are automatically detected from SIP registrations</p>
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
          <p>The phone has existing Action URL configuration that differs from RayanPBX values.</p>
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
            <button @click="updateActionUrls(true)" class="btn btn-danger">
              Force Update
            </button>
            <button @click="showActionUrlConfirmModal = false" class="btn btn-secondary">
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
import { ref, onMounted } from 'vue'

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

const credentials = ref({
  username: 'admin',
  password: ''
})

onMounted(() => {
  refreshPhones()
  loadExtensions()
})

async function refreshPhones() {
  loading.value = true
  try {
    const response = await fetch('/api/phones', {
      headers: {
        'Authorization': `Bearer ${localStorage.getItem('token')}`,
      }
    })
    const data = await response.json()
    if (data.success) {
      phones.value = data.phones
    }
  } catch (error) {
    showNotification('Failed to load phones', 'error')
  } finally {
    loading.value = false
  }
}

async function scanNetwork() {
  loading.value = true
  
  // Get network from config or use default
  const network = localStorage.getItem('network_range') || '192.168.1.0/24'
  
  try {
    const response = await fetch('/api/grandstream/scan', {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${localStorage.getItem('token')}`,
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({ network })
    })
    const data = await response.json()
    if (data.success) {
      showNotification('Network scan completed', 'success')
      refreshPhones()
    }
  } catch (error) {
    showNotification('Network scan failed', 'error')
  } finally {
    loading.value = false
  }
}

async function selectPhone(phone) {
  selectedPhone.value = phone
  await refreshPhoneStatus()
}

async function refreshPhoneStatus() {
  if (!selectedPhone.value) return
  
  try {
    const response = await fetch('/api/phones/control', {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${localStorage.getItem('token')}`,
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        ip: selectedPhone.value.ip,
        action: 'get_status',
        credentials: credentials.value
      })
    })
    const data = await response.json()
    if (data.success !== false) {
      phoneStatus.value = data
    } else if (data.error && data.error.includes('401')) {
      needsCredentials.value = true
    }
  } catch (error) {
    console.error('Failed to get phone status:', error)
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
    const requestBody = {
      ip: selectedPhone.value.ip,
      action: action,
      credentials: credentials.value
    }
    
    // Add confirmation flag for destructive actions
    if (action === 'factory_reset') {
      requestBody.confirm_destructive = true
    }

    const response = await fetch('/api/phones/control', {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${localStorage.getItem('token')}`,
        'Content-Type': 'application/json',
      },
      body: JSON.stringify(requestBody)
    })
    const data = await response.json()
    
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
    const response = await fetch('/api/extensions', {
      headers: {
        'Authorization': `Bearer ${localStorage.getItem('token')}`,
      }
    })
    const data = await response.json()
    extensions.value = data
  } catch (error) {
    console.error('Failed to load extensions:', error)
  }
}

async function provisionExtension() {
  if (!selectedExtension.value) {
    showNotification('Please select an extension', 'error')
    return
  }

  try {
    // Use complete provisioning if Action URLs are included
    const endpoint = includeActionUrls.value ? '/api/grandstream/provision-complete' : '/api/phones/provision'
    
    const response = await fetch(endpoint, {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${localStorage.getItem('token')}`,
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        ip: selectedPhone.value.ip,
        extension_id: selectedExtension.value,
        account_number: accountNumber.value,
        credentials: credentials.value,
        force_action_urls: false
      })
    })
    const data = await response.json()
    
    if (response.status === 409 && data.action_urls_result?.requires_confirmation) {
      // Action URLs have conflicts - show confirmation modal
      actionUrlConflicts.value = data.action_urls_result.conflicts
      showActionUrlConfirmModal.value = true
      showProvisionModal.value = false
      showNotification('Extension provisioned but Action URLs need confirmation', 'warning')
    } else if (data.success) {
      showNotification('Extension provisioned successfully', 'success')
      showProvisionModal.value = false
    } else {
      showNotification(data.error || 'Provisioning failed', 'error')
    }
  } catch (error) {
    showNotification('Provisioning failed', 'error')
  }
}

async function checkActionUrls() {
  if (!selectedPhone.value) return
  
  try {
    const response = await fetch('/api/grandstream/action-urls/check', {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${localStorage.getItem('token')}`,
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        ip: selectedPhone.value.ip,
        credentials: credentials.value
      })
    })
    const data = await response.json()
    
    if (!response.ok) {
      showNotification(data.error || `Failed to check Action URLs (${response.status})`, 'error')
      return
    }
    
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
    const response = await fetch('/api/grandstream/action-urls/update', {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${localStorage.getItem('token')}`,
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        ip: selectedPhone.value.ip,
        credentials: credentials.value,
        force: force
      })
    })
    const data = await response.json()
    
    if (response.status === 409 && data.requires_confirmation) {
      // Conflicts found - show confirmation modal
      actionUrlConflicts.value = data.conflicts
      showActionUrlConfirmModal.value = true
      showNotification('Action URL conflicts found - confirmation required', 'warning')
    } else if (!response.ok) {
      showNotification(data.error || `Failed to update Action URLs (${response.status})`, 'error')
    } else if (data.success) {
      showActionUrlConfirmModal.value = false
      showNotification(data.message || 'Action URLs updated successfully', 'success')
      // Refresh status
      await checkActionUrls()
    } else {
      showNotification(data.error || 'Failed to update Action URLs', 'error')
    }
  } catch (error) {
    showNotification('Failed to update Action URLs', 'error')
  }
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
</style>
