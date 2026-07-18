# Egyptian tax catalog — the settings-driven tax spec (source requirement)

> **Status:** captured spec, **not yet built**. Provided by the operator 2026-07-19 as a Numbers sheet
> titled *"ETA tax codes mixin (account.tax)"* — i.e. the Egyptian Tax Authority (ETA) code set in the
> shape of Odoo's `account.tax` model. This is the **source-of-truth requirement** for the
> "settings-driven Egyptian tax catalog" roadmap item ([ROADMAP §5 Product](../ROADMAP.md)); the operator
> will confirm the exact treatment with their accountant before we build. Do **not** invent rows beyond
> this sheet — this is the client's actual data.

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
  from this catalog instead of a hardcoded 14%. See [docs/plans/04-owner-statements-disbursements.md](../plans/04-owner-statements-disbursements.md).
- **Supersedes** the hardcoded VAT/marketing-levy constants once built (a migration/backfill, not a rewrite).
- **Cross-cutting** — a config/settings feature, not part of the owner-statements build. Needs the
  accountant's sign-off on treatment (especially withholding mechanics + stamp applicability) first.
