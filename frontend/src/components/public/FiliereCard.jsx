import { Link } from 'react-router-dom'
import { filiereIcon } from '../../lib/filiereIcons.js'
import { paths } from '../../routing/routes.js'

/**
 * Carte d'une filière sur l'accueil. Données : `GET /api/filieres`
 * ({ code, nom, description, actif }). Icône : table client (D-8b1-3).
 * `actif: false` -> badge « Actuellement fermé » + candidature indisponible (§16).
 */
export function FiliereCard({ filiere }) {
  const { code, nom, description, actif } = filiere

  return (
    <div className="card card-hover cqp-card" data-reveal="scale">
      <div className="flex justify-between items-center" style={{ marginBottom: 'var(--space-2)' }}>
        <div className="cqp-icon" style={{ marginBottom: 0 }}>
          <i className={`fa-solid ${filiereIcon(code)}`} aria-hidden="true" />
        </div>
        {actif ? null : <span className="badge badge-neutral">Actuellement fermé</span>}
      </div>
      <h4>{nom}</h4>
      <p className="body-sm" style={{ margin: 'var(--space-2) 0 var(--space-4)' }}>
        {description}
      </p>
      {actif ? (
        <Link to={paths.inscription} className="btn btn-outline btn-sm btn-block">
          Candidater <i className="fa-solid fa-chevron-right" aria-hidden="true" />
        </Link>
      ) : (
        <button type="button" className="btn btn-outline btn-sm btn-block" disabled>
          Actuellement fermé
        </button>
      )}
    </div>
  )
}
