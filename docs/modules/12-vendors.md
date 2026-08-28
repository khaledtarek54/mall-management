# Vendors & Contracts

> A vendor is an external service provider, contractor, or supplier (modeled by type: contractor / supplier / service_provider / consultant / other) with contacts and time-bound contracts; contracts expire automatically and may be scoped to a specific property (Asset) for multi-mall operators.

> **The supplier register exports (2026-08-23).** Vendors and properties were the two resources an
> operator could import INTO and never export OUT of — a one-way door, and the reason a custom field
> on a vendor could be recorded and never taken away. `VendorExporter` leads with `code`, the
> identity `VendorImporter` dedups on, so a file exported here can be re-imported. The gate is the
> resource's own `canViewAny()` through `App\Support\Exports` — never a permission of its own,
> because the FRD restricts import and widens export. Read as an authorization question it is not
> one: Filament exports `getTableQueryForExport()`, the resource's own scoped query with the
> operator's filters applied, so an export can never return a row the list would not.

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
| | | `currency` (string 3, default 'EGP') | **EGP only, enforced** — `App\Support\ValueSets` refuses anything else on every save. Not a placeholder for multi-currency: see the gotcha below. |
| | | `scope` (text, nullable) | Description of work/services (e.g., "Quarterly filter replacement, lubrication, and testing"). |
| | | `notes` (text, nullable) | Additional notes or audit trail. |
| | | `created_at`, `updated_at`, `deleted_at` | Timestamps; soft delete enabled. |

**Relationships:**
- `Vendor::contacts()` → `hasMany(VendorContact::class)` (all contacts for a vendor)
- `Vendor::primaryContact()` → returns first with `is_primary=true`, or oldest by creation if none marked (helper method)
- `Vendor::contracts()` → `hasMany(VendorContract::class)` (all contracts)
- `Vendor::activeContractsCount()` → count of contracts with `status='active'` (helper for nav badge)
- `Vendor::tenantRequests()` → `hasMany(TenantRequest::class, 'assigned_to_vendor_id')` (tenant requests assigned to this vendor; `MaintenanceRequest` was renamed `TenantRequest`, and the relation with it)
- `VendorContact::vendor()` → `belongsTo(Vendor::class)`
- `VendorContract::vendor()` → `belongsTo(Vendor::class)`
- `VendorContract::asset()` → `belongsTo(Asset::class)` (nullable; null = portfolio-wide contract)

## 3. Business rules & invariants

> **Withholding is a tax code, not a typed rate (2026-08-12).** `vendors.withholding_tax_code` names
> which of the four natures on the operator's sheet (0.5 · 1 · 3 · 5%) applies to this supplier, and
> `vendors.withholding_exempt` says they are outside Egyptian withholding altogether. Those were one
> overloaded percentage column, where null meant "use the default" and an explicit 0 meant "exempt" —
> a distinction that needed a paragraph of comment everywhere it was read, and that a spreadsheet
> could not show at all.
>
> `TaxSettings::wht_default_rate` is gone; `wht_default_tax_code` replaces it and **ships empty**,
> because there is no defensible default nature and an empty one withholds nothing. Rates resolve for
> the PAYMENT's date and drop the catalogue's negative sign (the sheet writes "WH -1%" because the
> tax comes off what is paid; a leaked sign would pay the supplier more). The **vendor import** now
> carries `withholding_tax_code` + `withholding_exempt` and validates the code against the catalogue
> — a CSV carrying "2" is refused rather than quietly withholding a rate the operator's sheet does
> not list. See `WithholdingByTaxCodeTest`.

> **The filing artefact — Form 41 and the certificate (EG-21, 2026-08-22).** The engine had been
> correct and dated for months and there was nothing to FILE from it, which is what kept
> `wht_enabled` switched off: withholding money you cannot declare or evidence is worse than not
> withholding it. `/admin/withholding-tax-return` reports the QUARTER per supplier, and
> `WithholdingCertificatePdfService` issues the document a supplier needs to set the amount against
> their own income tax (it is an advance payment of THEIR tax, not a cost).
>
> Four decisions worth keeping:
> - **Quarterly, on the FISCAL year's start**, not the calendar — an April→March mall year is
>   ordinary in Egypt and every other report on `ScopesLedgerReport` already honours it. Months and
>   the whole year stay offered beside the quarters, because an accountant reconciles a month at a time.
> - **Per registration, not per property.** One Form 41 covers the portfolio; `$assetId` stays null.
>   Same decision, same reason, as the VAT return.
> - **The per-supplier rate is DERIVED — withheld ÷ base.** Several payments in one quarter can carry
>   different rates (a code corrected on the vendor, a rate revised mid-quarter), so quoting a single
>   agreed rate would be a guess. Nothing is recomputed from today's catalogue: a rate revised now
>   must not rewrite a past quarter, exactly as an issued invoice keeps the VAT rate it was billed at.
> - **The tie-out is the screen's purpose.** `vendor_bill_payments.withholding_amount` against the
>   credit movement on `withholding_tax_payable` — two independent reads that must agree before a
>   number becomes a filing position somebody signs. `WithholdingTaxReturnTest` proves it can FAIL.
>
> It files nothing and reproduces none of the ETA's boxes. Those are the accountant's, and a screen
> imitating them would be a document that looks official and is not.

> **Input VAT is classified (2026-08-12).** `vendor_bills.tax_code` names which purchases-side tax
> the supplier charged, alongside the amount. Both this and `Expense` post their VAT to
> `vat_recoverable` — the account the VAT return reads — so before this the input side of a filed
> return rested on a number with nothing saying what it was.
>
> **The amount stays editable, unlike an invoice line's rate.** On an invoice the rate is our
> decision, so it is re-derived and gated on `tax_codes.override`. On a supplier's bill the tax is
> *their* number on *their* document: refusing to record what a supplier actually charged would push
> the difference somewhere worse. Picking a code fills the amount in; a departure of more than
> **EGP 1** requires a written reason (`tax_override_reason`). One pound because a rounding
> difference between two systems computing the same percentage is sub-unit — anything larger is a
> different rate or a different base, which is a decision. See `PurchaseInputTaxCodeTest`.

| Rule | Enforcement | Test(s) |
|------|-------------|---------|
| **Vendor slug is unique and collision-safe.** The `booted()` hook generates a slug from name; if it collides with an existing (including soft-deleted) vendor, it appends a numeric suffix (`-2`, `-3`, etc.) until unique. | `Vendor::booted()` creating hook; checks `withTrashed()`. | (implicit in model boot; tested via create flow) |
| **Only active contracts with an end_date are subject to expiry.** Contracts with status=draft/expired/terminated are ignored. Open-ended contracts (end_date=null) never expire. | `ExpireVendorContractsCommand` query: `where('status', 'active').whereNotNull('end_date').whereDate('end_date', '<', today())`. | `VendorScenarioTest::expire_contracts_state_transitions`, `VendorScenarioTest::expire_contracts_end_date_boundary` |
| **Expiry boundary is strict `end_date < today`** (not ≤). A contract ending today remains active; one ending yesterday is expired. | `whereDate('end_date', '<', today())` in ExpireVendorContractsCommand. | `VendorScenarioTest::does_NOT_expire_a_contract_ending_exactly_today`, `does_NOT_expire_a_contract_ending_tomorrow`, `DOES_expire_a_contract_that_ended_yesterday` |
| **Contract expiration is idempotent.** Running the command twice on the same dataset yields no changes on the second run (expired contracts are no longer active, so not queried again). | Command checks `status='active'` before update; second run finds 0 candidates. | `VendorScenarioTest::is_IDEMPOTENT` |
| **Contract value must be non-negative.** Enforced in Filament form validation. | `TextInput::make('value')->minValue(0)`. | `VendorScenarioTest::rejects_a_negative_contract_value` |
| **Asset scoping is strict.** A property-scoped operator (pinned to Asset A) via TenantScope sees only contracts with `asset_id = A` (or null = portfolio). Contracts on other properties are invisible; the relation-manager form's asset picker excludes foreign properties. The synthetic "All Properties" asset is excluded from the picker. | `TenantScope::applyTo(VendorContract::query())` filters by `asset_id = currentAssetId()`. `ContractsRelationManager` uses `TenantScope::selectableAssetOptions()` which excludes synthetic ALL row. Form disables & defaults picker when scoped to a real property. | `VendorScenarioTest::a_manager_pinned_to_property_A_sees_A's_contract_but_never_B's`, `the_asset_picker_offers_only_A_to_a_manager_pinned_to_A`, `offers_BOTH_real_properties_to_super_admin_in_the_All_Properties_view`, `locks_the_asset_picker_to_the_pinned_property` |
| **Compliance / COI gate.** A **blacklisted/inactive** vendor, or one whose insurance (**COI**, `coi_expires_at`) has **lapsed**, cannot be dispatched to maintenance work. Assignment-time only — an order whose vendor's COI expires *later* isn't retroactively broken; a vendor with **no COI on file is still assignable** (v1 doesn't force a cert on every existing vendor; blacklist to hard-block). Compliance lives on the SHARED vendor (one cert covers every mall). The COI document is a **private** media collection (`useDisk('local')`). | The single server-side gate is `FacilityWorkOrder::saving()` (throws `DomainException` when `vendor_id` is dirty + `! Vendor::isDispatchable()`); all three module-26 vendor pickers filter to `Vendor::assignable()` / `assignableOptions()`. | `VendorComplianceGateTest` (blacklisted/expired blocked, compliant/no-COI allowed, scope excludes, no retroactive block, private disk) |
| **The same supplier invoice cannot be entered twice.** `vendor_bills.reference` holds the VENDOR's own invoice number, and carried no uniqueness at any layer until 2026-08-11 — so two people keying the same paper, a re-import, or a scan processed after someone typed it produced two payables for one debt, both approvable, both payable. The key is **(vendor, reference)** among non-`cancelled` bills, matched case-insensitively and trimmed (two people keying one document do not produce byte-identical strings, and an exact-match guard would miss the realistic duplicate while looking like coverage). A **blank** reference is exempt — not every bill arrives with the number to hand, and it is the deliberate escape hatch. Cancelled is excluded so a mis-key can be cancelled and re-entered, which is why this is a model guard and not a unique index. **Deviates from Yardi**, which warns and lets the operator through; we refuse, because this failure pays money out of the door. | `VendorBill::assertVendorReferenceNotAlreadyBilled()`, called from `saving` on create and on any change to reference / vendor / status. The create + edit pages mirror it as an inline error on the field (matching how `bill_date` reports its posting-date refusal). | `VendorInvoiceReferenceIsUniquePerVendorTest` |
| **A bill cannot be paid beyond its balance.** `recordPayment()` locks the bill and caps at the remaining balance. This is layer 2 — normally bypassable by a direct model write — but `VendorBillPayment::create` has **exactly one caller in the codebase**, and it is that service, so the over-paid state is unreachable rather than merely guarded. Keep it that way: a second creator would need its own cap. (Withholding tax sits INSIDE the gross payment — `amount` discharges the vendor's claim in full, part cash and part tax paid to the ETA on their behalf — so it never enters the balance arithmetic.) | `VendorBillService::recordPayment()` (`lockForUpdate` + `min($amount, $bill->balance)`). | `VendorBillServiceTest`, and the single-caller property |
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

### What a vendor DOES — the trade link (2026-08-20)

`vendors.type` says what **kind** of counterparty a company is — contractor · supplier ·
service_provider · consultant · other. It has never said what work they can take, and until
2026-08-20 nothing did: the vendor picker on an HVAC fault offered every vendor in the register
including the stationery supplier, "spend by trade" had no dimension to group by, and
`VendorScorecardService` compared a cleaning contractor with an HVAC contractor because there was
no trade to compare *within*.

`vendor->trades()` is a many-to-many against `App\Models\Trade` (module 26's register) — many,
because a facilities company does HVAC **and** electrical, and registering them twice is not an
answer. It is set on the vendor form.

**It is a suggestion, not a gate, and the distinction is load-bearing.**
`Vendor::assignableOptions($keepId, $tradeId)` **groups** the work-order picker — "Does this trade"
first, "Other vendors" after — rather than filtering. Filament validates a `Select` against its
options with `Rule::in`, so dropping the others would *refuse* a legitimate pick at validation, and
the day the usual HVAC contractor is unavailable is a real day.

The thing that genuinely blocks a dispatch remains `Vendor::isDispatchable()` — active, and no
lapsed compliance document. That is the one place a hard block is right, because there is a real
decision behind it that the operator made about that vendor. Eligibility is about capability;
compliance is about permission. See [modules/26 → the trade register](26-facility.md).

### Compliance documents — `vendor_documents` (module 12b)

`vendors.coi_expires_at` modelled exactly **one** document. Before an Egyptian entity may legally engage and pay a supplier it needs several, each on its own expiry clock: insurance (COI), بطاقة ضريبية (tax card), سجل تجاري (commercial register), شهادة تأمينات اجتماعية (social-insurance certificate — the principal carries liability for a subcontractor's unpaid social insurance). The three COI columns **moved into** `vendor_documents` (data + certificate files migrated across, `vendors` columns dropped) so there is exactly one source of truth, not two mechanisms for one concept.

- **Blocking vs non-blocking.** `vendor_document_types.blocks_dispatch` decides which lapse *stops dispatch*, and it is a ROW the operator ticks at `/admin/vendor-document-types` — an Egyptian principal dealing with a government client may be told a lapsed social-insurance certificate blocks too, because they carry the contractor's unpaid contributions. `VendorDocument::BLOCKING_TYPES` (just insurance) is now the FLOOR, applied when the catalogue table holds NO ROWS: `whereIn('type', [])` matches nothing, so an empty answer would make every uninsured contractor dispatchable with no error anywhere. A table that holds rows of which none block is the operator's actual decision and is honoured. **Inactive types still block** — `is_active` governs what may be FILED, not whether a certificate already on file counts. A lapsed insurance certificate removes the vendor from every picker (`Vendor::assignable()` now reads `whereDoesntHave('documents', blocking+expired)`); a lapsed tax card is a finance-side compliance problem chased loudly but never blocking emergency work.
- **Compliance is a question about the CURRENT certificate of each type (2026-08-12).** A compliance file keeps its history — you upload the renewal and leave last year's on file as the record of what was in force then — so *"does this vendor hold any lapsed insurance row?"* answers **yes forever**. Doing the paperwork correctly therefore **bricked the contractor**: gone from every picker, refused by `FacilityWorkOrder::saving()`, with no escape but deleting the evidence. It also stopped that contractor's preventive plans generating at all ([module 26](26-facility.md#a-plan-that-cannot-generate-says-so)). `App\Models\Concerns\HasSupersededDocuments` adds `current()` / `superseded()`, and **both** the row question (`hasExpiredBlockingDocument()`) and the set question (`scopeAssignable()`) now reach it through the same scope chain — a picker that offers a vendor the save guard then refuses is worse than either half being wrong alone. Most-current first: an **open-ended** certificate (no expiry recorded) outranks any dated one, because `hasExpired()` already treats a missing expiry as "never lapses"; otherwise the cover that runs **longest** wins — *not* the row entered last, so back-filing the 2019 certificate for completeness cannot silently reintroduce the block. Ties break on `id`, so exactly one row per type is ever current. The same trait is on `TenantDocument`, which had the identical defect in the nag channel.
- **The chase — `vendors:scan-document-expiry`** (daily 02:40). For every document inside the 30-day window (`VendorDocument::scopeNeedsAttention`), resolve `expiring`/`expired` and notify. Idempotent via the **two-column stamp** on the *document* (`alert_stage` + `alert_for` = the expiry it fired for): a re-run never re-nags, `expiring → expired` escalates once, and **renewing a document re-arms its cycle by itself**. Lock-safe (stage re-checked inside the row lock), per-document containment, delivery wrapped so a failed send warns but still stamps.
- **Recipients** come from *engagement* (vendors are a shared catalog): staff of the assets where the vendor holds an active contract, portfolio roles otherwise.
- **One scope, three surfaces:** the scan, the **Action Required** card (`vendor_documents`), and the Vendors table filter **"Document lapsed / lapsing"** all read `Vendor::documentsNeedAttention()`, so nag, count and list can't disagree. `scopeNeedsAttention` is itself scoped to `current()`: history cannot be renewed, so a superseded row makes a chase item nobody can ever clear — and a nag nobody can clear is a nag people learn to close.

**Filament:** `DocumentsRelationManager` on the vendor (replaces the three fixed COI fields on the form); the table badge names the *consequence*, not just the date.
**Tests:** `tests/Feature/Regression/VendorDocumentAlertingTest.php`, `tests/Feature/VendorComplianceGateTest.php`, `tests/Feature/Regression/RenewingACertificateDoesNotBrickTheVendorTest.php`.

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

### Importing the supplier register (`VendorImporter`, 2026-08-12)

The fourth importer, so the operator's existing vendor list arrives with the cut-over instead of
being keyed by hand. `/admin/vendors` → **Import**, gated through `App\Support\Imports` like the
other three (import is admin-only, FR-USR-02 — it is not a flavour of create).

- **Identity is `tax_id`, then `email`** — never `name`. Re-running an import is the normal response
  to a partial one, and "Cairo HVAC Co." vs "Cairo HVAC Co" would fork one supplier into two, each
  accumulating its own bills, contracts and compliance documents. Once either has history
  `RefusesDeletionWhenReferenced` correctly refuses to delete it and the two cannot be merged.
  A TRN matches **with or without dashes**, because the file and the existing record will not agree
  on punctuation.
- **A blank withholding-tax cell stays NULL.** `null` = no agreed rate, use the portfolio default;
  `0` = this supplier is EXEMPT. Coercing blank to 0 would silently exempt the entire register and
  nothing would be withheld from anyone. Mutation-checked in both directions.
- **`type` and `status` are validated against the exact set the column accepts**, read from
  `App\Support\ValueSets` rather than repeated in the importer, so a bad value fails that row with a
  readable message instead of reaching the INSERT as an opaque failure — and widening the set stays a
  one-line change in one file. (Both were DB enums until 2026-08-12.)
- **`slug` is not importable** — the model derives it from the name and de-duplicates against
  soft-deleted rows.
- `Vendor` is SHARED, so unlike `LeaseImporter` there is no asset column and nothing to clamp.

Tests: `VendorImportTest`.

### Withholding tax on vendor payments — خصم وإضافة (module 12b)

Atriom paid vendors **gross**, which is non-compliant with Income Tax Law 91/2005 art. 59 — the operator must withhold at source and remit to the ETA, and the un-withheld amount otherwise becomes their own liability. This is the AP-side twin of the AR VAT.

- **Mechanics.** `vendor_bill_payments.amount` stays **gross** (settles the payable in full — `VendorBill::recompute()` untouched); `withholding_amount` is the slice owed to the ETA; net cash out = `amount − withholding_amount`. GL: **Dr Accounts Payable (gross) / Cr Bank (net) / Cr Withholding Tax Payable `21303001` (withheld)** — the WHT leg only appears when > 0.
- **Settings-driven, never hardcoded** (`App\Support\WithholdingTax` over `TaxSettings`): the statutory rate varies by payment nature and is revised by the ETA, so a compiled constant would look official and be wrong. **Off by default** (`wht_enabled`); per-vendor `withholding_tax_rate` overrides the `wht_default_rate`, where **`0` = exempt ≠ `null` = use default**. Clamped to the payment so a mis-set rate can't drive cash negative.
- **The base EXCLUDES VAT** — `WithholdingTax::onBillPayment()`, not `::on()`. Withholding is a
  prepayment of the *supplier's income tax*, so it is charged on the consideration for the supply;
  the VAT on top is the supplier's own output tax, which they remit themselves, and withholding on
  it taxes a tax. **Wrong until 2026-08-12**: `recordPayment()` passed `min($amount, $bill->balance)`
  — derived from `total`, i.e. net *plus* VAT — into the primitive, so at 3% on a 100,000 net bill
  it withheld **3,420 instead of 3,000**, short-paying the vendor by 420 and over-remitting the same
  to the ETA on every payment. The whole existing WHT suite was blind to it: every fixture set
  `vat_amount => 0`, so net and gross were the same number.
  - Computed as the payment's **VAT-exclusive share** (`subtotal / (subtotal + vat_amount)`), so a
    partial payment splits correctly — of 57,000 against a 100,000 + 14,000 bill, 50,000 is
    consideration. Taken from the bill's own tax composition rather than `total`, so an SLA penalty
    (which reduces the balance without touching either) cannot distort the ratio.
  - A bill with **no VAT is unaffected**, which is what makes this invisible to every exempt supply.
  - `on()` survives as the primitive and its docblock now says plainly that it applies the rate to
    whatever it is handed — that misuse is how the bug happened.
  - The `record_payment` preview calls the **same** function, and a test asserts the previewed
    figure equals the recorded one. A displayed number drifting from a recorded one is invisible,
    because the screen is the only thing anyone checks.
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

### VoidVendorBillPaymentService — the way back from a wrong cheque (2026-08-11)

A payment keyed against the wrong bill used to be **permanent**. Three places promised a correction
that did not exist: `DeletionPolicy` named it ("void the payment — money left the bank"),
`cancel()` refused a bill with payments and told the operator to reverse them first, and the payments
relation manager was read-only — while the model is unconditionally committed, so even the
soft-delete that would have self-healed the GL was refused. The AP balance, the bank leg and the
withholding-tax liability were all wrong with no operator path to any of them. *(Voiding a check is
an everyday operation in Yardi Voyager — [change-impact plan F1](../accounting/CHANGE-IMPACT-PLAN.md).)*

- **A void is a status flip, not a delete** — the AP mirror of `VoidInvoiceService` /
  `VoidPaymentService`. `voided_at` + `void_reason` + `voided_by_user_id` are set; the row **stays on
  the register**, greyed with its reason. That is the difference between voiding and deleting: an
  auditor holding a bank statement that shows no such payment can follow it back to who cancelled it
  and why. The reason is also written to the immutable activity log, because `void_reason` is a
  column someone can later edit.
- **One predicate, two consumers.** `VendorBillPayment::isVoided()` is read by
  `VendorBill::recompute()` (a voided payment is excluded from `paid_amount`, so the payable re-opens
  and the status falls back from `paid`) **and** by `VendorBillPaymentJournalizer` (which returns no
  payload, so the sweep posts the reversing entry — Dr Bank / Cr AP, plus the withholding leg where
  there was one). Named once so the document and the books cannot disagree about whether cash moved.
- **Locks the bill first, then the payment.** `recompute()` re-derives from *all* of a bill's
  payments, so two concurrent voids must serialize on the parent or the second overwrites the first's
  result. Same discipline as `recordPayment()`.
- **It does not re-open a closed period.** The reversal lands in the original entry's period when
  that is open and in today's otherwise — the standing rule for every void in the system — so a
  correction to a sealed month surfaces in the current one instead of silently failing.
- **A recorded payment is now immutable** (promoted 2026-08-11, once the void existed). `amount`,
  `withholding_amount`, `payment_date` and `vendor_bill_id` are refused by an `updating` guard —
  the cash has already left the bank, so the correction is a void and a re-record, not an edit.
  **This could not be written before the void:** locking the fields without a reversal path would
  have trapped an operator holding a wrong cheque, and a refusal is only as good as the path it
  names. `voided_at` is deliberately outside the frozen set, so the void itself still saves. The
  verdicts live in `App\Support\ChangeImpact`, and `ChangeImpactConformanceTest` *proves* each
  refusal fires by dirtying it on a committed fixture rather than asserting a guard exists.
- **Permission:** `vendor_bills.void_payment`, its own right rather than a fold into `.pay` (mirroring
  `invoices.void` / `payments.void`): keying a cheque and un-keying one are different acts. Granted to
  super_admin, manager, mall_admin and accounting. **Existing deployments must re-run
  `db:seed --class=RolesPermissionsSeeder`.**
- **Tests:** `tests/Feature/Regression/VendorPaymentVoidTest.php` (7) — the payable re-opens and the
  entry is reversed *through the real `accounting:sync-ledger` sweep*, AP ties back out to the full
  bill, all three legs (AP / bank / WHT) net to zero, the void is idempotent, a sibling payment still
  settles its share, the cancel refusal is now an instruction that works, and the permission is held
  by the roles that pay bills and not by the ones that don't.


### `VendorScorecard` page (app/Filament/Admin/Pages/VendorScorecard.php) — added 2026-08-18

How each vendor has actually performed, over a window: jobs raised / completed / still open, average
hours to acknowledge and to resolve, SLA breaches, penalties applied and their value, lapsed
compliance documents, and whether the vendor is dispatchable at all. Nothing here is new data — every
figure is a by-product of work already recorded; what was missing was bringing it together per vendor,
so "who is actually any good" was answered from memory at renewal time.

- **Counts and times, never a single score.** A composite would have to weight responsiveness against
  cost against compliance, and that weighting is the operator's judgement — a vendor who is slow but
  cheap may be exactly right for routine work. Sorted by SLA breaches because that is what somebody
  arriving here is looking for, not because it is "the" ranking.
- **A blank response time is not zero.** `VendorScorecardService` returns null when nothing was ever
  acknowledged, and both the table and the CSV keep it blank — averaging "never" as zero would flatter
  the vendor into looking instant.
- **Gated on `vendors.view`, deliberately not `reports.view`.** The `vendor` role is an *external*
  contractor holding facility rights and no vendor rights; it must never read a competitor's response
  times, penalties or lapsed documents.
- Catalogued in `ReportCatalogue` under Operations (so the Reports hub indexes it) while its nav entry
  sits in **Payables**, beside the register it summarises. Screen guide in both languages.

> The service and its seven regression tests shipped without this screen, and it sat in
> the backlog as a feature to build while the feature was already built — the only way to read a
> scorecard was to call the service from tinker.

## 6. Filament resources & key fields

> **12b additions not detailed below** (this section predates them): the vendor edit page also carries a **`DocumentsRelationManager`** (compliance certs, private disk) and the contracts RM gained an **`amend`** (change-order) action + Committed/Billed/Remaining columns; and there is a **separate `VendorBillResource`** (`/admin/vendor-bills`) for AP — property-scoped (`asset_id` guarded by `assertAssetInScope` on create+edit), with `approve` / `record_payment` (withholding-tax breakdown) / `cancel_bill` actions, all double-gated. Its **payments relation manager** creates and edits nothing but owns one correction — **`void_payment`** (§5), which states the ledger effect in the confirmation ("the bill's balance re-opens by X and its entry is reversed") and requires a reason. All vendor relation-manager write actions gate the predicate in both `visible()` and `authorize()`.

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
- *(no currency field — removed 2026-08-20, EG-07. The column is EGP-only and the value is never printed on a vendor document, so the form does not ask.)*

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

**Request/work-order assignment:** `TenantRequest::assignedVendor()` (formerly `MaintenanceRequest`) links a request to a vendor via `assigned_to_vendor_id`; the actual facility **dispatch** is a `FacilityWorkOrder`, whose `saving()` hook is the compliance gate (see §9). Assignment is activity-logged; the vendor picker filters to active/dispatchable vendors.

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

### The contract currency picker offered five currencies nothing honoured

Until 2026-08-20 the contract form carried a `Select` offering EGP/USD/EUR/GBP/SAR/AED, one line
below an amount field prefixed `EGP`. Nothing downstream honoured the choice: there is no
exchange-rate table anywhere in the system, no rate stamped on any document, and no currency or
base-amount column on `journal_lines`. And it was not inert — `vendor_contracts.value` feeds the
SLA-penalty basis (`AssessSlaPenaltyService`), which posts. Choosing a foreign code put a foreign
number into an EGP ledger at 1:1, silently, with every downstream total still balancing.

The field is gone and `vendor_contracts.currency` is EGP-only in `ValueSets`. The rule the codebase
now follows: **a currency field survives only where the value is PRINTED** — the asset's is (it leads
the owner statement) and stays, visible and read-only; this one was not, so it went. Widening either
set is a decision about FX, not a typo fix (EG-31 in [EGYPT-MARKET-FIT](../EGYPT-MARKET-FIT.md)).
Pinned by `tests/Feature/Regression/CurrencyIsEgpOnlyTest.php`.

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

A non-dispatchable vendor **cannot be put on a facility job.** The single server-side gate is `FacilityWorkOrder::saving()`, which throws a `DomainException` when `vendor_id` is dirty and `! Vendor::isDispatchable()` (blacklisted/inactive, or a **blocking** document — insurance/COI — has lapsed). Every work-order vendor picker filters to `Vendor::assignableOptions()` / `scopeAssignable()`, and the tenant-request picker filters `status='active'`, so a blacklisted vendor is never offered for triage assignment either. See §3's compliance-gate row. (Assignment-time only — an order whose vendor's COI lapses *later* isn't retroactively broken; a vendor with no COI on file is still assignable, blacklist to hard-block.)

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

- **Maintenance Requests** (`docs/modules/11-tenant-requests.md` when available)
  - Vendors can be assigned to maintenance requests via `assigned_to_vendor_id`.
  - Assignment is one-to-many; a vendor services many requests.

- **Assets / Properties** (`docs/modules/01-properties-units.md`)
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
| `VendorBillPayment` | **Never deletable** | void the payment — money left the bank (`VoidVendorBillPaymentService`, §5 — the correction this row named before anything implemented it) |


## The language a supplier's documents are written in (2026-08-28)

`vendors.locale` — nullable, `en` / `ar`, on the vendor form (Contact) and `VendorImporter`.

Two documents leave the building toward a supplier and neither was answerable to them: the
**purchase order** and the **withholding-tax certificate**. The certificate is the sharper case —
withholding is an ADVANCE payment of the supplier's OWN income tax, so this is the page they hand to
their accountant to claim it back, and it was being written in whichever language the operator's
panel happened to be set to.

**Blank is the normal state** and means "nobody has asked": the document then follows whoever is
producing it, and the download picker is the answer. A stated language wins over the operator's and
loses to an explicit pick — see
[OVERVIEW → Core business rules](../OVERVIEW.md#4-core-business-rules-quick-reference).

`Vendor` is not `Notifiable`, so it deliberately does NOT implement `HasLocalePreference`:
`App\Support\Pdf\DocumentLocale` reads a plain `locale` attribute for exactly this case. If vendors
ever start receiving notifications, adding the interface is what makes Laravel render those in this
language too.

The column is registered in `App\Support\ValueSets` (derived from `SetLocale::SUPPORTED`), so a
spreadsheet typo is refused on save rather than silently leaving every document in English.

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
