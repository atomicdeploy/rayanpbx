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
      if (error.response?.status === 401) {
        // Unauthorized - clear auth and redirect to login
        await authStore.logout()
        navigateTo('/login')
      }
      throw error
    }
  }

  return {
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
  }
}
