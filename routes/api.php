<?php

use App\Http\Controllers\Api\V1\Auth\ChangePasswordController;
use App\Http\Controllers\Api\V1\Auth\ForgotPasswordController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\MeController;
use App\Http\Controllers\Api\V1\Auth\ResetPasswordController;
use App\Http\Controllers\Api\V1\CreditNotes\ListCreditNotesController;
use App\Http\Controllers\Api\V1\CreditNotes\ShowCreditNoteController;
use App\Http\Controllers\Api\V1\Devices\RegisterDeviceController;
use App\Http\Controllers\Api\V1\Devices\UnregisterDeviceController;
use App\Http\Controllers\Api\V1\Invoices\InvoicePdfController;
use App\Http\Controllers\Api\V1\Invoices\ListInvoicesController;
use App\Http\Controllers\Api\V1\Invoices\ShowInvoiceController;
use App\Http\Controllers\Api\V1\Invoices\StatementController;
use App\Http\Controllers\Api\V1\Requests\CancelTenantRequestController;
use App\Http\Controllers\Api\V1\Requests\CommentTenantRequestController;
use App\Http\Controllers\Api\V1\Requests\CreateTenantRequestController;
use App\Http\Controllers\Api\V1\Requests\ListTenantRequestsController;
use App\Http\Controllers\Api\V1\Requests\RateTenantRequestController;
use App\Http\Controllers\Api\V1\Requests\ShowTenantRequestAttachmentController;
use App\Http\Controllers\Api\V1\Requests\ShowTenantRequestController;
use App\Http\Controllers\Api\V1\Notifications\ListNotificationsController;
use App\Http\Controllers\Api\V1\Notifications\MarkAllNotificationsReadController;
use App\Http\Controllers\Api\V1\Notifications\MarkNotificationReadController;
use App\Http\Controllers\Api\V1\Notifications\UnreadCountController;
use App\Http\Controllers\Api\V1\Payments\ListPaymentsController;
use App\Http\Controllers\Api\V1\Payments\ShowPaymentController;
use App\Http\Controllers\Api\V1\Profile\BalanceController;
use App\Http\Controllers\Api\V1\Profile\LeasesController;
use App\Http\Controllers\Api\V1\Profile\ShowProfileController;
use App\Http\Controllers\Api\V1\Profile\SummaryController;
use App\Http\Controllers\Api\V1\Profile\UpdateProfileController;
use App\Http\Controllers\Api\V1\SalesDeclarations\CreateSalesDeclarationController;
use App\Http\Controllers\Api\V1\SalesDeclarations\ListSalesDeclarationsController;
use App\Http\Controllers\Api\V1\SalesDeclarations\ShowSalesDeclarationAttachmentController;
use App\Http\Controllers\Api\V1\SalesDeclarations\ShowSalesDeclarationController;
use App\Http\Controllers\Api\V1\Tenant\DemoPayInvoiceController;
use App\Http\Controllers\Api\V1\Tenant\InitiatePaymobSessionController;
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

    // Password reset request + apply — tighter throttle (anti-abuse), still public.
    Route::middleware('throttle:3,1')->group(function () {
        Route::post('auth/forgot-password', ForgotPasswordController::class)->name('api.v1.auth.forgot-password');
        Route::post('auth/reset-password', ResetPasswordController::class)->name('api.v1.auth.reset-password');
    });

    // ============ Authenticated (Sanctum tenant-api guard) ============
    Route::middleware(['auth:tenant-api', 'throttle:60,1'])->group(function () {

        // --- Auth / session ---
        Route::get('auth/me', MeController::class)->name('api.v1.auth.me');
        Route::post('auth/logout', LogoutController::class)->name('api.v1.auth.logout');
        Route::post('auth/change-password', ChangePasswordController::class)->name('api.v1.auth.change-password');

        // --- Profile / account ---
        Route::get('me', ShowProfileController::class)->name('api.v1.me.show');
        Route::patch('me', UpdateProfileController::class)->name('api.v1.me.update');
        Route::get('me/balance', BalanceController::class)->name('api.v1.me.balance');
        // Home-screen rollup — money owed + open work + things needing attention.
        Route::get('me/summary', SummaryController::class)->name('api.v1.me.summary');
        Route::get('me/leases', LeasesController::class)->name('api.v1.me.leases');

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

        // --- Credit notes (read-only — issued by the operator) ---
        Route::get('me/credit-notes', ListCreditNotesController::class)->name('api.v1.me.credit-notes.index');
        Route::get('me/credit-notes/{id}', ShowCreditNoteController::class)->whereNumber('id')->name('api.v1.me.credit-notes.show');

        // --- Notifications inbox ---
        // Specific paths before the {id} route so they aren't captured by it.
        Route::get('me/notifications', ListNotificationsController::class)->name('api.v1.me.notifications.index');
        Route::get('me/notifications/unread-count', UnreadCountController::class)->name('api.v1.me.notifications.unread-count');
        Route::post('me/notifications/read-all', MarkAllNotificationsReadController::class)->name('api.v1.me.notifications.read-all');
        Route::post('me/notifications/{id}/read', MarkNotificationReadController::class)->name('api.v1.me.notifications.read');

        // --- Maintenance requests ---
        Route::get('me/requests', ListTenantRequestsController::class)->name('api.v1.me.requests.index');
        Route::post('me/requests', CreateTenantRequestController::class)->name('api.v1.me.requests.store');
        Route::get('me/requests/{id}', ShowTenantRequestController::class)->whereNumber('id')->name('api.v1.me.requests.show');
        Route::post('me/requests/{id}/comments', CommentTenantRequestController::class)->whereNumber('id')->name('api.v1.me.requests.comment');
        Route::post('me/requests/{id}/cancel', CancelTenantRequestController::class)->whereNumber('id')->name('api.v1.me.requests.cancel');
        Route::post('me/requests/{id}/rate', RateTenantRequestController::class)->whereNumber('id')->name('api.v1.me.requests.rate');
        Route::get('me/requests/{id}/attachments/{media}', ShowTenantRequestAttachmentController::class)->whereNumber('id')->whereNumber('media')->name('api.v1.me.requests.attachment');

        // --- Sales declarations (percentage-rent leases) ---
        Route::get('me/sales-declarations', ListSalesDeclarationsController::class)->name('api.v1.me.sales.index');
        Route::post('me/sales-declarations', CreateSalesDeclarationController::class)->name('api.v1.me.sales.store');
        Route::get('me/sales-declarations/{id}', ShowSalesDeclarationController::class)->whereNumber('id')->name('api.v1.me.sales.show');
        Route::get('me/sales-declarations/{id}/attachments/{media}', ShowSalesDeclarationAttachmentController::class)->whereNumber('id')->whereNumber('media')->name('api.v1.me.sales.attachment');

        // --- Push device tokens ---
        Route::post('me/devices', RegisterDeviceController::class)->name('api.v1.me.devices.store');
        Route::delete('me/devices/{id}', UnregisterDeviceController::class)->whereNumber('id')->name('api.v1.me.devices.destroy');
    });

});
