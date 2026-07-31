# Module 25 — Treasury / Custody (الخزينة والعُهد)

> **Status: Phase 1 shipped (custodies / عهدة).** Cash placed in a custodian's hands to
> spend for the company, settled by categorised expenses (with receipts) or cash returns —
> all posting to the double-entry ledger. Property-scoped `CustodyResource` + a settlements
> relation manager + `custodies.*` RBAC (accounting) + the `custodies` module flag. Delivers
> the core of discovery backlog item **TREAS-1** (custodies/عهدة). Multi-treasury (petty-cash
> boxes, sub-treasuries) and **multi-currency (TREAS-2)** are deferred — the latter is gated
> on open question **Q-F** (is anything billed in USD/EUR?); everything here is EGP.

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
   custody has any settlement (editing them would misstate outstanding).
5. **NOT-NULL money** — blank custody `amount` / settlement `amount` coerce to 0.

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

- The expense **category → P&L account** uses the shared `MapsExpenseCategory` trait (same
  map as vendor bills / direct expenses; `other` → admin_expense).
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
| **2b — Multi-currency (TREAS-2)** | per-account currency + FX/exchange-rate — **blocked on Q-F** (is anything billed in USD/EUR?) | ⏳ |

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
