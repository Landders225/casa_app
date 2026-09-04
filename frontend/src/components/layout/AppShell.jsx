import { useState } from 'react'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import { useAuth } from '../../auth/useAuth.js'
import { paths } from '../../routing/routes.js'
import { navConfig, roleLabel } from './navConfig.js'

/**
 * Entrées de navigation RÉELLEMENT branchées, par rôle et par clé `navConfig`.
 * Les autres entrées restent affichées (fidélité maquette) mais inertes — elles
 * ne doivent pas paraître cliquables (cf. `.sidebar-link.is-inert`).
 */
const NAV_LINKS = {
  candidat: {
    dashboard: paths.candidat,
    candidature: paths.maCandidature,
  },
  evaluateur: { dashboard: paths.evaluateur, 'mes-dossiers': paths.evaluateurDossiers },
  administrateur: {
    dashboard: paths.admin,
    candidatures: paths.adminCandidatures,
    cqp: paths.adminFilieres,
    campagnes: paths.adminCampagnes,
    classement: paths.adminClassement,
    audit: paths.adminAudit,
  },
}

function initials(profil) {
  const p = (profil?.prenom || '').trim()
  const n = (profil?.nom || '').trim()
  return ((p[0] || '') + (n[0] || '')).toUpperCase() || '?'
}

function fullName(profil, fallback) {
  const name = [profil?.prenom, profil?.nom].filter(Boolean).join(' ').trim()
  return name || fallback
}

/**
 * Coquille des espaces authentifiés (candidat / évaluateur / administrateur).
 * Réutilise à l'identique `.app-shell` / `.app-sidebar` / `.app-topbar` /
 * `.app-content` du design system maquette (assets/css/layouts.css).
 *
 * Lot 8a : la navigation latérale est affichée mais inerte (fidélité visuelle) ;
 * seul le bouton « se déconnecter » est fonctionnel.
 *
 * `space` (Lot 8c-1, recouvrement admin⊇évaluateur, ADR-10) : la navigation
 * affichée dépend d'OÙ l'utilisateur se trouve, pas seulement de QUI il est.
 * Par défaut elle replie sur `role` (comportement inchangé pour candidat/
 * évaluateur/admin dans leur propre espace). Un écran évaluateur passe
 * explicitement `space="evaluateur"` : un administrateur qui y navigue voit donc
 * la sidebar évaluateur (cohérente avec l'écran affiché), tandis que le pied de
 * sidebar continue d'afficher son VRAI rôle (« Administrateur ») — l'identité
 * ne ment jamais, seule la navigation s'adapte au contexte.
 */
export function AppShell({ title, space, children }) {
  const { user, role, logout } = useAuth()
  const navigate = useNavigate()
  const { pathname } = useLocation()
  const [sidebarOpen, setSidebarOpen] = useState(false)

  const navRole = space ?? role
  const config = navConfig[navRole] ?? navConfig.candidat
  const links = NAV_LINKS[navRole] ?? NAV_LINKS.candidat

  const handleLogout = async () => {
    await logout()
    navigate(paths.login, { replace: true })
  }

  return (
    <div className="app-shell">
      <aside className={`app-sidebar${sidebarOpen ? ' is-open' : ''}`} id="appSidebar">
        <div className="sidebar-brand">
          <span className="brand" style={{ color: '#fff' }}>
            <span className="brand-mark">
              <i className="fa-solid fa-seedling" aria-hidden="true" />
            </span>{' '}
            CASA
          </span>
        </div>

        <nav className="sidebar-nav" aria-label={config.label}>
          {config.sections.map((section, si) => (
            <div key={section.title ?? si}>
              <div className="sidebar-section-label">{section.title ?? config.label}</div>
              {section.items.map((item) => {
                const href = links[item.key]
                // Entrées non branchées : affichées (fidélité maquette) mais
                // INERTES — pas de curseur pointer, état atténué (.is-inert).
                if (!href) {
                  return (
                    <span
                      key={item.key}
                      className="sidebar-link is-inert"
                      aria-disabled="true"
                      title="Écran à venir"
                    >
                      <i className={`fa-solid ${item.icon}`} aria-hidden="true" /> {item.label}
                    </span>
                  )
                }
                // « Mes dossiers » reste actif sur la fiche candidat (route
                // sœur /evaluateur/candidatures/:id, pas sous /mes-dossiers).
                const isActive = pathname === href
                  || pathname.startsWith(`${href}/`)
                  || (href === paths.evaluateurDossiers && pathname.startsWith('/evaluateur/candidatures/'))
                return (
                  <Link
                    key={item.key}
                    to={href}
                    className={`sidebar-link${isActive ? ' is-active' : ''}`}
                    aria-current={isActive ? 'page' : undefined}
                    onClick={() => setSidebarOpen(false)}
                  >
                    <i className={`fa-solid ${item.icon}`} aria-hidden="true" /> {item.label}
                  </Link>
                )
              })}
            </div>
          ))}
        </nav>

        <div className="sidebar-footer">
          <div className="sidebar-user">
            <span className="avatar avatar-sm">{initials(user?.profil)}</span>
            <div className="sidebar-user-info">
              <div className="name truncate">{fullName(user?.profil, user?.email)}</div>
              <div className="role">{roleLabel[role] ?? role}</div>
            </div>
            <button
              type="button"
              className="btn btn-icon btn-ghost"
              style={{ color: 'var(--casa-secondary-300)' }}
              onClick={handleLogout}
              aria-label="Se déconnecter"
            >
              <i className="fa-solid fa-right-from-bracket" aria-hidden="true" />
            </button>
          </div>
        </div>
      </aside>

      <button
        type="button"
        className={`sidebar-scrim${sidebarOpen ? ' is-visible' : ''}`}
        aria-label="Fermer le menu"
        tabIndex={sidebarOpen ? 0 : -1}
        onClick={() => setSidebarOpen(false)}
      />

      <div className="app-main">
        <header className="app-topbar">
          <div className="flex items-center gap-3">
            <button
              type="button"
              className="btn btn-icon btn-ghost sidebar-toggle-mobile"
              aria-label="Ouvrir le menu"
              aria-expanded={sidebarOpen}
              onClick={() => setSidebarOpen((v) => !v)}
            >
              <i className="fa-solid fa-bars" aria-hidden="true" />
            </button>
            <h1 className="h4" style={{ margin: 0 }}>
              {title}
            </h1>
          </div>
          <div className="flex items-center gap-3">
            <span className="avatar avatar-sm" style={{ background: 'var(--casa-accent-500)', color: 'var(--casa-primary-900)' }}>
              {initials(user?.profil)}
            </span>
          </div>
        </header>

        <main className="app-content page-enter">{children}</main>
      </div>
    </div>
  )
}
