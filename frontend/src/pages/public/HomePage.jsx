import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { PublicFooter } from '../../components/public/PublicFooter.jsx'
import { PublicHeader } from '../../components/public/PublicHeader.jsx'
import { FiliereCard } from '../../components/public/FiliereCard.jsx'
import { Alert } from '../../components/ui/Alert.jsx'
import { Spinner } from '../../components/ui/Spinner.jsx'
import { useScrollReveal } from '../../hooks/useScrollReveal.js'
import { apiClient } from '../../lib/apiClient.js'
import { paths } from '../../routing/routes.js'
import './HomePage.css'

/* -------------------------------------------------------------------------
   Contenu éditorial — repris de App_maquette/index.html.
   ⚠️ NE JAMAIS écrire ici la GRILLE DE NOTATION : ni points, ni pondérations,
   ni sous-critères, ni maxima de volet, ni score total, ni méthode de calcul
   (cf. ADR-02, ADR-18). Les CRITÈRES D'ÉLIMINATION (ci-dessous) sont publics
   et distincts.
------------------------------------------------------------------------- */

const HOW_STEPS = [
  { n: 1, title: 'Créer son compte', desc: 'Renseignez votre état civil et vos identifiants.' },
  { n: 2, title: 'Vérifier les conditions', desc: 'Relisez les critères ci-dessous avant de commencer.' },
  { n: 3, title: 'Remplir son dossier', desc: 'Formulaire guidé en plusieurs étapes.' },
  { n: 4, title: 'Ajouter les justificatifs', desc: "Pièce d'identité, diplôme, CV, lettre de motivation…" },
  { n: 5, title: 'Soumettre la candidature', desc: 'Récapitulatif et certification sur l\'honneur.' },
  { n: 6, title: 'Évaluation', desc: 'Votre dossier est instruit par l\'équipe projet.' },
  { n: 7, title: 'Entretien', desc: "Échange avec l'équipe projet." },
  { n: 8, title: 'Sélection finale', desc: 'Classement par filière et décision.' },
  { n: 9, title: 'Notification du résultat', desc: 'Communiqué dans votre espace candidat.' },
]

// Strictement dérivés des contrôles réels de scoring.js (checkCriteresEliminatoires
// / checkCriteresEliminatoiresEvaluateur), reformulés de façon informative.
const CRITERES_ELIMINATOIRES = [
  { icon: 'fa-cake-candles', label: 'Avoir entre 18 et 30 ans' },
  { icon: 'fa-school', label: "Ne pas être scolarisé(e) actuellement" },
  { icon: 'fa-graduation-cap', label: "Avoir suivi sa scolarité au moins jusqu'à la classe de 3ème" },
  { icon: 'fa-book-open-reader', label: "Ne pas déjà bénéficier d'un autre programme de formation financé" },
  { icon: 'fa-briefcase', label: 'Être sans emploi (ni temps partiel, ni temps plein)' },
  { icon: 'fa-comments', label: 'Avoir un niveau de français au moins élémentaire' },
  { icon: 'fa-map-location-dot', label: 'Pouvoir se rendre au Plateau ou aux 2 Plateaux Vallons (un seul des deux suffit)' },
  { icon: 'fa-calendar-check', label: 'Être disponible du lundi au vendredi pendant toute la formation' },
  { icon: 'fa-handshake', label: "S'engager à suivre l'intégralité du programme" },
  { icon: 'fa-id-card', label: "Nationalité ivoirienne confirmée par la pièce d'identité déposée", evaluateur: true },
  { icon: 'fa-award', label: 'Diplôme le plus élevé confirmé supérieur au CEPE', evaluateur: true },
]

const ELIGIBILITE = [
  { title: '18 à 30 ans', desc: 'Âge calculé automatiquement à partir de votre date de naissance.' },
  { title: 'Nationalité ivoirienne', desc: 'Vérifiée via la pièce d\'identité déposée.' },
  { title: "Résider en Côte d'Ivoire", desc: null },
  { title: 'Sans emploi, non scolarisé(e), hors formation', desc: 'Au moment du dépôt de la candidature.' },
]

const FAQ = [
  {
    q: 'Qui peut candidater au projet CASA ?',
    a: "Tout jeune ivoirien(ne) âgé(e) de 18 à 30 ans, résidant en Côte d'Ivoire, sans emploi, non scolarisé(e) et ne suivant aucune formation au moment de la candidature.",
  },
  {
    q: 'Combien de temps dure la formation ?',
    a: "Le dispositif inclut une formation certifiante CQP ainsi qu'un stage pratique de 90 jours en entreprise, complétés par une initiation à l'italien.",
  },
  {
    q: 'Puis-je candidater à plusieurs filières ?',
    a: 'Vous choisissez une filière principale et pouvez indiquer un ordre de préférence parmi les 5 CQP proposés lors de la rubrique « Motivation » du formulaire.',
  },
  {
    q: "Comment suis-je informé(e) de l'avancement de mon dossier ?",
    a: "Vous suivez l'avancement de votre candidature en temps réel depuis votre espace candidat, à chaque changement de statut.",
  },
  {
    // Q3 : adjectif de pondération et score total retirés. Le message de
    // confidentialité est conservé — il renforce la légitimité de la sélection.
    q: 'Comment est évalué mon dossier ?',
    a: "Votre dossier puis votre entretien sont évalués par l'équipe projet selon une grille de notation interne établie par le programme. Le détail de cette grille est un document interne, réservé à l'équipe d'évaluation.",
  },
  {
    // Q2 : politique d'équité assumée, formulée SANS mécanique numérotée.
    q: "Que se passe-t-il en cas d'égalité de score ?",
    a: "En cas d'égalité de score, la priorité est donnée à la mixité, à la vulnérabilité socio-économique, à l'expérience du secteur de l'hôtellerie-restauration et à la motivation démontrée.",
  },
]

function useFilieres() {
  const [state, setState] = useState({ status: 'loading', data: [], error: null })

  useEffect(() => {
    let alive = true
    apiClient
      .get('/filieres')
      .then((res) => {
        if (alive) setState({ status: 'ready', data: res.data ?? [], error: null })
      })
      .catch((err) => {
        if (alive) setState({ status: 'error', data: [], error: err })
      })
    return () => {
      alive = false
    }
  }, [])

  return state
}

function Faq() {
  const [open, setOpen] = useState(null)
  return (
    <div id="faqList">
      {FAQ.map((item, i) => (
        <div className={`faq-item${open === i ? ' is-open' : ''}`} key={item.q}>
          <button
            type="button"
            className="faq-trigger"
            aria-expanded={open === i}
            onClick={() => setOpen(open === i ? null : i)}
          >
            {item.q} <i className="fa-solid fa-plus" aria-hidden="true" />
          </button>
          <div className="faq-panel" hidden={open !== i}>
            <div className="faq-panel-inner">
              <p>{item.a}</p>
            </div>
          </div>
        </div>
      ))}
    </div>
  )
}

export function HomePage() {
  const revealRef = useScrollReveal()
  const filieres = useFilieres()
  const [ack, setAck] = useState(false)

  return (
    <>
      <PublicHeader landing />
      <main ref={revealRef}>
        {/* HERO */}
        <section className="hero bg-gradient-hero">
          <span className="blob blob-1" />
          <span className="blob blob-2" />
          <div className="container">
            <div className="hero-grid">
              <div data-reveal>
                <span className="eyebrow">
                  <i className="fa-solid fa-star" aria-hidden="true" /> Projet CASA — CCI-CI · FADV · AICS
                </span>
                <h1 className="display" style={{ marginTop: 'var(--space-4)' }}>
                  Votre avenir <br />commence ici.
                </h1>
                <p className="body-lg" style={{ maxWidth: 520, marginTop: 'var(--space-5)' }}>
                  CASA forme et insère 240 jeunes ivoiriens dans les métiers de l'hôtellerie et de la
                  restauration : formation certifiante (CQP), stage pratique de 90 jours en entreprise et
                  initiation à l'italien au Centre d'Étude des Langues de la CCI-CI.
                </p>
                <div className="flex gap-3" style={{ marginTop: 'var(--space-8)', flexWrap: 'wrap' }}>
                  <Link to={paths.inscription} className="btn btn-primary btn-lg">
                    Candidater maintenant <i className="fa-solid fa-arrow-right" aria-hidden="true" />
                  </Link>
                  <a href="#programme" className="btn btn-outline btn-lg">Découvrir le programme</a>
                </div>
                <div className="hero-stats">
                  {[
                    ['240', 'jeunes accompagnés'],
                    ['2', 'cohortes de 120'],
                    ['90j', 'de stage pratique'],
                    ['5', 'filières CQP'],
                  ].map(([v, l]) => (
                    <div className="hero-stat" key={l}>
                      <div className="kpi-value">{v}</div>
                      <div className="caption">{l}</div>
                    </div>
                  ))}
                </div>
              </div>
              <div className="hero-visual" data-reveal="scale">
                <div className="hv-card">
                  <div className="hv-photo">
                    <i className="fa-solid fa-bell-concierge" aria-hidden="true" />
                    <span className="badge badge-lg" style={{ background: 'rgba(255,255,255,0.18)', color: '#fff' }}>
                      Cohorte 1 · Ouverte
                    </span>
                  </div>
                  <div style={{ marginTop: 'var(--space-5)' }}>
                    <div className="flex justify-between items-center" style={{ marginBottom: 'var(--space-2)' }}>
                      <span className="body-sm fw-semibold">Une sélection transparente</span>
                      <span className="caption">De la candidature au résultat</span>
                    </div>
                    <div className="progress"><div className="progress-bar" style={{ width: '64%' }} /></div>
                  </div>
                </div>
                <div className="hv-badge-float b1">
                  <span className="avatar" style={{ background: 'var(--casa-accent-500)', color: 'var(--casa-primary-900)' }}>
                    <i className="fa-solid fa-graduation-cap" aria-hidden="true" />
                  </span>
                  <div>
                    <div className="fw-bold body-sm">5 filières CQP</div>
                    <div className="caption">Hôtellerie-restauration</div>
                  </div>
                </div>
                <div className="hv-badge-float b2">
                  <span className="avatar avatar-sm" style={{ background: 'var(--casa-success-600)' }}>
                    <i className="fa-solid fa-check" aria-hidden="true" />
                  </span>
                  <div>
                    <div className="fw-bold body-sm">Suivi transparent</div>
                    <div className="caption">De la candidature au résultat</div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </section>

        {/* BANDE STAT */}
        <section className="stat-strip">
          <div className="container grid grid-4">
            <div><div className="kpi-value">240</div><div className="caption">jeunes formés et insérés</div></div>
            <div><div className="kpi-value">2</div><div className="caption">cohortes de 120 bénéficiaires</div></div>
            <div><div className="kpi-value">90j</div><div className="caption">de stage en entreprise</div></div>
            <div><div className="kpi-value">100%</div><div className="caption">dossiers suivis en ligne</div></div>
          </div>
        </section>

        {/* LE PROGRAMME */}
        <section className="section" id="programme">
          <div className="container">
            <div className="grid grid-2" style={{ alignItems: 'center', gap: 'var(--space-16)' }}>
              <div data-reveal="left">
                <span className="eyebrow">
                  <i className="fa-solid fa-book-open" aria-hidden="true" /> Le programme CASA
                </span>
                <h2 style={{ marginTop: 'var(--space-4)' }}>Coopération au Service de l'Apprentissage</h2>
                <p style={{ marginTop: 'var(--space-4)' }}>
                  Porté par la <strong className="text-primary-brand">Chambre de Commerce et d'Industrie de Côte
                  d'Ivoire (CCI-CI)</strong> en partenariat avec la <strong className="text-primary-brand">Fondation
                  Arbre de Vie Côte d'Ivoire (FADV)</strong>, et cofinancé par l'<strong className="text-primary-brand">Agence
                  Italienne pour la Coopération au Développement (AICS)</strong>, le dispositif CASA forme et insère
                  des jeunes de 18 à 30 ans dans les métiers de l'hôtellerie et de la restauration.
                </p>
                <ul style={{ marginTop: 'var(--space-6)', display: 'flex', flexDirection: 'column', gap: 'var(--space-3)' }}>
                  {[
                    'Formation certifiante CQP reconnue par le METFPA',
                    'Stage pratique de 90 jours en entreprise',
                    "Initiation à l'italien au Centre d'Étude des Langues de la CCI-CI",
                    'Sélection équitable, transparente et tracée de bout en bout',
                  ].map((t) => (
                    <li className="flex items-center gap-3" key={t}>
                      <i className="fa-solid fa-circle-check text-primary-brand" aria-hidden="true" /> {t}
                    </li>
                  ))}
                </ul>
              </div>
              <div className="card" style={{ padding: 'var(--space-8)' }} data-reveal="right">
                <h3 style={{ marginBottom: 'var(--space-5)' }}>Le processus de sélection en un coup d'œil</h3>
                <div className="timeline">
                  <div className="timeline-item is-done">
                    <span className="timeline-dot" />
                    <div className="timeline-title">Vérification des critères d'éligibilité</div>
                    <p className="caption">Âge, nationalité, résidence, situation</p>
                  </div>
                  <div className="timeline-item is-done">
                    <span className="timeline-dot" />
                    <div className="timeline-title">Évaluation du dossier</div>
                    <p className="caption">Grille de notation interne, appliquée par l'équipe d'évaluation</p>
                  </div>
                  <div className="timeline-item is-done">
                    <span className="timeline-dot" />
                    <div className="timeline-title">Entretien</div>
                    <p className="caption">Échange avec l'équipe projet</p>
                  </div>
                  <div className="timeline-item is-active">
                    <span className="timeline-dot" />
                    <div className="timeline-title">Classement par filière</div>
                    <p className="caption">En cas d'égalité de score, des critères de priorité s'appliquent</p>
                  </div>
                  <div className="timeline-item is-pending">
                    <span className="timeline-dot" />
                    <div className="timeline-title">Publication des résultats</div>
                    <p className="caption">Retenu, liste d'attente ou non retenu</p>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </section>

        {/* FILIERES */}
        <section className="section section-band" id="filieres">
          <div className="container">
            <div className="text-center" data-reveal style={{ maxWidth: 640, margin: '0 auto var(--space-12)' }}>
              <span className="eyebrow">
                <i className="fa-solid fa-layer-group" aria-hidden="true" /> 5 filières prioritaires
              </span>
              <h2 style={{ marginTop: 'var(--space-4)' }}>Choisissez votre métier de demain</h2>
              <p style={{ marginTop: 'var(--space-3)' }}>
                Cinq Certificats de Qualification Professionnelle, conçus avec les professionnels du secteur.
              </p>
            </div>

            {filieres.status === 'loading' ? (
              <div className="text-center"><Spinner large /></div>
            ) : filieres.status === 'error' ? (
              <div style={{ maxWidth: 520, margin: '0 auto' }}>
                <Alert variant="warning">
                  La liste des filières n'a pas pu être chargée. Réessayez plus tard.
                </Alert>
              </div>
            ) : (
              <div className="grid grid-5">
                {filieres.data.map((f) => (
                  <FiliereCard key={f.code} filiere={f} />
                ))}
              </div>
            )}
          </div>
        </section>

        {/* AVANT DE CANDIDATER */}
        <section className="section" id="comment">
          <div className="container">
            <div className="text-center" data-reveal style={{ maxWidth: 640, margin: '0 auto' }}>
              <span className="eyebrow">
                <i className="fa-solid fa-route" aria-hidden="true" /> Avant de candidater
              </span>
              <h2 style={{ marginTop: 'var(--space-4)' }}>Comment candidater ?</h2>
              <p style={{ marginTop: 'var(--space-3)' }}>
                Un parcours guidé en 9 étapes, du compte à la notification du résultat.
              </p>
            </div>
            <div className="how-steps how-steps-9">
              {HOW_STEPS.map((s) => (
                <div className="how-step" data-reveal="scale" key={s.n}>
                  <div className="how-num">{s.n}</div>
                  <h4>{s.title}</h4>
                  <p className="caption">{s.desc}</p>
                </div>
              ))}
            </div>

            <div className="text-center" data-reveal style={{ maxWidth: 640, margin: 'var(--space-16) auto var(--space-10)' }}>
              <span className="eyebrow">
                <i className="fa-solid fa-shield-halved" aria-hidden="true" /> À vérifier avant de commencer
              </span>
              <h2 style={{ marginTop: 'var(--space-4)' }}>Critères pouvant entraîner une élimination</h2>
              <p style={{ marginTop: 'var(--space-3)' }}>
                Ces points sont contrôlés automatiquement à la soumission de votre dossier, ou lors de la
                vérification de vos pièces justificatives.
              </p>
            </div>
            <div className="grid grid-3" style={{ marginBottom: 'var(--space-12)' }}>
              {CRITERES_ELIMINATOIRES.map((c) => (
                <div className={`elim-card${c.evaluateur ? ' is-evaluateur' : ''}`} data-reveal="scale" key={c.label}>
                  <span className="elim-ic">
                    <i className={`fa-solid ${c.icon}`} aria-hidden="true" />
                  </span>
                  <div>
                    <span className="body-sm fw-medium">{c.label}</span>
                    {c.evaluateur ? (
                      <div className="caption" style={{ marginTop: 2 }}>Vérifié lors de l'instruction du dossier</div>
                    ) : null}
                  </div>
                </div>
              ))}
            </div>

            <div className="card" style={{ maxWidth: 640, margin: '0 auto', padding: 'var(--space-8)', textAlign: 'center' }} data-reveal>
              <label className="check-row" style={{ justifyContent: 'center', marginBottom: 'var(--space-6)' }}>
                <input type="checkbox" checked={ack} onChange={(e) => setAck(e.target.checked)} />
                <span className="body-sm">J'ai pris connaissance des conditions ci-dessus.</span>
              </label>
              {ack ? (
                <Link to={paths.inscription} className="btn btn-primary btn-lg">
                  Candidater <i className="fa-solid fa-arrow-right" aria-hidden="true" />
                </Link>
              ) : (
                <button type="button" className="btn btn-primary btn-lg" disabled>
                  Candidater <i className="fa-solid fa-arrow-right" aria-hidden="true" />
                </button>
              )}
            </div>
          </div>
        </section>

        {/* ELIGIBILITE */}
        <section className="section section-band">
          <div className="container">
            <div style={{ maxWidth: 640, margin: '0 auto', textAlign: 'center' }} data-reveal>
              <span className="eyebrow">
                <i className="fa-solid fa-clipboard-check" aria-hidden="true" /> Conditions d'éligibilité
              </span>
              <h2 style={{ marginTop: 'var(--space-4)' }}>Vérifiez que vous remplissez les critères</h2>
              <p style={{ marginTop: 'var(--space-3)', marginBottom: 'var(--space-6)' }}>
                Ces critères sont vérifiés automatiquement dès votre inscription sur la plateforme.
              </p>
            </div>
            <div style={{ maxWidth: 640, margin: '0 auto' }} data-reveal>
              {ELIGIBILITE.map((e) => (
                <div className="elig-item" key={e.title}>
                  <span className="check-ic">
                    <i className="fa-solid fa-check" aria-hidden="true" />
                  </span>
                  <div>
                    <div className="fw-semibold">{e.title}</div>
                    {e.desc ? <div className="caption">{e.desc}</div> : null}
                  </div>
                </div>
              ))}
            </div>
          </div>
        </section>

        {/* FAQ */}
        <section className="section" id="faq">
          <div className="container" style={{ maxWidth: 800 }}>
            <div className="text-center" data-reveal style={{ marginBottom: 'var(--space-10)' }}>
              <span className="eyebrow">
                <i className="fa-solid fa-circle-question" aria-hidden="true" /> Questions fréquentes
              </span>
              <h2 style={{ marginTop: 'var(--space-4)' }}>Tout savoir sur la candidature</h2>
            </div>
            <Faq />
          </div>
        </section>

        {/* CTA + CONTACT */}
        <section className="section" id="contact">
          <div className="container">
            <div className="cta-band" data-reveal>
              <span
                className="blob"
                style={{ width: 320, height: 320, background: 'var(--casa-accent-500)', top: -100, right: -60, opacity: 0.25 }}
              />
              <h2 style={{ color: '#fff' }}>Prêt(e) à construire votre avenir ?</h2>
              <p style={{ color: 'var(--casa-primary-100)', maxWidth: 480, margin: 'var(--space-4) auto var(--space-8)' }}>
                Rejoignez la prochaine cohorte CASA et lancez votre carrière dans l'hôtellerie-restauration.
              </p>
              <div className="flex gap-3" style={{ justifyContent: 'center', flexWrap: 'wrap' }}>
                <Link to={paths.inscription} className="btn btn-accent btn-lg">Déposer ma candidature</Link>
                <Link to={paths.login} className="btn btn-lg" style={{ background: 'rgba(255,255,255,0.12)', color: '#fff' }}>
                  J'ai déjà un compte
                </Link>
              </div>
            </div>
          </div>
        </section>
      </main>
      <PublicFooter />
    </>
  )
}
