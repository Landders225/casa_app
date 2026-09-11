import { Link } from 'react-router-dom'
import { AppShell } from '../../components/layout/AppShell.jsx'
import { Alert } from '../../components/ui/Alert.jsx'
import { FullPageSpinner } from '../../components/ui/Spinner.jsx'
import { paths } from '../../routing/routes.js'
import { useDocuments } from './useDocuments.js'
import { EXPERIENCE_DOMAINES, EXPERIENCE_DUREES, PIECES_DOSSIER } from './wizard/formStructure.js'

/**
 * Écran « Documents » (Lot 14) — re-consultation / re-téléchargement des
 * pièces après soumission. Comble le manque tracé dans POINTS-OUVERTS : le
 * wizard (`StepDocuments.jsx`) ne gère le dépôt qu'EN BROUILLON.
 *
 * Contrat strict par `statut_public` (aucune dérivation, ADR-03) :
 *  - aucune candidature / brouillon -> écran d'atterrissage, CTA vers le
 *    wizard (chemin canonique du dépôt/suppression — jamais dupliqué ici,
 *    pas même en lecture, cf. Étape 1 point c) ;
 *  - soumis (peu importe la décision) -> LECTURE SEULE stricte : aucun
 *    bouton dépôt/suppression n'est RENDU (pas juste désactivé) — le backend
 *    les refuserait de toute façon (409, `candidature.modifiable`), mais
 *    l'UI ne doit même pas les proposer.
 *
 * Téléchargement : lien direct `<a href={piece.url} target="_blank">`,
 * patron déjà en prod côté évaluateur (`FicheCandidat.jsx`, Lot 4a) — jamais
 * d'id/URL construit côté client, toujours celui renvoyé par le serveur.
 */
function humanSize(bytes) {
  if (bytes < 1024) return `${bytes} o`
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} Ko`
  return `${(bytes / (1024 * 1024)).toFixed(1)} Mo`
}

function labelDomaine(code) {
  return EXPERIENCE_DOMAINES.find((d) => d.value === code)?.label ?? code
}

function labelDuree(code) {
  return EXPERIENCE_DUREES.find((d) => d.value === code)?.label ?? code
}

function LigneDeposee({ label, piece }) {
  return (
    <a
      className="file-row"
      style={{ marginBottom: 'var(--space-3)', textDecoration: 'none' }}
      href={piece.url}
      target="_blank"
      // `noopener` SEUL (pas `noreferrer`) : Sanctum a besoin de l'en-tête
      // `Referer` pour reconnaître cette navigation same-origin comme une
      // requête « frontend » — sans lui, 401 (piège attrapé par l'E2E,
      // cf. FicheCandidat.jsx où le même bug existait). `noopener` protège
      // déjà contre le reverse-tabnabbing, seul risque réel d'un `_blank`.
      rel="noopener"
    >
      <div className="file-icon">
        <i className="fa-solid fa-file-lines" aria-hidden="true" />
      </div>
      <div style={{ flex: 1, minWidth: 0 }}>
        <div className="fw-medium body-sm" style={{ color: 'var(--casa-text-primary)' }}>{label}</div>
        <div className="caption truncate">{piece.nom_original} · {humanSize(piece.taille_octets)}</div>
      </div>
      <span className="badge badge-success">
        <i className="fa-solid fa-download" aria-hidden="true" /> Télécharger
      </span>
    </a>
  )
}

function LigneManquante({ label }) {
  return (
    <div className="file-row file-row-empty" style={{ borderStyle: 'dashed', marginBottom: 'var(--space-3)' }}>
      <div className="file-icon" style={{ background: 'var(--casa-bg-alt)', color: 'var(--casa-text-tertiary)' }}>
        <i className="fa-solid fa-file-circle-xmark" aria-hidden="true" />
      </div>
      <div style={{ flex: 1, minWidth: 0 }}>
        <div className="fw-medium body-sm">{label}</div>
        <div className="caption">Non déposé</div>
      </div>
      <span className="badge badge-warning">Manquant</span>
    </div>
  )
}

function EcranAtterrissage({ jamaisSoumis }) {
  return (
    <AppShell title="Documents">
      <div className="empty-state card" style={{ maxWidth: 520, margin: '0 auto' }}>
        <div className="empty-icon">
          <i className="fa-solid fa-folder-open" aria-hidden="true" />
        </div>
        <h3>
          {jamaisSoumis
            ? "Vous n'avez pas encore de dossier de candidature"
            : 'Vos documents se gèrent depuis votre dossier de candidature'}
        </h3>
        <p>
          {jamaisSoumis
            ? 'Complétez votre dossier en quelques étapes pour rejoindre le projet CASA.'
            : "Déposez, remplacez ou retirez vos pièces justificatives depuis le formulaire — cet écran affichera vos documents en lecture seule une fois votre dossier soumis."}
        </p>
        <Link to={paths.candidatureWizard} className="btn btn-primary">
          {jamaisSoumis ? 'Démarrer mon dossier' : 'Reprendre mon dossier'}{' '}
          <i className="fa-solid fa-arrow-right" aria-hidden="true" />
        </Link>
      </div>
    </AppShell>
  )
}

export function Documents() {
  const s = useDocuments()

  if (s.status === 'loading') return <FullPageSpinner />

  if (s.status === 'error') {
    return (
      <AppShell title="Documents">
        <Alert variant="warning">Vos documents n'ont pas pu être chargés. Réessayez plus tard.</Alert>
      </AppShell>
    )
  }

  if (s.status === 'none') return <EcranAtterrissage jamaisSoumis />
  if (s.statutPublic === 'brouillon') return <EcranAtterrissage jamaisSoumis={false} />

  const piecesParCode = Object.fromEntries(s.piecesDossier.map((p) => [p.type_document_code, p]))

  return (
    <AppShell title="Documents">
      <div className="page-head">
        <div>
          <h2>Documents</h2>
          <p className="text-muted">Dossier n° {s.numeroDossier} — pièces transmises lors de votre candidature.</p>
        </div>
      </div>

      <div style={{ marginBottom: 'var(--space-5)' }}>
        <Alert variant="info">
          Votre dossier a été soumis : ces documents sont en lecture seule.
        </Alert>
      </div>

      <div className="card" style={{ maxWidth: 720, marginBottom: 'var(--space-6)' }}>
        <div className="card-header">
          <h3>Pièces du dossier</h3>
          <span className="badge badge-neutral">{s.piecesDossier.length}/6 déposées</span>
        </div>
        {PIECES_DOSSIER.map((type) => {
          const piece = piecesParCode[type.code]
          return piece
            ? <LigneDeposee key={type.code} label={type.label} piece={piece} />
            : <LigneManquante key={type.code} label={type.label} />
        })}
      </div>

      {s.experiences.length > 0 ? (
        <div className="card" style={{ maxWidth: 720 }}>
          <div className="card-header">
            <h3>Justificatifs d'expérience</h3>
          </div>
          {s.experiences.map((exp) => {
            const label = `${labelDomaine(exp.domaine)} · ${labelDuree(exp.duree_categorie)}`
            return exp.justificatif
              ? <LigneDeposee key={exp.id} label={label} piece={exp.justificatif} />
              : <LigneManquante key={exp.id} label={label} />
          })}
        </div>
      ) : null}
    </AppShell>
  )
}
