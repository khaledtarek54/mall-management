# ETA E-Invoicing

> **🧊 FROZEN (2026-08-22): the `eta` module is switched off IN CODE and is not shown anywhere.**
> Registered in `App\Support\Modules::FROZEN`, so `Modules::enabled('eta')` answers **false before
> the settings row is consulted** — a stale row, a restored backup or a hand-edited `settings` table
> cannot bring it back, and there is no operator toggle for it any more.
>
> **Why this replaced the 2026-07-03 "postponed" state.** `modules.eta` had defaulted to false since
> then, and ETA was still *present* everywhere an operator looks: an "ETA e-Invoicing" tab on
> `/admin/settings` with two `->required()` fields that nothing read; a Modules toggle inviting them
> to switch it on; an **"ETA Status" column on every invoice list** (the one surface that was never
> module-gated); *"Submit invoices to the Egyptian Tax Authority"* as a grantable right on the roles
> matrix; an ETA reference block on the invoice PDF printing a **mock** submission id onto a document
> a tenant files with their own accountant; three `eta_*` keys in the mobile API payload; and ~55
> seeded "Valid" badges in the demo data. Off and unfinished looked identical, and the difference was
> presented as the operator's to decide.
>
> **What was removed, not just gated:**
> - `App\Settings\EtaSettings` — all four properties were inert (the pipeline reads `config('eta.*')`
>   from env, never these rows). The surviving home for the issuer identity is `TaxSettings`;
>   `EtaJsonBuilder` should build its issuer block from there when the work resumes.
> - The `invoices.submit_to_eta` permission (migration `2026_08_22_810000`), because the roles matrix
>   renders a checkbox per catalogue row.
> - The three `eta_*` keys from `Api\V1\InvoiceResource` — removed rather than runtime-gated,
>   because `docs/api/openapi.json` is GENERATED from that method and both gated forms corrupt it
>   (a conditional spread becomes a property with an empty name inside an `anyOf`; a post-return
>   `if` becomes three *required* keys the endpoint never sends). A generated spec must describe
>   what the endpoint returns. Recorded as breaking in [MOBILE-API.md](../api/MOBILE-API.md).
> - `DemoSeeder::seedEtaSubmissions()`, the ETA claims on the marketing landing page, the orphaned
>   `admin.settings.*.eta_*` / `admin.notifications.eta_submitted*` / `bulk_eta_complete*` lang keys
>   in both locales. (`tests/e2e/13-eta.spec.js` was deleted with the freeze — see below.)
>
> **What was kept, dormant and covered:** `app/Services/Eta/*`, `App\Jobs\SubmitInvoiceToEta`,
> `config/eta.php`, the `invoices.eta_*` columns, the `EtaCompliance` widget's registration on the
> accounting dashboard, and every service-level test (`EtaScenarioTest`, `EtaJsonBuilderTest`,
> `EtaIntegrationTest`, `EtaReceiverAddressTest`, `EtaRetryPolicyTest`, `EtaJsonBuilderTaxIdTest`) —
> none of which asks `Modules::enabled()`, so the dormant code stays honest. The two suites that
> drove the UI are `->skip()`ed with the reason.
>
> **To unfreeze**, in order: delete the `eta` entry from `Modules::FROZEN`; restore the
> `invoices.submit_to_eta` line in `RolesPermissionsSeeder` **and re-run the seeder** (a permission
> that exists only in the seeder file is invisible to a green suite); re-add the three `eta_*` keys
> to `Api\V1\InvoiceResource` and run `composer api-spec`; point `EtaJsonBuilder`'s issuer block at
> `TaxSettings` instead of the deleted `EtaSettings`; and drop the `->skip()`s on the two UI suites. The invariant is pinned
> the other way round by `tests/Feature/Regression/EtaIsFrozenAndInvisibleTest.php`, which will start
> failing — that is the signal the freeze is actually lifted, not a regression.

> Submits B2B invoices to the Egyptian Tax Authority (ETA) preproduction e-invoicing API, capturing acceptance/rejection status and integrating with the Filament admin dashboard for compliance visibility.

## 1. Purpose & business context

**Who**: Operators (Eltizam) and accounting staff manage invoice compliance with Egyptian tax regulations.

**What it models**: Egypt mandates e-invoice submission to ETA for all B2B transactions (business-to-business; Goods/Services). Invoices issued to businesses (type='company') or foreigners must be submitted in the ETA-prescribed JSON format, which includes issuer identity, receiver (tenant) details, line items with EGS codes, and tax totals. Individual tenants (type='individual') are not required for ETA submission per spec, but can be submitted as person type (P).

**Why it exists**:
- **Regulatory**: Egyptian Tax Authority requires e-invoice documentation for audit trail, VAT tracking, and business accountability.
- **Compliance posture**: Admin dashboard widget (EtaCompliance) surfaces valid/submitted/rejected/pending invoice counts so CFOs see real-time compliance status at a glance.
- **Audit trail**: Full submission responses (accepted/rejected documents, error messages) are persisted on the invoice so operators can diagnose and re-submit failed invoices.
- **Tenant safety**: ETA submission fails early if a business tenant lacks a tax_id (required by ETA), raising clear error rather than opaque API rejection.

## 2. Domain model

| Table | Model | Key columns | Meaning |
|-------|-------|-------------|---------|
| `invoices` | `Invoice` | `eta_status` (enum) | One of: `null` (pending), `submitted`, `valid`, `invalid`, `rejected`, `cancelled`. Defaults to `null` on creation. |
| | | `eta_submission_id` (string) | UUID or submission ID returned by ETA's accepted-documents response (or 'MOCK-...' in mock mode). |
| | | `eta_long_id` (string) | Long ID from accepted-documents response; used by ETA for advanced tracking. |
| | | `eta_submitted_at` (datetime) | Timestamp of the last ETA submission attempt (success or failure). |
| | | `eta_response` (json) | Full response array from ETA API or mock client: acceptedDocuments, rejectedDocuments, errors, documentStatus, etc. |
| `tenants` | `Tenant` | `tax_id` (string) | Business registration / TIN number (e.g., '111-222-333'). REQUIRED for type='company' invoices before ETA submission. |
| | | `type` (enum) | One of: 'individual', 'company', 'foreign'. Controls tenant type in ETA JSON (P/B/F). |
| | | `legal_name` (string) | Official business name for ETA receiver field; falls back to `name` if null. |
| | | `address` (string) | Tenant address for ETA receiver node. |

**Relationships**:
- `Invoice` belongsTo `Lease` (to access Unit → Asset scoping).
- `Invoice` belongsTo `Tenant` (business name, tax_id, type).
- `Invoice` hasMany `InvoiceItem` (line items aggregated into ETA document).
- `InvoiceItem` belongsTo `Charge` (to map charge type → EGS code).

**Config** (`config/eta.php`):
- `enabled` (bool): Master toggle; hides "Submit to ETA" actions if false.
- `mock` (bool): Uses deterministic mock response (default) or hits real ETA preprod endpoint.
- `endpoint`, `auth_endpoint`: ETA OAuth + API URLs (preprod by default).
- `client_id`, `client_secret`: OAuth credentials (from .env).
- `issuer`: Object with name, tax_registration_number, type, address (populates every submitted document as the seller).

## 3. Business rules & invariants

1. **Only issued or beyond invoices**: `eta_status` only appears on invoices with status IN ['issued', 'partially_paid', 'paid', 'overdue']. Draft invoices are not submitted.

2. **Business tenant tax_id validation**: If `Tenant.type === 'company'` and the invoice is being built for submission, `EtaJsonBuilder::build()` throws `RuntimeException` if `tenant.tax_id` is null. This prevents opaque ETA API errors; the operator sees: "Tenant 'Acme Co' (id=5) is a business but has no tax_id — add the tax registration number on the tenant record before submitting invoice INV-2026-0042."

3. **Tenant type mapping to ETA receiver type**:
   - `type='company'` → ETA `receiver.type='B'` (business).
   - `type='individual'` → ETA `receiver.type='P'` (person).
   - `type='foreign'` → ETA `receiver.type='F'` (foreigner).

4. **No VAT → empty taxTotals array**: If `invoice.vat_amount <= 0`, `taxTotals` is an empty array (not an array containing a zero-amount VAT line). This matches ETA's validation expectations.

5. **Idempotent submission**: If `invoice.eta_status === 'valid'`, re-submitting returns the same invoice unchanged (checked in `EtaSubmissionService::submit()` at line 24–26). No new submission ID or timestamp is generated.

6. **Rejected invoices can be re-submitted**: Once `eta_status='rejected'`, the operator can fix the underlying issue (e.g., add missing tenant tax_id) and submit again. The service does NOT skip rejected invoices; it only skips valid ones.

7. **Per-invoice isolation**: Submitting an invoice for property A does NOT modify the ETA state of property B's invoices. Each submission is scoped to a single invoice ID (test: EtaScenarioTest.php line 343–355).

8. **Rounding to 5 decimals**: All monetary values in the JSON (amounts, VAT, totals) are rounded to 5 decimal places via `EtaJsonBuilder::round()` (line 143–146). This matches ETA's precision expectations for Egyptian Pound.

9. **Activitylog exclusion**: ETA fields (eta_status, eta_submission_id, eta_response, eta_submitted_at) are NOT logged to the activity log; only Invoice.status, number, issue_date, due_date, total, paid_amount, balance, tenant_id, lease_id are tracked (Invoice model, LogOptions line 22).

## 4. Lifecycle / state machine

| Status | Meaning | Trigger | Next states |
|--------|---------|---------|-------------|
| `null` / `pending` | Invoice created, never submitted to ETA | Issued | `submitted`, `valid`, `rejected` |
| `submitted` | ETA received but hasn't validated yet (status='Submitted' from API) | EtaSubmissionService::submit() with documentStatus='Submitted' in response | `valid`, `rejected` |
| `valid` | ETA accepted and validated (documentStatus='Valid' from API) | EtaSubmissionService::submit() with documentStatus='Valid' in response | (terminal, idempotent) |
| `invalid` | ETA accepted but found validation errors (documentStatus='Invalid' from API) | EtaSubmissionService::submit() with documentStatus='Invalid' in response | `submitted`, `valid` (re-submit after fix) |
| `rejected` | ETA rejected the document (rejectedDocuments array populated) or a transport error occurred | EtaSubmissionService::submit() catches exception or finds rejectedDocuments[0] | `submitted`, `valid` (re-submit after fix) |
| `cancelled` | Operator or system cancelled the submission (future state, not yet used) | (reserved) | (reserved) |

**Transitions**:
- Unsubmitted → submitted/valid/invalid/rejected: triggered by calling `EtaSubmissionService::submit($invoice)`.
- Submitted → valid/invalid/rejected: triggered by re-submit (e.g., poll or retry).
- rejected → submitted/valid: allowed; operator fixes issue and re-submits.
- valid → (no outbound): idempotent; re-submit is a no-op.

**Terminal state**: `valid` (re-submission is skipped). All other states are transient.

## 5. Services, jobs & scheduled commands

### EtaApiClient
**Signature**: `submitDocument(array $documentJson): array`

**What it does**:
- **Mock mode** (default): Returns a deterministic "accepted" response with a MOCK- submission ID and a random longId. Useful for demos before credentials land.
- **Real mode**: Fetches an OAuth bearer token from ETA's auth endpoint, then POSTs the JSON document array to ETA's `/api/v1/documentsubmissions` endpoint. Falls back to error shape on empty/error responses.

**Idempotency**: Client is stateless. Idempotency is enforced by `EtaSubmissionService` (which skips re-submit if `eta_status='valid'`), not by the client.

**Transactions**: None (client is a thin HTTP wrapper).

**Notes**:
- Mock mode is controlled by `config('eta.mock', true)` (line 31).
- Real mode requires `ETA_CLIENT_ID`, `ETA_CLIENT_SECRET` in .env.
- Token fetch uses `grant_type=client_credentials` with scope `InvoicingAPI` (line 78–79).

### EtaJsonBuilder
**Signature**: `build(Invoice $invoice): array`

**What it does**:
1. Eager loads `['lease.tenant', 'items.charge']` to avoid N+1 queries.
2. Validates that if the tenant type is 'company' and has no tax_id, throws `RuntimeException` immediately (before any HTTP).
3. Maps invoice data to ETA's B2B invoice JSON schema (v1.0):
   - **issuer** (seller): hardcoded from `config('eta.issuer')`.
   - **receiver** (buyer): tenant name, type (B/P/F), tax_id, address.
   - **invoiceLines**: one entry per InvoiceItem, with EGS codes (e.g., 'EG-6820-001' for base_rent), VAT rates, taxableItems array.
   - **taxTotals**: one entry per VAT rate if VAT > 0, else empty.
   - **totals**: totalAmount (subtotal+VAT), netAmount (subtotal), totalSalesAmount.

**EGS code mapping** (lines 129–141):
| Charge type | EGS code |
|---|---|
| base_rent | EG-6820-001 |
| service_charge | EG-6820-002 |
| utility | EG-3530-001 |
| parking | EG-5221-001 |
| percentage_rent | EG-6820-003 |
| (default/other) | EG-6820-999 |

Codes are placeholders; once the taxpayer profile is registered with ETA, replace these with actual EGS codes.

**Idempotency**: Stateless; same invoice always produces the same JSON (idempotent).

**Transactions**: None.

**Notes**:
- Taxpayer activity code is hardcoded to '6820' (renting/operating real estate, line 58).
- **Receiver address is the TENANT's, in parts (fixed 2026-07-30).** ETA files the buyer address as
  `governate` / `regionCity` / `street` / `buildingNumber` and validates them. These used to be
  **constants** — `'Giza'`, `'6th of October City'`, building `'1'`, with the tenant's whole freeform
  address dropped into `street` — so every document filed for a tenant outside 6th of October declared
  the wrong buyer address, and the building number was wrong for all of them. Mock mode hid it: the
  fake endpoint accepts anything, so the first real filing would have been the test. `tenants` now
  carries `address_governorate` / `address_city` / `address_street` / `address_building_number`
  (additive — the freeform `address` still drives the PDF, the portal and the directory).
- Receiver tax_id defaults to '000000000' for individuals without tax_id (line 52).
- Receiver name falls back: legal_name → name → 'Unknown' (line 53).

### EtaSubmissionService
**Signature**: `submit(Invoice $invoice): Invoice`

**What it does**:
1. If `$invoice->eta_status === 'valid'`, return unchanged (idempotent).
2. Build JSON via `EtaJsonBuilder::build()`.
3. Submit via `EtaApiClient::submitDocument()`, wrapped in try/catch.
4. On success: parse acceptedDocuments[0] or rejectedDocuments[0] from response.
   - If accepted: set `eta_status = documentStatus.toLowerCase()` (valid/submitted/invalid), `eta_submission_id`, `eta_long_id`.
   - If rejected: set `eta_status = 'rejected'`.
   - If neither: set `eta_status = 'submitted'` (fallback).
5. On exception: set `eta_status = 'rejected'`, store `$e->getMessage()` in `eta_response['error']`.
6. Always update `eta_submitted_at` and persist full `eta_response`.
7. Return refreshed invoice.

**Idempotency**: Yes. Re-submitting a valid invoice is a no-op (line 24–26).

**Transactions**: Yes, all updates are wrapped in `DB::transaction()` (line 28–69).

**Notes**:
- Exception handling (line 33–41) catches any Throwable, marking the invoice rejected and storing the message.
- Idempotency check is on `eta_status === 'valid'` only; previously rejected invoices ARE re-submittable.

### SubmitInvoiceToEta (Job)
**Signature**: `handle(EtaSubmissionService $service): void`

**What it does**: Delegates to `EtaSubmissionService::submit($this->invoice)` via dependency injection.

**Queue**: Implements `ShouldQueue`; job queue should process via Laravel queue worker.

**Retries**:
- `tries = 3`: 1 initial attempt + 2 backed-off retries.
- `backoff() = [60, 300, 900]`: 1 min → 5 min → 15 min delays (lines 34–37).
- Rationale (audit M08 F-34 / D-25): Avoids hammering ETA's OAuth endpoint within seconds; allows operators time to fix missing tax_id between attempts; preserves diagnostic eta_response trail across retries.

**Notes**: Job is created manually from Filament actions (InvoicesTable.php line 254–274 for single submit, line 296–325 for bulk). Not scheduled; triggered on-demand.

## 6. Filament resources & key fields

### Admin Resource: `app/Filament/Admin/Resources/Invoices/InvoiceResource.php`
- **Scoping**: ScopesViaProperty trait (line 25) → `tenantScopeRelation = 'lease.unit'` (line 28). Multi-asset (All Properties) users see all invoices; single-property users see only their asset's invoices.
- **Model label**: Translation keys: 'admin.resources.invoice.singular' / 'admin.resources.invoice.plural'.
- **Navigation**: Accounting group, sort 1, badge shows overdue count.

### Table: `app/Filament/Admin/Resources/Invoices/Tables/InvoicesTable.php`
**ETA columns**:
- **eta_status column** (line 94–105): Displays badge with color coding:
  - `valid` → green (success).
  - `submitted` → blue (info).
  - `invalid`, `rejected` → red (danger).
  - `cancelled` → gray.
  - null/pending → gray.

**ETA filters** (lines 179–192):
- **eta_status SelectFilter** (line 179): Visible if `Modules::enabled('eta')`. Options from translation 'admin.statuses.eta'.
- **needs_eta_attention Filter** (line 183): Shows invoices with `eta_status IN ['invalid', 'rejected']`.
- **eta_pending Filter** (line 187): Shows invoices with `eta_status IS NULL OR eta_status = 'pending'`.

**ETA actions**:
1. **submitToEta (record action)** (line 254–274):
   - Label: 'admin.actions.submit_to_eta'.
   - Icon: paper-airplane.
   - Visible: if ETA enabled, `eta_status !== 'valid'`, and invoice status IN ['issued', 'partially_paid', 'paid', 'overdue'].
   - Gate: Filament action authorization (tied to permission `invoices.submit_to_eta` via RoleGatedActions trait, though not explicitly visible in table code).
   - Confirmation modal: Displays mock vs. live mode warning.
   - Action: Calls `EtaSubmissionService::submit()`, shows notification with status + submission ID.

2. **bulkSubmitToEta (toolbar action)** (line 296–325):
   - Label: 'admin.actions.bulk_submit_to_eta'.
   - Visible: if ETA enabled.
   - Action: Iterates records, skips if `eta_status === 'valid'`, calls `submit()` for others. Counts submitted + skipped, shows summary notification.

**Widget: EtaCompliance** (app/Filament/Admin/Widgets/EtaCompliance.php)
- **Purpose**: Dashboard stat tiles for compliance posture.
- **Roles**: visible to 'manager', 'viewer' (line 22–24).
- **Module**: gated by `widgetModule='eta'` (line 27–29).
- **Stats** (lines 34–80):
  1. **Valid**: green, count + percentage, links to `eta_status=valid` filtered list.
  2. **Submitted**: blue, count, links to `eta_status=submitted` filtered list.
  3. **Rejected**: red (if > 0), count, links to `needs_eta_attention` filter.
  4. **Pending**: warning (if > 0), count, links to `eta_pending` filter.
- **Query**: Scoped via `TenantScope::applyTo()` (line 38) to respect property multi-tenancy. Counts only invoices with status IN ['issued', 'partially_paid', 'paid', 'overdue'] (line 39).

### Portal / Owner Resources
- **Portal** (`app/Filament/Portal/Resources/Invoices/InvoiceResource.php`): Read-only tenant view of invoices.
- ~~**Owner**~~ — the `/owner` panel was **removed**; owners are ordinary `User` records on the admin panel, gated by the `owner` role.
- Both display `eta_status` as badge but do NOT expose submit actions (admin-only).

### Form: `app/Filament/Admin/Resources/Invoices/Schemas/InvoiceForm.php`
- No ETA-specific form fields; ETA state is read-only and managed via submission service.

### RBAC Permission
- **invoices.submit_to_eta**: Grants the ability to trigger ETA submission (single or bulk).
- **Gating**: Seeded in RolesPermissionsSeeder.php (lines 'invoices.submit_to_eta' => 'Submit invoices to the Egyptian Tax Authority').
- **Role assignment**: 
  - `accounting` department (owns invoicing) has this permission.
  - `manager` (super-role) has this permission.
  - `super_admin` (cross-department) has this permission.
  - All others (viewer, leasing, operations, marketing, hr, owner) do NOT (test: EtaScenarioTest.php line 311–335).

## 7. Notifications & integrations

**Notifications fired**:
1. **admin.notifications.eta_submitted** (Filament, InvoicesTable.php line 265–273):
   - Title: 'admin.notifications.eta_submitted'.
   - Body: Shows status (valid/submitted/rejected) + submission ID.
   - Color: green if valid, else yellow.
   - Triggered: After single-invoice submission action.

2. **admin.notifications.bulk_eta_complete** (Filament, InvoicesTable.php line 317–324):
   - Title: 'admin.notifications.bulk_eta_complete'.
   - Body: Shows count submitted + skipped.
   - Triggered: After bulk submission action.

**External integrations**:
- **ETA API** (preproduction): OAuth2 endpoint (`ETA_AUTH_ENDPOINT`, default https://id.preprod.eta.gov.eg/connect/token) and document submission endpoint (`ETA_ENDPOINT`, default https://api.preprod.invoicing.eta.gov.eg).
- **No integration with Paymob, email, SMS, or other platforms**: ETA submission is standalone.

**No scheduled commands**: Submissions are triggered on-demand by Filament actions or queued jobs.

## 8. Extension points — how to change/extend SAFELY

### Add a new charge type (e.g., 'maintenance_fee')
1. **Add to Charge.type enum** (database migration): Update invoices → invoice_items → type enum.
2. **Map to EGS code** in `EtaJsonBuilder::mapItemCode()` (line 129–141):
   ```php
   'maintenance_fee' => 'EG-6820-004',  // or appropriate code
   ```
3. **Register EGS code with ETA**: Once registered in the taxpayer profile, update the hardcoded mapping.
4. **Add test** in EtaScenarioTest.php to verify the code appears in the output document.

### Change the issuer (operator) identity
1. **Update .env**: Set `ETA_ISSUER_TRN`, `ETA_ISSUER_NAME`, `ETA_ISSUER_TYPE`, `ETA_ISSUER_COUNTRY`, `ETA_ISSUER_GOVERNATE`, `ETA_ISSUER_CITY`, `ETA_ISSUER_STREET`, `ETA_ISSUER_BUILDING`.
2. **Verify in config/eta.php**: The issuer block reads from these env vars (lines 35–46).
3. **No code changes needed**: EtaJsonBuilder uses the config at runtime.

### Switch from mock to live ETA
1. **Obtain credentials**: Get `ETA_CLIENT_ID` and `ETA_CLIENT_SECRET` from ETA's registration process.
2. **Set .env**: 
   ```
   ETA_MOCK=false
   ETA_CLIENT_ID=your-client-id
   ETA_CLIENT_SECRET=your-client-secret
   ```
3. **Test**: Submit an invoice from the admin table (action will POST to the real preprod endpoint).
4. **Monitor eta_response**: Check the stored response for acceptance/rejection codes.

### Three refusals, not three guesses

`EtaJsonBuilder` refuses to build a document rather than filing invented data. Filing a guess on a
legal tax document is worse than not filing:

| Refused when | Why |
|---|---|
| A **business** tenant has no `tax_id` | ETA requires it for a `B` receiver. |
| A **business** tenant's tax address is incomplete | The parts are validated. The message names the tenant *and* which parts are missing, so it is actionable — the operator fills four fields once. |
| The invoice's **tenant is archived** | `invoices.tenant_id` is NOT NULL, but `Tenant` soft-deletes and the relation applies that scope, so `$invoice->tenant` resolves to `null`. The old code filed it anyway as buyer "Unknown", tax id `000000000` and the hardcoded address — a tax document naming a buyer that does not exist. |

Individuals (`P`) are exempt from the address requirement: they are not address-validated by ETA and
are not required to be filed at all. Their `street` falls back to the freeform address.

**Governorate is a fixed list** (`App\Support\EgyptGovernorates`, 27 entries) because "Cairo",
"cairo", "القاهرة" and "Cairo Governorate" are four spellings of one place and ETA accepts only some.
The **key** is what is filed (English, ETA's spelling); the label follows the operator's UI language.
A PHP constant rather than a settings table — the list changes when Egypt redraws its governorates,
which is legislation, not operator configuration. Nothing migrates when it does: the column is a string.

> ETA's own wire format misspells it `governate`. That is their contract — do not "fix" it.

### Retry policy (asserted since 2026-07-30)

`SubmitInvoiceToEta` carries `$tries = 3`, `backoff() = [60, 300, 900]` and a `failed()` that writes
`eta.job_exhausted` to `ops.log`. All three were chosen for stated reasons and **no test read any of
them** — a refactor could have dropped `$tries` (back to three attempts back-to-back within seconds,
hammering ETA's OAuth endpoint and overwriting `eta_response` with each fresh error) and stayed green.
Pinned by `tests/Feature/Regression/EtaRetryPolicyTest.php`, including that `handle()` lets the
exception out (otherwise the retries never count) and that `SerializesModels` re-reads the invoice on
retry — which is what lets an operator fix a rejected field between attempts, and the reason the
backoff is minutes rather than seconds.

### Customize per-tenant address in ETA documents
- Currently, `EtaJsonBuilder` uses `tenant.address` (line 48). To use a different field or derive address dynamically, edit `buildLines()` or the receiver address block (lines 43–50). Update the address derivation logic and add a test.

### Add VAT rate variants or tax type codes
- Line items now assume 'T1' tax type (VAT). To support other Egyptian tax types (e.g., 'T2' for specific services), edit `buildLines()` (line 96–101) and `buildTaxTotals()` (line 106–117) to branch on invoice item or charge tax type. Coordinate with ETA on valid tax type codes.

### Bulk re-submit all rejected invoices from a date range
- Create a console command that queries `Invoice::where('eta_status', 'rejected')->whereDate('eta_submitted_at', '>=', $from)->whereDate('eta_submitted_at', '<=', $to)` and dispatch SubmitInvoiceToEta jobs or call `EtaSubmissionService::submit()` in a loop. Add transactional safety to avoid partial failures.

### DO NOT:
- **Break the tax_id validation** (line 30–34): Removing it will allow business invoices with null tax_id to reach ETA, which will reject them opaquely. Keep the early check.
- **Change eta_status values** without migrating existing records: Add new enum values carefully and write a data migration.
- **Re-round monetary values** after building the JSON: ETA expects exactly 5 decimal places; rounding multiple times introduces drift.
- **Omit the idempotency check** in EtaSubmissionService: Removing line 24–26 will cause re-submission of valid invoices, generating duplicate submission IDs.

## 9. Gotchas, edge cases & recently-fixed bugs

1. **Tax_id validation timing**: The `RuntimeException` for missing business tax_id is thrown in `EtaJsonBuilder::build()`, which is called INSIDE `EtaSubmissionService::submit()` (line 29). If build() throws, the exception is caught (line 33) and marked as rejected (line 35). This means a missing tax_id results in `eta_status='rejected'` + the error message in `eta_response['error']`, not a 500 error. Operators can then add the tax_id and re-submit (test: EtaScenarioTest.php line 158–164).

2. **Empty taxTotals for zero-VAT invoices**: If `invoice.vat_amount <= 0`, `taxTotals` is an empty array (line 109–111), not `[{taxType: 'T1', amount: 0}]`. ETA validates that the taxTotals shape matches the line items; a mismatch causes rejection. Keep this logic tight (test: EtaScenarioTest.php line 166–179).

3. **Rounding precision**: All amounts are rounded to 5 decimals via `EtaJsonBuilder::round()` (line 143–146). This is critical because EGP (Egyptian Pound) can have fractional piasters; rounding to 2 decimals (EGP) would lose data. If a line item's vat_amount is computed as 1399.9999999 (due to float precision), rounding to 5 decimals gives 1400.0, which is correct. Rounding to 2 decimals would give 1400.00, which then JSON-encodes as 1400, losing the decimal trail. Always use `round($value, 5)` before including in JSON.

4. **Accepted but pending validation (status='Submitted')**: ETA can return `documentStatus='Submitted'` (not 'Valid'), meaning ETA accepted the document but hasn't completed validation. The submission service correctly maps this to `eta_status='submitted'` (line 50, test: EtaScenarioTest.php line 210–228). Operators should see the "Submitted" badge and may need to poll or wait for ETA to finalize.

5. **Re-submission after rejection**: Once `eta_status='rejected'`, the service does NOT skip re-submission (idempotency only applies to `eta_status='valid'`). This allows operators to fix a rejected invoice (add tax_id, fix address, etc.) and submit again. The new attempt will overwrite `eta_submission_id`, `eta_submitted_at`, and `eta_response` (test: EtaScenarioTest.php line 287–303).

6. **Mock mode vs. live mode**: Mock mode always returns a Valid response with `"mock": true` in the response (line 51). In production, this flag will be absent. Code should NOT rely on the mock flag for logic; it's purely for operator awareness in test environments.

7. **Concurrent submissions**: SubmitInvoiceToEta is a queued job with no locking. If two jobs for the same invoice are dispatched simultaneously, both will attempt submission in parallel. The database transaction in `EtaSubmissionService::submit()` (line 28) ensures last-write-wins, but the first job's response may be overwritten. If concurrency is a concern, add a `Cache::lock()` or row-level pessimistic lock before entering the transaction.

8. **ETA API outages**: If the endpoint is unreachable, `EtaApiClient::realResponse()` may timeout or return a non-JSON response. The fallback (line 65–68) returns an error shape. The exception handler in `EtaSubmissionService` (line 33–41) catches this and marks the invoice rejected. The job's retry backoff (60s, 300s, 900s) rides out short outages; if all retries exhaust, the invoice stays `eta_status='rejected'` and an operator must manually re-trigger.

9. **Tenant scoping and multi-asset**: The EtaCompliance widget uses `TenantScope::applyTo()` to filter invoices by the current asset. An operator on property A cannot see property B's ETA status. This is correct for multi-tenant security, but means the compliance dashboard is asset-specific (test: EtaScenarioTest.php line 343–355).

10. **Missing charge on invoice item**: If an invoice item has `charge_id=null`, `EtaJsonBuilder::mapItemCode()` receives `null` for charge type, which falls through to the default 'EG-6820-999'. This is safe but generic; prefer creating InvoiceItems with a Charge reference.

## 10. Tests & related modules

### Test files
- **tests/Feature/EtaJsonBuilderTest.php** (79 lines): JSON shape, tenant type mapping, VAT aggregation.
- **tests/Feature/Scenarios/EtaScenarioTest.php** (356 lines): Multi-line aggregation, status lifecycle, RBAC, scoping, idempotency, rejected/exception handling.
- **tests/Feature/Services/EtaJsonBuilderTaxIdTest.php** (48 lines): Tax_id validation (business must have it, individual optional).
- **tests/Feature/Services/EtaIntegrationTest.php** (166 lines): Mock/real HTTP modes, job queue, submission service end-to-end.
- ~~tests/Feature/Settings/ModulesEtaToggleTest.php~~ — **deleted with the freeze.** `modules.eta` is no longer a toggle; `tests/Feature/Regression/EtaIsFrozenAndInvisibleTest.php` asserts the module stays invisible even with the settings row switched ON.
- **tests/Feature/Resources/InvoiceEtaFiltersTest.php**: Table filters and actions (if exists).
- ~~tests/e2e/13-eta.spec.js~~ — **deleted with the freeze.** It asserted seeded "Valid" badges in the invoices table, and nothing seeds them any more. `tests/e2e/17-functional-actions.spec.js` now asserts the opposite: no Submit-to-ETA action and no ETA Status column on the page.

### Related modules (see docs/modules/)
- **05-billing-invoices.md**: Invoice model, status lifecycle, tenant scoping, line items, monthly billing.
- **02-tenants.md**: Tenant model, tax_id field, business types.
- **04-leases.md**: Lease model, tenant-unit relationship, charges.
- **18-rbac-scoping.md**: RBAC, permissions, roles (manager, accounting, super_admin, etc.).
- **../PROPERTY-ISOLATION.md**: Asset scoping, TenantScope, property isolation.

### Config files
- **config/eta.php**: Master toggle, mock/live modes, issuer identity, ETA endpoints, OAuth credentials.
- **.env**: ETA_ENABLED, ETA_MOCK, ETA_CLIENT_ID, ETA_CLIENT_SECRET, ETA_ISSUER_TRN, etc.

### Migrations
- **2024_01_01_000006_create_invoices_table.php**: Initial invoice schema (eta_submission_id, eta_submitted_at, eta_response columns).
- **2026_05_23_172154_add_eta_status_to_invoices_table.php**: Adds eta_status enum and eta_long_id columns.

### Language files
- **lang/en/admin.php**: 'admin.actions.submit_to_eta', 'admin.actions.bulk_submit_to_eta', 'admin.notifications.eta_submitted', 'admin.notifications.bulk_eta_complete', 'admin.statuses.eta.*', etc.
- **lang/ar/admin.php**: Arabic translations.

---

**Document version**: 1.0  
**Last updated**: June 2026  
**Scope**: ETA e-invoicing submission, status tracking, Filament admin UI, mock/live modes.
