import { createContext, useCallback, useContext, useEffect, useMemo, useState } from "react"

/**
 * shadcn/ui "Dark mode → Vite" theme provider (class strategy on <html>).
 * Persists to localStorage under `storageKey`; `system` follows prefers-color-scheme.
 */
const ThemeProviderContext = createContext({ theme: "system", resolvedTheme: "light", setTheme: () => null })

function readStored(storageKey, fallback) {
  try {
    return window.localStorage.getItem(storageKey) || fallback
  } catch {
    return fallback
  }
}

function systemTheme() {
  return window.matchMedia?.("(prefers-color-scheme: dark)").matches ? "dark" : "light"
}

export function ThemeProvider({ children, defaultTheme = "system", storageKey = "vite-ui-theme", ...props }) {
  const [theme, setThemeState] = useState(() => readStored(storageKey, defaultTheme))
  const resolvedTheme = theme === "system" ? systemTheme() : theme

  useEffect(() => {
    const root = window.document.documentElement
    root.classList.remove("light", "dark")
    root.classList.add(resolvedTheme)
  }, [resolvedTheme])

  const setTheme = useCallback(
    next => {
      try {
        window.localStorage.setItem(storageKey, next)
      } catch {
        /* storage unavailable (private mode) — keep in-memory state */
      }
      setThemeState(next)
    },
    [storageKey],
  )

  const value = useMemo(() => ({ theme, resolvedTheme, setTheme }), [theme, resolvedTheme, setTheme])

  return (
    <ThemeProviderContext.Provider {...props} value={value}>
      {children}
    </ThemeProviderContext.Provider>
  )
}

export function useTheme() {
  const context = useContext(ThemeProviderContext)
  if (context === undefined) throw new Error("useTheme must be used within a ThemeProvider")
  return context
}
