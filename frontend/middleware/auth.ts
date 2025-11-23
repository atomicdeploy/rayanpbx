export default defineNuxtRouteMiddleware((to, from) => {
  // Only run on client side to access localStorage
  if (process.server) {
    return
  }
  
  const token = localStorage.getItem('rayanpbx_token')
  
  // Redirect to login if not authenticated and not already on login page
  if (!token && to.path !== '/login') {
    return navigateTo('/login')
  }
  
  // Redirect to home if authenticated and trying to access login page
  if (token && to.path === '/login') {
    return navigateTo('/')
  }
})
