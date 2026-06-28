<?php

namespace App\Services\Push;

/**
 * Default push transport: does nothing. The app ships with this bound so it runs
 * without any Firebase credentials — the in-app inbox (DB notifications) and
 * email still deliver. Swap to {@see FcmPushSender} via config once creds land.
 */
class NullPushSender implements PushSender
{
    public function send(array $tokens, string $title, string $body, array $data = []): void
    {
        // Intentionally no-op.
    }
}
