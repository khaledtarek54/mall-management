# Accounting module — full walkthrough & demo guide
### الشرح الكامل لوحدة المحاسبة — للعرض على المحاسب

> **Purpose.** This is the detailed, bilingual tour of *everything* the accounting
> module does — the concepts, the chart of accounts, every money flow with its exact
> journal entry, every screen, the reports, and a demo script + a list of questions to
> ask the accountant. Read it top-to-bottom once and you'll be able to demo the module
> confidently and speak the accountant's language (Arabic terms are given throughout).
>
> Companion pages: [README.md](README.md) (short plain-language status) ·
> [../modules/21-general-ledger.md](../modules/21-general-ledger.md) (technical spec).

---

## Part 1 — The accounting foundations (الأساسيات) the accountant assumes you know

You need six ideas. That's genuinely all the theory required to follow the whole module.

### 1.1 Double-entry (القيد المزدوج)
Every financial event is recorded in **two** places at once: one account **receives**
value (debit / **مدين**) and another **gives** value (credit / **دائن**). The two
sides are **always equal**. This is not a Atriom rule — it's the 500-year-old rule of
all bookkeeping. The system *refuses to save* an entry whose debits ≠ credits.

> مدين (Debit) على اليسار، دائن (Credit) على اليمين، ومجموعهما متساوٍ دائمًا.

### 1.2 Debit/credit isn't "plus/minus" — it depends on the account's nature (طبيعة الحساب)
There are **five natures**. Whether a debit *increases* or *decreases* an account
depends on its nature:

| Nature (الطبيعة) | Normal balance | Debit does | Credit does | Examples |
|---|---|---|---|---|
| **Asset — أصل** | Debit (مدين) | ▲ increase | ▼ decrease | Cash, Bank, Receivables |
| **Liability — خصم/التزام** | Credit (دائن) | ▼ decrease | ▲ increase | Payables, VAT due, Deposits held |
| **Equity — حقوق ملكية** | Credit (دائن) | ▼ decrease | ▲ increase | Capital, Retained earnings |
| **Revenue — إيراد** | Credit (دائن) | ▼ decrease | ▲ increase | Rent, Service charges |
| **Expense — مصروف** | Debit (مدين) | ▲ increase | ▼ decrease | Salaries, Utilities |

**Memory aid:** Assets & Expenses grow on the **debit** side; Liabilities, Equity &
Revenue grow on the **credit** side.

### 1.3 The accounting equation (معادلة الميزانية)
> **Assets = Liabilities + Equity**  ·  الأصول = الخصوم + حقوق الملكية

Because every entry balances, this equation *always* holds — that's what a balance
sheet proves. Profit for the year sits inside Equity.

### 1.4 Sub-ledger vs. the general ledger (الأستاذ المساعد مقابل الأستاذ العام)
Your invoices, payments, deposits, etc. are **sub-ledgers** (detailed operational
records). The **general ledger (دفتر الأستاذ العام)** is the summarised company book.
In Atriom, every sub-ledger document **automatically posts** a matching journal entry
into the general ledger — the accountant doesn't re-type anything.

### 1.5 Accrual basis — revenue is recognised when *earned*, not when *paid* (أساس الاستحقاق)
When you **issue an invoice**, revenue is recorded immediately (the tenant now owes
you = a receivable). The later **payment** just converts the receivable into cash. This
is the standard accrual basis. (Deposits are the exception — they're never revenue, see
Part 4.7.)

### 1.6 VAT has two sides (ضريبة القيمة المضافة)
- **Output VAT (ض.ق.م مستحقة / مخرجات)** — VAT you charge tenants on service charges
  (14%). It's a **liability** — you owe it to the Tax Authority.
- **Input VAT (ض.ق.م مدخلات / قابلة للخصم)** — VAT suppliers charge *you*. It's an
  **asset** (recoverable) — it reduces what you owe the Tax Authority.
- **Base rent is VAT-exempt**; **service charges carry 14%**; a **marketing levy of 5%
  of base rent** is billed as its own revenue line.

---

## Part 2 — The Chart of Accounts (دليل الحسابات)

This is the master list of accounts. Atriom ships a **standard Egyptian-style starter
chart** the accountant can rename, deactivate, or extend. Codes are hierarchical
(`1 → 11 → 111 → 11101 → 11101001`); **only the deepest leaves are "postable"** (accept
entries) — the parents are just totalling headers.

**Screen:** Accounting → **Chart of Accounts (دليل الحسابات)**.

### 2.1 Assets — الأصول (1)
| Code | English | العربية |
|---|---|---|
| 11101001 | Main Cashier | الصندوق العام |
| 11102001 | Bank Account | حساب بنكي |
| 11201001 | Tenant Receivables | عملاء تجاريون (المدينون) |
| 11202001 | Other Debtors | مدينون متنوعون |
| 11401001 | VAT Recoverable (input) | ض.ق.م مدخلات (قابلة للخصم) |
| 12101001 | Furniture & Equipment | أثاث ومعدات |
| 12201001 | Accumulated Depreciation | مجمع إهلاك الأصول الثابتة |

### 2.2 Liabilities — الخصوم (2)
| Code | English | العربية |
|---|---|---|
| 21101001 | Vendor Payables | موردون تجاريون (الدائنون) |
| 21201001 | Tenant Deposits Held | تأمينات محتجزة |
| 21301001 | VAT Payable (output) | ض.ق.م مستحقة |
| 21302001 | Salary Tax Payable | ضريبة كسب العمل مستحقة |
| 21601001 | Social Insurance Payable | تأمينات اجتماعية مستحقة |
| 21401001 | Accrued Expenses | مصروفات مستحقة الدفع |
| 21501001 | Unearned / Deferred Revenue | إيرادات غير مكتسبة |
| 22101001 | Long-term Loans | قروض طويلة الأجل |

### 2.3 Equity — حقوق الملكية (3)
| Code | English | العربية |
|---|---|---|
| 31101001 | Owner Capital | رأس المال |
| 32101001 | Retained Earnings | أرباح محتجزة |
| 33101001 | Profit / Loss for the Year | أرباح / خسائر العام |

### 2.4 Revenue — الإيرادات (4)
| Code | English | العربية |
|---|---|---|
| 41101001 | Base Rent Revenue | إيرادات الإيجار الأساسي |
| 41102001 | Service Charge Revenue | إيرادات خدمات |
| 41103001 | CAM Recovery Revenue | إيرادات استرداد المصروفات المشتركة |
| 41104001 | Utility Revenue | إيرادات مرافق |
| 41105001 | Percentage Rent Revenue | إيرادات إيجار نسبي |
| 41106001 | Marketing Levy Revenue | إيرادات رسوم تسويق |
| 41107001 | Late Fee Income | إيرادات غرامات تأخير |
| 42101001 | Miscellaneous Income | إيرادات متنوعة |
| 43101001 | Sales Returns & Allowances | مردودات ومسموحات المبيعات |

### 2.5 Expenses — المصروفات (5)
| Code | English | العربية |
|---|---|---|
| 51101001 | Salaries & Wages | رواتب وأجور |
| 51102001 | Repairs & Maintenance | صيانة وإصلاحات |
| 51103001 | Utilities Expense | مصروف مرافق |
| 51104001 | Cleaning & Security | نظافة وأمن |
| 51105001 | Marketing & Advertising | مصروف تسويق ودعاية |
| 51106001 | General & Admin Expense | مصروفات إدارية وعمومية |
| 51107001 | Depreciation Expense | مصروف إهلاك |
| 52101001 | Bank Charges | مصروفات بنكية |

---

## Part 3 — Semantic mappings: how the system picks the right account (ربط الحسابات)

The system never hard-codes account numbers. Instead each posting refers to a **role**
(e.g. `accounts_receivable`), and a mapping table points that role at a chart account.
**The accountant can re-point any role from the UI** — e.g. move "bank" to a different
bank account — **without any code change**. Defaults:

| Role (used by the engine) | → Account | العربية |
|---|---|---|
| `cash` / `bank` | Main Cashier / Bank Account | الصندوق / البنك |
| `accounts_receivable` | Tenant Receivables | المدينون |
| `accounts_payable` | Vendor Payables | الموردون |
| `deposits_held` | Tenant Deposits Held | تأمينات محتجزة |
| `vat_payable` / `vat_recoverable` | VAT Payable / Recoverable | ض.ق.م مستحقة / مدخلات |
| `salary_tax_payable` / `social_insurance_payable` | Salary Tax / Social Insurance | ضريبة كسب العمل / تأمينات اجتماعية |
| `unearned_revenue` | Unearned Revenue | إيرادات غير مكتسبة |
| `rent_revenue`, `service_charge_revenue`, … | the matching revenue lines | خطوط الإيرادات |
| `salaries_expense`, `maintenance_expense`, … | the matching expense lines | خطوط المصروفات |
| `retained_earnings` | Retained Earnings | أرباح محتجزة |

> This is the single most important design point to show the accountant: **he controls
> the mapping**. The software provides the plumbing; he decides which accounts money
> flows into.

---

## Part 4 — Every money flow, with its exact journal entry (قواعد الترحيل)

This is the heart of the module. Each business document below **auto-posts** the journal
shown. Every example balances (Σ debit = Σ credit). Show these to the accountant — this
is where he'll confirm the treatment matches Egyptian practice.

### 4.1 Issue an invoice — إصدار فاتورة
**Rule:** Dr Receivables (total) / Cr each revenue line (ex-VAT) + Cr Output VAT.
*Example — base rent 10,000 (exempt) + service charge 1,000 + 14% VAT 140 = **11,140***

| Dr/Cr | Account | العربية | Amount |
|---|---|---|---|
| مدين | Tenant Receivables | المدينون | 11,140 |
| دائن | Base Rent Revenue | إيرادات الإيجار الأساسي | 10,000 |
| دائن | Service Charge Revenue | إيرادات خدمات | 1,000 |
| دائن | VAT Payable | ض.ق.م مستحقة | 140 |

### 4.2 Receive a payment — تحصيل دفعة
**Rule:** Dr Cash/Bank (amount) / Cr Receivables (allocated) + Cr Unearned Revenue (any overpayment).
*Example — tenant pays the 11,140 by bank transfer:*

| Dr/Cr | Account | العربية | Amount |
|---|---|---|---|
| مدين | Bank Account | حساب بنكي | 11,140 |
| دائن | Tenant Receivables | المدينون | 11,140 |

*Overpayment example — tenant pays 12,000 against an 11,140 balance:* the extra 860
becomes a customer advance:

| Dr/Cr | Account | العربية | Amount |
|---|---|---|---|
| مدين | Bank Account | حساب بنكي | 12,000 |
| دائن | Tenant Receivables | المدينون | 11,140 |
| دائن | Unearned Revenue | إيرادات غير مكتسبة | 860 |

### 4.3 Credit note (reduce/refund a charge) — إشعار دائن
**Rule:** Dr Sales Returns (net) + Dr VAT Payable (reverse the VAT) / Cr Receivables (total).
*Example — credit 500 + 70 VAT = 570:*

| Dr/Cr | Account | العربية | Amount |
|---|---|---|---|
| مدين | Sales Returns & Allowances | مردودات المبيعات | 500 |
| مدين | VAT Payable | ض.ق.م مستحقة | 70 |
| دائن | Tenant Receivables | المدينون | 570 |

### 4.4 Vendor bill (supplier invoice) — فاتورة مورد
**Rule:** Dr Expense by category (net) + Dr Input VAT (recoverable) / Cr Payables (total).
*Example — maintenance bill 2,000 + 280 VAT = 2,280:*

| Dr/Cr | Account | العربية | Amount |
|---|---|---|---|
| مدين | Repairs & Maintenance | صيانة وإصلاحات | 2,000 |
| مدين | VAT Recoverable | ض.ق.م مدخلات | 280 |
| دائن | Vendor Payables | الموردون | 2,280 |

**Paying the vendor bill — سداد للمورد:** Dr Payables / Cr Bank (or Cash).

| Dr/Cr | Account | العربية | Amount |
|---|---|---|---|
| مدين | Vendor Payables | الموردون | 2,280 |
| دائن | Bank Account | حساب بنكي | 2,280 |

*(Category → expense line: maintenance→صيانة, utilities→مرافق, cleaning_security→نظافة وأمن, marketing→تسويق, admin/other→إدارية وعمومية.)*

### 4.5 Direct / petty-cash expense — مصروف مباشر
Same as a vendor bill but paid **immediately** (no payable stage).
**Rule:** Dr Expense (net) + Dr Input VAT / Cr Cash|Bank (total).
*Example — admin expense 500 paid cash, no VAT:*

| Dr/Cr | Account | العربية | Amount |
|---|---|---|---|
| مدين | General & Admin Expense | مصروفات إدارية وعمومية | 500 |
| دائن | Main Cashier | الصندوق العام | 500 |

### 4.6 Payroll run — مسير رواتب
**Rule:** Dr Salaries (gross) / Cr Salary Tax Payable + Cr Social Insurance Payable (both withheld) + Cr Bank|Cash (net paid).
*Example — gross 20,000; salary tax 2,000; social insurance 1,500; net paid 16,500:*

| Dr/Cr | Account | العربية | Amount |
|---|---|---|---|
| مدين | Salaries & Wages | رواتب وأجور | 20,000 |
| دائن | Salary Tax Payable | ضريبة كسب العمل مستحقة | 2,000 |
| دائن | Social Insurance Payable | تأمينات اجتماعية مستحقة | 1,500 |
| دائن | Bank Account | حساب بنكي | 16,500 |

> The withheld tax/insurance sit as **liabilities** until you remit them to the
> authorities. *(Currently a per-run batch total, not per-employee payslips — a possible
> next step, see the questions in Part 9.)*

### 4.7 Security deposit — تأمين (receipt / refund / forfeit)
A deposit received is **not income** — it's money you owe back, so it's a **liability**
("Deposits Held / تأمينات محتجزة"). It only becomes income if **forfeited**.

| Event (الحركة) | Journal | العربية |
|---|---|---|
| **Receipt (استلام)** | Dr Bank/Cash / Cr Deposits Held | مدين بنك / دائن تأمينات محتجزة |
| **Refund (رد)** | Dr Deposits Held / Cr Bank/Cash | مدين تأمينات محتجزة / دائن بنك |
| **Forfeit (مصادرة)** | Dr Deposits Held / Cr Misc Income | مدين تأمينات محتجزة / دائن إيرادات متنوعة |

*Example — receive 5,000 deposit by bank:* Dr حساب بنكي 5,000 / Cr تأمينات محتجزة 5,000.
The GL "Deposits Held" balance is always **exactly what you still owe tenants**.

---

## Part 5 — The screens the accountant uses (الشاشات)

All under the **Accounting (المحاسبة)** section of the admin sidebar:

| Screen | العربية | What it's for |
|---|---|---|
| **Chart of Accounts** | دليل الحسابات | View/edit the account tree; add or rename accounts. |
| **Journal Entries** | قيود اليومية | See every posted entry; create a **manual entry** (e.g. opening balances, depreciation, adjustments); **post** or **void** an entry. |
| **Fiscal Periods** | الفترات المحاسبية | Open/close months and years; run the **year-end close**. |
| **Vendors** | الموردون | The supplier master list. |
| **Vendor Bills** | فواتير الموردين | Supplier invoices (draft → approved → paid) + record payments. |
| **Expenses** | المصروفات | Direct/petty-cash spending. |
| **Payroll** | مسير الرواتب | Monthly salary batch runs. |
| **Credit Notes** | الإشعارات الدائنة | Reductions/refunds against tenant invoices. |
| **Deposit Transactions** | حركات التأمينات | Deposit receipt / refund / forfeit. |

Every screen is **property-scoped** (مقيّد بالعقار): the accountant sees one mall or a
consolidated "All Properties" view, matching his permissions.

---

## Part 6 — The reports (التقارير)

All reports are **live views of the ledger** — always consistent, never hand-maintained.
Each can be filtered by **year** and **property** (per-mall or consolidated) and
exported to **PDF (bilingual, right-to-left Arabic)** for owners/auditors.

| Report | العربية | What it shows |
|---|---|---|
| **Trial Balance** | ميزان المراجعة | Every account with its debit/credit balance; total debits = total credits (proof the books balance). |
| **Income Statement** | قائمة الدخل | Revenue − Expenses = Profit/Loss for the period. |
| **Balance Sheet** | قائمة المركز المالي | Assets = Liabilities + Equity at a point in time. |
| **General Ledger / Account statement** | دفتر الأستاذ / كشف حساب | Every movement in a single account, with a running balance. |

---

## Part 7 — Closing the books (الإقفال)

- **Period close (إقفال الفترة):** once a month is reconciled, close it so no one can
  edit past entries. A closed period **refuses new postings**.
- **Year-end close (قيد الإقفال):** posts the closing entry that moves the year's total
  revenue and expenses into **Retained Earnings (الأرباح المحتجزة)**, resetting the P&L
  to zero for the new year. It's reversible ("reopen year") and must be done for years
  **in sequence** (2025 before 2026).

After close, the income statement for the closed year reads zero (the profit has moved
into equity on the balance sheet) — exactly as an accountant expects.

---

## Part 8 — Why it stays correct (الضمانات) — one paragraph

Postings happen through a **self-healing nightly sweep**: the system compares each live
document to the ledger and posts/updates/reverses to match — so it's idempotent and can
never double-post. Every entry is rejected unless it balances. A **tie-out check**
confirms the ledger's receivables equal the actual unpaid invoices (and payables equal
unpaid vendor bills). The whole module is covered by an automated test suite
(1,400+ tests) and each change is peer-reviewed before it ships.

---

## Part 9 — Demo script for the meeting (خطوات العرض)

A 10-minute flow that tells the whole story:

1. **Chart of Accounts** — "This is دليل الحسابات; it's a standard Egyptian chart you
   can rename or extend. Only leaf accounts take entries." Scroll the five sections.
2. **Issue/last invoice → Journal Entries** — open a recent tenant invoice, then show
   its **auto-generated journal entry** (Dr المدينون / Cr إيرادات + ض.ق.م). "No one typed
   this — it posted itself."
3. **Payment** — show a payment entry (Dr بنك / Cr المدينون). "The receivable becomes cash."
4. **A cost** — show a vendor bill (Dr مصروف + Dr ض.ق.م مدخلات / Cr الموردون) and a direct
   expense. Mention input VAT is recoverable.
5. **Payroll** — show a run (Dr رواتب / Cr ضرائب + تأمينات + بنك).
6. **Deposit** — show a deposit receipt (Dr بنك / Cr تأمينات محتجزة). "This is a liability,
   not income."
7. **Trial Balance** — "Debits = credits, always." Then **Income Statement** and
   **Balance Sheet**. Export one to **PDF** to show the bilingual output.
8. **Fiscal Periods** — show closing a month and the year-end close concept.
9. Hand him **Part 10** and ask what's missing.

---

## Part 10 — Questions to ask the accountant (أسئلة للمحاسب)

Bring these written down — his answers become our roadmap.

**Chart & mappings (الدليل والربط)**
- Does this chart of accounts match how you work, or do you have your own coded chart we
  should import? (هل لديك دليل حسابات بأكواد خاصة تريد إدخاله؟)
- Are the account names/codes right for Egyptian practice?

**Posting treatments (المعالجات المحاسبية)**
- Is revenue-at-issue (accrual) how you want it, or do you book on cash collection?
- CAM (service-charge) year-end recoveries now post to their **own revenue line**
  (إيرادات استرداد المصروفات المشتركة) — confirm that's how you want them presented, and
  whether they should carry VAT.
- Are the payroll withholdings (salary tax + social insurance) split the way you need?
  Do you also need the **employer's** share of social insurance recorded?

**Compliance (الامتثال الضريبي)**
- Do we need **ETA e-invoicing / VAT-return formats** (منظومة الفاتورة الإلكترونية /
  إقرار ض.ق.م)? On what schedule do you file?
- Any withholding-tax on vendor payments (خصم وحجز من المنبع) we must record?

**Opening & migration (الأرصدة الافتتاحية والترحيل)**
- If we go live, do you have **opening balances** (أرصدة افتتاحية) to load — existing
  receivables, cash, deposits, payables?

**Outputs (المخرجات)**
- Which reports do you send the owner / the auditor, and in what format?
- Do you need **per-employee payslips** (قسائم رواتب)?
- Do you need **fixed-asset depreciation** run automatically (الإهلاك)?

---

## Part 11 — Full bilingual glossary (المسرد الكامل)

| English | العربية |
|---|---|
| Accounting | المحاسبة |
| Bookkeeping | مسك الدفاتر |
| Double-entry | القيد المزدوج |
| Debit / Credit | مدين / دائن |
| Account nature | طبيعة الحساب |
| Chart of Accounts | دليل الحسابات |
| General Ledger | دفتر الأستاذ العام |
| Sub-ledger | الأستاذ المساعد |
| Journal / Journal entry | دفتر اليومية / قيد يومية |
| Posting | الترحيل |
| Trial Balance | ميزان المراجعة |
| Income Statement (P&L) | قائمة الدخل |
| Balance Sheet | قائمة المركز المالي / الميزانية العمومية |
| Accounting equation | معادلة الميزانية |
| Assets / Liabilities / Equity | الأصول / الخصوم / حقوق الملكية |
| Revenue / Expense | الإيرادات / المصروفات |
| Accounts Receivable | المدينون / ذمم مدينة |
| Accounts Payable | الموردون / الدائنون |
| VAT (output / input) | ض.ق.م (مخرجات مستحقة / مدخلات قابلة للخصم) |
| Accrual basis | أساس الاستحقاق |
| Deposits Held | تأمينات محتجزة |
| Unearned / Deferred revenue | إيرادات غير مكتسبة |
| Retained Earnings | الأرباح المحتجزة |
| Fiscal Year / Period | السنة المالية / الفترة المحاسبية |
| Period close / Year-end close | إقفال الفترة / قيد الإقفال السنوي |
| Reversing entry | قيد عكسي |
| Opening balances | أرصدة افتتاحية |
| Depreciation | الإهلاك |
| Withholding tax | ضريبة مستقطعة / خصم من المنبع |
| Salary tax | ضريبة كسب العمل |
| Social insurance | التأمينات الاجتماعية |
| Sales returns & allowances | مردودات ومسموحات المبيعات |
| Credit note | إشعار دائن |
