# 33 · Post-dated Cheques (PDC register)

الشيكات الآجلة — the forward-instrument register. In Egypt a tenant commonly lodges a year of post-dated
cheques (PDCs) up front; Atriom previously captured only one cheque number on a *received* payment, with no
forward register, maturity schedule, or bounce lifecycle. This module is that register — strengthen item #4
from the competitive gap analysis, and a **differentiator** (no Western benchmark fills it).

> **v1 scope (operator decision):** **register-only, settle-on-clear.** A lodged cheque is *tracked* (with a
> maturity date + bounce lifecycle) but the tenant's invoice stays **open** until the cheque **clears**;
> clearing records a normal cheque `Payment` through the existing payment flow, so `Invoice::recomputeTotals()`
> stays the AR single source of truth. The alternative Notes-Receivable-on-receipt accrual (Dr Notes Receivable
> `11205001` / Cr AR on lodging) is a **documented future refinement**, deferred pending the accountant.

## 1. Domain model

`post_dated_cheques` (a **property-owned** register, direct `asset_id`):

| Column | Notes |
|---|---|
| `reference` | PDC-YYYY-NNNN, race-safe |
| `asset_id` | the property (isolation dimension) |
| `tenant_id` | the drawer |
| `lease_id` / `invoice_id` | optional links; `invoice_id` = the invoice it settles on clearing |
| `cheque_number`, `bank_name`, `amount`, `currency` | the instrument |
| `cheque_date` | **maturity** (the post-date) — the register sorts/filters on this = the maturity schedule |
| `received_date` | when the operator took it in |
| `status` | `held` → `deposited` → `cleared` / `bounced`; `cancelled` |
| `cleared_payment_id` | the `Payment` recorded when it cleared |

## 2. Lifecycle & rules

```
held ──deposit──▶ deposited ──clear──▶ cleared   (records a captured cheque Payment)
  │                   │
  └──── cancel ───────┴──bounce──▶ bounced ──deposit──▶ deposited  (re-present)
```

- **Clearing** (the only money step) creates a `Payment` (method `cheque`, status `captured`, `cheque_number`
  copied) and allocates it to `invoice_id` **capped at the invoice balance** (surplus stays as an on-account
  credit), then `recomputeAllocatedInvoices()`. The `cleared_on` date is guarded by `App\Support\PostingDate`
  (not future, period open — the Payment posts to the GL).
- **Bounce** reverses nothing (no Payment was made before clearing); the cheque can be re-presented.
- **Cancel** voids a not-yet-cleared cheque. A **cleared or cancelled cheque is terminal-immutable**.
- Every transition is lock-safe + idempotent (row-lock + re-check under the lock).

## 3. Services & commands

- `App\Services\PostDatedChequeService::{deposit, clear, bounce, cancel}` — the lifecycle.
- **`PostDatedChequeService::lodgeSeries(array): Collection`** — bulk-lodge a whole series in one act
  (the Egyptian norm: a tenant hands over a year of monthly cheques up front). Creates `count` cheques
  with **sequential numbers** (increments the numeric tail, keeping zero-pad width; a non-numeric
  number falls back to a `-N` suffix) and maturities `interval_months` apart. Each cheque is its own
  register entry (own PDC reference, `held`); a series is **not** pre-linked to an invoice (the month's
  invoice may not exist yet — each settles whatever's open when it clears). Surfaced as a **"Lodge a
  series"** header action with a **live preview** (count · each · total · first→last maturity).
- `pdc:scan-maturing` (scheduled daily) — reports cheques matured-but-uncleared (OpsLog) + those
  maturing soon, **off the shared `PostDatedCheque::maturedUncleared()` / `maturingWithin()` scopes**.
- **The maturity schedule is surfaced live:** an **Action Required** dashboard card counts
  matured-but-uncleared cheques (property-scoped) and links to the register's **"Matured & uncleared"**
  filter — the scan, the card and the filter all read the one scope, so they can never disagree.

## 4. Filament surface

`PostDatedChequeResource` (Accounting nav) — the register with a **Matured & uncleared** filter, a create form
(property + tenant + optional invoice + cheque details), and **Deposit / Clear / Bounce / Cancel** row actions
(dual-gated). Property-scoped via the AnnouncementResource pattern (`BypassesFilamentTenantAutoScope` + manual
`getEloquentQuery` + `assertAssetInScope` on create/edit). Permissions `post_dated_cheques.{view,create,edit,delete}`;
**Clear additionally requires `payments.create`** (it records a Payment).

## 5. Gotchas & extension points

- **AR never changes until the cheque clears** (v1). A bounced held cheque leaves the invoice exactly as it was.
- **Future refinement:** the Notes-Receivable accrual on receipt (needs the accountant + a `notes_receivable`
  account mapping to `11205001`) — would move the receivable to a note on lodging and convert note→cash on clear.
- **Do not set the invoice balance directly** — clearing routes through `Payment` + `recomputeTotals()`.

## 6. Tests

`PostDatedChequeTest` — clear records a payment + reduces AR, allocation capped at balance, deposit→clear,
bounce leaves AR untouched + re-present, cancel (and refuses a cleared one), terminal immutability. Conformance:
`PropertyIsolationConformanceTest` (the create form guards `asset_id`), `TranslationCoverageTest`,
`ModuleLabelCoverageTest`, `AdminSmokeManifestConformanceTest`.
