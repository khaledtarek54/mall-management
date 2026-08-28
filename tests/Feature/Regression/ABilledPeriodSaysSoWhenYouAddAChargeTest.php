<?php

/*
|--------------------------------------------------------------------------
| Adding a charge into a billed period says so (2026-08-28)
|--------------------------------------------------------------------------
| Reported from the panel. A charge dated into an already-invoiced period is never raised: the
| billing run refuses to bill a month twice — correctly — so the schedule carries an amount no
| invoice will ever collect. Measured: a month billed at 44,000, a 14,000 service charge added into
| it, and the run answers "skipped". The money is simply lost, and nothing says a word.
|
| **Not refused, and that is the market standard rather than a compromise.** Back-dating a charge is
| a legitimate act — an omission found late — and the schedule row must start on the date it really
| applies from, or the record of what was agreed is wrong. Yardi does not block it either; it expects
| the shortfall to be collected on its own document.
|
| So the operator is told, at the moment they do it, which invoices already cover that period and
| what to do about it. A warning that persists, because a toast that fades is not a decision.
*/

use App\Filament\Admin\RelationManagers\ChargeScheduleRelationManager;
use App\Filament\Admin\Resources\Leases\Pages\EditLease;
use App\Models\Charge;
use App\Services\ChargeScheduleService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Livewire\Notifications;
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

    app(ChargeScheduleService::class)->setAmount(
        $this->lease, 'base_rent', 44000, CarbonImmutable::parse('2026-08-01'),
    );

    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/** August, invoiced. */
function billAugust($ctx): void
{
    makeInvoice($ctx->lease, [
        'asset_id' => $ctx->asset->id, 'status' => 'issued',
        'period_start' => '2026-08-01', 'period_end' => '2026-08-31',
        'subtotal' => 44000, 'vat_amount' => 0, 'total' => 44000,
        'paid_amount' => 0, 'balance' => 44000,
    ]);
}

/**
 * Add the charge and return the toast BODY.
 *
 * Read the way Filament's own `assertNotified()` reads it — by mounting the notifications component
 * — because `Notification::send()` pushes to the session and the component CLAIMS it from there. A
 * test reading `session('filament.notifications')` directly finds nothing and reports a screen that
 * sent no notification at all.
 */
function addChargeAt($ctx, string $from): string
{
    session()->forget(['filament.notifications', 'filament.claimed_notifications']);

    Livewire::test(ChargeScheduleRelationManager::class, [
        'ownerRecord' => $ctx->lease,
        'pageClass' => EditLease::class,
    ])->callTableAction('addCharge', data: [
        'type' => 'service_charge',
        'amount' => 14000,
        'frequency' => 'monthly',
        'effective_from' => $from,
    ]);

    $notifications = new Notifications;
    $notifications->mount();

    return collect($notifications->notifications)->map->toArray()
        ->pluck('body')->filter()->implode(' ');
}

it('warns when the period has already been invoiced', function () {
    billAugust($this);

    // The invoice is NAMED — "that period is billed" is not actionable without knowing which
    // document to look at.
    expect(addChargeAt($this, '2026-08-01'))->toContain('INV-');
});

it('still ADDS the charge — it is a warning, not a refusal', function () {
    billAugust($this);

    addChargeAt($this, '2026-08-01');

    // Back-dating is legitimate. The schedule must record what was agreed, from when it applies.
    $charge = Charge::where('lease_id', $this->lease->id)->where('type', 'service_charge')->first();

    expect($charge)->not->toBeNull()
        ->and($charge->start_date->format('Y-m-d'))->toBe('2026-08-01');
});

it('says nothing when the period is still open', function () {
    // The control, and the one that keeps the warning meaningful: a warning shown on every add is
    // trained away before the add that matters.
    billAugust($this);

    expect(addChargeAt($this, '2026-09-01'))->not->toContain('INV-');
});

it('ignores a CANCELLED invoice — that period still needs billing', function () {
    makeInvoice($this->lease, [
        'asset_id' => $this->asset->id, 'status' => 'cancelled',
        'period_start' => '2026-08-01', 'period_end' => '2026-08-31',
        'subtotal' => 44000, 'vat_amount' => 0, 'total' => 44000,
        'paid_amount' => 0, 'balance' => 0,
    ]);

    expect(addChargeAt($this, '2026-08-01'))->not->toContain('INV-');
});
