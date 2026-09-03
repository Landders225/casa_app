/**
 * Erreur normalisée renvoyée par apiClient. Le reste de l'app ne manipule jamais
 * de `Response` brute ni de code HTTP directement — seulement `err.kind`.
 *
 * kind :
 *  - 'unauthenticated'  401 — session absente/expirée (AuthContext bascule guest)
 *  - 'forbidden'        403 — authentifié mais rôle/policy insuffisant
 *  - 'validation'       422 — `err.errors` = { champ: [messages] }, `err.message` global
 *  - 'rate_limited'     429 — `err.retryAfter` (secondes) si fourni
 *  - 'csrf'             419 — jeton CSRF invalide même après re-tentative
 *  - 'server'           5xx
 *  - 'network'          fetch a échoué (hors-ligne, DNS, CORS...)
 *  - 'http'             autre code inattendu
 */
export class ApiError extends Error {
  constructor(kind, { status = 0, message, errors = null, retryAfter = null, payload = null } = {}) {
    super(message || kind)
    this.name = 'ApiError'
    this.kind = kind
    this.status = status
    this.errors = errors
    this.retryAfter = retryAfter
    this.payload = payload
  }

  /** Messages de validation aplatis (utile pour un résumé). */
  get validationMessages() {
    if (!this.errors) return []
    return Object.values(this.errors).flat()
  }
}
