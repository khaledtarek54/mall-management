import { test, expect } from '@playwright/test';
import { expectNoLaravelError } from './helpers.js';

test.use({ storageState: 'storage/playwright-state/admin.json' });

test.describe('Reports module', () => {
  test('Reports page loads with KPI cards', async ({ page }) => {
    const response = await page.goto('/admin/reports', { waitUntil: 'networkidle' });
    expect(response?.status()).toBeLessThan(500);
    await expectNoLaravelError(page);
    await expect(page.locator('body')).toContainText(/Invoices Issued|الفواتير الصادرة/);
    await expect(page.locator('body')).toContainText(/Outstanding AR|الذمم المستحقة/);
  });

  test('Monthly Close PDF download works', async ({ page }) => {
    await page.goto('/admin/reports', { waitUntil: 'networkidle' });
    const downloadPromise = page.waitForEvent('download', { timeout: 30000 });
    await page.locator('button:visible').filter({ hasText: /Download Monthly Close PDF|تحميل تقرير الإقفال/ }).first().click();
    const download = await downloadPromise;
    expect(download.suggestedFilename()).toMatch(/^atriom-monthly-close-\d{4}-\d{2}\.pdf$/);
  });

  test('AR Aging detail page loads and lists invoices', async ({ page }) => {
    const response = await page.goto('/admin/ar-aging?bucket=d_1_30', { waitUntil: 'networkidle' });
    expect(response?.status()).toBeLessThan(500);
    await expectNoLaravelError(page);
    await expect(page.locator('body')).toContainText(/AR Aging|أعمار الذمم/);
  });

  test('AR Aging buckets are clickable from Reports page', async ({ page }) => {
    await page.goto('/admin/reports', { waitUntil: 'networkidle' });
    // Click a bucket link to drill in
    const bucketLink = page.locator('a[href*="/admin/ar-aging"]').first();
    await expect(bucketLink).toBeVisible();
    await bucketLink.click();
    await page.waitForURL(/\/admin\/ar-aging/, { timeout: 10000 });
    await expectNoLaravelError(page);
  });
});
