import { defineStore } from 'pinia'

export const useAuthStore = defineStore('auth', {
  state: () => ({
    token: null as string | null,
    user: null as any,
    isAuthenticated: false,
  }),

  actions: {
    async login(username: string, password: string) {
      const config = useRuntimeConfig()
      
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

        return true
      } catch (error) {
        console.error('Login error:', error)
        return false
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
  },
})
