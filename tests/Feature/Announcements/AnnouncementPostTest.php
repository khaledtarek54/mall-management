<?php

use App\Filament\Admin\Resources\Announcements\AnnouncementResource;
use App\Filament\Admin\Resources\Announcements\Pages\CreateAnnouncement;
use App\Jobs\BroadcastAnnouncement;
use App\Models\Announcement;
use App\Models\AnnouncementRecipient;
use App\Notifications\AnnouncementNotification;
use App\Services\Announcements\MarkAnnouncementReadAction;
use App\Services\SendAnnouncementAction;
use App\Support\NotificationLocale;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| An announcement is a POST — recipients, read receipts, scheduling, language
|--------------------------------------------------------------------------
| The broadcast fan-out itself is covered by AnnouncementBroadcastTest. This
| file covers what the notice became: a durable record with a recipient list,
| a lifecycle, and two languages.
*/

/** A property with `$count` active tenants in it. Returns [asset, tenants]. */
function announcementMall(int $count = 1): array
{
    $asset = makeAsset();

    $tenants = collect(range(1, $count))->map(function () use ($asset) {
        $tenant = makeTenant();
        makeLease(makeUnit($asset), $tenant);

        return $tenant;
    });

    return [$asset, $tenants];
}

// --- the recipient list -------------------------------------------------

it('records who a notice went to, and stamps each delivery', function () {
    Notification::fake();
    [$asset, $tenants] = announcementMall(3);

    $announcement = Announcement::create([
        'asset_id' => $asset->id, 'title' => 'Fire drill', 'body' => '3pm Thursday.',
    ]);

    app(SendAnnouncementAction::class)->handle($announcement);

    expect($announcement->recipients()->count())->toBe(3)
        ->and($announcement->recipients()->whereNotNull('notified_at')->count())->toBe(3)
        ->and($announcement->recipients()->pluck('tenant_id')->sort()->values()->all())
        ->toBe($tenants->pluck('id')->sort()->values()->all());
});

it('writes the recipient rows BEFORE notifying, so a push can never outrun its post', function () {
    [$asset, $tenants] = announcementMall(1);
    $tenant = $tenants->first();

    $announcement = Announcement::create([
        'asset_id' => $asset->id, 'title' => 'Ordering', 'body' => 'x',
    ]);

    // Observed from INSIDE the send, not after it. The notification deep-links into the post,
    // and the post is only visible to a tenant who HAS a recipient row — so a row written after
    // the push would deep-link to a 404 for however long the gap lasted. Asserting afterwards
    // would pass whatever the order actually was, which is the whole failure mode.
    $rowExistedWhenNotified = false;
    Event::listen(
        NotificationSending::class,
        function (NotificationSending $event) use ($announcement, &$rowExistedWhenNotified): void {
            if ($event->notification instanceof AnnouncementNotification) {
                $rowExistedWhenNotified = $announcement->recipients()->exists();
            }
        }
    );

    app(SendAnnouncementAction::class)->handle($announcement);

    expect($rowExistedWhenNotified)->toBeTrue()
        ->and($tenant->notifications()->count())->toBe(1);
});

it('does not double-write a recipient when the send is re-run', function () {
    Notification::fake();
    [$asset] = announcementMall(2);

    $announcement = Announcement::create([
        'asset_id' => $asset->id, 'title' => 'Once', 'body' => 'x',
    ]);

    app(SendAnnouncementAction::class)->handle($announcement);
    app(SendAnnouncementAction::class)->handle($announcement); // idempotent no-op

    expect($announcement->recipients()->count())->toBe(2);
});

it('keeps the FIRST read and never resets it', function () {
    Notification::fake();
    [$asset, $tenants] = announcementMall(1);
    $tenant = $tenants->first();

    $announcement = Announcement::create(['asset_id' => $asset->id, 'title' => 'R', 'body' => 'x']);
    app(SendAnnouncementAction::class)->handle($announcement);

    $action = app(MarkAnnouncementReadAction::class);
    $action->handle($announcement, $tenant);
    $first = $announcement->recipients()->first()->read_at;

    test()->travel(2)->hours();
    $action->handle($announcement, $tenant);

    expect($announcement->recipients()->first()->read_at->timestamp)->toBe($first->timestamp);
});

it('is a no-op — not an error — for a tenant who was never a recipient', function () {
    Notification::fake();
    [$asset] = announcementMall(1);
    $stranger = makeTenant();

    $announcement = Announcement::create(['asset_id' => $asset->id, 'title' => 'R', 'body' => 'x']);
    app(SendAnnouncementAction::class)->handle($announcement);

    $result = app(MarkAnnouncementReadAction::class)
        ->handle($announcement, $stranger);

    expect($result)->toBeNull()
        ->and(AnnouncementRecipient::where('tenant_id', $stranger->id)->count())->toBe(0);
});

// --- the lifecycle ------------------------------------------------------

it('leaves a draft alone — nobody is notified until it is sent', function () {
    Notification::fake();
    [$asset, $tenants] = announcementMall(1);

    Announcement::create([
        'asset_id' => $asset->id, 'title' => 'Draft', 'body' => 'x',
        'status' => Announcement::STATUS_DRAFT,
    ]);

    Notification::assertNotSentTo($tenants->first(), AnnouncementNotification::class);
});

it('sends a scheduled notice once its time has come, and not before', function () {
    Notification::fake();
    [$asset, $tenants] = announcementMall(1);

    $announcement = Announcement::create([
        'asset_id' => $asset->id, 'title' => 'Ramadan hours', 'body' => 'x',
        'status' => Announcement::STATUS_SCHEDULED,
        'publish_at' => now()->addDay(),
    ]);

    $this->artisan('announcements:send-scheduled')->assertSuccessful();
    expect($announcement->refresh()->sent_at)->toBeNull();
    Notification::assertNotSentTo($tenants->first(), AnnouncementNotification::class);

    test()->travel(25)->hours();

    $this->artisan('announcements:send-scheduled')->assertSuccessful();

    $fresh = $announcement->refresh();
    expect($fresh->sent_at)->not->toBeNull()
        ->and($fresh->status)->toBe(Announcement::STATUS_SENT)
        ->and($fresh->recipients_count)->toBe(1);
    Notification::assertSentTo($tenants->first(), AnnouncementNotification::class);
});

it('does not re-send a scheduled notice on the next sweep', function () {
    Notification::fake();
    [$asset] = announcementMall(1);

    $announcement = Announcement::create([
        'asset_id' => $asset->id, 'title' => 'Once', 'body' => 'x',
        'status' => Announcement::STATUS_SCHEDULED,
        'publish_at' => now()->subMinute(),
    ]);

    $this->artisan('announcements:send-scheduled')->assertSuccessful();
    $sentAt = $announcement->refresh()->sent_at;

    $this->artisan('announcements:send-scheduled')->assertSuccessful();

    expect($announcement->refresh()->sent_at->timestamp)->toBe($sentAt->timestamp);
    Notification::assertSentTimes(AnnouncementNotification::class, 1);
});

it('writes nothing on a dry run', function () {
    Notification::fake();
    [$asset] = announcementMall(1);

    $announcement = Announcement::create([
        'asset_id' => $asset->id, 'title' => 'Dry', 'body' => 'x',
        'status' => Announcement::STATUS_SCHEDULED,
        'publish_at' => now()->subMinute(),
    ]);

    $this->artisan('announcements:send-scheduled', ['--dry-run' => true])->assertSuccessful();

    expect($announcement->refresh()->sent_at)->toBeNull();
    Notification::assertNothingSent();
});

it('refuses to edit a notice once it is sent, and allows it while it is a draft', function () {
    Notification::fake();
    [$asset] = announcementMall(1);

    $draft = Announcement::create([
        'asset_id' => $asset->id, 'title' => 'Draft', 'body' => 'x',
        'status' => Announcement::STATUS_DRAFT,
    ]);
    $sent = Announcement::create(['asset_id' => $asset->id, 'title' => 'Sent', 'body' => 'x']);
    app(SendAnnouncementAction::class)->handle($sent);

    // The predicate asserted directly. Neither callAction() nor mountAction can distinguish a
    // gate from its absence here — both refuse a hidden action for reasons of their own.
    expect($draft->isEditable())->toBeTrue()
        ->and($sent->refresh()->isEditable())->toBeFalse();
});

// --- language -----------------------------------------------------------

it('reaches an Arabic reader in Arabic and an English one in English, from one broadcast', function () {
    [$asset, $tenants] = announcementMall(1);
    $tenant = $tenants->first();

    $announcement = Announcement::create([
        'asset_id' => $asset->id,
        'title' => 'Trading hours', 'title_ar' => 'مواعيد العمل',
        'body' => 'We open late this week.', 'body_ar' => 'نفتح متأخرًا هذا الأسبوع.',
    ]);

    app(SendAnnouncementAction::class)->handle($announcement);

    $row = $tenant->notifications()->first();
    expect($row)->not->toBeNull();

    $en = NotificationLocale::localize($row->data, 'en');
    $ar = NotificationLocale::localize($row->data, 'ar');

    expect($en['title'])->toBe('Trading hours')
        ->and($ar['title'])->toBe('مواعيد العمل')
        ->and($ar['body'])->toContain('نفتح متأخرًا');
});

it('falls back to the language the operator actually wrote', function () {
    [$asset, $tenants] = announcementMall(1);

    $announcement = Announcement::create([
        'asset_id' => $asset->id, 'title' => 'English only', 'body' => 'No Arabic was typed.',
    ]);
    app(SendAnnouncementAction::class)->handle($announcement);

    $row = $tenants->first()->notifications()->first();

    // Blank Arabic must not produce a blank notice — that would hide the message from exactly
    // the readers the bilingual columns exist for.
    expect(NotificationLocale::localize($row->data, 'ar')['title'])->toBe('English only');
});

it('carries the announcement id so a tap opens the post', function () {
    [$asset, $tenants] = announcementMall(1);

    $announcement = Announcement::create(['asset_id' => $asset->id, 'title' => 'Tap me', 'body' => 'x']);
    app(SendAnnouncementAction::class)->handle($announcement);

    $data = $tenants->first()->notifications()->first()->data;

    expect($data['announcement_id'])->toBe($announcement->id)
        ->and($data['announcement_category'])->toBe(Announcement::CATEGORY_GENERAL);
});

// --- RBAC ---------------------------------------------------------------

it('splits sending from composing', function () {
    $this->seed(RolesPermissionsSeeder::class);

    // marketing owns tenant comms end to end.
    $this->actingAs(makeUser('marketing'));
    expect(AnnouncementResource::canCreate())->toBeTrue()
        ->and(AnnouncementResource::canSend())->toBeTrue();

    // leasing holds neither — the control that proves the assertions above are not vacuous.
    $this->actingAs(makeUser('leasing'));
    expect(AnnouncementResource::canCreate())->toBeFalse()
        ->and(AnnouncementResource::canSend())->toBeFalse();
});

// --- media --------------------------------------------------------------

it('keeps notice artwork on the private disk', function () {
    $announcement = Announcement::create([
        'asset_id' => makeAsset()->id, 'title' => 'With art', 'body' => 'x',
    ]);

    // A tenant notice can be an evacuation map. Registering it on the public disk would put it
    // in the webroot, enumerable by media id — medialibrary's default is fail-open.
    expect($announcement->getMediaCollection(Announcement::HERO_COLLECTION)->diskName)->toBe('local');
});

// --- the compose screen -------------------------------------------------

it('broadcasts when the operator chooses "Send now", and stays silent for a draft', function () {
    $this->seed(RolesPermissionsSeeder::class);
    Notification::fake();
    Queue::fake();

    [$asset] = announcementMall(1);
    $this->actingAs(makeUser('marketing', [$asset->id]));
    Filament::setTenant($asset);

    Livewire::test(CreateAnnouncement::class)
        ->fillForm([
            'asset_id' => $asset->id,
            'category' => Announcement::CATEGORY_HOURS,
            'title' => 'Eid hours', 'body' => 'We open at 10.',
            'delivery' => 'now',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    Queue::assertPushed(BroadcastAnnouncement::class);

    Livewire::test(CreateAnnouncement::class)
        ->fillForm([
            'asset_id' => $asset->id,
            'category' => Announcement::CATEGORY_GENERAL,
            'title' => 'Later', 'body' => 'Not yet.',
            'delivery' => 'draft',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    // Still ONE dispatch — the draft added none.
    Queue::assertPushed(BroadcastAnnouncement::class, 1);

    $draft = Announcement::where('title', 'Later')->firstOrFail();
    expect($draft->status)->toBe(Announcement::STATUS_DRAFT)
        ->and($draft->publish_at)->toBeNull();
});

it('stores a scheduled notice with its time, and dispatches nothing yet', function () {
    $this->seed(RolesPermissionsSeeder::class);
    Notification::fake();
    Queue::fake();

    [$asset] = announcementMall(1);
    $this->actingAs(makeUser('marketing', [$asset->id]));
    Filament::setTenant($asset);

    $at = now()->addDays(3)->startOfHour();

    Livewire::test(CreateAnnouncement::class)
        ->fillForm([
            'asset_id' => $asset->id,
            'category' => Announcement::CATEGORY_EVENT,
            'title' => 'Ramadan hours', 'body' => 'From the 1st.',
            'delivery' => 'schedule',
            'publish_at' => $at,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    Queue::assertNotPushed(BroadcastAnnouncement::class);

    $scheduled = Announcement::where('title', 'Ramadan hours')->firstOrFail();
    expect($scheduled->status)->toBe(Announcement::STATUS_SCHEDULED)
        ->and($scheduled->publish_at?->timestamp)->toBe($at->timestamp)
        ->and($scheduled->sent_at)->toBeNull();
});
