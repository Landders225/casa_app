import { execSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'
import { expect, test } from '@playwright/test'

/**
 * Lot 12c — historique in-app des notifications (canal `database`, ADR-33).
 *
 * Parcours réel : inscription (déclenche `InscriptionConfirmee`, Lot 12b) ->
 * badge « 1 » sur le lien Notifications de la sidebar -> écran Notifications
 * affiche la ligne -> clic la marque lue -> badge disparaît, persiste après F5.
 *
 * Dev : pas de worker (Lot 12a — service `worker` prod uniquement). La
 * notification `ShouldQueue` attend en file : on la traite manuellement.
 */
const APP = fileURLToPath(new URL('../..', import.meta.url))
const artisan = (cmd) =>
  execSync(`docker compose exec -T backend php artisan ${cmd}`, { cwd: APP, stdio: 'pipe' }).toString()

test('inscription → notification in-app → badge → marquer lu', async ({ page }) => {
  const email = `e2e-notif+${Date.now()}@example.ci`

  await page.goto('/inscription')
  await page.getByLabel('Prénom').fill('Fatou')
  await page.getByLabel('Nom', { exact: true }).fill('Notif')
  await page.getByLabel('Date de naissance').fill('2001-05-14')
  await page.getByLabel('Sexe').selectOption('F')
  await page.getByLabel('Numéro CNI / récépissé').fill('CI0099001122')
  await page.getByLabel('Ville de résidence').fill('Abidjan - Cocody')
  await page.getByLabel('Téléphone').fill('0709081012')
  await page.getByLabel('Adresse e-mail').fill(email)
  await page.getByLabel('Mot de passe', { exact: true }).fill('MotDePasse2026')
  await page.getByLabel('Confirmer le mot de passe').fill('MotDePasse2026')
  await page.getByLabel(/Je déclare résider en Côte d'Ivoire/).check()
  await page.getByLabel(/J'accepte les conditions/).check()
  await page.getByRole('button', { name: /Créer mon compte/i }).click()
  await expect(page).toHaveURL(/\/candidat$/, { timeout: 40000 })

  // Traite la file (dev, pas de worker) : InscriptionConfirmee -> canal database écrit.
  artisan('queue:work --stop-when-empty')

  // Badge « 1 » sur le lien Notifications (se recharge à chaque navigation).
  // Mobile : sidebar hors-écran par défaut — ouvrir le menu avant d'interagir.
  await page.reload()
  // Sidebar hors-écran par défaut sous le point de rupture mobile (1080px,
  // cf. layouts.css) : ouvrir le menu AVANT d'interagir. Décidé sur la largeur
  // du viewport (déterministe), pas sur une visibilité instantanée du bouton
  // (course possible juste après un reload, pendant l'hydratation React).
  const viewport = page.viewportSize()
  if (viewport && viewport.width <= 1080) {
    await page.getByRole('button', { name: 'Ouvrir le menu' }).click()
  }

  const lienNotifications = page.getByRole('link', { name: /notifications/i })
  await expect(lienNotifications).toBeVisible({ timeout: 30_000 })
  await expect(lienNotifications.getByText('1')).toBeVisible({ timeout: 30_000 })

  // Écran Notifications : la ligne, non lue.
  await lienNotifications.click()
  await expect(page).toHaveURL(/\/candidat\/notifications$/)
  await expect(page.getByRole('heading', { level: 2, name: 'Notifications' })).toBeVisible()
  await expect(page.getByText('Inscription confirmée')).toBeVisible({ timeout: 30_000 })
  await expect(page.getByText('Non lu')).toBeVisible()

  // Clic sur la ligne non lue -> marquée lue (PATCH réel).
  await page.getByText('Inscription confirmée').click()
  await expect(page.getByText('Non lu')).not.toBeVisible({ timeout: 30_000 })

  // Persiste après F5 : le badge a disparu (0 non lue), pas seulement l'état local React.
  await page.reload()
  await expect(page.getByText('Non lu')).not.toBeVisible()
  const lienApresLecture = page.getByRole('link', { name: /notifications/i })
  await expect(lienApresLecture.getByText(/^\d+$/)).not.toBeVisible()
})
