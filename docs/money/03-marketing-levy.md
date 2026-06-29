# Marketing Levy & Marketing Fund — exhaustive reference

> Audience: business / finance team **and** new engineers. This documents the **current** behaviour of the marketing levy and the per-property marketing fund in complete detail. Every statement is grounded in the code at the file:line shown. Read alongside the sibling money docs listed under [Related](#related).

---

## Plain-language summary

**What it is.** Eltizam (the operator) runs a small **marketing fund** for each property, each year. The fund is **filled** by charging every tenant a **marketing levy** — a percentage of that tenant's **base rent** (5% by default) — which appears as its own line on the tenant's monthly invoice. The fund is **spent** by the operator on offers, promotions, events, and printed work, recorded one spend at a time.

**Why it works this way.** The levy is a real charge to the tenant (the invoice total includes it), not an internal book entry. Because the fund's income figure is **derived** directly from the marketing lines that were actually billed (rather than being "topped up" by a counter), it always reconciles with the invoices and **automatically corrects itself** if an invoice is cancelled or a marketing line is removed. The same derive-from-source pattern is used for spend (summed from the recorded spend rows) and for AR balances elsewhere in the system (`Invoice::recomputeTotals`). Nothing in the fund is a hand-maintained running total.

**The three moving parts.**
1. **The levy charge** — one recurring monthly `marketing` charge per lease, amount = rate × base rent, captured at creation (VAT-exempt, mirroring base rent). Lives on the lease; the normal billing engine turns it into an invoice line each month.
2. **The fund (budget)** — one `marketing_budgets` row per (property, year). Its `accrued_amount` (money collected) is re-derived from billed marketing invoice lines; its `spent_amount` is re-derived from spend rows; `balance = accrued − spent`.
3. **The spends** — `marketing_spends` rows against a fund. Overspend is allowed (balance can go negative) with a non-blocking warning.

---

## The exact rule / formula

### 1. The levy rate (the only configurable input)

- **Source of truth:** `App\Settings\MarketingSettings::$levy_rate_percent` — a `float`, **default `5.0`**, settings group `marketing`, editable by the operator at `/admin/settings`.
  - File: `app/Settings/MarketingSettings.php:17` (default) and `:21` (`group() === 'marketing'`).
- **How it is read:** `MarketingLevyService::ratePercent()` returns `(float) app(MarketingSettings::class)->levy_rate_percent`. If the settings row is missing (minimal/CI environments), it **falls back to `5.0`** inside a `try/catch`.
  - File: `app/Services/MarketingLevyService.php:21-29`.
- **Versioning model:** the rate is **NOT** kept in a separate effective-dated table. Instead, the rate in force is **captured onto each marketing `Charge` at the moment the charge is created/synced**. Changing the setting therefore affects only **future** charge syncs and future bills; it never rewrites an existing charge or an already-billed line.
  - Documented in the class docblock: `app/Settings/MarketingSettings.php:6-13` and `app/Services/MarketingLevyService.php:10-18`.

### 2. The per-lease levy amount

```
levy_amount = round( base_rent_monthly × (levy_rate_percent / 100), 2 )
```

- **Input:** `Lease::$base_rent_monthly` (cast `decimal:2`, `app/Models/Lease.php:70`).
- **Rate input:** `ratePercent()` (above).
- **Rounding:** half-up to **2 decimal places** via PHP `round(..., 2)`.
- **Code:** `MarketingLevyService::amountFor(Lease $lease)` — `app/Services/MarketingLevyService.php:31-34`.

### 3. The levy charge on the lease (`Charge`, `type = 'marketing'`)

`MarketingLevyService::createLevyCharge(Lease $lease)` (`app/Services/MarketingLevyService.php:41-56`) does an **`updateOrCreate`** keyed on `(lease_id, type='marketing')`, setting:

| Field | Value | Why |
|-------|-------|-----|
| `name` | `'Marketing Levy'` | Human label. |
| `amount` | `amountFor($lease)` | Rate × base rent, captured **now**. |
| `currency` | `$lease->currency ?? 'EGP'` | Mirrors lease currency. |
| `frequency` | `'monthly'` | Billed every month by the engine. |
| `vat_applicable` | `false` | **VAT-exempt**, mirroring base rent. |
| `vat_rate` | `0` | No VAT. |
| `start_date` | `$lease->commencement_date` | First period it applies. |
| `is_active` | `true` | Picked up by billing. |

- **Idempotent:** exactly **one** marketing charge per lease; calling again **re-syncs** the amount to current rate × current base rent (it does not create a duplicate). This is the only way an old charge picks up a new rate.
- **Who calls it (the lock-step guarantee):**
  - On lease creation (when rent > 0): `LeaseCreationService` — `app/Services/LeaseCreationService.php:124-130`.
  - On a rent change (when new rent > 0): `LeaseRentChangeService` — `app/Services/LeaseRentChangeService.php:87-91`.
  - On renewal: `LeaseRenewalService` — `app/Services/LeaseRenewalService.php:99`.
  - Backfill for legacy active leases without a marketing charge: `BackfillMarketingBudgetsCommand` — `app/Console/Commands/BackfillMarketingBudgetsCommand.php:35`.

### 4. How the levy becomes an invoice line (and inherits proration)

The marketing charge is billed by the **normal** monthly billing engine — there is **no** special marketing pass.

- `MonthlyBillingService::generateInvoiceForLease()` collects every active charge applicable to the period and maps each into an `InvoiceItem`, copying `type` straight through — so a `marketing` charge yields an `invoice_items` row with `type = 'marketing'`.
  - File: `app/Services/MonthlyBillingService.php:189-273` (charge→item map at `:221-239`; `type` copied at `:233`).
- **Proration applies to the levy line too.** When a lease commences mid-month and the caller asks to prorate, every charge — including the marketing levy — is multiplied by the same day-granular factor:
  ```
  daysInPeriod = number of days in the billing month
  daysBilled   = whole inclusive days from commencement to period end
  factor       = daysBilled / daysInPeriod        (full precision, NOT pre-rounded)
  line.amount  = round(charge.amount × factor, 2) (the AMOUNT is rounded, not the factor)
  ```
  - File: `app/Services/MonthlyBillingService.php:206-219` (factor) and `:222-223` (per-line amount). So the **levy is 5% of the prorated rent**, because the rent line is prorated by the same factor — see [Edge cases](#proration).
- **VAT on the line:** the item-`saving` hook recomputes `vat_amount = round(amount × vat_rate/100, 2)` and `total = round(amount + vat_amount, 2)`. Because the marketing charge carries `vat_rate = 0`, the levy line has **`vat_amount = 0`, `total = amount`**.
  - File: `app/Models/InvoiceItem.php:33-38`.

### 5. The fund accrual is DERIVED, not incremented (the core rule)

`MarketingBudget::recomputeAccrued()` **re-derives** `accrued_amount` from source every time it runs — it is never an `increment()`:

```
accrued_amount = SUM(invoice_items.amount)
  WHERE invoice_items.type = 'marketing'
    AND the parent invoice.status != 'cancelled'
    AND YEAR(invoice.issue_date) = budget.period_year
    AND invoice.lease.unit.asset_id = budget.asset_id
```

- **Code:** `app/Models/MarketingBudget.php:75-87`. The query joins through `whereHas('invoice', …)` → `whereHas('lease.unit', …)` and `->sum('amount')`, then `round(…, 2)` and `update(['accrued_amount' => …])`.
- **It sums `amount` (the ex-VAT base), not `total`.** For marketing lines these are equal (VAT-exempt), but the field summed is `amount`.
- **Cancelled invoices are excluded** (`status != 'cancelled'`), which is what makes the accrual **auto-reverse** when an invoice is cancelled.

**What triggers a re-derive (reactive, never a manual counter):**

1. **Billing / any save or delete of a marketing line item** — `InvoiceItem::booted()` registers a `$syncMarketing` closure on both `saved` and `deleted`. If `type === 'marketing'`, it resolves the asset (`invoice.lease.unit.asset_id`) and the year (`invoice.issue_date.year`) and calls `MarketingBudget::forPeriod($assetId, $year)->recomputeAccrued()`.
   - File: `app/Models/InvoiceItem.php:42-54`.
2. **Invoice cancellation / un-cancellation (status-only change)** — the item hook does **not** fire on a status-only change to the parent invoice, so `Invoice::booted()`'s `updated` hook re-derives. It fires only when `status` actually changed and either the old or new status is `cancelled`, the invoice has a marketing item, and asset+year resolve.
   - File: `app/Models/Invoice.php:223-248` (status guard `:224`; cancelled-edge guard `:240-242`; re-derive `:243-247`).
3. **Backfill / migration** — `BackfillMarketingBudgetsCommand` calls `recomputeAccrued()` (and `recomputeSpent()`) on every budget.
   - File: `app/Console/Commands/BackfillMarketingBudgetsCommand.php:43`.

### 6. The fund spend is also DERIVED

```
spent_amount = SUM(marketing_spends.amount)   -- non-trashed only (relation excludes soft-deleted)
balance      = round(accrued_amount − spent_amount, 2)
```

- `MarketingBudget::recomputeSpent()` → `update(['spent_amount' => $this->spends()->sum('amount')])` — `app/Models/MarketingBudget.php:64-67`.
- `MarketingBudget::balance()` → `round((float) accrued_amount − (float) spent_amount, 2)` — `app/Models/MarketingBudget.php:58-61`. **Always computed, never stored.**
- **Trigger:** `MarketingSpend::booted()` re-syncs the parent on `saved`, `deleted`, **and `restored`** — `app/Models/MarketingSpend.php:55-63`. (The `restored` hook matters because spends soft-delete; restoring one must add it back.)

### 7. `forPeriod()` — the soft-delete-aware single entry point

`MarketingBudget::forPeriod(int $assetId, int $year)` is the **only** way accrual and spend obtain a budget row, guaranteeing exactly one row per (asset, year):

```php
$budget = static::withTrashed()->firstOrCreate(['asset_id' => $assetId, 'period_year' => $year]);
if ($budget->trashed()) { $budget->restore(); }
return $budget;
```

- File: `app/Models/MarketingBudget.php:93-108`.
- **Why `withTrashed()`:** the `(asset_id, period_year)` **unique key** (`marketing_budget_asset_year_unique`, migration line 27) is enforced **including** soft-deleted rows. A plain `firstOrCreate` would not see a trashed row, attempt an insert, and hit a duplicate-key error — which previously crashed the billing pipeline. `forPeriod` finds the trashed row and **restores** it instead. (Regression fix `a51952a` — "marketing-budget soft-delete crash".)

---

## Worked examples (real numbers)

### Example A — full month, default 5% rate

- Lease base rent: **EGP 10,000.00 / month**. Levy rate: **5.0%**.
- `amountFor()` → `round(10000 × 5/100, 2)` = **EGP 500.00**.
- Marketing charge created: amount 500.00, VAT-exempt, monthly.
- Monthly bill (full month, no proration) produces an `invoice_items` row: `type='marketing'`, `amount=500.00`, `vat_rate=0`, `vat_amount=0.00`, `total=500.00`. The **tenant's invoice total includes this 500.00.**
- Item `saved` hook → `forPeriod(asset, 2026)->recomputeAccrued()` → `accrued_amount` becomes the SUM of all 2026 marketing lines for that property. If this is the only one, **accrued = 500.00**.

### Example B — mid-month commencement, prorated

- Lease commences **2026-03-16**; March has **31 days**. Base rent **EGP 31,000.00 / month**. Rate **5%** → marketing charge amount **EGP 1,550.00**.
- Prorate factor: `daysBilled = inclusive days 16→31 = 16`; `factor = 16/31 ≈ 0.516129…` (full precision).
- Rent line: `round(31000 × 16/31, 2)` = **EGP 16,000.00**.
- **Marketing line (same factor):** `round(1550 × 16/31, 2)` = **EGP 800.00**.
- Accrual derived from billed marketing lines → contributes **800.00** (i.e. 5% of the **prorated** rent 16,000, not 5% of 31,000). The label reads `"Marketing Levy - March 2026 (52% pro-rated)"` (label percent is `round(factor × 100)` — `app/Services/MonthlyBillingService.php:227`).

### Example C — fund with spend and overspend

Property AW, year 2026, two tenants both billed full-month:

- Tenant 1 levy line 500.00, Tenant 2 levy line 750.00 → `recomputeAccrued()` derives **accrued = 1,250.00**.
- Record a spend: category `event`, amount **1,000.00** → `recomputeSpent()` derives **spent = 1,000.00**; `balance() = 250.00`.
- Record another spend: `printed_work`, amount **400.00** → **spent = 1,400.00**; `balance() = −150.00`. **Overspend is allowed**; the relation manager fires a non-blocking warning (see [Edge cases](#overspend)).

### Example D — invoice cancellation auto-reverses the accrual

Continuing Example C (accrued 1,250.00): cancel Tenant 2's invoice.

- `Invoice` `updated` hook detects `status` → `'cancelled'`, the invoice has a marketing item → calls `forPeriod(AW, 2026)->recomputeAccrued()`.
- The derive query now excludes Tenant 2's cancelled invoice → **accrued = 500.00** (Tenant 1 only). No manual reversal entry; the figure self-corrects. (If Tenant 2's invoice had also consumed an applied credit, cancellation additionally returns that credit via `CreditNoteService::reverseAppliedCredit` — `app/Models/Invoice.php:235-238` — but that is the AR path, not the levy.)

### Example E — rate change does not rewrite history

- 2026 marketing charge captured at 5% → 500.00; January–June billed at 500.00 each; accrued reflects 6 × 500 = 3,000.00.
- Operator raises `levy_rate_percent` to 8%. **Existing charge stays 500.00**; already-billed lines stay 500.00; accrued stays 3,000.00.
- Only after `createLevyCharge()` runs again (rent change / renewal / backfill) does the charge become `round(10000 × 8/100, 2)` = 800.00, and only **future** bills carry 800.00.

---

## Every edge case + how the system handles it

<a id="proration"></a>
- **Mid-month commencement (proration):** the levy line is multiplied by the **same** day-granular factor as rent, so the levy equals the configured rate of the **prorated** rent. The factor is full-precision; only the per-line amount is rounded to 2dp, so a clean fraction bills exactly. `app/Services/MonthlyBillingService.php:206-223`.
- **Invoice cancelled:** excluded from `recomputeAccrued` (`status != 'cancelled'`); the `Invoice` `updated` hook re-derives so the fund **drops** that levy automatically. `app/Models/MarketingBudget.php:80` + `app/Models/Invoice.php:223-248`.
- **Invoice un-cancelled (status back to issued/etc.):** same `Invoice` hook re-derives because the **old** status was `cancelled`; the levy is **added back**. `app/Models/Invoice.php:240-247`.
- **Marketing line item edited or deleted:** the `saved`/`deleted` item hook re-derives the whole year for that property — figures never drift. `app/Models/InvoiceItem.php:42-54`.
<a id="overspend"></a>
- **Overspend (spend > available):** **allowed by design.** `balance()` goes negative; the spends relation manager shows a non-blocking warning (`warnIfOverBudget()`), there is no `maxValue` guard. `app/Models/MarketingBudget.php:58-61` (balance can be negative).
- **Negative / zero spend:** rejected at the **form** layer (`minValue(0)` on the spend amount). There is no model-level guard, so a programmatic negative would not be blocked by the model — enforce at the form/service boundary.
- **Soft-deleted spend:** excluded from `spent_amount` because `spends()->sum()` runs through the relation, which omits trashed rows. Restoring a spend re-includes it (the `restored` hook re-syncs). `app/Models/MarketingSpend.php:55-63`.
- **Trashed budget row for the same (asset, year):** `forPeriod` restores it rather than colliding on the unique key — prevents the prior billing-pipeline crash. `app/Models/MarketingBudget.php:93-108`.
- **Missing asset or year at item-save time:** the item hook only re-derives when **both** `assetId` and `year` resolve (`if ($assetId && $year)`); otherwise it no-ops silently — the invoice still saves, but that line will not feed a fund. Ensure every lease's unit has an asset. `app/Models/InvoiceItem.php:46-51`.
- **Settings row missing:** `ratePercent()` falls back to **5.0** so charge creation never throws in minimal environments. `app/Services/MarketingLevyService.php:25-28`.
- **Rate changed after charges exist:** history is preserved (per-charge capture); only `createLevyCharge` re-sync or new bills pick up the new rate. See [Example E](#worked-examples-real-numbers).
- **Historical periods before the levy line existed:** **not** retro-charged. Accrued reflects only marketing lines **actually billed**, so prior-year/legacy budgets settle at what was really billed (often 0). `app/Console/Commands/BackfillMarketingBudgetsCommand.php:14-19`.
- **Lease with base rent 0:** no marketing charge is created (`createLevyCharge` is guarded behind `rent > 0` at every caller, e.g. `app/Services/LeaseCreationService.php:126`), so nothing accrues.
- **New property / new year:** a budget appears automatically — `marketing:ensure-budgets` runs **daily at 01:30** (idempotent `firstOrCreate` per property via `forPeriod`), and `forPeriod` also lazily creates one on the first billed levy. `app/Console/Commands/EnsureMarketingBudgetsCommand.php` + `routes/console.php:90-93`. Users never hand-create or delete budgets.

---

## Invariants + gotchas

1. **Accrual is derived, never incremented.** Always change the fund's income by changing the underlying marketing **invoice lines** (bill them, edit them, cancel/un-cancel the invoice) and let `recomputeAccrued()` re-derive. Never write `accrued_amount` directly, and never re-introduce an `increment()`-style accrue. (A previous `MarketingLevyService::accrue()` increment method has been **removed**; do not resurrect it.)
2. **Spend is derived too.** Never write `spent_amount` directly; change spends and let `recomputeSpent()` re-derive. `balance()` is computed on read and never stored.
3. **The levy is VAT-exempt**, mirroring base rent. The marketing charge sets `vat_applicable=false, vat_rate=0`; the levy line therefore has `vat_amount=0, total=amount`. (Do not confuse with the 14% VAT on service charges.)
4. **One marketing charge per lease.** Enforced by `updateOrCreate` on `(lease_id, type='marketing')`. Re-running re-syncs, never duplicates.
5. **One budget per (asset, year).** Enforced by the DB unique key **and** by always going through `forPeriod`. Going around `forPeriod` (e.g. `firstOrCreate` without `withTrashed`) risks the soft-delete unique-key crash.
6. **Rate is captured per-charge, not effective-dated.** Changing `levy_rate_percent` is forward-only; it never rewrites existing charges or billed lines. The "versioned" requirement is satisfied by per-charge capture.
7. **`recomputeAccrued` sums `amount` (ex-VAT base), not `total`.** Equal for marketing (VAT-exempt) but be deliberate if VAT-on-levy is ever introduced.
8. **The item hook does not fire on an invoice status-only change** — that's why `Invoice::booted()` carries its own re-derive for cancel/un-cancel. If you add another status that should exclude levies, update **both** the `recomputeAccrued` query filter and the `Invoice` hook's cancelled-edge guard.
9. **Asset/year must resolve** for the item hook to re-derive; a lease/unit with no asset silently skips funding. This is a data-integrity prerequisite, not a thrown error.
10. **The reconcile harness (`billing:reconcile` / `ReconcileBooksCommand`) does NOT currently assert marketing accrual.** As of this writing there is **no** marketing/accrued assertion in `app/Console/Commands/ReconcileBooksCommand.php`. Marketing self-reconciles through the derive triggers; if you want a hard guard, add an assertion there (and update this note).
11. **Idempotency / locking is at the billing layer, not the levy.** The monthly run is serialised per period by `Cache::lock('billing:run:Y-m', 900)` and the per-lease period-overlap skip guard (`app/Services/MonthlyBillingService.php:34-44`, `:75-83`), and the queued job carries `WithoutOverlapping`. So each lease+period yields at most one marketing line, hence the fund is funded exactly once per lease per month. `recomputeAccrued`/`recomputeSpent` are inherently safe to run repeatedly because they re-derive (no double counting).

---

## Where it lives in the code (file:line index)

| Concern | Location |
|---|---|
| Levy rate setting + default + group | `app/Settings/MarketingSettings.php:17`, `:21` |
| Read rate (with 5.0 fallback) | `app/Services/MarketingLevyService.php:21-29` |
| Per-lease levy amount = rate × base rent, 2dp | `app/Services/MarketingLevyService.php:31-34` |
| Create/sync the marketing charge (idempotent, VAT-exempt) | `app/Services/MarketingLevyService.php:41-56` |
| Levy charge seeded on lease create / rent change / renewal / backfill | `app/Services/LeaseCreationService.php:124-130`; `app/Services/LeaseRentChangeService.php:87-91`; `app/Services/LeaseRenewalService.php:99`; `app/Console/Commands/BackfillMarketingBudgetsCommand.php:35` |
| Charge → invoice item (type passthrough) + proration factor | `app/Services/MonthlyBillingService.php:189-273` (map `:221-239`; factor `:206-219`) |
| Invoice item VAT/total recompute on save | `app/Models/InvoiceItem.php:33-38` |
| Marketing item `saved`/`deleted` → re-derive fund | `app/Models/InvoiceItem.php:42-54` |
| `recomputeAccrued()` (derive accrued from billed lines, cancelled excluded) | `app/Models/MarketingBudget.php:75-87` |
| `recomputeSpent()` (derive spent from spends) | `app/Models/MarketingBudget.php:64-67` |
| `balance()` (accrued − spent, computed) | `app/Models/MarketingBudget.php:58-61` |
| `forPeriod()` (soft-delete-aware get-or-create/restore) | `app/Models/MarketingBudget.php:93-108` |
| Budget table schema (decimals 14,2; unique key; soft-deletes) | migration `…create_marketing_budgets_table` lines 19-28 |
| Invoice cancel/un-cancel → re-derive accrual + return credit | `app/Models/Invoice.php:223-248` |
| Spend model + `saved`/`deleted`/`restored` re-sync; categories | `app/Models/MarketingSpend.php:19`, `:55-63` |
| Daily auto-provision of budgets | `app/Console/Commands/EnsureMarketingBudgetsCommand.php`; schedule `routes/console.php:90-93` |
| Backfill: seed charges + re-derive all budgets | `app/Console/Commands/BackfillMarketingBudgetsCommand.php` |
| `invoice_items.type` allows `'marketing'` (string column) | migration `2026_06_28_000002_add_marketing_to_invoice_items_type.php` |

**Tests (regression / behaviour guards):** `tests/Feature/MarketingLevyTest.php`, `tests/Feature/MarketingBudgetTest.php`, `tests/Feature/MarketingSpendTest.php`, `tests/Feature/Scenarios/MarketingScenarioTest.php`, `tests/Feature/MarketingBudgetResourceTest.php`.

---

## Related

- [`docs/money/01-*`](.) and the other `docs/money/*.md` siblings — the AR / billing money references.
- The marketing-levy flow is the **income-side** analogue of CAM: see the CAM money doc and `docs/modules/08-cam.md`. CAM positive/negative true-ups, credit-note locking/reversal/auto-apply, and the billing run-lock are documented there and in `docs/modules/07-credit-notes.md` and `docs/modules/05-billing-invoices.md`.
- Module-level overview: [`docs/modules/13-marketing.md`](../modules/13-marketing.md). **Note:** that module doc still describes a now-removed `accrue()` increment method and a reconcile assertion that does not exist; **this file reflects the current derived behaviour** and supersedes those specific claims.
- Cross-cutting money invariants: [`docs/BUSINESS-RULES.md`](../BUSINESS-RULES.md), [`docs/OVERVIEW.md`](../OVERVIEW.md).
