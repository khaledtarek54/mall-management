<?php

use App\Models\Payment;
use App\Notifications\PaymentReceivedNotification;
use App\Services\Push\FcmPushSender;
use App\Services\Push\NullPushSender;
use App\Services\Push\PushSender;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** Records send() calls so we can assert the fan-out without a real provider. */
class RecordingPushSender implements PushSender
{
    /** @var array<int, array<string, mixed>> */
    public array $calls = [];

    /** @var array<int, string> tokens to report back as permanently dead. */
    public array $dead = [];

    public function send(array $tokens, string $title, string $body, array $data = []): array
    {
        $this->calls[] = compact('tokens', 'title', 'body', 'data');

        return array_values(array_intersect($tokens, $this->dead));
    }
}

/** A minimal notification routed only to push, with a bell-style payload. */
class PushTestNotification extends Notification
{
    public function via(object $notifiable): array
    {
        return ['push'];
    }

    public function toDatabase(object $notifiable): array
    {
        return ['title' => 'Hi there', 'body' => 'Body text', 'invoice_id' => 7,
            'icon' => 'heroicon-o-bell', 'color' => 'success', 'format' => 'filament'];
    }
}

it('fans a push notification out to the tenant\'s device tokens, reusing the bell payload', function () {
    $fake = new RecordingPushSender;
    $this->app->instance(PushSender::class, $fake);

    $tenant = makeTenant();
    $tenant->deviceTokens()->create(['platform' => 'android', 'token' => 'tok-A', 'device_name' => 'A']);
    $tenant->deviceTokens()->create(['platform' => 'ios', 'token' => 'tok-B', 'device_name' => 'B']);

    $tenant->notify(new PushTestNotification);

    expect($fake->calls)->toHaveCount(1);
    $call = $fake->calls[0];
    expect($call['tokens'])->toEqualCanonicalizing(['tok-A', 'tok-B'])
        ->and($call['title'])->toBe('Hi there')
        ->and($call['body'])->toBe('Body text')
        // id fields carried for deep-linking; bell render-hints dropped.
        ->and($call['data'])->toHaveKey('invoiceId')
        ->and($call['data'])->not->toHaveKeys(['icon', 'color', 'format', 'title', 'body']);
});

it('ships the push payload in the app wire contract: camelCase keys + the inbox type', function () {
    // Push is an outbound FCM call, so it never passes through the
    // CamelCaseResponseKeys middleware that every /api/v1 response does. If this
    // regresses, the app reads data['invoiceId'] against our `invoice_id` and
    // EVERY push tap deep-links to a null id (silently — nothing throws).
    $fake = new RecordingPushSender;
    $this->app->instance(PushSender::class, $fake);

    $tenant = makeTenant();
    $tenant->deviceTokens()->create(['platform' => 'android', 'token' => 'tok-A', 'device_name' => 'A']);

    $tenant->notify(new PushTestNotification);

    $data = $fake->calls[0]['data'];
    expect($data)->toHaveKey('invoiceId')
        ->and($data)->not->toHaveKey('invoice_id')
        ->and($data['invoiceId'])->toBe(7)
        // The app maps a push tap with the SAME mapper as an inbox tap, so `type`
        // must match NotificationResource::type (class_basename), not a slug.
        ->and($data['type'])->toBe('PushTestNotification');
});

it('does not push when the tenant has no registered devices', function () {
    $fake = new RecordingPushSender;
    $this->app->instance(PushSender::class, $fake);

    makeTenant()->notify(new PushTestNotification);

    expect($fake->calls)->toBeEmpty();
});

it('prunes device tokens the provider reports as permanently dead, keeping live ones', function () {
    $fake = new RecordingPushSender;
    $fake->dead = ['tok-dead']; // provider says this one is gone (uninstalled/expired)
    $this->app->instance(PushSender::class, $fake);

    $tenant = makeTenant();
    $tenant->deviceTokens()->create(['platform' => 'android', 'token' => 'tok-dead', 'device_name' => 'A']);
    $tenant->deviceTokens()->create(['platform' => 'ios', 'token' => 'tok-live', 'device_name' => 'B']);

    $tenant->notify(new PushTestNotification); // sync queue → SendPushNotification runs inline

    expect($tenant->deviceTokens()->pluck('token')->all())->toBe(['tok-live']);
});

it('binds the no-op NullPushSender by default (push works with zero credentials)', function () {
    expect(app(PushSender::class))->toBeInstanceOf(NullPushSender::class);

    $tenant = makeTenant();
    $tenant->deviceTokens()->create(['platform' => 'android', 'token' => 'x', 'device_name' => 'A']);
    $tenant->notify(new PushTestNotification); // must not throw

    expect($tenant->deviceTokens()->count())->toBe(1);
});

it('routes the tenant-facing notifications through the push channel', function () {
    $via = (new PaymentReceivedNotification(new Payment))->via(makeTenant());

    expect($via)->toContain('push');
});

it('FcmPushSender authenticates via service-account JWT and POSTs to FCM v1', function () {
    // Throwaway service account (real RSA key so openssl_sign succeeds).
    openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]), $pem);
    $path = tempnam(sys_get_temp_dir(), 'fcm');
    file_put_contents($path, json_encode([
        'client_email' => 'svc@proj.iam.gserviceaccount.com',
        'private_key' => $pem,
        'token_uri' => 'https://oauth2.googleapis.com/token',
        'project_id' => 'my-proj',
    ]));
    Cache::flush();

    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3600]),
        'fcm.googleapis.com/*' => Http::response(['name' => 'projects/my-proj/messages/1']),
    ]);

    (new FcmPushSender($path, null))->send(['device-token-1'], 'Title', 'Body', ['invoice_id' => 7]);

    Http::assertSent(fn ($req) => str_contains($req->url(), 'oauth2.googleapis.com/token'));
    Http::assertSent(fn ($req) => str_contains($req->url(), 'projects/my-proj/messages:send')
        && $req->hasHeader('Authorization', 'Bearer ya29.test')
        && $req['message']['token'] === 'device-token-1'
        && $req['message']['notification']['title'] === 'Title'
        && $req['message']['data']['invoice_id'] === '7'); // coerced to string

    @unlink($path);
});

it('FcmPushSender reports 404/UNREGISTERED tokens as dead (for pruning) but keeps transient failures', function () {
    openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]), $pem);
    $path = tempnam(sys_get_temp_dir(), 'fcm');
    file_put_contents($path, json_encode([
        'client_email' => 'svc@proj.iam.gserviceaccount.com',
        'private_key' => $pem,
        'token_uri' => 'https://oauth2.googleapis.com/token',
        'project_id' => 'my-proj',
    ]));
    Cache::flush();

    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3600]),
        // Respond per-token off the request body (pool ordering is not guaranteed):
        // dead → 404 UNREGISTERED, flaky → 503 (transient), live → 200.
        'fcm.googleapis.com/*' => fn ($request) => match ($request['message']['token'] ?? null) {
            't-dead' => Http::response(['error' => ['status' => 'UNREGISTERED', 'details' => [['errorCode' => 'UNREGISTERED']]]], 404),
            't-flaky' => Http::response(['error' => ['status' => 'UNAVAILABLE']], 503),
            default => Http::response(['name' => 'projects/my-proj/messages/1'], 200),
        },
    ]);

    $dead = (new FcmPushSender($path, null))->send(['t-dead', 't-live', 't-flaky'], 'Title', 'Body');

    // Only the permanently-gone token is returned; the 503 stays put for retry.
    expect($dead)->toBe(['t-dead']);

    @unlink($path);
});
