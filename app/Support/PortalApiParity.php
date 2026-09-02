<?php

namespace App\Support;

use App\Http\Resources\Api\V1\AnnouncementResource;
use App\Http\Resources\Api\V1\CamAllocationResource;
use App\Http\Resources\Api\V1\CreditNoteItemResource;
use App\Http\Resources\Api\V1\CreditNoteResource;
use App\Http\Resources\Api\V1\InvoiceItemResource;
use App\Http\Resources\Api\V1\InvoiceResource;
use App\Http\Resources\Api\V1\LeaseResource;
use App\Http\Resources\Api\V1\MarketingPostResource;
use App\Http\Resources\Api\V1\PaymentResource;
use App\Http\Resources\Api\V1\TenantRequestResource;
use App\Http\Resources\Api\V1\TenantSalesDeclarationResource;

/**
 * **The portal and `/api/v1` are the same surface with different renderers.**
 *
 * This project states that rule in `docs/modules/03-tenant-portal-users.md` and again in
 * `ConfirmTenantRequestAction`, and until 2026-09-02 **nothing enforced it**. The consequence was
 * exactly what an unenforced rule produces: it was honoured for VISIBILITY — drafts hidden from
 * both surfaces, fixed twice, each time with a test — and quietly not honoured for CONTENT, because
 * there was a gate for the first question and none for the second.
 *
 * Seven capabilities had drifted apart by the time anyone compared them, and the two that mattered
 * most were not incompleteness but silence:
 *
 *   - the **deposit shortfall**, which is never invoiced, so the portal figure was the ONLY channel
 *     by which a tenant was ever told they still owed one — and an app-only tenant could not be
 *     told at all;
 *   - **credit on account**, the tenant's own money, which the portal showed and the app did not,
 *     so an overpayment looked lost and then silently part-settled an invoice.
 *
 * Neither is a bug in an endpoint. Every endpoint returned exactly what it promised. They are
 * commits that landed on the portal and stopped there, which is a thing only a comparison can see.
 *
 * ## What the gate checks
 *
 * For every portal resource: an `/api/v1` counterpart exists, and every field the portal's own
 * detail view renders is published by the matching API resource — or is named in {@see FIELD_EXEMPT}
 * with a reason.
 *
 * It reads SOURCE, so it proves a weaker property than "the payload contains it": a field could be
 * declared and conditioned away at runtime. That is deliberate — the behavioural half is each
 * resource's own regression test, and this is the sweep that cannot be forgotten when somebody adds
 * a tenth portal screen.
 */
final class PortalApiParity
{
    /**
     * Portal resource directory => [API resource, the route name that serves it].
     *
     * Keyed by DIRECTORY rather than by class so the gate can discover a new portal resource on
     * disk and fail on it — a registry that lists only what it already knows about cannot see what
     * it omits, which is the failure mode `CatalogueWidensItsColumnsConformanceTest` was written
     * for and this one deliberately copies.
     *
     * `resources` is a LIST because a screen's detail view spans more than one payload: an
     * invoice's lines are `InvoiceItemResource`, not `InvoiceResource`, and a gate that looked only
     * at the parent reported `description` and `disputed_reason` as missing when both ship.
     *
     * @var array<string, array{resources: list<class-string>, route: string}>
     */
    public const PAIRS = [
        'Announcements' => ['resources' => [AnnouncementResource::class], 'route' => 'api.v1.me.announcements.index'],
        'CamAllocations' => ['resources' => [CamAllocationResource::class], 'route' => 'api.v1.me.cam.index'],
        'CreditNotes' => ['resources' => [CreditNoteResource::class, CreditNoteItemResource::class], 'route' => 'api.v1.me.credit-notes.index'],
        'Invoices' => ['resources' => [InvoiceResource::class, InvoiceItemResource::class], 'route' => 'api.v1.me.invoices.index'],
        'Leases' => ['resources' => [LeaseResource::class], 'route' => 'api.v1.me.leases'],
        'MarketingPosts' => ['resources' => [MarketingPostResource::class], 'route' => 'api.v1.me.posts.index'],
        'Payments' => ['resources' => [PaymentResource::class], 'route' => 'api.v1.me.payments.index'],
        'TenantRequests' => ['resources' => [TenantRequestResource::class], 'route' => 'api.v1.me.requests.index'],
        'TenantSalesDeclarations' => ['resources' => [TenantSalesDeclarationResource::class], 'route' => 'api.v1.me.sales.index'],
    ];

    /**
     * A field the portal renders that the API deliberately does not publish — each with its reason.
     *
     * Keyed `Directory::field`, where `field` is the LAST segment of the portal's own path
     * (`lease.unit.code` → `code`), because that is the granularity a payload answers at.
     *
     * **An entry here is a decision, not a backlog item.** The gate rejects a stale one, so a field
     * that later reaches the API forces this list to shrink.
     *
     * @var array<string, string>
     */
    public const FIELD_EXEMPT = [
        // The API answers the same question under a better name, or in a better shape.
        'Payments::invoices' => 'the API sends the same rows as `allocations[]`, which names what they are (an allocated amount per invoice) rather than naming the relation',
        'Payments::number' => 'the invoice number inside that repeater — the API sends it as `allocations[].invoice_number`, flattened because a client does not need the invoice object to render a receipt',
        'TenantSalesDeclarations::report_status' => 'a portal badge counting the uploaded reports; the API sends `has_report` plus the `attachments[]` array itself, from which the count is exact',
        'TenantSalesDeclarations::period_info' => 'help text on the portal CREATE form explaining the period being declared, not a stored value — the API sends `period_start`/`period_end`/`period_label`',
    ];

    /**
     * Portal surfaces that are not resources, and where their content is answered on the API.
     *
     * Listed rather than derived because a Page has no model and no naming convention to follow —
     * but they are named here so that "which portal surfaces exist" stays a question with a written
     * answer. `AccountBalance` is the one that earned this: `credit_on_account` hid on a WIDGET, not
     * on a resource, which is precisely why a resource-only sweep would have missed it.
     *
     * @var array<string, string>
     */
    public const NON_RESOURCE_SURFACES = [
        'Pages/CompanyProfile' => 'GET /me + PATCH /me (TenantResource)',
        'Pages/NotificationCenter' => 'GET /me/notifications (NotificationResource)',
        'Widgets/AccountBalance' => 'GET /me/balance + GET /me/summary',
        'Widgets/OpenTenantRequests' => 'GET /me/summary (open_maintenance) + GET /me/requests',
    ];
}
