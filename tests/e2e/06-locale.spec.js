import { test, expect } from '@playwright/test';

test.use({ storageState: 'storage/playwright-state/admin.json' });

/*
 * Locale tests are kept minimal because Laravel stores locale server-side
 * keyed by session ID — the cached admin.json gives every Playwright worker
 * the SAME session cookie, so parallel workers running locale-swapping tests
 * race on the same backend state and clobber each other.
 *
 * The two tests below only assert the html dir attribute right after a
 * locale switch in a single navigation pair, which is robust under racing.
 * Arabic UI text + cross-page persistence are covered indirectly by the
 * Pest TranslationCoverageTest and by every other e2e spec that survives
 * either locale.
 */

test('Switching to Arabic sets html dir=rtl', async ({ page }) => {
  await page.goto('/locale/ar');
  await page.goto('/admin');
  await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});
  const dir = await page.locator('html').getAttribute('dir');
  expect(dir).toBe('rtl');
  const lang = await page.locator('html').getAttribute('lang');
  expect(lang).toBe('ar');
});

test('Switching to English sets html dir=ltr', async ({ page }) => {
  await page.goto('/locale/en');
  await page.goto('/admin');
  await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});
  const dir = await page.locator('html').getAttribute('dir');
  expect(dir).toBe('ltr');
});
