import { test, expect } from '@playwright/test';
import { clickHeaderAction } from './helpers.js';

test.use({ storageState: 'storage/playwright-state/admin.json' });

test('Admin can download invoice PDF in English', async ({ page }) => {
  await page.goto('/locale/en');
  await page.goto('/admin/AW/invoices');
  await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});

  const firstEdit = page.locator('a[href*="/admin/AW/invoices/"][href$="/edit"]').first();
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
  await page.goto('/admin/AW/invoices');
  await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});

  const firstEdit = page.locator('a[href*="/admin/AW/invoices/"][href$="/edit"]').first();
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
  await page.goto('/admin/AW/tenants');
  await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});

  const firstEdit = page.locator('a[href*="/admin/AW/tenants/"][href$="/edit"]').first();
  const href = await firstEdit.getAttribute('href');
  await page.goto(new URL(href, 'http://x').pathname);
  await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});

  const downloadPromise = page.waitForEvent('download', { timeout: 30000 });
  // By ACTION NAME, not by label: a loose `button, a` + hasText filter also
  // matches a non-interactive responsive duplicate, and `.first()` may click
  // the wrong one (the action never dispatches → no download).
  await clickHeaderAction(page, /^\s*(Statement|كشف الحساب)\s*$/, 'statement');
  const download = await downloadPromise;
  const path = await download.path();
  const buf = (await import('fs')).readFileSync(path);
  expect(buf.slice(0, 4).toString()).toBe('%PDF');
  expect(buf.length).toBeGreaterThan(1000);
});
