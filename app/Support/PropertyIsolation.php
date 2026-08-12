<?php

namespace App\Support;

use App\Models\AccountingPeriod;
use App\Models\AccountMapping;
use App\Models\Announcement;
use App\Models\ApprovalRule;
use App\Models\Area;
use App\Models\Floor;
use App\Models\RentableItem;
use App\Models\Asset;
use App\Models\AssetOwner;
use App\Models\CamAllocation;
use App\Models\CamExpensePool;
use App\Models\Charge;
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
use App\Models\FiscalYear;
use App\Models\FixedAsset;
use App\Models\FixedAssetDisposal;
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
use App\Models\MaintenancePenalty;
use App\Models\MaintenancePlan;
use App\Models\MaintenanceWorkOrder;
use App\Models\MaintenanceWorkOrderItem;
use App\Models\MaintenanceWorkOrderPart;
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
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestLine;
use App\Models\PropertySetting;
use App\Models\SlaPolicy;
use App\Models\StockMovement;
use App\Models\SystemSetting;
use App\Models\Tenant;
use App\Models\TenantCreditApplication;
use App\Models\TenantRequest;
use App\Models\TenantRequestComment;
use App\Models\TenantSalesDeclaration;
use App\Models\TenantUser;
use App\Models\Unit;
use App\Models\UnitArea;
use App\Models\User;
use App\Models\UtilityMeter;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Models\VendorBillPayment;
use App\Models\VendorContact;
use App\Models\VendorContract;
use App\Models\VendorContractAmendment;
use App\Models\ChargeCode;
use App\Models\SavedReport;
use App\Models\TaxCode;
use App\Models\TaxRate;
use App\Models\TenantDocument;
use App\Models\VendorDocument;
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
        VendorDocument::class,      // compliance file (insurance/tax card/register) of the shared Vendor
        TenantDocument::class,      // compliance file of the shared Tenant; occupancy decides who is alerted, not who may read it
        InventoryItem::class,       // shared SKU catalog; stock is per-Warehouse
        LedgerAccount::class,       // one shared chart of accounts; property is a dimension on entries
        FiscalYear::class,          // one operator fiscal calendar
        AccountingPeriod::class,    // one operator period calendar
        AccountMapping::class,      // global posting-rule defaults + optional per-property override rows
        ChargeCode::class,          // portfolio billing vocabulary; the per-property override lives on the mapping it names
        SavedReport::class,         // an operator's own report bookmark; the PROPERTY it filters on lives in its parameters and is re-clamped on open
        TaxCode::class,             // one tax law applies to the whole portfolio — a rate is national, not per-mall
        TaxRate::class,             // a rung on a TaxCode's dated ladder; shared for the same reason as its parent
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
        RentableItem::class => null,           // a parking bay / store / signage face stands in one mall; code unique per property
        Floor::class => null,                  // a floor belongs to one building; code and level unique per property
        Violation::class => null,              // a tenant violation is pinned to the mall where it occurred (module 31)
        SlaPolicy::class => null,              // per-property SLA override (FR-CM-05); absent = operator default
        // A setting one mall answers differently from the portfolio (CFG-03). Absent = the
        // portfolio's answer, never zero — see App\Support\PropertySettings.
        PropertySetting::class => null,
        UtilityMeter::class => null,
        CamExpensePool::class => null,
        MarketingBudget::class => null,
        // A shopper-facing offer/event/news card runs in exactly one mall — a shopper reading it
        // is standing in the building. Direct asset_id (module 36).
        MarketingPost::class => null,
        Employee::class => null,
        EmployeeAdvance::class => null,
        EmployeeAdvanceRepayment::class => null,
        FixedAsset::class => null,
        Custody::class => null,
        CustodyTransaction::class => null,
        Warehouse::class => null,
        \App\Models\BankAccount::class => null,   // owns its asset_id: the mall whose money it holds
        \App\Models\BankStatement::class => 'bankAccount',   // reaches its property through the account it belongs to
        \App\Models\BankStatementLine::class => 'statement.bankAccount',
        \App\Models\BankMatch::class => 'statementLine.statement.bankAccount',
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
        OwnerRequestReply::class => 'ownerRequest', // a reply reaches its property through its request; no resource of its own (posted via the Reply action)
        Department::class => null,             // asset_id nullable: null = operator-wide (global), set = property-scoped (hybrid)
        AssetOwner::class => null,             // the asset_owner ownership pivot — one row = one owner's stake in one mall; no Filament RESOURCE, managed through AssetOwnersRelationManager on the Asset (added 2026-08-11; until then this comment described a UI that did not exist, which is how the gap stayed invisible)
        OwnerStatementRun::class => null,      // owner statement run — one property's period statement (module 32)
        TenantCreditApplication::class => null, // applying on-account credit to an invoice; asset = the invoice's property; service-created, no Filament resource
        DepositApplication::class => null,        // netting a deposit against an invoice; asset = the invoice's property; service-created, no Filament resource
        \App\Models\StraightLineRentAdjustment::class => null, // monthly rent-recognition adjustment; asset = the lease's property; service-created, no Filament resource
        OwnerStatement::class => null,         // per-owner child; asset_id denormalized for uniform auto-scope
        Disbursement::class => null,           // owner payout; asset_id denormalized (journalizer reads own row)
        PostDatedCheque::class => null,        // a tenant's forward cheque, pinned to the property it relates to (module 33)

        // ---- Indirect (relation chain to asset_id) ----
        UnitArea::class => 'unit',             // a unit's measurement history — reached through its unit, never portfolio-wide
        Lease::class => 'unit',
        Invoice::class => 'lease.unit',
        InvoiceItem::class => 'invoice.lease.unit',
        Charge::class => 'lease.unit',
        LeaseCamTerm::class => 'lease.unit',
        LeaseOption::class => 'lease.unit',
        LeasePercentageRentTier::class => 'lease.unit',
        LeaseEvent::class => 'lease.unit',
        InvoiceWriteOff::class => 'asset',
        Payment::class => 'invoices.lease.unit',
        CreditNote::class => 'lease.unit',
        CreditNoteItem::class => 'creditNote.lease.unit',
        CreditNoteApplication::class => 'creditNote.lease.unit', // one application of a note; asset = the note's (invoice's) property; service-created, no Filament resource
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
        // A change order reaches its property through the contract it varies. No Filament
        // resource of its own — recorded via the "Add change order" action on the vendor's
        // contracts list, which is already property-scoped.
        VendorContractAmendment::class => 'contract',
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
