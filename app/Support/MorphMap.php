<?php

namespace App\Support;

use App\Models\AccountingPeriod;
use App\Models\AccountMapping;
use App\Models\Announcement;
use App\Models\AnnouncementRecipient;
use App\Models\ApprovalRule;
use App\Models\Area;
use App\Models\Asset;
use App\Models\AssetOwner;
use App\Models\BankAccount;
use App\Models\BankMatch;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\Bin;
use App\Models\BudgetLine;
use App\Models\CamAllocation;
use App\Models\CamExpensePool;
use App\Models\Charge;
use App\Models\ChargeCode;
use App\Models\CreditNote;
use App\Models\CreditNoteApplication;
use App\Models\CreditNoteItem;
use App\Models\Custody;
use App\Models\CustodyTransaction;
use App\Models\Department;
use App\Models\DepositApplication;
use App\Models\DepositTransaction;
use App\Models\DepreciationEntry;
use App\Models\DeviceToken;
use App\Models\Disbursement;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\EmployeeAdvanceRepayment;
use App\Models\Equipment;
use App\Models\Expense;
use App\Models\FacilityWorkOrder;
use App\Models\FacilityWorkOrderItem;
use App\Models\FacilityWorkOrderPart;
use App\Models\FiscalYear;
use App\Models\FixedAsset;
use App\Models\FixedAssetDisposal;
use App\Models\Floor;
use App\Models\InventoryItem;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceWriteOff;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Lease;
use App\Models\LeaseCamTerm;
use App\Models\LeaseEvent;
use App\Models\LeaseOption;
use App\Models\LeasePercentageRentTier;
use App\Models\LedgerAccount;
use App\Models\LowStockAlert;
use App\Models\MarketingBudget;
use App\Models\MarketingPost;
use App\Models\MarketingSpend;
use App\Models\MeterReading;
use App\Models\Note;
use App\Models\OwnerRequest;
use App\Models\OwnerRequestReply;
use App\Models\OwnerStatement;
use App\Models\OwnerStatementRun;
use App\Models\Payment;
use App\Models\Payroll;
use App\Models\PayrollLine;
use App\Models\PostDatedCheque;
use App\Models\PropertySetting;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestLine;
use App\Models\RentableItem;
use App\Models\RentIndex;
use App\Models\ReportPreference;
use App\Models\SavedReport;
use App\Models\ServicePlan;
use App\Models\SlaPenalty;
use App\Models\SlaPolicy;
use App\Models\StockMovement;
use App\Models\StraightLineRentAdjustment;
use App\Models\SystemSetting;
use App\Models\TableView;
use App\Models\TaxCode;
use App\Models\TaxRate;
use App\Models\Tenant;
use App\Models\TenantCreditApplication;
use App\Models\TenantDocument;
use App\Models\TenantRequest;
use App\Models\TenantRequestComment;
use App\Models\TenantSalesDeclaration;
use App\Models\TenantUser;
use App\Models\Unit;
use App\Models\UnitArea;
use App\Models\UnitOwnership;
use App\Models\User;
use App\Models\UtilityMeter;
use App\Models\UtilityTariff;
use App\Models\UtilityTariffRate;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Models\VendorBillPayment;
use App\Models\VendorContact;
use App\Models\VendorContract;
use App\Models\VendorContractAmendment;
use App\Models\VendorDocument;
use App\Models\Violation;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * **The alias every polymorphic column stores, instead of a class name.**
 *
 * Without this, `activity_log.subject_type`, `journal_entries.source_type`, `media.model_type`,
 * `notes.noteable_type`, `stock_movements.source_type`, `posting_month_overrides.source_type`
 * and `rentable_item_holdings.holder_type`
 * all hold fully-qualified class names — which makes a class name part of the DATABASE SCHEMA.
 * Renaming a model then silently strands every row that quoted it, and the damage is worst exactly
 * where it is least visible: `LedgerPoster::sync()` re-reads a posted entry's source to decide
 * whether to void and re-post it, and its answer to "no such class" is not an error but a
 * re-journal. The 2026-08-15 facility rename had to hand-write a seven-column backfill and a
 * fail-loud assertion to survive moving five models; this exists so the next rename is free.
 *
 * **The map is COMPLETE on purpose, and the gate keeps it that way.** `AppServiceProvider` installs
 * it with `Relation::enforceMorphMap()`, which also calls `requireMorphMap()` — so a model that is
 * missing here does not quietly fall back to its class name, it throws `ClassMorphViolationException`
 * on the first morph write. That is the correct trade (a loud failure beats a silent schema leak)
 * but it means a NEW MODEL MUST BE ADDED HERE, and `MorphMapConformanceTest` fails the build
 * otherwise rather than letting anyone discover it in production.
 *
 * **An alias is permanent.** It is stored in rows going back years, so renaming one is a data
 * migration, not an edit — which is the whole problem this class exists to solve, now confined to a
 * single reviewable list.
 *
 * **The alias IS the model's canonical short name**, so where a model already declares one for the
 * audit trail via `useLogName()`, that wins; everything else is `Str::snake(class_basename())`.
 * Three models disagree with the mechanical form and the declared name is right in all three:
 * `CamExpensePool` is `cam_pool`, `FacilityWorkOrderPart` is `work_order_part` and
 * `TenantSalesDeclaration` is `tenant_sales`. Deriving the alias mechanically instead would have
 * given those models two different words for one concept — one in the audit trail, one in the morph
 * columns — which is the confusion the 2026-08-15 rename existed to remove, rebuilt in a new place.
 *
 * **Comparing a morph column to `::class` no longer works.** Use `MorphMap::alias(Foo::class)`, or
 * Eloquent's own `whereMorphedTo()` / `$model->getMorphClass()`, which resolve through this map.
 */
class MorphMap
{
    /** @var array<string, class-string<Model>> alias => model */
    public const MAP = [
        'bin' => Bin::class,
        'budget_line' => BudgetLine::class,
        'account_mapping' => AccountMapping::class,
        'accounting_period' => AccountingPeriod::class,
        'announcement' => Announcement::class,
        'announcement_recipient' => AnnouncementRecipient::class,
        'approval_rule' => ApprovalRule::class,
        'area' => Area::class,
        'asset' => Asset::class,
        'asset_owner' => AssetOwner::class,
        'bank_account' => BankAccount::class,
        'bank_match' => BankMatch::class,
        'bank_statement' => BankStatement::class,
        'bank_statement_line' => BankStatementLine::class,
        'cam_allocation' => CamAllocation::class,
        'cam_pool' => CamExpensePool::class,
        'charge' => Charge::class,
        'charge_code' => ChargeCode::class,
        'rent_index' => RentIndex::class,
        'credit_note' => CreditNote::class,
        'credit_note_application' => CreditNoteApplication::class,
        'credit_note_item' => CreditNoteItem::class,
        'custody' => Custody::class,
        'custody_transaction' => CustodyTransaction::class,
        'department' => Department::class,
        'deposit_application' => DepositApplication::class,
        'deposit_transaction' => DepositTransaction::class,
        'depreciation_entry' => DepreciationEntry::class,
        'device_token' => DeviceToken::class,
        'disbursement' => Disbursement::class,
        'employee' => Employee::class,
        'employee_advance' => EmployeeAdvance::class,
        'employee_advance_repayment' => EmployeeAdvanceRepayment::class,
        'equipment' => Equipment::class,
        'expense' => Expense::class,
        'facility_work_order' => FacilityWorkOrder::class,
        'facility_work_order_item' => FacilityWorkOrderItem::class,
        'fiscal_year' => FiscalYear::class,
        'fixed_asset' => FixedAsset::class,
        'fixed_asset_disposal' => FixedAssetDisposal::class,
        'floor' => Floor::class,
        'inventory_item' => InventoryItem::class,
        'invoice' => Invoice::class,
        'invoice_item' => InvoiceItem::class,
        'invoice_write_off' => InvoiceWriteOff::class,
        'journal_entry' => JournalEntry::class,
        'journal_line' => JournalLine::class,
        'lease' => Lease::class,
        'lease_cam_term' => LeaseCamTerm::class,
        'lease_event' => LeaseEvent::class,
        'lease_option' => LeaseOption::class,
        'lease_percentage_rent_tier' => LeasePercentageRentTier::class,
        'ledger_account' => LedgerAccount::class,
        'low_stock_alert' => LowStockAlert::class,
        'marketing_budget' => MarketingBudget::class,
        'marketing_post' => MarketingPost::class,
        'marketing_spend' => MarketingSpend::class,
        'meter_reading' => MeterReading::class,
        'note' => Note::class,
        'owner_request' => OwnerRequest::class,
        'owner_request_reply' => OwnerRequestReply::class,
        'owner_statement' => OwnerStatement::class,
        'owner_statement_run' => OwnerStatementRun::class,
        'payment' => Payment::class,
        'payroll' => Payroll::class,
        'payroll_line' => PayrollLine::class,
        // NOT ours, and mapped for exactly the same reason our own models are: `AccessControlAudit`
        // writes a Role as an activity subject, which is a morph write like any other. Generating
        // this map from `app/Models` alone missed both — the completeness gate swept the same
        // directory and so agreed with the mistake. That is the validity-vs-completeness trap in its
        // purest form: the check and the thing it checks shared an assumption, so it passed.
        'permission' => Permission::class,
        'post_dated_cheque' => PostDatedCheque::class,
        'property_setting' => PropertySetting::class,
        'purchase_request' => PurchaseRequest::class,
        'purchase_request_line' => PurchaseRequestLine::class,
        'rentable_item' => RentableItem::class,
        'role' => Role::class,
        'report_preference' => ReportPreference::class,
        'saved_report' => SavedReport::class,
        'service_plan' => ServicePlan::class,
        'sla_penalty' => SlaPenalty::class,
        'sla_policy' => SlaPolicy::class,
        'stock_movement' => StockMovement::class,
        'straight_line_rent_adjustment' => StraightLineRentAdjustment::class,
        'system_setting' => SystemSetting::class,
        'table_view' => TableView::class,
        'tax_code' => TaxCode::class,
        'tax_rate' => TaxRate::class,
        'tenant' => Tenant::class,
        'tenant_credit_application' => TenantCreditApplication::class,
        'tenant_document' => TenantDocument::class,
        'tenant_request' => TenantRequest::class,
        'tenant_request_comment' => TenantRequestComment::class,
        'tenant_sales' => TenantSalesDeclaration::class,
        'tenant_user' => TenantUser::class,
        'unit' => Unit::class,
        'unit_area' => UnitArea::class,
        'unit_ownership' => UnitOwnership::class,
        'user' => User::class,
        'utility_meter' => UtilityMeter::class,
        'utility_tariff' => UtilityTariff::class,
        'utility_tariff_rate' => UtilityTariffRate::class,
        'vendor' => Vendor::class,
        'vendor_bill' => VendorBill::class,
        'vendor_bill_payment' => VendorBillPayment::class,
        'vendor_contact' => VendorContact::class,
        'vendor_contract' => VendorContract::class,
        'vendor_contract_amendment' => VendorContractAmendment::class,
        'vendor_document' => VendorDocument::class,
        'violation' => Violation::class,
        'warehouse' => Warehouse::class,
        'work_order_part' => FacilityWorkOrderPart::class,
    ];

    /**
     * The alias a model is stored as. Use this anywhere a query compares a morph type column,
     * so the comparison keeps working if the class is ever renamed.
     *
     * @param  class-string<Model>  $model
     */
    public static function alias(string $model): string
    {
        $alias = array_search($model, self::MAP, true);

        if ($alias === false) {
            throw new \InvalidArgumentException(
                "{$model} has no morph alias. Add it to App\\Support\\MorphMap::MAP — with the map "
                .'enforced, an unmapped model throws on its first polymorphic write.'
            );
        }

        return $alias;
    }

    /**
     * The model an alias resolves to, or null for an alias no longer in the map — which is what a
     * row written before a model was deleted looks like.
     *
     * @return class-string<Model>|null
     */
    public static function model(string $alias): ?string
    {
        return self::MAP[$alias] ?? null;
    }

    /**
     * **The class behind a value READ OUT of a `*_type` column.**
     *
     * Every caller that does `class_exists($type)`, `new $type` or `$type::query()` on a value it
     * read from the database must go through this, because that value is now an alias and
     * `class_exists('invoice')` is false. The failure mode is not an error — it is a silent `null`,
     * and the sharpest instance is `PeriodService`, whose close gate refuses to close a period
     * holding a drifted document: with the source unresolvable, it found no drift and closed
     * anyway, stranding the correcting post in a closed period forever.
     *
     * Legacy fully-qualified names still resolve, so this is safe on a row written before the
     * backfill ran — a rollback, a restored backup, or a queued job holding an old payload.
     *
     * @return class-string<Model>|null
     */
    public static function resolve(string $type): ?string
    {
        $class = self::MAP[$type] ?? $type;

        return class_exists($class) && is_subclass_of($class, Model::class)
            ? $class
            : null;
    }
}
