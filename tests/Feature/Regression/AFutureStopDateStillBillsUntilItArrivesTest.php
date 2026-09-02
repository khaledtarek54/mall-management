<?php

use App\Models\Charge;
use App\Services\ChargeScheduleService;
use App\Services\MonthlyBillingService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * Ending a charge from a FUTURE date must not stop billing today.
 *
 * `ChargeScheduleService::close()` stamped `end_date` — the operator's own date, recorded correctly
 * — and set `is_active = false` in the same breath. `MonthlyBillingService` selects on
 * `is_active`, so the row left the billing plan **immediately**: ending a charge from 1 December
 * silently stopped invoicing it in September, and the intervening months were never billed. Nothing
 * on any screen said so; the schedule showed the right end date the whole time.
 *
 * `end_date` alone is enough, because the planner already refuses a row whose `end_date` falls
 * before the period being billed. The flag is for a stop that has already ARRIVED, where leaving the
 * row active would offer a dead schedule in every picker.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);

    $this->asset = makeAsset();
    $this->lease = makeLease(makeUnit($this->asset), makeTenant(), [
        'status' => 'active',
        'base_rent_monthly' => 30000,
    ]);

    $this->charge = Charge::create([
        'lease_id' => $this->lease->id,
        'name' => 'Signage licence',
        'type' => 'other',
        'origin' => Charge::ORIGIN_SEED,
        'amount' => 5000,
        'currency' => 'EGP',
        'frequency' => 'monthly',
        'start_date' => CarbonImmutable::now()->subMonths(6)->startOfMonth()->toDateString(),
        'is_active' => true,
    ]);
});

it('keeps billing a charge ended from a future date, until that date', function () {
    $stopsOn = CarbonImmutable::now()->addMonths(3)->startOfMonth();

    app(ChargeScheduleService::class)->close($this->lease->fresh(), 'other', $stopsOn);

    $charge = $this->charge->fresh();

    // The operator's date is recorded…
    expect($charge->end_date)->not->toBeNull()
        // …and the row is still in the billing plan, which is the half that was wrong.
        ->and($charge->is_active)->toBeTrue();

    // Proven where it matters: this month still plans the line.
    $plan = app(MonthlyBillingService::class)->planInvoiceForLease(
        $this->lease->fresh(),
        CarbonImmutable::now()->startOfMonth(),
        CarbonImmutable::now()->endOfMonth(),
    );

    expect(collect($plan['items'] ?? $plan)->pluck('type'))->toContain('other');
});

it('deactivates a charge whose stop date has already arrived — the control', function () {
    // Without this, never deactivating would satisfy the test above while leaving dead schedules in
    // every picker.
    app(ChargeScheduleService::class)->close(
        $this->lease->fresh(),
        'other',
        CarbonImmutable::now()->startOfMonth(),
    );

    expect($this->charge->fresh()->is_active)->toBeFalse();
});
