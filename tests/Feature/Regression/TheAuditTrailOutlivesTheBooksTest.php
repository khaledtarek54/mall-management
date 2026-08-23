<?php

use App\Settings\AccountingSettings;
use Illuminate\Console\Scheduling\Schedule;
use Spatie\Activitylog\Models\Activity;

/**
 * EG-34 — how long the activity log is kept, and who decides.
 *
 * It was `config('activitylog.clean_after_days') = 365`, hardcoded, with no screen. The prune is
 * SCHEDULED monthly on the 1st, so this was not a dormant default: audit history really was being
 * deleted at a year old.
 *
 * Two things make 365 wrong here, and the second is the stronger one:
 *
 * - Egyptian commercial and tax records are commonly a FIVE-year obligation, so the trail expired
 *   years before the books it describes.
 * - **This system never deletes a money document.** An invoice from four years ago is still on the
 *   books; losing the record of who voided a line on it leaves a document nobody can account for.
 *
 * Bounded rather than infinite on purpose: the log names WHO did each thing, which is personal data,
 * and PDPL asks both that it not be kept longer than the purpose needs and that the period be
 * DOCUMENTED. A screen showing the number is that documentation; a constant in `config/` never was.
 */
function ageActivityByDays(int $days): Activity
{
    $row = Activity::create(['log_name' => 'test', 'description' => 'aged']);
    $row->forceFill(['created_at' => now()->subDays($days)])->saveQuietly();

    return $row->fresh();
}

function setRetention(int $days): void
{
    $settings = app(AccountingSettings::class);
    $settings->activity_log_retention_days = $days;
    $settings->save();
}

it('keeps the trail for five years by default, not the year it shipped with', function () {
    // The number matters: 365 is a web-application default, and the books this log describes are
    // kept for five years.
    expect(app(AccountingSettings::class)->activity_log_retention_days)->toBe(1825);
});

it('deletes what is past the period and keeps what is inside it', function () {
    setRetention(1825);

    $old = ageActivityByDays(1900);
    $inside = ageActivityByDays(1000);

    // The control is the whole point — a prune that deleted everything would satisfy the refusal
    // alone and read as a pass.
    $this->artisan('atriom:prune-activity-log')->assertSuccessful();

    expect(Activity::whereKey($old->id)->exists())->toBeFalse()
        ->and(Activity::whereKey($inside->id)->exists())->toBeTrue();
});

it('would have deleted an entry the old 365-day default destroyed', function () {
    // The regression this row exists for, stated as a comparison rather than as a number: an entry
    // two years old survived nothing before and survives now.
    setRetention(1825);

    $twoYears = ageActivityByDays(730);

    $this->artisan('atriom:prune-activity-log')->assertSuccessful();

    expect(Activity::whereKey($twoYears->id)->exists())->toBeTrue();

    // …and it does go once the operator shortens the period, so retention is really being honoured
    // rather than the prune being broken.
    setRetention(365);
    $this->artisan('atriom:prune-activity-log')->assertSuccessful();

    expect(Activity::whereKey($twoYears->id)->exists())->toBeFalse();
});

it('keeps everything when the operator sets 0, and says so rather than going quiet', function () {
    // A silent no-op looks identical to a broken schedule, which is how the posting-date bug in
    // this codebase survived being fixed six times.
    setRetention(0);

    $ancient = ageActivityByDays(9000);

    $this->artisan('atriom:prune-activity-log')
        ->expectsOutputToContain('keep everything')
        ->assertSuccessful();

    expect(Activity::whereKey($ancient->id)->exists())->toBeTrue();
});

it('counts without deleting on a dry run', function () {
    // What a retention policy is actually reviewed on: how much would go if this ran.
    setRetention(365);

    $old = ageActivityByDays(500);

    $this->artisan('atriom:prune-activity-log --dry-run')
        ->expectsOutputToContain('Would delete')
        ->assertSuccessful();

    expect(Activity::whereKey($old->id)->exists())->toBeTrue();
});

it('is the command the scheduler actually runs', function () {
    // The gap this whole class of bug lives in: a command nothing schedules is a policy nobody
    // applies. `activitylog:clean` was scheduled and read a constant; this must have replaced it.
    $schedule = app(Schedule::class);

    $commands = collect($schedule->events())->map(fn ($e) => $e->command ?? '')->implode(' ');

    expect($commands)->toContain('atriom:prune-activity-log')
        ->and($commands)->not->toContain('activitylog:clean');
});
