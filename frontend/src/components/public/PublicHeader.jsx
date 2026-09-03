import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { paths } from '../../routing/routes.js'

const LANDING_LINKS = [
  { href: '#programme', label: 'Le programme' },
  { href: '#filieres', label: 'Les formations' },
  { href: '#comment', label: 'Comment candidater' },
  { href: '#faq', label: 'FAQ' },
  { href: '#contact', label: 'Contact' },
]

function Brand() {
  return (
    <Link to={paths.home} className="brand">
      <span className="brand-mark">
        <i className="fa-solid fa-seedling" aria-hidden="true" />
      </span>{' '}
      CASA{' '}
      <span className="text-muted fw-medium brand-subtitle" style={{ fontSize: '0.8em' }}>
        · Arbre de Vie
      </span>
    </Link>
  )
}

/**
 * En-tête public — reprend `.site-header` de la maquette.
 * `landing` : nav à ancres + menu mobile plein écran (`.mobile-nav`).
 * Sinon : brand + « Se connecter » (écrans publics secondaires, ex. inscription).
 */
export function PublicHeader({ landing = false }) {
  const [menuOpen, setMenuOpen] = useState(false)

  useEffect(() => {
    document.body.style.overflow = menuOpen ? 'hidden' : ''
    return () => {
      document.body.style.overflow = ''
    }
  }, [menuOpen])

  return (
    <>
      <header className="site-header">
        <div className="container">
          <Brand />
          {landing ? (
            <nav className="main-nav" aria-label="Navigation principale">
              <ul>
                {LANDING_LINKS.map((l) => (
                  <li key={l.href}>
                    <a href={l.href}>{l.label}</a>
                  </li>
                ))}
              </ul>
            </nav>
          ) : null}
          <div className="header-actions">
            <Link to={paths.login} className="btn btn-outline btn-sm">
              Se connecter
            </Link>
            <Link to={paths.inscription} className="btn btn-primary btn-sm">
              Candidater <i className="fa-solid fa-arrow-right" aria-hidden="true" />
            </Link>
            {landing ? (
              <button
                type="button"
                className="btn btn-icon btn-ghost nav-toggle"
                aria-label="Ouvrir le menu"
                aria-expanded={menuOpen}
                onClick={() => setMenuOpen(true)}
              >
                <i className="fa-solid fa-bars" aria-hidden="true" />
              </button>
            ) : null}
          </div>
        </div>
      </header>

      {landing && menuOpen ? (
        <div className="mobile-nav" role="dialog" aria-label="Menu">
          <div className="flex justify-between items-center" style={{ marginBottom: 'var(--space-4)' }}>
            <span className="brand">
              <span className="brand-mark">
                <i className="fa-solid fa-seedling" aria-hidden="true" />
              </span>{' '}
              CASA
            </span>
            <button
              type="button"
              className="btn btn-icon btn-ghost"
              aria-label="Fermer le menu"
              onClick={() => setMenuOpen(false)}
            >
              <i className="fa-solid fa-xmark" aria-hidden="true" />
            </button>
          </div>
          {LANDING_LINKS.map((l) => (
            <a key={l.href} href={l.href} onClick={() => setMenuOpen(false)}>
              {l.label}
            </a>
          ))}
          <Link to={paths.login} onClick={() => setMenuOpen(false)}>
            Se connecter
          </Link>
          <Link
            to={paths.inscription}
            className="btn btn-primary btn-block"
            style={{ marginTop: 'var(--space-4)', justifyContent: 'center' }}
            onClick={() => setMenuOpen(false)}
          >
            Candidater
          </Link>
        </div>
      ) : null}
    </>
  )
}
