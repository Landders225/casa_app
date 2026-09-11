import { execSync } from 'node:child_process'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { expect, test } from '@playwright/test'

/**
 * Lot 14 — écran candidat « Documents » (`/candidat/documents`).
 *
 * Deux parcours réels :
 *  1. brouillon (jamais soumis) -> renvoi vers le wizard, pas de liste de pièces ;
 *  2. dossier SOUMIS (`DemoDocumentsSeeder` : 6 pièces + 1 justificatif
 *     d'expérience, de VRAIS fichiers sur le disque privé) -> liste + un vrai
 *     téléchargement (contenu du fichier vérifié, pas juste le clic).
 *
 * ⚠️ `DemoDocumentsSeeder` ÉCRIT DE VRAIS FICHIERS — doit tourner en `www-data`
 * (piège Docker-Windows déjà rencontré : un seeder lancé en root crée des
 * fichiers `0700 root:root` illisibles par PHP-FPM/www-data -> 404 "Fichier
 * introuvable" au téléchargement, alors que la ligne existe bien en base).
 */
const APP = fileURLToPath(new URL('../..', import.meta.url))
const artisan = (cmd, user = 'backend') =>
  execSync(`docker compose exec -T ${user} php artisan ${cmd}`, { cwd: APP, stdio: 'pipe' }).toString()
const artisanAsWwwData = (cmd) =>
  execSync(`docker compose exec -T -u www-data backend php artisan ${cmd}`, { cwd: APP, stdio: 'pipe' }).toString()

const PWD = 'Demo2026!'

async function login(page, email) {
  await page.goto('/connexion')
  await page.getByLabel('Adresse e-mail').fill(email)
  await page.getByLabel('Mot de passe', { exact: true }).fill(PWD)
  await page.getByRole('button', { name: /se connecter/i }).click()
  await page.waitForURL(/\/candidat$/, { timeout: 40_000 })
}

async function gotoDocuments(page) {
  await page.goto('/candidat/documents')
  await expect(page.locator('.app-content')).toBeVisible({ timeout: 90_000 })
}

async function shots(page, name) {
  for (const w of [390, 1280]) {
    await page.setViewportSize({ width: w, height: 900 })
    await page.evaluate(() => new Promise((r) => setTimeout(r, 120)))
    await page.screenshot({ path: `e2e/__screenshots__/documents-${name}-${w}.png`, fullPage: true, animations: 'disabled' })
  }
  await page.setViewportSize({ width: 1280, height: 800 })
}

test.describe.configure({ mode: 'serial' })

test.describe('Documents — écran candidat (Lot 14)', () => {
  test.beforeAll(() => {
    test.setTimeout(180_000)
    artisan('migrate:fresh --seed --force')
    artisanAsWwwData('db:seed --class=DemoDocumentsSeeder --force')
  })

  test.afterAll(() => {
    test.setTimeout(120_000)
    artisan('migrate:fresh --seed --force')
  })

  test('brouillon (candidature créée, pas encore soumise) -> renvoi vers le wizard, aucune liste de pièces', async ({ page }) => {
    const email = `e2e-docs-brouillon+${Date.now()}@example.ci`
    await page.goto('/inscription')
    await page.getByLabel('Prénom').fill('Fatim')
    await page.getByLabel('Nom', { exact: true }).fill('Docs')
    await page.getByLabel('Date de naissance').fill('2001-05-14')
    await page.getByLabel('Sexe').selectOption('F')
    await page.getByLabel('Numéro CNI / récépissé').fill('CI0099776655')
    await page.getByLabel('Ville de résidence').fill('Abidjan - Cocody')
    await page.getByLabel('Téléphone').fill('0709081013')
    await page.getByLabel('Adresse e-mail').fill(email)
    await page.getByLabel('Mot de passe', { exact: true }).fill('MotDePasse2026')
    await page.getByLabel('Confirmer le mot de passe').fill('MotDePasse2026')
    await page.getByLabel(/Je déclare résider en Côte d'Ivoire/).check()
    await page.getByLabel(/J'accepte les conditions/).check()
    await page.getByRole('button', { name: /Créer mon compte/i }).click()
    await expect(page).toHaveURL(/\/candidat$/, { timeout: 40_000 })

    // Crée un VRAI brouillon (pas juste "aucune candidature") : étapes 1-2 du
    // wizard (identité pré-remplie, puis filière + confirmation -> POST
    // /api/candidatures), patron repris de parcours-candidature.spec.js.
    await page.getByRole('link', { name: 'Commencer' }).click()
    await expect(page).toHaveURL(/\/candidat\/candidature$/)
    await expect(page.getByRole('heading', { name: /Vos informations personnelles/i })).toBeVisible({ timeout: 30_000 })
    await page.getByRole('button', { name: 'Suivant' }).click()
    await expect(page.getByText('Étape 2 / 10')).toBeVisible({ timeout: 60_000 })
    await page.getByRole('button', { name: /Agent de cuisine/ }).click()
    await page.getByLabel(/Je confirme/).check()
    await page.getByRole('button', { name: 'Suivant' }).click()
    await expect(page.getByText('Étape 3 / 10')).toBeVisible({ timeout: 60_000 }) // POST /api/candidatures a répondu

    await gotoDocuments(page)
    await expect(page.getByText(/se gèrent depuis votre dossier de candidature/i)).toBeVisible({ timeout: 30_000 })
    await shots(page, 'brouillon')

    const lien = page.getByRole('link', { name: /reprendre mon dossier/i })
    await expect(lien).toHaveAttribute('href', '/candidat/candidature')
    await expect(page.getByText(/déposées/i)).not.toBeVisible()
  })

  test('soumis -> liste les 6 pièces + 1 justificatif d\'expérience, téléchargement réel', async ({ page }) => {
    await login(page, 'documents@casa-demo.ci')
    await gotoDocuments(page)

    await expect(page.getByRole('heading', { level: 2, name: 'Documents' })).toBeVisible({ timeout: 30_000 })
    await expect(page.getByText('Votre dossier a été soumis')).toBeVisible()
    await expect(page.getByText('6/6 déposées')).toBeVisible()
    await expect(page.getByText('Hôtellerie · 6 à 12 mois')).toBeVisible()
    await shots(page, 'soumis')

    // Aucun bouton dépôt/suppression RENDU (pas juste désactivé).
    for (const nom of [/déposer/i, /retirer/i, /ajouter/i]) {
      await expect(page.getByRole('button', { name: nom })).toHaveCount(0)
    }
    await expect(page.locator('input[type="file"]')).toHaveCount(0)

    // Téléchargement RÉEL — clic du lien (session du navigateur, comme un vrai
    // candidat), pas un `page.request` séparé : le href est vérifié en plus,
    // mais la preuve porte sur le VRAI parcours utilisateur (clic -> fichier reçu).
    const lienCni = page.getByRole('link', { name: /cni\.pdf/i })
    await expect(lienCni).toHaveAttribute('href', /^\/api\/pieces\/[0-9a-f-]+\/download$/)

    const [telechargement] = await Promise.all([
      page.context().waitForEvent('download'),
      lienCni.click(),
    ])
    expect(telechargement.suggestedFilename()).toBe('cni.pdf')
    const chemin = await telechargement.path()
    const contenu = readFileSync(chemin, 'utf-8')
    expect(contenu).toContain('cni')
    expect(contenu).toContain('%PDF-1.4')
  })
})
