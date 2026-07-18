# Lease lifecycle & billing — Atriom vs the retail-lease specialists

> **Domain:** commercial lease lifecycle (create / renew / terminate / escalate), master-unit/multi-unit leases, monthly recurring billing, VAT 14% + Egyptian ETA e-invoicing, proration, charge frequencies, rent-free/fit-out periods, one-off charges, lease document generation.
> **Benchmarks:** **Yardi Voyager** (commercial lease admin + recurring billing) · **Re-Leased** (modern cloud commercial lease management).
> Produced 2026-07-18. Atriom cells are grounded in `docs/modules/04,05,16` + `docs/money/00,01,02` and the cited source. Competitor cells are from general knowledge (cutoff ~Jan 2026) and marked **(verify)** where version- or edition-sensitive.
>
> **Legend:** ✅ full · 🟡 partial · ❌ absent · ⏭️ N/A or deferred

---

## 1. Capability matrix

| Capability | Atriom | Yardi Voyager | Re-Leased | Gap note |
|---|---|---|---|---|
| **Lease lifecycle state machine** (draft→pending→active→renewed/expired/terminated/cancelled) | ✅ 7-state enum; unit occupancy is a *projection* of lease status via `LeaseObserver`/`Unit::recomputeStatus()` | ✅ (verify) | ✅ (verify) | At-par. Atriom's occupancy-as-projection is clean. |
| **Master-unit / multi-unit lease** | ✅ `lease_unit` pivot, exactly one `is_master`, mirrored to `leases.unit_id`; renewal carries the full unit set | ✅ suites/multi-space (verify) | 🟡 multi-unit per property (verify) | At-par. But **single `base_rent` applies to all units** — no per-unit rent split (documented extension point). |
| **Renewal** (chain via `previous_lease_id`, duplicate charges + units, mark original `renewed`) | ✅ `LeaseRenewalService` | ✅ (verify) | ✅ renewals + reminders (verify) | At-par. |
| **Termination** (deactivate charges, optionally cancel *fully-unpaid* invoices; partially-paid needs credit note) | ✅ `LeaseTerminationService`, AR-safe | ✅ (verify) | ✅ (verify) | At-par; Atriom's "never orphan a paid_amount" guard is correct. |
| **Rent escalation — storage** (`escalation_rate`, `escalation_type` none/fixed_percent/cpi, `next_escalation_date`) | 🟡 fields exist | ✅ | ✅ | — |
| **Rent escalation — automated application** | ❌ **manual only** — no scheduled job; operator must call `LeaseRentChangeService::apply()` (module 04 §9 "Escalation does NOT auto-apply") | ✅ scheduled rent steps (verify) | ✅ automated rent reviews (verify) | **Real gap.** Stepped increases don't fire on a schedule. |
| **CPI / index-linked escalation** | ❌ `cpi` enum value reserved, "future"; no index feed | ✅ (verify) | ✅ CPI rent reviews (verify) | **Real gap.** |
| **Percentage / turnover rent** (artificial breakpoint + natural breakpoint; immediate overage invoice) | ✅ `PercentageRentCalculationService`; sales declarations; locked overage billed CAM-style | ✅ (verify) | 🟡 retail turnover rent, less deep (verify) | **Match/exceed.** Both breakpoint methods + immediate settlement is strong. |
| **Recurring monthly billing engine** | ✅ `MonthlyBillingService`: idempotent (period-overlap guard), per-lease transaction, `Cache::lock` + `WithoutOverlapping` queue middleware | ✅ | ✅ automated rent invoicing | **Match.** Triple double-bill defence is above-average discipline. |
| **Charge frequencies** | 🟡 `monthly` / `quarterly` (calendar-month-agnostic) / `annually` / `one_time` | ✅ arbitrary charge schedules (verify) | ✅ (verify) | **Gap:** no weekly/fortnightly/semi-annual; no per-lease billing *anchor day* (`billing_day` column is "reserved, unused" — run is always 1st-of-month). |
| **Proration** | 🟡 first-month only, day-granular, factor kept full-precision; **bulk run never prorates** (single-lease admin action only); no trailing/termination proration | ✅ both ends (verify) | ✅ (verify) | **Gap:** move-out / mid-month expiry bills the full month. |
| **Rent-free / fit-out / stepped-rent periods** | ❌ no first-class concept; must be *simulated* via a charge's `start_date`/`end_date` window | ✅ free-rent + step schedules (verify) | 🟡 rent-free periods (verify) | **Real gap.** No incentive/abatement schedule object. |
| **One-off / ad-hoc charges** | ✅ `one_time` charge (billed in the period its `start_date` falls) + manual invoice line items | ✅ | ✅ | At-par. |
| **VAT 14% + rent-exempt split** (service charge taxed, base rent + marketing levy exempt, per-charge `vat_applicable`/`vat_rate`) | ✅ enforced at charge creation + re-derived on every `InvoiceItem` save; sum-of-rounded | 🟡 tax engine configurable, not Egypt-native (verify) | 🟡 GST/VAT for UK/AU/NZ, not Egypt (verify) | **Exceed for Egypt.** Correct-by-construction; no global VAT switch (14% duplicated in 3 defaults, intentional). |
| **Egyptian ETA e-invoicing** (B2B JSON, EGS codes, T1/V009 VAT, tax_id guard, mock/preprod) | 🟡 **built + tested but DISABLED by default** (postponed 2026-07-03, not certified/live); no scheduled auto-submit; invoices only (no debit/credit-note submission) | ❌ no native ETA (verify) | ❌ no native ETA (verify) | **Unique differentiator, currently dormant.** Neither specialist ships Egyptian ETA. |
| **Late fees / arrears automation** | ✅ `LateFeeService` (idempotent, grace-days, once per invoice) + `billing:scan-overdue-invoices` + tenant/owner dunning | ✅ (verify) | ✅ arrears automation is a core strength (verify) | At-par; Re-Leased's dunning workflows are broader. |
| **Credit notes / adjustments** (durable `credit_applied_amount`, FIFO auto-apply, lock-safe, reversal on cancel) | ✅ well-engineered AR | ✅ (verify) | ✅ via Xero/QBO (verify) | **Match/exceed** on correctness. |
| **Lessor lease accounting — straight-line rent / IFRS 16 / ASC 842** | ❌ revenue = cash-basis billed amount; no smoothing of free-rent/steps over the term | ✅ FASB/IFRS lease-accounting module (verify, may be add-on) | 🟡 landlord-side, limited tenant-accounting (verify) | **Real gap** for owner (Jawad) revenue recognition. |
| **Security deposit** | 🟡 `security_deposit` + `security_deposit_received` are *informational* fields on the lease; not auto-applied to invoices (operator issues a credit note) | ✅ deposit ledger (verify) | ✅ bond/deposit tracking (verify) | **Gap** at lease level (a separate deposit register exists elsewhere in Atriom). |
| **Lease document / contract generation** | ❌ **upload only** — `SpatieMediaLibraryFileUpload` stores PDFs/Word; there is **no** generator, template merge, or e-signature | 🟡 document mgmt + correspondence (verify) | ✅ document templates + e-sign integrations (verify) | **Real gap.** Cannot produce a contract from lease data. |
| **CAM / OpEx recovery reconciliation** | ✅ pools, per-lease allocation, annual true-up (positive=recovery invoice, negative=auto-applied credit) | ✅ recoveries are a Yardi flagship strength (verify) | 🟡 OpEx budgets/recoveries, shallower (verify) | Covered in the CAM domain deep-dive; Atriom's true-up invariant is solid. |
| **Expiry / review reminders** | ✅ `leases:remind-expiring` (email + bell + push, idempotent, 90-day default) | ✅ (verify) | ✅ task/reminder engine is a core strength (verify) | At-par on the reminder itself; Re-Leased's task automation is richer. |

---

## 2. Architecture read

**Atriom's billing spine is genuinely well-built, and in places stricter than a specialist needs to be.** The money model is derived-truth throughout: `Invoice::recomputeTotals()` is the single source for `paid_amount`/`balance` (`paid = captured payments + credit_applied_amount`, `balance = max(0, total − paid)`), VAT is line-derived and header-summed (sum-of-rounded, not round-of-sum), and every concurrency-sensitive mutation follows a lock-and-re-check-inside-the-transaction pattern. The monthly run has three independent double-bill guards (period-overlap DB check, per-period `Cache::lock`, queue `WithoutOverlapping`). The proration fix (round the amount, not the factor; `abs()+1` inclusive day count) and the credit-note `credit_applied_amount` column are the kind of hard-won correctness details that a mature product accumulates. For a mall operator's **AR engine**, this is at or above the specialists on trustworthiness.

**Where it clearly exceeds Yardi/Re-Leased is the Egyptian fit.** The 14%-VAT / rent-exempt / 5%-marketing-levy split is correct-by-construction and captured per-charge at creation so a rate change never rewrites history; the ETA e-invoicing pipeline (B2B JSON, EGS item codes, T1/V009 tax blocks, business-tenant `tax_id` pre-validation, 5-decimal ETA precision distinct from the 2dp ledger) is a full localization neither specialist ships. That said, **ETA is disabled by default and runs in mock mode** — it is a built-but-dormant differentiator, not a live one, and submission is manual (no scheduled sweep, invoices only — no debit/credit-note documents).

**Where it is genuinely thin is classic commercial *lease administration* depth.** The lease model stores escalation terms but **does not apply them** — no scheduled escalation job exists, so stepped and CPI increases are an operator's manual `LeaseRentChangeService` call each anniversary; CPI has no index feed at all. There is no first-class rent-free/fit-out/abatement schedule (you simulate it with a charge's `start_date`), no per-unit rent allocation on a multi-unit lease (one `base_rent` covers all units), no flexible billing cycle or per-lease anchor day (`billing_day` is a reserved, unused column; the run is always 1st-of-month), and no proration on the *trailing* month. These are exactly the levers a commercial lease team reaches for daily, and Yardi/Re-Leased treat them as table stakes.

**Finally, there is no lessor-side lease accounting and no document generation.** Revenue is recognised on a cash/billed basis — there is no straight-lining of free-rent and step-ups across the term (IFRS 16 operating-lease / IAS-equivalent smoothing), which matters for the owner (Jawad) reporting layer. And leases carry *uploaded* documents but the system cannot *generate* a contract from lease data or route it for e-signature — a routine expectation in Re-Leased.

---

## 3. Top 5 gaps for a mall operator

1. **Automated rent escalation (stepped + CPI) — effort M.** Fields exist; the engine doesn't. A mall runs hundreds of leases with annual fixed-percent step-ups and (increasingly, given Egyptian inflation) CPI-linked reviews. Manually applying each on its anniversary is error-prone and leaks revenue when missed. A scheduled `ApplyLeaseEscalationsCommand` scanning `next_escalation_date ≤ now` and calling the existing `LeaseRentChangeService` is a natural, low-risk build; CPI adds an index-feed dependency.
2. **Lease document / contract generation + e-signature — effort M–L.** Today the system holds uploaded PDFs but cannot produce a lease contract, renewal letter, or termination notice from the lease's own data. For an operator onboarding tenants continuously, template merge + e-sign is a daily workflow the specialists own and Atriom entirely lacks.
3. **First-class rent-free / fit-out / stepped-rent schedules — effort M.** Retail deals routinely include a fit-out/rent-free period and a rent schedule that steps over the term. Simulating this via charge `start_date` windows is fragile, invisible to reporting, and can't express a step schedule. A lease-level abatement/step schedule object (that seeds/updates charges) closes it.
4. **Lessor revenue recognition / straight-line rent — effort L.** With free-rent and step-ups, cash-basis billed revenue misstates period revenue for the owner. Yardi has a lease-accounting module; Atriom has none. This is the owner-reporting gap that an auditor will raise once incentive-heavy deals exist (parallels the accounting-domain "second book" theme).
5. **Flexible billing schedules + per-unit rent — effort M.** No per-lease billing anchor day (all invoices date to the 1st), no semi-annual/weekly cadence, no trailing-month proration on move-out, and a single rent amount across all units of a multi-unit lease. Individually small; together they're the "our lease doesn't bill the way the contract says" friction a Yardi customer would feel on day one.

---

## 4. Net verdict

**At-par-to-behind on classic commercial lease administration** (escalation automation, rent-free/step schedules, document generation, lessor lease accounting, flexible billing cadence) — **ahead on Egyptian localization** (correct-by-construction VAT split + a full ETA e-invoicing pipeline no specialist ships, though it's currently dormant) **and ahead on billing-engine correctness** (derived-AR single source of truth, idempotent locked monthly run, durable credit handling, percentage-rent depth).
