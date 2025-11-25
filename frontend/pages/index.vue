<template>
  <div class="min-h-screen bg-gray-50 dark:bg-gray-900">
    <!-- Navigation -->
    <nav class="bg-white dark:bg-gray-800 shadow-lg">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
          <div class="flex items-center">
            <h1 class="text-2xl font-bold text-blue-600 dark:text-blue-400">
              {{ $t('app.title') }}
            </h1>
          </div>

          <div class="flex items-center space-x-4">
            <!-- Language Switcher -->
            <button
              @click="toggleLocale"
              class="btn btn-secondary text-sm"
            >
              {{ locale === 'en' ? 'فارسی' : 'English' }}
            </button>

            <!-- Dark Mode Toggle -->
            <button
              @click="toggleColorMode"
              class="btn btn-secondary"
            >
              <span v-if="colorMode === 'dark'">☀️</span>
              <span v-else>🌙</span>
            </button>

            <!-- Logout -->
            <button
              @click="handleLogout"
              class="btn btn-secondary"
            >
              {{ $t('auth.logout') }}
            </button>
          </div>
        </div>
      </div>
    </nav>

    <!-- Main Content -->
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
      <!-- Asterisk Errors Alert -->
      <div v-if="asteriskErrors.length > 0" class="mb-6">
        <div class="bg-red-100 dark:bg-red-900 border-l-4 border-red-500 p-4 rounded-lg">
          <div class="flex items-start">
            <div class="flex-shrink-0">
              <span class="text-2xl">⚠️</span>
            </div>
            <div class="ml-3 flex-1">
              <h3 class="text-lg font-medium text-red-800 dark:text-red-200">
                Asterisk Service Errors
              </h3>
              <div class="mt-2 text-sm text-red-700 dark:text-red-300">
                <p class="mb-2">The Asterisk service has encountered errors:</p>
                <ul class="list-disc list-inside space-y-1 max-h-40 overflow-y-auto">
                  <li v-for="(error, index) in asteriskErrors.slice(0, 5)" :key="index" class="font-mono text-xs">
                    {{ error.context }}: {{ error.message?.substring(0, 100) }}{{ error.message?.length > 100 ? '...' : '' }}
                  </li>
                </ul>
                <button 
                  @click="showErrorDetails = !showErrorDetails"
                  class="mt-2 text-red-600 dark:text-red-400 underline text-sm"
                >
                  {{ showErrorDetails ? 'Hide Details' : 'Show Details' }}
                </button>
              </div>
              <div v-if="showErrorDetails" class="mt-4 p-3 bg-gray-900 rounded text-white font-mono text-xs overflow-x-auto max-h-60 overflow-y-auto">
                <div v-for="(error, index) in asteriskErrors" :key="'detail-' + index" class="mb-4">
                  <div class="text-yellow-400">{{ error.timestamp }} - {{ error.context }}</div>
                  <pre class="whitespace-pre-wrap text-gray-300">{{ error.message }}</pre>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Status Cards -->
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
        <div class="card">
          <div class="flex items-center justify-between">
            <div>
              <p class="text-sm text-gray-600 dark:text-gray-400">{{ $t('dashboard.asterisk') }}</p>
              <p class="text-2xl font-bold" :class="statusColor(status?.asterisk)">
                {{ $t(`status.${status?.asterisk}`) }}
              </p>
            </div>
            <div class="text-4xl">📞</div>
          </div>
        </div>

        <div class="card">
          <div class="flex items-center justify-between">
            <div>
              <p class="text-sm text-gray-600 dark:text-gray-400">{{ $t('dashboard.database') }}</p>
              <p class="text-2xl font-bold text-green-600">
                {{ $t(`status.${status?.database}`) }}
              </p>
            </div>
            <div class="text-4xl">💾</div>
          </div>
        </div>

        <div class="card">
          <div class="flex items-center justify-between">
            <div>
              <p class="text-sm text-gray-600 dark:text-gray-400">{{ $t('dashboard.extensionsTitle') }}</p>
              <p class="text-2xl font-bold text-blue-600">
                {{ status?.extensions?.active || 0 }} / {{ status?.extensions?.total || 0 }}
              </p>
            </div>
            <div class="text-4xl">👥</div>
          </div>
        </div>

        <div class="card">
          <div class="flex items-center justify-between">
            <div>
              <p class="text-sm text-gray-600 dark:text-gray-400">{{ $t('dashboard.trunksTitle') }}</p>
              <p class="text-2xl font-bold text-purple-600">
                {{ status?.trunks?.active || 0 }} / {{ status?.trunks?.total || 0 }}
              </p>
            </div>
            <div class="text-4xl">🌐</div>
          </div>
        </div>
      </div>

      <!-- Quick Actions -->
      <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-5 gap-6">
        <NuxtLink to="/extensions" class="card hover:shadow-xl cursor-pointer transition-shadow">
          <div class="text-center">
            <div class="text-5xl mb-4">📱</div>
            <h3 class="text-xl font-bold mb-2">{{ $t('nav.extensions') }}</h3>
            <p class="text-gray-600 dark:text-gray-400">{{ $t('dashboard.manageExtensions') }}</p>
          </div>
        </NuxtLink>

        <NuxtLink to="/phones" class="card hover:shadow-xl cursor-pointer transition-shadow">
          <div class="text-center">
            <div class="text-5xl mb-4">☎️</div>
            <h3 class="text-xl font-bold mb-2">{{ $t('nav.phones') }}</h3>
            <p class="text-gray-600 dark:text-gray-400">{{ $t('dashboard.managePhones') }}</p>
          </div>
        </NuxtLink>

        <NuxtLink to="/trunks" class="card hover:shadow-xl cursor-pointer transition-shadow">
          <div class="text-center">
            <div class="text-5xl mb-4">🔗</div>
            <h3 class="text-xl font-bold mb-2">{{ $t('nav.trunks') }}</h3>
            <p class="text-gray-600 dark:text-gray-400">{{ $t('dashboard.configureTrunks') }}</p>
          </div>
        </NuxtLink>

        <NuxtLink to="/console" class="card hover:shadow-xl cursor-pointer transition-shadow">
          <div class="text-center">
            <div class="text-5xl mb-4">🖥️</div>
            <h3 class="text-xl font-bold mb-2">{{ $t('nav.console') }}</h3>
            <p class="text-gray-600 dark:text-gray-400">{{ $t('dashboard.asteriskCLI') }}</p>
          </div>
        </NuxtLink>

        <NuxtLink to="/logs" class="card hover:shadow-xl cursor-pointer transition-shadow">
          <div class="text-center">
            <div class="text-5xl mb-4">📋</div>
            <h3 class="text-xl font-bold mb-2">{{ $t('nav.logs') }}</h3>
            <p class="text-gray-600 dark:text-gray-400">{{ $t('dashboard.viewLogs') }}</p>
          </div>
        </NuxtLink>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
definePageMeta({
  middleware: 'auth'
})

const { t, locale } = useI18n()
const authStore = useAuthStore()
const router = useRouter()
const api = useApi()
const colorMode = useColorMode()

const status = ref<any>(null)
const loading = ref(false)
const asteriskErrors = ref<any[]>([])
const showErrorDetails = ref(false)

const toggleLocale = () => {
  locale.value = locale.value === 'en' ? 'fa' : 'en'
  if (process.client) {
    document.documentElement.setAttribute('dir', locale.value === 'fa' ? 'rtl' : 'ltr')
    document.documentElement.setAttribute('lang', locale.value)
  }
}

const toggleColorMode = () => {
  colorMode.preference = colorMode.value === 'dark' ? 'light' : 'dark'
}

const handleLogout = async () => {
  await authStore.logout()
  router.push('/login')
}

const statusColor = (status: string) => {
  switch (status) {
    case 'running':
    case 'connected':
    case 'online':
      return 'text-green-600'
    case 'stopped':
    case 'offline':
      return 'text-red-600'
    default:
      return 'text-yellow-600'
  }
}

const fetchStatus = async () => {
  loading.value = true
  try {
    const response = await api.getStatus()
    status.value = response.status
    
    // Fetch Asterisk errors if service is not running
    if (response.status?.asterisk !== 'running') {
      await fetchAsteriskErrors()
    } else {
      asteriskErrors.value = []
    }
  } catch (error) {
    console.error('Error fetching status:', error)
  }
  loading.value = false
}

const fetchAsteriskErrors = async () => {
  try {
    const response = await api.get('/asterisk/errors')
    if (response.errors && response.errors.length > 0) {
      asteriskErrors.value = response.errors
    }
  } catch (error) {
    console.error('Error fetching Asterisk errors:', error)
  }
}

onMounted(async () => {
  // Initialize auth state
  await authStore.checkAuth()
  
  // Fetch initial status
  await fetchStatus()
  
  // Connect to WebSocket
  const ws = useWebSocket()
  ws.connect()
  
  // Listen for status updates
  ws.on('status_update', (payload) => {
    console.log('Status update:', payload)
    if (status.value) {
      // Update extension and trunk counts
      if (status.value.extensions && payload.extensions !== undefined) {
        status.value.extensions.active = payload.extensions
      }
      if (status.value.trunks && payload.trunks !== undefined) {
        status.value.trunks.active = payload.trunks
      }
    }
  })
  
  // Fallback: Refresh status every 30 seconds (reduced from 5)
  setInterval(fetchStatus, 30000)
})
</script>
