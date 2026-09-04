import { FieldError } from '../../../../components/form/FieldError.jsx'
import { FormField } from '../../../../components/ui/FormField.jsx'

/**
 * Étape 1 — état civil, écrit via `PATCH /api/candidat/profil` (Lot 7).
 * `email` en lecture seule (non éditable — flux dédié, D-7-2). `residence_ci`
 * absent (fixé à l'inscription — Lot 7 Q2b). Ville = champ libre (D-8b1).
 */
export function StepIdentite({ email, draft, setDraft, errors }) {
  const set = (k) => (e) => setDraft((d) => ({ ...d, [k]: e.target.value }))

  return (
    <>
      <h3 style={{ marginBottom: 'var(--space-5)' }}>Vos informations personnelles</h3>

      <div className="form-row">
        <FormField label="Prénom" error={errors.prenom} inputProps={{ value: draft.prenom, onChange: set('prenom'), required: true }} />
        <FormField label="Nom" error={errors.nom} inputProps={{ value: draft.nom, onChange: set('nom'), required: true }} />
      </div>

      <div className="form-row">
        <FormField
          label="Date de naissance"
          type="date"
          error={errors.date_naissance}
          inputProps={{ value: draft.date_naissance, onChange: set('date_naissance'), required: true }}
        />
        <div className="form-group">
          <label className="label" htmlFor="id-sexe">Sexe</label>
          <select id="id-sexe" className="select" value={draft.sexe} onChange={set('sexe')} required>
            <option value="">Sélectionner</option>
            <option value="F">Féminin</option>
            <option value="H">Masculin</option>
          </select>
          <FieldError>{errors.sexe}</FieldError>
        </div>
      </div>

      <div className="form-row">
        <FormField label="Numéro CNI / récépissé" error={errors.cni} inputProps={{ value: draft.cni, onChange: set('cni'), required: true }} />
        <FormField
          label="Téléphone"
          type="tel"
          error={errors.telephone}
          inputProps={{ value: draft.telephone, onChange: set('telephone'), inputMode: 'tel' }}
        />
      </div>

      <FormField
        label="Ville de résidence"
        error={errors.ville_residence}
        hint="Ex. Abidjan - Cocody, Bouaké, San-Pédro…"
        inputProps={{ value: draft.ville_residence, onChange: set('ville_residence') }}
      />

      <div className="form-group">
        <label className="label" htmlFor="id-email">Adresse e-mail</label>
        <input id="id-email" className="input" type="email" value={email} disabled readOnly />
        <p className="input-hint">L'adresse e-mail de connexion ne se modifie pas ici.</p>
      </div>
    </>
  )
}
