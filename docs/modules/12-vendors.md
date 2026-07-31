# Vendors & Contracts

> A vendor is an external service provider, contractor, or supplier (modeled by type: contractor / supplier / service_provider / consultant / other) with contacts and time-bound contracts; contracts expire automatically and may be scoped to a specific property (Asset) for multi-mall operators.

## 1. Purpose & business context

Vendors model the external service partners that Egyptian malls depend on: HVAC contractors, cleaners, electrical suppliers, security services, and consulting firms. The module tracks:
- **Vendor master data:** name, legal name, tax ID, contact details, status (active/inactive/blacklisted).
- **Vendor contacts:** named people within a vendor org with roles, email, phone; one is marked primary (fallback to oldest if none).
- **Vendor contracts:** service agreements with a vendor, optionally bound to a specific property (Asset), with start/end dates, financial value, and a lifecycle (draft → active → expired/terminated).

Operators (Eltizam) manage vendor records and can assign vendors to maintenance requests. The platform automatically expires contracts when their end_date passes. Vendors are **not tenant-scoped**—a single HVAC contractor may serve multiple malls—but individual contracts can be pinned to one property, enabling property-level operators to see only relevant contracts via TenantScope filtering.

## 2. Domain model

| Table | Model | Key Columns | Meaning |
|-------|-------|-----------|---------|
| `vendors` | `Vendor` | `name` (string 200, required) | Vendor display name (e.g., "Cool-Air HVAC"). |
| | | `slug` (string, unique) | URL-safe identifier, auto-generated from name using `Str::slug()` with numeric suffix on collision. Collision-safe even with soft deletes (checks withTrashed()). |
| | | `type` (enum) | One of: `contractor`, `supplier`, `service_provider`, `consultant`, `other`. Default `service_provider`. |
| | | `status` (enum) | One of: `active`, `inactive`, `blacklisted`. Default `active`. Filters available vendors in dropdowns. |
| | | `legal_name` (string 200, nullable) | Official company name for invoicing/compliance. |
| | | `tax_id` (string 50, nullable) | Egyptian tax file number; indexed for lookups. |
| | | `email` (string 200, nullable) | Primary email contact. |
| | | `phone` (string 50, nullable) | Phone number. |
| | | `address` (string 500, nullable) | Physical address. |
| | | `city` (string 100, nullable) | City. |
| | | `notes` (text, nullable) | Free-form notes and audit history. |
| | | `metadata` (JSON, nullable) | Reserved for future integrations (e.g., payment terms, bank details). |
| | | `created_at`, `updated_at`, `deleted_at` | Timestamps; soft delete enabled. |
| `vendor_contacts` | `VendorContact` | `vendor_id` (FK → vendors, NOT NULL, CASCADE DELETE) | Back-reference to vendor. |
| | | `name` (string 200, required) | Contact person's name. |
| | | `role` (string 100, nullable) | Job title (e.g., "Facilities Manager"). |
| | | `email` (string 200, nullable) | Email address. |
| | | `phone` (string 50, nullable) | Phone number. |
| | | `is_primary` (boolean, default false) | Marks this as the default contact. |
| | | `notes` (text, nullable) | Additional notes. |
| | | `created_at`, `updated_at` | Timestamps. |
| `vendor_contracts` | `VendorContract` | `vendor_id` (FK → vendors, NOT NULL, CASCADE DELETE) | Vendor providing the service. |
| | | `asset_id` (FK → assets, nullable, NULL ON DELETE) | Property (mall) this contract covers. Null = vendor contract spans all properties (portfolio-wide). For property-scoped operators, TenantScope filters to show only this property's contracts. |
| | | `reference` (string 100, nullable) | External contract or PO reference (e.g., "PO-2026-001"). Not unique; free-form. |
| | | `name` (string 200, required) | Contract name/title (e.g., "Annual HVAC Maintenance"). |
| | | `status` (enum) | One of: `draft`, `active`, `expired`, `terminated`. Default `draft`. Only `active` contracts are subject to expiry via command. |
| | | `start_date` (date, required) | Effective start. |
| | | `end_date` (date, nullable) | Expiry date (inclusive). Null = open-ended contract (never expires via command). |
| | | `value` (decimal 14,2, nullable) | Contract value (EGP). Must be ≥0 (validated in form). |
| | | `currency` (string 3, default 'EGP') | ISO 4217 code; currently always EGP. |
| | | `scope` (text, nullable) | Description of work/services (e.g., "Quarterly filter replacement, lubrication, and testing"). |
| | | `notes` (text, nullable) | Additional notes or audit trail. |
| | | `created_at`, `updated_at`, `deleted_at` | Timestamps; soft delete enabled. |

**Relationships:**
- `Vendor::contacts()` → `hasMany(VendorContact::class)` (all contacts for a vendor)
- `Vendor::primaryContact()` → returns first with `is_primary=true`, or oldest by creation if none marked (helper method)
- `Vendor::contracts()` → `hasMany(VendorContract::class)` (all contracts)
- `Vendor::activeContractsCount()` → count of contracts with `status='active'` (helper for nav badge)
- `Vendor::maintenanceRequests()` → `hasMany(TenantRequest::class, 'assigned_to_vendor_id')` (tenant requests assigned to this vendor; `MaintenanceRequest` was renamed `TenantRequest`)
- `VendorContact::vendor()` → `belongsTo(Vendor::class)`
- `VendorContract::vendor()` → `belongsTo(Vendor::class)`
- `VendorContract::asset()` → `belongsTo(Asset::class)` (nullable; null = portfolio-wide contract)

## 3. Business rules & invariants

| Rule | Enforcement | Test(s) |
|------|-------------|---------|
| **Vendor slug is unique and collision-safe.** The `booted()` hook generates a slug from name; if it collides with an existing (including soft-deleted) vendor, it appends a numeric suffix (`-2`, `-3`, etc.) until unique. | `Vendor::booted()` creating hook; checks `withTrashed()`. | (implicit in model boot; tested via create flow) |
| **Only active contracts with an end_date are subject to expiry.** Contracts with status=draft/expired/terminated are ignored. Open-ended contracts (end_date=null) never expire. | `ExpireVendorContractsCommand` query: `where('status', 'active').whereNotNull('end_date').whereDate('end_date', '<', today())`. | `VendorScenarioTest::expire_contracts_state_transitions`, `VendorScenarioTest::expire_contracts_end_date_boundary` |
| **Expiry boundary is strict `end_date < today`** (not ≤). A contract ending today remains active; one ending yesterday is expired. | `whereDate('end_date', '<', today())` in ExpireVendorContractsCommand. | `VendorScenarioTest::does_NOT_expire_a_contract_ending_exactly_today`, `does_NOT_expire_a_contract_ending_tomorrow`, `DOES_expire_a_contract_that_ended_yesterday` |
| **Contract expiration is idempotent.** Running the command twice on the same dataset yields no changes on the second run (expired contracts are no longer active, so not queried again). | Command checks `status='active'` before update; second run finds 0 candidates. | `VendorScenarioTest::is_IDEMPOTENT` |
| **Contract value must be non-negative.** Enforced in Filament form validation. | `TextInput::make('value')->minValue(0)`. | `VendorScenarioTest::rejects_a_negative_contract_value` |
| **Asset scoping is strict.** A property-scoped operator (pinned to Asset A) via TenantScope sees only contracts with `asset_id = A` (or null = portfolio). Contracts on other properties are invisible; the relation-manager form's asset picker excludes foreign properties. The synthetic "All Properties" asset is excluded from the picker. | `TenantScope::applyTo(VendorContract::query())` filters by `asset_id = currentAssetId()`. `ContractsRelationManager` uses `TenantScope::selectableAssetOptions()` which excludes synthetic ALL row. Form disables & defaults picker when scoped to a real property. | `VendorScenarioTest::a_manager_pinned_to_property_A_sees_A's_contract_but_never_B's`, `the_asset_picker_offers_only_A_to_a_manager_pinned_to_A`, `offers_BOTH_real_properties_to_super_admin_in_the_All_Properties_view`, `locks_the_asset_picker_to_the_pinned_property` |
| **Compliance / COI gate.** A **blacklisted/inactive** vendor, or one whose insurance (**COI**, `coi_expires_at`) has **lapsed**, cannot be dispatched to maintenance work. Assignment-time only — an order whose vendor's COI expires *later* isn't retroactively broken; a vendor with **no COI on file is still assignable** (v1 doesn't force a cert on every existing vendor; blacklist to hard-block). Compliance lives on the SHARED vendor (one cert covers every mall). The COI document is a **private** media collection (`useDisk('local')`). | The single server-side gate is `MaintenanceWorkOrder::saving()` (throws `DomainException` when `vendor_id` is dirty + `! Vendor::isDispatchable()`); all three module-26 vendor pickers filter to `Vendor::assignable()` / `assignableOptions()`. | `VendorComplianceGateTest` (blacklisted/expired blocked, compliant/no-COI allowed, scope excludes, no retroactive block, private disk) |
| **Primary contact fallback.** If no contact is marked `is_primary=true`, `Vendor::primaryContact()` returns the oldest contact by creation date. | `primaryContact()` method: `where('is_primary', true)->first() ?? contacts()->oldest()->first()`. | (implicit in Filament display; vendor detail page uses this) |
| **Activity logging.** Vendor and VendorContract changes are logged via Spatie ActivityLog; only specified fields are logged (`name`, `type`, `status`, `email`, `phone`, `tax_id` for Vendor; `name`, `status`, `value`, `start_date`, `end_date` for VendorContract). | `LogsActivity` trait with `getActivitylogOptions()` specifying `logOnly(...)` and `useLogName()`. | (implicit audit trail in activities relation manager) |

## 4. Lifecycle / state machine

| Status | Entry point | Allowed transitions | Exit rule / immutability |
|--------|-------------|-------------------|--------------------------|
| **draft** | New contract created in Filament relation manager with explicit status=draft. | → `active`, `terminated` | Awaiting activation. Not yet effective. |
| **active** | Contract activated (explicit status set on creation or via edit). | → `expired` (automatic via command when end_date passes), `terminated` (manual) | Effective; tracked in nav badge (expiring within 30 days). Automatic expiry is only transition triggered by system. |
| **expired** | Automatic: `ExpireVendorContractsCommand` transitions status='active' contracts past their end_date. Or manual via edit. | (terminal) | Implicit end-of-life. No further action. |
| **terminated** | Manual edit to terminate a contract early. | (terminal) | Explicit early closure; captured in notes for audit. |

**Expiry automation:**
- Scheduled command `vendors:expire-contracts` runs daily at **02:30 UTC** (see `routes/console.php`).
- Command is idempotent; safe to run multiple times per day or in tests.
- Operator can manually expire a contract via Filament form edit → status = expired.

**Notes:**
- No approval/pending state; contracts are draft until explicitly activated.
- Termination is manual only (no auto-trigger).
- `end_date=null` implies perpetual contract; never auto-expires.

## 5. Services, jobs & scheduled commands

### ExpireVendorContractsCommand

**Signature:** `ExpireVendorContractsCommand::handle(): int`

**Registered as:** `vendors:expire-contracts {--dry-run}`

**Idempotency:** Yes. Safe to call multiple times; updates only rows with status='active' and end_date < today. On second run, those rows are no longer 'active', so query returns 0.

**Transaction:** Yes, atomic bulk update via Eloquent.

**Locking:** None. No explicit pessimistic locking; assumes minimal contention (command runs once daily at 02:30).

**When it runs:** 
- Daily at 02:30 UTC via Laravel Scheduler (see `Schedule::command('vendors:expire-contracts')->dailyAt('02:30')` in `routes/console.php`).
- Callable manually: `php artisan vendors:expire-contracts`.
- Testable with `--dry-run` flag: prints candidates without writing.

**Behavior:**
1. Query all rows: `VendorContract::where('status', 'active').whereNotNull('end_date').whereDate('end_date', '<', today())`.
2. If none found, return SUCCESS with message "No active vendor contracts past their end_date."
3. If `--dry-run` flag:
   - Print each candidate: contract ID, reference, vendor name, contract name, end_date.
   - Return SUCCESS without updating.
4. Otherwise:
   - Update all candidates to `status='expired'`.
   - Return SUCCESS with count: "Expired N vendor contract(s)."

**Related:** None. Standalone command with no service layer; updates directly via Eloquent.

---

### Compliance documents — `vendor_documents` (module 12b)

`vendors.coi_expires_at` modelled exactly **one** document. Before an Egyptian entity may legally engage and pay a supplier it needs several, each on its own expiry clock: insurance (COI), بطاقة ضريبية (tax card), سجل تجاري (commercial register), شهادة تأمينات اجتماعية (social-insurance certificate — the principal carries liability for a subcontractor's unpaid social insurance). The three COI columns **moved into** `vendor_documents` (data + certificate files migrated across, `vendors` columns dropped) so there is exactly one source of truth, not two mechanisms for one concept.

- **Blocking vs non-blocking.** `VendorDocument::BLOCKING_TYPES` (currently just insurance) is the single place that decides which lapse *stops dispatch*. A lapsed insurance certificate removes the vendor from every picker (`Vendor::assignable()` now reads `whereDoesntHave('documents', blocking+expired)`); a lapsed tax card is a finance-side compliance problem chased loudly but never blocking emergency work.
- **The chase — `vendors:scan-document-expiry`** (daily 02:40). For every document inside the 30-day window (`VendorDocument::scopeNeedsAttention`), resolve `expiring`/`expired` and notify. Idempotent via the **two-column stamp** on the *document* (`alert_stage` + `alert_for` = the expiry it fired for): a re-run never re-nags, `expiring → expired` escalates once, and **renewing a document re-arms its cycle by itself**. Lock-safe (stage re-checked inside the row lock), per-document containment, delivery wrapped so a failed send warns but still stamps.
- **Recipients** come from *engagement* (vendors are a shared catalog): staff of the assets where the vendor holds an active contract, portfolio roles otherwise.
- **One scope, three surfaces:** the scan, the **Action Required** card (`vendor_documents`), and the Vendors table filter **"Document lapsed / lapsing"** all read `Vendor::documentsNeedAttention()`, so nag, count and list can't disagree.

**Filament:** `DocumentsRelationManager` on the vendor (replaces the three fixed COI fields on the form); the table badge names the *consequence*, not just the date.
**Tests:** `tests/Feature/Regression/VendorDocumentAlertingTest.php`, `tests/Feature/VendorComplianceGateTest.php`.

---

### Contract lifecycle — commitment, renewal notice, change orders (module 12b)

**Commitment tracking.** `vendor_contracts.value` used to be decorative: no bill was tied to its contract, so a EGP 500k contract could quietly absorb EGP 5m of bills. `vendor_bills.vendor_contract_id` (**nullable** — ad-hoc call-outs have none) closes it:

| Method | Meaning |
|---|---|
| `billedToDate()` | gross invoiced, **excluding cancelled bills** (withdrawn ≠ incurred) |
| `effectiveValue()` | `value` + every approved change order |
| `remainingValue()` | `effectiveValue() − billedToDate()`; **negative** once over-run |
| `isOverCommitted()` | `effectiveValue() > 0 && remainingValue() < 0` |

Surfaced as **Committed / Billed / Remaining** columns (remaining red when over-run) + a "View working" action modal, and a live helper on the bill's *Under contract* picker spelling out the arithmetic. **A flag, not a block** — the failure worth preventing is an over-run nobody can *see*.

**Change orders — `vendor_contract_amendments`.** Without them the over-run flag couldn't tell an approved uplift from an uncontrolled over-run — both showed red, which teaches operators to ignore the flag. A **signed** `value_delta` (descoping allowed) moves `effectiveValue()`, with a dated, attributed, reasoned audit trail. Recorded via the **"Add change order"** action on the contracts list (double-gated, `visible()` + `abort_unless`); **no edit action** — a change order is a signed instrument, corrected by a compensating one, not a silent rewrite.

**Renewal *notice* — `vendors:scan-contract-renewals`** (daily 02:45). `vendors:expire-contracts` fires on `end_date`, by which point every decision is already made for you. The date a contract manager works to is the **notice deadline** = `end_date − notice_period_days`, stored in the indexable `notice_deadline` column (kept in step by `VendorContract::saving()`, because "date minus a *column* of days" has no portable SQL). `auto_renews` changes the alert from "line up a replacement" to the harder "**serve notice by X or you're committed for another term**". Idempotent via `renewal_alert_for` (the end_date it fired for); re-signing re-arms it. Shares `VendorContract::noticeDue()` with the Action Required card and the **"Renewal notice due"** filter.

**Tests:** `tests/Feature/Regression/VendorContractLifecycleTest.php`, `VendorContractCommitmentTest.php`.

---

### Withholding tax on vendor payments — خصم وإضافة (module 12b)

Atriom paid vendors **gross**, which is non-compliant with Income Tax Law 91/2005 art. 59 — the operator must withhold at source and remit to the ETA, and the un-withheld amount otherwise becomes their own liability. This is the AP-side twin of the AR VAT.

- **Mechanics.** `vendor_bill_payments.amount` stays **gross** (settles the payable in full — `VendorBill::recompute()` untouched); `withholding_amount` is the slice owed to the ETA; net cash out = `amount − withholding_amount`. GL: **Dr Accounts Payable (gross) / Cr Bank (net) / Cr Withholding Tax Payable `21303001` (withheld)** — the WHT leg only appears when > 0.
- **Settings-driven, never hardcoded** (`App\Support\WithholdingTax` over `TaxSettings`): the statutory rate varies by payment nature and is revised by the ETA, so a compiled constant would look official and be wrong. **Off by default** (`wht_enabled`); per-vendor `withholding_tax_rate` overrides the `wht_default_rate`, where **`0` = exempt ≠ `null` = use default**. Clamped to the payment so a mis-set rate can't drive cash negative.
- **UX.** The payment modal shows a live *"what the bank will pay"* breakdown; the success toast reports **net paid + withheld**, not just gross. Configured at **/admin/settings → Tax**.
- **GL proof** is driven through the **real `accounting:sync-ledger` sweep**, per the registry rule — not `LedgerPoster` directly.

**Tests:** `tests/Feature/Regression/VendorWithholdingTaxTest.php` (incl. the sweep tie-out).

---

### Vendor lifecycle in Filament (no service layer)

Creation, edit, and delete are handled directly in Filament pages + relation managers. No service class is needed because vendor records are simple master data (no cascading derived state like leases or invoices).

**Key operations:**
- **Create vendor:** `CreateVendor` page → saves via model fillable + validation rules from `VendorForm`.
- **Edit vendor:** `EditVendor` page → updates via form.
- **Create contract:** `ContractsRelationManager::create` table action → saves via form validation. Asset picker is scoped by `TenantScope::selectableAssetOptions()`.
- **Edit contract:** `ContractsRelationManager::edit` → updates contract.
- **Delete:** Soft-delete via `SoftDeletes` trait; hard-delete restricted to super_admin.

**Model hooks (not "none"):** `Vendor::creating()` generates the collision-safe slug; `VendorContract::saving()` maintains the derived `notice_deadline` (`end_date − notice_period_days`, kept in step because "a date minus a *column* of days" has no portable SQL). No cascading service is needed for the master data itself.

### VendorBillService (Accounts Payable)

AP has a real service because a vendor bill posts to the GL and is settled by payments. `VendorBillService`:
- **`approve()`** — draft → approved (GL-postable). **Guards `bill_date`'s period** with `App\Support\PostingDate`: a draft approved after its bill-date month has closed can't recognise the payable (silent-strand class F-89/F-93), so approval is refused until the still-editable draft is re-dated into an open period.
- **`recordPayment()`** — lock-safe, caps the amount at the balance, computes Egyptian **withholding tax**, and **guards `payment_date`'s period** the same way (the AP mirror of the AR receipt guard). A back-dated payment into a closed period is refused, not silently stranded.
- **`cancel()`** — refuses if any cash was paid; **releases any applied SLA penalty back to `final`** (a cancelled bill owes nothing, so an applied penalty would otherwise be silently dropped — still owed, but no longer chargeable), then zeroes the balance via `recompute()`'s cancelled branch.

## 6. Filament resources & key fields

> **12b additions not detailed below** (this section predates them): the vendor edit page also carries a **`DocumentsRelationManager`** (compliance certs, private disk) and the contracts RM gained an **`amend`** (change-order) action + Committed/Billed/Remaining columns; and there is a **separate `VendorBillResource`** (`/admin/vendors/bills`) for AP — property-scoped (`asset_id` guarded by `assertAssetInScope` on create+edit), with `approve` / `record_payment` (withholding-tax breakdown) / `cancel_bill` actions, all double-gated. All vendor relation-manager write actions gate the predicate in both `visible()` and `authorize()`.

### VendorResource (Admin)

**Route:** `/admin/vendors`

**Pages:** ListVendors, CreateVendor, EditVendor

**Relation managers:** ContactsRelationManager, ContractsRelationManager, ActivitiesRelationManager

**Tenant scoping:** `$isScopedToTenant = false` — vendors are **global** (not multi-tenant-scoped by property). However, vendor contracts are scoped via asset_id + TenantScope query filtering.

**Navigation:** Group "Operations" (alongside Maintenance Requests, Utility Meters). Icon: BuildingOffice2.

**Navigation badge:** Count of contracts expiring within 30 days (scoped to current property if pinned). Tooltip: "Vendor contracts expiring soon."
- Query: `VendorContract::where('status', 'active').whereNotNull('end_date').whereDate('end_date', '<=', now()->addDays(30)).whereDate('end_date', '>=', now())` + TenantScope filter.
- **Important:** If a property-scoped operator is active, badge counts only that property's expiring contracts. Portfolio-wide (ALL asset) bypasses filter and counts all.

**Permissions (RBAC):** Gated by trait `RoleGatedActions`. Module = "vendors". Permissions:
- `vendors.view` → `canViewAny()`, `canView($record)`
- `vendors.create` → `canCreate()`
- `vendors.edit` → `canEdit($record)`, `canRestore($record)`
- `vendors.delete` → (not used; only super_admin can delete, hardcoded in trait)

The 'operations' role has all three (view, create, edit).

---

### VendorForm (Schemas/VendorForm.php)

**Section: Vendor Details** (2-column grid)
- `name` (TextInput, required, maxLength 200)
- `legal_name` (TextInput, maxLength 200)
- `type` (Select, required, default 'service_provider', enum: contractor/supplier/service_provider/consultant/other, native=false)
- `status` (Select, required, default 'active', enum: active/inactive/blacklisted, native=false)
- `tax_id` (TextInput, maxLength 50)
- `email` (TextInput, email validation, maxLength 200)
- `phone` (TextInput, tel validation, maxLength 50)
- `city` (TextInput, maxLength 100)
- `address` (Textarea, rows 2, full-width)

**Section: Notes** (collapsible, collapsed by default)
- `notes` (Textarea, rows 3, full-width)

---

### VendorsTable (Tables/VendorsTable.php)

**Columns:**
- `name` (TextColumn, searchable, bold)
- `type` (TextColumn, badge, gray background, i18n label from `admin.enums.vendor_type.{type}`)
- `phone` (TextColumn, copyable, placeholder '—')
- `email` (TextColumn, copyable, placeholder '—')
- `active_contracts_count` (TextColumn, badge, info color) — count of contracts with status='active' (loaded via withCount in getEloquentQuery)
- `status` (TextColumn, badge with color mapping: active=success, blacklisted=danger, else=gray, i18n label)

**Filters:**
- Type (SelectFilter, enum)
- Status (SelectFilter, enum)
- TrashedFilter (show soft-deleted rows)

**Actions:**
- EditAction (visible if `VendorResource::canEdit($record)`)
- BulkActionGroup → DeleteBulkAction (visible if `VendorResource::canDeleteAny()`, which requires both super_admin AND $bulkDeletable=true; currently false for VendorResource)

**Default sort:** name (ascending)

**Empty state:** Icon BuildingOffice2, heading, description, CreateAction CTA.

---

### ContactsRelationManager

**Relationship:** `contacts` (hasMany VendorContact)

**Title:** "Vendor Contacts"

**Form (Schema, 2 columns):**
- `name` (TextInput, required, maxLength 200, label 'Contact person')
- `role` (TextInput, maxLength 100)
- `email` (TextInput, email validation, maxLength 200)
- `phone` (TextInput, tel validation, maxLength 50)
- `is_primary` (Toggle, full-width)

**Table columns:**
- `name` (TextColumn, bold)
- `role` (TextColumn, gray, placeholder '—')
- `phone` (TextColumn, copyable, placeholder '—')
- `email` (TextColumn, copyable, placeholder '—')
- `is_primary` (IconColumn, boolean icon)

**Actions:**
- CreateAction (label "Add contact")
- EditAction
- DeleteAction

**Default sort:** is_primary desc (primary contact first)

---

### ContractsRelationManager

**Relationship:** `contracts` (hasMany VendorContract)

**Title:** "Vendor Contracts"

**Form (Schema, 1 column with 2 main sections):**

*Section 1: Contract details (2-column grid)*
- `reference` (TextInput, maxLength 100, label 'Reference')
- `name` (TextInput, required, maxLength 200, label 'Name')
- `status` (Select, required, default 'draft', enum: draft/active/expired/terminated, native=false, i18n label)
- `asset_id` (Select, label 'Asset')
  - **Scoping logic:** Uses `TenantScope::selectableAssetOptions()` to populate options (excludes synthetic ALL asset).
  - **Behavior when scoped to a real property:** Disabled (form attribute `disabled: true`) and defaulted to `currentAssetId()`.
  - **Behavior when scoped to ALL or no tenant:** Enabled and populated with all real properties.
  - **Dehydrated:** true (write disabled field value).
- `start_date` (DatePicker, required, native=false)
- `end_date` (DatePicker, native=false, nullable)
- `value` (TextInput, prefix 'EGP', numeric, minValue 0)
- `currency` (TextInput, default 'EGP', maxLength 3, hidden in normal form flow)

*Section 2: Notes (collapsed)*
- `scope` (Textarea, rows 3, label 'Description', full-width)
- `notes` (Textarea, rows 2, label 'Notes', full-width)

**Table columns:**
- `reference` (TextColumn, monospace, xs size, placeholder '—')
- `name` (TextColumn, bold, searchable)
- `asset.name` (TextColumn, gray, placeholder 'Portfolio' = null/portfolio-wide)
- `start_date` (TextColumn, date format 'd/m/Y', sortable)
- `end_date` (TextColumn, date format 'd/m/Y', sortable, placeholder '—')
- `value` (TextColumn, money format 'EGP', right-aligned)
- `status` (TextColumn, badge with color mapping: active=success, expired/terminated=gray, draft=warning, i18n label)

**Filters:**
- SelectFilter by status

**Actions:**
- CreateAction (label "Add contract")
- EditAction
- DeleteAction

**Default sort:** start_date desc

## 7. Notifications & integrations

**Notifications:** None currently. Vendors do not trigger email/SMS notifications to users.

**Integrations:** None currently. Vendors are internal master data, not connected to external systems (Paymob, ETA, etc.). Future integrations (e.g., vendor payment processing) would be added here.

**Request/work-order assignment:** `TenantRequest::assignedVendor()` (formerly `MaintenanceRequest`) links a request to a vendor via `assigned_to_vendor_id`; the actual facility **dispatch** is a `MaintenanceWorkOrder`, whose `saving()` hook is the compliance gate (see §9). Assignment is activity-logged; the vendor picker filters to active/dispatchable vendors.

## 8. Extension points — how to change/extend SAFELY

### To add a new vendor field:

1. **Add column to migration** (new migration file in `database/migrations/`).
2. **Update model fillable array** in `Vendor` or `VendorContract`.
3. **Update form schema** in `VendorForm` or `ContractsRelationManager` → add field to appropriate section.
4. **Update table** in `VendorsTable` or `ContractsRelationManager` → add TextColumn if needed for display.
5. **Update activity logging** in `getActivitylogOptions()` if the field should be audited.
6. **Do NOT break invariants:**
   - Slug uniqueness and collision logic remain intact.
   - Do not add a non-nullable FK without a default or cascade rule.
   - Do not add a field that changes contract expiry logic (reserved to `end_date` only).

### To add a new contract status:

1. **Update migration enum** (add new status to `vendor_contracts.status` enum).
2. **Update model casts/validation** if needed.
3. **Update form Select options** in `ContractsRelationManager::form()` to include new status in dropdown.
4. **Update table badge color mapping** in `ContractsRelationManager::table()` if needed.
5. **Update `ExpireVendorContractsCommand`** if the new status affects expiry logic (e.g., add to exclusion list if it's terminal).
6. **Add test** in `tests/Feature/Scenarios/VendorScenarioTest.php` to verify command ignores the new status if needed.
7. **Update nav badge logic** in `VendorResource::getNavigationBadge()` if new status affects "expiring soon" count.

### To add a new permission or restrict access by role:

1. **Add permission to `RolesPermissionsSeeder::PERMISSIONS`** (e.g., "vendors.delete").
2. **Assign to role** in `PERMISSION_GROUPS` array.
3. **Update `RoleGatedActions` trait** if permission logic is non-standard (currently only view, create, edit, delete are supported).
4. Note: Delete is hardcoded to super_admin only; changing this requires trait override.

### To schedule a recurring vendor task:

1. **Add new command** in `app/Console/Commands/` (e.g., `VendorRenewalReminderCommand`).
2. **Register in `routes/console.php`** with desired schedule (see `Schedule::command('vendors:expire-contracts')->dailyAt('02:30')`).
3. **Add test** in `tests/Feature/Console/` to verify command behavior.
4. Ensure command is idempotent and handles errors gracefully (exit SUCCESS even if no rows affected).

### To change contract expiry logic:

1. **Only modify `ExpireVendorContractsCommand::handle()`** — the single source of truth for expiry.
2. **Update the query** to reflect new conditions (e.g., add a contract-type filter, a vendor-status filter, etc.).
3. **Add comprehensive tests** in `VendorScenarioTest.php` to verify new behavior and that old behavior still works.
4. **DO NOT** change expiry in an observer or event listener; the command is scheduled and deterministic.
5. **DO NOT** add expiry logic to a service class; it is a scheduled task, not a transactional operation.

## 9. Gotchas, edge cases & recently-fixed bugs

### Slug generation collision safety

**Risk:** If two vendors are created with the same name in rapid succession, or if one is soft-deleted and recreated with the same name, collisions can occur.

**Mitigation:** `Vendor::booted()` checks `withTrashed()` to detect soft-deleted vendors; it appends a numeric suffix (`-2`, `-3`, etc.) until unique. This is safe even if the original is deleted—the slug space is reserved.

**Test:** Implicit in create flow; explicit in test if slug collision is encountered.

---

### Property scoping and the synthetic "All Properties" asset

**Risk:** If a property-scoped operator is active and viewing contracts, the contract list must exclude the synthetic ALL asset. Similarly, the asset picker in the contract form must never offer ALL as an option.

**Mitigation:**
- `TenantScope::selectableAssetOptions()` explicitly excludes synthetic ALL: `!$a->isAllProperties()`.
- `VendorResource::getNavigationBadge()` checks `TenantScope::currentAssetId()` which returns null for ALL, bypassing the property filter.
- Test: `VendorScenarioTest::the_asset_picker_offers_only_A_to_a_manager_pinned_to_A` verifies no ALL in options.

---

### Idempotency of ExpireVendorContractsCommand

**Risk:** If the command is run twice in quick succession, it could double-update rows or cause log spam.

**Mitigation:** The query explicitly checks `status='active'`. Once a row is expired, it is no longer active, so the second run skips it. The command is **fully idempotent**.

**Test:** `VendorScenarioTest::is_IDEMPOTENT` — first run expires contract, second run finds nothing.

---

### Open-ended contracts (end_date = null)

**Risk:** A contract with `end_date=null` will never expire via the command, which is the intended behavior. However, if an operator forgets to set an end_date, the contract becomes perpetual with no audit warning.

**Mitigation:** Filament form does not require `end_date` (it is nullable). Operator must explicitly leave it blank for open-ended. Form label is "End" (optional context). No validation forces end_date.

**Best practice:** Document in training that multi-year or indefinite contracts must have `end_date=null` set explicitly; otherwise, set a realistic expiry date.

---

### End_date boundary: `<` not `≤`

**Risk:** A contract ending today (2026-06-01) should remain active on that day, only expiring tomorrow. Using `<=` would expire it prematurely.

**Mitigation:** Command uses strict `<` (less-than): `whereDate('end_date', '<', today())`.

**Test:** `VendorScenarioTest::does_NOT_expire_a_contract_ending_exactly_today`, `DOES_expire_a_contract_that_ended_yesterday`.

---

### Activity logging and sensitive data

**Risk:** Vendor tax_id, email, phone are logged. If audit logs are exported, sensitive contact data is visible.

**Mitigation:** Activity logs are stored in the `activity_log` table with `user_id` and timestamp. Access to activity logs is controlled by Filament permissions (typically view-only for auditors). Sensitive data (bank details, secret keys) should **not** be stored in Vendor fields; use `metadata` JSON if privacy controls are needed in the future.

---

### Concurrent contract updates

**Risk:** If two operators edit the same contract simultaneously, one update could be lost (race condition).

**Mitigation:** Filament uses optimistic locking (last-write-wins). No pessimistic database locking is in place. For high-concurrency scenarios, add a `version` column or use a service with explicit locking.

**Workaround:** None currently. Assume vendor data is low-contention.

---

### Primary contact fallback

**Risk:** If all contacts are deleted (cascade from vendor delete), `primaryContact()` returns null. If multiple contacts exist but none is marked `is_primary=true`, the oldest is returned. Operator must mark one explicitly for predictable results.

**Mitigation:** `primaryContact()` helper provides fallback (oldest by creation). It is used only in Vendor detail views and emails (future). Operator can toggle `is_primary` flag in relation manager to designate the primary contact.

**Test:** Implicit in Filament contact manager behavior.

---

### Nav badge 30-day window scoping

**Risk:** The nav badge counts contracts expiring within 30 days (`<= now()->addDays(30)` AND `>= now()`). If TenantScope is active for a property but the query accidentally includes contracts from other properties, the badge becomes misleading.

**Mitigation:** `VendorResource::getNavigationBadge()` explicitly applies the TenantScope filter:
```php
if ($assetId = TenantScope::currentAssetId()) {
    $query->where('asset_id', $assetId);
}
```
When `currentAssetId()` is null (ALL asset or no tenant), the filter is skipped and the badge counts all properties. This is correct—portfolio-wide operators should see the full count.

**Test:** `VendorScenarioTest::the_nav_badge_counts_only_the_scoped_property's_soon_expiring_contracts`.

---

### Contract value precision (decimal 14,2)

**Risk:** Financial values are stored as `decimal(14, 2)`, which is 12 integer digits + 2 decimal places. Large contracts (> 999,999,999,999 EGP) truncate silently.

**Mitigation:** Form validates `numeric` and `minValue(0)`. No max-value constraint. For Egyptian mall contracts, 14 digits is more than sufficient (even multi-million-dollar annual contracts fit).

**Test:** `VendorScenarioTest::rejects_a_negative_contract_value` (minValue enforcement).

---

### Vendor dispatch is compliance-gated (superseding the old "assignment is independent" note)

A non-dispatchable vendor **cannot be put on a facility job.** The single server-side gate is `MaintenanceWorkOrder::saving()`, which throws a `DomainException` when `vendor_id` is dirty and `! Vendor::isDispatchable()` (blacklisted/inactive, or a **blocking** document — insurance/COI — has lapsed). Every work-order vendor picker filters to `Vendor::assignableOptions()` / `scopeAssignable()`, and the tenant-request picker filters `status='active'`, so a blacklisted vendor is never offered for triage assignment either. See §3's compliance-gate row. (Assignment-time only — an order whose vendor's COI lapses *later* isn't retroactively broken; a vendor with no COI on file is still assignable, blacklist to hard-block.)

## 10. Tests & related modules

### Test files

- **`tests/Feature/Scenarios/VendorScenarioTest.php`** (385 lines)
  - State transitions (draft/active/expired/terminated, draft/terminated/null-end_date are untouched)
  - Idempotency (second run finds nothing)
  - Boundary testing (end_date `<` today, not `<=`)
  - Property scoping via TenantScope (manager pinned to A sees only A's contracts)
  - Asset picker scoping (offers only real properties, excludes ALL)
  - Nav badge property scoping
  - Form validation (minValue 0 for value field)

- **`tests/Feature/Console/ExpireVendorContractsCommandTest.php`** (87 lines)
  - Expires active contracts past end_date
  - `--dry-run` reports without writing
  - Clean exit when no candidates

**Module 12b + AP tests** (the doc's original list predates these):
- `tests/Feature/Services/VendorBillTest.php` — AP lifecycle (approve → pay → paid), `recompute()` derivation, GRNI clearing.
- `tests/Feature/Regression/VendorBillClosedPeriodTest.php` — **closed-period guard** on bill create/edit **and** (added this sweep) on the payment + approve paths.
- `tests/Feature/Regression/VendorWithholdingTaxTest.php` — WHT arithmetic + the GL sweep tie-out (Dr AP / Cr Bank / Cr WHT-Payable).
- `tests/Feature/Scenarios/SlaPenaltyChargeScenarioTest.php` — SLA penalty applied to a bill (FR-CM-08), detach/waive re-derive, and (added this sweep) **cancel releases the applied penalty**.
- `tests/Feature/Regression/VendorContractLifecycleTest.php` + `VendorContractCommitmentTest.php` — commitment (billed/effective/remaining/over-committed), append-only change orders, renewal notice.
- `tests/Feature/Regression/VendorDocumentAlertingTest.php` + `tests/Feature/VendorComplianceGateTest.php` — document expiry chase (two-column stamp) + the dispatch compliance gate.
- `tests/Feature/Resources/VendorBillResourceTest.php`, `tests/Feature/VendorRoleTest.php` — resource + RBAC.
- GRNI: `tests/Feature/Regression/GrniClearingTest.php`, `GrniReachableAndCappedTest.php`, `PurchaseReceiptLedgerTest.php`.

### Related modules

- **Maintenance Requests** (`docs/modules/11-maintenance.md` when available)
  - Vendors can be assigned to maintenance requests via `assigned_to_vendor_id`.
  - Assignment is one-to-many; a vendor services many requests.

- **Assets / Properties** (`docs/modules/01-assets.md`)
  - VendorContract.asset_id links to Asset (property).
  - TenantScope uses Asset to filter contracts for property-scoped operators.
  - Synthetic "All Properties" asset is excluded from contract-scoping picker.

- **Operations Group** (Filament navigation)
  - Vendors appear in the "Operations" group alongside Maintenance Requests and Utility Meters.
  - Gated by 'operations' role or explicit 'vendors' permissions (view, create, edit).

---

## Audit references

- **Command description:** "Transition active VendorContract rows past their end_date to status=expired (audit M15 F-58 / D-43)."
  - M15, F-58, D-43 refer to internal audit/spec documents; retained for traceability.

---

## Deletion policy

Operator decision 2026-07-31, following Yardi/MRI/Entrata: a record that carries history is
**refused**, not warned about — the damage lands on the reports and audit trail that referenced
it, none of which are in front of whoever clicks the button. The single register is
[`App\Support\DeletionPolicy`](../../app/Support/DeletionPolicy.php); `DeletionPolicyConformanceTest` fails the build if a model here ships unclassified or a Delete
button reappears on a money record.

| Model | Rule | Instead / why |
|---|---|---|
| `Vendor` | **Only while unreferenced** — blocked by `bills`, `contracts`, `maintenanceRequests`, `documents` | set the vendor to inactive (or blacklisted) — it disappears from every assignment picker without losing its bills |
| `VendorBill` | **Never deletable** | cancel the bill |
| `VendorBillPayment` | **Never deletable** | void the payment — money left the bank |
