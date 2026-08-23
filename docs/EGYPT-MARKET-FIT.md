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
   *"this lease is taxed, that one is not"*, because taxability is portfolio-wide per charge code
   ([§4.1](#41-tax)). *(It was **also** frozen onto each lease's charge rows the day the lease was
   created — fixed 2026-08-22, EG-01. A ruling now reaches every existing lease; what remains missing
   is the lease/unit DIMENSION, EG-02.)*
2. **Statutory payroll numbers change every January.** They were three flat, undated settings with no
   insurable-wage cap; since 2026-08-22 they are a dated ladder with the band (EG-03). What remains is
   the progressive bracket table and the personal exemption — and whether the operator wants
   statutory payroll computed at all is a question for them first ([§4.2](#42-payroll), §6.4).
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
| 2 | **Tax *treatment*** (who is taxed) | 🟠 SEMI | A row per charge code, resolved at billing so a ruling reaches existing leases (EG-01, 2026-08-22) — but still **portfolio-wide**: no lease or unit dimension (EG-02) |
| 3 | **Chart of accounts / posting map** | 🟢 DYNAMIC | 51 roles re-pointable globally or per property; the real handover point for a new chart |
| 4 | **Billing policy** (late fee, terms, ageing, fiscal year, numbering) | 🟢 DYNAMIC | The CFG cycle shipped this properly, incl. 3-tier lease→property→portfolio resolution |
| 5 | **Approvals** | 🟢 DYNAMIC | `approval_rules` bands, fail-closed; no multi-level chain |
| 6 | **Roles** | 🟢 DYNAMIC | Full role CRUD with permission sync and audit; permission *keys* stay in code (correct) |
| 7 | **Reporting parameters** | 🟢 DYNAMIC | Saved views, per-user memory, scheduled email delivery, CSV+XLSX |
| 8 | **Reporting *shape*** | 🔴 HARDCODED | No report builder; every column is a PHP literal; statement layout is a `match()` on account type |
| 9 | **Master-data lists** | ✅ DONE | Trades, failure codes, charge codes, tax codes, SLA policies, rent indices, and the six operator catalogues sharing `IsCodeCatalogue` — payment rails (EG-11), expense categories (EG-13), tenant-request subcategories (EG-14), the merchandising mix, house rules and supplier document types (EG-23) — are all rows, as are departments. The request TYPE stays an enum on purpose, because it carries behaviour |
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
of what Egyptian law now requires. It was **also** frozen per lease until 2026-08-22 (T-2/T-3, EG-01);
that half is fixed and the missing dimension (T-1, EG-02) is not.

| # | Finding | Where | Rating |
|---|---|---|---|
| T-1 | **Taxability has no lease or unit dimension.** `Vat::rateForType($type, $on)` resolves *charge code → tax code → dated rate*. There is no third input. Whether base rent is taxed is one answer for the whole portfolio | `app/Support/Vat.php:125-140`; `app/Models/ChargeCode.php` | 🔴 |
| ~~T-2~~ ✅ | **FIXED 2026-08-22 (EG-01).** Nullable, null the normal state, every row backfilled, and `resolvedVatRate()` now tests `=== false` rather than falsy — so a ruling reaches every existing lease and an explicit per-charge exemption still wins. Thirteen derived writes removed, not eleven. ~~`vat_applicable` is frozen onto each recurring charge row at creation~~, from whatever the catalogue said that day: `'vat_applicable' => Vat::rateForType('base_rent') > 0`. `Charge::resolvedVatRate()` short-circuits to `0.0` when it is false, so **an accountant's later ruling can never reach an existing lease** | `app/Services/LeaseCreationService.php:127`; `app/Models/Charge.php:289-295`; and 10 sibling sites: `LeaseRentChangeService.php:109,116` · `LeaseSpaceChangeService.php:229` · `ConvertLeaseToHoldoverService.php:136` · `MarketingLevyService.php:81` · `ApplyCamEstimateService.php:90` · `ChargeScheduleService.php:541` · `AssignRentableItemService.php:221` · `ChargeScheduleRelationManager.php:307` · `UnitOwnershipChargesRelationManager.php:232` · `ChargeImporter.php:135` | 🔴 |
| ~~T-3~~ ✅ | **FIXED 2026-08-22 (EG-01),** together with T-2 — the two are one bug read at two layers. ~~Base rent additionally writes a non-null `vat_rate` override (0.00)~~, re-introducing at the *rate* layer exactly what migration `2026_08_12_200000_charge_vat_rate_is_an_override` removed. The service-charge block **two lines below does it correctly** (`'vat_rate' => null` with the comment *"null = the catalogue answers at billing time; a value is an override"*) — so the same file contains both the bug and its fix | `app/Services/LeaseCreationService.php:128` vs `:145`; also `LeaseSpaceChangeService.php:230` · `ConvertLeaseToHoldoverService.php:137` · `AssignRentableItemService.php:222` | 🔴 |
| T-4 | **Making base rent taxable turns a conformance gate red.** `ChargeCodeVatTreatmentConformanceTest` asserts the catalogue's exempt set `toEqualCanonicalizing(Vat::EXEMPT_TYPES)` — so the accountant's row change must be paired with a PHP edit. Correct as a design (floor and catalogue must agree), but it means *"change the tax treatment of rent"* is **not** a no-deploy operation | `tests/Feature/Scenarios/ChargeCodeVatTreatmentConformanceTest.php:36`; `app/Support/Vat.php:EXEMPT_TYPES` | 🟠 |
| T-5 | **A new withholding rate cannot be entered from the screen.** The rates relation manager sets `->minValue(0)` while every `WH_*` rung is negative by construction and the conformance test *requires* it | `app/Filament/Admin/Resources/TaxCodes/RelationManagers/RatesRelationManager.php:46`; `database/seeders/TaxCodeSeeder.php:219` | 🟠 |
| ~~T-6~~ ✅ | **FIXED 2026-08-22 (EG-21).** `WithholdingTaxReturnService` + a quarterly Form 41 page + a per-supplier certificate PDF. The tie-out is the point of the screen: what was DEDUCTED from suppliers against what the LEDGER owes the ETA, two independent reads that must agree before a number becomes a filing position. ~~The engine was correct and dated — per-vendor code → portfolio default → 0, on the VAT-exclusive share — and there was no Form 41 report and no per-vendor certificate, so `VatReturn.php` had no WHT sibling.~~ | `app/Services/Reports/WithholdingTaxReturnService.php` | 🟠 |
| T-7 | **Tenant-side WHT unmodelled** — a tenant who withholds from rent reconciles as an underpayment forever | `docs/OPEN-QUESTIONS.md` A2.1 | 🟠 |
| T-8 | **Real-estate tax and municipal levies are not a statutory cost.** Recovery via a CAM pool works; the *liability* has no rate, no rental-value basis, no assessment, no due dates. There is **no recurring-expense concept anywhere** in the system — recurrence exists only on the revenue side | grep `recurring` over `app/Models` + `app/Services` → revenue-side only | 🟠 |
| T-9 | **Tax depreciation rates are PHP constants** (5/10/25/50%, Law 91/2005 art. 25) | `app/Support/TaxDepreciation.php:52-58` | 🟡 |
| T-10 | **One seller identity for the whole install.** `seller_tax_registration_number` and `seller_legal_name` are single portfolio-wide settings; `IssuingEntity` documents the intended per-asset override and does not have it. Two owners with two VAT registrations cannot both be billed correctly | `app/Settings/TaxSettings.php:65,68`; `app/Support/IssuingEntity.php:27-30,69,87` | 🔴 |

> **The concrete Egyptian scenario T-1..T-4 fails.** Suppose the executive regulations land and the
> accountant rules: *administrative units in the mall carry 1% schedule tax; retail shops stay
> exempt.* Today that requires (a) a second charge code, since taxability is per code; (b) a PHP edit
> to `Vat::EXEMPT_TYPES` and the gate. ~~and (c) **it still would not reach any existing lease**,
> because every rent charge row already carries `vat_applicable = false` frozen at creation.~~
> **(c) was fixed 2026-08-22 (EG-01)** — taxability is resolved at billing, so a ruling now reaches
> every lease already on the books, and the thirteen origination sites no longer freeze the answer.
> What is left is (a) and (b): the fix for those is not a bigger settings screen but a
> **lease/unit-level tax treatment** as a third input to the resolver — EG-02, which waits on the
> accountant's ruling rather than on code.

### 4.2 Payroll

The one area where the books may be quietly wrong on data the system already holds. *(Two of the three 🔴 rows — the missing insurable-wage band and the undated rates — were fixed on 2026-08-22 by EG-03. The bracket engine is the one that remains, and it is a question for the operator before it is work.)*

| # | Finding | Where | Rating |
|---|---|---|---|
| ~~P-1~~ ✅ | **FIXED 2026-08-22 (EG-03).** `payroll_rates.insurable_wage_floor` / `_ceiling`, clamped by `PayrollRates::insurableWage()` and applied to BOTH shares. A null bound means no bound — not zero, which on the ceiling would insure everybody on nothing. ~~No insurable-wage floor or ceiling.~~ The SI rate is applied to the employee's whole `base_salary`. Every employee above the ceiling is over-deducted and the employer over-accrues — and Egypt raised the ceiling to EGP 16,700 on 1 Jan 2026. The employer-share line even carries the comment *"Employer SI is a company cost — it does NOT reduce net, so no cap needed"*, which is not how the Egyptian cap works (it caps the **wage**, both shares) | `app/Services/GeneratePayrollService.php:65,72,143`; `app/Settings/PayrollSettings.php:16` describes "a capped subscription salary" and implements no cap | 🔴 |
| P-2 | 🔴 **STILL OPEN — and it waits on the operator, not on code (§6.4).** The dated ladder EG-03 shipped is what a bracket table hangs off. ~~**Income tax is a single flat rate against full gross.**~~ **Income tax is a single flat rate against full gross.** No brackets, no personal exemption — neither exists anywhere in the codebase. A flat rate cannot approximate a 7-band progressive schedule. Ships at `0.0`, so out of the box **no salary tax is withheld at all** | `PayrollSettings.php:25`; `GeneratePayrollService.php:45,142` | 🔴 |
| ~~P-3~~ ✅ | **FIXED 2026-08-22 (EG-03).** A dated ladder resolved for the run's `period_month`, so a January run generated in September computes on January's numbers — proven by driving the real generator, not just the resolver. ~~No dated payroll rates.~~ `generate()` reads the settings with **no date argument**, so a January run generated in March uses March's numbers, a rise cannot be entered in advance, and nothing records what rate a past run used. Contrast `TaxCode::rateOn($code, $on)`, which is the correct shape sitting in the same codebase | `GeneratePayrollService.php:44-47` vs `app/Models/TaxCode.php:243,314-331` | 🔴 |
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
| M-2 ✅ | **FIXED 2026-08-22 (EG-30).** ~~Billing in advance only, cycle anchored to the lease. No arrears option; a service charge or utility recharge billed in arrears has no home.~~ `charges.billing_timing` (nullable, null = advance) + `MonthlyBillingService::coveredWindow()`; arrears lines ride the same invoice and name their own month | `MonthlyBillingService.php:367-371,548`; `DeterminesFitOutGrace.php:185-199` | 🟠 |
| M-3 ✅ | **FIXED 2026-08-20 (EG-19).** ~~`config/billing.php:14-16` late-fee keys are dead and look alive.~~ They read `env('LATE_FEE_PERCENT')` etc.; nothing consumes them (the 12 `billing.late_fee_*` hits in `app/` are `PropertySettings` keys in a same-named namespace, not `config()` reads). A deployer setting `LATE_FEE_PERCENT=3` gets silence. **Cheapest fix in this document: delete the three keys** | `config/billing.php:14-16`; live path is `PropertySettings.php:49-57` + `LateFeeService.php:107-109` | 🟠 |
| M-4 ✅ | **FIXED 2026-08-20 (EG-20).** ~~`leases.billing_day` is an inert column~~ — fillable, cast, migration comment *"day of month to issue invoice"*, **zero readers**. Combined with M-5, a multi-mall operator has exactly one billing date for the whole portfolio | `app/Models/Lease.php:373,411` | 🟠 |
| ~~M-5~~ ✅ | **FIXED 2026-08-21 (EG-18) — and it was NOT "one line each".** `auto_apply_tenant_credit` was: one registry line plus pointing its single read at the resolver. `monthly_billing_day` was not, and the row was wrong about it: the value drives `->monthlyOn()` on the SCHEDULER, which is one process for the whole portfolio, so an override would have been a setting the operator saves and nothing can honour. Both runs now fire DAILY and ask each property whose day it is, clamped to the month's last day — unclamped, a property set to the 31st skips seven months of the year. ~~`monthly_billing_day` is portfolio-wide and capped at 28~~ | `app/Support/PropertySettings.php`; `app/Jobs/RunMonthlyBilling.php` | 🟠 |
| M-6 ✅ | **FIXED 2026-08-22 (EG-30).** ~~Escalation is annual-only — `->addYear()`, and no `escalation_frequency` column exists. A biennial or 18-month clause cannot be automated.~~ `leases.escalation_interval_months` (nullable, null = 12) read through `Lease::escalationIntervalMonths()` | `app/Services/RentEscalationService.php:196` | 🟠 |
| M-7 ✅ | **FIXED 2026-08-20 (EG-09).** ~~`leases.escalation_type` has no `ValueSets` entry~~ — a freed `string(32)` column whose options live in a **translation array**. The wildcard save-listener does not refuse out-of-set values here, so an import can write `annual_increase` and the sweep silently skips that lease forever. This is the exact pattern CLAUDE.md bans after the `Trade.category` episode | `lang/en/admin/statuses.php:311`; migration `2026_08_10_240000_...:44`; absent from `app/Support/ValueSets.php` | 🔴 |
| ~~M-8~~ ✅ | **BOTH HALVES SHIPPED 2026-08-22 (EG-35).** The cap first (`leases.late_fee_maximum`), then RECURRENCE — `leases.late_fee_recurrence_days`, same three tiers, **0 = charge once** so nothing moves on deploy. It needed the schema change the cap did not: `invoices.late_fee_for_invoice_id` is the fee's pointer back at what it penalises, which is what makes *"which fees came from this invoice"* answerable once there can be more than one. ~~**Late fees have no cap and no compounding option.**~~ One fee per invoice; a large arrears produces an uncapped penalty and a six-months-late tenant is penalised once. Neither is settable, and both are things a real clause states | `app/Services/LateFeeService.php:130-135,158` | 🟠 |
| M-9 | **Document numbers reset monthly, per property, by construction** — only the prefix letters are configurable; the mask is a `sprintf`. Egyptian tax-invoice series are conventionally expected to run continuously. Worth a deliberate decision **before go-live**, because it is not renumberable afterwards | `app/Models/Concerns/Invoice/AllocatesInvoiceNumber.php:33,38-44` | 🟠 |
| M-10 | 🟡 **DEFERRED deliberately (2026-08-22).** 540 money sites, every stored figure re-derived, and the finding's own premise is conditional — *"an accountant asking for banker's rounding cannot have it"*, and none has asked. It is a real gap and a bad thing to do speculatively. ~~**Rounding is 2dp with PHP's default mode, in 540 places.**~~ No `PHP_ROUND_HALF_*` anywhere, no config. An accountant asking for banker's rounding cannot have it | 540 `round(…, 2)` under `app/` | 🟡 |
| ~~M-11~~ ✅ | **FIXED 2026-08-22 (EG-35).** `BillingSettings::default_security_deposit_months`, per-property overridable. The policy had been the literal `3` in `LeaseCreationService`'s `$rent * 3` — sharper than this row said, since it was not merely absent but hardcoded. ~~**No deposit default at portfolio or property level**~~ — months-of-rent is per lease only, so a policy change ("3 months from Q1") reaches nothing | `PropertySettings::OVERRIDABLE` has no deposit key | 🟡 |
| M-12 | 🔴 **STILL OPEN, and bigger than it reads (checked 2026-08-22).** Not a schedule change: `cam_expense_pools` is `unique(asset_id, period_year)`, so a quarterly true-up needs the POOL to have a period shorter than a year — schema, apportionment and reconciliation all move. ~~**CAM reconciliation is annual-only**~~ on a scheduled month/day; a quarterly true-up cannot be automated **Tracked as EG-41** — promoted out of EG-35, where it had been mis-scoped as part of an M. | `routes/console.php:78-84` | 🟡 |
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
| D-7 | ✅ **BUILT 2026-08-23 (EG-32 slice 2).** The operator defines their own fields at `/admin/custom-fields` and they appear on tenants, leases, units, vendors and properties. **The five unread `metadata` columns were the answer, not just the evidence** — answers live on the record, so no join, no N+1, and an export is a column read. `units` gained the one new column. Only keys the catalogue defines are ever written, and `metadata` is no longer `fillable` on any of the five. **Slices 3–4** add a list column, a typed filter, database sorting, export columns, IMPORT columns and the global-search blob. Deploy step: `atriom:rebuild-search` | `app/Support/CustomFields.php`; `app/Models/CustomField.php`; `app/Models/Concerns/HasCustomFields.php` | 🟢 |
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
| ~~X-7~~ ✅ | **FIXED 2026-08-22 (EG-12).** `bank_account_id` on the six documents a bank statement can explain, and `App\Support\MoneyAccount` — the one seam all THIRTEEN journalizers now resolve through: the document's bank account → the rail's account → the posting role. ~~Nothing records which bank account a receipt landed in.~~ | `app/Support/MoneyAccount.php` | 🟠 |
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
| S-3 | 🟡 **HALF FIXED 2026-08-22 (EG-27).** The invisible-money half is closed: every statement now declares what it is leaving out, with the amount and the remedy. The **consolidated view is still unreachable**, deliberately — reaching it reopens a decision already taken the other way. ~~**Consolidated books exist in the service layer and are unreachable in the panel.**~~ The property switcher never offers "All Properties" and the report picker is pinned+disabled. Combined with `whereIn('je.asset_id', $ids)` never matching NULL, **any operator-level or cross-property journal entry is invisible in every financial statement an operator can open.** Module 21's doc still advertises "per-property & consolidated" | `app/Models/User.php:151-153`; `app/Support/Filament/PropertyField.php:146-147`; `LedgerReportService.php:472` | 🔴 |
| S-4 | ✅ **CLOSED 2026-08-22 (EG-28).** Cash flow is driven by `ledger_accounts.cash_flow_section`, not by code prefixes; the `parent_id` rollup now drives statement layout via `App\Support\StatementGroups`; the chart IMPORTER shipped the same day. ~~**Financial-statement layout is a PHP `match()` on `ledger_accounts.type`**~~, and the chart's own `parent_id` rollup hierarchy is read by **no report**. The cash-flow statement classifies by **literal code prefixes** (`111`, `121`, `12`, `22`…). **If the accountant hands over a different Egyptian chart:** a chart not numbered 1–5 by nature is *refused at save*; one numbered 1–5 with different sub-ranges *saves fine and silently misclassifies the cash-flow statement* — and `reconciled` will not catch it, because it only re-asserts the double-entry identity. There is also **no chart importer** | `app/Support/StatementGroups.php`; `LedgerReportService.php:163-227,280-303`; `app/Models/LedgerAccount.php:39-45,131-148,197-209` | 🟢 |
| S-5 | ✅ **CLOSED 2026-08-23.** Columns are toggleable AND reorderable on every table in both panels, and the choice is durable and shareable — a saved list view or saved report stores the toggles and the order. **The finding was overstated on its first half and right on its second.** *"No user-defined columns"* was **overstated**: this app marks **173** columns toggleable and Filament v4 ships a column manager — work orders offer 13 optional columns, tenant requests 10. What was true is the second half: a saved view stored **filters/sort/search/tab only, never columns**, and Filament persists a layout in the SESSION, so the choice did not travel with a shared view and was gone tomorrow. Columns now ride the view as `?tableView={id}`. **Not built, and stated:** a drag-and-drop report DESIGNER, and user-defined GROUPINGS. Widening which columns a given report or list offers as optional is editorial — `ListInvoices` still offers none | `app/Support/Filament/SavedColumnLayout.php`; `app/Support/TableDefaults.php`; `app/Filament/Admin/Pages/Concerns/SavesReportViews.php` | 🟢 |
| S-6 | 🟡 **HALF FIXED 2026-08-22 (EG-15 slice 1)** — `document_templates` + a screen now carry the invoice's footer, payment instructions and terms, property-overridable with the old lang key as the floor. **Messages are untouched**: no mail tab, no dunning wording, and still no `RichEditor` in the app. ~~**No operator-editable document or message templates anywhere.**~~ No `document_templates` table, no terms/footer settings field, **no `RichEditor` in the entire app**, no mail tab on the settings page. Every invoice footer, dunning letter and SLA email is a deploy. **This is the single largest "the operator cannot run their own business" gap** | searches named; `app/Filament/Admin/Pages/Settings.php:91-98` | 🔴 |
| S-7 ✅ | **FIXED 2026-08-21 (EG-05).** ~~A fake `.test` address prints on every issued invoice PDF.~~ `__('admin.pdf.footer')` interpolates `billing@:slug.test` — rendering e.g. `billing@atriom-walk.test` on a legal tax document, and on tenant/asset statements. Verified in all four lang files. **One settings field plus four string edits; cheapest item in this report and the most embarrassing** | `resources/views/invoices/pdf.blade.php:332`; `lang/en/admin/reports.php:324`, `lang/ar/admin/reports.php:323`, `lang/en/admin/accounting.php:439`, `lang/ar/admin/accounting.php:432` | 🔴 |
| ~~S-8~~ ✅ | **FIXED 2026-08-21 (EG-16), completed after review.** The first cut reached all twelve TEMPLATES and only seven SERVICES: five called `forView()` with no asset, so the owner statement — the document Jawad actually receives — rendered `$asset` in its own party block while the logo beside the issuer name was unconditionally absent. The gate only checked the `@include` was present. Both halves are gated now, and a report filtered to one mall carries that mall's letterhead via `forViewScopedTo()`. ~~No mall logo on any PDF~~ | `app/Support/IssuingEntity.php` | 🟠 |
| S-9 ✅ | **FIXED 2026-08-21 (EG-06).** ~~`ext-intl` is undeclared while 260 money columns depend on it.~~ `composer.json` `require` has no `ext-*` at all; `Number::currency()` throws without intl. The codebase already documents the hazard for a different call site. **A deploy box without intl 500s every list and dashboard showing money** | `composer.json:11-28`; `app/Support/Search/SearchText.php:111-113` | 🔴 |
| ~~S-10~~ ✅ | **FIXED 2026-08-21 (EG-17).** `resources/views/vendor/mail` is published and the layout carries `dir`, so all eleven `MailMessage` notifications render RTL in Arabic. The theme was closer than the finding suggested — it already used the logical `text-align: start` nearly everywhere; the gap was two `left` rules and one `border-left` on the panel accent. The published copy is deliberately a MINIMAL fork (one attribute, three properties) so a framework upgrade stays readable. ~~21 of 23 email templates are LTR~~ | `resources/views/vendor/mail/html/layout.blade.php`; `.../themes/default.css` | 🟠 |
| ~~S-11~~ ✅ | **FIXED 2026-08-22 (EG-22).** The portal skins itself per property the way the admin panel has since it gained tenancy. The rule is **exactly one mall, or the platform**: a tenant trading in three sees Atriom, because branding their portal as one of the three is a claim about the other two. Reviewing it found the same question answered differently one document along — `TenantStatementPdfService` took `leases->first()?->unit?->asset`, so a chain's Statement of Account carried one ARBITRARY mall's letterhead. Both read the invoices' own `asset_id` now and fall back to the operator, so a tenant is not told two different things by two of our own documents. The palette derivation moved to `PanelBranding` and both panels read it, rather than a second copy that can drift. ~~untranslated English literal, static logo/favicon, no colour hook~~ | `app/Support/Filament/PortalBranding.php` | 🟠 |
| ~~S-12~~ ✅ | **FIXED 2026-08-22 (EG-36).** 24 journalizers, not 29 — counted by grep rather than taken from the row. ~~**29 journalizers write Arabic prose literals into `journal_entries.description_ar`**~~ at post time. This directly contradicts the project's own *"stores DATA, never PROSE"* rule: a wording fix needs a deploy **and** never reaches rows already posted. The fix pattern (`name_en`/`name_ar`, or `ActivityVocabulary`'s read-time resolution) is already in the codebase | `app/Services/Accounting/Journalizers/*.php` | 🟠 |
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
| ~~**EG-01**~~ ✅ | **DONE 2026-08-22.** The row was right about the shape and understated the damage. `vat_rate` had been half-fixed on 2026-08-12 — the service-charge block, not the base-rent block two lines above it — but `vat_applicable` was untouched, `boolean default(true)` NOT NULL, written by thirteen services from `Vat::rateForType($type) > 0`. **That is the worse half**: `resolvedVatRate()` tests it FIRST and returns before the catalogue is consulted, so a `base_rent` row born `false` can never become taxable again, and a rate the operator deliberately TYPED was discarded with it. Measured before changing anything — charge code pointed at `VAT_14`, resolver answered 14.0, charge resolved 0.0, billing run raised a rent line with **0.00 VAT where 14,000 was due**. Both columns are now nullable with null the normal state, every row backfilled, and the test is `=== false` rather than falsy. **No screen ever offered `vat_applicable` as a tick** — all three UI/import sites derived it — so nothing an operator had stated was lost: their channel is the rate they type, and `vat_rate = 0` still holds a supply untaxed. One trap the ticket could not have named: `TransferUnitOwnershipService` copied the flag through a `(bool)` cast, which turns null into false, so a resale would have re-frozen the row one unit at a time | T-2, T-3 | 🧑‍💻 | M |
| **EG-02** | **Give tax treatment a lease/unit dimension.** An effective-dated tax treatment on the lease (or on the unit's type), resolved through `Vat::rateForType()` as a third input, so *"admin units taxed, retail exempt, from date D"* is expressible. This is what Law 157/2025 and the pending executive regulations actually require | T-1, §3.1 | 🧑‍💻 + 🔑 | L |
| **EG-03** | 🟡 **PART DONE 2026-08-22 — P-1 and P-3 shipped, P-2 still open.** `App\Support\PayrollRates::for($periodMonth)` is built as asked, mirroring `Vat::rateForType($code, $on)`, over a `payroll_rates` ladder: **a row is a SET of figures, not a key/value pair**, because Egypt publishes the band and the rates together in one decree. The 1 Jan 2026 rung ships with the band (2,700 / 16,700) and **not** the rates — seed the vocabulary, not the numbers, the same call `TaxCodeSeeder` makes; a migration that started withholding 11% from every salary would be this software deciding to deduct money from people. The band only bites through a non-zero rate, so nothing moves on deploy. `GeneratePayrollService` now resolves for the run's own `period_month`, charges SI on the **insurable wage** and tax on the whole gross, and the cap binds the employer share too. The three settings are **gone** (settings hold policy, master data holds rates) and EG-04's health row reads the ladder, as this row asked. **P-2 — the seven-band progressive engine with a personal exemption — is deliberately NOT built**, because §6.4 asks the operator a prior question: whether they want statutory payroll computed at all or keyed per run. Brackets are rungs with more columns, so the ladder is what they will hang off | P-1, P-2, P-3, §3.2 | 🧑‍💻 + 🔑 | L |
| ~~**EG-04**~~ ✅ | **DONE 2026-08-20.** `payroll_rates_configured`, in a new `payroll` category. **Not** the blocking-on-zero-rates row this line originally asked for: the settings screen's own help offers *"leave at 0 and enter it per employee"* as a supported posture, so a red row saying otherwise would contradict the field help beside it. It fires on **evidence** — BLOCKING when the latest payroll month's approved runs withheld nothing at all (net = gross, no liability in the books), ADVISORY when there is a roster, every rate is still nil and nothing has been approved yet. Scoped to the **latest** month so the row can clear, because an approved run's amounts are frozen and an all-time count would pin a red dot with no remedy but cancelling a real payroll | P-4 | 🧑‍💻 | S |
| ~~**EG-05**~~ ✅ | **DONE 2026-08-21.** `TaxSettings::seller_billing_email`, resolved through `IssuingEntity` like the seller's other particulars and **omitted when unset** — the same contract the TRN has. **THREE documents, not two**: the owner-facing asset statement carried the same fabrication. Pinned by a sweep that fails on any `@…​.test/.example/.invalid` in a lang file or a Blade. It also surfaced a **live 500**: `invoices.lease_id` became nullable when module 37 started billing owners, and the template dereferenced it — so every صيانة assessment invoice's PDF crashed on the list, the edit page, the portal and the API. Fixed by resolving the invoice's context from its AGREEMENT (lease **or** ownership) | S-7 | 🧑‍💻 | S |
| ~~**EG-06**~~ ✅ | **DONE 2026-08-21.** Declared (`intl`, `mbstring`, `zip` — what the app itself calls; the rest arrive through the tree). **The report overstated the risk and understated the real one:** `filament/support` already hard-requires intl, so `composer install --no-dev` refuses on a box without it. What composer structurally *cannot* see is the SAPI split — it runs under `php-cli`, the money columns render under `php-fpm`, and a box with intl in one and not the other installs, schedules and passes console health while throwing on every list. So the substance is a **runtime** check: `App\Support\PhpExtensions` (nine extensions, each with what it costs) read by `/health` over HTTP | S-9 | 🧑‍💻 + ⚙️ | S |
| ~~**EG-07**~~ ✅ | **DONE 2026-08-20.** The picker is gone and `ValueSets` now refuses a non-EGP value on `vendor_contracts.currency` **and** `assets.currency` — the guard, not the dropdown, is what makes it true. The rule both screens follow is stated: **a currency field survives only where the value is PRINTED**, so the asset's stays (it leads the owner statement) visible and read-only with a server-side `Rule::in`, and the vendor contract's went. Not inert, which is why it ranked: the contract value feeds the SLA-penalty basis, so a foreign number reached the GL | X-3 | 🧑‍💻 | S |
| ~~**EG-08**~~ ✅ | **DONE 2026-08-21.** `holidays` (a register, because Egypt's are ANNOUNCED — the Eids move with the moon and mid-week holidays shift to the Thursday), `CalendarSettings` (Sun–Thu, 09:00–17:00) and `App\Support\WorkingCalendar`. The SLA clock each job is promised on is **frozen onto the job**, and the feature **ships off** — `SlaSettings::sla_working_clock_priorities` is empty, so nothing changes until the operator rules on which priorities are office work. **Three deliberate narrowings from this row, all argued below:** the working WEEK is portfolio-wide (individual dates are per property); PM compliance is excluded; and the reporting week is untouched | C-1..C-4, §3.4 | 🧑‍💻 | L |
| ~~**EG-09**~~ ✅ | **DONE 2026-08-20.** Registered (`none · fixed_percent · fixed_amount · cpi`), and the lease form's options now DERIVE from the registry rather than from the label catalogue, so the picker cannot offer what the model would refuse. It also closed the drift that proved the point: the field help advertised a **"Step"** type that existed in neither list, and omitted `fixed_amount`. Why the sweep missed it: the column stopped being a DB enum on 2026-08-10, two days before the generator read the live schema | M-7 | 🧑‍💻 | S |
| **EG-10** | ✅ **DECIDED AND BUILT 2026-08-23, by market standard.** Atriom shipped a MONTHLY reset (`INV-AW-202608-0417`) — twelve series per mall per year, a convention **no major system uses**: SAP, Oracle, NetSuite and Odoo all reset accounting document numbers per YEAR, while Yardi and MRI use continuous control numbers. Default is now **annual** (`INV-AW-2026-0417`), and the scheme is a SETTING (`never` · `annual` · `monthly`) because every one of those systems treats a number range as configuration. **An install that has already issued an invoice stays monthly** — the migration reads the books first, so no live series is ever split. **Calendar year, not fiscal**: SAP resets per fiscal year, deliberately not copied, because a March-2027 invoice numbered `…-2026-…` reads as a mistake to anyone who is not an accountant — an operator on an April→March year should choose `never`, which is Yardi's behaviour. **PAYROLL keeps its month** (`PAY-AW-202608-0001`): there the period is the run's identity, not a counter reset | M-9, §3.6 | 🧑‍💻 | S |

### P1 — real operator pain in the first weeks

| # | Work | Refs | Owner | Size |
|---|---|---|---|---|
| ~~**EG-38**~~ ✅ | **DONE 2026-08-21.** Module 11 now resolves its SLA on the same clock module 26 does. `SlaResolver` gained the canonical `CLOCK_CALENDAR`/`CLOCK_WORKING` constants both modules reference (module 11 does not reach into module 26 for them); `tenant_requests.sla_clock` freezes the promise at intake; all **three** intake roads write it — portal and `/api/v1` through `TenantRequestService::create()`, plus the admin `CreateTenantRequest` page. Two things the row as written did not ask for and the work needed anyway: `TenantRequest::hoursOverSla()`, because the breach bell was quoting a **calendar** overrun for a request promised on the working clock (67 hours for a 3-hour failure), and a test pinning that a crafted payload cannot choose its own clock | C-1 | 🧑‍💻 | M |
| ~~**EG-11**~~ ✅ | **DONE 2026-08-21.** Closed X-5, X-6 and X-8 together. **A rail names its ledger account DIRECTLY, not a `PostingRoles` key** — a role exists so a code path can ask for "the bank account" without knowing the chart, and a rail is operator data pointing at operator data. Decisive against roles: `Health::accountingReadiness()` requires every role to be mapped, so a clearing role per rail would have turned a BLOCKING health row red on every existing install, and two rails could never have had two different clearing accounts. **Six journalizers, not the four the row named** — `Expense` and `Disbursement` read columns this widens and carried the mirror ternary, which sends `bank_transfer` to CASH once the set grows | X-5, X-6, X-8 | 🧑‍💻 | M |
| ~~**EG-12**~~ ✅ | **DONE 2026-08-22.** Six documents, not thirteen, and the boundary is stated rather than implied: these are the ones a bank statement line can explain (AR receipt · supplier payment · expense · deposit movement · owner disbursement · payroll). The other seven money sources are petty-cash and internal flows that keep resolving through the RAIL — correct and unchanged — and because all thirteen now call one resolver, giving one of them a bank account later is a column and a form field rather than a change to how posting works. **Null is the normal state**: until an operator says which account, the rail answers exactly as before and no balance moves | X-7 | 🧑‍💻 | M |
| ~~**EG-13**~~ ✅ | **DONE 2026-08-21.** Built on EG-11's pattern with its review's lessons applied up front rather than after: all four journalizers and all eight surfaces converted in one pass, the seeder wired into all three entry points, and the surface gate GENERALISED to both catalogues rather than duplicated. Two things the row did not name and the work needed: `CostNature::categoriesOf()` — the REVERSE direction — still read only the const, so a category an operator marked `fixed` would answer `fixed` one way and be absent the other, and a CAM pool filtered by nature would omit a cost that was itself classified correctly. And the three category columns had no value set at all | D-1 | 🧑‍💻 | M |
| ~~**EG-14**~~ ✅ | **DONE 2026-08-21.** Deliberately narrower than the row as written: subcategories and per-type SLA became rows, the TYPE did not. Four things the ticket did not name — a nullable column inside a UNIQUE silently stops enforcing it (SQL treats NULLs as distinct, so two conflicting `urgent` policies both saved and the existing uniqueness test went green because its expected exception stopped being thrown); MySQL refused the migration three ways sqlite would have accepted; `request_type` is cast to the enum so `(string)` on it is a TypeError; and **the helper-uniqueness gate had been blind since it was written** — `T_CURLY_OPEN` vs a plain `}` drove its depth counter negative on any file with string interpolation, so `tests/Pest.php`, the one file CLAUDE.md says to put shared helpers in, was the one it could not see | D-3 | 🧑‍💻 | M |
| ~~**EG-15**~~ ✅ | **DONE 2026-08-23 — documents AND messages.** `document_templates` + `App\Support\DocumentText::for()` + a screen, resolving *this property's row → the house row → the translation key the document always used*. **Twelve blocks:** the invoice PDF's footer, payment instructions and terms (slice 1); the invoice EMAIL's covering note; and a body **and subject** each for the overdue reminder, the late-fee notice, the payment receipt and the lease-expiry notice. The floor is what makes it deployable — an install that has written nothing renders and sends exactly what it did before, and the operator adopts one block at a time. **Plain text throughout, and the deviation argued in slice 1 now applies to mail too:** a `RichEditor` here buys bold text and costs operator-authored HTML in an email, so the body is `nl2br(e())` and the subject is flattened by `forSubject()` so a typed newline cannot reach a mail header. **`TenantFacingWordingIsTheOperatorsConformanceTest` is what keeps it true** — it discovers notifications from disk, follows `->markdown()`/`->view()` into the blade, and requires each tenant-facing mail notice to be templated or exempt with a stated reason (nine are, each saying why); it also asserts every key has a floor and a bilingual picker label, which is how five blocks that were registered but unnameable in the dropdown were caught. **Not built, deliberately:** a mail SMTP/sender tab — that is deployment configuration, not wording, and putting credentials in the settings table is a different decision from letting an operator write their own sentences | S-6 | 🧑‍💻 | L |
| ~~**EG-16**~~ ✅ | **DONE 2026-08-21.** One seam, one partial, one gate — twelve templates, none of which can be missed next time | S-8 | 🧑‍💻 | S |
| ~~**EG-17**~~ ✅ | **DONE 2026-08-21.** Driven through Laravel's own renderer in both locales rather than asserted against the blade source — a `dir` attribute a later layer overrides is not a fixed email. The theme half had to be a SOURCE assertion, and the reason is the finding: the CSS inliner normalises `text-align: left` away entirely, so with the bug restored the rendered `<p>` carries no alignment at all and the rendered HTML cannot tell the two apart | S-10 | 🧑‍💻 | S |
| ~~**EG-18**~~ ✅ | **DONE 2026-08-21.** Two keys, and only one of them was the one-liner the finding promised. Also strengthened the wiring gate that was supposed to prevent exactly this: it searched three paths for the bare key name, so the PORTFOLIO read satisfied it and it certified wiring it never checked | M-5, S-14 | 🧑‍💻 | S |
| ~~**EG-19**~~ ✅ | **DONE 2026-08-20.** The three keys are gone, replaced by a pointer comment. Two things the ticket had wrong and the work corrected: the **env vars are not dead** — `database/settings/2026_05_25_200000_create_billing_settings.php` still seeds the initial row from them on a fresh install, so they are now documented as such in `.env.example`; and four test setups (`LateFeeServiceTest` ×3, `AdversarialSweepFindingsTest` ×1) were writing those keys as if configuring the service, a false pass now replaced with the input the service actually reads. Pinned by a test asserting on the shipped FILE with a live-key control | M-3 | 🧑‍💻 | S |
| ~~**EG-20**~~ ✅ | **DONE 2026-08-20.** Dropped (`2026_08_20_700000_drop_the_lease_billing_day_nobody_ever_read`), with the model, factory and 27 QA-harness fixtures cleaned. Honouring it was rejected on evidence, not taste: the monthly run is one scheduled sweep, so a per-lease day means per-day cohorts and a reworked idempotency stamp — and the question worth answering first is per-**property** (EG-18). No "the column is gone" test, following the project's own precedent for `security_deposit_received` | M-4 | 🧑‍💻 | S |
| ~~**EG-21**~~ ✅ | **DONE 2026-08-22.** Quarterly, on the fiscal year's own start rather than the calendar (an April→March mall year is ordinary here), with the months and the whole year still offered beside the quarters because an accountant reconciles a month at a time. Filed per REGISTRATION, not per mall — same decision as the VAT return. The base excludes VAT and the per-supplier rate is **what was withheld over what it was withheld from**, not a rate recomputed from today's catalogue: several payments in one quarter can carry different rates, so a single agreed figure would be a guess. The certificate exists because withholding is an ADVANCE PAYMENT of the supplier's income tax — they cannot claim it without a document from the party that deducted it | T-6, §3.3 | 🧑‍💻 | M |
| ~~**EG-22**~~ ✅ | **DONE 2026-08-22.** Name, logo, favicon and `primary_color`, derived from the tenant's own active leases **and** handed-over unit ownerships — a unit owner is a `tenants` row too and pays their service charge through this same portal. Answers null for a chain, deliberately: half-branding is worse than none. `PortalBranding` memoises per REQUEST (the panel asks four times a page) and not in a static, because a queue worker outlives the request | S-11 | 🧑‍💻 | S |
| ~~**EG-23**~~ ✅ DONE | **D-2 + D-6 (2026-08-21), D-4 + D-5 (2026-08-22).** Six catalogues now share `App\Models\Concerns\IsCodeCatalogue`, extracted in part 2 rather than written a fifth and sixth time — and the extraction immediately found a live bug the four hand-written copies had hidden: `TenantRequestSubcategory`'s flush dropped `…map.labels` while filling `…map.labels.en`, so an operator renaming a subcategory saw the old word for the rest of the request, and for the rest of the day on a `queue:work` daemon. Part 2 also closed D-6's other half: `fd1ea2d1` removed `canCreate()`'s hard `false` and announced a department could be added, while registering no create route, page or button — the gate opened and the door was never built | D-2, D-4, D-5, D-6 | 🧑‍💻 | M |
| ~~**EG-24**~~ ✅ | **DONE 2026-08-21.** One seam over 39 scheduled events rather than 33 edits. Proven by driving it: with `facility` off the PM generator's `filtersPass()` is false while the core ledger sweep's is true — a guard that skipped everything would satisfy the first assertion alone | X-12 | 🧑‍💻 | S |
| **EG-25** | **A WhatsApp/SMS channel + notification routing table.** With push off, tenant reach is bell + email only, and Egyptian retailers answer WhatsApp | X-10, X-11 | 🧑‍💻 + 🔑 | L |

### P2 — structural, and worth deciding rather than drifting into

| # | Work | Refs | Owner | Size |
|---|---|---|---|---|
| **EG-26** | **Legal entity as a first-class object** — per-entity TRN, issuer, chart and fiscal calendar. Already named as a blocker for the Jawad/Eltizam revenue split | S-1, S-2, T-10 | 🧑‍💻 + 🔑 | XL |
| **EG-27** | 🟡 **HALF DONE 2026-08-22 — the disappearing entries, not the consolidated view.** Every statement scoped with `whereIn('je.asset_id', …)`, which never matches NULL, so a property-less entry was invisible in all five and nothing said so — while the year-end close already bucketed those rows *"so no P&L is ever stranded"*. **Surfaced, not folded in**, on the operator's call: a null `asset_id` is portfolio overhead visible from every mall, so absorbing it would show one operator-wide cost in full on each of them and no mall's figures would be right. `LedgerReportService::unallocated()` + a notice on `ScopesLedgerReport` (so a sixth statement inherits it), silent on clean books and on an unscoped read, sized by debits because an entry balances. **Consolidated stays unreachable** — that half reopens the "All-Properties mode removed" decision and is not something to drift into | S-3 | 🧑‍💻 | M |
| **EG-28** | ✅ **DONE 2026-08-22 — both halves.** Statement LAYOUT now reads the chart's own `parent_id` rollup: `App\Support\StatementGroups` resolves each row to its highest ancestor below the root and the screen, the CSV and the PDF all subtotal through it, so a balance sheet reads current vs non-current and an income statement separates operating revenue from other income and sales returns. On the demo books that is 10,055,007 of operating revenue distinguished from 12,440 of other income — a figure the page did not previously carry. **THE DANGEROUS HALF DONE 2026-08-22.** The cash-flow statement no longer classifies by **literal code prefixes** — `ledger_accounts.cash_flow_section` is the account's own answer, resolved through `App\Support\CashFlowSection`, with the shipped chart backfilled from exactly the rules the report used so no existing figure moves. That was the silent one: a chart numbered 1–5 with different sub-ranges SAVES (the guard only checks the leading digit) and then misclassifies every flow with nothing on screen to say so — and the operator's real chart is still pending. **The chart IMPORTER shipped the same day** — `LedgerAccountImporter`, keyed on `code` like the seeder, with `cash_flow_section` as a column because a chart arriving from another system is exactly when the classification must be stated. It also closed a latent ordering bug: parent links are derived by looking BACKWARD for an existing parent, which is complete only when parents precede children — true of the seeder, false of a CSV — so `LedgerAccount::adoptOrphanedDescendants()` now closes the reverse direction on `saved`. **Nothing open.** | S-4 | 🧑‍💻 | L |
| **EG-29** | **Configurable proration method** (30/360 · actual/actual · actual/365 · whole month), per property or per charge code | M-1 | 🧑‍💻 + 🔑 | M |
| ~~**EG-30**~~ ✅ | **DONE 2026-08-22 — both halves.** **M-6:** `leases.escalation_interval_months`, nullable, null = twelve, read through `Lease::escalationIntervalMonths()`. Two things the tests caught: Carbon's `addMonths()` OVERFLOWS a month-end date (31 Aug + 18 months → 2 March, not the last day of February), so the roll is `addMonthsNoOverflow()`; and a 0 from an importer would roll the date nowhere and make the sweep reconsider that lease daily for ever, so the accessor floors at one month. **M-2:** `charges.billing_timing` — per CHARGE, not per lease, because the case that matters is MIXED (rent ahead, service charge behind, one lease). Both ride the SAME invoice, each arrears line naming the month it covers. **A second invoice per lease per month was rejected on evidence:** `alreadyBilledForMonth()` has silently suppressed a lease's base rent FIVE times over a second invoice dated into a billed month, and every one was a ONE-OFF — a recurring one would fire monthly for every arrears lease. Stated cost of that choice: the invoice header's period no longer bounds every line, which it already did not (late fees, utility recharges and violation fines all ride on invoices covering another window). An arrears row prorates against the month it COVERS, and produces nothing on a lease's first invoice because that month predates the lease. Ships with every charge in advance — null is the normal state and no figure moves | M-2, M-6 | 🧑‍💻 | M  **Reviewed adversarially after shipping, and the review found nine defects in it** — seven follow-up commits. Worth recording because the pattern is the lesson: the feature was UNREACHABLE through its only UI (the add-charge action built an explicit attribute list and the column was not on it), it DOUBLE-BILLED its own headline example (an arrears `utility` line put a standalone type on the recurring invoice, so `alreadyBilledForMonth()` ignored the invoice just raised), it REFUNDED a month already earned on termination, LOST the final month, lost up to NINE months on a truncated annual cycle (108,000 EGP on a 12,000/month charge), handed back a rent-free abatement a month later, reverted to advance on every successor row / renewal / resale, and skipped the صيانة run entirely. Every one silent — plausible figures, no error, nothing in a run summary. **Known limitation, deliberately not built:** a lease TERMINATED mid-period loses the arrears tail for its final month. `LeaseTerminationService` writes `expiry_date = terminationDate` so `$isFinalCycle` is satisfied, but the lease then goes `status = 'terminated'` and `scopeBillableForPeriod()` only selects `active` — so unless the final invoice is raised in the same period, that month's arrears is never billed. Fixing it is a decision about whether termination should raise a final arrears settlement, which is its own change |
| **EG-31** | **USD-indexed / EGP-denominated rent** — the index on the escalation path, no GL change. **Do this instead of full multi-currency unless the client insists otherwise** | X-4, §3.5 | 🧑‍💻 + 🔑 | M |
| **EG-32** | ✅ **DONE 2026-08-23 — four slices.** **S-5's user-defined columns are real and durable**: every table is toggleable AND reorderable panel-wide, a saved list view stores the toggles and the ORDER, and the 23 catalogued reports remember their layout too (`SavedColumnLayout`, one implementation for both). **D-7 is complete end to end** — define, fill, read, filter, sort, export, import and search. **What this is NOT is a drag-and-drop report DESIGNER**, and that is stated rather than implied: which columns a given report offers as optional is a per-report editorial call (RentRoll offers 3; AR ageing, the GL, the expiration schedule and the income statement offer none), and a generic query builder over financial statements, an occupancy map and a workflow diagram is not a sensible thing to build. **SLICES 1–3. Slice 3 made the answers USABLE** — list column, filter and sort per field on all five lists, and export columns on the three resources that have an exporter. A value you can type and never group by is the notes box with extra steps. **SLICE 2 — custom fields (D-7) are built**, the larger half. **SLICE 1 DONE 2026-08-23 — a saved view remembers its columns.** The cheapest real part of S-5, and the part the finding got wrong: columns were already user-selectable, just not durable or shareable. **Still open and still XL:** a report BUILDER for the 23 catalogued report pages, user-defined groupings, column reordering (needs a blank-label sweep — two table columns use `->label('')` and `reorderableColumns()` throws on them), **Nothing open beyond one editorial choice**: widening which report and list columns are offered as optional. The vendor and property exporters — the last concrete gap — shipped the same day | S-5, D-7 | 🧑‍💻 | XL |
| **EG-33** | **Real-estate tax and municipal levies as a recurring statutory cost** — there is no recurring-expense concept at all today | T-8, §3.6 | 🧑‍💻 + 🔑 | M |
| **EG-34** | **Configurable retention policy** (activity log is pruned at 365 days from a hardcoded config value), per PDPL's documented-retention obligation | S-16, §3.6 | 🧑‍💻 + 🔑 | S |
| ~~**EG-35**~~ ✅ | **CLOSED 2026-08-22 — its own scope is delivered; what is left was mis-scoped into it.** **Shipped:** the late-fee CAP on the three tiers its siblings already had (`leases.late_fee_maximum` → `PropertySettings` → `BillingSettings`, 0 = no cap at every tier, applied AFTER the minimum so a floor cannot bill above a ceiling the clause names); late-fee RECURRENCE (`late_fee_recurrence_days`, 0 = charge once, measured from the last fee's ISSUE date so an old arrear does not fire a burst of back-dated fees); and the DEPOSIT default, which was a literal `3` in `LeaseCreationService` and is now `BillingSettings::default_security_deposit_months`. All three ship at today's behaviour — no figure moves on deploy. **The two remaining items are not this row's work, and both are recorded at source rather than left pending here:** M-10 (rounding mode) is a standing DECISION — 540 money sites, every stored figure re-derived, for a request nobody has made; and M-12 (quarterly CAM true-up) is a 🔴 L in the CAM module that was mis-scoped into an M — `cam_expense_pools` is `unique(asset_id, period_year)`, so the POOL must gain a shorter period before any schedule can change. M-12 is promoted to **EG-41** so a real L is not lost inside a closed row | M-8, M-11 | 🧑‍💻 | M |
| **EG-41** | **A CAM true-up that is not annual.** Split out of EG-35 (M-12), where it was mis-scoped as part of an M. `cam_expense_pools` is `unique(asset_id, period_year)` — one pool per property per YEAR — so a quarterly or half-yearly true-up is not a scheduling option at all: the pool itself has to gain a period shorter than a year, and the apportionment, the reconciliation and every read that assumes one-pool-per-year follow it. Sized honestly as an L across the CAM module rather than a setting. Worth doing only if the operator's leases actually state a non-annual reconciliation — which is a question for §6, not an assumption | M-12 | 🧑‍💻 + 🔑 | L |
| ~~**EG-36**~~ ✅ | **DONE 2026-08-22.** `journal_entries.description_key` + `description_data`, resolved by `App\Support\JournalNarrative` — the ledger's twin of `ActivityVocabulary`, and the same rule: **a row stores DATA, never PROSE**. All **24** journalizers converted (25 narratives: the custody one branches, so its key is chosen in the same branch its prose is, and the two can never describe different movements). **The prose columns STAY and are still written**, as a snapshot and a floor: every row posted before today has prose and no key, a manual entry is prose the operator typed, and a read site nobody converted degrades to today's wording rather than to a blank cell — on a general ledger an empty description is indistinguishable from an entry nobody described. Nothing re-posts, because `matches()` compares lines, date and asset and deliberately not text | S-12 | 🧑‍💻 | M |
| ~~**EG-37**~~ ✅ | **DONE 2026-08-22.** The count was **74**, not ~25 — and separating them was the value: **48 registered** (deriving from the model's own constant wherever it states one, so the registry is not 48 copies), **24 exempted** each naming the registry that owns it (`MorphMap` for a morph alias, the CHARGE CODE catalogue for `charges.type` and `invoice_items.type`, free text for three). Enforcement immediately found four unreachable fixture values, a **demo-data** defect (a renewal option whose 8% uplift the app could never apply), and a `facility_work_orders.status` of `completed` where the model says `done` — the very status D-8 named. Five of my own first sets were wrong because I read them off sampled DATA rather than the code | D-8 | 🧑‍💻 | S |
| ~~**EG-39**~~ ✅ | **DONE 2026-08-22 — the operator chose to RE-RATE.** A renewal is a re-negotiation, so the deal wins and the rate follows it: `LeaseRenewalService` derives the new `base_rent_rate_per_sqm_year` from the agreed rent (rent × 12 ÷ area, off the ORIGINAL's area because the renewal has no units until `syncUnits()`), and both columns stay true — which keeps the rate meaningful for the escalations that run off it. **Origination is unchanged**: on a NEW lease the rate the deal was struck at still outranks a typed figure, and a test pins that. Fixed in the SERVICE, not in `Lease::saving()`: the model's CREATE rule is general and a disabled form field still posts a value, so flipping precedence there would have changed every rate-priced creation on ambiguous evidence. The agreed figure is also kept EXACT — on an awkward area the 2dp rate cannot round back to it (97,531.11 became 97,531.19), and the operator must see the number they negotiated. Both halves mutation-proved | new | 🧑‍💻 + 🔑 | S |
| ~~**EG-40**~~ ✅ | **DONE 2026-08-22 — and it needed no decision, which is the correction.** Recorded an hour earlier as 🔑 on the question *"should the rate follow a temporary premium"*. Re-read, that is the wrong question and its answer is plainly no: `base_rent_rate_per_sqm_year` is the CONTRACTUAL rate and `holdover_rate_pct` is the premium recorded on top of it, which is exactly the right split — re-rating on conversion would bake a penalty into the contracted rate and lose what the parties agreed. The real question is whether the DERIVATION honours the premium, and it must. `deriveBaseRentFromRate()` now applies it from `holdover_from` onward, the same way `ConvertLeaseToHoldoverService` applies it (premium on the contracted figure, each step rounded), so the two cannot produce different numbers. Before it, a rate-priced lease taking an extra unit mid-holdover silently re-derived to 100% of contracted — 120,000 where 180,000 was owed — and nothing on screen said the negotiated uplift had gone | new | 🧑‍💻 | S |

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

> **Re-verified 2026-08-22, and most of this section was wrong.** It was written on 2026-08-20 and
> never re-checked, so by the time anyone worked it two rows had already been fixed elsewhere, one
> was a false finding, and one named a remedy that would have made the system worse. That is the
> ordinary half-life of a findings list nobody re-runs — and the reason the rows below now carry a
> **verdict** rather than only a claim. **Verify before fixing**: an absence finding is usually
> false, and a finding that is real is not evidence that its proposed fix is.

**Documentation that has drifted from the code:**

| Doc | Says | Verdict |
|---|---|---|
| ~~`docs/OPEN-QUESTIONS.md` A1.1~~ | ~~The VAT rate is `TaxSettings::vat_standard_rate`~~ | ❌ **STALE FINDING.** A1.1 already reads *"the rate is now MASTER DATA … `TaxSettings::vat_standard_rate` was removed 2026-08-12"*. Corrected before this list was worked |
| ~~`docs/OPEN-QUESTIONS.md` A3.2~~ | ~~"no straight-line spread"~~ | ❌ **STALE FINDING.** A3.2 already reads *"Straight-line rent IS built … ships off behind `BillingSettings::straight_line_rent_enabled`"* |
| ~~`docs/accounting/EGYPTIAN-TAX-CATALOG.md:10-11`~~ ✅ | `TaxCatalogueConformanceTest` gates the catalogue "in both directions" | ✅ **REAL — and fixed by making the claim true (2026-08-22).** The test asserted every code on the operator's sheet EXISTS and never that nothing else does, so an invented row passed cleanly. A presence-only sweep reporting on a property it never checked — the same shape as the reconciliation tie-out that could not fail. The gate now diffs the seeded catalogue against the sheet both ways |
| ~~`docs/modules/21-general-ledger.md:610`~~ ✅ | Statements are "per-property **& consolidated**" | ✅ **REAL — corrected in place.** Consolidated is unreachable from the panel; the row now says so and points at EG-27 rather than describing a capability the operator cannot open |
| ~~`docs/modules/17-reports.md`~~ ✅ | "Fourteen of twenty reports are deliverable"; "the six that are not … a searchable log" | ✅ **REAL, found while fixing the audit-log export.** Both counts were hand-typed and both had drifted, and the "searchable log" among the undeliverable is the very page this round wired an export onto. Replaced with the registry, per the project's own rule against typing a count into a doc |
| ~~`lang/en/admin/help.php:137`~~ ✅ | ~~Escalation type "Step = pre-agreed increases per year"~~ | **Fixed 2026-08-20 (EG-09)** in both languages |

**Live defects:**

| # | Finding | Verdict |
|---|---|---|
| 1 | `ActivityLog` implements `DeliverableReport` and defines `reportCsv()` but never spreads `exportActions()` — emailable on a schedule, not exportable from the screen | ✅ **REAL, fixed.** It was the only `DeliverableReport` page in that state. Wired with `ExportsReport` — but **not** on the trait's default gate: `mayExport()` defaults to `reports.view`, and this page is gated on `activity_log.view`, which the seeder withholds from `mall_admin` *because* the feed spans every property and cannot be scoped to one. Inheriting the default would have made the export a second door into exactly the data the screen's own gate withholds. Overridden to `canAccess()` |
| 2 | No `ExportAction` carries `->authorize()`, so bulk egress inherits only `viewAny` while every `ImportAction` is double-gated | ⚠️ **REAL BUT MIS-STATED — and it was hiding something worse.** Three corrections: one of the seven DOES gate (tenant requests, on `requests.view_all`); the six `ExportBulkAction`s were missed entirely, so it is **thirteen** actions; and it is **not a data-egress hole** — Filament exports `getTableQueryForExport()`, the resource's own scoped query with the operator's filters applied, so an export can never return a row the list would not. See ⑥ for what it really was |
| 3 | `LeaseOption::TYPES` omits `'extension'`, which `ExerciseLeaseOptionService` handles and queries for — dead code reachable only by a direct write | ⚠️ **REAL, REMEDY INVERTED — fixed the other way.** The drift is genuine, but adding `'extension'` to `TYPES` would have been the wrong repair: an OPTION is an unexercised RIGHT and `renewal` already IS the right to extend, so a second code for one thing would split option reporting across both — and there is no `admin.lease_options.types.extension` key, so the picker would have rendered a raw translation key. Adjacent-lang-group confusion: `extension` is a **lease EVENT** (`LeaseEvent::TYPE_EXTENSION`, what HAPPENED), and `admin.leasing.lease_events.types` sits directly above `admin.leasing.lease_options.types`. The dead branches were **removed**; no test referenced the value |
| 4 | `PushChannel` returns early for a notifiable with no `deviceTokens()`, and only `Tenant` has that relation — three notifications declaring `'push'` to admin `User`s are silent no-ops | ❌ **FALSE FINDING.** It is **two**, not three (`MarketingPostReviewed` and `SalesDeclarationLocked` are sent via `$tenant->notifyPortal()`, and `Tenant` has device tokens), and both remaining ones say so in their own docblocks — *"reaches the mobile app the moment a supervisor is a push-capable notifiable"*. A deliberate forward declaration, not a defect. No change |
| 5 | Renumbering the chart desynchronises the seeders silently: `ChartOfAccountsSeeder` matches on `code`, and `AccountMappingSeeder` `continue`s past a miss with no warning | ⚠️ **REAL, HALF FIXED — deliberately.** The silent half is closed: the miss now logs the role and the code it could not find, so `atriom:install` on a customised chart reports what it failed to wire instead of surfacing months later as a red `atriom:health` row naming neither. The `updateOrCreate(['code' => …])` half is **not** fixed here — code is the only stable key the seeder has, and giving the chart a durable identity is EG-28's chart importer, not a patch to a seeder |
| 6 | *(not in the original list)* | 🔴 **NEW, and the real content of ②.** Six of the seven Tables classes are **shared with the PORTAL** — one `InvoicesTable::configure()` serves `Filament\Admin\Resources\Invoices` and `Filament\Portal\Resources\Invoices` alike — so a tenant saw an Export button on their invoices, payments, leases and credit notes. Clicking it cannot work: Filament resolves the exporting user from `Filament::getAuthGuard()`, which on the portal is `portal` and yields a `TenantUser`, then writes its id into `exports.user_id` — **a foreign key to `users`**. The click either violates the constraint or, where an admin happens to hold that id, files a tenant's export under a stranger's name. `App\Support\Exports::allowed()` now floors all thirteen on `instanceof User` **and** the resource's own `canViewAny()` |
| 7 | `ActivityVocabulary.php:136` still maps `facility_work_order.category`, a column dropped by `2026_08_20_100000` | ✅ **REAL, fixed — and worse than reported.** The entry pointed at `admin.enums.work_category` while the keys live under `admin.statuses.work_category`, so it had resolved nothing even while the column existed. Removed. The orphaned lang group is **left in place**: `TranslationCoverageTest` still asserts it, and unpicking the trade register's leftovers is its own change rather than a half-done one here |


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

### 2026-08-22 — milestone 12: §7 re-verified, and the export button nobody had pressed

Not an EG row. **§7 had never been re-run since it was written**, and working it as written would
have shipped two no-op changes, one wrong fix and one correct one — so every row now carries a
verdict, and the section documents its own half-life rather than reading as a fresh worklist.

Of the eleven rows: **two were already fixed** elsewhere (both `OPEN-QUESTIONS.md` entries), **one
was false** (`PushChannel` — two notifications, not three, and both docblocks state the no-op is a
deliberate forward declaration), **one named the wrong remedy** (`LeaseOption::TYPES`), and the
rest were real. **Verify before fixing, and separately verify the fix**: a finding being real is
not evidence that its proposed repair is.

**What the export row was actually about.** It read as an authorization gap — *"no `ExportAction`
carries `->authorize()`"* — and on the letter of it that is not a hole at all: an export runs
`getTableQueryForExport()`, the resource's own scoped query with the operator's filters applied, so
it can never return a row the list would not. Three things the row had wrong: one of the seven DOES
gate, the six `ExportBulkAction`s were missed so it is **thirteen** actions, and the reason it
mattered was somewhere else entirely.

**Six of those seven Tables classes are shared with the PORTAL.** One `InvoicesTable::configure()`
serves the admin panel and the tenant portal, so a tenant had an Export button on their invoices,
payments, leases and credit notes — and clicking it cannot work. Filament resolves the exporting
user from `Filament::getAuthGuard()`, `portal` on that panel, which yields a `TenantUser`; it then
writes that id into `exports.user_id`, **a foreign key to `users`**. Either a constraint violation,
or — where an admin happens to hold the same id — a tenant's export filed under a stranger's name.

`App\Support\Exports::allowed()` is the counterpart to `Imports`, and the shape of the gate is the
argument. Export is deliberately the **wide** door: the FRD says *"all other roles may
export/download but not import"*, so it is not a permission of its own but the resource's own
`canViewAny()` — whoever may read the list may take it away, the same rule `ExportsReport` states
for the report pages. What it adds is a floor of `instanceof User`, **not** `?->can()`: `TenantUser`
does not use spatie's `HasRoles`, so `can()` answers false today for the wrong reason and would
answer TRUE the day the portal grows a policy. A portal export is not refused so much as **not
offered** — if tenants should be able to take their own ledger away, that is a portal feature with
its own exporter and its own foreign key, not an admin button leaking onto their screen.

**Also fixed:** the audit log's missing export (the one `DeliverableReport` page without one — and
gated on `activity_log.view` rather than the trait's default `reports.view`, since the seeder
withholds the former from `mall_admin` precisely because the feed spans every property); the tax
catalogue gate's missing direction, which had been claimed in writing since the day it was written;
`ExerciseLeaseOptionService`'s unreachable `extension` branches; and `AccountMappingSeeder`'s silent
skip past a chart account it cannot find.

**Two gates, mutation-proved.** `PortalNeverGetsAnAdminExportButtonTest` pairs every refusal with a
control that must succeed — a gate refusing everyone satisfies the refusals alone and reads as a
pass — and sweeps `app/Filament` for an ungated export chain, asserting it **found** something
before reporting on it. Deleting one `->visible()` from `UnitsTable` turns it red naming that file.
The control needed `RolesPermissionsSeeder`, not `seedRoles()`: role rows with no grants make a
manager fail a permission gate for a reason that has nothing to do with the gate.

**Worked in parallel with the EG-37 session on one shared tree** — disjoint lanes (§7 vs the EG
worklist), explicit pathspecs on the commit, no `git add -A`.

---

### 2026-08-22 — milestone 13: EG-01, the exemption a service wrote and nobody could lift

The P0 at the top of the list, and the second time in two days that a finding was real while its
description was one layer off the actual damage.

**What the row said:** `vat_applicable` and `vat_rate` are frozen onto recurring charge rows;
make them null-by-default. **What was true:** `vat_rate` had already been half-fixed on 2026-08-12
— applied to `seedStandardCharges()`'s service-charge block and not to the base-rent block **two
lines above it**, which is the comparison the row itself draws — and `vat_applicable` had never
been touched at all.

**The untouched half is the dangerous one.** `Charge::resolvedVatRate()` tests `vat_applicable`
FIRST and returns 0.0 before the catalogue is ever consulted. So a `base_rent` row is born `false`
— rent is in `Vat::EXEMPT_TYPES` — and is permanently exempt, whatever anyone later rules. Measured
before writing the fix, with the charge code pointed at `VAT_14`:

- `Vat::rateForType('base_rent')` → **14.0** (the ruling took)
- `$rent->resolvedVatRate()` → **0.0** (the row overrode it)
- the billing run raised a rent line with **0.00 VAT** where 14,000 was due
- and a row carrying a deliberately typed **8%** also resolved 0.0 — the short-circuit runs before
  the rate is read, so the freeze defeated the operator's own override as well

This is not a latent risk. **Law 157/2025 pulled property rental into the tax net** (§3.1), so
*"point base rent at VAT_14"* is precisely the change this operator is expecting to make. It would
have appeared to work — the screen saves, the resolver answers 14 — while every lease already on
the books went on billing rent untaxed and the operator accrued output VAT owed to ETA that they
had never collected.

**Why every row backfills to null.** No screen has ever offered `vat_applicable` as a tick: all
three UI/import write sites DERIVE it, from the catalogue or from a rate the operator typed. So the
column held no operator statement to preserve — it held the catalogue's answer copied onto a row,
which is the freeze itself. Nothing is lost either, because the real channel survives: a
deliberately untaxed charge is one with `vat_rate = 0`, which the resolver still honours ahead of
the catalogue. Two columns saying one thing became one, and the one left is the one a form writes.
An explicit `false` keeps its meaning and still wins — it just stopped being written by services
that were quoting the catalogue back to themselves.

**Nothing moves on deploy.** Rent is exempt in the shipped catalogue, so a nulled row resolves 0.0
exactly as the frozen `false` did. What changes is that the day the ruling changes, the bill does.

**Enumerated by grep, not from the diff.** Thirteen `vat_applicable` writes and seven recurring
`vat_rate` freezes across `LeaseCreationService`, `LeaseSpaceChangeService`,
`ConvertLeaseToHoldoverService`, `LeaseRentChangeService`, `MarketingLevyService`,
`AssignRentableItemService`, `ApplyCamEstimateService`, `ChargeScheduleService`,
`PercentageRentCalculationService`, `CamReconciliationService`, both charge-schedule relation
managers and `ChargeImporter`. The one-off sites were converted too rather than left as "harmless
because billed immediately" — a per-site rule is one more thing to get wrong, and the invoice LINE
is what correctly keeps the rate it was billed at.

**Two things the ticket could not have named.** `hasVatRateOverride()` required `vat_applicable` as
well as a rate, so against a nullable column it would have reported every genuine override as "no
override" and the schedule's ⚠ marker would have stopped appearing on exactly the rows it exists to
flag. And `TransferUnitOwnershipService` copied the flag through a **`(bool)` cast** — which turns
null into `false` — so a resale would have re-frozen "ask the catalogue" into "permanently exempt",
reintroducing the whole bug one unit at a time.

**EG-02 is still open and is not blocked by this.** Taxability now resolves from the charge code at
billing time for every lease alike; giving it a per-lease or per-unit dimension — *"admin units
taxed, retail exempt, from date D"* — is the separate question, and it waits on the accountant.

---

### 2026-08-22 — milestone 14: EG-03 part 1, payroll numbers get a date and a ceiling

Two of §4.2's three 🔴 rows, and the third is deliberately left because it is a question for the
operator before it is work.

**P-3, undated.** `GeneratePayrollService` read three flat settings with **no date argument**, so a
January run generated in March computed on March's numbers, a rise could not be entered in advance,
and nothing recorded what a past run had used — against a state that raises the insurable-wage band
every January. The correct shape was already in the codebase (`TaxCode::rateOn($code, $on)`), and
`App\Support\PayrollRates::for($periodMonth)` is that shape for payroll. **Pass the month the money
is FOR**, not the day someone pressed Generate.

**P-1, uncapped.** Social insurance is charged on the **insurable wage** — the gross clamped into a
floor/ceiling band — and the service applied the rate to `base_salary` outright. Every employee
above the ceiling was over-deducted and the employer over-accrued, under a comment reading
*"Employer SI is a company cost — it does NOT reduce net, so no cap needed"*. That misreads the
rule: the cap is on the WAGE, so it binds both shares. Measured on the fix — a 50,000 salary against
the 16,700 ceiling deducts 1,837 rather than 5,500, and the employer accrues 3,131.25 rather than
9,375. Salary tax stays on the whole gross; getting those two bases the right way round is the
substance.

**A row is a SET, not a key/value pair.** Egypt publishes these together — one decree sets the band
and the rates, effective 1 January — so the accountant enters one row a year, which is also how they
receive it. It avoids inventing a classification column that would need a `ValueSets` entry to stop
`insurable_wage_ceiling` being saved as `insurable_wage_celing`; a set of columns cannot be mistyped.

**Seed the vocabulary, not the numbers.** The 1 Jan 2026 rung carries the **band** (2,700 / 16,700,
NOSI) because that is a statutory fact with a published date. It does **not** seed the 11% employee
contribution, equally published: the install ships rates at 0, the settings help offers *"leave at 0
and enter it per employee"* as a supported posture, and a migration that started withholding 11%
from every salary would be this software deciding to deduct money from people. Which also means the
band changes nothing on deploy — it only bites through a non-zero rate.

**Null is no bound, not zero.** On the ceiling, zero would insure everybody on nothing. Periods
before 1 Jan 2026 get no band at all, because we know the band was raised **to** 2,700/16,700 on
that date and this does not claim to know what preceded it — which is also exactly today's
behaviour, so no historical run changes.

**The settings are GONE**, not left beside the ladder: settings hold policy, master data holds rates
— the same split that removed `TaxSettings::vat_standard_rate`. `gratuity_*` stays, because whether
this workforce is entitled to a gratuity is a question about their contracts, not a figure the state
publishes each January. EG-04's health row now reads the rung in force today, which is what the
ticket meant by *"fix EG-04 on top of it, not beside it"*.

**Gated apart from `payrolls.*`.** Running a payroll and deciding what the state's rates ARE are not
one authority: `payroll_rates.*` goes to accounting (write) and HR (read), so an HR clerk who may
generate a run cannot move the ceiling underneath it.

**What is NOT built: P-2, the bracket engine.** Seven bands and a personal exemption, and §6.4 asks
the operator a prior question — whether they want this system to compute statutory payroll at all,
or to keep keying it per run. Brackets are rungs with more columns, so the ladder is the thing they
hang off when that is answered.

**Three bugs found in my own work, worth recording because two are traps this codebase has
documented before.** A wrong FQCN for spatie's `LogsActivity` made the whole suite exit **1 with
zero bytes on both streams** — the signature CLAUDE.md attributes to a helper redeclaration, and the
diagnosis (`php -r` booting the app) is the same either way. `$defaults + $attrs` in a test helper
silently discarded every argument, because PHP's `+` keeps the LEFT operand's key — so the first cut
asserted a 14,500 ceiling against a rung that had none. And the migration's own seeded 2026 rung
**supersedes** a test rung dated 2000, so three existing tests had to state their whole ladder
rather than add to it.

---

### 2026-08-22 — milestone 15: EG-15 slice 1, the invoice starts saying what the operator says

S-6 called this *"the single largest 'the operator cannot run their own business' gap"*, and the
part of it that reaches a tenant every month is the invoice.

**Two specifics, not a general complaint about lang files.** The footer reads *"Payment due within
:days days of issue · **Bank transfer / Card / InstaPay**"* — three payment rails hardcoded on the
one document every tenant reads monthly, while EG-11 turned rails into a catalogue the operator
adds to and retires, so the sentence can be wrong the moment they use the feature. And **no invoice
showed bank details at all**: a tenant holding one had no way to know where to pay, and there was
nowhere to put it.

`document_templates` + `App\Support\DocumentText::for()` + a screen, wired into the invoice PDF as
three blocks — footer, payment instructions, terms.

**The floor is the safety case.** Resolution is *property row → house row → the translation key the
document always used*, so an install with no rows renders exactly what it rendered yesterday and the
operator adopts one block at a time. The two NEW blocks have no floor and render **nothing** until
written — a heading over a gap on a document about money reads as a missing instruction rather than
an absent one.

**Null `asset_id` is the house default, and that decision cost three gate failures to get right.**
The wording is portfolio text first and a mall's override second, which makes the model a HYBRID —
`#[PropertyOwned(portfolioRowsWhenNull: true)]`, the same third case the five money models have. The
gates caught all three consequences: the resource needed a hybrid `getEloquentQuery()` or the house
row is invisible from every screen; it needed `BypassesFilamentTenantAutoScope`, or the panel
stamps the operator's blank with the selected mall and the default becomes unwritable through its
own form (the "Announcements tenancy trap"); and the hybrid list is **pinned by a test that calls it
a money decision**, so joining it is an argued edit rather than a silent one.

**A stated deviation: plain text, not a `RichEditor`.** The ticket asks for one and S-6 notes the app
has none. These blocks are set in the document's own typography, so what a rich editor mainly buys
here is operator-authored HTML flowing into mpdf and, later, into email — a real escaping problem
taken on for a bolded line. It belongs with the **remaining half**: dunning wording, the mail tab
and message templates, where wording is the whole artefact. EG-15 is 🟡, not closed.

**Small things that are the actual content.** An unknown token is **printed, not blanked** —
`{amont}` visible on an invoice gets reported, a silently deleted sentence does not. `e()` goes
INSIDE `nl2br`, or nl2br's own `<br>` is escaped. Both languages live on one row and a missing one
falls back to the other, because a blank where the payment terms belong is worse than the wrong
language. And the test renders the **real blade**, asserting the operator's footer replaced the
built-in sentence rather than being appended under it — a resolver agreeing is a different claim
from the document being right.

**One bug of my own, caught by a gate and worth recording.** The lang insert for this slice anchored
on the `insurable_wage_floor` key shipped an hour earlier in EG-03 and **replaced it** instead of
inserting beside it. `ActivityLogVocabularyConformanceTest` named the exact missing label in both
locales. A registry gate catching a regression in the previous commit's work is the whole argument
for having them.

---

### 2026-08-22 — milestone 16: EG-27, the statements admit what they are leaving out

Half of EG-27, and the half chosen with the operator rather than guessed at.

**The bug.** `aggregate()` and `accountLedger()` both scope with `whereIn('je.asset_id', $ids)`, and
`whereIn` never matches NULL. So a journal entry filed against no property was invisible in the
income statement, balance sheet, cash flow, trial balance and general ledger — all five — and
nothing on the page said so. The year-end close had already solved this for itself:
`plByAssetAndAccount()` buckets null-asset rows under `asset_id => null` precisely *"so no P&L is
ever stranded"*. The close and the reports disagreed, and the reports are the ones somebody signs.

**Why the obvious fix is wrong, and why this needed asking.** The literal reading of the row — make
the filter match NULL — would show one operator-wide insurance bill **in full on every mall at
once**, because a null `asset_id` is portfolio overhead visible from all of them
(`#[PropertyOwned(portfolioRowsWhenNull: true)]`). Three malls, one cost, counted three times.
`AuditPropertyDimensionCommand`'s own docblock supplied the rest of the picture: since
`PropertyField` pinned the pickers **no screen can create such a row any more**, so the remaining
sources are a CSV import and a migration — exactly the moments nobody is watching a report.

So: **surface, do not absorb.** The figures are untouched (pinned by a test), and every statement
renders a notice naming the count, the amount and `atriom:audit-property-dimension`.

**Three details that are the actual content.** Silent on clean books — a warning that shows on a
healthy period is trained away within a week and then missed on the one that matters. Silent on an
**unscoped** read, because there is no `whereIn` there and the entries ARE in those figures. And
**sized by debits**: an entry balances, so summing both sides doubles every figure, and a notice
reading 169,000 against 84,500 of real exposure is worse than none.

It lives on the `ScopesLedgerReport` concern rather than on five pages, so a sixth statement
inherits the warning instead of being the one that quietly omits money; the balance sheet overrides
the window to *everything up to the date*, since a month's worth would understate what an "as at"
statement is missing.

**Two gates caught omissions in the two PREVIOUS commits.** `ViewActionCoverageTest` and
`AdminSmokeManifestConformanceTest` both flagged the payroll-rates screen (EG-03) and the
document-wording screen (EG-15): neither offered a read-only view, and neither was in the E2E smoke
manifest. Both fixed here, and the manifest regenerated rather than hand-edited. Running the full
gate set per item — not just the ones you expect to be relevant — is the lesson.

---

### 2026-08-22 — milestone 17a: EG-35 review pass

Three findings on the committed work, which is the argument for doing this pass at all — the suite
was green and the gates passed before any of them surfaced.

**1. The deposit policy reached the WIZARD only.** `LeaseCreationService` reads the setting, so a
lease created through the wizard honoured it — and a lease created through the ordinary Filament
form was still typed from scratch. *"Three months from Q1"* would have changed one of two create
paths and looked done, which is precisely the shape of a policy that reaches nothing that M-11 was
raised about. The form field now defaults from the same per-property setting.

Pinned by **mounting the real create page**, not by inspecting the schema: a default declared in a
closure that never runs is the failure being guarded against, and only mounting runs it.
Mutation-checked — remove the `->default()` and the assertion reads `null` against `2.0`.

**2 and 3. Stale prose in the two files I had just changed.** `LateFeeService`'s docblock still said
*"The rate, minimum and grace come from the LEASE"* and `lateFeeTerms()`'s still said *"Real leases
do not agree on the rate, the minimum or the grace period"* — both enumerating three terms in a
method I had just given a fourth. A comment that lists a set is a comment that goes stale when the
set grows, and this project's own rule is that the doc changes in the same breath as the code.

**Checked and NOT defects**, which is information too: the per-property overrides screen derives its
fields from `PropertySettings::OVERRIDABLE` and labels them from `admin.settings.fields.*`, so both
new keys appear there with no wiring of their own; and driving it on real data confirmed the cap
resolves through the real chain (`lateFeeTerms()` returns `maximum: 0` on an existing lease) rather
than only through `LateFeeService`'s detached fallback.

---

### 2026-08-22 — milestone 17: EG-35, the cap a clause states and the deposit policy a literal held

The row reads as one M covering four things. It is four separate pieces of work, and saying which
is which turned out to be most of the value.

**Shipped — the late-fee CAP (M-8a).** `late_fee_minimum` existed and its opposite did not, so
*"2% per month, minimum EGP 50, capped at EGP 5,000"* was two thirds expressible. The asymmetry
matters more than it reads: a percentage of an arrears has **no upper bound**, so a tenant six
months behind on a large invoice drew a penalty proportional to the size of the debt rather than to
the breach — the figure a tenant disputes and an operator waives by hand. `leases.late_fee_maximum`
on the same three tiers its siblings already had, 0 = no cap at every tier.

**The cap is applied AFTER the minimum**, deliberately: a ceiling the operator typed is a statement
about the most they will charge, a floor only rounds small ones up, and `max()` last would bill
above a cap the clause names.

**Shipped — the DEPOSIT default (M-11).** Sharper than the row said. Not merely absent: the house
policy was the literal `3` in `LeaseCreationService`'s `$rent * 3`, so *"three months from Q1"* was
a deploy and *"two months at the outlet mall"* was unsayable. Now
`BillingSettings::default_security_deposit_months`, per-property, and the lease still records the
amount actually agreed.

**Two bugs of my own on the way, both classes this codebase has recorded.** I first added
`'maximum'` only to `LateFeeService`'s no-lease fallback — but `invoices.lease_id` is NOT NULL, so
that branch never runs for a real invoice and the cap would have been read as an undefined key on
every fee the sweep charges: present in the code, inert in production. Then `$max` was missing from
the transaction closure's `use` list — the `Undefined variable` shape that 500'd the raise-invoice
button for five days in August. The test that pins the first one sets the cap at the **portfolio**
tier with no lease override, so only the real resolution chain can deliver it.

**Not shipped, and each says why.** Late-fee RECURRENCE (M-8b) turns `invoices.late_fee_invoice_id`
from a `belongsTo` into a one-to-many on a money link — a schema change that deserves its own change
and its own tests, not a rider on a settings commit. The ROUNDING mode (M-10) is 540 money sites and
re-derives every stored figure, and the finding's own premise is conditional: *"an accountant asking
for banker's rounding cannot have it"* — none has asked, and doing it speculatively is how a money
system acquires a change nobody can evaluate. A QUARTERLY CAM true-up (M-12) is not a schedule
change at all: `cam_expense_pools` is `unique(asset_id, period_year)`, one pool per property per
year, so the pool's period has to change before the schedule means anything.

---

### 2026-08-22 — milestone 18a: EG-36 review pass

One finding, and it is the class this pass exists for: **a read site nobody converted.**

`ReportCsvExporter::generalLedger()` read `description_en` / `description_ar` straight out of the
statement array, so the general-ledger **CSV** kept showing the prose frozen at post time while the
**screen** it was exported from showed the resolved narrative. The moment anyone edited a wording
those two would disagree about the same line — two truths about one entry, in the feature whose
whole purpose is to have one. The data was already in the row (`LedgerReportService` selects the key
and its data for the page), so this was a seam left unused rather than a missing capability.

Fixed through the same resolver, and pinned both ways: a line WITH a key exports the resolved
narrative, and a line posted before keys existed still exports its prose.

**Checked and NOT defects.** `ChangeImpactConformanceTest` passes — `JournalEntry` is the entry
rather than a classified GL SOURCE, so its two new fillables need no impact classification. The
`ValueSets` coverage gate does not want `description_key` registered: it has its own registry, and
`JournalNarrative` ignores a key it does not know rather than rendering it, which the test proves
directly.

---

### 2026-08-22 — milestone 18: EG-36, the ledger stops baking its own prose

The last of the 🧑‍💻-only rows that needed no decision from anybody.

Twenty-four journalizers wrote Arabic and English literals into `description_ar` / `description_en`
at post time — *"فاتورة INV-0001"* — which contradicts the rule CLAUDE.md states for the activity
log in as many words: **it stores DATA, never PROSE.** The consequences are exactly the ones that
rule exists to prevent: a wording fix needs a deploy, it never reaches a row already posted, and a
third language means re-posting history. The pattern was already in the codebase twice, so this is
the ledger finally using it.

**The prose columns STAY, and are still written.** They become a snapshot and a floor rather than
the truth, for three reasons and the third decided it: every row posted before today has prose and
no key and must keep reading correctly for ever, because a ledger is evidence; `search_text` folds
the narrative and a stored copy keeps a raw reader honest; and **a read site nobody converted
degrades to today's wording rather than to a blank cell** — on a general ledger an empty description
is indistinguishable from an entry nobody described. `JournalNarrative::resolve()` prefers the key,
so a wording change still reaches every read that goes through it.

**Nothing re-posts.** `LedgerPoster::sync()`'s `matches()` compares lines, date and asset and
deliberately not text (`ChangeImpact` classifies these columns DESCRIPTIVE), so adding a key cannot
void and re-post an entry.

**Three details that are the content.** `__()` reads dots as NESTING, so `invoice.posted` is nested
rather than keyed by the literal string — the first attempt printed
`admin.journal.narratives.invoice.posted` raw, which is the trap already recorded for
`admin.activity.descriptions`. A missing placeholder renders an **em dash**, not `:number`, because
a leftover token on a financial statement reads as a broken template. And `Lang::has()` **falls back
to English**, so the parity check passes `fallback: false` or it only ever catches a key missing
from BOTH languages.

**The custody journalizer chooses between two narratives in a branch**, so its key is chosen in the
same branch its prose is — the key and the sentence beside it can never describe different
movements.

**One bug of my own, and the test caught it.** The `use App\Support\JournalNarrative;` insert into
`GeneralLedger.php` matched no anchor and silently did not land, so PHP resolved the name in the
page's own namespace — `App\Filament\Admin\Pages\JournalNarrative` not found, on the general
ledger, at render time. The exact shape `UnresolvedClassReferenceConformanceTest` exists for, in a
closure that only runs when the table renders. I had even grepped for the import and seen no output,
and read past it.

**Two gates, not one.** The narratives sweep asserts both languages resolve with no leftover
placeholder AND that it found at least 24 keys — a registry that quietly emptied would pass the
loops. The second reads the journalizers from disk and fails on one that writes prose with no key,
because a journalizer left behind would look identical to the converted ones in review.

---

### 2026-08-23 — milestone 27: EG-10, and what "needs a decision" actually means

Asked to walk the 🔑 items one by one, I put EG-10 up as a question with three options. The answer
was not one of them: *"take reference from other systems like yardi or market standards … always
take into consideration the market standards and how other good systems behave with good UX."*

So the 🔑 marker is **my** classification and most of it does not survive that rule. A row is only
genuinely blocked when the answer is a FACT about Eltizam — what their leases state, what their
accountant has ruled, what their tax registrations are. *"Should an invoice series reset monthly,
annually or never"* is not that: it is a market-standard question, and parking it was the mistake.

**What the market does.** SAP, Oracle, NetSuite and Odoo reset accounting document numbers per YEAR
— Odoo's sequences (a prefix with a date range and a counter) are the closest analogue to this
implementation. Yardi Voyager and MRI use continuous control numbers that never reset, with the
property as a field on the record rather than a segment of the number. **Monthly, which Atriom
shipped, is used by none of them.**

So: default **annual**, and the scheme is a SETTING (`never` · `annual` · `monthly`), because every
one of those systems treats a number range as configuration rather than as code.

**An install that has already issued an invoice stays MONTHLY.** Numbers are allocated as
`MAX(number)` within a prefix, so changing the scheme starts a fresh sequence at 1 and leaves the
old documents on the old series — harmless on an empty install, exactly the discontinuity an auditor
would query on a live one. The settings migration reads the books before it decides.

**Calendar year, not fiscal.** SAP resets per fiscal year; deliberately not copied. This system
already lets a property run an April→March year, and a March-2027 invoice numbered `…-2026-…` reads
as a mistake to everyone who is not an accountant. An operator whose year is not the calendar year
should choose `never` — Yardi's behaviour, with no year in the number to disagree with.

**Payroll keeps its month.** `PAY-AW-202608-0001`: a payroll run is per property per month by
definition and there is one of them, so `202608` names the run rather than resetting a counter.
Stated as an exception rather than left as an inconsistency.

Six tests updated that had hardcoded the monthly shape. One of them, `InvoiceTest`, was asserting
"February and March get different sequences" — which was describing the accident rather than the
requirement, so it now asserts what the test is really about: the numbers are unique and climbing
within one series.

### 2026-08-23 — milestone 26: the two registers with no way out

`VendorExporter` and `AssetExporter`, plus their header and bulk actions.

Seven resources exported; vendors and properties imported and never exported — a **one-way door**.
It surfaced while finishing EG-32: a custom field on a vendor could be defined, filled, filtered and
imported, and could not be taken away, because there was no exporter for the columns to attach to.

Both lead with `code` — the identity `VendorImporter` dedups on — so a file exported here can be
re-imported. Custom-field columns come last, so adding a field never moves the positions a
colleague's mapping template depends on.

The gate is the resource's own `canViewAny()` through `App\Support\Exports`, never a permission of
its own: the FRD restricts import and widens export. Read as an authorization question it is not
one — Filament exports `getTableQueryForExport()`, the resource's own scoped query with the
operator's filters applied, so an export can never return a row the list would not.

`Department::$fillable` also lost `metadata` here, closing the last of the five JSON columns that
were mass-assignable for no reader — the same hardening EG-32 applied to the five extensible models.

`EveryRegisterCanBeExportedTest` (4 cases), mutation-checked: removing the custom-field spread from
the vendor exporter fails it.

### 2026-08-23 — milestone 25: EG-32 slice 4 — user-defined columns become real, and EG-32 closes

The last of EG-32: custom-field IMPORT and SEARCH, column REORDERING panel-wide, and saved layouts
for the 23 catalogued reports.

**Import** closes the round trip. `CustomFieldsTable::importColumns()` adds an optional column per
active field to the tenant, lease, unit and vendor importers, filled through `fillRecordUsing()` so
it goes through `fillCustomFields()` — an import gets the same key filtering and casting a form
does, and **a CSV cannot introduce a key the catalogue never defined**.

**Search**: each model spreads `customFieldSearchValues()` into `searchTextSources()`. That honours
the blob's one hard rule — never reach through a relation — because `metadata` is the row's own
attribute. Stored values only, never a choice's LABEL: a label lives on the definition, so indexing
it would be silently stale after a rename, which is the exact failure the rule exists to prevent.
Deploy step: `atriom:rebuild-search`.

**Column reordering is now ON panel-wide**, which is S-5's "user-defined columns" taken literally.
`HasColumnManager` throws on any blank-label column when it is, so it needed a sweep — and the sweep,
done by BUILDING every admin list rather than grepping, found exactly **one**: the marketing card
thumbnail, plus its portal twin. `ReorderableColumnsConformanceTest` keeps it true and asserts its
own premise, since a blank label is only fatal because reordering is on.

**A saved view now stores the ORDER as well as the toggles**, and the 23 reports remember their
layout too — `ReportParameters::snapshot()` reads a page's own public scalar properties and excludes
trait-provided ones, so `$tableColumns` was invisible to it. Both paths go through ONE
implementation (`SavedColumnLayout`); two copies is how they would drift into remembering different
things.

**The failure worth recording is my own test.** The first cut of the saved-report test ran on AR
ageing, which has NO toggleable columns, and quietly skipped its own assertions when it found none —
so it passed with the feature deleted. Both mutations confirmed it: capture removed, green; apply
removed, green. Rewritten on RENT ROLL (3 toggleable columns) with the premise asserted rather than
assumed, both mutations now fail. This is the same class as the reconciliation check that could not
fail and the sweep that matched zero models, and it is the third time this project has recorded it.

**What EG-32 is NOT**, stated rather than implied: a drag-and-drop report DESIGNER, and user-defined
GROUPINGS. Which columns a given report offers as optional stays a per-report editorial decision —
most offer none — and a generic query builder spanning financial statements, an occupancy map and a
workflow diagram is not a sensible thing to build.

### 2026-08-23 — milestone 24: EG-32 slice 3 — the answers become usable

Slice 2 made a custom field fillable and readable on its own record. That is **half a capability**: an
operator who records a parent buying group on two hundred tenants wants a list BY parent group and a
spreadsheet of it, which is usually the whole reason they asked for the field. A value you can type and
never group by is the notes box with extra steps.

`App\Support\Filament\CustomFieldsTable` adds, per active field: a **list column** (hidden until asked
for), a **typed filter**, **database sorting**, and an **export column**. Wired into all five lists and the
three exporters that exist.

**The value is read two different ways, deliberately.** Display goes through the model, so a column shows
exactly what the record page shows. Query goes through SQL (`metadata->{key}`), because filtering and
sorting have to happen in the database — a collection filter pages wrongly and a collection sort only
orders the rows already fetched. Laravel compiles that path per driver and the two genuinely differ
(`json_extract` on SQLite, `json_unquote(json_extract(...))` on MySQL); both were EXECUTED against their
real driver rather than merely compiled, per the standing rule that a query the suite never runs is not
covered by the suite.

**A record that never answered is EXCLUDED, not treated as empty.** `NULL` at the JSON path fails every
comparison, and *"no parent group recorded"* is not *"parent group is empty"*.

**Columns ship hidden.** An operator who defines eight fields must not find eight new columns on a list they
were happy with — and because of slice 1 they can turn on the ones they want and save that as a view.

**The bug this slice hit is worth recording.** Filament resolves a closure's arguments by **parameter
name**. Written `fn (Builder $q, array $data)` a filter registers, renders, and **filters nothing** — the
list ignores it and every part of it looks correct in review. It was caught only by driving the real list
and counting rows; a test asserting the filter exists passes either way. The mutation confirms it: renaming
the parameter back fails two cases.

A second one was in the test rather than the code, and is the same class: this panel sets
`persistFiltersInSession()`, so a filter applied by one Livewire component is still applied when the next
mounts. A control assertion placed last therefore failed against filters the earlier assertions had left
behind — and read exactly like the list being broken.

23 cases (16 → 23), mutation-checked both ways. **Still open on custom fields:** import, and the global
search blob.

### 2026-08-23 — milestone 23: EG-32 slice 2 — D-7, the operator's own fields

**The single biggest structural gap vs Yardi UDFs / MRI user-defined fields / Odoo Studio**, and the
larger half of EG-32. Every operator eventually needs to record something the vendor never modelled:
a tenant's parent buying group, a lease's broker, the landlord-works reference on a shop, whether a
supplier is on a government approved list. Without somewhere to put it the fact goes in the notes
box where nothing can filter, report or export it — or it costs a deploy, every time.

**The storage was already here, and D-7's own evidence was the answer.** `tenants`, `leases`,
`assets`, `vendors` and `departments` have carried a nullable `metadata` JSON column since the first
migrations. All five are `fillable`, all five are cast to `array`, and **not one was written or read
by any form, table, service, report or export** — verified before designing anything, because a
finding in this document has been wrong more often than right. So an answer lives on the record it
describes: no join, no N+1, and an export is a column read. `units` gained the one new column, and it
is the only host-table change — the shop is the record a mall accumulates the most physical facts
about and was the only master record with nowhere to put them. `departments` keeps its column and is
deliberately not offered.

**Only known keys are ever written.** `metadata` being `fillable` made it an open mass-assignment
surface — a JSON column accepts anything without complaint — so `fillCustomFields()` writes only
keys the catalogue currently defines, and **`metadata` was removed from `$fillable` on all five
models**. Nothing filled it wholesale, so nothing breaks; the concern assigns the attribute directly,
which `$fillable` does not govern. Pinned by a test that drives the real Create page and pushes two
extra keys straight into the Livewire payload.

**The key and the record type are immutable; the label is not.** Together they are the ADDRESS of
every answer already recorded — renaming either strands the data in `metadata` where nothing can read
it again. The label is renamed freely, in both languages, and reaches every record at once because it
resolves at read time. Same rule the activity log runs on: the row stores DATA, the words come later.

**Deactivating is not deleting, and deleting is refused once anyone has answered.** A retired field
still explains what is on the records that carry it, so the display keeps showing it — and a value
whose definition was deleted outright still renders, labelled by its own key, rather than becoming
invisible while it is still stored.

**Money documents are deliberately excluded.** An invoice, a payment, a journal entry is evidence,
and an operator-defined field on one is a place to record something onto a document nobody can
reconstruct later — the same reasoning that already refuses to let them be deleted.

**One bug found only by driving the real pages.** Writing worked through a virtual `custom_fields`
attribute; reading did not. Filament fills an Edit form from `attributesToArray()`, which never
contains a virtual accessor — so the section opened EMPTY on every edit, and the next save would have
looked exactly like the operator clearing every answer. Appending the attribute would have fixed it
and been wrong: `$appends` reaches `toArray()`, and `docs/api/openapi.json` is GENERATED from the API
resources' `toArray()`, so a display concern would have quietly rewritten the mobile contract.
`FillsCustomFields` does it on the five Edit pages instead. **Building the schema and asserting its
shape passed the whole time.**

**Deliberately NOT in this slice, and each says why:** filtering and sorting by a custom field (JSON
extraction works on both drivers, but a filter per definition is its own design), a list column,
CSV export, and import. Each is a real half-capability if left unsaid, so they are said.

17 cases in `ACustomFieldIsARowTheOperatorDefinesTest`, including the real Create, Edit and View
pages. Screen at `/admin/custom-fields`, gated on `custom_fields.*` (manager holds view/create/edit;
delete is super_admin), with a bilingual screen guide, and two definitions seeded into `DemoSeeder`
so the capability does not read as unbuilt on a fresh demo.

### 2026-08-23 — milestone 22: EG-32 slice 1 — a saved view remembers its columns

The first slice of EG-32, and the one where **verifying the finding changed what got built**.

S-5 says *"no report builder … every column a PHP literal, no user-defined columns or groupings"*.
The first half is overstated: this app marks **173** columns `toggleable()` and Filament v4 ships a
column manager. Work orders offer **13** optional columns and tenant requests **10** — an operator
can already choose. What was actually missing is the half the same row states correctly: a saved view
stored **filters, sort, search and tab, never columns**, and Filament persists a layout in the
**session**. So the choice survived a page reload and nothing else — it did not travel with a shared
view, was gone tomorrow, and opening a named view left whatever the browser happened to be showing.
That is a real cost, and it is the one an operator pays every morning.

Had I built to the row as written, the work would have been a report builder. The row's own evidence
pointed at `TableView.php:59-69` — `queryParameters()`, an allowlist of exactly four keys.

**Columns travel as `?tableView={id}`.** The layout is far too big for a query string and Filament
binds none of it to the URL, so the link names the view and the page reads the columns back. That
matters because `SavesTableViews` states a design rule in writing — *"a view is a URL … there is no
second code path that sets Livewire state directly"* — and an id in the URL honours it: a saved view
stays a single pasteable link, and a colleague opening it sees the same columns.

**Only the toggles are stored**, and only for columns that are toggleable at all. A fixed column
records no decision — Filament forces its toggle back on when it re-syncs — so storing it would be
noise that reads as a choice and would pin today's fixed set into a row read a year from now. Labels
and hidden flags are not stored either: they are re-derived from the reader's own table every time.

**A view that states no columns opens on the list DEFAULTS**, not on whatever the session held. A
view is a named state a colleague must be able to open and see what you saw; "whatever your browser
was showing" is not a state anyone named.

**The security property has two layers, and only one is ours.** The layout is rebuilt from the
READER's `getDefaultTableColumnState()`, so a name their table does not carry is never introduced.
On top of that Filament's `syncTableColumnStateItemAttributes()` re-derives `label`, `isHidden` and
`isToggleable` and forces a fixed column back on. Mutation testing showed **upstream is the layer
actually doing the work** — deleting our own guard leaves the security test green — so rather than
claim a protection we do not provide, `SavedTableViewsTest` now pins Filament's half as a contract,
the same way `FilamentActionDispatchContractTest` pins hidden-implies-disabled. An upgrade that
changes it turns the build red instead of quietly removing the guarantee.

**Two things deliberately not done, each with its reason.** Column REORDERING is one line in
`TableDefaults` (`reorderableColumns()`) and would 500 two lists: `HasColumnManager` throws a
`LogicException` for any blank-label column when reordering is on, and two table columns here use
`->label('')`. It needs a label sweep across 62 resources, which is its own change. And **`ListInvoices`
offers no toggleable column at all** — the busiest money list has no column choice to save; which of
its ten should be optional is a judgement for the operator, not a default worth guessing.

7 new cases in `SavedTableViewsTest` (20 → 27), every one mutation-checked: making the apply hook
inert fails 3, storing non-toggleable columns fails 1. The "opens on the defaults" case dirties the
session first, because otherwise it would pass just as happily against a feature that does nothing.

### 2026-08-22 — milestone 21: EG-28's other half — a statement is read by the chart's own subtotals

`App\Support\StatementGroups`. **EG-28 is now closed.**

The statements listed every moving account flat under its type: the balance sheet was forty-odd leaf
lines with one figure at the bottom, and the summary accounts the chart already models
(`is_postable = false`) appeared on **no statement at all**. `parent_id` was read by no report.

What that cost, on the demo books: revenue reported one total of **10,060,947**. It is really
**10,055,007 of operating revenue**, 12,440 of other income and −6,500 of sales returns — and
operating revenue is the figure a mall is actually run on. Expenses likewise mixed 489,347 of
operating cost with a 38,833 loss on disposal of assets, so the operating margin was not on the page.

**The group is the highest ancestor BELOW the root**, read off `parent_id` rather than off the code.
`LedgerAccount::saving` derives that parent from the code prefix, so the two agree — but reading the
TREE means it works at any depth and any width without knowing where one level of the numbering ends
and the next begins, which is the assumption the cash-flow statement had to be freed of in milestone
19.

**Three renderers, one helper.** Screen, CSV and PDF each built a statement their own way — the PDF
blades carried **three copies** of one `$lines` closure — and EG-36 had already shipped a screen out
of step with its own export once. They now go through one helper and one blade partial.

**A subtotal is printed only where it says something**, the same rule at two levels: a section with
one group shows none (the subtotal would equal the section total), and a one-row group shows none
(the row already is the subtotal). *"Share capital 500,000 / Total Capital 500,000"* is four lines
for two facts. **The cash-flow statement opts out entirely** — its sections are activities, not
branches of the chart.

**Two live bugs found in the review pass, both on the comparative income statement.**
`ComparativeStatementService::line()` read `$row['label']`, and **neither source emits that key** —
`LedgerReportService::statementRow()` and `BudgetService::asIncomeStatement()` both return `name_en`
/ `name_ar`. Every row rendered as a code beside a **blank account name**, on all three bases, for
the life of the screen; twelve existing tests passed over it because none asserted the label. The
same method dropped `account_id`, so a comparison was the one reading of the P&L whose figures could
not be opened in the ledger — and the code recorded that as *"the comparative service works in
labels and codes, not account ids"*, describing the symptom as if it were the design. Both fixed.

A third bug was caught by the new tests before it shipped: a comparative row names its figure
`current`, not `amount`, so the helper summed nothing and every subtotal read 0.00 — hence the
explicit `amountKey` parameter rather than a silent default.

`StatementsGroupByChartHierarchyTest` (12 cases) + 2 in `ComparativeStatementTest`. Every mutation
checked: disabling grouping fails 5 cases across all three renderers, dropping the code fallback
fails the comparative case, and reverting the blank-label fix fails its own.

### 2026-08-22 — milestone 19a: EG-28 review pass

One finding, and it is the same class as the EG-35 one: **half a capability**.

The form wrote `cash_flow_section` and the chart LIST could not show it — so an accountant handed a
new chart had no way to see what was still unclassified except by opening every account in turn. The
whole point of moving classification off the code is that somebody has to do it deliberately, and
the screen gave them no way to find the work.

The list now carries the column (toggleable, dashed where there is none) and a filter whose real
entry is **"Not classified"** — the question an accountant actually asks when a chart lands. The
filter excludes revenue and expense, which never carry a section and would otherwise drown the
answer on any real chart.

Pinned by mounting the real list page and filtering it, with the revenue/expense exclusion asserted
rather than assumed.

**Checked and NOT defects.** No other code-prefix inference survives anywhere under `app/` — the
sweep for `str_starts_with(...->code)` and `substr(...code)` outside `CashFlowSection` returns
nothing, so the report was the only reader that inferred from numbering. And the absence of a chart
importer is not a new finding: it is EG-28's other half and already recorded as open.

---

### 2026-08-22 — milestone 19: EG-28's dangerous half — the cash-flow statement stops reading code prefixes

Of the remaining rows this was the one that produces **wrong numbers with nothing on screen to say
so**, and it is about to be triggered by a known pending event.

`cashFlow()` classified by six literal `str_starts_with` checks on the account code — `111`, `222`,
`22`, `122`, `12` — so it was correct about the chart this project happens to ship and about no
other. The failure mode is the quiet one: a different Egyptian chart numbered 1–5 by nature but with
different sub-ranges **saves fine**, because the save-time guard only checks the leading digit. Then
a capital purchase lands in operating, a loan drawdown lands in operating, the statement still
balances, and the figures are wrong. The operator's real chart is still pending and the one supplied
so far is recorded in `docs/accounting/` as a dummy template, so this was waiting to happen rather
than hypothetical.

**The account now says where it belongs.** `ledger_accounts.cash_flow_section` — `cash` ·
`operating` · `investing` · `financing` — read through `CashFlowSection::for()`.

**Backfilled from the prefixes, so nothing moves.** The migration classifies every existing account
using exactly the rules the report used, in exactly the order it used them (`222` before `22`, `122`
before `12`), and the seeder does the same for a fresh install. Prefixes survive in ONE place —
`CashFlowSection::forShippedChart()` — which is a statement about *our* chart used to backfill it,
not a rule about charts in general. The report no longer reads a code at all.

**Revenue and expense are deliberately not classifiable.** They net into `net_income` by TYPE, which
is already chart-agnostic; giving them a section would let an operator move revenue into investing
and break the statement's own arithmetic. The form hides the field for them, and a test asserts no
seeded revenue or expense account carries one.

**The floor is OPERATING, not investing.** An account somebody adds without saying where it belongs
is far more often working capital than a capital asset — and being wrong toward operating leaves the
net change in cash correct, while being wrong toward investing misstates two subtotals. Equity
floors to financing.

**Registered in `ValueSets`**, because a mistyped section does not error: it would silently fall
through to the operating default, which is precisely the class of bug this row is about.

**Two details worth keeping.** The cash branch is tested BEFORE the zero-impact guard, because a
cash account whose movement nets to zero over the period still has to contribute to the running cash
figure. And the type Select needed `->live()`, or the section field's `visible()` would never
re-evaluate — it would stay on screen after switching to revenue and stay hidden after switching
back.

**What is still open in EG-28**, and said rather than implied: statement LAYOUT is still a `match()`
on `type`. That is defensible — type is chart-agnostic — but the chart's own `parent_id` rollup is
still read by no report, and there is still no chart importer. Neither is silent, so neither was the
half to do first.

---

### 2026-08-22 — milestone 20a: chart-importer review pass

Three findings, one of which a gate caught and two of which nothing would have.

**1. A tooltip that rendered its own key.** `LedgerAccountForm` asked for
`admin.hints.cash_flow_section` and the string had been added under `helpers`, so the hint icon
would have shown `admin.hints.cash_flow_section` on screen. `TranslationCoverageTest` named it
exactly — the value of a gate that reads the code for referenced keys rather than the lang files for
unused ones.

**2. The screen guide had gone stale in the same change.** `ledger_accounts`' guide still described
adding accounts one at a time and said nothing about importing a chart, the cash-flow section, or
the "Not classified" filter — after two changes that reshaped the screen. `ScreenGuides` exists to
tell an operator *what moves elsewhere when you touch this screen*, and both of the day's additions
do exactly that. Rewritten in both languages.

**3. Adoption ran on every save.** `adoptOrphanedDescendants()` fired on any account write —
renaming an account or toggling `is_active` cannot orphan anything, so that was an extra write-scan
behind every routine edit for no possible effect. Now guarded on `wasRecentlyCreated ||
wasChanged('code')`, which is precisely when the tree can have moved.

**Checked and NOT a defect.** Soft deletes: `adoptOrphanedDescendants()` queries through the default
scope, so a trashed account is neither adopted nor an adopter — and a child whose parent was trashed
keeps its stored `parent_id` rather than being silently re-homed, which is the existing behaviour of
the account's own save and not something this change should decide.

---

### 2026-08-22 — milestone 20: the accountant's own chart can be loaded

EG-28's other half, and the one importer a first deploy actually needs that did not exist. Atriom
ships a chart so a box can post on day one, but the operator's accountant has theirs — and adopting
it meant typing a few hundred accounts into a form, which is how a chart acquires the typo that
misfiles revenue for a year. `docs/accounting/` still records the supplied chart as a dummy Saudi
template, so this is the road the real one arrives on.

**It found a latent ordering bug, which is the substance.** `LedgerAccount::resolveParentIdFromCode()`
looks BACKWARD for a parent that already exists — complete only when parents precede children. That
is true of `ChartOfAccountsSeeder`, which sorts by code, and false of a CSV in whatever order another
system exported it. Filament streams rows in file order and offers **no after-import hook**, so a
file listing `11101` before `111` left the child parented to null: the rollup silently loses a
branch and nothing on screen says so.

`adoptOrphanedDescendants()` closes the reverse direction on `saved`, so the tree is correct whatever
order rows arrive in — the seeder included. Two rules make it safe: it claims a descendant only when
it is a **closer** ancestor than the current parent, so inserting `111` cannot steal `1110123` from
`11101`; and it re-parents by QUERY rather than by saving each child, because a model save would
re-enter the hook and on a real chart that recursion is the whole import. Mutation-proved — remove
the hook and the child stays `null`.

**What is deliberately not a column.** `parent_id` and `normal_balance` are both derived in
`LedgerAccount::saving`, and the model's own docblock says the second "is never set by hand". A
column for either would be a second, conflicting truth — a file could assert that an asset is
credit-normal and the system would quietly disagree.

**`cash_flow_section` IS a column**, which is the pairing with the same day's other change: a chart
arriving from another system is exactly the moment the cash-flow classification has to be stated
rather than inferred from how somebody numbered it. Blank leaves the account on the operating floor,
and the chart screen's "Not classified" filter finds it.

**Identity is the CODE**, the same key the seeder uses, so a second pass corrects rather than
duplicates and an import over the shipped chart merges instead of twinning. That is also the known
hazard the seeder carries — renumbering creates a second account rather than moving one — which is
why the code is treated as identity and not as data.

**The code/type guard is checked in `resolveRecord()`, not as a column rule**, because it is a rule
about the code AND the type together and `getColumns()` is static. The model throws for the same
reason, but its `InvalidArgumentException` reaches the operator as a failed row with a developer's
sentence on it; this reaches them as the message the form shows.

---

### 2026-08-22 — milestone 21a: recurrence review pass

Two findings, both about what the change LEFT rather than what it added.

**1. `Invoice::hasLiveLateFee()` became dead the moment `mayChargeAgain()` replaced its only call
site.** A public predicate on a money model, referenced by nothing — the "remove the old design in
the SAME change" rule, missed by one file. Deleted; `latestLiveLateFee()` supersedes it and is what
the decision reads.

**2. The lease guide never mentioned the late-fee clause at all**, and that lease tab now carries
five negotiable terms — two of them added today. `ScreenGuides` exists to say *what moves elsewhere
when you touch this screen*, and a clause term here is precisely that: it changes what the nightly
sweep charges. One `affects` line in both languages, naming all five and saying that a blank
inherits the property.

**Checked and NOT defects.** Nothing outside `LateFeeService` reads `late_fee_invoice_id`, so
cancelling a fee cannot strand a link — the guard reads status, not presence. And no other caller
assumed `hasLiveLateFee()` meant "ever", because there was no other caller.

---

### 2026-08-22 — milestone 21: EG-35's other half — a late fee that recurs while the debt stands

Deferred from milestone 17 with a reason, and this is that reason discharged: it needed a schema
change on a money link rather than a settings field, so it got its own change and its own tests.

One fee per invoice, ever — a tenant six months late paid the same penalty as one six days late, and
*"2% per month while the balance remains outstanding"* could not be expressed at all.

**It ships OFF.** `late_fee_recurrence_days` is 0 on all three tiers, which is what every install has
done since late fees existed, so no penalty changes on deploy. That default is a position, not
caution: whether a penalty recurs is the sharpest term in a late-fee clause, and Egyptian practice
and the rules around compounding are the accountant's ground rather than this system's. Opt in per
lease.

**The schema change is the audit trail, not the mechanism.** The recurrence DECISION only needed the
last fee's issue date, which `late_fee_invoice_id` could already reach. What it could not do is
answer *"which fees came from this invoice"* once there is more than one — the only record of that
was a sentence inside each fee's line description. `invoices.late_fee_for_invoice_id` is the fee's
pointer back at what it penalises, backfilled from the links that already existed so a fee invoice's
history starts complete.

**Two links, and they are not two truths.** `late_fee_invoice_id` on the source names the most recent
fee and is the idempotency stamp the existing readers and `ChangeImpact` key on;
`late_fee_for_invoice_id` on the fee names its source and is the trail. Different directions,
different questions — and the decision itself reads the trail, so the rule has one home.

**The bar that had to survive.** `items()->where('type','late_fee')->exists()` was doing two jobs by
coincidence: barring an invoice charged under the old in-line behaviour, and — because a FEE
invoice's only line is itself of type `late_fee` — stopping a late fee earning a late fee. With
recurrence on and a fee invoice going past due like any other, that second job is the only thing
standing between the operator and a penalty compounding on a penalty. It stays absolute, recurrence
does not reach through it, and a test drives a real fee invoice past its own due date to prove it.

**Measured from the last fee's ISSUE date**, not the invoice's due date: the clause says "again every
N days", and anchoring to the due date would fire a burst of back-dated fees the first time an old
arrear is swept.

**A cancelled fee still does not count**, so one raised in error is voided and re-charged
immediately rather than the tenant waiting out a window — behaviour that predates recurrence and had
to survive it.

**The sweep needed no change at all.** Its candidate query deliberately over-selects every past-due
invoice and lets `applyTo()` decide, and it snapshots ids up front precisely so it cannot walk into
the fees it just raised. Both properties were already there and both were load-bearing here.

**`ChangeImpactConformanceTest` caught the new column unclassified**, which is the gate doing exactly
its job: a new fillable on a posting source must say whether it can move the books. It cannot —
NEUTRAL, beside its sibling.

---

### 2026-08-22 — milestone 23: EG-40, and a row that did not need the decision I gave it

Recorded an hour earlier, in the EG-39 review, as needing an operator's call. It did not, and
noticing that is the substance.

**The question I wrote down was the wrong one.** *"Should the rate follow a temporary premium?"* —
and framed that way it does look like policy. Re-read: `base_rent_rate_per_sqm_year` is the
CONTRACTUAL rate and `holdover_rate_pct` is the premium recorded on top of it. That split is already
right, and re-rating on conversion would bake a penalty into the contracted rate and lose what the
parties actually agreed. The real question is whether the DERIVATION honours the premium — and there
is only one answer to that.

**What it cost to get wrong.** A rate-priced lease taking an extra unit mid-holdover re-derived from
the contractual rate alone: 300 m² × 4,800 ÷ 12 = **120,000**, where the negotiated 150% holdover
made it **180,000**. The uplift the operator had negotiated, gone, with nothing on screen to say so.
Mutation-proved both ways.

**Applied the way the conversion applies it** — premium on the contracted figure, each step rounded
— so the derivation and `ConvertLeaseToHoldoverService` cannot produce different numbers for the
same lease. And only from `holdover_from`: a date before the conversion is still contracted, the
same way the area is read as it stood on that day.

**The lesson is about the RECORD, not the code.** A row that says "this needs a decision" is a
standing instruction to everyone who reads it afterwards, and a wrongly-framed one parks real work
indefinitely behind a question nobody needs to answer. Worth re-reading a 🔑 before acting on it —
including one I wrote myself an hour before.

---

### 2026-08-22 — milestone 22a: EG-39 review pass

No defects in the change, and one finding beside it.

**Checked and clean.** There are exactly two paths that CREATE a lease — `LeaseCreationService`
(origination, deliberately unchanged and pinned by its own test) and `LeaseRenewalService` (fixed) —
so no third caller carries the same silent replacement. A holdover converts **in place**, so it
takes the model hook's update branch, which already honours a stated rent.
`deriveRateFromBaseRent()` has one caller and is reachable from it.

**Found beside it, and written down rather than fixed: EG-40.** A rate-priced lease put into
holdover keeps a rate that no longer implies its rent — the uplifted figure is stored, the rate is
not touched, and `LeaseSpaceChangeService` re-derives from the rate, so taking an extra unit during
a holdover would silently drop the rent back off the uplift.

It is deliberately NOT fixed here. A holdover is a penalty **on** the contracted rate rather than a
new rate, so re-rating may be exactly the wrong answer — which makes it the same kind of call EG-39
needed, and the same reason to record it as a row instead of inventing a rule inside a review.

---

### 2026-08-22 — milestone 22: EG-39, a renewal is a negotiation and not a rate lookup

The one row on this list that was a live money bug rather than a missing capability, and it needed a
decision before it could be fixed.

`Lease::saving()` re-derives `base_rent_monthly` from rate × area on CREATE — and a renewal IS a
create — on the stated rule that *"a typed monthly figure cannot outrank the rate the deal was
struck at"*. So renewing a 250 m² unit let at 4,800/m²/yr for a negotiated **110,000** saved
**100,000**, with nothing on screen to say the figure had been replaced.

**The operator chose to re-rate**, which is the right reading: a renewal is a re-negotiation, so the
deal wins and the rate follows it. Refusing the mismatch would have made the ordinary case — renewing
at an agreed figure — a two-step workflow with a refusal to explain.

**Fixed in the SERVICE, not in the model hook.** That rule is right at origination and wrong only at
renewal, and the model cannot tell the difference: a disabled form field still posts a value, so
"the caller stated a rent" is ambiguous on create and flipping precedence there would have changed
every rate-priced creation on evidence that does not distinguish them. The renewal service is where
the intent is known. A test pins that origination still behaves as it did.

**The inverse lives beside its twin.** `deriveRateFromBaseRent()` sits next to
`deriveBaseRentFromRate()` in the same concern — one is `rate × area ÷ 12`, the other
`rent × 12 ÷ area`, and a second copy of either inside a service is how they come to disagree. It is
computed off the ORIGINAL's area, because the renewal holds no units until `syncUnits()` runs and
would divide by zero.

**The agreed figure stays EXACT.** A rate rounded to 2dp does not always round back: 97,531.11
became 97,531.19 on an awkward area. The rate is what the rent implies; the rent is what was agreed,
and that is the number the operator must see. Both mechanisms are mutation-proved — remove the
re-rating and the rate stays at last year's, disable the correction and the eight cents appear.

**Two existing tests encoded the old behaviour**, and one of them said so in as many words: *"Pinned
as it behaves rather than as it should behave… the operator's call and not one to invent inside a
regression test."* That is the right way to leave a known defect, and it made this change a
two-line edit with the resolution written where the question had been.

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
