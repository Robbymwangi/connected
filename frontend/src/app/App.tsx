import { useState } from 'react'
import { AssessmentsScreen } from '../features/assessments/AssessmentsScreen'
import { ClassesScreen } from '../features/classes/ClassesScreen'
import { Dashboard } from '../features/dashboard/Dashboard'
import { SyncScreen } from '../features/sync/SyncScreen'
import { AppShell } from '../layout/AppShell'
import type { NavId } from '../layout/navigation'
import { home, type Location } from './location'
import { useSessionStore } from './useSessionStore'

export default function App() {
  const [location, setLocation] = useState<Location>(home)
  const navigate = (screen: NavId) =>
    setLocation(
      screen === 'assessments' ? { screen: 'assessments' }
      : screen === 'sync' ? { screen: 'sync' }
      : screen === 'classes' ? { screen: 'classes' }
      : { screen },
    )
  const store = useSessionStore()

  return (
    <AppShell active={location.screen} onNavigate={navigate}>
      {location.screen === 'dashboard' && (
        <Dashboard
          conflicts={store.conflicts}
          onNavigate={navigate}
          onCreateAssessment={() => setLocation({ screen: 'assessments', creating: true })}
          onViewConflicts={(highlight) => setLocation({ screen: 'sync', highlight })}
        />
      )}
      {location.screen === 'assessments' && (
        <AssessmentsScreen
          store={store}
          assessmentId={location.assessmentId}
          view={location.view}
          creating={location.creating}
          onOpen={(assessmentId, view) => setLocation({ screen: 'assessments', assessmentId, view })}
          onBackToList={() => setLocation({ screen: 'assessments' })}
        />
      )}
      {location.screen === 'classes' && (
        <ClassesScreen
          store={store}
          classId={location.classId}
          studentId={location.studentId}
          teacherId={location.teacherId}
          onOpenClass={(classId) => setLocation({ screen: 'classes', classId })}
          onOpenStudent={(classId, studentId) => setLocation({ screen: 'classes', classId, studentId })}
          onOpenTeacher={(teacherId) => setLocation({ screen: 'classes', teacherId })}
          onBackToList={() => setLocation({ screen: 'classes' })}
          onOpenGrid={(assessmentId) => setLocation({ screen: 'assessments', assessmentId, view: 'grid' })}
          onOpenReport={(assessmentId) => setLocation({ screen: 'assessments', assessmentId, view: 'report' })}
        />
      )}
      {location.screen === 'sync' && (
        <SyncScreen
          key={location.highlight ?? ''}
          store={store}
          highlight={location.highlight}
          onOpenGrid={(assessmentId) => setLocation({ screen: 'assessments', assessmentId, view: 'grid' })}
        />
      )}
      {location.screen === 'reports' && (
        <div className="px-5 pt-6 pb-10 lg:px-8">
          <h1 className="text-2xl font-bold text-foreground capitalize">{location.screen}</h1>
          <p className="mt-1 text-sm text-muted-foreground">Not built yet.</p>
        </div>
      )}
    </AppShell>
  )
}
