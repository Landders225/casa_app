import { useContext } from 'react'
import { AuthContext } from './authContext.js'

export function useAuth() {
  const ctx = useContext(AuthContext)
  if (ctx === null) {
    throw new Error('useAuth doit être utilisé dans un <AuthProvider>.')
  }
  return ctx
}
