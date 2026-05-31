import { test, expect } from '@playwright/test';
import { expectNoLaravelError } from './helpers.js';

test.use({ storageState: 'storage/playwright-state/admin.json' });

test.describe('CSV import header actions', () => {
  for (const path of ['/admin/ALL/tenants', '/admin/ALL/units', '/admin/ALL/leases']) {
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
