import { useEffect, useRef } from 'react'
import { useNavigate } from 'react-router-dom'
import { paths } from '../../routing/routes.js'
import './ChoixCompteModal.css'

const FOCUSABLE = 'button, a[href], input, select, textarea, [tabindex]:not([tabindex="-1"])'

/**
 * Modale de choix avant inscription (Lot B correctif) — le clic sur
 * « Candidater » dans l'en-tête de l'accueil ouvre ce choix explicite plutôt
 * que de foncer directement vers l'inscription, pour le candidat qui a peut-
 * être déjà un compte et clique par réflexe.
 *
 * Fermeture (X / clic extérieur / Échap) = reste sur place, AUCUNE
 * redirection implicite (décision direction) : les deux seules routes
 * possibles viennent d'un choix explicite (bouton primaire ou secondaire).
 */
export function ChoixCompteModal({ onClose }) {
  const navigate = useNavigate()
  const dialogRef = useRef(null)

  useEffect(() => {
    const previouslyFocused = document.activeElement
    dialogRef.current?.focus()

    const onKeyDown = (e) => {
      if (e.key === 'Escape') {
        onClose()
        return
      }
      if (e.key !== 'Tab' || !dialogRef.current) return
      const focusables = dialogRef.current.querySelectorAll(FOCUSABLE)
      if (focusables.length === 0) return
      const first = focusables[0]
      const last = focusables[focusables.length - 1]
      if (e.shiftKey && document.activeElement === first) {
        e.preventDefault()
        last.focus()
      } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault()
        first.focus()
      }
    }

    document.addEventListener('keydown', onKeyDown)
    return () => {
      document.removeEventListener('keydown', onKeyDown)
      if (previouslyFocused instanceof HTMLElement) previouslyFocused.focus()
    }
  }, [onClose])

  return (
    <div
      className="modal-overlay"
      onClick={(e) => {
        if (e.target === e.currentTarget) onClose()
      }}
    >
      <div
        ref={dialogRef}
        className="modal choix-compte-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="choix-compte-titre"
        aria-describedby="choix-compte-description"
        tabIndex={-1}
      >
        <div className="modal-header">
          <h3 id="choix-compte-titre">Avez-vous déjà un compte CASA ?</h3>
          <button type="button" className="btn btn-icon btn-ghost" aria-label="Fermer" onClick={onClose}>
            <i className="fa-solid fa-xmark" aria-hidden="true" />
          </button>
        </div>
        <div className="modal-body">
          <p id="choix-compte-description" className="body-sm text-muted">
            Si vous avez déjà créé votre espace candidat, connectez-vous pour continuer. Sinon, poursuivez vers
            l'inscription.
          </p>
        </div>
        <div className="modal-footer">
          <button type="button" className="btn btn-outline" onClick={() => navigate(paths.login)}>
            J'ai déjà un compte, me connecter
          </button>
          <button type="button" className="btn btn-primary" onClick={() => navigate(paths.inscription)}>
            Créer mon compte
          </button>
        </div>
      </div>
    </div>
  )
}
