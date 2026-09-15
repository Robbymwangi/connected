export type Crumb = {
  label: string
  /* Absent on the last crumb, which is the current place. */
  onClick?: () => void
}

export function Breadcrumb({ items }: { items: Crumb[] }) {
  return (
    <nav aria-label="Breadcrumb" className="flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground">
      {items.map((item, i) => (
        <span key={`${item.label}-${i}`} className="flex items-center gap-1.5">
          {i > 0 && <span className="opacity-40">/</span>}
          {item.onClick ? (
            <button
              type="button"
              onClick={item.onClick}
              className="font-medium transition-colors hover:text-foreground"
            >
              {item.label}
            </button>
          ) : (
            <span className="font-semibold text-foreground" aria-current="page">
              {item.label}
            </span>
          )}
        </span>
      ))}
    </nav>
  )
}
