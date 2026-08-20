# Atriom — configurability and Egyptian market fit

> **The question this answers.** *"Is the system flexible and dynamic in all aspects, as the market
> standard? And what needs to be configured to be dynamic/flexible for the Egyptian market?"*
>
> **Audited 2026-08-20** against the code at `main`, plus external research into Egyptian statute and
> market practice current to August 2026. Every code claim below carries a `path:line`; every
> statutory claim carries a source in [§3](#3-the-egyptian-statute-book-and-market-practice-2025-2026).
>
> **This is not the gap analysis.** [`gap-analysis/README.md`](gap-analysis/README.md) asks *"what does
> Yardi / the FM specialists / Odoo do that we do not?"* — a **product-parity** benchmark. This asks a
> different question on a different axis: *"what can the operator change without a developer, and does
> it match what Egyptian law and Egyptian mall practice will demand of it?"* A feature can be fully
> built and still fail this test. Where a row here overlaps the gap analysis it cites it rather than
> restating it.
>
> **Read with the same rule the gap analysis is written under:** *a row here is a claim about code —
> check it against code.* Absence claims below name the search that proved them.

---

## 0. The short answer

**No — the system is not "flexible and dynamic in all aspects", and no system truthfully is.** What it
*is*, is unusually strong on the axes it has already worked on, and unusually weak on four axes it has
not. The honest summary in one line:

> **Money, tax rates, the chart of accounts, approvals and reporting parameters are genuinely
> operator-owned. Time, statutory payroll, currency, and anything the operator wants to *word* or
> *classify* themselves are not.**

Three things make this urgent rather than academic, and all three are Egyptian:

1. **Egyptian law moved under a rule the system treats as fixed.** Law 157/2025 pulled property rental
   into the tax net (§3.1), and as of June 2026 the exact scope is *still being written into the
   executive regulations*. Atriom can change a tax **rate** without a deploy — but it cannot say
   *"this lease is taxed, that one is not"*, because taxability is portfolio-wide per charge code, and
   is additionally **frozen onto each lease's charge rows the day the lease is created** ([§4.1](#41-tax)).
2. **Statutory payroll numbers change every January and the system holds them as three flat, undated
   settings** — with no brackets, no personal exemption, and no insurable-wage cap ([§4.2](#42-payroll)).
   Egypt raised the insurable wage ceiling on 1 January 2026; Atriom has no place to put it.
3. **There is no working calendar.** Egypt's weekend is Friday–Saturday, and every SLA clock in the
   system is `now()->addHours()` ([§4.5](#45-time-and-the-working-calendar)). Vendor SLA penalties post
   real money off that clock.

The rest of this document is the evidence, then the worklist.

---

## 1. The verdict by axis

Rating: **DYNAMIC** = an operator changes it from a screen, no deploy · **SEMI** = a settings/config
value, or a row with a code-side twin that must move with it · **HARDCODED** = a code change ·
**ABSENT** = the capability does not exist.

| # | Axis | Rating | One-line verdict |
|---|---|---|---|
| 1 | **Tax rates** | 🟢 DYNAMIC | Best-in-class: dated rungs resolved for the *document's* date, editable at `/admin/tax-codes` |
| 2 | **Tax *treatment*** (who is taxed) | 🟠 SEMI | A row per charge code — but portfolio-wide, and frozen per lease at creation |
| 3 | **Chart of accounts / posting map** | 🟢 DYNAMIC | 51 roles re-pointable globally or per property; the real handover point for a new chart |
| 4 | **Billing policy** (late fee, terms, ageing, fiscal year, numbering) | 🟢 DYNAMIC | The CFG cycle shipped this properly, incl. 3-tier lease→property→portfolio resolution |
| 5 | **Approvals** | 🟢 DYNAMIC | `approval_rules` bands, fail-closed; no multi-level chain |
| 6 | **Roles** | 🟢 DYNAMIC | Full role CRUD with permission sync and audit; permission *keys* stay in code (correct) |
| 7 | **Reporting parameters** | 🟢 DYNAMIC | Saved views, per-user memory, scheduled email delivery, CSV+XLSX |
| 8 | **Reporting *shape*** | 🔴 HARDCODED | No report builder; every column is a PHP literal; statement layout is a `match()` on account type |
| 9 | **Master-data lists** | 🟠 MIXED | Trades, failure codes, charge codes, tax codes, SLA policies, rent indices = rows. Expense category, retail mix, request types, violation types, vendor document types = PHP/lang arrays |
| 10 | **Statutory payroll** | 🔴 HARDCODED | Three flat undated rates; no brackets, no exemption, no insurable-wage cap |
| 11 | **Time / working calendar** | 🔴 ABSENT | No weekend, no holidays, no business hours anywhere in `app/` |
| 12 | **Currency / FX** | 🔴 ABSENT | 15 tables carry `currency`; nothing reads it; no rate table; no GL currency |
| 13 | **Payment rails** | 🔴 HARDCODED | A PHP const in 4 parallel registries; adding one is a 9–14 file change |
| 14 | **Document & message wording** | 🔴 ABSENT | No template table, no rich editor, no mail tab — every invoice footer and dunning letter is a deploy |
| 15 | **Legal entity / multi-owner** | 🔴 ABSENT | One issuer, one TRN, one chart, one fiscal calendar for the whole install |
| 16 | **Custom fields (UDFs)** | 🔴 ABSENT | The single biggest structural gap vs Yardi/MRI/Odoo |
| 17 | **Localization (AR/EN)** | 🟢 DYNAMIC | 11,977 keys at exact parity, gated; RTL PDFs are best-in-class |
| 18 | **Workflow states** | 🟠 SEMI | Read-only visualiser over hardcoded transition matrices — *deliberately*, and correctly |

**Score: 7 green · 4 amber · 7 red.** The reds cluster, and the cluster has a shape: everything the
system decided was *engineering* got made configurable, and everything that is *someone else's
domain* — the accountant's wording, the HR manager's statute, the leasing manager's taxonomy, the
facility manager's calendar — did not.

---

## 2. What is already dynamic — do not rebuild these

Listed first, because the most expensive mistake this document could cause is a rebuild of something
that works. Each of these was verified in code during this audit.

| Capability | Where | Note |
|---|---|---|
| **Tax codes + dated rate rungs** | `/admin/tax-codes`; `app/Support/Vat.php`; `TaxCode::rateOn($code, $on)` | A rise can be entered *in advance* and starts applying by itself; a back-dated invoice keeps the rate in force on its own date. This is the correct shape and is the model every other statutory rate in the system should copy |
| **Charge codes end-to-end** | `app/Models/ChargeCode.php`; proven by `tests/Feature/Regression/AccountantAddedChargeCodeBillsTest.php:56` | An accountant can invent "key money" and have it bill *and* post, with no deploy |
| **Posting map, per property** | `app/Services/Accounting/AccountResolver.php:27-41`; `/admin/account-mappings` | 51 semantic roles re-pointable at any chart, globally or per mall |
| **Approval bands** | `app/Support/ApprovalPolicy.php:27-70`; `/admin/approval-rules` | Fail-closed: a gap in the ladder makes approval *harder*, never waives it |
| **Roles** | `app/Filament/Admin/Resources/Roles/`; audited via `AccessControlAudit` | Create, clone, sync permissions — `super_admin` only, correctly |
| **Per-property policy resolution** | `app/Support/PropertySettings.php:99-156`; `/admin/property-overrides` | Real 3-tier lease → property → portfolio; blank means inherit and says so twice |
| **Per-property SLA hours** | `sla_policies` + `app/Support/SlaResolver.php:36-77` | Deliberately kept *out* of `PropertySettings` to avoid two truths about one number |
| **Saved report views + scheduled delivery** | `SavesReportViews`, `SavedReport`, `reports:deliver` | Renders as the view's owner with `canAccess()` re-checked — a schedule stops when access is withdrawn |
| **Trades & failure codes as rows** | `Trade`, `FailureCode`, bilingual | The model the other lists should copy |
| **Bilingual master data** | `name_en`/`name_ar` on ledger accounts, charge codes, tax codes, trades, failure codes, equipment, tariffs | Operator-editable in both languages, no deploy |
| **Bank reconciliation** | `app/Services/Banking/*` | Candidates derive from the *ledger*, so every money source is included the day it ships |
| **Import column mapping** | Filament's stock header→column mapper, fully exposed (no `columnMapping` override anywhere) | The operator maps their own CSV headings per upload |
| **AR/EN parity + RTL PDFs** | 11,977 keys each way, zero mismatch; mPDF `xbriyaz` with OTL shaping | Gated by conformance tests |
| **Arabic-Indic digit folding on search input** | `app/Support/Search/SearchText.php:82-90` | Typing `٢٠٢٦` finds invoice 2026 — while output stays Latin, which is the Egyptian commercial norm. **Do not "fix" the output** |
| **Fiscal year start, document prefixes, AR ageing buckets** | `AccountingSettings`, `BillingSettings`, `DocumentNumbering` | All shipped by the CFG cycle; see `ROADMAP.md` §8.3 |

---

## 3. The Egyptian statute book and market practice, 2025–2026

The research that makes the rest of this document a priority list rather than an opinion.

### 3.1 Property rental has entered the tax net — and the rule is still moving

- **Law No. 157 of 2025** (issued 17 July 2025, effective 18 July 2025) amends VAT Law No. 67 of 2016.
  **Administrative-unit rental was added to taxable "commercial premises components", at a 1% schedule
  tax rate.** Separately, the **commercial trademark / trade-name component of administrative units
  became taxable at a 10% schedule tax charged on 10% of the rental or sale value** — previously exempt.
  Ministerial Decrees Nos. 417 and 418 (23 October 2025) refined the executive regulations.
- **June 2026:** parliament approved a further VAT package, and the government is **narrowing** a
  planned 14% VAT on leased administrative space, aiming it at "non-operational or non-service
  administrative premises". **Whether the existing 1% levy on administrative units inside malls
  survives alongside it, or is scrapped to avoid double taxation, is explicitly undecided** and will be
  settled in the executive regulations.
- ⚠️ **A correction worth recording, because it is easy to get wrong:** the widely-quoted rule that
  *"contracts signed on or after 18 July 2025 are taxed at 14%, earlier ones at 36% × 14% with no input
  deduction"* is about **construction contracts**, not property leases. Do not apply it to leases.

**Why this matters to Atriom:** the operator will need to bill VAT/schedule tax on *some* units and not
others, on a rule that is dated, unit-type-dependent, and expected to change again. Atriom resolves
taxability **per charge code, portfolio-wide** — there is no unit-level or lease-level tax dimension.

### 3.2 Payroll and employment — the numbers move every year

| Rule | Current position | Source |
|---|---|---|
| Social insurance — **minimum insurable wage** | EGP 2,300 → **2,700**, effective **1 Jan 2026** | NOSI |
| Social insurance — **maximum insurable wage** | EGP 14,500 → **16,700**, effective **1 Jan 2026** | NOSI |
| Employee contribution | 11% of the *insurable* wage (capped ≈ EGP 1,837), + Martyrs Fund 0.05% | — |
| Personal income tax | **Progressive, 7 brackets, 0% → 27.5%**; top band above EGP 1,200,000; personal exemption applies | ETA |
| **Labour Law No. 14 of 2025** (effective 1 Sep 2025) | Annual leave **15 days in year 1**, 21 from year 2, 30 after 10 years' service or age 50, 45 for employees with disabilities | — |
| Statutory annual raise | **At least 3% of the social-insured salary**, annually | Law 14/2025 |
| Overtime | Prior authorisation replaced by 7-day notification; 10-hour cap replaced by a **12-hour presence** cap | Law 14/2025 |
| Work on a public holiday | **3× the standard daily wage** | — |
| Ramadan | Working day reduced to **6 hours**, at full pay | — |

**The shape of the requirement, not just the numbers:** every one of these is *dated*. The min/max
insurable wage has now been raised on a January cadence for several years running. A system that holds
them as undated scalars must be edited by hand every January, cannot enter a change in advance, and
leaves no record of what was in force when a past run was computed.

### 3.3 Withholding tax on suppliers

Local withholding ("withholding and collection under account of tax") on payments **above EGP 300**:
**contracting and supplying 1%**, **services 3%**, **commissions 5%** *(PwC Worldwide Tax Summaries;
other published summaries quote 2% for services — the exact figure is the accountant's ruling, and this
is precisely why it belongs in a dated table rather than in code)*. Filed **quarterly on Form 41**, and
treated as an advance payment of corporate income tax, not an extra cost.

### 3.4 The working calendar

- The Egyptian working week is **Sunday–Thursday; the weekend is Friday–Saturday**.
- Standard hours 8/day, 48/week. **Ramadan: 6-hour days at full pay.**
- ~15 public holidays a year. **The two Eids move on the Hijri calendar and are set by moon sighting**,
  and since 2020 the government routinely **shifts mid-week holidays to Thursday**.

**The shape of the requirement:** Egyptian public holidays **cannot be computed** — they are announced.
They must be a table the operator maintains, refreshed annually.

### 3.5 Currency

Three devaluations since 2024 took USD/EGP from ~30 to **~50.5 (August 2026)**. The market response is
documented and consistent: **prime Cairo retail and Grade-A office leases are increasingly
USD-denominated or dollar-indexed**. Mall of Egypt's published leasing terms quote **base rent in
USD/sqm/year (25–40)**, with **turnover clauses at 8–12% over breakpoint** and **annual escalations of
5–7%**. Foreign-currency contracting is lawful provided settlement runs through licensed banks.

### 3.6 Other statutory context

- **Real-estate tax (Law 196/2008):** 10% of annual rental value, after a 32% deduction for
  non-residential use; units with net annual rental value under EGP 1,200 exempt; revaluation capped at
  +45% per five-year cycle. **Owner-borne**, assessed per property, on a fixed payment schedule.
- **Corporate income tax:** 22.5%; quarterly advance payments 20 Apr / Jul / Oct / Jan; return due
  30 April.
- **EAS 49** (2020, aligned to IFRS 16): a lessor recognises operating-lease income **straight-line**
  over the term, including rent-free periods. *(Atriom has this — see §2 note in `BillingSettings`.)*
- **PDPL — Law 151/2020 + Executive Regulations (Decree 816/2025, in force 2025):** controllers must
  keep secure electronic records documenting consent, data categories, **defined retention periods**,
  and applied security measures.
- **VAT invoice content (Law 67/2016):** a tax invoice must carry the seller's registration number and
  the purchaser's name and registration number where the purchaser is registered.

### 3.7 The market standard for "flexible and dynamic"

**Yardi Voyager 8** — the benchmark this project already uses for the money core — defines the bar as:
user-defined fields on core records, a **Report Builder** where the operator pulls fields, filters,
pivots and schedules delivery **with no coding**, **configurable workflows**, and role-based dashboards.
**MRI** competes on modularity and an open integration/partner ecosystem. **Odoo** ships fiscal
localizations as installable packages (chart of accounts, pre-configured taxes, payroll rules per
country) — the "localization pack" model.

Against that bar, Atriom's report **parameters** are at standard and its report **shape** is not; its
tax **rates** are above standard and its **workflow** configurability is below it; and user-defined
fields are absent entirely.

**Sources.** [EY — Egypt VAT updates](https://www.ey.com/en_gl/technical/tax-alerts/egypt-introduces-significant-vat-updates-on-certain-goods-and-services) ·
[International Tax Review — Law 157/2025 and the executive regulations](https://www.internationaltaxreview.com/article/2fypmi95q13u7glfrs8ao/sponsored/insights-from-egypts-updates-to-the-vat-law-and-its-executive-regulations) ·
[EnterpriseAM — government scaling back the 14% VAT on leased office space (23 Jun 2026)](https://enterpriseam.com/egypt/2026/06/23/govt-moves-to-scale-back-its-planned-14-vat-on-leased-office-space/) ·
[State Information Service — VAT reform package](https://sis.gov.eg/en/media-center/news/egypt-passes-vat-reform-package-to-support-industry-healthcare-and-investment/) ·
[Mondaq — Understanding Egypt's VAT amendments for 2026](https://www.mondaq.com/tax-authorities/1799728/understanding-egypts-vat-amendments-for-2026) ·
[PwC — Egypt withholding taxes](https://taxsummaries.pwc.com/egypt/corporate/withholding-taxes) ·
[PwC — Egypt other taxes](https://taxsummaries.pwc.com/egypt/corporate/other-taxes) ·
[Zawya — NOSI raises insurable wage limits from January 2026](https://www.zawya.com/en/economy/north-africa/egypt-nosi-raises-insurable-wage-limits-starting-january-2026-lv4id0fw) ·
[Mercans — Egypt insurable wage limits 2026](https://mercans.com/resources/statutory-alerts/egypt-minimum-and-maximum-insurable-wage-limits-increase-for-social-insurance-from-2026/) ·
[Andersen — Egypt personal income tax 2026](https://eg.andersen.com/personal-income-tax/) ·
[EY — Egypt's new labour law from 1 Sep 2025](https://www.ey.com/en_gl/technical/tax-alerts/egypt-enacts-new-labor-law-with-changes-affecting-employers-beginning-1-september-2025) ·
[Amereller — Labor Law No. 14 of 2025](https://amereller.com/publication/egypt-new-labor-law-no-14-of-2025/) ·
[Andersen — translation of Law 196/2008 (real-estate tax)](https://eg.andersen.com/translation-law-196-2008/) ·
[Al Tamimi — PDPL executive regulations](https://www.tamimi.com/law_update_articles/from-policy-to-practice-egypt-issues-executive-regulations-of-the-personal-data-protection-law/) ·
[Andersen — 2026 corporate tax calendar](https://eg.andersen.com/2026-corporate-tax-calendar/) ·
[Mondaq — financial leasing in Egypt: IFRS 16 and EAS](https://www.mondaq.com/accounting-standards/1593126/financial-leasing-in-egypt-ifrs-16-and-accounting-standards) ·
[AmCham Egypt — counting on commercial space](https://www.amcham.org.eg/publications/industry-insight/issue/75/counting-on-commercial-space) ·
[Thunes — Egypt's payments transformation](https://www.thunes.com/insights/trends/egypts-payments-transformation-a-regional-hub-in-the-making/) ·
[Yardi — Voyager 8 for CRE](https://www.yardi.com/blog/voyager-8-commercial-real-estate-erp/) ·
[Odoo — Egypt fiscal localization](https://www.odoo.com/documentation/18.0/applications/finance/fiscal_localizations/egypt.html) ·
[WUZZUF — Egypt public holidays 2026](https://wuzzuf.net/careers/official-public-holidays-in-egypt/)


---

## 4. Findings

### 4.1 Tax

The rate mechanism is the best thing in the system. The *treatment* mechanism is one dimension short
of what Egyptian law now requires, and is additionally frozen per lease.

| # | Finding | Where | Rating |
|---|---|---|---|
| T-1 | **Taxability has no lease or unit dimension.** `Vat::rateForType($type, $on)` resolves *charge code → tax code → dated rate*. There is no third input. Whether base rent is taxed is one answer for the whole portfolio | `app/Support/Vat.php:125-140`; `app/Models/ChargeCode.php` | 🔴 |
| T-2 | **`vat_applicable` is frozen onto each recurring charge row at creation**, from whatever the catalogue said that day: `'vat_applicable' => Vat::rateForType('base_rent') > 0`. `Charge::resolvedVatRate()` short-circuits to `0.0` when it is false, so **an accountant's later ruling can never reach an existing lease** | `app/Services/LeaseCreationService.php:127`; `app/Models/Charge.php:289-295`; and 10 sibling sites: `LeaseRentChangeService.php:109,116` · `LeaseSpaceChangeService.php:229` · `ConvertLeaseToHoldoverService.php:136` · `MarketingLevyService.php:81` · `ApplyCamEstimateService.php:90` · `ChargeScheduleService.php:541` · `AssignRentableItemService.php:221` · `ChargeScheduleRelationManager.php:307` · `UnitOwnershipChargesRelationManager.php:232` · `ChargeImporter.php:135` | 🔴 |
| T-3 | **Base rent additionally writes a non-null `vat_rate` override (0.00)**, re-introducing at the *rate* layer exactly what migration `2026_08_12_200000_charge_vat_rate_is_an_override` removed. The service-charge block **two lines below does it correctly** (`'vat_rate' => null` with the comment *"null = the catalogue answers at billing time; a value is an override"*) — so the same file contains both the bug and its fix | `app/Services/LeaseCreationService.php:128` vs `:145`; also `LeaseSpaceChangeService.php:230` · `ConvertLeaseToHoldoverService.php:137` · `AssignRentableItemService.php:222` | 🔴 |
| T-4 | **Making base rent taxable turns a conformance gate red.** `ChargeCodeVatTreatmentConformanceTest` asserts the catalogue's exempt set `toEqualCanonicalizing(Vat::EXEMPT_TYPES)` — so the accountant's row change must be paired with a PHP edit. Correct as a design (floor and catalogue must agree), but it means *"change the tax treatment of rent"* is **not** a no-deploy operation | `tests/Feature/Scenarios/ChargeCodeVatTreatmentConformanceTest.php:36`; `app/Support/Vat.php:EXEMPT_TYPES` | 🟠 |
| T-5 | **A new withholding rate cannot be entered from the screen.** The rates relation manager sets `->minValue(0)` while every `WH_*` rung is negative by construction and the conformance test *requires* it | `app/Filament/Admin/Resources/TaxCodes/RelationManagers/RatesRelationManager.php:46`; `database/seeders/TaxCodeSeeder.php:219` | 🟠 |
| T-6 | **WHT engine is correct and dated; the filing artefact is missing.** Per-vendor code → portfolio default → 0, resolved for the *payment* date, withheld on the VAT-exclusive share, posting `Cr withholding_tax_payable`. But there is **no Form 41 report and no per-vendor certificate** — `VatReturn.php` exists with no WHT sibling | `app/Support/WithholdingTax.php:63-103,145-174`; `app/Services/Accounting/Journalizers/VendorBillPaymentJournalizer.php:57` | 🟠 |
| T-7 | **Tenant-side WHT unmodelled** — a tenant who withholds from rent reconciles as an underpayment forever | `docs/OPEN-QUESTIONS.md` A2.1 | 🟠 |
| T-8 | **Real-estate tax and municipal levies are not a statutory cost.** Recovery via a CAM pool works; the *liability* has no rate, no rental-value basis, no assessment, no due dates. There is **no recurring-expense concept anywhere** in the system — recurrence exists only on the revenue side | grep `recurring` over `app/Models` + `app/Services` → revenue-side only | 🟠 |
| T-9 | **Tax depreciation rates are PHP constants** (5/10/25/50%, Law 91/2005 art. 25) | `app/Support/TaxDepreciation.php:52-58` | 🟡 |
| T-10 | **One seller identity for the whole install.** `seller_tax_registration_number` and `seller_legal_name` are single portfolio-wide settings; `IssuingEntity` documents the intended per-asset override and does not have it. Two owners with two VAT registrations cannot both be billed correctly | `app/Settings/TaxSettings.php:65,68`; `app/Support/IssuingEntity.php:27-30,69,87` | 🔴 |

> **The concrete Egyptian scenario T-1..T-4 fails.** Suppose the executive regulations land and the
> accountant rules: *administrative units in the mall carry 1% schedule tax; retail shops stay
> exempt.* Today that requires (a) a second charge code, since taxability is per code; (b) a PHP edit
> to `Vat::EXEMPT_TYPES` and the gate; and (c) **it still would not reach any existing lease**,
> because every rent charge row already carries `vat_applicable = false` frozen at creation. The fix
> is not a bigger settings screen — it is a **lease/unit-level tax treatment resolved at billing
> time**, plus stopping the eleven origination sites from freezing the answer.

### 4.2 Payroll

The one area where the books may be quietly wrong on data the system already holds.

| # | Finding | Where | Rating |
|---|---|---|---|
| P-1 | **No insurable-wage floor or ceiling.** The SI rate is applied to the employee's whole `base_salary`. Every employee above the ceiling is over-deducted and the employer over-accrues — and Egypt raised the ceiling to EGP 16,700 on 1 Jan 2026. The employer-share line even carries the comment *"Employer SI is a company cost — it does NOT reduce net, so no cap needed"*, which is not how the Egyptian cap works (it caps the **wage**, both shares) | `app/Services/GeneratePayrollService.php:65,72,143`; `app/Settings/PayrollSettings.php:16` describes "a capped subscription salary" and implements no cap | 🔴 |
| P-2 | **Income tax is a single flat rate against full gross.** No brackets, no personal exemption — neither exists anywhere in the codebase. A flat rate cannot approximate a 7-band progressive schedule. Ships at `0.0`, so out of the box **no salary tax is withheld at all** | `PayrollSettings.php:25`; `GeneratePayrollService.php:45,142` | 🔴 |
| P-3 | **No dated payroll rates.** `generate()` reads the settings with **no date argument**, so a January run generated in March uses March's numbers, a rise cannot be entered in advance, and nothing records what rate a past run used. Contrast `TaxCode::rateOn($code, $on)`, which is the correct shape sitting in the same codebase | `GeneratePayrollService.php:44-47` vs `app/Models/TaxCode.php:243,314-331` | 🔴 |
| P-4 ✅ | **FIXED 2026-08-20 (EG-04).** ~~No payroll row in `ConfigurationHealth`.~~ Six checks exist; none mentions payroll. The install ships `salary_tax_rate = 0` and `social_insurance_rate = 0` and nothing says so | `app/Support/ConfigurationHealth.php` | 🟠 |
| P-5 | **Gratuity day-counts are undated**, so editing one **retro-restates every past year of every employee's accrual**. Ships off and posts nothing, which is the only reason this is not 🔴 | `PayrollSettings.php:48-54`; `app/Services/GratuityService.php:64-70` | 🟠 |
| P-6 | **Overtime, leave and the statutory annual raise are wholly absent** — no hours, no multiplier, no leave model, no salary history, no scheduled raise. Searched `overtime\|annual_leave\|leave_days\|sick_leave\|salary_increase\|علاوة\|إجازة` across `app/ database/ config/ lang/ routes/`: the only hit is a Maximo benchmark doc | absence proven by the searches named | 🟠 |
| P-7 | **Allowances and deductions are single decimal columns**, so taxable vs non-taxable allowances cannot be distinguished — which is exactly the input a bracket engine needs | `app/Models/PayrollLine.php:32,36-37` | 🟠 |
| P-8 | **Employee master lacks `contract_type` and `insurance_status`** — and insured status is the precise input the gratuity toggle needs, which is why that feature has to be a single portfolio-wide switch | `database/migrations/2026_07_04_140001_create_employees_table.php:18-37` | 🟠 |
| P-9 | Payroll GL split (salary / SI / tax / net) resolves through `account_mappings` with per-property override | `app/Services/Accounting/Journalizers/PayrollJournalizer.php:66-99` | 🟢 |

### 4.3 Money and billing

| # | Finding | Where | Rating |
|---|---|---|---|
| M-1 | **Proration is one hardcoded method** — actual days ÷ that month's own length. Yardi ships 30/360, actual/365 and whole-month because leases say different things. Every move-in, move-out, rent-commencement and final cycle runs through this line; a lease saying "1/30th per day" is billed wrong in seven months of the year | `app/Services/MonthlyBillingService.php:338` | 🔴 |
| M-2 | **Billing in advance only, cycle anchored to the lease.** No arrears option; a service charge or utility recharge billed in arrears has no home | `MonthlyBillingService.php:367-371,548`; `DeterminesFitOutGrace.php:185-199` | 🟠 |
| M-3 ✅ | **FIXED 2026-08-20 (EG-19).** ~~`config/billing.php:14-16` late-fee keys are dead and look alive.~~ They read `env('LATE_FEE_PERCENT')` etc.; nothing consumes them (the 12 `billing.late_fee_*` hits in `app/` are `PropertySettings` keys in a same-named namespace, not `config()` reads). A deployer setting `LATE_FEE_PERCENT=3` gets silence. **Cheapest fix in this document: delete the three keys** | `config/billing.php:14-16`; live path is `PropertySettings.php:49-57` + `LateFeeService.php:107-109` | 🟠 |
| M-4 ✅ | **FIXED 2026-08-20 (EG-20).** ~~`leases.billing_day` is an inert column~~ — fillable, cast, migration comment *"day of month to issue invoice"*, **zero readers**. Combined with M-5, a multi-mall operator has exactly one billing date for the whole portfolio | `app/Models/Lease.php:373,411` | 🟠 |
| M-5 | **`monthly_billing_day` is portfolio-wide and capped at 28.** One mall cannot bill on the 25th while another bills on the 1st. Same for `auto_apply_tenant_credit`. Both are **one line each** in `PropertySettings::OVERRIDABLE` away from being per-property — the override screen is registry-derived, so no UI work | `app/Settings/BillingSettings.php:71,73`; `app/Support/PropertySettings.php:48-69` | 🟠 |
| M-6 | **Escalation is annual-only** — `->addYear()`, and no `escalation_frequency` column exists. A biennial or 18-month clause cannot be automated | `app/Services/RentEscalationService.php:196` | 🟠 |
| M-7 | **`leases.escalation_type` has no `ValueSets` entry** — a freed `string(32)` column whose options live in a **translation array**. The wildcard save-listener does not refuse out-of-set values here, so an import can write `annual_increase` and the sweep silently skips that lease forever. This is the exact pattern CLAUDE.md bans after the `Trade.category` episode | `lang/en/admin/statuses.php:311`; migration `2026_08_10_240000_...:44`; absent from `app/Support/ValueSets.php` | 🔴 |
| M-8 | **Late fees have no cap and no compounding option.** One fee per invoice; a large arrears produces an uncapped penalty and a six-months-late tenant is penalised once. Neither is settable, and both are things a real clause states | `app/Services/LateFeeService.php:130-135,158` | 🟠 |
| M-9 | **Document numbers reset monthly, per property, by construction** — only the prefix letters are configurable; the mask is a `sprintf`. Egyptian tax-invoice series are conventionally expected to run continuously. Worth a deliberate decision **before go-live**, because it is not renumberable afterwards | `app/Models/Concerns/Invoice/AllocatesInvoiceNumber.php:33,38-44` | 🟠 |
| M-10 | **Rounding is 2dp with PHP's default mode, in 540 places.** No `PHP_ROUND_HALF_*` anywhere, no config. An accountant asking for banker's rounding cannot have it | 540 `round(…, 2)` under `app/` | 🟡 |
| M-11 | **No deposit default at portfolio or property level** — months-of-rent is per lease only, so a policy change ("3 months from Q1") reaches nothing | `PropertySettings::OVERRIDABLE` has no deposit key | 🟡 |
| M-12 | **CAM reconciliation is annual-only** on a scheduled month/day; a quarterly true-up cannot be automated | `routes/console.php:78-84` | 🟡 |
| M-13 | Late-fee rate/grace/minimum resolve lease → property → portfolio; **best-in-class** | `app/Models/Concerns/Lease/ActsAsBillableAgreement.php:51-71` | 🟢 |
| M-14 | CAM caps (absolute / YoY / both, base year, compounding, carry-forward, scope) and percentage-rent tiers are **effective-dated rows at Yardi parity** | `app/Models/LeaseCamTerm.php:28-66`; `app/Models/LeasePercentageRentTier.php` | 🟢 |
| M-15 | **All 21 settings properties in the four money settings classes are exposed on a screen. Zero orphans** | `app/Filament/Admin/Pages/Settings.php` via `SettingsRegistry` | 🟢 |
| M-16 | `lang/en/admin/help.php:137` advertises a **"Step"** escalation type the Select does not offer. The capability exists by another mechanism (dated `charges` rows), so the help points at a door that isn't there | `lang/en/admin/help.php:137` | 🟡 |

### 4.4 Master data — what the operator can classify

**The rule that emerges:** anything reached by a *row* is extensible; anything reached by a **PHP
enum, class constant, or `lang/` array** is a deploy. Five of the second kind are lists an Egyptian
operator will want to change in week one.

| # | List | Storage | Where | Rating |
|---|---|---|---|---|
| D-1 | **Expense / vendor-bill category** — six values, and **the only thing deciding which P&L account a supplier bill hits**, via a `private const` in a trait. *Insurance, government fees & licences, bank charges, legal & professional, fuel/generator* all silently collapse into `other → admin_expense` with a `Log::warning`. Also drives `CostNature` (fixed vs variable) and the CAM pool | LANG-ARRAY + private const | `lang/en/admin/statuses.php:371`; `app/Services/Accounting/Journalizers/Concerns/MapsExpenseCategory.php:17-23`; `app/Support/CostNature.php:29-36` | 🔴 |
| D-2 | **Tenant retail category / merchandising mix** — 12 hardcoded values driving the store directory, the public API filter and all tenant-mix analysis. In Yardi and MRI this is a row, revised per mall and per season | PHP-CONST | `app/Models/Tenant.php:54-67` | 🔴 |
| D-3 | **Tenant request types + subcategories + their SLA hours** — a PHP enum with a `match()`; **and it has already drifted from the trades register.** `tradeForRequest()` matches `tenant_requests.category` against `trades.code`, but maintenance subcategories are 7 values while `trades` seeds 14. **A tenant cannot report a stuck lift, a generator failure, a fire-safety fault, a pest problem or a security issue as such**, and no operator-added trade is ever reachable from the tenant-request path. The enum's own docblock concedes *"Phase 2 reads these from settings/the request_types table"* | PHP-ENUM | `app/Enums/TenantRequestType.php:23-30,76-84,120-134`; `app/Services/RaiseCorrectiveWorkOrderService.php:139-144` | 🔴 |
| D-4 | **Violation categories** — seven values, where the migration that created the column **promised the opposite in writing**: *"the operator's set of violation types is theirs to extend without a migration"* | PHP-CONST | `app/Models/Violation.php:65`; `database/migrations/2026_07_23_180000_add_category_to_violations.php:13` | 🟠 |
| D-5 | **Vendor compliance document types** — six fixed types gate the COI chase and dispatchability. Egyptian vendor compliance varies (social-insurance certificate, tax clearance, civil-defence licence) | LANG-ARRAY | `lang/en/admin/vendors.php:23` | 🟠 |
| D-6 | **Departments** — rows and screen both exist; `canCreate()` simply `return false;`. Frozen at five seeded **English-only** names with no `name_ar`, inside an otherwise bilingual panel. Lowest effort on this list | ROW, seed-only | `app/Filament/Admin/Resources/Departments/DepartmentResource.php:43-46` | 🟠 |
| D-7 | **No custom fields / UDFs anywhere.** Zero hits for `custom_field`; five `metadata` JSON columns with no reader in any UI, service or report. **The single biggest structural gap vs Yardi UDFs / MRI user-defined fields / Odoo Studio** | ABSENT | searches named | 🔴 |
| D-8 | **`ValueSets` covers only the 62 columns that were DB enums on 2026-08-12.** ~25 classification columns added since are outside the registry — including `facility_work_orders.status`, which the transition matrix branches on. `NoDatabaseEnumsConformanceTest` never asks *"is this new column registered?"* | gate gap | `tests/Feature/Scenarios/NoDatabaseEnumsConformanceTest.php:75-131` | 🟠 |
| D-9 | Trades, failure codes, charge codes, tax codes, SLA policies, rent indices, utility tariffs, areas, approval bands, roles — **all rows, bilingual, operator-editable** | ROW | — | 🟢 |
| D-10 | **Correctly code-coupled and should stay so:** every workflow status the code branches on; posting-role *names*; tax `family`/`direction`/`treatment`; `fixed_assets.tax_pool` (Law 91/2005); `failure_codes.type`; `ledger_accounts.type`; permission keys; `EgyptGovernorates` | — | — | 🟢 |

### 4.5 Time and the working calendar

**The most clear-cut gap in the audit, and the most Egyptian.**

| # | Finding | Where | Rating |
|---|---|---|---|
| C-1 | **There is no working calendar.** Searched `isWeekend\|isWeekday\|dayOfWeek\|Carbon::FRIDAY\|nextWeekday\|businessDay\|working_day\|business_day` across `app/ config/ database/ resources/` — **one hit, in report scheduling.** Every SLA clock is `created_at->addHours(n)` | `app/Models/FacilityWorkOrder.php:491,497`; `app/Services/TenantRequestService.php:158,565`; `app/Services/FacilityWorkOrderService.php:91` | 🔴 |
| C-2 | **No public-holidays table.** `ls database/migrations \| grep -i "holiday\|calendar\|working"` → nothing. Egypt has ~15 public days; **the Eids move on the Hijri calendar and are set by moon sighting**, and mid-week holidays are routinely shifted to Thursday — so they can only ever be a table the operator maintains annually. **This gap is not recorded in the gap analysis** — it is unknown, not deferred | absence proven by the searches named | 🔴 |
| C-3 | **This is not cosmetic — it posts money.** Vendor SLA penalties are computed off the same clock and journalised. A 24-hour urgent job raised Thursday 17:00 is due Friday 17:00 with the engineering team off; the resulting penalty is a payable an Egyptian contractor will contest and win | `SlaPenaltyJournalizer` | 🔴 |
| C-4 | **No business hours, no Ramadan hours.** `business_hours\|opening_hours\|trading_hours\|work_start\|shift_start` → **zero**. The only "Ramadan hours" mechanism in the system is an announcement a human types | absence proven | 🟠 |
| C-5 | **PM compliance compounds it** — strict whole-day comparison with no tolerance window, so a PM due Friday is "overdue" on Saturday although nobody worked | `app/Models/Concerns/FacilityWorkOrder/TracksPmCompliance.php:44-47` | 🟠 |
| C-6 | **Reporting weeks are Mon–Sun**, hardcoded, splitting Egypt's Sun–Thu business week across two buckets | `app/Services/Reports/ReportService.php:170-187`; `WeeklySpend.php:81-83`; `ReportHub.php:190` | 🟡 |
| C-7 | Calendar days for AR due dates, ageing and late-fee grace is **correct and matches Yardi** — and the numbers are configurable. Do not change this | `BillingSettings.php:41,45`; `app/Support/AgingBuckets.php` | 🟢 |
| C-8 | No Hijri calendar support. `hijri\|umalqura\|islamic` → zero hits. **Correctly low priority** — Gregorian is the Egyptian commercial norm; a Hijri reference line on tenant documents is a nice-to-have | — | ⚪ |

> **The fix has a model already in the codebase.** `SlaResolver` resolves SLA hours through a
> three-tier chain ending in `sla_policies` rows. A `WorkingCalendar` support class — working days +
> working hours + a `holidays` table, with a per-property override on the same three-tier shape —
> would slot in beside it, and C-5 and C-6 fall out of the same registry.

### 4.6 Currency, payment rails and integrations

| # | Finding | Where | Rating |
|---|---|---|---|
| X-1 | **No FX of any kind.** `exchange_rate\|fx_rate\|conversion_rate\|base_currency\|functional_currency` across `app/` and `database/migrations/` → **zero hits**. `journal_lines` carries no currency or rate column | absence proven | 🔴 |
| X-2 | **…and `currency` looks like it works.** 15 tables carry `currency string(3) default 'EGP'`, 8 models hard-set `'EGP'` on create, **260 `->money('EGP')` display calls read none of them**, and zero currency comparisons exist anywhere (`currency !==` → 0 hits) | `app/Models/Invoice.php:472` et al. | 🔴 |
| X-3 | **The sharpest edge in the audit:** the vendor-contract form offers a currency Select of **EGP/USD/EUR/GBP/SAR/AED** one line below an amount field hardcoded `->prefix('EGP')`. Pick USD today and a USD number posts to an EGP ledger at 1:1, silently. **Either remove the non-EGP options or build the rate** | `app/Filament/Admin/Resources/Vendors/RelationManagers/ContractsRelationManager.php:117-126` (verified) | 🔴 |
| X-4 | **The cheaper path is probably the right one.** Egyptian malls overwhelmingly write **USD-indexed, EGP-denominated** leases — the rent *amount* moves with a published rate while the books stay single-currency. That is an addition to `RentEscalationService` (which already steps rent on a schedule and already reads a dated `rent_indices` register), needs **no GL change**, and is **M** effort against **XL** for true multi-currency. Worth putting to the client as the actual question behind open question A7.4 / Q-F | `app/Services/RentEscalationService.php`; `app/Models/RentIndex` | — |
| X-5 | **A payment rail cannot be added without a deploy** — 9–14 files including two lang catalogues, a hardcoded 7-value expectation in `TranslationCoverageTest.php:54`, and two `->only()` filter lists. `ValueSets`' own docblock names the failure mode — *"Egypt's payment rails keep moving: Fawry, Meeza, Aman, Vodafone Cash"* — and then keeps them in a `const`. **InstaPay is present; Fawry, Meeza and BNPL are not** | `app/Support/ValueSets.php:141,59-63` | 🟠 |
| X-6 | **Every non-cash rail debits one `bank` account on capture day** — `$cashRole = $method === 'cash' ? 'cash' : 'bank';`. No clearing account, no undeposited funds, no PSP receivable (`PostingRoles` has only `cash` and `bank`). **The bank reconciliation just built will show a gross unmatched population every month**, because the book line is dated capture and the bank line is dated settlement (T+1/T+2 for Paymob, longer for Fawry). This surfaces on the *first real reconciliation* | `app/Services/Accounting/Journalizers/PaymentJournalizer.php:75` + 9 siblings | 🔴 |
| X-7 | **Nothing records which bank account a receipt landed in.** The register exists and `BankAccount::ledger_account_id` exists — **no journalizer reads it**; the only `bank_account_id` FK in the schema is on `bank_statements`. With two banks in one mall the matcher will offer the *other* bank's postings as candidates | `app/Models/BankAccount.php:18-23`; `docs/accounting/BANK-RECONCILIATION-PLAN.md:92-98` | 🟠 |
| X-8 | **Four parallel payment-method registries that have already drifted** — `payments.method` (7) · `vendor_bill_payments.method` (5) · `deposit_transactions.method`/`expenses.paid_from` (2) · `Disbursement::METHODS` (3, outside `ValueSets` entirely). Concretely: **a security deposit received by InstaPay cannot be recorded as InstaPay** | `ValueSets.php:91,96,141,180`; `app/Models/Disbursement.php:42-48` | 🟠 |
| X-9 | **No gateway abstraction.** `PaymobPaymentInitiator` is concrete at four call sites, `'gateway' => 'paymob'` is a literal, and the callback re-finds rows by `where('gateway','paymob')`. Adding Fawry/Kashier/Geidea is a project. Paymob config is also **global, not per-property** | `app/Providers/AppServiceProvider.php:48`; `config/integrations.php:38-77` | 🟠 |
| X-10 | **No SMS, no WhatsApp.** Every major provider searched (`twilio\|vonage\|unifonic\|smsmisr\|victorylink\|360dialog\|gupshup`) → zero. The WhatsApp stub was honestly deleted rather than left as a fake. With push shipping off, **tenant-facing reach today is bell + email only** — and Egyptian retailers answer WhatsApp, not email | `database/settings/2026_08_11_200000_delete_whatsapp_toggle.php` | 🟠 |
| X-11 | **Notification routing is ~15 PHP literals.** No screen, no table, no settings group; `super_admin` is hard-unioned into every operator fan-out; there is no opt-out and no per-tenant channel choice | `app/Services/TenantRequestService.php:200`; `app/Support/AssetStaffRecipients.php:34` | 🟠 |
| X-12 | **Turning a module off does not stop its scheduled work.** One of ~30 scheduled commands checks the flag. Disable `facility` and the nightly PM generator keeps writing work orders and alerting staff about screens they can no longer open. Also `Modules::enabled('billing')` is a **phantom key** that always returns true | `routes/console.php:172-201`; `app/Filament/Admin/Pages/BillingRunPreview.php:73` | 🟠 |
| X-13 | **No third-party API and no outbound webhooks.** `/api/v1` is excellent — versioned, OpenAPI-generated, contract-tested — but it is a *tenant* API: one token-minting site, `['tenant:*']` abilities, no API-key model, no partner scopes. A POS feed or an owner's accounting package has no door | `app/Actions/Api/Auth/LoginTenantAction.php:44` | 🟠 |
| X-14 | Bank statement import + ledger-derived matching + reconciliation statement are **built and good** — candidates come from the ledger, so every money source is included the day it ships, and a match posts nothing | `app/Services/Banking/*` | 🟢 |

### 4.7 Structure, reporting and documents

| # | Finding | Where | Rating |
|---|---|---|---|
| S-1 | **There is no legal entity.** No model, no table, no column. Everything is one company. `docs/operations/GO-LIVE.md:159` already states the consequence for the Jawad/Eltizam revenue split | absence proven; `app/Support/IssuingEntity.php:27-30` | 🔴 |
| S-2 | **One chart, one fiscal calendar, for the whole install.** `ledger_accounts.code` is globally unique; `fiscal_years.year` is globally unique with no `asset_id`; periods are exactly 12 calendar months; the start month is **refused once anything is posted**. Pick the wrong month before go-live and no owner on a different year-end can ever be onboarded | `2026_06_30_000001_...:18`; `2026_06_30_000002_...:18,25-36`; `app/Services/Accounting/FiscalCalendar.php:42-53` | 🔴 |
| S-3 | **Consolidated books exist in the service layer and are unreachable in the panel.** The property switcher never offers "All Properties" and the report picker is pinned+disabled. Combined with `whereIn('je.asset_id', $ids)` never matching NULL, **any operator-level or cross-property journal entry is invisible in every financial statement an operator can open.** Module 21's doc still advertises "per-property & consolidated" | `app/Models/User.php:151-153`; `app/Support/Filament/PropertyField.php:146-147`; `LedgerReportService.php:472` | 🔴 |
| S-4 | **Financial-statement layout is a PHP `match()` on `ledger_accounts.type`**, and the chart's own `parent_id` rollup hierarchy is read by **no report**. The cash-flow statement classifies by **literal code prefixes** (`111`, `121`, `12`, `22`…). **If the accountant hands over a different Egyptian chart:** a chart not numbered 1–5 by nature is *refused at save*; one numbered 1–5 with different sub-ranges *saves fine and silently misclassifies the cash-flow statement* — and `reconciled` will not catch it, because it only re-asserts the double-entry identity. There is also **no chart importer** | `LedgerReportService.php:163-227,280-303`; `app/Models/LedgerAccount.php:39-45,131-148,197-209` | 🔴 |
| S-5 | **No report builder.** 23 catalogued reports, every column a PHP literal, no user-defined columns or groupings. The *parameter* layer is genuinely good (saved views, per-user memory, scheduled delivery, CSV+XLSX) but saves **filters/sort/search/tab only, never columns**. Against Yardi's Report Builder this is the largest ongoing cost multiplier per additional owner | `app/Support/ReportCatalogue.php:85-118`; `app/Models/TableView.php:59-69` | 🔴 |
| S-6 | **No operator-editable document or message templates anywhere.** No `document_templates` table, no terms/footer settings field, **no `RichEditor` in the entire app**, no mail tab on the settings page. Every invoice footer, dunning letter and SLA email is a deploy. **This is the single largest "the operator cannot run their own business" gap** | searches named; `app/Filament/Admin/Pages/Settings.php:91-98` | 🔴 |
| S-7 | **A fake `.test` address prints on every issued invoice PDF.** `__('admin.pdf.footer')` interpolates `billing@:slug.test` — rendering e.g. `billing@atriom-walk.test` on a legal tax document, and on tenant/asset statements. Verified in all four lang files. **One settings field plus four string edits; cheapest item in this report and the most embarrassing** | `resources/views/invoices/pdf.blade.php:332`; `lang/en/admin/reports.php:324`, `lang/ar/admin/reports.php:323`, `lang/en/admin/accounting.php:439`, `lang/ar/admin/accounting.php:432` | 🔴 |
| S-8 | **No mall logo on any PDF.** `Asset` already has a `logo` media collection; the 16 templates simply never reference it. Small change, very high perceived value — it is the first thing every operator asks for | `app/Models/Asset.php:106-117`; 16 templates under `resources/views/` | 🟠 |
| S-9 | **`ext-intl` is undeclared while 260 money columns depend on it.** `composer.json` `require` has no `ext-*` at all; `Number::currency()` throws without intl. The codebase already documents the hazard for a different call site. **A deploy box without intl 500s every list and dashboard showing money** | `composer.json:11-28`; `app/Support/Search/SearchText.php:111-113` | 🔴 |
| S-10 | **21 of 23 email templates are LTR.** `resources/views/vendor/` does not exist, so everything except `invoice-issued` renders Arabic inside Laravel's stock left-aligned frame. The PDF layer already solved this and can be copied | `resources/views/emails/invoice-issued.blade.php:1-7` is the only RTL-aware one | 🟠 |
| S-11 | **The tenant portal has zero white-labelling** — `->brandName('Atriom · Tenant Portal')` is an untranslated English literal, logo and favicon are static assets, no `primary_color` hook. The admin panel already does per-property branding properly | `app/Providers/Filament/PortalPanelProvider.php:51,54-57` | 🟠 |
| S-12 | **29 journalizers write Arabic prose literals into `journal_entries.description_ar`** at post time. This directly contradicts the project's own *"stores DATA, never PROSE"* rule: a wording fix needs a deploy **and** never reaches rows already posted. The fix pattern (`name_en`/`name_ar`, or `ActivityVocabulary`'s read-time resolution) is already in the codebase | `app/Services/Accounting/Journalizers/*.php` | 🟠 |
| S-13 | **English month names inside Arabic sentences** on the public payment page, the invoice email and the owner-statement PDF — raw `format('F Y')` instead of the `->locale()->isoFormat()` pattern used 36 times elsewhere | `resources/views/pay/show.blade.php:55,58`; `emails/invoice-issued.blade.php:29,35,36`; `owner-statements/statement.blade.php:58-59` | 🟡 |
| S-14 | **Per-property policy is a good mechanism with a five-key allow-list** — **5 of 59** settings fields are per-property. Billing day/time, CAM reconciliation dates, holdover rate, straight-line toggle, marketing levy %, ageing buckets, lease default term and auto-apply-credit are all portfolio-global, and each is negotiated per building in practice. The gap is the allow-list, not the plumbing — **the cheapest structural item here** | `app/Support/PropertySettings.php:48-69` | 🟠 |
| S-15 | **No lease document generation and no clause library.** The signed lease is an uploaded file; `lease_clauses` rows are a per-lease abstract, not a reusable library; there is no `lease_type` column; every new lease gets the same three hardcoded charges | `app/Services/LeaseCreationService.php:118-154`; `app/Models/LeaseClause.php` | 🟠 |
| S-16 | **Dashboards are a PHP registry** with no per-user layer; **module switches are global**; and **the activity log is pruned at 365 days** from a hardcoded config value with no screen — shorter than Egyptian statutory book retention, and invisible to the operator (see PDPL, §3.6) | `app/Support/DashboardLayout.php:71-118`; `app/Support/Modules.php:47-55`; `config/activitylog.php:18` | 🟠 |
| S-17 | **National ID (14 digits) and Egyptian phone format are unvalidated** (`maxLength` only). Tax ID is already validated correctly at `TenantForm.php:69-82` and is the template to copy. Phone normalisation becomes load-bearing the moment SMS/WhatsApp ships | `app/Filament/Admin/Resources/Tenants/Schemas/TenantForm.php:83-85,96-110` | 🟡 |
| S-18 | AR/EN parity is **11,977 keys each, zero mismatch**, gated by a conformance test that renders every screen in both locales. RTL PDFs use mPDF `xbriyaz` with full OTL shaping and their own gate. **Best-in-class; do not disturb** | `tests/Feature/Scenarios/TranslationKeyConformanceTest.php`; `PdfDocumentConformanceTest.php:132-168` | 🟢 |

---

## 5. The worklist

Ordered by **what breaks if it is not done**, not by effort. Owner: 🧑‍💻 code · 🔑 a decision or
credential from the operator/accountant · ⚙️ ops.

### P0 — wrong money, or a legal document that is wrong

| # | Work | Refs | Owner | Size |
|---|---|---|---|---|
| **EG-01** | **Stop freezing taxability onto charge rows.** `vat_applicable` and `vat_rate` must be null-by-default on every recurring charge and resolved at billing time, exactly as the service-charge block already does two lines below the base-rent block. 11 origination sites + a backfill of existing rows | T-2, T-3 | 🧑‍💻 | M |
| **EG-02** | **Give tax treatment a lease/unit dimension.** An effective-dated tax treatment on the lease (or on the unit's type), resolved through `Vat::rateForType()` as a third input, so *"admin units taxed, retail exempt, from date D"* is expressible. This is what Law 157/2025 and the pending executive regulations actually require | T-1, §3.1 | 🧑‍💻 + 🔑 | L |
| **EG-03** | **Payroll: insurable-wage floor/ceiling, tax brackets, personal exemption — as dated rungs.** Build `PayrollRates::for($periodMonth)` mirroring `Vat::rateForType($code, $on)`; fix EG-04 on top of it, not beside it. Seed with the 1 Jan 2026 figures (2,700 / 16,700) | P-1, P-2, P-3, §3.2 | 🧑‍💻 + 🔑 | L |
| ~~**EG-04**~~ ✅ | **DONE 2026-08-20.** `payroll_rates_configured`, in a new `payroll` category. **Not** the blocking-on-zero-rates row this line originally asked for: the settings screen's own help offers *"leave at 0 and enter it per employee"* as a supported posture, so a red row saying otherwise would contradict the field help beside it. It fires on **evidence** — BLOCKING when the latest payroll month's approved runs withheld nothing at all (net = gross, no liability in the books), ADVISORY when there is a roster, every rate is still nil and nothing has been approved yet. Scoped to the **latest** month so the row can clear, because an approved run's amounts are frozen and an all-time count would pin a red dot with no remedy but cancelling a real payroll | P-4 | 🧑‍💻 | S |
| **EG-05** | **Remove the fake `billing@…test` address from every issued document.** One settings field (billing contact) + four lang strings | S-7 | 🧑‍💻 | S |
| **EG-06** | **Declare `ext-intl` in `composer.json` and add it to the go-live checklist.** Without it every money column 500s | S-9 | 🧑‍💻 + ⚙️ | S |
| **EG-07** | **Close the vendor-contract currency hole** — remove the non-EGP options, or gate them behind a real rate. A USD number posting to an EGP ledger at 1:1 is silent | X-3 | 🧑‍💻 | S |
| **EG-08** | **A working calendar: working days + working hours + a `holidays` table, per property**, on the same three-tier shape as `SlaResolver`. Then re-base every SLA clock, PM compliance and the reporting week on it | C-1..C-6, §3.4 | 🧑‍💻 | L |
| **EG-09** | **Register `leases.escalation_type` in `ValueSets`** — the one freed money column in the leasing core with no runtime refusal, whose options live in a translation array | M-7 | 🧑‍💻 | S |
| **EG-10** | **Decide the document-number reset rule before go-live.** Monthly-per-property reset is a convention nobody chose and cannot be changed afterwards | M-9, §3.6 | 🔑 | S |

### P1 — real operator pain in the first weeks

| # | Work | Refs | Owner | Size |
|---|---|---|---|---|
| **EG-11** | **A per-rail clearing account.** Make the payment method a **row with a `posting_role`**, as `charge_codes` did for revenue — this fixes X-5, X-6 and X-8 at once, and prevents the first real bank reconciliation from producing a gross unmatched population | X-5, X-6, X-8 | 🧑‍💻 | M |
| **EG-12** | **`bank_account_id` on the money documents**, and teach the journalizers to read `BankAccount::ledger_account_id` | X-7 | 🧑‍💻 | M |
| **EG-13** | **Expense categories become rows with a `posting_role`** — the only thing deciding which P&L account a supplier bill hits | D-1 | 🧑‍💻 | M |
| **EG-14** | **Tenant request types + subcategories + SLA hours become rows**, and re-seat `tradeForRequest()` on the trades register so lifts, generators, fire safety, pest and security are reportable | D-3 | 🧑‍💻 | M |
| **EG-15** | **Operator-editable document/message templates** — invoice terms, footer, bank details, dunning wording. A `document_templates` table + a rich editor + a mail tab | S-6 | 🧑‍💻 | L |
| **EG-16** | **Mall logo on PDFs** — the media collection already exists | S-8 | 🧑‍💻 | S |
| **EG-17** | **Publish the mail views and make them RTL-aware**, copying the PDF layer's `$isRtl` pattern | S-10 | 🧑‍💻 | S |
| **EG-18** | **Widen `PropertySettings::OVERRIDABLE`** — start with `monthly_billing_day` and `auto_apply_tenant_credit` (one registry line each; the screen is registry-derived) | M-5, S-14 | 🧑‍💻 | S |
| ~~**EG-19**~~ ✅ | **DONE 2026-08-20.** The three keys are gone, replaced by a pointer comment. Two things the ticket had wrong and the work corrected: the **env vars are not dead** — `database/settings/2026_05_25_200000_create_billing_settings.php` still seeds the initial row from them on a fresh install, so they are now documented as such in `.env.example`; and four test setups (`LateFeeServiceTest` ×3, `AdversarialSweepFindingsTest` ×1) were writing those keys as if configuring the service, a false pass now replaced with the input the service actually reads. Pinned by a test asserting on the shipped FILE with a live-key control | M-3 | 🧑‍💻 | S |
| ~~**EG-20**~~ ✅ | **DONE 2026-08-20.** Dropped (`2026_08_20_700000_drop_the_lease_billing_day_nobody_ever_read`), with the model, factory and 27 QA-harness fixtures cleaned. Honouring it was rejected on evidence, not taste: the monthly run is one scheduled sweep, so a per-lease day means per-day cohorts and a reworked idempotency stamp — and the question worth answering first is per-**property** (EG-18). No "the column is gone" test, following the project's own precedent for `security_deposit_received` | M-4 | 🧑‍💻 | S |
| **EG-21** | **WHT Form 41 report + per-vendor certificate**, on the `VatReturn` + `PayslipPdfService` pattern. This gates switching `wht_enabled` on | T-6, §3.3 | 🧑‍💻 | M |
| **EG-22** | **Tenant portal white-labelling** — lift the admin panel's per-property branding | S-11 | 🧑‍💻 | S |
| **EG-23** | **Retail/merchandising mix, violation categories, vendor document types, departments become rows** (departments is mostly deleting a `return false;` and adding `name_ar`) | D-2, D-4, D-5, D-6 | 🧑‍💻 | M |
| **EG-24** | **Make module toggles reach the scheduler**, and gate the phantom `billing` key | X-12 | 🧑‍💻 | S |
| **EG-25** | **A WhatsApp/SMS channel + notification routing table.** With push off, tenant reach is bell + email only, and Egyptian retailers answer WhatsApp | X-10, X-11 | 🧑‍💻 + 🔑 | L |

### P2 — structural, and worth deciding rather than drifting into

| # | Work | Refs | Owner | Size |
|---|---|---|---|---|
| **EG-26** | **Legal entity as a first-class object** — per-entity TRN, issuer, chart and fiscal calendar. Already named as a blocker for the Jawad/Eltizam revenue split | S-1, S-2, T-10 | 🧑‍💻 + 🔑 | XL |
| **EG-27** | **Make consolidated statements reachable**, and stop null-property entries disappearing from every P&L an operator can open | S-3 | 🧑‍💻 | M |
| **EG-28** | **Drive statement layout from the chart's own hierarchy** instead of a `match()` on account type and literal code prefixes; add a chart importer | S-4 | 🧑‍💻 | L |
| **EG-29** | **Configurable proration method** (30/360 · actual/actual · actual/365 · whole month), per property or per charge code | M-1 | 🧑‍💻 + 🔑 | M |
| **EG-30** | **Billing in arrears, and non-annual escalation intervals** | M-2, M-6 | 🧑‍💻 | M |
| **EG-31** | **USD-indexed / EGP-denominated rent** — the index on the escalation path, no GL change. **Do this instead of full multi-currency unless the client insists otherwise** | X-4, §3.5 | 🧑‍💻 + 🔑 | M |
| **EG-32** | **Report builder / user-defined columns**, and **custom fields (UDFs)** — the two biggest structural gaps vs the market standard | S-5, D-7 | 🧑‍💻 | XL |
| **EG-33** | **Real-estate tax and municipal levies as a recurring statutory cost** — there is no recurring-expense concept at all today | T-8, §3.6 | 🧑‍💻 + 🔑 | M |
| **EG-34** | **Configurable retention policy** (activity log is pruned at 365 days from a hardcoded config value), per PDPL's documented-retention obligation | S-16, §3.6 | 🧑‍💻 + 🔑 | S |
| **EG-35** | **Late-fee cap and compounding option; deposit default at property level; quarterly CAM true-ups; rounding mode** | M-8, M-11, M-12, M-10 | 🧑‍💻 | M |
| **EG-36** | **Journal descriptions become keys resolved at read time**, as the activity log already does | S-12 | 🧑‍💻 | M |
| **EG-37** | **`ValueSets` gate must fail on an unregistered classification column** — ~25 have been added since the enum sweep, including a status the transition matrix branches on | D-8 | 🧑‍💻 | S |

---

## 6. Decisions this needs from the operator and the accountant

None of these are engineering questions. They are recorded here so they can be asked once.

1. **Which units in the mall carry the 1% schedule tax on administrative-unit rental, and from what
   date?** And if the pending executive regulations impose 14% VAT on administrative space, does it
   reach our units, and does the 1% survive alongside it? *(§3.1 — this is the question EG-02 is
   built to answer, and it is still open in Egyptian law.)*
2. **Does the operator charge a trade-name / brand component?** If so it now attracts a 10% schedule
   tax on 10% of the value. *(§3.1)*
3. **Confirm the withholding rates by supply type** — published summaries disagree (1%/2%/3%/5%).
   *(§3.3)*
4. **Payroll:** confirm the bracket table, the personal exemption, and whether the operator wants the
   system to compute statutory payroll at all, or to keep keying it per run. *(§3.2, EG-03)*
5. **Which employees are covered by social insurance** — the input the gratuity feature needs and has
   nowhere to store. *(P-8)*
6. **The document-number reset rule** — monthly per property, annual, or continuous. Decide before
   go-live; it cannot be changed afterwards. *(EG-10)*
7. **Proration convention** — what do the actual leases say? *(EG-29)*
8. **USD-indexed rent** — is any lease USD-denominated or dollar-indexed? If indexed rather than
   denominated, EG-31 is an M and full multi-currency can stay declined. *(§3.5, open question A7.4)*
9. **Whose TRN issues the invoice** — Eltizam's, or each owner's? *(T-10, open question A2.7)*
10. **Are there tax-exempt tenants** (free zone, government, NGO, embassy)? There is no per-tenant tax
    override today. *(open question A2.6)*
11. **Record-retention period** the operator is required to keep, versus the 365 days the activity log
    is currently pruned at. *(§3.6, EG-34)*

---

## 7. Drift and defects found in passing

Not part of the configurability question, but found while verifying it and worth fixing.

**Documentation that has drifted from the code:**

| Doc | Says | Actually |
|---|---|---|
| `docs/OPEN-QUESTIONS.md` A1.1 | The VAT rate is `TaxSettings::vat_standard_rate` | That setting was **removed 2026-08-12**; rates live in the `tax_codes`/`tax_rates` catalogue |
| `docs/OPEN-QUESTIONS.md` A3.2 | "no straight-line spread" | Straight-line rent **exists** (`StraightLineRentAdjustment`, `PostStraightLineRentService`), shipped off behind `BillingSettings::straight_line_rent_enabled` |
| `docs/accounting/EGYPTIAN-TAX-CATALOG.md:10-11` | `TaxCatalogueConformanceTest` gates the catalogue "in both directions" | Only the *dropped-row* direction is asserted (presence-only, no count or `array_diff`), and it runs under `RefreshDatabase` so it structurally cannot see an operator's production row |
| `docs/modules/21-general-ledger.md:610` | Statements are "per-property **& consolidated**" | Consolidated is unreachable from the panel (S-3) |
| `lang/en/admin/help.php:137` | Escalation type "Step = pre-agreed increases per year" | No `step` option exists in that Select (M-16) |

**Live defects:**

- `ActivityLog` implements `DeliverableReport` and defines `reportCsv()` but never spreads
  `exportActions()` — the audit log can be **emailed on a schedule but not exported from the screen**;
  the only one of 19 report pages in that state. `app/Filament/Admin/Pages/ActivityLog.php:30,55-61,252`
- No `ExportAction` anywhere carries `->authorize()`, so bulk data egress inherits only `viewAny`,
  while every `ImportAction` is double-gated. `.../Invoices/Tables/InvoicesTable.php:309-313`
- `LeaseOption::TYPES` omits `'extension'`, which `ExerciseLeaseOptionService` handles and queries for
  — dead code reachable only by a direct write. `app/Models/LeaseOption.php:28` vs
  `ExerciseLeaseOptionService.php:40,145`
- `PushChannel` returns early for a notifiable with no `deviceTokens()`, and only `Tenant` has that
  relation — so three notifications declaring `'push'` to admin `User`s are silent no-ops.
  `app/Notifications/Channels/PushChannel.php:29`
- Renumbering the chart desynchronises the seeders silently: `ChartOfAccountsSeeder` matches on
  `code`, so a renumbered account produces a **duplicate** on the next `atriom:install`, and
  `AccountMappingSeeder` `continue`s past a miss with no warning.
- `ActivityVocabulary.php:136` still maps `facility_work_order.category`, a column dropped by
  `2026_08_20_100000`.

---

## 7a. Progress

> Updated as each item lands. A row here means the code is written, its targeted tests pass, and the
> docs it touches were corrected in the same change.

### 2026-08-20 — milestone 1: P0 hygiene (EG-04 · EG-19 · EG-20)

| Item | What shipped | Tests |
|---|---|---|
| **EG-04** | `payroll_rates_configured` check + a new `payroll` category, bilingual | `ConfigurationHealthTest` — 4 new cases, 15 pass |
| **EG-19** | Dead config keys deleted; `.env.example` documents the one thing the env vars still do; 4 false-pass test setups made reachable; 3 module docs + BUSINESS-RULES corrected | `PerLeaseLateFeeTermsTest` (+1 pin), `LateFeeServiceTest`, `AdversarialSweepFindingsTest` — pass |
| **EG-20** | Column dropped + model/factory/27 fixtures cleaned; module doc explains the one definition | `LeaseRenewalCarriesTermsTest`, `FixtureColumnsExistConformanceTest`, `ApiResourceFieldConformanceTest` — pass |

**Three things the adversarial pass changed about the work itself**, recorded because each was a plan
that read as correct:

1. **EG-04 was going to gate on `Modules::enabled('employees')`.** Wrong: `PayrollResource` derives
   its module key as `payrolls` (`RoleGatedActions::permissionModule()` pluralises the model), which
   is not in `Modules::KEYS` and is therefore always enabled. The gate would have silenced the check
   while payroll stayed fully reachable. The roster is the honest gate.
2. **EG-04's blocking row could never have cleared.** Approved payroll amounts are frozen
   (`Payroll::booted()` refuses a dirty `salary_tax`), so counting zero-withholding runs across all
   time pins a red dot for the life of the install, remediable only by cancelling a real payroll to
   satisfy a checklist. Scoping to the latest payroll month makes it both able to fail and able to go
   green.
3. **`whereDate()` against a `max()` value silently matches nothing** when the value carries a time
   component — the check was written that way, the test caught it, and it is now exact equality.

**Two deployment notes this milestone creates:**

- `docs/qa/scripts/baseline.sql` is now stale — it predates the `billing_day` drop. Re-run
  `composer qa:baseline` before `composer qa` or `composer test:mysql`, per that script's own note.
- `docs/PROJECT-MAP.md`'s generated census (migration and test counts) is stale. Regenerate with
  `php artisan atriom:dump-system-census` **once the working tree is clean** — running it now would
  bake in another session's uncommitted files.

---

## 8. Method, and what this audit did not cover

**How it was done.** Six parallel code audits over the working tree at `main` (money/billing ·
master data · localization/calendar · payroll/statutory · payments/FX/integrations · structure/
reporting), each required to cite `path:line` and to name the search that proved any absence claim.
External research into Egyptian statute and market practice was run separately and is sourced in
[§3](#3-the-egyptian-statute-book-and-market-practice-2025-2026). **Every 🔴 finding above was then
re-verified by hand** against the source before being written down — which is how the report avoids
the failure mode the gap analysis records: a "missing" row that turned out to be present under
another name.

Two agent claims were checked and **corrected** during that pass, and the corrected versions are what
appear above:
- The `config/billing.php` late-fee keys really are dead — the 12 `billing.late_fee_*` matches in
  `app/` are `PropertySettings` keys in a same-named namespace, not `config()` reads.
- The *"contracts signed after 18 July 2025 are taxed at 14%"* rule found in the tax research applies
  to **construction contracts**, not property leases, and was nearly mis-attributed.

**Not covered, deliberately:**
- **Egyptian e-invoicing / ETA** — out of scope by the owner's standing instruction. No ETA finding,
  recommendation or sequencing appears anywhere above.
- **Security, performance and correctness** beyond what surfaced incidentally — that is
  [`qa/`](qa/README.md) and `/qa-sweep`.
- **Product parity vs Yardi / the FM specialists / Odoo** — that is
  [`gap-analysis/README.md`](gap-analysis/README.md), which this document cites rather than restates.
- **No tests were run and no code was changed** in producing this audit.

**Confidence.** High on every code claim (each carries a line reference and the 🔴 set was
hand-verified). High on the statutory facts of §3.2–§3.6. **Medium on §3.1**, and deliberately so:
Egypt's VAT treatment of leased commercial and administrative space was actively being rewritten as
of June 2026, and the final scope sits in executive regulations that had not settled. That
uncertainty is not a reason to wait — it is the argument for EG-02, because a rule still being
written is exactly the rule you do not want compiled into `EXEMPT_TYPES`.
