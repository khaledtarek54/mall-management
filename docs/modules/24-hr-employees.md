# Module 24 — HR / Employees (الموارد البشرية)

> **Status: Phase 2 shipped (advances/loans → GL).** A per-property employee register +
> a property-scoped Filament `EmployeeResource` (terminate action) + `employees.*` RBAC +
> the `employees` module flag, PLUS **employee advances & loans (سلف)** that post to the
> double-entry ledger (grant Dr Employee Advances / Cr Cash|Bank; repayment reverses),
> with derived outstanding + a lock-safe over-repayment guard. Builds on the existing
> GL-posting **Payroll** (module 21). Per-employee payslips are the remaining phase.
> Delivers the discovery backlog item **HR-2** (employee master + advances/loans + payslips).

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
6. **NOT-NULL money** — blank `base_salary` / advance `amount` coerce to 0 in the models.

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
| **3 — Per-employee payslips** | `PayrollLine` (per-employee breakdown of a payroll run: gross / allowances / deductions / advance repayment / net; run total = Σ lines) + bilingual payslip PDF | ⏳ next |

---

## 5. Tests

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

**Related:** 21 General Ledger (payroll posting + advances GL), 14 Departments
(org units), 18 RBAC (the `hr` role), 01 Properties (asset scope).
