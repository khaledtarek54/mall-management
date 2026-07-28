/**
 * Per-role RBAC access matrix.
 *
 * For every non-super-admin role, drives its saved session against a curated set
 * of resource URLs and asserts the panel enforces permissions:
 *   - a module the role CAN view      → the list page renders (status < 400)
 *   - a module the role CANNOT view    → the route is forbidden (403), never a
 *                                         200 data leak
 *   - a module it can view but not create → the /create route is forbidden (403)
 *
 * The expectations come from tests/e2e/rbac-matrix.json, generated from the real
 * permission set (`php artisan atriom:dump-rbac-matrix`), so this can't drift out
 * of sync with RolesPermissionsSeeder. Roles are scoped to their assigned
 * property; a role with no assignment lands on Filament's /admin/new and is
 * skipped (nothing to scope to — a demo-data gap, not an RBAC failure).
 */
import { test, expect } from '@playwright/test';
import { readFileSync } from 'fs';
import { expectNoLaravelError } from './helpers.js';

const MATRIX = JSON.parse(readFileSync(new URL('./rbac-matrix.json', import.meta.url), 'utf8'));

/** The property tenant a role lands on after login (protocol-agnostic). */
async function landingTenant(page) {
  await page.goto('/admin', { waitUntil: 'domcontentloaded' });
  await page.waitForURL(/\/admin\/[^/]+/, { timeout: 10000 }).catch(() => null);
  return new URL(page.url()).pathname.match(/^\/admin\/([^/]+)/)?.[1] || null;
}

for (const [role, access] of Object.entries(MATRIX.roles)) {
  test.describe(`RBAC — ${role}`, () => {
    test.use({ storageState: `storage/playwright-state/role-${role}.json` });

    test(`${role}: permitted modules render, forbidden ones 403`, async ({ page }) => {
      const tenant = await landingTenant(page);

      // An EXPIRED saved session redirects /admin → /admin/login, so `tenant`
      // comes back as "login" and every URL below becomes
      // /admin/login/<slug>, which renders the login page at 200 — reported as
      // "not viewable but returned 200", i.e. a phantom RBAC violation on every
      // role at once. Fail loudly with the fix instead: these states are cached
      // by global-setup and only refreshed when deleted, so they go stale
      // silently whenever the demo DB is reseeded.
      expect(
        tenant,
        `Saved session for "${role}" is no longer authenticated (landed on /admin/login).\n` +
        'Refresh it:  rm storage/playwright-state/role-*.json  then re-run.',
      ).not.toBe('login');

      if (!tenant || tenant === 'new') {
        test.skip(true, `${role} has no assigned property (landed on /admin/${tenant})`);
        return;
      }

      const failures = [];
      for (const [slug, a] of Object.entries(access)) {
        // 1. list-page access
        const listResp = await page.goto(`/admin/${tenant}/${slug}`, { waitUntil: 'domcontentloaded' }).catch(() => null);
        const listStatus = listResp?.status() ?? 0;
        if (a.view) {
          if (listStatus >= 400) {
            failures.push(`${slug}: viewable but list returned ${listStatus}`);
          } else {
            await expectNoLaravelError(page);
          }
        } else if (listStatus !== 403) {
          failures.push(`${slug}: NOT viewable but list returned ${listStatus} (expected 403)`);
        }

        // 2. create-page denial — can view, cannot create, and a create route exists
        if (a.view && !a.create && a.hasCreate) {
          const createResp = await page.goto(`/admin/${tenant}/${slug}/create`, { waitUntil: 'domcontentloaded' }).catch(() => null);
          const createStatus = createResp?.status() ?? 0;
          if (createStatus !== 403) {
            failures.push(`${slug}/create: no create permission but returned ${createStatus} (expected 403)`);
          }
        }
      }

      expect(failures, `RBAC violations for "${role}":\n  ${failures.join('\n  ')}`).toEqual([]);
    });
  });
}
