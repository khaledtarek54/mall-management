<?php

/**
 * The by-products of running the system have a retention period (D2-09).
 *
 * EG-34 gave the AUDIT TRAIL one and stopped there. Five other tables grew for ever with nothing to
 * prune them — `notifications`, `exports` and their FILES, `imports` / `failed_import_rows`,
 * `failed_jobs`, expired Sanctum tokens — and none of it is money or evidence, which is why it goes
 * unnoticed until a table has years in it.
 *
 * **The export FILE is the substance of this.** Filament's `Export` model `use`s the `Prunable`
 * trait and declares no `prunable()` method — so `model:prune` throws `LogicException` on it — and
 * there is no `pruning()` hook either, so even a working prune would delete the row and leave a
 * full CSV of a register on disk with nothing pointing at it.
 */

use App\Models\User;
use App\Settings\HousekeepingSettings;
use Carbon\CarbonImmutable;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->user = makeUser('super_admin');
});

/** A notification that has been on the bell for a given number of days. */
function bellNotificationAged(User $user, int $days): DatabaseNotification
{
    return DatabaseNotification::query()->create([
        'id' => (string) Str::uuid(),
        'type' => 'App\\Notifications\\Whatever',
        'notifiable_type' => $user->getMorphClass(),
        'notifiable_id' => $user->getKey(),
        'data' => ['body' => 'anything'],
        'created_at' => CarbonImmutable::now()->subDays($days),
        'updated_at' => CarbonImmutable::now()->subDays($days),
    ]);
}

/** An export row with a real file behind it, aged. */
function exportWithFileAged(User $user, int $days): Export
{
    $export = Export::query()->create([
        'completed_at' => CarbonImmutable::now()->subDays($days),
        'file_disk' => 'local',
        'file_name' => 'register',
        'exporter' => 'App\\Filament\\Exports\\Whatever',
        'total_rows' => 1,
        'processed_rows' => 1,
        'successful_rows' => 1,
        'user_id' => $user->getKey(),
    ]);

    $export->forceFill([
        'created_at' => CarbonImmutable::now()->subDays($days),
        'updated_at' => CarbonImmutable::now()->subDays($days),
    ])->saveQuietly();

    Storage::disk('local')->put($export->getFileDirectory().'/register.csv', 'tenant,rent'.PHP_EOL);

    return $export->refresh();
}

it('deletes a notification past its period and keeps a fresh one', function () {
    $old = bellNotificationAged($this->user, 200);
    $fresh = bellNotificationAged($this->user, 5);

    $this->artisan('atriom:prune-transient-data')->assertSuccessful();

    expect(DatabaseNotification::query()->whereKey($old->id)->exists())->toBeFalse()
        // The control: it did not simply empty the table.
        ->and(DatabaseNotification::query()->whereKey($fresh->id)->exists())->toBeTrue();
});

it('deletes the export FILE, not just the row', function () {
    // The half nothing else covers, and the reason this command exists rather than `model:prune`.
    $old = exportWithFileAged($this->user, 90);
    $directory = $old->getFileDirectory();

    expect(Storage::disk('local')->exists($directory.'/register.csv'))->toBeTrue();

    $this->artisan('atriom:prune-transient-data')->assertSuccessful();

    expect(Export::query()->whereKey($old->getKey())->exists())->toBeFalse()
        ->and(Storage::disk('local')->exists($directory.'/register.csv'))->toBeFalse();
});

it('keeps a recent export and its file', function () {
    $fresh = exportWithFileAged($this->user, 2);
    $directory = $fresh->getFileDirectory();

    $this->artisan('atriom:prune-transient-data')->assertSuccessful();

    expect(Export::query()->whereKey($fresh->getKey())->exists())->toBeTrue()
        ->and(Storage::disk('local')->exists($directory.'/register.csv'))->toBeTrue();
});

it('keeps everything at 0, and says so', function () {
    // The convention EG-34 set, per key. A silent no-op is indistinguishable from a broken schedule.
    $old = bellNotificationAged($this->user, 500);

    app(HousekeepingSettings::class)->fill(['notification_retention_days' => 0])->save();

    $this->artisan('atriom:prune-transient-data')
        ->expectsOutputToContain('keeping everything')
        ->assertSuccessful();

    expect(DatabaseNotification::query()->whereKey($old->id)->exists())->toBeTrue();
});

it('counts without deleting on a dry run', function () {
    // What a retention policy is actually reviewed on: how much would go.
    $old = bellNotificationAged($this->user, 400);

    $this->artisan('atriom:prune-transient-data', ['--dry-run' => true])
        ->expectsOutputToContain('would delete')
        ->assertSuccessful();

    expect(DatabaseNotification::query()->whereKey($old->id)->exists())->toBeTrue();
});

it('honours a period the operator changed', function () {
    // Read at RUN time, not when the schedule was defined — the trap EG-34 records and the reason
    // this is one command rather than five schedule lines with `--hours` arguments.
    $notification = bellNotificationAged($this->user, 45);

    app(HousekeepingSettings::class)->fill(['notification_retention_days' => 90])->save();
    $this->artisan('atriom:prune-transient-data')->assertSuccessful();
    expect(DatabaseNotification::query()->whereKey($notification->id)->exists())->toBeTrue();

    app(HousekeepingSettings::class)->fill(['notification_retention_days' => 30])->save();
    $this->artisan('atriom:prune-transient-data')->assertSuccessful();
    expect(DatabaseNotification::query()->whereKey($notification->id)->exists())->toBeFalse();
});
