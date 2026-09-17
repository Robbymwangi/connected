import { home, type Location } from './location'

/* URL to Location and back (ADR 0005). Both directions are pure; the hook in
   useLocation.ts is the only thing that touches the browser. */

export function pathFor(loc: Location): string {
  switch (loc.screen) {
    case 'dashboard':
      return '/'
    case 'assessments':
      if (loc.assessmentId) return `/assessments/${enc(loc.assessmentId)}/${loc.view}`
      return loc.creating ? '/assessments?new' : '/assessments'
    case 'classes':
      if (loc.teacherId) return `/teachers/${enc(loc.teacherId)}`
      if (loc.classId && loc.studentId) return `/classes/${enc(loc.classId)}/students/${enc(loc.studentId)}`
      if (loc.classId) return `/classes/${enc(loc.classId)}`
      return '/classes'
    case 'sync':
      return loc.highlight ? `/sync?highlight=${enc(loc.highlight)}` : '/sync'
    case 'reports':
      return '/reports'
  }
}

/* Anything unrecognised is the dashboard; a wrong shape under a known screen is
   that screen's list. Screens themselves cope with an id that names nothing. */
export function parseLocation(pathname: string, search = ''): Location {
  const parts = pathname.split('/').filter(Boolean).map(dec)
  const params = new URLSearchParams(search)

  switch (parts[0]) {
    case undefined:
      return home
    case 'assessments': {
      const [, id, view] = parts
      if (id && (view === 'grid' || view === 'report')) return { screen: 'assessments', assessmentId: id, view }
      return params.has('new') ? { screen: 'assessments', creating: true } : { screen: 'assessments' }
    }
    case 'classes': {
      const [, classId, students, studentId] = parts
      if (classId && students === 'students' && studentId) return { screen: 'classes', classId, studentId }
      if (classId) return { screen: 'classes', classId }
      return { screen: 'classes' }
    }
    case 'teachers': {
      const [, teacherId] = parts
      return teacherId ? { screen: 'classes', teacherId } : { screen: 'classes' }
    }
    case 'sync': {
      const highlight = params.get('highlight')
      return highlight ? { screen: 'sync', highlight } : { screen: 'sync' }
    }
    case 'reports':
      return { screen: 'reports' }
    default:
      return home
  }
}

const enc = encodeURIComponent
const dec = (s: string) => {
  try {
    return decodeURIComponent(s)
  } catch {
    return s
  }
}
