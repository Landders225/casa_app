import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useAuth } from '../../../auth/useAuth.js'
import { apiClient } from '../../../lib/apiClient.js'
import { ApiError } from '../../../lib/ApiError.js'
import { Documents } from '../Documents.jsx'

vi.mock('../../../lib/apiClient.js', () => ({ apiClient: { get: vi.fn() } }))
vi.mock('../../../auth/useAuth.js', () => ({ useAuth: vi.fn() }))

function piece(over = {}) {
  return {
    id: 'p-1',
    rattachement: 'dossier',
    type_document_code: 'cni',
    nom_original: 'cni.pdf',
    type_mime: 'application/pdf',
    taille_octets: 204800,
    depose_le: '2026-06-01T09:00:00+00:00',
    url: '/api/pieces/p-1/download',
    ...over,
  }
}

function payload(over = {}) {
  return {
    id: 'c-1',
    numero_dossier: 'CASA-2026-000042',
    statut_public: 'en_cours_de_traitement',
    pieces_dossier: [],
    experiences: [],
    ...over,
  }
}

/** `AppShell` (Lot 12c) appelle AUSSI `GET /candidat/notifications/compteur` — router par URL. */
function mockCandidature(reponseCandidature) {
  apiClient.get.mockImplementation((path) => {
    if (path === '/candidature') return reponseCandidature()
    return Promise.resolve({ data: { non_lues: 0 } })
  })
}

function renderScreen() {
  return render(<MemoryRouter><Documents /></MemoryRouter>)
}

beforeEach(() => {
  vi.clearAllMocks()
  useAuth.mockReturnValue({ user: { email: 'aya@example.ci', profil: { prenom: 'Aya', nom: 'T' } }, role: 'candidat', logout: vi.fn() })
})
afterEach(() => vi.restoreAllMocks())

describe('Documents — écran candidat (Lot 14)', () => {
  it('aucune candidature -> écran d’atterrissage, CTA vers le wizard', async () => {
    mockCandidature(() => Promise.reject(new ApiError('http', { status: 404 })))
    renderScreen()

    expect(await screen.findByText(/pas encore de dossier de candidature/i)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /démarrer mon dossier/i })).toHaveAttribute('href', '/candidat/candidature')
  })

  it('brouillon -> renvoi vers le wizard, aucune liste de pièces affichée', async () => {
    mockCandidature(() => Promise.resolve({ data: payload({ statut_public: 'brouillon' }) }))
    renderScreen()

    expect(await screen.findByText(/se gèrent depuis votre dossier de candidature/i)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /reprendre mon dossier/i })).toHaveAttribute('href', '/candidat/candidature')
    expect(screen.queryByText(/déposées/i)).not.toBeInTheDocument()
  })

  it('erreur serveur -> message d’erreur, pas de crash', async () => {
    mockCandidature(() => Promise.reject(new ApiError('server', { status: 500 })))
    renderScreen()
    expect(await screen.findByRole('alert')).toHaveTextContent(/n'ont pas pu être charg/i)
  })

  it('soumis -> liste les 5 pièces obligatoires (déposé/manquant), compteur correct (Lot D : residence/lettre retirées)', async () => {
    mockCandidature(() => Promise.resolve({
      data: payload({
        pieces_dossier: [
          piece({ id: 'p-cni', type_document_code: 'cni', nom_original: 'cni.pdf', url: '/api/pieces/p-cni/download' }),
          piece({ id: 'p-cv', type_document_code: 'cv', nom_original: 'cv.pdf', url: '/api/pieces/p-cv/download' }),
        ],
      }),
    }))
    renderScreen()

    expect(await screen.findByText('2/5 déposées')).toBeInTheDocument()
    expect(screen.getByText('Carte Nationale d’Identité')).toBeInTheDocument()
    expect(screen.getByText('Couverture Maladie Universelle (CMU)')).toBeInTheDocument()
    expect(screen.getAllByText('Manquant')).toHaveLength(3) // diplôme, photo, cmu
    // residence/lettre ne sont plus proposées du tout (ni requises, ni « manquantes »).
    expect(screen.queryByText('Certificat de résidence')).not.toBeInTheDocument()
    expect(screen.queryByText('Lettre de motivation')).not.toBeInTheDocument()
    expect(screen.queryByText('Pièces conservées')).not.toBeInTheDocument()

    // Téléchargement : l'URL vient EXACTEMENT de la réponse serveur, jamais reconstruite.
    const lienCni = screen.getByRole('link', { name: /cni\.pdf/i })
    expect(lienCni).toHaveAttribute('href', '/api/pieces/p-cni/download')
    expect(lienCni).toHaveAttribute('target', '_blank')
    const lienCv = screen.getByRole('link', { name: /cv\.pdf/i })
    expect(lienCv).toHaveAttribute('href', '/api/pieces/p-cv/download')
  })

  it('Lot D — un dossier qui a DÉJÀ residence/lettre les affiche en lecture seule sous « Pièces conservées », sans les compter dans les 5 obligatoires', async () => {
    mockCandidature(() => Promise.resolve({
      data: payload({
        pieces_dossier: [
          piece({ id: 'p-cni', type_document_code: 'cni', nom_original: 'cni.pdf' }),
          piece({ id: 'p-diplome', type_document_code: 'diplome', nom_original: 'diplome.pdf' }),
          piece({ id: 'p-cv', type_document_code: 'cv', nom_original: 'cv.pdf' }),
          piece({ id: 'p-photo', type_document_code: 'photo', nom_original: 'photo.pdf' }),
          piece({ id: 'p-cmu', type_document_code: 'cmu', nom_original: 'cmu.pdf' }),
          piece({ id: 'p-residence', type_document_code: 'residence', nom_original: 'residence.pdf', url: '/api/pieces/p-residence/download' }),
          piece({ id: 'p-lettre', type_document_code: 'lettre', nom_original: 'lettre.pdf' }),
        ],
      }),
    }))
    renderScreen()

    expect(await screen.findByText('5/5 déposées')).toBeInTheDocument() // pas 7/5
    expect(screen.getByText('Pièces conservées')).toBeInTheDocument()
    expect(screen.getByText('Certificat de résidence')).toBeInTheDocument()
    expect(screen.getByText('Lettre de motivation')).toBeInTheDocument()

    const lienResidence = screen.getByRole('link', { name: /residence\.pdf/i })
    expect(lienResidence).toHaveAttribute('href', '/api/pieces/p-residence/download')
  })

  it('aucun bouton dépôt/suppression RENDU après soumission (pas juste désactivé)', async () => {
    mockCandidature(() => Promise.resolve({
      data: payload({ pieces_dossier: [piece()] }),
    }))
    renderScreen()
    await screen.findByText(/déposées/i)

    for (const nom of [/déposer/i, /retirer/i, /supprimer/i, /ajouter/i]) {
      expect(screen.queryByRole('button', { name: nom })).not.toBeInTheDocument()
    }
    // Aucun input file caché non plus.
    expect(document.querySelector('input[type="file"]')).toBeNull()
  })

  it('justificatifs d’expérience : un par expérience, libellé domaine + durée', async () => {
    mockCandidature(() => Promise.resolve({
      data: payload({
        experiences: [
          {
            id: 'exp-1', domaine: 'hotellerie', duree_categorie: '6_12',
            justificatif: piece({ id: 'p-exp1', nom_original: 'attestation.pdf', url: '/api/pieces/p-exp1/download' }),
          },
          { id: 'exp-2', domaine: 'commerce', duree_categorie: 'moins_6', justificatif: null },
        ],
      }),
    }))
    renderScreen()

    expect(await screen.findByText('Hôtellerie · 6 à 12 mois')).toBeInTheDocument()
    expect(screen.getByText('Commerce / accueil clientèle · Moins de 6 mois')).toBeInTheDocument()
    const lien = screen.getByRole('link', { name: /attestation\.pdf/i })
    expect(lien).toHaveAttribute('href', '/api/pieces/p-exp1/download')
  })

  it('aucune section « Justificatifs d’expérience » si aucune expérience déclarée', async () => {
    mockCandidature(() => Promise.resolve({ data: payload({ experiences: [] }) }))
    renderScreen()
    await screen.findByText(/déposées/i)
    expect(screen.queryByText(/justificatifs d.exp[ée]rience/i)).not.toBeInTheDocument()
  })

  it('aucune donnée interne affichée (chemin_stockage, id brut)', async () => {
    mockCandidature(() => Promise.resolve({
      data: payload({ pieces_dossier: [piece({ id: 'uuid-tres-interne-1234' })] }),
    }))
    const { container } = renderScreen()
    await screen.findByText(/déposées/i)

    expect(container.textContent).not.toMatch(/chemin_stockage/i)
    expect(container.textContent).not.toContain('uuid-tres-interne-1234')
  })
})
