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
            <span class="text-lg text-gray-700 dark:text-gray-300">🖥️ Asterisk Console</span>
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
      <!-- Live Console Toggle -->
      <div class="card mb-6">
        <div class="flex items-center justify-between mb-4">
          <h2 class="text-xl font-bold flex items-center">
            <span class="text-2xl mr-2">📡</span>
            Live Console
            <span v-if="liveMode" class="ml-3 px-2 py-1 bg-green-100 dark:bg-green-900 text-green-800 dark:text-green-200 rounded text-sm animate-pulse">
              🔴 LIVE
            </span>
          </h2>
          <div class="flex items-center space-x-4">
            <label class="flex items-center space-x-2">
              <span class="text-sm text-gray-600 dark:text-gray-400">Verbosity:</span>
              <select v-model="verbosity" class="input py-1 px-2 text-sm w-20" :disabled="liveMode">
                <option v-for="v in 10" :key="v" :value="v">{{ v }}</option>
              </select>
            </label>
            <button
              @click="toggleLiveMode"
              :class="liveMode ? 'btn bg-red-600 hover:bg-red-700 text-white' : 'btn btn-primary'"
            >
              {{ liveMode ? '⏹️ Stop' : '▶️ Start Live' }}
            </button>
          </div>
        </div>

        <!-- Live Console Box -->
        <div class="live-console-container">
          <div class="live-console-header">
            ╭────────────────────────────────────────────────────────────────────────────────╮
            <span v-if="liveMode" class="text-green-400 ml-4">● Connected (verbosity: {{ verbosity }})</span>
            <span v-else class="text-gray-500 ml-4">○ Disconnected</span>
          </div>
          <div class="live-console-output" ref="liveConsoleOutput">
            <div v-if="liveOutput.length === 0" class="text-center py-4 text-gray-500">
              │ {{ liveMode ? 'Waiting for output...' : 'Click "Start Live" to stream Asterisk console' }} │
            </div>
            <div v-else>
              <div
                v-for="(line, index) in liveOutput"
                :key="index"
                class="live-console-line"
                :class="getLiveLineClass(line)"
              >
                <span class="console-border">│</span>
                <span v-if="line.timestamp" class="text-gray-500 text-xs mr-2">[{{ formatTimestamp(line.timestamp) }}]</span>
                <span v-if="line.level" class="uppercase text-xs font-bold mr-2" :class="getLevelBadgeClass(line.level)">{{ line.level }}</span>
                <span v-if="line.source" class="text-cyan-400 text-xs mr-2">{{ line.source }}:</span>
                <span class="console-content">{{ line.message }}</span>
              </div>
            </div>
          </div>
          <div class="live-console-footer">
            ╰────────────────────────────────────────────────────────────────────────────────╯
          </div>
        </div>

        <!-- Error Summary -->
        <div v-if="recentErrors.length > 0" class="mt-4">
          <h4 class="text-sm font-semibold mb-2 text-red-600 dark:text-red-400 flex items-center">
            <span class="mr-2">⚠️</span>
            Recent Errors ({{ recentErrors.length }})
          </h4>
          <div class="bg-red-50 dark:bg-red-900/20 rounded-lg p-3 max-h-32 overflow-y-auto">
            <div v-for="(error, idx) in recentErrors" :key="idx" class="text-sm text-red-700 dark:text-red-300 font-mono mb-1">
              {{ error.message }}
            </div>
          </div>
        </div>
      </div>

      <!-- Console Output Card -->
      <div class="card mb-6">
        <div class="flex items-center justify-between mb-4">
          <h2 class="text-xl font-bold flex items-center">
            <span class="text-2xl mr-2">🖥️</span>
            Asterisk Console
            <span v-if="asteriskVersion" class="ml-3 text-sm text-gray-500 dark:text-gray-400">
              v{{ asteriskVersion }}
            </span>
          </h2>
          <div class="flex space-x-2">
            <button @click="clearConsole" class="btn btn-secondary text-sm">
              🗑️ Clear
            </button>
            <button @click="fetchOutput" class="btn btn-primary text-sm">
              🔄 Refresh
            </button>
          </div>
        </div>

        <!-- Console Box with Unicode borders -->
        <div class="console-container">
          <div class="console-header">
            ╭─────────────────────────────────────────────────────────────╮
          </div>
          <div class="console-output" ref="consoleOutput">
            <div v-if="loading" class="text-center py-4 text-gray-500">
              ⏳ Loading...
            </div>
            <div v-else-if="output.length === 0" class="text-center py-4 text-gray-500">
              │ No output yet. Execute a command to see results.            │
            </div>
            <div v-else>
              <div
                v-for="(line, index) in output"
                :key="index"
                class="console-line"
                :class="getLineClass(line)"
              >
                <span class="console-border">│</span>
                <span class="console-content">{{ line.message }}</span>
              </div>
            </div>
          </div>
          <div class="console-footer">
            ╰─────────────────────────────────────────────────────────────╯
          </div>
        </div>
      </div>

      <!-- Command Input Card -->
      <div class="card">
        <h3 class="text-lg font-bold mb-4 flex items-center">
          <span class="text-xl mr-2">⌨️</span>
          Execute Command
        </h3>
        
        <form @submit.prevent="executeCommand" class="space-y-4">
          <div class="flex space-x-2">
            <div class="flex-1">
              <input
                v-model="command"
                type="text"
                class="input font-mono"
                placeholder="Enter Asterisk CLI command..."
                :disabled="executing"
                @keydown.up="navigateHistory(-1)"
                @keydown.down="navigateHistory(1)"
              />
            </div>
            <button
              type="submit"
              class="btn btn-primary"
              :disabled="executing || !command"
            >
              {{ executing ? '⏳ Executing...' : '▶️ Execute' }}
            </button>
          </div>
        </form>

        <!-- Quick Commands -->
        <div class="mt-4">
          <h4 class="text-sm font-semibold mb-2 text-gray-600 dark:text-gray-400">
            Quick Commands:
          </h4>
          <div class="flex flex-wrap gap-2">
            <button
              v-for="cmd in quickCommands"
              :key="cmd.command"
              @click="quickExecute(cmd.command)"
              class="btn-sm bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 px-3 py-1 rounded text-sm"
              :title="cmd.description"
            >
              {{ cmd.label }}
            </button>
          </div>
        </div>

        <!-- Command History -->
        <div v-if="commandHistory.length > 0" class="mt-4">
          <h4 class="text-sm font-semibold mb-2 text-gray-600 dark:text-gray-400">
            Command History:
          </h4>
          <div class="space-y-1">
            <div
              v-for="(cmd, index) in commandHistory.slice(-5).reverse()"
              :key="index"
              class="text-sm font-mono bg-gray-50 dark:bg-gray-800 p-2 rounded cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-700"
              @click="command = cmd"
            >
              {{ cmd }}
            </div>
          </div>
        </div>
      </div>

      <!-- Active Calls & Channels -->
      <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mt-6">
        <!-- Active Calls -->
        <div class="card">
          <h3 class="text-lg font-bold mb-4 flex items-center">
            <span class="text-xl mr-2">📞</span>
            Active Calls
            <span class="ml-2 px-2 py-1 bg-green-100 dark:bg-green-900 text-green-800 dark:text-green-200 rounded text-sm">
              {{ activeCalls.length }}
            </span>
          </h3>
          
          <div v-if="activeCalls.length === 0" class="text-center py-4 text-gray-500">
            No active calls
          </div>
          <div v-else class="space-y-2">
            <div
              v-for="(call, index) in activeCalls"
              :key="index"
              class="p-3 bg-gray-50 dark:bg-gray-800 rounded"
            >
              <div class="font-mono text-sm">{{ call.channel }}</div>
              <div class="text-xs text-gray-500">{{ call.state }} • {{ call.duration }}</div>
            </div>
          </div>
        </div>

        <!-- Endpoints -->
        <div class="card">
          <h3 class="text-lg font-bold mb-4 flex items-center">
            <span class="text-xl mr-2">🔌</span>
            PJSIP Endpoints
            <span class="ml-2 px-2 py-1 bg-blue-100 dark:bg-blue-900 text-blue-800 dark:text-blue-200 rounded text-sm">
              {{ endpoints.length }}
            </span>
          </h3>
          
          <div v-if="endpoints.length === 0" class="text-center py-4 text-gray-500">
            No endpoints configured
          </div>
          <div v-else class="space-y-2">
            <div
              v-for="(endpoint, index) in endpoints"
              :key="index"
              class="p-3 bg-gray-50 dark:bg-gray-800 rounded"
            >
              <div class="font-mono text-sm font-bold">{{ endpoint.endpoint }}</div>
              <div class="text-xs">
                <span :class="endpoint.state === 'Avail' ? 'text-green-600' : 'text-red-600'">
                  {{ endpoint.state }}
                </span>
                • {{ endpoint.contacts }} contacts
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

const command = ref('')
const executing = ref(false)
const loading = ref(false)
const output = ref<any[]>([])
const commandHistory = ref<string[]>([])
const historyIndex = ref(-1)
const asteriskVersion = ref('')
const activeCalls = ref<any[]>([])
const endpoints = ref<any[]>([])

// Live console state
const liveMode = ref(false)
const verbosity = ref(5)
const liveOutput = ref<any[]>([])
const recentErrors = ref<any[]>([])
const eventSource = ref<EventSource | null>(null)
const maxLiveLines = 500

const consoleOutput = ref<HTMLElement>()
const liveConsoleOutput = ref<HTMLElement>()

const quickCommands = [
  { label: '📊 Core Show Calls', command: 'core show calls', description: 'Show active calls' },
  { label: '🔌 PJSIP Endpoints', command: 'pjsip show endpoints', description: 'Show PJSIP endpoints' },
  { label: '📱 PJSIP Registrations', command: 'pjsip show registrations', description: 'Show registrations' },
  { label: '🔄 Core Reload', command: 'core reload', description: 'Reload Asterisk configuration' },
  { label: '📋 Dialplan Show', command: 'dialplan show', description: 'Show dialplan' },
  { label: 'ℹ️ Core Show Version', command: 'core show version', description: 'Show Asterisk version' },
]

const getLineClass = (line: any) => {
  if (!line.level) return ''
  
  switch (line.level.toLowerCase()) {
    case 'error':
      return 'text-red-600 dark:text-red-400'
    case 'warning':
    case 'warn':
      return 'text-yellow-600 dark:text-yellow-400'
    case 'notice':
      return 'text-blue-600 dark:text-blue-400'
    case 'debug':
      return 'text-gray-500 dark:text-gray-400'
    default:
      return 'text-green-600 dark:text-green-400'
  }
}

const getLiveLineClass = (line: any) => {
  if (line.isError) return 'bg-red-900/20'
  if (!line.level) return ''
  
  switch (line.level.toLowerCase()) {
    case 'error':
      return 'text-red-400 bg-red-900/20'
    case 'warning':
    case 'warn':
      return 'text-yellow-400'
    case 'notice':
      return 'text-blue-400'
    case 'debug':
      return 'text-gray-500'
    default:
      return 'text-green-400'
  }
}

const getLevelBadgeClass = (level: string) => {
  switch (level.toLowerCase()) {
    case 'error':
      return 'text-red-400'
    case 'warning':
    case 'warn':
      return 'text-yellow-400'
    case 'notice':
      return 'text-blue-400'
    case 'debug':
      return 'text-gray-500'
    default:
      return 'text-green-400'
  }
}

const formatTimestamp = (timestamp: string) => {
  // Return just the time portion for compact display
  if (timestamp.includes(' ')) {
    return timestamp.split(' ')[1] || timestamp
  }
  return timestamp
}

const toggleLiveMode = async () => {
  if (liveMode.value) {
    // Stop live mode
    stopLiveStream()
  } else {
    // Start live mode
    await startLiveStream()
  }
}

const startLiveStream = async () => {
  liveMode.value = true
  liveOutput.value = []
  
  try {
    // Fetch using fetch API with auth header for SSE
    const config = useRuntimeConfig()
    const url = `${config.public.apiBase}/console/live?verbosity=${verbosity.value}`
    
    const response = await fetch(url, {
      headers: {
        'Authorization': `Bearer ${authStore.token}`,
        'Accept': 'text/event-stream',
      },
    })
    
    if (!response.ok) {
      throw new Error(`HTTP error! status: ${response.status}`)
    }
    
    const reader = response.body?.getReader()
    const decoder = new TextDecoder()
    
    if (!reader) {
      throw new Error('No reader available')
    }
    
    // Read the stream
    while (liveMode.value) {
      const { done, value } = await reader.read()
      
      if (done) {
        break
      }
      
      const text = decoder.decode(value)
      const lines = text.split('\n')
      
      for (const line of lines) {
        if (line.startsWith('data: ')) {
          try {
            const data = JSON.parse(line.slice(6))
            handleLiveData(data)
          } catch (e) {
            // Ignore parsing errors
          }
        }
      }
    }
    
    reader.cancel()
  } catch (error: any) {
    console.error('Live stream error:', error)
    liveOutput.value.push({
      type: 'error',
      message: `Connection error: ${error.message}`,
      timestamp: new Date().toISOString(),
      isError: true,
    })
    liveMode.value = false
  }
}

const stopLiveStream = () => {
  liveMode.value = false
}

const handleLiveData = (data: any) => {
  // Add to live output
  liveOutput.value.push(data)
  
  // Keep only last N lines
  if (liveOutput.value.length > maxLiveLines) {
    liveOutput.value = liveOutput.value.slice(-maxLiveLines)
  }
  
  // Track errors
  if (data.isError || data.level === 'error' || data.level === 'warning') {
    recentErrors.value.push(data)
    if (recentErrors.value.length > 20) {
      recentErrors.value = recentErrors.value.slice(-20)
    }
  }
  
  // Auto-scroll
  scrollLiveToBottom()
}

const scrollLiveToBottom = () => {
  nextTick(() => {
    if (liveConsoleOutput.value) {
      liveConsoleOutput.value.scrollTop = liveConsoleOutput.value.scrollHeight
    }
  })
}

const fetchOutput = async () => {
  loading.value = true
  try {
    const response = await api.apiFetch('/console/output')
    if (response.success && response.logs) {
      output.value = response.logs
      scrollToBottom()
    }
  } catch (error) {
    console.error('Error fetching output:', error)
  }
  loading.value = false
}

const executeCommand = async () => {
  if (!command.value.trim()) return
  
  executing.value = true
  
  // Add to history
  commandHistory.value.push(command.value)
  historyIndex.value = -1
  
  try {
    const response = await api.apiFetch('/console/execute', {
      method: 'POST',
      body: { command: command.value },
    })
    
    if (response.success) {
      // Add command and output to console
      output.value.push({
        message: `> ${command.value}`,
        level: 'info',
        timestamp: new Date().toISOString(),
      })
      
      // Split output into lines
      const lines = response.output.split('\n')
      lines.forEach((line: string) => {
        if (line.trim()) {
          output.value.push({
            message: line,
            level: 'info',
            timestamp: new Date().toISOString(),
          })
        }
      })
    } else {
      output.value.push({
        message: `Error: ${response.error}`,
        level: 'error',
        timestamp: new Date().toISOString(),
      })
    }
    
    scrollToBottom()
    command.value = ''
  } catch (error: any) {
    console.error('Error executing command:', error)
    output.value.push({
      message: `Error: ${error.message || 'Unknown error'}`,
      level: 'error',
      timestamp: new Date().toISOString(),
    })
  }
  
  executing.value = false
}

const quickExecute = (cmd: string) => {
  command.value = cmd
  executeCommand()
}

const clearConsole = () => {
  output.value = []
}

const navigateHistory = (direction: number) => {
  if (commandHistory.value.length === 0) return
  
  historyIndex.value += direction
  
  if (historyIndex.value < 0) {
    historyIndex.value = 0
  } else if (historyIndex.value >= commandHistory.value.length) {
    historyIndex.value = commandHistory.value.length - 1
  }
  
  command.value = commandHistory.value[commandHistory.value.length - 1 - historyIndex.value]
}

const scrollToBottom = () => {
  nextTick(() => {
    if (consoleOutput.value) {
      consoleOutput.value.scrollTop = consoleOutput.value.scrollHeight
    }
  })
}

const fetchVersion = async () => {
  try {
    const response = await api.apiFetch('/console/version')
    asteriskVersion.value = response.version
  } catch (error) {
    console.error('Error fetching version:', error)
  }
}

const fetchActiveCalls = async () => {
  try {
    const response = await api.apiFetch('/console/calls')
    activeCalls.value = response.calls || []
  } catch (error) {
    console.error('Error fetching calls:', error)
  }
}

const fetchEndpoints = async () => {
  try {
    const response = await api.apiFetch('/console/endpoints')
    endpoints.value = response.endpoints || []
  } catch (error) {
    console.error('Error fetching endpoints:', error)
  }
}

const fetchRecentErrors = async () => {
  try {
    const response = await api.apiFetch('/console/errors?lines=500')
    if (response.success && response.errors) {
      recentErrors.value = response.errors.slice(-20)
    }
  } catch (error) {
    console.error('Error fetching errors:', error)
  }
}

onMounted(async () => {
  await authStore.checkAuth()
  if (!authStore.isAuthenticated) {
    router.push('/login')
    return
  }
  
  await fetchVersion()
  await fetchOutput()
  await fetchActiveCalls()
  await fetchEndpoints()
  await fetchRecentErrors()
  
  // Auto-refresh every 5 seconds
  setInterval(() => {
    fetchActiveCalls()
    fetchEndpoints()
  }, 5000)
})

onUnmounted(() => {
  stopLiveStream()
})
</script>

<style scoped>
.console-container {
  @apply bg-gray-900 text-green-400 font-mono text-sm rounded-lg overflow-hidden;
}

.console-header,
.console-footer {
  @apply text-cyan-400 px-4 py-1;
}

.console-output {
  @apply max-h-96 overflow-y-auto px-2 py-2;
  scrollbar-width: thin;
  scrollbar-color: #4a5568 #1a202c;
}

.console-output::-webkit-scrollbar {
  width: 8px;
}

.console-output::-webkit-scrollbar-track {
  @apply bg-gray-800;
}

.console-output::-webkit-scrollbar-thumb {
  @apply bg-gray-600 rounded;
}

.console-line {
  @apply flex items-start leading-relaxed;
}

.console-border {
  @apply text-cyan-400 mr-2 flex-shrink-0;
}

.console-content {
  @apply flex-1;
}

/* Live Console Styles */
.live-console-container {
  @apply bg-black text-green-400 font-mono text-sm rounded-lg overflow-hidden border border-green-800;
}

.live-console-header,
.live-console-footer {
  @apply text-green-600 px-4 py-1 flex items-center;
}

.live-console-output {
  @apply max-h-80 overflow-y-auto px-2 py-2;
  scrollbar-width: thin;
  scrollbar-color: #22c55e #000;
}

.live-console-output::-webkit-scrollbar {
  width: 8px;
}

.live-console-output::-webkit-scrollbar-track {
  @apply bg-gray-900;
}

.live-console-output::-webkit-scrollbar-thumb {
  @apply bg-green-800 rounded;
}

.live-console-line {
  @apply flex items-start leading-relaxed py-0.5;
}

.btn-sm {
  @apply px-2 py-1 text-xs rounded font-medium transition-all duration-200;
}
</style>
