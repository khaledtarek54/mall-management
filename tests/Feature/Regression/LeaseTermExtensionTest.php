<?php

/*
|--------------------------------------------------------------------------
| Extending a term is an act, not a typed date (2026-08-17)
|--------------------------------------------------------------------------
| `expiry_date` and `term_months` were free text on the lease form, so a further term happened by
| typing a date: no reason, no actor, no event, and nothing downstream able to tell an extension from
| a correction. `LeaseEvent::TYPE_EXTENSION` had been declared and never written by anything — the
| same shape `relocation` still has.
|
| The distinction the service exists to keep: an EXTENSION leaves the same contract running longer on
| the same terms; a RENEWAL ends this tenancy and starts a new lease with its own reference and its
| own negotiated terms. Modelling one as the other loses which happened.
|
| And pulling an expiry BACKWARDS is neither — that ends a tenancy early, which is a termination, and
| a termination settles the deposit, credits unearned billing and closes the charge schedule. Doing
| it here would do none of that and would record it as an extension.
*/

use App\Models\Lease;
use App\Models\LeaseEvent;
use App\Services\ChargeScheduleService;
use App\Services\LeaseCreationService;
use App\Services\LeaseExtensionService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->tenant = makeTenant();
    $this->unit = makeUnit($this->asset);
    $this->svc = app(LeaseExtensionService::class);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function extendableLease($ctx, array $overrides = []): Lease
{
    return makeLease($ctx->unit, $ctx->tenant, array_merge([
        'status' => 'active',
        'commencement_date' => '2026-01-01',
        'expiry_date' => '2028-12-31',
        'term_months' => 36,
        'base_rent_monthly' => 100000,
        'escalation_type' => 'none',
        'escalation_rate' => 0,
    ], $overrides));
}

it('moves the expiry and re-derives the term', function () {
    $lease = extendableLease($this);

    $extended = $this->svc->extend($lease, [
        'new_expiry_date' => '2030-12-31',
        'reason' => 'Extension option exercised',
    ]);

    expect($extended->expiry_date->format('Y-m-d'))->toBe('2030-12-31')
        ->and((int) $extended->term_months)->toBe(60);
});

it('records the extension as an event — which nothing had ever written', function () {
    $lease = extendableLease($this);

    $this->svc->extend($lease, [
        'new_expiry_date' => '2030-12-31',
        'reason' => 'Further term agreed pending renewal negotiation',
        'document_reference' => 'ADD-2026-11',
    ]);

    $event = LeaseEvent::where('lease_id', $lease->id)->latest('id')->first();

    expect($event->type)->toBe(LeaseEvent::TYPE_EXTENSION)
        // The further term begins the day after the old one ended.
        ->and($event->effective_date->format('Y-m-d'))->toBe('2029-01-01')
        ->and($event->reason)->toContain('Further term')
        ->and($event->document_reference)->toBe('ADD-2026-11')
        ->and($event->payload['previous_expiry_date'])->toBe('2028-12-31')
        ->and($event->payload['new_expiry_date'])->toBe('2030-12-31');
});

it('refuses to pull an expiry backwards — that is a termination, and it settles money', function () {
    $lease = extendableLease($this);

    expect(fn () => $this->svc->extend($lease, [
        'new_expiry_date' => '2027-06-30',
        'reason' => 'Tenant leaving early',
    ]))->toThrow(DomainException::class);

    expect($lease->fresh()->expiry_date->format('Y-m-d'))->toBe('2028-12-31');
});

it('refuses on a lease that is not running', function () {
    $lease = extendableLease($this, ['status' => 'draft']);

    expect(fn () => $this->svc->extend($lease, [
        'new_expiry_date' => '2030-12-31',
        'reason' => 'Not yet a tenancy',
    ]))->toThrow(DomainException::class);
});

it('projects the escalation ladder across the YEARS THE TERM GAINED', function () {
    $lease = extendableLease($this, [
        'escalation_type' => 'fixed_percent',
        'escalation_rate' => 10,
    ]);

    LeaseCreationService::seedStandardCharges($lease, rent: 100000, service: 0);
    app(ChargeScheduleService::class)->projectTermEscalations($lease->fresh());

    $before = $lease->charges()->where('type', 'base_rent')->count();

    $this->svc->extend($lease->fresh(), [
        'new_expiry_date' => '2030-12-31',
        'reason' => 'Extension option exercised',
    ]);

    $rows = $lease->charges()
        ->where('type', 'base_rent')
        ->orderBy('start_date')
        ->get()
        ->map(fn ($c) => $c->start_date->format('Y-m-d').' @ '.number_format((float) $c->amount, 2))
        ->all();

    // A lease must not run two more years with its future rent recorded nowhere.
    expect(count($rows))->toBeGreaterThan($before)
        ->and($rows)->toContain('2029-01-01 @ 133,100.00')
        ->and($rows)->toContain('2030-01-01 @ 146,410.00');
});

it('does not re-date the rent already in force — an extension bills the same rent, longer', function () {
    $lease = extendableLease($this);

    LeaseCreationService::seedStandardCharges($lease, rent: 100000, service: 0);
    $opening = $lease->charges()->where('type', 'base_rent')->first();

    $this->svc->extend($lease->fresh(), [
        'new_expiry_date' => '2030-12-31',
        'reason' => 'Further term',
    ]);

    // Open-ended rows simply keep billing; re-dating them would be the bug.
    expect($opening->fresh()->start_date->format('Y-m-d'))->toBe($opening->start_date->format('Y-m-d'))
        ->and($opening->fresh()->end_date)->toBeNull();
});
