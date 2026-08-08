# CAM (Common-Area Maintenance) Reconciliation — exhaustive reference

> Status: current as of the money-hardening session (June 2026). Every statement below is grounded in the live code — file paths and line numbers are cited so a reader can verify. This is the most complex money path in Atriom and was heavily reworked this session (proration lock-safety, positive/negative true-up settlement, credit-note auto-apply, and the books-reconciliation CAM check). Read this in full before changing any CAM logic.

---

## Plain-language summary (for a non-engineer)

A mall has shared costs that no single tenant owns: cleaning the corridors, lighting the atrium, security, HVAC for the common areas, landscaping. These are **Common-Area Maintenance (CAM)** costs.

Throughout the year the operator (Eltizam) collects an **estimate** of each tenant's CAM share, month by month, baked into their service charges. Nobody knows the *real* annual cost until the year is over and all the invoices from cleaners, security firms, and utility providers are in.

So at the start of the **next** year we **reconcile**: compare what each tenant *actually* should have paid against what they *were estimated to have paid*, and settle the difference (the "true-up"):

- **Tenant under-paid** (their real share is bigger than the estimate they paid): they owe more. We bill them **immediately** on a dedicated "CAM Reconciliation" recovery invoice. We do this on the spot — not as a charge that piggybacks on a future monthly invoice — because by the time we reconcile last year, the tenant may have already moved out (their lease ended), and an ended lease is skipped by the monthly billing engine. Deferring would silently lose that revenue.
- **Tenant over-paid** (their real share is smaller than the estimate): we owe *them*. We issue a **credit note** (an IOU on their account) and immediately apply it against any open invoices they still have, oldest first. Whatever credit is left over stays on their account as a standing credit they can use later. We deliberately do **not** model this as a "negative charge," because a negative charge could drag an invoice total below zero, and the invoice engine floors totals at zero — which would silently *erase* the credit.

Each tenant's share is **pro-rata by leased floor area**: a tenant occupying 1,000 m² in a 10,000 m² mall bears 10% of the actual CAM cost (and is credited with 10% of the estimate collected). Bigger footprint, bigger share — fair cost-sharing.

The whole run is **idempotent** (safe to run twice — it won't double-bill) and **lock-safe** (two concurrent runs can't both bill the same tenant). After billing, an independent audit (the "books reconciliation") re-checks that every billed true-up actually reached a real, non-cancelled invoice or credit note — so finance can trust that nothing was billed into the void.

---

## The exact rule / formula

There are two phases: **(A) allocate** (compute each tenant's numbers) and **(B) bill** (settle the difference). Both live in `app/Services/CamReconciliationService.php`.

### Inputs

| Input | Source | Notes |
|-------|--------|-------|
| `pool.total_actual_expense` | `CamExpensePool.total_actual_expense` (decimal `14,2`, EGP) | The **real** annual CAM spend for one property (`asset_id`) in one `period_year`. Entered by the operator's accounting team. |
| `pool.total_estimated_collected` | `CamExpensePool.total_estimated_collected` (decimal `14,2`, EGP) | What was estimated/collected from tenants across the year for CAM. Entered by accounting. |
| `pool.asset_id` | `CamExpensePool.asset_id` | The property the pool covers. One pool per `(asset_id, period_year)` — enforced by the unique index `cam_pool_asset_year_unique` (`database/migrations/2026_05_23_164627_create_cam_expense_pools_table.php`). |
| `pool.period_year` | `CamExpensePool.period_year` (unsigned smallint, e.g. `2025`) | The calendar year being reconciled. |
| Eligible leases | `Lease` where `status = 'active'` **and** `unit.asset_id = pool.asset_id` | Only **active** leases on the pool's own property. See `generateAllocations()` lines 30–34. |
| `lease.totalAreaSqm()` | Σ `Unit.area_sqm` over **every** unit on the lease (the `lease_unit` pivot), falling back to the master unit when the pivot is empty | The leased floor area used for the pro-rata weight. Null/0 area ⇒ the lease is skipped. **Corrected 2026-08-08:** this read the **master unit only**, on both the numerator and the denominator, so every multi-unit lease was under-charged by its non-master footprint and single-unit tenants absorbed the shortfall. Because both sides were wrong the same way the shares still summed to 100% and Σ(allocated) = total_actual_expense stayed green — *a tie-out cannot see a distribution error; assert the share.* Regression: `tests/Feature/Regression/CamMultiUnitAreaTest.php`. |

### Phase A — `generateAllocations(CamExpensePool $pool): int`
`app/Services/CamReconciliationService.php:28-89`

1. Load eligible leases (active, on this asset), eager-loading `unit` **and `units`** (the pivot).
2. Compute `totalSqm = Σ lease.totalAreaSqm()` over those leases. **If `totalSqm <= 0`, return `0` — a complete no-op.** This is the guard for "no active tenants / no areas recorded."
3. Open a DB transaction. For each lease:
   - `sqm = lease.totalAreaSqm()`; if `sqm <= 0`, **skip this lease**.
   - `share = sqm / totalSqm` (a fraction in `[0,1]`, line 51).
   - `allocated_amount = round(pool.total_actual_expense * share, 2)` (line 52).
   - `estimated_paid   = round(pool.total_estimated_collected * share, 2)` (line 53).
   - `true_up_amount   = round(allocated - estimated, 2)` (line 54). **Positive ⇒ tenant under-paid (owes more). Negative ⇒ tenant over-paid (is owed a credit).**
   - `pro_rata_share_pct = round(share * 100, 4)` (stored as decimal `7,4`, line 77).
4. **Lock + idempotency guard** (lines 60–69): the existing allocation for `(pool, lease)` is read **`lockForUpdate()` inside the transaction**. If it exists and its `status !== 'pending'` (i.e. already `billed`/`disputed`/`closed`), **`continue` — never re-touch it.** This is the fix from `91fb4be` (lock-safe generateAllocations): a concurrent `bill()` that flipped the row to `billed` between a stale read and our save would otherwise be clobbered back to `pending` and re-billed (a double charge/credit).
5. Otherwise fill the allocation (`firstOrNew`-style: reuse the locked row or `new`) with the four computed amounts + `status = 'pending'` and `save()` (lines 71–84). Existing rows are **updated in place**, never duplicated — the unique index `cam_alloc_pool_lease_unique` (`(cam_expense_pool_id, lease_id)`) also backs this.
6. Return the count of allocations created/updated (skipped non-pending ones are **not** counted).

**Rounding:** money to **2 dp** (`round(..., 2)`); the pro-rata percent to **4 dp**. Column types: `pro_rata_share_pct decimal(7,4)`, the three money columns `decimal(14,2)` (`database/migrations/2026_05_23_164628_create_cam_allocations_table.php`). Because each line is rounded independently, the sum of `allocated_amount` across a pool can differ from `total_actual_expense` by a sub-cent residual — see *Edge cases → Rounding residual*.

### Phase B — `bill(CamAllocation $allocation): CamAllocation`
`app/Services/CamReconciliationService.php:182-225`

Wrapped in a DB transaction. **Re-loads the allocation `lockForUpdate()` and re-checks status inside the txn** (lines 188–192): if the row is gone or already `status === 'billed'`, it returns immediately — a **no-op** (idempotent + concurrency-safe; two concurrent `bill()` calls can't both bill).

Let `amount = (float) allocation.true_up_amount`.

- **If `amount < 0` (over-paid → credit):**
  1. `billCredit(allocation, abs(amount), year)` issues a `CreditNote` (lines 199, 309–328).
  2. `applyCreditToOpenInvoices(note, lease_id)` auto-applies it FIFO to the lease's open invoices (lines 206, 289–306).
  3. Update allocation: `status = 'billed'`, `billed_credit_note_id = note.id` (lines 208–211).
- **If `amount >= 0` (under-paid → recovery; `amount == 0` also takes this branch):**
  1. `billChargeImmediately(allocation, amount, year)` creates a `Charge` **and** a dedicated `issued` recovery `Invoice` + `InvoiceItem` right now (lines 216, 236–286).
  2. Update allocation: `status = 'billed'`, `billed_charge_id = charge.id` (lines 218–221).

> **Note on `amount == 0`:** a zero true-up still takes the positive branch and creates a zero-value charge + zero-value invoice. This is intentional traceability — the books check (below) tolerates it because a zero charge still "reaches" a non-cancelled invoice.

#### The positive-true-up recovery invoice — `billChargeImmediately()` (lines 236–286)

This is the heart of this session's fix (`e9e6235` then `67ae0e0`). It does **not** defer to the monthly billing engine. It builds, in one transaction:

1. A `Charge` — kept purely for **traceability** and the books CAM check (it is **not** a billable recurring charge; the true-up is settled on the recovery invoice below):
   - `lease_id`, `name = "CAM Reconciliation — {year}"`, `type = 'other'`, `amount = <positive true-up>`, `currency = 'EGP'`, `frequency = 'one_time'`, `vat_applicable = false`, `vat_rate = 0`, `start_date = {reconciled year}-01-01`, `end_date = {reconciled year}-12-31`, **`is_active = false`**.
   - **Critical:** `is_active = false` (and the past-year dates). The monthly billing engine loads only `is_active` charges, so it can **never** pick this up and re-bill the true-up onto the tenant's regular monthly invoice. An active, current-month-dated `one_time` charge here would **double-bill** the tenant (review pass 6, `477daee`).
2. An **`issued` recovery `Invoice`**:
   - `lease_id`, `tenant_id = lease.tenant_id`, `status = 'issued'`, `issue_date = now()`, `due_date = now()->addDays(lease.payment_terms_days ?? 7)`, **`period_start/period_end = the reconciled CAM year` (Jan 1 – Dec 31), NOT the current month**, `subtotal = total = balance = amount`, `vat_amount = 0`, `paid_amount = 0`, `currency = lease.currency ?? 'EGP'`.
   - **Critical:** the period is the **reconciled year**, not the current month. The monthly engine's idempotency is a per-lease period-**overlap** check; a current-month period would satisfy it and make the monthly run **skip the lease's regular rent invoice** for that month (review pass 6, `ddd53a9`). A past-year period never collides with a live monthly run.
3. An `InvoiceItem` linking the charge to the invoice (lines 274–283): `charge_id = charge.id`, `type = 'other'`, `amount = total = amount`, `vat_rate = vat_amount = 0`.

The amounts are written **directly** here (this is a fresh single-line invoice with no payments/credits yet, so it is internally consistent: `subtotal + vat == total`, `paid == 0`, `balance == total`). It is then a normal AR invoice that the tenant must pay by `due_date`.

**Why immediate settlement, not a deferred `one_time` charge?** (Documented in the method's own docblock, lines 227–235.) Reconciliation runs the year **after** the reconciled year is fully billed (see *Scheduling*). The tenant most likely to be under-collected is exactly the one whose **lease has ended** — and ended/expired leases are **excluded from the monthly billing engine** by its active/expiry filter. A future-dated `one_time` charge would therefore be **silently skipped → lost revenue**, regardless of date. Settling immediately on a dedicated invoice mirrors the negative path (which applies its credit immediately) and guarantees the receivable is actually raised.

#### The negative-true-up credit — `billCredit()` (lines 309–328)

Creates an **`issued` `CreditNote`** on the tenant's account:
- `tenant_id`, `lease_id`, `status = 'issued'`, `issue_date = now()`, `reason = 'adjustment'`, `reason_notes = "CAM reconciliation credit — {year}"`, `subtotal = total = balance = <abs(true-up)>`, `vat_amount = 0`, `applied_amount = 0`, `currency = 'EGP'`.

**Why a credit note, not a negative charge?** (Docblock lines 170–181, migration `2026_06_29_000002_add_billed_credit_note_to_cam_allocations.php`, fix `c022f9f`.) The old design billed a **negative one-off charge**. If the credit exceeded the other charges on (say) January's invoice, the invoice total went negative, and `Invoice::recomputeTotals()` floors the balance at zero (`round(max(0, total - paid), 2)`, `app/Models/Invoice.php:267`) — **silently losing the credit**. A `CreditNote` preserves the full value and settles AR through the normal credit-apply flow.

#### Auto-apply (FIFO) — `applyCreditToOpenInvoices()` (lines 289–306)

After issuing the credit, it is applied to the lease's **open** invoices, **oldest due-date first**:
- "Open" = `status IN ('issued','partially_paid','overdue')` **and** `balance > 0`, `orderBy('due_date')` (lines 293–298).
- For each invoice, **re-read the note's live balance** (`CreditNote::whereKey(...)->value('balance')`); if it's `<= 0`, stop (lines 301–303).
- Apply via `CreditNoteService::applyToInvoice($note, $invoice)` (line 304).

`CreditNoteService::applyToInvoice()` (`app/Services/CreditNoteService.php:36-91`) is itself **lock-safe** (locks both the note and the invoice, re-reads under lock — fix `8a0a5c6`): it caps the application at `min(note.balance, invoice.balance)`, only touches **payable** invoices (`issued`/`partially_paid`/`overdue` and `hasBalance()`), bumps `invoice.credit_applied_amount`, and calls `invoice.recomputeTotals()` so the credit folds into `paid_amount`/`balance`/`status`. **Any remainder stays on the credit note as a standing credit — preserved, never lost** (lines 288, 206 docblock). This restores the *intent* of the old negative-charge behaviour (the credit nets against what the tenant owes) without the floor-to-zero loss, and without waiting for an admin to manually click "Apply."

### Orchestration — `autoTrueUpForYear(int $year, bool $autoBill = false)`
`app/Services/CamReconciliationService.php:102-168`

Runs the lifecycle for **every** pool of `$year` whose `status IN ('draft','reconciling')` (already-`reconciled`/`closed` pools are filtered out — idempotent across re-runs):
- `generateAllocations()` for each pool.
- If `$autoBill`, `bill()` every still-`pending` allocation.
- Bump pool status: `reconciled` (and stamp `reconciled_at = now()`) if `autoBill && allocations > 0`; else `reconciling` if `allocations > 0`; else unchanged (lines 128–139).
- **One failing pool does not abort the run** — it is logged (`OpsLog::error('cam.pool_failed', ...)`) and the loop continues (lines 148–156). A run summary is logged via `OpsLog::info('cam.run_complete', ...)`.
- Returns a per-pool report array: `{pool_id, asset, allocations, billed, status}`.

### CLI + scheduling

- **Command:** `php artisan cam:reconcile {--year=YYYY} {--auto-bill}` — `app/Console/Commands/CamAnnualReconciliationCommand.php`. `--year` defaults to **previous calendar year** (`now()->year - 1`, line 16). Without `--auto-bill` it only generates allocations and moves pools to `reconciling` for manual review; with `--auto-bill` it runs end-to-end.
- **Schedule:** `routes/console.php:37-44` — `Schedule::command('cam:reconcile')->yearlyOn(month, day, time)->withoutOverlapping()`, where:
  - month = `config('billing.cam_reconciliation_month', 1)` ← env `CAM_RECONCILIATION_MONTH` (default **January**)
  - day = `config('billing.cam_reconciliation_day', 15)` ← env `CAM_RECONCILIATION_DAY` (default **15th**)
  - time = `config('billing.cam_reconciliation_time', '03:00')` ← env `CAM_RECONCILIATION_TIME` (default **03:00**)
  - (`config/billing.php:35-37`.) Note the **scheduled** run uses the command's **default options**, i.e. previous year and **review-only** (no `--auto-bill`) unless an operator runs it manually with the flag.

---

## Worked examples (real numbers)

Setup: one property, pool `period_year = 2025`. Three active leases on the asset:

| Lease | Unit area (m²) | Share = area / 10,000 | `pro_rata_share_pct` |
|------:|---------------:|----------------------:|---------------------:|
| A | 5,000 | 0.5000 | 50.0000 |
| B | 3,000 | 0.3000 | 30.0000 |
| C | 2,000 | 0.2000 | 20.0000 |
| **Total** | **10,000** | 1.0000 | 100.0000 |

### Example 1 — POSITIVE true-up (under-collected → immediate recovery invoice)

Pool: `total_actual_expense = 1,000,000.00`, `total_estimated_collected = 800,000.00` (the operator under-estimated; real spend exceeded estimates). Pool `variance()` = `1,000,000 − 800,000 = +200,000.00`.

| Lease | `allocated_amount` = 1,000,000 × share | `estimated_paid` = 800,000 × share | `true_up_amount` = allocated − estimated |
|------:|----------------:|----------------:|-----------------:|
| A | 500,000.00 | 400,000.00 | **+100,000.00** |
| B | 300,000.00 | 240,000.00 | **+60,000.00** |
| C | 200,000.00 | 160,000.00 | **+40,000.00** |
| **Σ** | **1,000,000.00** | **800,000.00** | **+200,000.00** |

`bill()` on each (positive branch) → `billChargeImmediately()`:
- Lease A gets a `Charge` "CAM Reconciliation — 2025" of `100,000.00` **and** a fresh `issued` invoice: `subtotal = total = balance = 100,000.00`, `vat = 0`, `due_date = now() + lease.payment_terms_days` (or +7 days). Allocation A → `status = 'billed'`, `billed_charge_id` set.
- Likewise B (`60,000.00`) and C (`40,000.00`).

Even if Lease C's term had **ended** before reconciliation ran, C is still billed here — the invoice is raised directly, not deferred to a monthly run that would skip the ended lease. That is the whole point of the immediate-settlement fix.

### Example 2 — NEGATIVE true-up (over-collected → credit note, auto-applied FIFO)

Same areas. Pool: `total_actual_expense = 700,000.00`, `total_estimated_collected = 1,000,000.00` (the operator over-collected). Pool `variance()` = `−300,000.00`.

| Lease | `allocated_amount` = 700,000 × share | `estimated_paid` = 1,000,000 × share | `true_up_amount` |
|------:|----------------:|----------------:|-----------------:|
| A | 350,000.00 | 500,000.00 | **−150,000.00** |
| B | 210,000.00 | 300,000.00 | **−90,000.00** |
| C | 140,000.00 | 200,000.00 | **−60,000.00** |

Take **Lease A** (`true_up = −150,000.00`). `bill()` (negative branch):
1. `billCredit()` issues a `CreditNote`: `total = balance = 150,000.00`, `applied_amount = 0`, status `issued`.
2. `applyCreditToOpenInvoices()` walks A's open invoices oldest-first. Suppose A has two open invoices:
   - Invoice #1 (older `due_date`), `balance = 50,000.00` → apply `min(150,000, 50,000) = 50,000`. Invoice #1 → `paid_amount += 50,000`, `balance = 0`, status `paid`. Note balance → `100,000.00`.
   - Invoice #2, `balance = 30,000.00` → apply `30,000`. Invoice #2 → `balance = 0`, `paid`. Note balance → `70,000.00`.
   - No more open invoices → loop ends.
3. **`70,000.00` remains on the credit note** as a standing `issued` credit (it nets against A's AR in the books, and applies automatically to A's next invoice when one is raised, or can be applied by an admin).
4. Allocation A → `status = 'billed'`, `billed_credit_note_id` set.

Contrast with the old (buggy) design: a `−150,000` charge dropped onto a January invoice whose other charges totalled only, say, `40,000` would have produced a `−110,000` total, floored to `0` by `recomputeTotals()` — the tenant's `110,000` of credit would have **vanished**. The credit-note path keeps every piastre.

---

## Every edge case + how the system handles it

| Edge case | Behaviour | Where |
|-----------|-----------|-------|
| **No active leases / total area = 0** | `generateAllocations()` returns `0`; no rows written. Pool status unchanged in `autoTrueUpForYear()` (the `default => $pool->status` arm). | `:38-40`, `:128-132` |
| **A single lease has no recorded area on any of its units** | That lease is **skipped** (not allocated, doesn't draw a share); the other leases still split 100% of the pool. | `generateAllocations()` |
| **Re-running `generateAllocations()` (idempotency)** | Existing rows are updated in place (unique `(pool,lease)` index). A row already `billed`/`disputed`/`closed` is **left untouched and uncounted** (status guard under `lockForUpdate`). | `:60-69` |
| **Re-running `bill()` on a billed allocation** | No-op: re-read under lock, `status === 'billed'` ⇒ return immediately. No second charge/credit. | `:188-192` |
| **Two concurrent `generateAllocations()` + `bill()`** | The `lockForUpdate` in `generateAllocations` reads committed truth, so it can't clobber a freshly-`billed` row back to `pending`. `bill()` re-checks status under its own lock. No double-bill. | `:60-69`, `:185-192` |
| **Two concurrent `bill()` on the same allocation** | First wins; second sees `billed` under lock and no-ops. | `:188-192` |
| **Two concurrent credit applications to the same invoice** | `applyToInvoice()` locks both note and invoice, re-reads under lock, caps at `min(available, owed)` — can't over-apply (note balance can't go negative, invoice credit can't exceed total). | `CreditNoteService.php:38-91` |
| **`amount == 0` (exact match true-up)** | Takes the positive branch: a zero charge + zero invoice is created (traceability). Books check tolerates it (the zero charge reaches a non-cancelled invoice). | `:198-221`, `:236-286` |
| **Negative true-up but tenant has NO open invoices** | Credit note issued, nothing applied; full amount sits as a standing `issued` credit. Auto-applies to the next open invoice raised. | `:289-306` |
| **Credit larger than total open balance** | Applied across all open invoices FIFO; remainder preserved on the note. Never floored/lost. | `:300-305` |
| **Under-collected tenant whose lease has ENDED** | Still billed: a recovery invoice is raised **immediately**, bypassing the monthly engine's active/expiry filter (the exact lost-revenue case the fix targets). | `:227-286` |
| **A pool throws mid-run** | Logged via `OpsLog::error('cam.pool_failed')`; the annual loop continues with the next pool. Failed pool isn't in the returned report. | `:148-156` |
| **Already-`reconciled` / `closed` pool re-run** | Filtered out by `whereIn('status', ['draft','reconciling'])` — not re-processed. | `:104-108` |
| **Recovery invoice later cancelled** | If a CAM recovery invoice is cancelled, the books `cam_allocations` check flags the billed charge as **"never reached a non-cancelled invoice (lost revenue)"** — it does not silently pass. (For credit notes applied to an invoice that is later cancelled, `CreditNoteService::reverseAppliedCredit()` returns the consumed credit as a fresh note — `CreditNoteService.php:99-123`.) | `BooksReconciliationService.php:142-148` |
| **Soft-deleted lease/unit after billing** | The allocation persists and still references the lease; the charge/credit remains. A soft-deleted unit removes its lease from future `generateAllocations()` (the `whereHas('unit',…)` won't match). | model `SoftDeletes`, `:30-34` |

---

## Invariants + gotchas

1. **`true_up_amount = round(allocated_amount − estimated_paid, 2)`**, with `allocated = round(actual × share, 2)` and `estimated = round(estimated_collected × share, 2)`, `share = lease area ÷ total active-lease area`. This is the only place CAM shares are computed (`:51-54`).
2. **Positive true-up settles IMMEDIATELY on a dedicated `issued` recovery invoice** — never via a deferred `one_time` charge. Deferring loses revenue for ended-term leases. (`:227-286`.)
3. **Negative true-up is a `CreditNote`, never a negative charge** — a negative charge can be floored to zero by `Invoice::recomputeTotals()` and silently lost. (`:170-181`, `Invoice.php:267`.)
4. **Credits auto-apply FIFO and the remainder is preserved** — never dropped. (`:289-306`.)
5. **`Invoice::recomputeTotals()` is the single source of truth for money** — `paid_amount = captured payments + credit_applied_amount`, `balance = max(0, total − paid)`. CAM credit application goes through `credit_applied_amount`, never by setting `paid_amount`/`balance` directly. The one place CAM writes invoice amounts directly is the fresh recovery invoice (no payments/credits yet, so it's internally consistent). (`Invoice.php:255-283`.)
6. **Idempotent + lock-safe at every step:** the `lockForUpdate` + in-transaction status re-check in both `generateAllocations()` and `bill()` is load-bearing — it is the difference between "safe to re-run / run concurrently" and "double-bill." Do not remove it.
7. **A `billed` allocation is effectively terminal** for re-generation: never reset to `pending`. (`:67-69`.)
8. **Books reconciliation asserts settlement reached AR.** The `cam_allocations` check (`BooksReconciliationService.php:117-152`) verifies (a) Σ `allocated_amount` ties to `total_actual_expense` within a per-line tolerance of `0.01 × allocation_count`, (b) every `billed` allocation has **either** a backing `Charge` **or** a backing `CreditNote`, and (c) for a charge-backed one, the charge **actually reached a non-cancelled invoice** (catches "billed but never invoiced" lost revenue). Run `php artisan billing:reconcile` before a close/tax filing.
9. **Gotcha — VAT:** CAM true-ups are created with `vat_applicable = false`, `vat_rate = 0`. CAM is reconciled net of VAT here. (`:251-252`, `:280-282`. Aligns with the project rule "base rent VAT-exempt"; CAM mirrors that. If your jurisdiction differs, change deliberately and add VAT to the recovery invoice.)
10. **Gotcha — currency:** charges/credit notes are hard-coded `EGP`; the recovery invoice uses `lease.currency ?? 'EGP'`. Mixed-currency portfolios would need to thread currency through. (`:249`, `:271`, `:319`.)
11. **Gotcha — rounding residual:** because each line is rounded independently, Σ `allocated_amount` may differ from `total_actual_expense` by up to ~1 piastre per line. The books check absorbs this via its `0.01 × count` tolerance; there is **no** penny-pinch redistribution. (`BooksReconciliationService.php:126-129`.)
12. **Gotcha — the `Charge` on a positive true-up is for traceability only.** The receivable is the **invoice**, raised at `bill()` time. Don't expect the monthly engine to "pick up" this charge — it already has its own invoice. The charge's `start_date`/`end_date` are the **current** month (when reconciliation runs), not the reconciled year.
13. **Gotcha — scheduled run is review-only.** The cron (`yearlyOn`, Jan 15, 03:00 by default) runs `cam:reconcile` **without** `--auto-bill`. Pools land in `reconciling` and an admin must bill (or someone runs the command with `--auto-bill`). Don't assume CAM self-settles unattended.

---

## Where it lives in the code (file:line index)

| Concern | Location |
|---------|----------|
| Allocation generation (proration, lock, idempotency) | `app/Services/CamReconciliationService.php:28-89` |
| Annual orchestration (`autoTrueUpForYear`) | `app/Services/CamReconciliationService.php:102-168` |
| `bill()` — lock-safe dispatch on true-up sign | `app/Services/CamReconciliationService.php:182-225` |
| Positive true-up → immediate recovery invoice (`billChargeImmediately`) | `app/Services/CamReconciliationService.php:236-286` |
| FIFO credit auto-apply (`applyCreditToOpenInvoices`) | `app/Services/CamReconciliationService.php:289-306` |
| Negative true-up → credit note (`billCredit`) | `app/Services/CamReconciliationService.php:309-328` |
| Lock-safe credit application + recompute | `app/Services/CreditNoteService.php:36-91` |
| Returning credit when an invoice is cancelled (`reverseAppliedCredit`) | `app/Services/CreditNoteService.php:99-123` |
| Money source of truth (`recomputeTotals`, floor-at-0) | `app/Models/Invoice.php:255-283` |
| Books reconciliation CAM check | `app/Services/Reconciliation/BooksReconciliationService.php:117-152` |
| `CamExpensePool` model (`variance()`, statuses) | `app/Models/CamExpensePool.php` |
| `CamAllocation` model (statuses, `billed_credit_note_id`) | `app/Models/CamAllocation.php` |
| Pool table / unique `(asset_id, period_year)` | `database/migrations/2026_05_23_164627_create_cam_expense_pools_table.php` |
| Allocation table / unique `(pool, lease)` / column types | `database/migrations/2026_05_23_164628_create_cam_allocations_table.php` |
| `billed_credit_note_id` column (negative-true-up link) | `database/migrations/2026_06_29_000002_add_billed_credit_note_to_cam_allocations.php` |
| CLI command (`cam:reconcile`) | `app/Console/Commands/CamAnnualReconciliationCommand.php` |
| Scheduled run + config keys | `routes/console.php:37-44`, `config/billing.php:35-37` |

### Relevant commits (this session's hardening)

- `67ae0e0` settle a positive true-up on a recovery invoice immediately (review pass 5)
- `e9e6235` bill a positive true-up on the next monthly run, not a lost back-dated charge (superseded by `67ae0e0`)
- `c022f9f` model a negative true-up as a credit note, not a lost negative charge
- `8a0a5c6` CAM credit auto-applies + lock-safe credit-note apply
- `91fb4be` lock-safe `generateAllocations` — can't clobber a billed allocation
- `76bc843` return consumed credit when a credited invoice is cancelled
- `bb1ceed` net AR for credits, billing run-lock, late-fee via recomputeTotals
- `db2c88e` reconciliation: CAM allocations check added to the books harness

---

## Related

- [`docs/money/04-cam-reconciliation.md`](04-cam-reconciliation.md) — this document.
- [`docs/modules/08-cam.md`](../modules/08-cam.md) — the CAM module reference (domain model, Filament resources, lifecycle, full test index).
- [`docs/modules/07-credit-notes.md`](../modules/07-credit-notes.md) — credit-note lifecycle and the apply/void/reverse rules used by the negative-true-up path.
- [`docs/modules/05-billing-invoices.md`](../modules/05-billing-invoices.md) — invoices, `recomputeTotals()`, the monthly billing engine and its active/expiry filter (why deferring loses revenue).
- [`docs/modules/06-payments.md`](../modules/06-payments.md) — captured-payment allocation that `recomputeTotals()` sums alongside applied credits.
- [`docs/modules/04-leases.md`](../modules/04-leases.md) — lease status, `payment_terms_days`, `currency`, master unit / `area_sqm` (the pro-rata weight).
- [`docs/BUSINESS-RULES.md`](../BUSINESS-RULES.md) — the books-reconciliation harness (`billing:reconcile`) and control totals an accountant reconciles against.
