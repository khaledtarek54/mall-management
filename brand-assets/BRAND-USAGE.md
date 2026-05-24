# Atriom Brand Assets

Everything your team needs to use the Atriom brand consistently.

---

## What's in this package

```
brand-assets/
├── BRAND-USAGE.md      ← this file
├── svg/                ← source of truth (use these whenever possible)
│   ├── atriom-logo.svg          ← AUTO-ADAPT (respects OS / browser dark mode)
│   ├── atriom-logo-light.svg    ← explicit light-background variant
│   ├── atriom-logo-dark.svg     ← explicit dark-background variant
│   ├── atriom-mark.svg          ← icon only (no wordmark)
│   └── atriom-favicon.svg       ← favicon (square, with bg) – auto-adapts
└── png/                ← raster exports for contexts that can't use SVG
    ├── atriom-logo-light-{800x200,1600x400}.png
    ├── atriom-logo-dark-{800x200,1600x400}.png
    ├── atriom-mark-light-{256,512,1024}.png
    ├── atriom-mark-dark-{256,512,1024}.png
    ├── atriom-favicon-light-{16,32,64,180,256,512}.png
    └── atriom-favicon-dark-{16,32,64,180,256,512}.png
```

---

## Which file to use where

| Use case | Best file |
|---|---|
| Website, web app, anywhere the browser renders the logo | `svg/atriom-logo.svg` (auto-adapts) |
| Slide decks / Keynote / PowerPoint with a light theme | `svg/atriom-logo-light.svg` or `png/atriom-logo-light-1600x400.png` |
| Slide decks / Keynote / PowerPoint with a dark theme | `svg/atriom-logo-dark.svg` or `png/atriom-logo-dark-1600x400.png` |
| Email signature | `png/atriom-logo-light-800x200.png` (most email clients are light) |
| Social profile avatar | `png/atriom-mark-light-512x512.png` or `-dark-` |
| App icon (iOS, Android, PWA) | `png/atriom-favicon-light-512x512.png` (or `-dark-`) |
| Apple touch icon | `png/atriom-favicon-light-180x180.png` |
| Browser favicon (modern browsers) | `svg/atriom-favicon.svg` (one file, auto-adapts) |
| Browser favicon (legacy / `.ico` workflows) | `png/atriom-favicon-light-32x32.png` |
| Social share / OG image | `png/atriom-logo-light-1600x400.png` (centered on a 1200×630 canvas) |
| Print materials (business card, letterhead) | SVG source — your designer should redraw on the print spec sheet |

---

## Brand identity at a glance

- **Name:** Atriom
- **Tagline:** *Egyptian mall operations, end to end.*
- **Type style:** Lowercase wordmark, Inter / system-ui font family, weight 700, letter-spacing −0.02em
- **Tone:** Modern, professional, monochrome-first. Brand color is used as accent, not chrome.

### Palette

| Token | Light mode | Dark mode | Use |
|---|---|---|---|
| Background | `#FFFFFF` | `#09090B` | Default page bg |
| Surface | `#FAFAFA` | `#18181B` | Cards, panels |
| Surface-2 | `#F4F4F5` | `#27272A` | Table headers, secondary surfaces |
| Ink | `#18181B` | `#FAFAFA` | Primary text, buttons |
| Ink-muted | `#52525B` | `#A1A1AA` | Secondary text |
| Ink-subtle | `#71717A` | `#71717A` | Tertiary text, dividers |
| **Teal** | `#0F766E` | `#14B8A6` | Brand accent, focus rings, mark color |
| **Amber** | `#D97706` | `#F59E0B` | Accent highlights, skylight dot, CTAs |

### Logo anatomy

- **Arch mark** — the stylized atrium opening. Teal in light mode, brighter teal in dark mode. Two vertical depth lines suggest the volume of an atrium.
- **Skylight dot** — small amber circle floating above the arch peak. The single warm accent in an otherwise cool palette.
- **Wordmark** — lowercase `atriom`, ink color in light, cream color in dark. Always paired alongside the mark (right side in LTR contexts) — never separately unless using `atriom-mark.svg`.

---

## Do / Don't

**Do:**
- Use SVG whenever the medium supports it (web, modern apps, slides)
- Pair the mark + wordmark together as a unit
- Respect the empty space around the logo (at minimum: the height of the dot on each side)
- Use the auto-adapting SVG when in doubt — it does the right thing in 95% of cases

**Don't:**
- Stretch or distort the proportions
- Recolor the mark or wordmark outside the documented palette
- Place the light-variant logo on a dark background (use the dark variant) — or vice versa
- Rasterize the SVG yourself; use the provided PNGs at the largest size needed and let the medium downscale
- Add effects (shadow, glow, stroke, gradient) — the logo is intentionally flat

---

## Regenerating the PNGs

If the source SVGs change, regenerate the PNGs:

```bash
node brand-assets/render.mjs
```

Requires `@playwright/test` chromium (already installed for E2E tests). Renders at 2× device pixel ratio for crispness.

---

## Questions

Brand decisions are documented in [FEATURES.md § Branding & i18n](../FEATURES.md). Strategic context (why these choices) lives in [MASTER-PLAN.md § 2 Competitive read](../MASTER-PLAN.md).
