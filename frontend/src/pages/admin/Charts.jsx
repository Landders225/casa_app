/**
 * Graphiques de l'écran Rapports (Lot 11c) — SVG inline maison, ZÉRO lib
 * (cohérent ADR-23, aucune dépendance CDN à autoriser dans la CSP du Lot 9b).
 *
 * Chaque graphique est un `<svg role="img">` avec un `<title>` et un
 * `aria-label` qui ÉNUMÈRE les valeurs — un lecteur d'écran restitue le contenu
 * sans dépendre du rendu visuel (meilleure accessibilité qu'un `<canvas>`).
 *
 * Aucune borne de barème ici : les libellés viennent des `props` (eux-mêmes
 * issus du payload API), jamais d'une constante de scoring.
 */

function tronquer(texte, max = 18) {
  return texte.length > max ? `${texte.slice(0, max - 1)}…` : texte
}

/**
 * Barres horizontales — candidatures par filière, répartition par ville.
 * `items` : `[{ label, value }]`.
 */
export function BarChart({ titre, items }) {
  const max = Math.max(1, ...items.map((i) => i.value))
  const rowH = 28
  const width = 340
  const labelW = 128
  const trackX = labelW + 6
  const trackW = width - trackX - 26
  const height = items.length * rowH + 8

  return (
    <svg
      viewBox={`0 0 ${width} ${height}`}
      className="chart-svg"
      role="img"
      aria-label={`${titre}. ${items.map((i) => `${i.label} : ${i.value}`).join(' ; ')}`}
    >
      <title>{titre}</title>
      {items.map((item, idx) => {
        const y = idx * rowH + 4
        const barW = Math.max(2, (item.value / max) * trackW)
        return (
          <g key={item.label}>
            <text x="0" y={y + rowH / 2} dominantBaseline="middle" className="chart-label">
              {tronquer(item.label)}
            </text>
            <rect x={trackX} y={y + 5} width={trackW} height={rowH - 12} rx="3" className="chart-track" />
            <rect x={trackX} y={y + 5} width={barW} height={rowH - 12} rx="3" className="chart-bar" />
            <text x={width} y={y + rowH / 2} textAnchor="end" dominantBaseline="middle" className="chart-value">
              {item.value}
            </text>
          </g>
        )
      })}
    </svg>
  )
}

/**
 * Colonnes verticales — distribution des scores par tranche.
 * `bins` : `[{ label, value }]` (libellés « 0–20 » etc. construits par l'appelant
 * à partir de `distribution_scores.bornes`).
 */
export function ColumnChart({ titre, bins }) {
  const max = Math.max(1, ...bins.map((b) => b.value))
  const width = 340
  const height = 190
  const padX = 16
  const padY = 30
  const slot = (width - padX * 2) / bins.length
  const barW = slot * 0.58

  return (
    <svg
      viewBox={`0 0 ${width} ${height}`}
      className="chart-svg"
      role="img"
      aria-label={`${titre}. ${bins.map((b) => `${b.label} : ${b.value}`).join(' ; ')}`}
    >
      <title>{titre}</title>
      <line x1={padX} y1={height - padY} x2={width - padX} y2={height - padY} className="chart-axis" />
      {bins.map((bin, idx) => {
        const cx = padX + (idx + 0.5) * slot
        const h = (bin.value / max) * (height - padY * 2)
        return (
          <g key={bin.label}>
            <rect x={cx - barW / 2} y={height - padY - h} width={barW} height={h} rx="3" className="chart-bar" />
            <text x={cx} y={height - padY - h - 5} textAnchor="middle" className="chart-value">
              {bin.value}
            </text>
            <text x={cx} y={height - padY + 14} textAnchor="middle" className="chart-tick">
              {bin.label}
            </text>
          </g>
        )
      })}
    </svg>
  )
}

/**
 * Anneau — répartition F/H, décisions, présence entretien.
 * `slices` : `[{ label, value, tone }]` où `tone` ∈ {a,b,c,d} (couleur CSS).
 */
export function DonutChart({ titre, slices }) {
  const total = slices.reduce((sum, s) => sum + s.value, 0)
  const cx = 80
  const cy = 80
  const r = 58
  const circ = 2 * Math.PI * r
  let acc = 0

  return (
    <div className="chart-donut">
      <svg
        viewBox="0 0 160 160"
        className="chart-svg"
        role="img"
        aria-label={`${titre}. ${slices.map((s) => `${s.label} : ${s.value}`).join(' ; ')}`}
      >
        <title>{titre}</title>
        <circle cx={cx} cy={cy} r={r} fill="none" strokeWidth="20" className="chart-track" />
        {total > 0 && slices.map((s) => {
          const len = (s.value / total) * circ
          const node = (
            <circle
              key={s.label}
              cx={cx}
              cy={cy}
              r={r}
              fill="none"
              strokeWidth="20"
              className={`chart-slice chart-slice-${s.tone}`}
              strokeDasharray={`${len} ${circ - len}`}
              strokeDashoffset={-acc}
              transform={`rotate(-90 ${cx} ${cy})`}
            />
          )
          acc += len
          return node
        })}
        <text x={cx} y={cy} textAnchor="middle" dominantBaseline="middle" className="chart-donut-total">
          {total}
        </text>
      </svg>
      <ul className="chart-legend">
        {slices.map((s) => (
          <li key={s.label}>
            <span className={`chart-legend-dot chart-slice-${s.tone}`} aria-hidden="true" />
            {s.label} — {s.value}
            {total > 0 ? ` (${Math.round((s.value / total) * 100)} %)` : ''}
          </li>
        ))}
      </ul>
    </div>
  )
}

/** Encart affiché à la place d'un graphique masqué par le garde-fou k-anonymat. */
export function MasqueEffectif({ seuil }) {
  return (
    <p className="chart-masque">
      <i className="fa-solid fa-shield-halved" aria-hidden="true" />{' '}
      Effectif insuffisant pour publier cette répartition sans risque de ré-identification
      (moins de {seuil} personnes).
    </p>
  )
}
