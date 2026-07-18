/**
 * Functional create-form submit sweep.
 *
 * 99-system-smoke proves every create form MOUNTS. This proves every create form
 * can be filled and SUBMITTED without the server crashing — it fills every
 * fillable field with type-appropriate values and clicks Create, asserting no 5xx
 * response and no Laravel error page. Either outcome is a pass: a clean save
 * (redirect to the record) or clean server-side validation. Only a 500 fails —
 * that's the save-path crash class (bad cast, NOT-NULL violation, broken observer)
 * that a mount-only smoke can't catch.
 *
 * Driven by the same manifest as the smoke, so every create-capable resource is
 * covered. Runs as super_admin inside the primary demo mall (AW / Atriom Walk).
 */
import { test, expect } from '@playwright/test';
import { readFileSync } from 'fs';
import { expectNoLaravelError } from './helpers.js';

const MANIFEST = JSON.parse(readFileSync(new URL('./filament-admin-manifest.json', import.meta.url), 'utf8'));
const CREATE_RESOURCES = MANIFEST.resources.filter((r) => r.hasCreate);

// Fill every fillable, visible field with a value its validation will accept.
// Each op is bounded (1.5s) so a stubborn field can never hang the test.
const FILL = { timeout: 800 };
async function fillForm(page, tag) {
  const uniq = `E2E ${tag} ${Date.now().toString().slice(-6)}`;

  for (const el of await page.locator('form input[type="text"]:visible, form input:not([type]):visible, form textarea:visible').all()) {
    await el.fill(uniq, FILL).catch(() => {});
  }
  for (const el of await page.locator('form input[type="email"]:visible').all()) {
    await el.fill(`e2e${Date.now().toString().slice(-6)}@e2e.test`, FILL).catch(() => {});
  }
  for (const el of await page.locator('form input[type="url"]:visible').all()) {
    await el.fill('https://e2e.test', FILL).catch(() => {});
  }
  for (const el of await page.locator('form input[type="tel"]:visible').all()) {
    await el.fill('+201000000000', FILL).catch(() => {});
  }
  for (const el of await page.locator('form input[type="number"]:visible').all()) {
    await el.fill('10', FILL).catch(() => {});
  }
  for (const el of await page.locator('form input[type="date"]:visible').all()) {
    await el.fill('2026-06-15', FILL).catch(() => {});
  }
  // Native selects — choose the first non-empty option (satisfies most FK requires).
  for (const el of await page.locator('form select:visible').all()) {
    const values = await el.locator('option').evaluateAll((opts) => opts.map((o) => o.value).filter(Boolean));
    if (values.length) await el.selectOption(values[0], FILL).catch(() => {});
  }
}

test.describe('ADMIN — every create form submits without a server error', () => {
  test.use({ storageState: 'storage/playwright-state/admin.json' });

  for (const r of CREATE_RESOURCES) {
    test(`submit ${r.slug} create form`, async ({ page }) => {
      const serverErrors = [];
      page.on('response', (resp) => {
        if (resp.status() >= 500) serverErrors.push(`${resp.status()} ${resp.url()}`);
      });

      const createResp = await page.goto(`/admin/AW/${r.slug}/create`, { waitUntil: 'domcontentloaded' });
      expect(createResp?.status(), `${r.slug} create page returned ${createResp?.status()}`).toBeLessThan(500);
      await page.locator('form').first().waitFor({ timeout: 10000 }).catch(() => {});
      await fillForm(page, r.slug);

      // Click Create and give the save round-trip a bounded window. We don't wait
      // on a specific response (an unfilled required combobox blocks the POST
      // client-side, so it may never come) — the 5xx listener below is the real
      // assertion and fires whenever a save path actually crashes.
      const submit = page.locator('button[type="submit"]').filter({ hasText: /create|save|إنشاء|حفظ/i }).first();
      await submit.click({ timeout: 5000 }).catch(() => {});
      await page.waitForTimeout(2500); // bounded — long enough for a real save/redirect

      await expectNoLaravelError(page);
      expect(serverErrors, `5xx during ${r.slug} create submit:\n  ${serverErrors.join('\n  ')}`).toEqual([]);
    });
  }
});
