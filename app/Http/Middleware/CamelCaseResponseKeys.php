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
 * untouched. Exception responses are camelCased in the exception handler
 * (they unwind outside this middleware), so the two stay in sync via KeyCase.
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
