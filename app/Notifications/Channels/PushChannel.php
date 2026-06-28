<?php

namespace App\Notifications\Channels;

use App\Services\Push\PushSender;
use Illuminate\Notifications\Notification;

/**
 * The 'push' notification channel. Fans a notification out to the notifiable's
 * registered device tokens via the bound PushSender. Reuses the notification's
 * existing toDatabase() title/body (already localized for the in-app bell) and
 * forwards its id fields as the deep-link data payload — so adding push to a
 * notification is just appending 'push' to its via(), no extra method needed.
 * A notification may define toPush() to override.
 */
class PushChannel
{
    public function __construct(private PushSender $sender) {}

    public function send(object $notifiable, Notification $notification): void
    {
        // Only notifiables that register device tokens get push (Tenants do;
        // TenantUsers / admin Users don't — they're skipped silently).
        if (! method_exists($notifiable, 'deviceTokens')) {
            return;
        }

        $tokens = $notifiable->deviceTokens()->pluck('token')->filter()->values()->all();

        if ($tokens === []) {
            return;
        }

        $payload = $this->payload($notifiable, $notification);

        if ($payload === null) {
            return;
        }

        $this->sender->send($tokens, $payload['title'], $payload['body'], $payload['data']);
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
