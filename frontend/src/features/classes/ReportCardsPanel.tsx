import { liveQuery } from 'dexie'
import { CloudOff, FileDown } from 'lucide-react'
import { useEffect, useState } from 'react'
import { getReportDownload } from '../../api/reports'
import { useCurrentUser } from '../../app/AuthContext'
import { useConnectivity } from '../../lib/connectivity'
import { localDatabaseFor, type LocalRecord } from '../../lib/localDatabase'
import { Panel } from './Panel'

type Link = { reportId: string; url: string; expiresAt: number } | { reportId: string; error: string }

const ACTION =
  'flex items-center gap-1.5 rounded-xl border border-border bg-card px-3 py-1.5 text-xs font-semibold text-foreground transition-colors hover:bg-muted disabled:cursor-not-allowed disabled:opacity-50'

/* A student's report cards (docs/spec/workflow.md, Report cards): which terms have one is
   known offline, from the synced reports rows; the PDF itself needs a connection. Asking for
   it fetches a link that expires in minutes, shown for the teacher to open, since a browser
   blocks a window opened after a request completes. */
export function ReportCardsPanel({ studentId, token }: { studentId: string; token: string }) {
  const user = useCurrentUser()
  const { isOnline } = useConnectivity()
  /* Keyed by the student they belong to, so a profile never shows the previous student's cards
     in the moment before the new query answers. */
  const [loaded, setLoaded] = useState<{ studentId: string; rows: LocalRecord[] }>({ studentId, rows: [] })
  const [fetching, setFetching] = useState<string | null>(null)
  const [link, setLink] = useState<Link | null>(null)

  useEffect(() => {
    const database = localDatabaseFor(user.id)
    const subscription = liveQuery(() => database.reports.where('studentId').equals(studentId).toArray()).subscribe({
      next: (rows) => setLoaded({ studentId, rows: rows.filter((row) => row.deletedAt == null) }),
      error: () => setLoaded({ studentId, rows: [] }),
    })
    return () => subscription.unsubscribe()
  }, [user.id, studentId])

  /* The link expires within minutes; once it has, the panel offers a fresh one. */
  useEffect(() => {
    if (!link || !('url' in link)) return
    const timer = setTimeout(() => setLink(null), Math.max(0, link.expiresAt - Date.now()))
    return () => clearTimeout(timer)
  }, [link])

  const reports = loaded.studentId === studentId ? loaded.rows : []
  const sorted = [...reports].sort((a, b) => Number(b.year) - Number(a.year) || Number(b.term) - Number(a.term))

  const fetchLink = async (reportId: string) => {
    setFetching(reportId)
    setLink(null)
    try {
      const { data } = await getReportDownload(reportId, token)
      const expiresAt = Date.parse(data.expiresAt)
      setLink({ reportId, url: data.url, expiresAt: Number.isNaN(expiresAt) ? Date.now() + 5 * 60_000 : expiresAt })
    } catch (error) {
      setLink({ reportId, error: error instanceof Error ? error.message : 'The card could not be fetched.' })
    } finally {
      setFetching(null)
    }
  }

  return (
    <Panel title="Report cards" aside={sorted.length ? `${sorted.length}` : undefined}>
      {sorted.length === 0 ? (
        <p className="px-5 py-6 text-center text-sm text-muted-foreground">No report cards issued yet for this student.</p>
      ) : (
        <div className="divide-y divide-border/40">
          {sorted.map((report) => (
            <div key={report.id} className="flex flex-wrap items-center gap-3 px-5 py-3">
              <span className="flex-1 text-sm font-semibold text-foreground">Term {String(report.term)}, {String(report.year)}</span>
              {link?.reportId === report.id && 'url' in link ? (
                <a href={link.url} target="_blank" rel="noopener noreferrer" className={ACTION}>
                  <FileDown className="size-3.5" /> Open the PDF
                </a>
              ) : isOnline ? (
                <button type="button" className={ACTION} disabled={fetching !== null} onClick={() => void fetchLink(report.id)}>
                  <FileDown className="size-3.5" /> {fetching === report.id ? 'Fetching…' : 'Get the card'}
                </button>
              ) : (
                <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
                  <CloudOff className="size-3.5" /> Needs a connection
                </span>
              )}
              {link?.reportId === report.id && 'error' in link && (
                <p className="w-full text-xs text-danger" role="alert">{link.error}</p>
              )}
            </div>
          ))}
        </div>
      )}
    </Panel>
  )
}
