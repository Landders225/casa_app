import { execSync } from 'node:child_process'
import { expect, test } from '@playwright/test'

/**
 * Lot 8c-1 — Espace ÉVALUATEUR : consultation & vérification.
 *
 * Réutilise `DemoClassementSeeder` (6 candidatures évaluées, verrouillées,
 * affectées à `eval-classement@casa-demo.ci`) plutôt qu'un script de seed dédié :
 * un simple UPDATE PSQL rouvre UN dossier (`en_instruction`, déverrouillé) pour
 * exercer le formulaire de vérification. Aucun acte irréversible ici (contraire
 * au 5b/8b-3) — `migrate:fresh` en fin reste une hygiène, pas une nécessité.
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
    await page.screenshot({ path: `e2e/__screenshots__/evaluateur-${name}-${w}.png`, fullPage: true, animations: 'disabled' })
  }
  await page.setViewportSize({ width: 1280, height: 800 })
}

test.describe.configure({ mode: 'serial', timeout: 240_000 })

test.describe('Espace évaluateur — consultation & vérification (Lot 8c-1)', () => {
  let koffiId

  test.beforeAll(() => {
    artisan('migrate:fresh --seed --force')
    artisan('db:seed --class=DemoClassementSeeder --force')
    koffiId = psql("select c.id from candidature c join candidat ca on ca.id = c.candidat_id where ca.prenom = 'Koffi'")
    // Rouvre CE dossier pour la vérification (les 6 de la seeder sont verrouillés/évalués).
    psql(`
      update candidature
      set statut_interne = 'en_instruction', dossier_verrouille = false,
          dossier_verrouille_le = null, dossier_verrouille_par = null
      where id = '${koffiId}';
    `)
  })

  test.afterAll(() => {
    artisan('migrate:fresh --seed --force')
  })

  test('candidat -> redirigé hors de l’espace évaluateur', async ({ page }) => {
    await login(page, 'candidat@casa-demo.ci')
    await page.waitForURL(/\/candidat$/, { timeout: 40_000 })
    await page.goto('/evaluateur/mes-dossiers')
    await expect(page).toHaveURL(/\/candidat$/)
    await expect(page.getByText('Mes dossiers')).toHaveCount(0)
  })

  test('évaluateur -> dashboard, liste ses dossiers, ouvre une fiche (zone 🔴 visible)', async ({ page }) => {
    await login(page, 'eval-classement@casa-demo.ci')
    await page.waitForURL(/\/evaluateur$/, { timeout: 60_000 })
    await expect(page.getByRole('heading', { name: /Bonjour Yann/i })).toBeVisible({ timeout: 30_000 })
    // Les 4 KPI (meta.total) chargent en parallèle après le heading — attendre
    // avant la capture, sinon on photographie le spinner.
    await expect(page.getByText('Total dossiers')).toBeVisible({ timeout: 30_000 })
    await shots(page, 'dashboard')

    await page.getByRole('link', { name: /traiter mes dossiers/i }).click()
    await expect(page).toHaveURL(/\/evaluateur\/mes-dossiers$/)
    await expect(page.getByRole('link', { name: 'Voir' }).first()).toBeVisible({ timeout: 30_000 })
    await shots(page, 'liste')

    await page.goto(`/evaluateur/candidatures/${koffiId}`)
    await expect(page.getByRole('heading', { name: 'Koffi Démo' })).toBeVisible({ timeout: 30_000 })
    // Zone 🔴, légitime ici : statut interne + éligibilité déjà calculée serveur.
    await expect(page.getByText('En cours d’instruction')).toBeVisible()
    await expect(page.getByText('Éligible')).toBeVisible()

    await page.getByRole('button', { name: 'Scolarité' }).click()
    await expect(page.getByText('Actuellement scolarisé(e) ?')).toBeVisible()
    await shots(page, 'fiche')
  })

  test('vérification diplome=CEPE -> critère éliminatoire reflété EN DIRECT', async ({ page }) => {
    await login(page, 'eval-classement@casa-demo.ci')
    await page.waitForURL(/\/evaluateur$/, { timeout: 60_000 })
    await page.goto(`/evaluateur/candidatures/${koffiId}`)
    await expect(page.getByRole('heading', { name: 'Koffi Démo' })).toBeVisible({ timeout: 30_000 })
    await expect(page.getByText('Éligible')).toBeVisible() // état AVANT

    await page.getByRole('button', { name: 'Vérification' }).click()
    await page.getByRole('radio', { name: 'CEPE' }).click()
    await shots(page, 'verification')
    await page.getByRole('button', { name: /enregistrer la vérification/i }).click()

    await expect(page.getByText('Vérification enregistrée.')).toBeVisible({ timeout: 30_000 })
    // Panneau Éligibilité — état APRÈS, tel que renvoyé par le PUT (pas recalculé) :
    await expect(page.getByText('Non éligible')).toBeVisible()
    await expect(page.getByText(/Plus haut diplôme confirmé = CEPE/)).toBeVisible()
    await expect(page.getByText('Vérification évaluateur')).toBeVisible()
    await shots(page, 'critere-reflete')
  })

  test('administrateur -> accède à la fiche évaluateur (recouvrement ADR-10)', async ({ page }) => {
    await login(page, 'admin@casa-demo.ci')
    await page.waitForURL(/\/admin$/, { timeout: 60_000 })

    await page.goto(`/evaluateur/candidatures/${koffiId}`)
    await expect(page.getByRole('heading', { name: 'Koffi Démo' })).toBeVisible({ timeout: 30_000 })
    // Navigation = espace évaluateur (où l'admin se trouve) — l'item de sidebar,
    // pas le lien de fil d'Ariane de la fiche qui porte le même libellé.
    await expect(page.getByRole('navigation', { name: 'Espace évaluateur' }).getByRole('link', { name: 'Mes dossiers' })).toBeVisible()
    // …identité = son vrai rôle (qui il est) :
    await expect(page.getByText('Administrateur')).toBeVisible()
    await shots(page, 'admin-recouvrement')

    await page.goto('/evaluateur/mes-dossiers')
    await expect(page.getByRole('heading', { level: 2, name: 'Tous les dossiers' })).toBeVisible({ timeout: 30_000 })
  })
})
