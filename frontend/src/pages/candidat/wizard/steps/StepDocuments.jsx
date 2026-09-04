import { useState } from 'react'
import { FieldError } from '../../../../components/form/FieldError.jsx'
import { FileDropRow } from '../../../../components/form/FileDropRow.jsx'
import { ApiError } from '../../../../lib/ApiError.js'
import { PIECES_DOSSIER, UPLOAD } from '../formStructure.js'

function PieceRow({ type, label, form }) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)
  const file = form.pieces[type] ?? null

  const wrap = (fn) => async (...args) => {
    setBusy(true)
    setError(null)
    try {
      await fn(...args)
    } catch (err) {
      setError(err instanceof ApiError ? (err.errors?.fichier?.[0] || err.message) : 'Échec de l’envoi.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <FileDropRow
      label={label}
      hint="Obligatoire"
      file={file}
      busy={busy}
      error={error}
      onPick={wrap((f) => form.uploadPiece(type, f))}
      onRemove={wrap(() => form.removePiece(type))}
    />
  )
}

export function StepDocuments({ form, errors }) {
  return (
    <>
      <h3 style={{ marginBottom: 'var(--space-2)' }}>Pièces justificatives</h3>
      <p className="caption" style={{ marginBottom: 'var(--space-5)' }}>
        Formats acceptés : {UPLOAD.label}. Les 6 pièces sont obligatoires.
      </p>
      {PIECES_DOSSIER.map((p) => (
        <PieceRow key={p.code} type={p.code} label={p.label} form={form} />
      ))}
      <FieldError>{errors.pieces_dossier}</FieldError>
    </>
  )
}
