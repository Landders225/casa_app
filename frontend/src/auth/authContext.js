import { createContext } from 'react'

/**
 * Contexte d'authentification (valeur fournie par <AuthProvider>).
 * Isolé dans son propre fichier pour ne pas casser le Fast Refresh
 * (react/only-export-components).
 *
 * Forme : { user, status, role, login(email,password), logout(), reload() }
 */
export const AuthContext = createContext(null)
