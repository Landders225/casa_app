import { useState } from 'react'

/**
 * Affichage UNIQUE d'un mot de passe temporaire (Lot 11b) — après création d'un
 * compte ou réinitialisation. Le serveur ne le renverra plus jamais : le
 * message insiste pour qu'il soit noté et transmis maintenant, hors bande.
 *
 * Pas de stockage, pas de log : la valeur ne vit que dans le state de ce
 * composant, le temps de la fenêtre.
 */
export function MotDePasseDialog({ titre, email, motDePasse, onClose }) {
  const [copie, setCopie] = useState(false)

  const copier = async () => {
    try {
      await navigator.clipboard.writeText(motDePasse)
      setCopie(true)
    } catch {
      setCopie(false)
    }
  }

  return (
    <div className="modal-overlay" role="dialog" aria-modal="true" aria-label={titre}>
      <div className="modal">
        <div className="modal-body">
          <h3>{titre}</h3>
          <p className="body-sm" style={{ marginTop: 'var(--space-3)' }}>
            Communiquez ce mot de passe provisoire à <strong>{email}</strong> par un canal sûr.
            Il ne sera <strong>plus jamais affiché</strong> — notez-le maintenant.
          </p>
          <div
            className="card"
            style={{
              marginTop: 'var(--space-4)',
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'space-between',
              gap: 'var(--space-3)',
              fontFamily: 'var(--font-mono, monospace)',
            }}
          >
            <code aria-label="Mot de passe provisoire" style={{ fontSize: '1.05rem', letterSpacing: '0.04em' }}>
              {motDePasse}
            </code>
            <button type="button" className="btn btn-outline btn-sm" onClick={copier}>
              {copie ? 'Copié' : 'Copier'}
            </button>
          </div>
        </div>
        <div className="modal-footer">
          <button type="button" className="btn btn-primary" onClick={onClose}>
            J'ai noté le mot de passe
          </button>
        </div>
      </div>
    </div>
  )
}
