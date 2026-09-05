import { test, expect } from '@playwright/test';

test.use({ storageState: 'storage/playwright-state/admin.json' });

/**
 * Press a page-header action and, if it opens one, submit its modal.
 *
 * This helper stopped pressing anything and kept reporting green, for two
 * reasons that arrived a few days apart:
 *
 *  1. The grouped-header work (2026-08-30) put most record-page acts inside an
 *     `ActionGroup`. A dropdown item is a `display: none` node until the
 *     dropdown is opened, and `click({ force: true })` skips the actionability
 *     checks but still needs a box to aim at -- so it throws rather than
 *     dispatching. OPEN the containing dropdown first.
 *  2. `PdfDownloadAction` asks which LANGUAGE the document is written in
 *     (2026-08-27), so pressing the act MOUNTS a modal; the download only
 *     starts when that modal is submitted.
 *
 * `actionName` mirrors `Action::make('<name>')`. It is matched against the
 * `wire:click="mountAction('<name>')"` Filament emits on every action button --
 * precise, and the same string in both languages, which a label is not.
 */
async function clickHeaderAction(page, labelRegex, actionName) {
  const wireSelector = `button[wire\\:click*="mountAction('${actionName}'"]`;
  const item = page.locator(wireSelector).first();

  // `isVisible()` is an IMMEDIATE check -- it takes no timeout and waits for
  // nothing. That is what is wanted here (the button is already in the DOM;
  // the question is only whether a dropdown is hiding it), but see
  // `submitActionModal` for the half where it silently swallowed the wait.
  if (await item.isVisible().catch(() => false)) {
    await item.click();
  } else if (await item.count()) {
    // Outermost first, so a nested group opens in the order a person would.
    const groups = page.locator('.fi-dropdown').filter({ has: page.locator(wireSelector) });
    for (let i = 0, depth = await groups.count(); i < depth; i++) {
      await groups.nth(i).locator('.fi-dropdown-trigger').first().click();
    }
    await expect(item).toBeVisible({ timeout: 10000 });
    await item.click();
  } else {
    // No such action on the page -- fail on the LABEL, which names what a
    // person was looking for rather than an internal action name.
    await page.locator('button:visible, a:visible').filter({ hasText: labelRegex }).first().click();
  }

  await submitActionModal(page);
}

/**
 * Submit the mounted action's modal, when the act opened one.
 *
 * Structural, not by label -- these specs run in both languages and the label
 * is the thing that differs. Filament renders the footer actions submit-first,
 * so the first button is the one that runs the act.
 *
 * A modal that is OPEN and whose submit cannot be found is a failure, never a
 * skip: quietly doing nothing is exactly how this helper came to report green
 * while pressing nothing at all.
 */
async function submitActionModal(page) {
  const modal = page.locator('.fi-modal-window:visible').last();

  // `waitFor`, NOT `isVisible({ timeout })`: `isVisible()` takes no timeout and
  // answers immediately, so the obvious spelling asked whether the modal was up
  // before the `mountAction` round-trip had even returned, got `false`, and
  // skipped the submit -- leaving the download it was waiting for un-started.
  const opened = await modal.waitFor({ state: 'visible', timeout: 10000 }).then(() => true, () => false);
  if (!opened) {
    return; // the act opened no modal -- it ran on the click
  }

  const submit = modal.locator('.fi-modal-footer-actions button').first();
  await expect(submit).toBeVisible({ timeout: 5000 });
  await submit.click();
}

test('Admin can download invoice PDF in English', async ({ page }) => {
  await page.goto('/locale/en');
  await page.goto('/admin/AW/invoices');
  await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});

  const firstEdit = page.locator('a[href*="/admin/AW/invoices/"][href$="/edit"]').first();
  const href = await firstEdit.getAttribute('href');
  await page.goto(new URL(href, 'http://x').pathname);
  await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});

  const downloadPromise = page.waitForEvent('download', { timeout: 30000 });
  await clickHeaderAction(page, /Download PDF/i, 'downloadPdf');
  const download = await downloadPromise;
  const path = await download.path();
  expect(path).toBeTruthy();
  const size = (await import('fs')).statSync(path).size;
  expect(size).toBeGreaterThan(1000);

  // Verify it's a real PDF
  const buf = (await import('fs')).readFileSync(path);
  expect(buf.slice(0, 4).toString()).toBe('%PDF');
});

test('Admin can download invoice PDF in Arabic', async ({ page }) => {
  await page.goto('/locale/ar');
  await page.goto('/admin/AW/invoices');
  await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});

  const firstEdit = page.locator('a[href*="/admin/AW/invoices/"][href$="/edit"]').first();
  const href = await firstEdit.getAttribute('href');
  await page.goto(new URL(href, 'http://x').pathname);
  await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});

  const downloadPromise = page.waitForEvent('download', { timeout: 30000 });
  await clickHeaderAction(page, /Download PDF|تنزيل|تحميل/i, 'downloadPdf');
  const download = await downloadPromise;
  const path = await download.path();
  const buf = (await import('fs')).readFileSync(path);
  expect(buf.slice(0, 4).toString()).toBe('%PDF');
});

test('Admin can download tenant statement PDF', async ({ page }) => {
  await page.goto('/locale/en');
  await page.goto('/admin/AW/tenants');
  await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});

  const firstEdit = page.locator('a[href*="/admin/AW/tenants/"][href$="/edit"]').first();
  const href = await firstEdit.getAttribute('href');
  await page.goto(new URL(href, 'http://x').pathname);
  await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});

  const downloadPromise = page.waitForEvent('download', { timeout: 30000 });
  // By ACTION NAME, not by label: a loose `button, a` + hasText filter also
  // matches a non-interactive responsive duplicate, and `.first()` may click
  // the wrong one (the action never dispatches → no download).
  await clickHeaderAction(page, /^\s*(Statement|كشف الحساب)\s*$/, 'statement');
  const download = await downloadPromise;
  const path = await download.path();
  const buf = (await import('fs')).readFileSync(path);
  expect(buf.slice(0, 4).toString()).toBe('%PDF');
  expect(buf.length).toBeGreaterThan(1000);
});
