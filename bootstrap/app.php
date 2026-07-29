<?php

use App\Http\Middleware\CamelCaseResponseKeys;
use App\Http\Middleware\RecordCoverage;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetApiLocale;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\SnakeCaseRequestKeys;
use App\Support\KeyCase;
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
