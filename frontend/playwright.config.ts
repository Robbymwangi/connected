import { defineConfig, devices } from '@playwright/test'

/* End-to-end tests, chiefly the offline guarantee (ADR 0004), which unit tests
   cannot cover: they need a real service worker across navigations. The offline
   spec drives a production build, so it builds and previews first.

   Local runs need the browser and its system libraries once:
     npx playwright install --with-deps chromium
   (the --with-deps part needs sudo; CI images have the libraries already.) */
export default defineConfig({
  testDir: './e2e',
  fullyParallel: false,
  workers: 1,
  timeout: 30_000,
  webServer: {
    command: 'npm run build && npm run preview -- --port 4173',
    url: 'http://localhost:4173',
    reuseExistingServer: !process.env.CI,
    timeout: 120_000,
  },
  use: {
    baseURL: 'http://localhost:4173',
    ...devices['Desktop Chrome'],
  },
})
