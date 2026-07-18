import { test, expect } from '@playwright/test';
import { expectNoLaravelError } from './helpers.js';

test.use({ storageState: 'storage/playwright-state/admin.json' });

test.describe('Credit Notes & Vendors admin pages', () => {
  test('credit notes index renders', async ({ page }) => {
    const response = await page.goto('/admin/AW/credit-notes', { waitUntil: 'networkidle' });
    expect(response?.status()).toBeLessThan(500);
    await expectNoLaravelError(page);
    await expect(page.locator('h1').first()).toBeVisible();
  });

  test('credit notes filter form opens without error', async ({ page }) => {
    const response = await page.goto('/admin/AW/credit-notes', { waitUntil: 'networkidle' });
    expect(response?.status()).toBeLessThan(500);

    // Opening the filter panel forces Filament to evaluate getModel() which is
    // where the modifyQueryUsing closure was crashing with $q vs $query.
    const filterBtn = page.locator('button:has-text("Filter"), button[aria-label*="filter" i]').first();
    if (await filterBtn.isVisible({ timeout: 2000 }).catch(() => false)) {
      await filterBtn.click();
      await page.waitForTimeout(500);
      await expectNoLaravelError(page);
    }
  });

  test('credit notes create form renders', async ({ page }) => {
    await page.goto('/admin/AW/credit-notes/create', { waitUntil: 'networkidle' });
    await expectNoLaravelError(page);
    await expect(page.locator('input[type="text"], input[type="number"]').first()).toBeVisible();
  });

  test('vendors index renders with seeded vendors', async ({ page }) => {
    await page.goto('/admin/AW/vendors', { waitUntil: 'networkidle' });
    await expectNoLaravelError(page);
    await expect(page.locator('body')).toContainText(/Cool-Air|BrightSpark|CleanFleet|SecureGuard/, { timeout: 5000 });
  });

  test('vendor edit page loads contacts and contracts relation managers', async ({ page }) => {
    await page.goto('/admin/AW/vendors', { waitUntil: 'networkidle' });
    const firstEditLink = page.locator('a[href*="/admin/AW/vendors/"][href*="/edit"]').first();
    await firstEditLink.click();
    await page.waitForLoadState('networkidle');
    await expectNoLaravelError(page);
    await expect(page.locator('body')).toContainText(/Contact|Contract|عقد|جهة/i);
  });

  test('maintenance request edit shows external vendor field', async ({ page }) => {
    await page.goto('/admin/AW/requests', { waitUntil: 'networkidle' });
    const firstEditLink = page.locator('a[href*="/admin/AW/requests/"][href*="/edit"]').first();
    await firstEditLink.click();
    await page.waitForLoadState('networkidle');
    await expectNoLaravelError(page);
    await expect(page.locator('body')).toContainText(/External Vendor|مورد خارجي/i);
  });
});
