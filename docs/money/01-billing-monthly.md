# Monthly Billing Run — exhaustive reference

> Scope: the **monthly billing run** that generates each active lease's recurring invoice.
> This document is exhaustive on purpose: it states every rule, every input, every
> rounding step, and every edge case, with file:line citations so a finance person
> or a new engineer can verify each claim against the source.
>
> Primary source files:
> - `app/Services/MonthlyBillingService.php` — all the logic.
> - `app/Jobs/RunMonthlyBilling.php` — the queued wrapper + per-period overlap guard.
> - `app/Console/Commands/RunMonthlyBillingCommand.php` — the manual CLI entry point.
> - `routes/console.php:24-30` — the scheduled monthly trigger.
> - `config/billing.php` — schedule day/time settings.

---

## Plain-language summary

Once a month the system creates one invoice per active lease, listing that lease's
recurring charges (base rent, service charge, marketing levy, utilities, parking,
etc.) for that calendar month. It runs automatically on the **1st of each month at
02:00** (both the day and time are configurable), and a person can also run it by
hand from the command line for the current month or any back-month.

For a normal full month the invoice is just the sum of the lease's charges that
apply that month, plus VAT where applicable. For a tenant that **moves in part-way
through the month**, the very first invoice can be **pro-rated** — they only pay for
the days from their move-in date to the end of the month, counted by calendar day.

The run is **safe to repeat**. If you run it twice for the same month, a lease that
already has an invoice for that month is silently skipped — it will not be billed
twice. Multiple guards (a period-overlap check, a per-period lock, and a queue
"no overlap" middleware) make sure a manual run and the scheduled run cannot collide
and double-bill.

Each lease is billed inside its **own database transaction**, so if one lease fails
(bad data, etc.) the rest of the run still completes; the failure is logged and
reported in the run summary, not swallowed.

---

## The exact rule / formula

### 1. Entry points (how a run starts)

| Path | Trigger | Code |
| --- | --- | --- |
| Scheduled | `monthlyOn(billing.monthly_billing_day=1, billing.monthly_billing_time='02:00')` dispatches `RunMonthlyBilling` for the **current** month | `routes/console.php:24-30` |
| Queued (manual) | `php artisan billing:run-monthly --queue [--period=YYYY-MM]` dispatches the job | `RunMonthlyBillingCommand.php:20-24` |
| Synchronous (manual) | `php artisan billing:run-monthly [--period=YYYY-MM]` runs in-process and prints a table | `RunMonthlyBillingCommand.php:26-44` |
| Single lease | `MonthlyBillingService::generateForLease($lease, $period, $prorate)` (used by the admin "Generate invoice" action) | `MonthlyBillingService.php:127-168` |

All paths converge on `MonthlyBillingService::runForPeriod()` except the single-lease
action, which calls `generateForLease()`. Both ultimately call the private
`generateInvoiceForLease()` (`MonthlyBillingService.php:189-273`).

The **period** is always normalised to the **start of the month**:
`($period ?? now())->startOfMonth()` (`MonthlyBillingService.php:29`, also
`:129`, `RunMonthlyBilling.php:36-38`, `RunMonthlyBillingCommand.php:26-28`).
`--period` is parsed with `CarbonImmutable::createFromFormat('Y-m', ...)`
(`RunMonthlyBillingCommand.php:27`, `RunMonthlyBilling.php:37`), i.e. the format is
`YYYY-MM` (e.g. `2026-06`).

- `periodStart` = first day of the month.
- `periodEnd` = `$period->endOfMonth()` = last day of the month (`MonthlyBillingService.php:49`).

### 2. Which leases are eligible

`billForPeriod()` selects leases with this exact query (`MonthlyBillingService.php:60-66`):

```php
Lease::query()
    ->where('status', 'active')
    ->where('commencement_date', '<=', $periodEnd)
    ->where(function ($q) use ($periodStart) {
        $q->whereNull('expiry_date')->orWhere('expiry_date', '>=', $periodStart);
    })
    ->with(['charges' => fn ($q) => $q->where('is_active', true)])
```

A lease is billed **only if all three hold**:

1. **`status === 'active'`.** Draft, expired, terminated, etc. leases are never billed by this run.
2. **`commencement_date <= periodEnd`.** The lease must have started on or before the last day of the month. A lease commencing **next** month is not yet billable.
3. **`expiry_date IS NULL` OR `expiry_date >= periodStart`.** An open-ended lease (no expiry) always qualifies; a fixed-term lease qualifies as long as it hasn't expired before the month began. A lease that expired **last** month (`expiry_date < periodStart`) is excluded.

Notes:
- The **commencement/expiry window is an overlap test against the whole month**, not against the day. A lease whose `expiry_date` falls **mid-month** still qualifies (it's `>= periodStart`) and is billed the **full** month's charges — this run does **not** prorate the trailing/expiring month (only the first month, and only when `prorate` is requested — see §4).
- Leases are processed in batches of 100 via `chunkById(100, ...)` (`MonthlyBillingService.php:67`) so the run is memory-safe at portfolio scale.
- Only **active charges** are eager-loaded (`is_active = true`, `MonthlyBillingService.php:66`); inactive charges are never billed.

### 3. Which charges apply this month — `chargeAppliesToPeriod()`

For each eligible lease, every active charge is filtered through
`chargeAppliesToPeriod()` (`MonthlyBillingService.php:191-193`, `:275-299`). A charge
is included only if it passes the **date-window** test **and** its **frequency**
test.

**Date-window gate (`MonthlyBillingService.php:277-282`):**
- If `charge.start_date` is set and `start_date > periodEnd` → **excluded** (charge hasn't begun yet).
- If `charge.end_date` is set and `end_date < periodStart` → **excluded** (charge already ended).
- Otherwise it proceeds to the frequency test.

**Frequency test (`MonthlyBillingService.php:284-298`):**

| `frequency` | Applies when | Code |
| --- | --- | --- |
| `monthly` | **Always** (every month it's active). | `:285` |
| `quarterly` | The number of **calendar months** between `start_date` and `periodStart` is a multiple of 3. Computed day-of-month-agnostic as `((periodStart.year − start.year)*12 + periodStart.month − start.month) % 3 === 0`. With **no** `start_date`, falls back to `(periodStart.month − 1) % 3 === 0` (Jan/Apr/Jul/Oct). | `:289-291` |
| `annually` | `start_date.month === periodStart.month` (same calendar month each year). With **no** `start_date`, January only (`periodStart.month === 1`). | `:292-294` |
| `one_time` | `start_date` is set **and** `start_date` falls **within** `[periodStart, periodEnd]` (inclusive `between`). A one-time charge with no `start_date` never bills. | `:295-296` |
| anything else | **Excluded** (`default => false`). | `:297` |

> Why the quarterly math is hand-rolled: `diffInMonths()` under-counts when the
> period's day-of-month is earlier than the start's, which would push a quarter a
> month late for mid-month start dates. The explicit `year*12 + month` difference is
> day-of-month agnostic (`MonthlyBillingService.php:286-288`).

If **no** charge applies this month, the lease produces **no invoice** —
`generateInvoiceForLease()` returns `null` (`MonthlyBillingService.php:195-197`).
In the bulk run that lease still counts as `created` in the stats array
(see "Gotcha: a null invoice counts as created" below); in the single-lease path it
returns `status: 'skipped', reason: 'no_applicable_charges'`
(`MonthlyBillingService.php:161-163`).

### 4. First-month proration — the exact formula

Proration applies **only** when **all** of these are true
(`MonthlyBillingService.php:206`):

1. The caller passed `prorate = true`. **The bulk run now passes it (fixed 2026-08-08).** `billForPeriod()` calls `generateInvoiceForLease(..., prorate: true)`, so the scheduled/CLI portfolio run prorates a commencement month like the single-lease action always did. *Until that fix the bulk run took the default `false`, so a mid-month move-in the nightly run reached first was billed a **full month** — an overcharge that landed on exactly the leases nobody was watching. Regression: `tests/Feature/Regression/BulkBillingProratesCommencementTest.php`.* The `$prorate` argument survives as the single-lease **override** (`generateForLease(..., $prorate)`), for a lease whose contract bills the first month in full.
2. The lease has a `commencement_date`.
3. `commencement` is **within** the billing month: `commencement->between(periodStart, periodEnd)`.
4. `commencement` is **strictly after** the month start: `commencement->greaterThan(periodStart)`. (A 1st-of-month commencement bills the full month — factor stays `1.0`.)

When proration applies (`MonthlyBillingService.php:212-218`):

```php
$daysInPeriod = $periodStart->daysInMonth;                                        // 28/29/30/31
$daysBilled   = (int) abs($periodEnd->startOfDay()
                    ->diffInDays($commencement->startOfDay())) + 1;               // inclusive day count
$factor       = $daysBilled / $daysInPeriod;                                      // FULL precision, NOT rounded
$effectivePeriodStart = $commencement;                                            // invoice period_start = move-in day
```

- **`daysInPeriod`** = the number of days in the billing month (`Carbon::daysInMonth` — 28/29/30/31, leap-year aware).
- **`daysBilled`** = the **inclusive** count of days from `commencement` to `periodEnd`. Both ends are snapped to `startOfDay()` and the magnitude is taken with `abs(...)` before `+ 1`, so it is **sign-safe** and **day-granular**. A commencement on the last day of the month yields `daysBilled = 1`.
- **`factor`** = `daysBilled / daysInPeriod`, kept at **full floating precision** — it is deliberately **not** rounded.

Then **per line item**, the amount (not the factor) is rounded
(`MonthlyBillingService.php:221-239`):

```php
$amount    = round((float) $charge->amount * $factor, 2);          // amount is rounded, 2dp
$vatRate   = $charge->vat_applicable ? (float) $charge->vat_rate : 0.0;
$vatAmount = round($amount * ($vatRate / 100), 2);                 // VAT off the prorated amount
$total     = round($amount + $vatAmount, 2);
```

The line **description** gets a suffix when prorated:
`"{charge name} - {Month YYYY} ({NN}% pro-rated)"`, where `NN = round(factor * 100)`
(`MonthlyBillingService.php:225-228`). For a full month (`factor = 1`) no suffix is
added.

> **Why round the amount, not the factor (this session's HIGH-severity fix).**
> Rounding the factor to e.g. 4dp would turn a clean `1/30` into `0.0333`, and
> `0.0333 × 30000` would mis-bill. Rounding the **per-line amount** to 2dp instead
> means a clean fraction bills exactly. See commit `88816d4`
> ("proration undercharge (HIGH)") and `3057520`.
>
> **Why the inclusive day count is `abs(...) + 1` (the sign-safety fix).**
> Carbon 3's `diffInDays` is **signed and fractional**. The old
> `$periodEnd->diffInDays($start) + 1` added 1 to a **negative** magnitude — it
> undercharged every mid-month move-in and billed **0** for a last-day commencement.
> The current code snaps both ends to `startOfDay()`, takes `abs()`, casts to `int`,
> then `+ 1`, guaranteeing a positive whole-day inclusive count
> (`MonthlyBillingService.php:208-213`).

### 5. What the generated invoice contains

After items are built, the invoice header is computed and saved
(`MonthlyBillingService.php:241-262`):

```php
$subtotal  = round((float) $items->sum('amount'), 2);
$vatAmount = round((float) $items->sum('vat_amount'), 2);
$total     = round($subtotal + $vatAmount, 2);

$issueDate = $effectivePeriodStart;                              // = periodStart, OR commencement if prorated
$dueDate   = $issueDate->addDays($lease->payment_terms_days ?? 7);
```

The `Invoice::create([...])` writes (`MonthlyBillingService.php:248-262`):

| Column | Value | Source |
| --- | --- | --- |
| `lease_id` | the lease | `:249` |
| `tenant_id` | `lease->tenant_id` | `:250` |
| `status` | `'issued'` | `:251` |
| `issue_date` | `effectivePeriodStart` (commencement if prorated, else month start) | `:252` |
| `due_date` | `issue_date + (lease.payment_terms_days ?? 7)` days | `:253` |
| `period_start` | `effectivePeriodStart` (**the commencement day on a prorated first month**) | `:254` |
| `period_end` | `periodEnd` (always month-end) | `:255` |
| `subtotal` | sum of line `amount`s, 2dp | `:256` |
| `vat_amount` | sum of line `vat_amount`s, 2dp | `:257` |
| `total` | `subtotal + vat_amount`, 2dp | `:258` |
| `paid_amount` | `0` | `:259` |
| `balance` | `total` (nothing paid yet) | `:260` |
| `currency` | `lease->currency ?? 'EGP'` | `:261` |

Then each line is persisted as an `InvoiceItem` (`MonthlyBillingService.php:264-266`)
with `charge_id`, `description`, `type` (copied from the charge), `amount`,
`vat_rate`, `vat_amount`, `total`.

**Invoice number** is generated in the model `creating` hook, **not** in the service.
`Invoice::generateUniqueNumber()` produces `INV-{ASSET_CODE}-{YYYYMM}-{NNNN}`, where
the asset code is derived from `lease->unit->asset->code` (fallback `AW`), the
`YYYYMM` is from `issue_date`, and `NNNN` is a zero-padded per-prefix sequence that
checks `withTrashed()` to avoid colliding with soft-deleted invoices
(`Invoice.php:161-218`). A pay-link token (`Str::random(48)`) is also pre-generated
on create (`Invoice.php:215-217`).

**Marketing levy.** There is **no special code** in the billing service for the levy.
The 5%-of-base-rent marketing levy is realised as an ordinary recurring `marketing`
**Charge** on the lease (`MarketingLevyService::createLevyCharge`,
`app/Services/MarketingLevyService.php:41-56`: `monthly`, `vat_applicable = false`,
amount captured at creation time). It therefore flows through the **same charge loop**
and lands as a `marketing`-type `InvoiceItem`. That item's `saved`/`deleted` hook
(`app/Models/InvoiceItem.php:42-54`) recomputes the property's marketing budget
`accrued_amount` via `MarketingBudget::forPeriod($assetId, $year)->recomputeAccrued()`
— so the budget is **derived from invoice items**, never incremented. (See
`docs/money/` marketing sibling docs.)

### 6. Due-date calculation

`due_date = issue_date + lease.payment_terms_days` calendar days, defaulting to **7**
when the lease has no payment terms (`MonthlyBillingService.php:246`,
`$lease->payment_terms_days ?? 7`).

- For a **full month**, `issue_date = periodStart` (month-start), so e.g. June → due 8 June with default terms.
- For a **prorated first month**, `issue_date = commencement`, so due-date is anchored to the move-in day, not the 1st.

### 7. Notification on issue

After a successful create, `notifyInvoiceIssued()` fires
(`MonthlyBillingService.php:91`, `:165`, `:170-187`):
`tenant->notifyPortal(new InvoiceIssuedNotification($invoice))`. The notification's
channels are **`mail`, `database`, and `push`**
(`app/Notifications/InvoiceIssuedNotification.php:18-21`); the mail carries a PDF
attachment (`InvoicePdfService`, `:25`, `:34`). A notification failure is caught and
logged as a warning — it **never** rolls back the invoice
(`MonthlyBillingService.php:181-186`).

---

## Worked examples (real numbers)

Assume default `payment_terms_days = 7` unless noted, and a single base-rent charge
unless stated.

### A. Full month (the normal scheduled run)

Lease active, June 2026, base rent `EGP 50,000` (VAT-exempt) + service charge
`EGP 10,000` (VAT 14%). Not prorated (bulk run).

- Base rent line: amount `50,000.00`, VAT `0.00`, total `50,000.00`.
- Service charge line: amount `10,000.00`, VAT `round(10000 × 14/100) = 1,400.00`, total `11,400.00`.
- `subtotal = 60,000.00`, `vat_amount = 1,400.00`, `total = 61,400.00`.
- `issue_date = 2026-06-01`, `due_date = 2026-06-08`, `period_start = 2026-06-01`, `period_end = 2026-06-30`.

### B. Mid-month move-in, June 16 → 15/30 (prorate via single-lease action)

`commencement = 2026-06-16`, June has 30 days, base rent `EGP 1,000`.

- `daysInPeriod = 30`.
- `daysBilled = |diffInDays(2026-06-30, 2026-06-16)| + 1 = 14 + 1 = 15`.
- `factor = 15 / 30 = 0.5` (full precision).
- amount = `round(1000 × 0.5, 2) = 500.00`. Description: `"Base Rent - June 2026 (50% pro-rated)"`.
- `issue_date = period_start = 2026-06-16`, `period_end = 2026-06-30`, `due_date = 2026-06-23`.

### C. March 15 → 17/31 = 5,483.87 (the canonical example)

`commencement = 2026-03-15`, March has 31 days, base rent `EGP 10,000`.

- `daysInPeriod = 31`.
- `daysBilled = |diffInDays(2026-03-31, 2026-03-15)| + 1 = 16 + 1 = 17`.
- `factor = 17 / 31 = 0.548387096…` (full precision, **not** rounded).
- amount = `round(10000 × 0.548387…, 2) = round(5483.87096…, 2) = 5,483.87`. ✓
- `round(factor × 100) = round(54.8387…) = 55`, so the line reads `"... (55% pro-rated)"`. (The **percentage in the label** is a display rounding only; the **money** uses the unrounded factor.)
- `period_start = issue_date = 2026-03-15`, `period_end = 2026-03-31`, `due_date = 2026-03-22`.

### D. Last-day commencement → 1 day

`commencement = 2026-06-30` (last day of a 30-day month), base rent `EGP 1,000`.

- `daysBilled = |diffInDays(2026-06-30, 2026-06-30)| + 1 = 0 + 1 = 1`.
- `factor = 1 / 30 = 0.0333…`.
- amount = `round(1000 × 0.0333…, 2) = 33.33`.
- This is the case the old signed-diff bug billed **0**; the current `abs() + 1` guarantees **1 day**.

### E. Re-run / double-run is a no-op

Run B above, then immediately re-run billing for June 2026 (scheduled **or** the
single-lease action, prorate on or off). The period-overlap guard finds an invoice
with `period_start = 2026-06-16` ∈ `[2026-06-01, 2026-06-30]` and **skips** the lease
— no second invoice, no full-month re-bill. (See §Idempotency.)

---

## Every edge case + how the system handles it

| Edge case | Behaviour | Where |
| --- | --- | --- |
| **Lease commences next month** | Excluded (`commencement_date <= periodEnd` fails). | `MonthlyBillingService.php:62` |
| **Lease expired last month** | Excluded (`expiry_date >= periodStart` fails). | `:63-65` |
| **Lease expires mid-month** | Still billed, **full** month (no trailing proration). | `:63-65` |
| **Open-ended lease (no expiry)** | Always eligible. | `:64` |
| **Non-active status** (draft/terminated/expired) | Excluded entirely. | `:61` |
| **No active charges on the lease** | No invoice created (`null`). Bulk: counts as `created`; single: `skipped / no_applicable_charges`. | `:195-197`, `:161-163` |
| **All charges out of window / wrong frequency this month** | Same as "no applicable charges": `null` invoice. | `:191-197`, `:275-299` |
| **1st-of-month commencement, prorate requested** | `factor` stays `1.0` (the `greaterThan(periodStart)` guard fails) → full month. | `:206` |
| **Bulk run encounters a mid-month commencement** | Full month — bulk run never passes `prorate=true`. | `:87` |
| **Prorated invoice already exists, re-run** | Skipped by period-overlap guard (its `period_start` is mid-month). | `:75-83`, `:136-139` |
| **One lease throws mid-run** | Caught per-lease; counted in `failed` + `failed_lease_ids`; logged; **run continues**. | `:85-101` |
| **Concurrent run for same period** (manual races scheduled) | Second caller can't acquire `Cache::lock('billing:run:Y-m')` → returns a zero-stats result + warning, does nothing. Queue job also blocked by `WithoutOverlapping`. | `:34-41`, `RunMonthlyBilling.php:29-32` |
| **Notification (email/push) fails** | Logged as warning; invoice is **not** rolled back. | `:181-186` |
| **`one_time` charge with no `start_date`** | Never bills (requires `start_date` to be in-period). | `:295-296` |
| **Quarterly/annual charge with no `start_date`** | Falls back to calendar anchors (Jan/Apr/Jul/Oct for quarterly; January for annual). | `:289-294` |
| **VAT not applicable on a charge** | `vatRate = 0.0`, VAT line = 0 (e.g. base rent, marketing levy). | `:223` |
| **Invoice-number collision with a soft-deleted invoice** | `generateUniqueNumber` checks `withTrashed()` and increments past it (up to 100 attempts, else throws). | `Invoice.php:178-195` |

---

## Invariants + gotchas

- **Idempotency is a period-OVERLAP guard, not an exact month-start match.** The check is `Invoice::where('lease_id', …)->whereDate('period_start', '>=', periodStart)->whereDate('period_start', '<=', periodEnd)->exists()` (`MonthlyBillingService.php:75-78` for bulk, `:136-139` for single). A prorated first-month invoice stores `period_start = the mid-month commencement`, so an exact `= month-start` check would miss it and bill the month a **second** time (full). This was a confirmed HIGH defect — fixed in commit `3057520`. **Do not** change this back to an equality check.

- **Three layers of overlap protection (defence in depth):**
  1. **DB existence check** — the period-overlap guard above (per lease).
  2. **`Cache::lock('billing:run:' . $period->format('Y-m'), 900)`** in `runForPeriod()` (`MonthlyBillingService.php:34-41`) — serialises whole runs for the same period across the synchronous CLI path **and** the queued path, with a 900s TTL. If the lock can't be taken, the run returns a zero-stats array and logs a warning (`:37-41`). This is the "billing run-lock" from commit `bb1ceed`.
  3. **`WithoutOverlapping('monthly-billing:' . period)->dontRelease()`** queue middleware on the job (`RunMonthlyBilling.php:29-32`) — prevents two copies of the **queued** job for the same period from running at once; `dontRelease()` means a blocked copy is dropped, not retried.
  > There is **not yet** a DB-level unique constraint on `(lease_id, period)` — see `docs/qa/HARDENING-BACKLOG.md`, noted at `RunMonthlyBilling.php:24-28`. The three guards above are the current protection.

- **Per-lease transaction isolation.** Each lease is billed in its own `DB::transaction(...)` (`MonthlyBillingService.php:86-88`, `:148-150`). A failure rolls back **only that lease**; the run continues and records it in `failed` / `failed_lease_ids`.

- **Gotcha — a `null` invoice counts as `created` in the bulk stats.** In `billForPeriod()`, `$stats['created']++` runs unconditionally after the transaction, then `if ($invoice) notify` (`MonthlyBillingService.php:89-92`). A lease with no applicable charges returns `null` but is still tallied as `created`. So `created` is "leases processed without error", not strictly "invoices written". (The single-lease path is precise: it returns `skipped / no_applicable_charges`.)

- **Money rounding order is fixed and load-bearing:** the **per-line amount** is rounded to 2dp (`:222`), VAT is computed off the already-rounded amount (`:224`), header totals are the **sum of rounded line values** re-rounded to 2dp (`:241-243`). The proration **factor is never rounded** (`:217`). Don't reorder these.

- **`paid_amount` / `balance` are never set elsewhere.** The service writes `paid_amount = 0`, `balance = total` only at **creation** (`:259-260`). From then on, `Invoice::recomputeTotals()` is the single source of truth: `paid_amount = captured payments + credit_applied_amount`, `balance = max(0, total − paid_amount)` (`Invoice.php:255-283`). Never mutate them directly. (See `docs/modules/05-billing-invoices.md` and the credit-note sibling doc.)

- **Auto-status respects manual overrides.** New invoices are `'issued'`; `recomputeTotals()` will advance them to `partially_paid` / `paid` / `overdue` but **won't** override `cancelled` / `credited` / `disputed` (`Invoice.php:270`).

- **Cancelling a credit-consuming invoice returns the credit.** Out of scope for the run itself, but relevant downstream: cancelling an invoice that has `credit_applied_amount > 0` reverses that applied credit (`Invoice.php:235-238`); a terminal `credited` invoice keeps its credit consumed. See the credit-note sibling doc.

- **Scheduled-run config keys:** `billing.monthly_billing_day` (default `1`, env `MONTHLY_BILLING_DAY`) and `billing.monthly_billing_time` (default `'02:00'`, env `MONTHLY_BILLING_TIME`) in `config/billing.php`. Late-fee and CAM-reconciliation schedules live in the same file but are separate runs.

- **Time zone / "now".** When no `--period` is given, the run bills the month of `CarbonImmutable::now()` (app timezone). The job timeout is 600s and `tries = 1` (no automatic retry) — `RunMonthlyBilling.php:19-20`.

---

## Where it lives in the code (file:line index)

| What | File:line |
| --- | --- |
| `runForPeriod()` + per-period `Cache::lock` | `app/Services/MonthlyBillingService.php:27-44` |
| `billForPeriod()` — lease query, chunking, per-lease tx, stats | `app/Services/MonthlyBillingService.php:46-117` |
| Eligible-lease query (active + commencement/expiry window) | `app/Services/MonthlyBillingService.php:60-66` |
| Period-overlap idempotency guard (bulk) | `app/Services/MonthlyBillingService.php:75-83` |
| `generateForLease()` — single-lease action + guard | `app/Services/MonthlyBillingService.php:127-168` |
| `notifyInvoiceIssued()` | `app/Services/MonthlyBillingService.php:170-187` |
| `generateInvoiceForLease()` — items, proration, invoice header | `app/Services/MonthlyBillingService.php:189-273` |
| Proration formula (`daysInPeriod` / `daysBilled` / `factor`) | `app/Services/MonthlyBillingService.php:206-219` |
| Per-line amount/VAT/total + pro-rated label | `app/Services/MonthlyBillingService.php:221-239` |
| Header subtotal/VAT/total + due-date | `app/Services/MonthlyBillingService.php:241-246` |
| `Invoice::create([...])` field map | `app/Services/MonthlyBillingService.php:248-262` |
| `chargeAppliesToPeriod()` — date window + frequency | `app/Services/MonthlyBillingService.php:275-299` |
| Queued job + `WithoutOverlapping` middleware | `app/Jobs/RunMonthlyBilling.php:15-46` |
| CLI command `billing:run-monthly` | `app/Console/Commands/RunMonthlyBillingCommand.php:10-45` |
| Scheduled trigger (`monthlyOn`, config keys) | `routes/console.php:24-30` |
| Schedule config keys | `config/billing.php:23-25` |
| Invoice number / pay-link token / `creating` hook | `app/Models/Invoice.php:161-218` |
| `recomputeTotals()` (AR source of truth) | `app/Models/Invoice.php:255-283` |
| Invoice-item `saving`/`saved` hooks (VAT recompute + marketing accrual) | `app/Models/InvoiceItem.php:31-55` |
| Marketing levy charge creation | `app/Services/MarketingLevyService.php:41-56` |
| Invoices table schema (status enum, columns) | `database/migrations/2024_01_01_000006_create_invoices_table.php:11-47` |
| `marketing` added to charges/invoice-items enums | `database/migrations/2026_06_24_000005_*`, `2026_06_28_000002_*` |

---

## Related

- `docs/money/` — sibling money references (CAM true-ups, credit notes, late fees, payments) live alongside this file.
- `docs/modules/05-billing-invoices.md` — the invoices module (statuses, AR, payments, recompute).
- `docs/modules/07-credit-notes.md` — credit-note locking / reversal / auto-apply (the `credit_applied_amount` half of AR).
- `docs/modules/08-cam.md` — CAM annual reconciliation and positive/negative true-ups (separate scheduled run).
- `config/billing.php` — schedule + late-fee policy.
- `docs/qa/HARDENING-BACKLOG.md` — the outstanding `(lease_id, period)` unique-constraint item.

---

## The charge schedule (2026-08-08)

A charge type is a **date-ranged schedule**, not one mutable amount. A rent change closes the row
in force at the end of the previous month and opens the next from the 1st
([`ChargeScheduleService`](../../app/Services/ChargeScheduleService.php)); the escalation sweep
appends rather than overwrites.

What this changes for billing:

- **`chargeAppliesToPeriod()` already did the right thing** — it filtered on `start_date`/`end_date`
  long before anything wrote more than one row. The read path did not change.
- **Billing a past month bills the amount in force in that month.** Under the old model, re-billing
  March charged March at *today's* rent, because the single row held today's amount.
- **Exactly one recurring row per charge type may cover a period.** Two rows means an overlapping
  schedule — a bad import, a hand-edited date, a bug in a writer — and
  `assertScheduleUnambiguous()` throws a `DomainException` naming the rows. `runForPeriod()` catches
  per lease, so it is one failed lease in the run summary, never a silent double charge. **One-off
  charges are exempt**: a CAM true-up, a percentage-rent overage and a utility recharge can all
  genuinely land in one month.
- **Effective dates snap to the start of the billing month** (`ChargeScheduleService::billingBoundary()`),
  because the engine bills one amount per type per month. This reproduces the old behaviour exactly
  — overwriting an amount mid-month always billed that whole month at the new rate.
- **The marketing levy moves with the rent, on the same effective date.** It is a percentage of base
  rent, so leaving it single-row would have billed a past month's rent correctly beside a levy
  derived from today's — a worse inconsistency than the one the schedule set out to fix.

**The whole term is projected at signing.** `ChargeScheduleService::projectTermEscalations()` writes
every contracted `fixed_percent` step when a lease (or renewal) is created, so billing any future
month reads a real contracted amount rather than waiting for a nightly job to invent it. The
marketing levy is projected in lock-step, so a future month bills that year's rent beside that
year's levy. The escalation sweep still runs and is a no-op against a projected row.

Tests: `tests/Feature/Regression/ChargeScheduleTest.php` — including the two silent-money traps
(a renewal copying the whole history, and a month covered by two rows), both mutation-verified.
