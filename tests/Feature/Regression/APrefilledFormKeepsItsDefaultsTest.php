<?php

use App\Filament\Admin\Resources\Payments\Pages\CreatePayment;
use App\Filament\Admin\Resources\TenantSalesDeclarations\Pages\CreateTenantSalesDeclaration;
use App\Filament\Admin\Resources\Violations\Pages\CreateViolation;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * A create form reached through a prefill link keeps every default it declares.
 *
 * `$form->fill($data)` REPLACES the state rather than merging into the defaults, so a page that
 * overrides `fillForm()` to carry a tenant or a lease across silently loses everything else the
 * schema sets. Reported from the panel: the violation form opened from the tenant 360 showed an
 * EMPTY property picker, on a panel where every other form shows it pinned and disabled — nothing
 * was wrong with `PropertyField`, the prefill had erased its value.
 *
 * That is the worst shape of quiet: a blank required field reads as a form still loading, and the
 * operator's instinct is to fill it in — so a prefill silently turned a PINNED, guarded field into
 * a free one. All three prefilling pages had it.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();

    $this->asset = makeAsset();
    $this->lease = makeLease(makeUnit($this->asset), makeTenant(), ['status' => 'active']);
    $this->tenant = $this->lease->tenant;

    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    // Frozen, because several of these defaults ARE `now()` — the two renders being compared are
    // a second apart otherwise and the test fails on the clock rather than on a lost default.
    $this->freezeTime();
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/** The raw form state of a create page, opened with the given query string. */
function createState(string $page, array $query, $asset): array
{
    return asTenant($asset, function () use ($page, $query) {
        Livewire::withQueryParams($query);

        return Livewire::test($page)->instance()->form->getRawState();
    });
}

it('keeps the pinned property when a violation form is opened from a tenant', function () {
    $plain = createState(CreateViolation::class, [], $this->asset);
    $prefilled = createState(CreateViolation::class, ['for_tenant' => $this->tenant->getKey()], $this->asset);

    // The premise: opened plainly, the property IS pinned. Without this the assertion below could
    // pass on a form that never had a property to lose.
    expect($plain['asset_id'] ?? null)->not->toBeNull();

    expect($prefilled['asset_id'] ?? null)->toBe($plain['asset_id'])
        ->and((int) ($prefilled['tenant_id'] ?? 0))->toBe($this->tenant->getKey());
});

it('keeps every other default too, not just the property', function () {
    $plain = createState(CreateViolation::class, [], $this->asset);
    $prefilled = createState(CreateViolation::class, ['for_tenant' => $this->tenant->getKey()], $this->asset);

    // `status` defaults to open and `violation_date` to today. A fix that special-cased the
    // property would leave these behind — which is why the seam restores the whole default state
    // rather than the one field somebody reported.
    foreach (['status', 'violation_date'] as $field) {
        expect($prefilled[$field] ?? null)->toBe($plain[$field] ?? null, "the prefill dropped `{$field}`");
    }
});

it('keeps the defaults on the two pages that had this before today', function () {
    // Enumerated by grepping for the pattern, not from the change that exposed it: `CreatePayment`
    // and `CreateTenantSalesDeclaration` both predate the violation tab and both fill an explicit
    // array. A payment reached from the collections worklist was losing its own defaults.
    $plainPay = createState(CreatePayment::class, [], $this->asset);
    $prefilledPay = createState(CreatePayment::class, ['for_tenant' => $this->tenant->getKey()], $this->asset);

    expect((int) ($prefilledPay['tenant_id'] ?? 0))->toBe($this->tenant->getKey());

    // A repeater keys its rows by a fresh UUID each render, so compare what is IN the rows, never
    // the keys — otherwise this fails on two identical empty allocation rows.
    $comparable = fn ($value) => is_array($value) ? array_values($value) : $value;

    foreach (array_keys($plainPay) as $field) {
        if (($plainPay[$field] ?? null) !== null && $field !== 'tenant_id') {
            expect($comparable($prefilledPay[$field] ?? null))
                ->toEqual($comparable($plainPay[$field]), "CreatePayment lost `{$field}`");
        }
    }

    $plainSales = createState(CreateTenantSalesDeclaration::class, [], $this->asset);
    $prefilledSales = createState(CreateTenantSalesDeclaration::class, ['lease' => $this->lease->getKey()], $this->asset);

    expect((int) ($prefilledSales['lease_id'] ?? 0))->toBe($this->lease->getKey());

    foreach (array_keys($plainSales) as $field) {
        if (($plainSales[$field] ?? null) !== null && $field !== 'lease_id') {
            expect($comparable($prefilledSales[$field] ?? null))
                ->toEqual($comparable($plainSales[$field]), "CreateTenantSalesDeclaration lost `{$field}`");
        }
    }
});
