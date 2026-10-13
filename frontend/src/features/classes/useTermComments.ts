import { liveQuery } from 'dexie'
import { useEffect, useState } from 'react'
import { localDatabaseFor } from '../../lib/localDatabase'
import type { OutboxEntry } from '../../lib/outbox'
import { termViews, type TermView } from '../../lib/termComments'

export type TermCommentsState =
  | { status: 'loading' }
  | { status: 'ready'; terms: TermView[] }
  | { status: 'error' }

/* A stream's terms for comments and report cards, read live from the local store, so a
   closure, a comment, or a card the pull delivers appears without a reload, and a command
   shows the moment it is queued. Works offline: everything here is already on the device. */
export function useTermComments(userId: string, classId: string, year: number): TermCommentsState {
  const database = localDatabaseFor(userId)
  const [state, setState] = useState<TermCommentsState>({ status: 'loading' })

  useEffect(() => {
    const subscription = liveQuery(() =>
      database.transaction(
        'r',
        [database.assessments, database.term_closures, database.comments, database.reports, database.outbox],
        async () => {
          const closures = await database.term_closures.where('classId').equals(classId).toArray()
          const closureIds = closures.map((row) => row.id)
          const [assessments, comments, outbox] = await Promise.all([
            database.assessments.where('classId').equals(classId).toArray(),
            closureIds.length ? database.comments.where('termClosureId').anyOf(closureIds).toArray() : Promise.resolve([]),
            database.outbox.filter((entry) => entry.table === 'comments').toArray() as Promise<OutboxEntry[]>,
          ])
          const studentIds = [...new Set(comments.map((row) => String(row.studentId)))]
          const reports = studentIds.length ? await database.reports.where('studentId').anyOf(studentIds).toArray() : []

          return { terms: termViews({ classId, year, assessments, closures, comments, reports, outbox }) }
        },
      ),
    ).subscribe({
      next: (data) => setState({ status: 'ready', ...data }),
      error: () => setState({ status: 'error' }),
    })
    return () => subscription.unsubscribe()
  }, [database, classId, year])

  return state
}
