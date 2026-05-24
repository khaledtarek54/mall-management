import { test, expect } from '@playwright/test';
import { expectNoLaravelError } from './helpers.js';

test.use({ storageState: 'storage/playwright-state/admin.json' });

test.describe('Multi-property tenancy (session-based operator switcher)', () => {
  test.beforeEach(async ({ page }) => {
    // Reset to English + "All operators" before every test so order independence holds
    await page.goto('/locale/en');
    await page.goto('/operator/switch/all');
  });

  test('Operator switcher button is present in the admin topbar', async ({ page }) => {
    await page.goto('/admin');
    await expectNoLaravelError(page);
    const switcher = page.locator('button:has-text("All Operators")');
    await expect(switcher).toBeVisible();
  });

  test('Switching to Jawad shows Haya Walk in Properties', async ({ page }) => {
    await page.goto('/operator/switch/jawad');
    await page.goto('/admin/assets');
    await expectNoLaravelError(page);
    await expect(page.locator('text=Haya Walk').first()).toBeVisible();
  });

  test('Switching to Eltizam Egypt hides Haya Walk (operator has no assets)', async ({ page }) => {
    await page.goto('/operator/switch/eltizam-egypt');
    await page.goto('/admin/assets');
    await expectNoLaravelError(page);
    // Eltizam Egypt has no assets, so the Haya Walk row must not appear in the table body
    await expect(page.locator('table').locator('text=Haya Walk')).toHaveCount(0);
  });

  test('Switching back to "all" restores cross-operator visibility', async ({ page }) => {
    await page.goto('/operator/switch/eltizam-egypt');
    await page.goto('/operator/switch/all');
    await page.goto('/admin/assets');
    await expectNoLaravelError(page);
    await expect(page.locator('text=Haya Walk').first()).toBeVisible();
  });

  test('Brand swaps when switching operators (switcher button reflects state)', async ({ page }) => {
    await page.goto('/operator/switch/jawad', { waitUntil: 'networkidle' });
    await page.goto('/admin', { waitUntil: 'networkidle' });
    await expect(page.locator('button').filter({ hasText: 'Jawad Developments' })).toBeVisible({ timeout: 15000 });

    await page.goto('/operator/switch/eltizam-egypt', { waitUntil: 'networkidle' });
    await page.goto('/admin', { waitUntil: 'networkidle' });
    await expect(page.locator('button').filter({ hasText: 'Eltizam Egypt' })).toBeVisible({ timeout: 15000 });
  });
});
