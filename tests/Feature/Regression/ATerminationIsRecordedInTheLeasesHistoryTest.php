<?php

declare(strict_types=1);

use App\Models\Charge;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\LeaseEvent;
use App\Services\LeaseTerminationService;
use App\Support\LeaseEventNarrative;
use Carbon\CarbonImmutable;

/**
 * THE LEASE'S OWN HISTORY MUST RECORD THAT IT ENDED.
 *
 * `lease_events` has carried a `termination` type since it shipped, and only two services ever
 * wrote one: `ExerciseLeaseOptionService` (a break option) and `SettleMoveOutService` (the final
 * account). The ordinary Terminate button wrote none — so a lease that ended and was never
 * settled, which is every lease with no deposit to return, has an append-only history showing its
 * extensions and its abatements and nothing at all about the day it ended.
 *
 * Measured on the demo books after terminating lease #3: the history read
 * `rent_modification · abatement · extension` and stopped, while the activity trail said only
 * "lease updated".
 *
 * The final account still records its OWN event and must — they are two acts. This one says the
 * tenancy ended on a date; that one says the account was struck and freezes the figures, and its
 * payload carries `settlement: true`, which is how a reader tells them apart.
 */
function terminableLease(): Lease
{
    $lease = Lease::factory()->create([
        'status' => 'active',
        'commencement_date' => CarbonImmutable::parse('2025-01-01'),
        'expiry_date' => CarbonImmutable::parse('2028-12-31'),
        'base_rent_monthly' => 50_000,
        'escalation_type' => 'none',
    ]);

    Charge::create([
        'lease_id' => $lease->id,
        'name' => 'Base Rent',
        'type' => 'base_rent',
        'amount' => 50_000,
        'currency' => 'EGP',
        'frequency' => 'monthly',
        'start_date' => $lease->commencement_date,
        'is_active' => true,
    ]);

    return $lease->fresh();
}

it('records a termination event carrying the reason the operator gave', function (): void {
    $lease = terminableLease();

    app(LeaseTerminationService::class)->terminate($lease, [
        'termination_date' => '2026-12-15',
        'reason' => 'the tenant closed the branch',
        'cancel_open_invoices' => true,
        'credit_unearned' => true,
    ]);

    $event = LeaseEvent::where('lease_id', $lease->id)->where('type', 'termination')->first();

    expect($event)->not->toBeNull()
        ->and($event->effective_date->toDateString())->toBe('2026-12-15')
        ->and($event->reason)->toBe('the tenant closed the branch');
});

it('keeps the contracted expiry, which the termination overwrites on the lease', function (): void {
    $lease = terminableLease();

    app(LeaseTerminationService::class)->terminate($lease, [
        'termination_date' => '2026-12-15',
        'reason' => 'early exit',
    ]);

    // The lease record now ends on the termination date — that is deliberate, it IS when the
    // tenancy ended — so the contracted end lives here, where a reader of the history can see how
    // much term was walked away from without reconstructing it from `term_months`.
    expect($lease->fresh()->expiry_date->toDateString())->toBe('2026-12-15')
        ->and(LeaseEvent::where('lease_id', $lease->id)->where('type', 'termination')->first()->payload['contracted_expiry'])
        ->toBe('2028-12-31');
});

it('names the documents it withdrew', function (): void {
    $lease = terminableLease();

    $future = Invoice::factory()->create([
        'lease_id' => $lease->id,
        'tenant_id' => $lease->tenant_id,
        'asset_id' => $lease->unit->asset_id,
        'status' => 'issued',
        'period_start' => CarbonImmutable::parse('2027-03-01'),
        'period_end' => CarbonImmutable::parse('2027-03-31'),
    ]);

    app(LeaseTerminationService::class)->terminate($lease, [
        'termination_date' => '2026-12-15',
        'reason' => 'early exit',
        'cancel_open_invoices' => true,
    ]);

    expect($future->fresh()->status)->toBe('cancelled');

    // The invoice is cancelled and the credit notes exist, but nothing else says they happened
    // BECAUSE of this termination.
    $payload = LeaseEvent::where('lease_id', $lease->id)->where('type', 'termination')->first()->payload;

    expect($payload['cancelled_invoices'])->toContain($future->number);
});

it('falls back to a worded reason rather than an empty one', function (): void {
    $lease = terminableLease();

    app(LeaseTerminationService::class)->terminate($lease, [
        'termination_date' => '2026-12-15',
        'reason' => '',
    ]);

    $event = LeaseEvent::where('lease_id', $lease->id)->where('type', 'termination')->first();

    // What the READER sees, not the stored column — since 2026-08-30 a service stamps a narrative
    // KEY rather than freezing a sentence, so `reason` is null on exactly this path and asserting
    // on it would be asserting on the floor rather than on the timeline.
    foreach (['en', 'ar'] as $locale) {
        $sentence = LeaseEventNarrative::resolve($event, $locale);

        expect($sentence)->not->toBeEmpty()
            // A key rendered raw is the failure this codebase names for every dynamic prefix.
            ->and($sentence)->not->toContain('admin.')
            ->and($sentence)->not->toContain(': ');
    }

    // …and the two really are different sentences, which is the whole point of the change.
    expect(LeaseEventNarrative::resolve($event, 'ar'))->toMatch('/\p{Arabic}/u');
});
