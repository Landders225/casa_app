import { execSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'
import { expect, test } from '@playwright/test'

/**
 * Lot 11b — Espace ADMINISTRATEUR : écran « Équipe » (gestion des comptes).
 *
 * Parcours réel de bout en bout contre la stack Docker :
 *   admin crée un évaluateur → le nouvel évaluateur SE CONNECTE avec le mot de
 *   passe provisoire → l'admin le DÉSACTIVE → l'évaluateur PERD L'ACCÈS (login
 *   refusé) → l'admin RÉINITIALISE → nouveau mot de passe, nouveau login.
 *
 * `ComptesDemoSeeder` fournit `admin@casa-demo.ci` (Prisca Yéo, mot de passe
 * `Demo2026!`). L'e-mail du membre créé est horodaté (unique par exécution).
 */

const APP = fileURLToPath(new URL('../..', import.meta.url))
const artisan = (cmd) =>
  execSync(`docker compose exec -T backend php artisan ${cmd}`, { cwd: APP, stdio: 'pipe' }).toString()

const PWD = 'Demo2026!'
const NEW_EMAIL = `jury.e2e.${Date.now()}@cci.ci`

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
    await page.evaluate(() => new Promise((r) => setTimeout(r, 120)))
    // eslint-disable-next-line no-await-in-loop
    await page.screenshot({ path: `e2e/__screenshots__/equipe-${name}-${w}.png`, fullPage: true, animations: 'disabled' })
  }
  await page.setViewportSize({ width: 1280, height: 800 })
}

test.describe.configure({ mode: 'serial', timeout: 240_000 })

test.describe('Espace administrateur — écran « Équipe » (Lot 11b)', () => {
  let motDePasseProvisoire

  test.beforeAll(() => {
    artisan('migrate:fresh --seed --force')
  })
  test.afterAll(() => {
    artisan('migrate:fresh --seed --force')
  })

  test('évaluateur → redirigé hors de /admin/equipe', async ({ page }) => {
    await login(page, 'evaluateur@casa-demo.ci', PWD)
    await page.waitForURL(/\/evaluateur$/, { timeout: 60_000 })
    await page.goto('/admin/equipe')
    await expect(page).toHaveURL(/\/evaluateur$/)
  })

  test('admin crée un évaluateur → mot de passe provisoire affiché une fois', async ({ page }) => {
    await login(page, 'admin@casa-demo.ci', PWD)
    await page.waitForURL(/\/admin$/, { timeout: 60_000 })
    await page.goto('/admin/equipe')
    await expect(page.getByRole('heading', { level: 2, name: 'Équipe du projet' })).toBeVisible({ timeout: 30_000 })
    // Le seed liste au moins l'admin et l'évaluateur de démo.
    await expect(page.getByText('evaluateur@casa-demo.ci')).toBeVisible({ timeout: 30_000 })
    await shots(page, 'liste')

    await page.getByRole('button', { name: /ajouter un membre/i }).click()
    const dialog = page.getByRole('dialog', { name: /ajouter un membre/i })
    await dialog.getByLabel('Prénom', { exact: true }).fill('Amina')
    await dialog.getByLabel('Nom', { exact: true }).fill('Cissé')
    await dialog.getByLabel(/adresse e-mail/i).fill(NEW_EMAIL)
    await dialog.getByLabel('Poste', { exact: true }).fill('Jury filière couture')
    // Le select ne propose que Évaluateur / Administrateur.
    await expect(dialog.getByLabel('Rôle', { exact: true }).locator('option')).toHaveText(['Évaluateur', 'Administrateur'])
    await shots(page, 'formulaire')
    await dialog.getByRole('button', { name: /créer le compte/i }).click()

    const pwdDialog = page.getByRole('dialog', { name: /compte créé/i })
    await expect(pwdDialog).toBeVisible({ timeout: 30_000 })
    await expect(pwdDialog.getByText(/plus jamais affiché/i)).toBeVisible()
    motDePasseProvisoire = (await pwdDialog.getByLabel('Mot de passe provisoire').textContent()).trim()
    expect(motDePasseProvisoire).toMatch(/^[A-Za-z2-9]{12,}$/)
    await shots(page, 'mot-de-passe')
    await pwdDialog.getByRole('button', { name: /j'ai noté/i }).click()

    await expect(page.getByText(NEW_EMAIL)).toBeVisible({ timeout: 30_000 })
  })

  test('le nouvel évaluateur se connecte avec le mot de passe provisoire', async ({ page }) => {
    await login(page, NEW_EMAIL, motDePasseProvisoire)
    await page.waitForURL(/\/evaluateur$/, { timeout: 60_000 })
    await expect(page).toHaveURL(/\/evaluateur$/)
    await logout(page)
  })

  test('admin désactive le compte → l\'évaluateur perd l\'accès', async ({ page }) => {
    await login(page, 'admin@casa-demo.ci', PWD)
    await page.waitForURL(/\/admin$/, { timeout: 60_000 })
    await page.goto('/admin/equipe')

    const ligne = page.getByRole('row', { name: new RegExp(NEW_EMAIL, 'i') })
    await ligne.getByRole('button', { name: 'Désactiver' }).click()
    const confirm = page.getByRole('dialog', { name: /désactiver amina cissé/i })
    await confirm.getByRole('button', { name: 'Désactiver' }).click()
    await expect(ligne.getByText('Désactivé')).toBeVisible({ timeout: 30_000 })
    await shots(page, 'desactive')
    await logout(page)

    // Login désormais refusé (message générique).
    await login(page, NEW_EMAIL, motDePasseProvisoire)
    await expect(page.getByText(/e-mail ou mot de passe incorrect/i)).toBeVisible({ timeout: 30_000 })
  })

  test('admin réinitialise le mot de passe → nouveau mot de passe fonctionnel', async ({ page }) => {
    await login(page, 'admin@casa-demo.ci', PWD)
    await page.waitForURL(/\/admin$/, { timeout: 60_000 })
    await page.goto('/admin/equipe')

    const ligne = page.getByRole('row', { name: new RegExp(NEW_EMAIL, 'i') })
    // Réactiver d'abord, pour pouvoir se reconnecter ensuite.
    await ligne.getByRole('button', { name: 'Réactiver' }).click()
    await page.getByRole('dialog').getByRole('button', { name: 'Réactiver' }).click()
    await expect(ligne.getByText('Actif')).toBeVisible({ timeout: 30_000 })

    await ligne.getByRole('button', { name: /réinitialiser le mot de passe/i }).click()
    await page.getByRole('dialog', { name: /réinitialiser le mot de passe/i }).getByRole('button', { name: 'Réinitialiser' }).click()
    const pwdDialog = page.getByRole('dialog', { name: /mot de passe réinitialisé/i })
    await expect(pwdDialog).toBeVisible({ timeout: 30_000 })
    const nouveau = (await pwdDialog.getByLabel('Mot de passe provisoire').textContent()).trim()
    expect(nouveau).not.toBe(motDePasseProvisoire)
    await pwdDialog.getByRole('button', { name: /j'ai noté/i }).click()
    await logout(page)

    await login(page, NEW_EMAIL, nouveau)
    await page.waitForURL(/\/evaluateur$/, { timeout: 60_000 })
    await expect(page).toHaveURL(/\/evaluateur$/)
  })

  test('admin corrige l\'identité (Lot 15a) — poste mis à jour, rôle/e-mail absents du formulaire', async ({ page }) => {
    await login(page, 'admin@casa-demo.ci', PWD)
    await page.waitForURL(/\/admin$/, { timeout: 60_000 })
    await page.goto('/admin/equipe')

    const ligne = page.getByRole('row', { name: new RegExp(NEW_EMAIL, 'i') })
    await ligne.getByRole('button', { name: 'Modifier' }).click()

    const dialog = page.getByRole('dialog', { name: /modifier amina cissé/i })
    await expect(dialog.getByLabel('Prénom')).toHaveValue('Amina')
    // Ni rôle ni e-mail dans ce formulaire — pas de porte dérobée possible.
    await expect(dialog.getByLabel(/rôle/i)).toHaveCount(0)
    await expect(dialog.getByLabel(/e-mail/i)).toHaveCount(0)
    await shots(page, 'edition-identite')

    await dialog.getByLabel('Poste', { exact: true }).fill('Jury filière couture — référente')
    await dialog.getByRole('button', { name: /enregistrer/i }).click()

    await expect(dialog).not.toBeVisible({ timeout: 30_000 })
    await expect(ligne.getByText('Jury filière couture — référente')).toBeVisible({ timeout: 30_000 })
  })
})
