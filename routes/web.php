<?php

use App\Http\Controllers\HandbookController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\PaymentLinkController;
use App\Http\Controllers\Paymob\CallbackController;
use App\Http\Middleware\SetLocale;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
|--------------------------------------------------------------------------
| Health
|--------------------------------------------------------------------------
| Deliberately unauthenticated and outside the panels: an uptime monitor has to
| reach it while the app is broken. Laravel's stock `/up` (bootstrap/app.php)
| only proves PHP rendered a route — it answers 200 with the database down, the
| queue stalled and the scheduler dead. This one checks those.
|
| Throttled: it touches the DB and the filesystem, so it must not become a cheap
| way to load the box.
*/
Route::get('/health', HealthController::class)
    ->middleware('throttle:60,1')
    ->name('health');

/*
 * Switching language is a preference, not a session fact.
 *
 * It used to be written to the session and nowhere else, which answered for the screen in front of
 * you and for nothing that arrives while you are not looking at it. A scheduled command has no
 * session, so every alert the nightly sweeps raised — overdue invoices, SLA breaches, expiring
 * documents — rendered in `config('app.locale')` for everybody; and a notification raised inside a
 * request rendered in the SENDER's language, so an operator working in Arabic sent Arabic invoice
 * emails to English-speaking tenants.
 *
 * So it is persisted on the signed-in record as well. Laravel reads it back through
 * `HasLocalePreference` when it dispatches a notification, which is what makes mail and push arrive
 * in the recipient's language. The session write stays: it is what makes THIS request's redirect
 * render in the new language, and it is all an anonymous visitor has.
 */
Route::get('/locale/{locale}', function (string $locale) {
    if (! in_array($locale, SetLocale::SUPPORTED, true)) {
        return back();
    }

    session(['locale' => $locale]);

    // Both panels, whichever the switcher was clicked in. `Auth::user()` alone would miss the
    // portal, whose guard is not the default one — and the portal is where this matters most.
    foreach (['web', 'portal'] as $guard) {
        $user = Auth::guard($guard)->user();

        if ($user instanceof Model && $user->getAttribute('locale') !== $locale) {
            $user->forceFill(['locale' => $locale])->saveQuietly();
        }
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
 * The demo settle button — its OWN, tighter limit rather than the group's 30/min.
 *
 * This is the one route under /pay that writes money, and it is unauthenticated: the bearer token
 * in the URL is the whole of who is asking. A legitimate caller presses it once, so six a minute
 * is generous; the group's 30 would let a scripted caller hammer the capture path. It sits outside
 * the group because two `throttle` middlewares on one route share a request signature and the
 * counts interfere. `DemoPayments::enabled()` (checked in the controller) is what actually keeps
 * this off production — the limit only bounds the damage where it IS live.
 */
Route::post('/pay/{token}/demo', [PaymentLinkController::class, 'demo'])
    ->middleware('throttle:6,1')
    ->name('pay.demo');

/*
|--------------------------------------------------------------------------
| The visual handbook
|--------------------------------------------------------------------------
| Built by `npm run docs:build` into storage/app/handbook — OUTSIDE the webroot,
| so nginx cannot serve it directly and `auth` genuinely applies. It documents
| posting rules, GL mappings, approval ladders and internal controls, which is
| not material for a guessable public URL.
|
| `where('.*')` because the segment is a real path: /handbook/ar/money/the-books.
| The traversal guard lives in the controller and is a resolved-prefix check,
| not a string check.
*/
Route::get('/handbook/{path?}', HandbookController::class)
    ->where('path', '.*')
    ->middleware(['auth'])
    ->name('handbook');

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
