import { renderHook, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { apiClient } from '../../../lib/apiClient.js'
import { ApiError } from '../../../lib/ApiError.js'
import { useCampagneOuverte } from '../useCampagneOuverte.js'

vi.mock('../../../lib/apiClient.js', () => ({ apiClient: { get: vi.fn() } }))

afterEach(() => vi.clearAllMocks())

describe('useCampagneOuverte', () => {
  it('démarre à null (chargement) — fail-open, jamais un faux « fermée »', () => {
    apiClient.get.mockReturnValue(new Promise(() => {})) // jamais résolue
    const { result } = renderHook(() => useCampagneOuverte())
    expect(result.current).toBeNull()
  })

  it('campagne_ouverte: true -> true', async () => {
    apiClient.get.mockResolvedValueOnce({ data: { campagne_ouverte: true } })
    const { result } = renderHook(() => useCampagneOuverte())
    await waitFor(() => expect(result.current).toBe(true))
  })

  it('campagne_ouverte: false -> false', async () => {
    apiClient.get.mockResolvedValueOnce({ data: { campagne_ouverte: false } })
    const { result } = renderHook(() => useCampagneOuverte())
    await waitFor(() => expect(result.current).toBe(false))
  })

  it('échec réseau -> reste null (fail-open, jamais d’erreur affichée)', async () => {
    apiClient.get.mockRejectedValueOnce(new ApiError('server', { status: 500 }))
    const { result } = renderHook(() => useCampagneOuverte())
    await new Promise((r) => setTimeout(r, 10))
    expect(result.current).toBeNull()
  })
})
