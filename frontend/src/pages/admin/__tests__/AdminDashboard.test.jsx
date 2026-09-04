import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useAuth } from '../../../auth/useAuth.js'
import { apiClient } from '../../../lib/apiClient.js'
import { AdminDashboard } from '../AdminDashboard.jsx'

vi.mock('../../../lib/apiClient.js', () => ({ apiClient: { get: vi.fn() } }))
vi.mock('../../../auth/useAuth.js', () => ({ useAuth: vi.fn() }))

function renderScreen() {
  return render(
    <MemoryRouter>
      <AdminDashboard />
    </MemoryRouter>,
  )
}

beforeEach(() => {
  vi.clearAllMocks()
  useAuth.mockReturnValue({ user: { profil: { prenom: 'Admin', nom: 'Test' } }, role: 'administrateur', logout: vi.fn() })
})
afterEach(() => vi.restoreAllMocks())

describe('AdminDashboard — 4 KPI = meta.total de 4 appels filtrés, aucun calcul reconstitué', () => {
  it('affiche les 4 KPI depuis meta.total, et la liste des campagnes depuis le nouvel endpoint', async () => {
    apiClient.get.mockImplementation((path) => {
      if (path === '/admin/candidatures') return Promise.resolve({ data: [], meta: { total: 42 } })
      if (path === '/admin/candidatures?evaluateur=non_affecte') return Promise.resolve({ data: [], meta: { total: 7 } })
      if (path === '/admin/candidatures?statut_interne=en_instruction') return Promise.resolve({ data: [], meta: { total: 12 } })
      if (path === '/admin/candidatures?statut_interne=evalue') return Promise.resolve({ data: [], meta: { total: 23 } })
      if (path === '/admin/campagnes') return Promise.resolve({ data: [{ id: 'c1', nom: 'Cohorte 1 — 2026', statut: 'ouverte', date_ouverture: '2026-05-01', date_cloture: '2026-06-30', places_totales: 120 }] })
      return Promise.resolve({ data: [] })
    })
    renderScreen()

    expect(await screen.findByText('42')).toBeInTheDocument()
    expect(screen.getByText('7')).toBeInTheDocument()
    expect(screen.getByText('12')).toBeInTheDocument()
    expect(screen.getByText('23')).toBeInTheDocument()
    expect(screen.getByText('Cohorte 1 — 2026')).toBeInTheDocument()

    // Aucun graphique (Chart.js), aucun taux calculé (D-8c1-1/2, même choix qu'au 8c-1).
    expect(document.querySelector('canvas')).not.toBeInTheDocument()
    expect(screen.queryByText(/taux/i)).not.toBeInTheDocument()
  })

  it('erreur réseau -> alerte, pas de plantage', async () => {
    apiClient.get.mockRejectedValue(new Error('network'))
    renderScreen()
    expect(await screen.findByText(/n'a pas pu être chargé/i)).toBeInTheDocument()
  })
})
