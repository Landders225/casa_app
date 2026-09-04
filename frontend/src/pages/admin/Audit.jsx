import { useState } from 'react'
import { AppShell } from '../../components/layout/AppShell.jsx'
import { Alert } from '../../components/ui/Alert.jsx'
import { Spinner } from '../../components/ui/Spinner.jsx'
import { formatDateFr } from '../../lib/formatDate.js'
import { AUDIT_MODULES } from './optionLabels.js'
import { useAudit } from './useAudit.js'

/**
 * Journal d'audit — consultation (Lot 6a/8d-1), administrateur strict ABSOLU.
 *
 * La zone 🔴 (`ancienne_valeur`/`nouvelle_valeur`/`motif`) est LÉGITIME ici —
 * c'est l'outil de supervision — et affichée TELLE QUELLE, jamais reformulée.
 *
 * `recherche` est envoyé tel quel à `?recherche=` : le backend le borne à
 * `action`+`objet` (docstring `AuditController`, Lot 6a) — CE COMPOSANT
 * n'applique JAMAIS de filtre client sur `ancienne_valeur`/`nouvelle_valeur`/
 * `motif`. Le placeholder le dit honnêtement (contrairement à la maquette,
 * qui laisse croire que la recherche couvre l'auteur — faux côté backend) :
 * `auteur` est un champ SÉPARÉ, envoyé à `?auteur=` (égalité exacte e-mail).
 */
export function Audit() {
  const [module, setModule] = useState('')
  const [auteur, setAuteur] = useState('')
  const [dateDebut, setDateDebut] = useState('')
  const [dateFin, setDateFin] = useState('')
  const [recherche, setRecherche] = useState('')
  const [page, setPage] = useState(1)

  const { status, items, meta } = useAudit({ module, auteur, dateDebut, dateFin, recherche, page })

  const resetPage = () => setPage(1)

  return (
    <AppShell title="Journal d'audit">
      <div className="page-head">
        <div>
          <h2>Journal d'audit</h2>
          <p className="text-muted">Traçabilité de toutes les actions sensibles de la plateforme.</p>
        </div>
      </div>

      <div className="flex gap-3" style={{ marginBottom: 'var(--space-4)', flexWrap: 'wrap' }}>
        <div className="input-icon-wrap" style={{ maxWidth: 280, flex: 1 }}>
          <i className="fa-solid fa-magnifying-glass" aria-hidden="true" />
          <input
            className="input"
            placeholder="Rechercher une action, un objet…"
            value={recherche}
            onChange={(e) => { setRecherche(e.target.value); resetPage() }}
          />
        </div>
        <input
          className="input"
          style={{ maxWidth: 240 }}
          placeholder="Auteur (e-mail exact)"
          value={auteur}
          onChange={(e) => { setAuteur(e.target.value); resetPage() }}
        />
        <select className="select" aria-label="Module" style={{ maxWidth: 220 }} value={module} onChange={(e) => { setModule(e.target.value); resetPage() }}>
          <option value="">Tous les modules</option>
          {AUDIT_MODULES.map((m) => (
            <option key={m} value={m}>{m}</option>
          ))}
        </select>
        <input
          className="input"
          type="date"
          aria-label="Date de début"
          style={{ maxWidth: 170 }}
          value={dateDebut}
          onChange={(e) => { setDateDebut(e.target.value); resetPage() }}
        />
        <input
          className="input"
          type="date"
          aria-label="Date de fin"
          style={{ maxWidth: 170 }}
          value={dateFin}
          onChange={(e) => { setDateFin(e.target.value); resetPage() }}
        />
      </div>

      {status === 'loading' ? (
        <div className="text-center" style={{ padding: 'var(--space-16)' }}>
          <Spinner large />
        </div>
      ) : status === 'error' ? (
        <Alert variant="warning">Le journal n'a pas pu être chargé. Réessayez plus tard.</Alert>
      ) : items.length === 0 ? (
        <div className="empty-state card">
          <p>Aucune entrée pour ce filtre.</p>
        </div>
      ) : (
        <>
          <div className="table-wrap">
            <table className="table">
              <thead>
                <tr>
                  <th>Utilisateur</th>
                  <th>Rôle</th>
                  <th>Action</th>
                  <th>Module</th>
                  <th>Objet</th>
                  <th>Date</th>
                  <th>Résultat</th>
                  <th>Ancienne valeur</th>
                  <th>Nouvelle valeur</th>
                  <th>Motif</th>
                </tr>
              </thead>
              <tbody>
                {items.map((l) => (
                  <tr key={l.id}>
                    <td className="fw-medium">{l.auteur?.nom || l.auteur?.email || '—'}</td>
                    <td><span className="badge badge-neutral">{l.auteur?.role || '—'}</span></td>
                    <td>{l.action}</td>
                    <td><span className="badge badge-neutral">{l.module}</span></td>
                    <td className="caption">{l.objet || '—'}</td>
                    <td className="caption">{formatDateFr(l.horodatage)}</td>
                    <td className="caption">{l.resultat || '—'}</td>
                    <td className="caption">{l.ancienne_valeur || '—'}</td>
                    <td className="caption">{l.nouvelle_valeur || '—'}</td>
                    <td className="caption">{l.motif || '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {meta && meta.last_page > 1 ? (
            <div className="flex justify-between items-center" style={{ marginTop: 'var(--space-4)' }}>
              <span className="caption">Page {meta.current_page} / {meta.last_page} · {meta.total} entrées</span>
              <div className="pagination">
                <button type="button" className="page-btn" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
                  <i className="fa-solid fa-chevron-left" aria-hidden="true" />
                </button>
                <button type="button" className="page-btn" disabled={page >= meta.last_page} onClick={() => setPage((p) => p + 1)}>
                  <i className="fa-solid fa-chevron-right" aria-hidden="true" />
                </button>
              </div>
            </div>
          ) : null}
        </>
      )}
    </AppShell>
  )
}
