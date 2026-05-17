import { test, expect } from '@playwright/test';
import { expectNoLaravelError, captureConsoleErrors } from './helpers.js';

test.use({ storageState: 'storage/playwright-state/admin.json' });

const RESOURCE_PAGES = [
  { name: 'Dashboard', url: '/admin' },
  { name: 'Activity Log', url: '/admin/activity-log' },
  { name: 'Assets index', url: '/admin/assets' },
  { name: 'Assets create', url: '/admin/assets/create' },
  { name: 'Units index', url: '/admin/units' },
  { name: 'Units create', url: '/admin/units/create' },
  { name: 'Tenants index', url: '/admin/tenants' },
  { name: 'Tenants create', url: '/admin/tenants/create' },
  { name: 'Leases index', url: '/admin/leases' },
  { name: 'Leases create', url: '/admin/leases/create' },
  { name: 'Invoices index', url: '/admin/invoices' },
  { name: 'Invoices create', url: '/admin/invoices/create' },
  { name: 'Payments index', url: '/admin/payments' },
  { name: 'Payments create', url: '/admin/payments/create' },
  { name: 'Users index', url: '/admin/users' },
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
