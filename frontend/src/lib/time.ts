/* Small date helpers. Everything here is computed locally; nothing needs the network. */

export function greetingFor(date: Date): string {
  const hour = date.getHours()
  if (hour < 12) return 'Good morning'
  if (hour < 17) return 'Good afternoon'
  return 'Good evening'
}

/* "Thursday, 28 August 2026" */
export function formatLongDate(date: Date): string {
  return new Intl.DateTimeFormat('en-GB', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  }).format(date)
}

/* "just now", "5m ago", "2h ago", "3d ago". Coarse on purpose: the sync line is a
   reassurance, not a log.

   Elapsed time floors: a unit is only reported once it has fully passed. Rounding
   would report "1m ago" at 59.6 seconds, claiming more drift than has occurred. */
export function formatRelative(from: Date, now: Date = new Date()): string {
  const seconds = Math.max(0, Math.floor((now.getTime() - from.getTime()) / 1000))
  if (seconds < 60) return 'just now'
  const minutes = Math.floor(seconds / 60)
  if (minutes < 60) return `${minutes}m ago`
  const hours = Math.floor(minutes / 60)
  if (hours < 24) return `${hours}h ago`
  return `${Math.floor(hours / 24)}d ago`
}

/* "27 Aug 2026, 14:32", for the audit-style timestamps on conflicts. */
export function formatDateTime(iso: string): string {
  return new Intl.DateTimeFormat('en-GB', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  }).format(new Date(iso))
}

/* "1 student", "28 students". */
export function count(n: number, noun: string): string {
  return `${n} ${noun}${n === 1 ? '' : 's'}`
}

/* A date-only ISO string (yyyy-mm-dd) as a local calendar date. new Date("2016-03-12")
   would parse it as UTC midnight, which west of Greenwich formats as the day before. */
export function parseLocalDate(iso: string): Date {
  const [y, m, d] = iso.split('-').map(Number)
  return new Date(y, m - 1, d)
}
