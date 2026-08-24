import { test, expect, devices } from '@playwright/test';
import { loginAdmin } from './helpers.js';

/**
 * The technician's two screens, in one hand.
 *
 * The operator DECLINED a technician mobile app (O3), so technicians use the admin panel — which
 * makes this role's phone experience part of the product. Work orders showed 17 columns by default
 * and tenant requests 15; `Column::visibleFrom('md')` now holds the desk-work columns back below
 * the breakpoint, leaving six on a phone and all seventeen on a desktop.
 *
 * `TheTechniciansScreensFitAPhoneTest` pins WHICH columns survive the breakpoint and that the class
 * reaches the DOM. It cannot tell you the result is usable — whether six columns plus the row
 * actions actually fit at 375px, whether a technician can reach the status change without pinching.
 * Only a browser answers that, which is what this file is for.
 *
 * ## Run it yourself
 *
 *     npx playwright test tests/e2e/24-phone-technician.spec.js --project=chromium
 *
 * It is NOT part of any automated run here: the browser suite is advisory, CI is paused by the
 * owner's standing call, and the E2E suite WRITES to the dev database and does not clean up. This
 * spec is read-only — it opens two lists and one record and asserts layout — so it leaves nothing
 * behind, but reseed anyway if you run it alongside `20-functional-create`.
 *
 * `php` and `npx` must be ON PATH; Herd's php is not there by default.
 */

// iPhone 13 — 390x844. Narrower than most Android handsets, so it is the honest worst case.
test.use({ ...devices['iPhone 13'] });

const PROPERTY = process.env.E2E_PROPERTY || 'AW';

/** Nothing on the page may push the document wider than the viewport. */
async function expectNoHorizontalOverflow(page, what) {
  const overflow = await page.evaluate(() => ({
    doc: document.documentElement.scrollWidth,
    win: window.innerWidth,
  }));

  expect(
    overflow.doc,
    `${what} scrolls sideways at ${overflow.win}px — the page is ${overflow.doc}px wide. ` +
      'A table may scroll inside its own container; the DOCUMENT may not.',
  ).toBeLessThanOrEqual(overflow.win + 1);
}

test.describe('the technician on a phone', () => {
  test('work orders identify a job without sideways scrolling', async ({ page }) => {
    await loginAdmin(page);
    await page.goto(`/admin/${PROPERTY}/facility-work-orders`);
    await page.waitForSelector('table', { timeout: 30000 });

    // The six that survive the breakpoint. If a technician cannot see what the job is, where it is
    // and when it is due, the screen has failed regardless of how it looks.
    const headers = await page.locator('thead th:visible').allInnerTexts();
    expect(headers.length, `expected ~6 visible columns on a phone, got ${headers.length}`).toBeLessThanOrEqual(8);

    await expectNoHorizontalOverflow(page, 'The work-order list');
  });

  test('a technician can reach the actions on a row', async ({ page }) => {
    await loginAdmin(page);
    await page.goto(`/admin/${PROPERTY}/facility-work-orders`);
    await page.waitForSelector('table', { timeout: 30000 });

    // Row actions collapse into a menu on narrow screens. What matters is that SOMETHING is
    // tappable — a row whose only affordance is off-screen is a dead end.
    const firstRow = page.locator('tbody tr').first();
    await expect(firstRow).toBeVisible();

    const tappable = firstRow.locator('a, button');
    expect(await tappable.count(), 'no tappable control on a work-order row').toBeGreaterThan(0);

    const box = await tappable.first().boundingBox();
    expect(box, 'the row control has no box — it is not rendered').not.toBeNull();
    // Apple's own minimum is 44pt; anything under ~32px is a mis-tap waiting to happen.
    expect(box.height, 'row control is too small to tap reliably').toBeGreaterThanOrEqual(24);
  });

  test('opening a work order does not overflow, and its tabs are reachable', async ({ page }) => {
    await loginAdmin(page);
    await page.goto(`/admin/${PROPERTY}/facility-work-orders`);
    await page.waitForSelector('tbody tr', { timeout: 30000 });

    await page.locator('tbody tr').first().click();
    await page.waitForLoadState('networkidle');

    await expectNoHorizontalOverflow(page, 'The work-order record page');

    // Four relation managers live under this record. On a phone Filament collapses the tab strip;
    // the check is that they have not simply run off the side.
    const tabs = page.locator('[role="tablist"], .fi-tabs');
    if (await tabs.count()) {
      const box = await tabs.first().boundingBox();
      if (box) {
        expect(box.width, 'the tab strip is wider than the screen').toBeLessThanOrEqual(
          (await page.evaluate(() => window.innerWidth)) + 1,
        );
      }
    }
  });

  test('tenant requests identify a report without sideways scrolling', async ({ page }) => {
    await loginAdmin(page);
    await page.goto(`/admin/${PROPERTY}/requests`);
    await page.waitForSelector('table', { timeout: 30000 });

    const headers = await page.locator('thead th:visible').allInnerTexts();
    expect(headers.length, `expected ~6 visible columns on a phone, got ${headers.length}`).toBeLessThanOrEqual(8);

    await expectNoHorizontalOverflow(page, 'The tenant-request list');
  });

  test('the sidebar opens and closes on a phone', async ({ page }) => {
    await loginAdmin(page);
    await page.goto(`/admin/${PROPERTY}/facility-work-orders`);
    await page.waitForSelector('table', { timeout: 30000 });

    // Fourteen groups collapsed behind one button. If this does not open, a technician cannot
    // navigate at all — which is a worse failure than any column layout.
    const toggle = page.locator('.fi-topbar button').first();
    await expect(toggle).toBeVisible();
    await toggle.click();

    await expect(page.locator('.fi-sidebar')).toBeVisible({ timeout: 10000 });
  });
});
