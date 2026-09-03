import { useCallback, useEffect, useMemo, useState } from 'react'
import { apiClient } from '../lib/apiClient.js'
import { ApiError } from '../lib/ApiError.js'
import { AuthContext } from './authContext.js'

/**
 * Source unique de l'état de session (ADR-01/03).
 *
 * status :
 *  - 'loading'        : GET /api/me en cours (boot, ou après login)
 *  - 'authenticated'  : `user` renseigné (UserResource : id, email, role, profil)
 *  - 'guest'          : pas de session
 *
 * Aucun jeton n'est stocké : au rechargement de page, l'état est reconstruit
 * exclusivement depuis GET /api/me (le cookie de session, lui, persiste).
 */
export function AuthProvider({ children }) {
  const [user, setUser] = useState(null)
  const [status, setStatus] = useState('loading')

  const loadMe = useCallback(async () => {
    try {
      const res = await apiClient.get('/me')
      setUser(res.data)
      setStatus('authenticated')
    } catch (err) {
      // 401 = pas de session ; toute autre erreur (serveur/réseau) au boot :
      // on ne laisse pas l'app coincée sur 'loading', l'utilisateur pourra
      // retenter via l'écran de connexion.
      if (!(err instanceof ApiError)) {
        // eslint-disable-next-line no-console -- diagnostic utile en dev
        console.warn('Auth boot: erreur inattendue', err)
      }
      setUser(null)
      setStatus('guest')
    }
  }, [])

  // Reconstruction de la session au montage (et sur reload() explicite).
  // Effet légitime : synchronisation avec un système externe (la session serveur) —
  // `loadMe` est asynchrone, les setState sont post-await, pas synchrones.
  useEffect(() => {
    // oxlint-disable-next-line react/set-state-in-effect
    loadMe()
  }, [loadMe])

  const login = useCallback(async (email, password) => {
    // POST /api/login renvoie directement le UserResource (pas besoin de /me).
    const res = await apiClient.post('/login', { email, password })
    setUser(res.data)
    setStatus('authenticated')
    return res.data
  }, [])

  /**
   * Inscription (Lot 8b-1). POST /api/register ouvre déjà la session côté serveur
   * (auto-login, ADR-16) et renvoie le UserResource — on l'adopte directement.
   * Les ApiError (422 champ par champ, message âge/résidence, 429...) remontent
   * au formulaire.
   */
  const register = useCallback(async (payload) => {
    const res = await apiClient.post('/register', payload)
    setUser(res.data)
    setStatus('authenticated')
    return res.data
  }, [])

  const logout = useCallback(async () => {
    try {
      await apiClient.post('/logout')
    } finally {
      setUser(null)
      setStatus('guest')
    }
  }, [])

  const value = useMemo(
    () => ({ user, status, role: user?.role ?? null, login, register, logout, reload: loadMe }),
    [user, status, login, register, logout, loadMe],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}
