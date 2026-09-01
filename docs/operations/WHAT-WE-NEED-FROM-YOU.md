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

## Part 5 · The other decisions that block go-live
## خامسًا · بقية القرارات التي توقف بدء التشغيل

Parts 1–4 are the ones with deadlines. These have no deadline but still block a real launch, and
every one of them needs your accountant or your management — none is a technical choice.

| # | Question | السؤال | Your answer |
|---|---|---|---|
| **A1.x** | **Sign off the tax treatment:** which supplies are taxable, at what rate, from when — including the one **Law 157/2025** forces: **is base rent now taxable?** | اعتماد المعالجة الضريبية، ومنها: هل أصبح الإيجار الأساسي خاضعًا للضريبة؟ | `__________` |
| **C-TAX** | **Which supplies carry stamp tax (ضريبة الدمغة) or schedule tax (ضريبة الجدول)?** | أي التوريدات تحمل ضريبة الدمغة أو ضريبة الجدول؟ | `__________` |
| **A9.1 / A9.2** | **Sign off the posting map** — do all 52 roles point at the right account in your chart? And is the **5% marketing levy** revenue, or a restricted fund? | اعتماد خريطة الترحيل · ورسم التسويق ٥٪: إيراد أم صندوق مخصص؟ | `__________` |
| **A2.7** | **Are invoices issued under Eltizam's TRN, or each owner's?** | هل تصدر الفواتير برقم التزام الضريبي أم برقم كل مالك؟ | `__________` |
| **A8.3** | **What history migrates, how many years, and can you send sample files?** | ما التاريخ الذي سيُرحَّل، وكم سنة، وهل يمكن إرسال عينات؟ | `__________` |
| **B.1 / B.3–B.5** | **How is Eltizam paid, and whose bank account does tenant money land in?** Fixed fee, % of collected, or % of gross? Rent only, or all income? | كيف تُحتسب أتعاب التزام، وفي أي حساب بنكي يدخل مال المستأجرين؟ | `__________` |
| **C-PAY** | **The statutory payroll rates** — salary tax and both social-insurance shares. | النسب القانونية للأجور: ضريبة المرتبات وحصتا التأمينات. | `__________` |
| **C4.2** | **Target go-live date, parallel-run period, and who validates the migrated data on your side.** | تاريخ بدء التشغيل، ومدة التشغيل المتوازي، ومن يعتمد البيانات المرحَّلة لديكم. | `__________` |

> **Two notes.** The payroll rates ship at **0 · 0 · 0** on purpose — software should not start
> deducting money from people's salaries on an assumption. And **two owners with two VAT
> registrations cannot share one install** (A2.7); if that is your situation, tell us early.

---

## Part 6 · Confirm a default — silence ships it
## سادسًا · تأكيد الإعدادات الافتراضية — الصمت يعني الموافقة

**You do not need to reply to this section.** Everything below is already built and will ship exactly
as described. Read it, and tell us only about the lines you want **changed**. Each is a setting, not
a rebuild.

| # | Ships as | ✓ or change |
|---|---|---|
| A1.2–A1.6 | Percentage rent, CAM true-up, late fees and the marketing levy are **VAT-exempt**; levy is **5% of base rent only** | |
| A1.7 | Late fee **2%** of outstanding · **minimum 50 EGP** · **7-day grace** · charged **once** · **no cap** | |
| A1.8 | **Security deposit 3 months** · **escalation 7% fixed** (a CPI-indexed option exists) | |
| A1.9 | Percentage rent on an **artificial breakpoint**: (sales − threshold) × rate | |
| A1.10 | **Payment terms 7 days** from issue | |
| A3.2 | **Accrual, revenue recognised at issue.** Straight-line rent (EAS 49) is built and **off** | |
| A3.4 | A closed period blocks back-dated posting | |
| A3.8 | **Reporting per property. Consolidated is NOT reachable today** — the books support it; the screens do not | |
| A5.2 | Payroll withholdings split into their own payable accounts | |
| A6.1 | **Egyptian tax depreciation 5 / 10 / 25 / 50%** (Law 91/2005 art. 25) | |
| A6.2 / A9.6 | Monthly depreciation run · bilingual payslips · per-asset useful life and salvage | |
| A7.2 / A7.5 | Deposit is a refundable liability with **no VAT**; discounts go through credit notes with approval | |
| A9.3 / A9.4 | CAM presented **gross**; inventory at per-movement unit cost (FIFO on receipts) | |
| A8.1 / A8.2 | 23 report pages · CSV + XLSX · saved views · scheduled email | |
| B.2 / B.9 | Co-owners with % and dates; the owner has oversight and requests, and approves nothing before Eltizam acts | |
| B2.3–B2.5 | Unit-owner **صيانة** is property revenue; no operator approval on a resale | |
| C1.1 | Unit types: retail · F&B · wellness · service · kiosk · office · storage | |
| C1.2–C1.6 | Renewal · escalation · early termination · **full** fit-out grace (rent, service, CAM and levy all suppressed) · manual sales declarations | |
| C2.1 / C2.2 | CAM pool contents and annual true-up; utilities **at cost**, no markup, no cap | |
| C2.3 | The SLA hour targets the breach scan alerts on | |
| C2.6 / C3.5 / C3.9 | Approval bands **1,000 / 10,000 EGP**; delete is super-admin only and **money records are never deletable** | |
| C3.3 / C3.4 | Warehouse categories are free text; one reorder level and quantity per item | |
| D.4 | Daily backups, weekly restore drill; a leaver is **deactivated**, never deleted | |
| E.3 | *"Admin (per mall) — full access"* does **not** include deleting records | |

---

## Part 7 · Do you need this? — yes means new work
## سابعًا · هل تحتاجون هذا؟ — نعم تعني عملًا جديدًا

**None of these blocks go-live.** Each is a real feature we have deliberately not built, because
building the wrong one is worse than building none. Answer **yes** or **no** — a *yes* becomes
scheduled work with an estimate.

| # | Question | السؤال | Yes / No |
|---|---|---|---|
| **A2.6** | **Tax-exempt tenants** — free zone, government, NGO, embassy? Today taxability is one answer for the whole portfolio | مستأجرون معفون من الضريبة؟ | |
| **A2.1** | **Do tenants withhold tax from your rent** and issue certificates you must track? *(The supplier side is built; the tenant side is not — a tenant who withholds looks like an underpayment for ever)* | هل يخصم المستأجرون ضريبة من الإيجار ويصدرون شهادات؟ | |
| **A3.3 / A7.3** | **Should rent billed in advance be deferred** and recognised over the period? | هل يُؤجَّل الإيجار المحصَّل مقدمًا ويُعترف به على مدى الفترة؟ | |
| **A7.1** | **Should security cheques be their own class**, separate from payment cheques? | هل تُصنَّف شيكات الضمان تصنيفًا مستقلًا؟ | |
| **A9.5** | **Accrue a leave provision monthly?** | هل يُحتسب مخصص إجازات شهريًا؟ | |
| **A9.8** | **A salary-tax return**, beside the VAT return and Form 41? | إقرار ضريبة مرتبات؟ | |
| **C1.8** | **Generate the lease contract as a PDF**, with signature tracked in-system? *(Uploading a signed lease already works)* | إصدار عقد الإيجار من النظام مع تتبع التوقيع؟ | |
| **C2.5** | **Recharge a tenant-caused repair to that tenant?** If yes: VATable or cost recovery? Parts only, or parts + labour + the vendor's invoice? | إعادة تحميل الإصلاح على المستأجر المتسبب؟ | |
| **C2.7** | **Must a vendor bill back an externally-bought part before the job can close?** | هل يجب فوترة القطع المشتراة خارجيًا قبل إغلاق أمر العمل؟ | |
| **C3.2** | **Inter-mall stock transfers as ONE action?** *(Same-property transfers already work. Cross-property is refused by design, because value would cross the property boundary with no journal entry — the documented path is adjust-out then receive-in)* | تحويل مخزون بين المولات كإجراء واحد؟ | |
| **C3.6** | **More than one approver for a large spend?** Today it is a single-level band per module | أكثر من معتمِد للمصروفات الكبيرة؟ | |
| **C3.8** | **Per service: billed out or absorbed as a unit expense** — plus an annual report either way | لكل خدمة: تُفوتر أم تُحمَّل كمصروف؟ | |
| **D.2** | **Paymob card payments — activate now or later?** *(Built and switched off; sandbox certified)* | مدفوعات البطاقات عبر Paymob: الآن أم لاحقًا؟ | |
| **C4.3** | **Training format, and which roles?** | شكل التدريب وأي الوظائف؟ | |

---

## Part 8 · Two sentences we cannot build from
## ثامنًا · جملتان لم نستطع البناء عليهما

Each needs **one clarifying sentence** — we stopped rather than guess.

| # | The wording | Why we stopped | Your answer |
|---|---|---|---|
| **E.1** | FR-REQ-01, *"delegation (from/to)"* | No such concept exists anywhere else in the system or in the rest of the requirements | `__________` |
| **E.2** | FR-PPM-01, *"Fixed maintenance"* | The requirements say **both** one-time and periodic, in different sentences. We built periodic | `__________` |

---

## How to return this

Any way that suits you — reply in the table, mark up a printed copy, or send the answers in a
message. **Parts 1–4 first**; the rest can follow. Part 6 needs nothing unless you want a line
changed.

> بأي طريقة تناسبكم — بالرد في الجدول أو على نسخة مطبوعة أو برسالة. **الأجزاء ١–٤ أولًا**،
> وما بعدها يمكن أن يتبع. والجزء السادس لا يحتاج ردًا إلا إذا أردتم تغيير سطر منه.
