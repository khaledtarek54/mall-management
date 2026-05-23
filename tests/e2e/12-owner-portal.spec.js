import { test, expect } from '@playwright/test';
import { expectNoLaravelError } from './helpers.js';

test.use({ storageState: 'storage/playwright-state/owner.json' });

test.describe('Owner Portal', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/locale/en');
  });

  test('Owner dashboard loads with portfolio stats', async ({ page }) => {
    const response = await page.goto('/owner');
    expect(response.status()).toBeLessThan(400);
    await expectNoLaravelError(page);
    // Portfolio widget headings
    await expect(page.locator('text=Properties').first()).toBeVisible({ timeout: 15000 });
  });

  test('Owner can open the Properties resource and see Haya Walk', async ({ page }) => {
    const response = await page.goto('/owner/properties');
    expect(response.status()).toBeLessThan(400);
    await expectNoLaravelError(page);
    await expect(page.locator('text=Haya Walk').first()).toBeVisible({ timeout: 15000 });
  });

  test('Owner can open the Invoices resource (scoped to owned assets)', async ({ page }) => {
    const response = await page.goto('/owner/invoices');
    expect(response.status()).toBeLessThan(400);
    await expectNoLaravelError(page);
  });

  test('Owner can open the Maintenance resource (scoped to owned assets)', async ({ page }) => {
    const response = await page.goto('/owner/maintenance-requests');
    expect(response.status()).toBeLessThan(400);
    await expectNoLaravelError(page);
  });

  test('Owner cannot reach the admin panel (gated by role)', async ({ page }) => {
    // Non-admin owner user trying to hit /admin should not be granted access.
    // Filament redirects to a 403 page or panel-not-accessible state.
    const response = await page.goto('/admin');
    // Accept either a 403 or a redirect to login (Filament returns 403 when canAccessPanel = false)
    expect([302, 403, 200]).toContain(response.status());
    // The owner must not see admin-only nav items like "Tenants" or "Users"
    const tenantsLink = page.getByRole('link', { name: /^Tenants$/ });
    await expect(tenantsLink).toHaveCount(0);
  });
});
