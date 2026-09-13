<?php

/*
|--------------------------------------------------------------------------
| An option is exercised by an ACT, not by a dropdown (SW-259)
|--------------------------------------------------------------------------
| Found by the review of SW-258. The lease's Options tab offered every status as a word in a
| Select — on create and on edit — so an open option could be set to `exercised` by hand.
| `ExerciseLeaseOptionService` is the act: it checks the notice window, records the lease event,
| stamps the notice and the resolution dates and creates the renewal. The dropdown wrote the word
| and none of that: an option reading "exercised" with no lease to show for it, and a unit freed.
|
| SW-238's rule, applied to the option: a status past the first one is the outcome of an act. Two
| layers, each with its own case here — the Select no longer offers `exercised` (Filament's own
| `Rule::in` over the options refuses a smuggled value first), and the model refuses the
| transition for every door the form is not unless the ACT is the one saving (`markExercised()`).
| The first cut recognised the act by SHAPE — the notice and resolution dates dirty together —
| and the review broke it: record the notice date on the tab, press Exercise later, and the
| service re-writes the same date, nothing is dirty, and the operator is refused for pressing the
| button they were told to press. An exercised record's status is LOCKED: its exercise is on the
| lease's record, and no act un-exercises. Statements — waived, lapsed, and reopening — stay
| available from the tab, and a stated outcome gets the resolution date it omitted.
*/

use App\Filament\Admin\RelationManagers\LeaseOptionsRelationManager;
use App\Filament\Admin\Resources\Leases\Pages\EditLease;
use App\Models\Lease;
use App\Models\LeaseOption;
use App\Models\User;
use App\Services\ExerciseLeaseOptionService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\App;
use Livewire\Livewire;

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

/** An open renewal option whose notice window is open today. */
function sw259Option(Lease $lease, array $attrs = []): LeaseOption
{
    return LeaseOption::create(array_merge([
        'lease_id' => $lease->id,
        'type' => 'renewal',
        'status' => 'open',
        'earliest_notice_date' => '2026-08-10',
        'latest_notice_date' => '2026-10-09',
        'term_months' => 24,
        'rent_basis' => 'uplift_percent',
        'uplift_percent' => 10,
    ], $attrs));
}

function sw259Tab(Lease $lease)
{
    return Livewire::test(LeaseOptionsRelationManager::class, [
        'ownerRecord' => $lease,
        'pageClass' => EditLease::class,
    ]);
}

it('does not offer Exercised on a live option, and refuses it when it is sent anyway', function (): void {
    $option = sw259Option($this->lease);

    expect(LeaseOption::statusesAnOperatorMayState($option))->not->toContain('exercised')
        ->and(LeaseOption::statusesAnOperatorMayState($option))->toContain('open', 'waived', 'lapsed');

    // Filament derives `Rule::in` from the options it offered: a smuggled value is refused ON the
    // field, and the modal stays open.
    sw259Tab($this->lease)
        ->callTableAction('edit', $option, data: ['status' => 'exercised'])
        ->assertHasTableActionErrors(['status']);

    expect($option->fresh()->status)->toBe('open')
        ->and($option->fresh()->resolved_at)->toBeNull();
});

it('refuses the transition at the model for every door the form is not — and names the act', function (): void {
    $option = sw259Option($this->lease);

    foreach (['en', 'ar'] as $locale) {
        App::setLocale($locale);
        expect(fn () => $option->fresh()->update(['status' => 'exercised']))
            ->toThrow(DomainException::class, __('admin.errors.option_exercised_is_an_act'));
    }
    App::setLocale('en');

    // …including a door that dresses the word in the act's clothes. The first cut recognised the
    // act by the two dates being dirty together — and the model's own derivation of `resolved_at`
    // supplied the second, so a status plus a fresh notice date walked through.
    expect(fn () => $option->fresh()->update(['status' => 'exercised', 'notice_given_at' => '2026-08-21']))
        ->toThrow(DomainException::class);

    expect($option->fresh()->status)->toBe('open')
        ->and($option->fresh()->resolved_at)->toBeNull();
});

it('still exercises through the act — including when the notice date was recorded on the tab first', function (): void {
    // The careful workflow, and the one the first cut refused: the served notice is recorded on
    // the option's own form, Exercise is pressed later, and the modal defaults to that same date.
    $option = sw259Option($this->lease);
    sw259Tab($this->lease)
        ->callTableAction('edit', $option, data: ['notice_given_at' => '2026-08-20'])
        ->assertHasNoTableActionErrors();
    expect($option->fresh()->notice_given_at?->toDateString())->toBe('2026-08-20');

    sw259Tab($this->lease)
        ->callTableAction('exercise', $option->fresh(), data: ['notice_given_at' => '2026-08-20'])
        ->assertHasNoTableActionErrors()
        ->assertNotified(__('admin.lease_options.exercised_notice'));

    expect($option->fresh()->status)->toBe('exercised')
        ->and($option->fresh()->resolved_at?->toDateString())->toBe('2026-08-30');

    // And the plain service call on an option with no date yet — the case the first test had.
    $another = sw259Option($this->lease, ['type' => 'expansion']);
    expect(app(ExerciseLeaseOptionService::class)->exercise($another, [])->status)->toBe('exercised');
});

it('locks the status of an exercised option — on the tab and at the model', function (): void {
    $option = app(ExerciseLeaseOptionService::class)->exercise(sw259Option($this->lease), ['notice_given_at' => '2026-08-20']);

    expect(LeaseOption::statusesAnOperatorMayState($option))->toBe(['exercised']);

    // The control renders disabled…
    sw259Tab($this->lease)
        ->mountTableAction('edit', $option)
        ->assertFormFieldDisabled('status');

    // …the rest of the record stays editable (a lock that freezes the notes reads as a broken form)…
    sw259Tab($this->lease)
        ->callTableAction('edit', $option, data: ['notes' => 'Renewal signed 20 Aug.'])
        ->assertHasNoTableActionErrors();
    expect($option->fresh()->status)->toBe('exercised')
        ->and($option->fresh()->notes)->toBe('Renewal signed 20 Aug.');

    // …a value smuggled for the locked field is refused ON the field (`Rule::in` over the one
    // option offered), so the record keeps its own…
    sw259Tab($this->lease)
        ->callTableAction('edit', $option, data: ['status' => 'open'])
        ->assertHasTableActionErrors(['status']);
    expect($option->fresh()->status)->toBe('exercised');

    // …and the disabled control is a rendering decision; the model is the gate for every other door.
    expect(fn () => $option->fresh()->update(['status' => 'open']))
        ->toThrow(DomainException::class, __('admin.errors.option_exercised_is_final'));
});

it('lets a waiver be STATED from the tab, and derives the resolution date it omitted', function (): void {
    $option = sw259Option($this->lease);

    sw259Tab($this->lease)
        ->callTableAction('edit', $option, data: ['status' => 'waived'])
        ->assertHasNoTableActionErrors();

    expect($option->fresh()->status)->toBe('waived')
        ->and($option->fresh()->resolved_at?->toDateString())->toBe('2026-08-30');
});

it('lets a resolved option be REOPENED from the tab — the way back SW-258 relies on', function (): void {
    $option = sw259Option($this->lease, ['status' => 'waived', 'resolved_at' => '2026-08-01']);

    sw259Tab($this->lease)
        ->callTableAction('edit', $option, data: ['status' => 'open'])
        ->assertHasNoTableActionErrors();

    expect($option->fresh()->status)->toBe('open')
        ->and($option->fresh()->resolved_at)->toBeNull();
});

it('withholds Exercised on the create form too, while a console seeder may still record history', function (): void {
    // A NEW row is not a transition, so the model accepts an already-exercised option from a
    // seeder abstracting history (`SeedLeasingDepthCommand`). The tab does not offer it: a
    // hand-made exercised row would outrank a genuine one in `pendingRenewalTerms()`, and the tab
    // is the door for options on THIS lease going forward.
    expect(LeaseOption::statusesAnOperatorMayState(null))->not->toContain('exercised');

    $history = sw259Option($this->lease, [
        'status' => 'exercised',
        'earliest_notice_date' => '2024-01-01',
        'latest_notice_date' => '2024-04-01',
        'resolved_at' => '2024-03-15',
    ]);
    expect($history->status)->toBe('exercised')
        ->and($history->resolved_at?->toDateString())->toBe('2024-03-15');
});

it('dates a stated lapse on the day its window closed, not on the day it was typed', function (): void {
    $lapsed = sw259Option($this->lease, [
        'status' => 'lapsed',
        'earliest_notice_date' => '2024-01-01',
        'latest_notice_date' => '2024-04-01',
    ]);

    expect($lapsed->resolved_at?->toDateString())->toBe('2024-04-01');
});
