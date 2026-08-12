<?php

/*
|--------------------------------------------------------------------------
| The month-end pack sends itself
|--------------------------------------------------------------------------
| Nothing in this system emailed a report. The pack was six screens opened by hand, six CSVs
| exported and attached to a mail, on a day somebody had to remember — so it arrived late in the
| months somebody was on leave, and not at all in the months somebody left.
|
| Two properties here would be SILENT if they broke, which is why each is tested from both sides:
|
|   1. **It runs as the person who saved it.** A report reads whatever the current user may read,
|      and a console command has no current user. Rendered as nobody, a report either shows nothing
|      or — far worse — shows everything. The owner is authenticated for the render, their own
|      `canAccess()` is checked first, and the guard is put back afterwards however it goes.
|   2. **It is idempotent.** The scheduler retries, catches up after downtime, and can run twice.
|      A month-end pack that arrives three times is how an operator learns to filter the sender.
*/

use App\Mail\SavedReportDelivered;
use App\Models\SavedReport;
use App\Models\User;
use App\Services\Reports\DeliverSavedReportService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);

    $this->asset = makeAsset(['code' => 'SC']);
    Mail::fake();
});

function scheduledView(User $owner, array $attributes = []): SavedReport
{
    return SavedReport::create($attributes + [
        'report' => 'trial_balance',
        'name' => 'Month-end trial balance',
        'parameters' => ['year' => 2026],
        'user_id' => $owner->id,
        'is_shared' => false,
        'frequency' => SavedReport::MONTHLY,
        'day_of_month' => 3,
        'recipients' => ['owner@jawad.test'],
    ]);
}

it('knows which day it is due', function () {
    $view = scheduledView(makeUser('accounting'));

    expect($view->isDueOn(CarbonImmutable::parse('2026-03-03')))->toBeTrue()
        ->and($view->isDueOn(CarbonImmutable::parse('2026-03-04')))->toBeFalse();
});

it('sends on the last day of a month too short for the chosen day', function () {
    // "The 31st" from an accountant means month end. Skipping February silently is the failure
    // they would notice last — the month the pack simply did not arrive.
    $view = scheduledView(makeUser('accounting'), ['day_of_month' => 31]);

    expect($view->isDueOn(CarbonImmutable::parse('2026-02-28')))->toBeTrue()
        ->and($view->isDueOn(CarbonImmutable::parse('2026-02-27')))->toBeFalse()
        // …and on a long month it still means the 31st, not the 28th.
        ->and($view->isDueOn(CarbonImmutable::parse('2026-03-31')))->toBeTrue()
        ->and($view->isDueOn(CarbonImmutable::parse('2026-03-28')))->toBeFalse();
});

it('never sends the same day twice', function () {
    $view = scheduledView(makeUser('accounting'));
    $on = CarbonImmutable::parse('2026-03-03');

    expect($view->isDueOn($on))->toBeTrue();

    $view->update(['last_delivered_on' => $on->toDateString()]);

    expect($view->fresh()->isDueOn($on))->toBeFalse()
        // …but next month it is due again.
        ->and($view->fresh()->isDueOn(CarbonImmutable::parse('2026-04-03')))->toBeTrue();
});

it('sends nothing without a recipient', function () {
    // A schedule with nowhere to go is a half-finished setup, not a delivery.
    $view = scheduledView(makeUser('accounting'), ['recipients' => []]);

    expect($view->isDueOn(CarbonImmutable::parse('2026-03-03')))->toBeFalse();
});

it('delivers a report as the person who saved it', function () {
    $owner = makeUser('accounting');
    $view = scheduledView($owner);

    expect(app(DeliverSavedReportService::class)->deliver($view))->toBeTrue();

    Mail::assertSent(SavedReportDelivered::class, function ($mail) {
        return $mail->hasTo('owner@jawad.test')
            && str_contains($mail->filename, 'trial-balance')
            && str_contains($mail->csv, 'Month-end trial balance') === false; // it is the report, not the label
    });
});

it('leaves no authenticated user behind after rendering', function () {
    // The render authenticates the owner. Leaking that out of the method would hand the NEXT saved
    // report in the run somebody else's property scope — a cross-tenant leak that would look like
    // a reporting bug.
    $owner = makeUser('accounting');

    expect(Auth::check())->toBeFalse();

    app(DeliverSavedReportService::class)->deliver(scheduledView($owner));

    expect(Auth::check())->toBeFalse();
});

it('stops delivering when the owner loses access to the report', function () {
    // A schedule is not a standing grant. Somebody moved off the finance team should stop receiving
    // the trial balance, and the delivery is where that has to bite — nobody revisits schedules.
    $view = scheduledView(makeUser('marketing'));

    expect(app(DeliverSavedReportService::class)->deliver($view))->toBeFalse();

    Mail::assertNothingSent();
});

it('refuses to deliver a report that cannot render without a browser', function () {
    // Six reports have no CSV at all — a checklist, a floor plan, a diagram, a searchable log, a
    // dry run and a PDF pack. `ReportCatalogue::NOT_DELIVERABLE` names them with a reason, and a
    // schedule on one must fail rather than send an empty file.
    $view = scheduledView(makeUser('accounting'), ['report' => 'occupancy_map']);

    expect(app(DeliverSavedReportService::class)->deliver($view))->toBeFalse();

    Mail::assertNothingSent();
});

it('sends each due report once, however many times the command runs', function () {
    // The idempotency that matters. The scheduler retries and catches up after downtime; the stamp
    // is claimed under a lock inside the transaction, so a second run is a no-op.
    $owner = makeUser('accounting');
    scheduledView($owner, ['day_of_month' => 3]);

    $this->artisan('reports:deliver', ['--date' => '2026-03-03'])->assertSuccessful();
    $this->artisan('reports:deliver', ['--date' => '2026-03-03'])->assertSuccessful();

    Mail::assertSent(SavedReportDelivered::class, 1);
});

it('sends nothing on a day nothing is due', function () {
    scheduledView(makeUser('accounting'), ['day_of_month' => 3]);

    $this->artisan('reports:deliver', ['--date' => '2026-03-04'])->assertSuccessful();

    Mail::assertNothingSent();
});

it('keeps going when one report fails', function () {
    // A month-end morning is exactly when the other reports matter.
    $owner = makeUser('accounting');
    scheduledView($owner, ['report' => 'occupancy_map', 'name' => 'Cannot render']);
    scheduledView($owner, ['name' => 'Fine']);

    $this->artisan('reports:deliver', ['--date' => '2026-03-03']);

    Mail::assertSent(SavedReportDelivered::class, 1);
});
