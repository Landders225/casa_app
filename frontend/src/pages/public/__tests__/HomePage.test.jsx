import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { apiClient } from '../../../lib/apiClient.js'
import { HomePage } from '../HomePage.jsx'

vi.mock('../../../lib/apiClient.js', () => ({ apiClient: { get: vi.fn() } }))

const FILIERES = [
  { code: 'cuisine', nom: 'Agent de cuisine', description: 'Préparation culinaire.', actif: true },
  { code: 'buanderie', nom: 'Agent de buanderie', description: 'Traitement du linge.', actif: true },
  { code: 'restaurant-bar', nom: 'Service restaurant-bar', description: 'Service en salle.', actif: false },
]

function renderHome() {
  return render(
    <MemoryRouter initialEntries={['/']}>
      <Routes>
        <Route path="/" element={<HomePage />} />
        <Route path="/inscription" element={<h1>Créer votre compte candidat</h1>} />
        <Route path="/connexion" element={<h1>Accéder à mon espace</h1>} />
      </Routes>
    </MemoryRouter>,
  )
}

describe('HomePage (accueil public)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    apiClient.get.mockResolvedValue({ data: FILIERES })
  })

  it('affiche les filières de GET /api/filieres', async () => {
    renderHome()
    expect(apiClient.get).toHaveBeenCalledWith('/filieres')
    expect(await screen.findByText('Agent de cuisine')).toBeInTheDocument()
    expect(screen.getByText('Agent de buanderie')).toBeInTheDocument()
    expect(screen.getByText('Service restaurant-bar')).toBeInTheDocument()
  })

  it('une filière inactive affiche « Actuellement fermé » et n\'est pas candidatable', async () => {
    renderHome()
    const fermee = (await screen.findByText('Service restaurant-bar')).closest('.cqp-card')
    expect(within(fermee).getByText('Actuellement fermé', { selector: '.badge' })).toBeInTheDocument()
    expect(within(fermee).getByRole('button', { name: 'Actuellement fermé' })).toBeDisabled()

    // Une filière active reste candidatable (lien vers /inscription).
    const active = (await screen.findByText('Agent de cuisine')).closest('.cqp-card')
    expect(within(active).getByRole('link', { name: /candidater/i })).toHaveAttribute('href', '/inscription')
  })

  it('affiche les critères pouvant entraîner une élimination', async () => {
    renderHome()
    await screen.findByText('Agent de cuisine')
    expect(screen.getByText('Avoir entre 18 et 30 ans')).toBeInTheDocument()
    expect(screen.getByText(/Être disponible du lundi au vendredi/)).toBeInTheDocument()
    expect(screen.getByText(/Nationalité ivoirienne confirmée par la pièce/)).toBeInTheDocument()
  })

  it('NE RÉVÈLE JAMAIS la grille de notation (§12-13)', async () => {
    const { container } = renderHome()
    await screen.findByText('Agent de cuisine')
    const html = container.innerHTML

    for (const interdit of ['/100', 'sur 100', '/65', '/35', '/ 100', 'pondération', 'pondérée', 'pondéré', 'sous-critère', 'sous-crit', 'barème']) {
      expect(html.toLowerCase()).not.toContain(interdit.toLowerCase())
    }
    // Le texte de confidentialité de la FAQ, lui, DOIT être présent.
    expect(screen.getByText(/document interne, réservé à l'équipe d'évaluation/)).toBeInTheDocument()
  })

  it('gère l\'échec de chargement des filières sans planter', async () => {
    apiClient.get.mockRejectedValueOnce(new Error('boom'))
    renderHome()
    expect(await screen.findByRole('alert')).toHaveTextContent(/filières n'a pas pu être chargée/i)
  })

  describe('Lot A — renommage « Projet CASA » → « Initiative CASA » + AICS + logos partenaires', () => {
    it('le titre principal (eyebrow hero) porte la forme complète', async () => {
      renderHome()
      await screen.findByText('Agent de cuisine')
      expect(screen.getByText(/Initiative CASA \/ Projet de formation et\s+Insertion – Hôtellerie et Tourisme/)).toBeInTheDocument()
    })

    it('nav et bouton renommés : « Le projet » / « Découvrir le projet »', async () => {
      renderHome()
      await screen.findByText('Agent de cuisine')
      expect(screen.getAllByRole('link', { name: 'Le projet' }).length).toBeGreaterThanOrEqual(1)
      expect(screen.getByRole('link', { name: 'Découvrir le projet' })).toBeInTheDocument()
    })

    it('section « Projet Initiative CASA » affiche la mention « Financé par l’AICS »', async () => {
      renderHome()
      await screen.findByText('Agent de cuisine')
      expect(screen.getByText('Projet Initiative CASA')).toBeInTheDocument()
      expect(screen.getByText('Financé par l\'AICS')).toBeInTheDocument()
    })

    it('footer renommé (Initiative CASA) et logos partenaires intégrés (image réelle, pas de placeholder cassé)', async () => {
      renderHome()
      await screen.findByText('Agent de cuisine')
      expect(screen.getByText(/Initiative CASA — Arbre de Vie 2026/)).toBeInTheDocument()
      expect(screen.getByText('© 2026 Initiative CASA — Arbre de Vie.')).toBeInTheDocument()

      const logo = screen.getByRole('img', { name: /Logos des partenaires/ })
      expect(logo).toHaveAttribute('src', '/partner_logo.png')
    })

    it('« Projet CASA » n\'apparaît plus nulle part sur la page d\'accueil', async () => {
      const { container } = renderHome()
      await screen.findByText('Agent de cuisine')
      expect(container.innerHTML).not.toContain('Projet CASA')
    })
  })

  describe('Lot B (correctif) — « Se connecter » restauré + modale de choix au clic sur « Candidater »', () => {
    // Le libellé « Candidater » n'est pas unique sur la page (aussi dans le
    // hero « Candidater maintenant »), d'où ce scope explicite à l'en-tête.
    const clickHeaderCandidater = async (container, user) => {
      const header = within(container.querySelector('.site-header'))
      await user.click(header.getByRole('link', { name: /^candidater/i }))
    }

    it('l’en-tête garde « Se connecter » ET « Candidater »', async () => {
      const { container } = renderHome()
      await screen.findByText('Agent de cuisine')
      const header = within(container.querySelector('.site-header'))
      expect(header.getByRole('link', { name: 'Se connecter' })).toHaveAttribute('href', '/connexion')
      expect(header.getByRole('link', { name: /^candidater/i })).toBeInTheDocument()
    })

    it('cliquer sur « Candidater » (en-tête) ouvre la modale de choix au lieu de naviguer directement', async () => {
      const { container } = renderHome()
      await screen.findByText('Agent de cuisine')
      const user = userEvent.setup()
      await clickHeaderCandidater(container, user)

      const dialog = screen.getByRole('dialog', { name: /avez-vous déjà un compte casa/i })
      expect(dialog).toBeInTheDocument()
      expect(within(dialog).getByText(/connectez-vous pour continuer.*poursuivez vers/i)).toBeInTheDocument()
      // Toujours sur l'accueil : aucune navigation n'a eu lieu à l'ouverture.
      expect(screen.getByText('Agent de cuisine')).toBeInTheDocument()
    })

    it('« Créer mon compte » dans la modale mène à l’inscription', async () => {
      const { container } = renderHome()
      await screen.findByText('Agent de cuisine')
      const user = userEvent.setup()
      await clickHeaderCandidater(container, user)

      await user.click(screen.getByRole('button', { name: 'Créer mon compte' }))
      expect(await screen.findByRole('heading', { name: /créer votre compte candidat/i })).toBeInTheDocument()
    })

    it('« J’ai déjà un compte, me connecter » dans la modale mène à la connexion', async () => {
      const { container } = renderHome()
      await screen.findByText('Agent de cuisine')
      const user = userEvent.setup()
      await clickHeaderCandidater(container, user)

      await user.click(screen.getByRole('button', { name: "J'ai déjà un compte, me connecter" }))
      expect(await screen.findByRole('heading', { name: /accéder à mon espace/i })).toBeInTheDocument()
    })

    it('fermer la modale (X) ne redirige nulle part — reste sur l’accueil', async () => {
      const { container } = renderHome()
      await screen.findByText('Agent de cuisine')
      const user = userEvent.setup()
      await clickHeaderCandidater(container, user)
      expect(screen.getByRole('dialog')).toBeInTheDocument()

      await user.click(screen.getByRole('button', { name: 'Fermer' }))

      expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
      expect(screen.getByText('Agent de cuisine')).toBeInTheDocument()
      expect(screen.queryByRole('heading', { name: /créer votre compte candidat/i })).not.toBeInTheDocument()
    })

    it('fermer la modale (Échap) ne redirige nulle part', async () => {
      const { container } = renderHome()
      await screen.findByText('Agent de cuisine')
      const user = userEvent.setup()
      await clickHeaderCandidater(container, user)
      expect(screen.getByRole('dialog')).toBeInTheDocument()

      await user.keyboard('{Escape}')

      expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
      expect(screen.getByText('Agent de cuisine')).toBeInTheDocument()
    })

    it('fermer la modale (clic extérieur) ne redirige nulle part', async () => {
      const { container } = renderHome()
      await screen.findByText('Agent de cuisine')
      const user = userEvent.setup()
      await clickHeaderCandidater(container, user)
      const dialog = screen.getByRole('dialog')

      await user.click(dialog.parentElement) // l'overlay, hors du contenu de la modale

      expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
      expect(screen.getByText('Agent de cuisine')).toBeInTheDocument()
    })

    it('le focus est piégé dans la modale (Tab depuis le dernier bouton revient au premier élément focusable)', async () => {
      const { container } = renderHome()
      await screen.findByText('Agent de cuisine')
      const user = userEvent.setup()
      await clickHeaderCandidater(container, user)

      const dialog = screen.getByRole('dialog')
      const fermer = within(dialog).getByRole('button', { name: 'Fermer' })
      const creerCompte = within(dialog).getByRole('button', { name: 'Créer mon compte' })

      creerCompte.focus()
      expect(document.activeElement).toBe(creerCompte)
      await user.tab()
      expect(document.activeElement).toBe(fermer) // reboucle sur le premier élément focusable
    })

    it('le bouton « J’ai déjà un compte » de la bande CTA finale reste intact et navigue directement, sans modale (chemin de reconnexion volontaire)', async () => {
      renderHome()
      await screen.findByText('Agent de cuisine')
      const user = userEvent.setup()

      await user.click(screen.getByRole('link', { name: /j.ai déjà un compte/i }))
      expect(await screen.findByRole('heading', { name: /accéder à mon espace/i })).toBeInTheDocument()
    })
  })

  describe('Lot B (correctif 2) — TOUS les boutons « Candidater »/« Déposer ma candidature » de l’accueil ouvrent la modale', () => {
    it('le hero « Candidater maintenant » ouvre la modale au lieu de naviguer directement', async () => {
      renderHome()
      await screen.findByText('Agent de cuisine')
      const user = userEvent.setup()

      await user.click(screen.getByRole('link', { name: /^candidater maintenant/i }))

      expect(screen.getByRole('dialog', { name: /avez-vous déjà un compte casa/i })).toBeInTheDocument()
      expect(screen.getByText('Agent de cuisine')).toBeInTheDocument() // pas de navigation
    })

    it('la bande CTA finale « Déposer ma candidature » ouvre la modale au lieu de naviguer directement', async () => {
      renderHome()
      await screen.findByText('Agent de cuisine')
      const user = userEvent.setup()

      await user.click(screen.getByRole('link', { name: 'Déposer ma candidature' }))

      expect(screen.getByRole('dialog', { name: /avez-vous déjà un compte casa/i })).toBeInTheDocument()
      expect(screen.getByText('Agent de cuisine')).toBeInTheDocument()
    })

    it('le « Candidater » de la section éligibilité (actif une fois la case cochée) ouvre la modale', async () => {
      renderHome()
      await screen.findByText('Agent de cuisine')
      const user = userEvent.setup()

      const cocher = screen.getByLabelText(/J'ai pris connaissance des conditions/i)
      await user.click(cocher)
      const carte = within(cocher.closest('.card'))
      // Devenu un vrai lien (plus le <button disabled>) une fois la case cochée.
      await user.click(carte.getByRole('link', { name: /^candidater/i }))

      expect(screen.getByRole('dialog', { name: /avez-vous déjà un compte casa/i })).toBeInTheDocument()
      expect(screen.getByText('Agent de cuisine')).toBeInTheDocument()
    })

    it('depuis n’importe lequel de ces boutons, « Créer mon compte » mène bien à l’inscription', async () => {
      renderHome()
      await screen.findByText('Agent de cuisine')
      const user = userEvent.setup()

      await user.click(screen.getByRole('link', { name: 'Déposer ma candidature' }))
      await user.click(screen.getByRole('button', { name: 'Créer mon compte' }))

      expect(await screen.findByRole('heading', { name: /créer votre compte candidat/i })).toBeInTheDocument()
    })
  })
})
