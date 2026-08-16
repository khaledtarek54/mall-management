<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared base for every /api/v1 controller. Centralises the three things that
 * were otherwise copy-pasted across endpoints — the {data, message} envelope,
 * pagination clamping, and PDF streaming — so the individual controllers stay
 * thin and uniform (DRY).
 */
abstract class ApiController extends Controller
{
    /**
     * Standard success envelope. Omits whichever of data / message is null so
     * message-only responses (e.g. logout) don't carry a null data key.
     */
    protected function ok(mixed $data = null, ?string $message = null, int $status = 200): JsonResponse
    {
        $payload = [];

        if ($data !== null) {
            $payload['data'] = $data;
        }

        if ($message !== null) {
            $payload['message'] = $message;
        }

        return response()->json($payload, $status);
    }

    /**
     * Resolve a safe page size from the request: defaults to 25, hard-capped
     * at 100 so a client can't ask for an unbounded page.
     */
    protected function perPage(Request $request, int $default = 25, int $max = 100): int
    {
        $value = (int) $request->integer('per_page', $default);

        return max(1, min($value ?: $default, $max));
    }

    /**
     * The `meta` block for an endpoint that shapes its own payload instead of returning a resource
     * collection.
     *
     * Laravel emits six keys on a paginated `ResourceCollection`; the three hand-rolled endpoints
     * here emitted four, so the client had **two** pagination shapes to model depending on which
     * list it was reading — and `from`/`to` (what this page actually covers) were the two missing.
     * One method so there is one shape.
     *
     * @param  LengthAwarePaginator<int, mixed>  $paginator
     * @return array<string, int|null>
     */
    protected function paginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ];
    }

    /**
     * Stream a generated PDF as a file download.
     */
    protected function streamPdf(string $contents, string $filename): Response
    {
        return response($contents, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}
