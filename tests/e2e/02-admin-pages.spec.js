import { test, expect } from '@playwright/test';
import { expectNoLaravelError, captureConsoleErrors } from './helpers.js';

test.use({ storageState: 'storage/playwright-state/admin.json' });

const RESOURCE_PAGES = [
  { name: 'Dashboard', url: '/admin/ALL' },
  { name: 'Activity Log', url: '/admin/ALL/activity-log' },
  { name: 'Assets index', url: '/admin/ALL/assets' },
  { name: 'Assets create', url: '/admin/ALL/assets/create' },
  { name: 'Units index', url: '/admin/ALL/units' },
  { name: 'Units create', url: '/admin/ALL/units/create' },
  { name: 'Tenants index', url: '/admin/ALL/tenants' },
  { name: 'Tenants create', url: '/admin/ALL/tenants/create' },
  { name: 'Leases index', url: '/admin/ALL/leases' },
  { name: 'Leases create', url: '/admin/ALL/leases/create' },
  { name: 'Invoices index', url: '/admin/ALL/invoices' },
  { name: 'Invoices create', url: '/admin/ALL/invoices/create' },
  { name: 'Payments index', url: '/admin/ALL/payments' },
  { name: 'Payments create', url: '/admin/ALL/payments/create' },
  { name: 'Users index', url: '/admin/ALL/users' },
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
