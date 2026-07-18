import { test, expect } from '@playwright/test';
import { expectNoLaravelError, captureConsoleErrors } from './helpers.js';

test.use({ storageState: 'storage/playwright-state/admin.json' });

const RESOURCE_PAGES = [
  { name: 'Dashboard', url: '/admin/AW' },
  { name: 'Activity Log', url: '/admin/AW/activity-log' },
  { name: 'Assets index', url: '/admin/AW/assets' },
  { name: 'Assets create', url: '/admin/AW/assets/create' },
  { name: 'Units index', url: '/admin/AW/units' },
  { name: 'Units create', url: '/admin/AW/units/create' },
  { name: 'Tenants index', url: '/admin/AW/tenants' },
  { name: 'Tenants create', url: '/admin/AW/tenants/create' },
  { name: 'Leases index', url: '/admin/AW/leases' },
  { name: 'Leases create', url: '/admin/AW/leases/create' },
  { name: 'Invoices index', url: '/admin/AW/invoices' },
  { name: 'Invoices create', url: '/admin/AW/invoices/create' },
  { name: 'Payments index', url: '/admin/AW/payments' },
  { name: 'Payments create', url: '/admin/AW/payments/create' },
  { name: 'Users index', url: '/admin/AW/users' },
];

test.describe('Admin pages load without errors', () => {
  for (const { name, url } of RESOURCE_PAGES) {
    test(`${name} loads cleanly`, async ({ page }) => {
      const errors = await captureConsoleErrors(page);
      const response = await page.goto(url);
      expect(response.status(), `HTTP status for ${url}`).toBeLessThan(400);
      await expectNoLaravelError(page);
      // Wait for Livewire to settle
      await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});
      expect(errors, `No JS errors on ${url}`).toEqual([]);
    });
  }
});
