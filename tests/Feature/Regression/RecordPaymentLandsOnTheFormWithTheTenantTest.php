<?php

use App\Filament\Admin\Resources\Payments\PaymentResource;
use App\Filament\Admin\Resources\Payments\Pages\CreatePayment;
use Database\Seeders\RolesPermissionsSeeder;
use App\Filament\Admin\Pages\ArCollections;
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

it('never lets a producer use Filament s reserved tenancy key', function () {
    // The property that actually matters and can be checked cheaply and completely: NO producer of
    // a payment-create URL may pass `tenant` in the parameters, because Filament substitutes it into
    // the PATH where the mall's slug belongs. A source sweep, because the two call sites live on an
    // array-backed page and a relation manager, neither of which yields its action outside a mounted
    // Livewire component — and a sweep covers the THIRD producer somebody adds next.
    $offenders = [];

    foreach (\Symfony\Component\Finder\Finder::create()->files()->in(base_path('app'))->name('*.php') as $file) {
        // **CODE ONLY.** The first version swept raw source and reported `CreatePayment` — whose
        // docblock quotes the broken call as the description of the bug. A gate that fires on a
        // SENTENCE is one that gets weakened rather than fixed, which this project has already
        // recorded twice.
        $source = collect(token_get_all($file->getContents()))
            ->reject(fn ($token) => is_array($token)
                && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true))
            ->map(fn ($token) => is_array($token) ? $token[1] : $token)
            ->implode('');

        if (! str_contains($source, "PaymentResource::getUrl('create'")) {
            continue;
        }

        // The parameters array, windowed to the call rather than the file: another `'tenant' =>`
        // elsewhere in the same class is not this bug.
        foreach (explode("PaymentResource::getUrl('create'", $source) as $i => $chunk) {
            if ($i === 0) {
                continue;
            }

            if (preg_match("/^[^;]*'tenant'\s*=>/", $chunk)) {
                $offenders[] = str_replace(base_path().'/', '', $file->getPathname());
            }
        }
    }

    expect($offenders)->toBe([], implode(', ', $offenders).
        " pass Filament's reserved `tenant` key to PaymentResource::getUrl('create'), which puts the ".
        'tenant id in the PATH where the mall slug belongs — a 404. Use `for_tenant`.');
});
