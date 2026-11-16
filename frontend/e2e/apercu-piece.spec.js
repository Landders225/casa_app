import { execSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'
import { expect, test } from '@playwright/test'

/**
 * Lot 19 (correctif de revue) — vérification de bout en bout de l'aperçu en
 * ligne d'une pièce justificative, contre la VRAIE stack Docker (nginx réel,
 * donc la VRAIE CSP) : un vrai PDF (texte en Helvetica, police standard NON
 * embarquée) doit se dessiner sur un `<canvas>`, une vraie image doit
 * s'afficher, le worker pdf.js doit être chargé depuis le bundle (même
 * origine), et la console ne doit JAMAIS signaler de violation CSP.
 *
 * `DemoDocumentsSeeder` écrit des pièces PLACEHOLDER (texte brut, pas un vrai
 * PDF structuré — suffisant pour le téléchargement, pas pour un rendu réel).
 * Ce test les REMPLACE par de vrais fichiers (`e2e/fixtures/apercu-reel.*`)
 * — idempotent, PAS de `migrate:fresh` (destructif, jamais exécuté ici).
 */
const APP = fileURLToPath(new URL('../..', import.meta.url))
const artisanAsWwwData = (cmd) =>
  execSync(`docker compose exec -T -u www-data backend php artisan ${cmd}`, { cwd: APP, stdio: 'pipe' }).toString()
const dockerCp = (src, dest) => execSync(`docker compose cp "${src}" ${dest}`, { cwd: APP, stdio: 'pipe' }).toString()

const PWD = 'Demo2026!'

test.describe.configure({ mode: 'serial' })

test.describe('Aperçu en ligne — vérification avec de vrais fichiers (Lot 19)', () => {
  test.beforeAll(() => {
    test.setTimeout(60_000)
    artisanAsWwwData('db:seed --class=DemoDocumentsSeeder --force')
    dockerCp('frontend/e2e/fixtures/apercu-reel.pdf', 'backend:/tmp/apercu-reel.pdf')
    dockerCp('frontend/e2e/fixtures/apercu-reel.png', 'backend:/tmp/apercu-reel.png')
    // Commande artisan dédiée (E2eApercuFixtures) plutôt qu'un script inline
    // via `tinker --execute` : l'échappement multi-ligne `$`/guillemets à
    // travers Node -> shell -> docker exec -> tinker s'est montré trop
    // fragile (échec silencieux observé sur cette machine Windows).
    artisanAsWwwData('casa:e2e-apercu-fixtures /tmp/apercu-reel.pdf /tmp/apercu-reel.png')
  })

  test.afterAll(() => {
    // Remet le compte démo dans son état seedé normal (fichiers PLACEHOLDER,
    // `photo` redevenu `application/pdf`) pour les autres specs qui s'appuient
    // sur `documents@casa-demo.ci` (`documents.spec.js`) — EN `www-data`,
    // jamais en root (piège Docker-Windows déjà documenté : des fichiers
    // 0700 root:root deviennent illisibles par PHP-FPM).
    artisanAsWwwData('db:seed --class=DemoDocumentsSeeder --force')
  })

  test('aperçu PDF réel (Helvetica non embarquée) : canvas dessiné, worker chargé, aucune violation CSP', async ({ page }) => {
    const messagesConsole = []
    const violationsCsp = []
    const requetesReseau = []

    page.on('console', (msg) => {
      messagesConsole.push(`[${msg.type()}] ${msg.text()}`)
      if (/content security policy/i.test(msg.text())) violationsCsp.push(msg.text())
    })
    page.on('response', (res) => {
      if (/pdf\.worker/.test(res.url())) {
        requetesReseau.push(`${res.status()} ${res.headers()['content-type']} ${res.url()}`)
      }
    })

    await page.goto('/connexion')
    await page.getByLabel('Adresse e-mail').fill('documents@casa-demo.ci')
    await page.getByLabel('Mot de passe', { exact: true }).fill(PWD)
    await page.getByRole('button', { name: /se connecter/i }).click()
    await page.waitForURL(/\/candidat$/, { timeout: 40_000 })

    await page.goto('/candidat/documents')
    await expect(page.getByRole('heading', { level: 2, name: 'Documents' })).toBeVisible({ timeout: 30_000 })

    const ligneCni = page.locator('.file-row', { hasText: 'cni.pdf' })
    await ligneCni.getByRole('button', { name: /aperçu/i }).click()

    const modale = page.getByRole('dialog')
    await expect(modale).toBeVisible()
    const canvas = modale.locator('canvas')
    await expect(canvas).toBeVisible({ timeout: 20_000 })

    // Le canvas a bien du CONTENU dessiné (pas transparent/blanc) — on échantillonne
    // les pixels : un texte Helvetica noir sur fond blanc donne forcément des
    // pixels non-blancs quelque part.
    const aDuContenu = await canvas.evaluate((el) => {
      const ctx = el.getContext('2d')
      const { data } = ctx.getImageData(0, 0, el.width, el.height)
      for (let i = 0; i < data.length; i += 4) {
        const [r, g, b, a] = [data[i], data[i + 1], data[i + 2], data[i + 3]]
        if (a > 0 && (r < 250 || g < 250 || b < 250)) return true
      }
      return false
    })
    expect(aDuContenu).toBe(true)

    await page.getByRole('button', { name: /fermer/i }).click()
    await expect(modale).not.toBeVisible()

    console.log('--- messages console (PDF) ---')
    console.log(messagesConsole.join('\n') || '(aucun)')
    console.log('--- requêtes réseau worker pdf.js ---')
    console.log(requetesReseau.join('\n') || '(aucune)')
    console.log('--- avertissements standardFontDataUrl/wasmUrl ---')
    const avertissementsFontsWasm = messagesConsole.filter((m) => /standardFontDataUrl|wasmUrl|useSystemFonts/i.test(m))
    console.log(avertissementsFontsWasm.join('\n') || '(aucun avertissement — pdf.js est passé par la substitution de police système, sans erreur)')

    expect(violationsCsp, `Violations CSP détectées :\n${violationsCsp.join('\n')}`).toEqual([])
  })

  test('aperçu image réelle (PNG) : <img> affichée, décodée, zoomable', async ({ page }) => {
    const violationsCsp = []
    page.on('console', (msg) => {
      if (/content security policy/i.test(msg.text())) violationsCsp.push(msg.text())
    })

    await page.goto('/connexion')
    await page.getByLabel('Adresse e-mail').fill('documents@casa-demo.ci')
    await page.getByLabel('Mot de passe', { exact: true }).fill(PWD)
    await page.getByRole('button', { name: /se connecter/i }).click()
    await page.waitForURL(/\/candidat$/, { timeout: 40_000 })

    await page.goto('/candidat/documents')
    await expect(page.getByRole('heading', { level: 2, name: 'Documents' })).toBeVisible({ timeout: 30_000 })

    const lignePhoto = page.locator('.file-row', { hasText: 'photo.png' })
    await lignePhoto.getByRole('button', { name: /aperçu/i }).click()

    const modale = page.getByRole('dialog')
    const img = modale.locator('img')
    await expect(img).toBeVisible({ timeout: 20_000 })

    const decodee = await img.evaluate((el) => el.complete && el.naturalWidth > 0)
    expect(decodee).toBe(true)

    expect(violationsCsp, `Violations CSP détectées :\n${violationsCsp.join('\n')}`).toEqual([])
  })
})
