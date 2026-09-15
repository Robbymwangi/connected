import {
  ChartColumn,
  ClipboardList,
  LayoutGrid,
  RefreshCw,
  Users,
  type LucideIcon,
} from 'lucide-react'

export type NavId = 'dashboard' | 'classes' | 'assessments' | 'reports' | 'sync'

export type NavEntry = {
  id: NavId
  label: string
  icon: LucideIcon
}

/* Two groups, rendered as one list. The split is kept so a divider or heading can be
   added between them without touching the sidebar. Navigation depth is capped at two
   levels; these are the five level-one destinations. */
export const NAV_MAIN: NavEntry[] = [
  { id: 'dashboard', label: 'Dashboard', icon: LayoutGrid },
  { id: 'classes', label: 'Classes', icon: Users },
  { id: 'assessments', label: 'Assessments', icon: ClipboardList },
]

export const NAV_TOOLS: NavEntry[] = [
  { id: 'reports', label: 'Reports', icon: ChartColumn },
  { id: 'sync', label: 'Sync', icon: RefreshCw },
]
