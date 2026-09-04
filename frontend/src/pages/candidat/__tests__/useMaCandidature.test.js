import { renderHook, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { apiClient } from '../../../lib/apiClient.js'
import { ApiError } from '../../../lib/ApiError.js'
import { useMaCandidature } from '../useMaCandidature.js'

vi.mock('../../../lib/apiClient.js', () => ({ apiClient: { get: vi.fn() } }))

afterEach(() => vi.clearAllMocks())

describe('useMaCandidature', () => {
  it('404 -> status "none"', async () => {
    apiClient.get.mockRejectedValueOnce(new ApiError('http', { status: 404 }))
    const { result } = renderHook(() => useMaCandidature())
    await waitFor(() => expect(result.current.status).toBe('none'))
    expect(result.current.statutPublic).toBeNull()
  })

  it('200 -> mappe les primitives d’affichage, et RIEN d’autre', async () => {
    apiClient.get.mockResolvedValueOnce({
      data: {
        id: 'c-1',
        numero_dossier: 'CASA-2026-000009',
        statut_public: 'decision_publiee',
        decision: 'non_retenu',
        motif_communicable: null,
        date_soumission: '2026-06-01T10:00:00+00:00',
        filiere: { id: 'f1', code: 'cuisine', nom: 'Agent de cuisine' },
        campagne: { id: 'k1', nom: 'Cohorte 2026' },
        pieces_dossier: [{ id: 'p1' }, { id: 'p2' }, { id: 'p3' }],
        // bruit qui ne doit JAMAIS être exposé par le hook :
        reponses: { sc01_scolarise_actuellement: 'non' },
        classement: [{ rang: 1 }],
        experiences: [{ domaine: 'hotellerie' }],
      },
    })
    const { result } = renderHook(() => useMaCandidature())
    await waitFor(() => expect(result.current.status).toBe('ready'))

    expect(result.current).toEqual({
      status: 'ready',
      statutPublic: 'decision_publiee',
      decision: 'non_retenu',
      motifCommunicable: null,
      numeroDossier: 'CASA-2026-000009',
      dateSoumission: '2026-06-01T10:00:00+00:00',
      filiereNom: 'Agent de cuisine',
      campagneNom: 'Cohorte 2026',
      piecesCount: 3,
    })
    // aucune clé « reponses » / « classement » / « experiences » / « rang »
    expect(Object.keys(result.current)).not.toEqual(
      expect.arrayContaining(['reponses', 'classement', 'experiences', 'rang', 'score', 'statutInterne']),
    )
  })

  it('erreur serveur -> status "error"', async () => {
    apiClient.get.mockRejectedValueOnce(new ApiError('server', { status: 500 }))
    const { result } = renderHook(() => useMaCandidature())
    await waitFor(() => expect(result.current.status).toBe('error'))
  })
})
