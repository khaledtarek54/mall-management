<?php

use App\Filament\Admin\Resources\Leases\Pages\CreateLease;
use App\Filament\Admin\Resources\Leases\Pages\EditLease;
use App\Models\Lease;
use App\Models\LeaseEvent;
use App\Services\LeaseTerminationService;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\Lang;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/**
 * A lease cannot be ENTERED as active with a term that has already run out.
 *
 * Reported by the tester: a lease keyed in September 2026 for a term of 01/09/2024 → 31/08/2025 read
 * **Active** on the register — a tenancy the system itself already believed had ended, since
 * `hasExpiredTerm()` was true the moment it was written.
 *
 * `leases:expire` was doing its job and structurally could not have caught this: it is a NIGHTLY
 * sweep, so between the save and 05:15 the register, the occupancy figures and the rent roll all
 * read a state nothing agreed with. **Yardi derives a lease's status from its dates plus explicit
 * acts** — entering a historical lease in Voyager shows it as Past immediately — so the fix is the
 * one this panel already applied to a unit's occupancy: stop OFFERING a state the system disagrees
 * with, rather than accepting it and correcting it later behind the operator's back.
 *
 * **Deliberately at the FORM, not on the model.** A `saving` hook that rewrote the column was tried
 * first and reverted: it makes "active with a past term" impossible, and three services still refuse
 * to act on a lease that is not `active` — renewing it, taking a parking bay, and (until this
 * change) closing it out. Making the state impossible a day earlier than the sweep does turns three
 * latent gaps into immediate ones without fixing any of them. The form is the door the operator
 * used; the sweep still owns everything else.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs(makeUser('super_admin'));
    $this->asset = makeAsset();
});

it('does not offer active once the term typed has already run out', function () {
    // The tester's exact act, on the real create page. The expiry field is live, so the option list
    // re-resolves as the dates are keyed rather than after a save has already accepted the wrong one.
    asTenant($this->asset, function () {
        $options = Livewire::test(CreateLease::class)
            ->fillForm(['commencement_date' => '2024-09-01', 'expiry_date' => '2025-08-31'])
            ->instance()
            ->form
            ->getFlatFields()['status']
            ->getOptions();

        expect($options)->not->toHaveKey('active')
            // Still offered — entering history as a finished tenancy is the whole point of the screen.
            ->and($options)->toHaveKey('expired');
    });
});

it('still offers active for a term that is still running', function () {
    // The control. The refusal above passes just as happily on a form that offers nothing.
    asTenant($this->asset, function () {
        $options = Livewire::test(CreateLease::class)
            ->fillForm([
                'commencement_date' => now()->subMonth()->toDateString(),
                'expiry_date' => now()->addYear()->toDateString(),
            ])
            ->instance()
            ->form
            ->getFlatFields()['status']
            ->getOptions();

        expect($options)->toHaveKey('active');
    });
});

it('explains itself in both languages rather than just removing the option', function () {
    // A withheld option with no reason reads as a broken dropdown. `Lang::has(..., fallback: false)`
    // because the default fallback answers TRUE for a key present only in English, which is the
    // realistic failure when a string is added in one pass and reviewed in English.
    expect(Lang::has('admin.helpers.lease_term_has_run_out', 'en', false))->toBeTrue()
        ->and(Lang::has('admin.helpers.lease_term_has_run_out', 'ar', false))->toBeTrue()
        ->and(__('admin.helpers.lease_term_has_run_out', [], 'ar'))
        ->not->toBe(__('admin.helpers.lease_term_has_run_out', [], 'en'));
});

it('leaves a lease already in a state the form no longer offers editable', function () {
    // The catalogue-lockout trap, through this door. Filament derives a Select's `Rule::in` from the
    // options it resolved, so withholding `active` from a lease that IS active would refuse every
    // save of it on a field nobody touched — a lease under notice, whose expiry has been stamped
    // into the past by `LeaseTerminationService`, is exactly that record.
    $lease = makeLease(makeUnit($this->asset), makeTenant(), [
        'status' => 'active',
        'commencement_date' => now()->subYears(2)->toDateString(),
        'expiry_date' => now()->subDay()->toDateString(),
    ]);

    asTenant($this->asset, function () use ($lease) {
        $options = Livewire::test(EditLease::class, [
            'record' => $lease->getRouteKey(),
        ])
            ->instance()
            ->form
            ->getFlatFields()['status']
            ->getOptions();

        expect($options)->toHaveKey('active');
    });
});

it('lets a tenancy whose term has ended still be CLOSED OUT', function () {
    // A SEPARATE gap, found while fixing the above and fixed with it because it is the same shape.
    // At the end of a term an operator has three answers — renew, hold over, or close out — and
    // `leases:expire` projects the whole candidate set to `expired` at 05:15. Holding over was given
    // its carve-out when LE-04 was found unreachable; closing out was not, so after that sweep a
    // tenant who had actually left could not be recorded as having left.
    //
    // Voyager records a move-out against a lease whether its status is Current or Past: the move-out
    // is a fact about the tenant, not about a column a nightly job maintains.
    $lease = makeLease(makeUnit($this->asset), makeTenant(), [
        'status' => 'active',
        'commencement_date' => now()->subYears(2)->toDateString(),
        'expiry_date' => now()->subMonths(2)->endOfMonth()->toDateString(),
    ]);

    $this->artisan('leases:expire')->assertSuccessful();
    expect($lease->fresh()->status)->toBe('expired');

    app(LeaseTerminationService::class)->terminate($lease->fresh(), [
        'termination_date' => now()->subMonth()->toDateString(),
        'reason' => 'Tenant vacated at the end of the term.',
    ]);

    expect($lease->fresh()->status)->toBe('terminated')
        ->and($lease->fresh()->events()->where('type', LeaseEvent::TYPE_TERMINATION)->exists())->toBeTrue();
});

it('still refuses to close out a tenancy somebody already closed', function () {
    $lease = makeLease(makeUnit($this->asset), makeTenant(), [
        'status' => 'active',
        'commencement_date' => now()->subYears(2)->toDateString(),
        'expiry_date' => now()->subMonths(2)->endOfMonth()->toDateString(),
    ]);

    $this->artisan('leases:expire')->assertSuccessful();

    app(LeaseTerminationService::class)->terminate($lease->fresh(), [
        'termination_date' => now()->subMonth()->toDateString(),
        'reason' => 'Tenant vacated.',
    ]);

    expect(fn () => app(LeaseTerminationService::class)->terminate($lease->fresh(), [
        'termination_date' => now()->toDateString(),
        'reason' => 'Again.',
    ]))->toThrow(InvalidArgumentException::class);
});

it('keeps an expired lease immutable for everything that is NOT a close-out', function () {
    // The loosening that must NOT have happened. `expired` is terminal, and the carve-out is a
    // keyhole for one shape — `expired` → `terminated`, touching no commercial term — not a door.
    $lease = makeLease(makeUnit($this->asset), makeTenant(), [
        'status' => 'active',
        'commencement_date' => now()->subYears(2)->toDateString(),
        'expiry_date' => now()->subMonths(2)->endOfMonth()->toDateString(),
    ]);

    $this->artisan('leases:expire')->assertSuccessful();
    expect($lease->fresh()->status)->toBe('expired');

    expect(fn () => $lease->fresh()->update(['base_rent_monthly' => 999999]))
        ->toThrow(DomainException::class);
});

it('still expires a past-term lease on the nightly sweep', function () {
    // The sweep is unchanged and still owns the ceremony — the lease event, the final-period
    // billing, the unit re-projection. The form fix stops one being ENTERED wrong; this is what
    // catches one that RUNS out.
    $lease = makeLease(makeUnit($this->asset), makeTenant(), [
        'status' => 'active',
        'commencement_date' => now()->subYears(2)->toDateString(),
        'expiry_date' => now()->subDay()->toDateString(),
    ]);

    expect($lease->fresh()->status)->toBe('active');

    $this->artisan('leases:expire')->assertSuccessful();

    expect(Lease::whereKey($lease->id)->value('status'))->toBe('expired');
});
