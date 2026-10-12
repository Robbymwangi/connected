import { Card } from '../../../components/Card'
import { ChevronRow } from '../../../components/ChevronRow'
import type { RecentAccess } from '../../../lib/recentAccess'

type RecentCardProps = {
  items: RecentAccess[]
  onExpand: () => void
  onOpen: (item: RecentAccess) => void
}

export function RecentCard({ items, onExpand, onOpen }: RecentCardProps) {
  return (
    <Card title="Recently Accessed" onExpand={onExpand} expandLabel="Open classes">
      {items.length > 0 ? items.map((item) => (
        <ChevronRow
          key={item.key}
          primary={item.primary}
          secondary={item.secondary}
          onClick={() => onOpen(item)}
        />
      )) : <p className="px-5 py-8 text-center text-sm text-muted-foreground">Pages you open on this device will appear here.</p>}
    </Card>
  )
}
