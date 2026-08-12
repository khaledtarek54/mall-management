<?php

/*
|--------------------------------------------------------------------------
| Every bell notification is clickable — and clicks into the RIGHT panel
|--------------------------------------------------------------------------
| The behaviour half of the deep-link work (the registry itself is gated by
| NotificationDeepLinkConformanceTest). What is asserted here is what an
| operator and a retailer actually get in the bell:
|
|   1. an "Open …" action exists at all, on a payload written by a real
|      service — not a hand-built array;
|   2. the SAME notification hands an operator an /admin/{property}/… URL and
|      a retailer a /portal/… one. This is the load-bearing assertion: both
|      panels host an InvoiceResource, both would answer `getUrl()`, and
|      whichever panel happened to be current would win if the panel were not
|      passed explicitly. In a scheduled command that is the DEFAULT panel —
|      i.e. every tenant would silently receive an /admin link;
|   3. the property slug is the RECORD's property, not the reader's current
|      one (there is no current one out here);
|   4. a link is withheld rather than 404'd when the reader could not open it
|      — a different property, another tenant's invoice;
|   5. the fallback: notifications with no record still get a destination, so
|      nothing in the bell is a dead end;
|   6. the link never leaks into the two payloads that are NOT the web bell —
|      the FCM push and the mobile API.
*/

use App\Filament\Admin\Resources\Invoices\InvoiceResource;
use App\Http\Resources\Api\V1\NotificationResource;
use App\Notifications\Channels\PushChannel;
use App\Notifications\DepartmentMessageNotification;
use App\Notifications\InvoiceIssuedNotification;
use App\Support\NotificationLink;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    NotificationLink::flushCache();

    $this->asset = makeAsset(['code' => 'ATRIOM']);
    $this->unit = makeUnit($this->asset);
    $this->tenant = makeTenant(['name' => 'Haya Cafe']);
    $this->lease = makeLease($this->unit, $this->tenant);
    $this->invoice = makeInvoice($this->lease);

    $this->operator = makeUser('manager', [$this->asset->id]);
    $this->portalUser = makeTenantUser($this->tenant, isAdmin: true);
});

/** The single stored action on the newest bell row for a notifiable. */
function bellAction(object $notifiable): ?array
{
    /** @var DatabaseNotification|null $row */
    $row = $notifiable->notifications()->latest('created_at')->first();

    return $row?->data['actions'][0] ?? null;
}

it('gives an operator a link into the admin panel, stamped with the record\'s own property', function () {
    $this->operator->notify(new InvoiceIssuedNotification($this->invoice));

    $action = bellAction($this->operator);

    expect($action)->not->toBeNull('the bell entry carries no action at all');
    expect($action['url'])->toContain("/admin/ATRIOM/invoices/{$this->invoice->id}");
    expect($action['url'])->not->toContain('/portal/');
    // Following the link answers the alert, so the badge must stop counting it.
    expect($action['shouldMarkAsRead'])->toBeTrue();
});

it('gives the tenant a link into the portal for the SAME notification', function () {
    $this->portalUser->notify(new InvoiceIssuedNotification($this->invoice));

    $action = bellAction($this->portalUser);

    expect($action)->not->toBeNull();
    expect($action['url'])->toContain("/portal/invoices/{$this->invoice->id}");
    // The whole point: a tenant must never be handed a panel they cannot sign into. The portal has
    // no Filament tenancy, so a property slug here would be a 404 as surely as the wrong panel is.
    expect($action['url'])->not->toContain('/admin');
    expect($action['url'])->not->toContain('ATRIOM');
});

it('labels the link with what it opens rather than a bare "Open"', function () {
    $this->operator->notify(new InvoiceIssuedNotification($this->invoice));

    expect(bellAction($this->operator)['label'])
        ->toBe(__('admin.notifications.actions.open_named', [
            'name' => InvoiceResource::getModelLabel(),
        ]));
});

it('withholds the link when the operator is not assigned to the record\'s property', function () {
    // Filament's IdentifyTenant 404s a property you are not assigned to, so a link there would read
    // as a broken system rather than as a boundary. Falls back to the centre instead.
    $outsider = makeUser('manager', [makeAsset()->id]);

    $outsider->notify(new InvoiceIssuedNotification($this->invoice));

    $action = bellAction($outsider);

    expect($action)->not->toBeNull('an unreachable record must still leave the entry clickable');
    expect($action['url'])->not->toContain('/invoices/');
    expect($action['url'])->toContain('/notifications');
});

it('withholds the link when the invoice belongs to a different tenant', function () {
    // The portal scopes every read to the signed-in tenant; a link outside that scope would land on
    // "record not found". Same rule applied one step earlier.
    $stranger = makeTenantUser(makeTenant(['name' => 'Rival Co']), isAdmin: true);

    $stranger->notify(new InvoiceIssuedNotification($this->invoice));

    expect(bellAction($stranger)['url'])->not->toContain("/invoices/{$this->invoice->id}");
});

it('still gives a destination to a notification with no record behind it', function () {
    $this->operator->notify(new DepartmentMessageNotification('Roof crew on site at 6am.', 'Facilities'));

    $action = bellAction($this->operator);

    expect($action)->not->toBeNull('a bell entry with nowhere to go is the dead end this work removed');
    expect($action['label'])->toBe(__('admin.notifications.actions.details'));
    expect($action['url'])->toContain('/admin/ATRIOM/notifications');
});

it('is a property of the CHANNEL, so a notification class needs no change to gain one', function () {
    // toDatabase() writes no `actions` key anywhere in app/Notifications — the URL is attached by
    // BellChannel on the way to the database. If this ever inverts, the 36 classes are back to
    // remembering it one at a time.
    $payload = (new InvoiceIssuedNotification($this->invoice))->toDatabase($this->operator);

    expect($payload)->not->toHaveKey('actions');
});

it('never lets the web URL reach the push payload or the mobile API', function () {
    $this->portalUser->notify(new InvoiceIssuedNotification($this->invoice));
    $row = $this->portalUser->notifications()->first();

    // The mobile app has no session in either web panel; a link it cannot open is worse than none.
    $api = (new NotificationResource($row))->toArray(request());
    expect($api['data'])->not->toHaveKey('actions');

    // Push is built from toDatabase() directly and never passes through BellChannel, so this is a
    // guard against someone "helpfully" moving the action back into the payload.
    $push = (new class extends PushChannel
    {
        public function expose(object $n, $notification): array
        {
            return $this->payload($n, $notification)['data'];
        }
    })->expose($this->tenant, new InvoiceIssuedNotification($this->invoice));

    expect($push)->not->toHaveKey('actions');
});

it('leaves non-bell database rows exactly as the notification wrote them', function () {
    // Only `format: filament` payloads are Filament's to render. Anything else on the database
    // channel is a plain record and must come back byte-identical.
    $notification = new class extends Notification
    {
        public function via(object $notifiable): array
        {
            return ['database'];
        }

        public function toDatabase(object $notifiable): array
        {
            return ['type' => 'plain', 'note' => 'no bell'];
        }
    };

    $this->operator->notify($notification);

    expect($this->operator->notifications()->first()->data)->toBe(['type' => 'plain', 'note' => 'no bell']);
});

it('drops the dead `url` key six payloads used to carry', function () {
    // Nothing has ever read it. Leaving it in the stored row would keep advertising a mechanism
    // that does not exist beside the one that now does.
    $this->operator->notify(new DepartmentMessageNotification('Lift 3 is out.', 'Facilities'));

    expect($this->operator->notifications()->first()->data)->not->toHaveKey('url');
});

it('resolves the property through the isolation registry, not an asset_id column', function () {
    // Invoice has no asset_id — it reaches its property via `lease.unit`, which is exactly what
    // PropertyIsolation::OWNED already declares. Asserting the slug proves the chain was walked.
    expect($this->invoice->getAttributes())->not->toHaveKey('asset_id');

    $this->operator->notify(new InvoiceIssuedNotification($this->invoice));

    expect(bellAction($this->operator)['url'])->toContain('/admin/ATRIOM/');
});

it('gives no link to a notifiable that belongs to neither panel', function () {
    // A notifiable with no bell (an Asset, a Vendor) must not be handed one panel's URL by default.
    expect(NotificationLink::panelFor($this->asset))->toBeNull();
    expect(NotificationLink::centre($this->asset))->toBeNull();
});

it('memoises the reader\'s home property across a fan-out', function () {
    // A single sweep (`vendors:scan-document-expiry`) notifies the same operators once per expiring
    // document. Resolving their home property from the asset_user ∪ asset_owner join every time
    // would be a query per notification, on the hot path of exactly the commands that fan out most.
    NotificationLink::flushCache();

    $joins = 0;
    DB::listen(function ($query) use (&$joins) {
        if (str_contains($query->sql, 'asset_user')) {
            $joins++;
        }
    });

    foreach (range(1, 3) as $ignored) {
        $this->operator->notify(new DepartmentMessageNotification('ping', 'Facilities'));
    }

    expect($joins)->toBeLessThanOrEqual(1);
});
