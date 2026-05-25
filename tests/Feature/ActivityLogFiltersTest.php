<?php

use App\Filament\Admin\Pages\ActivityLog;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    ensureAllPropertiesAsset();
    $this->actingAs(makeUser('super_admin'));
    \Filament\Facades\Filament::setTenant(makeAsset(['code' => 'HW']));

    // Anchor "now" so the period-preset math is deterministic.
    CarbonImmutable::setTestNow('2026-05-15 12:00:00');
    Carbon\Carbon::setTestNow('2026-05-15 12:00:00');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    Carbon\Carbon::setTestNow();
});

function seedActivityAt(string $when): Activity
{
    return Activity::create([
        'log_name' => 'lease',
        'description' => 'updated',
        'event' => 'updated',
        'created_at' => $when,
        'updated_at' => $when,
    ]);
}

it('today preset keeps only rows from the current day', function () {
    $today = seedActivityAt('2026-05-15 09:00:00');
    $yesterday = seedActivityAt('2026-05-14 09:00:00');
    $lastWeek = seedActivityAt('2026-05-08 09:00:00');

    Livewire::test(ActivityLog::class)
        ->filterTable('period', 'today')
        ->assertCanSeeTableRecords([$today])
        ->assertCanNotSeeTableRecords([$yesterday, $lastWeek]);
});

it('yesterday preset isolates the previous calendar day', function () {
    $today = seedActivityAt('2026-05-15 09:00:00');
    $yesterday = seedActivityAt('2026-05-14 09:00:00');
    $dayBefore = seedActivityAt('2026-05-13 09:00:00');

    Livewire::test(ActivityLog::class)
        ->filterTable('period', 'yesterday')
        ->assertCanSeeTableRecords([$yesterday])
        ->assertCanNotSeeTableRecords([$today, $dayBefore]);
});

it('last_7_days preset includes today and the prior week, not older rows', function () {
    $today = seedActivityAt('2026-05-15 09:00:00');
    $sixDaysAgo = seedActivityAt('2026-05-09 09:00:00');
    $tenDaysAgo = seedActivityAt('2026-05-05 09:00:00');

    Livewire::test(ActivityLog::class)
        ->filterTable('period', 'last_7_days')
        ->assertCanSeeTableRecords([$today, $sixDaysAgo])
        ->assertCanNotSeeTableRecords([$tenDaysAgo]);
});

it('last_30_days preset includes everything within the trailing month', function () {
    $today = seedActivityAt('2026-05-15 09:00:00');
    $twentyDaysAgo = seedActivityAt('2026-04-25 09:00:00');
    $fortyDaysAgo = seedActivityAt('2026-04-05 09:00:00');

    Livewire::test(ActivityLog::class)
        ->filterTable('period', 'last_30_days')
        ->assertCanSeeTableRecords([$today, $twentyDaysAgo])
        ->assertCanNotSeeTableRecords([$fortyDaysAgo]);
});

it('this_month preset is calendar-month scoped, not 30-day rolling', function () {
    $may = seedActivityAt('2026-05-02 09:00:00');
    $aprLate = seedActivityAt('2026-04-30 23:59:00');

    Livewire::test(ActivityLog::class)
        ->filterTable('period', 'this_month')
        ->assertCanSeeTableRecords([$may])
        ->assertCanNotSeeTableRecords([$aprLate]);
});

it('last_month preset covers the prior calendar month only', function () {
    $apr = seedActivityAt('2026-04-15 09:00:00');
    $may = seedActivityAt('2026-05-15 09:00:00');
    $mar = seedActivityAt('2026-03-15 09:00:00');

    Livewire::test(ActivityLog::class)
        ->filterTable('period', 'last_month')
        ->assertCanSeeTableRecords([$apr])
        ->assertCanNotSeeTableRecords([$may, $mar]);
});

it('custom created_from / created_until range is inclusive of both bounds', function () {
    $may10 = seedActivityAt('2026-05-10 09:00:00');
    $may15 = seedActivityAt('2026-05-15 09:00:00');
    $may16 = seedActivityAt('2026-05-16 09:00:00');

    Livewire::test(ActivityLog::class)
        ->filterTable('created_range', [
            'created_from' => '2026-05-10',
            'created_until' => '2026-05-15',
        ])
        ->assertCanSeeTableRecords([$may10, $may15])
        ->assertCanNotSeeTableRecords([$may16]);
});

it('created_until alone caps to that day; created_from alone opens-ended forward', function () {
    $may10 = seedActivityAt('2026-05-10 09:00:00');
    $may15 = seedActivityAt('2026-05-15 09:00:00');
    $may20 = seedActivityAt('2026-05-20 09:00:00');

    // created_from only (>= 2026-05-15)
    Livewire::test(ActivityLog::class)
        ->filterTable('created_range', ['created_from' => '2026-05-15'])
        ->assertCanSeeTableRecords([$may15, $may20])
        ->assertCanNotSeeTableRecords([$may10]);

    // created_until only (<= 2026-05-15)
    Livewire::test(ActivityLog::class)
        ->filterTable('created_range', ['created_until' => '2026-05-15'])
        ->assertCanSeeTableRecords([$may10, $may15])
        ->assertCanNotSeeTableRecords([$may20]);
});
