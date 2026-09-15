import { ChevronRight } from 'lucide-react'

type ChevronRowProps = {
  primary: string
  secondary: string
  onClick?: () => void
}

export function ChevronRow({ primary, secondary, onClick }: ChevronRowProps) {
  return (
    <button
      type="button"
      onClick={onClick}
      className="group flex w-full items-center justify-between border-b border-border/50 px-5 py-3.5 text-left transition-colors last:border-0 hover:bg-muted/50"
    >
      <div>
        <p className="text-sm font-medium text-foreground">{primary}</p>
        <p className="mt-0.5 text-xs text-muted-foreground">{secondary}</p>
      </div>
      <ChevronRight className="ml-2 size-4 shrink-0 text-muted-foreground transition-colors group-hover:text-foreground" />
    </button>
  )
}
