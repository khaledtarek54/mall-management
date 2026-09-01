# What we need from you, to go live
# ما نحتاجه منكم لبدء التشغيل

> **This is a FORM, not a status list.** It tells the operator and their accountant what to send and
> in what format — nothing more. **[STATUS.md](../STATUS.md) is the authority** on what is
> outstanding and what is blocked; if the two ever disagree, STATUS.md is right and this file is
> stale. It deliberately carries no progress, no roadmap and no "what is missing", because
> [two lists of one launch is how a stale one survives](../STATUS.md) — that has already happened
> twice on this project.
>
> **Send to:** the operator (Eltizam) and their accountant · **Prepared by:** the build team

---

## At a glance — four things, and two of them have deadlines

| # | What | Who answers | Deadline | Why it cannot wait |
|---|---|---|---|---|
| **1** | Tax registration number, legal name, billing email | Accountant | **Before the first invoice** | Until it is set, no invoice can call itself a *Tax Invoice*, and **your tenants cannot reclaim the VAT they pay you** |
| **2** | Your Egyptian chart of accounts | Accountant | Before opening balances | Everything else in the books hangs off it |
| **3** | Opening balances + the cut-over date | Accountant | Before the first month closes | Without them the first trial balance is wrong by exactly the history that preceded it |
| **4** | Two conventions: document numbering, fiscal-year start | Accountant | **Before the first invoice / before anything is posted** | **Neither can be undone cleanly.** See Part 4 |

**١ ·** الرقم الضريبي والاسم القانوني وبريد الفوترة — قبل أول فاتورة · **٢ ·** دليل الحسابات المصري ·
**٣ ·** الأرصدة الافتتاحية وتاريخ التحول · **٤ ·** قراران لا رجعة فيهما: ترقيم المستندات وبداية السنة المالية.

---

## Part 1 · Your tax identity
## أولًا · الهوية الضريبية

**This is the single highest-consequence item on the page, and it is one screen.**

Three values. They print on every invoice a tenant receives and files with their own accountant.

| Field | الحقل | Your answer |
|---|---|---|
| Tax registration number (TRN) | الرقم الضريبي | `____________________` |
| Registered legal name (exactly as on the tax card) | الاسم القانوني المسجَّل كما في البطاقة الضريبية | `____________________` |
| Billing-enquiries email | بريد استفسارات الفوترة | `____________________` |

**What happens while these are blank — and it is deliberate, not a bug.**

- The invoice is titled plainly **Invoice / فاتورة**, never *Tax Invoice / فاتورة ضريبية*. A document
  that calls itself a tax invoice must carry a registration number or the tenant cannot claim the
  input VAT. We would rather issue an **incomplete** document than a **confidently wrong** one — a
  plausible-looking placeholder TRN gets filed by the tenant and fails on their audit.
- The seller name falls back to **"Atriom"**, which is the software's name and appears on nobody's
  lease.
- The system reports this to us as **blocking** on every pre-deploy check, so it cannot be forgotten.

> بدون هذه القيم تصدر الفاتورة بعنوان «فاتورة» لا «فاتورة ضريبية»، ولا يستطيع المستأجر خصم ضريبة
> القيمة المضافة. ونحن نُصدر مستندًا ناقصًا عمدًا بدلًا من مستند يبدو صحيحًا وهو خطأ.

---

## Part 2 · Your chart of accounts
## ثانيًا · دليل الحسابات

Send it as a **CSV or Excel file, one account per row.** The system imports it directly — there is
nothing to type in by hand, and re-sending a corrected file **updates** the accounts rather than
duplicating them (they are matched on the code).

> **Note on the file sent earlier:** it was a Saudi contracting template — zakat, no VAT — so it
> could not be used. We need the Egyptian chart the business will actually be audited on.
> *(الملف السابق كان قالبًا سعوديًا للمقاولات — زكاة وبلا ضريبة قيمة مضافة — ولا يصلح.)*

### The columns

| Column | العمود | Required | What it accepts |
|---|---|---|---|
| `code` | كود الحساب | **Yes** | Digits only. Any width — see below |
| `name_en` | اسم الحساب بالإنجليزية | **Yes** | Free text |
| `name_ar` | اسم الحساب بالعربية | **Yes** | Free text |
| `type` | نوع الحساب | **Yes** | `asset` · `liability` · `equity` · `revenue` · `expense` |
| `cash_flow_section` | قسم التدفق النقدي | Optional | `cash` · `operating` · `investing` · `financing` |
| `is_postable` | قابل للترحيل | Optional | `1` or `0` — is this a posting account, or a heading you only total? |
| `is_active` | نشط | Optional | `1` or `0` |

**Three things that are NOT columns, on purpose:**

- **The parent account.** It is derived from the code — `1110` is the parent of `11101`. A column
  for it would let a file state a hierarchy the system disagrees with, and then two answers exist
  for one question. **Row order does not matter**; parents may come after children.
- **Debit or credit normal balance.** Derived from `type`. A file could otherwise assert that an
  asset is credit-normal, and the system would quietly disagree with it for ever.
- Revenue and expense accounts **may not carry a cash-flow section** — they net into net income by
  type, and letting one move to *investing* breaks the statement's arithmetic.

### One convention that is yours, not ours

**Account code width — 8 digits or 10?** The system is width-agnostic, so this is your house
convention. Tell us which you use and send the file that way; we will not renumber it.

> **عرض كود الحساب — ٨ خانات أم ١٠؟** النظام لا يفرض عرضًا معيّنًا، فهذا اصطلاحكم أنتم.

---

## Part 3 · Opening balances and the cut-over date
## ثالثًا · الأرصدة الافتتاحية وتاريخ التحول

**The cut-over date** is the day the new system becomes the book of record. Everything before it is
history you carry in as one opening position; everything after it is transacted here.

| | | Your answer |
|---|---|---|
| Cut-over date | تاريخ التحول | `____ / ____ / ________` |

Then the balances **as at the day before that date**:

| Balance | الرصيد | What we need |
|---|---|---|
| Accounts receivable | الذمم المدينة | Per tenant, per invoice if you have it — an aged list is ideal |
| Accounts payable | الذمم الدائنة | Per supplier |
| Bank and cash | البنك والنقدية | Per account, matching your chart |
| Security deposits held | التأمينات المحتجزة | Per tenant — this is money you owe back, not income |
| Fixed assets | الأصول الثابتة | Cost, accumulated depreciation, and the date each was put into service |

**How it is loaded.** A screen takes the trial balance and creates a **draft** journal entry your
accountant reviews and posts. Nothing reaches the books until they do — so a wrong file is a
correction, not a disaster: **loading a corrected file a second time produces a second draft beside
the first**, which you can compare and delete. Only posting commits anything.

**You do not have to wait for the fiscal year to be opened.** A draft can be prepared before the
period exists and posted afterwards, so Part 3 and Part 4b can be answered in either order.

**What happens without them.** The first trial balance is wrong by exactly the history preceding it:
the ledger balances, but it describes a business that started on the cut-over date with nothing.

> **بدونها** يكون أول ميزان مراجعة ناقصًا بمقدار التاريخ السابق له بالضبط. وتُحمَّل الأرصدة كقيد
> يومية **تحت المراجعة** يعتمده المحاسب — فلا شيء يدخل الدفاتر قبل موافقته.

---

## Part 4 · Two decisions that cannot be undone
## رابعًا · قراران لا رجعة فيهما

Both are free to make now and expensive to change later. **Please answer both before the first
invoice is issued.**

### 4a · Document numbering · ترقيم المستندات

Every document carries a prefix and a running number. Two questions:

**(i) Do the default prefixes suit you, or do you use your own?**

| Document | المستند | Default | Yours, if different |
|---|---|---|---|
| Tax invoice | فاتورة ضريبية | `INV` | `______` |
| Credit note | إشعار خصم | `CN` | `______` |
| Payment receipt | إيصال سداد | `RCT` | `______` |
| Journal entry | قيد يومية | `JE` | `______` |
| Supplier bill | فاتورة مورد | `BILL` | `______` |
| Expense | مصروف | `EXP` | `______` |
| Security deposit movement | حركة تأمين | `DEP` | `______` |
| Payroll run | مسيّر رواتب | `PAY` | `______` |
| Lease | عقد إيجار | `LSE` | `______` |
| Post-dated cheque | شيك آجل | `PDC` | `______` |
| Purchase request | طلب شراء | `PR` | `______` |

**(ii) When does the number reset?**

| Option | Meaning | Who does this |
|---|---|---|
| **Continuous** *(our default)* | `INV-AW-0001`, then `0002`, for ever | **Yardi and MRI.** Recommended |
| Annual | Restarts each January | SAP, Oracle, NetSuite, Odoo |
| Monthly | Restarts each month | Nobody we benchmarked |

☐ Continuous ☐ Annual ☐ Monthly — `____________________`

**The deadline is real.** After the first invoice is issued, the prefix is printed on documents that
cannot be renumbered, and changing the scheme starts a **second** series running alongside the first.

### 4b · Fiscal year start · بداية السنة المالية

| | | Your answer |
|---|---|---|
| The month your fiscal year starts | الشهر الذي تبدأ به السنة المالية | `____________` *(default: January / يناير)* |

**Free to change on an empty system; refused once anything is posted** — moving it re-dates every
accounting period that already exists. An April→March year is perfectly ordinary here; we only need
to know before the first entry.

---

## What we do the moment each arrives

| You send | We do | How long |
|---|---|---|
| Part 1 | Enter it; the pre-deploy check goes green and invoices become tax invoices | Minutes |
| Part 2 | Import the file; you review the tree on screen | Same day |
| Part 3 | Load the balances as a draft entry for your accountant to post | Same day |
| Part 4 | Set both; they are then locked by the first document | Minutes |

**Nothing here is waiting on development.** All four have working screens and importers already
built and tested; what is missing is your numbers, your file and your two decisions.

> **لا شيء مما سبق ينتظر عملًا برمجيًا.** الشاشات والمستوردات جاهزة ومختبَرة، والناقص هو أرقامكم
> وملفكم وقراراكم.

---

## Questions we have NOT asked here

Deliberately. There is a longer list of things that would change what the system does — tax-exempt
tenants, tenants who withhold tax from rent, whether to generate the lease contract as a PDF,
whether a tenant-caused repair is recharged. **None of them blocks go-live**, and asking twenty
questions at once is how the four that matter get lost. They are in
[STATUS.md §5](../STATUS.md) and we will bring them in a second pass.
