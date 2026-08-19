# Egyptian tax catalog — the settings-driven tax spec (source requirement)

> **Status: ✅ BUILT 2026-08-12** — `tax_codes` + `tax_rates`, seeded from this sheet by
> `TaxCodeSeeder`, maintained at **/admin/tax-codes**. See [§What was built](#what-was-built) for
> how the shipped model maps onto the spec below and what deliberately differs.
>
> Provided by the operator 2026-07-19 as a Numbers sheet titled *"ETA tax codes mixin (account.tax)"*
> — i.e. the Egyptian Tax Authority (ETA) code set in the shape of Odoo's `account.tax` model. This
> is the **source-of-truth requirement**. Do **not** invent rows beyond this sheet — this is the
> client's actual data. `TaxCatalogueConformanceTest` now gates that in both directions: a row
> invented beyond the sheet and a row quietly dropped from it both fail the build.

## Why this exists

Today Atriom hardcodes two taxes as constants: **VAT 14%** on service charges (base rent exempt) and the
**5% marketing levy** on base rent. The operator wants **all Egyptian taxes to be configurable settings /
dropdown selections** — a tax a user picks on a charge/fee, not a number baked into code — so a rate change
or a new tax is a setting, not a deploy. See [[project_taxes_settings_driven]].

## The catalog (verbatim from the operator's sheet)

Columns: **Tax Name · Description · Tax Type · Tax Scope · Label on Invoices · Active**. Every rate exists in
both directions — **Sales** (output tax the operator charges) and **Purchases** (input tax the operator pays).
`Tax Scope` was blank on the sheet (Odoo uses it for goods/services — keep nullable).

### VAT — ضريبة القيمة المضافة
| Tax Name | Label on Invoices | Directions |
|---|---|---|
| 14% | VAT 14% | Sales, Purchases |
| 0% | Zero Rated 0% | Sales, Purchases |
| 0% EXEMPT | Exempt | Sales, Purchases |

### Stamp tax — ضريبة الدمغة
| Tax Name | Label on Invoices | Directions |
|---|---|---|
| 20% | Stamp | Sales, Purchases |

### Schedule tax — ضريبة الجدول (suffix "S", label "SCHD")
| Tax Name | Description | Label on Invoices | Directions |
|---|---|---|---|
| 0.5% S | Schedule 0.5% | SCHD 0.5% | Sales, Purchases |
| 1% S | Schedule 1% | SCHD 1% | Sales, Purchases |
| 5% S | Schedule 5% | SCHD 5% | Sales, Purchases |
| 8% S | Schedule 8% | SCHD 8% | Sales, Purchases |
| 10% S | Schedule 10% | SCHD 10% | Sales, Purchases |
| 15% S | Schedule 15% | SCHD 15% | Sales, Purchases |
| 30% S | Schedule 30% | SCHD 30% | Sales, Purchases |

### Withholding tax — خصم وتحصيل تحت حساب الضريبة (suffix "WH", label "WH", **negative** rate = deducted at source)
| Tax Name | Description | Label on Invoices | Directions |
|---|---|---|---|
| 0.5% WH | Withholding -0.5% | WH -0.5% | Sales, Purchases |
| 1% WH | Withholding -1% | WH -1% | Sales, Purchases |
| 3% WH | Withholding -3% | WH -3% | Sales, Purchases |
| 5% WH | Withholding -5% | WH -5% | Sales, Purchases |

## Implied data model (for when we build)

A `taxes` (tax-code) catalog table + a picker on charges/fees:

| Field | Type | Notes |
|---|---|---|
| `name` | string | e.g. "14%", "8% S", "1% WH" |
| `description` | string nullable | e.g. "Schedule 8%", "Withholding -1%" |
| `family` | enum | `vat` · `stamp` · `schedule` · `withholding` (derived from the sheet's groupings; drives sign + GL account) |
| `direction` | enum | `sales` (output) · `purchases` (input) — the sheet's "Tax Type" |
| `rate_percent` | decimal(6,3) | **signed** — withholding is negative; `0% EXEMPT` distinct from `0%` (see `is_exempt`) |
| `is_exempt` | bool | true for "0% EXEMPT" (out-of-scope) vs a true 0% zero-rating |
| `scope` | string nullable | Odoo's goods/services scope; blank on the sheet |
| `invoice_label` | string | printed on the invoice, e.g. "VAT 14%", "SCHD 8%", "WH -1%" |
| `is_active` | bool default true | the sheet's "Active" |

**GL wiring (later):** VAT sales → `vat_payable` (21301001), VAT purchases → `vat_recoverable` (11401001);
schedule/stamp → their own payable/expense accounts; withholding → a withholding-tax-payable (a new account).
Map families to accounts through `AccountMapping` (semantic role, per the one-registry rule), never hardcode.

## Relationship to other work

- **Unblocks the deferred owner-statement management fee** — its VAT-on-fee toggle would select a VAT code
  from this catalog. See [modules/32 — owner statements](../modules/32-owner-statements.md).
- **SHIPPED 2026-08-12.** `TaxSettings::vat_standard_rate` — the 2026-07-30 half-step — is **gone**;
  the standard rate is now a dated rung on the `VAT_14` code. The marketing levy remains
  settings-driven (`MarketingSettings::levy_rate_percent`), which is correct: it is a lease term, not
  a tax.
- **Cross-cutting** — a config/master-data feature, not part of the owner-statements build. The
  accountant's sign-off on **stamp applicability** and **withholding mechanics** is still outstanding,
  which is exactly why those families ship switched off.

---

## What was built

### The model, against the spec's "implied data model"

| Spec field | Shipped as | Note |
|---|---|---|
| `name` | `code` + `name_en` / `name_ar` | `code` is the stable identity charge codes reference (`VAT_14`, `SCHD_8`, `WH_1`, `_P` suffix for purchases); the names are what an operator reads, in both languages |
| `description` | folded into `name_en` | one label, not two that can disagree |
| `family` | `family` — `vat` · `stamp` · `schedule` · `withholding` | as specced |
| `direction` | `direction` — `sales` · `purchases` | as specced (the sheet's "Tax Type") |
| `rate_percent` | **`tax_rates.rate`, on a dated ladder** | **the one deliberate departure — see below** |
| `is_exempt` | `treatment` — `standard` · `exempt` · `zero_rated` | three values rather than a bool: zero-rated is a *taxable supply at 0%* and exempt is *out of scope*. Both bill 0 and they are different lines on a return, and the distinction cannot be recovered later from a document that recorded nothing but a zero |
| `scope` | dropped | blank on the sheet, and Odoo's goods/services axis has no consumer here. Re-add it as a column when something reads it — an unused nullable column is a question nobody can answer |
| `invoice_label` | `invoice_label` | as specced |
| `is_active` | `is_active` | as specced, but **not freely settable** — see "what ships off" |

### The one deliberate departure: a rate has a date

The spec puts `rate_percent` on the tax row. The shipped model puts it on a **dated ladder**
(`tax_rates`: rate + `effective_from`, no end date — a rung runs until the next begins). The reason
is the failure the spec's own shape still permits: Egypt moved VAT 10% → 14% in 2017, and with one
rate column, editing it re-rates everything originated afterwards **including a document back-dated
into the previous regime**. `App\Support\Vat` resolves for the *document's* date, so an invoice
raised before a change keeps billing the old rate and a rate announced in advance starts applying by
itself on the day.

The no-end-date shape is not a simplification: a from/to pair makes overlapping and missing windows
representable, and overlapping date ranges on money is the exact defect `atriom:audit-charge-schedules`
exists to find — legacy leases whose charge rows overlap **bill nothing**.

### What ships switched off, and why

A code is activated only when it can actually bill — it needs a rate **and** a GL account for its
family. `TaxCode` refuses activation otherwise (`DomainException`), so this is a guard rather than a
convention:

| Family | Ships | Because |
|---|---|---|
| VAT (both directions) | **on** | `vat_payable` / `vat_recoverable` are registered posting roles pointing at real accounts |
| Withholding | **on** | `withholding_tax_payable` exists. Nothing consumes these codes yet — the vendor-payment path still reads `TaxSettings::wht_default_rate` (roadmap TX-05) |
| Stamp · Schedule | **on since 2026-08-19** | commissioned — see below |

### Commissioning stamp and schedule tax (2026-08-19)

This section used to say the remaining work was "add the posting role + chart account + mapping,
name it on the code, switch it on". **The accounts were the smaller half, and the sentence hid the
larger one.** All three journalizers threw the document's own `tax_code` away:

- `InvoiceJournalizer` summed every line's `vat_amount` into one accumulator and credited
  `vat_payable`;
- `VendorBillJournalizer` and `ExpenseJournalizer` hard-coded `vat_recoverable`.

`invoice_items.tax_code` had recorded which tax each line carried since the catalogue shipped. The
posting simply never read it — so switching stamp tax on would have put 20% of a supply onto the
**VAT return**, under the **VAT liability**, with the entry balancing perfectly and the tie-out
green. Nothing would have looked wrong.

Tax now groups by the tax code's own posting role, exactly as revenue groups by the charge code's
role in the same method, with VAT as the floor for a document that names no code.

**The asymmetry is the actual accounting content, and it is deliberate:**

| Direction | VAT | Stamp · Schedule |
|---|---|---|
| Output (sales) | `vat_payable` — liability `21301001` | `stamp_tax_payable` `21304001` · `schedule_tax_payable` `21305001` — liabilities |
| Input (purchases) | `vat_recoverable` — **asset** `11401001` | `stamp_tax_expense` `51111001` · `schedule_tax_expense` `51111002` — **expenses** |

Input VAT is creditable against output VAT, so it is an asset. Neither stamp duty nor schedule tax
has a credit mechanism an operator of this kind can use, so both are a cost. Copying the VAT shape
on the input side would have grown a receivable nobody could ever collect — an asset that is not one
— on the balance sheet, for as long as the operator kept buying. If the accountant rules otherwise
for a particular supply, the role→account map is a row at `/admin`, not a deploy.

**A fresh install ships them active. An existing database does not:** the seeder backfills the
posting role and leaves `is_active` alone, because the rule that a reseed must never reactivate a
code the operator retired is worth more than saving a click. Switch them on at `/admin/tax-codes`.

**Activation grants nothing on its own.** A tax code taxes a supply only when a charge code points
at it (`charge_codes.tax_code` — the accountant's ruling, a row, no deploy). So this makes stamp and
schedule tax *available* to the accountant; it does not decide that anything is subject to them.
That decision is theirs, and it is the one thing here that software must not guess.

Pinned by `TaxPostsToItsOwnAccountTest`.
