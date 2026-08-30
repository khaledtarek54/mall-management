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
- **A cheque naming NO invoice settles what is open when it clears** (2026-08-24). A series cheque is
  deliberately not pre-linked — the month's invoice may not exist when the book is handed over — and
  `lodgeSeries()`'s docblock has always promised that each one "settles whatever is open when it
  clears, through the normal clear() flow". Until this shipped, `clear()` allocated only
  `if ($cheque->invoice_id)`, so the promise was kept by nothing: the receipt captured with **zero
  allocations**, and a wholly unallocated receipt belongs to no property, because
  `Tenant::creditBalance([$assetId])` attributes credit through the invoices a payment settles. The
  per-property cap read 0, `ApplyTenantCreditService` refused every draw, `Invoice::saved`'s
  auto-apply swallowed that refusal as the ordinary case — and the month's invoice stayed open for
  the overdue sweep and `LateFeeService` to find. **The tenant was chased, and could be charged a
  late fee, while the mall held their cleared cash.** `PaymentForm` already refuses creating a
  zero-allocation receipt for exactly this orphaning reason; the cheque path was minting the record
  that guard exists to prevent. It now settles the tenant's own open invoices **in the cheque's
  property, oldest due first**, under a locking read (a plain read behind the cheque lock answers
  from the pre-wait snapshot), capped per invoice and backstopped by
  `assertInvoicesNotOverAllocated()`. Deliberately **not** scoped to `lease_id`: Voyager applies a
  receipt at the customer record, and one cheque legitimately covers whatever that tenant owes in
  that mall. Any surplus stays on account and **is drawable**, because `creditBalance()` now falls
  back to the cheque's own property for a receipt with no allocations at all — the same fact
  `Payment::originatingAssetId()` already files the GL entry under. (`ClearedChequeSettlesWhatIsOpenTest`.)
- **Bounce** reverses nothing (no Payment was made before clearing); the cheque can be re-presented.
- **Cancel** voids a not-yet-cleared cheque. A **cleared or cancelled cheque is terminal-immutable**.
- Every transition is lock-safe + idempotent (row-lock + re-check under the lock).
- **Clearing against a CANCELLED invoice allocates nothing** — its balance is forced to 0, so
  `min(amount, balance)` is 0 and the cheque's money becomes an unallocated on-account credit rather
  than settling AR that has left the books. This mirrors `refitAllocationsToBalance()` on the gateway
  path. Correct all along, but untested until the 2026-08-11 validation sweep gave it a witness.

### One physical cheque, one register row (2026-08-11)

`cheque_number` had **no uniqueness at any layer** — no DB constraint, no model guard, not even a form
rule. Two rows for one piece of paper are each independently clearable, and each clear records a
captured Payment: the second settles AR that no money backs, or mints an on-account credit the tenant
never funded. `lodgeSeries()` makes it easy to hit by accident — re-run over the same cheque book it
regenerates the identical sequential numbers *by design*.

`PostDatedCheque::assertChequeNumberNotAlreadyLodged()` is the guard, keyed on **(tenant, bank,
number)** among non-**cancelled** cheques:

- a cheque number is unique within a bank ACCOUNT, so two tenants at different banks may share one;
- a blank `bank_name` on either side cannot distinguish two cheques, so it collides with anything of
  that tenant's carrying the same number;
- **cancelled is excluded** so a mis-key can be cancelled and re-lodged — that carve-out is exactly
  why this is a model guard and not a unique index.

It fires on create and on any edit that moves the number / bank / tenant / status. The create and edit
pages mirror it as a toast over a still-filled form, calling the **same** predicate. Deliberately
**not** a Filament `unique()` rule: keyed on the client-supplied `tenant_id` it would be the
cross-tenant existence oracle `UniqueRuleScopeConformanceTest` exists to stop.

**Deviation from Yardi, stated:** Yardi *warns* on a duplicate cheque number and lets the operator
proceed. We refuse — a PDC register that double-counts is a cash forecast wrong in the operator's
favour, and cancel-then-re-lodge costs one click. Tests: `ChequeNumberIsUniquePerTenantBankTest`.

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
- `pdc:scan-coverage` (scheduled **weekly**, Mondays 08:00) — the opposite question, and the one
  nothing could answer until 2026-08-19: which active leases are about to **run out of lodged
  cheques while the term runs on**. Egyptian practice is a year of cheques lodged against a longer
  lease, so running dry mid-term is the normal shape of the arrangement — and it is invisible,
  because every lodged cheque clears on its date and the register stays green right up until the
  month the money simply stops. **The failure is the ABSENCE of a row**, so no query over the rows
  that exist can find it; `pdc:scan-maturing` reports instruments that exist and are late, this
  reports the instruments that do not exist yet. Separate command deliberately: one says *collect
  this*, the other says *ask for this*, and they go to different people on different timescales.
  Weekly rather than daily because the answer moves when a batch is lodged, not overnight.

  **Coverage is the latest `cheque_date` among cheques still AWAITING collection** (`held` /
  `deposited` — the shared `AWAITING_STATUSES`). A **`cleared` cheque is excluded even though it is
  the happy outcome**: coverage is a forward question, and a banked cheque answers nothing about
  the months ahead — counting it would make a lease look covered by the very instrument that was
  consumed, which is the failure this exists to catch. **A lease with no cheques at all is not
  reported** — that is a tenant who pays by transfer, and alerting on those would fire for most of
  the portfolio on the first run, which is how an alert becomes a folder nobody opens.

## 4. Filament surface

`PostDatedChequeResource` (Accounting nav) — the register with a **Matured & uncleared** filter, a create form
(property + tenant + optional invoice + cheque details). **Deposit / Clear / Bounce / Charge NSF fee / Cancel**
moved off the row on 2026-08-30 and onto the cheque's own Edit page — the list FINDS, the record ACTS — defined
once in `App\Filament\Admin\Actions\PostDatedChequeActions` (still dual-gated). Property-scoped via the AnnouncementResource pattern (`BypassesFilamentTenantAutoScope` + manual
`getEloquentQuery` + `assertAssetInScope` on create/edit). Permissions `post_dated_cheques.{view,create,edit,delete}`;
**Clear additionally requires `payments.create`** (it records a Payment).

## 5. Gotchas & extension points

- **AR never changes until the cheque clears** (v1). A bounced held cheque leaves the invoice exactly as it was.
- **Future refinement:** the Notes-Receivable accrual on receipt (needs the accountant + a `notes_receivable`
  account mapping to `11205001`) — would move the receivable to a note on lodging and convert note→cash on clear.
- **Do not set the invoice balance directly** — clearing routes through `Payment` + `recomputeTotals()`.
- **`clear()` locks the invoice + calls `assertInvoicesNotOverAllocated`** (close-out 2026-07-27) — two cheques
  clearing the same invoice concurrently must not over-settle it. Mirror this on any new settle path.
- **A linked invoice must match the cheque's property AND tenant.** The model `saving` hook is the real gate
  (the picker is scoped, but a crafted submit / a `tenant_id` edit after linking could bypass it); `clear()` also
  calls `assertInvoicesShareTenant`. Never relax to property-only — a same-mall cross-tenant clear contaminates
  the per-tenant AR sub-ledger + owner statements.
- **A cleared cheque whose payment is later voided reverses to `bounced` automatically** (`Payment::saved` →
  `reconcileClearedChequeOnReversal`), so the register never lies about a collection that was reversed and the
  matured-uncleared surfaces re-catch it. The cheque's terminal-immutability guard carves out exactly this
  `cleared→bounced` reversal; keep any new lifecycle change behind the same carve-out.

## 6. Tests

`PostDatedChequeTest` — clear records a payment + reduces AR, allocation capped at balance, deposit→clear,
bounce leaves AR untouched + re-present, cancel (and refuses a cleared one), terminal immutability; plus the
close-out (2026-07-27) cases: two cheques don't over-settle one invoice, a cross-tenant/same-property link is
refused (and the `tenant_id`-edit trigger), a voided clearing payment reverses the cheque to `bounced`; plus the
validation-sweep (2026-08-11) cases: clearing against a cancelled invoice allocates nothing (with a live-invoice
control). `ChequeNumberIsUniquePerTenantBankTest` — the duplicate-lodging refusal on create, edit and
`lodgeSeries`, with controls for a different tenant, a different bank, and re-lodging a cancelled number. Conformance:
`PropertyIsolationConformanceTest` (the create form guards `asset_id`), `TranslationCoverageTest`,
`ModuleLabelCoverageTest`, `AdminSmokeManifestConformanceTest`.

---

## Deletion policy

Operator decision 2026-07-31, following Yardi/MRI/Entrata: a record that carries history is
**refused**, not warned about — the damage lands on the reports and audit trail that referenced
it, none of which are in front of whoever clicks the button. The single register is
[`App\Support\DeletionPolicy`](../../app/Support/DeletionPolicy.php); `DeletionPolicyConformanceTest` fails the build if a model here ships unclassified or a Delete
button reappears on a money record.

| Model | Rule | Instead / why |
|---|---|---|
| `PostDatedCheque` | **Never deletable** | cancel or bounce the cheque |
