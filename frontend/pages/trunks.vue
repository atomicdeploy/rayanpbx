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
            <span class="text-lg text-gray-700 dark:text-gray-300">{{ $t('trunks.title') }}</span>
          </div>
          <div class="flex items-center space-x-4">
            <button @click="showModal = true" class="btn btn-primary">
              {{ $t('trunks.add') }}
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

        <div v-else-if="trunks.length === 0" class="text-center py-8 text-gray-600 dark:text-gray-400">
          No trunks configured. Click "Add Trunk" to get started.
        </div>

        <table v-else class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
          <thead class="bg-gray-50 dark:bg-gray-800">
            <tr>
              <th class="px-6 py-3 text-start text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                {{ $t('trunks.name') }}
              </th>
              <th class="px-6 py-3 text-start text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                {{ $t('trunks.host') }}
              </th>
              <th class="px-6 py-3 text-start text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                {{ $t('trunks.prefix') }}
              </th>
              <th class="px-6 py-3 text-start text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                {{ $t('trunks.priority') }}
              </th>
              <th class="px-6 py-3 text-start text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                {{ $t('trunks.enabled') }}
              </th>
              <th class="px-6 py-3 text-end text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                {{ $t('trunks.actions') }}
              </th>
            </tr>
          </thead>
          <tbody class="bg-white dark:bg-gray-900 divide-y divide-gray-200 dark:divide-gray-700">
            <tr v-for="trunk in trunks" :key="trunk.id" class="hover:bg-gray-50 dark:hover:bg-gray-800">
              <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                {{ trunk.name }}
              </td>
              <td class="px-6 py-4 whitespace-nowrap text-sm">
                {{ trunk.host }}:{{ trunk.port }}
              </td>
              <td class="px-6 py-4 whitespace-nowrap text-sm">
                {{ trunk.prefix }}
              </td>
              <td class="px-6 py-4 whitespace-nowrap text-sm">
                {{ trunk.priority }}
              </td>
              <td class="px-6 py-4 whitespace-nowrap text-sm">
                <span v-if="trunk.enabled" class="text-green-600">✓</span>
                <span v-else class="text-red-600">✗</span>
              </td>
              <td class="px-6 py-4 whitespace-nowrap text-end text-sm font-medium space-x-2">
                <button @click="editTrunk(trunk)" class="text-blue-600 hover:text-blue-900">
                  ✏️
                </button>
                <button @click="deleteTrunk(trunk)" class="text-red-600 hover:text-red-900">
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
            {{ editMode ? $t('trunks.edit') : $t('trunks.add') }}
          </h2>

          <form @submit.prevent="saveTrunk" class="space-y-4">
            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="label">{{ $t('trunks.name') }}</label>
                <input v-model="form.name" type="text" class="input" required :disabled="editMode" />
              </div>
              <div>
                <label class="label">{{ $t('trunks.prefix') }}</label>
                <input v-model="form.prefix" type="text" class="input" required />
              </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="label">{{ $t('trunks.host') }}</label>
                <input v-model="form.host" type="text" class="input" required />
              </div>
              <div>
                <label class="label">{{ $t('trunks.port') }}</label>
                <input v-model="form.port" type="number" class="input" required />
              </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="label">{{ $t('trunks.username') }}</label>
                <input v-model="form.username" type="text" class="input" />
              </div>
              <div>
                <label class="label">{{ $t('trunks.password') }}</label>
                <input v-model="form.secret" type="password" class="input" />
              </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="label">{{ $t('trunks.priority') }}</label>
                <input v-model.number="form.priority" type="number" class="input" required />
              </div>
              <div>
                <label class="label">{{ $t('trunks.stripDigits') }}</label>
                <input v-model.number="form.strip_digits" type="number" class="input" required />
              </div>
            </div>

            <div>
              <label class="flex items-center space-x-2">
                <input v-model="form.enabled" type="checkbox" class="rounded" />
                <span class="text-sm">{{ $t('trunks.enabled') }}</span>
              </label>
            </div>

            <div>
              <label class="label">{{ $t('trunks.notes') }}</label>
              <textarea v-model="form.notes" class="input" rows="3"></textarea>
            </div>

            <div class="flex justify-end space-x-4">
              <button type="button" @click="showModal = false" class="btn btn-secondary">
                {{ $t('trunks.cancel') }}
              </button>
              <button type="submit" class="btn btn-primary" :disabled="saving">
                {{ saving ? $t('common.loading') : $t('trunks.save') }}
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

const trunks = ref<any[]>([])
const loading = ref(false)
const showModal = ref(false)
const editMode = ref(false)
const saving = ref(false)

const form = ref({
  id: null,
  name: '',
  host: '',
  port: 5060,
  username: '',
  secret: '',
  prefix: '9',
  strip_digits: 1,
  priority: 1,
  enabled: true,
  notes: '',
})

const fetchTrunks = async () => {
  loading.value = true
  try {
    const response = await api.getTrunks()
    trunks.value = response.trunks
  } catch (error) {
    console.error('Error fetching trunks:', error)
  }
  loading.value = false
}

const editTrunk = (trunk: any) => {
  form.value = {
    id: trunk.id,
    name: trunk.name,
    host: trunk.host,
    port: trunk.port,
    username: trunk.username || '',
    secret: '',
    prefix: trunk.prefix,
    strip_digits: trunk.strip_digits,
    priority: trunk.priority,
    enabled: trunk.enabled,
    notes: trunk.notes || '',
  }
  editMode.value = true
  showModal.value = true
}

const deleteTrunk = async (trunk: any) => {
  if (!confirm(t('trunks.deleteConfirm'))) return

  try {
    await api.deleteTrunk(trunk.id)
    // WebSocket will trigger removal via event
  } catch (error) {
    console.error('Error deleting trunk:', error)
  }
}

const saveTrunk = async () => {
  saving.value = true
  try {
    if (editMode.value) {
      await api.updateTrunk(form.value.id!, form.value)
    } else {
      await api.createTrunk(form.value)
    }
    showModal.value = false
    resetForm()
    // WebSocket will trigger refresh via event
  } catch (error) {
    console.error('Error saving trunk:', error)
  }
  saving.value = false
}

const resetForm = () => {
  form.value = {
    id: null,
    name: '',
    host: '',
    port: 5060,
    username: '',
    secret: '',
    prefix: '9',
    strip_digits: 1,
    priority: 1,
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
  await fetchTrunks()
  
  // Connect to WebSocket
  const ws = useWebSocket()
  ws.connect()
  
  // Listen for trunk events
  ws.on('trunk.created', async (payload) => {
    console.log('Trunk created:', payload)
    await fetchTrunks()
  })
  
  ws.on('trunk.updated', async (payload) => {
    console.log('Trunk updated:', payload)
    await fetchTrunks()
  })
  
  ws.on('trunk.deleted', (payload) => {
    console.log('Trunk deleted:', payload)
    // Remove from local list
    trunks.value = trunks.value.filter(t => t.id !== payload.id)
  })
})
</script>
