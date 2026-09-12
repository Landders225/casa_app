import { execSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'
import { expect, test } from '@playwright/test'

/**
 * Lot 8c-2 — Notation dossier /65 + entretien /35, verrouillage réel.
 *
 * Réutilise `DemoClassementSeeder` (Koffi, affecté à `eval-classement@casa-demo.ci`)
 * plutôt qu'un script de seed dédié : quelques UPDATE/DELETE PSQL réinitialisent
 * SON dossier (les 6 de la seeder sont déjà évalués/verrouillés) et posent une
 * vérification déjà faite (couverte en détail par l'E2E du Lot 8c-1 — pas
 * reproduite ici, cet E2E se concentre sur la NOTATION).
 *
 * Lot 15b — un 2ᵉ candidat de la même seeder (Mariam) prouve « planifier ->
 * MODIFIER la planification -> 2ᵉ mail réel (log SMTP) », VRAI parcours
 * navigateur (le bouton « Modifier la planification » est ce qui rend le
 * mécanisme serveur `EntretienReplanifie` réellement atteignable).
 */

// Racine du dépôt — portable (CI Linux comprise), plus de chemin Windows en dur.
const APP = fileURLToPath(new URL('../..', import.meta.url))
const artisan = (cmd) =>
  execSync(`docker compose exec -T backend php artisan ${cmd}`, { cwd: APP, stdio: 'pipe' }).toString()
const psql = (sql) =>
  execSync('docker compose exec -T postgres psql -U casa -d casa -tA -v ON_ERROR_STOP=1', {
    cwd: APP,
    input: sql,
    stdio: ['pipe', 'pipe', 'pipe'],
  }).toString().trim()
const backendLog = () =>
  execSync('docker compose exec -T backend sh -c "cat storage/logs/laravel.log 2>/dev/null || true"', { cwd: APP, stdio: 'pipe' }).toString()
const clearBackendLog = () =>
  execSync('docker compose exec -T backend sh -c "truncate -s 0 storage/logs/laravel.log 2>/dev/null || true"', { cwd: APP, stdio: 'pipe' })

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
    await page.screenshot({ path: `e2e/__screenshots__/notation-${name}-${w}.png`, fullPage: true, animations: 'disabled' })
  }
  await page.setViewportSize({ width: 1280, height: 800 })
}

test.describe.configure({ mode: 'serial', timeout: 240_000 })

test.describe('Notation dossier /65 + entretien /35 (Lot 8c-2)', () => {
  let koffiId
  let mariamId

  test.beforeAll(() => {
    artisan('migrate:fresh --seed --force')
    artisan('db:seed --class=DemoClassementSeeder --force')
    koffiId = psql("select c.id from candidature c join candidat ca on ca.id = c.candidat_id where ca.prenom = 'Koffi'")
    psql(`
      delete from note_sous_critere_entretien where entretien_id = '${koffiId}';
      delete from entretien where candidature_id = '${koffiId}';
      delete from score_rubrique_dossier where evaluation_dossier_id = '${koffiId}';
      delete from evaluation_dossier where candidature_id = '${koffiId}';
      update reponse_formulaire set mo04_note_etoiles = null where candidature_id = '${koffiId}';
      update candidature
        set statut_interne = 'en_instruction', dossier_verrouille = false,
            dossier_verrouille_le = null, dossier_verrouille_par = null
        where id = '${koffiId}';
      insert into verification_dossier (candidature_id, nationalite_confirmee, diplome_verifie, verifie_le)
        values ('${koffiId}', true, 'bac', now())
        on conflict (candidature_id) do update
        set nationalite_confirmee = true, diplome_verifie = 'bac', verifie_le = now();
    `)

    // Mariam (Lot 15b) — dossier déjà verrouillé par le seeder (inchangé), on
    // repart juste sans entretien (le seeder en crée un déjà `valide` pour les
    // 6 candidats) pour tester planification -> MODIFICATION -> 2e mail.
    mariamId = psql("select c.id from candidature c join candidat ca on ca.id = c.candidat_id where ca.prenom = 'Mariam'")
    psql(`
      delete from note_sous_critere_entretien where entretien_id = '${mariamId}';
      delete from entretien where candidature_id = '${mariamId}';
    `)
  })

  test.afterAll(() => {
    artisan('migrate:fresh --seed --force')
  })

  test('noter le dossier (étoiles + commentaire) -> valider -> verrouillage visuel réel', async ({ page }) => {
    await login(page, 'eval-classement@casa-demo.ci')
    await page.waitForURL(/\/evaluateur$/, { timeout: 60_000 })

    await page.goto(`/evaluateur/candidatures/${koffiId}`)
    await expect(page.getByRole('heading', { name: 'Koffi Démo' })).toBeVisible({ timeout: 30_000 })
    await page.getByRole('link', { name: 'Noter le dossier' }).click()
    await expect(page).toHaveURL(new RegExp(`/evaluateur/candidatures/${koffiId}/evaluation$`))

    // Aperçu initial — score déjà affiché depuis l'API (rubriques non notées par
    // l'évaluateur, MO.04 pas encore saisie).
    await expect(page.getByText('Score dossier')).toBeVisible({ timeout: 30_000 })
    await expect(page.getByRole('button', { name: 'Valider définitivement' })).toBeDisabled()
    await shots(page, 'dossier-apercu')

    await page.getByRole('button', { name: '4 étoiles' }).click()
    await page.getByPlaceholder(/observations de l'évaluateur/i).fill('Entretien de dossier convaincant.')
    await page.getByRole('button', { name: 'Enregistrer' }).click()
    await expect(page.getByText('Brouillon enregistré.')).toBeVisible({ timeout: 30_000 })
    // Le score affiché change — c'est l'aperçu RENVOYÉ PAR LE PUT, pas un calcul local.
    await expect(page.getByRole('button', { name: 'Valider définitivement' })).toBeEnabled()

    await page.getByRole('button', { name: 'Valider définitivement' }).click()
    await expect(page.getByRole('dialog', { name: 'Valider définitivement ?' })).toBeVisible()
    await shots(page, 'dossier-confirmation')
    await page.getByRole('dialog').getByRole('button', { name: 'Valider' }).click()

    // Verrouillage RÉEL : bannière, plus de boutons de saisie, champs nativement désactivés.
    await expect(page.getByText('🔒 ÉVALUATION VALIDÉE')).toBeVisible({ timeout: 30_000 })
    await expect(page.getByRole('button', { name: 'Enregistrer' })).toHaveCount(0)
    await expect(page.getByRole('button', { name: 'Valider définitivement' })).toHaveCount(0)
    await expect(page.getByRole('button', { name: '4 étoiles' })).toBeDisabled()
    await expect(page.getByPlaceholder(/observations de l'évaluateur/i)).toHaveAttribute('readonly', '')
    await shots(page, 'dossier-verrouille')
  })

  test('programmer + noter un entretien -> valider -> verrouillage visuel réel', async ({ page }) => {
    await login(page, 'eval-classement@casa-demo.ci')
    await page.waitForURL(/\/evaluateur$/, { timeout: 60_000 })

    await page.goto(`/evaluateur/candidatures/${koffiId}`)
    await expect(page.getByRole('heading', { name: 'Koffi Démo' })).toBeVisible({ timeout: 30_000 })
    await page.getByRole('link', { name: 'Entretien' }).click()
    await expect(page).toHaveURL(new RegExp(`/evaluateur/candidatures/${koffiId}/entretien$`))

    await expect(page.getByRole('heading', { name: "Planifier l'entretien" })).toBeVisible({ timeout: 30_000 })
    await page.getByLabel('Date').fill('2026-07-10')
    await page.getByLabel('Heure').fill('10:30')
    await page.getByLabel('Lieu').selectOption('2 Plateaux Vallons')
    await shots(page, 'entretien-planification')
    await page.getByRole('button', { name: /planifier l'entretien/i }).click()

    await expect(page.getByText('Présence')).toBeVisible({ timeout: 30_000 })
    await expect(page.getByRole('button', { name: 'Valider définitivement' })).toBeDisabled()
    await page.getByRole('button', { name: 'Présent' }).click()

    // Sous-note bornée par le max FOURNI PAR L'API — la plage n'est pas codée en dur.
    const presentationCard = page.locator('.rubrique-card').filter({ hasText: 'Présentation' })
    await presentationCard.getByRole('radiogroup').first().getByRole('button', { name: '3', exact: true }).click()
    await shots(page, 'entretien-notation')
    await page.getByRole('button', { name: 'Enregistrer' }).click()
    await expect(page.getByText('Entretien enregistré.')).toBeVisible({ timeout: 30_000 })

    await expect(page.getByRole('button', { name: 'Valider définitivement' })).toBeEnabled()
    await page.getByRole('button', { name: 'Valider définitivement' }).click()
    await expect(page.getByRole('dialog', { name: 'Valider définitivement ?' })).toBeVisible()
    await page.getByRole('dialog').getByRole('button', { name: 'Valider' }).click()

    await expect(page.getByText('🔒 ENTRETIEN VALIDÉ')).toBeVisible({ timeout: 30_000 })
    await expect(page.getByRole('button', { name: 'Enregistrer' })).toHaveCount(0)
    await expect(page.getByRole('button', { name: 'Valider définitivement' })).toHaveCount(0)
    await expect(page.getByRole('button', { name: 'Présent' })).toBeDisabled()
    await shots(page, 'entretien-verrouille')
  })

  test('modifier la planification (Lot 15b) -> 2e mail réel, notation non affectée', async ({ page }) => {
    clearBackendLog()
    await login(page, 'eval-classement@casa-demo.ci')
    await page.waitForURL(/\/evaluateur$/, { timeout: 60_000 })

    await page.goto(`/evaluateur/candidatures/${mariamId}/entretien`)
    await expect(page.getByRole('heading', { name: "Planifier l'entretien" })).toBeVisible({ timeout: 30_000 })
    await page.getByLabel('Date').fill('2026-07-15')
    await page.getByLabel('Heure').fill('11:00')
    await page.getByLabel('Lieu').selectOption('Le Plateau')
    await page.getByRole('button', { name: /planifier l'entretien/i }).click()
    await expect(page.getByText('Présence')).toBeVisible({ timeout: 30_000 })

    // Aucune UI ne permettait de modifier la planification avant ce lot —
    // c'est ce bouton qui rend EntretienReplanifie réellement atteignable.
    await page.getByRole('button', { name: /modifier la planification/i }).click()
    await expect(page.getByRole('heading', { name: 'Modifier la planification' })).toBeVisible({ timeout: 30_000 })
    // Pré-rempli avec les valeurs ACTUELLES, pas un formulaire vide.
    await expect(page.getByLabel('Date')).toHaveValue('2026-07-15')
    await expect(page.getByLabel('Heure')).toHaveValue('11:00')
    await expect(page.getByLabel('Lieu')).toHaveValue('Le Plateau')

    await page.getByLabel('Date').fill('2026-07-20')
    await page.getByLabel('Heure').fill('16:30')
    await page.getByLabel('Lieu').selectOption('2 Plateaux Vallons')
    await shots(page, 'entretien-replanification')
    await page.getByRole('button', { name: /enregistrer la nouvelle planification/i }).click()

    // Retour à la notation — le flux existant n'est pas cassé par la modification.
    await expect(page.getByText('Présence')).toBeVisible({ timeout: 30_000 })
    await expect(page.getByText('2 Plateaux Vallons')).toBeVisible()
    await page.getByRole('button', { name: 'Présent' }).click()
    await page.getByRole('button', { name: 'Enregistrer' }).click()
    await expect(page.getByText('Entretien enregistré.')).toBeVisible({ timeout: 30_000 })

    // Notifications ShouldQueue : dev n'a pas de service `worker` (Lot 12a —
    // réservé à prod), on draine la file explicitement, comme profil-mot-de-passe.spec.js.
    artisan('queue:work --stop-when-empty')

    // Preuve réelle du 2e mail (MAIL_MAILER=log) — pas EntretienPlanifie une 2e fois.
    const logs = backendLog()
    expect(logs).toContain('Modification de votre entretien')
    expect(logs).toContain('20/07/2026')
    expect(logs).toContain('16:30')
    expect(logs).toContain('2 Plateaux Vallons')
  })
})
