import { test, expect } from '@playwright/test';
import { expectNoLaravelError } from './helpers.js';

test.use({ storageState: 'storage/playwright-state/admin.json' });

test.describe('CAM Reconciliation', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/locale/en');
    await page.goto('/operator/switch/all');
  });

  test('CAM Reconciliation page is reachable and renders', async ({ page }) => {
    const response = await page.goto('/admin/cam-expense-pools');
    expect(response.status()).toBeLessThan(400);
    await expectNoLaravelError(page);
    await expect(page.getByRole('heading', { name: 'CAM Reconciliation' })).toBeVisible({ timeout: 15000 });
  });

  test('Seeded pools render with both Haya Walk rows', async ({ page }) => {
    await page.goto('/admin/cam-expense-pools');
    await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});
    // Two pool rows seeded by HayaWalkSeeder (last year + current year) — so Haya Walk
    // appears twice in the asset column.
    const hayaWalkCells = page.locator('td').filter({ hasText: 'Haya Walk' });
    await expect(hayaWalkCells.first()).toBeVisible({ timeout: 10000 });
    await expect(hayaWalkCells).toHaveCount(2);
  });
});
