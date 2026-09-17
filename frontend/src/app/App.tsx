import { AssessmentsScreen } from '../features/assessments/AssessmentsScreen'
import { ClassesScreen } from '../features/classes/ClassesScreen'
import { Dashboard } from '../features/dashboard/Dashboard'
import { ReportsScreen } from '../features/reports/ReportsScreen'
import { SyncScreen } from '../features/sync/SyncScreen'
import { AppShell } from '../layout/AppShell'
import type { NavId } from '../layout/navigation'
import { useLocation } from './useLocation'
import { useSessionStore } from './useSessionStore'

export default function App() {
  /* The URL is the source of truth for where the user is (ADR 0005). */
  const [location, setLocation] = useLocation()
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
          onOpenStudent={(classId, studentId) => setLocation({ screen: 'classes', classId, studentId })}
          onCreateClosed={() => setLocation({ screen: 'assessments' }, { replace: true })}
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
        <ReportsScreen
          store={store}
          onOpenStudent={(classId, studentId) => setLocation({ screen: 'classes', classId, studentId })}
        />
      )}
    </AppShell>
  )
}
