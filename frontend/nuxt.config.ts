export default defineNuxtConfig({
  devtools: { enabled: true },
  
  modules: [
    '@nuxtjs/tailwindcss',
    '@nuxtjs/color-mode',
    '@pinia/nuxt',
    '@nuxtjs/i18n',
  ],

  colorMode: {
    preference: 'dark',
    fallback: 'dark',
    classSuffix: '',
  },

  i18n: {
    locales: [
      {
        code: 'en',
        file: 'en.json',
        name: 'English',
        dir: 'ltr',
      },
      {
        code: 'fa',
        file: 'fa.json',
        name: 'فارسی',
        dir: 'rtl',
      },
    ],
    lazy: true,
    langDir: 'lang',
    defaultLocale: 'en',
    strategy: 'no_prefix',
  },

  css: [
    '~/assets/css/main.css',
  ],

  runtimeConfig: {
    public: {
      apiBase: process.env.NUXT_PUBLIC_API_BASE || 'http://localhost:8000/api',
    },
  },

  app: {
    head: {
      title: 'RayanPBX - Modern SIP Server Management',
      meta: [
        { charset: 'utf-8' },
        { name: 'viewport', content: 'width=device-width, initial-scale=1' },
        { name: 'description', content: 'Modern, elegant SIP Server Management Toolkit' },
      ],
      link: [
        { rel: 'icon', type: 'image/x-icon', href: '/favicon.ico' },
      ],
    },
  },

  compatibilityDate: '2024-01-01',
})
