import { execSync } from 'node:child_process'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { expect, test } from '@playwright/test'

/**
 * Lot 9a — PARCOURS D'INTÉGRATION BOUT-EN-BOUT.
 *
 * Les E2E par-espace (evaluateur / notation / classement / suivi / admin /
 * actes-exceptionnels) prouvent chaque tronçon isolément. Ce fichier prouve que
 * la chaîne ENTIÈRE tient d'un bout à l'autre sur la stack Docker réelle, en une
 * session enchaînée.
 *
 * Deux describe, séparés pour la lisibilité (un test monolithique de 50 requêtes
 * cacherait quel maillon casse) :
 *   A. Parcours nominal complet + RÈGLE REINE (un vrai candidat frais, du wizard
 *      au résultat « retenu » ; en parallèle Sekou, non-éligible seedé, dont
 *      toute la chaîne *candidat-facing* est vécue — avant/après publication).
 *   B. Remplacement post-publication vu CÔTÉ CANDIDAT (l'E2E 8d-3 ne testait que
 *      le côté admin).
 *
 * ⚠️ ON-DEMANDE : tag `@integration`. `npm run e2e` l'EXCLUT (filet quotidien,
 * ~15 min). `npm run e2e:integration` le lance (chaîne complète, ~15 min, avant
 * déploiement / en CI au 9c).
 *
 * ⚠️ BASE JETABLE : `publier` est irréversible — `migrate:fresh --seed` avant ET
 * après chaque describe.
 */

const APP = fileURLToPath(new URL('../..', import.meta.url))
const artisan = (cmd) =>
  execSync(`docker compose exec -T backend php artisan ${cmd}`, { cwd: APP, stdio: 'pipe' }).toString()
const psql = (sql) =>
  execSync('docker compose exec -T postgres psql -U casa -d casa -tA -v ON_ERROR_STOP=1', {
    cwd: APP,
    input: sql,
    stdio: ['pipe', 'pipe', 'pipe'],
  }).toString().trim()

const HERE = dirname(fileURLToPath(import.meta.url))
const PDF = resolve(HERE, 'fixtures/piece.pdf')
const PNG = resolve(HERE, 'fixtures/piece.png')
const PWD = 'Demo2026!' // comptes de démonstration (seedés, contournent la validation)
// Le candidat frais s'inscrit pour de vrai : mot de passe conforme (min:10 +
// casse + chiffres — `Demo2026!` à 9 caractères serait refusé à l'inscription).
const FRESH_PWD = 'MotDePasse2026'

/** Tout terme qui trahirait le score / le rang / la cause interne côté candidat. */
const TERMES_INTERNES =
  /score|\brang\b|bar[èe]me|[ée]ligib|[ée]valuat|\bnote\b|\/(35|65|100)|\bpoints?\b|pond[ée]r|non[_ -]?eligible|statut_interne|motif_interne/i

const PIECES = [
  'Carte Nationale d’Identité',
  'Certificat de résidence',
  'Diplôme ou bulletin de notes',
  'Curriculum Vitae (CV)',
  'Lettre de motivation',
  'Photo d’identité',
]

async function login(page, email, urlRe, pwd = PWD) {
  // Repartir d'une session vierge : plusieurs `login()` successifs dans un même
  // test (ex. candidat frais puis Sekou) — sans ça, `/connexion` redirige vers
  // l'espace du 1er utilisateur (RedirectIfAuthed) et le formulaire est absent.
  await page.context().clearCookies()
  await page.goto('/connexion')
  await page.getByLabel('Adresse e-mail').fill(email)
  await page.getByLabel('Mot de passe', { exact: true }).fill(pwd)
  await page.getByRole('button', { name: /se connecter/i }).click()
  await page.waitForURL(urlRe, { timeout: 60_000 })
}

async function gotoSuivi(page) {
  await page.goto('/candidat/ma-candidature')
  // .app-content n'existe qu'une fois le chargement terminé (spinner plein écran
  // avant). Timeout large : backend Docker froid juste après migrate:fresh.
  await expect(page.locator('.app-content')).toBeVisible({ timeout: 90_000 })
}

async function shots(page, name) {
  for (const w of [390, 1280]) {
    // eslint-disable-next-line no-await-in-loop
    await page.setViewportSize({ width: w, height: 900 })
    // eslint-disable-next-line no-await-in-loop
    await page.evaluate(() => new Promise((r) => setTimeout(r, 150)))
    // eslint-disable-next-line no-await-in-loop
    await page.screenshot({ path: `e2e/__screenshots__/integration-${name}-${w}.png`, fullPage: true, animations: 'disabled' })
  }
  await page.setViewportSize({ width: 1280, height: 800 })
}

const pickRadio = (page, groupName, choix) =>
  page.getByRole('radiogroup', { name: groupName }).locator('label.radio-row', { hasText: choix }).click()

const expectStep = (page, n) =>
  expect(page.getByText(`Étape ${n} / 10`)).toBeVisible({ timeout: 60_000 })

/**
 * Inscription + wizard 10 étapes + soumission d'un VRAI candidat.
 * Identité « Nadia Intégration » — aucune collision avec la cohorte
 * `DemoClassementSeeder` (Awa/Koffi/Mariam/Ismael/Fatou/Sekou Démo).
 * Réponses connues-bonnes (reprises de `parcours-candidature.spec.js`) → dossier
 * ÉLIGIBLE, filière cuisine. Retourne l'e-mail créé.
 */
async function parcoursWizardNominal(page) {
  const email = `e2e-integration+${Date.now()}@example.ci`

  // --- Inscription ---
  await page.goto('/inscription')
  await page.getByLabel('Prénom').fill('Nadia')
  await page.getByLabel('Nom', { exact: true }).fill('Intégration')
  await page.getByLabel('Date de naissance').fill('2002-03-09')
  await page.getByLabel('Sexe').selectOption('F')
  await page.getByLabel('Numéro CNI / récépissé').fill('CI-INT-9001')
  await page.getByLabel('Ville de résidence').fill('Abidjan - Cocody')
  await page.getByLabel('Téléphone').fill('0708090102')
  await page.getByLabel('Mot de passe', { exact: true }).fill(FRESH_PWD)
  await page.getByLabel('Confirmer le mot de passe').fill(FRESH_PWD)
  await page.getByLabel('Adresse e-mail').fill(email)
  await page.getByLabel(/Je déclare résider en Côte d'Ivoire/).check()
  await page.getByLabel(/J'accepte les conditions/).check()
  await page.getByRole('button', { name: /Créer mon compte/i }).click()
  await expect(page).toHaveURL(/\/candidat$/, { timeout: 40_000 })

  // --- Wizard ---
  // Le tableau de bord charge GET /api/candidature (404 -> CTA « Commencer »).
  // Attente explicite : la latence Docker-Windows peut dépasser l'actionTimeout.
  await expect(page.getByRole('link', { name: 'Commencer' })).toBeVisible({ timeout: 60_000 })
  await page.getByRole('link', { name: 'Commencer' }).click()
  await expect(page).toHaveURL(/\/candidat\/candidature$/)

  // 1 — identité (pré-remplie)
  await expect(page.getByRole('heading', { name: /Vos informations personnelles/i })).toBeVisible()
  await page.getByRole('button', { name: 'Suivant' }).click()

  // 2 — filière + confirmation
  await expectStep(page, 2)
  const carte = page.getByRole('button', { name: /Agent de cuisine/ })
  await carte.click()
  await expect(carte).toHaveAttribute('aria-pressed', 'true')
  await page.getByLabel(/Je confirme/).check()
  await page.getByRole('button', { name: 'Suivant' }).click()

  // 3 — scolarité. sc05 = « Non » : NE PAS répondre « Oui » (bénéficiaire ACTUEL
  // d'une autre formation = critère éliminatoire SC.05 — piège de
  // `parcours-candidature.spec.js`, qui ne vérifie jamais l'éligibilité, cf.
  // findings d'intégration). sc06 = « Non » → aucune sous-question.
  await expectStep(page, 3)
  await pickRadio(page, /actuellement scolarisé/i, 'Non')
  await page.getByRole('radiogroup', { name: /Dernière classe/i }).locator('label.radio-row', { hasText: /^3ème$/ }).click()
  await pickRadio(page, /document justifiant ce niveau/i, 'Oui')
  await pickRadio(page, /Bénéficiez-vous actuellement/i, 'Non')
  await pickRadio(page, /déjà bénéficié/i, 'Non')
  await page.getByRole('button', { name: 'Suivant' }).click()

  // 4 — socio-éco
  await expectStep(page, 4)
  await pickRadio(page, /orphelin/i, 'Non')
  await page.getByRole('radiogroup', { name: /Situation d’emploi/i }).locator('label.radio-row', { hasText: 'Stage' }).click()
  await pickRadio(page, /soutien principal du ménage/i, 'Non')
  await page.getByRole('button', { name: 'Suivant' }).click()

  // 5 — expérience + justificatif (upload réel)
  await expectStep(page, 5)
  await page.getByRole('button', { name: /Ajouter une expérience/ }).click()
  await page.getByLabel('Domaine').selectOption('hotellerie')
  await page.getByLabel('Durée').selectOption('6_12')
  await expect(page.getByRole('button', { name: 'Déposer' })).toBeVisible({ timeout: 30_000 })
  await page.locator('input[type=file]').first().setInputFiles(PDF)
  // Upload multipart : plus lent (finfo + écriture disque) — marge généreuse pour
  // la latence Docker-sur-Windows du poste de dev ; en CI Linux c'est < 2 s.
  await expect(page.getByLabel(/Retirer Justificatif/)).toBeVisible({ timeout: 60_000 })
  await page.getByRole('button', { name: 'Suivant' }).click()

  // 6 — langues & informatique
  await expectStep(page, 6)
  for (const label of [
    'Français écrit', 'Français parlé', 'Compréhension orale',
    'Word (traitement de texte)', 'Excel (tableur)', 'Internet & messagerie',
  ]) {
    // eslint-disable-next-line no-await-in-loop
    await page.getByRole('radiogroup', { name: label }).getByRole('radio', { name: 'Intermédiaire' }).click()
  }
  await page.getByRole('button', { name: 'Suivant' }).click()

  // 7 — motivation + classement (PUT au départ de l'étape)
  await expectStep(page, 7)
  await page.getByLabel(/Pourquoi souhaitez-vous suivre cette formation/i)
    .fill('Je veux bâtir une carrière durable dans l’hôtellerie-restauration et servir au mieux les clients.')
  await page.getByRole('button', { name: /Descendre/ }).first().click()
  const putClassement = page.waitForRequest(
    (r) => r.method() === 'PUT' && /\/candidatures\/[^/]+\/classement$/.test(r.url()),
    { timeout: 30_000 },
  )
  await page.getByRole('button', { name: 'Suivant' }).click()
  await putClassement
  await expectStep(page, 8)

  // 8 — disponibilité (réponses éligibles : DI.01 / DI.03 / accès sites = Oui)
  await pickRadio(page, /disponible du lundi au vendredi/i, 'Oui')
  await page.getByRole('radiogroup', { name: /contraintes familiales/i })
    .locator('label.radio-row', { hasText: 'Aucune contrainte particulière' }).click()
  await pickRadio(page, /suivre l’intégralité de la formation/i, 'Oui')
  await pickRadio(page, /vous rendre au Plateau/i, 'Oui')
  await pickRadio(page, /vous rendre aux 2 Plateaux Vallons/i, 'Oui')
  await page.getByRole('button', { name: 'Suivant' }).click()

  // 9 — 6 pièces (upload réel)
  await expectStep(page, 9)
  for (let i = 0; i < PIECES.length; i++) {
    // eslint-disable-next-line no-await-in-loop
    await page.locator('input[type=file]').nth(i).setInputFiles(PNG)
    // eslint-disable-next-line no-await-in-loop
    // 60 s : 6 uploads multipart d'affilée sur Docker-Windows peuvent traîner ;
    // le backend répond bien 201 (cf. Lot 10), c'est la latence du poste.
    await expect(page.getByLabel(`Retirer ${PIECES[i]}`)).toBeVisible({ timeout: 60_000 })
  }
  await page.getByRole('button', { name: 'Suivant' }).click()

  // 10 — récap + soumission
  await expectStep(page, 10)
  await page.getByLabel(/Je certifie/).check()
  await page.getByRole('button', { name: /Soumettre ma candidature/ }).click()
  const modal = page.getByRole('dialog', { name: /Candidature soumise/i })
  await expect(modal).toBeVisible({ timeout: 40_000 })
  await expect(modal.getByText(/CASA-\d{4}-\d+/)).toBeVisible()
  await shots(page, 'soumission')
  await modal.getByRole('link', { name: /Suivre ma candidature/ }).click()
  await expect(page).toHaveURL(/\/candidat$/)

  return email
}

// ==========================================================================
//  A. PARCOURS NOMINAL COMPLET + RÈGLE REINE
// ==========================================================================

test.describe.configure({ mode: 'serial' })

test.describe('Intégration — parcours nominal complet + règle reine @integration', () => {
  let freshEmail = ''
  let freshId = ''

  test.beforeAll(() => {
    artisan('migrate:fresh --seed --force')
    artisan('db:seed --class=DemoClassementSeeder --force')
    artisan('cache:clear')
  })
  test.afterAll(() => {
    artisan('migrate:fresh --seed --force')
  })

  test('1 · candidat frais : inscription → wizard 10 étapes → soumission', async ({ page }) => {
    test.setTimeout(600_000)
    freshEmail = await parcoursWizardNominal(page)
    const row = psql(
      `select c.id || '|' || c.statut_interne from candidature c
       join candidat ca on ca.id = c.candidat_id
       join utilisateur u on u.id = ca.utilisateur_id
       where u.email = '${freshEmail}'`,
    )
    const [id, statut] = row.split('|')
    freshId = id
    expect(freshId).toMatch(/^[0-9a-f-]{36}$/)
    // La réponse de `/soumettre` est NEUTRE (règle reine) — elle ne dit pas si
    // le dossier est éligible. On le vérifie ici : un dossier non éligible
    // deviendrait `non_eligible` (pas `soumis`) et casserait l'affectation.
    expect(statut, 'le dossier frais doit être « soumis » (éligible)').toBe('soumis')
  })

  test('2 · admin : affecte l’évaluateur (Solange N’Dri) au dossier frais', async ({ page }) => {
    test.setTimeout(240_000)
    await login(page, 'admin@casa-demo.ci', /\/admin$/)
    await page.goto('/admin/candidatures')
    await expect(page.getByRole('heading', { level: 2, name: 'Candidatures' })).toBeVisible({ timeout: 30_000 })
    const ligne = page.getByRole('row', { name: /nadia intégration/i })
    await expect(ligne).toBeVisible({ timeout: 30_000 })
    await ligne.getByRole('checkbox').check()
    await page.getByRole('button', { name: /affecter \(1\)/i }).click()
    const dialog = page.getByRole('dialog', { name: 'Affecter à un évaluateur' })
    await dialog.locator('select').selectOption({ label: "Solange N'Dri" })
    await dialog.getByRole('button', { name: 'Affecter' }).click()
    await expect(dialog).not.toBeVisible({ timeout: 30_000 })
    await expect(page.getByRole('cell', { name: "Solange N'Dri" })).toBeVisible({ timeout: 30_000 })
  })

  test('3 · évaluateur : vérifie → note le dossier /65 → valide → note l’entretien /35 → valide', async ({ page }) => {
    test.setTimeout(360_000)
    await login(page, 'evaluateur@casa-demo.ci', /\/evaluateur$/)

    // --- Vérification (via la fiche) ---
    await page.goto(`/evaluateur/candidatures/${freshId}`)
    await expect(page.getByRole('heading', { name: 'Nadia Intégration' })).toBeVisible({ timeout: 30_000 })
    await page.getByRole('button', { name: 'Vérification' }).click()
    await page.getByRole('radiogroup', { name: /Nationalité ivoirienne confirmée/i })
      .locator('label.radio-row', { hasText: /^Oui$/ }).click()
    await page.getByRole('radio', { name: 'BEPC' }).click()
    await page.getByRole('button', { name: /enregistrer la vérification/i }).click()
    await expect(page.getByText('Vérification enregistrée.')).toBeVisible({ timeout: 30_000 })
    await expect(page.getByText('Éligible')).toBeVisible()

    // --- Notation dossier /65 ---
    await page.goto(`/evaluateur/candidatures/${freshId}/evaluation`)
    await expect(page.getByText('Score dossier')).toBeVisible({ timeout: 30_000 })
    await page.getByRole('button', { name: '4 étoiles' }).click()
    await page.getByPlaceholder(/observations de l'évaluateur/i).fill('Dossier complet, motivation cohérente avec la filière.')
    await page.getByRole('button', { name: 'Enregistrer' }).click()
    await expect(page.getByText('Brouillon enregistré.')).toBeVisible({ timeout: 30_000 })
    await page.getByRole('button', { name: 'Valider définitivement' }).click()
    await page.getByRole('dialog').getByRole('button', { name: 'Valider' }).click()
    await expect(page.getByText('🔒 ÉVALUATION VALIDÉE')).toBeVisible({ timeout: 30_000 })

    // --- Entretien /35 ---
    await page.goto(`/evaluateur/candidatures/${freshId}/entretien`)
    await expect(page.getByRole('heading', { name: "Planifier l'entretien" })).toBeVisible({ timeout: 30_000 })
    await page.getByLabel('Date').fill('2026-07-15')
    await page.getByLabel('Heure').fill('09:30')
    await page.getByLabel('Lieu').selectOption('Le Plateau')
    await page.getByRole('button', { name: /planifier l'entretien/i }).click()
    await expect(page.getByText('Présence')).toBeVisible({ timeout: 30_000 })
    await page.getByRole('button', { name: 'Présent' }).click()
    await page.locator('.rubrique-card').filter({ hasText: 'Présentation' })
      .getByRole('radiogroup').first().getByRole('button', { name: '3', exact: true }).click()
    await page.getByRole('button', { name: 'Enregistrer' }).click()
    await expect(page.getByText('Entretien enregistré.')).toBeVisible({ timeout: 30_000 })
    await page.getByRole('button', { name: 'Valider définitivement' }).click()
    await page.getByRole('dialog').getByRole('button', { name: 'Valider' }).click()
    await expect(page.getByText('🔒 ENTRETIEN VALIDÉ')).toBeVisible({ timeout: 30_000 })
  })

  test('4 · AVANT publication : candidat frais ET Sekou voient « en cours », rien d’interne', async ({ page }) => {
    test.setTimeout(180_000)
    for (const [email, pwd] of [[freshEmail, FRESH_PWD], ['classement5@casa-demo.ci', PWD]]) {
      // eslint-disable-next-line no-await-in-loop
      await login(page, email, /\/candidat$/, pwd)
      // eslint-disable-next-line no-await-in-loop
      await gotoSuivi(page)
      // eslint-disable-next-line no-await-in-loop
      await expect(page.getByText('Candidature en cours de traitement')).toBeVisible()
      // eslint-disable-next-line no-await-in-loop
      await expect(page.getByText(/le résultat vous sera communiqué à l'issue du processus/i)).toBeVisible()
      // eslint-disable-next-line no-await-in-loop
      const dom = (await page.locator('.app-content').innerText()).toLowerCase()
      expect(dom, `fuite interne pour ${email}`).not.toMatch(TERMES_INTERNES)
    }
    await shots(page, 'en-cours')
  })

  test('5 · admin : calcule le classement → publie (friction : saisie du nom exact)', async ({ page }) => {
    test.setTimeout(240_000)
    await login(page, 'admin@casa-demo.ci', /\/admin$/)
    await page.goto('/admin/campagnes')
    await expect(page.getByRole('heading', { level: 2, name: 'Campagnes de candidature' })).toBeVisible({ timeout: 30_000 })
    await page.getByRole('link', { name: 'Voir le classement' }).click()
    await expect(page.getByRole('heading', { level: 2, name: 'Classement des candidats' })).toBeVisible({ timeout: 30_000 })

    await page.getByRole('button', { name: 'Calculer le classement' }).click()
    await page.getByRole('button', { name: 'Agent de cuisine' }).click()
    await expect(page.getByRole('cell', { name: 'Nadia Intégration' })).toBeVisible({ timeout: 30_000 })
    // Quota 24 ≫ 7 candidats : tout éligible = retenu (sanity, pas l'objet du test).
    await expect(page.getByRole('row', { name: /nadia intégration/i }).getByText('Retenu')).toBeVisible()

    await page.getByRole('button', { name: /publier les résultats/i }).click()
    const pub = page.getByRole('dialog', { name: 'Publier les résultats' })
    const confirmBtn = pub.getByRole('button', { name: 'Publier définitivement' })
    await expect(confirmBtn).toBeDisabled()
    await pub.getByRole('textbox').fill('Cohorte 1 — 2026')
    await expect(confirmBtn).toBeEnabled()
    await shots(page, 'publication-friction')
    await confirmBtn.click()
    await expect(page.getByText('🔒 RÉSULTATS PUBLIÉS')).toBeVisible({ timeout: 30_000 })
  })

  test('6 · APRÈS publication : candidat frais RETENU ; Sekou NON RETENU générique, indiscernable', async ({ page }) => {
    test.setTimeout(180_000)

    // --- Candidat frais : retenu ---
    await login(page, freshEmail, /\/candidat$/, FRESH_PWD)
    await gotoSuivi(page)
    await expect(page.getByText(/félicitations, votre candidature est retenue/i)).toBeVisible()
    await expect(page.getByText(/votre place en filière .* est confirmée/i)).toBeVisible()
    expect((await page.locator('.app-content').innerText()).toLowerCase()).not.toMatch(TERMES_INTERNES)
    await shots(page, 'resultat-retenu')

    // --- Sekou : non-éligible en interne, mais côté candidat = non_retenu générique ---
    await login(page, 'classement5@casa-demo.ci', /\/candidat$/)
    await gotoSuivi(page)
    await expect(page.getByText(/votre candidature n'a pas été retenue/i)).toBeVisible()
    await expect(page.getByText(/le nombre de places étant limité/i)).toBeVisible()
    await expect(page.getByText(/motif :/i)).toHaveCount(0)
    const html = await page.locator('.app-content').innerHTML()
    expect(html.toLowerCase()).not.toMatch(TERMES_INTERNES)
    // Indiscernable : aucune trace de l'inéligibilité, ni en texte ni en HTML.
    expect(html).not.toMatch(/non conforme|inéligib|critère|éliminat/i)
    await shots(page, 'resultat-non-retenu')
  })
})

// ==========================================================================
//  B. REMPLACEMENT POST-PUBLICATION VU CÔTÉ CANDIDAT
// ==========================================================================

test.describe('Intégration — remplacement post-publication vu côté candidat @integration', () => {
  test.beforeAll(() => {
    artisan('migrate:fresh --seed --force')
    artisan('db:seed --class=DemoClassementSeeder --force')
    artisan('cache:clear')
    // Quota 2 : Koffi + Awa retenus, Mariam 1re en liste d'attente (sinon quota
    // 24 ⇒ personne à promouvoir).
    psql(`
      update campagne_filiere set quota = 2
      where filiere_id = (select id from filiere where code = 'cuisine')
        and campagne_id = (select id from campagne where statut = 'ouverte');
    `)
  })
  test.afterAll(() => {
    artisan('migrate:fresh --seed --force')
  })

  test('1 · admin : calcule → publie → déclare Awa indisponible (Mariam promue)', async ({ page }) => {
    test.setTimeout(240_000)
    await login(page, 'admin@casa-demo.ci', /\/admin$/)
    await page.goto('/admin/campagnes')
    await expect(page.getByRole('heading', { level: 2, name: 'Campagnes de candidature' })).toBeVisible({ timeout: 30_000 })
    await page.getByRole('link', { name: 'Voir le classement' }).click()
    await expect(page.getByRole('heading', { level: 2, name: 'Classement des candidats' })).toBeVisible({ timeout: 30_000 })

    await page.getByRole('button', { name: 'Calculer le classement' }).click()
    await page.getByRole('button', { name: 'Agent de cuisine' }).click()
    await expect(page.getByRole('cell', { name: 'Koffi Démo' })).toBeVisible({ timeout: 30_000 })
    await expect(page.getByRole('row', { name: /mariam démo/i }).getByText('Liste d’attente')).toBeVisible()

    await page.getByRole('button', { name: /publier les résultats/i }).click()
    const pub = page.getByRole('dialog', { name: 'Publier les résultats' })
    await pub.getByRole('textbox').fill('Cohorte 1 — 2026')
    await pub.getByRole('button', { name: 'Publier définitivement' }).click()
    await expect(page.getByText('🔒 RÉSULTATS PUBLIÉS')).toBeVisible({ timeout: 30_000 })

    // --- Remplacement : Awa (retenue) → indisponible ---
    await page.getByRole('row', { name: /awa démo/i }).getByRole('button', { name: /déclarer indisponible/i }).click()
    const modal = page.getByRole('dialog', { name: 'Déclarer indisponible' })
    await expect(modal.getByText(/sera promu « retenu » \(sous réserve\)/i)).toBeVisible()
    await expect(modal.getByText(/mariam démo/i)).toBeVisible()
    await modal.getByLabel(/motif/i).fill('Désistement — indisponibilité personnelle signalée par la candidate.')
    await modal.getByRole('button', { name: /confirmer le remplacement/i }).click()
    await expect(modal).not.toBeVisible({ timeout: 30_000 })
    await expect(page.getByText(/remplacement effectué/i)).toBeVisible({ timeout: 30_000 })
    await expect(page.getByRole('row', { name: /awa démo/i }).getByText('Indisponible')).toBeVisible()
  })

  test('2 · candidat SORTANT (Awa) : voit « clôturée », aucune fuite', async ({ page }) => {
    test.setTimeout(150_000)
    await login(page, 'classement0@casa-demo.ci', /\/candidat$/)
    await gotoSuivi(page)
    await expect(page.getByText(/votre candidature a été clôturée/i)).toBeVisible()
    const html = await page.locator('.app-content').innerHTML()
    expect(html.toLowerCase()).not.toMatch(TERMES_INTERNES)
    // Ni score, ni rang, ni le FAIT du remplacement / d'une promotion.
    expect(html).not.toMatch(/remplac|promu|liste d'attente|indisponible/i)
    await shots(page, 'remplacement-sortant')
  })

  test('3 · candidat PROMU (Mariam) : voit « retenue », indiscernable d’une retenue de 1re heure', async ({ page }) => {
    test.setTimeout(150_000)
    await login(page, 'classement2@casa-demo.ci', /\/candidat$/)
    await gotoSuivi(page)
    await expect(page.getByText(/félicitations, votre candidature est retenue/i)).toBeVisible()
    const html = await page.locator('.app-content').innerHTML()
    expect(html.toLowerCase()).not.toMatch(TERMES_INTERNES)
    expect(html).not.toMatch(/remplac|promu|liste d'attente|indisponible|sous réserve/i)
    await shots(page, 'remplacement-promu')
  })
})
