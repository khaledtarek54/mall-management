# HR / employees / payroll — Atriom vs Odoo

> Domain 5 of the [Atriom vs Odoo gap analysis](README.md). Atriom side grounded in
> `PayrollService`/`PayrollJournalizer`, the employee/payroll/advance models + grant/repayment/payslip
> services, and [`docs/modules/24`](../../modules/24-hr-employees.md) +
> [`docs/gap-analysis/24`](../24-hr-employees.md). The employer-social-insurance gap was re-verified
> against `PayrollJournalizer` (it credits only the withheld employee side). Egypt-localization
> specifics flagged *(verify)*. "CE" = Community, "EE" = Enterprise.

Legend: ✅ full · 🟡 partial · ❌ absent · ⏭️ out of scope.

## 1. Capability matrix

| Capability | Atriom | Odoo CE | Odoo EE | Gap note |
|---|---|---|---|---|
| Employee master / directory | ✅ | ✅ | ✅ | Parity on essentials; per-property-scoped. Odoo richer (documents/skills) — HR-admin polish. |
| Contracts (start/end, wage, terms) | ❌ | 🟡 | ✅ | **Real gap.** Atriom stores `base_salary` on the employee, no contract object. Odoo `hr_contract` is CE for the record; salary-structure link is EE. |
| Payroll run (batch, per period) | ✅ | ❌ | ✅ | Atriom has draft→approved→cancelled runs per property. Odoo runs/batches are **EE-only**. |
| Per-employee payslip | ✅ | ❌ | ✅ | Bilingual AR/EN payslip PDFs. Odoo payslips are **EE-only**. Genuine Atriom strength. |
| Salary rule / structure engine | ❌ | ❌ | ✅ | **Odoo's headline edge, but EE-gated.** Atriom is flat entry — gross/tax/insurance typed, net derived; no structures/rules/components. |
| Statutory tax-bracket automation | ❌ | ❌ | 🟡 | Atriom: tax **amount keyed**, not computed from brackets. Odoo computes via a country localization — Egypt payroll rules availability thin *(verify)*; likely custom either way. |
| Social insurance — employee withheld | 🟡 | ❌ | ✅ | Atriom withholds + posts the employee side (Cr Social Insurance Payable), but the **amount is entered**, not rate-driven. |
| **Employer social-insurance contribution** | ❌ | ❌ | 🟡 | **Verified: real Egyptian obligation, entirely missing.** Payroll posts only the withheld side; the employer share is neither expensed nor accrued — labour cost is understated. |
| Advances / loans + repayment netting | ✅ | ❌ | 🟡 | **Atriom strength.** Dedicated سلف: grant Dr Employee Advances / Cr Cash\|Bank, lock-safe over-repayment guard, derived outstanding, repayment reverses to GL. Odoo has no native CE loan; EE models it as salary attachments, not a receivable. Repayment is standalone, **not yet via payroll deduction** (Phase 3b). |
| Expense claims | ❌ | ✅ | ✅ | Atriom has none (advances are the nearest). Odoo **Expenses is CE**. |
| Time-off / leave management | ❌ | ✅ | ✅ | Absent in Atriom. Odoo **Time Off is CE**. |
| Attendance / timesheets | ❌ | ✅ | ✅ | Absent. Odoo **Attendances & Timesheets core are CE** (grid view is EE). |
| **End-of-service / gratuity** | ❌ | ❌ | 🟡 | **Real Egyptian obligation, missing** — no accrual, no settlement automation (final dues hand-keyed). Odoo via payroll localization (EE; Egypt *(verify)*). |
| Recruitment | ⏭️ | ✅ | ✅ | Out of scope for finance-first HR. Odoo **Recruitment is CE**. |
| Appraisals | ⏭️ | ❌ | ✅ | Out of scope. Odoo **Appraisals is EE**. |
| Org chart | 🟡 | ✅ | ✅ | `department_id` linkage but no manager hierarchy / visual chart. Low value for scope. |
| GL posting of payroll | ✅ | 🟡 | ✅ | Atriom posts a balanced, per-property, self-healing entry (Dr Salaries / Cr tax + insurance payable / Cr Cash\|Bank). Odoo's payroll→journal needs **EE payroll**; CE can hold the journal but nothing generates it. |

## 2. Architecture read

**Is Atriom's design sound? For its scope — an operator with dozens of staff across a few malls —
yes, and deliberately so.** The central choice is **flat computation** (gross/tax/insurance entered
per `PayrollLine`, net derived and re-derived on every write path) vs Odoo's **salary-rule engine**.
A rule engine earns its complexity at hundreds/thousands of employees with heterogeneous contracts
and country-specific automation. For ~dozens of salaried staff whose pay changes rarely, flat entry
is the pragmatic, auditable choice — and it's well-guarded: `net_paid` is model-enforced (can't
drift), the header derives from Σ lines so the GL always ties to the payslips, `approve()` rejects a
net-negative run, every money action is server-side `authorize()`d. The **payslip PDFs + GL
integration + first-class advances receivable are genuinely good** and are things Odoo cannot do
without Enterprise. **Keep these.**

**Advances→GL→repayment model.** Arguably *better-shaped than Odoo's* for this context: a dedicated
`Employee Advances` receivable (11203001, deliberately separate from tenant AR so the close tie-out
is unaffected), derived outstanding, a lock-safe over-repayment guard, and a child-source cascade so
soft-delete/restore self-heals through the windowed sweep. Odoo has no native Community loan;
Enterprise models it as a salary attachment, not a tracked receivable. The one seam the docs name:
**repayment is standalone, not yet netted through the payroll journal entry** (Phase 3b) — so an
advance repaid via a salary deduction needs two manual steps.

**What to reconsider (the honest weaknesses — two are real Egyptian statutory obligations):**
- **Employer-side social insurance is entirely absent.** Atriom withholds only the employee share;
  the employer contribution (a legal cost of employment) is neither expensed nor accrued — the books
  understate labour cost. A **correctness gap, not a feature gap.**
- **End-of-service / gratuity has no accrual.** Final settlements are hand-keyed as ordinary lines;
  there's no running liability, so the balance sheet doesn't reflect accruing severance.
- **Statutory amounts are entered, not computed** — tax and insurance are typed with no bracket/rate
  automation, so correctness depends on the keyer. A lightweight, mall-scale **rate/bracket helper**
  (not a full rule engine) would remove a class of keying error within the flat model. Also open:
  [F-90b](../24-hr-employees.md) (a per-line net can still go negative) and F-91 (a mis-keyed
  repayment is uncorrectable).

**Net:** the design is sound and appropriately scoped. Don't chase Odoo's rule engine — it's
Enterprise-gated and Egypt payroll localization is thin *(verify)*, so even on Odoo you'd likely
custom-build Egyptian rules. Instead close the statutory holes and add rate-assisted entry.

## 3. Top 5 real gaps (ranked for a mall operator)

1. **Employer social-insurance contribution** — a mandatory Egyptian employer cost Atriom neither
   expenses nor accrues, so labour cost and liabilities are understated on the books today.
2. **End-of-service / gratuity accrual** — a legally accruing severance obligation with no running
   liability or settlement automation, currently just hand-keyed at exit.
3. **Statutory tax/insurance rate automation** — amounts are typed, not derived from Egyptian
   brackets/rates (a mall-scale rate helper, not a full rule engine, would suffice).
4. **Advance repayment via payroll deduction** — the natural way staff repay سلف is a salary
   deduction, but repayment isn't yet netted inside the payroll journal entry (Phase 3b).
5. **Leave / attendance tracking** — no time-off balances or attendance, which a mall's shift-based
   security/cleaning staffing needs; both are **Odoo Community**, underscoring it as a real,
   non-exotic gap.

*Uncertainty flags: Odoo Payroll, Appraisals, and payroll-driven GL posting are **Enterprise**; Time
Off, Attendances, Timesheets (core), Expenses, Recruitment are **Community**. Egyptian payroll
localization (statutory brackets, employer contribution, gratuity as shipped rules) is
thin/unconfirmed —* (verify) *before assuming Odoo delivers Egyptian statutory payroll out of the
box; realistically it's an Enterprise licence plus custom Egyptian rules either way.*
