<template>
  <div class="min-h-screen bg-gray-50 dark:bg-gray-900">
    <nav class="bg-white dark:bg-gray-800 shadow-lg">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
          <div class="flex items-center space-x-4">
            <NuxtLink to="/" class="text-2xl font-bold text-blue-600 dark:text-blue-400">
              {{ $t('app.title') }}
            </NuxtLink>
            <span class="text-gray-400">│</span>
            <span class="text-lg text-gray-700 dark:text-gray-300">📡 Traffic Analyzer</span>
          </div>
          <div class="flex items-center space-x-4">
            <NuxtLink to="/" class="btn btn-secondary">
              ← {{ $t('nav.dashboard') }}
            </NuxtLink>
          </div>
        </div>
      </div>
    </nav>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
      <!-- Status Card -->
      <div class="card mb-6">
        <div class="flex items-center justify-between mb-4">
          <div>
            <h2 class="text-2xl font-bold flex items-center">
              <span class="text-3xl mr-3">📡</span>
              Packet Capture Status
            </h2>
            <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">
              Real-time SIP & RTP traffic analysis using tcpdump
              <button @click="showHelp('traffic_analyzer')" class="text-blue-500 hover:text-blue-700 ml-2">
                ❓
              </button>
            </p>
          </div>
          <div class="flex items-center space-x-3">
            <span v-if="captureStatus.running" class="flex items-center">
              <span class="relative flex h-3 w-3">
                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
                <span class="relative inline-flex rounded-full h-3 w-3 bg-red-500"></span>
              </span>
              <span class="ml-2 text-red-600 dark:text-red-400 font-semibold">CAPTURING</span>
            </span>
            <span v-else class="text-gray-500 font-semibold">STOPPED</span>
          </div>
        </div>

        <!-- Capture Controls -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
          <div class="p-4 bg-gray-50 dark:bg-gray-800 rounded-lg">
            <h3 class="font-semibold mb-3 flex items-center">
              <span class="text-xl mr-2">⚙️</span>
              Capture Settings
            </h3>
            <div class="space-y-3">
              <div>
                <label class="label text-sm">SIP Port</label>
                <input v-model.number="captureSettings.port" type="number" class="input input-sm" :disabled="captureStatus.running" />
              </div>
              <div>
                <label class="label text-sm">RTP Port Range</label>
                <input v-model="captureSettings.rtp_port" type="text" class="input input-sm" :disabled="captureStatus.running" />
              </div>
              <div>
                <label class="label text-sm">Interface</label>
                <input v-model="captureSettings.interface" type="text" class="input input-sm" :disabled="captureStatus.running" />
              </div>
            </div>
          </div>

          <div class="p-4 bg-gray-50 dark:bg-gray-800 rounded-lg">
            <h3 class="font-semibold mb-3 flex items-center">
              <span class="text-xl mr-2">📊</span>
              Capture Statistics
            </h3>
            <div class="space-y-2">
              <div class="flex justify-between">
                <span class="text-sm text-gray-600 dark:text-gray-400">Packets Captured:</span>
                <span class="font-mono font-bold">{{ captureStatus.packets_captured || 0 }}</span>
              </div>
              <div class="flex justify-between">
                <span class="text-sm text-gray-600 dark:text-gray-400">File Size:</span>
                <span class="font-mono font-bold">{{ captureStatus.formatted_size || '0 B' }}</span>
              </div>
              <div class="flex justify-between">
                <span class="text-sm text-gray-600 dark:text-gray-400">PID:</span>
                <span class="font-mono text-sm">{{ captureStatus.pid || 'N/A' }}</span>
              </div>
            </div>
          </div>
        </div>

        <!-- Action Buttons -->
        <div class="flex space-x-3">
          <button
            @click="startCapture"
            :disabled="captureStatus.running || loading"
            class="btn btn-primary"
          >
            ▶️ Start Capture
          </button>
          <button
            @click="stopCapture"
            :disabled="!captureStatus.running || loading"
            class="btn btn-secondary"
          >
            ⏹️ Stop Capture
          </button>
          <button
            @click="analyzeTraffic"
            :disabled="captureStatus.running || loading"
            class="btn btn-secondary"
          >
            🔍 Analyze
          </button>
          <button
            @click="clearCapture"
            :disabled="captureStatus.running || loading"
            class="btn btn-secondary"
          >
            🗑️ Clear
          </button>
          <button
            @click="fetchStatus"
            :disabled="loading"
            class="btn btn-secondary"
          >
            🔄 Refresh
          </button>
        </div>
      </div>

      <!-- Analysis Results -->
      <div v-if="analysisResults" class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
        <!-- SIP Messages -->
        <div class="card">
          <h3 class="text-xl font-bold mb-4 flex items-center">
            <span class="text-2xl mr-2">📞</span>
            SIP Messages
            <span class="ml-2 px-2 py-1 bg-blue-100 dark:bg-blue-900 text-blue-800 dark:text-blue-200 rounded text-sm">
              {{ analysisResults.sip_messages.length }}
            </span>
          </h3>
          
          <div v-if="analysisResults.sip_messages.length === 0" class="text-center py-8 text-gray-500">
            No SIP messages captured
          </div>
          <div v-else class="space-y-2 max-h-96 overflow-y-auto">
            <div
              v-for="(msg, index) in analysisResults.sip_messages"
              :key="index"
              class="p-3 bg-gray-50 dark:bg-gray-800 rounded font-mono text-sm"
            >
              <div class="flex items-center justify-between">
                <span :class="getSipMethodClass(msg.method)" class="font-bold">
                  {{ msg.method }}
                </span>
                <span class="text-xs text-gray-500">{{ formatTimestamp(msg.timestamp) }}</span>
              </div>
              <div class="text-xs text-gray-600 dark:text-gray-400 mt-1">
                {{ msg.details }}
              </div>
            </div>
          </div>
        </div>

        <!-- RTP Streams -->
        <div class="card">
          <h3 class="text-xl font-bold mb-4 flex items-center">
            <span class="text-2xl mr-2">🎵</span>
            RTP Streams
          </h3>
          
          <div class="space-y-3">
            <div class="p-4 bg-gray-50 dark:bg-gray-800 rounded">
              <div class="flex justify-between mb-2">
                <span class="text-sm text-gray-600 dark:text-gray-400">Total RTP Packets:</span>
                <span class="font-bold">{{ analysisResults.rtp_streams.total_packets }}</span>
              </div>
              <div class="flex justify-between">
                <span class="text-sm text-gray-600 dark:text-gray-400">Estimated Streams:</span>
                <span class="font-bold">{{ analysisResults.rtp_streams.estimated_streams }}</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Traffic Statistics -->
      <div v-if="analysisResults" class="card">
        <h3 class="text-xl font-bold mb-4 flex items-center">
          <span class="text-2xl mr-2">📈</span>
          Traffic Statistics
        </h3>
        
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
          <div class="p-4 bg-gradient-to-br from-blue-50 to-blue-100 dark:from-blue-900 dark:to-blue-800 rounded-lg">
            <div class="text-sm text-gray-600 dark:text-gray-400 mb-1">Total Packets</div>
            <div class="text-2xl font-bold text-blue-600 dark:text-blue-400">
              {{ analysisResults.statistics.total_packets }}
            </div>
          </div>
          
          <div class="p-4 bg-gradient-to-br from-green-50 to-green-100 dark:from-green-900 dark:to-green-800 rounded-lg">
            <div class="text-sm text-gray-600 dark:text-gray-400 mb-1">SIP Packets</div>
            <div class="text-2xl font-bold text-green-600 dark:text-green-400">
              {{ analysisResults.statistics.sip_packets }}
            </div>
          </div>
          
          <div class="p-4 bg-gradient-to-br from-purple-50 to-purple-100 dark:from-purple-900 dark:to-purple-800 rounded-lg">
            <div class="text-sm text-gray-600 dark:text-gray-400 mb-1">RTP Packets</div>
            <div class="text-2xl font-bold text-purple-600 dark:text-purple-400">
              {{ analysisResults.statistics.rtp_packets }}
            </div>
          </div>
          
          <div class="p-4 bg-gradient-to-br from-orange-50 to-orange-100 dark:from-orange-900 dark:to-orange-800 rounded-lg">
            <div class="text-sm text-gray-600 dark:text-gray-400 mb-1">Capture Size</div>
            <div class="text-2xl font-bold text-orange-600 dark:text-orange-400">
              {{ formatBytes(analysisResults.statistics.file_size) }}
            </div>
          </div>
        </div>
      </div>

      <!-- Info Box -->
      <div class="card mt-6 bg-blue-50 dark:bg-blue-900 border-l-4 border-blue-500">
        <div class="flex items-start">
          <span class="text-3xl mr-3">💡</span>
          <div>
            <h4 class="font-bold text-blue-900 dark:text-blue-100 mb-2">How to use Traffic Analyzer</h4>
            <ul class="text-sm text-blue-800 dark:text-blue-200 space-y-1">
              <li>• Click "Start Capture" to begin capturing SIP and RTP packets</li>
              <li>• Make some test calls to generate traffic</li>
              <li>• Click "Stop Capture" when done</li>
              <li>• Click "Analyze" to view captured SIP messages and RTP streams</li>
              <li>• Use this tool to diagnose connectivity and call quality issues</li>
            </ul>
          </div>
        </div>
      </div>
    </div>

    <!-- Help Modal -->
    <div v-if="showHelpModal" class="fixed inset-0 z-50 overflow-y-auto" @click.self="showHelpModal = false">
      <div class="flex items-center justify-center min-h-screen px-4">
        <div class="fixed inset-0 bg-black opacity-50"></div>
        <div class="relative card max-w-2xl w-full">
          <h2 class="text-2xl font-bold mb-4">{{ helpContent.topic }}</h2>
          <div v-if="helpContent.loading" class="text-center py-8">
            <span class="text-4xl">⏳</span>
            <p class="mt-2">Getting help from AI...</p>
          </div>
          <div v-else class="prose dark:prose-invert">
            <p>{{ helpContent.explanation }}</p>
          </div>
          <button @click="showHelpModal = false" class="btn btn-primary mt-4">
            Close
          </button>
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

const captureStatus = ref<any>({
  running: false,
  pid: null,
  file_size: 0,
  packets_captured: 0,
  formatted_size: '0 B',
})

const captureSettings = ref({
  port: 5060,
  rtp_port: '10000-20000',
  interface: 'any',
})

const analysisResults = ref<any>(null)
const loading = ref(false)
const showHelpModal = ref(false)
const helpContent = ref({
  topic: '',
  explanation: '',
  loading: false,
})

const startCapture = async () => {
  loading.value = true
  try {
    const response = await api.apiFetch('/traffic/start', {
      method: 'POST',
      body: captureSettings.value,
    })
    
    if (response.success) {
      await fetchStatus()
    } else {
      alert('Failed to start capture: ' + response.error)
    }
  } catch (error) {
    console.error('Start capture error:', error)
  }
  loading.value = false
}

const stopCapture = async () => {
  loading.value = true
  try {
    const response = await api.apiFetch('/traffic/stop', {
      method: 'POST',
    })
    
    if (response.success) {
      await fetchStatus()
    }
  } catch (error) {
    console.error('Stop capture error:', error)
  }
  loading.value = false
}

const fetchStatus = async () => {
  try {
    const response = await api.apiFetch('/traffic/status')
    if (response.success) {
      captureStatus.value = response.status
    }
  } catch (error) {
    console.error('Fetch status error:', error)
  }
}

const analyzeTraffic = async () => {
  loading.value = true
  try {
    const response = await api.apiFetch('/traffic/analyze')
    if (response.success) {
      analysisResults.value = response
    } else {
      alert('Analysis failed: ' + response.error)
    }
  } catch (error) {
    console.error('Analyze error:', error)
  }
  loading.value = false
}

const clearCapture = async () => {
  if (!confirm('Clear capture file?')) return
  
  loading.value = true
  try {
    const response = await api.apiFetch('/traffic/clear', {
      method: 'POST',
    })
    
    if (response.success) {
      analysisResults.value = null
      await fetchStatus()
    }
  } catch (error) {
    console.error('Clear error:', error)
  }
  loading.value = false
}

const showHelp = async (topic: string) => {
  showHelpModal.value = true
  helpContent.value = {
    topic: topic.replace(/_/g, ' ').toUpperCase(),
    explanation: '',
    loading: true,
  }
  
  try {
    const response = await api.apiFetch('/help/explain', {
      method: 'POST',
      body: { topic, context: 'traffic analysis' },
    })
    
    helpContent.value.explanation = response.explanation
  } catch (error) {
    helpContent.value.explanation = 'Help system unavailable. Traffic analyzer captures SIP and RTP packets for debugging call issues.'
  }
  helpContent.value.loading = false
}

const getSipMethodClass = (method: string) => {
  const classes: any = {
    'INVITE': 'text-green-600',
    'BYE': 'text-red-600',
    'REGISTER': 'text-blue-600',
    'ACK': 'text-purple-600',
    'OPTIONS': 'text-yellow-600',
    'TRYING': 'text-gray-600',
    'RINGING': 'text-orange-600',
    'OK': 'text-green-600',
  }
  return classes[method] || 'text-gray-600'
}

const formatTimestamp = (ts: number) => {
  return new Date(ts * 1000).toLocaleTimeString()
}

const formatBytes = (bytes: number) => {
  const units = ['B', 'KB', 'MB', 'GB']
  let index = 0
  let size = bytes
  
  while (size >= 1024 && index < units.length - 1) {
    size /= 1024
    index++
  }
  
  return `${size.toFixed(2)} ${units[index]}`
}

onMounted(async () => {
  await authStore.checkAuth()
  if (!authStore.isAuthenticated) {
    router.push('/login')
    return
  }
  
  await fetchStatus()
  
  // Auto-refresh status every 3 seconds
  setInterval(fetchStatus, 3000)
})
</script>
