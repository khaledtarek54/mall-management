// Renders Atriom brand SVGs to PNG at a handful of useful sizes
// using the chromium that ships with @playwright/test. Run with:
//   node brand-assets/render.mjs
// Output lands in brand-assets/png/

import { chromium } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const SVG_DIR = path.join(__dirname, 'svg');
const PNG_DIR = path.join(__dirname, 'png');
fs.mkdirSync(PNG_DIR, { recursive: true });

/**
 * Each entry:
 *   svg: source file under brand-assets/svg/
 *   out: output basename (we'll append "-{w}x{h}.png")
 *   sizes: array of [width, height] in CSS pixels (1x); we render at 2x DPR for crispness
 *   bg: background fill ("white" / "#0F1419" / "transparent")
 *   scale: optional CSS scale factor (default 1) — for fitting wordmark inside square canvas
 */
const SPEC = [
    // Auto-adapt: render once on neutral light bg (it'll show the light variant of itself)
    { svg: 'atriom-logo.svg', out: 'atriom-logo-light', sizes: [[800, 200], [1600, 400]], bg: 'white' },
    { svg: 'atriom-logo.svg', out: 'atriom-logo-dark',  sizes: [[800, 200], [1600, 400]], bg: '#09090B', forceDark: true },

    // Explicit variants
    { svg: 'atriom-logo-light.svg', out: 'atriom-logo-light-explicit', sizes: [[800, 200], [1600, 400]], bg: 'white' },
    { svg: 'atriom-logo-dark.svg',  out: 'atriom-logo-dark-explicit',  sizes: [[800, 200], [1600, 400]], bg: '#09090B' },

    // Mark-only — for app icons, social avatars, OG cards
    { svg: 'atriom-mark.svg', out: 'atriom-mark-light', sizes: [[256, 256], [512, 512], [1024, 1024]], bg: 'white' },
    { svg: 'atriom-mark.svg', out: 'atriom-mark-dark',  sizes: [[256, 256], [512, 512], [1024, 1024]], bg: '#09090B' },

    // Favicon raster fallbacks (modern browsers prefer SVG; PNGs are for legacy / app stores)
    { svg: 'atriom-favicon.svg', out: 'atriom-favicon-light', sizes: [[16, 16], [32, 32], [64, 64], [180, 180], [256, 256], [512, 512]], bg: 'transparent' },
    { svg: 'atriom-favicon.svg', out: 'atriom-favicon-dark',  sizes: [[16, 16], [32, 32], [64, 64], [180, 180], [256, 256], [512, 512]], bg: 'transparent', forceDark: true },
];

const browser = await chromium.launch();

let total = 0;
for (const item of SPEC) {
    const svgPath = path.join(SVG_DIR, item.svg);
    if (!fs.existsSync(svgPath)) {
        console.warn(`! missing ${item.svg}`);
        continue;
    }
    const svgContent = fs.readFileSync(svgPath, 'utf8');

    for (const [w, h] of item.sizes) {
        // Render at 2x DPR for crisp PNGs
        const dpr = 2;
        const context = await browser.newContext({
            viewport: { width: w, height: h },
            deviceScaleFactor: dpr,
            colorScheme: item.forceDark ? 'dark' : 'light',
        });
        const page = await context.newPage();

        const html = `<!doctype html>
<meta charset="utf-8">
<style>
  html, body { margin: 0; padding: 0; width: 100%; height: 100%; overflow: hidden; background: ${item.bg}; }
  .wrap { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; }
  .wrap svg { width: 100%; height: 100%; max-width: 100%; max-height: 100%; display: block; }
</style>
<div class="wrap">${svgContent}</div>`;

        await page.setContent(html, { waitUntil: 'load' });
        const outPath = path.join(PNG_DIR, `${item.out}-${w}x${h}.png`);
        await page.screenshot({
            path: outPath,
            omitBackground: item.bg === 'transparent',
            type: 'png',
            clip: { x: 0, y: 0, width: w, height: h },
        });
        await context.close();
        total++;
        console.log(`✓ ${path.basename(outPath)}`);
    }
}

await browser.close();
console.log(`\nRendered ${total} PNGs to ${path.relative(process.cwd(), PNG_DIR)}/`);
