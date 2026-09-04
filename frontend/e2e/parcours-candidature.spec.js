import { expect, test } from '@playwright/test'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'

/**
 * Parcours de bout en bout du FORMULAIRE DE CANDIDATURE (wizard 10 étapes) —
 * inscription -> remplir chaque étape -> uploader -> classer -> soumettre -> modale.
 *
 * Joue contre la stack Docker (:8080). La latence Docker/Windows est forte : ce
 * parcours enchaîne ~25 requêtes, d'où le timeout étendu.
 *
 * ⚠️ Crée un vrai compte candidat + une candidature soumise par exécution
 * (e-mail horodaté). `migrate:fresh --seed` réinitialise.
 */

const HERE = dirname(fileURLToPath(import.meta.url))
const PNG = resolve(HERE, 'fixtures/piece.png')
const PDF = resolve(HERE, 'fixtures/piece.pdf')

const PIECES = [
  'Carte Nationale d’Identité',
  'Certificat de résidence',
  'Diplôme ou bulletin de notes',
  'Curriculum Vitae (CV)',
  'Lettre de motivation',
  'Photo d’identité',
]

async function inscription(page) {
  const email = `e2e-wizard+${Date.now()}@example.ci`
  await page.goto('/inscription')
  await page.getByLabel('Prénom').fill('Awa')
  await page.getByLabel('Nom', { exact: true }).fill('Parcours')
  await page.getByLabel('Date de naissance').fill('2001-05-14')
  await page.getByLabel('Sexe').selectOption('F')
  await page.getByLabel('Numéro CNI / récépissé').fill('CI-E2E-4242')
  await page.getByLabel('Ville de résidence').fill('Abidjan - Cocody')
  await page.getByLabel('Téléphone').fill('0709081011')
  await page.getByLabel('Adresse e-mail').fill(email)
  await page.getByLabel('Mot de passe', { exact: true }).fill('MotDePasse2026')
  await page.getByLabel('Confirmer le mot de passe').fill('MotDePasse2026')
  await page.getByLabel(/Je déclare résider en Côte d'Ivoire/).check()
  await page.getByLabel(/J'accepte les conditions/).check()
  await page.getByRole('button', { name: /Créer mon compte/i }).click()
  await expect(page).toHaveURL(/\/candidat$/, { timeout: 40_000 })
  return email
}

/** Clique l'option `choix` (Oui / Non / libellé de carte) dans un radiogroup. */
const pickRadio = (page, groupName, choix) =>
  page.getByRole('radiogroup', { name: groupName }).locator('label.radio-row', { hasText: choix }).click()

/**
 * Attend l'arrivée sur une étape. Un « Suivant » qui commite peut enchaîner 2
 * requêtes (PATCH réponses + PUT classement, ou POST candidature + confirmation)
 * sur un backend Docker/Windows froid → délai large.
 */
const expectStep = (page, n) =>
  expect(page.getByText(`Étape ${n} / 10`)).toBeVisible({ timeout: 60_000 })

test('inscription → wizard complet → upload → classement → soumission → modale', async ({ page }) => {
  test.setTimeout(300_000)

  await inscription(page)

  // Depuis le tableau de bord : « Commencer »
  await page.getByRole('link', { name: 'Commencer' }).click()
  await expect(page).toHaveURL(/\/candidat\/candidature$/)

  // --- Étape 1 : identité (pré-remplie depuis l'inscription) ---
  await expect(page.getByRole('heading', { name: /Vos informations personnelles/i })).toBeVisible()
  await expect(page.getByLabel('Adresse e-mail')).toBeDisabled()
  await page.getByRole('button', { name: 'Suivant' }).click()

  // --- Étape 2 : filière + confirmation (crée la candidature) ---
  await expectStep(page, 2)
  const carteCuisine = page.getByRole('button', { name: /Agent de cuisine/ })
  await carteCuisine.click()
  await expect(carteCuisine).toHaveAttribute('aria-pressed', 'true')
  await page.getByLabel(/Je confirme/).check()
  await page.getByRole('button', { name: 'Suivant' }).click()

  // --- Étape 3 : profil scolaire ---
  await expectStep(page, 3)
  await pickRadio(page, /actuellement scolarisé/i, 'Non')
  await page.getByRole('radiogroup', { name: /Dernière classe/i }).locator('label.radio-row', { hasText: /^3ème$/ }).click()
  await pickRadio(page, /document justifiant ce niveau/i, 'Oui')
  await pickRadio(page, /programme de formation professionnelle/i, 'Oui') // sc05 = oui -> pas de conditionnels
  await page.getByRole('button', { name: 'Suivant' }).click()

  // --- Étape 4 : socio-économique ---
  await expectStep(page, 4)
  await pickRadio(page, /orphelin/i, 'Non')
  await page.getByRole('radiogroup', { name: /Situation d’emploi/i }).locator('label.radio-row', { hasText: 'Stage' }).click()
  await pickRadio(page, /soutien principal du ménage/i, 'Non')
  await page.getByRole('button', { name: 'Suivant' }).click()

  // --- Étape 5 : expérience + justificatif (upload réel) ---
  await expectStep(page, 5)
  await page.getByRole('button', { name: /Ajouter une expérience/ }).click()
  await page.getByLabel('Domaine').selectOption('hotellerie')
  await page.getByLabel('Durée').selectOption('6_12')
  // La ligne devient « prête » quand POST /experiences a répondu.
  await expect(page.getByRole('button', { name: 'Déposer' })).toBeVisible({ timeout: 30_000 })
  await page.locator('input[type=file]').first().setInputFiles(PDF)
  await expect(page.getByLabel(/Retirer Justificatif/)).toBeVisible({ timeout: 30_000 })
  await page.getByRole('button', { name: 'Suivant' }).click()

  // --- Étape 6 : langues & informatique (échelle 0-3) ---
  await expectStep(page, 6)
  for (const label of [
    'Français écrit', 'Français parlé', 'Compréhension orale',
    'Word (traitement de texte)', 'Excel (tableur)', 'Internet & messagerie',
  ]) {
    await page.getByRole('radiogroup', { name: label }).getByRole('radio', { name: 'Intermédiaire' }).click()
  }
  await page.getByRole('button', { name: 'Suivant' }).click()

  // --- Étape 7 : motivation + classement (PUT AVANT de quitter l'étape) ---
  await expectStep(page, 7)
  await page.getByLabel(/Pourquoi souhaitez-vous suivre cette formation/i)
    .fill('Je veux rejoindre ce programme pour construire une carrière durable dans l’hôtellerie.')
  // Réordonne pour rendre le classement « sale ».
  await page.getByRole('button', { name: /Descendre/ }).first().click()

  const putClassement = page.waitForRequest(
    (r) => r.method() === 'PUT' && /\/candidatures\/[^/]+\/classement$/.test(r.url()),
    { timeout: 30_000 },
  )
  await page.getByRole('button', { name: 'Suivant' }).click()
  await putClassement // le PUT part bien au « Suivant » de l'étape 7
  await expectStep(page, 8)

  // --- Étape 8 : disponibilité ---
  await pickRadio(page, /disponible du lundi au vendredi/i, 'Oui')
  await page.getByRole('radiogroup', { name: /contraintes familiales/i })
    .locator('label.radio-row', { hasText: 'Aucune contrainte particulière' }).click()
  await pickRadio(page, /suivre l’intégralité de la formation/i, 'Oui')
  await pickRadio(page, /vous rendre au Plateau/i, 'Oui')
  await pickRadio(page, /vous rendre aux 2 Plateaux Vallons/i, 'Oui')
  await page.getByRole('button', { name: 'Suivant' }).click()

  // --- Étape 9 : 6 pièces (upload réel) ---
  await expectStep(page, 9)
  for (let i = 0; i < PIECES.length; i++) {
    await page.locator('input[type=file]').nth(i).setInputFiles(PNG)
    await expect(page.getByLabel(`Retirer ${PIECES[i]}`)).toBeVisible({ timeout: 30_000 })
  }
  await page.getByRole('button', { name: 'Suivant' }).click()

  // --- Étape 10 : récapitulatif + soumission ---
  await expectStep(page, 10)
  await expect(page.getByText('Filière choisie')).toBeVisible()
  // Aucun score / barème visible nulle part sur le récap.
  const recap = (await page.locator('.form-shell').innerText()).toLowerCase()
  for (const mot of ['/65', '/35', 'sur 100', 'pondér', 'barème', 'points']) {
    expect(recap, `« ${mot} » ne doit pas apparaître dans le formulaire`).not.toContain(mot)
  }

  await page.getByLabel(/Je certifie/).check()
  await page.getByRole('button', { name: /Soumettre ma candidature/ }).click()

  // Modale de succès avec le numéro de dossier.
  const modal = page.getByRole('dialog', { name: /Candidature soumise/i })
  await expect(modal).toBeVisible({ timeout: 40_000 })
  await expect(modal.getByText(/CASA-\d{4}-\d+/)).toBeVisible()

  await modal.getByRole('link', { name: /Suivre ma candidature/ }).click()
  await expect(page).toHaveURL(/\/candidat$/)

  // Le formulaire est désormais fermé : y revenir affiche l'état non éditable.
  await page.goto('/candidat/candidature')
  await expect(page.getByRole('heading', { name: /déjà été soumis/i })).toBeVisible()
})

test('brouillon fiable : les réponses survivent à un rechargement', async ({ page }) => {
  test.setTimeout(180_000)
  await inscription(page)

  await page.getByRole('link', { name: 'Commencer' }).click()
  await page.getByRole('button', { name: 'Suivant' }).click() // identité

  await expectStep(page, 2)
  await page.getByRole('button', { name: /Agent de cuisine/ }).click()
  await page.getByLabel(/Je confirme/).check()
  await page.getByRole('button', { name: 'Suivant' }).click() // crée la candidature

  await expectStep(page, 3)
  await pickRadio(page, /actuellement scolarisé/i, 'Non')
  await page.getByRole('radiogroup', { name: /Dernière classe/i }).locator('label.radio-row', { hasText: 'Terminale' }).click()
  await pickRadio(page, /document justifiant ce niveau/i, 'Oui')
  await pickRadio(page, /programme de formation professionnelle/i, 'Oui')

  // Enregistrer le brouillon, puis RECHARGER la page.
  await page.getByRole('button', { name: /Enregistrer le brouillon/ }).click()
  await expect(page.getByText(/Brouillon enregistré/)).toBeVisible()
  await page.reload()

  // Le wizard repart à l'étape 1 mais la candidature est ré-hydratée : on
  // ré-avance jusqu'à l'étape scolaire et les réponses sont toujours là.
  await page.getByRole('button', { name: 'Suivant' }).click() // identité
  await expectStep(page, 2)
  await page.getByRole('button', { name: 'Suivant' }).click() // filière déjà confirmée
  await expectStep(page, 3)

  await expect(
    page.getByRole('radiogroup', { name: /actuellement scolarisé/i }).locator('label.radio-row.is-checked'),
  ).toHaveText(/Non/)
  await expect(
    page.getByRole('radiogroup', { name: /Dernière classe/i }).locator('label.radio-row.is-checked'),
  ).toHaveText(/Terminale/)
})
