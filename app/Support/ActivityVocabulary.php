<?php

namespace App\Support;

use App\Models\AccountingPeriod;
use App\Models\Area;
use App\Models\Asset;
use App\Models\BankAccount;
use App\Models\Custody;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\Equipment;
use App\Models\FacilityWorkOrder;
use App\Models\FixedAsset;
use App\Models\Floor;
use App\Models\InventoryItem;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\LedgerAccount;
use App\Models\MarketingBudget;
use App\Models\OwnerStatement;
use App\Models\OwnerStatementRun;
use App\Models\ServicePlan;
use App\Models\TaxCode;
use App\Models\Tenant;
use App\Models\TenantRequest;
use App\Models\Trade;
use App\Models\Unit;
use App\Models\User;
use App\Models\UtilityMeter;
use App\Models\UtilityTariff;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Models\Warehouse;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Lang;

/**
 * The audit trail's vocabulary — the ONE place a raw activity row becomes words.
 *
 * **The rule this class exists to enforce: the activity log stores DATA, never PROSE.**
 * A row records a `log_name`, an `event`, field keys and raw values; every human-readable
 * word is resolved HERE, at read time. Two consequences, and they are the whole point:
 * the same historical row reads correctly in Arabic and in English, and fixing a
 * translation retroactively fixes every row ever written. A sentence baked in at write
 * time (`->log('Invoice voided')`) can never be either — which is why the five services
 * that used to do that now store keys instead.
 *
 * Four questions, four methods: which record TYPE (`subject`), what HAPPENED (`event` /
 * `description`), which FIELD moved (`field`), and what it moved FROM/TO (`value`).
 *
 * Consumed by `ActivityLogChangeRenderer` (both the standalone Activity Log page and the
 * embedded ActivitiesRelationManager) — nothing else should `__()` on activity data, or
 * the two surfaces drift.
 */
class ActivityVocabulary
{
    /**
     * Value vocabularies that the conventions below can't derive — **exceptions only**.
     *
     * Keyed `{log_name}.{field}` → translation-group prefix. Two conventions cover the
     * common case with no entry at all:
     *   - `status` → `admin.statuses.{log_name}` (invoice, lease, payment, tenant,
     *     credit_note, vendor_bill, payroll, … — 15 log names already agree by name)
     *   - anything else → no vocabulary, the raw value renders.
     *
     * So this list is only the modules whose catalogue sits somewhere else (`admin.enums.*`,
     * a module section's own `statuses` block) or whose field isn't called `status`.
     *
     * `ActivityLogVocabularyConformanceTest` asserts every prefix here resolves in BOTH
     * locales, so a typo'd group is a red build rather than a value that silently renders raw.
     *
     * @var array<string, string>
     */
    private const VALUE_VOCABULARY = [
        // Both render raw without this: the `admin.statuses.{log_name}` convention only fires
        // for a field literally called `status`.
        'holiday.kind' => 'admin.facility.holiday.kinds',
        'facility_work_order.sla_clock' => 'admin.facility.sla_clocks',
        // The SAME catalogue: module 11 and module 26 promise on one set of clocks, and a
        // second list of words for it would be a second answer to one question.
        'tenant_request.sla_clock' => 'admin.facility.sla_clocks',
        // Statuses whose catalogue is not `admin.statuses.{log_name}`.
        'disbursement.status' => 'admin.disbursements.statuses',
        'employee.status' => 'admin.employees.statuses',
        'fixed_asset.status' => 'admin.fixed_assets.statuses',
        'lease_option.status' => 'admin.lease_options.statuses',
        // Pointed at the group the clause FORM's own Select reads from, which is the rule that
        // stopped `expense.category` being handed the retail list: pick the group by what the
        // field is, never by which keys happen to overlap.
        'lease_clause.type' => 'admin.enums.lease_clause_type',
        'work_permit.type' => 'admin.enums.work_permit_type',
        'work_permit.status' => 'admin.enums.work_permit_status',
        'facility_work_order.status' => 'admin.facility.statuses',
        'marketing_budget.status' => 'admin.enums.marketing_budget_status',
        'marketing_post.status' => 'admin.marketing_posts.statuses',
        'owner_request.status' => 'admin.owner_requests.statuses',
        'owner_statement.status' => 'admin.owner_statements.statuses',
        'owner_statement_run.status' => 'admin.owner_statements.statuses',
        'post_dated_cheque.status' => 'admin.post_dated_cheques.statuses',
        'purchase_request.status' => 'admin.procurement.statuses',
        'rentable_item.status' => 'admin.enums.rentable_item_status',
        // The ownership lifecycle. Its labels live with the enum they are cast from
        // (`admin.enums.*`), not under `admin.statuses`, so the audit trail and the form read the
        // SAME words rather than two catalogues that drift.
        'unit_ownership.status' => 'admin.enums.unit_ownership_status',
        'user.status' => 'admin.users.statuses',

        // Everything else, keyed by the catalogue the FORM for that field reads from — checked
        // against the real Select options, not guessed from key overlap. Two traps that caught
        // out an automated match: `admin.enums.category` is the RETAIL category list (retail,
        // food_beverage, …), NOT an expense category; and TenantRequest's field is
        // `request_type`, not `type`.
        'account_mapping.key' => 'admin.posting_roles',
        'approval_rule.module' => 'admin.enums.approval_module',
        'asset.type' => 'admin.enums.asset_type',
        'charge.frequency' => 'admin.charge_schedule.frequencies',
        'charge.type' => 'admin.enums.invoice_item_type',
        'charge_code.posting_role' => 'admin.posting_roles',
        'credit_note.reason' => 'admin.enums.credit_note_reason',
        'custody.paid_from' => 'admin.enums.expense_paid_from',
        'custody_transaction.category' => 'admin.enums.vendor_bill_category',
        // `expense_paid_from`, not `method`: this column holds `cash|bank` and the rail group names
        // neither `bank` nor anything else it can hold. Surfaced the moment the column gained a
        // `ValueSets` entry — before that, nothing knew what values to check the vocabulary against.
        'custody_transaction.method' => 'admin.enums.expense_paid_from',
        'custody_transaction.type' => 'admin.custodies.types',
        'deposit_transaction.method' => 'admin.enums.expense_paid_from',
        'deposit_transaction.type' => 'admin.enums.deposit_type',
        'employee.payment_method' => 'admin.employees.methods',
        'employee_advance.paid_from' => 'admin.enums.expense_paid_from',
        'employee_advance.type' => 'admin.employees.types',
        'employee_advance_repayment.method' => 'admin.employees.methods',
        // The catalogue's own column — pointed at the group the FORM's Select reads from, which is
        // the rule that stopped `expense.category` being handed the retail list.
        'expense_category.cost_nature' => 'admin.enums.cost_nature',
        'sla_policy.request_type' => 'admin.enums.request_type',
        'expense.category' => 'admin.enums.vendor_bill_category',
        'expense.paid_from' => 'admin.enums.expense_paid_from',
        'fixed_asset_disposal.proceeds_account' => 'admin.enums.cash_or_bank',
        'inventory_item.unit' => 'admin.enums.inventory_unit',
        'ledger_account.type' => 'admin.enums.ledger_account_type',
        'lease_option.type' => 'admin.lease_options.types',
        'service_plan.frequency_unit' => 'admin.facility.frequency_units',
        'service_plan.plan_type' => 'admin.facility.plan_types',
        'facility_work_order.category' => 'admin.enums.work_category',
        'facility_work_order.execution_type' => 'admin.facility.execution_types',
        'facility_work_order.work_order_type' => 'admin.facility.work_order_types',
        'sla_penalty.basis' => 'admin.facility.penalty.bases',
        'sla_penalty.status' => 'admin.facility.penalty.statuses',
        'work_order_part.source' => 'admin.facility.parts.sources',
        'work_order_part.status' => 'admin.facility.parts.statuses',
        'facility_work_order.priority' => 'admin.enums.work_priority',
        'marketing_spend.category' => 'admin.enums.marketing_spend_category',
        'marketing_spend.paid_from' => 'admin.enums.expense_paid_from',
        'note.channel' => 'admin.enums.note_channel',
        // Every value-set column on a unit ownership, pointed at the SAME `admin.enums.*` group its
        // backed enum labels from. Without these the audit trail renders the stored value verbatim —
        // an Arabic reader would see `handed_over` and `operator_managed` in an otherwise Arabic
        // diff, which is precisely what storing data and resolving words at read time exists to
        // avoid. `tenure_type` matters most: تمليك and حق انتفاع are different legal instruments,
        // and a diff that cannot name which one changed is a diff nobody can act on.
        'tenant.party_type' => 'admin.enums.party_type',
        'unit_ownership.assessment_basis' => 'admin.enums.assessment_basis',
        'unit_ownership.fee_basis' => 'admin.enums.management_fee_basis',
        'unit_ownership.management_mode' => 'admin.enums.unit_management_mode',
        'unit_ownership.tenure_type' => 'admin.enums.unit_tenure_type',
        'owner_request.priority' => 'admin.owner_requests.priorities',
        'owner_request.recipient' => 'admin.enums.owner_request_recipient',
        'payment.method' => 'admin.enums.method',
        'payroll.paid_from' => 'admin.enums.expense_paid_from',
        'rentable_item.type' => 'admin.enums.rentable_item_type',
        'sla_policy.priority' => 'admin.enums.work_priority',
        'stock_movement.type' => 'admin.inventory.types',
        'tax_code.direction' => 'admin.enums.tax_direction',
        'tax_code.family' => 'admin.enums.tax_family',
        'tax_code.posting_role' => 'admin.posting_roles',
        'tax_code.treatment' => 'admin.enums.tax_treatment',
        // Pointed at the group the FORM's Select reads from, which is the rule that stops a log
        // and a form calling the same value two different things. The field-label gate demanded a
        // LABEL for this column and was satisfied; it does not check that the VALUE resolves, so
        // the log printed a raw `electric` beside a form that says "Electricity".
        'service_plan.trigger_type' => 'admin.facility.trigger_types',
        'utility_tariff.utility_type' => 'admin.enums.meter_type',
        'tenant.type' => 'admin.enums.tenant_type',
        'tenant_document.type' => 'admin.enums.tenant_document_type',
        'tenant_request.category' => 'admin.enums.tenant_request_subcategory',
        'tenant_request.decision' => 'admin.statuses.tenant_request_decision',
        'tenant_request.priority' => 'admin.enums.work_priority',
        'tenant_request.request_type' => 'admin.enums.tenant_request_type',
        'vendor.type' => 'admin.enums.vendor_type',
        'vendor_bill.category' => 'admin.enums.vendor_bill_category',
        'vendor_document.type' => 'admin.vendors.documents.types',
        'violation.category' => 'admin.violations.categories',
        'warehouse.category' => 'admin.enums.category_suggestions.warehouse',
    ];

    /**
     * Foreign-key column → the model it points at, so a diff reads "Tenant  Nour Retail" rather
     * than "Tenant  42". An id is not an audit trail: nobody remembers which lease is 328.
     *
     * Keys are either a bare column name (unambiguous across every model that logs it) or
     * `{log_name}.{field}` where the column would mean something else elsewhere — `parent_id`
     * is Equipment's own parent here, and must not become a global rule.
     *
     * `charge_code`/`account_mapping` codes are deliberately absent from the VALUE_VOCABULARY
     * above for the same family of reason: a code is an identifier the accountant types and
     * searches for, so it stays verbatim in both languages.
     *
     * @var array<string, class-string<Model>>
     */
    private const FOREIGN_KEYS = [
        'accounting_period_id' => AccountingPeriod::class,
        'area_id' => Area::class,
        'trade_id' => Trade::class,
        'asset_id' => Asset::class,
        'assigned_to_user_id' => User::class,
        'assigned_to_vendor_id' => Vendor::class,
        'bank_account_id' => BankAccount::class,
        'custody_id' => Custody::class,
        'department_id' => Department::class,
        'employee_advance_id' => EmployeeAdvance::class,
        'employee_id' => Employee::class,
        'equipment.parent_id' => Equipment::class,
        'equipment_id' => Equipment::class,
        'fixed_asset_id' => FixedAsset::class,
        'floor_id' => Floor::class,
        'head_user_id' => User::class,
        'inventory_item_id' => InventoryItem::class,
        'invoice_id' => Invoice::class,
        'lease_id' => Lease::class,
        'ledger_account_id' => LedgerAccount::class,
        'service_plan_id' => ServicePlan::class,
        'marketing_budget_id' => MarketingBudget::class,
        'owner_statement_id' => OwnerStatement::class,
        'owner_statement_run_id' => OwnerStatementRun::class,
        'parent_work_order_id' => FacilityWorkOrder::class,
        'tax_code_id' => TaxCode::class,
        'utility_tariff_id' => UtilityTariff::class,
        'utility_meter_id' => UtilityMeter::class,
        'tenant_id' => Tenant::class,
        'tenant_request_id' => TenantRequest::class,
        'unit_id' => Unit::class,
        'user_id' => User::class,
        'vendor_bill_id' => VendorBill::class,
        'vendor_id' => Vendor::class,
        'warehouse_id' => Warehouse::class,
    ];

    /**
     * How to name a referenced record, most specific first.
     *
     * `label()` / `displayName()` are the project's existing bilingual-name convention
     * (ChargeCode, LedgerAccount); the rest are the identifying columns documents carry.
     */
    private const LABEL_METHODS = ['label', 'displayName'];

    private const LABEL_COLUMNS = ['reference', 'number', 'name', 'code', 'title'];

    /** Lowercase acronym → display form, for the humanised fallback only. */
    private const ACRONYMS = [
        'eta' => 'ETA',
        'vat' => 'VAT',
        'id' => 'ID',
        'pdf' => 'PDF',
        'url' => 'URL',
        'sla' => 'SLA',
        'cam' => 'CAM',
        'sku' => 'SKU',
    ];

    /**
     * Cast map per subject class, memoised.
     *
     * A 100-row page can reference the same model dozens of times, and `new $class` on
     * every cell would instantiate it dozens of times for a `$casts` array that never varies.
     *
     * @var array<class-string, array<string, string>>
     */
    private array $castCache = [];

    /**
     * Referenced record id → display name, per class. A null entry is a remembered MISS (the
     * record was deleted), which is what stops a missing id being re-queried once per cell.
     *
     * @var array<class-string, array<int, string|null>>
     */
    private array $referenceCache = [];

    /** The record type — the "What" badge. */
    public function subject(?string $logName): string
    {
        if (! $logName) {
            return __('admin.activity.subjects.default');
        }

        return Lang::has("admin.activity.subjects.{$logName}")
            ? __("admin.activity.subjects.{$logName}")
            : __('admin.activity.subjects.default');
    }

    /** The verb — the "Event" badge. */
    public function event(?string $event): string
    {
        if (! $event) {
            return '—';
        }

        return Lang::has("admin.activity.events.{$event}")
            ? __("admin.activity.events.{$event}")
            : $this->humanise($event);
    }

    /**
     * The stored description.
     *
     * Modern rows store a key (`invoice.voided`). Rows written before that change hold an
     * English sentence, so an unresolvable value falls back to itself rather than showing
     * the operator a key — history stays readable while the catalogue is what's read going forward.
     */
    public function description(?string $description): string
    {
        if (! $description) {
            return '';
        }

        return Lang::has("admin.activity.descriptions.{$description}")
            ? __("admin.activity.descriptions.{$description}")
            : $description;
    }

    /**
     * The field label — layered, reusing the catalogue the FORMS already label from.
     *
     *   1. `admin.activity.fields.{log_name}.{field}` — per-model override, for the rare
     *      field that means something different on one model.
     *   2. `admin.fields.{field}` — the shared catalogue. The audit trail then calls a field
     *      by the same name the form that edits it does, which is a correctness property and
     *      not merely DRY.
     *   3. Humanised — deliberately kept, because history contains columns that no longer
     *      exist and a dropped column must still render. The conformance gate ensures no
     *      CURRENTLY-logged field ever reaches this rung.
     */
    public function field(?string $logName, string $field): string
    {
        if ($logName && Lang::has("admin.activity.fields.{$logName}.{$field}")) {
            return __("admin.activity.fields.{$logName}.{$field}");
        }

        if (Lang::has("admin.fields.{$field}")) {
            return __("admin.fields.{$field}");
        }

        return $this->humanise($field);
    }

    /**
     * Whether `field()` resolves from a catalogue rather than falling through to the
     * humaniser. Exists for the conformance gate — "it returned a string" proves nothing,
     * since the fallback always returns one.
     *
     * **`fallback: false` is the whole point of this method.** `Lang::has($key, 'ar')` defaults
     * to `$fallback = true`, so a key present in English and missing in Arabic answers TRUE for
     * Arabic — an EN/AR parity check written the obvious way can only ever catch a key missing
     * from BOTH files, which is not the failure anyone is worried about. Every lookup on the
     * READ path above deliberately keeps the fallback (English beats a raw key on screen); this
     * one, which exists to find gaps, must not.
     */
    public function hasFieldLabel(?string $logName, string $field, ?string $locale = null): bool
    {
        return ($logName && Lang::has("admin.activity.fields.{$logName}.{$field}", $locale, fallback: false))
            || Lang::has("admin.fields.{$field}", $locale, fallback: false);
    }

    /**
     * A single value, on either side of the arrow.
     *
     * Formatting is derived from the subject model's own `$casts` rather than a hand-kept
     * list of "which fields are money" — so a new module gets correct formatting for free
     * and a column that changes type can't leave a registry stale behind it.
     */
    public function value(?string $logName, ?string $subjectType, string $field, mixed $value): string
    {
        if ($value === null || $value === '') {
            return __('admin.activity.empty_value');
        }

        // A catalogued value (status / enum) wins over cast-driven formatting: these are
        // string columns, and the raw token is exactly what must not reach the operator.
        if (is_scalar($value) && ($translated = $this->lookupValue($logName, $field, (string) $value)) !== null) {
            return $translated;
        }

        // A foreign key names its record instead of showing a bare id.
        if (is_scalar($value) && ($named = $this->lookupReference($logName, $field, (string) $value)) !== null) {
            return $named;
        }

        return $this->format($value, $this->castFor($subjectType, $field));
    }

    /**
     * Resolve every foreign key on a page of activity rows in ONE query per referenced table.
     *
     * Both surfaces call this before rendering. Without it, `lookupReference()` would still be
     * correct but would issue a query per distinct id — a classic N+1 on a 100-row page whose
     * rows all reference different leases. With it, a page costs one `whereIn` per model.
     *
     * @param  iterable<object>  $activities
     */
    public function preloadReferences(iterable $activities): void
    {
        $wanted = [];

        foreach ($activities as $activity) {
            foreach ([$activity->attribute_changes ?? null, $activity->properties ?? null] as $payload) {
                // Both columns cast to a Collection. `(array)` on one yields its PROTECTED
                // PROPERTIES, not its data — so an unconverted Collection here collects nothing,
                // the batch stays empty and every cell silently falls back to a query of its own.
                // The page looks perfect either way; only the query count tells you.
                $payload = $payload instanceof Arrayable ? $payload->toArray() : (array) $payload;

                // Both diff shapes plus scalar context, flattened — see ActivityLogChangeRenderer.
                $rows = array_merge(
                    (array) ($payload['attributes'] ?? []),
                    (array) ($payload['old'] ?? []),
                    (array) ($payload['changes'] ?? []),
                    $payload,
                );

                foreach ($rows as $field => $value) {
                    $class = $this->referencedClass($activity->log_name ?? null, (string) $field);
                    if ($class === null) {
                        continue;
                    }

                    foreach (is_array($value) ? $value : [$value] as $candidate) {
                        if (is_numeric($candidate) && ! isset($this->referenceCache[$class][(int) $candidate])) {
                            $wanted[$class][(int) $candidate] = true;
                        }
                    }
                }
            }
        }

        foreach ($wanted as $class => $ids) {
            $this->loadReferences($class, array_keys($ids));
        }
    }

    /**
     * The model a column points at — the model-specific key first, then the global one.
     *
     * @return class-string<Model>|null
     */
    private function referencedClass(?string $logName, string $field): ?string
    {
        return ($logName ? (self::FOREIGN_KEYS["{$logName}.{$field}"] ?? null) : null)
            ?? self::FOREIGN_KEYS[$field]
            ?? null;
    }

    /** The referenced record's display name, or null when this column is not a known key. */
    private function lookupReference(?string $logName, string $field, string $value): ?string
    {
        $class = $this->referencedClass($logName, $field);

        if ($class === null || ! is_numeric($value)) {
            return null;
        }

        $id = (int) $value;

        if (! array_key_exists($id, $this->referenceCache[$class] ?? [])) {
            // Not preloaded (a single render, or a row that arrived after the sweep). One query,
            // then cached — including the miss, so a deleted record is not re-queried per cell.
            $this->loadReferences($class, [$id]);
        }

        return $this->referenceCache[$class][$id] ?? null;
    }

    /**
     * @param  class-string<Model>  $class
     * @param  list<int>  $ids
     */
    private function loadReferences(string $class, array $ids): void
    {
        // Seed every requested id as a miss first, so an id that no longer exists is remembered
        // as absent rather than re-queried on every subsequent cell.
        foreach ($ids as $id) {
            $this->referenceCache[$class][$id] ??= null;
        }

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return;
        }

        // withTrashed where available: history routinely references records since retired, and
        // "Tenant 42" is exactly the rendering this method exists to remove.
        $query = $class::query();
        if (method_exists($query, 'withTrashed')) {
            $query->withTrashed();
        }

        foreach ($query->whereKey($ids)->get() as $record) {
            $this->referenceCache[$class][$record->getKey()] = $this->describeReference($record);
        }
    }

    /**
     * How a record names itself, for the Record column. Null when there is no subject (a deleted
     * one, or a row like `settings` that has none) so the caller can fall back.
     */
    public function describeSubject(?Model $subject): ?string
    {
        return $subject ? $this->describeReference($subject) : null;
    }

    /** How this record names itself — the project's `label()`/`displayName()` convention first. */
    private function describeReference(Model $record): ?string
    {
        foreach (self::LABEL_METHODS as $method) {
            if (method_exists($record, $method)) {
                $label = $record->{$method}();
                if (is_string($label) && $label !== '') {
                    return $label;
                }
            }
        }

        foreach (self::LABEL_COLUMNS as $column) {
            $value = $record->getAttribute($column);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * The catalogued label for an enum-ish value, or null when there is no vocabulary
     * for this field (in which case the raw value is the honest rendering).
     */
    private function lookupValue(?string $logName, string $field, string $value): ?string
    {
        if (! $logName) {
            return null;
        }

        $prefix = self::VALUE_VOCABULARY["{$logName}.{$field}"]
            ?? ($field === 'status' ? "admin.statuses.{$logName}" : null);

        if ($prefix === null) {
            return null;
        }

        return Lang::has("{$prefix}.{$value}") ? __("{$prefix}.{$value}") : null;
    }

    /** Render a value according to its Eloquent cast. */
    private function format(mixed $value, ?string $cast): string
    {
        if (is_bool($value)) {
            return $this->bool($value);
        }

        if (is_array($value) || is_object($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE) ?: '';
        }

        $base = strtok((string) $cast, ':') ?: '';

        return match ($base) {
            'bool', 'boolean' => $this->bool((bool) $value),
            // number_format is locale-independent, so figures stay in Latin digits under
            // `ar` — the project-wide rule pinned by LatinNumeralsTest.
            'decimal' => number_format((float) $value, (int) (explode(':', (string) $cast)[1] ?? 2)),
            'float', 'double', 'real' => number_format((float) $value, 2),
            'int', 'integer' => number_format((int) $value),
            'date', 'immutable_date' => $this->date($value, 'd/m/Y'),
            'datetime', 'immutable_datetime', 'timestamp' => $this->date($value, 'd/m/Y H:i'),
            'array', 'json', 'object', 'collection' => $this->json($value),
            default => (string) $value,
        };
    }

    private function bool(bool $value): string
    {
        return $value ? __('admin.activity.bool_true') : __('admin.activity.bool_false');
    }

    /** A stored date string, or the raw value when it isn't parseable (history). */
    private function date(mixed $value, string $format): string
    {
        try {
            return Carbon::parse((string) $value)->format($format);
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    /** An array-cast column arrives from the log as a JSON string, not an array. */
    private function json(mixed $value): string
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return json_last_error() === JSON_ERROR_NONE
                ? (json_encode($decoded, JSON_UNESCAPED_UNICODE) ?: $value)
                : $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE) ?: '';
    }

    /**
     * The declared cast for a field, or null when the subject class is unknown — which is
     * normal for history whose model has since been renamed or removed.
     */
    private function castFor(?string $subjectType, string $field): ?string
    {
        if (! $subjectType) {
            return null;
        }

        $class = Relation::getMorphedModel($subjectType) ?? $subjectType;

        if (! isset($this->castCache[$class])) {
            // An empty map is cached too — a removed model must not be probed once per cell.
            $this->castCache[$class] = (class_exists($class) && is_subclass_of($class, Model::class))
                ? (new $class)->getCasts()
                : [];
        }

        return $this->castCache[$class][$field] ?? null;
    }

    /** `base_rent` → `Base rent`. The last rung of every lookup above. */
    private function humanise(string $key): string
    {
        $words = explode(' ', str_replace('_', ' ', $key));
        $words[0] = ucfirst($words[0]);

        return implode(' ', array_map(
            fn (string $w): string => self::ACRONYMS[strtolower($w)] ?? $w,
            $words,
        ));
    }
}
