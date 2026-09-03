import { ApiError } from './ApiError.js'

/**
 * Cycle CSRF de Sanctum SPA (ADR-01) :
 *  1. GET /sanctum/csrf-cookie  -> le backend pose le cookie `XSRF-TOKEN`
 *     (lisible par JS, PAS httpOnly) + le cookie de session (httpOnly).
 *  2. Chaque requête mutante renvoie la valeur du cookie `XSRF-TOKEN` (URL-décodée)
 *     dans l'en-tête `X-XSRF-TOKEN`.
 *
 * Aucun jeton n'est stocké en JS : on lit le cookie à chaque envoi. Le cookie de
 * session reste géré par le navigateur (httpOnly, inaccessible au JS).
 */

// L'endpoint csrf-cookie est à la racine, pas sous /api.
const CSRF_COOKIE_URL = '/sanctum/csrf-cookie'

let inflight = null

/** Lit et décode le cookie XSRF-TOKEN, ou null s'il est absent. */
export function readXsrfToken() {
  const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)
  return match ? decodeURIComponent(match[1]) : null
}

/**
 * Garantit qu'un cookie XSRF-TOKEN est présent. Idempotent et dé-dupliqué :
 * plusieurs mutations concurrentes au boot ne déclenchent qu'un seul GET.
 * `force` refait l'appel (utilisé après un 419).
 */
export async function ensureCsrfCookie({ force = false } = {}) {
  if (!force && readXsrfToken()) return

  if (!inflight) {
    inflight = fetch(CSRF_COOKIE_URL, {
      method: 'GET',
      credentials: 'include',
      headers: { Accept: 'application/json' },
    })
      .catch(() => {
        throw new ApiError('network', { message: 'Impossible de contacter le serveur.' })
      })
      .finally(() => {
        inflight = null
      })
  }

  const res = await inflight
  if (!res.ok) {
    throw new ApiError('server', { status: res.status, message: 'Initialisation de session impossible.' })
  }
}
