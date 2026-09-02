<?php

namespace App\Support;

use App\Enums\InvoiceItemType;
use App\Models\ChargeCode;
use App\Models\PaymentMethod;
use App\Models\RetailCategory;

/**
 * **Every classification the mobile API emits, in both languages, worded exactly as the panel words
 * it.**
 *
 * The API sends `"status": "overdue"`, `"type": "cam_recovery"`, `"method": "instapay"` — machine
 * codes, correctly. What it never sent is what those words ARE, so the app had to carry its own
 * EN+AR table for **25 vocabularies across 16 resources**, and keep it in step with a backend it
 * cannot see. The panel has never had that problem: it resolves every one of them at READ time from
 * `admin.statuses.*` / `admin.enums.*` and the code catalogues.
 *
 * **For five of the twenty-five a client-side table cannot work at all.** `payment.method`,
 * `invoiceItem.type`, `request.category`, `store.retailCategory` and the request sub-categories are
 * OPERATOR-EDITABLE CATALOGUES: the accountant adds a charge code and the mall adds a payment rail
 * with no deploy on either side, so a hardcoded app renders a blank cell or a raw `chiller_charge`
 * on the screen whose own filter lists it. That is the exact failure `IsCodeCatalogue` was written
 * to prevent in the panel — *"never fall back to a raw translation key… an operator-added code has
 * no lang key and would render `admin.enums.method.fawry` on the very screen whose filter lists
 * Fawry"* — reproduced on the surface the retailer actually reads.
 *
 * ## The rule
 *
 * **Nothing here is a second vocabulary.** A closed set takes its VALUES from `ValueSets` — the
 * registry the column is enforced against — and its WORDS from the lang group the panel labels
 * from. An open catalogue takes both from the catalogue's own `catalogueOptions()`. So a widened
 * set appears here the day it is widened, a renamed row is renamed here, and there is no third
 * place for the two to drift apart.
 *
 * `TheApiSpeaksBothLanguagesConformanceTest` closes the loop from the other end: it discovers the
 * classification fields the API resources actually EMIT and fails on one this registry does not
 * cover — a registry that reads only itself cannot see what it omits.
 */
final class ApiVocabulary
{
    /**
     * Wire path (`resource.field`, camelCase exactly as the client reads it) => how to resolve it.
     *
     *   ['set' => 'table.column', 'group' => 'admin.…']  a CLOSED set: values from ValueSets,
     *                                                    words from the panel's own lang group
     *   ['catalogue' => [Model::class, 'method'], 'args' => []]
     *                                                    an OPEN catalogue: values AND words are
     *                                                    rows, read through the SAME public option
     *                                                    method the panel's own picker calls
     *
     * The `group` is the group the PANEL renders from, never a copy written for the API. Where two
     * fields share a vocabulary the entry is repeated rather than aliased: a client holds a field,
     * not a vocabulary name, and a few hundred bytes is cheaper than making them join.
     *
     * @var array<string, array{set?: string, group?: string, catalogue?: array{0: class-string, 1: string}, args?: list<mixed>}>
     */
    public const VOCABULARIES = [
        // ── Money ─────────────────────────────────────────────────────────────────────────────
        'invoice.status' => ['set' => 'invoices.status', 'group' => 'admin.statuses.invoice'],
        // OPEN: the accountant adds a charge code with no deploy, and it appears on an invoice line
        // the next billing night. `ChargeCode::labelFor()` reads the ROW first and the lang key as
        // its floor, which is the order the panel fixed on 2026-08-28.
        'invoiceItem.type' => [
            'catalogue' => [ChargeCode::class, 'options'],
            // `ChargeCode::options()` is rows-only — unlike the `IsCodeCatalogue` twins, it has no
            // per-code floor — so on an install whose chart has not been seeded the vocabulary came
            // back EMPTY, which reads to a client as "there are no charge types" rather than as an
            // unconfigured box. The shipped codes are the floor, labelled through `labelFor()`,
            // which is the row-then-lang-then-humanised order the panel settled on 2026-08-28.
            'floor' => InvoiceItemType::class,
            'label_via' => [ChargeCode::class, 'labelFor'],
        ],
        'payment.status' => ['set' => 'payments.status', 'group' => 'admin.statuses.payment'],
        // OPEN: `payment_methods` is the rail catalogue — the operator adds Fawry as a row.
        'payment.method' => ['catalogue' => [PaymentMethod::class, 'optionsFor'], 'args' => ['payments.method']],
        'payment.channel' => ['set' => 'payments.channel', 'group' => 'admin.enums.payment_channel'],
        'creditNote.status' => ['set' => 'credit_notes.status', 'group' => 'admin.statuses.credit_note'],
        'creditNote.reason' => ['set' => 'credit_notes.reason', 'group' => 'admin.enums.credit_note_reason'],
        'camAllocation.status' => ['set' => 'cam_allocations.status', 'group' => 'admin.statuses.cam_allocation'],

        // ── Leasing ───────────────────────────────────────────────────────────────────────────
        'lease.status' => ['set' => 'leases.status', 'group' => 'admin.statuses.lease'],
        'lease.billingFrequency' => ['set' => 'leases.billing_frequency', 'group' => 'admin.billing_frequency'],
        'lease.escalationType' => ['set' => 'leases.escalation_type', 'group' => 'admin.enums.escalation_type'],
        'lease.percentageRentFrequency' => ['set' => 'leases.percentage_rent_frequency', 'group' => 'admin.enums.percentage_rent_frequency'],
        // Nested re-emissions. A rentable item and a unit have no resource of their own — they
        // ride inside a lease or an ownership — so they are keyed by the payload the client
        // actually holds, which is also how the gate reads them off the resource file.
        'lease.type' => ['set' => 'rentable_items.type', 'group' => 'admin.enums.rentable_item_type'],
        'lease.category' => ['set' => 'units.category', 'group' => 'admin.enums.category'],
        'unitOwnership.category' => ['set' => 'units.category', 'group' => 'admin.enums.category'],

        // ── Ownership (module 37) ─────────────────────────────────────────────────────────────
        'unitOwnership.status' => ['set' => 'unit_ownerships.status', 'group' => 'admin.enums.unit_ownership_status'],
        'unitOwnership.tenureType' => ['set' => 'unit_ownerships.tenure_type', 'group' => 'admin.enums.unit_tenure_type'],
        'unitOwnership.managementMode' => ['set' => 'unit_ownerships.management_mode', 'group' => 'admin.enums.unit_management_mode'],
        'unitOwnership.assessmentBasis' => ['set' => 'unit_ownerships.assessment_basis', 'group' => 'admin.enums.assessment_basis'],

        // ── Requests ──────────────────────────────────────────────────────────────────────────
        'tenantRequest.requestType' => ['set' => 'tenant_requests.request_type', 'group' => 'admin.enums.tenant_request_type'],
        'tenantRequest.status' => ['set' => 'tenant_requests.status', 'group' => 'admin.statuses.tenant_request'],
        'tenantRequest.priority' => ['set' => 'tenant_requests.priority', 'group' => 'admin.enums.work_priority'],
        'tenantRequest.channel' => ['set' => 'tenant_requests.channel', 'group' => 'admin.enums.request_channel'],

        // ── The rest ──────────────────────────────────────────────────────────────────────────
        'tenantSalesDeclaration.status' => ['set' => 'tenant_sales_declarations.status', 'group' => 'admin.statuses.tenant_sales'],
        'announcement.category' => ['set' => 'announcements.category', 'group' => 'admin.announcements.categories'],
        'marketingPost.status' => ['set' => 'marketing_posts.status', 'group' => 'admin.marketing_posts.statuses'],
        'marketingPost.type' => ['set' => 'marketing_posts.type', 'group' => 'admin.marketing_posts.types'],
        'marketingPost.audience' => ['set' => 'marketing_posts.audience', 'group' => 'admin.marketing_posts.audiences'],
        // The shopper feed sends the same vocabulary to a different audience — and to an app with
        // no token, which cannot call /me/vocabulary at all. Keyed separately so the visitor build
        // can be pointed at a public copy later without the tenant one moving.
        'publicMarketingPost.type' => ['set' => 'marketing_posts.type', 'group' => 'admin.marketing_posts.types'],
        'tenant.type' => ['set' => 'tenants.type', 'group' => 'admin.enums.tenant_type'],
        'tenant.status' => ['set' => 'tenants.status', 'group' => 'admin.statuses.tenant'],
        // OPEN: the shopper directory's own filter is built from these rows.
        'publicStore.retailCategory' => ['catalogue' => [RetailCategory::class, 'options']],
    ];

    /**
     * Fields the API emits that carry NO vocabulary, each with the reason.
     *
     * The gate reads this, so an unexplained classification cannot ship — and a stale entry fails
     * too, which is what stops the list becoming a place to put things nobody wants to think about.
     *
     * @var array<string, string>
     */
    public const NOT_A_VOCABULARY = [
        'notification.type' => 'the notification CLASS name, which the app branches on for an icon — it is an identifier, and its human words are already resolved into `data.title`/`data.body` in the reader/s language',
        'tenantRequestComment.authorKind' => 'two values the client renders as a role, not as a word: `tenant` is "you" and `staff` is already resolved to "Property team" in `authorName`',
        'deviceToken.platform' => 'ios/android — the app knows which one it is; there is nothing to translate',
        'tenantRequest.category' => 'a sub-category is scoped to its TYPE and the same code can sit under two, so it is served with that structure by GET /me/request-types rather than flattened into a lookup that would lose it',
        'invoiceItem.disputedReason' => 'free text an operator typed to record what the tenant argued about — a sentence, not a code, and it is already in whatever language they wrote it in',
        'tenantRequest.decisionReason' => 'free text explaining a refusal, written by a person; translating it would mean translating what the operator said',
        'tenantRequest.mimeType' => 'an IANA media type on an attachment (application/pdf) — the client switches a preview on it; there is nothing to translate',
        'tenantSalesDeclaration.mimeType' => 'the same IANA media type, on an uploaded sales report — the client picks a preview from it and there is no word to translate',
    ];

    /**
     * The vocabularies whose values are ROWS an operator can add or retire with no deploy.
     *
     * Derived, so it cannot fall behind the registry above — and named because it is the half a
     * client CANNOT solve locally however carefully it ships its tables.
     *
     * @return list<string>
     */
    public static function openCatalogues(): array
    {
        return array_keys(array_filter(
            self::VOCABULARIES,
            fn (array $spec) => isset($spec['catalogue']),
        ));
    }
}
