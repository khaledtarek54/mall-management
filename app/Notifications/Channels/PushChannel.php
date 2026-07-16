<?php

namespace App\Notifications\Channels;

use App\Jobs\SendPushNotification;
use App\Support\KeyCase;
use Illuminate\Notifications\Notification;

/**
 * The 'push' notification channel. Fans a notification out to the notifiable's
 * registered device tokens via the bound PushSender. Reuses the notification's
 * existing toDatabase() title/body (already localized for the in-app bell) and
 * forwards its id fields as the deep-link data payload — so adding push to a
 * notification is just appending 'push' to its via(), no extra method needed.
 * A notification may define toPush() to override.
 *
 * The actual FCM delivery is handed to {@see SendPushNotification} so the network
 * round-trip never blocks the triggering request; this channel only renders the
 * payload and resolves the target tokens.
 */
class PushChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        // Only notifiables that register device tokens get push (Tenants do;
        // TenantUsers / admin Users don't — they're skipped silently).
        if (! method_exists($notifiable, 'deviceTokens')) {
            return;
        }

        // id => token map so the delivery job can prune dead tokens by row id.
        $tokens = $notifiable->deviceTokens()->pluck('token', 'id')->filter()->all();

        if ($tokens === []) {
            return;
        }

        $payload = $this->payload($notifiable, $notification);

        if ($payload === null) {
            return;
        }

        SendPushNotification::dispatch($tokens, $payload['title'], $payload['body'], $payload['data']);
    }

    /**
     * @return array{title: string, body: string, data: array<string, mixed>}|null
     */
    protected function payload(object $notifiable, Notification $notification): ?array
    {
        if (method_exists($notification, 'toPush')) {
            $p = $notification->toPush($notifiable);
            $title = $p['title'] ?? (string) config('app.name');
            $body = (string) ($p['body'] ?? '');
            $data = $p['data'] ?? [];
        } elseif (method_exists($notification, 'toDatabase')) {
            $d = $notification->toDatabase($notifiable);
            $title = $d['title'] ?? (string) config('app.name');
            $body = (string) ($d['body'] ?? '');
            // Drop the in-app bell render hints; keep the id/reference fields
            // the app needs to deep-link on tap.
            $data = collect($d)->except(['title', 'body', 'icon', 'color', 'format', 'duration'])->all();
        } else {
            return null;
        }

        return [
            'title' => $title,
            'body' => $body,
            'data' => $this->wireData($data, $notification),
        ];
    }

    /**
     * Shape the deep-link payload to the mobile app's wire contract
     * (docs/api/MOBILE-API.md: "All JSON keys are camelCase in both directions").
     *
     * Push is an OUTBOUND call to FCM, so — unlike every /api/v1 response — it
     * never passes through CamelCaseResponseKeys. Without re-casing here we'd ship
     * `invoice_id` while the app reads `invoiceId`, and every push tap would
     * deep-link to a null id (it resolves the target from these id fields).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function wireData(array $data, Notification $notification): array
    {
        // The app routes a push tap through the SAME mapper as an inbox tap, so
        // `type` must speak the inbox's vocabulary — NotificationResource::type,
        // i.e. the short class name — not toDatabase()'s internal bell slug.
        $data['type'] = class_basename($notification);

        return KeyCase::camelKeys($data);
    }
}
