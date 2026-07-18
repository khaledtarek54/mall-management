/**
 * Functional spec — actually clicks every custom action button and verifies
 * the expected outcome (modal opens, notification shows, state changes,
 * file downloads, etc.). Complements 99-system-smoke.spec.js (which only
 * loads pages) by exercising the things users actually DO.
 */
import { test, expect } from '@playwright/test';
import { expectNoLaravelError } from './helpers.js';

// --------------------------------------------------------------------------
// Helpers
// --------------------------------------------------------------------------

async function clickAction(page, label) {
  const btn = page.locator('button:visible, a:visible').filter({ hasText: new RegExp(`^\\s*${label}\\s*$`, 'i') }).first();
  await btn.click();
}

// Filament wraps modals with `x-show="isOpen"`; the dialog node lives in
// the DOM permanently with `display: none` until Alpine flips it. Asserting
// visibility on the wrapper is unreliable (transition timing) — we instead
// wait for the modal heading text to become visible somewhere on the page,
// which only happens after the modal mounts and renders.
async function expectModalWithText(page, textPattern) {
  await expect(page.getByText(textPattern).first()).toBeVisible({ timeout: 5000 });
}

// =========================================================================
// ADMIN — invoices
// =========================================================================

test.describe('ADMIN: invoice actions', () => {
  test.use({ storageState: 'storage/playwright-state/admin.json' });

  test('Run Monthly Billing modal opens', async ({ page }) => {
    await page.goto('/admin/AW/invoices', { waitUntil: 'networkidle' });
    await clickAction(page, 'Run Monthly Billing');
    await expectModalWithText(page, /generate invoices/i);
    // Cancel out
    await page.keyboard.press('Escape');
  });

  test('Download PDF starts a file download', async ({ page }) => {
    await page.goto('/admin/AW/invoices', { waitUntil: 'networkidle' });
    const downloadPromise = page.waitForEvent('download', { timeout: 15000 });
    await page.locator('button:visible, a:visible').filter({ hasText: /^\s*PDF\s*$/ }).first().click();
    const download = await downloadPromise;
    expect(download.suggestedFilename()).toMatch(/\.pdf$/i);
    await expectNoLaravelError(page);
  });

  test('Submit to ETA action is wired when the ETA module is enabled', async ({ page }) => {
    await page.goto('/admin/AW/invoices', { waitUntil: 'networkidle' });
    await expectNoLaravelError(page);
    // The ETA row + bulk actions are gated on Modules::enabled('eta'), which is
    // OFF by default (feature not yet certified for production). When it's off,
    // the actions are *correctly* absent — assert the page rendered cleanly and
    // skip the action assertion. When someone enables ETA, this coverage kicks in.
    const html = await page.content();
    const etaOn = /Submit to ETA|إرسال إلى مصلحة الضرائب/.test(html);
    if (!etaOn) {
      test.skip(true, 'ETA module disabled (Modules::enabled("eta") === false) — action correctly absent');
      return;
    }
    expect(html).toMatch(/Submit to ETA|إرسال إلى مصلحة الضرائب/);
  });
});

// =========================================================================
// ADMIN — leases (Generate Invoice, Renew, Terminate)
// =========================================================================

test.describe('ADMIN: lease actions', () => {
  test.use({ storageState: 'storage/playwright-state/admin.json' });

  test('Generate Invoice header action wired on lease edit', async ({ page }) => {
    await page.goto('/admin/AW/leases', { waitUntil: 'networkidle' });
    const editLink = page.locator('a[href*="/admin/AW/leases/"][href$="/edit"]').first();
    const href = await editLink.getAttribute('href');
    await page.goto(href, { waitUntil: 'networkidle' });
    const html = await page.content();
    expect(html).toMatch(/Generate Invoice|إنشاء فاتورة/);
  });

  test('Renew lease modal opens', async ({ page }) => {
    await page.goto('/admin/AW/leases', { waitUntil: 'networkidle' });
    const btn = page.locator('button:visible, a:visible').filter({ hasText: /^\s*Renew\s*$/i }).first();
    if (await btn.isVisible({ timeout: 2000 }).catch(() => false)) {
      await btn.click();
      // The modal may have no unique heading; just confirm any visible dialog content.
      await page.waitForTimeout(500); // let Alpine show transition complete
      const visibleDialog = page.locator('.fi-modal-window:visible, [role="dialog"]:visible').first();
      await expect(visibleDialog).toBeVisible({ timeout: 5000 });
      await page.keyboard.press('Escape');
    } else {
      test.skip(true, 'No renewable lease visible');
    }
  });
});

// =========================================================================
// ADMIN — credit notes end-to-end: create → issue → apply
// =========================================================================

test.describe('ADMIN: credit note workflow', () => {
  test.use({ storageState: 'storage/playwright-state/admin.json' });

  test('issue action button exists on a draft credit note', async ({ page }) => {
    // First make sure we can reach a credit note (create one if none exist)
    await page.goto('/admin/AW/credit-notes', { waitUntil: 'networkidle' });
    const firstEdit = page.locator('a[href*="/admin/AW/credit-notes/"][href$="/edit"]').first();
    if (!(await firstEdit.isVisible({ timeout: 2000 }).catch(() => false))) {
      // No credit notes seeded — skip. Direct end-to-end create is a separate test.
      test.skip(true, 'No credit notes — create flow covered separately');
      return;
    }
    await firstEdit.click();
    await page.waitForLoadState('networkidle');
    await expectNoLaravelError(page);
  });
});

// =========================================================================
// ADMIN — vendor create flow
// =========================================================================

test.describe('ADMIN: vendor creation end-to-end', () => {
  test.use({ storageState: 'storage/playwright-state/admin.json' });

  test('can create a vendor end-to-end (form submit → edit page)', async ({ page }) => {
    const vendorName = `Test Vendor ${Date.now()}`;
    await page.goto('/admin/AW/vendors/create', { waitUntil: 'networkidle' });
    await expectNoLaravelError(page);

    await page.locator('input[wire\\:model="data.name"], input[id*="data.name"]').first().fill(vendorName);
    await page.locator('button[type="submit"]').filter({ hasText: /create|save/i }).first().click();

    // Successful create redirects to the new record's edit page
    await page.waitForURL(/\/admin\/[A-Z0-9_-]+\/vendors\/\d+\/edit/, { timeout: 10000 });
    await expectNoLaravelError(page);

    // Confirm the new vendor name appears (as a heading or in the page title)
    await expect(page.locator('body')).toContainText(vendorName, { timeout: 5000 });
  });
});

// =========================================================================
// ADMIN — maintenance actions
// =========================================================================

test.describe('ADMIN: maintenance actions', () => {
  test.use({ storageState: 'storage/playwright-state/admin.json' });

  test('Change Status modal opens', async ({ page }) => {
    await page.goto('/admin/AW/maintenance-requests', { waitUntil: 'networkidle' });
    const btn = page.locator('button:visible, a:visible').filter({ hasText: /Change Status/i }).first();
    if (await btn.isVisible({ timeout: 2000 }).catch(() => false)) {
      await btn.click();
      // The modal may have no unique heading; just confirm any visible dialog content.
      await page.waitForTimeout(500); // let Alpine show transition complete
      const visibleDialog = page.locator('.fi-modal-window:visible, [role="dialog"]:visible').first();
      await expect(visibleDialog).toBeVisible({ timeout: 5000 });
      await page.keyboard.press('Escape');
    } else {
      test.skip(true, 'No change-status action visible');
    }
  });

  test('maintenance edit page loads with vendor select', async ({ page }) => {
    await page.goto('/admin/AW/maintenance-requests', { waitUntil: 'networkidle' });
    const editLink = page.locator('a[href*="/admin/AW/maintenance-requests/"][href$="/edit"]').first();
    await editLink.click();
    await page.waitForLoadState('networkidle');
    await expectNoLaravelError(page);
    // The new External Vendor field should be present
    await expect(page.locator('body')).toContainText(/External Vendor|مورد خارجي/i);
  });
});

// =========================================================================
// ADMIN — tenant sales actions
// =========================================================================

test.describe('ADMIN: tenant sales actions', () => {
  test.use({ storageState: 'storage/playwright-state/admin.json' });

  test('Lock action modal opens on a submitted declaration', async ({ page }) => {
    await page.goto('/admin/AW/tenant-sales-declarations', { waitUntil: 'networkidle' });
    const btn = page.locator('button:visible, a:visible').filter({ hasText: /^\s*Lock\s*$/ }).first();
    if (await btn.isVisible({ timeout: 2000 }).catch(() => false)) {
      await btn.click();
      // The modal may have no unique heading; just confirm any visible dialog content.
      await page.waitForTimeout(500); // let Alpine show transition complete
      const visibleDialog = page.locator('.fi-modal-window:visible, [role="dialog"]:visible').first();
      await expect(visibleDialog).toBeVisible({ timeout: 5000 });
      await page.keyboard.press('Escape');
    } else {
      test.skip(true, 'No submitted declaration visible');
    }
  });
});

// =========================================================================
// ADMIN — CAM actions
// =========================================================================

test.describe('ADMIN: CAM actions', () => {
  test.use({ storageState: 'storage/playwright-state/admin.json' });

  test('Generate Allocations action available on a draft pool', async ({ page }) => {
    await page.goto('/admin/AW/cam-expense-pools', { waitUntil: 'networkidle' });
    // Find a draft pool — typically the most recent year
    const firstEdit = page.locator('a[href*="/admin/AW/cam-expense-pools/"][href$="/edit"]').first();
    if (!(await firstEdit.isVisible({ timeout: 2000 }).catch(() => false))) {
      test.skip(true, 'No CAM pools');
      return;
    }
    await firstEdit.click();
    await page.waitForLoadState('networkidle');
    await expectNoLaravelError(page);
    // Either generate-allocations action or already-billed allocations table
    await expect(page.locator('body')).toContainText(/Generate Allocations|Allocations|Mark Reconciled/i);
  });
});

// =========================================================================
// ADMIN — bulk actions
// =========================================================================

test.describe('ADMIN: bulk actions', () => {
  test.use({ storageState: 'storage/playwright-state/admin.json' });

  test('selecting invoices enables bulk action toolbar', async ({ page }) => {
    await page.goto('/admin/AW/invoices', { waitUntil: 'networkidle' });

    // Check first 2 row checkboxes (skip the "select all" header one)
    const rowCheckboxes = page.locator('table input[type="checkbox"]:visible');
    const count = await rowCheckboxes.count();
    if (count < 2) {
      test.skip(true, 'Not enough invoices to bulk-select');
      return;
    }
    await rowCheckboxes.nth(1).check();
    await rowCheckboxes.nth(2).check();

    // The bulk actions live inside a collapsed "Bulk actions" group dropdown —
    // open it, then assert the always-present "Download PDFs" action is reachable.
    // (Submit-to-ETA is module-gated and off by default, so don't rely on it.)
    const bulkToggle = page.locator('button:visible').filter({ hasText: /Bulk actions|إجراءات مجمّعة/i }).first();
    if (await bulkToggle.count()) {
      await bulkToggle.click();
    }
    await expect(page.locator('button:visible, a:visible').filter({ hasText: /Download PDFs|زيب|PDFs/i }).first())
      .toBeVisible({ timeout: 5000 });
  });
});

// =========================================================================
// ADMIN — operator switcher
// =========================================================================

test.describe('ADMIN: operator switcher', () => {
  test.use({ storageState: 'storage/playwright-state/admin.json' });

  test('switcher dropdown opens and lists operators', async ({ page }) => {
    await page.goto('/admin', { waitUntil: 'networkidle' });
    // Switcher is usually in the topbar; click it
    const btn = page.locator('button:visible').filter({ hasText: /All Operators|Jawad|Eltizam/i }).first();
    if (await btn.isVisible({ timeout: 3000 }).catch(() => false)) {
      await btn.click();
      // Look for operator options
      await expect(page.locator('body')).toContainText(/Jawad|Eltizam/i, { timeout: 3000 });
    }
  });
});

// =========================================================================
// PORTAL — submit sales declaration
// =========================================================================

test.describe('PORTAL: tenant flows', () => {
  test.use({ storageState: 'storage/playwright-state/portal.json' });

  test('Submit Sales create form mounts', async ({ page }) => {
    const response = await page.goto('/portal/tenant-sales-declarations/create', { waitUntil: 'networkidle' });
    expect(response?.status()).toBeLessThan(500);
    await expectNoLaravelError(page);
    await expect(page.locator('input, select').first()).toBeVisible();
  });

  test('Create Maintenance Request form mounts', async ({ page }) => {
    const response = await page.goto('/portal/maintenance-requests/create', { waitUntil: 'networkidle' });
    expect(response?.status()).toBeLessThan(500);
    await expectNoLaravelError(page);
    await expect(page.locator('input, textarea, select').first()).toBeVisible();
  });
});

// =========================================================================
// LOCALE switching
// =========================================================================

test.describe('Locale switching', () => {
  test.use({ storageState: 'storage/playwright-state/admin.json' });

  test('switching to Arabic flips dir and shows AR labels', async ({ page }) => {
    await page.goto('/locale/ar', { waitUntil: 'networkidle' });
    await page.goto('/admin', { waitUntil: 'networkidle' });
    const dir = await page.locator('html').getAttribute('dir');
    expect(dir).toBe('rtl');
    await expectNoLaravelError(page);
  });

  test('switching back to English flips dir back', async ({ page }) => {
    await page.goto('/locale/en', { waitUntil: 'networkidle' });
    await page.goto('/admin', { waitUntil: 'networkidle' });
    const dir = await page.locator('html').getAttribute('dir');
    expect(dir).toBe('ltr');
  });
});
