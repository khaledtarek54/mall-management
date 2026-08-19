# Paymob integration — Flutter mobile guide

This is everything the Flutter app needs to take card payments through
Paymob. The backend, webhook verification, payment reconciliation, and
notification side are already shipped — this doc covers only what runs on
the device.

**TL;DR:** call one endpoint, open a WebView, refresh the invoice when it
closes.

---

## 1. Architecture

```
┌──────────────┐    1. POST paymob-session   ┌────────────┐
│ Flutter app  │ ─────────────────────────►  │  Backend   │
│ (tenant)     │ ◄─────────────── token ─────│  (Laravel) │
└──────┬───────┘                              └──────┬─────┘
       │ 2. open iframe URL in WebView                │
       │                                              │
       ▼                                              │
┌──────────────┐    3. card form, 3DS, etc.   ┌──────▼────────┐
│ Paymob iframe│ ◄──────────────────────────► │     Paymob    │
└──────┬───────┘                              └──────┬────────┘
       │                                             │
       │ 4. user finishes, iframe redirects          │ 4. S2S webhook
       │    back to /paymob/return                   │    /paymob/callback
       │                                             │    (HMAC verified)
       ▼                                             ▼
┌──────────────┐    5. refresh invoice        ┌──────────────┐
│ Flutter app  │ ◄─────────────────────────►  │   Backend    │
│ closes sheet │                              │  invoice =   │
│              │                              │   "paid"     │
└──────────────┘                              └──────────────┘
```

You own steps **1**, **2**, and **5**. Paymob owns **3**. Our backend owns
**4**.

The webhook in step 4 is the **only source of truth** for whether a payment
succeeded. The Paymob iframe / SDK can show a "success" page in step 3
**before** our backend has finished processing — never trust the iframe's
client-side result, always refresh from the backend.

---

## 2. Authentication

The Paymob endpoint is Sanctum-protected. Login first, keep the bearer
token, send it on every Paymob call.

### Login

```http
POST /api/v1/auth/login
Content-Type: application/json

{
  "email": "andiamoitalian@example.com",
  "password": "password",
  "device_name": "Khaled's iPhone 16"
}
```

Response 200:

```json
{
  "data": {
    "tenant": {
      "id": 12,
      "name": "Andiamo Italian",
      "legal_name": "Andiamo Italian Ltd",
      "type": "company",
      "email": "andiamoitalian@example.com",
      "phone": "+201001234567",
      "whatsapp": "+201001234567",
      "contact_person": "Marco Rossi",
      "status": "active",
      "tax_id": "100-200-300"
    },
    "token": "1|AAJM7AfP98W4056WRdKCeeGAecCV8wYYPo5AcWzr731a96ae",
    "token_type": "Bearer"
  },
  "message": "Logged in successfully."
}
```

Errors:

| Status | Body                               | Meaning                         |
|--------|------------------------------------|---------------------------------|
| 422    | `{"errors": {"email": [...]}}`     | Validation failed               |
| 401    | `{"message": "Invalid credentials"}` | Wrong email / password        |
| 429    | —                                  | More than 5 attempts per minute |

Store the `token` securely (`flutter_secure_storage`). Send it as
`Authorization: Bearer <token>` on every subsequent call.

### Logout

```http
POST /api/v1/auth/logout
Authorization: Bearer <token>
```

Deletes only the current device's token. Other devices stay logged in.

---

## 3. Initiate a payment session

The mobile-facing endpoint that does everything for you. It auths against
Paymob, creates an order, requests a payment key, and returns a payload
that lets you launch either a WebView or the Paymob SDK.

```http
POST /api/v1/me/invoices/{invoice_id}/paymob-session
Authorization: Bearer <token>
Accept: application/json
```

Response 200 (keys are camelCase — the backend's API middleware re-cases
the internal snake_case Resource on the way out):

```json
{
  "data": {
    "paymentToken": "ZXlKaGJHY2lPaUpJVXpVeE1pSXNJblI1Y0NJNklr…",
    "iframeUrl": "https://accept.paymob.com/api/acceptance/iframes/1049031?payment_token=ZXlKaG…",
    "iframeId": "1049031",
    "orderId": 537814381,
    "paymentId": 193,
    "expiresAt": "2026-06-02T09:46:39+00:00",
    "reused": false
  }
}
```

| Field          | Use it for                                                 |
|----------------|------------------------------------------------------------|
| `paymentToken` | Native Paymob SDK (`PaymobPayment.instance.acceptPayment`) |
| `iframeUrl`    | WebView the user opens                                     |
| `iframeId`     | Native SDK config (rarely needed if you use `iframeUrl`)   |
| `orderId`      | Paymob's order id — useful for support / logs              |
| `paymentId`    | Our `Payment` row id — useful for polling                  |
| `expiresAt`    | Session expiration (1 hour from issue)                     |
| `reused`       | `true` if you called within 45 min and got a cached session |

### Error contract

| Status | `error`                  | What happened                                | UX             |
|--------|--------------------------|----------------------------------------------|----------------|
| 401    | —                        | Token missing / expired                      | Re-auth        |
| 404    | —                        | Unknown invoice **or** one belonging to another tenant (deliberately indistinguishable — no ID enumeration) | Error toast |
| 409    | `paymob_disabled`        | Backend has Paymob switched off              | Hide Pay Now   |
| 422    | `no_balance`             | Invoice is already paid                      | "Already paid" |
| 422    | `invoice_not_payable`    | Invoice cancelled / credited                 | Hide Pay Now   |
| 429    | —                        | More than 60 calls/min on the authenticated surface (shared across all `/api/v1` calls) | "Try again in 1 min" |
| 502    | `paymob_upstream_error`  | Paymob returned an error                     | "Try again later" |

### Idempotency

You can call this endpoint as many times as you want — within the 45-minute
reuse window it returns the *same* session (`reused: true`), so the user
doesn't get charged twice and Paymob doesn't get spammed with orders. Build
defensively: don't worry about double-tap protection on the Pay button.

---

## 4. Option A — WebView (recommended for v1)

Simplest, ships in a day, works with any version of the Paymob iframe.

### Packages

```yaml
# pubspec.yaml
dependencies:
  webview_flutter: ^4.7.0
  flutter_secure_storage: ^9.0.0
  http: ^1.2.0
```

### Service layer

```dart
// lib/payments/paymob_service.dart
import 'package:http/http.dart' as http;
import 'dart:convert';

class PaymobSession {
  final String paymentToken;
  final String iframeUrl;
  final int orderId;
  final int paymentId;
  final DateTime expiresAt;
  final bool reused;

  PaymobSession.fromJson(Map<String, dynamic> j)
    : paymentToken = j['paymentToken'],
      iframeUrl = j['iframeUrl'],
      orderId = j['orderId'],
      paymentId = j['paymentId'],
      expiresAt = DateTime.parse(j['expiresAt']),
      reused = j['reused'];
}

class PaymobService {
  PaymobService({required this.baseUrl, required this.token});
  final String baseUrl;
  final String token;

  Future<PaymobSession> initSession(int invoiceId) async {
    final res = await http.post(
      Uri.parse('$baseUrl/api/v1/me/invoices/$invoiceId/paymob-session'),
      headers: {
        'Authorization': 'Bearer $token',
        'Accept': 'application/json',
      },
    );

    if (res.statusCode == 200) {
      return PaymobSession.fromJson(jsonDecode(res.body)['data']);
    }

    final body = jsonDecode(res.body);
    throw PaymobException(
      status: res.statusCode,
      code: body['error'] ?? 'unknown',
      message: body['message'] ?? 'Payment failed',
    );
  }
}

class PaymobException implements Exception {
  PaymobException({required this.status, required this.code, required this.message});
  final int status;
  final String code;
  final String message;
}
```

### WebView screen

```dart
// lib/payments/paymob_webview_page.dart
import 'package:flutter/material.dart';
import 'package:webview_flutter/webview_flutter.dart';

class PaymobWebViewPage extends StatefulWidget {
  const PaymobWebViewPage({
    super.key,
    required this.iframeUrl,
    required this.returnHost, // e.g. "mall-management.test"
  });
  final String iframeUrl;
  final String returnHost;

  @override
  State<PaymobWebViewPage> createState() => _PaymobWebViewPageState();
}

class _PaymobWebViewPageState extends State<PaymobWebViewPage> {
  late final WebViewController _controller;
  bool _resolved = false;

  @override
  void initState() {
    super.initState();
    _controller = WebViewController()
      ..setJavaScriptMode(JavaScriptMode.unrestricted)
      ..setNavigationDelegate(NavigationDelegate(
        onNavigationRequest: (request) {
          // Paymob redirects back to /paymob/return when the user is done.
          // success=true|false is informational only — the real status comes
          // from the backend on the next refresh.
          if (request.url.contains('${widget.returnHost}/paymob/return')) {
            final uri = Uri.parse(request.url);
            final success = uri.queryParameters['success'] == 'true';
            if (!_resolved) {
              _resolved = true;
              Navigator.of(context).pop(success);
            }
            return NavigationDecision.prevent;
          }
          return NavigationDecision.navigate;
        },
      ))
      ..loadRequest(Uri.parse(widget.iframeUrl));
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Pay invoice')),
      body: WebViewWidget(controller: _controller),
    );
  }
}
```

### Wire it into the invoice screen

```dart
Future<void> _payInvoice(BuildContext context, Invoice invoice) async {
  try {
    final session = await paymob.initSession(invoice.id);

    final paid = await Navigator.of(context).push<bool>(
      MaterialPageRoute(builder: (_) => PaymobWebViewPage(
        iframeUrl: session.iframeUrl,
        returnHost: 'mall-management.test', // your production host
      )),
    );

    // Don't trust `paid` — refetch the invoice from the backend.
    await invoicesRepository.refresh();

    if (!context.mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text(paid == true
          ? 'Payment submitted. Your invoice will update shortly.'
          : 'Payment cancelled.'),
    ));
  } on PaymobException catch (e) {
    final message = switch (e.code) {
      'no_balance' => 'This invoice is already paid.',
      'invoice_not_payable' => 'This invoice cannot be paid.',
      'paymob_disabled' => 'Online payments are temporarily unavailable.',
      'paymob_upstream_error' => 'Payment gateway is unavailable. Please try again.',
      _ => e.message,
    };
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(message)));
  }
}
```

That's the whole integration.

---

## 5. Option B — Native Paymob SDK (later)

Better UX (native card form, Apple Pay / Google Pay, save card), but ~3–5
days of extra work and a native-plugin dependency. Recommended for v1.1
once you have real users paying frequently.

```yaml
dependencies:
  paymob_payment: ^1.0.0  # check the latest version on pub.dev
```

```dart
import 'package:paymob_payment/paymob_payment.dart';

PaymobPayment.instance.initialize(
  apiKey: 'NOT-NEEDED-CLIENT-DOESNT-AUTH-DIRECTLY',
  // ↑ the SDK is built around the assumption that you have the merchant
  // API key on the device — DON'T do that. Use the session approach below.
);

Future<void> _payWithSdk(Invoice invoice) async {
  final session = await paymob.initSession(invoice.id);

  await PaymobPayment.instance.payWithSavedToken(
    context: context,
    paymentToken: session.paymentToken,
    onPayment: (response) async {
      // Ignore response.success — refetch from backend.
      await invoicesRepository.refresh();
    },
  );
}
```

If you go this route, **never** ship the merchant API key on the device.
The backend session endpoint is designed so the SDK only ever sees a
short-lived token scoped to one order.

---

## 6. After the payment sheet dismisses

The webhook from Paymob to our backend can take a few seconds after the
user sees "Success" on Paymob's side. Your app needs to handle the small
window between "user thinks they paid" and "backend confirms paid".

**Simplest pattern (recommended):**

1. Close the WebView / SDK sheet.
2. Show a snackbar: "Payment submitted, your invoice will update
   shortly."
3. Pull-to-refresh on the invoice list, or auto-refresh after 2-3 seconds.

**More polished pattern:**

1. Close the sheet, show a spinner overlay.
2. Poll `GET /api/v1/me/invoices/{id}` every 1.5 seconds for up to 15
   seconds.
3. If the invoice flips to `paid`, show a green checkmark.
4. If 15 seconds pass with no change, show "Payment is processing —
   refresh in a minute."

```dart
Future<bool> pollUntilPaid(int invoiceId, {int maxAttempts = 10}) async {
  for (var i = 0; i < maxAttempts; i++) {
    await Future.delayed(const Duration(milliseconds: 1500));
    final invoice = await api.showInvoice(invoiceId);
    if (invoice.status == 'paid') return true;
  }
  return false;
}
```

The `paymentId` from the session response gives you a stable handle if
you want to surface "this payment is processing" UI even before the
invoice flips. The full mobile invoice surface (`me/invoices`,
`me/invoices/{id}`, `me/payments`, `me/statement`, …) is in routes/api.php
— see the [mobile API contract](#) the backend team owns.

---

## 7. Sandbox testing

The backend is currently pointed at Paymob sandbox. Use these cards inside
the WebView / SDK:

| Card                  | Number              | Expiry | CVV |
|-----------------------|---------------------|--------|-----|
| Approved              | 5123 4567 8901 2346 | 12/25  | 100 |
| Declined              | 4111 1111 1111 1112 | 12/25  | 100 |
| 3D Secure approved    | 4987 6543 2109 8769 | 12/25  | 099 |

Sample tenant logins for testing:

| Email                              | Password   | Has outstanding invoices |
|------------------------------------|------------|--------------------------|
| `andiamoitalian@example.com`       | `password` | ✅                       |
| `quickcutsbarber@example.com`      | `password` | ✅                       |
| `vintagecloset@example.com`        | `password` | ✅                       |

**For local dev**, point at: `https://mall-management.test` (Herd) or
whatever URL the backend team gives you.

**Note:** the backend host needs to be HTTPS for Paymob's iframe to work
on iOS — `localhost` http will fail with mixed-content errors.

---

## 8. Going to production

The mobile-side checklist:

- [ ] Change the base URL to the production backend (env-config it; don't
      hardcode).
- [ ] Confirm with backend team that `PAYMOB_ENABLED=true` is set and
      production Paymob credentials are loaded.
- [ ] Real Paymob production cards will be live — sandbox cards above
      will be rejected.
- [ ] On iOS, add `NSAppTransportSecurity → NSAllowsArbitraryLoads = NO`
      and confirm the backend host has a valid cert.
- [ ] On Android, the WebView is allowed to load HTTPS resources by
      default. No special manifest entry needed.
- [ ] Crash analytics on `PaymobException` so we can see failure rates
      per `error` code.

---

## 9. FAQ

**Q: Can the same invoice be paid twice?**
No. Once captured, the invoice's `balance` is 0 and a second session
attempt returns `422 no_balance`.

**Q: What happens if the user closes the WebView mid-payment?**
The Payment row stays in `initiated` state on the backend, indefinitely —
there is no expiry job, and it needs none: an `initiated` payment has **zero**
effect on the invoice balance or the ledger. The user can tap Pay Now again —
within 45 minutes they get the same session (idempotent), after that a fresh
one. Don't surface `initiated` rows as "pending payments" in the app.

**Q: How do refunds work?**
Currently manual through the admin panel — a tenant who wants a refund
asks operations and they handle it on Paymob's dashboard + issue a credit
note. A self-service refund flow isn't shipped.

**Q: Can the app save a card for next time?**
That's a native-SDK-only feature (Option B). Not available via WebView.

**Q: How do I know if Paymob is configured / live?**
Hit `GET /api/v1/auth/me` and try `paymob-session` for a known
outstanding invoice. If it returns `409 paymob_disabled`, hide the Pay Now
button.

**Q: What happens if my Sanctum token expires mid-payment?**
Tokens don't currently expire on the backend (Sanctum default). If you
get a `401` mid-flow, kick the user back to login.

**Q: Why do I get `reused: true` sometimes?**
The backend caches your initiated session for 45 minutes so repeated
taps don't burn Paymob orders. This is a feature, not a bug — the cached
session is still valid (it's the same iframe URL you got the first time).

---

## 10. Questions / blockers

Ask the backend team.

- Operator-side setup (env vars, Paymob dashboard config, callback URLs, going
  live): [`PAYMOB-SETUP.md`](PAYMOB-SETUP.md).
- The complete implementation reference — every server-side rule, file, API
  body, HMAC field order, the concurrency lock, the capture clamp, and a port
  checklist for another system:
  [`docs/integrations/PAYMOB.md`](PAYMOB.md).
