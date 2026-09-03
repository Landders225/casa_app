import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useAuth } from '../../../auth/useAuth.js'
import { ApiError } from '../../../lib/ApiError.js'
import { InscriptionPage } from '../InscriptionPage.jsx'

vi.mock('../../../auth/useAuth.js', () => ({ useAuth: vi.fn() }))

function renderInscription() {
  return render(
    <MemoryRouter initialEntries={['/inscription']}>
      <Routes>
        <Route path="/inscription" element={<InscriptionPage />} />
        <Route path="/candidat" element={<div>tableau de bord candidat</div>} />
        <Route path="/connexion" element={<div>écran de connexion</div>} />
      </Routes>
    </MemoryRouter>,
  )
}

const setup = () => userEvent.setup({ delay: null })

async function fillValidForm(user) {
  await user.type(screen.getByLabelText('Prénom'), 'Aya')
  await user.type(screen.getByLabelText('Nom'), 'Traoré')
  await user.type(screen.getByLabelText('Date de naissance'), '2001-05-14')
  await user.selectOptions(screen.getByLabelText('Sexe'), 'F')
  await user.type(screen.getByLabelText('Numéro CNI / récépissé'), 'CI0012345678')
  await user.type(screen.getByLabelText(/Ville de résidence/), 'Abidjan - Cocody')
  await user.type(screen.getByLabelText('Téléphone'), '0709081011')
  await user.type(screen.getByLabelText('Adresse e-mail'), 'aya@example.ci')
  await user.type(screen.getByLabelText('Mot de passe'), 'MotDePasse2026')
  await user.type(screen.getByLabelText('Confirmer le mot de passe'), 'MotDePasse2026')
  await user.click(screen.getByLabelText(/Je déclare résider en Côte d'Ivoire/))
  await user.click(screen.getByLabelText(/J'accepte les conditions/))
}

describe('InscriptionPage', () => {
  beforeEach(() => vi.clearAllMocks())

  it('rend tous les champs de l\'étape 1', () => {
    useAuth.mockReturnValue({ register: vi.fn() })
    renderInscription()
    for (const label of ['Prénom', 'Nom', 'Date de naissance', 'Sexe', 'Numéro CNI / récépissé',
      'Ville de résidence', 'Téléphone', 'Adresse e-mail', 'Mot de passe', 'Confirmer le mot de passe']) {
      expect(screen.getByLabelText(label)).toBeInTheDocument()
    }
    expect(screen.getByLabelText(/Je déclare résider en Côte d'Ivoire/)).toBeInTheDocument()
    expect(screen.getByLabelText(/J'accepte les conditions/)).toBeInTheDocument()
  })

  it('bloque côté client si les deux mots de passe diffèrent (pas d\'appel API)', async () => {
    const register = vi.fn()
    useAuth.mockReturnValue({ register })
    renderInscription()
    const user = setup()
    await fillValidForm(user)
    await user.clear(screen.getByLabelText('Confirmer le mot de passe'))
    await user.type(screen.getByLabelText('Confirmer le mot de passe'), 'autre-chose')
    await user.click(screen.getByRole('button', { name: /créer mon compte/i }))

    expect(register).not.toHaveBeenCalled()
    expect(screen.getByText('Les deux mots de passe ne correspondent pas.')).toBeInTheDocument()
  })

  it('appelle register() avec la charge attendue puis redirige vers le dashboard', async () => {
    const register = vi.fn().mockResolvedValue({ role: 'candidat' })
    useAuth.mockReturnValue({ register })
    renderInscription()
    const user = setup()
    await fillValidForm(user)
    await user.click(screen.getByRole('button', { name: /créer mon compte/i }))

    expect(register).toHaveBeenCalledWith(
      expect.objectContaining({
        prenom: 'Aya', nom: 'Traoré', sexe: 'F', date_naissance: '2001-05-14',
        cni: 'CI0012345678', ville_residence: 'Abidjan - Cocody', telephone: '0709081011',
        email: 'aya@example.ci', password: 'MotDePasse2026', password_confirmation: 'MotDePasse2026',
        residence_ci: true, cgu: true,
      }),
    )
    expect(await screen.findByText('tableau de bord candidat')).toBeInTheDocument()
  })

  it('mappe les erreurs 422 champ par champ', async () => {
    const register = vi.fn().mockRejectedValue(
      new ApiError('validation', {
        status: 422,
        errors: { email: ['Un compte existe déjà pour cette adresse e-mail.'], cni: ['Le champ CNI est requis.'] },
      }),
    )
    useAuth.mockReturnValue({ register })
    renderInscription()
    const user = setup()
    await fillValidForm(user)
    await user.click(screen.getByRole('button', { name: /créer mon compte/i }))

    expect(await screen.findByText('Un compte existe déjà pour cette adresse e-mail.')).toBeInTheDocument()
    expect(screen.getByText('Le champ CNI est requis.')).toBeInTheDocument()
    expect(screen.queryByText('tableau de bord candidat')).not.toBeInTheDocument()
  })

  it('affiche le message global d\'un 422 âge/résidence (sans `errors`)', async () => {
    const register = vi.fn().mockRejectedValue(
      new ApiError('validation', { status: 422, message: "Le programme CASA s'adresse aux personnes de 18 à 30 ans.", errors: {} }),
    )
    useAuth.mockReturnValue({ register })
    renderInscription()
    const user = setup()
    await fillValidForm(user)
    await user.click(screen.getByRole('button', { name: /créer mon compte/i }))

    expect(await screen.findByRole('alert')).toHaveTextContent("Le programme CASA s'adresse aux personnes de 18 à 30 ans.")
  })

  it('affiche un message dédié sur 429', async () => {
    const register = vi.fn().mockRejectedValue(new ApiError('rate_limited', { status: 429 }))
    useAuth.mockReturnValue({ register })
    renderInscription()
    const user = setup()
    await fillValidForm(user)
    await user.click(screen.getByRole('button', { name: /créer mon compte/i }))

    expect(await screen.findByRole('alert')).toHaveTextContent(/trop de tentatives/i)
  })

  it('avertit quand l\'âge saisi est hors 18-30 (indice, sans bloquer)', async () => {
    useAuth.mockReturnValue({ register: vi.fn() })
    renderInscription()
    const user = setup()
    await user.type(screen.getByLabelText('Date de naissance'), '2015-01-01')
    expect(screen.getByText(/18-30 ans/)).toBeInTheDocument()
  })
})
