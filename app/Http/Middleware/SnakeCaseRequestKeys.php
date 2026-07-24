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
 *
 * All FOUR input bags must be covered, not just the JSON one. A request body
 * lives in exactly one of the JSON bag (application/json) or the request bag
 * (multipart/form-data + urlencoded) — and for a long while only the former was
 * re-cased. That silently broke every multipart endpoint for the camelCase
 * client we publish: `leaseId` never reached validation (POST
 * /me/sales-declarations 422'd on `lease_id` every time) and `unitId` /
 * `requestType` were dropped without an error, so a request was filed against
 * the wrong unit. Uploaded files travel in their own bag that merge() does not
 * reach, so they are re-cased too.
 */
class SnakeCaseRequestKeys
{
    public function handle(Request $request, Closure $next): Response
    {
        // Body — exactly one of these bags carries it.
        if ($request->isJson()) {
            if (is_array($request->json()->all())) {
                $request->merge(KeyCase::snakeKeys($request->json()->all()));
            }
        } elseif ($request->request->count() > 0) {
            $request->merge(KeyCase::snakeKeys($request->request->all()));
        }

        if ($request->query->count() > 0) {
            $request->query->replace(KeyCase::snakeKeys($request->query->all()));
        }

        // Uploaded-file field names (`salesReport[]` -> `sales_report`). Numeric
        // keys inside an `attachments[]` array are left alone by KeyCase.
        if ($request->files->count() > 0) {
            $request->files->replace(KeyCase::snakeKeys($request->files->all()));
        }

        return $next($request);
    }
}
