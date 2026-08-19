# ETA & Paymob — live-certification runbook

How to take the two external integrations from their current safe state (ETA in
**mock**, Paymob **disabled**) to certified-live. Both are **code-complete for the
common cases**; what remains is mostly credentials, an ETA signing certificate, and
manual sandbox testing. Run the preflight any time you change credentials:

```
php artisan integrations:check            # both
php artisan integrations:check --eta      # ETA only
php artisan integrations:check --paymob   # Paymob only
```
It verifies credentials + connectivity **without** submitting a document or charging a card.

---

## A. Paymob (card payments)

**Code status:** ✅ complete — 3-step API (auth → order → payment key → iframe), HMAC-SHA512
webhook verification, 45-min session reuse with amount-change protection, idempotent capture.
**No code changes needed.** Certification is credentials + KYC.

### A1. Sandbox
1. Create a Paymob account → copy from the dashboard: **API Key**, **Card Integration ID**, **Iframe ID**, **HMAC Secret**.
2. Register callback URLs on the integration (use an HTTPS tunnel for local):
   - Transaction *processed* (server-to-server): `https://<host>/paymob/callback`
   - Transaction *response* (browser return): `https://<host>/paymob/return`
3. Set env, then `php artisan config:clear`:
   ```
   PAYMOB_ENABLED=true
   PAYMOB_API_KEY=…   PAYMOB_INTEGRATION_ID=…   PAYMOB_IFRAME_ID=…   PAYMOB_HMAC_SECRET=…
   ```
4. `php artisan integrations:check --paymob` → expect "Authenticated (token received)".
5. Smoke test from the portal: pay an invoice with a Paymob **test card**; confirm the invoice flips to `paid` (via the S2S callback), a `captured` Payment row appears, and the tenant gets a receipt notification. Test a **declined** card → invoice stays open, Payment `failed`. Fire the callback twice → no double-capture (idempotent).

### A2. Production
6. Complete Paymob **KYC**; wait for approval.
7. Switch the dashboard to **Live** and **rotate all four credentials** (Paymob re-issues them). Re-register the callback URLs on the production domain.
8. Update env, `config:clear`, deploy. Run one small real charge, then monitor.

---

## B. ETA (e-invoicing)

**Code status:** ✅ OAuth + document submission + status handling + retry queue + UI are built.
⚠️ **Two real gaps before production** (both flagged below). EGS codes + issuer identity are now
**env-driven** (no code change to set real values).

### B1. What you must obtain (external dependencies)
- [ ] **ETA API credentials** — `client_id` + `client_secret` (from the operator's ETA taxpayer profile).
- [ ] **Real issuer identity** — operator legal name, TRN, and address.
- [ ] **Registered EGS item codes** — one per charge type (base rent / service / utility / parking / percentage rent).
- [ ] **A signing certificate** — ETA **production rejects unsigned B2B documents**. The signature (CAdES) is produced from the operator's certificate, usually on a USB/HSM token or a cloud key vault. **This is the main blocker and is an external procurement.**

### B2. Configure (env)
```
ETA_MOCK=false
ETA_ENDPOINT=https://api.preprod.invoicing.eta.gov.eg        # preprod first; prod = api.invoicing.eta.gov.eg
ETA_AUTH_ENDPOINT=https://id.preprod.eta.gov.eg/connect/token #            prod = id.eta.gov.eg/connect/token
ETA_CLIENT_ID=…   ETA_CLIENT_SECRET=…
ETA_ISSUER_TRN=…  ETA_ISSUER_NAME="…"  ETA_ISSUER_GOVERNATE=…  ETA_ISSUER_CITY=…  ETA_ISSUER_STREET=…  ETA_ISSUER_BUILDING=…
ETA_EGS_BASE_RENT=…  ETA_EGS_SERVICE_CHARGE=…  ETA_EGS_UTILITY=…  ETA_EGS_PARKING=…  ETA_EGS_PERCENTAGE_RENT=…
```
Then `php artisan config:clear` and `php artisan integrations:check --eta` → expect "OAuth token acquired".

### B3. Preprod test (unsigned plumbing)
Submit a test invoice for a **business tenant that has a `tax_id`** (the builder rejects a business with no tax ID) via the admin **Submit to ETA** action. Confirm `eta_status` → `valid`/`submitted` (not `rejected`) and `eta_submission_id`/`eta_long_id` populate. Fix-and-resubmit works for rejections.

### B4. Signing (required for production)
1. Implement `App\Services\Eta\Signing\EtaDocumentSigner` (CAdES) using the operator's certificate.
2. Bind it in `AppServiceProvider` (replacing `UnsignedEtaSigner`).
3. Set `ETA_SIGNING_ENABLED=true` (+ `ETA_CERTIFICATE_PATH` / `ETA_PRIVATE_KEY_PATH`).
   *Guard:* the client **refuses to submit** if signing is enabled while only the passthrough signer is bound — you can't accidentally ship unsigned documents.
4. Re-test against preprod with signing on, then cut over the endpoints to production.

> **Known follow-up (not blocking preprod):** the receiver address on the document is currently
> a fixed Giza/6th-October placeholder. Before production, capture each tenant's real
> governate/city (a small schema + form addition) and feed them into `EtaJsonBuilder`.

---

## Who does what

| Item | Owner | State |
|---|---|---|
| Paymob flow + HMAC + idempotency | code | ✅ done |
| Paymob sandbox creds + KYC + live keys | operator | ⏳ external |
| ETA OAuth + submit + status + retry + UI | code | ✅ done |
| ETA EGS codes + issuer identity (env) | operator → env | ⏳ values needed |
| ETA CAdES signing | code + operator cert | ⏳ blocker (external cert) |
| ETA receiver address from tenant | code | 🔜 small follow-up |
| Credential preflight (`integrations:check`) | code | ✅ done |
