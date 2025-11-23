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
        <div v-if="loading" class="text-center py-8">
          {{ $t('common.loading') }}
        </div>

        <div v-else-if="extensions.length === 0" class="text-center py-8 text-gray-600 dark:text-gray-400">
          No extensions configured. Click "Add Extension" to get started.
        </div>

        <table v-else class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
          <thead class="bg-gray-50 dark:bg-gray-800">
            <tr>
              <th class="px-6 py-3 text-start text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                {{ $t('extensions.number') }}
              </th>
              <th class="px-6 py-3 text-start text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                {{ $t('extensions.name') }}
              </th>
              <th class="px-6 py-3 text-start text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                {{ $t('extensions.status') }}
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
            <tr v-for="ext in extensions" :key="ext.id" class="hover:bg-gray-50 dark:hover:bg-gray-800">
              <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
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
                  
                  <span :class="statusClass(ext.status)">
                    {{ ext.registered ? '🟢 Registered' : '⚫ Offline' }}
                  </span>
                  
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

const form = ref({
  id: null,
  extension_number: '',
  name: '',
  email: '',
  secret: '',
  enabled: true,
  notes: '',
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
}

const deleteExtension = async (ext: any) => {
  if (!confirm(t('extensions.deleteConfirm'))) return

  try {
    await api.deleteExtension(ext.id)
    await fetchExtensions()
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
    await fetchExtensions()
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
  
  // Auto-refresh live status every 10 seconds
  setInterval(enrichWithLiveStatus, 10000)
})
</script>
