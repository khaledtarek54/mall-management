<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Operational notifications to a Discord webhook.
 *
 * ## Never throws, and never through OpsLog
 *
 * Every caller is already reporting that something went wrong. A poster that throws would turn a
 * failed backup into a failed *backup job*, and one that reported its own failure through `OpsLog`
 * would recurse the moment OpsLog's own Discord route is what is broken. So this swallows, and
 * writes to the plain log instead. A missing alert is bad; an alert system that takes down the
 * thing it was watching is worse.
 *
 * ## Off unless configured
 *
 * Same contract as `Turnstile`: no webhook, no attempt, no failure. That keeps the test suite and
 * every unconfigured box silent, and makes "stop the noise" a config change rather than a deploy.
 */
final class Discord
{
    public const RED = 15158332;

    public const GREEN = 3066993;

    public const AMBER = 15844367;

    public static function enabled(): bool
    {
        return filled(config('discord.webhook_url'));
    }

    /**
     * Post one embed. Returns whether it was delivered — callers may ignore it.
     *
     * @param  array<int, string>  $lines
     */
    public static function send(string $title, array $lines = [], int $color = self::AMBER): bool
    {
        if (! self::enabled()) {
            return false;
        }

        // Discord rejects an embed description over 4096 characters with a 400 and no partial
        // delivery, so a long list is truncated here rather than lost entirely at their end.
        $description = trim(implode("\n", $lines));

        if (mb_strlen($description) > 3900) {
            $description = mb_substr($description, 0, 3900)."\n… truncated";
        }

        try {
            $response = Http::timeout((int) config('discord.timeout', 5))
                ->post((string) config('discord.webhook_url'), [
                    'username' => config('discord.username'),
                    'embeds' => [array_filter([
                        'title' => mb_substr($title, 0, 250),
                        'description' => $description ?: null,
                        'color' => $color,
                    ])],
                ]);

            if (! $response->successful()) {
                Log::warning('discord webhook rejected the notification', [
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 500),
                ]);

                return false;
            }

            return true;
        } catch (Throwable $e) {
            Log::warning('discord webhook unreachable', ['message' => $e->getMessage()]);

            return false;
        }
    }
}
