import { useCallback, useEffect, useState } from 'react'

export type Theme = 'light' | 'dark'

/* Shared with the inline script in index.html, which applies the stored value before
   React mounts. Changing one without the other reintroduces the white flash. */
const STORAGE_KEY = 'theme'

/* The class on <html> is the source of truth at mount time: the inline script has
   already set it from storage, so React only needs to read it, not decide it. */
function readDocumentTheme(): Theme {
  return document.documentElement.classList.contains('dark') ? 'dark' : 'light'
}

function applyTheme(theme: Theme) {
  document.documentElement.classList.toggle('dark', theme === 'dark')
  try {
    localStorage.setItem(STORAGE_KEY, theme)
  } catch {
    /* Storage unavailable. The choice still applies for this session. */
  }
}

export function useTheme() {
  const [theme, setTheme] = useState<Theme>(readDocumentTheme)

  useEffect(() => {
    applyTheme(theme)
  }, [theme])

  const toggleTheme = useCallback(() => {
    setTheme((current) => (current === 'dark' ? 'light' : 'dark'))
  }, [])

  return { theme, toggleTheme }
}
