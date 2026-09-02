<?php

use App\Models\Announcement;
use App\Services\SendAnnouncementAction;
use Illuminate\Support\Facades\Notification;

/*
|--------------------------------------------------------------------------
| Mall news on mobile — GET /me/announcements
|--------------------------------------------------------------------------
| The read surface an announcement never had. Every absence assertion below is
| paired with a positive control, because a scoping bug and an empty response
| are indistinguishable otherwise — and a feed test that passes on nothing is
| the exact shape of a false pass.
*/

/**
 * A property with one tenant in it, and a notice broadcast to them.
 *
 * Returns [asset, tenant, announcement]. Named `announcementFor` rather than the obvious
 * `makeAnnouncement` deliberately: file-scope helpers are global in Pest, and a collision is a
 * fatal redeclaration during collection that `--parallel` hides.
 */
function announcementFor(array $attributes = [], ?callable $before = null): array
{
    Notification::fake();

    $asset = makeAsset();
    $tenant = makeTenant();
    makeLease(makeUnit($asset), $tenant);

    $announcement = Announcement::create(array_merge([
        'asset_id' => $asset->id,
        'title' => 'Roof works',
        'body' => 'Expect some noise this week.',
        'category' => Announcement::CATEGORY_OPERATIONS,
    ], $attributes));

    if ($before !== null) {
        $before($announcement);
    }

    app(SendAnnouncementAction::class)->handle($announcement);

    return [$asset, $tenant, $announcement->refresh()];
}

// --- the feed -----------------------------------------------------------

it('serves a tenant the notices they were sent', function () {
    [, $tenant, $announcement] = announcementFor();

    $this->getJson('/api/v1/me/announcements', apiHeaders($tenant))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $announcement->id)
        ->assertJsonPath('data.0.title', 'Roof works')
        ->assertJsonPath('data.0.category', 'operations')
        ->assertJsonPath('data.0.read', false);
});

it('never shows a tenant a notice sent to another property', function () {
    [, $mine] = announcementFor(['title' => 'Mine']);

    // A second mall, its own tenant, its own notice.
    $otherAsset = makeAsset();
    $otherTenant = makeTenant();
    makeLease(makeUnit($otherAsset), $otherTenant);
    app(SendAnnouncementAction::class)->handle(Announcement::create([
        'asset_id' => $otherAsset->id, 'title' => 'Theirs', 'body' => 'Not for you.',
    ]));

    $this->getJson('/api/v1/me/announcements', apiHeaders($mine))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Mine');
});

/**
 * The control for the test above: the other mall's tenant genuinely receives their own notice.
 *
 * Deliberately a SEPARATE test rather than a second call inside the one above. Laravel does not
 * rebuild the auth guard between `getJson()` calls in a single test, so a second request with a
 * different tenant's token is still answered as the FIRST tenant — which would make an isolation
 * assertion pass or fail for a reason that has nothing to do with the isolation. Same idiom the
 * rest of `tests/Feature/Api/V1` uses: one authenticated caller per test.
 */
it('does show the other mall\'s tenant their own notice', function () {
    $otherAsset = makeAsset();
    $otherTenant = makeTenant();
    makeLease(makeUnit($otherAsset), $otherTenant);
    Notification::fake();
    app(SendAnnouncementAction::class)->handle(Announcement::create([
        'asset_id' => $otherAsset->id, 'title' => 'Theirs', 'body' => 'Not for you.',
    ]));

    $this->getJson('/api/v1/me/announcements', apiHeaders($otherTenant))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Theirs');
});

it('leaves a notice out of the feed once it expires, and keeps a standing one', function () {
    [$asset, $tenant] = announcementFor(['title' => 'Standing']);

    // Sent while it was still live, and expired AFTERWARDS — which is the only way this row exists.
    // `Announcement::assertSendable()` refuses to send one that has already ended, because every
    // tenant would be pushed to a notice they cannot open and a sent notice cannot be corrected.
    // Creating it already-expired and sending it tested a state the product does not produce.
    $expired = Announcement::create([
        'asset_id' => $asset->id,
        'title' => 'Yesterday only',
        'body' => 'Over.',
        'expires_at' => now()->addDay(),
    ]);
    app(SendAnnouncementAction::class)->handle($expired);

    // Time passes. `saveQuietly()` so the expiry moves without re-running the send guards.
    $expired->forceFill(['expires_at' => now()->subDay()])->saveQuietly();

    $this->getJson('/api/v1/me/announcements', apiHeaders($tenant))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Standing');
});

it('never shows a draft or a scheduled notice', function () {
    [$asset, $tenant] = announcementFor(['title' => 'Sent']);

    Announcement::create([
        'asset_id' => $asset->id, 'title' => 'Draft', 'body' => 'x',
        'status' => Announcement::STATUS_DRAFT,
    ]);
    Announcement::create([
        'asset_id' => $asset->id, 'title' => 'Scheduled', 'body' => 'x',
        'status' => Announcement::STATUS_SCHEDULED, 'publish_at' => now()->addDay(),
    ]);

    $this->getJson('/api/v1/me/announcements', apiHeaders($tenant))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Sent');
});

it('puts a pinned notice above a newer one', function () {
    [$asset, $tenant] = announcementFor(['title' => 'Pinned', 'is_pinned' => true]);

    $newer = Announcement::create([
        'asset_id' => $asset->id, 'title' => 'Newer', 'body' => 'x',
    ]);
    app(SendAnnouncementAction::class)->handle($newer);

    $this->getJson('/api/v1/me/announcements', apiHeaders($tenant))
        ->assertOk()
        ->assertJsonPath('data.0.title', 'Pinned')
        ->assertJsonPath('data.1.title', 'Newer');
});

it('ships both languages so the client can pick', function () {
    [, $tenant] = announcementFor([
        'title' => 'Trading hours', 'title_ar' => 'مواعيد العمل',
        'body' => 'We open late.', 'body_ar' => 'نفتح متأخرًا.',
    ]);

    $this->getJson('/api/v1/me/announcements', apiHeaders($tenant))
        ->assertOk()
        ->assertJsonPath('data.0.title', 'Trading hours')
        ->assertJsonPath('data.0.titleAr', 'مواعيد العمل')
        ->assertJsonPath('data.0.bodyAr', 'نفتح متأخرًا.');
});

it('never leaks the operator\'s side of the record', function () {
    [, $tenant] = announcementFor();

    $row = $this->getJson('/api/v1/me/announcements', apiHeaders($tenant))
        ->assertOk()
        ->json('data.0');

    // The control first: the payload is genuinely populated, so the absences below mean
    // something. Without it every assertMissing passes on an empty object.
    expect($row['title'])->toBe('Roof works');

    foreach (['recipientsCount', 'createdBy', 'status', 'publishAt', 'recipients'] as $key) {
        expect($row)->not->toHaveKey($key);
    }
});

// --- the detail + read receipt ------------------------------------------

it('opens one notice in full', function () {
    [, $tenant, $announcement] = announcementFor();

    $this->getJson("/api/v1/me/announcements/{$announcement->id}", apiHeaders($tenant))
        ->assertOk()
        ->assertJsonPath('data.id', $announcement->id)
        ->assertJsonPath('data.body', 'Expect some noise this week.');
});

it('404s a notice the caller was never sent, rather than 403', function () {
    [, $mine] = announcementFor();

    $otherAsset = makeAsset();
    $otherTenant = makeTenant();
    makeLease(makeUnit($otherAsset), $otherTenant);
    $theirs = Announcement::create(['asset_id' => $otherAsset->id, 'title' => 'T', 'body' => 'B']);
    app(SendAnnouncementAction::class)->handle($theirs);

    $this->getJson("/api/v1/me/announcements/{$theirs->id}", apiHeaders($mine))
        ->assertNotFound();
});

/** The control: that same notice IS openable by its own recipient, so the 404 above is about
 *  the caller and not about a record nobody can reach. Separate test — see the note above. */
it('lets the notice\'s own recipient open it', function () {
    Notification::fake();
    $otherAsset = makeAsset();
    $otherTenant = makeTenant();
    makeLease(makeUnit($otherAsset), $otherTenant);
    $theirs = Announcement::create(['asset_id' => $otherAsset->id, 'title' => 'T', 'body' => 'B']);
    app(SendAnnouncementAction::class)->handle($theirs);

    $this->getJson("/api/v1/me/announcements/{$theirs->id}", apiHeaders($otherTenant))
        ->assertOk()
        ->assertJsonPath('data.title', 'T');
});

it('records a read receipt, and keeps the FIRST one on a re-read', function () {
    [, $tenant, $announcement] = announcementFor();

    $this->postJson("/api/v1/me/announcements/{$announcement->id}/read", [], apiHeaders($tenant))
        ->assertOk()
        ->assertJsonPath('data.read', true);

    $first = $announcement->recipients()->where('tenant_id', $tenant->id)->first()->read_at;
    expect($first)->not->toBeNull();

    $this->travel(1)->hours();

    $this->postJson("/api/v1/me/announcements/{$announcement->id}/read", [], apiHeaders($tenant))
        ->assertOk();

    $again = $announcement->recipients()->where('tenant_id', $tenant->id)->first()->read_at;
    expect($again->timestamp)->toBe($first->timestamp);
});

it('does NOT mark a notice read merely by opening its detail', function () {
    [, $tenant, $announcement] = announcementFor();

    $this->getJson("/api/v1/me/announcements/{$announcement->id}", apiHeaders($tenant))->assertOk();

    expect($announcement->recipients()->where('tenant_id', $tenant->id)->first()->read_at)->toBeNull();
});

it('filters the feed to unread', function () {
    [$asset, $tenant, $first] = announcementFor(['title' => 'First']);

    $second = Announcement::create(['asset_id' => $asset->id, 'title' => 'Second', 'body' => 'x']);
    app(SendAnnouncementAction::class)->handle($second);

    $this->postJson("/api/v1/me/announcements/{$first->id}/read", [], apiHeaders($tenant))->assertOk();

    $this->getJson('/api/v1/me/announcements?unread=1', apiHeaders($tenant))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Second');
});

it('counts unread mall news on the home summary', function () {
    [$asset, $tenant, $first] = announcementFor();

    $second = Announcement::create(['asset_id' => $asset->id, 'title' => 'Second', 'body' => 'x']);
    app(SendAnnouncementAction::class)->handle($second);

    $this->getJson('/api/v1/me/summary', apiHeaders($tenant))
        ->assertOk()
        ->assertJsonPath('data.unreadAnnouncements', 2);

    $this->postJson("/api/v1/me/announcements/{$first->id}/read", [], apiHeaders($tenant))->assertOk();

    $this->getJson('/api/v1/me/summary', apiHeaders($tenant))
        ->assertOk()
        ->assertJsonPath('data.unreadAnnouncements', 1);
});

// --- auth ---------------------------------------------------------------

it('refuses an unauthenticated caller', function () {
    $this->getJson('/api/v1/me/announcements')->assertUnauthorized();
});
