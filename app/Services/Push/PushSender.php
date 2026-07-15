<?php

namespace App\Services\Push;

/**
 * Pluggable push-notification transport. The default binding is a no-op
 * ({@see NullPushSender}) so the app runs with zero push credentials; bind
 * {@see FcmPushSender} (PUSH_ENABLED + FCM creds) to actually deliver to phones.
 * Mirrors the EtaDocumentSigner / PaymobClient pluggable-integration pattern.
 */
interface PushSender
{
    /**
     * Deliver one notification to a set of device tokens. Implementations must
     * never throw into the caller — a push failure must not break the event that
     * triggered it (the DB notification + email already delivered).
     *
     * @param  array<int, string>  $tokens  device push tokens
     * @param  array<string, mixed>  $data  deep-link payload (invoice_id, etc.) — coerced to strings
     * @return array<int, string> the subset of $tokens the provider reported as
     *                            permanently invalid (uninstalled / expired) and
     *                            therefore safe to delete. Transient failures
     *                            (5xx, network, quota) are NOT included.
     */
    public function send(array $tokens, string $title, string $body, array $data = []): array;
}
