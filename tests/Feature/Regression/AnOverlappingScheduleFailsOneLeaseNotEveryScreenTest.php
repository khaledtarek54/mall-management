<?php

/*
|--------------------------------------------------------------------------
| One broken schedule fails ONE lease, not four screens (SW-052)
|--------------------------------------------------------------------------
| `assertScheduleUnambiguous()` threw straight out of `planInvoiceForLease()`, and its docblock
| said the damage was contained because "`runForPeriod()` catches per lease". That was true of the
| two WRITE paths and of nothing else. Every READ caller loops the planner with no catch:
|
|   - `previewForPeriod()`               → the Billing run preview, and Month-end close readiness
|   - `LeaseBillingForecastService`      → the lease's own Billing forecast tab
|   - `PortfolioRevenueForecastService`  → the portfolio Revenue forecast
|
| So one lease with two live rent rows took all four down for the whole property — including the
| forecast tab an operator would open to diagnose that very lease. And because it is a
| `DomainException` it renders as a toast and a redirect, not an error page, so it reads as "the
| preview is broken" rather than "lease L-0042 has two rent rows".
|
| The fix is at the seam, not in a catch per screen: the plan ANSWERS `schedule_conflict`, and
| `generateInvoiceForLease()` re-throws it so the write is exactly as loud as it was. The last two
| cases are the ones that matter most — a fix that made the planner merely quiet would silently
| turn a double-billing hazard into an ordinary `skipped` lease nobody looks at.
*/

use App\Filament\Admin\Pages\BillingRunPreview;
use App\Models\Charge;
use App\Models\Lease;
use App\Services\LeaseBillingForecastService;
use App\Services\MonthlyBillingService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    // The preview page defaults to the current month, so the fixture month is the current month.
    $this->travelTo(CarbonImmutable::parse('2026-06-15')->setTime(9, 0));
    $this->asset = makeAsset();
    $this->june = CarbonImmutable::parse('2026-06-01');
});

/** A healthy lease: exactly one rent row and one service row. */
function leaseWithOneRentRow($asset, float $rent = 20000, float $service = 5000): Lease
{
    $lease = makeLease(makeUnit($asset), null, [
        'commencement_date' => '2026-01-01',
        'expiry_date' => '2028-12-31',
        'base_rent_monthly' => $rent,
        'service_charge_monthly' => $service,
    ]);

    Charge::create([
        'lease_id' => $lease->id, 'name' => 'Base Rent', 'type' => 'base_rent',
        'amount' => $rent, 'frequency' => 'monthly',
        'vat_applicable' => false, 'vat_rate' => 0, 'is_active' => true,
    ]);
    Charge::create([
        'lease_id' => $lease->id, 'name' => 'Service Charge', 'type' => 'service_charge',
        'amount' => $service, 'frequency' => 'monthly',
        'vat_applicable' => true, 'vat_rate' => 14, 'is_active' => true,
    ]);

    return $lease;
}

/**
 * A SECOND open-ended base-rent row — the overlap.
 *
 * Deliberately NOT through `Charge::create()`: `Charge::booted()` refuses this shape at the
 * keystroke, which is exactly why the billing planner is the last line and exactly how such rows
 * still arrive — a legacy row, a bad import, a hand-edited date.
 */
function duplicateRentRowOn(Lease $lease, float $amount = 20000): void
{
    DB::table('charges')->insert([
        'lease_id' => $lease->id, 'name' => 'Base Rent (duplicate)', 'type' => 'base_rent',
        'amount' => $amount, 'currency' => 'EGP', 'frequency' => 'monthly',
        'vat_applicable' => false, 'vat_rate' => 0,
        'start_date' => null, 'end_date' => null, 'is_active' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('previews the whole property, naming the broken lease as one row', function () {
    $broken = leaseWithOneRentRow($this->asset);
    duplicateRentRowOn($broken);
    $healthy = leaseWithOneRentRow($this->asset, rent: 31000, service: 7500);

    $preview = app(MonthlyBillingService::class)->previewForPeriod($this->june, $this->asset->id);

    $rows = collect($preview['rows'])->keyBy('lease_id');

    expect($rows[$broken->id]['billable'])->toBeFalse()
        ->and($rows[$broken->id]['reason'])->toBe('schedule_conflict')
        // CONTROL — the lease beside it is untouched. Without this the assertion above passes on a
        // "fix" that quietly made every lease unbillable.
        ->and($rows[$healthy->id]['billable'])->toBeTrue()
        ->and($rows[$healthy->id]['subtotal'])->toBe(38500.0)
        ->and($preview['totals']['will_bill'])->toBe(1)
        ->and($preview['totals']['skipped'])->toBe(1);
});

it('still draws the forecast for the broken lease itself', function () {
    // The tooth that proves the SEAM rather than a catch bolted onto the preview: nothing anywhere
    // in the forecast path catches, so this can only pass if the planner stopped throwing. It is
    // also the screen an operator opens to work out what is wrong with this lease.
    $broken = leaseWithOneRentRow($this->asset);
    duplicateRentRowOn($broken);

    $forecast = app(LeaseBillingForecastService::class)->forecast($broken, $this->june, 1);

    expect($forecast['rows'])->toHaveCount(1)
        ->and($forecast['rows'][0]['billable'])->toBeFalse()
        ->and($forecast['rows'][0]['reason'])->toBe('schedule_conflict');
});

it('opens the Billing run preview page with a broken lease in the property', function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));

    $broken = leaseWithOneRentRow($this->asset);
    duplicateRentRowOn($broken);
    leaseWithOneRentRow($this->asset, rent: 31000, service: 7500);

    Filament::setTenant($this->asset);

    // The reported symptom, through the real screen: before this the page could not be opened at
    // all — a toast and a redirect, with no way to reach the 400 leases that were fine.
    Livewire::test(BillingRunPreview::class)
        ->assertOk()
        ->assertCountTableRecords(2);
});

it('the RUN still refuses the broken lease loudly, and bills the healthy one', function () {
    // The safety property. A plan that merely goes quiet would turn a double-billing hazard into an
    // ordinary `skipped` lease with nothing in the ops log — worse than the page that died.
    $broken = leaseWithOneRentRow($this->asset);
    duplicateRentRowOn($broken);
    $healthy = leaseWithOneRentRow($this->asset, rent: 31000, service: 7500);

    $stats = app(MonthlyBillingService::class)->runForPeriod($this->june, $this->asset->id);

    expect($stats['failed'])->toBe(1)
        ->and($stats['failed_lease_ids'])->toBe([$broken->id])
        ->and($stats['created'])->toBe(1)
        ->and($broken->invoices()->count())->toBe(0)
        ->and($healthy->invoices()->count())->toBe(1);
});

it('the single-lease Generate Invoice path still refuses it too', function () {
    $broken = leaseWithOneRentRow($this->asset);
    duplicateRentRowOn($broken);

    $result = app(MonthlyBillingService::class)->generateForLease($broken, $this->june);

    expect($result['status'])->toBe('failed')
        ->and($broken->invoices()->count())->toBe(0);
});
