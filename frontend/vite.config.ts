import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'
import { VitePWA } from 'vite-plugin-pwa'
import { defineConfig } from 'vitest/config'

// https://vite.dev/config/
export default defineConfig({
  plugins: [
    react(),
    tailwindcss(),
    /* Offline install via Workbox (ADR 0004). generateSW precaches every built
       asset with a content-hashed manifest, versions the cache, and cleans up old
       ones; registerType 'prompt' means a new version waits until the user reloads,
       never swapping mid-marking. The manifest and icons in public/ are picked up
       through includeAssets. */
    VitePWA({
      registerType: 'prompt',
      injectRegister: null,
      manifest: false,
      includeAssets: ['favicon.svg', 'icons/*'],
      workbox: {
        globPatterns: ['**/*.{js,css,html,woff2,svg,png,webmanifest}'],
        navigateFallback: '/index.html',
        navigateFallbackDenylist: [/^\/api\//],
        cleanupOutdatedCaches: true,
        /* Control the page as soon as the worker activates, so a first visit is
           offline-ready without a second load; a new version still waits for the
           user's Reload (registerType 'prompt', skipWaiting left off). */
        clientsClaim: true,
      },
      devOptions: { enabled: false },
    }),
  ],
  test: {
    include: ['src/**/*.test.ts', 'src/**/*.test.tsx'],
    exclude: ['e2e/**', 'node_modules/**'],
  },
})
