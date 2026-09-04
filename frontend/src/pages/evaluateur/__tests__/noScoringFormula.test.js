import { readdirSync, readFileSync, statSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

/**
 * GARDE « ZÉRO FORMULE DE SCORING DANS LE BUNDLE » (Lot 8c-2) — pas une
 * convention, un test exécutable.
 *
 * Contrairement à `optionLabels.js` (Lot 8c-1), les écrans de notation
 * (`EvaluationDossier.jsx`, `Entretien.jsx`, `ScoreRing.jsx`, `PointPicker.jsx`)
 * n'ont besoin d'AUCUNE constante de barème : labels ET bornes (`max`) viennent
 * systématiquement de la réponse API (`rubriques[].label/.max`,
 * `sous_notes[].label/.max`). Ce test scanne le SOURCE de `pages/evaluateur/**`
 * et échoue si un motif de barème y apparaît en dur.
 *
 * Exception explicitement tolérée (Étape 1, Q6) : la plage FIXE 0-5 du
 * sélecteur d'étoiles MO.04 (`StarPicker.jsx`) — une cardinalité d'UI miroir
 * de la règle backend `between:0,5`, pas une valeur en points. Les 2 libellés
 * de lieu d'entretien (`LIEUX_ENTRETIEN`, `Entretien.jsx`) sont eux aussi
 * hors barème (noms de site, miroir de `Rule::in([...])`) — mais pas testés
 * en unicité ici : « 2 Plateaux Vallons » est un nom de lieu déjà légitimement
 * présent ailleurs dans l'arbre évaluateur (disponibilité candidate, Lot 8c-1),
 * sans rapport avec le barème.
 */
const HERE = dirname(fileURLToPath(import.meta.url))
const ROOT = join(HERE, '..') // src/pages/evaluateur

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

/**
 * Retire les commentaires `/* *\/` et `// ...` avant de chercher un motif de
 * barème : les fichiers documentent légitimement l'ADR-04 en PROSE (« notation
 * du dossier /65 », cf. les docstrings de ce lot) — seul du CODE (littéral,
 * template string, appel de fonction) doit faire échouer ce test, pas un
 * commentaire qui explique la règle.
 */
function stripComments(source) {
  return source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/.*$/gm, '')
}

const files = collectSourceFiles(ROOT)

// Motifs de barème : dénominateurs de volet en dur, mots-clés de pondération,
// et noms de fonctions de calcul que la maquette utilise côté client
// (`window.CasaScoring.*`) — cette API n'est jamais importée ni réimplémentée ici.
const FORBIDDEN_PATTERNS = [
  /\/\s?65\b/, // dénominateur du volet dossier en dur (hors variable)
  /\/\s?35\b/, // dénominateur du volet entretien en dur
  /pointsParEtoile/i,
  /pond[ée]r/i,
  /bar[èe]me/i,
  /CasaScoring/,
  /computeScores?\b/,
  /computeEntretienScore/,
  /computeScoreFinal/,
]

// Seule valeur numérique autorisée hors réponse API (cf. docstring) — le test
// la whiteliste explicitement.
const ALLOWED_FILES_FOR_STAR_RANGE = new Set(['StarPicker.jsx'])

describe('pages/evaluateur/** — zéro formule de scoring/barème dans le bundle (Lot 8c-2)', () => {
  it('a bien trouvé des fichiers à vérifier (le test ne passe pas “par défaut”)', () => {
    expect(files.length).toBeGreaterThan(10)
  })

  it.each(files.map((f) => [f.slice(ROOT.length + 1)]))('%s ne contient aucun motif de barème en dur (hors commentaires)', (rel) => {
    const content = stripComments(readFileSync(join(ROOT, rel), 'utf8'))
    const offenders = FORBIDDEN_PATTERNS.filter((re) => re.test(content))
    expect(offenders.map(String)).toEqual([])
  })

  it('StarPicker.jsx est le SEUL endroit qui code la plage 0-5 des étoiles', () => {
    for (const f of files) {
      const rel = f.slice(ROOT.length + 1)
      const base = rel.split(/[/\\]/).pop()
      if (ALLOWED_FILES_FOR_STAR_RANGE.has(base)) continue
      const content = stripComments(readFileSync(f, 'utf8'))
      expect(content).not.toMatch(/\[1,\s*2,\s*3,\s*4,\s*5\]/)
    }
  })

  it("les sélecteurs de sous-notes d'entretien (PointPicker) ne codent aucun maximum : la plage vient d'un paramètre `max`", () => {
    const content = stripComments(readFileSync(join(ROOT, 'PointPicker.jsx'), 'utf8'))
    // La seule occurrence numérique doit être `{ length: max + 1 }` — pas de
    // constante de secours (`max ?? 5`, `Math.min(max, N)`...).
    expect(content).not.toMatch(/\bmax\s*(\?\?|\|\|)\s*\d/)
    expect(content).toMatch(/length:\s*max\s*\+\s*1/)
  })
})
