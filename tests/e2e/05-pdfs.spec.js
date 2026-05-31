import { test, expect } from '@playwright/test';
import { expectNoLaravelError } from './helpers.js';

test.use({ storageState: 'storage/playwright-state/admin.json' });

test('Admin can download invoice PDF in English', async ({ page }) => {
  await page.goto('/locale/en');
  await page.goto('/admin/ALL/invoices');
  await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});

  const firstEdit = page.locator('a[href*="/admin/ALL/invoices/"][href$="/edit"]').first();
  const href = await firstEdit.getAttribute('href');
  await page.goto(new URL(href, 'http://x').pathname);
  await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});

  const downloadPromise = page.waitForEvent('download', { timeout: 30000 });
  await page.locator('button:has-text("Download PDF"), a:has-text("Download PDF")').first().click();
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
  // Try Arabic + English labels
  await page.locator('button, a').filter({ hasText: /Download PDF|تنزيل|PDF/i }).first().click();
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
  await page.locator('button, a').filter({ hasText: /Statement|كشف|بيان/i }).first().click();
  const download = await downloadPromise;
  const path = await download.path();
  const buf = (await import('fs')).readFileSync(path);
  expect(buf.slice(0, 4).toString()).toBe('%PDF');
  expect(buf.length).toBeGreaterThan(1000);
});
