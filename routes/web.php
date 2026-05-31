<?php

use App\Http\Controllers\Paymob\CallbackController;
use App\Http\Middleware\SetLocale;
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
