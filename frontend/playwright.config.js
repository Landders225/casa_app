import { defineConfig, devices } from '@playwright/test'

/**
 * E2E CASA — joue contre la STACK DOCKER (reverse-proxy nginx :8080).
 *
 * Prérequis : `docker compose up -d` à la racine du dépôt (les 4 services).
 * Pas de `webServer` ici : on teste le vrai assemblage servi par nginx, pas le
 * dev-server Vite.
 *
 * ⚠️ Base de données : l'E2E crée un vrai compte candidat par exécution (e-mail
 * horodaté unique) dans la base `casa` de développement. C'est sans risque —
 * `docker compose exec -T backend php artisan migrate:fresh --seed` réinitialise.
 * Ne PAS pointer cette suite vers une base servant aux smokes d'autres lots.
 */
export default defineConfig({
  testDir: './e2e',
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: 0,
  workers: 1,
  reporter: [['list']],
  outputDir: './e2e/.results',
  // La stack Docker sous Windows a une latence HTTP marquée (~4-5 s / requête,
  // bind-mount + pas d'opcache) : les timeouts sont volontairement généreux.
  timeout: 90_000,
  expect: { timeout: 25_000 },
  use: {
    baseURL: process.env.E2E_BASE_URL || 'http://localhost:8080',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    locale: 'fr-FR',
    actionTimeout: 25_000,
    navigationTimeout: 40_000,
    // Les révélations au défilement (`[data-reveal]`) partent à opacity:0 — sans
    // ça, Playwright les considère « non visibles » tant qu'elles ne sont pas
    // dans le viewport. `useScrollReveal` court-circuite tout en reduced-motion,
    // ce qui donne aussi des captures dans l'état final.
    reducedMotion: 'reduce',
  },
  projects: [
    {
      name: 'desktop',
      use: { ...devices['Desktop Chrome'], viewport: { width: 1280, height: 800 } },
    },
    {
      // Largeur d'un smartphone (déclenche tous les points de rupture mobiles),
      // mais SANS `isMobile` : les captures `fullPage` sous émulation mobile
      // Playwright produisent des hauteurs erronées (bug connu). Le layout testé
      // reste bien celui du mobile.
      name: 'mobile',
      use: { ...devices['Desktop Chrome'], viewport: { width: 390, height: 844 }, isMobile: false },
      // Le parcours d'intégration (Lot 9a) tourne sur `desktop` uniquement — son
      // helper `shots()` bascule déjà le viewport 390/1280 pour les captures, et
      // ses variables de module partagées (freshEmail/freshId) ne survivraient
      // pas à un 2e passage projet.
      testIgnore: ['**/integration.spec.js'],
    },
  ],
})
