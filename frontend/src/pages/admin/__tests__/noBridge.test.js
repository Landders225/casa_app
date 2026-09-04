import { readdirSync, readFileSync, statSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

/**
 * GARDE DE NON-PONT (Lot 8d-1) — pas une convention, un test exécutable.
 *
 * `pages/admin/**` a le DROIT de rendre la zone 🔴 (statut interne,
 * éligibilité, scores figés, décision — `CandidatureAdminResource`) : c'est
 * le travail de supervision de l'administrateur. Mais aucun fichier de cet
 * arbre ne doit importer depuis `pages/candidat/**` NI `pages/evaluateur/**`
 * — les trois arbres restent étanches, y compris pour de simples libellés
 * (`optionLabels.js` duplique volontairement plutôt que de réutiliser
 * `pages/evaluateur/optionLabels.js`, pourtant quasi identique, ou
 * `ConfirmDialog.jsx`, dupliqué pour la même raison). RÉCIPROQUE de
 * `pages/evaluateur/__tests__/noBridge.test.js`.
 */
const HERE = dirname(fileURLToPath(import.meta.url))
const ROOT = join(HERE, '..') // src/pages/admin
const ARBRES_INTERDITS = ['candidat', 'evaluateur']

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

describe('pages/admin/** — aucun pont vers pages/candidat/** ni pages/evaluateur/**', () => {
  const files = collectSourceFiles(ROOT)

  it('a bien trouvé des fichiers à vérifier (le test ne passe pas “par défaut”)', () => {
    expect(files.length).toBeGreaterThan(5)
  })

  // Seules les instructions `import`/`require` réelles comptent — une mention de
  // "pages/evaluateur" dans un commentaire (ex. ce fichier lui-même) n'est pas
  // un pont et ne doit pas faire échouer le test.
  const IMPORT_RE = /^\s*import\b[^\n]*from\s+['"]([^'"]+)['"]|(?:^|[^.\w])require\(\s*['"]([^'"]+)['"]\s*\)|(?:^|[^.\w])import\(\s*['"]([^'"]+)['"]\s*\)/gm

  it.each(files.map((f) => [f.slice(ROOT.length + 1)]))('%s n’importe pas depuis pages/candidat ni pages/evaluateur', (rel) => {
    const content = readFileSync(join(ROOT, rel), 'utf8')
    const specifiers = [...content.matchAll(IMPORT_RE)].map((m) => m[1] || m[2] || m[3])
    const offenders = specifiers.filter((s) => ARBRES_INTERDITS.some((arbre) => new RegExp(`/${arbre}(/|$)`).test(s)))
    expect(offenders).toEqual([])
  })
})
