import { test } from '@playwright/test'

/**
 * Captures RESPONSIVE du formulaire de candidature — l'enjeu n°1 (public
 * smartphone). Largeurs 360 / 390 / 768. Fichiers -> e2e/__screenshots__/.
 * Pas de comparaison pixel : images à examiner (segmenté Oui/Non, cartes,
 * échelle langues 2×2 sous 420px, footer wizard empilé, flèches classement ≥44px).
 *
 * Le viewport est redimensionné par test — lancer avec `--project=mobile` pour
 * ne pas dupliquer les 3 largeurs sur les 2 projets.
 */
const DIR = 'e2e/__screenshots__'
const WIDTHS = [360, 390, 768]

const pickRadio = (page, groupName, choix) =>
  page.getByRole('radiogroup', { name: groupName }).locator('label.radio-row', { hasText: choix }).click()

async function shot(page, name) {
  await page.evaluate(() => new Promise((r) => setTimeout(r, 150)))
  await page.screenshot({ path: `${DIR}/${name}.png`, fullPage: true, animations: 'disabled' })
}

for (const w of WIDTHS) {
  test(`wizard responsive @${w}px`, async ({ page }) => {
    test.setTimeout(240_000)
    await page.setViewportSize({ width: w, height: 900 })

    // Inscription (compte + auto-login).
    const email = `e2e-cap+${w}+${Date.now()}@example.ci`
    await page.goto('/inscription')
    await page.getByLabel('Prénom').fill('Cap')
    await page.getByLabel('Nom', { exact: true }).fill('Ture')
    await page.getByLabel('Date de naissance').fill('2001-05-14')
    await page.getByLabel('Sexe').selectOption('F')
    await page.getByLabel('Numéro CNI / récépissé').fill(`CI-CAP-${w}`)
    await page.getByLabel('Ville de résidence').fill('Abidjan')
    await page.getByLabel('Téléphone').fill('0709081011')
    await page.getByLabel('Adresse e-mail').fill(email)
    await page.getByLabel('Mot de passe', { exact: true }).fill('MotDePasse2026')
    await page.getByLabel('Confirmer le mot de passe').fill('MotDePasse2026')
    await page.getByLabel(/Je déclare résider en Côte d'Ivoire/).check()
    await page.getByLabel(/J'accepte les conditions/).check()
    await page.getByRole('button', { name: /Créer mon compte/i }).click()
    await page.waitForURL(/\/candidat$/, { timeout: 40_000 })

    await page.getByRole('link', { name: 'Commencer' }).click()
    await page.getByRole('button', { name: 'Suivant' }).click() // étape 1

    // Étape 2 : filière + confirmation.
    await page.getByText('Étape 2 / 10').waitFor()
    await page.getByRole('button', { name: /Agent de cuisine/ }).click()
    await page.getByLabel(/Je confirme/).check()
    await page.getByRole('button', { name: 'Suivant' }).click()

    // Étape 3 : segmenté Oui/Non + cartes + footer wizard empilé.
    await page.getByText('Étape 3 / 10').waitFor()
    await shot(page, `wizard-scolaire-${w}`)

    await pickRadio(page, /actuellement scolarisé/i, 'Non')
    await page.getByRole('radiogroup', { name: /Dernière classe/i }).locator('label.radio-row', { hasText: /^3ème$/ }).click()
    await pickRadio(page, /document justifiant ce niveau/i, 'Oui')
    await pickRadio(page, /programme de formation professionnelle/i, 'Oui')
    await page.getByRole('button', { name: 'Suivant' }).click()

    // Étape 4 -> 5 -> 6.
    await page.getByText('Étape 4 / 10').waitFor()
    await pickRadio(page, /orphelin/i, 'Non')
    await page.getByRole('radiogroup', { name: /Situation d’emploi/i }).locator('label.radio-row', { hasText: 'Stage' }).click()
    await pickRadio(page, /soutien principal du ménage/i, 'Non')
    await page.getByRole('button', { name: 'Suivant' }).click()

    await page.getByText('Étape 5 / 10').waitFor()
    await page.getByRole('button', { name: 'Suivant' }).click() // expérience : liste vide

    // Étape 6 : échelle langues (2×2 sous 420px).
    await page.getByText('Étape 6 / 10').waitFor()
    await shot(page, `wizard-langues-${w}`)

    for (const label of [
      'Français écrit', 'Français parlé', 'Compréhension orale',
      'Word (traitement de texte)', 'Excel (tableur)', 'Internet & messagerie',
    ]) {
      await page.getByRole('radiogroup', { name: label }).getByRole('radio', { name: 'Intermédiaire' }).click()
    }
    await page.getByRole('button', { name: 'Suivant' }).click()

    // Étape 7 : flèches de classement ≥44px.
    await page.getByText('Étape 7 / 10').waitFor()
    await shot(page, `wizard-classement-${w}`)
  })
}
