import { test, expect } from '@playwright/test';
import { expectNoLaravelError, captureConsoleErrors } from './helpers.js';

test.use({ storageState: 'storage/playwright-state/portal.json' });

const PORTAL_PAGES = [
  { name: 'Portal Dashboard', url: '/portal' },
  { name: 'Portal Invoices index', url: '/portal/invoices' },
];

for (const { name, url } of PORTAL_PAGES) {
  test(`${name} loads cleanly`, async ({ page }) => {
    const errors = await captureConsoleErrors(page);
    const response = await page.goto(url);
    expect(response.status()).toBeLessThan(400);
    await expectNoLaravelError(page);
    await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});
    expect(errors).toEqual([]);
  });
}

test('Portal: tenant can view their own invoice', async ({ page }) => {
  await page.goto('/portal/invoices');
  await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});
  const firstViewLink = page.locator('a[href*="/portal/invoices/"]').first();
  await expect(firstViewLink).toBeVisible({ timeout: 10000 });
  const href = await firstViewLink.getAttribute('href');
  await page.goto(new URL(href, 'http://x').pathname);
  await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});
  await expectNoLaravelError(page);
  // Portal view page should show invoice number somewhere
  await expect(page.locator('body')).toContainText(/INV-/i);
});

test('Portal: cannot access admin', async ({ page }) => {
  const response = await page.goto('/admin');
  // Either redirected to admin/login or shown 403
  expect(response.status()).toBeLessThan(500);
  expect(page.url()).not.toMatch(/\/admin$/);
});
