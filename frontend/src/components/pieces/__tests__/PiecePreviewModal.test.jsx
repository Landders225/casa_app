import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { apiClient } from '../../../lib/apiClient.js'
import { ApiError } from '../../../lib/ApiError.js'
import { PiecePreviewModal } from '../PiecePreviewModal.jsx'

vi.mock('../../../lib/apiClient.js', () => ({ apiClient: { getBlob: vi.fn() } }))

vi.mock('pdfjs-dist/build/pdf.mjs', () => ({
  GlobalWorkerOptions: {},
  getDocument: vi.fn(),
}))
vi.mock('pdfjs-dist/build/pdf.worker.min.mjs?url', () => ({ default: 'blob:worker-mock' }))

function piece(over = {}) {
  return {
    id: 'p-1',
    nom_original: 'cni.pdf',
    taille_octets: 204800,
    url: '/api/pieces/p-1/download',
    url_apercu: '/api/pieces/p-1/apercu',
    ...over,
  }
}

describe('PiecePreviewModal', () => {
  beforeEach(() => {
    URL.createObjectURL = vi.fn(() => 'blob:mock-preview')
    URL.revokeObjectURL = vi.fn()
  })

  it('affiche un état de chargement puis une image une fois le blob récupéré', async () => {
    const blob = { type: 'image/png', arrayBuffer: vi.fn() }
    apiClient.getBlob.mockResolvedValue({ blob, filename: 'cni.png' })

    render(<PiecePreviewModal piece={piece({ nom_original: 'cni.png' })} label="CNI" onClose={() => {}} />)

    expect(screen.getByText(/chargement de l'aperçu/i)).toBeInTheDocument()

    await waitFor(() => expect(screen.getByRole('img', { name: 'CNI' })).toBeInTheDocument())
    expect(screen.getByRole('img', { name: 'CNI' })).toHaveAttribute('src', 'blob:mock-preview')
  })

  it('appelle getBlob sans le préfixe /api (correctif de revue — url_apercu est un chemin absolu, apiClient préfixe déjà /api)', async () => {
    const blob = { type: 'image/png', arrayBuffer: vi.fn() }
    apiClient.getBlob.mockResolvedValue({ blob })

    render(<PiecePreviewModal piece={piece({ url_apercu: '/api/pieces/p-1/apercu' })} label="CNI" onClose={() => {}} />)
    await waitFor(() => expect(screen.getByRole('img')).toBeInTheDocument())

    expect(apiClient.getBlob).toHaveBeenCalledWith('/pieces/p-1/apercu')
  })

  it('révoque l’URL blob à la fermeture (démontage)', async () => {
    const blob = { type: 'image/png', arrayBuffer: vi.fn() }
    apiClient.getBlob.mockResolvedValue({ blob })

    const { unmount } = render(<PiecePreviewModal piece={piece()} label="CNI" onClose={() => {}} />)
    await waitFor(() => expect(screen.getByRole('img')).toBeInTheDocument())

    unmount()
    expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:mock-preview')
  })

  it('404 -> message "non disponible", jamais une erreur technique', async () => {
    apiClient.getBlob.mockRejectedValue(new ApiError('http', { status: 404, message: 'Fichier introuvable.' }))

    render(<PiecePreviewModal piece={piece()} label="CNI" onClose={() => {}} />)

    await waitFor(() => expect(screen.getByText(/n'est pas disponible/i)).toBeInTheDocument())
  })

  it('erreur réseau -> message générique, jamais le détail technique', async () => {
    apiClient.getBlob.mockRejectedValue(new ApiError('network', { message: 'Impossible de contacter le serveur.' }))

    render(<PiecePreviewModal piece={piece()} label="CNI" onClose={() => {}} />)

    await waitFor(() => expect(screen.getByText(/aperçu impossible/i)).toBeInTheDocument())
    expect(screen.queryByText(/impossible de contacter le serveur/i)).not.toBeInTheDocument()
  })

  it('PDF illisible (pdf.js rejette) -> message clair, le téléchargement direct reste proposé', async () => {
    const { getDocument } = await import('pdfjs-dist/build/pdf.mjs')
    getDocument.mockReturnValue({ promise: Promise.reject(new Error('corrupted')) })
    const blob = { type: 'application/pdf', arrayBuffer: vi.fn().mockResolvedValue(new ArrayBuffer(0)) }
    apiClient.getBlob.mockResolvedValue({ blob })

    render(<PiecePreviewModal piece={piece()} label="CNI" onClose={() => {}} />)

    await waitFor(() => expect(screen.getByText(/aperçu impossible pour ce fichier/i)).toBeInTheDocument())
    expect(screen.getByRole('link', { name: /télécharger cni\.pdf/i })).toHaveAttribute('href', '/api/pieces/p-1/download')
  })

  it('PDF lisible -> les pages sont listées (numéro de page affiché)', async () => {
    const { getDocument } = await import('pdfjs-dist/build/pdf.mjs')
    const fakePage = {
      getViewport: () => ({ width: 100, height: 140 }),
      render: () => ({ promise: Promise.resolve(), cancel: vi.fn() }),
    }
    const fakePdf = { numPages: 2, getPage: vi.fn().mockResolvedValue(fakePage), destroy: vi.fn() }
    getDocument.mockReturnValue({ promise: Promise.resolve(fakePdf) })
    const blob = { type: 'application/pdf', arrayBuffer: vi.fn().mockResolvedValue(new ArrayBuffer(0)) }
    apiClient.getBlob.mockResolvedValue({ blob })

    render(<PiecePreviewModal piece={piece()} label="CNI" onClose={() => {}} />)

    await waitFor(() => expect(screen.getByText('Page 1')).toBeInTheDocument())
    expect(screen.getByText('Page 2')).toBeInTheDocument()
  })

  it('une image qui ne décode pas (onError) -> message "aperçu impossible"', async () => {
    const blob = { type: 'image/png', arrayBuffer: vi.fn() }
    apiClient.getBlob.mockResolvedValue({ blob })

    render(<PiecePreviewModal piece={piece()} label="CNI" onClose={() => {}} />)
    await waitFor(() => expect(screen.getByRole('img')).toBeInTheDocument())

    fireEvent.error(screen.getByRole('img'))

    await waitFor(() => expect(screen.getByText(/aperçu impossible pour ce fichier/i)).toBeInTheDocument())
  })

  it('la touche Échap ferme la modale', async () => {
    const blob = { type: 'image/png', arrayBuffer: vi.fn() }
    apiClient.getBlob.mockResolvedValue({ blob })
    const onClose = vi.fn()

    render(<PiecePreviewModal piece={piece()} label="CNI" onClose={onClose} />)
    await waitFor(() => expect(screen.getByRole('img')).toBeInTheDocument())

    await userEvent.keyboard('{Escape}')
    expect(onClose).toHaveBeenCalledTimes(1)
  })

  it('page PDF dont le rendu échoue -> "Page illisible.", sans casser les autres pages', async () => {
    const { getDocument } = await import('pdfjs-dist/build/pdf.mjs')
    const fakePage = {
      getViewport: () => ({ width: 100, height: 140 }),
      render: () => ({ promise: Promise.reject(new Error('render failed')), cancel: vi.fn() }),
    }
    const fakePdf = { numPages: 1, getPage: vi.fn().mockResolvedValue(fakePage), destroy: vi.fn() }
    getDocument.mockReturnValue({ promise: Promise.resolve(fakePdf) })
    const blob = { type: 'application/pdf', arrayBuffer: vi.fn().mockResolvedValue(new ArrayBuffer(0)) }
    apiClient.getBlob.mockResolvedValue({ blob })

    render(<PiecePreviewModal piece={piece()} label="CNI" onClose={() => {}} />)

    await waitFor(() => expect(screen.getByText('Page illisible.')).toBeInTheDocument())
    expect(screen.getByText('Page 1')).toBeInTheDocument()
  })

  it('démontage pendant un rendu PDF en cours -> annule la tâche de rendu (tache.cancel)', async () => {
    const { getDocument } = await import('pdfjs-dist/build/pdf.mjs')
    const annuler = vi.fn()
    const fakePage = {
      getViewport: () => ({ width: 100, height: 140 }),
      render: () => ({ promise: new Promise(() => {}), cancel: annuler }),
    }
    const fakePdf = { numPages: 1, getPage: vi.fn().mockResolvedValue(fakePage), destroy: vi.fn() }
    getDocument.mockReturnValue({ promise: Promise.resolve(fakePdf) })
    const blob = { type: 'application/pdf', arrayBuffer: vi.fn().mockResolvedValue(new ArrayBuffer(0)) }
    apiClient.getBlob.mockResolvedValue({ blob })

    const { unmount } = render(<PiecePreviewModal piece={piece()} label="CNI" onClose={() => {}} />)
    await waitFor(() => expect(screen.getByText('Page 1')).toBeInTheDocument())
    // Laisse le temps à l'effet de rendu de poser `tache` avant de démonter.
    await new Promise((r) => setTimeout(r, 0))

    unmount()

    expect(annuler).toHaveBeenCalledTimes(1)
  })

  it('le bouton Fermer appelle onClose', async () => {
    const blob = { type: 'image/png', arrayBuffer: vi.fn() }
    apiClient.getBlob.mockResolvedValue({ blob })
    const onClose = vi.fn()

    render(<PiecePreviewModal piece={piece()} label="CNI" onClose={onClose} />)
    await waitFor(() => expect(screen.getByRole('img')).toBeInTheDocument())

    await userEvent.click(screen.getByRole('button', { name: /fermer/i }))
    expect(onClose).toHaveBeenCalledTimes(1)
  })

  it('le téléchargement direct reste toujours proposé, y compris pendant le chargement', () => {
    apiClient.getBlob.mockReturnValue(new Promise(() => {}))

    render(<PiecePreviewModal piece={piece()} label="CNI" onClose={() => {}} />)

    expect(screen.getByRole('link', { name: /télécharger cni\.pdf/i })).toHaveAttribute('href', '/api/pieces/p-1/download')
  })
})
