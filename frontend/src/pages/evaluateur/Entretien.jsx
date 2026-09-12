import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useAuth } from '../../auth/useAuth.js'
import { AppShell } from '../../components/layout/AppShell.jsx'
import { Alert } from '../../components/ui/Alert.jsx'
import { FullPageSpinner } from '../../components/ui/Spinner.jsx'
import { formatDateFr } from '../../lib/formatDate.js'
import { evaluateurDossierPath, evaluateurEvaluationPath, retourListeLabel, retourListePath } from '../../routing/routes.js'
import { ConfirmDialog } from './ConfirmDialog.jsx'
import { CorrectionEntretienModal } from './CorrectionEntretienModal.jsx'
import './Notation.css'
import { PointPicker } from './PointPicker.jsx'
import { ScoreRing } from './ScoreRing.jsx'
import { useCorrection } from './useCorrection.js'
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
 *
 * Modifier la planification (Lot 15b) — tant que l'entretien n'est pas
 * verrouillé (`entretien.verrouille === false`, même contrainte que côté
 * serveur : 409 sinon), un bouton dans `EntretienNotation` réutilise CE MÊME
 * composant `Planification` (pré-rempli, formulaire d'édition plutôt que
 * l'écran de notation, `PUT` identique) pour corriger date/heure/lieu — c'est
 * ce qui rend atteignable la ré-notification `EntretienReplanifie` : sans ce
 * bouton, le mécanisme serveur existait mais aucun évaluateur ne pouvait
 * réellement l'atteindre.
 */
export function Entretien() {
  const { id } = useParams()
  const { role } = useAuth()
  const { status: ficheStatus, dossier } = useFicheCandidat(id)
  const { status, dossierVerrouille, entretien, saving, validating, error, save, validate, reload: reloadEntretien } = useEntretien(id)
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
        <EntretienNotation
          dossier={dossier}
          entretien={entretien}
          saving={saving}
          validating={validating}
          error={error}
          onSave={save}
          onValidate={validate}
          isAdmin={role === 'administrateur'}
          onCorrected={reloadEntretien}
        />
      )}
    </AppShell>
  )
}

/**
 * Réutilisé pour DEUX usages (Lot 15b) : la planification initiale (`initial`
 * absent, champs vides) ET la modification d'un entretien déjà planifié mais
 * pas encore verrouillé (`initial` fourni, champs pré-remplis, `onCancel`
 * affiche un bouton retour). Même formulaire, même endpoint (`PUT .../entretien`
 * avec `{date, heure, lieu}`) — c'est ce PUT qui, côté serveur, distingue les
 * deux cas et déclenche `EntretienPlanifie` (création) ou `EntretienReplanifie`
 * (modification réelle d'un entretien existant, cf. `EntretienController`).
 */
function Planification({ dossier, saving, error, initial = null, submitLabel, onCancel, onSave }) {
  const [date, setDate] = useState(initial?.date || '')
  const [heure, setHeure] = useState(initial?.heure || '')
  const [lieu, setLieu] = useState(initial?.lieu || LIEUX_ENTRETIEN[0])

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
      <h4 style={{ marginBottom: 'var(--space-4)' }}>{initial ? 'Modifier la planification' : "Planifier l'entretien"}</h4>

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
        <div className="flex gap-3">
          {onCancel ? (
            <button type="button" className="btn btn-outline btn-block" disabled={saving} onClick={onCancel}>
              Annuler
            </button>
          ) : null}
          <button type="submit" className="btn btn-primary btn-block" disabled={saving}>
            {saving ? 'Enregistrement…' : <><i className="fa-solid fa-calendar-check" aria-hidden="true" /> {submitLabel || "Planifier l'entretien"}</>}
          </button>
        </div>
      </form>
    </div>
  )
}

function EntretienNotation({ dossier, entretien, saving, validating, error, onSave, onValidate, isAdmin, onCorrected }) {
  const [presence, setPresence] = useState(entretien.presence)
  const [notes, setNotes] = useState(() =>
    Object.fromEntries((entretien.sous_notes || []).map((sn) => [sn.code, Number(sn.points_attribues) || 0])),
  )
  const [observation, setObservation] = useState(entretien.observation || '')
  const [saved, setSaved] = useState(false)
  const [confirming, setConfirming] = useState(false)
  const [correcting, setCorrecting] = useState(false)
  const [editingPlanification, setEditingPlanification] = useState(false)
  const { corrigerEntretien, correcting: savingCorrection, error: correctionError, resetError: resetCorrectionError } = useCorrection()

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

  const submitCorrection = async (payload) => {
    try {
      await corrigerEntretien(dossier.id, payload)
      setCorrecting(false)
      onCorrected()
    } catch {
      // `correctionError` est déjà posé par le hook — le modal reste ouvert.
    }
  }

  const submitReplanification = async (patch) => {
    // Même endpoint que la planification initiale (`PUT .../entretien` avec
    // {date, heure, lieu}) — c'est le serveur qui, en comparant aux valeurs
    // persistées, décide si ça déclenche EntretienReplanifie (Lot 15b).
    await onSave(patch)
    setEditingPlanification(false)
  }

  if (editingPlanification) {
    return (
      <Planification
        dossier={dossier}
        saving={saving}
        error={error}
        initial={{ date: entretien.date, heure: (entretien.heure || '').slice(0, 5), lieu: entretien.lieu }}
        submitLabel="Enregistrer la nouvelle planification"
        onCancel={() => setEditingPlanification(false)}
        onSave={submitReplanification}
      />
    )
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
          {editable ? (
            <button type="button" className="btn btn-outline btn-sm" onClick={() => setEditingPlanification(true)}>
              <i className="fa-solid fa-pen" aria-hidden="true" /> Modifier la planification
            </button>
          ) : null}
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
          {isAdmin ? (
            <button
              type="button"
              className="btn btn-outline"
              style={{ marginLeft: 'auto' }}
              onClick={() => { resetCorrectionError(); setCorrecting(true) }}
            >
              <i className="fa-solid fa-user-shield" aria-hidden="true" /> Correction exceptionnelle
            </button>
          ) : null}
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

      {correcting ? (
        <CorrectionEntretienModal
          entretien={entretien}
          saving={savingCorrection}
          error={correctionError}
          onCancel={() => setCorrecting(false)}
          onSave={submitCorrection}
        />
      ) : null}
    </>
  )
}
