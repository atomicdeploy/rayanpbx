<template>
  <div class="min-h-screen bg-gradient-to-br from-gray-900 via-purple-900 to-gray-900 p-6">
    <div class="max-w-7xl mx-auto">
      <!-- Header -->
      <div class="mb-8">
        <h1 class="text-4xl font-bold text-white mb-2 flex items-center">
          <svg class="w-10 h-10 mr-3 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
          </svg>
          Configuration Manager
        </h1>
        <p class="text-gray-400">Complete control over your RayanPBX environment variables</p>
      </div>

      <!-- Action Bar -->
      <div class="bg-gray-800 rounded-lg shadow-lg p-4 mb-6 flex flex-wrap items-center justify-between gap-4">
        <div class="flex items-center gap-4 flex-1">
          <!-- Search -->
          <div class="relative flex-1 max-w-md">
            <input 
              v-model="searchQuery"
              type="text" 
              placeholder="Search configuration keys..."
              class="w-full bg-gray-700 text-white rounded-lg pl-10 pr-4 py-2 focus:ring-2 focus:ring-purple-500 focus:outline-none"
            />
            <svg class="w-5 h-5 absolute left-3 top-2.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
            </svg>
          </div>
          
          <!-- Filter -->
          <select 
            v-model="filterType"
            class="bg-gray-700 text-white rounded-lg px-4 py-2 focus:ring-2 focus:ring-purple-500 focus:outline-none"
          >
            <option value="all">All Keys</option>
            <option value="sensitive">Sensitive Only</option>
            <option value="normal">Normal Only</option>
          </select>
        </div>

        <!-- Action Buttons -->
        <div class="flex items-center gap-2">
          <button 
            @click="showAddModal = true"
            class="bg-gradient-to-r from-purple-600 to-purple-700 hover:from-purple-700 hover:to-purple-800 text-white px-6 py-2 rounded-lg font-semibold flex items-center gap-2 transition-all shadow-lg"
          >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
            </svg>
            Add New
          </button>
          
          <button 
            @click="reloadServices"
            :disabled="reloading"
            class="bg-gradient-to-r from-green-600 to-green-700 hover:from-green-700 hover:to-green-800 text-white px-6 py-2 rounded-lg font-semibold flex items-center gap-2 transition-all shadow-lg disabled:opacity-50"
          >
            <svg class="w-5 h-5" :class="{'animate-spin': reloading}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
            </svg>
            {{ reloading ? 'Reloading...' : 'Reload Services' }}
          </button>
          
          <button 
            @click="refreshConfig"
            :disabled="loading"
            class="bg-gray-700 hover:bg-gray-600 text-white px-4 py-2 rounded-lg font-semibold flex items-center gap-2 transition-all"
          >
            <svg class="w-5 h-5" :class="{'animate-spin': loading}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
            </svg>
          </button>
        </div>
      </div>

      <!-- Stats -->
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <div class="bg-gradient-to-br from-blue-500 to-blue-600 rounded-lg p-4 text-white shadow-lg">
          <div class="flex items-center justify-between">
            <div>
              <p class="text-blue-100 text-sm mb-1">Total Keys</p>
              <p class="text-3xl font-bold">{{ config.length }}</p>
            </div>
            <svg class="w-12 h-12 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
            </svg>
          </div>
        </div>

        <div class="bg-gradient-to-br from-amber-500 to-amber-600 rounded-lg p-4 text-white shadow-lg">
          <div class="flex items-center justify-between">
            <div>
              <p class="text-amber-100 text-sm mb-1">Sensitive Keys</p>
              <p class="text-3xl font-bold">{{ sensitiveCount }}</p>
            </div>
            <svg class="w-12 h-12 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
            </svg>
          </div>
        </div>

        <div class="bg-gradient-to-br from-green-500 to-green-600 rounded-lg p-4 text-white shadow-lg">
          <div class="flex items-center justify-between">
            <div>
              <p class="text-green-100 text-sm mb-1">Normal Keys</p>
              <p class="text-3xl font-bold">{{ normalCount }}</p>
            </div>
            <svg class="w-12 h-12 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
            </svg>
          </div>
        </div>
      </div>

      <!-- Configuration Table -->
      <div class="bg-gray-800 rounded-lg shadow-lg overflow-hidden">
        <div class="overflow-x-auto">
          <table class="w-full">
            <thead class="bg-gray-700">
              <tr>
                <th class="px-6 py-4 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
                  Key
                </th>
                <th class="px-6 py-4 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
                  Value
                </th>
                <th class="px-6 py-4 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
                  Type
                </th>
                <th class="px-6 py-4 text-right text-xs font-medium text-gray-300 uppercase tracking-wider">
                  Actions
                </th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-700">
              <tr 
                v-for="item in filteredConfig" 
                :key="item.key"
                class="hover:bg-gray-700 transition-colors"
              >
                <td class="px-6 py-4 whitespace-nowrap">
                  <div class="flex items-center">
                    <svg v-if="item.sensitive" class="w-5 h-5 text-amber-400 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                    </svg>
                    <span class="text-white font-mono text-sm">{{ item.key }}</span>
                  </div>
                  <p v-if="item.description" class="text-xs text-gray-400 mt-1">{{ item.description }}</p>
                </td>
                <td class="px-6 py-4">
                  <code class="bg-gray-900 text-green-400 px-3 py-1 rounded text-sm font-mono">
                    {{ item.value }}
                  </code>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                  <span 
                    class="px-3 py-1 inline-flex text-xs leading-5 font-semibold rounded-full"
                    :class="item.sensitive ? 'bg-amber-900 text-amber-200' : 'bg-blue-900 text-blue-200'"
                  >
                    {{ item.sensitive ? 'Sensitive' : 'Normal' }}
                  </span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium space-x-2">
                  <button 
                    @click="editConfig(item)"
                    class="text-blue-400 hover:text-blue-300 inline-flex items-center gap-1 transition-colors"
                  >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                    </svg>
                    Edit
                  </button>
                  <button 
                    @click="confirmDelete(item)"
                    class="text-red-400 hover:text-red-300 inline-flex items-center gap-1 transition-colors"
                  >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                    </svg>
                    Delete
                  </button>
                </td>
              </tr>
              
              <tr v-if="filteredConfig.length === 0">
                <td colspan="4" class="px-6 py-12 text-center">
                  <svg class="w-16 h-16 mx-auto text-gray-600 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" />
                  </svg>
                  <p class="text-gray-400 text-lg">No configuration keys found</p>
                  <p class="text-gray-500 text-sm mt-2">Try adjusting your search or filter</p>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Add/Edit Modal -->
      <div 
        v-if="showAddModal || showEditModal" 
        class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4"
        @click.self="closeModals"
      >
        <div class="bg-gray-800 rounded-lg shadow-2xl max-w-2xl w-full p-6 transform transition-all">
          <div class="flex justify-between items-center mb-6">
            <h2 class="text-2xl font-bold text-white">
              {{ showAddModal ? 'Add New Configuration' : 'Edit Configuration' }}
            </h2>
            <button @click="closeModals" class="text-gray-400 hover:text-white transition-colors">
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>

          <form @submit.prevent="showAddModal ? addConfig() : updateConfig()">
            <div class="space-y-4">
              <div>
                <label class="block text-gray-300 text-sm font-semibold mb-2">
                  Key
                </label>
                <input 
                  v-model="formData.key"
                  :disabled="showEditModal"
                  type="text"
                  placeholder="e.g., NEW_FEATURE_FLAG"
                  pattern="[A-Z_][A-Z0-9_]*"
                  required
                  class="w-full bg-gray-700 text-white rounded-lg px-4 py-2 focus:ring-2 focus:ring-purple-500 focus:outline-none disabled:opacity-50 disabled:cursor-not-allowed font-mono"
                />
                <p class="text-gray-400 text-xs mt-1">Must be uppercase with underscores (e.g., MY_KEY_NAME)</p>
              </div>

              <div>
                <label class="block text-gray-300 text-sm font-semibold mb-2">
                  Value
                </label>
                <textarea 
                  v-model="formData.value"
                  rows="3"
                  placeholder="Enter the value..."
                  required
                  class="w-full bg-gray-700 text-white rounded-lg px-4 py-2 focus:ring-2 focus:ring-purple-500 focus:outline-none font-mono"
                ></textarea>
              </div>
            </div>

            <div class="flex justify-end gap-3 mt-6">
              <button 
                type="button"
                @click="closeModals"
                class="bg-gray-700 hover:bg-gray-600 text-white px-6 py-2 rounded-lg font-semibold transition-all"
              >
                Cancel
              </button>
              <button 
                type="submit"
                :disabled="submitting"
                class="bg-gradient-to-r from-purple-600 to-purple-700 hover:from-purple-700 hover:to-purple-800 text-white px-6 py-2 rounded-lg font-semibold transition-all disabled:opacity-50"
              >
                {{ submitting ? 'Saving...' : (showAddModal ? 'Add Configuration' : 'Update Configuration') }}
              </button>
            </div>
          </form>
        </div>
      </div>

      <!-- Delete Confirmation Modal -->
      <div 
        v-if="showDeleteModal" 
        class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4"
        @click.self="showDeleteModal = false"
      >
        <div class="bg-gray-800 rounded-lg shadow-2xl max-w-md w-full p-6 transform transition-all">
          <div class="text-center">
            <svg class="w-16 h-16 mx-auto text-red-500 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
            <h3 class="text-xl font-bold text-white mb-2">Confirm Deletion</h3>
            <p class="text-gray-400 mb-6">
              Are you sure you want to delete the configuration key
              <span class="text-white font-mono">{{ deleteTarget?.key }}</span>?
              This action cannot be undone.
            </p>
            <div class="flex justify-center gap-3">
              <button 
                @click="showDeleteModal = false"
                class="bg-gray-700 hover:bg-gray-600 text-white px-6 py-2 rounded-lg font-semibold transition-all"
              >
                Cancel
              </button>
              <button 
                @click="deleteConfig"
                :disabled="deleting"
                class="bg-red-600 hover:bg-red-700 text-white px-6 py-2 rounded-lg font-semibold transition-all disabled:opacity-50"
              >
                {{ deleting ? 'Deleting...' : 'Delete' }}
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Reload Services Modal -->
      <div 
        v-if="showReloadModal" 
        class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4"
        @click.self="showReloadModal = false"
      >
        <div class="bg-gray-800 rounded-lg shadow-2xl max-w-md w-full p-6 transform transition-all">
          <h3 class="text-xl font-bold text-white mb-4">Select Services to Reload</h3>
          
          <div class="space-y-3 mb-6">
            <label class="flex items-center p-3 bg-gray-700 rounded-lg cursor-pointer hover:bg-gray-600 transition-colors">
              <input 
                type="radio" 
                v-model="reloadService" 
                value="all"
                class="w-4 h-4 text-purple-600 focus:ring-purple-500"
              />
              <span class="ml-3 text-white">All Services</span>
            </label>
            
            <label class="flex items-center p-3 bg-gray-700 rounded-lg cursor-pointer hover:bg-gray-600 transition-colors">
              <input 
                type="radio" 
                v-model="reloadService" 
                value="asterisk"
                class="w-4 h-4 text-purple-600 focus:ring-purple-500"
              />
              <span class="ml-3 text-white">Asterisk Only</span>
            </label>
            
            <label class="flex items-center p-3 bg-gray-700 rounded-lg cursor-pointer hover:bg-gray-600 transition-colors">
              <input 
                type="radio" 
                v-model="reloadService" 
                value="laravel"
                class="w-4 h-4 text-purple-600 focus:ring-purple-500"
              />
              <span class="ml-3 text-white">Laravel/Backend Only</span>
            </label>
          </div>
          
          <div class="flex justify-end gap-3">
            <button 
              @click="showReloadModal = false"
              class="bg-gray-700 hover:bg-gray-600 text-white px-6 py-2 rounded-lg font-semibold transition-all"
            >
              Cancel
            </button>
            <button 
              @click="confirmReload"
              :disabled="reloading"
              class="bg-green-600 hover:bg-green-700 text-white px-6 py-2 rounded-lg font-semibold transition-all disabled:opacity-50"
            >
              {{ reloading ? 'Reloading...' : 'Reload' }}
            </button>
          </div>
        </div>
      </div>

      <!-- Toast Notifications -->
      <div class="fixed bottom-4 right-4 space-y-2 z-50">
        <div 
          v-for="(toast, index) in toasts" 
          :key="index"
          class="bg-gray-800 text-white px-6 py-4 rounded-lg shadow-lg flex items-center gap-3 animate-slide-in"
          :class="{
            'border-l-4 border-green-500': toast.type === 'success',
            'border-l-4 border-red-500': toast.type === 'error',
            'border-l-4 border-blue-500': toast.type === 'info',
          }"
        >
          <svg v-if="toast.type === 'success'" class="w-6 h-6 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          <svg v-if="toast.type === 'error'" class="w-6 h-6 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          <span>{{ toast.message }}</span>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'

const config = ref([])
const loading = ref(false)
const reloading = ref(false)
const submitting = ref(false)
const deleting = ref(false)

const searchQuery = ref('')
const filterType = ref('all')

const showAddModal = ref(false)
const showEditModal = ref(false)
const showDeleteModal = ref(false)
const showReloadModal = ref(false)

const formData = ref({ key: '', value: '' })
const deleteTarget = ref(null)
const reloadService = ref('all')

const toasts = ref([])

// Computed
const filteredConfig = computed(() => {
  let filtered = config.value

  // Apply search
  if (searchQuery.value) {
    const query = searchQuery.value.toLowerCase()
    filtered = filtered.filter(item => 
      item.key.toLowerCase().includes(query) || 
      item.value.toLowerCase().includes(query)
    )
  }

  // Apply filter
  if (filterType.value === 'sensitive') {
    filtered = filtered.filter(item => item.sensitive)
  } else if (filterType.value === 'normal') {
    filtered = filtered.filter(item => !item.sensitive)
  }

  return filtered
})

const sensitiveCount = computed(() => config.value.filter(item => item.sensitive).length)
const normalCount = computed(() => config.value.filter(item => !item.sensitive).length)

// Methods
const fetchConfig = async () => {
  loading.value = true
  try {
    const response = await fetch('/api/config', {
      headers: {
        'Authorization': `Bearer ${localStorage.getItem('token')}`
      }
    })
    const data = await response.json()
    if (data.success) {
      config.value = data.data
    } else {
      showToast('Failed to load configuration', 'error')
    }
  } catch (error) {
    showToast('Error loading configuration: ' + error.message, 'error')
  } finally {
    loading.value = false
  }
}

const refreshConfig = () => {
  fetchConfig()
  showToast('Configuration refreshed', 'success')
}

const addConfig = async () => {
  submitting.value = true
  try {
    const response = await fetch('/api/config', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${localStorage.getItem('token')}`
      },
      body: JSON.stringify(formData.value)
    })
    const data = await response.json()
    if (data.success) {
      showToast('Configuration added successfully', 'success')
      closeModals()
      fetchConfig()
    } else {
      showToast(data.message || 'Failed to add configuration', 'error')
    }
  } catch (error) {
    showToast('Error adding configuration: ' + error.message, 'error')
  } finally {
    submitting.value = false
  }
}

const editConfig = (item) => {
  formData.value = { key: item.key, value: item.sensitive ? '' : item.value }
  showEditModal.value = true
}

const updateConfig = async () => {
  submitting.value = true
  try {
    const response = await fetch(`/api/config/${formData.value.key}`, {
      method: 'PUT',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${localStorage.getItem('token')}`
      },
      body: JSON.stringify({ value: formData.value.value })
    })
    const data = await response.json()
    if (data.success) {
      showToast('Configuration updated successfully', 'success')
      closeModals()
      fetchConfig()
    } else {
      showToast(data.message || 'Failed to update configuration', 'error')
    }
  } catch (error) {
    showToast('Error updating configuration: ' + error.message, 'error')
  } finally {
    submitting.value = false
  }
}

const confirmDelete = (item) => {
  deleteTarget.value = item
  showDeleteModal.value = true
}

const deleteConfig = async () => {
  deleting.value = true
  try {
    const response = await fetch(`/api/config/${deleteTarget.value.key}`, {
      method: 'DELETE',
      headers: {
        'Authorization': `Bearer ${localStorage.getItem('token')}`
      }
    })
    const data = await response.json()
    if (data.success) {
      showToast('Configuration deleted successfully', 'success')
      showDeleteModal.value = false
      fetchConfig()
    } else {
      showToast(data.message || 'Failed to delete configuration', 'error')
    }
  } catch (error) {
    showToast('Error deleting configuration: ' + error.message, 'error')
  } finally {
    deleting.value = false
  }
}

const reloadServices = () => {
  showReloadModal.value = true
}

const confirmReload = async () => {
  reloading.value = true
  try {
    const response = await fetch('/api/config/reload', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${localStorage.getItem('token')}`
      },
      body: JSON.stringify({ service: reloadService.value })
    })
    const data = await response.json()
    if (data.success) {
      showToast('Services reloaded successfully', 'success')
      showReloadModal.value = false
    } else {
      showToast(data.message || 'Failed to reload services', 'error')
    }
  } catch (error) {
    showToast('Error reloading services: ' + error.message, 'error')
  } finally {
    reloading.value = false
  }
}

const closeModals = () => {
  showAddModal.value = false
  showEditModal.value = false
  formData.value = { key: '', value: '' }
}

const showToast = (message, type = 'info') => {
  const toast = { message, type }
  toasts.value.push(toast)
  setTimeout(() => {
    toasts.value = toasts.value.filter(t => t !== toast)
  }, 5000)
}

// Lifecycle
onMounted(() => {
  fetchConfig()
})
</script>

<style scoped>
@keyframes slide-in {
  from {
    transform: translateX(100%);
    opacity: 0;
  }
  to {
    transform: translateX(0);
    opacity: 1;
  }
}

.animate-slide-in {
  animation: slide-in 0.3s ease-out;
}
</style>
