<?php

namespace App\Http\Controllers;

use App\Support\Health;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The endpoint an external uptime monitor polls.
 *
 * External is the whole point. `backup:monitor` and every other scheduled check
 * can only report a problem if the scheduler is alive — so none of them can
 * report that the scheduler is dead. Something off-box has to ask.
 *
 * Returns 503 when any check fails, because an uptime monitor reads the status
 * code, not the body.
 */
class HealthController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $result = Health::run();
        $status = $result['status'] === 'ok' ? 200 : 503;

        // Detail names internal subsystems and can carry a DB error message, so
        // it is gated. Without the token the caller learns only up/down — which
        // is all an uptime monitor needs.
        if (! $this->authorised($request)) {
            return response()->json(['status' => $result['status']], $status);
        }

        return response()->json($result, $status);
    }

    private function authorised(Request $request): bool
    {
        $token = config('health.token');

        if (blank($token)) {
            return false;
        }

        // hash_equals: a plain === leaks the token a character at a time to
        // anyone willing to measure the response.
        $supplied = (string) ($request->header('X-Health-Token') ?? $request->query('token', ''));

        return hash_equals((string) $token, $supplied);
    }
}
