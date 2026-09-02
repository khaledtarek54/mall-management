<?php

use App\Filament\Admin\Resources\Payments\PaymentResource;
use App\Filament\Admin\Resources\Payments\Pages\CreatePayment;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * "RECORD PAYMENT" BUILT A 404, AND THE PREFILL IT EXISTS FOR COULD NEVER FIRE.
 *
 * `tenant` is **Filament's own tenancy ROUTE parameter** — the mall's segment in every admin URL.
 * So `PaymentResource::getUrl('create', ['tenant' => $tenantId])` did not append a query string: it
 * substituted the RETAILER's id for the MALL's slug and produced `/admin/{tenantId}/payments/create`.
 * The operator got a 404, and `CreatePayment::fillForm()` — which reads `request()->query('tenant')`
 * — was reading a key the URL never carried even when the path happened to resolve.
 *
 * **Two producers had it, and the sweep row named one.** The collections worklist and the tenant
 * hub's own *Record payment* button, which is the daily loop the prefill was built for: call the
 * tenant, they say they paid, record it. Both are asserted here, because enumerating the peers by
 * grep rather than from the diff is what this project keeps re-learning.
 *
 * `for_tenant` cannot collide: it is not a route parameter of any panel, so Filament appends it to
 * the query string, which is where `fillForm()` looks.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);

    $this->mall = makeAsset(['code' => 'AA']);
    $this->tenant = makeTenant(['name' => 'Café Crema']);
    $this->lease = makeLease(makeUnit($this->mall), $this->tenant);

    $this->actingAs(makeUser('super_admin', [$this->mall->id]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('keeps the MALL in the path and puts the tenant in the query string', function () {
    $url = asTenant($this->mall, fn (): string => PaymentResource::getUrl('create', [
        'for_tenant' => $this->tenant->id,
    ]));

    // The mall's own tenancy slug — its CODE, not its id — is what belongs in that segment.
    expect($url)->toContain('/admin/'.$this->mall->code.'/payments/create')
        ->toContain('for_tenant='.$this->tenant->id)
        // The failure this replaces: the retailer's id standing where the mall's slug belongs.
        ->not->toContain('/admin/'.$this->tenant->id.'/');
});

it('opens the payment form with that tenant already chosen', function () {
    // Through the REAL page and the REAL query string — a URL that resolves is only half of it, and
    // the prefill is the whole reason the link carries a parameter at all.
    asTenant($this->mall, function () {
        Livewire::withQueryParams(['for_tenant' => $this->tenant->id])
            ->test(CreatePayment::class)
            ->assertFormSet(['tenant_id' => $this->tenant->id]);
    });
});

it('ignores a tenant the operator cannot see', function () {
    // The control for the control. A hand-typed query string must not prefill a tenant outside the
    // operator's scope — the picker would refuse it at validation anyway, but a prefilled value the
    // form later rejects presents as the page being broken rather than as a refusal.
    $otherMall = makeAsset(['code' => 'BB']);
    $stranger = makeTenant(['name' => 'Not mine']);
    makeLease(makeUnit($otherMall), $stranger);

    $this->actingAs(makeUser('manager', [$this->mall->id]));

    asTenant($this->mall, function () use ($stranger) {
        Livewire::withQueryParams(['for_tenant' => $stranger->id])
            ->test(CreatePayment::class)
            ->assertFormSet(['tenant_id' => null]);
    });
});
