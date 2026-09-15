import { useState } from 'react'
import { FormField } from './FormField.jsx'

/**
 * Champ mot de passe AVEC bascule visuelle (œil / œil barré) — partagé par
 * TOUS les espaces (connexion, inscription, changement candidat/équipe,
 * réinitialisation), au même titre que `FormField`/`Alert`/`Spinner` dans
 * `components/ui/` : la garde de non-pont (`noBridge.test.js`) ne restreint
 * QUE `pages/{espace}/**`, pas cette zone déjà partagée.
 *
 * Enveloppe `FormField` (prop `trailing`) plutôt que de reconstruire le
 * balisage label/erreur/hint en double — même comportement d'erreur/aide que
 * n'importe quel autre `FormField`, juste le `type` qui bascule.
 *
 * Masqué par défaut. Le bouton est un VRAI `<button type="button">` :
 * focusable et activable au clavier (Tab puis Entrée/Espace) nativement, et
 * `type="button"` est INDISPENSABLE — sans lui, un bouton dans un `<form>` est
 * `type="submit"` par défaut et soumettrait le formulaire au clic/Entrée.
 * `aria-label` reflète l'action à venir (« Afficher »/« Masquer »), pas
 * l'état courant.
 */
export function ChampMotDePasse({ label, icon = null, error = null, hint = null, inputProps = {} }) {
  const [visible, setVisible] = useState(false)

  return (
    <FormField
      label={label}
      type={visible ? 'text' : 'password'}
      icon={icon}
      error={error}
      hint={hint}
      inputProps={inputProps}
      trailing={
        <button
          type="button"
          className="toggle-password"
          onClick={() => setVisible((v) => !v)}
          aria-label={visible ? 'Masquer le mot de passe' : 'Afficher le mot de passe'}
        >
          <i className={`fa-solid ${visible ? 'fa-eye-slash' : 'fa-eye'}`} aria-hidden="true" />
        </button>
      }
    />
  )
}
