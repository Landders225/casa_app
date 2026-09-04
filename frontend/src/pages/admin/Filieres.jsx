import { useEffect, useState } from 'react'
import { AppShell } from '../../components/layout/AppShell.jsx'
import { Alert } from '../../components/ui/Alert.jsx'
import { Spinner } from '../../components/ui/Spinner.jsx'
import { apiClient } from '../../lib/apiClient.js'
import { ApiError } from '../../lib/ApiError.js'

/**
 * Filières — activation/désactivation (Lot 8d-1).
 *
 * La LISTE réutilise `GET /api/filieres` (public, déjà consommé par la
 * vitrine 8b-1 et le wizard 8b-2) : `actif` y est déjà exposé, inutile de
 * dupliquer un point de lecture admin. Seul le SWITCH est réel
 * (`PATCH /admin/filieres/{id}`) — pas d'édition nom/description/quota, pas
 * de création (D-6a-2, hors backend ; le bouton « Ajouter » est d'ailleurs
 * `disabled` jusque dans la maquette elle-même).
 */
export function Filieres() {
  const [status, setStatus] = useState('loading')
  const [filieres, setFilieres] = useState([])
  const [error, setError] = useState(null)
  const [pending, setPending] = useState(null)

  useEffect(() => {
    apiClient
      .get('/filieres')
      .then((res) => {
        setFilieres(res.data ?? [])
        setStatus('ready')
      })
      .catch(() => setStatus('error'))
  }, [])

  const toggle = async (filiere) => {
    setPending(filiere.id)
    setError(null)
    try {
      const res = await apiClient.patch(`/admin/filieres/${filiere.id}`, { actif: !filiere.actif })
      setFilieres((fs) => fs.map((f) => (f.id === filiere.id ? { ...f, ...res.data } : f)))
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'La mise à jour a échoué.')
    } finally {
      setPending(null)
    }
  }

  return (
    <AppShell title="Filières CQP">
      <div className="page-head">
        <div>
          <h2>Filières CQP</h2>
          <p className="text-muted">Certificats de Qualification Professionnelle proposés par le projet CASA.</p>
        </div>
      </div>

      {error ? (
        <div style={{ marginBottom: 'var(--space-5)' }}>
          <Alert variant="danger">{error}</Alert>
        </div>
      ) : null}

      {status === 'loading' ? (
        <div className="text-center" style={{ padding: 'var(--space-16)' }}>
          <Spinner large />
        </div>
      ) : status === 'error' ? (
        <Alert variant="warning">La liste n'a pas pu être chargée. Réessayez plus tard.</Alert>
      ) : (
        <div className="grid grid-2">
          {filieres.map((f) => (
            <div className="card" key={f.id}>
              <div className="card-header">
                <h3>{f.nom}</h3>
                <label className="switch" title={f.actif ? 'Désactiver' : 'Activer'}>
                  <input
                    type="checkbox"
                    aria-label={`${f.actif ? 'Désactiver' : 'Activer'} ${f.nom}`}
                    checked={f.actif}
                    disabled={pending === f.id}
                    onChange={() => toggle(f)}
                  />
                  <span className="switch-track" />
                </label>
              </div>
              <span className={`badge ${f.actif ? 'badge-success' : 'badge-neutral'}`} style={{ marginBottom: 'var(--space-3)' }}>
                {f.actif ? 'Active — candidatures ouvertes' : 'Inactive — « Actuellement fermé » côté public'}
              </span>
              <p className="body-sm text-muted">{f.description}</p>
            </div>
          ))}
        </div>
      )}
    </AppShell>
  )
}
