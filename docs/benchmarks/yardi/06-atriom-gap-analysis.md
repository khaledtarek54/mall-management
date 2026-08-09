# 06 — Atriom vs Yardi: the gap analysis

> Row by row, with a **verdict** and a **severity**. The Atriom column is grounded in the code, not
> in the module docs — two rows in the [July 2026 competitor
> analysis](../../gap-analysis/competitors/01-lease-billing.md) were already stale when this was
> written, and stale gap rows cost more than missing ones because they send people to rebuild what
> exists.

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

---

## 1. The lease record

| Capability | Yardi | Atriom today | Verdict | Sev |
|---|---|---|---|---|
| Lease lifecycle states | Status (Future/Current/Notice/Past) **and** type (new/renewal/expansion/holdover) as separate axes | One 7-state enum; `renewed` is a *status* | ➕ EXTEND — add `lease_type` alongside status | 🟡 |
| Occupancy as a projection of lease state | ✅ | ✅ `LeaseObserver` → `Unit::recomputeStatus()`, idempotent, observer-driven | ✅ **KEEP** — clean, and better factored than most | ⚪ |
| Multi-unit / multi-space lease | Space links are **date-ranged**, each with its own area | `lease_unit` pivot with one `is_master`, mirrored to `leases.unit_id`; renewal carries the full set | ➕ EXTEND — pivot needs effective dates (LE-02) | 🟠 |
| Per-space rent | Rent per space, or a rate × area | One blended `base_rent_monthly` for all units | ➕ EXTEND (LS-04) | 🟡 |
| The six dates (sign / possession / rent commencement / term commencement / expiry / move-out) | ✅ | Two: `commencement_date`, `expiry_date`; fit-out is an integer month count | ➕ EXTEND — possession + rent-commencement are the two that matter | 🟠 |
| Area as a first-class, date-ranged number | ✅ drives rent, recoveries, breakpoints | `units.area_sqm`, static, used only by CAM | ➕ EXTEND | 🟠 |
| Double-booking prevention | ✅ | ✅ `lockForUpdate()` on the **contended unit row** in both activation paths, with a standing test that the lock is still there | ✅ **KEEP** — this is better than the benchmark deserves credit for | ⚪ |
| Terminal-lease immutability | ✅ | ✅ `Lease::updating` blocks non-allow-listed changes once terminal | ✅ **KEEP** | ⚪ |
| Lease documents | Abstraction + clause library + AI extraction | Media upload, private disk, gated | ➕ EXTEND much later (XX-05) | 🟡 |

---

## 2. Charges — the structural gap

| Capability | Yardi | Atriom today | Verdict | Sev |
|---|---|---|---|---|
| **Date-ranged charge schedule, many rows per code** | ✅ the whole term written on day one | **One active row per type**, mutated in place | ♻️ **REBUILD the write path** | 🔴 |
| Billing reads the row effective for the period | ✅ | ✅ **already does** — `chargeAppliesToPeriod()` honours `start_date`/`end_date` ([`MonthlyBillingService.php:402`](../../../app/Services/MonthlyBillingService.php#L402)) | ✅ **KEEP** — this is why the rebuild is small | ⚪ |
| Charge codes as configuration (code → GL account, tax, recoverable, deposit) | ✅ an accountant adds one | `InvoiceItemType` enum + a hard-coded map in `InvoiceJournalizer::REVENUE_ROLE` — a code change | ➕ EXTEND — a `charge_codes` table resolving through the existing `AccountMapping` | 🟠 |
| Escalation: stored | ✅ %, amount, index, **floor/ceiling**, compounding | `escalation_rate`, `escalation_type` (none/fixed_percent/cpi), `next_escalation_date` | ➕ EXTEND — floor/ceiling are mandatory for any CPI work | 🟠 |
| Escalation: applied automatically | ✅ generates the future row | ✅ `leases:apply-escalations` — idempotent, row-locked, re-checked in-transaction, one step per run, CPI deliberately skipped | ♻️ **REBUILD the effect, KEEP the sweep** — it must append a row, not overwrite one | 🔴 |
| CPI / index escalation | ✅ with an index source | Skipped by design (no feed; refuses to invent a number) | ➕ EXTEND — index register + **collar**, or leave it out honestly | 🟡 |
| Free rent / abatement | Per charge code, date-ranged, feeds straight-line | ✅ `fit_out_scope` — `rent_only` (net abatement, **default for new leases**) or `gross`; existing leases keep gross | ✅ **KEEP** — per-charge-code abatement rows remain future work | ⚪ |
| Billing frequency | Per charge row | Per lease (`monthly`/`quarterly`/`semiannual`/`annual`), cycle-anchored, billed in advance, capped at expiry | ✅ **KEEP** — genuinely careful work | ⚪ |
| Proration — commencement | ✅ | ✅ correct arithmetic… but **the bulk run never prorates** | ➕ EXTEND (MF-01) | 🔴 |
| Proration — termination/expiry | ✅ | ❌ none | ➕ EXTEND (MF-02) | 🔴 |
| Holdover billing | ✅ amendment at 125–200% | Alerted, never billed | ➕ EXTEND (LE-04) | 🔴 |
| Batch review before posting | ✅ edit/delete the proposed charges before commit | Direct create; guarded by a run lock + period-overlap idempotency | ➕ EXTEND — a dry-run preview | 🟡 |
| Double-bill prevention | Batch + post month | ✅ `Cache::lock` on the period + `WithoutOverlapping` + period-overlap guard, and the manual action **contends on the same lock** | ✅ **KEEP** — triple defence, above benchmark | ⚪ |

---

## 3. Amendments & lease events

| Capability | Yardi | Atriom today | Verdict | Sev |
|---|---|---|---|---|
| Mid-term amendment as a first-class record | ✅ expansion/contraction/relocation/holdover/rent-mod, with effective date, reason, document | ❌ a sentence appended to `leases.notes` | ♻️ **REBUILD** (LE-01) | 🔴 |
| Point-in-time reconstruction of a lease | ✅ | ❌ | follows from LE-01 | 🟠 |
| Renewal | Amendment **or** new record (configurable) | New lease chained by `previous_lease_id`, carrying the full unit set and duplicating charges; original → `renewed` | ✅ **KEEP** — defensible and arguably cleaner for a mall. **The gap is amendments, not renewal** | ⚪ |
| Termination | ✅ with a final account | ✅ deactivates charges, cancels only **fully-unpaid** invoices (correctly refusing partially-paid ones) | ➕ EXTEND with MF-02 + MF-03 | 🟠 |

---

## 4. Options & critical dates

| Capability | Yardi | Atriom today | Verdict | Sev |
|---|---|---|---|---|
| Renewal / termination / expansion / ROFR options with notice windows | ✅ | ✅ `LeaseOption` + panel on the lease (shipped 2026-08-09) | ✅ **KEEP** | ⚪ |
| Alert before the **earliest notice date** | ✅ | ✅ `leases:scan-option-windows` daily — opening · closing · lapsed | ✅ **KEEP** | ⚪ |
| Space encumbrance | ✅ | ❌ | ➕ EXTEND (OP-03) | 🟠 |
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
| Automatic receipt application order | ✅ credits → priority → oldest | ❌ manual allocation | ➕ EXTEND | 🟡 |
| Item-level payment allocation | ✅ (charge-level natively) | ❌ | ➕ EXTEND (MF-06) | 🟠 |
| Line-level dispute | ✅ | ❌ invoice-level `disputed` only | ➕ EXTEND (MF-07) | 🟠 |
| **Bad-debt write-off** | ✅ `WRTOFF` → bad-debt expense | ❌ **absent** — cancel reverses revenue in the wrong period | ➕ EXTEND (MF-04) | 🔴 |
| Late fees | per charge code, per-lease override | ✅ idempotent + lock-safe, but **config-global** | ➕ EXTEND (MF-08) | 🟡 |
| Bounced / NSF | ✅ reverses + fees | ✅ `Payment.status = bounced` + module 33 PDC lifecycle | ✅ **KEEP** — no NSF fee, minor | ⚪ |
| Bank deposit batches | ✅ | ❌ | ⏭️ **DECLINE** — PDCs and transfers dominate here (XX-06) | ⚪ |
| Post-dated cheques | 🟡 regional builds | ✅ full register, lodging, maturity, clear/bounce, invoice-lock + over-allocation backstop | ✅ **KEEP — exceeds Yardi for this market** | ⚪ |
| Tenant statement | ✅ | ✅ `TenantStatementPdfService` | ✅ KEEP | ⚪ |
| AR aging | ✅ by charge code | ✅ bucket summary + drill-down (`ArAging`) **and a per-tenant collections worklist** (`ArCollections`, shipped 2026-08-08) — still invoice-level, not charge-level | ➕ EXTEND (RR-03) once MF-06 lands | 🟡 |

---

## 6. Deposits

| Capability | Yardi | Atriom today | Verdict | Sev |
|---|---|---|---|---|
| Deposit as a GL liability | ✅ | ✅ `DepositTransaction` → `Dr Cash\|Bank / Cr Deposits Held`, journalized, numbered, bilingual | ✅ KEEP | ⚪ |
| Refund / forfeit | ✅ | ✅ both paths posted | ✅ KEEP | ⚪ |
| **Itemised move-out disposition** | ✅ one document nets damages vs refund | ❌ two unconnected manual events | ➕ EXTEND (MF-03) | 🟠 |
| Held vs contractual reconciliation | ✅ | ❌ nothing compares the register to `leases.security_deposit` | ➕ EXTEND (MF-03) | 🟠 |
| Deposit top-up on escalation | 🟡 | ❌ | 🟡 note only | 🟡 |
| Interest-bearing / segregated | ✅ | ❌ | ⏭️ DECLINE — not an Egyptian requirement | ⚪ |

---

## 7. Recoveries & percentage rent

| Capability | Yardi | Atriom today | Verdict | Sev |
|---|---|---|---|---|
| Pool sourced from GL expense accounts | ✅ with drill-down | ❌ two hand-keyed totals | ➕ EXTEND (RC-01) | 🟠 |
| Multiple pools per property | ✅ | ❌ one per `(asset_id, period_year)` | ➕ EXTEND (RC-02) | 🟠 |
| Configurable denominator | ✅ GLA / occupied / fixed / stated | ❌ hard-coded **occupied** | ➕ EXTEND (RC-03) | 🟠 |
| Gross-up | ✅ | ❌ — **but the inputs exist**: `Asset::totalUnitAreaSqm()`, `occupiedAreaSqm()`, `areaOccupancyRate()` and the declared `leasable_area_sqm`. The CAM service simply never reads them | ➕ EXTEND (RC-04) — wiring, not new data | 🟡 |
| Caps: absolute, YoY, base year, compounding | ✅ | ✅ **`LeaseCamTerm`, effective-dated, tighter ceiling wins, landlord absorbs `cap_absorbed`** | ✅ **KEEP** — *the 2026-07-18 doc calling this absent is stale* | ⚪ |
| Cap scoped to controllable expenses; cumulative headroom | ✅ | ❌ caps the whole share | ➕ EXTEND (RC-07) | 🟡 |
| Admin / management fee | ✅ | ✅ `admin_fee_pct` per pool, applied to the **capped** cost so it cannot re-breach the cap, VAT-rated per pool | ✅ **KEEP** | ⚪ |
| Share freezing / tie-out on re-run | — | ✅ frozen participant set + frozen `pro_rata_share_pct` | ✅ **KEEP — above benchmark discipline** | ⚪ |
| True-up settlement | ✅ | ✅ positive bills immediately on its own invoice (correct — the lease may have ended); negative becomes a credit auto-applied FIFO | ✅ **KEEP — hard-won, do not re-open** | ⚪ |
| **Re-estimate next year** | ✅ | ❌ and the estimate *billed* ≠ the estimate *reconciled* — two hand-kept numbers | ➕ EXTEND (RC-05) | 🟠 |
| Tenant reconciliation statement | ✅ auditable | ❌ an invoice line | ➕ EXTEND (RC-06) | 🟠 |
| **CAM area = summed lease area** | ✅ | ❌ **master unit only, both sides — a live money bug** | ➕ EXTEND (MF-09) — *fix independently* | 🔴 |
| % rent: natural + artificial breakpoint | ✅ | ✅ both | ✅ KEEP | ⚪ |
| % rent: **cumulative YTD + annual settle-up** | ✅ | ❌ period basis only; annual reconciliation DEFERRED | ➕ EXTEND (PR-01) | 🔴 |
| % rent: tiers | ✅ | ❌ | ➕ EXTEND (PR-02) | 🟠 |
| % rent: deductions/offsets | ✅ | ❌ | ➕ EXTEND (PR-03) | 🟠 |
| % rent: estimated sales when undeclared | ✅ | ❌ chases, never bills | ➕ EXTEND (PR-04) | 🟠 |
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
| **Rent roll** | ✅ the most-used commercial report | ❌ **does not exist** | ➕ EXTEND (RR-01) | 🔴 |
| Lease expiration schedule | ✅ | ❌ (nav badge for expiring leases only) | ➕ EXTEND (RR-02) | 🟠 |
| Stacking plan | ✅ | ❌ | 🟡 later | 🟡 |
| Occupancy / vacancy | ✅ | 🟡 unit status counts | ➕ EXTEND | 🟡 |
| AR aging | ✅ by charge code | ✅ invoice-level page + drill-down + CSV | ➕ EXTEND (RR-03) | 🟡 |
| **Occupancy cost %** | ✅ | ❌ — *and every input already exists* | ➕ EXTEND (RR-04) — best value-per-line in this document | 🟠 |
| Sales MTD/YTD/MAT, like-for-like | ✅ | 🟡 declarations captured, little analysis | ➕ EXTEND (RR-05) | 🟡 |
| Straight-line schedule | ✅ | ❌ | ❓ DECIDE (RA-01) | ❓ |
| Owner statements | Investment Manager | ✅ three-tier accrual-GL spine, journalized | ✅ KEEP | ⚪ |
| Revenue forecast | ✅ Forecast Manager | ❌ | 🟡 falls out of LS-01 almost free | 🟡 |

---

## 10. Accounting & GL

| Capability | Yardi | Atriom today | Verdict | Sev |
|---|---|---|---|---|
| Every money source posts to the GL | ✅ | ✅ **`LedgerPoster::JOURNALIZERS` as a single registry, with a conformance gate that fails the build on an unregistered source** | ✅ **KEEP — stricter than the benchmark** | ⚪ |
| Closed-period refusal | ✅ | ✅ `PostingDateGuards`, per-source, conformance-gated | ✅ KEEP | ⚪ |
| **Post month ≠ document date** | ✅ | ❌ `entry_date` is the document date | ➕ EXTEND (MF-05) | 🟠 |
| Multiple books | ✅ | ❌ single book | ⏭️ **DECLINE** (XX-02) | ⚪ |
| Charge code → GL account as data | ✅ | 🟡 `AccountMapping` (key → account, per-property override) exists, but the item-type → key map is hard-coded | ➕ EXTEND — join the two | 🟠 |
| **Straight-line rent / deferred rent** | ✅ | ❌ revenue = as billed | ❓ **DECIDE (RA-01)**, then EXTEND (RA-02) | 🔴 |
| Bad-debt expense | ✅ | ❌ | ➕ EXTEND (MF-04) | 🔴 |
| VAT | region packs | ✅ settings-driven, origination-only, literal-banned by a gate | ✅ KEEP | ⚪ |
| Money-record deletion | soft controls | ✅ **refused at the model, gated in CI, with a stated reason per model** | ✅ **KEEP — exceeds the benchmark** | ⚪ |

---

## 11. Scorecard

| Area | Verdict |
|---|---|
| **Charge / rent schedule** | ♻️ **REBUILD the write path** — 7 of 15 scenarios trace here |
| **Lease events & amendments** | ♻️ **REBUILD** — currently a `notes` string |
| **Options & critical dates** | ➕ new, small, high value |
| **Money flow completion** (proration both ends, move-out, write-off, holdover) | ➕ EXTEND |
| **Revenue recognition** | ❓ DECIDE first |
| **Recoveries** | ➕ EXTEND — the calculation core is sound, the *inputs* are hand-keyed |
| **Percentage rent** | ➕ EXTEND — cumulative basis is the money item |
| **Reporting** | ➕ EXTEND — rent roll is a conspicuous hole |
| **AR / payments / credit notes / GL registry / deletion policy / property isolation** | ✅ **KEEP — do not reopen** |

**Read that last line twice.** The instinct behind this cycle — *"if it's wrong, rebuild it"* — is
right, and it applies to **lease → charge**. It does not apply to **charge → invoice → cash → GL**,
which is the part that took a year to get right and is, in several specific places, better than the
system being benchmarked against. Rebuilding the money core to fix a leasing-model problem would
trade a year of proven invariants for a defect that does not live there.
