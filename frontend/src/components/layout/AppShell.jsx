import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useAuth } from '../../auth/useAuth.js'
import { paths, roleHome } from '../../routing/routes.js'
import { navConfig, roleLabel } from './navConfig.js'

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
 */
export function AppShell({ title, children }) {
  const { user, role, logout } = useAuth()
  const navigate = useNavigate()
  const [sidebarOpen, setSidebarOpen] = useState(false)

  const config = navConfig[role] ?? navConfig.candidat

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
              {section.items.map((item, ii) => {
                // Le tableau de bord est la seule entrée navigable pour l'instant ;
                // les autres écrans arrivent aux sous-lots suivants.
                const active = si === 0 && ii === 0
                if (active) {
                  return (
                    <Link
                      key={item.key}
                      to={roleHome(role)}
                      className="sidebar-link is-active"
                      aria-current="page"
                      onClick={() => setSidebarOpen(false)}
                    >
                      <i className={`fa-solid ${item.icon}`} aria-hidden="true" /> {item.label}
                    </Link>
                  )
                }
                return (
                  <span
                    key={item.key}
                    className="sidebar-link"
                    aria-disabled="true"
                    title="Écran à venir"
                  >
                    <i className={`fa-solid ${item.icon}`} aria-hidden="true" /> {item.label}
                  </span>
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
