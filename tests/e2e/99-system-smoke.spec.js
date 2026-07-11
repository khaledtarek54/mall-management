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
import { readFileSync } from 'fs';
import { expectNoLaravelError } from './helpers.js';

// ===== ADMIN PANEL =====
//
// Driven by tests/e2e/filament-admin-manifest.json — the authoritative list of
// EVERY registered admin resource + custom page, generated from Filament itself.
// A Pest gate (AdminSmokeManifestConformanceTest) fails if a new resource ships
// without being added here, so this smoke can never silently miss a module.
// Regenerate: php artisan atriom:dump-admin-manifest  (see that command).
const MANIFEST = JSON.parse(
  readFileSync(new URL('./filament-admin-manifest.json', import.meta.url), 'utf8'),
);

const TENANT = 'ALL'; // portfolio pseudo-tenant — super_admin sees every property

// The dashboard lives at the tenant root, not /admin/ALL/dashboard.
const ADMIN_LIST_PAGES = [
  `/admin/${TENANT}`, // dashboard
  ...MANIFEST.resources.map((r) => `/admin/${TENANT}/${r.slug}`),
  ...MANIFEST.pages
    .filter((p) => p.slug && p.slug !== 'dashboard')
    .map((p) => `/admin/${TENANT}/${p.slug}`),
];

const ADMIN_CREATE_PAGES = MANIFEST.resources
  .filter((r) => r.hasCreate)
  .map((r) => `/admin/${TENANT}/${r.slug}/create`);

// Resources where we'll click into the first record's edit page.
const ADMIN_EDIT_TARGETS = MANIFEST.resources
  .filter((r) => r.hasEdit)
  .map((r) => ({ list: `/admin/${TENANT}/${r.slug}` }));

const PORTAL_PAGES = [
  '/portal',
  '/portal/invoices',
  '/portal/payments',
  '/portal/maintenance-requests',
  '/portal/maintenance-requests/create',
  '/portal/tenant-sales-declarations',
  '/portal/tenant-sales-declarations/create',
];

// Owners are admin RBAC users now (the /owner portal was retired) — they walk
// the admin app, scoped to their owned properties.
const OWNER_PAGES = [
  '/admin',
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
    '/admin/ALL/invoices',
    '/admin/ALL/payments',
    '/admin/ALL/credit-notes',
    '/admin/ALL/leases',
    '/admin/ALL/maintenance-requests',
    '/admin/ALL/tenant-sales-declarations',
    '/admin/ALL/vendors',
    '/admin/ALL/units',
    '/admin/ALL/tenants',
  ];

  for (const path of filterablePages) {
    test(`filter form opens on ${path}`, async ({ page }) => {
      // `domcontentloaded` (not `networkidle`) — Livewire polls continuously, so
      // networkidle can hang past the nav timeout under load. We only need the
      // response status + a rendered page to open the filter panel.
      const response = await page.goto(path, { waitUntil: 'domcontentloaded' });
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

test.describe('OWNER (admin RBAC user) — admin app loads; /owner portal retired', () => {
  test.use({ storageState: 'storage/playwright-state/owner.json' });

  for (const path of OWNER_PAGES) {
    test(`GET ${path}`, async ({ page }) => {
      const response = await page.goto(path, { waitUntil: 'networkidle' });
      expect(response?.status(), `${path} returned ${response?.status()}`).toBeLessThan(500);
      await expectNoLaravelError(page);
      await expectNoRawTranslationKey(page);
    });
  }

  test('the retired /owner portal returns 404', async ({ page }) => {
    const response = await page.goto('/owner');
    expect(response?.status()).toBe(404);
  });
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
    '/admin/ALL/invoices',
    '/admin/ALL/credit-notes',
    '/admin/ALL/vendors',
    '/admin/ALL/maintenance-requests',
    '/admin/ALL/users',
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
