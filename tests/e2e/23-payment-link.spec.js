/**
 * The public payment link — /pay/{token}.
 *
 * This is the only unauthenticated HTML surface in the app, and the only page a
 * paying client ever sees. Everything else in this suite runs behind a Filament
 * panel with a session; nothing covered this. It is also the page most likely to
 * break silently, because it does NOT inherit the panel's layout, assets or
 * locale handling — it is its own Blade view.
 *
 * The token is read from the database rather than the admin UI, because the
 * "Payment link" action only renders when PAYMOB_ENABLED=true, and this page has
 * to work whether or not the gateway is switched on.
 */
import { execFileSync } from 'node:child_process';
import { expect, test } from '@playwright/test';
import { expectNoLaravelError } from './helpers.js';

/** Run a snippet through artisan tinker and return its trimmed stdout. */
function tinker(php) {
  return execFileSync('php', ['artisan', 'tinker', '--execute', php], {
    encoding: 'utf8',
    cwd: process.cwd(),
  })
    .trim()
    .split('\n')
    .pop()
    .trim();
}

/** An unpaid invoice's pay token, plus the details the page must show. */
function payableInvoice() {
  const raw = tinker(`
    $i = App\\Models\\Invoice::whereNotIn('status', ['cancelled', 'credited', 'draft'])
        ->where('balance', '>', 0)
        ->with('tenant')
        ->first();
    echo $i ? json_encode([
        'token' => $i->paymentLinkToken(),
        'number' => $i->number,
        'tenant' => $i->tenant?->name,
    ]) : '';
  `);

  return raw ? JSON.parse(raw) : null;
}

test.describe('Public payment link', () => {
  // No storageState on purpose: a client following an emailed link has no session.
  test.use({ storageState: { cookies: [], origins: [] } });

  test('renders the invoice to a visitor with no login', async ({ page }) => {
    const invoice = payableInvoice();
    test.skip(!invoice, 'No payable invoice in the demo data.');

    const response = await page.goto(`/pay/${invoice.token}`, { waitUntil: 'networkidle' });

    expect(response.status()).toBe(200);
    await expectNoLaravelError(page);

    // The client must be able to tell WHAT they are paying before they pay it.
    await expect(page.getByText(invoice.number).first()).toBeVisible();
    if (invoice.tenant) {
      await expect(page.getByText(invoice.tenant).first()).toBeVisible();
    }
  });

  test('never leaks a session — the page must not show admin chrome', async ({ page }) => {
    const invoice = payableInvoice();
    test.skip(!invoice, 'No payable invoice in the demo data.');

    await page.goto(`/pay/${invoice.token}`, { waitUntil: 'networkidle' });

    // A public page that renders the panel sidebar would mean the layout is
    // pulling authenticated context it has no business having.
    await expect(page.locator('.fi-sidebar')).toHaveCount(0);
    await expect(page.locator('.fi-topbar')).toHaveCount(0);
  });

  test('serves Arabic RTL from ?lang=ar, with no session to remember it', async ({ page }) => {
    const invoice = payableInvoice();
    test.skip(!invoice, 'No payable invoice in the demo data.');

    await page.goto(`/pay/${invoice.token}?lang=ar`, { waitUntil: 'networkidle' });

    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    await expectNoLaravelError(page);
  });

  test('404s an unknown token without disclosing whether it existed', async ({ page }) => {
    const response = await page.goto('/pay/definitely-not-a-real-token-000000000000000', {
      waitUntil: 'domcontentloaded',
    });

    expect(response.status()).toBe(404);
  });

  test('the status page is reachable and does not error', async ({ page }) => {
    const invoice = payableInvoice();
    test.skip(!invoice, 'No payable invoice in the demo data.');

    const response = await page.goto(`/pay/${invoice.token}/status`, { waitUntil: 'networkidle' });

    expect(response.status()).toBe(200);
    await expectNoLaravelError(page);
  });

  test('a rotated link goes dead in a real browser', async ({ page }) => {
    // The revocation path, end to end: the URL a client already has must stop
    // working the moment the operator regenerates it.
    const invoice = payableInvoice();
    test.skip(!invoice, 'No payable invoice in the demo data.');

    await expect(async () => {
      const res = await page.goto(`/pay/${invoice.token}`, { waitUntil: 'domcontentloaded' });
      expect(res.status()).toBe(200);
    }).toPass();

    const fresh = tinker(`
      echo App\\Models\\Invoice::where('payment_link_token', '${invoice.token}')
          ->first()?->rotatePaymentLinkToken();
    `);

    expect(fresh).not.toBe(invoice.token);

    const dead = await page.goto(`/pay/${invoice.token}`, { waitUntil: 'domcontentloaded' });
    expect(dead.status()).toBe(404);

    const live = await page.goto(`/pay/${fresh}`, { waitUntil: 'domcontentloaded' });
    expect(live.status()).toBe(200);
  });
});
