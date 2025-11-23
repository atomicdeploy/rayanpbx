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
            <span class="text-lg text-gray-700 dark:text-gray-300">{{ $t('logs.title') }}</span>
          </div>
          <div class="flex items-center space-x-4">
            <button @click="fetchLogs" class="btn btn-secondary">
              🔄 {{ $t('logs.refresh') }}
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

        <div v-else-if="logs.length === 0" class="text-center py-8 text-gray-600 dark:text-gray-400">
          No logs available.
        </div>

        <div v-else class="space-y-2 max-h-[70vh] overflow-y-auto">
          <div
            v-for="(log, index) in logs"
            :key="index"
            class="p-3 rounded-lg bg-gray-50 dark:bg-gray-800 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors"
          >
            <div class="flex items-start space-x-3">
              <span :class="levelClass(log.level)" class="text-xs font-bold uppercase">
                {{ log.level }}
              </span>
              <div class="flex-1 min-w-0">
                <div class="flex items-center space-x-2 text-xs text-gray-500 dark:text-gray-400 mb-1">
                  <span>{{ log.timestamp }}</span>
                  <span v-if="log.source">{{ log.source }}</span>
                </div>
                <p class="text-sm text-gray-900 dark:text-gray-100 font-mono break-all">
                  {{ log.message }}
                </p>
              </div>
            </div>
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

const logs = ref<any[]>([])
const loading = ref(false)

const levelClass = (level: string) => {
  switch (level?.toLowerCase()) {
    case 'error':
      return 'text-red-600'
    case 'warning':
    case 'warn':
      return 'text-yellow-600'
    case 'notice':
      return 'text-blue-600'
    case 'debug':
      return 'text-gray-600'
    default:
      return 'text-green-600'
  }
}

const fetchLogs = async () => {
  loading.value = true
  try {
    const response = await api.getLogs({ lines: 100 })
    logs.value = response.logs.reverse()
  } catch (error) {
    console.error('Error fetching logs:', error)
  }
  loading.value = false
}

onMounted(async () => {
  await authStore.checkAuth()
  if (!authStore.isAuthenticated) {
    router.push('/login')
    return
  }
  await fetchLogs()
  
  // Auto-refresh every 5 seconds
  setInterval(fetchLogs, 5000)
})
</script>
