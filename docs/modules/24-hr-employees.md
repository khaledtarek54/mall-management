# Module 24 — HR / Employees (الموارد البشرية)

> **Status: Phase 1 shipped (employee master).** A per-property register of the
> operator's own staff + a property-scoped Filament `EmployeeResource`, `employees.*`
> RBAC (owned by the HR role), the `employees` module flag, and a terminate action.
> Builds on the existing GL-posting **Payroll** (module 21, batch run). Advances/loans
> (سلف) and per-employee payslips are the remaining phases. Delivers the discovery
> backlog item **HR-2** (employee master + advances/loans + payslips).

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

---

## 2. Business rules

1. **Property-scoped** (`asset_id`), like units / fixed assets — scoped in Filament via
   `BypassesScopingOnAll` + `tenantOwnershipRelationshipName='asset'`; create/edit
   re-validate the submitted `asset_id` against `visibleAssetIds()`
   (`EmployeeResource::assertAssetInScope`), closing the All-Properties tamper hole.
2. **Unique staff code per property** (DB composite unique + form rule).
3. **NOT-NULL money** — blank `base_salary` coerces to 0 in the model.
4. **Terminate** flips `status → terminated` + stamps `terminated_on` (gated on
   `employees.edit`, server-side re-checked). Termination is a status flip (no GL in
   Phase 1).

---

## 3. RBAC & module flag

- Permissions `employees.view/create/edit/delete` (delete = super_admin only, project-wide).
  Granted to the **`hr`** role (view/create/edit); **manager** (all view/create/edit) and
  **viewer** (all `.view`) inherit via the flat list; **accounting** gets `employees.view`
  (needs staff for payroll/advances).
- Module flag **`employees`** (`Modules::KEYS` + `ModulesSettings`), on by default,
  toggleable from /admin/settings → Modules.

---

## 4. Roadmap

| Phase | Scope | Status |
|-------|-------|--------|
| **1 — Employee master** | `Employee` model + migration, property-scoped `EmployeeResource` (form + table + terminate), `employees.*` RBAC, module flag, tests | ✅ shipped |
| **2 — Advances / loans (سلف)** | `EmployeeAdvance` (grant + repayments) posting to the GL (Dr Employee Advances receivable / Cr Cash\|Bank on grant; reverse on repayment) + a dedicated chart account + journalizer + sweep | ⏳ next |
| **3 — Per-employee payslips** | `PayrollLine` (per-employee breakdown of a payroll run: gross / allowances / deductions / advance repayment / net; run total = Σ lines) + bilingual payslip PDF | ⏳ |

---

## 5. Tests

`tests/Feature/Resources/EmployeeResourceTest.php` — `employees.*` RBAC gating (hr owns
it; accounting/viewer read-only; leasing none), module-off hiding, property scoping, the
unique staff-code-per-property rule, the terminate action (+ read-only guard), and the
`assertAssetInScope` write guard.

**Related:** 21 General Ledger (payroll posting + future advances GL), 14 Departments
(org units), 18 RBAC (the `hr` role), 01 Properties (asset scope).
