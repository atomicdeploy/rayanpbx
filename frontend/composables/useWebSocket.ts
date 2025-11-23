import { ref, computed } from 'vue'

export interface WebSocketMessage {
  type: string
  payload: any
  timestamp: string
}

// Singleton WebSocket instance and state
let wsInstance: WebSocket | null = null
const listeners = new Map<string, Set<(payload: any) => void>>()

// Reconnection state (shared across all uses of the composable)
let reconnectAttempts = 0
let reconnectTimer: ReturnType<typeof setTimeout> | null = null
const MAX_RECONNECT_ATTEMPTS = 10
const BASE_RECONNECT_DELAY = 1000

export const useWebSocket = () => {
  const config = useRuntimeConfig()
  const authStore = useAuthStore()
  const wsStore = useWebSocketStore()

  const connect = () => {
    // Return early if already connected
    if (wsInstance?.readyState === WebSocket.OPEN) {
      return
    }

    const token = authStore.token
    if (!token) {
      wsStore.setError('No authentication token available')
      return
    }

    try {
      const wsUrl = `${config.public.wsUrl}?token=${token}`
      wsInstance = new WebSocket(wsUrl)

      wsInstance.onopen = () => {
        wsStore.setConnected(true)
        wsStore.setReconnecting(false)
        wsStore.setError(null)
        reconnectAttempts = 0
        console.log('✅ WebSocket connected')
      }

      ws.value.onmessage = (event) => {
        try {
          const message: WebSocketMessage = JSON.parse(event.data)
          
          // Notify all listeners for this message type
          const typeListeners = listeners.get(message.type)
          if (typeListeners) {
            typeListeners.forEach(callback => callback(message.payload))
          }

          // Also notify wildcard listeners
          const wildcardListeners = listeners.get('*')
          if (wildcardListeners) {
            wildcardListeners.forEach(callback => callback(message))
          }
        } catch (error) {
          console.error('Failed to parse WebSocket message:', error)
        }
      }

      wsInstance.onerror = (error) => {
        console.error('WebSocket error:', error)
        wsStore.setError('WebSocket connection error')
      }

      wsInstance.onclose = () => {
        wsStore.setConnected(false)
        console.log('👋 WebSocket disconnected')
        
        // Attempt to reconnect with exponential backoff
        if (reconnectAttempts < MAX_RECONNECT_ATTEMPTS) {
          wsStore.setReconnecting(true)
          const delay = Math.min(
            BASE_RECONNECT_DELAY * Math.pow(2, reconnectAttempts),
            30000 // Max 30 seconds
          )
          reconnectAttempts++
          
          console.log(`🔄 Reconnecting in ${delay}ms (attempt ${reconnectAttempts})...`)
          reconnectTimer = setTimeout(() => {
            connect()
          }, delay)
        } else {
          wsStore.setError('Failed to reconnect after multiple attempts')
          wsStore.setReconnecting(false)
        }
      }
    } catch (error) {
      console.error('Failed to create WebSocket connection:', error)
      wsStore.setError('Failed to create WebSocket connection')
    }
  }

  const disconnect = () => {
    if (reconnectTimer) {
      clearTimeout(reconnectTimer)
      reconnectTimer = null
    }
    
    if (wsInstance) {
      wsInstance.close()
      wsInstance = null
    }
    
    wsStore.setConnected(false)
    wsStore.setReconnecting(false)
  }

  const send = (type: string, payload: any) => {
    if (wsInstance?.readyState === WebSocket.OPEN) {
      const message: WebSocketMessage = {
        type,
        payload,
        timestamp: new Date().toISOString(),
      }
      wsInstance.send(JSON.stringify(message))
    } else {
      console.warn('WebSocket is not connected, cannot send message')
    }
  }

  const on = (type: string, callback: (payload: any) => void) => {
    if (!listeners.has(type)) {
      listeners.set(type, new Set())
    }
    listeners.get(type)!.add(callback)

    // Return unsubscribe function
    return () => {
      const typeListeners = listeners.get(type)
      if (typeListeners) {
        typeListeners.delete(callback)
        if (typeListeners.size === 0) {
          listeners.delete(type)
        }
      }
    }
  }

  const off = (type: string, callback?: (payload: any) => void) => {
    if (callback) {
      const typeListeners = listeners.get(type)
      if (typeListeners) {
        typeListeners.delete(callback)
        if (typeListeners.size === 0) {
          listeners.delete(type)
        }
      }
    } else {
      listeners.delete(type)
    }
  }

  return {
    state: computed(() => ({
      connected: wsStore.connected,
      reconnecting: wsStore.reconnecting,
      error: wsStore.error,
    })),
    connect,
    disconnect,
    send,
    on,
    off,
  }
}
