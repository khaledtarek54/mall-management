# Paymob setup

This is the step-by-step to get Pay Now working in the tenant portal. Sandbox
gets you all the way to a card form and a server-to-server callback that
flips an invoice to `paid`; production is the same flow with different
credentials.

> **Engineers / porting to another system:** this doc is the *operator*
> walkthrough. The complete implementation reference — every rule, file,
> API body, HMAC field order, concurrency lock and gotcha — is
> [`docs/integrations/PAYMOB.md`](docs/integrations/PAYMOB.md).

You need 4 credentials from your Paymob dashboard:

| .env variable          | Where in the dashboard                                              |
|------------------------|---------------------------------------------------------------------|
| `PAYMOB_API_KEY`       | Account → Profile → API Key                                         |
| `PAYMOB_INTEGRATION_ID`| Developers → Payment Integrations → your card integration → ID      |
| `PAYMOB_IFRAME_ID`     | Developers → Iframes → your iframe → ID                             |
| `PAYMOB_HMAC_SECRET`   | Account → Profile → HMAC                                            |

## 1. Create a Paymob sandbox account

1. Open <https://accept.paymob.com> and click **Sign up**.
2. Pick **Egypt** as the country (the integration is wired for EGP). For
   another currency edit `config/integrations.php` → `paymob.currency`.
3. Verify your email, finish onboarding. You land in the test/sandbox
   environment by default — there's no separate sandbox URL; Paymob switches
   the environment based on which account you're logged into.

## 2. Grab the 4 credentials

In the dashboard:

1. **API Key** — top-right avatar → **Profile** → scroll to **API Key**.
   Copy the long opaque string. This is what we trade for a bearer token
   on each transaction; rotating it invalidates all in-flight sessions, so
   only do it during a known quiet window.

2. **Integration ID** — left nav **Developers** → **Payment Integrations**.
   There's a default "Online Card" integration pre-created on every new
   account. Copy its numeric ID. If you also want to accept wallets later
   you'll create a second integration and update the env var when you flip
   between them.

3. **Iframe ID** — left nav **Developers** → **Iframes**. Create one if the
   list is empty (the default name is fine). Copy the numeric ID.

4. **HMAC** — back to **Profile**, scroll to **HMAC**. Copy the secret.
   This is what we use to verify the server-to-server callback isn't
   spoofed — treat it like a signing key, not a password.

## 3. Populate `.env`

Edit `.env`:

```env
PAYMOB_ENABLED=true
PAYMOB_BASE_URL=https://accept.paymob.com
PAYMOB_API_KEY=<paste from step 2.1>
PAYMOB_INTEGRATION_ID=<paste from step 2.2>
PAYMOB_IFRAME_ID=<paste from step 2.3>
PAYMOB_HMAC_SECRET=<paste from step 2.4>
PAYMOB_CURRENCY=EGP

# Session concurrency lock — leave the defaults unless Paymob is slow for you.
PAYMOB_SESSION_LOCK_SECONDS=30
PAYMOB_SESSION_LOCK_WAIT_SECONDS=10

# Apple Pay is a SEPARATE Paymob integration + needs a verified domain.
# Empty = the Apple Pay button stays hidden; card payments are unaffected.
PAYMOB_APPLE_PAY_INTEGRATION_ID=
```

Then:

```bash
php artisan config:clear
```

`PAYMOB_ENABLED=false` (the default) hides the Pay Now action everywhere so
the demo can run without a live integration — and activates the demo-pay
shortcut (`POST /api/v1/me/invoices/{id}/pay-demo`) so the mobile app can be
built end-to-end before KYC clears.

**There are two switches, and they are asymmetric.** `.env` says *credentials are
provisioned*; the toggle at **/admin/settings → Integrations** says *collect right
now*. They're ANDed, so the UI toggle is a **kill switch**: an operator can stop
card collection instantly during a Paymob outage or a fraud incident, but cannot
turn payments on without the credentials below. See
[`docs/integrations/PAYMOB.md` §5a](docs/integrations/PAYMOB.md#5a-environment-variables).

## 4. Register the callback URLs

Paymob needs to know where to POST the transaction result. In the dashboard:

**Developers → Payment Integrations → your integration → Edit**:

- **Transaction processed callback** → `https://<your-host>/paymob/callback`
- **Transaction response callback** → `https://<your-host>/paymob/return`

The first is the trusted server-to-server hook (HMAC-verified — this is
what actually marks the invoice paid). The second is the browser bounce-back
URL after the iframe; we only use it for the UX flash message.

For local development pair this with an HTTPS tunnel — `ngrok http 8000`
or Herd's share feature — and use the public URL.

## 5. Smoke test

Sandbox cards (no real charge):

| Card                     | Number              | CVV | Expiry  |
|--------------------------|---------------------|-----|---------|
| Approved transaction     | 5123 4567 8901 2346 | 100 | 12/25   |
| Declined transaction     | 4000 0000 0000 0002 | 100 | 12/25   |

Steps:

1. Log into the portal as a tenant who has an outstanding invoice (the demo
   seeder produces a few — Cafe Crema usually has one).
2. Open the invoice, click **Pay Now**.
3. Confirm in the modal. You should be redirected to the Paymob iframe.
4. Pay with the approved test card.
5. You land on `/paymob/return` with the success flash. Within a second
   or two the server-to-server callback fires; reload the invoice and the
   status should be **paid**.
6. The Payment row in the admin panel shows `gateway = paymob`,
   `gateway_transaction_id = paymob:txn:<id>:order:<id>`, full gateway
   payload in `gateway_response`. The tenant gets a
   "Payment received" mail + bell notification.

If you see the success flash but the invoice doesn't update, Paymob couldn't
reach your callback URL. Check the Paymob dashboard → Developers →
Transaction logs → click the transaction → "Callback attempts" tab.

## 6. Going live

When you're ready to take real money:

1. Switch your Paymob account from test to live in the dashboard
   (Compliance → KYC → submit docs). The same 4 credentials are reissued for
   the live environment — rotate all 4 `.env` values.
2. `PAYMOB_BASE_URL` stays `https://accept.paymob.com` (Paymob uses the same
   host for both environments).
3. Re-test with a real card for a small amount before going wide.

## How it's wired

- `config/integrations.php` reads env, exposes the 7 paymob.* keys.
- `app/Services/Paymob/PaymobClient.php` is the thin API wrapper (auth →
  order → payment key → iframe URL + HMAC verify).
- `app/Services/Paymob/PaymobPaymentInitiator.php` glues the client to an
  `initiated` Payment row keyed by the Paymob order ID. Idempotent: a second
  call inside the reuse window (`REUSE_WINDOW_SECONDS`, 45 minutes) returns
  the existing session rather than burning a fresh Paymob order.
- `app/Http/Controllers/Paymob/CallbackController.php` handles the S2S
  callback (HMAC-verified, CSRF-exempt) and the browser return URL.
- The Pay Now action in the portal `Invoices` table and view page calls the
  initiator and redirects to the iframe.
- `app/Http/Controllers/PaymentLinkController.php` is the **public, no-login**
  channel: `/pay/{token}` → Paymob → `/pay/{token}/status`. Each invoice carries
  a rotatable `payment_link_token`; rotation kills every URL previously issued.
- Invoice totals + tenant notifications fire from the existing
  `Payment::saved` hook once the callback flips status to `captured`.

Full implementation detail (session reuse, the concurrency lock, the capture
clamp, HMAC field order, the mobile contract):
[`docs/integrations/PAYMOB.md`](docs/integrations/PAYMOB.md).

## Mobile (Flutter) integration

The mobile-facing endpoint and contract are documented in
[`PAYMOB-FLUTTER.md`](./PAYMOB-FLUTTER.md) — hand that doc to the Flutter
dev. Quick summary for operators:

- The Flutter app never holds the Paymob API key or HMAC secret.
- It calls `POST /api/v1/me/invoices/{invoice}/paymob-session` (Sanctum
  bearer auth) to get a short-lived session, then opens either the
  iframe URL in a WebView or hands the payment token to the Paymob SDK.
- Repeat taps inside 45 minutes return the cached session (`reused: true`)
  — no duplicate Paymob orders.
- The S2S `/paymob/callback` webhook is still the authoritative source
  of truth for the `paid` status. The mobile client refreshes the invoice
  after the payment UI dismisses; it never trusts the SDK's local result.
- Rate limit: the authenticated API surface's 60 requests per minute per
  tenant token (the session endpoint has no tighter limit of its own — the
  45-minute reuse window is what stops repeat taps burning Paymob orders).
