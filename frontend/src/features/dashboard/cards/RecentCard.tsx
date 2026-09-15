import { Card } from '../../../components/Card'
import { ChevronRow } from '../../../components/ChevronRow'
import { recentlyAccessed, type RecentItem } from '../../../fixtures/dashboard'

type RecentCardProps = {
  onExpand: () => void
  onOpen: (item: RecentItem) => void
}

export function RecentCard({ onExpand, onOpen }: RecentCardProps) {
  return (
    <Card title="Recently Accessed" onExpand={onExpand} expandLabel="Open classes">
      {recentlyAccessed.map((item) => (
        <ChevronRow
          key={item.primary}
          primary={item.primary}
          secondary={item.secondary}
          onClick={() => onOpen(item)}
        />
      ))}
    </Card>
  )
}
