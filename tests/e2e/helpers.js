import { expect } from '@playwright/test';

async function fillLogin(page, email, password) {
  // Wait for the form to fully mount (Filament + Livewire) before interacting.
  // The brand-logo closure on /owner queries the DB on first paint and can
  // delay form mount enough to race against the submit click.
  const emailInput = page.locator('input[type="email"]');
  await emailInput.waitFor({ state: 'visible', timeout: 15000 });
  await emailInput.fill(email);
  await page.locator('input[wire\\:model="data.password"]').fill(password);
  await Promise.all([
    page.waitForResponse((res) => res.request().method() === 'POST' && /\/livewire\/update/.test(res.url()), { timeout: 30000 }).catch(() => null),
    page.locator('button[type="submit"]').click(),
  ]);
}

async function waitForLoggedIn(page, panelPath) {
  await page.waitForFunction((path) => {
    const u = new URL(window.location.href);
    return u.pathname.startsWith(path) && !u.pathname.endsWith('/login');
  }, panelPath, { timeout: 30000 });
}

export async function loginAdmin(page, email = 'admin@mall.test', password = 'password') {
  await page.goto('/admin/login');
  await fillLogin(page, email, password);
  await waitForLoggedIn(page, '/admin');
}

export async function loginPortal(page, email = 'tenant1@atriomwalk.test', password = 'password') {
  await page.goto('/portal/login');
  await fillLogin(page, email, password);
  await waitForLoggedIn(page, '/portal');
}

export async function loginOwner(page, email = 'owner@atriom.test', password = 'password') {
  // Owners are now RBAC users in the admin app (the standalone /owner portal
  // was retired); they log in at /admin, scoped to their owned properties.
  await page.goto('/admin/login');
  await fillLogin(page, email, password);
  await waitForLoggedIn(page, '/admin');
}

export async function setLocale(page, locale) {
  // Visit a non-Filament route first so middleware writes locale to session
  await page.goto(`/locale/${locale}`).catch(() => {});
  // After locale switch, navigate fresh
  await page.goto('/admin').catch(() => {});
}

export async function expectNoLaravelError(page) {
  // The most reliable check is the HTTP status — anything 5xx is a server error,
  // regardless of which error page template happens to render.
  const response = await page.evaluate(() => {
    return window.performance?.getEntriesByType?.('navigation')?.[0]?.responseStatus;
  });
  if (response && response >= 500) {
    throw new Error(`Server returned HTTP ${response} at ${page.url()}`);
  }

  const body = await page.content();
  // Symfony / Whoops exception page markers
  expect(body).not.toMatch(/<title>[^<]*(Whoops|Symfony\\Component\\HttpKernel\\Exception|Internal Server Error)[^<]*<\/title>/i);
  expect(body).not.toMatch(/exception_message|sf-dump-public/i);
  // Generic 500 / debug page headings
  expect(body).not.toMatch(/<h1>[^<]*Server Error[^<]*<\/h1>/i);
  // Laravel's Ignition debug stack-trace marker (only present on dev 500s)
  expect(body).not.toMatch(/(class="[^"]*ignition|data-ignition|Stack Trace)/i);
  // Filament's own error wrapper (rare but possible)
  expect(body).not.toMatch(/Call to a member function .* on null/i);
}

export async function captureConsoleErrors(page) {
  const errors = [];
  page.on('pageerror', (e) => errors.push(`pageerror: ${e.message}`));
  page.on('console', (msg) => {
    if (msg.type() === 'error') {
      const text = msg.text();
      // Filter out noise (favicons, etc.)
      if (!/favicon|404 \(Not Found\)/i.test(text)) {
        errors.push(`console: ${text}`);
      }
    }
  });
  return errors;
}

/**
 * Open every dropdown that hides `locator`, outermost first, until it is visible.
 *
 * A Filament `ActionGroup` renders its items as `display: none` nodes until the
 * dropdown opens, and `click({ force: true })` skips the actionability checks but
 * still needs a BOX to aim at -- so it throws rather than dispatching. Three specs
 * asserted `toBeVisible()` on such an item and went red the day their act was
 * grouped (2026-08-30); `05-pdfs.spec.js` had the fix and the others did not,
 * which is what a helper living in one spec buys you.
 *
 * Returns the locator so the caller can assert or click it.
 */
export async function revealInDropdown(page, locator) {
  if (await locator.first().isVisible().catch(() => false)) {
    return locator.first();
  }

  if (await locator.count()) {
    const groups = page.locator('.fi-dropdown').filter({ has: locator.first() });
    for (let i = 0, depth = await groups.count(); i < depth; i++) {
      await groups.nth(i).locator('.fi-dropdown-trigger').first().click();
    }
    await expect(locator.first()).toBeVisible({ timeout: 10000 });
  }

  return locator.first();
}

/**
 * Press a page-header or row action and, if it opens one, submit its modal.
 *
 * Targets the act by NAME -- the `wire:click="mountAction('<name>'"` (or
 * `mountTableAction`) Filament emits on every action button -- because these
 * specs run in both languages and the label is the half that differs. Falls back
 * to the label only when no such action exists, so the failure names what a
 * person was looking for.
 *
 * Two things had walked out from under the naive version of this: the act moved
 * inside an `ActionGroup` (see `revealInDropdown`), and `PdfDownloadAction`
 * (2026-08-27) asks which language the document is written in, so pressing the
 * act MOUNTS a modal and the download only starts when it is submitted.
 */
export async function clickHeaderAction(page, labelRegex, actionName) {
  const wireSelector = `button[wire\\:click*="mount"][wire\\:click*="Action('${actionName}'"]`;
  const item = page.locator(wireSelector);

  if (await item.count()) {
    await (await revealInDropdown(page, item)).click();
  } else {
    await page.locator('button:visible, a:visible').filter({ hasText: labelRegex }).first().click();
  }

  await submitActionModal(page);
}

/**
 * Submit the mounted action's modal, when the act opened one.
 *
 * Structural, not by label -- Filament renders the footer actions submit-first,
 * so the first button is the one that runs the act.
 *
 * `waitFor`, NOT `isVisible({ timeout })`: `isVisible()` takes no timeout and
 * answers immediately, so the obvious spelling asked whether the modal was up
 * before the `mountAction` round-trip had returned, got `false`, and skipped the
 * submit -- leaving the download it was waiting for un-started. A modal that is
 * OPEN and whose submit cannot be found is a failure, never a skip: quietly doing
 * nothing is exactly how this came to report green while pressing nothing.
 */
export async function submitActionModal(page) {
  const modal = page.locator('.fi-modal-window:visible').last();
  const opened = await modal.waitFor({ state: 'visible', timeout: 10000 }).then(() => true, () => false);
  if (!opened) {
    return; // the act opened no modal -- it ran on the click
  }

  const submit = modal.locator('.fi-modal-footer-actions button').first();
  await expect(submit).toBeVisible({ timeout: 5000 });
  await submit.click();
}
