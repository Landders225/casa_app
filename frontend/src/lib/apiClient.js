import { ApiError } from './ApiError.js'
import { ensureCsrfCookie, readXsrfToken } from './csrf.js'

/**
 * Client HTTP unique de l'application (ADR-01, ADR-02).
 *
 *  - base = VITE_API_URL (défaut '/api'), same-origin en Docker ;
 *  - `credentials: 'include'` : les cookies (session httpOnly + XSRF-TOKEN)
 *    voyagent, aucun jeton n'est stocké/lu côté JS hormis le cookie XSRF ;
 *  - toute requête mutante déclenche d'abord le cycle CSRF (ensureCsrfCookie) et
 *    pose `X-XSRF-TOKEN` ;
 *  - un 419 (jeton expiré) est retenté UNE fois après renouvellement du cookie ;
 *  - les réponses non-OK deviennent des `ApiError` typées (jamais de `Response`
 *    brute au-delà de ce module).
 */

const BASE = (import.meta.env.VITE_API_URL ?? '/api').replace(/\/$/, '')
const MUTATING = new Set(['POST', 'PUT', 'PATCH', 'DELETE'])

async function parseBody(res) {
  const type = res.headers.get('content-type') || ''
  if (res.status === 204 || !type.includes('application/json')) return null
  try {
    return await res.json()
  } catch {
    return null
  }
}

function toApiError(res, body) {
  const message = body?.message || null

  switch (res.status) {
    case 401:
      return new ApiError('unauthenticated', { status: 401, message: message || 'Non authentifié.' })
    case 403:
      return new ApiError('forbidden', { status: 403, message: message || 'Accès refusé.' })
    case 419:
      return new ApiError('csrf', { status: 419, message: message || 'Session expirée, réessayez.' })
    case 422:
      return new ApiError('validation', {
        status: 422,
        message: message || 'Certaines informations sont invalides.',
        errors: body?.errors ?? {},
      })
    case 429:
      return new ApiError('rate_limited', {
        status: 429,
        message: message || 'Trop de tentatives. Patientez un instant.',
        retryAfter: Number(res.headers.get('retry-after')) || null,
      })
    default:
      if (res.status >= 500) {
        return new ApiError('server', { status: res.status, message: message || 'Erreur serveur.' })
      }
      return new ApiError('http', { status: res.status, message: message || `Requête échouée (${res.status}).`, payload: body })
  }
}

async function send(method, path, body, { retriedCsrf = false } = {}) {
  const isMutating = MUTATING.has(method)

  if (isMutating) {
    await ensureCsrfCookie({ force: retriedCsrf })
  }

  const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
  if (isMutating) {
    const token = readXsrfToken()
    if (token) headers['X-XSRF-TOKEN'] = token
  }
  if (body !== undefined) headers['Content-Type'] = 'application/json'

  let res
  try {
    res = await fetch(`${BASE}${path}`, {
      method,
      credentials: 'include',
      headers,
      body: body !== undefined ? JSON.stringify(body) : undefined,
    })
  } catch {
    throw new ApiError('network', { message: 'Impossible de contacter le serveur.' })
  }

  // Jeton CSRF expiré : on renouvelle le cookie et on retente une seule fois.
  if (res.status === 419 && isMutating && !retriedCsrf) {
    return send(method, path, body, { retriedCsrf: true })
  }

  const parsed = await parseBody(res)
  if (!res.ok) throw toApiError(res, parsed)

  return parsed
}

export const apiClient = {
  get: (path) => send('GET', path, undefined),
  post: (path, body) => send('POST', path, body ?? {}),
  put: (path, body) => send('PUT', path, body ?? {}),
  patch: (path, body) => send('PATCH', path, body ?? {}),
  del: (path) => send('DELETE', path, undefined),
}
