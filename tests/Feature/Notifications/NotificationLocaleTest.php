<?php

/*
|--------------------------------------------------------------------------
| Notifications are read in the READER's language, not the sender's
|--------------------------------------------------------------------------
| Every toDatabase() in this project builds its title and body with `__()`,
| which renders in whatever locale is current at the moment the alert is
| RAISED. That is the wrong moment, and it failed both ways round:
|
|   - a scheduled command has no session, so config('app.locale') decided —
|     every overdue-invoice alert, SLA breach and expiring document reached an
|     Arabic-only retailer in English;
|   - an alert raised inside a request rendered in the SENDER's language, so an
|     operator working in Arabic issued invoices whose emails arrived in Arabic
|     for tenants reading English.
|
| Two mechanisms answer it, and both are asserted here:
|
|   1. HasLocalePreference on the notifiables — Laravel wraps each recipient's
|      dispatch in withLocale(), which fixes the DELIVERED channels (mail, push)
|      and the row as first written;
|   2. the stored payload carries EVERY language, because a bell entry is not
|      delivered, it is re-read — possibly months later, possibly after the
|      reader has switched language, which no single rendered string can answer.
*/

use App\Filament\Admin\Resources\Invoices\InvoiceResource;
use App\Http\Resources\Api\V1\NotificationResource;
use App\Models\OwnerRequest;
use App\Notifications\DepartmentMessageNotification;
use App\Notifications\InvoiceIssuedNotification;
use App\Notifications\OwnerRequestNotification;
use App\Notifications\TenantRequestSlaBreachedNotification;
use App\Notifications\TenantResetPasswordNotification;
use App\Support\NotificationLink;
use App\Support\NotificationLocale;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification as NotificationFacade;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    NotificationLink::flushCache();

    $this->asset = makeAsset(['code' => 'LOCALE']);
    $this->unit = makeUnit($this->asset);
    $this->tenant = makeTenant(['name' => 'Haya Cafe']);
    $this->lease = makeLease($this->unit, $this->tenant);
    $this->invoice = makeInvoice($this->lease);

    $this->operator = makeUser('manager', [$this->asset->id]);
});

/** The stored payload of the newest bell row. */
function bellData(object $notifiable): array
{
    return $notifiable->notifications()->latest('created_at')->first()?->data ?? [];
}

it('stores the alert in every language it ships, from ONE send', function () {
    $this->operator->notify(new InvoiceIssuedNotification($this->invoice));

    $data = bellData($this->operator);

    expect($data)->toHaveKey(NotificationLocale::KEY);
    expect(array_keys($data[NotificationLocale::KEY]))->toEqualCanonicalizing(['en', 'ar']);

    $en = $data[NotificationLocale::KEY]['en'];
    $ar = $data[NotificationLocale::KEY]['ar'];

    expect($en['title'])->toBe(__('admin.notifications.invoice_issued_title', [], 'en'));
    expect($ar['title'])->toBe(__('admin.notifications.invoice_issued_title', [], 'ar'));
    // If these matched, the "both languages" claim would be true and empty.
    expect($en['title'])->not->toBe($ar['title']);
});

it('reads the SAME row differently for an English reader and an Arabic one', function () {
    // The whole point. One stored notification, two readers, two languages — without either of
    // them re-sending anything.
    $this->operator->notify(new InvoiceIssuedNotification($this->invoice));
    $data = bellData($this->operator);

    App::setLocale('en');
    $english = NotificationLocale::localize($data)['title'];

    App::setLocale('ar');
    $arabic = NotificationLocale::localize($data)['title'];

    expect($english)->toBe(__('admin.notifications.invoice_issued_title', [], 'en'));
    expect($arabic)->toBe(__('admin.notifications.invoice_issued_title', [], 'ar'));
    expect($english)->not->toBe($arabic);
});

it('localizes the action label too, not only the text above it', function () {
    $this->operator->notify(new InvoiceIssuedNotification($this->invoice));
    $data = bellData($this->operator);

    App::setLocale('ar');
    $localized = NotificationLocale::localize($data);

    expect($localized['actions'][0]['label'])->toBe(__('admin.notifications.actions.open_named', [
        'name' => InvoiceResource::getModelLabel(),
    ], 'ar'));

    // The URL is the same sentence in every language — only the noun changed.
    expect($localized['actions'][0]['url'])->toBe($data['actions'][0]['url']);
});

it('renders a nightly sweep\'s alert in the recipient\'s language, not the app default', function () {
    // The failure this whole change is about. A scheduled command runs with no session at all, so
    // `config('app.locale')` used to decide for every reader on the portfolio.
    expect(config('app.locale'))->toBe('en');

    $this->operator->forceFill(['locale' => 'ar'])->save();
    App::setLocale('en');   // …as a console run would be

    $this->operator->notify(new InvoiceIssuedNotification($this->invoice));

    // Laravel wraps the dispatch in withLocale($notifiable->preferredLocale()), so the row is
    // WRITTEN in Arabic — the `i18n` block is belt to that braces, not a substitute for it.
    expect(bellData($this->operator)['title'])
        ->toBe(__('admin.notifications.invoice_issued_title', [], 'ar'));

    // …and the ambient locale is left exactly as it was found.
    expect(App::getLocale())->toBe('en');
});

it('emails the recipient in their language, not the sender\'s', function () {
    Mail::fake();

    $reader = makeUser('accounting', [$this->asset->id]);
    $reader->forceFill(['locale' => 'ar'])->save();

    // An operator working in English raises it…
    App::setLocale('en');
    $reader->notify(new TenantRequestSlaBreachedNotification(makeTenantRequest([
        'asset' => $this->asset,
    ])));

    // …and the Arabic reader's bell row is Arabic.
    expect(bellData($reader)['title'])
        ->toBe(__('admin.notifications.sla_breached_title', [], 'ar'));
});

it('translates the two notifications that used to hard-code English prose', function () {
    // `'title' => 'New owner request'` and `'title' => 'Message from '.$label` — no key, no
    // catalogue, no way for a reader's language to matter.
    $request = OwnerRequest::create([
        'asset_id' => $this->asset->id,
        'reference' => 'OR-0001',
        'subject' => 'Roof access',
        'body' => 'Please arrange access.',
        'status' => 'open',
        'priority' => 'medium',
        'created_by_user_id' => $this->operator->id,
        'recipient' => 'operator',
    ]);

    foreach (['en', 'ar'] as $locale) {
        App::setLocale($locale);

        expect((new OwnerRequestNotification($request))->toDatabase($this->operator)['title'])
            ->toBe(__('admin.notifications.owner_request_submitted_title', [], $locale));

        expect((new DepartmentMessageNotification('Lift 3 is out.', 'Facilities'))
            ->toDatabase($this->operator)['title'])
            ->toBe(__('admin.notifications.department_message_title', ['department' => 'Facilities'], $locale));
    }
});

it('translates a status INSIDE a sentence rather than interpolating it raw', function () {
    // The commonest way a "translated" string stays half-English: the sentence is a key, the value
    // dropped into it is an enum column. "أصبح OR-0001 الآن in_progress" is not Arabic.
    $request = OwnerRequest::create([
        'asset_id' => $this->asset->id,
        'reference' => 'OR-0002',
        'subject' => 'Signage',
        'body' => 'Please approve.',
        'status' => 'in_progress',
        'priority' => 'medium',
        'created_by_user_id' => $this->operator->id,
        'recipient' => 'operator',
    ]);

    App::setLocale('ar');
    $body = (new OwnerRequestNotification($request, 'updated'))->toDatabase($this->operator)['body'];

    expect($body)->toContain(__('admin.owner_requests.statuses.in_progress', [], 'ar'));
    expect($body)->not->toContain('in_progress');
});

it('renders the reset email in the recipient\'s language, body and chrome', function () {
    // The hardest case in the change: the reader is signed OUT. They cannot switch the interface
    // language to understand what they were sent, and there is no session to read one from — only
    // the preference stored on their record, which Laravel passes to withLocale() before rendering.
    // Asserted across the WHOLE email, because the body and the chrome around it come from two
    // different catalogues and only one of them was ever ours.
    $this->tenant->forceFill(['locale' => 'ar'])->save();

    $notification = new TenantResetPasswordNotification('tok3n');

    App::setLocale('ar');
    $mail = $notification->toMail($this->tenant);

    // Our own words…
    expect($mail->subject)->toBe(__('admin.email.reset_password_subject', [], 'ar'));
    expect($mail->actionText)->toBe(__('admin.email.reset_password_action', [], 'ar'));
    expect($mail->introLines[0])->toBe(__('admin.email.reset_password_intro', [], 'ar'));

    // …and none of it is the English it used to be.
    expect($mail->subject)->not->toBe('Reset your password');

    // The expiry is read from config, not written into the sentence — the old copy said "60
    // minutes" beside a configurable window.
    $minutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);
    expect($mail->outroLines[0])->toContain((string) $minutes);

    // …and the chrome Laravel wraps around it, which lived in no catalogue at all until lang/ar.json.
    expect(__('Hello!'))->toBe('مرحباً');
    expect(__('Regards,'))->toBe('مع التحية،');

    App::setLocale('en');
    expect($notification->toMail($this->tenant)->subject)->toBe('Reset your password');
    expect(__('Hello!'))->toBe('Hello!');
});

it('serves the mobile API in the language the request asked for', function () {
    $this->operator->notify(new InvoiceIssuedNotification($this->invoice));
    $row = $this->operator->notifications()->first();

    App::setLocale('ar');
    $api = (new NotificationResource($row))->toArray(request());

    expect($api['data']['title'])->toBe(__('admin.notifications.invoice_issued_title', [], 'ar'));
    // The machinery that made that possible is not the app's business.
    expect($api['data'])->not->toHaveKey(NotificationLocale::KEY);
    expect($api['data'])->not->toHaveKey('actions');
});

it('leaves a row written before this shipped exactly as it was', function () {
    // No `i18n` block = nothing to pick from. It must render, in the language it was frozen in,
    // rather than blank.
    $legacy = ['format' => 'filament', 'title' => 'Old alert', 'body' => 'Old body'];

    App::setLocale('ar');

    expect(NotificationLocale::localize($legacy))->toBe($legacy);
});

it('does not touch a database row that is not a bell alert', function () {
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

it('restores the ambient locale even when a payload throws mid-render', function () {
    // Rendering under each locale means temporarily changing a global. If an exception escaped the
    // loop, the REST of the request would carry on in Arabic — a bug that would look like anything
    // except a notification problem.
    NotificationFacade::fake();
    App::setLocale('en');

    $exploding = new class extends Notification
    {
        public function via(object $notifiable): array
        {
            return ['database'];
        }

        public function toDatabase(object $notifiable): array
        {
            if (App::getLocale() !== 'en') {
                throw new RuntimeException('boom');
            }

            return ['format' => 'filament', 'title' => 't', 'body' => 'b'];
        }
    };

    $channel = app(DatabaseChannel::class);

    try {
        $channel->send($this->operator, $exploding);
    } catch (RuntimeException) {
        // expected
    }

    expect(App::getLocale())->toBe('en');
});
