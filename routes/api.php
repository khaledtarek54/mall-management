<?php

use App\Http\Controllers\Api\V1\Auth\ChangePasswordController;
use App\Http\Controllers\Api\V1\Auth\ForgotPasswordController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\MeController;
use App\Http\Controllers\Api\V1\Auth\ResetPasswordController;
use App\Http\Controllers\Api\V1\Devices\RegisterDeviceController;
use App\Http\Controllers\Api\V1\Devices\UnregisterDeviceController;
use App\Http\Controllers\Api\V1\Invoices\InvoicePdfController;
use App\Http\Controllers\Api\V1\Invoices\ListInvoicesController;
use App\Http\Controllers\Api\V1\Invoices\ShowInvoiceController;
use App\Http\Controllers\Api\V1\Invoices\StatementController;
use App\Http\Controllers\Api\V1\Maintenance\CancelMaintenanceRequestController;
use App\Http\Controllers\Api\V1\Maintenance\CommentMaintenanceRequestController;
use App\Http\Controllers\Api\V1\Maintenance\CreateMaintenanceRequestController;
use App\Http\Controllers\Api\V1\Maintenance\ListMaintenanceRequestsController;
use App\Http\Controllers\Api\V1\Maintenance\ShowMaintenanceRequestController;
use App\Http\Controllers\Api\V1\Payments\ListPaymentsController;
use App\Http\Controllers\Api\V1\Payments\ShowPaymentController;
use App\Http\Controllers\Api\V1\Profile\BalanceController;
use App\Http\Controllers\Api\V1\Profile\LeasesController;
use App\Http\Controllers\Api\V1\Profile\ShowProfileController;
use App\Http\Controllers\Api\V1\Profile\UpdateProfileController;
use App\Http\Controllers\Api\V1\SalesDeclarations\CreateSalesDeclarationController;
use App\Http\Controllers\Api\V1\SalesDeclarations\ListSalesDeclarationsController;
use App\Http\Controllers\Api\V1\SalesDeclarations\ShowSalesDeclarationController;
use App\Http\Controllers\Api\V1\Tenant\InitiatePaymobSessionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — Mobile (tenant-facing)
|--------------------------------------------------------------------------
| Sanctum token auth against the `tenants` provider. Web/admin endpoints
| are NOT exposed here; the Filament panels at /admin /portal /owner are
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

        // --- Payments ---
        Route::get('me/payments', ListPaymentsController::class)->name('api.v1.me.payments.index');
        Route::get('me/payments/{id}', ShowPaymentController::class)->whereNumber('id')->name('api.v1.me.payments.show');

        // --- Maintenance requests ---
        Route::get('me/maintenance-requests', ListMaintenanceRequestsController::class)->name('api.v1.me.maintenance.index');
        Route::post('me/maintenance-requests', CreateMaintenanceRequestController::class)->name('api.v1.me.maintenance.store');
        Route::get('me/maintenance-requests/{id}', ShowMaintenanceRequestController::class)->whereNumber('id')->name('api.v1.me.maintenance.show');
        Route::post('me/maintenance-requests/{id}/comments', CommentMaintenanceRequestController::class)->whereNumber('id')->name('api.v1.me.maintenance.comment');
        Route::post('me/maintenance-requests/{id}/cancel', CancelMaintenanceRequestController::class)->whereNumber('id')->name('api.v1.me.maintenance.cancel');

        // --- Sales declarations (percentage-rent leases) ---
        Route::get('me/sales-declarations', ListSalesDeclarationsController::class)->name('api.v1.me.sales.index');
        Route::post('me/sales-declarations', CreateSalesDeclarationController::class)->name('api.v1.me.sales.store');
        Route::get('me/sales-declarations/{id}', ShowSalesDeclarationController::class)->whereNumber('id')->name('api.v1.me.sales.show');

        // --- Push device tokens ---
        Route::post('me/devices', RegisterDeviceController::class)->name('api.v1.me.devices.store');
        Route::delete('me/devices/{id}', UnregisterDeviceController::class)->whereNumber('id')->name('api.v1.me.devices.destroy');
    });

});
