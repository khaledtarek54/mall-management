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
