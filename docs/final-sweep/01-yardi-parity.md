# Final sweep — competitive parity

> Part of [the final pre-staging sweep](README.md). Three independent audits: leasing & space vs
> **Yardi Voyager Commercial**; facility/vendor/procurement vs the FM specialists (**Angus, Building
> Engines, Planon, Corrigo, MRI Evolution**); and the modules Yardi does not cover vs their own
> best-in-class benchmarks (**Odoo/Zoho** for HR, **Mallcomm/Vicinity** for engagement,
> **Commercial Café/Entrata** for the portal).
>
> **Money-side parity — AR, recoveries, GL, tax — lives in [03-money-gl.md](03-money-gl.md)**, so it
> is not repeated here.
>
> Every CRITICAL and HIGH was re-verified in the code by the lead. Severity corrections and retired
> claims are marked inline — there are several, and they matter.

## 0. Where Atriom actually stands

**The 2026-08 Yardi benchmark cycle's central claim holds.** Atriom moved from "store the lease's
current state and mutate it" to a date-ranged charge schedule, and an agent sent specifically to find
surviving state-mutation in the recurring-rent path **found none**. The money core is at or above the
benchmark and must not be rebuilt.

**What the cycle did not do is finish the edges.** The dominant failure shape across all three audits
is the same, and it is not a design flaw — it is a maintenance flaw:

> A feature ships. Eleven more features are added around it. The first feature's **hand-written list
> of the things it must handle is never revisited.**

That single shape produces the renewal that loses negotiated terms, the importer that produces leases
billing nothing, the vendor bill that cascades to payments but not penalties, the rent roll that sums
three charge types out of a catalogue that is now open-ended, and the approval ladder that is seeded
in dev and never in production. **The generalisable fix is to derive these lists rather than
enumerate them, and to gate the derivation** — exactly the pattern this project already uses for its
twelve conformance registries.

---

## 1. Leasing & space vs Yardi Voyager

### 1.1 CRITICAL — `LeaseRenewalService` is a partial copy

- **Remedy class:** REIMPLEMENT (derive) + conformance gate · **Effort:** M · **Verified:** yes, in full

[LeaseRenewalService.php:69-104](../../app/Services/LeaseRenewalService.php#L69-L104) builds the
renewal from a literal array written when `leases` had ~24 columns. It now has **43 fillable**. I
diffed them: **29 are carried, 14 are not.** Some omissions are deliberate and documented
(`rent_commencement_date`, `next_escalation_date`, `holdover_from`, `possession_date`,
`expiry_reminder_notified_at`) — the rest are terms nobody came back to:

| Dropped | Consequence |
|---|---|
| `escalation_amount` | **Verified chain:** `escalation_type` carries but the amount does not → `Lease::creating` ([:75-84](../../app/Models/Lease.php#L75-L84)) computes `configured = false` for `fixed_amount` → `next_escalation_date` stays null → `RentEscalationService`'s `whereNotNull` ([:43](../../app/Services/RentEscalationService.php#L43)) excludes the lease **for its whole term**. Silent, compounding revenue leak. |
| `escalation_floor_rate`, `escalation_ceiling_rate` | The negotiated collar is lost on **every** renewal, not just fixed-amount ones — including the guard rail against a mistyped rate. |
| `rent_pricing_basis`, `base_rent_rate_per_sqm_year` | A rate-priced lease renews as flat, so `deriveBaseRentFromRate()` returns null and a later expansion changes **no rent at all**. |
| `late_fee_percent`, `late_fee_grace_days`, `late_fee_minimum` | Per-lease late-fee terms (MF-08) revert to the global default. |
| `percentage_rent_deductible_types` | The deduction clause is lost. |
| `holdover_rate_pct` | The negotiated holdover uplift is lost. |

**And three child collections are never copied at all** — `LeaseRenewalService` contains no mention
of any of them (grepped `camterm`, `tier`, `rentable`, `percentageRentTiers`):

- **`LeaseCamTerm`** — the CAM cap and the contractually stated share. `camTermFor()`
  ([Lease.php:759](../../app/Models/Lease.php#L759)) queries by the *new* lease id, finds nothing, and
  the tenant gets an **uncapped year-end CAM true-up on a capped lease** — a GL-posted invoice they
  will dispute with the contract in hand. The renewal's CAM panel is simply empty, so nobody can see
  the cap was lost.
- **`LeasePercentageRentTier`** — `has_percentage_rent` and the `tiered` type *are* carried, so the
  lease looks configured; `ladderFor()` returns empty and **overage = 0.00 every month**.
- **The `lease_rentable_item` pivot** — see §1.2.

**Fix.** Stop enumerating. Derive the payload from `$fillable` minus an explicit, commented
`NOT_CARRIED` list, copy the three child collections, and add a conformance test in the
`ChangeImpactConformanceTest` shape that fails when a new lease column is classified by neither list.
That gate is the actual deliverable — without it this recurs the next time a term is added.

### 1.2 HIGH — renewal orphans rentable-item assignments

- **Remedy class:** EDIT · **Effort:** S · **Verified:** yes

The charge carry-forward ([:120-147](../../app/Services/LeaseRenewalService.php#L120-L147)) matches
`parking` and copies the charge, but the `lease_rentable_item` pivot is not copied.
`RentableItem::isHeldOn()` filters `leases.status IN ('active','pending_approval')`
([RentableItem.php:124](../../app/Models/RentableItem.php#L124)) and the original lease is flipped to
`renewed` at [:155](../../app/Services/LeaseRenewalService.php#L155) — so **every bay reads free** to
the assignment picker while the renewal is still invoiced for them. Conversely the next
`rebuildCharge()` sums an empty pivot to 0 and **closes** the charge, so the parking income silently
stops.

**Fix:** exclude `parking` from the literal carry and let `rebuildCharge()` derive it from the pivot —
one source of truth instead of two.

### 1.3 HIGH — `LeaseSpaceChangeService::expand()` is an unguarded third activation path

- **Remedy class:** BUGFIX · **Effort:** S · **Verified:** yes

`expand()` checks `isActivelyLeased()` at [:71](../../app/Services/LeaseSpaceChangeService.php#L71) —
a plain read **outside** the transaction that starts at `:76`. I grepped the entire file for
`lockForUpdate`: **zero occurrences.** Both peers lock the contended unit row
([LeaseCreationService.php:39](../../app/Services/LeaseCreationService.php#L39),
[LeaseRenewalService.php:57](../../app/Services/LeaseRenewalService.php#L57)), and the module doc
states the rule verbatim: *"Adding a third activation path? Take the unit lock."* `expand()` **is**
that path. `lease_unit`'s unique key is `(lease_id, unit_id)`, so nothing catches the loser: two
concurrent expansions, or an expansion racing a new lease, both commit.

**This is more likely than it sounds** — expansion rights sit on units the mall is simultaneously
marketing.

**It is worse than a third path — it is two of four.** The standard **New Lease page** is also
unguarded: [CreateLease.php](../../app/Filament/Admin/Resources/Leases/Pages/CreateLease.php) uses
Filament's stock `CreateRecord` flow, where `mutateFormDataBeforeCreate()` only re-validates property
scope and `afterCreate()` only seeds charges and attaches extra units. There is **no unit lock and no
`isActivelyLeased()` check** on the primary path an operator uses to create a lease.

| Activation path | Locks the unit? |
|---|---|
| `LeaseCreationService` (Quick New Lease wizard) | ✅ |
| `LeaseRenewalService` | ✅ |
| **`CreateLease` (the standard New Lease page)** | ❌ |
| **`LeaseSpaceChangeService::expand()`** | ❌ |

> **Stale doc that now protects the bug.** `docs/benchmarks/yardi/06-atriom-gap-analysis.md` states
> double-booking is locked in "**both** activation paths" and verdicts the row **"KEEP — do not
> touch."** There are four paths and two are guarded. The verdict now actively shields the gap.

### 1.4 HIGH — a retroactive rent change is never collected

- **Remedy class:** EDIT (guard) + M (catch-up) · **Effort:** S / M · **Verified:** yes

The Change Rent `effective_from` picker
([LeasesTable.php:592-596](../../app/Filament/Admin/Resources/Leases/Tables/LeasesTable.php#L592-L596))
has `->default(now()->startOfMonth())->required()` and **no `minDate`** — while a different picker in
the same file at `:1074` does use one. `setAmount()` rewrites the schedule for months already
invoiced, and `alreadyBilledForMonth()` then makes a re-run a no-op, so **no catch-up or credit is
ever raised**. The rent roll reads `pickInForce($asOf)` and reports the new rent for a month whose
invoice says the old one.

This is the commonest Egyptian mall scenario there is: renewal agreed in March, keyed in June, three
months of increase never invoiced and nothing surfaces it. **Voyager generates catch-up charges on a
retroactive amendment.**

### 1.4b CRITICAL — the lease importer cannot work, and PHPStan said so

- **Remedy class:** BUGFIX ×4 · **Effort:** M · **Verified:** yes, all four

The single path for loading a real mall's leases has **four stacked faults**. They are sequential —
fixing one exposes the next — which is why no single reviewer caught the set.

1. **`$this` in a static-method closure.** `getColumns()` is `public static`
   ([LeaseImporter.php:17](../../app/Filament/Imports/LeaseImporter.php#L17)), and the `unit_code`
   column's `fillRecordUsing()` closure reads `$this->data['asset_code']`
   ([:41](../../app/Filament/Imports/LeaseImporter.php#L41)). No `$this` is bound there, so
   `$assetCode` is never truthy, the branch that sets `$record->unit_id` never runs, and `leases.unit_id`
   is NOT NULL. **PHPStan proved this and the baseline swallowed it** — both
   `nullCoalesce.variable` and the consequent `if.alwaysFalse` are baselined against this file.
   *(Lines 96 and 103 also use `$this->data`, but those sit in `resolveRecord()`, an instance method,
   and are fine — which is exactly why the fault is easy to miss.)*
2. **A column that does not exist.** `ImportColumn::make('asset_code')` at `:31-34` has no
   `fillRecordUsing()`, so Filament's default writes `$record->asset_code`; `leases` has no such column
   in any of the 195 migrations.
3. **No charge schedule.** The importer never calls `seedStandardCharges()` — it has exactly two
   callers, and neither is here — so an imported lease bills nothing.
4. **Not idempotent.** `resolveRecord()` keys on `reference`, which the import rules mark `nullable`;
   without one it mints a fresh reference per run, and the double-booking guard lives in
   `LeaseCreationService`, which the importer bypasses. It is also not property-clamped:
   `withoutGlobalScopes()` with no visibility check — **the exact hole `UnitImporter` documents and
   closes**.

And the safety net is blind: `AuditChargeSchedulesCommand::auditLease()` iterates
`$lease->charges->groupBy('type')`, so a lease with **zero** charges yields no findings and the command
prints *"Every charge schedule is unambiguous."* **A lease with no charges is the one shape the
pre-import gate reports as clean.**

**No test executes any importer** — the sole importer test inspects validation *rules* on
`TenantImporter`.

### 1.5 HIGH — the unit importer still writes a column that was dropped

- **Remedy class:** BUGFIX · **Effort:** S · **Verified:** yes

`units.floor` was dropped on 2026-08-10
([migration:133](../../database/migrations/2026_08_10_160000_create_floors_and_move_units_onto_them.php#L133))
and replaced by `floor_id`. [UnitImporter.php:60](../../app/Filament/Imports/UnitImporter.php#L60)
still declares `ImportColumn::make('floor')`, and [UnitExporter.php:19](../../app/Filament/Exports/UnitExporter.php#L19)
still exports it — where `floor` now resolves the `Floor` **relation**. Critically, **there is no
`floor_id` import column at all**, so even an unmapped import lands every unit floorless, breaking
the occupancy map's floor grouping and per-floor GLA.

Every other consumer was migrated in that same commit; these two were missed. *(I verified the column
mapping and the dropped column; I did not execute an import, so whether it hard-errors or silently
ignores is inferred.)* **This is the mall-#2 / 500-unit onboarding path.**

### 1.6 HIGH — the rent roll understates contracted income

- **Remedy class:** EDIT · **Effort:** S · **Verified:** yes

[ReportService.php:478](../../app/Services/Reports/ReportService.php#L478) computes
`'total_monthly' => round($rent + $service + $marketing, 2)` — **three hardcoded charge types.** It
omits `parking` and every accountant-added catalogue code.

This got worse on 2026-08-11, and for a good reason: freeing `charges.type` from its DB enum was
correct, and it means an accountant can now add a recurring charge code with no deploy. **The rent
roll was not updated in the same change**, so any new code bills correctly and is invisible in the
portfolio's headline income report — the one an owner and a lender read.

Same method: `'area_sqm' => $lease->totalAreaSqm()` takes **today's** area, not the as-at area, while
`:539` gets it right — so `rent_per_sqm_year` divides an as-at rent by a current area.

### 1.7 MEDIUM — the leasing long tail

Verified as reported, not individually re-checked by the lead. **No WALE/WAULT** anywhere, though the
inputs exist · **open `LeaseOption`s outlive their lease** ([Unit.php:127-132](../../app/Models/Unit.php#L127-L132)
has no lease-status filter), encumbering units forever and never lapsing · **`relocation` is a dead
enum value** and `LeaseSpaceChangeService.php:139` tells the operator to record one ·
**`lease_options.penalty_amount` is dead** — break options bill no penalty · `rofr`/`rofo`/`purchase`
exercise into nothing · straight-line rent has no report surface · the remeasurement register is
write-only (no screen reads `unit_areas`) · **no vacancy duration/downtime**, and not derivable
because `status` carries no history · no unit combine/split/demise · **one measurement standard**
(zero hits for NLA/BOMA/IPMS/load factor) · **no guarantor or bank guarantee on the lease** — the
خطاب ضمان enum is four dead lines and `deposit_transactions.method` is cash|bank only · no
assignment/sublease · **no clause register** (co-tenancy, exclusivity, radius, continuous operation)
— yet `Violation::CATEGORIES['operating_hours']` fines a covenant the system does not store ·
`TenantMix` charts lease **counts**, not area, so an anchor and a kiosk weigh the same.

> **Retired.** "No lease abstract — reachable only from Edit" is **false**.
> [LeasesTable.php:463](../../app/Filament/Admin/Resources/Leases/Tables/LeasesTable.php#L463) carries
> a `canView()`-gated `ViewAction`, and its comment explains the disabled-form schema is deliberate
> *"so it cannot drift from the fields that actually exist."* The residual, much weaker, point is that
> a disabled 577-line form is not a Yardi-style one-page abstract. **Do not "fix" this into drift.**

> **Also retired (lead's own error).** "Only 3 of 48 resources have a View page" — misleading. Of the
> **48 admin resources**, all but **Disbursements** and **StockMovements** expose a read path, almost
> all via a modal `ViewAction` rather than a dedicated View page.
>
> *(Two further lead census errors, corrected: there are **48 admin resources**, not 55 — the 55 count
> included the 7 portal resources, a different panel. And there are **17 widgets**, not 1 — that count
> was a `*Widget*.php` filename artefact.)*

---

## 2. Facility management vs the FM specialists

### 2.1 CRITICAL — the value-based approval ladder is never installed in production

- **Remedy class:** EDIT (one line) + health check · **Effort:** S · **Verified:** yes, **with a severity correction**

`ApprovalRulesSeeder` is referenced by exactly one thing —
[DatabaseSeeder.php:14](../../database/seeders/DatabaseSeeder.php#L14), the dev/demo chain.
`atriom:install` seeds `RolesPermissionsSeeder` and `AccountingSeeder` and nothing else
([InstallCommand.php:65,69](../../app/Console/Commands/InstallCommand.php#L65-L69)). So on a real
install `approval_rules` is **empty**, `ApprovalPolicy::permissionFor()` returns null
([:35-39](../../app/Support/ApprovalPolicy.php#L35-L39)), and `canApprove()` returns **true for any
amount** ([:112](../../app/Support/ApprovalPolicy.php#L112)).

> **Severity corrected.** The audit reported this as "returns true for any signed-in user." That
> overstates it: **base RBAC still applies.** I checked all three call sites — the purchase-request
> action is double-gated with `->authorize(fn ($r) => self::canDecide($r))` plus a self-approval
> exclusion ([PurchaseRequestsTable.php:105-108](../../app/Filament/Admin/Resources/PurchaseRequests/Tables/PurchaseRequestsTable.php#L105-L108));
> `WorkOrderPartService` calls `assertMayDecide()` first and blocks self-approval; `DisbursementService`
> re-checks the frozen `required_permission`.

**What is actually lost is the value tiering** — the whole point of FR-CM-11 (spare-part tiers) and
FR-PROC-02 (purchase-request tiers). Anyone holding a module's approve permission approves **any
amount**, and because `required_permission` freezes as `null`, the audit trail cannot even show which
tier was required or that one was bypassed. It is a contractual FRD control that is simply absent in
production, silently.

**And the tests cannot see it: every approval test seeds the ladder itself**, so the suite is green
against a state production never reaches. That is a textbook instance of this project's own standing
rule that a test using inputs no real path produces is green over dead code.

**Fix:** add `ApprovalRulesSeeder` to `atriom:install`, plus an `atriom:health` reference-data check.
`Health::run()` has no such check today.

### 2.2 ~~HIGH — the SLA moat has a trapdoor: the clock only starts if someone clicks Start~~ ✅ FIXED 2026-08-12

- **Remedy class:** EDIT · **Effort:** S · **Verified:** yes · **Shipped:** a second clock. Response runs from creation; resolution runs from acceptance *or from when acceptance was due, whichever came first*, so a job can no longer complete without a deadline and a late acceptance cannot buy extra time. See [module 26 §7c](../modules/26-facility.md). The finding understated it: `open → done` is legal, so the escape was not merely "nobody starts it" but a whole job closing with no clock.

`target_resolution_at` on a work order is written in **exactly one place**: the manual
`open → in_progress` transition, conditioned on `isCorrective()` and `acknowledged_at === null`
([FacilityWorkOrderService.php:79-84](../../app/Services/FacilityWorkOrderService.php#L79-L84)).
I grepped every occurrence — the rest are fillable/casts/log declarations and readers.

An external corrective order that nobody starts therefore has `target_resolution_at = null` forever,
and `isSlaBreached()` requires it to be non-null ([FacilityWorkOrder.php:381](../../app/Models/FacilityWorkOrder.php#L381)).
So the hourly scan, the penalty gate, the table filter and the dashboard card **all skip it
permanently** — and *not clicking Start* becomes a silent discretion to waive a vendor penalty, with
no record that it happened.

**There is also no response SLA at all**: `response_time`, `first_response`, `respond_by`,
`target_response` — **zero hits**. ServiceChannel, Corrigo and Facilio all start the clock at *issue*
and run two clocks (response and resolution). Atriom starts one clock at *acceptance*.

The irony is that the code comment at that line shows careful thought about not *resetting* a
deadline. The gap is the opposite: never *setting* one.

**Also fixed:** `sla_policies.respond_hours` + four `SlaSettings` keys resolve through the
identical three-tier chain, so a property overrides both halves of its SLA in one place. What was
deliberately left out is a monetary penalty for an unanswered job — FR-CM-08 is about a job that ran
late, and whether an unanswered one is separately chargeable is a contract question for the
operator.

### 2.3 HIGH — the rest of the facility list

Verified as reported. ~~**PPM can silently stop generating for a plan, forever**~~ — **fixed
2026-08-12**, all three links in the chain: a renewed COI no longer counts as lapsed
(`HasSupersededDocuments`), a genuinely lapsed one withholds the *assignment* rather than cancelling
the round, and a stuck plan is stamped on its own row and alerts once. The cycle is still not
skipped. See [module 26](../modules/26-facility.md#a-plan-that-cannot-generate-says-so).
The original finding: a throw in `generateFor()` rolls back without advancing `next_due_date`, is
contained per-plan into a `Log::warning` (not `OpsLog`), and the command's non-zero exit goes nowhere
because no `onFailure`/`emailOutput` hook exists anywhere in `routes/console.php` · **PPM work orders have no overdue detection at all** — every scan and widget
filters `->corrective()`, and generated PPM orders carry no assignee, so even the `dueToday` count
reaches nobody · ~~**a superseded vendor COI disqualifies the vendor permanently**~~ — **fixed 2026-08-12**;
`scopeAssignable()` tested any-expired with no latest-per-type notion, so renewing a certificate
correctly *bricked* the contractor · **the
compliance gate covers dispatch only** — a blacklisted vendor can still be awarded a PO
(`PurchaseRequestsTable.php:153` uses a bare `Vendor::query()`), and `docs/modules/12-vendors.md:72`
claims otherwise · **no technician surface** — `/api/v1` is 100% `auth:tenant-api`, `User` has no
`HasApiTokens`, `FacilityWorkOrder` is not `HasMedia` so **there is no completion photo anywhere**,
and no labour-time concept exists.

A full field app is a fair L-sized deferral. **The completion-photo collection and staff device
tokens are S each and worth taking now** — evidence of work done is what a tenant chargeback and a
vendor dispute both rest on.

### 2.4 MEDIUM — facility long tail

No partial goods receipt (no `received_quantity` column) · no 3-way-match tolerance · the
vendor-bill↔contract picker is not asset-constrained, so commitment is consumed cross-property · no
business-hours calendar and **no SLA pause** (`awaiting_tenant` does not stop the clock) · one-shot
escalation · **finalised SLA penalties are effectively invisible** — no resource, report or filter ·
no vendor scorecard · the equipment register has no criticality, warranty or failure codes and no
work-order-history relation manager · no cycle count · low stock never drafts a purchase request ·
**zero H&S domain** — permit-to-work, incident log, statutory certificates and RAMS all verified
absent. *The FRD asks for none of it, so it is a fair deferral — but a real mall operator will ask
for the permit-to-work first.*

---

## 3. The modules Yardi does not cover

### 3.1 CRITICAL — every wizard-created tenant gets the mobile password `password`

- **Remedy class:** BUGFIX · **Effort:** S · **Verified:** yes

[LeaseCreationService.php:152](../../app/Services/LeaseCreationService.php#L152):

```php
'password' => Hash::make($data['password'] ?? 'password'),
```

I grepped the entire Leases `Schemas/` directory and `CreateLease.php` for a password field: **there
is none.** The quick-lease wizard ([LeasesTable.php:444](../../app/Filament/Admin/Resources/Leases/Tables/LeasesTable.php#L444))
calls `create($data)` without one, so the fallback fires every time. `Tenant` is the Sanctum
`tenant-api` auth model — **this is the mobile API credential.**

**Read this together with [03-money-gl §1.4](03-money-gl.md#14-critical--the-pay-demo-endpoint-is-live-in-production).**
Knowing only a tenant's email address, an attacker authenticates to the mobile API as that company and
calls `pay-demo` to mark its invoices paid. Neither finding is theoretical on its own; **chained they
are a remote, low-effort path to destroying a tenant's AR**, and the resulting GL entry is
`Dr Bank / Cr AR` for money that does not exist.

### 3.2 CRITICAL — `asset_owner` has no UI anywhere

- **Remedy class:** WIRE · **Effort:** S–M · **Verified:** yes

The `AssetResource` relation managers are Floors, Units, RentableItems, Staff and Activities
([AssetResource.php:73-77](../../app/Filament/Admin/Resources/Assets/AssetResource.php#L73-L77)) —
**no Owners.** There is no owned-assets surface on the User resource either. So on a real install
there is **no way to record who owns a property, or their `ownership_percentage`** — the input the
entire owner-statement module (module 32) divides by.

Note this contradicts an in-code comment:
[PropertyIsolation.php:182](../../app/Support/PropertyIsolation.php#L182) describes `AssetOwner` as
*"managed via User/Asset relations"*. It is not managed anywhere. **A comment asserting a UI exists is
how this stayed invisible.**

### 3.3 HIGH — HR & payroll is not Egyptian statutory payroll

Three flat percentages, all shipping at 0.0, with **no tax brackets, no personal exemption, and no
insured-wage cap**. This confirms the "4d brackets deferred" note is still accurate, but the framing
matters: what exists is not a simplified payroll, it is an *uncalibrated* one. Related: **payroll
generation silently omits anyone terminated during the period**, so a leaver's final month is never
paid; **adding a payslip line by hand silently discards allowances, ad-hoc deductions, the deduction
note and employer SI**; payroll posts cash out at approval dated the **first of the period month**
with no salaries-payable accrual; an advance installment may take **100% of take-home pay** with no
Egyptian wage-deduction cap; there are no employee bank details and no salary transfer file, so
`payment_method: bank` records an intention nothing can act on; and the employee never sees their own
payslip.

**Recommendation:** either finish Egyptian statutory payroll properly or **descope it for go-live and
say so in the FRD**. Shipping payroll that computes zero tax is worse than not shipping payroll,
because it looks operational.

### 3.4 HIGH — the mobile API authenticates a *company*, the portal authenticates *people*

The portal moved to multi-user `TenantUser` with an `is_admin` write gate. **The mobile API never
did** — it authenticates the `Tenant` row with one shared password, and the `is_admin` distinction
**does not exist there at all**. So the read-only/write separation the portal enforces is absent on
mobile, and every employee of a retailer who has the app has full write access as the company.

Combined with §3.1 this is the single weakest authentication story in the product.

### 3.5 HIGH — the rest

**The marketing fund's `spent_amount` ignores VendorBills and Expenses that debit the same GL
account**, so the owner's over-budget screen is understated by construction — the module-13 UX pass
made over-budget *visible*, against a number that is incomplete · **32 `{module}.delete` permissions
are grantable in the Roles UI and consulted by nothing** (a direct consequence of the correct
never-delete policy — the permissions were left behind) · **five scheduled scans and the Paymob
capture callback hold a row lock across synchronous MailerSend HTTPS calls** — a slow mail provider
becomes a database lock-wait · **monthly billing sends every invoice email inline, with a freshly
rendered PDF, per recipient, inside a 600s job that never retries** · announcements are
all-or-nothing per property with **no floor/zone/category/per-tenant audience, no read receipts, no
urgency class, and are single-language in a bilingual product** — while module 36 got bilingual right
in the same codebase · notification channels are hard-coded per class with no per-user preference,
digest, quiet hours or unsubscribe · **no tenant-side compliance upload** — the insurance chase
notifies staff and never the retailer.

### 3.6 MEDIUM — selected

**The Occupancy Map is the one admin page with no permission gate** — external `vendor` logins can
read the tenant roster and the vacancy rate · **global search is 35 unindexed `LIKE '%…%'` table scans
per query** — correct and self-aware, but unbounded · a tenant admin cannot manage their own portal
users, so every login change is an operator round-trip · the Tenants module has no CRM depth (one
contact, no chains/brands, no prospect state, no credit vetting) · `tenants.type`/`tenants.status` are
DB-level enums against the project's own rule · no delegation, no time-boxed access, no
segregation-of-duties, and the approval ladder covers **3 of ~12 spending paths** · no footfall
counting, so the 5% marketing levy has no attribution story · the campaign↔spend link is write-only ·
no scheduled report delivery and no saved report parameters.

---

## 4. Dead weight to remove before staging

From the non-Yardi audit's delete list, plus the lead's own census. Full proof-of-absence for each is
in the scratchpad report.

| Item | Verdict |
|---|---|
| **The entire demo-payment path** — `RecordDemoPaymentAction`, `DemoPayInvoiceController`, `routes/api.php:159-161`, and the two `payDemo` Filament actions | **REMOVE — the single highest-value deletion in this sweep.** It is both the dead-weight cut and the security fix. |
| `AssetStatementPdfService` | REMOVE — orphaned by the `/owner` panel removal (`cadd3a2`/`464241d`); no caller in `app/`. |
| `App\Mail\InvoiceIssued` | REMOVE — documented dead code; the live path is `InvoiceIssuedNotification`. |
| `payroll_lines.notes` | REMOVE — nothing writes or reads it; superseded by `deduction_note`. |
| `PublicFeedController::CACHE_SECONDS` | REMOVE — zero callers; a second home for a TTL that lives in `MarketingFeedCache::TTL_SECONDS`. |
| `MarketingPost::validUntil()` | REMOVE — zero callers. |
| `admin.announcements.group` (EN + AR) | REMOVE — unreferenced in both locale files. |
| The 32 inert `{module}.delete` permissions | REMOVE, or filter them out of `RoleForm`. |
| `VatReturnService` | **WIRE, do not delete** — see [03-money-gl §2.1](03-money-gl.md#21-the-vat-return-unreachable-and-wrong-when-reached). |
| `DeleteBulkAction` on `PayrollsTable` | Unreachable, but it appears in 26 resources — **sweep it project-wide or leave it**. |

**Judgement calls — flagged, not recommended:** the All-Properties consolidation plumbing (12 files) is
a *documented* decision with a stated trigger (mall #2), so it needs a 5-minute owner ruling rather
than a unilateral deletion · `marketing_spends.marketing_post_id` has no reader but operators are
populating it — **the fix is to read it, not drop it** · `Payroll.allowances` /
`employer_social_insurance` are half-built rather than dead — finish them or state in the doc that
phase 4a is line-only.

---

## 5. At or above benchmark — do NOT touch

- **The date-ranged charge schedule**, and `overlayWindow()`'s plan-then-write in particular.
- **The occupied-vs-leased predicate split** — two genuinely different questions kept apart.
- **The structural GLA exclusion of rentable items.**
- **The three-layer facility model** — Demand (Tenant Requests) / Execution (Work Orders) / Planned
  generator (Service Schedules). This is what every CMMS/IWMS is built on and it is right.
- **The SLA-penalty → AP → GL mechanics**, per-property SLA fallback, and the FR-PPM-07 gate with its
  parent-row-lock design.
- **The perpetual stock ledger** — sign-keyed overdraw guard, weighted average, atomic transfers — and
  the GRNI FIFO sibling re-derive.
- **The procurement state machine** and the part-draw ladder.
- **The COI gate's placement** (dropped from pickers *and* refused at the real gate).
- **The unauthenticated shopper surface** — module-flag 404, hand-written allowlists, one `liveFor()`
  predicate, version+TTL cache. The best-engineered new surface in the product.
- **Property isolation throughout**, and model-level area derivation for zone routing.
- **EN/AR at exact key parity** — 4,153 keys per locale, zero drift, verified by the lead.

---

## 6. Stale documentation this audit found

Beyond the money-side list in [03-money-gl §7](03-money-gl.md#7-stale-documentation-this-sweep-found):

- `docs/benchmarks/yardi/06-atriom-gap-analysis.md:48` — the double-booking "KEEP — do not touch"
  verdict now shields §1.3.
- `docs/modules/04-leases.md:169-171` — says options, trailing proration and holdover billing are
  open. **All three shipped** (`ExerciseLeaseOptionService`, `ConvertLeaseToHoldoverService`,
  `monthsCovered()` driving `CreditUnearnedBillingService`). Reading it would send someone to rebuild
  three finished features.
- `docs/modules/11-tenant-requests.md` — ~10 false statements: wrong command signatures, "not scheduled"
  claims for scheduled commands, a renamed Filament directory.
- `docs/modules/12-vendors.md:72` — claims the blacklist blocks award; it blocks dispatch only.
- `docs/modules/28-approvals.md:3` — says one consumer; there are three.
- `docs/modules/30-areas.md:125` — contradicts the code on auto-assignment.
- `docs/modules/13-marketing.md:120-141` — documents `accrue()`/`accrueMarketingLevy()` methods that no
  longer exist.
- `docs/gap-analysis/competitors/05` and `/06` — six rows now wrong, including **two of that document's
  own "top 5 gaps"** (weighted-average costing, transfers, COI tracking, blacklist guard, violation
  billing — all shipped since 2026-07-18).
- **`CLAUDE.md`'s scheduled-command list omits `inventory:scan-low-stock` and seven others.** The
  actual count is 28. Since this file is the AI/dev entry point, it should be generated by
  `atriom:dump-system-census` rather than hand-maintained.
