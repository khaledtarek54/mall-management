import { test, expect } from '@playwright/test';
import { expectNoLaravelError, captureConsoleErrors } from './helpers.js';

test.use({ storageState: 'storage/playwright-state/admin.json' });

test('Occupancy Map page loads and renders units', async ({ page }) => {
  const errors = await captureConsoleErrors(page);
  const response = await page.goto('/admin/AW/occupancy-map');
  expect(response.status()).toBeLessThan(400);
  await expectNoLaravelError(page);
  await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});

  // Must show the page title (EN locale)
  await page.goto('/locale/en');
  await page.goto('/admin/AW/occupancy-map');
  await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});
  await expect(page.locator('h1, h2').filter({ hasText: /Occupancy Map/i }).first()).toBeVisible();

  // Should render at least one unit tile (link to /admin/AW/units/{id}/edit)
  const unitTile = page.locator('a[href*="/admin/AW/units/"]').first();
  await expect(unitTile).toBeVisible({ timeout: 10000 });

  expect(errors, 'No JS errors on Occupancy Map').toEqual([]);
});

test('Occupancy Map appears in admin sidebar nav', async ({ page }) => {
  await page.goto('/locale/en');
  await page.goto('/admin');
  await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});
  // The EXPANDED sidebar item (`fi-sidebar-item-btn`), not just any anchor with
  // that href. The navigation is rendered more than once per page — the topbar
  // carries a copy, and the sidebar carries a second, hidden dropdown copy for
  // its collapsed state (which is why `Panel::getNavigation()` is built several
  // times; see `NavigationItemMemo`). So the obvious locator plus `.first()`
  // resolves to an invisible `fi-dropdown-list-item` and reports the sidebar as
  // missing a link that is sitting in it.
  const navLink = page.locator('a.fi-sidebar-item-btn[href*="/admin/AW/occupancy-map"]').first();
  await expect(navLink).toBeVisible({ timeout: 10000 });
});

test('Occupancy Map renders Arabic label when locale is ar', async ({ page }) => {
  await page.goto('/locale/ar');
  await page.goto('/admin/AW/occupancy-map');
  await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});
  await expect(page.locator('body')).toContainText(/خريطة الإشغال/);
});
