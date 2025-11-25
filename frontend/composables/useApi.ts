export const useApi = () => {
  const config = useRuntimeConfig()
  const authStore = useAuthStore()

  const apiFetch = async (endpoint: string, options: any = {}) => {
    const headers = {
      ...options.headers,
    }

    if (authStore.token) {
      headers.Authorization = `Bearer ${authStore.token}`
    }

    try {
      return await $fetch(`${config.public.apiBase}${endpoint}`, {
        ...options,
        headers,
      })
    } catch (error: any) {
      if (error.status === 401 || error.statusCode === 401) {
        // Unauthorized - clear auth and redirect to login
        await authStore.logout()
        navigateTo('/login')
      }
      
      // Enhance error with message from backend if available
      if (error.data?.message && !error.message) {
        error.message = error.data.message
      }
      
      throw error
    }
  }

  return {
    apiFetch,
    
    // Generic GET request
    async get(endpoint: string) {
      return apiFetch(endpoint)
    },
    
    // Generic POST request
    async post(endpoint: string, data: any = {}) {
      return apiFetch(endpoint, {
        method: 'POST',
        body: data,
      })
    },
    
    // Extensions
    async getExtensions() {
      return apiFetch('/extensions')
    },
    
    async createExtension(data: any) {
      return apiFetch('/extensions', {
        method: 'POST',
        body: data,
      })
    },
    
    async updateExtension(id: number, data: any) {
      return apiFetch(`/extensions/${id}`, {
        method: 'PUT',
        body: data,
      })
    },
    
    async deleteExtension(id: number) {
      return apiFetch(`/extensions/${id}`, {
        method: 'DELETE',
      })
    },

    // Trunks
    async getTrunks() {
      return apiFetch('/trunks')
    },
    
    async createTrunk(data: any) {
      return apiFetch('/trunks', {
        method: 'POST',
        body: data,
      })
    },
    
    async updateTrunk(id: number, data: any) {
      return apiFetch(`/trunks/${id}`, {
        method: 'PUT',
        body: data,
      })
    },
    
    async deleteTrunk(id: number) {
      return apiFetch(`/trunks/${id}`, {
        method: 'DELETE',
      })
    },

    // Status
    async getStatus() {
      return apiFetch('/status')
    },

    // Logs
    async getLogs(params: any = {}) {
      const query = new URLSearchParams(params).toString()
      return apiFetch(`/logs?${query}`)
    },
    
    // Console
    async executeConsoleCommand(command: string) {
      return apiFetch('/console/execute', {
        method: 'POST',
        body: { command },
      })
    },
    
    async getConsoleOutput(lines: number = 50) {
      return apiFetch(`/console/output?lines=${lines}`)
    },
    
    async getConsoleCommands() {
      return apiFetch('/console/commands')
    },
    
    async getAsteriskVersion() {
      return apiFetch('/console/version')
    },
    
    async getActiveCalls() {
      return apiFetch('/console/calls')
    },
    
    async getChannels() {
      return apiFetch('/console/channels')
    },
    
    async getEndpoints() {
      return apiFetch('/console/endpoints')
    },
    
    async getRegistrations() {
      return apiFetch('/console/registrations')
    },
    
    async reloadAsterisk(module?: string) {
      return apiFetch('/console/reload', {
        method: 'POST',
        body: { module },
      })
    },
    
    async hangupChannel(channel: string) {
      return apiFetch('/console/hangup', {
        method: 'POST',
        body: { channel },
      })
    },
    
    async originateCall(channel: string, extension: string, context?: string) {
      return apiFetch('/console/originate', {
        method: 'POST',
        body: { channel, extension, context },
      })
    },

    // VoIP Phone Management
    async getPhones() {
      return apiFetch('/phones')
    },

    async getPhone(identifier: string) {
      return apiFetch(`/phones/${identifier}`)
    },

    async controlPhone(ip: string, action: string, credentials: any = {}, config: any = {}, confirmDestructive = false) {
      return apiFetch('/phones/control', {
        method: 'POST',
        body: { ip, action, credentials, config, confirm_destructive: confirmDestructive },
      })
    },

    async provisionPhone(ip: string, extensionId: number, accountNumber = 1, credentials: any = {}) {
      return apiFetch('/phones/provision', {
        method: 'POST',
        body: { ip, extension_id: extensionId, account_number: accountNumber, credentials },
      })
    },

    // GrandStream-specific APIs
    async scanGrandstreamNetwork(network: string) {
      return apiFetch('/grandstream/scan', {
        method: 'POST',
        body: { network },
      })
    },

    async checkPhoneActionUrls(ip: string, credentials: any = {}) {
      return apiFetch('/grandstream/action-urls/check', {
        method: 'POST',
        body: { ip, credentials },
      })
    },

    async updatePhoneActionUrls(ip: string, credentials: any = {}, force = false) {
      return apiFetch('/grandstream/action-urls/update', {
        method: 'POST',
        body: { ip, credentials, force },
      })
    },

    async provisionPhoneComplete(ip: string, extensionId: number, accountNumber = 1, credentials: any = {}, forceActionUrls = false) {
      return apiFetch('/grandstream/provision-complete', {
        method: 'POST',
        body: { ip, extension_id: extensionId, account_number: accountNumber, credentials, force_action_urls: forceActionUrls },
      })
    },

    // LLDP Neighbors
    async getLldpNeighbors() {
      return apiFetch('/phones/lldp/neighbors')
    },
  }
}
