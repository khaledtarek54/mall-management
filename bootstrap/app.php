<?php

use App\Http\Middleware\CamelCaseResponseKeys;
use App\Http\Middleware\IgnoreStrayLivewireHeader;
use App\Http\Middleware\RecordCoverage;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetApiLocale;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\SnakeCaseRequestKeys;
use App\Support\KeyCase;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Sentry\Laravel\Integration;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        apiPrefix: 'api',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The app always runs behind a TLS-terminating proxy (Herd locally, nginx / a load
        // balancer in production). Without this, `$request->ip()` is the PROXY's address, not the
        // caller's — which silently guts everything that keys on the client: login throttling
        // (one shared bucket for the whole internet), the activity log's audit trail, and the
        // Paymob callback's origin. `X-Forwarded-*` is also what tells Laravel the original
        // request was https.
        //
        // `TRUSTED_PROXIES` defaults to `*` because the proxy's address is deployment-specific and
        // usually not stable. That is safe ONLY because the app is not reachable except through
        // that proxy — if it is ever exposed directly, pin this to the real addresses, or a caller
        // can forge their own X-Forwarded-For and become un-throttleable.
        $middleware->trustProxies(
            at: env('TRUSTED_PROXIES', '*'),
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_AWS_ELB,
        );

        $middleware->web(append: [
            SetLocale::class,
        ]);

        // Where `auth` sends a guest.
        //
        // This app has no route named `login` — both panels register their own
        // (`filament.admin.auth.login`, `filament.portal.auth.login`), and Laravel's default
        // unauthenticated handler calls `route('login')` unconditionally. So the FIRST plain
        // `auth` route added outside a panel answered a guest with a 500 (RouteNotFoundException)
        // instead of a redirect — an authorisation failure presenting as a server fault, which is
        // the kind that gets triaged as an outage. `/handbook` was that route.
        $middleware->redirectGuestsTo(fn () => Filament::getPanel('admin')->getLoginUrl());

        // The mobile API is stateless (no session), so it resolves locale from
        // the Accept-Language header rather than the session. The case middleware
        // bridge the Flutter app's camelCase contract to the backend's
        // snake_case: requests are snake-cased on the way in, responses
        // camel-cased on the way out.
        $middleware->api(append: [
            SetApiLocale::class,
            SnakeCaseRequestKeys::class,
            CamelCaseResponseKeys::class,
        ]);

        // Baseline security headers on every response (+ a tight CSP on /pay/*).
        $middleware->append(SecurityHeaders::class);

        // A `back()` issued to a Livewire fetch is followed by the browser with the
        // `X-Livewire` header still attached, which makes Filament mis-read the page GET
        // as a component update and 405. Runs first so nothing downstream sees the stray
        // header. See the middleware's docblock — this is what keeps a DomainException
        // refusal rendering as a toast rather than an error modal.
        $middleware->prepend(IgnoreStrayLivewireHeader::class);

        // The API does not use cookies/session — Sanctum tokens only. Disable
        // CSRF for /api/* (Laravel does this by default but spell it out).
        // Paymob's S2S callback is HMAC-verified and not browser-originated,
        // so it is also CSRF-exempt.
        $middleware->validateCsrfTokens(except: [
            'api/*',
            'paymob/callback',
        ]);

        // E2E coverage capture — only active when the server is booted with
        // COVERAGE=1. See app/Http/Middleware/RecordCoverage.php.
        if (RecordCoverage::shouldRecord()) {
            $middleware->prepend(RecordCoverage::class);
        }
    })
    // Scheduled jobs live in routes/console.php — see Schedule::job(...) and
    // Schedule::command(...) calls there for the monthly billing, daily late
    // fees, and annual CAM reconciliation cadences.
    ->withExceptions(function (Exceptions $exceptions): void {
        // Report unhandled exceptions to Sentry. Until this existed, a 500 or an exhausted
        // queue job surfaced only as a customer complaint — OpsLog covers the money paths it
        // was told about, and nothing covered the ones nobody anticipated.
        //
        // No-ops without SENTRY_LARAVEL_DSN (the SDK's transport skips the send outright), so
        // this is inert in dev and tests and turns on with one env var. PII is withheld by
        // config/sentry.php — see send_default_pii + before_send there.
        Integration::handles($exceptions);

        // Mobile API error contract: every /api/* failure renders as
        // { "message": "...", "statusCode": <int> } (+ "errors" for validation).
        // Keys are camelCased here too, since exception responses unwind
        // outside the CamelCaseResponseKeys middleware.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            if ($e instanceof ValidationException) {
                return response()->json([
                    'message' => $e->validator->errors()->first(),
                    'errors' => KeyCase::camelKeys($e->errors()),
                    'statusCode' => 422,
                ], 422);
            }

            if ($e instanceof AuthenticationException) {
                return response()->json(['message' => 'Unauthenticated.', 'statusCode' => 401], 401);
            }

            // A DomainException is a REFUSAL, not a fault — the web side has said so since
            // bootstrap's handler below, but the API contract had no case for it, so it fell to
            // `default => 500` and the message was overwritten with "Internal Server Error".
            //
            // A mobile client showed the user a crash for "you have no active lease in that
            // property" — a sentence they could have acted on. Found by actually calling the
            // endpoint; no test caught it because the services' refusals were all asserted at the
            // service layer, where they are DomainExceptions, not over HTTP.
            //
            // 422, matching the ValidationException case above: both are "your request was
            // well-formed but I will not do it", and the client already handles that shape.
            if ($e instanceof DomainException) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'statusCode' => 422,
                ], 422);
            }

            $status = match (true) {
                $e instanceof ModelNotFoundException => 404,
                $e instanceof HttpExceptionInterface => $e->getStatusCode(),
                default => 500,
            };

            $message = $e instanceof HttpExceptionInterface && $e->getMessage() !== ''
                ? $e->getMessage()
                : (Response::$statusTexts[$status] ?? 'Error');

            if ($status === 500 && ! config('app.debug')) {
                $message = 'Server error';
            }

            return response()->json(['message' => $message, 'statusCode' => $status], $status);
        });

        // A DomainException is a REFUSAL the operator caused and can act on — "that accounting
        // period is closed", "this would over-apply the credit". It is not a fault, and it must
        // not look like one: uncaught it renders the 500 page, which loses the form they filled
        // in and reads as "the app broke" rather than "that is not allowed, here is why".
        //
        // Centrally, because the refusals now come from MODEL hooks as much as from services
        // (App\Models\Concerns\GuardsPostingDate covers Invoice, Payment, Expense, MarketingSpend,
        // DepositTransaction and FixedAsset at once). Handling it per-page would mean a catch on
        // every Create page, every Edit page and every relation manager that can touch one of
        // them — and the one everybody forgets is the one the operator finds.
        //
        // Pages that already catch it themselves keep working: they never reach here, and their
        // message is usually more specific.
        $exceptions->render(function (DomainException $e, Request $request) {
            if ($request->is('api/*')) {
                return null; // the JSON contract above owns these
            }

            Notification::make()
                ->title(__('admin.posting.errors.refused'))
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();

            return back();
        });
    })->create();
