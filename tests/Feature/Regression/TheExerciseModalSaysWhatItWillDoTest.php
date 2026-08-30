<?php

declare(strict_types=1);

use App\Filament\Admin\RelationManagers\LeaseOptionsRelationManager;
use App\Filament\Admin\Resources\Leases\Pages\EditLease;
use App\Models\Lease;
use App\Models\LeaseOption;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;

/**
 * EXERCISING AN OPTION BINDS BOTH PARTIES FOR ITS WHOLE TERM, AND THE MODAL SAID NOTHING.
 *
 * It asked for a notice date, a reason and a document reference, and showed no outcome at all —
 * not the window being judged against, not when the new term starts, not what the rent becomes.
 * Every one of those is already derived by the model and the service; none of it was shown.
 *
 * Two of the lines matter more than the rest. "The lease itself is not changed today" is the thing
 * operators get wrong — exercising records that notice was SERVED and leaves the running lease
 * alone. And the window warning appears while the date can still be corrected, rather than as a
 * refusal after Confirm.
 *
 * `projectedRent()` returns NULL for a market or CPI basis — neither is a number this system may
 * invent — so the preview says the rent will be agreed rather than printing a figure nobody set.
 */
beforeEach(function (): void {
    $this->seed(RolesPermissionsSeeder::class);

    $user = User::factory()->create();
    $user->assignRole('super_admin');
    $this->actingAs($user);

    CarbonImmutable::setTestNow('2026-08-30');
    Carbon\Carbon::setTestNow('2026-08-30');

    $this->lease = Lease::factory()->create([
        'status' => 'active',
        'commencement_date' => CarbonImmutable::parse('2026-08-01'),
        'expiry_date' => CarbonImmutable::parse('2029-07-31'),
        'base_rent_monthly' => 44_000,
        'escalation_type' => 'none',
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    Carbon\Carbon::setTestNow();
});

function exercisePreview(LeaseOption $option): string
{
    $component = Livewire::test(LeaseOptionsRelationManager::class, [
        'ownerRecord' => $option->lease,
        'pageClass' => EditLease::class,
    ])->mountTableAction('exercise', $option->getKey());

    $page = $component->instance();

    // `getSchema()` takes the schema's NAME — the mounted action knows its own.
    $action = $page->getMountedAction();

    return strip_tags((string) $page
        ->getSchema($page->getMountedActionSchemaName())
        ->getFlatComponents()['exercise_preview']
        ->getContent());
}

it('shows the window, the new term and the rent it will become', function (): void {
    $option = LeaseOption::create([
        'lease_id' => $this->lease->id,
        'type' => 'renewal',
        'status' => 'open',
        'earliest_notice_date' => '2026-08-10',
        'latest_notice_date' => '2026-10-09',
        'term_months' => 36,
        'rent_basis' => 'uplift_percent',
        'uplift_percent' => 8,
    ]);

    $preview = exercisePreview($option);

    expect($preview)->toContain('10/08/2026')          // window opens
        ->and($preview)->toContain('01/08/2029')       // new term starts the day after expiry
        ->and($preview)->toContain('36')               // its length
        ->and($preview)->toContain('47,520.00');       // 44,000 × 1.08
});

it('says the lease itself does not change today', function (): void {
    $option = LeaseOption::create([
        'lease_id' => $this->lease->id, 'type' => 'renewal', 'status' => 'open',
        'earliest_notice_date' => '2026-08-10', 'latest_notice_date' => '2026-10-09',
        'term_months' => 24, 'rent_basis' => 'uplift_percent', 'uplift_percent' => 5,
    ]);

    // The thing operators get wrong: exercising records that notice was served. The running lease
    // is untouched until its own expiry.
    expect(exercisePreview($option))->toContain(trans('admin.lease_options.preview.records_value'));
});

it('warns that a notice outside the window will be refused, before Confirm', function (): void {
    $option = LeaseOption::create([
        'lease_id' => $this->lease->id, 'type' => 'termination', 'status' => 'open',
        'earliest_notice_date' => '2026-12-30', 'latest_notice_date' => '2027-03-30',
        'penalty_amount' => 250_000,
    ]);

    $preview = exercisePreview($option);

    expect($preview)->toContain(trans('admin.lease_options.preview.outside_window'))
        // and the money it would cost, which is the reason to read the warning
        ->and($preview)->toContain('250,000.00');
});

it('does not invent a rent it cannot know', function (): void {
    $option = LeaseOption::create([
        'lease_id' => $this->lease->id, 'type' => 'renewal', 'status' => 'open',
        'earliest_notice_date' => '2026-08-10', 'latest_notice_date' => '2026-10-09',
        'term_months' => 36, 'rent_basis' => 'market',
    ]);

    // A market rent needs a valuation and a CPI rent needs an index — the same rule that makes the
    // escalation sweep WAIT rather than assume.
    $preview = exercisePreview($option);

    expect($preview)->not->toContain('47,520')
        ->and($preview)->toContain(trans('admin.lease_options.preview.rent_to_be_agreed', [
            'basis' => trans('admin.enums.rent_basis.market'),
        ]));
});
