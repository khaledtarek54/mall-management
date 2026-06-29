# Late Fees — complete reference

> Scope: the entire late-fee mechanism — when an invoice becomes eligible, the exact fee formula and every
> input that feeds it, where the percent / minimum / grace-period values actually come from, the
> idempotency + row-lock guards that stop double-charging, and the fact that the fee now settles through the
> single source of truth (`recomputeTotals()`) rather than writing the balance directly. Every statement is
> grounded in the current code with `file:line` citations so a reader can verify each claim.
>
> Audience: the business/finance team **and** new engineers. Nothing is left implicit.

---

## Plain-language summary

A **late fee** is a penalty the mall operator (Eltizam) automatically adds to a tenant's invoice when that
invoice has been unpaid for too long. "Too long" means past its due date **plus a grace period** (default
**7 days**). The penalty is a percentage of whatever is still owed (default **2%**), but never less than a
floor amount (default **EGP 50**). So a tenant who is only a couple of days late is never penalised, and a
tenant with a tiny remaining balance still pays at least the floor.

The fee is **added once**. A nightly job sweeps every overdue invoice; if an invoice already has a late-fee
line, it is left alone. Re-running the job (the same night, the next night, manually, by accident) never
stacks a second fee onto an invoice that already has one. This is the single most important property of the
system: **one overdue invoice → at most one late fee, ever** (unless an operator manually deletes the line).

The fee shows up as a new line item on the invoice (type `late_fee`, no VAT), which raises the invoice
subtotal and total, which in turn raises what the tenant owes (the balance) and flips the invoice status to
`overdue`. The tenant sees a larger bill; the operator's accounts-receivable (AR) goes up by exactly the fee.

Three things this document insists on, because two of them were real issues that have been hardened:

1. **The fee is applied exactly once**, even under two concurrent job runs — guaranteed by a database row
   lock plus an idempotency check re-evaluated *inside* the transaction.
2. **The fee no longer writes the invoice balance directly.** It bumps only the non-derived header amounts
   (`subtotal`, `total`) and then calls `recomputeTotals()`, the single source of truth, to re-derive
   `paid_amount` / `balance` / `status`. (This was the `L1` hardening fix — see
   [Recent fixes](#recent-fixes--current-correct-behaviour).)
3. **The configurable values the service reads come from `config/billing.php` (env-backed), not from the
   DB-backed Settings page.** This is a live gotcha — see
   [Configuration source of truth](#configuration-source-of-truth-important-gotcha).

---

## The exact rule / formula

### Eligibility — which invoices get a fee

`LateFeeService::runForToday()` selects the candidate set
(`app/Services/LateFeeService.php:20-30`). An invoice is **considered** if and only if **all** of these hold:

| Condition | Code | Meaning |
| --- | --- | --- |
| Status is one of `issued`, `partially_paid`, `overdue` | `whereIn('status', ['issued','partially_paid','overdue'])` (`:27`) | Excludes `paid`, `cancelled`, `credited`, `disputed`, `draft`. A disputed or cancelled invoice is never penalised. |
| Balance strictly greater than 0 | `where('balance', '>', 0)` (`:28`) | There must be money still owed. A zero-balance invoice is skipped. |
| Due date is on or before the cutoff | `whereDate('due_date', '<=', $cutoff)` (`:29`) | The grace window has fully elapsed (see cutoff below). |

The **cutoff** is computed as (`:22-24`):

```
$today    = $today ?? CarbonImmutable::now()->startOfDay()
$graceDays = (int) config('billing.late_fee_grace_days', 7)   // default 7
$cutoff    = $today->subDays($graceDays)
```

So an invoice is eligible when `due_date <= today − grace_days`, i.e. it has been overdue for **strictly more
than `grace_days` whole days** (the comparison is on the date part only, via `whereDate`). With the default
7-day grace and "today" = 2026-06-29, the cutoff is 2026-06-22: any invoice due **on or before** 2026-06-22
is eligible; an invoice due 2026-06-23 is not yet.

> `$today` is injectable. The command/job pass an explicit date when `--date=YYYY-MM-DD` is supplied,
> otherwise `CarbonImmutable::now()->startOfDay()` (`ApplyLateFeesCommand.php:26-28`,
> `ApplyLateFees.php:24-26`). This makes the sweep deterministic and back-datable for testing/replay.

### Per-invoice idempotency guard

Even when an invoice is in the candidate set, `applyTo()` adds a fee **only if the invoice does not already
carry a `late_fee` line item** (`app/Services/LateFeeService.php:71`):

```php
if (! $locked || $locked->items()->where('type', 'late_fee')->exists()) {
    return false;   // already applied (or row vanished) → no-op
}
```

This check runs against the **row-locked** invoice loaded *inside* the transaction (see
[Idempotency + lock guard](#idempotency--lock-guard) below), so it is concurrency-safe.

### The fee formula

The fee is computed in `applyTo()` (`app/Services/LateFeeService.php:62-75`):

```php
$percent = (float) config('billing.late_fee_percent', 2.0);    // default 2.0
$min     = (float) config('billing.late_fee_minimum', 50.0);   // default 50.00
...
$fee = max($min, round((float) $locked->balance * $percent / 100, 2));
```

In words:

```
fee = max( minimum , round( current_balance × percent ÷ 100 , 2 ) )
```

| Symbol | Source | Default | Config key |
| --- | --- | --- | --- |
| `percent` | `config('billing.late_fee_percent')` | `2.0` (%) | `late_fee_percent` |
| `minimum` | `config('billing.late_fee_minimum')` | `50.00` (EGP) | `late_fee_minimum` |
| `grace_days` | `config('billing.late_fee_grace_days')` | `7` (days) | `late_fee_grace_days` |
| `current_balance` | the row-locked invoice's `balance` at fee time | — | (live AR amount) |

All three config keys are declared in `config/billing.php:14-16`, each defaulting from an env var
(`LATE_FEE_PERCENT`, `LATE_FEE_GRACE_DAYS`, `LATE_FEE_MINIMUM`).

**Rounding:** the percentage component is rounded to **2 decimal places** before the `max()` so the stored
fee is always whole-piastre. The `max()` with the floor is applied *after* rounding.

**Inputs in / output out:**
- Input: the invoice's `balance` (the amount still owed at the moment the fee is applied), plus the three
  config values.
- Output: a single new `InvoiceItem` of type `late_fee`, and an updated invoice header (`subtotal`, `total`,
  `balance`, `status`).

### The line item that is created

`applyTo()` writes one `InvoiceItem` (`app/Services/LateFeeService.php:77-85`):

| Field | Value | Note |
| --- | --- | --- |
| `invoice_id` | the invoice | |
| `description` | `__('admin.enums.invoice_item_type.late_fee')` → "Late Fee" | Translated; Arabic "غرامة تأخير" (`lang/en/admin.php:1229`, `lang/ar/admin.php:1229`). |
| `type` | `late_fee` | A valid enum value on the column (`database/migrations/2026_06_28_000002_add_marketing_to_invoice_items_type.php:30`). |
| `amount` | `$fee` | The computed fee. |
| `vat_rate` | `0` | **Late fees carry no VAT.** |
| `vat_amount` | `0` | |
| `total` | `$fee` | |

> Note: `InvoiceItem::booted()` has a `saving` hook (`app/Models/InvoiceItem.php:33-38`) that *recomputes*
> `vat_amount = round(amount × vat_rate / 100, 2)` and `total = round(amount + vat_amount, 2)` on every save.
> Because `vat_rate` is `0`, this confirms `vat_amount = 0` and `total = amount = fee` — the explicit values
> passed in agree with what the hook would derive. A late-fee line never funds a marketing budget: the
> marketing-accrual hook (`InvoiceItem.php:42-54`) fires only for `type === 'marketing'`.

### How the header amounts move (settling through the single source of truth)

After creating the line, `applyTo()` updates the invoice header (`app/Services/LateFeeService.php:90-93`):

```php
$locked->subtotal = (float) $locked->subtotal + $fee;   // non-derived
$locked->total    = (float) $locked->total + $fee;      // non-derived
$locked->status   = 'overdue';
$locked->recomputeTotals();                              // re-derives the rest
```

It bumps **only** `subtotal` and `total` (the non-derived header fields), sets `status` to `overdue`, then
hands off to `Invoice::recomputeTotals()` (`app/Models/Invoice.php:255-283`), which is the **single source of
truth** for AR. `recomputeTotals()`:

1. Sums **captured** payments allocated to the invoice (`payments.status = 'captured'`, summed over the
   `invoice_payment.allocated_amount` pivot — `Invoice.php:257-259`).
2. Adds `credit_applied_amount` (applied credit notes settle AR too — `Invoice.php:264`).
3. Sets `paid_amount = round(paid, 2)` and `balance = round(max(0, total − paid_amount), 2)`
   (`Invoice.php:266-267`).
4. Re-derives `status` (unless the invoice is in a manual terminal state `cancelled` / `credited` /
   `disputed`): `paid` if balance ≤ 0 and something was paid; `partially_paid` if some paid; `overdue` if the
   due date is past; else `issued` (`Invoice.php:270-280`).
5. Persists with `saveQuietly()` (`Invoice.php:282`).

Net effect for a late fee: `total` goes up by `fee`, `paid_amount` is unchanged (no new payment), so
`balance` goes up by exactly `fee`, and because the invoice is past due the status lands on `overdue`. The
invariant `balance = total − paid_amount` holds throughout — the service never writes `balance` itself.

### Where the values are read vs. where the Settings page edits them — see the gotcha

The service reads `config('billing.*')`. The `/admin/settings` Billing tab edits a **different**,
DB-backed object. This matters; it is documented under
[Configuration source of truth](#configuration-source-of-truth-important-gotcha).

---

## Worked examples (real numbers)

Assume defaults: **percent = 2.0%**, **minimum = EGP 50.00**, **grace_days = 7**, and "today" = 2026-06-29
(cutoff = 2026-06-22).

### Example 1 — percentage exceeds the floor

- Invoice INV-001, `total` = 10,000.00, no payments → `balance` = 10,000.00, status `issued`, `due_date`
  2026-06-10.
- Eligible? Status `issued` ✓, balance > 0 ✓, due 2026-06-10 ≤ cutoff 2026-06-22 ✓ → **considered**.
- No existing `late_fee` line → proceeds.
- `fee = max(50.00, round(10000.00 × 2.0 / 100, 2)) = max(50.00, 200.00) = ` **200.00**.
- A `late_fee` line of 200.00 (VAT 0) is added.
- Header: `subtotal` += 200 → ; `total` 10,000 → **10,200.00**; `paid_amount` 0 (unchanged);
  `balance` → **10,200.00**; `status` → **overdue**.

### Example 2 — floor wins (small balance)

- Invoice INV-002, `total` = 1,000.00, paid 1,500? no — say `balance` = 1,000.00, due 2026-06-01.
- `fee = max(50.00, round(1000.00 × 2.0 / 100, 2)) = max(50.00, 20.00) = ` **50.00** (the floor wins,
  because 2% of 1,000 = 20 < 50).
- `total` 1,000 → **1,050.00**; `balance` → **1,050.00**; status **overdue**.

### Example 3 — partial payment already made (fee is on the *remaining* balance)

- Invoice INV-003, `total` = 5,000.00, a captured payment of 3,000.00 → `balance` = 2,000.00, status
  `partially_paid`, due 2026-06-05.
- Eligible (status `partially_paid` ✓, balance 2,000 > 0 ✓, due ≤ cutoff ✓).
- `fee = max(50.00, round(2000.00 × 2.0 / 100, 2)) = max(50.00, 40.00) = ` **50.00** (floor wins; 2% of the
  *remaining* 2,000 is 40).
- `total` 5,000 → **5,050.00**; `recomputeTotals` keeps `paid_amount` = 3,000.00; `balance` → **2,050.00**;
  status → **overdue**. The fee is charged on the **balance**, not the original total.

### Example 4 — rounding

- `balance` = 1,234.57, percent 2.0 → `1234.57 × 2 / 100 = 24.6914` → `round(…, 2) = 24.69` →
  `max(50.00, 24.69) = ` **50.00** (floor). With percent 7.0:
  `1234.57 × 7 / 100 = 86.4199` → `86.42` → `max(50.00, 86.42) = ` **86.42**.

### Example 5 — re-run is a no-op (idempotency)

- Run the job again the next night against INV-001 (now `total` 10,200, status `overdue`, balance 10,200).
- It is still in the candidate set (status `overdue`, balance > 0, due past) so it is **considered**, but
  `applyTo()` finds an existing `late_fee` line and returns `false` → counted as **skipped**, no second fee.

---

## Every edge case + how the system handles it

| Edge case | Behaviour | Where |
| --- | --- | --- |
| Invoice still within grace (due 3 days ago, grace 7) | Not selected; `due_date > cutoff`. No fee. | `LateFeeService.php:24,29` |
| Invoice exactly on the boundary (`due_date == cutoff`) | **Eligible** — comparison is `<=`. | `LateFeeService.php:29` |
| Zero or negative balance | Excluded by `balance > 0`. No fee even if very overdue. | `LateFeeService.php:28` |
| Invoice `paid` / `cancelled` / `credited` / `disputed` / `draft` | Not in the status whitelist → never considered. | `LateFeeService.php:27` |
| Invoice already has a `late_fee` line | `applyTo()` returns `false` → **skipped**, no second fee. Idempotent across re-runs. | `LateFeeService.php:71` |
| Two concurrent job runs hit the same invoice | Row lock + re-check inside the transaction: the second run sees the line (or waits then sees it) and returns `false`. At most one fee. | `LateFeeService.php:65-73` |
| Fee percentage rounds below the floor | `max()` returns the floor (e.g. EGP 50). | `LateFeeService.php:75` |
| Balance changes (a payment lands) between selection and `applyTo` | The fee is computed from the **locked** row's balance read inside the transaction, not the stale value from the initial `get()`. | `LateFeeService.php:69,75` |
| Invoice row deleted between selection and lock | `lockForUpdate()->find()` returns `null` → `! $locked` → returns `false` (skipped), no error. | `LateFeeService.php:69,71` |
| One invoice throws mid-batch | Caught per-invoice; logged to the `ops` channel via `OpsLog::error`; counted as **failed**; the loop continues. | `LateFeeService.php:42-48` |
| Custom `--date` for replay/back-fill | `runForToday($today)` uses the supplied date for the cutoff; deterministic. | `ApplyLateFeesCommand.php:26-31`, `ApplyLateFees.php:24-28` |
| Operator deletes the `late_fee` line later | A subsequent run no longer sees a `late_fee` line, so the invoice becomes eligible again and a fresh fee can be applied. (Deletion is the only way to "re-charge".) | `LateFeeService.php:71` |
| Late fee and VAT | A late fee carries **no VAT** (`vat_rate = 0`), unlike service charges (14%). | `LateFeeService.php:82-84` |
| Marketing accrual | A `late_fee` line never touches a marketing budget — the accrual hook only fires for `type === 'marketing'`. | `InvoiceItem.php:42-54` |
| Owner-overdue notification overlap | A separate command (`billing:scan-overdue-invoices`) alerts Jawad owners; it is idempotent via `owner_overdue_notified_at` and independent of fee application. | `ScanOverdueInvoicesCommand.php:25-31,59-73` |

---

## Invariants + gotchas

- **Money invariant upheld.** The fee path bumps only `subtotal` and `total`, then calls
  `recomputeTotals()`, which re-derives `paid_amount` / `balance` / `status` per the project invariant
  `paid_amount = captured payments + credit_applied_amount`, `balance = total − paid_amount`. The service
  **never writes `balance` directly** (`LateFeeService.php:90-93`). This is the `L1` hardening fix.
- **At most one late fee per invoice.** Enforced by the `late_fee`-line existence check
  (`LateFeeService.php:71`). The only ways to add a second are: (a) delete the existing line, or (b) bypass
  the service.
- **Concurrency-safe.** `applyTo()` runs inside `DB::transaction`, loads the invoice with
  `lockForUpdate()->find()`, and re-checks the idempotency guard **inside** the transaction
  (`LateFeeService.php:65-73`). This mirrors the project rule that scheduled scans must be idempotent +
  lock-safe (re-check the stamp inside the transaction). The scheduled job additionally uses
  `withoutOverlapping()` (`routes/console.php:35`) and the queued `ApplyLateFees` job sets `tries = 1`
  (`ApplyLateFees.php:19`) so a failed batch is not silently retried into a double-charge window.
- **Fee is on the current balance, not the original total.** Partial payments and applied credits reduce
  `balance` first, and the fee is `2%` of what is *still owed* (`LateFeeService.php:75`). Example 3 above.
- **No VAT on late fees** (`vat_rate = 0`). Do not "fix" this to 14% without a business decision — it is
  intentional.
- **Status is forced to `overdue`.** `applyTo()` sets `status = 'overdue'` before `recomputeTotals()`; since
  the invoice has a positive balance and a past due date, `recomputeTotals()` keeps it `overdue`
  (`LateFeeService.php:92`, `Invoice.php:275-276`). It does **not** override the manual terminal states
  `cancelled` / `credited` / `disputed` — but those are already excluded from selection, so they never reach
  `applyTo()` from the batch path.
- **Batch stats.** `runForToday()` returns `{considered, applied, skipped, failed}` (`LateFeeService.php:32`)
  and logs a summary via `OpsLog::info('Late fee batch complete', …)` to the `ops` log channel
  (`LateFeeService.php:51`, `app/Support/OpsLog.php:47`). The command renders these as a table
  (`ApplyLateFeesCommand.php:33-36`) and returns a non-zero exit code if anything failed (`:38`).

### Configuration source of truth (IMPORTANT gotcha)

There are **two** places that look like they hold the late-fee policy, and they are **not** wired together:

1. **`config('billing.late_fee_*')`** — file-/env-backed (`config/billing.php:14-16`, defaults
   `2.0` / `7` / `50.00` from `LATE_FEE_PERCENT` / `LATE_FEE_GRACE_DAYS` / `LATE_FEE_MINIMUM`). **This is
   what `LateFeeService` reads** (`LateFeeService.php:23,62-63`).
2. **`App\Settings\BillingSettings`** — a Spatie DB-backed settings object edited by the admin Settings page
   (`/admin/settings` → Billing tab), holding `late_fee_percent` / `late_fee_grace_days` /
   `late_fee_minimum` (`app/Settings/BillingSettings.php:18-20`,
   `app/Filament/Admin/Pages/Settings.php:79-81,138-146`). Its DB defaults are also seeded from the same env
   vars (`database/settings/2026_05_25_200000_create_billing_settings.php:9-11`).

**The gotcha:** `LateFeeService` consults `config('billing.*')`, **not** `BillingSettings`. There is no
provider that pushes `BillingSettings` back into `config` at boot (confirmed: no such binding in
`app/Providers/`). So **changing the late-fee values on the Settings page does not change the fees the
nightly job actually charges** unless the corresponding env vars / `config/billing.php` are also updated and
the config cache is rebuilt. If finance wants a different percent/floor/grace to take effect today, set the
`LATE_FEE_*` env vars (and `php artisan config:cache` if caching) — do not rely on the Settings UI alone for
late fees. Treat aligning these two as a known follow-up.

---

## Where it lives in the code (file:line index)

| What | File:line |
| --- | --- |
| Service entry / batch sweep | `app/Services/LateFeeService.php:20-54` (`runForToday`) |
| Cutoff = `today − grace_days` | `app/Services/LateFeeService.php:22-24` |
| Candidate query (status, balance, due) | `app/Services/LateFeeService.php:26-30` |
| Per-invoice apply (transaction + lock) | `app/Services/LateFeeService.php:60-97` (`applyTo`) |
| Reads percent / minimum from config | `app/Services/LateFeeService.php:62-63` |
| Row lock + idempotency re-check | `app/Services/LateFeeService.php:65-73` |
| Fee formula `max(min, round(balance×%/100, 2))` | `app/Services/LateFeeService.php:75` |
| Creates the `late_fee` line item | `app/Services/LateFeeService.php:77-85` |
| Bumps subtotal/total, then `recomputeTotals()` | `app/Services/LateFeeService.php:90-93` |
| Single source of truth (re-derives balance/status) | `app/Models/Invoice.php:255-283` (`recomputeTotals`) |
| Invoice-item save hook (derives vat_amount/total) | `app/Models/InvoiceItem.php:31-55` |
| Config keys + defaults | `config/billing.php:14-16` |
| DB-backed settings (edited by UI, NOT read by the service) | `app/Settings/BillingSettings.php:18-20` |
| Settings page persistence | `app/Filament/Admin/Pages/Settings.php:79-81,138-146` |
| Settings seed defaults | `database/settings/2026_05_25_200000_create_billing_settings.php:9-11` |
| `late_fee` is a valid item-type enum value | `database/migrations/2026_06_28_000002_add_marketing_to_invoice_items_type.php:30` |
| CLI command | `app/Console/Commands/ApplyLateFeesCommand.php` (signature `billing:apply-late-fees {--date=} {--queue}`, `:12`) |
| Queued job (`timeout 600`, `tries 1`) | `app/Jobs/ApplyLateFees.php:17-29` |
| Daily schedule (04:00, no overlap) | `routes/console.php:32-35` |
| Owner-overdue alert (separate, idempotent) | `app/Console/Commands/ScanOverdueInvoicesCommand.php` |
| Batch logging (`ops` channel) | `app/Services/LateFeeService.php:44-51`, `app/Support/OpsLog.php:30-47` |
| Translations ("Late Fee" / "غرامة تأخير") | `lang/en/admin.php:1229`, `lang/ar/admin.php:1229` |

### How it is triggered

- **Scheduled (production):** `Schedule::job(new ApplyLateFees)->dailyAt('04:00')->withoutOverlapping()`
  (`routes/console.php:32-35`). Runs every day at 04:00 app-time against today's date.
- **Manual / synchronous:** `php artisan billing:apply-late-fees [--date=YYYY-MM-DD]` — runs in-process and
  prints a `considered / applied / skipped / failed` table (`ApplyLateFeesCommand.php:30-36`).
- **Manual / queued:** `php artisan billing:apply-late-fees --queue [--date=…]` dispatches the
  `ApplyLateFees` job to the queue (`ApplyLateFeesCommand.php:20-24`).

---

## Recent fixes / current correct behaviour

- **`L1` — late fee settles via `recomputeTotals()` (commit `bb1ceed`).** Previously `applyTo()` wrote
  `balance` directly (`$locked->balance = balance + fee; $locked->save();`), bypassing the single source of
  truth — an invariant smell. The current code bumps only `subtotal` and `total`, sets `status = 'overdue'`,
  and calls `recomputeTotals()` so `balance` and `status` are **re-derived** from `total − paid_amount`
  (`app/Services/LateFeeService.php:88-93`). Behaviourally the balance still rises by exactly the fee, but it
  is now derived, not hand-written, so a later payment/credit recompute stays consistent.
- **Idempotency lock-check moved inside the transaction.** The `late_fee`-line existence check runs against a
  `lockForUpdate()`-loaded row *inside* `DB::transaction`, so two overlapping runs cannot both pass the
  "no late_fee yet" check and double-charge (`app/Services/LateFeeService.php:65-73`).

This document reflects the current code as of the `bb1ceed` (late-fee L1) and surrounding money-hardening
commits. It does not describe the pre-fix direct-balance-write behaviour except to note it was removed.

---

## Related

- [`00-money-model.md`](00-money-model.md) — the AR invariant (`paid_amount` / `balance` / `recomputeTotals`)
  that late fees flow through.
- [`01-billing-monthly.md`](01-billing-monthly.md) — how invoices and their balances are created in the first
  place (the inputs to "overdue").
- [`02-vat-and-tax.md`](02-vat-and-tax.md) — why a late fee carries 0% VAT while service charges carry 14%.
- [`06-payments.md`](06-payments.md) — captured payments that reduce `balance` before a fee is computed.
- [`07-credit-notes.md`](07-credit-notes.md) — applied credits also settle AR (`credit_applied_amount`) and
  thus shrink the balance a late fee is charged on.
- [`03-marketing-levy.md`](03-marketing-levy.md), [`04-cam-reconciliation.md`](04-cam-reconciliation.md),
  [`05-percentage-rent.md`](05-percentage-rent.md) — sibling charge types (none of which a `late_fee` line
  participates in).
