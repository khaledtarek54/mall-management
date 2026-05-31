import { test, expect } from '@playwright/test';
import { expectNoLaravelError } from './helpers.js';

test.use({ storageState: 'storage/playwright-state/admin.json' });

test.describe('Energy & Utilities', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/locale/en');
    await page.goto('/operator/switch/all');
  });

  test('Energy meters resource page loads', async ({ page }) => {
    const response = await page.goto('/admin/ALL/utility-meters');
    expect(response.status()).toBeLessThan(400);
    await expectNoLaravelError(page);
    await expect(page.getByRole('heading', { name: 'Energy & Utilities' })).toBeVisible({ timeout: 15000 });
  });

  test('Energy & Utilities appears in the admin sidebar nav', async ({ page }) => {
    const response = await page.goto('/admin');
    expect(response.status()).toBeLessThan(400);
    await expectNoLaravelError(page);
    // The UtilityMeterResource registers under Operations nav with label "Energy & Utilities"
    await expect(page.getByRole('link', { name: 'Energy & Utilities' })).toBeVisible({ timeout: 15000 });
  });
});
