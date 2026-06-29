# Money · 02 — VAT & Tax (Egyptian VAT 14% + ETA e-invoicing)

> **Scope.** How Value-Added Tax (VAT) is computed, stored and rolled up across the
> Atriom billing stack, which charges are taxed vs. exempt, the exact arithmetic
> (per-line and per-invoice), and how the resulting tax breakdown feeds the
> Egyptian Tax Authority (ETA) e-invoice document. Every statement below is
> grounded in the current code — file paths and line numbers are cited so you can
> verify each rule against source.
>
> **Audience.** Finance/business team *and* engineers new to the codebase. The
> Plain-language summary is non-technical; everything after it is exact.

---

## Plain-language summary

In Egypt, **VAT is 14%**. Atriom does **not** apply that 14% to everything on an
invoice. The rule the business operates under is:

- **Service Charge → VAT 14%** (taxed).
- **Base Rent → VAT-exempt** (0%). Rent of real estate is outside the VAT base.
- **Marketing levy → VAT-exempt** (it mirrors base rent — it is a percentage *of*
  base rent).
- **Percentage rent, CAM reconciliation true-ups, utility/parking pass-throughs,
  late fees → VAT-exempt as configured** (each is created with VAT switched off in
  code; see the table below).

Every charge on a lease carries two settings that decide its tax: a yes/no flag
(**`vat_applicable`**) and a **`vat_rate`** (a percentage, normally `14.00`). When
the monthly billing engine turns charges into an invoice, it computes the VAT for
each line, sums the lines into the invoice's **subtotal** (pre-tax) and
**vat_amount** (tax), and the invoice **total** is `subtotal + vat_amount`. So an
invoice can mix taxed and exempt lines — e.g. base rent (no VAT) *plus* service
charge (with VAT) — and the totals add up correctly.

When the operator submits that invoice to the Egyptian Tax Authority (e-invoicing),
the same per-line tax breakdown is re-expressed in ETA's format: each taxed line
gets a tax block of type **T1 / sub-type V009** (ETA's code for VAT) at its rate,
exempt lines carry no tax block, and the invoice's total VAT becomes the
document-level **taxTotals**. Each line also carries an **EGS item code** (a
registered goods/services code) keyed off the charge type.

**Why it matters:** getting "rent is exempt, service charge is taxed" wrong would
either over-charge tenants VAT they don't owe, or under-report VAT to ETA. The
split is enforced at charge-creation time and recomputed deterministically on every
save, so it cannot drift.

---

## The exact rule / formula

### Inputs (where the tax decision comes from)

Tax is driven **per charge**, then captured **per invoice line**. Two columns on
the `charges` table are the source of truth:

| Column | Type / default | Meaning |
|---|---|---|
| `charges.vat_applicable` | `boolean`, **default `true`** | Master on/off. If false, VAT is forced to 0 regardless of `vat_rate`. |
| `charges.vat_rate` | `decimal(5,2)`, **default `14.00`** | The VAT percentage applied when `vat_applicable` is true. |

Defaults from the migration: `database/migrations/2024_01_01_000005_create_charges_table.php:27-28`.

The model casts these as `boolean` / `decimal:2`: `app/Models/Charge.php:38-45`.

> **Important — the column DEFAULTS are not the business rule.** The DB default is
> `vat_applicable = true, vat_rate = 14` (so a hand-created charge is taxed unless
> told otherwise), but the *seeding code* overrides these per charge type. Base
> rent is explicitly created with `vat_applicable = false`. See the per-type table
> below.

### The per-charge VAT formula

`Charge::calculateVat()` — `app/Models/Charge.php:52-58`:

```php
public function calculateVat(): float
{
    if (! $this->vat_applicable) {
        return 0;
    }
    return round($this->amount * ($this->vat_rate / 100), 2);
}
```

- If `vat_applicable` is false → **VAT = 0** (short-circuit; `vat_rate` is ignored).
- Otherwise → **VAT = round(amount × vat_rate ÷ 100, 2)** — rounded to **2 decimal
  places**, half-up (PHP's default `round`).
- `Charge::totalWithVat()` = `amount + calculateVat()` (`app/Models/Charge.php:60-63`).

This `Charge` helper is the *reference* formula; in practice the VAT that lands on
an invoice is computed by the billing engine (next section) using the **same**
arithmetic but against the (possibly prorated) line amount.

### The per-line VAT formula (what actually persists on the invoice)

Each `invoice_items` row stores `amount`, `vat_rate`, `vat_amount`, `total`. The
VAT and line total are **derived on every save** by the model's `saving` hook —
`app/Models/InvoiceItem.php:33-38`:

```php
static::saving(function (self $item) {
    $amount = (float) ($item->amount ?? 0);
    $rate   = (float) ($item->vat_rate ?? 0);
    $item->vat_amount = round($amount * $rate / 100, 2);
    $item->total      = round($amount + (float) $item->vat_amount, 2);
});
```

> **`vat_amount` and `total` are never trusted from the caller** — even if a caller
> passes them in, this hook overwrites them from `amount × vat_rate`. So a line is
> always internally consistent. The exempt/taxed decision is encoded purely in the
> line's `vat_rate`: an exempt line is written with `vat_rate = 0`, which makes
> `vat_amount = 0` automatically.

Per-line formula, precisely:

```
line.vat_amount = round(line.amount * line.vat_rate / 100, 2)
line.total      = round(line.amount + line.vat_amount, 2)
```

Column types: `invoice_items.vat_rate decimal(5,2) default 14.00`,
`vat_amount decimal(12,2) default 0`, `amount/total decimal(12,2)` —
`database/migrations/2024_01_01_000006_create_invoices_table.php:63-66`.

### How the billing engine sets each line's `vat_rate` (the exempt/taxed gate)

`MonthlyBillingService::generateInvoiceForLease()` maps each applicable charge to a
line — `app/Services/MonthlyBillingService.php:221-239`:

```php
$amount    = round((float) $charge->amount * $factor, 2);   // $factor = proration
$vatRate   = $charge->vat_applicable ? (float) $charge->vat_rate : 0.0;
$vatAmount = round($amount * ($vatRate / 100), 2);
...
'amount'     => $amount,
'vat_rate'   => $vatRate,
'vat_amount' => $vatAmount,
'total'      => round($amount + $vatAmount, 2),
```

So **the billing engine collapses `vat_applicable=false` into `vat_rate = 0`** when
it writes the line. This is the single place the exempt rule is applied during
automated billing. (The `InvoiceItem::saving` hook then re-derives `vat_amount` from
that `vat_rate` — they agree by construction.)

**Proration interaction:** VAT is computed on the **prorated** `amount`
(`charge.amount × factor`, rounded to 2dp), *not* on the full charge. So a
half-month service charge is taxed on the half-month value. `$factor` is `1.0`
unless the lease commences mid-period **and** the caller passed `prorate = true`
(`app/Services/MonthlyBillingService.php:206-219`).

### How lines roll up to the invoice header

Still in `generateInvoiceForLease()` — `app/Services/MonthlyBillingService.php:241-243`:

```php
$subtotal  = round((float) $items->sum('amount'), 2);       // pre-tax
$vatAmount = round((float) $items->sum('vat_amount'), 2);    // tax
$total     = round($subtotal + $vatAmount, 2);
```

Invoice header columns (`database/migrations/2024_01_01_000006_create_invoices_table.php:30-34`):

```
invoices.subtotal   = Σ line.amount       (pre-tax total of ALL lines, taxed + exempt)
invoices.vat_amount = Σ line.vat_amount    (tax only — exempt lines contribute 0)
invoices.total      = subtotal + vat_amount
```

> **Sum-of-rounded, not round-of-sum.** Each line's `vat_amount` is rounded to 2dp
> *first*, then the rounded lines are summed and rounded again. This means
> `invoices.vat_amount` equals the sum of the per-line VATs the tenant sees — there
> is no separate "invoice-level VAT recompute" that could disagree with the lines.

**The manual-invoice path matches the engine exactly.** When an admin builds an
invoice by hand in Filament, `InvoiceForm` mirrors the same arithmetic in the live
form preview — `app/Filament/Admin/Resources/Invoices/Schemas/InvoiceForm.php`:
per-item `recomputeItem()` (lines 248-262) does `round($amount * $vatRate / 100, 2)`;
`sumItems()` (lines 348-363) sums rounded line VATs into the header. The manual VAT
field **defaults to `14`** (line 183) and is bounded `0–100` (lines 181-182). When
the lease is selected, `prefillItemsFromLease()` copies each charge's effective rate
(`vat_applicable ? vat_rate : 0`) into the line (lines 327-339), so picking a lease
reproduces its exempt/taxed split.

### Config / settings sources (named keys)

- **VAT rate is data, not config.** There is **no global VAT-rate config key**.
  The 14% lives in (a) the column default `vat_rate decimal(5,2) default 14.00`,
  (b) the seed defaults in `LeaseCreationService::seedStandardCharges()`, and
  (c) the Filament form default `->default(14)`. To change a tenant's rate you edit
  the charge, not a config file. `config/billing.php` carries late-fee and schedule
  keys only — **no VAT key**.
- **ETA tax codes** come from `config/eta.php`:
  - `eta.egs_codes.*` — per-charge-type EGS/GS1 item codes (`base_rent`,
    `service_charge`, `utility`, `parking`, `percentage_rent`, `default`),
    each overridable via env (`ETA_EGS_*`). `config/eta.php:55-63`.
  - `eta.issuer.*` — seller identity / TRN stamped on every document
    (`config/eta.php:33-49`).
  - The VAT line tax-type/sub-type (`T1` / `V009`) are **hard-coded** in
    `EtaJsonBuilder` (not config) — `app/Services/Eta/EtaJsonBuilder.php:96-101`.
- **ETA toggles** (whether to submit at all) live in `EtaSettings` (runtime,
  admin-editable) and `config/eta.php`: `eta_enabled`, `eta_mock`
  (`app/Settings/EtaSettings.php:15-16`, `config/eta.php:23-24`).

### Per-charge-type VAT defaults (the exempt/taxed map as created in code)

| Charge `type` | `vat_applicable` | `vat_rate` | Created by | Source |
|---|---|---|---|---|
| `base_rent` | **false (exempt)** | 0 | Lease creation seed | `app/Services/LeaseCreationService.php:94-107` |
| `service_charge` | **true (14%)** | 14.00 | Lease creation seed | `app/Services/LeaseCreationService.php:109-122` |
| `marketing` (levy) | **false (exempt)** | 0 | `MarketingLevyService::createLevyCharge()` | `app/Services/MarketingLevyService.php:41-56` |
| `percentage_rent` | **false (exempt)** | 0 | `PercentageRentCalculationService` | `app/Services/PercentageRentCalculationService.php:150-164` |
| CAM positive true-up (`type=other`) | **false (exempt)** | 0 | `CamReconciliationService::billChargeImmediately()` | `app/Services/CamReconciliationService.php:236-256` |
| `late_fee` (line, not a charge) | n/a — line written with `vat_rate=0` | 0 | `LateFeeService::applyTo()` | `app/Services/LateFeeService.php:77-85` |
| Hand-created charge (DB default) | true | 14.00 | DB default | `...create_charges_table.php:27-28` |

> The seed lives in **`LeaseCreationService::seedStandardCharges()`** (static, called
> from the lease create flow). It reads the lease's `base_rent_monthly` and
> `service_charge_monthly` fields and creates the two standard charges with the
> exempt/taxed split above. The marketing levy is then created (also exempt) when
> base rent > 0 (`app/Services/LeaseCreationService.php:124-128`).

---

## Worked examples (real numbers)

### Example 1 — a normal monthly invoice (mixed taxed + exempt)

A lease has three active monthly charges:

| Charge | amount | vat_applicable | vat_rate |
|---|---|---|---|
| Base Rent | 100,000.00 | false | 0 |
| Service Charge | 20,000.00 | true | 14 |
| Marketing Levy (5% of rent) | 5,000.00 | false | 0 |

Billing runs for the month, `factor = 1.0`. Per line
(`MonthlyBillingService:221-239` → `InvoiceItem:33-38`):

| Line | amount | vat_rate | vat_amount = round(amount×rate/100,2) | total |
|---|---|---|---|---|
| Base Rent | 100,000.00 | 0 | 0.00 | 100,000.00 |
| Service Charge | 20,000.00 | 14 | round(20000×0.14,2) = **2,800.00** | 22,800.00 |
| Marketing Levy | 5,000.00 | 0 | 0.00 | 5,000.00 |

Header roll-up (`MonthlyBillingService:241-243`):

```
subtotal   = 100000 + 20000 + 5000 = 125,000.00
vat_amount = 0 + 2800 + 0           =   2,800.00
total      = 125000 + 2800          = 127,800.00
```

**Only the service charge bears VAT.** Tenant pays 127,800.00, of which 2,800.00 is
VAT (collected on the 20,000 service charge alone).

### Example 2 — VAT on a prorated line (mid-month commencement)

Same service charge of 20,000.00, lease commences on day 16 of a 30-day month,
billed with `prorate = true`. `daysBilled = 15`, `daysInPeriod = 30`,
`factor = 15/30 = 0.5` (`MonthlyBillingService:212-217`):

```
amount     = round(20000 × 0.5, 2)        = 10,000.00
vat_amount = round(10000 × 14 / 100, 2)   =  1,400.00
total      = 10000 + 1400                 = 11,400.00
```

VAT is charged on the **prorated** 10,000, not the full 20,000. (The factor is kept
full-precision; only the per-line *amount* is rounded — see
`MonthlyBillingService:215-217`.)

### Example 3 — fractional rounding, two service charges

A line of 33.33 at 14%:

```
33.33 × 14 / 100 = 4.6662 → round(…,2) = 4.67
```

Two such lines: each rounds to 4.67 *first*, then the header sums: `4.67 + 4.67 =
9.34`. (Round-of-sum would give `round(9.3324,2)=9.33` — the system deliberately
uses **sum-of-rounded**, so the header matches what the tenant sees per line.)

### Example 4 — ETA document for Example 1

`EtaJsonBuilder::build()` re-expresses the same invoice
(`app/Services/Eta/EtaJsonBuilder.php`):

- **Lines** (`buildLines`, lines 71-104): one `invoiceLines` entry per item.
  - Service Charge line: `salesTotal = 20000`, `netTotal = 20000`,
    `total = 22800`, and `taxableItems = [{ taxType: "T1", subType: "V009",
    rate: 14, amount: 2800 }]` (lines 96-101). `itemCode` = `eta.egs_codes.service_charge`
    (default `EG-6820-002`).
  - Base Rent + Marketing lines: `taxableItems = []` (empty — `vatRate > 0` is
    false), `total = amount`. Base Rent `itemCode` = `EG-6820-001`.
- **Header** (lines 62-68): `totalSalesAmount = netAmount = 125000` (= subtotal),
  `totalAmount = 127800` (= total).
- **taxTotals** (`buildTaxTotals`, lines 106-117): `[{ taxType:"T1", amount:2800 }]`
  — the document-level VAT, equal to `invoices.vat_amount`. If `vat_amount <= 0`
  (a fully-exempt invoice), `taxTotals` is `[]`.
- ETA amounts are rounded to **5 decimals** (`round($value, 5)`, lines 138-141) —
  ETA's precision, distinct from the 2dp the ledger stores.

---

## Every edge case + how the system handles it

- **Fully VAT-exempt invoice** (only base rent / marketing): every line `vat_rate =
  0`, so `vat_amount = 0`, `total = subtotal`. ETA `taxTotals` returns `[]`
  (`EtaJsonBuilder:108-111`) and each line's `taxableItems` is `[]`.
- **`vat_applicable = true` but `vat_rate = 0`:** VAT = `amount × 0 = 0`. Legal but
  unusual; behaves identically to exempt for arithmetic. The taxed/exempt
  distinction that matters downstream (ETA `taxableItems`) keys off `vatRate > 0`,
  so a 0-rate line carries **no** tax block.
- **`vat_applicable = false` with a non-zero `vat_rate`:** `Charge::calculateVat()`
  short-circuits to 0 (`Charge.php:54-55`); the billing engine forces the line's
  `vat_rate` to `0.0` (`MonthlyBillingService:223`). The stored `vat_rate` on the
  charge is ignored. **Exempt always wins.**
- **Proration:** VAT follows the prorated amount (Example 2). Last-day
  commencement still bills ≥ 1 day (`daysBilled = …+1`,
  `MonthlyBillingService:213`) — VAT is non-zero on a 1-day prorated taxed line.
- **Late fee:** added as a line with `vat_rate = 0, vat_amount = 0`
  (`LateFeeService:82-83`) — **late fees are not VAT'd**. The fee bumps
  `subtotal`/`total` directly then calls `recomputeTotals()` so `balance` re-derives
  from the single source of truth (`LateFeeService:90-93`).
- **CAM negative true-up (tenant over-paid):** issued as a **CreditNote**, not a
  negative charge, with `vat_amount = 0` (`CamReconciliationService:309-328`). It's
  VAT-free because it is a settlement adjustment, and modelling it as a credit note
  (rather than a negative line) prevents the invoice total from going negative and
  being floored to 0 — which historically **lost the credit** (see the comment at
  `CamReconciliationService:174-180`). The credit auto-applies FIFO to the lease's
  open invoices (`applyCreditToOpenInvoices`, lines 288-306).
- **CAM positive true-up (tenant owes more):** billed **immediately** on a dedicated
  recovery invoice with `vat_applicable = false, vat_rate = 0` and
  `vat_amount = 0` (`CamReconciliationService:236-286`). It is **not** deferred to a
  monthly run — an ended-term lease would be skipped by the active/expiry filter and
  the revenue would be silently lost (comment, lines 227-235).
- **Percentage rent:** the computed charge is VAT-exempt (`vat_applicable = false`),
  `PercentageRentCalculationService:159-160`.
- **Credit notes carry their own VAT fields** (`credit_notes.vat_amount`,
  `CreditNote.php:37,53`), but every system-issued credit note (CAM, reversal) sets
  `vat_amount = 0`. The manual credit-note form's per-line VAT rate **defaults to
  `0`** (`CreditNoteForm.php:136`), unlike the invoice form's default of `14` — a
  credit note is an adjustment, taxed only if an admin explicitly sets a rate.
- **Cancelling an invoice that consumed credit:** the consumed credit is returned as
  an offsetting credit note (`Invoice::booted` → `reverseAppliedCredit`,
  `Invoice.php:235-238`, `CreditNoteService:99-123`). The offsetting note is
  `vat_amount = 0`. **Exception:** a `'credited'` invoice (paid *by* a credit note)
  is **not** reversed — its credit is the intended settlement (comment,
  `Invoice.php:228-238`).
- **ETA: business receiver with no tax_id:** `EtaJsonBuilder::build()` throws a
  clear `RuntimeException` before submission (`EtaJsonBuilder:30-34`) rather than
  letting ETA reject opaquely. Individuals map to type `P`, foreigners to `F`,
  everything else to `B` (`mapTenantType`, lines 119-127).
- **ETA item code missing for a type:** falls back to `eta.egs_codes.default`, then
  to the literal `EG-6820-999` (`mapItemCode`, `EtaJsonBuilder:129-136`).
- **Manual invoice VAT bounds:** the admin form caps `vat_rate` to `0–100`
  (`InvoiceForm.php:181-182`); a negative or >100% rate can't be entered.

---

## Invariants + gotchas

- **`paid_amount`/`balance` are never set directly** — `Invoice::recomputeTotals()`
  is the single source of truth (`Invoice.php:255-283`). VAT touches `total` (via
  `subtotal + vat_amount`), and `balance = total − paid_amount` flows from there. Do
  **not** write `vat_amount`/`total` on an invoice header by hand without
  re-summing the lines.
- **VAT is line-derived, header-summed.** `invoices.vat_amount` must always equal
  `Σ invoice_items.vat_amount`. The `InvoiceItem::saving` hook + the billing engine's
  header sum guarantee this; bypass them (e.g. raw SQL line insert) and you break the
  invariant.
- **Exempt = `vat_rate 0`, not "skip the line".** Exempt charges still appear as
  invoice lines (base rent, marketing) and contribute to `subtotal`; they just add 0
  to `vat_amount`. Don't filter them out.
- **No global VAT-rate setting.** 14% is duplicated as a *default* in three places
  (column default, seed service, form default). They agree today. If the statutory
  rate ever changes, all three plus the type table above must move together — there
  is no single switch. Historical charges keep their captured rate (intended:
  changing the default must not retroactively re-tax old invoices).
- **Base rent VAT-exempt is enforced at creation, not by type.** Nothing stops an
  admin editing a `base_rent` charge to `vat_applicable = true`. The exemption is a
  *seed default*, not a hard constraint — treat the seed and the type table as the
  policy, and audit any base-rent charge with VAT on.
- **ETA precision differs from the ledger.** Ledger stores 2dp; the ETA document
  rounds to 5dp (`EtaJsonBuilder::round`). The submitted `taxTotals.amount` equals
  the ledger `vat_amount` to 2dp (no extra precision is invented).
- **ETA tax codes are placeholders.** `eta.egs_codes.*` and the issuer TRN are demo
  values until the operator's taxpayer profile is finalised — override via env, no
  code change (`config/eta.php:51-63`). `V009` (VAT) and `T1` are the real ETA codes
  and are correct as-is.
- **Idempotency / locks around money (so VAT isn't double-counted):**
  - Monthly billing holds `Cache::lock('billing:run:Y-m', 900)` so concurrent runs
    can't double-bill a period (`MonthlyBillingService:34-41`), plus a
    period-overlap "already billed" guard (lines 75-83, 136-143).
  - CAM `generateAllocations`/`bill` use `lockForUpdate` + re-check status inside the
    txn so a true-up can't be billed twice (`CamReconciliationService:60-67,
    188-191`).
  - Credit-note `applyToInvoice` locks both rows and re-checks balance under the lock
    (`CreditNoteService:44-49`) to prevent over-applying a credit.
  - Late-fee `applyTo` locks the invoice and re-checks "no late_fee yet" inside the
    txn (`LateFeeService:66-73`) — the fee (and its 0 VAT) is added at most once.

---

## Where it lives in the code (file:line index)

| Concern | File:line |
|---|---|
| Per-charge VAT formula | `app/Models/Charge.php:52-63` |
| Charge VAT columns/casts | `app/Models/Charge.php:31-45` · migration `…000005_create_charges_table.php:27-28` |
| Per-line VAT derived on save | `app/Models/InvoiceItem.php:33-38` |
| Invoice header VAT columns | `…000006_create_invoices_table.php:30-34` · casts `app/Models/Invoice.php:60-67` |
| Engine: line VAT + exempt gate | `app/Services/MonthlyBillingService.php:221-239` |
| Engine: header roll-up | `app/Services/MonthlyBillingService.php:241-243` |
| Engine: proration factor | `app/Services/MonthlyBillingService.php:206-219` |
| Billing run lock + overlap guard | `app/Services/MonthlyBillingService.php:34-41, 75-83, 136-143` |
| Seed: base rent (exempt) / service charge (14%) | `app/Services/LeaseCreationService.php:94-122` |
| Marketing levy (exempt) | `app/Services/MarketingLevyService.php:41-56` |
| Percentage rent (exempt) | `app/Services/PercentageRentCalculationService.php:150-164` |
| Late fee line (no VAT) | `app/Services/LateFeeService.php:77-93` |
| CAM positive true-up (exempt recovery invoice) | `app/Services/CamReconciliationService.php:236-286` |
| CAM negative true-up (credit note, no VAT) | `app/Services/CamReconciliationService.php:288-328` |
| Credit-note apply / reverse (VAT-free adjustments) | `app/Services/CreditNoteService.php:36-123` |
| Single source of truth (totals) | `app/Models/Invoice.php:255-283` |
| Manual invoice form VAT math | `app/Filament/Admin/Resources/Invoices/Schemas/InvoiceForm.php:183, 248-363` |
| Credit-note form VAT default (0) | `app/Filament/Admin/Resources/CreditNotes/Schemas/CreditNoteForm.php:130-141` |
| ETA: per-line tax block (T1/V009) | `app/Services/Eta/EtaJsonBuilder.php:71-104` |
| ETA: document-level taxTotals | `app/Services/Eta/EtaJsonBuilder.php:106-117` |
| ETA: EGS item-code mapping | `app/Services/Eta/EtaJsonBuilder.php:129-136` · `config/eta.php:55-63` |
| ETA: receiver type + tax_id guard | `app/Services/Eta/EtaJsonBuilder.php:30-34, 119-127` |
| ETA toggles | `app/Settings/EtaSettings.php` · `config/eta.php:23-24` |
| API exposes VAT | `app/Http/Resources/Api/V1/InvoiceResource.php:27` · `InvoiceItemResource.php:23-24` |

---

## Related

- `docs/modules/05-billing-invoices.md` — the full billing/invoice lifecycle that
  produces the lines this doc taxes.
- `docs/modules/16-eta-einvoicing.md` — the end-to-end ETA submission flow
  (signing, mock vs. preprod, statuses) that consumes the tax breakdown here.
- `docs/modules/07-credit-notes.md` — credit-note issuance/apply/void (VAT-free
  adjustments referenced above).
- `docs/modules/08-cam.md` — CAM expense pools and true-up reconciliation
  (exempt positive charge / negative credit note).
- `docs/modules/09-tenant-sales-percentage-rent.md` — percentage-rent charge
  creation (VAT-exempt).
- `docs/modules/13-marketing.md` — the marketing levy (VAT-exempt, % of base rent).
