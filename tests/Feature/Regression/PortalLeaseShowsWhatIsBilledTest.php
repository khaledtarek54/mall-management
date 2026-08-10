<?php

use App\Filament\Portal\Resources\Leases\Pages\ViewLease;
use App\Models\Lease;
use App\Models\RentableItem;
use App\Services\AssignRentableItemService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * The tenant's own portal must describe the deal the tenant actually signed.
 *
 * Two things it did not. The escalation row was gated on `escalation_rate > 0`, which is ZERO on a
 * fixed-AMOUNT lease — so a tenant whose rent steps by EGP 5,000 every year was shown nothing at all
 * about it: their own contract, invisible on their own portal. And a lease holding parking bays put
 * a "Parking & rentable items" line on the invoice with no way to check WHICH bays or at what rate,
 * which is the most common billing query there is.
 *
 * These render the real page and assert what a tenant would READ, rather than re-asserting the
 * predicate the infolist uses — a test that copies the source's own condition passes whether or not
 * the screen still honours it.
 */
beforeEach(fn () => Filament::setCurrentPanel(Filament::getPanel('portal')));
afterEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    CarbonImmutable::setTestNow();
});

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->tenant = makeTenant(['name' => 'Cafe Crema']);
});

function billedLease(array $attributes = []): Lease
{
    return makeLease(makeUnit(test()->asset), test()->tenant, array_merge([
        'status' => 'active',
        'commencement_date' => '2026-01-01',
        'expiry_date' => '2029-12-31',
        'base_rent_monthly' => 100000,
        'next_escalation_date' => '2027-01-01',
    ], $attributes))->fresh();
}

function viewAsTenant(Lease $lease): \Livewire\Features\SupportTesting\Testable
{
    test()->actingAs(makeTenantUser(test()->tenant), 'portal');

    return Livewire::test(ViewLease::class, ['record' => $lease->getKey()]);
}

it('tells a fixed-amount tenant their rent steps, and by how much', function () {
    // The defect: `escalation_rate` is 0 on an amount lease, so the row was hidden outright and the
    // tenant was never told about an increase they had signed up to.
    $lease = billedLease([
        'escalation_type' => 'fixed_amount',
        'escalation_amount' => 5000,
        'escalation_rate' => 0,
    ]);

    viewAsTenant($lease)
        ->assertOk()
        ->assertSee('5,000.00 EGP');
});

it('shows a collared tenant the cap they negotiated', function () {
    $lease = billedLease([
        'escalation_type' => 'fixed_percent',
        'escalation_rate' => 7,
        'escalation_floor_rate' => 3,
        'escalation_ceiling_rate' => 10,
    ]);

    viewAsTenant($lease)
        ->assertOk()
        ->assertSee('7%')
        // The cap is worth more to a tenant than the headline rate.
        ->assertSee('max 10%');
});

it('says nothing about escalation on a lease that has none', function () {
    // The control — the fix must not turn "no escalation" into a row reading zero.
    $lease = billedLease(['escalation_type' => 'none', 'escalation_rate' => 0]);

    viewAsTenant($lease)
        ->assertOk()
        ->assertDontSee(__('admin.portal.lease.escalation'));
});

it('shows the parking a tenant is actually paying for, at the negotiated rate', function () {
    CarbonImmutable::setTestNow('2026-03-05');
    $lease = billedLease();

    $bay = RentableItem::create([
        'asset_id' => $this->asset->id,
        'code' => 'P-001',
        'type' => RentableItem::TYPE_PARKING,
        'monthly_rate' => 900,
    ]);

    app(AssignRentableItemService::class)->assign($lease, $bay, [
        'effective_from' => '2026-03-01',
        'monthly_rate' => 650,
    ]);

    viewAsTenant($lease->fresh())
        ->assertOk()
        ->assertSee('P-001')
        // The NEGOTIATED rate — what reconciles with the parking line on their invoice — not the
        // bay's asking rate of 900.
        ->assertSee('650');
});

it('drops a released bay off the portal', function () {
    CarbonImmutable::setTestNow('2026-03-05');
    $lease = billedLease();
    $bay = RentableItem::create([
        'asset_id' => $this->asset->id,
        'code' => 'P-002',
        'type' => RentableItem::TYPE_PARKING,
        'monthly_rate' => 900,
    ]);

    $service = app(AssignRentableItemService::class);
    $service->assign($lease, $bay, ['effective_from' => '2026-03-01']);
    $service->release($lease->fresh(), $bay->fresh(), '2026-03-31');

    viewAsTenant($lease->fresh())
        ->assertOk()
        ->assertDontSee('P-002');
});

it('shows a rent-free tenant when their rent actually starts', function () {
    $lease = billedLease(['rent_commencement_date' => '2026-04-01']);

    viewAsTenant($lease)
        ->assertOk()
        ->assertSee('01/04/2026');
});
