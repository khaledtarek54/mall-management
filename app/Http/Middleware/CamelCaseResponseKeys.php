<?php

namespace App\Http\Middleware;

use App\Support\KeyCase;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Re-cases outgoing JSON response keys to camelCase to match the Flutter app's
 * contract. Only touches JsonResponse — PDF / binary streams pass through
 * untouched. An exception thrown before this middleware in the stack (auth, the throttle) is
 * rendered outside it, so the exception handler camelCases its own keys; one thrown after it (the
 * tenant middlewares, a controller) is rendered where it is thrown and passes back out through here.
 * Both go through KeyCase, so the two cannot drift.
 */
class CamelCaseResponseKeys
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response instanceof JsonResponse) {
            $data = $response->getData(true);

            if (is_array($data)) {
                $response->setData(KeyCase::camelKeys($data));
            }
        }

        return $response;
    }
}
