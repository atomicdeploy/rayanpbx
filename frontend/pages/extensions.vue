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
            <span class="text-lg text-gray-700 dark:text-gray-300">{{ $t('extensions.title') }}</span>
          </div>
          <div class="flex items-center space-x-4">
            <button @click="showModal = true" class="btn btn-primary">
              {{ $t('extensions.add') }}
            </button>
            <NuxtLink to="/" class="btn btn-secondary">
              ← {{ $t('nav.dashboard') }}
            </NuxtLink>
          </div>
        </div>
      </div>
    </nav>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
      <div class="card">
        <!-- Search and Filter Controls -->
        <div v-if="!loading && extensions.length > 0" class="mb-4 flex gap-4">
          <input
            v-model="searchQuery"
            type="text"
            :placeholder="$t('common.search') + '...'"
            class="input flex-1"
          />
          <select v-model="statusFilter" class="input w-48">
            <option value="">All Status</option>
            <option value="registered">Registered</option>
            <option value="offline">Offline</option>
          </select>
        </div>

        <div v-if="loading" class="text-center py-8">
          {{ $t('common.loading') }}
        </div>

        <div v-else-if="filteredExtensions.length === 0 && extensions.length === 0" class="text-center py-8 text-gray-600 dark:text-gray-400">
          No extensions configured. Click "Add Extension" to get started.
        </div>

        <div v-else-if="filteredExtensions.length === 0" class="text-center py-8 text-gray-600 dark:text-gray-400">
          No extensions match your search criteria.
        </div>

        <div v-else class="overflow-x-auto">
          <div class="max-h-[600px] overflow-y-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
              <thead class="bg-gray-50 dark:bg-gray-800 sticky top-0 z-10">
                <tr>
                  <th 
                    @click="sortBy('extension_number')"
                    class="px-3 py-3 text-start text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-700 w-32"
                  >
                    <div class="flex items-center space-x-1">
                      <span>{{ $t('extensions.number') }}</span>
                      <span v-if="sortField === 'extension_number'">{{ sortDirection === 'asc' ? '↑' : '↓' }}</span>
                    </div>
                  </th>
                  <th 
                    @click="sortBy('name')"
                    class="px-6 py-3 text-start text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-700"
                  >
                    <div class="flex items-center space-x-1">
                      <span>{{ $t('extensions.name') }}</span>
                      <span v-if="sortField === 'name'">{{ sortDirection === 'asc' ? '↑' : '↓' }}</span>
                    </div>
                  </th>
                  <th 
                    @click="sortBy('status')"
                    class="px-6 py-3 text-start text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-700"
                  >
                    <div class="flex items-center space-x-1">
                      <span>{{ $t('extensions.status') }}</span>
                      <span v-if="sortField === 'status'">{{ sortDirection === 'asc' ? '↑' : '↓' }}</span>
                    </div>
                  </th>
                  <th class="px-6 py-3 text-start text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    {{ $t('extensions.enabled') }}
                  </th>
                  <th class="px-6 py-3 text-end text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    {{ $t('extensions.actions') }}
                  </th>
                </tr>
              </thead>
              <tbody class="bg-white dark:bg-gray-900 divide-y divide-gray-200 dark:divide-gray-700">
                <tr v-for="ext in filteredExtensions" :key="ext.id" class="hover:bg-gray-50 dark:hover:bg-gray-800">
                  <td class="px-3 py-4 whitespace-nowrap text-sm font-medium w-32">
                    <div class="flex items-center space-x-2">
                      <span>{{ ext.extension_number }}</span>
                      <!-- HD Badge if using 16kHz+ codec -->
                      <span v-if="ext.hd_codec" 
                        class="px-2 py-0.5 text-xs font-bold rounded bg-gradient-to-r from-green-400 to-green-600 text-white shadow-sm"
                        :title="`HD Audio - ${ext.codec_info || '16kHz+'}`">
                        🎵 HD
                      </span>
                    </div>
                  </td>
                  <td class="px-6 py-4 whitespace-nowrap text-sm">
                    <div>
                      <div class="font-medium">{{ ext.name }}</div>
                      <!-- Show IP address if registered -->
                      <div v-if="ext.ip_address" class="text-xs text-gray-500 dark:text-gray-400">
                        📍 {{ ext.ip_address }}:{{ ext.port }}
                      </div>
                    </div>
                  </td>
                  <td class="px-6 py-4 whitespace-nowrap text-sm">
                    <div class="flex items-center space-x-2">
                      <!-- Live registration indicator -->
                      <span v-if="ext.registered" class="relative flex h-2 w-2">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-2 w-2 bg-green-500"></span>
                      </span>
                      <span v-else class="inline-flex rounded-full h-2 w-2 bg-gray-400"></span>
                      
                      <button
                        @click="ext.registered ? null : showOfflineHelp(ext)"
                        :class="[
                          statusClass(ext.status),
                          !ext.registered ? 'cursor-pointer hover:underline' : ''
                        ]"
                      >
                        {{ ext.registered ? '🟢 Registered' : '⚫ Offline' }}
                      </button>
                      
                      <!-- Show latency if available -->
                      <span v-if="ext.latency_ms" class="text-xs text-gray-500" :title="`Qualify latency: ${ext.latency_ms}ms`">
                        ({{ ext.latency_ms }}ms)
                      </span>
                    </div>
                  </td>
                  <td class="px-6 py-4 whitespace-nowrap text-sm">
                    <span v-if="ext.enabled" class="text-green-600">✓</span>
                    <span v-else class="text-red-600">✗</span>
                  </td>
                  <td class="px-6 py-4 whitespace-nowrap text-end text-sm font-medium space-x-2">
                    <button @click="editExtension(ext)" class="text-blue-600 hover:text-blue-900">
                      ✏️
                    </button>
                    <button @click="deleteExtension(ext)" class="text-red-600 hover:text-red-900">
                      🗑️
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- Modal for Add/Edit -->
    <div v-if="showModal" class="fixed inset-0 z-50 overflow-y-auto" @click.self="showModal = false">
      <div class="flex items-center justify-center min-h-screen px-4">
        <div class="fixed inset-0 bg-black opacity-50"></div>
        <div class="relative card max-w-2xl w-full">
          <h2 class="text-2xl font-bold mb-6">
            {{ editMode ? $t('extensions.edit') : $t('extensions.add') }}
          </h2>

          <form @submit.prevent="saveExtension" class="space-y-4">
            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="label">{{ $t('extensions.number') }}</label>
                <input v-model="form.extension_number" type="text" class="input" required :disabled="editMode" />
              </div>
              <div>
                <label class="label">{{ $t('extensions.name') }}</label>
                <input v-model="form.name" type="text" class="input" required />
              </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="label">{{ $t('extensions.email') }}</label>
                <input v-model="form.email" type="email" class="input" />
              </div>
              <div>
                <label class="label">{{ $t('extensions.password') }}</label>
                <input v-model="form.secret" type="password" class="input" :required="!editMode" />
              </div>
            </div>

            <div>
              <label class="flex items-center space-x-2">
                <input v-model="form.enabled" type="checkbox" class="rounded" />
                <span class="text-sm">{{ $t('extensions.enabled') }}</span>
              </label>
            </div>

            <div>
              <label class="label">{{ $t('extensions.notes') }}</label>
              <textarea v-model="form.notes" class="input" rows="3"></textarea>
            </div>

            <div class="flex justify-end space-x-4">
              <button type="button" @click="showModal = false" class="btn btn-secondary">
                {{ $t('extensions.cancel') }}
              </button>
              <button type="submit" class="btn btn-primary" :disabled="saving">
                {{ saving ? $t('common.loading') : $t('extensions.save') }}
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Offline Help Modal -->
    <div v-if="offlineHelpModal" class="fixed inset-0 z-50 overflow-y-auto" @click.self="offlineHelpModal = false">
      <div class="flex items-center justify-center min-h-screen px-4">
        <div class="fixed inset-0 bg-black opacity-50"></div>
        <div class="relative card max-w-2xl w-full">
          <div class="flex justify-between items-start mb-4">
            <h2 class="text-2xl font-bold text-red-600">
              Extension {{ selectedExtension?.extension_number }} is Offline
            </h2>
            <button @click="offlineHelpModal = false" class="text-gray-500 hover:text-gray-700">
              ✕
            </button>
          </div>

          <div class="space-y-4 text-gray-700 dark:text-gray-300">
            <div class="bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-700 rounded-lg p-4">
              <h3 class="font-semibold mb-2">⚠️ Troubleshooting Steps:</h3>
              <ol class="list-decimal list-inside space-y-2 text-sm">
                <li>Check if the SIP device is powered on and connected to the network</li>
                <li>Verify network connectivity between the device and PBX server</li>
                <li>Confirm the extension credentials are correctly configured on the device:
                  <ul class="list-disc list-inside ml-6 mt-1">
                    <li>Extension Number: <strong>{{ selectedExtension?.extension_number }}</strong></li>
                    <li>Server: Check your server IP/hostname</li>
                    <li>Password: Verify the secret matches</li>
                  </ul>
                </li>
                <li>Check if the extension is enabled: 
                  <span v-if="selectedExtension?.enabled" class="text-green-600 font-semibold">✓ Enabled</span>
                  <span v-else class="text-red-600 font-semibold">✗ Disabled - Enable it to allow registration</span>
                </li>
                <li>Review firewall rules (ports 5060 UDP for SIP, 10000-20000 UDP for RTP)</li>
                <li>Check Asterisk logs for registration errors using the Console page</li>
              </ol>
            </div>

            <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-700 rounded-lg p-4">
              <h3 class="font-semibold mb-2">💡 Quick Actions:</h3>
              <div class="space-y-2">
                <button 
                  @click="editExtension(selectedExtension)"
                  class="btn btn-primary w-full"
                >
                  Edit Extension Configuration
                </button>
                <button 
                  v-if="!selectedExtension?.enabled"
                  @click="enableExtension(selectedExtension)"
                  class="btn bg-green-600 hover:bg-green-700 text-white w-full"
                >
                  Enable This Extension
                </button>
                <NuxtLink 
                  to="/console"
                  class="btn btn-secondary w-full block text-center"
                >
                  View Asterisk Console Logs
                </NuxtLink>
              </div>
            </div>

            <div class="text-sm text-gray-600 dark:text-gray-400">
              <p><strong>Note:</strong> After making changes, the device may need to be restarted or re-registered manually.</p>
            </div>
          </div>

          <div class="flex justify-end mt-6">
            <button @click="offlineHelpModal = false" class="btn btn-secondary">
              Close
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
const { t } = useI18n()
const api = useApi()
const authStore = useAuthStore()
const router = useRouter()

const extensions = ref<any[]>([])
const loading = ref(false)
const showModal = ref(false)
const editMode = ref(false)
const saving = ref(false)

// Sorting and filtering state
const searchQuery = ref('')
const statusFilter = ref('')
const sortField = ref('extension_number')
const sortDirection = ref<'asc' | 'desc'>('asc')

// Offline help modal
const offlineHelpModal = ref(false)
const selectedExtension = ref<any>(null)

const form = ref({
  id: null,
  extension_number: '',
  name: '',
  email: '',
  secret: '',
  enabled: true,
  notes: '',
})

// Computed property for filtered and sorted extensions
const filteredExtensions = computed(() => {
  let result = [...extensions.value]

  // Apply search filter
  if (searchQuery.value) {
    const query = searchQuery.value.toLowerCase()
    result = result.filter(ext => 
      ext.extension_number.toLowerCase().includes(query) ||
      ext.name.toLowerCase().includes(query) ||
      ext.email?.toLowerCase().includes(query)
    )
  }

  // Apply status filter
  if (statusFilter.value) {
    result = result.filter(ext => {
      if (statusFilter.value === 'registered') {
        return ext.registered === true
      } else if (statusFilter.value === 'offline') {
        return ext.registered !== true
      }
      return true
    })
  }

  // Apply sorting
  result.sort((a, b) => {
    let aVal = a[sortField.value]
    let bVal = b[sortField.value]

    // Special handling for status field
    if (sortField.value === 'status') {
      aVal = a.registered ? 'registered' : 'offline'
      bVal = b.registered ? 'registered' : 'offline'
    }

    // Handle null/undefined values
    if (aVal == null) aVal = ''
    if (bVal == null) bVal = ''

    // String comparison
    const comparison = String(aVal).localeCompare(String(bVal))
    return sortDirection.value === 'asc' ? comparison : -comparison
  })

  return result
})

const statusClass = (status: string) => {
  switch (status) {
    case 'registered':
    case 'online':
      return 'text-green-600 font-semibold'
    case 'offline':
      return 'text-red-600'
    default:
      return 'text-yellow-600'
  }
}

const sortBy = (field: string) => {
  if (sortField.value === field) {
    // Toggle direction if same field
    sortDirection.value = sortDirection.value === 'asc' ? 'desc' : 'asc'
  } else {
    // New field, default to ascending
    sortField.value = field
    sortDirection.value = 'asc'
  }
}

const showOfflineHelp = (ext: any) => {
  selectedExtension.value = ext
  offlineHelpModal.value = true
}

const enableExtension = async (ext: any) => {
  try {
    await api.updateExtension(ext.id, { ...ext, enabled: true })
    offlineHelpModal.value = false
    // WebSocket will trigger refresh
  } catch (error) {
    console.error('Error enabling extension:', error)
  }
}

const fetchExtensions = async () => {
  loading.value = true
  try {
    const response = await api.getExtensions()
    extensions.value = response.extensions
    
    // Fetch live status for each extension
    await enrichWithLiveStatus()
  } catch (error) {
    console.error('Error fetching extensions:', error)
  }
  loading.value = false
}

const enrichWithLiveStatus = async () => {
  // Fetch all endpoint statuses from Asterisk
  try {
    const statusResponse = await api.apiFetch('/asterisk/endpoints')
    if (statusResponse.success && statusResponse.endpoints) {
      // Match endpoints with extensions
      extensions.value = extensions.value.map(ext => {
        const endpoint = statusResponse.endpoints.find(
          e => e.endpoint === ext.extension_number
        )
        
        if (endpoint) {
          return {
            ...ext,
            registered: endpoint.registered,
            status: endpoint.status,
            ip_address: endpoint.ip_address,
            port: endpoint.port,
            latency_ms: endpoint.last_qualify_ms,
            device_state: endpoint.device_state,
            hd_codec: false, // Will be determined from codecs
            codec_info: endpoint.codecs?.join(', '),
          }
        }
        
        return ext
      })
      
      // Check for HD codecs
      extensions.value = extensions.value.map(ext => {
        const hdCodecs = ['g722', 'opus', 'silk', 'speex16', 'slin16', 'g722.2']
        const hasHD = ext.codec_info && hdCodecs.some(c => 
          ext.codec_info.toLowerCase().includes(c)
        )
        
        return {
          ...ext,
          hd_codec: hasHD
        }
      })
    }
  } catch (error) {
    console.error('Error fetching live status:', error)
  }
}

const editExtension = (ext: any) => {
  form.value = {
    id: ext.id,
    extension_number: ext.extension_number,
    name: ext.name,
    email: ext.email || '',
    secret: '',
    enabled: ext.enabled,
    notes: ext.notes || '',
  }
  editMode.value = true
  showModal.value = true
  offlineHelpModal.value = false
}

const deleteExtension = async (ext: any) => {
  if (!confirm(t('extensions.deleteConfirm'))) return

  try {
    await api.deleteExtension(ext.id)
    // WebSocket will trigger removal via event
  } catch (error) {
    console.error('Error deleting extension:', error)
  }
}

const saveExtension = async () => {
  saving.value = true
  try {
    if (editMode.value) {
      await api.updateExtension(form.value.id!, form.value)
    } else {
      await api.createExtension(form.value)
    }
    showModal.value = false
    resetForm()
    // WebSocket will trigger refresh via event
  } catch (error) {
    console.error('Error saving extension:', error)
  }
  saving.value = false
}

const resetForm = () => {
  form.value = {
    id: null,
    extension_number: '',
    name: '',
    email: '',
    secret: '',
    enabled: true,
    notes: '',
  }
  editMode.value = false
}

onMounted(async () => {
  await authStore.checkAuth()
  if (!authStore.isAuthenticated) {
    router.push('/login')
    return
  }
  await fetchExtensions()
  
  // Connect to WebSocket
  const ws = useWebSocket()
  ws.connect()
  
  // Listen for extension events
  ws.on('extension.created', async (payload) => {
    console.log('Extension created:', payload)
    await fetchExtensions()
  })
  
  ws.on('extension.updated', async (payload) => {
    console.log('Extension updated:', payload)
    await fetchExtensions()
  })
  
  ws.on('extension.deleted', (payload) => {
    console.log('Extension deleted:', payload)
    // Remove from local list
    extensions.value = extensions.value.filter(e => e.id !== payload.id)
  })
  
  // Auto-refresh live status every 10 seconds
  setInterval(enrichWithLiveStatus, 10000)
})
</script>
