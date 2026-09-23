import { Link } from 'react-router-dom'
import { paths } from '../../routing/routes.js'

/** Pied de page public — reprend `.site-footer` de la maquette. */
export function PublicFooter() {
  return (
    <footer className="site-footer">
      <div className="container">
        <div className="footer-grid">
          <div>
            <div className="brand" style={{ color: '#fff', marginBottom: 'var(--space-4)' }}>
              <span className="brand-mark">
                <i className="fa-solid fa-seedling" aria-hidden="true" />
              </span>{' '}
              CASA
            </div>
            <p style={{ color: 'var(--casa-secondary-300)', fontSize: 'var(--fs-body-sm)', maxWidth: 280 }}>
              Initiative CASA — Arbre de Vie 2026. Formation et insertion aux métiers de l'hôtellerie et de la
              restauration en Côte d'Ivoire.
            </p>
          </div>
          <div>
            <h4>Plateforme</h4>
            <ul>
              <li><a href="#programme">Le projet</a></li>
              <li><a href="#filieres">Les formations</a></li>
              <li><a href="#comment">Comment candidater</a></li>
              <li><a href="#faq">FAQ</a></li>
            </ul>
          </div>
          <div>
            <h4>Accès</h4>
            <ul>
              <li><Link to={paths.login}>Se connecter</Link></li>
              <li><Link to={paths.inscription}>Candidater</Link></li>
            </ul>
          </div>
          <div className="footer-partners-col">
            <h4>Partenaires</h4>
            <div className="footer-partners-logo-wrap">
              <img
                src="/partner_logo.png"
                alt="Logos des partenaires : Agence Italienne pour la Coopération au Développement (AICS), Fondation Arbre de Vie Côte d'Ivoire, Projet de Formation et d'Insertion aux Métiers de l'Hôtellerie et de la Restauration, CCI-Côte d'Ivoire"
                className="footer-partners-logo"
              />
            </div>
          </div>
        </div>
        <div className="footer-bottom">
          <span>© 2026 Initiative CASA — Arbre de Vie.</span>
          <span>CCI-CI · Fondation Arbre de Vie · AICS</span>
        </div>
      </div>
    </footer>
  )
}
