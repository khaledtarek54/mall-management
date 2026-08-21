<?php

use App\Settings\CalendarSettings;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * The working week and the working day, shipped with Egypt's answer.
 *
 * Sunday–Thursday, 09:00–17:00. Unlike most defaults in this system these are not a guess to be
 * confirmed: the Egyptian weekend is Friday and Saturday as a matter of law and universal practice,
 * so shipping the country's week is more honest than shipping a blank one the operator must fill
 * before any SLA can be measured.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('calendar.working_days', CalendarSettings::EGYPTIAN_WEEK);
        $this->migrator->add('calendar.day_opens_at', '09:00');
        $this->migrator->add('calendar.day_closes_at', '17:00');
    }

    public function down(): void
    {
        $this->migrator->delete('calendar.working_days');
        $this->migrator->delete('calendar.day_opens_at');
        $this->migrator->delete('calendar.day_closes_at');
    }
};
