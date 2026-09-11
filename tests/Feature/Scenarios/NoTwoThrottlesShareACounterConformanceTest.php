<?php

use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

/**
 * No two throttles share a counter by accident.
 *
 * An unnamed `throttle:X,Y` keys a guest request on the IP alone — `sha1($domain.'|'.$ip)`: the
 * route's PATH is not in it, nor the limit, only its domain, which no route here declares — so until 2026-09-11 every guest throttle in the app spent ONE counter
 * per address, each route measuring that shared count against its own ceiling: five screens of the
 * shopper feed and the next sign-in answered 429 (mobile §L L1; the behaviour is pinned by
 * `BrowsingTheMallDoesNotSpendTheSignInTest`). The third parameter names the counter. Three comments
 * said "its own bucket" while none of them had one, which is why this is a gate and not a comment.
 *
 * It reads the LIVE route table through `gatherRouteMiddleware()` — groups and aliases expanded to the
 * class and its parameters — not the route files, so a throttle on a new route file or declared as
 * `ThrottleRequests::class.':…'` is covered by existing. Three teeth, each catching what the others
 * cannot: every in-app throttle names a counter; a counter keeps ONE limit (two ceilings on one counter
 * each spend the other's); and the counters are the set below, so a new budget is a decision made here
 * rather than a line copied from the group above.
 *
 * What it cannot see, said rather than implied: a prefix REUSED on a new group with the same limit.
 * Two groups sharing a budget on purpose look exactly like an accident, so that one is the reviewer's.
 */

/**
 * Every throttle in the live route table.
 *
 * @return list<array{route: string, action: string, counter: ?string, limit: string}>
 */
function throttleCountersInTheRouteTable(): array
{
    $router = app('router');
    $found = [];

    foreach (Route::getRoutes() as $route) {
        foreach ($router->gatherRouteMiddleware($route) as $middleware) {
            // A closure middleware is not a throttle, and has no parameters to read.
            if (! is_string($middleware)) {
                continue;
            }

            [$class, $parameters] = array_pad(explode(':', $middleware, 2), 2, '');

            // `is_a`, not a list: ThrottleRequestsWithRedis extends it, and so would any throttle of ours.
            if (! is_a($class, ThrottleRequests::class, true)) {
                continue;
            }

            $parameters = $parameters === '' ? [] : explode(',', $parameters);

            // One parameter naming a REGISTERED limiter (`throttle:api`) is keyed by that name. Anything
            // else that looks like one — `throttle:60|120`, the guest|user limit split — is no limiter at
            // all: it keys on the requester alone, exactly like a throttle that names no counter.
            $counter = count($parameters) === 1 && RateLimiter::limiter($parameters[0]) !== null
                ? 'limiter:'.$parameters[0]
                : ($parameters[2] ?? null);

            $found[] = [
                'route' => implode('|', $route->methods()).' '.$route->uri(),
                'action' => $route->getActionName(),
                'counter' => $counter === '' ? null : $counter,
                'limit' => implode(',', array_slice($parameters, 0, 2)),
            ];
        }
    }

    return $found;
}

/**
 * Vendor routes that carry the vendor's own throttle, each with why it is not ours to name.
 *
 * @return list<string>
 */
function vendorThrottlesNotOursToName(): array
{
    return [
        // Livewire's temporary-upload endpoint ships `throttle:60,1` from its own config
        // (`livewire.temporary_file_upload.middleware`), read at REQUEST time — so it COULD be named
        // with one `config()->set()` in a provider's boot (a published partial `config/livewire.php`
        // would blank the rest of that block, since package config merges shallowly, but that is not
        // the only door). It is allowlisted rather than named because, with every throttle of ours
        // named, it is the one unnamed counter left and shares with nothing this app sizes — a
        // decision, not a constraint. Name it the day a second unnamed vendor throttle appears.
        'Livewire\Features\SupportFileUploads\FileUploadController@handle',
    ];
}

it('finds the throttles it judges — a sweep over nothing would pass everything', function () {
    $counters = collect(throttleCountersInTheRouteTable())->pluck('counter')->filter()->unique();

    expect($counters->count())->toBeGreaterThanOrEqual(8,
        'Fewer named counters than this app declares — has the route table stopped loading, or did a group lose its throttle?');
});

it('names a counter on every throttle in the app', function () {
    $unnamed = collect(throttleCountersInTheRouteTable())
        ->reject(fn (array $throttle) => in_array($throttle['action'], vendorThrottlesNotOursToName(), true))
        ->whereNull('counter')
        ->pluck('route')
        ->unique()
        ->values()
        ->all();

    expect($unnamed)->toBe([],
        "These throttles name no counter, so each shares one per IP with every other unnamed throttle:\n  - "
        .implode("\n  - ", $unnamed)
        ."\n\nGive the middleware its third parameter — `throttle:5,1,api-login` — and add the name below.");
});

it('keeps one limit per counter — two ceilings on one counter each spend the other\'s', function () {
    $mixed = collect(throttleCountersInTheRouteTable())
        ->whereNotNull('counter')
        ->groupBy('counter')
        ->filter(fn ($throttles) => $throttles->pluck('limit')->unique()->count() > 1)
        ->map(fn ($throttles, $counter) => $counter.': '.$throttles->pluck('limit')->unique()->implode(' and '))
        ->values()
        ->all();

    expect($mixed)->toBe([], "These counters carry more than one limit:\n  - ".implode("\n  - ", $mixed));
});

it('holds exactly the counters this app has decided on — a new budget is a decision made here', function () {
    $counters = collect(throttleCountersInTheRouteTable())
        ->reject(fn (array $throttle) => in_array($throttle['action'], vendorThrottlesNotOursToName(), true))
        ->pluck('counter')
        ->filter()
        ->unique()
        ->sort()
        ->values()
        ->all();

    expect($counters)->toBe([
        'api-click',    // POST /api/v1/public/…/click — the one public WRITE
        'api-login',    // POST /api/v1/auth/login
        'api-me',       // every authenticated /api/v1 route, keyed on the person
        'api-password', // forgot + reset — one flow, one counter
        'api-public',   // the shopper feed's reads
        'health',       // GET /health
        'pay',          // GET /pay/{token}, /start, /status
        'pay-demo',     // POST /pay/{token}/demo — the one /pay route that writes money
    ]);
});

it('resolves the authenticated group\'s auth BEFORE its throttle — so `api-me` is keyed on the person, not the address', function () {
    // The `api-me` counter is per signed-in login only because `$request->user()` is already resolved
    // when the throttle reads it — and that rests on Laravel's middleware PRIORITY list sorting
    // `AuthenticatesRequests` ahead of `ThrottleRequests`, an upstream ordering nothing here declares.
    // Were it ever to move, `user()` would answer the default `web` guard on an API request — null —
    // and the counter would fall to the IP: sixty a minute for everybody behind the mall's Wi-Fi,
    // with no test going red. Pinned as a contract, the `FilamentActionDispatchContractTest` idiom.
    $route = Route::getRoutes()->getByName('api.v1.me.show');
    $stack = collect(app('router')->gatherRouteMiddleware($route))->filter(fn ($m) => is_string($m))->values();

    $auth = $stack->search(fn (string $m) => is_a(explode(':', $m, 2)[0], Authenticate::class, true));
    $throttle = $stack->search(fn (string $m) => is_a(explode(':', $m, 2)[0], ThrottleRequests::class, true));

    expect($auth)->not->toBeFalse('no auth middleware on /me')
        ->and($throttle)->not->toBeFalse('no throttle on /me')
        ->and($auth)->toBeLessThan($throttle, 'auth must resolve before the throttle reads $request->user()');
});

it('allowlists only vendor throttles that still exist — a stale entry is a guard that guards nothing', function () {
    $actions = collect(throttleCountersInTheRouteTable())->pluck('action')->unique();

    foreach (vendorThrottlesNotOursToName() as $action) {
        expect($actions->contains($action))->toBeTrue("{$action} no longer carries a throttle — drop it from the allowlist.");
    }
});
