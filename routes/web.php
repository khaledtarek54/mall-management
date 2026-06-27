<?php

use App\Http\Controllers\PaymentLinkController;
use App\Http\Controllers\Paymob\CallbackController;
use App\Http\Middleware\SetLocale;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/locale/{locale}', function (string $locale) {
    if (in_array($locale, SetLocale::SUPPORTED, true)) {
        session(['locale' => $locale]);
    }

    return back();
})->name('locale.switch');

/*
|--------------------------------------------------------------------------
| Paymob
|--------------------------------------------------------------------------
| processed = server-to-server callback (HMAC-verified, CSRF-exempt — see
| bootstrap/app.php validateCsrfTokens(except: ['paymob/callback'])).
| returned = browser bounce-back URL after the iframe — UX-only.
*/
Route::post('/paymob/callback', [CallbackController::class, 'processed'])
    ->name('paymob.callback');

Route::get('/paymob/return', [CallbackController::class, 'returned'])
    ->name('paymob.return');

/*
|--------------------------------------------------------------------------
| Public online payment link  (channel = payment_link)
|--------------------------------------------------------------------------
| No login. A client opens /pay/{token}, pays via Paymob, lands on a public
| status page. Throttled — these are unauthenticated, internet-facing routes.
*/
Route::middleware('throttle:30,1')->group(function () {
    Route::get('/pay/{token}', [PaymentLinkController::class, 'show'])->name('pay.show');
    Route::post('/pay/{token}/start', [PaymentLinkController::class, 'start'])->name('pay.start');
    Route::get('/pay/{token}/status', [PaymentLinkController::class, 'status'])->name('pay.status');
});

/*
| Apple Pay domain verification. Apple requires this exact path to serve the
| merchant domain-association file before Apple Pay will render. Drop the file
| Apple/Paymob gives you at storage/app/apple-pay/domain-association and it is
| served here. Returns 404 until configured. See docs/PAYMENT-LINK-APPLEPAY.md.
*/
Route::get('/.well-known/apple-developer-merchantid-domain-association', function () {
    $path = storage_path('app/apple-pay/domain-association');
    abort_unless(is_file($path), 404);

    return Response::make(file_get_contents($path), 200, ['Content-Type' => 'text/plain']);
})->name('applepay.domain-association');
