import { readdirSync, readFileSync, statSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

/**
 * GARDE « AUCUN IDENTIFIANT DE DÉMONSTRATION DANS LE BUNDLE » (Lot 10 — sécurité).
 *
 * Les comptes `*@casa-demo.ci` (mot de passe `Demo2026!`) sont des comptes
 * PRIVILÉGIÉS seedés en dev uniquement (`ComptesDemoSeeder`). Les afficher sur
 * `LoginPage` — page publique, donc bundle JS téléchargeable — les divulguait.
 *
 * Ce test scanne TOUT `src/` (hors `__tests__/` et `test/`, jamais bundlés) et
 * échoue si un identifiant de démonstration réapparaît. Complété en CI par un
 * `grep` du `dist/` réel (job `frontend` de `.github/workflows/ci.yml`) : si ce
 * n'est ni dans la source ni dans le build, ce n'est pas exposé.
 */
const HERE = dirname(fileURLToPath(import.meta.url))
const SRC = join(HERE, '..', '..', '..') // src/pages/auth/__tests__ -> src

function collectSourceFiles(dir) {
  const out = []
  for (const entry of readdirSync(dir)) {
    if (entry === '__tests__' || entry === 'test') continue
    const full = join(dir, entry)
    if (statSync(full).isDirectory()) {
      out.push(...collectSourceFiles(full))
    } else if (/\.(jsx?|css|html)$/.test(entry)) {
      out.push(full)
    }
  }
  return out
}

const files = collectSourceFiles(SRC)

const FORBIDDEN = [
  /casa-demo\.ci/i, // e-mails des comptes de démonstration
  /Demo2026/, // mot de passe de démonstration
  /DEMO_ACCOUNTS?/, // constante retirée au Lot 10
  /DEMO_PASSWORD/,
]

describe('src/** — aucun identifiant de démonstration (Lot 10)', () => {
  it('a bien trouvé des fichiers à vérifier', () => {
    expect(files.length).toBeGreaterThan(30)
  })

  it.each(files.map((f) => [f.slice(SRC.length + 1)]))(
    '%s ne contient aucun identifiant de démonstration',
    (rel) => {
      const content = readFileSync(join(SRC, rel), 'utf8')
      const offenders = FORBIDDEN.filter((re) => re.test(content))
      expect(offenders.map(String)).toEqual([])
    },
  )
})
