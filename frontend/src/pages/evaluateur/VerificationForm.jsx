import { useState } from 'react'
import { RadioCardList } from '../../components/form/RadioCardList.jsx'
import { SegmentedRadio } from '../../components/form/SegmentedRadio.jsx'
import { Alert } from '../../components/ui/Alert.jsx'
import { formatDateFr } from '../../lib/formatDate.js'
import { SC04_DIPLOME } from './optionLabels.js'

const NAT_OPTIONS = [
  { value: 'oui', label: 'Oui' },
  { value: 'non', label: 'Non' },
]

const natToValue = (b) => (b === true ? 'oui' : b === false ? 'non' : null)
const valueToNat = (v) => (v === 'oui' ? true : v === 'non' ? false : null)

/**
 * Onglet Vérification — nationalité ivoirienne confirmée (CNI) + SC.04 diplôme
 * (`PUT /evaluateur/candidatures/{id}/verification`).
 *
 * Actif SEULEMENT si `statut_interne === 'en_instruction'` — miroir exact de la
 * garde backend (sinon 409). Un seul PUT envoie les deux champs ; la réponse
 * fraîche remplace tout l'état du dossier dans le composant parent — le panneau
 * Éligibilité se met à jour depuis CETTE réponse, jamais recalculé ici.
 */
export function VerificationForm({ dossier, saving, verifError, onSave }) {
  const editable = dossier.statut_interne === 'en_instruction'
  const [nat, setNat] = useState(natToValue(dossier.verification?.nationalite_confirmee ?? null))
  const [diplome, setDiplome] = useState(dossier.verification?.diplome_verifie ?? null)
  const [saved, setSaved] = useState(false)

  const submit = async () => {
    setSaved(false)
    try {
      await onSave({ nationalite_confirmee: valueToNat(nat), diplome_verifie: diplome })
      setSaved(true)
    } catch {
      // verifError est déjà posé par useFicheCandidat — rien à faire ici.
    }
  }

  return (
    <>
      {!editable ? (
        <div style={{ marginBottom: 'var(--space-5)' }}>
          <Alert variant="info">
            La vérification n'est modifiable que lorsque le dossier est « en cours d'instruction ».
          </Alert>
        </div>
      ) : null}
      {verifError ? (
        <div style={{ marginBottom: 'var(--space-5)' }}>
          <Alert variant="danger">{verifError}</Alert>
        </div>
      ) : null}
      {saved && !verifError ? (
        <div style={{ marginBottom: 'var(--space-5)' }}>
          <Alert variant="success">Vérification enregistrée.</Alert>
        </div>
      ) : null}

      <fieldset disabled={!editable} style={{ border: 0, padding: 0, margin: 0 }}>
        <SegmentedRadio
          legend="Nationalité ivoirienne confirmée (CNI)"
          name="nationalite_confirmee"
          options={NAT_OPTIONS}
          value={nat}
          onChange={setNat}
        />
        <RadioCardList
          legend={SC04_DIPLOME.label}
          name="diplome_verifie"
          options={SC04_DIPLOME.options}
          value={diplome}
          onChange={setDiplome}
          hint={SC04_DIPLOME.hint}
        />
      </fieldset>

      {dossier.verification?.verifie_le ? (
        <p className="caption" style={{ marginBottom: 'var(--space-4)' }}>
          Dernière vérification : {formatDateFr(dossier.verification.verifie_le)}
          {dossier.verification.verifie_par
            ? ` par ${dossier.verification.verifie_par.prenom} ${dossier.verification.verifie_par.nom}`
            : ''}
        </p>
      ) : null}

      <button type="button" className="btn btn-primary" disabled={!editable || saving} onClick={submit}>
        {saving ? 'Enregistrement…' : 'Enregistrer la vérification'}
        <i className="fa-solid fa-check" aria-hidden="true" />
      </button>
    </>
  )
}
