/**
 * Table-action sweep — every resource's row + bulk actions render without crashing.
 *
 * Row actions are inline in the rendered table, so 99-system-smoke's raw-key check
 * already covers their labels. Bulk actions are LAZY — their label/visible closures
 * only evaluate when you select rows and open the "Bulk actions" dropdown, so a
 * crashing closure or a missing translation there is invisible to a page-load smoke.
 *
 * This sweep, for every resource list with rows:
 *   - asserts the first row's inline action labels aren't raw admin.* keys
 *   - selects rows, opens the bulk-actions dropdown, and asserts it renders with
 *     no 5xx and no raw translation key
 *
 * It only OPENS menus — it never clicks an action that mutates/deletes/sends, so
 * it's safe to run against live demo data. Driven by the admin manifest.
 */
import { test, expect } from '@playwright/test';
import { readFileSync } from 'fs';
import { expectNoLaravelError } from './helpers.js';

const MANIFEST = JSON.parse(readFileSync(new URL('./filament-admin-manifest.json', import.meta.url), 'utf8'));
const RAW_KEY = /(?:^|\s)(admin\.[a-z_]+(?:\.[a-z_]+){1,3})(?:\s|$|[,.;:])/i;

test.describe('ADMIN — row + bulk actions render without crashing', () => {
  test.use({ storageState: 'storage/playwright-state/admin.json' });

  for (const r of MANIFEST.resources) {
    test(`actions on ${r.slug}`, async ({ page }) => {
      const serverErrors = [];
      page.on('response', (resp) => {
        if (resp.status() >= 500) serverErrors.push(`${resp.status()} ${resp.url()}`);
      });

      await page.goto(`/admin/ALL/${r.slug}`, { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(1500);
      await expectNoLaravelError(page);

      const rowCount = await page.locator('tbody tr').count();
      if (rowCount === 0) {
        test.skip(true, `${r.slug} list is empty — no actions to exercise`);
        return;
      }

      // 1. Inline row-action labels on the first row must not be raw i18n keys.
      const rowActionText = await page.locator('tbody tr').first().innerText().catch(() => '');
      const rowLeak = rowActionText.match(RAW_KEY);
      expect(rowLeak?.[1] ?? null, `${r.slug} row action leaked raw key`).toBeNull();

      // 2. Bulk actions (if any): select rows → open the dropdown → assert clean.
      const checkboxes = page.locator('table input[type="checkbox"]:visible');
      if ((await checkboxes.count()) >= 2) {
        await checkboxes.nth(1).check().catch(() => {});
        await page.waitForTimeout(300);
        const bulkBtn = page.locator('button:visible').filter({ hasText: /Bulk actions|إجراءات مجمّعة/i }).first();
        if (await bulkBtn.count()) {
          await bulkBtn.click().catch(() => {});
          await page.waitForTimeout(500);
          const panel = page.locator('.fi-dropdown-panel:visible, [role="menu"]:visible').last();
          const panelText = (await panel.innerText().catch(() => '')) || '';
          const bulkLeak = panelText.match(RAW_KEY);
          expect(bulkLeak?.[1] ?? null, `${r.slug} bulk action leaked raw key: ${bulkLeak?.[1]}`).toBeNull();
          await page.keyboard.press('Escape').catch(() => {});
        }
      }

      await expectNoLaravelError(page);
      expect(serverErrors, `5xx during ${r.slug} actions:\n  ${serverErrors.join('\n  ')}`).toEqual([]);
    });
  }
});
