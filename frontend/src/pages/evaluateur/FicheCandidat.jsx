import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { AppShell } from '../../components/layout/AppShell.jsx'
import { Alert } from '../../components/ui/Alert.jsx'
import { FullPageSpinner } from '../../components/ui/Spinner.jsx'
import { formatDateFr } from '../../lib/formatDate.js'
import { paths } from '../../routing/routes.js'
import './FicheCandidat.css'
import { useFicheCandidat } from './useFicheCandidat.js'
import { VerificationForm } from './VerificationForm.jsx'
import {
  DI02_CONTRAINTES,
  ELIGIBILITE_LABELS,
  EXPERIENCE_DOMAINES,
  EXPERIENCE_DUREES,
  NIVEAUX,
  ORIGINE_LABELS,
  PIECES_DOSSIER,
  SC02_CLASSE,
  SE01_VIT_AVEC,
  SE03_EMPLOI,
  SE04_REVENU,
  SE05_CHARGE,
  STATUT_INTERNE_LABELS,
  optionLabel,
  ouiNonLabel,
} from './optionLabels.js'

/**
 * Fiche candidat — consultation & vérification (Lot 8c-1).
 *
 * INVERSION DE POSTURE (par rapport à l'espace candidat) : l'évaluateur a le
 * DROIT de voir `reponses`, `statut_interne`, `criteres_eliminatoires`… — ce
 * n'est plus une fuite, c'est son travail. Mais le panneau Éligibilité
 * n'affiche QUE ce que le backend a déjà calculé (`statut_eligibilite_interne`,
 * `criteres_eliminatoires[].detail`) : aucune règle d'élimination n'est
 * recalculée ici (contrairement à `fiche-candidat.html` de la maquette, qui
 * appelle `checkCriteresEliminatoires` côté navigateur — on NE reproduit PAS ça).
 *
 * Pas d'onglet Évaluation/Entretien, aucun score affiché (Lot 8c-2).
 */

const TABS = [
  { key: 'profil', label: 'Profil' },
  { key: 'scolarite', label: 'Scolarité' },
  { key: 'socioeco', label: 'Socio-éco' },
  { key: 'experience', label: 'Expérience' },
  { key: 'langues', label: 'Langues' },
  { key: 'motivation', label: 'Motivation' },
  { key: 'disponibilite', label: 'Disponibilité' },
  { key: 'documents', label: 'Documents' },
  { key: 'verification', label: 'Vérification' },
]

const QaRow = ({ q, a }) => (
  <div className="qa-row" style={{ padding: 'var(--space-3) 0', borderBottom: '1px dashed var(--casa-border)' }}>
    <div className="caption">{q}</div>
    <div className="fw-semibold body-sm">{a}</div>
  </div>
)

function humanSize(bytes) {
  if (bytes == null) return ''
  if (bytes < 1024) return `${bytes} o`
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} Ko`
  return `${(bytes / (1024 * 1024)).toFixed(1)} Mo`
}

function PieceLink({ label, piece }) {
  if (!piece) {
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
  return (
    <a
      className="file-row"
      style={{ marginBottom: 'var(--space-3)', textDecoration: 'none' }}
      href={piece.url}
      target="_blank"
      rel="noreferrer"
    >
      <div className="file-icon">
        <i className="fa-solid fa-file-lines" aria-hidden="true" />
      </div>
      <div style={{ flex: 1, minWidth: 0 }}>
        <div className="fw-medium body-sm" style={{ color: 'var(--casa-text-primary)' }}>{label}</div>
        <div className="caption truncate">
          {piece.nom_original} · {humanSize(piece.taille_octets)}
        </div>
      </div>
      <span className="badge badge-success">
        <i className="fa-solid fa-download" aria-hidden="true" /> Télécharger
      </span>
    </a>
  )
}

function EligibilitePanel({ dossier }) {
  const info = ELIGIBILITE_LABELS[dossier.statut_eligibilite_interne] ?? {
    label: dossier.statut_eligibilite_interne,
    badge: 'badge-neutral',
  }
  const criteres = dossier.criteres_eliminatoires || []

  return (
    <div className="card">
      <h3 style={{ marginBottom: 'var(--space-4)' }}>
        <i className="fa-solid fa-shield-halved" style={{ color: 'var(--casa-primary-600)' }} aria-hidden="true" />{' '}
        Éligibilité
      </h3>
      <span className={`badge ${info.badge} badge-lg`} style={{ marginBottom: 'var(--space-4)', display: 'inline-block' }}>
        {info.label}
      </span>

      {dossier.statut_eligibilite_interne === 'non_eligible' ? (
        <Alert variant="danger" title="Critère(s) éliminatoire(s) déclenché(s)">
          <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-2)', marginTop: 'var(--space-2)' }}>
            {criteres.map((c) => (
              <div className="body-sm" key={`${c.code_critere}-${c.declenche_le}`}>
                <strong>{c.code_critere}</strong> — {c.detail}
                <div className="caption" style={{ marginTop: 2 }}>
                  <span className="badge badge-neutral">{ORIGINE_LABELS[c.origine] ?? c.origine}</span>{' '}
                  {formatDateFr(c.declenche_le)}
                </div>
              </div>
            ))}
          </div>
        </Alert>
      ) : dossier.statut_eligibilite_interne === 'eligible' ? (
        <Alert variant="success">Aucun critère éliminatoire détecté.</Alert>
      ) : (
        <Alert variant="info">Éligibilité non encore vérifiée.</Alert>
      )}
    </div>
  )
}

export function FicheCandidat() {
  const { id } = useParams()
  const { status, dossier, saving, verifError, updateVerification } = useFicheCandidat(id)
  const [tab, setTab] = useState('profil')

  if (status === 'loading') return <FullPageSpinner />

  if (status === 'not_found') {
    return (
      <AppShell title="Fiche candidat" space="evaluateur">
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

  if (status === 'error' || !dossier) {
    return (
      <AppShell title="Fiche candidat" space="evaluateur">
        <Alert variant="warning">Le dossier n'a pas pu être chargé. Réessayez plus tard.</Alert>
      </AppShell>
    )
  }

  const r = dossier.reponses || {}
  const statutInfo = STATUT_INTERNE_LABELS[dossier.statut_interne] ?? { label: dossier.statut_interne, badge: 'badge-neutral' }
  const piecesParType = Object.fromEntries((dossier.pieces_dossier || []).map((p) => [p.type_document_code, p]))

  return (
    <AppShell title="Fiche candidat" space="evaluateur">
      <div className="breadcrumbs" style={{ marginBottom: 'var(--space-4)' }}>
        <Link to={paths.evaluateurDossiers}>Mes dossiers</Link>
        <span className="sep">/</span>
        <span>{dossier.candidat?.prenom} {dossier.candidat?.nom}</span>
      </div>

      <div className="card">
        <div className="flex gap-5 items-center" style={{ flexWrap: 'wrap' }}>
          <span className="avatar avatar-xl">
            {(dossier.candidat?.prenom?.[0] || '') + (dossier.candidat?.nom?.[0] || '')}
          </span>
          <div style={{ flex: 1, minWidth: 220 }}>
            <h2 style={{ marginBottom: 4 }}>{dossier.candidat?.prenom} {dossier.candidat?.nom}</h2>
            <div className="flex gap-3 items-center" style={{ flexWrap: 'wrap' }}>
              <span className="caption">{dossier.numero_dossier}</span>
              <span className="caption">·</span>
              <span className="caption">{dossier.filiere?.nom}</span>
              <span className="caption">·</span>
              <span className="caption">{dossier.candidat?.ville_residence}</span>
            </div>
          </div>
          <div className="flex gap-2" style={{ flexWrap: 'wrap' }}>
            <span className={`badge ${statutInfo.badge} badge-lg`}>{statutInfo.label}</span>
            {dossier.dossier_verrouille ? (
              <span className="badge badge-success badge-lg">
                <i className="fa-solid fa-lock" aria-hidden="true" /> Dossier verrouillé
              </span>
            ) : null}
          </div>
        </div>
      </div>

      <div className="fiche-layout">
        <div className="card">
          <div className="tabs" style={{ marginBottom: 'var(--space-6)' }}>
            {TABS.map((t) => (
              <button
                key={t.key}
                type="button"
                className={`tab${tab === t.key ? ' is-active' : ''}`}
                aria-current={tab === t.key ? 'true' : undefined}
                onClick={() => setTab(t.key)}
              >
                {t.label}
              </button>
            ))}
          </div>

          {tab === 'profil' ? (
            <div>
              <QaRow q="Nom complet" a={`${dossier.candidat?.prenom} ${dossier.candidat?.nom}`} />
              <QaRow q="Date de naissance" a={formatDateFr(dossier.candidat?.date_naissance)} />
              <QaRow q="Sexe" a={dossier.candidat?.sexe === 'F' ? 'Féminin' : 'Masculin'} />
              <QaRow q="CNI" a={dossier.candidat?.cni || '—'} />
              <QaRow q="Téléphone" a={dossier.candidat?.telephone || '—'} />
              <QaRow q="Ville de résidence" a={dossier.candidat?.ville_residence || '—'} />
              <QaRow q="Réside en Côte d’Ivoire" a={dossier.candidat?.residence_ci ? 'Oui' : 'Non'} />
              <QaRow q="Filière" a={dossier.filiere?.nom || '—'} />
              <QaRow q="Cohorte" a={dossier.campagne?.nom || '—'} />
              <QaRow q="Date de soumission" a={formatDateFr(dossier.date_soumission)} />
            </div>
          ) : null}

          {tab === 'scolarite' ? (
            <div>
              <QaRow q="Actuellement scolarisé(e) ?" a={ouiNonLabel(r.sc01_scolarise_actuellement)} />
              <QaRow q="Dernière classe fréquentée" a={optionLabel(SC02_CLASSE, r.sc02_derniere_classe)} />
              <QaRow q="Document justifiant le niveau" a={ouiNonLabel(r.sc03_document_justifiant_niveau)} />
              <QaRow q="Bénéficiaire actuel d’une formation" a={ouiNonLabel(r.sc05_beneficiaire_formation_actuelle)} />
              <QaRow q="A déjà bénéficié d’une formation" a={ouiNonLabel(r.sc06_deja_beneficie_formation)} />
              {r.sc07_filiere_suivie ? <QaRow q="Filière suivie" a={r.sc07_filiere_suivie} /> : null}
              <QaRow q="Programme mené à terme ?" a={ouiNonLabel(r.sc08_mene_a_terme)} />
              {r.sc09_motif_non_achevement ? <QaRow q="Motif de non-achèvement" a={r.sc09_motif_non_achevement} /> : null}
            </div>
          ) : null}

          {tab === 'socioeco' ? (
            <div>
              <QaRow q="Vit avec" a={optionLabel(SE01_VIT_AVEC, r.se01_vit_avec)} />
              <QaRow q="Orphelin(e) ?" a={ouiNonLabel(r.se02_orphelin)} />
              <QaRow q="Situation d’emploi" a={optionLabel(SE03_EMPLOI, r.se03_situation_emploi)} />
              <QaRow q="Principale source de revenu" a={optionLabel(SE04_REVENU, r.se04_source_revenu)} />
              <QaRow q="Personnes à charge" a={optionLabel(SE05_CHARGE, r.se05_personnes_a_charge)} />
              <QaRow q="Soutien principal du ménage ?" a={ouiNonLabel(r.se06_soutien_menage)} />
            </div>
          ) : null}

          {tab === 'experience' ? (
            <div>
              {(dossier.experiences || []).length === 0 ? (
                <QaRow q="Expérience professionnelle" a="Aucune expérience déclarée" />
              ) : (
                dossier.experiences.map((e, i) => (
                  <div key={e.id} style={{ marginBottom: 'var(--space-4)' }}>
                    <QaRow
                      q={`Expérience ${i + 1}`}
                      a={`${optionLabel(EXPERIENCE_DOMAINES, e.domaine)} · ${optionLabel(EXPERIENCE_DUREES, e.duree_categorie)}`}
                    />
                    <PieceLink label={`Justificatif — expérience ${i + 1}`} piece={e.justificatif} />
                  </div>
                ))
              )}
            </div>
          ) : null}

          {tab === 'langues' ? (
            <div>
              <QaRow q="Français écrit" a={NIVEAUX[r.langue_ecrit] ?? '—'} />
              <QaRow q="Français parlé" a={NIVEAUX[r.langue_parle] ?? '—'} />
              <QaRow q="Compréhension orale" a={NIVEAUX[r.langue_comprehension] ?? '—'} />
              <QaRow q="Word" a={NIVEAUX[r.info_word] ?? '—'} />
              <QaRow q="Excel" a={NIVEAUX[r.info_excel] ?? '—'} />
              <QaRow q="Internet" a={NIVEAUX[r.info_internet] ?? '—'} />
            </div>
          ) : null}

          {tab === 'motivation' ? (
            <div>
              <div className="qa-row" style={{ padding: 'var(--space-3) 0' }}>
                <div className="caption">Lettre de motivation</div>
                <p className="body-sm" style={{ marginTop: 6, whiteSpace: 'pre-wrap' }}>
                  {r.mo04_lettre_motivation || '—'}
                </p>
              </div>
              {(dossier.classement || []).length ? (
                <QaRow
                  q="Classement des filières (préférence candidat)"
                  a={[...dossier.classement].sort((a, b) => a.rang - b.rang).map((c) => `${c.rang}. ${c.filiere?.nom}`).join(' · ')}
                />
              ) : null}
            </div>
          ) : null}

          {tab === 'disponibilite' ? (
            <div>
              <QaRow q="Disponible lundi-vendredi" a={ouiNonLabel(r.di01_disponible_lun_ven)} />
              <QaRow q="Contraintes déclarées" a={optionLabel(DI02_CONTRAINTES, r.di02_contraintes)} />
              <QaRow q="Engagement complet" a={ouiNonLabel(r.di03_engagement_complet)} />
              <QaRow q="Accès au Plateau" a={ouiNonLabel(r.acces_plateau)} />
              <QaRow q="Accès aux 2 Plateaux Vallons" a={ouiNonLabel(r.acces_deux_plateaux_vallons)} />
            </div>
          ) : null}

          {tab === 'documents' ? (
            <div>
              {PIECES_DOSSIER.map((t) => (
                <PieceLink key={t.code} label={t.label} piece={piecesParType[t.code]} />
              ))}
            </div>
          ) : null}

          {tab === 'verification' ? (
            <VerificationForm
              key={dossier.id}
              dossier={dossier}
              saving={saving}
              verifError={verifError}
              onSave={updateVerification}
            />
          ) : null}
        </div>

        <EligibilitePanel dossier={dossier} />
      </div>
    </AppShell>
  )
}
