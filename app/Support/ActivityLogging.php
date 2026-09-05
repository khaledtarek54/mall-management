<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Support\LogOptions;

/**
 * What the audit trail records, and the short list of what it deliberately does not.
 *
 * ## Why this exists: the allowlist was inverted
 *
 * Every audited model used to name the columns it wanted logged — `->logOnly([...])` — which meant
 * a column was invisible to the audit trail until somebody remembered it. Measured 2026-08-24
 * across 84 audited models: **1,063 operator-settable columns, 598 audited, 467 invisible (43%)**,
 * and **33 models where editing `notes` recorded nothing at all** — not a row with an empty diff,
 * no row, because `dontLogEmptyChanges()` suppresses a save in which nothing *watched* moved.
 * `Lease` audited 9 of its 52 fillable columns. That is how it was found: an operator changed the
 * notes on a lease and the activity log stayed empty.
 *
 * Yardi, MRI and Entrata all audit the ENTITY and exclude noise — you switch auditing on for a
 * table and it captures the row diff minus system and derived fields. This inverts Atriom to match:
 * **everything an operator can set is audited unless it is on the list below.** The pressure that
 * produced the short allowlists was real — the vocabulary gate requires an EN+AR label for every
 * logged column, so a nine-column list was the cheap path — but the answer to that is labels, not
 * an audit trail with holes in it.
 *
 * ## Reading the list
 *
 * An entry has to say why the trail is better off without it. Three things earn a place and
 * nothing else does: a value **no person set** (a scheduled scan's stamp), a value **derived from
 * one already audited** (so the row would record a consequence twice and bury the act), and a
 * value that **must never be written down** (a credential). Anything else — including a column
 * that looks like plumbing — is audited, because the cost of an unnecessary row is noise and the
 * cost of a missing one is an operator who cannot answer what happened.
 */
final class ActivityLogging
{
    /**
     * Columns never audited on any model, each with the reason it earns the exclusion.
     *
     * @var array<string, string>
     */
    public const NEVER = [
        // ── Credentials. Non-negotiable: these must not exist in a readable table. `password` is
        // FILLABLE on both User and Tenant, so flipping to logFillable() without this would have
        // started writing password hashes into activity_log on the first save.
        'password' => 'A credential. It must never be written to a readable audit table, and a hash is still a credential.',
        'remember_token' => 'A session credential — churn is not an audit event and the value is a live secret.',
        'two_factor_secret' => 'A live TOTP seed. Logging it would let anyone with log access mint codes.',
        'two_factor_recovery_codes' => 'Single-use bypass codes — logging them defeats the second factor entirely.',
        'two_factor_confirmed_at' => 'Moves as a side effect of the 2FA enrolment flow, which is audited as its own act.',
        'api_token' => 'A live bearer credential.',

        // ── Written by a schedule, not by a person. The trail answers "who did this"; a scan stamp
        // has no who, and one row per nightly sweep per record would bury every human act.
        'last_generated_at' => 'Stamped by the recurring-document generator, not typed by anyone.',
        'last_generated_on' => 'Stamped by the recurring-document generator, not typed by anyone.',

        // ── Derived from a column that IS audited. Recording both writes the consequence beside
        // the act and doubles every diff; the service that recomputes them is the thing to follow.
        // NOT here, deliberately: `paid_amount`, `balance`, `credit_applied_amount`, `paid_to_date`.
        // All four are derived, and the first draft of this list excluded them on that reasoning —
        // which would have REMOVED coverage that already existed (Invoice, VendorBill and CreditNote
        // audit `paid_amount`/`balance` today). They are also the numbers an operator actually asks
        // about, and `credit_applied_amount` is one of the four settlement channels. A flip that
        // widens the trail must never narrow it anywhere; COVERAGE_FLOOR enforces that.
        'landlord_unrecovered_amount' => 'Derived by the CAM generator as actual − Σ allocated.',
        'denominator_used_sqm' => 'Resolved by the CAM apportionment from the basis, which is audited.',
        'grossed_up_expense' => 'Computed from the pool total and gross-up percentage, both audited.',
        'net_operating_income' => 'A statement figure computed from revenue and expense, both audited.',
        'total_expense' => 'A statement roll-up of audited components.',
        'total_revenue' => 'A statement roll-up of audited components.',
        'income_breakdown' => 'A rendered JSON summary of figures audited individually.',
        'search_text' => 'The folded search blob — a pure function of the row\'s own audited attributes, rewritten on every save.',
        'slug' => 'Derived from the name, which is audited.',

        // ── Machine payloads. A provider\'s raw response is evidence on its own record, not a diff
        // a person reads; rendering one in a Changes cell produces a wall of JSON.
        'gateway_response' => 'The payment provider\'s raw response body — kept on the record itself, unreadable as a diff.',
        'description_key' => 'JournalNarrative\'s lookup key; its resolved prose sibling is what a person reads.',
        'description_data' => 'JournalNarrative\'s placeholder payload, meaningless without the key.',
        'custom_fields' => 'The VIRTUAL write attribute for HasCustomFields — the stored `metadata` column is the audited one, and logging both records every answer twice.',

        // ── The frozen module. ETA (module 16) is frozen in code and removed from every operator
        // surface; an audit column would put it back on the one screen the freeze did not clear.
        'eta_status' => 'Module 16 is FROZEN (Modules::FROZEN) and deliberately invisible on every operator surface.',
        'eta_submission_id' => 'Module 16 is FROZEN — see Modules::FROZEN.',
        'eta_long_id' => 'Module 16 is FROZEN — see Modules::FROZEN.',
        'eta_submitted_at' => 'Module 16 is FROZEN — see Modules::FROZEN.',
        'eta_response' => 'Module 16 is FROZEN — see Modules::FROZEN.',
    ];

    /**
     * The entries of {@see NEVER} that nothing may override — not a model's floor, not anything.
     *
     * Kept as its own list because the rest of the denylist is a judgement about noise and this is
     * not: a credential must not exist in a readable audit table, and `password` is FILLABLE on
     * both User and Tenant, so the flip would have written hashes into activity_log on the first
     * save without it.
     *
     * @var list<string>
     */
    public const CREDENTIALS = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
        'api_token',
    ];

    /**
     * Column SUFFIXES never audited, so the family inherits the decision.
     *
     * A suffix rather than a list because these arrive one per feature and always mean the same
     * thing — the alternative is a register that is one column behind the code for ever.
     *
     * @var array<string, string>
     */
    public const NEVER_SUFFIX = [
        '_notified_at' => 'A scheduled scan stamped the moment it sent an alert. There is no causer, and one row per sweep per record would bury every human act in the trail.',
    ];

    /**
     * The polymorphic `*_type` halves — excluded only when a sibling `*_id` column exists.
     *
     * A plain `_type` SUFFIX rule is what a first draft used and it was wrong within minutes:
     * it swallowed `escalation_type` and `percentage_rent_calculation_type` on Lease alone, which
     * are operator classifications and among the most audit-worthy columns on the record. A morph
     * half is identifiable by its PAIR, not by its name — `noteable_type` is structural because
     * `noteable_id` sits beside it, and that test stays right as new ones arrive.
     */
    public const MORPH_TYPE_REASON = 'The structural half of a polymorphic pair (a sibling `*_id` exists) — it stores a morph alias, renders as a class name, and says nothing the audited id beside it does not.';

    /**
     * What each model audited BEFORE the allowlist was inverted — a floor, never a ceiling.
     *
     * The flip widens the trail, and the one way it could go wrong is by narrowing it somewhere: a
     * denylist entry that happens to name a column some model was already auditing removes coverage
     * while every headline number says coverage went up. That is not hypothetical — the first draft
     * of {@see NEVER} excluded `paid_amount` and `balance` as "derived", and Invoice, VendorBill and
     * CreditNote audit both today. They are also the numbers an operator actually asks about.
     *
     * So this records the pre-flip set for all 85 audited models, captured from the source at the
     * moment of the change, and `ActivityLoggingCoversAtLeastWhatItUsedToTest` requires every model's
     * audited set to remain a SUPERSET of its entry. Entries are keyed by model basename rather than
     * `::class` so the register does not drag eighty-five imports behind it.
     *
     * It is deliberately never edited to make a failure go away: a column dropping out of a model's
     * audit is either a mistake or a decision that belongs in {@see NEVER} with a reason.
     *
     * @var array<string, list<string>>
     */
    public const COVERAGE_FLOOR = [
        'AccountMapping' => ['asset_id', 'key', 'ledger_account_id'],
        'ApprovalRule' => ['is_active', 'max_amount', 'min_amount', 'module', 'required_permission'],
        'Area' => ['asset_id', 'code', 'is_active', 'name'],
        'Asset' => ['city', 'code', 'is_active', 'leasable_area_sqm', 'name', 'primary_color', 'type'],
        'BankAccount' => ['account_number', 'asset_id', 'bank_name', 'iban', 'is_active', 'ledger_account_id', 'name'],
        'BankMatch' => ['bank_statement_line_id', 'journal_line_id', 'matched_at'],
        'BankStatement' => ['bank_account_id', 'closing_balance', 'opening_balance', 'period_end', 'period_start'],
        'CamExpensePool' => ['admin_fee_pct', 'reconciled_at', 'recovery_vat_rate', 'status', 'total_actual_expense', 'total_estimated_collected'],
        'Charge' => ['amount', 'frequency', 'is_active', 'lease_id', 'name', 'prorate', 'type'],
        'ChargeCode' => ['code', 'is_active', 'name_ar', 'name_en', 'posting_role', 'tax_code'],
        'CreditNote' => ['applied_amount', 'balance', 'invoice_id', 'number', 'reason', 'status', 'tenant_id', 'total'],
        'Custody' => ['amount', 'asset_id', 'custody_date', 'employee_id', 'paid_from', 'reference'],
        'CustodyTransaction' => ['amount', 'asset_id', 'category', 'custody_id', 'method', 'transaction_date', 'type'],
        'CustomField' => ['is_active', 'is_required', 'key', 'label_ar', 'label_en', 'model', 'options', 'sort_order', 'type'],
        'Department' => ['asset_id', 'code', 'head_user_id', 'is_active', 'name', 'sort_order'],
        'DepositTransaction' => ['amount', 'asset_id', 'lease_id', 'method', 'number', 'status', 'type'],
        'DepreciationEntry' => ['amount', 'fixed_asset_id', 'period_month'],
        'Disbursement' => ['amount', 'external_reference', 'owner_statement_id', 'paid_on', 'reference', 'status', 'user_id'],
        'DocumentTemplate' => ['asset_id', 'body_ar', 'body_en', 'is_active', 'key'],
        'Employee' => ['asset_id', 'base_salary', 'code', 'department_id', 'name', 'payment_method', 'position', 'status'],
        'EmployeeAdvance' => ['advance_date', 'amount', 'asset_id', 'employee_id', 'paid_from', 'type'],
        'EmployeeAdvanceRepayment' => ['amount', 'asset_id', 'employee_advance_id', 'method', 'repaid_on'],
        'Equipment' => ['asset_id', 'code', 'fixed_asset_id', 'is_active', 'location', 'name_ar', 'name_en', 'parent_id', 'trade_id', 'unit_id'],
        'Expense' => ['amount', 'asset_id', 'category', 'number', 'paid_from', 'status', 'total', 'vat_amount'],
        'ExpenseCategory' => ['code', 'cost_nature', 'is_active', 'ledger_account_id', 'name_ar', 'name_en', 'sort_order'],
        'FacilityWorkOrder' => ['acknowledged_at', 'area_id', 'asset_id', 'assigned_to_user_id', 'completed_at', 'cost_bearer', 'equipment_id', 'execution_type', 'fault_notes', 'fault_party', 'parent_work_order_id', 'priority', 'scheduled_for', 'service_plan_id', 'sla_clock', 'status', 'target_resolution_at', 'target_response_at', 'tenant_request_id', 'title', 'trade_id', 'unit_id', 'vendor_id', 'work_order_type'],
        'FacilityWorkOrderLabour' => ['cost', 'facility_work_order_id', 'hourly_rate', 'hours', 'notes', 'trade_id', 'user_id', 'worked_on'],
        'FacilityWorkOrderComment' => ['author_id', 'author_type', 'body', 'facility_work_order_id', 'is_internal'],
        'FacilityWorkOrderPart' => ['decision_notes', 'inventory_item_id', 'quantity', 'required_permission', 'source', 'status', 'unit_cost', 'value'],
        'FailureCode' => ['code', 'is_active', 'name_ar', 'name_en', 'sort_order', 'trade_id', 'type'],
        'FixedAsset' => ['acquisition_cost', 'asset_id', 'name', 'salvage_value', 'status', 'tag', 'useful_life_months'],
        'FixedAssetDisposal' => ['disposed_on', 'fixed_asset_id', 'proceeds', 'proceeds_account'],
        'Floor' => ['asset_id', 'code', 'level', 'name'],
        'Holiday' => ['asset_id', 'closes_at', 'date', 'is_active', 'kind', 'name_ar', 'name_en', 'opens_at'],
        'InventoryItem' => ['category', 'is_active', 'name', 'reorder_level', 'sku', 'unit', 'unit_cost'],
        'Invoice' => ['balance', 'due_date', 'issue_date', 'lease_id', 'number', 'paid_amount', 'status', 'tenant_id', 'total'],
        'JournalEntry' => ['asset_id', 'entry_date', 'number', 'source_id', 'source_type', 'status'],
        'Lease' => ['base_rent_monthly', 'commencement_date', 'expiry_date', 'reference', 'service_charge_monthly', 'status', 'tenant_id', 'term_months', 'unit_id'],
        'LeaseClause' => ['applies_from', 'applies_to', 'notice_days', 'radius_km', 'source_reference', 'summary', 'threshold_amount', 'threshold_pct', 'type'],
        'LeaseOption' => ['earliest_notice_date', 'latest_notice_date', 'notice_given_at', 'resolved_at', 'status', 'type'],
        'LedgerAccount' => ['code', 'is_active', 'is_postable', 'name_ar', 'name_en', 'type'],
        'MarketingBudget' => ['accrued_amount', 'spent_amount', 'status'],
        'MarketingPost' => ['is_featured', 'priority', 'published_at', 'review_notes', 'reviewed_by', 'status', 'title'],
        'MarketingSpend' => ['amount', 'category', 'marketing_budget_id', 'paid_from', 'receipt_reference'],
        'Note' => ['body', 'channel', 'contacted_at', 'subject'],
        'OwnerRequest' => ['assigned_to_user_id', 'priority', 'recipient', 'status', 'subject'],
        'OwnerStatement' => ['owner_share', 'owner_statement_run_id', 'reference', 'sent_at', 'status', 'user_id'],
        'OwnerStatementRun' => ['accounting_period_id', 'asset_id', 'net_distributable', 'reference', 'status', 'version'],
        'Payment' => ['amount', 'method', 'payment_date', 'reference', 'status', 'tenant_id'],
        'PaymentMethod' => ['code', 'for_inbound', 'for_outbound', 'is_active', 'ledger_account_id', 'name_ar', 'name_en', 'settlement_days', 'sort_order'],
        'Payroll' => ['advance_deductions', 'allowances', 'asset_id', 'employer_social_insurance', 'gross_salaries', 'net_paid', 'number', 'other_deductions', 'paid_from', 'salary_tax', 'social_insurance', 'status'],
        'PayrollRate' => ['effective_from', 'employee_social_insurance_rate', 'employer_social_insurance_rate', 'insurable_wage_ceiling', 'insurable_wage_floor', 'note', 'salary_tax_rate'],
        'PostDatedCheque' => ['amount', 'cheque_date', 'cheque_number', 'invoice_id', 'reference', 'status', 'tenant_id'],
        'PropertySetting' => ['asset_id', 'group', 'name', 'payload'],
        'PurchaseRequest' => ['asset_id', 'decision_notes', 'justification', 'order_reference', 'required_permission', 'status', 'total_value', 'vendor_id', 'warehouse_id'],
        'RecurringExpense' => ['amount', 'category', 'day_of_month', 'description', 'ends_on', 'frequency', 'is_active', 'notes', 'starts_on', 'tax_code'],
        'RentIndex' => ['code', 'period', 'published_on', 'value'],
        'RentableItem' => ['area_id', 'asset_id', 'code', 'floor_id', 'monthly_rate', 'name', 'notes', 'status', 'type'],
        'RetailCategory' => ['code', 'is_active', 'name_ar', 'name_en', 'sort_order'],
        'ServicePlan' => ['area_id', 'asset_id', 'days_of_week', 'equipment_id', 'frequency_unit', 'frequency_value', 'is_active', 'next_due_date', 'plan_type', 'title', 'trade_id', 'trigger_type', 'unit_id', 'usage_threshold', 'utility_meter_id'],
        'SlaPenalty' => ['amount', 'basis', 'hours_over_sla', 'rate', 'status', 'vendor_bill_id', 'waive_reason'],
        'SlaPolicy' => ['asset_id', 'is_active', 'priority', 'request_type', 'resolve_hours', 'respond_hours'],
        'StockMovement' => ['inventory_item_id', 'quantity', 'reference', 'type', 'unit_cost', 'warehouse_id'],
        'TaxCode' => ['code', 'direction', 'family', 'invoice_label', 'is_active', 'name_ar', 'name_en', 'posting_role', 'treatment'],
        'TaxRate' => ['effective_from', 'note', 'rate', 'tax_code_id'],
        'Tenant' => ['email', 'legal_name', 'name', 'party_type', 'phone', 'status', 'type'],
        'TenantDocument' => ['coverage_amount', 'expires_on', 'issued_on', 'issuer', 'reference', 'type'],
        'TenantRequest' => ['area_id', 'assigned_to', 'assigned_to_vendor_id', 'category', 'confirmed_at', 'csat_rating', 'decision', 'decision_reason', 'department_id', 'priority', 'request_type', 'resolution_notes', 'sla_clock', 'status', 'target_resolution_at', 'valid_from', 'valid_to'],
        'TenantRequestSubcategory' => ['code', 'is_active', 'name_ar', 'name_en', 'request_type', 'sort_order', 'trade_id'],
        'TenantSalesDeclaration' => ['audit_notes', 'calculated_percentage_rent', 'declared_sales', 'gross_sales', 'locked_at', 'status'],
        'Trade' => ['code', 'default_nte', 'is_active', 'name_ar', 'name_en', 'sort_order', 'standard_hourly_rate'],
        // A unit was audited NOWHERE until 2026-09-05 — the tester's card about the property's
        // Activity Log is what surfaced it, and the gap was far wider than that tab: creating,
        // re-homing, re-categorising or describing a shop recorded nothing at all, anywhere.
        // `area_sqm` is included even though `RemeasureUnitService` is the only thing that may
        // move it: the dated `unit_areas` register records WHAT it became, and the trail records
        // that the current-measurement column moved with it.
        'Unit' => ['area_id', 'area_sqm', 'asset_id', 'category', 'code', 'description', 'floor_id', 'status'],
        'UnitOwnership' => ['assessment_basis', 'ended_at', 'fee_basis', 'management_fee_pct', 'management_mode', 'ownership_share_pct', 'participation_pct', 'reference', 'started_at', 'status', 'tenant_id', 'tenure_type', 'unit_id'],
        'User' => ['email', 'email_verified_at', 'name', 'status', 'suspended_reason'],
        'UtilityTariff' => ['code', 'is_active', 'name_ar', 'name_en', 'provider', 'unit_of_measurement', 'utility_type'],
        'UtilityTariffRate' => ['effective_from', 'note', 'rate_per_unit', 'utility_tariff_id'],
        'Vendor' => ['email', 'name', 'phone', 'status', 'tax_id', 'type', 'withholding_exempt', 'withholding_tax_code'],
        'VendorBill' => ['asset_id', 'balance', 'category', 'number', 'paid_amount', 'penalty_applied_amount', 'status', 'total', 'vendor_id'],
        'VendorContract' => ['end_date', 'name', 'start_date', 'status', 'value'],
        'VendorContractAmendment' => ['effective_on', 'reason', 'reference', 'value_delta'],
        'VendorDocument' => ['expires_on', 'issued_on', 'issuer', 'reference', 'type'],
        'VendorDocumentType' => ['blocks_dispatch', 'code', 'is_active', 'name_ar', 'name_en', 'sort_order'],
        'Violation' => ['asset_id', 'category', 'description', 'fine_amount', 'notified_at', 'status', 'tenant_id', 'violation_date'],
        'ViolationCategory' => ['code', 'default_fine_amount', 'is_active', 'name_ar', 'name_en', 'sort_order'],
        'Warehouse' => ['asset_id', 'category', 'code', 'is_active', 'name'],
        'WorkOrderProposal' => ['decided_at', 'decision_reason', 'facility_work_order_id', 'is_supplementary', 'labour_amount', 'material_amount', 'scope', 'service_amount', 'status', 'total_amount', 'vendor_id'],
        'WorkPermit' => ['area_id', 'closed_at', 'closure_notes', 'conditions', 'contractor_name', 'description', 'facility_work_order_id', 'issued_at', 'location', 'status', 'type', 'unit_id', 'valid_from', 'valid_to', 'vendor_id'],
    ];

    /**
     * The audit options every model shares.
     *
     * @param  Model  $model  the record itself, so suffix rules resolve against its real columns
     * @param  string  $logName  the log this model files under — the one thing that stays per-model
     * @param  list<string>  $alsoLog  columns to audit that are NOT fillable (a service-set column
     *                                 that still records a decision, e.g. FacilityWorkOrder::sla_clock)
     * @param  array<string, string>  $alsoExcept  model-specific exclusions, column => reason
     */
    public static function for(Model $model, string $logName, array $alsoLog = [], array $alsoExcept = []): LogOptions
    {
        $excluded = self::excludedFor($model, $alsoExcept);

        return LogOptions::defaults()
            // Everything an operator can set. `logFillable()` and `logOnly()` UNION rather than
            // override (spatie merges fillable ∪ unguarded ∪ explicit, then subtracts excluded),
            // which is what lets $alsoLog add a non-fillable column back.
            ->logFillable()
            ->logOnly($alsoLog)
            ->logExcept($excluded)
            ->logOnlyDirty()
            // Without this a save touching ONLY excluded columns still writes a row — spatie
            // decides whether to log by diffing getDirty() against this list, not against the
            // logged set, so a password-only save produced a row with an empty diff. `updated_at`
            // belongs here because it is dirty on every save.
            ->dontLogIfAttributesChangedOnly([...$excluded, 'updated_at'])
            ->dontLogEmptyChanges()
            ->useLogName($logName);
    }

    /**
     * The columns this model actually audits — the question every gate over the trail has to ask.
     *
     * **`$options->logAttributes` is NOT the answer, and reading it as one is how a gate goes
     * blind.** Since the denylist flip (2026-08-24) `for()` composes `logFillable()` +
     * `logOnly($alsoLog)` − `logExcept($excluded)`, so `logAttributes` holds only the handful of
     * non-fillable columns three models pass through `$alsoLog`. A sweep reading it literally sees
     * almost nothing while appearing to walk all 85 audited models —
     * `LoggedValuesResolveConformanceTest` was doing exactly that and its own "am I vacuous?"
     * assertion is what caught it, finding **1** logged code-valued column where it expected 30.
     *
     * Derived from the model's OWN options rather than re-deriving the rule, so a change to what
     * `for()` composes reaches every caller. Dotted paths and the `*` wildcard are dropped: neither
     * is a column on this table.
     *
     * @return array<int, string>
     */
    public static function auditedColumns(Model $model): array
    {
        if (! method_exists($model, 'getActivitylogOptions')) {
            return [];
        }

        $options = $model->getActivitylogOptions();

        $named = array_filter(
            $options->logAttributes,
            fn ($column): bool => is_string($column) && $column !== '*' && ! str_contains($column, '.'),
        );

        $columns = $options->logFillable
            ? array_merge($model->getFillable(), $named)
            : $named;

        return array_values(array_diff(array_unique($columns), $options->logExceptAttributes));
    }

    /**
     * The exclusions that actually apply to one model — the shared list narrowed to its columns.
     *
     * Narrowed rather than passed whole so that `logExcept()` names only real columns, which keeps
     * the conformance gate able to tell a live exclusion from a stale one.
     *
     * @param  array<string, string>  $alsoExcept
     * @return list<string>
     */
    public static function excludedFor(Model $model, array $alsoExcept = []): array
    {
        $columns = [...$model->getFillable(), ...array_keys($alsoExcept)];

        // The floor BEATS the denylist, structurally rather than by my curating the denylist
        // correctly. A rule written to remove noise will eventually name a column some model was
        // already auditing — the morph-half rule did exactly that to `JournalEntry.source_type`,
        // caught only because the floor gate existed — and the answer is for the flip to be
        // incapable of narrowing, not for the next author to notice.
        //
        // Credentials are the one thing it cannot override: a hash must not be written down even
        // if some model once logged it. Nothing in the floor is a credential today, and this is
        // what keeps that true if one ever appears.
        $floor = self::COVERAGE_FLOOR[class_basename($model)] ?? [];

        $excluded = array_filter(
            $columns,
            fn (string $column): bool => in_array($column, self::CREDENTIALS, true)
                || (
                    ! in_array($column, $floor, true)
                    && (
                        array_key_exists($column, self::NEVER)
                        || array_key_exists($column, $alsoExcept)
                        || self::matchesNeverSuffix($column)
                        || self::isMorphTypeHalf($column, $columns)
                    )
                ),
        );

        return array_values(array_unique($excluded));
    }

    /** Whether a column falls under one of the {@see NEVER_SUFFIX} families. */
    public static function matchesNeverSuffix(string $column): bool
    {
        foreach (array_keys(self::NEVER_SUFFIX) as $suffix) {
            if (str_ends_with($column, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A `*_type` column that is the structural half of a morph pair — see {@see MORPH_TYPE_REASON}.
     *
     * @param  list<string>  $columns  the model's own columns, which is what makes the pair visible
     */
    public static function isMorphTypeHalf(string $column, array $columns): bool
    {
        if (! str_ends_with($column, '_type')) {
            return false;
        }

        return in_array(substr($column, 0, -5).'_id', $columns, true);
    }
}
