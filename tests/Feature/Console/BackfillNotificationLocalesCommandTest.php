<?php

/*
|--------------------------------------------------------------------------
| atriom:backfill-notification-locales
|--------------------------------------------------------------------------
| BellChannel stores every alert in both languages, but only from the moment
| it shipped. Rows written before that carry one rendered string each — so an
| operator who switches to Arabic gets a fully Arabic screen with an English
| inbox on it, which reads as a broken feature rather than as an old row. That
| is exactly what it looked like on the demo data.
|
| Re-running the original toDatabase() is not possible: it needs the
| notification OBJECT, whose constructor takes models that may since have moved
| on. So the command works backwards from the text — match the stored English
| against the catalogue keys that notification class could have produced,
| recover what was substituted in, and re-render in every language.
|
| The interesting cases are all about NOT over-matching, and about knowing which
| captured values are data and which are tokens.
*/

use App\Models\MaintenanceWorkOrder;
use App\Models\User;
use App\Notifications\OwnerRequestNotification;
use App\Notifications\WorkOrderRaisedNotification;
use App\Support\NotificationLocale;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();

    $this->asset = makeAsset(['code' => 'BF']);
    $this->operator = makeUser('manager', [$this->asset->id]);
});

/** A bell row exactly as it was written BEFORE any of this existed: one language, no action. */
function legacyRow(User $user, string $type, string $title, ?string $body = null, array $extra = []): DatabaseNotification
{
    return $user->notifications()->create([
        'id' => Str::uuid()->toString(),
        'type' => $type,
        'data' => array_merge([
            'title' => $title,
            'body' => $body,
            'format' => 'filament',
            'duration' => 'persistent',
        ], $extra),
        'read_at' => null,
    ]);
}

it('gives an old row both languages without being able to re-run the notification', function () {
    $row = legacyRow($this->operator, OwnerRequestNotification::class, 'New owner request');

    $this->artisan('atriom:backfill-notification-locales --commit')->assertSuccessful();

    $data = $row->refresh()->data;

    expect($data)->toHaveKey(NotificationLocale::KEY);
    expect($data[NotificationLocale::KEY]['ar']['title'])
        ->toBe(__('admin.notifications.owner_request_submitted_title', [], 'ar'));
    expect($data[NotificationLocale::KEY]['en']['title'])->toBe('New owner request');
});

it('splits a key on its placeholders and not on every colon', function () {
    // The bug this pins. `:reference: :subject` splits into [':reference', ': ', ':subject'], and
    // that MIDDLE PART IS A LITERAL that merely starts with a colon. Reading it as a placeholder
    // collapsed the pattern to `^(.*?)(.*?)(.*?)$` — a wildcard matching every string offered, and
    // it sits earlier in the candidate list than the title's own key.
    //
    // Asserted on the CAPTURES rather than on the rendered output: a wildcard pattern still
    // "matches", so a test that only checks a translation appeared goes green while the values
    // behind it are garbage.
    //
    // Honest about what this pins: the command carries TWO guards against that collapse, and
    // mutation testing shows either alone is enough — this goes red only when both are removed.
    // It pins the OUTCOME, not one implementation of it.
    $row = legacyRow($this->operator, OwnerRequestNotification::class,
        'New owner request', 'OR-2026-0001: Facade repair budget split');

    $this->artisan('atriom:backfill-notification-locales --commit')->assertSuccessful();

    $data = $row->refresh()->data;

    // The title resolves through its OWN key, not through the body's wildcard.
    expect($data[NotificationLocale::KEY]['ar']['title'])
        ->toBe(__('admin.notifications.owner_request_submitted_title', [], 'ar'));

    // And the body's two captures land in the right slots, which is only true if the ': ' between
    // them was treated as the literal it is.
    expect($data[NotificationLocale::KEY]['ar']['body'])
        ->toBe(__('admin.notifications.owner_request_submitted_body', [
            'reference' => 'OR-2026-0001',
            'subject' => 'Facade repair budget split',
        ], 'ar'));
});

it('translates a status token inside the sentence but quotes operator data as typed', function () {
    // The old payload interpolated `$request->status` RAW, so the stored English says "…is now
    // resolved." Re-rendering the sentence without touching the capture would give an Arabic
    // sentence with a database token in it — the exact half-translated result this all exists to
    // remove. The reference is data and must survive untouched.
    $row = legacyRow($this->operator, OwnerRequestNotification::class,
        'Owner request updated', 'OR-2026-0001 is now resolved.');

    $this->artisan('atriom:backfill-notification-locales --commit')->assertSuccessful();

    $ar = $row->refresh()->data[NotificationLocale::KEY]['ar']['body'];

    expect($ar)->toContain(__('admin.owner_requests.statuses.resolved', [], 'ar'));
    expect($ar)->not->toContain('resolved');
    expect($ar)->toContain('OR-2026-0001');
});

it('gives an old row the deep link it never had', function () {
    // Those rows predate the links as well as the languages, and they are the same rows the
    // operator is looking at — backfilling one without the other leaves half the work invisible.
    $order = MaintenanceWorkOrder::create([
        'asset_id' => $this->asset->id,
        'work_order_type' => 'cm',
        'execution_type' => 'internal',
        'title' => 'Fix pump',
        'description' => 'Pump leaking',
        'category' => 'plumbing',
        'scheduled_for' => '2026-07-01',
    ]);

    $row = legacyRow($this->operator, WorkOrderRaisedNotification::class,
        'Corrective work order raised', null, ['work_order_id' => $order->id]);

    expect($row->data)->not->toHaveKey('actions');

    $this->artisan('atriom:backfill-notification-locales --commit')->assertSuccessful();

    $action = $row->refresh()->data['actions'][0] ?? null;

    expect($action)->not->toBeNull();
    expect($action['url'])->toContain('/admin/BF/');
    expect($action['shouldMarkAsRead'])->toBeTrue();
});

it('never overwrites an action a row already has', function () {
    $row = legacyRow($this->operator, OwnerRequestNotification::class, 'New owner request', null, [
        'actions' => [['name' => 'open', 'label' => 'Mine', 'url' => 'https://example.test/kept']],
    ]);

    $this->artisan('atriom:backfill-notification-locales --commit')->assertSuccessful();

    expect($row->refresh()->data['actions'][0]['url'])->toBe('https://example.test/kept');
});

it('writes nothing on a dry run, and counts what it would have done', function () {
    $row = legacyRow($this->operator, OwnerRequestNotification::class, 'New owner request');

    // Counted OUTSIDE the commit guard on purpose — a dry run that cannot report what it would do
    // is worse than none, because it is what people read before trusting the write.
    $this->artisan('atriom:backfill-notification-locales')
        ->expectsOutputToContain('1 given a link')
        ->assertSuccessful();

    expect($row->refresh()->data)->not->toHaveKey(NotificationLocale::KEY);
});

it('skips rows that already carry translations, unless asked to refresh', function () {
    $row = legacyRow($this->operator, OwnerRequestNotification::class, 'New owner request');

    $this->artisan('atriom:backfill-notification-locales --commit')->assertSuccessful();

    // Corrupt the stored variant, then prove only --refresh reaches it again.
    $data = $row->refresh()->data;
    $data[NotificationLocale::KEY]['ar']['title'] = 'STALE';
    $row->forceFill(['data' => $data])->saveQuietly();

    $this->artisan('atriom:backfill-notification-locales --commit')->assertSuccessful();
    expect($row->refresh()->data[NotificationLocale::KEY]['ar']['title'])->toBe('STALE');

    $this->artisan('atriom:backfill-notification-locales --refresh --commit')->assertSuccessful();
    expect($row->refresh()->data[NotificationLocale::KEY]['ar']['title'])
        ->toBe(__('admin.notifications.owner_request_submitted_title', [], 'ar'));
});

it('leaves a row it cannot recognise exactly as it was', function () {
    // An announcement's title IS its content, and a body whose wording has since been reworded in
    // the catalogue can no longer be matched. Both must keep showing what they showed before —
    // never blank, and never another notification's sentence.
    $row = legacyRow($this->operator, OwnerRequestNotification::class,
        'Something nobody ever wrote in a catalogue');

    $this->artisan('atriom:backfill-notification-locales --commit')->assertSuccessful();

    $data = $row->refresh()->data;

    expect($data[NotificationLocale::KEY]['ar']['title'])->toBe('Something nobody ever wrote in a catalogue');
    expect($data[NotificationLocale::KEY]['en']['title'])->toBe('Something nobody ever wrote in a catalogue');
});

it('does not touch database rows that are not bell alerts', function () {
    $row = $this->operator->notifications()->create([
        'id' => Str::uuid()->toString(),
        'type' => OwnerRequestNotification::class,
        'data' => ['type' => 'plain', 'note' => 'no bell'],
        'read_at' => null,
    ]);

    $this->artisan('atriom:backfill-notification-locales --commit')->assertSuccessful();

    expect($row->refresh()->data)->toBe(['type' => 'plain', 'note' => 'no bell']);
});

it('leaves the ambient locale where it found it', function () {
    legacyRow($this->operator, OwnerRequestNotification::class, 'New owner request');
    App::setLocale('en');

    $this->artisan('atriom:backfill-notification-locales --commit')->assertSuccessful();

    expect(App::getLocale())->toBe('en');
});
