import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'
import { defineConfig } from 'vitest/config'
import { precache } from './build/precache.ts'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react(), tailwindcss(), precache()],
  test: {
    /* Tests sit beside the code they cover. The environment is Node; a test that
       needs the DOM opts in with a "@vitest-environment jsdom" comment once jsdom
       is added. */
    include: ['src/**/*.test.ts', 'src/**/*.test.tsx'],
  },
})
