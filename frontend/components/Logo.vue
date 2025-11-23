<template>
  <div class="flex items-center justify-center">
    <div class="relative">
      <!-- Logo Circle with Pulse Animation -->
      <div class="absolute inset-0 bg-blue-500 rounded-full animate-ping opacity-20"></div>
      
      <!-- Logo Container -->
      <div class="relative bg-gradient-to-br from-blue-600 to-purple-600 dark:from-blue-500 dark:to-purple-500 rounded-full p-6 shadow-2xl">
        <!-- Phone Icon -->
        <svg class="w-12 h-12 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
        </svg>
      </div>
    </div>
    
    <div v-if="showText" class="ms-4">
      <h1 class="text-3xl font-bold bg-gradient-to-r from-blue-600 to-purple-600 dark:from-blue-400 dark:to-purple-400 bg-clip-text text-transparent">
        RayanPBX
      </h1>
      <p v-if="showTagline" class="text-sm text-gray-600 dark:text-gray-400 mt-1">
        Modern SIP Server Management
      </p>
    </div>
  </div>
</template>

<script setup lang="ts">
defineProps({
  showText: {
    type: Boolean,
    default: true
  },
  showTagline: {
    type: Boolean,
    default: true
  }
})
</script>

<style scoped>
@keyframes ping {
  75%, 100% {
    transform: scale(1.1);
    opacity: 0;
  }
}

.animate-ping {
  animation: ping 2s cubic-bezier(0, 0, 0.2, 1) infinite;
}
</style>
