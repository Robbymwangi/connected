import { AssessmentsScreen } from '../features/assessments/AssessmentsScreen'
import { SignIn } from '../features/auth/SignIn'
import { ClassesScreen } from '../features/classes/ClassesScreen'
import { Dashboard } from '../features/dashboard/Dashboard'
import { ReportsScreen } from '../features/reports/ReportsScreen'
import { SyncScreen } from '../features/sync/SyncScreen'
import type { Resolver } from '../lib/conflicts'
import type { CurrentUser } from '../lib/session'
import { AppShell } from '../layout/AppShell'
import type { NavId } from '../layout/navigation'
import { AuthProvider } from './AuthProvider'
import { useAuthSession } from './useAuthSession'
import { useSchoolDirectory } from './useSchoolDirectory'
import { useLocation } from './useLocation'
import { useSessionStore } from './useSessionStore'

export default function App() {
  const auth = useAuthSession()

  /* Loading is the brief window while the cached session is read back
      (Dexie, lib/sessionStorage.ts); it resolves before there is
     anything meaningful to show either way. */
  if (auth.status === 'loading') return null

  if (auth.status === 'signedOut') {
    return <SignIn onSignIn={auth.signIn} signingIn={auth.signingIn} error={auth.error} />
  }

  return <AuthenticatedApp user={auth.user} onSignOut={auth.signOut} />
}

/* Split out from App so its hooks (useLocation, useSessionStore) are only
   ever called once a user exists to call them with: App itself branches on
   auth.status before either hook runs, and that branch must not change how
   many hooks the same component instance calls across renders. */
function AuthenticatedApp({ user, onSignOut }: { user: CurrentUser; onSignOut: () => Promise<void> }) {
  /* The URL is the source of truth for where the user is (ADR 0005). */
  const [location, setLocation] = useLocation()
  const directory = useSchoolDirectory(user.id)
  const navigate = (screen: NavId) =>
    setLocation(
      screen === 'assessments' ? { screen: 'assessments' }
      : screen === 'sync' ? { screen: 'sync' }
      : screen === 'classes' ? { screen: 'classes' }
      : { screen },
    )
  const me: Resolver = { id: user.id, name: user.fullName, moderatedSubjects: user.moderatedSubjectIds }
  const store = useSessionStore(me)

  return (
    <AuthProvider value={{ user, signOut: onSignOut }}>
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
            directory={directory}
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
            directory={directory}
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
    </AuthProvider>
  )
}
