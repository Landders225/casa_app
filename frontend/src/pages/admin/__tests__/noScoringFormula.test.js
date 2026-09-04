import { readdirSync, readFileSync, statSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

/**
 * GARDE « ZÉRO FORMULE DE CLASSEMENT/SCORING DANS LE BUNDLE » (Lot 8d-2) —
 * pas une convention, un test exécutable. Dupliqué de
 * `pages/evaluateur/__tests__/noScoringFormula.test.js` (même discipline,
 * arbre différent — cf. `noBridge.test.js`).
 *
 * `Classement.jsx` affiche `score_final`/`rang`/`departage` TELS QUE renvoyés
 * par `ClassementResource` — `ServiceClassement` (tri, départage, plafond)
 * reste 100 % serveur. Les `lignes[]` arrivent déjà TRIÉES par rang : cet
 * écran ne fait AUCUN `.sort()` sur des données de classement.
 *
 * Exception explicitement assumée : le littéral `/ 100` dans `Classement.jsx`
 * pour `score_final`. Ce n'est PAS un poids de barème (contrairement à un
 * `max_points` de rubrique) : `ServiceClassement::scoreFinal()` plafonne à 100
 * par un `min(..., 100.0)` FIXE, indépendant des poids de grille — la valeur
 * ne peut pas dériver d'un recalcul de grille comme `/65`/`/35` le pourraient.
 * Ces deux derniers restent interdits partout : `score_dossier`/`score_entretien`
 * s'affichent SANS dénominateur codé en dur (leurs maxima n'étant pas dans
 * cette réponse API).
 */
const HERE = dirname(fileURLToPath(import.meta.url))
const ROOT = join(HERE, '..') // src/pages/admin

function collectSourceFiles(dir) {
  const out = []
  for (const entry of readdirSync(dir)) {
    if (entry === '__tests__') continue
    const full = join(dir, entry)
    if (statSync(full).isDirectory()) {
      out.push(...collectSourceFiles(full))
    } else if (/\.(jsx?|css)$/.test(entry)) {
      out.push(full)
    }
  }
  return out
}

function stripComments(source) {
  return source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/.*$/gm, '')
}

const files = collectSourceFiles(ROOT)

// Motifs de barème/classement : dénominateurs de volet en dur, fonctions de
// calcul que la maquette utilise côté client (`window.CasaScoring.*`,
// `rankCandidatsParFiliere`), constante algorithmique de `ServiceClassement`.
const FORBIDDEN_PATTERNS = [
  /\/\s?65\b/, // dénominateur du volet dossier en dur
  /\/\s?35\b/, // dénominateur du volet entretien en dur
  /pointsParEtoile/i,
  /pond[ée]r/i,
  /bar[èe]me/i,
  /CasaScoring/,
  /computeScores?\b/,
  /computeEntretienScore/,
  /computeScoreFinal/,
  /rankCandidatsParFiliere/,
  /TAILLE_LISTE_ATTENTE/,
  /quota\s*\+\s*8\b/, // "quota + 8" = la constante de liste d'attente de la maquette, en dur
]

describe('pages/admin/** — zéro formule de classement/scoring dans le bundle (Lot 8d-2)', () => {
  it('a bien trouvé des fichiers à vérifier (le test ne passe pas “par défaut”)', () => {
    expect(files.length).toBeGreaterThan(10)
  })

  it.each(files.map((f) => [f.slice(ROOT.length + 1)]))('%s ne contient aucun motif de barème/classement en dur (hors commentaires)', (rel) => {
    const content = stripComments(readFileSync(join(ROOT, rel), 'utf8'))
    const offenders = FORBIDDEN_PATTERNS.filter((re) => re.test(content))
    expect(offenders.map(String)).toEqual([])
  })

  it("Classement.jsx ne trie JAMAIS les lignes d'un classement — l'ordre vient déjà du serveur", () => {
    const content = stripComments(readFileSync(join(ROOT, 'Classement.jsx'), 'utf8'))
    expect(content).not.toMatch(/\.sort\(/)
    // Les lignes sont bien rendues depuis `filiereActive.lignes` tel quel (pas
    // une variable locale re-triée) — présence directe du `.map(` attendu.
    expect(content).toMatch(/filiereActive\.lignes\.map\(/)
  })
})
