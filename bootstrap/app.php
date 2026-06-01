<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        apiPrefix: 'api',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            \App\Http\Middleware\SetLocale::class,
        ]);

        // The mobile API is stateless (no session), so it resolves locale from
        // the Accept-Language header rather than the session. The case middleware
        // bridge the Flutter app's camelCase contract to the backend's
        // snake_case: requests are snake-cased on the way in, responses
        // camel-cased on the way out.
        $middleware->api(append: [
            \App\Http\Middleware\SetApiLocale::class,
            \App\Http\Middleware\SnakeCaseRequestKeys::class,
            \App\Http\Middleware\CamelCaseResponseKeys::class,
        ]);

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
        if (\App\Http\Middleware\RecordCoverage::shouldRecord()) {
            $middleware->prepend(\App\Http\Middleware\RecordCoverage::class);
        }
    })
    // Scheduled jobs live in routes/console.php — see Schedule::job(...) and
    // Schedule::command(...) calls there for the monthly billing, daily late
    // fees, and annual CAM reconciliation cadences.
    ->withExceptions(function (Exceptions $exceptions): void {
        // Mobile API error contract: every /api/* failure renders as
        // { "message": "...", "statusCode": <int> } (+ "errors" for validation).
        // Keys are camelCased here too, since exception responses unwind
        // outside the CamelCaseResponseKeys middleware.
        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            if ($e instanceof \Illuminate\Validation\ValidationException) {
                return response()->json([
                    'message' => $e->validator->errors()->first(),
                    'errors' => \App\Support\KeyCase::camelKeys($e->errors()),
                    'statusCode' => 422,
                ], 422);
            }

            if ($e instanceof \Illuminate\Auth\AuthenticationException) {
                return response()->json(['message' => 'Unauthenticated.', 'statusCode' => 401], 401);
            }

            $status = match (true) {
                $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException => 404,
                $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface => $e->getStatusCode(),
                default => 500,
            };

            $message = $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface && $e->getMessage() !== ''
                ? $e->getMessage()
                : (\Symfony\Component\HttpFoundation\Response::$statusTexts[$status] ?? 'Error');

            if ($status === 500 && ! config('app.debug')) {
                $message = 'Server error';
            }

            return response()->json(['message' => $message, 'statusCode' => $status], $status);
        });
    })->create();
