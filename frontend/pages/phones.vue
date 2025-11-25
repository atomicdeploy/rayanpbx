<template>
  <div class="min-h-screen bg-gray-50 dark:bg-gray-900">
    <nav class="bg-white dark:bg-gray-800 shadow-lg">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
          <div class="flex items-center space-x-4">
            <NuxtLink to="/" class="text-2xl font-bold text-blue-600 dark:text-blue-400">
              {{ $t('app.title') }}
            </NuxtLink>
            <span class="text-gray-400">|</span>
            <span class="text-lg text-gray-700 dark:text-gray-300">{{ $t('phones.title') }}</span>
          </div>
          <div class="flex items-center space-x-4">
            <button @click="refreshPhones" class="btn btn-primary">
              🔄 {{ $t('phones.refresh') }}
            </button>
            <button @click="scanNetwork" class="btn btn-secondary">
              🔍 {{ $t('phones.scanNetwork') }}
            </button>
            <NuxtLink to="/" class="btn btn-secondary">
              ← {{ $t('nav.dashboard') }}
            </NuxtLink>
          </div>
        </div>
      </div>
    </nav>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
      <!-- Phone List -->
      <div v-if="!selectedPhone" class="card">
        <div v-if="loading" class="text-center py-8">
          {{ $t('common.loading') }}
        </div>

        <div v-else-if="phones.length === 0" class="text-center py-8 text-gray-600 dark:text-gray-400">
          <p class="text-xl mb-2">📭 {{ $t('phones.noPhones') }}</p>
          <p class="text-sm">{{ $t('phones.phonesHelp') }}</p>
        </div>

        <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
          <div
            v-for="phone in phones"
            :key="phone.ip"
            class="border border-gray-200 dark:border-gray-700 rounded-lg p-4 cursor-pointer hover:shadow-lg hover:border-blue-500 transition-all"
            @click="selectPhone(phone)"
          >
            <div class="flex items-center space-x-4">
              <div class="text-3xl">
                {{ phone.status === 'online' ? '🟢' : '🔴' }}
              </div>
              <div class="flex-1">
                <h3 class="font-bold text-lg">{{ phone.extension || $t('common.unknown') }}</h3>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ phone.ip }}</p>
                <p class="text-xs text-gray-400 dark:text-gray-500">{{ phone.user_agent || 'GrandStream' }}</p>
              </div>
              <div>
                <span :class="[
                  'px-2 py-1 rounded text-xs font-medium',
                  phone.status === 'online' ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' : 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200'
                ]">
                  {{ phone.status === 'online' ? $t('phones.online') : $t('phones.offline') }}
                </span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Phone Details -->
      <div v-else class="card">
        <div class="flex justify-between items-center mb-6 pb-4 border-b border-gray-200 dark:border-gray-700">
          <button @click="selectedPhone = null" class="btn btn-secondary">
            ← {{ $t('phones.back') }}
          </button>
          <h2 class="text-xl font-bold">{{ $t('phones.phoneDetails') }}: {{ selectedPhone.extension || selectedPhone.ip }}</h2>
          <button @click="refreshPhoneStatus" class="btn btn-primary">
            🔄 {{ $t('phones.refreshStatus') }}
          </button>
        </div>

        <!-- Status Panel -->
        <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4 mb-4">
          <h3 class="font-bold mb-3">📊 {{ $t('phones.statusPanel') }}</h3>
          <div v-if="phoneStatus" class="grid grid-cols-2 md:grid-cols-3 gap-4">
            <div>
              <span class="text-gray-500 dark:text-gray-400">{{ $t('phones.ipAddress') }}:</span>
              <span class="ml-2 font-medium">{{ phoneStatus.ip }}</span>
            </div>
            <div>
              <span class="text-gray-500 dark:text-gray-400">{{ $t('phones.model') }}:</span>
              <span class="ml-2 font-medium">{{ phoneStatus.model || $t('common.unknown') }}</span>
            </div>
            <div>
              <span class="text-gray-500 dark:text-gray-400">{{ $t('phones.firmware') }}:</span>
              <span class="ml-2 font-medium">{{ phoneStatus.firmware || $t('common.unknown') }}</span>
            </div>
            <div>
              <span class="text-gray-500 dark:text-gray-400">{{ $t('phones.mac') }}:</span>
              <span class="ml-2 font-medium">{{ phoneStatus.mac || $t('common.unknown') }}</span>
            </div>
            <div>
              <span class="text-gray-500 dark:text-gray-400">{{ $t('phones.uptime') }}:</span>
              <span class="ml-2 font-medium">{{ phoneStatus.uptime || $t('common.unknown') }}</span>
            </div>
            <div>
              <span class="text-gray-500 dark:text-gray-400">{{ $t('phones.status') }}:</span>
              <span :class="['ml-2 font-medium', phoneStatus.status === 'online' ? 'text-green-600' : 'text-red-600']">
                {{ phoneStatus.status }}
              </span>
            </div>
          </div>
        </div>

        <!-- Control Panel -->
        <div class="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-4 mb-4">
          <h3 class="font-bold mb-3">🎛️ {{ $t('phones.control') }}</h3>
          <div class="flex flex-wrap gap-2">
            <button @click="performAction('reboot')" class="btn btn-warning">
              🔄 {{ $t('phones.reboot') }}
            </button>
            <button @click="performAction('factory_reset')" class="btn btn-danger">
              🏭 {{ $t('phones.factoryReset') }}
            </button>
            <button @click="performAction('get_config')" class="btn btn-info">
              📋 {{ $t('phones.getConfig') }}
            </button>
            <button @click="showProvisionModal = true" class="btn btn-success">
              🔧 {{ $t('phones.provision') }}
            </button>
          </div>
        </div>

        <!-- Action URLs Panel -->
        <div class="bg-purple-50 dark:bg-purple-900/20 rounded-lg p-4 mb-4">
          <h3 class="font-bold mb-3">📡 {{ $t('phones.actionUrls') }}</h3>
          <div class="flex gap-2 mb-3">
            <button @click="checkActionUrls" class="btn btn-info">
              🔍 {{ $t('phones.checkStatus') }}
            </button>
            <button @click="updateActionUrls(false)" class="btn btn-primary">
              🔄 {{ $t('phones.update') }}
            </button>
          </div>
          <div v-if="actionUrlStatus" class="bg-white dark:bg-gray-800 rounded p-3">
            <div class="flex gap-2 mb-2">
              <span :class="[
                'px-2 py-1 rounded text-xs font-medium',
                actionUrlStatus.needs_update ? 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200' : 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200'
              ]">
                {{ actionUrlStatus.needs_update ? $t('phones.needsUpdate') : $t('phones.configured') }}
              </span>
              <span v-if="actionUrlStatus.has_conflicts" class="px-2 py-1 rounded text-xs font-medium bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200">
                ⚠️ {{ $t('phones.hasConflicts') }}
              </span>
            </div>
            <div class="text-sm text-gray-600 dark:text-gray-400">
              <span>Total: {{ actionUrlStatus.summary?.total || 0 }}</span>
              <span class="ml-4">Matching: {{ actionUrlStatus.summary?.matching || 0 }}</span>
              <span class="ml-4">Conflicts: {{ actionUrlStatus.summary?.conflicts || 0 }}</span>
            </div>
          </div>
        </div>

        <!-- Configuration Panel -->
        <div v-if="phoneConfig" class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4 mb-4">
          <h3 class="font-bold mb-3">⚙️ {{ $t('phones.configuration') }}</h3>
          <pre class="bg-white dark:bg-gray-900 p-3 rounded overflow-x-auto text-xs">{{ JSON.stringify(phoneConfig, null, 2) }}</pre>
        </div>
      </div>

      <!-- Credentials Input Modal -->
      <div v-if="needsCredentials" class="fixed inset-0 z-50 overflow-y-auto" @click.self="needsCredentials = false">
        <div class="flex items-center justify-center min-h-screen px-4">
          <div class="fixed inset-0 bg-black opacity-50"></div>
          <div class="relative card max-w-md w-full">
            <h3 class="text-lg font-bold mb-4">{{ $t('phones.credentials') }}</h3>
            <input
              v-model="credentials.username"
              type="text"
              :placeholder="$t('phones.username') + ' (default: admin)'"
              class="input mb-3"
            />
            <input
              v-model="credentials.password"
              type="password"
              :placeholder="$t('phones.password')"
              class="input mb-4"
            />
            <div class="flex justify-end gap-2">
              <button @click="needsCredentials = false" class="btn btn-secondary">
                {{ $t('phones.cancel') }}
              </button>
              <button @click="submitCredentials" class="btn btn-primary">
                {{ $t('phones.submit') }}
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Action URL Confirmation Modal -->
      <div v-if="showActionUrlConfirmModal" class="fixed inset-0 z-50 overflow-y-auto" @click.self="cancelActionUrlUpdate">
        <div class="flex items-center justify-center min-h-screen px-4">
          <div class="fixed inset-0 bg-black opacity-50"></div>
          <div class="relative card max-w-lg w-full max-h-[80vh] overflow-y-auto">
            <h3 class="text-lg font-bold mb-4">⚠️ {{ $t('phones.confirmActionUrlUpdate') }}</h3>
            <p class="text-gray-600 dark:text-gray-400 mb-4">
              {{ $t('phones.actionUrlConflictWarning') }}
            </p>
            <div v-if="actionUrlConflicts" class="mb-4 max-h-60 overflow-y-auto">
              <h4 class="font-medium mb-2">Conflicts:</h4>
              <div v-for="(conflict, event) in actionUrlConflicts" :key="event" class="bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-700 rounded p-2 mb-2">
                <strong class="text-yellow-800 dark:text-yellow-200">{{ event }}</strong>
                <div class="text-xs mt-1">
                  <div class="text-red-600">{{ $t('phones.current') }}: {{ conflict.current || '(empty)' }}</div>
                  <div class="text-green-600">{{ $t('phones.expected') }}: {{ conflict.expected }}</div>
                </div>
              </div>
            </div>
            <div class="flex justify-end gap-2">
              <button @click="cancelActionUrlUpdate" class="btn btn-secondary">
                {{ $t('phones.cancel') }}
              </button>
              <button @click="forceUpdateActionUrls" class="btn btn-danger">
                {{ $t('phones.forceUpdateActionUrls') }}
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Provision Modal -->
      <div v-if="showProvisionModal" class="fixed inset-0 z-50 overflow-y-auto" @click.self="showProvisionModal = false">
        <div class="flex items-center justify-center min-h-screen px-4">
          <div class="fixed inset-0 bg-black opacity-50"></div>
          <div class="relative card max-w-md w-full">
            <h3 class="text-lg font-bold mb-4">{{ $t('phones.provisionExtension') }}</h3>
            <select v-model="selectedExtension" class="input mb-3">
              <option value="">{{ $t('phones.selectExtension') }}</option>
              <option v-for="ext in extensions" :key="ext.id" :value="ext.id">
                {{ ext.extension_number }} - {{ ext.name }}
              </option>
            </select>
            <input
              v-model="accountNumber"
              type="number"
              min="1"
              max="6"
              :placeholder="$t('phones.accountNumber') + ' (1-6)'"
              class="input mb-3"
            />
            <label class="flex items-center gap-2 mb-4 cursor-pointer">
              <input type="checkbox" v-model="includeActionUrls" class="rounded" />
              <span>{{ $t('phones.configureActionUrls') }}</span>
            </label>
            <div class="flex justify-end gap-2">
              <button @click="showProvisionModal = false" class="btn btn-secondary">
                {{ $t('phones.cancel') }}
              </button>
              <button @click="provisionExtension" class="btn btn-primary">
                {{ $t('phones.provision') }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Toast Notification -->
    <div v-if="notification" :class="[
      'fixed top-4 right-4 px-6 py-3 rounded-lg shadow-lg z-50 animate-fade-in',
      notification.type === 'success' ? 'bg-green-100 text-green-800 border border-green-200' :
      notification.type === 'error' ? 'bg-red-100 text-red-800 border border-red-200' :
      notification.type === 'warning' ? 'bg-yellow-100 text-yellow-800 border border-yellow-200' :
      'bg-blue-100 text-blue-800 border border-blue-200'
    ]">
      {{ notification.message }}
    </div>
  </div>
</template>

<script setup lang="ts">
definePageMeta({
  middleware: 'auth'
})

const { t } = useI18n()
const api = useApi()
const authStore = useAuthStore()
const router = useRouter()

const phones = ref<any[]>([])
const selectedPhone = ref<any>(null)
const phoneStatus = ref<any>(null)
const phoneConfig = ref<any>(null)
const loading = ref(false)
const needsCredentials = ref(false)
const showProvisionModal = ref(false)
const showActionUrlConfirmModal = ref(false)
const extensions = ref<any[]>([])
const selectedExtension = ref('')
const accountNumber = ref(1)
const includeActionUrls = ref(true)
const notification = ref<{ message: string; type: string } | null>(null)
const actionUrlStatus = ref<any>(null)
const actionUrlConflicts = ref<any>(null)

// Store provision context for re-provisioning with force flag
const provisionContext = ref<any>(null)

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
  await refreshPhones()
  await loadExtensions()
})

async function refreshPhones() {
  loading.value = true
  try {
    const data = await api.getPhones()
    if (data.success) {
      phones.value = data.phones || []
    }
  } catch (error: any) {
    showNotification(t('phones.actionFailed'), 'error')
  } finally {
    loading.value = false
  }
}

async function scanNetwork() {
  loading.value = true
  
  // Get network from config or use default
  const network = localStorage.getItem('network_range') || '192.168.1.0/24'
  
  try {
    const data = await api.scanGrandstreamNetwork(network)
    if (data.success) {
      showNotification(t('phones.networkScanCompleted'), 'success')
      await refreshPhones()
    }
  } catch (error: any) {
    showNotification(t('phones.networkScanFailed'), 'error')
  } finally {
    loading.value = false
  }
}

async function selectPhone(phone: any) {
  selectedPhone.value = phone
  await refreshPhoneStatus()
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
  } catch (error: any) {
    console.error('Failed to get phone status:', error?.message || 'Unknown error')
  }
}

async function performAction(action: string) {
  if (!selectedPhone.value) return

  const confirmActions = ['factory_reset', 'reboot']
  if (confirmActions.includes(action)) {
    const actionName = action === 'factory_reset' ? t('phones.factoryReset') : t('phones.reboot')
    if (!confirm(action === 'factory_reset' ? t('phones.factoryResetConfirm') : t('phones.rebootConfirm'))) {
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
      showNotification(t('phones.actionSuccess'), 'success')
    } else {
      if (data.error && data.error.includes('401')) {
        needsCredentials.value = true
      } else {
        showNotification(data.error || data.message || t('phones.actionFailed'), 'error')
      }
    }
  } catch (error: any) {
    showNotification(t('phones.actionFailed'), 'error')
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
  } catch (error: any) {
    console.error('Failed to load extensions:', error?.message || 'Unknown error')
  }
}

async function provisionExtension(forceActionUrls = false) {
  if (!selectedExtension.value) {
    showNotification(t('phones.selectExtension'), 'error')
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

    let data: any
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
        ? t('phones.provisionSuccess') + '. ' + t('phones.hasConflicts')
        : t('phones.hasConflicts')
      showNotification(message, 'warning')
    } else if (data.success) {
      provisionContext.value = null // Clear context on success
      showNotification(t('phones.provisionSuccess'), 'success')
      showProvisionModal.value = false
      showActionUrlConfirmModal.value = false
    } else {
      const errorMessage = data.error || data.message || t('phones.provisionFailed')
      showNotification(errorMessage, 'error')
    }
  } catch (error: any) {
    console.error('Provisioning error:', error?.message || 'Network or server error')
    showNotification(t('phones.provisionFailed'), 'error')
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
      showNotification(t('phones.actionUrlsCheckSuccess'), 'success')
    } else {
      showNotification(data.error || t('phones.actionFailed'), 'error')
    }
  } catch (error: any) {
    showNotification(t('phones.actionFailed'), 'error')
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
      showNotification(t('phones.hasConflicts'), 'warning')
    } else if (data.success) {
      provisionContext.value = null
      showActionUrlConfirmModal.value = false
      showNotification(t('phones.actionUrlsUpdated'), 'success')
      // Refresh status
      await checkActionUrls()
    } else {
      showNotification(data.error || data.message || t('phones.actionFailed'), 'error')
    }
  } catch (error: any) {
    console.error('Update Action URLs error:', error?.message || 'Network or server error')
    showNotification(t('phones.actionFailed'), 'error')
  }
}

// Cancel action URL update and clear context
function cancelActionUrlUpdate() {
  showActionUrlConfirmModal.value = false
  provisionContext.value = null
  actionUrlConflicts.value = null
}

function showNotification(message: string, type = 'info') {
  notification.value = { message, type }
  setTimeout(() => {
    notification.value = null
  }, 3000)
}
</script>

<style scoped>
/* Animation for notification fade-in */
@keyframes fade-in {
  from {
    transform: translateX(100%);
    opacity: 0;
  }
  to {
    transform: translateX(0);
    opacity: 1;
  }
}

.animate-fade-in {
  animation: fade-in 0.3s ease-out;
}
</style>
