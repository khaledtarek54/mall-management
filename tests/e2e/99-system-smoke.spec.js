/**
 * System-wide smoke test.
 *
 * Walks every admin / portal / owner URL, opens every filter panel,
 * and clicks into the first record on every list page. Any HTTP 5xx,
 * raw translation key leak, or "Call to a member function ... on null"
 * fails the test.
 *
 * This is the safety net: if any page in any panel breaks, this spec
 * catches it before the user does.
 */
import { test, expect } from '@playwright/test';
import { expectNoLaravelError } from './helpers.js';

// ===== ADMIN PANEL =====

const ADMIN_LIST_PAGES = [
  '/admin',                              // dashboard
  '/admin/assets',
  '/admin/units',
  '/admin/tenants',
  '/admin/leases',
  '/admin/invoices',
  '/admin/payments',
  '/admin/credit-notes',
  '/admin/maintenance-requests',
  '/admin/tenant-sales-declarations',
  '/admin/cam-expense-pools',
  '/admin/utility-meters',
  '/admin/vendors',
  '/admin/users',
  '/admin/occupancy-map',
  '/admin/activity-log',
];

const ADMIN_CREATE_PAGES = [
  '/admin/assets/create',
  '/admin/units/create',
  '/admin/tenants/create',
  '/admin/leases/create',
  '/admin/invoices/create',
  '/admin/payments/create',
  '/admin/credit-notes/create',
  '/admin/maintenance-requests/create',
  '/admin/tenant-sales-declarations/create',
  '/admin/cam-expense-pools/create',
  '/admin/utility-meters/create',
  '/admin/vendors/create',
  '/admin/users/create',
];

// Resources where we'll click into the first record's edit page.
const ADMIN_EDIT_TARGETS = [
  { list: '/admin/assets', editPattern: /\/admin\/assets\/\d+\/edit/ },
  { list: '/admin/units', editPattern: /\/admin\/units\/\d+\/edit/ },
  { list: '/admin/tenants', editPattern: /\/admin\/tenants\/\d+\/edit/ },
  { list: '/admin/leases', editPattern: /\/admin\/leases\/\d+\/edit/ },
  { list: '/admin/invoices', editPattern: /\/admin\/invoices\/\d+\/edit/ },
  { list: '/admin/payments', editPattern: /\/admin\/payments\/\d+\/edit/ },
  { list: '/admin/maintenance-requests', editPattern: /\/admin\/maintenance-requests\/\d+\/edit/ },
  { list: '/admin/tenant-sales-declarations', editPattern: /\/admin\/tenant-sales-declarations\/\d+\/edit/ },
  { list: '/admin/cam-expense-pools', editPattern: /\/admin\/cam-expense-pools\/\d+\/edit/ },
  { list: '/admin/utility-meters', editPattern: /\/admin\/utility-meters\/\d+\/edit/ },
  { list: '/admin/vendors', editPattern: /\/admin\/vendors\/\d+\/edit/ },
  { list: '/admin/users', editPattern: /\/admin\/users\/\d+\/edit/ },
];

const PORTAL_PAGES = [
  '/portal',
  '/portal/invoices',
  '/portal/payments',
  '/portal/maintenance-requests',
  '/portal/maintenance-requests/create',
  '/portal/tenant-sales-declarations',
  '/portal/tenant-sales-declarations/create',
];

const OWNER_PAGES = [
  '/owner',
  '/owner/properties',
  '/owner/invoices',
  '/owner/maintenance-requests',
];

// Reusable assertion: nothing rendered as a raw `admin.foo.bar.baz` key in
// VISIBLE text. We check innerText (not raw HTML) so Livewire component names
// like "app.filament.admin.widgets.action-required" embedded in attributes
// don't false-positive — only what an end user actually sees on the page.
async function expectNoRawTranslationKey(page) {
  const visibleText = await page.evaluate(() => document.body.innerText);
  // Match admin.x.y or admin.x.y.z as a standalone token (whitespace/edges around it)
  const match = visibleText.match(/(?:^|\s)(admin\.[a-z_]+(?:\.[a-z_]+){1,3})(?:\s|$|[,.;:])/i);
  if (match) {
    throw new Error(`Raw translation key leaked into visible UI: "${match[1]}"`);
  }
}

// ============================================================================

test.describe('ADMIN panel — every list page loads cleanly', () => {
  test.use({ storageState: 'storage/playwright-state/admin.json' });

  for (const path of ADMIN_LIST_PAGES) {
    test(`GET ${path}`, async ({ page }) => {
      const response = await page.goto(path, { waitUntil: 'networkidle' });
      expect(response?.status(), `${path} returned ${response?.status()}`).toBeLessThan(500);
      await expectNoLaravelError(page);
      await expectNoRawTranslationKey(page);
    });
  }
});

test.describe('ADMIN panel — every create form mounts cleanly', () => {
  test.use({ storageState: 'storage/playwright-state/admin.json' });

  for (const path of ADMIN_CREATE_PAGES) {
    test(`GET ${path}`, async ({ page }) => {
      const response = await page.goto(path, { waitUntil: 'networkidle' });
      expect(response?.status(), `${path} returned ${response?.status()}`).toBeLessThan(500);
      await expectNoLaravelError(page);
    });
  }
});

test.describe('ADMIN panel — first record edit page renders', () => {
  test.use({ storageState: 'storage/playwright-state/admin.json' });

  for (const target of ADMIN_EDIT_TARGETS) {
    test(`first record on ${target.list}`, async ({ page }) => {
      await page.goto(target.list, { waitUntil: 'networkidle' });
      const editLink = page.locator(`a[href*="${target.list}/"][href$="/edit"]`).first();
      const editHref = await editLink.getAttribute('href').catch(() => null);
      if (!editHref) {
        test.skip(true, 'No records — list is empty');
        return;
      }
      const response = await page.goto(editHref, { waitUntil: 'networkidle' });
      expect(response?.status(), `${editHref} returned ${response?.status()}`).toBeLessThan(500);
      await expectNoLaravelError(page);
      await expectNoRawTranslationKey(page);
    });
  }
});

test.describe('ADMIN panel — opening filter panel does not 500', () => {
  test.use({ storageState: 'storage/playwright-state/admin.json' });

  // Pages that have filter dropdowns — opening these forces Filament to
  // evaluate getModel() and modifyQueryUsing closures, where most
  // closure-injection bugs surface.
  const filterablePages = [
    '/admin/invoices',
    '/admin/payments',
    '/admin/credit-notes',
    '/admin/leases',
    '/admin/maintenance-requests',
    '/admin/tenant-sales-declarations',
    '/admin/vendors',
    '/admin/units',
    '/admin/tenants',
  ];

  for (const path of filterablePages) {
    test(`filter form opens on ${path}`, async ({ page }) => {
      const response = await page.goto(path, { waitUntil: 'networkidle' });
      expect(response?.status()).toBeLessThan(500);

      const filterBtn = page.locator('button[aria-label*="filter" i], button:has-text("Filter")').first();
      if (await filterBtn.isVisible({ timeout: 2000 }).catch(() => false)) {
        await filterBtn.click();
        await page.waitForTimeout(700);
        await expectNoLaravelError(page);
      }
    });
  }
});

// ============================================================================

test.describe('PORTAL panel — every page loads cleanly', () => {
  test.use({ storageState: 'storage/playwright-state/portal.json' });

  for (const path of PORTAL_PAGES) {
    test(`GET ${path}`, async ({ page }) => {
      const response = await page.goto(path, { waitUntil: 'networkidle' });
      expect(response?.status(), `${path} returned ${response?.status()}`).toBeLessThan(500);
      await expectNoLaravelError(page);
      await expectNoRawTranslationKey(page);
    });
  }
});

test.describe('PORTAL panel — first record detail loads', () => {
  test.use({ storageState: 'storage/playwright-state/portal.json' });

  const targets = [
    { list: '/portal/invoices', pattern: /\/portal\/invoices\/\d+/ },
    { list: '/portal/payments', pattern: /\/portal\/payments\/\d+/ },
    { list: '/portal/maintenance-requests', pattern: /\/portal\/maintenance-requests\/\d+/ },
    { list: '/portal/tenant-sales-declarations', pattern: /\/portal\/tenant-sales-declarations\/\d+/ },
  ];

  for (const target of targets) {
    test(`first record on ${target.list}`, async ({ page }) => {
      await page.goto(target.list, { waitUntil: 'networkidle' });
      // Portal uses view (read-only) links, not edit.
      const link = page.locator(`a[href*="${target.list}/"]`).first();
      const href = await link.getAttribute('href').catch(() => null);
      if (!href || href.endsWith('/create')) {
        test.skip(true, 'No records');
        return;
      }
      const response = await page.goto(href, { waitUntil: 'networkidle' });
      expect(response?.status()).toBeLessThan(500);
      await expectNoLaravelError(page);
      await expectNoRawTranslationKey(page);
    });
  }
});

// ============================================================================

test.describe('OWNER panel — every page loads cleanly', () => {
  test.use({ storageState: 'storage/playwright-state/owner.json' });

  for (const path of OWNER_PAGES) {
    test(`GET ${path}`, async ({ page }) => {
      const response = await page.goto(path, { waitUntil: 'networkidle' });
      expect(response?.status(), `${path} returned ${response?.status()}`).toBeLessThan(500);
      await expectNoLaravelError(page);
      await expectNoRawTranslationKey(page);
    });
  }
});

test.describe('OWNER panel — first record view loads', () => {
  test.use({ storageState: 'storage/playwright-state/owner.json' });

  const targets = [
    { list: '/owner/properties', pattern: /\/owner\/properties\/\d+/ },
    { list: '/owner/invoices', pattern: /\/owner\/invoices\/\d+/ },
    { list: '/owner/maintenance-requests', pattern: /\/owner\/maintenance-requests\/\d+/ },
  ];

  for (const target of targets) {
    test(`first record on ${target.list}`, async ({ page }) => {
      await page.goto(target.list, { waitUntil: 'networkidle' });
      const link = page.locator(`a[href^="${target.list}/"]`).first();
      const href = await link.getAttribute('href').catch(() => null);
      if (!href) {
        test.skip(true, 'No records');
        return;
      }
      const response = await page.goto(href, { waitUntil: 'networkidle' });
      expect(response?.status()).toBeLessThan(500);
      await expectNoLaravelError(page);
      await expectNoRawTranslationKey(page);
    });
  }
});

// ============================================================================
// LOCALE — admin pages also need to work in Arabic. Switch and re-walk a subset.

test.describe('ARABIC locale — critical admin pages render', () => {
  test.use({ storageState: 'storage/playwright-state/admin.json' });

  test.beforeEach(async ({ page }) => {
    await page.goto('/locale/ar', { waitUntil: 'networkidle' }).catch(() => {});
  });

  test.afterEach(async ({ page }) => {
    await page.goto('/locale/en', { waitUntil: 'networkidle' }).catch(() => {});
  });

  const arabicCheckPages = [
    '/admin',
    '/admin/invoices',
    '/admin/credit-notes',
    '/admin/vendors',
    '/admin/maintenance-requests',
    '/admin/users',
  ];

  for (const path of arabicCheckPages) {
    test(`AR ${path}`, async ({ page }) => {
      const response = await page.goto(path, { waitUntil: 'networkidle' });
      expect(response?.status()).toBeLessThan(500);
      await expectNoLaravelError(page);
      await expectNoRawTranslationKey(page);
    });
  }
});
