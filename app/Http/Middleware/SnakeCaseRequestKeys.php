<?php

namespace App\Http\Middleware;

use App\Support\KeyCase;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Normalises incoming request keys to snake_case so the Flutter app can speak
 * camelCase (newPassword, deviceName, periodFrom) while FormRequests / Eloquent
 * keep working in snake_case. Idempotent for already-snake keys.
 */
class SnakeCaseRequestKeys
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isJson() && is_array($request->json()->all())) {
            $request->merge(KeyCase::snakeKeys($request->json()->all()));
        }

        if ($request->query->count() > 0) {
            $request->query->replace(KeyCase::snakeKeys($request->query->all()));
        }

        return $next($request);
    }
}
