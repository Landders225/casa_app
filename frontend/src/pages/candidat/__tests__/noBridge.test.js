import { readdirSync, readFileSync, statSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

/**
 * GARDE DE NON-PONT (Lot 8d-1) — pas une convention, un test exécutable.
 *
 * `pages/candidat/**` est l'arbre le plus contraint des trois : il ne doit
 * JAMAIS rendre la zone 🔴 (statut interne, éligibilité, scores, décision
 * détaillée). Réciproque des gardes évaluateur/admin : aucun fichier de cet
 * arbre ne doit importer depuis `pages/evaluateur/**` ni `pages/admin/**` —
 * même si aujourd'hui rien n'y incite, le garde-fou protège le futur,
 * symétrique sur les trois arbres avant qu'un contributeur soit tenté d'y
 * introduire un pont.
 */
const HERE = dirname(fileURLToPath(import.meta.url))
const ROOT = join(HERE, '..') // src/pages/candidat
const ARBRES_INTERDITS = ['evaluateur', 'admin']

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

describe('pages/candidat/** — aucun pont vers pages/evaluateur/** ni pages/admin/**', () => {
  const files = collectSourceFiles(ROOT)

  it('a bien trouvé des fichiers à vérifier (le test ne passe pas “par défaut”)', () => {
    expect(files.length).toBeGreaterThan(5)
  })

  const IMPORT_RE = /^\s*import\b[^\n]*from\s+['"]([^'"]+)['"]|(?:^|[^.\w])require\(\s*['"]([^'"]+)['"]\s*\)|(?:^|[^.\w])import\(\s*['"]([^'"]+)['"]\s*\)/gm

  it.each(files.map((f) => [f.slice(ROOT.length + 1)]))('%s n’importe pas depuis pages/evaluateur ni pages/admin', (rel) => {
    const content = readFileSync(join(ROOT, rel), 'utf8')
    const specifiers = [...content.matchAll(IMPORT_RE)].map((m) => m[1] || m[2] || m[3])
    const offenders = specifiers.filter((s) => ARBRES_INTERDITS.some((arbre) => new RegExp(`/${arbre}(/|$)`).test(s)))
    expect(offenders).toEqual([])
  })
})
