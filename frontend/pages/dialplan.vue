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
            <span class="text-lg text-gray-700 dark:text-gray-300">📜 Dialplan Management</span>
          </div>
          <div class="flex items-center space-x-4">
            <button @click="showCreateModal = true" class="btn btn-primary">
              ➕ Add Rule
            </button>
            <button @click="createDefaults" class="btn btn-secondary" title="Create default internal pattern">
              🔧 Create Defaults
            </button>
            <NuxtLink to="/" class="btn btn-secondary">
              ← {{ $t('nav.dashboard') }}
            </NuxtLink>
          </div>
        </div>
      </div>
    </nav>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
      <!-- Info Banner -->
      <div class="mb-6 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-700 rounded-lg p-4">
        <div class="flex items-start space-x-3">
          <span class="text-blue-600 dark:text-blue-400 text-2xl">📜</span>
          <div>
            <h3 class="font-semibold text-blue-700 dark:text-blue-300">Dialplan Configuration</h3>
            <p class="text-sm text-blue-600 dark:text-blue-400 mt-1">
              Manage how calls are routed in your PBX. Create rules for internal calls, outbound routing, and inbound call handling.
            </p>
          </div>
        </div>
      </div>

      <!-- Error/Success Messages -->
      <div v-if="errorMessage" class="mb-4 p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-700 rounded-lg">
        <div class="flex items-center justify-between">
          <span class="text-red-600 dark:text-red-400">❌ {{ errorMessage }}</span>
          <button @click="errorMessage = ''" class="text-red-500 hover:text-red-700">✕</button>
        </div>
      </div>

      <div v-if="successMessage" class="mb-4 p-4 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-700 rounded-lg">
        <div class="flex items-center justify-between">
          <span class="text-green-600 dark:text-green-400">✅ {{ successMessage }}</span>
          <button @click="successMessage = ''" class="text-green-500 hover:text-green-700">✕</button>
        </div>
      </div>

      <!-- Quick Actions -->
      <div class="mb-6 flex gap-4">
        <button @click="previewDialplan" class="btn btn-secondary">
          👁️ Preview Dialplan
        </button>
        <button @click="applyDialplan" class="btn bg-green-600 hover:bg-green-700 text-white" :disabled="applying">
          {{ applying ? '⏳ Applying...' : '🚀 Apply to Asterisk' }}
        </button>
        <button @click="viewLiveDialplan" class="btn btn-secondary">
          📡 View Live Dialplan
        </button>
        <button @click="showPatternHelp = true" class="btn btn-secondary">
          ❓ Pattern Help
        </button>
      </div>

      <!-- Rules Table -->
      <div class="card">
        <h2 class="text-xl font-bold mb-4 text-gray-700 dark:text-gray-300">
          📋 Dialplan Rules
          <span class="text-sm font-normal text-gray-500">({{ rules.length }} rules)</span>
        </h2>

        <div v-if="loading" class="text-center py-8">
          ⏳ Loading rules...
        </div>

        <div v-else-if="rules.length === 0" class="text-center py-8 text-gray-600 dark:text-gray-400">
          <p class="text-lg mb-4">No dialplan rules configured yet.</p>
          <button @click="createDefaults" class="btn btn-primary">
            🔧 Create Default Rules
          </button>
        </div>

        <div v-else class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
            <thead class="bg-gray-50 dark:bg-gray-800">
              <tr>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Context</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Pattern</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Application</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
              <tr v-for="rule in rules" :key="rule.id" 
                  class="hover:bg-gray-50 dark:hover:bg-gray-800"
                  :class="{ 'opacity-50': !rule.enabled }">
                <td class="px-4 py-3 whitespace-nowrap">
                  <button 
                    @click="toggleRule(rule)"
                    class="relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200"
                    :class="rule.enabled ? 'bg-green-500' : 'bg-gray-300'"
                    :title="rule.enabled ? 'Click to disable' : 'Click to enable'"
                  >
                    <span
                      :class="[
                        'pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow transition',
                        rule.enabled ? 'translate-x-5' : 'translate-x-0'
                      ]"
                    />
                  </button>
                </td>
                <td class="px-4 py-3 whitespace-nowrap font-medium text-gray-900 dark:text-gray-100">
                  {{ rule.name }}
                </td>
                <td class="px-4 py-3 whitespace-nowrap">
                  <span class="px-2 py-1 text-xs bg-blue-100 dark:bg-blue-900 text-blue-800 dark:text-blue-200 rounded">
                    {{ rule.context }}
                  </span>
                </td>
                <td class="px-4 py-3 whitespace-nowrap font-mono text-sm">
                  {{ rule.pattern }}
                </td>
                <td class="px-4 py-3 whitespace-nowrap text-sm">
                  <span class="font-medium">{{ rule.app }}</span>
                  <span v-if="rule.app_data" class="text-gray-500 dark:text-gray-400 text-xs block">
                    {{ truncate(rule.app_data, 40) }}
                  </span>
                </td>
                <td class="px-4 py-3 whitespace-nowrap">
                  <span :class="typeClass(rule.rule_type)" class="px-2 py-1 text-xs rounded">
                    {{ rule.rule_type }}
                  </span>
                </td>
                <td class="px-4 py-3 whitespace-nowrap text-right text-sm space-x-2">
                  <button @click="editRule(rule)" class="text-blue-600 hover:text-blue-900" title="Edit">
                    ✏️
                  </button>
                  <button @click="deleteRule(rule)" class="text-red-600 hover:text-red-900" title="Delete">
                    🗑️
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Create/Edit Modal -->
    <div v-if="showCreateModal || showEditModal" class="fixed inset-0 z-50 overflow-y-auto" @click.self="closeModal">
      <div class="flex items-center justify-center min-h-screen px-4">
        <div class="fixed inset-0 bg-black opacity-50"></div>
        <div class="relative card max-w-2xl w-full max-h-[90vh] overflow-y-auto">
          <h2 class="text-2xl font-bold mb-6">
            {{ showEditModal ? '✏️ Edit Dialplan Rule' : '➕ Create Dialplan Rule' }}
          </h2>

          <form @submit.prevent="saveRule" class="space-y-4">
            <!-- Basic Info -->
            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="label">Rule Name *</label>
                <input v-model="form.name" type="text" class="input" required placeholder="e.g., Internal Extensions" />
              </div>
              <div>
                <label class="label">Context *</label>
                <select v-model="form.context" class="input" required>
                  <option value="from-internal">from-internal (Recommended)</option>
                  <option value="from-trunk">from-trunk</option>
                  <option value="outbound-routes">outbound-routes</option>
                </select>
              </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="label">Pattern *</label>
                <input v-model="form.pattern" type="text" class="input" required placeholder="e.g., _1XX or 101" />
                <p class="text-xs text-gray-500 mt-1">
                  Use _1XX for 100-199, _9X. for outbound with 9 prefix
                </p>
              </div>
              <div>
                <label class="label">Rule Type</label>
                <select v-model="form.rule_type" class="input">
                  <option value="pattern">Pattern (Generalized)</option>
                  <option value="internal">Internal Extension</option>
                  <option value="outbound">Outbound Route</option>
                  <option value="inbound">Inbound Route</option>
                  <option value="custom">Custom</option>
                </select>
              </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="label">Application *</label>
                <select v-model="form.app" class="input" required>
                  <option value="Dial">Dial</option>
                  <option value="NoOp">NoOp (Logging)</option>
                  <option value="Hangup">Hangup</option>
                  <option value="Answer">Answer</option>
                  <option value="VoiceMail">VoiceMail</option>
                  <option value="Playback">Playback</option>
                  <option value="Goto">Goto</option>
                  <option value="Queue">Queue</option>
                  <option value="Set">Set</option>
                </select>
              </div>
              <div>
                <label class="label">Priority</label>
                <input v-model.number="form.priority" type="number" class="input" min="1" max="999" />
              </div>
            </div>

            <div>
              <label class="label">Application Data</label>
              <input v-model="form.app_data" type="text" class="input" placeholder="e.g., PJSIP/${EXTEN},30" />
              <p class="text-xs text-gray-500 mt-1">
                For Dial: PJSIP/&dollar;{EXTEN},timeout. For VoiceMail: extension@context
              </p>
            </div>

            <div>
              <label class="label">Description</label>
              <textarea v-model="form.description" class="input" rows="2" placeholder="Optional description"></textarea>
            </div>

            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="label">Sort Order</label>
                <input v-model.number="form.sort_order" type="number" class="input" min="0" />
              </div>
              <div class="flex items-center pt-6">
                <label class="flex items-center space-x-2">
                  <input v-model="form.enabled" type="checkbox" class="rounded" />
                  <span>Enabled</span>
                </label>
              </div>
            </div>

            <!-- Preview -->
            <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4">
              <h3 class="font-semibold mb-2">Preview:</h3>
              <pre class="text-sm font-mono text-gray-600 dark:text-gray-400 overflow-x-auto">{{ generatePreview() }}</pre>
            </div>

            <div class="flex justify-end space-x-4">
              <button type="button" @click="closeModal" class="btn btn-secondary">
                Cancel
              </button>
              <button type="submit" class="btn btn-primary" :disabled="saving">
                {{ saving ? 'Saving...' : 'Save Rule' }}
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Pattern Help Modal -->
    <div v-if="showPatternHelp" class="fixed inset-0 z-50 overflow-y-auto" @click.self="showPatternHelp = false">
      <div class="flex items-center justify-center min-h-screen px-4">
        <div class="fixed inset-0 bg-black opacity-50"></div>
        <div class="relative card max-w-3xl w-full max-h-[90vh] overflow-y-auto">
          <h2 class="text-2xl font-bold mb-6">❓ Dialplan Pattern Reference</h2>

          <div class="space-y-4">
            <div class="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-4">
              <h3 class="font-semibold text-blue-700 dark:text-blue-300 mb-2">Pattern Characters</h3>
              <table class="w-full text-sm">
                <tbody>
                  <tr><td class="font-mono font-bold py-1">X</td><td>Matches any digit 0-9</td></tr>
                  <tr><td class="font-mono font-bold py-1">Z</td><td>Matches any digit 1-9</td></tr>
                  <tr><td class="font-mono font-bold py-1">N</td><td>Matches any digit 2-9</td></tr>
                  <tr><td class="font-mono font-bold py-1">[1-5]</td><td>Matches any digit in the range 1-5</td></tr>
                  <tr><td class="font-mono font-bold py-1">.</td><td>Wildcard: matches one or more characters</td></tr>
                  <tr><td class="font-mono font-bold py-1">!</td><td>Wildcard: matches zero or more characters</td></tr>
                  <tr><td class="font-mono font-bold py-1">_</td><td>Prefix indicating a pattern (required)</td></tr>
                </tbody>
              </table>
            </div>

            <div class="bg-green-50 dark:bg-green-900/20 rounded-lg p-4">
              <h3 class="font-semibold text-green-700 dark:text-green-300 mb-2">Common Patterns</h3>
              <table class="w-full text-sm">
                <tbody>
                  <tr><td class="font-mono font-bold py-1">100</td><td>Matches exactly 100</td></tr>
                  <tr><td class="font-mono font-bold py-1">_1XX</td><td>Matches 100-199 (3 digits starting with 1)</td></tr>
                  <tr><td class="font-mono font-bold py-1">_1XXX</td><td>Matches 1000-1999 (4 digits starting with 1)</td></tr>
                  <tr><td class="font-mono font-bold py-1">_NXX</td><td>Matches 200-999</td></tr>
                  <tr><td class="font-mono font-bold py-1">_9X.</td><td>Matches 9 followed by any number of digits</td></tr>
                  <tr><td class="font-mono font-bold py-1">_0X.</td><td>Matches 0 followed by any number of digits</td></tr>
                  <tr><td class="font-mono font-bold py-1">s</td><td>Start extension (for incoming calls without DID)</td></tr>
                </tbody>
              </table>
            </div>

            <div class="bg-yellow-50 dark:bg-yellow-900/20 rounded-lg p-4">
              <h3 class="font-semibold text-yellow-700 dark:text-yellow-300 mb-2">Variables</h3>
              <table class="w-full text-sm">
                <tbody>
                  <tr><td class="font-mono font-bold py-1">&dollar;{EXTEN}</td><td>The dialed extension number</td></tr>
                  <tr><td class="font-mono font-bold py-1">&dollar;{EXTEN:1}</td><td>Dialed number with first digit stripped</td></tr>
                  <tr><td class="font-mono font-bold py-1">&dollar;{CALLERID(num)}</td><td>Caller ID number</td></tr>
                  <tr><td class="font-mono font-bold py-1">&dollar;{CALLERID(name)}</td><td>Caller ID name</td></tr>
                </tbody>
              </table>
            </div>
          </div>

          <div class="flex justify-end mt-6">
            <button @click="showPatternHelp = false" class="btn btn-secondary">
              Close
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Preview Modal -->
    <div v-if="showPreviewModal" class="fixed inset-0 z-50 overflow-y-auto" @click.self="showPreviewModal = false">
      <div class="flex items-center justify-center min-h-screen px-4">
        <div class="fixed inset-0 bg-black opacity-50"></div>
        <div class="relative card max-w-4xl w-full max-h-[90vh] overflow-y-auto">
          <h2 class="text-2xl font-bold mb-6">👁️ Dialplan Preview</h2>

          <div class="bg-gray-900 rounded-lg p-4 overflow-x-auto">
            <pre class="text-sm text-green-400 font-mono whitespace-pre-wrap">{{ previewContent }}</pre>
          </div>

          <div class="flex justify-end mt-6">
            <button @click="showPreviewModal = false" class="btn btn-secondary">
              Close
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Live Dialplan Modal -->
    <div v-if="showLiveModal" class="fixed inset-0 z-50 overflow-y-auto" @click.self="showLiveModal = false">
      <div class="flex items-center justify-center min-h-screen px-4">
        <div class="fixed inset-0 bg-black opacity-50"></div>
        <div class="relative card max-w-4xl w-full max-h-[90vh] overflow-y-auto">
          <h2 class="text-2xl font-bold mb-6">📡 Live Asterisk Dialplan</h2>

          <div class="bg-gray-900 rounded-lg p-4 overflow-x-auto max-h-96 overflow-y-auto">
            <pre class="text-sm text-green-400 font-mono whitespace-pre-wrap">{{ liveDialplan }}</pre>
          </div>

          <div class="flex justify-end mt-6">
            <button @click="showLiveModal = false" class="btn btn-secondary">
              Close
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
const api = useApi()
const authStore = useAuthStore()
const router = useRouter()

const rules = ref<any[]>([])
const loading = ref(false)
const saving = ref(false)
const applying = ref(false)
const errorMessage = ref('')
const successMessage = ref('')

const showCreateModal = ref(false)
const showEditModal = ref(false)
const showPatternHelp = ref(false)
const showPreviewModal = ref(false)
const showLiveModal = ref(false)

const previewContent = ref('')
const liveDialplan = ref('')

const form = ref({
  id: null as number | null,
  name: '',
  context: 'from-internal',
  pattern: '',
  priority: 1,
  app: 'Dial',
  app_data: '',
  enabled: true,
  rule_type: 'pattern',
  description: '',
  sort_order: 0,
})

const typeClass = (type: string) => {
  switch (type) {
    case 'internal': return 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200'
    case 'outbound': return 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200'
    case 'inbound': return 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200'
    case 'pattern': return 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200'
    default: return 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200'
  }
}

const truncate = (str: string, len: number) => {
  if (!str) return ''
  return str.length > len ? str.substring(0, len) + '...' : str
}

const fetchRules = async () => {
  loading.value = true
  try {
    const response = await api.apiFetch('/dialplan')
    rules.value = response.rules || []
  } catch (error: any) {
    errorMessage.value = error.message || 'Failed to load dialplan rules'
  } finally {
    loading.value = false
  }
}

const resetForm = () => {
  form.value = {
    id: null,
    name: '',
    context: 'from-internal',
    pattern: '',
    priority: 1,
    app: 'Dial',
    app_data: '',
    enabled: true,
    rule_type: 'pattern',
    description: '',
    sort_order: 0,
  }
}

const closeModal = () => {
  showCreateModal.value = false
  showEditModal.value = false
  resetForm()
}

const editRule = (rule: any) => {
  form.value = { ...rule }
  showEditModal.value = true
}

const saveRule = async () => {
  saving.value = true
  errorMessage.value = ''
  try {
    if (form.value.id) {
      await api.apiFetch(`/dialplan/${form.value.id}`, {
        method: 'PUT',
        body: form.value,
      })
      successMessage.value = 'Rule updated successfully'
    } else {
      await api.apiFetch('/dialplan', {
        method: 'POST',
        body: form.value,
      })
      successMessage.value = 'Rule created successfully'
    }
    closeModal()
    await fetchRules()
  } catch (error: any) {
    errorMessage.value = error.data?.message || error.message || 'Failed to save rule'
  } finally {
    saving.value = false
  }
}

const deleteRule = async (rule: any) => {
  if (!confirm(`Delete rule "${rule.name}"?`)) return

  try {
    await api.apiFetch(`/dialplan/${rule.id}`, { method: 'DELETE' })
    successMessage.value = 'Rule deleted successfully'
    await fetchRules()
  } catch (error: any) {
    errorMessage.value = error.message || 'Failed to delete rule'
  }
}

const toggleRule = async (rule: any) => {
  try {
    const response = await api.apiFetch(`/dialplan/${rule.id}/toggle`, { method: 'POST' })
    rule.enabled = response.rule.enabled
    successMessage.value = rule.enabled ? 'Rule enabled' : 'Rule disabled'
  } catch (error: any) {
    errorMessage.value = error.message || 'Failed to toggle rule'
  }
}

const createDefaults = async () => {
  try {
    const response = await api.apiFetch('/dialplan/defaults', { method: 'POST' })
    successMessage.value = response.message
    await fetchRules()
  } catch (error: any) {
    errorMessage.value = error.message || 'Failed to create default rules'
  }
}

const previewDialplan = async () => {
  try {
    const response = await api.apiFetch('/dialplan/preview')
    previewContent.value = response.dialplan || 'No dialplan generated'
    showPreviewModal.value = true
  } catch (error: any) {
    errorMessage.value = error.message || 'Failed to preview dialplan'
  }
}

const viewLiveDialplan = async () => {
  try {
    const response = await api.apiFetch('/dialplan/live')
    liveDialplan.value = response.dialplan || 'No dialplan found'
    showLiveModal.value = true
  } catch (error: any) {
    errorMessage.value = error.message || 'Failed to get live dialplan'
  }
}

const applyDialplan = async () => {
  applying.value = true
  try {
    const response = await api.apiFetch('/dialplan/apply', { method: 'POST' })
    if (response.success) {
      successMessage.value = 'Dialplan applied successfully'
    } else {
      errorMessage.value = response.error || 'Failed to apply dialplan'
    }
  } catch (error: any) {
    errorMessage.value = error.message || 'Failed to apply dialplan'
  } finally {
    applying.value = false
  }
}

const generatePreview = () => {
  const lines = []
  if (form.value.description) {
    lines.push(`; ${form.value.description}`)
  }
  
  if (form.value.rule_type === 'pattern' || form.value.rule_type === 'internal') {
    lines.push(`exten => ${form.value.pattern},1,NoOp(${form.value.name}: \${EXTEN})`)
    if (form.value.app === 'Dial') {
      lines.push(` same => n,${form.value.app}(${form.value.app_data})`)
      lines.push(` same => n,Hangup()`)
    } else {
      const appData = form.value.app_data ? `(${form.value.app_data})` : ''
      lines.push(` same => n,${form.value.app}${appData}`)
    }
  } else {
    const appData = form.value.app_data ? `(${form.value.app_data})` : ''
    lines.push(`exten => ${form.value.pattern},${form.value.priority},${form.value.app}${appData}`)
  }
  
  return lines.join('\n')
}

onMounted(async () => {
  await authStore.checkAuth()
  if (!authStore.isAuthenticated) {
    router.push('/login')
    return
  }
  await fetchRules()
})
</script>
