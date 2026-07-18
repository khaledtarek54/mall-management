import { test, expect } from '@playwright/test';
import { expectNoLaravelError, captureConsoleErrors } from './helpers.js';

test.use({ storageState: 'storage/playwright-state/admin.json' });

const PARENT_INDEX_FOR_EDIT = [
  { name: 'Asset', list: '/admin/AW/assets', editPath: 'edit' },
  { name: 'Unit', list: '/admin/AW/units', editPath: 'edit' },
  { name: 'Tenant', list: '/admin/AW/tenants', editPath: 'edit' },
  { name: 'Lease', list: '/admin/AW/leases', editPath: 'edit' },
  { name: 'Invoice', list: '/admin/AW/invoices', editPath: 'edit' },
  { name: 'Payment', list: '/admin/AW/payments', editPath: 'edit' },
];

for (const r of PARENT_INDEX_FOR_EDIT) {
  test(`${r.name}: opens first row's edit page cleanly`, async ({ page }) => {
    const errors = await captureConsoleErrors(page);
    await page.goto(r.list);
    await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {});

    // Find first row link to edit. Filament tables wrap rows in <a> or attach click handlers.
    const firstEditLink = page.locator(`a[href*="/${r.editPath}"]`).first();
    if (!(await firstEditLink.count())) {
      test.skip(true, `No ${r.name} records to edit`);
      return;
    }
    const targetHref = await firstEditLink.getAttribute('href');
    const targetPath = new URL(targetHref, 'http://x').pathname;
    // Skip wire:navigate to force a full page load (more reliable in Playwright)
    await page.goto(targetPath);
    await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {});
    expect(page.url()).toMatch(new RegExp(`/${r.editPath}$`));
    await expectNoLaravelError(page);
    expect(errors, `No JS errors editing ${r.name}`).toEqual([]);
  });
}

test('Invoice edit page exposes PDF download action', async ({ page }) => {
  await page.goto('/admin/AW/invoices');
  await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {});
  const firstEditLink = page.locator('a[href*="/edit"]').first();
  await firstEditLink.click();
  await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {});

  // The per-invoice PDF download action lives in the row action group; the bulk-PDF
  // action is in a hidden dropdown until selection. Match only visible buttons.
  const pdfButton = page.locator('button:visible, a:visible').filter({ hasText: /PDF|pdf|تنزيل/i }).first();
  await expect(pdfButton).toBeVisible({ timeout: 10000 });
});

test('Tenant edit page exposes statement download action', async ({ page }) => {
  await page.goto('/admin/AW/tenants');
  await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {});
  const firstEditLink = page.locator('a[href*="/edit"]').first();
  await firstEditLink.click();
  await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {});

  await expect(page.locator('button, a').filter({ hasText: /statement|كشف|بيان/i }).first()).toBeVisible({ timeout: 10000 });
});
