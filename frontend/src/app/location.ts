import type { NavId } from '../layout/navigation'

/* Where the user is. Local state until a router lands; this is the shape the router
   will replace, so screens take and return it rather than reaching into App. */
export type Location =
  | { screen: Exclude<NavId, 'assessments' | 'sync' | 'classes'> }
  /* Classes: the list, a stream, a student within a stream (reached contextually,
     which is how the two-level cap allows a third), or a teacher. */
  | { screen: 'classes'; classId?: undefined; studentId?: undefined; teacherId?: undefined }
  | { screen: 'classes'; classId: string; studentId?: string; teacherId?: undefined }
  | { screen: 'classes'; teacherId: string; classId?: undefined; studentId?: undefined }
  /* highlight draws the eye to one conflict on arrival, from the dashboard banner. */
  | { screen: 'sync'; highlight?: string }
  /* creating opens the new-assessment dialog on arrival, so the dashboard can start
     the flow rather than merely pointing at it. */
  | { screen: 'assessments'; assessmentId?: undefined; view?: undefined; creating?: boolean }
  | { screen: 'assessments'; assessmentId: string; view: 'grid' | 'report'; creating?: undefined }

export const home: Location = { screen: 'dashboard' }
