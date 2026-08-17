<?php

use App\Models\DeviceToken;
use App\Models\Lease;
use App\Models\Note;
use App\Models\Tenant;
use App\Models\User;
use App\Support\MorphMap;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;

/* ───────────────────────── DeviceToken ───────────────────────── */

it('DeviceToken mass-assigns its fillable columns', function () {
    $tenant = makeTenant();

    $token = DeviceToken::create([
        'tenant_id' => $tenant->id,
        'platform' => 'ios',
        'token' => 'apns-opaque-token-abc123',
        'device_name' => "Khaled's iPhone",
        'last_used_at' => '2026-06-01 09:30:00',
    ]);

    $fresh = $token->fresh();

    expect($fresh->tenant_id)->toBe($tenant->id)
        ->and($fresh->platform)->toBe('ios')
        ->and($fresh->token)->toBe('apns-opaque-token-abc123')
        ->and($fresh->device_name)->toBe("Khaled's iPhone");
});

it('DeviceToken does NOT mass-assign columns outside $fillable (guarded id)', function () {
    $tenant = makeTenant();

    $token = DeviceToken::create([
        'id' => 999999,
        'tenant_id' => $tenant->id,
        'platform' => 'android',
        'token' => 'fcm-token',
    ]);

    // id is not fillable: the attempted value is ignored, an autoincrement id is used.
    expect($token->id)->not->toBe(999999);
});

it('DeviceToken casts last_used_at to a Carbon datetime', function () {
    $tenant = makeTenant();

    $token = DeviceToken::create([
        'tenant_id' => $tenant->id,
        'platform' => 'android',
        'token' => 'fcm-token-xyz',
        'last_used_at' => '2026-06-15 14:00:00',
    ])->fresh();

    expect($token->last_used_at)->toBeInstanceOf(Carbon::class)
        ->and($token->last_used_at->format('Y-m-d H:i:s'))->toBe('2026-06-15 14:00:00');
});

it('DeviceToken last_used_at is nullable and stays null when omitted', function () {
    $tenant = makeTenant();

    $token = DeviceToken::create([
        'tenant_id' => $tenant->id,
        'platform' => 'ios',
        'token' => 'no-timestamp-token',
    ])->fresh();

    expect($token->last_used_at)->toBeNull();
});

it('DeviceToken belongs to its owning tenant', function () {
    $tenant = makeTenant();

    $token = DeviceToken::create([
        'tenant_id' => $tenant->id,
        'platform' => 'ios',
        'token' => 'rel-token',
    ]);

    expect($token->tenant())->toBeInstanceOf(BelongsTo::class)
        ->and($token->tenant->is($tenant))->toBeTrue()
        ->and($token->tenant)->toBeInstanceOf(Tenant::class);
});

it('DeviceToken enforces a unique (tenant, platform, device_name) registration', function () {
    $tenant = makeTenant();

    DeviceToken::create([
        'tenant_id' => $tenant->id,
        'platform' => 'ios',
        'token' => 'first-token',
        'device_name' => 'iPhone 15',
    ]);

    // Same phone (tenant + platform + device_name) re-registering must collide —
    // the schema upserts on this key rather than stacking stale tokens.
    expect(fn () => DeviceToken::create([
        'tenant_id' => $tenant->id,
        'platform' => 'ios',
        'token' => 'rotated-token',
        'device_name' => 'iPhone 15',
    ]))->toThrow(QueryException::class);
});

it('DeviceToken allows the same device_name across different platforms', function () {
    $tenant = makeTenant();

    $ios = DeviceToken::create([
        'tenant_id' => $tenant->id,
        'platform' => 'ios',
        'token' => 'ios-token',
        'device_name' => 'My Phone',
    ]);
    $android = DeviceToken::create([
        'tenant_id' => $tenant->id,
        'platform' => 'android',
        'token' => 'android-token',
        'device_name' => 'My Phone',
    ]);

    expect($ios->exists)->toBeTrue()
        ->and($android->exists)->toBeTrue()
        ->and(DeviceToken::where('tenant_id', $tenant->id)->count())->toBe(2);
});

/* ───────────────────────────── Note ───────────────────────────── */

it('Note mass-assigns its fillable columns', function () {
    $tenant = makeTenant();
    $author = makeUser('manager');

    $note = Note::create([
        'noteable_type' => MorphMap::alias(Tenant::class),
        'noteable_id' => $tenant->id,
        'author_id' => $author->id,
        'channel' => 'whatsapp',
        'subject' => 'Overdue reminder',
        'body' => 'Promised to pay by EOD.',
        'contacted_at' => '2026-06-20 11:00:00',
    ]);

    $fresh = $note->fresh();

    expect($fresh->noteable_type)->toBe(MorphMap::alias(Tenant::class))
        ->and($fresh->noteable_id)->toBe($tenant->id)
        ->and($fresh->author_id)->toBe($author->id)
        ->and($fresh->channel)->toBe('whatsapp')
        ->and($fresh->subject)->toBe('Overdue reminder')
        ->and($fresh->body)->toBe('Promised to pay by EOD.');
});

it('Note casts contacted_at to a Carbon datetime', function () {
    $tenant = makeTenant();
    $author = makeUser('manager');

    $note = Note::create([
        'noteable_type' => MorphMap::alias(Tenant::class),
        'noteable_id' => $tenant->id,
        'author_id' => $author->id,
        'channel' => 'call',
        'body' => 'Called the tenant.',
        'contacted_at' => '2026-06-21 16:45:00',
    ])->fresh();

    expect($note->contacted_at)->toBeInstanceOf(Carbon::class)
        ->and($note->contacted_at->format('Y-m-d H:i:s'))->toBe('2026-06-21 16:45:00');
});

it('Note.contacted_at is nullable and subject is optional', function () {
    $tenant = makeTenant();
    $author = makeUser('manager');

    $note = Note::create([
        'noteable_type' => MorphMap::alias(Tenant::class),
        'noteable_id' => $tenant->id,
        'author_id' => $author->id,
        'channel' => 'other',
        'body' => 'No contact timestamp recorded.',
    ])->fresh();

    expect($note->contacted_at)->toBeNull()
        ->and($note->subject)->toBeNull();
});

it('Note belongs to its author (User on author_id)', function () {
    $tenant = makeTenant();
    $author = makeUser('leasing');

    $note = Note::create([
        'noteable_type' => MorphMap::alias(Tenant::class),
        'noteable_id' => $tenant->id,
        'author_id' => $author->id,
        'channel' => 'email',
        'body' => 'Emailed the lease draft.',
    ]);

    expect($note->author())->toBeInstanceOf(BelongsTo::class)
        ->and($note->author->is($author))->toBeTrue()
        ->and($note->author)->toBeInstanceOf(User::class);
});

it('Note morphs to its subject (noteable) — polymorphic to a Tenant', function () {
    $tenant = makeTenant();
    $author = makeUser('manager');

    $note = Note::create([
        'noteable_type' => MorphMap::alias(Tenant::class),
        'noteable_id' => $tenant->id,
        'author_id' => $author->id,
        'channel' => 'meeting',
        'body' => 'Met at the unit.',
    ]);

    expect($note->noteable())->toBeInstanceOf(MorphTo::class)
        ->and($note->noteable->is($tenant))->toBeTrue()
        ->and($note->noteable)->toBeInstanceOf(Tenant::class);
});

it('Note morphs to a different subject type (a Lease) on the same relation', function () {
    $asset = makeAsset();
    $unit = makeUnit($asset);
    $tenant = makeTenant();
    $lease = makeLease($unit, $tenant);
    $author = makeUser('manager');

    $note = Note::create([
        'noteable_type' => MorphMap::alias(Lease::class),
        'noteable_id' => $lease->id,
        'author_id' => $author->id,
        'channel' => 'site_visit',
        'body' => 'Inspected the leased unit.',
    ]);

    expect($note->noteable)->toBeInstanceOf(Lease::class)
        ->and($note->noteable->is($lease))->toBeTrue();
});

it('Note exposes the canonical channel catalogue via CHANNELS', function () {
    expect(Note::CHANNELS)->toBe(['call', 'whatsapp', 'email', 'meeting', 'site_visit', 'other']);
});

it('Note writes a spatie activity-log entry on the "note" log', function () {
    $tenant = makeTenant();
    $author = makeUser('manager');

    $note = Note::create([
        'noteable_type' => MorphMap::alias(Tenant::class),
        'noteable_id' => $tenant->id,
        'author_id' => $author->id,
        'channel' => 'call',
        'subject' => 'Logged',
        'body' => 'Activity should be recorded.',
    ]);

    $logged = Activity::query()
        ->where('log_name', 'note')
        ->where('subject_type', MorphMap::alias(Note::class))
        ->where('subject_id', $note->id)
        ->exists();

    expect($logged)->toBeTrue();
});
