<?php

use App\Filament\Portal\Resources\Announcements\AnnouncementResource;
use App\Filament\Portal\Resources\Announcements\Pages\ListAnnouncements;
use App\Filament\Portal\Resources\Announcements\Pages\ViewAnnouncement;
use App\Models\Announcement;
use App\Models\Asset;
use App\Services\Announcements\MarkAnnouncementReadAction;
use App\Services\SendAnnouncementAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| /portal — the retailer's notice board
|--------------------------------------------------------------------------
| The web twin of the mobile feed. Same predicate, so the two cannot disagree
| about which notices a retailer was sent.
*/

beforeEach(function (): void {
    // Without this the row actions resolve against the ADMIN panel and try to build
    // /admin/{tenant}/… URLs for a portal page. Restored afterwards so the panel does not leak
    // into the next file's expectations.
    Filament::setCurrentPanel(Filament::getPanel('portal'));
});

afterEach(fn () => Filament::setCurrentPanel(Filament::getPanel('admin')));

/** A retailer in `$asset`, signed in to the portal. Returns [tenantUser, tenant]. */
function noticeBoardActor(Asset $asset, bool $isAdmin = true): array
{
    $tenant = makeTenant();
    makeLease(makeUnit($asset), $tenant, ['status' => 'active']);

    $user = makeTenantUser($tenant, $isAdmin);
    test()->actingAs($user, 'portal');

    return [$user, $tenant];
}

/** A notice broadcast to everyone active in `$asset`. */
function broadcastNotice(Asset $asset, array $attrs = []): Announcement
{
    Notification::fake();

    $announcement = Announcement::create(array_merge([
        'asset_id' => $asset->id, 'title' => 'Loading bay closed', 'body' => 'Friday, all day.',
    ], $attrs));

    app(SendAnnouncementAction::class)->handle($announcement);

    return $announcement->refresh();
}

it('lists the notices this retailer was sent', function () {
    $asset = makeAsset();
    [, $tenant] = noticeBoardActor($asset);
    $notice = broadcastNotice($asset);

    Livewire::test(ListAnnouncements::class)
        ->assertCanSeeTableRecords([$notice]);
});

it('never lists another mall\'s notice', function () {
    $asset = makeAsset();
    noticeBoardActor($asset);
    $mine = broadcastNotice($asset, ['title' => 'Mine']);

    // Another mall, its own tenant, its own notice — the signed-in retailer is not a recipient.
    $otherAsset = makeAsset();
    $otherTenant = makeTenant();
    makeLease(makeUnit($otherAsset), $otherTenant);
    $theirs = broadcastNotice($otherAsset, ['title' => 'Theirs']);

    // The positive control travels with the refusal: a scoping bug that returned NOTHING would
    // satisfy assertCanNotSeeTableRecords on its own.
    Livewire::test(ListAnnouncements::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('never lists a notice that has expired, and keeps a standing one', function () {
    $asset = makeAsset();
    noticeBoardActor($asset);

    $standing = broadcastNotice($asset, ['title' => 'Standing']);
    $expired = broadcastNotice($asset, ['title' => 'Gone', 'expires_at' => now()->subDay()]);

    Livewire::test(ListAnnouncements::class)
        ->assertCanSeeTableRecords([$standing])
        ->assertCanNotSeeTableRecords([$expired]);
});

it('records the read receipt — and WHO read it — when the retailer opens the notice', function () {
    $asset = makeAsset();
    [$user, $tenant] = noticeBoardActor($asset);
    $notice = broadcastNotice($asset);

    expect($notice->recipients()->where('tenant_id', $tenant->id)->first()->read_at)->toBeNull();

    Livewire::test(ViewAnnouncement::class, ['record' => $notice->getKey()])
        ->assertSuccessful();

    $receipt = $notice->recipients()->where('tenant_id', $tenant->id)->first();

    expect($receipt->read_at)->not->toBeNull()
        // The portal knows which login is looking; the mobile API never does, which is why that
        // column is nullable and why this assertion belongs here rather than in the API test.
        ->and($receipt->read_by_tenant_user_id)->toBe($user->id);
});

it('badges the unread count, and drops the badge once everything is read', function () {
    $asset = makeAsset();
    [, $tenant] = noticeBoardActor($asset);
    $notice = broadcastNotice($asset);

    expect(AnnouncementResource::getNavigationBadge())->toBe('1');

    app(MarkAnnouncementReadAction::class)->handle($notice, $tenant);

    expect(AnnouncementResource::getNavigationBadge())->toBeNull();
});

it('gives a retailer no way to write', function () {
    $asset = makeAsset();
    noticeBoardActor($asset);
    $notice = broadcastNotice($asset);

    // A notice is the mall's record. Asserted on the predicates directly — a missing button
    // proves nothing about what a crafted request can reach.
    expect(AnnouncementResource::canCreate())->toBeFalse()
        ->and(AnnouncementResource::canEdit($notice))->toBeFalse()
        ->and(AnnouncementResource::canDelete($notice))->toBeFalse();
});

it('shows nothing at all to a session with no tenant', function () {
    $asset = makeAsset();
    noticeBoardActor($asset);
    $notice = broadcastNotice($asset);

    // Control: signed in, the notice IS visible.
    expect(AnnouncementResource::getEloquentQuery()->count())->toBe(1);

    auth()->guard('portal')->logout();

    // The failure mode of a null tenant id must be "see nothing", never "see everything".
    expect(AnnouncementResource::getEloquentQuery()->count())->toBe(0)
        ->and($notice->exists)->toBeTrue();
});
