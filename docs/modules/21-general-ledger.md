# Module 21 — General Ledger & Accounting Core (المحاسبة العامة / دفتر الأستاذ العام)

> **Status:** Phases 0–4 shipped (foundation, auto-posting, financial statements,
> expenses/payables/payroll, close) + security deposits. Remaining follow-ups are in
> the roadmap at the bottom. Read [docs/OVERVIEW.md](../OVERVIEW.md) and
> [docs/MONEY-PATHS.md](../MONEY-PATHS.md) first — this module sits *underneath* every
> money path already documented there.
>
> **New to this module?** Start with the plain-language overview for non-accountants:
> [docs/accounting/README.md](../accounting/README.md) — what's built, what's next, and
> the mental model — then come back here for the technical detail.

This module adds a **double-entry general ledger** (نظام القيد المزدوج) beneath the
existing business documents (invoices, payments, credit notes, CAM …). Those documents
are the **sub-ledgers** (الأستاذ المساعد); the general ledger is the **company's books**
(الدفاتر). Every money event posts a **balanced journal entry** (قيد يومية متوازن) into
the ledger, and the ledger is the source of every financial report.

---

## 0. Why this exists / business context (السياق)

Before this module the system was **single-entry, accounts-receivable only**: it knew
*who owes rent* but never kept *the company's books*. An accountant (المحاسب) could not
produce a **trial balance** (ميزان المراجعة), an **income statement** (قائمة الدخل), or
a **balance sheet** (قائمة المركز المالي).

The general ledger fixes that without rebuilding billing. The design rule:

> **The general ledger never trusts the sub-ledgers' stored totals — it re-derives
> every figure from balanced journal entries.** Debits always equal credits, so the
> books cannot silently drift. This is what makes the reports trustworthy enough to
> be the *official* books (الدفاتر الرسمية).

### The one rule of double-entry
Every event touches at least two accounts, and for every entry:

```
Σ debit (مدين)  =  Σ credit (دائن)
```

Money is never created or destroyed — it *moves* from one account to another. An
unbalanced entry cannot be saved (enforced in `JournalPostingService`).

### The five account natures (طبيعة الحساب)
Every account is exactly one of these. They map directly to the two main reports.

| # | English | Arabic | Normal balance (الرصيد الطبيعي) | Goes on |
|---|---------|--------|---------------------------------|---------|
| 1 | Asset | الأصول | Debit (مدين) | Balance sheet |
| 2 | Liability | الخصوم / الالتزامات | Credit (دائن) | Balance sheet |
| 3 | Equity | حقوق الملكية | Credit (دائن) | Balance sheet |
| 4 | Revenue | الإيرادات | Credit (دائن) | Income statement |
| 5 | Expense | المصروفات | Debit (مدين) | Income statement |

`Asset + Expense` increase on the **debit** side; `Liability + Equity + Revenue`
increase on the **credit** side. The model derives `normal_balance` from `type`.

---

## 1. Domain model (نموذج البيانات)

Six tables. All money columns are `decimal(14,2)`; all user-facing names are bilingual
(`*_en` / `*_ar`) so the accountant reads them natively.

### `ledger_accounts` — دليل الحسابات (Chart of Accounts)
The master list of accounts, as a tree (the accountant's Excel turned into a table).

| Column | Type | Meaning (Arabic) |
|--------|------|------------------|
| `code` | string, unique | رقم الحساب — hierarchical, e.g. `11201001` |
| `parent_id` | FK self, null | الحساب الأب — builds the tree |
| `name_en` / `name_ar` | string | اسم الحساب |
| `type` | enum: asset/liability/equity/revenue/expense | طبيعة الحساب |
| `normal_balance` | enum: debit/credit | الرصيد الطبيعي (derived from `type`) |
| `is_postable` | bool | حساب ترحيل (leaf) vs تجميعي (rollup). **Only `is_postable` accounts may appear on a journal line.** |
| `is_active` | bool | نشط |
| `description` | text, null | بيان |

**Chart guardrails (so a hand-entered code can't corrupt the chart):**
- **`parent_id` is derived from the code**, never picked by hand — `LedgerAccount`'s
  saving hook sets it to the deepest existing account whose code is a strict prefix
  (mirrors the seeder). So the tree can never contradict the code (the form has no manual
  parent field).
- **Leading-digit ↔ type guard:** a code in a *defined* range (1 asset · 2 liability ·
  3 equity · 4 revenue · 5 expense) must carry the matching `type`; the model throws and
  the form shows an inline error (`App\Rules\AccountCodeMatchesType`) otherwise. Custom
  ranges (6-9/0) are unconstrained. `normal_balance` is always derived from `type`.
- **Re-coding is safe:** journal lines FK to the account's `id`, not its `code`, so
  editing a code never breaks existing postings (it just re-derives the parent).

**Hierarchy convention** (mirrors the accountant's sheet): `1` → `11` → `111` →
`11101` → `11101001`. Parents are *summary* accounts (no postings); the deepest
leaves are *posting* accounts.

**Adding accounts — the two traps.** The chart is a flat list in the seeder whose tree
and type are *derived*, so a hand-added row can go wrong quietly. Both are covered by
`tests/Feature/Accounting/ChartOfAccountsConformanceTest.php`:
1. **The cash-flow statement classifies by code range**, so *where you number an account
   decides which section it lands in* — see §8. A non-cash accrual numbered under `22…`
   would be reported as a financing inflow. Check the ranges below before picking a code.
2. **`AccountMappingSeeder` silently skips** a role whose account code doesn't exist
   (rather than seeding a dangling mapping), so a typo'd code turns into a posting recipe
   that fails at *runtime*, not at seed time.

**Contra accounts** carry the type of what they offset, not their balance's side — the
allowance for doubtful debts (`11206001`) is typed `asset` and sits under the AR branch
carrying a credit balance, exactly as accumulated depreciation (`12201001`) does under
fixed assets. `normal_balance` stays derived from `type`; it does not describe the balance
a contra account actually holds.

### `fiscal_years` — السنة المالية
| Column | Meaning |
|--------|---------|
| `year` (int, unique) · `starts_on` · `ends_on` | the financial year window |
| `status` | `open` / `closed` (مفتوحة / مقفلة) |

### `accounting_periods` — الفترة المحاسبية
Months inside a fiscal year. Closing a period blocks backdated entries.

| Column | Meaning |
|--------|---------|
| `fiscal_year_id` · `period_no` (1–12) · `starts_on` · `ends_on` | the month |
| `status` | `open` / `closed` (مفتوحة / مقفلة) |

### `journal_entries` — قيد يومية (header)
| Column | Meaning |
|--------|---------|
| `number` | رقم القيد — `JE-YYYYMM-NNNN`, unique |
| `entry_date` | تاريخ القيد |
| `accounting_period_id` | the period it falls in |
| `description_en` / `description_ar` | البيان |
| `source_type` / `source_id` | nullable polymorphic link to the Invoice/Payment/… that generated it (null = manual) |
| `is_manual` | bool — accountant-written vs auto-posted |
| `status` | `draft` / `posted` / `void` (مسودة / مرحّل / ملغى) |
| `asset_id` | nullable FK — which property's books (dimension) |
| `posted_by_user_id` · `posted_at` · `voided_at` | audit |
| `reversal_of_id` | nullable self-FK — links a reversing entry to the entry it cancels |

### `journal_lines` — أطراف القيد (the debit/credit lines)
| Column | Meaning |
|--------|---------|
| `journal_entry_id` | parent |
| `ledger_account_id` | الحساب (must be `is_postable`) |
| `debit` / `credit` | مدين / دائن — exactly one is > 0, the other 0 |
| `description` | بيان السطر (null) |
| `asset_id` · `tenant_id` · `lease_id` | nullable **analytical dimensions** (الأبعاد التحليلية) for filtered reports |

### `account_mappings` — ربط الحسابات (semantic role → account)
So code never hard-codes an account number. Code posts to a *role*
(`accounts_receivable`); this table resolves the role to the accountant's chosen
account. Optional per-property override.

| Column | Meaning |
|--------|---------|
| `key` | semantic role, e.g. `accounts_receivable`, `vat_payable`, `rent_revenue` |
| `ledger_account_id` | the postable account it resolves to |
| `asset_id` | nullable — a per-property override; null = global default |

---

## 2. The posting recipes (قواعد الترحيل) — how money becomes journal entries

Each business event maps to a fixed balanced entry. These recipes are wired in
**Phase 1** via small "journalizer" classes (see §4); they are listed here so the
accountant can verify the accounting treatment. `[role]` resolves via `account_mappings`.

| Event (الحدث) | Debit (مدين) | Credit (دائن) |
|---------------|--------------|----------------|
| **Issue invoice** إصدار فاتورة | `accounts_receivable` (total) | `rent_revenue`, `service_charge_revenue`, `utility_revenue`, `percentage_rent_revenue`, `marketing_revenue`, `vat_payable` — one credit line per item kind |
| **Capture payment** تحصيل دفعة | `bank` or `cash` (amount) | `accounts_receivable` (amount) |
| **Apply credit note** تطبيق إشعار خصم | `sales_returns`, `vat_payable` | `accounts_receivable` (total) |
| **Late fee** غرامة تأخير | `accounts_receivable` | `late_fee_income` |
| **Security deposit — receipt** استلام تأمين | `bank`/`cash` | `deposits_held` (a *liability*) |
| **Security deposit — refund** استرداد تأمين | `deposits_held` | `bank`/`cash` |
| **Security deposit — forfeit** مصادرة تأمين | `deposits_held` | `misc_income` |
| **CAM positive true-up** تسوية صيانة موجبة | `accounts_receivable` | `cam_recovery_revenue` (+ `vat_payable` if taxed) |
| **Vendor bill** فاتورة مورد | `*_expense` (net, by category) + `vat_recoverable` (input VAT) | `accounts_payable` (total) |
| **Pay vendor** سداد مورد | `accounts_payable` | `bank` / `cash` |
| **Direct / petty-cash expense** مصروف مباشر | `*_expense` (net) + `vat_recoverable` (input VAT) | `cash` / `bank` (total) |
| **Marketing spend** مصروف تسويق | `marketing_expense` (amount) | `cash` / `bank` (amount) — per `paid_from` |
| **Stock receipt** استلام مخزون | `inventory` (value) | `accounts_payable` |
| **Stock consumption** صرف مخزون | `maintenance_expense` (value) | `inventory` |
| **Stock adjustment** تسوية مخزون | `inventory` (found) / `inventory_adjustment` (shrinkage) | the other side (per sign) |
| **Payroll run** مسير رواتب | `salaries_expense` (gross) | `salary_tax_payable` + `social_insurance_payable` (withheld) + `bank`/`cash` (net) |

> **Tie-out invariant:** after Phase 1, the balance of `accounts_receivable` in the
> ledger must equal `Σ Invoice.balance` for live invoices. The reconciliation harness
> (`billing:reconcile`) gains a GL tie-out check.

---

## 3. Business rules & invariants (القواعد والثوابت)

1. **Debits = credits, always.** `JournalPostingService::post()` rejects an unbalanced
   payload before writing anything (rounded to 2dp).
2. **Only postable leaf accounts** may appear on a journal line; summary accounts are
   rejected.
3. **A line is one-sided:** exactly one of `debit`/`credit` is > 0.
4. **No posting into a closed period** (الفترة المقفلة). `entry_date` must fall in an
   `open` `accounting_period`; otherwise the post is refused.
5. **Posted entries are immutable.** You don't edit a posted entry — you **void** it
   (which creates a balanced *reversing* entry, قيد عكسي) or post an adjusting entry.
   Mirrors the project's "terminal records are immutable" invariant.
6. **Property is a dimension, not a separate ledger.** One shared chart; every entry/
   line can carry `asset_id`. Per-property books = filter by `asset_id`; consolidated =
   no filter. This reuses `TenantScope` exactly like every other module.
7. **The ledger is derived truth.** Reports re-aggregate `journal_lines`; nothing
   caches account balances. (A materialized balance table is a future optimization, not
   a source of truth.)
8. **Money is 2dp everywhere**, `round($x, 2)` on every amount — same as the rest of
   the money model.

---

## 4. Services & extensibility (الخدمات وقابلية التوسعة)

`app/Services/Accounting/`:

- **`AccountResolver`** — `account(string $key, ?int $assetId = null): LedgerAccount`.
  Resolves a semantic role via `account_mappings` (per-asset override → global default).
  Throws if a mapping is missing, so a mis-wired posting fails loudly.
- **`JournalPostingService`** — the engine.
  - `post(JournalEntryData): JournalEntry` — validates (balanced, postable, open period,
    one-sided lines), generates the number, writes header + lines atomically, marks
    `posted`. Idempotent per source document (one posted entry per `source_type+source_id`).
  - `void(JournalEntry, reason): JournalEntry` — posts a balanced reversing entry and
    links it via `reversal_of_id`.
- **`LedgerReportService`** — read-only aggregation for the reports: `trialBalance()`,
  `accountLedger()`, and (Phase 2) `incomeStatement()`, `balanceSheet()`. Accepts
  `asset_id` (per-property) or null (consolidated) + a date range.

### How a NEW module plugs into accounting (the extensibility contract)
This is the whole point of the design. To make any future module post to the ledger:

1. Write **one journalizer class** that turns the document into a balanced set of lines
   using `AccountResolver` for the accounts. (Phase 1 ships journalizers for invoice/
   payment/credit-note/CAM/late-fee/deposit as the reference implementations.)
2. **Register it in `LedgerPoster::JOURNALIZERS`** — one line, `Model::class => Journalizer::class`.
   This const is the single source of truth for what reaches the GL; **all four dispatch
   paths derive from it** via `LedgerPoster::sources()` — the real-time hooks, the
   `accounting:sync-ledger` sweep, the period-close gate, and `billing:reconcile`'s drift
   check. You do **not** call `LedgerPoster` from your service.
3. **Declare its entry-date column** in `LedgerRealtimeSync::SOURCE_DATE_COLUMNS` — the
   column your journalizer uses for `entry_date`. The close gate needs it to spot documents
   *dated* in a period being closed but not yet posted.
4. If your journalizer's payload walks a relation, add it to `SyncLedgerCommand::EAGER` so a
   full backfill doesn't N+1. Optional — an absent source is swept un-eager, never skipped.
5. Add any new semantic roles to `account_mappings` (+ seeder) and point them at chart
   accounts.

The general-ledger engine itself **never changes** when a module is added. New module →
new journalizer + new mappings. That is the "accounting works with any future module"
guarantee.

<a id="gl-registry-gate"></a>
> **⚠️ Never hand-copy the source list — and test through the real path.**
> Steps 2–4 are enforced by `tests/Feature/Scenarios/GlRegistryConformanceTest.php`: a
> journalizer without a date column fails CI, as does a date column for a model that can't
> post, or a sweep that stops deriving from the registry.
>
> This gate exists because the lists drifted and cost real money. `MaintenancePenalty`
> (module 26) had a correct, tested journalizer while being absent from *every* dispatch
> list, so applying an SLA penalty **cut the vendor bill's AP balance and posted nothing** —
> the GL overstated the payable, the sweep couldn't self-heal a source it had never heard
> of, and `billing:reconcile` couldn't see the drift because it walked the same short list.
> It shipped green because the scenario test called `LedgerPoster::post($penalty)` directly,
> proving the journalizer while never exercising the dispatch (fixed 2026-07-16;
> `tests/Feature/Regression/SlaPenaltyLedgerDispatchTest.php`).
>
> **So: a GL test that calls `LedgerPoster::post()`/`sync()` directly proves only that the
> journalizer's arithmetic is right.** At least one test per source must drive the operator's
> service and then `accounting:sync-ledger`, and assert the tie-out. See also the
> child-source sweep trap in §"Gotchas".

---

## 5. Filament surfaces (الواجهات)

All under the **Accounting** navigation group (`admin.groups.accounting`), gated by
`RoleGatedActions` on the permissions in §7, property-scoped via `asset_id`.

- **`LedgerAccountResource`** — دليل الحسابات. Browse/manage the chart (tree by code),
  toggle active, mark postable. Seeded with the standard starter chart.
- **`JournalEntryResource`** — قيود اليومية. Create a **manual** journal entry with a
  balanced lines repeater (live debit/credit totals); list + view auto-posted entries;
  a **Post** action and a **Void** (reverse) action. Editing is blocked once `posted`.
- **`TrialBalance` page** — ميزان المراجعة. Every account with total debit / total
  credit / closing balance; proves `Σ debit = Σ credit`. Filter by property + period.
- **`GeneralLedger` page** — دفتر الأستاذ. Per-account running statement (كشف حساب).

Income statement & balance sheet pages land in **Phase 2**.

---

## 6. Seeders (البيانات الأولية)

- **`ChartOfAccountsSeeder`** — the **standard starter chart** (bilingual), covering all
  five natures and every account the existing money paths need (cash, banks, tenant
  receivables, VAT payable, deposits held, the revenue families, the expense families,
  capital, retained earnings, sales returns). The accountant can rename/extend freely.
  Accounts the *accountant* posts by hand (no automated recipe, so deliberately **no**
  mapping role): notes receivable/payable — post-dated cheques, `11205001` / `21102001`
  (شيكات آجلة — how Egyptian tenants commonly settle) · prepaid expenses `11501001` ·
  due from/to related parties `11601001` / `21801001` (Eltizam ↔ Jawad) · allowance for
  doubtful debts `11206001` + bad debt expense `51109001` · provisions `22201001`
  (end-of-service) / `22201002` (staff leave), charged to salaries `51101001` · bank
  commission `52103001` and interest `52104001`, split out from bank charges `52101001`.
- **`AccountMappingSeeder`** — default `account_mappings` for every semantic role used by
  the Phase-1 posting recipes. A role is only worth adding once a recipe *consumes* it —
  `AccountResolver` throws on a missing role, so an unused role is dead config that
  cannot fail loudly.
- Current **fiscal year + 12 periods** are opened so manual entries work immediately.

Wired into `DatabaseSeeder` *before* `DemoSeeder` (reference data, all environments).

---

## 7. RBAC (الصل"احيات)

New permission modules in `RolesPermissionsSeeder::PERMISSIONS`:

| Module | Actions |
|--------|---------|
| `ledger_accounts` | view, create, edit, delete |
| `journal_entries` | view, create, edit, delete, post, void |
| `accounting_periods` | view, manage (open/close) |
| `general_ledger` | view (trial balance, ledger, statements) |

- **super_admin / manager / viewer / owner** inherit automatically (all-perms / non-delete /
  all-`.view` / all-`.view`).
- The **`accounting`** department role is granted the full set explicitly (incl. post/void
  and period management).
- These modules are **core** (not in `Modules::KEYS`) → always on.

---

## 8. Reports (التقارير)

| Report | Arabic | Definition |
|--------|--------|------------|
| Trial Balance | ميزان المراجعة | per account: Σ debit, Σ credit, balance — must net to zero overall |
| General Ledger / account statement | دفتر الأستاذ / كشف حساب | per account: every line, running balance |
| Income Statement (P&L) | قائمة الدخل | Σ revenue − Σ expense = net profit (contra-revenue nets in) |
| Balance Sheet | قائمة المركز المالي | Assets = Liabilities + Equity + net-income-for-period (until year-end close, Phase 4) |
| Cash-Flow Statement | قائمة التدفقات النقدية | Indirect method: net income ± working-capital changes (operating) + investing + financing. **Reconcile-by-construction:** by double-entry, ΔCash ≡ −Σ(non-cash account movements), so the three sections are classified by code range (111 = cash · 121 = gross non-current assets → investing · 122 = accumulated depreciation → operating add-back · **222 = provisions → operating add-back** · 22 + equity → financing · else operating) and sum to the actual cash movement (`reconciled` = a double-entry integrity guard). Closing entries excluded. |

Each runs **per property** (filter `asset_id`) or **consolidated** (no filter), over a
date range. All four export to bilingual, RTL-aware **PDF**.

---

## 9. Gotchas & edge cases (محاذير)

- **Posting to a summary account** — refused; only `is_postable` leaves accept lines.
- **Backdated entry into a closed period** — refused; reopen the period or post into the
  current one.
- **Editing a posted entry** — not allowed; void (reverse) and re-post.
- **A voided entry stays on the books.** Reports count both `posted` AND `void` entries:
  a voided original is offset by its (posted) reversing entry, so the two net to zero.
  Dropping the original from reports would leave the reversal as a phantom balance —
  never hard-delete a posted entry. `void()` only acts on a `posted` entry (a draft can't
  be voided).
- **Reversal period** — `void()` posts the reversal into the original entry's period if
  it is still open (keeps that period self-consistent), else into the current open period;
  if neither is open the void is refused rather than silently shifting the books.
- **Report scoping** — the trial balance / general ledger are scoped to the current user's
  visible properties via `TenantScope::reportAssetIds()`. A property-restricted user cannot
  read another property's books by tampering the picker; "consolidated" for them means
  across their assigned set only. Pass `null` (all) only for portfolio-wide users.
- **NOT-NULL amounts** — `journal_lines.debit`/`credit` are NOT-NULL (default 0); the model
  coerces a blank/cleared field to 0 (the `meter_readings.cost` bug class).
- **Rounding** — validate balance on 2dp-rounded sums; a 1-cent rounding line may be
  needed on machine-generated entries (handled in the journalizers, Phase 1).
- **A new account's CODE decides its cash-flow section.** `cashFlow()` classifies by code
  prefix, not by any per-account flag, so numbering an account into the wrong range
  silently mis-states the statement — and `reconciled` will still be **true**, because it
  only asserts the double-entry identity (the three sections summing to ΔCash), never that
  a given account landed in the right section. The two carve-outs exist for exactly this:
  accumulated depreciation (`122…`) and provisions (`222…`) are non-cash, so both are
  operating add-backs rather than, respectively, an investing flow and a financing inflow.
  Number new non-cash accruals under `222…`; real funding movement (loans) stays at `221…`.
- **Deleting a chart account that has postings** — blocked (FK `restrictOnDelete`); mark
  it inactive instead.
- **Changing a mapping mid-life** — only affects *future* postings; historical entries
  keep their original accounts (correct — never rewrite history).
- **Phase-1 note (re-post after void):** posting is idempotent per source only while a
  `draft`/`posted` entry exists; a *voided* source-entry lets the same source post again
  (intended "correct and re-post" flow). When wiring auto-posting, ensure a re-run after a
  void is the deliberate path, not an accidental double-book.

---

## 10. Tests & related modules

Tests (`tests/Feature/`):
- `Accounting/ChartOfAccountsConformanceTest` — the gate on the starter chart: every
  non-root account parents to a real prefix of its own code (catches an orphaning typo),
  types match leading digits and `normal_balance` follows `type`, no summary account is
  postable, reseeding is idempotent, and **every** `AccountMappingSeeder` role resolves to
  a postable+active account (catches the silent-skip → runtime-failure class).
- `Services/JournalPostingServiceTest` — balanced enforced, unbalanced rejected, closed
  period rejected, non-postable account rejected, one-sided lines, negatives rejected,
  number generation, idempotent per source, `void` creates a balanced reversal, and
  `postDraft` posts/rejects a saved draft (balance is enforced at **post**, not at
  draft-save — a draft may be unbalanced while being built).
- `Scenarios/GeneralLedgerScenarioTest` — trial balance ties out across entries; per-
  property scoping vs consolidated; account ledger running balance.
- `Models/LedgerAccountTest` — tree, `normal_balance` derivation, `postable`/`active` scopes.
- `Resources/JournalEntryResourceTest` — the accounting screens render; full UI flow of
  creating a draft entry and posting it.

**Related modules:** 05 Billing, 06 Payments, 07 Credit Notes, 08 CAM, 13 Marketing
(levy → revenue via the invoice journalizer; **spend → expense** via `MarketingSpendJournalizer`),
12 Vendors (Phase 3 AP), 17 Reports, 18 RBAC, 12-reconciliation-harness.

---

## Roadmap (خارطة الطريق)

| Phase | Arabic | Scope |
|-------|--------|-------|
| **0 — Foundation** ✅ this doc | الأساس | Chart of accounts, fiscal years/periods, journal entries, posting service, account mappings, manual-entry UI, trial balance + general ledger, RBAC, bilingual labels, tests |
| **1 — Auto-posting** ✅ | الترحيل الآلي | **1a:** journalizer engine (`Journalizer` contract + `LedgerPoster` registry) + Invoice/Payment/CreditNote journalizers. **1b:** `LedgerPoster::sync()` reconciling upsert + `accounting:sync-ledger` command (one-time `--all` backfill + scheduled recent-window sweep) + GL↔AR tie-out report. Invoice journalizer covers CAM-recovery + late-fee items automatically; ties out exactly on the demo books. |
| **2 — Financial statements** ✅ | القوائم المالية | **Income Statement (قائمة الدخل)** + **Balance Sheet (قائمة المركز المالي)** pages, per-property & consolidated. `LedgerReportService::incomeStatement()` (revenue − expense = net profit; contra-revenue nets correctly) and `balanceSheet()` (Assets ≡ Liabilities + Equity + net income, since the trial balance always balances). Gated by `general_ledger.view`. **PDF export** (`LedgerReportPdfService`, mpdf, bilingual + RTL): a Download-PDF action on the Trial Balance, Income Statement, and Balance Sheet pages renders the current (year + property) view for owners/auditors. |
| **3 — Expenses & payables** 🟡 | المصروفات والموردون | **Accounts Payable done:** `VendorBill` (فاتورة مورد) + `VendorBillPayment` with a draft→approved→paid lifecycle; journalizers post Dr expense (by category) + Dr **VAT Recoverable** (input VAT) / Cr Payables, and payments Dr Payables / Cr Bank; swept + backfilled by `accounting:sync-ledger` with a GL↔AP tie-out; `VendorBillResource` under Accounting, gated by `vendor_bills.*`. **Direct expenses done:** `Expense` (مصروف مباشر / petty cash) posts Dr expense (by category) + Dr VAT Recoverable / Cr cash|bank immediately (no payable stage); `ExpenseResource` gated by `expenses.*`. **Payroll done:** `Payroll` (مسير رواتب, batch per-run totals — not per-employee payslips) posts Dr Salaries Expense (gross) / Cr Salary Tax Payable + Cr Social Insurance Payable (withheld) + Cr Bank\|Cash (net); draft→approved→cancelled; `PayrollResource` gated by `payrolls.*`. All swept by `accounting:sync-ledger`. Per-employee payslips + employer-side social-insurance contribution are a future HR extension. |
| **4 — Close & compliance** 🟡 | الإقفال والامتثال | **Period close done:** `PeriodService` closes/reopens accounting periods + fiscal years (a closed period refuses postings); `YearEndCloseService` posts the year-end closing entry (قيد الإقفال) that zeros revenue/expense into retained earnings (idempotent, reopenable). Closing entries are flagged `is_closing` → excluded from the income statement (shows actual P&L) but included in the trial balance + balance sheet (P&L reads zero post-close; profit sits in equity). `AccountingPeriodResource` gated by `accounting_periods.*`. **Still to do:** optional ETA/EAS statutory report formatting. |

---

## Auto-posting mechanism & current deferrals

**How posting fires (Phase 1):** rather than entangle real-time hooks with the
delicate `recomputeTotals`/`saveQuietly` money machinery, posting is a **reconciling
sweep**. `LedgerPoster::sync($document)` makes the ledger match a document's current
state (post / re-derive / void), and is idempotent + self-healing. It runs via:
- **`php artisan accounting:sync-ledger --all`** — historical backfill; also **scheduled
  weekly (Friday 03:00)** as a defense-in-depth backstop that self-heals anything the
  daily window missed (GL integrity hardening, Phase 0).
- **`accounting:sync-ledger`** (scheduled daily 05:00) — recent-window sweep that keeps
  the books current. Late fees and CAM-recovery invoices are picked up automatically
  (the invoice journalizer re-derives from the invoice's items / header).
- **"Post to GL now" button** (UI, on-demand) — a header action on the Journal Entries
  list + Trial Balance pages (`App\Filament\Admin\Concerns\PostsToLedger`) runs the
  windowed sweep on demand so a non-technical accountant never touches the CLI; gated by
  `journal_entries.post`. Each run stamps `ledger_last_synced_at` (via
  `SystemSetting::put` in the command), which those pages read (`SystemSetting::get`) and
  show as a **"Ledger last synced …"** subheading so the freshness of the books is always
  visible.

**Robustness (from review):** `sync()` is lock-safe (runs in a transaction, locks the
source row + existing entry) so a manual `--all` backfill and the scheduled sweep can't
double-post; it also re-derives when an invoice is re-pointed to another property
(`asset_id` is part of the match identity). A **payment spanning invoices across
properties** credits each property's receivables on its own asset (the books stay correct
per-property). Credit-note `sales_returns` is derived as `total − VAT` so the entry always
balances even if a stored subtotal drifts. The scheduled run is best-effort (a single
un-postable legacy doc is logged, not a red nightly task); an operator `--all`/`--since`
run exits non-zero on failures.

**Windowed-sweep input-touch guarantees (Phase 0 hardening, 2026-07-10):** the daily run
discovers work by each source's own `updated_at`, so a money-affecting edit that doesn't
bump the swept row would strand its entry until the weekly `--all`. These paths now bump
the right row so the *daily* sweep re-derives them:
- **`InvoiceItem` `$touches = ['invoice']`** — a money-neutral line-item change (e.g.
  re-typing `base_rent`→`service_charge`, which remaps the revenue account without moving
  the total) bumps the invoice so the sweep re-splits revenue (F3).
- **`VendorBill` full child cascade** — soft-delete / restore / re-home of a bill flows to
  its payments (mirrors `FixedAsset::ledgerChildRelations`): deleting a paid bill voids the
  bill entry **and** its payments' `Dr AP / Cr Cash` (so AP/cash aren't left understated);
  re-homing bumps their dimension (F9). **`VendorBillPayment` now soft-deletes** so a
  deleted payment self-heals to a voided entry instead of orphaning (F7).
- **`Warehouse` re-home cascade only** — changing a warehouse's `asset_id` bumps its stock
  movements' dimension. Deliberately **no** delete cascade: a movement is a completed
  historical fact and `StockMovement::warehouse()` uses `withTrashed()` so its GL survives
  the warehouse's soft-delete (F9).
- **Payment reallocation** — `EditPayment` touches the payment after re-syncing its
  allocations, so a reallocation that leaves the payment's own columns unchanged still
  re-derives its AR/per-asset split (F8).
- **Weekly `--all --scheduled`** — the automated full backstop uses `--scheduled` so it
  stays best-effort (exit 0) and a legacy un-postable doc can't turn the cron red; a human
  operator's `--all` still exits non-zero to surface failures loudly.

**Document immutability — finalized AR documents are locked (Phase 1 hardening):** a posted
AR/GL document must not be silently rewritten — corrections go through a void / re-issue or a
credit note. The admin forms lock the money-affecting fields once a document is finalized,
matching the existing VendorBill/Expense `$locked` convention (UI `->disabled()`):
- **Invoice** — once past `draft` (invoices are born `issued`), the `items` repeater and the
  GL-identity selects (`lease`, `tenant`, `issue_date`) are disabled. `status`, `due_date`,
  `notes` stay open.
- **Payment** — once it exists, `amount`, `payment_date`, `method`, `tenant` are disabled; the
  **allocations repeater stays open** (re-allocating a receipt across invoices is legitimate)
  and `status` stays open (initiated→captured, captured→failed).
- **Credit note** — once past `draft`, the items + `tenant`/`invoice`/`lease`/`issue_date` are
  disabled.
- **Lock-bypass guard:** `status` stays editable for legitimate transitions, but reverting a
  finalized invoice/credit-note to `draft` (which would re-open the locked fields) is refused
  at **both** layers — `draft` is dropped from the status options, and an `updating` model
  guard throws on a non-draft→draft transition (closing the JS-tamper / API / tinker path).
- **Model-layer field guards (defense-in-depth):** beyond the form lock, `updating` guards throw
  if the truly-immutable fields change on an already-finalized record — invoice `issue_date`/
  `tenant_id`/`lease_id`, captured-payment `amount`/`payment_date`, credit-note `issue_date`/
  `tenant_id`/`invoice_id`/`lease_id`. Keyed on the *original* status so the transition *into*
  finalized isn't blocked. `subtotal`/`total`/`items` are intentionally left to the UI lock only
  (LateFeeService/CAM rewrite them on issued invoices via `saveQuietly`).
- **Intentionally not locked:** MarketingSpend (edits fully reconcile via its budget + GL
  cascade — locking would remove a valid correction) and FixedAsset (terminal immutability is
  already enforced by `EditFixedAsset`'s `abort_unless(active)` + hidden edit action for
  disposed). Also left open as metadata: invoice `period_start`/`period_end`/`due_date`,
  payment gateway/cheque fields, credit-note `reason` (none change a booked amount).

**Known limitation — cross-property payments in per-property reports:** reports scope by
the *entry's* `asset_id`. A single payment that settles invoices across two properties is
booked as a consolidated entry (`asset_id` = null) with per-asset receivable lines, so it
shows correctly in **consolidated** reports but is **excluded from each property's**
per-property report (that property's receivables read high until the next consolidated
view). Splitting one cash receipt cleanly across properties needs inter-property
due-to/due-from accounts — deferred to Phase 3. Single-property payments (the norm) are
unaffected.

**Close workflow (order matters):** "Year-end close" posts the closing entry *while the
periods are open*, then locks the year's periods — "Reopen year" unlocks the periods
*first*, then voids the closing entry (so the reversal posts back inside the same year).
Both are bundled in the Fiscal Periods actions so the order can't be got wrong. Close
fiscal years **in sequence** (2025 before 2026): the balance sheet rolls only closed
years' profit into retained earnings, so an out-of-order close leaves a prior year's P&L
as an un-rolled `net_income` residual.

**Deleting a posted document — handled by the sweep:** both **cancelling** and
**soft-deleting** a posted document (invoice / vendor bill / expense / …) are now safe
reversals. `LedgerPoster::sync()` treats a **trashed** source as having no ledger effect
and **voids** its entry, exactly like a cancelled document; the sweep visits trashed rows
(`withTrashed()`), and because a soft-delete bumps `updated_at`, the windowed scheduled
run catches freshly-deleted docs (and `--all` self-heals any older orphans). So a deleted
posted document's entry is voided on the next sweep — the GL no longer overstates AR/
revenue. (Immediacy note: voiding happens on the next sweep, not the instant of deletion —
consistent with the whole sweep-based design. `VendorBillPayment` has no soft-delete, so
only its hard-delete would orphan an entry — not a normal path.)

**Open-period caveat (same rule as cancel):** the void is a real reversing entry, so it
needs an open period — it posts into the original entry's period if still open, else into
today's open period. If a document from a **closed** period is deleted **and** the current
period is also closed, the void cannot post: the sweep logs it as a failure and the entry
stays until a period is reopened (`accounting:sync-ledger --all` then exits non-zero so it
isn't missed). In normal operation the current period is open (the sweep opens the current
fiscal year first), so a closed-period delete reverses cleanly into today. To reverse
inside closed books specifically, reopen the period first — same as the close workflow above.

**Security deposits (تأمينات) — done:** `DepositTransaction` records receipt / refund /
forfeit against a lease, each posting its own entry — receipt Dr Bank\|Cash / Cr Deposits
Held; refund Dr Deposits Held / Cr Bank\|Cash; forfeit Dr Deposits Held / Cr Misc Income.
Swept by `accounting:sync-ledger`; `DepositTransactionResource` gated by
`deposit_transactions.*`. The GL Deposits-Held balance = Σ receipts − Σ refunds − forfeits.

**Inventory stock movements (حركات المخزون) — done (Phase 3, module 22):**
`StockMovement` posts via `InventoryMovementJournalizer` (registered in
`LedgerPoster`, swept by `accounting:sync-ledger`) — perpetual inventory: receipt
Dr `inventory` (11301001) / Cr `accounts_payable`; consumption Dr
`maintenance_expense` / Cr `inventory`; adjustment moves value between `inventory`
and `inventory_adjustment` (51108001) per sign; transfers post nothing (intra-company
location move). Value = |quantity| × unit_cost, dimensioned to the warehouse's
`asset_id`. Soft-delete voids the entry. **Caveat:** a receipt credits Accounts
Payable, so record the goods receipt OR a vendor bill for those goods — not both
(receipt↔vendor-bill linking, which would clear the payable, is a future enhancement).

**Marketing spend (مصروف تسويق) — done (2026-07-03):** `MarketingSpend` posts its own
entry via `MarketingSpendJournalizer` — **Dr Marketing Expense (`51105001`) / Cr Cash|Bank**
(per the new `marketing_spends.paid_from`), scoped to the budget's `asset_id`. Swept by
`accounting:sync-ledger` (registered in `LedgerPoster`, added to the command's model list +
fiscal-year span); a soft-deleted spend voids its entry like any other document. This books
the **spend side** of the marketing fund — the levy is already recognised as revenue on the
tenant invoice (`marketing_revenue`), so the fund's net P&L position is now complete. No VAT
split yet (the spend carries a single gross amount; see [module 13](13-marketing.md) § 7).

**CAM recovery revenue (إيرادات استرداد المصروفات المشتركة) — done:** the positive
true-up recovery invoice item is typed `cam_recovery` (in `CamReconciliationService`), and
`InvoiceJournalizer` maps that type to the `cam_recovery_revenue` role (account 41103001)
— so year-end CAM recoveries now show on their own Income-Statement line instead of
`misc_income`. `cam_recovery` is registered in `App\Enums\InvoiceItemType` (the
single-source-of-truth for item types → validation, Filament options, translation keys).
Forward-only: pre-existing recovery items keep `type='other'` (the `accounting:sync-ledger`
backfill re-derives from the *stored* item type, so it leaves them in `misc_income`); a
fresh reseed regenerates them with the new type, and reclassifying already-billed
historical recoveries would be a separate one-off data step. The linked `Charge` stays
`type='other'` (a non-billed, non-journalized traceability anchor).

**GL↔AR/AP tie-out in the reconcile harness — done:** `BooksReconciliationService`
now includes a `gl_tie_out` check (surfaced by `billing:reconcile`, which gates a
monthly close / tax filing with a non-zero exit) asserting the GL's AR/AP control
accounts equal the source-derived receivables/payables. The computation lives in one
place — `BooksReconciliationService::glTieOut()` — and is reused by the
`accounting:sync-ledger` printout, so the two can never disagree. The check is all-time
(GL balances are cumulative, so it's skipped for a `--month` run) and self-skips when the
GL isn't configured/populated.

**Near-real-time posting (Phase 2 hardening) — done:** in addition to the daily sweep and
the on-demand button, every posting source now dispatches a queued `SyncDocumentToLedger`
job **after commit** on save/delete/restore (`App\Support\LedgerRealtimeSync`, wired in
`AppServiceProvider::boot`, gated by `config('accounting.realtime_ledger_sync')`, default
on). The job re-runs the idempotent `LedgerPoster::sync`, so it can't double-book and the
sweep + weekly `--all` still backstop it. It fires only on real model events, not on
`saveQuietly` — so a payment's `recomputeTotals` (which only re-derives the invoice's
GL-neutral `paid_amount`/`balance`) dispatches nothing. The one `saveQuietly` path that
*does* change the GL is a **late fee** (`LateFeeService` adds a `late_fee` item + bumps the
total quietly): it deliberately has **no** real-time posting and waits for the daily sweep —
acceptable because late fees are a scheduled batch, not a time-critical interactive change.
For the interactive paths (issue invoice, capture payment, record expense, …) a document's
entry is fresh within seconds of the change, not up to a day. The freshness button + "Ledger last synced" subheading
now appear on **all** statement pages (Trial Balance, Journal Entries, Income Statement,
Balance Sheet, General Ledger). Disabled under the test suite (`sync` queue would race the
deterministic sync/sweep the tests drive).

**Sweep-failure alerting (Phase 3 hardening) — done:** an un-postable document must never be
silent. `SyncLedgerCommand` stamps `ledger_last_sync_failures` and, when that count newly
appears or changes, sends a `LedgerSyncFailedNotification` (bell) to the GL managers
(`permission('journal_entries.post')`) — de-duped so a persistent failure alerts once, not
every night. The count also shows on **every** report page's "Ledger last synced" subheading
(⚠ N documents could not be posted — reopen the period). A **windowed** run never *clears*
the count (it lacks the scope to re-verify an old stranded doc — the trap doc is outside the
2-day window); only a full `--all` run (manual, or the weekly backstop) resets it to 0, so the
warning persists until the doc is actually posted (fails safe: over-warn, never false-clear).
The scheduled run stays best-effort
exit-0 (the cron isn't perpetually red), but the **closed-period reversal trap** — a document
whose entry can't be voided/re-posted because its period (and the current period) is closed —
is now loud instead of a silent log line. Note: closed-period *edits* are already blocked by
the finalized-document locks, and a closed-period *delete* intentionally reverses into the
current open period (above); *preventing* the trap (don't close a period with pending re-syncs)
is the Phase-4 close gate.

**Close gate + deep tie-out (Phase 4 hardening) — done:** `PeriodService::closePeriod` /
`closeFiscalYear` refuse to close while any document affecting the period(s) is out of sync — a
read-only dry-run (`LedgerPoster::wouldChange`) over **both** (a) documents whose posted entry
lives in the period (a pending re-post/void) **and** (b) documents *dated* in the period that
aren't posted there yet (a fresh post that would land in it). Case (b) closes an adversarial-QA
gap: a never-posted document (real-time off / queue backlogged / a best-effort sync job that
failed once) has no entry for (a) to see, so without it the close would succeed and then strand
that post forever (posting into a closed period throws). The date columns are
`LedgerRealtimeSync::SOURCE_DATE_COLUMNS`; only the genuinely-unposted remainder reaches
`wouldChange`, so the scan stays cheap. Together this **prevents the closed-period trap being
created**: you can't close a period that still has a pending or never-posted document.
The message tells the accountant to "Post to GL now" first; the year-end action gates *before*
posting the closing entry so a refusal leaves no orphan. Separately, **`billing:reconcile --deep`**
adds a `gl_in_sync` check that dry-runs `wouldChange` over **every** posting document — catching
drift the AR/AP control-account tie-out can't see (a mis-typed revenue split, wrong VAT, a stale
cash/inventory/deposit/payroll posting). It's opt-in (re-derives every doc — can take minutes on a
large database) for pre-filing audits; the default `billing:reconcile` stays fast.

## Bilingual glossary (مصطلحات)

| English | Arabic |
|---------|--------|
| Chart of Accounts | دليل الحسابات |
| General Ledger | دفتر الأستاذ العام |
| Journal / Journal Entry | دفتر اليومية / قيد يومية |
| Debit / Credit | مدين / دائن |
| Trial Balance | ميزان المراجعة |
| Income Statement (P&L) | قائمة الدخل |
| Balance Sheet | قائمة المركز المالي / الميزانية العمومية |
| Accounts Receivable | المدينون / ذمم مدينة |
| Accounts Payable | الموردون / الدائنون |
| VAT Payable | ضريبة القيمة المضافة المستحقة |
| Deposits Held | تأمينات محتجزة |
| Fiscal Year / Period | السنة المالية / الفترة المحاسبية |
| Posting | الترحيل |
| Reversing entry | قيد عكسي |
| Closing entries | قيود الإقفال |
| Opening balances | أرصدة افتتاحية |
</content>
</invoke>

## Posting-date gate (2026-07-29)

`LedgerPoster::JOURNALIZERS` says *what* posts; `LedgerRealtimeSync::SOURCE_DATE_COLUMNS` says *what
date* each entry carries. **`App\Support\PostingDateGuards::GUARDS` says who refuses that date when
its period is closed** — and `PostingDateGuardConformanceTest` holds all three in step, so a new
money source cannot ship without the question being answered.

It exists because the answer was got wrong six times running — custody settlement and advance
repayment (F-93/F-89), vendor bills, stock movements, procurement, PDC — each fixed as if it were
that module's own bug, and each time the next module shipped with the same hole. The 2026-07-29
sweep found five more: fixed-asset disposal, acquisition and depreciation, plus the **grant** halves
of custody and advances that the very first fix had walked straight past. Then the registry itself
found four more that no sweep had reached: expenses, marketing spend, deposits, and payment *edits*
(payments were guarded on the admin create page only, so the edit form, the portal, the mobile API
and the console all sailed through).

Each entry is either a **guard class** or a **`system:` reason** stating why the date can never be
operator-typed. The gate checks the declared class actually consults `PostingDate`, that a
model-level guard names the same column the ledger dates from, and that no `system:` exemption is
contradicted by a form offering a DatePicker for that column — the way such an exemption rots.

Guard in the **service** where one exists (the refusal lands before any related work begins). Where
a source has no create/update service — its Filament resource writes the model — the model's own
save is the single choke point every path shares, and it uses `App\Models\Concerns\GuardsPostingDate`
(dirty-only: the rule is "nobody MOVES an entry into a sealed period", not "old records are
read-only").
