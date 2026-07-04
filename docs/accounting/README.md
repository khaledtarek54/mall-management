# Accounting — where we are & what's next
### دليل مبسّط لحالة المحاسبة (for the owner, not the accountant)

> **Read this first.** It's the plain-language map of the accounting module — no
> accounting knowledge needed. The deep technical reference lives in
> [docs/modules/21-general-ledger.md](../modules/21-general-ledger.md); this page
> is the "you are here" overview so you never have to hold the whole thing in your head.
>
> **Presenting to the accountant?** Use [WALKTHROUGH.md](WALKTHROUGH.md) — a full
> bilingual (Arabic) tour: the concepts, the chart of accounts, every money flow with
> its exact journal entry, the screens, the reports, a demo script, and the questions
> to ask him.
>
> **Where do we stand vs. a full accounting system?** See
> [GAP-ANALYSIS.md](GAP-ANALYSIS.md) — capability matrix, the chart-of-accounts
> code-entry hardening, the prioritized backlog, and the stability verdict.

---

## 1. The one idea that ties everything together

There is **one central book** — the *general ledger* (دفتر الأستاذ العام). Every
money event in the mall writes a matching entry into it **automatically**:

```
  Invoices ─┐
  Payments ─┤
  Deposits ─┤      ┌──────────────────┐      ┌── Trial Balance (ميزان المراجعة)
  Vendor    ├────► │  ONE ledger book │ ───► ├── Income Statement (قائمة الدخل)
  bills     │      │ (every entry     │      ├── Balance Sheet (قائمة المركز المالي)
  Expenses ─┤      │  balances)       │      └── PDF exports for the accountant/auditor
  Payroll  ─┤      └──────────────────┘
  ...next  ─┘        (auto-written)          (all reports are just VIEWS of the book)
```

**What this means for you:** you do **not** need to understand the accounting of
each module. You only need to trust **one rule** and read the reports:

> **The one rule (double-entry / القيد المزدوج):** every entry has two equal sides —
> money *into* one account and *out of* another. The two sides always match, so the
> books can never silently go wrong. If they ever didn't balance, the system flags it.

The reports are **computed from the book**, never typed by hand — so they're always
consistent with each other.

---

## 2. Why it feels like "a lot" — and why it actually isn't getting harder

We're adding many money sources (invoices, payroll, deposits, vendor bills…). That
*sounds* like growing complexity, but each one is added the **same isolated way**:

> A new money source = **one small "translator" class** (a *journalizer*) that says
> "this document becomes these two ledger lines." It plugs into the ledger and is
> done. It doesn't touch the other modules.

So the module grows **wide, not tangled**. Adding the 10th money source is exactly
as simple as the 1st. That's the whole point of the design — you can keep saying
"add the next thing" without the system becoming fragile. Every phase is also
**reviewed by multiple AI reviewers and covered by tests before it ships**
(currently **1,439 passing tests**), so nothing new breaks what's already there.

---

## 3. What we've built so far (the journey)

Each row is a shipped, tested, reviewed phase. "What the accountant can now do" is
the practical payoff.

| # | Phase (Arabic) | In plain terms | What the accountant can now do |
|---|----------------|----------------|-------------------------------|
| 0 | **Foundation** (الأساس) | The empty ledger itself: chart of accounts, fiscal years, the manual journal-entry screen, the trial balance. | See the **chart of accounts** (دليل الحسابات), post a **manual journal entry**, read the **trial balance** (ميزان المراجعة). |
| 1 | **Auto-posting** (الترحيل الآلي) | Invoices, payments and credit notes now write themselves into the ledger; we also back-filled all past history so the ledger matches the receivables exactly. | Stop hand-copying sales into the books — rent/service invoices and payments appear in the ledger automatically. |
| 2 | **Financial statements** (القوائم المالية) | The two reports every business lives by, per-property and consolidated, plus **PDF export** (bilingual, right-to-left Arabic). | Produce a **profit & loss** (قائمة الدخل) and a **balance sheet** (قائمة المركز المالي), and hand a clean PDF to an owner or auditor. |
| 3 | **Expenses & payables** (المصروفات والموردون) | The money going *out*: **vendor bills** with recoverable input VAT, **direct/petty-cash expenses**, and **payroll runs** (salaries, tax & insurance withheld). | Record supplier bills and pay them, log petty-cash spending, and run the monthly salary batch — all landing in the ledger with the right VAT and liabilities. |
| 4 | **Close & compliance** (الإقفال) | Lock a finished month/year so no one edits it, and post the **year-end closing entry** that moves the year's profit into equity. | **Close a period** (إقفال الفترة) after it's reconciled, and do the **year-end close** (قيد الإقفال). |
| + | **Security deposits** (تأمينات) | Tenant deposits tracked as receipt / refund / forfeit — held as a **liability** (money you owe back), turning into income only if forfeited. | Record a deposit received, refund it, or forfeit it — and read exactly how much tenant money is still held. |
| + | **Marketing spend → books** (مصروف التسويق) *(shipped 2026-07-03)* | The marketing fund's **spending** now lands in the ledger as a marketing expense (Dr Marketing Expense / Cr Cash\|Bank). The **levy** (money in) was already booked via the tenant invoice, so the fund's full picture — collected minus spent — is now on the P&L. | Record offers/promotions/events/printed-work spend and see it flow straight into the income statement — no manual re-entry. |
| + | **Inventory → books** (المخزون) *(just shipped)* | The new inventory module (22) posts to the ledger: receiving stock builds an **Inventory asset** (Dr Inventory / Cr a "goods received" clearing liability); using materials on a maintenance job books the **cost as an expense** (Dr Maintenance / Cr Inventory). So material cost is recognised as it's consumed and stock value stays reconcilable. | See per-job material cost and inventory value on the books, without manual entries. |

**Where the accountant clicks for all this:** the **Accounting** section of the admin
sidebar — Chart of Accounts, Journal Entries, Fiscal Periods, Vendors, Vendor Bills,
Expenses, Payroll, Credit Notes, Deposit Transactions, and the report pages (Trial
Balance, Income Statement, Balance Sheet).

---

## 4. The safety rails (why you can trust it without checking the math)

- **It always balances.** Every posting is rejected unless the two sides are equal.
- **It self-heals.** A nightly sweep (`accounting:sync-ledger`) re-checks that the
  ledger matches the live documents — if an invoice changed, the ledger catches up.
- **Tie-outs.** The system cross-checks the ledger's receivables against the actual
  unpaid invoices and reports any mismatch.
- **Reviewed + tested every phase.** Nothing ships without a multi-agent review and a
  green test suite (1,439 tests today).

---

## 5. What's next — and what you're free to skip

You told me to keep things skippable. Here's the honest menu. **"Skip?"** = can you
ignore it and still run the mall day-to-day.

| Candidate | What it gives you | Effort | Skip? |
|-----------|-------------------|--------|-------|
| **Opening balances** (أرصدة افتتاحية) | When you switch to this system, the accountant enters the *starting* balances (existing debts, cash, deposits) as a first manual journal so the ledger starts from reality, not zero. Already **possible today** via the manual journal screen — this would just add a guided helper. | Small–Med | **Only if migrating from old books.** If you go live fresh, skip. |
| **CAM recovery revenue line** | Right now, year-end CAM (service-charge) recoveries land in a generic "misc income" account. This gives them their own clearly-labelled revenue line. | Small | Skip — it's a reporting nicety, the money is already correct. |
| **Per-employee payslips** (قسيمة راتب) | Payroll today is one batch total per run. This breaks it down per employee with printable payslips. | Medium | Skip unless staff ask for individual payslips. |
| **ETA / e-invoicing & tax reports** (منظومة الفاتورة الإلكترونية) | Egypt's Tax Authority statutory formats (VAT return, e-invoice). Matters for legal compliance. | Large | **Don't skip long-term** if you must file with ETA — but it's separate from daily bookkeeping. |
| **Void-on-delete safety hook** | Today the safe way to reverse a posted document is **Cancel** (not Delete). This hook would make Delete safe too. | Small | Skip — just train the accountant to use Cancel. |
| **Inter-property accounts** | Correctly split a *single* payment that covers *two* properties in each property's separate report (today it's only exact in the consolidated view). | Medium | Skip unless you regularly take one payment across multiple malls. |

**My recommendation for the next build:** either **per-employee payslips** (most
visible to the team) or, if you're preparing to go live on real books, **opening
balances** (so the accountant can load the current position). Say the word and I'll
scope it, build it, review it, and push it — same process as every phase.

---

## 6. Quick glossary (hand this to the accountant / اعطِ هذا للمحاسب)

| English | العربية |
|---------|---------|
| Chart of Accounts | دليل الحسابات |
| General Ledger | دفتر الأستاذ العام |
| Journal Entry | قيد يومية |
| Debit / Credit | مدين / دائن |
| Trial Balance | ميزان المراجعة |
| Income Statement (P&L) | قائمة الدخل |
| Balance Sheet | قائمة المركز المالي |
| Accounts Receivable / Payable | ذمم مدينة / الموردون |
| VAT (payable / recoverable) | ضريبة القيمة المضافة (مستحقة / قابلة للخصم) |
| Deposits Held | تأمينات محتجزة |
| Fiscal Year / Period | السنة المالية / الفترة المحاسبية |
| Posting | الترحيل |
| Closing entry | قيد الإقفال |
| Opening balances | أرصدة افتتاحية |

---

## 7. Where things live (if you ever need to look)

- **Plain-language overview:** this file.
- **Full technical spec + rules:** [docs/modules/21-general-ledger.md](../modules/21-general-ledger.md)
- **The ledger engine:** `app/Services/Accounting/` (posting, reports, close).
- **The "translators" (one per money source):** `app/Services/Accounting/Journalizers/`.
- **Nightly self-healing sweep:** `accounting:sync-ledger` (runs 05:00 daily).

*Keep this page current: when a phase ships, add its row to §3 and move the item out
of §5.*
