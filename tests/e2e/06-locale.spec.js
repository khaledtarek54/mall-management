import { test, expect } from '@playwright/test';
import { expectNoLaravelError } from './helpers.js';

test.use({ storageState: 'storage/playwright-state/admin.json' });

test('Switching to Arabic sets html dir=rtl', async ({ page }) => {
  await page.goto('/locale/ar');
  await page.goto('/admin');
  await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});
  const dir = await page.locator('html').getAttribute('dir');
  expect(dir).toBe('rtl');
  const lang = await page.locator('html').getAttribute('lang');
  expect(lang).toBe('ar');
});

test('Switching to English sets html dir=ltr', async ({ page }) => {
  await page.goto('/locale/en');
  await page.goto('/admin');
  await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});
  const dir = await page.locator('html').getAttribute('dir');
  expect(dir).toBe('ltr');
});

test('Arabic locale renders translated nav labels', async ({ page }) => {
  await page.goto('/locale/ar');
  await page.goto('/admin');
  await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});
  // Look for Arabic translation of a known nav item
  await expect(page.locator('body')).toContainText(/الفواتير|المستأجرون|العقود/);
});

test('Arabic invoice index renders Arabic column headers', async ({ page }) => {
  await page.goto('/locale/ar');
  await page.goto('/admin/ALL/invoices');
  await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});
  // Status column header in Arabic; check the thead block contains at least one Arabic header
  await expect(page.locator('thead').first()).toContainText(/الحالة|الرقم|المستأجر|الإجمالي/);
});

test('Locale persists across page navigation', async ({ page }) => {
  await page.goto('/locale/ar');
  await page.goto('/admin');
  await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});
  await page.goto('/admin/ALL/invoices');
  await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});
  const dir = await page.locator('html').getAttribute('dir');
  expect(dir).toBe('rtl');
});
