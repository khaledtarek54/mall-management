import { expect } from '@playwright/test';

async function fillLogin(page, email, password) {
  await page.locator('input[type="email"]').fill(email);
  await page.locator('input[wire\\:model="data.password"]').fill(password);
  // Click submit and wait for either redirect or form to settle
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

export async function loginPortal(page, email = 'tenant1@haya.test', password = 'password') {
  await page.goto('/portal/login');
  await fillLogin(page, email, password);
  await waitForLoggedIn(page, '/portal');
}

export async function loginOwner(page, email = 'owner@jawad.test', password = 'password') {
  await page.goto('/owner/login');
  await fillLogin(page, email, password);
  await waitForLoggedIn(page, '/owner');
}

export async function setLocale(page, locale) {
  // Visit a non-Filament route first so middleware writes locale to session
  await page.goto(`/locale/${locale}`).catch(() => {});
  // After locale switch, navigate fresh
  await page.goto('/admin').catch(() => {});
}

export async function expectNoLaravelError(page) {
  const body = await page.content();
  // Symfony exception page markers
  expect(body).not.toMatch(/<title>[^<]*(Whoops|Symfony\\Component\\HttpKernel\\Exception)[^<]*<\/title>/i);
  expect(body).not.toMatch(/exception_message|sf-dump-public/i);
  // Generic 500 server error page
  expect(body).not.toMatch(/<h1>[^<]*Server Error[^<]*<\/h1>/i);
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
