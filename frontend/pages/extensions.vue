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
        <!-- Page-level Error Banner -->
        <div v-if="pageError" class="mb-4 p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-700 rounded-lg">
          <div class="flex items-start space-x-3">
            <span class="text-red-600 dark:text-red-400 text-xl">❌</span>
            <div class="flex-1">
              <h3 class="font-semibold text-red-700 dark:text-red-300">{{ $t('common.error') }}</h3>
              <p class="text-sm text-red-600 dark:text-red-400 mt-1">{{ pageError }}</p>
              <button @click="retryLoad" class="mt-2 text-sm text-red-700 dark:text-red-300 hover:underline">
                🔄 {{ $t('common.retry') || 'Retry' }}
              </button>
            </div>
            <button @click="pageError = ''" class="text-red-500 hover:text-red-700">✕</button>
          </div>
        </div>

        <!-- Sync Status Banner -->
        <div v-if="syncStatus && (syncStatus.summary.db_only > 0 || syncStatus.summary.asterisk_only > 0 || syncStatus.summary.mismatched > 0)" 
             class="mb-4 p-4 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700 rounded-lg">
          <div class="flex items-center justify-between">
            <div class="flex items-center space-x-2">
              <span class="text-amber-600 dark:text-amber-400 text-xl">⚠️</span>
              <div>
                <h3 class="font-semibold text-amber-700 dark:text-amber-300">Sync Issues Detected</h3>
                <p class="text-sm text-amber-600 dark:text-amber-400">
                  <span v-if="syncStatus.summary.db_only > 0">{{ syncStatus.summary.db_only }} DB only • </span>
                  <span v-if="syncStatus.summary.asterisk_only > 0">{{ syncStatus.summary.asterisk_only }} Asterisk only • </span>
                  <span v-if="syncStatus.summary.mismatched > 0">{{ syncStatus.summary.mismatched }} mismatched</span>
                </p>
              </div>
            </div>
            <button @click="showSyncModal = true" class="btn bg-amber-600 hover:bg-amber-700 text-white">
              🔄 Open Sync Manager
            </button>
          </div>
        </div>

        <!-- All Synced Banner -->
        <div v-else-if="syncStatus && syncStatus.summary.matched > 0 && !loading" 
             class="mb-4 p-3 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-700 rounded-lg">
          <div class="flex items-center justify-between">
            <div class="flex items-center space-x-2">
              <span class="text-green-600 dark:text-green-400">✅</span>
              <span class="text-green-700 dark:text-green-300 text-sm">
                All {{ syncStatus.summary.matched }} extensions synced between database and Asterisk
              </span>
            </div>
            <button @click="refreshSyncStatus" class="text-green-600 hover:text-green-800 text-sm">
              🔄 Refresh
            </button>
          </div>
        </div>

        <!-- Search and Filter Controls -->
        <div v-if="!loading && extensions.length > 0" class="mb-4 flex gap-4">
          <input
            v-model="searchQuery"
            type="text"
            :placeholder="$t('common.search') + '...'"
            class="input flex-1"
          />
          <select v-model="statusFilter" class="input w-48">
            <option value="">{{ $t('extensions.allStatus') }}</option>
            <option value="registered">{{ $t('status.registered') }}</option>
            <option value="offline">{{ $t('status.offline') }}</option>
          </select>
          <button @click="showSyncModal = true" class="btn btn-secondary" title="Open Sync Manager">
            🔄 Sync
          </button>
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
                        @click="showOfflineHelp(ext)"
                        class="cursor-pointer hover:underline"
                        :class="statusClass(ext.status)"
                        :title="ext.registered ? 'Click for diagnostics and setup guide' : 'Click for setup and troubleshooting'"
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
                    <button 
                      @click="toggleExtension(ext)" 
                      :disabled="togglingExtension === ext.id"
                      :class="[
                        'relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2',
                        ext.enabled ? 'bg-green-500' : 'bg-gray-300',
                        togglingExtension === ext.id ? 'opacity-50 cursor-wait' : ''
                      ]"
                      role="switch"
                      :aria-checked="ext.enabled"
                      :title="ext.enabled ? 'Click to disable extension' : 'Click to enable extension'"
                    >
                      <span
                        :class="[
                          'pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out',
                          ext.enabled ? 'translate-x-5' : 'translate-x-0'
                        ]"
                      />
                    </button>
                    <span class="ml-2 text-xs" :class="ext.enabled ? 'text-green-600' : 'text-gray-500'">
                      {{ ext.enabled ? 'Enabled' : 'Disabled' }}
                    </span>
                  </td>
                  <td class="px-6 py-4 whitespace-nowrap text-end text-sm font-medium space-x-2">
                    <button @click="editExtension(ext)" class="text-blue-600 hover:text-blue-900" title="Edit extension">
                      ✏️
                    </button>
                    <button @click="deleteExtension(ext)" class="text-red-600 hover:text-red-900" title="Delete extension">
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
    <div v-if="showModal" class="fixed inset-0 z-50 overflow-y-auto" @click.self="closeModal">
      <div class="flex items-center justify-center min-h-screen px-4">
        <div class="fixed inset-0 bg-black opacity-50"></div>
        <div class="relative card max-w-3xl w-full max-h-[90vh] overflow-y-auto">
          <h2 class="text-2xl font-bold mb-6">
            {{ editMode ? $t('extensions.edit') : $t('extensions.add') }}
          </h2>

          <form @submit.prevent="saveExtension" class="space-y-4">
            <!-- Error message -->
            <div v-if="saveError" class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-700 rounded-lg p-4">
              <div class="flex items-center space-x-2">
                <span class="text-red-600 dark:text-red-400 text-xl">⚠️</span>
                <div>
                  <h3 class="font-semibold text-red-700 dark:text-red-300">{{ $t('common.error') }}</h3>
                  <p class="text-sm text-red-600 dark:text-red-400">{{ saveError }}</p>
                </div>
              </div>
            </div>

            <!-- Basic Information -->
            <fieldset :disabled="saving" class="space-y-4">
              <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4 space-y-4">
                <h3 class="text-lg font-semibold text-gray-700 dark:text-gray-300">📱 Basic Information</h3>
                <div class="grid grid-cols-2 gap-4">
                  <div>
                    <label class="label">{{ $t('extensions.number') }}</label>
                    <input v-model="form.extension_number" type="text" class="input" required :disabled="editMode" placeholder="e.g., 101" />
                  </div>
                  <div>
                    <label class="label">{{ $t('extensions.name') }}</label>
                    <input v-model="form.name" type="text" class="input" required placeholder="e.g., John Doe" />
                  </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                  <div>
                    <label class="label">{{ $t('extensions.email') }}</label>
                    <input v-model="form.email" type="email" class="input" placeholder="john@example.com" />
                  </div>
                  <div>
                    <label class="label">{{ $t('extensions.password') }}</label>
                    <input v-model="form.secret" type="password" class="input" :required="!editMode" placeholder="Min 8 characters" />
                  </div>
                </div>

                <div class="flex items-center space-x-4">
                  <label class="flex items-center space-x-2">
                    <input v-model="form.enabled" type="checkbox" class="rounded" />
                    <span class="text-sm">{{ $t('extensions.enabled') }}</span>
                  </label>
                  <label class="flex items-center space-x-2">
                    <input v-model="form.voicemail_enabled" type="checkbox" class="rounded" />
                    <span class="text-sm">Enable Voicemail</span>
                  </label>
                </div>
              </div>

              <!-- Advanced PJSIP Configuration -->
              <div class="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-4 space-y-4">
                <div class="flex items-center justify-between">
                  <h3 class="text-lg font-semibold text-blue-700 dark:text-blue-300">⚙️ Advanced PJSIP Configuration</h3>
                  <button type="button" @click="showAdvanced = !showAdvanced" class="text-blue-600 hover:text-blue-800 text-sm">
                    {{ showAdvanced ? 'Hide Advanced' : 'Show Advanced' }}
                  </button>
                </div>
                
                <div v-show="showAdvanced" class="space-y-4">
                  <!-- Codec Selection -->
                  <div>
                    <label class="label flex items-center">
                      🎵 Audio Codecs
                      <span class="ml-2 text-xs text-gray-500">(Higher = better quality, more bandwidth)</span>
                    </label>
                    <div class="grid grid-cols-3 md:grid-cols-6 gap-2 mt-2">
                      <label v-for="codec in availableCodecs" :key="codec.id" 
                             class="flex items-center space-x-2 p-2 border rounded cursor-pointer hover:bg-blue-100 dark:hover:bg-blue-800"
                             :class="{ 'bg-blue-100 dark:bg-blue-800 border-blue-500': form.codecs?.includes(codec.id) }">
                        <input type="checkbox" :value="codec.id" v-model="form.codecs" class="rounded" />
                        <div>
                          <span class="text-sm font-medium">{{ codec.name }}</span>
                          <span v-if="codec.hd" class="ml-1 px-1 py-0.5 text-xs bg-green-500 text-white rounded">HD</span>
                          <div class="text-xs text-gray-500">{{ codec.desc }}</div>
                        </div>
                      </label>
                    </div>
                  </div>

                  <div class="grid grid-cols-2 gap-4">
                    <div>
                      <label class="label">📍 Context</label>
                      <select v-model="form.context" class="input">
                        <option value="from-internal">from-internal (Recommended)</option>
                        <option value="internal">internal</option>
                        <option value="default">default</option>
                      </select>
                      <p class="text-xs text-gray-500 mt-1">Dialplan context for calls from this extension</p>
                    </div>
                    <div>
                      <label class="label">🔌 Transport</label>
                      <select v-model="form.transport" class="input">
                        <option value="transport-udp">UDP (Recommended)</option>
                        <option value="transport-tcp">TCP</option>
                        <option value="transport-tls">TLS (Secure)</option>
                      </select>
                      <p class="text-xs text-gray-500 mt-1">SIP signaling transport protocol</p>
                    </div>
                  </div>

                  <div class="grid grid-cols-3 gap-4">
                    <div>
                      <label class="label">🔄 Direct Media</label>
                      <select v-model="form.direct_media" class="input">
                        <option value="no">No (NAT-safe, recommended)</option>
                        <option value="yes">Yes (LAN only)</option>
                      </select>
                      <p class="text-xs text-gray-500 mt-1">Allow RTP to flow directly between endpoints</p>
                    </div>
                    <div>
                      <label class="label">📞 Max Contacts</label>
                      <input v-model.number="form.max_contacts" type="number" min="1" max="10" class="input" />
                      <p class="text-xs text-gray-500 mt-1">Simultaneous registrations (1-10)</p>
                    </div>
                    <div>
                      <label class="label">⏱️ Qualify Frequency</label>
                      <input v-model.number="form.qualify_frequency" type="number" min="0" max="3600" class="input" />
                      <p class="text-xs text-gray-500 mt-1">Keep-alive interval in seconds (0=disabled)</p>
                    </div>
                  </div>

                  <!-- Info Box -->
                  <div class="bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-700 rounded p-3 text-sm">
                    <p class="font-semibold text-yellow-800 dark:text-yellow-300">💡 Configuration Tips:</p>
                    <ul class="list-disc list-inside text-yellow-700 dark:text-yellow-400 mt-1 space-y-1">
                      <li><strong>G.722</strong> provides HD audio (16kHz) - great for softphones</li>
                      <li><strong>Direct Media = No</strong> is safer for NAT/firewall setups</li>
                      <li><strong>Qualify Frequency = 60</strong> helps detect offline devices</li>
                      <li>Use <strong>remove_existing=yes</strong> to avoid stale registrations (applied automatically)</li>
                    </ul>
                  </div>
                </div>
              </div>

              <!-- Notes -->
              <div>
                <label class="label">{{ $t('extensions.notes') }}</label>
                <textarea v-model="form.notes" class="input" rows="2" placeholder="Optional notes about this extension"></textarea>
              </div>
            </fieldset>

            <div class="flex justify-end space-x-4">
              <button type="button" @click="closeModal" class="btn btn-secondary" :disabled="saving">
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

    <!-- Offline Help Modal / Diagnostics Modal -->
    <div v-if="offlineHelpModal" class="fixed inset-0 z-50 overflow-y-auto" @click.self="offlineHelpModal = false" role="dialog" aria-modal="true" aria-labelledby="diagnostics-modal-title">
      <div class="flex items-center justify-center min-h-screen px-4">
        <div class="fixed inset-0 bg-black opacity-50" aria-hidden="true"></div>
        <div class="relative card max-w-4xl w-full max-h-[90vh] overflow-y-auto" role="document">
          <div class="flex justify-between items-start mb-4">
            <h2 id="diagnostics-modal-title" class="text-2xl font-bold" :class="selectedExtension?.registered ? 'text-green-600' : 'text-red-600'">
              {{ selectedExtension?.registered ? '✓' : '⚠️' }} Extension {{ selectedExtension?.extension_number }} 
              {{ selectedExtension?.registered ? 'Diagnostics' : 'Setup & Troubleshooting' }}
            </h2>
            <button @click="offlineHelpModal = false" class="text-gray-500 hover:text-gray-700" aria-label="Close diagnostics modal">
              ✕
            </button>
          </div>

          <div class="space-y-4 text-gray-700 dark:text-gray-300">
            <!-- Registration Status -->
            <div v-if="diagnosticsData" class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-700 rounded-lg p-4">
              <h3 class="font-semibold mb-2 flex items-center">
                <span v-if="diagnosticsData.registration_status?.registered" class="text-green-600">🟢 Registered</span>
                <span v-else class="text-red-600">⚫ Offline</span>
                <span class="ml-2">- Real-time Status</span>
              </h3>
              <div v-if="diagnosticsData.registration_status?.registered && diagnosticsData.registration_status?.details" class="text-sm space-y-1">
                <p v-if="diagnosticsData.registration_status.details.contacts?.[0]">
                  <strong>Contact:</strong> {{ diagnosticsData.registration_status.details.contacts[0].uri }}
                </p>
                <p v-if="diagnosticsData.registration_status.details.contacts?.[0]?.expires">
                  <strong>Expires:</strong> {{ diagnosticsData.registration_status.details.contacts[0].expires }} seconds
                </p>
              </div>
            </div>

            <!-- SIP Client Setup Guide -->
            <div class="bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-700 rounded-lg p-4">
              <h3 class="font-semibold mb-3">📱 SIP Client Setup Guide</h3>
              <div v-if="diagnosticsData?.setup_guide" class="space-y-2 text-sm">
                <p class="font-medium">Configure your SIP phone/softphone with these credentials:</p>
                <div class="bg-white dark:bg-gray-800 p-3 rounded border">
                  <table class="w-full">
                    <tr><td class="font-semibold pr-4">Extension/Username:</td><td>{{ diagnosticsData.setup_guide.extension }}</td></tr>
                    <tr><td class="font-semibold pr-4">Password:</td><td>(your configured secret)</td></tr>
                    <tr><td class="font-semibold pr-4">SIP Server:</td><td>{{ diagnosticsData.setup_guide.server }}</td></tr>
                    <tr><td class="font-semibold pr-4">Port:</td><td>{{ diagnosticsData.setup_guide.port }}</td></tr>
                    <tr><td class="font-semibold pr-4">Transport:</td><td>{{ diagnosticsData.setup_guide.transport }}</td></tr>
                  </table>
                </div>
              </div>
              
              <div v-if="diagnosticsData?.sip_clients" class="mt-3">
                <p class="font-medium mb-2">Popular SIP Clients:</p>
                <ul class="text-sm space-y-1">
                  <li v-for="client in diagnosticsData.sip_clients.slice(0, 5)" :key="client.name">
                    <strong>{{ client.name }}</strong> ({{ client.platform }}) - {{ client.description }}
                    <a :href="client.url" target="_blank" class="text-blue-600 hover:underline ml-1">↗</a>
                  </li>
                </ul>
              </div>
            </div>

            <!-- Test Instructions -->
            <div v-if="diagnosticsData?.test_instructions" class="bg-purple-50 dark:bg-purple-900/20 border border-purple-200 dark:border-purple-700 rounded-lg p-4">
              <h3 class="font-semibold mb-3">🧪 Testing & Validation Steps</h3>
              <ol class="list-decimal list-inside space-y-2 text-sm">
                <li v-for="instruction in diagnosticsData.test_instructions" :key="instruction.step">
                  <strong>{{ instruction.action }}:</strong> {{ instruction.description }}
                </li>
              </ol>
            </div>

            <!-- Troubleshooting -->
            <div v-if="diagnosticsData?.troubleshooting?.length > 0" class="bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-700 rounded-lg p-4">
              <h3 class="font-semibold mb-2">🔧 Troubleshooting</h3>
              <ul class="space-y-2 text-sm">
                <li v-for="(tip, index) in diagnosticsData.troubleshooting" :key="index" 
                    :class="{
                      'text-red-700 dark:text-red-400': tip.severity === 'error',
                      'text-yellow-700 dark:text-yellow-400': tip.severity === 'warning',
                      'text-blue-700 dark:text-blue-400': tip.severity === 'info'
                    }">
                  <strong>{{ tip.message }}:</strong> {{ tip.solution }}
                </li>
              </ul>
            </div>

            <!-- Status Indicators Guide -->
            <div class="bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg p-4">
              <h3 class="font-semibold mb-2">📊 Status Indicators Guide</h3>
              <ul class="text-sm space-y-1">
                <li><span class="text-green-600 font-bold">🟢 Registered</span> - Extension is online and ready to make/receive calls</li>
                <li><span class="text-red-600 font-bold">⚫ Offline</span> - Extension is not registered, check device and credentials</li>
                <li><span class="font-bold">📍 IP:Port</span> - Shows the network location of the registered device</li>
              </ul>
            </div>

            <!-- Quick Actions -->
            <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-700 rounded-lg p-4">
              <h3 class="font-semibold mb-3">⚡ Quick Actions</h3>
              <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                <button 
                  @click="editExtension(selectedExtension)"
                  class="btn btn-primary"
                >
                  📝 Edit Extension
                </button>
                <button 
                  v-if="!selectedExtension?.enabled"
                  @click="enableExtension(selectedExtension)"
                  class="btn bg-green-600 hover:bg-green-700 text-white"
                >
                  ✅ Enable Extension
                </button>
                <button 
                  @click="refreshDiagnostics"
                  :disabled="loadingDiagnostics"
                  class="btn btn-secondary"
                >
                  {{ loadingDiagnostics ? '⏳ Loading...' : '🔄 Refresh Status' }}
                </button>
                <NuxtLink 
                  to="/console"
                  class="btn btn-secondary block text-center"
                >
                  🖥️ View Console
                </NuxtLink>
              </div>
            </div>

            <!-- API Reference -->
            <div v-if="diagnosticsData?.api_endpoints" class="text-xs text-gray-600 dark:text-gray-400">
              <p><strong>API Endpoints:</strong></p>
              <ul class="list-disc list-inside">
                <li>Verify: <code class="bg-gray-200 dark:bg-gray-700 px-1 rounded">{{ diagnosticsData.api_endpoints.verify }}</code></li>
                <li>Endpoints: <code class="bg-gray-200 dark:bg-gray-700 px-1 rounded">{{ diagnosticsData.api_endpoints.endpoints }}</code></li>
              </ul>
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

    <!-- Sync Manager Modal -->
    <div v-if="showSyncModal" class="fixed inset-0 z-50 overflow-y-auto" @click.self="showSyncModal = false">
      <div class="flex items-center justify-center min-h-screen px-4">
        <div class="fixed inset-0 bg-black opacity-50"></div>
        <div class="relative card max-w-5xl w-full max-h-[90vh] overflow-y-auto">
          <div class="flex justify-between items-start mb-4">
            <h2 class="text-2xl font-bold text-blue-600 dark:text-blue-400">
              🔄 Extension Sync Manager
            </h2>
            <button @click="showSyncModal = false" class="text-gray-500 hover:text-gray-700">
              ✕
            </button>
          </div>

          <!-- Sync Summary -->
          <div v-if="syncStatus" class="grid grid-cols-5 gap-4 mb-6">
            <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4 text-center">
              <div class="text-2xl font-bold text-gray-700 dark:text-gray-300">{{ syncStatus.summary.total }}</div>
              <div class="text-xs text-gray-500">Total</div>
            </div>
            <div class="bg-green-50 dark:bg-green-900/20 rounded-lg p-4 text-center">
              <div class="text-2xl font-bold text-green-600">{{ syncStatus.summary.matched }}</div>
              <div class="text-xs text-green-600">✅ Synced</div>
            </div>
            <div class="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-4 text-center">
              <div class="text-2xl font-bold text-blue-600">{{ syncStatus.summary.db_only }}</div>
              <div class="text-xs text-blue-600">📦 DB Only</div>
            </div>
            <div class="bg-purple-50 dark:bg-purple-900/20 rounded-lg p-4 text-center">
              <div class="text-2xl font-bold text-purple-600">{{ syncStatus.summary.asterisk_only }}</div>
              <div class="text-xs text-purple-600">⚡ Asterisk Only</div>
            </div>
            <div class="bg-amber-50 dark:bg-amber-900/20 rounded-lg p-4 text-center">
              <div class="text-2xl font-bold text-amber-600">{{ syncStatus.summary.mismatched }}</div>
              <div class="text-xs text-amber-600">⚠️ Mismatch</div>
            </div>
          </div>

          <!-- Bulk Sync Actions -->
          <div class="mb-6 p-4 bg-gray-50 dark:bg-gray-800 rounded-lg">
            <h3 class="font-semibold mb-3 text-gray-700 dark:text-gray-300">⚡ Bulk Sync Actions</h3>
            <div class="flex gap-4">
              <button 
                @click="syncAllDbToAsterisk" 
                :disabled="syncing"
                class="btn bg-blue-600 hover:bg-blue-700 text-white flex-1"
              >
                📥 Sync All DB → Asterisk
              </button>
              <button 
                @click="syncAllAsteriskToDb" 
                :disabled="syncing"
                class="btn bg-purple-600 hover:bg-purple-700 text-white flex-1"
              >
                📤 Sync All Asterisk → DB
              </button>
              <button 
                @click="refreshSyncStatus" 
                :disabled="syncing"
                class="btn btn-secondary"
              >
                🔄 Refresh
              </button>
            </div>
          </div>

          <!-- Extensions List -->
          <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
              <thead class="bg-gray-50 dark:bg-gray-800">
                <tr>
                  <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                  <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Extension</th>
                  <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                  <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Source</th>
                  <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Differences</th>
                  <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                <tr v-for="ext in syncStatus?.extensions" :key="ext.extension_number" 
                    class="hover:bg-gray-50 dark:hover:bg-gray-800">
                  <td class="px-4 py-3 whitespace-nowrap">
                    <span v-if="ext.sync_status === 'match'" class="text-green-600">✅</span>
                    <span v-else-if="ext.sync_status === 'db_only'" class="text-blue-600">📦</span>
                    <span v-else-if="ext.sync_status === 'asterisk_only'" class="text-purple-600">⚡</span>
                    <span v-else class="text-amber-600">⚠️</span>
                    <span v-if="ext.registered" class="ml-1 text-green-500" title="Registered">📞</span>
                  </td>
                  <td class="px-4 py-3 whitespace-nowrap font-medium">{{ ext.extension_number }}</td>
                  <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-600 dark:text-gray-400">
                    {{ ext.db_extension?.name || `Extension ${ext.extension_number}` }}
                  </td>
                  <td class="px-4 py-3 whitespace-nowrap text-sm">
                    <span v-if="ext.source === 'both'" class="text-green-600">DB + Asterisk</span>
                    <span v-else-if="ext.source === 'database'" class="text-blue-600">Database</span>
                    <span v-else class="text-purple-600">Asterisk</span>
                  </td>
                  <td class="px-4 py-3 text-sm text-gray-500">
                    <ul v-if="ext.differences?.length > 0" class="list-disc list-inside text-xs">
                      <li v-for="diff in ext.differences" :key="diff" class="text-amber-600">{{ diff }}</li>
                    </ul>
                    <span v-else class="text-green-600 text-xs">No differences</span>
                  </td>
                  <td class="px-4 py-3 whitespace-nowrap text-right text-sm">
                    <button 
                      v-if="ext.source === 'database' || ext.sync_status === 'mismatch'"
                      @click="syncSingleDbToAsterisk(ext.extension_number)"
                      :disabled="syncing"
                      class="text-blue-600 hover:text-blue-800 mr-2"
                      title="Sync DB → Asterisk"
                    >
                      📥
                    </button>
                    <button 
                      v-if="ext.source === 'asterisk' || ext.sync_status === 'mismatch'"
                      @click="syncSingleAsteriskToDb(ext.extension_number)"
                      :disabled="syncing"
                      class="text-purple-600 hover:text-purple-800"
                      title="Sync Asterisk → DB"
                    >
                      📤
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- Sync Status Message -->
          <div v-if="syncMessage" 
               :class="['mt-4 p-3 rounded-lg text-sm', 
                       syncError ? 'bg-red-50 text-red-600 border border-red-200' : 'bg-green-50 text-green-600 border border-green-200']">
            {{ syncMessage }}
          </div>

          <div class="flex justify-end mt-6">
            <button @click="showSyncModal = false" class="btn btn-secondary">
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
const saveError = ref('')
const showAdvanced = ref(false)
const togglingExtension = ref<number | null>(null)
const pageError = ref('')

// Sync status
const showSyncModal = ref(false)
const syncStatus = ref<any>(null)
const syncing = ref(false)
const syncMessage = ref('')
const syncError = ref(false)

// Sorting and filtering state
const searchQuery = ref('')
const statusFilter = ref('')
const sortField = ref('extension_number')
const sortDirection = ref<'asc' | 'desc'>('asc')

// Offline help modal
const offlineHelpModal = ref(false)
const selectedExtension = ref<any>(null)
const diagnosticsData = ref<any>(null)
const loadingDiagnostics = ref(false)

// Available audio codecs with descriptions
const availableCodecs = [
  { id: 'ulaw', name: 'μ-law', desc: '8kHz (US)', hd: false },
  { id: 'alaw', name: 'A-law', desc: '8kHz (EU)', hd: false },
  { id: 'g722', name: 'G.722', desc: '16kHz', hd: true },
  { id: 'g729', name: 'G.729', desc: '8kHz (low BW)', hd: false },
  { id: 'opus', name: 'Opus', desc: '48kHz', hd: true },
  { id: 'gsm', name: 'GSM', desc: '8kHz', hd: false },
]

const form = ref({
  id: null as number | null,
  extension_number: '',
  name: '',
  email: '',
  secret: '',
  enabled: true,
  voicemail_enabled: false,
  notes: '',
  // Advanced PJSIP options
  codecs: ['ulaw', 'alaw', 'g722'] as string[],
  context: 'from-internal',
  transport: 'transport-udp',
  direct_media: 'no',
  max_contacts: 1,
  qualify_frequency: 60,
})

// Computed property for filtered and sorted extensions
const filteredExtensions = computed(() => {
  let result = [...extensions.value]

  // Apply search filter
  if (searchQuery.value) {
    const query = searchQuery.value.toLowerCase()
    result = result.filter(ext => 
      String(ext.extension_number || '').toLowerCase().includes(query) ||
      String(ext.name || '').toLowerCase().includes(query) ||
      String(ext.email || '').toLowerCase().includes(query)
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

const showOfflineHelp = async (ext: any) => {
  selectedExtension.value = ext
  offlineHelpModal.value = true
  
  // Fetch diagnostics data
  await fetchDiagnostics(ext.id)
}

const fetchDiagnostics = async (extensionId: number) => {
  loadingDiagnostics.value = true
  try {
    const response = await api.apiFetch(`/extensions/${extensionId}/diagnostics`)
    diagnosticsData.value = response
  } catch (error) {
    console.error('Failed to fetch diagnostics:', error)
    diagnosticsData.value = null
  } finally {
    loadingDiagnostics.value = false
  }
}

const refreshDiagnostics = async () => {
  if (selectedExtension.value) {
    await fetchDiagnostics(selectedExtension.value.id)
    // Also refresh the extension list to get updated status
    await fetchExtensions()
  }
}

const enableExtension = async (ext: any) => {
  try {
    await api.updateExtension(ext.id, { ...ext, enabled: true })
    offlineHelpModal.value = false
    alert(t('extensions.enableSuccess', { number: ext.extension_number }))
    // WebSocket will trigger refresh
  } catch (error) {
    console.error('Error enabling extension:', error)
    alert(t('extensions.enableError', { number: ext.extension_number }))
  }
}

const toggleExtension = async (ext: any) => {
  togglingExtension.value = ext.id
  try {
    // Call the toggle endpoint
    const response = await api.apiFetch(`/extensions/${ext.id}/toggle`, { method: 'POST' })
    
    if (response.extension) {
      // Update the local extension state
      const index = extensions.value.findIndex(e => e.id === ext.id)
      if (index !== -1) {
        extensions.value[index].enabled = response.extension.enabled
      }
      
      // Show feedback to user
      const status = response.extension.enabled ? 'enabled' : 'disabled'
      
      // Check for configuration errors
      if (response.error || !response.config_write_success || !response.reload_success) {
        const errorMsg = response.error || 'Configuration update failed'
        alert(`Extension ${ext.extension_number} ${status} in database, but: ${errorMsg}`)
      } else {
        console.log(`Extension ${ext.extension_number} ${status} successfully`)
      }
      
      // Optionally refresh live status from Asterisk after toggle
      // This updates registration status which may change after enable/disable
      enrichWithLiveStatus()
    }
  } catch (error) {
    console.error('Error toggling extension:', error)
    alert(`Failed to toggle extension ${ext.extension_number}`)
  } finally {
    togglingExtension.value = null
  }
}

// Helper function to extract error message from various error formats
const extractErrorMessage = (error: any): string => {
  // Try to get the most specific error message available
  if (error.data?.message) {
    return error.data.message
  }
  if (error.data?.error) {
    return error.data.error
  }
  if (error.message) {
    return error.message
  }
  if (typeof error === 'string') {
    return error
  }
  return t('common.error') || 'An error occurred'
}

const fetchExtensions = async () => {
  loading.value = true
  pageError.value = ''
  try {
    const response = await api.getExtensions()
    extensions.value = response.extensions
    
    // Fetch live status for each extension
    await enrichWithLiveStatus()
  } catch (error: any) {
    console.error('Error fetching extensions:', error)
    pageError.value = extractErrorMessage(error)
  }
  loading.value = false
}

// Retry loading data after an error
const retryLoad = async () => {
  pageError.value = ''
  await fetchExtensions()
  await refreshSyncStatus()
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
    voicemail_enabled: ext.voicemail_enabled || false,
    notes: ext.notes || '',
    // Load advanced PJSIP options
    codecs: ext.codecs || ['ulaw', 'alaw', 'g722'],
    context: ext.context || 'from-internal',
    transport: ext.transport || 'transport-udp',
    direct_media: ext.direct_media || 'no',
    max_contacts: ext.max_contacts || 1,
    qualify_frequency: ext.qualify_frequency || 60,
  }
  editMode.value = true
  showAdvanced.value = true // Show advanced options when editing
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
  saveError.value = ''
  try {
    if (editMode.value) {
      await api.updateExtension(form.value.id!, form.value)
    } else {
      await api.createExtension(form.value)
    }
    showModal.value = false
    resetForm()
    // WebSocket will trigger refresh via event
  } catch (error: any) {
    console.error('Error saving extension:', error)
    // Extract error message from various error formats
    saveError.value = error.data?.message || error.message || t('common.error')
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
    voicemail_enabled: false,
    notes: '',
    // Reset advanced PJSIP options to defaults
    codecs: ['ulaw', 'alaw', 'g722'],
    context: 'from-internal',
    transport: 'transport-udp',
    direct_media: 'no',
    max_contacts: 1,
    qualify_frequency: 60,
  }
  editMode.value = false
  showAdvanced.value = false
  saveError.value = ''
}

const closeModal = () => {
  showModal.value = false
  saveError.value = ''
}

// Sync functions
const refreshSyncStatus = async () => {
  try {
    syncing.value = true
    syncMessage.value = ''
    syncError.value = false
    const response = await api.apiFetch('/extensions/sync/status')
    syncStatus.value = response
  } catch (error: any) {
    console.error('Error fetching sync status:', error)
    syncError.value = true
    syncMessage.value = extractErrorMessage(error)
    // Also show in page-level error if it's a significant issue
    if (!pageError.value) {
      pageError.value = extractErrorMessage(error)
    }
  } finally {
    syncing.value = false
  }
}

const syncSingleDbToAsterisk = async (extensionNumber: string) => {
  try {
    syncing.value = true
    syncMessage.value = ''
    syncError.value = false
    const response = await api.apiFetch('/extensions/sync/db-to-asterisk', {
      method: 'POST',
      body: { extension_number: extensionNumber }
    })
    syncMessage.value = response.message || `Extension ${extensionNumber} synced to Asterisk`
    await refreshSyncStatus()
    await fetchExtensions()
  } catch (error: any) {
    console.error('Error syncing to Asterisk:', error)
    syncError.value = true
    syncMessage.value = extractErrorMessage(error)
  } finally {
    syncing.value = false
  }
}

const syncSingleAsteriskToDb = async (extensionNumber: string) => {
  try {
    syncing.value = true
    syncMessage.value = ''
    syncError.value = false
    const response = await api.apiFetch('/extensions/sync/asterisk-to-db', {
      method: 'POST',
      body: { extension_number: extensionNumber }
    })
    syncMessage.value = response.message || `Extension ${extensionNumber} synced to database`
    await refreshSyncStatus()
    await fetchExtensions()
  } catch (error: any) {
    console.error('Error syncing to database:', error)
    syncError.value = true
    syncMessage.value = extractErrorMessage(error)
  } finally {
    syncing.value = false
  }
}

const syncAllDbToAsterisk = async () => {
  try {
    syncing.value = true
    syncMessage.value = ''
    syncError.value = false
    const response = await api.apiFetch('/extensions/sync/all-db-to-asterisk', {
      method: 'POST'
    })
    syncMessage.value = response.message || `Synced ${response.synced} extensions to Asterisk`
    if (response.errors?.length > 0) {
      syncError.value = true
      syncMessage.value += ` (${response.errors.length} errors)`
    }
    await refreshSyncStatus()
    await fetchExtensions()
  } catch (error: any) {
    console.error('Error syncing all to Asterisk:', error)
    syncError.value = true
    syncMessage.value = extractErrorMessage(error)
  } finally {
    syncing.value = false
  }
}

const syncAllAsteriskToDb = async () => {
  try {
    syncing.value = true
    syncMessage.value = ''
    syncError.value = false
    const response = await api.apiFetch('/extensions/sync/all-asterisk-to-db', {
      method: 'POST'
    })
    syncMessage.value = response.message || `Synced ${response.synced} extensions to database`
    if (response.errors?.length > 0) {
      syncError.value = true
      syncMessage.value += ` (${response.errors.length} errors)`
    }
    await refreshSyncStatus()
    await fetchExtensions()
  } catch (error: any) {
    console.error('Error syncing all to database:', error)
    syncError.value = true
    syncMessage.value = extractErrorMessage(error)
  } finally {
    syncing.value = false
  }
}

onMounted(async () => {
  await authStore.checkAuth()
  if (!authStore.isAuthenticated) {
    router.push('/login')
    return
  }
  await fetchExtensions()
  
  // Fetch sync status
  await refreshSyncStatus()
  
  // Connect to WebSocket
  const ws = useWebSocket()
  ws.connect()
  
  // Listen for extension events
  ws.on('extension.created', async (payload) => {
    console.log('Extension created:', payload)
    await fetchExtensions()
    await refreshSyncStatus()
  })
  
  ws.on('extension.updated', async (payload) => {
    console.log('Extension updated:', payload)
    await fetchExtensions()
    await refreshSyncStatus()
  })
  
  ws.on('extension.deleted', async (payload) => {
    console.log('Extension deleted:', payload)
    // Remove from local list
    extensions.value = extensions.value.filter(e => e.id !== payload.id)
    await refreshSyncStatus()
  })
  
  // Auto-refresh live status every 10 seconds
  setInterval(enrichWithLiveStatus, 10000)
})
</script>
