import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

// CASA frontend (Vite).
//
// - En Docker : la SPA est buildée puis servie par le nginx interne du conteneur
//   `frontend`, et le reverse-proxy `nginx` route /api + /sanctum vers le backend.
//   Tout est donc SAME-ORIGIN (http://localhost:8080) — le cycle Sanctum marche
//   sans configuration côté navigateur.
// - En `npm run dev` (hors Docker, port 5173) : on proxifie /api et /sanctum vers
//   le backend exposé par docker-compose (:8080) pour rester same-origin du point
//   de vue du navigateur, sans toucher au backend.
export default defineConfig({
  plugins: [react()],
  // JSX automatique aussi pour les fichiers de test (Vitest utilise sa propre
  // instance esbuild ; sans cela le JSX des *.test.jsx compile en classic runtime
  // et échoue sur « React is not defined »).
  esbuild: { jsx: 'automatic', jsxImportSource: 'react' },
  server: {
    proxy: {
      '/api': { target: 'http://localhost:8080', changeOrigin: true },
      '/sanctum': { target: 'http://localhost:8080', changeOrigin: true },
    },
  },
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: ['./src/test/setup.js'],
    css: false,
    restoreMocks: true,
  },
})
