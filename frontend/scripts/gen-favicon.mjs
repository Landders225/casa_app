/**
 * CASA — génère les favicons PNG de secours (Lot 10).
 *
 *   node scripts/gen-favicon.mjs
 *
 * Aucune dépendance : dessin par primitives (capsule + ellipses tournées) dans
 * un buffer RGBA sur-échantillonné ×4 puis réduit, encodage PNG via `zlib`.
 * Les mêmes primitives que `public/favicon.svg` — le rendu est volontairement
 * proche, pas identique au pixel près.
 *
 * Sorties :
 *   public/favicon-32.png       (onglet, favoris — fallback des navigateurs
 *                                sans support SVG, ex. Safari < 16.4)
 *   public/apple-touch-icon.png (écran d'accueil iOS, 180×180)
 */
import { deflateSync } from 'node:zlib'
import { writeFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const OUT = join(dirname(fileURLToPath(import.meta.url)), '..', 'public')
const SS = 4 // supersampling

// Palette (charte CASA — cf. src/styles/variables.css)
const PRIMARY_700 = [0x0e, 0x5c, 0x53]
const PRIMARY_200 = [0xbf, 0xe3, 0xdb]
const WHITE = [0xff, 0xff, 0xff]

/** Couverture d'un pixel (0..1) — sur-échantillonnage déjà appliqué en amont. */
function roundedRectCover(x, y, w, h, r) {
  const cx = Math.min(Math.max(x, r), w - r)
  const cy = Math.min(Math.max(y, r), h - r)
  const dx = x - cx
  const dy = y - cy
  if (x >= r && x <= w - r) return y >= 0 && y <= h ? 1 : 0
  if (y >= r && y <= h - r) return x >= 0 && x <= w ? 1 : 0
  return dx * dx + dy * dy <= r * r ? 1 : 0
}

function segmentDist(px, py, ax, ay, bx, by) {
  const vx = bx - ax
  const vy = by - ay
  const wx = px - ax
  const wy = py - ay
  const t = Math.max(0, Math.min(1, (wx * vx + wy * vy) / (vx * vx + vy * vy)))
  const dx = px - (ax + t * vx)
  const dy = py - (ay + t * vy)
  return Math.hypot(dx, dy)
}

function inRotatedEllipse(px, py, cx, cy, rx, ry, deg) {
  const a = (-deg * Math.PI) / 180
  const dx = px - cx
  const dy = py - cy
  const ex = dx * Math.cos(a) - dy * Math.sin(a)
  const ey = dx * Math.sin(a) + dy * Math.cos(a)
  return (ex * ex) / (rx * rx) + (ey * ey) / (ry * ry) <= 1
}

/** Dessine l'icône 64u dans un buffer RGBA de `size` px (sans supersampling ici). */
function render(size) {
  const s = size / 64 // échelle unité -> pixel
  const px = (u) => u * s
  const buf = Buffer.alloc(size * size * 4)

  for (let y = 0; y < size; y++) {
    for (let x = 0; x < size; x++) {
      const u = (x + 0.5) / s
      const v = (y + 0.5) / s

      let rgb = null
      let alpha = 0

      // fond arrondi
      if (roundedRectCover(x + 0.5, y + 0.5, size, size, px(14)) > 0) {
        rgb = PRIMARY_700
        alpha = 1
      }

      if (alpha > 0) {
        // feuille gauche (primary-200), feuille droite (blanc), tige (blanc)
        if (inRotatedEllipse(u, v, 22, 27, 12, 6.5, -33)) rgb = PRIMARY_200
        if (inRotatedEllipse(u, v, 42, 24, 12, 6.5, 33)) rgb = WHITE
        if (segmentDist(u, v, 32, 54, 32, 25) <= 2.5) rgb = WHITE
      }

      const o = (y * size + x) * 4
      if (alpha > 0) {
        buf[o] = rgb[0]
        buf[o + 1] = rgb[1]
        buf[o + 2] = rgb[2]
        buf[o + 3] = 255
      }
    }
  }
  return buf
}

/** Rend en `size*SS` puis réduit en moyenne SS×SS -> anti-aliasing. */
function renderAA(size) {
  const big = render(size * SS)
  const out = Buffer.alloc(size * size * 4)
  for (let y = 0; y < size; y++) {
    for (let x = 0; x < size; x++) {
      let r = 0
      let g = 0
      let b = 0
      let a = 0
      for (let dy = 0; dy < SS; dy++) {
        for (let dx = 0; dx < SS; dx++) {
          const o = (((y * SS + dy) * size * SS) + (x * SS + dx)) * 4
          r += big[o]
          g += big[o + 1]
          b += big[o + 2]
          a += big[o + 3]
        }
      }
      const n = SS * SS
      const o = (y * size + x) * 4
      const av = a / n / 255
      // pré-multiplié -> non pré-multiplié pour PNG RGBA droit
      out[o] = av > 0 ? Math.round(r / n / av) : 0
      out[o + 1] = av > 0 ? Math.round(g / n / av) : 0
      out[o + 2] = av > 0 ? Math.round(b / n / av) : 0
      out[o + 3] = Math.round(a / n)
    }
  }
  return out
}

// --- Encodage PNG (RGBA, 8 bits, filtre 0) ---
function crc32(buf) {
  let c = ~0
  for (let i = 0; i < buf.length; i++) {
    c ^= buf[i]
    for (let k = 0; k < 8; k++) c = (c >>> 1) ^ (0xedb88320 & -(c & 1))
  }
  return ~c >>> 0
}

function chunk(type, data) {
  const len = Buffer.alloc(4)
  len.writeUInt32BE(data.length, 0)
  const typeBuf = Buffer.from(type, 'ascii')
  const body = Buffer.concat([typeBuf, data])
  const crc = Buffer.alloc(4)
  crc.writeUInt32BE(crc32(body), 0)
  return Buffer.concat([len, body, crc])
}

function encodePNG(size, rgba) {
  const sig = Buffer.from([137, 80, 78, 71, 13, 10, 26, 10])
  const ihdr = Buffer.alloc(13)
  ihdr.writeUInt32BE(size, 0)
  ihdr.writeUInt32BE(size, 4)
  ihdr[8] = 8 // bit depth
  ihdr[9] = 6 // color type RGBA
  const raw = Buffer.alloc(size * (size * 4 + 1))
  for (let y = 0; y < size; y++) {
    raw[y * (size * 4 + 1)] = 0 // filter: none
    rgba.copy(raw, y * (size * 4 + 1) + 1, y * size * 4, (y + 1) * size * 4)
  }
  return Buffer.concat([
    sig,
    chunk('IHDR', ihdr),
    chunk('IDAT', deflateSync(raw, { level: 9 })),
    chunk('IEND', Buffer.alloc(0)),
  ])
}

for (const [name, size] of [
  ['favicon-32.png', 32],
  ['apple-touch-icon.png', 180],
]) {
  const file = join(OUT, name)
  writeFileSync(file, encodePNG(size, renderAA(size)))
  console.log('écrit', file)
}
