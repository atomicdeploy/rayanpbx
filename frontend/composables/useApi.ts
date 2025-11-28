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
      // Laravel can return errors in various formats depending on the exception type
      if (!error.message) {
        if (error.data?.message) {
          error.message = error.data.message
        } else if (error.data?.error) {
          error.message = error.data.error
        } else if (error.data?.exception) {
          // For unhandled Laravel exceptions, show a more user-friendly message
          error.message = `Server error: ${error.data.exception}`
        }
      }
      
      // Also ensure error.data.message is set for components that check it directly
      if (error.data && !error.data.message && error.message) {
        error.data.message = error.message
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

    async authenticatePhone(ip: string, credentials: any = null) {
      return apiFetch('/phones/authenticate', {
        method: 'POST',
        body: { ip, credentials },
      })
    },

    async updatePhone(id: number, data: any) {
      return apiFetch(`/phones/${id}`, {
        method: 'PUT',
        body: data,
      })
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
    
    // ARP Neighbors
    async getArpNeighbors() {
      return apiFetch('/phones/arp/neighbors')
    },
    
    // Discover all phones (LLDP + ARP + nmap)
    async discoverPhones() {
      return apiFetch('/phones/discover')
    },

    // CTI/CSTA Operations
    async getCTIStatus(ip: string, credentials: any = {}) {
      return apiFetch('/grandstream/cti/status', {
        method: 'POST',
        body: { ip, credentials },
      })
    },

    async getLineStatus(ip: string, lineId: number, credentials: any = {}) {
      return apiFetch('/grandstream/cti/line-status', {
        method: 'POST',
        body: { ip, line_id: lineId, credentials },
      })
    },

    async executeCTIOperation(ip: string, operation: string, params: any = {}, credentials: any = {}) {
      return apiFetch('/grandstream/cti/operation', {
        method: 'POST',
        body: { ip, operation, credentials, ...params },
      })
    },

    async displayLCDMessage(ip: string, message: string, duration?: number, credentials: any = {}) {
      return apiFetch('/grandstream/cti/lcd-message', {
        method: 'POST',
        body: { ip, message, duration, credentials },
      })
    },

    async takeScreenshot(ip: string, credentials: any = {}) {
      return apiFetch('/grandstream/cti/screenshot', {
        method: 'POST',
        body: { ip, credentials },
      })
    },

    async enableCTI(ip: string, credentials: any = {}) {
      return apiFetch('/grandstream/cti/enable', {
        method: 'POST',
        body: { ip, credentials },
      })
    },

    async disableCTI(ip: string, credentials: any = {}) {
      return apiFetch('/grandstream/cti/disable', {
        method: 'POST',
        body: { ip, credentials },
      })
    },

    async provisionCTIFeatures(ip: string, enableCti = true, enableSnmp = true, snmpConfig: any = {}, credentials: any = {}) {
      return apiFetch('/grandstream/cti/provision', {
        method: 'POST',
        body: { ip, enable_cti: enableCti, enable_snmp: enableSnmp, snmp_config: snmpConfig, credentials },
      })
    },

    async testCTIFeatures(ip: string, credentials: any = {}) {
      return apiFetch('/grandstream/cti/test', {
        method: 'POST',
        body: { ip, credentials },
      })
    },

    // SNMP Operations
    async enableSNMP(ip: string, snmpConfig: any = {}, credentials: any = {}) {
      return apiFetch('/grandstream/snmp/enable', {
        method: 'POST',
        body: { ip, snmp_config: snmpConfig, credentials },
      })
    },

    async disableSNMP(ip: string, credentials: any = {}) {
      return apiFetch('/grandstream/snmp/disable', {
        method: 'POST',
        body: { ip, credentials },
      })
    },

    async getSNMPStatus(ip: string, credentials: any = {}) {
      return apiFetch('/grandstream/snmp/status', {
        method: 'POST',
        body: { ip, credentials },
      })
    },
  }
}
