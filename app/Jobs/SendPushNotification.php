<?php

namespace App\Jobs;

use App\Models\DeviceToken;
use App\Notifications\Channels\PushChannel;
use App\Services\Push\PushSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Delivers one already-rendered push payload to a set of device tokens off the
 * request thread. Title/body/data are materialized by {@see PushChannel}
 * before dispatch (there is no model to reload), so the job is safe to queue even
 * from inside the triggering DB transaction — on a database queue the job row is
 * written in that same transaction, so it rolls back with the event and is never
 * visible to the worker before commit (no afterCommit dance needed).
 *
 * Tokens FCM reports as permanently invalid are pruned so dead rows don't
 * accumulate and waste a request on every future send.
 */
class SendPushNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  array<int, string>  $tokens  map of device_token id => token string
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public array $tokens,
        public string $title,
        public string $body,
        public array $data = [],
    ) {}

    public function handle(PushSender $sender): void
    {
        if ($this->tokens === []) {
            return;
        }

        $dead = $sender->send(array_values($this->tokens), $this->title, $this->body, $this->data);

        if ($dead === []) {
            return;
        }

        // Map the dead token strings back to their row ids (array_intersect keeps
        // the id keys) and delete only those rows.
        $deadIds = array_keys(array_intersect($this->tokens, $dead));

        if ($deadIds !== []) {
            DeviceToken::whereIn('id', $deadIds)->delete();
        }
    }
}
