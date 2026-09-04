/**
 * Résumé compact du départage (Lot 8d-2, Étape 1 Q2) — `departage` vient TEL
 * QUEL de `ClassementResource` (`mixite_f`/`vulnerabilite`/`experience_secteur`/
 * `mo04`), calculé par `ServiceClassement::departage()` côté serveur. Affiché
 * en clair sous chaque ligne (pas au survol — visible aussi sur mobile/tablette,
 * la table dense justifiant le compact plutôt que 4 colonnes séparées).
 */
export function DepartageBadges({ departage }) {
  if (!departage) return null

  return (
    <div className="flex gap-1 items-center" style={{ flexWrap: 'wrap', marginTop: 4 }}>
      {departage.mixite_f ? (
        <span className="badge badge-neutral" title="Critère de départage : mixité (jeune fille)">
          <i className="fa-solid fa-venus" aria-hidden="true" /> Mixité
        </span>
      ) : null}
      <span className="badge badge-neutral" title="Critère de départage : vulnérabilité socio-économique">
        Vulnérabilité {departage.vulnerabilite}
      </span>
      {departage.experience_secteur ? (
        <span className="badge badge-neutral" title="Critère de départage : expérience dans le secteur hôtellerie-restauration">
          <i className="fa-solid fa-briefcase" aria-hidden="true" /> Secteur
        </span>
      ) : null}
      <span className="badge badge-neutral" title="Critère de départage : note de motivation (MO.04)">
        <i className="fa-solid fa-star" aria-hidden="true" /> {departage.mo04}
      </span>
    </div>
  )
}
