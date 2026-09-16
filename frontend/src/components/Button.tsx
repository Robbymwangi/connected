import type { ButtonHTMLAttributes, Ref } from 'react'

type Variant = 'ghost' | 'danger'

const VARIANT_CLASSES: Record<Variant, string> = {
  /* Quiet icon or text button that only reveals itself on hover. */
  ghost:
    'rounded-md p-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground',
  /* Outlined destructive action that fills on hover. */
  danger:
    'rounded-xl border border-danger/20 bg-danger/5 text-danger shadow-xs transition-all hover:bg-danger hover:text-danger-foreground',
}

type ButtonProps = ButtonHTMLAttributes<HTMLButtonElement> & {
  variant?: Variant
  /* React 19 passes ref as an ordinary prop; no forwardRef needed. */
  ref?: Ref<HTMLButtonElement>
}

export function Button({
  variant = 'ghost',
  className = '',
  type = 'button',
  ref,
  ...rest
}: ButtonProps) {
  return (
    <button
      ref={ref}
      type={type}
      className={`${VARIANT_CLASSES[variant]} ${className}`}
      {...rest}
    />
  )
}
