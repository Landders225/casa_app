import { execSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'
import { expect, test } from '@playwright/test'

/**
 * Lot 11c — Espace ADMINISTRATEUR : écran « Rapports & statistiques » (ADR-30).
 *
 * `DemoClassementSeeder` fournit 6 candidatures évaluées (filière cuisine, même
 * ville) SANS classement calculé → les répartitions à effectif ≥ 5 (F/H,
 * scores, villes, présence) s'affichent, celle des décisions (0 décision) est
 * masquée par le garde-fou k-anonymat. Idéal pour capturer les deux états.
 */

const APP = fileURLToPath(new URL('../..', import.meta.url))
const artisan = (cmd) =>
  execSync(`docker compose exec -T backend php artisan ${cmd}`, { cwd: APP, stdio: 'pipe' }).toString()

const PWD = 'Demo2026!'

async function login(page, email) {
  await page.goto('/connexion')
  await page.getByLabel('Adresse e-mail').fill(email)
  await page.getByLabel('Mot de passe', { exact: true }).fill(PWD)
  await page.getByRole('button', { name: /se connecter/i }).click()
}

async function shots(page, name) {
  for (const w of [390, 1280]) {
    // eslint-disable-next-line no-await-in-loop
    await page.setViewportSize({ width: w, height: 900 })
    // eslint-disable-next-line no-await-in-loop
    await page.evaluate(() => new Promise((r) => setTimeout(r, 150)))
    // eslint-disable-next-line no-await-in-loop
    await page.screenshot({ path: `e2e/__screenshots__/rapports-${name}-${w}.png`, fullPage: true, animations: 'disabled' })
  }
  await page.setViewportSize({ width: 1280, height: 800 })
}

test.describe.configure({ mode: 'serial', timeout: 240_000 })

test.describe('Espace administrateur — Rapports & statistiques (Lot 11c)', () => {
  test.beforeAll(() => {
    artisan('migrate:fresh --seed --force')
    artisan('db:seed --class=DemoClassementSeeder --force')
  })
  test.afterAll(() => {
    artisan('migrate:fresh --seed --force')
  })

  test('évaluateur → redirigé hors de /admin/rapports', async ({ page }) => {
    await login(page, 'evaluateur@casa-demo.ci')
    await page.waitForURL(/\/evaluateur$/, { timeout: 60_000 })
    await page.goto('/admin/rapports')
    await expect(page).toHaveURL(/\/evaluateur$/)
  })

  test('admin → graphiques SVG rendus depuis l’API + panneau masqué (k-anonymat)', async ({ page }) => {
    await login(page, 'admin@casa-demo.ci')
    await page.waitForURL(/\/admin$/, { timeout: 60_000 })
    await page.goto('/admin/rapports')

    await expect(page.getByRole('heading', { level: 2, name: /Rapports & statistiques/i })).toBeVisible({ timeout: 30_000 })
    // Note explicative du garde-fou.
    await expect(page.getByText(/moins de 5 personnes est masquée/i)).toBeVisible({ timeout: 30_000 })

    // Répartitions à effectif ≥ 5 : graphes SVG titrés présents.
    await expect(page.getByRole('img', { name: /Répartition femmes \/ hommes/i })).toBeVisible()
    await expect(page.getByRole('img', { name: /Distribution des scores/i })).toBeVisible()
    await expect(page.getByRole('img', { name: /Candidatures par filière/i })).toBeVisible()

    // Décisions : 0 décision calculée → panneau « effectif insuffisant », pas de graphe.
    await expect(page.getByText(/Effectif insuffisant pour publier cette répartition/i).first()).toBeVisible()

    await shots(page, 'ecran')
  })

  test('admin → sélecteur « Toutes les campagnes » relance la requête', async ({ page }) => {
    await login(page, 'admin@casa-demo.ci')
    await page.waitForURL(/\/admin$/, { timeout: 60_000 })
    await page.goto('/admin/rapports')
    await expect(page.getByRole('heading', { level: 2, name: /Rapports & statistiques/i })).toBeVisible({ timeout: 30_000 })

    const [reponse] = await Promise.all([
      page.waitForResponse((r) => r.url().includes('/api/admin/rapports?campagne=toutes')),
      page.getByLabel('Campagne').selectOption('toutes'),
    ])
    expect(reponse.ok()).toBeTruthy()
    await expect(page.getByText(/Toutes campagnes confondues/i)).toBeVisible({ timeout: 30_000 })
  })

  test('admin → export CSV : téléchargement d’un fichier text/csv', async ({ page }) => {
    await login(page, 'admin@casa-demo.ci')
    await page.waitForURL(/\/admin$/, { timeout: 60_000 })
    await page.goto('/admin/rapports')
    await expect(page.getByRole('heading', { level: 2, name: /Rapports & statistiques/i })).toBeVisible({ timeout: 30_000 })

    const [download] = await Promise.all([
      page.waitForEvent('download'),
      page.getByRole('button', { name: /exporter \(csv\)/i }).click(),
    ])
    expect(download.suggestedFilename()).toMatch(/casa-rapport-.*\.csv/)

    const flux = await download.createReadStream()
    const contenu = await new Promise((resolve) => {
      let data = ''
      flux.on('data', (c) => { data += c })
      flux.on('end', () => resolve(data))
    })
    // Agrégats présents, aucune donnée individuelle.
    expect(contenu).toContain('Candidatures par filière')
    expect(contenu).not.toMatch(/CASA-2026-9|Démo/)

    // Les boutons Excel / PDF restent désactivés.
    await expect(page.getByRole('button', { name: /^Excel$/ })).toBeDisabled()
    await expect(page.getByRole('button', { name: /^PDF$/ })).toBeDisabled()
  })
})
