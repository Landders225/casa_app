import { execSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'
import { expect, test } from '@playwright/test'

/**
 * Lot 8b-3 — SUIVI & RÉSULTAT candidat (`/candidat/ma-candidature`).
 *
 * ⚠️ Base JETABLE : `publier` est IRRÉVERSIBLE (Lot 5b). Ce spec fait
 * `migrate:fresh --seed` AVANT (beforeAll) et APRÈS (afterAll) — la base de dev
 * repart non publiée, aucun autre smoke n'hérite d'une campagne publiée.
 *
 * Ordonné (`serial`) : on vérifie l'état « en cours de traitement » AVANT de
 * publier, puis les résultats APRÈS.
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

const PWD = 'Demo2026!'
const TERMES_INTERNES =
  /score|\brang\b|bar[èe]me|[ée]ligib|[ée]valuat|\bnote\b|\/(35|65|100)|\bpoints?\b|pond[ée]r|non[_ -]?eligible|statut_interne|motif_interne/i

async function login(page, email) {
  await page.goto('/connexion')
  await page.getByLabel('Adresse e-mail').fill(email)
  await page.getByLabel('Mot de passe', { exact: true }).fill(PWD)
  await page.getByRole('button', { name: /se connecter/i }).click()
  await page.waitForURL(/\/candidat$/, { timeout: 40_000 })
}

async function gotoSuivi(page) {
  await page.goto('/candidat/ma-candidature')
  // .app-content n'existe qu'une fois le chargement terminé (avant : plein-écran
  // spinner). Timeout large : le backend Docker est FROID juste après
  // migrate:fresh — la 1re compilation de la pile candidature peut être lente.
  await expect(page.locator('.app-content')).toBeVisible({ timeout: 90_000 })
}

async function shots(page, name) {
  for (const w of [390, 1280]) {
    await page.setViewportSize({ width: w, height: 900 })
    await page.evaluate(() => new Promise((r) => setTimeout(r, 120)))
    await page.screenshot({ path: `e2e/__screenshots__/suivi-${name}-${w}.png`, fullPage: true, animations: 'disabled' })
  }
  await page.setViewportSize({ width: 1280, height: 800 })
}

test.describe.configure({ mode: 'serial' })

test.describe('Suivi & résultat candidat', () => {
  // Chaque test (et les hooks) : large — Docker/Windows + migrate:fresh à froid.
  test.beforeAll(async () => {
    test.setTimeout(300_000)
    artisan('migrate:fresh --seed --force')
    artisan('db:seed --class=DemoClassementSeeder --force')
    artisan('cache:clear')
  })

  test.beforeEach(() => {
    test.setTimeout(150_000)
  })

  test.afterAll(async () => {
    test.setTimeout(180_000)
    artisan('migrate:fresh --seed --force')
  })

  test('aucune candidature -> écran « pas encore de dossier »', async ({ page }) => {
    await login(page, 'candidat@casa-demo.ci')
    await gotoSuivi(page)
    await expect(page.getByText(/pas encore de dossier de candidature/i)).toBeVisible()
    await expect(page.getByRole('link', { name: /démarrer mon dossier/i })).toBeVisible()
  })

  test('en cours de traitement -> phrase neutre, aucune décision, aucun jalon interne', async ({ page }) => {
    await login(page, 'classement1@casa-demo.ci') // Koffi, évalué mais pas publié
    await gotoSuivi(page)

    await expect(page.getByText('Candidature en cours de traitement')).toBeVisible()
    await expect(page.getByText(/le résultat vous sera communiqué à l'issue du processus/i)).toBeVisible()

    // aucun résultat, aucun jalon interne
    await expect(page.getByText(/félicitations|retenue|liste d'attente|clôturée/i)).toHaveCount(0)
    for (const mot of [/entretien/i, /évaluation/i, /éligibilité/i, /instruction/i, /non conforme/i]) {
      await expect(page.getByText(mot)).toHaveCount(0)
    }
    await expect(page.getByText('En traitement')).toBeVisible() // l'étape coarse

    const dom = (await page.locator('.app-content').innerText()).toLowerCase()
    expect(dom).not.toMatch(TERMES_INTERNES)

    await shots(page, 'en-cours')
  })

  test('PUBLICATION (insertion directe des décisions + publication)', async () => {
    const cid = psql("select id from campagne where statut='ouverte'")
    psql(`
      INSERT INTO decision_candidature (candidature_id, decision, rang, motif_interne, motif_communicable)
      SELECT c.id,
        CASE ca.prenom
          WHEN 'Koffi' THEN 'retenu' WHEN 'Awa' THEN 'liste_attente'
          WHEN 'Mariam' THEN 'indisponible' ELSE 'non_retenu' END,
        CASE ca.prenom WHEN 'Koffi' THEN 1 WHEN 'Awa' THEN 2 ELSE NULL END,
        CASE ca.prenom WHEN 'Sekou' THEN 'non éligible — critère interne' ELSE NULL END,
        CASE ca.prenom WHEN 'Fatou' THEN 'Places limitées cette année, recandidatez à la prochaine cohorte.' ELSE NULL END
      FROM candidature c JOIN candidat ca ON ca.id = c.candidat_id
      WHERE c.campagne_id = '${cid}' AND ca.nom = 'Démo';

      INSERT INTO publication (id, campagne_id, publiee_le, publiee_par)
      SELECT gen_random_uuid(), '${cid}', now(), (SELECT id FROM membre_equipe LIMIT 1);
    `)
    expect(psql(`select count(*) from publication where campagne_id='${cid}'`)).toBe('1')
  })

  test('résultat RETENU -> félicitations', async ({ page }) => {
    await login(page, 'classement1@casa-demo.ci') // Koffi
    await gotoSuivi(page)
    await expect(page.getByText(/félicitations, votre candidature est retenue/i)).toBeVisible()
    await expect(page.getByText(/votre place en filière .* est confirmée/i)).toBeVisible()

    const dom = (await page.locator('.app-content').innerText()).toLowerCase()
    expect(dom).not.toMatch(TERMES_INTERNES)

    await shots(page, 'retenu')
  })

  test('résultat NON RETENU (ordinaire) -> message générique fixe', async ({ page }) => {
    await login(page, 'classement3@casa-demo.ci') // Ismael, non_retenu sans motif
    await gotoSuivi(page)
    await expect(page.getByText(/votre candidature n'a pas été retenue/i)).toBeVisible()
    await expect(page.getByText(/le nombre de places étant limité/i)).toBeVisible()
    await expect(page.getByText(/motif :/i)).toHaveCount(0)

    const dom = (await page.locator('.app-content').innerText()).toLowerCase()
    expect(dom).not.toMatch(TERMES_INTERNES)

    await shots(page, 'non-retenu')
  })

  test('résultat NON RETENU issu d’un NON-ÉLIGIBLE -> identique au non_retenu ordinaire', async ({ page }) => {
    await login(page, 'classement5@casa-demo.ci') // Sekou, non_retenu + motif_interne interne
    await gotoSuivi(page)
    await expect(page.getByText(/votre candidature n'a pas été retenue/i)).toBeVisible()
    await expect(page.getByText(/le nombre de places étant limité/i)).toBeVisible()

    // aucune trace de l'inéligibilité, ni via texte ni via le HTML
    const html = await page.locator('.app-content').innerHTML()
    expect(html.toLowerCase()).not.toMatch(TERMES_INTERNES)
    expect(html).not.toMatch(/non conforme|critère interne|inéligib/i)
  })
})
