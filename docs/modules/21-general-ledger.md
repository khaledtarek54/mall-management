# Module 21 — General Ledger & Accounting Core (المحاسبة العامة / دفتر الأستاذ العام)


> **⚠️ Fixed 2026-08-11 — four services summed money without counting voided entries.**
>
> `JournalPostingService::void()` does not erase an entry: it posts a sign-flipped **reversal**
> (status `posted`) and marks the original `void`, leaving the original's lines in `journal_lines`,
> dated in their original period. That is deliberate — an auditor must see both the mistake and the
> correction — and it means **the pair nets to zero only if both are counted**.
>
> `LedgerReportService` knew this and held a *private* `['posted','void']`. Four other services
> summed journal lines with their own `where('status','posted')`, so each computed
> `(new − original)` on every correction and went **negative** on every plain cancellation. This is
> not an edge case: `LedgerPoster::sync()` calls `void()` on every re-derive, which is the normal
> operating mode of a derived ledger.
>
> | Consumer | What it broke |
> |---|---|
> | `SyncCamPoolFromLedgerService` ×2 | **The CAM recovery basis tenants are billed off.** A cancelled 100,000 bill drove `total_actual_expense` to −100,000; the annual true-up would have issued every tenant in the pool a credit note for a share of money nobody over-collected. Its docblock even asserted the wrong rule — *"a voided one never was"*. |
> | `ReconcileBankStatementService` ×2 | The bank rec's "ledger balance" read 250,000 below the trial balance for the same account, and the accountant hunts a variance that does not exist. |
> | `VatReturnService` | Input VAT read 0 instead of 14,000 for a corrected vendor bill — the operator overpays the tax authority. |
>
> The rule now lives on **`JournalEntry::REPORTABLE_STATUSES`**, because a rule four callers need
> cannot be private to one of them. `VoidedEntriesStayReportableTest` pins it, and its first case is
> the one the sweep said would have caught all four sites at once: **two independent readings of the
> same account must agree.** Mutation-checked — reverting the constant to `['posted']` reproduces
> the −100,000 and the 250,000 gap exactly.
>
> **Not every `posted`-only filter is wrong**, and the surviving ones are now annotated as
> decisions: `LedgerPoster` asking *which entry is currently live for this source* wants `posted`
> alone, and so does the bank-match candidate picker — that is a **selection**, not a sum. The test
> is whether you are summing money.

## A posted entry is immutable — enforced at the model (2026-08-11)

`JournalPostingService` validates an entry when it **posts** it: every line carries a debit or a
credit, the total is non-zero, and debits equal credits to the half-piastre. **Nothing re-validated
afterwards**, and "a posted entry is permanent" was enforced by

```php
EditJournalEntry::getSaveFormAction()->visible(fn () => $this->record->status === 'draft')
```

— a hidden Save button. `JournalEntry::booted()` carried only a `creating` hook; `JournalLine`
only the NOT-NULL coercion. So the ledger protected itself at layer 3, weakly, while every module
posting into it was being given layer-1 guards by the same close-out.

The consequences do not stay inside one document, which is what makes this the worst of the batch:

- **an unbalanced entry** — re-price a line, add one or remove one and debits stop equalling
  credits. The trial balance stops balancing and the balance sheet, the P&L and the owner
  statements are all wrong at once, with nothing naming which entry did it;
- **a restated closed period** — `entry_date` decides the period, so moving it walks the amount
  into another month, including one already closed, reported and shown to an owner. That is the
  divergence `App\Support\PostingDate` exists to stop, arriving from *inside* the ledger;
- **money moved with no trail** — changing a line's `ledger_account_id` re-homes an amount between
  accounts, leaving both wrong and nothing recording it.

Now: `JournalEntry::FROZEN_ONCE_POSTED` (date, property, source link, number, period, reversal link)
refuses once the ORIGINAL status is `posted` or `void`, and status may only ever move `posted → void`
— never back to draft. `JournalLine::saving`/`deleting` refuse on any non-draft entry.

**The posting engine is the one exception, and says so.** `post()` inserts the entry already
`posted` and then writes its lines, so it wraps them in `JournalLine::withinPostingEngine()` — a
deliberately narrow, greppable escape with exactly one caller. Relaxing the rule to "created lines
are fine" instead would have left *add a line to a posted entry* — the whole unbalanced-ledger
case — wide open.

**Nothing is trapped by this.** `void()` posts a balanced reversing entry (قيد عكسي) and you post a
fresh one; that is the correction this module already documents. Drafts stay fully editable — a
draft is not on the books, which is the entire distinction the status carries.

Tests: `PostedJournalEntryIsImmutableTest`. Two fixtures were rebuilt in the order the product uses
(lines while draft, then post) rather than hand-crafting a state production cannot reach.


> **Status:** Phases 0–4 shipped (foundation, auto-posting, financial statements,
> expenses/payables/payroll, close) + security deposits. Remaining follow-ups are in
> the roadmap at the bottom. Read [docs/OVERVIEW.md](../OVERVIEW.md) and
> [the module index](README.md) first — this module sits *underneath* every
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

**The width is not fixed — 8 digits is the starter chart, not a limit.** `code` is a varchar;
the parent is derived by PREFIX, the type from the LEADING digit, and the cash-flow statement
classifies with `str_starts_with` rather than numeric ranges. A 10- or 12-digit chart therefore
drops in with **no migration** — pinned by `ChartSupportsWiderCodesTest`, which exists so a
later `code >= 21000000` style comparison cannot quietly re-introduce a width assumption and
drop every wide account out of its cash-flow section.

**Widening the code does not buy more capacity, though.** What bounds the chart is the
hierarchy, and property/tenant/lease are **dimensions on the journal line** (`asset_id`,
`tenant_id`, `lease_id`) — never encoded into the account number. That is the same separation
Yardi draws with its account/property/department segments. Encoding a property into the code
instead would fragment every report that consolidates across the portfolio, which is the
failure mode a wide monolithic code invites. Adopt whatever width the operator's real chart
uses; do not renumber to create room.

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

**The vocabulary is a registry.** `App\Support\PostingRoles` lists all 48 roles with the statement
class each is meant for. It exists because `key` is a plain string: a row spelled `rent_revenu` maps
nothing and *does not fail* — the resolver never asks for that spelling, so the real role is left
unmapped behind a row that looks saved. The registry makes the key a picker rather than a text box.
`PostingRolesRegistryTest` asserts the registry and the seeded defaults describe the same set, so a
role added to the seeder cannot ship unreachable.

**Uniqueness is enforced in the model, not the schema.** `unique(['key','asset_id'])` covers
per-property overrides and *cannot* cover the global defaults, because SQL treats every NULL as
distinct — two `('rent_revenue', NULL)` rows are legal. That was survivable while the only writer was
a seeder calling `firstOrCreate()`, and stopped being survivable when the screen below shipped. A
duplicate is worse than an error because nothing breaks: `AccountResolver` orders by id and takes the
older row, so the accountant re-points an account, sees their row saved, and every invoice keeps
posting to the old one. `AccountMapping` refuses it on `saving`.

**A global default cannot be deleted; an override can.** Nothing falls back behind a global, so
removing `accounts_receivable` would fail every invoice posting in the system — re-point it instead.
Removing an override is ordinary: the role falls back to the global, which is the point of having one.

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
| **Vendor bill** فاتورة مورد | the category's own account, else `*_expense` by the floor map (net) + `vat_recoverable` (input VAT) | `accounts_payable` (total) |
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

### Post month: where a document's entry lands (MF-05)

A document's GL date normally comes from its own date column
(`LedgerRealtimeSync::SOURCE_DATE_COLUMNS`). `posting_month_overrides` lets one document post to a
DIFFERENT month without changing its own date — for the bill that arrives after its month closed.
Written by `SetPostMonthService`, read by `App\Support\PostMonth`, applied in `LedgerPoster` where
every payload is built. **One override for all 24 sources**, not a column on each: a post month that
works on some documents and not others is worse than none.

Three things to keep right:

- **Apply it BEFORE the change-detection — in EVERY reader, not just the sweep.** The existing entry
  already carries the moved date; comparing it against the raw document date reports a drift that is
  not one. `sync()` got this right from the start and `wouldChange()` did not, so for two months any
  overridden document sat in a permanent standoff: the sweep correctly reported it *unchanged* while
  `billing:reconcile --deep` reported it *drifted*, and no run could ever clear it. That failed the
  reconciler, failed `books_tie_out` on `/health`, and paged the GL managers with
  `BooksDriftDetectedNotification` — **a permanent un-clearable alarm on the very mechanism built to
  make real drift visible**, which teaches an operator to ignore it. Both readers now derive the
  payload from one method, `LedgerPoster::effectivePayload()` (soft-delete + override), so they
  cannot reach different verdicts about the same document. Pinned by
  `PostMonthOverrideTest → it reports no drift to the reconciler once the override is applied`, whose
  control moves the override behind the service's back so the check is not merely hard-wired to
  false. *The general lesson is the one this project keeps paying for: when a guard is added, ask
  **where else** the same question is asked.*
- **A CLOSED target is still refused** — `PostingDate::assertOpen()`, the same guard as everywhere.
  This reaches an open month with an honest document date; it does not reopen a sealed period.
- **The day is clamped, not rolled.** 31 January → February is the 28th, never 2 March.

Because it moves the JOURNAL ENTRY rather than the document, every GL report and the monthly close
read the post month with no change of their own, and the tenant and the ETA payload still see the
document's real date.

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
5. **Declare its change policy in `App\Support\ChangeImpact::POLICY`** — for every fillable column,
   whether a change to it once the document is committed is REFUSED (immutable; correct via a new
   document), DERIVED (the entry is voided and re-posted), PROSPECTIVE (future documents only),
   DESCRIPTIVE (reaches the entry's description but never re-derives) or NEUTRAL. Because the ledger
   is *derived* here rather than posted-and-frozen, "may this edit move the books?" is a decision
   someone has to make, and `ChangeImpactConformanceTest` fails the build when a new source — or a new
   column on an existing one — ships without it. If you declare any REFUSED field you also add a
   committed fixture to that test, which *proves* the refusal fires rather than asserting a guard
   exists. See [the change-impact plan](../accounting/CHANGE-IMPACT-PLAN.md).
6. Add any new semantic roles to **`App\Support\PostingRoles`** *and* `AccountMappingSeeder`, and
   point them at chart accounts. Both, not either: the seeder gives the role a default, the registry
   makes it reachable on the Posting Map screen, and `PostingRolesRegistryTest` fails if you do one
   without the other. A new role also needs an `admin.posting_roles.*` label in EN **and** AR.

The general-ledger engine itself **never changes** when a module is added. New module →
new journalizer + new mappings. That is the "accounting works with any future module"
guarantee.

<a id="gl-registry-gate"></a>
> **⚠️ Never hand-copy the source list — and test through the real path.**
> Steps 2–4 are enforced by `tests/Feature/Scenarios/GlRegistryConformanceTest.php`: a
> journalizer without a date column fails CI, as does a date column for a model that can't
> post, or a sweep that stops deriving from the registry.
>
> This gate exists because the lists drifted and cost real money. `SlaPenalty`
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
- **`ChargeCodeResource`** — أكواد الرسوم, the billing vocabulary: which codes an invoice line may carry, what each is called in both languages, and the posting role it books to. Adding one used to mean editing a PHP enum **and** a private const map inside `InvoiceJournalizer`, then deploying. The `code` is immutable once saved (it is stored on every invoice line ever billed under it, so a rename orphans the history — the label is what you change), and a code the billing engine references by name can be neither deactivated nor deleted, because removing the row would not remove the behaviour. Resolution order at posting time is **catalogue → hard-coded map → `misc_income`**; the middle step is a floor for an un-seeded deployment, never a second opinion, and `ChargeCodeGlMappingConformanceTest` asserts the two agree code-for-code.
- **`AccountMappingResource`** — خريطة الترحيل, the posting map: which account each semantic role
  posts to. Tabs by statement class; the account picker lists **postable, active accounts only**
  (the resolver refuses anything else at posting time, which would otherwise surface as a failed
  entry long after the mapping was saved); the property picker is scoped via `TenantScope`, so an
  override cannot be aimed at a mall the operator cannot see. Changes are written to the activity
  log — *who re-pointed rent revenue, and when* is an audit question with real money behind it, and
  the entries themselves record only the account they used, never the decision that sent them there.
  **This is the handover point for a new chart of accounts:** re-pointing 48 roles is configuration
  an accountant does, not a migration. Until 2026-08-10 the table was seeded and thereafter
  unreachable — `AccountMappingSeeder`'s own docblock had promised since it was written that "the
  accountant can re-point any role from the UI without touching code", and there was no UI.
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
| `account_mappings` | view, create, edit, delete (delete removes a per-property override only) |
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

> **Every report runs for a MONTH or the whole year, and follows the real fiscal year (2026-08-12).**
> `ScopesLedgerReport` hardcoded `1 Jan – 31 Dec` and the balance sheet was always as-of 31 December,
> so an operator running a monthly close could not print that month's trial balance, income
> statement, balance sheet or cash flow. **The services already took ranges** — only the pages did
> not, which is why this was a filter and not a rebuild.
>
> - The `Period` picker offers *Full year* (the default, so nothing changes for an existing user)
>   plus each month of the selected fiscal year.
> - **`FiscalYear::starts_on`/`ends_on` are now honoured.** They existed and were ignored, so an
>   April→March mall year — ordinary in Egypt — reported the wrong twelve months, silently, because
>   the header only ever printed the year number. With no `FiscalYear` row the calendar year is
>   still assumed, which is what a fresh install looks like.
> - Months are labelled with their real calendar month AND year ("Mar 2027"), never an ordinal: on a
>   non-calendar year the twelfth month falls in the *next* calendar year.
> - The period reaches the **PDF header and the export filename**, not just the screen — a March
>   trial balance must not land on disk under a name that reads like the whole year's.
> - Changing the year clears the month. Livewire would otherwise keep it, leaving a report headed
>   2025 showing March 2026 — two pickers contradicting each other, which is worse than either being
>   wrong alone. Pinned by `MonthlyLedgerStatementsTest`.

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
| **2 — Financial statements** ✅ | القوائم المالية | **Income Statement (قائمة الدخل)** + **Balance Sheet (قائمة المركز المالي)** pages, per-property, each declaring what it LEAVES OUT (see *Unallocated entries* below). *(**Consolidated is computed but not reachable from the panel** — corrected 2026-08-22; this line claimed it for months. `TenantScope::reportAssetIds()` clamps every pick back to the selected mall, so the code path exists and no screen opens it. That is [EGYPT-MARKET-FIT.md](../EGYPT-MARKET-FIT.md) **EG-27**, not a documentation fix.)* `LedgerReportService::incomeStatement()` (revenue − expense = net profit; contra-revenue nets correctly) and `balanceSheet()` (Assets ≡ Liabilities + Equity + net income, since the trial balance always balances). Gated by `general_ledger.view`. **PDF export** (`LedgerReportPdfService`, mpdf, bilingual + RTL): a Download-PDF action on the Trial Balance, Income Statement, and Balance Sheet pages renders the current (year + property) view for owners/auditors. |
| **3 — Expenses & payables** 🟡 | المصروفات والموردون | **Accounts Payable done:** `VendorBill` (فاتورة مورد) + `VendorBillPayment` with a draft→approved→paid lifecycle; journalizers post Dr expense (by category) + Dr **VAT Recoverable** (input VAT) / Cr Payables, and payments Dr Payables / Cr Bank; swept + backfilled by `accounting:sync-ledger` with a GL↔AP tie-out; `VendorBillResource` under Accounting, gated by `vendor_bills.*`. **Direct expenses done:** `Expense` (مصروف مباشر / petty cash) posts Dr expense (by category) + Dr VAT Recoverable / Cr cash|bank immediately (no payable stage); `ExpenseResource` gated by `expenses.*`. **Payroll done:** `Payroll` (مسير رواتب, batch per-run totals — not per-employee payslips) posts Dr Salaries Expense (gross) / Cr Salary Tax Payable + Cr Social Insurance Payable (withheld) + Cr Bank\|Cash (net); draft→approved→cancelled; `PayrollResource` gated by `payrolls.*`. All swept by `accounting:sync-ledger`. Per-employee payslips + employer-side social-insurance contribution are a future HR extension. |
| **4 — Close & compliance** 🟡 | الإقفال والامتثال | **Period close done:** `PeriodService` closes/reopens accounting periods + fiscal years (a closed period refuses postings); `YearEndCloseService` posts the year-end closing entry (قيد الإقفال) that zeros revenue/expense into retained earnings (idempotent, reopenable). Closing entries are flagged `is_closing` → excluded from the income statement (shows actual P&L) but included in the trial balance + balance sheet (P&L reads zero post-close; profit sits in equity). `AccountingPeriodResource` gated by `accounting_periods.*`. **Still to do:** optional ETA/EAS statutory report formatting. |

### Loading the accountant's own chart (EG-28, 2026-08-22)

`LedgerAccountImporter` + an admin-gated `ImportAction` on the chart list. Columns: `code`,
`name_en`, `name_ar`, `type`, `cash_flow_section`, `is_postable`, `is_active`.

**Not columns, deliberately:** `parent_id` and `normal_balance`, both derived in
`LedgerAccount::saving`. A column for either would be a second, conflicting truth.

**Identity is the CODE** — the same key `ChartOfAccountsSeeder` uses, so a second pass corrects
rather than duplicates and an import over the shipped chart merges rather than twinning.

**Row order does not matter, and making that true fixed a latent bug.**
`resolveParentIdFromCode()` looks BACKWARD for an existing parent, which is complete only when
parents precede children — true of the seeder (sorted by code), false of a CSV. Filament streams
rows in file order with no after-import hook, so `11101` before `111` left the child parented to
null and the rollup silently lost a branch. `LedgerAccount::adoptOrphanedDescendants()` closes the
reverse direction on `saved`: it claims a descendant only when it is a **closer** ancestor than the
current parent (so `111` cannot steal `1110123` from `11101`), and re-parents by QUERY, because a
model save would re-enter the hook and on a real chart that recursion is the whole import.

The code/type convention is enforced in `resolveRecord()` rather than as a column rule — it is a
rule about two cells and `getColumns()` is static. The model throws for the same reason, but its
exception reaches the operator as a developer's sentence; this reaches them as the form's message.

### The cash-flow statement follows the ACCOUNT, not the code (EG-28, 2026-08-22)

`ledger_accounts.cash_flow_section` — `cash` · `operating` · `investing` · `financing` — resolved
through `App\Support\CashFlowSection`. It replaced **six literal `str_starts_with` checks on the
account code** (`111`, `222`, `22`, `122`, `12`), which were correct about the chart this project
ships and about no other.

**The failure mode was silent.** A different Egyptian chart numbered 1–5 by nature but with
different sub-ranges SAVES — the save-time guard only checks the leading digit — and then a capital
purchase lands in operating, a loan drawdown lands in operating, the statement still balances and
the figures are wrong. The operator's real chart is still pending, so this was waiting to happen.

**Nothing moves on existing installs.** The migration backfills every account using exactly the
rules the report used, in exactly the order it used them (`222` before `22`, `122` before `12`), and
`ChartOfAccountsSeeder` does the same for a fresh install. Prefixes survive in ONE place —
`CashFlowSection::forShippedChart()` — a statement about *our* chart used to backfill it, not a rule
about charts. The report no longer reads a code.

- **Revenue and expense cannot carry a section.** They net into `net_income` by TYPE, which is
  already chart-agnostic; a section on them would let an operator move revenue into investing and
  break the statement's own arithmetic. The form hides the field for them and a test asserts none
  is seeded with one.
- **The floor is OPERATING, not investing** (equity floors to financing). An unclassified account is
  far more often working capital than a capital asset, and being wrong toward operating leaves the
  net change in cash correct while being wrong toward investing misstates two subtotals.
- **Registered in `ValueSets`**, because a mistyped section does not error — it silently falls
  through to the operating default, which is the very class of bug this fixed.
- **The cash branch is tested BEFORE the zero-impact guard**: a cash account whose movement nets to
  zero over the period still contributes to the running cash figure.

### A statement is read by the chart's own subtotals (EG-28, 2026-08-22)

`App\Support\StatementGroups` — the second half of EG-28, and the one that closes it.

A statement listed every moving account flat under its type, so the balance sheet was forty-odd leaf
lines with one figure at the bottom, and the summary accounts the chart already models
(`is_postable = false`) appeared on **no statement at all**. `parent_id` was read by no report. The
distinctions an accountant reads a statement by — current versus non-current, operating revenue
versus other income versus sales returns — were all in the chart and on none of the pages. On the
demo books that meant 10,055,007 of *operating* revenue was never distinguished from 12,440 of other
income and −6,500 of sales returns; the reader saw one total of 10,060,947.

**The group is the highest ancestor BELOW the root**, read off `parent_id`.
`LedgerAccount::saving` does derive that parent from the code prefix, so the two agree here — but
reading the TREE means this works at any depth and any width without knowing where one level of the
numbering ends and the next begins, which is exactly the assumption the cash-flow statement had to
be freed of in the first half of this ticket. An account with no parent belongs to no group: it
either IS a root, or its code matched no shorter code and the chart never placed it. Both render
ungrouped, after the grouped rows and with no subtotal — they still print and still count toward the
section total, as does the balance sheet's synthetic *net income for the period* line, which has no
account at all.

**Three renderers, one helper.** The screen (`RendersFinancialStatement`), the CSV
(`ReportCsvExporter::sectioned()`) and the PDF (`accounting.pdf._statement-section`) each used to
build a statement their own way — the PDF blades carried **three copies** of one `$lines` closure —
and EG-36 had already shipped a screen out of step with its own export once. They now resolve
through one helper and one partial, so a statement, its CSV and its PDF cannot lay the same figures
out three different ways.

**A subtotal is only printed where it says something.** Two gates, the same rule at two levels:
`StatementGroups::worthShowing()` suppresses grouping for a section with a single group (its
subtotal would equal the section total), and each group's own `show_subtotal` is false for a
one-row group (the row already IS the subtotal — *"Share capital 500,000 / Total Capital 500,000"*
is four lines for two facts, and the row's own name says more than the heading repeating its
figure). A statement that prints the same number twice under two names reads as an error.

**The cash-flow statement opts OUT** (`CashFlow::groupStatements()` → false, `grouped: false` on
the CSV and the partial). Its sections are ACTIVITIES, not branches of the chart: operating
legitimately mixes revenue, receivables, payables and depreciation from five different roots, and
subtotalling those by root would print headings that say nothing about cash.

**The comparative income statement groups too.** Its rows carry a `code` and no `account_id` —
`ComparativeStatementService` compares two periods and never reads the chart — so `StatementGroups`
resolves either way, and one checkbox cannot leave the screen laid out differently from the
statement it is a comparison of. Its subtotals compare as well (`prior`, `change`, and a null
`change_pct` against a zero prior, the rule the column already formats by), and it passes
`amountKey: 'current'` because a comparative row carries two figures and neither is named the way a
plain statement names its one.

**Two live bugs found reviewing this, both on the comparative statement.** `line()` read
`$row['label']`, and **neither source emits that key** — `LedgerReportService::statementRow()` and
`BudgetService::asIncomeStatement()` both return `name_en` / `name_ar`. Every row rendered as a code
beside a **blank account name**, on all three bases, for the life of the screen; no test asserted the
label. The same method dropped `account_id`, so the comparative statement was the one reading of the
P&L whose figures could not be opened in the ledger — recorded in a comment as *"the comparative
service works in labels and codes, not account ids"*, which described the symptom as if it were the
design. Both fixed; both pinned in `ComparativeStatementTest`.

Tests: `StatementsGroupByChartHierarchyTest` (12 cases — the helper, all three renderers, and the
tie-out that subtotals foot back to the section total, because a grouping that silently dropped a row
would still render as a tidy statement) and two cases in `ComparativeStatementTest`.

### A narrative is a KEY, resolved when the entry is read (EG-36, 2026-08-22)

`journal_entries.description_key` + `description_data`, resolved by `App\Support\JournalNarrative`
— the ledger's twin of `ActivityVocabulary`, under the same rule: **a row stores DATA, never
PROSE**. All 24 journalizers post a key (25 narratives — the custody one branches).

Before this, each wrote Arabic and English literals at post time, so a wording fix needed a deploy,
never reached a row already posted, and a third language would have meant re-posting history.

**The prose columns stay and are still written**, as a snapshot and a floor:

- every row posted before today has prose and no key, and a ledger is evidence — history is never
  rewritten here;
- `search_text` folds the narrative, and a stored copy keeps a raw reader honest;
- **a read site nobody converted degrades to today's wording, not to a blank cell.** On a general
  ledger an empty description is indistinguishable from an entry nobody described.

`JournalNarrative::resolve()` prefers the key, so one edit to `admin.journal.narratives.*` reaches
every entry ever posted under it. Read sites: `JournalEntry::displayDescription()` (the model path),
the journal-entry CSV, and the GL page — which reads raw query rows, so `LedgerReportService`
selects the key and data alongside the prose and the page resolves per row.

**Nothing re-posts.** `matches()` compares lines, date and asset and deliberately not text
(`ChangeImpact` classifies these columns DESCRIPTIVE), so a key cannot void and re-post an entry.

Three traps, all previously recorded elsewhere in this codebase and all hit again here: `__()` reads
dots as **nesting**, so the narratives are nested rather than keyed by the literal `invoice.posted`;
a missing placeholder renders an **em dash** rather than a leftover `:number`, which on a financial
statement reads as a broken template; and `Lang::has()` **falls back to English**, so the parity
check passes `fallback: false` or it only catches a key missing from both languages.

### Unallocated entries — what a statement leaves out, and why it says so (EG-27, 2026-08-22)

Every statement scopes with `whereIn('je.asset_id', $ids)`, and **`whereIn` never matches NULL** —
so a journal entry filed against no property was invisible in the income statement, balance sheet,
cash flow, trial balance and general ledger alike, and nothing said so. The year-end close already
knew better: `plByAssetAndAccount()` buckets those rows under `asset_id => null` precisely *"so no
P&L is ever stranded"*. The close and the reports disagreed, and the reports are what an operator
signs.

**They are surfaced, not folded in — an operator decision, taken deliberately.** A null `asset_id`
on a money document is portfolio-level overhead visible from every mall
(`#[PropertyOwned(portfolioRowsWhenNull: true)]`), so absorbing it into each property's statement
would show one operator-wide insurance bill **in full on all three malls** and none of their figures
would be right. Instead `LedgerReportService::unallocated()` counts them and every statement page
renders a notice above the table naming the count, the amount and
`atriom:audit-property-dimension`, which is what finds and corrects them.

Three properties of the notice worth keeping:

- **Silent on clean books and on an unscoped read.** A warning that appeared on a healthy period
  would be trained away within a week; and an unscoped read has no `whereIn`, so the entries ARE in
  those figures and warning there would be false.
- **Sized by DEBITS.** An entry balances, so summing both sides doubles every figure — a notice
  reading 169,000 against 84,500 of real exposure is a worse number than no notice at all.
- **It lives on `ScopesLedgerReport`, not on five pages**, so a sixth statement inherits the warning
  rather than being the one that quietly omits money. The balance sheet overrides the window to
  "everything up to the date", because it is an *as at* statement and a month's worth would
  understate what it is missing.

**Consolidated statements remain unreachable** — that is the other half of EG-27 and it reopens the
"All-Properties mode removed" decision, so it stays open rather than being drifted into.

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
  **Plus `ledgerDerivedRelations()` — SLA penalties (added 2026-08-11, the third instance of
  this class).** The two lists answer different questions and conflating them is what hid the
  bug: `ledgerChildRelations()` is *rows that follow the parent into the bin* (so the cascade
  writes `deleted_at` and needs `withTrashed()`), while `ledgerDerivedRelations()` is *rows
  with their own lifecycle whose ENTRY the parent determines* (so they get a `touch`, nothing
  more). `SlaPenaltyJournalizer` reads the bill for its `asset_id`, its postability and
  its expense category — but a penalty records that a vendor missed an SLA, which stays true
  whether or not the bill survives, and `sla_penalties` has no `deleted_at` to cascade
  into. Adding it to the owned list is fatal (`HasMany::withTrashed()` does not exist).
  The derived touch fires on **`asset_id`, `status` OR `category`**, not `asset_id` alone —
  cancelling a bill must void the penalty deducted from it, and until the wider condition
  landed that entry stayed posted indefinitely. **Membership test: does the child's journalizer
  read the parent?** — not "does it have its own row".
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

**How void-on-delete relates to the deletion policy (2026-07-31).** Since
`App\Support\DeletionPolicy` shipped, an operator can no longer soft-delete any of these
sources — `Invoice`, `VendorBill`, `VendorBillPayment`, `StockMovement` and
`DepreciationEntry` are `NEVER_DELETABLE`, so the documented correction is cancel / void /
credit note, and the reversal is what moves the GL. Void-on-delete is therefore **not** dead
code, but it is no longer reached from an operator action. It still has to work for exactly
two inputs:

- **rows trashed before the refusal shipped**, which are still in the database and must still
  void on the next sweep;
- **cascades**, which are the live path — a bill's `deleted` hook stamps its payments, a fixed
  asset's stamps its depreciation charges, and the *windowed* sweep must find both.

Tests therefore arrange this state through `trashBypassingDeletionPolicy()`
(`tests/Pest.php`), which detaches only the model's `deleting` refusal and re-arms it after.
It deliberately does **not** use `withoutEvents()` — that would also mute `deleted`, the
cascade would never run, and every cascade-sweep assertion above would pass over nothing.
`DeletionPolicyTestHelperTest` pins both halves of that contract.

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

**An entry with no property is now visible (2026-08-12).** `journal_entries.asset_id` is nullable
and the journal form deliberately offers a blank property, labelled *consolidated* — an
operator-level entry belonging to no single mall. Refusing it would break that. What was missing is
what happens next: **every owner statement is generated per asset**
(`GenerateOwnerStatementRunService` scopes `where('asset_id', $asset->id)`), so a consolidated entry
appears in **no** statement while the portfolio-wide trial balance still balances to the penny.
Revenue posted that way understates the owner's statement and no report disagrees with any other, so
it cannot be found by noticing a discrepancy. `JournalEntry::withoutProperty()` is the one scope —
posted, no asset — read by both an **Action Required** card and the journal table's *No property
(consolidated)* filter, so the count and the list cannot drift apart. Drafts are excluded: they are
in nobody's books yet. Pinned by `LedgerEntriesWithoutPropertyAreVisibleTest`.

**GL↔AR/AP tie-out in the reconcile harness — done:** `BooksReconciliationService`
now includes a `gl_tie_out` check (surfaced by `billing:reconcile`, which gates a
monthly close / tax filing with a non-zero exit) asserting the GL's AR/AP control
accounts equal the source-derived receivables/payables. The computation lives in one
place — `BooksReconciliationService::glTieOut()` — and is reused by the
`accounting:sync-ledger` printout, so the two can never disagree. The check is all-time
(GL balances are cumulative, so it's skipped for a `--month` run) and self-skips when the
GL isn't configured/populated.

**…and the delta now reaches somebody (2026-08-12).** Until then the sweep computed the
tie-out and printed it with `warn()`. The sweep runs on cron, so that went to `/dev/null` —
the one number that says *the books no longer agree with themselves* reached no channel at
all: not the console, not the bell, not `/health`, not a stored value anyone could query.
The obvious objection — "there is already a ledger alert" — is precisely why it stayed
invisible: `LedgerSyncFailedNotification` fires from `recordAndAlertFailures()`, which
**returns early on `$failed === 0`**, and a ledger that drifts while posting every document
cleanly has zero failures *by definition*. Both persisted keys were about documents that
threw. So `SyncLedgerCommand::recordAndAlertDrift()` now:

| | |
|---|---|
| **persists** | `ledger_tie_out_ar_delta` · `ledger_tie_out_ap_delta` · `ledger_tie_out_checked_at` · `ledger_books_drifting` |
| **alerts** | `BooksDriftDetectedNotification` (mail + bell) to holders of `journal_entries.post`, **on the transition into drift only** — a nightly message repeating a known delta is a message people filter |
| **surfaces** | `/health` → `books_tie_out`, so an uptime monitor sees it without anyone opening `/admin` |

The same health check also reports a standing `ledger_last_sync_failures`. That count had the
identical gap one layer over: `recordAndAlertFailures()` de-dupes on a *change* in the number, so a
failure sitting at 3 for a month alerts once and afterwards exists only on `PostsToLedger`'s banner,
on report pages nobody has open. An un-postable document is the other way the books stop agreeing,
and it belongs on a surface something polls.

Two deliberate asymmetries against the failures counter next to it. It **clears on any
run**, including a windowed one: `glTieOut()` sums the whole ledger against the whole
sub-ledger, so even a two-day sweep computes a full-scope answer and there is no partial
view to false-clear from. And a **missing** stamp is reported healthy — that means the
sweep has not run, which the `scheduler` check already reports; failing here too would give
the operator two alarms for one cause and teach them to ignore this one.

**`billing:reconcile` is scheduled (2026-08-12).** The tie-out says the books disagree;
the deep re-derivation says *which document* disagrees. It existed, it worked, and it
appeared nowhere in `routes/console.php` — only an operator working through the month-end
checklist ever ran it. Now `billing:reconcile --deep`, Fridays 04:00, `withoutOverlapping`.
Pinned by `tests/Feature/Regression/BooksDriftIsVisibleTest.php`, which asserts against the
real `Schedule` rather than the file's text, and drives the alert through the command
itself — a test that constructed the notification by hand would have passed against the
old code, since the defect was that nothing ever called it.

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
date* each entry carries. **`App\Support\PostingDateGuards::guards()` says who refuses that date when
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

---

## Deletion policy

Operator decision 2026-07-31, following Yardi/MRI/Entrata: a record that carries history is
**refused**, not warned about — the damage lands on the reports and audit trail that referenced
it, none of which are in front of whoever clicks the button. The single register is
[`App\Support\DeletionPolicy`](../../app/Support/DeletionPolicy.php); `DeletionPolicyConformanceTest` fails the build if a model here ships unclassified or a Delete
button reappears on a money record.

| Model | Rule | Instead / why |
|---|---|---|
| `JournalEntry` | **Never deletable** | post a reversing entry; a posted entry is never removed |
| `JournalLine` | Deletable (super_admin) | parent-managed: rebuilt when its entry is re-posted |
| `LedgerAccount` | **Only while unreferenced** — blocked by `lines`, `children` | deactivate the account — removing one that has been posted to breaks every prior statement |
| `AccountingPeriod` | **Only while unreferenced** — blocked by `entries` | a period that has been posted to is part of the books; close it rather than remove it |

### Seeing it — the document ↔ ledger trail (2026-08-11)

Because the ledger is *derived*, a change to a posted document makes a queued job void its entry and
post a fresh one, described as "Superseded by an updated document", with nothing told to anyone. The
correction is real, correct, and was completely invisible.

- **On the document:** `App\Filament\Actions\LedgerEntryAction` — a factory like `PostMonthAction`,
  on invoices, payments, credit notes, vendor bills and expenses. State (posted / not posted yet /
  reversed / *does not reach the ledger*), entry number and date, post month when overridden, the
  property dimension, the debit/credit lines, **the reversal chain**, every entry the document has
  ever had, and a pending note when the document has drifted since posting. Read-only and gated on
  `general_ledger.view` — a leasing user sees their invoice without seeing which accounts it moved.
- **On the entry:** a **source column with a drill-through** back to the document, resolved via
  `Filament::getModelResource()` rather than a hand-kept model→resource map (one more list to drift);
  a reversing entry reads as "Reversal of JE-…" instead of showing a source it does not have.
- **The read model** is `App\Support\LedgerTrail`, side-effect free: `wouldChange()` is `sync()`'s dry
  run — no lock, no write — so rendering the panel can never move the books.

Tests: `tests/Feature/Regression/LedgerTrailVisibilityTest.php` (6), driving the real sweep — including
the late-fee case, where `saveQuietly` fires no model event so the entry is stale until the next sweep.

### Reported ≠ closed — the restatement warning (2026-08-11)

The close gate stops you *sealing* a month over a pending re-post. Nothing stopped the opposite:
**changing a month you have already reported.** An owner statement is finalised on the 5th and the
period closed on the 20th, and in those fifteen days an edit to a March document voids its entry and
posts a new one — restating a figure the owner is holding.

- **`App\Support\ReportedPeriod`** — a month is reported once a **finalised owner statement covers
  it**. Derived, not stored: no `reported_at` column to set, forget, or drift out of step with the
  statements themselves. Scoped per property, because a statement for one mall says nothing about
  another's March, and overlap-based, so a quarterly statement reports each of its months.
- **Three surfaces:** a danger note on the document's ledger panel (the books get corrected either
  way — what changes is that the owner's copy stops matching), a non-blocking **month-end step**
  (`reported_not_closed`), and a **notification to the GL managers** when a re-derive actually
  restates a reported month — raised in `LedgerPoster::sync()` after the commit, because that is the
  one place a re-derive happens and therefore the one place that can see it. Best-effort, like the
  sweep's own failure alert: a notification hiccup must never fail the sync and leave the ledger
  un-corrected.
- **It warns rather than refuses, deliberately.** Voyager has no "reported" state — its control is
  the post month, and the discipline is that you *close* the month when you report it. A
  reported-but-open month is a process gap, not a transaction to refuse, and refusing would be
  **stricter than the benchmark** while blocking the case where the correction is exactly what the
  owner is waiting for. So the step steers to the close, which is the control that already exists.

Tests: `tests/Feature/Regression/ReportedPeriodRestatementTest.php` (6), including the control that
an unreported month stays silent — an alert firing on every re-derive would pass the positive test
just as happily.

### Void coverage — every money document needs a way back

Refusing deletion is only half a policy: the correction path it names has to *exist*. Each posting
source reverses through one of three mechanisms, all of which end at the same place — the journalizer
stops returning a payload and the sweep posts a reversing entry:

| Mechanism | Sources | Reversal |
|---|---|---|
| **Explicit void/cancel action** | Invoice (`VoidInvoiceService`), Payment (`VoidPaymentService`), **VendorBillPayment (`VoidVendorBillPaymentService`)**, VendorBill / Expense / DepositTransaction (`cancel_*`), CreditNote (void) | status flip → no payload → entry voided |
| **Soft-delete** | the remaining sources | `sync()` visits trashed rows and voids their entry |
| **Parent lifecycle** | child sources (vendor-bill payments, stock movements, CAM allocations) | cascade from the parent's cancel |

> **The gap this closed (2026-08-11).** `VendorBillPayment` had *neither*: its DeletionPolicy row
> named "void the payment" with nothing implementing it, its relation manager was read-only, and the
> model is unconditionally committed so the soft-delete route was refused too. A cheque keyed against
> the wrong bill was permanent — the AP balance, the bank leg and the withholding-tax liability all
> wrong, and the bill uncancellable because `cancel()` refuses a bill with payments. The lesson
> generalises past this one row: **a `NEVER_DELETABLE` classification is only as good as the
> correction it names**, and nothing was checking that the named path existed. See
> [the change-impact plan](../accounting/CHANGE-IMPACT-PLAN.md) F1.

<!-- GENERATED:gl-sources — do not edit by hand; run `php artisan atriom:dump-registries` -->

## Every GL posting source

Generated from `LedgerPoster::JOURNALIZERS` — the single registry all four dispatch paths
derive from (real-time hook · `accounting:sync-ledger` sweep · close gate · `billing:reconcile`
drift check). **24 sources.** The `entry_date` column is what the sweep windows on, and what
the posting-date guard checks against a closed period.

| Source model | Journalizer | `entry_date` from | Posting-date guard |
|---|---|---|---|
| `Invoice` | `InvoiceJournalizer` | `issue_date` | on the model (`GuardsPostingDate`) |
| `Payment` | `PaymentJournalizer` | `payment_date` | on the model (`GuardsPostingDate`) |
| `CreditNote` | `CreditNoteJournalizer` | `issue_date` | `CreditNoteService` |
| `VendorBill` | `VendorBillJournalizer` | `bill_date` | `VendorBillService` |
| `VendorBillPayment` | `VendorBillPaymentJournalizer` | `payment_date` | `VendorBillService` |
| `SlaPenalty` | `SlaPenaltyJournalizer` | `applied_at` | _system — applied_at is stamped now() at the moment the penalty is applied — a penalty cannot be applied into the past._ |
| `Expense` | `ExpenseJournalizer` | `expense_date` | on the model (`GuardsPostingDate`) |
| `Payroll` | `PayrollJournalizer` | `period_month` | `PayrollService` |
| `DepositTransaction` | `DepositTransactionJournalizer` | `transaction_date` | on the model (`GuardsPostingDate`) |
| `MarketingSpend` | `MarketingSpendJournalizer` | `spent_on` | on the model (`GuardsPostingDate`) |
| `StockMovement` | `InventoryMovementJournalizer` | `moved_on` | `StockMovementService` |
| `FixedAsset` | `FixedAssetAcquisitionJournalizer` | `acquisition_date` | on the model (`GuardsPostingDate`) |
| `DepreciationEntry` | `DepreciationEntryJournalizer` | `period_month` | _system — period_month is set by DepreciationService::run from the month being posted; the operator-reachable inputs are the scheduler and the admin button (both now()) and PostDepreciationCommand --month, which is guarded there._ |
| `FixedAssetDisposal` | `FixedAssetDisposalJournalizer` | `disposed_on` | `DisposeFixedAssetService` |
| `EmployeeAdvance` | `EmployeeAdvanceJournalizer` | `advance_date` | `GrantEmployeeAdvanceService` |
| `EmployeeAdvanceRepayment` | `EmployeeAdvanceRepaymentJournalizer` | `repaid_on` | `RecordAdvanceRepaymentService` |
| `Custody` | `CustodyJournalizer` | `custody_date` | `GrantCustodyService` |
| `CustodyTransaction` | `CustodyTransactionJournalizer` | `transaction_date` | `SettleCustodyService` |
| `OwnerStatementRun` | `OwnerStatementRunJournalizer` | `posting_date` | `FinaliseOwnerStatementRunService` |
| `Disbursement` | `DisbursementJournalizer` | `paid_on` | `DisbursementService` |
| `TenantCreditApplication` | `TenantCreditApplicationJournalizer` | `entry_date` | _system — entry_date is deliberately stamped at application time, never the source receipt's date. That decoupling is the whole point: it lets an old overpayment settle a current invoice without ever posting into the closed period the overpayment came from._ |
| `DepositApplication` | `DepositApplicationJournalizer` | `entry_date` | `ApplyDepositToInvoiceService` |
| `StraightLineRentAdjustment` | `StraightLineRentAdjustmentJournalizer` | `entry_date` | _system — entry_date is the last day of the month being recognised, derived by PostStraightLineRentService and never operator-typed. The sweep refuses to post into a closed period, which is also what makes an amendment forward-only: months already recognised are left exactly as they were._ |
| `InvoiceWriteOff` | `InvoiceWriteOffJournalizer` | `entry_date` | `WriteOffInvoiceService` |
<!-- /GENERATED:gl-sources -->

---
## Straight-line rent recognition (RA-02, 2026-08-09) — **ships OFF**

`BillingSettings::straight_line_rent_enabled` is **false**, and while it is, nothing here runs.

**What it does when enabled.** A stepped or abated lease bills a different amount most years; EAS 49
/ IFRS 16 say the lessor recognises the total consideration evenly across the term. So the P&L shows
a flat rent, the tenant keeps being invoiced the contracted ladder, and the running difference sits
in **Deferred Rent** (`11701001`).

    Dr Deferred Rent  / Cr Rental Income   — recognising MORE than billed (early in a stepped lease)
    Dr Rental Income  / Cr Deferred Rent   — once the ladder overtakes the average

**One account, not two.** The balance is a single running difference; splitting it into a receivable
and a liability would mean reclassifying every lease that crosses over mid-term for no gain in what
the balance sheet says.

**Only possible because of the charge schedule.** The whole contracted ladder exists as rows the day
a lease is signed, so "total rent over the term" is a query. On the pre-2026-08-08 model — one
mutable amount — the future was unknowable and averaging it would have meant inventing it.

Four rules worth keeping:

- **Rent-free months count in the DENOMINATOR, not the numerator.** That is what spreads a fit-out
  abatement across the term instead of leaving a hole in the first quarter. Read through
  `Lease::periodInFitOut()` / `abatedChargeTypesFor()` — the same predicates the billing engine uses,
  so the two cannot disagree about which months are free.
- **Billed is read from the SCHEDULE, not from invoices.** The adjustment must be computable before
  a month's invoice exists, and a cancelled or re-issued invoice must not move revenue recognition.
- **`entry_date` is the last day of the month being recognised**, never the day the job ran — a
  recognition entry belongs in the period it recognises.
- **Forward-only.** `PostStraightLineRentService::reverseFrom()` drops adjustments from a date onward
  so the next run re-derives them against amended terms, and **skips any month whose period has
  closed**. A reversed month is soft-deleted but still holds its `unique(lease_id, period)` slot, so
  the writer looks up `withTrashed()` and RESTORES rather than inserting — otherwise re-deriving
  collides with its own tombstone, which is exactly what happened the first time it was built.

**It changes nothing a tenant sees**, and that is asserted rather than assumed: `StraightLineRentTest`
bills the same month with the setting on and off and compares the invoices field by field.


## Month-end close checklist

`/admin/month-end-close` ([`MonthEndClose`](../../app/Filament/Admin/Pages/MonthEndClose.php) +
[`MonthEndReadinessService`](../../app/Services/Accounting/MonthEndReadinessService.php)) answers
"is this month ready to close?" in seven ordered rows: billing posted · sales declarations received ·
payments settled · vendor bills posted · **ledger in sync** · books tie out · period closed.

Two rules it is built on:

1. **It derives, it does not re-implement.** Every count comes from the service that already owns
   that decision — `MonthlyBillingService::previewForPeriod()`, `Lease::missingSalesDeclarationsFor()`,
   `PeriodService::assertPeriodsReconciled()`, `BooksReconciliationService::run()`. A checklist that
   re-implements "is billing done" is a checklist that can say done when it isn't.
2. **The `ledger_in_sync` row is the real close gate** — it catches the *same* assertion
   `PeriodService::closePeriod()` throws, so a green checklist means the close will succeed rather
   than failing at the last click, and a red one shows the service's own message.

**Closing still happens in the Accounting Periods resource.** This page links there; it does not
re-implement the close. One place to close a period, one gate to pass.

**Watch for green-for-the-wrong-reason.** A status row that cannot read its input must report a
FAILURE, never a pass — `MonthEndCloseTest` asserts every row goes red when its condition is
genuinely outstanding, and is mutation-verified against the one instance of this bug that shipped
(a `$check['ok']` read of a key `BooksReconciliationService` does not emit).

