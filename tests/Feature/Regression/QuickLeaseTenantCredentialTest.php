<?php

use App\Services\LeaseCreationService;
use Illuminate\Support\Facades\Hash;

/**
 * A tenant created by the quick-lease wizard must not carry a guessable credential.
 *
 * `tenants.password` is the Sanctum credential for `/api/v1` (`LoginTenantAction`), and the
 * wizard's tenant step has no password field — so `Hash::make($data['password'] ?? 'password')`
 * meant every retailer onboarded that way shared the literal secret `password`, and knowing a
 * retailer's email address was enough to authenticate as that company.
 *
 * It mattered most in combination: an authenticated tenant could then reach the demo-payment
 * shortcut and mark their own invoices paid (see `DemoPaymentEnvironmentGuardTest`). Each half was
 * survivable; chained, they were a remote, low-effort path to destroying a tenant's AR.
 *
 * The fix is not a better default — it is that there is no default. An unusable random secret
 * means the company exists and nobody can sign in until the operator issues credentials through
 * the audited, super_admin/manager-gated "Setup Portal Access" action.
 */
beforeEach(function () {
    $this->asset = makeAsset(['code' => 'MALL']);
    $this->unit = makeUnit($this->asset, ['status' => 'vacant']);
});

function wizardPayload(array $tenant = [], ?int $unitId = null): array
{
    return [
        'tenant_mode' => 'new',
        'tenant' => array_merge([
            'name' => 'Brand Egypt LLC',
            'email' => 'info@brand-egypt.test',
            'type' => 'company',
        ], $tenant),
        'lease' => [
            'unit_id' => $unitId,
            'commencement_date' => '2026-03-01',
            'term_months' => 12,
            'base_rent_monthly' => 10000,
            'service_charge_monthly' => 1500,
        ],
    ];
}

it('never assigns the literal password "password" to a wizard-created tenant', function () {
    $lease = app(LeaseCreationService::class)->create(wizardPayload(unitId: $this->unit->id));

    $tenant = $lease->tenant;

    expect($tenant->password)->not->toBeNull()
        ->and(Hash::check('password', $tenant->password))->toBeFalse();
});

it('gives two wizard-created tenants different secrets', function () {
    $second = makeUnit($this->asset, ['status' => 'vacant']);

    $a = app(LeaseCreationService::class)->create(wizardPayload(unitId: $this->unit->id));
    $b = app(LeaseCreationService::class)->create(
        wizardPayload(['email' => 'second@brand-egypt.test'], $second->id)
    );

    // A shared RANDOM default would be no better than a shared literal one.
    expect($a->tenant->password)->not->toBe($b->tenant->password);
});

it('still honours a password the caller supplies — the paired control', function () {
    // Without this the assertions above would pass just as happily if the column stopped being
    // written at all, which would be a different bug wearing the same green tick.
    // (LeaseCreationServiceTest covers the full new-tenant field mapping; this pins the branch.)
    $lease = app(LeaseCreationService::class)->create(
        wizardPayload(['password' => 'a-deliberate-secret'], $this->unit->id)
    );

    expect(Hash::check('a-deliberate-secret', $lease->tenant->password))->toBeTrue();
});
