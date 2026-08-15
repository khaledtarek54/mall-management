<?php

namespace App\Support;

use App\Filament\Admin\Pages\TrialBalance;
use App\Filament\Admin\Resources\Announcements\AnnouncementResource;
use App\Filament\Admin\Resources\InventoryItems\InventoryItemResource;
use App\Filament\Admin\Resources\Invoices\InvoiceResource;
use App\Filament\Admin\Resources\JournalEntries\JournalEntryResource;
use App\Filament\Admin\Resources\Leases\LeaseResource;
use App\Filament\Admin\Resources\ServicePlans\ServicePlanResource;
use App\Filament\Admin\Resources\FacilityWorkOrders\FacilityWorkOrderResource;
use App\Filament\Admin\Resources\MarketingPosts\MarketingPostResource;
use App\Filament\Admin\Resources\OwnerRequests\OwnerRequestResource;
use App\Filament\Admin\Resources\OwnerStatementRuns\OwnerStatementRunResource;
use App\Filament\Admin\Resources\Payments\PaymentResource;
use App\Filament\Admin\Resources\TenantRequests\TenantRequestResource;
use App\Filament\Admin\Resources\Tenants\TenantResource;
use App\Filament\Admin\Resources\TenantSalesDeclarations\TenantSalesDeclarationResource;
use App\Filament\Admin\Resources\Vendors\VendorResource;
use App\Filament\Admin\Resources\Violations\ViolationResource;
use App\Models\Announcement;
use App\Models\InventoryItem;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\ServicePlan;
use App\Models\FacilityWorkOrder;
use App\Models\MarketingPost;
use App\Models\OwnerRequest;
use App\Models\OwnerStatement;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\TenantRequest;
use App\Models\TenantSalesDeclaration;
use App\Models\Vendor;
use App\Models\Violation;
use App\Notifications\AnnouncementNotification;
use App\Notifications\AreaRequestRaisedNotification;
use App\Notifications\AreaWorkOrderRaisedNotification;
use App\Notifications\BooksDriftDetectedNotification;
use App\Notifications\DepartmentMessageNotification;
use App\Notifications\InvoiceIssuedNotification;
use App\Notifications\InvoiceOverdueOwnerNotification;
use App\Notifications\InvoiceOverdueTenantNotification;
use App\Notifications\LateFeeAppliedNotification;
use App\Notifications\LeaseExpiryApproachingNotification;
use App\Notifications\LeaseOptionWindowNotification;
use App\Notifications\LedgerRestatedReportedPeriodNotification;
use App\Notifications\LedgerSyncFailedNotification;
use App\Notifications\LowStockNotification;
use App\Notifications\MarketingPostReviewedNotification;
use App\Notifications\OwnerRequestNotification;
use App\Notifications\OwnerStatementSentNotification;
use App\Notifications\PaymentReceivedNotification;
use App\Notifications\PortalRequestSubmittedNotification;
use App\Notifications\PreventiveGenerationFailedNotification;
use App\Notifications\SalesDeclarationLockedNotification;
use App\Notifications\SalesDeclarationReminderNotification;
use App\Notifications\SalesDeclarationSubmittedNotification;
use App\Notifications\TenantDocumentExpiringNotification;
use App\Notifications\TenantRequestCommentAddedNotification;
use App\Notifications\TenantRequestSlaBreachedNotification;
use App\Notifications\TenantRequestStatusChangedNotification;
use App\Notifications\TenantResetPasswordNotification;
use App\Notifications\VendorContractRenewalDueNotification;
use App\Notifications\VendorDocumentExpiringNotification;
use App\Notifications\ViolationNoticeNotification;
use App\Notifications\WorkOrderAssignedNotification;
use App\Notifications\WorkOrderRaisedNotification;
use App\Notifications\WorkOrderResponseSlaBreachedNotification;
use App\Notifications\WorkOrderSlaBreachedNotification;

/**
 * **Where every bell notification goes when the operator clicks it.**
 *
 * A notification that says "SLA breached on WO-0042" and cannot be clicked is a riddle: the
 * reader now has to remember the module, switch property, find the filter and type the reference.
 * Every row here turns one of those into one click.
 *
 * ## Why a registry and not a `url` key on each payload
 *
 * The URL cannot be written at the payload, because the payload does not know who is reading it.
 * The SAME notification object is delivered to an operator (`User` → `/admin`) and to a retailer
 * (`Tenant` / `TenantUser` → `/portal`), and the two panels are not interchangeable:
 *
 *   - `/admin` has Filament tenancy (`->tenant(Asset::class, slugAttribute: 'code')`), so every
 *     admin route carries a property slug — `/admin/ATRIOM/invoices/7`. Build it without one and
 *     Laravel throws `UrlGenerationException`; build it with the WRONG one and Filament's
 *     `IdentifyTenant` 404s the reader out of a property they may not even be assigned to.
 *   - `/portal` has no tenancy at all — `/portal/invoices/7`. A slug there is a 404.
 *
 * So the destination is a function of (notification, reader), which is exactly what
 * {@see NotificationLink} computes and what this registry parameterises. Six payloads used to
 * carry a hand-written `'url' => null` that nothing ever read; that is the shape of the mistake
 * this replaces.
 *
 * ## The spec
 *
 *   'record' => [Model::class, 'payload_key']   the record the alert is about; null = no record
 *   'admin'  => Resource::class                 destination for a User; null = no admin link
 *              | [Resource::class, 'relation']  …hop to `$record->relation` first
 *              | Page::class                    a Filament page (no record involved)
 *   'portal' => …                               the same, for a Tenant / TenantUser
 *   'why'    => 'reason'                        REQUIRED when both panels are null
 *
 * With `record` set, the link opens that row; without it, the resource's index. A panel left null
 * means "this notification never reaches that audience, or that audience has nowhere to go" — the
 * reader still gets a link, to the notification centre, so nothing in the bell is a dead end.
 *
 * `NotificationDeepLinkConformanceTest` reflects over this: a notification class that ships with a
 * `toDatabase()` and no row here fails the build, a row naming a payload key the notification does
 * not emit fails, a row pointing a panel at a resource that panel does not own fails, and every
 * built URL is asserted to resolve inside the panel it was built for.
 *
 * @see NotificationLink  the builder that turns a row here into a URL
 */
final class NotificationTargets
{
    /**
     * @var array<class-string, array{record?: array{class-string, string}, admin?: mixed, portal?: mixed, why?: string}>
     */
    public const TARGETS = [
        // ---- Receivables -------------------------------------------------------------------
        InvoiceIssuedNotification::class => [
            'record' => [Invoice::class, 'invoice_id'],
            'admin' => InvoiceResource::class,
            'portal' => \App\Filament\Portal\Resources\Invoices\InvoiceResource::class,
        ],
        InvoiceOverdueOwnerNotification::class => [
            'record' => [Invoice::class, 'invoice_id'],
            'admin' => InvoiceResource::class,
            'portal' => null,
        ],
        InvoiceOverdueTenantNotification::class => [
            'record' => [Invoice::class, 'invoice_id'],
            'admin' => InvoiceResource::class,
            'portal' => \App\Filament\Portal\Resources\Invoices\InvoiceResource::class,
        ],
        // Deliberately the OVERDUE invoice, not the fee invoice: the fee is the consequence, the
        // overdue balance is the thing to act on. The payload carries both (`fee_invoice_id`).
        LateFeeAppliedNotification::class => [
            'record' => [Invoice::class, 'invoice_id'],
            'admin' => InvoiceResource::class,
            'portal' => \App\Filament\Portal\Resources\Invoices\InvoiceResource::class,
        ],
        PaymentReceivedNotification::class => [
            'record' => [Payment::class, 'payment_id'],
            'admin' => PaymentResource::class,
            'portal' => \App\Filament\Portal\Resources\Payments\PaymentResource::class,
        ],

        // ---- Leasing -----------------------------------------------------------------------
        LeaseExpiryApproachingNotification::class => [
            'record' => [Lease::class, 'lease_id'],
            'admin' => LeaseResource::class,
            'portal' => \App\Filament\Portal\Resources\Leases\LeaseResource::class,
        ],
        // The option row has no resource of its own — it is edited on the lease it belongs to,
        // which is also where the operator decides whether to exercise it.
        LeaseOptionWindowNotification::class => [
            'record' => [Lease::class, 'lease_id'],
            'admin' => LeaseResource::class,
            'portal' => null,
        ],

        // ---- Sales & percentage rent -------------------------------------------------------
        SalesDeclarationSubmittedNotification::class => [
            'record' => [TenantSalesDeclaration::class, 'declaration_id'],
            'admin' => TenantSalesDeclarationResource::class,
            'portal' => null,
        ],
        SalesDeclarationLockedNotification::class => [
            'record' => [TenantSalesDeclaration::class, 'declaration_id'],
            'admin' => TenantSalesDeclarationResource::class,
            'portal' => \App\Filament\Portal\Resources\TenantSalesDeclarations\TenantSalesDeclarationResource::class,
        ],
        // A reminder that a declaration is MISSING, so there is no declaration to open — the
        // useful destination is the list the tenant declares from.
        SalesDeclarationReminderNotification::class => [
            'record' => null,
            'admin' => null,
            'portal' => \App\Filament\Portal\Resources\TenantSalesDeclarations\TenantSalesDeclarationResource::class,
        ],

        // ---- Tenant requests ---------------------------------------------------------------
        PortalRequestSubmittedNotification::class => [
            'record' => [TenantRequest::class, 'request_id'],
            'admin' => TenantRequestResource::class,
            'portal' => null,
        ],
        TenantRequestStatusChangedNotification::class => [
            'record' => [TenantRequest::class, 'request_id'],
            'admin' => TenantRequestResource::class,
            'portal' => \App\Filament\Portal\Resources\TenantRequests\TenantRequestResource::class,
        ],
        TenantRequestCommentAddedNotification::class => [
            'record' => [TenantRequest::class, 'request_id'],
            'admin' => TenantRequestResource::class,
            'portal' => \App\Filament\Portal\Resources\TenantRequests\TenantRequestResource::class,
        ],
        TenantRequestSlaBreachedNotification::class => [
            'record' => [TenantRequest::class, 'request_id'],
            'admin' => TenantRequestResource::class,
            'portal' => null,
        ],
        AreaRequestRaisedNotification::class => [
            'record' => [TenantRequest::class, 'request_id'],
            'admin' => TenantRequestResource::class,
            'portal' => null,
        ],

        // ---- Facility / work orders --------------------------------------------------------
        WorkOrderRaisedNotification::class => [
            'record' => [FacilityWorkOrder::class, 'work_order_id'],
            'admin' => FacilityWorkOrderResource::class,
            'portal' => null,
        ],
        WorkOrderAssignedNotification::class => [
            'record' => [FacilityWorkOrder::class, 'work_order_id'],
            'admin' => FacilityWorkOrderResource::class,
            'portal' => null,
        ],
        WorkOrderSlaBreachedNotification::class => [
            'record' => [FacilityWorkOrder::class, 'work_order_id'],
            'admin' => FacilityWorkOrderResource::class,
            'portal' => null,
        ],
        WorkOrderResponseSlaBreachedNotification::class => [
            'record' => [FacilityWorkOrder::class, 'work_order_id'],
            'admin' => FacilityWorkOrderResource::class,
            'portal' => null,
        ],
        AreaWorkOrderRaisedNotification::class => [
            'record' => [FacilityWorkOrder::class, 'work_order_id'],
            'admin' => FacilityWorkOrderResource::class,
            'portal' => null,
        ],
        // The generation FAILED, so there is no work order to open — the plan is what needs
        // fixing (a missing asset, a bad schedule, a closed period).
        PreventiveGenerationFailedNotification::class => [
            'record' => [ServicePlan::class, 'service_plan_id'],
            'admin' => ServicePlanResource::class,
            'portal' => null,
        ],

        // ---- Compliance --------------------------------------------------------------------
        // Both document alerts land on the PARENT, not the document: the documents live in a
        // relation manager on the tenant/vendor page, which is also where the renewal is filed.
        TenantDocumentExpiringNotification::class => [
            'record' => [Tenant::class, 'tenant_id'],
            'admin' => TenantResource::class,
            'portal' => null,
        ],
        VendorDocumentExpiringNotification::class => [
            'record' => [Vendor::class, 'vendor_id'],
            'admin' => VendorResource::class,
            'portal' => null,
        ],
        VendorContractRenewalDueNotification::class => [
            'record' => [Vendor::class, 'vendor_id'],
            'admin' => VendorResource::class,
            'portal' => null,
        ],
        ViolationNoticeNotification::class => [
            'record' => [Violation::class, 'violation_id'],
            'admin' => ViolationResource::class,
            // Tenant-facing: the portal has no violations resource, so the notice itself (title,
            // reference, description) IS the record, and the centre renders all of it.
            'portal' => null,
        ],

        // ---- Owners ------------------------------------------------------------------------
        OwnerRequestNotification::class => [
            'record' => [OwnerRequest::class, 'owner_request_id'],
            'admin' => OwnerRequestResource::class,
            'portal' => null,
        ],
        // OwnerStatement has no resource of its own — it is a child of the run, which is the page
        // that renders it. Hence the hop.
        OwnerStatementSentNotification::class => [
            'record' => [OwnerStatement::class, 'owner_statement_id'],
            'admin' => [OwnerStatementRunResource::class, 'run'],
            'portal' => null,
        ],

        // ---- Marketing ---------------------------------------------------------------------
        MarketingPostReviewedNotification::class => [
            'record' => [MarketingPost::class, 'marketing_post_id'],
            'admin' => MarketingPostResource::class,
            'portal' => \App\Filament\Portal\Resources\MarketingPosts\MarketingPostResource::class,
        ],
        AnnouncementNotification::class => [
            'record' => [Announcement::class, 'announcement_id'],
            // Reaches operators only when one broadcasts to themselves; the audience is tenants.
            'admin' => AnnouncementResource::class,
            // Tenant-facing: the portal has no announcements resource. The announcement's whole
            // content is its title + body, both already in the payload, so the centre shows the
            // reader everything the record would have.
            'portal' => null,
        ],

        // ---- Inventory ---------------------------------------------------------------------
        LowStockNotification::class => [
            'record' => [InventoryItem::class, 'inventory_item_id'],
            'admin' => InventoryItemResource::class,
            'portal' => null,
        ],

        // ---- General ledger ----------------------------------------------------------------
        // None of these is about one document, so each points at the screen that answers the
        // question it raises rather than at a row.
        LedgerSyncFailedNotification::class => [
            'record' => null,
            'admin' => JournalEntryResource::class,   // …the entries that did post; the failures are the gap
            'portal' => null,
        ],
        BooksDriftDetectedNotification::class => [
            'record' => null,
            'admin' => TrialBalance::class,           // "the subledger and the GL disagree" → the tie-out
            'portal' => null,
        ],
        LedgerRestatedReportedPeriodNotification::class => [
            'record' => null,
            'admin' => OwnerStatementRunResource::class, // a restatement's consequence is a re-issued statement
            'portal' => null,
        ],

        // ---- No destination ----------------------------------------------------------------
        DepartmentMessageNotification::class => [
            'record' => null,
            'admin' => null,
            'portal' => null,
            'why' => 'A free-text message between departments — the message IS the record. There is '
                .'nothing to open, so the centre (which shows the full body, sender and time) is the '
                .'destination.',
        ],
    ];

    /**
     * Notifications with no `toDatabase()` at all — they never reach a bell, so they have no
     * destination to declare. Listed with a reason so the gate can tell "deliberately absent"
     * from "forgotten".
     *
     * @var array<class-string, string>
     */
    public const NOT_IN_BELL = [
        TenantResetPasswordNotification::class => 'Mail only — a reset link is useless in an in-app '
            .'bell, which you can only reach by already being signed in.',
    ];

    /** @return array<int, class-string> */
    public static function registered(): array
    {
        return array_keys(self::TARGETS);
    }

    /** @return array{record?: array{class-string, string}, admin?: mixed, portal?: mixed, why?: string}|null */
    public static function for(string $notification): ?array
    {
        return self::TARGETS[$notification] ?? null;
    }

    public static function isClassified(string $notification): bool
    {
        return array_key_exists($notification, self::TARGETS)
            || array_key_exists($notification, self::NOT_IN_BELL);
    }

    /**
     * The destination declared for one panel, normalised to [resourceOrPage, ?hopRelation].
     *
     * @return array{class-string, ?string}|null
     */
    public static function destination(string $notification, string $panel): ?array
    {
        $target = self::TARGETS[$notification][$panel] ?? null;

        if ($target === null) {
            return null;
        }

        return is_array($target)
            ? [$target[0], $target[1] ?? null]
            : [$target, null];
    }

    /**
     * The model + payload key naming the record a notification is about.
     *
     * @return array{class-string, string}|null
     */
    public static function record(string $notification): ?array
    {
        return self::TARGETS[$notification]['record'] ?? null;
    }
}
