import { test, expect } from '@playwright/test';
import { expectNoLaravelError } from './helpers.js';

test.use({ storageState: 'storage/playwright-state/admin.json' });

test.describe('Credit Notes & Vendors admin pages', () => {
  test('credit notes index renders', async ({ page }) => {
    await page.goto('/admin/credit-notes', { waitUntil: 'networkidle' });
    await expectNoLaravelError(page);
    await expect(page.locator('h1').first()).toBeVisible();
  });

  test('credit notes create form renders', async ({ page }) => {
    await page.goto('/admin/credit-notes/create', { waitUntil: 'networkidle' });
    await expectNoLaravelError(page);
    await expect(page.locator('input[type="text"], input[type="number"]').first()).toBeVisible();
  });

  test('vendors index renders with seeded vendors', async ({ page }) => {
    await page.goto('/admin/vendors', { waitUntil: 'networkidle' });
    await expectNoLaravelError(page);
    await expect(page.locator('body')).toContainText(/Cool-Air|BrightSpark|CleanFleet|SecureGuard/, { timeout: 5000 });
  });

  test('vendor edit page loads contacts and contracts relation managers', async ({ page }) => {
    await page.goto('/admin/vendors', { waitUntil: 'networkidle' });
    const firstEditLink = page.locator('a[href*="/admin/vendors/"][href*="/edit"]').first();
    await firstEditLink.click();
    await page.waitForLoadState('networkidle');
    await expectNoLaravelError(page);
    await expect(page.locator('body')).toContainText(/Contact|Contract|عقد|جهة/i);
  });

  test('maintenance request edit shows external vendor field', async ({ page }) => {
    await page.goto('/admin/maintenance-requests', { waitUntil: 'networkidle' });
    const firstEditLink = page.locator('a[href*="/admin/maintenance-requests/"][href*="/edit"]').first();
    await firstEditLink.click();
    await page.waitForLoadState('networkidle');
    await expectNoLaravelError(page);
    await expect(page.locator('body')).toContainText(/External Vendor|مورد خارجي/i);
  });
});
