import { execSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'
import { expect, test } from '@playwright/test'

/**
 * Lot 15a — « Mon compte » (`/equipe/mon-compte`), self-service mot de passe
 * partagé évaluateur + administrateur. Un seul écran, un seul composant : la
 * preuve E2E porte sur l'évaluateur (le chemin admin est identique — même
 * composant, même endpoint — et déjà prouvé côté backend pour les deux rôles,
 * cf. `Equipe/ChangementMotDePasseTest` avec dataProvider).
 *
 * ⚠️ Base JETABLE : change réellement le mot de passe de `evaluateur@casa-demo.ci`.
 * `migrate:fresh --seed` avant/après.
 */
const APP = fileURLToPath(new URL('../..', import.meta.url))
const artisan = (cmd) =>
  execSync(`docker compose exec -T backend php artisan ${cmd}`, { cwd: APP, stdio: 'pipe' }).toString()

const PWD = 'Demo2026!'
const NOUVEAU = 'NouveauMdpE2E2026'

async function login(page, email, password) {
  await page.goto('/connexion')
  await page.getByLabel('Adresse e-mail').fill(email)
  await page.getByLabel('Mot de passe', { exact: true }).fill(password)
  await page.getByRole('button', { name: /se connecter/i }).click()
}

async function shots(page, name) {
  for (const w of [390, 1280]) {
    // eslint-disable-next-line no-await-in-loop
    await page.setViewportSize({ width: w, height: 900 })
    // eslint-disable-next-line no-await-in-loop
    await page.evaluate(() => new Promise((r) => setTimeout(r, 120)))
    // eslint-disable-next-line no-await-in-loop
    await page.screenshot({ path: `e2e/__screenshots__/mon-compte-${name}-${w}.png`, fullPage: true, animations: 'disabled' })
  }
  await page.setViewportSize({ width: 1280, height: 800 })
}

test.describe.configure({ mode: 'serial', timeout: 240_000 })

test.describe('Mon compte — self-service équipe (Lot 15a)', () => {
  test.beforeAll(() => {
    artisan('migrate:fresh --seed --force')
  })
  test.afterAll(() => {
    artisan('migrate:fresh --seed --force')
  })

  test('évaluateur : identité en lecture seule + changement de mot de passe réel', async ({ page }) => {
    await login(page, 'evaluateur@casa-demo.ci', PWD)
    await page.waitForURL(/\/evaluateur$/, { timeout: 60_000 })

    await page.goto('/equipe/mon-compte')
    await expect(page.getByRole('heading', { level: 2, name: 'Mon compte' })).toBeVisible({ timeout: 30_000 })

    // Identité en lecture seule — aucun champ éditable dans cette carte.
    await expect(page.getByLabel('Prénom')).toBeDisabled()
    await expect(page.getByLabel('E-mail')).toHaveValue('evaluateur@casa-demo.ci')
    await expect(page.getByLabel('Rôle')).toHaveValue('Évaluateur')
    await shots(page, 'ecran')

    await page.getByLabel('Mot de passe actuel').fill(PWD)
    await page.getByLabel('Nouveau mot de passe', { exact: true }).fill(NOUVEAU)
    await page.getByLabel('Confirmer le nouveau mot de passe').fill(NOUVEAU)
    await page.getByRole('button', { name: /changer mon mot de passe/i }).click()
    await expect(page.getByText(/mot de passe a été modifié/i)).toBeVisible({ timeout: 30_000 })

    await page.getByRole('button', { name: /se déconnecter/i }).click()
    await page.waitForURL(/\/connexion$/, { timeout: 60_000 })

    // Ancien mot de passe refusé, nouveau accepté.
    await login(page, 'evaluateur@casa-demo.ci', PWD)
    await expect(page.getByText(/e-mail ou mot de passe incorrect/i)).toBeVisible({ timeout: 30_000 })
    await login(page, 'evaluateur@casa-demo.ci', NOUVEAU)
    await page.waitForURL(/\/evaluateur$/, { timeout: 60_000 })
  })
})
