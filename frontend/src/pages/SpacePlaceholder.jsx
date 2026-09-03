import { AppShell } from '../components/layout/AppShell.jsx'
import { useAuth } from '../auth/useAuth.js'

/**
 * Contenu-placeholder d'un espace authentifié (Lot 8a). Prouve que le routing
 * par rôle mène au bon endroit ; les écrans métier arrivent aux Lots 8b/c/d.
 */
export function SpacePlaceholder({ title, lot, children }) {
  const { user } = useAuth()

  return (
    <AppShell title={title}>
      <div className="card" style={{ maxWidth: 640 }}>
        <span className="eyebrow">
          <i className="fa-solid fa-screwdriver-wrench" aria-hidden="true" /> Fondation — Lot 8a
        </span>
        <h2 className="h2" style={{ marginTop: 'var(--space-3)' }}>{title}</h2>
        <p className="text-muted" style={{ marginTop: 'var(--space-2)' }}>
          Vous êtes connecté en tant que <strong>{user?.email}</strong>. Cet espace est en place — ses écrans ({lot})
          seront livrés dans un prochain sous-lot.
        </p>
        {children}
      </div>
    </AppShell>
  )
}

export function EvaluateurPlaceholder() {
  return (
    <SpacePlaceholder title="Espace évaluateur" lot="dossiers, notation, entretiens">
      <p className="caption" style={{ marginTop: 'var(--space-4)' }}>
        À venir : consultation des dossiers affectés, vérification des pièces, notation du dossier puis de
        l'entretien, classement.
      </p>
    </SpacePlaceholder>
  )
}

export function AdminPlaceholder() {
  return (
    <SpacePlaceholder title="Espace administrateur" lot="campagnes, classement, publication, audit">
      <p className="caption" style={{ marginTop: 'var(--space-4)' }}>
        À venir : affectations, filières et campagnes, classement, publication des résultats, journal d'audit.
      </p>
    </SpacePlaceholder>
  )
}
