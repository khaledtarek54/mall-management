<?php

namespace App\Support;

use App\Models\AccountingPeriod;
use App\Models\AccountMapping;
use App\Models\Announcement;
use App\Models\ApprovalRule;
use App\Models\Area;
use App\Models\Asset;
use App\Models\AssetOwner;
use App\Models\CamAllocation;
use App\Models\CamExpensePool;
use App\Models\Charge;
use App\Models\CreditNote;
use App\Models\CreditNoteItem;
use App\Models\Custody;
use App\Models\CustodyTransaction;
use App\Models\Department;
use App\Models\DepositTransaction;
use App\Models\DepreciationEntry;
use App\Models\DeviceToken;
use App\Models\Disbursement;
use App\Models\Employee;
use App\Models\Equipment;
use App\Models\EmployeeAdvance;
use App\Models\EmployeeAdvanceRepayment;
use App\Models\Expense;
use App\Models\FiscalYear;
use App\Models\FixedAsset;
use App\Models\FixedAssetDisposal;
use App\Models\InventoryItem;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Lease;
use App\Models\LedgerAccount;
use App\Models\MaintenancePenalty;
use App\Models\MaintenancePlan;
use App\Models\MaintenanceWorkOrder;
use App\Models\MaintenanceWorkOrderItem;
use App\Models\MaintenanceWorkOrderPart;
use App\Models\LowStockAlert;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestLine;
use App\Models\MarketingBudget;
use App\Models\MarketingSpend;
use App\Models\MeterReading;
use App\Models\Note;
use App\Models\OwnerRequest;
use App\Models\OwnerStatement;
use App\Models\OwnerStatementRun;
use App\Models\Payment;
use App\Models\Payroll;
use App\Models\PayrollLine;
use App\Models\SlaPolicy;
use App\Models\StockMovement;
use App\Models\SystemSetting;
use App\Models\Tenant;
use App\Models\TenantRequest;
use App\Models\TenantRequestComment;
use App\Models\TenantSalesDeclaration;
use App\Models\TenantUser;
use App\Models\Unit;
use App\Models\User;
use App\Models\UtilityMeter;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Models\VendorBillPayment;
use App\Models\VendorContact;
use App\Models\VendorContract;
use App\Models\Violation;
use App\Models\Warehouse;

/**
 * The authoritative register of which models are SHARED across every property
 * and which are ISOLATED to one property — the code-level source of truth for
 * total property isolation. See docs/PROPERTY-ISOLATION-PLAN.md.
 *
 * `PropertyIsolationConformanceTest` reflects over this registry so that a new
 * model or resource that ships unclassified (or a property-owned resource that
 * ships unscoped) FAILS CI instead of leaking silently in production. Add every
 * new model here — that is the point of the gate.
 */
class PropertyIsolation
{
    /**
     * GLOBAL / shared models. They intentionally carry no per-property row
     * scoping — they are operator-wide catalogs, config, or people (docs §4a).
     * Where a shared master is *used* per-property, the usage rows live in
     * OWNED (e.g. Vendor is shared; VendorBill/VendorContract are owned).
     *
     * @var array<int, class-string>
     */
    public const SHARED = [
        User::class,                // operator staff (assigned to properties via asset_user)
        Tenant::class,              // a retailer can lease in several malls; money is per-property (Invoice/Payment)
        TenantUser::class,          // portal login for a Tenant (transitively multi-property)
        DeviceToken::class,         // push token for a Tenant
        Vendor::class,              // shared vendor catalog; engagement per-property (VendorContract/Bill)
        VendorContact::class,       // belongs to the shared Vendor
        InventoryItem::class,       // shared SKU catalog; stock is per-Warehouse
        LedgerAccount::class,       // one shared chart of accounts; property is a dimension on entries
        FiscalYear::class,          // one operator fiscal calendar
        AccountingPeriod::class,    // one operator period calendar
        AccountMapping::class,      // global posting-rule defaults + optional per-property override rows
        ApprovalRule::class,        // operator-wide approval policy (FR-CM-11) — authority is a company rule, not a per-mall one
        SystemSetting::class,       // system state / config
        Note::class,                // polymorphic note attached to various records
    ];

    /**
     * PROPERTY-OWNED models → how each row reaches its Asset.
     *   null    = a direct `asset_id` column on the model's table.
     *   'chain' = the relation chain ending at a model that has `asset_id`
     *             (e.g. Invoice → lease → unit → asset_id => 'lease.unit').
     * Every row here is isolated to exactly one property (docs §4b).
     *
     * @var array<class-string, string|null>
     */
    public const OWNED = [
        // ---- Direct asset_id column ----
        Unit::class => null,
        Announcement::class => null,           // broadcast targeted at one property
        Equipment::class => null,              // a machine stands in exactly one mall; code unique per property
        Area::class => null,                   // a facility zone stands in exactly one mall; code unique per property
        Violation::class => null,              // a tenant violation is pinned to the mall where it occurred (module 31)
        SlaPolicy::class => null,              // per-property SLA override (FR-CM-05); absent = operator default
        UtilityMeter::class => null,
        CamExpensePool::class => null,
        MarketingBudget::class => null,
        Employee::class => null,
        EmployeeAdvance::class => null,
        EmployeeAdvanceRepayment::class => null,
        FixedAsset::class => null,
        Custody::class => null,
        CustodyTransaction::class => null,
        Warehouse::class => null,
        Expense::class => null,
        Payroll::class => null,
        JournalEntry::class => null,           // asset_id nullable = the books dimension (null = consolidated)
        DepositTransaction::class => null,     // asset_id derived from the lease in the model's saving hook
        VendorBill::class => null,             // asset_id nullable = property the expense belongs to
        VendorContract::class => null,
        MaintenancePlan::class => null,
        MaintenanceWorkOrder::class => null,
        MaintenancePenalty::class => null,   // asset_id copied from the breaching work order
        OwnerRequest::class => null,           // asset_id nullable (property-specific or cross-property)
        Department::class => null,             // asset_id nullable: null = operator-wide (global), set = property-scoped (hybrid)
        AssetOwner::class => null,             // the asset_owner ownership pivot — one row = one owner's stake in one mall; no Filament resource (managed via User/Asset relations), like LowStockAlert
        OwnerStatementRun::class => null,      // owner statement run — one property's period statement (module 32)
        OwnerStatement::class => null,         // per-owner child; asset_id denormalized for uniform auto-scope
        Disbursement::class => null,           // owner payout; asset_id denormalized (journalizer reads own row)

        // ---- Indirect (relation chain to asset_id) ----
        Lease::class => 'unit',
        Invoice::class => 'lease.unit',
        InvoiceItem::class => 'invoice.lease.unit',
        Charge::class => 'lease.unit',
        Payment::class => 'invoices.lease.unit',
        CreditNote::class => 'lease.unit',
        CreditNoteItem::class => 'creditNote.lease.unit',
        TenantRequest::class => 'unit',
        TenantRequestComment::class => 'request.unit',
        TenantSalesDeclaration::class => 'lease.unit',
        MeterReading::class => 'meter',
        FixedAssetDisposal::class => 'fixedAsset',
        DepreciationEntry::class => 'fixedAsset',
        MarketingSpend::class => 'budget',
        CamAllocation::class => 'pool',
        JournalLine::class => 'entry',
        PayrollLine::class => 'payroll',
        MaintenanceWorkOrderItem::class => 'workOrder',
        MaintenanceWorkOrderPart::class => 'workOrder',
        LowStockAlert::class => null,
        PurchaseRequest::class => null,
        PurchaseRequestLine::class => 'request',
        StockMovement::class => 'warehouse',
        VendorBillPayment::class => 'bill',
    ];

    /**
     * The property itself — it IS the Filament tenant, scoped by identity, so it
     * belongs to neither bucket.
     *
     * @var array<int, class-string>
     */
    public const SELF = [
        Asset::class,
    ];

    public static function isShared(string $model): bool
    {
        return in_array($model, self::SHARED, true);
    }

    public static function isOwned(string $model): bool
    {
        return array_key_exists($model, self::OWNED);
    }

    /** True when the owned model carries a direct `asset_id` column. */
    public static function isDirect(string $model): bool
    {
        return self::isOwned($model) && self::OWNED[$model] === null;
    }

    /** The relation chain for an indirect owned model, or null for direct / unknown. */
    public static function linkageFor(string $model): ?string
    {
        return self::OWNED[$model] ?? null;
    }

    /** Every model is expected to be classified into exactly one bucket. */
    public static function isClassified(string $model): bool
    {
        return self::isShared($model)
            || self::isOwned($model)
            || in_array($model, self::SELF, true);
    }

    /** @return array<int, class-string> */
    public static function ownedModels(): array
    {
        return array_keys(self::OWNED);
    }

    /** @return array<int, class-string> */
    public static function sharedModels(): array
    {
        return self::SHARED;
    }
}
