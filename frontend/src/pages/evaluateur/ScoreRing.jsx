/**
 * Anneau de score partagé (notation dossier /65 et entretien /35, Lot 8c-2) —
 * fidèle au design de la maquette (`evaluation.html`/`entretien.html`).
 *
 * TOUTES les valeurs (`total`, `max`, `rubriques[].score_obtenu`/`.max`) sont
 * fournies par l'appelant depuis la réponse API — aperçu recalculé serveur ou
 * snapshot figé. Le seul calcul fait ICI est un ratio D'AFFICHAGE (angle de
 * l'anneau SVG, largeur en % d'une mini-barre) : jamais une note, jamais un
 * total — juste la mise en forme visuelle d'un nombre déjà connu.
 */
export function ScoreRing({ title, total, max, accent = 'var(--casa-primary-600)', rubriques }) {
  const totalNum = Number(total) || 0
  const maxNum = Number(max) || 0
  const circumference = 2 * Math.PI * 62
  const ratio = maxNum > 0 ? Math.min(totalNum, maxNum) / maxNum : 0
  const offset = circumference * (1 - ratio)

  return (
    <div className="card">
      <h3 className="text-center">{title}</h3>
      <div className="score-gauge-wrap">
        <div className="score-ring">
          <svg width="148" height="148">
            <circle cx="74" cy="74" r="62" fill="none" stroke="var(--casa-bg-alt)" strokeWidth="12" />
            <circle
              cx="74"
              cy="74"
              r="62"
              fill="none"
              stroke={accent}
              strokeWidth="12"
              strokeLinecap="round"
              strokeDasharray={circumference}
              strokeDashoffset={offset}
            />
          </svg>
          <div className="val">
            <span className="kpi-value" style={{ fontSize: '2rem' }}>{totalNum.toFixed(1)}</span>
            <span className="caption">/ {maxNum}</span>
          </div>
        </div>
      </div>
      {(rubriques || []).map((r) => {
        const rMax = Number(r.max) || 0
        const rScore = Number(r.score_obtenu) || 0
        const width = rMax > 0 ? Math.min(100, (rScore / rMax) * 100) : 0
        return (
          <div className="mini-bar-row" key={r.code}>
            <span>{r.label}</span>
            <div className="mini-bar-track">
              <div className="mini-bar-fill" style={{ width: `${width}%` }} />
            </div>
            <span>{rScore.toFixed(1)}/{rMax}</span>
          </div>
        )
      })}
    </div>
  )
}
