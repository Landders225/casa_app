import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useAuth } from '../../auth/useAuth.js'
import { AppShell } from '../../components/layout/AppShell.jsx'
import { Alert } from '../../components/ui/Alert.jsx'
import { FullPageSpinner } from '../../components/ui/Spinner.jsx'
import { formatDateFr } from '../../lib/formatDate.js'
import { evaluateurDossierPath, evaluateurEvaluationPath, retourListeLabel, retourListePath } from '../../routing/routes.js'
import { ConfirmDialog } from './ConfirmDialog.jsx'
import './Notation.css'
import { PointPicker } from './PointPicker.jsx'
import { ScoreRing } from './ScoreRing.jsx'
import { useEntretien } from './useEntretien.js'
import { useFicheCandidat } from './useFicheCandidat.js'

/**
 * Lieux d'entretien (Lot 8c-2) — miroir des 2 valeurs acceptées par
 * `EnregistrerEntretienRequest` (`Rule::in([...])`). Ce sont des noms de site,
 * pas du barème.
 */
const LIEUX_ENTRETIEN = ['Le Plateau', '2 Plateaux Vallons']

/**
 * Entretien /35 (Lot 8c-2) — `/evaluateur/candidatures/:id/entretien`.
 *
 * Trois états pilotés par l'état SERVEUR (`useEntretien`), jamais par une
 * navigation locale :
 *  1. `dossierVerrouille === false` → empty-state, aucun formulaire (le
 *     dossier /65 doit être validé en premier — Lot 4b) ;
 *  2. `dossierVerrouille === true` et `entretien === null` → planification
 *     (date/heure/lieu), qui CRÉE l'entretien via le même `PUT` ;
 *  3. `entretien !== null` → notation : présence, 12 sous-notes bornées
 *     dynamiquement par `sous_notes[].max` (API), observation, score /35.
 *
 * Le score AFFICHÉ (total, détail par rubrique, sous-notes) vient toujours de
 * la réponse serveur — jamais recalculé ici (ADR-02, ADR-04).
 */
export function Entretien() {
  const { id } = useParams()
  const { role } = useAuth()
  const { status: ficheStatus, dossier } = useFicheCandidat(id)
  const { status, dossierVerrouille, entretien, saving, validating, error, save, validate } = useEntretien(id)
  const retour = retourListePath(role)

  if (ficheStatus === 'loading' || status === 'loading') return <FullPageSpinner />

  if (ficheStatus === 'not_found') {
    return (
      <AppShell title="Entretien" space="evaluateur">
        <div className="empty-state card" style={{ maxWidth: 480, margin: '0 auto' }}>
          <div className="empty-icon">
            <i className="fa-solid fa-user-slash" aria-hidden="true" />
          </div>
          <h3>Candidat introuvable</h3>
          <p>Ce dossier n'existe pas ou ne vous est pas affecté.</p>
          <Link to={retour} className="btn btn-primary">Retour à la liste</Link>
        </div>
      </AppShell>
    )
  }

  if (ficheStatus === 'error' || status === 'error' || !dossier) {
    return (
      <AppShell title="Entretien" space="evaluateur">
        <Alert variant="warning">L'entretien n'a pas pu être chargé. Réessayez plus tard.</Alert>
      </AppShell>
    )
  }

  return (
    <AppShell title="Entretien" space="evaluateur">
      <div className="breadcrumbs" style={{ marginBottom: 'var(--space-4)' }}>
        <Link to={retour}>{retourListeLabel(role)}</Link>
        <span className="sep">/</span>
        <Link to={evaluateurDossierPath(dossier.id)}>{dossier.candidat?.prenom} {dossier.candidat?.nom}</Link>
        <span className="sep">/</span>
        <span>Entretien</span>
      </div>

      {!dossierVerrouille ? (
        <div className="empty-state card">
          <div className="empty-icon">
            <i className="fa-solid fa-lock" aria-hidden="true" />
          </div>
          <h3>Dossier pas encore validé</h3>
          <p>L'évaluation du dossier doit être validée avant de pouvoir programmer ou conduire l'entretien.</p>
          <Link to={evaluateurEvaluationPath(dossier.id)} className="btn btn-primary">Aller à l'évaluation du dossier</Link>
        </div>
      ) : entretien === null ? (
        <Planification dossier={dossier} saving={saving} error={error} onSave={save} />
      ) : (
        <EntretienNotation dossier={dossier} entretien={entretien} saving={saving} validating={validating} error={error} onSave={save} onValidate={validate} />
      )}
    </AppShell>
  )
}

function Planification({ dossier, saving, error, onSave }) {
  const [date, setDate] = useState('')
  const [heure, setHeure] = useState('')
  const [lieu, setLieu] = useState(LIEUX_ENTRETIEN[0])

  const submit = async (e) => {
    e.preventDefault()
    try {
      await onSave({ date, heure, lieu })
    } catch {
      // `error` est déjà posé par le hook.
    }
  }

  return (
    <div className="card" style={{ maxWidth: 560 }}>
      <div className="flex items-center gap-4" style={{ marginBottom: 'var(--space-6)' }}>
        <span className="avatar avatar-lg">
          {(dossier.candidat?.prenom?.[0] || '') + (dossier.candidat?.nom?.[0] || '')}
        </span>
        <div>
          <h3>{dossier.candidat?.prenom} {dossier.candidat?.nom}</h3>
          <p className="caption">{dossier.numero_dossier} · {dossier.filiere?.nom}</p>
        </div>
      </div>
      <h4 style={{ marginBottom: 'var(--space-4)' }}>Planifier l'entretien</h4>

      {error ? (
        <div style={{ marginBottom: 'var(--space-4)' }}>
          <Alert variant="danger">{error}</Alert>
        </div>
      ) : null}

      <form onSubmit={submit}>
        <div className="form-row">
          <div className="form-group">
            <label className="label" htmlFor="ent-date">Date</label>
            <input className="input" type="date" id="ent-date" value={date} onChange={(e) => setDate(e.target.value)} required />
          </div>
          <div className="form-group">
            <label className="label" htmlFor="ent-heure">Heure</label>
            <input className="input" type="time" id="ent-heure" value={heure} onChange={(e) => setHeure(e.target.value)} required />
          </div>
        </div>
        <div className="form-group">
          <label className="label" htmlFor="ent-lieu">Lieu</label>
          <select className="select" id="ent-lieu" value={lieu} onChange={(e) => setLieu(e.target.value)}>
            {LIEUX_ENTRETIEN.map((l) => (
              <option key={l} value={l}>{l}</option>
            ))}
          </select>
        </div>
        <button type="submit" className="btn btn-primary btn-block" disabled={saving}>
          {saving ? 'Planification…' : <><i className="fa-solid fa-calendar-check" aria-hidden="true" /> Planifier l'entretien</>}
        </button>
      </form>
    </div>
  )
}

function EntretienNotation({ dossier, entretien, saving, validating, error, onSave, onValidate }) {
  const [presence, setPresence] = useState(entretien.presence)
  const [notes, setNotes] = useState(() =>
    Object.fromEntries((entretien.sous_notes || []).map((sn) => [sn.code, Number(sn.points_attribues) || 0])),
  )
  const [observation, setObservation] = useState(entretien.observation || '')
  const [saved, setSaved] = useState(false)
  const [confirming, setConfirming] = useState(false)

  const editable = !entretien.verrouille
  const isAbsent = presence === 'absent'
  const peutValider = editable && presence !== null && presence !== undefined

  const sousNotesParRubrique = {}
  for (const sn of entretien.sous_notes || []) {
    ;(sousNotesParRubrique[sn.rubrique_code] ??= []).push(sn)
  }

  const submit = async () => {
    setSaved(false)
    const patch = { presence, observation }
    if (presence === 'present') patch.notes = { ...notes }
    try {
      await onSave(patch)
      setSaved(true)
    } catch {
      // `error` est déjà posé par le hook.
    }
  }

  const submitValidation = async () => {
    setConfirming(false)
    try {
      await onValidate()
    } catch {
      // `error` est déjà posé par le hook.
    }
  }

  return (
    <>
      <div className="card" style={{ marginBottom: 'var(--space-6)' }}>
        <div className="flex items-center gap-4" style={{ flexWrap: 'wrap' }}>
          <span className="avatar avatar-lg">
            {(dossier.candidat?.prenom?.[0] || '') + (dossier.candidat?.nom?.[0] || '')}
          </span>
          <div style={{ flex: 1, minWidth: 200 }}>
            <h3>{dossier.candidat?.prenom} {dossier.candidat?.nom}</h3>
            <p className="caption">{dossier.numero_dossier} · {dossier.filiere?.nom} · {dossier.candidat?.ville_residence}</p>
          </div>
          <div className="caption text-right">
            <i className="fa-solid fa-calendar" aria-hidden="true" /> {formatDateFr(entretien.date)} à {entretien.heure}
            <br />
            <i className="fa-solid fa-location-dot" aria-hidden="true" /> {entretien.lieu}
          </div>
          <Link to={evaluateurDossierPath(dossier.id)} className="btn btn-outline btn-sm">Voir la fiche complète</Link>
        </div>
      </div>

      {entretien.verrouille ? (
        <div className="lock-banner" style={{ marginBottom: 'var(--space-5)' }}>
          <i className="fa-solid fa-lock" style={{ fontSize: '1.2rem' }} aria-hidden="true" />
          <div>
            🔒 ENTRETIEN VALIDÉ
            <div className="caption fw-normal" style={{ color: 'inherit', opacity: 0.85 }}>
              Verrouillé le {formatDateFr(entretien.valide_le)}
              {entretien.valide_par ? ` par ${entretien.valide_par.prenom} ${entretien.valide_par.nom}` : ''}.
            </div>
          </div>
        </div>
      ) : null}

      {error ? (
        <div style={{ marginBottom: 'var(--space-5)' }}>
          <Alert variant="danger">{error}</Alert>
        </div>
      ) : null}
      {saved && !error && !entretien.verrouille ? (
        <div style={{ marginBottom: 'var(--space-5)' }}>
          <Alert variant="success">Entretien enregistré.</Alert>
        </div>
      ) : null}

      <div className="eval-layout">
        <fieldset disabled={!editable} style={{ border: 0, padding: 0, margin: 0 }}>
          <div className="card" style={{ marginBottom: 'var(--space-6)' }}>
            <h4 style={{ marginBottom: 'var(--space-4)' }}>Présence</h4>
            <div className="presence-toggle">
              <button
                type="button"
                className={`is-present${presence === 'present' ? ' is-selected' : ''}`}
                onClick={() => setPresence('present')}
              >
                <i className="fa-solid fa-user-check" aria-hidden="true" /> Présent
              </button>
              <button
                type="button"
                className={`is-absent${presence === 'absent' ? ' is-selected' : ''}`}
                onClick={() => setPresence('absent')}
              >
                <i className="fa-solid fa-user-xmark" aria-hidden="true" /> Absent
              </button>
            </div>
            {isAbsent ? (
              <div style={{ marginTop: 'var(--space-4)' }}>
                <Alert variant="warning">Candidat absent — les sous-critères sont notés à 0 et ne sont pas modifiables.</Alert>
              </div>
            ) : null}
          </div>

          {(entretien.rubriques || []).map((r) => (
            <div className="card rubrique-card" key={r.code}>
              <div className="rc-head">
                <h3>{r.label}</h3>
                <span className="rc-score">{Number(r.score_obtenu).toFixed(1)}/{r.max}</span>
              </div>
              {(sousNotesParRubrique[r.code] || []).map((sn) => (
                <div className="sc-row" key={sn.code}>
                  <span className="body-sm">{sn.label}</span>
                  <PointPicker
                    label={sn.label}
                    max={sn.max}
                    value={isAbsent ? 0 : (notes[sn.code] ?? 0)}
                    disabled={!editable || isAbsent}
                    onChange={(v) => setNotes((n) => ({ ...n, [sn.code]: v }))}
                  />
                </div>
              ))}
            </div>
          ))}

          <div className="card">
            <h3 style={{ marginBottom: 'var(--space-4)' }}>Observation</h3>
            <textarea
              className="textarea"
              rows={4}
              placeholder="Observations de l'évaluateur pendant l'entretien..."
              value={observation}
              onChange={(e) => setObservation(e.target.value)}
              readOnly={!editable}
            />
          </div>
        </fieldset>

        <div className="eval-sticky">
          <ScoreRing
            title="Score entretien"
            total={entretien.score_total}
            max={entretien.volet_max}
            accent="var(--casa-accent-500)"
            rubriques={entretien.rubriques}
          />

          {!entretien.verrouille ? (
            <div className="flex gap-3" style={{ marginTop: 'var(--space-6)' }}>
              <button type="button" className="btn btn-outline btn-block" disabled={saving} onClick={submit}>
                {saving ? 'Enregistrement…' : 'Enregistrer'}
              </button>
              <button
                type="button"
                className="btn btn-primary btn-block"
                disabled={!peutValider || validating}
                title={peutValider ? undefined : "Indiquez la présence du candidat avant de valider l'entretien."}
                onClick={() => setConfirming(true)}
              >
                <i className="fa-solid fa-check" aria-hidden="true" /> Valider définitivement
              </button>
            </div>
          ) : null}
        </div>
      </div>

      {confirming ? (
        <ConfirmDialog
          title="Valider définitivement ?"
          message="Une fois validé, cet entretien sera verrouillé : vous ne pourrez plus le modifier."
          confirmLabel="Valider"
          loading={validating}
          onCancel={() => setConfirming(false)}
          onConfirm={submitValidation}
        />
      ) : null}
    </>
  )
}
