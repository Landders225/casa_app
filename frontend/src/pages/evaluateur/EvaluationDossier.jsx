import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { AppShell } from '../../components/layout/AppShell.jsx'
import { Alert } from '../../components/ui/Alert.jsx'
import { FullPageSpinner } from '../../components/ui/Spinner.jsx'
import { formatDateFr } from '../../lib/formatDate.js'
import { evaluateurDossierPath, evaluateurEntretienPath, paths } from '../../routing/routes.js'
import { ConfirmDialog } from './ConfirmDialog.jsx'
import { EligibilitePanel } from './EligibilitePanel.jsx'
import './Notation.css'
import { ScoreRing } from './ScoreRing.jsx'
import { StarPicker } from './StarPicker.jsx'
import { useEvaluationDossier } from './useEvaluationDossier.js'
import { useFicheCandidat } from './useFicheCandidat.js'

/**
 * Notation du dossier /65 (Lot 8c-2) — `/evaluateur/candidatures/:id/evaluation`.
 *
 * LE SCORE VIENT TOUJOURS DE L'API (ADR-02, ADR-04) : cet écran ne calcule
 * jamais de note — `useEvaluationDossier` remplace intégralement son état par
 * la réponse du GET/PUT/POST (aperçu recalculé serveur, puis snapshot figé
 * après validation). Le détail par rubrique affiché dans `ScoreRing` vient du
 * même payload.
 *
 * SEULE zone éditable : la Motivation (`mo04_note_etoiles` + `commentaire_evaluateur`,
 * D-4b-2). La maquette laisse l'évaluateur ré-éditer les réponses SC/SE/DI du
 * candidat avec recalcul JS à chaque clic — le backend
 * (`EnregistrerEvaluationRequest`) n'accepte que ces deux champs, donc l'UI ne
 * propose pas cette ré-édition (D-8c2-1) : les autres rubriques ne sont
 * visibles ici que via leur SCORE agrégé (`ScoreRing`) ; la consultation
 * détaillée des réponses reste sur la fiche candidat (bouton dédié).
 *
 * Verrouillage réel (ADR-04) : après validation, `<fieldset disabled>` rend
 * les champs NATIVEMENT non éditables, les boutons Enregistrer/Valider sont
 * REMPLACÉS (pas grisés) par la bannière de verrouillage.
 */
export function EvaluationDossier() {
  const { id } = useParams()
  // Identité, lettre MO.04, éligibilité, statut de vérification — déjà fourni
  // par l'endpoint fiche (8c-1), aucun appel réseau supplémentaire nécessaire.
  const { status: ficheStatus, dossier } = useFicheCandidat(id)
  const { status, evaluation, saving, validating, error, save, validate } = useEvaluationDossier(id)

  if (ficheStatus === 'loading' || status === 'loading') return <FullPageSpinner />

  if (ficheStatus === 'not_found') {
    return (
      <AppShell title="Évaluation du dossier" space="evaluateur">
        <div className="empty-state card" style={{ maxWidth: 480, margin: '0 auto' }}>
          <div className="empty-icon">
            <i className="fa-solid fa-user-slash" aria-hidden="true" />
          </div>
          <h3>Candidat introuvable</h3>
          <p>Ce dossier n'existe pas ou ne vous est pas affecté.</p>
          <Link to={paths.evaluateurDossiers} className="btn btn-primary">Retour à la liste</Link>
        </div>
      </AppShell>
    )
  }

  if (ficheStatus === 'error' || status === 'error' || !dossier || !evaluation) {
    return (
      <AppShell title="Évaluation du dossier" space="evaluateur">
        <Alert variant="warning">L'évaluation n'a pas pu être chargée. Réessayez plus tard.</Alert>
      </AppShell>
    )
  }

  return (
    <AppShell title="Évaluation du dossier" space="evaluateur">
      <div className="breadcrumbs" style={{ marginBottom: 'var(--space-4)' }}>
        <Link to={paths.evaluateurDossiers}>Mes dossiers</Link>
        <span className="sep">/</span>
        <Link to={evaluateurDossierPath(dossier.id)}>{dossier.candidat?.prenom} {dossier.candidat?.nom}</Link>
        <span className="sep">/</span>
        <span>Évaluation</span>
      </div>

      <div className="card" style={{ marginBottom: 'var(--space-6)' }}>
        <div className="flex items-center gap-4" style={{ flexWrap: 'wrap' }}>
          <span className="avatar avatar-lg">
            {(dossier.candidat?.prenom?.[0] || '') + (dossier.candidat?.nom?.[0] || '')}
          </span>
          <div style={{ flex: 1, minWidth: 200 }}>
            <h3>{dossier.candidat?.prenom} {dossier.candidat?.nom}</h3>
            <p className="caption">{dossier.numero_dossier} · {dossier.filiere?.nom} · {dossier.candidat?.ville_residence}</p>
          </div>
          <Link to={evaluateurDossierPath(dossier.id)} className="btn btn-outline btn-sm">Voir la fiche complète</Link>
        </div>
      </div>

      <EvaluationForm dossier={dossier} evaluation={evaluation} saving={saving} validating={validating} error={error} onSave={save} onValidate={validate} />
    </AppShell>
  )
}

function verificationComplete(dossier) {
  const v = dossier.verification
  return !!v && v.nationalite_confirmee !== null && v.diplome_verifie !== null
}

function EvaluationForm({ dossier, evaluation, saving, validating, error, onSave, onValidate }) {
  const [etoiles, setEtoiles] = useState(evaluation.mo04_note_etoiles)
  const [commentaire, setCommentaire] = useState(evaluation.commentaire_evaluateur || '')
  const [saved, setSaved] = useState(false)
  const [confirming, setConfirming] = useState(false)

  const enInstruction = dossier.statut_interne === 'en_instruction'
  const editable = !evaluation.verrouille && enInstruction
  const verifOk = verificationComplete(dossier)
  const noteOk = etoiles !== null && etoiles !== undefined
  const peutValider = editable && verifOk && noteOk

  const submit = async () => {
    setSaved(false)
    try {
      await onSave({ mo04_note_etoiles: etoiles, commentaire_evaluateur: commentaire })
      setSaved(true)
    } catch {
      // `error` est déjà posé par le hook — rien à faire ici.
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
    <div className="eval-layout">
      <div>
        {evaluation.verrouille ? (
          <div className="lock-banner" style={{ marginBottom: 'var(--space-5)' }}>
            <i className="fa-solid fa-lock" style={{ fontSize: '1.2rem' }} aria-hidden="true" />
            <div>
              🔒 ÉVALUATION VALIDÉE
              <div className="caption fw-normal" style={{ color: 'inherit', opacity: 0.85 }}>
                Verrouillée le {formatDateFr(evaluation.valide_le)}
                {evaluation.valide_par ? ` par ${evaluation.valide_par.prenom} ${evaluation.valide_par.nom}` : ''}.
                Seule une correction exceptionnelle (administrateur) peut la rouvrir.
              </div>
            </div>
          </div>
        ) : null}

        {!evaluation.verrouille && !enInstruction ? (
          <div style={{ marginBottom: 'var(--space-5)' }}>
            <Alert variant="info">Ce dossier n'est pas (ou plus) « en cours d'instruction » : la notation n'est pas ouverte.</Alert>
          </div>
        ) : null}

        {!evaluation.verrouille && enInstruction && !verifOk ? (
          <div style={{ marginBottom: 'var(--space-5)' }}>
            <Alert variant="warning">
              La vérification du dossier (nationalité confirmée et diplôme vérifié) doit être complétée avant de valider la notation.{' '}
              <Link to={evaluateurDossierPath(dossier.id)}>Aller à la fiche (onglet Vérification)</Link>
            </Alert>
          </div>
        ) : null}

        {error ? (
          <div style={{ marginBottom: 'var(--space-5)' }}>
            <Alert variant="danger">{error}</Alert>
          </div>
        ) : null}
        {saved && !error && !evaluation.verrouille ? (
          <div style={{ marginBottom: 'var(--space-5)' }}>
            <Alert variant="success">Brouillon enregistré.</Alert>
          </div>
        ) : null}

        <fieldset disabled={!editable} style={{ border: 0, padding: 0, margin: 0 }}>
          <div className="card rubrique-card">
            <h3 style={{ marginBottom: 'var(--space-4)' }}>Motivation (dossier)</h3>
            <div className="form-group">
              <label className="label">Lettre de motivation du candidat</label>
              <div className="card card-sunken" style={{ padding: 'var(--space-4)' }}>
                <p className="body-sm" style={{ whiteSpace: 'pre-wrap' }}>
                  {dossier.reponses?.mo04_lettre_motivation || 'Non renseignée.'}
                </p>
              </div>
            </div>
            <div className="form-group" style={{ marginBottom: 0 }}>
              <label className="label">Notation qualitative de la motivation (0 à 5)</label>
              <StarPicker value={etoiles} onChange={setEtoiles} disabled={!editable} />
            </div>
          </div>

          <div className="card">
            <h3 style={{ marginBottom: 'var(--space-4)' }}>Commentaire qualitatif</h3>
            <textarea
              className="textarea"
              rows={4}
              placeholder="Observations de l'évaluateur..."
              value={commentaire}
              onChange={(e) => setCommentaire(e.target.value)}
              readOnly={!editable}
            />
          </div>
        </fieldset>
      </div>

      <div className="eval-sticky">
        <ScoreRing title="Score dossier" total={evaluation.score_total} max={evaluation.volet_max} rubriques={evaluation.rubriques} />

        <div style={{ marginTop: 'var(--space-6)' }}>
          <EligibilitePanel dossier={dossier} />
        </div>

        {evaluation.verrouille ? (
          <Link to={evaluateurEntretienPath(dossier.id)} className="btn btn-primary btn-block" style={{ marginTop: 'var(--space-6)' }}>
            <i className="fa-solid fa-comments" aria-hidden="true" /> Entretien
          </Link>
        ) : editable ? (
          <div className="flex gap-3" style={{ marginTop: 'var(--space-6)' }}>
            <button type="button" className="btn btn-outline btn-block" disabled={saving} onClick={submit}>
              {saving ? 'Enregistrement…' : 'Enregistrer'}
            </button>
            <button type="button" className="btn btn-primary btn-block" disabled={!peutValider || validating} onClick={() => setConfirming(true)}>
              <i className="fa-solid fa-check" aria-hidden="true" /> Valider définitivement
            </button>
          </div>
        ) : null}
      </div>

      {confirming ? (
        <ConfirmDialog
          title="Valider définitivement ?"
          message="Une fois validée, cette évaluation sera verrouillée : vous ne pourrez plus la modifier. Seul un administrateur pourra effectuer une correction exceptionnelle."
          confirmLabel="Valider"
          loading={validating}
          onCancel={() => setConfirming(false)}
          onConfirm={submitValidation}
        />
      ) : null}
    </div>
  )
}
