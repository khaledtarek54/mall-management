import { test, expect } from '@playwright/test';
import { expectNoLaravelError } from './helpers.js';

// Owners are RBAC users in the admin app now — the standalone /owner portal was
// retired. The owner auth state logs in at /admin (scoped to owned properties).
test.use({ storageState: 'storage/playwright-state/owner.json' });

test.describe('Owner = admin RBAC user (no separate /owner portal)', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/locale/en');
  });

  test('Owner loads the admin app without error', async ({ page }) => {
    const response = await page.goto('/admin');
    expect(response.status()).toBeLessThan(400);
    await expectNoLaravelError(page);
  });

  test('The retired /owner portal is gone (404)', async ({ page }) => {
    const response = await page.goto('/owner');
    expect(response.status()).toBe(404);
  });
});
