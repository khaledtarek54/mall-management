/**
 * Record-page action sweep — every act that moved off a list row still MOUNTS.
 *
 * On 2026-08-30 sixteen resources moved their write verbs off the list row and onto the record —
 * the list finds, the record acts. That created a surface no browser spec covered:
 * `22-actions-sweep` walks LIST rows, and `99-system-smoke` only loads pages.
 *
 * The gap matters because of how Filament actions fail. An action builds its SCHEMA on MOUNT, so
 * a record page renders perfectly, its header buttons render perfectly, and the whole thing fatals
 * the moment somebody clicks — an unimported class in a `->schema()` closure, a `use ($get)` that
 * was never captured. This project has shipped both, and the Pest tests that would have caught
 * them called the SERVICE instead of the button.
 *
 * ── SAFETY ────────────────────────────────────────────────────────────────────────────────────
 * It clicks ONLY acts the manifest marks `opensModal` — an act with a form or a confirmation
 * shows a dialog and has done nothing yet, and Escape closes it. An act with neither RUNS on
 * click, and this suite points at the same demo database the reconciliation commands read. The
 * flag is derived in `DumpAdminManifest::recordPageActions()` from Filament's own `hasModal()`,
 * never hand-listed here.
 *
 * It also never submits: no modal's confirm button is touched.
 */
import { test, expect } from '@playwright/test';
import { readFileSync } from 'fs';
import { expectNoLaravelError } from './helpers.js';

const MANIFEST = JSON.parse(readFileSync(new URL('./filament-admin-manifest.json', import.meta.url), 'utf8'));
const RAW_KEY = /(?:^|\s)(admin\.[a-z_]+(?:\.[a-z_]+){1,3})(?:\s|$|[,.;:])/i;

const WITH_ACTS = MANIFEST.resources.filter(
  (r) => (r.recordActions ?? []).length > 0 && (r.hasEdit || r.hasView),
);

test.describe('ADMIN — record-page actions mount without crashing', () => {
  test.use({ storageState: 'storage/playwright-state/admin.json' });

  // The premise. If the manifest ever stops carrying record acts this file would pass while
  // sweeping nothing, which is the failure mode every gate in this project is written against.
  test('the manifest carries record-page acts to sweep', async () => {
    expect(WITH_ACTS.length).toBeGreaterThan(10);
  });

  for (const r of WITH_ACTS) {
    test(`record actions on ${r.slug}`, async ({ page }) => {
      const serverErrors = [];
      page.on('response', (resp) => {
        if (resp.status() >= 500) serverErrors.push(`${resp.status()} ${resp.url()}`);
      });

      // Find a record. The suffix differs per resource — most have an Edit page, owner
      // statement runs only a View page.
      const suffix = r.hasEdit ? 'edit' : 'view';
      await page.goto(`/admin/AW/${r.slug}`, { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(1200);

      const link = page.locator(`a[href*="/admin/AW/${r.slug}/"][href$="/${suffix}"]`).first();
      if (!(await link.isVisible({ timeout: 2000 }).catch(() => false))) {
        test.skip(true, `${r.slug} has no record to open`);
        return;
      }

      await link.click();
      await page.waitForLoadState('networkidle');
      await expectNoLaravelError(page);

      const modalActs = r.recordActions.filter((a) => a.opensModal);
      let opened = 0;

      for (const act of modalActs) {
        // Many acts are state-gated — `Mark Reconciled` only shows on a reconciling pool — so a
        // missing button is expected and is NOT a failure. What is a failure is a button that is
        // there and dies on click.
        const btn = page
          .locator('button:visible, a:visible')
          .filter({ hasText: new RegExp(`^\\s*${act.label.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\s*$`, 'i') })
          .first();

        if (!(await btn.isVisible({ timeout: 1000 }).catch(() => false))) continue;

        await btn.click();
        await page.waitForTimeout(600);

        const dialog = page.locator('.fi-modal-window:visible, [role="dialog"]:visible').first();
        await expect(dialog, `${r.slug} → "${act.label}" opened no dialog`).toBeVisible({ timeout: 5000 });

        const text = await dialog.innerText().catch(() => '');
        expect(text, `${r.slug} → "${act.label}" modal renders a raw key`).not.toMatch(RAW_KEY);

        await page.keyboard.press('Escape');
        await page.waitForTimeout(300);
        opened++;
      }

      await expectNoLaravelError(page);
      expect(serverErrors, `${r.slug} record page raised a 5xx`).toEqual([]);

      test.info().annotations.push({
        type: 'opened',
        description: `${opened}/${modalActs.length} modal acts on ${r.slug}`,
      });
    });
  }
});
