import { Link } from 'react-router-dom'
import { AppShell } from '../../components/layout/AppShell.jsx'
import { Alert } from '../../components/ui/Alert.jsx'
import { FullPageSpinner } from '../../components/ui/Spinner.jsx'
import { formatDateFr } from '../../lib/formatDate.js'
import { paths } from '../../routing/routes.js'
import { useMaCandidature } from './useMaCandidature.js'
import {
  MESSAGE_EN_TRAITEMENT,
  SUIVI_ETAPES,
  messageResultat,
  suiviEtapeCourante,
} from './resultatMessages.js'

/**
 * Écran « Ma candidature » — SUIVI puis RÉSULTAT (Lot 8b-3).
 *
 * Rendu conditionnel par `statut_public` SEUL. Voir `resultatMessages.js` pour
 * la règle reine côté UI : aucun calcul, aucun état interne, un `switch` pur.
 */

const STATUT_BADGE = {
  brouillon: { label: 'Brouillon', badge: 'badge-neutral' },
  en_cours_de_traitement: { label: 'En cours de traitement', badge: 'badge-info' },
  decision_publiee: { label: 'Décision publiée', badge: 'badge-primary' },
}

/** Suivi : 4 étapes fixes, état dérivé UNIQUEMENT de `statut_public`. */
function Stepper({ statutPublic }) {
  const courante = suiviEtapeCourante(statutPublic)
  return (
    <div className="timeline">
      {SUIVI_ETAPES.map((label, i) => {
        const cls = i < courante ? 'is-done' : i === courante ? 'is-active' : 'is-pending'
        return (
          <div className={`timeline-item ${cls}`} key={label}>
            <span className="timeline-dot" />
            <div className="timeline-title">{label}</div>
          </div>
        )
      })}
    </div>
  )
}

function ResultatBanner({ decision, filiereNom, motifCommunicable }) {
  const m = messageResultat(decision, { filiereNom, motif: motifCommunicable })
  return (
    <Alert variant={m.variant} title={m.titre}>
      {m.motif ? (
        <p style={{ marginTop: 'var(--space-2)' }}>
          <strong>Motif :</strong>
          <br />
          {m.motif}
        </p>
      ) : (
        m.corps
      )}
    </Alert>
  )
}

const RecapRow = ({ k, v }) => (
  <div className="flex justify-between body-sm">
    <span className="text-muted">{k}</span>
    <span className="fw-semibold">{v}</span>
  </div>
)

function EmptyDossier({ jamaisSoumis }) {
  return (
    <AppShell title="Ma candidature">
      <div className="empty-state card" style={{ maxWidth: 520, margin: '0 auto' }}>
        <div className="empty-icon">
          <i className="fa-solid fa-file-circle-question" aria-hidden="true" />
        </div>
        <h3>
          {jamaisSoumis
            ? "Vous n'avez pas encore de dossier de candidature"
            : "Votre dossier n'est pas encore soumis"}
        </h3>
        <p>
          {jamaisSoumis
            ? 'Complétez votre dossier en quelques étapes pour rejoindre le projet CASA.'
            : 'Reprenez là où vous vous êtes arrêté(e) : filière, informations, justificatifs, puis soumission.'}
        </p>
        <Link to={paths.candidatureWizard} className="btn btn-primary">
          {jamaisSoumis ? 'Démarrer mon dossier' : 'Reprendre mon dossier'}{' '}
          <i className="fa-solid fa-arrow-right" aria-hidden="true" />
        </Link>
      </div>
    </AppShell>
  )
}

export function MaCandidature() {
  const s = useMaCandidature()

  if (s.status === 'loading') return <FullPageSpinner />

  if (s.status === 'error') {
    return (
      <AppShell title="Ma candidature">
        <Alert variant="warning">Votre dossier n'a pas pu être chargé. Réessayez plus tard.</Alert>
      </AppShell>
    )
  }

  if (s.status === 'none') return <EmptyDossier jamaisSoumis />
  if (s.statutPublic === 'brouillon') return <EmptyDossier jamaisSoumis={false} />

  const badge = STATUT_BADGE[s.statutPublic] ?? { label: s.statutPublic, badge: 'badge-neutral' }

  return (
    <AppShell title="Ma candidature">
      <div className="page-head">
        <div>
          <h2>Ma candidature</h2>
          <p className="text-muted">
            Dossier n° {s.numeroDossier}
            {s.filiereNom ? ` — Filière ${s.filiereNom}` : ''}
          </p>
        </div>
        <span className={`badge ${badge.badge} badge-lg`}>{badge.label}</span>
      </div>

      <div style={{ marginBottom: 'var(--space-6)' }}>
        {s.statutPublic === 'en_cours_de_traitement' ? (
          <Alert variant={MESSAGE_EN_TRAITEMENT.variant} title={MESSAGE_EN_TRAITEMENT.titre}>
            {MESSAGE_EN_TRAITEMENT.corps}
          </Alert>
        ) : null}
        {s.statutPublic === 'decision_publiee' ? (
          <ResultatBanner
            decision={s.decision}
            filiereNom={s.filiereNom}
            motifCommunicable={s.motifCommunicable}
          />
        ) : null}
      </div>

      <div className="dashboard-layout">
        <div className="card">
          <h3 style={{ marginBottom: 'var(--space-6)' }}>Suivi de mon dossier</h3>
          <Stepper statutPublic={s.statutPublic} />
        </div>

        <div className="card">
          <h3 style={{ marginBottom: 'var(--space-4)' }}>Récapitulatif</h3>
          <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-3)' }}>
            <RecapRow k="Numéro de dossier" v={s.numeroDossier ?? '—'} />
            <RecapRow k="Date de soumission" v={formatDateFr(s.dateSoumission)} />
            <RecapRow k="Filière" v={s.filiereNom ?? '—'} />
            <RecapRow k="Cohorte" v={s.campagneNom ?? '—'} />
            <RecapRow k="Documents" v={`${s.piecesCount} / 6`} />
          </div>
        </div>
      </div>
    </AppShell>
  )
}
