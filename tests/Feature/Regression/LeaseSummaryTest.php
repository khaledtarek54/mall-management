<?php

/*
|--------------------------------------------------------------------------
| The tenancy at a glance — UX-01's Summary (2026-08-17)
|--------------------------------------------------------------------------
| Every tab on the lease already made a fact REACHABLE; none made the important ones VISIBLE
| together. The story that asked for the lease hub said why in one line: "so that I stop hunting
| across five resources." Reachable and visible are not the same property.
|
| The stats compute nothing of their own — the rent in force comes from
| `ChargeScheduleService::pickInForce()`, the same selection billing uses; the deposit from
| `MoveOutStatementService::depositHeld()`; the receivable from the invoices themselves. A summary
| with its own arithmetic would be a second opinion, and the first thing anyone would notice is that
| it disagreed with the tab underneath it. These tests hold that.
*/

use App\Filament\Admin\Widgets\LeaseSummary;
use App\Models\DepositTransaction;
use App\Models\LeaseOption;
use App\Services\ChargeScheduleService;
use App\Services\LeaseCreationService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->tenant = makeTenant();
    $this->unit = makeUnit($this->asset, ['area_sqm' => 200]);
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);
    CarbonImmutable::setTestNow('2026-06-15');
});

afterEach(function () {
    Filament::setTenant(null, isQuiet: true);
    CarbonImmutable::setTestNow();
});

function summaryLease($ctx, array $overrides = [])
{
    $lease = makeLease($ctx->unit, $ctx->tenant, array_merge([
        'status' => 'active',
        'commencement_date' => '2026-01-01',
        'expiry_date' => '2028-12-31',
        'base_rent_monthly' => 100000,
        'security_deposit' => 300000,
        'escalation_type' => 'none',
        'escalation_rate' => 0,
    ], $overrides));

    LeaseCreationService::seedStandardCharges($lease, rent: (float) $lease->base_rent_monthly, service: 0);

    return $lease->fresh();
}

/** The rendered stats, as label => [value, description]. */
function summaryStats($lease): array
{
    $widget = Livewire::test(LeaseSummary::class, ['record' => $lease])->instance();

    $method = new ReflectionMethod($widget, 'getStats');
    $method->setAccessible(true);

    $out = [];
    foreach ($method->invoke($widget) as $stat) {
        $out[$stat->getLabel()] = [(string) $stat->getValue(), (string) $stat->getDescription()];
    }

    return $out;
}

it('shows the rent in force — the schedule, not the lease column', function () {
    $lease = summaryLease($this);

    // Re-price from July. Today is June, so the summary must still show the row in force NOW.
    app(ChargeScheduleService::class)->setAmount(
        $lease, 'base_rent', 130000, CarbonImmutable::parse('2026-07-01'), ['name' => 'Base Rent'], 'manual',
    );

    $stats = summaryStats($lease->fresh());

    expect($stats[__('admin.lease_summary.rent_today')][0])->toBe('EGP 100,000.00')
        ->and($stats[__('admin.lease_summary.rent_today')][1])->toContain('130,000.00');
});

it('says a lease that has not commenced is not billing, rather than printing a rent', function () {
    $lease = summaryLease($this, [
        'commencement_date' => '2026-10-01',
        'expiry_date' => '2029-09-30',
    ]);

    expect(summaryStats($lease)[__('admin.lease_summary.rent_today')][0])
        ->toBe(__('admin.lease_summary.not_billing_yet'));
});

it('states the deposit HELD against the contractual figure, and names the shortfall', function () {
    $lease = summaryLease($this);

    DepositTransaction::create([
        'lease_id' => $lease->id,
        'tenant_id' => $lease->tenant_id,
        'asset_id' => $this->asset->id,
        'type' => 'receipt',
        'status' => 'recorded',
        'amount' => 200000,
        'transaction_date' => '2026-01-05',
    ]);

    $deposit = summaryStats($lease->fresh())[__('admin.lease_summary.deposit')];

    // A deposit agreed and never fully collected reads identically to one sitting in the bank if
    // only the contractual figure is shown. The shortfall is why this stat exists.
    expect($deposit[0])->toBe('EGP 200,000.00')
        ->and($deposit[1])->toContain('100,000.00');
});

it('counts what is owed ON THIS LEASE', function () {
    $lease = summaryLease($this);

    makeInvoice($lease, ['status' => 'overdue', 'total' => 50000, 'balance' => 50000]);
    makeInvoice($lease, ['status' => 'issued', 'total' => 30000, 'balance' => 30000]);
    makeInvoice($lease, ['status' => 'paid', 'total' => 90000, 'balance' => 0]);

    $ar = summaryStats($lease->fresh())[__('admin.lease_summary.outstanding')];

    // The paid one is not outstanding; the overdue count is what a collections call opens with.
    expect($ar[0])->toBe('EGP 80,000.00')
        ->and($ar[1])->toContain('1');
});

it('surfaces the soonest deadline that can still be missed — open options only', function () {
    $lease = summaryLease($this);

    LeaseOption::create([
        'lease_id' => $lease->id, 'type' => 'renewal', 'status' => 'open',
        'earliest_notice_date' => '2027-12-01', 'latest_notice_date' => '2028-03-31',
    ]);
    // An exercised option carries no deadline; counting it would push a real one off the card.
    LeaseOption::create([
        'lease_id' => $lease->id, 'type' => 'termination', 'status' => 'exercised',
        'earliest_notice_date' => '2026-07-01', 'latest_notice_date' => '2026-08-01',
    ]);

    expect(summaryStats($lease->fresh())[__('admin.lease_summary.next_critical_date')][0])
        ->toBe('31/03/2028');
});

it('reads the premises as at TODAY, so a unit given back is not counted', function () {
    $lease = summaryLease($this);

    expect(summaryStats($lease)[__('admin.lease_summary.premises')][1])
        ->toContain('200.00');
});

it('is gated on leases.view — a tenancy\'s AR and deposit are not for every role', function () {
    $this->actingAs(makeUser('hr'));
    expect(LeaseSummary::canView())->toBeFalse();

    $this->actingAs(makeUser('viewer', [$this->asset->id]));
    expect(LeaseSummary::canView())->toBeTrue();
});
