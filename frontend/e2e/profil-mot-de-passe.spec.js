import { execSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'
import { expect, test } from '@playwright/test'

/**
 * Lot 13 — Espace candidat : Mon profil + mot de passe (connecté + oublié).
 *
 * Parcours réel de bout en bout contre la stack Docker :
 *   candidat corrige son téléphone (persiste après F5) → change son mot de
 *   passe (ancien refusé, nouveau accepté) → « mot de passe oublié » (e-mail
 *   MAIL_MAILER=log, on lit le lien dans les logs backend) → écran nouveau
 *   mot de passe → login avec le mot de passe réinitialisé.
 */

const APP = fileURLToPath(new URL('../..', import.meta.url))
const artisan = (cmd) =>
  execSync(`docker compose exec -T backend php artisan ${cmd}`, { cwd: APP, stdio: 'pipe' }).toString()
const backendLog = () =>
  execSync('docker compose exec -T backend sh -c "cat storage/logs/laravel.log 2>/dev/null || true"', { cwd: APP, stdio: 'pipe' }).toString()
const clearBackendLog = () =>
  execSync('docker compose exec -T backend sh -c "truncate -s 0 storage/logs/laravel.log 2>/dev/null || true"', { cwd: APP, stdio: 'pipe' })

const PWD = 'Demo2026!'
const CANDIDAT_EMAIL = 'candidat@casa-demo.ci'

async function login(page, email, password) {
  await page.goto('/connexion')
  await page.getByLabel('Adresse e-mail').fill(email)
  await page.getByLabel('Mot de passe', { exact: true }).fill(password)
  await page.getByRole('button', { name: /se connecter/i }).click()
}

async function logout(page) {
  await page.getByRole('button', { name: /se déconnecter/i }).click()
  await page.waitForURL(/\/connexion$/, { timeout: 60_000 })
}

async function shots(page, name) {
  for (const w of [390, 1280]) {
    // eslint-disable-next-line no-await-in-loop
    await page.setViewportSize({ width: w, height: 900 })
    // eslint-disable-next-line no-await-in-loop
    await page.evaluate(() => new Promise((r) => setTimeout(r, 150)))
    // eslint-disable-next-line no-await-in-loop
    await page.screenshot({ path: `e2e/__screenshots__/profil-mdp-${name}-${w}.png`, fullPage: true, animations: 'disabled' })
  }
  await page.setViewportSize({ width: 1280, height: 800 })
}

/** Extrait le lien de réinitialisation le PLUS RÉCENT écrit dans les logs (transport `log`). */
function dernierLienReset(log) {
  const matches = [...log.matchAll(/https?:\/\/[^\s]+\/mot-de-passe\/nouveau\?token=[^\s]+/g)]
  if (matches.length === 0) throw new Error('Aucun lien de réinitialisation trouvé dans les logs backend.')
  return matches.at(-1)[0]
}

test.describe.configure({ mode: 'serial', timeout: 240_000 })

test.describe('Espace candidat — Mon profil & mot de passe (Lot 13)', () => {
  test.beforeAll(() => {
    artisan('migrate:fresh --seed --force')
  })
  test.afterAll(() => {
    artisan('migrate:fresh --seed --force')
  })

  test('candidat -> Mon profil : corrige son téléphone, persiste après F5', async ({ page }) => {
    await login(page, CANDIDAT_EMAIL, PWD)
    await page.waitForURL(/\/candidat$/, { timeout: 60_000 })
    await page.goto('/candidat/profil')

    await expect(page.getByRole('heading', { level: 2, name: 'Mon profil' })).toBeVisible({ timeout: 30_000 })
    // E-mail en lecture seule.
    await expect(page.getByLabel('E-mail')).toBeDisabled()
    await shots(page, 'ecran')

    const telephone = page.getByLabel('Téléphone')
    await telephone.fill('0708091011')
    await page.getByRole('button', { name: /enregistrer les modifications/i }).click()
    await expect(page.getByText('Votre profil a été mis à jour.')).toBeVisible({ timeout: 30_000 })

    await page.reload()
    await expect(page.getByLabel('Téléphone')).toHaveValue('0708091011', { timeout: 30_000 })
  })

  test('candidat -> change son mot de passe : ancien refusé, nouveau accepté', async ({ page }) => {
    await login(page, CANDIDAT_EMAIL, PWD)
    await page.waitForURL(/\/candidat$/, { timeout: 60_000 })
    await page.goto('/candidat/profil')
    await expect(page.getByRole('heading', { name: 'Sécurité' })).toBeVisible({ timeout: 30_000 })

    await page.getByLabel('Mot de passe actuel').fill(PWD)
    await page.getByLabel('Nouveau mot de passe', { exact: true }).fill('MdpChange2026')
    await page.getByLabel('Confirmer le nouveau mot de passe').fill('MdpChange2026')
    await shots(page, 'changement-mdp')
    await page.getByRole('button', { name: /changer mon mot de passe/i }).click()
    await expect(page.getByText(/mot de passe a été modifié/i)).toBeVisible({ timeout: 30_000 })

    await logout(page)
    await login(page, CANDIDAT_EMAIL, PWD) // ancien
    await expect(page.getByText(/e-mail ou mot de passe incorrect/i)).toBeVisible({ timeout: 30_000 })

    await login(page, CANDIDAT_EMAIL, 'MdpChange2026') // nouveau
    await page.waitForURL(/\/candidat$/, { timeout: 60_000 })
  })

  test('mot de passe oublié -> lien reçu (log) -> nouveau mot de passe -> login', async ({ page }) => {
    clearBackendLog()

    await page.goto('/connexion')
    await page.getByRole('link', { name: /mot de passe oublié/i }).click()
    await expect(page.getByRole('heading', { name: /réinitialiser mon mot de passe/i })).toBeVisible({ timeout: 30_000 })

    await page.getByLabel('Adresse e-mail').fill(CANDIDAT_EMAIL)
    await shots(page, 'oubli')
    await page.getByRole('button', { name: /envoyer le lien/i }).click()
    await expect(page.getByText(/si un compte existe pour cette adresse/i)).toBeVisible({ timeout: 30_000 })

    // Dev : pas de worker (Lot 12a — le service `worker` n'existe qu'en prod).
    // La notification ShouldQueue attend en file : on la traite une fois.
    artisan('queue:work --stop-when-empty')

    // Lien lu dans les logs backend (MAIL_MAILER=log) — jamais dans les logs nginx
    // (exclus par `access_log off`, cf. docker/nginx/*.conf).
    const lien = dernierLienReset(backendLog())
    const url = new URL(lien)

    await page.goto(url.pathname + url.search)
    await expect(page.getByRole('heading', { name: /choisir un nouveau mot de passe/i })).toBeVisible({ timeout: 30_000 })
    await page.getByLabel('Nouveau mot de passe', { exact: true }).fill('MdpOublie2026')
    await page.getByLabel('Confirmer le nouveau mot de passe').fill('MdpOublie2026')
    await shots(page, 'nouveau')
    await page.getByRole('button', { name: /réinitialiser mon mot de passe/i }).click()

    await page.waitForURL(/\/connexion$/, { timeout: 30_000 })
    await expect(page.getByText(/mot de passe a été réinitialisé/i)).toBeVisible({ timeout: 30_000 })

    await login(page, CANDIDAT_EMAIL, 'MdpOublie2026')
    await page.waitForURL(/\/candidat$/, { timeout: 60_000 })
  })

  test('un lien de réinitialisation déjà utilisé est refusé', async ({ page }) => {
    clearBackendLog()
    await page.goto('/mot-de-passe/oublie')
    await page.getByLabel('Adresse e-mail').fill(CANDIDAT_EMAIL)
    await page.getByRole('button', { name: /envoyer le lien/i }).click()
    await expect(page.getByText(/si un compte existe pour cette adresse/i)).toBeVisible({ timeout: 30_000 })
    artisan('queue:work --stop-when-empty')

    const lien = dernierLienReset(backendLog())
    const url = new URL(lien)

    // 1er usage : succès.
    await page.goto(url.pathname + url.search)
    await page.getByLabel('Nouveau mot de passe', { exact: true }).fill('EncoreUnAutre2026')
    await page.getByLabel('Confirmer le nouveau mot de passe').fill('EncoreUnAutre2026')
    await page.getByRole('button', { name: /réinitialiser mon mot de passe/i }).click()
    await page.waitForURL(/\/connexion$/, { timeout: 30_000 })

    // 2e usage du MÊME lien : refusé, message générique.
    await page.goto(url.pathname + url.search)
    await page.getByLabel('Nouveau mot de passe', { exact: true }).fill('TroisiemeTentative2026')
    await page.getByLabel('Confirmer le nouveau mot de passe').fill('TroisiemeTentative2026')
    await page.getByRole('button', { name: /réinitialiser mon mot de passe/i }).click()
    await expect(page.getByText(/lien de réinitialisation est invalide ou a expiré/i)).toBeVisible({ timeout: 30_000 })
  })
})
