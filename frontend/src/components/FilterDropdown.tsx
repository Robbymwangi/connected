import { Check, ChevronDown } from 'lucide-react'
import { useState } from 'react'
import { Popover } from './Popover'

type FilterDropdownProps<T extends string> = {
  value: T
  options: readonly T[]
  onChange: (value: T) => void
  disabled?: boolean
  label?: string
}

export function FilterDropdown<T extends string>({
  value,
  options,
  onChange,
  disabled = false,
  label,
}: FilterDropdownProps<T>) {
  const [open, setOpen] = useState(false)

  return (
    <div className="relative">
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        disabled={disabled}
        aria-label={label}
        aria-expanded={open}
        className="flex items-center gap-1.5 rounded-xl border border-border bg-card px-3 py-2 text-sm font-medium text-foreground transition-colors hover:bg-muted disabled:cursor-not-allowed disabled:opacity-40"
      >
        <span>{value}</span>
        <ChevronDown className="size-3.5 shrink-0 text-muted-foreground" />
      </button>
      <Popover
        open={open}
        onClose={() => setOpen(false)}
        anchor="top-left"
        className="min-w-40 bg-card backdrop-blur-lg"
      >
        <div className="py-1.5" role="listbox">
          {options.map((option) => (
            <button
              key={option}
              type="button"
              role="option"
              aria-selected={option === value}
              onClick={() => {
                onChange(option)
                setOpen(false)
              }}
              className={`flex w-full items-center justify-between px-4 py-2.5 text-left text-sm transition-colors hover:bg-muted/50 ${
                option === value ? 'font-semibold text-foreground' : 'text-foreground/80'
              }`}
            >
              <span>{option}</span>
              {option === value && <Check className="size-3 shrink-0 text-primary" />}
            </button>
          ))}
        </div>
      </Popover>
    </div>
  )
}
