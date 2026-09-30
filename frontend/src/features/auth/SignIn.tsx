import { useState, type FormEvent } from 'react'

type SignInProps = {
  onSignIn: (email: string, password: string) => Promise<void>
  signingIn: boolean
  error: string | null
}

const INPUT =
  'w-full rounded-xl border border-border bg-muted/30 px-3 py-2.5 text-sm text-foreground placeholder:text-muted-foreground focus:ring-2 focus:ring-primary/30 focus:outline-none'
const LABEL = 'mb-1 block text-xs font-medium text-muted-foreground'

/* Not a screen in the Location/router sense (app/routes.ts): it gates the
   whole app rather than sitting behind the sidebar, so it stays outside the
   two-level navigation cap rather than pretending to be a third level of it. */
export function SignIn({ onSignIn, signingIn, error }: SignInProps) {
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')

  const submit = (e: FormEvent) => {
    e.preventDefault()
    if (signingIn) return
    void onSignIn(email, password)
  }

  return (
    <div className="flex min-h-screen items-center justify-center bg-shell px-4">
      <form
        onSubmit={submit}
        className="w-full max-w-sm rounded-2xl border border-border bg-card p-8 shadow-sm"
      >
        <div className="mb-6 flex items-center gap-3">
          <div className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary text-sm font-bold text-primary-foreground shadow-sm">
            C
          </div>
          <span className="font-display text-lg font-semibold tracking-tight text-foreground">
            ConnectED
          </span>
        </div>

        <h1 className="mb-1 font-display text-xl font-semibold text-foreground">Sign in</h1>
        <p className="mb-6 text-sm text-muted-foreground">Use your school account.</p>

        <label className={LABEL} htmlFor="email">
          Email
        </label>
        <input
          id="email"
          type="email"
          autoComplete="username"
          required
          disabled={signingIn}
          value={email}
          onChange={(e) => setEmail(e.target.value)}
          className={INPUT}
        />

        <label className={`mt-4 ${LABEL}`} htmlFor="password">
          Password
        </label>
        <input
          id="password"
          type="password"
          autoComplete="current-password"
          required
          disabled={signingIn}
          value={password}
          onChange={(e) => setPassword(e.target.value)}
          className={INPUT}
        />

        {error && (
          <p role="alert" className="mt-3 text-sm text-danger">
            {error}
          </p>
        )}

        <button
          type="submit"
          disabled={signingIn}
          className="mt-6 w-full rounded-xl bg-primary py-2.5 text-sm font-bold text-primary-foreground transition-opacity hover:opacity-90 disabled:opacity-40"
        >
          {signingIn ? 'Signing in…' : 'Sign in'}
        </button>
      </form>
    </div>
  )
}
