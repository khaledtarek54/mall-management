# 06 — Atriom vs Yardi: the gap analysis

> Row by row, with a **verdict** and a **severity**. The Atriom column is grounded in the code, not
> in the module docs — two rows in the [July 2026 competitor
> analysis](../../gap-analysis/competitors/01-lease-billing.md) were already stale when this was
> written, and stale gap rows cost more than missing ones because they send people to rebuild what
> exists.

> **State as at 2026-08-09: all 43 stories shipped.** Every row that cited one now reads ✅ CLOSED.
> This document had itself gone stale — ~20 rows still said ❌ for work that had landed, which is the
> very failure the paragraph above warns about. If you are reading a ❌ here, check
> [05-user-stories.md](05-user-stories.md) before believing it.

**Verdicts**

| | Meaning |
|---|---|
| ✅ **KEEP** | At or above the benchmark. Do not touch it |
| ➕ **EXTEND** | The structure is right; it is missing capability |
| ♻️ **REBUILD** | The *model* is wrong. Fixing it inside the current shape produces a worse system |
| ⏭️ **DECLINE** | Yardi does it, Atriom should not. Reason stated |
| ❓ **DECIDE** | Blocked on a business/accounting ruling, not on engineering |

**Severity** — 🔴 wrong money or a blocked workflow · 🟠 real operator pain or misstatement ·
🟡 capability gap · ⚪ cosmetic/none

> **Verified against the code on 2026-08-09.** Every remaining open row in this file was checked by
> grepping the source, not by re-reading a module doc. That pass exists because this analysis had
> already produced **two false gaps** — CAM caps (which ship) and cumulative percentage rent (which
> ships) — and both came from trusting documentation. Three further rows were stale in the other
> direction: they described work this cycle had since completed.
>
> **A gap row is a claim about code. Check it against code.** If you add a row here, grep first and
> say what you grepped.

---

## 1. The lease record

| Capability | Yardi | Atriom today | Verdict | Sev |
|---|---|---|---|---|
| Lease lifecycle states | Status (Future/Current/Notice/Past) **and** type (new/renewal/expansion/holdover) as separate axes | One 7-state enum; `renewed` is a *status* | ➕ EXTEND — add `lease_type` alongside status | 🟡 |
| Occupancy as a projection of lease state | ✅ | ✅ `LeaseObserver` → `Unit::recomputeStatus()`, idempotent, observer-driven | ✅ **KEEP** — clean, and better factored than most | ⚪ |
| Multi-unit / multi-space lease | Space links are **date-ranged**, each with its own area | ✅ `lease_unit` carries `effective_from`/`effective_to`; `LeaseSpaceChangeService` opens and closes them, CAM apportions on time-weighted area | ✅ SHIPPED (LE-02) | ✅ |
| Per-space rent | Rent per space, or a rate × area | Flat amount **or** EGP/m²/yr, re-derived when the area moves | ✅ CLOSED (LS-04) | 🟢 |
| The six dates (sign / possession / rent commencement / term commencement / expiry / move-out) | ✅ | Two: `commencement_date`, `expiry_date`; fit-out is an integer month count | ➕ EXTEND — possession + rent-commencement are the two that matter | 🟠 |
| Area as a first-class, date-ranged number | ✅ drives rent, recoveries, breakpoints | `units.area_sqm`, static, used only by CAM | ➕ EXTEND | 🟠 |
| Double-booking prevention | ✅ | ✅ `lockForUpdate()` on the **contended unit row** in both activation paths, with a standing test that the lock is still there | ✅ **KEEP** — this is better than the benchmark deserves credit for | ⚪ |
| Terminal-lease immutability | ✅ | ✅ `Lease::updating` blocks non-allow-listed changes once terminal | ✅ **KEEP** | ⚪ |
| Lease documents | Abstraction + clause library + AI extraction | Media upload, private disk, gated | ➕ EXTEND much later (XX-05) | 🟡 |

---

## 2. Charges — the structural gap

| Capability | Yardi | Atriom today | Verdict | Sev |
|---|---|---|---|---|
| **Date-ranged charge schedule, many rows per code** | ✅ the whole term written on day one | ✅ `charges` is date-ranged; `ChargeScheduleService` closes the row in force and opens the next, and `projectTermEscalations()` writes the whole ladder at signing | ✅ **CLOSED (LS-01, LS-02)** | ⚪ |
| Billing reads the row effective for the period | ✅ | ✅ **already does** — `chargeAppliesToPeriod()` honours `start_date`/`end_date` ([`MonthlyBillingService.php:402`](../../../app/Services/MonthlyBillingService.php#L402)) | ✅ **KEEP** — this is why the rebuild is small | ⚪ |
| Charge codes as configuration (code → GL account, tax, recoverable, deposit) | ✅ an accountant adds one | **Half closed 2026-08-10.** *Re-pointing* is now configuration: `AccountMappingResource` (خريطة الترحيل) lets the accountant aim any of the 48 posting roles at a different account, globally or per property — previously the table was seeded and unreachable, so this took SQL. What still needs a developer is **adding a new charge code**: `InvoiceItemType` is a PHP enum and `InvoiceJournalizer::REVENUE_ROLE` maps code → role in code | ➕ EXTEND — a `charge_codes` table resolving through the existing `AccountMapping` | 🟡 |
| Escalation: stored | ✅ %, amount, index, **floor/ceiling**, compounding | **Collar CLOSED 2026-08-10.** `escalation_floor_rate` / `escalation_ceiling_rate` clamp whatever rate the sweep is about to apply (`RentEscalationService::collar()`), so they bite before CPI exists — on a `fixed_percent` lease the ceiling is a rail against a mistyped rate. A floor above the ceiling is refused at the model. Still missing: escalation by fixed **amount**, and compounding is implicit (each step multiplies the current rent) | ➕ EXTEND — fixed-amount escalation | 🟡 |
| Escalation: applied automatically | ✅ generates the future row | ✅ `leases:apply-escalations` — idempotent, row-locked, re-checked in-transaction, and it now APPENDS a schedule row instead of overwriting one | ✅ **CLOSED (LS-03)** | ⚪ |
| CPI / index escalation | ✅ with an index source | Skipped by design (no feed; refuses to invent a number) | ➕ EXTEND — index register + **collar**, or leave it out honestly | 🟡 |
| Free rent / abatement | Per charge code, date-ranged, feeds straight-line | ✅ `fit_out_scope` — `rent_only` (net abatement, **default for new leases**) or `gross`; existing leases keep gross | ✅ **KEEP** — per-charge-code abatement rows remain future work | ⚪ |
| Billing frequency | Per charge row | Per lease (`monthly`/`quarterly`/`semiannual`/`annual`), cycle-anchored, billed in advance, capped at expiry | ✅ **KEEP** — genuinely careful work | ⚪ |
| Proration — commencement | ✅ | ✅ **fixed 2026-08-08** — `billForPeriod()` passes `prorate: true`; the flag survives as the single-lease override | ✅ **KEEP** | ⚪ |
| Proration — termination/expiry | ✅ | ✅ `MonthlyBillingService::monthsCovered()` is the ONE rule for both the bill and the trailing credit | ✅ **CLOSED (MF-02)** | ⚪ |
| Holdover billing | ✅ amendment at 125–200% | ✅ `ConvertLeaseToHoldoverService` bills a month-to-month row at the contracted multiple (default 150%, settings-driven). Operator-confirmed, never automatic | ✅ SHIPPED (LE-04) | ✅ |
| Batch review before posting | ✅ edit/delete the proposed charges before commit | ✅ `/admin/billing-run-preview` — a dry run computed by the same `planInvoiceForLease()` the post persists (2026-08-08). Yardi still lets you EDIT the batch; Atriom's is review-then-commit | ✅ **KEEP** | ⚪ |
| Double-bill prevention | Batch + post month | ✅ `Cache::lock` on the period + `WithoutOverlapping` + period-overlap guard, and the manual action **contends on the same lock** | ✅ **KEEP** — triple defence, above benchmark | ⚪ |

---

## 3. Amendments & lease events

| Capability | Yardi | Atriom today | Verdict | Sev |
|---|---|---|---|---|
| Mid-term amendment as a first-class record | ✅ expansion/contraction/relocation/holdover/rent-mod, with effective date, reason, document | ✅ append-only `lease_events` with all five, plus abatement and termination; the `notes` append is gone | ✅ SHIPPED (LE-01) | ✅ |
| Point-in-time reconstruction of a lease | ✅ | ✅ `Lease::eventsAsOf($date)` + the date-ranged charge schedule and premises | ✅ SHIPPED | ✅ |
| Renewal | Amendment **or** new record (configurable) | New lease chained by `previous_lease_id`, carrying the full unit set and duplicating charges; original → `renewed` | ✅ **KEEP** — defensible and arguably cleaner for a mall. **The gap is amendments, not renewal** | ⚪ |
| Termination | ✅ with a final account | ✅ deactivates charges, cancels only **fully-unpaid** invoices (correctly refusing partially-paid ones) | ➕ EXTEND with MF-02 + MF-03 | 🟠 |

---

## 4. Options & critical dates

| Capability | Yardi | Atriom today | Verdict | Sev |
|---|---|---|---|---|
| Renewal / termination / expansion / ROFR options with notice windows | ✅ | ✅ `LeaseOption` + panel on the lease (shipped 2026-08-09) | ✅ **KEEP** | ⚪ |
| Alert before the **earliest notice date** | ✅ | ✅ `leases:scan-option-windows` daily — opening · closing · lapsed | ✅ **KEEP** | ⚪ |
| Space encumbrance | ✅ | ✅ an option marks the unit spoken-for; the picker warns rather than blocks, because a landlord may legitimately let encumbered space | ✅ **CLOSED (OP-03)** | ⚪ |
| Insurance-certificate expiry on the tenant | ✅ | 🟡 exists for **vendors** (`VendorDocument` + expiry scan); not for tenants | ➕ EXTEND — reuse the vendor pattern | 🟠 |
| Clause abstract (co-tenancy, kick-out, exclusivity, radius) | ✅ | ❌ PDF only | ➕ EXTEND, later | 🟡 |

---

## 5. AR, cash & the money core

| Capability | Yardi | Atriom today | Verdict | Sev |
|---|---|---|---|---|
| **Single source of truth for settlement** | charge open balance | ✅ `Invoice::recomputeTotals()` — one function, `saveQuietly`, override statuses respected | ✅ **KEEP. Do not touch** | ⚪ |
| Credit durability through recompute | — | ✅ `credit_applied_amount` re-added inside recompute | ✅ **KEEP** | ⚪ |
| Over-allocation race guard | — | ✅ `lockForUpdate` + re-check inside the pivot-sync transaction, 0.01 epsilon | ✅ **KEEP — above benchmark** | ⚪ |
| Cross-tenant allocation guard | — | ✅ model-level | ✅ **KEEP** | ⚪ |
| Credit note reversal | offsetting credit charge | ✅ **un-applies the original** via `credit_note_applications` rather than stacking a second document | ✅ **KEEP — better than the benchmark** | ⚪ |
| On-account / open credit | ✅ auto-applies to the next charge | ✅ `TenantCreditApplication`, its own dated GL document (`Dr Unearned / Cr AR`) | ➕ EXTEND — no *automatic* application order | 🟡 |
| Automatic receipt application order | ✅ credits → priority → oldest | ✅ `InvoiceItemSettlement::TYPE_PRIORITY` — rent first, late fees last — with explicit per-line allocation overriding it | ✅ **CLOSED (MF-06)**. Order is a constant, not an AR setting; see the story | ⚪ |
| Item-level payment allocation | ✅ (charge-level natively) | ✅ `invoice_item_payment`, DERIVED from `paid_amount` so item outstandings always sum to `balance` | ✅ **CLOSED (MF-06)** | ⚪ |
| Line-level dispute | ✅ | ✅ `invoice_items.disputed_at` — out of the late-fee base, shown beside the aged figure, visible on the portal | ✅ **CLOSED (MF-07)** | ⚪ |
| **Bad-debt write-off** | ✅ `WRTOFF` → bad-debt expense | ✅ `InvoiceWriteOff` + `WriteOffInvoiceService`, own GL source, reversible (shipped 2026-08-09) | ✅ **KEEP** | ⚪ |
| Late fees | per charge code, per-lease override | ✅ idempotent + lock-safe, with per-lease grace/rate/minimum overrides | ✅ **CLOSED (MF-08)** | ⚪ |
| Bounced / NSF | ✅ reverses + fees | ✅ `Payment.status = bounced` + module 33 PDC lifecycle. **The reversal half is better than Yardi's**: no `Payment` exists until a cheque CLEARS, so a bounce has nothing to un-apply — Voyager enters a receipt and then reverses it. **The FEE half is genuinely absent.** | ✅ **CLOSED 2026-08-10** — `BillBouncedChequeFeeService` + `nsf_fee` charge code | ⚪ |
| Bank deposit batches | ✅ | ❌ | ⏭️ **DECLINE** — PDCs and transfers dominate here (XX-06) | ⚪ |
| Post-dated cheques | 🟡 regional builds | ✅ full register, lodging, maturity, clear/bounce, invoice-lock + over-allocation backstop | ✅ **KEEP — exceeds Yardi for this market** | ⚪ |
| Tenant statement | ✅ | ✅ `TenantStatementPdfService` | ✅ KEEP | ⚪ |
| AR aging | ✅ by charge code | ✅ bucket summary + drill-down + per-tenant collections worklist, **and aging by charge type** (`ArAgingByType`) | ✅ **CLOSED (RR-03)** | ⚪ |

---

## 6. Deposits

| Capability | Yardi | Atriom today | Verdict | Sev |
|---|---|---|---|---|
| Deposit as a GL liability | ✅ | ✅ `DepositTransaction` → `Dr Cash\|Bank / Cr Deposits Held`, journalized, numbered, bilingual | ✅ KEEP | ⚪ |
| Refund / forfeit | ✅ | ✅ both paths posted | ✅ KEEP | ⚪ |
| **Itemised move-out disposition** | ✅ one document nets damages vs refund | ✅ one statement nets AR against the deposit and freezes into the termination event | ✅ **CLOSED (MF-03)** | ⚪ |
| Held vs contractual reconciliation | ✅ | ✅ the move-out statement reconciles the register against the lease's contracted deposit | ✅ **CLOSED (MF-03)** | ⚪ |
| Deposit top-up on escalation | 🟡 | ❌ | 🟡 note only | 🟡 |
| Interest-bearing / segregated | ✅ | ❌ | ⏭️ DECLINE — not an Egyptian requirement | ⚪ |

---

## 7. Recoveries & percentage rent

| Capability | Yardi | Atriom today | Verdict | Sev |
|---|---|---|---|---|
| Pool sourced from GL expense accounts | ✅ with drill-down | ✅ `cam_pool_accounts` + `expense_basis = ledger`; the total is a query over posted lines | ✅ **CLOSED (RC-01)** | ⚪ |
| Multiple pools per property | ✅ | ✅ keyed `(asset_id, period_year, pool_code)`, each with its own participants, fee, VAT and cap | ✅ **CLOSED (RC-02)** | ⚪ |
| Configurable denominator | ✅ GLA / occupied / fixed / stated | ✅ `denominator_basis` — occupied (default) / GLA / fixed — frozen onto the pool as `denominator_used_sqm` | ✅ **CLOSED (RC-03)** | ⚪ |
| Gross-up | ✅ | ✅ `gross_up_pct` × the variable share, which a ledger pool derives per ACCOUNT from `cam_pool_accounts.cost_nature` | ✅ **CLOSED (RC-04)** | ⚪ |
| Caps: absolute, YoY, base year, compounding | ✅ | ✅ **`LeaseCamTerm`, effective-dated, tighter ceiling wins, landlord absorbs `cap_absorbed`** | ✅ **KEEP** — *the 2026-07-18 doc calling this absent is stale* | ⚪ |
| Cap scoped to controllable expenses; cumulative headroom | ✅ | ✅ `cap_scope` + `cap_carry_forward`; unused headroom banks forward | ✅ **CLOSED (RC-07)** | ⚪ |
| Admin / management fee | ✅ | ✅ `admin_fee_pct` per pool, applied to the **capped** cost so it cannot re-breach the cap, VAT-rated per pool | ✅ **KEEP** | ⚪ |
| Share freezing / tie-out on re-run | — | ✅ frozen participant set + frozen `pro_rata_share_pct` | ✅ **KEEP — above benchmark discipline** | ⚪ |
| True-up settlement | ✅ | ✅ positive bills immediately on its own invoice (correct — the lease may have ended); negative becomes a credit auto-applied FIFO | ✅ **KEEP — hard-won, do not re-open** | ⚪ |
| **Re-estimate next year** | ✅ | ✅ the reconciliation moves next year's estimate, and `estimate_basis = billed` reads what tenants were actually invoiced | ✅ **CLOSED (RC-05)** | ⚪ |
| Tenant reconciliation statement | ✅ auditable | ✅ a statement showing the pool, the exclusions, the gross-up, the share basis and the arithmetic | ✅ **CLOSED (RC-06)** | ⚪ |
| **CAM area = summed lease area** | ✅ | ✅ **fixed 2026-08-08** — `Lease::totalAreaSqm()` sums the `lease_unit` pivot on both numerator and denominator | ✅ **KEEP** | ⚪ |
| % rent: natural + artificial breakpoint | ✅ | ✅ both | ✅ KEEP | ⚪ |
| % rent: **cumulative YTD + annual settle-up** | ✅ | ✅ **`percentage_rent_frequency = 'annual'`** — canonical chronological marginals (`overage(YTD) − overage(prior YTD)`, each floored at 0) that sum to the year's overage, with `retrueAnnualYear()` re-attributing every month on any lock/void. Settable on the lease form. *This row said "period basis only" until 2026-08-09 — it was **WRONG**, read off a stale doc line instead of the code* | ✅ **KEEP** | ⚪ |
| % rent: tiers | ✅ | ✅ `LeasePercentageRentTier` ladder, each band charging only the sales within it (shipped 2026-08-09) | ✅ **KEEP** | ⚪ |
| % rent: deductions/offsets | ✅ | ✅ `percentage_rent_deductible_types`, netted against the gross overage and floored at 0 | ✅ **KEEP** | ⚪ |
| % rent: estimated sales when undeclared | ✅ | ✅ `sales:estimate-missing` — the tenant's own trailing average, marked as an estimate, never auto-locked | ✅ **KEEP** | ⚪ |
| Sales declaration capture + lock | ✅ | ✅ portal + admin, locked, immediate overage billing | ✅ KEEP | ⚪ |

---

## 8. The one architectural decision: invoice-level vs charge-level AR

**Yardi:** the posted charge is the receivable. **Atriom:** the invoice is the receivable, with
items beneath it.

### Why Atriom's choice is right and should not be reversed

1. **ETA e-invoicing is invoice-centric.** Egypt's e-invoicing regime files a *document*. An AR
   model with no invoice must synthesise one to file, and then reconcile the synthetic document
   against the receivables it represents — a second source of truth, which is the exact failure
   `Invoice::recomputeTotals()` exists to prevent.
2. **VAT is computed and reported per document**, not per receivable.
3. **The invariants are already built and tested against the invoice.** `recomputeTotals`, the
   over-allocation guard, credit durability, the credit-note un-apply, the AR-exclusion status
   rules, the GL journalizer. Moving the AR atom would invalidate all of them at once.
4. **The tenant experience is document-shaped.** Tenants pay invoices, dispute invoices, and file
   invoices with their own accountants.

### What it costs, and how to buy most of it back

| Yardi gets | Atriom loses | Recover it by |
|---|---|---|
| Aging by charge type | one balance per invoice | **RR-03** — group aging by item type once **MF-06** exists |
| Targeted settlement ("rent only") | payment lands on the invoice | **MF-06** — item-level allocation *beneath* the invoice, never replacing it |
| Line-level dispute | invoice-level `disputed` only | **MF-07** — a disputed item excluded from the late-fee base |
| Line-level write-off | none | **MF-04** — write-off at item granularity where it matters |

**The rule for anyone implementing MF-06:** item allocation is *detail*, not truth.
`Invoice::recomputeTotals()` stays the single source of `paid_amount` and `balance`. If item
allocations and the invoice total can ever disagree, the design is wrong.

---

## 9. Reporting

| Report | Yardi | Atriom | Verdict | Sev |
|---|---|---|---|---|
| **Rent roll** | ✅ the most-used commercial report | ✅ `/admin/rent-roll`, as-at-a-date, reading the same schedule row billing does (shipped 2026-08-09) | ✅ **KEEP** | ⚪ |
| Lease expiration schedule | ✅ | ✅ `ReportService::expirationSchedule()` + page — area AND income at risk per year, holdovers in their own bucket | ✅ **CLOSED (RR-02)** | ⚪ |
| Stacking plan | ✅ | ❌ | 🟡 later | 🟡 |
| Occupancy / vacancy | ✅ | 🟡 unit status counts | ➕ EXTEND | 🟡 |
| AR aging | ✅ by charge code | ✅ invoice-level page + drill-down + CSV, **and by charge type** | ✅ **CLOSED (RR-03)** | ⚪ |
| **Occupancy cost %** | ✅ | ✅ `ReportService::occupancyCost()` + page | ✅ **CLOSED (RR-04)** | ⚪ |
| Sales MTD/YTD/MAT, like-for-like | ✅ | ✅ `SalesAnalytics` — MAT and like-for-like side by side, because the gap between them is the story | ✅ **CLOSED (RR-05)** | ⚪ |
| Straight-line schedule | ✅ | ✅ `StraightLineRentService::scheduleFor()` — the whole term's recognition, possible only because LS-01 writes the ladder at signing | ✅ **CLOSED (RA-02)** | ⚪ |
| Owner statements | Investment Manager | ✅ three-tier accrual-GL spine, journalized | ✅ KEEP | ⚪ |
| Revenue forecast | ✅ Forecast Manager | ❌ | 🟡 falls out of LS-01 almost free | 🟡 |

---

## 10. Accounting & GL

| Capability | Yardi | Atriom today | Verdict | Sev |
|---|---|---|---|---|
| Every money source posts to the GL | ✅ | ✅ **`LedgerPoster::JOURNALIZERS` as a single registry, with a conformance gate that fails the build on an unregistered source** | ✅ **KEEP — stricter than the benchmark** | ⚪ |
| Closed-period refusal | ✅ | ✅ `PostingDateGuards`, per-source, conformance-gated | ✅ KEEP | ⚪ |
| **Post month ≠ document date** | ✅ | ✅ `posting_month_overrides` — one override for all 24 GL sources; the entry moves, the document keeps its date | ✅ **CLOSED (MF-05)** | ⚪ |
| Multiple books | ✅ | ❌ single book | ⏭️ **DECLINE** (XX-02) | ⚪ |
| Charge code → GL account as data | ✅ | 🟡 `AccountMapping` (key → account, per-property override) exists, but the item-type → key map is hard-coded | ➕ EXTEND — join the two | 🟠 |
| **Straight-line rent / deferred rent** | ✅ | ✅ `StraightLineRentService` (EAS 49 / IFRS 16), settings-gated and shipped OFF; invoices are byte-identical either way | ✅ **CLOSED (RA-01, RA-02)** | ⚪ |
| Bad-debt expense | ✅ | ✅ `bad_debt_expense` role, posted by `InvoiceWriteOffJournalizer` | ✅ **KEEP** | ⚪ |
| VAT | region packs | ✅ settings-driven, origination-only, literal-banned by a gate | ✅ KEEP | ⚪ |
| Money-record deletion | soft controls | ✅ **refused at the model, gated in CI, with a stated reason per model** | ✅ **KEEP — exceeds the benchmark** | ⚪ |

---

### The NSF fee — specced and SHIPPED (2026-08-10)

Voyager posts an **NSF charge** when a cheque is returned. Atriom bounces the cheque and charges
nothing, so the bank's return fee and the operator's own handling cost are absorbed silently. In
Egypt a returned cheque is a serious event and recovering the fee is ordinary practice.

**The design is already determined by two things Atriom has**, which is why this is an extension
rather than a decision:

1. **The billing shape** — `BillViolationFineService` is the exact analogue: an operator action
   raises a one-line issued invoice for a penalty, VAT out of scope, guarded against double-billing,
   posting-date guarded. An NSF fee is the same act on a different trigger. It should be a SEPARATE
   operator action on a bounced cheque, not a side effect of `bounce()` — the same separation module
   31 draws between recording a violation and billing its fine.
2. **The amount** — settings-driven like the late fee (`BillingSettings::$late_fee_*`), defaulting
   to **0 = off**, so nothing changes until an operator configures it. Same conservative shipping
   posture as straight-line rent.

**Shipped as specced**, with one correction: this spec claimed `invoice_items.type` was a DB enum
needing a migration. It is `varchar(32)` — already converted under the no-DB-enums house rule, and
`InvoiceItemType`'s own docblock says "add a new type here — no migration needed". That was the
riskiest part of the estimate and it did not exist.

`nsf_fee` has its own charge code rather than reusing `other`, mapped EXPLICITLY to `misc_income` in
`InvoiceJournalizer` (the fallback would have classified it correctly by accident), and sits with
`late_fee` at the end of `InvoiceItemSettlement::TYPE_PRIORITY` so a part payment is never eaten by
a penalty. The amount is `BillingSettings::$nsf_fee_amount`, shipping 0 = off, and the action stays
hidden until it is set.

## 11. Scorecard

**All 43 stories in [05-user-stories.md](05-user-stories.md) shipped between 2026-08-08 and
2026-08-09.** The verdicts below are what the cycle SET OUT to do, kept as written, with what
actually happened beside them — a scorecard rewritten to say "done" everywhere teaches nothing.

| Area | Verdict set at the start | Outcome |
|---|---|---|
| **Charge / rent schedule** | ♻️ **REBUILD the write path** — 7 of 15 scenarios trace here | ✅ done (LS-01…LS-06). The read path already honoured dates; only the write path was inverted, so it was smaller than "rebuild" implied |
| **Lease events & amendments** | ♻️ **REBUILD** — currently a `notes` string | ✅ done (LE-01…LE-04). Append-only `lease_events`; "occupied" and "leased" turned out to be different predicates |
| **Options & critical dates** | ➕ new, small, high value | ✅ done (OP-01…OP-04). The data mostly existed and nothing read it |
| **Money flow completion** (proration both ends, move-out, write-off, holdover) | ➕ EXTEND | ✅ done (MF-01…MF-09), including post month, item allocation and line disputes |
| **Revenue recognition** | ❓ DECIDE first | ✅ decided then built (RA-01, RA-02) — straight-line shipped **OFF**, invoices byte-identical either way |
| **Recoveries** | ➕ EXTEND — the calculation core is sound, the *inputs* are hand-keyed | ✅ done (RC-01…RC-07). The tie-out GREW rather than loosened: `Σ allocated + landlord_unrecovered = total` |
| **Percentage rent** | ➕ EXTEND — cumulative basis is the money item | ✅ done (PR-01…PR-04) |
| **Reporting** | ➕ EXTEND — rent roll is a conspicuous hole | ✅ done (RR-01…RR-05) |
| **AR / payments / credit notes / GL registry / deletion policy / property isolation** | ✅ **KEEP — do not reopen** | ✅ kept. Item allocation sits BENEATH `recomputeTotals()` rather than beside it |

**The one prediction that held all the way through:** the defect was state-not-schedule in the
LEASING model, and the money core did not need rebuilding. Everything below the charge row —
invoice, cash, GL — was extended, never reopened.

**Read that last line twice.** The instinct behind this cycle — *"if it's wrong, rebuild it"* — is
right, and it applies to **lease → charge**. It does not apply to **charge → invoice → cash → GL**,
which is the part that took a year to get right and is, in several specific places, better than the
system being benchmarked against. Rebuilding the money core to fix a leasing-model problem would
trade a year of proven invariants for a defect that does not live there.
