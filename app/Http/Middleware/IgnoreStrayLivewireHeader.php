<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Drop an `X-Livewire` header that arrives on a plain page GET.
 *
 * WHY THIS EXISTS. A refusal (`DomainException` — "that accounting period is closed")
 * is turned into a notification + `back()` by the handler in bootstrap/app.php. When the
 * refusal came from a Livewire action, that 302 is answered by Livewire's own `fetch`,
 * and the browser follows it: same-origin redirects keep the request headers, so the
 * page the operator is sent back to is fetched as `GET /admin/... ` **still carrying
 * `X-Livewire: ""`**.
 *
 * That header is the whole of Livewire's `isLivewireRequest()` check
 * (`HandleRequests::isLivewireRequest()` — header only, no payload check), and Filament
 * builds its `originalRequest` binding on top of it: on a "Livewire" request it asks
 * `PersistentMiddleware::makeFakeRequest()` for the request the component was mounted
 * from (SupportServiceProvider). On a real update that mechanism has the component's
 * path and method to work with; on this redirect-follow there is no Livewire payload at
 * all, so it synthesises `''` as the method against `/` and
 * `RouteCollection::match()` throws **MethodNotAllowedHttpException** — which
 * `getRouteFromRequest()` does not catch (only `NotFoundHttpException`).
 *
 * Net effect: the operator asks to create an invoice dated into a closed period and gets
 * a 405 error modal instead of "that period is closed". The refusal machinery worked
 * perfectly; the redirect it issued is what blew up. Every refusal raised from a Livewire
 * action landed here, in both panels.
 *
 * A safe-method request never legitimately carries this header — Livewire's component
 * updates and file uploads are POSTs to `/livewire/*`, and `wire:navigate` announces
 * itself with `X-Livewire-Navigate` instead. So a GET with `X-Livewire` on an app route
 * is always this artefact, and removing it simply restores the truth: it is a normal page
 * request. Livewire's JS then sees `response.redirected` and navigates, which is what
 * makes the queued notification render.
 *
 * Deliberately not scoped to /admin: /portal is Livewire too, and the handler is global.
 */
class IgnoreStrayLivewireHeader
{
    public function handle(Request $request, Closure $next): Response
    {
        if (
            $request->isMethodSafe()
            && $request->hasHeader('X-Livewire')
            && ! $request->is('livewire/*')
        ) {
            // Mutating the bound Request in place is the point: `isLivewireRequest()`
            // reads it off the container's `request()` further down the stack.
            $request->headers->remove('X-Livewire');
        }

        return $next($request);
    }
}
