# Online payment link + Apple Pay

How the public **pay link** works, how it stays separate from the in-app flow, and how to turn on **Apple Pay**.

## What it is

A client can pay an invoice from a public, no-login URL — an alternative to paying inside the mobile app / portal.

```
/pay/{token}            public pay page (invoice summary + Pay button)
/pay/{token}/start      POST → opens a Paymob session → redirect to the gateway
/pay/{token}/status     public result page (paid / failed / processing)
```

- `{token}` is a stable, unguessable per-invoice token (`invoices.payment_link_token`, generated on invoice creation). Unknown tokens → **404** (no enumeration). Routes are throttled (30/min).
- After paying, Paymob's **server-to-server callback** (`/paymob/callback`, HMAC-verified) captures the payment and fires the tenant's existing notification; the browser is returned to **`/pay/{token}/status`**, which shows the result and — if `APP_DEEP_LINK` is set — an **"Open the app to confirm"** button.
- The status page auto-refreshes while `processing`, so it settles to *paid* as soon as the callback lands.

## The two payment ways are separate

Every payment records a **`channel`** (`payments.channel`):

| Channel | Started from | Browser returns to |
|---|---|---|
| `payment_link` | public `/pay/{token}` | public status page `/pay/{token}/status` |
| `mobile_api` | mobile app (`/api/v1/.../paymob-session`) | app handles it |
| `portal` | tenant portal "Pay Now" | `/portal/invoices` |
| `admin` | operator (reserved) | `/portal/invoices` |

Session reuse is scoped **per channel** (a mobile session is never reused for a link payment), and `CallbackController::returned()` routes the browser back by channel. The S2S capture + notification are shared.

## Where the link appears

- **Admin** → invoice row → **Payment link** action (shows a copyable URL to share with the client).
- **Portal** → invoice row → **Payment link** action (the tenant can copy/forward it).
- **Mobile API** → `payment_link_url` on the invoice resource (null once nothing is owed).

## If a link goes somewhere it shouldn't

The pay link is a **bearer URL** — no login, no expiry. That is what makes it work from an email on a phone, and it also means whoever holds it can read the tenant name, the line items and the amounts, and pay the invoice. Links leak: they get forwarded, land in a shared inbox, end up in a WhatsApp screenshot, or sit in browser history on a shop-floor PC.

**Admin** → invoice row (or the invoice edit page) → **Regenerate payment link**. It asks for confirmation, then:

- issues a new token — **every URL sent out before that moment stops working**, on the pay page, the status page and the start endpoint alike;
- leaves any payment already at the gateway alone (Paymob tracks it by its own order id, not by this token), so nobody mid-checkout is stranded;
- records `invoice.pay_link_rotated` in `ops.log` with the invoice and the user.

**You must then send the client the new link** — they will get a "not found" page on the old one, with no explanation. Requires the `invoices.edit` permission. Works on settled invoices too, since a leaked link still discloses a paid invoice.

There is deliberately **no automatic expiry**: it would quietly break legitimate links in already-sent mail and turn every late payer into a support call.

## Turning on Apple Pay

Apple Pay is **scaffolded but off** until you provision it (it can't be tested on localhost — it needs Safari + a verified HTTPS domain + a real Apple Pay-capable device).

1. **Create an Apple Pay integration in Paymob** (separate from the card integration) and copy its integration id.
2. **Verify your domain** with Apple/Paymob: download the domain-association file they give you and place it at `storage/app/apple-pay/domain-association`. It is served at the Apple-required path:
   ```
   /.well-known/apple-developer-merchantid-domain-association
   ```
   (returns 404 until the file exists).
3. Set the integration id:
   ```
   PAYMOB_APPLE_PAY_INTEGRATION_ID=<your apple pay integration id>
   ```
4. The **Apple Pay button** now appears on the pay page. It opens a Paymob session bound to the Apple Pay integration (a separate session from card — never reused across the two). Card payments are unaffected throughout.

## Config

```
PAYMOB_APPLE_PAY_INTEGRATION_ID=   # empty = Apple Pay button hidden
APP_DEEP_LINK=                     # e.g. atriom://invoices ; empty = button hidden

# Serialisation of gateway-session creation (rarely needs changing):
PAYMOB_SESSION_LOCK_SECONDS=30       # lock TTL, held across the call to Paymob
PAYMOB_SESSION_LOCK_WAIT_SECONDS=10  # how long a second, simultaneous tap waits to reuse it
```

Verify Paymob credentials at any time (no charge): `php artisan integrations:check --paymob`.
See also [PAYMOB-SETUP.md](../PAYMOB-SETUP.md) and [docs/modules/06-payments.md](modules/06-payments.md).
