import { execSync } from 'node:child_process'
import { expect, test } from '@playwright/test'

/**
 * Lot 8d-1 — Espace ADMINISTRATEUR : supervision & gestion courante.
 *
 * Réutilise `DemoClassementSeeder` (6 candidatures) plutôt qu'un script de
 * seed dédié : un simple UPDATE PSQL rend UN dossier (Awa) « soumis » et non
 * affecté, pour exercer l'affectation en masse. `ComptesDemoSeeder` (seed par
 * défaut) fournit déjà `admin@casa-demo.ci` (Prisca Yéo) et l'évaluateur par
 * défaut `evaluateur@casa-demo.ci` (Solange N'Dri), qui sert de cible
 * d'affectation.
 */

const APP = 'c:/Users/isaacaka/Desktop/CCI/Projet Arbre de Vie/casa-app'
const artisan = (cmd) =>
  execSync(`docker compose exec -T backend php artisan ${cmd}`, { cwd: APP, stdio: 'pipe' }).toString()
const psql = (sql) =>
  execSync('docker compose exec -T postgres psql -U casa -d casa -tA -v ON_ERROR_STOP=1', {
    cwd: APP,
    input: sql,
    stdio: ['pipe', 'pipe', 'pipe'],
  }).toString().trim()

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
    await page.evaluate(() => new Promise((r) => setTimeout(r, 120)))
    // eslint-disable-next-line no-await-in-loop
    await page.screenshot({ path: `e2e/__screenshots__/admin-${name}-${w}.png`, fullPage: true, animations: 'disabled' })
  }
  await page.setViewportSize({ width: 1280, height: 800 })
}

test.describe.configure({ mode: 'serial', timeout: 240_000 })

test.describe('Espace administrateur — supervision & gestion courante (Lot 8d-1)', () => {
  let awaId

  test.beforeAll(() => {
    artisan('migrate:fresh --seed --force')
    artisan('db:seed --class=DemoClassementSeeder --force')
    awaId = psql("select c.id from candidature c join candidat ca on ca.id = c.candidat_id where ca.prenom = 'Awa'")
    // Rouvre CE dossier pour l'affectation (les 6 de la seeder sont déjà affectés/évalués).
    psql(`
      update candidature
      set statut_interne = 'soumis', evaluateur_id = null
      where id = '${awaId}';
    `)
  })

  test.afterAll(() => {
    artisan('migrate:fresh --seed --force')
  })

  test('évaluateur -> redirigé hors de /admin/candidatures', async ({ page }) => {
    await login(page, 'evaluateur@casa-demo.ci')
    await page.waitForURL(/\/evaluateur$/, { timeout: 60_000 })
    await page.goto('/admin/candidatures')
    await expect(page).toHaveURL(/\/evaluateur$/)
  })

  test('admin -> dashboard : 4 KPI réels + campagnes, aucun graphique', async ({ page }) => {
    await login(page, 'admin@casa-demo.ci')
    await page.waitForURL(/\/admin$/, { timeout: 60_000 })
    await expect(page.getByRole('heading', { name: /Bonjour Prisca/i })).toBeVisible({ timeout: 30_000 })
    await expect(page.getByText('Non affectées')).toBeVisible({ timeout: 30_000 })
    await expect(page.getByText('Cohorte 1 — 2026')).toBeVisible()
    await expect(page.locator('canvas')).toHaveCount(0)
    await shots(page, 'dashboard')
  })

  test('admin -> candidatures -> affecte Awa à Solange N\'Dri', async ({ page }) => {
    await login(page, 'admin@casa-demo.ci')
    await page.waitForURL(/\/admin$/, { timeout: 60_000 })
    await page.goto('/admin/candidatures')
    await expect(page.getByRole('heading', { level: 2, name: 'Candidatures' })).toBeVisible({ timeout: 30_000 })
    await expect(page.getByText('Awa')).toBeVisible({ timeout: 30_000 })
    await shots(page, 'candidatures')

    // Scopé à la ligne d'Awa (pas la case « Tout sélectionner » de l'en-tête,
    // qui matcherait aussi une regex trop large sur /sélectionner/i).
    await page.getByRole('row', { name: /awa/i }).getByRole('checkbox').check()
    await page.getByRole('button', { name: /affecter \(1\)/i }).click()
    const dialog = page.getByRole('dialog', { name: 'Affecter à un évaluateur' })
    await expect(dialog).toBeVisible()
    await dialog.locator('select').selectOption({ label: "Solange N'Dri" })
    await shots(page, 'affectation')
    await dialog.getByRole('button', { name: 'Affecter' }).click()

    await expect(dialog).not.toBeVisible({ timeout: 30_000 })
    // La ligne reflète l'affectation SANS rechargement manuel de la page (scopé
    // à la cellule du tableau — le filtre « évaluateur » liste aussi ce nom).
    await expect(page.getByRole('cell', { name: "Solange N'Dri" })).toBeVisible({ timeout: 30_000 })
  })

  test('admin -> filières -> bascule actif/inactif (réel, PATCH admin)', async ({ page }) => {
    await login(page, 'admin@casa-demo.ci')
    await page.waitForURL(/\/admin$/, { timeout: 60_000 })
    await page.goto('/admin/filieres')
    await expect(page.getByRole('heading', { level: 2, name: 'Filières CQP' })).toBeVisible({ timeout: 30_000 })
    await expect(page.getByText('Active — candidatures ouvertes').first()).toBeVisible({ timeout: 30_000 })
    await shots(page, 'filieres')

    // Le switch (`.switch input[opacity:0]`) est cliqué via son <label> visible,
    // pas l'input caché directement — comportement natif du <label> HTML.
    await page.locator('label.switch').first().click()
    await expect(page.getByText('Inactive — « Actuellement fermé » côté public').first()).toBeVisible({ timeout: 30_000 })
  })

  test("admin -> audit -> consulte le journal (filière + affectation tracées)", async ({ page }) => {
    await login(page, 'admin@casa-demo.ci')
    await page.waitForURL(/\/admin$/, { timeout: 60_000 })
    await page.goto('/admin/audit')
    await expect(page.getByRole('heading', { level: 2, name: "Journal d'audit" })).toBeVisible({ timeout: 30_000 })
    await expect(page.getByText('Affectation évaluateur')).toBeVisible({ timeout: 30_000 })
    await shots(page, 'audit')

    await page.getByRole('combobox', { name: 'Module' }).selectOption('Filières')
    await expect(page.getByText('Désactivation de filière')).toBeVisible({ timeout: 30_000 })
    await expect(page.getByText('Affectation évaluateur')).toHaveCount(0)
  })
})
