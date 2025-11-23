import { defineStore } from 'pinia'

interface WebSocketState {
  connected: boolean
  reconnecting: boolean
  error: string | null
}

export const useWebSocketStore = defineStore('websocket', {
  state: (): WebSocketState => ({
    connected: false,
    reconnecting: false,
    error: null,
  }),

  actions: {
    setConnected(connected: boolean) {
      this.connected = connected
    },

    setReconnecting(reconnecting: boolean) {
      this.reconnecting = reconnecting
    },

    setError(error: string | null) {
      this.error = error
    },
  },
})
