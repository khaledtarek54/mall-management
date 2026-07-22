# Property + Facility — module close-out ledger

> **What this is.** The living record of the module-by-module close-out sweep of Atriom's
> **property + facility** modules — the moat. Each module is taken to a documented **CLOSED**
> state and **never reopened**. Started 2026-07-19.
>
> Companion reads: [competitors/README.md](competitors/README.md) (the competitive benchmark this
> sweep closes against), [000-progress.md](000-progress.md) (Round 1/2 internal audit),
> [GENERIC-MODULES-CLOSED.md](GENERIC-MODULES-CLOSED.md) (the generic-ERP layer, already closed —
> out of scope here), [998-deferred-backlog.md](998-deferred-backlog.md) (parked items).

## The loop (per module)

1. **Baseline** — module doc + its `competitors/` deep-dive + Round-1/2 findings + code/tests.
2. **Gap sweep** — parallel lenses: **business-logic / domain-rules** (the deepest — are the encoded rules *correct* for an Egyptian mall, not just bug-free) · **operator + tenant UX / workflow** (configure-once-vs-choose-every-time, footguns, missing columns/filters, generated docs that need manual fixup — added at the owner's request 2026-07-19) · competitor parity · correctness/bugs · tie-out & property-isolation · coverage/conformance · FRD conformance. Every finding **adversarially verified**.
3. **Triage** — CLOSE-NOW / DEFER (with trigger) / OUT-OF-SCOPE (with reason).
4. **Close** — build the CLOSE-NOW items; `pest --parallel` green; conformance green; docs updated; committed.
5. **Sign-off** — mark CLOSED below with date + decisions. Never reopen.

## What "CLOSED" means

Not "feature-complete like Yardi." A defensible line, same as the generic-modules bar:

- **No verified open correctness gap** — nothing on the books is wrong, no money document lacks a
  correction path, no operator input can silently diverge business state from the GL, no
  property-isolation leak.
- **No open competitive-parity gap that is in-scope + worth building** — every gap vs the
  specialists is either closed, or **explicitly parked with a trigger**, or ruled out-of-scope with
  a reason. A deferred item is a decision on record, not debt a later audit rediscovers.
- **Coverage** — the module's rules are exercised by tests + guarded by a conformance gate where one
  is warranted.

## Verification rules (inherited from Round 2)

- An absence claim is a **hypothesis**, never a finding — search the whole repo for the *capability*,
  not the spelling; say where you looked.
- A finding needs a **failure scenario**: concrete inputs/state → wrong output. Prove by exploiting;
  prove the fix by reverting and watching the test fail.
- Money paths are driven through the **real service + `accounting:sync-ledger`**, with **reachable
  inputs** (a fixture that sets a column no form/service writes proves nothing).

## Scope + status

Ordered dependency-first. Generic-ERP modules (21–25, 28) are out of scope (already CLOSED).

| Order | # | Module | Status | Closed | Notes |
|---|---|---|---|---|---|
| 1 | 01 | Properties & Units | ✅ CLOSED | 2026-07-19 | 6 CLOSE_NOW fixed (2 HIGH), 1 parity DEFER; see closure record |
| 2 | 02 | Tenants | ✅ CLOSED | 2026-07-19 | 7 CLOSE_NOW fixed (3 HIGH: portal+API blacklist bypass, statement AR leak), 3 parity DEFER; see closure record |
| 3 | 04 | Leases | ✅ CLOSED | 2026-07-19 | 2 CLOSE_NOW fixed (dead rent-escalation — critical business-logic — + terminal-lease immutability); 8 DEFER incl. 4 domain-decision calls for the operator; see closure record |
| 4 | 05 | Billing & Invoices | ✅ CLOSED | 2026-07-19 | 5 CLOSE_NOW fixed (marketing-levy doc/behaviour reconciled by operator decision, parking GL account, legacy AR trap removed, run-summary count, late-fee floor test); 8 DEFER; VAT-on-levy = accountant question |
| 5 | 06 | Payments | ✅ CLOSED | 2026-07-19 | 9 CLOSE_NOW fixed (4 HIGH: reconciled/settled silently un-paid the invoice → canonical received-set across 10 money consumers incl. the books-reconciliation tie-out; back-dated receipt into a closed period; orphaned on-account receipt; duplicate-allocation data loss) + dedup + status-restriction + portal allocation display; adversarial review caught 1 low KPI-consistency miss (fixed); 8 DEFER (receipt voucher PDF, credit-balance auto-apply, autopay, batch entry); see closure record |
| 6 | 07 | Credit Notes | ✅ CLOSED | 2026-07-20 | 6 CLOSE_NOW integrity fixes (2 HIGH: cancel-with-applied-credit **double-counted** the sales-return → AR negative [empirically reproduced]; **cross-tenant apply** — no `note.tenant==invoice.tenant` guard + **standalone-note owner-statement leak**) + delete-guard on applied notes + closed-period guard on issue + server-side total recompute (anti-tamper); 4 completeness wins (credit-note PDF إشعار دائن, exporter+period filter, VAT-inherit from source invoice, guided **Reverse** action); introduced `credit_note_applications` (reversible link → un-apply on cancel + guided reverse, no second note); standalone notes now scoped to the tenant's leased properties; 2-lens pre-push review. VAT reversal + GL registration were verified CORRECT (not gaps). **DEFER:** ETA credit-note e-filing (gated on ETA go-live — biggest compliance hole), cash-refund path, maker-checker approval, portal web visibility, auto-apply. See closure record. |
| 7 | 08 | CAM reconciliation | ✅ CLOSED | 2026-07-20 | 5-lens sweep on a mature module (slices 1–2 already shipped). Fixed: **[HIGH] authz hole** — generateAllocations/markReconciled/bill gated only in visible(), so seeded viewer/owner (hold cam.view) could dispatch them via mountAction + re-open a reconciled pool; now double-gated (named predicate in visible()+action()) + 4 mountAction negative tests. **[HIGH/tax] recovery VAT** — recovery billed 0% while the monthly estimate was 14%; added per-pool `recovery_vat_rate` (default 14, frozen with the basis) applied to the true-up + over-collection credit. **[MED] void/correction path** — `voidAllocation` reverses the recovery invoice / credit note / fee invoice and unfreezes the pool (the freeze guard promised it but it didn't exist). Correctness lens: engine math CLEAN (tie-out invariant intact, Module-07 credit-apply change verified safe for CAM). **DEFER:** slice 3 (gross-up/basis/exclusions), estimate-derivation, move-in/out proration, tenant statement PDF. See closure record. |
| 8 | 09 | Tenant Sales & % Rent | ✅ CLOSED | 2026-07-22 | 3-lens sweep on a mature module (immediate-overage-billing already shipped). Fixed: **[CRITICAL] authz hole** — lock/dispute/voidLocked gated only in visible(), so seeded viewer/owner (hold tenant_sales.view) could bill/cancel an overage invoice via mountAction; now double-gated (named predicate) + 3 mountAction tests. **[HIGH] non-reporting leak** — a % rent tenant who never uploads escaped billing silently; added `sales:scan-missing-declarations` (monthly, idempotent) + a dashboard "missing sales declarations" card + a tenant reminder. **[GL invariant] added the real-sweep tie-out test** (Dr AR / Cr percentage_rent_revenue + void reversal). Correctness lens: core CLEAN (Module-07 void seam empirically safe). Fixed stale "billed next monthly cycle" copy. **DEFER (operator confirmed monthly breakpoints):** annual/cumulative reconciliation, sales-basis exclusions, estimated/deemed billing, tenant % rent PDF. See closure record. |
| 9 | 10 | Utility Meters | ⬜ Not started | — | |
| 10 | 11/26 | Maintenance (CM + PPM) | ⬜ Not started | — | Round-2 fixed F-77/78/96 |
| 11 | 12 | Vendors & Contracts | 🟡 Partial | — | COI gate shipped this session; full sweep pending |
| 12 | 29 | Procurement | ⬜ Not started | — | Round-2 fixed F-100..105 |
| 13 | 30 | Areas | ⬜ Not started | — | register shipped; routing to follow |
| 14 | 31 | Violations | ⬜ Not started | — | fine recorded-not-billed |
| 15 | 03 | Tenant Portal | ⬜ Not started | — | |
| 16 | 15 | Owner Requests & model | ⬜ Not started | — | |
| 17 | 32 | Owner Statements | 🟡 Partial | — | shipped this session; mgmt fee + multi-owner DEFERRED |
| 18 | 33 | Post-dated Cheques | 🟡 Partial | — | register-only shipped this session; NR accrual deferred |
| 19 | 16 | ETA e-invoicing | ⬜ Not started | — | ⚠️ mock/disabled — go-live risk |
| 20 | 17 | Reports | ⬜ Not started | — | |
| — | 13 | Marketing | ⬜ folded in | — | cross-cutting |
| — | 18/19/20 | RBAC / Notifications / Mobile API | ⬜ folded in | — | cross-cutting |

## Deferred parity backlog (from close-out sweeps)

Real competitor-parity gaps, verified but **DEFERRED** — each formally re-triaged (build vs.
park-with-trigger) when its owning module's close-out comes up.

| Gap | Kind | Owning module | Trigger to build |
|---|---|---|---|
| Field-technician mobile app (offline checklists + photos); `/api/v1` is tenant-only | parity/high | 20/26 | operator commits to in-house field crews on the app |
| PPM is calendar-only — no meter/runtime/condition-based triggers | parity/med | 26 | a mall with metered plant (chillers/generators) onboards |
| Vendor performance scorecard / SLA-compliance analytics | parity/med | 12 | operator wants vendor-renewal decisions data-driven |
| 3-way-match tolerance / variance hold (overbill above receipt clears silently) | parity/med | 29 | procurement volume makes silent overbilling a real loss |
| Asset criticality ranking (drives PM priority / SLA tier / spare stocking) | parity/med | 26 | PM/spares backlog needs prioritisation |
| Reorder-driven auto-purchase (low-stock alert never drafts a PR) | parity/med | 22/29 | store-keeping becomes a bottleneck |
| Fit-out permit grant/reject/conditions workflow (capture-only today) | parity/med | 15 | operator formalises tenant fit-out approvals |
| Internal labor / job-cost + photo-evidence completion gate on work orders | parity/med | 26 | operator wants true maintenance cost per asset |
| Multi-quote / RFQ bid comparison for capex procurement | parity/low | 29 | capex procurement volume warrants it |
| Vendor dispatch / provider portal (accept/quote/evidence loop) | parity/low | 12 | external-vendor dispatch becomes core |
| Penalty Filament actions (charge/waive) lack a dispatch/dual-gate test | coverage/med | 26 | fixed when module 26 closes |
| No conformance gate for `ApprovalPolicy` single-interpretation invariant | coverage/low | 28 | generic-ERP already closed; revisit only if approvals grow |
| Occupancy measured by unit count, not leasable area (no GLA / physical-vs-economic occupancy) | parity/high | 01 | a mall with a large vacant anchor + many small kiosks (where unit-count occupancy misleads), or the operator/owner asks for economic occupancy. `area_sqm` data already exists |
| No tenant-level merchandising / trade classification — tenant-mix keyed on the coarse unit space-category, not the tenant's retail category | parity/med | 02 | operator wants tenant-mix / category-exclusivity analysis (a leasing-strategy feature) |
| `tax_id` indexed but not unique + no tenant merge/dedupe — duplicates split AR and give one legal entity two ETA identities | parity/med | 02 | duplicate tenant records observed in practice; needs a merge tool (re-point leases/invoices/payments) before a uniqueness constraint |
| **Holdover** — a past-`expiry_date` lease stays `active`, keeps the unit occupied, but billing silently STOPS → rent-free holdover. **ALERT shipped (MVP):** ActionRequired dashboard card + Leases "Holdover" filter, so it's never silent. | **business_logic · billing DEFERRED** | 04 | **operator rule still needed for auto-billing:** auto-expire? month-to-month at a premium? — decide once frequency is known. The silent-leak risk is closed. |
| **Move-out proration** — mid-month termination bills the full final month (no refund) or drops it entirely; only the FIRST month prorates, and only via the single-lease action | **business_logic/high · DOMAIN CALL** | 04 | **operator rule needed:** prorate the final month + auto-credit the overpaid days? |
| **Stepped-rent schedule** — anchor deals need non-uniform per-period step amounts; today faked as manual charges (flat escalation % can't express steps) | **business_logic/high · DOMAIN CALL** | 04 | **operator rule needed:** model a first-class lease-level step schedule? (`LeaseRentChangeService`/`RentEscalationService` mutate only the latest base-rent charge — needs the effective-dated `LeaseCamTerm` pattern). *(Rent-free / fit-out grace itself: **BUILT** 2026-07-19 — `fit_out_months`.)* |
| **Per-unit rent on a multi-unit lease** — one `base_rent` spans all units | **business_logic/med · DOMAIN CALL** | 04 | **operator rule needed:** do multi-unit leases price per-unit, or is a single blended rent correct? |
| **`leases.billing_day` is dead** — fillable, date-mistyped, copied on renewal, read by nowhere; global `monthly_billing_day` drives cadence. Not a bug (no writer) | **correctness/low · wire-or-drop** | 04/05 | a real per-lease billing-day requirement (→ implement + retype as day-of-month), OR any new write path starts populating it (→ becomes a live mis-billing risk; drop it). |
| **Tenant PO / customer reference stamped on every invoice** — genuinely absent; notes workaround exists | **ux/parity/med** | 04/05 | onboard a corporate/government/anchor tenant whose AP requires a PO. Build as lease-persisted PO → snapshot onto invoice → PDF line, with an "ActionRequired: PO missing" surface (not a hard block). |
| **Auto-prorate the first partial month in the *scheduled* run** — batch never prorates (`prorate` defaults false) while the manual toggle defaults true; common flow (manual first invoice → batch skips) is already correct | **business_logic/low · policy** | 05 | mid-month commencements common enough that hands-off billing must prorate, or multi-mall rollout where per-lease manual first invoices don't scale. Needs its own regression tests + doc update. |
| ✅ **BUILT — Payment receipt voucher (سند قبض)** — printable/downloadable per-payment receipt (`ReceiptPdfService` + blade, EN/AR), admin + portal download gated on `isReceived()`. Amount-in-words (تفقيط) deferred (needs an Arabic number-to-words helper). | ~~parity/ux/med~~ **DONE** | 06 | — |
| ✅ **BUILT (surface) — Tenant credit balance** — `Tenant::creditBalance()` sums a tenant's received-payment unallocated remainders (already booked to Unearned Revenue); the portal "Credit on account" stat shows it. Display-only, no AR/GL mutation. | **business_logic** | 06 | — |
| ✅ **BUILT — APPLYING a tenant credit to an invoice** — the **Apply tenant credit** action on `EditInvoice` draws an on-account overpayment onto an open invoice. Done as designed: a NEW `TenantCreditApplication` GL source posting **Dr Unearned Revenue / Cr AR dated at application time** (open period) — registered in `LedgerPoster::JOURNALIZERS` + `SOURCE_DATE_COLUMNS` + `PropertyIsolation::OWNED`, settling the invoice via its **own** `recomputeTotals` summand (NOT `credit_applied_amount`). Capped at `min(balance, credit, requested)`, property-scoped, both actions gated `payments.edit` in visible()+action(). **Reverse applied credit** soft-deletes → GL voids + invoice re-opens; `VoidPaymentService` blocks refunding an already-applied surplus. A first attempt that instead extended the source payment's pivot (re-deriving its immutable closed-period entry → GL↔AR divergence) was reverted in review; this ships the correct dated-now design. `TenantCreditApplyTest` incl. a GL↔AR tie-out through real posting + the closed-source-period case. | ~~business_logic/high~~ **DONE 2026-07-20** | 06 | — |
| **DEFER — pure on-account receipt with no invoice** — needs `payments.lease_id` + conditionally relaxing the require-≥1-allocation guard + fixing the null-asset unearned posting | **business_logic/parity** | 06 | operators need to bank an advance before any invoice exists (overpayment-sourced credit is surfaced now). |
| **DEFER — auto-apply a credit on next invoice issuance** — hook the billing run, idempotent/lock-safe, on top of the now-built `TenantCreditApplication` apply source (manual apply ships today) | **business_logic** | 06 | prepayment volume makes hands-off desirable. |
| **Autopay / recurring / stored card**, and **batch (lockbox) receipt entry** — card-only + one-receipt-per-form today | **parity/med-low** | 06 | online-collection volume (autopay), or a daily cash-batch intake process (lockbox), makes them worth it. |

## Closure records

_One section per module as it closes, most recent first._

### Module 09 — Tenant Sales & Percentage Rent — CLOSED 2026-07-22

A 3-lens sweep on a mature module (the immediate-overage-billing invariant, void/re-lock, and file-first submission were already shipped & hardened). The **correctness lens found the core clean** — it ran a probe confirming the just-shipped Module-07 void seam is safe (an overage invoice settled by applied tenant credit reverses cleanly). The gaps were around the core.

**CLOSE_NOW fixed (2 + the GL invariant):**
- **[CRITICAL · authz] Dispatch hole (identical to CAM).** `lock` / `dispute` / `voidLocked` (`TenantSalesDeclarationsTable`) gated permission + status only in `visible()`. Filament's `mountAction()` never checks `isVisible()`, and seeded `viewer` + `owner` hold `tenant_sales.view` (the list renders) — so a read-only auditor or **owner Jawad could Lock (bill a tenant an overage invoice + post GL), Dispute, or Void a locked declaration (cancel its invoice)** via a crafted call. The existing "negative" test used `assertTableActionHidden` (checks only `visible()`), so it false-passed while the action was live. Fixed: named predicate (`canLock`/`canDispute`/`canVoid`) re-checked in `visible()` **and** `action()` (`abort_unless`); 3 `mountAction`+`callMountedAction` negatives (`SalesDeclarationActionAuthzTest`).
- **[HIGH · revenue assurance] Non-reporting tenant = silent leak.** A percentage-rent tenant who never uploads a report has no declaration → nothing bills and nothing alerts (the reporting-layer twin of the closed billing gap). Added `sales:scan-missing-declarations` (monthly on the 10th, idempotent — one reminder per lease+period; skips fit-out-grace leases) that reminds the tenant, plus an `ActionRequired` **"missing sales declarations"** dashboard card (property-scoped live count) so the leak is never silent again. Mirrors the existing `billing:scan-overdue-invoices` pattern.
- **[GL invariant] Real-sweep tie-out.** The `percentage_rent` overage → `percentage_rent_revenue` (41105001) source had no test driving the real `accounting:sync-ledger` sweep. Added `PercentageRentGlTieOutTest`: lock → sweep → assert Dr AR / Cr percentage_rent_revenue balanced + tied; void → sweep → assert the GL reverses.

Also: corrected the locked-notification copy ("billed in the next monthly cycle" → "billed on a separate invoice", since billing is immediate).

**DEFER (operator confirmed all current leases use MONTHLY breakpoints):** **annual / cumulative reconciliation** (the biggest gap — Atriom can only do per-period breakpoints, which over-bills a seasonal tenant's spike; *trigger: the first lease with an annual/cumulative breakpoint*) · structured sales-basis / exclusions (*a lease defining exclusions, or a basis dispute*) · estimated/deemed-sales billing on chronic non-reporting · a tenant-facing % rent statement PDF · a couple of LOW UX/coverage items (side-by-side report preview, lock-with-blank-figure guard, a real-race concurrency test).

### Module 08 — CAM Reconciliation — CLOSED 2026-07-20

A 5-lens sweep on an already-mature module (admin-fee + caps slices shipped & reviewed earlier). The **correctness/GL lens found the engine clean** — the hard tie-out invariant (`Σ allocated = total_actual_expense`, uncapped; admin fee a sibling line) is intact, and the just-shipped Module-07 credit-apply change was verified safe for CAM's negative-true-up path. The value was in the layer around the engine.

**CLOSE_NOW fixed (3):**
- **[HIGH · authz] Dispatch gate hole.** `generateAllocations` / `markReconciled` (`CamExpensePoolsTable`) + `bill` (`CamAllocationsRelationManager`) gated their permission + status only in `visible()`. Filament's `mountAction()` never checks `isVisible()`, and the seeded `viewer` + `owner` roles hold `cam.view` (the list renders) — so a read-only auditor or owner Jawad could dispatch them via a crafted call, and `generateAllocations`' action unconditionally set `status=reconciling`, **re-opening a reconciled pool**. Fixed: each write action re-asserts a named predicate (`canGenerate`/`canMarkReconciled`/`canBill`/`canVoid`) in **both** `visible()` and `action()` (`abort_unless`); 4 `mountAction`/`callMountedAction` negative tests (`CamActionAuthzTest`).
- **[HIGH · tax] VAT asymmetry on the recovery.** The monthly CAM estimate bills at 14% VAT (a taxable supply) but the year-end recovery billed at 0% — a systematic output-VAT under-collection, internally inconsistent with the estimate + the admin fee. Added a per-pool **`recovery_vat_rate`** (default 14%, frozen with the basis) applied to the true-up recovery line **and** the over-collection credit note (which reverses the VAT). `allocated_amount` is untouched (VAT rides on top), so the books-check tie-out holds. Real-sweep tests assert output VAT booked on a positive true-up and reversed on a credit (`CamRecoveryVatTest`). *(Ultimately a tax call for Jawad's accountant; the default matches the estimate so the two can't diverge, and 0% is available per pool.)*
- **[MED · correctness/UX] Void / correction path.** The freeze guard's own error told operators to "void the billed allocations first" — but no such action existed, so a late vendor invoice changing the actual was uncorrectable without DB surgery. Added `voidAllocation`: reverses the recovery invoice / credit note (un-applied first) / fee invoice and resets the allocation to `pending`, unfreezing the pool for a re-bill; a paid recovery invoice blocks it (refund first). Lock-safe + idempotent; the reversal is atomic (`CamVoidAllocationTest`).

Also: the module doc's stale claims corrected (§ scoping wrongly said "bypasses tenant scoping"; the caps/exclusions "not used" note).

**DEFER (with triggers):** **slice 3 — gross-up / GLA basis / alternative bases / per-lease exclusions** (occupied-area denominator = occupied tenants absorb vacant-space cost, the inverse of gross-up; latent while occupancy is high + caps protect tenants — *trigger: a lease negotiating gross-up/GLA, or sustained sub-90% occupancy*) · **estimate derivation** (per-tenant estimate from actual billed service-charge vs a typed area-split figure — *trigger: multi-rate tenants disputing a true-up*) · **move-in/out proration** (occupied-days weighting; departed tenants — *trigger: frequent mid-year churn*) · **tenant reconciliation-statement PDF** (*trigger: a tenant wanting the workings*). Two LOW workflow edges the books-check already surfaces (freeze omits period_year/asset_id; bill-without-regenerate on an all-pending pool).

### Module 07 — Credit Notes — CLOSED 2026-07-20

A 5-lens gap sweep (business-model/competitor lead, correctness, isolation, UX, coverage) → 6 CLOSE_NOW integrity fixes + 4 completeness wins, then a 2-lens adversarial pre-push review. The module's core was already strong (dual row-locking + capping on apply, finalized-note immutability, and — verified, not gaps — **correct VAT reversal** in the GL and a real-sweep tie-out on the apply path). The value was in domain-correctness the arithmetic hid.

**Business model (the lead lens).** A credit note is an *operator adjustment* (Dr Sales Returns / Cr AR), cleanly distinct from a tenant's overpayment *credit* (Dr Unearned Revenue) — no confusion bug. Owner statements correctly net down for asset-scoped credits. The two real business-model holes were the owner-statement leak on standalone notes (fixed) and the absence of ETA credit-note filing (deferred behind ETA go-live).

**CLOSE_NOW fixed (6 integrity):**
- **[HIGH · correctness] Cancel double-count.** Cancelling an invoice that had a credit applied left the note `applied` (its Dr Sales Returns still posted) **and** issued a second offsetting note → the sales-return was booked twice and AR went to −6,000 vs −3,000 (trial balance stayed balanced, hiding it). Reproduced empirically. Fixed by **un-applying** the original application (new `credit_note_applications` link) instead of issuing a second note — the note's single original entry now correctly represents the returned credit; GL↔AR ties out.
- **[HIGH · isolation] Cross-tenant apply.** `applyToInvoice` never checked `note.tenant == invoice.tenant`; the apply action re-validated *property* but not *tenant*, so a crafted dispatch could pay down another tenant's invoice with a tenant's credit. Fixed: fail-closed guard in the service + a tenant `abort(403)` in the action.
- **[HIGH · business_logic] Standalone-note owner-statement leak.** A lease-less note's Dr Sales Returns posted to the null/portfolio bucket, so applying it to a property invoice cut that property's AR while its income statement never saw the return → the owner was overpaid. Fixed: on first apply the note **binds to the invoice's property** (its return posts there) and can then only settle that property's invoices; standalone reads are scoped to the tenant's leased properties (was portfolio-wide, also a read leak that let a restricted operator void/issue another property's note).
- **[MED] Soft-deleting an applied note stranded `credit_applied_amount`** (Filament Delete doesn't route through void, which is hidden for applied notes) → permanent AR drift. Fixed: a `deleting` guard mirroring void.
- **[MED] Back-dated note bypassed `PostingDate`** → AR moved while the GL post silently failed. Fixed: `PostingDate::assertOpen(issue_date)` in `issue()` (+ the create-as-issued page path).
- **[MED] Create trusted client `total`/`balance`** (readOnly, not disabled) → a crafted submit could post a fabricated sales-return. Fixed: the note's totals are re-derived from its line items on save (reachable-path test).

**Completeness (4 wins, sibling-pattern copies):** credit-note **PDF** (إشعار دائن); **exporter + issue-date range filter** for the accountant; **VAT inherited** from the linked invoice's lines (no 0%-under-reversal); a guided **Reverse** action (un-apply) that resolves the applied-note void dead-end.

**Coverage:** 8 new close-out tests (incl. the cancel GL↔AR tie-out through real posting, the VAT-reversal real-path, cross-tenant/cross-property guards, delete + posting-date guards, guided reverse, deterministic double-apply race) + a reachable-path create-tamper test; 3 pre-existing tests updated from the old double-count/leak behavior. Full suite green, `billing:reconcile` ties out, PHPStan clean above baseline.

**DEFER (with triggers):** **ETA credit-note e-filing** (`documentType 'c'`, references the invoice UUID — *trigger: ETA leaves mock*; biggest compliance hole) · cash-refund/AP path (*tenant owed money with no open invoice*) · maker-checker approval (*second finance user / auditor*) · tenant-portal web visibility + tenant statement line (*self-service*) · auto-apply at billing run (*credit volume*) · per-reason GL accounts (*Jawad's real chart lands*) · multi-invoice apply modal.

### Module 06 — Payments — CLOSED 2026-07-19

First close-out with the **UX lens** live. Sweep: 6 lenses → 9 CLOSE_NOW (4 HIGH), 8 DEFER, 3 refuted. Then a 4-lens adversarial review of the diff before push (1 low finding, fixed; 10 refuted). The module was already hardened by prior audits (M06 F-25/F-26, GL-integrity Phase 1), so the value was in domain-correctness + UX.

- **[HIGH · business_logic/correctness] `reconciled`/`settled` silently un-paid the invoice + voided its GL cash entry.** The AR/GL core keyed on `status === 'captured'` while the portal `AccountBalance` widget already grouped `[captured, reconciled, settled]` — so a normal bank-reconciliation step (offered by the form + the state-machine doc) dropped the invoice back to unpaid and voided the ledger leg. Fixed with a **canonical `Payment::RECEIVED_STATUSES`** used by every money consumer: `recomputeTotals` (AR source of truth), `PaymentJournalizer`, both over-allocation guards, the immutability hook, `VoidPaymentService`, and — caught proactively, not by the sweep — **`BooksReconciliationService`** (the tie-out itself), the tenant/asset statement PDFs, `ReportService`, and `MonthlyRevenueTrend`. The review then caught the one remaining collections consumer (the trend's rate numerator, a raw pivot join with no status filter) → fixed. Tests: `PaymentReceivedStatusesTest`.
- **[HIGH · correctness] Back-dated receipt into a closed period relieved AR while its GL leg could never post** (the exact `PostingDate` failure shape — every sibling money document guards it, the Payment form didn't). Fixed: `CreatePayment` runs `PostingDate::assertOpen(payment_date)`. Test: `PaymentFormGuardsTest`.
- **[HIGH · isolation] Orphaned on-account receipt** — a zero-allocation captured payment posted as unearned revenue but was invisible in the property-scoped UI (which scopes payments via their invoices) with no way to apply it. Fixed: require ≥1 allocation (`minItems(1)` + server guard). Full credit-balance surfacing/auto-apply deferred.
- **[MED · correctness] Duplicate allocation rows silently collapsed** (pivot keyed by invoice id → last row won; summary showed the money as applied). Fixed: the pivot builder now **sums** duplicate invoice rows.
- **[MED · ux] Reversal bypassed the reason-gated Void action** — the editable status Select let `refunded`/`failed` reverse money with no reason/audit; and `reconciled`/`settled` un-paid (above). Fixed: status Select offers only the forward flow; reversals route through Void.
- **[MED · ux] Portal receipt hid what it settled** — the web-portal PaymentInfolist omitted the invoice allocation the email + mobile API already show. Fixed: added the allocation display.

**DEFER (8):** receipt voucher PDF (سند قبض) — *build when cash tenants need a counter receipt; mirror the invoice PDF* · tenant credit-balance surfacing + auto-apply advances · autopay / recurring / stored card · batch (lockbox) receipt entry · "record payment" action from an invoice · late-fee single-flat (known domain call) · localized/other minor UX. See the deferred backlog.

### Lease → Invoice UX sweep (follow-on) — 2026-07-19

First run of the new **UX / workflow lens** (added at the owner's request), targeted at the lease→invoice seam: *"what should be configurable on a lease so invoices generate right without manual fixups?"* 3 factual readers → 3 lens-proposers (ux / business-model / bug) → 19 candidates → adversarial verify + triage. **Outcome: the configurability candidates all DEFERRED** (each gated on an open operator/business decision, not an engineering choice); the two build-now items were both **confirmed money-path bugs** — fixed here.

- **[HIGH · bug] Invoices were born overdue on any off-the-1st run.** `generateInvoiceForLease` set `due_date = period_start + terms`, ignoring the real run date. A mid-month "Generate Invoice", a `monthly_billing_day > 1` scheduled run, or a back-fill produced a bill already past due → that night's `ScanOverdueInvoices` dunned the owner and `LateFeeService` added a 2% (min EGP 50) fee to a **same-day** bill. Fixed: `due_date = max(issue_date, today) + terms`. `issue_date` deliberately unchanged (it is the GL `entry_date` + the invoice-number `YYYYMM` — moving it is a separate accrual/numbering decision). Test: `InvoiceDueDateNotBornOverdueTest`.
- **[HIGH · bug] Single-lease "Generate Invoice" took no lock → double-bill race.** The bulk run holds `Cache::lock('billing:run:Y-m')`; the per-lease action didn't, and idempotency is a check-then-create with no DB unique key — so a double-click / second admin / manual-vs-scheduled race could each pass the probe and mint a duplicate. Fixed: `generateForLease` now contends on the same period lock (returns `skipped/run_in_progress` under contention). The DB dedup index is the harder belt-and-suspenders (MySQL has no filtered unique index; a plain `unique(lease_id, period_start)` would break the legitimate %-overage + annual-CAM-recovery coexistence) → deferred. Test: `SingleLeaseBillingLockTest`.

**BUILT since (from the config candidates):** ✅ **Fit-out / rent-free grace period** — `leases.fit_out_months` (operator chose full grace: rent + service + CAM + levy all suppressed for that many whole months from commencement; C1.5 resolved). Billing gate on the model (`periodInFitOut`/`firstBillableMonth`), shared with the "unbilled leases" card so a lease in grace is neither billed nor nagged; distinct `fit_out` skip reason for a clear UI message. Tests: `FitOutGracePeriodTest`.

**BUILT since (from the config candidates):**
- ✅ **Fit-out / rent-free grace period** — `leases.fit_out_months` (full grace; C1.5 resolved). SHIPPED 01ac386.
- ✅ **Lease-level billing frequency** — `leases.billing_frequency` (monthly/quarterly/semiannual/annual). Quarterly+ bill in advance, one invoice per cycle (whole recurring stack × months), commencement-anchored full cycles. Required rewriting the anti-double-bill probe to item-type exclusion (`alreadyBilledForMonth`) so a multi-month invoice can't slip past it; adversarially reviewed before push. Tests: `BillingFrequencyTest`.

**Still DEFER (lease-config, each gated on a business decision — see backlog):** stepped-rent schedule · auto-prorate the first partial month in the *scheduled* run · per-lease billing-day (`leases.billing_day` is dead — wire-or-drop) · tenant-PO-on-invoice · per-lease default invoice notes/legal text · localized invoice line-item descriptions (fix at render time). **Already handled (checked):** per-invoice notes + payment-instructions footer, "unbilled leases" ActionRequired card, security-deposit posting via `DepositTransaction`.

### Module 05 — Billing & Invoices — CLOSED 2026-07-19

Business-logic-led sweep of the money core. 5 CLOSE_NOW fixed; 8 DEFER; 4 refuted. Operator decisions taken: the 5% marketing levy **IS billed to the tenant** (fix the docs, not the code); VAT-on-levy flagged for the accountant.

- **[HIGH · business_logic] Marketing levy: code bills it, sign-off doc said it's internal/not billed.** The code (deliberate + tested) bills the 5% levy as a tenant line; `BUSINESS-RULES.md` (the accountant sign-off doc) + module-05 doc said the opposite — an accountant would have certified the reverse of what ships. **Operator confirmed billing is correct** → reconciled `BUSINESS-RULES.md` (rules + open-question 5) + module-05 doc §5/§9 to state the levy is billed + the budget accrues from the billed line. **VAT 0% vs 14% left as an explicit accountant question** at sign-off (no code change).
- **[MED · correctness] Parking revenue → misc income in the GL.** No `parking` role in the journalizer → parking lines lumped into misc_income. Added `parking_revenue` (41109001) + mapping; test drives real billing + sweep and asserts it lands in its own account, not misc.
- **[LOW · correctness] Legacy `Invoice::recalculateBalance()`** set balance directly, ignoring applied credits — a dead-but-endorsed trap on the AR single-source-of-truth. Removed (+ its 2 anti-pattern tests).
- **[LOW · correctness] Monthly-run summary counted a no-charge lease as "created"** (masking a silently-unbilled lease) → now counted "skipped", matching the single-lease path.
- **[LOW · coverage] Late-fee minimum-floor** (`max(50, balance×2%)`) had no test — added the small-balance case (floor is operative).

**DEFER:** move-out/trailing proration + auto-proration in the scheduled run (shared with mod-04), flexible billing cadence, escalating dunning, "voided invoice suppresses re-billing", + coverage gaps. **Accountant question:** marketing-levy VAT (0% vs 14%).

### Module 04 — Leases — CLOSED 2026-07-19

First sweep with the new **business-logic / domain-rules lens** (added at the user's request), which led the findings. 2 CLOSE_NOW fixed + regression-guarded (`Module04LeaseIntegrityTest`); 8 DEFER (4 of them domain-decision calls flagged for the operator); 0 refuted.

- **[CRITICAL · business_logic] Automated rent escalation was DEAD in production.** The daily sweep selects `WHERE next_escalation_date IS NOT NULL`, but no operator-reachable creation path ever seeded it (wizard omitted it, standard form has no field, renewal set it null) — so escalation never fired for a single real lease, and the tests only passed because fixtures hand-injected the column ("green over dead code"). Fixed with a single converged `Lease::creating` hook that arms `next_escalation_date = commencement + 1yr` whenever escalation is configured (`fixed_percent`/`cpi`, rate > 0); renewal now carries it forward. Tests rewritten to drive the REAL creation path. *This is exactly the class of bug the business-logic lens exists to catch — the feature "existed" but the rule never ran.*
- **[MED · business_logic] Terminal leases were editable** — a terminated/expired/cancelled/renewed lease could be re-opened + have its rent/status mutated via the standard Edit form (violates the "terminal records immutable" invariant). `Lease::updating` now blocks commercial/state changes on a terminal lease (notes/metadata + soft-delete still allowed); `EditLease` halts cleanly with a notice.

**DEFER — 4 domain-decision calls flagged for the operator** (business-logic gaps that need the operator's rule, not an engineering choice — see parity backlog): holdover / auto-expiry billing · mid-month move-out proration · rent-free / fit-out / stepped-rent schedules · per-unit rent on multi-unit leases. **DEFER — parity (4):** lease contract/document generation + e-signature · CPI/index-linked escalation · flexible billing cadence / anchor day · IFRS-16 straight-line recognition.

### Module 02 — Tenants — CLOSED 2026-07-19

Scoped sweep → 9 CLOSE_NOW (deduped to 7 distinct), 3 DEFER, 2 refuted. All CLOSE_NOW fixed + regression-guarded (`Module02TenantIntegrityTest`):

- **[HIGH · correctness] Blacklisted company kept PORTAL access** — the portal gate moved to `TenantUser` at the multi-user migration, but `TenantUser::canAccessPanel()` only checked the panel id, never the owning company's status (so `Tenant::canAccessPanel()`'s status check was dead). Now gates on `$this->tenant?->status === 'active'`.
- **[HIGH · correctness] Blacklisted company kept mobile-API access** — status was checked only at login; a mid-session blacklist left every token valid. New `EnsureTenantActive` middleware on the `auth:tenant-api` group re-checks per request + revokes the token.
- **[HIGH · isolation] Tenant Statement PDF + delinquency badge/filter + outstanding leaked cross-property AR** — a shared tenant's mall-B invoices/payments were shown to a mall-A-only operator. `TenantStatementPdfService::build()`, `Tenant::isDelinquent()/outstandingBalance()`, and the table column/filter now take an optional `visibleAssetIds()` scope (admin passes it; the tenant's own portal/API statement stays whole-company).
- **[MED · frd] Importer bypassed the tax_id format** the form enforces (the ETA go-live risk). Added the same regex to the importer.
- **[MED · coverage] tax_id un-normalised** — dashes reached ETA. `Tenant::setTaxIdAttribute` now stores bare digits; ETA builder tests updated to assert digits-only.
- **[LOW · correctness] Importer `type=foreign`** — unstorable enum value → silent row failure on MySQL. Restricted to `in:individual,company`.
- **[LOW · frd] FRD marked TEN-3 (tenant multi-user) deferred** though shipped. Corrected.

**DEFER (3):** tenant merchandising classification; tax_id uniqueness + merge/dedupe (×2) — see parity backlog. **Refuted (2).**

### Module 01 — Properties & Units — CLOSED 2026-07-19

Scoped sweep: 4 lenses → adversarial verify. 10 raw findings → 8 CLOSE_NOW (deduped to 6 distinct), 1 DEFER, 2 refuted. All CLOSE_NOW fixed + regression-guarded (`Module01OccupancyIntegrityTest`, 6 tests):

- **[HIGH · isolation] UnitImporter cross-property write** — resolved the target asset by CSV `asset_code` with `Asset::withoutGlobalScopes()` and no clamp, so a restricted user could create/overwrite another mall's units via import (a path that bypasses the form's `assertAssetInScope`). Fixed: `resolveVisibleAsset()` clamps to `visibleAssetIds()`; out-of-scope rows fail the `asset_code` rule; status column restricted to `vacant`/`maintenance`.
- **[HIGH · correctness] Unit double-booking** — the guard checked only `leases.unit_id` (master), so a unit held as an *additional* unit in a multi-unit lease could be re-leased. Fixed: `Unit::isActivelyLeased()` consults the `lease_unit` pivot; used in both the lease-form rule and `LeaseCreationService`.
- **[MED · correctness] Lease delete/restore strands occupancy** — `LeaseObserver` had only created/updated hooks, so soft-delete/restore/force-delete never re-projected unit status → phantom-occupied units, inflated owner occupancy. Fixed: `deleting` (captures unit ids into a **static** store — the observer instance isn't shared across events) + `deleted` + `restored` hooks recompute.
- **[LOW · correctness] Unit status self-heal** — a lease-less unit set to `occupied`/`reserved` via the form never reconciled. Fixed: Unit create/edit pages recompute on save (maintenance preserved).
- **[LOW · isolation] Units table property filter** — enumerated every mall's name + the ALL pseudo-asset. Fixed: `selectableAssetOptions()`.
- **[LOW · frd] FRD marked UNIT-1 (multi-unit lease) unbuilt** though shipped + heavily tested. Corrected in 3 places.

**DEFER:** occupancy-by-area (GLA) — see the parity backlog. **Refuted (2):** claims that grepped-missing but were implemented elsewhere.

### Sweep 0 — cross-module bugs caught while standing up the process (2026-07-19)

The first gap-sweep ran **unscoped** (a workflow arg bug), so it isn't a module-01 close-out — but
it surfaced three confirmed defects in this session's own work, all now fixed + regression-guarded:

- **[HIGH · mod 32] Owner-statement Revise → double payout** — revise rebuilt statements at
  version+1 with `paid_to_date = 0`, resetting the overpayment cap so the owner could be paid twice.
  Fix: `revise()` refuses while any non-cancelled disbursement exists (`OwnerStatementRun::hasActiveDisbursements()`, used in both the service guard and the action's `visible()`). Test: `OwnerStatementReviseDoublePayoutTest`.
- **[HIGH · mod 33] PDC invoice picker cross-property leak** — a cheque pinned to Mall A could link
  Mall B's invoice and settle it on clear. Fix: form picker scoped to the cheque's `asset_id` +
  `visibleAssetIds()`; model `saving` hook refuses a cross-property link (the real gate). Test: `PdcCrossPropertyInvoiceTest`.
- **[LOW · mod 08] CAM admin-fee doc formula** — said `pct × allocated`; engine uses `pct × capped_cost`. Doc corrected.
- **[HIGH · mod 33] PDC edit page 500** — the invoice picker plucked a non-existent `invoices.reference` column (invoices use `number`); latent until this session's demo seeded cheques made the edit page reachable. Fixed (`number` + balance label); guarded by `PdcEditPageRendersTest` (renders the form with a record — proven to fail on the old code).

Module 01's proper scoped close-out is re-run next (arg bug fixed).
