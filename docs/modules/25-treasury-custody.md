# Module 25 — Treasury / Custody (الخزينة والعُهد)

> **Status: Phase 1 shipped (custodies / عهدة).** Cash placed in a custodian's hands to
> spend for the company, settled by categorised expenses (with receipts) or cash returns —
> all posting to the double-entry ledger. Property-scoped `CustodyResource` + a settlements
> relation manager + `custodies.*` RBAC (accounting) + the `custodies` module flag. Delivers
> the core of discovery backlog item **TREAS-1** (custodies/عهدة). Multi-treasury (petty-cash
> boxes, sub-treasuries) and **multi-currency (TREAS-2)** are deferred — the latter is gated
> on **Q-F**, which was **decided 2026-08-20 (EG-07): EGP only, and enforced at the value set**; everything here is EGP.

An operator constantly hands cash to staff — a purchasing agent, a site engineer — to buy
materials or pay small bills, later settled against receipts. This module keeps that **عهدة**
lifecycle on the books: the money is an asset (in the custodian's hands) until it's spent or
returned.

**Distinct from:** employee **advances/loans** (module 24 — a personal loan repaid in cash),
direct **expenses**/vendor bills (module 21 — the company pays a supplier directly), and the
flat cash/bank chart accounts.

---

## 1. Domain model

### `custodies` — the عهدة register (per property)
| Column | Meaning |
|--------|---------|
| `employee_id` · `asset_id` | the custodian + **denormalised** property (GL dimension survives the employee being archived) |
| `reference` · `purpose` | free-form label + description |
| `amount` · `custody_date` · `paid_from` | the grant (cash \| bank) |

### `custody_transactions` — settlements (child of the custody)
| Column | Meaning |
|--------|---------|
| `custody_id` · `asset_id` | the custody + denormalised property |
| `type` | `expense` (with `category`) \| `return` (with `method` cash\|bank) |
| `amount` · `transaction_date` | the settlement |

Outstanding = `amount − Σ settlements` (DERIVED, never cached).

---

## 2. Business rules

1. **Property-scoped** (`asset_id`), denormalised from the custodian — scoped in Filament via
   `BypassesScopingOnAll` + `tenantOwnershipRelationshipName='asset'`; the create page
   re-validates the custodian's property against `visibleAssetIds()` (tamper guard). The
   **custodian is fixed at grant** (locked on edit) so the books dimension can't drift.
2. **No grant to a terminated employee**, and **no non-positive amount** (`GrantCustodyService`).
3. **A settlement can't exceed outstanding** — `SettleCustodyService` re-checks under a
   `lockForUpdate`, so concurrent settlements can't over-spend the custody.
4. **Grant terms lock once settled** — amount / date / paid-from become read-only once the
   custody has any settlement (editing them would misstate outstanding). **Enforced on the model
   since 2026-08-11** (module 25 close-out); until then it was `->disabled()` on `CustodyForm` and
   nothing else, so an import, the console, the API or a future screen walked past it. The doc's own
   parenthesis was the failure scenario: outstanding is DERIVED (`amount − Σ settlements`), so
   lowering `amount` under what is already settled makes it NEGATIVE — the register showing a
   custodian owing money never granted to them — while the grant's Dr Custodies / Cr Cash entry
   re-derives at the new figure and the settlements' credits do not move, so Custodies stops netting
   to zero. `paid_from` decides WHICH account was credited, after the cash has left it.
   **"Once settled", not "on grant":** a عهدة keyed wrongly stays fixable until it is spent against.
   `purpose` and `reference` carry no money and no dimension, so they stay editable.
   Tests: `CustodyGrantTermsLockTest`.
6. **The custodian is fixed from the grant** — `asset_id` is denormalised FROM the employee, so
   moving them moves the books dimension with it and a settled عهدة's entries land in another
   property. Rule 1 already stated this; it too was form-only until the same pass.
5. **NOT-NULL money** — blank custody `amount` / settlement `amount` coerce to 0.

**Verified clean in the same pass, recorded so nobody re-checks it:** outstanding is DERIVED and has
no column (asserted by a test, so adding one would fail the build) — the "two truths about one
number" class that bit modules 22 and 01 cannot arise here; `CustodyTransaction::create` has exactly
ONE caller in the codebase, `SettleCustodyService`, which re-checks outstanding under a
`lockForUpdate`, so the over-settled state is unreachable rather than merely guarded (keep it that
way — a second creator would need its own cap); and both GL sources run through the real
`accounting:sync-ledger` sweep in `CustodyLedgerTest` / `CrossModuleGlScenarioTest`, not the
journalizer alone.

---

## 3. GL posting

Custodies post through **two journalizers** registered in `LedgerPoster` + reconciled by the
`accounting:sync-ledger` sweep. **Custodies `11204001`** is a dedicated asset (money in a
custodian's hands) — NOT accounts receivable — so the AR tie-out is unaffected.

| Event | Source | Entry |
|-------|--------|-------|
| **Grant** | `Custody` | Dr Custodies `11204001` / Cr **Cash `11101001` \| Bank `11102001`** (per `paid_from`) |
| **Expense settlement** | `CustodyTransaction` (expense) | Dr **Expense (by category)** / Cr Custodies |
| **Cash return** | `CustodyTransaction` (return) | Dr **Cash \| Bank** (per `method`) / Cr Custodies |

- The expense **category → P&L account** goes through the shared `MapsExpenseCategory` trait, which
  since EG-13 asks the `expense_categories` CATALOGUE first and falls back to the six-entry map
  (`other` → admin_expense). Same resolution as vendor bills and direct expenses, so the three
  cannot drift. A category with no row, or a row with no account, books exactly where it always did.
- The **return** branch is deliberately not catalogued: `custody_transactions.method` holds
  cash|bank and is not widened by the payment-rail catalogue, so it stays on the role map.
- Grant + settlements net Custodies back toward zero as the عهدة is spent/returned.
- **Denormalised `asset_id`** — the entry's dimension survives the custodian's archival; the
  journalizer resolves the custodian name `withTrashed`.
- **Settlement is a CHILD ledger source** — its GL follows the custody's lifecycle via
  `Custody::booted()` (soft-delete cascades to settlements, matched on the parent's
  `deleted_at`; restore is exact), so the windowed sweep self-heals (the child-source pattern).
- **Mapping:** `custody → 11204001` (added to `AccountMappingSeeder`).

---

## 4. RBAC & module flag

- Permissions `custodies.view/create/edit/delete` (delete = super_admin only) + the financial
  action `custodies.settle`. Granted to **accounting** (treasury domain) + view/create/edit/settle;
  **manager** (all non-delete) and **viewer** (all `.view`) inherit via the flat list.
- Module flag **`custodies`** (`Modules::KEYS` + `ModulesSettings`), on by default.

---

## 5. Roadmap

| Phase | Scope | Status |
|-------|-------|--------|
| **1 — Custodies (عهدة)** | `Custody` + `CustodyTransaction` posting to the GL (grant Dr Custodies / Cr Cash\|Bank; expense/return settlements), grant + settle services (lock-safe over-settle guard), property-scoped `CustodyResource` + settlements relation manager, chart account + mapping + 2 journalizers + sweep, tests | ✅ shipped |
| **2 — Multi-treasury / petty cash** | multiple cash boxes / bank accounts, each mapping to its own chart account, with transactions routed to a chosen treasury (reshapes the single cash/bank routing) | ⏳ |
| **2b — Multi-currency (TREAS-2)** | per-account currency + FX/exchange-rate — **declined by decision (Q-F / EG-07): EGP only, enforced.** A USD-*linked* lease is EG-31 — index the escalation, denominate in EGP | ⛔ |

---

### Custody register CSV export (UX, 2026-07-23)

The عهدة register — who holds company cash, how much they've settled, and what's still outstanding —
is the treasury's core control, and it lived only on screen. Added an **Export CSV** action on the
Custodies list via the shared `App\Support\ReportCsv` (UTF-8 BOM). `CustodyResource::registerCsv()`
reads the **same property-scoped query and derived `settled_sum` subquery the table shows** (so the
export can never disagree with the screen), emits date / custodian / reference / purpose / property /
amount / settled / outstanding / paid-from per custody, and closes with **amount, settled and
outstanding totals** — the outstanding-custody schedule an accountant reconciles. Double-gated
(`visible()` + `authorize()` on `canViewAny()`). Same accountant-workable finding as inventory (mod
22), fixed assets (mod 23) and payroll (mod 24).

## 6. Tests

`tests/Feature/Regression/CustodyRegisterCsvTest.php` — the register CSV values each custody at
`amount − settled` **scoped to the user** (a restricted accounting user gets their mall's custodies,
not the portfolio) and closes with amount / settled / outstanding totals.

`tests/Feature/Services/CustodyLedgerTest.php` — grant (Dr Custodies / Cr Cash|Bank, not
touching AR/AP), expense settlement (Dr Expense by category / Cr Custodies), cash return (Dr
Cash / Cr Custodies), derived settled/outstanding + over-settle guard, grant-to-terminated +
amount guards, Custodies netting to zero after full settlement, and the cascade void-on-delete
through the **windowed sweep**.

`tests/Feature/Resources/CustodyResourceTest.php` — `custodies.*` RBAC gating, module-off
hiding, property scoping, the expense + return settlement actions, the maxValue over-settle
guard, and the `custodies.settle` gating.

**Related:** 21 General Ledger (posting + the expense-category map), 24 HR / Employees (the
custodian), 01 Properties (asset scope), 18 RBAC.

### Closed-period guard covers the GRANT side too (gap-analysis, 2026-07-29)

F-93 guarded the **settlement** of a عهدة and left the **grant** unguarded — same bug class, other
half of the same document. `GrantCustodyService` now runs `custody_date` through
`App\Support\PostingDate`.

Why it matters as much as the settlement side: an unguarded grant created a custody the custodian
is on the hook for, with no *Dr Custodies / Cr Cash* behind it — and the settlement guard then
refused **every** settlement of it (a settlement may not predate its grant). The عهدة ended up
stuck: recorded, unbacked in the books, and unsettleable.

Tests: `tests/Feature/Regression/PostingDateGuardTest.php` (mutation-checked).

---

## Deletion policy

Operator decision 2026-07-31, following Yardi/MRI/Entrata: a record that carries history is
**refused**, not warned about — the damage lands on the reports and audit trail that referenced
it, none of which are in front of whoever clicks the button. The single register is
[`App\Support\DeletionPolicy`](../../app/Support/DeletionPolicy.php); `DeletionPolicyConformanceTest` fails the build if a model here ships unclassified or a Delete
button reappears on a money record.

| Model | Rule | Instead / why |
|---|---|---|
| `DepositTransaction` | **Never deletable** | reverse the deposit transaction |
| `Custody` | Deletable (super_admin) | operational: settled through SettleCustodyService |
| `CustodyTransaction` | Deletable (super_admin) | parent-managed: removed on settlement |

> **⚠️ A custody date could be back-dated into a CLOSED period from the Edit form (fixed 2026-09-02).**
> `Custody` declared `#[PostingDateGuardedBy(GrantCustodyService::class)]` and that service does
> assert it — but the edit form reached the same column unguarded, so an un-settled عهدة could be
> moved into a closed month: the row saves, the operator reads "Saved", and the GL re-post is
> refused inside the best-effort sync that only logs.
>
> **The guard belongs on the MODEL, and the first attempt put it on the page — which broke every
> settled custody.** `CustodyForm` disables `custody_date` once anything has been spent against it,
> a disabled field is not dehydrated, so `$data['custody_date']` was ABSENT and the hook refused a
> date that had not moved. Editing only the *purpose* of a settled عهدة became impossible — exactly
> what this model's own docblock promises stays editable, and 28 custody tests were green over it.
> `GuardsPostingDate` is dirty-only and `filled()`-guarded for precisely those two reasons, and on
> the model it also covers the importer, the console and the API, which a form hook never could.

---

## Sweep fixes — 2026-09-04

*Designed by the patch fleet, adversarially reviewed, then applied and tested one at a
time. Each row's full claim and evidence is in [docs/qa/DEEP-SWEEP-2026-09-01.md](../qa/DEEP-SWEEP-2026-09-01.md).*


### SW-107


### The custodian picker always offers the record's own custodian
`CustodyForm::employeeOptions()` narrows to `Employee::query()->active()` of the visible properties, and it appends the record's OWN `employee_id` when that narrowing would drop it. Both halves are load-bearing and the second is not cosmetic: Filament derives an `in:` rule from a Select's options and validates the CURRENT value against it (`Select::getInValidationRuleValues()` returns `[]` once `getOptionLabel()` comes back blank, giving `Rule::in([])`), and a **disabled field is still validated** — `disabled()` only stops it being SAVED. So with the active-only list alone, terminating the custodian made the whole custody record unsavable: the purpose and reference too, which `Custody::saving()` deliberately leaves editable ("an operator must be able to record what it turned out to be for"). The lockout arrived on exactly the day an outstanding custody has to be chased. Two reachable states drop the stored custodian and one mechanism covers both — termination, and the employee's `asset_id` moving to another mall while the custody keeps the property it was granted under; the migration's own docblock had already anticipated this ("asset_id is denormalised from the custodian employee so the GL dimension survives the employee being archived"). A soft-deleted employee is not a third: `Employee` is `#[DeletableWhenUnused(blockedBy: [..., 'custodies'])]`. **CREATE is untouched** — a new custody still cannot be granted to somebody who has left. Same shape as `App\Support\EquipmentPicker` and the `UnitForm` area picker.

