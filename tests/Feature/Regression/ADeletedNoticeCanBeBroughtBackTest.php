<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\Announcements\Pages\ViewAnnouncement;
use App\Models\Announcement;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * A SOFT-DELETED NOTICE WAS A DEAD END.
 *
 * `AnnouncementsTable` ships a `TrashedFilter`, so a deleted announcement can be FOUND — and
 * nothing anywhere could put it back. Measured: `grep -rl TrashedFilter app/Filament` returns 33
 * files and `grep -rl RestoreAction app/Filament` returns 15 Edit pages; this resource is in the
 * first list and not the second.
 *
 * It is not a cosmetic gap, because an announcement is TENANT-FACING: the portal list and
 * `GET /api/v1/me/announcements` both read the model under its ordinary soft-delete scope, so
 * deleting one silently retracts a broadcast from every retailer's feed with no way back. A SENT
 * notice is the one worth recovering and the one the peers' placement could not reach —
 * `AnnouncementResource::canEdit()` refuses a sent notice outright, so its Edit page does not open.
 * The act therefore lives on the VIEW page, which is reachable for every notice.
 *
 * `ForceDeleteAction` is deliberately NOT offered beside it:
 * `announcement_recipients.announcement_id` is `cascadeOnDelete`, so purging a sent notice destroys
 * the record of who received it — the evidence that screen exists to show.
 */
afterEach(fn () => Filament::setTenant(null, isQuiet: true));

beforeEach(function (): void {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();

    $this->asset = makeAsset(['code' => 'ANN-REST']);
    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);

    $this->notice = Announcement::create([
        'asset_id' => $this->asset->id,
        'title' => 'Ramadan trading hours',
        'body' => 'The mall trades until 02:00 for the whole of Ramadan.',
        'category' => Announcement::CATEGORY_HOURS,
        'status' => Announcement::STATUS_SENT,
        'sent_at' => now(),
        'recipients_count' => 12,
    ]);
});

it('offers no restore on a notice that is not deleted', function (): void {
    // The control. A restore button on a live record is noise, and an assertion that only ever
    // looked for the button's presence would pass on one that is always there.
    Livewire::test(ViewAnnouncement::class, ['record' => $this->notice->getKey()])
        ->assertActionHidden('restore');
});

it('brings a deleted notice back from its own page', function (): void {
    $this->notice->delete();

    // What the deletion did: the notice is gone from every ordinary read, which is the portal's
    // and the mobile API's read.
    expect(Announcement::query()->whereKey($this->notice->getKey())->exists())->toBeFalse();

    Livewire::test(ViewAnnouncement::class, ['record' => $this->notice->getKey()])
        ->assertActionVisible('restore')
        ->callAction('restore');

    expect(Announcement::query()->whereKey($this->notice->getKey())->exists())->toBeTrue()
        ->and($this->notice->fresh()->trashed())->toBeFalse()
        // A sent notice is exactly the case the peers' Edit-page placement could not reach.
        ->and($this->notice->fresh()->status)->toBe(Announcement::STATUS_SENT);
});

it('does not offer to purge one', function (): void {
    $this->notice->delete();

    // The deliberate departure from the thirteen pages that offer one, pinned so it is not
    // "tidied up" into symmetry: the recipient rows cascade, and they are the evidence that the
    // broadcast happened.
    Livewire::test(ViewAnnouncement::class, ['record' => $this->notice->getKey()])
        ->assertActionDoesNotExist('forceDelete');
});
