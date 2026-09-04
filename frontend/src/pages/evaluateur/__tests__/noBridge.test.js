import { readdirSync, readFileSync, statSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

/**
 * GARDE DE NON-PONT (Lot 8c-1) — pas une convention, un test exécutable.
 *
 * `pages/evaluateur/**` a le DROIT de rendre la zone 🔴 (statut interne,
 * réponses, critères éliminatoires) : c'est le travail de l'évaluateur. Mais
 * aucun fichier de cet arbre ne doit importer depuis `pages/candidat/**` — les
 * deux arbres restent étanches, y compris pour de simples libellés
 * (`optionLabels.js` duplique volontairement `formStructure.js` plutôt que de
 * le réutiliser).
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

describe('pages/evaluateur/** — aucun pont vers pages/candidat/**', () => {
  const files = collectSourceFiles(ROOT)

  it('a bien trouvé des fichiers à vérifier (le test ne passe pas “par défaut”)', () => {
    expect(files.length).toBeGreaterThan(5)
  })

  // Seules les instructions `import`/`require` réelles comptent — une mention de
  // "pages/candidat" dans un commentaire (ex. ce fichier lui-même, ou la doc de
  // optionLabels.js expliquant POURQUOI il duplique plutôt que d'importer)
  // n'est pas un pont et ne doit pas faire échouer le test.
  const IMPORT_RE = /^\s*import\b[^\n]*from\s+['"]([^'"]+)['"]|(?:^|[^.\w])require\(\s*['"]([^'"]+)['"]\s*\)|(?:^|[^.\w])import\(\s*['"]([^'"]+)['"]\s*\)/gm

  it.each(files.map((f) => [f.slice(ROOT.length + 1)]))('%s n’importe pas depuis pages/candidat', (rel) => {
    const content = readFileSync(join(ROOT, rel), 'utf8')
    const specifiers = [...content.matchAll(IMPORT_RE)].map((m) => m[1] || m[2] || m[3])
    const offenders = specifiers.filter((s) => /\/candidat(\/|$)/.test(s))
    expect(offenders).toEqual([])
  })
})
