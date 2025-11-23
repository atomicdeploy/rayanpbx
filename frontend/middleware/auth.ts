export default defineNuxtRouteMiddleware((to, from) => {
  const authStore = useAuthStore()
  
  // Skip middleware on server side
  if (process.server) {
    return
  }
  
  // Check if user is authenticated
  if (process.client) {
    const token = localStorage.getItem('rayanpbx_token')
    
    if (!token && to.path !== '/login') {
      return navigateTo('/login')
    }
    
    if (token && to.path === '/login') {
      return navigateTo('/')
    }
  }
})
