import { test, expect } from '@playwright/test';
import { expectNoLaravelError } from './helpers.js';

test.use({ storageState: 'storage/playwright-state/admin.json' });

test.describe('CAM Reconciliation', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/locale/en');
    await page.goto('/operator/switch/all');
  });

  test('CAM Reconciliation page is reachable and renders', async ({ page }) => {
    const response = await page.goto('/admin/AW/cam-expense-pools');
    expect(response.status()).toBeLessThan(400);
    await expectNoLaravelError(page);
    await expect(page.getByRole('heading', { name: 'CAM Reconciliation' })).toBeVisible({ timeout: 15000 });
  });

  test('Seeded pools render, including the zone-scoped one', async ({ page }) => {
    await page.goto('/admin/AW/cam-expense-pools');
    await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});

    // Assert WHICH pools are there, not how many. The count was hard-coded at 2 and broke the
    // moment the seeder grew a third (the food-court pool, RC-02) — a stale number that says
    // nothing about whether the right rows rendered. Naming them means the test fails when a pool
    // genuinely goes missing, and survives the next one being added.
    await expect(page.locator('td').filter({ hasText: 'Atriom Walk' }).first())
      .toBeVisible({ timeout: 10000 });

    // The property's own CAM pool, and the pool scoped to the food court — different participants,
    // different bases, reconciled separately. Rendering both is the point of RC-02.
    await expect(page.getByText('CAM', { exact: true }).first()).toBeVisible();
    await expect(page.getByText('Food court — grease trap & extraction').first()).toBeVisible();
  });
});
