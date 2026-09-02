<?php

use App\Http\Controllers\Api\V1\Announcements\ListAnnouncementsController;
use App\Http\Controllers\Api\V1\Announcements\MarkAnnouncementReadController;
use App\Http\Controllers\Api\V1\Announcements\ShowAnnouncementController;
use App\Http\Controllers\Api\V1\Announcements\ShowAnnouncementHeroController;
use App\Http\Controllers\Api\V1\Auth\ChangePasswordController;
use App\Http\Controllers\Api\V1\Auth\ForgotPasswordController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\ResetPasswordController;
use App\Http\Controllers\Api\V1\CamAllocations\CamStatementController;
use App\Http\Controllers\Api\V1\Catalogue\ListRequestTypesController;
use App\Http\Controllers\Api\V1\Catalogue\ShowVocabularyController;
use App\Http\Controllers\Api\V1\CamAllocations\ListCamAllocationsController;
use App\Http\Controllers\Api\V1\CamAllocations\ShowCamAllocationController;
use App\Http\Controllers\Api\V1\CreditNotes\ListCreditNotesController;
use App\Http\Controllers\Api\V1\CreditNotes\ShowCreditNoteController;
use App\Http\Controllers\Api\V1\Devices\ListDevicesController;
use App\Http\Controllers\Api\V1\Devices\RegisterDeviceController;
use App\Http\Controllers\Api\V1\Devices\UnregisterDeviceController;
use App\Http\Controllers\Api\V1\Invoices\InvoicePdfController;
use App\Http\Controllers\Api\V1\Invoices\ListInvoicesController;
use App\Http\Controllers\Api\V1\Invoices\ShowInvoiceController;
use App\Http\Controllers\Api\V1\Invoices\StatementController;
use App\Http\Controllers\Api\V1\MarketingPosts\MarketingPostsController;
use App\Http\Controllers\Api\V1\Notifications\ListNotificationsController;
use App\Http\Controllers\Api\V1\Notifications\MarkAllNotificationsReadController;
use App\Http\Controllers\Api\V1\Notifications\MarkNotificationReadController;
use App\Http\Controllers\Api\V1\Notifications\UnreadCountController;
use App\Http\Controllers\Api\V1\Payments\ListPaymentsController;
use App\Http\Controllers\Api\V1\Payments\PaymentReceiptController;
use App\Http\Controllers\Api\V1\Payments\ShowPaymentController;
use App\Http\Controllers\Api\V1\Profile\BalanceController;
use App\Http\Controllers\Api\V1\Profile\LeaseDocumentController;
use App\Http\Controllers\Api\V1\Profile\LeasesController;
use App\Http\Controllers\Api\V1\Profile\ShowProfileController;
use App\Http\Controllers\Api\V1\Profile\SummaryController;
use App\Http\Controllers\Api\V1\Profile\UpdateProfileController;
use App\Http\Controllers\Api\V1\PublicFeed\ListPublicMallsController;
use App\Http\Controllers\Api\V1\PublicFeed\ListPublicPostsController;
use App\Http\Controllers\Api\V1\PublicFeed\ListPublicStoresController;
use App\Http\Controllers\Api\V1\PublicFeed\RecordPostClickController;
use App\Http\Controllers\Api\V1\PublicFeed\ShowPublicPostController;
use App\Http\Controllers\Api\V1\PublicFeed\ShowPublicStoreController;
use App\Http\Controllers\Api\V1\Requests\CancelTenantRequestController;
use App\Http\Controllers\Api\V1\Requests\CommentTenantRequestController;
use App\Http\Controllers\Api\V1\Requests\ConfirmTenantRequestController;
use App\Http\Controllers\Api\V1\Requests\CreateTenantRequestController;
use App\Http\Controllers\Api\V1\Requests\DisputeTenantRequestController;
use App\Http\Controllers\Api\V1\Requests\ListTenantRequestsController;
use App\Http\Controllers\Api\V1\Requests\RateTenantRequestController;
use App\Http\Controllers\Api\V1\Requests\ShowTenantRequestAttachmentController;
use App\Http\Controllers\Api\V1\Requests\ShowTenantRequestController;
use App\Http\Controllers\Api\V1\SalesDeclarations\CreateSalesDeclarationController;
use App\Http\Controllers\Api\V1\SalesDeclarations\ListSalesDeclarationsController;
use App\Http\Controllers\Api\V1\SalesDeclarations\ShowSalesDeclarationAttachmentController;
use App\Http\Controllers\Api\V1\SalesDeclarations\ShowSalesDeclarationController;
use App\Http\Controllers\Api\V1\Tenant\DemoPayInvoiceController;
use App\Http\Controllers\Api\V1\UnitOwnerships\ListUnitOwnershipsController;
use App\Http\Controllers\Api\V1\Tenant\InitiatePaymobSessionController;
use App\Http\Middleware\EnsureMarketingPostsEnabled;
use App\Http\Middleware\EnsureTenantActive;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — Mobile (tenant-facing)
|--------------------------------------------------------------------------
| Sanctum token auth against the `tenants` provider. Web/admin endpoints
| are NOT exposed here; the Filament panels at /admin /portal are
| separate session-based flows.
|
| Conventions:
|   - Versioned under /api/v1
|   - Standard JSON envelope: { data: ..., message: ... }
|   - 401 unauthenticated, 422 validation, 403 forbidden, 404 not found,
|     429 throttled
|   - Every /me/* route is scoped to the authenticated tenant server-side;
|     a tenant_id in the URL or body is never trusted.
|   - Writes go through single-action classes (App\Actions\Api\V1\...).
*/

Route::prefix('v1')->group(function () {

    // ============ Public (unauthenticated) ============
    Route::middleware('throttle:5,1')->group(function () {
        Route::post('auth/login', LoginController::class)->name('api.v1.auth.login');
    });

    /*
    |--------------------------------------------------------------------------
    | Shopper feed — the ONLY unauthenticated read surface in the system (module 36)
    |--------------------------------------------------------------------------
    | Serves the visitor app: what's on at the mall, and who trades there. Everything else
    | under /api/v1 authenticates a Tenant; these routes deliberately do not, because the
    | audience is anyone standing in the building.
    |
    | Three things keep that safe, and none of them is "we remembered to filter":
    |   1. `EnsureMarketingPostsEnabled` — 404s the whole surface when the module is off.
    |      A permission gate would not help here: there is no user to hold a permission.
    |   2. Field ALLOWLISTS (PublicMarketingPostResource / PublicStoreResource), not the
    |      models. Nothing from `tenants` reaches a shopper except by being typed out.
    |   3. `MarketingPost::liveFor('visitors')` — the single visibility predicate, shared
    |      with every other surface so they cannot drift apart.
    |
    | Throttled per-IP at 120/min: generous for a shopper flicking through a feed (each
    | screen is one request), tight enough that scraping the directory costs something.
    | The click counter gets its own tighter bucket — see RecordPostClickController on why
    | these numbers are indicative rather than audited.
    */
    Route::middleware([EnsureMarketingPostsEnabled::class, 'throttle:120,1'])
        ->prefix('public')
        ->group(function () {
            Route::get('malls', ListPublicMallsController::class)->name('api.v1.public.malls');

            Route::get('malls/{code}/posts', ListPublicPostsController::class)->name('api.v1.public.posts.index');
            Route::get('malls/{code}/posts/{post}', ShowPublicPostController::class)
                ->whereNumber('post')->name('api.v1.public.posts.show');

            Route::get('malls/{code}/stores', ListPublicStoresController::class)->name('api.v1.public.stores.index');
            Route::get('malls/{code}/stores/{store}', ShowPublicStoreController::class)
                ->whereNumber('store')->name('api.v1.public.stores.show');
        });

    // The one WRITE on the public surface. Its own, much tighter bucket: it is the only
    // unauthenticated endpoint that changes a row, so it should cost more than a read.
    Route::middleware([EnsureMarketingPostsEnabled::class, 'throttle:30,1'])
        ->prefix('public')
        ->group(function () {
            Route::post('malls/{code}/posts/{post}/click', RecordPostClickController::class)
                ->whereNumber('post')->name('api.v1.public.posts.click');
        });

    // Password reset request + apply — tighter throttle (anti-abuse), still public.
    Route::middleware('throttle:3,1')->group(function () {
        Route::post('auth/forgot-password', ForgotPasswordController::class)->name('api.v1.auth.forgot-password');
        Route::post('auth/reset-password', ResetPasswordController::class)->name('api.v1.auth.reset-password');
    });

    // ============ Authenticated (Sanctum tenant-api guard) ============
    Route::middleware(['auth:tenant-api', EnsureTenantActive::class, 'throttle:60,1'])->group(function () {

        // --- Auth / session ---
        // Same controller as `GET /me` — deliberately, because they are the same answer. Two
        // identical invokables had been maintained side by side, which is two chances for the
        // tenant identity to start differing by which URL you asked. The route stays for the
        // released clients that call it.
        Route::get('auth/me', ShowProfileController::class)->name('api.v1.auth.me');
        Route::post('auth/logout', LogoutController::class)->name('api.v1.auth.logout');
        Route::post('auth/change-password', ChangePasswordController::class)->name('api.v1.auth.change-password');

        // --- Profile / account ---
        Route::get('me', ShowProfileController::class)->name('api.v1.me.show');
        Route::patch('me', UpdateProfileController::class)->name('api.v1.me.update');
        Route::get('me/balance', BalanceController::class)->name('api.v1.me.balance');
        // Home-screen rollup — money owed + open work + things needing attention.
        Route::get('me/summary', SummaryController::class)->name('api.v1.me.summary');
        Route::get('me/leases', LeasesController::class)->name('api.v1.me.leases');
        // The shops this party OWNS (module 37). A unit owner IS a `tenants` row and is billed
        // monthly assessments, and the API had no way to say WHICH shop one was for.
        Route::get('me/unit-ownerships', ListUnitOwnershipsController::class)->name('api.v1.me.unit-ownerships');
        // The tenant's own signed lease — the portal's `downloadDocument` action, on the surface
        // where the shop manager actually is. Private disk; see the controller.
        Route::get('me/leases/{id}/document', LeaseDocumentController::class)
            ->whereNumber('id')->name('api.v1.me.leases.document');

        // --- Invoices ---
        Route::get('me/invoices', ListInvoicesController::class)->name('api.v1.me.invoices.index');
        Route::get('me/invoices/{id}', ShowInvoiceController::class)->whereNumber('id')->name('api.v1.me.invoices.show');
        Route::get('me/invoices/{id}/pdf', InvoicePdfController::class)->whereNumber('id')->name('api.v1.me.invoices.pdf');
        Route::get('me/statement', StatementController::class)->name('api.v1.me.statement');

        // Paymob session — protected by the parent throttle:60,1. The initiator
        // is idempotent within REUSE_WINDOW_SECONDS, so retries inside that
        // window don't burn the budget on the upstream side either.
        Route::post('me/invoices/{invoice}/paymob-session', InitiatePaymobSessionController::class)
            ->whereNumber('invoice')
            ->name('api.v1.me.invoices.paymob-session');

        // Demo payment shortcut — only active while Paymob is disabled. Marks
        // the invoice paid through the real capture path (no gateway call) so
        // the app can simulate a successful payment in environments without a
        // live PSP. Returns 409 once PAYMOB_ENABLED=true.
        Route::post('me/invoices/{invoice}/pay-demo', DemoPayInvoiceController::class)
            ->whereNumber('invoice')
            ->name('api.v1.me.invoices.pay-demo');

        // --- Payments ---
        Route::get('me/payments', ListPaymentsController::class)->name('api.v1.me.payments.index');
        Route::get('me/payments/{id}', ShowPaymentController::class)->whereNumber('id')->name('api.v1.me.payments.show');
        // The receipt voucher (سند قبض) — the same PDF the admin table and the portal hand out.
        // Only for a payment whose money actually arrived; see the controller.
        Route::get('me/payments/{id}/receipt', PaymentReceiptController::class)
            ->whereNumber('id')->name('api.v1.me.payments.receipt');

        // --- Common-area recoveries (module 08): the tenant's share of a year's service charge ---
        // CAM had no API surface at all while the annual reconciliation put `cam_recovery` and
        // `cam_admin_fee` lines straight onto the invoice — so the app showed a large once-a-year
        // charge with no way to see the pool, the share, or the statement explaining it.
        Route::get('me/cam-allocations', ListCamAllocationsController::class)->name('api.v1.me.cam.index');
        Route::get('me/cam-allocations/{id}', ShowCamAllocationController::class)
            ->whereNumber('id')->name('api.v1.me.cam.show');
        // The tenant's own copy of the service-charge statement — the same PDF the portal hands
        // out. An audit right only the operator can print is one the tenant has to ask for.
        Route::get('me/cam-allocations/{id}/statement', CamStatementController::class)
            ->whereNumber('id')->name('api.v1.me.cam.statement');

        // --- Credit notes (read-only — issued by the operator) ---
        Route::get('me/credit-notes', ListCreditNotesController::class)->name('api.v1.me.credit-notes.index');
        Route::get('me/credit-notes/{id}', ShowCreditNoteController::class)->whereNumber('id')->name('api.v1.me.credit-notes.show');

        // --- Notifications inbox ---
        // Specific paths before the {id} route so they aren't captured by it.
        Route::get('me/notifications', ListNotificationsController::class)->name('api.v1.me.notifications.index');
        Route::get('me/notifications/unread-count', UnreadCountController::class)->name('api.v1.me.notifications.unread-count');
        Route::post('me/notifications/read-all', MarkAllNotificationsReadController::class)->name('api.v1.me.notifications.read-all');
        Route::post('me/notifications/{id}/read', MarkNotificationReadController::class)->name('api.v1.me.notifications.read');

        // --- Mall news (module 27): the operator's notices to this mall's tenants ---
        // The read surface an announcement never had. It is deliberately NOT behind a module
        // flag: `announcements` is core (absent from Modules::KEYS on purpose), because a mall
        // that cannot tell its tenants the garage is shut has no fallback channel.
        Route::get('me/announcements', ListAnnouncementsController::class)->name('api.v1.me.announcements.index');
        Route::get('me/announcements/{id}', ShowAnnouncementController::class)
            ->whereNumber('id')->name('api.v1.me.announcements.show');
        Route::post('me/announcements/{id}/read', MarkAnnouncementReadController::class)
            ->whereNumber('id')->name('api.v1.me.announcements.read');
        // Private artwork, streamed — never a public URL. See the controller docblock.
        Route::get('me/announcements/{id}/hero/{media}', ShowAnnouncementHeroController::class)
            ->whereNumber('id')->whereNumber('media')->name('api.v1.me.announcements.hero');

        // --- Maintenance requests ---
        // What a tenant may raise, and under which sub-category. The set is a CATALOGUE the
        // operator edits, so a client shipping its own copy is one release behind by construction
        // — and the symptom is a 422 on a picker the tenant was offered.
        Route::get('me/request-types', ListRequestTypesController::class)->name('api.v1.me.request-types');
        // Every classification this API emits, in BOTH languages, worded exactly as the panel
        // words it. Fetch once on launch and cache on `version`; five of the vocabularies are
        // catalogues the operator edits, which a client-side table can never be right about.
        Route::get('me/vocabulary', ShowVocabularyController::class)->name('api.v1.me.vocabulary');
        Route::get('me/requests', ListTenantRequestsController::class)->name('api.v1.me.requests.index');
        Route::post('me/requests', CreateTenantRequestController::class)->name('api.v1.me.requests.store');
        Route::get('me/requests/{id}', ShowTenantRequestController::class)->whereNumber('id')->name('api.v1.me.requests.show');
        Route::post('me/requests/{id}/comments', CommentTenantRequestController::class)->whereNumber('id')->name('api.v1.me.requests.comment');
        Route::post('me/requests/{id}/cancel', CancelTenantRequestController::class)->whereNumber('id')->name('api.v1.me.requests.cancel');
        Route::post('me/requests/{id}/rate', RateTenantRequestController::class)->whereNumber('id')->name('api.v1.me.requests.rate');
        // The tenant accepts, or sends it back. Same surface as the portal, different renderer —
        // the mobile app is where a shop manager actually is.
        Route::post('me/requests/{id}/confirm', ConfirmTenantRequestController::class)->whereNumber('id')->name('api.v1.me.requests.confirm');
        Route::post('me/requests/{id}/dispute', DisputeTenantRequestController::class)->whereNumber('id')->name('api.v1.me.requests.dispute');
        Route::get('me/requests/{id}/attachments/{media}', ShowTenantRequestAttachmentController::class)->whereNumber('id')->whereNumber('media')->name('api.v1.me.requests.attachment');

        // --- Sales declarations (percentage-rent leases) ---
        Route::get('me/sales-declarations', ListSalesDeclarationsController::class)->name('api.v1.me.sales.index');
        Route::post('me/sales-declarations', CreateSalesDeclarationController::class)->name('api.v1.me.sales.store');
        Route::get('me/sales-declarations/{id}', ShowSalesDeclarationController::class)->whereNumber('id')->name('api.v1.me.sales.show');
        Route::get('me/sales-declarations/{id}/attachments/{media}', ShowSalesDeclarationAttachmentController::class)->whereNumber('id')->whereNumber('media')->name('api.v1.me.sales.attachment');

        // --- Marketing posts (module 36): the retailer's own offers/events, and the mall feed ---
        // Composing here ends at `pending`; nothing a tenant can call reaches the public feed.
        //
        // Behind the SAME module gate as the public surface. Without it, switching
        // `marketing_posts` off would hide every operator review screen (RoleGatedActions folds
        // the flag into each permission) while retailers carried on submitting from their phones
        // into a queue nobody can see — the worst of both states, and invisible from either side.
        Route::middleware(EnsureMarketingPostsEnabled::class)->group(function () {
            Route::get('me/feed', [MarketingPostsController::class, 'feed'])->name('api.v1.me.feed');
            Route::get('me/marketing-posts', [MarketingPostsController::class, 'index'])->name('api.v1.me.posts.index');
            Route::post('me/marketing-posts', [MarketingPostsController::class, 'store'])->name('api.v1.me.posts.store');
            Route::get('me/marketing-posts/{id}', [MarketingPostsController::class, 'show'])
                ->whereNumber('id')->name('api.v1.me.posts.show');
            // POST rather than PATCH for the update: a multipart body (the hero image) does not
            // survive PHP's PATCH handling — the same wire-contract trap the mobile brief records.
            Route::post('me/marketing-posts/{id}', [MarketingPostsController::class, 'update'])
                ->whereNumber('id')->name('api.v1.me.posts.update');
            Route::post('me/marketing-posts/{id}/submit', [MarketingPostsController::class, 'submit'])
                ->whereNumber('id')->name('api.v1.me.posts.submit');
            Route::post('me/marketing-posts/{id}/withdraw', [MarketingPostsController::class, 'withdraw'])
                ->whereNumber('id')->name('api.v1.me.posts.withdraw');
            Route::delete('me/marketing-posts/{id}', [MarketingPostsController::class, 'destroy'])
                ->whereNumber('id')->name('api.v1.me.posts.destroy');
        });

        // --- Push device tokens ---
        // The list is what makes the DELETE reachable by a client that did not register the
        // device — a tenant who lost a phone otherwise had no id to revoke. The raw token is
        // never echoed back; see DeviceTokenResource.
        Route::get('me/devices', ListDevicesController::class)->name('api.v1.me.devices.index');
        Route::post('me/devices', RegisterDeviceController::class)->name('api.v1.me.devices.store');
        Route::delete('me/devices/{id}', UnregisterDeviceController::class)->whereNumber('id')->name('api.v1.me.devices.destroy');
    });

});
