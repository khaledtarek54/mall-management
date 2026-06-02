# Paymob setup

This is the step-by-step to get Pay Now working in the tenant portal. Sandbox
gets you all the way to a card form and a server-to-server callback that
flips an invoice to `paid`; production is the same flow with different
credentials.

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
```

Then:

```bash
php artisan config:clear
```

`PAYMOB_ENABLED=false` (the default) hides the Pay Now action everywhere so
the demo can run without a live integration.

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
- Invoice totals + tenant notifications fire from the existing
  `Payment::saved` hook once the callback flips status to `captured`.

## Mobile (Flutter) integration

The Flutter app does **not** hold the Paymob API key or HMAC secret. It calls
our backend, gets a short-lived session, and lets Paymob's mobile SDK (or a
WebView) take it from there. The S2S callback we ship is the authoritative
source of truth for `paid` status — the mobile client polls the invoice
endpoint after the payment UI dismisses.

### Endpoint

```http
POST /api/v1/invoices/{invoice}/paymob-session
Authorization: Bearer <tenant Sanctum token>
```

Response (200):

```json
{
  "data": {
    "payment_token": "ZXlKaGJHY2lPaUpJVXpV…",
    "iframe_url": "https://accept.paymob.com/api/acceptance/iframes/12345?payment_token=ZXlKaG…",
    "iframe_id": "12345",
    "order_id": 4242424,
    "payment_id": 117,
    "expires_at": "2026-06-02T11:30:00+00:00",
    "reused": false
  }
}
```

Error contract:

| Status | `error` body                | Reason                                                       |
|--------|-----------------------------|--------------------------------------------------------------|
| 401    | —                           | No / invalid bearer token                                    |
| 403    | —                           | Invoice belongs to another tenant                            |
| 409    | `paymob_disabled`           | `PAYMOB_ENABLED=false`                                       |
| 422    | `no_balance`                | Invoice fully paid                                           |
| 422    | `invoice_not_payable`       | Invoice cancelled / credited                                 |
| 429    | —                           | More than 5 fresh sessions in a minute                       |
| 502    | `paymob_upstream_error`     | Paymob returned a non-2xx during auth/order/payment-key step |

### Two integration patterns

**A. Native SDK (recommended for production)** — better UX, native card form,
Apple Pay / Google Pay, save-card. Hand the `payment_token` to the
[`paymob_payment`](https://pub.dev/packages/paymob_payment) plugin's
`acceptPayment()`. The `iframe_id` is needed once at app config.

```dart
final session = await api.initPaymobSession(invoiceId: invoice.id);
final result = await PaymobPayment.instance.acceptPayment(
  context: context,
  currency: 'EGP',
  amountInCents: (invoice.balance * 100).round(),
  onPayment: (response) async {
    // Don't trust this response for state. Poll the invoice.
    await api.refreshInvoice(invoice.id);
  },
);
```

**B. WebView (simplest, ships today)** — open the `iframe_url` in
[`webview_flutter`](https://pub.dev/packages/webview_flutter), listen for
the `/paymob/return` redirect to close the sheet.

```dart
final session = await api.initPaymobSession(invoiceId: invoice.id);
await Navigator.push(context, MaterialPageRoute(
  builder: (_) => PaymobWebViewPage(
    iframeUrl: session.iframeUrl,
    onReturn: () async {
      Navigator.pop(context);
      await api.refreshInvoice(invoice.id);
    },
  ),
));
```

### Idempotency

Tapping Pay Now twice within 45 minutes returns the same Paymob session
(`reused: true`). This protects the Paymob-side rate limits and prevents
zombie `initiated` Payment rows. The Flutter app can call the endpoint
defensively without producing duplicates.

### Polling for status

After the user dismisses the SDK / WebView, the Flutter app must refetch the
invoice — the local "success" callback is informational only. The
authoritative status update lands when Paymob hits our `/paymob/callback`
webhook, which can happen seconds after the user sees "success" in the UI.

### Deep-link return URL for WebView

For the WebView path, ask the mobile dev which URL they want to use to detect
"done" — anything we register as the Paymob "Transaction response callback"
will work, e.g. `https://your-host/paymob/return` (current default) or a
custom deep-link scheme like `atriom://paymob/return`. The S2S callback URL
stays `https://your-host/paymob/callback` regardless — that one is server-to-
server and never visible to the app.
