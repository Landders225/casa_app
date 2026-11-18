import { useCallback, useEffect, useState } from 'react'
import { Alert } from '../../components/ui/Alert.jsx'
import { Spinner } from '../../components/ui/Spinner.jsx'
import { useAffectationAutomatique } from './useAffectationAutomatique.js'

const IGNORES_LIBELLES = [
  ['deja_affectes', 'déjà affecté(s)'],
  ['non_eligibles', 'non éligible(s)'],
  ['statut_non_affectable', 'non affectable(s) (statut)'],
]

/**
 * Répartition automatique (ADR-45, ADR-48) — proposée UNIQUEMENT en mode
 * « tous les N résultats ». APERÇU d'abord (rien n'est écrit), confirmation
 * ensuite.
 *
 * Tous les nombres affichés (affectables, ignorés, charge, « à recevoir »)
 * viennent du serveur : aucun calcul de répartition côté client. Les
 * évaluateurs proposés sont ceux de `GET /admin/evaluateurs` (actifs) ; tous
 * cochés = pas de restriction envoyée (le serveur prend tous les actifs).
 *
 * Sur échec, le message du serveur est affiché TEL QUEL, le modal reste
 * ouvert et la confirmation est bloquée jusqu'à un nouvel aperçu
 * (« Recalculer l'aperçu ») — jamais une confirmation sur des nombres
 * périmés. Si la liste elle-même a changé (`liste_modifiee` dès l'aperçu),
 * la fermeture recharge la liste et vide la sélection pour reconfirmer.
 *
 * Cibles par évaluateur (Lot 3, ADR-48) : champ numérique facultatif par
 * évaluateur coché — « nombre de dossiers à recevoir » POUR CETTE OPÉRATION
 * (jamais un plafond de charge totale). La somme saisie est comparée EN
 * DIRECT (calcul local, aucun appel serveur) aux dossiers affectables : au-
 * delà, la confirmation est bloquée avant même d'interroger le serveur, qui
 * la refuserait de toute façon (422 `cibles_excessives`). Un changement de
 * cible relance l'aperçu, comme une case cochée/décochée.
 */
export function AffectationAutomatiqueModal({ criteres, nombreCorrespondants, evaluateurs, onCancel, onSuccess }) {
  const [choisis, setChoisis] = useState(() => new Set(evaluateurs.map((e) => e.id)))
  const [ciblesTexte, setCiblesTexte] = useState({})
  const { apercu, chargement, execution, erreur, calculerApercu, executer } = useAffectationAutomatique()

  // Tous cochés = aucune restriction envoyée. Clé texte stable (ordre de la
  // liste) : l'aperçu n'est recalculé que si la sélection change vraiment.
  const cleChoix = choisis.size === evaluateurs.length
    ? '*'
    : evaluateurs.filter((e) => choisis.has(e.id)).map((e) => e.id).join(',')
  const evaluateurIds = cleChoix === '*' ? null : cleChoix.split(',').filter(Boolean)

  // Cibles valides parmi les évaluateurs COCHÉS uniquement (décocher retire
  // sa cible de l'opération). `cleCibles` : clé texte stable, même principe
  // que `cleChoix` — l'aperçu n'est recalculé que si une cible change vraiment.
  const ciblesNombres = {}
  for (const id of choisis) {
    const texte = ciblesTexte[id]
    if (texte !== undefined && texte !== '') {
      const n = Number(texte)
      if (Number.isInteger(n) && n >= 0) ciblesNombres[id] = n
    }
  }
  const idsAvecCible = Object.keys(ciblesNombres).sort()
  const cleCibles = idsAvecCible.map((id) => `${id}:${ciblesNombres[id]}`).join(',')
  const cibles = idsAvecCible.length > 0
    ? idsAvecCible.map((evaluateur_id) => ({ evaluateur_id, nombre: ciblesNombres[evaluateur_id] }))
    : null

  const recalculer = useCallback(() => {
    if (cleChoix === '') return
    calculerApercu({
      criteres,
      nombreCorrespondants,
      evaluateurIds: cleChoix === '*' ? null : cleChoix.split(','),
      cibles,
    })
    // eslint-disable-next-line react-hooks/exhaustive-deps -- `cibles` est redérivé de `cleCibles` (clé stable) dans ce même rendu.
  }, [calculerApercu, criteres, nombreCorrespondants, cleChoix, cleCibles])

  useEffect(() => {
    recalculer()
  }, [recalculer])

  const basculer = (id) => setChoisis((prev) => {
    const next = new Set(prev)
    if (next.has(id)) next.delete(id)
    else next.add(id)
    return next
  })

  const changerCible = (id, valeur) => setCiblesTexte((prev) => ({ ...prev, [id]: valeur }))

  const confirmer = async () => {
    try {
      const res = await executer({
        criteres,
        nombreCorrespondants,
        nombreAffectables: apercu.nombre_affectables,
        evaluateurIds,
        cibles,
        repartitionAttendue: cibles
          ? apercu.evaluateurs.map((e) => ({ evaluateur_id: e.id, a_recevoir: e.a_recevoir }))
          : null,
        nonAffectesAttendu: cibles ? apercu.non_affectes : null,
      })
      onSuccess(res)
    } catch {
      // `erreur` est posé par le hook — le modal reste ouvert.
    }
  }

  const recus = apercu ? apercu.evaluateurs.map((e) => e.a_recevoir) : []
  const recoivent = recus.filter((n) => n > 0).length
  const min = recus.length ? Math.min(...recus) : 0
  const max = recus.length ? Math.max(...recus) : 0
  // Dossiers RÉELLEMENT répartis (correctif de revue) : `nombre_affectables`
  // seul surcompte dès qu'un reliquat reste non affecté sous cibles (ADR-48).
  const repartis = apercu ? apercu.nombre_affectables - (apercu.non_affectes ?? 0) : 0
  const ignores = apercu ? IGNORES_LIBELLES.filter(([cle]) => apercu.ignores[cle] > 0) : []
  const totalIgnores = apercu ? apercu.nombre_correspondants - apercu.nombre_affectables : 0
  const occupe = chargement || execution

  // Comparaison EN DIRECT (aucun appel serveur) : la somme saisie contre le
  // meilleur plafond connu (l'affectable exact dès l'aperçu disponible,
  // sinon le nombre de correspondants en attendant).
  const sommeCibles = Object.values(ciblesNombres).reduce((a, b) => a + b, 0)
  const plafondCibles = apercu ? apercu.nombre_affectables : nombreCorrespondants
  const depassementCibles = cibles !== null && sommeCibles > plafondCibles

  return (
    <div className="modal-overlay" role="dialog" aria-modal="true" aria-label="Répartir automatiquement">
      <div className="modal modal-lg">
        <div className="modal-header">
          <h3>Répartir automatiquement</h3>
        </div>
        <div className="modal-body">
          <p className="body-sm" style={{ marginBottom: 'var(--space-3)' }}>
            {nombreCorrespondants} dossier{nombreCorrespondants > 1 ? 's' : ''} correspond{nombreCorrespondants > 1 ? 'ent' : ''} à votre recherche et vos filtres.
            Les dossiers déjà affectés ne sont jamais réaffectés automatiquement.
          </p>

          <fieldset disabled={occupe} style={{ border: 'none', padding: 0, margin: '0 0 var(--space-3)' }}>
            <legend className="caption fw-semibold" style={{ marginBottom: 'var(--space-2)' }}>Évaluateurs concernés</legend>
            {evaluateurs.length === 0 ? <p className="text-muted caption">Aucun évaluateur actif.</p> : null}
            <div className="flex gap-3" style={{ flexWrap: 'wrap' }}>
              {evaluateurs.map((e) => (
                <div key={e.id} className="flex items-center gap-2 caption">
                  <label className="flex items-center gap-2">
                    <input type="checkbox" checked={choisis.has(e.id)} onChange={() => basculer(e.id)} />
                    {e.prenom} {e.nom}
                  </label>
                  <input
                    type="number"
                    min="0"
                    max="1000"
                    placeholder="Partagé également"
                    aria-label={`Cible pour ${e.prenom} ${e.nom}`}
                    value={ciblesTexte[e.id] ?? ''}
                    disabled={!choisis.has(e.id)}
                    onChange={(ev) => changerCible(e.id, ev.target.value)}
                    style={{ width: '7rem' }}
                  />
                </div>
              ))}
            </div>
            {choisis.size === 0 && evaluateurs.length > 0 ? (
              <p className="caption text-muted" style={{ marginTop: 'var(--space-2)' }}>Cochez au moins un évaluateur.</p>
            ) : null}
            <p className="caption text-muted" style={{ marginTop: 'var(--space-2)' }}>
              Cible facultative : nombre de dossiers à recevoir POUR CETTE OPÉRATION (pas un plafond de charge totale).
              Les évaluateurs sans cible se partagent le reste, les moins chargés d'abord.
            </p>
            {cibles !== null ? (
              <p className={depassementCibles ? 'caption text-danger' : 'caption text-muted'} style={{ marginTop: 'var(--space-1)' }}>
                {sommeCibles} dossier{sommeCibles > 1 ? 's' : ''} ciblé{sommeCibles > 1 ? 's' : ''} sur {plafondCibles} affectable{plafondCibles > 1 ? 's' : ''}
                {depassementCibles ? ' : dépassement — réduisez une ou plusieurs cibles.' : '.'}
              </p>
            ) : null}
          </fieldset>

          {erreur ? (
            <div style={{ marginBottom: 'var(--space-3)' }}>
              <Alert variant="danger">{erreur.message}</Alert>
              <button type="button" className="btn btn-outline btn-sm" style={{ marginTop: 'var(--space-2)' }} onClick={recalculer} disabled={occupe || choisis.size === 0}>
                Recalculer l'aperçu
              </button>
            </div>
          ) : null}

          {chargement ? (
            <div className="text-center" style={{ padding: 'var(--space-6)' }}><Spinner /></div>
          ) : apercu ? (
            <>
              <p className="body-sm fw-semibold" style={{ marginBottom: 'var(--space-2)' }}>
                {repartis} dossier{repartis > 1 ? 's' : ''} {repartis > 1 ? 'seront répartis' : 'sera affecté'} entre {recoivent} évaluateur{recoivent > 1 ? 's' : ''} : {min === max ? `${max}` : `${min} à ${max}`} chacun.
              </p>
              {totalIgnores > 0 ? (
                <p className="caption text-muted" style={{ marginBottom: 'var(--space-3)' }}>
                  {totalIgnores} ignoré{totalIgnores > 1 ? 's' : ''} : {ignores.map(([cle, libelle]) => `${apercu.ignores[cle]} ${libelle}`).join(', ')}.
                </p>
              ) : null}
              {apercu.non_affectes > 0 ? (
                <div style={{ marginBottom: 'var(--space-3)' }}>
                  <Alert variant="warning">
                    {apercu.non_affectes} dossier{apercu.non_affectes > 1 ? 's' : ''} affectable{apercu.non_affectes > 1 ? 's' : ''} resteront NON affecté{apercu.non_affectes > 1 ? 's' : ''} à ce tour (cibles couvrant tous les évaluateurs sans atteindre le total) — vous pourrez relancer une opération plus tard pour eux.
                  </Alert>
                </div>
              ) : null}
              <div className="table-wrap">
                <table className="table">
                  <thead>
                    <tr>
                      <th>Évaluateur</th>
                      <th>Charge actuelle</th>
                      <th>À recevoir</th>
                      <th>Charge finale</th>
                    </tr>
                  </thead>
                  <tbody>
                    {apercu.evaluateurs.map((e) => (
                      <tr key={e.id}>
                        <td>{e.prenom} {e.nom}</td>
                        <td>{e.charge_actuelle}</td>
                        <td className="fw-semibold">{e.a_recevoir}</td>
                        <td>{e.charge_finale}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <p className="caption text-muted" style={{ marginTop: 'var(--space-2)' }}>
                Charge actuelle : dossiers en instruction dans la campagne concernée.
              </p>
            </>
          ) : null}
        </div>
        <div className="modal-footer">
          <button type="button" className="btn btn-outline" onClick={() => onCancel(erreur?.code ?? null)} disabled={execution}>
            Annuler
          </button>
          <button
            type="button"
            className="btn btn-primary"
            disabled={occupe || !apercu || Boolean(erreur) || choisis.size === 0 || depassementCibles || repartis === 0}
            onClick={confirmer}
          >
            {execution ? 'Répartition…' : apercu ? `Répartir ${repartis} dossier${repartis > 1 ? 's' : ''}` : 'Répartir'}
          </button>
        </div>
      </div>
    </div>
  )
}
