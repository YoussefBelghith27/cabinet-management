// Decodes QR matrices produced by App\Core\QrCode with jsQR (test-only dependency in tests/qr-decoder/).
// Usage: node tests/qr-decode.mjs <cases.json>   — cases: [{ "text": "...", "matrix": [[0,1,...], ...] }, ...]
import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';

const require = createRequire(import.meta.url);
const jsQR = require('./qr-decoder/node_modules/jsqr/dist/jsQR.js');

const cases = JSON.parse(readFileSync(process.argv[2], 'utf8'));
const SCALE = 6;
const QUIET = 4;
const results = cases.map(({ text, matrix }) => {
  const n = matrix.length;
  const side = (n + 2 * QUIET) * SCALE;
  const pixels = new Uint8ClampedArray(side * side * 4).fill(255);
  for (let y = 0; y < n; y++) {
    for (let x = 0; x < n; x++) {
      if (!matrix[y][x]) continue;
      for (let dy = 0; dy < SCALE; dy++) {
        for (let dx = 0; dx < SCALE; dx++) {
          const i = (((y + QUIET) * SCALE + dy) * side + (x + QUIET) * SCALE + dx) * 4;
          pixels[i] = pixels[i + 1] = pixels[i + 2] = 0;
        }
      }
    }
  }
  const decoded = jsQR(pixels, side, side, { inversionAttempts: 'dontInvert' });
  return { ok: decoded !== null && decoded.data === text, size: n, version: (n - 17) / 4, got: decoded ? decoded.data : null };
});
console.log(JSON.stringify(results));
