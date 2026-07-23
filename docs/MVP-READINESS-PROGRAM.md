# Atriom — MVP-Readiness Program

> Take the whole system to a **high-standard, competitive, first-client-MVP** state — module by module — benchmarked against property **and** facility-management specialists. This doc is the charter for that program: the mission, the per-module pass, the process, and live status. The authoritative per-module findings live in [`docs/gap-analysis/`](gap-analysis/) and the closure ledger [`PROPERTY-FACILITY-CLOSURE.md`](gap-analysis/PROPERTY-FACILITY-CLOSURE.md).

## 1. Mission

- **Client:** Eltizam (operator) running Egyptian malls for owners (Jawad). This is the **MVP for the first real client** — it must be trustworthy on money and pleasant to operate, not a demo.
- **Bar:** a genuinely high-level app with high standards — correct books, verifiable numbers, clean UX, bilingual (EN + RTL Arabic), scalable and maintainable **by default**.
- **Benchmark:** the leasing/AR specialists (Yardi, MRI) **and** the facility-management specialists (Angus, Building Engines, Planon, MRI). For every module we ask: *what do the specialists do that we don't, and is it MVP-critical for this client or a fair deferral?*
- **Moat we are protecting:** Egyptian books (VAT 14% / exemptions, ETA e-invoicing, marketing levy), a real double-entry GL with a property/asset dimension, SLA→AP automation, airtight per-property isolation — the things generic global platforms localize poorly.

## 2. The six-dimension pass (every module)

Each module is taken to production-grade across **all six** dimensions — not just one:

| # | Dimension | What "done" means |
|---|-----------|-------------------|
| 1 | **Business-model gap vs competitors** | Compare against P&FM specialists; list what's missing; classify each gap **MVP-critical** (our client needs it / competitors all have it) vs **deferrable** (with an explicit trigger). "Competitors have it" alone ≠ build it — the test is whether *our* client's reality needs it. |
| 2 | **UX enhancement** | The UX-completeness checklist: **verifiable numbers** (a native "View working" breakdown wherever a computed figure appears), native Filament (no hand-rolled Blade), clear action **feedback** with resulting state, **honest** modal copy, reactive forms, and **EN+AR i18n added in the same change** (no raw keys). |
| 3 | **Module completion** | The missing slices that make it functionally complete for the client's real workflow. |
| 4 | **GL completion & correctness** | Every money source on the `LedgerPoster::JOURNALIZERS` single registry; a real `accounting:sync-ledger` sweep tie-out test per source; closed-period guards (`PostingDate`); `billing:reconcile` ties out. |
| 5 | **Bug hunt** | An adversarial correctness review (multi-agent) before every push — find the input→wrong-output, don't trust "looks right". |
| 6 | **Recommendations** | What would make the module fully functional + competitive beyond the MVP — captured as deferrals with triggers, so nothing is lost. |

## 3. Process

- **Audit** — per-module (or grouped) **read-only** agents produce ranked findings (HIGH / MEDIUM / LOW) against the six dimensions.
- **Triage** — fix HIGH + MEDIUM now; defer LOW **with an explicit trigger**; retire false "X is missing" findings with the reason (the mechanism usually lives in another layer).
- **Adversarial review** before every push (a fresh pass on the diff, not just green tests).
- **Definition-of-done gates:** `vendor/bin/pest --parallel` green · PHPStan no new errors above the baseline · `billing:reconcile` ties out · module doc + memory updated **in the same commit** · pushed to `main`.
- **Standing decisions** (don't re-ask): pick the **MVP subset competitors have**, defer the rest; scalability + maintainability are non-negotiable defaults; no DB-level enums; native Filament over Blade; property isolation + authz double-gating on every write action.

## 4. Module status matrix

Legend: ✅ done · 🔄 in progress · ⬜ not started · — n/a. "Biz/Compl/GL/Bugs" for the AR/leasing spine were taken to CLOSED in the earlier close-out; **UX** is the dimension being added across the program now.

| # | Module | Biz-gap | UX | Completion | GL | Bugs | Overall |
|---|--------|:---:|:---:|:---:|:---:|:---:|---|
| 01 | Properties & Units | ✅ | ✅ | ✅ | — | ✅ | **UX pass done** |
| 02 | Tenants | ✅ | ✅ | ✅ | — | ✅ | **UX pass done** (HIGH authz fixed) |
| 04 | Leases | ✅ | ✅ | ✅ | ✅ | ✅ | **UX pass done** |
| 05 | Billing & Invoices | ✅ | ✅ | ✅ | ✅ | ✅ | **UX pass done** (HIGH authz fixed) |
| 06 | Payments (+ tenant credit) | ✅ | ✅ | ✅ | ✅ | ✅ | **UX pass done** (`b0740e1`) |
| 07 | Credit Notes | ✅ | ✅ | ✅ | ✅ | ✅ | **UX pass done** (`88814a7`) |
| 08 | CAM reconciliation | ✅ | ✅ | ✅ | ✅ | ✅ | **UX pass done** (`6ba6fb1`) |
| 09 | Tenant Sales & % Rent | ✅ | ✅ | ✅ | ✅ | ✅ | **Annual + UX done** (`8b2ca48`) |
| 10 | Utility Meters | ✅ | ✅ | ✅ | ✅ | ✅ | **Recharge built** — readings can now be billed |
| 11/26 | Facility work (requests · work orders · service schedules) | ✅ | 🟡 | ✅ | — | 🟡 | **Generalised** to any facility service |
| 12 | Vendors & Contracts | ✅ | ✅ | ✅ | ✅ | ✅ | **Full vendor lifecycle** — compliance docs, renewal notice, change orders, withholding tax |
| 29 | Procurement | ✅ | ✅ | ✅ | ✅ | ✅ | **PO document + UX** — ordering now produces a real purchase order |
| 32 | Owner Statements | ✅ | ✅ | ✅ | ✅ | ✅ | **Itemized statement + owner portal** — the deliverable is readable and reaches the owner |
| 33 | Post-dated Cheques | ✅ | ✅ | ✅ | ✅ | ✅ | **Series lodging + maturity dashboard** — a year of cheques in one act, and matured ones surfaced |
| 17 | Reports | ✅ | ✅ | ✅ | — | ✅ | **CSV export across all financial reports** — GL + AR-aging gained export; accountant-workable, not PDF-only |
| 03 | Tenant Portal | ✅ | ✅ | ✅ | — | ✅ | **Tenant can see their own lease** — terms + document download; the doc claimed it, the surface didn't exist |
| 15 | Owner Requests | ✅ | ✅ | ✅ | — | ✅ | **Conversation thread** — a real back-and-forth replacing a single overwritten note that was silently dropped |
| 31 | Violations | ✅ | ✅ | ✅ | — | ✅ | **Categories + photo evidence** — classify & filter by kind; attach the photo of the breach |
| 22 | Inventory & Stock | ✅ | ✅ | ✅ | ✅ | ✅ | **Stock valuation on screen + CSV registers** — first generic-module UX pass; correctness unchanged |
| 10–33 | (remaining) | ⬜ | ⬜ | — | — | ⬜ | Backlog — see the ledger |

*The full ordered list (including 03 Tenant Portal, 15 Owner Requests, 16 ETA, 17 Reports, 30 Areas, 31 Violations, and the generic-ERP layer that is intentionally frozen) is in the [closure ledger](gap-analysis/PROPERTY-FACILITY-CLOSURE.md).*

## 5. Current focus — the "first 8" (the AR / leasing spine)

Modules **01, 02, 04, 05, 06, 07, 08, 09** — the money-critical core the client touches daily. UX pass:

- **Done:** 09 annual (cumulative) % rent — module completion **and** the operator/tenant UX; then the UX pass over **06 tenant-credit**, **07 Credit Notes**, **08 CAM** (each: verifiable "View working" breakdowns, caught `DomainException`→toast instead of 500, native components, richer/branched feedback, honest modal copy, EN+AR keys — with per-module adversarial review + tests).
- **Also done — 01, 02, 04, 05.** Two **HIGH authz holes** closed (the systemic `visible()`-only class): Tenants **`portalAccess`** (a `leasing` user could set/reset any tenant's portal password via a crafted dispatch) and Billing **`runMonthlyBilling` + ETA submit/bulk-submit** (viewer/owner could trigger a property-wide billing run or file tax invoices). Plus: Leases `renew`/`changeRent`/`terminate` now catch the service guard instead of a Livewire 500; the Assets list column titled *Occupancy* actually showed raw leasable area (relabelled); English label fallbacks in the Arabic panel fixed (country/currency/areas/is_active/description); a manually-added `base_rent` line no longer defaults to 14% VAT (base + % rent are exempt); the late-fee line now states its basis (`2% of EGP X overdue, min EGP 50`) instead of a bare "Late Fee"; `reverse_credit` catches its guard.
- **The "first 8" (the AR/leasing spine) is now complete across all six dimensions.**
- **Next:** modules 12+ (Vendors → Procurement → Owner Statements → PDC → …), each through the full six-dimension pass.

## 5c. Facility work (modules 11/26) — the domain model, and what changed

**There is no standalone "maintenance module", and that is correct.** The system already has the three-layer structure every CMMS/IWMS is built on — and they must stay distinct (not every request becomes work; one request can spawn several work orders; and **planned work has no request at all**):

| Layer | Here | Holds |
|---|---|---|
| **Demand (intake)** | **Tenant Requests** | `request_type` + `category`, priority, SLA target, area, department, vendor, CSAT — maintenance is one *category* |
| **Execution** | **Work Orders** | ppm/cm, internal/external, targets asset/unit/**area**/equipment, SLA, vendor/assignee, parent WO, `tenant_request_id`, **fault party + cost bearer** (tenant chargeback), parts |
| **Planned generator** | **Service Schedules** (was "Maintenance Plans") | target + discipline + cadence + **checklist** → raises work orders |

**What changed (2026-07-22).** FM splits work into **hard services** (HVAC, electrical, lifts — *equipment*-centric PPM) and **soft services** (cleaning, landscaping, pest, waste, security — *location*-centric rounds). Both belong in the same work-order engine; they differ only in target and cadence. Plans and work orders could target asset/unit/equipment but **not an area**, so soft services — which this operator schedules in-house — could not be planned at all. Now:

- **`area_id` on schedules and work orders** — a round knows *where* it happens ("clean the food court"), and the generator carries the location onto the raised order so it still says where after the plan changes.
- **`days_of_week` on schedules** — "every Mon/Wed/Fri" rounds. Empty = any day, so every existing plan behaves exactly as before.
- **Discipline vocabulary broadened** — added landscaping, pest control, waste management, security alongside the maintenance trades.
- **Relabelled** to *Service Schedule(s)* so an operator scheduling cleaning isn't staring at a screen called "Maintenance Plans" (labels + i18n only; tables unchanged).

**Deliberately NOT built — sub-daily work orders.** "Clean twice daily" as two work orders per day is 700+ orders a year per area of pure noise. The FM convention is **one daily work order whose checklist carries the rounds** ("morning round", "evening round") — which `checklist` already supports. *Trigger to revisit: a client needing per-round sign-off with distinct times/assignees.*

**Deferred (triggers):** meter/usage-based triggers (service every 500 runtime hours — *trigger: equipment with runtime metering*); condition-based triggers; route/patrol schedules covering many areas in one order; per-discipline SLA targets.

## 5d. Module 12 — Vendors & Contracts (done)

**The compliance gate already existed** (`5df09c0`): a vendor whose Certificate of Insurance has lapsed is dropped from every assignment picker (`Vendor::assignable()`) and refused at the real gate (`MaintenanceWorkOrder::saving()`). The initial scan claimed "COI is never enforced" — **that was a false absence finding**, caught by verifying before fixing. What was actually missing were the two things that make the gate usable:

- **The cert lapsed *silently*.** No warning beforehand, no explanation afterwards — the operator's contractor simply vanished from a dropdown. Now `vendors:scan-coi-expiry` (daily 02:40) chases at **30 days out** and again **on lapse**, stamped with *both* the stage and the exact cert date, so a re-run never re-nags, an escalation alerts once more, and **renewing the cert re-arms the cycle by itself**. Recipients come from *engagement* — staff of the properties where the vendor actually holds an active contract (vendors are a shared portfolio catalog), falling back to portfolio roles. Backed by an **Action Required card** + a **"Insurance lapsed / lapsing" table filter**, all three reading the same `Vendor::coiNeedsAttention()` scope so they can never disagree. Delivery failure warns but still stamps — the live card is the backstop, so a dropped notification can't hide a lapsing cert.
- **`vendor_contracts.value` was decorative.** A bill was never tied to the contract it was incurred under, so nothing compared committed vs actually invoiced — a EGP 500k contract could quietly absorb EGP 5m of bills. Now `vendor_bills.vendor_contract_id` (nullable — ad-hoc call-outs have no contract) drives **committed / billed-to-date / remaining** on the contracts list, red once over-run, and a live helper on the bill form spelling out the arithmetic (`committed − billed = remaining`) rather than a bare figure. Cancelled bills don't consume the commitment. It's a **flag, not a block** — change orders and overruns are legitimate; hiding them isn't.

**Verified sound, not changed:** every vendor/AP write action already uses `->authorize()` (a real Filament gate, unlike `visible()`), `VendorBillService::approve()` re-checks state, `recordPayment()` locks, `cancel()` guards; `VendorBill`/`VendorBillPayment`/`MaintenancePenalty`/`Disbursement` are all on the GL registry.

**Deferred (triggers):** hard-blocking a non-compliant vendor at *award* time on purchase requests (*trigger: a client whose procurement policy requires it* — a block without an emergency override stops 2am burst-pipe work); COI document-expiry OCR; per-contract SLA scorecards.

### 5d(ii). Module 12b — structural completion vs competitors (done)

A follow-up review asked whether the module was *structurally* competitive, not just correct. It was a **vendor directory + AP ledger**; Yardi/MRI/ServiceChannel ship vendor **lifecycle management**. Four material gaps closed (all verified real by grep before building):

- **Compliance documents (`vendor_documents`).** `vendors.coi_expires_at` modelled exactly one document. An Egyptian supplier file is several — insurance, بطاقة ضريبية, سجل تجاري, شهادة تأمينات اجتماعية — each expiring on its own clock. Rather than bolt a second mechanism beside the COI columns, the COI **moved into** the new table (data + files migrated, columns dropped) so there is one source of truth. Only **blocking** types (insurance) stop dispatch; the statutory ones are chased but never block emergency work. The expiry chase generalised from COI-only to any document (`vendors:scan-document-expiry`), same two-column re-arming stamp.
- **Renewal *notice* alerting.** `vendors:expire-contracts` fired on `end_date` — too late to decide anything. `vendors:scan-contract-renewals` fires on the **notice deadline** (`end_date − notice_period_days`), with `auto_renews` changing the message from "line up a replacement" to the harder "serve notice by X or you're committed for another term". `notice_deadline` is a stored, indexable column kept in step by a saving hook.
- **Change orders (`vendor_contract_amendments`).** `value` was static, so the over-run flag couldn't tell an approved uplift from an uncontrolled over-run — both showed red. A signed `value_delta` (descoping allowed) now moves `effectiveValue()`, with a dated, attributed, reasoned audit trail. No edit action — a change order is a signed instrument; correct via a compensating one.
- **Withholding tax (خصم وإضافة).** Atriom paid vendors **gross** — non-compliant with Income Tax Law 91/2005 art. 59, and the un-withheld amount becomes the operator's own liability. Now the payment splits **Dr AP (gross) / Cr Bank (net) / Cr WHT Payable (withheld)** — the AP-side twin of the AR VAT. **Settings-driven** rates (never a hardcoded statutory guess), **off by default**, per-vendor override where `0 = exempt ≠ null = use default`. GL tie-out proven through the **real `accounting:sync-ledger` sweep**, per the registry rule.

*Discipline note:* the initial scout's "COI is never enforced" was **false** (the gate shipped in `5df09c0`) — caught by verifying first, again ([[feedback_verify_absence_claims]]). **Deferred (triggers):** WHT remittance-return reporting to the ETA (*trigger: first filing period*); per-payment-category WHT rates; document OCR.

## 5e. Module 29 — Procurement (done)

The mechanics were already complete and correct: the request → approve → order → receive state machine (with `requested → ordered` deliberately *unrepresentable* = FR-PROC-02), the value-based approval ladder, GRNI clearing against the vendor bill (a proven double-count fixed earlier), warehouse scoping, RBAC. The pass therefore led with the two priority dimensions — **business gap** and **UX** — and found one material gap.

- **Business gap #1 — there was no Purchase Order document.** Ordering flipped a status and stored a free-text `order_reference`; the vendor received nothing. Every procurement system (and the operator's real workflow) needs a numbered, itemized PO to send. Now `po_number` is stamped at order time — its own identity, distinct from the internal requisition `reference` — and `PurchaseOrderPdfService` renders a bilingual, priced PO downloadable from the row and the edit page. It reuses the established PDF-service pattern (the app already has 7); the "order" action finally produces an artifact like every other money-document.
- **UX — verifiable numbers + feedback with resulting state.** A **"View working"** modal shows the lines that make the total and **which approval tier the value falls into** (re-judged on the current total), so an approver never trusts a bare figure. Order and receive feedback now carry the *result*: ordering reports the PO number + who it went to ("download and send it from the row"); receiving reports how many items stocked into which warehouse (or that it was services-only). EN+AR keys added in the same change.

**Verified sound, unchanged:** every write action already double-gates (`visible()` + `authorize()`); the approval tier is frozen at request time yet re-judged on the current total; GRNI clearing is capped at what the receipt credited. **Deferred (trigger):** partial receipts (receive some lines/quantity now) — the FRD doesn't ask and half-receiving is a real workflow decision; the line-level `stock_movement_id` is the seam when a client needs it. Emailing the PO to the vendor waits on the same email-certification gate as the rest of the app.

## 5f. Module 32 — Owner Statements (done)

The GL spine, lifecycle (generate → finalise → revise), disbursements, approval ladder and posting-date guards were already built and correct. Leading with **business gap + UX**, two findings — both about the *deliverable itself*, not the accounting:

- **The statement showed only three totals** — revenue, expense, net. An owner receiving it couldn't see WHAT the revenue was (rent, CAM, parking) or WHERE the expenses went (maintenance, utilities, staff). Every property-management platform itemizes; Atriom already computed the per-account breakdown in `LedgerReportService::incomeStatement()` and was **discarding it after summing**. Now `income_breakdown` (JSON) snapshots it at generate time, **frozen alongside the totals** (recompute-then-freeze, so the detail can't drift from the net), and the PDF + "View working" modals render revenue-by-account → expenses-by-account → net. This is the "verifiable numbers" checklist item applied to the deliverable — the net is no longer an unexplained figure.
- **The owner portal (`/owner`) had no statements at all.** The operator produces the statement *for* the owner, yet the owner's own portal (Invoices, CAM, Properties, TenantRequests) didn't surface it — it had to be emailed by hand. A read-only `OwnerStatementResource` now lists the owner's **own finalised/sent** statements (scoped `where('user_id', Auth::id())` + status filter — never a draft, never another owner's), with owner-share / paid / **outstanding**, in-portal "View working", and Download PDF. The deliverable finally reaches its recipient, self-service.

**Verified sound, unchanged:** the `net_distributable = Σ owner_share` penny reconciliation; recompute-then-freeze; disbursement cap re-checked under lock; posting-date guard; the two-source GL tie-out. **Deferred (unchanged):** the **management fee** (Eltizam's cut) — it needs the settings-driven Egyptian tax catalog for its VAT-on-fee treatment, so it stays deferred with the design pre-written in the plan; and multi-owner co-ownership (the split infra exists, unapplied — one owner, 100%).

## 5g. Module 33 — Post-dated Cheques (done)

The register, lifecycle (held → deposited → cleared/bounced/cancelled), settle-on-clear GL and property isolation were already correct. Leading with business-gap + UX, two findings, both drawn straight from the module's own framing — "a tenant commonly lodges a **year** of post-dated cheques up front":

- **UX — no bulk series lodging.** The create form is one cheque at a time, so taking in a year of PDCs meant 12 separate, error-prone entries. `PostDatedChequeService::lodgeSeries()` + a **"Lodge a series"** action now creates the whole series in one act — sequential cheque numbers (numeric tail incremented, zero-pad kept), maturities one interval apart (monthly/quarterly/biannual) — with a **live preview** of count · each · total · first→last maturity before the operator commits. This is the operator's real workflow, matched.
- **Business-gap + UX — the maturity schedule wasn't surfaced.** The whole value of a PDC register is knowing what cash is due when, but the daily scan only wrote to OpsLog; matured-but-uncleared cheques (money the operator should already have collected) never reached the dashboard the way overdue invoices do. An **Action Required card** now counts them (property-scoped) and links to the register's "Matured & uncleared" filter. The scan, the card and the filter all read one shared scope (`maturedUncleared()`), so they can never disagree.

**Verified sound, unchanged:** clearing routes through `Payment` + `recomputeTotals()` (AR single source of truth); posting-date guard; terminal-immutability of cleared/cancelled cheques; every transition lock-safe. Demo now seeds a 12-cheque annual series so the feature is visible on a fresh install. **Deferred (unchanged):** the Notes-Receivable-on-receipt accrual (needs the accountant + a `notes_receivable` mapping) — v1 stays register-only, settle-on-clear.

## 5h. Module 17 — Reports (done)

Flagged by the client as "stale and not best practice." The scout confirmed the instinct precisely: **the entire app had zero CSV/Excel export**, every report was **PDF-only**, and the two reports an accountant most needs as raw data — the **General Ledger** (raw transaction detail) and **AR Aging** (the collections worklist) — had **no export at all**. That *is* the not-best-practice: a financial report you can only look at, never pull into a spreadsheet to pivot, reconcile, or hand an auditor, isn't a working report.

The fix — **CSV export across all six financial reports** (Trial Balance, Income Statement, Balance Sheet, Cash Flow, General Ledger, AR Aging):
- One streaming primitive (`App\Support\ReportCsv`) with a **UTF-8 BOM** so Excel renders Arabic, and a testable flattener (`ReportCsvExporter`) that turns each computed report into `[headers, rows]` — amounts as plain numbers (spreadsheet-native), account names by locale, statements with per-section subtotals + a self-checking totals/net line so the CSV reads like the on-screen report.
- Wired as an **"Export CSV"** action on every report page, gated on `reports.view`. **GL and AR aging gained export for the first time.**

**Verified sound, unchanged:** the reports are read-only, property-scoped (`TenantScope`), and GL-derived; the existing PDF export stays. This is dimension 2 (UX) + dimension 1 (business-gap: every accounting system exports to CSV) closed together. **Deferred (trigger):** true `.xlsx` (needs a spreadsheet library — CSV opens natively in Excel and covers the accountant's need); a custom from/to date range on the statements (currently calendar-year) — *trigger: an accountant asking for a non-calendar period*.

## 5i. Module 03 — Tenant Portal (done)

The portal is already rich (Invoices with pay/download, Payments, Tenant Requests, CAM, Sales Declarations, a dashboard). Leading with business-gap + UX, the scout found a gap the module doc itself papered over: **the doc says a tenant "sees the same lease, invoices and maintenance requests" — but there was no lease surface at all.** A tenant could not see their own terms (rent, dates, escalation, percentage rent, deposit) or download their signed lease — a core tenant-portal staple, and the same recurring shape as the last few modules: *a record the recipient can't see.*

The fix: a read-only **`LeaseResource` in the portal**, scoped `where('tenant_id', Portal::tenantId())` (never another tenant's), with a full-terms **infolist** (native, no Blade) — the percentage-rent section shown only to the tenants it applies to — and a **Download lease** action streaming the signed document from the private `documents` collection. Read-only for everyone (it's the operator's record, shown to the tenant), so even a viewer-only portal user sees it.

**Verified sound, unchanged:** the existing portal scoping/gating (admin-only writes, tenant isolation) holds; no new model (Lease is already property-classified). **Deferred (trigger):** a browsable **Announcements** page — operator broadcasts already reach the tenant as a bell notification (the deliverable *arrives*), so a persistent list is polish; *trigger: a tenant wanting to re-read a dismissed notice.*

## 5j. Module 15 — Owner Requests (done)

The user asked to lead with **UX** here, and the module had two concrete UX defects hiding behind a working-looking feature. Owner requests are a *communication channel* (an owner raises a matter to the operator or a co-owner), but the interaction was a single `resolution_notes` field the operator overwrote — and, worse, that note was **silently dropped unless the status was set to `resolved`**. So an operator replying "we're looking into it" while moving the request to *in-progress* lost their message entirely, and there was never a back-and-forth. A channel with no conversation and a note that can vanish is the definition of poor UX.

The fix turns it into a real conversation: an `owner_request_replies` thread (immutable, oldest-first), and a reworked **Reply** action that (1) shows the whole conversation inline so a reply is written in context, (2) takes a **required message that is always saved** regardless of status, (3) lets an **optional status move ride along**, and (4) notifies the *counterparty* (owner ↔ operator), never the author. Feedback reports the resulting status; the list shows a reply-count badge. Demo seeds a request with a 3-message conversation so the thread is visible on a fresh install.

**Verified sound, unchanged:** terminal-immutability (a closed request refuses replies, guarded in the service now, not just the UI); property scoping; the reply model is a property-owned chain (`=> 'ownerRequest'`), caught-and-classified by the conformance gate. **Deferred (trigger):** exposing owner requests in the dedicated `/owner` portal (owners currently use `/admin` with the owner role, per the module's design) — *trigger: consolidating owners onto the `/owner` panel*.

## 5k. Module 31 — Violations (done)

Leading with UX, a violation record had two gaps that made the operator's core workflow weak: it was classified only by **free-text `description`** (so you couldn't filter or report "how many signage breaches this quarter" or "which tenants repeat safety issues"), and it had **no photo evidence** at all — a violation without the photo of the blocked exit or the unauthorised banner isn't defensible. The module's own doc even listed "category, evidence photos" as a future extension; this builds it.

- **Category** — a required Select over `Violation::CATEGORIES` (signage / operating hours / cleanliness / safety / unauthorised works / noise / other; strings, not a DB enum), with a **table column and filter**. A field officer picks the kind instead of retyping it, and the operator can classify and report by it.
- **Photo evidence** — a `SpatieMediaLibraryFileUpload` (multiple, reorderable, max 8) on a **private** `photos` collection (`useDisk('local')`, gated by `MediaPrivacyConformanceTest` so it can't fail open to the webroot). The table flags evidence-backed violations with a **camera icon**.

**Verified sound, unchanged:** `fine_amount` still records-only (never billed/GL); property scoping + RBAC; the `notified_at` tenant-notice flow. **Deferred (trigger):** exposing violations in the tenant portal (the tenant is already notified) — *trigger: a tenant asking to see their violation history*; and billing a fine to AR — *trigger: the operator confirming fines should hit the tenant's account* (the doc keeps it record-only by design).

## 5l. Module 22 — Inventory & Stock (done) — first generic-module UX pass

The generic-ERP layer is frozen for *breadth* (don't grow toward Odoo), but the user re-scoped the MVP **UX-completeness** pass onto it too. Inventory is the first: correctness stays exactly as it was (the on-hand scoping, sign-by-type, GL costing, overdraw lock are all sound and untouched), and the pass adds only what makes the existing data workable.

**The gap was that stock value existed only in the GL.** The item table showed on-hand quantity and unit cost side by side and left the operator to multiply in their head, and there was **no way to export** either the stock register or the movement ledger — an accountant doing a stock-take or reconciling Inventory against the GL had nothing but a screen to read.

- **`value` column** on the items table — `on_hand × unit_cost`, money-formatted, reusing the same property-scoped `on_hand` subquery so the valuation can never disagree with the quantity beside it. "What is this mall sitting on?" is now answerable at a glance.
- **Export CSV** on both the item register and the movement ledger, via the shared `App\Support\ReportCsv` (UTF-8 BOM so Excel renders Arabic) — the same accountant-workable finding as [Module 17 Reports](#5h-module-17--reports-done). `InventoryItemResource::stockRegisterCsv()` reads the **same scoped query the table shows** and closes with a **total valuation** row; `StockMovementResource::movementsCsv()` exports the scoped movement trail. Both double-gate (`visible()` + `authorize()` on `canViewAny()`).

**Trap caught while building it:** a pre-existing cross-file test collision — my new `ViolationEvidenceAndCategoryTest` (Module 31) and `ViolationScenarioTest` both defined a global `makeViolation()` helper. `--parallel` runs each file in its own worker so they never collided there (which is why it stayed green), but a serial full-suite run fataled on the redeclaration. Renamed mine to `makeCategorisedViolation()`. **Also surfaced:** the PHPStan gate currently has ~73 above-baseline errors in the owner-statement / PDC / disbursement modules from recent commits — landed because `--parallel` doesn't run PHPStan and CI enforcement lagged. Not mine (my two changed files are 0-error), flagged for a dedicated baseline-reconcile pass.

**Deferred (triggers):** an as-of-date point-in-time valuation (needs a movement replay) — *trigger: a period-end stock-take that must value at a past date*; the `restrictOnDelete` FK hardening for the ledger (§6 gotcha).

## 5b. Module 10 — Utility Meters (done)

**The gap was a missing revenue path.** Readings were recorded (consumption even auto-derived from the prior reading, with a rollover guard) — but there was **no tariff on the meter**, so `cost` was hand-typed into a NOT-NULL column, and **nothing turned a reading into an invoice**. `InvoiceItemType::Utility → utility_revenue (41104001)` was already wired in the journalizer and chart: the system *intended* recharge and it was never finished, so every submetered EGP had to be re-keyed by hand (revenue-leak risk + an unverifiable number). Every submetering competitor (Yardi Utility Billing, RealPage, Conservice) bills recharge — MVP-critical.

**Built:** `utility_meters.rate_per_unit` (tariff; blank = a monitored-but-not-recharged landlord/common-area meter) · cost now **derives** as consumption × tariff in the reading form (still overridable, with a helper naming the rate) · **`BillMeterReadingService`** issues a dedicated recharge invoice (one `utility` line + 14% VAT — a taxable supply, unlike base rent), lock-safe and **idempotent** via `meter_readings.billed_invoice_id`/`billed_at`, refusing when the meter has no unit, no active lease, or no cost · a **"Bill to tenant"** action (double-gated visible + `abort_unless`) and a "Recharged / Not billed" column so un-billed consumption is visible.

**The trap caught while building it:** the recharge invoice is dated to the **consumption month**, which overlaps `MonthlyBillingService`'s already-billed probe — so a recharged month would have read as "already billed" and the monthly run would have **silently skipped that lease's base rent**. `utility` is now excluded from that probe, exactly as `percentage_rent`/`cam_*` are, with a regression test.

**Deferred (triggers):** tiered/slab tariffs (Egypt's electricity brackets) + time-of-use — *trigger: a meter whose provider bills in brackets*; standing/ minimum charges; RUBS-style apportionment of unmetered common usage; estimated readings + a cost-recovery reconciliation (recharged vs the actual provider bill); meter multiplier/CT ratio; bulk "bill all unbilled readings for the month".

### Deferred from the first-8 UX pass (recommendations, with triggers)
Non-blocking; captured so nothing is lost. **Leases:** an on-screen "working" for derived figures (next escalation date + escalated rent, levy EGP/month, deposit multiple), reactive escalation-rate field (hide for `none`/CPI, which the sweep skips), CAM cap-term resolved-ceiling column, renew-modal term preview, relation-manager empty states. **Billing:** distinguish the *lock-skipped* monthly run from "nothing to bill" (currently a green success with zeros), a read-only itemised invoice View, per-line VAT column, status filter for cancelled/credited/disputed. **Properties:** surface real occupancy % (`Asset::occupancyRate()` exists but is unsurfaced), a guard so force-deleting a unit/asset with lease dependents is a toast not an FK 500, confirm whether the Units resource should stay labelled "Tenant Directory". **Tenants:** a derivation tooltip on the on-account credit badge. **Project-wide:** exporter completion toasts are hardcoded English (systemic — own sweep).

## 6. Recurring gaps found (so the pass stays sharp)

From the first-8 UX pass, the same defects recur — audit every module for them:

1. **Bare, unverifiable numbers** — a computed money figure with no visible working (fixed with a service `explain()` + a native "View working" modal).
2. **Uncaught `DomainException` → Livewire 500** — service guards (closed period, terminal state, freeze) must be **caught** into a clean localized toast + the offending control disabled/hidden.
3. **All-or-nothing** operations where the client needs **partial/granular** control.
4. **Thin feedback** — toasts that omit the resulting state (new balance, which months re-trued, recover-vs-credit).
5. **Wrong/stale copy at a money moment** — modal descriptions that misstate what a button does.
6. **Missing i18n keys** — a key referenced in code but absent in en/ar renders raw to the user; always add EN+AR in the same change.

## 7. Competitive positioning (why this wins the deal)

- **Egyptian-native books** the global platforms fake: 14% VAT vs base-rent exemption per line, ETA e-invoicing, the 5% marketing levy, Arabic-first RTL.
- **A real GL** (double-entry, property/asset dimension, single-registry money sources, tie-out gates) — not a reporting bolt-on.
- **SLA → AP** automation and **CAM/percentage-rent** engines built for retail malls specifically.
- **Airtight per-property isolation** with self-enforcing conformance gates — a multi-mall operator can trust the walls.

---

*Kept in sync as the program advances. Per-module detail: [`docs/gap-analysis/`](gap-analysis/) · business model: [`docs/business-model/`](business-model/) · the single prioritized list: [`ROADMAP.md`](ROADMAP.md).*
