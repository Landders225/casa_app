import { useId, useRef, useState } from 'react'
import { FieldError } from './FieldError.jsx'
import { UPLOAD } from '../../pages/candidat/wizard/formStructure.js'

function humanSize(bytes) {
  if (bytes < 1024) return `${bytes} o`
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} Ko`
  return `${(bytes / (1024 * 1024)).toFixed(1)} Mo`
}

/**
 * Ligne de dépôt / affichage d'un fichier — réutilise `.file-row` / `.file-icon`
 * du design system. Garde-fous client (type + taille) AVANT l'upload ; l'erreur
 * serveur (422) est affichée sous la ligne.
 *
 * `file` : métadonnées de la pièce déposée ({ nom_original, taille_octets }) ou null.
 * `onPick(File)` : lance l'upload. `onRemove()` : supprime.
 */
export function FileDropRow({ label, hint, file, onPick, onRemove, busy, error }) {
  const inputId = useId()
  const inputRef = useRef(null)
  const [localError, setLocalError] = useState(null)

  const pick = (f) => {
    setLocalError(null)
    if (!f) return
    if (!UPLOAD.mimes.includes(f.type)) {
      setLocalError('Format non autorisé : PDF, JPEG ou PNG uniquement.')
      return
    }
    if (f.size > UPLOAD.maxBytes) {
      setLocalError('Le fichier dépasse la taille maximale de 10 Mo.')
      return
    }
    onPick(f)
  }

  const shown = localError || error

  return (
    <div style={{ marginBottom: 'var(--space-3)' }}>
      <div className={`file-row${file ? '' : ' file-row-empty'}`} style={file ? undefined : { borderStyle: 'dashed' }}>
        <div
          className="file-icon"
          style={file ? undefined : { background: 'var(--casa-bg-alt)', color: 'var(--casa-text-tertiary)' }}
        >
          <i className={`fa-solid ${file ? 'fa-file-lines' : 'fa-file-circle-plus'}`} aria-hidden="true" />
        </div>
        <div style={{ flex: 1, minWidth: 0 }}>
          <div className="fw-medium body-sm">{label}</div>
          <div className="caption truncate">
            {file ? `${file.nom_original} · ${humanSize(file.taille_octets)}` : hint || 'Obligatoire'}
          </div>
        </div>
        {file ? (
          <>
            <span className="badge badge-success" aria-label="Déposé">
              <i className="fa-solid fa-check" aria-hidden="true" />
            </span>
            <button
              type="button"
              className="btn btn-icon btn-ghost"
              aria-label={`Retirer ${label}`}
              disabled={busy}
              onClick={onRemove}
            >
              <i className="fa-solid fa-trash" aria-hidden="true" />
            </button>
          </>
        ) : (
          <button
            type="button"
            className={`btn btn-sm btn-outline${busy ? ' is-loading' : ''}`}
            disabled={busy}
            onClick={() => inputRef.current?.click()}
          >
            Déposer
          </button>
        )}
        <input
          ref={inputRef}
          id={inputId}
          type="file"
          accept={UPLOAD.accept}
          hidden
          onChange={(e) => {
            pick(e.target.files?.[0])
            e.target.value = ''
          }}
        />
      </div>
      <FieldError>{shown}</FieldError>
    </div>
  )
}
