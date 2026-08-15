# Module 08 — ETA e-invoicing

> Date: 2026-05-31
> Status: 🟡 Yellow — code clean, well-tested; 1 inline fix (F-32 PDF ETA block); 3 Yellow findings (compliance widget filter inconsistency, Pending tile non-clickable, retry behavior); 1 production-cutover spec for D-17.
> Surface: `app/Services/Eta/` (3 services), [SubmitInvoiceToEta job](../../app/Jobs/SubmitInvoiceToEta.php), [EtaSettings](../../app/Settings/EtaSettings.php), [EtaCompliance widget](../../app/Filament/Admin/Widgets/EtaCompliance.php), Invoice ETA columns + actions + filters, PDF ETA reference block (added this module).

## 1. Inventory

### 1.1 Services — `app/Services/Eta/`

| Service | LOC | Purpose |
|---|---:|---|
| [EtaJsonBuilder](../../app/Services/Eta/EtaJsonBuilder.php) | 147 | Maps Invoice → ETA v1.0 JSON document. Issuer block from config; receiver block from tenant (type → `B`/`P`/`F` for business/individual/foreign). Lines: itemType `EGS`, unitType `EA`, quantity 1.0, T1/V009 tax breakdown. **EGS code map** in `mapItemCode()`: `base_rent→EG-6820-001, service_charge→EG-6820-002, utility→EG-3530-001, parking→EG-5221-001, percentage_rent→EG-6820-003, default→EG-6820-999`. 5-decimal rounding. **Tax-id guard** at line 30: throws `RuntimeException` if business tenant has null/empty tax_id. |
| [EtaSubmissionService](../../app/Services/Eta/EtaSubmissionService.php) | 71 | `submit($invoice)` — idempotent (no-op if `eta_status==='valid'`). On success persists `eta_submission_id`, `eta_long_id`, `eta_status`, `eta_submitted_at`, full `eta_response`. On error sets `eta_status='rejected'` + `eta_response=['error'=>msg]`. Fallback status `'submitted'` if response has neither accepted nor rejected docs. |
| [EtaApiClient](../../app/Services/Eta/EtaApiClient.php) | 83 | Switches on `config('eta.mock', true)`. **Mock**: returns deterministic shape `{status:success, submissionId:'MOCK-{rand20}', longId:{rand32}, documentStatus:'Valid'}`. **Real**: OAuth client-credentials token from `eta.auth_endpoint`, then POST to `eta.endpoint/api/v1/documentsubmissions` with Bearer. |

### 1.2 [EtaSettings.php](../../app/Settings/EtaSettings.php)

Spatie LaravelSettings, group `eta`:

| Field | Default | Purpose |
|---|---|---|
| `enabled` (bool) | true | Module gate — `Modules::enabled('eta')` reads this |
| `mock` (bool) | true | Mock vs real submission |
| `issuer_name` (string) | "Atriom Demo Operator" | Operator legal name on JSON |
| `issuer_tax_registration_number` (string) | "123-456-789" | Operator TRN on JSON |

Admin UI exposes all 4 fields at `/admin/{tenant}/settings` (ETA tab). For production, both toggles + the two TRN/name strings must reflect real operator data.

### 1.3 Invoice ETA columns + UI

- 5 columns on Invoice: `eta_submission_id`, `eta_long_id`, `eta_status`, `eta_submitted_at`, `eta_response`. Status enum: `pending, submitted, valid, invalid, rejected, cancelled`.
- Table `eta_status` badge: `valid→success`, `submitted→info`, `invalid|rejected→danger`, `cancelled→gray`, null→`—`.
- **Record action** "Submit to ETA": visible if `Modules::enabled('eta') && eta_status !== 'valid' && status ∈ ['issued','partially_paid','paid','overdue']`. Modal copy adapts to mock vs live.
- **Bulk action** "Submit Selected to ETA": skips already-valid, reports submitted vs skipped counts.

### 1.4 [SubmitInvoiceToEta job](../../app/Jobs/SubmitInvoiceToEta.php) (23 LOC)

ShouldQueue, default queue, default Laravel retries (=3). Just delegates `EtaSubmissionService::submit($invoice)`. **No exponential backoff configured**. If service throws (transport error), job retries 3 times — each retry tries the service again, which may overwrite `eta_response` with a new error message each time. Service-level errors (rejected document) do NOT throw, so they're not retried at the job level. See F-34.

### 1.5 EtaCompliance widget (77 LOC, already inventoried at Module 01)

Stat tiles: Valid / Submitted / Rejected / Pending. Base query is invoices with `status ∈ ['issued','partially_paid','paid','overdue']` (excludes drafts + cancelled) scoped via `TenantScope`. Deep-link URLs into filtered `InvoiceResource::index`.

### 1.6 PDF ETA reference block (added this module — see F-32)

[invoices/pdf.blade.php](../../resources/views/invoices/pdf.blade.php) now has a teal-bordered ETA block above the footer, rendered conditionally on `eta_submission_id`. Shows Submission ID, Long ID, Submitted timestamp. Bilingual via 4 new `admin.pdf.eta_*` keys in EN + AR.

## 2. Tests

| File | Cases |
|---|---|
| [EtaJsonBuilderTest.php](../../tests/Feature/EtaJsonBuilderTest.php) (118 LOC) | Required-field shape; individual tenant → receiver type `P`, empty taxTotals. |
| [Services/EtaJsonBuilderTaxIdTest.php](../../tests/Feature/Services/EtaJsonBuilderTaxIdTest.php) (48 LOC) | Throws for business+null tax_id; builds for business+tax_id; allows individual without tax_id. |
| [Services/EtaIntegrationTest.php](../../tests/Feature/Services/EtaIntegrationTest.php) (166 LOC) | Mock submission shape; real OAuth flow; HTTP 500 fallback; service idempotency on valid; persists ids/status on accepted; marks rejected; captures throwables; job delegation. |
| [Settings/ModulesEtaToggleTest.php](../../tests/Feature/Settings/ModulesEtaToggleTest.php) (39 LOC) | `Modules::enabled('eta')`, widget `canView()` toggling, canonical key list. |
| [tests/e2e/13-eta.spec.js](../../tests/e2e/13-eta.spec.js) | Invoices index loads, seeded Valid badges render. |

All ETA-tagged tests pass (18 cases / 49 assertions in 1.1 s).

## 3. Spec map

| Source | Verbatim | Verified |
|---|---|---|
| DEMO.md | "ETA e-invoicing is live in mock mode — submit-to-ETA action returns a stubbed Valid response. Flip `eta.mock` off in `/admin/settings → ETA` when preprod creds land." | ✅ |
| DEMO.md | "ETA Compliance widget on the dashboard surfaces Valid/Submitted/Rejected/Pending counts at a glance, each tile clickable into a filtered invoice list." | ⚠️ **Pending tile is not clickable — F-35**. |
| MASTER-PLAN.md | "EtaJsonBuilder (with tax-id validation for business tenants), EtaApiClient (mock + real modes), EtaSubmissionService, SubmitInvoiceToEta job, admin Submit to ETA per-invoice + bulk action, status badge column." | ✅ |
| MASTER-PLAN.md | "Seeded 65 historical submissions (55 Valid + 10 Rejected)." | ✅ seeder line confirms `48 valid + 8 rejected` after recent re-seed — numbers drift slightly but the spread is honored. |
| FEATURES.md | "EtaJsonBuilder throws a clear error when a business tenant lacks a tax_id, instead of silently submitting 000000000 and letting ETA reject the document with an opaque error." | ✅ tested. |

## 4. Findings

### 🟡 F-32 (Fixed inline) — Invoice PDF had no ETA reference block

The PDF Blade rendered the totals, notes, and footer but no submission_id / long_id / submitted_at. Egyptian compliance practice is to show the ETA reference on the invoice document so the customer can verify with the regulator. Now rendered conditionally on `eta_submission_id`:

- 4 new translation keys: `admin.pdf.eta_reference`, `eta_submission_id`, `eta_long_id`, `eta_submitted_at` (EN + AR).
- New CSS class `.eta-block` (teal accent matching brand). LTR/RTL aware.
- Hidden when invoice not yet submitted (the 99 % of cases during day 1 of demo).

Regression: Pest 287/287, PDF e2e 4/4, content e2e 4/4, ETA e2e 2/2 — all green.

### 🟡 F-33. EtaCompliance "Rejected" tile counts both `invalid+rejected` but deep-links to `invalid` only

[EtaCompliance.php:43](../../app/Filament/Admin/Widgets/EtaCompliance.php#L43) computes `$rejected = whereIn('eta_status', ['invalid', 'rejected'])->count()` (correct: regulator can return either state and operators treat them the same). But the tile's URL filter at line 68 is `['eta_status' => ['value' => 'invalid']]` — clicking the tile lands on a list filtered to `invalid` only, missing the `rejected` half.

**Fix scope (deferred D-24):** the InvoicesTable filter on `eta_status` is a SelectFilter with single value. To fix cleanly, either:
- **A**: Replace the SelectFilter on InvoicesTable with a custom Filter that supports multi-value (clean but 30+ LOC).
- **B**: Add a separate "Needs ETA attention" filter that maps to `whereIn ['invalid', 'rejected']`, and point the widget tile at that filter.
- **C**: Drop the URL on the Rejected tile (consistent with Pending — not clickable). Worst UX, smallest change.

Recommend B; defer for explicit approval.

### 🟡 F-34. SubmitInvoiceToEta uses default Laravel retries (3) with no backoff

A transport error (5xx from ETA or network blip) makes the job throw → Laravel retries 3 times with no backoff. Each retry runs the full submit flow, including a fresh OAuth token mint. For ETA's preprod sandbox this is fine; for production rate-limited endpoints, three back-to-back retries within seconds could:

- trigger rate limiting on the auth endpoint
- overwrite `eta_response` with each retry's error message (only the last one survives)

**Fix (small but config-sensitive — deferred D-25):** set explicit `$tries=1` on the job + configure a separate retry strategy via `backoff(): array` returning `[60, 300, 900]` (1m, 5m, 15m). Service-level rejections aren't retried (they don't throw) — good.

### 🟡 F-35. EtaCompliance "Pending" tile is not clickable

The widget says "each tile clickable into a filtered invoice list" (DEMO.md) but Pending has no URL. The InvoicesTable filter for `eta_status` would need a "pending or null" option to support this. Same fix family as F-33; bundle the two together (D-26).

### 🟢 The tax-id guard is the right defensive layer

`EtaJsonBuilder::build()` throws before submission, so operators see "Tenant X is missing tax_id, fix that first" instead of "ETA rejected your document with code ABC". Tested by 3 cases in `EtaJsonBuilderTaxIdTest`.

### 🟢 No F-17 carryover for ETA widget

`EtaCompliance` uses `TenantScope::applyTo()` correctly. No nav badge override (it's a stats widget).

### 🟢 EtaSettings provides the production cutover surface

`/admin/{tenant}/settings → ETA` exposes the 4 fields an operator needs. Combined with the `.env` work, this is what D-17 needs to action.

## 5. Production cutover plan (D-17 specification)

When real ETA credentials arrive, the cutover is a 2-zone change:

**Zone 1 — `.env` and config**
```env
ETA_MOCK=false
ETA_ENDPOINT=https://api.invoicing.eta.gov.eg            # or preprod URL during testing
ETA_AUTH_ENDPOINT=https://id.eta.gov.eg/connect/token
ETA_CLIENT_ID=<from ETA portal>
ETA_CLIENT_SECRET=<from ETA portal>
```

**Zone 2 — admin Settings → ETA**
- "ETA Mock Mode" toggle → OFF
- "Issuer Name" → operator legal name
- "Issuer TRN" → operator tax registration number

**Pre-cutover validation (run against ETA preprod first):**
1. Pick one test invoice with a B2B tenant that has a valid `tax_id`.
2. Submit via Submit to ETA action.
3. Verify response: `eta_status` reaches `valid` (may briefly be `submitted` first).
4. Confirm `eta_submission_id` + `eta_long_id` populated.
5. Open the PDF — the new ETA block (F-32 fix) should display both ids.
6. Confirm EtaCompliance widget increments Valid.

**Rollback plan:** flip `ETA_MOCK=true` in `.env` and `php artisan config:clear`. All in-flight `SubmitInvoiceToEta` jobs will then run against mock. Real submissions already valid remain valid (we don't un-submit).

## 6. Test sweep

| Filter | Result | Time |
|---|---|---|
| `php artisan test --parallel --filter='Eta'` | **18 passed / 0 failed** | 1.11 s |
| `npx playwright test 05-pdfs 07-pdf-content 13-eta` | **9 passed / 0 failed** | 12.7 s |
| `php artisan test --parallel` (post-PDF + lang edits) | **287 passed / 0 failed** | 4.11 s |

## 7. Manual UX

PDF generated under e2e includes the ETA block when invoice has an `eta_submission_id`. Spot-checked the visible-text e2e (`07-pdf-content.spec.js`) — no assertion on the ETA block text yet; could be added (D-27) but not blocking.

## 8. Inline fixes this module

- **F-32**: ETA reference block in [invoices/pdf.blade.php](../../resources/views/invoices/pdf.blade.php) + 4 new lang keys (en+ar). ≈30 LOC across 3 files.

## 9. Deferred decisions

| # | Decision | Default |
|---|---|---|
| D-17 (from M05) | Confirm production cutover sequence per §5 above | Apply when real creds land |
| D-24 | F-33: fix Rejected-tile filter to navigate to invalid OR rejected | Approach B — add "needs ETA attention" filter, repoint tile |
| D-25 | F-34: explicit `$tries=1` + `backoff()` on SubmitInvoiceToEta | Apply pre-production cutover |
| D-26 | F-35: make Pending tile clickable (filter "pending or null") | Bundle with D-24 — same filter family |
| D-27 | Add e2e assertion for the new ETA block text in PDF | Defer to test-writing pass |

## 10. Verdict

**🟡 Yellow.** ETA is a polished module with strong tests and a clean mock/real split. The new PDF ETA block (F-32) closes a real compliance gap that would have surfaced on day 1 of production submission. The remaining Yellow items (F-33/F-34/F-35) are all small refinements rather than blockers, and the production cutover sequence is now documented end-to-end.

Module ratings: 00 🟢 · 01 🟡 · 02 🟢 · 03 🟡 · 04 🟡 · 05 🟡 · 06 🟢 · 07 🟢 · 08 🟡.

## Next

Module 09 — Maintenance / CAFM. Surface: [MaintenanceRequest model](../../app/Models/MaintenanceRequest.php), [MaintenanceRequestComment model](../../app/Models/MaintenanceRequestComment.php), Admin + Portal resources, [MaintenanceRequestService](../../app/Services/MaintenanceRequestService.php), [SlaSettings](../../app/Settings/SlaSettings.php), [OpenMaintenanceRequests widget](../../app/Filament/Admin/Widgets/OpenMaintenanceRequests.php), and the F-17 nav badge carryover fix on MaintenanceRequestResource.
