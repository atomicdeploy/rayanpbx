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
      <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <NuxtLink to="/extensions" class="card hover:shadow-xl cursor-pointer">
          <div class="text-center">
            <div class="text-5xl mb-4">📱</div>
            <h3 class="text-xl font-bold mb-2">{{ $t('nav.extensions') }}</h3>
            <p class="text-gray-600 dark:text-gray-400">Manage SIP extensions</p>
          </div>
        </NuxtLink>

        <NuxtLink to="/trunks" class="card hover:shadow-xl cursor-pointer">
          <div class="text-center">
            <div class="text-5xl mb-4">🔗</div>
            <h3 class="text-xl font-bold mb-2">{{ $t('nav.trunks') }}</h3>
            <p class="text-gray-600 dark:text-gray-400">Configure trunk routing</p>
          </div>
        </NuxtLink>

        <NuxtLink to="/logs" class="card hover:shadow-xl cursor-pointer">
          <div class="text-center">
            <div class="text-5xl mb-4">📋</div>
            <h3 class="text-xl font-bold mb-2">{{ $t('nav.logs') }}</h3>
            <p class="text-gray-600 dark:text-gray-400">View system logs</p>
          </div>
        </NuxtLink>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
const { t, locale } = useI18n()
const authStore = useAuthStore()
const router = useRouter()
const api = useApi()
const colorMode = useColorMode()

const status = ref<any>(null)
const loading = ref(false)

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
  } catch (error) {
    console.error('Error fetching status:', error)
  }
  loading.value = false
}

onMounted(async () => {
  await authStore.checkAuth()
  if (!authStore.isAuthenticated) {
    router.push('/login')
    return
  }

  await fetchStatus()
  
  // Refresh status every 5 seconds
  setInterval(fetchStatus, 5000)
})
</script>
