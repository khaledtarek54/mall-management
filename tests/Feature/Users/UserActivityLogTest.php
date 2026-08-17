<?php

use App\Models\User;
use Spatie\Activitylog\Models\Activity;

it('logs activity rows for User create + name change', function () {
    $user = User::create([
        'name' => 'Original Name',
        'email' => 'user-'.uniqid().'@test.local',
        'password' => bcrypt('secret'),
    ]);

    expect(Activity::where('log_name', 'user')
        ->where('event', 'created')
        ->where('subject_id', $user->id)
        ->exists())->toBeTrue();

    $user->update(['name' => 'Renamed Name']);

    expect(Activity::where('log_name', 'user')
        ->where('event', 'updated')
        ->where('subject_id', $user->id)
        ->exists())->toBeTrue();
});

it('does not log a password-only change (password is not in the allowlist)', function () {
    $user = User::create([
        'name' => 'Q',
        'email' => 'q-'.uniqid().'@test.local',
        'password' => bcrypt('secret-one'),
    ]);

    $countBefore = Activity::where('log_name', 'user')
        ->where('event', 'updated')
        ->where('subject_id', $user->id)
        ->count();

    $user->update(['password' => bcrypt('secret-two')]);

    $countAfter = Activity::where('log_name', 'user')
        ->where('event', 'updated')
        ->where('subject_id', $user->id)
        ->count();

    // dontLogEmptyChanges + logOnly excludes password → no new row from a
    // password-only update.
    expect($countAfter)->toBe($countBefore);
});

it('logs a deleted event when the user is removed', function () {
    $user = User::create([
        'name' => 'Departing User',
        'email' => 'dep-'.uniqid().'@test.local',
        'password' => bcrypt('secret'),
    ]);
    $id = $user->id;

    $user->delete();

    expect(Activity::where('log_name', 'user')
        ->where('event', 'deleted')
        ->where('subject_id', $id)
        ->exists())->toBeTrue();
});
