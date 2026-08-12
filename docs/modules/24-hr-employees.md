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
   `employees.edit`, server-side re-checked).
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

| **3c — Generate payslips from roster** | `GeneratePayrollService` (one line per active employee, gross = base salary, deductions from `PayrollSettings`) + the *Generate payslips* action + settings tab + create→edit redirect + guiding empty state | ✅ shipped |
| **4a — Salary structure** | Allowances (بدلات — itemised portion of gross) + **employer social insurance** (a company cost that posts Dr Social Insurance Expense `51110001` / Cr Social Insurance Payable, without touching net pay); settings-driven employer rate; payslip/register/journalizer expanded; GL tie-out preserved | ✅ shipped |
| **4b — Advance repayment via payroll** | A payslip line repays one of the employee's outstanding advances: the installment reduces net pay and the payroll entry credits Employee Advances `11203001` (closing the سلف loop). `EmployeeAdvance::outstanding()` derives to include approved-run installments; lock-safe over-repay re-check at approval; cancel restores the balance | ✅ shipped |
| **4c — Ad-hoc / penalty deductions (خصومات)** | A payslip line carries an `other_deductions` amount (+ note) for penalties / absence / damages; reduces net pay and credits a holding liability **Employee Deductions Payable `21602001`** (accountant reclassifies via mapping) | ✅ shipped |

**Future (Phase 4d+, not built):** the **progressive Egyptian income-tax bracket engine**
(personal exemption + brackets, replacing the flat `salary_tax_rate` — gated on the accountant's
confirmed brackets); structured basic-first entry (build gross up from basic + allowances); and
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

Rates are settings-driven (`PayrollSettings::employer_social_insurance_rate`, default 0 — the
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
| `salary_tax` | `gross × PayrollSettings::salary_tax_rate` (0 by default) |
| `social_insurance` | `gross × PayrollSettings::social_insurance_rate` (0 by default) |

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
