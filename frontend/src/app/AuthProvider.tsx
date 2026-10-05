import type { ReactNode } from 'react'
import { AuthContext, type AuthContextValue } from './AuthContext'

export function AuthProvider({ value, children }: { value: AuthContextValue; children: ReactNode }) {
  return <AuthContext value={value}>{children}</AuthContext>
}