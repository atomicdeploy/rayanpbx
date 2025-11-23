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

const consoleOutput = ref<HTMLElement>()

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
  
  // Auto-refresh every 5 seconds
  setInterval(() => {
    fetchActiveCalls()
    fetchEndpoints()
  }, 5000)
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

.btn-sm {
  @apply px-2 py-1 text-xs rounded font-medium transition-all duration-200;
}
</style>
