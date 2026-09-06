import { execSync } from 'node:child_process'
import { expect, test } from '@playwright/test'

/**
 * Lot 8d-3 — Actes exceptionnels : correction, remplacement, élimination.
 * BASE JETABLE : le remplacement n'a de sens qu'après une PUBLICATION
 * irréversible (comme au 8b-3/8d-2) — `migrate:fresh --seed` avant ET après.
 *
 * Réutilise `DemoClassementSeeder` (6 candidatures évaluées, filière cuisine,
 * campagne "Cohorte 1 — 2026") — dossier et entretien déjà VERROUILLÉS
 * (terrain nécessaire pour tester une correction, qui est la SEULE opération
 * pouvant les rouvrir). Le quota de la filière est réduit à 2 par UPDATE SQL
 * direct (la seeder en donne 24, largement au-dessus des 5 éligibles — sans
 * ce réglage, personne ne serait en liste d'attente et le remplacement
 * n'aurait personne à promouvoir).
 *
 * Classement attendu une fois calculé (quota=2, départage D-5a-1) :
 *   Koffi 88.0 (retenu #1) · Awa 85.0 (retenu #2) · Mariam 82.0 (attente #3) ·
 *   Fatou 60.0 (attente #4, F devant à égalité) · Ismael 60.0 (attente #5) ·
 *   Sekou non_eligible (non_retenu, hors classement par quota).
 *
 * Les corrections exceptionnelles portent sur FATOU (pas Awa ni Mariam) :
 * `EvaluationDossier`/`Entretien` chez `DemoClassementSeeder` posent un
 * `score_total` LITTÉRAL, découplé des réponses réelles (la plupart nulles,
 * seules quelques-unes forcées) — une correction RECALCULE réellement depuis
 * ces réponses (ADR-04), donc le score corrigé s'effondre vers ce que les
 * données réelles donnent, sans rapport avec le score de démonstration.
 * Fatou (liste d'attente #4, sous Mariam) est hors de toute assertion du test
 * de remplacement : ce recalcul ne peut donc pas perturber le classement
 * Koffi/Awa/Mariam qu'il vérifie.
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
    await page.screenshot({ path: `e2e/__screenshots__/actes-${name}-${w}.png`, fullPage: true, animations: 'disabled' })
  }
  await page.setViewportSize({ width: 1280, height: 800 })
}

test.describe.configure({ mode: 'serial', timeout: 240_000 })

test.describe('Actes exceptionnels — correction, remplacement, élimination (Lot 8d-3)', () => {
  // Seule Fatou est atteinte par URL directe (écrans de notation) ; le remplacement
  // et l'élimination ciblent leurs candidats par le TEXTE de ligne (« Awa Démo »,
  // « Ismael Démo », « Sekou Démo »), pas par id.
  let fatouId

  test.beforeAll(() => {
    artisan('migrate:fresh --seed --force')
    artisan('db:seed --class=DemoClassementSeeder --force')
    psql(`
      update campagne_filiere set quota = 2
      where filiere_id = (select id from filiere where code = 'cuisine')
        and campagne_id = (select id from campagne where statut = 'ouverte');
    `)
    fatouId = psql("select c.id from candidature c join candidat ca on ca.id = c.candidat_id where ca.prenom = 'Fatou'")
  })

  test.afterAll(() => {
    artisan('migrate:fresh --seed --force')
  })

  test('correction exceptionnelle — dossier : rouvre le verrou, motif obligatoire, nouveau snapshot', async ({ page }) => {
    await login(page, 'admin@casa-demo.ci')
    await page.waitForURL(/\/admin$/, { timeout: 60_000 })

    await page.goto(`/evaluateur/candidatures/${fatouId}/evaluation`)
    await expect(page.getByText('🔒 ÉVALUATION VALIDÉE')).toBeVisible({ timeout: 30_000 })
    const scoreValue = page.locator('.score-ring .val .kpi-value')
    const scoreAvant = await scoreValue.textContent()

    await page.getByRole('button', { name: /correction exceptionnelle/i }).click()
    const dialog = page.getByRole('dialog', { name: 'Correction exceptionnelle — dossier' })
    await expect(dialog).toBeVisible()
    await expect(dialog.getByText(/vous rouvrez une évaluation validée/i)).toBeVisible()

    const confirmBtn = dialog.getByRole('button', { name: 'Enregistrer la correction' })
    await expect(confirmBtn).toBeDisabled()

    // Le `<span>` du libellé recouvre l'input radio natif (design `.radio-row`) :
    // on clique le `<label>` entier, pas l'input (cf. gotcha Playwright connu du projet).
    await dialog.locator('label.radio-row', { hasText: 'Oui' }).first().click() // nationalité confirmée
    await dialog.locator('label.radio-row', { hasText: 'BAC' }).click() // SC.04 réexaminé
    await dialog.getByRole('button', { name: '5 étoiles' }).click() // MO.04 relevé de 4 à 5
    await confirmBtn.scrollIntoViewIfNeeded()
    await expect(confirmBtn).toBeDisabled() // motif toujours vide -> bloqué

    await page.getByLabel(/motif de la correction/i).fill('Di'); await expect(confirmBtn).toBeDisabled() // < 3 caractères
    await page.getByLabel(/motif de la correction/i).fill('Diplôme réexaminé sur pièce : BAC confirmé, note de motivation relevée.')
    await expect(confirmBtn).toBeEnabled()
    await shots(page, 'correction-dossier')
    await confirmBtn.click()

    await expect(dialog).not.toBeVisible({ timeout: 30_000 })
    // Reste verrouillée (la correction ne « dé-valide » pas), mais avec un NOUVEAU score
    // — le nouveau snapshot recalculé serveur, pas l'ancien.
    await expect(page.getByText('🔒 ÉVALUATION VALIDÉE')).toBeVisible({ timeout: 30_000 })
    await expect(scoreValue).not.toHaveText(scoreAvant, { timeout: 30_000 })
    await shots(page, 'correction-dossier-appliquee')
  })

  test('correction exceptionnelle — entretien : bannière dédiée, motif obligatoire, nouveau snapshot', async ({ page }) => {
    await login(page, 'admin@casa-demo.ci')
    await page.waitForURL(/\/admin$/, { timeout: 60_000 })

    await page.goto(`/evaluateur/candidatures/${fatouId}/entretien`)
    await expect(page.getByText('🔒 ENTRETIEN VALIDÉ')).toBeVisible({ timeout: 30_000 })
    const scoreValue = page.locator('.score-ring .val .kpi-value')
    const scoreAvant = await scoreValue.textContent()

    await page.getByRole('button', { name: /correction exceptionnelle/i }).click()
    const dialog = page.getByRole('dialog', { name: 'Correction exceptionnelle — entretien' })
    await expect(dialog).toBeVisible()
    await expect(dialog.getByText(/vous rouvrez un entretien validé/i)).toBeVisible()

    const confirmBtn = dialog.getByRole('button', { name: 'Enregistrer la correction' })
    await expect(confirmBtn).toBeDisabled()

    // Sous-note réellement bornée par le max FOURNI PAR L'API (aucune constante locale).
    const presentationRow = dialog.locator('.sc-row').filter({ hasText: 'Tenue et posture professionnelle' })
    await presentationRow.getByRole('radiogroup').getByRole('button', { name: '3', exact: true }).click()

    await dialog.getByLabel(/motif de la correction/i).fill('Réécoute de l’entretien : posture sous-évaluée initialement.')
    await expect(confirmBtn).toBeEnabled()
    await shots(page, 'correction-entretien')
    await confirmBtn.click()

    await expect(dialog).not.toBeVisible({ timeout: 30_000 })
    await expect(page.getByText('🔒 ENTRETIEN VALIDÉ')).toBeVisible({ timeout: 30_000 })
    await expect(scoreValue).not.toHaveText(scoreAvant, { timeout: 30_000 })
  })

  test('remplacement : calcul -> publication -> déclarer indisponible -> Mariam promue (aperçu puis réel)', async ({ page }) => {
    await login(page, 'admin@casa-demo.ci')
    await page.waitForURL(/\/admin$/, { timeout: 60_000 })

    await page.goto('/admin/campagnes')
    await expect(page.getByRole('heading', { level: 2, name: 'Campagnes de candidature' })).toBeVisible({ timeout: 30_000 })
    await page.getByRole('link', { name: 'Voir le classement' }).click()
    await expect(page.getByRole('heading', { level: 2, name: 'Classement des candidats' })).toBeVisible({ timeout: 30_000 })

    await page.getByRole('button', { name: 'Calculer le classement' }).click()
    await page.getByRole('button', { name: 'Agent de cuisine' }).click()
    await expect(page.getByRole('cell', { name: 'Koffi Démo' })).toBeVisible({ timeout: 30_000 })
    // Quota=2 : Koffi et Awa retenus, Mariam première en liste d'attente.
    const ligneAwa = page.getByRole('row', { name: /awa démo/i })
    await expect(ligneAwa.getByText('Retenu')).toBeVisible()
    await expect(page.getByRole('row', { name: /mariam démo/i }).getByText('Liste d’attente')).toBeVisible()

    // Aucune action de remplacement avant publication.
    await expect(page.getByRole('button', { name: /déclarer indisponible/i })).toHaveCount(0)

    await page.getByRole('button', { name: /publier les résultats/i }).click()
    const publishDialog = page.getByRole('dialog', { name: 'Publier les résultats' })
    await publishDialog.getByRole('textbox').fill('Cohorte 1 — 2026')
    await publishDialog.getByRole('button', { name: 'Publier définitivement' }).click()
    await expect(page.getByText('🔒 RÉSULTATS PUBLIÉS')).toBeVisible({ timeout: 30_000 })

    // --- Remplacement : Awa (retenue) déclarée indisponible ---
    await ligneAwa.getByRole('button', { name: /déclarer indisponible/i }).click()
    const remplDialog = page.getByRole('dialog', { name: 'Déclarer indisponible' })
    await expect(remplDialog).toBeVisible()
    await expect(remplDialog.getByText(/action irréversible/i)).toBeVisible()
    // Le candidat promu affiché est un APERÇU (lecture du 1er `liste_attente` déjà trié serveur).
    await expect(remplDialog.getByText(/sera promu « retenu » \(sous réserve\)/i)).toBeVisible()
    await expect(remplDialog.getByText(/mariam démo/i)).toBeVisible()

    const remplConfirm = remplDialog.getByRole('button', { name: /confirmer le remplacement/i })
    await expect(remplConfirm).toBeDisabled()
    await remplDialog.getByLabel(/motif/i).fill('Désistement — indisponibilité personnelle signalée par le candidat.')
    await expect(remplConfirm).toBeEnabled()
    await shots(page, 'remplacement-apercu')
    await remplConfirm.click()

    await expect(remplDialog).not.toBeVisible({ timeout: 30_000 })
    // Résultat RÉEL (réponse POST /remplacements), pas l'aperçu — Mariam est bien la promue.
    await expect(page.getByText(/remplacement effectué/i)).toBeVisible({ timeout: 30_000 })
    await expect(page.getByRole('row', { name: /awa démo/i }).getByText('Indisponible')).toBeVisible()
    await expect(page.getByRole('row', { name: /mariam démo/i }).getByText('Retenu')).toBeVisible()
    await shots(page, 'remplacement-effectue')
  })

  test('correction après publication : bandeau proposé mais backend refuse (409 reflété verbatim)', async ({ page }) => {
    await login(page, 'admin@casa-demo.ci')
    await page.waitForURL(/\/admin$/, { timeout: 60_000 })

    await page.goto(`/evaluateur/candidatures/${fatouId}/evaluation`)
    await expect(page.getByText('🔒 ÉVALUATION VALIDÉE')).toBeVisible({ timeout: 30_000 })
    // Le client ne pré-vérifie pas la publication : le bouton reste proposé.
    await page.getByRole('button', { name: /correction exceptionnelle/i }).click()
    const dialog = page.getByRole('dialog', { name: 'Correction exceptionnelle — dossier' })
    await dialog.getByLabel(/motif de la correction/i).fill('Nouvelle tentative après publication.')
    await dialog.getByRole('button', { name: 'Enregistrer la correction' }).click()

    // Le serveur décide : 409 affiché verbatim, le modal reste ouvert.
    await expect(dialog.getByText(/publié.*plus.*correction/i)).toBeVisible({ timeout: 30_000 })
    await expect(dialog).toBeVisible()
  })

  test('élimination manuelle : précondition (déjà non éligible) + acte réel sur un dossier évalué', async ({ page }) => {
    await login(page, 'admin@casa-demo.ci')
    await page.waitForURL(/\/admin$/, { timeout: 60_000 })

    await page.goto('/admin/candidatures')
    await expect(page.getByRole('heading', { level: 2, name: 'Candidatures' })).toBeVisible({ timeout: 30_000 })

    // Précondition : Sekou est déjà non éligible -> bouton désactivé.
    const ligneSekou = page.getByRole('row', { name: /sekou démo/i })
    await expect(ligneSekou.getByRole('button', { name: 'Éliminer' })).toBeDisabled()

    // Acte réel sur Ismael (dossier évalué, encore éligible).
    const ligneIsmael = page.getByRole('row', { name: /ismael démo/i })
    await ligneIsmael.getByRole('button', { name: 'Éliminer' }).click()
    const dialog = page.getByRole('dialog', { name: 'Éliminer ce dossier ?' })
    await expect(dialog).toBeVisible()
    await expect(dialog.getByText(/sera marqué non éligible.*irréversible/i)).toBeVisible()

    const confirmBtn = dialog.getByRole('button', { name: 'Éliminer' })
    await expect(confirmBtn).toBeDisabled()
    await dialog.getByLabel(/motif/i).fill('Diplôme présenté jugé falsifié après contrôle.')
    await expect(confirmBtn).toBeEnabled()
    await shots(page, 'elimination')
    await confirmBtn.click()

    await expect(dialog).not.toBeVisible({ timeout: 30_000 })
    await expect(page.getByRole('row', { name: /ismael démo/i }).getByText('Non éligible')).toBeVisible({ timeout: 30_000 })
    // Élimination désormais elle-même désactivable (précondition reflétée après coup).
    await expect(page.getByRole('row', { name: /ismael démo/i }).getByRole('button', { name: 'Éliminer' })).toBeDisabled()
    await shots(page, 'elimination-appliquee')

    // RÈGLE REINE (structurel, mono-cible D-6b-4) : aucun bouton d'élimination EN MASSE
    // n'existe sur cette page, contrairement à la maquette (`candidatures.html`).
    await expect(page.getByRole('button', { name: /marquer éliminé/i })).toHaveCount(0)
  })
})
