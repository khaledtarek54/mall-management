<?php

namespace App\Support;

use Throwable;

/**
 * Read an operator-editable setting from `routes/console.php`, safely.
 *
 * **The bug this fixes.** The Settings → Billing tab wrote `BillingSettings` while the scheduler
 * built its cron expressions from `config('billing.*')` — populated from `env`. So the monthly
 * billing day, its time and the three CAM reconciliation values an operator saved on that screen
 * **had never had any effect**, exactly like the late-fee values fixed in MF-08. The screen saved,
 * reloaded, showed the new number, and the scheduler ignored it.
 *
 * **Why it needed a helper rather than a one-line swap.** `routes/console.php` runs at BOOT, before
 * a request and sometimes before a database exists: `php artisan config:cache` during deploy, a
 * fresh clone, CI ahead of `migrate`, a container whose DB is not up yet. Reading settings there
 * unguarded turns a missing table into a boot failure, which is a far worse outcome than a stale
 * cron time.
 *
 * So: try the settings record, and fall back to the config value on ANY failure. The operator's
 * choice is honoured whenever the database can answer, and the scheduler still boots when it
 * cannot. Deliberately catches `Throwable` rather than a specific exception — the failure modes
 * here are a missing table, a missing connection and a missing settings row, and the correct
 * response to all three is the same.
 */
class ScheduleSetting
{
    /**
     * @param  string  $property  the property name on the settings class
     * @param  string  $configKey the `config()` key that supplies the fallback
     */
    public static function billing(string $property, string $configKey, mixed $default = null): mixed
    {
        try {
            $value = app(\App\Settings\BillingSettings::class)->{$property};

            // A settings row that exists but holds nothing is not an answer; fall through.
            if ($value !== null && $value !== '') {
                return $value;
            }
        } catch (Throwable) {
            // No database yet — boot must not depend on one.
        }

        return config($configKey, $default);
    }
}
