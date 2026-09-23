import { useRef, useState } from 'react'

/**
 * Classement par préférence — réutilise `.rank-list` / `.rank-item` du wizard.
 *
 *  - DESKTOP : glisser-déposer HTML5 (dragstart / dragover / drop) ;
 *  - TACTILE : flèches ↑ / ↓ (cible ≥ 44px) — SEUL mécanisme déclenchable au
 *    toucher (le drag & drop HTML5 ne l'est pas).
 *
 * `items` : [{ id, nom }] déjà ordonné (1 = préféré). `onReorder(orderedIds)`.
 *
 * `lockedId` (Lot C) — id d'un item FIGÉ (la filière déjà confirmée par le
 * candidat) : ni poignée, ni flèches, ni cible de dépôt ; les autres items
 * restent classables normalement AUTOUR de lui. Le point délicat n'est PAS
 * visuel : un item voisin qui se déplace ne doit JAMAIS pousser l'item figé
 * hors de sa position (un `splice` naïf sur le tableau complet le ferait).
 * La correction : tout le réordonnancement (drag ET flèches) opère sur le
 * SOUS-TABLEAU des items classables uniquement — l'item figé est retiré
 * avant, puis réinséré à son index d'origine (`lockedIndex`, appelant
 * `StepMotivation` avec la filière confirmée déjà forcée en tête, Lot C —
 * donc `lockedIndex` vaut 0 en pratique, mais le mécanisme reste correct
 * quel que soit son index). Sans `lockedId`, comportement 100% inchangé.
 */
export function RankList({ items, onReorder, lockedId }) {
  const [dragId, setDragId] = useState(null)
  const draggingRef = useRef(null)

  const lockedIndex = lockedId != null ? items.findIndex((i) => i.id === lockedId) : -1
  const movable = lockedIndex === -1 ? items : items.filter((i) => i.id !== lockedId)

  /** Reconstruit le tableau complet des 5 ids à partir du sous-tableau réordonné. */
  const rebuild = (nextMovable) => {
    if (lockedIndex === -1) return nextMovable.map((i) => i.id)
    const full = [...nextMovable]
    full.splice(lockedIndex, 0, items[lockedIndex])
    return full.map((i) => i.id)
  }

  const moveMovable = (from, to) => {
    if (to < 0 || to >= movable.length || from === to) return
    const next = [...movable]
    const [x] = next.splice(from, 1)
    next.splice(to, 0, x)
    onReorder(rebuild(next))
  }

  const onDrop = (targetId) => {
    const draggedId = draggingRef.current
    draggingRef.current = null
    setDragId(null)
    if (draggedId == null || draggedId === lockedId || targetId === lockedId) return // pas de cible valide
    const from = movable.findIndex((i) => i.id === draggedId)
    const to = movable.findIndex((i) => i.id === targetId)
    if (from === -1 || to === -1) return
    moveMovable(from, to)
  }

  return (
    <ol className="rank-list" aria-label="Classement des filières par préférence">
      {items.map((item, i) => {
        if (item.id === lockedId) {
          return (
            <li
              key={item.id}
              className="rank-item is-locked"
              draggable={false}
              aria-label={`${item.nom} — votre choix principal, déjà confirmé, position non modifiable`}
            >
              <i className="fa-solid fa-lock" aria-hidden="true" style={{ color: 'var(--casa-text-tertiary)' }} />
              <span className="body-sm fw-medium">{item.nom}</span>
              <span className="badge badge-success" aria-hidden="true">Votre choix principal</span>
            </li>
          )
        }

        const mi = movable.findIndex((m) => m.id === item.id)
        return (
          <li
            key={item.id}
            className={`rank-item${dragId === item.id ? ' is-dragging' : ''}`}
            draggable
            onDragStart={() => {
              draggingRef.current = item.id
              setDragId(item.id)
            }}
            onDragEnd={() => {
              draggingRef.current = null
              setDragId(null)
            }}
            onDragOver={(e) => e.preventDefault()}
            onDrop={() => onDrop(item.id)}
          >
            <i className="fa-solid fa-grip-vertical" aria-hidden="true" style={{ color: 'var(--casa-text-tertiary)' }} />
            <span className="rank-num">{i + 1}</span>
            <span className="body-sm fw-medium">{item.nom}</span>
            <div className="rank-actions">
              <button
                type="button"
                className="btn btn-icon btn-ghost"
                aria-label={`Monter ${item.nom}`}
                disabled={mi === 0}
                onClick={() => moveMovable(mi, mi - 1)}
              >
                <i className="fa-solid fa-chevron-up" aria-hidden="true" />
              </button>
              <button
                type="button"
                className="btn btn-icon btn-ghost"
                aria-label={`Descendre ${item.nom}`}
                disabled={mi === movable.length - 1}
                onClick={() => moveMovable(mi, mi + 1)}
              >
                <i className="fa-solid fa-chevron-down" aria-hidden="true" />
              </button>
            </div>
          </li>
        )
      })}
    </ol>
  )
}
