<?php

namespace App\Support;

use App\Enums\AssessmentBasis;
use App\Enums\InvoiceItemType;
use App\Enums\ManagementFeeBasis;
use App\Enums\TenantRequestType;
use App\Enums\UnitManagementMode;
use App\Enums\UnitOwnershipStatus;
use App\Enums\UnitTenureType;
use App\Models\CamExpensePool;
use App\Models\Charge;
use App\Models\CustomField;
use App\Models\Disbursement;
use App\Models\ExpenseCategory;
use App\Models\FacilityWorkOrder;
use App\Models\FailureCode;
use App\Models\Lease;
use App\Models\LeaseCamTerm;
use App\Models\LeaseEvent;
use App\Models\LeaseOption;
use App\Models\MarketingPost;
use App\Models\OwnerStatement;
use App\Models\OwnerStatementRun;
use App\Models\PaymentMethod;
use App\Models\PostDatedCheque;
use App\Models\RentableItem;
use App\Models\RetailCategory;
use App\Models\ServicePlan;
use App\Models\TenantRequestSubcategory;
use App\Models\User;
use App\Models\VendorDocumentType;
use App\Models\Violation;
use App\Models\ViolationCategory;
use App\Models\WorkOrderProposal;
use DomainException;
use Illuminate\Database\Eloquent\Model;

/**
 * **Every value set the database used to enforce, now enforced here.**
 *
 * CLAUDE.md's rule is `string` + Laravel validation, never `$table->enum(...)`, and on 2026-08-12
 * the last 62 DB-level enum columns were converted. This registry is what replaced them: the same
 * sets, in one place, checked at the model — where every writer passes, not just the form.
 *
 * **Freeing the columns without this file would have been a downgrade, and the reason is specific.**
 * MySQL refuses an out-of-set value outright, so the enum was doing real work in production even
 * though it cost an `ALTER TABLE` per new value. Dropping it and leaning on the Filament selects
 * would have left every service, job, importer, seeder and console command unconstrained. So the
 * constraint moved rather than disappeared, and it moved to the layer that sees all of them.
 *
 * **What the enum was NOT doing is the part that had been recorded backwards.** The old
 * `DatabaseEnums` registry claimed the suite enforced the identical set, because Laravel renders
 * `enum()` on SQLite as `varchar check (…)`. That is true only until something calls `->change()`
 * on the table: SQLite has no `ALTER COLUMN`, so Laravel rebuilds the table from the *introspected*
 * schema — which knows the column is a `varchar` and knows nothing about the check. The constraint
 * is dropped, silently, for every column of that table. 24 of the 62 had been freed on SQLite
 * exactly this way and were still enums on MySQL, so the gate that read the live schema counted 38
 * and passed while production carried 62. A value the model allowed and MySQL refused would have
 * been green in tests and failed on the first real save — the `escalation_type` bug that
 * `add_fixed_amount_escalation_to_leases` documented, waiting to happen 24 more times.
 *
 * That asymmetry is gone now: the columns are plain strings on both drivers, and this registry is
 * the only thing that says what may go in them, so both drivers enforce the same rule for the first
 * time.
 *
 * **This answers "is the value allowed", never "what is it called".** Labels live in
 * `admin.statuses.*` / `admin.enums.*` and stay there — same split as the tax catalogue, where
 * `charge_codes.tax_code` holds the ruling and `tax_codes` holds the rate. Two questions, two homes.
 *
 * **Sets NOT listed here, and why.** Three string columns were freed earlier and are enforced by a
 * catalogue of their own, which is the better shape wherever one exists:
 *
 *   - `invoice_items.type` → {@see InvoiceItemType} (a PHP enum used as a catalogue, not
 *     as a cast — casting would turn `$invoice->status === 'paid'` silently false everywhere);
 *   - `tenant_requests.type` → {@see TenantRequestType};
 *   - `charges.type` → the `charge_codes` TABLE, via `Charge::assertTypeIsAKnownChargeCode()`, so an
 *     accountant can add a code without a deploy. A static list could not express that.
 *
 * A set here may graduate to either shape; it is listed here because a flat list is all it is today.
 *
 * **Widening a set is a one-line code change and no migration** — which was the whole point. Ten of
 * these are extensible by an operator rather than an engineer (Egypt's payment rails keep moving:
 * Fawry, Meeza, Aman, Vodafone Cash), and those are the ones that used to mean a blocking `ALTER`
 * on `payments` — the hottest table in the system. If a set needs to be operator-editable at
 * runtime rather than release-time, it wants a table, like `charge_codes`.
 *
 * `NoDatabaseEnumsConformanceTest` gates all of it: no enum column on either driver, an entry for
 * every column that was one, and no entry naming a column that no longer exists.
 */
class ValueSets
{
    /**
     * `table.column` => the values that column accepts.
     *
     * Generated from the pre-conversion schema (`information_schema` on a freshly migrated MySQL
     * database), so it starts as an exact record of the contract the enums enforced. Edit freely
     * from here — that is the point — but a change is a change to what the system accepts, not
     * bookkeeping.
     *
     * @var array<string, list<string>>
     */
    public const SETS = [
        // The blocks a document actually renders (EG-15). Derived from `DocumentText::KEYS` rather
        // than re-listed: a template written for a slot nothing reads is the inert-settings-screen
        // failure — the operator writes it, saves it, and nothing happens.
        // Where an account's movement lands on the cash-flow statement (EG-28). Registered because
        // a typo here does not error — it silently sends the movement to the operating default.
        'ledger_accounts.cash_flow_section' => [CashFlowSection::class, 'SECTIONS'],

        'document_templates.key' => [DocumentText::class, 'KEY_NAMES'],

        // What an operator-defined field can hold (D-7 / EG-32). Registered because a mistyped type
        // does not error — `CustomFieldsSchema::input()` falls through its `match` to a plain text
        // box, so the field renders, saves, and quietly is not the date or the number it was
        // defined as.
        'custom_fields.type' => [CustomField::class, 'TYPES'],

        // ── Registered 2026-08-22 (EG-37) ──────────────────────────────────────────────────────
        // `ValueSets` covered the 62 columns that were DB enums on 2026-08-12 and nothing since, so
        // every classification column added in the ten weeks after was UNENFORCED — including
        // `facility_work_orders.status`, which the transition matrix branches on. These are declared
        // as `[Model::class, 'CONST']` wherever the model already states the set, because two copies
        // of a value list is the drift CLAUDE.md's "don't re-list a set" exists to prevent.
        'facility_work_orders.status' => [FacilityWorkOrder::class, 'STATUSES'],
        'lease_events.type' => [LeaseEvent::class, 'TYPES'],
        'lease_options.type' => [LeaseOption::class, 'TYPES'],
        'lease_options.status' => [LeaseOption::class, 'STATUSES'],
        'marketing_posts.type' => [MarketingPost::class, 'TYPES'],
        'marketing_posts.status' => [MarketingPost::class, 'STATUSES'],
        'post_dated_cheques.status' => [PostDatedCheque::class, 'STATUSES'],
        'rentable_items.type' => [RentableItem::class, 'TYPES'],
        'rentable_items.status' => [RentableItem::class, 'STATUSES'],
        'work_order_proposals.status' => [WorkOrderProposal::class, 'STATUSES'],
        'owner_statements.status' => [OwnerStatement::class, 'STATUSES'],
        'owner_statement_runs.status' => [OwnerStatementRun::class, 'STATUSES'],
        'violations.status' => [Violation::class, 'STATUSES'],
        'users.status' => [User::class, 'STATUSES'],
        'disbursements.status' => [Disbursement::class, 'STATUSES'],
        'failure_codes.type' => [FailureCode::class, 'TYPES'],
        'lease_cam_terms.cap_type' => [LeaseCamTerm::class, 'CAP_TYPES'],
        'service_plans.frequency_unit' => [ServicePlan::class, 'FREQUENCY_UNITS'],
        'tenant_requests.request_type' => TenantRequestType::class,
        'tenant_request_subcategories.request_type' => TenantRequestType::class,
        // Sets with no home on a model — declared here because here IS their only home.
        'tax_codes.direction' => ['sales', 'purchases'],
        'tax_codes.treatment' => ['standard', 'zero_rated', 'exempt'],
        'employees.payment_method' => ['cash', 'bank'],
        'custody_transactions.type' => ['expense', 'return'],
        'employee_advances.type' => ['advance', 'loan'],
        'tenant_documents.type' => ['insurance_coi', 'tax_card', 'commercial_register', 'other'],
        'tenant_documents.alert_stage' => ['expiring', 'expired'],
        'vendor_documents.alert_stage' => ['expiring', 'expired'],
        'fixed_assets.method' => ['straight_line', 'declining_balance'],
        // Derived, not copied — and the first cut of these five WAS copied, from whatever happened
        // to be seeded. Four were wrong (`billed`, `gla`, `uplift_percent`, `rate_per_sqm`) and the
        // suite said so within a minute. Reading a set off the data tells you what has been stored,
        // never what the code accepts.
        'leases.rent_pricing_basis' => [Lease::class, 'RENT_PRICING_BASES'],
        'lease_options.rent_basis' => [LeaseOption::class, 'RENT_BASES'],
        'cam_expense_pools.expense_basis' => [CamExpensePool::class, 'EXPENSE_BASES'],
        'cam_expense_pools.estimate_basis' => [CamExpensePool::class, 'ESTIMATE_BASES'],
        'cam_expense_pools.denominator_basis' => [CamExpensePool::class, 'DENOMINATOR_BASES'],
        'owner_statement_runs.basis' => ['accrual', 'cash'],
        // EG-07's rule, applied to the six currency columns it did not reach: EGP only, because
        // there is no exchange rate anywhere in this system and any other code would post at 1:1.
        'payments.channel' => ['admin', 'portal', 'mobile_api', 'payment_link'],
        'saved_reports.frequency' => ['weekly', 'monthly'],
        'cam_pool_accounts.cost_nature' => ['fixed', 'variable'],
        'leases.currency' => ['EGP'],
        'bank_accounts.currency' => ['EGP'],
        'charges.currency' => ['EGP'],
        'credit_notes.currency' => ['EGP'],
        'invoices.currency' => ['EGP'],
        'payments.currency' => ['EGP'],
        'post_dated_cheques.currency' => ['EGP'],
        'sla_penalties.currency' => ['EGP'],
        'unit_ownerships.currency' => ['EGP'],
        'vendor_bills.currency' => ['EGP'],
        'accounting_periods.status' => ['open', 'closed'],
        // Mall news (module 27). `status` is the lifecycle a notice moves through; only
        // SendAnnouncementAction ever writes `sent`, because that word means "tenants have been
        // pushed this text" and no form can know that.
        'announcements.status' => ['draft', 'scheduled', 'sent'],
        'announcements.category' => ['general', 'operations', 'event', 'emergency', 'hours'],
        // EGP only, and enforced rather than assumed. There is no exchange-rate table, no rate on
        // any document and no currency column on `journal_lines`, so a non-EGP value would be
        // posted to the ledger at 1:1 — silently. Until FX exists (EG-31), the set IS the
        // support: the column may carry the code it prints, and nothing else.
        'assets.currency' => ['EGP'],
        'assets.type' => ['mall', 'retail_walk', 'mixed_use', 'office', 'residential'],
        'cam_allocations.status' => ['pending', 'billed', 'disputed', 'closed'],
        'cam_expense_pools.status' => ['draft', 'reconciling', 'reconciled', 'closed'],
        'charges.frequency' => ['monthly', 'quarterly', 'annually', 'one_time'],
        // Ahead of the period or behind it (EG-30 / M-2). Null is the normal state and means
        // `advance`, so every charge that existed before this bills exactly as it did; the two
        // values are registered so the column cannot quietly acquire a third reading.
        'charges.billing_timing' => [Charge::class, 'BILLING_TIMINGS'],
        'credit_notes.status' => ['draft', 'issued', 'applied', 'void'],
        'deposit_transactions.method' => ['cash', 'bank'],
        'deposit_transactions.status' => ['recorded', 'cancelled'],
        'deposit_transactions.type' => ['receipt', 'refund', 'forfeit'],
        'device_tokens.platform' => ['ios', 'android'],
        // The fourth rail registry, which lived on `Disbursement::METHODS` outside this file — so
        // the column was unenforced and the list could not be widened without a deploy. Same floor
        // as the other three, widened by the same catalogue.
        'disbursements.method' => ['cash', 'bank_transfer', 'cheque'],
        // The SIXTH money rail, and the one EG-11 missed. It is INBOUND — the employee is paying
        // the operator back, so the journalizer DEBITS cash/bank. Until 2026-08-22 the column had no
        // set at all and `RecordAdvanceRepaymentService` clamped anything that was not `bank` to
        // `cash`, which is a WRONG RAIL rather than a refusal: an InstaPay repayment posted to the
        // cash account and the operator was never told.
        'employee_advance_repayments.method' => ['cash', 'bank'],
        // Four more `cash|bank` columns with no set at all, found while converting the journalizers
        // that read them. Each was protected only by a PHP clamp in its service —
        // `=== 'bank' ? 'bank' : 'cash'` — which turns a bad value into a WRONG one instead of
        // refusing it, and posts the money to the wrong account under a success toast.
        //
        // Registered but deliberately NOT catalogue-widened: these hold two values, and offering
        // collection networks as a way to fund a fixed asset is the mirror of the bug the rail
        // catalogue exists to prevent. Their JOURNALIZERS still resolve through
        // `PaymentMethod::accountIdOrFloor()`, so an operator who points a `bank` rail at a specific
        // account gets that account here too rather than the generic role.
        'custodies.paid_from' => ['cash', 'bank'],
        'custody_transactions.method' => ['cash', 'bank'],
        'fixed_assets.funded_from' => ['cash', 'bank'],
        'fixed_asset_disposals.proceeds_account' => ['cash', 'bank'],
        // …and the other half of the same money movement, in the other DIRECTION: granting an
        // advance PAYS the employee, so it is an outbound rail like an expense. `paid_from` had no
        // set either, and `EmployeeAdvanceJournalizer` carried the same mirror ternary.
        'employee_advances.paid_from' => ['cash', 'bank'],
        'employees.status' => ['active', 'terminated'],
        // The expense CATEGORY had no set at all, on any of its three columns: it lived in a lang
        // array and a `private const` inside a journalizer trait, so a typo'd or imported value
        // saved cleanly and then silently booked to `admin_expense`. These six are the floor the
        // trait held; `expense_categories` widens them.
        'custody_transactions.category' => ['maintenance', 'utilities', 'cleaning_security', 'marketing', 'admin', 'other'],
        // The catalogue's OWN column. Registering three sets and forgetting the one this change
        // introduced would be the same gap, one table along.
        'expense_categories.cost_nature' => ['fixed', 'variable'],
        'expenses.category' => ['maintenance', 'utilities', 'cleaning_security', 'marketing', 'admin', 'other'],
        'expenses.paid_from' => ['cash', 'bank'],
        'expenses.status' => ['recorded', 'cancelled'],
        'fiscal_years.status' => ['open', 'closed'],
        'fixed_assets.status' => ['active', 'disposed'],
        // Egyptian income-tax depreciation pools — Law 91/2005 Art. 25. STATUTE, not preference:
        // an operator does not get to decide that computers depreciate at 50%.
        'fixed_assets.tax_pool' => [
            TaxDepreciation::BUILDINGS, TaxDepreciation::INTANGIBLES,
            TaxDepreciation::COMPUTERS, TaxDepreciation::GENERAL, TaxDepreciation::NONE,
        ],
        'invoices.eta_status' => ['pending', 'submitted', 'valid', 'invalid', 'rejected', 'cancelled'],
        'invoices.status' => [
            'draft', 'issued', 'partially_paid', 'paid', 'overdue', 'disputed', 'cancelled', 'credited',
            'written_off',
        ],
        'journal_entries.status' => ['draft', 'posted', 'void'],
        'work_permits.type' => ['hot_work', 'electrical_isolation', 'working_at_height', 'confined_space', 'excavation', 'general'],
        'work_permits.status' => ['draft', 'issued', 'closed', 'cancelled'],
        'lease_clauses.type' => ['use', 'exclusivity', 'radius', 'co_tenancy', 'kick_out', 'assignment', 'insurance', 'operating_hours', 'signage', 'parking', 'repairs', 'guarantor', 'other'],
        'leases.billing_frequency' => ['monthly', 'quarterly', 'semiannual', 'annual'],
        // Missed by the 2026-08-12 enum sweep for a reason worth stating: this column stopped
        // being a DB enum two days EARLIER (2026_08_10_240000, which added `fixed_amount`), so the
        // generator that read the live schema could not see it. It has been a `string(32)` with no
        // runtime refusal ever since, while its option list came from a translation array — the
        // shape banned after `Trade.category`. An out-of-set value here is silent and permanent:
        // `RentEscalationService` filters on the three escalating types, so a lease carrying
        // `annual_increase` is skipped by the sweep for ever and its rent simply never steps.
        'leases.escalation_type' => ['none', 'fixed_percent', 'fixed_amount', 'cpi'],
        'leases.percentage_rent_calculation_type' => ['natural_breakpoint', 'artificial', 'tiered'],
        // The two halves of a percentage-rent clause that are constantly confused for each other:
        // how the overage is WORKED OUT, and when it is CHARGED. The first was an enumerated string
        // column with nothing guarding it until now.
        'leases.percentage_rent_frequency' => ['monthly', 'annual'],
        'leases.percentage_rent_billing_frequency' => ['monthly', 'quarterly', 'annual'],
        'leases.status' => ['draft', 'pending_approval', 'active', 'expired', 'renewed', 'terminated', 'cancelled'],
        'ledger_accounts.normal_balance' => ['debit', 'credit'],
        'ledger_accounts.type' => ['asset', 'liability', 'equity', 'revenue', 'expense'],
        'sla_penalties.basis' => ['flat', 'per_day', 'percent_of_value'],
        'sla_penalties.status' => ['pending', 'final', 'applied', 'waived'],
        'service_plans.plan_type' => ['routine', 'fixed'],
        'facility_work_order_items.result' => ['pending', 'pass', 'fail'],
        'facility_work_order_parts.source' => ['internal', 'external'],
        'facility_work_order_parts.status' => ['pending', 'approved', 'rejected', 'recorded'],
        'facility_work_orders.execution_type' => ['internal', 'external'],
        // Frozen when the deadline is stamped, never on a form — an operator changes the policy,
        // not one job's clock. Null is the fourth state and means calendar: it is what every
        // order created before the working calendar carries, and what a PPM order always will.
        'facility_work_orders.sla_clock' => ['calendar', 'working'],
        'holidays.kind' => ['closure', 'short_day'],
        'facility_work_orders.priority' => ['low', 'medium', 'high', 'urgent'],
        'facility_work_orders.work_order_type' => ['ppm', 'cm'],
        'marketing_budgets.status' => ['open', 'closed'],
        'marketing_spends.category' => ['offer', 'promotion', 'event', 'printed_work', 'other'],
        'marketing_spends.paid_from' => ['cash', 'bank'],
        'notes.channel' => ['call', 'whatsapp', 'email', 'meeting', 'site_visit', 'other'],
        'owner_requests.priority' => ['low', 'medium', 'high'],
        'owner_requests.recipient' => ['operator', 'owner'],
        'owner_requests.status' => ['open', 'in_progress', 'resolved', 'closed', 'cancelled'],
        'payments.method' => ['card', 'bank_transfer', 'instapay', 'wallet', 'cash', 'cheque', 'other'],
        'payments.status' => [
            'initiated', 'authorized', 'captured', 'reconciled', 'settled', 'failed', 'refunded', 'bounced',
        ],
        'payrolls.paid_from' => ['cash', 'bank'],
        'payrolls.status' => ['draft', 'approved', 'cancelled'],
        'purchase_requests.status' => ['draft', 'requested', 'approved', 'rejected', 'ordered', 'received', 'cancelled'],
        'sla_policies.priority' => ['low', 'medium', 'high', 'urgent'],
        'stock_movements.type' => ['receipt', 'consumption', 'adjustment', 'transfer_in', 'transfer_out'],
        // `tenant_requests.category` had NO set, so a typo'd or imported subcategory saved cleanly
        // and then resolved to no trade. The floor is every subcategory the enum listed, across all
        // types — flat, because `other` is a legitimate subcategory of four of them and the value
        // set answers "may this column hold this string", not "may this TYPE offer it". Which
        // subcategories a type offers is the form's question, and the forms ask the catalogue.
        // `any` plus the request types that HAVE an SLA. A policy naming a type with no SLA would
        // never be read, so the column refuses it rather than storing a row nothing consults.
        'sla_policies.request_type' => ['any', 'maintenance', 'complaint', 'access'],
        'tenant_requests.category' => [
            'electrical', 'plumbing', 'hvac', 'structural', 'cleaning', 'safety', 'other',
            'keys_cards', 'parking', 'after_hours', 'visitor', 'delivery',
            'lease_copy', 'renewal', 'termination_notice', 'noc_certificate',
            'fit_out', 'temporary_installation', 'signage',
            'noise', 'cleanliness', 'conduct',
        ],
        'tenant_requests.channel' => ['portal', 'whatsapp', 'phone', 'email', 'walk_in', 'admin'],
        // The answer a request that ASKED for something was given. Null is the third state and
        // deliberately not a member: it means nobody has answered (or the row predates the
        // column), which readers must not render as either outcome.
        'tenant_requests.decision' => ['approved', 'rejected'],
        'tenant_requests.sla_clock' => ['calendar', 'working'],
        'tenant_requests.priority' => ['low', 'medium', 'high', 'urgent'],
        'tenant_requests.status' => [
            'submitted', 'acknowledged', 'in_progress', 'awaiting_tenant', 'resolved', 'closed', 'cancelled',
        ],
        'tenant_sales_declarations.status' => ['submitted', 'locked', 'disputed'],
        // `tenants.retail_category` had NO set, so a typo'd or imported value saved cleanly and then
        // matched no filter in the shopper app — invisible in the directory while looking correct on
        // the tenant record. The twelve are the floor the const held.
        'tenants.retail_category' => [
            'fashion', 'food_beverage', 'electronics', 'health_beauty', 'home_lifestyle', 'kids_toys',
            'sports', 'jewellery_accessories', 'entertainment', 'services', 'hypermarket', 'other',
        ],
        'tenants.party_type' => ['retailer', 'unit_owner'],
        'tenants.status' => ['active', 'inactive', 'blacklisted'],
        'tenants.type' => ['individual', 'company'],
        // Declared as the ENUM the model also casts to, not as a copied list — see `resolved()`.
        // The cast is type safety for the developer; this registry is the runtime refusal that
        // covers an importer or an API write. One source of cases, two layers of enforcement.
        'unit_ownerships.assessment_basis' => AssessmentBasis::class,
        'unit_ownerships.fee_basis' => ManagementFeeBasis::class,
        'unit_ownerships.management_mode' => UnitManagementMode::class,
        'unit_ownerships.status' => UnitOwnershipStatus::class,
        'unit_ownerships.tenure_type' => UnitTenureType::class,
        'units.category' => ['retail', 'food_beverage', 'wellness', 'service', 'kiosk', 'office', 'storage'],
        'units.status' => ['vacant', 'reserved', 'occupied', 'maintenance'],
        'service_plans.trigger_type' => ['time', 'usage'],
        'utility_meters.status' => ['active', 'inactive', 'faulty'],
        'utility_meters.type' => ['electric', 'water', 'gas', 'hours'],
        // The SAME set as `utility_meters.type`, and deliberately spelled out rather than
        // referenced: these two must agree, because a tariff is only offered for meters of its own
        // utility. Widening one without the other is what the listener would then refuse on save.
        'utility_tariffs.utility_type' => ['electric', 'water', 'gas'],
        'vendor_bill_payments.method' => ['cash', 'bank_transfer', 'cheque', 'card', 'other'],
        'vendor_bills.category' => ['maintenance', 'utilities', 'cleaning_security', 'marketing', 'admin', 'other'],
        'vendor_bills.status' => ['draft', 'approved', 'partially_paid', 'paid', 'cancelled'],
        'vendor_contracts.sla_penalty_basis' => ['none', 'flat', 'per_day', 'percent_of_value'],
        'vendor_contracts.status' => ['draft', 'active', 'expired', 'terminated'],
        'vendor_contracts.currency' => ['EGP'],
        // `vendor_documents.type` had NO set, so the column accepted anything — and this one decides
        // dispatchability: a certificate filed under a typo'd type is not the CURRENT document of any
        // type the gate checks, so it neither blocks nor gets chased. The six are the floor the
        // constants held.
        'vendor_documents.type' => [
            'insurance_coi', 'tax_card', 'commercial_register', 'social_insurance', 'trade_license', 'other',
        ],
        'vendors.status' => ['active', 'inactive', 'blacklisted'],
        'vendors.type' => ['contractor', 'supplier', 'service_provider', 'consultant', 'other'],
        // `violations.category` had NO set either, despite its own migration promising the operator
        // could extend it. A typo'd or imported value saved cleanly and then matched no filter and no
        // repeat-offender report. The seven are the floor the const held.
        'violations.category' => [
            'signage', 'operating_hours', 'cleanliness', 'safety', 'unauthorized_works', 'noise', 'other',
        ],
    ];

    /**
     * Columns that LOOK like a classification and are governed somewhere else — each with the
     * registry that actually owns them.
     *
     * `ValueSetCoverageConformanceTest` sweeps every text column whose name ends in a
     * classification suffix and requires it to be either in {@see SETS} or named here. Without the
     * second half the gate would be unshippable: a third of the matches are polymorphic columns
     * holding a morph alias, framework tables Laravel and spatie own, or genuinely free text.
     *
     * An entry here is a CLAIM, and the gate checks two things about it: the column still exists,
     * and the reason is long enough to be reviewable. "Not needed" is what a stale exemption always
     * says.
     *
     * @var array<string, string>
     */
    public const UNCLASSIFIED = [
        // ── Polymorphic: the value is a MORPH ALIAS, and `App\Support\MorphMap` is its registry ──
        // Listing them here as well would be two registries for one fact, and the morph map already
        // throws on an unmapped class — a stronger refusal than this one, because it fires on read
        // as well as on write.
        'activity_log.subject_type' => 'A morph alias. Governed by App\Support\MorphMap, which throws on an unmapped class — on READ as well as write, which this listener cannot do.',
        'activity_log.causer_type' => 'A morph alias naming WHO acted. MorphMap owns it — see activity_log.subject_type.',
        'journal_entries.source_type' => 'A morph alias naming the GL source document. MorphMap owns it, and LedgerPoster::sync() voids an entry whose source_type no longer resolves.',
        'media.model_type' => 'A morph alias (medialibrary). MorphMap owns it.',
        'notes.noteable_type' => 'A morph alias. MorphMap owns it.',
        'notifications.notifiable_type' => 'A morph alias. MorphMap owns it.',
        'posting_month_overrides.source_type' => 'A morph alias. MorphMap owns it.',
        'rentable_item_holdings.holder_type' => 'A morph alias. MorphMap owns it.',
        'stock_movements.source_type' => 'A morph alias. MorphMap owns it.',
        'tenant_request_comments.author_type' => 'A morph alias. MorphMap owns it.',
        'tenant_sales_declarations.declared_by_type' => 'A morph alias. MorphMap owns it.',
        'model_has_permissions.model_type' => 'A morph alias on spatie\'s own pivot. Not ours to constrain.',
        'model_has_roles.model_type' => 'A morph alias on spatie\'s own pivot. Not ours to constrain.',
        'personal_access_tokens.tokenable_type' => 'A morph alias on Sanctum\'s own table. Not ours to constrain.',

        // ── Governed by a CATALOGUE, which is a stronger and more current answer than a list ──
        'charges.type' => 'Validated against the CHARGE CODE catalogue by Charge::assertTypeIsAKnownChargeCode(), so the operator adds a charge type as a row. A fixed list here would refuse one they just created.',
        'invoice_items.type' => 'The CHARGE CODE the line was raised under, and the accountant adds one with no deploy (AccountantAddedChargeCodeBillsTest bills `key_money`). `InvoiceItemType` names the codes that SHIP and the ones the journalizer has posting roles for — it is not the set the column may hold, and registering it refused every code the operator created.',
        'charge_codes.posting_role' => 'A key into App\Support\PostingRoles, which is the registry of what may be posted to. Duplicating its keys here would drift the moment a role is added.',
        'tax_codes.posting_role' => 'A key into App\Support\PostingRoles — see charge_codes.posting_role.',

        // ── Framework-owned ────────────────────────────────────────────────────────────────────
        'notifications.type' => 'Laravel writes the notification CLASS NAME here. It is a FQCN, not a classification, and every new notification class would otherwise need a registry line.',
        'media.mime_type' => 'A MIME type from the uploaded file. Free text by definition — the set is IANA\'s, not ours.',

        // ── Genuinely free text, and a decision rather than an omission ────────────────────────
        'vendor_contacts.role' => 'A job title the operator types — "Operations Lead", "Account Manager". Constraining it would force every supplier\'s org chart into our vocabulary, which is the opposite of what a contact list is for.',
        'department_user.role' => 'The member\'s role WITHIN a department, typed per membership. Same reasoning as vendor_contacts.role.',
        'fixed_assets.category' => 'Free text today, and flagged for a decision rather than a set: it names the KIND of asset (HVAC, generator, lift) and the operator\'s list is theirs. If it is ever made a catalogue it becomes the seventh IsCodeCatalogue, not a literal here.',
        'inventory_items.category' => 'Free text today — see fixed_assets.category. Same decision, same shape if it changes.',
        'inventory_items.unit' => 'Deliberately OPEN: InventoryItemForm offers six suggestions, merges every unit already in use, and carries a createOptionForm so a storekeeper can add one. A fixed set here would refuse the affordance the form advertises.',
        'warehouses.category' => 'Free text: what a store room holds ("spare parts", "consumables"), typed with spaces. Two warehouses in one mall may describe themselves differently and neither is wrong.',
    ];

    /**
     * `table` => (`column` => values), built once.
     *
     * @var array<string, array<string, list<string>>>|null
     */
    private static ?array $byTable = null;

    /**
     * The sets that apply to one table, keyed by column.
     *
     * Indexed on first use rather than scanned, because {@see guard()} runs on every save of every
     * model — including the ~1,300 rows `DemoSeeder` writes on each of ten parallel test workers.
     * A 62-key scan per save would be a measurable tax for an answer that never changes.
     *
     * @return array<string, list<string>>
     */
    public static function forTable(string $table): array
    {
        if (self::$byTable === null) {
            $byTable = [];

            foreach (self::SETS as $key => $values) {
                [$onTable, $column] = explode('.', $key, 2);
                // Expanded here, once, so the guard always compares against real values — an entry
                // declared as an enum class-string would otherwise reach the comparison as a
                // class name and refuse every value the column legitimately holds.
                // Widened here TOO, not only in `allowed()`. The guard reads this map and the
                // pickers read `allowed()`; building them from different sources is what let a
                // surface offer eight values while the listener accepted two — the precise bug
                // `DepositTransaction::methodOptions()` was written to end on 2026-08-18, where the
                // operator picked InstaPay, Filament's `Rule::in` passed, and the save threw with
                // nothing on screen to explain it. One derivation, so they cannot disagree.
                $byTable[$onTable][$column] = self::widen($key, self::expand($values));
            }

            self::$byTable = $byTable;
        }

        return self::$byTable[$table] ?? [];
    }

    /** The values a column accepts, or null when it is not a constrained column. */
    /**
     * Sets whose literal list is a FLOOR that a database catalogue widens.
     *
     * Kept as closures rather than a class-string so the query runs only for the columns that need
     * it — the `saving` listener asks {@see allowed()} for every column of every model save.
     *
     * The four payment-rail columns had drifted into four different lists (7 / 5 / 2 / 2 values),
     * which is why a security deposit received by InstaPay could not be recorded as InstaPay. They
     * share one catalogue now, split only by DIRECTION, because offering a collection network as a
     * way to pay a vendor would be nonsense.
     *
     * @var array<string, callable(): array<int, string>>
     */
    private const CATALOGUE_WIDENED = [
        'employee_advance_repayments.method' => [PaymentMethod::class, 'inboundCodes'],
        'employee_advances.paid_from' => [PaymentMethod::class, 'outboundCodes'],
        'payments.method' => [PaymentMethod::class, 'inboundCodes'],
        'deposit_transactions.method' => [PaymentMethod::class, 'inboundCodes'],
        'vendor_bill_payments.method' => [PaymentMethod::class, 'outboundCodes'],
        'expenses.paid_from' => [PaymentMethod::class, 'outboundCodes'],
        'disbursements.method' => [PaymentMethod::class, 'outboundCodes'],
        'custody_transactions.category' => [ExpenseCategory::class, 'codes'],
        'expenses.category' => [ExpenseCategory::class, 'codes'],
        'vendor_bills.category' => [ExpenseCategory::class, 'codes'],
        'tenant_requests.category' => [TenantRequestSubcategory::class, 'codes'],
        'tenants.retail_category' => [RetailCategory::class, 'codes'],
        'vendor_documents.type' => [VendorDocumentType::class, 'codes'],
        'violations.category' => [ViolationCategory::class, 'codes'],
    ];

    /**
     * The catalogue-widened columns, for the gate that checks every catalogue widens one.
     *
     * Exposed rather than left private because `CatalogueWidensItsColumnsConformanceTest` derives
     * its expectation from the MODELS ON DISK and needs this side to compare against — a gate that
     * read only this registry could not see what the registry omits, which is the whole defect.
     *
     * @return array<string, array{0: class-string, 1: string}>
     */
    public static function catalogueWidenedColumns(): array
    {
        return self::CATALOGUE_WIDENED;
    }

    public static function allowed(string $table, string $column): ?array
    {
        $key = $table.'.'.$column;
        $set = self::SETS[$key] ?? null;

        if ($set === null) {
            return null;
        }

        return self::widen($key, self::expand($set));
    }

    /**
     * Apply a catalogue's rows to a set whose literal list is a FLOOR.
     *
     * The same arrangement `Vat::EXEMPT_TYPES` has with the seeded tax catalogue, and for the same
     * two reasons. An unseeded database — every test that does not seed rails, and a box mid-install
     * — behaves exactly as the literal list this replaced; and an operator who adds Fawry gets a
     * value the listener accepts, without a deploy.
     *
     * It only ever WIDENS. Switching a rail off stops it being offered; it must never invalidate the
     * documents that already name it.
     *
     * @param  array<int, string>  $values
     * @return array<int, string>
     */
    private static function widen(string $key, array $values): array
    {
        $catalogue = self::CATALOGUE_WIDENED[$key] ?? null;

        return $catalogue === null
            ? $values
            : array_values(array_unique([...$values, ...$catalogue()]));
    }

    /**
     * Drop the per-process table cache after a catalogue row changes.
     *
     * `forTable()` memoizes across the whole process because the guard runs on every model save. A
     * catalogue makes that map mutable, so the model that owns the catalogue calls this on write —
     * otherwise an operator activating a rail would be refused by their own request, and by every
     * queue worker until it restarted.
     */
    public static function flushCatalogueCache(): void
    {
        self::$byTable = null;

        // …AND the catalogues' own memos, because the enforced set is DERIVED from them and the two
        // caches are one fact stored twice. Without this the flush cleared `$byTable` and the
        // rebuild immediately re-read the stale container memo behind `codes()` — so `guard()`'s
        // last-chance re-derive, the whole point of which is to recover from a stale map in a
        // long-lived worker, could not recover from anything. It was a no-op from the day it was
        // written and every test passed, because a test process activates a rail and reads it back
        // through the same flush that filled the memo in the first place.
        foreach (array_unique(array_column(self::CATALOGUE_WIDENED, 0)) as $catalogue) {
            if (method_exists($catalogue, 'forgetCatalogueMemos')) {
                $catalogue::forgetCatalogueMemos();
            }
        }
    }

    /**
     * Every set with its values resolved — the EFFECTIVE registry.
     *
     * A set may be declared two ways: a literal list, or the class-string of a backed enum the model
     * also casts to. The second is the better shape where a PHP enum exists, because the cases are
     * then the single source — the model casts against them and this listener refuses against them,
     * so the two cannot drift the way two hand-kept copies of a list always eventually do
     * (CLAUDE.md: don't re-list a set).
     *
     * Conformance reads THIS rather than the raw `SETS`, so it checks what the system actually
     * enforces instead of how the entry happened to be written.
     *
     * @return array<string, list<string>>
     */
    public static function resolved(): array
    {
        return array_map(static fn (array|string $set): array => self::expand($set), self::SETS);
    }

    /**
     * A set may be declared THREE ways, and two of them avoid re-listing anything.
     *
     * - a literal list — for a set that lives nowhere else;
     * - the class-string of a backed ENUM the model also casts to;
     * - `[Model::class, 'STATUSES']` — a CONSTANT the model already declares.
     *
     * The last is the one that keeps this registry honest as it grows past the 62 columns that were
     * DB enums in August 2026. Most classification columns added since already have a `STATUSES` or
     * `TYPES` const on their model that the forms and services read; copying those values here would
     * make two lists to keep in step, which CLAUDE.md's "don't re-list a set" exists to prevent —
     * and a drifted copy is worse than no entry, because it refuses a value the model considers
     * valid.
     *
     * @param  list<string>|class-string<\BackedEnum>|array{0: class-string, 1: string}  $set
     * @return list<string>
     */
    private static function expand(array|string $set): array
    {
        if (is_string($set)) {
            return array_column($set::cases(), 'value');
        }

        // `[Model::class, 'CONST']` — two elements, the first an existing class. A literal list of
        // two strings cannot be mistaken for one: a class name that is also a value set's first
        // member would have to be a real loadable class, which no status ever is.
        if (count($set) === 2 && is_string($set[0]) && is_string($set[1])
            && class_exists($set[0]) && defined($set[0].'::'.$set[1])) {
            return array_values((array) constant($set[0].'::'.$set[1]));
        }

        return $set;
    }

    /**
     * Refuse an out-of-set value on the way in — the replacement for the DB enum's own refusal.
     *
     * Registered once, in `AppServiceProvider`, against the wildcard `eloquent.saving: *` event, so
     * it covers all 39 models without a line in any of them and cannot be forgotten on the fortieth.
     * That is the same reasoning as binding `Filament\Actions\Action` to `AuthorizedAction`: a guard
     * that must never be missing belongs at one seam, not in N files where the gate has to police
     * whether someone remembered it.
     *
     * **Dirty-only**, matching `Charge::assertTypeIsAKnownChargeCode()`. A row already holding a
     * value the set no longer lists must stay editable — otherwise narrowing a set would make
     * historical records unsaveable, and an operator fixing a typo elsewhere on the row would meet a
     * refusal about a field they did not touch. On a create every attribute is dirty, so new records
     * are fully checked.
     *
     * **Null and '' are skipped, deliberately.** Whether the column may be empty is the schema's
     * question — it has a NOT NULL constraint and a default for exactly that — and CLAUDE.md's own
     * invariant is that a blank optional field is coerced in the model. A Filament select that
     * submits '' for a nullable column must not raise a refusal about an unknown value; it hasn't
     * named one.
     *
     * **Known limit, stated rather than implied:** model events do not fire for `Model::insert()`,
     * `DB::table()->insert()` or a raw `UPDATE`, so those paths are now unchecked where MySQL used
     * to refuse them. That is the cost of the trade, and it is why widening a set is a code change
     * in a reviewed file rather than a free-for-all.
     *
     * @throws DomainException when an attribute is set to a value its column does not accept
     */
    public static function guard(Model $model): void
    {
        foreach (self::forTable($model->getTable()) as $column => $allowed) {
            if (! $model->isDirty($column)) {
                continue;
            }

            $value = $model->getAttribute($column);

            // ── A cast column hands back the ENUM, not the string ──────────────────────────────
            // `$casts` turns the attribute into a backed-enum instance on the way out, and casting
            // that to string is a TypeError — so a column that is BOTH registered here and cast on
            // the model used to throw "could not be converted to string" on every single save.
            //
            // It had never happened because the two mechanisms had never met: the only enum-cast
            // column in the app (`tenant_requests.request_type`) is not registered here. Casting is
            // the better shape where a PHP enum exists — the service gets
            // `->operatorCollectsRent()` instead of a string literal — so the guard learns about it
            // rather than the models avoiding it.
            if ($value instanceof \BackedEnum) {
                $value = $value->value;
            }

            if ($value === null || $value === '') {
                continue;
            }

            if (in_array((string) $value, $allowed, true)) {
                continue;
            }

            // ── Last chance before refusing: is our catalogue map stale? ───────────────────────
            // `forTable()` memoizes in a PHP STATIC, and for a catalogue-widened column that map
            // now depends on database state. Both the static and the model's `saved` flush are
            // PROCESS-LOCAL, so a `queue:work` daemon that started before an operator activated
            // Fawry keeps answering from a map built without it — and refuses a job that the very
            // same save through the web accepts. A wrong REFUSAL is the worse direction: the row is
            // lost and the failure looks like a bug in the job.
            //
            // Re-deriving on the FAILURE path only costs nothing on the millions of saves that pass
            // and one query on the handful that would otherwise throw. It is also self-correcting
            // across a rolled-back transaction, which is the other way this static goes stale.
            if (isset(self::CATALOGUE_WIDENED[$model->getTable().'.'.$column])) {
                self::flushCatalogueCache();
                $fresh = self::forTable($model->getTable())[$column] ?? [];

                if (in_array((string) $value, $fresh, true)) {
                    continue;
                }
            }

            throw new DomainException(__('admin.errors.value_not_allowed', [
                'value' => (string) $value,
                'field' => $column,
                'allowed' => implode(', ', $allowed),
            ]));
        }
    }
}
