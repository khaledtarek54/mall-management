# Module 04 — Leases

> Date: 2026-05-31
> Status: 🟡 Yellow — services are excellent and tested; standard Create/Edit form bypasses them and leaks consistency (F-19/F-20/F-21). Demo path is safe (wizard + modals always invoke services).
> Surface: [Lease model](../../app/Models/Lease.php), [Charge model](../../app/Models/Charge.php), [Admin Leases resource](../../app/Filament/Admin/Resources/Leases/), 3 lifecycle services ([Creation](../../app/Services/LeaseCreationService.php) / [Renewal](../../app/Services/LeaseRenewalService.php) / [Termination](../../app/Services/LeaseTerminationService.php)).

## 1. Inventory

### 1.1 Model — [Lease.php](../../app/Models/Lease.php) (155 LOC)

- Traits: `HasFactory`, `InteractsWithMedia`, `LogsActivity`, `SoftDeletes`. Implements `HasMedia`.
- `$fillable`: 24 columns covering identity (`reference`, `unit_id`, `tenant_id`), lifecycle (`status`, `previous_lease_id`, `commencement_date`, `expiry_date`, `term_months`), financial (`base_rent_monthly`, `service_charge_monthly`, `currency`, `security_deposit`, `security_deposit_received`, `escalation_rate`, `escalation_type`, `next_escalation_date`), percentage-rent (`has_percentage_rent`, `percentage_rent_threshold`, `percentage_rent_rate`, `percentage_rent_calculation_type`), billing (`billing_day`, `payment_terms_days`), and free-form (`notes`, `metadata`).
- Date casts: `commencement_date`, `expiry_date`, `next_escalation_date`, `billing_day`.
- Decimal:2 casts: `base_rent_monthly`, `service_charge_monthly`, `security_deposit`, `escalation_rate`, `percentage_rent_threshold`, `percentage_rent_rate`.
- Relationships: `tenant`, `unit`, `previousLease`, `renewals` (reverse chain via `previous_lease_id`), `charges`, `invoices`, `maintenanceRequests`, `salesDeclarations`, `camAllocations`.
- Computed: `totalMonthlyAmount()`, `annualValue()`, `isActive()`, `isExpiringSoon($days = 90)`, `daysUntilExpiry()`, `generateReference($assetCode = 'HW')`.
- **No model observer**, **no boot logic** for unit-flip or charge-seeding. All side-effects live in the 3 services.
- LogsActivity allowlist: `['reference', 'status', 'commencement_date', 'expiry_date', 'term_months', 'base_rent_monthly', 'service_charge_monthly', 'tenant_id', 'unit_id']`, dirty-only, log name `lease`.

### 1.2 Migrations

| File | Effect |
|---|---|
| [2024_01_01_000004_create_leases_table.php](../../database/migrations/2024_01_01_000004_create_leases_table.php) | Base table. Status enum: `draft, pending_approval, active, expired, renewed, terminated, cancelled` (default `draft`). Escalation type enum: `none, fixed_percent, cpi`. FKs `unit_id`/`tenant_id` are `restrictOnDelete` — orphan-safe. Indexes `(status, expiry_date)`, `unit_id`, `tenant_id`. Soft-deletes. |
| [2024_01_01_000005_create_charges_table.php](../../database/migrations/2024_01_01_000005_create_charges_table.php) | Charge belongs-to Lease (cascade-on-delete). `type` enum: `base_rent, service_charge, utility, parking, percentage_rent, other`. `frequency` enum: `monthly, quarterly, annually, one_time` (default `monthly`). `vat_rate` default `14.00`. Index `(lease_id, is_active)`. |
| 2026_05_12_162539_add_previous_lease_id_to_leases_table | Adds `previous_lease_id` FK (nullable, `nullOnDelete`). Enables renewal chain. |
| 2026_05_23_160331_add_percentage_rent_calculation_type_to_leases | Adds `percentage_rent_calculation_type` enum `natural_breakpoint, artificial` (nullable). |

### 1.3 Admin Resource

| File | LOC | Notes |
|---|---:|---|
| [LeaseResource.php](../../app/Filament/Admin/Resources/Leases/LeaseResource.php) | 106 | `RoleGatedActions` + `ScopesViaProperty` traits; `tenantScopeRelation()` = `unit`. Nav icon `DocumentText`, sort 4, group "Operations". Globally searchable by `reference`, `tenant.name`, `unit.code`. Relations: `LeaseInvoicesRelationManager`, `ActivitiesRelationManager`. **No `getNavigationBadge()` override** — so no F-17 carryover from Module 03. |
| Schemas/LeaseForm.php | 192 | 6 form sections: Lease Details / Term / Financial Terms / Percentage Rent (collapsed, live-conditional on `has_percentage_rent`) / Notes / Documents (Spatie media, up to 10MB PDFs/images/Word). |
| [Tables/LeasesTable.php](../../app/Filament/Admin/Resources/Leases/Tables/LeasesTable.php) | 422 | 8 columns; 7 filters incl. expiring-soon and trashed; **Quick New Lease 2-step modal wizard** (header action) calls `LeaseCreationService::create()`; **Renew** + **Terminate** record actions call the matching services; export + bulk export/delete/restore/forceDelete. |
| Pages/CreateLease.php | **12** | **Standard `CreateRecord` page — no service delegation.** See [F-19](#-f-19--createleasephp-bypasses-leasecreationservice). |
| Pages/EditLease.php | 101 | Header action **Generate Invoice** modal calls `MonthlyBillingService::generateForLease()` with 4 outcome notifications (created / already_billed / no_charges / failure). Also standard Delete/ForceDelete/Restore. **No status-edit side-effect handler.** See [F-21](#-f-21-edit-form-status-change-doesnt-sync-unit-status). |
| Pages/ListLeases.php | thin | Default list. |

### 1.4 Lifecycle services (the design highlight of this module)

| Service | LOC | Method | Side-effects |
|---|---:|---|---|
| [LeaseCreationService](../../app/Services/LeaseCreationService.php) | 106 | `create(array $payload): Lease` | DB transaction: resolve/create tenant → load unit → compute expiry from `commencement + term_months - 1 day` → create lease (`status='active'`, defaults `security=3× rent`, `escalation=7%`, `escalation_type='fixed_percent'`, `payment_terms_days=7`) → create base_rent Charge (VAT-exempt, `vat_rate=0`) → create service_charge Charge (`vat_rate=14.00`) if `$service > 0` → mark unit `status='occupied'`. |
| [LeaseRenewalService](../../app/Services/LeaseRenewalService.php) | 93 | `renew(Lease $original, array $data): Lease` | Validates `$original->status === 'active'`. Computes new expiry. Creates new Lease with `previous_lease_id=$original->id`, copies (currency, security deposit, escalation, percentage-rent config, billing, notes, metadata) but resets `next_escalation_date=null`. Duplicates each Charge with new amounts (base_rent ← new_rent, service_charge ← new_service_charge, others unchanged), `start_date=commencement`, `end_date=null`, `is_active=true`. Marks original `status='renewed'`. |
| [LeaseTerminationService](../../app/Services/LeaseTerminationService.php) | 79 | `terminate(Lease $lease, array $data): Lease` | Validates `$lease->status ∈ ['active','pending_approval']`. Sets `status='terminated'`, overwrites `expiry_date=$terminationDate`, appends termination line to `notes`. Marks unit `vacant`. Deactivates all Charges (`is_active=false`, `end_date=$terminationDate`). If `cancel_open_invoices=true`, cancels **only fully-unpaid** invoices (`balance>0 AND paid_amount=0`). Excellent docstring at lines 57-62 explaining why partially-paid invoices aren't auto-cancelled (preserves paid_amount ledger; operators must issue explicit credit notes). |

All three services wrap their work in `DB::transaction()`. All three throw `InvalidArgumentException` on state-precondition violations.

### 1.5 Charge model — [Charge.php](../../app/Models/Charge.php) (64 LOC)

- Traits: `HasFactory`, `LogsActivity`.
- $fillable: `lease_id, name, type, amount, currency, frequency, vat_applicable, vat_rate, start_date, end_date, is_active`.
- Helpers: `calculateVat()` returns `amount * vat_rate / 100` if `vat_applicable`; `totalWithVat()` returns `amount + calculateVat()`.
- LogsActivity allowlist: 6 columns under log name `charge`.
- No scopes. No observers. **No RelationManager on LeaseResource** — Charges are not editable from the UI directly.

### 1.6 Owner panel — no LeaseResource

Owner sees lease information only indirectly: `PortfolioStats` widget (counts active leases), `InvoicesTable` (joins to Lease for display), `PropertyResource` (Asset detail view may surface lease counts). No standalone Lease management for owners. Confirmed by directory listing under `app/Filament/Owner/Resources/`.

### 1.7 Tenant Portal — no LeaseResource

Tenants don't see their lease record as a navigable resource; lease data appears embedded in Invoice display. This is intentional per FEATURES.md (portal = "balance + invoices + maintenance"). Worth verifying at Module 11 audit that lease term/expiry/rent are visible somewhere on the portal Invoice view.

### 1.8 Tests (~402 LOC) — all green

- [LeaseTest.php](../../tests/Feature/Models/LeaseTest.php) — model computeds.
- [LeaseCreationServiceTest.php](../../tests/Feature/Services/LeaseCreationServiceTest.php) — creates lease + 2 charges, marks unit occupied; skips service charge when 0; creates tenant when mode=new; applies defaults; honors overrides.
- [LeaseRenewalServiceTest.php](../../tests/Feature/Services/LeaseRenewalServiceTest.php) — renews active, copies charges with new amounts, marks original renewed, returns linked lease.
- [LeaseTerminationServiceTest.php](../../tests/Feature/Services/LeaseTerminationServiceTest.php) — terminates active, frees unit, deactivates charges, optionally cancels fully-unpaid invoices (but not partially-paid).

## 2. Spec map

| Source | Verbatim claim | Verified |
|---|---|---|
| DEMO.md §3 | "Pick a vacant unit (the list filters to vacant only). Save. 'Lease is created, unit flips to occupied automatically.'" | ✅ **via the Quick New Lease wizard**; **not via the standard Create form** — see F-19. |
| FEATURES.md | "Sign a new tenant in under 2 minutes." | ✅ 2-step modal wizard. |
| FEATURES.md | "Quick New Lease wizard … filters to vacant units, picks existing or creates new tenant, auto-seeds charges, flips unit to occupied." | ✅ via service. |
| FEATURES.md | "Renew Lease — creates a new linked lease, copies charges with new amounts, flips old lease to `renewed`." | ✅ via service. |
| FEATURES.md | "Terminate Lease — marks lease `terminated`, frees unit, deactivates charges, optionally cancels open invoices." | ✅ via service (fully-unpaid only). |
| FEATURES.md | "Run Monthly Billing — iterates active leases, generates Invoice+InvoiceItems from each lease's Charges." | Defer billing path to Module 05. |
| FEATURES.md | "First 4 leases expire within the next 90 days (15/35/65/80) — ExpiringLeases widget exercises all 3 color tiers." | ✅ verified in seeder lines 166-169. |
| FEATURES.md | "Generate single invoice — per-lease action on Lease edit page to bill one specific lease for an arbitrary period." | ✅ EditLease.php `generateInvoiceAction()`. |

## 3. Findings

### 🟡 F-19. `CreateLease.php` bypasses `LeaseCreationService`

[CreateLease.php](../../app/Filament/Admin/Resources/Leases/Pages/CreateLease.php) is 12 LOC and extends `CreateRecord` with no overrides. The standard form (used when an operator clicks "New Lease" from the table header CreateAction) writes a raw Lease row via Eloquent's default flow and **skips** all `LeaseCreationService` side-effects:

1. **No Charge seeding** — the lease has zero charges, so `MonthlyBillingService` later finds nothing to bill and either skips (`reason: no_applicable_charges`) or generates an empty invoice.
2. **No unit flip** — `status` on the chosen `Unit` stays `vacant`, even though it has an active lease. Cascading consequences: `OccupancyMap`/`MallStats` undercount occupancy; `UnitsTable` filter `Vacant` still shows the unit; `ActionRequired` widget still lists it as a vacant-needs-leasing card.
3. **No reference auto-gen** — `LeaseCreationService` calls `Lease::generateReference($assetCode)`. The standard form doesn't. The reference field in the form is `disabled()` when present, but on create it's blank, so the database `unique('reference')` constraint will eventually conflict for any second lease with no reference set, depending on what default the column has.

**Demo impact:** none. DEMO.md §3 narrates the wizard flow, and the LeasesTable Quick New Lease action is the demo path.

**Production impact:** high. Any operator who clicks "Create" (the default Filament action) instead of "Quick New Lease" (the bolt-icon header action) gets a broken lease.

**Fix scope:** design decision. Three options:

- **A**: Make `CreateLease::handleRecordCreation()` delegate to `LeaseCreationService::create()`. Tests need to assert the form's data shape vs. the service's input shape (the form has `unit_id`, `tenant_id`, `base_rent_monthly`, etc. at the top level; the service expects nested `lease.*` + `tenant_mode`).
- **B**: Introduce a `LeaseObserver` with a `created()` handler that seeds the standard Egypt charges (if none exist) and flips the unit to `occupied` when `status='active'`. Idempotent: guards with `if (! $lease->charges()->exists())`. Bonus: also fixes F-21 by adding `updated()` handler that watches `wasChanged('status')` → resync unit.
- **C**: Remove the standard `Create` page from `LeaseResource::getPages()` so the only way to create a lease is the wizard. Affects the e2e `02-admin-pages.spec.js:Leases create` test; will need to remove or rewrite that assertion. Operator-facing surprise (no "+ New" button at the table header any more).

Recommend B (observer) — fixes F-19 and F-21 simultaneously, keeps the wizard's UX, doesn't change the form, doesn't break the existing e2e tests.

### 🟡 F-20. Editing rent fields on the lease record doesn't sync the Charge

`LeaseForm` exposes `base_rent_monthly` and `service_charge_monthly` as editable fields on both Create and Edit. But:

- `MonthlyBillingService` (see Module 05) bills from `Lease::charges()->active()`, not from `Lease::base_rent_monthly`.
- The lease's rent columns ARE used elsewhere: `MallStats` MRR, `TopTenants`, `ExpiringLeases`, `LeasesTable` display, `PercentageRentCalculationService` (lines 18, 34).

So if an operator changes `base_rent_monthly` from EGP 50,000 to EGP 60,000 on an existing lease via the Edit form: tables and the MRR KPI update immediately, but invoices keep being generated at EGP 50,000 until the corresponding Charge::amount is manually edited too. There is no UI to edit Charge::amount directly (no RelationManager).

**Fix scope:** similar to F-19. Three options:
- Add an Edit handler that syncs `base_rent_monthly`/`service_charge_monthly` changes to the matching base_rent / service_charge Charge row.
- Make the rent fields read-only on Edit; expose a "Change rent" action that does the sync atomically.
- Add a Charges RelationManager so operators can see/edit charges directly.

Recommend "Change rent" action — keeps the audit trail clean and prevents silent drift. Catalogued for explicit decision.

### 🟡 F-21. Edit form status-change doesn't sync unit status

`EditLease` allows editing the `status` field via the standard form. None of the three services intercept this path:

- `active` → `terminated` via Edit form: Lease record updates, but Unit stays `occupied`. Termination side-effects (deactivate charges, optionally cancel invoices) are skipped.
- `draft` → `active` via Edit form: Lease record updates, but Unit stays `vacant`.

The Terminate modal (which DOES call the service) and the Renew modal are the only safe paths. Per F-19's observer recommendation, an `updated()` hook watching `wasChanged('status')` would close this gap too.

### 🟢 Termination's "fully-unpaid only" guard is a great defense

[LeaseTerminationService:57-62](../../app/Services/LeaseTerminationService.php#L57) explicitly does NOT auto-cancel partially-paid invoices on termination, with a clear docstring about why (preserves paid_amount ledger; explicit credit notes required for the paid portion). This is the kind of defensive design that makes auditors happy. Test coverage at [LeaseTerminationServiceTest](../../tests/Feature/Services/LeaseTerminationServiceTest.php) confirms it.

### 🟢 Renewal preserves the chain correctly

`previous_lease_id` is set on the new lease, and `renewals()` exposes the reverse chain via HasMany. The original is flipped to `renewed` (not `terminated`), so AR aging won't trip over it. Tested.

### 🟢 LeaseResource has no `getNavigationBadge()` — F-17 does NOT apply

No carryover from Module 03 for this module.

### 🟢 Form sections + i18n + RTL all wired

LeaseForm has full i18n on every label (`admin.sections.*`, `admin.fields.*`, `admin.actions.*`). Quick New Lease wizard labels confirmed in [LeasesTable.php](../../app/Filament/Admin/Resources/Leases/Tables/LeasesTable.php). Renew and Terminate modal copy translated.

## 4. Test sweep

| Filter | Result | Time |
|---|---|---|
| `php artisan test --parallel --filter='Lease'` | **36 passed / 0 failed** | 1.24 s |
| `npx playwright test tests/e2e/17-functional-actions.spec.js` (Renew modal, Generate Invoice, Run Monthly Billing, ETA, bulk actions, operator switcher, locale switching, portal flows) | **17 passed / 0 failed** | 26.4 s |
| `php artisan test --parallel` (full regression confirms no drift from Module 03 fixes) | **287 passed / 0 failed** | 4.13 s |

Coverage gap (not blocking): no test asserts the F-19/F-20/F-21 inconsistencies — would be the first thing to add once the fix approach is chosen.

## 5. Manual UX

- Quick New Lease wizard: confirmed by Playwright (`17-functional-actions.spec.js:46`).
- Renew modal: confirmed by Playwright (`17-functional-actions.spec.js:78`).
- Generate Invoice from Edit: confirmed by Playwright (`17-functional-actions.spec.js:69`).
- Locale switching with lease pages in scope: confirmed by Playwright (`17-functional-actions.spec.js:293, 301`).

## 6. No inline fixes this module

All three findings (F-19, F-20, F-21) point at the same architectural choice — services hold side-effects, standard forms don't — and the right fix depends on which option (A/B/C above) you prefer. Per "fix small, batch large", this is a design decision that goes to the deferred backlog.

## 7. Deferred decisions

| # | Decision | Default if not raised |
|---|---|---|
| D-12 | F-19/F-21: pick a fix approach for the standard form bypassing lifecycle services (A=delegate from page, B=observer, C=remove standard create) | **B** — `LeaseObserver` with idempotent `created()` and `updated()` handlers; closes both findings at once |
| D-13 | F-20: rent fields on Edit can drift from Charge::amount — pick: read-only fields + explicit "Change rent" action / form-level sync hook / Charges RelationManager | **A** — Change-rent action + read-only display on Edit |
| D-14 | Should tests cover the form-bypass paths post-fix? (3-4 cases each) | Yes — block merge of the fix until tests assert charge-seeding, unit-flip, charge-sync from the form path |

## 8. Verdict

**🟡 Yellow.** This module has the codebase's best service-layer design (three small, single-purpose, transactional, well-tested services) and one of its biggest UX risks (the form bypasses those services). Demo flow is safe because the demo script narrates the wizard, not the form. Production rollout needs the F-19/F-21 fix shipped before a non-pilot deployment.

The fix is well-bounded and low-risk: a single observer file plus 2-3 test cases. Catalogued as D-12 / D-13 / D-14 for explicit go-ahead.

Module ratings to date: Module 00 🟢 · 01 🟡 · 02 🟢 · 03 🟡 · 04 🟡.

## Next

Module 05 — Invoices. Surface: [Invoice model](../../app/Models/Invoice.php), [InvoiceItem model](../../app/Models/InvoiceItem.php), [Admin Invoices resource](../../app/Filament/Admin/Resources/Invoices/), [MonthlyBillingService](../../app/Services/MonthlyBillingService.php), [InvoicePdfService](../../app/Services/InvoicePdfService.php), [LateFeeService](../../app/Services/LateFeeService.php), the [RunMonthlyBilling job](../../app/Jobs/RunMonthlyBilling.php) + [command](../../app/Console/Commands/RunMonthlyBillingCommand.php), the [ApplyLateFees job](../../app/Jobs/ApplyLateFees.php), Arabic PDF rendering via mPDF, and the F-17 nav badge carryover fix on InvoiceResource.
