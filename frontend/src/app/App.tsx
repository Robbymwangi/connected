import { useState } from 'react'
import { activeConflicts, type ActiveConflict } from '../fixtures/conflicts'
import { AssessmentsScreen } from '../features/assessments/AssessmentsScreen'
import { Dashboard } from '../features/dashboard/Dashboard'
import { AppShell } from '../layout/AppShell'
import type { NavId } from '../layout/navigation'
import { home, type Location } from './location'

export default function App() {
  const [location, setLocation] = useState<Location>(home)

  /* Active conflicts are shared by the dashboard banner, the marking grid, and the
     Sync screen, so they live here until the local store exists. */
  const [conflicts, setConflicts] = useState<ActiveConflict[]>(activeConflicts)
  const resolveConflict = (id: string) => setConflicts((prev) => prev.filter((k) => k.id !== id))
  const navigate = (screen: NavId) =>
    setLocation(screen === 'assessments' ? { screen: 'assessments' } : { screen })

  return (
    <AppShell active={location.screen} onNavigate={navigate}>
      {location.screen === 'dashboard' && (
        <Dashboard
          conflicts={conflicts}
          onNavigate={navigate}
          onCreateAssessment={() => setLocation({ screen: 'assessments', creating: true })}
        />
      )}
      {location.screen === 'assessments' && (
        <AssessmentsScreen
          assessmentId={location.assessmentId}
          view={location.view}
          creating={location.creating}
          conflicts={conflicts}
          onResolveConflict={resolveConflict}
          onOpen={(assessmentId, view) => setLocation({ screen: 'assessments', assessmentId, view })}
          onBackToList={() => setLocation({ screen: 'assessments' })}
        />
      )}
      {location.screen !== 'dashboard' && location.screen !== 'assessments' && (
        <div className="px-5 pt-6 pb-10 lg:px-8">
          <h1 className="text-2xl font-bold text-foreground capitalize">{location.screen}</h1>
          <p className="mt-1 text-sm text-muted-foreground">Not built yet.</p>
        </div>
      )}
    </AppShell>
  )
}
