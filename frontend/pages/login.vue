<template>
  <div class="min-h-screen flex items-center justify-center bg-gradient-to-br from-blue-500 to-purple-600 dark:from-blue-900 dark:to-purple-900">
    <div class="card w-full max-w-md">
      <div class="text-center mb-8">
        <h1 class="text-3xl font-bold text-gray-900 dark:text-white">{{ $t('app.title') }}</h1>
        <p class="text-gray-600 dark:text-gray-400 mt-2">{{ $t('app.subtitle') }}</p>
      </div>

      <form @submit.prevent="handleLogin" class="space-y-6">
        <div>
          <label class="label">{{ $t('auth.username') }}</label>
          <input
            v-model="username"
            type="text"
            class="input"
            required
            autocomplete="username"
          />
        </div>

        <div>
          <label class="label">{{ $t('auth.password') }}</label>
          <input
            v-model="password"
            type="password"
            class="input"
            required
            autocomplete="current-password"
          />
        </div>

        <div v-if="error" class="text-red-600 dark:text-red-400 text-sm">
          {{ error }}
        </div>

        <button
          type="submit"
          class="btn btn-primary w-full"
          :disabled="loading"
        >
          {{ loading ? $t('common.loading') : $t('auth.loginButton') }}
        </button>
      </form>

      <div class="mt-6 text-center text-sm text-gray-600 dark:text-gray-400">
        <p>{{ $t('app.subtitle') }}</p>
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

const handleLogin = async () => {
  loading.value = true
  error.value = ''

  const success = await authStore.login(username.value, password.value)

  if (success) {
    router.push('/')
  } else {
    error.value = t('auth.loginError')
  }

  loading.value = false
}

// Redirect if already authenticated
onMounted(async () => {
  await authStore.checkAuth()
  if (authStore.isAuthenticated) {
    router.push('/')
  }
})
</script>
