import { test } from '@playwright/test'

/**
 * Captures de fidélité visuelle — accueil / inscription / connexion, dans les
 * deux projets (desktop 1280 / largeur mobile 390). Fichiers -> e2e/__screenshots__/.
 * Ce ne sont PAS des tests de non-régression visuelle (pas de comparaison pixel),
 * juste des images à examiner pour juger la fidélité à la maquette.
 */
const DIR = 'e2e/__screenshots__'

/** Fait défiler toute la page pour forcer le rendu, puis capture en pleine hauteur. */
async function fullShot(page, path) {
  await page.waitForLoadState('networkidle')
  await page.evaluate(async () => {
    const step = window.innerHeight
    for (let y = 0; y <= document.body.scrollHeight; y += step) {
      window.scrollTo(0, y)
      await new Promise((r) => setTimeout(r, 60))
    }
    window.scrollTo(0, 0)
    await new Promise((r) => setTimeout(r, 150))
  })
  await page.screenshot({ path, fullPage: true, animations: 'disabled' })
}

test('capture — accueil', async ({ page }, testInfo) => {
  await page.goto('/')
  await page.getByRole('heading', { name: 'Votre avenir commence ici.' }).waitFor()
  await page.getByText('Choisissez votre métier de demain').waitFor()
  await fullShot(page, `${DIR}/accueil-${testInfo.project.name}.png`)
})

test('capture — inscription', async ({ page }, testInfo) => {
  await page.goto('/inscription')
  await page.getByRole('heading', { name: /Créer votre compte candidat/i }).waitFor()
  await page.getByRole('button', { name: /Créer mon compte/i }).waitFor()
  await fullShot(page, `${DIR}/inscription-${testInfo.project.name}.png`)
})

test('capture — connexion', async ({ page }, testInfo) => {
  await page.goto('/connexion')
  await page.getByRole('heading', { name: /Accéder à mon espace/i }).waitFor()
  await fullShot(page, `${DIR}/connexion-${testInfo.project.name}.png`)
})
