<?php

namespace App\Support;

use App\Settings\BillingSettings;
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
     * @param  string  $configKey  the `config()` key that supplies the fallback
     */
    public static function billing(string $property, string $configKey, mixed $default = null): mixed
    {
        try {
            $value = app(BillingSettings::class)->{$property};

            // A settings row that exists but holds nothing is not an answer; fall through.
            if ($value !== null && $value !== '') {
                return $value;
            }
        } catch (Throwable) {
            // No database yet — boot must not depend on one.
        }

        return config($configKey, $default);
    }

    /**
     * The same thing for a setting that becomes a CLOCK TIME in a cron expression.
     *
     * **A malformed time here stops the whole scheduler, not one job.** `->dailyAt('24:00')` splices
     * hour 24 into `0 24 * * *`, and `Schedule::dueEvents()` is `filter->isDue()` over every
     * registered event with no try/catch — so the first bad expression throws and `schedule:run`
     * aborts *before any event runs*. Proved by building a schedule with a good event, a bad one and
     * an `everyMinute()` one: `dueEvents()` threw and **nothing ran**, including the event defined
     * before the bad one. Monthly billing, late fees, both SLA scans, `accounting:sync-ledger` and
     * `backup:run` stop together — and so do the two things that would have raised the alarm:
     * `atriom:notify-status`, and the `everyMinute()` heartbeat that makes `atriom:health` notice a
     * dead scheduler at all.
     *
     * The picker on the form is the first layer and is a UI truth only: a settings row also arrives
     * from a seeder, an import, `tinker`, `env` or a restored backup. This is the layer that holds,
     * and it fails the way the rest of this class does — fall back to the config default, because a
     * scheduler running at the wrong hour is recoverable and a scheduler that does not run is not.
     *
     * **Lenient about SHAPE, strict about RANGE.** `dailyAt()` reads only the hour and the minute,
     * so `2:30`, `9:05`, `03:00:00` and even `0:0` — Laravel's own default for `monthlyOn()` — are
     * all perfectly schedulable, and the likeliest thing to reach this method is a CORRECT time
     * written unpadded in `env`. Refusing those would turn a harmless shape into a setting silently
     * ignored, which is this method's own failure mode arriving through a different door. What is
     * refused is an hour or minute out of range, which is the only thing that actually throws.
     */
    public static function billingTime(string $property, string $configKey, string $default): string
    {
        $value = trim((string) self::billing($property, $configKey, $default));

        if (preg_match('/^(\d{1,2}):(\d{1,2})(?::\d{1,2})?$/', $value, $m) === 1
            && (int) $m[1] <= 23
            && (int) $m[2] <= 59) {
            return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
        }

        self::warn('Scheduled time setting is not a valid clock time — falling back', $property, $value, $default);

        return $default;
    }

    /**
     * A setting that becomes a DAY or a MONTH in a cron expression.
     *
     * The same hazard with two failure modes, and the quieter one is worse. Month `0` or day `32`
     * throws and takes the whole run down exactly as a bad hour does. But an IMPOSSIBLE PAIR —
     * 30 February, which the form permits because it caps the month at 12 and the day at 31
     * independently — does not throw: `isDue()` simply answers false for ever, so CAM reconciliation
     * never runs and nothing anywhere says so. (`schedule:list` does throw on it, which is the only
     * place it is visible.)
     *
     * So the range is enforced here, and {@see yearlyDay()} resolves the pair.
     */
    public static function billingInt(string $property, string $configKey, int $default, int $min, int $max): int
    {
        $value = self::billing($property, $configKey, $default);

        if (is_numeric($value) && (int) $value >= $min && (int) $value <= $max) {
            return (int) $value;
        }

        self::warn('Scheduled day/month setting is out of range — falling back', $property, $value, $default);

        return $default;
    }

    /**
     * The day of an annual schedule, clamped into the month it is actually in.
     *
     * 30 February is savable on the form and silently never runs. Clamping rather than refusing is
     * the house answer to the same question elsewhere — `BillingDay` clamps the monthly billing day
     * to the month's last day, because a property set to the 31st would otherwise skip seven months
     * of the year with nothing reporting it. An operator who typed the 30th of February meant the
     * end of February.
     */
    public static function yearlyDay(int $month, int $day): int
    {
        $daysInMonth = (int) date('t', mktime(0, 0, 0, $month, 1, 2027));   // a non-leap year: never overstate

        return min($day, $daysInMonth);
    }

    /**
     * Report a setting being ignored — without being able to take the boot down doing it.
     *
     * This runs from `routes/console.php`, which is loaded by EVERY artisan invocation, and the
     * `ops` log stack ships with `ignore_exceptions => false` and a Slack handler at `error` level
     * on a production box. So an unguarded call here would POST to a webhook on every
     * `schedule:run`, every `queue:work` boot and every step of `deploy.sh` — and a webhook failure
     * would throw straight out of a method the caller has no reason to guard, turning a stale cron
     * time into the boot failure this whole class exists to prevent.
     *
     * Once per process, because the value cannot change within one, and inside its own catch.
     */
    private static function warn(string $message, string $property, mixed $value, mixed $using): void
    {
        static $reported = [];

        if (isset($reported[$property])) {
            return;
        }

        $reported[$property] = true;

        try {
            OpsLog::error($message, [
                'property' => $property,
                'value' => is_scalar($value) ? (string) $value : gettype($value),
                'using' => $using,
            ]);
        } catch (Throwable) {
            // Reporting a misconfiguration must never be more dangerous than the misconfiguration.
        }
    }
}
