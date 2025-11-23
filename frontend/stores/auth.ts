import { defineStore } from 'pinia'

export const useAuthStore = defineStore('auth', {
  state: () => ({
    token: null as string | null,
    user: null as any,
    isAuthenticated: false,
    lastError: null as string | null,
  }),

  actions: {
    async login(username: string, password: string) {
      const config = useRuntimeConfig()
      this.lastError = null
      
      try {
        const response = await $fetch(`${config.public.apiBase}/auth/login`, {
          method: 'POST',
          body: { username, password },
        })

        this.token = response.token
        this.user = response.user
        this.isAuthenticated = true

        // Store in localStorage
        if (process.client) {
          localStorage.setItem('rayanpbx_token', response.token)
        }

        return { success: true }
      } catch (error: any) {
        console.error('Login error:', error)
        
        // Check if error has a status code (meaning we got a response from server)
        if (error.status || error.statusCode) {
          const status = error.status || error.statusCode
          
          // Invalid credentials
          if (status === 422 || status === 401) {
            this.lastError = 'invalid_credentials'
            return { success: false, error: 'invalid_credentials' }
          }
          
          // Other server errors - check if there's a message from backend
          if (error.data?.message) {
            this.lastError = error.data.message
            return { success: false, error: error.data.message }
          }
          
          // Fallback to unknown error
          this.lastError = 'unknown_error'
          return { success: false, error: 'unknown_error' }
        }
        
        // No response means network/connection error
        this.lastError = 'backend_unreachable'
        return { success: false, error: 'backend_unreachable' }
      }
    },

    async logout() {
      const config = useRuntimeConfig()

      try {
        await $fetch(`${config.public.apiBase}/auth/logout`, {
          method: 'POST',
          headers: {
            Authorization: `Bearer ${this.token}`,
          },
        })
      } catch (error) {
        console.error('Logout error:', error)
      }

      this.token = null
      this.user = null
      this.isAuthenticated = false

      if (process.client) {
        localStorage.removeItem('rayanpbx_token')
      }
    },

    async checkAuth() {
      if (process.client) {
        const token = localStorage.getItem('rayanpbx_token')
        if (token) {
          this.token = token
          this.isAuthenticated = true
          // Optionally fetch user details
        }
      }
    },

    async checkBackendHealth() {
      const config = useRuntimeConfig()
      
      try {
        const response = await $fetch(`${config.public.apiBase}/health`, {
          method: 'GET',
        })
        
        return { 
          available: true, 
          data: response 
        }
      } catch (error: any) {
        console.error('Backend health check failed:', error)
        return { 
          available: false, 
          error: error.message || 'Connection failed' 
        }
      }
    },
  },
})
