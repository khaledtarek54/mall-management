<?php

/**
 * The refusal `back()` must land on a page that actually renders.
 *
 * `DomainRefusalIsNotAnErrorPageTest` proves the handler answers a DomainException with
 * 302 + a queued notification. It stops there — and the half it never followed is where
 * this broke: Livewire's `fetch` follows that 302 itself, and a same-origin redirect
 * keeps the request headers, so the page the operator is bounced back to is fetched with
 * `X-Livewire: ""` still on it.
 *
 * That header alone IS Livewire's `isLivewireRequest()`, so Filament's `originalRequest`
 * binding treats the page GET as a component update and asks
 * `PersistentMiddleware::makeFakeRequest()` for a request that does not exist — method
 * `''` against `/` — and `RouteCollection::match()` throws MethodNotAllowedHttpException,
 * which `getRouteFromRequest()` doesn't catch.
 *
 * So: try to create an invoice dated into a closed period, and instead of "that period is
 * closed" the operator gets a 405 error modal (reported 2026-07-31 on
 * `/admin/AW/invoices/create`). The guard, the handler and the notification all worked;
 * the redirect they issued is what 500'd. It hit every Livewire-raised refusal in both
 * panels, so it is fixed once, globally, in `IgnoreStrayLivewireHeader`.
 */

use App\Models\AccountingPeriod;
use App\Services\Accounting\FiscalCalendar;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
});

it('renders the page a refusal redirects back to, header and all', function () {
    $asset = makeAsset(['code' => 'AW']);

    $this->actingAs(makeUser('super_admin', [$asset->id]))
        ->withHeaders(['X-Livewire' => ''])   // what the browser re-sends when it follows back()
        ->get("/admin/{$asset->code}/invoices/create")
        ->assertSuccessful();
});

it('still refuses a closed period — the guard is untouched', function () {
    // The point of the fix is that the operator SEES this refusal, so pair it with proof
    // the refusal is still raised. A page that renders because nothing guards it would
    // pass the test above just as happily.
    app(FiscalCalendar::class)->ensureYear(2024);
    AccountingPeriod::forDate(Carbon::parse('2024-07-15'))->update(['status' => 'closed']);

    $lease = makeLease(makeUnit(makeAsset(['code' => 'AW2'])));

    expect(fn () => makeInvoice($lease, ['issue_date' => '2024-07-15']))
        ->toThrow(DomainException::class, '2024-07');
});

it('leaves a real Livewire update alone', function () {
    // The header is only meaningless on a safe-method app route. Stripping it from a
    // POST to /livewire/* would break every component in the app, so prove the guard is
    // narrow: a request that looks like a genuine update keeps its header.
    Route::middleware('web')->post('/livewire/__test_header', fn () => response()->json([
        'seen' => request()->hasHeader('X-Livewire'),
    ]));
    Route::middleware('web')->get('/__test_header', fn () => response()->json([
        'seen' => request()->hasHeader('X-Livewire'),
    ]));

    $this->withHeaders(['X-Livewire' => ''])
        ->postJson('/livewire/__test_header')
        ->assertJson(['seen' => true]);

    $this->withHeaders(['X-Livewire' => ''])
        ->getJson('/__test_header')
        ->assertJson(['seen' => false]);
});
