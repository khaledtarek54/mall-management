# 10 — Utility Meters & Utility Billing

> **Audience:** business/finance team and engineers new to Atriom.
> **Scope:** how Atriom tracks **utility meters** (electric / water / gas), how it records **meter readings** and derives **consumption** and per-reading **cost**, and — separately — how a tenant is actually **billed for utilities** on an invoice. Every column, every formula, every rounding rule, every edge case.
> **The one thing to get right first:** a **meter reading is operational/tracking data, NOT a billing event.** Recording a reading (with consumption and even a cost) does **not** create an invoice, an invoice line, or a charge. Tenants are billed for utilities only through a `Charge` of `type = utility` on their lease, which flows through the normal monthly-billing engine. The two halves of this doc explain each side and where they meet (or, more precisely, where they *don't*).

---

## Plain-language summary (what + why, for a non-engineer)

Atriom does two related-but-separate things with utilities:

1. **Metering (operations).** Each property (asset), and optionally each shop (unit), can have **utility meters** — one per service: electricity, water, or gas. Every month an operator reads the physical meter and logs the **odometer value** (`reading_value`) along with the date. The system computes **consumption** for that period as *this reading minus the previous reading* (e.g. the meter went from 12,000 to 12,450 kWh → 450 kWh used). The operator can also record an optional **cost** in EGP for that period's consumption. All of this feeds a 12-month **Energy Consumption Trend** dashboard chart and gives operators a record of usage. This is purely tracking — nobody is billed by logging a reading.

2. **Billing the tenant (money).** Whatever the tenant pays for utilities is set up as a recurring **charge** on their **lease** — a line of `type = utility` with an amount, a frequency (usually monthly), and a VAT flag. When the monthly billing run executes, that charge becomes a `utility` **line item** on the tenant's **invoice**, exactly like rent or a service charge. The amount on that charge is entered/maintained by leasing/accounting — Atriom does **not** automatically multiply consumption by a tariff and push it onto the invoice. If utilities are sub-metered and re-billed at cost, the operator reads the cost off the meter readings and updates the lease's utility charge (or adds a one-off line) accordingly.

So: **the meter side measures usage; the billing side charges money; the bridge between them is a human** (the operator/accountant who decides what the utility charge amount should be). This is deliberate — Egyptian mall utility re-billing varies per lease (fixed allowance, flat fee, at-cost pass-through, included-in-service-charge), so the system keeps the measured number and the billed number independent.

The **currency is Egyptian Pounds (EGP)** throughout, and **all money and consumption values are stored to 2 decimal places**.

---

## The exact rule / formula

### A. Consumption (the metering side)

**Definition:** `consumption = current_reading_value − previous_reading_value`, where "previous" is the meter's most recent reading with a *strictly earlier* `reading_date`.

This is **auto-filled in the operator's form**, not computed by a model hook or service. It lives in `ReadingsRelationManager::form()` on the `reading_value` field's `afterStateUpdated` callback (`app/Filament/Admin/Resources/UtilityMeters/RelationManagers/ReadingsRelationManager.php:52-75`). The exact logic, fired **live, on blur**, when the operator types/leaves the `reading_value` field:

```
if (consumption field already has a value)            → do nothing   (never clobber a manual entry)
if (no meter id / no reading_date / value not numeric)→ do nothing
prior = the latest MeterReading for this meter with reading_date < the new date
if (no prior reading)                                 → do nothing   (first-ever reading: operator keys it)
delta = (float) reading_value − (float) prior.reading_value
if (delta < 0)                                        → do nothing   (meter rolled/reset: operator keys it)
otherwise consumption = round(delta, 2)
```

| Input | Source |
|---|---|
| `reading_value` (current) | the number the operator just typed (decimal, `min 0`, `step 0.01`). |
| `prior.reading_value` | the meter's most recent earlier reading, found by `MeterReading::where('utility_meter_id', …)->where('reading_date','<',$date)->orderByDesc('reading_date')->first()`. |
| guard "consumption already set" | `$get('consumption')` — auto-fill **never overwrites** an operator-entered value. |

**Output:** the form's `consumption` field, `round(delta, 2)`. It is then **persisted as a stored column** `meter_readings.consumption` (`DECIMAL(14,2)`, default `0`). It is **not** recomputed on read — once saved it is frozen (see *Immutability* below). `consumption` is a **required** form field (`->required()`, `ReadingsRelationManager.php:81`), so even when auto-fill declines (first reading or a reset), the operator must key a figure to save.

> **Important nuance:** this delta math runs **only through the live Filament form**. There is no `MeterReading::booted()` consumption hook, no service, no command. If a reading is created programmatically (a seeder, a test via `MeterReading::create([...])`, a future import job), **consumption is whatever you pass in** — nothing derives it for you. This is by design but is the most common surprise.

### B. Cost (the metering side) — and the NOT-NULL coercion gotcha

`meter_readings.cost` (`DECIMAL(14,2)`, **NOT NULL**, DDL `default 0`) is an **optional, operator-entered** EGP figure: "what this period's consumption cost." It is **purely informational** — nothing computes it (no tariff × consumption), and **nothing in the billing engine reads it.** It exists so operators can record the utility provider's bill against the reading.

**The gotcha (a NOT-NULL invariant bug this codebase explicitly guards):**
The `cost` field in the form is **not** `->required()` (`ReadingsRelationManager.php:83-88`). When an operator leaves it blank, **Filament dehydrates the field to `null`** and sends `null` to the database. A SQL column's `DEFAULT 0` is **only** applied when the column is *omitted* from the INSERT — an explicit `NULL` **bypasses the default** and hits the NOT-NULL constraint → "NOT NULL constraint failed: meter_readings.cost" (or MySQL equivalent), a 500 on save.

**The fix (current behaviour):** a model `saving` hook coerces `null → 0` before every write (`app/Models/MeterReading.php:34-43`):

```php
protected static function booted(): void
{
    // cost is NOT NULL (DDL default 0); a blank/optional form field
    // dehydrates null, which bypasses the default — coerce it to 0.
    static::saving(function (self $reading) {
        if ($reading->cost === null) {
            $reading->cost = 0;
        }
    });
}
```

So a reading saved with the cost box left blank stores `cost = 0.00`, never `null`, and never errors. This is the canonical example of the project-wide invariant *"never let an optional/blank form field send `null` into a NOT-NULL column — coerce in the model"* (see `CLAUDE.md` → Invariants, and `docs/money/00-money-model.md` → *Edge cases → NOT-NULL money columns*). The same class of bug previously bit `leases.has_percentage_rent`.

### C. Utility billing (the money side)

A tenant is billed for utilities through the **normal invoice pipeline** — there is no utility-specific billing code. The chain:

```
Lease ──< Charge (type = 'utility') ──[monthly billing run]──> InvoiceItem (type = 'utility') ──> Invoice.total
```

**Step 1 — the charge.** On the lease, leasing/accounting create a `Charge` row (`app/Models/Charge.php`, table `charges`):

| Field | Meaning / default |
|---|---|
| `type` | `'utility'` — one of the `charges.type` enum values (`base_rent`, `service_charge`, **`utility`**, `parking`, `percentage_rent`, `marketing`, `other`). |
| `amount` | the EGP amount to bill **per period**, ex-VAT (`DECIMAL(12,2)`). Set by a human. |
| `frequency` | `monthly` / `quarterly` / `annually` / `one_time` (DB default `monthly`). |
| `vat_applicable` | boolean, **DB default `true`** (`charges` migration `:27`). |
| `vat_rate` | `DECIMAL(5,2)`, **DB default `14.00`** (`:28`). |
| `start_date`, `end_date` | optional window the charge is active for. |
| `is_active` | boolean, default `true`. Only active charges are billed. |

> **VAT note for utilities:** unlike base rent (which is VAT-exempt by rule), a `utility` charge inherits the generic charge defaults — `vat_applicable = true`, `vat_rate = 14.00` — **unless the operator overrides them on the charge**. There is no automatic VAT-exemption for utilities. Whether utility pass-throughs carry 14% VAT is a per-lease data decision, captured on the `Charge` row at creation. See `docs/money/02-vat-and-tax.md`.

**Step 2 — the monthly run** (`app/Services/MonthlyBillingService.php`). For each active lease, `generateInvoiceForLease()` (`:189-273`) filters the lease's active charges to those applicable to the period (`chargeAppliesToPeriod`, `:275-299`), then maps each to an invoice line (`:221-239`):

```
amount      = round((float) charge.amount × factor, 2)      // factor = 1.0 unless prorated first month
vatRate     = charge.vat_applicable ? (float) charge.vat_rate : 0.0
vatAmount   = round(amount × vatRate / 100, 2)
description = charge.name . ' - ' . periodStart('F Y')      // e.g. "Electricity - March 2026"
total       = round(amount + vatAmount, 2)
type        = charge.type                                   // 'utility' carries straight through
```

**Step 3 — the line item.** Each row becomes an `InvoiceItem` of `type = 'utility'`. The item **re-derives** its own VAT and total on save (`app/Models/InvoiceItem.php:33-38`), the single source of truth for line maths:

```
vat_amount = round(amount × vat_rate / 100, 2)
total      = round(amount + vat_amount, 2)
```

The invoice header is then `subtotal = Σ item.amount`, `vat_amount = Σ item.vat_amount`, `total = subtotal + vat_amount` (`MonthlyBillingService.php:241-262`), and from there everything obeys the money model in `docs/money/00-money-model.md` (`recomputeTotals()` owns `paid_amount`/`balance`).

**`utility` as a manual line type.** `utility` is also a member of `App\Enums\InvoiceItemType` (`app/Enums/InvoiceItemType.php:16`), so an admin can add a one-off `utility` line directly on an invoice via the Invoice form (`InvoiceForm.php:157`, options from `InvoiceItemType::options()`) — e.g. a one-time at-cost electricity recovery — without a recurring charge. That manual line obeys the same `InvoiceItem` VAT/total derivation.

**There is no automatic consumption→charge bridge.** A `grep` across `app/Services` and the money models for `MeterReading` / `consumption` / a reading-driven charge returns nothing relevant: no service, job, command, or model hook turns a reading into a charge or invoice line. The `cost` and `consumption` on a reading never reach billing automatically. (Verified: the only references to `MeterReading` outside its own model/resource are the `EnergyConsumptionTrend` widget and `ModulesSettings`.)

### Config / settings sources (named)

| Knob | Where | Default | Affects |
|---|---|---|---|
| Allowed meter **types** | `UtilityMeter::TYPES` const (`app/Models/UtilityMeter.php:15`) **and** the `type` enum in the meters migration (`:16`) | `['electric','water','gas']` | which utilities can be metered |
| Allowed meter **statuses** | `UtilityMeter::STATUSES` const (`:17`) **and** the `status` enum (`:18`) | `['active','inactive','faulty']`, default `active` | meter lifecycle label |
| Charge **VAT applicable** default | `charges` migration `:27` | `true` | whether a new utility charge carries VAT |
| Charge **VAT rate** default | `charges` migration `:28` | `14.00` | the % on a VAT-applicable utility charge |
| Utility module **on/off** | `App\Settings\ModulesSettings::$utility_meters` (`:29`) | `true` | shows/hides the Energy & Utilities resource + widget |
| Energy widget **window** | `EnergyConsumptionTrend::getData()` (`:43-44`) | rolling **12 months** (`now → now − 11 months`) | the chart's x-axis |

There is **no** tariff/rate-per-unit setting anywhere — Atriom does not price consumption.

### Currency & rounding (precise)

- **Currency:** EGP. The form prefixes the cost field with `EGP` (`ReadingsRelationManager.php:88`); the table renders cost with `->money('EGP')` (`:117`).
- **Storage precision:** `reading_value`, `consumption`, `cost` are all `DECIMAL(14,2)` (meter readings migration `:15-17`), cast `decimal:2` in `MeterReading` (`:22-27`) — reads return 2-dp strings. Note the readings table uses **`DECIMAL(14,2)`**, wider than the `DECIMAL(12,2)` used by invoice money columns.
- **Rounding:** consumption auto-fill uses PHP `round($delta, 2)` (half-away-from-zero). Billing amounts use `round($x, 2)` at every step (per-line amount, VAT, totals).

---

## Worked examples (real numbers)

### Example A — a normal monthly electricity reading (consumption + cost)

Meter `HW-E-001` (electric, unit kWh) on Atriom Walk. History:

| Date | reading_value | consumption | cost (EGP) |
|---|---:|---:|---:|
| 2026-02-01 | 12,000.00 | 0.00 (first reading) | 9,600.00 |
| 2026-03-01 | 12,450.00 | **450.00** | 8,500.00 |

For the **March** reading the operator types `reading_value = 12450`. On blur the form finds the prior reading (2026-02-01, value 12,000.00), computes `delta = 12,450 − 12,000 = 450`, `delta ≥ 0`, so it sets `consumption = round(450, 2) = 450.00`. The operator types the provider's bill, `cost = 8500`. Saved row: `consumption = 450.00`, `cost = 8,500.00`. **No invoice is created. No tenant is billed.** The 450 kWh rolls into the Energy Consumption Trend chart for March; the 8,500 sits on the reading as a record.

### Example B — cost left blank (the NOT-NULL coercion)

Same meter, **2026-04-01**, `reading_value = 12,900`, auto-fill sets `consumption = 450.00`. The operator does **not** know the provider's bill yet and leaves **cost blank**. Filament dehydrates `cost → null`. The `MeterReading::saving` hook (`:38-42`) sees `cost === null` and sets `cost = 0`. Saved row: `consumption = 450.00`, **`cost = 0.00`** (not null, no SQL error). Later the operator can edit the reading to enter the real cost.

### Example C — meter reset / rollback (auto-fill declines)

The electric meter is replaced; the new unit starts at 0. Next reading `reading_value = 300`. Prior value was 12,900. `delta = 300 − 12,900 = −12,600`, which is `< 0`, so **auto-fill does nothing** — the `consumption` box stays empty. Because `consumption` is `->required()`, the operator **must** key the real figure manually (e.g. they know 300 kWh was used since the swap) and should note *"meter replaced"* in `notes`. Saved: `consumption = 300.00` (operator-entered), `cost` as keyed. This prevents a nonsensical negative consumption from a hardware swap.

### Example D — how the tenant actually pays for that electricity (the billing side)

The lease for that shop has a recurring charge: **name** "Electricity (at-cost)", **type** `utility`, **amount** `8,500.00`, **frequency** `monthly`, **vat_applicable** `true`, **vat_rate** `14.00` (the operator set the amount from last month's reading cost). The March billing run produces a `utility` line:

| Item | amount | vat_rate | vat_amount | total |
|---|---:|---:|---:|---:|
| Electricity (at-cost) - March 2026 | 8,500.00 | 14 | 1,190.00 | 9,690.00 |

That `9,690.00` joins the invoice's other lines (rent, service charge, etc.) in the header total, and `recomputeTotals()` takes over for settlement. Crucially, the `8,500.00` here is the **charge amount a human maintained**, not a number Atriom pulled from the meter reading — even though, in this at-cost setup, the operator *chose* it by reading the meter's recorded cost. Change the consumption pattern next month and the **operator** must update the charge; the system will not.

### Example E — VAT-exempt utility (operator override)

A lease bundles a **flat water allowance** the operator decides not to VAT. They set the `utility` charge with `vat_applicable = false`. The billing map sets `vatRate = 0.0`, so the line is `amount = 200.00`, `vat_amount = 0.00`, `total = 200.00`. There is no automatic exemption — it is a data choice on that charge.

---

## Every edge case + how the system handles it

- **First-ever reading (no prior).** Auto-fill finds no earlier reading and declines (`ReadingsRelationManager.php:67-69`); the operator keys `consumption` (often `0` or the meter's opening usage). Required-field validation forces a value.
- **Meter reset / rollback (current < prior).** `delta < 0` → auto-fill declines (`:71-73`); operator keys the real consumption and notes the reset. No negative consumption is silently stored from the form. (Programmatic creates can still store a negative — nothing guards that path.)
- **Operator override of a good delta.** If the operator has typed a corrected `consumption` first, the `$get('consumption')` guard (`:54-56`) means a later `reading_value` blur will **not** overwrite it. Manual always wins.
- **Cost left blank → NOT-NULL violation.** Coerced to `0` by `MeterReading::saving` (`:38-42`). See Example B.
- **Two readings on the same date.** Blocked twice: a form-level `->unique(...)` rule scoped to this meter (`:37-40`) and a DB `UNIQUE (utility_meter_id, reading_date)` constraint (`meter_reading_period_unique`, readings migration `:21`). A programmatic duplicate throws a `QueryException`.
- **Same date on a *different* meter.** Allowed — uniqueness is per `(meter, date)`, not per date.
- **Backdated readings.** Permitted: the prior-reading lookup is `reading_date < candidate` and orders by date desc, so inserting an out-of-order historical reading still computes its delta against the correct earlier one. (There is no "future date" guard — the doc-recommended `->maxDate(today())` is not currently applied.)
- **Common-area meter (`unit_id = null`).** Valid; `UtilityMeter::isCommonArea()` returns true (`:49-52`). Its readings still belong to the asset and appear in the asset-level energy chart, but are **not** attributable to any tenant/unit. They are not billed to anyone automatically.
- **Deleting a meter.** `meter_readings.utility_meter_id` is `cascadeOnDelete` (readings migration `:13`), so deleting a meter deletes its readings. The meter itself is **soft-deleted** (`UtilityMeter use SoftDeletes`), so a normal delete is recoverable; a force-delete cascades the readings away. To retire a meter without losing history, set `status = 'inactive'`/`'faulty'` instead of deleting.
- **Faulty/inactive meter still logged.** Status is a free label — no DB rule stops readings on a `faulty` meter, and the Energy widget does **not** filter by status (`EnergyConsumptionTrend::getData` joins and sums all readings, `:53-64`). Faulty-meter readings will pollute the trend until the meter is removed. Operators should not log against faulty meters.
- **Reading has cost but no utility charge on the lease.** Common and fine — the cost is a record only; nothing bills it. To re-bill, a human adds/updates a `utility` charge.
- **Utility charge but no meter.** Equally fine — a flat utility fee needs no meter at all. The billing side is fully independent of the metering side.
- **Charge active window vs period.** `chargeAppliesToPeriod` (`MonthlyBillingService.php:275-299`) skips a utility charge whose `start_date` is after the period or `end_date` before it, and applies frequency logic (monthly/quarterly/annually/one_time). A `one_time` utility charge bills only in the period containing its `start_date`.
- **Prorated first month.** If the lease commences mid-month and the run is asked to prorate, the utility line amount is `round(charge.amount × factor, 2)` and the label gets a "(N% pro-rated)" suffix (`:221-228`) — identical to rent proration. See `docs/money/01-billing-monthly.md`.
- **Double-billing a period.** Prevented by the billing run-lock and the per-lease period-overlap guard in `MonthlyBillingService` — not utility-specific. See `docs/money/00-money-model.md` and `01-billing-monthly.md`.
- **Module switched off.** With `ModulesSettings::$utility_meters = false`, the Energy & Utilities resource and the trend widget disappear, but any existing `utility` **charges/invoice lines are unaffected** — billing does not depend on the metering module being enabled.

---

## Invariants + gotchas

1. **A meter reading is not a billing event.** Logging consumption or a cost bills no one. Tenant utility charges live on the **lease** as a `Charge` of `type = utility` and are billed by `MonthlyBillingService`. Keep these two mental models separate.
2. **`meter_readings.cost` is tracking-only.** Nothing in the money layer reads it. Do not assume changing a reading's cost changes any invoice.
3. **NOT-NULL coercion is load-bearing.** `MeterReading::saving` coerces `cost null → 0` (`:38-42`). If you make `cost` required in the form, you may remove the hook — but until then, never remove it, or blank-cost saves 500. This is the project's canonical NOT-NULL-from-blank-form-field guard.
4. **Consumption is derived in the FORM, not the model.** The delta math is a Filament `afterStateUpdated` callback (`ReadingsRelationManager.php:52-75`). Programmatic creates (seeders/tests/imports) must compute and pass `consumption` themselves — there is no model hook or service to fall back on.
5. **Auto-fill never overwrites a manual `consumption`,** and **never** fills on first-ever reading or on a negative delta (meter reset). Those paths require operator entry; `consumption` is a required field.
6. **`consumption` is immutable in spirit.** It is not recomputed on read and the relation manager's live-delta only fires on form interaction. To correct a reading, edit or delete-and-re-enter; downstream values (the chart) reflect the stored figure.
7. **Per-(meter, date) uniqueness is enforced at two layers** — form rule + DB unique index. Honour it if you bulk-import.
8. **No automatic VAT exemption for utilities.** A utility charge defaults to `vat_applicable = true`, `vat_rate = 14.00` (charge DB defaults). VAT treatment is a per-charge data decision; only **base rent** is exempt by rule. See `docs/money/02-vat-and-tax.md`.
9. **Readings table uses `DECIMAL(14,2)`** (wider than invoice money's `DECIMAL(12,2)`). Fine for large odometer values; just be aware when comparing schemas.
10. **The Energy widget ignores meter status and sums all readings** for the asset over a rolling 12 months (`EnergyConsumptionTrend::getData`, `:41-98`), grouped by `(month, type)`. Faulty-meter readings count unless removed.

---

## Where it lives in the code (file:line index)

| Concern | Location |
|---|---|
| **Meter model** (types, statuses, relations, `isCommonArea`, `latestReading`) | `app/Models/UtilityMeter.php` (TYPES `:15`, STATUSES `:17`, readings `:39-42`, latest `:44-47`, common-area `:49-52`) |
| **Reading model** + casts + **NOT-NULL cost coercion** | `app/Models/MeterReading.php` (fillable `:13-20`, casts `:22-27`, **`saving` cost→0 hook `:34-43`**) |
| **Consumption delta auto-fill** (the formula, live/onBlur) | `app/Filament/Admin/Resources/UtilityMeters/RelationManagers/ReadingsRelationManager.php:52-75` |
| Reading form fields (cost optional, consumption required, unique date) | `ReadingsRelationManager.php` (date+unique `:32-41`, value `:42-75`, consumption required `:76-82`, cost `:83-88`) |
| Reading table (cost as `money('EGP')`, consumption bold) | `ReadingsRelationManager.php:96-147` |
| **Meters schema** (decimals, NOT-NULL cost default 0, unique `(meter,date)`) | `database/migrations/2026_05_23_174734_create_meter_readings_table.php` · `..._174733_create_utility_meters_table.php` |
| **Utility charge** model (amount, VAT, frequency, active) | `app/Models/Charge.php` · `database/migrations/2024_01_01_000005_create_charges_table.php` (type enum `:15-22`, VAT defaults `:27-28`) |
| **Charge → invoice line** mapping (utility billed here) | `app/Services/MonthlyBillingService.php` (charge map `:221-239`, applies-to-period `:275-299`, header `:241-262`) |
| Invoice line VAT/total derivation | `app/Models/InvoiceItem.php:33-38` |
| `utility` as an allowed line-item type | `app/Enums/InvoiceItemType.php:16` |
| Manual `utility` line on the invoice form | `app/Filament/Admin/Resources/Invoices/Schemas/InvoiceForm.php:157` |
| Energy Consumption Trend chart (12-month, by type, sum consumption) | `app/Filament/Admin/Widgets/EnergyConsumptionTrend.php:41-98` |
| Module toggle | `app/Settings/ModulesSettings.php:29` |
| Meter resource scoping (per-property, All-Properties bypass) | `app/Filament/Admin/Resources/UtilityMeters/UtilityMeterResource.php` · `…/Concerns/BypassesScopingOnAll.php` |
| Tests | `tests/Feature/Scenarios/UtilityMeterScenarioTest.php` (consumption math, rollback, cost-blank coercion `:209+`, uniqueness, RBAC) · `tests/Feature/Resources/MeterReadingsRelationManagerTest.php` |

**Relevant git history:** `ca7f6c1` (Energy & Utilities stub — meters, readings, consumption chart) introduced the module; the broader money-path hardening that this doc situates utility billing within — `88816d4` (credited/proration), `bb1ceed` (net-AR, billing run-lock), `3057520` (prorated double-bill) — lives on the invoice/billing side, which utility charges share. No git changes were needed to the metering side beyond the original stub; the `cost` coercion has been present since the model was added.

---

## Related

- `docs/money/00-money-model.md` — the single source of truth for invoices, items, payments, credit notes (`recomputeTotals()`, NOT-NULL money columns, rounding). **Read this to understand what happens after a utility line lands on an invoice.**
- `docs/money/01-billing-monthly.md` — the monthly billing engine, charge→line generation, proration, idempotency/run-lock that carry utility charges onto invoices.
- `docs/money/02-vat-and-tax.md` — VAT 14% rules; why base rent is exempt and how a utility charge's VAT flag is decided.
- `docs/money/03-marketing-levy.md` — sibling "charge becomes a derived invoice line" pattern (marketing levy), for contrast.
- `docs/modules/10-utility-meters.md` — the operational module reference (Filament resource, RBAC matrix, scoping, energy widget, extension points).
- `docs/BUSINESS-RULES.md` — the operator-facing rule sign-off sheet.
