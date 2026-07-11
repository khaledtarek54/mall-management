import { test, expect } from '@playwright/test';
import { expectNoLaravelError } from './helpers.js';

test.use({ storageState: 'storage/playwright-state/admin.json' });

/**
 * Open the edit-page header-action (Filament 4 may inline it as a button OR
 * collapse it into an "Actions" dropdown when there are several). We try the
 * direct button first; if that's not visible quickly, we open the dropdown
 * and pick the action from there.
 */
/**
 * Click a Filament page-header action (downloadPdf, etc.) by name, surviving
 * Xdebug-slow coverage runs where the action may not be a directly-visible
 * inline button. Strategy:
 *   1. Try a visible button/link with the label.
 *   2. Fall back to Filament 4's `wire:click="mountAction('<name>')"` button,
 *      force-clicking even if hidden (e.g. inside an unopened dropdown).
 *
 * `actionName` mirrors the Action::make('<name>') string.
 */
async function clickHeaderAction(page, labelRegex, actionName) {
  const direct = page.locator('button:visible, a:visible').filter({ hasText: labelRegex }).first();
  if (await direct.isVisible({ timeout: 10000 }).catch(() => false)) {
    await direct.click();
    return;
  }
  // Filament 4 emits  wire:click="mountAction('downloadPdf')"  on every
  // header-action button. Targeting that attribute works whether the action
  // is rendered inline or tucked behind an overflow dropdown.
  const wireBtn = page.locator(`button[wire\\:click*="${actionName}"]`).first();
  await wireBtn.click({ force: true, timeout: 15000 });
}

test('Admin can download invoice PDF in English', async ({ page }) => {
  await page.goto('/locale/en');
  await page.goto('/admin/ALL/invoices');
  await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});

  const firstEdit = page.locator('a[href*="/admin/ALL/invoices/"][href$="/edit"]').first();
  const href = await firstEdit.getAttribute('href');
  await page.goto(new URL(href, 'http://x').pathname);
  await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});

  const downloadPromise = page.waitForEvent('download', { timeout: 30000 });
  await clickHeaderAction(page, /Download PDF/i, 'downloadPdf');
  const download = await downloadPromise;
  const path = await download.path();
  expect(path).toBeTruthy();
  const size = (await import('fs')).statSync(path).size;
  expect(size).toBeGreaterThan(1000);

  // Verify it's a real PDF
  const buf = (await import('fs')).readFileSync(path);
  expect(buf.slice(0, 4).toString()).toBe('%PDF');
});

test('Admin can download invoice PDF in Arabic', async ({ page }) => {
  await page.goto('/locale/ar');
  await page.goto('/admin/ALL/invoices');
  await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});

  const firstEdit = page.locator('a[href*="/admin/ALL/invoices/"][href$="/edit"]').first();
  const href = await firstEdit.getAttribute('href');
  await page.goto(new URL(href, 'http://x').pathname);
  await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});

  const downloadPromise = page.waitForEvent('download', { timeout: 30000 });
  await clickHeaderAction(page, /Download PDF|تنزيل|تحميل/i, 'downloadPdf');
  const download = await downloadPromise;
  const path = await download.path();
  const buf = (await import('fs')).readFileSync(path);
  expect(buf.slice(0, 4).toString()).toBe('%PDF');
});

test('Admin can download tenant statement PDF', async ({ page }) => {
  await page.goto('/locale/en');
  await page.goto('/admin/ALL/tenants');
  await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});

  const firstEdit = page.locator('a[href*="/admin/ALL/tenants/"][href$="/edit"]').first();
  const href = await firstEdit.getAttribute('href');
  await page.goto(new URL(href, 'http://x').pathname);
  await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});

  const downloadPromise = page.waitForEvent('download', { timeout: 30000 });
  // Target the header-action button precisely — a loose `button, a` + hasText
  // filter also matches a non-interactive responsive duplicate, and `.first()`
  // may click the wrong one (the action never dispatches → no download).
  await page.getByRole('button', { name: /^\s*(Statement|كشف الحساب)\s*$/ }).first().click();
  const download = await downloadPromise;
  const path = await download.path();
  const buf = (await import('fs')).readFileSync(path);
  expect(buf.slice(0, 4).toString()).toBe('%PDF');
  expect(buf.length).toBeGreaterThan(1000);
});
