import { useEffect, useRef } from 'react'

/**
 * Révélation au défilement — porte les `[data-reveal]` de la maquette
 * (opacity/translate -> `.is-visible`, cf. `src/styles/index.css`).
 *
 * Sûreté : le masquage initial est scopé à `html.js-reveal`, classe posée ICI
 * uniquement si le JS tourne ET que `prefers-reduced-motion` n'est pas demandé.
 * En reduced-motion ou sans JS, tout est visible d'emblée (jamais de page vide).
 *
 * Robustesse : un MutationObserver capte les `[data-reveal]` ajoutés après coup
 * (cartes filières chargées en async) ; un filet de sécurité révèle tout après
 * 4 s au cas où l'IntersectionObserver ne se déclencherait pas.
 *
 * Usage : `ref={useScrollReveal()}` sur un conteneur.
 */
export function useScrollReveal() {
  const containerRef = useRef(null)

  useEffect(() => {
    const root = containerRef.current
    if (!root) return

    const reduced = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches
    if (reduced || typeof IntersectionObserver === 'undefined') {
      // Rien à masquer : on ne pose pas `js-reveal`, le contenu reste visible.
      return
    }

    document.documentElement.classList.add('js-reveal')

    const io = new IntersectionObserver(
      (entries) => {
        for (const entry of entries) {
          if (entry.isIntersecting) {
            entry.target.classList.add('is-visible')
            io.unobserve(entry.target)
          }
        }
      },
      { threshold: 0.1, rootMargin: '0px 0px -40px 0px' },
    )

    const observeAll = () => {
      root.querySelectorAll('[data-reveal]:not(.is-visible)').forEach((el) => io.observe(el))
    }
    observeAll()

    // Cartes / éléments montés après le premier rendu (fetch async).
    const mo = new MutationObserver(observeAll)
    mo.observe(root, { childList: true, subtree: true })

    // Filet de sécurité.
    const safety = window.setTimeout(() => {
      root.querySelectorAll('[data-reveal]:not(.is-visible)').forEach((el) => el.classList.add('is-visible'))
    }, 4000)

    return () => {
      io.disconnect()
      mo.disconnect()
      window.clearTimeout(safety)
      document.documentElement.classList.remove('js-reveal')
    }
  }, [])

  return containerRef
}
