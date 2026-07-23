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
   the lines are frozen (mutation actions hidden + server-side `abort_unless(runIsEditable)`).
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

**Future (Phase 3b, not built):** richer payslip components — allowances (بدلات) / generic
deductions and **advance repayment via payroll deduction** — need the payroll journalizer to
expand (e.g. Cr Employee Advances within the payroll entry), so they're deferred to keep
Phase 3 additive and the GL/tie-out untouched.

---

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
