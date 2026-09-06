import { useState } from 'react'
import { RadioCardList } from '../../components/form/RadioCardList.jsx'
import { SegmentedRadio } from '../../components/form/SegmentedRadio.jsx'
import { Alert } from '../../components/ui/Alert.jsx'
import { SC04_DIPLOME } from './optionLabels.js'
import { StarPicker } from './StarPicker.jsx'

const NAT_OPTIONS = [
  { value: 'oui', label: 'Oui' },
  { value: 'non', label: 'Non' },
]
const natToValue = (b) => (b === true ? 'oui' : b === false ? 'non' : null)
const valueToNat = (v) => (v === 'oui' ? true : v === 'non' ? false : null)

/**
 * Correction exceptionnelle — volet dossier (Lot 8d-3) —
 * `POST .../correction/dossier`. SEULE opération qui rouvre une évaluation
 * verrouillée (ADR-04).
 *
 * PORTÉE CIBLÉE, assumée et documentée (Étape 1, Q1) : le backend accepte
 * n'importe quel champ de `reponses.*` (tout `ChampsFormulaire` — auto-
 * déclarations candidat SC/SE/DI/langues/expériences comprises), mais cette
 * UI ne couvre QUE les champs *vérifiés/notés par l'équipe* — nationalité,
 * SC.04 (diplôme), MO.04 (étoiles) et le commentaire qualitatif. La
 * correction des auto-déclarations candidat reste un POINT OUVERT explicite
 * (cf. docs/ADR.md, README) : le backend la supporte déjà, l'UI ne la
 * construit pas encore ici.
 */
export function CorrectionDossierModal({ dossier, evaluation, saving, error, onCancel, onSave }) {
  const [nat, setNat] = useState(natToValue(dossier.verification?.nationalite_confirmee ?? null))
  const [diplome, setDiplome] = useState(dossier.verification?.diplome_verifie ?? null)
  const [etoiles, setEtoiles] = useState(evaluation.mo04_note_etoiles)
  const [commentaire, setCommentaire] = useState(evaluation.commentaire_evaluateur || '')
  const [motif, setMotif] = useState('')
  const pret = motif.trim().length >= 3

  const submit = () => {
    onSave({
      motif: motif.trim(),
      reponses: { mo04_note_etoiles: etoiles },
      verification: { nationalite_confirmee: valueToNat(nat), diplome_verifie: diplome },
      commentaire_evaluateur: commentaire,
    })
  }

  return (
    <div className="modal-overlay" role="dialog" aria-modal="true" aria-label="Correction exceptionnelle — dossier">
      <div className="modal" style={{ maxWidth: 640 }}>
        <div className="modal-header">
          <h3>Correction exceptionnelle — dossier</h3>
        </div>
        <div className="modal-body">
          {error ? (
            <div style={{ marginBottom: 'var(--space-4)' }}>
              <Alert variant="danger">{error}</Alert>
            </div>
          ) : null}
          <Alert variant="warning" title="Vous rouvrez une évaluation validée">
            Un nouveau score sera calculé côté serveur et remplacera l'ancien snapshot ; l'ancien reste tracé dans le
            journal d'audit. Possible seulement si la campagne n'est pas encore publiée.
          </Alert>

          <div style={{ marginTop: 'var(--space-4)' }}>
            <SegmentedRadio legend="Nationalité ivoirienne confirmée (CNI)" name="nat-correction" options={NAT_OPTIONS} value={nat} onChange={setNat} />
            <RadioCardList
              legend={SC04_DIPLOME.label}
              name="diplome-correction"
              options={SC04_DIPLOME.options}
              value={diplome}
              onChange={setDiplome}
              hint={SC04_DIPLOME.hint}
            />
            <div className="form-group">
              <label className="label">Notation qualitative de la motivation (0 à 5)</label>
              <StarPicker value={etoiles} onChange={setEtoiles} />
            </div>
            <div className="form-group" style={{ marginBottom: 0 }}>
              <label className="label">Commentaire qualitatif</label>
              <textarea className="textarea" rows={3} value={commentaire} onChange={(e) => setCommentaire(e.target.value)} maxLength={5000} />
            </div>
          </div>

          <div className="form-group" style={{ marginTop: 'var(--space-4)', marginBottom: 0 }}>
            <label className="label" htmlFor="motif-correction-dossier">Motif de la correction (obligatoire)</label>
            <textarea
              id="motif-correction-dossier"
              className="textarea"
              rows={3}
              value={motif}
              onChange={(e) => setMotif(e.target.value)}
              placeholder="Ex. le diplôme réexaminé montre en réalité un BEPC..."
              maxLength={2000}
            />
          </div>
        </div>
        <div className="modal-footer">
          <button type="button" className="btn btn-outline" onClick={onCancel} disabled={saving}>
            Annuler
          </button>
          <button type="button" className="btn btn-danger" disabled={!pret || saving} onClick={submit}>
            {saving ? 'Correction…' : 'Enregistrer la correction'}
          </button>
        </div>
      </div>
    </div>
  )
}
