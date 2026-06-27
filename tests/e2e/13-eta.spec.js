import { test, expect } from '@playwright/test';
import { expectNoLaravelError } from './helpers.js';

test.use({ storageState: 'storage/playwright-state/admin.json' });

test.describe('ETA e-invoicing', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/locale/en');
    await page.goto('/operator/switch/all');
  });

  test('Invoices index loads without error', async ({ page }) => {
    const response = await page.goto('/admin/ALL/invoices');
    expect(response.status()).toBeLessThan(400);
    await expectNoLaravelError(page);
  });

  test('Seeded ETA submissions render as Valid badges in the table', async ({ page }) => {
    await page.goto('/admin/ALL/invoices');
    await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {});
    // Seeder produces ~55 Valid ETA badges across the invoices table (recent invoices first).
    // Filament badges render the localized status text; "Valid" appears in multiple rows.
    // Scope to the table — a hidden "Valid" eta_status filter option renders
    // before the table and would otherwise be matched by .first().
    await expect(page.locator('table').getByText('Valid', { exact: true }).first()).toBeVisible({ timeout: 15000 });
  });
});
