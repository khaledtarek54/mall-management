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
3. ~~**There is no working calendar.**~~ **FIXED 2026-08-21 (EG-08 + EG-38).** Egypt's weekend is
   Friday–Saturday, and every SLA clock was `now()->addHours()`
   ([§4.5](#45-time-and-the-working-calendar)) — with vendor SLA penalties posting real money off it.
   There is now a `holidays` register, `CalendarSettings` and `App\Support\WorkingCalendar`; both
   modules freeze the clock they promised on, and all four overrun measures read it. It **ships off**
   until the operator rules on which priorities are office work.

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
| 9 | **Master-data lists** | 🟠 MIXED | Trades, failure codes, charge codes, tax codes, SLA policies, rent indices, **payment rails (EG-11)** and **expense categories (EG-13)** = rows. Still a lang array or a const: retail mix, violation types, vendor document types, departments. Tenant-request SUBCATEGORIES are rows (EG-14); the request TYPE stays an enum on purpose, because it carries behaviour |
| 10 | **Statutory payroll** | 🔴 HARDCODED | Three flat undated rates; no brackets, no exemption, no insurable-wage cap |
| 11 | **Time / working calendar** | ✅ DONE | `holidays` register + `CalendarSettings` + `WorkingCalendar` (Fri–Sat weekend, Ramadan short days, per-property dates). Both SLA modules freeze the clock they promised on. Ships **off** — EG-08 + EG-38 |
| 12 | **Currency / FX** | 🔴 ABSENT | 15 tables carry `currency`; nothing reads it; no rate table; no GL currency |
| 13 | **Payment rails** | ✅ DONE | One `payment_methods` catalogue: a row per rail with a bilingual name, a direction, and the ledger account its money lands in. Fawry / Meeza / Vodafone Cash / Aman ship present and switched off. EG-11 |
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
| ~~M-5~~ ✅ | **FIXED 2026-08-21 (EG-18) — and it was NOT "one line each".** `auto_apply_tenant_credit` was: one registry line plus pointing its single read at the resolver. `monthly_billing_day` was not, and the row was wrong about it: the value drives `->monthlyOn()` on the SCHEDULER, which is one process for the whole portfolio, so an override would have been a setting the operator saves and nothing can honour. Both runs now fire DAILY and ask each property whose day it is, clamped to the month's last day — unclamped, a property set to the 31st skips seven months of the year. ~~`monthly_billing_day` is portfolio-wide and capped at 28~~ | `app/Support/PropertySettings.php`; `app/Jobs/RunMonthlyBilling.php` | 🟠 |
| M-6 | **Escalation is annual-only** — `->addYear()`, and no `escalation_frequency` column exists. A biennial or 18-month clause cannot be automated | `app/Services/RentEscalationService.php:196` | 🟠 |
| M-7 ✅ | **FIXED 2026-08-20 (EG-09).** ~~`leases.escalation_type` has no `ValueSets` entry~~ — a freed `string(32)` column whose options live in a **translation array**. The wildcard save-listener does not refuse out-of-set values here, so an import can write `annual_increase` and the sweep silently skips that lease forever. This is the exact pattern CLAUDE.md bans after the `Trade.category` episode | `lang/en/admin/statuses.php:311`; migration `2026_08_10_240000_...:44`; absent from `app/Support/ValueSets.php` | 🔴 |
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
| ~~D-1~~ ✅ | **FIXED 2026-08-21 (EG-13).** `expense_categories` — a row per kind of cost, carrying the P&L account it books to and whether it is fixed or variable. All four cost journalizers (Expense, VendorBill, SlaPenalty, CustodyTransaction) resolve through it; null takes the floor, which is the same six-entry map, so it ships behaviour-identical. Insurance, government fees & licences, bank charges, legal & professional and fuel ship present and **switched off**. Registering the value sets also closed an enforcement gap: the three category columns had NO set at all, and the guard immediately caught 13 fixtures billing under `hvac`/`services` — trade codes no form offers, every one of which was booking to `admin_expense`. ~~Expense / vendor-bill category — six values, and **the only thing deciding which P&L account a supplier bill hits**, via a `private const` in a trait~~ | `app/Models/ExpenseCategory.php`; `MapsExpenseCategory.php`; `app/Support/CostNature.php` | 🔴 |
| ~~D-2~~ ✅ | **FIXED 2026-08-21 (EG-23, part 1).** `retail_categories` — a row per category, with a screen, so the leasing team revises the mix as the mall changes. Registering the value set also closed an unenforced column: `tenants.retail_category` had NO set, so a typo'd or imported value saved cleanly and then matched no filter in the shopper app — invisible in the directory while looking correct on the tenant record. ~~12 hardcoded values driving the store directory, the public API filter and all tenant-mix analysis~~ | `app/Models/RetailCategory.php` | 🔴 |
| ~~D-3~~ ✅ | **FIXED 2026-08-21 (EG-14).** `tenant_request_subcategories` — a row per reportable problem, LINKED to the trade register by foreign key rather than matched to it by name. The seven trades a tenant could not report (lift, generator, fire safety, pest, security, landscaping, waste) are seeded ACTIVE, because unlike the payment-rail and expense-category catalogues, activating one changes no accounting — it only lets a tenant describe a fault precisely, and the status quo of "other, with no trade" was the worse default. The link also bridges `fire_safety` → `fire-safety`, which the string match could never have done. Per-type SLA became one new tier on `sla_policies`, not a new table. **The TYPE stays a PHP enum** — it carries behaviour, and rows would let an operator create a type the code has no answers for. ~~a PHP enum with a `match()`; and it has already drifted from the trades register~~ | `app/Models/TenantRequestSubcategory.php`; `RaiseCorrectiveWorkOrderService.php` | 🔴 |
| ~~D-4~~ ✅ | **FIXED 2026-08-22 (EG-23, part 2).** `violation_categories` — a row per house rule, with a screen, and the row carries the STANDARD FINE so a field officer at the shop door is not recalling the tariff from memory (a prefill only: `violations.fine_amount` stays the operator's number and is never re-derived). `violations.category` had NO value set either, despite the migration's promise, so the column accepted anything and a typo matched no filter and no repeat-offender report. ~~7 hardcoded values; the migration promised the opposite in writing~~ | `app/Models/ViolationCategory.php` | 🟠 |
| ~~D-5~~ ✅ | **FIXED 2026-08-22 (EG-23, part 2).** `vendor_document_types` — and the field that earns the screen is `blocks_dispatch`, which was a one-element array literal deciding who may be sent onto the mall floor. An operator dealing with a government client may be told a lapsed social-insurance certificate blocks too; that is now a tick, not a deploy. The floor is applied only when the table holds NO rows, because `whereIn([])` matches nothing and an empty answer would have deleted the compliance gate silently; inactive types still block, because `is_active` governs what may be FILED. ~~6 fixed types gating the COI chase and dispatchability~~ | `app/Models/VendorDocumentType.php` | 🟠 |
| ~~D-6~~ ✅ | **FIXED 2026-08-21 (EG-23, part 1).** `canCreate()` is the trait's again, i.e. gated on `departments.create` (held by manager and mall_admin — checked, not assumed). `name_ar` added and the five seeded rows backfilled, so the register is bilingual like the panel around it. DELETE stays refused for everyone: a department that routed a request is referenced by rows an auditor reads. Four tests encoded the old decision and now state the new one. ~~`canCreate()` simply `return false;`~~ | `app/Models/Department.php`; `DepartmentResource.php` | 🟠 |
| D-7 | **No custom fields / UDFs anywhere.** Zero hits for `custom_field`; five `metadata` JSON columns with no reader in any UI, service or report. **The single biggest structural gap vs Yardi UDFs / MRI user-defined fields / Odoo Studio** | ABSENT | searches named | 🔴 |
| D-8 | **`ValueSets` covers only the 62 columns that were DB enums on 2026-08-12.** ~25 classification columns added since are outside the registry — including `facility_work_orders.status`, which the transition matrix branches on. `NoDatabaseEnumsConformanceTest` never asks *"is this new column registered?"* | gate gap | `tests/Feature/Scenarios/NoDatabaseEnumsConformanceTest.php:75-131` | 🟠 |
| D-9 | Trades, failure codes, charge codes, tax codes, SLA policies, rent indices, utility tariffs, areas, approval bands, roles — **all rows, bilingual, operator-editable** | ROW | — | 🟢 |
| D-10 | **Correctly code-coupled and should stay so:** every workflow status the code branches on; posting-role *names*; tax `family`/`direction`/`treatment`; `fixed_assets.tax_pool` (Law 91/2005); `failure_codes.type`; `ledger_accounts.type`; permission keys; `EgyptGovernorates` | — | — | 🟢 |

### 4.5 Time and the working calendar

**The most clear-cut gap in the audit, and the most Egyptian.**

| # | Finding | Where | Rating |
|---|---|---|---|
| ~~C-1~~ ✅ | **FIXED 2026-08-21 (EG-08 + EG-38).** A working calendar exists and **both** SLA consumers use it: module 26's work-order clocks (EG-08) and module 11's tenant-request clocks (EG-38). The shared `SlaSettings::sla_working_clock_priorities` knob now means one thing across both modules, each job freezes the clock it was promised on, and **all four** overrun measures read that same clock — `daysOverSla()`, `FacilityWorkOrder::hoursOverSla()`, `::hoursOverResponseSla()` and `TenantRequest::hoursOverSla()`. The middle two were missed until the stream review found them. Ships **off** — the setting is empty until the operator rules on which priorities are office work. ~~There is no working calendar.~~ Searched `isWeekend\|isWeekday\|dayOfWeek\|Carbon::FRIDAY\|nextWeekday\|businessDay\|working_day\|business_day` across `app/ config/ database/ resources/` — **one hit, in report scheduling.** Every SLA clock was `created_at->addHours(n)` | `app/Support/WorkingCalendar.php`; `app/Models/FacilityWorkOrder.php`; `app/Services/TenantRequestService.php`; `app/Models/TenantRequest.php` | 🔴 |
| C-2 ✅ | **FIXED 2026-08-21 (EG-08).** ~~No public-holidays table.~~ `ls database/migrations \| grep -i "holiday\|calendar\|working"` → nothing. Egypt has ~15 public days; **the Eids move on the Hijri calendar and are set by moon sighting**, and mid-week holidays are routinely shifted to Thursday — so they can only ever be a table the operator maintains annually. **This gap is not recorded in the gap analysis** — it is unknown, not deferred | absence proven by the searches named | 🔴 |
| C-3 | **This is not cosmetic — it posts money.** Vendor SLA penalties are computed off the same clock and journalised. A 24-hour urgent job raised Thursday 17:00 is due Friday 17:00 with the engineering team off; the resulting penalty is a payable an Egyptian contractor will contest and win | `SlaPenaltyJournalizer` | 🔴 |
| C-4 ✅ | **FIXED 2026-08-21 (EG-08).** ~~No business hours, no Ramadan hours.~~ Ramadan is a `short_day` row, because the dates move every year and cannot be a standing setting. `business_hours\|opening_hours\|trading_hours\|work_start\|shift_start` → **zero**. The only "Ramadan hours" mechanism in the system is an announcement a human types | absence proven | 🟠 |
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
| X-3 ✅ | **FIXED 2026-08-20 (EG-07).** ~~The sharpest edge in the audit:~~ the vendor-contract form offers a currency Select of **EGP/USD/EUR/GBP/SAR/AED** one line below an amount field hardcoded `->prefix('EGP')`. Pick USD today and a USD number posts to an EGP ledger at 1:1, silently. **Either remove the non-EGP options or build the rate** | `app/Filament/Admin/Resources/Vendors/RelationManagers/ContractsRelationManager.php:117-126` (verified) | 🔴 |
| X-4 | **The cheaper path is probably the right one.** Egyptian malls overwhelmingly write **USD-indexed, EGP-denominated** leases — the rent *amount* moves with a published rate while the books stay single-currency. That is an addition to `RentEscalationService` (which already steps rent on a schedule and already reads a dated `rent_indices` register), needs **no GL change**, and is **M** effort against **XL** for true multi-currency. Worth putting to the client as the actual question behind open question A7.4 / Q-F | `app/Services/RentEscalationService.php`; `app/Models/RentIndex` | — |
| ~~X-5~~ ✅ | **FIXED 2026-08-21 (EG-11).** A rail is a row, added from `/admin/payment-methods` by anyone holding `payment_methods.create`. The two `->only()` filter lists, the table cell renderer and `Disbursement::METHODS` all derive from the catalogue now, so there is no hand-kept list left to forget. ~~A payment rail cannot be added without a deploy~~ — 9–14 files including two lang catalogues, a hardcoded 7-value expectation in `TranslationCoverageTest.php:54`, and two `->only()` filter lists. `ValueSets`' own docblock named the failure mode — *"Egypt's payment rails keep moving: Fawry, Meeza, Aman, Vodafone Cash"* — and then kept them in a `const` | `app/Models/PaymentMethod.php`; `app/Support/ValueSets.php` | 🟠 |
| ~~X-6~~ ✅ | **MECHANISM FIXED 2026-08-21 (EG-11); the accounts remain the accountant's.** A rail now names its own `ledger_account_id` and all **six** journalizers resolve through `PaymentMethod::accountIdOrFloor()`. Null takes the floor — `cash` for cash, `bank` for the rest — so it ships behaviour-identical and the operator opts in one rail at a time. **What is still open is not code**: the real Egyptian chart has not been supplied, so no clearing account exists to point Fawry at yet (§6). ~~Every non-cash rail debits one `bank` account on capture day~~ — no clearing account, no undeposited funds, no PSP receivable. **The bank reconciliation just built will show a gross unmatched population every month**, because the book line is dated capture and the bank line is dated settlement (T+1/T+2 for Paymob, longer for Fawry) | `app/Models/PaymentMethod.php`; the six journalizers | 🔴 |
| X-7 | **Nothing records which bank account a receipt landed in.** The register exists and `BankAccount::ledger_account_id` exists — **no journalizer reads it**; the only `bank_account_id` FK in the schema is on `bank_statements`. With two banks in one mall the matcher will offer the *other* bank's postings as candidates | `app/Models/BankAccount.php:18-23`; `docs/accounting/BANK-RECONCILIATION-PLAN.md:92-98` | 🟠 |
| ~~X-8~~ ✅ | **FIXED 2026-08-21 (EG-11).** One catalogue serves all four columns, split only by DIRECTION (`for_inbound` / `for_outbound`) so a collection network is never offered as a way to pay a vendor. `Disbursement::METHODS` — the registry that sat outside `ValueSets` entirely, so its column was unenforced — now derives from it. A security deposit received by InstaPay records as InstaPay. ~~Four parallel payment-method registries that have already drifted~~ | `ValueSets.php`; `app/Models/Disbursement.php` | 🟠 |
| X-9 | **No gateway abstraction.** `PaymobPaymentInitiator` is concrete at four call sites, `'gateway' => 'paymob'` is a literal, and the callback re-finds rows by `where('gateway','paymob')`. Adding Fawry/Kashier/Geidea is a project. Paymob config is also **global, not per-property** | `app/Providers/AppServiceProvider.php:48`; `config/integrations.php:38-77` | 🟠 |
| X-10 | **No SMS, no WhatsApp.** Every major provider searched (`twilio\|vonage\|unifonic\|smsmisr\|victorylink\|360dialog\|gupshup`) → zero. The WhatsApp stub was honestly deleted rather than left as a fake. With push shipping off, **tenant-facing reach today is bell + email only** — and Egyptian retailers answer WhatsApp, not email | `database/settings/2026_08_11_200000_delete_whatsapp_toggle.php` | 🟠 |
| X-11 | **Notification routing is ~15 PHP literals.** No screen, no table, no settings group; `super_admin` is hard-unioned into every operator fan-out; there is no opt-out and no per-tenant channel choice | `app/Services/TenantRequestService.php:200`; `app/Support/AssetStaffRecipients.php:34` | 🟠 |
| ~~X-12~~ ✅ | **FIXED 2026-08-21 (EG-24).** `ScheduledModules::guard()` attaches the skip to every scheduled event from ONE registry, applied once at the end of `routes/console.php` — not as a `->skip()` per command, because thirty-three edits are thirty-three chances to forget and the next command inherits nothing. Every command is classified as owned by a module or stated to be CORE with the reason it can never be switched off. The phantom `billing` key is gone, and a gate now refuses ANY `Modules::enabled()` call naming a key outside `Modules::KEYS` — my own first draft of the registry contained one (`marketing`), which is how quietly that fails. ~~Turning a module off does not stop its scheduled work~~ | `app/Support/ScheduledModules.php`; `routes/console.php` | 🟠 |
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
| S-7 ✅ | **FIXED 2026-08-21 (EG-05).** ~~A fake `.test` address prints on every issued invoice PDF.~~ `__('admin.pdf.footer')` interpolates `billing@:slug.test` — rendering e.g. `billing@atriom-walk.test` on a legal tax document, and on tenant/asset statements. Verified in all four lang files. **One settings field plus four string edits; cheapest item in this report and the most embarrassing** | `resources/views/invoices/pdf.blade.php:332`; `lang/en/admin/reports.php:324`, `lang/ar/admin/reports.php:323`, `lang/en/admin/accounting.php:439`, `lang/ar/admin/accounting.php:432` | 🔴 |
| ~~S-8~~ ✅ | **FIXED 2026-08-21 (EG-16), completed after review.** The first cut reached all twelve TEMPLATES and only seven SERVICES: five called `forView()` with no asset, so the owner statement — the document Jawad actually receives — rendered `$asset` in its own party block while the logo beside the issuer name was unconditionally absent. The gate only checked the `@include` was present. Both halves are gated now, and a report filtered to one mall carries that mall's letterhead via `forViewScopedTo()`. ~~No mall logo on any PDF~~ | `app/Support/IssuingEntity.php` | 🟠 |
| S-9 ✅ | **FIXED 2026-08-21 (EG-06).** ~~`ext-intl` is undeclared while 260 money columns depend on it.~~ `composer.json` `require` has no `ext-*` at all; `Number::currency()` throws without intl. The codebase already documents the hazard for a different call site. **A deploy box without intl 500s every list and dashboard showing money** | `composer.json:11-28`; `app/Support/Search/SearchText.php:111-113` | 🔴 |
| ~~S-10~~ ✅ | **FIXED 2026-08-21 (EG-17).** `resources/views/vendor/mail` is published and the layout carries `dir`, so all eleven `MailMessage` notifications render RTL in Arabic. The theme was closer than the finding suggested — it already used the logical `text-align: start` nearly everywhere; the gap was two `left` rules and one `border-left` on the panel accent. The published copy is deliberately a MINIMAL fork (one attribute, three properties) so a framework upgrade stays readable. ~~21 of 23 email templates are LTR~~ | `resources/views/vendor/mail/html/layout.blade.php`; `.../themes/default.css` | 🟠 |
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
| ~~**EG-05**~~ ✅ | **DONE 2026-08-21.** `TaxSettings::seller_billing_email`, resolved through `IssuingEntity` like the seller's other particulars and **omitted when unset** — the same contract the TRN has. **THREE documents, not two**: the owner-facing asset statement carried the same fabrication. Pinned by a sweep that fails on any `@…​.test/.example/.invalid` in a lang file or a Blade. It also surfaced a **live 500**: `invoices.lease_id` became nullable when module 37 started billing owners, and the template dereferenced it — so every صيانة assessment invoice's PDF crashed on the list, the edit page, the portal and the API. Fixed by resolving the invoice's context from its AGREEMENT (lease **or** ownership) | S-7 | 🧑‍💻 | S |
| ~~**EG-06**~~ ✅ | **DONE 2026-08-21.** Declared (`intl`, `mbstring`, `zip` — what the app itself calls; the rest arrive through the tree). **The report overstated the risk and understated the real one:** `filament/support` already hard-requires intl, so `composer install --no-dev` refuses on a box without it. What composer structurally *cannot* see is the SAPI split — it runs under `php-cli`, the money columns render under `php-fpm`, and a box with intl in one and not the other installs, schedules and passes console health while throwing on every list. So the substance is a **runtime** check: `App\Support\PhpExtensions` (nine extensions, each with what it costs) read by `/health` over HTTP | S-9 | 🧑‍💻 + ⚙️ | S |
| ~~**EG-07**~~ ✅ | **DONE 2026-08-20.** The picker is gone and `ValueSets` now refuses a non-EGP value on `vendor_contracts.currency` **and** `assets.currency` — the guard, not the dropdown, is what makes it true. The rule both screens follow is stated: **a currency field survives only where the value is PRINTED**, so the asset's stays (it leads the owner statement) visible and read-only with a server-side `Rule::in`, and the vendor contract's went. Not inert, which is why it ranked: the contract value feeds the SLA-penalty basis, so a foreign number reached the GL | X-3 | 🧑‍💻 | S |
| ~~**EG-08**~~ ✅ | **DONE 2026-08-21.** `holidays` (a register, because Egypt's are ANNOUNCED — the Eids move with the moon and mid-week holidays shift to the Thursday), `CalendarSettings` (Sun–Thu, 09:00–17:00) and `App\Support\WorkingCalendar`. The SLA clock each job is promised on is **frozen onto the job**, and the feature **ships off** — `SlaSettings::sla_working_clock_priorities` is empty, so nothing changes until the operator rules on which priorities are office work. **Three deliberate narrowings from this row, all argued below:** the working WEEK is portfolio-wide (individual dates are per property); PM compliance is excluded; and the reporting week is untouched | C-1..C-4, §3.4 | 🧑‍💻 | L |
| ~~**EG-09**~~ ✅ | **DONE 2026-08-20.** Registered (`none · fixed_percent · fixed_amount · cpi`), and the lease form's options now DERIVE from the registry rather than from the label catalogue, so the picker cannot offer what the model would refuse. It also closed the drift that proved the point: the field help advertised a **"Step"** type that existed in neither list, and omitted `fixed_amount`. Why the sweep missed it: the column stopped being a DB enum on 2026-08-10, two days before the generator read the live schema | M-7 | 🧑‍💻 | S |
| **EG-10** | **Decide the document-number reset rule before go-live.** Monthly-per-property reset is a convention nobody chose and cannot be changed afterwards | M-9, §3.6 | 🔑 | S |

### P1 — real operator pain in the first weeks

| # | Work | Refs | Owner | Size |
|---|---|---|---|---|
| ~~**EG-38**~~ ✅ | **DONE 2026-08-21.** Module 11 now resolves its SLA on the same clock module 26 does. `SlaResolver` gained the canonical `CLOCK_CALENDAR`/`CLOCK_WORKING` constants both modules reference (module 11 does not reach into module 26 for them); `tenant_requests.sla_clock` freezes the promise at intake; all **three** intake roads write it — portal and `/api/v1` through `TenantRequestService::create()`, plus the admin `CreateTenantRequest` page. Two things the row as written did not ask for and the work needed anyway: `TenantRequest::hoursOverSla()`, because the breach bell was quoting a **calendar** overrun for a request promised on the working clock (67 hours for a 3-hour failure), and a test pinning that a crafted payload cannot choose its own clock | C-1 | 🧑‍💻 | M |
| ~~**EG-11**~~ ✅ | **DONE 2026-08-21.** Closed X-5, X-6 and X-8 together. **A rail names its ledger account DIRECTLY, not a `PostingRoles` key** — a role exists so a code path can ask for "the bank account" without knowing the chart, and a rail is operator data pointing at operator data. Decisive against roles: `Health::accountingReadiness()` requires every role to be mapped, so a clearing role per rail would have turned a BLOCKING health row red on every existing install, and two rails could never have had two different clearing accounts. **Six journalizers, not the four the row named** — `Expense` and `Disbursement` read columns this widens and carried the mirror ternary, which sends `bank_transfer` to CASH once the set grows | X-5, X-6, X-8 | 🧑‍💻 | M |
| **EG-12** | **`bank_account_id` on the money documents**, and teach the journalizers to read `BankAccount::ledger_account_id` | X-7 | 🧑‍💻 | M |
| ~~**EG-13**~~ ✅ | **DONE 2026-08-21.** Built on EG-11's pattern with its review's lessons applied up front rather than after: all four journalizers and all eight surfaces converted in one pass, the seeder wired into all three entry points, and the surface gate GENERALISED to both catalogues rather than duplicated. Two things the row did not name and the work needed: `CostNature::categoriesOf()` — the REVERSE direction — still read only the const, so a category an operator marked `fixed` would answer `fixed` one way and be absent the other, and a CAM pool filtered by nature would omit a cost that was itself classified correctly. And the three category columns had no value set at all | D-1 | 🧑‍💻 | M |
| ~~**EG-14**~~ ✅ | **DONE 2026-08-21.** Deliberately narrower than the row as written: subcategories and per-type SLA became rows, the TYPE did not. Four things the ticket did not name — a nullable column inside a UNIQUE silently stops enforcing it (SQL treats NULLs as distinct, so two conflicting `urgent` policies both saved and the existing uniqueness test went green because its expected exception stopped being thrown); MySQL refused the migration three ways sqlite would have accepted; `request_type` is cast to the enum so `(string)` on it is a TypeError; and **the helper-uniqueness gate had been blind since it was written** — `T_CURLY_OPEN` vs a plain `}` drove its depth counter negative on any file with string interpolation, so `tests/Pest.php`, the one file CLAUDE.md says to put shared helpers in, was the one it could not see | D-3 | 🧑‍💻 | M |
| **EG-15** | **Operator-editable document/message templates** — invoice terms, footer, bank details, dunning wording. A `document_templates` table + a rich editor + a mail tab | S-6 | 🧑‍💻 | L |
| ~~**EG-16**~~ ✅ | **DONE 2026-08-21.** One seam, one partial, one gate — twelve templates, none of which can be missed next time | S-8 | 🧑‍💻 | S |
| ~~**EG-17**~~ ✅ | **DONE 2026-08-21.** Driven through Laravel's own renderer in both locales rather than asserted against the blade source — a `dir` attribute a later layer overrides is not a fixed email. The theme half had to be a SOURCE assertion, and the reason is the finding: the CSS inliner normalises `text-align: left` away entirely, so with the bug restored the rendered `<p>` carries no alignment at all and the rendered HTML cannot tell the two apart | S-10 | 🧑‍💻 | S |
| ~~**EG-18**~~ ✅ | **DONE 2026-08-21.** Two keys, and only one of them was the one-liner the finding promised. Also strengthened the wiring gate that was supposed to prevent exactly this: it searched three paths for the bare key name, so the PORTFOLIO read satisfied it and it certified wiring it never checked | M-5, S-14 | 🧑‍💻 | S |
| ~~**EG-19**~~ ✅ | **DONE 2026-08-20.** The three keys are gone, replaced by a pointer comment. Two things the ticket had wrong and the work corrected: the **env vars are not dead** — `database/settings/2026_05_25_200000_create_billing_settings.php` still seeds the initial row from them on a fresh install, so they are now documented as such in `.env.example`; and four test setups (`LateFeeServiceTest` ×3, `AdversarialSweepFindingsTest` ×1) were writing those keys as if configuring the service, a false pass now replaced with the input the service actually reads. Pinned by a test asserting on the shipped FILE with a live-key control | M-3 | 🧑‍💻 | S |
| ~~**EG-20**~~ ✅ | **DONE 2026-08-20.** Dropped (`2026_08_20_700000_drop_the_lease_billing_day_nobody_ever_read`), with the model, factory and 27 QA-harness fixtures cleaned. Honouring it was rejected on evidence, not taste: the monthly run is one scheduled sweep, so a per-lease day means per-day cohorts and a reworked idempotency stamp — and the question worth answering first is per-**property** (EG-18). No "the column is gone" test, following the project's own precedent for `security_deposit_received` | M-4 | 🧑‍💻 | S |
| **EG-21** | **WHT Form 41 report + per-vendor certificate**, on the `VatReturn` + `PayslipPdfService` pattern. This gates switching `wht_enabled` on | T-6, §3.3 | 🧑‍💻 | M |
| **EG-22** | **Tenant portal white-labelling** — lift the admin panel's per-property branding | S-11 | 🧑‍💻 | S |
| ~~**EG-23**~~ ✅ DONE | **D-2 + D-6 (2026-08-21), D-4 + D-5 (2026-08-22).** Six catalogues now share `App\Models\Concerns\IsCodeCatalogue`, extracted in part 2 rather than written a fifth and sixth time — and the extraction immediately found a live bug the four hand-written copies had hidden: `TenantRequestSubcategory`'s flush dropped `…map.labels` while filling `…map.labels.en`, so an operator renaming a subcategory saw the old word for the rest of the request, and for the rest of the day on a `queue:work` daemon. Part 2 also closed D-6's other half: `fd1ea2d1` removed `canCreate()`'s hard `false` and announced a department could be added, while registering no create route, page or button — the gate opened and the door was never built | D-2, D-4, D-5, D-6 | 🧑‍💻 | M |
| ~~**EG-24**~~ ✅ | **DONE 2026-08-21.** One seam over 39 scheduled events rather than 33 edits. Proven by driving it: with `facility` off the PM generator's `filtersPass()` is false while the core ledger sweep's is true — a guard that skipped everything would satisfy the first assertion alone | X-12 | 🧑‍💻 | S |
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
| ~~`lang/en/admin/help.php:137`~~ ✅ | ~~Escalation type "Step = pre-agreed increases per year"~~ | **Fixed 2026-08-20 (EG-09)** in both languages — the helper now names the four types that exist |

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

**The adversarial passes changed the work itself seven times.** Each was a plan or an implementation
that read as correct. The reasoning for each decision lives in
`ConfigurationHealth::payrollRatesConfigured()`'s docblock — cited here rather than restated, since
two copies of a rationale is how one of them goes stale:

*Caught before the code was written:* gating on `Modules::enabled('employees')` would have silenced
the check while payroll stayed reachable (`PayrollResource` derives `payrolls`, which is not a module
key); and a blocking row counting zero-withholding runs across all time could never have cleared,
because approved payroll amounts are frozen.

*Caught by the tests:* `whereDate()` compared against a `max()` value silently matches nothing when
that value carries a time component.

*Caught by the review of the commit:* the advisory branch rendered the **blocking** sentence, telling
an operator with no payroll at all that ":count runs withheld nothing" — with a count of zero; the
"no roster" sentence was computed, translated into both languages, and unreachable, so the green row
asserted evidence that did not exist; a portfolio-wide "latest month" let one mall's correct month
silence another's broken one, and a future-dated month silenced everything; and the check read
property-owned data with no property scope on a page `mall_admin` can open. Also a genuine
build-breaker: the new test helper `payrollRun()` collided with a file-scope declaration of the same
name in `PayrollHeaderHasOneDefinitionTest`, which is a fatal redeclaration the single-file test run
could never have shown.

### 2026-08-20 — milestone 2: review fixes · EG-09 · EG-07

Milestone 1 was reviewed before this was started, and the review changed it. **Six findings, one of
them a build-breaker**, all fixed in this change:

| # | Finding | Fix |
|---|---|---|
| 🔴 | The new test helper `payrollRun()` collided with a file-scope declaration of the same name in `PayrollHeaderHasOneDefinitionTest` — a fatal redeclaration that a single-file test run can never show, and that `TestHelperUniquenessConformanceTest` exists to catch | Renamed to `approvedPayrollFor()`; the gate is green |
| 🔴 | The advisory branch rendered the **blocking** sentence, telling an operator with no payroll at all that ":count runs withheld nothing" — with a count of zero | A distinct `advisory` string in both languages, selected by `ConfigurationHealth::sentenceFor()`; asserted on the rendered page |
| 🟠 | The "no roster" sentence was computed, translated twice, and unreachable — the green row asserted evidence that did not exist | `ok` is now `:detail`, following `posting_map_complete` |
| 🟠 | The remedy the impact line promised did not work: a corrective run in the same month could not clear the row | The check asks about a **month**, not a run — so raising a corrective run carrying the deductions genuinely clears it |
| 🟠 | Two false negatives: a portfolio-wide "latest month" let one mall's correct month silence another's broken one, and a future-dated month silenced everything | Judged **per property**, never beyond the current month |
| 🟠 | The check read `#[PropertyOwned]` data with no property scope, on a page `mall_admin` can open | Scoped to `AssignedAssets::idsForCurrentUser()`, with a leak test |

Plus the polish: the config tombstone cited the wrong ticket, a doc blockquote had been inserted
*inside* a table (breaking four rows of module 04's domain model), the late-fee floor case restated
the shipped defaults so its mutation could not bite, two QA fixtures kept a trailing comma, and
module 24 + GO-LIVE had not been told about the new check. `docs/PROJECT-MAP.md`'s census was
regenerated.

**One finding was mine, caught by mutation-testing my own new test.** EG-07's second case used
`assertTableActionDataSet()` with a closure — which passes just as happily when the predicate is
inverted, i.e. it asserted nothing. Replaced with a single-file source assertion, and proven by
inverting the needle and watching it go red.

| Item | What shipped | Tests |
|---|---|---|
| **EG-09** | `leases.escalation_type` registered; form options derived from the registry; the "Step" helper text that named a type nobody implemented corrected in both languages | `EscalationTypeIsARegisteredValueSetTest` (new, 3 cases) + the four escalation suites + `LeaseFormTightnessTest`, `ResourceFormSmokeTest`, `FieldHelpConformanceTest` |
| **EG-07** | Vendor-contract currency picker removed; `assets.currency` + `vendor_contracts.currency` EGP-only in `ValueSets`; asset field read-only with a server-side rule | `CurrencyIsEgpOnlyTest` (new, 3 cases, mutation-proven) + `VendorScenarioTest` |

---

### 2026-08-20 — milestone 2 review fixes

The review of `fb06d33f` found that **EG-04 had shipped three defects of the same class it was
rewritten to remove**, which is the finding worth recording rather than the individual fixes:

| Finding | What it was |
|---|---|
| 🟠 | The **advisory** branch was still portfolio-wide, so one mall's year of correct payroll silenced the advisory for the mall onboarded last week — verbatim the cross-property false negative the blocking branch had just been fixed for |
| 🟠 | The green row read *"your latest payroll month carries its statutory deductions"* the moment the rates were set, on an install that had never approved a run — the same empty claim the previous review removed from the other string |
| 🟠 | `Payroll` is `#[PropertyOwned(portfolioRowsWhenNull: true)]`, and a bare `whereIn` excludes NULL — so a head-office run that withheld nothing was red for super_admin and **green for every mall_admin**. The null case was handled correctly ten lines lower, on the other half of the same function |
| 🟠 | The leak test returned at the roster gate and never ran a payroll query: it proved the *Employee* scope while being named for the *Payroll* one. It now fails when the payroll scope is deleted — verified by deleting it |
| 🟠 | The blocking sentence said "your latest payroll month" while the count summed several months across several properties, so the remedy it prescribed was unactionable. It now names which mall and which month |
| 🟡 | The `endOfMonth()` clamp compared 10 characters against the 19 the date cast writes — true on MySQL, false on sqlite, for a run dated the last day of the month. Now an exclusive bound against next month's first day |

Also: the page's `Lang::has()` defended an EN-only key by rendering English inside an Arabic panel
(now `fallback: false`); the bilingual sweep did not know the new optional `advisory` key existed;
one escalation test would have passed with EG-09 fully reverted, so it is gone; `CurrencyIsEgpOnlyTest`'s
source assertion forbade a legitimate `TextColumn::make('currency')` in the same file and its control
was satisfied by the table rather than the form, so it is now scoped to the `form()` block; two
`VendorScenarioTest` fixtures still passed a key no form accepts; and the read-only currency field now
explains itself on screen rather than only in three docs.

**The 55-line docblock was cut to 20.** Two-thirds of it restated the commit message, and module 24
already carried the same argument — two copies of a rationale is how one of them goes stale.

---

### 2026-08-21 — milestone 3: EG-05 · EG-06 (+ a live 500 found on the way)

| Item | What shipped | Tests |
|---|---|---|
| **EG-05** | `TaxSettings::seller_billing_email` through `IssuingEntity`, omitted when unset. Three documents, not the two the finding named — the owner-facing asset statement carried the same fabrication | `DocumentContactIsRealOrAbsentTest` (new, 4 cases; the sweep and the null-lease case both mutation-proven) |
| **EG-06** | `ext-intl`/`mbstring`/`zip` declared; `App\Support\PhpExtensions` + a `/health` row that answers in the SAPI serving the request | `PhpExtensionsConformanceTest` (new, 5 cases, both directions + a driven red path) |

**EG-06's finding was half wrong, and saying so is the point.** S-9 claimed "a deploy box without
intl 500s every money column". It would not: `filament/support` hard-requires `ext-intl`, so
`composer install --no-dev` — which every deploy path runs — already refuses. Declaring the
extensions is still right (declare what you call), but it is not where the risk lives. **The risk is
the SAPI split**: composer runs under `php-cli`, the money columns render under `php-fpm`, and one
missing `.ini` symlink gives a box that installs cleanly, schedules cleanly, passes `atriom:health`
from the console, and throws on every list in the panel. That is a *runtime* question, so the
substance of EG-06 became a health row read over HTTP rather than a line in `composer.json`.

**A live 500 found while removing the fake address.** `invoices.lease_id` became nullable on
2026-08-15 when module 37 started billing unit owners for صيانة, but `InvoicePdfService` still
resolved the property as `$invoice->lease?->unit?->asset` and the template dereferenced
`$lease->reference` and `$lease->commencement_date` unguarded. So **every owner-assessment invoice's
PDF crashed** — on the invoice list, the edit page, the tenant portal and `/api/v1` — and the
document had no property, and therefore no issuer block, even where it did render. An invoice's
context is now its AGREEMENT (a lease **or** an ownership), resolved in one place.

That fix exposed a second-order problem worth recording: `TaxInvoiceSellerParticularsTest` kept its
own hand-written copy of the service's view data, so it **reproduced the bug faithfully instead of
catching it** — it resolved `$asset` through the lease too. The assembly is now
`InvoicePdfService::viewData()`, used by the renderer and by both tests.

---

### 2026-08-21 — milestone 3 review fixes

The review found the fix was real and the **evidence for it was not**, which is the honest summary:

| Finding | What it was |
|---|---|
| 🟠 | **No test proved the new contact PRINTS on any of the three documents.** The file asserted the *absence* of the fabricated address and never the *presence* of the real one, so deleting `@if($billingEmail)` from all three templates left every case green. The case named for the asset statement asserted `class_exists()` — a tautology |
| 🟠 | **The same null-lease defect was still live on the RECEIPT** — `$payment->invoices->first()?->lease?->unit?->asset`. An owner paying their صيانة assessment got a counter receipt with no property and no issuer. It degrades quietly instead of crashing, which is why nobody reported it |
| 🟠 | **…and on the TENANT STATEMENT**, in a file this milestone edited: a unit owner *is* a `tenants` row and may hold no lease, while the invoice query below happily listed their assessments |
| 🟠 | `viewData()` rebuilt the property by walking the agreement when `invoices.asset_id` exists, is NOT NULL, and is stamped `withTrashed()`. `CreditNotePdfService` solved the identical problem on 2026-08-15 and left a docblock warning against exactly the chain I wrote |
| 🟠 | ROADMAP still listed EG-05 as open; module 17 claimed a footer that does not exist; and nothing told the operator to fill the field the documents now depend on — the fix turned a *wrong* contact into *no* contact silently |
| 🟡 | `PhpExtensions` missed `iconv` (the payment-link QR needs it), and `exif` would have 503'd a perfectly healthy box over missing thumbnails |

**The lesson is the one this repo already records:** I fixed the invoice and did not enumerate its
peers. The receipt and the tenant statement had the same line, in the same shape, for the same
reason — and one of them was in a file the same commit was editing.

**A false pass of my own, again in the new test.** `expect($html)->toContain($needle, $message)` —
Pest's `toContain()` takes VARIADIC needles, so the "message" became a second string it looked for
and the assertion could never pass for the right reason. Restructured to collect the offenders and
assert on the list, which `toBe()` does take a message for.

`AssetStatementPdfService` gained a `data()` seam so the owner statement can be asserted at all,
mirroring `InvoicePdfService::viewData()`; a `billing_contact` advisory row joined
`/admin/configuration-health`; GO-LIVE gained A1.3; and the census is regenerated (845 test files).

---

### 2026-08-21 — milestone 4: EG-08, the working calendar

Designed before it was written, and the design pass changed it in six ways. Recording those is the
point of the entry — each was a plan that read as correct:

| Caught | What it would have done |
|---|---|
| 🔴 | **Resolving the clock at read time** would have re-priced every job in flight the moment the setting changed: a PENDING penalty is recomputed on every hourly scan and `SlaPenalty.amount` is DERIVED, so its posted entry gets void-and-reposted. The clock is now **frozen onto the work order** with the deadline, the same way labour freezes the craft rate |
| 🔴 | **A weekend-only overrun would have charged nothing.** Deadline Thursday 23:00, finished Saturday 10:00: a real breach with no working time in it, `0 × rate = 0`, and a penalty row reading "assessed and owed nothing" — while a FLAT-basis penalty charged in full for the same breach. Floored at 1, which is the documented rule ("part of a day counts as a whole day") |
| 🔴 | **PM compliance was in scope and had to come out.** A PPM order never receives a clock (`stampSlaClocks()` returns early for anything non-corrective), so the change would have been dead code — and routing around that would have made skipping Fri/Sat a *tolerance window*, which module 26 refuses by design |
| 🟠 | **A NOT-NULL column with a model default** would have made the `??=` never fire: every order stamped `calendar` for ever, and no test able to notice. The column is nullable, and null is also the honest value for the orders that predate it |
| 🟠 | **The suite runs UTC and production runs Africa/Cairo.** A job raised Friday 00:30 in Cairo is Thursday 22:30 in UTC — a working day in one and the weekend in the other, i.e. exactly the boundary the feature exists for. Every case in the test pins Cairo explicitly rather than merely freezing the clock |
| 🟠 | **`PropertyField::make()` hard-requires and pins the property**, so a national holiday — the ordinary case — was unreachable through its own form. It uses `PropertyField::free()` with a registered reason |

**And two narrowings, stated because a narrowing is still a deviation:**

- **The working WEEK is portfolio-wide**, not per property. `WorkingCalendar` takes an `?int $assetId`
  so the tier can be added when a mall's FM shift genuinely differs; shipping an override nothing
  consults is what `PropertySettings`' own docblock calls worse than no override. Individual DATES
  are per property, which is the part operators actually need.
- **The reporting week (C-6) is untouched.** Mon–Sun in `ReportService` splits Egypt's Sun–Thu week
  across two buckets, but a reporting anchor is not a fact about when engineers are on duty, and the
  ISO-key collision makes it its own job.

**It ships OFF.** `sla_working_clock_priorities` is empty, so every clock still runs on bare hours —
exactly as before. Whether a 24-hour promise means calendar hours (a chiller does not stop on Friday)
or working hours (a signage approval is plainly office work) is a contract question per priority, and
the operator's ruling. That decision is now a GO-LIVE item rather than an assumption in code.

| Shipped | Tests |
|---|---|
| `holidays` register + Filament screen + bilingual guide + permissions seeded; `CalendarSettings`; `WorkingCalendar`; frozen `facility_work_orders.sla_clock`; `HolidaySeeder` (fixed-date holidays only) wired into `atriom:install` | `WorkingCalendarTest` (8 cases) + 19 conformance gates re-run green |

**One pre-existing red fixed in passing:** `SearchPolicyConformanceTest` had been failing on
`EmployeePayslipsRelationManager` since `1ae94b09` — a table rendering a search box it could never
answer. One line, and it was blocking verification of this work.

---

### 2026-08-21 — milestone 4 review fixes

The review's verdict was the right one: *nothing was broken today, and the feature did not work when
switched on.* Since the switch is now a GO-LIVE line item someone will tick, that is the same thing
as broken.

| Finding | What it was |
|---|---|
| 🔴 | **The two overrun measures were incommensurate, and the working one OVER-charged.** The calendar branch measures elapsed duration; the working branch counted working days *touched*. Sunday 17:00 → Monday 09:00 contains no working time and touches two working days — so the option sold as charging a contractor *less* added a day to every overrun crossing a midnight, against the same rate, posting to the GL. Now elapsed working seconds ÷ a standard working day, rounded up |
| 🔴 | **Acceptance discarded the working deadline.** FR-CM-07 re-derives from the moment of acceptance in bare hours; because the working deadline is always later in wall-clock, the `min()` picked the calendar figure every time — leaving a job stamped `working` and measured on neither clock consistently |
| 🟠 | **The heal path re-promised the legacy backlog.** The hourly scan stamps clocks on pre-feature orders and resolved the CURRENT setting, so the day an operator switched it on the whole backlog silently changed penalty basis — via `saveQuietly`, so not even the activity log saw it. Pinned to calendar |
| 🟠 | **A property-restricted `mall_admin` could write a NATIONAL holiday** affecting malls they cannot open. Guarded against `AssignedAssets`, with the refusal logged |
| 🟠 | **Soft delete + `unique(asset_id, date)`** gave a 500 on re-adding a deleted date, and silently resurrected a deleted national one on the next reseed. `deleted_at` is gone — the model was already `#[DeletionAllowed]` and the guide says deactivate — and the form now refuses a duplicate as a field error |
| 🟠 | **One DB query per day walked**, from the hourly breach scan, per overdue order. Now one query per span |
| 🟠 | **An hours-less `short_day` turned any Friday into a full working day** by falling through to the standard window *and* skipping the weekday check |
| 🟠 | **`sla_clock` was written and invisible** — not logged, not on any screen. Now logged with its own bilingual vocabulary |
| 🟠 | **C-1 was marked ✅ while module 11's two clocks were untouched.** Narrowed to PARTLY FIXED, with EG-38 opened |
| 🟡 | No holidays in demo/learning/QA data; a 19-word helper against an 18-word budget; a settings pair with no closes-after-opens rule; the hand-typed "83 screens" in CLAUDE.md (actually 99) |

**Two more false passes of my own, both caught by mutation-testing my own tests.** The weekend-penalty
case used a Thursday-evening deadline — which `workingDaysBetween` already scored 1, so the floor it
existed to prove never fired. And the acceptance case asserted only "lands on a working day", which
is true either way for a short window from Thursday afternoon; it now compares against the working
computation and goes red when the fix is reverted. That is three such passes across four milestones,
which is a rate worth naming rather than explaining away.

---

### 2026-08-21 — milestone 5: EG-38, module 11 on the same clock

Finishing what EG-08 started. `SlaSettings::sla_working_clock_priorities` is one setting read by two
modules; only one of them honoured it. An operator ticking `medium` got a working-calendar deadline
for a work order and a bare `now()->addHours()` deadline for a tenant request — the same word
meaning two things, which is the split-brain the maintenance rename was done to end.

| What | Why it is this way |
|---|---|
| `SlaResolver::CLOCK_CALENDAR` / `CLOCK_WORKING` / `CLOCKS` | The canonical constants. Module 11 must not reach into `FacilityWorkOrder` for a vocabulary that belongs to neither module — `SlaResolver` is where both already go for SLA *hours* |
| `tenant_requests.sla_clock`, nullable, **no backfill** | Null reads as `calendar`, which is the behaviour those rows were actually given. Backfilling the current setting onto a pre-feature backlog is the exact mistake the EG-08 review caught in the heal path |
| Frozen at intake, on all **three** roads | Portal and `/api/v1` share `TenantRequestService::create()`; the admin `CreateTenantRequest` page is the third and resolves identically — including when the operator typed their own deadline, because a hand-set target is still measured against something |
| `TenantRequest::hoursOverSla()` | Not in the row as written, and the work needed it. The breach bell quoted a **calendar** overrun for a request promised on the working clock: 67 hours for a failure that was 3 working hours old, telling the operator it was twenty times worse than it was. One definition, on the model, next to the deadline it measures |
| `sla_clock` fillable here, guarded on `FacilityWorkOrder` | A divergence, deliberately. The admin road is a Filament `CreateRecord`, which mass-assigns and would silently **drop** a guarded key — the freeze would then be missing on exactly one road with no error. Both writers set it themselves instead: the service uses an explicit whitelist and never spreads the client payload, the page force-sets it. Pinned by a test that goes red when the payload is spread |

**Found while running the gates, not by looking for it:**

| Finding | What it was |
|---|---|
| 🔴 | **`ReceiptPdfService` referenced an undefined `$asset`** — a fatal on every receipt PDF. My own damage from milestone 4's null-lease fix: I wrote the explanatory comment and lost the assignment line. Two test files caught it; both had been green before that milestone |
| 🟠 | **`correctiveOrder()` was declared in two test files** — the fatal redeclaration `CLAUDE.md` warns about, which `--parallel` hides because a worker only loads its own files. Now shared in `tests/Pest.php`. Fourth occurrence in this project's history |
| 🟠 | **`holidays` was an unclassified permission group** (`OwnerVisibility`) and the property-isolation doc block was stale — both from milestone 4, both caught by gates I had not re-run across the whole `tests/Feature/Scenarios` directory after that commit |

**A false pass of my own, again caught by mutating my own test.** The first cut asserted that a
working-clock request "falls due on a working day" — using the shipped 72-hour medium window, which
from a Thursday afternoon lands on **Sunday**, a working day in Egypt either way. It passed with the
whole feature reverted. It now pins the interval at 24h, asserts its own premise (that the calendar
deadline really does land in the weekend), and requires the working deadline to be strictly later.
Four such passes across five milestones. The pattern is consistent enough to name: every one of them
was an assertion that happened to be true for a reason unrelated to the change.

**Verification.** All five behavioural mutations go red — reverting `advance()` to bare hours,
dropping the freeze, ignoring the stored clock, always using the working clock (kills the control),
dropping the not-yet-breached guard — and spreading the client payload kills the crafted-clock test.
`tests/Feature/Scenarios` 1457 passed / 3 skipped; `tests/Feature/Regression` 2909 passed / 1 skipped.

---

### 2026-08-21 — milestone 6: the whole-stream review

Not a feature. A 67-agent adversarial review of all eight commits — one reader per commit, five
cross-cutting sweeps (interactions, docs drift, test quality, deploy hazards, security), then up to
three independent skeptics per finding. 78 raw findings, 17 survived. Five commits acted on them.

**The verdict was not about any one change.** It was that the *sweep discipline* failed, the same way,
five times: fix one instance, leave the siblings. Two of the findings were broken in production.

| Severity | Finding |
|---|---|
| 🔴 | **No unit owner's on-account credit had ever been applied.** `ApplyTenantCreditService` walked the retired `lease → unit → asset` chain, which is null by construction for an assessment, and threw. `Invoice::saved()` catches that exact exception as "the ordinary case" and deliberately does not log. `auto_apply_tenant_credit` ships TRUE, so every month re-billed owners in full, silently. The manual path showed "Credit available: EGP 2,000" and then refused |
| 🔴 | **`ad4aea89` was broken on a fresh box.** EG-38 routed an unguarded settings read in front of the try/catch that exists so a box without settings rows still produces a deadline — and re-stated that guarantee in a comment while breaking it. Request creation 500'd, with the feature off |
| 🟠 | **Two of four overrun measures were never converted.** CLAUDE.md's new invariant enumerated the two I had just changed. One `sla_penalties` row carried "66 hours over" beside an amount priced at one working day |
| 🟠 | **A restricted admin could delete a national holiday from every other mall** by re-homing it — the guard read only the submitted value. Same shape on `EditDepartment`. And the portfolio-wide test was inverted: it refused everyone WITH an assignment, which is the state the user form produces by default |
| 🟠 | **A cross-property leak**: the tenant Edit page's Statement omitted the scope both sibling call sites pass |
| 🟠 | **The CAM statement could not state its own derivation for an owner** — party, unit and reference blank, and a *stated* denominator of "0.00 m²" beside a real share |
| 🟠 | **The payroll health row prescribed a remedy the model refuses**, a clamp that overflows on the 31st, and a green sentence that is false in any mixed portfolio |
| 🟠 | Three fixes and two tests that could not fail — including `it('freezes the clock…')`, which passed with the freeze deleted |

**What changed structurally, so the next one is caught by a machine:**

- `NullLeaseChainConformanceTest` bans reaching an asset through a subject's `lease` relation. It
  states its own blind spot rather than implying it has none.
- `GuardsPortfolioWideRows` replaces two drifted copies of the same guard, checks BOTH ends of a
  move, and measures "can see every mall" by comparing the sets.
- `HealthChecksAreWiredConformanceTest` pins the health registry as a SET — `php_extensions` had
  five tests and none that noticed if it stopped being reported at all.
- `PropertyIsolationConformanceTest` no longer goes red when a page is upgraded to a stronger guard.

**The lesson worth keeping, in the reviewers' words:** *the documentation of the rule was written
from the diff rather than from the code, and now certifies coverage that does not exist.* When you
write an invariant, derive its member list by grep.

---

### 2026-08-21 — milestone 7: EG-11, a payment rail is a row

One catalogue replaces four drifted lists, and a rail now says where its money lands. Ships
behaviour-identical: `ledger_account_id` is null on every seeded row and null takes the floor —
`cash` for cash, `bank` for the rest, verbatim the ternary the journalizers carried.

| Decision | Why |
|---|---|
| A rail names its **ledger account directly**, not a `PostingRoles` key | A role exists so a CODE PATH can ask for "the bank account" without knowing the chart. A rail is operator data pointing at operator data, the same shape as `bank_accounts.ledger_account_id`. Decisive: `Health::accountingReadiness()` requires EVERY role to be mapped, so a clearing role per rail would turn a **blocking** health row red on every existing install until the accountant mapped them — and two rails could never have two different clearing accounts without two more roles |
| One catalogue, split by **direction** | `for_inbound` serves `payments.method` and `deposit_transactions.method`; `for_outbound` serves `vendor_bill_payments.method`, `expenses.paid_from` and `disbursements.method`. Without it, unifying would offer a collection network as a way to pay a vendor |
| Fawry, Meeza, Vodafone Cash, Aman ship **switched off** | A tick, not a deploy — and activating one cannot change anything already posted |
| **Six** journalizers, not four | `Expense` and `Disbursement` read columns this widens and carried the mirror ternary (`=== 'bank' ? 'bank' : 'cash'`), correct for two values and wrong the moment the set grows: `bank_transfer` fell to CASH |

**The review of my own first cut found four reds, and the structural one was a REPEAT.**

| | |
|---|---|
| 🔴 | **What a screen OFFERS and what the column ACCEPTS became two sets.** I widened `ValueSets::allowed()` (the picker) and not `forTable()` (the saving listener). The deposit modal offered eight rails and the guard took two: Filament's `Rule::in` passed, the save threw, the operator saw a button do nothing. That is verbatim the 2026-08-18 bug `DepositTransaction::methodOptions()` exists to prevent — and its docblock states the rule I broke: *"deriving it means a surface CANNOT offer a value the column refuses."* Both derive from one `widen()` now, and a gate fails the moment they drift |
| 🔴 | **The regression test guarding that bug had gone vacuous** — it compared `methodOptions()` to `allowed()`, two things that now moved together. It measures the GUARD's set now |
| 🔴 | **The seeder was called from nowhere.** Not `DatabaseSeeder`, not `atriom:install`, not Demo or Learning — every deployed database would have had an empty catalogue and X-5 would not have shipped at all. The local DB had rows only because I ran it by hand |
| 🔴 | **The memo was never invalidated.** Copied from `ChargeCode` without its flush, so a rail activated at 10:00 stays invisible to `queue:work` until it restarts: offered by the picker, accepted by the web request, unknown to the worker posting the entry |

**And my first cut of the new gate could not fail either.** With `payment_methods` empty in tests the
catalogue widens nothing, so both derivations returned the same literal and the gate passed under the
exact mutation it exists to catch. It now creates a rail that is in no floor list and asserts that
premise before measuring. Five such passes across seven milestones — the pattern is always the same:
an assertion true for a reason unrelated to the change.

**One misread worth recording.** `DemoSeeder` timed at 137s against a documented 15.8s and I nearly
logged it as my regression. It was machine contention — CLAUDE.md warns that a shared machine
inflates everything ~3.7× and reads exactly like a real one. Proved it instead: **2
`payment_methods` queries across 200 model saves**, so the guard's new DB read is memoised and costs
nothing per save.

**Still open on X-6, and it is not code:** no clearing account exists to point Fawry at, because the
real Egyptian chart has not been supplied. See §6.

---

### 2026-08-21 — milestone 8: EG-13, an expense category is a row

The same shape as EG-11, built with EG-11's review already in hand — which is the point of doing
them back to back. All four cost journalizers and all eight surfaces converted in one pass, the
seeder wired into all three entry points, and the surface gate **generalised** to both catalogues
rather than duplicated.

| | |
|---|---|
| **What was wrong** | The category decided which P&L account every supplier bill, expense and custody spend hit, from a six-entry `private const` inside a journalizer trait. Insurance, government fees, bank charges, legal fees and generator fuel — most of an Egyptian mall's overhead — fell past it into `admin_expense` behind a `Log::warning` nobody reads |
| **Why an account, not a role** | `Health::accountingReadiness()` requires every `PostingRoles` key to be mapped, so "Insurance" as a role would turn a BLOCKING health row red on every install until the accountant mapped it. Same reasoning as the payment rails |
| **Two things D-1 did not name** | `CostNature::categoriesOf()` — the REVERSE direction — still read only the const, so a category marked `fixed` answered `fixed` one way and was ABSENT the other — any reader filtering by nature (the expense register, the weekly-spend report) would omit a cost that was itself classified correctly. And the three category columns had **no value set at all**, so the column accepted anything |
| **What the new enforcement caught immediately** | 13 fixtures billing under `hvac` and `services` — trade codes, not expense categories, that no form has ever offered. Every one of those bills was silently booking to `admin_expense`, so the fixtures were encoding the bug they were meant to be independent of |

**Corrections from the review of this milestone**, both of which I claimed the opposite of above:

| | |
|---|---|
| 🔴 | **I wrote an unverified causal claim into two languages.** "Fixed versus variable feeds the CAM pool, so it reaches what tenants are charged" is false: `App\Support\CostNature` has exactly three readers — the expense register, its filter, and the weekly-spend report — and CAM is not one. The service-charge split is `cam_pool_accounts.cost_nature`, a per-ACCOUNT pivot on a different table with the OPPOSITE default, and `docs/modules/08-cam.md` already said so. An accountant marking insurance `fixed` to stop it being grossed up would have changed nothing, having been pointed away from the real lever. The true statement was the one I had not written: pointing a category at an account that sits in a CAM pool DOES route those costs in, because the pool is built from the GL **by account** |
| 🔴 | **`is_active` was inert for every shipped category.** `options()` read `ValueSets`' floor ∪ active union, and the floor holds all six permanently — so switching one off left it in every picker, which the field help and both guides promised it would not. It reads its rows first now, like `PaymentMethod::options()` |
| 🟠 | **And I claimed "no false-pass test this milestone" in the commit message, which was itself false.** The behaviour-identical case passes the floor role in as an ARGUMENT, so deleting an entry from the map it exists to protect leaves it green |

The lesson is narrower than "check your work": all three are claims I could have falsified in under
a minute with a grep, and I asserted them instead because they were plausible. The two that reached
an operator's screen did so in two languages.

---

### 2026-08-21 — milestone 9: EG-14, a tenant can report a stuck lift

`TenantRequestType::subcategories()` returned seven maintenance values; `trades` seeds fourteen, and
the bridge between them was a **string match**. Seven trades the operator dispatches every week could
not be reported — lift, generator, fire safety, pest, security, landscaping, waste — so the tenant
picked "other" and the corrective work order was raised with **no trade at all**: invisible to every
by-trade report, to the craft rate the cost object reads, and to vendor eligibility.

A foreign key fixes it at the root, and bridges `fire_safety` → `fire-safety`, one hyphen apart,
which the string match could never have done. **That is the case the regression test uses**: most
subcategory codes equal their trade code, so a test built on `elevator` passes with the fix reverted
— which is what my first one did.

**A deliberate narrowing:** the request TYPE stays a PHP enum. It carries behaviour, and rows would
let an operator create a type the code has no answers for. Only the vocabulary moved. Per-type SLA
became one new tier on the register that already answers this per property, not a new table.

**Fixed after review** (2 🔴, 7 🟠): both request-list cells and the portal infolist still labelled
subcategories from a static lang group, so the seven new codes rendered as raw keys — `labelFor()`
existed with **zero callers**, which is EG-11's mistake verbatim, one milestone later. The helper
gate had **two** scanners and only the inline one got the tokenizer fix, so the shadow gate still
missed 35 names — they are one scanner now, which is what that function's own docblock always
claimed. The per-type SLA tier was **uncreatable**: no form field, no table column, and a `unique`
rule still citing the index this migration dropped. `sla_policies.request_type` shipped with no
`ValueSets` entry, `respondHoursFor()` was left type-blind beside a sibling whose docblock explains
why that is dangerous, and the catalogue had **no screen at all** — so nothing could add, retire,
re-point or translate a subcategory while three docblocks described an operator doing exactly that.

**Four things the ticket did not name:**

| | |
|---|---|
| 🔴 | **A nullable column inside a UNIQUE stops enforcing it.** My first cut made `sla_policies.request_type` nullable; SQL treats NULLs as DISTINCT, so two conflicting `urgent` policies for one property both saved and the resolver would take whichever the index returned first. The existing uniqueness test went green **because its expected exception stopped being thrown** — a test that asserts a throw silently passes when the throw disappears in the other direction |
| 🟠 | **MySQL refused the migration three ways sqlite would have accepted** — the 64-character identifier limit on an auto-generated index name; a unique that cannot be dropped while it is the only index backing a foreign key; and no transactional DDL, so each failure left partial state. Rollback verified both ways |
| 🟠 | **`request_type` is cast to the enum**, so `(string)` on it is a TypeError — the collision between a cast column and code expecting the raw value that `ValueSets` documents |
| 🔴 | **The helper-uniqueness gate had been blind since it was written.** I added a helper to `tests/Pest.php` that already existed in a test file; the suite exited **255 with zero bytes on both streams** and the gate stayed green. PHP's tokenizer emits `T_CURLY_OPEN` — an ARRAY token — for `"{$x}"` and a **plain** `}` for its close, so every interpolated string decremented the depth counter without incrementing it. A file with a few of them sat at negative depth for the rest of the scan and no file-scope function in it was ever recorded. `tests/Pest.php` is full of interpolation, so the one file CLAUDE.md tells you to put shared helpers in was precisely the one this gate could not see |

That last one is the most useful thing in this milestone. It is a **gate that reported on a set it
had silently stopped collecting** — the same class as the reconciliation check that could not fail
and the conformance sweep that matched zero models, and the third instance this project has found.
The tell was not the gate; it was a 255 with no output, which the gate's own docblock names as the
symptom it exists to prevent.

---

**Deployment notes, cumulative across all nine milestones:**

- **`docs/qa/scripts/baseline.sql` must be regenerated before `composer qa` or `composer test:mysql`.**
  It now predates four things: the `leases.billing_day` drop (milestone 1), the
  `tax.seller_billing_email` settings row (milestone 3), the `holidays` table plus the
  `calendar.*` / `sla.sla_working_clock_priorities` settings rows (milestone 4), and
  `tenant_requests.sla_clock` (milestone 5), the `payment_methods` table (milestone 7), the `expense_categories` table (milestone 8), and `tenant_request_subcategories` + `sla_policies.request_type` (milestone 9). The settings rows are the sharp ones — an
  **exception**, not drift: `reset.sh` restores the dump without migrating, so the first
  `app(TaxSettings::class)` / `app(CalendarSettings::class)` throws `MissingSettings`. Run
  `composer qa:baseline` (needs MySQL).
- **`RolesPermissionsSeeder` must be re-run** for the `holidays.*` permissions (milestone 4), the
  `payment_methods.*` (milestone 7), `expense_categories.*` (milestone 8),
  `tenant_request_subcategories.*` (milestone 9), `retail_categories.*` (milestone 10) and
  `violation_categories.*` / `vendor_document_types.*` (milestone 11) permissions.
  A permission that exists only in the seeder file leaves the screen absent from the navigation for
  everyone, super_admin included, with no error to say why.
- ~~`docs/PROJECT-MAP.md`'s generated census is stale.~~ **Regenerated 2026-08-20** — the other
  session's work had landed, so the counts are now honest.

### 2026-08-21 — milestone 10: EG-23 part 1, the merchandising mix and the frozen org chart

*(Written up on 2026-08-22 with milestone 11 — the commit shipped without its progress entry, which
is the same omission as the create page it also shipped without.)*

**D-2 — the merchandising mix** was twelve values in a const on `Tenant`, driving the store
directory, the public shopper API's category filter and every tenant-mix report an owner reads. Yardi
and MRI make it a row for a reason a leasing team would recognise: the mix is their working
vocabulary, revised per mall and per season. Registering the value set closed a second gap — 
`tenants.retail_category` had NO set, so a typo'd or imported value saved cleanly and then matched no
filter in the shopper app, invisible in the directory while looking correct on the tenant record.

**D-6 — departments** were five seeded English-only names behind `canCreate() => false`. Tenant
requests ROUTE to a department, so the freeze reached the routing and not only the org chart.
`name_ar` was added and the seeded rows backfilled. **Half of this was not actually delivered** — see
milestone 11.

**A bug in all FOUR shipped catalogues:** the label memo was not keyed by locale, so a request that
switches language — every PDF service and every queued notification does — read the other language's
cache. Fixing it four times is what made the shared concern in milestone 11 obviously right.

### 2026-08-22 — milestone 11: EG-23 part 2, the last two registers and the seam under all six

D-4 and D-5 are the same shape as the four before them, so the first work of this milestone was
**not** the fifth and sixth copy: `App\Models\Concerns\IsCodeCatalogue` now holds the memo, the
flush, the labels, the floor and the options for all six, and the four shipped catalogues were
retrofitted onto it. That paid for itself immediately — `TenantRequestSubcategory`'s hand-written
flush dropped `…map.labels` while `labelFor()` filled `…map.labels.{locale}`, so **the key it
invalidated had never existed**. An operator renaming a subcategory saw the old word for the rest of
the request, and on a `queue:work` daemon — one long-lived process — for the rest of the day. Four
copies of a thing is four chances to get it wrong and one chance in four of noticing.

**D-4 — the house rules.** Seven values in a const, on a column whose own migration promised in
writing that "the operator's set of violation types is theirs to extend without a migration". It also
had **no `ValueSets` entry at all**, so the column accepted anything: a typo or an import saved
cleanly and then matched no filter and no repeat-offender report, while looking correct on the
record. The row carries the standard fine, because a rule book is a schedule of penalties and a field
officer at the shop door should not be recalling the tariff — a PREFILL only, driven through the
form's own `->live()` callback and proved by mutation in both directions (removing the prefill, and
removing the guard that stops it overwriting a typed figure).

**D-5 — and the one field that is not vocabulary.** `VendorDocument::BLOCKING_TYPES` was a
one-element array literal deciding **who may be sent onto the mall floor**. Insurance is the right
default and it still ships as the default; what was wrong is that an operator told a lapsed
social-insurance certificate blocks site work — which an Egyptian principal dealing with a government
client may well be told, since they carry the contractor's unpaid contributions — needed a deploy to
say so. The failure direction is what took the care: `whereIn('type', [])` matches NOTHING, so a
catalogue answering an empty list would have made every uninsured contractor dispatchable with no
error anywhere and every existing test still green. The floor therefore applies when the table holds
**no rows**, not when the blocking list is empty — an operator who unticks everything meant it —
and INACTIVE types still block, because `is_active` governs what may be filed, not what counts.

**Three tests were already red on `main` before this started, and are fixed here.** Two were mine
from part 1:

| | |
|---|---|
| 🔴 | **D-6 shipped its gate and not its door.** `fd1ea2d1` removed `DepartmentResource::canCreate()`'s hard `false` and the commit message said "a department can be added". There was no `create` route, no page and no button — so `departments.create` was a permission that granted nothing, the form's `disabledOn('edit')` branch was unreachable, and the register was as frozen as before. `CreateDepartment` exists now, with the same both-ends portfolio guard `EditDepartment` carries. **The test I wrote for it was a false pass first**: `assertActionExists('create')` and `Livewire::test(CreateDepartment::class)` both stay green with the route deleted, because a component can be instantiated without one and an action object exists whether or not it navigates anywhere. `assertActionHasUrl(...)` is the assertion that fails |
| 🟠 | **Seven catalogue tables had a form and no read-only view**, five of them from earlier milestones. `ViewActionCoverageTest` had been reporting them by name and nobody read it. A role holding `.view` and not `.edit` could not open a row at all |
| 🟠 | **A fixture writing a value no form offers**, the third instance. `ExpenseTest` posted an expense under category `insurance` to exercise the unmapped-category warning; EG-13 gave that column a value set, so the case died on its fixture. It seeds and activates the real catalogue row now, which is a truer reproduction — the warning exists for a category nobody has pointed at an account. `ResourceLinkConformanceTest` had the same shape with `vendor_documents.type = 'insurance'` (the six shipped codes have never included it), which the new value set caught on its first run |

**What did not change:** `TenantRequestType` stays a PHP enum, and so does everything else that
carries behaviour. Only vocabulary becomes rows. And the activity log still renders an operator-added
code as its raw code — one documented gap, six catalogues, exempted by name in
`CatalogueBackedSurfacesConformanceTest` rather than left to be discovered.


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
