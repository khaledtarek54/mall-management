<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Feature flags for outbound integrations. Each flag toggles the
 * corresponding action's visibility in the UI (Paymob Pay Now button) and
 * gates the underlying service from making real network calls.
 *
 * `whatsapp_enabled` was removed 2026-08-11 along with the action it controlled:
 * that action's entire body was a success notification, so the toggle enabled a
 * button that reported a chase which never happened.
 */
class IntegrationsSettings extends Settings
{
    public bool $paymob_enabled = false;

    public static function group(): string
    {
        return 'integrations';
    }
}
