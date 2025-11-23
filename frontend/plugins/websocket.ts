export default defineNuxtPlugin(() => {
  // WebSocket will be initialized when needed by components
  // This ensures the plugin runs on both server and client
  if (process.client) {
    console.log('🚀 RayanPBX WebSocket plugin loaded')
  }
})
