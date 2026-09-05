<?php

use App\Filament\Admin\RelationManagers\AssetStaffRelationManager;
use App\Filament\Admin\Resources\Assets\Pages\EditAsset;
use App\Models\Asset;
use App\Models\AssetUser;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Carbon;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/**
 * Two cards from the tester's board, both about the Assigned Staff register.
 *
 * **"Add a Status column showing whether each staff member is currently active or no longer
 * working."** The register printed an Assigned date and an Ended date and left the reader to
 * compare them against today, for every row, in their head.
 *
 * **"No way to edit the assigned staff email."** There was none from here — and there should not be
 * an email FIELD on the assignment modal: the address is the person's LOGIN, not a fact about their
 * posting to this mall, and editing a credential from a property tab is the wrong place for it.
 * What was missing is the ROUTE, so the address links to the user record for anyone who may edit it.
 *
 * The tenure answer comes from `AssetUser::coversDate()` — the pivot had no model at all until now,
 * so its dates came back as raw strings while the ownership pivot beside it returned real dates, and
 * "is this assignment still running?" had no definition anywhere to reuse.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs(makeUser('super_admin'));
    $this->asset = makeAsset();
});

function staffRegister(Asset $asset): Testable
{
    return Livewire::test(AssetStaffRelationManager::class, [
        'ownerRecord' => $asset,
        'pageClass' => EditAsset::class,
    ]);
}

it('shows an open-ended assignment as active', function () {
    $staff = makeUser('manager');
    $this->asset->staff()->attach($staff->id, ['assigned_at' => now()->subYear()->toDateString()]);

    staffRegister($this->asset)->assertTableColumnStateSet('tenure', 'active', $staff);
});

it('shows someone whose assignment has ended as no longer here', function () {
    $staff = makeUser('manager');
    $this->asset->staff()->attach($staff->id, [
        'assigned_at' => now()->subYear()->toDateString(),
        'ended_at' => now()->subDay()->toDateString(),
    ]);

    staffRegister($this->asset)->assertTableColumnStateSet('tenure', 'ended', $staff);
});

it('counts the last day of an assignment as still active', function () {
    // The boundary, and the same day-boundary discipline the tenure DATE fields now use: an
    // assignment ending today has not ended yet. Comparing instants rather than days is what made
    // a one-day tenure unsavable on the form.
    $staff = makeUser('manager');
    $this->asset->staff()->attach($staff->id, [
        'assigned_at' => now()->subYear()->toDateString(),
        'ended_at' => now()->toDateString(),
    ]);

    staffRegister($this->asset)->assertTableColumnStateSet('tenure', 'active', $staff);
});

it('shows a future assignment as not started rather than active', function () {
    // "Ended" and "not started yet" are both "not currently working here" and they call for
    // opposite actions, so they are not collapsed into one badge.
    $staff = makeUser('manager');
    $this->asset->staff()->attach($staff->id, ['assigned_at' => now()->addWeek()->toDateString()]);

    staffRegister($this->asset)->assertTableColumnStateSet('tenure', 'scheduled', $staff);
});

it('links the email to the person record', function () {
    $staff = makeUser('manager');
    $this->asset->staff()->attach($staff->id, ['assigned_at' => now()->toDateString()]);

    $url = staffRegister($this->asset)
        ->instance()
        ->getTable()
        ->getColumn('email')
        ->record($this->asset->fresh()->staff()->first())
        ->getUrl();

    expect($url)->toBeString()->toContain((string) $staff->getKey());
});

it('casts the pivot dates instead of handing back raw strings', function () {
    // What the pivot model bought, and the reason the badge can be trusted: `assigned_at` came back
    // as the string '2026-09-30' before, so every reader had to re-parse it and any one of them
    // could do it differently.
    $staff = makeUser('manager');
    $this->asset->staff()->attach($staff->id, [
        'assigned_at' => '2026-09-30',
        'ended_at' => '2026-10-31',
    ]);

    $pivot = $this->asset->fresh()->staff()->first()->pivot;

    expect($pivot->assigned_at)->toBeInstanceOf(Carbon::class)
        ->and($pivot->coversDate('2026-10-01'))->toBeTrue()
        ->and($pivot->coversDate('2026-09-29'))->toBeFalse()
        // Inclusive on BOTH bounds, exactly as AssetOwner::coversDate() is.
        ->and($pivot->coversDate('2026-09-30'))->toBeTrue()
        ->and($pivot->coversDate('2026-10-31'))->toBeTrue()
        ->and($pivot->coversDate('2026-11-01'))->toBeFalse();
});

it('reads the same pivot the same way from the user side', function () {
    // One table, two relations. `title` was readable from the property side and null from the
    // user's, because only one of them listed it — so both now use the same pivot model and the
    // same withPivot set.
    $staff = makeUser('manager');
    $this->asset->staff()->attach($staff->id, [
        'assigned_at' => '2026-09-30',
        'title' => 'Site Engineer',
    ]);

    $fromUser = $staff->fresh()->assignedAssets()->first()->pivot;

    expect($fromUser)->toBeInstanceOf(AssetUser::class)
        ->and($fromUser->title)->toBe('Site Engineer')
        ->and($fromUser->assigned_at)->toBeInstanceOf(Carbon::class);
});
