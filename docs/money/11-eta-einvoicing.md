# 11 — ETA E-Invoicing (Egyptian Tax Authority)

> **Status:** Live integration is **wired but NOT certified.** It ships in **MOCK mode by default** (`ETA_MOCK=true`). In mock mode every submission returns a canned `Valid` response and **nothing is sent to the real tax authority.** The real OAuth + submit path exists and is unit-tested against a fake HTTP server, but it has **never been exercised against ETA production**, document **signing is a no-op passthrough**, and the issuer identity + EGS item codes are **placeholders**. See [What is certified vs not](#whats-certified-vs-not-read-this-before-go-live).

---

## Plain-language summary

Egypt requires businesses to send every B2B invoice to the **Egyptian Tax Authority (ETA)** electronically, in a specific JSON format, digitally signed, the moment it is issued. ETA validates the document and either **accepts** it (the invoice is now a legal e-invoice with a government-issued ID) or **rejects** it (with an error you must fix and resubmit).

This system does three things:

1. **Builds the document.** It takes one of our invoices and turns it into the exact JSON shape ETA expects — issuer (us, the operator), receiver (the tenant), one line per charge, the VAT breakdown, and the totals. (`EtaJsonBuilder`)
2. **Submits it.** It logs into ETA with OAuth, optionally signs the document, POSTs it, and reads back whether ETA accepted or rejected it. (`EtaApiClient`)
3. **Records the outcome on the invoice.** It stamps the invoice with the government submission ID, the long ID, the status (`valid` / `rejected` / `submitted`), and the full raw response for the audit trail. (`EtaSubmissionService`)

Because real ETA credentials, a real signing certificate, and the operator's real tax profile (issuer tax number + registered item codes) are not yet provisioned, the whole thing runs in a **mock mode**: it goes through every step *except* actually talking to ETA, and returns a deterministic "accepted" answer. This lets the operator demo the full compliance experience — including the dashboard "X of Y invoices accepted by ETA" widget — before the paperwork lands. Flipping a single env flag (`ETA_MOCK=false`) switches it to the real endpoint.

**Who triggers it:** an admin user with the `invoices.submit_to_eta` permission clicks **"Submit to ETA"** on an issued invoice (or bulk-selects several). There is **no automatic submission** — it is operator-driven, on demand. The module can be turned off entirely (then the button and dashboard widget disappear).

---

## The exact rule / formula

### 0. Entry points — when an invoice is submitted

There are exactly **three** ways `EtaSubmissionService::submit()` runs. **None of them is automatic / scheduled** — ETA submission is always operator-initiated.

| Entry point | Where | Gating |
|---|---|---|
| Single **"Submit to ETA"** row action | `app/Filament/Admin/Resources/Invoices/Tables/InvoicesTable.php:262` | Module `eta` enabled **AND** `eta_status !== 'valid'` **AND** invoice `status ∈ {issued, partially_paid, paid, overdue}` **AND** user has `invoices.submit_to_eta` |
| Bulk **"Submit selected to ETA"** toolbar action | `InvoicesTable.php:304` | Module `eta` enabled **AND** user has `invoices.submit_to_eta`. Per-row, already-`valid` invoices are skipped (counted as "skipped"). |
| `SubmitInvoiceToEta` queued job | `app/Jobs/SubmitInvoiceToEta.php` | Job's `handle()` just calls `EtaSubmissionService::submit()`. **The job class is not dispatched anywhere in the current codebase** — it exists as the async/retry wrapper for when submission is wired into the issue-invoice flow. It is covered by a unit test (`EtaIntegrationTest.php:158`). |

**Important — no submission on invoice issue.** Issuing or paying an invoice does **not** push it to ETA. The invoice sits at `eta_status = null` ("pending") until a human submits it. This is a deliberate consequence of the not-yet-certified status; do not assume issuing == filed-with-ETA.

> The Filament action runs `EtaSubmissionService::submit()` **synchronously** in the web request (`InvoicesTable.php:272`). The `SubmitInvoiceToEta` job is the path that would move this onto the queue with retries/backoff once submission is hooked into automatic billing.

### 1. The idempotency guard

`EtaSubmissionService::submit()` (`EtaSubmissionService.php:25`):

```
if ($invoice->eta_status === 'valid') {
    return $invoice;   // no-op; already accepted by ETA
}
```

An invoice ETA has already accepted (`eta_status === 'valid'`) is **never re-submitted** — neither the single action, the bulk action (`InvoicesTable.php:318` skips it), nor the service. Any other status (`null`, `pending`, `submitted`, `rejected`, `invalid`, `cancelled`) is re-submittable. The whole submit body runs inside `DB::transaction()` (`EtaSubmissionService.php:29`) so the status/response stamp is written atomically.

### 2. The JSON build (`EtaJsonBuilder::build`)

Spec reference baked into the file header: `https://sdk.invoicing.eta.gov.eg/document-package-format/`, **document type `i`, version `1.0`** (`EtaJsonBuilder.php:55-56`). The builder eager-loads `lease.tenant` and `items.charge` (`EtaJsonBuilder.php:20`).

**Top-level document fields** (`EtaJsonBuilder.php:36-68`):

| Field | Value / source |
|---|---|
| `issuer.address` | `config('eta.issuer.address')` → all `ETA_ISSUER_*` env keys |
| `issuer.type` | `config('eta.issuer.type')` = `ETA_ISSUER_TYPE` (default `B` = business) |
| `issuer.id` | `config('eta.issuer.tax_registration_number')` = `ETA_ISSUER_TRN` (default placeholder `100000000`) |
| `issuer.name` | `config('eta.issuer.name')` = `ETA_ISSUER_NAME` (default `Atriom Demo Operator`) |
| `receiver.address` | **Hard-coded** `country=EG`, `governate=Giza`, `regionCity=6th of October City`, `buildingNumber=1`, `street = tenant->address ?? 'N/A'` (`EtaJsonBuilder.php:44-50`) |
| `receiver.type` | `mapTenantType(tenant->type)` — see [tenant-type mapping](#tenant-type-mapping-receivertype) |
| `receiver.id` | `tenant->tax_id ?? '000000000'` |
| `receiver.name` | `tenant->legal_name ?? tenant->name ?? 'Unknown'` |
| `documentType` | `'i'` (invoice) |
| `documentTypeVersion` | `'1.0'` |
| `dateTimeIssued` | `invoice->issue_date->toIso8601String()` |
| `taxpayerActivityCode` | **Hard-coded** `'6820'` — "Renting and operating of own or leased real estate" |
| `internalID` | `invoice->number` (our human invoice number — this is what ETA echoes back per-document) |
| `invoiceLines` | one entry per `invoice->items` — see [line build](#3-the-line-build-buildlines) |
| `totalDiscountAmount` | `0.0` (we never discount at the document level) |
| `totalSalesAmount` | `round(invoice->subtotal, 5)` |
| `netAmount` | `round(invoice->subtotal, 5)` (equals `totalSalesAmount`; no document-level discount) |
| `taxTotals` | `buildTaxTotals(invoice)` — see [tax totals](#4-tax-totals-buildtaxtotals) |
| `totalAmount` | `round(invoice->total, 5)` |
| `extraDiscountAmount` | `0.0` |
| `totalItemsDiscountAmount` | `0.0` |

`invoice->subtotal`, `invoice->vat_amount`, and `invoice->total` are the **persisted, authoritative** money columns maintained by `Invoice::recomputeTotals()` (the single source of truth — see [00-money-model.md](00-money-model.md)). The builder **reads** them; it never recomputes money. The VAT split (14% on service charges, base rent VAT-exempt, 5% marketing levy) lives upstream in the billing engine — see [02-vat-and-tax.md](02-vat-and-tax.md) and [03-marketing-levy.md](03-marketing-levy.md).

#### Business-receiver tax_id guard (fail-fast)

Before building, if the receiver maps to type `B` (business) **and** the tenant has no `tax_id`, the builder throws a `RuntimeException` with a human message naming the tenant and invoice (`EtaJsonBuilder.php:30-34`):

```
Tenant '{name}' (id={id}) is a business but has no tax_id — ETA submission
requires one. Add the tax registration number on the tenant record before
submitting invoice {number}.
```

This is caught downstream by `EtaSubmissionService` (see [rejection handling](#5-outcome-mapping--rejection-handling)) and turns into a clear `rejected` status instead of an opaque ETA rejection later. Only **businesses** need a tax id; individuals (type `P`) submit with the `'000000000'` placeholder receiver id.

#### Tenant-type mapping (`receiver.type`)

`mapTenantType()` (`EtaJsonBuilder.php:119-127`) maps **our** tenant `type` to ETA's receiver type code:

| Our `tenant.type` | ETA `receiver.type` |
|---|---|
| `'individual'` | `P` (person) |
| `'foreign'` | `F` (foreigner) |
| anything else (incl. `'company'`, `null`) | `B` (business) — the **default** |

> **Gotcha / known mismatch:** the `tenants.type` column enum is **only `['individual', 'company']`** (`2024_01_01_000003_create_tenants_table.php:15`, default `company`). The mapping's `'foreign' => 'F'` branch is therefore **dead in practice** — no tenant can currently hold `type='foreign'`, so `F` is never emitted. `'company'` (the default tenant type) falls through to `B`. This is harmless but worth knowing: practically every receiver is `B`, and every `B` tenant must carry a `tax_id`.

### 3. The line build (`buildLines`)

One ETA `invoiceLine` per `InvoiceItem` (`EtaJsonBuilder.php:71-104`). Per item:

| Line field | Value / source |
|---|---|
| `description` | `item->description` |
| `itemType` | `'EGS'` (Egyptian Goods/Services classification) |
| `itemCode` | `mapItemCode(item->charge?->type)` — config-driven, see below |
| `unitType` | `'EA'` (each) |
| `quantity` | `1.0` (always — our items are lump amounts, not unit×qty) |
| `internalCode` | `item->charge?->type ?? 'other'` |
| `salesTotal` | `round(item->amount, 5)` (pre-VAT) |
| `total` | `round(item->amount + item->vat_amount, 5)` (VAT-inclusive line total) |
| `valueDifference` | `0.0` |
| `totalTaxableFees` | `0.0` |
| `netTotal` | `round(item->amount, 5)` |
| `itemsDiscount` | `0.0` |
| `unitValue.currencySold` | `'EGP'` |
| `unitValue.amountEGP` | `round(item->amount, 5)` |
| `discount` | `{rate: 0.0, amount: 0.0}` |
| `taxableItems` | If `item->vat_rate > 0`: a single entry `{taxType:'T1', amount: round(vat_amount,5), subType:'V009', rate: vat_rate}`. If `vat_rate <= 0`: **empty array `[]`** (VAT-exempt lines like base rent carry no taxable item). |

`item->amount`, `item->vat_rate`, `item->vat_amount` are read from the invoice item. Note `InvoiceItem` self-maintains `vat_amount = round(amount * vat_rate / 100, 2)` and `total = round(amount + vat_amount, 2)` on save (`InvoiceItem.php:33-38`), so the builder is consuming already-consistent numbers.

#### Tax codes used

- **`taxType = 'T1'`** — ETA's tax type for **VAT** (used on both the line `taxableItems` and the document `taxTotals`).
- **`subType = 'V009'`** — the VAT sub-type code emitted on every taxable line.
- **`itemType = 'EGS'`** — every line is classified as an Egyptian Good/Service.

#### EGS item codes — config-driven (`mapItemCode`)

`mapItemCode()` (`EtaJsonBuilder.php:129-136`) looks up the **charge type** in `config('eta.egs_codes')` and falls back through `['default']` → the literal `'EG-6820-999'`:

```
$codes[$chargeType] ?? $codes['default'] ?? 'EG-6820-999'
```

The codes are env-overridable so the operator's **real registered EGS/GS1 codes** drop in without a code change (`config/eta.php:54-61`):

| Charge type | Config key / env | Default placeholder |
|---|---|---|
| `base_rent` | `ETA_EGS_BASE_RENT` | `EG-6820-001` |
| `service_charge` | `ETA_EGS_SERVICE_CHARGE` | `EG-6820-002` |
| `utility` | `ETA_EGS_UTILITY` | `EG-3530-001` |
| `parking` | `ETA_EGS_PARKING` | `EG-5221-001` |
| `percentage_rent` | `ETA_EGS_PERCENTAGE_RENT` | `EG-6820-003` |
| `default` (covers `marketing`, `other`, unmapped) | `ETA_EGS_DEFAULT` | `EG-6820-999` |

> The valid charge types are `base_rent, service_charge, utility, parking, percentage_rent, marketing, other` (`2026_06_24_000005_add_marketing_to_charges_type.php`). Note `marketing` has **no dedicated EGS key** — it falls to `default`. The lookup keys off `item->charge?->type` (the related `Charge` record's type via `charge_id`), **not** the `InvoiceItem.type` column. If an item has no linked charge, `charge?->type` is `null`, `internalCode` becomes `'other'`, and `itemCode` becomes the `default` code.

### 4. Tax totals (`buildTaxTotals`)

`buildTaxTotals()` (`EtaJsonBuilder.php:106-117`):

- If `invoice->vat_amount <= 0` → returns **empty array `[]`** (a fully VAT-exempt invoice, e.g. base-rent-only, carries no document tax total).
- Otherwise → a single entry: `{taxType: 'T1', amount: round(invoice->vat_amount, 5)}`.

### 5. Outcome mapping & rejection handling

`EtaSubmissionService::submit()` (`EtaSubmissionService.php:23-87`) reads `response['acceptedDocuments'][0]` and `response['rejectedDocuments'][0]` and stamps the invoice:

| Response shape | `eta_status` written | Other fields written |
|---|---|---|
| **Throwable** during build or submit (transport/auth/missing-tax_id) | `'rejected'` | `eta_response = {error: <message>}`, `eta_submitted_at = now()`. Logs `eta.submission_failed` (error). **Only the message is stored — never the document JSON** (it carries tenant tax id / name / address). (`EtaSubmissionService.php:34-49`) |
| `acceptedDocuments[0]` present | `strtolower(accepted.documentStatus ?? 'submitted')` — typically `'valid'` | `eta_submission_id = response.submissionId ?? accepted.uuid`, `eta_long_id = accepted.longId`, `eta_submitted_at = now()`, `eta_response = <full response>`. Logs `eta.submission_accepted` (info). (`EtaSubmissionService.php:54-66`) |
| `rejectedDocuments[0]` present (no accepted) | `'rejected'` | `eta_submitted_at = now()`, `eta_response = <full response>`. Logs `eta.submission_rejected` (warning) with reason `rejected.error ?? rejected.documentStatus ?? 'rejected'`. (`EtaSubmissionService.php:67-76`) |
| Neither accepted nor rejected (ambiguous) | `'submitted'` | `eta_submitted_at = now()`, `eta_response = <full response>`. No ops-log line. (`EtaSubmissionService.php:77-83`) |

The method always returns `$invoice->refresh()`. **All ops logging goes through `App\Support\OpsLog`** to the dedicated ops channel (do not log the document body).

### 6. The submit path (`EtaApiClient`) — mock vs real

`EtaApiClient::submitDocument()` (`EtaApiClient.php:33-40`) branches on `isMock()` (`config('eta.mock', true)`).

**Mock mode (`ETA_MOCK=true`, the default)** — `mockResponse()` (`EtaApiClient.php:74-93`):
- Returns a **deterministic accepted** shape: `status='success'`, `documentStatus='Valid'`, `submissionId='MOCK-'+random(20)`, a random 32-char `longId`, one `acceptedDocuments` entry echoing `internalID` with a fresh `uuid` + `longId` + `dateTimeReceived=now()`, empty `rejectedDocuments`, and **`mock => true`** so the response is unmistakably synthetic in `eta_response`.
- **No network call, no OAuth, no signing.** Nothing reaches ETA.

**Real mode (`ETA_MOCK=false`)** — `realResponse()` (`EtaApiClient.php:95-120`):
1. **Signing safety gate.** If `config('eta.signing.enabled')` is true **but** the bound signer's `isSigning()` is false (i.e. only the passthrough `UnsignedEtaSigner` is bound), it throws a `RuntimeException` and refuses to submit (`EtaApiClient.php:99-104`). This makes it **impossible to submit an unsigned document while pretending it's compliant.**
2. **OAuth.** `fetchAccessToken()` (`EtaApiClient.php:122-132`) POSTs `grant_type=client_credentials`, `client_id`, `client_secret`, `scope=InvoicingAPI` to `config('eta.auth_endpoint')` and reads `access_token`.
3. **Sign.** `$document = $this->signer->sign($documentJson)` — the passthrough returns it unchanged; a real CAdES signer would add a `signatures` array.
4. **Submit.** `Http::withToken($token)->acceptJson()->post(config('eta.endpoint').'/api/v1/documentsubmissions', ['documents' => [$document]])`.
5. **Response.** Returns `response->json()`, or — on an empty body — a fallback `{status:'error', message:'Empty response from ETA', httpStatus: <code>}` (`EtaApiClient.php:115-119`).

### 7. The pluggable signing seam

ETA **production rejects unsigned B2B documents** — they must carry a **CAdES-BES** digital signature produced from the operator's certificate (typically on a USB/HSM token or cloud key vault — an external dependency the operator provisions).

- **Contract:** `App\Services\Eta\Signing\EtaDocumentSigner` — `sign(array $documentJson): array` and `isSigning(): bool` (`EtaDocumentSigner.php`).
- **Default binding:** `UnsignedEtaSigner` — a **no-op passthrough**: `sign()` returns the document unchanged, `isSigning()` returns `false` (`UnsignedEtaSigner.php`). Bound in `AppServiceProvider::register()` (`AppServiceProvider.php:36`): `$this->app->bind(EtaDocumentSigner::class, UnsignedEtaSigner::class);`.
- **Going live:** provision the certificate, implement a real CAdES `EtaDocumentSigner`, set `ETA_SIGNING_ENABLED=true`, and **rebind it in `AppServiceProvider`**. The rest of the pipeline is unchanged. The safety gate in step 1 above prevents `enabled-but-unsigned` from ever reaching ETA. (This is the same pluggable-integration pattern as `PaymobClient` and `PushSender`.)

### 8. The queued job — retries & backoff

`SubmitInvoiceToEta` (`app/Jobs/SubmitInvoiceToEta.php`) is the async wrapper (currently dispatched only by tests). It exists to make submission queue-safe with bounded, backed-off retries:

- `public int $tries = 3;` — 1 initial attempt + 2 retries (instead of the worker's default back-to-back 3). Rationale (audit M08 F-34 / D-25): the default would hammer ETA's OAuth endpoint within seconds, and each retry would overwrite `eta_response` with a fresh error, losing the diagnostic trail.
- `backoff(): [60, 300, 900]` — waits **60s → 300s (5 min) → 900s (15 min)** between attempts. Rides out short ETA outages without re-storming auth, and gives an operator time to fix a missing `tax_id` between attempts.
- `failed(?Throwable $e)` — when all retries are exhausted, logs `eta.job_exhausted` (error) with the invoice id + number + error, and the job lands in `failed_jobs`. A tax submission has NOT gone through, so it is surfaced loudly.

### 9. The integrations preflight (`integrations:check`)

`php artisan integrations:check [--eta] [--paymob]` (`CheckIntegrationsCommand.php`) is a **non-destructive** credentials/connectivity check — it **never submits a document.** For ETA it calls `EtaApiClient::verifyCredentials()` (`EtaApiClient.php:48-67`):

- **Mock mode** → `{ok:true, mode:'mock', message:'ETA is in MOCK mode (ETA_MOCK=true) — not contacting the real tax authority.'}`.
- **Real mode, missing `client_id`/`client_secret`** → `{ok:false, ...}`.
- **Real mode** → attempts **OAuth token grant only**; reports whether a token was acquired from `auth_endpoint`.

The command additionally warns, when `mode=real` and `eta.signing.enabled` is false, that "*Document signing is OFF — ETA production rejects unsigned B2B documents. OK for preprod plumbing only.*" Run it the moment new credentials are pasted.

---

## Worked example(s) with REAL NUMBERS

### Example A — typical mixed invoice, mock mode

A shop's monthly invoice (the canonical billing shape from [01-billing-monthly.md](01-billing-monthly.md)):

| Item | charge type | amount (EGP) | vat_rate | vat_amount | line total |
|---|---|---|---:|---:|---:|
| Base rent | `base_rent` | 50,000.00 | 0% (exempt) | 0.00 | 50,000.00 |
| Service charge | `service_charge` | 10,000.00 | 14% | 1,400.00 | 11,400.00 |
| Marketing levy (5% of rent) | `marketing` | 2,500.00 | 0% | 0.00 | 2,500.00 |

Invoice persisted totals (`recomputeTotals`): `subtotal = 62,500.00`, `vat_amount = 1,400.00`, `total = 63,900.00`.

Tenant: `type='company'` → receiver type **`B`**; has `tax_id='123-456-789'`.

**Document built** (`EtaJsonBuilder`):
- `issuer` from config (placeholders unless env set): `id=100000000`, `type=B`, `name='Atriom Demo Operator'`.
- `receiver`: `type=B`, `id='123-456-789'`, `name = legal_name ?? name`, hard-coded Giza/6th-of-October address.
- `taxpayerActivityCode='6820'`, `documentType='i'`, `documentTypeVersion='1.0'`, `internalID='<invoice number>'`.
- `totalSalesAmount = netAmount = 62500.0`, `totalAmount = 63900.0`.
- `taxTotals = [{taxType:'T1', amount: 1400.0}]` (vat_amount > 0).
- **Three lines:**
  - Base rent: `itemCode='EG-6820-001'`, `internalCode='base_rent'`, `salesTotal=50000.0`, `total=50000.0`, `taxableItems=[]` (rate 0).
  - Service charge: `itemCode='EG-6820-002'`, `internalCode='service_charge'`, `salesTotal=10000.0`, `total=11400.0`, `taxableItems=[{taxType:'T1', amount:1400.0, subType:'V009', rate:14}]`.
  - Marketing: `itemCode='EG-6820-999'` (no dedicated key → default), `internalCode='marketing'`, `salesTotal=2500.0`, `total=2500.0`, `taxableItems=[]`.

**Submitted (mock):** `EtaApiClient::mockResponse()` returns `documentStatus='Valid'`, `submissionId='MOCK-XXXX…'`, a 32-char `longId`, `mock=true`.

**Stamped on the invoice:** `eta_status='valid'`, `eta_submission_id='MOCK-XXXX…'`, `eta_long_id='<random32>'`, `eta_submitted_at=now()`, `eta_response=<full mock response incl. mock:true>`. Dashboard counts it under **Valid**. Re-submitting it is now a no-op.

### Example B — business tenant with no tax_id

Same invoice, but the tenant is `type='company'` with `tax_id = null`. `EtaJsonBuilder::build()` throws **before** any HTTP call. `EtaSubmissionService` catches it: `eta_status='rejected'`, `eta_response={error: "Tenant '…' (id=…) is a business but has no tax_id — …"}`, `eta_submitted_at=now()`, ops-log `eta.submission_failed`. The operator sees the exact fix (add the tenant's tax id) and re-submits.

### Example C — VAT-exempt invoice (base rent only)

A single `base_rent` item of 50,000 EGP, 0% VAT. `subtotal=50,000`, `vat_amount=0`, `total=50,000`. The document has `taxTotals=[]` and the single line's `taxableItems=[]`. Everything else is identical. In mock mode it returns `Valid`.

### Example D — real mode, ETA rejects (live path)

`ETA_MOCK=false`, credentials set. `EtaApiClient` fetches an OAuth token, signs (passthrough or real), POSTs to `/api/v1/documentsubmissions`. ETA replies with `acceptedDocuments=[]` and `rejectedDocuments=[{internalId:'INV-…', error:'…schema/registration error…'}]`. `EtaSubmissionService` writes `eta_status='rejected'`, stores the **full** response in `eta_response`, ops-logs `eta.submission_rejected` with the reason. The invoice surfaces in the dashboard "Rejected" tile and the **"Needs ETA attention"** filter; the operator fixes the cause and re-submits.

---

## Every edge case + how the system handles it

| Edge case | Behaviour |
|---|---|
| Invoice already `eta_status='valid'` | **No-op.** Service returns immediately (`EtaSubmissionService.php:25`); bulk action counts it as "skipped". |
| Business tenant missing `tax_id` | Builder throws fail-fast; caught → `rejected` with a clear human message. Never POSTs. |
| Individual tenant (`type='individual'`) with no `tax_id` | Allowed — receiver type `P`, `receiver.id='000000000'` placeholder. No exception. |
| Tenant `type='company'` (the default) | Maps to `B` and **requires** `tax_id`. |
| Tenant `type='foreign'` | Would map to `F`, but the `tenants.type` enum has no `'foreign'` value — currently unreachable. |
| Fully VAT-exempt invoice (`vat_amount<=0`) | `taxTotals=[]`; each exempt line has `taxableItems=[]`. Valid document. |
| Item with no linked `Charge` | `internalCode='other'`, `itemCode=default` (`EG-6820-999`). No crash. |
| Charge type with no dedicated EGS key (`marketing`, `other`) | Falls to `egs_codes['default']` → literal `EG-6820-999`. |
| Mock mode | Deterministic `Valid`, `mock:true` in response, no network/OAuth/signing. |
| Real mode, empty/500 ETA response | `realResponse()` returns `{status:'error', message:'Empty response from ETA', httpStatus:<code>}`; service stores it as `eta_status='submitted'` (no accepted/rejected docs present). |
| Real mode, transport/auth throwable | Caught by service → `rejected`, `eta_response={error:<msg>}`, message only (no document body). |
| Response has neither accepted nor rejected docs | Ambiguous → `eta_status='submitted'` (in-flight; resubmittable). |
| `ETA_SIGNING_ENABLED=true` but only `UnsignedEtaSigner` bound | `realResponse()` throws immediately — **refuses to submit an unsigned doc**. Caught → `rejected` with the binding instructions. |
| Module `eta` turned off (`Modules::enabled('eta')` false) | "Submit to ETA" action + bulk action hidden; `EtaCompliance` dashboard widget hidden. |
| User lacks `invoices.submit_to_eta` permission | Action hidden (`InvoicesTable.php:266,308`). |
| Invoice status `draft` / `cancelled` | Single action hidden (only `issued/partially_paid/paid/overdue` qualify). Dashboard compliance counts exclude drafts & cancelled. |
| Queued job exhausts all 3 attempts | `failed()` ops-logs `eta.job_exhausted` (error) + lands in `failed_jobs`. (Only relevant once the job is dispatched.) |
| Credit note / partial credit changes the balance after submission | ETA submission captures the invoice **as issued** (subtotal/vat/total). It is **not** re-driven by credit-note application. A credited/reversed invoice is handled in AR, not by re-filing — see [07-credit-notes.md](07-credit-notes.md). |

---

## Invariants + gotchas

- **The builder never computes money.** It reads `invoice->subtotal`, `invoice->vat_amount`, `invoice->total`, and the per-item `amount`/`vat_rate`/`vat_amount` — all maintained upstream by `Invoice::recomputeTotals()` and `InvoiceItem` save hooks. ETA is a **read-only consumer** of the money model. Never let it become a second source of truth.
- **Idempotent on `valid`.** Submission is safe to re-run; an accepted invoice is never re-filed. The whole stamp is transactional.
- **Never log the document body.** It carries the tenant's tax id, legal name, and address. The service logs **messages only** (`OpsLog`), and on a throwable stores `{error: <message>}` in `eta_response`, not the document.
- **Unsigned-but-pretending is impossible.** With `ETA_SIGNING_ENABLED=true` and only the passthrough bound, `realResponse()` throws before POSTing. Production must bind a real CAdES signer.
- **Mock is not certification.** `mock:true` in `eta_response` and `submissionId` starting `MOCK-` mean the document **never reached ETA**. Do not treat mock `valid` as a legal e-invoice.
- **Submission is operator-driven, not automatic.** Issuing/paying an invoice does not file it. The `SubmitInvoiceToEta` job is wired for async/retry but is **not dispatched** in the current code.
- **Placeholders everywhere until the taxpayer profile lands:** issuer TRN (`100000000`), issuer name (`Atriom Demo Operator`), and all six EGS codes (`EG-…`) are env-overridable placeholders. The receiver address is **hard-coded** to a 6th-of-October/Giza address regardless of the tenant — only `street` comes from the tenant. These must be corrected before go-live.
- **`internalID` = our invoice number.** ETA echoes it per-document; it is how we correlate `acceptedDocuments[0].internalId` back to the invoice.
- **Rounding:** the ETA document rounds money to **5 decimals** (`round($v, 5)`) — ETA's schema precision — whereas our money columns are `decimal:2`. The 5-dp rounding on already-2-dp values is a no-op in practice but matches the spec's field precision.
- **`internalCode` vs `itemCode` source:** both derive from `item->charge?->type` (the linked `Charge`), **not** the `InvoiceItem.type` column. If `charge_id` is null these fall back to `'other'`/default code.
- **`scope=InvoicingAPI`** and **`grant_type=client_credentials`** are fixed in the OAuth call. Endpoints default to ETA **preproduction** (`api.preprod.invoicing.eta.gov.eg`, `id.preprod.eta.gov.eg`) — change `ETA_ENDPOINT`/`ETA_AUTH_ENDPOINT` for production.

---

## What's certified vs not (read this before go-live)

| Area | State | What it means |
|---|---|---|
| **Mode** | **MOCK by default** (`ETA_MOCK=true`) | Submissions are synthetic; nothing reaches ETA. |
| **Real OAuth + submit path** | Coded + unit-tested against an HTTP fake (`EtaIntegrationTest.php:46`), **never run against ETA production** | Plumbing exists; not certified. |
| **Document signing** | **No-op passthrough** (`UnsignedEtaSigner`); `ETA_SIGNING_ENABLED=false` | ETA production rejects unsigned B2B docs. A real CAdES signer + certificate must be provisioned and bound. |
| **Issuer identity** | Placeholder TRN/name | Replace via `ETA_ISSUER_*` once the commercial register / TIN is finalized. |
| **EGS item codes** | Placeholder `EG-…` codes | Replace via `ETA_EGS_*` with the operator's real registered codes. |
| **Receiver address** | Hard-coded Giza / 6th-of-October | Only `street` is tenant-driven; the rest is a stand-in. |
| **Credentials** | `ETA_CLIENT_ID`/`ETA_CLIENT_SECRET` unset | Provision ETA preprod, then production credentials. |

**Go-live checklist (env + binding):** set real `ETA_CLIENT_ID`/`ETA_CLIENT_SECRET`; point `ETA_ENDPOINT`/`ETA_AUTH_ENDPOINT` at the target environment; set `ETA_ISSUER_*` and all `ETA_EGS_*`; implement + bind a CAdES `EtaDocumentSigner` in `AppServiceProvider` and set `ETA_SIGNING_ENABLED=true`; set `ETA_MOCK=false`; run `php artisan integrations:check --eta` to confirm OAuth before submitting a single real document.

---

## Where it lives in the code (file:line index)

| What | File:line |
|---|---|
| Config — flags, endpoints, issuer, EGS codes, signing | `config/eta.php` (whole file) |
| `mock` / `enabled` flags | `config/eta.php:22-23` |
| Issuer identity (`ETA_ISSUER_*`) | `config/eta.php:35-46` |
| EGS item codes (`ETA_EGS_*`) | `config/eta.php:54-61` |
| Signing config (`ETA_SIGNING_ENABLED`, cert/key paths) | `config/eta.php:70-74` |
| **JSON builder** — document + lines + tax | `app/Services/Eta/EtaJsonBuilder.php` |
| Business-tenant tax_id fail-fast | `EtaJsonBuilder.php:30-34` |
| Top-level document fields | `EtaJsonBuilder.php:36-68` |
| Line build (`itemType=EGS`, `T1`/`V009`) | `EtaJsonBuilder.php:71-104` |
| Tax totals | `EtaJsonBuilder.php:106-117` |
| Tenant-type → receiver-type map | `EtaJsonBuilder.php:119-127` |
| EGS item-code lookup | `EtaJsonBuilder.php:129-136` |
| **HTTP client** — mock vs real | `app/Services/Eta/EtaApiClient.php` |
| Mock response | `EtaApiClient.php:74-93` |
| Real response (signing gate, OAuth, sign, POST) | `EtaApiClient.php:95-120` |
| OAuth token grant | `EtaApiClient.php:122-132` |
| Credentials preflight (`verifyCredentials`) | `EtaApiClient.php:48-67` |
| **Submission orchestrator** — outcome mapping | `app/Services/Eta/EtaSubmissionService.php` |
| Idempotency guard (`valid` no-op) | `EtaSubmissionService.php:25` |
| Throwable → rejected (message-only) | `EtaSubmissionService.php:34-49` |
| Accepted / rejected / fallback stamping | `EtaSubmissionService.php:54-83` |
| **Signing seam** — interface | `app/Services/Eta/Signing/EtaDocumentSigner.php` |
| Default passthrough signer | `app/Services/Eta/Signing/UnsignedEtaSigner.php` |
| Signer binding | `app/Providers/AppServiceProvider.php:36` |
| **Queued job** — tries/backoff/failed | `app/Jobs/SubmitInvoiceToEta.php` |
| **Filament action** — single "Submit to ETA" | `app/Filament/Admin/Resources/Invoices/Tables/InvoicesTable.php:262-282` |
| **Filament action** — bulk submit | `InvoicesTable.php:304-333` |
| ETA filters (`eta_status`, needs-attention, pending) | `InvoicesTable.php:179-191` |
| **Dashboard widget** — compliance posture | `app/Filament/Admin/Widgets/EtaCompliance.php` |
| **Preflight command** — `integrations:check` | `app/Console/Commands/CheckIntegrationsCommand.php` |
| Permission `invoices.submit_to_eta` | `database/seeders/RolesPermissionsSeeder.php:80,262` |
| Module toggle key `eta` | `app/Support/Modules.php:33` |
| Invoice ETA columns (`eta_submission_id`, `eta_submitted_at`, `eta_response`) | `database/migrations/2024_01_01_000006_create_invoices_table.php:36-38` |
| Invoice ETA columns (`eta_status` enum, `eta_long_id`, index) | `database/migrations/2026_05_23_172154_add_eta_status_to_invoices_table.php` |
| Invoice ETA fillable + casts (`eta_response`→array, `eta_submitted_at`→datetime) | `app/Models/Invoice.php:44-66` |
| `Invoice::recomputeTotals()` (money source of truth) | `app/Models/Invoice.php:255` |
| Tenant `type` enum (`individual`/`company`) | `database/migrations/2024_01_01_000003_create_tenants_table.php:15` |
| Charge `type` enum (incl. `marketing`) | `database/migrations/2026_06_24_000005_add_marketing_to_charges_type.php` |
| Tests — mock, real (HTTP fake), service outcomes, job | `tests/Feature/Services/EtaIntegrationTest.php` |

---

## Related

- [00-money-model.md](00-money-model.md) — the AR / totals model ETA reads from (`subtotal` / `vat_amount` / `total`).
- [01-billing-monthly.md](01-billing-monthly.md) — how the invoices ETA files are generated each month.
- [02-vat-and-tax.md](02-vat-and-tax.md) — the 14% VAT on service charges, base-rent exemption (drives the `T1`/`V009` line tax + `taxTotals`).
- [03-marketing-levy.md](03-marketing-levy.md) — the 5%-of-rent marketing levy line (EGS `default` code).
- [04-cam-reconciliation.md](04-cam-reconciliation.md) — CAM true-up charges that may appear as invoice lines.
- [05-percentage-rent.md](05-percentage-rent.md) — percentage-rent charges (EGS `EG-6820-003`).
- [06-payments.md](06-payments.md) — payment capture (independent of ETA; affects AR, not the filed document).
- [07-credit-notes.md](07-credit-notes.md) — credit notes settle AR after the invoice is filed; they do not re-drive ETA submission.
