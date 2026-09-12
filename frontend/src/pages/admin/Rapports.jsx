import { AppShell } from '../../components/layout/AppShell.jsx'
import { Alert } from '../../components/ui/Alert.jsx'
import { Spinner } from '../../components/ui/Spinner.jsx'
import { BarChart, ColumnChart, DonutChart, MasqueEffectif } from './Charts.jsx'
import { DECISION_LABELS } from './optionLabels.js'
import { useRapports } from './useRapports.js'
import './Rapports.css'

function Kpi({ label, value }) {
  return (
    <div className="card kpi-card">
      <span className="caption">{label}</span>
      <div className="kpi-value">{value}</div>
    </div>
  )
}

function ChartCard({ titre, children }) {
  return (
    <div className="card">
      <h3 style={{ marginBottom: 'var(--space-4)' }}>{titre}</h3>
      {children}
    </div>
  )
}

/**
 * Rapports & statistiques (Lot 11c, ADR-30) — restitution pour le Comité de
 * Pilotage. Tous les chiffres viennent de `GET /admin/rapports` : l'écran ne
 * calcule rien, ne trie rien, ne connaît aucune borne de barème (les tranches
 * de score sont dans le payload). Les graphiques sont du SVG maison.
 *
 * Garde-fou k-anonymat 100 % serveur : une répartition renvoyée `null` s'affiche
 * « effectif insuffisant » ; un taux `null` s'affiche « n/d ». Les exports CSV
 * ET Excel (`.xlsx`, Lot 15c) passent par le même service — le masquage y est
 * identique. PDF reste désactivé « à venir » (pas construit ce lot — le CSV
 * couvre déjà la donnée brute, Excel la présentation exploitable ; un PDF
 * répond à un besoin différent — document figé/officiel — non confirmé).
 */
export function Rapports() {
  const { status, data, campagne, setCampagne, campagnes, telechargerCsv, telechargerXlsx, exporting } = useRapports()

  const seuil = data?.perimetre?.seuil_masquage ?? 5

  const binsScores = () => {
    const d = data.distribution_scores
    if (!d) return null
    return d.bornes.slice(0, -1).map((b, i) => ({ label: `${b}–${d.bornes[i + 1]}`, value: d.effectifs[i] ?? 0 }))
  }

  return (
    <AppShell title="Rapports & statistiques">
      <div className="page-head">
        <div>
          <h2>Rapports &amp; statistiques</h2>
          <p className="text-muted">Restitution destinée au Comité de Pilotage (CoPil) du projet CASA.</p>
        </div>
        <div className="flex gap-2" style={{ flexWrap: 'wrap' }}>
          <select
            className="select"
            aria-label="Campagne"
            style={{ maxWidth: 240 }}
            value={campagne}
            onChange={(e) => setCampagne(e.target.value)}
          >
            <option value="">Campagne courante</option>
            {campagnes.map((c) => (
              <option key={c.id} value={c.id}>{c.nom}</option>
            ))}
            <option value="toutes">Toutes les campagnes</option>
          </select>
          <button type="button" className="btn btn-outline btn-sm" disabled={exporting || status !== 'ready'} onClick={telechargerCsv}>
            <i className="fa-solid fa-file-csv" aria-hidden="true" /> {exporting ? 'Export…' : 'Exporter (CSV)'}
          </button>
          <button type="button" className="btn btn-outline btn-sm" disabled={exporting || status !== 'ready'} onClick={telechargerXlsx}>
            <i className="fa-solid fa-file-excel" aria-hidden="true" /> {exporting ? 'Export…' : 'Excel'}
          </button>
          <button type="button" className="btn btn-outline btn-sm" disabled title="Rapport PDF — à venir">
            <i className="fa-solid fa-file-pdf" aria-hidden="true" /> PDF
          </button>
        </div>
      </div>

      {status === 'loading' ? (
        <div className="text-center" style={{ padding: 'var(--space-16)' }}>
          <Spinner large />
        </div>
      ) : status === 'error' ? (
        <Alert variant="warning">Les rapports n'ont pas pu être chargés. Réessayez plus tard.</Alert>
      ) : (
        <>
          <p className="rapports-note">
            <i className="fa-solid fa-circle-info" aria-hidden="true" />
            {data.perimetre.toutes_campagnes
              ? 'Toutes campagnes confondues.'
              : `Campagne : ${data.perimetre.campagne?.nom ?? '—'}.`}{' '}
            {data.perimetre.candidatures} candidature{data.perimetre.candidatures > 1 ? 's' : ''} soumise{data.perimetre.candidatures > 1 ? 's' : ''}.
            Toute répartition portant sur moins de {seuil} personnes est masquée (protection contre la ré-identification).
          </p>

          <div className="grid grid-4" style={{ margin: 'var(--space-5) 0 var(--space-6)' }}>
            <Kpi label="Candidatures soumises" value={data.kpis.candidatures} />
            <Kpi label="Éligibles" value={data.kpis.eligibles} />
            <Kpi label="Évaluées" value={data.kpis.evaluees} />
            <Kpi label="Retenus" value={data.kpis.retenus} />
          </div>
          <div className="grid grid-4" style={{ marginBottom: 'var(--space-6)' }}>
            <Kpi label="Taux d'éligibilité" value={data.kpis.taux_eligibilite === null ? 'n/d' : `${data.kpis.taux_eligibilite} %`} />
            <Kpi label="Taux de sélection" value={data.kpis.taux_selection === null ? 'n/d' : `${data.kpis.taux_selection} %`} />
          </div>

          <div className="grid grid-2" style={{ marginBottom: 'var(--space-6)' }}>
            <ChartCard titre="Candidatures par filière">
              {data.par_filiere.length === 0 ? (
                <p className="caption">Aucune candidature.</p>
              ) : (
                <BarChart titre="Candidatures par filière" items={data.par_filiere.map((f) => ({ label: f.filiere.nom, value: f.candidatures }))} />
              )}
            </ChartCard>

            <ChartCard titre="Répartition femmes / hommes">
              {data.repartition_sexe ? (
                <DonutChart
                  titre="Répartition femmes / hommes"
                  slices={[
                    { label: 'Femmes', value: data.repartition_sexe.F, tone: 'b' },
                    { label: 'Hommes', value: data.repartition_sexe.H, tone: 'a' },
                  ]}
                />
              ) : (
                <MasqueEffectif seuil={seuil} />
              )}
            </ChartCard>

            <ChartCard titre="Distribution des scores">
              {binsScores() ? (
                <ColumnChart titre="Distribution des scores" bins={binsScores()} />
              ) : (
                <MasqueEffectif seuil={seuil} />
              )}
            </ChartCard>

            <ChartCard titre="Répartition par ville">
              {data.top_villes && data.top_villes.length > 0 ? (
                <BarChart titre="Répartition par ville" items={data.top_villes.map((v) => ({ label: v.ville, value: v.candidatures }))} />
              ) : (
                <MasqueEffectif seuil={seuil} />
              )}
            </ChartCard>

            <ChartCard titre="Répartition des décisions">
              {data.repartition_decisions ? (
                <DonutChart
                  titre="Répartition des décisions"
                  slices={[
                    { label: DECISION_LABELS.retenu.label, value: data.repartition_decisions.retenu, tone: 'a' },
                    { label: DECISION_LABELS.liste_attente.label, value: data.repartition_decisions.liste_attente, tone: 'c' },
                    { label: DECISION_LABELS.non_retenu.label, value: data.repartition_decisions.non_retenu, tone: 'd' },
                    { label: DECISION_LABELS.indisponible.label, value: data.repartition_decisions.indisponible, tone: 'b' },
                  ]}
                />
              ) : (
                <MasqueEffectif seuil={seuil} />
              )}
            </ChartCard>

            <ChartCard titre="Présence à l'entretien">
              {data.presence_entretien ? (
                <DonutChart
                  titre="Présence à l'entretien"
                  slices={[
                    { label: 'Présents', value: data.presence_entretien.present, tone: 'a' },
                    { label: 'Absents', value: data.presence_entretien.absent, tone: 'd' },
                  ]}
                />
              ) : (
                <MasqueEffectif seuil={seuil} />
              )}
            </ChartCard>
          </div>
        </>
      )}
    </AppShell>
  )
}
