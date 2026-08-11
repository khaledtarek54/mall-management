<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Delete `integrations.whatsapp_enabled` — the action it controlled is gone.
 *
 * The "Send WhatsApp" invoice action's entire `action()` body was a success notification:
 *
 *     ->action(fn ($record) => Notification::make()
 *         ->title(__('admin.actions.send_whatsapp'))
 *         ->body($record->tenant->name)
 *         ->success()
 *         ->send()),
 *
 * Nothing was sent. There is no WhatsApp client anywhere in this codebase. And the toggle was
 * genuinely wired — `AppServiceProvider` bridged the setting onto
 * `config('integrations.whatsapp.enabled')` — so an operator could switch it on, see the button
 * appear on issued and overdue invoices, click it, and be told the tenant had been chased.
 *
 * (Its own helper text claimed the toggle "cannot switch it ON — that needs WhatsApp credentials
 * in the server configuration". That was false: the settings bridge overwrote the env-derived
 * config value, so the toggle could and did turn it on.)
 *
 * A button that reports a false result is worse than a missing feature, because it puts a chase
 * that never happened into the collections record. Removed rather than disabled: leaving the
 * toggle behind would be a switch that controls nothing, which is the inert-settings trap this
 * project has already been bitten by.
 *
 * `tenants.whatsapp` is untouched — the number is real data, and a future integration will want it.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->deleteIfExists('integrations.whatsapp_enabled');
    }
};
