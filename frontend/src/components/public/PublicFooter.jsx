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
              Projet CASA — Arbre de Vie 2026. Formation et insertion aux métiers de l'hôtellerie et de la
              restauration en Côte d'Ivoire.
            </p>
          </div>
          <div>
            <h4>Plateforme</h4>
            <ul>
              <li><a href="#programme">Le programme</a></li>
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
          <div>
            <h4>Partenaires</h4>
            <ul>
              <li><span>CCI-CI</span></li>
              <li><span>Fondation Arbre de Vie</span></li>
              <li><span>AICS</span></li>
            </ul>
          </div>
        </div>
        <div className="footer-bottom">
          <span>© 2026 Projet CASA — Arbre de Vie.</span>
          <span>CCI-CI · Fondation Arbre de Vie · AICS</span>
        </div>
      </div>
    </footer>
  )
}
