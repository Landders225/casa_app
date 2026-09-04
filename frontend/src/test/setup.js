import '@testing-library/jest-dom/vitest'

// jsdom ne fournit pas window.scrollTo : le wizard le rappelle à chaque « Suivant ».
window.scrollTo = () => {}

// jsdom n'implémente pas matchMedia (utilisé par les media queries JS éventuelles).
if (!window.matchMedia) {
  window.matchMedia = (query) => ({
    matches: false,
    media: query,
    onchange: null,
    addEventListener: () => {},
    removeEventListener: () => {},
    addListener: () => {},
    removeListener: () => {},
    dispatchEvent: () => false,
  })
}
