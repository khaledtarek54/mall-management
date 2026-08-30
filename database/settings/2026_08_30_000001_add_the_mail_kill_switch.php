<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * An operator switch for outbound email.
 *
 * Defaults TRUE — every existing box keeps sending exactly as it did. This adds the ability to
 * STOP, from the Settings screen, without an SSH session and a config rebuild: a provider incident,
 * a runaway notification loop, a data import that would email hundreds of tenants, or simply a test
 * window where nothing should reach a real inbox.
 *
 * It only ever narrows. A box already on the `log` mailer has nothing to disable.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('integrations.mail_enabled', true);
    }
};
