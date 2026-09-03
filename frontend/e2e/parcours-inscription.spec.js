import { expect, test } from '@playwright/test'

/**
 * Parcours de bout en bout : accueil public -> inscription -> tableau de bord
 * candidat (auto-login après POST /api/register).
 */
test('accueil → inscription → dashboard candidat', async ({ page }) => {
  // 1. Accueil public
  await page.goto('/')
  await expect(page.getByRole('heading', { name: 'Votre avenir commence ici.' })).toBeVisible()
  await expect(page.locator('#filieres')).toBeVisible()
  await expect(page.getByText('Critères pouvant entraîner une élimination')).toBeVisible()

  // La vitrine ne révèle pas la grille de notation.
  const bodyText = (await page.locator('body').innerText()).toLowerCase()
  for (const interdit of ['sur 100', '/100', '/65', '/35', 'pondération', 'sous-critère']) {
    expect(bodyText, `« ${interdit} » ne doit pas apparaître sur l'accueil`).not.toContain(interdit)
  }

  // 2. Aller à l'inscription (CTA du hero — libellé sans ambiguïté)
  await page.getByRole('link', { name: 'Candidater maintenant' }).click()
  await expect(page).toHaveURL(/\/inscription$/)
  await expect(page.getByRole('heading', { name: /Créer votre compte candidat/i })).toBeVisible()

  // 3. Remplir le formulaire (e-mail unique par exécution)
  const email = `e2e+${Date.now()}@example.ci`
  await page.getByLabel('Prénom').fill('Aya')
  await page.getByLabel('Nom', { exact: true }).fill('E2E')
  await page.getByLabel('Date de naissance').fill('2001-05-14')
  await page.getByLabel('Sexe').selectOption('F')
  await page.getByLabel('Numéro CNI / récépissé').fill('CI0099887766')
  await page.getByLabel('Ville de résidence').fill('Abidjan - Cocody')
  await page.getByLabel('Téléphone').fill('0709081011')
  await page.getByLabel('Adresse e-mail').fill(email)
  await page.getByLabel('Mot de passe', { exact: true }).fill('MotDePasse2026')
  await page.getByLabel('Confirmer le mot de passe').fill('MotDePasse2026')
  await page.getByLabel(/Je déclare résider en Côte d'Ivoire/).check()
  await page.getByLabel(/J'accepte les conditions/).check()

  // 4. Soumettre -> auto-login -> dashboard
  await page.getByRole('button', { name: /Créer mon compte/i }).click()
  await expect(page).toHaveURL(/\/candidat$/, { timeout: 40000 })
  await expect(page.getByRole('heading', { name: /Bonjour Aya/i })).toBeVisible()
  await expect(page.getByText('Votre compte a été créé !')).toBeVisible()

  // 5. La session survit à un rechargement (reconstruite via GET /api/me)
  await page.reload()
  await expect(page).toHaveURL(/\/candidat$/)
  await expect(page.getByRole('heading', { name: /Bonjour Aya/i })).toBeVisible()
})
