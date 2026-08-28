<?php

/*
|--------------------------------------------------------------------------
| A manual invoice prefills ONE month, not the whole term (2026-08-28)
|--------------------------------------------------------------------------
| Found while exercising late fees. The invoice form's prefill filtered the lease's charges on
| `is_active` and frequency — and on nothing about WHEN. A lease carries one charge row per
| escalation step, and every one of them is active and monthly, so picking the lease on a manual
| invoice pulled ALL of them onto one document.
|
| Measured: a lease with three annual steps (44,000 · 47,080 · 50,375.60, each with its marketing
| levy) produced a two-month invoice of **148,528.38** — three years of rent on one page. The
| late-fee run then charged 2% of that figure.
|
| The billing engine has always billed one amount per type per month. This asks the same question
| through the same resolver — `ChargeScheduleService::rowInForce()` — so the form and the run cannot
| answer differently.
*/

use App\Filament\Admin\Resources\Invoices\Pages\CreateInvoice;
use App\Services\ChargeScheduleService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    ensureAllPropertiesAsset();
    $this->seed(RolesPermissionsSeeder::class);

    $this->asset = makeAsset();
    $this->lease = makeLease(makeUnit($this->asset, ['area_sqm' => 110]), makeTenant(), [
        'status' => 'active',
        'commencement_date' => '2026-08-01',
        'expiry_date' => '2029-07-31',
    ]);

    // A three-step ladder — exactly what a lease with an annual escalation carries.
    $schedule = app(ChargeScheduleService::class);
    $schedule->setAmount($this->lease, 'base_rent', 44000, CarbonImmutable::parse('2026-08-01'));
    $schedule->setAmount($this->lease, 'base_rent', 47080, CarbonImmutable::parse('2027-08-01'));
    $schedule->setAmount($this->lease, 'base_rent', 50375.60, CarbonImmutable::parse('2028-08-01'));

    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/** The items the form prefills when the lease is picked for a given period. */
function prefilledItems($ctx, string $periodStart): array
{
    return Livewire::test(CreateInvoice::class)
        ->set('data.period_start', $periodStart)
        ->set('data.issue_date', $periodStart)
        ->set('data.lease_id', $ctx->lease->id)
        ->get('data')['items'] ?? [];
}

it('prefills the step in force, not every step in the ladder', function () {
    $items = collect(prefilledItems($this, '2026-08-01'))->where('type', 'base_rent');

    expect($items)->toHaveCount(1)
        ->and(round((float) $items->first()['amount'], 2))->toBe(44000.0);
});

it('prefills the step in force for a LATER period', function () {
    // The assertion that proves it is reading the schedule rather than just taking the first row.
    $items = collect(prefilledItems($this, '2027-09-01'))->where('type', 'base_rent');

    expect($items)->toHaveCount(1)
        ->and(round((float) $items->first()['amount'], 2))->toBe(47080.0);
});

it('still prefills something', function () {
    // The control: a filter that matched nothing would satisfy both tests above and leave the
    // operator typing every line by hand.
    expect(prefilledItems($this, '2026-08-01'))->not->toBeEmpty();
});
