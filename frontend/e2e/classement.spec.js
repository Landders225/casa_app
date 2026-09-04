import { execSync } from 'node:child_process'
import { expect, test } from '@playwright/test'

/**
 * Lot 8d-2 — Classement & publication. BASE JETABLE : la publication est
 * IRRÉVERSIBLE (comme au 8b-3) — `migrate:fresh --seed` avant ET après.
 *
 * Réutilise `DemoClassementSeeder` (6 candidatures évaluées, filière cuisine,
 * campagne "Cohorte 1 — 2026") — dont Sekou, `non_eligible`, qui donne un
 * `non_retenu` réel sans avoir à épuiser un quota (quota=24 > 6 candidats).
 */

const APP = 'c:/Users/isaacaka/Desktop/CCI/Projet Arbre de Vie/casa-app'
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
    await page.evaluate(() => new Promise((r) => setTimeout(r, 120)))
    // eslint-disable-next-line no-await-in-loop
    await page.screenshot({ path: `e2e/__screenshots__/classement-${name}-${w}.png`, fullPage: true, animations: 'disabled' })
  }
  await page.setViewportSize({ width: 1280, height: 800 })
}

test.describe.configure({ mode: 'serial', timeout: 240_000 })

test.describe('Classement & publication (Lot 8d-2)', () => {
  test.beforeAll(() => {
    artisan('migrate:fresh --seed --force')
    artisan('db:seed --class=DemoClassementSeeder --force')
  })

  test.afterAll(() => {
    artisan('migrate:fresh --seed --force')
  })

  test('calculer -> saisir un motif -> publier (avec friction) -> état publié', async ({ page }) => {
    await login(page, 'admin@casa-demo.ci')
    await page.waitForURL(/\/admin$/, { timeout: 60_000 })

    await page.goto('/admin/campagnes')
    await expect(page.getByRole('heading', { level: 2, name: 'Campagnes de candidature' })).toBeVisible({ timeout: 30_000 })
    await page.getByRole('link', { name: 'Voir le classement' }).click()
    await expect(page.getByRole('heading', { level: 2, name: 'Classement des candidats' })).toBeVisible({ timeout: 30_000 })

    // --- Calcul (idempotent tant que non publié) ---
    await expect(page.getByText('Aucun classement calculé')).toBeVisible({ timeout: 30_000 })
    await shots(page, 'vide')
    await page.getByRole('button', { name: 'Calculer le classement' }).click()
    // Les 6 candidats de la seeder sont tous en filière cuisine — pas l'onglet
    // actif par défaut (le premier de la campagne, "Accueil-réception").
    await page.getByRole('button', { name: 'Agent de cuisine' }).click()
    await expect(page.getByRole('cell', { name: 'Sekou Démo' })).toBeVisible({ timeout: 30_000 })
    // Le score final, le rang et le départage viennent de l'API, pas d'un calcul local.
    await expect(page.getByText('Non retenu')).toBeVisible()
    await expect(page.getByRole('button', { name: 'Recalculer le classement' })).toBeVisible()
    await shots(page, 'calcule')

    // --- Motif (Sekou est non_retenu -> bouton visible) ---
    const ligneSekou = page.getByRole('row', { name: /sekou/i })
    await ligneSekou.getByRole('button', { name: 'Motif' }).click()
    const motifDialog = page.getByRole('dialog', { name: 'Motif de la décision' })
    await expect(motifDialog).toBeVisible()
    await expect(motifDialog.getByText(/🔴 Motif interne/)).toBeVisible()
    await expect(motifDialog.getByText(/🟡 Motif communicable/)).toBeVisible()
    await page.getByPlaceholder('Note interne, jamais visible du candidat...').fill('Non éligible — hors tranche d’âge au moment du contrôle.')
    await page.getByPlaceholder('Laisser vide pour le message générique par défaut...').fill('Nous vous invitons à candidater lors d’une prochaine cohorte.')
    await shots(page, 'motif')
    await motifDialog.getByRole('button', { name: 'Enregistrer' }).click()
    await expect(motifDialog).not.toBeVisible({ timeout: 30_000 })

    // --- Publication : friction délibérée (nom exact requis) ---
    await page.getByRole('button', { name: /publier les résultats/i }).click()
    const publishDialog = page.getByRole('dialog', { name: 'Publier les résultats' })
    await expect(publishDialog).toBeVisible()
    const confirmBtn = publishDialog.getByRole('button', { name: 'Publier définitivement' })
    await expect(confirmBtn).toBeDisabled()
    await publishDialog.getByRole('textbox').fill('nom incorrect')
    await expect(confirmBtn).toBeDisabled()
    await publishDialog.getByRole('textbox').fill('')
    await publishDialog.getByRole('textbox').fill('Cohorte 1 — 2026')
    await expect(confirmBtn).toBeEnabled()
    await shots(page, 'confirmation-publication')
    await confirmBtn.click()

    // --- État "déjà publié" : consultation stricte ---
    await expect(page.getByText('🔒 RÉSULTATS PUBLIÉS')).toBeVisible({ timeout: 30_000 })
    await expect(page.getByText(/publiés le .* par prisca yéo/i)).toBeVisible()
    await expect(page.getByRole('button', { name: /calculer/i })).toHaveCount(0)
    await expect(page.getByRole('button', { name: /publier/i })).toHaveCount(0)
    await expect(page.getByRole('button', { name: 'Motif' })).toHaveCount(0)
    // Le classement reste visible (consultation, pas un masquage).
    await expect(page.getByRole('cell', { name: 'Sekou Démo' })).toBeVisible()
    await shots(page, 'publie')

    // Un rechargement de page (F5) doit encore montrer la traçabilité (Étape 1 Q3).
    await page.reload()
    await expect(page.getByText('🔒 RÉSULTATS PUBLIÉS')).toBeVisible({ timeout: 30_000 })
    await expect(page.getByText(/publiés le .* par prisca yéo/i)).toBeVisible()
  })
})
