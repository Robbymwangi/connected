import { useState } from 'react'
import { AppShell } from '../layout/AppShell'
import type { NavId } from '../layout/navigation'

/* Navigation is local state until a router lands; only the dashboard exists yet, so
   the other destinations show a placeholder. */
export default function App() {
  const [active, setActive] = useState<NavId>('dashboard')

  return (
    <AppShell active={active} onNavigate={setActive}>
      <div className="px-4 pt-4 pb-8 lg:px-5">
        <div className="mx-auto max-w-7xl">
          <h2 className="text-2xl font-semibold text-foreground capitalize">{active}</h2>
          <p className="mt-1 text-sm text-muted-foreground">
            Dashboard cards arrive in session three.
          </p>
        </div>
      </div>
    </AppShell>
  )
}
