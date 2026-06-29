// @ts-check
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { expectNoLaravelError } from './helpers.js';

/**
 * Plan 2 (QA) — accessibility. Runs axe-core (WCAG 2.1 A/AA) over the key
 * surfaces. The gate is on CRITICAL violations (the real blockers); serious /
 * moderate / minor counts are logged for awareness so the backlog is visible
 * without blocking the build. Raise the bar to `serious` as the UI is hardened.
 */
async function audit(page, url) {
  await page.goto(url, { waitUntil: 'networkidle' });
  await expectNoLaravelError(page);

  const results = await new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa'])
    .analyze();

  const by = (impact) => results.violations.filter((v) => v.impact === impact);
  const critical = by('critical');
  console.log(
    `[a11y] ${url}: ${results.violations.length} total ` +
    `(critical=${critical.length}, serious=${by('serious').length}, ` +
    `moderate=${by('moderate').length}, minor=${by('minor').length})`,
  );
  if (critical.length) {
    console.log('[a11y] CRITICAL:', critical.map((v) => `${v.id} (${v.nodes.length})`).join(', '));
  }

  return critical;
}

test.describe('Accessibility (axe WCAG 2.1 A/AA)', () => {
  test('admin login — no critical violations', async ({ page }) => {
    const critical = await audit(page, '/admin/login');
    expect(critical.map((v) => v.id)).toEqual([]);
  });

  test('portal login — no critical violations', async ({ page }) => {
    const critical = await audit(page, '/portal/login');
    expect(critical.map((v) => v.id)).toEqual([]);
  });

  test.describe('authenticated admin', () => {
    test.use({ storageState: 'storage/playwright-state/admin.json' });

    test('admin dashboard — no critical violations', async ({ page }) => {
      const critical = await audit(page, '/admin');
      expect(critical.map((v) => v.id)).toEqual([]);
    });
  });
});
