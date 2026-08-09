# Leases

> A lease is a binding occupancy contract between a tenant and a unit (or units) with linked charges (rent + service fees), escalation terms, optional percentage rent, and a multi-state lifecycle from draft through expiry/renewal/termination.

> **⚠️ The rent is a SCHEDULE now (2026-08-08).** A benchmark against Yardi Voyager Commercial
> found one structural defect here: this module stored the lease's *current state* and mutated it.
> `LeaseRentChangeService` overwrote `Charge.amount` and `RentEscalationService` overwrote it again
> every year, so the system knew what the rent *is* and had no structured memory of what it *was*.
>
> **Phase 1 inverted the write path.** A rent change now **closes the row in force the day before
> the new one starts and opens the next** — [`ChargeScheduleService`](../../app/Services/ChargeScheduleService.php)
> is the one place that happens, and `charges.origin` records whether a row was seeded, typed,
> escalated or carried on renewal. Consequences you must know before touching this module:
>
> - **A charge type can have MANY rows.** Anything that assumed one row per `(lease, type)` is
>   wrong. `LeaseRenewalService` carried *every* active row onto the renewal — with a schedule that
>   is three overlapping rent rows billing the tenant three times a month; it now carries only the
>   row in force. `MarketingLevyService` had the same assumption baked into an `updateOrCreate`.
> - **Exactly one recurring row per type may cover a billing period.** Two is an overlapping
>   schedule, and `MonthlyBillingService::assertScheduleUnambiguous()` refuses the lease loudly
>   rather than putting two rent lines on one invoice. One-off charges are exempt.
> - **Effective dates snap to the billing month.** The engine bills one amount per type per month,
>   so a mid-month change starts on the 1st — which also reproduces the old overwrite behaviour
>   exactly. Mid-month proration of a rent change is deliberately future work.
> - **Billing a past month now bills what was in force THEN**, not today's amount. That is a
>   behaviour change, and it is the point.
> - `Lease::base_rent_monthly` still tracks the rent in force; nothing downstream moved.
>
> **Fit-out grace is per-charge now (LS-05).** `fit_out_scope` decides what the grace abates:
> `rent_only` (**the new default** — base rent free, service charge and every other reimbursement
> still payable; the industry standard, "net abatement") or `gross` (the whole invoice, the
> 2026-07-19 operator decision). **The column default is `gross` and the MODEL default is
> `rent_only`** — that split is the migration: existing leases keep the grace they were actually
> billed under, new leases get the standard. `Lease::firstBillableMonth()` derives from the scope,
> so `periodInFitOut()`, the quarterly cycle anchor and the "unbilled leases" card all follow
> without their own copy of the rule. Use `inFitOutWindow()` for "is the rent free" and
> `periodInFitOut()` for "does nothing bill" — they are different questions under net abatement.
>
> **The whole term is written at signing (LS-01).** A lease created with a `fixed_percent`
> escalation gets its entire rent ladder up front — a five-year 7% lease is five rent rows the day
> it is signed, so the mall's future revenue is a recorded fact and an operator can review an
> increase before it bills. Renewals project their own ladder. **CPI is not projected** (no index
> feed; inventing the number would be inventing data — the same reason the sweep skips it), and
> `leases:apply-escalations` still runs each anniversary: it recomputes the same amount, finds it
> already in force, adds no row, and advances `base_rent_monthly` + `next_escalation_date`. A
> projected lease and a swept one converge on identical rows.
>
> **Where you SEE it:** the **Charge schedule** panel on the lease
> ([`ChargeScheduleRelationManager`](../../app/Filament/Admin/RelationManagers/ChargeScheduleRelationManager.php))
> — every row, its date range, whether it is billing now / scheduled / ended, and why it exists.
> The heading says what is billing today and when it next changes. **Read-only on purpose:** rent
> changes go through the Change Rent action so the schedule is written by one service.
>
> **Leases signed before projection existed** carry a single open-ended rent row and no ladder.
> `php artisan atriom:project-lease-schedules` backfills them (dry-run by default, `--commit` to
> write); it anchors on each lease's own `next_escalation_date`, so a mid-term lease gets its steps
> on the contract's dates and an already-billed month is never re-dated. Until a lease is
> backfilled its Charge schedule says so explicitly rather than claiming no increase is coming.
>
> **Known wart — `charges.type` is a DB-level ENUM**
> (`enum('base_rent','service_charge','utility','parking','percentage_rent','marketing','other')`),
> which the project convention forbids (string + validation, so a new type needs no migration).
> It has a visible side effect: **MySQL orders an ENUM by its DECLARED index, not alphabetically**,
> so `ORDER BY type` yields base_rent → service_charge → utility → parking → percentage_rent →
> marketing → other, which looks arbitrary on screen. The charge-schedule table therefore sorts by
> date, not type. Converting the column to a string is a small migration and would also make the
> ordering sane; it is not urgent because nothing depends on the enum ordering.
>
> Full analysis and the remaining phases: [`docs/benchmarks/yardi/`](../benchmarks/yardi/README.md).
> **Still open here:** no lease options / notice-window alerts, no trailing proration, holdover is
> alerted but never billed. Note `LeaseCreationService` hard-codes `escalation_type =
> 'fixed_percent'` and ignores the caller's value — a CPI lease can only be made by editing one
> after creation.

## 1. Purpose & business context

Leases model the core revenue instrument of Egyptian mall operations. They bind tenants to units (retail spaces) for a fixed term, specify monthly rent and service charges with embedded VAT rules, enable percentage-of-sales rent triggers, and track the full lifecycle: draft negotiation → active occupancy → renewal or expiry → termination. A tenant may hold multiple single-unit leases across a mall; a single lease may span multiple units (multi-unit lease). Operators (Eltizam department) manage creation, renewal, termination, and rent escalation; owners (Jawad) and the accounting department oversee invoicing and payment via the linked Charge and Invoice modules.

## 2. Domain model

| Table | Model | Key Columns | Meaning |
|-------|-------|-----------|---------|
| `leases` | `Lease` | `reference` (string, unique) | LSE-{ASSET_CODE}-{YEAR}-{SEQ_NUM}, e.g. "LSE-HW-2026-0001". Generated by `Lease::generateReference()`. |
| | | `unit_id` (FK → units, NOT NULL) | Foreign key to the master unit; denormalized pointer to `units.id` for fast lookups and backward compatibility. Always mirrors the `is_master=true` row in `lease_unit` pivot. Scoped by `ScopesViaProperty` trait in Filament. |
| | | `tenant_id` (FK → tenants, NOT NULL, RESTRICT) | Tenant occupying the lease. Cannot be orphaned. |
| | | `previous_lease_id` (FK → leases, nullable, NULL ON DELETE) | Points to the prior lease if this is a renewal. Enables the lease chain: original → renewal → next renewal. |
| | | `status` (enum) | One of: `draft`, `pending_approval`, `active`, `expired`, `renewed`, `terminated`, `cancelled`. Default `draft`. Drives unit occupancy projection (see § 4). |
| | | `commencement_date` (date) | Start of lease term. |
| | | `expiry_date` (date) | End of lease term (inclusive). Calculated on creation: `commencement + term_months - 1 day`. |
| | | `expiry_reminder_notified_at` (timestamp, nullable) | Idempotency stamp for the tenant lease-expiry reminder (`leases:remind-expiring`); NULL until the tenant has been reminded once for this lease's expiry. |
| | | `term_months` (unsigned small int) | Contract duration in months (1–120). |
| | | `base_rent_monthly` (decimal 12,2) | Monthly rent amount (EGP), before VAT. Core revenue stream. Read-only on edit; changed via `LeaseRentChangeService::apply()` to keep `Charge.amount` synchronized. |
| | | `service_charge_monthly` (decimal 12,2) | Monthly service charge (EGP), VAT-applicable (14% in Egypt). Default 0. |
| | | `has_marketing_levy` (boolean, NOT NULL, default **true**) | Whether the tenant pays the marketing-fund contribution (a `marketing` charge = % of base rent, billed monthly). Default true preserves today's behaviour; turn off for tenants who negotiated out. Carried forward on renewal. |
| | | `marketing_levy_rate` (decimal 5,2, nullable) | Per-lease override of the marketing levy %. Blank = the mall default (`MarketingSettings`, 5%). Carried forward on renewal. |
| | | `fit_out_months` (unsigned tinyint, NOT NULL, default 0) | Rent-free fit-out grace: for this many whole months from the commencement month, the monthly billing run suppresses the **entire** invoice (rent + service + CAM + marketing levy — full grace, operator decision 2026-07-19). Does **not** carry forward on renewal. |
| | | `billing_frequency` (enum `monthly`\|`quarterly`\|`semiannual`\|`annual`, NOT NULL, default `monthly`) | How often the lease is invoiced. Quarterly/annual leases pay **in advance**: one invoice per cycle covering the whole cycle (each monthly charge × months-in-cycle; rent + service + levy together), on cycle-start months only. Cycles are anchored to the **first billable month** (commencement + fit-out); every cycle is a full N months. **Carries forward** on renewal. |
| | | `currency` (string 3, default 'EGP') | ISO 4217 code (currently always EGP in Egypt context). |
| | | `security_deposit` (decimal 12,2, default 0) | One-time security amount (typically 3× monthly rent). |
| | | `security_deposit_received` (boolean, NOT NULL, default false) | Whether the deposit has been collected. |
| | | `escalation_rate` (decimal 5,2) | Annual rent-increase percentage (0–100, e.g., 7 → 7%). |
| | | `escalation_type` (enum) | One of: `none`, `fixed_percent` (escalation_rate %, **auto-applied**), `cpi` (inflation-indexed — **skipped by the sweep until an index feed exists**; no number is invented). Default `none`. |
| | | `next_escalation_date` (date, nullable) | Next scheduled escalation. **Armed automatically on create** by `Lease::creating` = `commencement + 1yr` whenever escalation is configured (`fixed_percent`/`cpi`, rate > 0) — converged in the model so the wizard, standard form, and renewal all set it consistently (before this, NO creation path populated it, so the sweep never fired for a real lease). The daily `leases:apply-escalations` sweep (`RentEscalationService`) applies a due `fixed_percent` increase through `LeaseRentChangeService` and rolls this forward a year — idempotent + lock-safe. `none`/rate-0 leases stay null (never escalate). |
| | | `has_percentage_rent` (boolean, NOT NULL, default false) | Whether sales-based rent (pct rent) applies. |
| | | `percentage_rent_threshold` (decimal 12,2, nullable) | Sales floor triggering pct rent (artificial breakpoint). E.g., 100,000 EGP/month → charge on sales above this. |
| | | `percentage_rent_rate` (decimal 5,2, nullable) | Pct rent rate (0–100, e.g., 8 → 8% of sales above threshold). |
| | | `percentage_rent_calculation_type` (enum, nullable) | `artificial` (threshold-based) or `natural_breakpoint` (% of sales minus monthly base rent, floored at 0). Defaults to `artificial` if null when calculating. |
| | | `billing_day` (date, nullable) | Preferred day of month to invoice (reserved for future billing logic). |
| | | `payment_terms_days` (unsigned small int, default 7) | Invoice payment due window (7 days = due 1 week after issue). |
| | | `notes` (text, nullable) | Audit trail: appended with termination/rent-change stamps and reasons. |
| | | `metadata` (JSON, nullable) | Flexible key-value store for future integrations. |
| `lease_unit` | (pivot) | `lease_id`, `unit_id` | Links leases to units; supports multi-unit leases. Each lease has ≥1 pivot rows (one per unit). |
| | | `is_master` (boolean, default false) | Exactly one `is_master=true` per lease. The master is the "primary" unit and is mirrored to `leases.unit_id`. |

**Relationships:**
- `Lease::unit()` → `belongsTo(Unit::class)` (the master via `unit_id`)
- `Lease::masterUnit()` → alias to `unit()` (semantic clarity)
- `Lease::units()` → `belongsToMany(Unit::class, 'lease_unit')` with pivot `is_master` (all units including master)
- `Lease::tenant()` → `belongsTo(Tenant::class)`
- `Lease::previousLease()` → `belongsTo(Lease::class, 'previous_lease_id')` (points backward)
- `Lease::renewals()` → `hasMany(Lease::class, 'previous_lease_id')` (points forward to all renewals)
- `Lease::charges()` → `hasMany(Charge::class)` (rent, service charge, plus any custom charges)
- `Lease::invoices()` → `hasMany(Invoice::class)` (generated monthly bills)
- `Lease::camAllocations()` → `hasMany(CamAllocation::class)` (CAM expense allocations)
- `Lease::salesDeclarations()` → `hasMany(TenantSalesDeclaration::class)` (sales-based rent triggers)

## 3. Business rules & invariants

| Rule | Enforcement | Test(s) |
|------|-------------|---------|
| **Unit occupancy is a lease-status projection.** Active lease → occupied. Draft/pending/renewed → reserved. Expired/terminated/cancelled → vacant. Maintenance overrides auto-projection. | `Unit::recomputeStatus()` (called by `LeaseObserver` on Lease create/update). | `LeaseObserverTest::*`, `MultiUnitLeaseDataScenarioTest::projects_*` |
| **Master unit is authoritative & mirrored.** `leases.unit_id` always = the `is_master=true` unit in the `lease_unit` pivot. Single-unit code paths rely on this. | `LeaseObserver::ensureMasterPivot()` syncs the pivot; `Lease::syncUnits()` updates both pivot and `unit_id`. | `MultiUnitLeaseTest::mirrors_single_unit`, `demotes_the_old_master_and_mirrors_*` |
| **Only one active lease per unit at a time.** Prevents double-booking. | Filament form validation + guard in `LeaseCreationService::create()`. | `LeaseForm::unit_id` rule checks uniqueness on status='active'. |
| **Rent charges are VAT-exempt; service charges carry 14% VAT.** Egyptian tax rule. | `LeaseCreationService::seedStandardCharges()` creates: base_rent with `vat_applicable=false`, service with `vat_applicable=true, vat_rate=Vat::standardRate()` (settings-driven). | `LeaseLifecycleScenarioTest::creation_seeds_VAT_exempt_rent_*` |
| **Lease.base_rent_monthly & Charge.amount stay synchronized.** Prevents billing-amount drift between UI display and actual invoice generation. | `LeaseRentChangeService::apply()` updates both Lease field AND the matching Charge row(s). Form edit disables rent fields; only the service method changes them. | `LeaseRentChangeService` tests; `LeaseLifecycleScenarioTest::escalation_raises_base_rent_*` |
| **Terminal leases are immutable.** Once `terminated`/`expired`/`cancelled`/`renewed`, a lease's commercial + state fields can't change (only notes/metadata + soft-delete/restore). Stops a terminated lease being re-opened and re-priced via the Edit form. | `Lease::updating` blocks any dirty field outside the allow-list once the ORIGINAL status is terminal (the transition INTO terminal is allowed); `EditLease` halts with a notice. | `Module04LeaseIntegrityTest` |
| **Renewal carries forward the full unit set.** Multi-unit lease renewal does NOT drop additional units. | `LeaseRenewalService::renew()` calls `syncUnits()` with the original's full unit set. | `MultiUnitLeaseRenewalTest::renews_a_multi_unit_lease_carrying_*` |
| **Percentage rent threshold variants:** <br> - **Artificial:** max(0, sales - threshold) × rate. <br> - **Natural breakpoint:** max(0, sales × rate - base_rent). | Calculated at invoice time by `PercentageRentCalculationService`. | `BillingMathTest::test_percentage_rent_artificial_breakpoint`, `test_percentage_rent_natural_breakpoint` |
| **Termination deactivates charges & optionally cancels unpaid invoices.** Prevents recurring billing post-termination. | `LeaseTerminationService::terminate()` sets `Charge.is_active=false` and optionally cancels fully-unpaid invoices (status → 'cancelled', balance → 0). Partially-paid invoices require explicit credit-note reversal. | `LeaseTerminationService` tests |
| **Security deposit is non-binding for invoicing.** It is a field on Lease, NOT automatically deducted from tenant balances; operators issue credit notes if collected. | Manually tracked in notes; `security_deposit_received` flag aids reporting. | Domain rule; design choice for audit clarity. |

## 4. Lifecycle / state machine

| Status | Entry point | Allowed transitions | Exit rule / immutability |
|--------|-------------|-------------------|--------------------------|
| **draft** | New lease created in admin or via `LeaseCreationService`. | → `pending_approval`, `active`, `cancelled` | Discarded if not activated; reserved unit if present. |
| **pending_approval** | Operator upgrades a draft lease pending review. | → `active`, `cancelled` | Awaits approval before activation; reserved unit. |
| **active** | Lease commences (explicit status set on creation or via promotion). | → `renewed` (renewal creates new lease), `terminated`, `expired`, `cancelled` | Unit is occupied. Invoices generate. Charges are active. Only one active lease per unit. |
| **renewed** | Triggered when `LeaseRenewalService::renew()` marks original as 'renewed'. | (terminal for original) | Original lease is now closed; the renewal is a new 'active' lease linked via `previous_lease_id`. Unit is reserved (because the renewal—a new active lease—projects it to occupied). |
| **expired** | Manual mark-as-expired or automated task (future). | (terminal) | Unit becomes vacant (unless another non-terminal lease on it). Invoicing stops. |
| **terminated** | `LeaseTerminationService::terminate()` on active or pending lease. | (terminal) | Charges deactivated. Unit becomes vacant (unless another non-terminal lease). Invoices optionally cancelled. |
| **cancelled** | Operator cancels a draft or pending lease. | (terminal) | Unit reverts to vacant (if no other non-terminal leases). |

**Projection rules (Unit status):**
```
foreach lease in unit.allLeases():
  if lease.status == 'active':
    → occupied (STOP; active takes precedence)
  elif lease.status in ['draft', 'pending_approval', 'renewed']:
    → reserved (CONTINUE; check if any active)
  else:
    → vacant (CONTINUE; ignore expired/terminated/cancelled)
```

**Notes:**
- Only `active` status produces occupied units; renewal status is reserved (the new lease is active, not the old one).
- `maintenance` override on Unit prevents any auto-recomputation until manually cleared.
- Lease observers fire on create/update to recompute all attached units (via pivot).

## 5. Services, jobs & scheduled commands

### LeaseCreationService

**Signature:** `LeaseCreationService::create(array $payload): Lease`

**Idempotency:** Not idempotent — creates a new Lease row and seeded Charges on each call.

**Transaction:** Yes, atomic.

**Locking:** No explicit locking; guard on active-lease uniqueness.

**When it runs:** Called by Filament `CreateLease` page or programmatically.

**Behavior:**
1. Validates tenant mode (existing or create new).
2. Checks for existing active lease on the unit (throws ValidationException if found).
3. Generates unique lease reference (asset code + year + sequence).
4. Computes `expiry_date` as `commencement + term_months - 1 day`.
5. Creates Lease row with status='active' (or as supplied).
6. Seeds two standard Charges: base_rent (VAT-exempt) and service_charge (VAT at the standard rate — `TaxSettings::vat_standard_rate`, 14% today).

**Related:** `LeaseCreationService::seedStandardCharges()` (static) — idempotent seed of rent + service-charge pair; skips if Charges already exist; used by CreateLease page afterCreate.

---

### LeaseRenewalService

**Signature:** `LeaseRenewalService::renew(Lease $original, array $data): Lease`

**Idempotency:** Not idempotent — creates new Lease, marks original as 'renewed'.

**Transaction:** Yes, atomic.

**Locking:** No explicit locking; guards original must be status='active'.

**When it runs:** Called by Filament bulk action or programmatically.

**Behavior:**
1. Validates original lease status is 'active'; throws InvalidArgumentException if not.
2. Parses new term months, rent, service charge (defaults to original if omitted).
3. Computes commencement (defaults to day after original expiry) and new expiry.
4. Creates new Lease row linked via `previous_lease_id → original.id`, with status='active'.
5. Syncs all units from original (including additional units): `syncUnits()` with master preserved.
6. Duplicates all Charges from original, updating base_rent and service_charge amounts to new values.
7. Marks original as status='renewed'.

**Critical fix:** Carries full unit set (not just master); regression test in `MultiUnitLeaseRenewalTest`.

---

### LeaseTerminationService

**Signature:** `LeaseTerminationService::terminate(Lease $lease, array $data): Lease`

**Idempotency:** Not idempotent — updates lease and deactivates charges.

**Transaction:** Yes, atomic.

**Locking:** No explicit locking; guards lease must be status='active' or 'pending_approval'.

**When it runs:** Called by Filament edit page action or programmatically.

**Behavior:**
1. Validates lease is active or pending; throws InvalidArgumentException if not.
2. Parses termination_date (defaults to today), reason, and cancel_open_invoices flag.
3. Updates Lease: status='terminated', expiry_date=termination_date, appends reason to notes.
4. Deactivates all Charges: is_active=false, end_date=termination_date (stops monthly billing).
5. Optionally cancels unpaid invoices (status in [draft, issued, partially_paid, overdue], balance > 0, paid_amount = 0). Sets status='cancelled', balance=0.
   - **Important:** Partially-paid invoices are NOT cancelled (would orphan paid_amount); operator must issue credit notes.

---

### LeaseRentChangeService

**Signature:** `LeaseRentChangeService::apply(Lease $lease, array $data): Lease`

**Idempotency:** Not idempotent — updates Lease and Charge rows.

**Transaction:** Yes, atomic.

**Locking:** No explicit locking; guards lease must be status='active' or 'pending_approval'.

**When it runs:** Called by Filament edit page custom action (not the standard edit form, which disables rent fields).

**Behavior:**
1. Validates lease is active or pending; throws InvalidArgumentException if not.
2. Parses new base_rent_monthly and optionally new service_charge_monthly; validates ≥ 0.
3. Updates Lease fields and appends reason stamp to notes.
4. Syncs the most-recent active Charge of type 'base_rent': updates amount or creates if missing.
5. If service_charge provided: syncs matching Charge (creates only if amount > 0).

**Why a dedicated service:** Form edit disables rent fields to prevent silent Charge drift. This service keeps Lease and Charge.amount in sync for monthly billing consistency (audit M04 F-20 / D-13).

---

### LeaseObserver

**Fires on:** `created()`, `updated()`.

**Behavior:**
- **created():** Calls `ensureMasterPivot()` (mirrors unit_id into lease_unit with is_master=true) and `recomputeUnits()` (re-project all attached units).
- **updated():** If status or unit_id changed, calls `ensureMasterPivot()` and `recomputeUnits()`. No-op if only other fields changed.

**Idempotent:** Yes; re-applying the same projection is safe.

### Scheduled: lease-expiry reminder (`leases:remind-expiring`)

Daily command (07:00) that reminds the tenant when an **active** lease's `expiry_date` falls within `billing.lease_expiry_reminder_days` (default 90) — email + in-app bell + mobile push, nudging renewal. Idempotent via `leases.expiry_reminder_notified_at` (one reminder per lease; a renewal is a new lease row, so it reminds for its own expiry). Same lock+re-check pattern as the overdue scans. See [19-notifications-scans.md](19-notifications-scans.md) for the notification + `LeaseExpiryApproachingNotification`.

---

## 6. Filament resources & key fields

### LeaseResource

**Location:** `/app/Filament/Admin/Resources/Leases/LeaseResource.php`

**Permission scope:** `leases.*` (view, create, edit, delete, terminate, renew, generate_invoice).

**Tenant scoping:** Via `ScopesViaProperty::tenantScopeRelation()` → `unit` (filters leases by asset of current property).

**Navigation:** Leasing group, sort=4, icon=DocumentText.

**Key pages:**
- `ListLeases` — table with status filters, tenant/unit dropdowns, import/export.
- `CreateLease` — full form (incl. additional_unit_ids multi-select, charges not in form).
- `EditLease` — rent fields read-only; additional_unit_ids prefilled; custom "Generate Invoice" and "Change Rent" actions.

---

### LeaseForm (Schemas/LeaseForm.php)

**Tabbed, not scrolled (2026-08-08).** Thirty fields across six concerns is a scroll, not a form, so
the sections below are now **tabs** — one concern per screen (operator directive; standard recorded
as UX-13 in [the UI/UX benchmark](../benchmarks/yardi/08-yardi-ui-ux.md)). Notes and Documents are
merged into one tab; `persistTabInQueryString()` lets a link point at a tab.

Tabs are built with **`App\Support\FormTab::make(label, [...])`, never a bare `Tab::make()`** —
`FormTab` adds a danger badge counting the validation errors *inside that tab*, because Filament
v4.11.8 has no error indicator on `Tabs` and a required field left blank on a tab you are not
looking at would otherwise refuse the form with nothing visible to fix. The count is derived from
the tab's own fields at render time, so it cannot drift from what the tab contains. Tests:
`tests/Feature/Regression/FormTabErrorBadgeTest.php`.

**Tabs:**

1. **Lease Details** (3 cols)
   - `reference` (TextInput, disabled, dehydrated) — auto-generated, read-only.
   - `unit_id` (Select, live, required) — master unit; filters to non-occupied/non-reserved unless `show_occupied_units` toggle. Validation rule prevents active-lease conflicts.
   - `additional_unit_ids` (Select, multiple, dehydrated=false) — non-master units for multi-unit leases; dehydrated=false (processed in `afterCreate()` / `afterSave()`).
   - `tenant_id` (Select, required, searchable, creatable inline) — with quick-create form (name, phone, email).
   - `status` (Select) — draft, pending_approval, active, etc.
   - `show_occupied_units` (Toggle, live, dehydrated=false) — toggles unit dropdown visibility.

2. **Term** (3 cols)
   - `commencement_date` (DatePicker, required).
   - `term_months` (TextInput, numeric, 1–120, default 36).
   - `expiry_date` (DatePicker, required).

3. **Financial Terms** (3 cols)
   - `base_rent_monthly` (TextInput, numeric, ≥0, required; disabled on edit, dehydrated) — read-only on edit to enforce use of LeaseRentChangeService.
   - `service_charge_monthly` (TextInput, numeric, ≥0; disabled on edit, dehydrated) — helper text on edit warns "use Change Rent action".
   - `has_marketing_levy` (Toggle, live, default true) — whether the marketing levy is billed to this tenant. `EditLease::afterSave()` re-syncs the `marketing` charge via `MarketingLevyService::createLevyCharge()` so a toggle change takes effect on the next run.
   - `marketing_levy_rate` (TextInput, numeric, 0–100, suffix '%', visible if has_marketing_levy) — per-lease rate override; placeholder shows the mall default; blank = default.
   - `fit_out_months` (TextInput, integer, 0–24, suffix 'months') — rent-free fit-out grace; 0 = none; a blank field coerces to 0 (NOT-NULL). The billing gate lives on the model: `Lease::periodInFitOut()` / `firstBillableMonth()`, shared by `MonthlyBillingService` and the ActionRequired "unbilled leases" card (so a lease in grace is neither billed nor flagged).
   - `billing_frequency` (Select: monthly / quarterly / semiannual / annual, default monthly) — the invoicing cadence. The cadence rule lives on the model: `Lease::billingCycleMonths()` (1/3/6/12) and `isBillingCycleStart()` (commencement-anchored, post-fit-out), used by `MonthlyBillingService` (bill the whole cycle on cycle-start months) and the "unbilled leases" card (don't nag off-cycle months). A manual "Generate Invoice" for an off-cycle month returns reason `off_cycle` with a clear notice.
   - `security_deposit` (TextInput, numeric, ≥0).
   - `escalation_rate` (TextInput, numeric, 0–100, default 7, suffix '%').
   - `escalation_type` (Select) — none, fixed_percent, cpi; default fixed_percent.
   - `payment_terms_days` (TextInput, numeric, default 7, suffix ' days').
   - `security_deposit_received` (Toggle, column full).

4. **Percentage Rent** (3 cols, collapsed, collapsible)
   - `has_percentage_rent` (Toggle, live).
   - `percentage_rent_calculation_type` (Select, visible if has_percentage_rent) — artificial, natural_breakpoint; default artificial.
   - `percentage_rent_threshold` (TextInput, numeric, ≥0, prefix 'EGP', visible if has_percentage_rent).
   - `percentage_rent_rate` (TextInput, numeric, 0–100, suffix '%', visible if has_percentage_rent).

5. **Notes** (collapsed)
   - `notes` (Textarea, 3 rows).

6. **Documents** (collapsible)
   - `documents` (SpatieMediaLibraryFileUpload, multiple, PDF/image/Word, max 10 MB, collection='documents').

---

### CreateLease page

**Behavior:**
- Standard Filament form creates Lease via Eloquent.
- `afterCreate()` hook:
  - Seeds standard charges via `LeaseCreationService::seedStandardCharges()`.
  - Syncs additional units via `Lease::syncUnits()` if `additional_unit_ids` is non-empty.

---

### EditLease page

**Behavior:**
- `mutateFormDataBeforeFill()` prefills `additional_unit_ids` from the pivot (non-master units).
- `afterSave()` syncs the full unit set (master + additional) via `syncUnits()`.

**Custom actions:**
- **Generate Invoice** (action, visible if status='active'): Modal schema collects period (month-picker) and prorate flag, calls `MonthlyBillingService::generateForLease()`.
- **Change Rent** (action, visible if status='active'): Modal collects new base_rent, optional new service_charge, and reason; calls `LeaseRentChangeService::apply()`.

---

### LeasesTable (Tables/LeasesTable.php)

**Columns:**
- `reference` (copyable, mono, xs font).
- `unit.code` (badge, gray; description lists additional units: "+ A-02, A-03").
- `tenant.name` (bold).
- `base_rent_monthly` (money EGP, right-aligned, sortable).
- `commencement_date`, `expiry_date` (d/m/Y, sortable; expiry color-coded: red <30d, orange <90d).
- `status` (badge, colored: green=active, warning=pending, info=renewed, danger=terminated/cancelled, gray=other).

**Filters:**
- Status, tenant, unit (relationship dropdowns).
- Commencement/expiry date ranges.
- Trash (soft-delete filter).

**Bulk actions:**
- Export (LeaseExporter).
- Delete, Force Delete, Restore (soft-delete actions; only super_admin).

**Inline actions:**
- Edit, Delete (standard Filament).

---

## 7. Notifications & integrations

**Invoice notifications:** When an invoice is issued from a lease's charges, `InvoiceIssuedNotification` is sent to the tenant (email + WhatsApp).

**Sales declaration notifications:** When a tenant submits a sales declaration, `SalesDeclarationSubmittedNotification` alerts accounting.

**ETA integration:** Invoices linked to leases are submitted to the Egyptian Tax Authority (ETA) via `EtaJsonBuilder` / `EtaIntegrationService`.

**Monthly billing:** `MonthlyBillingService::generateForLease()` creates invoices for active leases' charges on a scheduled run (RunMonthlyBillingCommand) or manually via the EditLease action.

**CAM allocations:** `CamAllocation::allocateTenant()` computes service-charge allocations per lease-unit for CAM reconciliation.

---

## 8. Extension points — how to change/extend SAFELY

### Adding a new lease-level field (e.g., tenant_contact_override)

1. **Schema:** Add column to `create_leases_table` migration or new migration.
2. **Model:** Add to `Lease::$fillable` and `$casts` (if date/decimal/boolean).
3. **Form:** Add input to `LeaseForm::configure()` in the appropriate section.
4. **Validation:** Add rules in the field definition or in a custom Request class if complex.
5. **Tests:** Write a scenario test in `tests/Feature/Scenarios/LeaseLifecycleScenarioTest.php` or a unit test in `tests/Feature/Models/LeaseTest.php`.
6. **Do NOT:** Manually edit rent fields on the Lease record via a standard form — use `LeaseRentChangeService::apply()` to keep Charges in sync.

### Adding a new lease state (e.g., 'on_hold')

1. **Migration:** Update the status enum in `create_leases_table` migration.
2. **Model:** No code change needed (enum is auto-recognized).
3. **Form:** Update the status Select options in `LeaseForm`.
4. **Unit projection:** Update `Unit::recomputeStatus()` match logic if the new status should map to reserved/occupied/vacant differently.
5. **Observers:** Check `LeaseObserver::updated()` to ensure status transitions fire recomputation as needed.
6. **Tests:** Add scenario in `LeaseObserverTest` + `LeaseLifecycleScenarioTest`.
7. **Permissions:** Add new permission entry in `RolesPermissionsSeeder::PERMISSIONS['leases']` if needed (e.g., 'leases.hold').

### Changing escalation logic (e.g., adding CPI indexing)

1. **Lease model:** escalation_type already supports 'cpi' enum value.
2. **Escalation job/command:** Create a new command (e.g., `ApplyLeaseEscalationsCommand`) that queries leases with escalation_type='cpi' and next_escalation_date ≤ now, fetches CPI index, calculates new rent, calls `LeaseRentChangeService::apply()` for each.
3. **Invoices:** Escalation changes take effect on the next invoice (monthly billing reads Charge.amount).
4. **Do NOT:** Directly edit Lease.base_rent_monthly without updating the matching Charge; use the service.

### Supporting multi-unit lease rent differentiation (per-unit rents)

**Current design:** Single base_rent_monthly applies to all units in the lease. If per-unit rent allocation is needed:

1. Add `lease_unit.rent_allocation_factor` (decimal) column — proportional share of base rent per unit.
2. Modify `LeaseCreationService::seedStandardCharges()` or create a new `allocateChargesPerUnit()` method to split the base-rent Charge across units proportionally.
3. Modify `MonthlyBillingService` to read per-unit charges when invoicing.
4. Update `LeaseRentChangeService` to re-allocate Charges per unit on rent change.
5. Update tests to assert per-unit charge splits.

**Caveat:** This breaks the single Charge.amount model; the current design assumes one rent Charge per lease that applies to all units equally.

### Adding a percentage-rent variant (e.g., tiered thresholds)

1. Extend the `percentage_rent_calculation_type` enum (or add a new field like `percentage_rent_tier_type`).
2. Implement new logic in `PercentageRentCalculationService::calculate()`.
3. Extend `LeaseForm` to expose new tier fields conditionally.
4. Write test scenarios in `PercentageRentScenarioTest.php`.

### Handling lease conflicts during multi-unit sync

`Lease::syncUnits()` is idempotent: it diffs the supplied unit IDs against the current pivot and recomputes occupancy for all affected units. If a unit is already occupied by another active lease:

- The pivot uniqueness constraint (`UNIQUE (lease_id, unit_id)`) prevents duplicate attachments.
- But there is no guard against attaching a unit that is already occupied by another lease's active status.
- **Do NOT** call `syncUnits()` without first checking that all units are available (not occupied by another active lease).

## 9. Gotchas, edge cases & recently-fixed bugs

### Bug: Renewal silently drops multi-unit leases' additional units (FIXED)

**Issue:** `LeaseRenewalService::renew()` previously carried only leases.unit_id, dropping additional units from multi-unit leases.

**Fix:** Now calls `syncUnits()` with the original's full unit set (from pivot), preserving the master.

**Test:** `MultiUnitLeaseRenewalTest::renews_a_multi_unit_lease_carrying_the_full_unit_set_*`

**Lesson:** Always copy the full unit set on renewal, not just the master.

---

### Bug: In-memory has_percentage_rent null on renewal without fresh()

**Issue:** `LeaseCreationService::create()` omits `has_percentage_rent` in the payload, so the returned Lease instance has null in memory (even though the column NOT NULL defaults to false in the DB). If renewal is called on that instance without `fresh()`, null propagates into the renewal's non-nullable column.

**Fix:** Model defaults `has_percentage_rent => false` in `$attributes`, so the in-memory value is never null. `LeaseLifecycleScenarioTest` now calls `fresh()` on service-created leases to mirror production behavior (admin panel re-fetches before follow-up actions).

**Test:** `LeaseLifecycleScenarioTest::renews_a_service_created_lease_without_the_has_percentage_rent_NOT_NULL_crash`

**Lesson:** Always re-read (fresh()) service-created models before cascading operations, or ensure model defaults cover all NOT NULL columns.

---

### Rent change must stay atomic with Charge sync

**Issue:** Audit M04 F-20 / D-13 identified drift between Lease.base_rent_monthly and Charge.amount when rent was changed via the standard edit form (which disabled the fields but allowed the form to still write them in the background).

**Fix:** Rent fields are now read-only on edit. The dedicated `LeaseRentChangeService::apply()` updates both Lease and Charge(s) in a single transaction. The form disable + dehydrated flags enforce this.

**Test:** `LeaseRentChangeService` tests; `LeaseLifecycleScenarioTest::escalation_raises_base_rent_*`

**Lesson:** Any time Lease affects a derived Charge, use a dedicated service with explicit transaction guards.

---

### Termination of partially-paid invoices requires explicit credit-note handling

**Issue:** If a lease is terminated and the operator chooses to cancel open invoices, partially-paid invoices (paid_amount > 0, balance > 0) are NOT cancelled. Cancelling them would orphan the paid_amount.

**Design:** Only fully-unpaid invoices (paid_amount = 0, balance > 0) are auto-cancelled. Operators who want to void a partially-paid invoice must:
1. Issue a credit note for the balance.
2. Manually mark the invoice as cancelled.

**Test:** `LeaseTerminationService` tests verify this guard.

**Lesson:** Termination actions must respect the AR ledger; never orphan payments.

---

### Bug: two concurrent requests could double-book a unit (FIXED 2026-07-30)

Two active leases on one shop is the single thing this module's invariants exist to prevent — it
bills the shop twice a month, gives two tenants a claim on it, and corrupts every occupancy figure
the owner sees.

**`LeaseRenewalService` was the hole.** Its `status === 'active'` guard sat *outside* the
transaction with no lock, so two requests that each loaded the lease before either committed —
a double-clicked "Renew", two admins, a retried POST — both passed it and both created an `active`
renewal. Reproduced: the unit was left carrying **two active leases** with the original in
`renewed`.

**`LeaseCreationService` had the same shape, weaker.** Its `isActivelyLeased()` guard is inside the
transaction, but it read the unit with a plain `find()`. Under MySQL's REPEATABLE READ a snapshot
read cannot see another transaction's uncommitted lease, so two concurrent creates on one unit both
find it free — and there is no unique constraint to catch the loser.

**The fix:** both services now `lockForUpdate()` **the unit row** before checking. Occupancy is the
contended resource, so every path that can put an active lease on a unit contends on the same row
and they serialise against each other, not merely against themselves. Renewal additionally
re-reads and re-checks the original lease under its own lock.

Adding a third activation path? Take the unit lock. `LeaseDoubleBookingTest` asserts every one of
these services still calls `lockForUpdate` — a sequential test cannot reproduce a race, but it can
hold the line that the lock protecting against it is still there.

The `LeaseForm` `unit_id` rule (pivot-aware, excludes self) remains the UI-level guard; it is not a
substitute for the service lock, because it validates before the write and outside the transaction.

---

### Multi-unit occupancy: unit is occupied if ANY attached lease is active

**Concurrency:** If a unit is part of multiple leases (which should not happen in normal flow due to the active-lease guard, but is theoretically possible if the guard is bypassed), the occupancy projection queries all leases on the unit and sees if any are active. One active lease is enough to mark occupied.

**Idempotence:** `Unit::recomputeStatus()` is idempotent: applying it multiple times is safe. Observers ensure it fires on every lease change.

---

### Percentage rent calculation type defaults to 'artificial' if null

**Migration:** An older lease might have `percentage_rent_calculation_type = null`. When `PercentageRentCalculationService::calculate()` runs, it treats null as 'artificial'.

**Future-proof:** New leases always set a non-null type via the form default (artificial).

---

### Escalation DOES auto-apply (this section used to say the opposite)

`RentEscalationService`, driven by the scheduled `leases:apply-escalations`, sweeps active leases
with `next_escalation_date <= today` and applies the increase through `LeaseRentChangeService`
(so the base-rent Charge and the marketing levy stay in lock-step), then rolls
`next_escalation_date` forward a year.

- **Idempotent + concurrency-safe:** each lease is row-locked and its due-ness re-checked *inside*
  the transaction; applying advances the date past today, so a re-run is a no-op.
- **One step per run:** a multi-year backlog (from a mis-set date) catches up over successive runs
  instead of compounding several years in one pass.
- **CPI is deliberately skipped** — there is no index feed, and inventing a CPI number would be
  inventing data. Only `fixed_percent` applies. Wiring CPI = §8 "Changing escalation logic".
- A rate of `0` still rolls the date forward, so it is not reconsidered every single day.

*(Until 2026-07-30 this section told you escalation was manual and invited you to build
`ApplyLeaseEscalationsCommand`. It had already been built. Check `routes/console.php` before
building anything this file calls "future".)*

---

### Security deposit is a metadata field, not enforced in invoicing

**Design:** The `security_deposit` and `security_deposit_received` fields are informational. They do NOT automatically reduce tenant invoices or create offset credits. If a security deposit is held, operators must:
1. Manual track its collection (toggle `security_deposit_received`).
2. Issue a credit note or payment offset when returning or applying the deposit to final invoices.

---

## 10. Tests & related modules

### Test files

- **Models & unit logic:**
  - `tests/Feature/Models/LeaseTest.php` — helpers, derived methods (totalMonthlyAmount, annualValue, isActive, isExpiringSoon, generateReference).

- **Observer (unit-status projection):**
  - `tests/Feature/Observers/LeaseObserverTest.php` — status transitions, master mirroring, maintenance override.

- **Services:**
  - `tests/Feature/Services/LeaseCreationServiceTest.php`
  - `tests/Feature/Services/LeaseRenewalServiceTest.php`
  - `tests/Feature/Services/LeaseTerminationServiceTest.php`
  - `tests/Feature/Services/LeaseRentChangeServiceTest.php`

- **Scenarios (end-to-end):**
  - `tests/Feature/Scenarios/LeaseLifecycleScenarioTest.php` — creation → escalation → renewal → termination, charges + VAT + invoicing integration.
  - `tests/Feature/Scenarios/MultiUnitLeaseFormScenarioTest.php` — Filament form for multi-unit leases.
  - `tests/Feature/Scenarios/MultiUnitLeaseDataScenarioTest.php` — occupancy projection for master + additional units, syncUnits edge cases.
  - `tests/Feature/Scenarios/PercentageRentScenarioTest.php` — artificial & natural-breakpoint percentage rent.

- **Filament:**
  - `tests/Feature/MultiUnitLeaseTest.php` — form & table interaction (edit, additional units).

- **Regression:**
  - `tests/Feature/Regression/MultiUnitLeaseRenewalTest.php` — multi-unit renewal carrying full unit set.
  - `tests/Feature/Regression/LeaseDoubleBookingTest.php` — a unit can never carry two active leases: the raced double-renewal, the occupied-unit create, and the standing assertion that both activation paths still lock the unit row.
  - `tests/Feature/Regression/Module04HoldoverAlertTest.php` — an active lease past its end date is surfaced (see C1.10: it is alerted, not billed).
  - `tests/Feature/Regression/Module04LeaseIntegrityTest.php` — cross-cutting lease integrity.

### Related modules

- **Units** (`docs/modules/03-units.md`) — occupancy status projection; lease drives unit state.
- **Tenants** (`docs/modules/...`) — one tenant per lease.
- **Charges** (`docs/modules/...`) — rent + service charges linked to lease; VAT rules.
- **Invoices** (`docs/modules/...`) — monthly billing reads lease charges.
- **CAM** (`docs/modules/...`) — allocates service charges to CAM pools.
- **Percentage Rent / Sales Declarations** (`docs/modules/...`) — triggered by TenantSalesDeclaration on a lease.
- **ETA Integration** (`docs/modules/...`) — invoices from leases submitted to tax authority.
- **Marketing Levy** (`docs/modules/...`) — derived from lease data for budget allocation.

---

**CRUD Permissions (Spatie):**
- `leases.view` → see list/detail
- `leases.create` → create leases
- `leases.edit` → edit lease fields
- `leases.delete` → hard/soft delete (only super_admin)
- `leases.terminate` → call LeaseTerminationService
- `leases.renew` → call LeaseRenewalService
- `leases.generate_invoice` → ManuallyGenerate invoices from lease

---

## Deletion policy

Operator decision 2026-07-31, following Yardi/MRI/Entrata: a record that carries history is
**refused**, not warned about — the damage lands on the reports and audit trail that referenced
it, none of which are in front of whoever clicks the button. The single register is
[`App\Support\DeletionPolicy`](../../app/Support/DeletionPolicy.php); `DeletionPolicyConformanceTest` fails the build if a model here ships unclassified or a Delete
button reappears on a money record.

| Model | Rule | Instead / why |
|---|---|---|
| `Lease` | **Only while unreferenced** — blocked by `invoices`, `charges`, `salesDeclarations`, `camAllocations`, `maintenanceRequests`, `renewals`, `deposits`, `postDatedCheques` | terminate the lease — that is the documented end of a tenancy, and it keeps the billing history |
