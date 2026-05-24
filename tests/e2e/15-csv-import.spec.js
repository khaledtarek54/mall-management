import { test, expect } from '@playwright/test';
import { loginAdmin, expectNoLaravelError } from './helpers.js';

test.describe('CSV import header actions', () => {
  test.beforeEach(async ({ page }) => {
    await loginAdmin(page);
  });

  for (const path of ['/admin/tenants', '/admin/units', '/admin/leases']) {
    test(`import action visible on ${path}`, async ({ page }) => {
      await page.goto(path, { waitUntil: 'networkidle' });
      await expectNoLaravelError(page);

      const importBtn = page
        .locator('button:visible, a:visible')
        .filter({ hasText: /Import/i })
        .first();

      await expect(importBtn).toBeVisible({ timeout: 10000 });
    });
  }
});
