<?php

namespace App\Notifications\Channels;

use App\Jobs\SendPushNotification;
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

            return [
                'title' => $p['title'] ?? (string) config('app.name'),
                'body' => (string) ($p['body'] ?? ''),
                'data' => $p['data'] ?? [],
            ];
        }

        if (method_exists($notification, 'toDatabase')) {
            $d = $notification->toDatabase($notifiable);

            return [
                'title' => $d['title'] ?? (string) config('app.name'),
                'body' => (string) ($d['body'] ?? ''),
                // Drop the in-app bell render hints; keep the id/reference fields
                // the app needs to deep-link on tap.
                'data' => collect($d)->except(['title', 'body', 'icon', 'color', 'format', 'duration'])->all(),
            ];
        }

        return null;
    }
}
