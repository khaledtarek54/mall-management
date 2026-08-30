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

    /**
     * The operator's switch for OUTBOUND EMAIL.
     *
     * Defaults TRUE, unlike its Paymob sibling, and the asymmetry is deliberate: Paymob is off
     * until credentials exist, whereas mail is configured on every box and the question is only
     * whether it should be leaving right now. Switching this off routes every message to the LOG
     * instead — written, never delivered.
     *
     * Like every switch here it can only NARROW. A box whose MAIL_MAILER is already `log` has
     * nothing to disable, and this can never start mail sending on one that is not configured.
     */
    public bool $mail_enabled = true;

    public static function group(): string
    {
        return 'integrations';
    }
}
