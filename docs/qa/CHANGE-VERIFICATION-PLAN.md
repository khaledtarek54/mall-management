# Change Verification Plan — June 2026 session

**Purpose.** Prove that everything changed in this work session is correct and safe to ship — not just "the tests pass," but the business behaviour is right and an accountant/operator can trust the numbers. This plan is layered: each layer is more expensive than the last, and a failure in a lower layer blocks the higher ones.

**Scope.** ~100 commits, 475 files (`git diff --stat 16ee1d6..HEAD`), across these workstreams:

| WS | Workstream | Risk | Primary docs |
|----|-----------|------|--------------|
| A | **Money-path hardening** (review passes 1–6): proration, CAM ± true-ups, credit notes, billing lock, payment over-allocation, late fees, AR widgets, marketing levy | **Highest** | [docs/MONEY-PATHS.md](../MONEY-PATHS.md) |
| B | **Tenant-request generalization**: MaintenanceRequest→TenantRequest, typed requests, CSAT, per-type SLA/routing/notifications | Medium | [docs/modules/11-maintenance.md](../modules/11-maintenance.md) |
| C | **RBAC / security / scoping**: access-control audit, authz gating, cross-tenant leak, attachment private disk, security headers | High | [docs/modules/18-rbac-scoping.md](../modules/18-rbac-scoping.md) |
| D | **Payments / Paymob / pay-link**: public payment link, QR, callback HMAC, channels | High | [docs/money/06-payments.md](../money/06-payments.md) |
| E | **ETA e-invoicing**: preflight, pluggable signing seam, EGS codes | Medium (mock) | [docs/money/11-eta-einvoicing.md](../money/11-eta-einvoicing.md) |
| F | **Mobile API**: notifications inbox, credit notes, home summary, FCM push | Medium | [docs/modules/20-mobile-api.md](../modules/20-mobile-api.md) |
| G | **QA tooling + docs**: PHPStan/Larastan, factories, OpenAPI/Scramble, axe a11y, the new docs/money reference | Low | [docs/qa/README.md](README.md) |

**Sign-off legend:** ⬜ not started · 🔄 in progress · ✅ passed · ❌ failed (link the issue). Each layer has an owner: **DEV** (engineer), **ACCT** (accountant / finance), **OPS** (Eltizam operator), **QA**.

---

## Layer 0 — Automated baseline `[DEV]`

The gate every commit already passes. Re-run all five before sign-off; all must be green.

| # | Command | Expectation | Last run (2026-06-29) |
|---|---------|-------------|------------------------|
| 0.1 | `vendor/bin/pest --parallel` | 1324 pass, 0 fail | ✅ 1324/1324, 4946 assertions |
| 0.2 | `composer analyse` | PHPStan L5, 0 errors | ✅ 0 errors |
| 0.3 | `php artisan migrate:fresh --seed` | clean seed, no exception | ✅ 33 leases, 245 invoices |
| 0.4 | `php artisan billing:reconcile` | all 6 checks OK, books tie | ✅ tie-out (AR 725,464.89, VAT 225,975.40) |
| 0.5 | `php artisan integrations:check` | reports ETA/Paymob config state (no charge) | ⬜ run pre-go-live with real creds |

> Re-run 0.4 after any money change. It is the operator's trust tool — see [docs/money/12-reconciliation-harness.md](../money/12-reconciliation-harness.md).

---

## Layer 1 — Regression → fix traceability `[DEV]`

Every bug fixed this session is locked by a named test. This table is the audit trail: if a fix regresses, the named test goes red. (All ✅ as of the Layer-0 run.)

| Bug (severity) | Fix commit | Regression test |
|----------------|-----------|-----------------|
| Prorated first month double-bills the month (HIGH) | `3057520` | `Regression/ProratedDoubleBillingTest` |
| Proration undercharge — 4dp factor rounding (HIGH) | `88816d4` | `Regression/ProrationFactorTest` + `Scenarios/BillingScenarioTest` (March-15 = 5483.87) |
| Quarterly/annual charge timing | — | `Regression/QuarterlyChargeTimingTest` |
| CAM negative true-up lost as a floored charge (HIGH) | `c022f9f` | `Regression/CamNegativeTrueUpCreditTest` |
| CAM positive true-up lost (CRITICAL, 4 iterations) | `e9e6235`→`477daee` | `Regression/CamPositiveTrueUpBilledTest` (immediate · ended-lease · no-preempt · exactly-once) |
| CAM allocation double-bill / lock race | `91fb4be` | `Regression/CamAllocationDoubleBillTest` |
| `credited` invoice double-refund (CRITICAL) | `88816d4` | `Regression/CancelledInvoiceCreditReversalTest` (the `credited` case) |
| Cancel-reversal loses credit / stale instance / phantom AR | `76bc843`,`ddd53a9` | `Regression/CancelledInvoiceCreditReversalTest` + `CreditNoteBalanceDriftTest` |
| Credit applied to non-live invoice / over-apply | `8a0a5c6` | `Regression/CreditApplyGuardTest` |
| Payment over-allocation (HIGH) | `0e3e609` | `Regression/PaymentOverAllocationGuardTest` |
| Late fee not idempotent / direct balance write | `bb1ceed` | `Regression/LateFeeIdempotentTest` |
| Paymob stale-amount on session | — | `Regression/PaymobSessionStaleAmountTest` |
| Payment receipt notified twice | — | `Regression/PaymentReceiptNotifyOnceTest` |
| Request reference race (LOW) | `a621550` | `Regression/RequestReferenceRaceTest` |
| Terminal-request comment / immutability | `511e754` | `Regression/MaintenanceRedirectImmutableTest` + `RequestTypeFixesTest` |
| Sales-declaration lock guard | — | `Regression/SalesDeclarationLockGuardTest` |
| App-wide authz enforcement gaps | `b250b08` | `Regression/AuthzEnforcementTest` + `Scenarios/AuthorizationMatrixTest` |
| Cross-tenant device-token leak + soft-delete crash | `a51952a` | `Regression/CrossTenantAndSoftDeleteFixesTest` |
| Multi-unit lease renewal / % rent type | — | `Regression/MultiUnitLeaseRenewalTest` + `RenewalPercentageRentTypeTest` |

**Action:** `vendor/bin/pest tests/Feature/Regression` (run the whole folder) → expect all green.

---

## Layer 2 — Money business validation `[ACCT + DEV]`

Hand-verifiable scenarios with **expected numbers**. Each has an automated proof AND a manual reproduction so finance can confirm intent, not just code. Run against a fresh `migrate:fresh --seed`.

| # | Scenario | Setup | Expected result | Auto-proof |
|---|----------|-------|-----------------|------------|
| 2.1 | **Proration** | Lease commences **Mar-15**, base rent 10,000/mo | First invoice rent line = **5,483.87** (17/31 days; amount rounded, not the factor) | `BillingScenarioTest` |
| 2.2 | **VAT split** | Invoice with rent 10,000 (exempt) + service charge 5,000 (14%) | VAT = **700**, total = **15,700**; rent contributes 0 VAT | `Scenarios/BillingScenarioTest` |
| 2.3 | **Marketing levy** | Base rent 10,000 | Levy line = **500** (5%, VAT-exempt); fund **accrued +500** (derived, not incremented) | `MarketingScenarioTest` |
| 2.4 | **CAM positive (recovery)** | Pool actual 50,000 vs estimated 30,000, single 100 m² lease | **+20,000** → an **issued recovery invoice** (period = reconciled year), traceability charge **is_active=false** so the monthly run never re-bills it | `CamPositiveTrueUpBilledTest` |
| 2.5 | **CAM negative (credit)** | Pool actual 20,000 vs estimated 30,000 | **−10,000** → **issued credit note**, **auto-applied FIFO** to open invoices; never a negative charge | `CamNegativeTrueUpCreditTest`,`CamScenarioTest` |
| 2.6 | **Credit cancel-reversal** | Apply 5,000 credit to an 11,400 invoice (balance→6,400); then **cancel** the invoice | Offsetting credit note **5,000 issued** (credit returned); cancelled invoice balance = **0**, excluded from AR | `CancelledInvoiceCreditReversalTest` |
| 2.7 | **`credited` is NOT reversed** | Apply 5,000, mark invoice **credited** | No offsetting note; settlement stays consumed (no double-refund) | same file |
| 2.8 | **Late fee** | Overdue invoice balance 10,000, grace (7d) elapsed | Fee = **max(50, 10,000×2%) = 200**; applied **once**; balance 10,200 via recomputeTotals | `LateFeeIdempotentTest` |
| 2.9 | **Payment over-allocation** | 5,000 payment, try to allocate 6,000 across invoices | **Blocked** under lock; no invoice over-paid | `PaymentOverAllocationGuardTest` |
| 2.10 | **Reconciliation tie-out** | After all of the above | `billing:reconcile` → **all 6 checks OK**; control totals match a hand-rolled AR sheet | Layer 0.4 |

**ACCT deliverable:** pick a real month of demo data, export the invoice + payment + credit-note lists, and reconcile the control totals from `billing:reconcile` against a spreadsheet. Sign 2.10 only when the spreadsheet matches to the cent.

> ⚠️ **The CAM positive true-up (2.4) needed four review iterations.** Give it extra scrutiny: confirm in the UI that a recovery invoice is `issued`, dated to the reconciled year, and that the *same lease's regular monthly invoice still appears* for the current month (no pre-empt, no double charge).

---

## Layer 3 — Manual UI walkthroughs `[OPS + QA]`

Click-throughs on `mall-management.test` (admin) and the portal. Confirm the screen matches the numbers.

**Admin (`/admin`)**
- [ ] Dashboard widgets render; **MallStats** Outstanding-AR + overdue count exclude cancelled/credited (Layer-2.6 invoice must NOT inflate AR); **ArAging** buckets exclude them too.
- [ ] Invoice edit → **Payment link** action produces a working public pay page (amount paid, not invoice total, on the status page).
- [ ] CAM pool → generate allocations → **Bill** a positive allocation → a recovery invoice appears; reconcile widget stays green.
- [ ] Credit note → apply to invoice → invoice balance drops; cancel that invoice → offsetting note appears.
- [ ] Marketing fund banner shows collected levy + available balance (dark + light mode).
- [ ] Delete actions visible to **super_admin only**; bulk-delete absent.

**Portal (`/portal`)**
- [ ] Read-only staff user cannot write; `is_admin` user can.
- [ ] Tenant raises a **non-maintenance request type**; sees per-type labels.
- [ ] Tenant rates a resolved request (**CSAT**); rating surfaces in admin.
- [ ] Invoice list/detail shows correct balance + pay link.

---

## Layer 4 — Security / RBAC / scoping `[QA + DEV]`

- [ ] `vendor/bin/pest tests/Feature/Security tests/Feature/Scenarios/AuthorizationMatrixTest.php tests/Feature/Scenarios/ScopingScenarioTest.php` → green.
- [ ] **Property scoping:** a property-scoped manager sees only their asset's tenants/units/invoices; "All Properties" mode aggregates (uses `visibleAssetIds()`).
- [ ] **Cross-tenant API = 404** (no existence enumeration): hit `/api/v1/...` with another tenant's id.
- [ ] **Attachments private:** a request attachment is NOT reachable by guessable URL; only via the authed tenant-scoped stream route.
- [ ] **Role grants:** non-super_admin cannot grant `manager`/super_admin; grants/revokes appear in the activity log.
- [ ] Security headers + CSP present (`SecurityHeaders` middleware); Sanctum token TTL set.

---

## Layer 5 — Tenant-request generalization `[QA]`

- [ ] `vendor/bin/pest tests/Feature/Scenarios/MaintenanceScenarioTest.php tests/Feature/Regression/RequestTypeFixesTest.php tests/Feature/Regression/RequestNotificationTypeLabelTest.php` → green.
- [ ] Rename integrity: `TenantRequest` model + tables; no stray `MaintenanceRequest` references break (the resource dirs retain the old name by design — confirm routes work).
- [ ] Per-type **reference prefix, SLA, routing** apply on admin create + portal + mobile.
- [ ] Notifications speak the **request's actual type** (a complaint never reads "Maintenance").
- [ ] Terminal (closed/cancelled) requests are immutable (no comments, no edits).

---

## Layer 6 — QA tooling & docs accuracy `[DEV]`

- [ ] `composer api-spec` regenerates `docs/api/openapi.json` with **no drift** (the contract test guards `/api/v1`).
- [ ] `npx playwright test --project=chromium` (incl. axe a11y) → green against Herd.
- [ ] PHPStan baseline only **shrinks** (no new entries added to grandfather list).
- [ ] **Docs spot-check:** open 3 random `docs/money/NN-*.md`; confirm each cited `file:line` and formula still matches the code (the CAM doc was reconciled to the pass-6 code; late-fee constants verified 2% / 7d / EGP 50).

---

## Sign-off matrix

| WS | Layer 0 | Layer 1 | Layer 2 | Layer 3 | Layer 4 | Owner sign-off |
|----|:------:|:------:|:------:|:------:|:------:|----------------|
| A Money | ✅ | ✅ | ⬜ ACCT | ⬜ | — | ________ |
| B Requests | ✅ | ✅ | — | ⬜ | — | ________ |
| C Security | ✅ | ✅ | — | — | ⬜ | ________ |
| D Payments | ✅ | ✅ | ⬜ | ⬜ | ⬜ | ________ |
| E ETA | ✅ | ✅ | — | — | — | ________ (mock) |
| F Mobile | ✅ | ✅ | — | ⬜ | ⬜ | ________ |
| G Tooling/docs | ✅ | — | — | — | — | ________ |

_Automated layers (0–1) are green now; the human layers (2–4) are the outstanding work._

---

## Residual risks & known limitations (carry into go-live)

1. **CAM positive true-up** — corrected four times; the harness now catches lost revenue, but **finance must validate the recovery-invoice model** (Layer 2.4) before relying on it in production.
2. **ETA e-invoicing runs in mock mode** — not certified; `integrations:check` + real EGS creds + the signing seam must be exercised in staging.
3. **Paymob** — verify against the sandbox (HMAC, callback, capture) before enabling the live channel.
4. **Email/notification delivery** is logged, not certified against a real SMTP/FCM in this environment.
5. **No DB unique `(lease_id, period)` constraint** yet — billing idempotency is enforced in code (overlap guard + run-lock); a DB constraint is a belt-and-suspenders backlog item.

> Definition of done for this plan: Layers 0–1 green (✅ now) **and** Layers 2–4 signed by ACCT/OPS/QA. Until then, treat the money paths as "verified by tests, pending business validation."
