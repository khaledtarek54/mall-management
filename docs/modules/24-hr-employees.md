# Module 24 — HR / Employees (الموارد البشرية)

> **Status: COMPLETE (Phase 3 shipped — per-employee payslips).** A per-property employee
> register + `EmployeeResource` (terminate) + `employees.*` RBAC + the `employees` module
> flag; **advances & loans (سلف)** posting to the GL (grant Dr Employee Advances / Cr
> Cash|Bank; repayment reverses) with derived outstanding + a lock-safe over-repayment
> guard; and **per-employee payroll lines + bilingual payslip PDFs** that break the
> existing lump-sum **Payroll** run (module 21) down by employee (the run header derives
> from Σ lines; the payroll GL entry is unchanged). Fully delivers the discovery backlog
> item **HR-2** (employee master + advances/loans + payslips).

The operator (Eltizam) employs its own staff — managers, engineers, cleaners, security.
This module keeps the **register** of those employees, scoped per property, as the
foundation for financial HR (advances/loans → GL) and per-employee payslips.

**Distinct from:** `users` (admin logins with spatie roles), `tenant_users` (retailer
portal), and `departments` (org units). An employee is a *payroll subject*, not a login.

---


> **⚠️ The header's rollup was written twice (fixed 2026-08-20).** Seven identical sums plus the net
> lived in **two** places — `recomputeFromLines()`, called from the payslip save/delete hooks, and the
> `saving` hook that stops someone typing over the header while payslips exist. Both are needed, for
> different reasons, and both computed the same rule.
>
> They agreed, and that is the hazard: an **eighth** component would have to be added to both, and
> the copy that was missed would produce a payroll header disagreeing with the payslips beneath it —
> the divergence the invoice validation sweep closed on §8 R1, and the same rule applies (several
> channels change the number, so exactly one method computes it). `fillTotalsFromLines()` is now that
> method; it **assigns only**, because the `saving` hook is already inside a save and must not
> re-enter one. Found by reading, not by a failure: nothing was wrong, there were simply two of it.
> `PayrollHeaderHasOneDefinitionTest` drives BOTH paths and compares every component, and is proven
> by breaking the shared method and watching four of six go red.



> **⚠️ A month could be paid twice (fixed 2026-08-20).** `payroll_lines` is unique on
> `(payroll_id, employee_id)`, so nobody appears twice in ONE run — and **nothing stopped a second
> RUN for the same property and month.** Found by driving the module on real data: two August runs
> of nine employees each, both approvable. Approving both paid every employee twice and posted
> **134,564 for a month whose payroll was 66,782**, with no screen and no tie-out objecting, because
> each run is internally perfect.
>
> Guarded at the **transition into approved**, and on the **employee**, not the run. A supplementary
> run is legitimate — a bonus, an off-cycle correction, a starter paid late — so refusing a second
> run outright would block all of them; what may never happen is one person drawing two approved
> payslips for one period. On the MODEL rather than in the approve action, for the same reason the
> posting-date guards are: the action is one caller, and a console or a service must meet the same
> refusal. The message names the employees and points at the correction path — cancel the earlier
> run — and a **cancelled** run correctly stops blocking, or the operator would be told to do
> something that does not work. `PayrollHeaderHasOneDefinitionTest`, proven by removing the guard
> (11 of 12 red).

> **⚠️ …and a LUMP-SUM run walked straight past that guard (SW-100, fixed 2026-09-03).** The bar
> above is on the EMPLOYEE, which is what keeps the supplementary run possible — and a lump-sum run
> names nobody. It has no lines, which is a SUPPORTED shape rather than an abuse (`PayrollForm`
> unlocks the header money fields exactly when a run has none), so it skipped the guard on its own
> approval **and left nothing for another run's guard to find**.
>
> Measured on HEAD: a 3-line payslip run of 12,000 and a lineless run of 12,000, same mall, same
> month, **both approved in either order**, and two lineless runs likewise. `PayrollJournalizer`
> reads `gross_salaries` from the HEADER, never from the lines, so the ledger carried **24,000 of
> salaries expense for a 12,000 month** — overstating the wage bill, understating **net operating
> income** (the figure a valuation and every owner statement are built on), and crediting the bank
> for cash that never left, which leaves `MatchBankStatementLineService` carrying a phantom outflow
> permanently. Nothing downstream objects: `billing:reconcile` does not look at payroll at all, and
> each journal entry is internally balanced. One user reaches both doors — `accounting` holds
> `payrolls.create`, `.edit` and `.approve`.
>
> So when EITHER side cannot answer per employee, the bar falls back to the RUN. A run WITH lines
> still only has to fear the lineless ones, which is what keeps the deliberate supplementary payslip
> run working — and the refusal names the escape (cancel the earlier run, or give this one lines
> saying who it pays), because a refusal with no way out pushes an operator to back-date into the
> wrong month, which is worse than what it prevents.
>
> **The property clause was the same hole through a second door.** A run filed against NO property is
> portfolio-wide, and `where('asset_id', $id)` compiles to `asset_id = null` for the consolidated
> run — matching nothing — so a consolidated run and a mall run for the same month were both
> approvable. Both clauses are fixed in one pass; fixing one would have looked complete and not been.
> (`ANoMonthIsPaidTwiceTest`, 8 cases, 3 mutations. Note the fixture takes a SENTINEL rather than
> `?? $asset->id` for the property: `??` collapses "no property" into "this mall", which made the
> consolidated case pass for the wrong reason — the clause could be mutated back with the test still
> green.)


## Importing the payroll register at cut-over (`EmployeeImporter`, 2026-08-12)

A mall runs dozens of staff across security, cleaning, technical and admin, and the first payroll
run has to be complete or both the month's salary expense and the social-insurance withholding are
wrong. Typing them in on go-live morning is not a plan.

- **Identity is the national id, never the name.** Two staff share a name eventually, and a
  re-import matching on name would merge them into one record — one salary, one person unpaid. The
  employee code is the fallback; a row with neither creates a new record on every import, which is
  stated on the class rather than left to be discovered.
- **`base_salary` and `hire_date` are required, and blank is refused rather than defaulted.** A
  payslip generated from a zero salary looks correct and pays nobody — the same silent-zero as
  `opening_accumulated_depreciation` on the fixed-asset register. `hire_date` is NOT NULL in the
  schema and dates the employment that payroll and any end-of-service calculation rest on.
- **Departments are matched, never created.** A typo would otherwise open a second "Securty" and
  split the register across both.
- Property-clamped through `ResolvesVisibleAssetByCode`. An import bypasses the Create page where
  `assertAssetInScope()` runs, so without it a restricted user could staff another mall's payroll
  from a CSV. Admin-only via `App\Support\Imports`.

**Tests:** `tests/Feature/Regression/CutOverImportersTest.php`.

## 1. Domain model

### `employees` — the staff register (per property)
| Column | Meaning |
|--------|---------|
| `asset_id` | the property (FK, cascade) — scope |
| `department_id` | org unit (FK, nullable — global or property department) |
| `code` | staff number (**unique per property**) |
| `name` · `national_id` · `position` · `phone` | identity |
| `hire_date` | employment start |
| `base_salary` | monthly base (drives future payslips) |
| `payment_method` | `cash` \| `bank` — how they're paid |
| `status` · `terminated_on` | `active` / `terminated` |

### `employee_advances` — advances & loans (سلف)
| Column | Meaning |
|--------|---------|
| `employee_id` · `asset_id` | the employee + **denormalised** property (GL dimension survives the employee being archived) |
| `type` | `advance` \| `loan` |
| `amount` · `advance_date` · `paid_from` | the grant (cash \| bank) |

### `employee_advance_repayments` — repayments (child of the advance)
| Column | Meaning |
|--------|---------|
| `employee_advance_id` · `asset_id` | the advance + denormalised property |
| `amount` · `repaid_on` · `method` | the repayment (cash \| bank) |

Outstanding on an advance = `amount − Σ repayments` (DERIVED, never cached).

### `payroll_lines` — per-employee breakdown of a payroll run (Phase 3)
| Column | Meaning |
|--------|---------|
| `payroll_id` · `employee_id` | the run + employee (**unique per run**) |
| `gross` · `salary_tax` · `social_insurance` | that employee's amounts |

Net per line = `gross − tax − insurance` (accessor). When a run has lines, its **header
aggregates DERIVE from Σ lines** (`Payroll::recomputeFromLines`); lines are pure detail —
NOT a ledger source — so the existing payroll journalizer (which posts the header) is
unchanged and the GL tie-out is untouched.

---

## 2. Business rules

1. **Property-scoped** (`asset_id`), like units / fixed assets — scoped in Filament via
   `BypassesScopingOnAll` + `tenantOwnershipRelationshipName='asset'`; create/edit
   re-validate the submitted `asset_id` against `visibleAssetIds()`
   (`EmployeeResource::assertAssetInScope`), closing the All-Properties tamper hole.
2. **Unique staff code per property** (DB composite unique + form rule).
3. **NOT-NULL money** — blank `base_salary` coerces to 0 in the model.
4. **Terminate** flips `status → terminated` + stamps `terminated_on` (gated on
   `employees.edit`, server-side re-checked). It lives on the employee's own Edit page — moved off
   the list row 2026-08-30, the list FINDS and the record ACTS
   (`App\Filament\Admin\Actions\EmployeeActions`).

   **And `Reinstate` is the way back, which did not exist until 2026-09-03 (SW-097).** Terminate was
   the only act that touched the status and the form carries no status field, so a mis-click on a
   list — the wrong row, which is how this happens — was permanent: the person drops out of payroll,
   the org chart and every active-only picker, with nothing on any screen offering a correction. A
   dead-end status is the shape this codebase fixed twice in the same sweep (a draft invoice with no
   way out, a cheque whose only exit from `cleared` was a bank return that never happened), and the
   rule they all follow is `RefusesDeletionOfCommittedRecords`': correct a record through a workflow
   that leaves a trail, never by editing a column and never by having no answer.

   The pair is **exclusive** — each is visible for exactly the status the other is not — and
   `terminated_on` is cleared with the status, because leaving it behind would say the person left on
   a day they are still employed, and it is what every *was this person here then* read looks at.
5. **Advances/loans post to the GL** (see §3.5). No advance may be **granted to a
   terminated employee**; a **repayment can't exceed outstanding** (lock-safe re-check in
   `RecordAdvanceRepaymentService`, so concurrent repayments can't drive the receivable
   negative).
6. **NOT-NULL money** — blank `base_salary` / advance `amount` / line amounts coerce to 0.
7. **Payslip lines are draft-only** (Phase 3) — lines may be added/edited/removed only
   while the run is a **draft**; once approved the header (and its GL entry) is settled and
   the lines are frozen. **Enforced on the model since 2026-08-11** (module 24 close-out):
   `PayrollLine::saving`/`deleting` refuse a run that is not draft, and `Payroll::saving`
   refuses any money / period / paid-from change once the ORIGINAL status is `approved`.
   Until then the freeze was `abort_unless(runIsEditable)` — a method that exists in exactly
   one place, `PayrollLinesRelationManager` — so it was a property of that screen.
   `GeneratePayrollService` guards itself, so both KNOWN writers were safe and every other one
   (import, console, a future screen) restated a posted payroll. Mutation actions stay hidden in
   the UI as the mirror. Tests: `PayrollHeaderAndApprovedLockTest`.
8. **The header ties to Σ payslips, from BOTH directions.** `Payroll::recomputeFromLines()`
   pulls the lines into the header on every line write; `Payroll::saving` now re-derives a header
   written DIRECTLY, so the two cannot diverge. Before, the pull was the only direction and its
   docblock said so ("called only from the PayrollLine save/delete hooks") — a header written by
   an import persisted whatever arrived, and `PayrollJournalizer` posted the salaries debit from
   the header while the payslips, and the PDFs an employee is handed, said something else. The
   same divergence the validation sweep closed on invoices (§8 R1), closed the same way.
   **A lump-sum run with no payslips keeps its manual amounts** — nothing to derive from, exactly
   as an invoice with no line items keeps its header.
   Payslip PDFs download at any status. A line's employee is re-validated against the run's
   property scope (form-tamper guard). Header money fields go read-only once lines exist.

---

## 3. RBAC & module flag

- Employee permissions `employees.view/create/edit/delete` (delete = super_admin only,
  project-wide) + the financial actions `employees.grant_advance` / `employees.record_repayment`.
  Granted to the **`hr`** role (view/create/edit + both advance actions); **accounting** gets
  `employees.view` + both advance actions (سلف is a treasury action); **manager** (all
  non-delete) and **viewer** (all `.view`) inherit via the flat list.
- Module flag **`employees`** (`Modules::KEYS` + `ModulesSettings`), on by default,
  toggleable from /admin/settings → Modules.

---

## 3.5 GL posting (Phase 2)

Advances & loans post to the double-entry ledger through **two journalizers** registered in
`LedgerPoster` and reconciled by the `accounting:sync-ledger` sweep (the standard
self-healing path). **Employee Advances `11203001`** is a dedicated receivable (money owed
BY staff) — NOT accounts receivable `11201001` (which ties out to tenant invoices) — so the
AR tie-out that gates monthly close is unaffected.

| Event | Source | Entry |
|-------|--------|-------|
| **Grant** | `EmployeeAdvance` | Dr Employee Advances `11203001` / Cr **Cash `11101001` \| Bank `11102001`** (per `paid_from`) |
| **Repayment** | `EmployeeAdvanceRepayment` | Dr **Cash \| Bank** (per `method`) / Cr Employee Advances |

- Grant + repayments net Employee Advances back toward zero as the loan is paid off.
- **Denormalised `asset_id`** — set from the employee/advance at creation, so the books
  dimension (and the entry) survives the employee being archived (like marketing spend
  vs an archived budget). The journalizer resolves the employee name `withTrashed`.
- **Repayment is a CHILD ledger source** of the advance — its GL follows the advance's
  lifecycle via `EmployeeAdvance::booted()` (soft-delete cascades to repayments, stamped
  with the parent's `deleted_at`; restore matches exactly), so the windowed sweep
  self-heals (the child-source-windowed-sweep pattern from fixed assets).
- **Mapping:** `employee_advances → 11203001` (added to `AccountMappingSeeder`).

---

## 4. Roadmap

| Phase | Scope | Status |
|-------|-------|--------|
| **1 — Employee master** | `Employee` model + migration, property-scoped `EmployeeResource` (form + table + terminate), `employees.*` RBAC, module flag, tests | ✅ shipped |
| **2 — Advances / loans (سلف)** | `EmployeeAdvance` + `EmployeeAdvanceRepayment` posting to the GL (Dr Employee Advances `11203001` / Cr Cash\|Bank on grant; reverse on repayment), grant + repayment services (lock-safe over-repayment guard), the advances relation manager, chart account + mapping + 2 journalizers + sweep, tests | ✅ shipped |
| **3 — Per-employee payslips** | `PayrollLine` (per-employee gross / tax / insurance / net; run header derives from Σ lines, GL unchanged) + `PayslipPdfService` (bilingual payslip PDF, mpdf) + the payroll-lines relation manager (draft-only add/edit/remove, property-scoped employee, payslip download) | ✅ shipped |

| **3c — Generate payslips from roster** | `GeneratePayrollService` (one line per active employee, gross = base salary, deductions from the dated `payroll_rates` ladder) + the *Generate payslips* action + settings tab + create→edit redirect + guiding empty state | ✅ shipped |
| **4a — Salary structure** | Allowances (بدلات — itemised portion of gross) + **employer social insurance** (a company cost that posts Dr Social Insurance Expense `51110001` / Cr Social Insurance Payable, without touching net pay); settings-driven employer rate; payslip/register/journalizer expanded; GL tie-out preserved | ✅ shipped |
| **4b — Advance repayment via payroll** | A payslip line repays one of the employee's outstanding advances: the installment reduces net pay and the payroll entry credits Employee Advances `11203001` (closing the سلف loop). `EmployeeAdvance::outstanding()` derives to include approved-run installments; lock-safe over-repay re-check at approval; cancel restores the balance | ✅ shipped |
| **4c — Ad-hoc / penalty deductions (خصومات)** | A payslip line carries an `other_deductions` amount (+ note) for penalties / absence / damages; reduces net pay and credits a holding liability **Employee Deductions Payable `21602001`** (accountant reclassifies via mapping) | ✅ shipped |

**Future (Phase 4d+, not built):** the **progressive Egyptian income-tax bracket engine** — seven
bands and a personal exemption, replacing the flat `salary_tax_rate` (finding P-2). Still gated on
the accountant, and on a prior question: *whether the operator wants this system to compute
statutory payroll at all, or to keep keying it per run* (EGYPT-MARKET-FIT §6.4). The dated ladder
shipped in EG-03 is what a bracket table will hang off when that is answered — brackets are rungs
with more columns, not a different mechanism; structured basic-first entry (build gross up from basic + allowances); and
multi-advance installments per employee per run (today a line repays ONE advance).

### Phase 4c — ad-hoc / penalty deductions (خصومات, 2026-07-26)

A payslip line can carry an **`other_deductions`** amount plus an optional **`deduction_note`**
— for penalties (جزاءات), absence (غياب), damages, or any miscellaneous withholding beyond tax /
SI / advance. It reduces net pay (`net = gross − tax − SI − advance − other`) and the payroll GL
entry credits a **holding liability, Employee Deductions Payable `21602001`** — a neutral account
the accountant reclassifies via the mapping (a penalties fund صندوق الجزاءات, other income, or an
expense-reduction, per the deduction's nature). Set on the line form (with the net-≥0 guard); the
payslip PDF and register CSV break it out; the note shows under the amount in the lines table.

### Phase 4b — advance repayment via payroll deduction (2026-07-26)

Closes the سلف loop: instead of a separate cash repayment, an employee's loan installment is
withheld from their payslip. On a **draft** run, the *Advance installment* line action picks one
of the employee's **outstanding** advances and an amount; the installment reduces net pay.

**GL (one leg added to the run entry):** `Cr Employee Advances 11203001` for the installment,
and `Cr Cash|Bank` for the *reduced* net (`gross − tax − SI − installment`). No separate
cash-repayment document — that would double-credit the receivable. Balanced:
`Dr gross + employer_si = Cr tax + (emp_si + employer_si) + installment + net`.

**Outstanding stays truthful.** `EmployeeAdvance::outstanding() = amount − Σ cash repayments −
Σ APPROVED-run installments`. Only approved (non-cancelled, non-trashed) runs count, so:
cancelling a run automatically **restores** the balance (no repayment record to unwind), and the
existing over-repayment guard on manual cash repayments already accounts for payroll installments.

**Guards.** At line-edit: installment ≤ the advance's outstanding **and** ≤ take-home (net ≥ 0),
and the advance must belong to the line's employee + property (tamper guard). At **approval**, a
**lock-safe re-check** (advances row-locked) rejects the run if — with this run now counting —
any advance would be over-repaid (two draft runs each within outstanding could otherwise together
exceed it). A trashed/removed advance blocks approval until the installment is cleared.

### Phase 4a — salary structure (2026-07-26)

A payslip is no longer just `gross / tax / insurance`. Two additive money fields on both the
run and each line:

- **`allowances` (بدلات)** — the allowance PORTION of gross. `gross` stays the single source of
  truth for total earnings (still Dr Salaries Expense), so every existing write path is
  unaffected; this itemises the split for the payslip (basic = gross − allowances, a derived
  accessor). Guard: `allowances ≤ gross` (model invariant + inline form rule).
- **`employer_social_insurance` (حصة صاحب العمل)** — the EMPLOYER's contribution. Unlike the
  employee `social_insurance` (withheld from pay), this is a **company cost that does NOT reduce
  net pay**. The `PayrollJournalizer` posts it as a balanced pair — **Dr Social Insurance Expense
  `51110001`** (new account) / **Cr Social Insurance Payable `21601001`** (the same liability the
  employee share credits, so the total owed to the authority = employee + employer). The entry
  stays balanced (Dr gross + employer_si = Cr tax + emp_si + employer_si + net) — no new GL
  *source*, so the registry/tie-out gates are untouched; `GlPostingSourcesScenarioTest` drives the
  **real approve + sweep** and asserts the expanded entry balances.

Rates come from the dated ladder (`PayrollRates::for($month)->employerSocialInsuranceRate`, default 0 — the
same no-guessing rule: the employer share is a policy the accountant confirms before it posts).
`GeneratePayrollService` fills `employer_social_insurance = gross × rate`. The register CSV and
bilingual payslip PDF break out basic / allowances / gross / net and note the employer
contribution separately.

---

### Generate payslips from the roster (UX, 2026-07-26)

**The gap the operator hit:** "generating" a payroll run meant typing the lump-sum totals
(gross / tax / insurance) by hand, then — to break it down — opening the lines relation
manager and adding each employee one at a time, re-typing every number. The employee
register and each employee's `base_salary` were never used. Manual totals on a run that
posts to the GL is exactly what a payroll system should compute for you (cf. Odoo's payslip
batch: you generate slips from the roster, review, then confirm).

**The fix — one action, `GeneratePayrollService`:** on a **draft** run, *Generate payslips*
(the primary action in the lines relation manager) creates one `PayrollLine` per **active**
employee in the run's property, pre-filled:

| Field | Source |
|-------|--------|
| `gross` | the employee's `base_salary` (master data) |
| `salary_tax` | `gross × the salary-tax rate` (0 by default) — the WHOLE gross |
| `social_insurance` | `insurable wage × the employee SI rate` (0 by default) — the gross **clamped into the statutory band** |
| `employer_social_insurance` | `insurable wage × the employer SI rate` — the cap binds this share too |

> **Every figure comes from a DATED RUNG, resolved for the run's own `period_month`** (EG-03,
> 2026-08-22). `App\Support\PayrollRates::for($month)` is the payroll twin of `Vat::rateForType()`,
> and `payroll_rates` is its ladder: one row per decree, carrying the insurable-wage band and the
> contribution rates that came into force together. Maintained at `/admin/payroll-rates`.
>
> They were three flat `PayrollSettings` scalars until then, and both halves of that were wrong.
> **Undated** (finding P-3): a January run generated in March computed on March's numbers, a rise
> could not be entered in advance, and nothing recorded what a past run had used — against a state
> that raises the band every January. **Uncapped** (finding P-1): the SI rate was applied to
> `base_salary` outright, so every employee above the ceiling was over-deducted and the employer
> over-accrued, under a comment reading *"Employer SI is a company cost — it does NOT reduce net,
> so no cap needed"*, which misreads the rule. The cap is on the WAGE and it binds both shares.
>
> **Two different bases, and that is the substance.** Salary tax is charged on the whole gross;
> social insurance on the gross clamped into the band. A null floor or ceiling means no bound — not
> zero, which on the ceiling would insure everybody on nothing.
>
> **Origination only.** An approved run's amounts are frozen on its own lines, so correcting a rung
> changes what the NEXT generation computes and nothing already computed — the same rule that keeps
> an issued invoice on the VAT rate it was billed at. Pinned by
> `PayrollRatesAreDatedAndCappedTest`.

> **The zero defaults are watched, not just documented (EG-04, 2026-08-20).**
> `/admin/configuration-health` carries a `payroll_rates_configured` row. It does **not** call a zero
> rate a fault — the settings screen's own help offers *"leave at 0 and enter it per employee"* as a
> supported way to work, and a checklist that contradicts the field help beside it teaches the
> operator to ignore the page. It fires on **evidence** instead:
>
> - **Blocking** — a property's most recent payroll MONTH has an approved run with gross pay and no
>   salary tax, no employee insurance and no employer share. Net was the full gross and the books
>   carry none of the liability. An approved run's amounts are frozen, so it cannot be edited in
>   place — and a SECOND approved run for the same people in the same month is refused outright
>   (`Payroll::booted()`: "…would give N employees a second approved payslip for this month").
>   So the remedy is to **cancel the run and re-issue it** with the deductions, after setting the
>   rates. The row clears because the month's latest approved run is then the corrected one.
> - **Advisory** — a live roster, every rate still nil, and nothing approved yet.
>
> Judged per property, never on a future-dated month, and scoped to the assets the reader may see.
> The future-month bound is `startOfMonth()->addMonth()`, in that order: `addMonth()` first overflows
> on the 29th–31st (2026-08-31 + 1 month is 2026-10-01), which on seven days of the year admitted a
> genuinely future run as "latest" and hid the broken current month.
> The reasoning is in `ConfigurationHealth::payrollRatesConfigured()`'s docblock and is not repeated
> anywhere else.

The generated lines **are** the review surface — the operator adjusts any line, then approves.
Nothing about posting changes: the run header still **derives** from Σ lines
(`Payroll::recomputeFromLines`) and the existing payroll journalizer posts the (unchanged)
aggregate, so the GL and its tie-out are untouched. This automates data entry, not accounting.

**Invariants honoured:** draft-only (a settled run's lines are frozen); **property scope**
(never pulls an employee from another mall — same pool as the manual add); **one line per
employee** (already-lined + terminated staff are skipped, never duplicated — row-locked so
two concurrent generates can't race the unique index); **net ≥ 0** (deductions are capped so
a generated payslip can never print a negative net). The confirmation modal previews the
count first (`eligibleCount`), and the result notification is honest about employees with **no
base salary set** (added at gross 0 for the operator to fill, and counted) — no silent skips.

**Statutory rates are settings, not constants** — `PayrollSettings` (group `payroll`, a new
tab on /admin/settings). Both rates **ship at 0**, following the `TaxSettings`/WHT precedent:
Egyptian income tax is progressive-bracketed and social insurance rides a capped subscription
salary, so a guessed flat constant would look authoritative and be wrong. The accountant sets
them (or leaves them 0 and enters each employee's deductions), and every generated amount
stays editable per line before approval. This is the "taxes as configurable settings"
direction the operator asked for, scoped to payroll.

**Supporting UX:** creating a run now lands on its Edit page (where the lines + generate
action live, not back on the list); the Amounts section explains you can leave the header at 0
and build from payslips; and the lines table has a guiding empty state.

**Live updates (no manual refresh).** The header totals render on the parent Edit form — a
*separate* Livewire component from the lines relation manager — so a line change would
otherwise leave the header stale until a page reload. Any line mutation (generate / add / edit /
delete) dispatches `payroll-lines-updated`, which the page listens for and re-pulls the derived
gross / tax / insurance / net (`EditPayroll::refreshDerivedTotals`, which `$this->record->refresh()`es
first — `refreshFormData` reads the in-memory record, and the page's record is a distinct instance
from the relation manager's owner record).

> **Deliberately NOT done — the reverse direction.** Freezing the relation-manager actions the
> instant a run is approved/cancelled would need the *page* to re-render the *relation manager*.
> A bare `#[On]` listener on a relation manager renders it with an **uninitialised
> `$table`** (`InteractsWithTable` boots the table only on its own request lifecycle, not when a
> parent event forces a nested re-render) → a runtime 500. So approve/cancel only refreshes the
> page's own status field; the lines' add/generate/edit buttons stay visible until the next
> interaction, at which point the server-side `abort_unless(runIsEditable)` guards block the write
> (the security boundary was always server-side — this is only a cosmetic lag). A safe future
> polish is a full redirect after approve/cancel.

### Payroll register CSV export (UX, 2026-07-23)

Per-employee payslips shipped in Phase 3 — but one PDF at a time. What HR/finance actually works each
month is the **consolidated register** (muster roll): every employee on the run with gross, statutory
withholdings and net, in one spreadsheet. That view existed nowhere. Added an **Export register**
row action on the Payrolls table (per run) via the shared `App\Support\ReportCsv` (UTF-8 BOM).
`PayrollResource::registerCsv($run)` reads the run's `payroll_lines` (employee `withTrashed` so a
frozen run stays reproducible after staff turnover), emits code / name / position / gross / salary tax
/ social insurance / net per employee, and closes with totals that **tie to the derived run header**
(`net_paid`). The action shows only when the run has per-employee lines (a lump-sum run has nothing to
break down) and gates `canView` in both `visible()` and `authorize()`. Same accountant-workable
finding as inventory (mod 22) and fixed assets (mod 23).

## 5. Tests

`tests/Feature/Services/GeneratePayrollServiceTest.php` — generate builds one line per active
employee (gross = base salary), pre-fills deductions from the configured rates, derives the
header from Σ lines, is idempotent (skips already-lined staff, then adds only new hires),
refuses a non-draft run, never crosses property scope, caps deductions so a line can't go
net-negative, and flags employees with no base salary. `PayrollLinesRelationManagerTest`
covers the *Generate payslips* action (creates lines + derives the header, draft-only, gated on
`payrolls.edit`). `PayrollSettingsSmokeTest` — the settings page renders the Payroll tab and
persists the rates.

`tests/Feature/Regression/PayrollRegisterCsvTest.php` — the register CSV computes each line's
net (`gross − tax − insurance`) and closes with gross / tax / insurance / net totals that **tie to
the derived run header** (`net_paid`).

`tests/Feature/Resources/EmployeeResourceTest.php` — `employees.*` RBAC gating (hr owns
it; accounting/viewer read-only; leasing none), module-off hiding, property scoping, the
unique staff-code-per-property rule, the terminate action (+ read-only guard), and the
`assertAssetInScope` write guard.

`tests/Feature/Services/EmployeeAdvanceLedgerTest.php` — grant (Dr Employee Advances /
Cr Cash|Bank, not touching AR/AP), repayment (Dr Cash / Cr Employee Advances), derived
repaid/outstanding, the over-repayment guard, grant-to-terminated rejection, the receivable
netting to zero after full repayment, and the cascade void-on-delete through the **windowed
sweep**. `tests/Feature/Resources/EmployeeAdvancesRelationManagerTest.php` — the grant +
repayment actions, RBAC gating, the maxValue over-repay guard, and the terminated-employee
grant lock-out.

`tests/Feature/Services/PayrollLineTest.php` — the run header deriving from Σ lines (add +
delete), the line net accessor, the line-driven header posting the (unchanged) aggregate GL
entry on approval, and the payslip PDF rendering. `tests/Feature/Resources/PayrollLinesRelationManagerTest.php`
— add-line + header recompute, lines frozen once approved, the out-of-property employee
tamper guard, `payrolls.edit` gating, and payslip download visibility.

**Related:** 21 General Ledger (payroll posting + advances GL), 14 Departments
(org units), 18 RBAC (the `hr` role), 01 Properties (asset scope).

### Closed-period guard covers the GRANT side too (gap-analysis, 2026-07-29)

F-89 guarded the money going **out** of an advance (`RecordAdvanceRepaymentService`) and left the
money going **in** unguarded — the same silent divergence on the sibling half of the same document.
Now guarded via `App\Support\PostingDate`:

- **`GrantEmployeeAdvanceService`** — `advance_date` is the grant entry's `entry_date`. Unguarded,
  an employee carried an outstanding balance with no *Dr Employee Advances / Cr Cash* behind it, and
  the repayments that later relieve that receivable credited an account the grant never debited.
- **`PayrollService::approve()`** — `period_month` dates the payroll entry, and **approval**, not
  drafting, is the moment the run becomes GL-postable. A run can sit in draft across a month-end
  close; approving it then relieves every advance installment in the run and marks salaries paid
  while *Dr Salaries / Cr Cash* fails silently in the best-effort sync job. Approving is
  irreversible in practice — `cancel()` voids the entry, but the installments have already counted.

Tests: `tests/Feature/Regression/PostingDateGuardTest.php` (mutation-checked — removing the guards
fails them).

---

## Deletion policy

Operator decision 2026-07-31, following Yardi/MRI/Entrata: a record that carries history is
**refused**, not warned about — the damage lands on the reports and audit trail that referenced
it, none of which are in front of whoever clicks the button. The single register is
[`App\Support\DeletionPolicy`](../../app/Support/DeletionPolicy.php); `DeletionPolicyConformanceTest` fails the build if a model here ships unclassified or a Delete
button reappears on a money record.

| Model | Rule | Instead / why |
|---|---|---|
| `Payroll` | **Never deletable** | cancel the run — payslips and their GL entries follow it |
| `PayrollLine` | Deletable (super_admin) | parent-managed: rebuilt when payslips are regenerated |
| `Employee` | **Only while unreferenced** — blocked by `payrollLines`, `advances`, `custodies` | set the employee inactive — payroll history is a statutory record |
| `EmployeeAdvance` | Deletable (super_admin) | operational: reversed rather than removed |
| `EmployeeAdvanceRepayment` | Deletable (super_admin) | parent-managed: deleted to reverse a repayment |

---

## End-of-service gratuity (2026-08-18)

Payroll books the employee withholdings **and** the employer social-insurance contribution
(`PayrollJournalizer` posts `Dr Social Insurance Expense` for the employer share), so month-to-month
labour cost is right. What appeared nowhere was an entitlement that builds up silently over a
career: if it is owed, the books understate both the expense and the liability by the whole accrued
amount, and nobody sees the gap until somebody leaves.

**`App\Services\GratuityService`** computes it. Labour Law 12/2003 Art. 122: half a month's pay per
year for the first five years, one month per year thereafter — both figures are **settings**, not
constants, because a contract may be more generous than the floor and often is. Accrual is
**pro-rated within the year** rather than stepped at the anniversary: the liability builds
continuously, and a provision that jumped once a year would be wrong for eleven months of it.

**⚠️ It ships SWITCHED OFF, and that is a considered position rather than caution.** Art. 122
applies to workers **not covered by the social insurance law**, and in Egypt most employees are
covered — unlike the Gulf, where an EOS gratuity is close to universal. So an Egyptian employer
frequently owes nothing, and **accruing a provision nobody owes overstates the liability exactly as
surely as omitting a real one understates it**. Whether this workforce is entitled is a question
about their contracts and their insurance status: the accountant's to answer, not the software's to
assume. Same treatment straight-line rent gets under EAS 49 — built, correct, and inert until
someone decides.

**Nothing posts to the GL yet, deliberately.** The exposure is surfaced on Settings → Payroll beside
the toggle, so the entitlement decision is made against a number ("EGP X across N active employees —
what would be owed if everyone left today") rather than a feeling. Wiring a journalizer should
FOLLOW the entitlement ruling, not precede it: a provision on the balance sheet before anyone has
established it is owed is the same error in the opposite direction.

Two edges the tests pin: the clock **stops at termination** (asking four years later must not add
four years to what was owed on the way out), and terminated staff are **excluded from the exposure**
— whatever they were owed is settled or is a payable in its own right, and counting them would
double the liability at the moment it crystallises.

Tests: `tests/Feature/Regression/GratuityAccrualTest.php` — the off-by-default is the first thing
asserted, and the second tier is proved by a figure straight-line accrual could not produce.


## The language a payslip is written in (2026-08-28)

`employees.locale` — nullable, `en` / `ar`, on the employee form and `EmployeeImporter`.

An employee who reads only Arabic being handed an English breakdown of their own deductions is the
plainest case the document-language work exists for, and the payslip was following whoever generated
the run. It now follows the employee; **blank is the normal state** and falls back to the generator,
with the download picker as the override.

Same shape as `vendors.locale` — see [12-vendors](12-vendors.md) and
[OVERVIEW → Core business rules](../OVERVIEW.md#4-core-business-rules-quick-reference). `Employee` is
not `Notifiable`, so it carries the column without `HasLocalePreference`; the column is registered in
`App\Support\ValueSets`.

## The document, set in Direction D (2026-08-28)

Built on the shared shell (`resources/views/pdf/layout.blade.php`) and rendered by
`App\Support\Pdf\PdfDocument`: a full-bleed navy band carrying the mall's identity, everything below
it white paper with hairlines, and the one figure the reader came for set apart on the accent.

The direction was chosen from four drawn side by side in both languages; the tradeoff accepted with
it is that this is the heaviest of the four on ink, which is why the band is the ONLY large ink field
and the accent is spent once per page. See
[OVERVIEW → Core business rules](../OVERVIEW.md#4-core-business-rules-quick-reference).

**It is written in its reader's language**, resolved through `App\Support\Pdf\DocumentLocale` —
what the operator picked on the download modal, else the recipient's own stored `locale`, else the
request's. Blank is the normal state.

**Do NOT add an `@page` rule to the template.** Page geometry belongs to the renderer, which is also
the thing that knows there is a running footer; a template that sets its own margins leaves no room
for it and the footer renders nowhere at all.

> **⚠️ The payroll add-line modal asked for eight figures and kept three (fixed 2026-09-02).** It
> renders `gross`, `allowances`, `salary_tax`, `social_insurance`, `other_deductions`,
> `deduction_note` and `employer_social_insurance`; the create wrote the first, third and fourth. So
> an operator entered an allowance and a deduction, pressed Add, and got a payslip that ignored both
> — **no error, and a net figure that looked deliberate**. The employee is paid the wrong amount and
> the only evidence is a number nobody has a reason to re-derive.
>
> The fields are enumerated from `PayrollLinesRelationManager::LINE_MONEY_FIELDS` now, and a gate
> asserts that register covers everything `moneyFields()` renders — so a ninth field is carried by
> being added to the modal rather than by anyone remembering this hook. `advance_deduction` is the
> one deliberate exception: it is `->dehydrated(false)` and belongs to the **Deduct advance** act,
> which has its own gate and its own GL consequence.
>
> *(Testing note: a relation manager's HEADER action is reached with `callTableAction()`, not
> `callAction()` — the latter reports it as "not visible" even when the gate is satisfied, which
> reads exactly like an authorization failure.)*
> (`AFormThatAsksMustKeepWhatItIsToldTest`.)


## Two runs for one month could both be approved (SW-092, fixed 2026-09-02)

`Payroll::saving` carries the double-pay guard — no employee may be on two APPROVED runs for one
month at one property — and it was a plain read with **nothing serialising the writers**. Two runs
approved concurrently each see the other still `draft`, both pass, and the employee is paid twice:
salaries posted twice, and every advance installment in both runs relieved twice.

There is no contended ROW to lock — the two runs are different rows and the guard is about the SET —
so it is a **cache lock**, the same mechanism and reasoning as the monthly billing and assessment
runs. Keyed on the property and the month, because that is exactly the scope of the guard's own
query; a portfolio-wide key would serialise malls that cannot clash.

**Taken OUTSIDE the transaction, deliberately.** Acquiring it inside would leave our
consistent-read snapshot already fixed from before the other approval committed, so the guard would
still be answered from a state it had waited past.

### And the advance re-check decided from a pre-lock snapshot

The second half, and the same family. `approve()` takes `lockForUpdate()` on each `EmployeeAdvance`
the run repays and then asked `outstanding()` — which issues **plain** reads against
`employee_advance_repayments` and the approved payroll lines. *A lock serialises writers; it does not
make the guard behind it SEE them.* Under MySQL's REPEATABLE READ that answer comes from the snapshot
taken before the transaction waited, so two runs each deducting within the pre-approval outstanding
both passed and together over-repaid the loan: the advance reads zero while a real balance is still
owed, and the GL credits Employee Advances twice for money borrowed once.

`EmployeeAdvance::outstandingForUpdate()` is the locking twin — the same split, and the same reason,
as `Unit::isActivelyLeasedForUpdate()` and both payment over-allocation guards.

**And the first version of that twin was a no-op, for a reason worth writing down: a locking read
does NOT lock the rows of a nested subquery.** It was written
`->whereHas('payroll', fn ($q) => $q->where('status', 'approved'))->lockForUpdate()`, which reads as
a locking read of both tables and is not one — MySQL locks the outer `payroll_lines` and leaves the
`payrolls` rows in the subquery untouched unless the subquery says `for update` itself. That is the
worst possible split here, because `payroll_lines.advance_deduction` is **identical before and after
the other run approves** — the line is written when the run is generated — and `payrolls.status` is
the only value that moves. So the guard locked the data that cannot change and read the deciding
column from the snapshot: measured on real MySQL with two connections, it answered **0.00 where the
truth was 3,000**, byte-identical to the plain `outstanding()` it was supposed to replace. It is a
`join('payrolls', …)` now, which puts the status in the same query block.

**Neither the suite nor `LockSpy` can see that difference**, and it is worth knowing why before
trusting a similar guard. SQLite compiles `for update` to nothing; `LockSpy` compiles it to a SQL
comment on the OUTER query, and a `whereHas` still names `payrolls` in its own subquery text — so
`$spy->locked('payrolls')` is true for the broken version and the fixed one alike. What the test
pins instead is the SQL **shape**: the statement carrying `advance_deduction` must contain
`join "payrolls"` and must not contain `exists (select`.

The plain twin stays:
every list, form helper and infolist reads it on render, and taking row locks per row per page is a
cost with no writer waiting on it. It is registered in `ConcurrencyPolicy::AUTHORITATIVE_GUARDS`, so
the gate reads the method's own body and requires the locking read to be visible where the decision
is made.

**What none of this proves is that two transactions actually serialise** — that needs MySQL and two
connections (`docs/qa/scripts/race.sh`). What it proves is that the locks are taken and the guards
read under them, which is what stops the next tidy-up deleting either.

**The run row itself is the transaction's first statement.** `approveUnderLock()` opens with
`Payroll::whereKey(…)->lockForUpdate()->firstOrFail()` and returns early when the reloaded row is no
longer `draft`. That does two jobs at once: it makes a double submit of the SAME run idempotent
rather than posting its journal twice, and — because it is the transaction's first read — it is what
fixes the consistent-read snapshot at a point AFTER the cache lock was granted, so every guard behind
it sees what the run that just finished committed.

The cache lock is taken **outside** the transaction and `block(10, …)`s rather than failing fast; a
`LockTimeoutException` becomes a `DomainException` the operator reads as words
(`admin.refusals.payroll_approval_in_progress`), never a 500.

Tests: `APayrollRunCannotPayTheSameMonthTwiceTest` — the same-month refusal with its control, the
lock's key asserted through a `Cache::shouldReceive('lock')` expectation rather than by grepping the
source, the timeout refusal, idempotence under the run-row lock, a `LockSpy` run proving the advance
balance is read under the lock it just took, the SQL-shape case above, the two twins agreeing on
clean data, the over-repayment refusal, and an ordinary approval. Mutation-proved five ways.
