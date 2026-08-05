<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Re-point the integration toggles at what `.env` currently says.
 *
 * These two settings were written by the Settings page and read by nothing — the
 * gates all read `config('integrations.*.enabled')` straight from env (see
 * AppServiceProvider::applyIntegrationKillSwitches, which is what now makes them
 * live). Their stored values are therefore whatever env happened to say on the day
 * `create_integrations_settings` ran, which on any deployment provisioned before
 * the credentials landed is `false`.
 *
 * Without this re-sync, turning the switch on would instantly DISABLE card
 * collection on exactly those deployments — a silent outage on the money path,
 * caused by a bug fix. Re-syncing makes the change a no-op everywhere: whatever
 * env says today keeps applying, and the toggle starts from the truth.
 *
 * One-time. From here the setting is an operator decision and must not be
 * overwritten from env again.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->update(
            'integrations.paymob_enabled',
            fn () => (bool) env('PAYMOB_ENABLED', false),
        );

        $this->migrator->update(
            'integrations.whatsapp_enabled',
            fn () => (bool) env('WHATSAPP_ENABLED', false),
        );
    }
};
