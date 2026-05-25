<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Feature flags for outbound integrations. Each flag toggles the
 * corresponding action's visibility in the UI (Paymob Pay Now button,
 * WhatsApp send action) and gates the underlying service from making
 * real network calls.
 */
class IntegrationsSettings extends Settings
{
    public bool $paymob_enabled;
    public bool $whatsapp_enabled;

    public static function group(): string
    {
        return 'integrations';
    }
}
