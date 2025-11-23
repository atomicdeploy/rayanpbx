<template>
  <div class="min-h-screen flex items-center justify-center bg-gradient-to-br from-blue-500 via-purple-500 to-pink-500 dark:from-blue-900 dark:via-purple-900 dark:to-pink-900 relative overflow-hidden">
    <!-- Animated Background Elements -->
    <div class="absolute inset-0 overflow-hidden">
      <div class="absolute -top-40 -end-40 w-80 h-80 bg-white dark:bg-gray-800 rounded-full mix-blend-multiply dark:mix-blend-soft-light filter blur-xl opacity-10 animate-blob"></div>
      <div class="absolute -bottom-40 -start-40 w-80 h-80 bg-purple-300 dark:bg-purple-700 rounded-full mix-blend-multiply dark:mix-blend-soft-light filter blur-xl opacity-10 animate-blob animation-delay-2000"></div>
      <div class="absolute top-40 start-40 w-80 h-80 bg-pink-300 dark:bg-pink-700 rounded-full mix-blend-multiply dark:mix-blend-soft-light filter blur-xl opacity-10 animate-blob animation-delay-4000"></div>
    </div>
    
    <!-- Login Card with Glassmorphism -->
    <div class="relative card w-full max-w-md backdrop-blur-sm bg-white/90 dark:bg-gray-800/90 fade-in">
      <!-- Logo -->
      <div class="text-center mb-8">
        <Logo />
      </div>

      <!-- Backend checking spinner -->
      <div v-if="checkingBackend" class="flex flex-col items-center justify-center space-y-4 py-8">
        <svg class="w-12 h-12 animate-spin text-blue-600 dark:text-blue-400" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
        </svg>
        <p class="text-gray-600 dark:text-gray-400">{{ $t('auth.checkingBackend') }}</p>
      </div>

      <!-- Backend info display -->
      <div v-else-if="backendInfo && !error" class="mb-4 p-3 bg-green-50 dark:bg-green-900/20 rounded-lg text-sm">
        <div class="flex items-center gap-2 text-green-700 dark:text-green-400">
          <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          <span>{{ $t('auth.backendConnected') }}: {{ backendInfo.app?.name || 'RayanPBX' }}</span>
        </div>
      </div>

      <form v-if="!checkingBackend" @submit.prevent="handleLogin" class="space-y-6">
        <div>
          <label class="label flex items-center">
            <svg class="w-4 h-4 me-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
            </svg>
            {{ $t('auth.username') }}
          </label>
          <div class="relative">
            <input
              v-model="username"
              type="text"
              class="input ps-10"
              required
              autocomplete="username"
              :placeholder="$t('auth.usernamePlaceholder')"
            />
            <div class="absolute inset-y-0 start-0 flex items-center ps-3 pointer-events-none">
              <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
              </svg>
            </div>
          </div>
        </div>

        <div>
          <label class="label flex items-center">
            <svg class="w-4 h-4 me-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
            </svg>
            {{ $t('auth.password') }}
          </label>
          <div class="relative">
            <input
              v-model="password"
              :type="showPassword ? 'text' : 'password'"
              class="input ps-10 pe-10"
              required
              autocomplete="current-password"
              :placeholder="$t('auth.passwordPlaceholder')"
            />
            <div class="absolute inset-y-0 start-0 flex items-center ps-3 pointer-events-none">
              <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
              </svg>
            </div>
            <button
              type="button"
              @click="showPassword = !showPassword"
              class="absolute inset-y-0 end-0 flex items-center pe-3 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
            >
              <svg v-if="!showPassword" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
              </svg>
              <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" />
              </svg>
            </button>
          </div>
        </div>

        <div v-if="error" class="flex items-center gap-2 text-red-600 dark:text-red-400 text-sm bg-red-50 dark:bg-red-900/20 p-3 rounded-lg">
          <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          <span>{{ error }}</span>
        </div>

        <button
          type="submit"
          class="btn btn-primary w-full relative overflow-hidden group"
          :disabled="loading"
        >
          <span v-if="!loading" class="flex items-center justify-center">
            <svg class="w-5 h-5 me-2 group-hover:animate-bounce" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1" />
            </svg>
            {{ $t('auth.loginButton') }}
          </span>
          <span v-else class="flex items-center justify-center">
            <svg class="w-5 h-5 me-2 animate-spin" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            {{ $t('common.loading') }}
          </span>
        </button>
      </form>

      <div class="mt-6 text-center text-sm text-gray-600 dark:text-gray-400">
        <p class="flex items-center justify-center gap-1">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          {{ $t('auth.pamInfo') }}
        </p>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
const { t } = useI18n()
const authStore = useAuthStore()
const router = useRouter()

const username = ref('')
const password = ref('')
const loading = ref(false)
const error = ref('')
const showPassword = ref(false)
const backendInfo = ref<any>(null)
const checkingBackend = ref(true)

const handleLogin = async () => {
  loading.value = true
  error.value = ''

  const result = await authStore.login(username.value, password.value)

  if (result.success) {
    router.push('/')
  } else {
    // Show appropriate error message based on the error type
    if (result.error === 'backend_unreachable') {
      error.value = t('auth.backendUnreachable')
    } else if (result.error === 'invalid_credentials') {
      error.value = t('auth.loginError')
    } else if (result.error === 'unknown_error') {
      error.value = t('auth.unknownError')
    } else {
      // Display the actual error message from the backend
      error.value = result.error
    }
  }

  loading.value = false
}

// Check backend health and redirect if already authenticated
onMounted(async () => {
  await authStore.checkAuth()
  if (authStore.isAuthenticated) {
    router.push('/')
    return
  }

  // Check backend availability
  const health = await authStore.checkBackendHealth()
  checkingBackend.value = false
  
  if (health.available) {
    backendInfo.value = health.data
  } else {
    error.value = t('auth.backendUnreachable')
  }
})
</script>

<style scoped>
@keyframes blob {
  0%, 100% {
    transform: translate(0, 0) scale(1);
  }
  33% {
    transform: translate(30px, -50px) scale(1.1);
  }
  66% {
    transform: translate(-20px, 20px) scale(0.9);
  }
}

.animate-blob {
  animation: blob 7s infinite;
}

.animation-delay-2000 {
  animation-delay: 2s;
}

.animation-delay-4000 {
  animation-delay: 4s;
}
</style>
