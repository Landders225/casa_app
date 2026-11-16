import { useEffect, useRef, useState } from 'react'
import { apiClient } from '../../lib/apiClient.js'
import './PiecePreviewModal.css'

/**
 * Aperçu en ligne d'une pièce justificative (Lot 19) — PDF ou image, jamais
 * enregistré sur le disque de l'utilisateur. Utilisé par `candidat/Documents.jsx`
 * ET `evaluateur/FicheCandidat.jsx` : un seul composant, même comportement, pas
 * de duplication (cf. patron déjà suivi pour `PieceLink`/`LigneDeposee` avant ce
 * lot, où les deux pages avaient chacune leur copie).
 *
 * Récupère les octets via `apiClient.getBlob` (fetch authentifié par cookie,
 * déjà utilisé pour l'export CSV) sur `piece.url_apercu` — JAMAIS une
 * navigation directe : le serveur applique l'autorisation EXACTE du
 * téléchargement (même policy), et journalise la consultation côté
 * évaluateur/admin (jamais côté candidat, qui ne consulte que sa propre pièce).
 *
 * `piece.url_apercu` est un chemin ABSOLU côté API (`/api/pieces/{id}/apercu`,
 * même convention que `piece.url` utilisé en `href` direct) — `apiClient`
 * préfixe lui-même `/api` (`BASE`), donc on retire ce préfixe avant de l'y
 * passer (correctif de revue : sans ça, `/api/api/pieces/...` -> 404).
 *
 * Ce que l'aperçu NE protège PAS (affiché explicitement en bas de la modale) :
 * une capture d'écran, un enregistrement d'écran ou une impression du contenu
 * affiché restent toujours possibles. Ce n'est pas un DRM — seulement un moyen
 * d'éviter l'enregistrement direct du fichier et sa mise en cache.
 */
export function PiecePreviewModal({ piece, label, onClose }) {
  const [etat, setEtat] = useState({ statut: 'chargement' }) // chargement | pdf | image | indisponible | illisible
  const blobUrlRef = useRef(null)
  const pdfDocRef = useRef(null)

  useEffect(() => {
    let vivant = true

    apiClient
      .getBlob(piece.url_apercu.replace(/^\/api/, ''))
      .then(async ({ blob }) => {
        if (!vivant) return

        if (blob.type === 'application/pdf') {
          try {
            const pdfjsLib = await import('pdfjs-dist/build/pdf.mjs')
            const workerUrl = (await import('pdfjs-dist/build/pdf.worker.min.mjs?url')).default
            pdfjsLib.GlobalWorkerOptions.workerSrc = workerUrl

            const donnees = await blob.arrayBuffer()
            const pdf = await pdfjsLib.getDocument({ data: donnees, isEvalSupported: false }).promise
            if (!vivant) {
              pdf.destroy()
              return
            }
            pdfDocRef.current = pdf
            setEtat({ statut: 'pdf', pdf, nombrePages: pdf.numPages })
          } catch {
            if (vivant) setEtat({ statut: 'illisible' })
          }
          return
        }

        const url = URL.createObjectURL(blob)
        blobUrlRef.current = url
        setEtat({ statut: 'image', url })
      })
      .catch((err) => {
        if (!vivant) return
        setEtat({ statut: err?.status === 404 ? 'indisponible' : 'erreur' })
      })

    return () => {
      vivant = false
      if (blobUrlRef.current) {
        URL.revokeObjectURL(blobUrlRef.current)
        blobUrlRef.current = null
      }
      if (pdfDocRef.current) {
        pdfDocRef.current.destroy()
        pdfDocRef.current = null
      }
    }
    // `piece.url_apercu` est stable pour l'ouverture d'une modale donnée.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [piece.url_apercu])

  // Échap ferme la modale (correctif de revue) — même geste que le bouton Fermer.
  useEffect(() => {
    function surTouche(e) {
      if (e.key === 'Escape') onClose()
    }
    document.addEventListener('keydown', surTouche)
    return () => document.removeEventListener('keydown', surTouche)
  }, [onClose])

  return (
    <div className="modal-overlay piece-preview-overlay" role="dialog" aria-modal="true" aria-label={`Aperçu — ${label}`}>
      <div className="piece-preview-modal">
        <div className="piece-preview-header">
          <div>
            <h3>{label}</h3>
            <p className="caption text-muted">{piece.nom_original}</p>
          </div>
          <div className="flex gap-2">
            <a
              href={piece.url}
              className="btn btn-outline btn-sm"
              target="_blank"
              rel="noopener"
              aria-label={`Télécharger ${piece.nom_original}`}
            >
              <i className="fa-solid fa-download" aria-hidden="true" /> Télécharger
            </a>
            <button type="button" className="btn btn-outline btn-sm" onClick={onClose}>
              <i className="fa-solid fa-xmark" aria-hidden="true" /> Fermer
            </button>
          </div>
        </div>

        <div className="piece-preview-body">
          {etat.statut === 'chargement' ? (
            <div className="piece-preview-etat">Chargement de l'aperçu…</div>
          ) : etat.statut === 'indisponible' ? (
            <div className="piece-preview-etat">Ce document n'est pas disponible.</div>
          ) : etat.statut === 'illisible' || etat.statut === 'erreur' ? (
            <div className="piece-preview-etat">
              Aperçu impossible pour ce fichier. Vous pouvez le télécharger avec le bouton ci-dessus.
            </div>
          ) : etat.statut === 'image' ? (
            <ImageApercu url={etat.url} alt={label} onError={() => setEtat({ statut: 'illisible' })} />
          ) : etat.statut === 'pdf' ? (
            <PdfApercu pdf={etat.pdf} nombrePages={etat.nombrePages} />
          ) : null}
        </div>

        <p className="caption text-muted piece-preview-footer">
          Cet aperçu évite d'enregistrer le fichier sur votre appareil, mais ne protège pas contre une capture
          d'écran ou une impression de ce qui est affiché.
        </p>
      </div>
    </div>
  )
}

function ImageApercu({ url, alt, onError }) {
  const [zoom, setZoom] = useState(1)

  return (
    <div className="piece-preview-image-wrap">
      <div className="piece-preview-zoom-controls">
        <button type="button" className="btn btn-outline btn-sm" onClick={() => setZoom((z) => Math.max(0.5, z - 0.25))}>
          <i className="fa-solid fa-magnifying-glass-minus" aria-hidden="true" />
        </button>
        <button type="button" className="btn btn-outline btn-sm" onClick={() => setZoom((z) => Math.min(3, z + 0.25))}>
          <i className="fa-solid fa-magnifying-glass-plus" aria-hidden="true" />
        </button>
      </div>
      <div className="piece-preview-image-scroll">
        {/* eslint-disable-next-line jsx-a11y/alt-text -- couvert par `alt` */}
        <img src={url} alt={alt} style={{ transform: `scale(${zoom})` }} onError={onError} />
      </div>
    </div>
  )
}

/**
 * Rendu PDF page par page, EN CANVAS (jamais un `<iframe>`/`<embed>` : la CSP
 * `frame-src`/`object-src` reste `'none'`, aucun affaiblissement). Chaque page
 * n'est rendue que lorsqu'elle approche du viewport (`IntersectionObserver`) —
 * un gros PDF ou un mobile ne rendent jamais plus que les pages visibles +
 * marge, au lieu de tout rendre d'un coup à l'ouverture.
 */
function PdfApercu({ pdf, nombrePages }) {
  return (
    <div className="piece-preview-pdf-scroll">
      {Array.from({ length: nombrePages }, (_, i) => i + 1).map((numero) => (
        <PdfPage key={numero} pdf={pdf} numero={numero} />
      ))}
    </div>
  )
}

function PdfPage({ pdf, numero }) {
  const canvasRef = useRef(null)
  const wrapRef = useRef(null)
  const [rendue, setRendue] = useState(false)
  const [hauteur, setHauteur] = useState(null)
  const [erreur, setErreur] = useState(false)

  useEffect(() => {
    const noeud = wrapRef.current
    if (!noeud) return

    // Navigateur sans IntersectionObserver (ou jsdom en test) : on rend
    // directement, pas de virtualisation possible — repli sûr, même patron
    // que `useScrollReveal.js`.
    if (typeof IntersectionObserver === 'undefined') {
      setRendue(true)
      return
    }

    const observateur = new IntersectionObserver(
      (entrees) => {
        if (entrees.some((e) => e.isIntersecting)) {
          setRendue(true)
          observateur.disconnect()
        }
      },
      { rootMargin: '600px 0px' },
    )
    observateur.observe(noeud)

    return () => observateur.disconnect()
  }, [])

  // Ne demande la page à pdf.js QUE lorsqu'elle approche du viewport (ou
  // immédiatement si la virtualisation n'est pas possible, cf. ci-dessus) —
  // jamais avant, même pour calculer sa hauteur.
  useEffect(() => {
    if (!rendue) return
    let vivant = true
    let tache = null

    pdf.getPage(numero)
      .then((page) => {
        if (!vivant || !canvasRef.current) return

        const largeurCible = Math.min(760, window.innerWidth - 32)
        const viewportBase = page.getViewport({ scale: 1 })
        const echelle = largeurCible / viewportBase.width
        const viewport = page.getViewport({ scale: echelle })
        setHauteur(viewport.height)

        const canvas = canvasRef.current
        canvas.width = viewport.width
        canvas.height = viewport.height
        tache = page.render({ canvasContext: canvas.getContext('2d'), viewport })
        return tache.promise
      })
      .catch((err) => {
        // Rendu annulé volontairement au démontage (tache.cancel() ci-dessous) :
        // ce n'est pas une erreur à afficher.
        if (!vivant || err?.name === 'RenderingCancelledException') return
        setErreur(true)
      })

    return () => {
      vivant = false
      if (tache) tache.cancel()
    }
  }, [pdf, numero, rendue])

  return (
    <div ref={wrapRef} className="piece-preview-pdf-page" style={hauteur ? { minHeight: hauteur } : undefined}>
      {erreur ? (
        <div className="piece-preview-pdf-page-erreur caption">Page illisible.</div>
      ) : rendue ? (
        <canvas ref={canvasRef} />
      ) : null}
      <span className="caption piece-preview-pdf-page-numero">Page {numero}</span>
    </div>
  )
}
