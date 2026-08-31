<?php

declare(strict_types=1);

use App\Models\Lease;
use App\Models\LeaseOption;
use App\Models\User;
use App\Services\ExerciseLeaseOptionService;
use Carbon\CarbonImmutable;
use App\Filament\Admin\RelationManagers\LeaseOptionsRelationManager;
use App\Filament\Admin\Resources\Leases\Pages\EditLease;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;

/**
 * A NOTICE WINDOW IS A CONTRACTUAL TERM, AND NOTHING WAS CHECKING IT.
 *
 * `LeaseOption::windowIsOpen()` has been on the model since options shipped and NO caller ever
 * asked it — built, correct and unreachable, the shape this repo names for services that run and
 * bill nobody.
 *
 * Measured on the demo books: a break option whose window opens on 30/12/2026 was exercised on
 * 30/08/2026, four months early, and the system recorded a termination and priced its 250,000
 * penalty. The tenant's answer is that the notice was served outside the window their lease grants
 * and is therefore void — so the mall holds a termination its own contract does not support.
 *
 * The window CLOSING was refused only by accident: `status === 'lapsed'` is written by a scheduled
 * sweep, so an option the sweep has not yet reached passed in both directions.
 *
 * Judged on the date NOTICE WAS SERVED, not on today. A notice served inside the window and keyed
 * a week later is valid — refusing it on today's date would push the operator to falsify the date,
 * which is the reason the service derives `$noticeGiven` in the first place.
 */
beforeEach(function (): void {
    $this->seed(RolesPermissionsSeeder::class);

    $user = User::factory()->create();
    $user->assignRole('super_admin');
    $this->actingAs($user);

    $this->lease = Lease::factory()->create([
        'status' => 'active',
        'commencement_date' => CarbonImmutable::parse('2025-01-01'),
        'expiry_date' => CarbonImmutable::parse('2029-12-31'),
        'base_rent_monthly' => 44_000,
        'escalation_type' => 'none',
    ]);

    CarbonImmutable::setTestNow('2026-08-30');
    Carbon\Carbon::setTestNow('2026-08-30');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    Carbon\Carbon::setTestNow();
});

function optionWindowed(Lease $lease, string $from, ?string $to): LeaseOption
{
    return LeaseOption::create([
        'lease_id' => $lease->id,
        'type' => 'renewal',
        'status' => 'open',
        'earliest_notice_date' => $from,
        'latest_notice_date' => $to,
        'term_months' => 24,
        'rent_basis' => 'uplift_percent',
        'uplift_percent' => 8,
    ]);
}

it('refuses a notice served before the window opens', function (): void {
    $option = optionWindowed($this->lease, '2026-12-30', '2027-03-30');

    expect(fn () => app(ExerciseLeaseOptionService::class)->exercise($option, ['reason' => 'early']))
        ->toThrow(DomainException::class);
});

it('refuses a notice served after the window closes', function (): void {
    $option = optionWindowed($this->lease, '2026-01-01', '2026-06-30');

    // Status is still `open` — the lapse sweep has not reached it. Without the window check this
    // passed, which is why "it was refused" was never proof the rule existed.
    expect($option->status)->toBe('open');

    expect(fn () => app(ExerciseLeaseOptionService::class)->exercise($option, ['reason' => 'late']))
        ->toThrow(DomainException::class);
});

it('accepts a notice served inside the window', function (): void {
    $option = optionWindowed($this->lease, '2026-08-10', '2026-10-09');

    $exercised = app(ExerciseLeaseOptionService::class)->exercise($option, ['reason' => 'in time']);

    expect($exercised->fresh()->status)->toBe('exercised');
});

it('accepts a notice DATED inside the window even when it is keyed later', function (): void {
    $option = optionWindowed($this->lease, '2026-12-30', '2027-03-30');

    // The whole reason the service derives a served date rather than using today: the operator is
    // recording something that happened, and refusing it would push them to falsify the date.
    CarbonImmutable::setTestNow('2027-04-15');
    Carbon\Carbon::setTestNow('2027-04-15');

    $exercised = app(ExerciseLeaseOptionService::class)->exercise($option, [
        'notice_given_at' => '2027-01-15',
        'reason' => 'served in January, keyed in April',
    ]);

    expect($exercised->fresh()->status)->toBe('exercised');
});

it('accepts an option with no closing date at all', function (): void {
    $option = optionWindowed($this->lease, '2026-01-01', null);

    // A null `latest_notice_date` is an option with no deadline — `windowHasClosed()` answers false
    // for it, and a guard that read null as "closed" would refuse every open-ended option.
    expect(app(ExerciseLeaseOptionService::class)->exercise($option, ['reason' => 'no deadline'])->fresh()->status)
        ->toBe('exercised');
});

it('words the refusal in both languages, naming the window', function (): void {
    foreach (['en', 'ar'] as $locale) {
        $message = trans('admin.errors.option_notice_outside_window', [
            'served' => '30/08/2026', 'from' => '30/12/2026', 'to' => '30/03/2027',
        ], $locale);

        expect($message)->not->toBe('admin.errors.option_notice_outside_window')
            ->and($message)->toContain('30/12/2026');
    }

    expect(trans('admin.errors.option_notice_outside_window', [], 'ar'))->toMatch('/\p{Arabic}/u');
});

it('never reports a refusal and a success for the same click', function (): void {
    // WHAT THE OPERATOR SAW, reported from the panel: two notifications at once —
    //
    //   "Notice was served on 01/06/2026, outside this option's window (10/08/2026 to 09/10/2026)…"
    //   "Option marked exercised."
    //
    // …and only the first was true. A `catch` around the service call showed the refusal and then
    // let the closure RETURN NORMALLY, so Filament sent the action's success notification straight
    // after it. A success toast is what an operator files the day's work by; one that fires over a
    // refusal is worse than no message at all.
    $option = optionWindowed($this->lease, '2026-08-10', '2026-10-09');
    $lease = $this->lease;

    $component = Livewire::test(LeaseOptionsRelationManager::class, [
        'ownerRecord' => $lease,
        'pageClass' => EditLease::class,
    ])->callTableAction('exercise', $option, data: ['notice_given_at' => '2026-06-01']);

    // Refused ON THE FIELD, so the modal stays open with the date still in it and the operator
    // fixes the day rather than re-typing the reason and the document reference.
    $component->assertHasTableActionErrors(['notice_given_at']);

    // Nothing happened, and nothing SAID anything happened.
    expect($option->fresh()->status)->toBe('open')
        ->and($option->fresh()->notice_given_at)->toBeNull();

    $component->assertNotNotified(__('admin.lease_options.exercised_notice'));
});

it('still exercises on a date inside the window, through the same modal', function (): void {
    // The control. A refusal test passes just as happily when the action is a no-op, so the
    // success path must be driven through the SAME component for the pair to prove anything.
    $option = optionWindowed($this->lease, '2026-08-10', '2026-10-09');

    Livewire::test(LeaseOptionsRelationManager::class, [
        'ownerRecord' => $this->lease,
        'pageClass' => EditLease::class,
    ])
        ->callTableAction('exercise', $option, data: ['notice_given_at' => '2026-08-20'])
        ->assertHasNoTableActionErrors()
        ->assertNotified(__('admin.lease_options.exercised_notice'));

    expect($option->fresh()->status)->toBe('exercised');
});
