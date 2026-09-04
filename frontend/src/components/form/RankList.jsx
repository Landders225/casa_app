import { useRef, useState } from 'react'

/**
 * Classement par préférence — réutilise `.rank-list` / `.rank-item` du wizard.
 *
 *  - DESKTOP : glisser-déposer HTML5 (dragstart / dragover / drop) ;
 *  - TACTILE : flèches ↑ / ↓ (cible ≥ 44px) — SEUL mécanisme déclenchable au
 *    toucher (le drag & drop HTML5 ne l'est pas).
 *
 * `items` : [{ id, nom }] déjà ordonné (1 = préféré). `onReorder(orderedIds)`.
 */
export function RankList({ items, onReorder }) {
  const [dragId, setDragId] = useState(null)
  const draggingRef = useRef(null)

  const ids = items.map((i) => i.id)

  const move = (from, to) => {
    if (to < 0 || to >= ids.length || from === to) return
    const next = [...ids]
    const [x] = next.splice(from, 1)
    next.splice(to, 0, x)
    onReorder(next)
  }

  const onDrop = (targetId) => {
    const from = ids.indexOf(draggingRef.current)
    const to = ids.indexOf(targetId)
    draggingRef.current = null
    setDragId(null)
    move(from, to)
  }

  return (
    <ol className="rank-list" aria-label="Classement des filières par préférence">
      {items.map((item, i) => (
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
              disabled={i === 0}
              onClick={() => move(i, i - 1)}
            >
              <i className="fa-solid fa-chevron-up" aria-hidden="true" />
            </button>
            <button
              type="button"
              className="btn btn-icon btn-ghost"
              aria-label={`Descendre ${item.nom}`}
              disabled={i === items.length - 1}
              onClick={() => move(i, i + 1)}
            >
              <i className="fa-solid fa-chevron-down" aria-hidden="true" />
            </button>
          </div>
        </li>
      ))}
    </ol>
  )
}
