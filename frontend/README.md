# CASA — Frontend (React + Vite)

SPA React qui consomme l'API Laravel du dépôt. Servie **same-origin** (`:8080`)
derrière le reverse-proxy nginx ; authentification **Sanctum SPA** en cookie de
session (ADR-01) — **aucun jeton stocké côté JS**.

## Scripts

```bash
npm install
npm run dev      # serveur Vite :5173 (proxifie /api + /sanctum vers :8080)
npm run build    # build de prod -> dist/
npm run lint     # oxlint
npm test         # vitest (jsdom)
```

En Docker, le `Dockerfile` build puis sert `dist/` via un nginx interne
(`nginx.conf` — fallback SPA `try_files $uri /index.html`).

## Structure (Lot 8a)

```
src/
  main.jsx / App.jsx        bootstrap + arbre de routes
  styles/                   COPIE VERBATIM de App_maquette/assets/css/ (ne pas éditer ici)
                            + index.css (ordre d'import, polices/icônes npm)
  lib/
    apiClient.js            client HTTP unique : cycle Sanctum, retry 419, ApiError typée
    csrf.js / ApiError.js
  auth/
    authContext.js          le contexte
    AuthProvider.jsx        état de session (loading/authenticated/guest), reconstruit depuis /api/me
    useAuth.js
  routing/
    routes.js               chemins + roleHome()
    ProtectedRoute.jsx      gardiennage par rôle (strict en 8a — cf. commentaire, à rebrancher au 8c)
    RedirectIfAuthed.jsx
  components/
    layout/AppShell.jsx     sidebar + topbar (design system maquette)
    ui/                     Spinner, Alert, FormField (wrappers sur les classes maquette)
  pages/
    auth/LoginPage.jsx      écran de connexion réel
    SpacePlaceholder.jsx    placeholders candidat / évaluateur / admin
    NotFoundPage.jsx
```

## Conventions

- Composants `PascalCase.jsx`, un par fichier ; hooks `useX.js`.
- **Toute** la logique API vit dans `lib/` ; **tout** l'état d'auth dans `auth/`.
- Le CSS métier réutilise les classes du design system (`btn`, `card`,
  `sidebar-link`…) — on ne réécrit pas leur style. Une correction de style remonte
  d'abord à `App_maquette`.
