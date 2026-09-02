<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Pdf\DocumentLocale;
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
     * The language a streamed document should be written in.
     *
     * **The request wins here, and that is the opposite of the panel's default.** On the admin and
     * portal surfaces a document defaults to the RECIPIENT's stored language, because an operator
     * is producing a document for somebody else. On the API the caller IS the recipient, and they
     * have already said what they read — `SetApiLocale` resolves `Accept-Language` into the app
     * locale on every request. Letting the stored `tenants.locale` column override that would mean
     * a tenant who switches the mobile app to English keeps receiving Arabic PDFs, with no way to
     * change it from inside the app.
     *
     * `?lang=` is the explicit override for a client that wants one document in the other language
     * without changing its headers — the API's counterpart to the panel's download picker. Anything
     * unsupported falls through to the request's own locale rather than failing: an unreadable
     * parameter should not cost the caller their invoice.
     */
    protected function documentLocale(Request $request): string
    {
        return DocumentLocale::resolve($request->query('lang'));
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
     * Render a closure under one locale and put the request's own back.
     *
     * The bilingual payloads on this surface — the request-type catalogue, the vocabulary — are
     * built by asking the SAME resolver the panel uses, once per language, rather than by reaching
     * past it into the rows. That way an operator-added code with no lang key resolves exactly as
     * it does on screen, and there is no second wording to drift.
     *
     * `finally`, so a catalogue read that throws cannot leave the rest of the response — and, on a
     * long-lived worker, every response after it — rendering in the wrong language. Same reasoning
     * as {@see \App\Support\Pdf\DocumentLocale::in()}.
     *
     * @template T
     *
     * @param  \Closure(): T  $render
     * @return T
     */
    protected function inLocale(string $locale, \Closure $render): mixed
    {
        $previous = app()->getLocale();
        app()->setLocale($locale);

        try {
            return $render();
        } finally {
            app()->setLocale($previous);
        }
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
