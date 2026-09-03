import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { apiClient } from '../apiClient.js'
import { ApiError } from '../ApiError.js'

/** Réponse fetch minimale. */
function jsonResponse(status, body, headers = {}) {
  return {
    ok: status >= 200 && status < 300,
    status,
    headers: { get: (k) => headers[k.toLowerCase()] ?? (k.toLowerCase() === 'content-type' ? 'application/json' : null) },
    json: async () => body,
  }
}

function clearCookies() {
  for (const c of document.cookie.split(';')) {
    const name = c.split('=')[0].trim()
    if (name) document.cookie = `${name}=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/`
  }
}

describe('apiClient', () => {
  beforeEach(() => {
    clearCookies()
    vi.stubGlobal('fetch', vi.fn())
  })
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('GET ne déclenche pas le cycle CSRF', async () => {
    fetch.mockResolvedValueOnce(jsonResponse(200, { data: { ok: true } }))

    const res = await apiClient.get('/me')

    expect(res).toEqual({ data: { ok: true } })
    expect(fetch).toHaveBeenCalledTimes(1)
    expect(fetch).toHaveBeenCalledWith('/api/me', expect.objectContaining({ method: 'GET', credentials: 'include' }))
  })

  it('une mutation récupère /sanctum/csrf-cookie puis pose X-XSRF-TOKEN', async () => {
    fetch
      // 1. csrf-cookie -> pose le cookie
      .mockImplementationOnce(async () => {
        document.cookie = 'XSRF-TOKEN=tok%3D%3D;path=/'
        return jsonResponse(204, null)
      })
      // 2. le POST lui-même
      .mockResolvedValueOnce(jsonResponse(200, { data: { id: 1 } }))

    await apiClient.post('/login', { email: 'a@b.ci', password: 'x' })

    expect(fetch).toHaveBeenNthCalledWith(1, '/sanctum/csrf-cookie', expect.objectContaining({ method: 'GET' }))
    const [, opts] = fetch.mock.calls[1]
    expect(opts.method).toBe('POST')
    expect(opts.headers['X-XSRF-TOKEN']).toBe('tok==') // cookie URL-décodé
    expect(opts.headers['X-Requested-With']).toBe('XMLHttpRequest')
  })

  it('un 419 est retenté une seule fois après renouvellement du cookie', async () => {
    document.cookie = 'XSRF-TOKEN=old;path=/'
    fetch
      .mockResolvedValueOnce(jsonResponse(419, { message: 'expired' })) // 1er POST
      .mockImplementationOnce(async () => {
        document.cookie = 'XSRF-TOKEN=fresh;path=/'
        return jsonResponse(204, null)
      }) // re-csrf
      .mockResolvedValueOnce(jsonResponse(200, { data: { ok: true } })) // POST retenté

    const res = await apiClient.post('/campagnes/x/publier')

    expect(res).toEqual({ data: { ok: true } })
    expect(fetch).toHaveBeenCalledTimes(3)
    expect(fetch.mock.calls[2][1].headers['X-XSRF-TOKEN']).toBe('fresh')
  })

  it('un 419 persistant devient une ApiError kind "csrf"', async () => {
    document.cookie = 'XSRF-TOKEN=x;path=/'
    fetch
      .mockResolvedValueOnce(jsonResponse(419, { message: 'expired' }))
      .mockResolvedValueOnce(jsonResponse(204, null))
      .mockResolvedValueOnce(jsonResponse(419, { message: 'expired' }))

    await expect(apiClient.post('/x')).rejects.toMatchObject({ kind: 'csrf', status: 419 })
  })

  it('normalise 401 / 403 / 422 / 429', async () => {
    fetch.mockResolvedValueOnce(jsonResponse(401, { message: 'Non authentifié.' }))
    await expect(apiClient.get('/me')).rejects.toMatchObject({ kind: 'unauthenticated' })

    fetch.mockResolvedValueOnce(jsonResponse(403, { message: 'nope' }))
    await expect(apiClient.get('/admin/x')).rejects.toMatchObject({ kind: 'forbidden' })

    document.cookie = 'XSRF-TOKEN=x;path=/'
    fetch.mockResolvedValueOnce(jsonResponse(422, { message: 'invalide', errors: { email: ['requis'] } }))
    const err = await apiClient.patch('/candidat/profil', {}).catch((e) => e)
    expect(err).toBeInstanceOf(ApiError)
    expect(err.kind).toBe('validation')
    expect(err.errors).toEqual({ email: ['requis'] })

    fetch.mockResolvedValueOnce(jsonResponse(429, { message: 'stop' }, { 'retry-after': '42' }))
    await expect(apiClient.get('/x')).rejects.toMatchObject({ kind: 'rate_limited', retryAfter: 42 })
  })

  it('un échec réseau devient kind "network"', async () => {
    fetch.mockRejectedValueOnce(new TypeError('Failed to fetch'))
    await expect(apiClient.get('/me')).rejects.toMatchObject({ kind: 'network' })
  })
})
